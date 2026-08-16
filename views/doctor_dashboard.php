<?php
ob_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

// ✅ RBAC Permission Check - MUST HAVE DOCTOR DASHBOARD VIEW PERMISSION
if (!RBACHelper::hasPermission('doctor_dashboard_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view the Doctor Dashboard.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId  = $_SESSION['clinic_id'];
$userId    = $_SESSION['user_id'];
$userRole  = $_SESSION['role'] ?? '';

$canView = RBACHelper::hasPermission('doctor_dashboard_view');
$canCreateAppointments = RBACHelper::hasPermission('appointments_create');
$canEditAppointments = RBACHelper::hasPermission('appointments_edit');
$canCreateClinicalNotes = RBACHelper::hasPermission('doctor_dashboard_create');
$canEditClinicalNotes = RBACHelper::hasPermission('doctor_dashboard_edit');
$canCreatePrescriptions = RBACHelper::hasPermission('doctor_dashboard_create');
$canEditPrescriptions = RBACHelper::hasPermission('doctor_dashboard_edit');
$canViewHistory = RBACHelper::hasPermission('patient_history_view');
// Get doctor_id from session or fetch
$doctorId = $_SESSION['doctor_id'] ?? null;

// If user has permission but no doctor_id (for non-Optometrist roles), they can still view
if ($userRole === 'Optometrist' && !$doctorId) {
    $fullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE clinic_id = ? AND name = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$clinicId, $fullName]);
    $doc = $stmt->fetch();
    $doctorId = $doc ? $doc['id'] : null;
    $_SESSION['doctor_id'] = $doctorId;
}

// Get all doctors for users who can manage (ClinicAdmin or users with manage permission)
$allDoctors = [];
if ($userRole === 'ClinicAdmin' || RBACHelper::hasPermission('doctor_dashboard_manage')) {
    $stmt = $pdo->prepare("SELECT id, name, specialty FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$clinicId]);
    $allDoctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$currentDoctor = null;
foreach ($allDoctors as $doc) {
    if ($doc['id'] == $doctorId) {
        $currentDoctor = $doc;
        break;
    }
}
?>

<script>
    const baseUrl = 'http://eyecore.capstone001.com/';
    const clinicId        = <?= $clinicId ?>;
    const currentDoctorId = <?= json_encode($doctorId) ?>;
    let currentUserRole   = '<?php echo $_SESSION['role'] ?? ''; ?>';
    
    // ✅ RBAC Permissions from PHP
    const permissions = {
        canView: <?= json_encode($canView) ?>,
        canCreateAppointments: <?= json_encode($canCreateAppointments) ?>,
        canEditAppointments: <?= json_encode($canEditAppointments) ?>,
        canCreateClinicalNotes: <?= json_encode($canCreateClinicalNotes) ?>,
        canEditClinicalNotes: <?= json_encode($canEditClinicalNotes) ?>,
        canCreatePrescriptions: <?= json_encode($canCreatePrescriptions) ?>,
        canEditPrescriptions: <?= json_encode($canEditPrescriptions) ?>,
        canViewHistory: <?= json_encode($canViewHistory) ?>
    };
    
    // ✅ Permission check functions
    function hasPermission(permissionName) {
        return permissions[permissionName] === true;
    }
    
    function canViewDoctorDashboard() {
        return permissions.canView;
    }
    
    function canCreateAppointments() {
        return permissions.canCreateAppointments;
    }
    
    function canEditAppointments() {
        return permissions.canEditAppointments;
    }
    
    function canCreateClinicalNotes() {
        return permissions.canCreateClinicalNotes;
    }
    
    function canEditClinicalNotes() {
        return permissions.canEditClinicalNotes;
    }
    
    function canCreatePrescriptions() {
        return permissions.canCreatePrescriptions;
    }
    
    function canEditPrescriptions() {
        return permissions.canEditPrescriptions;
    }
    
    function canViewHistory() {
        return permissions.canViewHistory;
    }
    
    console.log('RBAC Permissions:', permissions);
</script>

<style>
/* ── TEAL PALETTE ── */
:root {
    --teal:       #0d9488;
    --teal-dark:  #0f766e;
    --teal-light: #ccfbf1;
    --teal-soft:  #f0fdfa;
}

#doctorDashboard { font-size: .875rem; }

/* ── SHELL: queue left, workspace right ── */
.dd-shell {
    display: flex;
    height: calc(100vh - 64px); /* adjust to your topbar height */
    overflow: hidden;
    background: #f1f5f9;
}

/* ── QUEUE PANEL ── */
.dd-queue {
    width: 290px;
    min-width: 290px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
}
.dd-queue-head   { padding: 12px 12px 8px; border-bottom: 1px solid #f1f5f9; }
.dd-queue-acts   { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 6px; }
.dd-queue-scroll { flex: 1; overflow-y: auto; padding: 7px 10px 20px; }
.dd-queue-scroll::-webkit-scrollbar { width: 4px; }
.dd-queue-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

/* Mini stat chips */
.dd-stats { display: flex; gap: 5px; }
.dd-stat  { flex: 1; text-align: center; padding: 5px 2px; border-radius: 8px; border: 1px solid transparent; }
.dd-stat .sv { font-size: 1.05rem; font-weight: 800; line-height: 1; }
.dd-stat .sl { font-size: .6rem; margin-top: 1px; }
.dd-stat.s-wait { background: #fef9e7; border-color: #fde68a; }
.dd-stat.s-wait .sv { color: #d97706; }
.dd-stat.s-mine { background: var(--teal-soft); border-color: #99f6e4; }
.dd-stat.s-mine .sv { color: var(--teal); }
.dd-stat.s-any  { background: #f0fbff; border-color: #bae6fd; }
.dd-stat.s-any  .sv { color: #0284c7; }
.dd-stat.s-done { background: #f0fdf4; border-color: #bbf7d0; }
.dd-stat.s-done .sv { color: #16a34a; }

/* Queue section labels */
.q-lbl {
    font-size: .65rem; font-weight: 800;
    letter-spacing: .08em; text-transform: uppercase;
    padding: 9px 4px 3px; color: #94a3b8;
}

/* Queue items */
.queue-item {
    border: 1px solid #e8edf2;
    border-left: 3px solid transparent;
    border-radius: 10px;
    padding: 9px 10px;
    margin-bottom: 5px;
    background: #fff;
    cursor: pointer;
    transition: box-shadow .15s, transform .15s;
}
.queue-item:hover           { box-shadow: 0 4px 12px rgba(0,0,0,.07); transform: translateY(-1px); }
.queue-item.waiting         { border-left-color: #f59e0b; }
.queue-item.in-progress     { border-left-color: var(--teal); background: var(--teal-soft); }
.queue-item.completed       { border-left-color: #10b981; opacity: .83; }
.queue-item.any-doctor      { border-left-color: #0ea5e9; background: #f0fbff; }
.queue-item .qn             { font-weight: 700; font-size: .83rem; color: #1e293b; }
.queue-item .qs             { font-size: .72rem; color: #64748b; }

/* ── WORKSPACE ── */
.dd-ws { flex: 1; overflow-y: auto; padding: 14px 18px 30px; }
.dd-ws::-webkit-scrollbar { width: 5px; }
.dd-ws::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.dd-empty { height: 100%; display: flex; align-items: center; justify-content: center; }

/* ── PATIENT CARD ── */
.pt-card { border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,.06); }

.pt-hdr {
    background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%);
    padding: 16px 20px 14px; color: #fff;
}
.pt-name  { font-size: 1.3rem; font-weight: 800; line-height: 1.1; }
.pt-timer {
    font-family: 'Courier New', monospace;
    font-size: 1.45rem; font-weight: 700;
    background: rgba(255,255,255,.18);
    border-radius: 8px; padding: 2px 11px;
}
.pt-pill {
    background: rgba(255,255,255,.18);
    border-radius: 20px; padding: 2px 9px;
    font-size: .73rem; display: inline-block;
}
.pt-detail-row {
    display: flex;
    border-top: 1px solid rgba(255,255,255,.15);
    margin-top: 11px; padding-top: 11px;
}
.pt-di { flex: 1; }
.pt-di + .pt-di { border-left: 1px solid rgba(255,255,255,.18); padding-left: 14px; }
.pt-di .dl { font-size: .62rem; opacity: .62; text-transform: uppercase; letter-spacing: .06em; }
.pt-di .dv { font-size: .86rem; font-weight: 700; }

.pt-alert {
    background: rgba(255,255,255,.14);
    border: 1px solid rgba(255,255,255,.28);
    border-radius: 8px; padding: 7px 11px;
    margin-top: 9px; display: flex;
    align-items: center; gap: 9px; font-size: .79rem;
}

/* ── BODY ── */
.pt-body { background: #fff; }
.exam-pane { padding: 14px; border-right: 1px solid #f1f5f9; }
.form-label { font-weight: 600; font-size: .77rem; color: #475569; margin-bottom: 3px; }
.form-control-sm, .form-select-sm { border-color: #e2e8f0; border-radius: 7px; font-size: .79rem; }
.form-control-sm:focus, .form-select-sm:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(13,148,136,.12);
}

/* Tabs */
.pt-tabs { border-bottom: 2px solid #f1f5f9; background: #fafbfc; padding: 0 10px; }
.pt-tabs .nav-link {
    font-size: .78rem; font-weight: 600; color: #64748b;
    border: none; border-bottom: 2px solid transparent;
    padding: 8px 11px; margin-bottom: -2px; border-radius: 0;
}
.pt-tabs .nav-link.active { color: var(--teal); border-bottom-color: var(--teal); background: transparent; }
.pt-tabs .nav-link:hover  { color: var(--teal); }

/* History */
.hist-item { border: 1px solid #f1f5f9; border-radius: 8px; padding: 8px 10px; margin-bottom: 5px; transition: background .15s; }
.hist-item:hover { background: #f8fafc; }
.hist-scroll { max-height: 240px; overflow-y: auto; }
.hist-scroll::-webkit-scrollbar { width: 3px; }
.hist-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

/* Rx eye boxes */
.rx-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 9px 10px; }
.rx-lbl { font-size: .68rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--teal); margin-bottom: 7px; }

/* Teal buttons */
.btn-teal { background: var(--teal); color: #fff; border: none; border-radius: 8px; font-weight: 600; }
.btn-teal:hover { background: var(--teal-dark); color: #fff; }
.btn-outline-teal { border: 1px solid var(--teal); color: var(--teal); background: transparent; border-radius: 8px; font-weight: 600; }
.btn-outline-teal:hover { background: var(--teal-soft); color: var(--teal-dark); }

/* Upcoming appointments styling */
.upcoming-date-group {
    margin-bottom: 1rem;
}

.upcoming-date-group .date-header {
    font-size: 0.7rem;
    font-weight: 600;
    color: #334155;
    padding-left: 4px;
    margin-bottom: 8px;
}

.upcoming-item {
    border-left: 3px solid #cbd5e1 !important;
    background: #ffffff;
    transition: all 0.2s ease;
}

.upcoming-item:hover {
    background: #f8fafc;
    transform: translateX(2px);
}

.queue-section .section-header {
    border-bottom: 2px solid #e2e8f0;
}

.bg-teal-light {
    background-color: #ccfbf1;
    color: #0d9488;
}

.sub-section .sub-header {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    color: #64748b;
    margin-bottom: 6px;
}

@media (max-width: 768px) {
    .dd-shell { flex-direction: column; height: auto; }
    .dd-queue { width: 100%; min-width: unset; height: 250px; border-right: none; border-bottom: 1px solid #e2e8f0; }
}
</style>

<!-- ═══════════════════════════════════════
     DASHBOARD ROOT
═══════════════════════════════════════ -->
<div id="doctorDashboard">
<div class="dd-shell">

    <aside class="dd-queue">
        <div class="dd-queue-head">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-700" style="font-size:.88rem;color:#1e293b;">Today's Queue</span>
                <button class="btn btn-sm btn-teal px-3 py-1" style="font-size:.73rem;" onclick="refreshQueue()">
                    <i class="bi bi-arrow-repeat me-1"></i>Refresh
                </button>
            </div>
<div class="dd-stats">
    <div class="dd-stat s-wait">
        <div class="sv" id="statWaiting">0</div>
        <div class="sl">Waiting</div>
    </div>
    <div class="dd-stat s-mine">
        <div class="sv" id="statProgress">0</div>
        <div class="sl">In Progress</div>
    </div>
    <div class="dd-stat s-payment">
        <div class="sv" id="statWaitingPayment">0</div>
        <div class="sl">Need Payment</div>
    </div>
    <div class="dd-stat s-any">
        <div class="sv" id="statUpcoming">0</div>
        <div class="sl">Upcoming</div>
    </div>
    <div class="dd-stat s-done">
        <div class="sv" id="statCompleted">0</div>
        <div class="sl">Completed</div>
    </div>
</div>
        </div>

        <div class="dd-queue-acts">
            <?php if ($canCreateAppointments): ?>
            <button class="btn btn-sm btn-success flex-fill fw-600" style="border-radius:8px;" onclick="showWalkInModal()">
                <i class="bi bi-person-walking me-1"></i>Walk-in
            </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary flex-fill fw-600" style="border-radius:8px;" onclick="showSearchModal()">
                <i class="bi bi-search me-1"></i>Find
            </button>
        </div>

        <div class="dd-queue-scroll" id="queueContainer">
            <div class="text-center py-5">
                <div class="spinner-border mb-2" style="width:1.3rem;height:1.3rem;color:var(--teal);"></div>
                <p class="text-muted small mb-0">Loading queue…</p>
            </div>
        </div>
    </aside>

    <!-- ══ RIGHT: WORKSPACE ══ -->
    <div class="dd-ws">

        <div class="dd-empty" id="noPatientPlaceholder">
            <div class="text-center">
                <div style="width:64px;height:64px;background:var(--teal-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                    <i class="bi bi-person-bounding-box" style="font-size:1.6rem;color:var(--teal);"></i>
                </div>
                <h5 class="fw-700" style="color:#1e293b;">No Patient Selected</h5>
                <p class="text-muted small mb-4">Select from the queue, or add a walk-in patient</p>
                <?php if ($canCreateAppointments): ?>
                <button class="btn btn-teal px-4" onclick="showWalkInModal()">
                    <i class="bi bi-person-walking me-2"></i>Add Walk-in
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Patient card -->
        <div id="patientCard" style="display:none;">

            <!-- Hidden form inputs -->
            <form id="examForm" style="display:none;">
                <input type="hidden" id="currentAppointmentId">
                <input type="hidden" id="currentPatientId">
                <input type="hidden" id="currentUserId">
                <input type="hidden" id="isNewPatient" value="0">
                <input type="hidden" id="isAnyDoctor" value="0">
            </form>

            <div class="pt-card">

                <!-- Header -->
<div class="pt-hdr">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <div class="pt-name" id="patientName">—</div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="pt-pill"><i class="bi bi-calendar me-1"></i><span id="patientAge">—</span> yrs</span>
                <span class="pt-pill"><i class="bi bi-gender-ambiguous me-1"></i><span id="patientGender">—</span></span>
                <span class="pt-pill" id="patientType">Regular</span>
                <span class="badge bg-success ms-1" id="newPatientBadge" style="display:none;">New Patient</span>
            </div>
        </div>
        <div class="text-end">
            <div class="pt-timer" id="timer">00:00</div>
            <div style="font-size:.62rem;opacity:.6;margin-top:2px;">consultation</div>
        </div>
    </div>

    <!-- ✅ ADD THIS START CONSULTATION BUTTON HERE -->
    <div id="startConsultationBtnContainer" class="mt-3" style="display: none;">
        <button class="btn btn-light w-100 fw-600" onclick="startConsultation()" 
                style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);">
            <i class="bi bi-play-fill me-2"></i> Start Consultation
        </button>
    </div>

    <div class="pt-detail-row">
<!-- Sa header area ng patient card -->
<div class="pt-di">
    <div class="dl">Services</div>
    <div class="dv" id="serviceName">—</div>
    <div id="billItemsList" class="mt-1" style="font-size:.75rem; display:none;"></div>
    <div id="billTotal" class="mt-1 fw-700" style="font-size:.8rem; display:none;">
        Total: <span id="billTotalAmount">₱0.00</span>
    </div>
</div>
        <div class="pt-di">
            <div class="dl">Doctor</div>
            <div class="dv" id="doctorName">—</div>
        </div>
        <div class="pt-di">
            <div class="dl">Time</div>
            <div class="dv" id="appointmentTime">—</div>
        </div>
    </div>

    <div id="anyDoctorAlert" style="display:none;">
        <div class="pt-alert">
            <i class="bi bi-people-fill fs-5"></i>
            <div class="flex-grow-1"><strong>Any Doctor</strong> — Claim to begin</div>
            <button class="btn btn-sm btn-light fw-600" onclick="claimAppointment()">Claim</button>
        </div>
    </div>
    <div id="newPatientAlert" style="display:none;">
        <div class="pt-alert">
            <i class="bi bi-person-plus-fill fs-5"></i>
            <div class="flex-grow-1"><strong>New Patient</strong> — Save after consultation</div>
            <button class="btn btn-sm btn-light fw-600" onclick="showSavePatientModal()">Save</button>
        </div>
    </div>
</div>

                <!-- Body -->
                <div class="pt-body">
                    <div class="row g-0">

                        <!-- Exam form -->
                        <div class="col-md-5 exam-pane">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <i class="bi bi-eyeglasses" style="color:var(--teal);font-size:1rem;"></i>
                                <span class="fw-700" style="color:#1e293b;font-size:.85rem;">Examination</span>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Chief Complaint</label>
                                <textarea class="form-control form-control-sm" id="chiefComplaint" rows="2" placeholder="Patient's main concern…"></textarea>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label">VA Left</label>
                                    <input type="text" class="form-control form-control-sm" id="vaLeft" placeholder="20/20">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">VA Right</label>
                                    <input type="text" class="form-control form-control-sm" id="vaRight" placeholder="20/25">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Findings</label>
                                <textarea class="form-control form-control-sm" id="findings" rows="2" placeholder="Examination findings…"></textarea>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label">Diagnosis</label>
                                    <input type="text" class="form-control form-control-sm" id="diagnosis" placeholder="e.g. Myopia">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Treatment</label>
                                    <input type="text" class="form-control form-control-sm" id="treatmentPlan" placeholder="e.g. Prescribe glasses">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control form-control-sm" id="notes" rows="2" placeholder="Additional remarks…"></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <?php if ($canCreateClinicalNotes || $canEditClinicalNotes): ?>
                                <button type="button" class="btn btn-success btn-sm flex-fill fw-600" style="border-radius:8px;" onclick="saveAndComplete()">
                                    <i class="bi bi-check-lg me-1"></i>Save & Complete
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="border-radius:8px;" onclick="saveOnly()" title="Save only">
                                    <i class="bi bi-save"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <div class="col-md-7">
                            <ul class="nav pt-tabs" id="patientTabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#prescriptionTab" id="prescriptionTabLink">
                                        <i class="bi bi-prescription2 me-1"></i>Rx
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#historyTab">
                                        <i class="bi bi-clock-history me-1"></i>History
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-bs-toggle="tab" href="#decisionTab">
                                        <i class="bi bi-graph-up me-1"></i>AI Analysis
                                    </a>
                                </li>
                            </ul>

                            <div class="tab-content p-3">

                                <!-- PRESCRIPTION TAB -->
                                <div class="tab-pane fade" id="prescriptionTab">
                                    <form id="rxForm">
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <div class="rx-box">
                                                    <div class="rx-lbl">Right Eye (OD)</div>
                                                    <div class="row g-1 mb-1">
                                                        <div class="col-6">
                                                            <label class="form-label">Sphere</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxSphR" placeholder="-2.00">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Cylinder</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxCylR" placeholder="-0.50">
                                                        </div>
                                                    </div>
                                                    <div class="row g-1">
                                                        <div class="col-6">
                                                            <label class="form-label">Axis</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxAxisR" placeholder="180">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Add</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxAddR" placeholder="+2.00">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="rx-box">
                                                    <div class="rx-lbl">Left Eye (OS)</div>
                                                    <div class="row g-1 mb-1">
                                                        <div class="col-6">
                                                            <label class="form-label">Sphere</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxSphL" placeholder="-1.75">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Cylinder</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxCylL" placeholder="-0.25">
                                                        </div>
                                                    </div>
                                                    <div class="row g-1">
                                                        <div class="col-6">
                                                            <label class="form-label">Axis</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxAxisL" placeholder="175">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label">Add</label>
                                                            <input type="text" class="form-control form-control-sm" id="rxAddL" placeholder="+2.00">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label class="form-label">PD</label>
                                                <input type="text" class="form-control form-control-sm" id="rxPd" placeholder="62mm">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">PD Type</label>
                                                <select class="form-select form-select-sm" id="rxPdType">
                                                    <option value="Binocular">Binocular</option>
                                                    <option value="Monocular">Monocular</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Rx Notes</label>
                                            <textarea class="form-control form-control-sm" id="rxNotes" rows="2" placeholder="Lens type, coating…"></textarea>
                                        </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($canCreatePrescriptions || $canEditPrescriptions): ?>
                                        <button type="button" class="btn btn-teal btn-sm flex-fill" onclick="savePrescriptionOnly()">
                                            <i class="bi bi-save me-1"></i>Save Rx
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-teal btn-sm w-100 mt-1" onclick="printPrescription()">
                                            <i class="bi bi-printer me-1"></i>Print Prescription
                                        </button>
                                    </div>
                                    </form>
                                </div>

                                <!-- HISTORY TAB -->
                                <div class="tab-pane fade show active" id="historyTab">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="fw-700" style="font-size:.81rem;color:#1e293b;">
                                            <i class="bi bi-clock-history me-1" style="color:var(--teal);"></i>Visit History
                                        </span>
                                        <?php if ($canViewHistory): ?>
                                        <button class="btn btn-sm btn-outline-teal px-2 py-1" style="font-size:.73rem;" onclick="viewFullHistory(currentPatientId)" id="viewFullHistoryBtn">
                                            View All <i class="bi bi-arrow-right ms-1"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>

                                    <div id="historySidebar" class="hist-scroll">
                                        <div class="text-center py-4 text-muted">
                                            <i class="bi bi-arrow-left-circle d-block mb-1 opacity-30" style="font-size:1.5rem;"></i>
                                            <small>Select a patient to view history</small>
                                        </div>
                                    </div>

                                    <div class="mt-3 pt-2 border-top">
                                        <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;" class="mb-1">Last Prescription</div>
                                        <div id="lastRxSummary" class="small text-muted">No previous prescription</div>
                                    </div>
                                </div>

                                <!-- AI ANALYSIS TAB -->
                                <div class="tab-pane fade" id="decisionTab">
                                    <div id="decisionContent">
                                        <div class="text-center py-4">
                                            <div class="spinner-border mb-2" id="decisionLoader" style="display:none;width:1.3rem;height:1.3rem;color:var(--teal);"></div>
                                            <div id="decisionResults">
                                                <i class="bi bi-graph-up-arrow d-block mb-2 opacity-25" style="font-size:1.8rem;color:var(--teal);"></i>
                                                <p class="text-muted small">Select a patient to see AI analysis</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div><!-- /tab-content -->
                        </div><!-- /col tabs -->
                    </div><!-- /row -->
                </div><!-- /pt-body -->
            </div><!-- /pt-card -->
        </div><!-- /patientCard -->
    </div><!-- /dd-ws -->
</div><!-- /dd-shell -->
</div><!-- /doctorDashboard -->

<!-- ═══════════════════════════════════════
     HISTORY MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="historyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background:var(--teal);color:#fff;">
                <h5 class="modal-title fw-700"><i class="bi bi-clock-history me-2"></i>Complete Patient History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 rounded-3 mb-4" style="background:#f8fafc;border:1px solid #e2e8f0;" id="modalPatientInfo">
                    <h5 class="fw-700 mb-1" id="modalPatientName">—</h5>
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                        <span><i class="bi bi-calendar me-1"></i><span id="modalPatientAge">—</span> yrs</span>
                        <span><i class="bi bi-gender-ambiguous me-1"></i><span id="modalPatientGender">—</span></span>
                        <span><i class="bi bi-tag me-1"></i><span id="modalPatientType">—</span></span>
                        <span><i class="bi bi-telephone me-1"></i><span id="modalPatientPhone">—</span></span>
                    </div>
                </div>
                <ul class="nav nav-tabs" id="historyModalTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#clinicalNotesTab"><i class="bi bi-file-medical me-1"></i>Clinical Notes</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#prescriptionsTab"><i class="bi bi-prescription2 me-1"></i>Prescriptions</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#appointmentsTab"><i class="bi bi-calendar-check me-1"></i>Appointments</a></li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="clinicalNotesTab">
                        <div id="clinicalNotesContent" class="p-2"><div class="text-center py-4"><div class="spinner-border" style="color:var(--teal);"></div></div></div>
                    </div>
                    <div class="tab-pane fade" id="prescriptionsTab">
                        <div id="prescriptionsContent" class="p-2"><div class="text-center py-4"><div class="spinner-border" style="color:var(--teal);"></div></div></div>
                    </div>
                    <div class="tab-pane fade" id="appointmentsTab">
                        <div id="appointmentsContent" class="p-2"><div class="text-center py-4"><div class="spinner-border" style="color:var(--teal);"></div></div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-teal" onclick="exportPatientData(currentPatientId)">
                    <i class="bi bi-download me-2"></i>Export Data
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     WALK-IN MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="walkInModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background:#16a34a;color:#fff;">
                <h5 class="modal-title fw-700"><i class="bi bi-person-walking me-2"></i>Walk-in Patient</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="p-3 rounded-3 mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                    <h6 class="fw-700 mb-2 small"><i class="bi bi-search text-success me-2"></i>Search Existing Patient</h6>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="walkinSearch" placeholder="Type name, contact, or email…">
                        <button class="btn btn-success" type="button" onclick="searchWalkinPatient()"><i class="bi bi-search"></i></button>
                    </div>
                    <div id="walkinSearchResults" class="mt-2" style="display:none;max-height:160px;overflow-y:auto;"></div>
                    <small class="text-muted d-block mt-1">Select an existing record to auto-fill.</small>
                </div>

                <div class="text-center my-2"><span class="badge bg-secondary px-3" style="font-size:.7rem;">OR NEW PATIENT</span></div>

                <form id="walkinForm">
                    <input type="hidden" id="walkin_patient_id">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-600 small">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="walkin_first_name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="walkin_last_name" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-600 small">Age</label>
                            <input type="number" class="form-control form-control-sm" id="walkin_age">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-600 small">Gender</label>
                            <select class="form-select form-select-sm" id="walkin_gender">
                                <option value="">Select</option><option>Male</option><option>Female</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-600 small">Patient Type</label>
                            <select class="form-select form-select-sm" id="walkin_patient_type">
                                <option>Regular</option><option>Senior</option><option>PWD</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Contact</label>
                            <input type="text" class="form-control form-control-sm" id="walkin_contact">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Address</label>
                            <textarea class="form-control form-control-sm" id="walkin_address" rows="2"></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Service</label>
                            <input type="text" class="form-control form-control-sm" id="walkin_service" value="Check-up">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control form-control-sm" id="walkin_time" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Notes</label>
                            <textarea class="form-control form-control-sm" id="walkin_notes" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="mt-3 p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-shield-lock-fill text-success"></i>
                            <strong class="small">Data Privacy Consent (RA 10173)</strong>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="walkin_dataConsent" required>
                            <label class="form-check-label fw-700 small" for="walkin_dataConsent">I give my consent</label>
                        </div>
                        <ul class="small text-muted ps-4 mb-1">
                            <li>Personal info collected & stored for medical purposes</li>
                            <li>Confidential; accessible only to authorized staff</li>
                            <li>Right to access, correct, or request deletion of data</li>
                            <li>Not shared with third parties without consent</li>
                        </ul>
                        <small class="text-muted"><i class="bi bi-info-circle me-1 text-success"></i>Required under RA 10173 — Data Privacy Act of 2012</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success fw-600" onclick="saveWalkinPatient()">
                    <i class="bi bi-check-lg me-2"></i>Add to Queue
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     SEARCH PATIENT MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="searchPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background:var(--teal);color:#fff;">
                <h5 class="modal-title fw-700"><i class="bi bi-search me-2"></i>Find Patient</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="globalPatientSearch" placeholder="Search by name, contact, or email…">
                    <button class="btn btn-teal" type="button" onclick="globalSearchPatients()">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
                <div id="globalSearchResults"></div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     SAVE PATIENT MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="savePatientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background:var(--teal);color:#fff;">
                <h5 class="modal-title fw-700"><i class="bi bi-person-plus me-2"></i>Save Patient Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="savePatientForm">
                    <input type="hidden" id="save_appointment_id">
                    <input type="hidden" id="save_user_id">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-600 small">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="save_first_name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="save_last_name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Age</label>
                            <input type="number" class="form-control form-control-sm" id="save_age">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600 small">Gender</label>
                            <select class="form-select form-select-sm" id="save_gender">
                                <option value="">Select</option><option>Male</option><option>Female</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Contact</label>
                            <input type="text" class="form-control form-control-sm" id="save_contact">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Address</label>
                            <textarea class="form-control form-control-sm" id="save_address" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Patient Type</label>
                            <select class="form-select form-select-sm" id="save_patient_type">
                                <option>Regular</option><option>Senior</option><option>PWD</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-teal fw-600" onclick="savePatientDetails()">
                    <i class="bi bi-check-lg me-2"></i>Save Patient
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════
     SURGERY CLINICS REFERRAL MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="surgeryClinicsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">

            <!-- Header -->
            <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;">
                <div>
                    <h5 class="modal-title fw-700 mb-0">
                        <i class="bi bi-hospital me-2"></i>Eye Surgery Clinics
                    </h5>
                    <div style="font-size:.72rem;opacity:.8;margin-top:2px;">
                        Eyecore-registered clinics offering eye surgery services
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">

                <!-- Referral note input (sticky at top) -->
                <div class="p-3 border-bottom" style="background:#fafbfc;">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-600 small mb-1">Diagnosis / Reason for Referral</label>
                            <input type="text" class="form-control form-control-sm" id="referralDiagnosis"
                                   placeholder="e.g. Cataract, Glaucoma…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-600 small mb-1">Referral Notes</label>
                            <input type="text" class="form-control form-control-sm" id="referralNotes"
                                   placeholder="Additional instructions for the clinic…">
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1 text-primary"></i>
                            These details will be included in the notification and email sent to the clinic.
                        </small>
                    </div>
                </div>

                <!-- Clinic list -->
                <div class="p-3" id="surgeryClinicsContainer">
                    <div class="text-center py-4">
                        <div class="spinner-border mb-2" style="color:var(--teal);width:1.4rem;height:1.4rem;"></div>
                        <p class="text-muted small mb-0">Loading clinics…</p>
                    </div>
                </div>

            </div>

            <div class="modal-footer border-0 py-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="printReferral()"
                        title="Print a blank referral letter">
                    <i class="bi bi-printer me-1"></i>Print Referral Letter
                </button>
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let currentAppointmentId = null;
let currentPatientId     = null;
let currentUserId        = null;
let timerInterval        = null;
let seconds              = 0;

// ✅ Permission check for buttons visibility
function canEditExam() {
    return permissions.canEditClinicalNotes || permissions.canCreateClinicalNotes;
}

function loadQueue() {
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_queue', method: 'GET',
        success: function(response) {
            if (response.success) { 
                renderQueueGrouped(response); 
                updateStatsFromData(response);  // ✅ Change this line
            }
            else showQueueError(response.message || 'Failed to load queue');
        },
        error: function() { showQueueError('Network error. Please refresh.'); }
    });
}

function updateStatsFromData(data) {
    const waitingCount = data.today.waiting ? data.today.waiting.length : 0;
    const inProgressCount = data.today.in_progress ? data.today.in_progress.length : 0;
    const waitingPaymentCount = data.today.waiting_payment ? data.today.waiting_payment.length : 0;
    const completedCount = data.today.completed ? data.today.completed.length : 0;
    
    let upcomingCount = 0;
    if (data.upcoming) {
        Object.keys(data.upcoming).forEach(date => {
            upcomingCount += data.upcoming[date].length;
        });
    }
    
    // Update stats display
    $('#statWaiting').text(waitingCount);
    $('#statProgress').text(inProgressCount);
    
    // Check if elements exist before setting
    if ($('#statWaitingPayment').length) {
        $('#statWaitingPayment').text(waitingPaymentCount);
    }
    
    if ($('#statCompleted').length) {
        $('#statCompleted').text(completedCount);
    }
    
    if ($('#statUpcoming').length) {
        $('#statUpcoming').text(upcomingCount);
    }
}


function showQueueError(msg) {
    $('#queueContainer').html(`
        <div class="text-center py-5">
            <i class="bi bi-exclamation-triangle d-block mb-2 opacity-50" style="font-size:1.8rem;color:#ef4444;"></i>
            <p class="text-danger small">${msg}</p>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadQueue()">
                <i class="bi bi-arrow-repeat me-1"></i>Retry
            </button>
        </div>`);
}

$(document).ready(function() {
    loadQueue();
    setInterval(function() { if (!currentAppointmentId) loadQueue(); }, 30000);
    
    // ✅ Hide buttons based on permissions
    if (!permissions.canCreateAppointments) {
        $('.btn-success').filter(function() {
            return $(this).text().includes('Walk-in');
        }).hide();
    }
    
    if (!permissions.canCreateClinicalNotes && !permissions.canEditClinicalNotes) {
        $('.btn-success').filter(function() {
            return $(this).text().includes('Save & Complete');
        }).hide();
        $('.btn-outline-secondary').filter(function() {
            return $(this).text().includes('Save');
        }).hide();
    }
    
    if (!permissions.canCreatePrescriptions && !permissions.canEditPrescriptions) {
        $('.btn-teal').filter(function() {
            return $(this).text().includes('Save Rx');
        }).hide();
    }
});





function renderQueueGrouped(data) {
    let html = '';
    const today = new Date(data.current_date);
    const formattedToday = today.toLocaleDateString('en-PH', { 
        month: 'long', 
        day: 'numeric', 
        year: 'numeric' 
    });
    
    // ========== TODAY'S QUEUE SECTION ==========
    html += `<div class="queue-section mb-4">
                <div class="section-header d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                    <i class="bi bi-calendar-day fs-5" style="color:var(--teal);"></i>
                    <span class="fw-700" style="font-size:0.9rem; color:#1e293b;">Today's Queue</span>
                    <span class="badge bg-light text-dark ms-2">${formattedToday}</span>
                </div>`;
    
    // IN PROGRESS
    if (data.today.in_progress && data.today.in_progress.length > 0) {
        html += `<div class="sub-section mb-3">
                    <div class="sub-header small text-muted mb-2">
                        <i class="bi bi-arrow-right-circle-fill me-1" style="color:var(--teal);"></i>
                        IN PROGRESS (${data.today.in_progress.length})
                    </div>`;
        data.today.in_progress.forEach(a => {
            html += renderQueueItem(a, 'in-progress');
        });
        html += `</div>`;
    }
    
    // WAITING (ARRIVED)
    if (data.today.waiting && data.today.waiting.length > 0) {
        html += `<div class="sub-section mb-3">
                    <div class="sub-header small text-muted mb-2">
                        <i class="bi bi-clock-fill me-1 text-warning"></i>
                        WAITING (${data.today.waiting.length})
                    </div>`;
        data.today.waiting.forEach(a => {
            html += renderQueueItem(a, 'waiting');
        });
        html += `</div>`;
    }
    
    // NO APPOINTMENTS TODAY
    if ((!data.today.waiting || data.today.waiting.length === 0) && 
        (!data.today.in_progress || data.today.in_progress.length === 0)) {
        html += `<div class="text-center py-4 text-muted">
                    <i class="bi bi-calendar-check d-block mb-2 fs-4 opacity-25"></i>
                    <small>No patients waiting for consultation</small>
                 </div>`;
    }
    
    // WAITING FOR PAYMENT (View Only)
    if (data.today.waiting_payment && data.today.waiting_payment.length > 0) {
        html += `<div class="sub-section mt-3">
                    <div class="sub-header small text-muted mb-2 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-credit-card me-1 text-warning"></i>
                        NEED PAYMENT (${data.today.waiting_payment.length})</span>
                        <span class="badge bg-warning text-dark" style="font-size:.6rem;">Awaiting Payment</span>
                    </div>`;
        data.today.waiting_payment.forEach(a => {
            html += renderQueueItem(a, 'waiting_payment');
        });
        html += `</div>`;
    }
    
    // COMPLETED TODAY (View Only - collapsible)
    if (data.today.completed && data.today.completed.length > 0) {
        html += `<div class="sub-section mt-3">
                    <div class="sub-header small text-muted mb-2 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-check-circle-fill me-1 text-success"></i>
                        COMPLETED TODAY (${data.today.completed.length})</span>
                        <button class="btn btn-sm btn-link p-0 text-muted" onclick="toggleCompleted()" style="font-size:.7rem;">
                            <i class="bi bi-chevron-down" id="completedToggleIcon"></i>
                        </button>
                    </div>
                    <div id="completedList" style="display:none;">`;
        data.today.completed.forEach(a => {
            html += renderQueueItem(a, 'completed');
        });
        html += `</div></div>`;
    }
    
    html += `</div>`;
    
    // ========== UPCOMING APPOINTMENTS SECTION (View Only) ==========
    const upcomingDates = Object.keys(data.upcoming || {});
    if (upcomingDates.length > 0) {
        // ... (keep existing upcoming section)
    } else {
        html += `<div class="queue-section mt-4 pt-2">
                    <div class="section-header d-flex align-items-center gap-2 mb-3 pb-2 border-top pt-3">
                        <i class="bi bi-calendar-week fs-5" style="color:var(--teal);"></i>
                        <span class="fw-700" style="font-size:0.9rem; color:#1e293b;">Upcoming Appointments</span>
                    </div>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-calendar-x d-block mb-2 fs-4 opacity-25"></i>
                        <small>No upcoming appointments in the next 7 days</small>
                    </div>
                </div>`;
    }
    
    updateStatsFromData(data);
    $('#queueContainer').html(html);
    
    if ($('#completedList').length) {
        window.completedVisible = false;
    }
}
// Helper function to toggle completed appointments
function toggleCompleted() {
    window.completedVisible = !window.completedVisible;
    $('#completedList').toggle(window.completedVisible);
    $('#completedToggleIcon').toggleClass('bi-chevron-down bi-chevron-up');
}

function updateStatsFromData(data) {
    const waitingCount = data.today.waiting ? data.today.waiting.length : 0;
    const inProgressCount = data.today.in_progress ? data.today.in_progress.length : 0;
    const waitingPaymentCount = data.today.waiting_payment ? data.today.waiting_payment.length : 0;
    const completedCount = data.today.completed ? data.today.completed.length : 0;
    
    let upcomingCount = 0;
    if (data.upcoming) {
        Object.keys(data.upcoming).forEach(date => {
            upcomingCount += data.upcoming[date].length;
        });
    }
    
    $('#statWaiting').text(waitingCount);
    $('#statProgress').text(inProgressCount);
    $('#statWaitingPayment').text(waitingPaymentCount);
    $('#statCompleted').text(completedCount);
    $('#statUpcoming').text(upcomingCount);
}
function escapeString(str) {
    if (!str) return '';
    return str.toString().replace(/'/g,"\\'").replace(/"/g,'&quot;');
}

function renderQueueItem(appt, statusClass) {
    const time = appt.appointment_time ? appt.appointment_time.substr(0,5) : '--:--';
    let statusText='Waiting', badgeColor='warning';
    if (statusClass==='in-progress') { statusText='In Progress'; badgeColor='primary'; }
    else if (statusClass==='completed') { statusText='Done'; badgeColor='success'; }
    else if (statusClass==='waiting_payment') { statusText='Need Payment'; badgeColor='warning'; }
    else if (statusClass==='any-doctor') { statusText='Any Dr.'; badgeColor='info'; }
    
    const appointmentStatus = appt.appointment_status || appt.status || '';
    
    return `<div class="queue-item ${statusClass}"
                 onclick="selectPatient(${appt.id},${appt.patient_id||'null'},${appt.user_id||'null'},
                     '${escapeString(appt.patient_name)}',${appt.age||0},'${escapeString(appt.gender)}',
                     '${escapeString(appt.patient_type||'Regular')}','${escapeString(appt.product_name)}',
                     '${escapeString(appt.doctor_name)}','${escapeString(appt.appointment_time)}',
                     ${appt.is_new_patient?1:0},${appt.doctor_id?1:0},'${appointmentStatus}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="badge bg-light text-dark" style="font-size:.65rem;"><i class="bi bi-clock me-1"></i>${time}</span>
                    <span class="badge bg-${badgeColor}" style="font-size:.64rem;">${statusText}</span>
                </div>
                <div class="qn">${appt.patient_name||'Unknown'}${appt.is_new_patient?'<span class="badge bg-success ms-1" style="font-size:.58rem;">New</span>':''}</div>
                <div class="qs">${appt.age||'?'} yrs · ${appt.gender||'?'} · ${appt.product_name||'Check-up'}</div>
            </div>`;
}
function selectPatient(appointmentId, patientId, userId, name, age, gender, type, service, doctor, time, isNew, hasDoctor, status) {
    currentAppointmentId = appointmentId;
    currentPatientId     = patientId;
    currentUserId        = userId;

    $('#patientCard').show(); 
    $('#noPatientPlaceholder').hide();

    const displayName = name && name!=='null' && name!=='Unknown' ? name : 'Patient';
    $('#patientName').text(displayName);
    $('#patientAge').text(age||'?'); 
    $('#patientGender').text(gender||'?'); 
    $('#patientType').text(type||'Regular');
    $('#serviceName').text(service||'—'); 
    $('#doctorName').text(doctor?'Dr. '+doctor:'—');
    $('#appointmentTime').text(time?time.substr(0,5):'—');

    $('#currentAppointmentId').val(appointmentId); 
    $('#currentPatientId').val(patientId);
    $('#currentUserId').val(userId); 
    $('#isNewPatient').val(isNew?1:0); 
    $('#isAnyDoctor').val(hasDoctor?0:1);

    // ✅ CHECK STATUS AND DISABLE INPUTS ACCORDINGLY
    const isWaitingPayment = status === 'waiting_payment';
    const isCompleted = status === 'completed';
    const isUpcoming = status === 'confirmed' || status === 'paid';
    const isInProgress = status === 'in_progress';
    const isArrived = status === 'arrived';
    
    // Remove any existing alert messages
    $('#patientCard .status-alert').remove();
    
    // ========== VIEW ONLY MODES ==========
    if (isCompleted) {
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes, .btn-success, .btn-teal, .btn-outline-teal').prop('disabled', true);
        $('#startConsultationBtnContainer').hide();
        $('#anyDoctorAlert').hide();
        $('#newPatientAlert').hide();
        
        $('#patientCard').prepend(`
            <div class="alert alert-success status-alert m-2">
                <i class="bi bi-check-circle me-1"></i> 
                <strong>Completed</strong> - This consultation has been fully paid and completed. View only.
            </div>
        `);
        return;
    }
    
    if (isWaitingPayment) {
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes, .btn-success, .btn-teal, .btn-outline-teal').prop('disabled', true);
        $('#startConsultationBtnContainer').hide();
        $('#anyDoctorAlert').hide();
        $('#newPatientAlert').hide();
        
        $('#patientCard').prepend(`
            <div class="alert alert-warning status-alert m-2">
                <i class="bi bi-credit-card me-1"></i> 
                <strong>Payment Required</strong> - Consultation completed. Please process payment in Sales & Billing.
                <button class="btn btn-sm btn-teal ms-2" onclick="goToSalesBilling(${appointmentId})">
                    Go to Payment
                </button>
            </div>
        `);
        return;
    }
    
    if (isUpcoming) {
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes, .btn-success, .btn-teal, .btn-outline-teal').prop('disabled', true);
        $('#startConsultationBtnContainer').hide();
        $('#anyDoctorAlert').hide();
        $('#newPatientAlert').hide();
        
        $('#patientCard').prepend(`
            <div class="alert alert-info status-alert m-2">
                <i class="bi bi-calendar me-1"></i> 
                <strong>Upcoming Appointment</strong> - Scheduled for ${time}. This is view only.
            </div>
        `);
        return;
    }
    
    // ========== ACTIVE CONSULTATION MODES ==========
    $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', true);
    
    const consultationStarted = sessionStorage.getItem(`consultation_${appointmentId}_started`) === 'true';
    
    if (!hasDoctor) {
        $('#anyDoctorAlert').show(); 
        $('#newPatientAlert').hide(); 
        $('#newPatientBadge').hide();
        $('#startConsultationBtnContainer').hide();
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', true);
    } else if (isNew && !patientId) {
        $('#anyDoctorAlert').hide(); 
        $('#newPatientAlert').show(); 
        $('#newPatientBadge').show();
        $('#startConsultationBtnContainer').hide();
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', true);
        loadUserDetails(userId);
    } else {
        $('#anyDoctorAlert').hide(); 
        $('#newPatientAlert').hide(); 
        $('#newPatientBadge').hide();
        
        // ✅ SHOW START CONSULTATION BUTTON if not started yet and patient is arrived
        if (!consultationStarted && isArrived) {
            $('#startConsultationBtnContainer').show();
            $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', true);
        } else if (consultationStarted || isInProgress) {
            $('#startConsultationBtnContainer').hide();
            $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', false);
            if (consultationStarted) {
                startTimer();
            }
        } else {
            $('#startConsultationBtnContainer').hide();
        }
    }

    resetTimer();
    clearForms(); 
    loadPatientData(patientId, appointmentId);
}
// Go to Sales & Billing for payment
function goToSalesBilling(appointmentId) {
    window.location.href = `main.php?view=sales&appointment_id=${appointmentId}`;
}

function loadPatientData(patientId, appointmentId) {
    if (!patientId) return;
    
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_patient_data',
        method: 'GET',
        data: { patient_id: patientId, appointment_id: appointmentId },
        success: function(r) {
            if (!r.success) return;
            
            // ✅ Check if appointment has multiple services
            if (r.appointment_services && r.appointment_services.length > 0) {
                // Build service list
                let serviceList = r.appointment_services.map(s => s.name).join(', ');
                
                // ✅ Display ALL services in the service name field
                $('#serviceName').html(`
                    <span class="fw-700">${serviceList}</span>
                    <span class="badge bg-info ms-1" style="font-size:.6rem;">${r.appointment_services.length} services</span>
                `);
                
                // ✅ Also show in the items list if we have a bill preview area
                if ($('#billItemsList').length) {
                    let itemsHtml = '';
                    r.appointment_services.forEach((s, index) => {
                        itemsHtml += `
                            <div class="d-flex justify-content-between align-items-center small py-1 ${index > 0 ? 'border-top' : ''}">
                                <span><i class="bi bi-dot me-1"></i>${s.name}</span>
                                <span class="fw-600">₱${parseFloat(s.price || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</span>
                            </div>
                        `;
                    });
                    $('#billItemsList').html(itemsHtml);
                    $('#billTotalAmount').text('₱' + parseFloat(r.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2}));
                }
            } else {
                // Fallback to single service
                $('#serviceName').text(r.service_name || '—');
            }
            
            if (r.latest_notes) {
                const n=r.latest_notes;
                $('#chiefComplaint').val(n.chief_complaint||''); $('#vaLeft').val(n.va_left||'');
                $('#vaRight').val(n.va_right||''); $('#findings').val(n.findings||'');
                $('#diagnosis').val(n.diagnosis||''); $('#treatmentPlan').val(n.treatment_plan||'');
                $('#notes').val(n.notes||'');
            }
            if (r.latest_rx) {
                const rx=r.latest_rx;
                $('#rxSphR').val(rx.sph_r||''); $('#rxCylR').val(rx.cyl_r||''); $('#rxAxisR').val(rx.axis_r||''); $('#rxAddR').val(rx.add_r||'');
                $('#rxSphL').val(rx.sph_l||''); $('#rxCylL').val(rx.cyl_l||''); $('#rxAxisL').val(rx.axis_l||''); $('#rxAddL').val(rx.add_l||'');
                $('#rxPd').val(rx.pd||''); $('#rxPdType').val(rx.pd_type||'Binocular'); $('#rxNotes').val(rx.notes||'');
                const rxSummary = (rx.sph_r||rx.sph_l)
                    ? `OD: ${rx.sph_r||'0'} ${rx.cyl_r?'/'+rx.cyl_r:''} | OS: ${rx.sph_l||'0'} ${rx.cyl_l?'/'+rx.cyl_l:''}`
                    : 'No previous prescription';
                $('#lastRxSummary').html(`<span class="fw-700">${rxSummary}</span>`);
            }
            renderHistorySidebar(r.history||[]);
            if (currentPatientId) loadDecisionSupport(currentPatientId, r.latest_notes?.diagnosis||'');
        }
    });
}
// ==================== HISTORY SIDEBAR ====================
function renderHistorySidebar(history) {
    if (!history||history.length===0) {
        $('#historySidebar').html(`<div class="text-center py-4 text-muted"><i class="bi bi-journal-medical d-block mb-1 opacity-25" style="font-size:1.5rem;"></i><small>No previous records</small></div>`);
        return;
    }
    let html='';
    history.slice(0,5).forEach((item,index)=>{
        const sd=item.formatted_date||item.service_date||'No date';
        const dx=item.diagnosis||'Check-up';
        const cc=item.chief_complaint?item.chief_complaint.substring(0,35)+(item.chief_complaint.length>35?'…':''):'';
        html+=`<div class="hist-item">
            <div class="d-flex justify-content-between align-items-center">
                <small class="fw-700" style="color:var(--teal);">${sd}</small>
                <span class="badge bg-light text-muted" style="font-size:.61rem;">Visit ${history.length-index}</span>
            </div>
            <div class="fw-600 small mt-1">${dx}</div>
            ${cc?`<div class="text-muted" style="font-size:.72rem;">${cc}</div>`:''}
        </div>`;
    });
    if (history.length>5) html+=`<div class="text-center mt-1"><small style="color:var(--teal);">+${history.length-5} more visits</small></div>`;
    $('#historySidebar').html(html);
}

// ==================== FULL HISTORY MODAL ====================
function viewFullHistory(patientId) {
    if (!patientId) { Swal.fire('Info','No patient selected','info'); return; }
    $('#historyModal').modal('show');
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_patient_data', method: 'GET',
        data: { patient_id: patientId },
        success: function(r) {
            if (r.success) {
                $('#modalPatientName').text($('#patientName').text()); $('#modalPatientAge').text($('#patientAge').text());
                $('#modalPatientGender').text($('#patientGender').text()); $('#modalPatientType').text($('#patientType').text());
                $('#modalPatientPhone').text('N/A');
                loadClinicalNotesTab(patientId); loadPrescriptionsTab(patientId);
                loadAppointmentsTab(patientId);
            }
        }
    });
}

function loadClinicalNotesTab(patientId) {
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_patient_data', method: 'GET',
        data: { patient_id: patientId },
        success: function(r) {
            if (r.success&&r.history) renderClinicalNotesTab(r.history);
            else $('#clinicalNotesContent').html('<p class="text-muted text-center py-4">No clinical notes found</p>');
        }
    });
}

function renderClinicalNotesTab(history) {
    if (!history||history.length===0) { $('#clinicalNotesContent').html('<p class="text-muted text-center py-4">No clinical notes found</p>'); return; }
    let html='<div class="table-responsive"><table class="table table-sm table-hover"><thead> <tr><th>Date</th><th>Chief Complaint</th><th>Diagnosis</th><th>Findings</th><th>Doctor</th></tr> </thead><tbody>';
    history.forEach(item=>{
        html+=`<tr><td>${item.formatted_date||item.service_date||'N/A'}</td><td>${item.chief_complaint||'—'}</td>
            <td><span class="badge" style="background:var(--teal);">${item.diagnosis||'Check-up'}</span></td>
            <td>${item.findings?item.findings.substring(0,50)+(item.findings.length>50?'…':''):'—'}</td>
            <td>Dr. ${item.doctor_name||'Unknown'}</td></tr>`;
    });
    html+='</tbody></table></div>';
    $('#clinicalNotesContent').html(html);
}

function claimAppointment() {
    // ✅ Check muna kung may doctor ID
    if (!currentDoctorId) {
        Swal.fire({
            icon: 'warning',
            title: 'Doctor Profile Needed',
            html: `<p>You need a doctor profile before you can claim a patient.</p>
                   <div class="mt-3 p-3 bg-light rounded-3 text-start">
                       <p class="small fw-700 mb-2">As a Clinic Admin, you can:</p>
                       <ul class="small text-muted mb-0">
                           <li>Perform eye examinations</li>
                           <li>Create clinical notes</li>
                           <li>Write prescriptions</li>
                           <li>Claim patients from queue</li>
                       </ul>
                   </div>`,
            confirmButtonText: 'Set Up Doctor Profile',
            confirmButtonColor: '#0d9488',
            showCancelButton: true,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                promptDoctorProfileSetup();
            }
        });
        return;
    }
    
    // May doctor ID na, proceed sa claim
    $.ajax({
        url: 'api/doctor_dashboard.php?action=claim_appointment',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ 
            appointment_id: currentAppointmentId, 
            doctor_id: currentDoctorId 
        }),
        success: function(r) {
            if (r.success) { 
                $('#anyDoctorAlert').hide(); 
                $('#isAnyDoctor').val(0); 
                startExamination(); 
                Swal.fire({icon:'success', title:'Patient Claimed!', timer:1500, showConfirmButton:false}); 
            } else if (r.code === 'NO_DOCTOR_PROFILE') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Doctor Profile Needed',
                    text: r.message,
                    confirmButtonText: 'Set Up Now',
                    confirmButtonColor: '#0d9488'
                }).then(() => {
                    promptDoctorProfileSetup();
                });
            } else {
                Swal.fire('Error', r.message || 'Failed to claim patient','error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Connection error. Please try again.', 'error');
        }
    });
}

function startExamination() {
    $.ajax({ url:'api/doctor_dashboard.php?action=start_exam', method:'POST', contentType:'application/json',
        data: JSON.stringify({appointment_id:currentAppointmentId}), success:function(r){if(r.success)loadQueue();} });
}
function loadUserDetails(userId) {
    $.ajax({ url:'api/doctor_dashboard.php?action=get_user_details', method:'GET', data:{user_id:userId},
        success:function(user){ if(user){ $('#save_first_name').val(user.first_name||''); $('#save_last_name').val(user.last_name||''); $('#save_contact').val(user.contact||''); $('#save_address').val(user.address||''); } }
    });
}

// ==================== TIMER ====================
function resetTimer() { seconds=0; updateTimerDisplay(); if(timerInterval) clearInterval(timerInterval); }
function startTimer() { timerInterval=setInterval(()=>{ seconds++; updateTimerDisplay(); },1000); }
function updateTimerDisplay() { const m=Math.floor(seconds/60),s=seconds%60; $('#timer').text(String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')); }
function clearForms()  { $('#examForm')[0].reset(); $('#rxForm')[0].reset(); }
function refreshQueue(){ loadQueue(); }

function saveClinicalNotes(callback) {
    if (!currentAppointmentId) { 
        Swal.fire('Warning', 'No patient selected', 'warning'); 
        return; 
    }
    if ($('#isAnyDoctor').val() == 1) { 
        Swal.fire({icon:'warning', title:'Claim Patient First', confirmButtonText:'Claim Now'})
            .then(() => claimAppointment()); 
        return; 
    }
    
    // ✅ Permission check
    if (!permissions.canCreateClinicalNotes && !permissions.canEditClinicalNotes) {
        Swal.fire('Permission Denied', 'You do not have permission to save clinical notes.', 'warning');
        return;
    }
    
    const data = { 
        appointment_id: currentAppointmentId, 
        patient_id: currentPatientId, 
        chief_complaint: $('#chiefComplaint').val(),
        va_left: $('#vaLeft').val(), 
        va_right: $('#vaRight').val(), 
        findings: $('#findings').val(),
        diagnosis: $('#diagnosis').val(), 
        treatment_plan: $('#treatmentPlan').val(), 
        notes: $('#notes').val() 
    };
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({ 
        url: 'api/doctor_dashboard.php?action=save_notes', 
        method: 'POST', 
        contentType: 'application/json', 
        data: JSON.stringify(data),
        success: function(r) { 
            Swal.close();
            if (r.success) {
                if (callback) callback(r);
            } else {
                // ✅ Handle specific error for non-doctor users
                if (r.code === 'NOT_A_DOCTOR') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Doctor Profile Required',
                        html: `<p class="text-muted">${r.message}</p>
                               <div class="mt-3 p-3 bg-light rounded-3">
                                   <p class="small fw-700 mb-2">Want to perform eye examinations?</p>
                                   <p class="small text-muted mb-2">As a Clinic Owner, you can also set up your doctor profile to:</p>
                                   <ul class="small text-muted mb-3">
                                       <li>Perform eye examinations</li>
                                       <li>Create clinical notes</li>
                                       <li>Write prescriptions</li>
                                       <li>View patient queue as a doctor</li>
                                   </ul>
                                   <button class="btn btn-teal btn-sm w-100" onclick="setupDoctorProfile()">
                                       <i class="bi bi-person-plus me-2"></i>Set Up Doctor Profile
                                   </button>
                               </div>`,
                        confirmButtonText: 'OK',
                        showCancelButton: true,
                        cancelButtonText: 'Cancel'
                    }).then(result => {
                        if (result.isConfirmed) {
                            setupDoctorProfile();
                        }
                    });
                } else if (r.code === 'NO_DOCTOR_PROFILE') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Doctor Profile',
                        text: r.message,
                        confirmButtonText: 'OK'
                    });
                } else {
                    Swal.fire('Error', r.message || 'Failed to save notes', 'error');
                }
            }
        },
        error: function() { 
            Swal.close();
            Swal.fire('Error', 'Connection error. Please try again.', 'error'); 
        } 
    });
}

// ✅ Function to setup doctor profile for ClinicAdmin
function setupDoctorProfile() {
    Swal.fire({
        title: 'Set Up Doctor Profile',
        html: `
            <form id="doctorProfileForm" class="text-start">
                <div class="mb-3">
                    <label class="form-label fw-600 small">Doctor's Name</label>
                    <input type="text" class="form-control form-control-sm" id="doctor_name" 
                           value="${$('#patientName').text()}" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-600 small">Specialty</label>
                    <select class="form-select form-select-sm" id="doctor_specialty" required>
                        <option value="">Select Specialty</option>
                        <option value="Optometry">Optometry</option>
                        <option value="Ophthalmology">Ophthalmology</option>
                        <option value="Pediatric Ophthalmology">Pediatric Ophthalmology</option>
                        <option value="Retina Specialist">Retina Specialist</option>
                        <option value="Glaucoma Specialist">Glaucoma Specialist</option>
                        <option value="Cornea Specialist">Cornea Specialist</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-600 small">License Number</label>
                    <input type="text" class="form-control form-control-sm" id="doctor_license" 
                           placeholder="PRC License Number" required>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="doctor_consent" required>
                    <label class="form-check-label small" for="doctor_consent">
                        I confirm that I am a licensed optometrist/ophthalmologist.
                    </label>
                </div>
            </form>
        `,
        showCancelButton: true,
        confirmButtonText: 'Create Profile',
        confirmButtonColor: '#0d9488',
        preConfirm: () => {
            const specialty = document.getElementById('doctor_specialty').value;
            const license = document.getElementById('doctor_license').value;
            const consent = document.getElementById('doctor_consent').checked;
            
            if (!specialty) {
                Swal.showValidationMessage('Please select a specialty');
                return false;
            }
            if (!license) {
                Swal.showValidationMessage('Please enter your license number');
                return false;
            }
            if (!consent) {
                Swal.showValidationMessage('Please confirm that you are a licensed professional');
                return false;
            }
            return { specialty: specialty, license: license };
        }
    }).then(result => {
        if (result.isConfirmed) {
            createDoctorProfile(result.value.specialty, result.value.license);
        }
    });
}

function createDoctorProfile(specialty, license) {
    Swal.fire({
        title: 'Creating Profile...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/doctor_dashboard.php?action=create_doctor_profile',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            name: $('#patientName').text(),
            specialty: specialty,
            license: license,
            user_id: currentUserId
        }),
        success: function(r) {
            Swal.close();
            if (r.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Doctor Profile Created!',
                    html: `<p>Your doctor profile has been created.</p>
                           <p class="small text-muted">Please log out and log back in to access full doctor features.</p>`,
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', r.message || 'Failed to create doctor profile', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Connection error. Please try again.', 'error');
        }
    });
}

function savePrescription(callback) {
    if (!currentAppointmentId) return;
    
    // ✅ Permission check
    if (!permissions.canCreatePrescriptions && !permissions.canEditPrescriptions) {
        Swal.fire('Permission Denied', 'You do not have permission to save prescriptions.', 'warning');
        return;
    }
    
    const data={ appointment_id:currentAppointmentId, patient_id:currentPatientId,
        sph_r:$('#rxSphR').val(), cyl_r:$('#rxCylR').val(), axis_r:$('#rxAxisR').val(), add_r:$('#rxAddR').val(),
        sph_l:$('#rxSphL').val(), cyl_l:$('#rxCylL').val(), axis_l:$('#rxAxisL').val(), add_l:$('#rxAddL').val(),
        pd:$('#rxPd').val(), pd_type:$('#rxPdType').val(), notes:$('#rxNotes').val() };
    $.ajax({ url:'api/doctor_dashboard.php?action=save_rx', method:'POST', contentType:'application/json', data:JSON.stringify(data),
        success:function(r){ if(r.success){if(callback)callback(r);}else Swal.fire('Error',r.message||'Failed to save prescription','error'); } });
}

function completeAppointment() {
    $.ajax({ url:'api/doctor_dashboard.php?action=complete', method:'POST', contentType:'application/json',
        data:JSON.stringify({appointment_id:currentAppointmentId}),
        success:function(r){
            if(r.success){ clearForms(); resetTimer(); currentAppointmentId=null; currentPatientId=null;
                $('#patientCard').hide(); $('#noPatientPlaceholder').show(); loadQueue();
                Swal.fire({icon:'success',title:'Completed!',timer:1800,showConfirmButton:false});
            } else Swal.fire('Error',r.message||'Failed to complete','error');
        }
    });
}

function saveOnly() { saveClinicalNotes(function(){ updatePatientDataAfterSave(); Swal.fire({icon:'success',title:'Saved!',timer:1500,showConfirmButton:false}); }); }
function saveAndPrescribe() { saveClinicalNotes(function(){ updatePatientDataAfterSave(); $('#prescriptionTabLink').tab('show'); Swal.fire({icon:'success',title:'Notes Saved',timer:1500,showConfirmButton:false}); }); }

function saveAndComplete() {
    // Check if consultation has started
    const consultationStarted = sessionStorage.getItem(`consultation_${currentAppointmentId}_started`) === 'true';
    
    if (!consultationStarted) {
        Swal.fire({
            icon: 'warning',
            title: 'Consultation Not Started',
            text: 'Please click "Start Consultation" button first.',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    if ($('#isNewPatient').val() == 1 && !currentPatientId) { 
        Swal.fire({icon:'warning', title:'Save Patient First', confirmButtonText:'Save Now'})
            .then(() => showSavePatientModal()); 
        return; 
    }
    
    Swal.fire({
        title: 'Complete Consultation?',
        text: 'This will save all data and generate a bill. Patient will need to pay in Sales & Billing.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        confirmButtonText: 'Yes, Complete',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            saveClinicalNotes(function(response) {
                if (response && response.success === false) return;
                
                updatePatientDataAfterSave();
                const hasRx = $('#rxSphR').val() || $('#rxSphL').val() || $('#rxCylR').val() || $('#rxCylL').val();

                const completeAction = function() {
                    Swal.fire({ title: 'Saving...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
                    
                    $.ajax({ 
                        url: 'api/doctor_dashboard.php?action=complete_consultation', 
                        method: 'POST', 
                        contentType: 'application/json',
                        data: JSON.stringify({
                            appointment_id: currentAppointmentId, 
                            patient_id: currentPatientId
                        }),
                        success: function(r) {
                            Swal.close();
                            if (r.success) {
                                // Clear session storage flag
                                sessionStorage.removeItem(`consultation_${currentAppointmentId}_started`);
                                
                                // Show success with bill summary
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Consultation Complete!',
                                    html: `
                                        <div class="text-start">
                                            <div class="bg-light p-3 rounded-3 mb-3">
                                                <div class="fw-bold mb-2">Bill Summary:</div>
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Consultation/Service</span>
                                                    <span>₱${parseFloat(r.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</span>
                                                </div>
                                                <hr class="my-2">
                                                <div class="d-flex justify-content-between fw-bold">
                                                    <span>TOTAL DUE</span>
                                                    <span class="text-warning">₱${parseFloat(r.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</span>
                                                </div>
                                                <div class="small text-muted mt-2">
                                                    <i class="bi bi-receipt"></i> Bill #: ${r.bill_number}
                                                </div>
                                            </div>
                                            <div class="alert alert-warning small">
                                                <i class="bi bi-credit-card me-1"></i>
                                                <strong>Payment Required</strong> — Please process payment in Sales & Billing.
                                            </div>
                                        </div>
                                    `,
                                    showConfirmButton: false,
                                    showCloseButton: true,
                                    width: '450px'
                                });

                                // ── Keep card visible but lock it as view-only ──
                                setTimeout(() => {
                                    // Disable all form inputs
                                    $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes')
                                        .prop('disabled', true);

                                    // Disable action buttons
                                    $('.btn-success, .btn-teal, .btn-outline-teal')
                                        .prop('disabled', true);

                                    // Hide the start consultation button
                                    $('#startConsultationBtnContainer').hide();

                                    // Hide any doctor/new-patient alerts
                                    $('#anyDoctorAlert').hide();
                                    $('#newPatientAlert').hide();

                                    // Remove old status banners and add the completed one
                                    $('.status-alert').remove();
                                    $('#patientCard').prepend(`
                                        <div class="alert alert-success status-alert m-2 d-flex align-items-center gap-2">
                                            <i class="bi bi-check-circle-fill fs-5"></i>
                                            <div>
                                                <strong>Consultation Complete</strong> — Bill #${r.bill_number} generated.<br>
                                                <small class="text-muted">Waiting for payment in Sales &amp; Billing.</small>
                                            </div>
                                            <button class="btn btn-sm btn-success ms-auto" onclick="goToSalesBilling(${currentAppointmentId})">
                                                <i class="bi bi-credit-card me-1"></i>Pay Now
                                            </button>
                                        </div>
                                    `);

                                    // Reload the queue sidebar to reflect the new status
                                    loadQueue();
                                }, 500);

                            } else {
                                Swal.fire('Error', r.message || 'Failed to complete', 'error');
                            }
                        },
                        error: function() { 
                            Swal.close();
                            Swal.fire('Error', 'Connection error', 'error'); 
                        }
                    });
                };
                
                if (hasRx) {
                    savePrescription(function() { completeAction(); });
                } else {
                    completeAction();
                }
            });
        }
    });
}
function savePrescriptionOnly() { savePrescription(function(){ updatePatientDataAfterSave(); Swal.fire({icon:'success',title:'Saved!',text:'Prescription saved.',timer:1500,showConfirmButton:false}); }); }

function updatePatientDataAfterSave() { if(!currentPatientId) return; setTimeout(()=>loadPatientData(currentPatientId,currentAppointmentId),500); }

function showWalkInModal() {
    if (!permissions.canCreateAppointments) {
        Swal.fire('Permission Denied', 'You do not have permission to create walk-in appointments.', 'warning');
        return;
    }
    
    $('#walkinForm')[0].reset();
    $('#walkin_patient_id').val('');
    
    $('#walkin_first_name').prop('readonly', false);
    $('#walkin_last_name').prop('readonly', false);
    
    $('#walkinSearchResults').hide();
    
    const now = new Date();
    $('#walkin_time').val(`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`);
    
    new bootstrap.Modal(document.getElementById('walkInModal')).show();
}

function searchWalkinPatient() {
    const search=$('#walkinSearch').val();
    if (search.length<2) { Swal.fire('Info','Please type at least 2 characters','info'); return; }
    $.ajax({ url:'api/doctor_dashboard.php?action=search_patients', method:'GET', data:{q:search},
        success:function(patients){
            let html=patients.length>0
                ? '<div class="list-group">'+patients.map(p=>`<a href="#" class="list-group-item list-group-item-action py-2"
                       onclick="selectWalkinPatient(${p.id},'${p.full_name.replace(/'/g,"\\'")}',${p.age},'${p.gender}','${p.phone}','${p.address}','${p.patient_type}')">
                       <strong>${p.full_name}</strong> <small class="text-muted ms-1">${p.phone||'N/A'}</small>
                       <span class="badge float-end" style="background:var(--teal);">${p.patient_code}</span></a>`).join('')+'</div>'
                : '<div class="alert alert-info small py-2">No existing patient found.</div>';
            $('#walkinSearchResults').html(html).show();
        }
    });
}

function selectWalkinPatient(id, name, age, gender, phone, address, type) {
    const parts = name.split(' ');
    const firstName = parts[0];
    const lastName = parts.slice(1).join(' ');
    
    $('#walkin_patient_id').val(id);
    
    $('#walkin_first_name').val(firstName);
    $('#walkin_last_name').val(lastName);
    $('#walkin_age').val(age || '');
    $('#walkin_gender').val(gender || '');
    $('#walkin_contact').val(phone || '');
    $('#walkin_address').val(address || '');
    $('#walkin_patient_type').val(type || 'Regular');
    
    $('#walkin_first_name').prop('readonly', true);
    $('#walkin_last_name').prop('readonly', true);
    
    $('#walkinSearchResults').hide();
}

function saveWalkinPatient() {
    if (!$('#walkin_dataConsent').is(':checked')) { 
        Swal.fire({icon:'warning', title:'Data Privacy Consent Required', confirmButtonText:'I Understand'}); 
        return; 
    }
    
    let patientId = $('#walkin_patient_id').val();
    let firstName = $('#walkin_first_name').val();
    let lastName = $('#walkin_last_name').val();
    
    const data = { 
        patient_id: patientId || 0,
        first_name: patientId ? '' : firstName,
        last_name: patientId ? '' : lastName,
        age: $('#walkin_age').val(),
        gender: $('#walkin_gender').val(),
        contact: $('#walkin_contact').val(),
        address: $('#walkin_address').val(),
        patient_type: $('#walkin_patient_type').val(),
        doctor_id: currentDoctorId,
        service: $('#walkin_service').val(),
        appointment_time: $('#walkin_time').val(),
        notes: $('#walkin_notes').val(),
        consent_given: true,
        consent_version: 'v1.0'
    };
    
    if (!data.first_name && !data.last_name && !patientId) {
        Swal.fire('Warning', 'Please fill in patient name or select existing patient', 'warning');
        return;
    }
    
    if (!data.appointment_time) {
        Swal.fire('Warning', 'Please select appointment time', 'warning');
        return;
    }
    
    Swal.fire({title:'Adding Patient…',allowOutsideClick:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
    
    $.ajax({ 
        url: 'api/doctor_dashboard.php?action=add_walkin', 
        method: 'POST', 
        contentType: 'application/json', 
        data: JSON.stringify(data),
        success: function(r){ 
            Swal.close(); 
            if(r.success){ 
                $('#walkInModal').modal('hide'); 
                loadQueue(); 
                Swal.fire({icon:'success',title:'Added!',text:'Patient added to queue',timer:1500}); 
            } else { 
                Swal.fire('Error', r.message, 'error'); 
            }
        },
        error: function(){ 
            Swal.close(); 
            Swal.fire('Error','Connection error. Please try again.','error'); 
        }
    });
}

// ==================== SEARCH MODAL ====================
function showSearchModal() {
    $('#globalPatientSearch').val(''); $('#globalSearchResults').html('');
    new bootstrap.Modal(document.getElementById('searchPatientModal')).show();
}

function globalSearchPatients() {
    const search=$('#globalPatientSearch').val();
    if (search.length<2) { Swal.fire('Info','Please type at least 2 characters','info'); return; }
    $.ajax({ url:'api/doctor_dashboard.php?action=search_patients', method:'GET', data:{q:search},
        success:function(patients){
            if(patients.length>0){
                let html='<div class="list-group">';
                patients.forEach(p=>{ html+=`<div class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <div><strong>${p.full_name}</strong><br><small class="text-muted">${p.phone||'N/A'}</small></div>
                    <button class="btn btn-sm btn-teal" onclick="createWalkinFromSearch(${p.id},'${p.full_name.replace(/'/g,"\\'")}',${p.age},'${p.gender}','${p.phone}','${p.address}','${p.patient_type}')">
                        <i class="bi bi-plus-circle"></i> Add
                    </button></div>`; });
                html+='</div>'; $('#globalSearchResults').html(html);
            } else { $('#globalSearchResults').html('<div class="alert alert-info small py-2">No patients found</div>'); }
        }
    });
}

function createWalkinFromSearch(id,name,age,gender,phone,address,type) {
    $('#searchPatientModal').modal('hide');
    setTimeout(()=>{ showWalkInModal(); selectWalkinPatient(id,name,age,gender,phone,address,type); },500);
}

// ==================== SAVE PATIENT MODAL ====================
function showSavePatientModal() {
    $('#save_appointment_id').val(currentAppointmentId); $('#save_user_id').val(currentUserId);
    new bootstrap.Modal(document.getElementById('savePatientModal')).show();
}

function savePatientDetails() {
    const data={ appointment_id:$('#save_appointment_id').val(), user_id:$('#save_user_id').val(),
        first_name:$('#save_first_name').val(), last_name:$('#save_last_name').val(), age:$('#save_age').val(),
        gender:$('#save_gender').val(), contact:$('#save_contact').val(), address:$('#save_address').val(),
        patient_type:$('#save_patient_type').val() };
    if (!data.first_name||!data.last_name) { Swal.fire('Warning','First name and last name are required','warning'); return; }
    $.ajax({ url:'api/doctor_dashboard.php?action=save_patient', method:'POST', contentType:'application/json', data:JSON.stringify(data),
        success:function(r){
            if(r.success){ $('#savePatientModal').modal('hide'); $('#newPatientAlert').hide(); $('#newPatientBadge').hide();
                currentPatientId=r.patient_id; $('#currentPatientId').val(r.patient_id);
                Swal.fire({icon:'success',title:'Patient Saved!',timer:2000}); loadQueue();
            } else Swal.fire('Error',r.message||'Failed to save patient','error');
        }
    });
}

function loadPrescriptionsTab(patientId) {
    $('#prescriptionsContent').html('<div class="text-center py-4"><div class="spinner-border" style="color:var(--teal);"></div></div>');
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_prescriptions',
        method: 'GET',
        data: { patient_id: patientId },
        success: function(r) {
            if (r.success && r.prescriptions && r.prescriptions.length > 0) {
                window._rxHistory = r.prescriptions;
                let html = '';
                r.prescriptions.forEach(function(rx, index) {
                    const date        = rx.formatted_date || rx.prescription_date || 'N/A';
                    const rxType      = rx.prescription_type ? `<span class="badge bg-light text-muted border" style="font-size:.62rem;">${rx.prescription_type}</span>` : '';
                    const expiryBadge = rx.expiry_date
                        ? `<span class="badge bg-light text-muted" style="font-size:.62rem;">
                               <i class="bi bi-calendar-x me-1"></i>Expires: ${rx.expiry_date}
                           </span>` : '';
                    const hasPrism = rx.prism_r || rx.prism_l || rx.base_r || rx.base_l;
 
                    html += `
                    <div class="mb-3 rounded-3 overflow-hidden" style="border:1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2"
                             style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <span class="fw-700 small" style="color:var(--teal);">
                                <i class="bi bi-prescription2 me-1"></i>${date}
                                ${rxType}
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                ${expiryBadge}
                                <span class="small text-muted">Dr. ${rx.doctor_name || 'Unknown'}</span>
                                <button class="btn btn-sm btn-outline-teal py-0 px-2" style="font-size:.7rem;"
                                        onclick="printPrescriptionFromHistory(${index})">
                                    <i class="bi bi-printer"></i>
                                </button>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <div class="p-2 rounded-3" style="background:#f0fdfa;border:1px solid #99f6e4;">
                                        <div class="fw-800 mb-2" style="font-size:.67rem;letter-spacing:.07em;text-transform:uppercase;color:var(--teal);">Right Eye (OD)</div>
                                        <div class="row g-1 text-center">
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">SPH</div><div class="fw-700 small">${rx.sph_r || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">CYL</div><div class="fw-700 small">${rx.cyl_r || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">AXIS</div><div class="fw-700 small">${rx.axis_r || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">ADD</div><div class="fw-700 small">${rx.add_r || '—'}</div></div>
                                        </div>
                                        ${hasPrism && (rx.prism_r || rx.base_r) ? `
                                        <div class="row g-1 text-center mt-1 pt-1 border-top">
                                            <div class="col-6"><div style="font-size:.6rem;color:#94a3b8;">PRISM</div><div class="fw-700 small">${rx.prism_r || '—'}</div></div>
                                            <div class="col-6"><div style="font-size:.6rem;color:#94a3b8;">BASE</div><div class="fw-700 small">${rx.base_r || '—'}</div></div>
                                        </div>` : ''}
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-2 rounded-3" style="background:#f0fdfa;border:1px solid #99f6e4;">
                                        <div class="fw-800 mb-2" style="font-size:.67rem;letter-spacing:.07em;text-transform:uppercase;color:var(--teal);">Left Eye (OS)</div>
                                        <div class="row g-1 text-center">
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">SPH</div><div class="fw-700 small">${rx.sph_l || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">CYL</div><div class="fw-700 small">${rx.cyl_l || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">AXIS</div><div class="fw-700 small">${rx.axis_l || '—'}</div></div>
                                            <div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">ADD</div><div class="fw-700 small">${rx.add_l || '—'}</div></div>
                                        </div>
                                        ${hasPrism && (rx.prism_l || rx.base_l) ? `
                                        <div class="row g-1 text-center mt-1 pt-1 border-top">
                                            <div class="col-6"><div style="font-size:.6rem;color:#94a3b8;">PRISM</div><div class="fw-700 small">${rx.prism_l || '—'}</div></div>
                                            <div class="col-6"><div style="font-size:.6rem;color:#94a3b8;">BASE</div><div class="fw-700 small">${rx.base_l || '—'}</div></div>
                                        </div>` : ''}
                                    </div>
                                </div>
                            </div>
                            ${(rx.pd || rx.notes) ? `
                            <div class="d-flex flex-wrap gap-3 small text-muted pt-1 border-top">
                                ${rx.pd ? `<span><i class="bi bi-rulers me-1"></i><strong>PD:</strong> ${rx.pd} (${rx.pd_type || 'Binocular'})</span>` : ''}
                                ${rx.notes ? `<span><i class="bi bi-chat-text me-1"></i>${rx.notes}</span>` : ''}
                            </div>` : ''}
                        </div>
                    </div>`;
                });
 
                $('#prescriptionsContent').html(html);
            } else {
                $('#prescriptionsContent').html(`
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-prescription2 d-block mb-2 opacity-25" style="font-size:2rem;"></i>
                        <small>No prescription records found</small>
                    </div>`);
            }
        },
        error: function() {
            $('#prescriptionsContent').html('<div class="alert alert-danger small py-2">Failed to load prescriptions.</div>');
        }
    });
}
 
function printPrescriptionFromHistory(index) {
    const rx = window._rxHistory && window._rxHistory[index];
    if (!rx) { Swal.fire('Error', 'Prescription data not found.', 'error'); return; }
 
    const patientName   = $('#modalPatientName').text()   || $('#patientName').text()   || '—';
    const patientAge    = $('#modalPatientAge').text()    || $('#patientAge').text()    || '—';
    const patientGender = $('#modalPatientGender').text() || $('#patientGender').text() || '—';
 
    const doctorName = rx.doctor_name || $('#doctorName').text().replace('Dr. ', '') || '—';
 
    const issuedRaw = rx.prescription_date || rx.formatted_date || null;
    const issued = issuedRaw
        ? new Date(issuedRaw).toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'})
        : new Date().toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
 
    const expiry = rx.expiry_date
        ? new Date(rx.expiry_date).toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'})
        : (() => {
            const d = issuedRaw ? new Date(issuedRaw) : new Date();
            d.setFullYear(d.getFullYear() + 1);
            return d.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
          })();
 
    const qrData = encodeURIComponent(
        `EYECORE-RX\nPT:${patientName}\n` +
        `OD:${rx.sph_r||'—'} ${rx.cyl_r||'—'} x${rx.axis_r||'—'} ADD${rx.add_r||'—'}\n` +
        `OS:${rx.sph_l||'—'} ${rx.cyl_l||'—'} x${rx.axis_l||'—'} ADD${rx.add_l||'—'}\n` +
        `PD:${rx.pd||'—'}(${rx.pd_type||'Binocular'})\n` +
        `DR:${doctorName}\nDATE:${issued}\nREF:APT-${rx.appointment_id||'N/A'}`
    );
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=${qrData}`;
 
    const win = window.open('', '_blank', 'width=340,height=720,scrollbars=yes');
    if (!win) { Swal.fire('Blocked', 'Please allow popups for this site to print.', 'warning'); return; }
 
    win.document.write(`<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Rx — ${patientName}</title>
<style>
@page { size: 80mm auto; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Courier New', Courier, monospace;
    width: 302px; margin: 0 auto; padding: 10px 8px 16px;
    font-size: 9px; color: #1e293b; background: #fff;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.clinic-header { text-align:center; border-bottom:1px dashed #cbd5e1; padding-bottom:7px; margin-bottom:7px; }
.clinic-name { font-size:12px; font-weight:900; color:#0d9488; margin:3px 0 2px; letter-spacing:.03em; }
.clinic-sub { font-size:7.5px; color:#64748b; line-height:1.6; }
.rx-badge { display:inline-block; border:1px solid #0d9488; border-radius:3px; color:#0d9488;
            font-size:10px; font-weight:900; letter-spacing:.15em; padding:2px 8px; margin:5px 0 2px; }
.dash  { border:none; border-top:1px dashed #cbd5e1; margin:5px 0; }
.solid { border:none; border-top:1px solid #0d9488; margin:5px 0; }
.sec  { font-size:7px; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; margin-bottom:2px; }
.pt-row { display:flex; justify-content:space-between; margin-bottom:2px; }
.pt-k { font-size:7.5px; color:#64748b; }
.pt-v { font-size:8.5px; font-weight:700; text-align:right; }
table.rx { width:100%; border-collapse:collapse; margin-bottom:3px; }
table.rx thead tr { background:#f0fdfa; }
table.rx thead th { font-size:7px; font-weight:800; text-transform:uppercase; letter-spacing:.05em;
                    text-align:center; color:#0d9488; padding:3px 2px; border-bottom:1px solid #99f6e4; }
table.rx thead th:first-child { text-align:left; }
table.rx tbody tr { border-bottom:1px dotted #e2e8f0; }
table.rx tbody tr:last-child { border-bottom:none; }
table.rx tbody td { font-size:9px; font-weight:700; text-align:center; padding:3px 2px; }
table.rx tbody td:first-child { text-align:left; font-size:8.5px; }
.eye-od { color:#0d9488; } .eye-os { color:#0891b2; }
.pd-row { display:flex; align-items:center; background:#f8fafc; border:1px solid #e2e8f0;
          border-radius:3px; padding:3px 5px; margin:4px 0; gap:8px; }
.pd-l { font-size:7.5px; color:#64748b; flex:1; }
.pd-v { font-size:9.5px; font-weight:900; }
.pd-t { font-size:7px; color:#94a3b8; }
.rx-notes { font-size:8px; color:#475569; line-height:1.5; border-left:2px solid #0d9488; padding-left:5px; margin:4px 0; }
.dates { display:flex; justify-content:space-between; margin:4px 0; }
.dt-box { text-align:center; }
.dt-l { font-size:7px; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
.dt-v { font-size:8.5px; font-weight:700; }
.dt-exp { font-size:8.5px; font-weight:700; color:#dc2626; }
.sig-section { text-align:center; margin-top:8px; }
.sig-line { border-bottom:1px solid #1e293b; width:120px; margin:18px auto 3px; }
.dr-name { font-size:9px; font-weight:700; }
.dr-role { font-size:7px; color:#64748b; }
.qr-row { display:flex; align-items:center; gap:6px; margin-top:7px; padding:5px;
          background:#f8fafc; border:1px dashed #e2e8f0; border-radius:3px; }
.qr-row img { width:52px; height:52px; }
.qr-txt { font-size:6.5px; color:#94a3b8; line-height:1.6; }
.qr-txt strong { color:#0d9488; font-size:7px; }
.footer { text-align:center; font-size:6.5px; color:#94a3b8; margin-top:8px; line-height:1.7; }
.print-btn { display:block; width:100%; padding:8px; background:#0d9488; color:#fff;
             font-size:11px; font-weight:700; border:none; border-radius:5px; cursor:pointer;
             margin-top:12px; letter-spacing:.04em; }
@media print { .print-btn { display:none !important; } }
</style></head><body>
 
<div class="clinic-header">
    <div class="clinic-name">👁 EYECORE OPTICAL CLINIC</div>
    <div class="clinic-sub">Eyecore PH &nbsp;·&nbsp; eyecore.com</div>
    <div class="rx-badge">&#x2772; Rx &#x2773;</div>
</div>
 
<div class="sec">Patient Information</div>
<div class="pt-row"><span class="pt-k">Name</span><span class="pt-v">${patientName}</span></div>
<div class="pt-row"><span class="pt-k">Age / Gender</span><span class="pt-v">${patientAge} yrs / ${patientGender}</span></div>
<hr class="dash">
 
<div class="sec" style="margin-bottom:3px;">Optical Prescription</div>
<table class="rx">
    <thead> <tr><th style="text-align:left;">Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th></tr> </thead>
    <tbody>
        <tr><td><span class="eye-od">OD ▶</span></td><td>${rx.sph_r || '—'}</td><td>${rx.cyl_r || '—'}</td><td>${rx.axis_r || '—'}</td><td>${rx.add_r || '—'}</td></tr>
        <tr><td><span class="eye-os">OS ▶</span></td><td>${rx.sph_l || '—'}</td><td>${rx.cyl_l || '—'}</td><td>${rx.axis_l || '—'}</td><td>${rx.add_l || '—'}</td></tr>
    </tbody>
</table>
 
<div class="pd-row">
    <span class="pd-l">Pupillary Distance (PD)</span>
    <span class="pd-v">${rx.pd || '—'}</span>
    <span class="pd-t">${rx.pd_type || 'Binocular'}</span>
</div>
 
${rx.notes ? `<div class="sec" style="margin-top:4px;">Notes / Remarks</div><div class="rx-notes">${rx.notes}</div>` : ''}
 
<hr class="dash">
 
<div class="dates">
    <div class="dt-box">
        <div class="dt-l">&#128197; Date Issued</div>
        <div class="dt-v">${issued}</div>
    </div>
    <div class="dt-box" style="text-align:right;">
        <div class="dt-l">&#x23F0; Valid Until</div>
        <div class="dt-exp">${expiry}</div>
    </div>
</div>
 
<hr class="solid">
<div class="sig-section">
    <div class="dr-role" style="margin-bottom:2px;">Prescribed by</div>
    <div class="sig-line"></div>
    <div class="dr-name">Dr. ${doctorName}</div>
    <div class="dr-role">Optometrist / Ophthalmologist</div>
</div>
 
<div class="qr-row">
    <img src="${qrUrl}" alt="QR" onerror="this.style.display='none'">
    <div class="qr-txt">
        <strong>Verification QR</strong><br>
        Scan to verify the<br>
        authenticity ng prescription.<br>
        Ref: APT-${rx.appointment_id || 'N/A'} &nbsp;·&nbsp; ${issued}
    </div>
</div>
 
<div class="footer">
    ★ Valid for 1 year from date issued ★<br>
    For optical use only. Keep this receipt.<br>
    Powered by EyeCore PH
</div>
 
<button class="print-btn" onclick="window.print()">🖨 PRINT PRESCRIPTION</button>
</body></html>`);
 
    win.document.close();
}

function loadAppointmentsTab(patientId) {
    $('#appointmentsContent').html('<div class="text-center py-4"><div class="spinner-border" style="color:var(--teal);"></div></div>');
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_appointments',
        method: 'GET',
        data: { patient_id: patientId },
        success: function(r) {
            if (r.success && r.appointments && r.appointments.length > 0) {
                const statusMap = {
                    'completed':   { badge: 'bg-success',            label: 'Completed'    },
                    'in_progress': { badge: 'bg-primary',            label: 'In Progress'  },
                    'waiting':     { badge: 'bg-warning text-dark',  label: 'Waiting'      },
                    'cancelled':   { badge: 'bg-secondary',          label: 'Cancelled'    },
                };
                const paymentMap = {
                    'paid':       { badge: 'bg-success bg-opacity-10 text-success border border-success', label: 'Paid'     },
                    'partial':    { badge: 'bg-warning bg-opacity-10 text-warning border border-warning', label: 'Partial'  },
                    'unpaid':     { badge: 'bg-danger  bg-opacity-10 text-danger  border border-danger',  label: 'Unpaid'   },
                    'refunded':   { badge: 'bg-info    bg-opacity-10 text-info    border border-info',    label: 'Refunded' },
                    'free':       { badge: 'bg-light text-muted border',                                  label: 'Free'     },
                };
                let html = `
                <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                  <tr style="background:#f8fafc;font-size:.74rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">
                    <th class="ps-3">Date & Time</th><th>Ref #</th><th>Service</th><th>Doctor</th><th>Amount</th><th>Payment</th><th>Status</th>
                   </tr>
                </thead><tbody>`;
                r.appointments.forEach(appt => {
                    const sKey  = appt.status_normalized || appt.status || '';
                    const sInfo = statusMap[sKey] || { badge: 'bg-light text-dark', label: appt.status || '—' };
                    const pKey  = (appt.payment_status || '').toLowerCase();
                    const pInfo = paymentMap[pKey] || { badge: 'bg-light text-muted border', label: appt.payment_status || '—' };
                    const typeLabel = appt.appointment_type === 'walk_in' ? '🚶 Walk-in' : appt.appointment_type === 'online' ? '🌐 Online' : appt.appointment_type || '';
                    const cancelNote = appt.cancellation_reason ? `<div class="text-danger" style="font-size:.65rem;"><i class="bi bi-x-circle me-1"></i>${appt.cancellation_reason}</div>` : '';
                    html += `
                    <tr style="font-size:.8rem;">
                        <td class="ps-3">
                            <div class="fw-600">${appt.formatted_date || appt.appointment_date || '—'}</div>
                            <div class="text-muted" style="font-size:.72rem;"><i class="bi bi-clock me-1"></i>${appt.appointment_time_formatted || '—'}</div>
                         </td>
                         <td><span class="text-muted" style="font-size:.72rem;">${appt.ref_no ? '#' + appt.ref_no : '—'}</span></td>
                         <td>
                            <div>${appt.product_name || appt.service_type || '—'}</div>
                            ${typeLabel ? `<div class="text-muted" style="font-size:.68rem;">${typeLabel}</div>` : ''}
                         </td>
                         <td>${appt.doctor_name ? 'Dr. ' + appt.doctor_name : '—'}</td>
                         <td>
                            ${appt.total_amount_formatted
                                ? `<div class="fw-600">${appt.total_amount_formatted}</div>
                                   ${appt.balance_amount > 0 ? `<div class="text-danger" style="font-size:.68rem;">Bal: ₱${parseFloat(appt.balance_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>` : ''}`
                                : '—'}
                         </td>
                         <td><span class="badge ${pInfo.badge}" style="font-size:.65rem;">${pInfo.label}</span></td>
                         <td><span class="badge ${sInfo.badge}" style="font-size:.65rem;">${sInfo.label}</span>${cancelNote}</td>
                     </tr>`;
                });
                html += '</tbody></table></div>';
                const total = r.total || r.appointments.length;
                html += `<div class="text-muted px-2 pt-2" style="font-size:.72rem;">Showing ${r.appointments.length} of ${total} appointment(s)</div>`;
                $('#appointmentsContent').html(html);
            } else {
                $('#appointmentsContent').html(`
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-x d-block mb-2 opacity-25" style="font-size:2rem;"></i>
                        <small>No appointment records found</small>
                    </div>`);
            }
        },
        error: function() { $('#appointmentsContent').html('<div class="alert alert-danger small py-2">Failed to load appointments.</div>'); }
    });
}

// ==================== DECISION SUPPORT ====================
function loadDecisionSupport(patientId, diagnosis) {
    if (!patientId) return;
    $('#decisionLoader').show();
    $('#decisionResults').html('<div class="text-center py-3 text-muted small">Loading AI analysis…</div>');
    $.ajax({
        url: 'api/doctor_dashboard.php?action=analyze',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ patient_id: patientId }),
        success: function(response) {
            if (response.success) displayDecisionResults(response);
            else $('#decisionResults').html('<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>Unable to analyze patient data.</div>');
            $('#decisionLoader').hide();
        },
        error: function() {
            $('#decisionResults').html('<div class="alert alert-danger small py-2"><i class="bi bi-x-circle me-1"></i>Error connecting to AI.</div>');
            $('#decisionLoader').hide();
        }
    });
}

function displayDecisionResults(data) {
    const riskLevel = data.risk_level || 'low';
    let rc = 'success', rIcon = 'bi-check-circle-fill', rLabel = 'LOW RISK';
    if (riskLevel === 'high')   { rc = 'danger';  rIcon = 'bi-exclamation-triangle-fill'; rLabel = 'HIGH RISK'; }
    if (riskLevel === 'medium') { rc = 'warning'; rIcon = 'bi-exclamation-circle-fill';   rLabel = 'MEDIUM RISK'; }
    const surgeryConditions = ['glaucoma','cataract','diabetic retinopathy','macular degeneration',
        'retinal detachment','corneal ulcer','uveitis','optic neuritis','keratoconus',
        'pterygium','strabismus','vitreous','retina','cornea transplant'];
    const diagnosisText = ($('#diagnosis').val() || data.diagnosis || '').toLowerCase();
    const needsReferral = riskLevel === 'high' || surgeryConditions.some(c => diagnosisText.includes(c));
    const recommendations = Array.isArray(data.recommendations) ? data.recommendations : [];
    const recoHtml = recommendations.length > 0 ? `
        <div class="mt-3 pt-3 border-top">
            <div class="fw-700 small mb-2" style="color:#374151;"><i class="bi bi-list-check me-1" style="color:var(--teal);"></i>Recommendations</div>
            <ul class="list-unstyled mb-0">
                ${recommendations.map(r => `
                <li class="d-flex align-items-start gap-2 mb-1 small text-muted">
                    <i class="bi bi-arrow-right-circle-fill flex-shrink-0 mt-1" style="color:var(--teal);font-size:.65rem;"></i>
                    <span>${r}</span>
                </li>`).join('')}
            </ul>
        </div>` : '';
    const referralHtml = `
        <div id="findCenterSection" class="mt-3 p-3 rounded-3"
             style="background:#fff7ed;border:1px solid #fed7aa;${needsReferral?'':'display:none;'}">
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle"
                     style="width:34px;height:34px;background:#fee2e2;">
                    <i class="bi bi-hospital text-danger"></i>
                </div>
                <div>
                    <div class="fw-700 small text-danger">Eye Surgery Referral Recommended</div>
                    <div class="small text-muted mt-1">This patient may need surgical evaluation. Find an Eyecore-registered clinic that offers eye surgery.</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-danger fw-600" onclick="showSurgeryClinicsModal()"><i class="bi bi-hospital me-1"></i>Find Surgery Clinics</button>
                <button class="btn btn-sm btn-outline-danger fw-600" onclick="printReferral()"><i class="bi bi-printer me-1"></i>Print Referral Letter</button>
            </div>
        </div>`;
    const bgColor   = rc==='danger' ? '#fef2f2' : rc==='warning' ? '#fffbeb' : '#f0fdf4';
    const bdColor   = rc==='danger' ? '#fecaca' : rc==='warning' ? '#fde68a' : '#bbf7d0';
    const textColor = rc==='danger' ? '#dc2626' : rc==='warning' ? '#d97706' : '#16a34a';
    $('#decisionResults').html(`
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-700 small" style="color:#1e293b;"><i class="bi bi-graph-up me-1" style="color:var(--teal);"></i>AI Clinical Analysis</span>
            <span class="badge bg-${rc} d-flex align-items-center gap-1 px-2 py-1" style="font-size:.7rem;"><i class="bi ${rIcon}"></i> ${rLabel}</span>
        </div>
        <div class="p-3 rounded-3" style="background:${bgColor};border:1px solid ${bdColor};">
            <div class="small fw-600" style="color:${textColor};"><i class="bi ${rIcon} me-1"></i>${data.risk_message || 'Clinical analysis complete.'}</div>
        </div>
        ${recoHtml}
        ${referralHtml}
    `);
    $('#decisionLoader').hide();
}

// ==================== SURGERY CLINICS MODAL ====================
function showSurgeryClinicsModal() {
    const diagnosis = $('#diagnosis').val() || '';
    const notes     = $('#notes').val() || '';
    $('#referralDiagnosis').val(diagnosis);
    $('#referralNotes').val(notes ? notes : 'Patient referred for surgical evaluation.');
    new bootstrap.Modal(document.getElementById('surgeryClinicsModal')).show();
    loadSurgeryClinics();
}

function loadSurgeryClinics() {
    $('#surgeryClinicsContainer').html(`<div class="text-center py-4"><div class="spinner-border mb-2" style="color:var(--teal);width:1.4rem;height:1.4rem;"></div><p class="text-muted small mb-0">Loading clinics…</p></div>`);
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_surgery_clinics', method: 'GET',
        success: function(r) {
            if (r.success && r.clinics && r.clinics.length > 0) renderSurgeryClinics(r.clinics);
            else $('#surgeryClinicsContainer').html(`<div class="text-center py-5 text-muted"><i class="bi bi-hospital d-block mb-2 opacity-25" style="font-size:2rem;"></i><p class="small mb-0">No registered surgery clinics found on the platform.</p></div>`);
        },
        error: function() { $('#surgeryClinicsContainer').html(`<div class="alert alert-danger small py-2"><i class="bi bi-x-circle me-1"></i>Failed to load clinics.</div>`); }
    });
}

function renderSurgeryClinics(clinics) {
    let html = '';
    clinics.forEach(c => {
        const logoUrl = c.logo ? `${baseUrl}uploads/${c.logo}` : `https://ui-avatars.com/api/?name=${encodeURIComponent(c.name)}&background=0d9488&color=fff&size=48`;
        const city = c.city || '', province = c.province || '';
        const location = [city, province].filter(Boolean).join(', ') || c.address || '—';
        const contact  = c.contact || c.phone || '—';
        html += `
        <div class="surgery-clinic-card d-flex align-items-start gap-3 p-3 mb-2 rounded-3"
             style="border:1px solid #e2e8f0;background:#fff;transition:box-shadow .15s;"
             onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.07)'" onmouseout="this.style.boxShadow='none'">
            <img src="${logoUrl}" alt="${c.name}" class="rounded-3 flex-shrink-0 object-fit-cover" style="width:48px;height:48px;"
                 onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(c.name)}&background=0d9488&color=fff&size=48'">
            <div class="flex-grow-1 min-width-0">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <div>
                        <div class="fw-700 small" style="color:#1e293b;">${c.name}</div>
                        <div class="text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i>${location}</div>
                        ${contact !== '—' ? `<div class="text-muted" style="font-size:.72rem;"><i class="bi bi-telephone me-1"></i>${contact}</div>` : ''}
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        ${c.average_rating > 0 ? `<span class="badge bg-warning text-dark" style="font-size:.65rem;"><i class="bi bi-star-fill me-1"></i>${parseFloat(c.average_rating).toFixed(1)}</span>` : ''}
                        <span class="badge bg-success" style="font-size:.65rem;"><i class="bi bi-scissors me-1"></i>Eye Surgery</span>
                    </div>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-teal fw-600" style="font-size:.75rem;"
                            onclick="confirmSendReferral(${c.id}, '${escapeString(c.name)}', '${escapeString(c.clinic_email||'')}', '${escapeString(contact)}')">
                        <i class="bi bi-send me-1"></i>Send Referral
                    </button>
                    ${c.clinic_email ? `
                    <button class="btn btn-sm btn-outline-secondary fw-600" style="font-size:.75rem;"
                            onclick="previewReferralEmail('${escapeString(c.name)}', '${escapeString(c.clinic_email)}')">
                        <i class="bi bi-envelope me-1"></i>Preview Email
                    </button>` : ''}
                </div>
            </div>
        </div>`;
    });
    $('#surgeryClinicsContainer').html(html);
}

function confirmSendReferral(clinicId, clinicName, clinicEmail, clinicContact) {
    const patientName = $('#patientName').text();
    const diagnosis   = $('#referralDiagnosis').val() || $('#diagnosis').val() || 'See clinical notes';
    const refNotes    = $('#referralNotes').val() || '';
    Swal.fire({
        icon: 'question', title: 'Send Referral?',
        html: `Refer <strong>${patientName}</strong> to<br><strong>${clinicName}</strong>?<br>
               <small class="text-muted">They will receive a notification and email about this referral.</small>`,
        showCancelButton: true, confirmButtonText: '<i class="bi bi-send me-1"></i>Send',
        confirmButtonColor: '#0d9488', cancelButtonText: 'Cancel'
    }).then(result => { if (result.isConfirmed) sendSurgeryReferral(clinicId, clinicName, clinicEmail, diagnosis, refNotes); });
}

function sendSurgeryReferral(clinicId, clinicName, clinicEmail, diagnosis, refNotes) {
    const patientName = $('#patientName').text(), patientAge = $('#patientAge').text();
    const patientGender = $('#patientGender').text(), doctorName = $('#doctorName').text();
    Swal.fire({ title: 'Sending referral…', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: 'api/doctor_dashboard.php?action=send_surgery_referral', method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ clinic_id:clinicId, clinic_name:clinicName, clinic_email:clinicEmail,
            patient_id:currentPatientId, patient_name:patientName, patient_age:patientAge,
            patient_gender:patientGender, appointment_id:currentAppointmentId,
            diagnosis:diagnosis, notes:refNotes, referring_doctor:doctorName }),
        success: function(r) {
            Swal.close();
            if (r.success) {
                bootstrap.Modal.getInstance(document.getElementById('surgeryClinicsModal'))?.hide();
                Swal.fire({ icon:'success', title:'Referral Sent!',
                    html:`<strong>${clinicName}</strong> has been notified about this patient.<br>
                          <small class="text-muted">${r.email_sent ? 'Email notification sent.' : 'In-app notification sent.'}</small>`,
                    timer:3000, showConfirmButton:false });
            } else Swal.fire('Error', r.message || 'Failed to send referral', 'error');
        },
        error: function() { Swal.close(); Swal.fire('Error', 'Connection error. Please try again.', 'error'); }
    });
}

function previewReferralEmail(clinicName, clinicEmail) {
    const patientName = $('#patientName').text(), patientAge = $('#patientAge').text();
    const patientGender = $('#patientGender').text();
    const diagnosis = $('#referralDiagnosis').val() || $('#diagnosis').val() || 'See clinical notes';
    const doctorName = $('#doctorName').text();
    const today = new Date().toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'});
    Swal.fire({
        title: 'Email Preview',
        html: `<div class="text-start p-2" style="font-size:.85rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
                <div class="mb-2"><strong>To:</strong> ${clinicEmail}</div>
                <div class="mb-2"><strong>Subject:</strong> Patient Referral – ${patientName} – ${diagnosis}</div>
                <hr style="border-color:#e2e8f0;">
                <p>Dear <strong>${clinicName}</strong>,</p>
                <p>We are referring the following patient to your clinic for evaluation and management:</p>
                <ul>
                    <li><strong>Patient Name:</strong> ${patientName}</li>
                    <li><strong>Age/Gender:</strong> ${patientAge} yrs / ${patientGender}</li>
                    <li><strong>Diagnosis:</strong> ${diagnosis}</li>
                    <li><strong>Referring Doctor:</strong> ${doctorName}</li>
                    <li><strong>Date:</strong> ${today}</li>
                </ul>
                <p>Kindly schedule this patient at your earliest convenience and provide the necessary surgical evaluation.</p>
                <p>Thank you.</p>
               </div>`,
        width: 560, showCancelButton: true,
        confirmButtonText: '<i class="bi bi-send me-1"></i>Send This',
        confirmButtonColor: '#0d9488', cancelButtonText: 'Close'
    }).then(result => { if (result.isConfirmed) confirmSendReferral(null, clinicName, clinicEmail, null); });
}

function printReferral(clinicName) {
    const patientName = $('#patientName').text();
    const diagnosis   = $('#referralDiagnosis').val() || $('#diagnosis').val() || 'See clinical notes';
    const doctorName  = $('#doctorName').text();
    const today       = new Date().toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'});
    const toClinic    = clinicName ? `<p><span class="lbl">To Clinic:</span> ${clinicName}</p>` : '';
    const win = window.open('','_blank','width=620,height=720');
    win.document.write(`<html><head><title>Referral Letter</title>
        <style>body{font-family:Arial,sans-serif;padding:40px;font-size:13px;color:#222;}
        h2{color:#0d9488;margin-bottom:4px;}.sub{color:#64748b;font-size:12px;margin-bottom:24px;}
        .lbl{font-weight:bold;color:#475569;}hr{border-color:#e2e8f0;margin:18px 0;}
        .sig{margin-top:60px;border-top:1px solid #ccc;padding-top:12px;font-size:11px;color:#94a3b8;}
        </style></head><body>
        <h2>Eyecore Clinic</h2>
        <div class="sub">Referral Letter &nbsp;·&nbsp; ${today}</div>
        <hr>
        <p><span class="lbl">Patient:</span> ${patientName}</p>
        ${toClinic}
        <p><span class="lbl">Referring Doctor:</span> ${doctorName}</p>
        <p><span class="lbl">Primary Diagnosis:</span> ${diagnosis}</p>
        <hr>
        <p>This patient is being referred for eye surgery evaluation and management by a qualified ophthalmologist.</p>
        <p>Kindly examine and provide the necessary care at your earliest convenience.</p>
        <p>Thank you for your cooperation.</p>
        <div class="sig">
            <p>Signed: ___________________________ &nbsp;&nbsp; Date: _______________</p>
            <p>Eyecore Clinic · Doctor Dashboard System</p>
        </div></body></html>`);
    win.document.close();
    win.print();
}

function exportPatientData(id) {
    Swal.fire({title:'Export Patient Data?',icon:'info',showCancelButton:true,confirmButtonText:'Export'}).then(r=>{
        if(r.isConfirmed){
            $.get(baseUrl+`api/patients.php?action=export&id=${id}`,function(r){
                if(r.success){ const blob=new Blob([JSON.stringify(r,null,2)],{type:'application/json'});
                    const url=window.URL.createObjectURL(blob); const a=document.createElement('a');
                    a.href=url; a.download=`patient_${id}_${new Date().toISOString().slice(0,10)}.json`;
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    Swal.fire({icon:'success',text:'Data exported',timer:1500});
                } else Swal.fire({icon:'error',text:r.message});
            },'json');
        }
    });
}

// ═══════════════════════════════════════════════════════════════
// ★ PRINT PRESCRIPTION — THERMAL 80mm STYLE
// ═══════════════════════════════════════════════════════════════
function printPrescription() {
    if (!currentAppointmentId && !currentPatientId) {
        Swal.fire('Warning', 'No patient selected.', 'warning');
        return;
    }

    const rx = {
        sph_r:  $('#rxSphR').val()  || '—',
        cyl_r:  $('#rxCylR').val()  || '—',
        axis_r: $('#rxAxisR').val() || '—',
        add_r:  $('#rxAddR').val()  || '—',
        sph_l:  $('#rxSphL').val()  || '—',
        cyl_l:  $('#rxCylL').val()  || '—',
        axis_l: $('#rxAxisL').val() || '—',
        add_l:  $('#rxAddL').val()  || '—',
        pd:     $('#rxPd').val()    || '—',
        pd_type:$('#rxPdType').val()|| 'Binocular',
        notes:  $('#rxNotes').val() || ''
    };

    const patientName   = $('#patientName').text()  || '—';
    const patientAge    = $('#patientAge').text()   || '—';
    const patientGender = $('#patientGender').text()|| '—';
    const doctorName    = $('#doctorName').text().replace('Dr. ', '') || '—';

    const today   = new Date();
    const issued  = today.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
    const expDate = new Date(today); expDate.setFullYear(expDate.getFullYear() + 1);
    const expiry  = expDate.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});

    const qrData  = encodeURIComponent(
        `EYECORE-RX\nPT:${patientName}\nOD:${rx.sph_r} ${rx.cyl_r} x${rx.axis_r} ADD${rx.add_r}\n` +
        `OS:${rx.sph_l} ${rx.cyl_l} x${rx.axis_l} ADD${rx.add_l}\nPD:${rx.pd}(${rx.pd_type})\n` +
        `DR:${doctorName}\nDATE:${issued}\nREF:APT-${currentAppointmentId||'N/A'}`
    );
    const qrUrl   = `https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=${qrData}`;

    const win = window.open('', '_blank', 'width=340,height=720,scrollbars=yes');
    if (!win) { Swal.fire('Blocked', 'Please allow popups for this site to print.', 'warning'); return; }

    win.document.write(`<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Rx — ${patientName}</title>
<style>
@page { size: 80mm auto; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Courier New', Courier, monospace;
    width: 302px; margin: 0 auto; padding: 10px 8px 16px;
    font-size: 9px; color: #1e293b; background: #fff;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.clinic-header { text-align:center; border-bottom:1px dashed #cbd5e1; padding-bottom:7px; margin-bottom:7px; }
.clinic-name { font-size:12px; font-weight:900; color:#0d9488; margin:3px 0 2px; letter-spacing:.03em; }
.clinic-sub { font-size:7.5px; color:#64748b; line-height:1.6; }
.rx-badge { display:inline-block; border:1px solid #0d9488; border-radius:3px; color:#0d9488;
            font-size:10px; font-weight:900; letter-spacing:.15em; padding:2px 8px; margin:5px 0 2px; }
.dash  { border:none; border-top:1px dashed #cbd5e1; margin:5px 0; }
.solid { border:none; border-top:1px solid #0d9488; margin:5px 0; }
.sec  { font-size:7px; font-weight:700; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; margin-bottom:2px; }
.pt-row { display:flex; justify-content:space-between; margin-bottom:2px; }
.pt-k { font-size:7.5px; color:#64748b; }
.pt-v { font-size:8.5px; font-weight:700; text-align:right; }
table.rx { width:100%; border-collapse:collapse; margin-bottom:3px; }
table.rx thead tr { background:#f0fdfa; }
table.rx thead th { font-size:7px; font-weight:800; text-transform:uppercase; letter-spacing:.05em;
                    text-align:center; color:#0d9488; padding:3px 2px; border-bottom:1px solid #99f6e4; }
table.rx thead th:first-child { text-align:left; }
table.rx tbody tr { border-bottom:1px dotted #e2e8f0; }
table.rx tbody tr:last-child { border-bottom:none; }
table.rx tbody td { font-size:9px; font-weight:700; text-align:center; padding:3px 2px; }
table.rx tbody td:first-child { text-align:left; font-size:8.5px; }
.eye-od { color:#0d9488; } .eye-os { color:#0891b2; }
.pd-row { display:flex; align-items:center; background:#f8fafc; border:1px solid #e2e8f0;
          border-radius:3px; padding:3px 5px; margin:4px 0; gap:8px; }
.pd-l { font-size:7.5px; color:#64748b; flex:1; }
.pd-v { font-size:9.5px; font-weight:900; }
.pd-t { font-size:7px; color:#94a3b8; }
.rx-notes { font-size:8px; color:#475569; line-height:1.5; border-left:2px solid #0d9488; padding-left:5px; margin:4px 0; }
.dates { display:flex; justify-content:space-between; margin:4px 0; }
.dt-box { text-align:center; }
.dt-l { font-size:7px; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
.dt-v { font-size:8.5px; font-weight:700; }
.dt-exp { font-size:8.5px; font-weight:700; color:#dc2626; }
.sig-section { text-align:center; margin-top:8px; }
.sig-line { border-bottom:1px solid #1e293b; width:120px; margin:18px auto 3px; }
.dr-name { font-size:9px; font-weight:700; }
.dr-role { font-size:7px; color:#64748b; }
.qr-row { display:flex; align-items:center; gap:6px; margin-top:7px; padding:5px;
          background:#f8fafc; border:1px dashed #e2e8f0; border-radius:3px; }
.qr-row img { width:52px; height:52px; }
.qr-txt { font-size:6.5px; color:#94a3b8; line-height:1.6; }
.qr-txt strong { color:#0d9488; font-size:7px; }
.footer { text-align:center; font-size:6.5px; color:#94a3b8; margin-top:8px; line-height:1.7; }
.print-btn { display:block; width:100%; padding:8px; background:#0d9488; color:#fff;
             font-size:11px; font-weight:700; border:none; border-radius:5px; cursor:pointer;
             margin-top:12px; letter-spacing:.04em; }
@media print { .print-btn { display:none !important; } }
</style></head><body>

<div class="clinic-header">
    <div class="clinic-name">👁 EYECORE OPTICAL CLINIC</div>
    <div class="clinic-sub">Eyecore PH &nbsp;·&nbsp; eyecore.com</div>
    <div class="rx-badge">&#x2772; Rx &#x2773;</div>
</div>

<div class="sec">Patient Information</div>
<div class="pt-row"><span class="pt-k">Name</span><span class="pt-v">${patientName}</span></div>
<div class="pt-row"><span class="pt-k">Age / Gender</span><span class="pt-v">${patientAge} yrs / ${patientGender}</span></div>
<hr class="dash">

<div class="sec" style="margin-bottom:3px;">Optical Prescription</div>
<table class="rx">
    <thead> <tr><th style="text-align:left;">Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th> </thead>
    <tbody>
        <tr>
            <td><span class="eye-od">OD ▶</span></span> </span>
            <td>${rx.sph_r}</span> </span>
            <td>${rx.cyl_r}</span> </span>
            <td>${rx.axis_r}</span> </span>
            <td>${rx.add_r}</span> </span>
         </span>
         </span>
            <td><span class="eye-os">OS ▶</span></span> </span>
            <td>${rx.sph_l}</span> </span>
            <td>${rx.cyl_l}</span> </span>
            <td>${rx.axis_l}</span> </span>
            <td>${rx.add_l}</span> </span>
         </span>
    </tbody>
</table>

<div class="pd-row">
    <span class="pd-l">Pupillary Distance (PD)</span>
    <span class="pd-v">${rx.pd}</span>
    <span class="pd-t">${rx.pd_type}</span>
</div>

${rx.notes ? `<div class="sec" style="margin-top:4px;">Notes / Remarks</div><div class="rx-notes">${rx.notes}</div>` : ''}

<hr class="dash">

<div class="dates">
    <div class="dt-box">
        <div class="dt-l">&#128197; Date Issued</div>
        <div class="dt-v">${issued}</div>
    </div>
    <div class="dt-box" style="text-align:right;">
        <div class="dt-l">&#x23F0; Valid Until</div>
        <div class="dt-exp">${expiry}</div>
    </div>
</div>

<hr class="solid">
<div class="sig-section">
    <div class="dr-role" style="margin-bottom:2px;">Prescribed by</div>
    <div class="sig-line"></div>
    <div class="dr-name">Dr. ${doctorName}</div>
    <div class="dr-role">Optometrist / Ophthalmologist</div>
</div>

<div class="qr-row">
    <img src="${qrUrl}" alt="QR" onerror="this.style.display='none'">
    <div class="qr-txt">
        <strong>Verification QR</strong><br>
        Scan to verify the<br>
        authenticity ng prescription.<br>
        Ref: APT-${currentAppointmentId||'N/A'} &nbsp;·&nbsp; ${issued}
    </div>
</div>

<div class="footer">
    ★ Valid for 1 year from date issued ★<br>
    For optical use only. Keep this receipt.<br>
    Powered by EyeCore PH
</div>

<button class="print-btn" onclick="window.print()">🖨 PRINT PRESCRIPTION</button>
</body></html>`);

    win.document.close();
}

async function promptDoctorProfileSetup() {
    let suggestedName = '';
    try {
        const check = await fetch('api/setup_doctor.php?action=check');
        const checkData = await check.json();
        if (checkData.exists) {
            await Swal.fire({
                icon: 'success',
                title: 'Doctor Profile Found',
                text: 'Your doctor profile has been linked. Refreshing page...',
                confirmButtonColor: '#0d9488',
                timer: 1500,
                showConfirmButton: false
            });
            location.reload();
            return true;
        }
        suggestedName = checkData.suggested_name || '';
    } catch (_) { }

    const { value: formValues, isConfirmed } = await Swal.fire({
        title: '🩺 Set Up Doctor Profile',
        html: `
            <p style="color:#6b7280;font-size:13px;margin-bottom:16px;">
                A doctor profile is required to claim patients and save clinical notes.
                This only needs to be done once.
            </p>
            <input  id="swal-doc-name"
                    class="swal2-input"
                    placeholder="Full name (e.g. Juan Dela Cruz)"
                    value="${suggestedName}">
            <input  id="swal-doc-spec"
                    class="swal2-input"
                    placeholder="Specialization (e.g. Optometrist)"
                    value="Optometrist">
            <input  id="swal-doc-license"
                    class="swal2-input"
                    placeholder="License No. (optional)">
        `,
        confirmButtonText: 'Set Up Profile',
        confirmButtonColor: '#0d9488',
        showCancelButton: true,
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        preConfirm: () => {
            const name = document.getElementById('swal-doc-name').value.trim();
            if (!name) {
                Swal.showValidationMessage('Please enter your full name');
                return false;
            }
            return {
                name,
                specialization: document.getElementById('swal-doc-spec').value.trim() || 'Optometrist',
                license_no:     document.getElementById('swal-doc-license').value.trim()
            };
        }
    });

    if (!isConfirmed || !formValues) return false;

    try {
        const res = await fetch('api/setup_doctor.php?action=setup', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(formValues)
        });
        const result = await res.json();

        if (!result.success) {
            await Swal.fire({
                icon: 'error',
                title: 'Setup Failed',
                text: result.message || 'Could not create doctor profile.',
                confirmButtonColor: '#0d9488'
            });
            return false;
        }

        await Swal.fire({
            icon:              'success',
            title:             'Doctor Profile Created! ✅',
            text:              'Refreshing page...',
            timer:             1500,
            showConfirmButton: false
        });
        
        location.reload();
        return true;

    } catch (err) {
        await Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: err.message,
            confirmButtonColor: '#0d9488'
        });
        return false;
    }
}

async function handleSaveNotes(payload) {
    const res = await fetch('api/doctor_dashboard_api.php?action=save_notes', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    });
    const data = await res.json();

    if (!data.success && data.code === 'NO_DOCTOR_PROFILE' && data.can_setup) {
        const setupOk = await promptDoctorProfileSetup();

        if (setupOk) {
            const retry = await fetch('api/doctor_dashboard_api.php?action=save_notes', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            });
            const retryData = await retry.json();

            if (retryData.success) {
                Swal.fire({
                    icon:              'success',
                    title:             'Notes Saved ✅',
                    timer:             1500,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon:  'error',
                    title: 'Save Failed',
                    text:  retryData.message || 'Please try again.',
                    confirmButtonColor: '#0d9488'
                });
            }
        }
        return;
    }

    if (data.success) {
        Swal.fire({
            icon:              'success',
            title:             'Notes Saved ✅',
            timer:             1500,
            showConfirmButton: false
        });
    } else {
        Swal.fire({
            icon:  'error',
            title: 'Error',
            text:  data.message || 'Failed to save notes.',
            confirmButtonColor: '#0d9488'
        });
    }
}

// ==================== START CONSULTATION FUNCTION ====================
function startConsultation() {
    if (!currentAppointmentId) {
        Swal.fire('Error', 'No appointment selected', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Start Consultation?',
        text: 'Begin consultation for this patient. Timer will start.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        confirmButtonText: 'Yes, Start',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({ 
                title: 'Starting consultation...', 
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            $.ajax({
                url: 'api/doctor_dashboard.php?action=start_consultation',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ 
                    appointment_id: currentAppointmentId,
                    patient_id: currentPatientId
                }),
                success: function(r) {
                    Swal.close();
                    if (r.success) {
                        // Mark as started in session
                        sessionStorage.setItem(`consultation_${currentAppointmentId}_started`, 'true');
                        
                        // Hide start button
                        $('#startConsultationBtnContainer').hide();
                        
                        // Enable input fields
                        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes').prop('disabled', false);
                        
                        // Start timer
                        startTimer();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Consultation Started',
                            text: 'Timer has started. You can now record examination details.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        
                        // Reload queue to update status
                        loadQueue();
                    } else {
                        Swal.fire('Error', r.message || 'Failed to start consultation', 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Connection error. Please try again.', 'error');
                }
            });
        }
    });
}

</script>