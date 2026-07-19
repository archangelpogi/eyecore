<?php
// auth/login.php
session_start();

require_once '../config/db.php';
require_once '../config/security.php';

// BASE URL
$base_url = 'http://eyecore.capstone001.com/';

$error = '';
$success = '';
$showForgotPassword = false;

// Check if already logged in
if (Security::isAuthenticated()) {
    header('Location: ' . $base_url . 'developer.php');
    exit();
}

// LOGIN HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $email = Security::sanitize($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    // Validate CSRF
    if (!Security::validateCSRFToken($csrf_token)) {
        $error = "Security token invalid. Please refresh the page.";
    } 
    // Check login attempts
    elseif (Security::checkLoginAttempts($email)) {
        $error = "Too many failed attempts. Please try again in 15 minutes.";
    }
    // Process login
    else {
        try {
            // Query using your existing database structure
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'Active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // SUPERADMIN CHECK - Only SuperAdmin can login
                if ($user['role'] !== 'SuperAdmin') {
                    Security::recordLoginAttempt($email, false);
                    $error = "Access restricted to system administrators only.";
                } else {
                    // SET SESSION - Using your existing session variables
                    $_SESSION['user_id'] = $user['user_id'] ?? $user['id'];
                    $_SESSION['user_db_id'] = $user['id'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['authenticated'] = true;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['regenerated'] = time();

                    // UPDATE LAST LOGIN
                    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);

                    // REMEMBER ME COOKIE
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60);
                        setcookie('remember_token', $token, $expires, '/', '', false, true);
                    }

                    // LOG ACTIVITY
                    Security::recordLoginAttempt($email, true);
                    Security::logActivity('LOGIN_SUCCESS', 'Authentication', 'SuperAdmin logged in', $user['id']);

                    // REDIRECT
                    header('Location: ' . $base_url . 'developer.php');
                    exit();
                }
            } else {
                Security::recordLoginAttempt($email, false);
                $error = "Invalid email or password";
                Security::logActivity('LOGIN_FAILED', 'Authentication', "Failed attempt: $email");
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System error. Please try again.";
        }
    }
}

// FORGOT PASSWORD HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrf_token)) {
        $error = "Security token invalid.";
    } else {
        $resetEmail = Security::sanitize($_POST['reset_email']);

        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'SuperAdmin'");
            $stmt->execute([$resetEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmt->execute([hash('sha256', $token), $expires, $user['id']]);

                Security::logActivity('PASSWORD_RESET_REQUEST', 'Authentication', 'Reset requested', $user['id']);
                $success = "Password reset link has been sent to your email.";
                $showForgotPassword = false;
            } else {
                $error = "Email not found for system administrator.";
            }
        } catch (PDOException $e) {
            error_log("Forgot password error: " . $e->getMessage());
            $error = "System error. Please try again.";
        }
    }
}

// SHOW FORGOT PASSWORD MODAL
if (isset($_GET['forgot'])) {
    $showForgotPassword = true;
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - SuperAdmin Login</title>
    <!-- Your existing CSS links -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --gray-50: #f9fafb;
            --gray-900: #111827;
        }
        
        body {
            background: linear-gradient(135deg, #f0fdfa, #eff6ff);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .login-container {
            max-width: 440px;
            margin: 0 auto;
        }
        
        .security-badge {
            background: var(--teal-600);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        
        .input-with-icon input {
            padding-left: 45px;
        }
        
        .form-control:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.25);
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
    </style>
</head>
<body>
    <div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center p-4">
        <div class="login-container w-100">
            
            <!-- Logo -->
            <div class="text-center mb-4">
                <div class="brand-logo">
                    <i class="bi bi-eye-fill text-white fa-2x"></i>
                </div>
                <h1 class="h3 fw-bold text-gray-900 mt-3 mb-1">Eyecore System</h1>
                <p class="text-gray-600">Optical Management Platform</p>
            </div>

            <!-- Login Card -->
            <div class="card border-0 shadow-lg rounded-3">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h4 fw-bold mb-1">System Administrator</h2>
                    <p class="text-muted mb-4">Enter your credentials to continue</p>

                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <div><?= htmlspecialchars($success) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Login Form -->
                    <form method="POST" action="login.php" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="login" value="1">
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Email Address</label>
                            <div class="input-with-icon">
                                <i class="bi bi-person-badge-fill"></i>
                                <input type="email" name="email" class="form-control form-control-lg" 
                                       placeholder="Enter Your Email" required autofocus
                                       value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-semibold">Password</label>
                                <a href="?forgot=1" class="text-decoration-none small text-teal-600">
                                    Forgot Password?
                                </a>
                            </div>
                            <div class="input-with-icon">
                                <i class="bi bi-lock-fill"></i>
                                <input type="password" name="password" id="password" 
                                       class="form-control form-control-lg" 
                                       placeholder="••••••••" required>
                                <button type="button" class="btn btn-link position-absolute end-2 top-50 translate-middle-y" 
                                        onclick="togglePassword()" style="right: 15px;">
                                         <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember"
                                       <?= isset($_POST['remember']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="remember">
                                    Remember this device
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-teal w-100 py-3 fw-semibold"
                                style="background: var(--teal-600); color: white; border: none;">
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Sign In
                        </button>
                    </form>

                    <!-- Security Notice -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-flex align-items-center text-muted small">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            <div>
                                <strong>Security Notice:</strong> This system is monitored. 
                                All activities are logged.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-4">
                <p class="text-muted small">
                    <i class="bi bi-c-circle me-1"></i>
                    2026 Eyecore System v2.0 • 
                    IP: <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?>
                </p>
            </div>
        </div>
    </div>

    <?php if ($showForgotPassword): ?>
    <div class="modal fade show" style="display: block;" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title text-gray-900">Reset Password</h5>
                    <button type="button" class="btn-close" onclick="window.location.href='login.php'"></button>
                </div>
                <div class="modal-body">
                    <p class="text-gray-600 mb-4">Enter your SuperAdmin email address</p>
                    <form method="POST" action="login.php">
                        <input type="hidden" name="forgot_password" value="1">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="mb-4">
                            <label for="reset_email" class="form-label text-gray-700">Email Address</label>
                            <input type="email" id="reset_email" name="reset_email" class="form-control" 
                                   placeholder="admin@eyecore.com" required>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="login.php" class="btn btn-outline-secondary flex-grow-1">Cancel</a>
                            <button type="submit" class="btn btn-teal flex-grow-1"
                                    style="background: var(--teal-600); color: white; border: none;">
                                Send Reset Link
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show" onclick="window.location.href='login.php'"></div>
    <?php endif; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = passwordInput.nextElementSibling.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
        
        // Form submission loading
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat me-2 fa-spin"></i> Authenticating...';
            submitBtn.disabled = true;
        });
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Security notice on first visit
        document.addEventListener('DOMContentLoaded', function() {
            if (!sessionStorage.getItem('securityNoticeShown')) {
                Swal.fire({
                    title: 'SuperAdmin Access Only',
                    html: `
                        <div class="text-start">
                            <p><i class="bi bi-shield-fill-check text-teal-600 me-2"></i> 
                               <strong>System Administrator Access</strong></p>
                            <p class="small">This system is restricted to authorized SuperAdmin users only.</p>
                            <p class="small"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i> 
                               All login attempts and activities are monitored and logged.</p>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'I Understand',
                    confirmButtonColor: '#0d9488',
                    allowOutsideClick: false
                });
                sessionStorage.setItem('securityNoticeShown', 'true');
            }
        });
    </script>
</body>
</html>