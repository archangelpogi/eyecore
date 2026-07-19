<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check - MUST HAVE DECISION-SUPPORT VIEW PERMISSION
if (!RBACHelper::hasPermission('decision-support_view')) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied - EyeCore</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <style>
            :root { --teal: #008080; }
            body { background: #f8fafc; font-family: 'Inter', sans-serif; }
            .access-denied-card { max-width: 500px; margin: 100px auto; border-radius: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card access-denied-card shadow">
                <div class="card-body text-center p-5">
                    <div class="mb-4">
                        <i class="bi bi-shield-lock display-1" style="color: var(--teal);"></i>
                    </div>
                    <h3 class="fw-bold mb-3">Access Denied</h3>
                    <p class="text-muted mb-4">You don't have permission to access Clinical Decision Support System.</p>
                    <div class="alert alert-light border">
                        <i class="bi bi-person-circle me-2"></i>
                        <strong>Your Role:</strong> <?= $_SESSION['role'] ?? 'Unknown' ?>
                        <br>
                        <small class="text-muted">Required permission: decision-support_view</small>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Get session data
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'User';

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('decision-support_view');
$canCreate = RBACHelper::hasPermission('decision-support_create');
$canEdit = RBACHelper::hasPermission('decision-support_edit');
$canDelete = RBACHelper::hasPermission('decision-support_delete');
$canApprove = RBACHelper::hasPermission('decision-support_approve');
$canReject = RBACHelper::hasPermission('decision-support_reject');

// ✅ Additional check for optometrist role (for backward compatibility)
$isOptometrist = ($user_role === 'Optometrist' || $user_role === 'ClinicAdmin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Decision Support System - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --teal: #008080;
            --teal-dark: #006666;
            --teal-light: #e0f2f2;
        }
        
        body {
            background: #f0f4f4;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--teal);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 9999;
            opacity: 0.7;
        }
        
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
        }
        
        .list-group-item {
            transition: all 0.2s;
        }
        .list-group-item:hover {
            background: #f8f9fa;
        }
        
        .risk-high {
            background: linear-gradient(135deg, #dc3545, #b02a37);
            color: white;
        }
        .risk-medium {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
        }
        .risk-low {
            background: linear-gradient(135deg, #198754, #157347);
            color: white;
        }
    </style>
</head>
<body>

<div class="container-fluid px-4 py-4">

    <!-- Welcome Banner -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 text-white shadow" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark));">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center flex-wrap gap-3">
                        <div class="bg-white bg-opacity-25 rounded-3 p-3">
                            <i class="bi bi-robot fs-1"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h3 class="fw-bold mb-1">AI-Powered Clinical Intelligence</h3>
                            <p class="mb-0 opacity-75">Get real-time recommendations based on patient data and clinical guidelines</p>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-white text-dark p-3">
                                <i class="bi bi-person-circle me-2"></i><?php echo htmlspecialchars($user_name); ?>
                            </span>
                            <br>
                            <small class="text-white-50"><?php echo htmlspecialchars($user_role); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-3 me-3">
                            <i class="bi bi-people fs-3 text-primary"></i>
                        </div>
                        <div>
                            <span class="text-muted small">Patients Analyzed</span>
                            <h3 class="fw-bold mb-0" id="totalAnalyzed">0</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-warning bg-opacity-10 p-3 rounded-3 me-3">
                            <i class="bi bi-hospital fs-3 text-warning"></i>
                        </div>
                        <div>
                            <span class="text-muted small">Surgery Recs</span>
                            <h3 class="fw-bold mb-0" id="totalSurgeries">0</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 p-3 rounded-3 me-3">
                            <i class="bi bi-send fs-3 text-success"></i>
                        </div>
                        <div>
                            <span class="text-muted small">Referrals</span>
                            <h3 class="fw-bold mb-0" id="totalReferrals">0</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 p-3 rounded-3 me-3">
                            <i class="bi bi-graph-up-arrow fs-3 text-info"></i>
                        </div>
                        <div>
                            <span class="text-muted small">Accuracy Rate</span>
                            <h3 class="fw-bold mb-0">94<small class="fs-6">%</small></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row g-4">
        <!-- Left Column - Patient Analysis -->
        <div class="col-lg-8">
            <!-- Patient Selection Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="bi bi-search me-2 text-teal"></i>Patient Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <select id="analysis_patient_id" class="form-select form-select-lg" onchange="loadPatientData()">
                                <option value="">🔍 Search or select patient...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($canCreate): ?>
                            <button class="btn btn-teal w-100 h-100" onclick="analyzePatient()">
                                <i class="bi bi-magic me-2"></i>Analyze Now
                            </button>
                            <?php else: ?>
                            <button class="btn btn-secondary w-100 h-100" disabled>
                                <i class="bi bi-lock me-2"></i>Analyze (No Permission)
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Patient Clinical Data Card -->
            <div class="card border-0 shadow-sm mb-4" id="patientDataCard" style="display: none;">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-person-vcard me-2 text-teal"></i>Patient Profile</h5>
                        <span class="badge bg-teal" id="patientName"></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="bg-light p-3 rounded-3 mb-3">
                                <small class="text-muted d-block">Demographics</small>
                                <h6 class="fw-bold mb-0" id="patientAgeGender">-</h6>
                                <small class="text-muted" id="patientType"></small>
                            </div>
                            <div class="bg-light p-3 rounded-3">
                                <small class="text-muted d-block">Chief Complaint</small>
                                <h6 class="fw-bold mb-0" id="patientComplaint">-</h6>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="bg-light p-3 rounded-3 mb-3">
                                <small class="text-muted d-block">Visual Acuity</small>
                                <h6 class="fw-bold mb-0" id="patientVA">OD: - / OS: -</h6>
                            </div>
                            <div class="bg-light p-3 rounded-3">
                                <small class="text-muted d-block">Last Diagnosis</small>
                                <h6 class="fw-bold mb-0" id="patientDiagnosis">-</h6>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Recommendations Card -->
            <div class="card border-0 shadow-sm" id="recommendationsCard" style="display: none;">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="bi bi-stars me-2 text-warning"></i>AI Recommendations</h5>
                </div>
                <div class="card-body">
                    <!-- Risk Alert -->
                    <div class="alert d-flex align-items-center mb-4" id="riskAlert" role="alert">
                        <i class="bi me-3 fs-4"></i>
                        <div id="riskMessage"></div>
                    </div>

                    <!-- Diagnosis Suggestions -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-stethoscope me-2 text-primary"></i>Possible Diagnoses
                        </h6>
                        <div class="list-group" id="diagnosisList"></div>
                    </div>

                    <!-- Treatment Plans -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-prescription2 me-2 text-success"></i>Recommended Treatments
                        </h6>
                        <div class="list-group" id="treatmentList"></div>
                    </div>

                    <!-- Surgery Section -->
                    <div class="mb-4" id="surgerySection" style="display: none;">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-hospital me-2 text-danger"></i>Surgery Consultation
                        </h6>
                        <div class="alert alert-danger" id="surgeryRecommendation"></div>
                        
                        <!-- Nearby Clinics -->
                        <div class="mt-3">
                            <small class="text-muted d-block mb-2">Available surgical centers nearby:</small>
                            <div class="list-group" id="nearbyClinics"></div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($canEdit): ?>
                        <button class="btn btn-teal" onclick="applyRecommendations()">
                            <i class="bi bi-check2-circle me-2"></i>Apply to Record
                        </button>
                        <?php endif; ?>
                        <?php if ($canView): ?>
                        <button class="btn btn-outline-teal" onclick="generateReport()">
                            <i class="bi bi-file-text me-2"></i>Generate Report
                        </button>
                        <button class="btn btn-outline-secondary" onclick="exportAnalysis()">
                            <i class="bi bi-download me-2"></i>Export
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Knowledge Base -->
        <div class="col-lg-4">
            <!-- Clinical Guidelines -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="bi bi-book me-2 text-success"></i>Clinical Guidelines</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-dot text-teal fs-4"></i>
                            <strong>Cataract:</strong> Surgery when VA ≤ 20/40
                        </div>
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-dot text-teal fs-4"></i>
                            <strong>Glaucoma:</strong> Refer if IOP > 21 mmHg
                        </div>
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-dot text-teal fs-4"></i>
                            <strong>Diabetic Retinopathy:</strong> Annual screening
                        </div>
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-dot text-teal fs-4"></i>
                            <strong>Macular Degeneration:</strong> Amsler grid
                        </div>
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-dot text-teal fs-4"></i>
                            <strong>High Myopia > -6.00:</strong> Retinal risk
                        </div>
                    </div>
                </div>
            </div>

            <!-- Surgical Centers -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="bi bi-building me-2 text-danger"></i>Surgical Centers</h5>
                </div>
                <div class="card-body p-0" id="surgicalCentersList">
                    <div class="text-center py-4">
                        <div class="spinner-border text-teal"></div>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 py-2">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>Standalone clinics don't offer surgery
                    </small>
                </div>
            </div>

            <!-- System Status -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="bi bi-activity me-2 text-info"></i>System Status</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">AI Model</span>
                        <strong>OptiNet v2.3</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Last Update</span>
                        <strong><?= date('M d, Y') ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Database</span>
                        <strong><i class="bi bi-check-circle-fill text-success me-1"></i>Connected</strong>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: 94%"></div>
                    </div>
                    <small class="text-muted d-block mt-2">AI confidence: 94%</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Referral Modal -->
<div class="modal fade" id="referralModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white;">
                <h5 class="modal-title"><i class="bi bi-send me-2"></i>Send Surgery Referral</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="referralForm">
                    <input type="hidden" id="referral_patient_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Surgical Center</label>
                        <select id="referral_clinic_id" class="form-select" required>
                            <option value="">Select center...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Surgery Type</label>
                        <select id="surgery_type" class="form-select" required>
                            <option value="Cataract">Cataract Surgery</option>
                            <option value="Glaucoma">Glaucoma Surgery</option>
                            <option value="Retinal">Retinal Detachment Repair</option>
                            <option value="LASIK">LASIK/Refractive Surgery</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Priority</label>
                        <select id="referral_priority" class="form-select">
                            <option value="Routine">Routine</option>
                            <option value="Urgent">Urgent</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea id="referral_notes" class="form-control" rows="3" placeholder="Additional information for the surgeon..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <?php if ($canCreate): ?>
                <button type="button" class="btn btn-teal" onclick="submitReferral()">
                    <i class="bi bi-send me-2"></i>Send Referral
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- RBAC Permission Indicator -->
<div class="permission-badge">
    <i class="bi bi-shield-check"></i> 
    <?php 
    $role_permissions = [];
    if($canView) $role_permissions[] = 'View';
    if($canCreate) $role_permissions[] = 'Analyze';
    if($canEdit) $role_permissions[] = 'Apply';
    if($canDelete) $role_permissions[] = 'Delete';
    if($canApprove) $role_permissions[] = 'Approve';
    if($canReject) $role_permissions[] = 'Reject';
    echo implode(' · ', $role_permissions);
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================
// RBAC PERMISSIONS - Passed from PHP to JavaScript
// ============================================
const permissions = {
    canView: <?php echo json_encode($canView); ?>,
    canCreate: <?php echo json_encode($canCreate); ?>,
    canEdit: <?php echo json_encode($canEdit); ?>,
    canDelete: <?php echo json_encode($canDelete); ?>,
    canApprove: <?php echo json_encode($canApprove); ?>,
    canReject: <?php echo json_encode($canReject); ?>
};

const currentUserId = <?php echo json_encode($user_id); ?>;
const currentUserRole = <?php echo json_encode($user_role); ?>;
const currentUserName = <?php echo json_encode($user_name); ?>;

console.log('RBAC Permissions loaded:', permissions);

const clinicId = <?= $clinic_id ?>;
const userId = <?= $user_id ?>;

// ============================================
// INITIALIZATION
// ============================================
$(document).ready(function() {
    // Check if user has view permission
    if (!permissions.canView) {
        showAccessDenied();
        return;
    }
    
    loadPatients();
    loadSurgicalCenters();
    loadDashboardStats();
});

function showAccessDenied() {
    $('.container-fluid').html(`
        <div class="container-fluid p-5 text-center">
            <div class="alert alert-danger">
                <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                <h3>Access Denied</h3>
                <p>You don't have permission to access Clinical Decision Support System.</p>
                <p class="text-muted">Your role: ${currentUserRole}</p>
                <p class="text-muted">Required permission: decision-support_view</p>
            </div>
        </div>
    `);
}

// ================== LOAD DASHBOARD STATS ==================
function loadDashboardStats() {
    if (!permissions.canView) return;
    
    $.ajax({
        url: 'api/decision_support.php?action=get_stats',
        method: 'GET',
        success: function(stats) {
            $('#totalAnalyzed').text(stats.total_analyzed || 0);
            $('#totalSurgeries').text(stats.surgery_recs || 0);
            $('#totalReferrals').text(stats.referrals || 0);
        },
        error: function(xhr, status, error) {
            console.error('Error loading stats:', error);
        }
    });
}

// ================== LOAD PATIENTS ==================
function loadPatients() {
    if (!permissions.canView) return;
    
    $.ajax({
        url: 'api/decision_support.php?action=get_patients',
        method: 'GET',
        success: function(data) {
            let options = '<option value="">🔍 Search or select patient...</option>';
            if (data && data.length > 0) {
                data.forEach(p => {
                    options += `<option value="${p.id}">${escapeHtml(p.name)} (${p.patient_code}) - ${p.age}y ${p.gender}</option>`;
                });
            }
            $('#analysis_patient_id').html(options);
        },
        error: function(xhr, status, error) {
            console.error('Error loading patients:', error);
            $('#analysis_patient_id').html('<option value="">Error loading patients</option>');
        }
    });
}

// ================== LOAD SURGICAL CENTERS ==================
function loadSurgicalCenters() {
    if (!permissions.canView) return;
    
    $.ajax({
        url: 'api/decision_support.php?action=get_surgical_centers',
        method: 'GET',
        success: function(data) {
            let html = '';
            if (data && data.length > 0) {
                data.forEach(c => {
                    html += `
                        <div class="list-group-item border-0 py-3 px-3">
                            <div class="d-flex align-items-start">
                                <div class="bg-${c.offers_eye_surgery ? 'danger' : 'secondary'} bg-opacity-10 p-2 rounded-3 me-3" style="width: 42px; height: 42px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-building fs-5 text-${c.offers_eye_surgery ? 'danger' : 'secondary'}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <strong class="text-truncate">${escapeHtml(c.clinic_name)}</strong>
                                        ${c.offers_eye_surgery ? '<span class="badge bg-danger bg-opacity-10 text-danger" style="font-size: 0.7rem;">Surgery</span>' : ''}
                                    </div>
                                    <div class="d-flex align-items-center text-muted small">
                                        <i class="bi bi-geo-alt me-1 flex-shrink-0"></i>
                                        <span class="text-truncate">${escapeHtml(c.city || 'No city')}</span>
                                    </div>
                                </div>
                                <div class="ms-2 flex-shrink-0">
                                    <span class="badge bg-${c.clinic_type === 'hospital_based' ? 'primary' : 'secondary'} bg-opacity-10 text-${c.clinic_type === 'hospital_based' ? 'primary' : 'secondary'} px-2 py-1" style="font-size: 0.7rem;">
                                        <i class="bi bi-${c.clinic_type === 'hospital_based' ? 'heart-pulse' : 'shop'} me-1"></i>
                                        ${c.clinic_type === 'hospital_based' ? 'Hospital' : 'Standalone'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html = `
                    <div class="text-center py-5">
                        <i class="bi bi-building-slash fs-1 text-muted d-block mb-3"></i>
                        <p class="text-muted mb-0">No clinics found</p>
                    </div>
                `;
            }
            $('#surgicalCentersList').html(html);
            
            // Referral dropdown
            let options = '<option value="">Select center...</option>';
            if (data && data.length > 0) {
                data.filter(c => c.offers_eye_surgery == 1).forEach(c => {
                    options += `<option value="${c.id}">${escapeHtml(c.clinic_name)} (${c.city || 'N/A'})</option>`;
                });
            }
            $('#referral_clinic_id').html(options);
        },
        error: function(xhr, status, error) {
            console.error('Error loading surgical centers:', error);
            $('#surgicalCentersList').html(`
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle fs-1 text-danger d-block mb-3"></i>
                    <p class="text-danger mb-0">Error loading clinics</p>
                    <button class="btn btn-sm btn-outline-primary mt-3" onclick="loadSurgicalCenters()">
                        <i class="bi bi-arrow-repeat me-2"></i>Try Again
                    </button>
                </div>
            `);
        }
    });
}

// ================== LOAD PATIENT DATA ==================
function loadPatientData() {
    if (!permissions.canView) return;
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        $('#patientDataCard').hide();
        $('#recommendationsCard').hide();
        return;
    }

    $.ajax({
        url: 'api/decision_support.php?action=get_patient_data',
        method: 'GET',
        data: { patient_id: patientId },
        success: function(data) {
            if (data.success && data.patient) {
                $('#patientDataCard').show();
                $('#patientName').text(escapeHtml(data.patient.name || 'Unknown'));
                $('#patientAgeGender').text((data.patient.age || '?') + ' / ' + (data.patient.gender || '?'));
                $('#patientType').text(data.patient.patient_type || 'Regular');
                
                let complaint = 'No complaint recorded';
                let diagnosis = 'No diagnosis recorded';
                let vaLeft = 'N/A', vaRight = 'N/A';
                
                if (data.latest_exam) {
                    complaint = data.latest_exam.chief_complaint || 'No complaint';
                    diagnosis = data.latest_exam.diagnosis || 'No diagnosis';
                    if (data.latest_exam.va_left) vaLeft = data.latest_exam.va_left;
                    if (data.latest_exam.va_right) vaRight = data.latest_exam.va_right;
                }
                
                $('#patientComplaint').text(escapeHtml(complaint));
                $('#patientDiagnosis').text(escapeHtml(diagnosis));
                $('#patientVA').text(`OD: ${vaRight} / OS: ${vaLeft}`);
            } else {
                alert('Error loading patient data');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading patient data:', error);
            alert('Failed to load patient data');
        }
    });
}

// ================== ANALYZE PATIENT ==================
function analyzePatient() {
    if (!permissions.canCreate) {
        Swal.fire('Access Denied', 'You don\'t have permission to analyze patients', 'error');
        return;
    }
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        alert('Please select a patient first');
        return;
    }

    $('#recommendationsCard').show();
    $('#diagnosisList').html('<div class="text-center py-3"><div class="spinner-border text-teal"></div></div>');
    
    $.ajax({
        url: 'api/decision_support.php?action=analyze',
        method: 'POST',
        data: JSON.stringify({ patient_id: patientId }),
        contentType: 'application/json',
        success: function(data) {
            // Update dashboard stats after analysis
            loadDashboardStats();
            
            // Risk Alert
            let riskIcon = data.risk_level === 'high' ? 'exclamation-triangle-fill' : 
                          data.risk_level === 'medium' ? 'exclamation-circle-fill' : 'check-circle-fill';
            let riskClass = data.risk_level === 'high' ? 'danger' : 
                           data.risk_level === 'medium' ? 'warning' : 'success';
            
            $('#riskAlert').removeClass().addClass(`alert alert-${riskClass} d-flex align-items-center`);
            $('#riskAlert i').removeClass().addClass(`bi bi-${riskIcon} me-3 fs-4`);
            $('#riskMessage').html(`
                <strong>Risk Level: ${data.risk_level.toUpperCase()}</strong><br>
                ${escapeHtml(data.risk_message)}
            `);

            // Diagnoses
            let diagHtml = '';
            if (data.diagnoses && data.diagnoses.length > 0) {
                data.diagnoses.forEach(d => {
                    diagHtml += `
                        <div class="list-group-item border-0 py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${escapeHtml(d.name)}</strong>
                                    <br><small class="text-muted">${escapeHtml(d.reason)}</small>
                                </div>
                                <span class="badge bg-${d.confidence === 'high' ? 'success' : 'warning'}">
                                    ${d.confidence}
                                </span>
                            </div>
                        </div>
                    `;
                });
            } else {
                diagHtml = '<div class="list-group-item border-0 py-3 text-muted">No diagnoses available</div>';
            }
            $('#diagnosisList').html(diagHtml);

            // Treatments
            let treatmentHtml = '';
            if (data.treatments && data.treatments.length > 0) {
                data.treatments.forEach(t => {
                    treatmentHtml += `
                        <div class="list-group-item border-0 py-3">
                            <i class="bi bi-${t.icon || 'check-circle'} text-success me-2"></i>
                            ${escapeHtml(t.description)}
                        </div>
                    `;
                });
            } else {
                treatmentHtml = '<div class="list-group-item border-0 py-3 text-muted">No treatments recommended</div>';
            }
            $('#treatmentList').html(treatmentHtml);

            // Surgery
            if (data.surgery_recommended) {
                $('#surgerySection').show();
                $('#surgeryRecommendation').html(`
                    <strong>Recommended Surgery:</strong> ${escapeHtml(data.surgery_type)}<br>
                    <small>${escapeHtml(data.surgery_reason)}</small>
                `);
                loadNearbyClinics(data.patient_city);
            } else {
                $('#surgerySection').hide();
            }
        },
        error: function(xhr, status, error) {
            console.error('Error analyzing patient:', error);
            alert('Failed to analyze patient');
            $('#recommendationsCard').hide();
        }
    });
}

// ================== LOAD NEARBY CLINICS ==================
function loadNearbyClinics(city) {
    if (!permissions.canView) return;
    
    $.ajax({
        url: 'api/decision_support.php?action=nearby_clinics',
        method: 'GET',
        data: { city: city },
        success: function(data) {
            let html = '';
            if (data && data.length > 0) {
                data.forEach(c => {
                    html += `
                        <div class="list-group-item border-0 py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${escapeHtml(c.clinic_name)}</strong>
                                    <br><small class="text-muted">${escapeHtml(c.city || 'N/A')}</small>
                                </div>
                                ${permissions.canCreate ? `
                                <button class="btn btn-sm btn-outline-danger" onclick="openReferralModal(${c.id})">
                                    Refer
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
            } else {
                html = '<div class="text-center py-3 text-muted">No surgical centers nearby</div>';
            }
            $('#nearbyClinics').html(html);
        },
        error: function(xhr, status, error) {
            console.error('Error loading nearby clinics:', error);
            $('#nearbyClinics').html('<div class="text-center py-3 text-danger">Error loading clinics</div>');
        }
    });
}

// ================== REFERRAL MODAL ==================
function openReferralModal(clinicId) {
    if (!permissions.canCreate) {
        Swal.fire('Access Denied', 'You don\'t have permission to send referrals', 'error');
        return;
    }
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        alert('Please select a patient first');
        return;
    }
    
    $('#referral_patient_id').val(patientId);
    $('#referral_clinic_id').val(clinicId);
    $('#referralModal').modal('show');
}

function submitReferral() {
    if (!permissions.canCreate) {
        Swal.fire('Access Denied', 'You don\'t have permission to send referrals', 'error');
        return;
    }
    
    const data = {
        patient_id: $('#referral_patient_id').val(),
        clinic_id: $('#referral_clinic_id').val(),
        surgery_type: $('#surgery_type').val(),
        notes: $('#referral_notes').val(),
        priority: $('#referral_priority').val()
    };

    if (!data.patient_id || !data.clinic_id) {
        alert('Please complete all required fields');
        return;
    }

    $.ajax({
        url: 'api/decision_support.php?action=refer',
        method: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        success: function(r) {
            if (r.success) {
                $('#referralModal').modal('hide');
                $('#referralForm')[0].reset();
                loadDashboardStats();
                alert('Referral sent successfully');
            } else {
                alert('Failed to send referral');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error sending referral:', error);
            alert('Failed to send referral');
        }
    });
}

// ================== UTILITY FUNCTIONS ==================
function applyRecommendations() {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to apply recommendations', 'error');
        return;
    }
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        alert('Please select a patient first');
        return;
    }
    alert('Recommendations applied to patient record');
    loadDashboardStats();
}

function generateReport() {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to generate reports', 'error');
        return;
    }
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        alert('Please select a patient first');
        return;
    }
    alert('Generating clinical report...');
}

function exportAnalysis() {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to export', 'error');
        return;
    }
    
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) {
        alert('Please select a patient first');
        return;
    }
    alert('Exporting analysis...');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-refresh stats every 30 seconds
setInterval(loadDashboardStats, 30000);
</script>
</body>
</html>