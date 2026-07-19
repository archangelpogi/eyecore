<?php
// config/db.php

// Database configuration - UPDATE THESE WITH YOUR CREDENTIALS
$host = 'localhost';
$dbname = 'u334978718_eyecore_db';
$username = 'u334978718_eyecore_user';
$password = 'Eyecore@2026';

// Create connection
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // ============================================
    // FIX: Illegal mix of collations error
    // Forces all connections to use same collation
    // ============================================
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET SESSION collation_connection = utf8mb4_unicode_ci");
    $pdo->exec("SET SESSION collation_database = utf8mb4_unicode_ci");
    
} catch (PDOException $e) {
    // For development - show error
    die("Connection failed: " . $e->getMessage());
    // For production: die("System temporarily unavailable.");
}

// Set timezone
date_default_timezone_set('Asia/Manila');
?>