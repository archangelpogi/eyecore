<?php
include __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo "<div class='alert alert-danger'>Please login first.</div>";
    exit;
}

// Get session data correctly
$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'Finance';
$clinic_id = $_SESSION['clinic_id'] ?? 1;
$user_name = $_SESSION['name'] ?? 'User';
$year = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Salary Disbursement - Payroll Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Core CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --success-color: #198754;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #0dcaf0;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .card-soft {
            background: white;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: all 0.2s ease;
        }
        
        .card-soft:hover {
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .amount-sm {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffe69c;
        }
        
        .badge-processing {
            background-color: #cfe2ff;
            color: #084298;
            border: 1px solid #9ec5fe;
        }
        
        .badge-completed {
            background-color: #d1e7dd;
            color: #0a3622;
            border: 1px solid #a3cfbb;
        }
        
        .badge-failed {
            background-color: #f8d7da;
            color: #58151c;
            border: 1px solid #f1aeb5;
        }
        
        .badge-draft {
            background-color: #e2e3e5;
            color: #41464b;
            border: 1px solid #c6c8ca;
        }
        
        .table > :not(caption) > * > * {
            padding: 1rem 0.75rem;
            vertical-align: middle;
        }
        
        .hover-row:hover {
            background-color: rgba(13, 110, 253, 0.03) !important;
        }
        
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        
        .progress {
            height: 0.5rem;
            border-radius: 1rem;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
        }
        
        .employee-info {
            font-size: 0.875rem;
        }
        
        .bank-badge {
            background-color: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        @media (max-width: 768px) {
            .amount {
                font-size: 1.25rem;
            }
            
            .table-responsive {
                border-radius: 12px;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER SECTION -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="fw-bold">
                <i class="bi bi-bank text-primary me-2"></i>
                Salary Disbursement
            </h2>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary">Payroll Period: <span id="currentPeriodLabel">Jan 1-15, 2024</span></span>
                <span class="badge bg-secondary"><?= date('F d, Y') ?></span>
            </div>
        </div>
        
        <div class="d-flex gap-2 flex-wrap">
            <?php if (in_array($user_role, ['Finance', 'HR', 'SuperAdmin'])): ?>
            <!-- Changed from showBulkDisbursementModal to generateBankCSV -->
            <button class="btn btn-primary" onclick="generateBankCSV()">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>
                Generate Bank CSV
            </button>
            <button class="btn btn-success" onclick="showUploadBankFileModal()">
                <i class="bi bi-upload me-1"></i>
                Upload Bank File
            </button>
            <?php endif; ?>
            <button class="btn btn-outline-secondary" onclick="exportReport()">
                <i class="bi bi-download me-1"></i>
                Export Report
            </button>
        </div>
    </div>
    
    <!-- STATS CARDS - Updated IDs to match new JS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card-soft p-3 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase">Total This Month</span>
                        <h3 class="amount mt-1 mb-0" id="totalNetPay">₱0.00</h3>
                        <small class="text-muted" id="totalEmployees">0 employees</small>
                    </div>
                    <div class="rounded-circle p-3 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-cash-stack fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-6">
            <div class="card-soft p-3 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase">Queued</span>
                        <h3 class="fw-bold mt-1 mb-0" id="pendingCount">0</h3>
                        <small class="text-warning" id="pendingAmount">₱0.00</small>
                    </div>
                    <div class="rounded-circle p-3 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-hourglass-split fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-6">
            <div class="card-soft p-3 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase">Completed</span>
                        <h3 class="fw-bold mt-1 mb-0" id="completedCount">0</h3>
                        <small class="text-success" id="completedAmount">₱0.00</small>
                    </div>
                    <div class="rounded-circle p-3 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-6">
            <div class="card-soft p-3 stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted small text-uppercase">Failed</span>
                        <h3 class="fw-bold mt-1 mb-0" id="failedCount">0</h3>
                        <small class="text-danger" id="failedAmount">₱0.00</small>
                    </div>
                    <div class="rounded-circle p-3 bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-exclamation-triangle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- FILTER SECTION -->
    <div class="card-soft p-3 mb-4 filter-section">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Payroll Period</label>
                <select class="form-select" id="periodFilter" onchange="applyFilters()">
                    <option value="current">Current Period</option>
                    <option value="previous">Previous Period</option>
                    <?php
                    // Get available payroll periods
                    for ($m = 1; $m <= 12; $m++):
                        $month = sprintf("%04d-%02d", $year, $m);
                        $month_name = date('F Y', strtotime($year . '-' . $m . '-01'));
                    ?>
                    <option value="<?= $month ?>"><?= $month_name ?></option>
                    <?php endfor; ?>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-semibold">Status</label>
                <select class="form-select" id="statusFilter" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="queued">Queued</option>
                    <option value="processing">Processing</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-semibold">Department</label>
                <select class="form-select" id="deptFilter" onchange="applyFilters()">
                    <option value="all">All Departments</option>
                    <option value="Optometry">Optometry</option>
                    <option value="HR">Human Resources</option>
                    <option value="Finance">Finance</option>
                    <option value="Inventory">Inventory</option>
                    <option value="IT">IT</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label fw-semibold">Search Employee</label>
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" class="form-control" id="searchFilter" 
                           placeholder="Name, ID, Bank..." onkeypress="if(event.key==='Enter') applyFilters()">
                </div>
            </div>
            
            <div class="col-md-2">
                <div class="d-flex gap-2">
                    <button class="btn btn-primary w-100" onclick="applyFilters()">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                    <button class="btn btn-outline-secondary" onclick="clearFilters()">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Custom Date Range (Hidden by default) -->
        <div id="customDateRange" class="row mt-3" style="display: none;">
            <div class="col-md-4">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" id="dateFromFilter">
            </div>
            <div class="col-md-4">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" id="dateToFilter">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-primary" onclick="applyCustomDate()">
                    Apply Custom Range
                </button>
            </div>
        </div>
    </div>
    
    <!-- BATCH ACTIONS BAR -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="selectAllCheckbox" onchange="toggleAllRows()">
                <label class="form-check-label fw-semibold" for="selectAllCheckbox">
                    Select All
                </label>
            </div>
            
            <span class="text-muted" id="selectedCount">0 selected</span>
        
            <?php if (in_array($user_role, ['Finance', 'HR', 'SuperAdmin'])): ?>
<div class="btn-group">
    <button class="btn btn-outline-success btn-sm" onclick="generateBankCSV()">
        <i class="bi bi-file-earmark-spreadsheet"></i> Generate CSV
    </button>
    <button class="btn btn-outline-primary btn-sm" onclick="markAsCompleted()">
        <i class="bi bi-check-circle"></i> Mark Completed
    </button>
</div>
            <?php endif; ?>
        </div>
        
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="refreshData()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="toggleColumns()">
                <i class="bi bi-table"></i> Columns
            </button>
        </div>
    </div>
    
    <!-- MAIN DISBURSEMENT TABLE - FIXED COLUMN COUNT (10 columns) -->
    <div class="card-soft overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="disbursementTable">
                <thead class="bg-light">
                    <tr>
                        <th width="40">
                            <input type="checkbox" class="form-check-input" onchange="toggleAll(this)">
                        </th>
                        <th>Employee</th>
                        <th>Bank Details</th>
                        <th class="text-end">Basic Salary</th>
                        <th class="text-end">Allowances</th>
                        <th class="text-end">Deductions</th>
                        <th class="text-end">Net Pay</th>
                        <th>Status</th>
                        <th>Due Date</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody id="disbursementTableBody">
                    <tr>
                        <td colspan="10" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2 text-muted">Loading disbursement data...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- TABLE FOOTER -->
        <div class="p-3 border-top bg-light">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                <div>
                    <small class="text-muted" id="tableSummary">Loading records...</small>
                </div>
                
                <div class="d-flex gap-2">
                    <div class="btn-group">
                        <button class="btn btn-outline-primary btn-sm" onclick="generatePayslips()">
                            <i class="bi bi-file-pdf"></i> Generate Payslips
                        </button>
                        <button class="btn btn-outline-success btn-sm" onclick="downloadSummary()">
                            <i class="bi bi-download"></i> Summary
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PAYMENT SUMMARY CARD -->
    <div class="card-soft p-3">
        <div class="row align-items-center">
            <div class="col-md-4">
                <h6 class="fw-semibold mb-2">
                    <i class="bi bi-pie-chart me-1"></i>
                    Batch Summary
                </h6>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="display-6 fw-bold text-primary" id="batchTotal">₱0.00</span>
                    <span class="text-muted" id="batchCount">(0 selected)</span>
                </div>
            </div>
            
            <div class="col-md-5">
                <div class="row g-2">
                    <div class="col-6">
                        <small class="text-muted d-block">Total Deductions</small>
                        <span class="fw-bold" id="batchDeductions">₱0.00</span>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Net Payable</small>
                        <span class="fw-bold text-success" id="batchNetPayable">₱0.00</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 text-end">
                <?php if (in_array($user_role, ['Finance', 'HR', 'SuperAdmin'])): ?>
                <button class="btn btn-primary w-100" onclick="generateBankCSV()">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>
                    Generate CSV
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODALS -->

<!-- Generate Bank CSV Modal (New) -->
<div class="modal fade" id="generateCSVModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-spreadsheet me-2 text-info"></i>
                    Generate Bank CSV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="csvPreviewContent">
                <!-- Will be filled by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="downloadCSVBtn" onclick="document.getElementById('downloadCSVLink').click()">
                    <i class="bi bi-download me-1"></i>
                    Download CSV
                </button>
                <a id="downloadCSVLink" style="display: none"></a>
            </div>
        </div>
    </div>
</div>

<!-- View Payment Details Modal -->
<div class="modal fade" id="viewPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>
                    Payment Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="paymentDetailsContent">
                <!-- Dynamically filled -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printPayslip()">
                    <i class="bi bi-printer"></i> Print Payslip
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Bank File Modal -->
<div class="modal fade" id="uploadBankFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-upload me-2 text-success"></i>
                    Upload Bank File
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="uploadBankFileForm" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bank</label>
                        <select class="form-select" name="bank_name" required>
                            <option value="BPI">BPI</option>
                            <option value="BDO">BDO</option>
                            <option value="Metrobank">Metrobank</option>
                            <option value="UnionBank">UnionBank</option>
                            <option value="Security Bank">Security Bank</option>
                            <option value="Landbank">Landbank</option>
                            <option value="PNB">PNB</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">File Type</label>
                        <select class="form-select" name="file_type" required>
                            <option value="csv">CSV File</option>
                            <option value="txt">Text File</option>
                            <option value="xlsx">Excel File</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Upload File</label>
                        <input type="file" class="form-control" name="bank_file" 
                               accept=".csv,.txt,.xlsx" required>
                        <div class="form-text">
                            File should contain account numbers, amounts, and reference numbers
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Reference</label>
                        <input type="text" class="form-control" name="reference_number" 
                               placeholder="Optional - will auto-generate if blank">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="uploadBankFile()">
                    <i class="bi bi-upload me-1"></i>
                    Upload & Process
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Column Visibility Modal - UPDATED to 10 columns (0-9) -->
<div class="modal fade" id="columnVisibilityModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Show/Hide Columns</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="list-group">
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="1"> Employee
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="2"> Bank Details
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="3"> Basic Salary
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="4"> Allowances
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="5"> Deductions
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="6"> Net Pay
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="7"> Status
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="8"> Due Date
                    </label>
                    <label class="list-group-item d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input" checked data-column="9"> Actions
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
<script>
let currentUserRole = '<?= addslashes($user_role) ?>';
let clinicId = <?= (int)$clinic_id ?>;
let currentUserId = <?= (int)$current_user_id ?>;
</script>
<script src="assets/js/salary-disbursement.js"></script>

</body>
</html>