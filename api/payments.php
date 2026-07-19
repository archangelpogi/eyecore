<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']);

if ($is_authenticated) {
    $user_id = $_SESSION['user_id'];
    $clinic_id = $_SESSION['clinic_id'];
} else {
    $user_id = null;
    $clinic_id = null;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// FUNCTION: UPDATE INVENTORY STOCK
// ============================================
function updateInventoryStock($pdo, $sale_id, $clinic_id) {
    $updated = [];
    $errors = [];
    
    // Get all items from sale_items
    $itemsStmt = $pdo->prepare("
        SELECT si.item_id, si.item_type, si.quantity, si.item_name
        FROM sale_items si
        WHERE si.sale_id = ? AND si.clinic_id = ?
    ");
    $itemsStmt->execute([$sale_id, $clinic_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        // Only deduct stock for products
        if ($item['item_type'] === 'product') {
            // Check if enough stock
            $checkStmt = $pdo->prepare("
                SELECT stock FROM inventory 
                WHERE id = ? AND clinic_id = ?
            ");
            $checkStmt->execute([$item['item_id'], $clinic_id]);
            $currentStock = $checkStmt->fetchColumn();
            
            if ($currentStock < $item['quantity']) {
                $errors[] = "Insufficient stock for {$item['item_name']}. Available: {$currentStock}, Required: {$item['quantity']}";
                continue;
            }
            
            // Update inventory stock
            $updateStmt = $pdo->prepare("
                UPDATE inventory 
                SET stock = stock - ?, 
                    item_status = CASE 
                        WHEN (stock - ?) <= 0 THEN 'out-of-stock'
                        WHEN (stock - ?) <= min_stock THEN 'low-stock'
                        ELSE 'in-stock'
                    END,
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ? AND stock >= ?
            ");
            $updateStmt->execute([
                $item['quantity'],
                $item['quantity'],
                $item['quantity'],
                $item['item_id'],
                $clinic_id,
                $item['quantity']
            ]);
            
            // Check if update was successful
            if ($updateStmt->rowCount() > 0) {
                $updated[] = [
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity']
                ];
            } else {
                $errors[] = "Failed to update stock for {$item['item_name']}";
            }
        }
    }
    
    return [
        'success' => empty($errors),
        'updated' => $updated,
        'errors' => $errors,
        'message' => empty($errors) ? 'Inventory updated successfully' : implode(', ', $errors)
    ];
}

try {
    if ($method === 'GET') {
        // Get payments by sale_id
        if (isset($_GET['sale_id'])) {
            $sale_id = (int)$_GET['sale_id'];
            
            $stmt = $pdo->prepare("
                SELECT id, amount, payment_method, payment_status, payment_type, 
                       reference_number, payment_reference, online_provider, 
                       notes, payment_date, created_at, verified_at
                FROM payments 
                WHERE sale_id = ? 
                ORDER BY payment_date DESC, created_at DESC
            ");
            $stmt->execute([$sale_id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($payments);
            exit;
        }
        
        // Get payments by appointment_id
        if (isset($_GET['appointment_id'])) {
            $appointment_id = (int)$_GET['appointment_id'];
            
            $stmt = $pdo->prepare("
                SELECT id, amount, payment_method, payment_status, payment_type, 
                       reference_number, payment_reference, online_provider, 
                       notes, payment_date, created_at
                FROM payments 
                WHERE appointment_id = ? 
                ORDER BY payment_date DESC
            ");
            $stmt->execute([$appointment_id]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($payments);
            exit;
        }
        
        echo json_encode([]);
        exit;
    }
    
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            $pdo->beginTransaction();
            
            // Determine clinic_id if not authenticated
            if (!$is_authenticated) {
                if (isset($data['appointment_id']) && !empty($data['appointment_id'])) {
                    $stmt = $pdo->prepare("SELECT clinic_id FROM appointments WHERE id = ?");
                    $stmt->execute([$data['appointment_id']]);
                    $appt = $stmt->fetch();
                    $clinic_id = $appt['clinic_id'] ?? null;
                } elseif (isset($data['sale_id']) && !empty($data['sale_id'])) {
                    $stmt = $pdo->prepare("SELECT clinic_id FROM sales WHERE id = ?");
                    $stmt->execute([$data['sale_id']]);
                    $sale = $stmt->fetch();
                    $clinic_id = $sale['clinic_id'] ?? null;
                }
                
                if (!$clinic_id) {
                    throw new Exception('Unable to determine clinic');
                }
            }
            
            $appointment_id = isset($data['appointment_id']) && !empty($data['appointment_id']) ? $data['appointment_id'] : null;
            $sale_id = isset($data['sale_id']) && !empty($data['sale_id']) ? $data['sale_id'] : null;
            $amount = floatval($data['amount'] ?? 0);
            $payment_method = $data['payment_method'] ?? 'cash';
            $payment_type = $data['payment_type'] ?? 'full';
            $reference_number = $data['reference_number'] ?? null;
            $notes = $data['notes'] ?? null;
            $online_provider = in_array($payment_method, ['gcash', 'paymaya', 'credit_card', 'bank_transfer']) ? $payment_method : null;
            
            // ✅ GET SALE DETAILS IF sale_id IS PROVIDED
            $sale_data = null;
            if ($sale_id && $sale_id > 0) {
                $saleStmt = $pdo->prepare("
                    SELECT s.*, a.status as appointment_status 
                    FROM sales s
                    LEFT JOIN appointments a ON s.appointment_id = a.id
                    WHERE s.id = ? AND s.clinic_id = ?
                ");
                $saleStmt->execute([$sale_id, $clinic_id]);
                $sale_data = $saleStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$sale_data) {
                    throw new Exception('Sale not found');
                }
                
                // Use appointment_id from sale if not provided
                if (!$appointment_id && $sale_data['appointment_id']) {
                    $appointment_id = $sale_data['appointment_id'];
                }
            }
            
            // Insert payment record
            if ($appointment_id && $appointment_id > 0) {
                // Payment with appointment_id
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (appointment_id, clinic_id, user_id, amount, payment_method, payment_status, 
                     payment_type, reference_number, payment_reference, online_provider, notes, payment_date, created_at)
                    VALUES (?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $appointment_id,
                    $clinic_id,
                    $user_id,
                    $amount,
                    $payment_method,
                    $payment_type,
                    $reference_number,
                    $reference_number,
                    $online_provider,
                    $notes
                ]);
            } elseif ($sale_id && $sale_id > 0) {
                // Payment with sale_id
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (sale_id, clinic_id, user_id, amount, payment_method, payment_status, 
                     payment_type, reference_number, payment_reference, online_provider, notes, payment_date, created_at)
                    VALUES (?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $sale_id,
                    $clinic_id,
                    $user_id,
                    $amount,
                    $payment_method,
                    $payment_type,
                    $reference_number,
                    $reference_number,
                    $online_provider,
                    $notes
                ]);
            } else {
                // Payment without any reference
                $stmt = $pdo->prepare("
                    INSERT INTO payments 
                    (clinic_id, user_id, amount, payment_method, payment_status, 
                     payment_type, reference_number, payment_reference, online_provider, notes, payment_date, created_at)
                    VALUES (?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $clinic_id,
                    $user_id,
                    $amount,
                    $payment_method,
                    $payment_type,
                    $reference_number,
                    $reference_number,
                    $online_provider,
                    $notes
                ]);
            }
            
            $payment_id = $pdo->lastInsertId();
            
            // ✅ UPDATE SALE amount_paid if this payment is for a sale
            $isFullyPaid = false;
            $inventory_result = null;
            
            if ($sale_id && $sale_id > 0 && $sale_data) {
                $current_paid = floatval($sale_data['amount_paid']);
                $total_amount = floatval($sale_data['total_amount']);
                $new_amount_paid = $current_paid + $amount;
                $isFullyPaid = $new_amount_paid >= $total_amount;
                
                $sale_status = $isFullyPaid ? 'Paid' : 'Partial';
                
                $updateStmt = $pdo->prepare("
                    UPDATE sales 
                    SET amount_paid = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $updateStmt->execute([$new_amount_paid, $sale_status, $sale_id, $clinic_id]);
                
                // ✅✅✅ IF FULLY PAID, UPDATE INVENTORY STOCK (same as sales.php) ✅✅✅
                if ($isFullyPaid) {
                    $inventory_result = updateInventoryStock($pdo, $sale_id, $clinic_id);
                }
            }
            
            // ✅ UPDATE APPOINTMENT STATUS IF APPOINTMENT ID EXISTS
            $appointment_new_status = null;
            if ($appointment_id) {
                // Get current appointment and calculate total paid
                $apptStmt = $pdo->prepare("
                    SELECT a.*, 
                           COALESCE(SUM(p.amount), 0) as total_paid
                    FROM appointments a
                    LEFT JOIN payments p ON a.id = p.appointment_id AND p.payment_status = 'paid'
                    WHERE a.id = ? AND a.clinic_id = ?
                    GROUP BY a.id
                ");
                $apptStmt->execute([$appointment_id, $clinic_id]);
                $appointment = $apptStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($appointment) {
                    $total_paid = floatval($appointment['total_paid']) + $amount;
                    $appt_total = floatval($appointment['total_amount']);
                    $is_appt_fully_paid = $total_paid >= $appt_total;
                    
                    if ($is_appt_fully_paid) {
                        // ✅ FULLY PAID -> COMPLETED
                        $appointment_new_status = 'completed';
                        $payment_status = 'paid';
                        $message = "✅ FULLY PAID! Appointment is now COMPLETED.";
                    } else {
                        $appointment_new_status = 'waiting_payment';
                        $payment_status = 'partial';
                        $message = "Partial payment recorded. Remaining balance: ₱" . number_format($appt_total - $total_paid, 2);
                    }
                    
                    // Update appointment
                    $updateAppt = $pdo->prepare("
                        UPDATE appointments 
                        SET status = ?, 
                            payment_status = ?,
                            amount_paid = ?,
                            updated_at = NOW()
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $updateAppt->execute([
                        $appointment_new_status, 
                        $payment_status, 
                        $total_paid,
                        $appointment_id, 
                        $clinic_id
                    ]);
                }
            }
            
            $pdo->commit();
            
            // Prepare response message
            $response_message = $message ?? 'Payment recorded successfully';
            
            if ($inventory_result && !$inventory_result['success']) {
                $response_message .= ' But inventory update failed: ' . $inventory_result['message'];
            } elseif ($inventory_result && $inventory_result['success'] && !empty($inventory_result['updated'])) {
                $response_message .= ' Inventory updated for ' . count($inventory_result['updated']) . ' item(s).';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $response_message,
                'payment_id' => $payment_id,
                'fully_paid' => $isFullyPaid || ($appointment_new_status === 'completed'),
                'appointment_status' => $appointment_new_status,
                'sale_status' => $sale_status ?? null,
                'inventory_updated' => $inventory_result ? $inventory_result['success'] : false,
                'inventory_details' => $inventory_result
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>