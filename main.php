<?php
// main.php - Clinic Dashboard with Two-Layer Access Control + Doctor Setup + SUBSCRIPTION CHECK

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
   ✅ SUBSCRIPTION CHECK - ADDED HERE
   Kailangan may active subscription para makapasok sa main.php
================================= */
require_once __DIR__ . '/include/SubscriptionHelper.php';

$clinic_id = $_SESSION['clinic_id'] ?? 0;
$subHelper = new SubscriptionHelper($pdo, $clinic_id);

// Check if clinic has active subscription
if (!$subHelper->hasActiveSubscription()) {
    // No active subscription, redirect to subscription page
    header('Location: views/subscription.php');
    exit();
}

// Optional: Store subscription info in session for quick access
$_SESSION['subscription_plan'] = $subHelper->getCurrentPlan();
$_SESSION['subscription_trial_days'] = $subHelper->getTrialDaysLeft();
$_SESSION['subscription_is_trial'] = $subHelper->isTrial();

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

// SuperAdmin redirect (separate system)
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
   Pinapalitan ang lumang canView() na
   hardcoded sa auth_functions.php
================================= */
require_once __DIR__ . '/include/RBACHelper.php';
RBACHelper::init($pdo);

// Load permissions into session kung wala pa
// (backup — dapat naka-load na siya from login)
if (!isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession(
        (int)$_SESSION['user_id'],
        (int)$_SESSION['clinic_id']
    );
}

/* =================================
   DEFAULT VIEW PER ROLE
================================= */
$roleDefaultView = [
    'ClinicAdmin' => 'dashboard',
    'Staff'       => 'dashboard',
    'Optometrist' => 'doctor_dashboard',
    'HR'          => 'users',
    'Finance'     => 'sales',
    'SCM'         => 'inventory',
    'CRM'         => 'patients'
];

$view = $_GET['view'] ?? $roleDefaultView[$user_role] ?? 'dashboard';

/* =================================
   🔐 RBAC GUARD — DYNAMIC NA!
   ClinicAdmin/SuperAdmin = auto-bypass
   Lahat ng iba = tsinecheck sa user_roles
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
   LOAD LAYOUT — WALANG BINAGO DITO
================================= */
require_once 'include/header.php';
?>

<div class="wrapper d-flex">
    <?php require_once 'include/sidebar.php'; ?>

    <div class="main-content flex-fill">
        <?php require_once 'include/topbar.php'; ?>

        <main class="content-area p-4">
            
            <!-- ✅ ADD SUBSCRIPTION BANNER (Optional) - Shows trial days left -->
            <?php if ($_SESSION['subscription_is_trial'] && $_SESSION['subscription_trial_days'] <= 7): ?>
            <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-hourglass-split fs-1 text-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <strong>Trial Ending Soon!</strong> Your <?php echo ucfirst($_SESSION['subscription_plan']); ?> plan trial ends in 
                        <strong><?php echo $_SESSION['subscription_trial_days']; ?> days</strong>. 
                        <a href="views/upgrade.php" class="alert-link">Upgrade now</a> to keep your premium features.
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($user_role === 'ClinicAdmin' && !$hasDoctor && $showDoctorPrompt && $view !== 'doctor_dashboard'): ?>
            <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert" id="doctorPrompt">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="bi bi-person-badge fs-1 text-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading">Are you also a doctor?</h5>
                        <p class="mb-2">As a Clinic Owner, you can also perform eye examinations. Set up your doctor profile to access the Doctor Dashboard and see patient queue.</p>
                        <div class="d-flex gap-2">
                            <button class="btn btn-warning" onclick="showDoctorSetupModal()">
                                <i class="bi bi-check-circle me-2"></i>Yes, I'm a doctor - Set up now
                            </button>
                            <button class="btn btn-outline-secondary" onclick="dismissDoctorPrompt()">
                                <i class="bi bi-x me-2"></i>No, thanks
                            </button>
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

<!-- Doctor Setup Modal — walang binago -->
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
    const modalEl = document.getElementById('doctorSetupModal');
    if (modalEl) doctorModal = new bootstrap.Modal(modalEl);
});

function showDoctorSetupModal() {
    if (doctorModal) doctorModal.show();
}

function dismissDoctorPrompt() {
    const prompt = document.getElementById('doctorPrompt');
    if (prompt) prompt.style.display = 'none';
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

setTimeout(function() {
    const alert = document.getElementById('doctorPrompt');
    if (alert) { alert.style.transition = 'opacity 0.5s'; alert.style.opacity = '0'; setTimeout(() => alert.style.display = 'none', 500); }
}, 10000);
</script>

<?php require_once 'include/footer.php'; ?>