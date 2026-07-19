<?php
// auth/middleware.php
require_once '../config/db.php';
require_once '../config/security.php';

// Check if user is authenticated
if (!Security::validateSession()) {
    Security::logActivity('SESSION_EXPIRED', 'Authentication', 
                         'Session expired or invalid', $_SESSION['user_db_id'] ?? null);
    Security::logout();
    
    // Return JSON response for API calls
    if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Session expired. Please login again.',
            'redirect' => '/auth/login.php'
        ]);
        exit();
    } else {
        // Redirect to login with message
        $_SESSION['login_message'] = 'Your session has expired. Please login again.';
        header('Location: /auth/login.php');
        exit();
    }
}

// Check if user is SuperAdmin (for admin pages)
if (defined('REQUIRE_SUPERADMIN') && REQUIRE_SUPERADMIN === true) {
    if (!Security::isSuperAdmin()) {
        Security::logActivity('UNAUTHORIZED_ACCESS', 'Authorization', 
                             'Attempted to access SuperAdmin area', $_SESSION['user_db_id']);
        
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized access.'
            ]);
            exit();
        } else {
            header('Location: /index.php');
            exit();
        }
    }
}

// Set headers for security
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
?>