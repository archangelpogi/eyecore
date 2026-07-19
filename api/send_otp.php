<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

// Only POST allowed
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

// Check if email already exists in users table
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Email is already registered.']);
    exit;
}

// Generate 6-digit OTP
$otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

// Store OTP in session (not DB yet — user not created until OTP verified)
$_SESSION['reg_otp']         = password_hash($otp, PASSWORD_DEFAULT); // hashed for security
$_SESSION['reg_otp_email']   = $email;
$_SESSION['reg_otp_expires'] = $expires;
$_SESSION['reg_otp_plain']   = $otp; // keep plain for comparison (short-lived session)

// Send email
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
    $mail->Subject = '🔐 Your Eyecore Registration OTP';
    $mail->Body = "
        <div style='font-family:\"Plus Jakarta Sans\",Arial,sans-serif;max-width:480px;margin:0 auto;
                    background:#fff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,.08);'>
            <div style='background:linear-gradient(135deg,#0d9488,#0891b2);
                        padding:28px 32px;text-align:center;'>
                <div style='width:56px;height:56px;background:rgba(255,255,255,.2);
                            border-radius:14px;display:inline-flex;align-items:center;
                            justify-content:center;margin-bottom:12px;'>
                    <span style='font-size:28px;'>👁️</span>
                </div>
                <h1 style='color:white;margin:0;font-size:1.4rem;font-weight:800;'>Eyecore</h1>
                <p style='color:rgba(255,255,255,.85);margin:4px 0 0;font-size:.9rem;'>Clinic Registration Verification</p>
            </div>
            <div style='padding:32px;'>
                <h2 style='color:#1e293b;font-size:1.1rem;font-weight:700;margin:0 0 8px;'>
                    Email Verification Code
                </h2>
                <p style='color:#64748b;font-size:.9rem;margin:0 0 24px;line-height:1.6;'>
                    Use the code below to verify your email address for your Eyecore clinic registration.
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
                    Eyecore staff will never ask for your OTP.
                </div>
            </div>
            <div style='background:#f8fafc;padding:16px 32px;text-align:center;
                        font-size:.75rem;color:#94a3b8;border-top:1px solid #e2e8f0;'>
                If you didn't request this, please ignore this email.
                &copy; " . date('Y') . " Eyecore System
            </div>
        </div>
    ";
    $mail->AltBody = "Your Eyecore OTP is: {$otp}\nValid for 5 minutes only.";
    $mail->send();

    echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
} catch (Exception $e) {
    error_log('OTP email error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
}