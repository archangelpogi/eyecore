<?php
// auth/logout.php
session_start();

// Include security
require_once '../config/security.php';

// Log the logout
Security::logActivity('LOGOUT', 'Authentication', 'User logged out', $_SESSION['user_db_id'] ?? null);

// Destroy session
Security::logout();

// Redirect to login
header('Location: login.php');
exit();
?>