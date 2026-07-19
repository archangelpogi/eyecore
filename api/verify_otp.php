<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
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

// Basic validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email']);
    exit;
}
if (strlen($otp) !== 6 || !ctype_digit($otp)) {
    echo json_encode(['success' => false, 'message' => 'Please enter the complete 6-digit OTP']);
    exit;
}

// Check session OTP
if (
    !isset($_SESSION['reg_otp_plain'])   ||
    !isset($_SESSION['reg_otp_email'])   ||
    !isset($_SESSION['reg_otp_expires'])
) {
    echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
    exit;
}

// Email must match
if ($_SESSION['reg_otp_email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Email mismatch.']);
    exit;
}

// Check expiry
if (strtotime($_SESSION['reg_otp_expires']) < time()) {
    // Clean up
    unset($_SESSION['reg_otp_plain'], $_SESSION['reg_otp'],
          $_SESSION['reg_otp_email'], $_SESSION['reg_otp_expires']);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

// Verify OTP
if ($_SESSION['reg_otp_plain'] !== $otp) {
    echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
    exit;
}

// ✅ OTP Correct! Mark as verified in session
$_SESSION['otp_email_verified'] = $email;

// Clean up OTP session data
unset($_SESSION['reg_otp_plain'], $_SESSION['reg_otp'],
      $_SESSION['reg_otp_email'], $_SESSION['reg_otp_expires']);

echo json_encode(['success' => true, 'message' => 'Email verified successfully']);