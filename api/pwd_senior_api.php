<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 60); // ✅ Increase execution time
ini_set('memory_limit', '256M');   // ✅ Increase memory limit

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
        $response = getVerificationRequests($pdo, $clinicId, $status);
    } 
    elseif ($action === 'get_request') {
        $id = $_POST['id'] ?? 0;
        $response = getVerificationRequest($pdo, $clinicId, $id);
    } 
    elseif ($action === 'approve' && $canApprove) {
        $id = $_POST['id'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        $response = approveRequest($pdo, $id, $userId, $clinicId, $notes);
    } 
    elseif ($action === 'reject' && $canReject) {
        $id = $_POST['id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        $response = rejectRequest($pdo, $id, $userId, $clinicId, $reason);
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
// FUNCTIONS USING user_verifications TABLE
// ============================================

function getVerificationRequests($pdo, $clinicId, $status = 'all') {
    try {
        // ✅ SIMPLIFIED QUERY - LIMIT results to prevent timeout
        $sql = "
            SELECT 
                uv.user_id,
                uv.clinic_id,
                uv.verification_type,
                uv.id_number,
                uv.id_image,
                uv.date_issued,
                uv.valid_until,
                uv.status,
                uv.rejection_reason,
                uv.verified_by,
                uv.verified_at,
                uv.created_at,
                uv.updated_at,
                u.fullname as patient_name,
                u.email as patient_email,
                u.contact as patient_phone
            FROM user_verifications uv
            INNER JOIN users u ON uv.user_id = u.id
            WHERE uv.clinic_id = ?
        ";
        
        $params = [$clinicId];
        
        if ($status !== 'all') {
            $sql .= " AND uv.status = ?";
            $params[] = $status;
        } else {
            $sql .= " AND uv.status IN ('pending', 'verified', 'rejected')";
        }
        
        $sql .= " ORDER BY uv.created_at DESC LIMIT 50"; // ✅ LIMIT to 50 records
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ✅ Get verified by names in separate query (if needed)
        foreach ($requests as &$request) {
            if ($request['verified_by']) {
                $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
                $stmt2->execute([$request['verified_by']]);
                $verifier = $stmt2->fetch(PDO::FETCH_ASSOC);
                $request['verified_by_name'] = $verifier['name'] ?? null;
            } else {
                $request['verified_by_name'] = null;
            }
        }
        
        return ['success' => true, 'requests' => $requests];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getVerificationRequest($pdo, $clinicId, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                uv.user_id,
                uv.clinic_id,
                uv.verification_type,
                uv.id_number,
                uv.id_image,
                uv.date_issued,
                uv.valid_until,
                uv.status,
                uv.rejection_reason,
                uv.verified_by,
                uv.verified_at,
                uv.created_at,
                uv.updated_at,
                u.fullname as patient_name,
                u.email as patient_email,
                u.contact as patient_phone
            FROM user_verifications uv
            INNER JOIN users u ON uv.user_id = u.id
            WHERE uv.user_id = ? AND uv.clinic_id = ?
        ");
        $stmt->execute([$userId, $clinicId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($request) {
            // Get verified by name
            if ($request['verified_by']) {
                $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
                $stmt2->execute([$request['verified_by']]);
                $verifier = $stmt2->fetch(PDO::FETCH_ASSOC);
                $request['verified_by_name'] = $verifier['name'] ?? null;
            }
            return ['success' => true, 'request' => $request];
        } else {
            return ['success' => false, 'message' => 'Request not found or not assigned to your clinic'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function approveRequest($pdo, $userId, $adminId, $clinicId, $notes = '') {
    try {
        $pdo->beginTransaction();
        
        // ✅ Update user_verifications table
        $stmt = $pdo->prepare("
            UPDATE user_verifications 
            SET 
                status = 'verified',
                verified_by = ?,
                verified_at = NOW(),
                rejection_reason = NULL,
                updated_at = NOW()
            WHERE user_id = ? AND clinic_id = ?
        ");
        $stmt->execute([$adminId, $userId, $clinicId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Verification request not found');
        }
        
        // ✅ Also update the user's pwd_senior fields for easy lookup
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'verified',
                pwd_senior_verified_at = NOW(),
                pwd_senior_verified_by = ?,
                pwd_senior_rejection_reason = NULL,
                pwd_senior_type = (SELECT verification_type FROM user_verifications WHERE user_id = ? AND clinic_id = ?)
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $userId, $clinicId, $userId]);
        
        // Log the action
        $log = $pdo->prepare("
            INSERT INTO pwd_senior_verification_logs (user_id, clinic_id, action, created_at)
            VALUES (?, ?, 'approved', NOW())
        ");
        $log->execute([$userId, $clinicId]);
        
        $pdo->commit();
        
        sendVerificationNotification($pdo, $userId, 'approved', $clinicId);
        
        return ['success' => true, 'message' => 'PWD/Senior verification approved successfully!'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rejectRequest($pdo, $userId, $adminId, $clinicId, $reason = '') {
    try {
        $pdo->beginTransaction();
        
        // ✅ Update user_verifications table
        $stmt = $pdo->prepare("
            UPDATE user_verifications 
            SET 
                status = 'rejected',
                rejection_reason = ?,
                verified_by = ?,
                verified_at = NOW(),
                updated_at = NOW()
            WHERE user_id = ? AND clinic_id = ?
        ");
        $stmt->execute([$reason, $adminId, $userId, $clinicId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Verification request not found');
        }
        
        // ✅ Also update the user's pwd_senior fields
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'rejected',
                pwd_senior_rejection_reason = ?,
                pwd_senior_verified_by = ?,
                pwd_senior_verified_at = NOW(),
                pwd_senior_type = (SELECT verification_type FROM user_verifications WHERE user_id = ? AND clinic_id = ?)
            WHERE id = ?
        ");
        $stmt->execute([$reason, $adminId, $userId, $clinicId, $userId]);
        
        // Log the action
        $log = $pdo->prepare("
            INSERT INTO pwd_senior_verification_logs (user_id, clinic_id, action, created_at)
            VALUES (?, ?, 'rejected', NOW())
        ");
        $log->execute([$userId, $clinicId]);
        
        $pdo->commit();
        
        sendVerificationNotification($pdo, $userId, 'rejected', $clinicId, $reason);
        
        return ['success' => true, 'message' => 'PWD/Senior verification rejected.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteRequest($pdo, $userId) {
    try {
        // ✅ Delete from user_verifications
        $stmt = $pdo->prepare("
            DELETE FROM user_verifications 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        
        // ✅ Reset user's pwd_senior fields
        $stmt = $pdo->prepare("
            UPDATE users 
            SET 
                pwd_senior_status = 'none',
                pwd_senior_type = NULL,
                pwd_senior_id_number = NULL,
                pwd_senior_id_image = NULL,
                pwd_senior_rejection_reason = NULL,
                pwd_senior_verified_by = NULL,
                pwd_senior_verified_at = NULL,
                pwd_senior_clinic_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        
        return ['success' => true, 'message' => 'Request deleted successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function sendVerificationNotification($pdo, $userId, $status, $clinicId, $message = '') {
    try {
        // Get clinic name
        $stmt = $pdo->prepare("SELECT name FROM clinics WHERE id = ?");
        $stmt->execute([$clinicId]);
        $clinic = $stmt->fetch(PDO::FETCH_ASSOC);
        $clinicName = $clinic['name'] ?? 'the clinic';
        
        $title = $status === 'approved' ? '✅ PWD/Senior Verification Approved!' : '❌ PWD/Senior Verification Update';
        $body = $status === 'approved' 
            ? "Your PWD/Senior verification for {$clinicName} has been approved! You now get 20% discount on all bookings."
            : "Your PWD/Senior verification for {$clinicName} was not approved. Reason: " . $message;
        
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