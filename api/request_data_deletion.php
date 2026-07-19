<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

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

function canViewDeletion() { return RBACHelper::hasPermission('request_data_deletion_view'); }
function canCreateDeletion() { return RBACHelper::hasPermission('request_data_deletion_create'); }
function canEditDeletion() { return RBACHelper::hasPermission('request_data_deletion_edit'); }
function canDeleteDeletion() { return RBACHelper::hasPermission('request_data_deletion_delete'); }
function canApproveDeletion() { return RBACHelper::hasPermission('request_data_deletion_approve'); }
function canRejectDeletion() { return RBACHelper::hasPermission('request_data_deletion_reject'); }

function logToAudit($pdo, $userId, $clinicId, $action, $tableName, $recordId, $oldValues = null, $newValues = null) {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $userId, $clinicId, $action, $tableName, $recordId, 
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ipAddress, $userAgent
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

function checkDeletionEligibility($pdo, $patientId, $clinicId) {
    try {
        $stmt = $pdo->prepare("
            SELECT MIN(examination_date) as oldest_record,
                   COUNT(*) as total_records,
                   MAX(examination_date) as newest_record
            FROM optical_records
            WHERE patient_id = ? AND clinic_id = ?
        ");
        $stmt->execute([$patientId, $clinicId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['oldest_record']) {
            return [
                'eligible' => false,
                'reason' => 'No examination records found',
                'oldest_record' => null,
                'newest_record' => null,
                'years_old' => 0,
                'total_records' => 0,
                'required_years' => 10,
                'years_remaining' => 10,
                'can_delete' => false,
                'eligible_date' => null
            ];
        }
        
        $oldestDate = new DateTime($result['oldest_record']);
        $today = new DateTime();
        $yearsDiff = $today->diff($oldestDate)->y;
        
        $eligibleDate = clone $oldestDate;
        $eligibleDate->modify('+10 years');
        
        $eligible = $yearsDiff >= 10;
        
        return [
            'eligible' => $eligible,
            'reason' => $eligible ? 'Records are eligible for deletion' : 'Records are less than 10 years old',
            'oldest_record' => $result['oldest_record'],
            'newest_record' => $result['newest_record'],
            'years_old' => $yearsDiff,
            'total_records' => $result['total_records'],
            'required_years' => 10,
            'years_remaining' => $eligible ? 0 : (10 - $yearsDiff),
            'can_delete' => $eligible,
            'eligible_date' => $eligibleDate->format('Y-m-d')
        ];
    } catch (Exception $e) {
        error_log("checkDeletionEligibility error: " . $e->getMessage());
        return [
            'eligible' => false,
            'reason' => 'Error checking eligibility',
            'years_old' => 0,
            'total_records' => 0,
            'required_years' => 10,
            'years_remaining' => 10,
            'can_delete' => false
        ];
    }
}

function notifyUser($pdo, $userId, $title, $message, $link = null, $type = 'deletion_update') {
    try {
        $link = $link ?? 'my-examinations.php?tab=requests';
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, link, type, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$userId, $title, $message, $link, $type]);
    } catch (Exception $e) {
        error_log("notifyUser error: " . $e->getMessage());
        return false;
    }
}

function notifyClinic($pdo, $clinicId, $title, $message, $link = null) {
    try {
        $link = $link ?? 'clinic/deletion_requests.php';
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, link, type, created_at)
            SELECT u.id, ?, ?, ?, 'deletion_update', NOW()
            FROM users u
            WHERE u.clinic_id = ? AND u.role IN ('ClinicAdmin', 'CRM')
        ");
        return $stmt->execute([$title, $message, $link, $clinicId]);
    } catch (Exception $e) {
        error_log("notifyClinic error: " . $e->getMessage());
        return false;
    }
}

// ================== API ENDPOINTS ==================

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

try {
    // ================== GET ALL REQUESTS ==================
    if ($action === 'get_requests') {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        if (!canViewDeletion()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot view deletion requests']);
            exit;
        }
        
        $clinicId = $_SESSION['clinic_id'];
        $status = $_GET['status'] ?? null;
        
        $sql = "
            SELECT d.*, 
                   p.first_name,
                   p.last_name,
                   p.email as patient_email,
                   p.phone as patient_phone,
                   p.age,
                   p.gender
            FROM data_retention_log d
            LEFT JOIN patients p ON d.patient_id = p.id
            WHERE d.clinic_id = ?
        ";
        $params = [$clinicId];
        
        if ($status && $status !== 'all') {
            $sql .= " AND d.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY 
            CASE WHEN d.status = 'pending' THEN 1 
                 WHEN d.status = 'approved' THEN 2
                 WHEN d.status = 'processing' THEN 3
                 WHEN d.status = 'completed' THEN 4
                 WHEN d.status = 'rejected' THEN 5
                 WHEN d.status = 'cancelled' THEN 6
                 ELSE 7 END,
            d.request_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($requests as &$request) {
            $eligibility = checkDeletionEligibility($pdo, $request['patient_id'], $clinicId);
            $request['eligibility'] = $eligibility;
        }
        
        echo json_encode(['success' => true, 'requests' => $requests]);
        exit;
    }
    
    // ================== GET REQUEST DETAILS ==================
    if ($action === 'get_request') {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        if (!canViewDeletion()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot view deletion requests']);
            exit;
        }
        
        $requestId = $_GET['id'] ?? null;
        $clinicId = $_SESSION['clinic_id'];
        
        if (!$requestId) {
            echo json_encode(['success' => false, 'message' => 'Request ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   p.first_name,
                   p.last_name,
                   p.email as patient_email,
                   p.phone as patient_phone,
                   p.age,
                   p.gender,
                   c.clinic_name
            FROM data_retention_log d
            LEFT JOIN patients p ON d.patient_id = p.id
            LEFT JOIN clinics c ON d.clinic_id = c.id
            WHERE d.id = ? AND d.clinic_id = ?
        ");
        $stmt->execute([$requestId, $clinicId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($request) {
            $recordStmt = $pdo->prepare("
                SELECT COUNT(*) as total_records,
                       MIN(examination_date) as oldest_record,
                       MAX(examination_date) as newest_record
                FROM optical_records
                WHERE patient_id = ? AND clinic_id = ?
            ");
            $recordStmt->execute([$request['patient_id'], $clinicId]);
            $records = $recordStmt->fetch(PDO::FETCH_ASSOC);
            
            $eligibility = checkDeletionEligibility($pdo, $request['patient_id'], $clinicId);
            
            echo json_encode([
                'success' => true,
                'request' => $request,
                'records' => $records,
                'eligibility' => $eligibility
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found']);
        }
        exit;
    }
    
    // ================== UPDATE REQUEST STATUS (Clinic Side) ==================
    if ($action === 'update_status') {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $requestId = $_POST['request_id'] ?? null;
        $newStatus = $_POST['status'] ?? null;
        $adminNotes = $_POST['admin_notes'] ?? '';
        $clinicId = $_SESSION['clinic_id'];
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['role'];
        
        if (!$requestId || !$newStatus) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        $validStatuses = ['pending', 'approved', 'rejected', 'processing', 'completed'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        // ✅ Permission check based on status
        if ($newStatus === 'approved' && !canApproveDeletion()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot approve deletion requests']);
            exit;
        }
        if ($newStatus === 'rejected' && !canRejectDeletion()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot reject deletion requests']);
            exit;
        }
        if (($newStatus === 'processing' || $newStatus === 'completed') && !canEditDeletion()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot update deletion requests']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   p.email as patient_email,
                   p.id as patient_id,
                   c.clinic_name
            FROM data_retention_log d
            LEFT JOIN patients p ON d.patient_id = p.id
            LEFT JOIN clinics c ON d.clinic_id = c.id
            WHERE d.id = ? AND d.clinic_id = ?
        ");
        $stmt->execute([$requestId, $clinicId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            echo json_encode(['success' => false, 'message' => 'Request not found']);
            exit;
        }
        
        $oldStatus = $request['status'] ?? 'pending';
        $actionTaken = strtoupper($newStatus);
        
        $pdo->beginTransaction();
        
        try {
            $currentNotes = $request['notes'] ?? '';
            $timestamp = date('Y-m-d H:i:s');
            $newNotes = $currentNotes . "\n\n[" . $timestamp . "] " . $userRole . " changed status to " . strtoupper($newStatus) . ": " . $adminNotes;
            
            // Base update
            $sql = "UPDATE data_retention_log SET status = ?, action_taken = ?, notes = ?, processed_by = ?, processed_date = NOW()";
            $params = [$newStatus, $actionTaken, $newNotes, $userId];
            
            // Special handling for approved status
            if ($newStatus === 'approved') {
                $scheduledDate = date('Y-m-d', strtotime('+30 days'));
                $sql .= ", approval_date = NOW(), scheduled_delete_date = ?";
                $params[] = $scheduledDate;
            }
            
            $sql .= " WHERE id = ? AND clinic_id = ?";
            $params[] = $requestId;
            $params[] = $clinicId;
            
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
            
            logToAudit($pdo, $userId, $clinicId, "UPDATE_STATUS", "data_retention_log", $requestId, 
                ['status' => $oldStatus], ['status' => $newStatus]);
            
            // Notifications
            $userMessage = '';
            $userTitle = '';
            $forceApprove = isset($_POST['force_approve']) && $_POST['force_approve'] === 'true';
            
            switch ($newStatus) {
                case 'approved':
                    $eligibility = checkDeletionEligibility($pdo, $request['patient_id'], $clinicId);
                    
                    if (!$eligibility['eligible'] && !$forceApprove) {
                        $pdo->rollBack();
                        echo json_encode([
                            'success' => false,
                            'requires_confirmation' => true,
                            'warning' => true,
                            'message' => '⚠️ WARNING: Records are only ' . $eligibility['years_old'] . ' years old. RA 10173 requires 10 years retention.',
                            'eligibility' => $eligibility
                        ]);
                        exit;
                    }
                    
                    $scheduledDateFormatted = date('F j, Y', strtotime('+30 days'));
                    $userTitle = 'Deletion Request Approved';
                    $userMessage = "Your data deletion request has been APPROVED by {$request['clinic_name']}. Your data will be permanently deleted on {$scheduledDateFormatted}. You may cancel this request anytime before that date.";
                    break;
                    
                case 'rejected':
                    $userTitle = 'Deletion Request Rejected';
                    $userMessage = "Your data deletion request has been REJECTED by {$request['clinic_name']}.\n\nReason: " . substr($adminNotes, 0, 200);
                    break;
                    
                case 'processing':
                    $userTitle = 'Deletion Request - Processing';
                    $userMessage = "Your data deletion request is now being PROCESSED by {$request['clinic_name']}.";
                    break;
                    
                case 'completed':
                    $userTitle = 'Data Deletion Completed';
                    $userMessage = "Your data has been successfully DELETED from {$request['clinic_name']} records as requested.";
                    break;
            }
            
            if ($userMessage && $request['patient_email']) {
                $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $userStmt->execute([$request['patient_email']]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    notifyUser($pdo, $user['id'], $userTitle, $userMessage);
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Request status updated to: " . strtoupper($newStatus),
                'scheduled_delete_date' => ($newStatus === 'approved') ? date('Y-m-d', strtotime('+30 days')) : null
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Update error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // ================== CANCEL DELETION REQUEST (User Side) ==================
    if ($action === 'cancel_deletion') {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Please login']);
            exit;
        }
        
        // User can cancel their own requests - no additional permission needed
        $requestId = $_POST['request_id'] ?? null;
        $cancelReason = $_POST['reason'] ?? 'User cancelled the request';
        $userId = $_SESSION['user_id'];
        
        if (!$requestId) {
            echo json_encode(['success' => false, 'message' => 'Request ID required']);
            exit;
        }
        
        // Get patient_id from user
        $patientStmt = $pdo->prepare("SELECT id FROM patients WHERE email = (SELECT email FROM users WHERE id = ?)");
        $patientStmt->execute([$userId]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            echo json_encode(['success' => false, 'message' => 'Patient record not found']);
            exit;
        }
        
        $patientId = $patient['id'];
        
        // Get request details
        $stmt = $pdo->prepare("
            SELECT d.*, c.clinic_name
            FROM data_retention_log d
            LEFT JOIN clinics c ON d.clinic_id = c.id
            WHERE d.id = ? AND d.patient_id = ? AND d.status IN ('pending', 'approved')
        ");
        $stmt->execute([$requestId, $patientId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled at this stage']);
            exit;
        }
        
        // Check if still within 30-day period for approved requests
        if ($request['status'] === 'approved' && $request['scheduled_delete_date']) {
            $deleteDate = new DateTime($request['scheduled_delete_date']);
            $today = new DateTime();
            if ($deleteDate < $today) {
                echo json_encode(['success' => false, 'message' => 'Cannot cancel: Data has already been deleted']);
                exit;
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            $updateStmt = $pdo->prepare("
                UPDATE data_retention_log 
                SET status = 'cancelled',
                    action_taken = 'CANCELLED',
                    cancel_reason = ?,
                    cancelled_at = NOW(),
                    cancelled_by = ?
                WHERE id = ? AND patient_id = ?
            ");
            $updateStmt->execute([$cancelReason, $userId, $requestId, $patientId]);
            
            logToAudit($pdo, $userId, $request['clinic_id'], "CANCEL_DELETION", "data_retention_log", $requestId,
                ['status' => $request['status']], ['status' => 'cancelled', 'reason' => $cancelReason]);
            
            // Notify user
            notifyUser($pdo, $userId, 'Deletion Request Cancelled', 
                "You have successfully cancelled your data deletion request for {$request['clinic_name']}. Your records will remain in the system.");
            
            // Notify clinic
            notifyClinic($pdo, $request['clinic_id'], 'Deletion Request Cancelled', 
                "Patient has cancelled their deletion request. Request #{$requestId} has been cancelled. Reason: " . substr($cancelReason, 0, 100));
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Your deletion request has been cancelled successfully.'
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Cancel error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error cancelling request: ' . $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>