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

$has_image = isset($_FILES['image']) && $_FILES['image']['error'] == 0;

// Kailangan ng clinic_id, at kailangan may text o image man lang
if (!$clinic_id || (!$message && !$has_image)) {
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

// ============================================
// HANDLE IMAGE UPLOAD (kung may image)
// ============================================
$image_filename = null;

if ($has_image) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5MB

    $filename = $_FILES['image']['name'];
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $filesize = $_FILES['image']['size'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image type. Only JPG, PNG, GIF, WEBP allowed.']);
        exit();
    }

    if ($filesize > $max_size) {
        echo json_encode(['success' => false, 'message' => 'Image is too large. Max size is 5MB.']);
        exit();
    }

    $upload_dir = '../assets/images/chat-images/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $image_filename = 'chat_' . $user_id . '_' . $clinic_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $upload_path = $upload_dir . $image_filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload image.']);
        exit();
    }
}

$msg_safe   = mysqli_real_escape_string($conn, $message);
$image_safe = $image_filename ? mysqli_real_escape_string($conn, $image_filename) : null;

if ($image_safe) {
    mysqli_query($conn,
        "INSERT INTO chats (clinic_id, user_id, sender_type, message, image, is_read, created_at)
         VALUES ($clinic_id, $user_id, 'user', '$msg_safe', '$image_safe', 0, NOW())"
    );
} else {
    mysqli_query($conn,
        "INSERT INTO chats (clinic_id, user_id, sender_type, message, is_read, created_at)
         VALUES ($clinic_id, $user_id, 'user', '$msg_safe', 0, NOW())"
    );
}

if (mysqli_affected_rows($conn) > 0) {
    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn)]);
} else {
    // Kung nag-fail yung DB insert pero na-upload na yung image, i-delete para hindi mag-iwan ng orphan file
    if ($image_filename && file_exists($upload_dir . $image_filename)) {
        unlink($upload_dir . $image_filename);
    }
    echo json_encode(['success' => false, 'message' => 'DB error']);
}