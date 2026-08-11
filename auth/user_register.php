<?php
include '../includes/config.php';
include '../includes/theme.php';

// PHPMailer - Direct include
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

// OTP Functions
function sendOTP($email, $name, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '🔐 Your OTP Code - Eyecore';
        $mail->Body = "
            <div style='font-family:Arial; max-width:400px; margin:0 auto;'>
                <h2 style='color:#00B761;'>Email Verification</h2>
                <p>Hello <strong>$name</strong>,</p>
                <p>Your OTP code is:</p>
                <div style='background:#00B761; color:white; font-size:32px; padding:15px; text-align:center; border-radius:8px;'>
                    <strong>$otp</strong>
                </div>
                <p style='color:#666; margin-top:20px;'>Valid for 5 minutes only.</p>
                <p style='color:#999; font-size:12px;'>If you didn't request this, please ignore this email.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP Error: " . $e->getMessage());
        return false;
    }
}

function sendWelcome($email, $name) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '🎉 Welcome to Eyecore!';
        $mail->Body = "
            <div style='font-family:Arial; max-width:400px; margin:0 auto;'>
                <h2 style='color:#00B761;'>Welcome $name!</h2>
                <p>Your account has been successfully verified.</p>
                <p>You can now login to your patient portal.</p>
                <p style='margin-top:20px;'>👓 Start booking appointments and explore our services!</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: ../pages/dashboard.php');
    exit();
}

$error = '';
$success = '';
$show_otp = false;
$temp_email = '';

// Check if we're in OTP mode from session
if (isset($_SESSION['otp_pending']) && $_SESSION['otp_pending'] === true) {
    $show_otp = true;
    $temp_email = $_SESSION['temp_email'] ?? '';
}

// Handle OTP Verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_otp'])) {
    $otp = trim($_POST['otp'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // ✅ FIX: Validate that OTP is exactly 6 digits before querying
    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = 'Please enter the complete 6-digit OTP code.';
        $show_otp = true;
        $temp_email = $email;
        $_SESSION['otp_pending'] = true;
        $_SESSION['temp_email'] = $email;
    } else {
        // Check OTP in database
        $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? AND otp_code = ? AND otp_expires > NOW()");
        mysqli_stmt_bind_param($stmt, "ss", $email, $otp);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if ($user) {
            // Update user status to Active
            $update = mysqli_prepare($conn, "UPDATE users SET status = 'Active', otp_code = NULL, email_verified_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($update, "i", $user['id']);
            
            if (mysqli_stmt_execute($update)) {
                // Send welcome email
                sendWelcome($email, $user['fullname']);
                
                // Clear OTP session
                unset($_SESSION['otp_pending']);
                unset($_SESSION['temp_email']);
                
                // ✅ FIX: Set session BEFORE redirect
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['fullname'];
                
                // ✅ FIX: Use PHP header redirect instead of JS redirect
                header('Location: ../pages/dashboard.php');
                exit();
            }
        } else {
            $error = 'Invalid or expired OTP code.';
            $show_otp = true;
            $temp_email = $email;
            $_SESSION['otp_pending'] = true;
            $_SESSION['temp_email'] = $email;
        }
    }
}

// Handle Resend OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resend_otp'])) {
    $email = $_POST['email'] ?? '';
    
    // Generate new OTP
    $otp = rand(100000, 999999);
    
    $update = mysqli_prepare($conn, "UPDATE users SET otp_code = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE email = ?");
    mysqli_stmt_bind_param($update, "ss", $otp, $email);
    
    if (mysqli_stmt_execute($update)) {
        // Get user name
        $stmt_name = mysqli_prepare($conn, "SELECT fullname FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt_name, "s", $email);
        mysqli_stmt_execute($stmt_name);
        $res_name = mysqli_stmt_get_result($stmt_name);
        $user = mysqli_fetch_assoc($res_name);
        
        // Send OTP
        if (sendOTP($email, $user['fullname'], $otp)) {
            $success = 'New OTP sent to your email.';
        } else {
            $error = 'Failed to send OTP. Please try again.';
        }
    }
    $show_otp = true;
    $temp_email = $email;
    $_SESSION['otp_pending'] = true;
    $_SESSION['temp_email'] = $email;
}

// Handle Initial Registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['verify_otp']) && !isset($_POST['resend_otp']) && isset($_POST['fullname']) && isset($_POST['email'])) {
    
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    
    $password = isset($_POST['password'])         ? $_POST['password']         : '';
    $confirm  = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Split fullname into first and last name
    $name_parts = explode(' ', $fullname, 2);
    $first_name = $name_parts[0];
    $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';
    
    if (empty($password) || empty($confirm)) {
        $error = 'Password fields are required!';
    } else if ($password != $confirm) {
        $error = 'Passwords do not match!';
    } else {
        // Check if email already exists
        $check = mysqli_query($conn, "SELECT id FROM users WHERE email='$email'");
        if (mysqli_num_rows($check) > 0) {
            $error = 'Email already registered!';
        } else {
            // Generate OTP and hash password
            $otp       = rand(100000, 999999);
            $hashed    = password_hash($password, PASSWORD_DEFAULT);
            $user_code = 'USR' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // ✅ Insert into users table ONLY
                // Patient record is NOT created here.
                // It will only be created when the clinic confirms
                // the appointment and the user visits physically.
                $sql = "INSERT INTO users 
                            (user_code, fullname, first_name, last_name, email, password, role, otp_code, otp_expires, status) 
                        VALUES 
                            (?, ?, ?, ?, ?, ?, 'Patient', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'Pending')";
                
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "sssssss",
                    $user_code,
                    $fullname,
                    $first_name,
                    $last_name,
                    $email,
                    $hashed,
                    $otp
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    // ✅ Commit — users table lang, walang patients insert
                    mysqli_commit($conn);
                    
                    // Send OTP email
                    if (sendOTP($email, $fullname, $otp)) {
                        $_SESSION['otp_pending'] = true;
                        $_SESSION['temp_email']  = $email;
                        
                        $show_otp   = true;
                        $temp_email = $email;
                        $success    = 'Registration successful! Please check your email for OTP.';
                    } else {
                        // OTP send failed — rollback the user insert
                        // so the user can try registering again
                        mysqli_rollback($conn);
                        $error = 'Failed to send OTP email. Please try again.';
                    }
                } else {
                    throw new Exception('Failed to create account: ' . mysqli_error($conn));
                }
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $show_otp ? 'Verify OTP - Eyecore' : 'Create Account - Eyecore'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            background: var(--bg-primary);
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 999px;
            
            --danger: #FF4444;
            --warning: #FF8C42;
            --success: #00B761;
            --info: #17A2B8;
            
            --weak: #FF4444;
            --medium: #FF8C42;
            --strong: #00B761;
            --very-strong: #4CAF50;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            --primary-gradient: linear-gradient(135deg, #00E676 0%, #00C853 100%);
            
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --card-bg: #242424;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #6B7280;
            --border-color: #2D2D2D;
            --border-light: #262626;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.2);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.4);
            
            --weak: #FF6B6B;
            --medium: #FFB347;
            --strong: #7AC97A;
            --very-strong: #6FCF97;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            transition: background-color 0.3s;
        }

        .auth-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }

        .auth-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 32px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary);
        }

        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            text-decoration: none;
        }

        .auth-logo i {
            font-size: 32px;
            color: var(--primary);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .auth-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .auth-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .email-display {
            background: var(--bg-primary);
            padding: 12px;
            border-radius: var(--radius-md);
            margin-top: 15px;
            font-weight: 500;
            color: var(--primary);
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .email-display i {
            color: var(--primary);
        }

        .alert-message {
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        .alert-message.error {
            background: rgba(255,68,68,0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }

        .alert-message.success {
            background: rgba(0,183,97,0.1);
            border-left: 4px solid var(--success);
            color: var(--success);
        }

        .alert-message i {
            font-size: 18px;
        }

        .alert-message .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            color: currentColor;
            cursor: pointer;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .alert-message.success a {
            color: var(--success);
            font-weight: 700;
            text-decoration: underline;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-group label i {
            color: var(--primary);
            font-size: 14px;
        }

        .input-wrapper {
            position: relative;
            width: 100%;
        }

        .input-wrapper input {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            color: var(--text-primary);
            transition: all 0.2s;
            padding-right: 45px;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .toggle-password {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            font-size: 16px;
        }

        .toggle-password:hover {
            background: var(--bg-primary);
            color: var(--primary);
        }

        .password-strength {
            margin-top: 10px;
        }

        .strength-bar {
            height: 6px;
            width: 0;
            border-radius: 3px;
            transition: all 0.3s;
            background: var(--border-color);
        }

        .strength-bar.weak { width: 20%; background: var(--weak); }
        .strength-bar.medium { width: 40%; background: var(--medium); }
        .strength-bar.strong { width: 60%; background: var(--strong); }
        .strength-bar.very-strong { width: 80%; background: var(--very-strong); }

        .password-requirements {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 15px;
            margin: 15px 0 0;
            border: 1px solid var(--border-light);
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            color: var(--text-secondary);
            font-size: 12px;
            transition: all 0.2s;
        }

        .requirement i {
            width: 16px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .requirement.met { color: var(--success); }
        .requirement.met i { color: var(--success); }

        .match-indicator {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
        }

        .match-indicator i { font-size: 16px; }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 8px 16px -8px var(--primary);
            margin-top: 10px;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -8px var(--primary);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn-primary i { font-size: 16px; }

        .btn-outline {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            margin-top: 10px;
        }

        .btn-outline:hover:not(:disabled) { background: var(--primary-light); }
        .btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }

        .otp-inputs {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 20px 0;
        }

        .otp-input {
            width: 55px;
            height: 65px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .timer {
            text-align: center;
            color: var(--text-secondary);
            font-size: 14px;
            margin: 15px 0;
        }

        .timer i { color: var(--primary); margin-right: 5px; }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border-light);
            color: var(--text-secondary);
            font-size: 13px;
        }

        .auth-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            border-radius: var(--radius-full);
        }

        .auth-link:hover { background: var(--primary-light); }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            body { padding: 12px; background: var(--bg-secondary); }
            .auth-card { padding: 28px 24px; box-shadow: var(--shadow-md); }
            .auth-logo { font-size: 26px; margin-bottom: 16px; }
            .auth-header h1 { font-size: 22px; }
            .otp-input { width: 45px; height: 55px; font-size: 20px; }
        }

        @media (max-width: 480px) {
            body { padding: 0; background: var(--bg-secondary); min-height: 100vh; display: flex; align-items: flex-start; }
            .auth-container { max-width: 100%; min-height: 100vh; display: flex; align-items: center; }
            .auth-card { padding: 32px 20px; border-radius: 0; min-height: 100vh; width: 100%; display: flex; flex-direction: column; justify-content: center; box-shadow: none; border: none; }
            .auth-card::before { display: none; }
            .otp-inputs { gap: 5px; }
            .otp-input { width: 40px; height: 50px; font-size: 18px; }
            .form-group { margin-bottom: 16px; }
            .input-wrapper input { padding: 12px 14px; padding-right: 40px; font-size: 13px; }
        }

        @media (max-width: 360px) {
            .auth-card { padding: 24px 16px; }
            .otp-input { width: 35px; height: 45px; font-size: 16px; }
            .input-wrapper input { padding: 10px 12px; padding-right: 36px; font-size: 12px; }
        }

        @media (max-height: 600px) and (orientation: landscape) {
            body { align-items: flex-start; padding: 20px; }
            .auth-card { padding: 24px; }
            .password-requirements { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .otp-inputs { margin: 10px 0; }
            .otp-input { width: 40px; height: 45px; }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <a href="../index.php" class="auth-logo">
                <i class="fas fa-eye"></i>
                <span>eyecore</span>
            </a>
            
            <?php if (!$show_otp): ?>
                <!-- REGISTRATION FORM -->
                <div class="auth-header">
                    <h1>Create Account ✨</h1>
                    <p>Join Eyecore and start your eye care journey</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert-message error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                        <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success && !$show_otp): ?>
                    <div class="alert-message success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                        <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="registerForm">
                    <div class="form-group">
                        <label for="fullname"><i class="fas fa-user"></i> Full Name</label>
                        <div class="input-wrapper">
                            <input type="text" name="fullname" id="fullname" placeholder="Enter your Full Name"
                                value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>"
                                required autocomplete="name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" id="email" placeholder="Enter your Email Address"
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                required autocomplete="email">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="password" id="password" placeholder="Create a strong password" required autocomplete="new-password">
                            <button type="button" class="toggle-password" onclick="togglePassword('password')">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div class="password-requirements">
                            <div class="requirement" id="reqLength"><i class="far fa-circle"></i> At least 8 characters</div>
                            <div class="requirement" id="reqUppercase"><i class="far fa-circle"></i> At least 1 uppercase letter</div>
                            <div class="requirement" id="reqLowercase"><i class="far fa-circle"></i> At least 1 lowercase letter</div>
                            <div class="requirement" id="reqNumber"><i class="far fa-circle"></i> At least 1 number</div>
                            <div class="requirement" id="reqSpecial"><i class="far fa-circle"></i> At least 1 special character (!@#$%^&*)</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter password" required autocomplete="new-password">
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                                <i class="far fa-eye"></i>
                            </button>
                            <span class="match-indicator" id="matchIndicator"></span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-primary" id="submitBtn" disabled>
                        <i class="fas fa-user-plus"></i>
                        Create Account
                    </button>
                </form>
                
                <div class="auth-footer">
                    Already have an account?
                    <a href="user_login.php" class="auth-link">Sign in <i class="fas fa-arrow-right"></i></a>
                </div>
                
            <?php else: ?>
                <!-- OTP VERIFICATION FORM -->
                <div class="auth-header">
                    <h1>Verify Your Email ✉️</h1>
                    <p>We've sent a 6-digit code to</p>
                    <div class="email-display">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($temp_email); ?>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert-message error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                        <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success && $show_otp): ?>
                    <div class="alert-message success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                        <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="otpForm">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($temp_email); ?>">
                    
                    <div class="otp-inputs">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus>
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    </div>
                    <input type="hidden" name="otp" id="otp_hidden">
                    
                    <div class="timer" id="timer"><i class="fas fa-clock"></i> 05:00</div>
                    
                    <button type="submit" name="verify_otp" id="verifyBtn" class="btn-primary">
                        <i class="fas fa-check-circle"></i>
                        Verify Account
                    </button>
                    
                    <button type="submit" name="resend_otp" class="btn-outline" id="resendBtn" disabled>
                        <i class="fas fa-redo-alt"></i>
                        Resend OTP
                    </button>
                </form>
                
                <div class="auth-footer">
                    Wrong email?
                    <a href="user_register.php" class="auth-link">Register again <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = event.currentTarget.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
        
        <?php if (!$show_otp): ?>
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        const strengthBar = document.getElementById('strengthBar');
        const matchIndicator = document.getElementById('matchIndicator');
        
        const reqs = {
            length: document.getElementById('reqLength'),
            uppercase: document.getElementById('reqUppercase'),
            lowercase: document.getElementById('reqLowercase'),
            number: document.getElementById('reqNumber'),
            special: document.getElementById('reqSpecial')
        };
        
        function checkPasswordStrength() {
            const pass = password.value;
            let strength = 0;
            
            if (pass.length >= 8) { strength++; updateRequirement(reqs.length, true); } else { updateRequirement(reqs.length, false); }
            if (/[A-Z]/.test(pass)) { strength++; updateRequirement(reqs.uppercase, true); } else { updateRequirement(reqs.uppercase, false); }
            if (/[a-z]/.test(pass)) { strength++; updateRequirement(reqs.lowercase, true); } else { updateRequirement(reqs.lowercase, false); }
            if (/[0-9]/.test(pass)) { strength++; updateRequirement(reqs.number, true); } else { updateRequirement(reqs.number, false); }
            if (/[!@#$%^&*(),.?":{}|<>]/.test(pass)) { strength++; updateRequirement(reqs.special, true); } else { updateRequirement(reqs.special, false); }
            
            strengthBar.className = 'strength-bar';
            if (pass.length === 0) { strengthBar.style.width = '0'; }
            else if (strength <= 2) { strengthBar.classList.add('weak'); }
            else if (strength <= 3) { strengthBar.classList.add('medium'); }
            else if (strength <= 4) { strengthBar.classList.add('strong'); }
            else { strengthBar.classList.add('very-strong'); }
            
            return strength >= 5;
        }
        
        function updateRequirement(element, isMet) {
            const icon = element.querySelector('i');
            if (isMet) {
                element.classList.add('met');
                icon.className = 'fas fa-check-circle';
            } else {
                element.classList.remove('met');
                icon.className = 'far fa-circle';
            }
        }
        
        function checkPasswordMatch() {
            if (confirmPassword.value.length === 0) { matchIndicator.innerHTML = ''; return false; }
            if (password.value === confirmPassword.value) {
                matchIndicator.innerHTML = '<i class="fas fa-check-circle" style="color: #00B761;"></i>';
                return true;
            } else {
                matchIndicator.innerHTML = '<i class="fas fa-times-circle" style="color: #FF4444;"></i>';
                return false;
            }
        }
        
        function canSubmit() {
            const isStrong = checkPasswordStrength();
            const isMatch = checkPasswordMatch();
            const hasName = document.getElementById('fullname').value.trim().length > 0;
            const hasEmail = document.getElementById('email').value.trim().length > 0;
            const isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('email').value);
            submitBtn.disabled = !(isStrong && isMatch && hasName && hasEmail && isValidEmail);
        }
        
        password.addEventListener('input', () => { checkPasswordStrength(); checkPasswordMatch(); canSubmit(); });
        confirmPassword.addEventListener('input', () => { checkPasswordMatch(); canSubmit(); });
        document.getElementById('fullname').addEventListener('input', canSubmit);
        document.getElementById('email').addEventListener('input', canSubmit);
        <?php endif; ?>
        
        <?php if ($show_otp): ?>
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpHidden = document.getElementById('otp_hidden');
        let timeLeft = 300;
        
        function updateOTPHidden() {
            let otp = '';
            otpInputs.forEach(input => otp += input.value);
            otpHidden.value = otp;
            return otp;
        }
        
        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            document.getElementById('timer').innerHTML = 
                `<i class="fas fa-clock"></i> ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                document.getElementById('timer').innerHTML = '<i class="fas fa-clock"></i> Expired';
                document.getElementById('resendBtn').disabled = false;
            }
            timeLeft--;
        }
        
        const timerInterval = setInterval(updateTimer, 1000);
        
        otpInputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
                
                if (e.target.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                
                // ✅ FIX: Always update hidden field on every input
                const otp = updateOTPHidden();
                
                // ✅ FIX: Auto-submit only when all 6 digits are filled
                if (otp.length === 6) {
                    setTimeout(() => {
                        document.getElementById('verifyBtn').click();
                    }, 150);
                }
            });
            
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
            
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '');
                
                for (let i = 0; i < Math.min(pastedData.length, otpInputs.length); i++) {
                    otpInputs[i].value = pastedData[i];
                }
                
                const nextEmpty = Array.from(otpInputs).findIndex(input => !input.value);
                if (nextEmpty !== -1) { otpInputs[nextEmpty].focus(); }
                else { otpInputs[otpInputs.length - 1].focus(); }
                
                // ✅ FIX: Update hidden field after paste
                const otp = updateOTPHidden();
                if (otp.length === 6) {
                    setTimeout(() => {
                        document.getElementById('verifyBtn').click();
                    }, 150);
                }
            });
        });
        <?php endif; ?>

        setTimeout(() => {
            document.querySelectorAll('.alert-message').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>