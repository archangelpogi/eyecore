<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

$clinicId = $_SESSION['clinic_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

if (!RBACHelper::hasPermission('pwd_senior_view')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$canApprove = RBACHelper::hasPermission('pwd_senior_approve');
$canReject = RBACHelper::hasPermission('pwd_senior_reject');
$canDelete = RBACHelper::hasPermission('pwd_senior_delete');

$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

try {
    if ($action === 'get_requests') {
        $status = $_POST['status'] ?? 'all';
        $response = getVerificationRequests($pdo, $status);
    } 
    elseif ($action === 'get_request') {
        $id = $_POST['id'] ?? 0;
        $response = getVerificationRequest($pdo, $id);
    } 
    elseif ($action === 'approve' && $canApprove) {
        $id = $_POST['id'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        $response = approveRequest($pdo, $id, $userId, $notes);
    } 
    elseif ($action === 'reject' && $canReject) {
        $id = $_POST['id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        $response = rejectRequest($pdo, $id, $userId, $reason);
    } 
    elseif ($action === 'delete' && $canDelete) {
        $id = $_POST['id'] ?? 0;
        $response = deleteRequest($pdo, $id);
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($response);
exit;

// ============================================
// FUNCTIONS - NO CLINIC_ID FILTER
// ============================================

function getVerificationRequests($pdo, $status = 'all') {
    try {
        $sql = "
            SELECT 
                u.id as user_id,
                u.fullname as patient_name,
                u.email as patient_email,
                u.contact as patient_phone,
                u.pwd_senior_status,
                u.pwd_senior_type,
                u.pwd_senior_id_number,
                u.pwd_senior_id_image,
                u.pwd_senior_verified_at,
                u.pwd_senior_rejection_reason,
                u.created_at as submitted_at,
                u.pwd_senior_verified_by,
                NULL as verified_by_name
            FROM users u
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($status !== 'all') {
            $sql .= " AND u.pwd_senior_status = ?";
            $params[] = $status;
        } else {
            $sql .= " AND u.pwd_senior_status IN ('pending', 'verified', 'rejected')";
        }
        
        $sql .= " ORDER BY u.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'requests' => $requests];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getVerificationRequest($pdo, $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id as user_id,
                u.fullname as patient_name,
                u.email as patient_email,
                u.contact as patient_phone,
                u.pwd_senior_status,
                u.pwd_senior_type,
                u.pwd_senior_id_number,
                u.pwd_senior_id_image,
                u.pwd_senior_verified_at,
                u.pwd_senior_rejection_reason,
                u.created_at as submitted_at,
                u.pwd_senior_verified_by,
                NULL as verified_by_name
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($request) {
            return ['success' => true, 'request' => $request];
        } else {
            return ['success' => false, 'message' => 'Request not found'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function approveRequest($pdo, $userId, $adminId, $notes = '') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'verified',
                pwd_senior_verified_by = ?,
                pwd_senior_verified_at = NOW(),
                pwd_senior_rejection_reason = NULL
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $userId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('User not found');
        }
        
        $pdo->commit();
        
        sendVerificationNotification($pdo, $userId, 'approved', $notes);
        
        return ['success' => true, 'message' => 'PWD/Senior verification approved successfully!'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rejectRequest($pdo, $userId, $adminId, $reason = '') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'rejected',
                pwd_senior_rejection_reason = ?,
                pwd_senior_verified_by = ?,
                pwd_senior_verified_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$reason, $adminId, $userId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('User not found');
        }
        
        $pdo->commit();
        
        sendVerificationNotification($pdo, $userId, 'rejected', $reason);
        
        return ['success' => true, 'message' => 'PWD/Senior verification rejected.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteRequest($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'none',
                pwd_senior_type = NULL,
                pwd_senior_id_number = NULL,
                pwd_senior_id_image = NULL,
                pwd_senior_rejection_reason = NULL,
                pwd_senior_verified_by = NULL,
                pwd_senior_verified_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        return ['success' => true, 'message' => 'Request deleted successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function sendVerificationNotification($pdo, $userId, $status, $message = '') {
    try {
        $title = $status === 'approved' ? 'PWD/Senior Verification Approved ✅' : 'PWD/Senior Verification Update ❌';
        $body = $status === 'approved' 
            ? 'Your PWD/Senior verification has been approved! You now get 20% discount on all bookings.'
            : 'Your PWD/Senior verification was not approved. Reason: ' . $message;
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, 'pwd_senior', '/profile.php', 0, NOW())
        ");
        $stmt->execute([$userId, $title, $body]);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>