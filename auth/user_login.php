<?php
session_name('eyecore_user');
session_start();
include '../includes/config.php';
include '../includes/theme.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Patient') {
    header('Location: ../pages/dashboard.php');
    exit();
}

$error = '';
$success = false;
$user_data = null;
$redirect_url = '../pages/dashboard.php'; // Default redirect

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    // Patient role lang ang pwede dito
    $sql = "SELECT * FROM users WHERE email='$email' AND role = 'Patient'";
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        // Block kung hindi pa verified
        if ($user['status'] === 'Pending') {
            $error = 'Account not yet verified. Please check your email for the OTP.';
        } elseif ($user['status'] === 'Archived') {
            $error = 'Account deactivated. Please contact the clinic.';
        } elseif (password_verify($password, $user['password'])) {
            
            // ===== UPDATE LAST LOGIN =====
            $update_last_login = "UPDATE users SET last_login = NOW() WHERE id = " . $user['id'];
            mysqli_query($conn, $update_last_login);
            
            // ===== ADD LOGIN HISTORY =====
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            
            // Detect device type
            $device_type = 'desktop';
            $device_name = 'Desktop/Laptop';
            
            if (preg_match('/(Mobile|Android|iPhone|iPad|iPod|Windows Phone)/i', $user_agent)) {
                $device_type = 'mobile';
                if (preg_match('/iPhone/i', $user_agent)) $device_name = 'iPhone';
                elseif (preg_match('/iPad/i', $user_agent)) $device_name = 'iPad';
                elseif (preg_match('/Android/i', $user_agent)) $device_name = 'Android Device';
                else $device_name = 'Mobile Device';
            } elseif (preg_match('/Macintosh|Mac OS X/i', $user_agent)) {
                $device_name = 'Mac';
            } elseif (preg_match('/Windows/i', $user_agent)) {
                $device_name = 'Windows PC';
            } elseif (preg_match('/Linux/i', $user_agent)) {
                $device_name = 'Linux PC';
            }
            
            // Get location from IP (optional - using free API or database)
            $location = '';
            // You can use a geolocation API here if you want
            // For now, we'll leave it empty or set to a default
            
            // Mark previous sessions as not current
            mysqli_query($conn, "UPDATE user_login_history SET is_current = 0 WHERE user_id = " . $user['id']);
            
            // Insert new login record
            $insert_history = "INSERT INTO user_login_history 
                (user_id, ip_address, device_type, device_name, location, is_current, login_time) 
                VALUES (
                    {$user['id']}, 
                    '$ip_address', 
                    '$device_type', 
                    '$device_name', 
                    '$location', 
                    1, 
                    NOW()
                )";
            mysqli_query($conn, $insert_history);
            
            // Clear any leftover admin session variables first
            unset($_SESSION['role']);
            unset($_SESSION['email']);
            unset($_SESSION['first_name']);
            unset($_SESSION['last_name']);
            unset($_SESSION['clinic_id']);
            unset($_SESSION['clinic_name']);
            unset($_SESSION['clinic_status']);
            unset($_SESSION['clinic_logo']);
            unset($_SESSION['doctor_id']);
            unset($_SESSION['employee_id']);
            unset($_SESSION['status']);

            // Store user data in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['fullname'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_login'] = date('Y-m-d H:i:s');

            if ($remember) {
                setcookie('user_email', $email, time() + (86400 * 30), '/');
            }

            // ============================================
            // CHECK FOR REDIRECT AFTER LOGIN - ADDED
            // ============================================
            // Determine where to redirect
if (isset($_SESSION['redirect_after_login'])) {
    $redirect_url = $_SESSION['redirect_after_login'];
    unset($_SESSION['redirect_after_login']);
    // Make sure path is correct
    if (strpos($redirect_url, 'pages/') !== 0 && strpos($redirect_url, '../') !== 0) {
        $redirect_url = '../' . $redirect_url;
    } elseif (strpos($redirect_url, 'pages/') === 0) {
        $redirect_url = '../' . $redirect_url;
    }
} else {
    $redirect_url = '../pages/dashboard.php';
}

            // Check if login alerts are enabled (YOUR ORIGINAL CODE)
            $settings_query = mysqli_query($conn, "SELECT login_alerts FROM user_settings WHERE user_id = " . $user['id']);
            if (mysqli_num_rows($settings_query) > 0) {
                $settings = mysqli_fetch_assoc($settings_query);
                if ($settings['login_alerts']) {
                    // Send login alert email (optional)
                    // sendLoginAlertEmail($user['email'], $user['fullname'], $ip_address, $device_name);
                    
                    // Add notification
                    addNotification(
                        $user['id'], 
                        'system', 
                        'New Login Detected', 
                        "You logged in from $device_name using $device_type device at " . date('h:i A'), 
                        'user_settings.php'
                    );
                }
            }

            // Store user data for welcome message
            $success = true;
            $user_data = [
                'fullname' => $user['fullname']
            ];
        } else {
            $error = 'Incorrect password!';
        }
    } else {
        $error = 'Email not found or unauthorized access.';
    }
}

$remembered_email = isset($_COOKIE['user_email']) ? $_COOKIE['user_email'] : '';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Login - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* ===== RESET AND BASE STYLES ===== */
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
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            transition: background-color 0.3s;
        }

        /* ===== AUTH CONTAINER ===== */
        .auth-container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }

        /* ===== AUTH CARD ===== */
        .auth-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 32px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        /* Green accent line */
        .auth-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary);
        }

        /* ===== LOGO ===== */
        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 24px;
            text-decoration: none;
        }

        .auth-logo i {
            font-size: 32px;
            color: var(--primary);
        }

        /* ===== HEADER ===== */
        .auth-header {
            text-align: center;
            margin-bottom: 28px;
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

        /* ===== ALERT ===== */
        .alert-message {
            background: rgba(255,68,68,0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
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

        /* ===== FORM GROUPS ===== */
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

        /* ===== INPUT WRAPPER ===== */
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
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        /* ===== PASSWORD TOGGLE ===== */
        .toggle-password {
            position: absolute;
            right: 12px;
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

        /* ===== FORM OPTIONS ===== */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 16px 0 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        /* Custom checkbox */
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--text-secondary);
            font-size: 13px;
            position: relative;
            padding-left: 26px;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 18px;
            width: 18px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 5px;
            transition: all 0.2s;
        }

        .checkbox-container:hover .checkmark {
            border-color: var(--primary);
        }

        .checkbox-container input:checked ~ .checkmark {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 5px;
            top: 2px;
            width: 4px;
            height: 8px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .checkbox-container input:checked ~ .checkmark:after {
            display: block;
        }

        /* Forgot password link */
        .forgot-link {
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: var(--radius-full);
        }

        .forgot-link:hover {
            background: var(--primary-light);
        }

        /* ===== BUTTON ===== */
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
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -8px var(--primary);
        }

        .btn-primary.loading {
            opacity: 0.8;
            cursor: not-allowed;
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* ===== AUTH FOOTER ===== */
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

        .auth-link:hover {
            background: var(--primary-light);
        }

        /* ===== ANIMATIONS ===== */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 768px) {
            body {
                padding: 12px;
                background: var(--bg-secondary);
            }
            
            .auth-card {
                padding: 28px 24px;
                box-shadow: var(--shadow-md);
            }
            
            .auth-logo {
                font-size: 26px;
                margin-bottom: 20px;
            }
            
            .auth-header h1 {
                font-size: 22px;
            }
            
            .btn-primary {
                padding: 12px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 0;
                background: var(--bg-secondary);
                min-height: 100vh;
                display: flex;
                align-items: flex-start;
            }
            
            .auth-container {
                max-width: 100%;
                min-height: 100vh;
                display: flex;
                align-items: center;
            }
            
            .auth-card {
                padding: 32px 20px;
                border-radius: 0;
                min-height: 100vh;
                width: 100%;
                display: flex;
                flex-direction: column;
                justify-content: center;
                box-shadow: none;
                border: none;
            }
            
            .auth-card::before {
                display: none;
            }
            
            .auth-logo {
                font-size: 24px;
                margin-bottom: 16px;
            }
            
            .auth-logo i {
                font-size: 28px;
            }
            
            .auth-header {
                margin-bottom: 24px;
            }
            
            .auth-header h1 {
                font-size: 20px;
            }
            
            .auth-header p {
                font-size: 13px;
            }
            
            .form-group {
                margin-bottom: 16px;
            }
            
            .form-group label {
                font-size: 12px;
            }
            
            .input-wrapper input {
                padding: 12px 14px;
                font-size: 13px;
            }
            
            .form-options {
                margin: 12px 0 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .checkbox-container {
                font-size: 12px;
                padding-left: 24px;
            }
            
            .checkmark {
                height: 16px;
                width: 16px;
            }
            
            .checkmark:after {
                left: 4px;
                top: 2px;
            }
            
            .forgot-link {
                font-size: 12px;
                padding: 4px 8px;
            }
            
            .btn-primary {
                padding: 12px;
                font-size: 14px;
            }
            
            .auth-footer {
                margin-top: 20px;
                padding-top: 12px;
                font-size: 12px;
            }
            
            .auth-link {
                padding: 4px 8px;
            }
        }

        @media (max-width: 360px) {
            .auth-card {
                padding: 24px 16px;
            }
            
            .auth-logo {
                font-size: 22px;
            }
            
            .auth-logo i {
                font-size: 26px;
            }
            
            .auth-header h1 {
                font-size: 18px;
            }
            
            .input-wrapper input {
                padding: 10px 12px;
            }
            
            .btn-primary {
                padding: 10px;
                font-size: 13px;
            }
            
        }

        /* Landscape mode */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding: 20px;
            }
            
            .auth-card {
                padding: 24px;
            }
            
            .auth-logo {
                font-size: 22px;
                margin-bottom: 12px;
            }
            
            .auth-header {
                margin-bottom: 16px;
            }
            
            .form-group {
                margin-bottom: 12px;
            }
            
            .auth-footer {
                margin-top: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <!-- Logo -->
            <a href="../index.php" class="auth-logo">
                <i class="fas fa-eye"></i>
                <span>eyecore</span>
            </a>
            
            <!-- Header -->
            <div class="auth-header">
                <h1>Welcome Back! 👋</h1>
                <p>Enter your credentials to continue</p>
            </div>
            
            <!-- Error Message -->
            <?php if ($error && !$success): ?>
                <div class="alert-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                    <button class="close-alert" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Login Form -->
            <form method="POST" id="loginForm">
                <!-- Email field -->
                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="email" 
                            name="email" 
                            id="email"
                            value="<?php echo htmlspecialchars($remembered_email); ?>" 
                            placeholder="Enter your Email Address" 
                            required
                        >
                    </div>
                </div>
                
                <!-- Password field -->
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            placeholder="Enter your password" 
                            required
                        >
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Form Options -->
                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember" <?php echo $remembered_email ? 'checked' : ''; ?>>
                        <span class="checkmark"></span>
                        <span>Remember me</span>
                    </label>
                    
                    <a href="forgot-password.php" class="forgot-link">
                        <i class="fas fa-question-circle"></i>
                        Forgot Password?
                    </a>
                </div>
                
                <!-- Login Button -->
                <button type="submit" class="btn-primary" id="loginBtn">
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            
            <!-- Register Link -->
            <div class="auth-footer">
                Don't have an account?
                <a href="user_register.php" class="auth-link">
                    Sign up for free
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Pass PHP data to JavaScript
        <?php if ($success && $user_data): ?>
            // Get redirect URL
            var redirectUrl = '<?php echo $redirect_url; ?>';
            
            Swal.fire({
                title: 'Welcome, <?php echo addslashes($user_data['fullname']); ?>! 👋',
                text: 'Successfully logged in',
                icon: 'success',
                iconColor: '#00B761',
                confirmButtonText: 'Continue',
                confirmButtonColor: '#00B761',
                allowOutsideClick: false,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: true
            }).then((result) => {
                window.location.href = redirectUrl;
            });
        <?php endif; ?>
        
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.toggle-password');
            const icon = toggleBtn.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Loading state on form submit
        document.getElementById('loginForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
            btn.querySelector('span').textContent = 'Signing in...';
            btn.querySelector('i').className = 'fas fa-spinner';
        });
        
        // Auto close alert after 5 seconds
        setTimeout(() => {
            const alert = document.querySelector('.alert-message');
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s';
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);
    </script>
</body>
</html>