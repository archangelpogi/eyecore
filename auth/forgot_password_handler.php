<?php
session_name('eyecore_user');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// CSRF check for all actions
if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'];

// ==================== ACTION 1: SEND OTP ====================
if ($action === 'send_otp') {
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
        exit;
    }
    
    // Rate limit: max 3 attempts per 15 minutes
    $rateLimitKey = 'reset_attempts_' . md5($email);
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 0, 'first' => time()];
    }
    $rl = &$_SESSION[$rateLimitKey];
    if (time() - $rl['first'] > 900) {
        $rl = ['count' => 0, 'first' => time()];
    }
    if ($rl['count'] >= 3) {
        echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait 15 minutes.']);
        exit;
    }
    $rl['count']++;
    
    // Generate OTP
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    $_SESSION['reset_otp_plain'] = $otp;
    $_SESSION['reset_otp_email'] = $email;
    $_SESSION['reset_otp_expires'] = $expires;
    $_SESSION['reset_email'] = $email;
    
    $fullName = htmlspecialchars($user['fullname']);
    
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('no-reply@eyecore.com', 'Eyecore');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = '🔑 Eyecore Password Reset Code';
        $mail->Body = "
            <div style='font-family:Arial;max-width:480px;margin:0 auto;background:#fff;border-radius:16px;'>
                <div style='background:linear-gradient(135deg,#00B761,#00A86B);padding:28px 32px;text-align:center;'>
                    <h1 style='color:white;margin:0;font-size:1.4rem;'>Eyecore</h1>
                    <p style='color:rgba(255,255,255,.85);margin:4px 0 0;'>Password Reset Request</p>
                </div>
                <div style='padding:32px;'>
                    <h2 style='color:#1e293b;margin:0 0 8px;'>Hi, {$fullName}!</h2>
                    <p style='color:#64748b;margin:0 0 24px;'>We received a request to reset your password. Use the code below.</p>
                    <div style='background:#E3FCE9;border:2px dashed #00B761;border-radius:14px;padding:20px;text-align:center;'>
                        <div style='letter-spacing:12px;font-size:2.4rem;font-weight:800;color:#00B761;'>{$otp}</div>
                        <p style='color:#64748b;font-size:.78rem;'>⏱ Valid for <strong>5 minutes</strong> only</p>
                    </div>
                    <div style='background:#fef9c3;border-radius:10px;padding:12px;font-size:.82rem;color:#92400e;margin-top:20px;'>
                        ⚠ Never share this code with anyone. If you did not request this, ignore this email.
                    </div>
                </div>
            </div>
        ";
        $mail->AltBody = "Your Eyecore password reset code is: {$otp}\nValid for 5 minutes only.";
        $mail->send();
        
        echo json_encode(['success' => true, 'message' => 'OTP sent to your email address.']);
    } catch (Exception $e) {
        error_log('Reset OTP error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
    }
    exit;
}

// ==================== ACTION 2: VERIFY OTP ====================
if ($action === 'verify_otp') {
    $email = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $otp = trim($input['otp'] ?? '');
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email']);
        exit;
    }
    
    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the complete 6-digit OTP']);
        exit;
    }
    
    if (empty($_SESSION['reset_otp_plain']) || empty($_SESSION['reset_otp_email']) || empty($_SESSION['reset_otp_expires'])) {
        echo json_encode(['success' => false, 'message' => 'No OTP found. Please request a new one.']);
        exit;
    }
    
    if ($_SESSION['reset_otp_email'] !== $email) {
        echo json_encode(['success' => false, 'message' => 'Email mismatch.']);
        exit;
    }
    
    if (strtotime($_SESSION['reset_otp_expires']) < time()) {
        unset($_SESSION['reset_otp_plain'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_expires']);
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
        exit;
    }
    
    if ($_SESSION['reset_otp_plain'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit;
    }
    
    $_SESSION['reset_otp_verified'] = $email;
    unset($_SESSION['reset_otp_plain'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_expires']);
    
    echo json_encode(['success' => true, 'message' => 'OTP verified. You may now set a new password.']);
    exit;
}

// ==================== ACTION 3: RESET PASSWORD ====================
if ($action === 'reset_password') {
    if (empty($_SESSION['reset_otp_verified'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please complete OTP verification first.']);
        exit;
    }
    
    $email = $_SESSION['reset_otp_verified'];
    $newPassword = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    
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
    
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashed, $email);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        unset($_SESSION['reset_otp_verified'], $_SESSION['reset_email']);
        echo json_encode(['success' => true, 'message' => 'Password reset successfully. You may now log in.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Account not found. Password was not updated.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>