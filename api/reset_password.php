<?php
session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// CSRF check
if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Must have verified OTP first
if (empty($_SESSION['reset_otp_verified'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please complete OTP verification first.']);
    exit;
}

$email           = $_SESSION['reset_otp_verified'];
$newPassword     = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

// Password validations
if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

if (!preg_match('/[A-Z]/', $newPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter.']);
    exit;
}

if (!preg_match('/[0-9]/', $newPassword)) {
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one number.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

try {
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET password = ?, remember_token = NULL WHERE email = ?");
    $stmt->execute([$hashed, $email]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Account not found. Password was not updated.']);
        exit;
    }

    // Clear the verified flag
    unset($_SESSION['reset_otp_verified']);

    echo json_encode(['success' => true, 'message' => 'Password reset successfully. You may now log in.']);
} catch (PDOException $e) {
    error_log('Reset password error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again.']);
}