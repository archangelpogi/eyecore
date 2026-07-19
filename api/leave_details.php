<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['employee_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$leave_id = $_GET['id'] ?? 0;
$employee_id = $_SESSION['employee_id'];

try {
    $stmt = $pdo->prepare("
        SELECT l.*, lt.type_name, lt.with_pay,
               CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
        FROM leaves l
        LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN employees e ON l.approved_by = e.id
        LEFT JOIN users u ON e.user_id = u.id
        WHERE l.id = ? AND l.employee_id = ?
    ");
    
    $stmt->execute([$leave_id, $employee_id]);
    $leave = $stmt->fetch();
    
    if (!$leave) {
        echo json_encode(['error' => 'Leave application not found']);
        exit;
    }
    
    echo json_encode($leave);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
?>