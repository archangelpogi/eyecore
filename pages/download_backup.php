<?php

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$file = $_GET['file'] ?? '';
if (empty($file)) {
    die('Invalid file');
}

$backup_dir = __DIR__ . '/../backups/';
$file_path = realpath($backup_dir . $file);

// Security check
if (!$file_path || strpos($file_path, realpath($backup_dir)) !== 0) {
    die('Invalid file path');
}

if (file_exists($file_path)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
} else {
    die('File not found');
}
?>