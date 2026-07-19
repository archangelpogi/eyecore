<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    
    <style>
        .card-soft {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .status-released { background-color: #d1fae5; color: #065f46; }
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .payslip-card {
            transition: all 0.3s ease;
            border-left: 4px solid #3b82f6;
        }
        .payslip-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .salary-amount {
            font-weight: bold;
            color: #059669;
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER - FIXED: Added ClinicAdmin to condition -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Payslip Management System</h2>
            <p class="text-muted mb-0">Manage and distribute employee payslips</p>
        </div>
    </div>
    
    <!-- STATS -->
    <div class="row g-3 mb-4" id="payslipStats">
        <!-- Stats loaded dynamically -->
    </div>
    
    <!-- FILTERS - FIXED: Added ClinicAdmin to condition -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3 align-items-center">
            <?php if (in_array($_SESSION['role'], ['HR', 'Finance', 'ClinicAdmin'])): ?>
            <div class="col-md-3">
                <select id="employeeFilter" class="form-select" onchange="loadPayslips()">
                    <option value="">All Employees</option>
                    <!-- Employees loaded dynamically -->
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <select id="statusFilter" class="form-select" onchange="loadPayslips()">
                    <option value="">All Status</option>
                    <option value="released">Released</option>
                    <option value="pending">Pending Release</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="month" id="monthFilter" class="form-control" 
                       value="<?= date('Y-m') ?>" onchange="loadPayslips()">
            </div>
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="searchPayslips" class="form-control" 
                           placeholder="Search payslips...">
                </div>
            </div>
        </div>
    </div>
    
    <!-- PAYSLIPS TABLE -->
    <div class="card-soft overflow-hidden mb-4">
        <div class="table-responsive">
            <table id="payslipsTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Payslip Details</th>
                        <th>Employee</th>
                        <th>Payroll Period</th>
                        <th>Net Pay</th>
                        <th>Status</th>
                        <th>Released On</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody id="payslipsTableBody">
                    <!-- Payslips loaded dynamically -->
                </tbody>
            </table>
        </div>
    </div>

</div>


<!-- MODAL - UPLOAD PAYSLIP FILE -->
<div class="modal fade" id="uploadPayslipModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Upload Payslip File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="uploadPayslipForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" id="upload_payslip_id">
                    <div class="mb-3">
                        <label class="form-label">Payslip Code</label>
                        <input type="text" class="form-control" id="upload_payslip_code" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select PDF File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="payslip_file" 
                               accept=".pdf" required>
                        <div class="form-text">Only PDF files are allowed. Maximum size: 5MB</div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Ensure the uploaded file contains accurate salary information.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload File</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL - VIEW PAYSLIP DETAILS -->
<div class="modal fade" id="viewPayslipModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Payslip Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewPayslipContent">
                <!-- Content loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="downloadPayslipBtn" 
                        onclick="downloadPayslip()">
                    <i class="bi bi-download me-2"></i>Download Payslip
                </button>
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

<script>
    // ============= PERMISSION VARIABLES =============
    let currentUserRole = null;
    let userPermissions = {
        view_all: false,
        view_own: true,
        generate: false,
        upload: false,
        release: false,
        bulk_release: false,
        delete: false,
        export: false,
        download: true
    };
    let hasHR = false;
    let isOwner = false;
    let currentUserId = null;

    let payslipsData = [];
    let employeesList = [];
    let payrollsList = [];

    // ============= LOAD PERMISSIONS =============
    async function loadPermissions() {
        try {
            const response = await fetch('api/payslips.php?get_permissions=true');
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
                
                // Apply permissions to UI
                applyPermissionBasedUI();
                
                // NOW load data after permissions are applied
                await loadEmployees();
                await loadPayslips();
            }
        } catch (error) {
            console.error('Error loading permissions:', error);
            // Still try to load data even if permissions fail
            loadEmployees();
            loadPayslips();
        }
    }

    // ============= PERMISSION HELPER FUNCTIONS =============
    function canViewAll() { return userPermissions.view_all; }
    function canViewOwn() { return userPermissions.view_own; }
    function canGenerate() { return userPermissions.generate; }
    function canUpload() { return userPermissions.upload; }
    function canRelease() { return userPermissions.release; }
    function canBulkRelease() { return userPermissions.bulk_release; }
    function canDelete() { return userPermissions.delete; }
    function canExport() { return userPermissions.export; }
    function canDownload() { return userPermissions.download; }

    // ============= APPLY PERMISSIONS TO UI =============
    function applyPermissionBasedUI() {
        // If user can't view anything, show access denied message
        if (!canViewAll() && !canViewOwn()) {
            const container = document.querySelector('.container-fluid');
            if (container) {
                const mainContent = document.querySelector('.card-soft');
                if (mainContent) {
                    container.innerHTML = `
                        <div class="text-center py-5">
                            <i class="bi bi-shield-lock text-muted" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">Access Denied</h4>
                            <p class="text-muted">You don't have permission to view payslips.</p>
                        </div>
                    `;
                }
            }
            return;
        }
        
        // FIXED: Use getElementById instead of querySelector for buttons
        const generateBtn = document.getElementById('generatePayslipBtn');
        if (generateBtn) {
            generateBtn.style.display = canGenerate() ? 'inline-block' : 'none';
        }
        
        const exportBtn = document.getElementById('exportPayslipsBtn');
        if (exportBtn) {
            exportBtn.style.display = canExport() ? 'inline-block' : 'none';
        }
        
    }


    // ============= MODIFY DOCUMENT READY =============
    document.addEventListener('DOMContentLoaded', async () => {
        // Initialize arrays
        payslipsData = [];
        employeesList = [];
        payrollsList = [];
        
        // Load permissions first, then data will be loaded automatically
        await loadPermissions();
        
        // Add form submit listeners
        const generateForm = document.getElementById('generatePayslipForm');
        if (generateForm) {
            generateForm.addEventListener('submit', generatePayslip);
        }
        
        const uploadForm = document.getElementById('uploadPayslipForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', uploadPayslipFile);
        }
        
        const searchInput = document.getElementById('searchPayslips');
        if (searchInput) {
            searchInput.addEventListener('keyup', searchPayslips);
        }
        
        // FIXED: Always add filter listeners, they'll only work if user can view all
        const monthFilter = document.getElementById('monthFilter');
        const statusFilter = document.getElementById('statusFilter');
        const employeeFilter = document.getElementById('employeeFilter');
        
        if (monthFilter) monthFilter.addEventListener('change', loadPayslips);
        if (statusFilter) statusFilter.addEventListener('change', loadPayslips);
        if (employeeFilter) employeeFilter.addEventListener('change', loadPayslips);
    });

    // ============= LOAD EMPLOYEES =============
    async function loadEmployees() {
        // Only load employees if user can manage payslips or view all
        if (!canGenerate() && !canViewAll()) {
            return;
        }
        
        try {
            const response = await fetch('api/users.php');
            const data = await response.json();
            employeesList = Array.isArray(data) ? data : [];
            populateEmployeeDropdowns();
        } catch (error) {
            console.error('Error loading employees:', error);
            employeesList = [];
        }
    }

    // ============= POPULATE EMPLOYEE DROPDOWNS =============
    function populateEmployeeDropdowns() {
        const filterSelect = document.getElementById('employeeFilter');
        const generateSelect = document.getElementById('generate_employee_id');
        
        if (filterSelect) {
            filterSelect.innerHTML = '<option value="">All Employees</option>';
            employeesList.forEach(emp => {
                const option = document.createElement('option');
                option.value = emp.id;
                option.textContent = `${emp.first_name} ${emp.last_name} (${emp.employee_no})`;
                filterSelect.appendChild(option);
            });
        }
        
        if (generateSelect) {
            generateSelect.innerHTML = '<option value="">Select Employee</option>';
            employeesList.forEach(emp => {
                const option = document.createElement('option');
                option.value = emp.id;
                option.textContent = `${emp.first_name} ${emp.last_name} - ${emp.employee_no}`;
                generateSelect.appendChild(option);
            });
        }
    }

    // ============= LOAD EMPLOYEE PAYROLLS =============
    function loadEmployeePayrolls(employeeId) {
        if (!canGenerate()) {
            return;
        }
        
        if (!employeeId) {
            const preview = document.getElementById('payrollPreview');
            if (preview) preview.style.display = 'none';
            
            const select = document.getElementById('generate_payroll_id');
            if (select) select.innerHTML = '<option value="">Select Payroll</option>';
            return;
        }
        
        // Load payroll records for this employee
        fetch(`api/payroll.php?employee_id=${employeeId}`)
            .then(res => res.json())
            .then(data => {
                payrollsList = Array.isArray(data) ? data : [];
                const select = document.getElementById('generate_payroll_id');
                select.innerHTML = '<option value="">Select Payroll</option>';
                
                payrollsList.forEach(payroll => {
                    const option = document.createElement('option');
                    option.value = payroll.id;
                    option.textContent = `${payroll.payroll_period} - ₱${parseFloat(payroll.net_pay || 0).toLocaleString()}`;
                    select.appendChild(option);
                });
                
                select.addEventListener('change', function() {
                    const payrollId = this.value;
                    if (payrollId) {
                        showPayrollPreview(payrollId);
                    } else {
                        const preview = document.getElementById('payrollPreview');
                        if (preview) preview.style.display = 'none';
                    }
                });
            });
    }

// ============= LOAD PAYSLIPS =============
function loadPayslips() {
    const month = document.getElementById('monthFilter')?.value;
    const status = document.getElementById('statusFilter')?.value;
    const employeeId = document.getElementById('employeeFilter')?.value;
    
    let url = 'api/payslips.php?';
    const params = [];
    
    // Always add filters if they exist
    if (month) params.push(`month=${month}`);
    if (status) params.push(`status=${status}`);
    if (employeeId) params.push(`employee_filter=${employeeId}`);
    
    if (params.length > 0) {
        url += params.join('&');
    } else {
        url = 'api/payslips.php';
    }
    
    console.log('Loading payslips from:', url);
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            console.log('Payslips data received:', data);
            
            // Check if data has error
            if (data && data.error) {
                console.error('API Error:', data.error);
                payslipsData = [];
            } else {
                // Data is the array of payslips
                payslipsData = Array.isArray(data) ? data : [];
            }
            
            renderPayslipStats();
            renderPayslipsTable();
        })
        .catch(error => {
            console.error('Error loading payslips:', error);
            payslipsData = [];
            renderPayslipStats();
            renderPayslipsTable();
        });
}
    // ============= RENDER PAYSLIP STATS =============
    function renderPayslipStats() {
        const container = document.getElementById('payslipStats');
        if (!container) return;
        
        // If no data or can't view, show zeros
        if (!payslipsData || payslipsData.length === 0 || (!canViewAll() && !canViewOwn())) {
            container.innerHTML = `
                <div class="col-md-3">
                    <div class="card-soft p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="text-muted">Released Payslips</small>
                                <h3 class="fw-bold mt-1 mb-2 text-success">0</h3>
                            </div>
                            <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card-soft p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="text-muted">Pending Release</small>
                                <h3 class="fw-bold mt-1 mb-2 text-warning">0</h3>
                            </div>
                            <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-clock-history fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card-soft p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="text-muted">Total Payslips</small>
                                <h3 class="fw-bold mt-1 mb-2 text-primary">0</h3>
                            </div>
                            <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-receipt fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card-soft p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="text-muted">Total Amount</small>
                                <h3 class="fw-bold mt-1 mb-2 text-success">₱0</h3>
                            </div>
                            <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                                <i class="bi bi-cash-coin fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            return;
        }
        
        const released = payslipsData.filter(p => p.released_at).length;
        const pending = payslipsData.filter(p => !p.released_at).length;
        const total = payslipsData.length;
        const totalAmount = payslipsData.reduce((sum, p) => sum + parseFloat(p.net_pay || 0), 0);
        
        container.innerHTML = `
            <div class="col-md-3">
                <div class="card-soft p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <small class="text-muted">Released Payslips</small>
                            <h3 class="fw-bold mt-1 mb-2 text-success">${released}</h3>
                        </div>
                        <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                            <i class="bi bi-check-circle fs-5"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block">Already distributed</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card-soft p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <small class="text-muted">Pending Release</small>
                            <h3 class="fw-bold mt-1 mb-2 text-warning">${pending}</h3>
                        </div>
                        <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-clock-history fs-5"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block">Awaiting distribution</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card-soft p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <small class="text-muted">Total Payslips</small>
                            <h3 class="fw-bold mt-1 mb-2 text-primary">${total}</h3>
                        </div>
                        <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-receipt fs-5"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block">All payslip records</small>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card-soft p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <small class="text-muted">Total Amount</small>
                            <h3 class="fw-bold mt-1 mb-2 text-success">₱${totalAmount.toLocaleString()}</h3>
                        </div>
                        <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                            <i class="bi bi-cash-coin fs-5"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block">Total net pay distributed</small>
                </div>
            </div>
        `;
    }

    // ============= RENDER PAYSLIPS TABLE =============
    function renderPayslipsTable() {
        const tbody = document.getElementById('payslipsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        // If user can't view any payslips
        if (!canViewAll() && !canViewOwn()) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="bi bi-shield-lock text-muted" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Access Denied</h5>
                        <p class="text-muted">You don't have permission to view payslips.</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        // If no data
        if (!payslipsData || payslipsData.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">No Payslips Found</h5>
                        <p class="text-muted">No payslips available.</p>
                    </td>
                </tr>
            `;
            return;
        }
        
        payslipsData.forEach(payslip => {
            const statusClass = payslip.released_at ? 'status-released' : 'status-pending';
            const statusText = payslip.released_at ? 'Released' : 'Pending Release';
            const releasedDate = payslip.released_at ? formatDate(payslip.released_at, true) : 'Not released';
            const periodText = payslip.payroll_period || formatDate(payslip.period_start, true) + ' to ' + formatDate(payslip.period_end, true);
            
            tbody.innerHTML += `
                <tr class="payslip-card">
                    <td>
                        <div class="fw-bold">${payslip.payslip_code || 'N/A'}</div>
                        <small class="text-muted">ID: ${payslip.id}</small>
                        ${payslip.file_path ? `
                        <div class="mt-1">
                            <span class="badge bg-info">
                                <i class="bi bi-file-pdf"></i> PDF Available
                            </span>
                        </div>
                        ` : ''}
                    </td>
                    <td>
                        <div class="fw-bold">${payslip.employee_name || 'N/A'}</div>
                        <small class="text-muted">${payslip.employee_no || ''}</small>
                        <br>
                        <small class="text-muted">${payslip.position_name || ''}</small>
                    </td>
                    <td>
                        <div class="fw-bold">${periodText}</div>
                        <small class="text-muted">
                            ${formatDate(payslip.period_start, true)} - ${formatDate(payslip.period_end, true)}
                        </small>
                    </td>
                    <td>
                        <div class="salary-amount">₱${parseFloat(payslip.net_pay || 0).toLocaleString()}</div>
                    </td>
                    <td>
                        <span class="badge ${statusClass}">
                            ${statusText}
                        </span>
                    </td>
                    <td>
                        <small class="text-muted">${releasedDate}</small>
                        ${payslip.released_by_name ? `
                        <br>
                        <small class="text-muted">by ${payslip.released_by_name}</small>
                        ` : ''}
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info" onclick="viewPayslip(${payslip.id})"
                                    title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                            ${renderPayslipActionButtons(payslip)}
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    // ============= RENDER PAYSLIP ACTION BUTTONS =============
    function renderPayslipActionButtons(payslip) {
        let buttons = '';
        const isReleased = payslip.released_at;
        const hasFile = payslip.file_path;
        
        // Upload button - only if can upload and not released
        if (canUpload() && !hasFile && !isReleased) {
            buttons += `
                <button class="btn btn-outline-warning" onclick="openUploadModal(${payslip.id}, '${payslip.payslip_code}')"
                        title="Upload PDF">
                    <i class="bi bi-upload"></i>
                </button>
            `;
        }
        
        // Release button - only if can release and not released
        if (canRelease() && !isReleased) {
            buttons += `
                <button class="btn btn-outline-success" onclick="releasePayslip(${payslip.id})"
                        title="Release Payslip">
                    <i class="bi bi-send-check"></i>
                </button>
            `;
        }
        
        // Download button - only if can download and has file
        if (canDownload() && hasFile) {
            buttons += `
                <button class="btn btn-outline-primary" onclick="downloadPayslipDirect(${payslip.id})"
                        title="Download PDF">
                    <i class="bi bi-download"></i>
                </button>
            `;
        }
        
        // Delete button - only if can delete
        if (canDelete()) {
            buttons += `
                <button class="btn btn-outline-danger" onclick="deletePayslip(${payslip.id})"
                        title="Delete Payslip">
                    <i class="bi bi-trash"></i>
                </button>
            `;
        }
        
        // If no action buttons, show "View Only" badge
        if (!buttons) {
            buttons = '<span class="badge bg-secondary">View Only</span>';
        }
        
        return buttons;
    }


    // ============= OPEN UPLOAD MODAL =============
    function openUploadModal(payslipId, payslipCode) {
        if (!canUpload()) {
            Swal.fire('Access Denied', 'You do not have permission to upload payslips', 'error');
            return;
        }
        
        const form = document.getElementById('uploadPayslipForm');
        if (form) form.reset();
        
        document.getElementById('upload_payslip_id').value = payslipId;
        document.getElementById('upload_payslip_code').value = payslipCode;
        
        const modal = new bootstrap.Modal(document.getElementById('uploadPayslipModal'));
        modal.show();
    }

    // ============= UPLOAD PAYSLIP FILE =============
    function uploadPayslipFile(e) {
        e.preventDefault();
        
        if (!canUpload()) {
            Swal.fire('Access Denied', 'You do not have permission to upload payslips', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('payslip_id', document.getElementById('upload_payslip_id').value);
        
        const fileInput = document.getElementById('payslip_file');
        if (!fileInput.files[0]) {
            Swal.fire('Error!', 'Please select a PDF file', 'error');
            return;
        }
        
        formData.append('payslip_file', fileInput.files[0]);
        
        // Show loading
        Swal.fire({
            title: 'Uploading...',
            text: 'Please wait while the file is being uploaded',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        fetch('api/payslips.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('uploadPayslipModal'));
                if (modal) modal.hide();
                
                loadPayslips();
            } else {
                Swal.fire('Error!', data.error, 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Error!', 'Upload failed: ' + error.message, 'error');
        });
    }

    // ============= RELEASE PAYSLIP =============
    function releasePayslip(payslipId) {
        if (!canRelease()) {
            Swal.fire('Access Denied', 'You do not have permission to release payslips', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Release Payslip?',
            text: 'This will mark the payslip as released to the employee.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, release it',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/payslips.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        release_payslip: true,
                        payslip_id: payslipId
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Released!', data.message, 'success');
                        loadPayslips();
                    } else {
                        Swal.fire('Error!', data.error, 'error');
                    }
                });
            }
        });
    }

    // ============= BULK RELEASE PAYSLIPS =============
    function bulkReleasePayslips(payrollId) {
        if (!canBulkRelease()) {
            Swal.fire('Access Denied', 'You do not have permission to bulk release payslips', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Bulk Release Payslips?',
            text: 'This will release all unreleased payslips for this payroll period.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, release all',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/payslips.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        bulk_release_payslips: true,
                        payroll_id: payrollId
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Success!', data.message, 'success');
                        loadPayslips();
                    } else {
                        Swal.fire('Error!', data.error, 'error');
                    }
                });
            }
        });
    }

    // ============= DELETE PAYSLIP =============
    function deletePayslip(payslipId) {
        if (!canDelete()) {
            Swal.fire('Access Denied', 'You do not have permission to delete payslips', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Delete Payslip?',
            text: 'This action cannot be undone. The PDF file will also be deleted.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/payslips.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        delete_payslip: true,
                        payslip_id: payslipId
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Deleted!', data.message, 'success');
                        loadPayslips();
                    } else {
                        Swal.fire('Error!', data.error, 'error');
                    }
                });
            }
        });
    }

   
    // ============= SHOW PAYROLL PREVIEW =============
    function showPayrollPreview(payrollId) {
        const payroll = payrollsList.find(p => p.id == payrollId);
        if (!payroll) return;
        
        const preview = document.getElementById('payrollPreview');
        preview.innerHTML = `
            <h6>Payroll Preview</h6>
            <div class="row small">
                <div class="col-md-6">
                    <div><strong>Period:</strong> ${payroll.payroll_period}</div>
                    <div><strong>Gross Pay:</strong> ₱${parseFloat(payroll.gross_pay || 0).toLocaleString()}</div>
                    <div><strong>Deductions:</strong> ₱${parseFloat(payroll.total_deductions || 0).toLocaleString()}</div>
                </div>
                <div class="col-md-6">
                    <div><strong>Net Pay:</strong> <span class="salary-amount">₱${parseFloat(payroll.net_pay || 0).toLocaleString()}</span></div>
                    <div><strong>Status:</strong> ${payroll.status || 'N/A'}</div>
                </div>
            </div>
        `;
        preview.style.display = 'block';
    }

    // ============= VIEW PAYSLIP =============
    function viewPayslip(payslipId) {
        fetch(`api/payslips.php?payslip_id=${payslipId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const payslip = data.data;
                    const breakdown = data.breakdown;
                    
                    let earningsHTML = '';
                    let deductionsHTML = '';
                    
                    if (breakdown) {
                        earningsHTML = `
                            <tr><td>Basic Salary</td><td class="text-end">₱${parseFloat(breakdown.basic_salary || 0).toLocaleString()}</td></tr>
                            <tr><td>Overtime</td><td class="text-end">₱${parseFloat(breakdown.overtime || 0).toLocaleString()}</td></tr>
                            <tr><td>Allowances</td><td class="text-end">₱${parseFloat(breakdown.allowances || 0).toLocaleString()}</td></tr>
                            <tr><td>Bonuses</td><td class="text-end">₱${parseFloat(breakdown.bonuses || 0).toLocaleString()}</td></tr>
                        `;
                        
                        deductionsHTML = `
                            <tr><td>SSS Contribution</td><td class="text-end">₱${parseFloat(breakdown.sss_contribution || 0).toLocaleString()}</td></tr>
                            <tr><td>PhilHealth Contribution</td><td class="text-end">₱${parseFloat(breakdown.philhealth_contribution || 0).toLocaleString()}</td></tr>
                            <tr><td>Pag-IBIG Contribution</td><td class="text-end">₱${parseFloat(breakdown.pagibig_contribution || 0).toLocaleString()}</td></tr>
                            <tr><td>Withholding Tax</td><td class="text-end">₱${parseFloat(breakdown.withholding_tax || 0).toLocaleString()}</td></tr>
                        `;
                    }
                    
                    document.getElementById('viewPayslipContent').innerHTML = `
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <h4>Payslip: ${payslip.payslip_code}</h4>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted">Employee</small>
                                        <h5 class="fw-bold">${payslip.employee_name}</h5>
                                        <small class="text-muted">${payslip.employee_no}</small>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">Position</small>
                                        <h6>${payslip.position_name}</h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="badge ${payslip.released_at ? 'bg-success' : 'bg-warning'} p-2">
                                    ${payslip.released_at ? 'RELEASED' : 'PENDING'}
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">Payroll Period</small>
                                    <h6>${payslip.payroll_period}</h6>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card-soft p-3">
                                    <h6 class="border-bottom pb-2 mb-3">Earnings</h6>
                                    <table class="table table-sm">
                                        ${earningsHTML}
                                        <tr class="table-success">
                                            <td><strong>Gross Pay</strong></td>
                                            <td class="text-end"><strong>₱${parseFloat(payslip.gross_pay || 0).toLocaleString()}</strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card-soft p-3">
                                    <h6 class="border-bottom pb-2 mb-3">Deductions</h6>
                                    <table class="table table-sm">
                                        ${deductionsHTML}
                                        <tr class="table-danger">
                                            <td><strong>Total Deductions</strong></td>
                                            <td class="text-end"><strong>₱${parseFloat(payslip.total_deductions || 0).toLocaleString()}</strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    ${payslip.released_at ? 
                                        `Released on ${formatDate(payslip.released_at)} by ${payslip.released_by_name}` : 
                                        'This payslip has not been released yet.'}
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card-soft p-3 bg-success bg-opacity-10">
                                    <h6 class="text-success">Net Pay</h6>
                                    <h2 class="text-success fw-bold">₱${parseFloat(payslip.net_pay || 0).toLocaleString()}</h2>
                                    <small class="text-muted">Amount to be received</small>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Set download button
                    const downloadBtn = document.getElementById('downloadPayslipBtn');
                    if (payslip.file_path && canDownload()) {
                        downloadBtn.style.display = 'inline-block';
                        downloadBtn.onclick = () => downloadPayslipDirect(payslipId);
                    } else {
                        downloadBtn.style.display = 'none';
                    }
                    
                    new bootstrap.Modal(document.getElementById('viewPayslipModal')).show();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
    }

    // ============= DOWNLOAD PAYSLIP DIRECT =============
    function downloadPayslipDirect(payslipId) {
        if (!canDownload()) {
            Swal.fire('Access Denied', 'You do not have permission to download payslips', 'error');
            return;
        }
        
        window.open(`api/payslips.php?download=true&payslip_id=${payslipId}`, '_blank');
    }

    // ============= SEARCH PAYSLIPS =============
    function searchPayslips() {
        const searchTerm = document.getElementById('searchPayslips').value.toLowerCase();
        
        const filtered = payslipsData.filter(payslip => 
            (payslip.employee_name && payslip.employee_name.toLowerCase().includes(searchTerm)) ||
            (payslip.employee_no && payslip.employee_no.toLowerCase().includes(searchTerm)) ||
            (payslip.payslip_code && payslip.payslip_code.toLowerCase().includes(searchTerm)) ||
            (payslip.payroll_period && payslip.payroll_period.toLowerCase().includes(searchTerm))
        );
        
        renderFilteredPayslips(filtered);
    }

    // ============= RENDER FILTERED PAYSLIPS =============
    function renderFilteredPayslips(data) {
        const tbody = document.getElementById('payslipsTableBody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        data.forEach(payslip => {
            const statusClass = payslip.released_at ? 'status-released' : 'status-pending';
            const statusText = payslip.released_at ? 'Released' : 'Pending Release';
            const releasedDate = payslip.released_at ? formatDate(payslip.released_at, true) : 'Not released';
            
            tbody.innerHTML += `
                <tr class="payslip-card">
                    <td>
                        <div class="fw-bold">${payslip.payslip_code || 'N/A'}</div>
                        <small class="text-muted">ID: ${payslip.id}</small>
                        ${payslip.file_path ? `
                        <div class="mt-1">
                            <span class="badge bg-info">
                                <i class="bi bi-file-pdf"></i> PDF Available
                            </span>
                        </div>
                        ` : ''}
                    </td>
                    <td>
                        <div class="fw-bold">${payslip.employee_name || 'N/A'}</div>
                        <small class="text-muted">${payslip.employee_no || ''}</small>
                    </td>
                    <td>
                        <div class="fw-bold">${payslip.payroll_period}</div>
                        <small class="text-muted">
                            ${formatDate(payslip.period_start, true)} - ${formatDate(payslip.period_end, true)}
                        </small>
                    </td>
                    <td>
                        <div class="salary-amount">₱${parseFloat(payslip.net_pay || 0).toLocaleString()}</div>
                    </td>
                    <td>
                        <span class="badge ${statusClass}">
                            ${statusText}
                        </span>
                    </td>
                    <td>
                        <small class="text-muted">${releasedDate}</small>
                        ${payslip.released_by_name ? `
                        <br>
                        <small class="text-muted">by ${payslip.released_by_name}</small>
                        ` : ''}
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info" onclick="viewPayslip(${payslip.id})">
                                <i class="bi bi-eye"></i>
                            </button>
                            ${renderPayslipActionButtons(payslip)}
                        </div>
                    </td>
                </tr>
            `;
        });
    }

    // ============= FORMAT DATE =============
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

    // ============= DEBUG FUNCTION =============
    function showPermissions() {
        Swal.fire({
            title: 'Your Payslip Permissions',
            html: `
                <div class="text-start">
                    <p><strong>Role:</strong> ${currentUserRole}</p>
                    <p><strong>Has HR:</strong> ${hasHR ? 'Yes' : 'No'}</p>
                    <p><strong>User ID:</strong> ${currentUserId}</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>View All: ${userPermissions.view_all ? '✅' : '❌'}</li>
                        <li>View Own: ${userPermissions.view_own ? '✅' : '❌'}</li>
                        <li>Generate: ${userPermissions.generate ? '✅' : '❌'}</li>
                        <li>Upload: ${userPermissions.upload ? '✅' : '❌'}</li>
                        <li>Release: ${userPermissions.release ? '✅' : '❌'}</li>
                        <li>Bulk Release: ${userPermissions.bulk_release ? '✅' : '❌'}</li>
                        <li>Delete: ${userPermissions.delete ? '✅' : '❌'}</li>
                        <li>Export: ${userPermissions.export ? '✅' : '❌'}</li>
                        <li>Download: ${userPermissions.download ? '✅' : '❌'}</li>
                    </ul>
                </div>
            `,
            icon: 'info'
        });
    }
</script>

</body>
</html>