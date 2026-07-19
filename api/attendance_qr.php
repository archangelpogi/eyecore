<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['generate_qr'])) {
        $today = date('Y-m-d');
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d 23:59:59');
        
        $pdo->prepare("
            INSERT INTO attendance_qr_codes (qr_token, expires_at, is_active)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE qr_token = VALUES(qr_token)
        ")->execute([$token, $expires]);
        
        echo json_encode([
            'success' => true,
            'token' => $token,
            'expires' => $expires
        ]);
    }
} else {
    // Get today's QR
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM attendance_qr_codes WHERE DATE(created_at) = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$today]);
    $qr = $stmt->fetch();
    
    echo json_encode($qr ?: null);
}
?>