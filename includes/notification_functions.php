<?php
// includes/notification_functions.php

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($user_id) {
    global $conn;
    $query = mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $result = mysqli_fetch_assoc($query);
    return $result['total'];
}

/**
 * Get recent notifications for dropdown
 */
function getRecentNotifications($user_id, $limit = 5) {
    global $conn;
    $query = "SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT $limit";
    return mysqli_query($conn, $query);
}

/**
 * Add a new notification
 */
function addNotification($user_id, $type, $title, $message, $link = '') {
    global $conn;
    $type = mysqli_real_escape_string($conn, $type);
    $title = mysqli_real_escape_string($conn, $title);
    $message = mysqli_real_escape_string($conn, $message);
    $link = mysqli_real_escape_string($conn, $link);
    
    $query = "INSERT INTO notifications (user_id, type, title, message, link) 
              VALUES ($user_id, '$type', '$title', '$message', '$link')";
    
    return mysqli_query($conn, $query);
}

/**
 * Format time ago
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60) . ' minutes ago';
    if ($diff < 86400) return floor($diff/3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff/86400) . ' days ago';
    
    return date('M j, Y', $time);
}
?>