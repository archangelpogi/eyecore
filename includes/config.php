<?php
// ============================================
// DATABASE CONNECTION
// ============================================
$host = 'localhost';
$user = 'u334978718_eyecore_user';
$pass = 'Eyecore@2026';  // ang password na ni-reset mo
$db = 'u334978718_eyecore_db';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ============================================
// ✅ PAYMONGO CONFIGURATION
// ============================================
define('PAYMONGO_SECRET_KEY', 'sk_test_qcZwF33CQGUk9owjBgRtGFbS');
define('PAYMONGO_PUBLIC_KEY', 'pk_test_XXXXXXXXXXXXX'); // ⚠️ Palitan

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_name('eyecore_user');
    session_start();
}

// ============================================
// NOTIFICATION FUNCTIONS
// ============================================

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($user_id) {
    global $conn;
    $query = mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $result = mysqli_fetch_assoc($query);
    return $result['total'] ?? 0;
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
 * Get all notifications for a user (with pagination)
 */
function getAllNotifications($user_id, $page = 1, $per_page = 20) {
    global $conn;
    $offset = ($page - 1) * $per_page;
    $query = "SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT $offset, $per_page";
    return mysqli_query($conn, $query);
}

/**
 * Get total notifications count for a user
 */
function getTotalNotificationsCount($user_id) {
    global $conn;
    $query = mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications WHERE user_id = $user_id");
    $result = mysqli_fetch_assoc($query);
    return $result['total'] ?? 0;
}

/**
 * Add a new notification
 */
function addNotification($user_id, $type, $title, $message, $link = '') {
    global $conn;
    
    // Validate notification type
    $allowed_types = ['appointment', 'favorite', 'system', 'promo'];
    if (!in_array($type, $allowed_types)) {
        $type = 'system';
    }
    
    $type = mysqli_real_escape_string($conn, $type);
    $title = mysqli_real_escape_string($conn, $title);
    $message = mysqli_real_escape_string($conn, $message);
    $link = mysqli_real_escape_string($conn, $link);
    
    $query = "INSERT INTO notifications (user_id, type, title, message, link, created_at) 
              VALUES ($user_id, '$type', '$title', '$message', '$link', NOW())";
    
    return mysqli_query($conn, $query);
}

/**
 * Mark a single notification as read
 */
function markNotificationAsRead($notification_id, $user_id) {
    global $conn;
    $notification_id = (int)$notification_id;
    $user_id = (int)$user_id;
    
    return mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = $notification_id AND user_id = $user_id");
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsAsRead($user_id) {
    global $conn;
    $user_id = (int)$user_id;
    
    return mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0");
}

/**
 * Delete old notifications (older than 30 days)
 */
function deleteOldNotifications($days = 30) {
    global $conn;
    $date = date('Y-m-d H:i:s', strtotime("-$days days"));
    
    return mysqli_query($conn, "DELETE FROM notifications WHERE created_at < '$date'");
}

/**
 * Format time ago
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    }
    if ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    }
    if ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
    
    return date('M j, Y', $time);
}

/**
 * Get notification icon based on type
 */
function getNotificationIcon($type) {
    $icons = [
        'appointment' => 'fa-calendar-check',
        'favorite' => 'fa-heart',
        'system' => 'fa-info-circle',
        'promo' => 'fa-tag'
    ];
    
    return $icons[$type] ?? 'fa-bell';
}

/**
 * Get notification background color based on type
 */
function getNotificationColor($type) {
    $colors = [
        'appointment' => '#1976d2',
        'favorite' => '#c2185b',
        'system' => '#388e3c',
        'promo' => '#f57c00'
    ];
    
    return $colors[$type] ?? '#667eea';
}

// ============================================
// APPOINTMENT SLOTS FUNCTIONS
// ============================================

/**
 * Generate appointment slots for a clinic
 */
function generateAppointmentSlots($conn, $clinic_id, $start_date, $days = 7) {
    // Get all active doctors for this clinic
    $doctors_query = mysqli_query($conn, "SELECT * FROM doctors WHERE clinic_id = $clinic_id AND is_active = 1");
    
    if (!$doctors_query || mysqli_num_rows($doctors_query) == 0) {
        return false; // No doctors found
    }
    
    $generated = 0;
    
    for ($i = 0; $i < $days; $i++) {
        $current_date = date('Y-m-d', strtotime($start_date . ' + ' . $i . ' days'));
        $day_of_week = strtolower(date('D', strtotime($current_date)));
        
        // Reset doctors query pointer
        mysqli_data_seek($doctors_query, 0);
        
        while ($doctor = mysqli_fetch_assoc($doctors_query)) {
            $schedule = json_decode($doctor['schedule'], true);
            
            // Check if doctor is available on this day
            if (isset($schedule[$day_of_week])) {
                foreach ($schedule[$day_of_week] as $time_range) {
                    list($start, $end) = explode('-', $time_range);
                    
                    // Convert to 24-hour format
                    $start_24 = date('H:i:s', strtotime($start));
                    $end_24 = date('H:i:s', strtotime($end));
                    
                    // Generate slots (every hour)
                    $current_time = strtotime($start);
                    $end_time = strtotime($end);
                    
                    while ($current_time < $end_time) {
                        $slot_time = date('H:i:s', $current_time);
                        
                        // Check if slot already exists
                        $check = mysqli_query($conn, "
                            SELECT id FROM appointment_slots 
                            WHERE clinic_id = $clinic_id 
                            AND doctor_id = {$doctor['id']} 
                            AND slot_date = '$current_date' 
                            AND slot_time = '$slot_time'
                        ");
                        
                        if (mysqli_num_rows($check) == 0) {
                            $insert = mysqli_query($conn, "
                                INSERT INTO appointment_slots 
                                (clinic_id, doctor_id, slot_date, slot_time, max_capacity) 
                                VALUES ($clinic_id, {$doctor['id']}, '$current_date', '$slot_time', 1)
                            ");
                            
                            if ($insert) $generated++;
                        }
                        
                        $current_time = strtotime('+1 hour', $current_time);
                    }
                }
            }
        }
    }
    
    return $generated;
}

/**
 * Check if a specific time slot is available
 */
function isSlotAvailable($conn, $doctor_id, $date, $time) {
    $doctor_id = (int)$doctor_id;
    $date = mysqli_real_escape_string($conn, $date);
    $time = mysqli_real_escape_string($conn, $time);
    
    $query = mysqli_query($conn, "
        SELECT * FROM appointment_slots 
        WHERE doctor_id = $doctor_id 
        AND slot_date = '$date' 
        AND slot_time = '$time'
    ");
    
    if (mysqli_num_rows($query) == 0) {
        return false; // Slot doesn't exist
    }
    
    $slot = mysqli_fetch_assoc($query);
    $available = $slot['max_capacity'] - ($slot['booked_count'] + $slot['walk_in_reserved']);
    
    return $available > 0 && $slot['status'] == 'available';
}

/**
 * Book an appointment slot (increment booked count)
 */
function bookAppointmentSlot($conn, $doctor_id, $date, $time, $type = 'online') {
    $doctor_id = (int)$doctor_id;
    $date = mysqli_real_escape_string($conn, $date);
    $time = mysqli_real_escape_string($conn, $time);
    
    if ($type == 'online') {
        $update = mysqli_query($conn, "
            UPDATE appointment_slots 
            SET booked_count = booked_count + 1 
            WHERE doctor_id = $doctor_id 
            AND slot_date = '$date' 
            AND slot_time = '$time'
        ");
    } else {
        $update = mysqli_query($conn, "
            UPDATE appointment_slots 
            SET walk_in_reserved = walk_in_reserved + 1 
            WHERE doctor_id = $doctor_id 
            AND slot_date = '$date' 
            AND slot_time = '$time'
        ");
    }
    
    // Update status if fully booked
    mysqli_query($conn, "
        UPDATE appointment_slots 
        SET status = 'full' 
        WHERE doctor_id = $doctor_id 
        AND slot_date = '$date' 
        AND slot_time = '$time'
        AND (booked_count + walk_in_reserved) >= max_capacity
    ");
    
    return $update;
}

/**
 * Release/Cancel an appointment slot (decrement booked count)
 */
function releaseAppointmentSlot($conn, $doctor_id, $date, $time, $type = 'online') {
    $doctor_id = (int)$doctor_id;
    $date = mysqli_real_escape_string($conn, $date);
    $time = mysqli_real_escape_string($conn, $time);
    
    if ($type == 'online') {
        $update = mysqli_query($conn, "
            UPDATE appointment_slots 
            SET booked_count = GREATEST(booked_count - 1, 0) 
            WHERE doctor_id = $doctor_id 
            AND slot_date = '$date' 
            AND slot_time = '$time'
        ");
    } else {
        $update = mysqli_query($conn, "
            UPDATE appointment_slots 
            SET walk_in_reserved = GREATEST(walk_in_reserved - 1, 0) 
            WHERE doctor_id = $doctor_id 
            AND slot_date = '$date' 
            AND slot_time = '$time'
        ");
    }
    
    // Update status back to available if not fully booked
    mysqli_query($conn, "
        UPDATE appointment_slots 
        SET status = 'available' 
        WHERE doctor_id = $doctor_id 
        AND slot_date = '$date' 
        AND slot_time = '$time'
        AND (booked_count + walk_in_reserved) < max_capacity
    ");
    
    return $update;
}

/**
 * Get available time slots for a doctor on a specific date
 */
function getAvailableTimeSlots($conn, $doctor_id, $date) {
    $doctor_id = (int)$doctor_id;
    $date = mysqli_real_escape_string($conn, $date);
    
    $query = mysqli_query($conn, "
        SELECT * FROM appointment_slots 
        WHERE doctor_id = $doctor_id 
        AND slot_date = '$date'
        AND status = 'available'
        AND (booked_count + walk_in_reserved) < max_capacity
        ORDER BY slot_time
    ");
    
    $slots = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $slots[] = $row;
    }
    
    return $slots;
}

/**
 * Get all slots (available and booked) for a doctor on a specific date
 */
function getAllTimeSlots($conn, $doctor_id, $date) {
    $doctor_id = (int)$doctor_id;
    $date = mysqli_real_escape_string($conn, $date);
    
    $query = mysqli_query($conn, "
        SELECT * FROM appointment_slots 
        WHERE doctor_id = $doctor_id 
        AND slot_date = '$date'
        ORDER BY slot_time
    ");
    
    $slots = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $slots[] = $row;
    }
    
    return $slots;
}

/**
 * Check if user has conflicting appointment (same date and time)
 */
function hasUserConflict($conn, $user_id, $date, $time) {
    $user_id = (int)$user_id;
    $date = mysqli_real_escape_string($conn, $date);
    $time = mysqli_real_escape_string($conn, $time);
    
    $query = mysqli_query($conn, "
        SELECT COUNT(*) as total 
        FROM appointments 
        WHERE user_id = $user_id 
        AND appointment_date = '$date' 
        AND appointment_time = '$time'
        AND status != 'cancelled'
    ");
    
    $result = mysqli_fetch_assoc($query);
    return $result['total'] > 0;
}

/**
 * Get user's daily appointment count
 */
function getUserDailyCount($conn, $user_id, $date) {
    $user_id = (int)$user_id;
    $date = mysqli_real_escape_string($conn, $date);
    
    $query = mysqli_query($conn, "
        SELECT COUNT(*) as total 
        FROM appointments 
        WHERE user_id = $user_id 
        AND appointment_date = '$date'
        AND status != 'cancelled'
    ");
    
    $result = mysqli_fetch_assoc($query);
    return $result['total'];
}

// ============================================
// DOCTOR FUNCTIONS
// ============================================

/**
 * Get available doctors for a specific date
 */
function getAvailableDoctors($conn, $clinic_id, $date) {
    $clinic_id = (int)$clinic_id;
    $date = mysqli_real_escape_string($conn, $date);
    $day_of_week = strtolower(date('D', strtotime($date)));
    
    $query = mysqli_query($conn, "
        SELECT * FROM doctors 
        WHERE clinic_id = $clinic_id 
        AND is_active = 1
    ");
    
    $available_doctors = [];
    
    while ($doctor = mysqli_fetch_assoc($query)) {
        $schedule = json_decode($doctor['schedule'], true);
        
        if (isset($schedule[$day_of_week])) {
            $doctor['available_slots'] = count(getAvailableTimeSlots($conn, $doctor['id'], $date));
            $available_doctors[] = $doctor;
        }
    }
    
    return $available_doctors;
}

/**
 * Format time for display
 */
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

/**
 * Format date for display
 */
function formatDate($date) {
    return date('F j, Y', strtotime($date));
}

// ============================================
// REWARDS / POINTS FUNCTIONS
// ============================================

/**
 * Add points to a user
 */
function addPoints($user_id, $points, $action, $description = '', $reference_id = null) {
    global $conn;

    // Prevent duplicate points for same action + reference
    if ($reference_id) {
        $check = mysqli_query($conn,
            "SELECT id FROM user_rewards
             WHERE user_id = $user_id
             AND action = '" . mysqli_real_escape_string($conn, $action) . "'
             AND reference_id = $reference_id
             LIMIT 1"
        );
        if (mysqli_num_rows($check) > 0) return false; // already awarded
    }

    $desc = mysqli_real_escape_string($conn, $description);
    $act  = mysqli_real_escape_string($conn, $action);
    $ref  = $reference_id ? $reference_id : 'NULL';

    return mysqli_query($conn,
        "INSERT INTO user_rewards (user_id, points, action, description, reference_id, created_at)
         VALUES ($user_id, $points, '$act', '$desc', $ref, NOW())"
    );
}

/**
 * Get total points for a user
 */
function getTotalPoints($user_id) {
    global $conn;
    $result = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) as total FROM user_rewards WHERE user_id = $user_id"
    ));
    return (int)($result['total'] ?? 0);
}

// ============================================
// AUTO CREATE TABLES IF NOT EXISTS
// ============================================

// Create notifications table if not exists
$create_notifications_table = "
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('appointment', 'favorite', 'system', 'promo') NOT NULL DEFAULT 'system',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(255),
    is_read TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (is_read),
    INDEX (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_notifications_table);

// Create appointment_slots table if not exists
$create_slots_table = "
CREATE TABLE IF NOT EXISTS appointment_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    doctor_id INT DEFAULT NULL,
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    max_capacity INT DEFAULT 1,
    booked_count INT DEFAULT 0,
    walk_in_reserved INT DEFAULT 0,
    status ENUM('available', 'full', 'closed') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slot (clinic_id, doctor_id, slot_date, slot_time),
    KEY doctor_id (doctor_id),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_slots_table);

// Create user_rewards table if not exists (for points system)
$create_rewards_table = "
CREATE TABLE IF NOT EXISTS user_rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    points INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (action),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_rewards_table);

// ============================================
// CHECK FOR TEST NOTIFICATIONS (optional)
// ============================================
if (isset($_SESSION['user_id']) && isset($_GET['test_notification'])) {
    $test_user_id = $_SESSION['user_id'];
    addNotification(
        $test_user_id,
        'system',
        'Welcome to Eyecore!',
        'Thank you for using our service. We hope you have a great experience.',
        'dashboard.php'
    );
    
    addNotification(
        $test_user_id,
        'appointment',
        'Appointment Reminder',
        'You have an appointment tomorrow at 2:00 PM.',
        'my-appointments.php'
    );
    
    echo "Test notifications added! <a href='{$_SERVER['PHP_SELF']}'>Refresh</a>";
}
?>