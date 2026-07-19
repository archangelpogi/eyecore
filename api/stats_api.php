<?php
session_start();
include __DIR__ . '/../config/db.php';

// Only SuperAdmin
$currentRole = $_SESSION['role'] ?? 'SuperAdmin';
if ($currentRole != 'SuperAdmin') {
    http_response_code(403);
    echo json_encode(['error'=>'Access denied']);
    exit;
}

// Fetch stats
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn();
$adminUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='ClinicAdmin'")->fetchColumn();
$optometristUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='Optometrist'")->fetchColumn();

echo json_encode([
    'totalUsers' => $totalUsers,
    'activeUsers' => $activeUsers,
    'adminUsers' => $adminUsers,
    'optometristUsers' => $optometristUsers
]);
