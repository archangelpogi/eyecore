<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Reports - Finance & Payroll</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    
    <style>
        /* MAIN STYLES */
        .card-soft {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        
        .card-soft:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .amount {
            font-weight: 700;
            color: #059669;
        }
        
        .report-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .chart-container {
            height: 280px;
            position: relative;
        }
        
        .nav-pills .nav-link {
            border-radius: 8px;
            padding: 8px 16px;
        }
        
        .table th {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom-width: 1px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-generated { background: #dbeafe; color: #1e40af; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-ready { background: #fff7ed; color: #9a3412; }
        .status-released { background: #e0f2fe; color: #075985; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .stat-card {
            transition: all 0.2s;
        }
        
        .stat-card:hover {
            background: linear-gradient(145deg, #ffffff, #f9fafb);
        }
        
        .filter-section {
            background: #f9fafb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .btn-action {
            padding: 5px 10px;
            margin: 0 2px;
        }
        
        .timeline {
            position: relative;
            padding: 20px 0;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 40px;
            margin-bottom: 25px;
        }
        
        .timeline-marker {
            position: absolute;
            left: 0;
            top: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .timeline-content {
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            display: none;
        }
        
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .report-preview {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .department-badge {
            background: #e5e7eb;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
        }
        
        .dataTables_length select {
            min-width: 60px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .container-fluid {
                padding: 10px !important;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>

<!-- LOADING OVERLAY -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="text-center">
        <div class="loading-spinner mb-3"></div>
        <h6 id="loadingMessage">Loading...</h6>
    </div>
</div>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER WITH BREADCRUMB -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">Payroll Reports</li>
                </ol>
            </nav>
            <h2 class="fw-bold mb-0">
                <i class="bi bi-file-earmark-text text-primary me-2"></i> 
                Payroll Reports
            </h2>
            <p class="text-muted mb-0">Generate, manage, and analyze payroll reports</p>
        </div>
        
        <div class="d-flex gap-2">
            <!-- PERIOD FILTER DROPDOWN -->
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-calendar-range"></i> Period
                </button>
                <div class="dropdown-menu p-3" style="width: 280px;">
                    <h6 class="dropdown-header">Select Period</h6>
                    <div class="mb-2">
                        <label class="form-label small">From</label>
                        <input type="text" class="form-control form-control-sm flatpickr-input" id="filterPeriodStart" placeholder="Start Date">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">To</label>
                        <input type="text" class="form-control form-control-sm flatpickr-input" id="filterPeriodEnd" placeholder="End Date">
                    </div>
                    <div class="d-grid gap-2 mt-2">
                        <button class="btn btn-primary btn-sm" onclick="applyDateFilter()">Apply Filter</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearDateFilter()">Clear</button>
                    </div>
                </div>
            </div>
            
            <button class="btn btn-primary px-4 d-flex align-items-center gap-2" onclick="openGenerateReportModal()">
                <i class="bi bi-plus-circle"></i> Generate Report
            </button>
            
            <button class="btn btn-success px-4 d-flex align-items-center gap-2" onclick="exportAllReports()">
                <i class="bi bi-file-earmark-excel"></i> Export
            </button>
        </div>
    </div>
    
    <!-- QUICK STATS CARDS - DYNAMIC FROM API -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-3 stat-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted text-uppercase fw-semibold">Reports Generated</small>
                        <h3 id="statsReportsGenerated" class="fw-bold mt-1 mb-1">0</h3>
                    </div>
                    <div class="rounded p-3 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-files fs-4"></i>
                    </div>
                </div>
                <small class="text-muted" id="statsReportsSubtext">This year</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 stat-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted text-uppercase fw-semibold">Total Payroll YTD</small>
                        <h3 id="statsTotalPayroll" class="amount mt-1 mb-1">₱0</h3>
                    </div>
                    <div class="rounded p-3 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-cash-coin fs-4"></i>
                    </div>
                </div>
                <small class="text-muted" id="statsPayrollSubtext">Year to Date</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 stat-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted text-uppercase fw-semibold">Average Salary</small>
                        <h3 id="statsAverageSalary" class="fw-bold mt-1 mb-1">₱0</h3>
                    </div>
                    <div class="rounded p-3 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-graph-up fs-4"></i>
                    </div>
                </div>
                <small class="text-muted">Monthly average</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 stat-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted text-uppercase fw-semibold">Compliance Rate</small>
                        <h3 id="statsComplianceRate" class="fw-bold mt-1 mb-1">0%</h3>
                    </div>
                    <div class="rounded p-3 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-shield-check fs-4"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div id="complianceProgressBar" class="progress-bar bg-warning" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- REPORT TYPE QUICK ACCESS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-3 report-card" onclick="filterByType('Monthly Payroll')">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-calendar-month fs-3"></i>
                    </div>
                    <span class="badge bg-primary rounded-pill" id="badgeMonthly">0</span>
                </div>
                <h6 class="fw-bold mb-1">Monthly Payroll</h6>
                <p class="text-muted small mb-2">Complete monthly payroll summaries</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Click to view</small>
                    <i class="bi bi-arrow-right-circle text-primary"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 report-card" onclick="filterByType('Tax Report')">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="rounded p-2 bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-receipt fs-3"></i>
                    </div>
                    <span class="badge bg-danger rounded-pill" id="badgeTax">0</span>
                </div>
                <h6 class="fw-bold mb-1">Tax Reports</h6>
                <p class="text-muted small mb-2">BIR 2316, 1601C, 1604CF</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Click to view</small>
                    <i class="bi bi-arrow-right-circle text-danger"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 report-card" onclick="filterByType('Government')">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-building fs-3"></i>
                    </div>
                    <span class="badge bg-success rounded-pill" id="badgeGov">0</span>
                </div>
                <h6 class="fw-bold mb-1">Gov't Contributions</h6>
                <p class="text-muted small mb-2">SSS, PhilHealth, Pag-IBIG</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Click to view</small>
                    <i class="bi bi-arrow-right-circle text-success"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 report-card" onclick="filterByType('Analytics')">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-graph-up-arrow fs-3"></i>
                    </div>
                    <span class="badge bg-info rounded-pill" id="badgeAnalytics">0</span>
                </div>
                <h6 class="fw-bold mb-1">Analytics</h6>
                <p class="text-muted small mb-2">Trends, forecasts, comparisons</p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Click to view</small>
                    <i class="bi bi-arrow-right-circle text-info"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- MAIN CONTENT: REPORTS TABLE & CHARTS -->
    <div class="row g-4">
        <!-- LEFT COLUMN: REPORTS TABLE -->
        <div class="col-lg-8">
            <div class="card-soft p-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-table me-2"></i>Recent Reports
                    </h5>
                    
                    <div class="d-flex gap-2">
                        <!-- SEARCH BOX -->
                        <div class="input-group input-group-sm" style="width: 250px;">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="reportSearch" placeholder="Search employee, ID..." onkeyup="searchReports()">
                        </div>
                        
                        <!-- FILTER DROPDOWN -->
                        <select class="form-select form-select-sm" id="reportTypeFilter" style="width: 150px;" onchange="filterReports()">
                            <option value="all">All Types</option>
                            <option value="Monthly Payroll">Monthly Payroll</option>
                            <option value="Tax Report">Tax Report</option>
                            <option value="Government">Government</option>
                            <option value="Analytics">Analytics</option>
                            <option value="Bonus">Bonus</option>
                        </select>
                        
                        <!-- REFRESH BUTTON -->
                        <button class="btn btn-sm btn-outline-secondary" onclick="loadReports()" title="Refresh">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="reportsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Report Name</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th>Department</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Generated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="reportsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="spinner-border text-primary mb-2"></div>
                                    <p class="text-muted">Loading reports...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- PAGINATION -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        Showing <span id="reportStartCount">0</span> to <span id="reportEndCount">0</span> of <span id="reportTotalCount">0</span> reports
                    </div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0" id="pagination">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" tabindex="-1">Previous</a>
                            </li>
                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item">
                                <a class="page-link" href="#">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- RIGHT COLUMN: QUICK ACTIONS & STATS -->
        <div class="col-lg-4">
            <!-- QUICK GENERATE CARDS -->
            <div class="card-soft p-3 mb-4">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-lightning-charge me-2 text-warning"></i>Quick Generate
                </h6>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary text-start d-flex align-items-center" onclick="quickGenerate('monthly')">
                        <i class="bi bi-calendar-month me-2"></i> Monthly Payroll Summary
                        <span class="ms-auto badge bg-primary bg-opacity-10 text-primary">Standard</span>
                    </button>
                    <button class="btn btn-outline-danger text-start d-flex align-items-center" onclick="quickGenerate('tax')">
                        <i class="bi bi-receipt me-2"></i> BIR 2316 / Withholding Tax
                        <span class="ms-auto badge bg-danger bg-opacity-10 text-danger">Quarterly</span>
                    </button>
                    <button class="btn btn-outline-success text-start d-flex align-items-center" onclick="quickGenerate('sss')">
                        <i class="bi bi-building me-2"></i> SSS Contribution Report
                        <span class="ms-auto badge bg-success bg-opacity-10 text-success">Monthly</span>
                    </button>
                    <button class="btn btn-outline-info text-start d-flex align-items-center" onclick="quickGenerate('philhealth')">
                        <i class="bi bi-heart-pulse me-2"></i> PhilHealth Report
                    </button>
                    <button class="btn btn-outline-secondary text-start d-flex align-items-center" onclick="quickGenerate('pagibig')">
                        <i class="bi bi-house me-2"></i> Pag-IBIG Report
                    </button>
                    <button class="btn btn-outline-warning text-start d-flex align-items-center" onclick="quickGenerate('bonus')">
                        <i class="bi bi-gift me-2"></i> 13th Month & Bonuses
                        <span class="ms-auto badge bg-warning bg-opacity-10 text-warning">Year-end</span>
                    </button>
                </div>
            </div>
            
            <!-- RECENT ACTIVITY / STATS -->
            <div class="card-soft p-3 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-pie-chart me-2"></i>Report Distribution
                    </h6>
                    <span class="badge bg-light text-dark" id="totalReportsBadge">0 total</span>
                </div>
                <div class="chart-container" style="height: 200px;">
                    <canvas id="reportDistributionChart"></canvas>
                </div>
                <div class="mt-3 small">
                    <div class="d-flex justify-content-between mb-1">
                        <span><span class="badge bg-primary rounded-circle p-1 me-1"></span> Monthly</span>
                        <span class="fw-bold" id="distMonthly">0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span><span class="badge bg-danger rounded-circle p-1 me-1"></span> Tax</span>
                        <span class="fw-bold" id="distTax">0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span><span class="badge bg-success rounded-circle p-1 me-1"></span> Government</span>
                        <span class="fw-bold" id="distGov">0</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span><span class="badge bg-info rounded-circle p-1 me-1"></span> Analytics</span>
                        <span class="fw-bold" id="distAnalytics">0</span>
                    </div>
                </div>
            </div>
            
            <!-- PENDING APPROVALS CARD -->
            <div class="card-soft p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-clock-history me-2"></i>Pending Approvals
                    </h6>
                    <span class="badge bg-warning rounded-pill" id="pendingApprovalsBadge">0</span>
                </div>
                <div id="pendingApprovalsList">
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-check-circle fs-4"></i>
                        <p class="small mb-0">No pending approvals</p>
                    </div>
                </div>
                <div class="mt-2 text-center">
                    <a href="payroll-approval.php" class="btn btn-sm btn-outline-primary w-100">
                        Go to Approval Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- CHARTS SECTION -->
    <div class="row g-4 mt-2">
        <div class="col-md-7">
            <div class="card-soft p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-graph-up me-2"></i>Payroll Trend
                    </h6>
                    <select class="form-select form-select-sm" style="width: 100px;" id="trendYear" onchange="loadChartData()">
                        <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                        <option value="<?php echo date('Y')-1; ?>"><?php echo date('Y')-1; ?></option>
                    </select>
                </div>
                <div class="chart-container">
                    <canvas id="payrollTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card-soft p-3">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-building me-2"></i>Department Distribution
                </h6>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- MODAL: GENERATE REPORT -->
<div class="modal fade" id="generateReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>Generate New Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="generateReportForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Report Type</label>
                            <select class="form-select" id="reportType" required>
                                <option value="">Select report type...</option>
                                <option value="Monthly Payroll">Monthly Payroll Summary</option>
                                <option value="Tax Report">Tax Withholding Report (BIR 2316)</option>
                                <option value="Government">Government Contributions Summary</option>
                                <option value="Analytics">Payroll Analytics</option>
                                <option value="Bonus">13th Month & Bonus Report</option>
                                <option value="Custom">Custom Report</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Period Start</label>
                            <input type="date" class="form-control" id="periodStart" value="<?php echo date('Y-m-01'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Period End</label>
                            <input type="date" class="form-control" id="periodEnd" value="<?php echo date('Y-m-t'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department (Optional)</label>
                            <select class="form-select" id="department">
                                <option value="all">All Departments</option>
                                <option value="Optometry">Optometry</option>
                                <option value="HR">Human Resources</option>
                                <option value="Finance">Finance</option>
                                <option value="IT">IT</option>
                                <option value="Admin">Administration</option>
                                <option value="Sales">Sales</option>
                                <option value="Inventory">Inventory</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Output Format</label>
                            <select class="form-select" id="fileFormat">
                                <option value="PDF">PDF Document</option>
                                <option value="Excel">Excel Spreadsheet</option>
                                <option value="CSV">CSV File</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-semibold">Additional Options</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeCharts" checked>
                                        <label class="form-check-label" for="includeCharts">
                                            Include charts and graphs
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeSummary">
                                        <label class="form-check-label" for="includeSummary">
                                            Include executive summary
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="sendEmail">
                                        <label class="form-check-label" for="sendEmail">
                                            Send to email after generation
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="saveTemplate">
                                        <label class="form-check-label" for="saveTemplate">
                                            Save as template
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12" id="emailField" style="display: none;">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" class="form-control" id="reportEmail" placeholder="recipient@example.com">
                        </div>
                    </div>
                </form>
                
                <!-- TEMPLATE NAME FIELD (hidden by default) -->
                <div id="templateNameField" class="mt-3" style="display: none;">
                    <label class="form-label fw-semibold">Template Name</label>
                    <input type="text" class="form-control" id="templateName" placeholder="e.g., Monthly Payroll Report">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitGenerateReport()">
                    <i class="bi bi-file-earmark-text me-1"></i> Generate Report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: REPORT DETAILS -->
<div class="modal fade" id="reportDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="reportDetailsModalLabel">
                    <i class="bi bi-file-earmark-text me-2"></i>Report Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Loading Spinner -->
                <div id="reportDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading report details...</p>
                </div>
                
                <!-- Content -->
                <div id="reportDetailsContent" style="display: none;">
                    <!-- Header with Employee Info -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-2">
                                <h4 class="mb-0" id="detailEmployeeName"></h4>
                                <span class="badge ms-2" id="detailStatusBadge"></span>
                            </div>
                            <div class="text-muted">
                                <div class="d-flex flex-wrap gap-3">
                                    <span><i class="bi bi-person-badge me-1"></i>ID: <span id="detailEmployeeNo"></span></span>
                                    <span><i class="bi bi-building me-1"></i>Dept: <span id="detailDepartment"></span></span>
                                    <span><i class="bi bi-calendar me-1"></i>Period: <span id="detailPeriod"></span></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="card border-0 bg-light p-3">
                                <div class="text-muted small">Net Pay</div>
                                <h3 class="text-success mb-0" id="detailNetPay">₱0.00</h3>
                                <div class="small text-muted">Gross: <span id="detailGrossPay">₱0.00</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabs Navigation -->
                    <ul class="nav nav-tabs mb-4" id="reportDetailsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings" type="button">
                                <i class="bi bi-cash-coin me-1"></i>Earnings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions" type="button">
                                <i class="bi bi-cash-stack me-1"></i>Deductions
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank" type="button">
                                <i class="bi bi-bank me-1"></i>Bank Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button">
                                <i class="bi bi-clock-history me-1"></i>Timeline
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tabs Content -->
                    <div class="tab-content">
                        <!-- Earnings Tab -->
                        <div class="tab-pane fade show active" id="earnings" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td>Basic Salary</td>
                                            <td class="text-end fw-bold" id="earningsBasic">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Overtime Pay</td>
                                            <td class="text-end text-success" id="earningsOvertime">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Holiday Pay</td>
                                            <td class="text-end text-success" id="earningsHoliday">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Night Differential</td>
                                            <td class="text-end text-success" id="earningsNightDiff">₱0.00</td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td>Allowances</td>
                                            <td class="text-end text-success" id="earningsAllowances">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Bonuses</td>
                                            <td class="text-end text-success" id="earningsBonuses">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>13th Month Pay</td>
                                            <td class="text-end text-success" id="earnings13th">₱0.00</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="mt-3 pt-2 border-top">
                                <div class="d-flex justify-content-between">
                                    <h6 class="fw-bold">Total Earnings</h6>
                                    <h5 class="text-success fw-bold" id="earningsTotal">₱0.00</h5>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Deductions Tab -->
                        <div class="tab-pane fade" id="deductions" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td>SSS Contribution</td>
                                            <td class="text-end text-danger" id="deductionsSSS">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>PhilHealth</td>
                                            <td class="text-end text-danger" id="deductionsPhilhealth">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Pag-IBIG</td>
                                            <td class="text-end text-danger" id="deductionsPagibig">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Withholding Tax</td>
                                            <td class="text-end text-danger" id="deductionsTax">₱0.00</td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-borderless">
                                        <tr>
                                            <td>Absences</td>
                                            <td class="text-end text-danger" id="deductionsAbsences">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Tardiness</td>
                                            <td class="text-end text-danger" id="deductionsTardiness">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Leave Without Pay</td>
                                            <td class="text-end text-danger" id="deductionsLWOP">₱0.00</td>
                                        </tr>
                                        <tr>
                                            <td>Other Deductions</td>
                                            <td class="text-end text-danger" id="deductionsOther">₱0.00</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="mt-3 pt-2 border-top">
                                <div class="d-flex justify-content-between">
                                    <h6 class="fw-bold">Total Deductions</h6>
                                    <h5 class="text-danger fw-bold" id="deductionsTotal">₱0.00</h5>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Details Tab -->
                        <div class="tab-pane fade" id="bank" role="tabpanel">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <h6 class="fw-bold mb-3">Payment Information</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="text-muted small">Payment Method</label>
                                            <div class="fw-bold" id="bankPaymentMethod">Not specified</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="text-muted small">Payment Status</label>
                                            <div><span class="badge" id="bankPaymentStatus">Not specified</span></div>
                                        </div>
                                    </div>
                                    
                                    <h6 class="fw-bold mt-4 mb-3">Bank Account Details</h6>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="text-muted small">Bank Name</label>
                                            <div class="fw-bold" id="bankBankName">Not specified</div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="text-muted small">Account Holder</label>
                                            <div class="fw-bold" id="bankAccountHolder">Not specified</div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="text-muted small">Account Number</label>
                                            <div class="fw-bold" id="bankAccountNumber">Not specified</div>
                                        </div>
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
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Error Message -->
                <div id="reportDetailsError" class="text-center py-5" style="display: none;">
                    <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                    <p class="text-danger mt-2" id="reportErrorMessage"></p>
                    <button class="btn btn-outline-primary btn-sm" onclick="loadReportDetails(currentReportId)">
                        <i class="bi bi-arrow-clockwise"></i> Retry
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" onclick="printReport(currentReportId)">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button type="button" class="btn btn-success" onclick="downloadReport(currentReportId)">
                    <i class="bi bi-download me-1"></i> Download
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="shareReportModal(currentReportId)">
                    <i class="bi bi-share me-1"></i> Share
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: SHARE REPORT -->
<div class="modal fade" id="shareReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-share me-2"></i>Share Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="shareReportForm">
                    <input type="hidden" id="shareReportId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Recipient Email</label>
                        <input type="email" class="form-control" id="shareEmail" placeholder="colleague@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message (Optional)</label>
                        <textarea class="form-control" id="shareMessage" rows="3" placeholder="Add a personal message..."></textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="sendCopyToMe" checked>
                        <label class="form-check-label" for="sendCopyToMe">
                            Send a copy to myself
                        </label>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        The recipient will receive a secure link to download the report.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitShareReport()">
                    <i class="bi bi-send me-1"></i> Send Report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: CONFIRM DELETE -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this report?</p>
                <p class="small text-muted mb-0" id="deleteReportName"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDeleteReport()">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: EXPORT ALL -->
<div class="modal fade" id="exportAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-excel me-2"></i>Export All Reports
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Period</label>
                    <select class="form-select" id="exportPeriod">
                        <option value="<?php echo date('Y-m'); ?>">Current Month (<?php echo date('F Y'); ?>)</option>
                        <option value="<?php echo date('Y-m', strtotime('-1 month')); ?>">Last Month</option>
                        <option value="<?php echo date('Y'); ?>">Year to Date (<?php echo date('Y'); ?>)</option>
                        <option value="all">All Reports</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Format</label>
                    <select class="form-select" id="exportFormat">
                        <option value="Excel">Microsoft Excel (.xlsx)</option>
                        <option value="PDF">PDF Documents</option>
                        <option value="CSV">CSV Files</option>
                    </select>
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle me-2"></i>
                    This will export multiple reports as a ZIP file.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmExportAll()">
                    <i class="bi bi-download me-1"></i> Export Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="assets/js/payroll-reports.js"></script>
</body>
</html>