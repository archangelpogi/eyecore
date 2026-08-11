<?php
session_name('eyecore_user');
session_start();
include '../includes/config.php';
include '../includes/theme.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current step
$step = isset($_GET['step']) ? $_GET['step'] : 'request';

// If coming from request step with email in URL, store it
if (isset($_GET['email']) && !empty($_GET['email'])) {
    $_SESSION['reset_email'] = $_GET['email'];
}

$email = $_SESSION['reset_email'] ?? '';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Forgot Password - Eyecore</title>
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
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            --radius-md: 16px;
            --radius-lg: 24px;
            --danger: #FF4444;
            --success: #00B761;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --border-color: #2D2D2D;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .auth-container {
            width: 100%;
            max-width: 450px;
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
            margin-bottom: 20px;
        }

        .auth-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .auth-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .info-message {
            background: var(--primary-light);
            border-left: 4px solid var(--primary);
            padding: 16px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .info-message i {
            color: var(--primary);
            font-size: 18px;
        }

        .alert-message {
            padding: 16px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        }

        .alert-message.success {
            background: rgba(0,183,97,0.1);
            border-left: 4px solid var(--success);
            color: var(--success);
        }

        .alert-message.error {
            background: rgba(255,68,68,0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }

        .alert-message .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            color: currentColor;
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 24px;
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

        .otp-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .otp-group .input-wrapper {
            flex: 1;
        }

        .btn-resend {
            background: none;
            border: 2px solid var(--border-color);
            padding: 12px 20px;
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .btn-resend:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .btn-resend:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .timer-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
            text-align: center;
        }

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
        }

        .btn-primary:hover {
            transform: translateY(-2px);
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

        .back-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border-light);
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .password-requirements {
            background: var(--bg-primary);
            padding: 12px;
            border-radius: var(--radius-md);
            margin-top: 12px;
        }

        .password-requirements p {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .req-item {
            font-size: 11px;
            color: var(--text-muted);
            margin: 4px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .req-item.valid {
            color: var(--success);
        }

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

        @media (max-width: 480px) {
            body {
                padding: 0;
                background: var(--bg-secondary);
            }
            
            .auth-card {
                padding: 32px 20px;
                border-radius: 0;
                min-height: 100vh;
                box-shadow: none;
                border: none;
            }
            
            .auth-card::before {
                display: none;
            }
            
            .otp-group {
                flex-direction: column;
            }
            
            .btn-resend {
                width: 100%;
            }
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
            
            <?php if ($step === 'request'): ?>
                <!-- STEP 1: Request OTP -->
                <div class="auth-header">
                    <h1>Forgot Password? 🔐</h1>
                    <p>Enter your email address and we'll send you an OTP to reset your password.</p>
                </div>
                
                <div class="info-message">
                    <i class="fas fa-info-circle"></i>
                    <span>We'll send a 6-digit OTP to your email. The OTP will expire in 5 minutes.</span>
                </div>
                
                <div id="requestAlert"></div>
                
                <form id="requestForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" id="email" placeholder="Enter your Email Address" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" id="requestBtn">
                        <i class="fas fa-paper-plane"></i> Send OTP
                    </button>
                </form>
                
            <?php elseif ($step === 'verify'): ?>
                <!-- STEP 2: Verify OTP -->
                <div class="auth-header">
                    <h1>Verify OTP 🔑</h1>
                    <p>Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($email); ?></strong></p>
                </div>
                
                <div id="verifyAlert"></div>
                
                <form id="verifyForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <div class="form-group">
                        <label for="otp"><i class="fas fa-key"></i> OTP Code</label>
                        <div class="otp-group">
                            <div class="input-wrapper">
                                <input type="text" name="otp" id="otp" placeholder="Enter 6-digit code" maxlength="6" required inputmode="numeric" autofocus>
                            </div>
                            <button type="button" class="btn-resend" id="resendBtn">
                                <i class="fas fa-redo-alt"></i> Resend
                            </button>
                        </div>
                        <div class="timer-text" id="timerText"></div>
                    </div>
                    <button type="submit" class="btn-primary" id="verifyBtn">
                        <i class="fas fa-check-circle"></i> Verify OTP
                    </button>
                </form>
                
            <?php elseif ($step === 'reset'): ?>
                <!-- STEP 3: Reset Password -->
                <div class="auth-header">
                    <h1>Set New Password 🔒</h1>
                    <p>Create a new password for your account</p>
                </div>
                
                <div id="resetAlert"></div>
                
                <form id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> New Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="password" id="password" placeholder="••••••••" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-check-circle"></i> Confirm Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="••••••••" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="password-requirements">
                        <p><i class="fas fa-shield-alt"></i> Password must contain:</p>
                        <div class="req-item" id="req-length"><i class="fas fa-circle"></i> At least 8 characters</div>
                        <div class="req-item" id="req-upper"><i class="fas fa-circle"></i> At least 1 uppercase letter</div>
                        <div class="req-item" id="req-number"><i class="fas fa-circle"></i> At least 1 number</div>
                    </div>
                    <button type="submit" class="btn-primary" id="resetBtn">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="back-link">
                <a href="user_login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </div>

    <script>
        function showAlert(container, type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-message ${type}`;
            alertDiv.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
                <button class="close-alert" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            const containerEl = document.getElementById(container);
            if (containerEl) {
                containerEl.innerHTML = '';
                containerEl.appendChild(alertDiv);
                setTimeout(() => { if(alertDiv.parentElement) alertDiv.remove(); }, 5000);
            }
        }

        let resendTimer = 0;
        let timerInterval = null;

        function startResendTimer(seconds = 300) {
            resendTimer = seconds;
            const resendBtn = document.getElementById('resendBtn');
            const timerText = document.getElementById('timerText');
            if (timerInterval) clearInterval(timerInterval);
            timerInterval = setInterval(() => {
                if (resendTimer <= 0) {
                    clearInterval(timerInterval);
                    if (resendBtn) { 
                        resendBtn.disabled = false; 
                        resendBtn.innerHTML = '<i class="fas fa-redo-alt"></i> Resend'; 
                    }
                    if (timerText) timerText.innerHTML = '';
                } else {
                    if (resendBtn) { 
                        resendBtn.disabled = true; 
                        resendBtn.innerHTML = `<i class="fas fa-hourglass-half"></i> Resend (${resendTimer}s)`; 
                    }
                    if (timerText) timerText.innerHTML = `⏱ OTP expires in ${resendTimer} seconds`;
                    resendTimer--;
                }
            }, 1000);
        }

        // STEP 1: Request OTP
        const requestForm = document.getElementById('requestForm');
        if (requestForm) {
            requestForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const email = document.getElementById('email').value.trim();
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    showAlert('requestAlert', 'error', 'Please enter a valid email address');
                    return;
                }
                const btn = document.getElementById('requestBtn');
                btn.classList.add('loading');
                btn.innerHTML = '<i class="fas fa-spinner"></i> Sending...';
                try {
                    const formData = new FormData(requestForm);
                    const response = await fetch('forgot_password_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send_otp', email: email, csrf_token: formData.get('csrf_token') })
                    });
                    const data = await response.json();
                    if (data.success) {
                        showAlert('requestAlert', 'success', data.message);
                        // Redirect to verify step with email in URL
                        setTimeout(() => { 
                            window.location.href = `forgot-password.php?step=verify&email=${encodeURIComponent(email)}`;
                        }, 1500);
                    } else {
                        showAlert('requestAlert', 'error', data.message);
                    }
                } catch (error) {
                    showAlert('requestAlert', 'error', 'Network error. Please try again.');
                } finally {
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP';
                }
            });
        }

        // STEP 2: Verify OTP
        const verifyForm = document.getElementById('verifyForm');
        if (verifyForm) {
            startResendTimer(300);
            
            verifyForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const otp = document.getElementById('otp').value.trim();
                if (!otp || otp.length !== 6 || !/^\d+$/.test(otp)) {
                    showAlert('verifyAlert', 'error', 'Please enter a valid 6-digit OTP');
                    return;
                }
                const btn = document.getElementById('verifyBtn');
                btn.classList.add('loading');
                btn.innerHTML = '<i class="fas fa-spinner"></i> Verifying...';
                try {
                    const formData = new FormData(verifyForm);
                    const response = await fetch('forgot_password_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'verify_otp', email: formData.get('email'), otp: otp, csrf_token: formData.get('csrf_token') })
                    });
                    const data = await response.json();
                    if (data.success) {
                        showAlert('verifyAlert', 'success', data.message);
                        setTimeout(() => { 
                            window.location.href = 'forgot-password.php?step=reset';
                        }, 1500);
                    } else {
                        showAlert('verifyAlert', 'error', data.message);
                    }
                } catch (error) {
                    showAlert('verifyAlert', 'error', 'Network error. Please try again.');
                } finally {
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Verify OTP';
                }
            });
            
            const resendBtn = document.getElementById('resendBtn');
            if (resendBtn) {
                resendBtn.addEventListener('click', async () => {
                    if (resendTimer > 0) return;
                    resendBtn.innerHTML = '<i class="fas fa-spinner"></i> Sending...';
                    try {
                        const formData = new FormData(verifyForm);
                        const response = await fetch('forgot_password_handler.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'send_otp', email: formData.get('email'), csrf_token: formData.get('csrf_token'), resend: true })
                        });
                        const data = await response.json();
                        if (data.success) {
                            showAlert('verifyAlert', 'success', 'New OTP sent to your email');
                            startResendTimer(300);
                        } else {
                            showAlert('verifyAlert', 'error', data.message);
                            resendBtn.disabled = false;
                            resendBtn.innerHTML = '<i class="fas fa-redo-alt"></i> Resend';
                        }
                    } catch (error) {
                        showAlert('verifyAlert', 'error', 'Network error. Please try again.');
                        resendBtn.disabled = false;
                        resendBtn.innerHTML = '<i class="fas fa-redo-alt"></i> Resend';
                    }
                });
            }
        }

        // STEP 3: Reset Password
        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            
            function checkPasswordRequirements() {
                const password = passwordInput.value;
                const lengthReq = document.getElementById('req-length');
                const upperReq = document.getElementById('req-upper');
                const numberReq = document.getElementById('req-number');
                
                if (password.length >= 8) { 
                    lengthReq.classList.add('valid'); 
                    lengthReq.innerHTML = '<i class="fas fa-check-circle"></i> At least 8 characters'; 
                } else { 
                    lengthReq.classList.remove('valid'); 
                    lengthReq.innerHTML = '<i class="fas fa-circle"></i> At least 8 characters'; 
                }
                
                if (/[A-Z]/.test(password)) { 
                    upperReq.classList.add('valid'); 
                    upperReq.innerHTML = '<i class="fas fa-check-circle"></i> At least 1 uppercase letter'; 
                } else { 
                    upperReq.classList.remove('valid'); 
                    upperReq.innerHTML = '<i class="fas fa-circle"></i> At least 1 uppercase letter'; 
                }
                
                if (/[0-9]/.test(password)) { 
                    numberReq.classList.add('valid'); 
                    numberReq.innerHTML = '<i class="fas fa-check-circle"></i> At least 1 number'; 
                } else { 
                    numberReq.classList.remove('valid'); 
                    numberReq.innerHTML = '<i class="fas fa-circle"></i> At least 1 number'; 
                }
            }
            
            passwordInput.addEventListener('input', checkPasswordRequirements);
            
            resetForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const password = passwordInput.value;
                const confirmPassword = confirmInput.value;
                
                if (password.length < 8) { 
                    showAlert('resetAlert', 'error', 'Password must be at least 8 characters'); 
                    return; 
                }
                if (!/[A-Z]/.test(password)) { 
                    showAlert('resetAlert', 'error', 'Password must contain at least one uppercase letter'); 
                    return; 
                }
                if (!/[0-9]/.test(password)) { 
                    showAlert('resetAlert', 'error', 'Password must contain at least one number'); 
                    return; 
                }
                if (password !== confirmPassword) { 
                    showAlert('resetAlert', 'error', 'Passwords do not match'); 
                    return; 
                }
                
                const btn = document.getElementById('resetBtn');
                btn.classList.add('loading');
                btn.innerHTML = '<i class="fas fa-spinner"></i> Resetting...';
                
                try {
                    const formData = new FormData(resetForm);
                    const response = await fetch('forgot_password_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'reset_password', password: password, confirm_password: confirmPassword, csrf_token: formData.get('csrf_token') })
                    });
                    const data = await response.json();
                    if (data.success) {
                        showAlert('resetAlert', 'success', data.message);
                        setTimeout(() => { 
                            window.location.href = 'user_login.php';
                        }, 2000);
                    } else {
                        showAlert('resetAlert', 'error', data.message);
                    }
                } catch (error) {
                    showAlert('resetAlert', 'error', 'Network error. Please try again.');
                } finally {
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-save"></i> Reset Password';
                }
            });
        }
    </script>
</body>
</html>