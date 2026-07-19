<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Salary History Tracker</title>
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
        .salary-increase { color: #059669; }
        .salary-decrease { color: #dc2626; }
        .salary-no-change { color: #6b7280; }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Salary History Tracker</h2>
            <p class="text-muted mb-0">Track employee salary changes and adjustments</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary px-3 d-flex align-items-center gap-2"
                    onclick="openAddAdjustmentModal()">
                <i class="bi bi-plus-circle"></i> Add Adjustment
            </button>
            <button class="btn btn-outline-primary px-3 d-flex align-items-center gap-2"
                    onclick="openBulkIncreaseModal()">
                <i class="bi bi-arrow-up-circle"></i> Bulk Increase
            </button>
        </div>
    </div>
    
    <!-- EMPLOYEE SELECTION -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Select Employee</label>
                <select class="form-select" id="employeeSelect" onchange="loadSalaryHistory()">
                    <option value="">Select an employee</option>
                </select>
            </div>
            <div class="col-md-6">
                <div class="d-flex h-100 align-items-end">
                    <div id="currentSalaryInfo" class="d-none">
                        <small class="text-muted d-block">Current Basic Salary</small>
                        <h4 class="fw-bold mb-0" id="currentSalary">₱0.00</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- STATS -->
    <div class="row g-3 mb-4 d-none" id="statsSection">
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Adjustments</small>
                        <h3 id="totalAdjustments" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-clock-history fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Salary change records</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Highest Increase</small>
                        <h3 id="highestIncrease" class="fw-bold mt-1 mb-2">₱0</h3>
                    </div>
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-arrow-up-circle fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Largest single increase</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Avg. Increase</small>
                        <h3 id="averageIncrease" class="fw-bold mt-1 mb-2">0%</h3>
                    </div>
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-graph-up fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Average percentage increase</small>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Last Adjustment</small>
                        <h3 id="lastAdjustment" class="fw-bold mt-1 mb-2">N/A</h3>
                    </div>
                    <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-calendar-event fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Most recent salary change</small>
            </div>
        </div>
    </div>
    
    <!-- SALARY CHART -->
    <div class="card-soft p-3 mb-4 d-none" id="chartSection">
        <h6 class="fw-bold mb-3">Salary Progression</h6>
        <div class="chart-container">
            <canvas id="salaryChart"></canvas>
        </div>
    </div>
    
    <!-- HISTORY TABLE -->
    <div class="card-soft overflow-hidden mb-4 d-none" id="tableSection">
        <div class="table-responsive">
            <table id="salaryHistoryTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Effective Date</th>
                        <th>Old Salary</th>
                        <th>New Salary</th>
                        <th>Change Amount</th>
                        <th>Change %</th>
                        <th>Reason</th>
                        <th>Changed By</th>
                        <th>Date Changed</th>
                        <th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody id="salaryHistoryTableBody">
                    <!-- History will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL - ADD SALARY ADJUSTMENT -->
<div class="modal fade" id="addAdjustmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Add Salary Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addAdjustmentForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_employee" required>
                                <option value="">Select Employee</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Salary</label>
                            <input type="text" class="form-control" id="current_salary_display" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Salary (₱) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="new_salary" 
                                   step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="effective_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reason for Adjustment <span class="text-danger">*</span></label>
                            <select class="form-select mb-2" id="reason_select">
                                <option value="">Select a reason or enter custom</option>
                                <option value="Performance Review">Performance Review</option>
                                <option value="Promotion">Promotion</option>
                                <option value="Annual Increase">Annual Increase</option>
                                <option value="Market Adjustment">Market Adjustment</option>
                                <option value="Cost of Living Adjustment">Cost of Living Adjustment</option>
                                <option value="Bonus">Bonus</option>
                                <option value="Demotion">Demotion</option>
                                <option value="Position Change">Position Change</option>
                                <option value="Probationary to Regular">Probationary to Regular</option>
                                <option value="Other">Other</option>
                            </select>
                            <textarea class="form-control" id="reason" rows="3" 
                                      placeholder="Enter details about this salary adjustment..." required></textarea>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-info" id="adjustmentSummary">
                                <div class="d-flex justify-content-between">
                                    <span>Change Amount:</span>
                                    <span id="changeAmount">₱0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span>Percentage Change:</span>
                                    <span id="changePercentage">0%</span>
                                </div>
                            </div>
                        </div>
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

<!-- MODAL - BULK INCREASE -->
<div class="modal fade" id="bulkIncreaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Bulk Salary Increase</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Increase Percentage (%) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="increase_percentage" 
                           step="0.01" min="0.01" max="100" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="bulk_effective_date" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <textarea class="form-control" id="bulk_reason" rows="2" 
                              placeholder="Annual salary increase..."></textarea>
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    This will increase salaries for ALL active employees. This action cannot be undone.
                </div>
                <div class="mt-3">
                    <h6>Affected Employees:</h6>
                    <div id="affectedEmployees" class="text-muted">
                        Loading employees...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="applyBulkIncrease()">Apply Increase</button>
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
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Replace the JavaScript section with this updated code -->

<script>
// ============= PERMISSION VARIABLES =============
let currentUserRole = null;
let userPermissions = {
    view: false,
    add: false,
    edit: false,
    delete: false,
    bulk: false,
    export: false
};
let hasHR = false;
let isOwner = false;
let currentUserId = null;

// ============= EXISTING VARIABLES =============
let employees = [];
let currentEmployeeId = null;
let salaryHistory = [];
let salaryChart = null;

// ============= LOAD PERMISSIONS =============
async function loadPermissions() {
    try {
        const response = await fetch('api/salary_history.php?get_permissions=true');
        const data = await response.json();
        
        if (data.success) {
            currentUserRole = data.data.role;
            userPermissions = data.data.permissions;
            hasHR = data.data.hasHR;
            isOwner = data.data.isOwner;
            currentUserId = data.data.user_id;
            
            console.log('Permissions loaded:', {
                role: currentUserRole,
                permissions: userPermissions,
                hasHR: hasHR,
                isOwner: isOwner,
                userId: currentUserId
            });
            
            // Apply permissions to UI - but don't load employees yet
            applyPermissionBasedUI();
            
            // NOW load employees after permissions are applied
            await loadEmployees();
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
        // Still try to load employees even if permissions fail
        loadEmployees();
    }
}

// ============= PERMISSION HELPER FUNCTIONS =============
function canView() { return userPermissions.view; }
function canAdd() { return userPermissions.add; }
function canEdit() { return userPermissions.edit; }
function canDelete() { return userPermissions.delete; }
function canBulk() { return userPermissions.bulk; }
function canExport() { return userPermissions.export; }

// ============= APPLY PERMISSIONS TO UI =============
function applyPermissionBasedUI() {
    // Hide/show Add Adjustment button
    const addBtn = document.querySelector('button[onclick="openAddAdjustmentModal()"]');
    if (addBtn) {
        addBtn.style.display = canAdd() ? 'inline-block' : 'none';
    }
    
    // Hide/show Bulk Increase button
    const bulkBtn = document.querySelector('button[onclick="openBulkIncreaseModal()"]');
    if (bulkBtn) {
        bulkBtn.style.display = canBulk() ? 'inline-block' : 'none';
    }
    
    // Hide/show Export button if exists
    const exportBtn = document.querySelector('button[onclick="exportSalaryHistory()"]');
    if (exportBtn) {
        exportBtn.style.display = canExport() ? 'inline-block' : 'none';
    }
    
    // If user can't view at all, show access denied message immediately
    if (!canView()) {
        const container = document.querySelector('.container-fluid');
        if (container) {
            const mainContent = document.querySelector('.card');
            if (mainContent) {
                mainContent.innerHTML = `
                    <div class="card-body text-center py-5">
                        <i class="bi bi-shield-lock text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">Access Denied</h4>
                        <p class="text-muted">You don't have permission to view salary history.</p>
                    </div>
                `;
            }
        }
        return; // Don't proceed further
    }

}



// ============= LOAD EMPLOYEES =============
async function loadEmployees() {
    try {
        // ✅ Use the correct API endpoint with proper error handling
        const response = await fetch('api/employees.php?active_only=true');
        
        if (!response.ok) {
            if (response.status === 401) {
                console.error('Unauthorized: Please login again');
                return;
            }
            if (response.status === 403) {
                console.error('Access Denied: You do not have permission to view employees');
                // Show a message in the dropdown
                const employeeSelect = document.getElementById('employeeSelect');
                if (employeeSelect) {
                    employeeSelect.innerHTML = '<option value="" disabled>No permission to view employees</option>';
                }
                return;
            }
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Check if data is array or has success property
        if (Array.isArray(data)) {
            employees = data;
        } else if (data.success === false) {
            console.error('API error:', data.error);
            employees = [];
        } else {
            employees = data;
        }
        
        populateEmployeeDropdowns();
        
        if (employees.length === 0 && canView()) {
            console.log('No employees found');
            const employeeSelect = document.getElementById('employeeSelect');
            if (employeeSelect) {
                employeeSelect.innerHTML = '<option value="" disabled>No employees found</option>';
            }
        }
        
    } catch (error) {
        console.error('Error loading employees:', error);
        employees = [];
        if (canView()) {
            Swal.fire('Error!', 'Failed to load employees: ' + error.message, 'error');
        }
    }
}
function populateEmployeeDropdowns() {
    const employeeSelect = document.getElementById('employeeSelect');
    const adjustmentSelect = document.getElementById('adjustment_employee');
    
    if (employeeSelect) {
        employeeSelect.innerHTML = '<option value="">Select an employee</option>';
    }
    
    if (adjustmentSelect) {
        adjustmentSelect.innerHTML = '<option value="">Select Employee</option>';
    }
    
    if (!employees || employees.length === 0) {
        const noEmployeesMsg = '<option value="" disabled>No employees found</option>';
        if (employeeSelect) employeeSelect.innerHTML += noEmployeesMsg;
        if (adjustmentSelect) adjustmentSelect.innerHTML += noEmployeesMsg;
        return;
    }
    
    employees.forEach(emp => {
        const fullName = emp.full_name || `${emp.first_name} ${emp.last_name}`;
        const optionText = `${emp.employee_no} - ${fullName}`;
        const option = `<option value="${emp.id}">${escapeHtml(optionText)}</option>`;
        
        if (employeeSelect) {
            employeeSelect.innerHTML += option;
        }
        if (adjustmentSelect) {
            adjustmentSelect.innerHTML += option;
        }
    });
}

// Helper function to prevent XSS
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
// ============= LOAD SALARY HISTORY =============
function loadSalaryHistory() {
    if (!canView()) {
        Swal.fire('Access Denied', 'You do not have permission to view salary history', 'error');
        return;
    }
    
    currentEmployeeId = document.getElementById('employeeSelect')?.value;
    
    if (!currentEmployeeId) {
        hideSections();
        return;
    }
    
    console.log('Loading salary history for employee:', currentEmployeeId);
    
    fetch(`api/salary_history.php?employee_id=${currentEmployeeId}`)
        .then(res => {
            console.log('Response status:', res.status);
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.success) {
                // Store the history data
                salaryHistory = data.history || [];
                
                // Update current salary display
                document.getElementById('currentSalaryInfo').classList.remove('d-none');
                document.getElementById('currentSalary').textContent = formatCurrency(data.current_salary);
                
                // Show all sections
                document.getElementById('statsSection').classList.remove('d-none');
                document.getElementById('chartSection').classList.remove('d-none');
                document.getElementById('tableSection').classList.remove('d-none');
                
                // Update statistics
                updateStats(data.history);
                
                // Render the history table
                renderHistoryTable(data.history);
                
                // Render the salary chart
                renderSalaryChart(data.history, data.current_salary);
                
                console.log('Salary history loaded successfully');
            } else {
                console.error('API error:', data.error);
                Swal.fire('Error!', data.error || 'Failed to load salary history', 'error');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            Swal.fire('Error!', 'Failed to load salary history: ' + error.message, 'error');
        });
}

// ============= RENDER HISTORY TABLE WITH PERMISSIONS =============
function renderHistoryTable(history) {
    const tbody = document.getElementById('salaryHistoryTableBody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    if (!canView()) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5">
                    <i class="bi bi-shield-lock text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Access Denied</h5>
                    <p class="text-muted">You don't have permission to view salary history.</p>
                </td>
            </tr>
        `;
        return;
    }
    
    if (!history || history.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5">
                    <i class="bi bi-clock-history text-muted" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">No Salary History Found</h5>
                    <p class="text-muted">No adjustments recorded for this employee.</p>
                </td>
            </tr>
        `;
        return;
    }
    
    history.forEach(record => {
        const changeAmount = record.new_salary - record.old_salary;
        const changePercentage = record.old_salary > 0 ? 
            ((changeAmount / record.old_salary) * 100).toFixed(2) : 0;
        
        const changeClass = changeAmount > 0 ? 'text-success' : 
                          changeAmount < 0 ? 'text-danger' : 'text-muted';
        
        // Build action buttons based on permissions
        let actionButtons = '';
        
        // View details button - always show if can view
        if (canView()) {
            actionButtons += `
                <button class="btn btn-sm btn-outline-secondary" onclick="viewAdjustmentDetails(${record.id})">
                    <i class="bi bi-info-circle"></i>
                </button>
            `;
        }
        
        // If no action buttons, show "View Only" badge
        if (!actionButtons) {
            actionButtons = '<span class="badge bg-secondary">View Only</span>';
        }
        
        tbody.innerHTML += `
            <tr>
                <td>
                    <div class="fw-bold">${formatDate(record.effective_date, true)}</div>
                </td>
                <td class="fw-bold">${formatCurrency(record.old_salary)}</td>
                <td class="fw-bold">${formatCurrency(record.new_salary)}</td>
                <td class="${changeClass} fw-bold">
                    ${changeAmount > 0 ? '+' : ''}${formatCurrency(Math.abs(changeAmount))}
                </td>
                <td class="${changeClass} fw-bold">
                    ${changeAmount > 0 ? '+' : ''}${changePercentage}%
                </td>
                <td>
                    <div class="text-muted small">${record.reason || 'N/A'}</div>
                </td>
                <td>
                    <small class="text-muted">${record.changed_by_name || 'System'}</small>
                </td>
                <td>
                    <small class="text-muted">${formatDate(record.changed_at, false)}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        ${actionButtons}
                    </div>
                </td>
            </tr>
        `;
    });
}

// ============= HIDE SECTIONS =============
function hideSections() {
    const sections = ['statsSection', 'chartSection', 'tableSection', 'currentSalaryInfo'];
    sections.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
    });
}

// ============= UPDATE STATS =============
function updateStats(history) {
    const totalAdjustmentsEl = document.getElementById('totalAdjustments');
    const highestIncreaseEl = document.getElementById('highestIncrease');
    const averageIncreaseEl = document.getElementById('averageIncrease');
    const lastAdjustmentEl = document.getElementById('lastAdjustment');
    
    if (!totalAdjustmentsEl || !highestIncreaseEl || !averageIncreaseEl || !lastAdjustmentEl) return;
    
    if (!history || history.length === 0) {
        totalAdjustmentsEl.textContent = '0';
        highestIncreaseEl.textContent = '₱0';
        averageIncreaseEl.textContent = '0%';
        lastAdjustmentEl.textContent = 'N/A';
        return;
    }
    
    const totalAdjustments = history.length;
    const increases = history.map(h => h.new_salary - h.old_salary);
    const highestIncrease = Math.max(...increases);
    
    // Calculate average percentage increase
    const percentageChanges = history.map(h => 
        h.old_salary > 0 ? ((h.new_salary - h.old_salary) / h.old_salary) * 100 : 0
    );
    const avgPercentage = percentageChanges.reduce((a, b) => a + b, 0) / totalAdjustments;
    
    // Get last adjustment date
    const lastAdjustment = history[0]?.effective_date || 'N/A';
    
    totalAdjustmentsEl.textContent = totalAdjustments;
    highestIncreaseEl.textContent = formatCurrency(highestIncrease);
    averageIncreaseEl.textContent = avgPercentage.toFixed(2) + '%';
    lastAdjustmentEl.textContent = lastAdjustment !== 'N/A' ? formatDate(lastAdjustment, true) : 'N/A';
}

// ============= RENDER SALARY CHART =============
function renderSalaryChart(history, currentSalary) {
    const canvas = document.getElementById('salaryChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Prepare data for chart
    const dates = [];
    const salaries = [];
    
    // Add historical data
    history.forEach(record => {
        dates.push(formatDate(record.effective_date, true));
        salaries.push(record.new_salary);
    });
    
    // Add current salary as last point
    if (dates.length > 0) {
        dates.push('Current');
        salaries.push(currentSalary);
    } else {
        // If no history, just show current salary
        dates.push('Current');
        salaries.push(currentSalary);
    }
    
    // Destroy existing chart if it exists
    if (salaryChart && typeof salaryChart.destroy === 'function') {
        salaryChart.destroy();
    }
    
    // Create new chart
    try {
        salaryChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Salary Progression',
                    data: salaries,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Salary: ${formatCurrency(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        console.log('Chart created successfully');
    } catch (error) {
        console.error('Error creating chart:', error);
    }
}

// ============= OPEN ADD ADJUSTMENT MODAL =============
function openAddAdjustmentModal() {
    if (!canAdd()) {
        let message = 'You do not have permission to add salary adjustments.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'HR manages salary adjustments. You are in oversight mode.';
        }
        
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    const form = document.getElementById('addAdjustmentForm');
    if (form) form.reset();
    
    const effectiveDate = document.getElementById('effective_date');
    if (effectiveDate) {
        effectiveDate.value = new Date().toISOString().split('T')[0];
    }
    
    const summary = document.getElementById('adjustmentSummary');
    if (summary) {
        summary.style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('addAdjustmentModal')).show();
}

// ============= UPDATE CURRENT SALARY =============
function updateCurrentSalary() {
    const employeeId = document.getElementById('adjustment_employee')?.value;
    const employee = employees.find(e => e.id == employeeId);
    
    const display = document.getElementById('current_salary_display');
    if (display) {
        if (employee) {
            const currentSalary = employee.basic_salary || 0;
            display.value = formatCurrency(currentSalary);
            
            // Calculate adjustment if new salary is already entered
            const newSalary = document.getElementById('new_salary')?.value;
            if (newSalary) {
                calculateAdjustment();
            }
        } else {
            display.value = '';
        }
    }
}

// ============= CALCULATE ADJUSTMENT =============
function calculateAdjustment() {
    const employeeId = document.getElementById('adjustment_employee')?.value;
    const employee = employees.find(e => e.id == employeeId);
    
    const summary = document.getElementById('adjustmentSummary');
    if (!summary) return;
    
    if (!employee) {
        summary.style.display = 'none';
        return;
    }
    
    const oldSalary = employee.basic_salary || 0;
    const newSalary = parseFloat(document.getElementById('new_salary')?.value) || 0;
    
    if (newSalary > 0 && newSalary !== oldSalary) {
        const changeAmount = newSalary - oldSalary;
        const changePercentage = oldSalary > 0 ? (changeAmount / oldSalary) * 100 : 0;
        
        const changeAmountEl = document.getElementById('changeAmount');
        const changePercentageEl = document.getElementById('changePercentage');
        
        if (changeAmountEl) {
            changeAmountEl.textContent = (changeAmount > 0 ? '+' : '') + formatCurrency(Math.abs(changeAmount));
        }
        if (changePercentageEl) {
            changePercentageEl.textContent = (changeAmount > 0 ? '+' : '') + changePercentage.toFixed(2) + '%';
        }
        
        summary.style.display = 'block';
    } else {
        summary.style.display = 'none';
    }
}

// ============= UPDATE REASON =============
function updateReason() {
    const selectedReason = document.getElementById('reason_select')?.value;
    const reasonInput = document.getElementById('reason');
    
    if (reasonInput) {
        if (selectedReason && selectedReason !== 'Other') {
            reasonInput.value = selectedReason;
        } else if (selectedReason === 'Other') {
            reasonInput.value = '';
        }
    }
}

// ============= SAVE ADJUSTMENT =============
function saveAdjustment(e) {
    e.preventDefault();
    
    // Double-check permission
    if (!canAdd()) {
        Swal.fire('Access Denied', 'You do not have permission to add salary adjustments', 'error');
        return;
    }
    
    const formData = {
        add_adjustment: true,
        employee_id: document.getElementById('adjustment_employee')?.value,
        new_salary: document.getElementById('new_salary')?.value,
        effective_date: document.getElementById('effective_date')?.value,
        reason: document.getElementById('reason')?.value
    };
    
    if (!formData.employee_id) {
        Swal.fire('Error!', 'Please select an employee', 'error');
        return;
    }
    
    if (!formData.new_salary || parseFloat(formData.new_salary) <= 0) {
        Swal.fire('Error!', 'Please enter a valid salary amount', 'error');
        return;
    }
    
    if (!formData.reason) {
        Swal.fire('Error!', 'Please provide a reason for adjustment', 'error');
        return;
    }
    
    fetch('api/salary_history.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(formData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', data.message, 'success');
            
            const modal = bootstrap.Modal.getInstance(document.getElementById('addAdjustmentModal'));
            if (modal) modal.hide();
            
            // Refresh if viewing same employee
            if (currentEmployeeId == formData.employee_id) {
                loadSalaryHistory();
            }
            
            // Update employee list to refresh salary
            loadEmployees();
        } else {
            Swal.fire('Error!', data.error || 'Failed to add adjustment', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving adjustment:', error);
        Swal.fire('Error!', 'Failed to save adjustment', 'error');
    });
}

// ============= OPEN BULK INCREASE MODAL =============
function openBulkIncreaseModal() {
    if (!canBulk()) {
        let message = 'You do not have permission to perform bulk salary increases.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'HR manages salary adjustments. You are in oversight mode.';
        }
        
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    const modal = document.getElementById('bulkIncreaseModal');
    if (modal) {
        const form = modal.querySelector('form');
        if (form) form.reset();
        
        const effectiveDate = document.getElementById('bulk_effective_date');
        if (effectiveDate) {
            effectiveDate.value = new Date().toISOString().split('T')[0];
        }
        
        const affectedDiv = document.getElementById('affectedEmployees');
        if (affectedDiv) {
            affectedDiv.innerHTML = 'Enter percentage to see affected employees';
        }
        
        new bootstrap.Modal(modal).show();
    }
}

// ============= LOAD AFFECTED EMPLOYEES =============
function loadAffectedEmployees() {
    const percentage = document.getElementById('increase_percentage')?.value;
    const affectedDiv = document.getElementById('affectedEmployees');
    
    if (!affectedDiv) return;
    
    if (percentage && parseFloat(percentage) > 0) {
        fetch('api/employees.php?active_only=true&has_salary=true')
            .then(res => res.json())
            .then(employees => {
                const count = employees.length;
                if (count > 0) {
                    affectedDiv.innerHTML = `
                        <div class="text-success fw-bold">${count} active employee(s) with salaries will be affected.</div>
                        <small class="d-block mt-1">Each salary will be increased by ${percentage}%.</small>
                    `;
                } else {
                    affectedDiv.innerHTML = `
                        <div class="text-warning">No active employees with salaries found.</div>
                    `;
                }
            })
            .catch(error => {
                affectedDiv.innerHTML = 'Error loading employees';
            });
    } else {
        affectedDiv.innerHTML = 'Enter percentage to see affected employees';
    }
}

// ============= APPLY BULK INCREASE =============
function applyBulkIncrease() {
    if (!canBulk()) {
        Swal.fire('Access Denied', 'You do not have permission to perform bulk salary increases', 'error');
        return;
    }
    
    const percentage = parseFloat(document.getElementById('increase_percentage')?.value);
    const effectiveDate = document.getElementById('bulk_effective_date')?.value;
    const reason = document.getElementById('bulk_reason')?.value || `Bulk salary increase (${percentage}%)`;
    
    if (!percentage || percentage <= 0) {
        Swal.fire('Error!', 'Please enter a valid percentage', 'error');
        return;
    }
    
    if (!effectiveDate) {
        Swal.fire('Error!', 'Please select an effective date', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Apply bulk salary increase?',
        html: `
            <div class="text-start">
                <p><strong>Percentage:</strong> ${percentage}%</p>
                <p><strong>Effective Date:</strong> ${formatDate(effectiveDate, true)}</p>
                <p><strong>Reason:</strong> ${reason}</p>
                <p class="text-danger mt-3"><i class="bi bi-exclamation-triangle"></i> This action cannot be undone.</p>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, apply increase',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('api/salary_history.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    bulk_increase: true,
                    percentage: percentage,
                    effective_date: effectiveDate,
                    reason: reason
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success');
                    
                    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkIncreaseModal'));
                    if (modal) modal.hide();
                    
                    // Refresh current view if needed
                    if (currentEmployeeId) {
                        loadSalaryHistory();
                    }
                    
                    // Update employee list
                    loadEmployees();
                } else {
                    Swal.fire('Error!', data.error || 'Failed to apply bulk increase', 'error');
                }
            })
            .catch(error => {
                console.error('Error applying bulk increase:', error);
                Swal.fire('Error!', 'Failed to apply bulk increase', 'error');
            });
        }
    });
}

// ============= VIEW ADJUSTMENT DETAILS =============
function viewAdjustmentDetails(id) {
    const adjustment = salaryHistory.find(h => h.id == id);
    
    if (adjustment) {
        const changeAmount = adjustment.new_salary - adjustment.old_salary;
        const changePercentage = adjustment.old_salary > 0 ? 
            ((changeAmount / adjustment.old_salary) * 100).toFixed(2) : 0;
        
        Swal.fire({
            title: 'Salary Adjustment Details',
            html: `
                <div class="text-start">
                    <p><strong>Effective Date:</strong> ${formatDate(adjustment.effective_date, true)}</p>
                    <p><strong>Previous Salary:</strong> ${formatCurrency(adjustment.old_salary)}</p>
                    <p><strong>New Salary:</strong> ${formatCurrency(adjustment.new_salary)}</p>
                    <p><strong>Change Amount:</strong> 
                        <span class="${changeAmount > 0 ? 'text-success' : changeAmount < 0 ? 'text-danger' : 'text-muted'}">
                            ${changeAmount > 0 ? '+' : ''}${formatCurrency(Math.abs(changeAmount))}
                        </span>
                    </p>
                    <p><strong>Percentage Change:</strong> 
                        <span class="${changeAmount > 0 ? 'text-success' : changeAmount < 0 ? 'text-danger' : 'text-muted'}">
                            ${changeAmount > 0 ? '+' : ''}${changePercentage}%
                        </span>
                    </p>
                    <p><strong>Reason:</strong> ${adjustment.reason || 'N/A'}</p>
                    <p><strong>Changed By:</strong> ${adjustment.changed_by_name || 'System'}</p>
                    <p><strong>Date Changed:</strong> ${formatDate(adjustment.changed_at, false)}</p>
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'Close'
        });
    }
}

// ============= EXPORT SALARY HISTORY =============
function exportSalaryHistory() {
    if (!canExport()) {
        let message = 'You do not have permission to export salary history.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'HR manages exports. You are in oversight mode.';
        }
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    if (!currentEmployeeId) {
        Swal.fire('Error!', 'Please select an employee first', 'error');
        return;
    }
    
    // Add your export logic here
    let csv = 'Effective Date,Previous Salary,New Salary,Change Amount,Change %,Reason,Changed By,Date Changed\n';
    salaryHistory.forEach(rec => {
        const changeAmount = rec.new_salary - rec.old_salary;
        const changePercentage = rec.old_salary > 0 ? ((changeAmount / rec.old_salary) * 100).toFixed(2) : 0;
        
        csv += `"${formatDate(rec.effective_date, true)}",` +
               `"${rec.old_salary}",` +
               `"${rec.new_salary}",` +
               `"${changeAmount > 0 ? '+' : ''}${Math.abs(changeAmount)}",` +
               `"${changeAmount > 0 ? '+' : ''}${changePercentage}%",` +
               `"${rec.reason || ''}",` +
               `"${rec.changed_by_name || 'System'}",` +
               `"${formatDate(rec.changed_at, false)}"\n`;
    });
    
    const blob = new Blob([csv], {type: 'text/csv'});
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `salary_history_${currentEmployeeId}_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ============= HELPER FUNCTIONS =============
function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString, dateOnly = false) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'N/A';
    
    if (dateOnly) {
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ============= MODIFY DOCUMENT READY =============
document.addEventListener('DOMContentLoaded', async () => {
    // Initialize arrays
    employees = [];
    salaryHistory = [];
    
    // Load permissions first, then employees will be loaded automatically
    await loadPermissions();
    
    // Add form submit listener
    const form = document.getElementById('addAdjustmentForm');
    if (form) {
        form.addEventListener('submit', saveAdjustment);
    }
    
    // Add event listeners for bulk increase
    const bulkForm = document.getElementById('bulkIncreaseForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', (e) => {
            e.preventDefault();
            applyBulkIncrease();
        });
    }
});

// ============= DEBUG FUNCTION =============
function showPermissions() {
    Swal.fire({
        title: 'Your Salary History Permissions',
        html: `
            <div class="text-start">
                <p><strong>Role:</strong> ${currentUserRole}</p>
                <p><strong>Has HR:</strong> ${hasHR ? 'Yes' : 'No'}</p>
                <p><strong>User ID:</strong> ${currentUserId}</p>
                <p><strong>Permissions:</strong></p>
                <ul>
                    <li>View: ${userPermissions.view ? '✅' : '❌'}</li>
                    <li>Add: ${userPermissions.add ? '✅' : '❌'}</li>
                    <li>Edit: ${userPermissions.edit ? '✅' : '❌'}</li>
                    <li>Delete: ${userPermissions.delete ? '✅' : '❌'}</li>
                    <li>Bulk: ${userPermissions.bulk ? '✅' : '❌'}</li>
                    <li>Export: ${userPermissions.export ? '✅' : '❌'}</li>
                </ul>
            </div>
        `,
        icon: 'info'
    });
}
</script>

</body>
</html>