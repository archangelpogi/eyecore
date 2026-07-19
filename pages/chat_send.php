<?php

include '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$clinic_id = (int)($_POST['clinic_id'] ?? 0);
$message   = trim($_POST['message'] ?? '');

if (!$clinic_id || !$message) {
    echo json_encode(['success' => false, 'message' => 'Missing fields']);
    exit();
}

// Verify user has a connection to this clinic
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT c.id FROM clinics c
     WHERE c.id = $clinic_id AND c.status = 'Active'
     AND (
         EXISTS (SELECT 1 FROM appointments  WHERE user_id=$user_id AND clinic_id=$clinic_id)
         OR EXISTS (SELECT 1 FROM reservations WHERE user_id=$user_id AND clinic_id=$clinic_id)
         OR EXISTS (SELECT 1 FROM favorites   WHERE user_id=$user_id AND clinic_id=$clinic_id)
     ) LIMIT 1"
));

if (!$check) {
    echo json_encode(['success' => false, 'message' => 'No connection to this clinic']);
    exit();
}

$msg_safe = mysqli_real_escape_string($conn, $message);
mysqli_query($conn,
    "INSERT INTO chats (clinic_id, user_id, sender_type, message, is_read, created_at)
     VALUES ($clinic_id, $user_id, 'user', '$msg_safe', 0, NOW())"
);

if (mysqli_affected_rows($conn) > 0) {
    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn)]);
} else {
    echo json_encode(['success' => false, 'message' => 'DB error']);
}