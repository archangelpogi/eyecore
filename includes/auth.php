<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SuperAdmin session check
function checkSuperAdmin() {
    if (!isset($_SESSION['authenticated']) || $_SESSION['role'] !== 'SuperAdmin') {
        header('Location: /eyecore/auth/login.php');
        exit;
    }
}

// Clinic user session check
function checkClinicUser() {
    if (!isset($_SESSION['authenticated']) || !in_array($_SESSION['role'], ['ClinicAdmin','Optometrist','Staff'])) {
        header('Location: /eyecore/admin/login.php');
        exit;
    }
}
