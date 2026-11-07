<?php
// rider/logout.php
session_start();

// Clear rider session
unset($_SESSION['rider_id']);
unset($_SESSION['rider_name']);
unset($_SESSION['rider_phone']);

// Kung may supplier session din, i-clear din
if (empty($_SESSION['supplier_id'])) {
    session_destroy();
}

header('Location: ../suppliers/supplier_login.php');
exit;