<?php
session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

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
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// ✅ Email MUST exist (opposite of registration)
$stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ? AND status = 'Active'");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Generic message for security (don't reveal if email exists or not)
    echo json_encode(['success' => false, 'message' => 'No active account found with this email.']);
    exit;
}

// Rate limit: max 3 OTP requests per 15 minutes
$rateLimitKey = 'reset_attempts_' . md5($email);
if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'first' => time()];
}
$rl = &$_SESSION[$rateLimitKey];
if (time() - $rl['first'] > 900) {
    // Reset window
    $rl = ['count' => 0, 'first' => time()];
}
if ($rl['count'] >= 3) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait 15 minutes.']);
    exit;
}
$rl['count']++;

// Generate 6-digit OTP
$otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

// Store reset OTP in session (separate from registration OTP)
$_SESSION['reset_otp_plain']   = $otp;
$_SESSION['reset_otp_email']   = $email;
$_SESSION['reset_otp_expires'] = $expires;
$_SESSION['reset_otp_verified'] = false;

$firstName = htmlspecialchars($user['first_name']);

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'angelloricanmendoza27@gmail.com';
    $mail->Password   = 'tkyv vypr pxvm pfse';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('no-reply@eyecore.com', 'Eyecore');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = '🔑 Eyecore Password Reset Code';
    $mail->Body = "
        <div style='font-family:\"Plus Jakarta Sans\",Arial,sans-serif;max-width:480px;margin:0 auto;
                    background:#fff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,.08);'>
            <div style='background:linear-gradient(135deg,#0d9488,#0891b2);
                        padding:28px 32px;text-align:center;'>
                <div style='width:56px;height:56px;background:rgba(255,255,255,.2);
                            border-radius:14px;display:inline-flex;align-items:center;
                            justify-content:center;margin-bottom:12px;'>
                    <span style='font-size:28px;'>🔑</span>
                </div>
                <h1 style='color:white;margin:0;font-size:1.4rem;font-weight:800;'>Eyecore</h1>
                <p style='color:rgba(255,255,255,.85);margin:4px 0 0;font-size:.9rem;'>Password Reset Request</p>
            </div>
            <div style='padding:32px;'>
                <h2 style='color:#1e293b;font-size:1.1rem;font-weight:700;margin:0 0 8px;'>
                    Hi, {$firstName}!
                </h2>
                <p style='color:#64748b;font-size:.9rem;margin:0 0 24px;line-height:1.6;'>
                    We received a request to reset your Eyecore password. Use the code below to proceed.
                </p>
                <div style='background:#f0fdfa;border:2px dashed #0d9488;border-radius:14px;
                            padding:20px;text-align:center;margin-bottom:24px;'>
                    <div style='letter-spacing:12px;font-size:2.4rem;font-weight:800;
                                color:#0d9488;font-family:monospace;'>
                        {$otp}
                    </div>
                    <p style='color:#64748b;font-size:.78rem;margin:8px 0 0;'>
                        ⏱ Valid for <strong>5 minutes</strong> only
                    </p>
                </div>
                <div style='background:#fef9c3;border:1px solid #fde68a;border-radius:10px;
                            padding:12px 16px;font-size:.82rem;color:#92400e;'>
                    <strong>⚠ Security Notice:</strong> Never share this code with anyone.
                    If you did not request this, please ignore this email — your password will not change.
                </div>
            </div>
            <div style='background:#f8fafc;padding:16px 32px;text-align:center;
                        font-size:.75rem;color:#94a3b8;border-top:1px solid #e2e8f0;'>
                &copy; " . date('Y') . " Eyecore System. All rights reserved.
            </div>
        </div>
    ";
    $mail->AltBody = "Your Eyecore password reset code is: {$otp}\nValid for 5 minutes only.";
    $mail->send();

    echo json_encode(['success' => true, 'message' => 'OTP sent to your email address.']);
} catch (Exception $e) {
    error_log('Reset OTP email error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
}