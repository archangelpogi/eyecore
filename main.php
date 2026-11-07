<?php
// main.php - Clinic Dashboard with Two-Layer Access Control + Doctor Setup + SUBSCRIPTION CHECK

// ✅ Flag para malaman ng subscription_guard na loaded na tayo via main.php
// Kapag set ito, ang guard ay mag-skip ng header() calls at gagamit ng JS redirect
define('MAIN_PHP_LOADED', true);

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/config/db.php';

/* =================================
   SIMPLE AUTH CHECK
================================= */
if (empty($_SESSION['user_id'])) {
    header('Location: admin/login.php');
    exit();
}

/* =================================
   ✅ SUBSCRIPTION CHECK
================================= */
require_once __DIR__ . '/include/SubscriptionHelper.php';

$clinic_id = $_SESSION['clinic_id'] ?? 0;
$subHelper = new SubscriptionHelper($pdo, $clinic_id);

// Check if clinic has active subscription
// ✅ FIXED: Redirect to subscription_manage.php (renew/upgrade page), hindi subscription.php (plan selection)
if (!$subHelper->hasActiveSubscription()) {
    header('Location: views/subscription_manage.php');
    exit();
}

// ✅ FRESH VALUES (hindi cached sa session) — para tama ang days left
$subscription_plan        = $subHelper->getCurrentPlan();
$subscription_trial_days  = $subHelper->getTrialDaysLeft();
$subscription_is_trial    = $subHelper->isTrial();
$subscription_end_date    = $subHelper->getEndDate();

// Sync sa session (para sa ibang pages)
$_SESSION['subscription_plan']       = $subscription_plan;
$_SESSION['subscription_trial_days'] = $subscription_trial_days;
$_SESSION['subscription_is_trial']   = $subscription_is_trial;

/* =================================
   ROLE CHECK (CLINIC SIDE ONLY)
================================= */
$user_role = trim($_SESSION['role'] ?? '');

$allowed_roles = [
    'ClinicAdmin',
    'Optometrist',
    'Staff',
    'HR',
    'Finance',
    'CRM',
    'SCM'
];

// SuperAdmin redirect
if ($user_role === 'SuperAdmin') {
    header('Location: admin/dashboard.php');
    exit();
}

// Block unknown roles
if (!in_array($user_role, $allowed_roles)) {
    session_destroy();
    header('Location: admin/login.php?error=clinic_access_only');
    exit();
}

/* =================================
   CLINIC STATUS GUARD
================================= */
$clinicRoles = ['ClinicAdmin', 'Staff'];

if (in_array($user_role, $clinicRoles)) {
    $clinic_status = $_SESSION['clinic_status'] ?? 'Pending';

    if ($clinic_status !== 'Active') {
        switch ($clinic_status) {
            case 'Pending':
                header('Location: views/clinic_pending.php');
                break;
            case 'Suspended':
                header('Location: views/clinic_suspended.php');
                break;
            case 'Rejected':
                header('Location: views/clinic_rejected.php');
                break;
        }
        exit();
    }
}

/* =================================
   DOCTOR SETUP FOR CLINICADMIN
================================= */
$hasDoctor = false;
$showDoctorPrompt = false;

if ($user_role === 'ClinicAdmin') {
    $stmt = $pdo->prepare("
        SELECT id FROM doctors 
        WHERE clinic_id = ? AND (user_id = ? OR name = ?)
    ");
    $fullName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
    $stmt->execute([$_SESSION['clinic_id'], $_SESSION['user_id'], $fullName]);
    $doctor = $stmt->fetch();
    
    if ($doctor) {
        $_SESSION['doctor_id'] = $doctor['id'];
        $hasDoctor = true;
    } else {
        $hasDoctor = false;
        $showDoctorPrompt = !isset($_SESSION['doctor_prompt_dismissed']);
    }
}

if ($user_role === 'Optometrist' && !isset($_SESSION['doctor_id'])) {
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ? AND clinic_id = ?");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['clinic_id']]);
    $doctor = $stmt->fetch();
    if ($doctor) {
        $_SESSION['doctor_id'] = $doctor['id'];
        $hasDoctor = true;
    }
}

/* =================================
   ✅ RBAC — DYNAMIC PERMISSION CHECK
================================= */
require_once __DIR__ . '/include/RBACHelper.php';
RBACHelper::init($pdo);

if (!isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession(
        (int)$_SESSION['user_id'],
        (int)$_SESSION['clinic_id']
    );
}

/* =================================
   DEFAULT VIEW — FULLY DYNAMIC
================================= */
if ($user_role === 'ClinicAdmin') {
    $defaultView = 'dashboard';
} else {
    $possibleDefaultViews = [
        'doctor_dashboard',
        'inventory',
        'purchase_orders',
        'purchase_requests',
        'supplier',
        'sales',
        'expenses',
        'users',
        'patients',
        'appointments',
        'crm_dashboard',
        'products',
        'reports',
        'settings',
        'dashboard'
    ];
    
    $defaultView = null;
    
    foreach ($possibleDefaultViews as $possibleView) {
        $requiredPermission = $possibleView . '_view';
        if (!RBACHelper::hasPermission($requiredPermission)) {
            continue;
        }
        $viewFileCheck = __DIR__ . "/views/{$possibleView}.php";
        if (file_exists($viewFileCheck)) {
            $defaultView = $possibleView;
            break;
        }
    }
    
    if ($defaultView === null) {
        $defaultView = 'dashboard';
    }
}

$view = $_GET['view'] ?? $defaultView;

/* =================================
   🔐 RBAC GUARD
================================= */
if (!RBACHelper::hasPermission($view . '_view')) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <style>
            body {
                background: linear-gradient(135deg, #ffffff 0%, #ffffff 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
            }
            .card {
                border-radius: 1rem;
                border: none;
                box-shadow: 0 10px 30px rgba(0, 128, 128, 0.15);
            }
            .teal-icon {
                color: #008080;
            }
            .permission-box {
                background-color: #f0f9f9;
                border-radius: 12px;
                padding: 15px;
                border-left: 4px solid #008080;
            }
            .btn-teal {
                background-color: #008080;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 8px;
                transition: all 0.3s ease;
            }
            .btn-teal:hover {
                background-color: #006666;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 128, 128, 0.3);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body text-center p-5">
                            <div class="display-1 mb-4">
                                <i class="bi bi-shield-lock teal-icon"></i>
                            </div>
                            <h2 class="mb-3" style="color: #008080;">Access Denied</h2>
                            <p class="text-muted mb-4">
                                You don't have permission to access the
                                <strong style="color: #008080;"><?php echo htmlspecialchars($view); ?></strong> module.
                            </p>
                            <div class="permission-box text-start mb-4">
                                <i class="bi bi-info-circle me-2" style="color: #008080;"></i>
                                <strong>Role:</strong> <?php echo htmlspecialchars($user_role); ?><br>
                                <strong>Required Permission:</strong> <?php echo htmlspecialchars($view . '_view'); ?>
                            </div>
                            <a href="admin/login.php" class="btn btn-teal">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Return to Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

/* =================================
   LOAD LAYOUT
================================= */
require_once 'include/header.php';
?>

<div class="wrapper d-flex">
    <?php require_once 'include/sidebar.php'; ?>

    <div class="main-content flex-fill">
        <?php require_once 'include/topbar.php'; ?>

        <main class="content-area p-4">
            
            <?php if ($user_role === 'ClinicAdmin' && !$hasDoctor && $showDoctorPrompt && $view !== 'doctor_dashboard'): ?>
            <!-- ✅ DOCTOR PROMPT — Slim inline card (redesigned) -->
            <div class="mb-4" id="doctorPrompt">
                <div class="card border-0 shadow-sm" style="border-radius: 16px; border-left: 4px solid #0d9488 !important; background: linear-gradient(90deg, #f0fdfa 0%, #ffffff 30%);">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="flex-shrink-0" style="width: 48px; height: 48px; border-radius: 12px; background: #ccfbf1; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-person-badge" style="color: #0d9488; font-size: 1.4rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong style="color: #0f172a;">Are you also a doctor?</strong>
                                    <span class="badge" style="background: #ccfbf1; color: #0d9488; font-size: 0.65rem; font-weight: 600;">Optional</span>
                                </div>
                                <div class="text-muted small">
                                    Set up your doctor profile to access the Doctor Dashboard and manage patient queue.
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <button class="btn btn-sm" style="background: #0d9488; color: white; border-radius: 8px; font-weight: 600; padding: 8px 16px;" onclick="showDoctorSetupModal()">
                                    <i class="bi bi-plus-circle me-1"></i>Set Up
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; padding: 8px 12px;" onclick="dismissDoctorPrompt()" title="Dismiss">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $viewFile = __DIR__ . "/views/{$view}.php";
            if (file_exists($viewFile)) {
                require $viewFile;
            } else {
                require __DIR__ . '/views/dashboard.php';
            }
            ?>
        </main>
    </div>
</div>

<!-- ✅ TRIAL ENDING MODAL — Center popup, once per session -->
<?php if ($subscription_is_trial && $subscription_trial_days <= 7): ?>
<?php
    // Urgency logic
    $isToday      = ($subscription_trial_days <= 0);
    $isCritical   = ($subscription_trial_days <= 2);
    $modalColor   = $isCritical ? '#dc2626' : '#d97706';
    $modalBgLight = $isCritical ? '#fef2f2' : '#fef3c7';
    $modalIcon    = $isToday ? 'bi-exclamation-octagon-fill' : ($isCritical ? 'bi-exclamation-triangle-fill' : 'bi-hourglass-split');
    
    if ($isToday) {
        $trialText = 'Your <strong>' . ucfirst($subscription_plan) . '</strong> trial <strong class="text-danger">ends today</strong>.';
        $subtext   = 'Upgrade now to avoid losing access to your features.';
    } elseif ($subscription_trial_days === 1) {
        $trialText = 'Your <strong>' . ucfirst($subscription_plan) . '</strong> trial ends <strong class="text-warning">tomorrow</strong>.';
        $subtext   = 'You have <strong>1 day</strong> left. Upgrade to keep your premium features.';
    } else {
        $trialText = 'Your <strong>' . ucfirst($subscription_plan) . '</strong> trial ends in <strong>' . $subscription_trial_days . ' days</strong>.';
        $subtext   = 'Upgrade now to keep your premium features without interruption.';
    }
?>
<div class="modal fade" id="trialEndingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; overflow: hidden;">
            
            <div style="background: linear-gradient(135deg, <?php echo $modalColor; ?>, <?php echo $isCritical ? '#991b1b' : '#b45309'; ?>); padding: 32px 24px 24px; text-align: center; color: white;">
                <div style="font-size: 3.5rem; line-height: 1; margin-bottom: 12px;">
                    <i class="bi <?php echo $modalIcon; ?>"></i>
                </div>
                <h4 class="mb-1" style="font-weight: 800; color: white;">
                    <?php echo $isToday ? 'Trial Expires Today!' : ($isCritical ? 'Trial Ending Very Soon' : 'Trial Ending Soon'); ?>
                </h4>
                <p class="mb-0" style="opacity: 0.9; font-size: 0.9rem;">
                    <?php echo $isCritical ? 'Immediate action recommended' : 'Plan ahead to avoid interruption'; ?>
                </p>
            </div>
            
            <div class="modal-body p-4 text-center">
                <p class="mb-3" style="font-size: 1.05rem;">
                    <?php echo $trialText; ?>
                </p>
                <p class="text-muted small mb-4">
                    <?php echo $subtext; ?>
                </p>
                
                <div class="d-inline-flex align-items-center gap-2 px-4 py-3 rounded-3 mb-4"
                     style="background: <?php echo $modalBgLight; ?>; border: 2px solid <?php echo $modalColor; ?>30;">
                    <i class="bi bi-calendar-event" style="color: <?php echo $modalColor; ?>; font-size: 1.5rem;"></i>
                    <div class="text-start">
                        <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; color: <?php echo $modalColor; ?>;">
                            <?php echo $isToday ? 'Expires' : 'Days Left'; ?>
                        </div>
                        <div style="font-size: 1.6rem; font-weight: 800; color: <?php echo $modalColor; ?>; line-height: 1;">
                            <?php echo $isToday ? 'Today' : $subscription_trial_days; ?>
                        </div>
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <!-- ✅ FIXED: Redirect to subscription_manage.php (renew/upgrade page) -->
                    <a href="views/subscription_manage.php" class="btn btn-lg fw-bold" 
                       style="background: <?php echo $modalColor; ?>; color: white; border-radius: 12px; padding: 14px;">
                        <i class="bi bi-rocket-takeoff me-2"></i>Upgrade Now
                    </a>
                    <button type="button" class="btn btn-link text-muted btn-sm" onclick="dismissTrialModal()">
                        Remind me later
                    </button>
                </div>
                
                <p class="text-muted mb-0 mt-3" style="font-size: 0.75rem;">
                    <i class="bi bi-shield-check me-1"></i>No hidden fees · Cancel anytime
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Doctor Setup Modal -->
<div class="modal fade" id="doctorSetupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Doctor Profile Setup</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>You'll be added as a doctor with these details:</p>
                <table class="table table-sm">
                    <tr><th>Name:</th><td><strong><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></strong></td></tr>
                    <tr><th>Email:</th><td><?= htmlspecialchars($_SESSION['email'] ?? '') ?></td></tr>
                    <tr><th>Role:</th><td>ClinicAdmin / Doctor</td>
                </table>
                <div class="mb-3">
                    <label class="form-label">Specialty <span class="text-danger">*</span></label>
                    <select class="form-select" id="doctor_specialty">
                        <option value="Optometrist">Optometrist</option>
                        <option value="Ophthalmologist">Ophthalmologist</option>
                        <option value="Optician">Optician</option>
                    </select>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    After setup, you'll have access to the Doctor Dashboard.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmDoctorSetup()">
                    <i class="bi bi-check-lg me-2"></i>Create Doctor Profile
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let doctorModal = null;

document.addEventListener('DOMContentLoaded', function() {
    // Doctor modal
    const modalEl = document.getElementById('doctorSetupModal');
    if (modalEl) doctorModal = new bootstrap.Modal(modalEl);
    
    // ✅ Trial ending modal — once per session
    const trialModalEl = document.getElementById('trialEndingModal');
    if (trialModalEl) {
        const dismissed = sessionStorage.getItem('trial_modal_dismissed');
        if (!dismissed) {
            setTimeout(() => {
                const tm = new bootstrap.Modal(trialModalEl);
                tm.show();
            }, 800);
        }
    }
});

function dismissTrialModal() {
    sessionStorage.setItem('trial_modal_dismissed', '1');
    const modalEl = document.getElementById('trialEndingModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();
}

function showDoctorSetupModal() {
    if (doctorModal) doctorModal.show();
}

function dismissDoctorPrompt() {
    const prompt = document.getElementById('doctorPrompt');
    if (prompt) {
        prompt.style.transition = 'opacity 0.3s, transform 0.3s';
        prompt.style.opacity = '0';
        prompt.style.transform = 'translateY(-8px)';
        setTimeout(() => prompt.remove(), 300);
    }
    fetch('api/dismiss_doctor_prompt.php', { method: 'POST' });
}

function confirmDoctorSetup() {
    const specialty = document.getElementById('doctor_specialty').value;
    Swal.fire({ title: 'Creating Doctor Profile...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    fetch('api/setup_doctor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ specialty })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Success!', text: 'Doctor profile created.', timer: 2000, showConfirmButton: false })
                .then(() => { if (doctorModal) doctorModal.hide(); window.location.href = '?view=doctor_dashboard'; });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to create doctor profile' });
        }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' }));
}
</script>

<?php require_once 'include/footer.php'; ?>