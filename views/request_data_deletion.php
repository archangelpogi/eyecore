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

// ✅ RBAC Permission Check - MUST HAVE DATA DELETION VIEW PERMISSION
if (!RBACHelper::hasPermission('request_data_deletion_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Data Deletion Requests.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId = $_SESSION['clinic_id'] ?? null;
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('request_data_deletion_view');
$canCreate = RBACHelper::hasPermission('request_data_deletion_create');
$canEdit = RBACHelper::hasPermission('request_data_deletion_edit');
$canDelete = RBACHelper::hasPermission('request_data_deletion_delete');
$canApprove = RBACHelper::hasPermission('request_data_deletion_approve');
$canReject = RBACHelper::hasPermission('request_data_deletion_reject');

// Get clinic name
$stmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ?");
$stmt->execute([$clinicId]);
$clinicInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$clinicName = $clinicInfo['clinic_name'] ?? 'My Clinic';

// Get statistics - use status column
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'processing' => 0, 'completed' => 0];
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM data_retention_log
    WHERE clinic_id = ?
");
$stmt->execute([$clinicId]);
$statsResult = $stmt->fetch(PDO::FETCH_ASSOC);
if ($statsResult) $stats = $statsResult;

$pageTitle = "Data Deletion Requests";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?> - <?= htmlspecialchars($clinicName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --teal: #0d9488; --teal-dark: #0f766e; }
        body { background: #f8fafc; font-family: 'Inter', system-ui, sans-serif; }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 13px; color: #64748b; font-weight: 500; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        .status-processing { background: #dbeafe; color: #2563eb; }
        .status-completed { background: #ede9fe; color: #7c3aed; }
        
        .btn-teal { background: var(--teal); color: white; border: none; padding: 8px 20px; border-radius: 10px; transition: all 0.2s; }
        .btn-teal:hover { background: var(--teal-dark); transform: translateY(-1px); }
        .btn-outline-teal { background: transparent; border: 1px solid var(--teal); color: var(--teal); padding: 6px 16px; border-radius: 10px; }
        .btn-outline-teal:hover { background: var(--teal); color: white; }
        
        .table-custom th { background: #f8fafc; font-weight: 600; font-size: 12px; color: #475569; text-transform: uppercase; padding: 12px 16px; }
        .table-custom td { padding: 12px 16px; vertical-align: middle; }
        .table-custom tr { cursor: pointer; transition: background 0.2s; }
        .table-custom tr:hover { background: #f8fafc; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header-custom { background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0; padding: 1rem 1.5rem; }
        .info-box { background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border-left: 4px solid var(--teal); }
        .warning-box { background: #fef3c7; border-radius: 12px; padding: 15px; margin-bottom: 15px; border-left: 4px solid #f59e0b; }
        
        .page-title { font-size: 1.75rem; font-weight: 600; }
        .page-subtitle { font-size: 0.875rem; color: #64748b; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
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
        
        @media (max-width: 768px) { .stat-card { margin-bottom: 12px; } }
        
        /* Permission-based visibility */
        .no-permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #ef4444;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 9999;
        }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0"><i class="bi bi-trash3 me-2" style="color: var(--teal);"></i>Data Deletion Requests</h1>
            <p class="page-subtitle mt-1">Manage patient data deletion requests under RA 10173 (10-year retention policy)</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <?php $statItems = [
            ['label'=>'Total', 'val'=>$stats['total'], 'color'=>'primary', 'icon'=>'bi-files', 'status'=>'all'],
            ['label'=>'Pending', 'val'=>$stats['pending'], 'color'=>'warning', 'icon'=>'bi-hourglass-split', 'status'=>'pending'],
            ['label'=>'Approved', 'val'=>$stats['approved'], 'color'=>'success', 'icon'=>'bi-check-circle', 'status'=>'approved'],
            ['label'=>'Rejected', 'val'=>$stats['rejected'], 'color'=>'danger', 'icon'=>'bi-x-circle', 'status'=>'rejected'],
            ['label'=>'Processing', 'val'=>$stats['processing'], 'color'=>'info', 'icon'=>'bi-arrow-repeat', 'status'=>'processing'],
            ['label'=>'Completed', 'val'=>$stats['completed'], 'color'=>'secondary', 'icon'=>'bi-check2-all', 'status'=>'completed'],
        ]; foreach($statItems as $s): ?>
        <div class="col-6 col-sm-4 col-lg-2">
            <div class="stat-card" onclick="filterRequests('<?= $s['status'] ?>')">
                <div class="text-center">
                    <i class="bi <?= $s['icon'] ?> fs-3 text-<?= $s['color'] ?>"></i>
                    <div class="stat-value text-<?= $s['color'] ?>"><?= $s['val'] ?></div>
                    <div class="stat-label"><?= $s['label'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Requests Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2" style="color: var(--teal);"></i>Deletion Requests</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="refreshPage()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="requestsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Request Date</th>
                            <th>Patient</th>
                            <th>Contact</th>
                            <th>Records Age</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="requestsTableBody">
                        <tr><td colspan="7" class="text-center py-5 text-muted"><div class="spinner-border text-teal"></div><p class="mt-2">Loading requests...</p></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Request Modal (with Action Buttons Inside) -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header-custom">
                <h5 class="modal-title"><i class="bi bi-file-text me-2"></i>Deletion Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="requestDetailsContent" style="max-height: 70vh; overflow-y: auto;">
                <div class="text-center py-4"><div class="spinner-border text-teal"></div><p class="mt-2">Loading...</p></div>
            </div>
        </div>
    </div>
</div>

<!-- Separate Modal for Reject Reason -->
<div class="modal fade" id="rejectReasonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Deletion Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Please provide a reason why this request is being rejected. The patient will be notified.</p>
                <textarea id="rejectReasonText" class="form-control" rows="4" placeholder="Enter rejection reason..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmRejectBtn">Confirm Rejection</button>
            </div>
        </div>
    </div>
</div>

<!-- Permission Badge (for debugging - optional) -->
<?php if (!$canApprove && !$canReject): ?>
<div class="no-permission-badge">
    <i class="bi bi-info-circle me-1"></i> View Only Mode
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
let currentRequestId = null;
let viewModal;
let rejectModal;

document.addEventListener('DOMContentLoaded', function() {
    viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
    rejectModal = new bootstrap.Modal(document.getElementById('rejectReasonModal'));
    
    if (permissions.canView) {
        loadRequests();
    } else {
        document.getElementById('requestsTableBody').innerHTML = '<tr><td colspan="7" class="text-center py-5 text-danger"><i class="bi bi-shield-lock fs-1"></i><p class="mt-2">You don\'t have permission to view requests</p></td></tr>';
    }
    
    const confirmRejectBtn = document.getElementById('confirmRejectBtn');
    if (confirmRejectBtn) {
        confirmRejectBtn.addEventListener('click', function() {
            const reason = document.getElementById('rejectReasonText').value.trim();
            if (!reason) {
                Swal.fire({ icon: 'warning', title: 'Reason Required', text: 'Please provide a reason for rejection.' });
                return;
            }
            rejectModal.hide();
            updateRequestStatus(currentRequestId, 'rejected', reason);
        });
    }
});

function refreshPage() { 
    location.reload(); 
}

function filterRequests(status) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to view requests', 'error');
        return;
    }
    currentFilter = status;
    loadRequests();
}

async function loadRequests() {
    try {
        const response = await fetch(`api/request_data_deletion.php?action=get_requests&status=${currentFilter}`);
        const data = await response.json();
        if (data.success) {
            renderRequestsTable(data.requests);
        } else {
            showError('Failed to load requests');
        }
    } catch (error) {
        showError('Connection error');
    }
}

function renderRequestsTable(requests) {
    const tbody = document.getElementById('requestsTableBody');
    if (!requests || requests.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No deletion requests found</p></td></tr>';
        return;
    }
    
    let html = '';
    for (let i = 0; i < requests.length; i++) {
        const req = requests[i];
        const status = (req.status || 'pending').toLowerCase();
        const patientName = (req.first_name || '') + ' ' + (req.last_name || '');
        const trimmedName = patientName.trim() || 'N/A';
        const age = req.eligibility?.years_old || 0;
        const isEligible = age >= 10;
        
        html += '<tr onclick="viewRequest(' + req.id + ')">';
        html += '<td class="fw-bold">#' + req.id + '</td>';
        html += '<td class="text-muted small">' + new Date(req.request_date).toLocaleDateString() + '<br><small>' + new Date(req.request_date).toLocaleTimeString() + '</small></td>';
        html += '<td><strong>' + escapeHtml(trimmedName) + '</strong></td>';
        html += '<td>' + escapeHtml(req.patient_phone || 'N/A') + '<br><small class="text-muted">' + escapeHtml(req.patient_email || 'N/A') + '</small></td>';
        html += '<td><span class="badge ' + (isEligible ? 'bg-success' : 'bg-warning text-dark') + '"><i class="bi ' + (isEligible ? 'bi-check-circle' : 'bi-clock-history') + ' me-1"></i>' + age + '/10 yrs</span></td>';
        html += '<td>' + getStatusBadge(status) + '</td>';
        html += '<td><button class="btn btn-sm btn-outline-primary" onclick="viewRequest(' + req.id + ')"><i class="bi bi-eye me-1"></i>View</button></td>';
        html += '</tr>';
    }
    tbody.innerHTML = html;
}

function getStatusBadge(status) {
    var s = (status || 'pending').toLowerCase();
    var badges = {
        'pending': '<span class="status-badge status-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>',
        'approved': '<span class="status-badge status-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>',
        'rejected': '<span class="status-badge status-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>',
        'processing': '<span class="status-badge status-processing"><i class="bi bi-arrow-repeat me-1"></i>Processing</span>',
        'completed': '<span class="status-badge status-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        'cancelled': '<span class="status-badge status-cancelled"><i class="bi bi-ban me-1"></i>Cancelled</span>'
    };
    return badges[s] || '<span class="status-badge status-pending">' + s + '</span>';
}

async function viewRequest(requestId) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to view request details', 'error');
        return;
    }
    
    currentRequestId = requestId;
    document.getElementById('requestDetailsContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-teal"></div><p class="mt-2">Loading request details...</p></div>';
    viewModal.show();
    
    try {
        const response = await fetch(`api/request_data_deletion.php?action=get_request&id=${requestId}`);
        const data = await response.json();
        if (data.success) {
            renderRequestDetails(data);
        } else {
            document.getElementById('requestDetailsContent').innerHTML = '<div class="alert alert-danger">' + (data.message || 'Failed to load request details') + '</div>';
        }
    } catch (error) {
        document.getElementById('requestDetailsContent').innerHTML = '<div class="alert alert-danger">Connection error: ' + error.message + '</div>';
    }
}

function renderRequestDetails(data) {
    const req = data.request;
    const records = data.records;
    const eligibility = data.eligibility;
    const patientName = ((req.first_name || '') + ' ' + (req.last_name || '')).trim() || 'N/A';
    const status = (req.status || 'pending').toLowerCase();
    const isEligible = (eligibility && eligibility.eligible === true);
    const isPending = (status === 'pending');
    
    let eligibilityHtml = '';
    if (isEligible) {
        eligibilityHtml = '<div class="info-box mb-3">' +
            '<i class="bi bi-check-circle-fill text-success me-2"></i>' +
            '<strong>RA 10173 Eligibility Check:</strong>' +
            '<div class="text-success mt-1"><i class="bi bi-check-circle me-1"></i>ELIGIBLE - Records are ' + (eligibility.years_old || 0) + ' years old (meets 10-year requirement)</div>' +
            '</div>';
    } else {
        const eligibleDate = records && records.oldest_record ? new Date(new Date(records.oldest_record).setFullYear(new Date(records.oldest_record).getFullYear() + 10)).toLocaleDateString() : 'N/A';
        eligibilityHtml = '<div class="warning-box mb-3">' +
            '<i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>' +
            '<strong>RA 10173 Eligibility Check:</strong>' +
            '<div class="text-warning mt-1"><i class="bi bi-clock-history me-1"></i>NOT YET ELIGIBLE - Records are only ' + (eligibility.years_old || 0) + ' years old. Need ' + (eligibility.years_remaining || 10) + ' more years.</div>' +
            '<div class="small text-muted mt-1">📅 Eligible on: ' + eligibleDate + '</div>' +
            '</div>';
    }
    
    let actionButtons = '';
    if (isPending && (permissions.canApprove || permissions.canReject)) {
        actionButtons = '<div class="action-buttons">';
        if (permissions.canApprove) {
            actionButtons += '<button class="btn btn-success" onclick="approveRequest(' + req.id + ')"><i class="bi bi-check-lg me-1"></i>Approve</button>';
        }
        if (permissions.canReject) {
            actionButtons += '<button class="btn btn-danger" onclick="showRejectModal(' + req.id + ')"><i class="bi bi-x-lg me-1"></i>Reject</button>';
        }
        actionButtons += '<button class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>Close</button>' +
            '</div>';
    } else {
        actionButtons = '<div class="action-buttons">' +
            '<button class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x me-1"></i>Close</button>' +
            '</div>';
    }
    
    const html = '<div class="card border-0 shadow-none">' +
        '<div class="card-body">' +
        '<div class="row mb-3">' +
        '<div class="col-md-6"><label class="text-muted small">Patient Name</label><div class="fw-bold">' + escapeHtml(patientName) + '</div></div>' +
        '<div class="col-md-6"><label class="text-muted small">Contact</label><div>' + escapeHtml(req.patient_phone || 'N/A') + '</div></div>' +
        '<div class="col-md-6 mt-2"><label class="text-muted small">Email</label><div>' + escapeHtml(req.patient_email || 'N/A') + '</div></div>' +
        '<div class="col-md-6 mt-2"><label class="text-muted small">Clinic</label><div>' + escapeHtml(req.clinic_name || 'N/A') + '</div></div>' +
        '</div>' +
        '<hr>' +
        '<div class="row mb-3">' +
        '<div class="col-md-4"><label class="text-muted small">Total Records</label><div class="fw-bold">' + (records?.total_records || 0) + '</div></div>' +
        '<div class="col-md-4"><label class="text-muted small">Oldest Record</label><div>' + (records?.oldest_record ? new Date(records.oldest_record).toLocaleDateString() : 'N/A') + '</div></div>' +
        '<div class="col-md-4"><label class="text-muted small">Latest Record</label><div>' + (records?.newest_record ? new Date(records.newest_record).toLocaleDateString() : 'N/A') + '</div></div>' +
        '</div>' +
        eligibilityHtml +
        '<div class="mb-3"><label class="text-muted small">Request Date</label><div>' + new Date(req.request_date).toLocaleString() + '</div></div>' +
        '<div class="mb-3"><label class="text-muted small">Reason for Deletion</label><div class="p-2 bg-light rounded">' + escapeHtml(req.notes || 'No reason provided') + '</div></div>' +
        '<div class="mb-3"><label class="text-muted small">Current Status</label><div>' + getStatusBadge(status) + '</div></div>' +
        (req.admin_notes ? '<div class="mb-3"><label class="text-muted small">Admin Notes</label><div class="p-2 bg-light rounded">' + escapeHtml(req.admin_notes) + '</div></div>' : '') +
        actionButtons +
        '</div>' +
        '</div>';
    
    document.getElementById('requestDetailsContent').innerHTML = html;
}

function showRejectModal(requestId) {
    if (!permissions.canReject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject requests', 'error');
        return;
    }
    currentRequestId = requestId;
    document.getElementById('rejectReasonText').value = '';
    viewModal.hide();
    rejectModal.show();
}

async function approveRequest(requestId) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve requests', 'error');
        return;
    }
    
    const eligibility = await checkEligibility(requestId);
    if (eligibility && !eligibility.eligible) {
        const result = await Swal.fire({
            title: '⚠️ RA 10173 Compliance Warning',
            html: '<div class="text-start">' +
                '<p class="text-warning"><strong>Records are only ' + eligibility.years_old + ' years old.</strong></p>' +
                '<p>RA 10173 requires 10 years retention before deletion.</p>' +
                '<p class="text-muted small">Oldest record: ' + new Date(eligibility.oldest_record).toLocaleDateString() + '<br>Eligible on: ' + new Date(eligibility.eligible_date).toLocaleDateString() + '</p>' +
                '<hr>' +
                '<p>Are you sure you want to approve this request?</p>' +
                '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Approve Anyway',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545'
        });
        if (!result.isConfirmed) return;
    }
    updateRequestStatus(requestId, 'approved', '');
}

async function updateRequestStatus(requestId, newStatus, adminNotes) {
    viewModal.hide();
    
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: function() {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('request_id', requestId);
    formData.append('status', newStatus);
    formData.append('admin_notes', adminNotes || '');
    if (newStatus === 'approved') formData.append('force_approve', 'true');
    
    try {
        const response = await fetch('api/request_data_deletion.php', { method: 'POST', body: formData });
        const data = await response.json();
        Swal.close();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Updated!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(function() {
                loadRequests();
            });
        } else if (data.requires_confirmation) {
            Swal.fire({ icon: 'warning', title: 'Eligibility Warning', text: data.message });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message });
        }
    } catch (error) {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Connection Error', text: error.message });
    }
}

async function checkEligibility(requestId) {
    try {
        const response = await fetch('api/request_data_deletion.php?action=get_request&id=' + requestId);
        const data = await response.json();
        return data.success ? data.eligibility : null;
    } catch (error) {
        return null;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(msg) {
    Swal.fire({ icon: 'error', title: 'Error', text: msg });
}
</script>
</body>
</html>