<?php
// config/security.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Security {

    // =====================
    // CSRF Token
    // =====================
    public static function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }

    // =====================
    // Role Checks
    // =====================
    public static function isSuperAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'SuperAdmin';
    }

    public static function isClinicAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'ClinicAdmin';
    }

    public static function isOptician() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'Optician';
    }

    public static function isStaff() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'Staff';
    }

    // =====================
    // Authentication
    // =====================
    public static function isAuthenticated() {
        return isset($_SESSION['user_id'], $_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    public static function validateSession($timeout = 1800) {
        if (!isset($_SESSION['last_activity'])) return false;

        if (time() - $_SESSION['last_activity'] > $timeout) {
            // Session expired
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    // =====================
    // Login Attempts
    // =====================
    public static function checkLoginAttempts($email) {
        $key = 'login_attempts_' . md5($email);
        if (!isset($_SESSION[$key])) return false;

        $attempts = $_SESSION[$key];

        // 5 attempts in 15 minutes
        if ($attempts['count'] >= 5 && (time() - $attempts['first_attempt']) < 900) {
            return true;
        }

        if ((time() - $attempts['first_attempt']) > 900) {
            unset($_SESSION[$key]);
            return false;
        }

        return false;
    }

    public static function recordLoginAttempt($email, $success) {
        $key = 'login_attempts_' . md5($email);
        if ($success) {
            unset($_SESSION[$key]);
        } else {
            if (!isset($_SESSION[$key])) {
                $_SESSION[$key] = ['count'=>1, 'first_attempt'=>time(), 'last_attempt'=>time()];
            } else {
                $_SESSION[$key]['count']++;
                $_SESSION[$key]['last_attempt'] = time();
            }
        }
    }

    // =====================
    // Logging
    // =====================
    public static function logActivity($action, $module, $details = '', $userId = null) {
        $logDir = dirname(__DIR__) . '/logs';
        $logFile = $logDir . '/security.log';

        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'module' => $module,
            'details' => $details,
            'user_id' => $userId ?? ($_SESSION['user_db_id'] ?? 'unknown'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        @file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    }

    // =====================
    // Input Sanitization
    // =====================
    public static function sanitize($input) {
        if (is_array($input)) return array_map([self::class, 'sanitize'], $input);
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    // =====================
    // Logout safely
    // =====================
    public static function logout($redirect = null) {
        // Log first
        if (isset($_SESSION['user_db_id'])) {
            self::logActivity('LOGOUT', 'Authentication', 'User logged out', $_SESSION['user_db_id']);
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();

        // Only redirect if headers not sent
        if ($redirect && !headers_sent()) {
            header("Location: $redirect");
            exit();
        }
    }

    // =====================
    // Role-based redirect helper
    // =====================
    public static function redirectUnauthorized($allowedRoles = [], $redirect = '../admin/login.php?error=unauthorized') {
        $userRole = $_SESSION['role'] ?? '';
        if (!in_array($userRole, $allowedRoles)) {
            self::logout($redirect);
        }
    }
}
?>
