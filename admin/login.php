<?php
session_name('eyecore_admin');
session_start();
include '../config/db.php';
$error = '';
$success = '';

$base_url = 'https://eyecore.capstone001.com/';

// Generate CSRF token for forgot password API calls
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ===============================
   LOGIN HANDLER
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    $email    = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    try {

        // ✅ Include clinic_id and clinic status
        $stmt = $pdo->prepare("
            SELECT u.*, 
                c.status as clinic_status, 
                c.clinic_name, 
                c.id as clinic_id,
                c.clinic_logo
            FROM users u 
            LEFT JOIN clinics c ON u.clinic_id = c.id 
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        /* ===============================
           WALANG ACCOUNT
        ================================ */
        if (!$user) {
            $_SESSION['swal'] = [
                'icon'  => 'error',
                'title' => 'Account Not Found',
                'text'  => 'No account is associated with this email.'
            ];
            header("Location: {$base_url}admin/login.php");
            exit();
        }

        /* ===============================
           PASSWORD CHECK
        ================================ */
        if (!password_verify($password, $user['password'])) {
            $_SESSION['swal'] = [
                'icon'  => 'error',
                'title' => 'Login Failed',
                'text'  => 'Incorrect email or password.'
            ];
            header("Location: {$base_url}admin/login.php");
            exit();
        }

        /* ===============================
           USER STATUS LOGIC
        ================================ */
        $isClinicUser = in_array($user['role'], ['ClinicAdmin', 'Staff']);
        $clinicStatus = $user['clinic_status'] ?? 'Pending';

        // ✅ Allow ClinicAdmins/Staff with Pending clinic to log in
        if (!$isClinicUser || $clinicStatus !== 'Pending') {
            if ($user['status'] === 'Inactive') {
                $_SESSION['swal'] = [
                    'icon'  => 'warning',
                    'title' => 'Account Inactive',
                    'text'  => 'Your account has been deactivated. Please contact admin.'
                ];
                header("Location: {$base_url}admin/login.php");
                exit();
            }
        }

        /* ===============================
           SET SESSION (ALL VALID USERS)
        ================================ */
        unset($_SESSION['user_name']);
        unset($_SESSION['user_email']);
        unset($_SESSION['user_role']);
        unset($_SESSION['last_login']);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['status']     = $user['status'];
        $_SESSION['employee_id']   = $user['id'];

        /* ===============================
           ✅ RIDER SESSION LOADING
        ================================ */
        if ($user['role'] === 'Rider') {
            $rStmt = $pdo->prepare("
                SELECT id, name, status 
                FROM riders 
                WHERE user_id = ? AND clinic_id = ?
            ");
            $rStmt->execute([$user['id'], $user['clinic_id']]);
            $riderRec = $rStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($riderRec && $riderRec['status'] === 'active') {
                $_SESSION['rider_id']   = (int)$riderRec['id'];
                $_SESSION['rider_name'] = $riderRec['name'];
            } else {
                // Rider record not found or not active
                session_destroy();
                $_SESSION['swal'] = [
                    'icon'  => 'error',
                    'title' => 'Rider Account Inactive',
                    'text'  => 'Your rider account is not active. Please contact admin.'
                ];
                header("Location: {$base_url}admin/login.php");
                exit();
            }
        }

        if (!empty($user['clinic_id'])) {
            $_SESSION['clinic_id']     = $user['clinic_id'];
            $_SESSION['clinic_name']   = $user['clinic_name'];
            $_SESSION['clinic_status'] = $user['clinic_status'];
            $_SESSION['clinic_logo']   = $user['clinic_logo'];
        }

        /* ===============================
           SET DOCTOR ID FOR OPTOMETRISTS / CLINICADMIN
        ================================ */
        if (in_array($user['role'], ['Optometrist', 'ClinicAdmin'])) {
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ? AND clinic_id = ?");
            $stmt->execute([$user['id'], $user['clinic_id']]);
            $doctor = $stmt->fetch();

            if ($doctor) {
                $_SESSION['doctor_id'] = $doctor['id'];
            } else {
                $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
                $stmt = $pdo->prepare("SELECT id FROM doctors WHERE clinic_id = ? AND name = ?");
                $stmt->execute([$user['clinic_id'], $fullName]);
                $doctor = $stmt->fetch();

                if ($doctor) {
                    $_SESSION['doctor_id'] = $doctor['id'];
                    $pdo->prepare("UPDATE doctors SET user_id = ? WHERE id = ?")
                        ->execute([$user['id'], $doctor['id']]);
                } else {
                    if ($user['role'] === 'Optometrist') {
                        $fullName = $user['first_name'] . ' ' . $user['last_name'];
                        $stmt = $pdo->prepare("
                            INSERT INTO doctors (clinic_id, user_id, name, specialty, is_active, created_at)
                            VALUES (?, ?, ?, 'Optometrist', 1, NOW())
                        ");
                        $stmt->execute([$user['clinic_id'], $user['id'], $fullName]);
                        $_SESSION['doctor_id'] = $pdo->lastInsertId();
                    }
                }
            }
        }

        /* ===============================
           CLINIC STATUS LOGIC — FIXED!
        ================================ */
        if (in_array($user['role'], ['ClinicAdmin', 'Staff'])) {
            $clinicStatus = $user['clinic_status'] ?? 'Pending';

            if ($clinicStatus === 'Rejected') {
                header("Location: {$base_url}views/clinic_rejected.php");
                exit();
            } elseif ($clinicStatus === 'Suspended') {
                header("Location: {$base_url}views/clinic_suspended.php");
                exit();
            } elseif ($clinicStatus === 'Pending') {
                header("Location: {$base_url}views/clinic_pending.php");
                exit();
            } elseif ($clinicStatus === 'Reapplying') {
                // ✅ FIX: Redirect to clinic_pending.php for Reapplying status
                header("Location: {$base_url}views/clinic_pending.php");
                exit();
            } elseif ($clinicStatus !== 'Active') {
                $_SESSION['swal'] = [
                    'icon'  => 'warning',
                    'title' => 'Limited Access',
                    'text'  => 'Your clinic is not active. Some features are disabled.'
                ];
            }
        }

        /* ===============================
           UPDATE LAST LOGIN
        ================================ */
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        /* ===============================
           REMEMBER ME
        ================================ */
        if ($remember) {
            $token   = bin2hex(random_bytes(32));
            $expires = time() + (30 * 24 * 60 * 60);
            $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
                ->execute([$token, $user['id']]);
            setcookie('remember_token', $token, $expires, '/', '', false, true);
        }

        // Load RBAC permissions
        require_once __DIR__ . '/../include/RBACHelper.php';
        RBACHelper::init($pdo);
        RBACHelper::loadPermissionsToSession($user['id'], $user['clinic_id']);

        /* ===============================
           REDIRECT BASED ON ROLE
        ================================ */
        require_once __DIR__ . '/../include/SubscriptionHelper.php';
        $subHelper = new SubscriptionHelper($pdo, $user['clinic_id']);
        $hasSubscription = $subHelper->hasActiveSubscription();

        if (in_array($user['role'], ['ClinicAdmin', 'Optometrist', 'Staff', 'HR', 'Finance', 'CRM', 'SCM'])) {
            $redirect = $hasSubscription
                ? $base_url . "main.php"
                : $base_url . "views/subscription.php";
        } elseif ($user['role'] === 'Rider') {
            // ✅ Rider redirect to rider dashboard
            $redirect = $base_url . "views/rider_dashboard.php";
        } else {
            $redirect = "{$base_url}access_denied.php?role=" . urlencode($user['role']);
        }

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Redirecting...</title>
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        </head>
        <body>
            <script>
            Swal.fire({
                icon: 'success',
                title: 'Welcome Back!',
                text: 'Login successful.',
                confirmButtonColor: '#3085d6',
                timer: 1500,
                timerProgressBar: true,
                allowOutsideClick: false,
                showConfirmButton: false
            }).then(() => {
                window.location.href = '<?php echo $redirect; ?>';
            });
            </script>
        </body>
        </html>
        <?php
        exit();

    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        $_SESSION['swal'] = [
            'icon'  => 'error',
            'title' => 'System Error',
            'text'  => 'Something went wrong. Please try again.'
        ];
        header("Location: {$base_url}admin/login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --teal-50: #f0fdfa;
            --teal-100: #ccfbf1;
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --blue-50: #eff6ff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-900: #111827;
        }
        body {
            background: linear-gradient(135deg, var(--teal-50), var(--blue-50));
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .brand-logo {
            background-color: var(--teal-600);
            width: 64px; height: 64px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto;
        }
        .login-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,.1), 0 10px 10px -5px rgba(0,0,0,.04);
        }
        .input-group-icon {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            pointer-events: none;
            z-index: 5;
        }
        .password-toggle {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--gray-400); cursor: pointer;
            z-index: 5;
        }
        .btn-teal {
            background-color: var(--teal-600);
            color: white; border: none;
        }
        .btn-teal:hover { background-color: var(--teal-700); color: white; }
        .btn-teal:disabled { opacity: .65; cursor: not-allowed; }
        .form-control:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13,148,136,.25);
        }
        /* ── OTP Inputs ── */
        .otp-inputs { display: flex; gap: 10px; justify-content: center; }
        .otp-inputs input {
            width: 48px; height: 52px;
            text-align: center; font-size: 1.3rem; font-weight: 700;
            border: 2px solid var(--gray-300);
            border-radius: 10px;
            transition: border-color .2s;
        }
        .otp-inputs input:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13,148,136,.2);
            outline: none;
        }
        /* ── Step indicators ── */
        .step-indicators { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .step-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--gray-300); transition: background .3s;
        }
        .step-dot.active { background: var(--teal-600); width: 24px; border-radius: 4px; }
        .step-dot.done   { background: var(--teal-600); }
        /* ── Password strength ── */
        .strength-bar { height: 4px; border-radius: 2px; transition: width .3s, background .3s; }
        .strength-text { font-size: .75rem; }
        /* ── Timer ── */
        #resetTimerText { font-size: .8rem; }
        a.link-teal { color: var(--teal-600); text-decoration: none; }
        a.link-teal:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center p-4">
    <div class="w-100" style="max-width: 400px;">

        <!-- Branding -->
        <div class="text-center mb-5">
            <div class="brand-logo">
                <i class="fas fa-eye text-white fa-2x"></i>
            </div>
            <h1 class="h3 fw-bold mt-3 mb-1" style="color:var(--gray-900)">Eyecore</h1>
            <p style="color:var(--gray-600)">Optical Clinic Management System</p>
        </div>

        <!-- Login Card -->
        <div class="login-card p-4 p-md-5">
            <h2 class="h4 fw-bold mb-1" style="color:var(--gray-900)">Welcome Back</h2>
            <p style="color:var(--gray-600)" class="mb-4">Sign in to access your dashboard</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input type="hidden" name="login" value="1">

                <div class="mb-4">
                    <label for="email" class="form-label" style="color:var(--gray-700)">Email Address</label>
                    <div class="position-relative">
                        <div class="input-group-icon"><i class="fas fa-envelope"></i></div>
                        <input type="email" id="email" name="email"
                               class="form-control ps-5 pe-4"
                               placeholder="Enter your email" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label" style="color:var(--gray-700)">Password</label>
                    <div class="position-relative">
                        <div class="input-group-icon"><i class="fas fa-lock"></i></div>
                        <input type="password" id="password" name="password"
                               class="form-control ps-5 pe-4"
                               placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember" style="color:var(--gray-700)">Remember me</label>
                    </div>
                    <!-- ✅ Triggers forgot password modal -->
                    <a href="#" class="link-teal" id="forgotPasswordLink">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-teal w-100 py-2">Sign In</button>

                <div class="mt-3">
                    <p class="text-center mb-0" style="color:var(--gray-600)">
                        Don't have an account?
                        <a href="../auth/register.php" class="link-teal">Register here</a>
                    </p>
                </div>
            </form>

            <div class="mt-4 pt-4 border-top">
                <p class="small text-muted text-center mb-0">Role-based access control enabled.</p>
            </div>
        </div>

        <p class="text-center mt-4" style="color:var(--gray-600)">© 2026 Eyecore. All rights reserved.</p>
    </div>
</div>

<!-- =====================================================
     FORGOT PASSWORD MODAL — 3-step flow
     Step 1: Enter email
     Step 2: Enter OTP
     Step 3: Set new password
====================================================== -->
<div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius:16px;overflow:hidden;">

            <!-- Header -->
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <div>
                    <!-- Step indicators -->
                    <div class="step-indicators" id="stepIndicators">
                        <div class="step-dot active" id="dot1"></div>
                        <div class="step-dot" id="dot2"></div>
                        <div class="step-dot" id="dot3"></div>
                    </div>
                    <h5 class="modal-title fw-bold mb-0" id="modalTitle" style="color:var(--gray-900)">
                        Reset Password
                    </h5>
                    <p class="mb-0 mt-1" id="modalSubtitle" style="font-size:.85rem;color:var(--gray-600)">
                        Enter your registered email address
                    </p>
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" onclick="resetModal()"></button>
            </div>

            <div class="modal-body px-4 pb-4 pt-3">

                <!-- ── STEP 1: Email ── -->
                <div id="step1">
                    <div class="mb-3">
                        <label class="form-label" style="color:var(--gray-700)">Email Address</label>
                        <div class="position-relative">
                            <div class="input-group-icon"><i class="fas fa-envelope"></i></div>
                            <input type="email" id="resetEmail" class="form-control ps-5"
                                   placeholder="Enter your email address">
                        </div>
                        <div id="step1Error" class="text-danger mt-2" style="font-size:.82rem;display:none;"></div>
                    </div>
                    <button class="btn btn-teal w-100 py-2" id="sendOtpBtn" onclick="sendResetOtp()">
                        <span id="sendOtpBtnText">Send Verification Code</span>
                        <span id="sendOtpSpinner" class="spinner-border spinner-border-sm ms-2 d-none"></span>
                    </button>
                </div>

                <!-- ── STEP 2: OTP ── -->
                <div id="step2" style="display:none;">
                    <p class="mb-3" style="font-size:.85rem;color:var(--gray-600)">
                        A 6-digit code was sent to <strong id="displayEmail"></strong>
                    </p>

                    <div class="otp-inputs mb-2" id="otpInputs">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                        <input type="text" maxlength="1" class="otp-box" inputmode="numeric" autocomplete="off">
                    </div>

                    <div id="step2Error" class="text-danger mb-2" style="font-size:.82rem;display:none;"></div>

                    <!-- Timer & Resend -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span id="resetTimerText" style="color:var(--gray-500)"></span>
                        <a href="#" id="resendOtpLink" class="link-teal" style="font-size:.82rem;display:none;"
                           onclick="resendOtp(event)">Resend Code</a>
                    </div>

                    <button class="btn btn-teal w-100 py-2" id="verifyOtpBtn" onclick="verifyResetOtp()">
                        <span id="verifyOtpBtnText">Verify Code</span>
                        <span id="verifyOtpSpinner" class="spinner-border spinner-border-sm ms-2 d-none"></span>
                    </button>
                    <button class="btn btn-outline-secondary w-100 py-2 mt-2" onclick="goToStep(1)">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </button>
                </div>

                <!-- ── STEP 3: New Password ── -->
                <div id="step3" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label" style="color:var(--gray-700)">New Password</label>
                        <div class="position-relative">
                            <div class="input-group-icon"><i class="fas fa-lock"></i></div>
                            <input type="password" id="newPassword" class="form-control ps-5 pe-5"
                                   placeholder="At least 8 chars, 1 uppercase, 1 number"
                                   oninput="checkPasswordStrength()">
                            <button type="button" class="password-toggle"
                                    onclick="togglePassword('newPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <!-- Strength bar -->
                        <div class="mt-1" style="background:var(--gray-200);border-radius:2px;height:4px;">
                            <div class="strength-bar" id="strengthBar" style="width:0%;background:transparent;"></div>
                        </div>
                        <span class="strength-text" id="strengthText" style="color:var(--gray-400)"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="color:var(--gray-700)">Confirm Password</label>
                        <div class="position-relative">
                            <div class="input-group-icon"><i class="fas fa-lock"></i></div>
                            <input type="password" id="confirmPassword" class="form-control ps-5 pe-5"
                                   placeholder="Re-enter new password">
                            <button type="button" class="password-toggle"
                                    onclick="togglePassword('confirmPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div id="step3Error" class="text-danger mb-2" style="font-size:.82rem;display:none;"></div>

                    <button class="btn btn-teal w-100 py-2" id="resetPwBtn" onclick="submitNewPassword()">
                        <span id="resetPwBtnText">Reset Password</span>
                        <span id="resetPwSpinner" class="spinner-border spinner-border-sm ms-2 d-none"></span>
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']); ?>;
const BASE_URL   = <?= json_encode($base_url); ?>;

let forgotModal;
let otpTimerInterval = null;

// ── Open modal ──────────────────────────────────────────────
document.getElementById('forgotPasswordLink').addEventListener('click', e => {
    e.preventDefault();
    forgotModal = new bootstrap.Modal(document.getElementById('forgotModal'));
    forgotModal.show();
});

// ── Reset modal to step 1 ────────────────────────────────────
function resetModal() {
    goToStep(1);
    document.getElementById('resetEmail').value = '';
    clearOtpBoxes();
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    clearError('step1Error');
    clearError('step2Error');
    clearError('step3Error');
    stopTimer();
}

// ── Step navigation ──────────────────────────────────────────
function goToStep(n) {
    [1, 2, 3].forEach(i => {
        document.getElementById('step' + i).style.display = i === n ? '' : 'none';
    });
    updateStepIndicators(n);

    const titles = ['Reset Password', 'Enter Verification Code', 'Set New Password'];
    const subs   = [
        'Enter your registered email address',
        'We sent a 6-digit code to your email',
        'Choose a strong new password'
    ];
    document.getElementById('modalTitle').textContent   = titles[n - 1];
    document.getElementById('modalSubtitle').textContent = subs[n - 1];
}

function updateStepIndicators(active) {
    for (let i = 1; i <= 3; i++) {
        const dot = document.getElementById('dot' + i);
        dot.className = 'step-dot' + (i < active ? ' done' : i === active ? ' active' : '');
    }
}

// ── Error helpers ─────────────────────────────────────────────
function showError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = '';
}
function clearError(id) {
    const el = document.getElementById(id);
    el.style.display = 'none';
    el.textContent = '';
}
function setLoading(btnId, spinnerId, loading) {
    document.getElementById(btnId).disabled = loading;
    document.getElementById(spinnerId).classList.toggle('d-none', !loading);
}

// ── Password visibility toggle ─────────────────────────────────
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ── STEP 1: Send OTP ─────────────────────────────────────────
async function sendResetOtp() {
    clearError('step1Error');
    const email = document.getElementById('resetEmail').value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError('step1Error', 'Please enter a valid email address.');
        return;
    }

    setLoading('sendOtpBtn', 'sendOtpSpinner', true);
    document.getElementById('sendOtpBtnText').textContent = 'Sending...';

    try {
        const res  = await fetch(BASE_URL + 'api/send_reset_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, csrf_token: CSRF_TOKEN })
        });
        const data = await res.json();

        if (data.success) {
            document.getElementById('displayEmail').textContent = email;
            goToStep(2);
            startOtpTimer(300); // 5 min
            setTimeout(() => document.querySelector('.otp-box').focus(), 200);
        } else {
            showError('step1Error', data.message || 'Failed to send OTP.');
        }
    } catch (err) {
        showError('step1Error', 'Network error. Please try again.');
    } finally {
        setLoading('sendOtpBtn', 'sendOtpSpinner', false);
        document.getElementById('sendOtpBtnText').textContent = 'Send Verification Code';
    }
}

// ── STEP 2: OTP boxes ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const boxes = document.querySelectorAll('.otp-box');
    boxes.forEach((box, idx) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/, '');
            if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && idx > 0) boxes[idx - 1].focus();
        });
        box.addEventListener('paste', e => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, i) => { if (boxes[i]) boxes[i].value = d; });
            if (boxes[Math.min(digits.length, 5)]) boxes[Math.min(digits.length, 5)].focus();
        });
    });
});

function getOtpValue() {
    return [...document.querySelectorAll('.otp-box')].map(b => b.value).join('');
}
function clearOtpBoxes() {
    document.querySelectorAll('.otp-box').forEach(b => b.value = '');
}

// ── OTP Timer ─────────────────────────────────────────────────
function startOtpTimer(seconds) {
    stopTimer();
    document.getElementById('resendOtpLink').style.display = 'none';
    const el = document.getElementById('resetTimerText');

    function tick() {
        if (seconds <= 0) {
            el.textContent = 'Code expired.';
            document.getElementById('resendOtpLink').style.display = '';
            stopTimer();
            return;
        }
        const m = String(Math.floor(seconds / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        el.textContent = `Code expires in ${m}:${s}`;
        seconds--;
    }
    tick();
    otpTimerInterval = setInterval(tick, 1000);
}
function stopTimer() {
    if (otpTimerInterval) { clearInterval(otpTimerInterval); otpTimerInterval = null; }
}

async function resendOtp(e) {
    e.preventDefault();
    stopTimer();
    clearOtpBoxes();
    clearError('step2Error');

    const email = document.getElementById('resetEmail').value.trim();
    try {
        const res  = await fetch(BASE_URL + 'api/send_reset_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, csrf_token: CSRF_TOKEN })
        });
        const data = await res.json();
        if (data.success) {
            startOtpTimer(300);
        } else {
            showError('step2Error', data.message || 'Failed to resend OTP.');
        }
    } catch {
        showError('step2Error', 'Network error. Please try again.');
    }
}

// ── STEP 2: Verify OTP ────────────────────────────────────────
async function verifyResetOtp() {
    clearError('step2Error');
    const email = document.getElementById('resetEmail').value.trim();
    const otp   = getOtpValue();

    if (otp.length !== 6) {
        showError('step2Error', 'Please enter the complete 6-digit code.');
        return;
    }

    setLoading('verifyOtpBtn', 'verifyOtpSpinner', true);
    document.getElementById('verifyOtpBtnText').textContent = 'Verifying...';

    try {
        const res  = await fetch(BASE_URL + 'api/verify_reset_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, otp, csrf_token: CSRF_TOKEN })
        });
        const data = await res.json();

        if (data.success) {
            stopTimer();
            goToStep(3);
            setTimeout(() => document.getElementById('newPassword').focus(), 200);
        } else {
            showError('step2Error', data.message || 'Invalid OTP.');
        }
    } catch {
        showError('step2Error', 'Network error. Please try again.');
    } finally {
        setLoading('verifyOtpBtn', 'verifyOtpSpinner', false);
        document.getElementById('verifyOtpBtnText').textContent = 'Verify Code';
    }
}

// ── Password strength ─────────────────────────────────────────
function checkPasswordStrength() {
    const pw  = document.getElementById('newPassword').value;
    const bar = document.getElementById('strengthBar');
    const txt = document.getElementById('strengthText');

    let score = 0;
    if (pw.length >= 8)          score++;
    if (/[A-Z]/.test(pw))        score++;
    if (/[0-9]/.test(pw))        score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const map = {
        0: [0,   'transparent',         ''],
        1: [25,  '#ef4444',             'Weak'],
        2: [50,  '#f97316',             'Fair'],
        3: [75,  '#eab308',             'Good'],
        4: [100, '#22c55e',             'Strong'],
    };
    const [w, color, label] = map[score];
    bar.style.width      = w + '%';
    bar.style.background = color;
    txt.textContent      = label;
    txt.style.color      = color;
}

// ── STEP 3: Submit new password ───────────────────────────────
async function submitNewPassword() {
    clearError('step3Error');
    const password         = document.getElementById('newPassword').value;
    const confirm_password = document.getElementById('confirmPassword').value;

    if (password.length < 8) {
        showError('step3Error', 'Password must be at least 8 characters.');
        return;
    }
    if (!/[A-Z]/.test(password)) {
        showError('step3Error', 'Password must contain at least one uppercase letter.');
        return;
    }
    if (!/[0-9]/.test(password)) {
        showError('step3Error', 'Password must contain at least one number.');
        return;
    }
    if (password !== confirm_password) {
        showError('step3Error', 'Passwords do not match.');
        return;
    }

    setLoading('resetPwBtn', 'resetPwSpinner', true);
    document.getElementById('resetPwBtnText').textContent = 'Resetting...';

    try {
        const res  = await fetch(BASE_URL + 'api/reset_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password, confirm_password, csrf_token: CSRF_TOKEN })
        });
        const data = await res.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('forgotModal')).hide();
            resetModal();
            Swal.fire({
                icon: 'success',
                title: 'Password Reset!',
                text: 'Your password has been updated. Please log in.',
                confirmButtonColor: '#0d9488',
                timer: 2500,
                timerProgressBar: true
            });
        } else {
            showError('step3Error', data.message || 'Failed to reset password.');
        }
    } catch {
        showError('step3Error', 'Network error. Please try again.');
    } finally {
        setLoading('resetPwBtn', 'resetPwSpinner', false);
        document.getElementById('resetPwBtnText').textContent = 'Reset Password';
    }
}

// ── Login page password toggle ────────────────────────────────
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn ? btn.querySelector('i') : document.querySelector('.password-toggle i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

<?php if (isset($_SESSION['swal'])): ?>
<script>
Swal.fire({
    icon:  "<?= $_SESSION['swal']['icon']; ?>",
    title: "<?= $_SESSION['swal']['title']; ?>",
    text:  "<?= $_SESSION['swal']['text']; ?>",
    confirmButtonColor: "#3085d6",
    timer: 1500,
    timerProgressBar: true
});
</script>
<?php unset($_SESSION['swal']); endif; ?>

</body>
</html>