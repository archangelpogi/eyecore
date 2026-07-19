<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Processing</title>
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
        .status-draft { background-color: #fef3c7; color: #92400e; }
        .status-for_approval { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #dbeafe; color: #1e40af; }
        .status-released { background-color: #d1fae5; color: #065f46; }
        .status-cancelled { background-color: #f3f4f6; color: #6b7280; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .salary-breakdown {
            border-left: 3px solid #3b82f6;
            background: #f8fafc;
        }
        .deduction-breakdown {
            border-left: 3px solid #ef4444;
            background: #fef2f2;
        }
        .approval-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
        }
        /* Complete status colors - professional version */
.status-draft {
    background-color: #6c757d !important;  /* Gray */
    color: white;
}

.status-for_approval {
    background-color: #ffc107 !important;  /* Amber/Yellow */
    color: #000;
}

.status-ready {
    background-color: #20c997 !important;  /* Teal - fresh and ready */
    color: white;
}

.status-approved {
    background-color: #28a745 !important;  /* Green */
    color: white;
}

.status-released {
    background-color: #007bff !important;  /* Blue */
    color: white;
}

.status-rejected {
    background-color: #dc3545 !important;  /* Red */
    color: white;
}

.status-cancelled {
    background-color: #6c757d !important;  /* Gray */
    color: white;
}

/* Optional: Add subtle hover effects */
.status-ready:hover,
.status-approved:hover,
.status-released:hover {
    filter: brightness(95%);
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

.status-draft:hover,
.status-cancelled:hover {
    filter: brightness(90%);
}
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Payroll Processing</h2>
            <p class="text-muted mb-0">Manage employee payroll and compensation</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary px-3 d-flex align-items-center gap-2"
                    onclick="openGeneratePayrollModal()">
                <i class="bi bi-plus-circle"></i> Generate Payroll
            </button>
        </div>
    </div>
    
    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Payrolls</small>
                        <h3 id="totalPayrolls" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-cash-stack fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">All time payroll records</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">For Approval</small>
                        <h3 id="pendingApprovals" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Pending your approval</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">This Month</small>
                        <h3 id="monthlyTotal" class="fw-bold mt-1 mb-2">₱0</h3>
                    </div>
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-calendar-month fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Total net pay for <span id="currentMonth"></span></small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Released</small>
                        <h3 id="releasedPayrolls" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-check-circle fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Released payrolls this month</small>
            </div>
        </div>
    </div>
    
    <!-- FILTERS -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Employee</label>
                <select class="form-select" id="filterEmployee">
                    <option value="">All Employees</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Payroll Period</label>
                <input type="text" class="form-control" id="filterPeriod" placeholder="YYYY-MM">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="Draft">Draft</option>
                    <option value="For Approval">For Approval</option>
                    <option value="Approved">Approved</option>
                    <option value="Released">Released</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary w-50" onclick="loadPayrolls()">Filter</button>
                    <button class="btn btn-outline-secondary w-50" onclick="resetFilters()">Reset</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- DATA TABLE -->
    <div class="card-soft overflow-hidden mb-4">
        <div class="table-responsive">
            <table id="payrollTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Payroll Period</th>
                        <th>Employee</th>
                        <th>Basic Salary</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Status</th>
                        <th>Approval</th>
                        <th>Generated Date</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody id="payrollTableBody">
                    <!-- Payrolls will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL - GENERATE PAYROLL (BULK) - UPDATED WITH ATTENDANCE & ADJUSTMENTS -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"> <!-- Added modal-dialog-scrollable -->
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Generate Payroll for All Active Employees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="generatePayrollForm" onsubmit="generateBulkPayroll(event)">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;"> 
                    <!-- PERIOD INFORMATION -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payroll Period <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="payroll_period" placeholder="YYYY-MM" required>
                            <small class="text-muted">e.g., 2024-01</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cut-off Period <span class="text-danger">*</span></label>
                            <select class="form-select" id="cutoff_type">
                                <option value="first_half">First Half (1-15)</option>
                                <option value="second_half">Second Half (16-30/31)</option>
                                <option value="whole_month">Whole Month</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-3" id="custom_dates" style="display: none;">
                        <div class="col-md-6">
                            <label class="form-label">Period Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="period_start">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Period End <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="period_end">
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    
                    <!-- ATTENDANCE VALIDATION SETTINGS -->
                    <div class="card mb-3 border-info">
                        <div class="card-header bg-info bg-opacity-10 text-info fw-bold">
                            <i class="bi bi-calendar-check me-2"></i>Attendance Settings
                        </div>
                        <div class="card-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="validate_attendance" checked>
                                <label class="form-check-label fw-bold" for="validate_attendance">
                                    Skip employees with no attendance records
                                </label>
                                <div class="text-muted small ms-4">
                                    Employees without any attendance in the period will be skipped
                                </div>
                            </div>
                            
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="prorate_salary">
                                <label class="form-check-label fw-bold" for="prorate_salary">
                                    Prorate salary based on actual days worked
                                </label>
                                <div class="text-muted small ms-4">
                                    Calculate salary based on actual attendance days (not full month)
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- MANUAL ADJUSTMENTS (BONUS/DEDUCTIONS) -->
<!-- MANUAL ADJUSTMENTS (BONUS/DEDUCTIONS) -->
<div class="card mb-3 border-success">
    <div class="card-header bg-success bg-opacity-10 text-success fw-bold">
        <i class="bi bi-plus-circle me-2"></i>Manual Adjustments (Apply to ALL Employees)
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Bonus Amount (₱)</label>
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control" id="manual_bonus" min="0" step="0.01" value="0">
                </div>
                <small class="text-muted">e.g., Performance bonus</small>
            </div>
            
            <!-- ===== NEW: Holiday Pay ===== -->
            <div class="col-md-4">
                <label class="form-label">Holiday Pay (₱)</label>
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control" id="manual_holiday_pay" min="0" step="0.01" value="0">
                </div>
                <small class="text-muted">e.g., Regular/Special holiday</small>
            </div>
            
            <!-- ===== NEW: Allowances ===== -->
            <div class="col-md-4">
                <label class="form-label">Allowances (₱)</label>
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control" id="manual_allowances" min="0" step="0.01" value="0">
                </div>
                <small class="text-muted">e.g., Rice, transportation</small>
            </div>
        </div>
        
        <div class="row g-3 mt-2">
            <div class="col-md-6">
                <label class="form-label">Additional Deduction (₱)</label>
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control" id="manual_deduction" min="0" step="0.01" value="0">
                </div>
                <small class="text-muted">e.g., Uniform, loan, penalties</small>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Remarks/Reason for Adjustments</label>
                <input type="text" class="form-control" id="adjustment_remarks" 
                       placeholder="e.g., Christmas bonus, uniform deduction">
            </div>
        </div>
    </div>
</div>
                    
<!-- CONTRIBUTION SETTINGS -->
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning bg-opacity-10 text-warning fw-bold">
        <i class="bi bi-gear me-2"></i>Contribution Settings
    </div>
    <div class="card-body">
        <!-- ===== NEW: Manual Override Option ===== -->
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="use_manual_contributions">
            <label class="form-check-label fw-bold" for="use_manual_contributions">
                Use Manual Contribution Values (Override system calculation)
            </label>
        </div>
        
        <div id="manual_contributions_fields" style="display: none;">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Manual SSS (₱)</label>
                    <input type="number" class="form-control" id="manual_sss" step="0.01" value="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Manual PhilHealth (₱)</label>
                    <input type="number" class="form-control" id="manual_philhealth" step="0.01" value="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Manual Pag-IBIG (₱)</label>
                    <input type="number" class="form-control" id="manual_pagibig" step="0.01" value="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Manual Tax (₱)</label>
                    <input type="number" class="form-control" id="manual_tax" step="0.01" value="0">
                </div>
            </div>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                When checked, system will use these manual values instead of calculated ones.
            </div>
        </div>
        
        <hr class="my-3">
        
        <div class="row">
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="include_sss" checked>
                    <label class="form-check-label" for="include_sss">Include SSS Contribution</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="include_philhealth" checked>
                    <label class="form-check-label" for="include_philhealth">Include PhilHealth</label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="include_pagibig" checked>
                    <label class="form-check-label" for="include_pagibig">Include Pag-IBIG</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="include_tax" checked>
                    <label class="form-check-label" for="include_tax">Include Withholding Tax</label>
                </div>
            </div>
        </div>
    </div>
</div>
                    <hr class="my-3">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Note:</strong> This will generate payroll for ALL ACTIVE EMPLOYEES in your clinic.
                        <br><small class="text-muted">You can edit individual payrolls after generation.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-calculator me-1"></i> Generate Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL - VIEW PAYROLL DETAILS -->
<div class="modal fade" id="viewPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Payroll Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <!-- APPROVAL STATUS BAR -->
                <div class="approval-status mb-4" id="approvalStatusBar" style="display: none;">
                    <h6 class="fw-bold mb-2">Approval Status</h6>
                    <div class="d-flex align-items-center gap-2 mb-3" id="approvalProgress">
                        <!-- Approval steps will be dynamically inserted -->
                    </div>
                    <div id="approvalHistory" class="small">
                        <!-- Approval history will be inserted here -->
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h6 id="detailEmployee" class="fw-bold"></h6>
                        <p class="mb-1" id="detailPeriod"></p>
                        <span id="detailStatus" class="badge"></span>
                    </div>
                    <div class="col-md-4 text-end">
                        <h3 id="detailNetPay" class="fw-bold text-success"></h3>
                        <small class="text-muted">Net Pay</small>
                    </div>
                </div>
                
                <!-- ===== NEW: BANK DETAILS SECTION ===== -->
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
                                        <span class="fw-bold" id="detailBankName">-</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-person-badge text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Account Holder</small>
                                        <span class="fw-bold" id="detailBankHolder">-</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-credit-card text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Account Number</small>
                                        <span class="fw-bold" id="detailBankAccount">-</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-check-circle text-secondary me-2"></i>
                                    <div>
                                        <small class="text-muted d-block">Bank Status</small>
                                        <span id="detailBankStatus" class="badge bg-success">Complete</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

<!-- Attendance Summary -->
<div class="card mb-4 border-info">
    <div class="card-header bg-info bg-opacity-10 text-info fw-bold py-2">
        <i class="bi bi-calendar-check me-2"></i>Attendance Summary
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="text-center p-2 border-end">
                    <span class="text-muted d-block">Days Present</span>
                    <span class="fw-bold fs-4 text-success" id="detailPresentDays">0</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-2 border-end">
                    <span class="text-muted d-block">Late Days</span>
                    <span class="fw-bold fs-4 text-warning" id="detailLateDays">0</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-2">
                    <span class="text-muted d-block">Absent Days</span>
                    <span class="fw-bold fs-4 text-danger" id="detailAbsentDays">0</span>
                </div>
            </div>
        </div>
        
        <!-- Detailed Attendance List -->
        <div class="mt-3" id="attendanceListSection" style="display: none;">
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
                    <tbody id="attendanceRecordsList">
                        <!-- Attendance records will be inserted here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card salary-breakdown p-3">
                            <h6 class="fw-bold mb-3">Earnings</h6>
                            <div class="row mb-1">
                                <div class="col">Basic Salary</div>
                                <div class="col text-end" id="detailBasicSalary"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Overtime</div>
                                <div class="col text-end" id="detailOvertime"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Holiday Pay</div>
                                <div class="col text-end" id="detailHolidayPay"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Allowances</div>
                                <div class="col text-end" id="detailAllowances"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Bonuses</div>
                                <div class="col text-end" id="detailBonuses"></div>
                            </div>
                            <hr>
                            <div class="row fw-bold">
                                <div class="col">Gross Pay</div>
                                <div class="col text-end" id="detailGrossPay"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card deduction-breakdown p-3">
                            <h6 class="fw-bold mb-3">Deductions</h6>
                            <div class="row mb-1">
                                <div class="col">Tardiness</div>
                                <div class="col text-end" id="detailTardiness"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Absences</div>
                                <div class="col text-end" id="detailAbsences"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">SSS</div>
                                <div class="col text-end" id="detailSSS"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">PhilHealth</div>
                                <div class="col text-end" id="detailPhilhealth"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Pag-IBIG</div>
                                <div class="col text-end" id="detailPagibig"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Tax (WHT)</div>
                                <div class="col text-end" id="detailTax"></div>
                            </div>
                            <div class="row mb-1">
                                <div class="col">Other Deductions</div>
                                <div class="col text-end" id="detailOtherDeductions"></div>
                            </div>
                            <hr>
                            <div class="row fw-bold">
                                <div class="col">Total Deductions</div>
                                <div class="col text-end" id="detailTotalDeductions"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ADJUSTMENTS SECTION -->
                <div class="mt-4" id="adjustmentsSection" style="display: none;">
                    <h6 class="fw-bold mb-2">Payroll Adjustments</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Item</th>
                                    <th>Amount</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody id="adjustmentsList">
                                <!-- Adjustments will be inserted here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- OVERTIME SECTION -->
                <div class="mt-4" id="overtimeSection" style="display: none;">
                    <h6 class="fw-bold mb-2">Overtime Records</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Hours</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody id="overtimeList">
                                <!-- Overtime will be inserted here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                                <!-- ✅ NEW: LEAVE SECTION -->
                <div class="mt-4" id="leaveSection" style="display: none;">
                    <h6 class="fw-bold mb-2">Leave Records</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>

                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Type</th>
                                    <th>Days</th>
                                    <th>Approval Status</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody id="leaveList">
                                <!-- Leave records will be inserted here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- PAYROLL INFORMATION -->
                <div class="mt-4">
                    <h6 class="fw-bold mb-2">Payroll Information</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Generated By</small>
                            <span id="detailGeneratedBy"></span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Generated Date</small>
                            <span id="detailGeneratedDate"></span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Released Date</small>
                            <span id="detailReleasedDate"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                
                <!-- HR ACTIONS -->
                <button type="button" class="btn btn-info" id="submitBtn" onclick="submitForApproval()" style="display:none;">
                    <i class="bi bi-send"></i> Submit for Approval
                </button>
                <button type="button" class="btn btn-warning" id="editBtn" onclick=" openEditPayrollModal(currentPayrollId)" style="display:none;">
                    <i class="bi bi-pencil"></i> Edit Payroll
                </button>
                
                <!-- FINANCE ACTIONS -->
                <button type="button" class="btn btn-success" id="approveBtn" onclick="approvePayroll()" style="display:none;">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
                <button type="button" class="btn btn-danger" id="rejectBtn" onclick="rejectPayroll()" style="display:none;">
                    <i class="bi bi-x-circle"></i> Reject
                </button>
                
                <!-- RELEASE ACTION -->
                <button type="button" class="btn btn-primary" id="releaseBtn" onclick="releasePayroll()" style="display:none;">
                    <i class="bi bi-cash-coin"></i> Release Payroll
                </button>
                
                <!-- CANCEL ACTION -->
                <button type="button" class="btn btn-outline-danger" id="cancelBtn" onclick="cancelPayroll()" style="display:none;">
                    <i class="bi bi-trash"></i> Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - EDIT PAYROLL -->
<div class="modal fade" id="editPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Payroll</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPayrollForm">
                <div class="modal-body">
                    <h6 class="fw-bold mb-3">Manual Adjustments</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Overtime Pay (₱)</label>
                            <input type="number" class="form-control" id="edit_overtime" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Holiday Pay (₱)</label>
                            <input type="number" class="form-control" id="edit_holiday_pay" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Allowances (₱)</label>
                            <input type="number" class="form-control" id="edit_allowances" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bonuses (₱)</label>
                            <input type="number" class="form-control" id="edit_bonuses" min="0" step="0.01">
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label">Other Deductions (₱)</label>
                            <input type="number" class="form-control" id="edit_other_deductions" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Manual Adjustment (₱)</label>
                            <div class="input-group">
                                <select class="form-select" id="edit_adjustment_type" style="max-width: 120px;">
                                    <option value="addition">Add</option>
                                    <option value="deduction">Deduct</option>
                                </select>
                                <input type="number" class="form-control" id="edit_adjustment_amount" placeholder="Amount">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3" id="edit_adjustment_reason_div" style="display: none;">
                        <label class="form-label">Adjustment Reason</label>
                        <input type="text" class="form-control" id="edit_adjustment_reason" placeholder="Reason for adjustment">
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Changes will require re-approval if payroll is already submitted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="recalculatePayroll()">Recalculate</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
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
<script src="assets/js/payroll.js"></script>
<script>
// Show/hide manual contribution fields
document.getElementById('use_manual_contributions').addEventListener('change', function() {
    document.getElementById('manual_contributions_fields').style.display = 
        this.checked ? 'block' : 'none';
});
</script>
</body>
</html>