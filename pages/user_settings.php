<?php

include '../includes/config.php';
include '../includes/theme.php';

// Import PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ FIXED: Correct path using __DIR__
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

require '../vendor/autoload.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user info
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get user avatar and created_at
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// ===== NAVBAR VARIABLES =====
$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?? 0;

$pending_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending_result = mysqli_fetch_assoc($pending_count_query);
$pending = $pending_result['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?? 0;

$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

// ===== GET USER SETTINGS =====
$settings_query = mysqli_query($conn, "SELECT * FROM user_settings WHERE user_id = $user_id");
if (mysqli_num_rows($settings_query) > 0) {
    $settings = mysqli_fetch_assoc($settings_query);
} else {
    // Create default settings
    mysqli_query($conn, "INSERT INTO user_settings (user_id) VALUES ($user_id)");
    $settings = [
        'email_notifications' => 1,
        'sms_notifications' => 0,
        'login_alerts' => 1,
        'marketing_emails' => 1,
        'last_password_change' => $user['created_at']
    ];
}

// ===== GET LOGIN HISTORY =====
$login_history = mysqli_query($conn, "
    SELECT * FROM user_login_history 
    WHERE user_id = $user_id 
    ORDER BY login_time DESC 
    LIMIT 5
");

// ===== GET CONNECTED ACCOUNTS =====
$connected_accounts = mysqli_query($conn, "
    SELECT * FROM user_connected_accounts 
    WHERE user_id = $user_id
");

function sendOTP($email, $name, $otp, $type = 'password') {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('noreply@eyecore.com', 'Eyecore');
        $mail->addAddress($email, $name);
        
        $mail->isHTML(true);
        
        $mail->Subject = 'Password Change OTP - Eyecore';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <h1 style='color: #00B761;'>Eyecore</h1>
                </div>
                <h2 style='color: #333;'>Password Change Request</h2>
                <p style='color: #666;'>Hi <strong>$name</strong>,</p>
                <p style='color: #666;'>We received a request to change your password. Use the OTP code below to complete the process:</p>
                <div style='background: linear-gradient(135deg, #00B761 0%, #00A86B 100%); color: white; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0;'>
                    <h1 style='font-size: 48px; letter-spacing: 10px; margin: 0;'>$otp</h1>
                </div>
                <p style='color: #666;'>This code will expire in <strong>10 minutes</strong>.</p>
                <p style='color: #666;'>If you didn't request this, please ignore this email or contact support.</p>
                <hr style='border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;'>
                <p style='color: #999; font-size: 12px; text-align: center;'>This is an automated message, please do not reply.</p>
            </div>
        ";
        
        $mail->AltBody = "Your OTP code is: $otp. This code will expire in 10 minutes.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

// ===== HANDLE FORM SUBMISSIONS =====
$success_message = '';
$error_message = '';
$show_otp_modal = false;

// Step 1: Initial password change request
if (isset($_POST['request_password_change'])) {
    $current_password = mysqli_real_escape_string($conn, $_POST['current_password']);
    $new_password = mysqli_real_escape_string($conn, $_POST['new_password']);
    $confirm_password = mysqli_real_escape_string($conn, $_POST['confirm_password']);
    
    $uppercase = preg_match('@[A-Z]@', $new_password);
    $lowercase = preg_match('@[a-z]@', $new_password);
    $number    = preg_match('@[0-9]@', $new_password);
    $special   = preg_match('@[^\w]@', $new_password);
    
    if (password_verify($current_password, $user['password'])) {
        if ($new_password === $confirm_password) {
            $errors = [];
            if (!$uppercase) $errors[] = 'at least one uppercase letter';
            if (!$lowercase) $errors[] = 'at least one lowercase letter';
            if (!$number) $errors[] = 'at least one number';
            if (!$special) $errors[] = 'at least one special character';
            if (strlen($new_password) < 8) $errors[] = 'at least 8 characters';
            
            if (empty($errors)) {
                $otp = generateOTP();
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $temp_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                
                mysqli_query($conn, "UPDATE users SET 
                    otp_code = '$otp', 
                    otp_expires = '$expires',
                    temp_password = '$temp_hashed' 
                    WHERE id = $user_id");
                
                if (sendOTP($user['email'], $user['fullname'], $otp, 'password')) {
                    $show_otp_modal = true;
                    $success_message = 'OTP code has been sent to your email.';
                } else {
                    $error_message = 'Failed to send OTP email. Please try again.';
                }
            } else {
                $error_message = 'Password must contain: ' . implode(', ', $errors);
            }
        } else {
            $error_message = 'New passwords do not match!';
        }
    } else {
        $error_message = 'Current password is incorrect!';
    }
}

// Step 2: Verify OTP and change password
if (isset($_POST['verify_otp'])) {
    $entered_otp = mysqli_real_escape_string($conn, $_POST['otp_code']);
    
    $otp_check = mysqli_query($conn, "SELECT otp_code, otp_expires, temp_password FROM users WHERE id = $user_id");
    $otp_data = mysqli_fetch_assoc($otp_check);
    
    if ($otp_data['otp_code'] && $otp_data['otp_code'] === $entered_otp) {
        if (strtotime($otp_data['otp_expires']) > time()) {
            $new_hashed = $otp_data['temp_password'];
            mysqli_query($conn, "UPDATE users SET 
                password = '$new_hashed',
                otp_code = NULL,
                otp_expires = NULL,
                temp_password = NULL 
                WHERE id = $user_id");
            
            // Update user_settings with last password change
            mysqli_query($conn, "UPDATE user_settings SET last_password_change = NOW() WHERE user_id = $user_id");
            
            addNotification($user_id, 'system', 'Password Changed', 'Your password was successfully changed.', 'user_settings.php');
            
            $success_message = 'Password changed successfully!';
            $show_otp_modal = false;
        } else {
            $error_message = 'OTP code has expired. Please request again.';
        }
    } else {
        $error_message = 'Invalid OTP code!';
    }
}

// Resend OTP
if (isset($_POST['resend_otp'])) {
    $new_otp = generateOTP();
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    mysqli_query($conn, "UPDATE users SET otp_code = '$new_otp', otp_expires = '$expires' WHERE id = $user_id");
    
    // ✅ FIXED: Use $new_otp variable, not $otp
    if (sendOTP($user['email'], $user['fullname'], $new_otp, 'password')) {
        $show_otp_modal = true;
        $success_message = 'OTP code has been sent to your email.';
        echo '<script>showOTPModal();</script>';
    } else {
        $error_message = 'Failed to send OTP email. Please try again.';
    }
}

// Update Notification Settings
if (isset($_POST['update_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $marketing_emails = isset($_POST['marketing_emails']) ? 1 : 0;
    
    mysqli_query($conn, "UPDATE user_settings SET 
        email_notifications = $email_notifications,
        sms_notifications = $sms_notifications,
        login_alerts = $login_alerts,
        marketing_emails = $marketing_emails
        WHERE user_id = $user_id");
    
    $success_message = 'Notification preferences updated!';
    
    // Refresh settings
    $settings_query = mysqli_query($conn, "SELECT * FROM user_settings WHERE user_id = $user_id");
    $settings = mysqli_fetch_assoc($settings_query);
}

// Unlink connected account
if (isset($_GET['unlink']) && isset($_GET['provider'])) {
    $provider = mysqli_real_escape_string($conn, $_GET['provider']);
    mysqli_query($conn, "DELETE FROM user_connected_accounts WHERE user_id = $user_id AND provider = '$provider'");
    header('Location: user_settings.php?unlinked=1');
    exit();
}

// Download user data
if (isset($_POST['download_data'])) {
    $user_data = [
        'profile' => $user,
        'appointments' => [],
        'favorites' => [],
        'notifications' => []
    ];
    
    $appts = mysqli_query($conn, "SELECT * FROM appointments WHERE user_id = $user_id");
    while($row = mysqli_fetch_assoc($appts)) {
        $user_data['appointments'][] = $row;
    }
    
    $favs = mysqli_query($conn, "SELECT * FROM favorites WHERE user_id = $user_id");
    while($row = mysqli_fetch_assoc($favs)) {
        $user_data['favorites'][] = $row;
    }
    
    $notifs = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id = $user_id");
    while($row = mysqli_fetch_assoc($notifs)) {
        $user_data['notifications'][] = $row;
    }
    
    $json = json_encode($user_data, JSON_PRETTY_PRINT);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="eyecore-data-' . date('Y-m-d') . '.json"');
    echo $json;
    exit();
}

// Handle account deletion
if (isset($_POST['delete_account'])) {
    $confirm = mysqli_real_escape_string($conn, $_POST['confirm_delete']);
    $password = mysqli_real_escape_string($conn, $_POST['confirm_password']);
    
    if ($confirm === 'DELETE') {
        if (password_verify($password, $user['password'])) {
            mysqli_query($conn, "DELETE FROM appointments WHERE user_id = $user_id");
            mysqli_query($conn, "DELETE FROM favorites WHERE user_id = $user_id");
            mysqli_query($conn, "DELETE FROM user_settings WHERE user_id = $user_id");
            mysqli_query($conn, "DELETE FROM user_login_history WHERE user_id = $user_id");
            mysqli_query($conn, "DELETE FROM users WHERE id = $user_id");
            
            session_destroy();
            header('Location: ../auth/user_register.php?deleted=1');
            exit();
        } else {
            $error_message = 'Incorrect password!';
        }
    } else {
        $error_message = 'Please type DELETE to confirm';
    }
}

// Set active nav
$active_nav = 'settings';

// Include navbar
include '../includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Settings - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            --password-weak: #FF4444;
            --password-medium: #FF8C42;
            --password-strong: #00B761;
            --toggle-bg: #ccc;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --card-bg: #242424;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #6B7280;
            --border-color: #2D2D2D;
            --border-light: #262626;
            --password-weak: #FF6B6B;
            --password-medium: #FFB347;
            --password-strong: #7AC97A;
            --toggle-bg: #555;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.3s, color 0.3s;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) {
            .main-content { padding: 30px 40px; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 20px 16px 100px; }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            font-size: 24px;
        }

        .settings-badge {
            background: var(--bg-secondary);
            padding: 12px 24px;
            border-radius: 30px;
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .settings-badge i {
            color: var(--primary);
        }

        .settings-badge span {
            font-weight: 600;
            color: var(--primary);
        }

        .settings-header {
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .settings-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .settings-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .settings-header p {
            opacity: 0.9;
            font-size: 16px;
            position: relative;
            z-index: 1;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        @media (max-width: 1024px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        .settings-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-light);
        }

        .card-header i {
            font-size: 24px;
            color: var(--primary);
            width: 40px;
            height: 40px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            flex: 1;
        }

        .card-header .badge {
            background: var(--primary-light);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 13px;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 6px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .password-field {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-field .form-control {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 5px;
        }

        .toggle-password:hover {
            color: var(--primary);
        }

        /* Password Strength */
        .password-strength {
            margin: 15px 0;
        }

        .strength-meter {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }

        .strength-bar {
            height: 6px;
            flex: 1;
            background: var(--border-color);
            border-radius: 3px;
        }

        .strength-bar.active.weak { background: var(--password-weak); }
        .strength-bar.active.medium { background: var(--password-medium); }
        .strength-bar.active.strong { background: var(--password-strong); }

        .strength-text {
            font-size: 13px;
            color: var(--text-muted);
        }

        .password-requirements {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 15px;
            margin: 15px 0;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .requirement-item.met i {
            color: var(--success);
        }

        .requirement-item .req-text.met {
            color: var(--success);
            text-decoration: line-through;
            opacity: 0.7;
        }

        #passwordMatch {
            font-size: 13px;
            margin-bottom: 15px;
            color: var(--danger);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Buttons */
        .btn-save {
            width: 100%;
            padding: 14px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Toggle Switch */
        .toggle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .toggle-item:last-child {
            border-bottom: none;
        }

        .toggle-info h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 3px;
        }

        .toggle-info p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .toggle-switch {
            position: relative;
            width: 50px;
            height: 26px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--toggle-bg);
            transition: .2s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background: var(--primary-gradient);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* Connected Accounts */
        .connected-account {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-bottom: 10px;
        }

        .account-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .account-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .account-icon.google { background: #DB4437; color: white; }
        .account-icon.facebook { background: #4267B2; color: white; }

        .account-details h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .account-details p {
            font-size: 11px;
            color: var(--text-muted);
        }

        .unlink-btn {
            padding: 6px 12px;
            background: transparent;
            border: 1px solid var(--danger);
            color: var(--danger);
            border-radius: var(--radius-full);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .unlink-btn:hover {
            background: var(--danger);
            color: white;
        }

        /* Login History */
        .login-history-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .login-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .login-icon {
            width: 35px;
            height: 35px;
            background: var(--bg-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .login-details h4 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .login-details p {
            font-size: 11px;
            color: var(--text-muted);
        }

        .login-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .login-badge {
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 600;
        }

        .login-badge.current {
            background: var(--primary-light);
            color: var(--primary);
        }

        /* Data Management */
        .data-actions {
            display: flex;
            gap: 12px;
            margin-top: 15px;
        }

        .btn-data {
            flex: 1;
            padding: 12px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .btn-data:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* Danger Zone */
        .danger-zone-card {
            background: var(--bg-secondary);
            border: 2px solid var(--danger);
            border-radius: var(--radius-lg);
            padding: 25px;
        }

        .danger-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .danger-header i {
            font-size: 24px;
            width: 40px;
            height: 40px;
            background: var(--danger);
            color: white;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .danger-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            flex: 1;
        }

        .danger-description {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .btn-danger {
            padding: 12px 25px;
            background: var(--danger);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger:hover {
            background: #ff6b6b;
            transform: translateY(-2px);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background: var(--primary-light);
            color: var(--primary-dark);
            border-left-color: var(--success);
        }

        .alert-error {
            background: rgba(255,68,68,0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            background: var(--bg-secondary);
            border-radius: 24px;
            width: 90%;
            max-width: 450px;
            transform: scale(0.8) translateY(20px);
            transition: all 0.3s;
            overflow: hidden;
        }

        .modal-overlay.show .modal-container {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            padding: 20px 24px;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header.error { background: var(--danger); }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            cursor: pointer;
        }

        .modal-body {
            padding: 30px 24px;
            text-align: center;
        }

        .otp-input {
            text-align: center;
            font-size: 32px;
            letter-spacing: 12px;
            font-weight: 700;
            padding: 16px;
            border: 2px solid var(--primary);
            border-radius: var(--radius-md);
            width: 100%;
            font-family: monospace;
        }

        .timer {
            font-size: 14px;
            color: var(--text-muted);
            margin: 20px 0;
            text-align: center;
        }

        .resend-link {
            text-align: center;
            margin-top: 20px;
        }

        .resend-link button {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: underline;
        }

        .delete-confirm-input {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            text-align: center;
            font-weight: 600;
            margin: 15px 0;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-light);
            display: flex;
            gap: 12px;
            background: var(--bg-primary);
        }

        .modal-btn {
            flex: 1;
            padding: 12px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }

        .modal-btn.cancel {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .modal-btn.primary {
            background: var(--primary-gradient);
            color: white;
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-notification {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 16px 24px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 12px;
            min-width: 320px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .modal-footer {
                flex-direction: column;
            }
            .modal-btn {
                width: 100%;
            }
            .data-actions {
                flex-direction: column;
            }
        }
        .modal-overlay.show {
    opacity: 1;
    visibility: visible;
}
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-cog"></i>
                Settings
            </h1>
            <div class="settings-badge">
                <i class="fas fa-user"></i> <span><?php echo htmlspecialchars($user['fullname']); ?></span>
            </div>
        </div>

        <div class="settings-header">
            <h1><i class="fas fa-sliders-h"></i> Account Settings</h1>
            <p>Manage your notification preferences and account security</p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="settings-grid">
            <!-- Change Password Card -->
            <div class="settings-card">
                <div class="card-header">
                    <i class="fas fa-lock"></i>
                    <h2>Change Password</h2>
                    <span class="badge">Last changed: <?php echo date('M d, Y', strtotime($settings['last_password_change'] ?? $user['created_at'])); ?></span>
                </div>
                
                <form method="POST" id="passwordForm">
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Current Password</label>
                        <div class="password-field">
                            <input type="password" name="current_password" id="currentPassword" class="form-control" placeholder="Enter current password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('currentPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> New Password</label>
                        <div class="password-field">
                            <input type="password" name="new_password" id="newPassword" class="form-control" placeholder="Enter new password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('newPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="password-strength">
                        <div class="strength-meter">
                            <div class="strength-bar" id="bar1"></div>
                            <div class="strength-bar" id="bar2"></div>
                            <div class="strength-bar" id="bar3"></div>
                            <div class="strength-bar" id="bar4"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Password strength: <span id="strengthLabel">Not entered</span></div>
                    </div>

                    <div class="password-requirements">
                        <div class="requirement-item" id="reqLength"><i class="fas fa-circle"></i> <span>At least 8 characters</span></div>
                        <div class="requirement-item" id="reqUppercase"><i class="fas fa-circle"></i> <span>At least one uppercase letter</span></div>
                        <div class="requirement-item" id="reqLowercase"><i class="fas fa-circle"></i> <span>At least one lowercase letter</span></div>
                        <div class="requirement-item" id="reqNumber"><i class="fas fa-circle"></i> <span>At least one number</span></div>
                        <div class="requirement-item" id="reqSpecial"><i class="fas fa-circle"></i> <span>At least one special character (!@#$%^&*)</span></div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                        <div class="password-field">
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirmPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div id="passwordMatch"><i class="fas fa-exclamation-circle"></i> Passwords do not match</div>

                    <button type="submit" name="request_password_change" class="btn-save">
                        <i class="fas fa-key"></i> Request Password Change
                    </button>
                </form>
            </div>

            <!-- Notification Settings Card -->
            <div class="settings-card">
                <div class="card-header">
                    <i class="fas fa-bell"></i>
                    <h2>Notifications</h2>
                </div>
                
                <form method="POST">
                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Email Notifications</h4>
                            <p>Receive appointment confirmations and reminders via email</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>SMS Notifications</h4>
                            <p>Get text messages for appointment reminders</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="sms_notifications" <?php echo $settings['sms_notifications'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Login Alerts</h4>
                            <p>Receive email when a new device logs into your account</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="login_alerts" <?php echo $settings['login_alerts'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Marketing Emails</h4>
                            <p>Receive promotions, news, and special offers</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="marketing_emails" <?php echo $settings['marketing_emails'] ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <button type="submit" name="update_notifications" class="btn-save">
                        <i class="fas fa-save"></i> Save Preferences
                    </button>
                </form>
            </div>

            <!-- Connected Accounts Card -->
            <?php if (mysqli_num_rows($connected_accounts) > 0): ?>
            <div class="settings-card">
                <div class="card-header">
                    <i class="fab fa-connectdevelop"></i>
                    <h2>Connected Accounts</h2>
                </div>
                
                <?php while($account = mysqli_fetch_assoc($connected_accounts)): ?>
                    <div class="connected-account">
                        <div class="account-info">
                            <div class="account-icon <?php echo $account['provider']; ?>">
                                <i class="fab fa-<?php echo $account['provider']; ?>"></i>
                            </div>
                            <div class="account-details">
                                <h4><?php echo ucfirst($account['provider']); ?></h4>
                                <p>Connected <?php echo date('M d, Y', strtotime($account['connected_at'])); ?></p>
                            </div>
                        </div>
                        <a href="?unlink=1&provider=<?php echo $account['provider']; ?>" class="unlink-btn" onclick="return confirm('Unlink this account?')">Unlink</a>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- Login History Card -->
            <div class="settings-card">
                <div class="card-header">
                    <i class="fas fa-history"></i>
                    <h2>Recent Login Activity</h2>
                </div>
                
                <div class="login-history">
                    <?php if (mysqli_num_rows($login_history) > 0): ?>
                        <?php while($login = mysqli_fetch_assoc($login_history)): ?>
                            <div class="login-history-item">
                                <div class="login-info">
                                    <div class="login-icon">
                                        <i class="fas fa-<?php echo $login['device_type'] == 'mobile' ? 'mobile-alt' : 'laptop'; ?>"></i>
                                    </div>
                                    <div class="login-details">
                                        <h4><?php echo htmlspecialchars($login['device_name'] ?? 'Unknown Device'); ?></h4>
                                        <p><?php echo htmlspecialchars($login['ip_address'] ?? 'Unknown IP'); ?></p>
                                    </div>
                                </div>
                                <div class="login-time">
                                    <?php echo date('M d, g:i A', strtotime($login['login_time'])); ?>
                                    <?php if($login['is_current']): ?>
                                        <div class="login-badge current">Current</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="login-history-item" style="justify-content: center; color: var(--text-muted);">
                            <p>No login history available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Data Management Card -->
        <div class="settings-card" style="margin-bottom: 25px;">
            <div class="card-header">
                <i class="fas fa-database"></i>
                <h2>Data Management</h2>
            </div>
            
            <p class="danger-description" style="margin-bottom: 15px;">
                Download a copy of your data or permanently delete your account.
            </p>
            
            <div class="data-actions">
                <form method="POST" style="flex: 1;">
                    <button type="submit" name="download_data" class="btn-data">
                        <i class="fas fa-download"></i> Download My Data
                    </button>
                </form>
                <button class="btn-data" onclick="showDeleteModal()" style="color: var(--danger);">
                    <i class="fas fa-trash-alt"></i> Delete Account
                </button>
            </div>
        </div>
    </div>

    <!-- OTP Verification Modal -->
   <div class="modal-overlay" id="otpModal" <?php echo $show_otp_modal ? 'style="opacity:1; visibility:visible;"' : ''; ?>>
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fas fa-shield-alt"></i> Verify OTP Code</h3>
                <button class="modal-close" onclick="closeOTPModal()"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div style="margin-bottom: 20px;">
                        <i class="fas fa-envelope" style="font-size: 48px; color: var(--primary);"></i>
                        <p style="margin-top: 10px;">Enter the 6-digit code sent to:</p>
                        <p style="font-weight: 600; color: var(--primary);"><?php echo substr($user['email'], 0, 3) . '****' . substr($user['email'], strpos($user['email'], '@')); ?></p>
                    </div>
                    <input type="text" name="otp_code" class="otp-input" maxlength="6" pattern="\d{6}" placeholder="000000" required>
                    <div class="timer"><i class="fas fa-hourglass-half"></i> Expires in: <strong id="timerDisplay">10:00</strong></div>
                    <div class="resend-link"><button type="submit" name="resend_otp"><i class="fas fa-redo-alt"></i> Resend Code</button></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn cancel" onclick="closeOTPModal()">Cancel</button>
                    <button type="submit" name="verify_otp" class="modal-btn primary">Verify & Change</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-container">
            <div class="modal-header error">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Account</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div>
                        <i class="fas fa-exclamation-circle" style="font-size: 48px; color: var(--danger);"></i>
                        <h4 style="margin: 15px 0;">This action cannot be undone!</h4>
                        <p>All your appointments, favorites, and personal data will be permanently removed.</p>
                    </div>
                    <p style="margin-top: 20px;">Type <strong style="color: var(--danger);">DELETE</strong> to confirm:</p>
                    <input type="text" name="confirm_delete" class="delete-confirm-input" placeholder="DELETE" required>
                    <div style="margin: 20px 0;">
                        <label style="display: block; margin-bottom: 8px;">Enter your password:</label>
                        <div class="password-field">
                            <input type="password" name="confirm_password" id="deletePassword" class="form-control" placeholder="Password" required>
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('deletePassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn cancel" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" name="delete_account" class="modal-btn danger">Delete Permanently</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('.toggle-password i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password Strength Checker
        const passwordInput = document.getElementById('newPassword');
        const confirmInput = document.getElementById('confirmPassword');
        const strengthBars = ['bar1', 'bar2', 'bar3', 'bar4'].map(id => document.getElementById(id));
        const strengthLabel = document.getElementById('strengthLabel');
        const strengthText = document.getElementById('strengthText');

        const reqElements = {
            length: document.getElementById('reqLength'),
            uppercase: document.getElementById('reqUppercase'),
            lowercase: document.getElementById('reqLowercase'),
            number: document.getElementById('reqNumber'),
            special: document.getElementById('reqSpecial')
        };

        function checkPasswordStrength() {
            const password = passwordInput.value;
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };

            Object.keys(checks).forEach(key => {
                const el = reqElements[key];
                const icon = el.querySelector('i');
                const text = el.querySelector('span');
                if (checks[key]) {
                    icon.className = 'fas fa-check-circle';
                    icon.style.color = '#28a745';
                    el.classList.add('met');
                    text.classList.add('met');
                } else {
                    icon.className = 'fas fa-circle';
                    icon.style.color = '';
                    el.classList.remove('met');
                    text.classList.remove('met');
                }
            });

            const metCount = Object.values(checks).filter(Boolean).length;
            strengthBars.forEach((bar, i) => {
                bar.classList.remove('active', 'weak', 'medium', 'strong');
                if (i < metCount) {
                    bar.classList.add('active');
                    if (metCount <= 2) bar.classList.add('weak');
                    else if (metCount <= 4) bar.classList.add('medium');
                    else bar.classList.add('strong');
                }
            });

            if (password.length === 0) {
                strengthLabel.textContent = 'Not entered';
                strengthText.className = 'strength-text';
            } else if (metCount <= 2) {
                strengthLabel.textContent = 'Weak';
                strengthText.className = 'strength-text weak';
            } else if (metCount <= 4) {
                strengthLabel.textContent = 'Medium';
                strengthText.className = 'strength-text medium';
            } else {
                strengthLabel.textContent = 'Strong';
                strengthText.className = 'strength-text strong';
            }
            checkPasswordMatch();
        }

        function checkPasswordMatch() {
            const matchEl = document.getElementById('passwordMatch');
            if (confirmInput.value.length > 0 && passwordInput.value !== confirmInput.value) {
                matchEl.style.display = 'flex';
            } else {
                matchEl.style.display = 'none';
            }
        }

        if (passwordInput) passwordInput.addEventListener('input', checkPasswordStrength);
        if (confirmInput) confirmInput.addEventListener('input', checkPasswordMatch);

        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };
            const metCount = Object.values(checks).filter(Boolean).length;
            if (metCount < 5) {
                e.preventDefault();
                showToast('Please meet all password requirements', 'error');
            } else if (password !== confirmInput.value) {
                e.preventDefault();
                showToast('Passwords do not match!', 'error');
            }
        });

// Modal Functions
function showOTPModal() {
    const modal = document.getElementById('otpModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        startTimer();
    }
}

function closeOTPModal() {
    const modal = document.getElementById('otpModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        if (timerInterval) clearInterval(timerInterval);
    }
}

let timeLeft = 600, timerInterval;

function startTimer() {
    if (timerInterval) clearInterval(timerInterval);
    timeLeft = 600;
    timerInterval = setInterval(() => {
        timeLeft--;
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        const timerDisplay = document.getElementById('timerDisplay');
        if (timerDisplay) timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            if (timerDisplay) timerDisplay.innerHTML = 'Expired';
        }
    }, 1000);
}

// Initialize modal on page load
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('otpModal');
    if (modal) {
        modal.style.display = 'none';
    }
    
    <?php if ($show_otp_modal): ?>
        setTimeout(function() {
            showOTPModal();
        }, 500);
    <?php endif; ?>
});

        function showDeleteModal() {
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }


        <?php if ($show_otp_modal): ?> startTimer(); <?php endif; ?>

        window.addEventListener('click', e => {
            if (e.target === document.getElementById('otpModal')) closeOTPModal();
            if (e.target === document.getElementById('deleteModal')) closeModal('deleteModal');
        });

        setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 5000);
    </script>
</body>
</html>