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

// Load permissions to session
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'SCM';
$clinic_id = $_SESSION['clinic_id'] ?? 1;
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ ============ GET JSON INPUT ============
$raw_input = file_get_contents('php://input');
$data = [];

if (!empty($raw_input)) {
    $data = json_decode($raw_input, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        // Merge JSON data into $_POST for compatibility
        foreach ($data as $key => $value) {
            if (!isset($_POST[$key])) {
                $_POST[$key] = $value;
            }
        }
    }
}

// ✅ Debug log
error_log("Purchase Request API - Raw input: " . $raw_input);
error_log("Purchase Request API - Decoded data: " . print_r($data, true));

// ✅ RBAC Permission Helper Class
class PurchaseRequestPermission {
    private static $module = 'purchase_requests';
    
    public static function can($action) {
        $permissionMap = [
            'view' => self::$module . '_view',
            'create' => self::$module . '_create',
            'edit' => self::$module . '_edit',
            'delete' => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject' => self::$module . '_reject'
        ];
        
        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }
    
    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                echo json_encode(['error' => 'Permission denied: Cannot ' . $action . ' purchase requests']);
                exit;
            }
            return false;
        }
        return true;
    }
    
    public static function checkAndExit($action) {
        return self::check($action, true);
    }
}

// ============================================
// CREATE FROM REORDER SUGGESTIONS
// ============================================
// Check both POST and JSON data for action
$action = $_POST['action'] ?? $data['action'] ?? '';

// ============================================
// CREATE FROM REORDER SUGGESTIONS
// ============================================
if ($action === 'create_from_reorder') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('create');
    
    try {
        // Get data from JSON or POST
        $items = $data['items'] ?? $_POST['items'] ?? [];
        $total_cost = $data['total_cost'] ?? $_POST['total_cost'] ?? 0;
        
        error_log("Create from reorder - Items: " . print_r($items, true));
        error_log("Create from reorder - Total cost: " . $total_cost);
        
        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'No items selected']);
            exit;
        }
        
        // Generate PR number
        $datePrefix = date('Ymd');
        $randNum = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $pr_number = 'PR-' . $datePrefix . '-' . $randNum;
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Insert purchase request
        $query = "INSERT INTO purchase_requests 
                  (pr_number, clinic_id, department, priority, needed_by, purpose, notes,
                   status, total_amount, requested_by, created_at, updated_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $pr_number,
            $clinic_id,
            'SCM',
            'Medium',
            date('Y-m-d', strtotime('+7 days')),
            'Auto-generated from reorder suggestions',
            'Created from inventory low stock alert',
            'Pending Approval',
            floatval($total_cost),
            $current_user_id
        ]);
        
        $pr_id = $pdo->lastInsertId();
        
        // ✅ FIXED: Remove inventory_id from the query
        $itemQuery = "INSERT INTO pr_items 
                      (pr_id, item_name, description, quantity, unit_price, total_price)
                      VALUES (?, ?, ?, ?, ?, ?)";
        
        $itemStmt = $pdo->prepare($itemQuery);
        
        foreach ($items as $item) {
            $quantity = intval($item['suggested_quantity'] ?? $item['quantity'] ?? 0);
            $total = floatval($item['estimated_cost'] ?? $item['cost'] ?? 0);
            $unit_price = $quantity > 0 ? $total / $quantity : 0;
            
            $itemStmt->execute([
                $pr_id,
                $item['name'] ?? '',
                $item['brand'] ?? '',
                $quantity,
                $unit_price,
                $total
            ]);
        }
        
        $pdo->commit();
        
        // Create notification for Finance users
        $notif_message = "New purchase request #{$pr_number} requires your approval. Total: ₱" . number_format($total_cost, 2);
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // Get Finance users
        $getFinanceUsers = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role IN ('Finance', 'ClinicAdmin')
            AND status = 'Active'
        ");
        $getFinanceUsers->execute([$clinic_id]);
        $finance_users = $getFinanceUsers->fetchAll();
        
        foreach ($finance_users as $user) {
            $insertNotif->execute([
                $user['id'],
                'New Purchase Request',
                $notif_message,
                'warning',
                $pr_number,
                "main.php?view=purchase_request&id={$pr_id}"
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request created successfully',
            'pr_number' => $pr_number,
            'pr_id' => $pr_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating purchase request: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
// ============================================
// CREATE NEW PURCHASE REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('create');
    
    try {
        // Validate required fields
        $required_fields = ['department', 'priority', 'needed_by', 'purpose'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Validate items
        if (empty($_POST['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one item is required']);
            exit;
        }
        
        $items = json_decode($_POST['items'], true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid items data']);
            exit;
        }
        
        // Generate PR number
        $datePrefix = date('Ymd');
        $randNum = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $pr_number = 'PR-' . $datePrefix . '-' . $randNum;
        
        // Calculate totals
        $total_amount = 0;
        foreach ($items as $item) {
            $item_total = floatval($item['quantity']) * floatval($item['unit_price']);
            $total_amount += $item_total;
        }
        
        $status = $_POST['status'] ?? 'Pending Approval';
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Insert purchase request
        $query = "INSERT INTO purchase_requests 
                  (pr_number, department, priority, needed_by, purpose, notes, 
                   status, total_amount, requested_by, clinic_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $pr_number,
            $_POST['department'],
            $_POST['priority'],
            $_POST['needed_by'],
            $_POST['purpose'],
            $_POST['notes'] ?? '',
            $status,
            $total_amount,
            $current_user_id,
            $clinic_id
        ]);
        
        $pr_id = $pdo->lastInsertId();
        
        // ✅ Check if pr_items table exists - INSERT ITEMS
        $tableExists = $pdo->query("SHOW TABLES LIKE 'pr_items'")->fetch();
        
        if ($tableExists) {
            // Insert items
            foreach ($items as $item) {
                $item_query = "INSERT INTO pr_items 
                              (clinic_id, pr_id, item_name, description, quantity, 
                               unit_price, total_price, supplier_id, supplier_name)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $item_stmt = $pdo->prepare($item_query);
                $item_total = floatval($item['quantity']) * floatval($item['unit_price']);
                
                $item_stmt->execute([
                    $clinic_id,
                    $pr_id,
                    $item['item_name'],
                    $item['description'] ?? '',
                    $item['quantity'],
                    $item['unit_price'],
                    $item_total,
                    $item['supplier_id'] ?? null,
                    $item['supplier_name'] ?? null
                ]);
            }
        }
        
        // ✅ Create notification for Finance users
        if ($status === 'Pending Approval') {
            $notif_message = "New purchase request #{$pr_number} requires your approval. Total: ₱" . number_format($total_amount, 2);
            
            $insertNotif = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, reference_number, link, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            // Get Finance users
            $getFinanceUsers = $pdo->prepare("
                SELECT id FROM users 
                WHERE clinic_id = ? 
                AND role IN ('Finance', 'ClinicAdmin')
                AND status = 'Active'
            ");
            $getFinanceUsers->execute([$clinic_id]);
            $finance_users = $getFinanceUsers->fetchAll();
            
            foreach ($finance_users as $user) {
                $insertNotif->execute([
                    $user['id'],
                    'New Purchase Request',
                    $notif_message,
                    'warning',
                    $pr_number,
                    "purchase_request.php?view=details&id={$pr_id}"
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $status === 'Draft' ? 
                'Purchase request saved as draft successfully' : 
                'Purchase request submitted for approval successfully',
            'pr_number' => $pr_number,
            'pr_id' => $pr_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// UPDATE PURCHASE REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('edit');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // Check ownership for non-admin roles
        if (!PurchaseRequestPermission::can('approve') && !PurchaseRequestPermission::can('reject')) {
            $checkQuery = "SELECT id FROM purchase_requests WHERE id = ? AND requested_by = ?";
            $checkStmt = $pdo->prepare($checkQuery);
            $checkStmt->execute([$pr_id, $current_user_id]);
            if (!$checkStmt->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only edit your own PRs']);
                exit;
            }
        }
        
        $required_fields = ['department', 'priority', 'needed_by', 'purpose'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Field '$field' is required"]);
                exit;
            }
        }
        
        if (empty($_POST['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one item is required']);
            exit;
        }
        
        $items = json_decode($_POST['items'], true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid items data']);
            exit;
        }
        
        // Calculate total
        $total_amount = 0;
        foreach ($items as $item) {
            $item_total = floatval($item['quantity']) * floatval($item['unit_price']);
            $total_amount += $item_total;
        }
        
        $status = $_POST['status'] ?? 'Pending Approval';
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Update purchase request
        $query = "UPDATE purchase_requests SET 
                  department = ?, priority = ?, needed_by = ?, purpose = ?, notes = ?,
                  status = ?, total_amount = ?, updated_at = NOW()
                  WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $_POST['department'],
            $_POST['priority'],
            $_POST['needed_by'],
            $_POST['purpose'],
            $_POST['notes'] ?? '',
            $status,
            $total_amount,
            $pr_id
        ]);
        
        // Delete existing items if table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'pr_items'")->fetch();
        if ($tableExists) {
            $deleteItems = $pdo->prepare("DELETE FROM pr_items WHERE pr_id = ?");
            $deleteItems->execute([$pr_id]);
            
            // Insert new items
            foreach ($items as $item) {
                $item_query = "INSERT INTO pr_items 
                              (pr_id, item_name, description, quantity, 
                               unit_price, total_price, supplier_id, supplier_name)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                $item_stmt = $pdo->prepare($item_query);
                $item_total = floatval($item['quantity']) * floatval($item['unit_price']);
                
                $item_stmt->execute([
                    $pr_id,
                    $item['item_name'],
                    $item['description'] ?? '',
                    $item['quantity'],
                    $item['unit_price'],
                    $item_total,
                    $item['supplier_id'] ?? null,
                    $item['supplier_name'] ?? null
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request updated successfully',
            'pr_id' => $pr_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// DELETE PURCHASE REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('delete');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // Check ownership for non-admin roles
        if (!PurchaseRequestPermission::can('approve')) {
            $checkQuery = "SELECT id FROM purchase_requests WHERE id = ? AND requested_by = ?";
            $checkStmt = $pdo->prepare($checkQuery);
            $checkStmt->execute([$pr_id, $current_user_id]);
            if (!$checkStmt->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only delete your own PRs']);
                exit;
            }
        }
        
        // Check if PR can be deleted (only Draft or Pending status)
        $statusQuery = "SELECT status FROM purchase_requests WHERE id = ?";
        $statusStmt = $pdo->prepare($statusQuery);
        $statusStmt->execute([$pr_id]);
        $pr = $statusStmt->fetch();
        
        if (!$pr) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase request not found']);
            exit;
        }
        
        if (!in_array($pr['status'], ['Draft', 'Pending Approval'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete PR with status: ' . $pr['status']]);
            exit;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Delete items if table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'pr_items'")->fetch();
        if ($tableExists) {
            $deleteItems = $pdo->prepare("DELETE FROM pr_items WHERE pr_id = ?");
            $deleteItems->execute([$pr_id]);
        }
        
        // Delete PR
        $deletePR = $pdo->prepare("DELETE FROM purchase_requests WHERE id = ?");
        $deletePR->execute([$pr_id]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// SUBMIT DRAFT FOR APPROVAL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    // ✅ RBAC Check - submit requires edit permission
    PurchaseRequestPermission::checkAndExit('edit');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // Check ownership
        $checkQuery = "SELECT id, status, total_amount, pr_number FROM purchase_requests WHERE id = ? AND requested_by = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$pr_id, $current_user_id]);
        $pr = $checkStmt->fetch();
        
        if (!$pr) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only submit your own PRs']);
            exit;
        }
        
        if ($pr['status'] !== 'Draft') {
            http_response_code(400);
            echo json_encode(['error' => 'Only draft PRs can be submitted']);
            exit;
        }
        
        // Update status
        $updateQuery = "UPDATE purchase_requests SET status = 'Pending Approval', updated_at = NOW() WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$pr_id]);
        
        // ✅ Create notification for Finance users
        $notif_message = "Purchase request #{$pr['pr_number']} has been submitted for approval. Total: ₱" . number_format($pr['total_amount'], 2);
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // Get Finance users
        $getFinanceUsers = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role IN ('Finance', 'ClinicAdmin')
            AND status = 'Active'
        ");
        $getFinanceUsers->execute([$clinic_id]);
        $finance_users = $getFinanceUsers->fetchAll();
        
        foreach ($finance_users as $user) {
            $insertNotif->execute([
                $user['id'],
                'PR Submitted for Approval',
                $notif_message,
                'warning',
                $pr['pr_number'],
                "purchase_request.php?view=details&id={$pr_id}"
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request submitted for approval successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// APPROVE PURCHASE REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('approve');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // Get PR details
        $prQuery = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? AND status = 'Pending Approval'");
        $prQuery->execute([$pr_id]);
        $pr = $prQuery->fetch();
        
        if (!$pr) {
            http_response_code(400);
            echo json_encode(['error' => 'PR not found or not pending approval']);
            exit;
        }
        
        // Update status
        $updateQuery = "UPDATE purchase_requests SET 
                        status = 'Approved', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        notes = CONCAT(IFNULL(notes, ''), ?),
                        updated_at = NOW() 
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$current_user_id, "\n\nApproval Notes: " . $notes, $pr_id]);
        
        // ✅ Create notification for requester
        $notif_message = "Your purchase request #{$pr['pr_number']} has been approved.";
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insertNotif->execute([
            $pr['requested_by'],
            'PR Approved',
            $notif_message,
            'success',
            $pr['pr_number'],
            "purchase_request.php?view=details&id={$pr_id}"
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request approved successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// REJECT PURCHASE REQUEST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('reject');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        if (empty($reason)) {
            http_response_code(400);
            echo json_encode(['error' => 'Rejection reason is required']);
            exit;
        }
        
        // Get PR details
        $prQuery = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? AND status = 'Pending Approval'");
        $prQuery->execute([$pr_id]);
        $pr = $prQuery->fetch();
        
        if (!$pr) {
            http_response_code(400);
            echo json_encode(['error' => 'PR not found or not pending approval']);
            exit;
        }
        
        // Update status with rejection reason
        $updateQuery = "UPDATE purchase_requests SET 
                        status = 'Rejected', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        rejection_reason = ?,
                        notes = CONCAT(IFNULL(notes, ''), ?),
                        updated_at = NOW() 
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$current_user_id, $reason, "\n\nRejection Reason: " . $reason, $pr_id]);
        
        // ✅ Create notification for requester
        $notif_message = "Your purchase request #{$pr['pr_number']} has been rejected. Reason: {$reason}";
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insertNotif->execute([
            $pr['requested_by'],
            'PR Rejected',
            $notif_message,
            'danger',
            $pr['pr_number'],
            "purchase_request.php?view=details&id={$pr_id}"
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request rejected successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// CREATE PURCHASE ORDER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_po') {
    // ✅ RBAC Check - create PO requires approve permission
    PurchaseRequestPermission::checkAndExit('approve');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // Get PR details with items and requester info
        $prQuery = "SELECT pr.*, 
                           u.id as requester_id,
                           CONCAT(u.first_name, ' ', u.last_name) as requester_name
                    FROM purchase_requests pr
                    LEFT JOIN users u ON pr.requested_by = u.id
                    WHERE pr.id = ? AND pr.status = 'Approved'";
        $prStmt = $pdo->prepare($prQuery);
        $prStmt->execute([$pr_id]);
        $pr = $prStmt->fetch();
        
        if (!$pr) {
            http_response_code(400);
            echo json_encode(['error' => 'Approved PR not found']);
            exit;
        }
        
        // Get supplier_id from pr_items
        $itemQuery = "SELECT supplier_id, supplier_name FROM pr_items WHERE pr_id = ? AND supplier_id IS NOT NULL LIMIT 1";
        $itemStmt = $pdo->prepare($itemQuery);
        $itemStmt->execute([$pr_id]);
        $item = $itemStmt->fetch();
        $supplier_id = $item['supplier_id'] ?? 0;
        $supplier_name = $item['supplier_name'] ?? 'Unknown Supplier';
        
        if (!$supplier_id) {
            http_response_code(400);
            echo json_encode(['error' => 'No supplier assigned to this PR items']);
            exit;
        }
        
        // START TRANSACTION
        $pdo->beginTransaction();
        
        // Generate PO number
        $datePrefix = date('Ymd');
        $randNum = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $po_number = 'PO-' . $datePrefix . '-' . $randNum;
        
        // Insert into purchase_orders
        $query = "INSERT INTO purchase_orders 
                  (po_number, pr_id, supplier_id, order_date, expected_date, terms, shipping_address,
                   total_amount, status, created_by, clinic_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Supplier Approval', ?, ?)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $po_number,
            $pr_id,
            $supplier_id,
            $_POST['order_date'] ?? date('Y-m-d'),
            $_POST['expected_date'] ?? null,
            $_POST['terms'] ?? '',
            $_POST['shipping_address'] ?? '',
            $pr['total_amount'],
            $current_user_id,
            $clinic_id
        ]);
        
        $po_id = $pdo->lastInsertId();
        
        // Update PR status to 'Pending Supplier Approval'
        $updatePR = $pdo->prepare("UPDATE purchase_requests SET 
                                    status = 'Pending Supplier Approval', 
                                    po_number = ? 
                                    WHERE id = ?");
        $updatePR->execute([$po_number, $pr_id]);
        
        // Update linked expense with PO number
        $expenseQuery = "SELECT id FROM expenses WHERE pr_id = ? AND clinic_id = ?";
        $expenseStmt = $pdo->prepare($expenseQuery);
        $expenseStmt->execute([$pr_id, $clinic_id]);
        $expense = $expenseStmt->fetch();
        
        if ($expense) {
            $updateExpense = $pdo->prepare("UPDATE expenses SET po_number = ?, updated_at = NOW() WHERE id = ?");
            $updateExpense->execute([$po_number, $expense['id']]);
        }
        
        // ✅ Create notifications
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // 1. Notify the CREATOR
        $creator_message = "You have successfully created PO #{$po_number} for PR #{$pr['pr_number']}.";
        $insertNotif->execute([
            $current_user_id,
            'PO Created',
            $creator_message,
            'success',
            $po_number,
            "purchase_order.php?view=details&id={$po_id}"
        ]);
        
        // 2. Notify the REQUESTER
        if ($pr['requester_id'] && $pr['requester_id'] != $current_user_id) {
            $requester_message = "PO #{$po_number} has been created for your PR #{$pr['pr_number']}. Status: Pending Supplier Approval.";
            $insertNotif->execute([
                $pr['requester_id'],
                'PO Created for Your PR',
                $requester_message,
                'info',
                $po_number,
                "purchase_request.php?view=details&id={$pr_id}"
            ]);
        }
        
        // 3. Notify FINANCE roles
        $finance_message = "PO #{$po_number} has been created for PR #{$pr['pr_number']} (Supplier: {$supplier_name}). Total: ₱" . number_format($pr['total_amount'], 2);
        
        $getFinanceUsers = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'Finance'
            AND id != ?
            AND status = 'Active'
        ");
        $getFinanceUsers->execute([$clinic_id, $current_user_id]);
        $finance_users = $getFinanceUsers->fetchAll();
        
        foreach ($finance_users as $finance_user) {
            $insertNotif->execute([
                $finance_user['id'],
                'New Purchase Order Created',
                $finance_message,
                'info',
                $po_number,
                "purchase_order.php?view=details&id={$po_id}"
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase order created successfully. Notifications sent.',
            'po_number' => $po_number,
            'po_id' => $po_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET APPROVED PR DETAILS (for PO creation)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_approved_pr_details') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    try {
        $pr_id = $_GET['id'] ?? 0;
        
        if (!$pr_id) {
            echo json_encode(['success' => false, 'error' => 'PR ID is required']);
            exit;
        }
        
        // Get PR details with approver info
        $prQuery = "SELECT pr.*, 
                           CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                           CONCAT(a.first_name, ' ', a.last_name) as approved_by_name,
                           pr.approved_at
                    FROM purchase_requests pr
                    LEFT JOIN users u ON pr.requested_by = u.id
                    LEFT JOIN users a ON pr.approved_by = a.id
                    WHERE pr.id = ? AND pr.clinic_id = ? AND pr.status = 'Approved'";
        
        $prStmt = $pdo->prepare($prQuery);
        $prStmt->execute([$pr_id, $clinic_id]);
        $pr = $prStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pr) {
            echo json_encode(['success' => false, 'error' => 'Approved PR not found']);
            exit;
        }
        
        // Get items with supplier details
        $itemsQuery = "SELECT i.*, 
                              s.id as supplier_id,
                              s.supplier_name,
                              s.contact_person,
                              s.email,
                              s.mobile,
                              s.phone,
                              s.address,
                              s.city,
                              s.payment_terms,
                              s.tax_id
                       FROM pr_items i
                       LEFT JOIN suppliers s ON i.supplier_id = s.id
                       WHERE i.pr_id = ?";
        
        $itemsStmt = $pdo->prepare($itemsQuery);
        $itemsStmt->execute([$pr_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get unique supplier info from items
        $supplier_info = null;
        if (!empty($items)) {
            foreach ($items as $item) {
                if (!empty($item['supplier_id'])) {
                    $address_parts = [];
                    if (!empty($item['address'])) $address_parts[] = $item['address'];
                    if (!empty($item['city'])) $address_parts[] = $item['city'];
                    
                    $supplier_info = [
                        'id' => $item['supplier_id'],
                        'name' => $item['supplier_name'],
                        'contact_person' => $item['contact_person'],
                        'email' => $item['email'],
                        'mobile' => $item['mobile'],
                        'phone' => $item['phone'],
                        'address' => implode(', ', $address_parts),
                        'payment_terms' => $item['payment_terms'],
                        'tax_id' => $item['tax_id']
                    ];
                    break;
                }
            }
        }
        
        $pr['items'] = $items;
        $pr['supplier_info'] = $supplier_info;
        
        echo json_encode([
            'success' => true,
            'data' => $pr
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// GET PR DETAILS WITH ITEMS AND SUPPLIER INFO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pr_details') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    try {
        $pr_id = $_GET['id'] ?? 0;
        
        // Get PR with supplier info and TRACKING from purchase_orders
        $query = "SELECT 
                    pr.*,
                    po.po_number,
                    po.tracking_number,  
                    po.carrier,           
                    po.shipped_date,      
                    po.supplier_id,
                    s.supplier_name,
                    s.contact_person,
                    s.phone as supplier_phone,
                    s.address as supplier_address
                  FROM purchase_requests pr
                  LEFT JOIN purchase_orders po ON pr.id = po.pr_id
                  LEFT JOIN suppliers s ON po.supplier_id = s.id
                  WHERE pr.id = ? AND pr.clinic_id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pr_id, $clinic_id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pr) {
            // Get items
            $itemsQuery = "SELECT * FROM pr_items WHERE pr_id = ?";
            $itemsStmt = $pdo->prepare($itemsQuery);
            $itemsStmt->execute([$pr_id]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $pr['items'] = $items;
            
            echo json_encode([
                'success' => true,
                'data' => $pr
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'PR not found']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RECEIVE ORDER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_order') {
    // ✅ RBAC Check - receive order requires edit permission
    PurchaseRequestPermission::checkAndExit('edit');
    
    try {
        $pr_id = $_POST['pr_id'] ?? 0;
        $received_items = json_decode($_POST['received_items'] ?? '[]', true);
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        $supplier_rating = $_POST['supplier_rating'] ?? 0;
        $supplier_tags = json_decode($_POST['supplier_tags'] ?? '[]', true);
        
        if (empty($received_items)) {
            throw new Exception('No items to receive');
        }
        
        $pdo->beginTransaction();
        
        // 1. Get PR details
        $prQuery = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ?");
        $prQuery->execute([$pr_id]);
        $pr = $prQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$pr) {
            throw new Exception('Purchase request not found');
        }
        
        // 2. Get PO details
        $poQuery = $pdo->prepare("SELECT * FROM purchase_orders WHERE pr_id = ?");
        $poQuery->execute([$pr_id]);
        $po = $poQuery->fetch(PDO::FETCH_ASSOC);
        
        // 3. Update PO to Delivered
        if ($po) {
            $updatePO = $pdo->prepare("UPDATE purchase_orders SET 
                                        status = 'Delivered', 
                                        actual_delivery = NOW(),
                                        delivery_notes = ?,
                                        updated_at = NOW()
                                        WHERE id = ?");
            $updatePO->execute([$delivery_notes, $po['id']]);
        }
        
        // 4. Save supplier rating
        if ($supplier_rating > 0 && $po) {
            $reviewQuery = $pdo->prepare("INSERT INTO supplier_reviews 
                (supplier_id, po_id, pr_id, rating, tags, review_text, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $reviewQuery->execute([
                $po['supplier_id'],
                $po['id'],
                $pr_id,
                $supplier_rating,
                json_encode($supplier_tags),
                $delivery_notes,
                $current_user_id
            ]);
        }
        
        // 5. Update PR to Completed
        $updatePR = $pdo->prepare("UPDATE purchase_requests SET 
                                    status = 'Completed', 
                                    updated_at = NOW() 
                                    WHERE id = ?");
        $updatePR->execute([$pr_id]);

        // 6. ✅ PROCESS INVENTORY UPDATES
foreach ($received_items as $item) {
    $inventory_action = $item['inventory_action'] ?? null;
    $received_qty     = intval($item['received'] ?? 0);

    if (!$inventory_action || $received_qty <= 0) continue;

    if ($inventory_action['type'] === 'add') {
        // ── Add stock to existing inventory item ──
        $inv_id = intval($inventory_action['inventory_id']);

        // Get current stock + min_stock to compute new status
        $getInv = $pdo->prepare("
            SELECT stock, min_stock, reorder_level 
            FROM inventory 
            WHERE id = ? AND clinic_id = ?
        ");
        $getInv->execute([$inv_id, $clinic_id]);
        $inv = $getInv->fetch(PDO::FETCH_ASSOC);

        if ($inv) {
            $new_stock  = $inv['stock'] + $received_qty;
            $min_stock  = $inv['min_stock'] ?? 10;

            // Compute correct item_status
            if ($new_stock <= 0) {
                $new_status = 'out-of-stock';
            } elseif ($new_stock <= $min_stock) {
                $new_status = 'low-stock';
            } else {
                $new_status = 'in-stock';
            }

            $updateInv = $pdo->prepare("
                UPDATE inventory 
                SET stock       = ?,
                    item_status = ?,
                    updated_by  = ?,
                    updated_at  = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $updateInv->execute([
                $new_stock,
                $new_status,
                $current_user_id,
                $inv_id,
                $clinic_id
            ]);
        }

    } elseif ($inventory_action['type'] === 'create') {
        // ── Create brand new inventory item ──
        $new_name   = trim($inventory_action['name'] ?? $item['item_name'] ?? 'New Item');
        $min_stock  = 10; // default
        $new_status = $received_qty <= 0 ? 'out-of-stock' 
                    : ($received_qty <= $min_stock ? 'low-stock' : 'in-stock');

        $insertInv = $pdo->prepare("
            INSERT INTO inventory 
                (clinic_id, name, stock, min_stock, reorder_level, 
                 item_status, created_by, updated_by, created_at, updated_at)
            VALUES 
                (?, ?, ?, ?, ?,
                 ?, ?, ?, NOW(), NOW())
        ");
        $insertInv->execute([
            $clinic_id,
            $new_name,
            $received_qty,
            $min_stock,
            5,             // default reorder_level
            $new_status,
            $current_user_id,
            $current_user_id
        ]);
    }
}
        
        // 6. Update expenses to 'Ready to Pay'
        $updateExpense = $pdo->prepare("UPDATE expenses SET 
                                         status = 'Ready to Pay', 
                                         updated_at = NOW() 
                                         WHERE pr_id = ?");
        $updateExpense->execute([$pr_id]);
        
        $pdo->commit();
        
        $message = 'Order received successfully';
        if ($supplier_rating > 0) $message .= ' and supplier rated';
        
        echo json_encode([
            'success' => true, 
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET ALL PRs WITH PAGINATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_prs') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    try {
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 10;
        $status = $_GET['status'] ?? 'all';
        $priority = $_GET['priority'] ?? '';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        $search = $_GET['search'] ?? '';
        
        $offset = ($page - 1) * $limit;
        
        // Base query - GET USER NAME FROM users TABLE WITH REJECTION NOTE
        $query = "SELECT pr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                         (SELECT COUNT(*) FROM pr_items WHERE pr_id = pr.id) as item_count,
                         (SELECT supplier_response FROM purchase_orders WHERE pr_id = pr.id AND status = 'Supplier Rejected' ORDER BY id DESC LIMIT 1) as rejection_note
                  FROM purchase_requests pr
                  LEFT JOIN users u ON pr.requested_by = u.id
                  WHERE pr.clinic_id = ?";
        
        $countQuery = "SELECT COUNT(*) as total
                       FROM purchase_requests pr
                       WHERE pr.clinic_id = ?";
        
        $conditions = [];
        $params = [$clinic_id];
        $countParams = [$clinic_id];
        
        // Role-based filtering
        if (!PurchaseRequestPermission::can('approve') && !PurchaseRequestPermission::can('reject')) {
            $conditions[] = "pr.requested_by = ?";
            $params[] = $current_user_id;
            $countParams[] = $current_user_id;
        }
        
        if ($status !== 'all') {
            $conditions[] = "pr.status = ?";
            $params[] = $status;
            $countParams[] = $status;
        }
        
        if (!empty($priority)) {
            $conditions[] = "pr.priority = ?";
            $params[] = $priority;
            $countParams[] = $priority;
        }
        
        if (!empty($date_from)) {
            $conditions[] = "DATE(pr.created_at) >= ?";
            $params[] = $date_from;
            $countParams[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $conditions[] = "DATE(pr.created_at) <= ?";
            $params[] = $date_to;
            $countParams[] = $date_to;
        }
        
        if (!empty($search)) {
            $conditions[] = "(pr.pr_number LIKE ? OR pr.purpose LIKE ? OR pr.department LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
        }
        
        // Build WHERE clause
        if (!empty($conditions)) {
            $whereClause = " AND " . implode(" AND ", $conditions);
            $query .= $whereClause;
            $countQuery .= " AND " . implode(" AND ", $conditions);
        }
        
        // Group and order
        $query .= " ORDER BY 
                   CASE pr.priority 
                       WHEN 'Critical' THEN 1
                       WHEN 'High' THEN 2
                       WHEN 'Medium' THEN 3
                       WHEN 'Low' THEN 4
                   END, 
                   pr.created_at DESC
                   LIMIT ? OFFSET ?";
        
        // Add limit and offset parameters
        $params[] = $limit;
        $params[] = $offset;
        
        // Execute main query
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($countParams);
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = $totalResult['total'] ?? 0;
        
        echo json_encode([
            'success' => true,
            'data' => $prs,
            'pagination' => [
                'total' => (int)$total,
                'current_page' => (int)$page,
                'last_page' => ceil($total / $limit),
                'per_page' => (int)$limit
            ],
            'user_role' => $user_role,
            'permissions' => [
                'can_approve' => PurchaseRequestPermission::can('approve'),
                'can_reject' => PurchaseRequestPermission::can('reject'),
                'can_edit' => PurchaseRequestPermission::can('edit'),
                'can_delete' => PurchaseRequestPermission::can('delete')
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET SINGLE PURCHASE REQUEST DETAILS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    $pr_id = $_GET['id'] ?? 0;
    
    if (!$pr_id) {
        echo json_encode(['error' => 'PR ID is required']);
        exit;
    }
    
    try {
        // Get PR details
        $query = "SELECT pr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                         CONCAT(ua.first_name, ' ', ua.last_name) as approved_by_name
                  FROM purchase_requests pr
                  LEFT JOIN users u ON pr.requested_by = u.id
                  LEFT JOIN users ua ON pr.approved_by = ua.id
                  WHERE pr.id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pr_id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pr) {
            echo json_encode(['error' => 'Purchase request not found']);
            exit;
        }
        
        // Check ownership for non-admin roles
        if (!PurchaseRequestPermission::can('approve') && !PurchaseRequestPermission::can('reject')) {
            if ($pr['requested_by'] != $current_user_id) {
                echo json_encode(['error' => 'You can only view your own PRs']);
                exit;
            }
        }
        
        // Get items with COMPLETE supplier details
        $itemsQuery = "SELECT i.*, 
                              s.id as supplier_id,
                              s.supplier_name,
                              s.contact_person as supplier_contact,
                              s.email as supplier_email,
                              s.mobile as supplier_mobile,
                              s.phone as supplier_phone,
                              s.payment_terms as supplier_payment_terms,
                              s.city as supplier_city
                       FROM pr_items i
                       LEFT JOIN suppliers s ON i.supplier_id = s.id
                       WHERE i.pr_id = ?";
        
        $itemsStmt = $pdo->prepare($itemsQuery);
        $itemsStmt->execute([$pr_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pr['items'] = $items;
        
        // GET rejection history
        $rejectionQuery = "
          SELECT 
    'supplier' as type,
    po.status,
    CAST(po.supplier_response AS CHAR) as message,
    po.response_date as date,
    po.po_number,
    CAST((SELECT supplier_name FROM suppliers WHERE id = po.supplier_id) AS CHAR) as source_name
FROM purchase_orders po
WHERE po.pr_id = ? AND po.status = 'Supplier Rejected'

UNION ALL

SELECT 
    'finance' as type,
    'Rejected' as status,
    CAST(pr.rejection_reason AS CHAR) as message,
    pr.updated_at as date,
    NULL as po_number,
    CAST(CONCAT(u.first_name, ' ', u.last_name) AS CHAR) as source_name
FROM purchase_requests pr
LEFT JOIN users u ON pr.approved_by = u.id
WHERE pr.id = ? AND pr.status = 'Rejected' AND pr.rejection_reason IS NOT NULL

UNION ALL

SELECT 
    'finance_note' as type,
    'Approval Note' as status,
    CAST(pr.notes AS CHAR) as message,
    pr.approved_at as date,
    NULL as po_number,
    CAST(CONCAT(u.first_name, ' ', u.last_name) AS CHAR) as source_name
FROM purchase_requests pr
LEFT JOIN users u ON pr.approved_by = u.id
WHERE pr.id = ? AND pr.notes IS NOT NULL AND pr.notes != '' AND pr.status = 'Approved'

ORDER BY date DESC";
        
        $rejectionStmt = $pdo->prepare($rejectionQuery);
        $rejectionStmt->execute([$pr_id, $pr_id, $pr_id]);
        $rejection_notes = $rejectionStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pr['rejection_notes'] = $rejection_notes;
        
        // Get PO history
        $poQuery = "SELECT 
                        po_number,
                        status as po_status,
                        order_date as date_created,
                        supplier_response,
                        expected_date,
                        total_amount,
                        (SELECT supplier_name FROM suppliers WHERE id = po.supplier_id) as supplier_name
                    FROM purchase_orders po
                    WHERE po.pr_id = ?
                    ORDER BY order_date DESC";
        
        $poStmt = $pdo->prepare($poQuery);
        $poStmt->execute([$pr_id]);
        $po_history = $poStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pr['po_history'] = $po_history;
        
        echo json_encode(['success' => true, 'data' => $pr]);
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET REORDER REPORT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'reorder_report') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    try {
        // Check if inventory_items table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'inventory_items'")->fetch();
        
        if ($tableExists) {
            $query = "SELECT 
                         i.item_name,
                         i.current_stock,
                         i.min_stock,
                         i.max_stock,
                         s.supplier_name,
                         CASE 
                             WHEN i.current_stock <= i.min_stock THEN 'CRITICAL'
                             WHEN i.current_stock <= (i.min_stock * 1.5) THEN 'LOW'
                             ELSE 'OK'
                         END as stock_level,
                         GREATEST(i.max_stock - i.current_stock, 0) as suggested_order
                      FROM inventory_items i
                      LEFT JOIN suppliers s ON i.preferred_supplier_id = s.id
                      WHERE i.clinic_id = ? 
                        AND i.current_stock <= (i.max_stock * 0.7)
                      ORDER BY stock_level, i.current_stock ASC";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$clinic_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $items
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => [],
                'message' => 'Inventory system not yet implemented'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// EXPORT PRs
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('view');
    
    try {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="purchase_requests_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'PR Number', 'Department', 'Priority', 'Purpose', 'Needed By', 
            'Status', 'Total Amount', 'Requested By', 'Created Date'
        ]);
        
        $status = $_GET['status'] ?? 'all';
        $priority = $_GET['priority'] ?? '';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        $search = $_GET['search'] ?? '';
        
        $query = "SELECT pr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as requester_name 
                  FROM purchase_requests pr
                  LEFT JOIN users u ON pr.requested_by = u.id
                  WHERE pr.clinic_id = ?";
        $conditions = [];
        $params = [$clinic_id];
        
        if (!PurchaseRequestPermission::can('approve') && !PurchaseRequestPermission::can('reject')) {
            $conditions[] = "pr.requested_by = ?";
            $params[] = $current_user_id;
        }
        
        if ($status !== 'all') {
            $conditions[] = "pr.status = ?";
            $params[] = $status;
        }
        
        if (!empty($priority)) {
            $conditions[] = "pr.priority = ?";
            $params[] = $priority;
        }
        
        if (!empty($date_from)) {
            $conditions[] = "DATE(pr.created_at) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $conditions[] = "DATE(pr.created_at) <= ?";
            $params[] = $date_to;
        }
        
        if (!empty($search)) {
            $conditions[] = "(pr.pr_number LIKE ? OR pr.purpose LIKE ? OR pr.department LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }
        
        $query .= " ORDER BY pr.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['pr_number'],
                $row['department'],
                $row['priority'],
                $row['purpose'],
                $row['needed_by'],
                $row['status'],
                '₱' . number_format($row['total_amount'], 2),
                $row['requester_name'] ?? 'Unknown',
                $row['created_at']
            ]);
        }
        
        fclose($output);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// CREATE MULTIPLE PRs (for cart checkout)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_multiple_prs') {
    // ✅ RBAC Check
    PurchaseRequestPermission::checkAndExit('create');
    
    try {
        $department = $_POST['department'] ?? '';
        $priority = $_POST['priority'] ?? 'Medium';
        $needed_by = $_POST['needed_by'] ?? date('Y-m-d', strtotime('+7 days'));
        $purpose = $_POST['purpose'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $suppliers = json_decode($_POST['suppliers'] ?? '[]', true);
        
        if (empty($suppliers)) {
            throw new Exception('No suppliers data');
        }
        
        $pdo->beginTransaction();
        
        $pr_numbers = [];
        $pr_count = 0;
        
        foreach ($suppliers as $supplier_id => $supplier) {
            // Generate PR number
            $year = date('Y');
            $month = date('m');
            $prQuery = $pdo->query("SELECT COUNT(*) as count FROM purchase_requests WHERE YEAR(created_at) = $year AND MONTH(created_at) = $month");
            $prCount = $prQuery->fetch()['count'] + 1;
            $pr_number = 'PR-' . $year . $month . '-' . str_pad($prCount, 4, '0', STR_PAD_LEFT);
            
            // Calculate supplier total
            $supplier_total = 0;
            foreach ($supplier['products'] as $product) {
                $supplier_total += $product['subtotal'];
            }
            
            // Insert PR
            $insertPR = $pdo->prepare("
                INSERT INTO purchase_requests 
                (pr_number, clinic_id, department, priority, purpose, notes, needed_by, 
                 total_amount, status, requested_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval', ?, NOW())
            ");
            
            $insertPR->execute([
                $pr_number,
                $clinic_id,
                $department,
                $priority,
                $purpose,
                $notes,
                $needed_by,
                $supplier_total,
                $current_user_id
            ]);
            
            $pr_id = $pdo->lastInsertId();
            
            // Insert PR items
            foreach ($supplier['products'] as $product) {
                $insertItem = $pdo->prepare("
                    INSERT INTO pr_items 
                    (pr_id, supplier_product_id, supplier_id, supplier_name, item_name, 
                     quantity, unit_price, total_price)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $insertItem->execute([
                    $pr_id,
                    $product['id'],
                    $supplier_id,
                    $supplier['name'],
                    $product['product_name'],
                    $product['cart_qty'],
                    $product['cost_price'],
                    $product['subtotal']
                ]);
            }
            
            $pr_numbers[] = $pr_number;
            $pr_count++;
            
            // Create notification for requester
            $notif_message = "You have successfully created PR: $pr_number";
            $notif_link = "purchase_request.php?view=details&id=$pr_id";
            
            $insertNotif = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, reference_number, link, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $insertNotif->execute([
                $current_user_id,
                'PR Created',
                $notif_message,
                'success',
                $pr_number,
                $notif_link
            ]);
            
            // Notify Finance users
            $getFinanceUsers = $pdo->prepare("
                SELECT id FROM users 
                WHERE clinic_id = ? 
                AND role IN ('Finance', 'ClinicAdmin')
                AND id != ?
                AND status = 'Active'
            ");
            $getFinanceUsers->execute([$clinic_id, $current_user_id]);
            $finance_users = $getFinanceUsers->fetchAll();
            
            $finance_message = "New PR requires your attention: $pr_number - Total: ₱" . number_format($supplier_total, 2);
            
            foreach ($finance_users as $user) {
                $insertNotif->execute([
                    $user['id'],
                    'New PR for Approval',
                    $finance_message,
                    'warning',
                    $pr_number,
                    "purchase_request.php?view=details&id=$pr_id"
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "$pr_count PR(s) created successfully. Notifications sent to Finance and ClinicAdmin.",
            'count' => $pr_count,
            'pr_numbers' => $pr_numbers
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Default response for invalid actions
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>