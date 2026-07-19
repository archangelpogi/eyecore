<?php
// session_name at session_start ay hina-handle ng includes/config.php
include '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Get all clinics user has a connection with, plus last message + unread count
$q = mysqli_query($conn,
    "SELECT
        c.id,
        c.clinic_name,
        c.clinic_image,
        c.logo,
        c.cover_photo,
        (SELECT message FROM chats
         WHERE clinic_id = c.id AND user_id = $user_id
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT created_at FROM chats
         WHERE clinic_id = c.id AND user_id = $user_id
         ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM chats
         WHERE clinic_id = c.id AND user_id = $user_id
           AND sender_type = 'clinic' AND is_read = 0) as unread_count
     FROM clinics c
     WHERE c.status = 'Active'
       AND (
           EXISTS (SELECT 1 FROM appointments  WHERE user_id=$user_id AND clinic_id=c.id)
           OR EXISTS (SELECT 1 FROM reservations WHERE user_id=$user_id AND clinic_id=c.id)
           OR EXISTS (SELECT 1 FROM favorites   WHERE user_id=$user_id AND clinic_id=c.id)
       )
     ORDER BY last_time DESC, c.clinic_name ASC"
);

$clinics = [];
while ($row = mysqli_fetch_assoc($q)) {
    // Build image path
    $img = null;
    if (!empty($row['cover_photo']))  $img = '/eyecore/assets/images/clinic-covers/'  . $row['cover_photo'];
    elseif (!empty($row['clinic_image'])) $img = '/eyecore/assets/images/clinic-images/' . $row['clinic_image'];
    elseif (!empty($row['logo']))     $img = '/eyecore/assets/images/clinic-logos/'   . $row['logo'];

    // Truncate last message preview
    $preview = $row['last_message']
        ? (mb_strlen($row['last_message']) > 35
            ? mb_substr($row['last_message'], 0, 35) . '…'
            : $row['last_message'])
        : 'Start a conversation';

    $clinics[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['clinic_name'],  // raw — JS textContent handles XSS safely
        'image'        => $img,
        'last_message' => htmlspecialchars($preview),
        'last_time'    => $row['last_time'] ? date('g:i A', strtotime($row['last_time'])) : '',
        'unread'       => (int)$row['unread_count'],
    ];
}

// Also return total unread across all clinics for the navbar badge
$total_unread_q = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM chats
     WHERE user_id = $user_id AND sender_type = 'clinic' AND is_read = 0"
));
$total_unread = (int)($total_unread_q['total'] ?? 0);

echo json_encode([
    'success'      => true,
    'clinics'      => $clinics,
    'total_unread' => $total_unread,
]);