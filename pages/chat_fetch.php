<?php
// session_name at session_start ay hina-handle ng includes/config.php
include '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$clinic_id = (int)($_GET['clinic_id'] ?? 0);
$last_id   = (int)($_GET['last_id']   ?? 0);

if (!$clinic_id) {
    echo json_encode(['success' => false]);
    exit();
}

// Fetch new messages after last_id
$messages = [];
$q = mysqli_query($conn,
    "SELECT id, sender_type, message, created_at
     FROM chats
     WHERE clinic_id = $clinic_id
       AND user_id   = $user_id
       AND id > $last_id
     ORDER BY created_at ASC
     LIMIT 50"
);
while ($row = mysqli_fetch_assoc($q)) {
    $messages[] = [
        'id'          => (int)$row['id'],
        'sender_type' => $row['sender_type'],
        'message'     => htmlspecialchars($row['message']),
        'time'        => date('g:i A', strtotime($row['created_at'])),
    ];
}

// Mark clinic messages as read
mysqli_query($conn,
    "UPDATE chats SET is_read = 1
     WHERE clinic_id = $clinic_id
       AND user_id   = $user_id
       AND sender_type = 'clinic'
       AND is_read = 0"
);

echo json_encode(['success' => true, 'messages' => $messages]);