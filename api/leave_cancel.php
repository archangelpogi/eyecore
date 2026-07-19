<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

// Accept both POST and GET
$leave_id = isset($_POST['id']) ? $_POST['id'] : (isset($_GET['id']) ? $_GET['id'] : 0);
$employee_id = $_SESSION['employee_id'];

if (!$leave_id) {
    echo json_encode(['success' => false, 'message' => 'No leave ID specified']);
    exit;
}

try {
    // Try to cancel without checking employee_id first (for testing)
    $check_stmt = $pdo->prepare("SELECT id, status FROM leaves WHERE id = ?");
    $check_stmt->execute([$leave_id]);
    $leave = $check_stmt->fetch();
    
    if (!$leave) {
        echo json_encode(['success' => false, 'message' => 'Leave ID ' . $leave_id . ' not found in database']);
        exit;
    }
    
    if ($leave['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending leaves can be cancelled']);
        exit;
    }
    
    // Update with employee check
    $update_stmt = $pdo->prepare("
        UPDATE leaves 
        SET status = 'Cancelled', updated_at = NOW() 
        WHERE id = ? AND employee_id = ?
    ");
    $update_stmt->execute([$leave_id, $employee_id]);
    
    $rows_affected = $update_stmt->rowCount();
    
    if ($rows_affected > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Leave application cancelled successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Could not cancel leave. Either not found or you are not the owner.'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>