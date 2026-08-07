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

// ✅ RBAC Permission Check - MUST HAVE PWD SENIOR VIEW PERMISSION
if (!RBACHelper::hasPermission('pwd_senior_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access PWD/Senior Verification.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('pwd_senior_view');
$canCreate = RBACHelper::hasPermission('pwd_senior_create');
$canEdit = RBACHelper::hasPermission('pwd_senior_edit');
$canDelete = RBACHelper::hasPermission('pwd_senior_delete');
$canApprove = RBACHelper::hasPermission('pwd_senior_approve');
$canReject = RBACHelper::hasPermission('pwd_senior_reject');

// Get statistics
$stats = ['total' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN pwd_senior_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN pwd_senior_status = 'verified' THEN 1 ELSE 0 END) as verified,
            SUM(CASE WHEN pwd_senior_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM users
        WHERE clinic_id = ? AND pwd_senior_status IN ('pending', 'verified', 'rejected')
    ");
    $stmt->execute([$clinicId]);
    $statsResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($statsResult) {
        $stats = $statsResult;
    }
} catch (Exception $e) {
    // Silent fail
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PWD/Senior Verification - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--teal-light));
            border-radius: 20px 20px 0 0;
        }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 13px; color: #64748b; font-weight: 500; }
        
        .request-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            margin-bottom: 16px;
        }
        .request-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        
        .request-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .request-body {
            padding: 16px 20px;
        }
        .request-footer {
            padding: 12px 20px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            border-radius: 0 0 16px 16px;
        }
        
        .patient-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-verified { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .type-pwd { background: #dbeafe; color: #2563eb; }
        .type-senior { background: #fce7f3; color: #db2777; }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
            color: white;
        }
        .btn-outline-teal {
            background: transparent;
            border: 1px solid var(--teal);
            color: var(--teal);
            padding: 6px 16px;
            border-radius: 10px;
        }
        .btn-outline-teal:hover {
            background: var(--teal);
            color: white;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #0f172a;
        }
        .page-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 6px 16px;
            border-radius: 30px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            border-color: var(--teal);
            color: var(--teal);
        }
        .filter-tab.active {
            background: var(--teal);
            border-color: var(--teal);
            color: white;
        }
        
        .id-preview {
            max-width: 120px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .id-preview img {
            width: 100%;
            height: auto;
            display: block;
            cursor: pointer;
        }
        
        .modal-id-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">
                <i class="bi bi-id-card me-2" style="color: var(--teal);"></i>PWD/Senior Verification
            </h1>
            <p class="page-subtitle mt-1">Manage PWD and Senior Citizen verification requests from patients</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border p-2">
                <i class="bi bi-info-circle me-1"></i>
                Patients must upload valid ID for verification
            </span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterRequests('all')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                    <i class="bi bi-list-check fs-2 text-teal opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterRequests('pending')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-warning"><?= $stats['pending'] ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <i class="bi bi-hourglass-split fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterRequests('verified')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-success"><?= $stats['verified'] ?></div>
                        <div class="stat-label">Verified</div>
                    </div>
                    <i class="bi bi-check-circle fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterRequests('rejected')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-danger"><?= $stats['rejected'] ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                    <i class="bi bi-x-circle fs-2 text-danger opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="mb-4">
        <div class="filter-tabs">
            <div class="filter-tab active" data-status="all" onclick="filterRequests('all')">All Requests</div>
            <div class="filter-tab" data-status="pending" onclick="filterRequests('pending')">
                Pending <span class="badge bg-warning text-dark ms-1"><?= $stats['pending'] ?></span>
            </div>
            <div class="filter-tab" data-status="verified" onclick="filterRequests('verified')">
                Verified <span class="badge bg-success ms-1"><?= $stats['verified'] ?></span>
            </div>
            <div class="filter-tab" data-status="rejected" onclick="filterRequests('rejected')">
                Rejected <span class="badge bg-danger ms-1"><?= $stats['rejected'] ?></span>
            </div>
        </div>
    </div>

    <!-- Requests Container -->
    <div id="requestsContainer">
        <div class="text-center py-5">
            <div class="spinner-border text-teal" role="status"></div>
            <p class="mt-2 text-muted">Loading verification requests...</p>
        </div>
    </div>
</div>

<!-- View Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-id-card me-2"></i>Verification Request Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewRequestContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-teal"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ✅ Pass permissions to JavaScript
const permissions = {
    canView: <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit: <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};

let currentFilter = 'all';
let currentUserId = null;
let viewModal;

$(document).ready(function() {
    viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
    
    if (permissions.canView) {
        loadRequests();
    } else {
        $('#requestsContainer').html('<div class="alert alert-danger text-center">You don\'t have permission to view verification requests.</div>');
    }
});

function loadRequests() {
    $('#requestsContainer').html('<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading requests...</p></div>');
    
    $.ajax({
        url: '../api/pwd_senior_api.php',
        type: 'POST',
        data: {
            action: 'get_requests',
            status: currentFilter
        },
        dataType: 'json',
        success: function(data) {
            console.log('Response:', data);
            if (data.success) {
                renderRequests(data.requests);
            } else {
                $('#requestsContainer').html('<div class="alert alert-danger">' + (data.message || 'Failed to load requests') + '</div>');
            }
        },
        error: function(xhr, status, error) {
            console.log('Error:', error);
            console.log('Response:', xhr.responseText);
            $('#requestsContainer').html('<div class="alert alert-danger">Connection error: ' + error + '</div>');
        }
    });
}

function renderRequests(requests) {
    if (!requests || requests.length === 0) {
        $('#requestsContainer').html(`
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted"></i>
                <p class="text-muted mt-2">No verification requests found</p>
            </div>
        `);
        return;
    }
    
    let html = '';
    requests.forEach(request => {
        const status = request.pwd_senior_status || 'pending';
        const type = request.pwd_senior_type || 'N/A';
        const idNumber = request.pwd_senior_id_number || 'N/A';
        const hasImage = request.pwd_senior_id_image ? true : false;
        
        const statusLabel = {
            'pending': '<span class="status-badge status-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>',
            'verified': '<span class="status-badge status-verified"><i class="bi bi-check-circle me-1"></i>Verified</span>',
            'rejected': '<span class="status-badge status-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>'
        };
        
        const typeLabel = {
            'pwd': '<span class="type-badge type-pwd"><i class="bi bi-person-wheelchair me-1"></i>PWD</span>',
            'senior': '<span class="type-badge type-senior"><i class="bi bi-person me-1"></i>Senior Citizen</span>'
        };
        
        const imageHtml = hasImage 
            ? `<img src="../assets/images/pwd_ids/${request.pwd_senior_id_image}" alt="ID Image" style="width:80px;height:60px;object-fit:cover;border-radius:8px;cursor:pointer;" onclick="viewImage('${request.pwd_senior_id_image}')">`
            : '<span class="text-muted">No image</span>';
        
        let actionButtons = '';
        if (status === 'pending') {
            if (permissions.canApprove) {
                actionButtons += `<button class="btn btn-sm btn-success" onclick="approveRequest(${request.user_id})">
                    <i class="bi bi-check-lg me-1"></i>Approve
                </button>`;
            }
            if (permissions.canReject) {
                actionButtons += `<button class="btn btn-sm btn-danger ms-1" onclick="rejectRequest(${request.user_id})">
                    <i class="bi bi-x-lg me-1"></i>Reject
                </button>`;
            }
        }
        if (permissions.canDelete && status !== 'verified') {
            actionButtons += `<button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteRequest(${request.user_id})">
                <i class="bi bi-trash me-1"></i>Delete
            </button>`;
        }
        
        html += `
            <div class="request-card">
                <div class="request-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex gap-3">
                            <div class="patient-avatar">
                                ${getInitials(request.patient_name)}
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">${escapeHtml(request.patient_name)}</h6>
                                <small class="text-muted d-block">${escapeHtml(request.patient_email)}</small>
                                <small class="text-muted">${escapeHtml(request.patient_phone || 'No phone')}</small>
                            </div>
                        </div>
                        <div class="text-end">
                            ${statusLabel[status] || statusLabel.pending}
                            <br>
                            ${typeLabel[type] || ''}
                        </div>
                    </div>
                </div>
                <div class="request-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <small class="text-muted d-block">ID Number</small>
                            <strong>${escapeHtml(idNumber)}</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Submitted</small>
                            <span class="small">${new Date(request.submitted_at).toLocaleString()}</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">ID Image</small>
                            ${imageHtml}
                        </div>
                    </div>
                    ${request.pwd_senior_rejection_reason ? `
                    <div class="mt-2">
                        <small class="text-danger d-block">Rejection Reason:</small>
                        <span class="small text-danger">${escapeHtml(request.pwd_senior_rejection_reason)}</span>
                    </div>
                    ` : ''}
                </div>
                <div class="request-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            ${request.pwd_senior_verified_at ? 
                                `<i class="bi bi-check-circle text-success me-1"></i>Verified on ${new Date(request.pwd_senior_verified_at).toLocaleString()}` : 
                                'Waiting for verification'}
                        </small>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-teal" onclick="viewRequest(${request.user_id})">
                                <i class="bi bi-eye me-1"></i>View
                            </button>
                            ${actionButtons}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    $('#requestsContainer').html(html);
}

function getInitials(name) {
    if (!name) return '?';
    return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
}

function filterRequests(status) {
    currentFilter = status;
    
    $('.filter-tab').removeClass('active');
    $(`.filter-tab[data-status="${status}"]`).addClass('active');
    
    loadRequests();
}

function viewRequest(id) {
    currentUserId = id;
    viewModal.show();
    
    $('#viewRequestContent').html('<div class="text-center py-4"><div class="spinner-border text-teal"></div></div>');
    
    $.ajax({
        url: '../api/pwd_senior_api.php',
        type: 'POST',
        data: {
            action: 'get_request',
            id: id
        },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                renderRequestDetails(data.request);
            } else {
                $('#viewRequestContent').html('<div class="alert alert-danger">' + (data.message || 'Failed to load request') + '</div>');
            }
        }
    });
}

function renderRequestDetails(request) {
    const status = request.pwd_senior_status || 'pending';
    const type = request.pwd_senior_type || 'N/A';
    const hasImage = request.pwd_senior_id_image ? true : false;
    
    const statusMap = {
        'pending': 'warning',
        'verified': 'success',
        'rejected': 'danger'
    };
    
    let actionButtons = '';
    if (status === 'pending') {
        if (permissions.canApprove) {
            actionButtons += `<button class="btn btn-success" onclick="approveRequest(${request.user_id})">
                <i class="bi bi-check-lg me-1"></i>Approve
            </button>`;
        }
        if (permissions.canReject) {
            actionButtons += `<button class="btn btn-danger ms-2" onclick="rejectRequest(${request.user_id})">
                <i class="bi bi-x-lg me-1"></i>Reject
            </button>`;
        }
    }
    
    const html = `
        <div class="row g-4">
            <div class="col-md-8">
                <div class="card border-0 shadow-none">
                    <div class="card-body p-0">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="patient-avatar" style="width:64px;height:64px;font-size:24px;">
                                ${getInitials(request.patient_name)}
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold">${escapeHtml(request.patient_name)}</h5>
                                <div class="text-muted small">${escapeHtml(request.patient_email)}</div>
                                <div class="text-muted small">${escapeHtml(request.patient_phone || 'No phone')}</div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Application Type</small>
                                <span class="fw-semibold">${type.toUpperCase()}</span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">ID Number</small>
                                <span class="fw-semibold">${escapeHtml(request.pwd_senior_id_number)}</span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Submitted On</small>
                                <span>${new Date(request.submitted_at).toLocaleString()}</span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Status</small>
                                <span class="badge bg-${statusMap[status] || 'secondary'}">${status.toUpperCase()}</span>
                            </div>
                        </div>
                        
                        ${request.pwd_senior_rejection_reason ? `
                        <div class="mt-3 p-3 bg-danger bg-opacity-10 rounded">
                            <small class="text-danger fw-semibold d-block">Rejection Reason:</small>
                            <span class="small text-danger">${escapeHtml(request.pwd_senior_rejection_reason)}</span>
                        </div>
                        ` : ''}
                        
                        ${request.pwd_senior_verified_at ? `
                        <div class="mt-3 p-3 bg-success bg-opacity-10 rounded">
                            <small class="text-success fw-semibold d-block">Verified On:</small>
                            <span class="small">${new Date(request.pwd_senior_verified_at).toLocaleString()}</span>
                            ${request.verified_by_name ? `<br><small class="text-muted">Verified by: ${escapeHtml(request.verified_by_name)}</small>` : ''}
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-none">
                    <div class="card-body p-0">
                        <small class="text-muted d-block mb-2">Uploaded ID Image</small>
                        ${hasImage ? `
                            <img src="../assets/images/pwd_ids/${request.pwd_senior_id_image}" 
                                 alt="ID Image" 
                                 class="modal-id-image w-100"
                                 onclick="window.open('../assets/images/pwd_ids/${request.pwd_senior_id_image}', '_blank')"
                                 style="cursor:pointer;">
                            <small class="text-muted d-block mt-1">Click image to enlarge</small>
                        ` : '<span class="text-muted">No image uploaded</span>'}
                    </div>
                </div>
            </div>
        </div>
        
        ${actionButtons ? `
        <div class="mt-4 pt-3 border-top">
            ${actionButtons}
        </div>
        ` : ''}
    `;
    
    $('#viewRequestContent').html(html);
}

function approveRequest(id) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve verification requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Approve Verification?',
        html: 'This will verify the patient as <strong>PWD or Senior Citizen</strong>.<br>They will get <strong>20% discount</strong> on all bookings.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            processRequest(id, 'approve');
        }
    });
}

function rejectRequest(id) {
    if (!permissions.canReject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject verification requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Reject Verification?',
        input: 'textarea',
        inputLabel: 'Reason for rejection (required)',
        inputPlaceholder: 'Enter reason why this request is being rejected...',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reject',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (!value || value.trim().length < 5) {
                return 'Please provide a valid reason (at least 5 characters)';
            }
        }
    }).then(result => {
        if (result.isConfirmed) {
            processRequest(id, 'reject', result.value);
        }
    });
}

function processRequest(id, action, notes = '') {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    var postData = {
        action: action === 'approve' ? 'approve' : 'reject',
        id: id
    };
    
    if (action === 'reject') {
        postData.reason = notes;
    }
    
    $.ajax({
        url: '../api/pwd_senior_api.php',
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(data) {
            Swal.close();
            if (data.success) {
                viewModal.hide();
                Swal.fire('Success!', data.message, 'success');
                loadRequests();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Connection error', 'error');
        }
    });
}

function deleteRequest(id) {
    if (!permissions.canDelete) {
        Swal.fire('Access Denied', 'You don\'t have permission to delete requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Delete Request?',
        text: 'This action cannot be undone. The patient will need to resubmit.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc2626'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            $.ajax({
                url: '../api/pwd_senior_api.php',
                type: 'POST',
                data: {
                    action: 'delete',
                    id: id
                },
                dataType: 'json',
                success: function(data) {
                    Swal.close();
                    if (data.success) {
                        viewModal.hide();
                        Swal.fire('Deleted!', data.message, 'success');
                        loadRequests();
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Connection error', 'error');
                }
            });
        }
    });
}

function viewImage(imagePath) {
    Swal.fire({
        title: 'ID Image',
        imageUrl: '../assets/images/pwd_ids/' + imagePath,
        imageWidth: '80%',
        imageHeight: 'auto',
        imageAlt: 'ID Image',
        showCloseButton: true,
        confirmButtonText: 'Close'
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>