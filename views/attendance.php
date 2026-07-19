<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance | EyecorePH</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .stat-card { transition: transform 0.2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); }
        .location-badge { background: #e3f2fd; color: #1976d2; }
        .table-container { background: white; border-radius: 10px; overflow: hidden; }
        .table th { background-color: #f8f9fa; font-weight: 600; }
        .coordinates { font-family: monospace; font-size: 0.85em; }
        .photo-container { max-width: 300px; max-height: 300px; margin: 0 auto; }
        .photo-container img { width: 100%; height: auto; border-radius: 5px; }
        /* Minimal teal theme - only these classes added */
        .btn-teal {
            background-color: #008080 !important;
            border-color: #008080 !important;
            color: white !important;
        }
        .btn-teal:hover {
            background-color: #006666 !important;
            border-color: #006666 !important;
        }
        .btn-outline-teal {
            border-color: #008080 !important;
            color: #008080 !important;
        }
        .btn-outline-teal:hover {
            background-color: #008080 !important;
            color: white !important;
        }
        .bg-teal {
            background-color: #008080 !important;
        }
        .text-teal {
            color: #008080 !important;
        }
        .border-teal {
            border-color: #008080 !important;
        }
        .nav-tabs .nav-link.active {
            background-color: #008080;
            color: white;
            border-color: #008080;
        }
        .nav-tabs .nav-link {
            color: #008080;
        }
        .badge-pending { background-color: #fff3cd; color: #856404; }
        .badge-approved { background-color: #d4edda; color: #155724; }
        .badge-rejected { background-color: #f8d7da; color: #721c24; }
        .badge-cancelled { background-color: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>

<div class="container py-4">

<!-- Stats - Added onclick to switch tabs -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card" onclick="$('#attendance-tab').tab('show')">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Present</h6>
                        <h3 class="fw-bold text-success" id="statPresent">0</h3>
                    </div>
                    <i class="bi bi-check-circle text-success fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" onclick="$('#attendance-tab').tab('show')">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Late</h6>
                        <h3 class="fw-bold text-warning" id="statLate">0</h3>
                    </div>
                    <i class="bi bi-clock-history text-warning fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" onclick="$('#attendance-tab').tab('show')">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Absent</h6>
                        <h3 class="fw-bold text-danger" id="statAbsent">0</h3>
                    </div>
                    <i class="bi bi-x-circle text-danger fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card" onclick="$('#overtime-tab').tab('show')">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-muted">Pending OT</h6>
                        <h3 class="fw-bold text-teal" id="statPendingOT">0</h3>
                    </div>
                    <i class="bi bi-clock-history text-teal fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab">
            <i class="bi bi-calendar-check me-2"></i> Attendance Records
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="overtime-tab" data-bs-toggle="tab" data-bs-target="#overtime" type="button" role="tab">
            <i class="bi bi-clock-history me-2"></i> Overtime Requests
        </button>
    </li>
</ul>

<!-- Tab Content -->
<div class="tab-content">
    <!-- ATTENDANCE TAB (YOUR EXISTING CODE) -->
    <div class="tab-pane fade show active" id="attendance" role="tabpanel">
<!-- Filters -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <select id="dateFilter" class="form-select" onchange="loadAttendanceData()">
                    <option value="all" selected>All Records</option>
                    <option value="today">Today</option>
                    <option value="this_month">This Month</option>
                </select>
            </div>
            <div class="col-md-3" id="employeeFilterContainer" style="display: none;">
                <select id="employeeFilter" class="form-select" onchange="loadAttendanceData()">
                    <option value="">All Employees</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>">
                            <?= $emp['employee_no'] ?> - <?= $emp['first_name'] ?> <?= $emp['last_name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select id="statusFilter" class="form-select" onchange="loadAttendanceData()">
                    <option value="">All Status</option>
                    <option value="Present">Present</option>
                    <option value="Late">Late</option>
                    <option value="Absent">Absent</option>
                    <option value="On-Leave">On Leave</option>
                </select>
            </div>
            
            <!-- ✅ IDAGDAG DITO ANG APPROVAL STATUS FILTER -->
            <div class="col-md-3">
                <select id="approvalStatusFilter" class="form-select" onchange="loadAttendanceData()">
                    <option value="all">All Approval Status</option>
                    <option value="pending">⚠️ Pending Approval</option>
                    <option value="approved">✅ Approved</option>
                    <option value="rejected">❌ Rejected</option>
                </select>
            </div>
            <!-- END OF ADDED CODE -->
            
            <div class="col-md-3">
                <div class="input-group">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search employee...">
                    <button class="btn btn-teal" onclick="loadAttendanceData()">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

        <!-- Attendance Table -->
        <div class="card shadow-sm table-container">
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="attendanceTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Employee No.</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Total Hours</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Method</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="attendanceTableBody">
                        <!-- Data will be loaded via JavaScript -->
                    </tbody>
                </table>
                <div id="loadingIndicator" class="text-center py-4">
                    <div class="spinner-border text-teal" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading attendance data...</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- OVERTIME TAB -->
    <div class="tab-pane fade" id="overtime" role="tabpanel">
        <!-- Overtime Filters -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-3">
                        <select id="otStatusFilter" class="form-select" onchange="loadOvertimeData()">
                            <option value="all" selected>All Requests</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
<div class="col-md-3" id="otEmployeeFilterContainer" style="display: none;">
    <select id="otEmployeeFilter" class="form-select" onchange="loadOvertimeData()">
        <option value="">All Employees</option>
        <?php foreach($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>">
                <?= $emp['employee_no'] ?> - <?= $emp['first_name'] ?> <?= $emp['last_name'] ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <input type="text" id="otSearchInput" class="form-control" placeholder="Search...">
                            <button class="btn btn-teal" onclick="loadOvertimeData()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Overtime Table -->
        <div class="card shadow-sm table-container">
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="overtimeTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date Requested</th>
                            <th>Employee</th>
                            <th>OT Date</th>
                            <th>Time</th>
                            <th>Hours</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th id="otActionsHeader" style="display: none;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="overtimeTableBody">
                        <!-- Data will be loaded via JavaScript -->
                    </tbody>
                </table>
                <div id="otLoadingIndicator" class="text-center py-4">
                    <div class="spinner-border text-teal" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading overtime requests...</p>
                </div>
            </div>
        </div>
    </div>
</div>

</div>

<!-- View Details Modal (YOUR EXISTING MODAL) -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-labelledby="viewDetailsModalLabel">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-teal text-white">
                <h5 class="modal-title" id="viewDetailsModalLabel">
                    <i class="bi bi-person-check me-2"></i>Attendance Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="modalLoading" class="text-center py-5">
                    <div class="spinner-border text-teal" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
                
                <div id="modalContent" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <!-- Employee Information Card -->
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-person me-2"></i>Employee Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th width="40%">Employee No:</th>
                                            <td id="detailEmpNo">-</td>
                                        </tr>
                                        <tr>
                                            <th>Name:</th>
                                            <td id="detailName">-</td>
                                        </tr>
                                        <tr>
                                            <th>Clinic:</th>
                                            <td id="detailClinic">-</td>
                                        </tr>
                                        <tr>
                                            <th>Date:</th>
                                            <td id="detailDate">-</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Time Records Card -->
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-clock me-2"></i>Time Records</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th width="40%">Time In:</th>
                                            <td id="detailTimeIn">-</td>
                                        </tr>
                                        <tr>
                                            <th>Time Out:</th>
                                            <td id="detailTimeOut">-</td>
                                        </tr>
                                        <tr>
                                            <th>Break Start:</th>
                                            <td id="detailBreakStart">-</td>
                                        </tr>
                                        <tr>
                                            <th>Break End:</th>
                                            <td id="detailBreakEnd">-</td>
                                        </tr>
                                        <tr>
                                            <th>Total Hours:</th>
                                            <td><span id="detailTotalHours" class="fw-bold">0</span> hours</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <!-- Location Information Card -->
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Location Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th width="40%">Status:</th>
                                            <td><span id="detailStatus" class="badge">-</span></td>
                                        </tr>
                                        <tr>
                                            <th>Method:</th>
                                            <td><span id="detailMethod" class="badge bg-light text-dark">-</span></td>
                                        </tr>
                                        <tr>
                                            <th>Location Name:</th>
                                            <td id="detailLocationName">-</td>
                                        </tr>
                                        <tr>
                                            <th>Coordinates:</th>
                                            <td class="coordinates">
                                                <div id="detailCoordinates">-</div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Distance:</th>
                                            <td id="detailDistance">-</td>
                                        </tr>
                                        <tr>
                                            <th>Within Geofence:</th>
                                            <td id="detailWithinGeo">-</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Photo Section Card -->
                            <div class="card">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="bi bi-camera me-2"></i>Attendance Photos</h6>
                                    <select id="photoSelect" class="form-select form-select-sm w-auto" style="display: none;">
                                        <!-- Options will be added by JavaScript -->
                                    </select>
                                </div>
                                <div class="card-body text-center">
                                    <!-- Photo Display -->
                                    <div id="photoContainer" class="photo-container mb-3">
                                        <img id="detailPhoto" src="" alt="Attendance Photo" 
                                             class="img-fluid rounded" style="display: none;">
                                        <div id="noPhoto" class="text-muted">
                                            <i class="bi bi-camera-off fs-1"></i>
                                            <p class="mt-2">No photo available for selected action</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Photo Information -->
                                    <div id="photoInfo" class="text-start small text-muted" style="display: none;">
                                        <div><strong>Action:</strong> <span id="photoAction">-</span></div>
                                        <div><strong>Time:</strong> <span id="photoTime">-</span></div>
                                        <div><strong>Device:</strong> <span id="photoDevice">-</span></div>
                                        <div><strong>IP Address:</strong> <span id="photoIP">-</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Remarks Card -->
                    <div class="card mt-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="bi bi-chat-text me-2"></i>Remarks</h6>
                        </div>
                        <div class="card-body">
                            <p id="detailRemarks" class="mb-0">-</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="googleMapsLink" class="btn btn-teal" target="_blank" style="display: none;">
                    <i class="bi bi-map me-1"></i> View on Google Maps
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Overtime Details Modal -->
<div class="modal fade" id="viewOTModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-teal text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Overtime Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="otModalContent">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
<button type="button" class="btn btn-success" onclick="approveOT()" 
        id="approveOTBtn" style="display:none;">Approve</button>
<button type="button" class="btn btn-danger" onclick="rejectOT()" 
        id="rejectOTBtn" style="display:none;">Reject</button>

            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
// ============= YOUR EXISTING CODE - UNCHANGED =============
let currentUserRole = null;
let userPermissions = {
    view: false,
    manage: false,
    manage_qr: false,
    export: false,
    scan: false,
    view_own: false
};
let hasHR = false;
let isOwner = false;

// ============= ORIGINAL VARIABLES =============
const userRole = '<?= $_SESSION["role"] ?? "" ?>';
const employeeId = <?= $_SESSION["employee_id"] ?? 0 ?>;
const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
const otModal = new bootstrap.Modal(document.getElementById('viewOTModal'));
let currentPhotos = [];

async function loadPermissions() {
    try {
        // Load attendance permissions
        const attRes = await fetch('api/attendance.php?get_permissions=true');
        const attData = await attRes.json();
        
        // Load overtime permissions
        const otRes = await fetch('api/overtime.php?get_permissions=true');
        const otData = await otRes.json();
        
        if (attData.success && otData.success) {
            currentUserRole = attData.data.role;
            
            userPermissions = {
                // Attendance permissions
                view: attData.data.permissions.view || false,
                manage: attData.data.permissions.manage || false,
                manage_qr: attData.data.permissions.manage_qr || false,
                export: attData.data.permissions.export || false,
                scan: attData.data.permissions.scan || false,
                view_own: attData.data.permissions.view_own || false,
                // Overtime permissions
                view_ot: otData.data.permissions.view || false,
                manage_ot: otData.data.permissions.manage || false,
                approve: otData.data.permissions.approve || false,
                reject: otData.data.permissions.reject || false
            };
            
            // ✅ ADD THIS DEBUG
            console.log('=== OVERTIME PERMISSIONS DEBUG ===');
            console.log('view_ot:', userPermissions.view_ot);
            console.log('manage_ot:', userPermissions.manage_ot);
            console.log('approve:', userPermissions.approve);
            console.log('reject:', userPermissions.reject);
            console.log('canManageOvertime():', canManageOvertime());
            console.log('canApproveOvertime():', canApproveOvertime());
            console.log('canRejectOvertime():', canRejectOvertime());
            
            hasHR = attData.data.hasHR;
            isOwner = attData.data.isOwner;
            
            applyPermissionBasedUI();
            loadAttendanceData();
            loadOvertimeData();
            loadStats();
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
    }
}
// ============= PERMISSION HELPER FUNCTIONS =============
function canView() { return userPermissions.view; }
function canManage() { return userPermissions.manage; }
function canManageQR() { return userPermissions.manage_qr; }
function canExport() { return userPermissions.export; }
function canScan() { return userPermissions.scan; }
function canViewOwn() { return userPermissions.view_own; }

// ============= PERMISSION HELPER FUNCTIONS =============

// Attendance permissions
function canViewAttendance() { return userPermissions.view; }
function canManageAttendance() { return userPermissions.manage; }
function canManageQR() { return userPermissions.manage_qr; }
function canExportAttendance() { return userPermissions.export; }
function canScanQR() { return userPermissions.scan; }
function canViewOwnAttendance() { return userPermissions.view_own; }

// ✅ Overtime permissions
function canViewOvertime() {
    return userPermissions.view_ot || userPermissions.view; // fallback to view
}

function canManageOvertime() {
    return userPermissions.manage_ot || userPermissions.manage; // fallback to manage
}

function canApproveOvertime() {
    return userPermissions.approve;
}

function canRejectOvertime() {
    return userPermissions.reject;
}

function canViewOwnOvertime() {
    return userPermissions.view_ot || userPermissions.view_own; // fallback
}

function applyPermissionBasedUI() {
    // QR Management Section
    const qrSection = document.querySelector('.qr-management-section');
    if (qrSection) {
        qrSection.style.display = canManageQR() ? 'block' : 'none';
    }
    
    // Generate QR Button
    const generateQRBtn = document.querySelector('button[onclick="generateQR()"]');
    if (generateQRBtn) {
        generateQRBtn.style.display = canManageQR() ? 'inline-block' : 'none';
    }
    
    // Revoke QR Button
    const revokeQRBtn = document.querySelector('button[onclick="revokeQR()"]');
    if (revokeQRBtn) {
        revokeQRBtn.style.display = canManageQR() ? 'inline-block' : 'none';
    }
    
    // Export Button
    const exportBtn = document.querySelector('button[onclick="exportAttendance()"]');
    if (exportBtn) {
        exportBtn.style.display = canExport() ? 'inline-block' : 'none';
    }
    
    // Auto Absent Button
    const autoAbsentBtn = document.querySelector('button[onclick="autoAbsent()"]');
    if (autoAbsentBtn) {
        autoAbsentBtn.style.display = canManage() ? 'inline-block' : 'none';
    }
    
    // Employee Filter Container (Attendance tab)
    const employeeFilterContainer = document.getElementById('employeeFilterContainer');
    if (employeeFilterContainer) {
        employeeFilterContainer.style.display = canView() ? 'block' : 'none';
    }
    
    // Overtime Employee Filter Container
    const otEmployeeFilterContainer = document.getElementById('otEmployeeFilterContainer');
    if (otEmployeeFilterContainer) {
        otEmployeeFilterContainer.style.display = canManageOvertime() ? 'block' : 'none';
    }
    
    // ✅ I-UPDATE: Overtime Actions Header - show/hide based on permissions
    const otActionsHeader = document.getElementById('otActionsHeader');
    if (otActionsHeader) {
        // Show Actions header if user can view, manage, approve, or reject overtime
        const showActions = canViewOvertime() || canManageOvertime() || canApproveOvertime() || canRejectOvertime();
        otActionsHeader.style.display = showActions ? 'table-cell' : 'none';
        console.log('Overtime Actions Header display:', showActions ? 'visible' : 'hidden');
    }
    
    // If no view permission at all
    if (!canView() && !canViewOwn()) {
        const container = document.querySelector('.container-fluid');
        if (container) {
            container.innerHTML = `
                <div class="container-fluid p-5 text-center">
                    <div class="alert alert-danger">
                        <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                        <h3>Access Denied</h3>
                        <p>You don't have permission to view attendance records.</p>
                    </div>
                </div>
            `;
        }
        return;
    }
}
$(document).ready(function() {
    $('#dateFilter').val('all');
    loadPermissions();
    
    setInterval(() => {
        if (canView() || canViewOwn()) {
            loadStats();
        }
    }, 5000);
    
    $('#searchInput').on('keypress', function(e) {
        if(e.which === 13) {
            loadAttendanceData();
        }
    });
    
    $('#otSearchInput').on('keypress', function(e) {
        if(e.which === 13) {
            loadOvertimeData();
        }
    });
    
    $('#dateFilter, #employeeFilter, #statusFilter').on('change', function() {
        loadAttendanceData();
    });
    
    $('#otStatusFilter, #otEmployeeFilter, #otTypeFilter').on('change', function() {
        loadOvertimeData();
    });
});

function loadAttendanceData() {
    if (!canView() && !canViewOwn()) {
        $('#attendanceTableBody').html(`
             <tr>
                <td colspan="12" class="text-center text-danger">
                    <i class="bi bi-shield-lock me-2"></i>
                    You don't have permission to view attendance records.
                 </td>
             </tr>
        `);
        return;
    }

    const dateRange = $('#dateFilter').val() || 'all';
    let employeeFilter = '';
    
    if (canView()) {
        employeeFilter = $('#employeeFilter').val();
    } else if (canViewOwn()) {
        employeeFilter = employeeId;
    } else {
        employeeFilter = employeeId;
    }
    
    const statusFilter = $('#statusFilter').val();
    const searchTerm = $('#searchInput').val();
    const approvalStatus = $('#approvalStatusFilter').val(); // ✅ ADD THIS
    
    const params = new URLSearchParams();
    params.append('date_range', dateRange);
    if (employeeFilter) params.append('employee_id', employeeFilter);
    if (statusFilter) params.append('status', statusFilter);
    if (searchTerm) params.append('search', searchTerm);
    if (approvalStatus && approvalStatus !== 'all') params.append('approval_status', approvalStatus); // ✅ ADD THIS
    
    $('#loadingIndicator').show();
    $('#attendanceTableBody').html('');
    
    fetch('api/attendance.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            $('#loadingIndicator').hide();
            renderAttendanceTable(data);
        })
        .catch(error => {
            $('#loadingIndicator').hide();
            $('#attendanceTableBody').html(`
                <tr>
                    <td colspan="12" class="text-center text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading data. Please try again.
                    </td>
                </tr>
            `);
            console.error('Error:', error);
        });
}

function renderAttendanceTable(data) {
    let html = '';
    
    if(data.error) {
        html = `
            <tr>
                <td colspan="12" class="text-center text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    ${data.error}
                </td>
            </tr>
        `;
    } else if(!data || data.length === 0) {
        html = `
            <tr>
                <td colspan="12" class="text-center text-muted">
                    <i class="bi bi-calendar-x me-2"></i>
                    No attendance records found
                </td>
            </tr>
        `;
    } else {
        data.forEach(row => {
            let locationDisplay = '-';
            if(row.scan_location_lat && row.scan_location_lng) {
                locationDisplay = `
                    <div class="coordinates">
                        <small>${parseFloat(row.scan_location_lat).toFixed(6)}, ${parseFloat(row.scan_location_lng).toFixed(6)}</small>
                    </div>
                `;
            } else if(row.location_name) {
                locationDisplay = `<span class="text-muted">${row.location_name}</span>`;
            }
            
            let statusClass = '';
            switch(row.status) {
                case 'Present': statusClass = 'bg-success'; break;
                case 'Late': statusClass = 'bg-warning'; break;
                case 'Absent': statusClass = 'bg-danger'; break;
                case 'On-Leave': statusClass = 'bg-info'; break;
                default: statusClass = 'bg-secondary';
            }
            
            // ✅ ADD THIS: Approval status badge
            let approvalBadge = '';
            switch(row.approval_status) {
                case 'approved':
                    approvalBadge = '<span class="badge bg-success ms-1"><i class="bi bi-check-circle"></i> Approved</span>';
                    break;
                case 'rejected':
                    approvalBadge = '<span class="badge bg-danger ms-1"><i class="bi bi-x-circle"></i> Rejected</span>';
                    break;
                case 'pending':
                    approvalBadge = '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-clock-history"></i> Pending</span>';
                    break;
                default:
                    approvalBadge = '';
            }
            
            let totalHours = row.total_hours ? parseFloat(row.total_hours).toFixed(2) : '0.00';
            
            // ✅ Show break times if available
            let breakInfo = '';
            if(row.break_start || row.break_end) {
                breakInfo = `<small class="text-muted d-block">Break: ${row.break_start || '--'} - ${row.break_end || '--'}</small>`;
            }
            
            const viewButton = (canView() || canViewOwn()) ? 
                `<button class="btn btn-sm btn-outline-teal" onclick="viewAttendanceDetails(${row.id})" title="View Full Details">
                    <i class="bi bi-eye"></i> View
                </button>` : '';
            
            // ✅ ADD THIS: Approve/Reject buttons for pending approval (only for managers)
            let approveButtons = '';
            if (row.approval_status === 'pending' && canManage()) {
                approveButtons = `
                    <button class="btn btn-sm btn-outline-success me-1" onclick="approveAttendance(${row.id})" title="Approve">
                        <i class="bi bi-check-lg"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="rejectAttendance(${row.id})" title="Reject">
                        <i class="bi bi-x-lg"></i>
                    </button>
                `;
            }
            
            html += `
            <tr>
                <td>${row.date || '-'}<br>${breakInfo}</td>
                <td><strong>${row.employee_name || 'Unknown'}</strong></td>
                <td>${row.employee_no || '-'}</td>
                <td>${row.time_in || '-'}</td>
                <td>${row.time_out || '-'}</td>
                <td>${totalHours}h</td>
                <td><span class="badge ${statusClass}">${row.status || '-'}</span>${approvalBadge}</td>
                <td>${locationDisplay}</td>
                <td><span class="badge bg-light text-dark">${row.attendance_method || 'qr_scan'}</span></td>
                <td class="text-nowrap">
                    ${viewButton}
                    ${approveButtons}
                </td>
            </tr>`;
        });
    }
    
    $('#attendanceTableBody').html(html);
}

function viewAttendanceDetails(attendanceId) {
    if (!canView() && !canViewOwn()) {
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'You do not have permission to view attendance details'
        });
        return;
    }
    
    $('#modalLoading').show();
    $('#modalContent').hide();
    
    currentPhotos = [];
    $('#detailPhoto').attr('src', '').hide();
    $('#noPhoto').show();
    $('#photoInfo').hide();
    $('#photoSelect').hide().empty();
    
    fetch(`api/attendance.php?attendance_id=${attendanceId}`)
        .then(r => r.json())
        .then(data => {
            $('#modalLoading').hide();
            
            if(data.error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error
                });
                return;
            }
            
            currentPhotos = data.photos || [];
            populateModalData(data);
            $('#modalContent').show();
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            $('#modalLoading').hide();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load attendance details'
            });
        });
}

function populateModalData(data) {
    $('#detailEmpNo').text(data.employee_no || '-');
    $('#detailName').text(`${data.first_name || ''} ${data.last_name || ''}`.trim() || '-');
    $('#detailClinic').text(data.clinic_name || '-');
    $('#detailDate').text(data.date || '-');
    
    $('#detailTimeIn').text(data.time_in || '-');
    $('#detailTimeOut').text(data.time_out || '-');
    $('#detailBreakStart').text(data.break_start || '-');
    $('#detailBreakEnd').text(data.break_end || '-');
    $('#detailTotalHours').text(data.total_hours ? parseFloat(data.total_hours).toFixed(2) : '0.00');
    
    $('#detailStatus').text(data.status || '-');
    $('#detailStatus').removeClass().addClass('badge');
    
    switch(data.status) {
        case 'Present': $('#detailStatus').addClass('bg-success'); break;
        case 'Late': $('#detailStatus').addClass('bg-warning'); break;
        case 'Absent': $('#detailStatus').addClass('bg-danger'); break;
        default: $('#detailStatus').addClass('bg-secondary');
    }
    
    $('#detailMethod').text(data.attendance_method || '-');
    $('#detailLocationName').text(data.attendance_location_name || '-');
    
    let lat = data.scan_location_lat || data.latitude;
    let lng = data.scan_location_lng || data.longitude;
    
    if(lat && lng) {
        $('#detailCoordinates').html(`
            <div>Lat: <strong>${parseFloat(lat).toFixed(6)}</strong></div>
            <div>Lng: <strong>${parseFloat(lng).toFixed(6)}</strong></div>
        `);
        $('#googleMapsLink').show().attr('href', `https://www.google.com/maps?q=${lat},${lng}`);
    } else {
        $('#detailCoordinates').text('-');
        $('#googleMapsLink').hide();
    }
    
    if(data.distance_meters) {
        $('#detailDistance').text(`${parseFloat(data.distance_meters).toFixed(0)} meters`);
    } else {
        $('#detailDistance').text('-');
    }
    
    $('#detailWithinGeo').html(
        data.is_within_geo == 1 
            ? '<span class="badge bg-success">Yes</span>' 
            : '<span class="badge bg-danger">No</span>'
    );
    
    setupPhotoSelection();
    $('#detailRemarks').text(data.remarks || 'No remarks');
}

function setupPhotoSelection() {
    const photoSelect = $('#photoSelect');
    photoSelect.empty().hide();
    
    if (!currentPhotos || currentPhotos.length === 0) {
        return;
    }
    
    currentPhotos.forEach((photo, index) => {
        const actionType = photo.action_type || 'unknown';
        const actionText = getActionText(actionType);
        const hasPhoto = photo.photo_path && photo.photo_path.trim() !== '';
        
        const option = $('<option>')
            .val(index)
            .text(actionText + (hasPhoto ? '' : ' (No photo)'));
        
        photoSelect.append(option);
    });
    
    if (currentPhotos.length > 0) {
        photoSelect.show();
        
        photoSelect.off('change').on('change', function() {
            const index = $(this).val();
            if (index !== '') {
                selectPhoto(parseInt(index));
            }
        });
        
        const firstWithPhoto = currentPhotos.findIndex(p => p.photo_path && p.photo_path.trim() !== '');
        if (firstWithPhoto !== -1) {
            photoSelect.val(firstWithPhoto);
            selectPhoto(firstWithPhoto);
        } else if (currentPhotos.length > 0) {
            photoSelect.val(0);
            selectPhoto(0);
        }
    }
}

function selectPhoto(index) {
    if (!currentPhotos || currentPhotos.length === 0) {
        console.error('No photos available');
        return;
    }
    
    const photo = currentPhotos[index];
    if (!photo) {
        console.error('Photo not found at index:', index);
        return;
    }
    
    const actionType = photo.action_type || 'unknown';
    const actionText = getActionText(actionType);
    const hasPhoto = photo.photo_path && photo.photo_path.trim() !== '';
    
    if (hasPhoto) {
        let photoUrl = photo.photo_path;
        
        if (!photoUrl.includes('uploads/attendance/')) {
            if (!photoUrl.includes('/')) {
                photoUrl = 'uploads/attendance/' + photoUrl;
            }
        }
        
        if (!photoUrl.endsWith('.jpg') && 
            !photoUrl.endsWith('.jpeg') && 
            !photoUrl.endsWith('.png')) {
            photoUrl += '.jpg';
        }
        
        const testImg = new Image();
        testImg.onload = function() {
            $('#detailPhoto').attr('src', photoUrl).show();
            $('#noPhoto').hide();
            updatePhotoInfo(photo, actionText);
        };
        
        testImg.onerror = function() {
            console.error('Photo not found at:', photoUrl);
            $('#detailPhoto').hide();
            $('#noPhoto').show();
            updatePhotoInfo(photo, actionText);
            tryAlternativePaths(photoUrl, photo, actionText);
        };
        
        testImg.src = photoUrl;
        
    } else {
        $('#detailPhoto').hide();
        $('#noPhoto').show();
        updatePhotoInfo(photo, actionText);
    }
}

function tryAlternativePaths(originalPath, photo, actionText) {
    const alternatives = [
        '/' + originalPath,
        '../' + originalPath,
        '../../' + originalPath,
        originalPath.replace('uploads/attendance/', '/uploads/attendance/'),
        originalPath.replace('uploads/attendance/', ''),
    ];
    
    alternatives.forEach(altPath => {
        const testImg = new Image();
        testImg.onload = function() {
            $('#detailPhoto').attr('src', altPath).show();
            $('#noPhoto').hide();
            updatePhotoInfo(photo, actionText);
        };
        testImg.src = altPath;
    });
}

function updatePhotoInfo(photo, actionText) {
    $('#photoAction').text(actionText);
    $('#photoTime').text(photo.scan_time || photo.created_at || '-');
    $('#photoDevice').text(photo.device_info || '-');
    $('#photoIP').text(photo.ip_address || '-');
    $('#photoInfo').show();
}

function getActionText(actionType) {
    switch(actionType) {
        case 'time_in': return 'Time In Photo';
        case 'break_start': return 'Break Start Photo';
        case 'break_end': return 'Break End Photo';
        case 'time_out': return 'Time Out Photo';
        default: return actionType.replace('_', ' ').toUpperCase();
    }
}

function getActionIcon(actionType) {
    switch(actionType) {
        case 'time_in': return 'bi-box-arrow-in-right';
        case 'break_start': return 'bi-cup-straw';
        case 'break_end': return 'bi-cup-fill';
        case 'time_out': return 'bi-box-arrow-right';
        default: return 'bi-camera';
    }
}

function loadStats() {
    if (!canView() && !canViewOwn()) {
        return;
    }
    
    fetch('api/attendance.php?stats=true')
        .then(r => r.json())
        .then(data => {
            if(data.error) {
                console.error('Stats error:', data.error);
                return;
            }
            $('#statPresent').text(data.present || 0);
            $('#statLate').text(data.late || 0);
            $('#statAbsent').text(data.absent || 0);
            $('#statScans').text(data.today_scans || 0);
        })
        .catch(error => {
            console.error('Failed to load stats:', error);
        });
    
    // Load overtime stats
    fetch('api/overtime.php?stats=true')
        .then(r => r.json())
        .then(data => {
            if(data.error) {
                console.error('OT Stats error:', data.error);
                return;
            }
            $('#statPendingOT').text(data.pending || 0);
        })
        .catch(error => {
            console.error('Failed to load OT stats:', error);
        });
}

function loadOvertimeData() {
    // ✅ Get filter values with proper defaults
    let statusFilter = $('#otStatusFilter').val();
    if (!statusFilter || statusFilter === '') {
        statusFilter = 'all';
    }
    
    let employeeFilter = '';
    let typeFilter = $('#otTypeFilter').val();
    let searchTerm = $('#otSearchInput').val();
    
    // ✅ Debug: Log the current filter values
    console.log('🔍 Overtime Filters:');
    console.log('  - Status filter:', statusFilter);
    console.log('  - Type filter:', typeFilter);
    console.log('  - Search term:', searchTerm);
    
    // ✅ Determine employee filter based on permissions
    if (canManageOvertime() || canViewOvertime()) {
        // Managers can filter by any employee
        employeeFilter = $('#otEmployeeFilter').val();
        console.log('  - Employee filter (manager mode):', employeeFilter);
    } else {
        // Regular employees can only see their own
        employeeFilter = employeeId;
        console.log('  - Employee filter (self mode):', employeeFilter);
    }
    
    // ✅ Build query parameters
    const params = new URLSearchParams();
    
    // Only add status if not 'all'
    if (statusFilter && statusFilter !== 'all' && statusFilter !== '') {
        params.append('status', statusFilter);
    }
    
    if (employeeFilter && employeeFilter !== '') {
        params.append('employee_id', employeeFilter);
    }
    
    if (typeFilter && typeFilter !== '' && typeFilter !== 'all') {
        params.append('type', typeFilter);
    }
    
    if (searchTerm && searchTerm !== '') {
        params.append('search', searchTerm);
    }
    
    // ✅ Debug: Log the final URL
    const url = 'api/overtime.php?' + params.toString();
    console.log('📡 Fetching URL:', url);
    console.log('📊 Full params object:', {
        status: statusFilter === 'all' ? '(all)' : statusFilter,
        employee_id: employeeFilter || '(none)',
        type: typeFilter || '(none)',
        search: searchTerm || '(none)'
    });
    
    // ✅ Show loading indicator
    $('#otLoadingIndicator').show();
    $('#overtimeTableBody').html('<tr><td colspan="9" class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading overtime requests...</p></td></tr>');
    
    // ✅ Fetch data
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // ✅ Hide loading indicator
            $('#otLoadingIndicator').hide();
            
            // ✅ Debug: Log the received data
            console.log('✅ Overtime data received:', data);
            console.log('  - Data type:', Array.isArray(data) ? 'Array' : typeof data);
            console.log('  - Data length:', data?.length || 0);
            
            if (data.error) {
                console.error('❌ API Error:', data.error);
                renderOvertimeTable({ error: data.error });
                return;
            }
            
            // ✅ Filter data by status client-side if needed (backup)
            let filteredData = data;
            if (statusFilter && statusFilter !== 'all' && statusFilter !== '' && Array.isArray(data)) {
                const originalCount = data.length;
                filteredData = data.filter(item => item.status === statusFilter);
                console.log(`  - Filtered by status '${statusFilter}': ${filteredData.length} of ${originalCount} records`);
            }
            
            // ✅ Render the table
            renderOvertimeTable(filteredData);
        })
        .catch(error => {
            // ✅ Hide loading indicator and show error
            $('#otLoadingIndicator').hide();
            console.error('❌ Error loading overtime data:', error);
            
            const colSpan = (canManageOvertime() || canApproveOvertime()) ? 9 : 8;
            $('#overtimeTableBody').html(`
                <tr>
                    <td colspan="${colSpan}" class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                        <strong>Error loading data</strong><br>
                        <small>${error.message || 'Please try again later'}</small>
                    </td>
                </tr>
            `);
        });
}

function renderOvertimeTable(data) {
    let html = '';
    const colSpan = 9; // Fixed at 9 columns
    
    if (data.error) {
        html = `
            <tr>
                <td colspan="${colSpan}" class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                    <strong>${data.error}</strong>
                </td>
            </tr>
        `;
        $('#overtimeTableBody').html(html);
        return;
    }
    
    if (!data || data.length === 0) {
        html = `
            <tr>
                <td colspan="${colSpan}" class="text-center py-4 text-muted">
                    <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                    <strong>No overtime requests found</strong>
                </td>
            </tr>
        `;
        $('#overtimeTableBody').html(html);
        return;
    }
    
    // ✅ Debug: Log permissions before rendering
    console.log('=== RENDERING OVERTIME TABLE ===');
    console.log('canViewOvertime():', canViewOvertime());
    console.log('canManageOvertime():', canManageOvertime());
    console.log('canApproveOvertime():', canApproveOvertime());
    console.log('canRejectOvertime():', canRejectOvertime());
    
    data.forEach(row => {
        let statusClass = '';
        switch(row.status) {
            case 'pending':   statusClass = 'badge-pending bg-warning text-dark'; break;
            case 'approved':  statusClass = 'badge-approved bg-success text-white'; break;
            case 'rejected':  statusClass = 'badge-rejected bg-danger text-white'; break;
            case 'cancelled': statusClass = 'badge-cancelled bg-secondary text-white'; break;
            default:          statusClass = 'bg-secondary text-white';
        }
        
        let typeIcon = '';
        switch(row.overtime_type) {
            case 'regular':          typeIcon = 'bi-brightness-high'; break;
            case 'rest_day':         typeIcon = 'bi-calendar-week';   break;
            case 'special_holiday':  typeIcon = 'bi-gift';            break;
            case 'regular_holiday':  typeIcon = 'bi-star';            break;
            case 'double_holiday':   typeIcon = 'bi-star-fill';       break;
            default:                 typeIcon = 'bi-clock';
        }
        
        let typeDisplay = row.overtime_type ? row.overtime_type.replace('_', ' ') : '';
        typeDisplay = typeDisplay.charAt(0).toUpperCase() + typeDisplay.slice(1);
        
        html += `
            <tr>
                <td>${row.created_at ? new Date(row.created_at).toLocaleDateString() : '-'}</td>
                <td><strong>${row.employee_name || 'Unknown'}</strong></td>
                <td>${row.overtime_date ? new Date(row.overtime_date).toLocaleDateString() : '-'}</td>
                <td>${row.time_start || '-'} - ${row.time_end || '-'}</td>
                <td><span class="badge bg-light text-dark">${row.total_hours || 0} hrs</span></td>
                <td><i class="bi ${typeIcon} me-1 text-teal"></i> ${typeDisplay}</td>
                <td><small>${row.reason ? row.reason.substring(0, 30) + (row.reason.length > 30 ? '...' : '') : '-'}</small></td>
                <td><span class="badge ${statusClass}">${row.status || '-'}</span></td>
                <td class="text-nowrap">`;
        
        // ✅ ITO ANG FIX: Use individual permission checks
        // View button - if user can view overtime
        if (canViewOvertime()) {
            html += `<button class="btn btn-sm btn-outline-teal me-1" onclick="viewOTDetails(${row.id})" title="View Details">
                        <i class="bi bi-eye"></i>
                    </button>`;
        }
        
        // Approve/Reject buttons - only for pending and if user has permission
        if (row.status === 'pending') {
            if (canApproveOvertime()) {
                html += `<button class="btn btn-sm btn-outline-success me-1" onclick="approveOT(${row.id})" title="Approve">
                            <i class="bi bi-check-lg"></i>
                        </button>`;
            }
            if (canRejectOvertime()) {
                html += `<button class="btn btn-sm btn-outline-danger" onclick="rejectOT(${row.id})" title="Reject">
                            <i class="bi bi-x-lg"></i>
                        </button>`;
            }
        }
        
        html += `</td></tr>`;
    });
    
    $('#overtimeTableBody').html(html);
}

function viewOTDetails(id) {
    $('#otModalContent').html('<div class="text-center py-4"><div class="spinner-border text-teal"></div><p class="mt-2">Loading...</p></div>');
    otModal.show();
    
    fetch(`api/overtime.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if(data.error) {
                $('#otModalContent').html(`<div class="alert alert-danger">${data.error}</div>`);
                return;
            }
            
            let typeDisplay = data.overtime_type ? data.overtime_type.replace('_', ' ') : '';
            typeDisplay = typeDisplay.charAt(0).toUpperCase() + typeDisplay.slice(1);
            
            let statusClass = '';
            switch(data.status) {
                case 'pending': statusClass = 'badge-pending'; break;
                case 'approved': statusClass = 'badge-approved'; break;
                case 'rejected': statusClass = 'badge-rejected'; break;
                case 'cancelled': statusClass = 'badge-cancelled'; break;
            }
            
            let html = `
                <div class="mb-3">
                    <h6 class="fw-bold text-teal">Employee Information</h6>
                    <p><strong>Name:</strong> ${data.employee_name || '-'}</p>
                    <p><strong>Employee No:</strong> ${data.employee_no || '-'}</p>
                    <p><strong>Position:</strong> ${data.position_name || '-'}</p>
                </div>
                <hr>
                <div class="mb-3">
                    <h6 class="fw-bold text-teal">Overtime Details</h6>
                    <p><strong>Date:</strong> ${data.overtime_date ? new Date(data.overtime_date).toLocaleDateString() : '-'}</p>
                    <p><strong>Time:</strong> ${data.time_start || '-'} - ${data.time_end || '-'}</p>
                    <p><strong>Total Hours:</strong> ${data.total_hours || 0} hours</p>
                    <p><strong>Type:</strong> ${typeDisplay}</p>
                    <p><strong>Reason:</strong> ${data.reason || '-'}</p>
                </div>
                <hr>
                <div class="mb-3">
                    <h6 class="fw-bold text-teal">Status</h6>
                    <p><span class="badge ${statusClass} p-2">${data.status || '-'}</span></p>
                    <p><small>Requested: ${data.created_at ? new Date(data.created_at).toLocaleString() : '-'}</small></p>
                    ${data.approved_at ? `<p><small>Processed: ${new Date(data.approved_at).toLocaleString()}</small></p>` : ''}
                    ${data.approved_by_name ? `<p><small>Processed by: ${data.approved_by_name}</small></p>` : ''}
                </div>
            `;
            
            $('#otModalContent').html(html);
            $('#approveOTBtn, #rejectOTBtn').data('id', data.id);
            
            // ✅ UPDATED: Use permission checks instead of role check
            // Check if user can approve/reject AND the request is still pending
            if(data.status === 'pending' && (canApproveOvertime() || canRejectOvertime())) {
                $('#approveOTBtn, #rejectOTBtn').show();
            } else {
                $('#approveOTBtn, #rejectOTBtn').hide();
            }
        })
        .catch(error => {
            $('#otModalContent').html(`<div class="alert alert-danger">Error loading details</div>`);
            console.error('Error:', error);
        });
}

function approveOT(id) {
    const otId = id || $('#approveOTBtn').data('id');
    
    // ✅ UPDATED: Check permission before proceeding
    if (!canApproveOvertime()) {
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'You do not have permission to approve overtime requests'
        });
        return;
    }
    
    Swal.fire({
        title: 'Approve Overtime?',
        text: 'Are you sure you want to approve this request?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, approve'
    }).then((result) => {
        if(result.isConfirmed) {
            fetch('api/overtime.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'approve',
                    id: otId
                })
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Approved!', 'Overtime request approved', 'success');
                    otModal.hide();
                    loadOvertimeData();
                    loadStats();
                } else {
                    Swal.fire('Error', data.message || 'Failed to approve', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
    });
}

function rejectOT(id) {
    const otId = id || $('#rejectOTBtn').data('id');
    
    // ✅ UPDATED: Check permission before proceeding
    if (!canRejectOvertime()) {
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'You do not have permission to reject overtime requests'
        });
        return;
    }
    
    Swal.fire({
        title: 'Reject Overtime?',
        text: 'Enter reason for rejection:',
        input: 'textarea',
        inputPlaceholder: 'Reason for rejection...',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, reject'
    }).then((result) => {
        if(result.isConfirmed && result.value) {
            fetch('api/overtime.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'reject',
                    id: otId,
                    remarks: result.value
                })
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Rejected!', 'Overtime request rejected', 'success');
                    otModal.hide();
                    loadOvertimeData();
                    loadStats();
                } else {
                    Swal.fire('Error', data.message || 'Failed to reject', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        } else if(result.isConfirmed && !result.value) {
            Swal.fire('Warning', 'Please provide a reason for rejection', 'warning');
        }
    });
}

// ============= MANUAL APPROVE/REJECT ATTENDANCE FUNCTIONS =============

function approveAttendance(attendanceId) {
    if (!canManage()) {
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'You do not have permission to approve attendance'
        });
        return;
    }
    
    Swal.fire({
        title: 'Approve Attendance?',
        text: 'Are you sure you want to approve this attendance record?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, approve',
        input: 'textarea',
        inputLabel: 'Remarks (optional)',
        inputPlaceholder: 'Enter approval remarks...'
    }).then((result) => {
        if(result.isConfirmed) {
            fetch('api/attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    approve_attendance: true,
                    attendance_id: attendanceId,
                    remarks: result.value || 'Manually approved'
                })
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Approved!', data.message, 'success');
                    loadAttendanceData(); // Refresh table
                    loadStats(); // Refresh stats
                } else {
                    Swal.fire('Error', data.message || 'Failed to approve', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
    });
}

function rejectAttendance(attendanceId) {
    if (!canManage()) {
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'You do not have permission to reject attendance'
        });
        return;
    }
    
    Swal.fire({
        title: 'Reject Attendance?',
        text: 'Please provide a reason for rejection:',
        input: 'textarea',
        inputPlaceholder: 'Reason for rejection (e.g., Missing break log, Incomplete time records)...',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, reject',
        inputValidator: (value) => {
            if (!value) {
                return 'Please provide a reason for rejection';
            }
        }
    }).then((result) => {
        if(result.isConfirmed && result.value) {
            fetch('api/attendance.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    reject_attendance: true,
                    attendance_id: attendanceId,
                    reason: result.value
                })
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Rejected!', data.message, 'success');
                    loadAttendanceData(); // Refresh table
                    loadStats(); // Refresh stats
                } else {
                    Swal.fire('Error', data.message || 'Failed to reject', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
    });
}

</script>
</body>
</html>