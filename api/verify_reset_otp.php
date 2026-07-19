<?php
session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

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

$email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$otp   = trim($input['otp'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email']);
    exit;
}

if (strlen($otp) !== 6 || !ctype_digit($otp)) {
    echo json_encode(['success' => false, 'message' => 'Please enter the complete 6-digit OTP']);
    exit;
}

// Check session OTP exists
if (
    empty($_SESSION['reset_otp_plain'])   ||
    empty($_SESSION['reset_otp_email'])   ||
    empty($_SESSION['reset_otp_expires'])
) {
    echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
    exit;
}

// Email must match
if ($_SESSION['reset_otp_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Email mismatch.']);
    exit;
}

// Check expiry
if (strtotime($_SESSION['reset_otp_expires']) < time()) {
    unset($_SESSION['reset_otp_plain'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_expires']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

// Verify OTP
if ($_SESSION['reset_otp_plain'] !== $otp) {
    echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
    exit;
}

// ✅ OTP correct — mark as verified
$_SESSION['reset_otp_verified'] = $email;

// Clean up OTP data (keep verified flag)
unset($_SESSION['reset_otp_plain'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_expires']);

echo json_encode(['success' => true, 'message' => 'OTP verified. You may now set a new password.']);