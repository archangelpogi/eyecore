<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Check if supplier is logged in
if (!isset($_SESSION['supplier_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$supplier_id = $_SESSION['supplier_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_returns':
            getReturns($pdo, $supplier_id);
            break;
        case 'get_return_details':
            getReturnDetails($pdo, $supplier_id);
            break;
        case 'process_return':
            processReturn($pdo, $supplier_id);
            break;
        case 'approve_return':
            approveReturn($pdo, $supplier_id);
            break;
        case 'reject_return':
            rejectReturn($pdo, $supplier_id);
            break;
        case 'complete_return':  // ✅ BAGONG ACTION
            completeReturn($pdo, $supplier_id);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getReturns($pdo, $supplier_id) {
    // Get all returns for this supplier
    $query = "
        SELECT 
            r.*,
            po.po_number,
            po.pr_id,
            c.clinic_name,
            (SELECT COUNT(*) FROM return_items WHERE return_id = r.id) as item_count,
            (SELECT SUM(quantity) FROM return_items WHERE return_id = r.id) as total_items
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        JOIN clinics c ON r.clinic_id = c.id
        WHERE po.supplier_id = ?
        ORDER BY r.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$supplier_id]);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count by status - ✅ UPDATED WITH NEW STATUSES
    $counts = [
        'total' => count($returns),
        'Pending' => 0,
        'Approved' => 0,
        'Completed' => 0,
        'Rejected' => 0,
        'For Pickup' => 0,
        'For Drop-off' => 0,
        'Ready for Pickup' => 0,    // ✅ BAGO
        'Dropped Off' => 0,          // ✅ BAGO
        'Return Completed' => 0      // ✅ BAGO
    ];
    
    foreach ($returns as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']]++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $returns,
        'counts' => $counts
    ]);
}

function getReturnDetails($pdo, $supplier_id) {
    $return_id = $_GET['id'] ?? 0;
    
    if (!$return_id) {
        throw new Exception('Return ID required');
    }
    
    // Get return details
    $query = "
        SELECT 
            r.*,
            po.po_number,
            po.pr_id,
            c.clinic_name,
            CONCAT(u.first_name, ' ', u.last_name) as requested_by_name
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        JOIN clinics c ON r.clinic_id = c.id
        JOIN users u ON r.requested_by = u.id
        WHERE po.supplier_id = ? AND r.id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$supplier_id, $return_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        throw new Exception('Return not found');
    }
    
    // Decode schedule_details if exists
    if (!empty($return['schedule_details'])) {
        $return['schedule_details'] = json_decode($return['schedule_details'], true);
    }
    
    // Get return items
    $items_query = "
        SELECT ri.*, pi.item_name
        FROM return_items ri
        JOIN pr_items pi ON ri.po_item_id = pi.id
        WHERE ri.return_id = ?
    ";
    $items_stmt = $pdo->prepare($items_query);
    $items_stmt->execute([$return_id]);
    $return['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get photos
    $photos_query = "SELECT * FROM return_photos WHERE return_id = ?";
    $photos_stmt = $pdo->prepare($photos_query);
    $photos_stmt->execute([$return_id]);
    $return['photos'] = $photos_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get timeline
    $timeline_query = "
        SELECT rt.*, CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM return_timeline rt
        LEFT JOIN users u ON rt.created_by = u.id
        WHERE rt.return_id = ?
        ORDER BY rt.created_at DESC
    ";
    $timeline_stmt = $pdo->prepare($timeline_query);
    $timeline_stmt->execute([$return_id]);
    $return['timeline'] = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $return]);
}

function processReturn($pdo, $supplier_id) {
    $return_id = $_POST['return_id'] ?? 0;
    $action = $_POST['status_action'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (!$return_id || !$action) {
        throw new Exception('Return ID and action required');
    }
    
    // Map action to status
    $new_status = '';
    switch ($action) {
        case 'approve':
            $new_status = 'Approved';
            break;
        case 'reject':
            $new_status = 'Rejected';
            break;
        case 'complete':
            $new_status = 'Return Completed';  // ✅ CHANGED from 'Completed'
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    // Verify this return belongs to this supplier
    $check_query = "
        SELECT r.id 
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        WHERE po.supplier_id = ? AND r.id = ?
    ";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$supplier_id, $return_id]);
    
    if (!$check_stmt->fetch()) {
        throw new Exception('Unauthorized access to this return');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Update return status
        $update_query = "
            UPDATE returns 
            SET status = ?, 
                supplier_response = ?,
                response_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$new_status, $notes, $return_id]);
        
        // Update PO and PR status if needed
        if ($new_status == 'Approved') {
            $get_ids = $pdo->prepare("SELECT po_id, pr_id FROM returns WHERE id = ?");
            $get_ids->execute([$return_id]);
            $ids = $get_ids->fetch(PDO::FETCH_ASSOC);
            
            if ($ids) {
                $update_po = $pdo->prepare("UPDATE purchase_orders SET status = 'Return Approved', updated_at = NOW() WHERE id = ?");
                $update_po->execute([$ids['po_id']]);
                
                $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = 'Return Approved', updated_at = NOW() WHERE id = ?");
                $update_pr->execute([$ids['pr_id']]);
            }
        } elseif ($new_status == 'Rejected') {
            $get_ids = $pdo->prepare("SELECT po_id, pr_id FROM returns WHERE id = ?");
            $get_ids->execute([$return_id]);
            $ids = $get_ids->fetch(PDO::FETCH_ASSOC);
            
            if ($ids) {
                $update_po = $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?");
                $update_po->execute([$ids['po_id']]);
                
                $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = 'Completed', updated_at = NOW() WHERE id = ?");
                $update_pr->execute([$ids['pr_id']]);
            }
        } elseif ($new_status == 'Return Completed') {
            $get_ids = $pdo->prepare("SELECT po_id, pr_id FROM returns WHERE id = ?");
            $get_ids->execute([$return_id]);
            $ids = $get_ids->fetch(PDO::FETCH_ASSOC);
            
            if ($ids) {
                $update_po = $pdo->prepare("UPDATE purchase_orders SET status = 'Return Completed', updated_at = NOW() WHERE id = ?");
                $update_po->execute([$ids['po_id']]);
                
                $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = 'Return Completed', updated_at = NOW() WHERE id = ?");
                $update_pr->execute([$ids['pr_id']]);
            }
        }
        
        // Add to timeline
        $timeline_query = "
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";
        $timeline_stmt = $pdo->prepare($timeline_query);
        $timeline_stmt->execute([$return_id, $new_status, $notes, $_SESSION['user_id'] ?? null]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Return request has been {$action}d successfully"
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function approveReturn($pdo, $supplier_id) {
    // Get POST data
    $return_id = $_POST['return_id'] ?? 0;
    $return_method = $_POST['return_method'] ?? ''; // 'Pickup' or 'Drop-off'
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (!$return_id) {
        throw new Exception('Return ID is required');
    }
    
    if (!$return_method || !in_array($return_method, ['Pickup', 'Drop-off'])) {
        throw new Exception('Valid return method (Pickup or Drop-off) is required');
    }
    
    // DAPAT: Direct to For Pickup or For Drop-off
    $new_status = $return_method === 'Pickup' ? 'For Pickup' : 'For Drop-off';
    
    // Store schedule/address details as JSON
    $details = [
        'method' => $return_method,
        'notes' => $notes
    ];
    
    // Add Pickup specific fields
    if ($return_method === 'Pickup') {
        $details['pickup_date'] = $_POST['pickup_date'] ?? null;
        $details['pickup_time'] = $_POST['pickup_time'] ?? null;
        $details['pickup_address'] = $_POST['pickup_address'] ?? null;
    } 
    // Add Drop-off specific fields
    else {
        $details['dropoff_address'] = $_POST['dropoff_address'] ?? null;
        $details['dropoff_contact'] = $_POST['dropoff_contact'] ?? null;
        $details['dropoff_phone'] = $_POST['dropoff_phone'] ?? null;
        $details['dropoff_hours'] = $_POST['dropoff_hours'] ?? null;
    }
    
    $schedule_details = json_encode($details);
    
    // Verify this return belongs to this supplier
    $check_query = "
        SELECT r.id, r.po_id, r.pr_id 
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        WHERE po.supplier_id = ? AND r.id = ?
    ";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$supplier_id, $return_id]);
    $return_data = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return_data) {
        throw new Exception('Unauthorized access to this return');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update return status to For Pickup or For Drop-off
        $update_query = "
            UPDATE returns
            SET status = ?, 
                return_method = ?, 
                schedule_details = ?,
                supplier_response = ?,
                response_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$new_status, $return_method, $schedule_details, $notes, $return_id]);
        
        // Update PO status to match
        if ($return_data['po_id']) {
            $update_po = $pdo->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $update_po->execute([$new_status, $return_data['po_id']]);
        }
        
        // Update PR status to match
        if ($return_data['pr_id']) {
            $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = ?, updated_at = NOW() WHERE id = ?");
            $update_pr->execute([$new_status, $return_data['pr_id']]);
        }
        
        // Add to timeline
        $timeline_query = "
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";
        $timeline_stmt = $pdo->prepare($timeline_query);
        $timeline_notes = "Return approved. Method: $return_method. " . ($notes ? "Notes: $notes" : "");
        $timeline_stmt->execute([$return_id, $new_status, $timeline_notes, $_SESSION['user_id'] ?? null]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Return approved successfully. Status: $new_status",
            'data' => [
                'return_id' => $return_id,
                'status' => $new_status,
                'method' => $return_method,
                'schedule_details' => $details
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function rejectReturn($pdo, $supplier_id) {
    // Get POST data
    $return_id = $_POST['return_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    
    // Validate required fields
    if (!$return_id) {
        throw new Exception('Return ID is required');
    }
    
    if (empty($reason)) {
        throw new Exception('Reason for rejection is required');
    }
    
    // Verify this return belongs to this supplier
    $check_query = "
        SELECT r.id, r.po_id, r.pr_id, r.status
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        WHERE po.supplier_id = ? AND r.id = ?
    ";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$supplier_id, $return_id]);
    $return_data = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return_data) {
        throw new Exception('Unauthorized access to this return');
    }
    
    // Check if return can be rejected (only Pending status)
    if ($return_data['status'] !== 'Pending') {
        throw new Exception('Only pending returns can be rejected. Current status: ' . $return_data['status']);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update return status to Rejected
        $update_query = "
            UPDATE returns
            SET status = 'Rejected', 
                supplier_response = ?,
                response_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$reason, $return_id]);
        
        // Update PO and PR status back to original
        if ($return_data['po_id']) {
            $update_po = $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?");
            $update_po->execute([$return_data['po_id']]);
        }
        
        if ($return_data['pr_id']) {
            $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = 'Completed', updated_at = NOW() WHERE id = ?");
            $update_pr->execute([$return_data['pr_id']]);
        }
        
        // Add to timeline
        $timeline_query = "
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            VALUES (?, 'Rejected', ?, ?, NOW())
        ";
        $timeline_stmt = $pdo->prepare($timeline_query);
        $timeline_notes = "Return rejected. Reason: $reason";
        $timeline_stmt->execute([$return_id, $timeline_notes, $_SESSION['user_id'] ?? null]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Return rejected successfully',
            'data' => [
                'return_id' => $return_id,
                'status' => 'Rejected',
                'reason' => $reason
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ✅ BAGONG FUNCTION: Complete Return (Supplier clicks after receiving items)
function completeReturn($pdo, $supplier_id) {
    $return_id = $_POST['return_id'] ?? 0;
    
    if (!$return_id) {
        throw new Exception('Return ID is required');
    }
    
    // Verify this return belongs to this supplier and is in valid status
    $check_query = "
        SELECT r.id, r.po_id, r.pr_id, r.status
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        WHERE po.supplier_id = ? AND r.id = ?
    ";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$supplier_id, $return_id]);
    $return_data = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return_data) {
        throw new Exception('Unauthorized access to this return');
    }
    
    // ✅ Only allow completion from Ready for Pickup or Dropped Off status
    $allowed_statuses = ['Ready for Pickup', 'Dropped Off'];
    if (!in_array($return_data['status'], $allowed_statuses)) {
        throw new Exception('Return can only be completed when status is Ready for Pickup or Dropped Off. Current status: ' . $return_data['status']);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // 1. Update return status to Return Completed
        $update_query = "
            UPDATE returns
            SET status = 'Return Completed',
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$return_id]);
        
        // 2. Update PO status
        if ($return_data['po_id']) {
            $update_po = $pdo->prepare("UPDATE purchase_orders SET status = 'Return Completed', updated_at = NOW() WHERE id = ?");
            $update_po->execute([$return_data['po_id']]);
        }
        
        // 3. Update PR status
        if ($return_data['pr_id']) {
            $update_pr = $pdo->prepare("UPDATE purchase_requests SET status = 'Return Completed', updated_at = NOW() WHERE id = ?");
            $update_pr->execute([$return_data['pr_id']]);
        }
        
        // 4. ✅ UPDATE EXPENSES TABLE
        // Get the pr_id from the return data (meron na tayo)
        $pr_id = $return_data['pr_id'];
        
        if ($pr_id) {
            // Debug: Check current expense status
            $checkExpense = $pdo->prepare("SELECT id, status, expense_code FROM expenses WHERE pr_id = ?");
            $checkExpense->execute([$pr_id]);
            $expense = $checkExpense->fetch(PDO::FETCH_ASSOC);
            
            // Log for debugging (check your PHP error log)
            error_log("Complete Return - PR ID: $pr_id");
            error_log("Complete Return - Expense found: " . ($expense ? json_encode($expense) : 'NOT FOUND'));
            
            if ($expense) {
                $updateExpense = $pdo->prepare("
                    UPDATE expenses 
                    SET status = 'Return Completed',
                        notes = CONCAT(IFNULL(notes, ''), '\n[RETURN] Return completed by supplier on ', NOW())
                    WHERE pr_id = ? AND status IN ('Return Requested', 'Waiting For Delivery')
                ");
                $updateExpense->execute([$pr_id]);
                error_log("Rows affected in expenses: " . $updateExpense->rowCount());
            } else {
                error_log("No expense found for PR ID: $pr_id");
            }
        }
        
        // 5. Add to timeline
        $timeline_query = "
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            VALUES (?, 'Return Completed', 'Return completed by supplier', ?, NOW())
        ";
        $timeline_stmt = $pdo->prepare($timeline_query);
        $timeline_stmt->execute([$return_id, $_SESSION['user_id'] ?? null]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Return has been completed successfully. Expense status updated to Return Completed.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Complete Return Error: " . $e->getMessage());
        throw $e;
    }
}
?>