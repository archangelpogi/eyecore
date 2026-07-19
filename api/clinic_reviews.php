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

// ✅ Auth check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ✅ RBAC Permission Check
if (!RBACHelper::hasPermission('clinic_reviews_view')) {
    echo json_encode(['error' => 'Permission denied: Cannot view clinic reviews']);
    exit;
}

// ✅ Permission helper functions
function canViewReviews() { return RBACHelper::hasPermission('clinic_reviews_view'); }
function canCreateReviews() { return RBACHelper::hasPermission('clinic_reviews_create'); }
function canEditReviews() { return RBACHelper::hasPermission('clinic_reviews_edit'); }
function canDeleteReviews() { return RBACHelper::hasPermission('clinic_reviews_delete'); }
function canApproveReviews() { return RBACHelper::hasPermission('clinic_reviews_approve'); }
function canRejectReviews() { return RBACHelper::hasPermission('clinic_reviews_reject'); }

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);

if (!$action && $data && isset($data['action'])) {
    $action = $data['action'];
}

error_log("Clinic Reviews API - Action: " . $action);

try {
    // ==================== GET PATIENTS (for dropdown) ====================
    if ($action === 'get_patients') {
        $stmt = $pdo->prepare("
            SELECT 
                p.id as patient_id,
                p.id as id,
                CONCAT(p.first_name, ' ', p.last_name) as name,
                u.id as user_id
            FROM patients p
            LEFT JOIN users u ON u.email = p.email AND u.clinic_id = p.clinic_id
            WHERE p.clinic_id = ? AND p.status = 'Active'
            ORDER BY p.first_name
        ");
        $stmt->execute([$clinicId]);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($patients);
        exit;
    }
    
    // ==================== GET REVIEWS ====================
    if ($action === 'get_reviews') {
        if (!canViewReviews()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $status = $_GET['status'] ?? 'all';
        
        $sql = "
            SELECT r.*, 
                   COALESCE(
                       CONCAT(p.first_name, ' ', p.last_name),
                       CONCAT(u.first_name, ' ', u.last_name)
                   ) as patient_name,
                   COALESCE(p.email, u.email) as patient_email,
                   COALESCE(u.contact, p.contact, '') as patient_phone,
                   CASE 
                       WHEN p.id IS NOT NULL THEN 'patient'
                       WHEN u.id IS NOT NULL THEN 'user'
                       ELSE 'unknown'
                   END as reviewer_type
            FROM clinic_reviews r
            LEFT JOIN patients p ON r.patient_id = p.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.clinic_id = ?
        ";
        $params = [$clinicId];
        
        if ($status !== 'all') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'reviews' => $reviews]);
        exit;
    }
    
    // ==================== GET SINGLE REVIEW ====================
    if ($action === 'get_review') {
        if (!canViewReviews()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        
        $reviewId = $_GET['id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   COALESCE(
                       CONCAT(p.first_name, ' ', p.last_name),
                       CONCAT(u.first_name, ' ', u.last_name)
                   ) as patient_name,
                   COALESCE(p.email, u.email) as patient_email,
                   COALESCE(u.contact, p.contact, '') as patient_phone
            FROM clinic_reviews r
            LEFT JOIN patients p ON r.patient_id = p.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND r.clinic_id = ?
        ");
        $stmt->execute([$reviewId, $clinicId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($review) {
            echo json_encode(['success' => true, 'review' => $review]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Review not found']);
        }
        exit;
    }
    

    
    // ==================== UPDATE REVIEW STATUS ====================
    if ($action === 'update_status') {
        $reviewId = $data['review_id'] ?? $_POST['review_id'] ?? 0;
        $newStatus = $data['status'] ?? $_POST['status'] ?? '';
        $adminNotes = $data['admin_notes'] ?? $_POST['admin_notes'] ?? '';
        
        if (!$reviewId || !$newStatus) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        $validStatuses = ['pending', 'approved', 'rejected'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit;
        }
        
        // Permission check based on status
        if ($newStatus === 'approved' && !canApproveReviews()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot approve reviews']);
            exit;
        }
        if ($newStatus === 'rejected' && !canRejectReviews()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot reject reviews']);
            exit;
        }
        
        // Get current review
        $stmt = $pdo->prepare("SELECT * FROM clinic_reviews WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$reviewId, $clinicId]);
        $oldReview = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldReview) {
            echo json_encode(['success' => false, 'message' => 'Review not found']);
            exit;
        }
        
        $oldStatus = $oldReview['status'];
        
        // Update status
        $stmt = $pdo->prepare("
            UPDATE clinic_reviews 
            SET status = ?, 
                admin_notes = CONCAT(IFNULL(admin_notes, ''), ?),
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        $timestamp = "\n\n[" . date('Y-m-d H:i:s') . "] Status changed to " . strtoupper($newStatus) . ": " . $adminNotes;
        $success = $stmt->execute([$newStatus, $timestamp, $userId, $reviewId, $clinicId]);
        
        if ($success) {
            // Log to audit
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, new_values, created_at)
                VALUES (?, ?, 'UPDATE_STATUS', 'clinic_reviews', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId, $clinicId, $reviewId,
                json_encode(['status' => $oldStatus]),
                json_encode(['status' => $newStatus])
            ]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Review ' . ($newStatus === 'approved' ? 'approved' : 'rejected') . ' successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
        exit;
    }
    
    // ==================== DELETE REVIEW ====================
    if ($action === 'delete_review') {
        if (!canDeleteReviews()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot delete reviews']);
            exit;
        }
        
        $reviewId = $data['review_id'] ?? $_POST['review_id'] ?? 0;
        
        // Get review for audit
        $stmt = $pdo->prepare("SELECT * FROM clinic_reviews WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$reviewId, $clinicId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$review) {
            echo json_encode(['success' => false, 'message' => 'Review not found']);
            exit;
        }
        
        // Delete
        $stmt = $pdo->prepare("DELETE FROM clinic_reviews WHERE id = ? AND clinic_id = ?");
        $success = $stmt->execute([$reviewId, $clinicId]);
        
        if ($success) {
            // Log to audit
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, created_at)
                VALUES (?, ?, 'DELETE', 'clinic_reviews', ?, ?, NOW())
            ");
            $stmt->execute([$userId, $clinicId, $reviewId, json_encode($review)]);
            
            echo json_encode(['success' => true, 'message' => 'Review deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete review']);
        }
        exit;
    }
    
    // If no action matched
    error_log("Clinic Reviews API - No matching action: " . $action);
    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . ($action ?: 'none')]);
    
} catch (Exception $e) {
    error_log("Clinic Reviews API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>