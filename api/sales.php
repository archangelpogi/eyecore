<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ RBAC Permission Helper Class for Sales
class SalesPermission {
    private static $module = 'sales';
    
    public static function can($action) {
        $permissionMap = [
            'view' => self::$module . '_view',
            'create' => self::$module . '_create',
            'edit' => self::$module . '_edit',
            'delete' => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject' => self::$module . '_reject',
            'export' => self::$module . '_view'  // export uses view permission
        ];
        
        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }
    
    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot ' . $action . ' sales records']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ============= AUDIT LOG FUNCTION =============
function logAudit($pdo, $user_id, $clinic_id, $action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;
    
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    return $stmt->execute([$user_id, $clinic_id, $action, $table_name, $record_id, $old_json, $new_json, $ip_address, $user_agent]);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ============= GET PERMISSIONS ENDPOINT =============
    if ($method === 'GET' && isset($_GET['get_permissions'])) {
        $hasHR = false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'HR' AND status = 'Active'");
        $stmt->execute([$user_id]);
        $hasHR = $stmt->fetchColumn() > 0;
        
        $permissions = [
            'view' => SalesPermission::can('view'),
            'create' => SalesPermission::can('create'),
            'edit' => SalesPermission::can('edit'),
            'delete' => SalesPermission::can('delete'),
            'approve' => SalesPermission::can('approve'),
            'reject' => SalesPermission::can('reject'),
            'export' => SalesPermission::can('view')
        ];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'role' => $user_role,
                'permissions' => $permissions,
                'hasHR' => $hasHR,
                'isOwner' => ($user_role === 'ClinicAdmin' && !$hasHR),
                'user_id' => $user_id,
                'user_name' => $user_name
            ]
        ]);
        exit;
    }
    
    // ============= GET SALES =============
    if ($method === 'GET') {
        // ✅ RBAC Check
        SalesPermission::check('view');
        
        // Get single sale with all payments
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            
            // Get sale details
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       CONCAT(p.first_name, ' ', p.last_name) as customer_name,
                       p.id as patient_code,
                       p.email as patient_email,
                       p.phone as patient_phone,
                       CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                       CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                FROM sales s
                LEFT JOIN patients p ON s.patient_id = p.id 
                LEFT JOIN users u ON s.created_by = u.id
                WHERE s.id = ? AND s.clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sale) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }
            
            // Get all payments for this sale
            $paymentsStmt = $pdo->prepare("
                SELECT id, amount, payment_method, payment_status, payment_type, 
                       reference_number, payment_reference, online_provider, notes, payment_date,
                       created_at, verified_at, verified_by
                FROM payments 
                WHERE sale_id = ? 
                ORDER BY payment_date DESC, created_at DESC
            ");
            $paymentsStmt->execute([$id]);
            $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sale['payments'] = $payments;
            
            // Log audit
            logAudit($pdo, $user_id, $clinic_id, 'VIEW', 'sales', $id);
            echo json_encode($sale);
            exit;
        }
        
        // Export data
        if (isset($_GET['export'])) {
            // ✅ RBAC Check - export uses view permission
            SalesPermission::check('view');
            
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       CASE 
                           WHEN s.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                           ELSE s.walk_in_name
                       END as customer_name,
                       CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                       (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id AND payment_status = 'paid') as payment_total
                FROM sales s
                LEFT JOIN patients p ON s.patient_id = p.id 
                WHERE s.clinic_id = ?
                ORDER BY s.sale_date DESC
            ");
            $stmt->execute([$clinic_id]);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            logAudit($pdo, $user_id, $clinic_id, 'EXPORT', 'sales', null, null, ['count' => count($sales)]);
            echo json_encode($sales);
            exit;
        }
        
        // Search with payment info
        $search = $_GET['search'] ?? '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        
        $sql = "
            SELECT s.*, 
                   CASE 
                       WHEN s.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                       ELSE s.walk_in_name
                   END as customer_name,
                   CASE 
                       WHEN s.patient_id IS NOT NULL THEN p.id
                       ELSE 'WALK-IN'
                   END as customer_code,
                   CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id AND payment_status = 'paid') as online_payments_total,
                   (SELECT COUNT(*) FROM payments WHERE sale_id = s.id AND payment_status = 'paid') as payment_count
            FROM sales s
            LEFT JOIN patients p ON s.patient_id = p.id
            WHERE s.clinic_id = ?
        ";
        
        $params = [$clinic_id];
        
        if (!empty($search)) {
            $sql .= " AND (
                s.id LIKE ? 
                OR CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) LIKE ?
                OR p.first_name LIKE ? 
                OR p.last_name LIKE ? 
                OR p.id LIKE ?
                OR s.walk_in_name LIKE ?
            )";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        $sql .= " ORDER BY s.sale_date DESC, s.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $invoices]);
        exit;
    }

// ============= GET APPOINTMENT WITH PAYMENT INFO =============
if ($method === 'GET' && isset($_GET['appointment_id']) && !isset($_GET['action'])) {
    SalesPermission::check('view');
    
    $appointmentId = (int)$_GET['appointment_id'];
    
    // Get appointment with product details
    $stmt = $pdo->prepare("
        SELECT a.*, 
               COALESCE(pr.name, srv.name, a.service_type, 'Consultation') as item_name,
               COALESCE(pr.price, srv.price, 0) as item_price,
               COALESCE(pr.category, srv.category, 'Service') as item_category,
               CONCAT(p.first_name, ' ', p.last_name) as patient_name
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN products pr ON a.product_id = pr.id
        LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $stmt->execute([$appointmentId, $clinic_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // ✅ Get existing payments
    $paymentsStmt = $pdo->prepare("
        SELECT id, amount, payment_method, payment_type, payment_status, 
               reference_number, payment_date, created_at
        FROM payments 
        WHERE appointment_id = ? AND payment_status = 'paid'
        ORDER BY created_at ASC
    ");
    $paymentsStmt->execute([$appointmentId]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalPaid = 0;
    foreach ($payments as $payment) {
        $totalPaid += floatval($payment['amount']);
    }
    
    // Check if sale already exists
    $saleStmt = $pdo->prepare("SELECT id, status, amount_paid FROM sales WHERE appointment_id = ? AND clinic_id = ?");
    $saleStmt->execute([$appointmentId, $clinic_id]);
    $existingSale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'appointment' => $appointment,
        'payments' => $payments,
        'payment_summary' => [
            'total_paid' => $totalPaid,
            'total_amount' => floatval($appointment['total_amount'] ?: $appointment['item_price']),
            'remaining_balance' => (floatval($appointment['total_amount'] ?: $appointment['item_price'])) - $totalPaid,
            'has_payments' => count($payments) > 0
        ],
        'existing_sale' => $existingSale
    ]);
    exit;
}


    
// ============= CREATE SALE =============
if ($method === 'POST') {
    // ✅ RBAC Check
    SalesPermission::check('create');
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['total_amount']) || $data['total_amount'] === '') {
        echo json_encode(['success' => false, 'message' => 'Total amount is required']);
        exit;
    }
    
    if (!isset($data['items']) || empty($data['items'])) {
        echo json_encode(['success' => false, 'message' => 'At least one item is required']);
        exit;
    }
    
    // Validate customer info
    if ($data['customer_type'] === 'registered') {
        if (!isset($data['patient_id']) || empty($data['patient_id'])) {
            echo json_encode(['success' => false, 'message' => 'Please select a patient']);
            exit;
        }
    } else {
        if (!isset($data['walk_in_name']) || empty(trim($data['walk_in_name']))) {
            echo json_encode(['success' => false, 'message' => 'Please enter customer name for walk-in']);
            exit;
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // Prepare customer data
        $patient_id = null;
        $walk_in_name = null;
        $walk_in_contact = null;
        $walk_in_email = null;
        
        if ($data['customer_type'] === 'registered') {
            $patient_id = $data['patient_id'];
        } else {
            $walk_in_name = trim($data['walk_in_name']);
            $walk_in_contact = $data['walk_in_contact'] ?? null;
            $walk_in_email = $data['walk_in_email'] ?? null;
        }
        
        // ✅ GET APPOINTMENT ID from data
        $appointment_id = isset($data['appointment_id']) && !empty($data['appointment_id']) 
            ? (int)$data['appointment_id'] 
            : null;
        
        // ✅ If appointment_id is provided, get patient_id from appointment if not set
        if ($appointment_id && !$patient_id) {
            $apptStmt = $pdo->prepare("
                SELECT patient_id, user_id, service_type, status as appt_status
                FROM appointments 
                WHERE id = ? AND clinic_id = ?
            ");
            $apptStmt->execute([$appointment_id, $clinic_id]);
            $appointment = $apptStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($appointment && $appointment['patient_id']) {
                $patient_id = $appointment['patient_id'];
            }
        }
        
        // ✅ INSERT SALE WITH appointment_id
        $stmt = $pdo->prepare("
            INSERT INTO sales 
            (clinic_id, sale_date, patient_id, appointment_id, walk_in_name, walk_in_contact, walk_in_email, 
             items, subtotal, discount, total_amount, amount_paid, 
             payment_method, status, created_by, created_at)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $amount_paid = floatval($data['amount_paid'] ?? 0);
        $total_amount = floatval($data['total_amount']);
        
        $stmt->execute([
            $clinic_id,
            $patient_id,
            $appointment_id,
            $walk_in_name,
            $walk_in_contact,
            $walk_in_email,
            json_encode($data['items']),
            $data['subtotal'] ?? 0,
            $data['discount'] ?? 0,
            $total_amount,
            $amount_paid,
            $data['payment_method'] ?? 'cash',
            $data['status'] ?? 'Unpaid',
            $user_id
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // Insert sale items and update inventory
        $items = $data['items'];
        $item_details = [];
        
        foreach ($items as $item) {
            $itemType = $item['type'] ?? 'product';
            
            // Insert into sale_items
            $itemStmt = $pdo->prepare("
                INSERT INTO sale_items 
                (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $itemStmt->execute([
                $sale_id,
                $clinic_id,
                $item['id'],
                $itemType,
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['price'] * $item['quantity']
            ]);
            
            // Update inventory stock ONLY for products
            if ($itemType === 'product') {
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
                    $item['id'],
                    $clinic_id,
                    $item['quantity']
                ]);
                
                // Check if update was successful
                if ($updateStmt->rowCount() === 0 && $item['quantity'] > 0) {
                    throw new Exception("Insufficient stock for product: {$item['name']}");
                }
            }
            
            $item_details[] = [
                'name' => $item['name'],
                'type' => $itemType,
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ];
        }
        
        // If there's a payment, record it in payments table
        if ($amount_paid > 0) {
            $payment_method = $data['payment_method'] ?? 'cash';
            $reference_number = $data['reference_number'] ?? null;
            $payment_type = $data['payment_type'] ?? ($amount_paid >= $total_amount ? 'full' : 'partial');
            $online_provider = in_array($payment_method, ['gcash', 'paymaya', 'credit_card', 'bank_transfer']) ? $payment_method : null;
            
            $paymentStmt = $pdo->prepare("
                INSERT INTO payments 
                (sale_id, clinic_id, user_id, amount, payment_method, payment_status, payment_type, 
                 reference_number, payment_reference, online_provider, payment_date, created_at)
                VALUES (?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), NOW())
            ");
            $paymentStmt->execute([
                $sale_id,
                $clinic_id,
                $user_id,
                $amount_paid,
                $payment_method,
                $payment_type,
                $reference_number,
                $reference_number,
                $online_provider
            ]);
        }
        
        // ✅ UPDATE APPOINTMENT STATUS IF APPOINTMENT ID EXISTS
        $appointment_status = null;
        if ($appointment_id) {
            $is_fully_paid = $amount_paid >= $total_amount;
            
            // Get current appointment status
            $currentAppt = $pdo->prepare("SELECT status FROM appointments WHERE id = ? AND clinic_id = ?");
            $currentAppt->execute([$appointment_id, $clinic_id]);
            $current_status = $currentAppt->fetchColumn();
            
            if ($is_fully_paid) {
                // ✅ FULLY PAID -> COMPLETED AGAD
                $appointment_status = 'completed';
                $payment_status = 'paid';
                $status_message = "Appointment completed. Full payment received.";
            } else if ($amount_paid > 0) {
                // Partial payment
                $appointment_status = 'waiting_payment';
                $payment_status = 'partial';
                $status_message = "Partial payment recorded. Waiting for remaining balance.";
            } else {
                // No payment yet
                $appointment_status = 'waiting_payment';
                $payment_status = 'pending';
                $status_message = "Bill created. Waiting for payment.";
            }
            
            // Only update if status is not already completed
            if ($current_status !== 'completed') {
                $updateAppt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?, 
                        payment_status = ?,
                        amount_paid = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $updateAppt->execute([
                    $appointment_status, 
                    $payment_status, 
                    $amount_paid, 
                    $appointment_id, 
                    $clinic_id
                ]);
            }
            
            // ✅ IF FULLY PAID, ALSO UPDATE SALE STATUS TO 'Paid'
            if ($is_fully_paid) {
                $updateSaleStatus = $pdo->prepare("
                    UPDATE sales SET status = 'Paid', updated_at = NOW() WHERE id = ? AND clinic_id = ?
                ");
                $updateSaleStatus->execute([$sale_id, $clinic_id]);
            }
        }
        
        // Log audit
        logAudit($pdo, $user_id, $clinic_id, 'CREATE', 'sales', $sale_id, null, [
            'customer_type' => $data['customer_type'],
            'total_amount' => $total_amount,
            'amount_paid' => $amount_paid,
            'appointment_id' => $appointment_id,
            'items' => $item_details
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sale created successfully',
            'sale_id' => $sale_id,
            'appointment_id' => $appointment_id,
            'appointment_status' => $appointment_status,
            'fully_paid' => isset($is_fully_paid) ? $is_fully_paid : false
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

    
    
    // ============= UPDATE SALE =============
    if ($method === 'PUT') {
        // ✅ RBAC Check
        SalesPermission::check('edit');
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['id']) || empty($data['id'])) {
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit;
        }
        
        $sale_id = $data['id'];
        
        try {
            // Get old values for audit
            $oldStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$sale_id, $clinic_id]);
            $old_sale = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_sale) {
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            // Update sale
            $updateFields = [];
            $params = [];
            
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['payment_method'])) {
                $updateFields[] = "payment_method = ?";
                $params[] = $data['payment_method'];
            }
            if (isset($data['notes'])) {
                $updateFields[] = "notes = ?";
                $params[] = $data['notes'];
            }
            
            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $params[] = $sale_id;
                $params[] = $clinic_id;
                
                $updateSql = "UPDATE sales SET " . implode(", ", $updateFields) . " WHERE id = ? AND clinic_id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($params);
            }
            
            // Log audit
            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'sales', $sale_id, $old_sale, $data);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Sale updated successfully']);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ============= DELETE SALE =============
    if ($method === 'DELETE') {
        // ✅ RBAC Check
        SalesPermission::check('delete');
        
        $data = json_decode(file_get_contents('php://input'), true);
        $sale_id = $data['id'] ?? ($_GET['id'] ?? 0);
        
        if (!$sale_id) {
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit;
        }
        
        try {
            // Check if sale can be deleted (only if not paid or draft)
            $checkStmt = $pdo->prepare("SELECT status, total_amount, amount_paid FROM sales WHERE id = ? AND clinic_id = ?");
            $checkStmt->execute([$sale_id, $clinic_id]);
            $sale = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }
            
            if ($sale['status'] === 'Paid' && $sale['amount_paid'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete a paid sale']);
                exit;
            }
            
            // Get old values for audit
            $oldStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$sale_id, $clinic_id]);
            $old_sale = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            $pdo->beginTransaction();
            
            // Delete sale items first (due to foreign key)
            $deleteItemsStmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ? AND clinic_id = ?");
            $deleteItemsStmt->execute([$sale_id, $clinic_id]);
            
            // Delete payments
            $deletePaymentsStmt = $pdo->prepare("DELETE FROM payments WHERE sale_id = ? AND clinic_id = ?");
            $deletePaymentsStmt->execute([$sale_id, $clinic_id]);
            
            // Delete sale
            $deleteStmt = $pdo->prepare("DELETE FROM sales WHERE id = ? AND clinic_id = ?");
            $deleteStmt->execute([$sale_id, $clinic_id]);
            
            // Log audit
            logAudit($pdo, $user_id, $clinic_id, 'DELETE', 'sales', $sale_id, $old_sale, null);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Sale deleted successfully']);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

// ============= RECORD PAYMENT =============
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'record_payment') {
    SalesPermission::check('edit');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $saleId = (int)($data['sale_id'] ?? 0);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $referenceNumber = $data['reference_number'] ?? null;
    
    if (!$saleId || !$appointmentId || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get sale details with items
        $stmt = $pdo->prepare("
            SELECT s.*, si.item_id, si.item_type, si.quantity, si.item_name
            FROM sales s
            LEFT JOIN sale_items si ON s.id = si.sale_id
            WHERE s.id = ? AND s.clinic_id = ?
        ");
        $stmt->execute([$saleId, $clinic_id]);
        $saleItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($saleItems)) {
            throw new Exception('Sale not found');
        }
        
        $sale = $saleItems[0]; // First row has sale data
        $newAmountPaid = floatval($sale['amount_paid']) + $amount;
        $totalAmount = floatval($sale['total_amount']);
        
        // Determine new status
        $isFullyPaid = $newAmountPaid >= $totalAmount;
        
        if ($isFullyPaid) {
            $saleStatus = 'Paid';
            $appointmentStatus = 'completed';  // ✅ Direct to completed
            $paymentType = 'full';
        } else {
            $saleStatus = 'Partial';
            $appointmentStatus = 'waiting_payment';
            $paymentType = 'partial';
        }
        
        // Update sale
        $pdo->prepare("
            UPDATE sales 
            SET amount_paid = ?, status = ?, payment_method = ?, updated_at = NOW() 
            WHERE id = ? AND clinic_id = ?
        ")->execute([$newAmountPaid, $saleStatus, $paymentMethod, $saleId, $clinic_id]);
        
        // Record payment in payments table
        $paymentStmt = $pdo->prepare("
            INSERT INTO payments 
            (sale_id, appointment_id, clinic_id, user_id, amount, payment_method, 
             payment_status, payment_type, reference_number, payment_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
        ");
        $paymentStmt->execute([
            $saleId,
            $appointmentId,
            $clinic_id,
            $user_id,
            $amount,
            $paymentMethod,
            $paymentType,
            $referenceNumber
        ]);
        
        // ✅ UPDATE APPOINTMENT STATUS
        $pdo->prepare("
            UPDATE appointments 
            SET status = ?, payment_status = ?, updated_at = NOW() 
            WHERE id = ? AND clinic_id = ?
        ")->execute([$appointmentStatus, $saleStatus, $appointmentId, $clinic_id]);
        
        // ✅ IF FULLY PAID, UPDATE INVENTORY STOCK
        if ($isFullyPaid) {
            // Get all items from sale_items
            $itemsStmt = $pdo->prepare("
                SELECT item_id, item_type, quantity 
                FROM sale_items 
                WHERE sale_id = ? AND clinic_id = ?
            ");
            $itemsStmt->execute([$saleId, $clinic_id]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                // Only deduct stock for products
                if ($item['item_type'] === 'product') {
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
                    if ($updateStmt->rowCount() === 0) {
                        throw new Exception("Insufficient stock for item ID: {$item['item_id']}");
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'sale_status' => $saleStatus,
            'appointment_status' => $appointmentStatus,
            'amount_paid' => $newAmountPaid,
            'balance' => $totalAmount - $newAmountPaid,
            'fully_paid' => $isFullyPaid,
            'message' => $isFullyPaid ? 'Payment completed. Appointment is now completed and inventory updated.' : 'Partial payment recorded'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============= COMPLETE SERVICE (After payment and service rendered) =============
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'complete_service') {
    SalesPermission::check('edit');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $saleId = (int)($data['sale_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if fully paid
        $stmt = $pdo->prepare("
            SELECT a.status as appt_status, s.status as sale_status, s.amount_paid, s.total_amount
            FROM appointments a
            LEFT JOIN sales s ON a.id = s.appointment_id
            WHERE a.id = ? AND a.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            throw new Exception('Appointment not found');
        }
        
        $isFullyPaid = floatval($record['amount_paid']) >= floatval($record['total_amount']);
        
        if ($record['appt_status'] !== 'paid' && !$isFullyPaid) {
            echo json_encode(['success' => false, 'message' => 'Payment not yet completed. Cannot complete service.']);
            exit;
        }
        
        // Update appointment to completed
        $pdo->prepare("
            UPDATE appointments 
            SET status = 'completed', completed_at = NOW(), updated_at = NOW() 
            WHERE id = ? AND clinic_id = ?
        ")->execute([$appointmentId, $clinic_id]);
        
        // Update sale if exists
        if ($saleId) {
            $pdo->prepare("
                UPDATE sales 
                SET status = 'completed', updated_at = NOW() 
                WHERE id = ? AND clinic_id = ?
            ")->execute([$saleId, $clinic_id]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Service completed successfully',
            'appointment_status' => 'completed'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============= GET BILL BY APPOINTMENT =============
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_bill_by_appointment') {
    SalesPermission::check('view');
    
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        // ✅ First, check if appointment exists
        $checkAppt = $pdo->prepare("SELECT id, status FROM appointments WHERE id = ? AND clinic_id = ?");
        $checkAppt->execute([$appointmentId, $clinic_id]);
        $appointment = $checkAppt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            exit;
        }
        
        // ✅ Check for sale with this appointment_id
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   CASE 
                       WHEN s.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                       ELSE s.walk_in_name
                   END as customer_name,
                   CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id AND payment_status IN ('paid', 'completed')) as total_paid
            FROM sales s
            LEFT JOIN patients p ON s.patient_id = p.id
            WHERE s.appointment_id = ? AND s.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bill) {
            // ✅ Debug: Check if any sale exists with this appointment_id
            $checkSale = $pdo->prepare("SELECT id, status FROM sales WHERE appointment_id = ?");
            $checkSale->execute([$appointmentId]);
            $saleExists = $checkSale->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => false, 
                'message' => 'No bill found for this appointment',
                'debug' => [
                    'appointment_id' => $appointmentId,
                    'appointment_status' => $appointment['status'],
                    'sale_exists' => $saleExists ? $saleExists : false
                ]
            ]);
            exit;
        }
        
        // Get items
        $itemsStmt = $pdo->prepare("
            SELECT * FROM sale_items WHERE sale_id = ? AND clinic_id = ?
        ");
        $itemsStmt->execute([$bill['id'], $clinic_id]);
        $bill['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'bill' => $bill,
            'balance' => floatval($bill['total_amount']) - floatval($bill['total_paid'])
        ]);
        
    } catch (Exception $e) {
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