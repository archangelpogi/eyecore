<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$clinicId = $_SESSION['clinic_id'] ?? 1;
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'User';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ✅ RBAC Permission Helper Class for 3D Models
class ThreeDModelPermission {
    private static $module = 'my-3d-models';
    
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
                echo json_encode(['success' => false, 'error' => 'Permission denied: Cannot ' . $action . ' 3D models']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ================== FUNCTION TO CREATE NOTIFICATION ==================
function createNotification($pdo, $userId, $title, $message, $type, $referenceNumber, $link) {
    try {
        // Check if notifications table exists
        $checkTable = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if ($checkTable->rowCount() == 0) {
            error_log("❌ Notifications table does not exist!");
            return false;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, reference_number, link, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([$userId, $title, $message, $type, $referenceNumber, $link]);
        
        if ($result) {
            error_log("✅ Notification created for user {$userId}: {$title}");
            return true;
        } else {
            error_log("❌ Failed to create notification: " . print_r($stmt->errorInfo(), true));
            return false;
        }
    } catch (Exception $e) {
        error_log("❌ Notification exception: " . $e->getMessage());
        return false;
    }
}

switch($action) {
    case 'create_3d_request_temp':
        // Create temporary request BEFORE payment (no payment proof yet)
        create3DRequestTemp($pdo, $clinicId, $userId);
        break;
        
    case 'create_3d_request':
        // ✅ Check create permission
        ThreeDModelPermission::check('create');
        create3DRequest($pdo, $clinicId, $userId);
        break;
        
    case 'get_my_requests':
        // ✅ Check view permission
        ThreeDModelPermission::check('view');
        getMyRequests($pdo, $clinicId, $userId);
        break;
        
    case 'get_request_details':
        // ✅ Check view permission
        ThreeDModelPermission::check('view');
        getRequestDetails($pdo);
        break;
        
    case 'cancel_request':
        // ✅ Check edit permission (to cancel own request)
        ThreeDModelPermission::check('edit');
        cancelRequest($pdo, $userId);
        break;
        
    case 'verify_payment':
        // ✅ Check approve permission (only admin/finance can verify payment)
        ThreeDModelPermission::check('approve');
        verifyPayment($pdo);
        break;
        
    case 'update_request_status':
        // ✅ Check approve permission (admin only)
        ThreeDModelPermission::check('approve');
        updateRequestStatus($pdo);
        break;
        
    case 'delete_request':
        // ✅ Check delete permission
        ThreeDModelPermission::check('delete');
        deleteRequest($pdo, $userId);
        break;
        
    case 'reject_request':
        // ✅ Check reject permission
        ThreeDModelPermission::check('reject');
        rejectRequest($pdo);
        break;

    case 'get_payment_details':
    getPaymentDetails($pdo);
    break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ============================================
// CREATE TEMPORARY 3D REQUEST (before PayMongo payment)
// ============================================
function create3DRequestTemp($pdo, $clinicId, $userId) {
    try {
        $item_id = $_POST['item_id'] ?? null;
        $product_name = $_POST['product_name'] ?? '';
        $model_type = $_POST['model_type'] ?? 'frame';
        $notes = $_POST['notes'] ?? '';
        $colors = $_POST['colors'] ?? '';
        
        if (!$item_id || !$product_name) {
            throw new Exception('Please fill in all required fields');
        }
        
        // Check if item exists
        $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$item_id, $clinicId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            throw new Exception('Item not found');
        }
        
        $pdo->beginTransaction();
        
        $request_number = '3DRQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
// Sa create_3d_request_temp function, siguraduhin na:
$insert = $pdo->prepare("
    INSERT INTO custom_3d_requests (
        request_number, clinic_id, user_id, inventory_id, product_name,
        model_type, notes, colors_requested, status, payment_status,
        price, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'pending_payment', 100.00, NOW())
");
        
        $insert->execute([
            $request_number, $clinicId, $userId, $item_id, $product_name,
            $model_type, $notes, $colors
        ]);
        
        $request_id = $pdo->lastInsertId();
        
        // Handle reference images
        $upload_dir = __DIR__ . '/../uploads/3d_requests/' . $request_id . '/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        if (!empty($_FILES['images'])) {
            $files = $_FILES['images'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] == 0) {
                    $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                    $filename = 'ref_' . ($i + 1) . '_' . time() . '.' . $ext;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $filename)) {
                        $img_stmt = $pdo->prepare("
                            INSERT INTO request_images (request_id, image_path, sort_order)
                            VALUES (?, ?, ?)
                        ");
                        $img_stmt->execute([
                            $request_id,
                            'uploads/3d_requests/' . $request_id . '/' . $filename,
                            $i
                        ]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'request_id' => $request_id,
            'request_number' => $request_number,
            'message' => 'Temporary request created. Proceed to payment.'
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// CREATE 3D REQUEST (with payment proof - manual)
// ============================================
function create3DRequest($pdo, $clinicId, $userId) {
    try {
        $item_id = $_POST['item_id'] ?? null;
        $product_name = $_POST['product_name'] ?? '';
        $model_type = $_POST['model_type'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $colors = $_POST['colors'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        
        if (!$item_id || !$product_name || !$model_type || !$payment_method) {
            throw new Exception('Please fill in all required fields');
        }
        
        // Check if item exists
        $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$item_id, $clinicId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            throw new Exception('Item not found');
        }
        
        $pdo->beginTransaction();
        
        $request_number = '3DRQ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Handle payment proof upload
        $payment_proof_path = null;
        if (!empty($_FILES['payment_proof'])) {
            $proof_dir = __DIR__ . '/../uploads/payment_proofs/';
            if (!file_exists($proof_dir)) {
                mkdir($proof_dir, 0777, true);
            }
            
            $file = $_FILES['payment_proof'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'payment_' . time() . '_' . uniqid() . '.' . $ext;
            
            if (move_uploaded_file($file['tmp_name'], $proof_dir . $filename)) {
                $payment_proof_path = 'uploads/payment_proofs/' . $filename;
            }
        }
        
        // Insert request
        $insert = $pdo->prepare("
            INSERT INTO custom_3d_requests (
                request_number, clinic_id, user_id, inventory_id, product_name,
                model_type, notes, colors_requested, status, payment_status,
                payment_method, payment_proof, price, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid', ?, ?, 100.00, NOW())
        ");
        
        $insert->execute([
            $request_number, $clinicId, $userId, $item_id, $product_name,
            $model_type, $notes, $colors, $payment_method, $payment_proof_path
        ]);
        
        $request_id = $pdo->lastInsertId();
        
        // Handle reference images
        $upload_dir = __DIR__ . '/../uploads/3d_requests/' . $request_id . '/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        if (!empty($_FILES['images'])) {
            $files = $_FILES['images'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] == 0) {
                    $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                    $filename = 'ref_' . ($i + 1) . '_' . time() . '.' . $ext;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $filename)) {
                        $img_stmt = $pdo->prepare("
                            INSERT INTO request_images (request_id, image_path, sort_order)
                            VALUES (?, ?, ?)
                        ");
                        $img_stmt->execute([
                            $request_id,
                            'uploads/3d_requests/' . $request_id . '/' . $filename,
                            $i
                        ]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
        // ===== NOTIFICATION FOR CLINIC OWNER =====
        createNotification(
            $pdo, 
            $userId, 
            "3D Model Request Submitted", 
            "Your request for '{$product_name}' has been submitted. Request #: {$request_number}. We'll notify you once payment is verified.",
            "request_submitted",
            $request_number,
            "main.php?view=my-3d-models&tab=pending"
        );
        
        // ===== NOTIFICATION FOR SUPERADMIN =====
        $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'SuperAdmin'");
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            createNotification(
                $pdo, 
                $admin['id'], 
                "New 3D Model Request", 
                "New request from Clinic ID {$clinicId}: '{$product_name}' (Ref: {$request_number})",
                "new_request",
                $request_number,
                "pages/3d_requests.php"
            );
        }
        
        echo json_encode([
            'success' => true,
            'request_id' => $request_id,
            'request_number' => $request_number,
            'message' => 'Request submitted! Please wait for payment confirmation.'
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// GET REQUEST DETAILS
// ============================================
function getRequestDetails($pdo) {
    try {
        $request_id = $_GET['id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT r.*, i.name as inventory_name, i.category, i.brand, i.item_code,
                   c.clinic_name, c.clinic_email as clinic_email, c.phone as clinic_phone,
                   u.first_name, u.last_name, u.email as user_email
            FROM custom_3d_requests r
            LEFT JOIN inventory i ON r.inventory_id = i.id
            LEFT JOIN clinics c ON r.clinic_id = c.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($request) {
            $img_stmt = $pdo->prepare("SELECT * FROM request_images WHERE request_id = ? ORDER BY sort_order");
            $img_stmt->execute([$request_id]);
            $request['images'] = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $request['formatted_price'] = '₱' . number_format($request['price'], 2);
            $request['created_at_formatted'] = date('M d, Y h:i A', strtotime($request['created_at']));
        }
        
        echo json_encode(['success' => true, 'data' => $request]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// GET MY REQUESTS
// ============================================
function getMyRequests($pdo, $clinicId, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, i.name as inventory_name, i.category,
                   (SELECT COUNT(*) FROM request_images WHERE request_id = r.id) as image_count
            FROM custom_3d_requests r
            LEFT JOIN inventory i ON r.inventory_id = i.id
            WHERE r.clinic_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$clinicId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($requests as &$req) {
            $req['formatted_price'] = '₱' . number_format($req['price'], 2);
            $req['created_at_formatted'] = date('M d, Y h:i A', strtotime($req['created_at']));
        }
        
        echo json_encode(['success' => true, 'data' => $requests]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// CANCEL REQUEST
// ============================================
function cancelRequest($pdo, $userId) {
    try {
        $request_id = $_POST['request_id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        // Check if user owns this request (or has admin permission)
        $checkStmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
        $checkStmt->execute([$request_id]);
        $request = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        // Only the owner or admin can cancel
        if ($request['user_id'] != $userId && !ThreeDModelPermission::can('approve')) {
            throw new Exception('You can only cancel your own requests');
        }
        
        $stmt = $pdo->prepare("
            UPDATE custom_3d_requests 
            SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW() 
            WHERE id = ? AND status IN ('pending', 'pending_payment')
        ");
        $stmt->execute([$reason, $request_id]);
        
        // Notify user about cancellation
        createNotification(
            $pdo,
            $request['user_id'],
            "Request Cancelled",
            "Your 3D model request for '{$request['product_name']}' has been cancelled. Reason: " . ($reason ?: 'No reason provided'),
            "cancelled",
            $request['request_number'],
            "main.php?view=my-3d-models&tab=pending"
        );
        
        echo json_encode(['success' => true, 'message' => 'Request cancelled successfully']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// VERIFY PAYMENT (Admin only)
// ============================================
function verifyPayment($pdo) {
    try {
        $request_id = $_POST['request_id'] ?? 0;
        
        // Get request details before updating
        $req_stmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
        $req_stmt->execute([$request_id]);
        $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        $stmt = $pdo->prepare("
            UPDATE custom_3d_requests 
            SET payment_status = 'paid', status = 'processing', payment_date = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$request_id]);
        
        // ===== NOTIFICATION FOR CLINIC OWNER =====
        $notif_result = createNotification(
            $pdo,
            $request['user_id'],
            "Payment Verified!",
            "Your payment for '{$request['product_name']}' has been verified. Your 3D model request is now being processed.",
            "payment_verified",
            $request['request_number'],
            "main.php?view=my-3d-models&tab=pending"
        );
        
        if (!$notif_result) {
            error_log("Warning: Failed to create notification for payment verification");
        }
        
        echo json_encode(['success' => true, 'message' => 'Payment verified!']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// UPDATE REQUEST STATUS (Admin only)
// ============================================
function updateRequestStatus($pdo) {
    try {
        $request_id = $_POST['request_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        $completed_model_file = $_POST['completed_model_file'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        $validStatuses = ['pending', 'processing', 'completed', 'cancelled', 'rejected'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception('Invalid status');
        }
        
        // Get request details
        $req_stmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
        $req_stmt->execute([$request_id]);
        $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        $updateFields = "status = ?, updated_at = NOW()";
        $params = [$status];
        
        if ($status == 'completed') {
            $updateFields .= ", completed_at = NOW(), completed_model_file = ?";
            $params[] = $completed_model_file;
        }
        
        if ($notes) {
            $updateFields .= ", admin_notes = ?";
            $params[] = $notes;
        }
        
        $params[] = $request_id;
        
        $stmt = $pdo->prepare("UPDATE custom_3d_requests SET $updateFields WHERE id = ?");
        $stmt->execute($params);
        
        // Notify user about status update
        $statusMessages = [
            'processing' => "Your 3D model request is now being processed.",
            'completed' => "Your 3D model is ready! You can now view and download it.",
            'rejected' => "Your request was rejected. Reason: " . ($notes ?: 'No reason provided')
        ];
        
        $message = $statusMessages[$status] ?? "Your request status has been updated to: " . ucfirst($status);
        
        createNotification(
            $pdo,
            $request['user_id'],
            "Request Status Update",
            $message,
            "status_update",
            $request['request_number'],
            "main.php?view=my-3d-models&tab=" . ($status == 'completed' ? 'completed' : 'pending')
        );
        
        echo json_encode(['success' => true, 'message' => "Request status updated to {$status}"]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// DELETE REQUEST
// ============================================
function deleteRequest($pdo, $userId) {
    try {
        $request_id = $_POST['request_id'] ?? 0;
        
        // Check if user owns this request (or has admin permission)
        $checkStmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
        $checkStmt->execute([$request_id]);
        $request = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        // Only the owner or admin can delete
        if ($request['user_id'] != $userId && !ThreeDModelPermission::can('approve')) {
            throw new Exception('You can only delete your own requests');
        }
        
        // Only allow deletion of pending or cancelled requests
        $stmt = $pdo->prepare("SELECT status FROM custom_3d_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $currentStatus = $stmt->fetchColumn();
        
        if (!in_array($currentStatus, ['pending', 'cancelled', 'pending_payment'])) {
            throw new Exception('Cannot delete requests that are already processing or completed');
        }
        
        // Delete images first
        $imgStmt = $pdo->prepare("SELECT image_path FROM request_images WHERE request_id = ?");
        $imgStmt->execute([$request_id]);
        $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($images as $img) {
            $filePath = __DIR__ . '/../' . $img['image_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Delete images from database
        $delImgStmt = $pdo->prepare("DELETE FROM request_images WHERE request_id = ?");
        $delImgStmt->execute([$request_id]);
        
        // Delete the request
        $delStmt = $pdo->prepare("DELETE FROM custom_3d_requests WHERE id = ?");
        $delStmt->execute([$request_id]);
        
        echo json_encode(['success' => true, 'message' => 'Request deleted successfully']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================
// REJECT REQUEST (Admin only)
// ============================================
function rejectRequest($pdo) {
    try {
        $request_id = $_POST['request_id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        if (empty($reason)) {
            throw new Exception('Rejection reason is required');
        }
        
        // Get request details
        $req_stmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
        $req_stmt->execute([$request_id]);
        $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        $stmt = $pdo->prepare("
            UPDATE custom_3d_requests 
            SET status = 'rejected', rejection_reason = ?, rejected_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$reason, $request_id]);
        
        // Notify user about rejection
        createNotification(
            $pdo,
            $request['user_id'],
            "Request Rejected",
            "Your 3D model request for '{$request['product_name']}' has been rejected. Reason: {$reason}",
            "rejected",
            $request['request_number'],
            "main.php?view=my-3d-models&tab=pending"
        );
        
        echo json_encode(['success' => true, 'message' => 'Request rejected successfully']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getPaymentDetails($pdo) {
    try {
        $stmt = $pdo->query("SELECT gcash_name, gcash_number, maya_name, maya_number, bank_name, bank_account_name, bank_account_number FROM system_settings LIMIT 1");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>