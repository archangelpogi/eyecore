<?php
// C:\xampp\htdocs\eyecore\suppliers\verify_email.php
session_start();
require_once __DIR__ . '/../config/db.php';

global $pdo;

$status = 'error'; // default
$title = 'Invalid Link';
$message = 'This verification link is invalid or has expired.';
$showResend = false;
$tokenEmail = '';

// ============================================
// HANDLE RESEND VERIFICATION EMAIL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verification'])) {
    $resendEmail = trim($_POST['resend_email'] ?? '');
    
    if (empty($resendEmail) || !filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['verify_swal'] = [
            'icon'  => 'error',
            'title' => 'Invalid Email',
            'text'  => 'Please enter a valid email address.'
        ];
    } else {
        try {
            $query = "SELECT id, first_name, last_name, email, status, is_verified, email_verified_at
                      FROM users 
                      WHERE email = :email 
                        AND role = 'Supplier'
                      LIMIT 1";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':email', $resendEmail);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch();
                
                if ($user['email_verified_at'] !== null) {
                    $_SESSION['verify_swal'] = [
                        'icon'  => 'info',
                        'title' => 'Already Verified',
                        'text'  => 'This email is already verified. You can now wait for admin approval.'
                    ];
                } elseif ($user['status'] === 'Rejected' || $user['status'] === 'Suspended') {
                    $_SESSION['verify_swal'] = [
                        'icon'  => 'error',
                        'title' => 'Account ' . $user['status'],
                        'text'  => 'Please contact support for assistance.'
                    ];
                } else {
                    // Generate new token
                    require_once __DIR__ . '/../config/mailer.php';
                    
                    $newToken = bin2hex(random_bytes(32));
                    $newExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    $updateQuery = "UPDATE users 
                                    SET email_verification_token = :token, 
                                        email_verification_expires = :expires 
                                    WHERE id = :id";
                    $updateStmt = $pdo->prepare($updateQuery);
                    $updateStmt->execute([
                        ':token'   => $newToken,
                        ':expires' => $newExpires,
                        ':id'      => $user['id']
                    ]);
                    
                    // Send email
                    $sent = @sendSupplierVerificationEmail(
                        $user['email'],
                        $user['first_name'],
                        $user['last_name'],
                        $newToken
                    );
                    
                    if ($sent) {
                        $_SESSION['verify_swal'] = [
                            'icon'  => 'success',
                            'title' => 'Email Sent!',
                            'text'  => 'A new verification link has been sent to ' . htmlspecialchars($user['email'])
                        ];
                    } else {
                        $_SESSION['verify_swal'] = [
                            'icon'  => 'error',
                            'title' => 'Email Failed',
                            'text'  => 'Unable to send email. Please try again later.'
                        ];
                    }
                }
            } else {
                // Security: don't reveal if email exists
                $_SESSION['verify_swal'] = [
                    'icon'  => 'info',
                    'title' => 'Check Your Email',
                    'text'  => 'If an account exists with that email, a new verification link has been sent.'
                ];
            }
        } catch (PDOException $e) {
            error_log("Resend verification error: " . $e->getMessage());
            $_SESSION['verify_swal'] = [
                'icon'  => 'error',
                'title' => 'System Error',
                'text'  => 'Please try again later.'
            ];
        }
    }
    
    header("Location: verify_email.php" . (!empty($resendEmail) ? "?email=" . urlencode($resendEmail) : ""));
    exit();
}

// ============================================
// HANDLE TOKEN VERIFICATION
// ============================================
$token = trim($_GET['token'] ?? '');
$emailParam = trim($_GET['email'] ?? '');

if (empty($token)) {
    // Walang token — baka nag-resend lang or direct access
    if (!empty($emailParam)) {
        $title = 'Verify Your Email';
        $message = 'Click the link in your email to verify your account. Didn\'t receive it?';
        $status = 'info';
        $showResend = true;
        $tokenEmail = $emailParam;
    } else {
        $title = 'Verification Link Required';
        $message = 'Please click the verification link sent to your email.';
        $status = 'info';
        $showResend = true;
    }
} else {
    // May token — i-verify
    try {
        $query = "SELECT id, email, first_name, last_name, status, 
                         email_verified_at, email_verification_expires
                  FROM users 
                  WHERE email_verification_token = :token 
                    AND role = 'Supplier'
                  LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            // Token not found
            $status = 'error';
            $title = 'Invalid Verification Link';
            $message = 'This verification link is invalid. It may have been used already or the link is incorrect.';
            $showResend = true;
        } else {
            $user = $stmt->fetch();
            
            // Already verified?
            if ($user['email_verified_at'] !== null) {
                $status = 'success';
                $title = 'Email Already Verified';
                $message = 'Your email has already been verified. Please wait for admin approval.';
            }
            // Account is rejected or suspended
            elseif (in_array($user['status'], ['Rejected', 'Suspended'])) {
                $status = 'error';
                $title = 'Account ' . $user['status'];
                $message = 'Your account is currently ' . strtolower($user['status']) . '. Please contact support.';
            }
            // Token expired?
            elseif (strtotime($user['email_verification_expires']) < time()) {
                $status = 'error';
                $title = 'Verification Link Expired';
                $message = 'This verification link has expired. Please request a new one.';
                $showResend = true;
                $tokenEmail = $user['email'];
            }
            // ✅ SUCCESS — verify the email
            else {
                $updateQuery = "UPDATE users 
                                SET email_verified_at = NOW(), 
                                    email_verification_token = NULL,
                                    email_verification_expires = NULL
                                WHERE id = :id";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->execute([':id' => $user['id']]);
                
                $status = 'success';
                $title = 'Email Verified Successfully! 🎉';
                $message = 'Thank you, ' . htmlspecialchars($user['first_name']) . '! Your email has been verified. '
                         . 'Your application is now pending review by our team. You\'ll receive an email once approved.';
            }
        }
    } catch (PDOException $e) {
        error_log("Verify email error: " . $e->getMessage());
        $status = 'error';
        $title = 'System Error';
        $message = 'Something went wrong. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Email Verification</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --teal-50: #f0fdfa;
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --blue-50: #eff6ff;
            --gray-50: #f9fafb;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-900: #111827;
        }
        body {
            background: linear-gradient(135deg, var(--teal-50), var(--blue-50));
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .brand-logo {
            background-color: var(--teal-600);
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .auth-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 480px;
            width: 100%;
            padding: 2.5rem;
            text-align: center;
        }
        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }
        .status-icon.success {
            background-color: #d1fae5;
            color: #059669;
        }
        .status-icon.error {
            background-color: #fee2e2;
            color: #dc2626;
        }
        .status-icon.info {
            background-color: #dbeafe;
            color: #2563eb;
        }
        .btn-teal {
            background-color: var(--teal-600);
            color: white;
            border: none;
            padding: 10px 24px;
        }
        .btn-teal:hover {
            background-color: var(--teal-700);
            color: white;
        }
        .form-control:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.25);
        }
        .text-teal-600 { color: var(--teal-600); }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="brand-logo mb-4">
            <i class="fas fa-eye text-white fa-2x"></i>
        </div>
        
        <div class="status-icon <?= $status ?>">
            <?php if ($status === 'success'): ?>
                <i class="fas fa-check-circle"></i>
            <?php elseif ($status === 'error'): ?>
                <i class="fas fa-times-circle"></i>
            <?php else: ?>
                <i class="fas fa-envelope-open-text"></i>
            <?php endif; ?>
        </div>
        
        <h1 class="h4 fw-bold text-gray-900 mb-3"><?= htmlspecialchars($title) ?></h1>
        <p class="text-gray-600 mb-4" style="line-height: 1.6;"><?= $message ?></p>
        
        <?php if ($showResend): ?>
            <hr class="my-4">
            <p class="text-gray-700 small mb-3">
                <strong>Need a new verification link?</strong>
            </p>
            <form method="POST" action="verify_email.php">
                <input type="hidden" name="resend_verification" value="1">
                <div class="mb-3">
                    <input type="email" 
                           name="resend_email" 
                           class="form-control" 
                           placeholder="Enter your registered email"
                           value="<?= htmlspecialchars($tokenEmail) ?>"
                           required>
                </div>
                <button type="submit" class="btn btn-teal w-100">
                    <i class="fas fa-paper-plane me-2"></i>Resend Verification Email
                </button>
            </form>
        <?php endif; ?>
        
        <div class="mt-4 pt-3 border-top">
            <?php if ($status === 'success'): ?>
                <a href="supplier_login.php" class="btn btn-teal w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            <?php else: ?>
                <a href="supplier_login.php" class="text-decoration-none text-teal-600 small">
                    <i class="fas fa-arrow-left me-1"></i>Back to Login
                </a>
            <?php endif; ?>
        </div>
        
        <p class="text-gray-500 mt-4 mb-0" style="font-size: 0.75rem;">
            © 2026 Eyecore Optical Clinic
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <?php if (isset($_SESSION['verify_swal'])): ?>
    <script>
        Swal.fire({
            icon: "<?= $_SESSION['verify_swal']['icon']; ?>",
            title: "<?= $_SESSION['verify_swal']['title']; ?>",
            text: "<?= $_SESSION['verify_swal']['text']; ?>",
            confirmButtonColor: "#0d9488",
            timer: 4000,
            timerProgressBar: true
        });
    </script>
    <?php unset($_SESSION['verify_swal']); endif; ?>
</body>
</html>