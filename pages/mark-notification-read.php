<?php

include '../includes/config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Validate
if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit();
}

// Mark as read - ensure it belongs to the user (security)
$query = "UPDATE notifications SET is_read = 1 WHERE id = $notification_id AND user_id = $user_id";
$result = mysqli_query($conn, $query);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>