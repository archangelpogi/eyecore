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

// Mark all unread notifications as read
$query = "UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0";
$result = mysqli_query($conn, $query);

if ($result) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>