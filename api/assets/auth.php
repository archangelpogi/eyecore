<?php
// assets/auth.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getDbConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $host = 'localhost';
        $dbname = 'u334978718_eyecore_db';
        $username = 'u334978718_eyecore_user';
        $password = 'Eyecore@2026';
        
        try {
            $conn = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die(json_encode(['success' => false, 'message' => 'Database connection failed']));
        }
    }
    
    return $conn;
}

// Check if employee is authenticated
function checkAuth() {
    if (!isset($_SESSION['employee_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login']);
        exit;
    }
    return $_SESSION['employee_id'];
}

// Get employee info
function getEmployeeInfo($employee_id) {
    $conn = getDbConnection();
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                e.id,
                e.user_id,
                e.employee_no,
                e.position_id,
                e.clinic_id,
                e.status as emp_status,
                u.first_name,
                u.last_name,
                u.email,
                u.role
            FROM employees e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get employee info error: " . $e->getMessage());
        return null;
    }
}

// Calculate distance between two coordinates (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meters
    
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    
    $latDelta = $lat2 - $lat1;
    $lonDelta = $lon2 - $lon1;
    
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
    
    return $angle * $earthRadius;
}

// Get device info
function getDeviceInfo() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceInfo = '';
    
    if (strpos($userAgent, 'Mobile') !== false) {
        $deviceInfo = 'Mobile';
    } else {
        $deviceInfo = 'Desktop';
    }
    
    if (strpos($userAgent, 'Android') !== false) {
        $deviceInfo .= ' (Android)';
    } elseif (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
        $deviceInfo .= ' (iOS)';
    } elseif (strpos($userAgent, 'Windows') !== false) {
        $deviceInfo .= ' (Windows)';
    } elseif (strpos($userAgent, 'Mac') !== false) {
        $deviceInfo .= ' (Mac)';
    }
    
    return substr($deviceInfo, 0, 255);
}

// Save photo from base64
function saveAttendancePhoto($base64_image, $employee_id) {
    if (empty($base64_image) || strpos($base64_image, 'data:image') !== 0) {
        return null;
    }
    
    // Create uploads directory if not exists
    $uploadDir = __DIR__ . '/../uploads/attendance/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    try {
        // Extract image data
        $imageData = explode(',', $base64_image);
        $imageData = base64_decode($imageData[1]);
        
        // Generate filename
        $filename = 'attendance_' . $employee_id . '_' . date('Ymd_His') . '_' . uniqid() . '.jpg';
        $filepath = $uploadDir . $filename;
        
        // Save file
        if (file_put_contents($filepath, $imageData) !== false) {
            return 'uploads/attendance/' . $filename;
        }
    } catch (Exception $e) {
        error_log("Save photo error: " . $e->getMessage());
    }
    
    return null;
}
?>