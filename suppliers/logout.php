<?php
// suppliers/logout.php
session_start();

// Include security
require_once '../config/security.php';

// Log the logout
if (isset($_SESSION['supplier_id'])) {
    Security::logActivity('SUPPLIER_LOGOUT', 'Supplier Authentication', 'Supplier logged out', $_SESSION['supplier_id']);
}

// Destroy session
Security::logout(); // Kung meron logout method sa Security class

// Redirect to supplier login
header('Location: supplier_login.php');
exit();
?>