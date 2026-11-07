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
/* ════════ PALETTE ════════ */
:root {
    --teal:        #0d9488;
    --teal-dark:   #0f766e;
    --teal-soft:   #f0fdfa;
    --teal-light:  #ccfbf1;
    --ink:         #0f172a;
    --ink-2:       #1e293b;
    --text:        #334155;
    --muted:       #64748b;
    --muted-2:     #94a3b8;
    --line:        #e5e9ef;
    --line-2:      #eef2f6;
    --bg:          #f8fafc;
    --card:        #ffffff;
    --green:       #16a34a;
    --amber:       #d97706;
    --sky:         #0284c7;
}
* { box-sizing: border-box; }
#doctorDashboard {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 13px;
    color: var(--text);
    background: var(--bg);
    line-height: 1.45;

    /* ✅ FIX: kalkulahin base sa viewport, wag asa sa parent height */
    height: calc(100vh - 64px);   /* 64px = navbar mo + konting allowance */
    max-height: calc(100vh - 64px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    margin: 0;
}

/* SHELL */
.dd-shell {
    display: flex;
    height: 100%;
    width: 100%;
    overflow: hidden;
    flex: 1 1 auto;
    min-height: 0;      /* ✅ CRITICAL para gumana yung overflow scroll sa loob */
}
/* QUEUE PANEL */
.dd-queue {
    width: 264px;
    min-width: 264px;
    background: #fff;
    border-right: 1px solid var(--line);
    display: flex;
    flex-direction: column;
    padding: 18px 14px 14px;
    height: 100%;
    min-height: 0;         /* ✅ punuin yung buong height ng parent */
    overflow: hidden;      /* ✅ walang labas */
}
.dd-queue-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.dd-queue-head .title { font-size: 16px; font-weight: 800; color: var(--ink); letter-spacing: -.01em; }
.dd-queue-head i { color: var(--muted-2); font-size: 15px; }
.dd-queue-date { font-size: 11px; color: var(--muted); margin-bottom: 14px; }
.dd-stats { display: flex; justify-content: space-between; margin-bottom: 14px; padding: 0 2px; gap: 12px; }
.dd-stat { text-align: left; flex: 1; }
.dd-stat .sv { font-size: 22px; font-weight: 800; line-height: 1; color: var(--ink); }
.dd-stat .sl { font-size: 10.5px; color: var(--muted); margin-top: 4px; }
.dd-queue-tabs { display: flex; gap: 3px; background: #f1f5f9; padding: 3px; border-radius: 8px; margin-bottom: 14px; }
.dd-queue-tabs button {
    flex: 1; border: none; background: transparent; font-size: 11px; font-weight: 600;
    color: var(--muted); padding: 6px 4px; border-radius: 6px; cursor: pointer; transition: all .12s;
}
.dd-queue-tabs button.active { background: #fff; color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.dd-queue-scroll {
    flex: 1;
    min-height: 0;          /* ✅ CRITICAL */
    overflow-y: auto;
    padding-right: 2px;
    margin-right: -4px;
    margin-bottom: 12px;
}
.dd-queue-scroll::-webkit-scrollbar { width: 4px; }
.dd-queue-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
/* Queue item */
.queue-item {
    border: 1px solid var(--line); border-left: 3px solid transparent; border-radius: 10px;
    padding: 11px; margin-bottom: 7px; background: #fff; cursor: pointer; transition: all .15s;
}
.queue-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.queue-item.active { border-color: var(--teal); border-left-color: var(--teal); background: var(--teal-soft); }
.queue-item.waiting         { border-left-color: #fbbf24; }
.queue-item.in-progress     { border-left-color: var(--teal); }
.queue-item.completed       { border-left-color: var(--green); }
.queue-item.waiting_payment { border-left-color: #0284c7; }
.queue-item.sent-to-sales { border-left-color: #0284c7; }
.queue-item.sent-to-sales .qi-avatar { background: #e0f2fe; color: #0284c7; }
.queue-item.any-doctor      { border-left-color: var(--sky); }

/* Badge for sent to sales */
.status-badge.sent-to-sales { background: #e0f2fe; color: #0284c7; }
.queue-item.any-doctor      { border-left-color: var(--sky); }
.queue-item .qi-top { display: flex; align-items: center; gap: 9px; margin-bottom: 6px; }
.queue-item .qi-avatar {
    width: 28px; height: 28px; border-radius: 50%; background: #e0f2f1; color: var(--teal-dark);
    display: flex; align-items: center; justify-content: center; font-size: 10.5px;
    font-weight: 800; flex-shrink: 0; letter-spacing: .02em;
}
.queue-item.in-progress .qi-avatar { background: var(--teal-light); }
.queue-item.waiting .qi-avatar { background: #fef3c7; color: var(--amber); }
.queue-item.completed .qi-avatar { background: #dcfce7; color: var(--green); }
.queue-item .qi-body { flex: 1; min-width: 0; }
.queue-item .qi-name {
    font-weight: 700; font-size: 12.5px; color: var(--ink); line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.queue-item .qi-sub { font-size: 11px; color: var(--muted); margin-top: 3px; }
.queue-item .qi-status-line {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px; margin-top: 7px; font-weight: 600;
}
.queue-item .qi-status-line .status-txt { display: inline-flex; align-items: center; gap: 5px; }
.queue-item .qi-status-line.waiting         { color: var(--amber); }
.queue-item .qi-status-line.in-progress     { color: var(--teal-dark); }
.queue-item .qi-status-line.completed       { color: var(--green); }
.queue-item .qi-status-line.waiting_payment { color: #0284c7; }
.queue-item .qi-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; background: currentColor; }
.queue-item .qi-current { font-size: 10.5px; font-weight: 600; color: var(--muted-2); }
.queue-item.filtered-out { display: none !important; }
.q-lbl { font-size: 9.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: 8px 4px 4px; color: var(--muted-2); }
/* Queue bottom */
.dd-queue-bottom { border-top: 1px solid var(--line-2); padding-top: 12px; display: flex; flex-direction: column; gap: 8px; }
.dd-queue-bottom .btn-queue {
    background: #fff; border: 1px solid var(--line); color: var(--ink-2);
    font-size: 12.5px; font-weight: 600; padding: 11px 12px; border-radius: 9px;
    text-align: center; cursor: pointer; transition: all .12s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.dd-queue-bottom .btn-queue:hover { background: #f8fafc; border-color: #cbd5e1; }
.dd-queue-bottom .btn-queue i { font-size: 13px; }
.queue-count-note { text-align: center; font-size: 10.5px; color: var(--muted-2); padding: 4px 0 0; }
/* WORKSPACE */
.dd-ws {
    flex: 1;
    overflow-y: auto;
    padding: 18px 24px 24px;
    background: var(--bg);
    height: 100%;
    min-height: 0;          /* ✅ CRITICAL */
    min-width: 0;
}
.dd-ws::-webkit-scrollbar { width: 6px; }
.dd-ws::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.dd-empty { display: flex; align-items: center; justify-content: center; min-height: 70vh; }
/* PATIENT HEADER */
.pt-header-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 20px 22px 16px; margin-bottom: 16px; }
.pt-header-top { display: flex; align-items: flex-start; gap: 15px; }
.pt-avatar-lg {
    width: 52px; height: 52px; border-radius: 50%; background: var(--teal-light); color: var(--teal-dark);
    display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 800;
    flex-shrink: 0; letter-spacing: .03em;
}
.pt-name-lg {
    font-size: 22px; font-weight: 800; color: var(--ink); display: flex; align-items: center;
    gap: 10px; line-height: 1.15; letter-spacing: -.01em;
}
.pt-name-lg .status-badge {
    font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px;
    background: var(--teal-light); color: var(--teal-dark);
    display: inline-flex; align-items: center; gap: 5px; letter-spacing: 0;
}
.pt-name-lg .status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.pt-name-lg .status-badge.waiting { background: #fef3c7; color: var(--amber); }
.pt-name-lg .status-badge.completed { background: #dcfce7; color: var(--green); }
.pt-name-lg .status-badge.waiting-payment { background: #fef3c7; color: var(--amber); }
.pt-meta-line { font-size: 12.5px; color: var(--muted); margin-top: 6px; }
.pt-meta-line .dot { margin: 0 7px; color: var(--muted-2); }
.pt-header-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.pt-header-actions .btn-ghost {
    background: #fff; border: 1px solid var(--line); color: var(--ink-2);
    font-size: 12.5px; font-weight: 600; padding: 8px 14px; border-radius: 8px;
    display: inline-flex; align-items: center; gap: 7px; cursor: pointer; transition: all .12s;
}
.pt-header-actions .btn-ghost:hover { background: #f8fafc; }
.pt-header-actions .btn-ghost.icon-only { padding: 8px 10px; }
.pt-info-strip { display: flex; gap: 60px; padding-top: 16px; margin-top: 16px; border-top: 1px solid var(--line-2); }
.pt-info-strip .pi-label { font-size: 11px; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
.pt-info-strip .pi-value { font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.2; }
#timerWrap { display: none !important; }
/* TABS */
.pt-tabs-bar {
    display: flex; gap: 28px; border-bottom: 1px solid var(--line);
    margin-bottom: 20px; padding: 0 2px; background: transparent;
}
.pt-tabs-bar .tab-link {
    font-size: 13.5px; font-weight: 500; color: var(--muted); padding: 12px 0 13px;
    border: none; border-bottom: 2px solid transparent; background: none; cursor: pointer;
    margin-bottom: -1px; white-space: nowrap; transition: color .12s;
}
.pt-tabs-bar .tab-link.active { color: var(--teal-dark); border-bottom-color: var(--teal-dark); font-weight: 600; }
.pt-tabs-bar .tab-link:hover { color: var(--teal-dark); }
/* BODY GRID */
.pt-body-grid { display: grid; grid-template-columns: 1fr 296px; gap: 20px; align-items: start; }
/* SECTION CARDS */
.section-card {
    background: #fff; border: 1px solid var(--line); border-radius: 12px;
    padding: 18px 20px 20px; margin-bottom: 14px;
}
.section-card .sec-title {
    font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    color: var(--muted); margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
}
.section-card .sec-title .note { font-size: 11.5px; font-weight: 500; color: var(--muted-2); text-transform: none; letter-spacing: 0; }
/* FORM FIELDS */
.pt-body-grid .form-label { font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 6px; display: block; }
.pt-body-grid .form-control-sm,
.pt-body-grid .form-select-sm {
    font-size: 13px; font-weight: 500; border: 1px solid var(--line); border-radius: 8px;
    padding: 9px 12px; color: var(--ink); background: #fff;
    transition: border-color .12s, box-shadow .12s; height: auto;
}
.pt-body-grid .form-control-sm:focus,
.pt-body-grid .form-select-sm:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.10); outline: none; }
.pt-body-grid textarea.form-control-sm { resize: vertical; }
.field-grid { display: grid; gap: 14px 16px; }
.field-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.field-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
/* VA TABLE */
.va-table { width: 100%; border-collapse: collapse; }
.va-table thead th {
    font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    color: var(--muted); text-align: left; padding: 10px 0; border-bottom: 1px solid var(--line-2);
}
.va-table thead th:first-child { width: 130px; }
.va-table tbody td { padding: 14px 0; border-bottom: 1px solid var(--line-2); }
.va-table tbody tr:last-child td { border-bottom: none; }
.va-table tbody td:first-child { font-size: 12.5px; color: var(--muted); }
.va-table tbody td input {
    width: 100%; border: none; background: transparent;
    font-size: 14px; font-weight: 700; color: var(--ink); padding: 0;
}
.va-table tbody td input:focus { outline: none; }
/* REFRACTION TABLE */
.refr-table { width: 100%; border-collapse: collapse; }
.refr-table thead th { font-size: 12px; font-weight: 500; color: var(--muted); text-align: left; padding: 0 0 10px 0; }
.refr-table thead th:first-child { width: 130px; }
.refr-table tbody td { padding: 8px 6px 8px 0; vertical-align: middle; }
.refr-table tbody td:first-child { font-size: 13px; font-weight: 700; color: var(--ink); padding-left: 0; }
.refr-table tbody td input {
    width: 100%; font-size: 13.5px; font-weight: 700; color: var(--ink);
    border: 1px solid var(--line); border-radius: 8px; padding: 9px 12px; background: #fff;
}
.refr-table tbody td input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.10); }
/* CLINICAL SUMMARY */
.summary-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 20px; position: sticky; top: 12px; }
.summary-card .sum-title { font-size: 15px; font-weight: 800; color: var(--ink); margin-bottom: 16px; }
.summary-row {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 9px 0; font-size: 12.5px; border-bottom: 1px solid var(--line-2);
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row .sum-label { color: var(--muted); font-size: 12.5px; }
.summary-row .sum-value { font-weight: 700; color: var(--ink); text-align: right; max-width: 62%; font-size: 13px; }
.summary-subhead {
    font-size: 11px; font-weight: 500; color: var(--muted);
    margin: 14px 0 6px; padding-top: 14px; border-top: 1px solid var(--line-2);
}
.summary-va-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 4px 0 8px; }
.summary-va-cell { text-align: left; }
.summary-va-cell.ta-r { text-align: right; }
.summary-va-cell .lbl { font-size: 11px; color: var(--muted-2); font-weight: 500; }
.summary-va-cell .val { font-size: 15px; font-weight: 800; color: var(--ink); line-height: 1.3; margin-top: 2px; }
.summary-cta {
    width: 100%; margin-top: 18px; background: var(--teal); color: #fff; border: none;
    border-radius: 8px; padding: 11px 14px; font-size: 13px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; transition: background .15s;
}
.summary-cta:hover { background: var(--teal-dark); color: #fff; }
.summary-cta-note { font-size: 11px; color: var(--muted-2); text-align: center; margin-top: 7px; line-height: 1.45; }
.summary-save-link {
    display: block; text-align: center; font-size: 12px; color: var(--teal-dark);
    font-weight: 600; margin-top: 10px; text-decoration: none;
    background: none; border: none; width: 100%; padding: 4px; cursor: pointer;
}
.summary-save-link:hover { text-decoration: underline; }
/* ═══════════════════════════════════════════════════════
   ✅ LENS RECOMMENDATION CARD — REDESIGNED
   ═══════════════════════════════════════════════════════ */
.lens-rec-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 20px;
}

.lens-rec-card .sec-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--line-2);
}

.lens-rec-card .sec-title span:first-child {
    font-size: 14px;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -0.01em;
    text-transform: none;
}

.lens-rec-card .sec-title i {
    color: var(--teal);
}

/* ─── Groups ─── */
.lr-group {
    margin-bottom: 22px;
}
.lr-group:last-of-type {
    margin-bottom: 16px;
}

.lr-label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.lr-label i {
    color: var(--teal);
    font-size: 14px;
}

.lr-hint {
    font-size: 11px;
    font-weight: 500;
    color: var(--muted-2);
    text-transform: none;
    letter-spacing: 0;
    margin-left: 2px;
}

/* ─── Option Grid (Lens Design & Index) ─── */
.lr-option-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.lr-index-grid {
    grid-template-columns: repeat(4, 1fr);
}

@media (max-width: 900px) {
    .lr-index-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 600px) {
    .lr-option-grid,
    .lr-index-grid {
        grid-template-columns: 1fr;
    }
}

.lr-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 14px;
    background: #fff;
    border: 2px solid var(--line);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
    font-family: inherit;
}

.lr-option:hover {
    border-color: var(--teal);
    background: var(--teal-soft);
}

.lr-option.selected {
    border-color: var(--teal);
    background: var(--teal-soft);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

/* Radio circle */
.lr-radio {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 2px solid var(--muted-2);
    flex-shrink: 0;
    position: relative;
    transition: all 0.15s ease;
}

.lr-option.selected .lr-radio {
    border-color: var(--teal);
}

.lr-option.selected .lr-radio::after {
    content: '';
    position: absolute;
    inset: 3px;
    border-radius: 50%;
    background: var(--teal);
}

/* Info */
.lr-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.lr-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    line-height: 1.2;
}

.lr-option.selected .lr-title {
    color: var(--teal-dark);
}

.lr-desc {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 500;
    line-height: 1.3;
}

/* Recommended badge */
.lr-badge-rec {
    position: absolute;
    top: -8px;
    right: 10px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    font-size: 9px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.35);
    animation: pulseRec 2s ease-in-out infinite;
}

@keyframes pulseRec {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* Index option (narrower) */
.lr-index .lr-info {
    align-items: center;
    text-align: center;
}

.lr-index .lr-radio {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 14px;
    height: 14px;
}

.lr-index .lr-radio::after {
    inset: 2px;
}

.lr-index .lr-title {
    font-size: 15px;
    font-weight: 800;
}

.lr-index .lr-desc {
    font-size: 10.5px;
}

/* ─── Features (Checkboxes) ─── */
.lr-features-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.lr-feature {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 15px;
    background: #fff;
    border: 2px solid var(--line);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s ease;
    position: relative;
    user-select: none;
}

.lr-feature:hover {
    border-color: var(--teal);
    background: var(--teal-soft);
}

.lr-feature input[type="checkbox"] {
    display: none;
}

/* Custom checkbox */
.lr-check {
    width: 20px;
    height: 20px;
    border-radius: 5px;
    border: 2px solid var(--muted-2);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
    background: #fff;
    position: relative;
}

.lr-feature input:checked ~ .lr-check {
    background: var(--teal);
    border-color: var(--teal);
}

.lr-feature input:checked ~ .lr-check::after {
    content: '';
    width: 5px;
    height: 10px;
    border: solid #fff;
    border-width: 0 2.5px 2.5px 0;
    transform: rotate(45deg) translate(-1px, -1px);
}

.lr-feature:has(input:checked) {
    border-color: var(--teal);
    background: var(--teal-soft);
}

.lr-feature-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.lr-feature-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 7px;
}

.lr-feature-title i {
    font-size: 13px;
}

.lr-feature:has(input:checked) .lr-feature-title {
    color: var(--teal-dark);
}

.lr-feature-desc {
    font-size: 11.5px;
    color: var(--muted);
    font-weight: 500;
    line-height: 1.3;
}

/* Feature count */
.lr-feature-count {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    padding: 8px 12px;
    background: var(--teal-soft);
    border: 1px solid var(--teal-light);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--teal-dark);
}

.lr-feature-count i {
    color: var(--teal);
}

/* ─── Info Note ─── */
.lr-info-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    margin-top: 16px;
}

.lr-info-note i {
    color: #0284c7;
    font-size: 16px;
    flex-shrink: 0;
    margin-top: 1px;
}

.lr-info-note span {
    font-size: 12px;
    color: #0c4a6e;
    line-height: 1.5;
}

.lr-info-note strong {
    color: #0284c7;
}
/* LEGACY BUTTONS */
.btn-teal { background: var(--teal); color: #fff; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; padding: 9px 16px; }
.btn-teal:hover { background: var(--teal-dark); color: #fff; }
.btn-outline-teal { border: 1px solid var(--teal); color: var(--teal); background: transparent; border-radius: 8px; font-weight: 600; font-size: 13px; padding: 9px 16px; }
.btn-outline-teal:hover { background: var(--teal-soft); color: var(--teal-dark); }
/* RX TAB */
.rx-page-title { font-size: 20px; font-weight: 800; color: var(--ink); margin-bottom: 5px; letter-spacing: -.01em; }
.rx-page-sub { font-size: 12.5px; color: var(--muted); margin-bottom: 20px; }
.rx-header-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.rx-header-actions { display: flex; gap: 10px; align-items: center; }
.rx-draft-badge { font-size: 11.5px; font-weight: 600; color: var(--muted); background: #f1f5f9; padding: 5px 11px; border-radius: 6px; }
.rx-print-btn {
    background: #fff; border: 1px solid var(--line); color: var(--ink-2);
    font-size: 12.5px; font-weight: 600; padding: 8px 14px; border-radius: 8px;
    display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
}
.rx-print-btn:hover { background: #f8fafc; }
.rx-eye-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 16px 18px; margin-bottom: 12px; }
.rx-eye-title { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 14px; }
.rx-eye-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
.rx-eye-grid .form-label { font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 6px; }
.rx-eye-grid input {
    font-size: 13px; font-weight: 600; color: var(--ink);
    border: 1px solid var(--line); border-radius: 8px; padding: 9px 12px; width: 100%;
}
.rx-eye-grid input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.10); }
.rx-eye-footer { font-size: 11.5px; color: var(--muted); margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line-2); }
.rx-save-bar {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--line);
}
.rx-save-bar .who { font-size: 13px; color: var(--ink); font-weight: 700; }
.rx-save-bar .who-sub { font-size: 11.5px; color: var(--muted); margin-top: 3px; font-weight: 500; }
.rx-save-btn {
    background: var(--teal); color: #fff; border: none; border-radius: 8px;
    padding: 11px 22px; font-size: 13px; font-weight: 700; cursor: pointer;
}
.rx-save-btn:hover { background: var(--teal-dark); }
/* HISTORY */
.hist-item { border: 1px solid var(--line-2); border-radius: 9px; padding: 10px 12px; margin-bottom: 6px; transition: background .15s; }
.hist-item:hover { background: #f8fafc; }
.hist-scroll { max-height: 300px; overflow-y: auto; }
/* RESPONSIVE */
@media (max-width: 1100px) {
    .pt-body-grid { grid-template-columns: 1fr; }
    .summary-card { position: static; }
    .rx-eye-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 768px) {
    .dd-shell { flex-direction: column; height: auto; }
    .dd-queue { width: 100%; min-width: unset; border-right: none; border-bottom: 1px solid var(--line); max-height: 320px; }
    .pt-info-strip { flex-wrap: wrap; gap: 20px; }
    .rx-eye-grid { grid-template-columns: 1fr 1fr; }
}

/* ═══════════════════════════════════════════════════════
   ✅ Complete Consultation — PRIMARY BUTTON
   ═══════════════════════════════════════════════════════ */
.summary-cta-primary {
    background: linear-gradient(135deg, #0284c7, #0369a1) !important;
    padding: 14px 18px !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    transition: all 0.2s ease;
}

.summary-cta-primary:hover {
    background: linear-gradient(135deg, #0369a1, #075985) !important;
    box-shadow: 0 6px 16px rgba(2, 132, 199, 0.35);
    transform: translateY(-1px);
}

/* ═══════════════════════════════════════════════════════
   ✅ AUTO-SAVE INDICATOR
   ═══════════════════════════════════════════════════════ */
.autosave-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 12px;
    padding: 8px 12px;
    background: #f0fdfa;
    border: 1px solid var(--teal-light);
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--teal-dark);
    transition: all 0.3s ease;
}

.autosave-indicator.saving {
    background: #fffbeb;
    border-color: #fde68a;
    color: var(--amber);
}

.autosave-indicator.saved {
    background: #f0fdfa;
    border-color: var(--teal-light);
    color: var(--teal-dark);
}

.autosave-indicator.unsaved {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #92400e;
}

.autosave-indicator i {
    font-size: 14px;
}

.autosave-indicator.saving i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div id="doctorDashboard">



<div class="dd-shell">

    <!-- ══════ LEFT: PATIENT QUEUE ══════ -->
    <aside class="dd-queue">
        <div class="dd-queue-head">
            <div class="title">Patient Queue</div>
            <i class="bi bi-chevron-left"></i>
        </div>

        <div class="dd-queue-date"><?= date('l, d M Y') ?></div>

        <div class="dd-stats">
            <div class="dd-stat s-wait">
                <div class="sv" id="statWaiting">0</div>
                <div class="sl">Waiting</div>
            </div>
            <div class="dd-stat s-mine">
                <div class="sv" id="statProgress">0</div>
                <div class="sl">In Progress</div>
            </div>
            <div class="dd-stat s-done">
                <div class="sv" id="statCompleted">0</div>
                <div class="sl">Completed</div>
            </div>
        </div>

        <!-- Hidden stats — JS logic preserves -->
        <div style="display:none;">
            <span id="statWaitingPayment">0</span>
            <span id="statUpcoming">0</span>
        </div>

        <div class="dd-queue-tabs" id="queueTabs">
            <button class="active" data-filter="all" onclick="filterQueueTab(this,'all')">All</button>
            <button data-filter="waiting" onclick="filterQueueTab(this,'waiting')">Waiting</button>
            <button data-filter="in_progress" onclick="filterQueueTab(this,'in_progress')">In Progress</button>
        </div>

        <div class="dd-queue-scroll" id="queueContainer">
            <div class="text-center py-5">
                <div class="spinner-border mb-2" style="width:1.1rem;height:1.1rem;color:var(--teal);"></div>
                <p class="text-muted small mb-0" style="font-size:11px;">Loading queue…</p>
            </div>
        </div>

        <div class="queue-count-note" id="queueCountNote">Showing 0 of 0 patients</div>

        <div class="dd-queue-bottom">
            <?php if ($canCreateAppointments): ?>
            <button class="btn-queue" onclick="showWalkInModal()">
                <i class="bi bi-plus-lg"></i> Walk-in Patient
            </button>
            <?php endif; ?>
            <button class="btn-queue" onclick="showSearchModal()">
                <i class="bi bi-search"></i> Find Patient
            </button>
        </div>
    </aside>

    <!-- ══════ RIGHT: WORKSPACE ══════ -->
    <div class="dd-ws">

        <div class="dd-empty" id="noPatientPlaceholder">
            <div class="text-center">
                <div style="width:56px;height:56px;background:var(--teal-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                    <i class="bi bi-person-bounding-box" style="font-size:1.4rem;color:var(--teal-dark);"></i>
                </div>
                <h5 class="fw-bold" style="color:var(--ink);font-size:15px;">No Patient Selected</h5>
                <p class="text-muted small mb-4" style="font-size:12px;">Select from the queue, or add a walk-in patient</p>
                <?php if ($canCreateAppointments): ?>
                <button class="btn btn-teal" onclick="showWalkInModal()">
                    <i class="bi bi-person-plus me-2"></i>Add Walk-in
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="patientCard" style="display:none;">

            <form id="examForm" style="display:none;">
                <input type="hidden" id="currentAppointmentId">
                <input type="hidden" id="currentPatientId">
                <input type="hidden" id="currentUserId">
                <input type="hidden" id="isNewPatient" value="0">
                <input type="hidden" id="isAnyDoctor" value="0">
            </form>

            <!-- ══════ PATIENT HEADER ══════ -->
            <div class="pt-header-card">
                <div class="pt-header-top">
                    <div class="pt-avatar-lg" id="patientInitials">??</div>
                    <div>
                        <div class="pt-name-lg">
                            <span id="patientName">—</span>
                            <span class="status-badge" id="patientStatusBadge">Waiting</span>
                        </div>
                        <div class="pt-meta-line">
                            <span id="patientGender">—</span>
                            <span class="dot">·</span>
                            <span><span id="patientAge">—</span> years old</span>
                            <span class="dot">·</span>
                            <span>Patient ID: <span id="patientCode">—</span></span>
                        </div>
                    </div>
                    <div class="pt-header-actions">
                        <?php if ($canViewHistory): ?>
                        <button class="btn-ghost" onclick="viewFullHistory(currentPatientId)" id="viewFullHistoryBtn">
                            <i class="bi bi-clock-history"></i> Patient History
                        </button>
                        <?php endif; ?>
                        <button class="btn-ghost icon-only" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="font-size:12.5px;">
                            <li><a class="dropdown-item" href="#" onclick="showSavePatientModal();return false;"><i class="bi bi-pencil me-2"></i>Edit Patient</a></li>
                            <li><a class="dropdown-item" href="#" onclick="exportPatientData(currentPatientId);return false;"><i class="bi bi-download me-2"></i>Export Data</a></li>
                        </ul>
                    </div>
                </div>

<div class="pt-info-strip">
    <div>
        <div class="pi-label">Appointment</div>
        <div class="pi-value" id="appointmentTime">—</div>
    </div>
    <div>
        <div class="pi-label">Doctor</div>
        <div class="pi-value" id="doctorName">—</div>
    </div>
    <div>
        <div class="pi-label">Visit</div>
        <div class="pi-value" id="serviceName">—</div>
    </div>
    <div>
        <div class="pi-label">Selected Frame</div>
        <div class="pi-value" id="frameName">—</div>
    </div>
    <div>
        <div class="pi-label">Source</div>
        <div class="pi-value" id="orderSource">—</div>
    </div>
    <div id="timerWrap">
        <div class="pi-label">Timer</div>
        <div class="pi-value" id="timer">00:00</div>
    </div>
</div>

                <div id="anyDoctorAlert" style="display:none;" class="mt-3">
                    <div class="alert alert-info d-flex align-items-center gap-2 mb-0 py-2">
                        <i class="bi bi-people-fill"></i>
                        <div class="flex-grow-1 small"><strong>Any Doctor</strong> — Claim to begin</div>
                        <button class="btn btn-sm btn-teal" onclick="claimAppointment()">Claim</button>
                    </div>
                </div>
                <div id="newPatientAlert" style="display:none;" class="mt-3">
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-0 py-2">
                        <i class="bi bi-person-plus-fill"></i>
                        <div class="flex-grow-1 small"><strong>New Patient</strong> — Save after consultation</div>
                        <button class="btn btn-sm btn-teal" onclick="showSavePatientModal()">Save</button>
                    </div>
                </div>

                <div id="startConsultationBtnContainer" class="mt-3" style="display:none;">
                    <button class="btn btn-teal w-100 fw-bold" onclick="startConsultation()" style="padding:11px;font-size:13px;">
                        <i class="bi bi-play-fill me-2"></i> Start Visit
                    </button>
                    <div class="summary-cta-note">Opens the service-specific workflow for this patient</div>
                </div>
            </div>

<!-- ══════ TABS ══════ -->
<ul class="nav pt-tabs-bar" id="patientTabsBar" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="tab-link active" id="examTabLink" data-bs-toggle="tab" data-bs-target="#examTab" type="button" role="tab">Examination</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="tab-link" id="prescriptionTabLink" data-bs-toggle="tab" data-bs-target="#prescriptionTab" type="button" role="tab">Prescription</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="tab-link" id="historyTabLink" data-bs-toggle="tab" data-bs-target="#historyTab" type="button" role="tab">History</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="tab-link" id="decisionTabLink" data-bs-toggle="tab" data-bs-target="#decisionTab" type="button" role="tab">AI Analysis</button>
    </li>
</ul>

            <div class="tab-content">

                <!-- ═══════ EXAMINATION TAB ═══════ -->
                <div class="tab-pane fade show active" id="examTab">
                    <div class="pt-body-grid">

    <div class="exam-pane">

        <!-- ═══ PATIENT VITALS — ALWAYS VISIBLE ═══ -->
        <div class="section-card" data-form-section="vitals">
            <div class="sec-title">Patient Vitals</div>
            <div class="field-grid cols-3">
                <div>
                    <label class="form-label">Blood Pressure</label>
                    <input type="text" class="form-control form-control-sm" id="vitalBP" placeholder="112 / 74">
                </div>
                <div>
                    <label class="form-label">Pulse</label>
                    <input type="text" class="form-control form-control-sm" id="vitalPulse" placeholder="72">
                </div>
                <div>
                    <label class="form-label">Temperature</label>
                    <input type="text" class="form-control form-control-sm" id="vitalTemp" placeholder="36.6">
                </div>
            </div>
        </div>

        <!-- ═══ CHIEF COMPLAINT — ALWAYS VISIBLE ═══ -->
        <div class="section-card" id="chiefComplaintCard" data-form-section="chief_complaint">
            <div class="sec-title">
                <span>Chief Complaint</span>
                <span class="note">Patient's main concern</span>
            </div>
            <textarea class="form-control form-control-sm" id="chiefComplaint" rows="2" placeholder="Patient's main concern…"></textarea>
        </div>

        <!-- ═══ VISUAL ACUITY — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="visual_acuity">
            <div class="sec-title">
                <span>Visual Acuity</span>
                <span class="note">With correction</span>
            </div>
            <table class="va-table">
                <thead>
                    <tr>
                        <th>Measurement</th>
                        <th>RIGHT EYE (OD)</th>
                        <th>LEFT EYE (OS)</th>
                        <th>BOTH EYES (OU)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Distance</td>
                        <td><input type="text" id="vaRight" placeholder="20/20"></td>
                        <td><input type="text" id="vaLeft" placeholder="20/25"></td>
                        <td><input type="text" id="vaBoth" placeholder="20/20"></td>
                    </tr>
                    <tr>
                        <td>Near</td>
                        <td><input type="text" id="vaNearRight" placeholder="J1"></td>
                        <td><input type="text" id="vaNearLeft" placeholder="J2"></td>
                        <td><input type="text" id="vaNearBoth" placeholder="J1"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ═══ REFRACTION — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="refraction">
            <div class="sec-title">
                <span>Refraction</span>
                <span class="note">Diopters (D)</span>
            </div>
            <table class="refr-table">
                <thead>
                    <tr>
                        <th>Eye</th>
                        <th>Sphere</th>
                        <th>Cylinder</th>
                        <th>Axis</th>
                        <th>Add</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Right eye (OD)</td>
                        <td><input type="text" id="rxSphR" placeholder="-1.25"></td>
                        <td><input type="text" id="rxCylR" placeholder="-0.50"></td>
                        <td><input type="text" id="rxAxisR" placeholder="180°"></td>
                        <td><input type="text" id="rxAddR" placeholder="+0.00"></td>
                    </tr>
                    <tr>
                        <td>Left eye (OS)</td>
                        <td><input type="text" id="rxSphL" placeholder="-1.50"></td>
                        <td><input type="text" id="rxCylL" placeholder="-0.50"></td>
                        <td><input type="text" id="rxAxisL" placeholder="175°"></td>
                        <td><input type="text" id="rxAddL" placeholder="+0.00"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ═══ EYE EXAMINATION — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="external_exam">
            <div class="sec-title">
                <span>Eye Examination</span>
                <span class="note">Bilateral findings unless noted</span>
            </div>
            <div class="field-grid cols-2">
                <div>
                    <label class="form-label">External Examination</label>
                    <select class="form-select form-select-sm" id="examExternal">
                        <option>Normal</option><option>Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Pupil</label>
                    <select class="form-select form-select-sm" id="examPupils">
                        <option>PERRL, no RAPD</option><option>Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">EOM</label>
                    <select class="form-select form-select-sm" id="examEom">
                        <option>Full, both eyes</option><option>Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Conjunctiva</label>
                    <select class="form-select form-select-sm" id="examConj">
                        <option>Quiet, both eyes</option><option>Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Cornea</label>
                    <select class="form-select form-select-sm" id="examCornea">
                        <option>Clear, both eyes</option><option>Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Anterior Chamber</label>
                    <select class="form-select form-select-sm" id="examAC">
                        <option>Deep and quiet</option><option>Shallow</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Lens</label>
                    <select class="form-select form-select-sm" id="examLens">
                        <option>Clear, both eyes</option><option>Cataract</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Fundus</label>
                    <select class="form-select form-select-sm" id="examFundus">
                        <option>Normal, both eyes</option><option>Abnormal</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ═══ CONTACT LENS ASSESSMENT — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="contact_lens_assessment">
            <div class="sec-title">
                <span>Contact Lens Assessment</span>
                <span class="note">Fitting & base curve</span>
            </div>
            <div class="field-grid cols-3">
                <div>
                    <label class="form-label">Base Curve (BC)</label>
                    <input type="text" class="form-control form-control-sm" id="clBaseCurve" placeholder="e.g., 8.6">
                </div>
                <div>
                    <label class="form-label">Diameter (DIA)</label>
                    <input type="text" class="form-control form-control-sm" id="clDiameter" placeholder="e.g., 14.2">
                </div>
                <div>
                    <label class="form-label">Power (SPH)</label>
                    <input type="text" class="form-control form-control-sm" id="clPower" placeholder="e.g., -1.25">
                </div>
                <div>
                    <label class="form-label">Cylinder (CYL)</label>
                    <input type="text" class="form-control form-control-sm" id="clCylinder" placeholder="e.g., -0.50">
                </div>
                <div>
                    <label class="form-label">Axis</label>
                    <input type="text" class="form-control form-control-sm" id="clAxis" placeholder="e.g., 180">
                </div>
                <div>
                    <label class="form-label">Lens Type</label>
                    <select class="form-select form-select-sm" id="clLensType">
                        <option value="">Select</option>
                        <option value="daily">Daily Disposable</option>
                        <option value="monthly">Monthly Wear</option>
                        <option value="toric">Toric</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:14px;">
                <label class="form-label">Fit Assessment</label>
                <textarea class="form-control form-control-sm" id="clFitAssessment" rows="2" placeholder="Centration, movement, coverage..."></textarea>
            </div>
            <div style="margin-top:14px;">
                <label class="form-label">Trial Lens Used</label>
                <input type="text" class="form-control form-control-sm" id="clTrialLens" placeholder="Brand and model">
            </div>
        </div>

        <!-- ═══ GLAUCOMA SCREENING — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="glaucoma_screening">
            <div class="sec-title">
                <span>Glaucoma Screening</span>
                <span class="note">IOP & optic nerve</span>
            </div>
            <div class="field-grid cols-2">
                <div>
                    <label class="form-label">IOP — Right Eye (mmHg)</label>
                    <input type="text" class="form-control form-control-sm" id="iopRight" placeholder="e.g., 16">
                </div>
                <div>
                    <label class="form-label">IOP — Left Eye (mmHg)</label>
                    <input type="text" class="form-control form-control-sm" id="iopLeft" placeholder="e.g., 17">
                </div>
                <div>
                    <label class="form-label">Cup-to-Disc Ratio — OD</label>
                    <input type="text" class="form-control form-control-sm" id="cdRatioRight" placeholder="e.g., 0.3">
                </div>
                <div>
                    <label class="form-label">Cup-to-Disc Ratio — OS</label>
                    <input type="text" class="form-control form-control-sm" id="cdRatioLeft" placeholder="e.g., 0.3">
                </div>
            </div>
            <div class="field-grid cols-2" style="margin-top:14px;">
                <div>
                    <label class="form-label">Visual Field Test</label>
                    <select class="form-select form-select-sm" id="visualField">
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                        <option value="not_performed">Not Performed</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Risk Assessment</label>
                    <select class="form-select form-select-sm" id="glaucomaRisk">
                        <option value="low">Low Risk</option>
                        <option value="moderate">Moderate Risk</option>
                        <option value="high">High Risk</option>
                        <option value="suspected">Suspected Glaucoma</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ═══ RETINA SCREENING — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="retina_screening">
            <div class="sec-title">
                <span>Retina Screening</span>
                <span class="note">Fundus examination</span>
            </div>
            <div class="field-grid cols-2">
                <div>
                    <label class="form-label">Fundus — Right Eye</label>
                    <select class="form-select form-select-sm" id="fundusRight">
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Fundus — Left Eye</label>
                    <select class="form-select form-select-sm" id="fundusLeft">
                        <option value="normal">Normal</option>
                        <option value="abnormal">Abnormal</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:14px;">
                <label class="form-label">Findings</label>
                <textarea class="form-control form-control-sm" id="retinaFindings" rows="2"></textarea>
            </div>
        </div>

        <!-- ═══ CATARACT EVALUATION — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="cataract_evaluation">
            <div class="sec-title">
                <span>Cataract Evaluation</span>
                <span class="note">Lens opacity grading</span>
            </div>
            <div class="field-grid cols-2">
                <div>
                    <label class="form-label">Lens Opacity — Right Eye</label>
                    <select class="form-select form-select-sm" id="cataractRight">
                        <option value="none">None</option>
                        <option value="mild">Mild</option>
                        <option value="moderate">Moderate</option>
                        <option value="severe">Severe</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Lens Opacity — Left Eye</label>
                    <select class="form-select form-select-sm" id="cataractLeft">
                        <option value="none">None</option>
                        <option value="mild">Mild</option>
                        <option value="moderate">Moderate</option>
                        <option value="severe">Severe</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:14px;">
                <label class="form-label">Recommendation</label>
                <textarea class="form-control form-control-sm" id="cataractRecommendation" rows="2"></textarea>
            </div>
        </div>

        <!-- ═══ TREATMENT NOTES — DYNAMIC ═══ -->
        <div class="section-card" data-form-section="treatment_notes">
            <div class="sec-title">
                <span>Treatment Notes</span>
                <span class="note">Medication & instructions</span>
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Treatment Given</label>
                <textarea class="form-control form-control-sm" id="treatmentGiven" rows="3"></textarea>
            </div>
            <div>
                <label class="form-label">Follow-up Instructions</label>
                <textarea class="form-control form-control-sm" id="followupInstructions" rows="2"></textarea>
            </div>
        </div>

        <!-- ═══ ASSESSMENT — ALWAYS VISIBLE ═══ -->
        <div class="section-card" data-form-section="assessment">
            <div class="sec-title">Assessment</div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Clinical findings / diagnosis</label>
                <textarea class="form-control form-control-sm" id="findings" rows="3" placeholder="Enter clinical findings…"></textarea>
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Diagnosis</label>
                <input type="text" class="form-control form-control-sm" id="diagnosis" placeholder="e.g., Myopia, bilateral">
            </div>
            <div style="margin-bottom:14px;">
                <label class="form-label">Treatment Plan</label>
                <input type="text" class="form-control form-control-sm" id="treatmentPlan" placeholder="e.g., Prescribe glasses">
            </div>
            <div>
                <label class="form-label">Notes</label>
                <textarea class="form-control form-control-sm" id="notes" rows="3" placeholder="Additional remarks…"></textarea>
            </div>
        </div>

        <!-- ═══ LENS RECOMMENDATION — DYNAMIC ═══ -->
        <div class="section-card lens-rec-card" id="lensRecommendationCard" data-form-section="lens_recommendation">
            <div class="sec-title">
                <span><i class="bi bi-eyeglasses me-2"></i>Lens Recommendation</span>
                <span class="note">Clinical recommendation for Sales/Optical</span>
            </div>

            <!-- Lens Design -->
            <div class="lr-group">
                <label class="lr-label">
                    <i class="bi bi-rulers"></i> Lens Design
                </label>
                <div class="lr-option-grid" id="recLensDesignGrid">
                    <button type="button" class="lr-option" data-value="Single Vision" onclick="selectRecDesign(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">Single Vision</span>
                            <span class="lr-desc">For near OR far vision</span>
                        </div>
                    </button>
                    <button type="button" class="lr-option" data-value="Progressive" onclick="selectRecDesign(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">Progressive</span>
                            <span class="lr-desc">Near, mid & far vision</span>
                        </div>
                        <span class="lr-badge-rec">Recommended</span>
                    </button>
                    <button type="button" class="lr-option" data-value="Bifocal" onclick="selectRecDesign(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">Bifocal</span>
                            <span class="lr-desc">Two-zone vision</span>
                        </div>
                    </button>
                    <button type="button" class="lr-option" data-value="Reading" onclick="selectRecDesign(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">Reading</span>
                            <span class="lr-desc">Near vision only</span>
                        </div>
                    </button>
                </div>
                <input type="hidden" id="recLensDesign" value="">
            </div>

            <!-- Lens Index -->
            <div class="lr-group">
                <label class="lr-label">
                    <i class="bi bi-layers"></i> Lens Index <span class="lr-hint">(Thickness)</span>
                </label>
                <div class="lr-option-grid lr-index-grid" id="recLensIndexGrid">
                    <button type="button" class="lr-option lr-index" data-value="1.56 Standard" onclick="selectRecIndex(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">1.56</span>
                            <span class="lr-desc">Standard</span>
                        </div>
                    </button>
                    <button type="button" class="lr-option lr-index" data-value="1.60 Thin" onclick="selectRecIndex(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">1.60</span>
                            <span class="lr-desc">Thin</span>
                        </div>
                        <span class="lr-badge-rec">Recommended</span>
                    </button>
                    <button type="button" class="lr-option lr-index" data-value="1.67 Ultra Thin" onclick="selectRecIndex(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">1.67</span>
                            <span class="lr-desc">Ultra Thin</span>
                        </div>
                    </button>
                    <button type="button" class="lr-option lr-index" data-value="1.74 High Index" onclick="selectRecIndex(this)">
                        <div class="lr-radio"></div>
                        <div class="lr-info">
                            <span class="lr-title">1.74</span>
                            <span class="lr-desc">High Index</span>
                        </div>
                    </button>
                </div>
                <input type="hidden" id="recLensIndex" value="">
            </div>

            <!-- Features -->
            <div class="lr-group">
                <label class="lr-label">
                    <i class="bi bi-stars"></i> Features <span class="lr-hint">(Optional — multi-select)</span>
                </label>
                <div class="lr-features-list" id="recLensFeaturesList">
                    <div class="lr-feature" onclick="toggleRecFeature(this, event)">
                        <input type="checkbox" value="Blue Cut">
                        <div class="lr-check"></div>
                        <div class="lr-feature-info">
                            <span class="lr-feature-title">
                                <i class="bi bi-shield-fill" style="color:#3b82f6;"></i> Blue Cut
                            </span>
                            <span class="lr-feature-desc">Reduces screen glare & eye strain</span>
                        </div>
                    </div>
                    <div class="lr-feature" onclick="toggleRecFeature(this, event)">
                        <input type="checkbox" value="Anti-Reflective (AR)">
                        <div class="lr-check"></div>
                        <div class="lr-feature-info">
                            <span class="lr-feature-title">
                                <i class="bi bi-eye-fill" style="color:#8b5cf6;"></i> Anti-Reflective (AR)
                            </span>
                            <span class="lr-feature-desc">Reduces reflections & improves clarity</span>
                        </div>
                    </div>
                    <div class="lr-feature" onclick="toggleRecFeature(this, event)">
                        <input type="checkbox" value="Photochromic">
                        <div class="lr-check"></div>
                        <div class="lr-feature-info">
                            <span class="lr-feature-title">
                                <i class="bi bi-brightness-high-fill" style="color:#f59e0b;"></i> Photochromic
                            </span>
                            <span class="lr-feature-desc">Darkens automatically in sunlight</span>
                        </div>
                    </div>
                    <div class="lr-feature" onclick="toggleRecFeature(this, event)">
                        <input type="checkbox" value="Scratch Resistant">
                        <div class="lr-check"></div>
                        <div class="lr-feature-info">
                            <span class="lr-feature-title">
                                <i class="bi bi-shield-check" style="color:#10b981;"></i> Scratch Resistant
                            </span>
                            <span class="lr-feature-desc">Durable protective coating</span>
                        </div>
                    </div>
                    <div class="lr-feature" onclick="toggleRecFeature(this, event)">
                        <input type="checkbox" value="UV Protection">
                        <div class="lr-check"></div>
                        <div class="lr-feature-info">
                            <span class="lr-feature-title">
                                <i class="bi bi-sun-fill" style="color:#ef4444;"></i> UV Protection
                            </span>
                            <span class="lr-feature-desc">Blocks harmful UV rays</span>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="recLensFeature" value="">
                <div class="lr-feature-count" id="recFeatureCount" style="display:none;">
                    <i class="bi bi-check-circle-fill"></i> <span id="recFeatureCountText">0 features selected</span>
                </div>
            </div>

            <!-- Clinical Notes -->
            <div class="lr-group">
                <label class="lr-label">
                    <i class="bi bi-chat-left-text"></i> Clinical Notes for Sales
                </label>
                <textarea class="form-control form-control-sm" id="recLensNotes" rows="2"
                          placeholder="e.g., Patient needs blue light protection for computer work…"></textarea>
            </div>

            <!-- Info Note -->
            <div class="lr-info-note">
                <i class="bi bi-info-circle-fill"></i>
                <span>Ito ang baseline recommendation. Ang <strong>Sales/Optical</strong> ang mag-fi-finalize ng price at order.</span>
            </div>
        </div>

    </div><!-- ✅ CLOSE exam-pane -->

    <!-- ═══ CLINICAL SUMMARY — SA LABAS ng exam-pane ═══ -->
    <aside class="summary-card">
        <div class="sum-title">Clinical Summary</div>

        <div class="summary-row">
            <span class="sum-label">Patient</span>
            <span class="sum-value" id="csPatientName">—</span>
        </div>
        <div class="summary-row">
            <span class="sum-label">Age</span>
            <span class="sum-value" id="csAgeGender">—</span>
        </div>
        <div class="summary-row">
            <span class="sum-label">Visit</span>
            <span class="sum-value" id="csVisit">—</span>
        </div>

        <div class="summary-subhead">Visual Acuity</div>
        <div class="summary-va-grid">
            <div class="summary-va-cell">
                <div class="lbl">OD</div>
                <div class="val" id="csVaOd">—</div>
            </div>
            <div class="summary-va-cell ta-r">
                <div class="lbl">OS</div>
                <div class="val" id="csVaOs">—</div>
            </div>
        </div>

        <div class="summary-subhead">Assessment</div>
        <div class="summary-row" style="border:none;padding-top:0;">
            <span class="sum-value" id="csAssessment" style="text-align:left;max-width:100%;">—</span>
        </div>

        <div class="summary-subhead">Status</div>
        <div class="summary-row" style="border:none;padding-top:0;">
            <span class="sum-value" id="csStatus" style="text-align:left;max-width:100%;display:inline-flex;align-items:center;gap:6px;color:var(--teal-dark);">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--teal);display:inline-block;"></span>
                In Examination
            </span>
        </div>

        <!-- ═══ PRIMARY: Complete Consultation ═══ -->
        <button type="button" class="summary-cta summary-cta-primary" onclick="sendToSales()" id="sendToSalesBtn">
            <i class="bi bi-arrow-right-circle-fill"></i> Complete Consultation
        </button>
        <div class="summary-cta-note">
            <i class="bi bi-info-circle me-1"></i>
            Save all data & Complete Consultation/Optical for final pricing.
        </div>

        <!-- ═══ SECONDARY: Pause & Save ═══ -->
        <button type="button" class="summary-save-link" onclick="pauseAndSave()">
            <i class="bi bi-pause-circle me-1"></i>Pause & Save
        </button>

        <!-- ═══ AUTO-SAVE INDICATOR ═══ -->
        <div class="autosave-indicator" id="autosaveIndicator" style="display:none;">
            <i class="bi bi-cloud-check"></i>
            <span id="autosaveText">Saved</span>
        </div>
    </aside>

</div><!-- ✅ CLOSE pt-body-grid -->

</div>

                <!-- ═══════ PRESCRIPTION TAB ═══════ -->
                <div class="tab-pane fade" id="prescriptionTab">
                    <form id="rxForm">

                        <div class="rx-header-row">
                            <div>
                                <div class="rx-page-title">Prescription</div>
                                <div class="rx-page-sub">Medication and optical correction · <?= date('d M Y') ?></div>
                            </div>
                            <div class="rx-header-actions">
                                <span class="rx-draft-badge">Draft</span>
                                <button type="button" class="rx-print-btn" onclick="printPrescription()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </div>

                        <!-- Spectacle prescription (existing Rx inputs) -->
                        <div style="margin: 4px 0 14px;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;">
                                <div style="font-size:15px;font-weight:800;color:var(--ink);">Spectacle prescription</div>
                                <div style="font-size:11.5px;color:var(--muted-2);">Sphere / Cylinder in D · PD in mm</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="rx-eye-card">
                                    <div class="rx-eye-title">OD Prescription · Right eye</div>
                                    <div class="rx-eye-grid">
                                        <div>
                                            <label class="form-label">Sphere</label>
                                            <input type="text" id="rxSphR_p" placeholder="-2.00">
                                        </div>
                                        <div>
                                            <label class="form-label">Cylinder</label>
                                            <input type="text" id="rxCylR_p" placeholder="-0.50">
                                        </div>
                                        <div>
                                            <label class="form-label">Axis</label>
                                            <input type="text" id="rxAxisR_p" placeholder="180">
                                        </div>
                                        <div>
                                            <label class="form-label">Add</label>
                                            <input type="text" id="rxAddR_p" placeholder="+2.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="rx-eye-card">
                                    <div class="rx-eye-title">OS Prescription · Left eye</div>
                                    <div class="rx-eye-grid">
                                        <div>
                                            <label class="form-label">Sphere</label>
                                            <input type="text" id="rxSphL_p" placeholder="-1.75">
                                        </div>
                                        <div>
                                            <label class="form-label">Cylinder</label>
                                            <input type="text" id="rxCylL_p" placeholder="-0.25">
                                        </div>
                                        <div>
                                            <label class="form-label">Axis</label>
                                            <input type="text" id="rxAxisL_p" placeholder="175">
                                        </div>
                                        <div>
                                            <label class="form-label">Add</label>
                                            <input type="text" id="rxAddL_p" placeholder="+2.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PD + PD Type (existing) -->
                        <div class="field-grid cols-2" style="margin-top:8px;">
                            <div>
                                <label class="form-label">PD</label>
                                <input type="text" class="form-control form-control-sm" id="rxPd" placeholder="62mm">
                            </div>
                            <div>
                                <label class="form-label">PD Type</label>
                                <select class="form-select form-select-sm" id="rxPdType">
                                    <option value="Binocular">Binocular</option>
                                    <option value="Monocular">Monocular</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:14px;">
                            <label class="form-label">Rx Notes</label>
                            <textarea class="form-control form-control-sm" id="rxNotes" rows="2" placeholder="Lens type, coating…"></textarea>
                        </div>

                        <!-- Save bar -->
                        <div class="rx-save-bar">
                            <div>
                                <div class="who" id="rxPrescribingDoctor">Dr. —</div>
                                <div class="who-sub">Prescribing doctor · <?= date('d M Y') ?> · <span id="rxPatientCode">PT-000000</span></div>
                            </div>
                            <div class="d-flex gap-2">
                                <?php if ($canCreatePrescriptions || $canEditPrescriptions): ?>
                                <button type="button" class="rx-save-btn" onclick="savePrescriptionOnly()">
                                    <i class="bi bi-check-lg me-1"></i> Save Prescription
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                    </form>
                </div>

                <!-- ═══════ HISTORY TAB ═══════ -->
                <div class="tab-pane fade" id="historyTab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-bold" style="color:var(--ink);font-size:13.5px;">
                            <i class="bi bi-clock-history me-1" style="color:var(--teal);"></i>Visit History
                        </span>
                        <?php if ($canViewHistory): ?>
                        <button class="btn btn-sm btn-outline-teal px-3 py-1" style="font-size:12px;" onclick="viewFullHistory(currentPatientId)">
                            View All <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                        <?php endif; ?>
                    </div>

                    <div id="historySidebar" class="hist-scroll">
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-arrow-left-circle d-block mb-1 opacity-30" style="font-size:1.5rem;"></i>
                            <small style="font-size:12px;">Select a patient to view history</small>
                        </div>
                    </div>

                    <div class="mt-3 pt-2 border-top">
                        <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted-2);" class="mb-1">Last Prescription</div>
                        <div id="lastRxSummary" class="small text-muted" style="font-size:12px;">No previous prescription</div>
                    </div>
                </div>

                <!-- ═══════ AI ANALYSIS TAB ═══════ -->
                <div class="tab-pane fade" id="decisionTab">
                    <div id="decisionContent">
                        <div class="text-center py-4">
                            <div class="spinner-border mb-2" id="decisionLoader" style="display:none;width:1.2rem;height:1.2rem;color:var(--teal);"></div>
                            <div id="decisionResults">
                                <i class="bi bi-graph-up-arrow d-block mb-2 opacity-25" style="font-size:1.6rem;color:var(--teal);"></i>
                                <p class="text-muted small" style="font-size:12.5px;">Select a patient to see AI analysis</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-content -->
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

<!-- ═══════════════════════════════════════
     QUICK PRESCRIPTION VALIDATION MODAL
═══════════════════════════════════════ -->
<div class="modal fade" id="quickRxModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" style="background:#0284c7;color:#fff;">
                <h5 class="modal-title fw-700">
                    <i class="bi bi-file-medical me-2"></i>Validate Existing Prescription
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Customer provided an existing prescription. I-validate lang — hindi na kailangan ng full eye exam.
                </div>
                <form id="quickRxForm">
                    <input type="hidden" id="quickRxAppointmentId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-600 small">Prescription Date</label>
                            <input type="date" class="form-control form-control-sm" id="quickRxDate">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-600 small">Prescribing Doctor</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxDoctor" 
                                   placeholder="Dr. Name">
                        </div>
                        <div class="col-12">
                            <hr class="my-2">
                            <div class="fw-700 small mb-2" style="color:var(--teal);">
                                <i class="bi bi-eye me-1"></i>RIGHT EYE (OD)
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Sphere</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxSphR" placeholder="-2.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Cylinder</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxCylR" placeholder="-0.50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Axis</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxAxisR" placeholder="180">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Add</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxAddR" placeholder="+2.00">
                        </div>
                        <div class="col-12">
                            <div class="fw-700 small mb-2" style="color:var(--teal);">
                                <i class="bi bi-eye me-1"></i>LEFT EYE (OS)
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Sphere</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxSphL" placeholder="-1.75">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Cylinder</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxCylL" placeholder="-0.25">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Axis</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxAxisL" placeholder="175">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-600 small">Add</label>
                            <input type="text" class="form-control form-control-sm" id="quickRxAddL" placeholder="+2.00">
                        </div>
                        <div class="col-12">
                            <hr class="my-2">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-600 small">Validation Notes</label>
                            <textarea class="form-control form-control-sm" id="quickRxNotes" rows="2"
                                      placeholder="e.g., Prescription is valid, within 1 year…"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="quickRxValid" checked>
                                <label class="form-check-label small fw-600" for="quickRxValid">
                                    I confirm this prescription is valid and can proceed to Sales
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-600" onclick="submitQuickRxValidation()">
                    <i class="bi bi-check-lg me-1"></i> Validate & Complete Consultation
                </button>
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

// ✅ BAGO: Flag para malaman kung may product ang appointment
let currentAppointmentHasProduct = false;/* ═══════════════════════════════════════════════════════
   ✅ LENS RECOMMENDATION — REDESIGNED HANDLERS
   ═══════════════════════════════════════════════════════ */

// Select Lens Design
function selectRecDesign(btn) {
    document.querySelectorAll('#recLensDesignGrid .lr-option').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    const value = btn.dataset.value;
    document.getElementById('recLensDesign').value = value;
    console.log('Lens Design selected:', value);
}

// Select Lens Index
function selectRecIndex(btn) {
    document.querySelectorAll('#recLensIndexGrid .lr-option').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    const value = btn.dataset.value;
    document.getElementById('recLensIndex').value = value;
    console.log('Lens Index selected:', value);
}

// Toggle feature checkbox
function toggleRecFeature(el, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const checkbox = el.querySelector('input[type="checkbox"]');
    if (!checkbox) return;
    checkbox.checked = !checkbox.checked;
    updateRecFeatures();
    console.log('Toggled feature:', checkbox.value, '→', checkbox.checked);
}

function updateRecFeatures() {
    const checked = [];
    document.querySelectorAll('#recLensFeaturesList input[type="checkbox"]:checked').forEach(cb => {
        checked.push(cb.value);
    });
    
    // Update hidden input (for backward compatibility)
    document.getElementById('recLensFeature').value = JSON.stringify(checked);
    
    // Update count display
    const countEl = document.getElementById('recFeatureCount');
    const countText = document.getElementById('recFeatureCountText');
    if (checked.length > 0) {
        countEl.style.display = 'flex';
        countText.textContent = checked.length + ' feature' + (checked.length > 1 ? 's' : '') + ' selected';
    } else {
        countEl.style.display = 'none';
    }
    
    console.log('Features selected:', checked);
}
// Helper: Load saved values
function loadLensRecommendation(notes) {
    if (!notes) return;
    
    // Reset
    document.querySelectorAll('#recLensDesignGrid .lr-option').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('#recLensIndexGrid .lr-option').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('#recLensFeaturesList input[type="checkbox"]').forEach(cb => cb.checked = false);
    
    // Load Lens Design
    if (notes.rec_lens_design) {
        const designBtn = document.querySelector(`#recLensDesignGrid .lr-option[data-value="${notes.rec_lens_design}"]`);
        if (designBtn) {
            designBtn.classList.add('selected');
            document.getElementById('recLensDesign').value = notes.rec_lens_design;
        }
    }
    
    // Load Lens Index
    if (notes.rec_lens_index) {
        const indexBtn = document.querySelector(`#recLensIndexGrid .lr-option[data-value="${notes.rec_lens_index}"]`);
        if (indexBtn) {
            indexBtn.classList.add('selected');
            document.getElementById('recLensIndex').value = notes.rec_lens_index;
        }
    }
    
    // Load Features
    if (notes.rec_lens_features) {
        let features = notes.rec_lens_features;
        if (typeof features === 'string') {
            try { features = JSON.parse(features); } catch(e) { features = []; }
        }
        if (Array.isArray(features)) {
            features.forEach(f => {
                const cb = document.querySelector(`#recLensFeaturesList input[value="${f}"]`);
                if (cb) cb.checked = true;
            });
        }
    }
    
    updateRecFeatures();
}

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
    else if (statusClass==='waiting_payment') { statusText='Sent to Sales'; badgeColor='info'; }
    else if (statusClass==='any-doctor') { statusText='Any Dr.'; badgeColor='info'; }
    
    const appointmentStatus = appt.appointment_status || appt.status || '';
    
    // ✅ USE items_display (server-parsed) kung available
    let frameDisplay = appt.items_display || 'Check-up';
    let itemsList = appt.items_parsed || [];
    
    // Fallback: kung walang items_display at walang items_parsed
    if (!frameDisplay || frameDisplay === '') {
        frameDisplay = appt.frame_name || appt.product_name || 'Check-up';
    }
    
    const sourceBadge = appt.order_source === 'online' 
        ? '<span class="badge bg-info ms-1" style="font-size:.58rem;">Online</span>' 
        : '';
    
    // ✅ Gamitin 'yung items_display (server-parsed) — hindi na kailangan JSON
    const itemsDisplay = escapeString(appt.items_display || '');
    
    return `<div class="queue-item ${statusClass}"
                 onclick="selectPatient(${appt.id},${appt.patient_id||'null'},${appt.user_id||'null'},
                     '${escapeString(appt.patient_name)}',${appt.age||0},'${escapeString(appt.gender)}',
                     '${escapeString(appt.patient_type||'Regular')}','${escapeString(appt.product_name)}',
                     '${escapeString(appt.doctor_name)}','${escapeString(appt.appointment_time)}',
                     ${appt.is_new_patient?1:0},${appt.doctor_id?1:0},'${appointmentStatus}',
                     '${escapeString(appt.frame_name||'')}','${escapeString(appt.order_source||'walk_in')}',
                     '${itemsDisplay}')">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="badge bg-light text-dark" style="font-size:.65rem;"><i class="bi bi-clock me-1"></i>${time}</span>
                    <span class="badge bg-${badgeColor}" style="font-size:.64rem;">${statusText}</span>
                </div>
                <div class="qn">${appt.patient_name||'Unknown'}${appt.is_new_patient?'<span class="badge bg-success ms-1" style="font-size:.58rem;">New</span>':''}${sourceBadge}</div>
                <div class="qs">${appt.age||'?'} yrs · ${appt.gender||'?'} · ${frameDisplay}</div>
            </div>`;
}
function selectPatient(appointmentId, patientId, userId, name, age, gender, type, service, doctor, time, isNew, hasDoctor, status, frame, source, itemsJson) {
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
    $('#doctorName').text(doctor?'Dr. '+doctor:'—');
    $('#appointmentTime').text(time?time.substr(0,5):'—');
    
    // ✅ itemsJson ay plain string na ("Service A • Service B")
    // Hindi na kailangan i-JSON.parse
    
    if (itemsJson && itemsJson.trim() !== '' && itemsJson !== 'null') {
        // ✅ Display 'yung buong string
        $('#serviceName').text(itemsJson);
        $('#frameName').text(itemsJson);
        
        // ✅ Store as array para sa sendToSales routing
        const serviceNames = itemsJson.split(' • ').map(s => s.trim()).filter(Boolean);
        window.currentAppointmentItems = serviceNames.map(name => ({
            name: name,
            type: 'service',
            price: 0,
            quantity: 1
        }));
        window.currentAppointmentHasProduct = false;
        window.currentAppointmentServiceCount = serviceNames.length;
        
        console.log('[selectPatient] Items:', itemsJson, '| Count:', serviceNames.length);
    } else {
        // Fallback: gamitin 'yung service parameter
        $('#serviceName').text(service || '—');
        $('#frameName').text(frame && frame !== 'null' && frame !== '' ? frame : '—');
        
        window.currentAppointmentItems = [];
        window.currentAppointmentHasProduct = false;
        window.currentAppointmentServiceCount = 0;
    }
    
    // ✅ FLAGS: Kunin mula sa appt data (para sa sendToSales routing)
    // NOTE: Hindi na kailangan i-parse, server na ang nag-parse
    // Pero kailangan pa rin i-store para sa sendToSales()
    // Use the currentAppointmentId to fetch from a cached map
    if (window.appointmentCache && window.appointmentCache[appointmentId]) {
        const cachedAppt = window.appointmentCache[appointmentId];
        window.currentAppointmentItems = cachedAppt.items_parsed || [];
        window.currentAppointmentHasProduct = (cachedAppt.items_parsed || []).some(i => i.type === 'product');
        window.currentAppointmentIsServiceOnly = !window.currentAppointmentHasProduct && 
            (cachedAppt.items_parsed || []).filter(i => i.type === 'service').length > 0;
    } else {
        // Fallback: walang cache, i-reset
        window.currentAppointmentItems = [];
        window.currentAppointmentHasProduct = false;
        window.currentAppointmentIsServiceOnly = false;
    }
    


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
        
        // ✅ Subtle success banner
        $('.status-alert').remove();
        $('#patientCard').prepend(`
            <div class="status-alert m-2" style="
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                background: #f0fdf4;
                border: 1px solid #bbf7d0;
                border-radius: 10px;
                font-size: 12.5px;
                color: #166534;
            ">
                <i class="bi bi-check-circle-fill" style="color: #16a34a; font-size: 18px; flex-shrink: 0;"></i>
                <div style="flex: 1; line-height: 1.5;">
                    <strong style="color: #16a34a;">Completed</strong> — 
                    This consultation has been fully paid and completed. View only.
                </div>
            </div>
        `);
        return;
    }
    
    if (isWaitingPayment) {
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes, .btn-success, .btn-teal, .btn-outline-teal').prop('disabled', true);
        $('#startConsultationBtnContainer').hide();
        $('#anyDoctorAlert').hide();
        $('#newPatientAlert').hide();
        
        // ✅ Subtle info banner — hindi na alert
        $('.status-alert').remove();
        $('#patientCard').prepend(`
            <div class="status-alert m-2" style="
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                background: #f0f9ff;
                border: 1px solid #bae6fd;
                border-radius: 10px;
                font-size: 12.5px;
                color: #0c4a6e;
            ">
                <i class="bi bi-arrow-right-circle-fill" style="color: #0284c7; font-size: 18px; flex-shrink: 0;"></i>
                <div style="flex: 1; line-height: 1.5;">
                    <strong style="color: #0284c7;">Sent to Sales</strong> — 
                    Prescription & recommendation naipasa na. Waiting for Sales/Optical to finalize order.
                </div>
            </div>
        `);
        return;
    }
    
    if (isUpcoming) {
        $('.exam-pane input, .exam-pane textarea, .rx-box input, .rx-box select, #rxNotes, .btn-success, .btn-teal, .btn-outline-teal').prop('disabled', true);
        $('#startConsultationBtnContainer').hide();
        $('#anyDoctorAlert').hide();
        $('#newPatientAlert').hide();
        
        // ✅ Subtle upcoming banner
        $('.status-alert').remove();
        $('#patientCard').prepend(`
            <div class="status-alert m-2" style="
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                background: #eff6ff;
                border: 1px solid #bfdbfe;
                border-radius: 10px;
                font-size: 12.5px;
                color: #1e40af;
            ">
                <i class="bi bi-calendar-event-fill" style="color: #2563eb; font-size: 18px; flex-shrink: 0;"></i>
                <div style="flex: 1; line-height: 1.5;">
                    <strong style="color: #2563eb;">Upcoming Appointment</strong> — 
                    Scheduled for ${time}. This is view only.
                </div>
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

    // ✅ I-reset ang product flag bago mag-load ng bagong patient
    currentAppointmentHasProduct = false;
    
    resetTimer();
    clearForms(); 
    loadPatientData(patientId, appointmentId);
    
    // ✅ Stop auto-save kung hindi active
    // NOTE: Gamitin ang existing `isInProgress` at `consultationStarted` 
    // na naka-declare na sa itaas ng function — HUWAG i-declare ulit
    
    if (isInProgress || consultationStarted) {
        // Auto-save mag-uumpisa pagkatapos ng load
        setTimeout(() => {
            startAutoSave();
        }, 1500);
    } else {
        stopAutoSave();
        const indicator = document.getElementById('autosaveIndicator');
        if (indicator) indicator.style.display = 'none';
    }
}
// Go to Sales & Billing for payment
function goToSalesBilling(appointmentId) {
    window.location.href = `main.php?view=sales&appointment_id=${appointmentId}`;
}

function applyDynamicForm(requiredSections) {
    const ALWAYS_VISIBLE = ['vitals', 'chief_complaint', 'assessment'];
    
    document.querySelectorAll('[data-form-section]').forEach(section => {
        const key = section.dataset.formSection;
        if (ALWAYS_VISIBLE.includes(key)) {
            section.style.display = '';
            return;
        }
        section.style.display = 'none';
    });
    
    (requiredSections || []).forEach(sectionKey => {
        const section = document.querySelector(`[data-form-section="${sectionKey}"]`);
        if (section) {
            section.style.display = '';
        }
    });
    
    const hasLensRec   = (requiredSections || []).includes('lens_recommendation');
    const hasCLAssess  = (requiredSections || []).includes('contact_lens_assessment');
    
    const isContactLensFlow = hasCLAssess && !hasLensRec;
    const isEyeglassFlow = hasLensRec;
    
    // ✅ I-set ang flags — ITO ANG PINAKA-RELIABLE
    window.currentAppointmentIsServiceOnly = !isEyeglassFlow && !isContactLensFlow;
    window.currentAppointmentIsEyeglass = isEyeglassFlow;
    window.currentAppointmentIsContactLens = isContactLensFlow;
    currentAppointmentHasProduct = isEyeglassFlow || isContactLensFlow;
    
    // ✅ Button text
    const sendToSalesBtn = document.getElementById('sendToSalesBtn');
    if (sendToSalesBtn) {
        if (isEyeglassFlow) {
            sendToSalesBtn.innerHTML = '<i class="bi bi-arrow-right-circle-fill"></i> Forward to Optical';
            sendToSalesBtn.style.background = 'linear-gradient(135deg, #0284c7, #0369a1)';
        } else if (isContactLensFlow) {
            sendToSalesBtn.innerHTML = '<i class="bi bi-cart-check-fill"></i> Complete & Forward to Sales';
            sendToSalesBtn.style.background = 'linear-gradient(135deg, #0284c7, #0369a1)';
        } else {
            sendToSalesBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Complete Consultation';
            sendToSalesBtn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        }
    }
    
    console.log('[DynamicForm] Applied sections:', requiredSections);
    console.log('[DynamicForm] Flags:', {
        isServiceOnly: window.currentAppointmentIsServiceOnly,
        isEyeglass: window.currentAppointmentIsEyeglass,
        isContactLens: window.currentAppointmentIsContactLens
    });
}
function loadPatientData(patientId, appointmentId) {
    if (!patientId) return;
    
    $.ajax({
        url: 'api/doctor_dashboard.php?action=get_patient_data',
        method: 'GET',
        data: { patient_id: patientId, appointment_id: appointmentId },
        success: function(r) {
            if (!r.success) return;
            
            // ... existing code ...
            
            renderHistorySidebar(r.history||[]);
            if (currentPatientId) loadDecisionSupport(currentPatientId, r.latest_notes?.diagnosis||'');
            
            // ✅ PHASE 6: Apply dynamic form based on booked services
            if (r.required_sections) {
                applyDynamicForm(r.required_sections);
            }
            
            // ✅ I-set ang category flags
            window.currentAppointmentIsContactLens = r.is_contact_lens || false;
            window.currentAppointmentIsEyeglass = r.is_eyeglass || false;
            window.currentAppointmentIsService = r.is_service || false;
            
            // ✅ BAGO: I-load ang existing values mula sa latest_notes
            if (r.latest_notes) {
                const n = r.latest_notes;
                
                // Visual Acuity extras
                if (n.va_both)       $('#vaBoth').val(n.va_both);
                if (n.va_near_right) $('#vaNearRight').val(n.va_near_right);
                if (n.va_near_left)  $('#vaNearLeft').val(n.va_near_left);
                if (n.va_near_both)  $('#vaNearBoth').val(n.va_near_both);
                
                // ✅ Contact Lens Assessment
                if (n.cl_base_curve)     $('#clBaseCurve').val(n.cl_base_curve);
                if (n.cl_diameter)       $('#clDiameter').val(n.cl_diameter);
                if (n.cl_power)          $('#clPower').val(n.cl_power);
                if (n.cl_cylinder)       $('#clCylinder').val(n.cl_cylinder);
                if (n.cl_axis)           $('#clAxis').val(n.cl_axis);
                if (n.cl_lens_type)      $('#clLensType').val(n.cl_lens_type);
                if (n.cl_fit_assessment) $('#clFitAssessment').val(n.cl_fit_assessment);
                if (n.cl_trial_lens)     $('#clTrialLens').val(n.cl_trial_lens);
                
                // Glaucoma
                if (n.iop_right)     $('#iopRight').val(n.iop_right);
                if (n.iop_left)      $('#iopLeft').val(n.iop_left);
                if (n.cd_ratio_right) $('#cdRatioRight').val(n.cd_ratio_right);
                if (n.cd_ratio_left)  $('#cdRatioLeft').val(n.cd_ratio_left);
                if (n.visual_field)  $('#visualField').val(n.visual_field);
                if (n.glaucoma_risk) $('#glaucomaRisk').val(n.glaucoma_risk);
                
                // Retina
                if (n.fundus_right)    $('#fundusRight').val(n.fundus_right);
                if (n.fundus_left)     $('#fundusLeft').val(n.fundus_left);
                if (n.retina_findings) $('#retinaFindings').val(n.retina_findings);
                
                // Cataract
                if (n.cataract_right)          $('#cataractRight').val(n.cataract_right);
                if (n.cataract_left)           $('#cataractLeft').val(n.cataract_left);
                if (n.cataract_recommendation) $('#cataractRecommendation').val(n.cataract_recommendation);
                
                // Treatment Notes
                if (n.treatment_given)        $('#treatmentGiven').val(n.treatment_given);
                if (n.followup_instructions)  $('#followupInstructions').val(n.followup_instructions);
            }
            
            console.log('[loadPatientData] Category flags:', {
                is_contact_lens: r.is_contact_lens,
                is_eyeglass: r.is_eyeglass,
                is_service: r.is_service
            });
        }
    });
}

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
function clearForms()  { 
    $('#examForm')[0].reset(); 
    $('#rxForm')[0].reset(); 
    
    // ✅ BAGO: Clear CL fields explicitly
    $('#clBaseCurve, #clDiameter, #clPower, #clCylinder, #clAxis').val('');
    $('#clLensType').val('');
    $('#clFitAssessment, #clTrialLens').val('');
    
    // ✅ BAGO: Clear iba pang dynamic sections
    $('#vaBoth, #vaNearRight, #vaNearLeft, #vaNearBoth').val('');
    $('#iopRight, #iopLeft, #cdRatioRight, #cdRatioLeft').val('');
    $('#visualField, #glaucomaRisk').val('');
    $('#fundusRight, #fundusLeft, #retinaFindings').val('');
    $('#cataractRight, #cataractLeft, #cataractRecommendation').val('');
    $('#treatmentGiven, #followupInstructions').val('');
}
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
        notes: $('#notes').val(),
        
        // ✅ Lens Recommendation (redesigned)
        rec_lens_design: $('#recLensDesign').val() || null,
        rec_lens_index: $('#recLensIndex').val() || null,
        rec_lens_features: (() => {
            const checked = [];
            document.querySelectorAll('#recLensFeaturesList input[type="checkbox"]:checked').forEach(cb => {
                checked.push(cb.value);
            });
            return checked;
        })(),
        rec_lens_notes: $('#recLensNotes').val() || null,
        
        // ✅ BAGO: Visual Acuity extras
        va_both: $('#vaBoth').val() || null,
        va_near_right: $('#vaNearRight').val() || null,
        va_near_left: $('#vaNearLeft').val() || null,
        va_near_both: $('#vaNearBoth').val() || null,
        
        // ✅ BAGO: CONTACT LENS ASSESSMENT (ito ang kulang!)
        cl_base_curve: $('#clBaseCurve').val() || null,
        cl_diameter: $('#clDiameter').val() || null,
        cl_power: $('#clPower').val() || null,
        cl_cylinder: $('#clCylinder').val() || null,
        cl_axis: $('#clAxis').val() || null,
        cl_lens_type: $('#clLensType').val() || null,
        cl_fit_assessment: $('#clFitAssessment').val() || null,
        cl_trial_lens: $('#clTrialLens').val() || null,
        
        // ✅ BAGO: Glaucoma Screening
        iop_right: $('#iopRight').val() || null,
        iop_left: $('#iopLeft').val() || null,
        cd_ratio_right: $('#cdRatioRight').val() || null,
        cd_ratio_left: $('#cdRatioLeft').val() || null,
        visual_field: $('#visualField').val() || null,
        glaucoma_risk: $('#glaucomaRisk').val() || null,
        
        // ✅ BAGO: Retina Screening
        fundus_right: $('#fundusRight').val() || null,
        fundus_left: $('#fundusLeft').val() || null,
        retina_findings: $('#retinaFindings').val() || null,
        
        // ✅ BAGO: Cataract Evaluation
        cataract_right: $('#cataractRight').val() || null,
        cataract_left: $('#cataractLeft').val() || null,
        cataract_recommendation: $('#cataractRecommendation').val() || null,
        
        // ✅ BAGO: Treatment Notes
        treatment_given: $('#treatmentGiven').val() || null,
        followup_instructions: $('#followupInstructions').val() || null
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

/* ═══════════════════════════════════════════════════════════════
   ★ PAUSE & SAVE — Save lang, hindi pa i-send sa Sales
   ═══════════════════════════════════════════════════════════════ */
function pauseAndSave() {
    if (!currentAppointmentId) {
        Swal.fire('Warning', 'No patient selected', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Pause & Save?',
        html: `
            <p style="font-size: 13.5px; color: #1e293b; margin-bottom: 8px;">
                Save the current progress and continue later.
            </p>
            <p style="font-size: 12px; color: #64748b;">
                The consultation will remain <strong>in progress</strong>. 
                You can resume anytime.
            </p>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        confirmButtonText: '<i class="bi bi-save me-1"></i> Yes, Save',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            saveClinicalNotes(function() {
                updatePatientDataAfterSave();
                lastSavedData = getFormData();
                lastSaveTime = Date.now();
                showAutoSaveIndicator('saved', 'Saved just now');
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: 'Progress saved. You can continue later.',
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        }
    });
}
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
                        
                        // ✅ Start auto-save
                        startAutoSave();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Consultation Started',
                            text: 'Timer & auto-save are now active. You can record examination details.',
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


/* ═══════════════════════════════════════════════════════════════
   ★ AUTO-SAVE — Save every 30 seconds habang nag-e-exam
   ═══════════════════════════════════════════════════════════════ */
let autoSaveInterval = null;
let lastSavedData = null;
let lastSaveTime = null;
let autoSaveEnabled = false;

// Start auto-save
function startAutoSave() {
    if (autoSaveInterval) clearInterval(autoSaveInterval);
    
    autoSaveEnabled = true;
    lastSavedData = getFormData(); // Initialize snapshot
    lastSaveTime = Date.now();
    showAutoSaveIndicator('saved', 'Auto-save active');
    
    // Check every 30 seconds
    autoSaveInterval = setInterval(() => {
        if (!autoSaveEnabled) return;
        if (!currentAppointmentId) return;
        
        // Check kung may changes
        const currentData = getFormData();
        if (JSON.stringify(currentData) === JSON.stringify(lastSavedData)) {
            return; // Walang changes, skip
        }
        
        // May changes, i-save
        autoSaveDraft(currentData);
    }, 30000); // 30 seconds
}

// Stop auto-save
function stopAutoSave() {
    autoSaveEnabled = false;
    if (autoSaveInterval) {
        clearInterval(autoSaveInterval);
        autoSaveInterval = null;
    }
}

// Get current form data (for change detection)
function getFormData() {
    const features = [];
    document.querySelectorAll('#recLensFeaturesList input[type="checkbox"]:checked').forEach(cb => {
        features.push(cb.value);
    });
    
    return {
        chief_complaint: $('#chiefComplaint').val() || '',
        va_left: $('#vaLeft').val() || '',
        va_right: $('#vaRight').val() || '',
        findings: $('#findings').val() || '',
        diagnosis: $('#diagnosis').val() || '',
        treatment_plan: $('#treatmentPlan').val() || '',
        notes: $('#notes').val() || '',
        rec_lens_design: $('#recLensDesign').val() || '',
        rec_lens_index: $('#recLensIndex').val() || '',
        rec_lens_features: features,
        rec_lens_notes: $('#recLensNotes').val() || '',
        
        // ✅ BAGO: Visual Acuity extras
        va_both: $('#vaBoth').val() || '',
        va_near_right: $('#vaNearRight').val() || '',
        va_near_left: $('#vaNearLeft').val() || '',
        va_near_both: $('#vaNearBoth').val() || '',
        
        // ✅ BAGO: Contact Lens Assessment
        cl_base_curve: $('#clBaseCurve').val() || '',
        cl_diameter: $('#clDiameter').val() || '',
        cl_power: $('#clPower').val() || '',
        cl_cylinder: $('#clCylinder').val() || '',
        cl_axis: $('#clAxis').val() || '',
        cl_lens_type: $('#clLensType').val() || '',
        cl_fit_assessment: $('#clFitAssessment').val() || '',
        cl_trial_lens: $('#clTrialLens').val() || '',
        
        // ✅ BAGO: Glaucoma Screening
        iop_right: $('#iopRight').val() || '',
        iop_left: $('#iopLeft').val() || '',
        cd_ratio_right: $('#cdRatioRight').val() || '',
        cd_ratio_left: $('#cdRatioLeft').val() || '',
        visual_field: $('#visualField').val() || '',
        glaucoma_risk: $('#glaucomaRisk').val() || '',
        
        // ✅ BAGO: Retina Screening
        fundus_right: $('#fundusRight').val() || '',
        fundus_left: $('#fundusLeft').val() || '',
        retina_findings: $('#retinaFindings').val() || '',
        
        // ✅ BAGO: Cataract Evaluation
        cataract_right: $('#cataractRight').val() || '',
        cataract_left: $('#cataractLeft').val() || '',
        cataract_recommendation: $('#cataractRecommendation').val() || '',
        
        // ✅ BAGO: Treatment Notes
        treatment_given: $('#treatmentGiven').val() || '',
        followup_instructions: $('#followupInstructions').val() || ''
    };
}

// Auto-save draft
function autoSaveDraft(data) {
    showAutoSaveIndicator('saving', 'Saving...');
    
    $.ajax({
        url: 'api/doctor_dashboard.php?action=save_notes',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            appointment_id: currentAppointmentId,
            patient_id: currentPatientId,
            ...data
        }),
        success: function(r) {
            if (r.success) {
                lastSavedData = data;
                lastSaveTime = Date.now();
                showAutoSaveIndicator('saved', 'Saved just now');
                console.log('✅ Auto-saved at', new Date().toLocaleTimeString());
            } else {
                showAutoSaveIndicator('unsaved', 'Save failed');
            }
        },
        error: function() {
            showAutoSaveIndicator('unsaved', 'Connection error');
        }
    });
}

// Show auto-save indicator
function showAutoSaveIndicator(state, text) {
    const el = document.getElementById('autosaveIndicator');
    const textEl = document.getElementById('autosaveText');
    if (!el || !textEl) return;
    
    el.style.display = 'flex';
    el.className = 'autosave-indicator ' + state;
    
    const icons = {
        'saving': 'bi-arrow-clockwise',
        'saved': 'bi-cloud-check',
        'unsaved': 'bi-exclamation-circle'
    };
    
    const icon = el.querySelector('i');
    if (icon) icon.className = 'bi ' + (icons[state] || 'bi-cloud-check');
    
    textEl.textContent = text;
}

// Update "time ago" every 10 seconds
setInterval(() => {
    if (!lastSaveTime || !autoSaveEnabled) return;
    const el = document.getElementById('autosaveIndicator');
    if (!el || !el.classList.contains('saved')) return;
    
    const seconds = Math.floor((Date.now() - lastSaveTime) / 1000);
    const textEl = document.getElementById('autosaveText');
    if (!textEl) return;
    
    if (seconds < 5) textEl.textContent = 'Saved just now';
    else if (seconds < 60) textEl.textContent = `Saved ${seconds}s ago`;
    else textEl.textContent = `Saved ${Math.floor(seconds / 60)}m ago`;
}, 10000);

// ✅ Track changes para sa unsaved indicator
$(document).on('input change', '.exam-pane input, .exam-pane textarea, .exam-pane select, #recLensNotes', function() {
    if (!autoSaveEnabled) return;
    const currentData = getFormData();
    if (JSON.stringify(currentData) !== JSON.stringify(lastSavedData)) {
        showAutoSaveIndicator('unsaved', 'Unsaved changes');
    }
});
// ═══════════════════════════════════════════════════
// UI-ONLY HELPERS — cosmetic lang, walang effect sa logic
// ═══════════════════════════════════════════════════

/* 1) Queue tab filter */
function filterQueueTab(btn, filter) {
    document.querySelectorAll('#queueTabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    let visible = 0, total = 0;
    document.querySelectorAll('#queueContainer .queue-item').forEach(item => {
        total++;
        if (filter === 'all') {
            item.classList.remove('filtered-out');
            visible++;
        } else {
            const matches = item.classList.contains(filter) || item.classList.contains(filter.replace('_','-'));
            item.classList.toggle('filtered-out', !matches);
            if (matches) visible++;
        }
    });
    const note = document.getElementById('queueCountNote');
    if (note) note.textContent = `Showing ${visible} of ${total} patients`;
}

/* 2) Sync header values after selectPatient */
const _origSelectPatientUI = window.selectPatient;
window.selectPatient = function(appointmentId, patientId, userId, name, age, gender, type, service, doctor, time, isNew, hasDoctor, status, frame, source) {
    if (typeof _origSelectPatientUI === 'function') _origSelectPatientUI.apply(this, arguments);

    const initials = (() => {
        if (!name || name === 'null' || name === 'Unknown') return '??';
        const parts = name.trim().split(/\s+/);
        return parts.length === 1 ? parts[0].substring(0,2).toUpperCase()
                                  : (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
    })();
    const elInit = document.getElementById('patientInitials');
    if (elInit) elInit.textContent = initials;

    const code = patientId ? `PT-${String(patientId).padStart(6,'0')}` : '—';
    const elCode = document.getElementById('patientCode');
    if (elCode) elCode.textContent = code;
    const elRxCode = document.getElementById('rxPatientCode');
    if (elRxCode) elRxCode.textContent = code;

    const badge = document.getElementById('patientStatusBadge');
    if (badge) {
        const s = (status || 'waiting').toLowerCase();
        let label = 'Waiting';
        if (s === 'in_progress') label = 'In Examination';
        else if (s === 'completed') label = 'Completed';
        else if (s === 'waiting_payment') label = 'Sent to Sales';
        else if (s === 'arrived') label = 'Waiting';
        else if (s === 'confirmed' || s === 'paid') label = 'Upcoming';
        badge.textContent = label;
        badge.className = 'status-badge ' + s.replace('_','-');
        const csStatus = document.getElementById('csStatus');
        if (csStatus) {
            const color = (s === 'in_progress') ? 'var(--teal)'
                        : (s === 'completed') ? 'var(--green)'
                        : (s === 'waiting_payment') ? '#0284c7'
                        : 'var(--amber)';
            const textColor = (s === 'in_progress') ? 'var(--teal-dark)'
                            : (s === 'completed') ? 'var(--green)'
                            : (s === 'waiting_payment') ? '#0284c7'
                            : 'var(--amber)';
            csStatus.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:' + color + ';display:inline-block;"></span> ' + label;
            csStatus.style.color = textColor;
        }
    }

    const dName = document.getElementById('doctorName')?.textContent || '—';
    const elRxDoc = document.getElementById('rxPrescribingDoctor');
    if (elRxDoc) elRxDoc.textContent = dName;

    const setText = (tid, val) => { const el = document.getElementById(tid); if (el) el.textContent = val || '—'; };
    setText('csPatientName', document.getElementById('patientName')?.textContent);
    setText('csAgeGender', document.getElementById('patientAge')?.textContent);
    setText('csVisit', document.getElementById('serviceName')?.textContent);
};

/* 3) Live summary update habang nagta-type */
document.addEventListener('input', function(e) {
    const id = e.target && e.target.id;
    if (!id) return;
    const setText = (tid, val) => { const el = document.getElementById(tid); if (el) el.textContent = val || '—'; };
    if (id === 'vaRight') setText('csVaOd', e.target.value);
    if (id === 'vaLeft')  setText('csVaOs', e.target.value);
    if (id === 'diagnosis') setText('csAssessment', e.target.value);
});

/* 4) Sync refraction fields papunta sa Prescription tab */
function syncRxToPrescriptionTab() {
    const map = {
        rxSphR:'rxSphR_p', rxCylR:'rxCylR_p', rxAxisR:'rxAxisR_p', rxAddR:'rxAddR_p',
        rxSphL:'rxSphL_p', rxCylL:'rxCylL_p', rxAxisL:'rxAxisL_p', rxAddL:'rxAddL_p'
    };
    Object.keys(map).forEach(src => {
        const s = document.getElementById(src);
        const d = document.getElementById(map[src]);
        if (s && d && s.value && !d.value) d.value = s.value;
    });
}
document.addEventListener('input', function(e) {
    if (e.target && e.target.id && e.target.id.startsWith('rx') && !e.target.id.endsWith('_p')) {
        syncRxToPrescriptionTab();
    }
});

/* 5) Sync Prescription tab → Refraction (pabalik) */
document.addEventListener('input', function(e) {
    if (!e.target || !e.target.id || !e.target.id.endsWith('_p')) return;
    const reverse = {
        rxSphR_p:'rxSphR', rxCylR_p:'rxCylR', rxAxisR_p:'rxAxisR', rxAddR_p:'rxAddR',
        rxSphL_p:'rxSphL', rxCylL_p:'rxCylL', rxAxisL_p:'rxAxisL', rxAddL_p:'rxAddL'
    };
    const src = reverse[e.target.id];
    if (src) {
        const el = document.getElementById(src);
        if (el) el.value = e.target.value;
    }
});

/* 6) Update queue count after render */
const _origRenderQueueGroupedUI = window.renderQueueGrouped;
window.renderQueueGrouped = function(data) {
    if (typeof _origRenderQueueGroupedUI === 'function') _origRenderQueueGroupedUI.apply(this, arguments);
    setTimeout(function() {
        const items = document.querySelectorAll('#queueContainer .queue-item:not(.filtered-out)');
        const all = document.querySelectorAll('#queueContainer .queue-item');
        const note = document.getElementById('queueCountNote');
        if (note) note.textContent = `Showing ${items.length} of ${all.length} patients`;
    }, 60);
};

function sendToSales() {
    if (!currentAppointmentId) {
        Swal.fire('Warning', 'No patient selected', 'warning');
        return;
    }
    
    // ✅ PRIMARY: Gamitin ang flags na sine-set ng applyDynamicForm()
    // Ito ang pinaka-reliable — naka-base sa required_sections mula sa API
    const isServiceOnly = window.currentAppointmentIsServiceOnly === true;
    const isContactLens = window.currentAppointmentIsContactLens === true;
    const isEyeglass    = window.currentAppointmentIsEyeglass === true;
    
    console.log('[sendToSales] Flags from applyDynamicForm:', {
        isServiceOnly,
        isContactLens,
        isEyeglass
    });
    
    // ✅ FALLBACK: Kung hindi pa naka-set ang flags (e.g., page refresh),
    // i-derive mula sa required_sections o sa visible sections
    let derivedServiceOnly = isServiceOnly;
    let derivedContactLens = isContactLens;
    let derivedEyeglass = isEyeglass;
    
    if (!isServiceOnly && !isContactLens && !isEyeglass) {
        // Check kung anong sections ang visible sa DOM
        const lensRecCard = document.getElementById('lensRecommendationCard');
        const clCard = document.querySelector('[data-form-section="contact_lens_assessment"]');
        
        const lensRecVisible = lensRecCard && lensRecCard.style.display !== 'none';
        const clVisible = clCard && clCard.style.display !== 'none';
        
        console.log('[sendToSales] DOM check:', { lensRecVisible, clVisible });
        
        if (clVisible && !lensRecVisible) {
            derivedContactLens = true;
        } else if (lensRecVisible) {
            derivedEyeglass = true;
        } else {
            // ✅ WALANG product section = SERVICE ONLY
            derivedServiceOnly = true;
        }
        
        console.log('[sendToSales] Derived flags:', {
            derivedServiceOnly,
            derivedContactLens,
            derivedEyeglass
        });
    }
    
    // ✅ ROUTING
    if (derivedServiceOnly) {
        console.log('[sendToSales] → SERVICES ONLY → completeConsultationOnly()');
        completeConsultationOnly();
    } else if (derivedContactLens) {
        console.log('[sendToSales] → CONTACT LENS → forwardContactLensToSales()');
        forwardContactLensToSales();
    } else if (derivedEyeglass) {
        console.log('[sendToSales] → EYEGLASS → forwardToOptical()');
        forwardToOptical();
    } else {
        // ✅ Last resort: check kung may service names sa display
        const serviceText = document.getElementById('serviceName')?.textContent || '';
        if (serviceText && serviceText !== '—' && serviceText.trim() !== '') {
            console.log('[sendToSales] → fallback (service text found) → completeConsultationOnly()');
            completeConsultationOnly();
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'No Items Found',
                text: 'This appointment has no items. Please check the appointment data.',
                confirmButtonText: 'OK'
            });
        }
    }
}

function completeConsultationOnly() {
    // ✅ Validate: kailangan may diagnosis
    const hasDiagnosis = $('#diagnosis').val();
    if (!hasDiagnosis) {
        Swal.fire({
            icon: 'warning',
            title: 'Diagnosis Required',
            text: 'Please enter a diagnosis before completing.',
            confirmButtonText: 'OK'
        }).then(() => {
            $('#diagnosis').focus();
            $('#diagnosis').scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        return;
    }
    
    // ✅ Confirmation dialog
    Swal.fire({
        title: 'Complete Consultation?',
        html: `
            <div style="text-align: left; padding: 12px; background: #f0fdf4; border-radius: 10px; margin: 12px 0;">
                <div style="font-size: 13px; margin-bottom: 10px; color: #166534; font-weight: 700;">
                    <i class="bi bi-info-circle me-1"></i>
                    The following will be saved:
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Clinical notes & findings</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Diagnosis & treatment plan</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Service marked as completed</span>
                </div>
            </div>
            <div style="font-size: 12px; color: #0369a1; padding: 10px; background: #f0f9ff; border-radius: 8px;">
                <i class="bi bi-info-circle-fill me-1"></i>
                <strong>No eyewear purchase</strong> for this appointment. Consultation will be marked as <strong>COMPLETED</strong>.
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Yes, Complete',
        cancelButtonText: 'Cancel',
        width: '480px'
    }).then(result => {
        if (result.isConfirmed) {
            stopAutoSave();
            
            Swal.fire({ 
                title: 'Completing...', 
                html: 'Please wait.',
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            // ✅ Save notes muna
            saveClinicalNotes(function() {
                // ✅ Tapos i-complete ang consultation
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
                            Swal.fire({
                                icon: 'success',
                                title: 'Consultation Complete! ✅',
                                html: `
                                    <div style="text-align: center;">
                                        <p style="font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                                            ✅ Consultation marked as <strong>COMPLETED</strong>.
                                        </p>
                                        <p style="font-size: 12.5px; color: #64748b;">
                                            Patient may now leave. No payment required.
                                        </p>
                                    </div>
                                `,
                                timer: 3000,
                                showConfirmButton: false
                            });
                            
                            // ✅ Lock UI
                            $('.exam-pane input, .exam-pane textarea, .exam-pane select, .rx-box input, .rx-box select, #rxNotes, #recLensNotes').prop('disabled', true);
                            $('#startConsultationBtnContainer').hide();
                            $('#sendToSalesBtn').hide();
                            $('.summary-save-link').hide();
                            $('#autosaveIndicator').hide();
                            
                            // ✅ Update status badge
                            $('#patientStatusBadge').text('Completed').removeClass().addClass('status-badge completed');
                            
                            // ✅ Show info alert
                            $('.status-alert').remove();
                            $('#patientCard').prepend(
                                '<div class="alert alert-success status-alert m-2 d-flex align-items-center gap-2">' +
                                    '<i class="bi bi-check-circle-fill fs-5"></i>' +
                                    '<div>' +
                                        '<strong>Consultation Complete</strong> — Patient may leave.<br>' +
                                        '<small class="text-muted">No payment required (services only).</small>' +
                                    '</div>' +
                                '</div>'
                            );
                            
                            // ✅ Clear session storage
                            sessionStorage.removeItem(`consultation_${currentAppointmentId}_started`);
                            
                            // ✅ Reload queue
                            loadQueue();
                        } else {
                            startAutoSave();
                            Swal.fire('Error', r.message || 'Failed to complete consultation', 'error');
                        }
                    },
                    error: function() {
                        Swal.close();
                        startAutoSave();
                        Swal.fire('Error', 'Connection error. Please try again.', 'error');
                    }
                });
            });
        }
    });
}

function forwardToOptical() {
    if (!currentAppointmentId) {
        Swal.fire('Warning', 'No patient selected', 'warning');
        return;
    }
    
    // ✅ Validate: kailangan may prescription
    const hasRx = $('#rxSphR').val() || $('#rxSphL').val() || $('#rxCylR').val() || $('#rxCylL').val();
    if (!hasRx) {
        Swal.fire({
            icon: 'warning',
            title: 'Prescription Required',
            text: 'Please save a prescription before sending to Sales.',
            confirmButtonText: 'Go to Prescription',
            showCancelButton: true,
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (result.isConfirmed) {
                $('#prescriptionTabLink').tab('show');
            }
        });
        return;
    }
    
    // ✅ Validate: kailangan may diagnosis
    const hasDiagnosis = $('#diagnosis').val();
    if (!hasDiagnosis) {
        Swal.fire({
            icon: 'warning',
            title: 'Diagnosis Required',
            text: 'Please enter a diagnosis before sending to Sales.',
            confirmButtonText: 'OK'
        }).then(() => {
            $('#diagnosis').focus();
            $('#diagnosis').scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        return;
    }
    
    // ✅ Detailed confirmation dialog
    Swal.fire({
        title: 'Complete Consultation?',
        html: `
            <div style="text-align: left; padding: 12px; background: #f0f9ff; border-radius: 10px; margin: 12px 0;">
                <div style="font-size: 13px; margin-bottom: 10px; color: #0369a1; font-weight: 700;">
                    <i class="bi bi-info-circle me-1"></i>
                    The following will be saved & sent:
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Clinical notes & findings</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Prescription (OD / OS)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Lens recommendation</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Patient will proceed to Sales counter</span>
                </div>
            </div>
            <div style="font-size: 12px; color: #dc2626; padding: 10px; background: #fef2f2; border-radius: 8px;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>This action cannot be undone.</strong>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0284c7',
        confirmButtonText: '<i class="bi bi-send me-1"></i> Yes, Complete Consultation',
        cancelButtonText: 'Cancel',
        width: '480px'
    }).then(result => {
        if (result.isConfirmed) {
            // ✅ Stop auto-save
            stopAutoSave();
            
            Swal.fire({ 
                title: 'Sending to Sales...', 
                html: 'Please wait while we save everything.',
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            // ✅ Save clinical notes + prescription, then Complete Consultation
            saveClinicalNotes(function() {
                savePrescription(function() {
                    $.ajax({
                        url: 'api/doctor_dashboard.php?action=send_to_sales',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            appointment_id: currentAppointmentId,
                            patient_id: currentPatientId
                        }),
                        success: function(r) {
                            Swal.close();
                            if (r.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Sent to Sales!',
                                    html: `
                                        <div style="text-align: center;">
                                            <p style="font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                                                ✅ Naipasa na sa <strong>Sales/Optical</strong>.
                                            </p>
                                            <p style="font-size: 12.5px; color: #64748b;">
                                                Patient will proceed to the counter for final order.
                                            </p>
                                        </div>
                                    `,
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                                
                                // ✅ Lock UI as view-only
                                $('.exam-pane input, .exam-pane textarea, .exam-pane select, .rx-box input, .rx-box select, #rxNotes, #recLensNotes').prop('disabled', true);
                                $('#startConsultationBtnContainer').hide();
                                $('#sendToSalesBtn').hide();
                                $('.summary-save-link').hide();
                                $('#autosaveIndicator').hide();
                                
                                // ✅ Update status badge
                                $('#patientStatusBadge').text('Sent to Sales').removeClass().addClass('status-badge sent-to-sales');
                                
                                // ✅ Show info alert
                                $('.status-alert').remove();
                                $('#patientCard').prepend(
                                    '<div class="alert alert-info status-alert m-2 d-flex align-items-center gap-2">' +
                                        '<i class="bi bi-arrow-right-circle-fill fs-5"></i>' +
                                        '<div>' +
                                            '<strong>Sent to Sales</strong> — Prescription at recommendation naipasa na.<br>' +
                                            '<small class="text-muted">Waiting for Sales/Optical to finalize order.</small>' +
                                        '</div>' +
                                    '</div>'
                                );
                                
                                // ✅ Clear session storage
                                sessionStorage.removeItem(`consultation_${currentAppointmentId}_started`);
                                
                                // ✅ Reload queue
                                loadQueue();
                            } else {
                                // ✅ Re-enable auto-save kung failed
                                startAutoSave();
                                Swal.fire('Error', r.message || 'Failed to Complete Consultation', 'error');
                            }
                        },
                        error: function() {
                            Swal.close();
                            // ✅ Re-enable auto-save kung failed
                            startAutoSave();
                            Swal.fire('Error', 'Connection error. Please try again.', 'error');
                        }
                    });
                });
            });
        }
    });
}

/* ═══════════════════════════════════════════════════════════════
   ★ NEW: QUICK RX VALIDATION
   ═══════════════════════════════════════════════════════════════ */
function forwardContactLensToSales() {
    if (!currentAppointmentId) {
        Swal.fire('Warning', 'No patient selected', 'warning');
        return;
    }
    
    // ✅ Validate: kailangan may CL assessment (BC at Power lang ang required)
    const clPower = $('#clPower').val();
    const clBaseCurve = $('#clBaseCurve').val();
    const clDiameter = $('#clDiameter').val();
    
    if (!clPower || !clBaseCurve) {
        Swal.fire({
            icon: 'warning',
            title: 'Contact Lens Assessment Required',
            html: `
                <p style="text-align: left; font-size: 13px; color: #1e293b;">
                    Please fill in the following before completing:
                </p>
                <ul style="text-align: left; font-size: 12.5px; color: #64748b; padding-left: 20px;">
                    <li>${!clBaseCurve ? '<strong>Base Curve (BC)</strong> — required' : '✅ Base Curve'}</li>
                    <li>${!clPower ? '<strong>Power (SPH)</strong> — required' : '✅ Power'}</li>
                    <li style="color: #94a3b8;">Diameter (DIA) — optional</li>
                </ul>
                <p style="text-align: left; font-size: 11.5px; color: #64748b; margin-top: 8px;">
                    <i class="bi bi-info-circle"></i> Ang BC at Power lang ang required para makapag-order. Ang DIA at Lens Type ay standard na.
                </p>
            `,
            confirmButtonText: 'OK'
        }).then(() => {
            if (!clBaseCurve) $('#clBaseCurve').focus();
            else if (!clPower) $('#clPower').focus();
        });
        return;
    }
    
    // ✅ Validate: kailangan may diagnosis
    const hasDiagnosis = $('#diagnosis').val();
    if (!hasDiagnosis) {
        Swal.fire({
            icon: 'warning',
            title: 'Diagnosis Required',
            text: 'Please enter a diagnosis before completing.',
            confirmButtonText: 'OK'
        }).then(() => {
            $('#diagnosis').focus();
            $('#diagnosis').scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        return;
    }
    
    // ✅ Confirmation dialog
    Swal.fire({
        title: 'Complete Consultation?',
        html: `
            <div style="text-align: left; padding: 12px; background: #f0f9ff; border-radius: 10px; margin: 12px 0;">
                <div style="font-size: 13px; margin-bottom: 10px; color: #0369a1; font-weight: 700;">
                    <i class="bi bi-info-circle me-1"></i>
                    Contact Lens Order — The following will be saved:
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Clinical notes & findings</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Contact Lens Assessment (BC, DIA, Power)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Diagnosis & treatment plan</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #1e293b; padding: 4px 0;">
                    <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                    <span>Patient will proceed to Sales for payment</span>
                </div>
            </div>
            <div style="font-size: 12px; color: #dc2626; padding: 10px; background: #fef2f2; border-radius: 8px;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>This action cannot be undone.</strong>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0284c7',
        confirmButtonText: '<i class="bi bi-send me-1"></i> Yes, Complete & Forward',
        cancelButtonText: 'Cancel',
        width: '480px'
    }).then(result => {
        if (result.isConfirmed) {
            stopAutoSave();
            
            Swal.fire({ 
                title: 'Sending to Sales...', 
                html: 'Please wait while we save everything.',
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            // ✅ Save clinical notes (kasama ang CL assessment)
            saveClinicalNotes(function() {
                // ✅ Save prescription (para sa CL power/BC/DIA)
                savePrescription(function() {
                    // ✅ Send to sales
                    $.ajax({
                        url: 'api/doctor_dashboard.php?action=send_to_sales',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            appointment_id: currentAppointmentId,
                            patient_id: currentPatientId,
                            order_type: 'contact_lens'
                        }),
                        success: function(r) {
                            Swal.close();
                            if (r.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Sent to Sales!',
                                    html: `
                                        <div style="text-align: center;">
                                            <p style="font-size: 14px; color: #1e293b; margin-bottom: 8px;">
                                                ✅ Contact Lens order naipasa na sa <strong>Sales</strong>.
                                            </p>
                                            <p style="font-size: 12.5px; color: #64748b;">
                                                Patient will proceed to the counter for payment.
                                            </p>
                                        </div>
                                    `,
                                    timer: 3000,
                                    showConfirmButton: false
                                });
                                
                                // ✅ Lock UI
                                $('.exam-pane input, .exam-pane textarea, .exam-pane select, .rx-box input, .rx-box select, #rxNotes, #recLensNotes').prop('disabled', true);
                                $('#startConsultationBtnContainer').hide();
                                $('#sendToSalesBtn').hide();
                                $('.summary-save-link').hide();
                                $('#autosaveIndicator').hide();
                                
                                // ✅ Update status badge
                                $('#patientStatusBadge').text('Sent to Sales').removeClass().addClass('status-badge sent-to-sales');
                                
                                // ✅ Show info alert
                                $('.status-alert').remove();
                                $('#patientCard').prepend(
                                    '<div class="alert alert-info status-alert m-2 d-flex align-items-center gap-2">' +
                                        '<i class="bi bi-cart-check-fill fs-5"></i>' +
                                        '<div>' +
                                            '<strong>Contact Lens Order Sent to Sales</strong> — Waiting for payment.<br>' +
                                            '<small class="text-muted">Patient will proceed to the counter.</small>' +
                                        '</div>' +
                                    '</div>'
                                );
                                
                                sessionStorage.removeItem(`consultation_${currentAppointmentId}_started`);
                                loadQueue();
                            } else {
                                startAutoSave();
                                Swal.fire('Error', r.message || 'Failed to send to Sales', 'error');
                            }
                        },
                        error: function() {
                            Swal.close();
                            startAutoSave();
                            Swal.fire('Error', 'Connection error. Please try again.', 'error');
                        }
                    });
                });
            });
        }
    });
}

   function showQuickRxModal(appointmentId) {
    $('#quickRxForm')[0].reset();
    $('#quickRxAppointmentId').val(appointmentId || currentAppointmentId);
    $('#quickRxValid').prop('checked', true);
    
    const today = new Date().toISOString().split('T')[0];
    $('#quickRxDate').val(today);
    
    new bootstrap.Modal(document.getElementById('quickRxModal')).show();
}

function submitQuickRxValidation() {
    const data = {
        appointment_id: $('#quickRxAppointmentId').val() || currentAppointmentId,
        rx_date: $('#quickRxDate').val(),
        rx_doctor: $('#quickRxDoctor').val(),
        sph_r: $('#quickRxSphR').val(),
        sph_l: $('#quickRxSphL').val(),
        cyl_r: $('#quickRxCylR').val(),
        cyl_l: $('#quickRxCylL').val(),
        axis_r: $('#quickRxAxisR').val(),
        axis_l: $('#quickRxAxisL').val(),
        add_r: $('#quickRxAddR').val(),
        add_l: $('#quickRxAddL').val(),
        notes: $('#quickRxNotes').val(),
        is_valid: $('#quickRxValid').is(':checked') ? 1 : 0
    };
    
    if (!data.is_valid) {
        Swal.fire('Warning', 'Please confirm the prescription is valid.', 'warning');
        return;
    }
    
    if (!data.sph_r && !data.sph_l) {
        Swal.fire('Warning', 'Please enter at least one prescription value.', 'warning');
        return;
    }
    
    Swal.fire({ title: 'Validating…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
    
    $.ajax({
        url: 'api/doctor_dashboard.php?action=validate_existing_rx',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(data),
        success: function(r) {
            Swal.close();
            if (r.success) {
                $('#quickRxModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Validated!',
                    text: 'Prescription validated. Sent to Sales.',
                    timer: 2000,
                    showConfirmButton: false
                });
                loadQueue();
            } else {
                Swal.fire('Error', r.message || 'Validation failed', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Connection error. Please try again.', 'error');
        }
    });
}
</script>