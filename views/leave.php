<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        .card-soft {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-approved { background-color: #d1fae5; color: #065f46; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #e5e7eb; color: #374151; }
        .days-cell { font-weight: bold; color: #3b82f6; }
        .employee-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .approval-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .btn-approve { background-color: #10b981; color: white; }
        .btn-reject { background-color: #ef4444; color: white; }
        .attachment-thumb {
            width: 100px;
            height: 100px;
            object-fit: cover;
            cursor: pointer;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
        }
        .attachment-thumb:hover {
            border-color: #3b82f6;
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Leave Management System</h2>
            <p class="text-muted mb-0">Manage employee leave requests and approvals</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary px-3 d-flex align-items-center gap-2" onclick="manageLeaveTypes()">
                <i class="bi bi-gear"></i> Manage Leave Types
            </button>
            <button class="btn btn-outline-primary px-3 d-flex align-items-center gap-2" onclick="manageLeaveBalances()">
                <i class="bi bi-calculator"></i> Manage Leave Balances
            </button>
        </div>
    </div>
    
    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Leaves</small>
                        <h3 id="totalLeaves" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-calendar-check fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">All leave requests</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Pending</small>
                        <h3 id="pendingLeaves" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock-history fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Awaiting approval</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Approved</small>
                        <h3 id="approvedLeaves" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Approved requests</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Days</small>
                        <h3 id="totalDays" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-calendar-day fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Total leave days</small>
            </div>
        </div>
    </div>
    
    <!-- FILTERS -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" id="filterStatus" onchange="filterLeaves()">
                    <option value="">All Status</option>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Leave Type</label>
                <select class="form-select" id="filterType" onchange="filterLeaves()">
                    <option value="">All Types</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Employee</label>
                <select class="form-select" id="filterEmployee" onchange="filterLeaves()">
                    <option value="">All Employees</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date Range</label>
                <input type="text" class="form-control" id="filterDateRange" placeholder="Select date range">
            </div>
        </div>
    </div>
    
    <!-- DATA TABLE -->
    <div class="card-soft overflow-hidden mb-4">
        <div class="table-responsive">
            <table id="leavesTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Date Range</th>
                        <th>Days</th>
                        <th>Pay Type</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody id="leavesTableBody">
                    <!-- Leaves will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL - VIEW LEAVE DETAILS -->
<!-- MODAL - VIEW LEAVE DETAILS -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Leave Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- ... LAHAT NG IYONG EXISTING CODE ... -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div id="detailEmployeeAvatar" class="employee-avatar"></div>
                        <div>
                            <h6 id="detailEmployeeName" class="fw-bold mb-0">Employee Name</h6>
                            <small id="detailEmployeeNo" class="text-muted">Employee No</small>
                        </div>
                    </div>
                    
                    <div id="detailStatusContainer" class="mb-3">
                        <span id="detailStatusBadge" class="status-badge"></span>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Leave Type</label>
                        <div id="detailLeaveType" class="fw-bold">-</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Duration</label>
                        <div id="detailDays" class="fw-bold days-cell">-</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Start Date</label>
                        <div id="detailStartDate" class="fw-bold">-</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">End Date</label>
                        <div id="detailEndDate" class="fw-bold">-</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Pay Type</label>
                        <div id="detailPayType" class="fw-bold">-</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">Deducted Amount</label>
                        <div id="detailDeductedAmount" class="fw-bold">-</div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-muted">Reason</label>
                    <div id="detailReason" class="p-3 bg-light rounded">-</div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-muted">Attachment</label>
                    <div id="detailAttachment">
                        <img src="" alt="Attachment" class="attachment-thumb" onclick="viewAttachment(this.src)" style="display: none;">
                        <span id="noAttachment" class="text-muted">No attachment</span>
                    </div>
                </div>
                
                <div id="emergencyInfo" style="display: none;">
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">Emergency Contact</label>
                            <div id="detailEmergencyContact" class="fw-bold">-</div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">Contact Number</label>
                            <div id="detailContactNumber" class="fw-bold">-</div>
                        </div>
                    </div>
                </div>
                
                <div id="approvalInfo" style="display: none;">
                    <hr>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">Approved By</label>
                            <div id="detailApprovedBy" class="fw-bold">-</div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label text-muted">Approved At</label>
                            <div id="detailApprovedAt" class="fw-bold">-</div>
                        </div>
                    </div>
                </div>
                
                <div id="rejectionInfo" style="display: none;">
                    <hr>
                    <div class="row">
                        <div class="col-md-12 mb-2">
                            <label class="form-label text-muted">Rejection Notes</label>
                            <div id="detailRejectionNotes" class="fw-bold text-danger">-</div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <label class="form-label text-muted">Applied On</label>
                    <div id="detailCreatedAt" class="text-muted">-</div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="approval-buttons" id="approvalButtons" style="display: none;">
                    <button type="button" class="btn btn-approve px-4" onclick="approveLeave()">
                        <i class="bi bi-check-lg"></i> Approve
                    </button>
                    <button type="button" class="btn btn-reject px-4" onclick="showRejectModal()">
                        <i class="bi bi-x-lg"></i> Reject
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - REJECT LEAVE -->
<div class="modal fade" id="rejectLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Reject Leave Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectLeaveId">
                <div class="mb-3">
                    <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="rejectReason" rows="4" 
                              placeholder="Please provide the reason for rejecting this leave request..."
                              required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="rejectLeave()">Confirm Rejection</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - VIEW ATTACHMENT -->
<div class="modal fade" id="viewAttachmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Attachment Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="attachmentPreview" src="" alt="Attachment Preview" class="img-fluid" style="max-height: 70vh;">
            </div>
            <div class="modal-footer">
                <a id="downloadAttachment" href="#" class="btn btn-primary" download>
                    <i class="bi bi-download"></i> Download
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - MANAGE LEAVE BALANCES -->
<div class="modal fade" id="manageLeaveBalancesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Manage Leave Balances</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select class="form-select" id="balanceYear" onchange="loadLeaveBalances()">
                            <!-- Years will be populated dynamically -->
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Leave Type</label>
                        <select class="form-select" id="balanceLeaveType" onchange="loadLeaveBalances()">
                            <option value="">All Types</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employee</label>
                        <select class="form-select" id="balanceEmployee" onchange="loadLeaveBalances()">
                            <option value="">All Employees</option>
                            <option value="all">All Employees (Bulk)</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" onclick="showAddLeaveBalanceModal()">
                            <i class="bi bi-plus"></i> Add Balance
                        </button>
                    </div>
                </div>
                
                <!-- BULK ACTION SECTION (for "All Employees" option) -->
                <div id="bulkActionSection" style="display: none;" class="mb-3 p-3 bg-light rounded">
                    <h6 class="mb-3">Bulk Action - All Employees</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Days to Add/Set</label>
                            <input type="number" class="form-control" id="bulkDays" min="0" step="0.5" value="15">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Action Type</label>
                            <select class="form-select" id="bulkActionType">
                                <option value="set">Set to this value</option>
                                <option value="add">Add to existing</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-warning w-100" onclick="applyBulkAction()">
                                <i class="bi bi-arrow-repeat"></i> Apply to All
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- DATA TABLE -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Year</th>
                                <th>Total Entitled</th>
                                <th>Used</th>
                                <th>Balance</th>
                                <th>Carried Over</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="leaveBalancesTableBody">
                            <!-- Leave balances will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - ADD/EDIT LEAVE BALANCE -->
<div class="modal fade" id="leaveBalanceModal" tabindex="-1" aria-labelledby="leaveBalanceModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="leaveBalanceModalTitle">Add Leave Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="leaveBalanceForm">
                <div class="modal-body">
                    <input type="hidden" id="balance_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Apply To</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="apply_to" id="apply_all" value="all" checked>
                            <label class="form-check-label" for="apply_all">
                                All Active Employees
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="apply_to" id="apply_specific" value="specific">
                            <label class="form-check-label" for="apply_specific">
                                Specific Employee
                            </label>
                        </div>
                    </div>
                    
                    <div id="specificEmployeeSection" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="balance_employee_id" aria-label="Select employee">
                                <option value="">Select Employee</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="balance_leave_type_id" required aria-label="Select leave type">
                            <option value="">Select Leave Type</option>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="balance_year" class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="balance_year" 
                                   min="2000" max="2100" value="<?php echo date('Y'); ?>" required
                                   aria-label="Year for leave balance">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="total_entitled" class="form-label">Total Entitled Days <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="total_entitled" min="0" step="0.5" value="15" required
                                   aria-label="Total entitled days">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="used" class="form-label">Used Days</label>
                            <input type="number" class="form-control" id="used" min="0" step="0.5" value="0"
                                   aria-label="Used days">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="carried_over" class="form-label">Carried Over Days</label>
                            <input type="number" class="form-control" id="carried_over" min="0" step="0.5" value="0"
                                   aria-label="Carried over days">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="balance_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="balance_notes" rows="2" aria-label="Additional notes"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Balance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL - EDIT SINGLE BALANCE -->
<div class="modal fade" id="editBalanceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Leave Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editBalanceForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_balance_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Employee</label>
                        <div id="editEmployeeName" class="fw-bold"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Leave Type</label>
                        <div id="editLeaveTypeName" class="fw-bold"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Year</label>
                        <div id="editYear" class="fw-bold"></div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Entitled Days <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_total_entitled" min="0" step="0.5" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Used Days</label>
                            <input type="number" class="form-control" id="edit_used" min="0" step="0.5">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Carried Over Days</label>
                            <input type="number" class="form-control" id="edit_carried_over" min="0" step="0.5">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Balance</label>
                            <input type="number" class="form-control" id="edit_balance" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Update Notes</label>
                        <textarea class="form-control" id="edit_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Balance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL - ADJUST BALANCE -->
<div class="modal fade" id="adjustBalanceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Adjust Leave Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="adjustBalanceForm">
                <div class="modal-body">
                    <input type="hidden" id="adjust_employee_id">
                    <input type="hidden" id="adjust_leave_type_id">
                    <input type="hidden" id="adjust_year">
                    
                    <div class="mb-3">
                        <label class="form-label">Employee</label>
                        <div id="adjustEmployeeName" class="fw-bold"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Leave Type</label>
                        <div id="adjustLeaveType" class="fw-bold"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Year</label>
                        <div id="adjustYear" class="fw-bold"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" id="adjustment_type" required>
                            <option value="add">Add Days (Positive)</option>
                            <option value="deduct">Deduct Days (Negative)</option>
                            <option value="set">Set Specific Value</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Amount (Days)</label>
                        <input type="number" class="form-control" id="adjustment_amount" min="0" step="0.5" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Adjustment</label>
                        <textarea class="form-control" id="adjustment_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL - MANAGE LEAVE TYPES -->
<div class="modal fade" id="manageLeaveTypesModal" tabindex="-1" aria-labelledby="manageLeaveTypesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="manageLeaveTypesTitle">Manage Leave Types</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i> 
                    Leave types define the different types of leaves available to employees.
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6>Leave Types Configuration</h6>
                    <button class="btn btn-sm btn-primary" onclick="showAddLeaveTypeModal()">
                        <i class="bi bi-plus"></i> Add New Type
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Type Name</th>
                                <th>Code</th>
                                <th>Max Days/Year</th>
                                <th>With Pay</th>
                                <th>Requires Attachment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="leaveTypesTableBody">
                            <!-- Leave types will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL - ADD/EDIT LEAVE TYPE -->
<div class="modal fade" id="leaveTypeModal" tabindex="-1" aria-labelledby="leaveTypeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="leaveTypeModalTitle">Add Leave Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="leaveTypeForm">
                <div class="modal-body">
                    <input type="hidden" id="leave_type_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Type Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="type_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code" required>
                            <small class="text-muted">e.g., VL, SL, ML</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Days per Year</label>
                            <input type="number" class="form-control" id="max_days_per_year" min="0" step="0.5" value="15">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Carry Over Days (Max)</label>
                            <input type="number" class="form-control" id="max_carry_over" min="0" step="0.5" value="5">
                            <small class="text-muted">Max days that can be carried to next year</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="with_pay" checked>
                                <label class="form-check-label" for="with_pay">
                                    With Pay
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="requires_attachment">
                                <label class="form-check-label" for="requires_attachment">
                                    Requires Attachment
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
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
<!-- Flatpickr for date range -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="assets/js/leaves.js"></script>

</body>
</html>