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

// ✅ RBAC Permission Check - MUST HAVE PAYROLL-APPROVAL VIEW PERMISSION
if (!RBACHelper::hasPermission('payroll-approval_view')) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied - EyeCore</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            :root { --teal: #0d9488; }
            body { background: #f8fafc; }
            .access-denied-card { max-width: 500px; margin: 100px auto; border-radius: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="card access-denied-card">
                <div class="card-body text-center p-5">
                    <div class="mb-4"><i class="bi bi-shield-lock display-1" style="color: var(--teal);"></i></div>
                    <h3 class="fw-bold mb-3">Access Denied</h3>
                    <p class="text-muted mb-4">You don't have permission to access Payroll Approval.</p>
                    <div class="alert alert-light border">
                        <i class="bi bi-person-circle me-2"></i>
                        <strong>Your Role:</strong> <?= $_SESSION['role'] ?? 'Unknown' ?>
                        <br>
                        <small class="text-muted">Required permission: payroll-approval_view</small>
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
$canView = RBACHelper::hasPermission('payroll-approval_view');
$canCreate = RBACHelper::hasPermission('payroll-approval_create');
$canEdit = RBACHelper::hasPermission('payroll-approval_edit');
$canDelete = RBACHelper::hasPermission('payroll-approval_delete');
$canApprove = RBACHelper::hasPermission('payroll-approval_approve');
$canReject = RBACHelper::hasPermission('payroll-approval_reject');
$canRelease = RBACHelper::hasPermission('payroll-approval_release');
$canViewReports = RBACHelper::hasPermission('reports_view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finance Dashboard - Payroll Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .card-soft {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .approval-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
        }
        .status-draft { background-color: #fef3c7; color: #92400e; }
        .status-for_approval { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #dbeafe; color: #1e40af; }
        .status-released { background-color: #d1fae5; color: #065f46; }
        .status-cancelled { background-color: #f3f4f6; color: #6b7280; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .approval-step {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 0.8rem;
        }
        .nav-tabs .nav-link.active {
            border-bottom: 3px solid #0d6efd;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Finance Dashboard</h2>
            <p class="text-muted mb-0">Payroll approval, release, and financial reporting</p>
        </div>
<div class="d-flex gap-2">
    <?php if ($canRelease): ?>
        <button class="btn btn-outline-primary px-3 d-flex align-items-center gap-2"
                onclick="openReleaseBatchModal()">
            <i class="bi bi-cash-stack"></i> Release Batch
        </button>
    <?php endif; ?>
    
    <?php if ($canViewReports): ?>
        <button class="btn btn-primary px-3 d-flex align-items-center gap-2"
                onclick="openGovernmentRemittanceModal()">
            <i class="bi bi-building"></i> Government Remittance
        </button>
    <?php endif; ?>
</div>
    </div>
    
    <!-- STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-3 h-100 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Pending Approval</small>
                        <h3 id="pendingApprovalCount" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock-history fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Awaiting your review</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Approved Payroll</small>
                        <h3 id="approvedPayrollCount" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Ready for release</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">This Month Total</small>
                        <h3 id="monthlyTotalAmount" class="fw-bold mt-1 mb-2">₱0</h3>
                    </div>
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-cash-coin fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Net pay for <span id="currentMonth"></span></small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Released This Month</small>
                        <h3 id="releasedThisMonth" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-send-check fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Paid payrolls</small>
            </div>
        </div>
    </div>
    
    <!-- TABS -->
    <div class="card-soft mb-4">
        <ul class="nav nav-tabs" id="financeTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="approval-tab" data-bs-toggle="tab" 
                        data-bs-target="#approval-tab-pane" type="button">
                    <i class="bi bi-clipboard-check"></i> Pending Approval
                    <span class="badge bg-warning ms-1" id="approvalBadge">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="approved-tab" data-bs-toggle="tab" 
                        data-bs-target="#approved-tab-pane" type="button">
                    <i class="bi bi-check-all"></i> Approved
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="release-tab" data-bs-toggle="tab" 
                        data-bs-target="#release-tab-pane" type="button">
                    <i class="bi bi-cash-coin"></i> Ready for Release
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="released-tab" data-bs-toggle="tab" 
                        data-bs-target="#released-tab-pane" type="button">
                    <i class="bi bi-send-check"></i> Released
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="government-tab" data-bs-toggle="tab" 
                        data-bs-target="#government-tab-pane" type="button">
                    <i class="bi bi-building"></i> Government Remittance
                </button>
            </li>
        </ul>
        
        <div class="tab-content p-3" id="financeTabsContent">
            <!-- TAB 1: PENDING APPROVAL -->
            <div class="tab-pane fade show active" id="approval-tab-pane" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover" id="approvalTable">
                        <thead>
                            <tr>
                                <th>Payroll ID</th>
                                <th>Employee</th>
                                <th>Period</th>
                                <th>Gross Pay</th>
                                <th>Net Pay</th>
                                <th>Days Pending</th>
                                <th>Approval Level</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="approvalTableBody">
                            <!-- Pending approvals will load here -->
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- TAB 2: APPROVED -->
<div class="tab-pane fade" id="approved-tab-pane" role="tabpanel">
    <div class="alert alert-info mb-3">
        <i class="bi bi-info-circle me-2"></i>
        Payrolls in this tab are approved but <strong>NOT YET READY FOR RELEASE</strong>. 
        Select payrolls to move to "Ready for Release" for batch processing.
    </div>
    
    <div class="mb-3">
        <div class="row g-2">
            <div class="col-md-4">
                <input type="text" class="form-control" id="approvedSearch" placeholder="Search employee...">
            </div>
            <div class="col-md-3">
                <input type="text" class="form-control" id="approvedPeriod" placeholder="Period YYYY-MM">
            </div>
            <div class="col-md-3">
                <select class="form-select" id="approvedDepartment">
                    <option value="">All Departments</option>
                    <option value="Optometry">Optometry</option>
                    <option value="Admin">Admin</option>
                    <option value="HR">HR</option>
                    <option value="Finance">Finance</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" onclick="loadApprovedPayrolls()">Filter</button>
            </div>
        </div>
    </div>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
<?php if ($canApprove): ?>
    <button class="btn btn-warning" onclick="moveToReadyForRelease()">
        <i class="bi bi-arrow-right-circle me-1"></i>Move Selected to Ready for Release
    </button>
<?php endif; ?>
        </div>
        <div class="text-end">
            <span class="badge bg-info me-2" id="selectedCount">0 selected</span>
            <strong id="selectedTotal">Total: ₱0.00</strong>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover" id="approvedTable">
            <thead>
                <tr>
                    <th width="50">
                        <input type="checkbox" id="selectAllApproved">
                    </th>
                    <th>Payroll ID</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Period</th>
                    <th>Net Pay</th>
                    <th>Approved Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="approvedTableBody">
                <!-- Approved payrolls will load here -->
            </tbody>
        </table>
    </div>
</div>

            
<!-- TAB 3: READY FOR RELEASE (BATCH PROCESSING) -->
<div class="tab-pane fade" id="release-tab-pane" role="tabpanel">
    <div class="alert alert-warning d-flex align-items-center">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <span>Payrolls in this tab are <strong>READY FOR RELEASE</strong>. Verify details and process batch release.</span>
    </div>
    
    <div class="row g-3">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control form-control-sm" id="readySearch" placeholder="Search employee..." style="width: 200px;">
                            <button class="btn btn-primary btn-sm" onclick="loadReadyForRelease()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <button class="btn btn-outline-danger btn-sm" onclick="clearReadySelection()">
                            <i class="bi bi-x-circle"></i> Clear Selection
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" id="releaseTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" class="form-check-input" id="selectAllReady">
                                    </th>
                                    <th>Payroll ID</th>
                                    <th>Employee</th>
                                    <th>Bank Details</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>Ready Since</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="releaseTableBody">
                                <!-- Ready for release payrolls -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card sticky-top" style="top: 20px;">
                <div class="card-header bg-white py-2">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-send-check me-1"></i>
                        Release Batch Processing
                    </h6>
                </div>
                <div class="card-body">
                    <!-- Selected Summary - Compact -->
                    <div class="bg-light rounded-3 p-2 mb-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-7">
                                <small class="text-muted d-block">Selected for Release</small>
                                <span class="badge bg-primary" id="releaseEmployeeCount">0 employees</span>
                            </div>
                            <div class="col-5 text-end">
                                <small class="text-muted d-block">Total Amount</small>
                                <strong id="releaseTotalAmount">₱0.00</strong>
                            </div>
                        </div>
                    </div>

                    <!-- 2-Column Grid for Form Fields -->
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold small mb-1">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-sm" id="paymentMethod" required>
                                <option value="">Select payment method</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cash">Cash</option>
                                <option value="Check">Check</option>
                                <option value="GCash">GCash</option>
                                <option value="Maya">Maya</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small mb-1">
                                Reference # <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="referenceNumber" placeholder="Ref no." required>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold small mb-1">
                                Release Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control form-control-sm" id="releaseDate" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small mb-1">
                                Time <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control form-control-sm" id="transactionTime" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold small mb-1">
                                Approved By <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-sm" id="approverId" required>
                                <option value="">Select approver</option>
                                <option value="1">Admin</option>
                                <option value="2">Manager</option>
                                <option value="3">Finance</option>
                            </select>
                        </div>
                        <div class="col-6" id="witnessField">
                            <label class="form-label fw-semibold small mb-1">Witness (Optional)</label>
                            <input type="text" class="form-control form-control-sm" id="witnessName" placeholder="Witness name">
                        </div>
                    </div>

                    <!-- Full Width Fields -->
                    <div class="mb-2">
                        <label class="form-label fw-semibold small mb-1">
                            Proof of Payment <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control form-control-sm" id="proofFiles" multiple accept=".jpg,.jpeg,.png,.pdf" required>
                        <small class="text-muted">Upload receipt/screenshot (JPG, PNG, PDF)</small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold small mb-1">Remarks (Optional)</label>
                        <textarea class="form-control form-control-sm" id="releaseRemarks" rows="1" placeholder="Additional notes..."></textarea>
                    </div>

                    <!-- Notification Checkbox -->
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="sendNotification" checked>
                        <label class="form-check-label small" for="sendNotification">
                            Send email notification
                        </label>
                    </div>

                    <!-- Buttons -->
                    <div class="d-grid gap-2">
<?php if ($canApprove): ?>
    <button class="btn btn-success" onclick="processReleaseBatch()" id="releaseBatchBtn">
        <i class="bi bi-send-check me-1"></i> Process Release
    </button>
<?php endif; ?>
                        <button class="btn btn-outline-secondary btn-sm" onclick="previewBatch()">
                            <i class="bi bi-eye me-1"></i> Preview
                        </button>
                    </div>

                    <div class="mt-2 text-center">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Generates payslips & marks as released
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- TAB 4: RELEASED (COMPLETED) -->
<div class="tab-pane fade" id="released-tab-pane" role="tabpanel">
    <div class="alert alert-success mb-3">
        <i class="bi bi-check-circle me-2"></i>
        These payrolls have been <strong>COMPLETELY RELEASED</strong> and payslips are available.
    </div>
    
    <div class="row mb-3">
        <div class="col-md-3">
            <input type="text" class="form-control" id="releasedMonth" placeholder="Month YYYY-MM" value="<?php echo date('Y-m'); ?>">
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary" onclick="loadReleasedPayrolls()">Filter</button>
        </div>
        <div class="col-md-7 text-end">
<?php if ($canViewReports): ?>
    <button class="btn btn-outline-success" onclick="exportReleasedReport()">
        <i class="bi bi-file-earmark-excel me-1"></i> Export Report
    </button>
<?php endif; ?>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover" id="releasedTable">
            <thead>
                <tr>
                    <th>Payslip Code</th>
                    <th>Employee</th>
                    <th>Period</th>
                    <th>Net Pay</th>
                    <th>Payment Method</th>
                    <th>Released Date</th>
                    <th>Released By</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="releasedTableBody">
                <!-- Released payrolls -->
            </tbody>
        </table>
    </div>
</div>
            
            <!-- TAB 5: GOVERNMENT REMITTANCE -->
            <div class="tab-pane fade" id="government-tab-pane" role="tabpanel">
                <div class="row">
                    <div class="col-md-8">
                        <div class="table-responsive">
                            <table class="table table-hover" id="remittanceTable">
                                <thead>
                                    <tr>
                                        <th>Remittance Type</th>
                                        <th>Period</th>
                                        <th>Total Amount</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="remittanceTableBody">
                                    <!-- Government remittances -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card-soft p-3">
                            <h6 class="fw-bold mb-3">Generate Remittance</h6>
                            <div class="mb-3">
                                <label class="form-label">Remittance Type</label>
                                <select class="form-select" id="remittanceType">
                                    <option value="SSS">SSS Contribution</option>
                                    <option value="PhilHealth">PhilHealth Contribution</option>
                                    <option value="PagIBIG">Pag-IBIG Contribution</option>
                                    <option value="BIR">BIR Withholding Tax</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Period (Month)</label>
                                <input type="text" class="form-control" id="remittancePeriod" placeholder="YYYY-MM" value="<?php echo date('Y-m'); ?>">
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" onclick="calculateRemittance()">
                                    <i class="bi bi-calculator"></i> Calculate
                                </button>
<?php if ($canViewReports): ?>
    <button class="btn btn-success" onclick="generateRemittance()">
        <i class="bi bi-file-earmark-text"></i> Generate Report
    </button>
<?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="payrollDetailsModal" tabindex="-1" aria-labelledby="payrollDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="payrollDetailsModalLabel">
                    <i class="bi bi-file-earmark-text me-2"></i>Payroll Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Loading Spinner -->
                <div id="payrollDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading payroll details...</p>
                </div>
                
                <!-- Content -->
                <div id="payrollDetailsContent" style="display: none;">
                    <!-- Header -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-2">
                                <h4 class="mb-0" id="payrollEmployeeName"></h4>
                                <span class="badge ms-2" id="payrollStatusBadge"></span>
                            </div>
                            <div class="text-muted">
                                <div class="d-flex flex-wrap gap-3">
                                    <span><i class="bi bi-person-badge me-1"></i>ID: <span id="payrollEmployeeNo"></span></span>
                                    <span><i class="bi bi-building me-1"></i>Dept: <span id="payrollDepartment"></span></span>
                                    <span><i class="bi bi-calendar me-1"></i>Period: <span id="payrollPeriod"></span></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="card border-0 bg-light">
                                <div class="card-body p-3">
                                    <div class="text-muted small">Net Pay</div>
                                    <h3 class="text-success mb-0" id="payrollNetPay"></h3>
                                    <div class="small text-muted">Gross: <span id="payrollGrossPay"></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs mb-4" id="payrollDetailsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings" type="button" role="tab">
                                <i class="bi bi-cash-coin me-1"></i>Earnings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions" type="button" role="tab">
                                <i class="bi bi-cash-stack me-1"></i>Deductions
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank" type="button" role="tab">
                                <i class="bi bi-bank me-1"></i>Bank Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i>Timeline
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tabs Content -->
                    <div class="tab-content" id="payrollDetailsTabContent">
                        <!-- Earnings Tab -->
                        <div class="tab-pane fade show active" id="earnings" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Basic Salary</span>
                                            <span class="fw-bold" id="earningsBasic"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Overtime Pay</span>
                                            <span class="fw-bold text-success" id="earningsOvertime"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Holiday Pay</span>
                                            <span class="fw-bold text-success" id="earningsHoliday"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Allowances</span>
                                            <span class="fw-bold text-success" id="earningsAllowances"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Bonuses</span>
                                            <span class="fw-bold text-success" id="earningsBonuses"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Other Earnings</span>
                                            <span class="fw-bold text-success" id="earningsOther"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Total Earnings</h5>
                                    <h4 class="text-success mb-0" id="earningsTotal"></h4>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Deductions Tab -->
                        <div class="tab-pane fade" id="deductions" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">SSS Contribution</span>
                                            <span class="fw-bold text-danger" id="deductionsSSS"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">PhilHealth</span>
                                            <span class="fw-bold text-danger" id="deductionsPhilhealth"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Pag-IBIG</span>
                                            <span class="fw-bold text-danger" id="deductionsPagibig"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Withholding Tax</span>
                                            <span class="fw-bold text-danger" id="deductionsTax"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Absences</span>
                                            <span class="fw-bold text-danger" id="deductionsAbsences"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Tardiness</span>
                                            <span class="fw-bold text-danger" id="deductionsTardiness"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Leave Without Pay</span>
                                            <span class="fw-bold text-danger" id="deductionsLWOP"></span>
                                        </div>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="fw-medium">Other Deductions</span>
                                            <span class="fw-bold text-danger" id="deductionsOther"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Total Deductions</h5>
                                    <h4 class="text-danger mb-0" id="deductionsTotal"></h4>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Details Tab -->
                        <div class="tab-pane fade" id="bank" role="tabpanel">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <h6 class="card-title mb-3"><i class="bi bi-bank me-2"></i>Payment Information</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Payment Method</label>
                                            <div class="fw-medium" id="bankPaymentMethod">Not specified</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Payment Status</label>
                                            <div>
                                                <span class="badge" id="bankPaymentStatus">Not specified</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6 class="card-title mt-4 mb-3"><i class="bi bi-credit-card me-2"></i>Bank Account Details</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Bank Name</label>
                                            <div class="fw-medium" id="bankBankName">Not specified</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Account Holder</label>
                                            <div class="fw-medium" id="bankAccountHolder">Not specified</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Account Number</label>
                                            <div class="fw-medium" id="bankAccountNumber">Not specified</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label text-muted small mb-1">Account Type</label>
                                            <div class="fw-medium" id="bankAccountType">Not specified</div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info mt-4">
                                        <i class="bi bi-info-circle me-2"></i>
                                        For security reasons, bank details may be partially masked in this view.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Timeline Tab -->
                        <div class="tab-pane fade" id="timeline" role="tabpanel">
                            <div class="timeline">
                                <div class="timeline-item" id="timelineGenerated">
                                    <div class="timeline-marker bg-primary"></div>
                                    <div class="timeline-content">
                                        <h6 class="mb-1">Payroll Generated</h6>
                                        <div class="text-muted small" id="timelineGeneratedDate"></div>
                                        <div class="text-muted small" id="timelineGeneratedBy"></div>
                                    </div>
                                </div>
                                <div class="timeline-item" id="timelineApproved">
                                    <div class="timeline-marker bg-success"></div>
                                    <div class="timeline-content">
                                        <h6 class="mb-1">Approved</h6>
                                        <div class="text-muted small" id="timelineApprovedDate"></div>
                                        <div class="text-muted small" id="timelineApprovedBy"></div>
                                    </div>
                                </div>
                                <div class="timeline-item" id="timelineReady">
                                    <div class="timeline-marker bg-warning"></div>
                                    <div class="timeline-content">
                                        <h6 class="mb-1">Ready for Release</h6>
                                        <div class="text-muted small" id="timelineReadyDate"></div>
                                    </div>
                                </div>
                                <div class="timeline-item" id="timelineReleased">
                                    <div class="timeline-marker bg-success"></div>
                                    <div class="timeline-content">
                                        <h6 class="mb-1">Released</h6>
                                        <div class="text-muted small" id="timelineReleasedDate"></div>
                                        <div class="text-muted small" id="timelineReleasedBy"></div>
                                        <div class="text-muted small" id="timelinePaymentMethod"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Error Message -->
                <div id="payrollDetailsError" class="text-center py-5" style="display: none;">
                    <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                    <p class="text-danger mt-2" id="payrollErrorMessage"></p>
                    <button class="btn btn-outline-primary btn-sm" onclick="loadPayrollDetails(currentPayrollId)">
                        <i class="bi bi-arrow-clockwise"></i> Retry
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Close
                </button>
                <button type="button" class="btn btn-primary" id="btnPrintPayslip">
                    <i class="bi bi-printer me-1"></i> Print Payslip
                </button>
                <button type="button" class="btn btn-success" id="btnDownloadPayslip">
                    <i class="bi bi-download me-1"></i> Download PDF
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="approvalDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Review Payroll for Approval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                
                <!-- APPROVAL PROGRESS BAR -->
                <div class="approval-status mb-4" id="approvalProgressBar">
                    <!-- Dynamic approval progress -->
                </div>

                <div class="card mb-4 border-danger" id="budgetCheckSection" style="display: none;">
                <div class="card-header bg-danger bg-opacity-10 text-danger fw-bold py-2">
                    <i class="bi bi-calculator me-2"></i>Budget Check
                </div>
                <div class="card-body">
                    <div id="budgetCheckMessage" class="fw-bold"></div>
                    <div class="row mt-2" id="budgetCheckDetails" style="display: none;">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Allocated Budget</small>
                            <span class="fw-bold" id="budgetAllocated">₱0.00</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Remaining Budget</small>
                            <span class="fw-bold" id="budgetRemaining">₱0.00</span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Payroll Amount</small>
                            <span class="fw-bold" id="budgetPayrollAmount">₱0.00</span>
                        </div>
                    </div>
                </div>
            </div>
                
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h6 class="fw-bold" id="approvalEmployeeName"></h6>
                        <p class="mb-1" id="approvalPeriod"></p>
                        <small class="text-muted" id="approvalEmployeeNo"></small>
                    </div>
                    <div class="col-md-4 text-end">
                        <h3 class="fw-bold text-success" id="approvalNetPay"></h3>
                        <small class="text-muted">Net Pay</small>
                    </div>
                </div>
                
                <!-- ===== BANK DETAILS SECTION ===== -->
                <div class="card mb-4 border-primary">
                    <div class="card-header bg-primary bg-opacity-10 text-primary fw-bold py-2">
                        <i class="bi bi-bank me-2"></i>Bank Details
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-building text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Bank Name</small>
                                        <span class="fw-bold" id="approvalBankName">-</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-person-badge text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Account Holder</small>
                                        <span class="fw-bold" id="approvalBankHolder">-</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-credit-card text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Account Number</small>
                                        <span class="fw-bold" id="approvalBankAccount">-</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-check-circle text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Bank Status</small>
                                        <span id="approvalBankStatus" class="badge bg-success">Complete</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== ATTENDANCE SUMMARY SECTION ===== -->
                <div class="card mb-4 border-info">
                    <div class="card-header bg-info bg-opacity-10 text-info fw-bold py-2">
                        <i class="bi bi-calendar-check me-2"></i>Attendance Summary
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center p-2 border-end">
                                    <span class="text-muted d-block">Days Present</span>
                                    <span class="fw-bold fs-4" id="approvalPresentDays">0</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 border-end">
                                    <span class="text-muted d-block">Late Days</span>
                                    <span class="fw-bold fs-4 text-warning" id="approvalLateDays">0</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2">
                                    <span class="text-muted d-block">Absent Days</span>
                                    <span class="fw-bold fs-4 text-danger" id="approvalAbsentDays">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Attendance List -->
                        <div class="mt-3" id="approvalAttendanceListSection" style="display: none;">
                            <hr>
                            <h6 class="fw-bold mb-2">Attendance Records</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Hours</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="approvalAttendanceRecordsList">
                                        <!-- Attendance records will be inserted here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                    <!-- ===== OVERTIME RECORDS SECTION ===== -->
    <div class="card mb-4 border-warning" id="approvalOvertimeSection" style="display: none;">
        <div class="card-header bg-warning bg-opacity-10 text-warning fw-bold py-2">
            <i class="bi bi-clock-history me-2"></i>Overtime Records
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Hours</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Approval Status</th>
                         </tr>
                    </thead>
                    <tbody id="approvalOvertimeList"></tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- ===== LEAVE RECORDS SECTION ===== -->
    <div class="card mb-4 border-secondary" id="approvalLeaveSection" style="display: none;">
        <div class="card-header bg-secondary bg-opacity-10 text-secondary fw-bold py-2">
            <i class="bi bi-calendar-week me-2"></i>Leave Records
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Type</th>
                            <th>Days</th>
                            <th>Approval Status</th>
                            <th>Reason</th>
                         </tr>
                    </thead>
                    <tbody id="approvalLeaveList"></tbody>
                </table>
            </div>
        </div>
    </div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card salary-breakdown p-3">
                            <h6 class="fw-bold mb-3">Earnings</h6>
                            <div class="row mb-1">
                                <div class="col">Basic Salary</div>
                                <div class="col text-end" id="approvalBasicSalary"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Overtime</div>
                                <div class="col text-end" id="approvalOvertime"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Holiday Pay</div>
                                <div class="col text-end" id="approvalHolidayPay"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Allowances</div>
                                <div class="col text-end" id="approvalAllowances"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Bonuses</div>
                                <div class="col text-end" id="approvalBonuses"></div>
                            </div>
                            <hr>
                            <div class="row fw-bold">
                                <div class="col">Gross Pay</div>
                                <div class="col text-end" id="approvalGrossPay"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card deduction-breakdown p-3">
                            <h6 class="fw-bold mb-3">Deductions</h6>
                            <div class="row mb-1">
                                <div class="col">SSS</div>
                                <div class="col text-end" id="approvalSSS"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">PhilHealth</div>
                                <div class="col text-end" id="approvalPhilhealth"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Pag-IBIG</div>
                                <div class="col text-end" id="approvalPagibig"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Tax (WHT)</div>
                                <div class="col text-end" id="approvalTax"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Other Deductions</div>
                                <div class="col text-end" id="approvalOtherDeductions"></div>
                            </div>
                            <hr>
                            <div class="row fw-bold">
                                <div class="col">Total Deductions</div>
                                <div class="col text-end" id="approvalTotalDeductions"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- PAYROLL INFORMATION -->
                <div class="mt-4">
                    <h6 class="fw-bold mb-2">Payroll Information</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Generated By</small>
                            <span id="approvalGeneratedBy"></span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Generated Date</small>
                            <span id="approvalGeneratedDate"></span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Submitted Date</small>
                            <span id="approvalSubmittedDate"></span>
                        </div>
                    </div>
                </div>
            </div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    <?php if ($canApprove): ?>
        <button type="button" class="btn btn-success" onclick="confirmApprovePayroll()">
            <i class="bi bi-check-circle"></i> Approve
        </button>
    <?php endif; ?>
    <?php if ($canReject): ?>
        <button type="button" class="btn btn-danger" onclick="confirmRejectPayroll()">
            <i class="bi bi-x-circle"></i> Reject
        </button>
    <?php endif; ?>
</div>
        </div>
    </div>
</div>

<!-- MODAL - BATCH RELEASE -->
<div class="modal fade" id="releaseBatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Batch Release Payroll</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" id="batchPaymentMethod">
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Release Date</label>
                    <input type="date" class="form-control" id="batchReleaseDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Bank Transaction Reference (if applicable)</label>
                    <input type="text" class="form-control" id="batchReference" placeholder="e.g., BDO-TRX-123456">
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    This action will release payroll for all selected employees and generate payslips.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <?php if ($canRelease): ?>
    <button type="button" class="btn btn-primary" onclick="confirmBatchRelease()">Release Batch</button>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - GOVERNMENT REMITTANCE -->
<div class="modal fade" id="governmentRemittanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Government Remittance Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <!-- SSS Card with Download Button -->
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 class="fw-bold mb-3">SSS Contribution</h6>
                            <div class="mb-2">
                                <small class="text-muted">Total Employee Share</small>
                                <h4 id="sssEmployeeShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Total Employer Share</small>
                                <h4 id="sssEmployerShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted">Total Remittance</small>
                                <h4 id="sssTotal" class="fw-bold text-primary">₱0.00</h4>
                            </div>
                            <button class="btn btn-outline-primary w-100" onclick="generateSSSFile()">
                                <i class="bi bi-file-spreadsheet"></i> Download SSS CSV
                            </button>
                        </div>
                    </div>
                    
                    <!-- PhilHealth Card with Download Button -->
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 class="fw-bold mb-3">PhilHealth Contribution</h6>
                            <div class="mb-2">
                                <small class="text-muted">Total Employee Share</small>
                                <h4 id="philhealthEmployeeShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Total Employer Share</small>
                                <h4 id="philhealthEmployerShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted">Total Remittance</small>
                                <h4 id="philhealthTotal" class="fw-bold text-primary">₱0.00</h4>
                            </div>
                            <button class="btn btn-outline-success w-100" onclick="generatePhilHealthFile()">
                                <i class="bi bi-file-spreadsheet"></i> Download PhilHealth CSV
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3 mt-3">
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 class="fw-bold mb-3">Pag-IBIG Contribution</h6>
                            <div class="mb-2">
                                <small class="text-muted">Total Employee Share</small>
                                <h4 id="pagibigEmployeeShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Total Employer Share</small>
                                <h4 id="pagibigEmployerShare" class="fw-bold">₱0.00</h4>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted">Total Remittance</small>
                                <h4 id="pagibigTotal" class="fw-bold text-primary">₱0.00</h4>
                            </div>
                            <button class="btn btn-outline-info w-100" onclick="generatePagIBIGFile()">
                                <i class="bi bi-file-spreadsheet"></i> Download Pag-IBIG CSV
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card p-3">
                            <h6 class="fw-bold mb-3">BIR Withholding Tax</h6>
                            <div class="mb-2">
                                <small class="text-muted">Total Tax Withheld</small>
                                <h4 id="taxWithheld" class="fw-bold">₱0.00</h4>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">Due Date</small>
                                <h5 id="taxDueDate">10th of next month</h5>
                            </div>
                            <button class="btn btn-outline-warning w-100" onclick="generateBIRFile()">
                                <i class="bi bi-file-spreadsheet"></i> Download BIR 1601C
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <?php if ($canViewReports): ?>
    <button type="button" class="btn btn-primary" onclick="generateAllReports()">Download All Reports</button>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/payroll-approval.js"></script>
</body>
</html>