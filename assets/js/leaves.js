// ============= PERMISSION VARIABLES =============
let currentUserRole = null;
let userPermissions = {
    view_leaves: false,
    view_employees: false,
    view_leave_types: false,
    view_balances: false,
    approve_leave: false,
    reject_leave: false,
    manage_leave_types: false,
    manage_balances: false,
    export: false
};
let hasHR = false;
let isOwner = false;
let currentUserId = null;

let leavesData = [];
let employeesData = [];
let leaveTypesData = [];
let filteredData = [];
let currentLeaveId = null;

// ============= LOAD PERMISSIONS =============
async function loadPermissions() {
    try {
        const response = await fetch('api/leaves.php?get_permissions=true');
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
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
    }
}

// ============= PERMISSION HELPER FUNCTIONS =============
function canViewLeaves() { return userPermissions.view_leaves; }
function canViewEmployees() { return userPermissions.view_employees; }
function canViewLeaveTypes() { return userPermissions.view_leave_types; }
function canViewBalances() { return userPermissions.view_balances; }
function canApproveLeave() { return userPermissions.approve_leave; }
function canRejectLeave() { return userPermissions.reject_leave; }
function canManageLeaveTypes() { return userPermissions.manage_leave_types; }
function canManageBalances() { return userPermissions.manage_balances; }
function canExport() { return userPermissions.export; }

// ============= APPLY PERMISSIONS TO UI =============
function applyPermissionBasedUI() {
    // ✅ FIXED: Huwag mag-access denied kung may kahit isang permission
    // Kung walang view_leaves, pero may view_employees, view_leave_types, or view_balances, dapat hindi mag-access denied
    
    // Hide/show Manage Leave Types button
    const manageTypesBtn = document.querySelector('button[onclick="manageLeaveTypes()"]');
    if (manageTypesBtn) {
        manageTypesBtn.style.display = canManageLeaveTypes() ? 'inline-block' : 'none';
    }
    
    // Hide/show Manage Leave Balances button
    const manageBalancesBtn = document.querySelector('button[onclick="manageLeaveBalances()"]');
    if (manageBalancesBtn) {
        manageBalancesBtn.style.display = canManageBalances() ? 'inline-block' : 'none';
    }
    
    // Hide/show Export button
    const exportBtn = document.querySelector('button[onclick="exportLeaves()"]');
    if (exportBtn) {
        exportBtn.style.display = canExport() ? 'inline-block' : 'none';
    }
    
}



// ============= DOCUMENT READY =============
document.addEventListener('DOMContentLoaded', async () => {
    await loadPermissions(); // Load permissions first
    
    // ✅ FIXED: Load data regardless of permissions (tulad sa users.php)
    await loadLeaves();
    await loadEmployees();
    await loadLeaveTypes();
    
    setupDatePicker();
    setupEventListeners();
    
    const leaveTypeForm = document.getElementById('leaveTypeForm');
    if (leaveTypeForm) {
        leaveTypeForm.addEventListener('submit', saveLeaveType);
    }
});

function setupDatePicker() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr("#filterDateRange", {
            mode: "range",
            dateFormat: "Y-m-d",
            placeholder: "Select date range",
            onChange: function(selectedDates, dateStr) {
                filterLeaves();
            }
        });
    }
}

function setupEventListeners() {
    const statusFilter = document.getElementById('filterStatus');
    if (statusFilter) {
        statusFilter.addEventListener('change', filterLeaves);
    }
    
    const typeFilter = document.getElementById('filterType');
    if (typeFilter) {
        typeFilter.addEventListener('change', filterLeaves);
    }
    
    const employeeFilter = document.getElementById('filterEmployee');
    if (employeeFilter) {
        employeeFilter.addEventListener('change', filterLeaves);
    }
    
    // Leave balance form
    const leaveBalanceForm = document.getElementById('leaveBalanceForm');
    if (leaveBalanceForm) {
        leaveBalanceForm.addEventListener('submit', saveLeaveBalance);
    }
}

async function loadLeaves() {
    try {
        const response = await fetch('api/leaves.php?action=get_leaves');
        const res = await response.json();
        
        // ✅ FIXED: Kahit walang success, dapat magkaroon ng data array
        if (res.success && res.data) {
            leavesData = res.data;
        } else {
            leavesData = []; // Empty array kung walang data
        }
        
        filteredData = [...leavesData];
        renderLeavesTable();
        updateStats();
        
    } catch(err) {
        console.error('Error fetching leaves:', err);
        leavesData = [];
        filteredData = [];
        renderLeavesTable();
        updateStats();
    }
}

function renderLeavesTable() {
    const tbody = document.getElementById('leavesTableBody');
    if (!tbody) return;
    
    tbody.innerHTML = '';

    if (!filteredData || filteredData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No leave requests found</td></tr>';
        return;
    }

    filteredData.forEach(leave => {
        // ✅ GAMITIN ANG employee_name NA GALING SA BACKEND
        const employeeName = leave.employee_name || 'Unknown Employee';
        const employeeNo = leave.employee_no || 'N/A';
        const initials = getInitials(employeeName);
        
        const leaveType = leave.type_name || 'Unknown';
        const statusBadge = getStatusBadge(leave.status);

        // Build action buttons
        let actionButtons = '';
        
        // View button - ALWAYS show
        actionButtons += `
            <button class="btn btn-sm btn-outline-info" onclick="viewLeave(${leave.id})" title="View Details">
                <i class="bi bi-eye"></i>
            </button>
        `;
        
        // Approve button - only if can approve and leave is pending
        if (canApproveLeave() && leave.status === 'Pending') {
            actionButtons += `
                <button class="btn btn-sm btn-outline-success" onclick="approveLeave(${leave.id})" title="Approve">
                    <i class="bi bi-check-circle"></i>
                </button>
            `;
        }
        
        // Reject button - only if can reject and leave is pending
        if (canRejectLeave() && leave.status === 'Pending') {
            actionButtons += `
                <button class="btn btn-sm btn-outline-danger" onclick="showRejectModal(${leave.id})" title="Reject">
                    <i class="bi bi-x-circle"></i>
                </button>
            `;
        }

        tbody.innerHTML += `
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="employee-avatar">${initials}</div>
                        <div>
                            <div class="fw-bold">${employeeName}</div>
                            <small class="text-muted">${employeeNo}</small>
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-info">${leaveType}</span></td>
                <td>
                    <div>${formatDate(leave.start_date, true)}</div>
                    <small class="text-muted">to ${formatDate(leave.end_date, true)}</small>
                </td>
                <td class="days-cell">${leave.number_of_days || 0} day(s)</td>
                <td>
                    <span class="badge ${leave.leave_with_pay === 'with_pay' ? 'bg-success' : 'bg-warning'}">
                        ${leave.leave_with_pay === 'with_pay' ? 'With Pay' : 'Without Pay'}
                    </span>
                </td>
                <td>${statusBadge}</td>
                <td><small class="text-muted">${formatDate(leave.created_at, true)}</small></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        ${actionButtons}
                    </div>
                </td>
            </tr>
        `;
    });
}

async function loadEmployees() {
    console.log('=== LOAD EMPLOYEES START ===');
    
    try {
        const response = await fetch('api/leaves.php?action=get_employees');
        console.log('Employees response status:', response.status);
        
        const res = await response.json();
        console.log('Employees data:', res);
        
        if (res.success && res.data) {
            employeesData = res.data;
            console.log('employeesData set to:', employeesData);
            
            // Populate employee dropdowns
            populateEmployeeDropdowns();
        } else {
            console.log('No employees data, setting empty array');
            employeesData = [];
        }
        
    } catch(err) {
        console.error('Error fetching employees:', err);
        employeesData = [];
    }
}

function populateEmployeeDropdowns() {
    console.log('Populating dropdowns with:', employeesData);
    
    const employeeFilter = document.getElementById('filterEmployee');
    const balanceEmployee = document.getElementById('balanceEmployee');
    const balanceEmployeeSelect = document.getElementById('balance_employee_id');
    
    [employeeFilter, balanceEmployee, balanceEmployeeSelect].forEach(select => {
        if (select) {
            select.innerHTML = '<option value="">Select Employee</option>';
            
            if (employeesData && employeesData.length > 0) {
                employeesData.forEach(emp => {
                    const option = document.createElement('option');
                    option.value = emp.id;
                    option.textContent = `${emp.employee_no} - ${emp.first_name} ${emp.last_name}`;
                    select.appendChild(option);
                });
            } else {
                console.log('No employees to populate');
            }
        }
    });
}

async function loadLeaveTypes() {
    try {
        const response = await fetch('api/leaves.php?action=get_leave_types');
        const res = await response.json();
        
        if (!res.success) {
            console.error('Failed to load leave types:', res.error);
            return;
        }
        
        leaveTypesData = res.data || [];
        
        // Populate leave type filter
        const typeFilter = document.getElementById('filterType');
        const balanceLeaveType = document.getElementById('balanceLeaveType');
        const balanceLeaveTypeSelect = document.getElementById('balance_leave_type_id');
        
        [typeFilter, balanceLeaveType, balanceLeaveTypeSelect].forEach(select => {
            if (select) {
                select.innerHTML = '<option value="">All Types</option>';
                leaveTypesData.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.id;
                    option.textContent = type.type_name;
                    select.appendChild(option.cloneNode(true));
                });
            }
        });
        
        if (leavesData.length) renderLeavesTable();
        
    } catch(err) {
        console.error('Error fetching leave types:', err);
    }
}

// ============= renderLeavesTable with proper permission checks =============
function renderLeavesTable() {
 const tbody = document.getElementById('leavesTableBody');
    if (!tbody) return;
    
    tbody.innerHTML = '';

    if (!filteredData || filteredData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No leave requests found</td></tr>';
        return;
    }

    filteredData.forEach(leave => {
        // ✅ FIXED: Hanapin ang employee sa employeesData
        const employeeId = leave.employee_id || leave.employee_db_id;
        const employee = employeesData.find(e => e.id == employeeId) || {};
        
        const employeeName = employee.first_name && employee.last_name 
            ? `${employee.first_name} ${employee.last_name}` 
            : 'Unknown Employee';
            
        const employeeNo = employee.employee_no || 'N/A';
        const initials = getInitials(employeeName);


        const statusBadge = getStatusBadge(leave.status);
        
        const leaveTypeId = leave.leave_type_id;
        const leaveTypeName = leave.leave_type;
        const leaveType = leaveTypesData.find(t => t.id == leaveTypeId) || {type_name: leaveTypeName};

        // Build action buttons based on permissions
        let actionButtons = '';
        
        // View button - ALWAYS show (tulad sa users.php)
        actionButtons += `
            <button class="btn btn-sm btn-outline-info" onclick="viewLeave(${leave.id})" title="View Details">
                <i class="bi bi-eye"></i>
            </button>
        `;
        
        // Approve button - only if can approve and leave is pending
        if (canApproveLeave() && leave.status === 'Pending') {
            actionButtons += `
                <button class="btn btn-sm btn-outline-success" onclick="approveLeave(${leave.id})" title="Approve">
                    <i class="bi bi-check-circle"></i>
                </button>
            `;
        }
        
        // Reject button - only if can reject and leave is pending
        if (canRejectLeave() && leave.status === 'Pending') {
            actionButtons += `
                <button class="btn btn-sm btn-outline-danger" onclick="showRejectModal(${leave.id})" title="Reject">
                    <i class="bi bi-x-circle"></i>
                </button>
            `;
        }

        tbody.innerHTML += `
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="employee-avatar">${initials}</div>
                        <div>
                            <div class="fw-bold">${employeeName}</div>
                            <small class="text-muted">${employeeNo}</small>
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-info">${leaveType.type_name || leaveTypeName || 'Unknown'}</span></td>
                <td>
                    <div>${formatDate(leave.start_date, true)}</div>
                    <small class="text-muted">to ${formatDate(leave.end_date, true)}</small>
                </td>
                <td class="days-cell">${leave.number_of_days || 0} day(s)</td>
                <td>
                    <span class="badge ${leave.leave_with_pay === 'with_pay' ? 'bg-success' : 'bg-warning'}">
                        ${leave.leave_with_pay === 'with_pay' ? 'With Pay' : 'Without Pay'}
                    </span>
                </td>
                <td>${statusBadge}</td>
                <td><small class="text-muted">${formatDate(leave.created_at, true)}</small></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        ${actionButtons}
                    </div>
                </td>
            </tr>
        `;
    });
}

function updateStats() {
    const totalLeavesEl = document.getElementById('totalLeaves');
    const pendingLeavesEl = document.getElementById('pendingLeaves');
    const approvedLeavesEl = document.getElementById('approvedLeaves');
    const totalDaysEl = document.getElementById('totalDays');
    
    if (totalLeavesEl) totalLeavesEl.textContent = leavesData.length;
    if (pendingLeavesEl) pendingLeavesEl.textContent = leavesData.filter(l => l.status === 'Pending').length;
    if (approvedLeavesEl) approvedLeavesEl.textContent = leavesData.filter(l => l.status === 'Approved').length;
    if (totalDaysEl) totalDaysEl.textContent = leavesData.reduce((sum, l) => sum + parseInt(l.number_of_days || 0), 0);
}

function viewLeave(id) {
    // ✅ FIXED: WALA NANG PERMISSION CHECK DITO!
    // Lahat ng may access sa page ay makakakita ng details
    
    const leave = leavesData.find(l => l.id == id);
    if (!leave) {
        Swal.fire('Error!', 'Leave not found', 'error');
        return;
    }
    
    const employeeId = leave.employee_id || leave.employee_db_id;
    const employee = employeesData.find(e => e.id == employeeId) || {};
    const name = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();
    
    const leaveTypeId = leave.leave_type_id;
    const leaveTypeName = leave.leave_type;
    const leaveType = leaveTypesData.find(t => t.id == leaveTypeId) || {type_name: leaveTypeName};
    
    // Store data in modal
    const modal = document.getElementById('viewLeaveModal');
    if (!modal) {
        Swal.fire('Error!', 'Modal element not found', 'error');
        return;
    }
    
    modal.dataset.leaveId = leave.id;
    modal.dataset.employeeId = employeeId;
    
    // Update modal content
    const avatarEl = document.getElementById('detailEmployeeAvatar');
    if (avatarEl) avatarEl.textContent = getInitials(name);
    
    const nameEl = document.getElementById('detailEmployeeName');
    if (nameEl) nameEl.textContent = name || 'Unknown Employee';
    
    const empNoEl = document.getElementById('detailEmployeeNo');
    if (empNoEl) empNoEl.textContent = employee.employee_no || 'N/A';
    
    // Update status badge
    const statusBadge = document.getElementById('detailStatusBadge');
    if (statusBadge) {
        statusBadge.className = 'status-badge ' + getStatusClass(leave.status);
        statusBadge.textContent = leave.status;
    }
    
    const leaveTypeEl = document.getElementById('detailLeaveType');
    if (leaveTypeEl) leaveTypeEl.textContent = leaveType.type_name || leaveTypeName || 'Unknown';
    
    const daysEl = document.getElementById('detailDays');
    if (daysEl) daysEl.textContent = `${leave.number_of_days || 0} day(s)`;
    
    const startDateEl = document.getElementById('detailStartDate');
    if (startDateEl) startDateEl.textContent = formatDate(leave.start_date, true);
    
    const endDateEl = document.getElementById('detailEndDate');
    if (endDateEl) endDateEl.textContent = formatDate(leave.end_date, true);
    
    const payTypeEl = document.getElementById('detailPayType');
    if (payTypeEl) payTypeEl.textContent = leave.leave_with_pay === 'with_pay' ? 'With Pay' : 'Without Pay';
    
    const deductedEl = document.getElementById('detailDeductedAmount');
    if (deductedEl) deductedEl.textContent = leave.deducted_amount ? `₱${parseFloat(leave.deducted_amount).toFixed(2)}` : '₱0.00';
    
    const reasonEl = document.getElementById('detailReason');
    if (reasonEl) reasonEl.textContent = leave.reason || '-';
    
    const createdEl = document.getElementById('detailCreatedAt');
    if (createdEl) createdEl.textContent = formatDate(leave.created_at, true);

    // Attachment handling
    const attachmentImg = document.querySelector('#detailAttachment img');
    const noAttachment = document.getElementById('noAttachment');
    
    if (leave.attachment && attachmentImg) {
        attachmentImg.src = leave.attachment;
        attachmentImg.style.display = 'block';
        if (noAttachment) noAttachment.style.display = 'none';
    } else {
        if (attachmentImg) attachmentImg.style.display = 'none';
        if (noAttachment) noAttachment.style.display = 'inline';
    }

    // Emergency info
    const emergencyInfo = document.getElementById('emergencyInfo');
    if (emergencyInfo) {
        if (leave.emergency_contact) {
            emergencyInfo.style.display = 'block';
            const contactEl = document.getElementById('detailEmergencyContact');
            if (contactEl) contactEl.textContent = leave.emergency_contact;
            
            const numberEl = document.getElementById('detailContactNumber');
            if (numberEl) numberEl.textContent = leave.contact_number || 'N/A';
        } else {
            emergencyInfo.style.display = 'none';
        }
    }

    // Approval info
    const approvalInfo = document.getElementById('approvalInfo');
    if (approvalInfo) {
        if (leave.approved_by) {
            approvalInfo.style.display = 'block';
            const approvedByEl = document.getElementById('detailApprovedBy');
            if (approvedByEl) approvedByEl.textContent = leave.approved_by_name || `User ID: ${leave.approved_by}`;
            
            const approvedAtEl = document.getElementById('detailApprovedAt');
            if (approvedAtEl) approvedAtEl.textContent = formatDate(leave.approved_at, true);
        } else {
            approvalInfo.style.display = 'none';
        }
    }

    // Rejection info
    const rejectionInfo = document.getElementById('rejectionInfo');
    if (rejectionInfo) {
        if (leave.status === 'Rejected') {
            rejectionInfo.style.display = 'block';
            const notesEl = document.getElementById('detailRejectionNotes');
            if (notesEl) notesEl.textContent = leave.rejection_notes || leave.rejection_reason || 'No reason provided';
        } else {
            rejectionInfo.style.display = 'none';
        }
    }

    // ✅ FIXED: Show approval buttons based on permissions
    const approvalButtons = document.getElementById('approvalButtons');
    if (approvalButtons) {
        if (leave.status === 'Pending') {
            let hasPermission = false;
            let buttonHTML = '';
            
            if (canApproveLeave()) {
                hasPermission = true;
                buttonHTML += `
                    <button class="btn btn-success" onclick="approveLeave(${leave.id})">
                        <i class="bi bi-check-circle me-2"></i>Approve
                    </button>
                `;
            }
            
            if (canRejectLeave()) {
                hasPermission = true;
                buttonHTML += `
                    <button class="btn btn-danger" onclick="showRejectModal(${leave.id})">
                        <i class="bi bi-x-circle me-2"></i>Reject
                    </button>
                `;
            }
            
            if (hasPermission) {
                approvalButtons.style.display = 'flex';
                approvalButtons.innerHTML = buttonHTML;
            } else {
                // ✅ VIEW ACCESS: No buttons, pero visible pa rin ang details
                approvalButtons.style.display = 'none';
                approvalButtons.innerHTML = '';
            }
        } else {
            approvalButtons.style.display = 'none';
            approvalButtons.innerHTML = '';
        }
    }

    // Show modal
    try {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    } catch (e) {
        console.error('Error showing modal:', e);
        Swal.fire('Error!', 'Failed to show modal', 'error');
    }
}

// ============= APPROVE LEAVE =============
function approveLeave(leaveId) {
    if (!canApproveLeave()) {
        Swal.fire('Access Denied', 'You do not have permission to approve leaves', 'error');
        return;
    }
    
    currentLeaveId = leaveId;
    
    Swal.fire({
        title: 'Approve Leave Request?',
        text: 'Are you sure you want to approve this leave request?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('api/leaves.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    approve_leave: true, 
                    leave_id: leaveId 
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Approved!', data.message, 'success');
                    
                    // Close modal if open
                    const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewLeaveModal'));
                    if (viewModal) viewModal.hide();
                    
                    loadLeaves();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            })
            .catch(err => {
                console.error('Error approving leave:', err);
                Swal.fire('Error!', 'Failed to approve leave', 'error');
            });
        }
    });
}

// ============= SHOW REJECT MODAL =============
function showRejectModal(leaveId) {
    if (!canRejectLeave()) {
        Swal.fire('Access Denied', 'You do not have permission to reject leaves', 'error');
        return;
    }
    
    currentLeaveId = leaveId;
    const rejectInput = document.getElementById('rejectLeaveId');
    if (rejectInput) rejectInput.value = leaveId;
    
    const reasonInput = document.getElementById('rejectReason');
    if (reasonInput) reasonInput.value = '';
    
    try {
        const rejectModal = new bootstrap.Modal(document.getElementById('rejectLeaveModal'));
        rejectModal.show();
    } catch (e) {
        console.error('Error showing reject modal:', e);
        Swal.fire('Error!', 'Failed to show reject modal', 'error');
    }
}

// ============= REJECT LEAVE =============
function rejectLeave() {
    if (!canRejectLeave()) {
        Swal.fire('Access Denied', 'You do not have permission to reject leaves', 'error');
        return;
    }
    
    const leaveId = document.getElementById('rejectLeaveId')?.value;
    const reason = document.getElementById('rejectReason')?.value;
    
    if (!leaveId) {
        Swal.fire('Error!', 'Leave ID not found', 'error');
        return;
    }
    
    if (!reason || !reason.trim()) {
        Swal.fire('Error!', 'Please provide a reason for rejection', 'error');
        return;
    }

    fetch('api/leaves.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            reject_leave: true, 
            leave_id: leaveId,
            reason: reason.trim()
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Rejected!', data.message, 'success');
            
            // Close modals
            const rejectModal = bootstrap.Modal.getInstance(document.getElementById('rejectLeaveModal'));
            if (rejectModal) rejectModal.hide();
            
            const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewLeaveModal'));
            if (viewModal) viewModal.hide();
            
            loadLeaves();
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    })
    .catch(err => {
        console.error('Error rejecting leave:', err);
        Swal.fire('Error!', 'Failed to reject leave', 'error');
    });
}

function filterLeaves() {
    const status = document.getElementById('filterStatus').value;
    const type = document.getElementById('filterType').value;
    const employee = document.getElementById('filterEmployee').value;
    const dateRange = document.getElementById('filterDateRange').value;

    filteredData = leavesData.filter(l => {
        if (status && l.status !== status) return false;
        if (type) {
            const leaveTypeId = l.leave_type_id;
            const leaveType = leaveTypesData.find(t => t.id == leaveTypeId);
            if (!leaveType || leaveType.id != type) {
                if (l.leave_type !== type) return false;
            }
        }
        if (employee) {
            const employeeId = l.employee_id || l.employee_db_id;
            if (employeeId != employee) return false;
        }
        if (dateRange) {
            const [start, end] = dateRange.split(' to ');
            const leaveStart = new Date(l.start_date);
            if (start && end && (leaveStart < new Date(start) || leaveStart > new Date(end))) return false;
        }
        return true;
    });

    renderLeavesTable();
}

// ============= LEAVE TYPES MANAGEMENT =============
async function manageLeaveTypes() {
    if (!canManageLeaveTypes()) {
        let message = 'You do not have permission to manage leave types.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'You are in oversight mode.';
        }
        
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    try {
        // Populate leave type dropdown for filtering if needed
        const filterTypeSelect = document.getElementById('filterType');
        if (filterTypeSelect) {
            filterTypeSelect.innerHTML = '<option value="">All Types</option>';
        }
        
        // Load leave types
        const response = await fetch('api/leaves.php?action=get_leave_types');
        const res = await response.json();
        
        if (!res.success) {
            Swal.fire('Error!', res.error, 'error');
            return;
        }
        
        // Populate filter dropdowns if they exist
        if (filterTypeSelect && res.data && res.data.length > 0) {
            res.data.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.type_name;
                filterTypeSelect.appendChild(option);
            });
        }
        
        // Also populate leave type dropdown in leave balance modal if it exists
        const balanceLeaveTypeSelect = document.getElementById('balanceLeaveType');
        if (balanceLeaveTypeSelect) {
            balanceLeaveTypeSelect.innerHTML = '<option value="">All Types</option>';
            res.data.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.type_name;
                balanceLeaveTypeSelect.appendChild(option);
            });
        }
        
        // Populate leave type dropdown in add/edit leave type modal
        const balanceLeaveTypeModalSelect = document.getElementById('balance_leave_type_id');
        if (balanceLeaveTypeModalSelect) {
            balanceLeaveTypeModalSelect.innerHTML = '<option value="">Select Leave Type</option>';
            res.data.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.type_name;
                balanceLeaveTypeModalSelect.appendChild(option);
            });
        }
        
        // Render leave types table
        const tbody = document.getElementById('leaveTypesTableBody');
        if (!tbody) {
            console.error('Cannot find leaveTypesTableBody element');
            Swal.fire('Error!', 'Page elements not found', 'error');
            return;
        }
        
        if (!res.data || res.data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                            <p class="mt-2">No leave types found</p>
                            <button class="btn btn-sm btn-primary mt-2" onclick="showAddLeaveTypeModal()">
                                <i class="bi bi-plus"></i> Add First Leave Type
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        } else {
            tbody.innerHTML = '';
            
            res.data.forEach(type => {
                // Build action buttons based on permissions
                let actionButtons = '';
                
                if (canManageLeaveTypes()) {
                    actionButtons += `
                        <button class="btn btn-outline-primary" onclick="editLeaveType(${type.id})" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="deleteLeaveType(${type.id})" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                } else {
                    actionButtons = '<span class="badge bg-secondary">View Only</span>';
                }
                
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold">${type.type_name}</div>
                            <small class="text-muted">${type.description || ''}</small>
                        </td>
                        <td><code>${type.code}</code></td>
                        <td>${type.max_days_per_year}</td>
                        <td>
                            <span class="badge ${type.with_pay ? 'bg-success' : 'bg-secondary'}">
                                ${type.with_pay ? 'Yes' : 'No'}
                            </span>
                        </td>
                        <td>
                            <span class="badge ${type.requires_attachment ? 'bg-warning' : 'bg-secondary'}">
                                ${type.requires_attachment ? 'Yes' : 'No'}
                            </span>
                        </td>
                        <td>
                            <span class="badge ${type.is_active ? 'bg-success' : 'bg-danger'}">
                                ${type.is_active ? 'Active' : 'Inactive'}
                            </span>
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
        
        // Setup form event listeners if not already set up
        const leaveTypeForm = document.getElementById('leaveTypeForm');
        if (leaveTypeForm && !leaveTypeForm.hasListener) {
            leaveTypeForm.addEventListener('submit', saveLeaveType);
            leaveTypeForm.hasListener = true;
        }
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('manageLeaveTypesModal'));
        modal.show();
        
    } catch(err) {
        console.error('Error loading leave types:', err);
        Swal.fire('Error!', 'Failed to load leave types', 'error');
    }
}

function showAddLeaveTypeModal() {
    if (!canManageLeaveTypes()) {
        Swal.fire('Access Denied', 'You do not have permission to add leave types', 'error');
        return;
    }
    
    // Get the manage modal
    const manageModal = bootstrap.Modal.getInstance(document.getElementById('manageLeaveTypesModal'));
    
    // Reset form
    document.getElementById('leaveTypeForm').reset();
    document.getElementById('leave_type_id').value = '';
    document.getElementById('leaveTypeModalTitle').textContent = 'Add Leave Type';
    
    // Set default values
    document.getElementById('max_days_per_year').value = 15;
    document.getElementById('max_carry_over').value = 5;
    document.getElementById('with_pay').checked = true;
    document.getElementById('requires_attachment').checked = false;
    document.getElementById('is_active').checked = true;
    document.getElementById('description').value = '';
    
    // Show the add modal
    const addModal = new bootstrap.Modal(document.getElementById('leaveTypeModal'));
    addModal.show();
    
    // Close the manage modal if it's open
    if (manageModal) {
        manageModal.hide();
    }
}

async function saveLeaveType(e) {
    e.preventDefault();
    
    if (!canManageLeaveTypes()) {
        Swal.fire('Access Denied', 'You do not have permission to save leave types', 'error');
        return;
    }
    
    // Collect form data
    const formData = {
        type_name: document.getElementById('type_name').value.trim(),
        code: document.getElementById('code').value.trim().toUpperCase(),
        max_days_per_year: parseFloat(document.getElementById('max_days_per_year').value) || 15,
        max_carry_over: parseFloat(document.getElementById('max_carry_over').value) || 5,
        with_pay: document.getElementById('with_pay').checked ? 1 : 0,
        requires_attachment: document.getElementById('requires_attachment').checked ? 1 : 0,
        is_active: document.getElementById('is_active').checked ? 1 : 0,
        description: document.getElementById('description').value.trim()
    };
    
    const typeId = document.getElementById('leave_type_id').value;
    
    // Validate required fields
    if (!formData.type_name) {
        Swal.fire('Error!', 'Type Name is required', 'error');
        return;
    }
    
    if (!formData.code) {
        Swal.fire('Error!', 'Code is required', 'error');
        return;
    }
    
    // Validate code format (uppercase letters only)
    if (!/^[A-Z]+$/.test(formData.code)) {
        Swal.fire('Error!', 'Code should contain only uppercase letters (e.g., VL, SL, ML)', 'error');
        return;
    }
    
    try {
        // Show loading
        Swal.fire({
            title: 'Saving...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Determine if we're adding or updating
        if (typeId) {
            formData.id = typeId;
            formData.update_leave_type = true;
        } else {
            formData.add_leave_type = true;
        }
        
        // Send request
        const response = await fetch('api/leaves.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        });
        
        const res = await response.json();
        
        Swal.close();
        
        if (!res.success) {
            Swal.fire('Error!', res.error, 'error');
            return;
        }
        
        // Close the add modal
        const addModal = bootstrap.Modal.getInstance(document.getElementById('leaveTypeModal'));
        if (addModal) {
            addModal.hide();
        }
        
        // Show success message
        await Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: typeId ? 'Leave type updated successfully' : 'Leave type added successfully',
            timer: 1500,
            showConfirmButton: false
        });
        
        // Refresh and reopen manage modal
        setTimeout(() => {
            manageLeaveTypes();
        }, 300);
        
    } catch(err) {
        console.error('Error saving leave type:', err);
        Swal.fire('Error!', 'Failed to save leave type: ' + err.message, 'error');
    }
}

async function editLeaveType(typeId) {
    if (!canManageLeaveTypes()) {
        Swal.fire('Access Denied', 'You do not have permission to edit leave types', 'error');
        return;
    }
    
    try {
        // Show loading
        Swal.fire({
            title: 'Loading...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Fetch leave type details
        const response = await fetch(`api/leaves.php?action=get_leave_type_details&id=${typeId}`);
        const res = await response.json();
        
        Swal.close();
        
        if (!res.success) {
            Swal.fire('Error!', res.error, 'error');
            return;
        }
        
        const type = res.data;
        
        // Fill form with leave type data
        document.getElementById('leave_type_id').value = type.id;
        document.getElementById('type_name').value = type.type_name || '';
        document.getElementById('code').value = type.code || '';
        document.getElementById('max_days_per_year').value = type.max_days_per_year || 15;
        document.getElementById('max_carry_over').value = type.max_carry_over || 5;
        document.getElementById('with_pay').checked = type.with_pay == 1;
        document.getElementById('requires_attachment').checked = type.requires_attachment == 1;
        document.getElementById('is_active').checked = type.is_active == 1;
        document.getElementById('description').value = type.description || '';
        
        // Update modal title
        document.getElementById('leaveTypeModalTitle').textContent = 'Edit Leave Type';
        
        // Get current manage modal
        const manageModal = bootstrap.Modal.getInstance(document.getElementById('manageLeaveTypesModal'));
        
        // Show edit modal
        const editModal = new bootstrap.Modal(document.getElementById('leaveTypeModal'));
        editModal.show();
        
        // Close manage modal if it's open
        if (manageModal) {
            manageModal.hide();
        }
        
    } catch(err) {
        console.error('Error loading leave type details:', err);
        Swal.fire('Error!', 'Failed to load leave type details', 'error');
    }
}

async function deleteLeaveType(typeId) {
    if (!canManageLeaveTypes()) {
        Swal.fire('Access Denied', 'You do not have permission to delete leave types', 'error');
        return;
    }
    
    try {
        const result = await Swal.fire({
            title: 'Are you sure?',
            text: "This leave type will be permanently deleted!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        });
        
        if (!result.isConfirmed) return;
        
        // Show loading
        Swal.fire({
            title: 'Deleting...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Send delete request
        const response = await fetch('api/leaves.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                delete_leave_type: true,
                id: typeId
            })
        });
        
        const res = await response.json();
        
        Swal.close();
        
        if (!res.success) {
            Swal.fire('Error!', res.error, 'error');
            return;
        }
        
        // Show success message
        await Swal.fire({
            icon: 'success',
            title: 'Deleted!',
            text: 'Leave type deleted successfully',
            timer: 1500,
            showConfirmButton: false
        });
        
        // Refresh the table
        await manageLeaveTypes();
        
    } catch(err) {
        console.error('Error deleting leave type:', err);
        Swal.fire('Error!', 'Failed to delete leave type', 'error');
    }
}

// ============= LEAVE BALANCES MANAGEMENT =============
async function manageLeaveBalances() {
    if (!canManageBalances()) {
        let message = 'You do not have permission to manage leave balances.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'You are in oversight mode.';
        }
        
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    try {
        // Populate year dropdown
        const yearSelect = document.getElementById('balanceYear');
        yearSelect.innerHTML = '';
        const currentYear = new Date().getFullYear();
        for (let i = currentYear - 5; i <= currentYear + 5; i++) {
            const option = document.createElement('option');
            option.value = i;
            option.textContent = i;
            if (i === currentYear) option.selected = true;
            yearSelect.appendChild(option);
        }
        
        // Populate employee dropdowns
        const employeeSelect = document.getElementById('balanceEmployee');
        const specificEmployeeSelect = document.getElementById('balance_employee_id');
        
        employeeSelect.innerHTML = '<option value="">All Employees</option><option value="all">All Employees (Bulk)</option>';
        specificEmployeeSelect.innerHTML = '<option value="">Select Employee</option>';
        
        if (employeesData && employeesData.length > 0) {
            employeesData.forEach(emp => {
                const optionText = `${emp.employee_no} - ${emp.first_name} ${emp.last_name}`;
                
                // For filter dropdown
                const filterOption = document.createElement('option');
                filterOption.value = emp.id;
                filterOption.textContent = optionText;
                employeeSelect.appendChild(filterOption);
                
                // For specific employee selection
                const specificOption = document.createElement('option');
                specificOption.value = emp.id;
                specificOption.textContent = optionText;
                specificEmployeeSelect.appendChild(specificOption);
            });
        }
        
        // Setup radio button toggle
        document.querySelectorAll('input[name="apply_to"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const specificSection = document.getElementById('specificEmployeeSection');
                const employeeSelect = document.getElementById('balance_employee_id');
                
                if (this.value === 'specific') {
                    specificSection.style.display = 'block';
                    employeeSelect.required = true;
                } else {
                    specificSection.style.display = 'none';
                    employeeSelect.required = false;
                    employeeSelect.value = '';
                }
            });
        });
        
        // Setup forms
        document.getElementById('leaveBalanceForm').addEventListener('submit', saveLeaveBalance);
        document.getElementById('editBalanceForm').addEventListener('submit', updateLeaveBalance);
        
        // Setup filter change listeners
        document.getElementById('balanceEmployee').addEventListener('change', function() {
            const bulkSection = document.getElementById('bulkActionSection');
            if (this.value === 'all') {
                bulkSection.style.display = 'block';
            } else {
                bulkSection.style.display = 'none';
            }
            loadLeaveBalances();
        });
        
        // Load initial data
        await loadLeaveBalances();
        
        // Show modal
        new bootstrap.Modal(document.getElementById('manageLeaveBalancesModal')).show();
        
    } catch(err) {
        console.error('Error initializing leave balances:', err);
        Swal.fire('Error!', 'Failed to initialize leave balances', 'error');
    }
}

async function loadLeaveBalances() {
    if (!canViewBalances()) {
        console.log('No permission to view leave balances');
        return;
    }
    
    try {
        const year = document.getElementById('balanceYear').value;
        const leaveType = document.getElementById('balanceLeaveType').value;
        const employee = document.getElementById('balanceEmployee').value;
        
        let url = `api/leaves.php?action=get_leave_balances&year=${year}`;
        if (leaveType) url += `&leave_type=${leaveType}`;
        if (employee && employee !== 'all') url += `&employee=${employee}`;
        
        const response = await fetch(url);
        const res = await response.json();
        
        if (!res.success) {
            console.error('Failed to load leave balances:', res.error);
            showNoDataMessage();
            return;
        }
        
        renderLeaveBalancesTable(res.data);
        
    } catch(err) {
        console.error('Error loading leave balances:', err);
        showNoDataMessage();
    }
}

function renderLeaveBalancesTable(balances) {
    const tbody = document.getElementById('leaveBalancesTableBody');
    if (!tbody) return;
    
    if (!balances || balances.length === 0) {
        showNoDataMessage();
        return;
    }
    
    tbody.innerHTML = '';
    
    balances.forEach(balance => {
        const balanceValue = parseFloat(balance.balance || 0);
        const balanceClass = balanceValue < 0 ? 'text-danger' : (balanceValue > 0 ? 'text-success' : '');
        
        // Build action buttons based on permissions
        let actionButtons = '';
        
        if (canManageBalances()) {
            actionButtons += `
                <button class="btn btn-outline-primary btn-sm" onclick="editSingleBalance(${balance.id}, '${balance.first_name || ''} ${balance.last_name || ''}', '${balance.type_name || ''}', ${balance.year}, ${balance.total_entitled}, ${balance.used}, ${balance.carried_over}, ${balance.balance})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="deleteLeaveBalance(${balance.id})">
                    <i class="bi bi-trash"></i>
                </button>
            `;
        } else {
            actionButtons = '<span class="badge bg-secondary">View Only</span>';
        }
        
        tbody.innerHTML += `
            <tr>
                <td>
                    <div class="fw-bold">${balance.first_name || ''} ${balance.last_name || ''}</div>
                    <small class="text-muted">${balance.employee_no || 'N/A'}</small>
                </td>
                <td>${balance.type_name || 'Unknown'}</td>
                <td>${balance.year}</td>
                <td>${balance.total_entitled || 0}</td>
                <td>${balance.used || 0}</td>
                <td>
                    <span class="fw-bold ${balanceClass}">
                        ${balance.balance || 0}
                    </span>
                </td>
                <td>${balance.carried_over || 0}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        ${actionButtons}
                    </div>
                </td>
            </tr>
        `;
    });
}

function showNoDataMessage() {
    const tbody = document.getElementById('leaveBalancesTableBody');
    if (!tbody) return;
    
    tbody.innerHTML = `
        <tr>
            <td colspan="8" class="text-center py-4">
                <div class="text-muted">
                    <i class="bi bi-calendar-x fs-1"></i>
                    <p class="mt-2">No leave balances found</p>
                    <small>Click "Add Balance" to create new balances</small>
                </div>
            </td>
        </tr>
    `;
}

function showAddLeaveBalanceModal() {
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to add leave balances', 'error');
        return;
    }
    
    document.getElementById('leaveBalanceModalTitle').textContent = 'Add Leave Balance';
    document.getElementById('leaveBalanceForm').reset();
    document.getElementById('balance_id').value = '';
    document.getElementById('apply_all').checked = true;
    document.getElementById('specificEmployeeSection').style.display = 'none';
    document.getElementById('balance_employee_id').required = false;
    
    new bootstrap.Modal(document.getElementById('leaveBalanceModal')).show();
}

function editSingleBalance(id, employeeName, leaveTypeName, year, entitled, used, carried, balance) {
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to edit leave balances', 'error');
        return;
    }
    
    document.getElementById('edit_balance_id').value = id;
    document.getElementById('editEmployeeName').textContent = employeeName;
    document.getElementById('editLeaveTypeName').textContent = leaveTypeName;
    document.getElementById('editYear').textContent = year;
    document.getElementById('edit_total_entitled').value = entitled;
    document.getElementById('edit_used').value = used;
    document.getElementById('edit_carried_over').value = carried;
    document.getElementById('edit_balance').value = balance;
    document.getElementById('edit_notes').value = '';
    
    new bootstrap.Modal(document.getElementById('editBalanceModal')).show();
}

async function saveLeaveBalance(e) {
    e.preventDefault();
    
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to save leave balances', 'error');
        return;
    }
    
    const applyTo = document.querySelector('input[name="apply_to"]:checked').value;
    const formData = {
        id: document.getElementById('balance_id').value,
        apply_to: applyTo,
        employee_id: applyTo === 'specific' ? document.getElementById('balance_employee_id').value : null,
        leave_type_id: document.getElementById('balance_leave_type_id').value,
        year: document.getElementById('balance_year').value,
        total_entitled: document.getElementById('total_entitled').value,
        used: document.getElementById('used').value,
        carried_over: document.getElementById('carried_over').value,
        notes: document.getElementById('balance_notes').value
    };
    
    const isEdit = formData.id !== '';
    const action = isEdit ? 'update_leave_balance' : 'add_leave_balance';
    
    try {
        const response = await fetch('api/leaves.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                [action]: true,
                ...formData
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire('Success!', data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('leaveBalanceModal')).hide();
            loadLeaveBalances();
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    } catch(err) {
        console.error(err);
        Swal.fire('Error!', 'Failed to save leave balance', 'error');
    }
}

async function updateLeaveBalance(e) {
    e.preventDefault();
    
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to update leave balances', 'error');
        return;
    }
    
    const formData = {
        id: document.getElementById('edit_balance_id').value,
        total_entitled: document.getElementById('edit_total_entitled').value,
        used: document.getElementById('edit_used').value,
        carried_over: document.getElementById('edit_carried_over').value,
        notes: document.getElementById('edit_notes').value
    };
    
    try {
        const response = await fetch('api/leaves.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                update_single_balance: true,
                ...formData
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire('Success!', data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('editBalanceModal')).hide();
            loadLeaveBalances();
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    } catch(err) {
        console.error(err);
        Swal.fire('Error!', 'Failed to update leave balance', 'error');
    }
}

function deleteLeaveBalance(id) {
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to delete leave balances', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Delete Leave Balance?',
        text: 'This will remove this balance record. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel'
    }).then(async (result) => {
        if (result.isConfirmed) {
            try {
                const response = await fetch('api/leaves.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ delete_leave_balance: true, id: id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success');
                    loadLeaveBalances();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            } catch(err) {
                Swal.fire('Error!', 'Failed to delete leave balance', 'error');
            }
        }
    });
}

async function applyBulkAction() {
    if (!canManageBalances()) {
        Swal.fire('Access Denied', 'You do not have permission to apply bulk actions', 'error');
        return;
    }
    
    const year = document.getElementById('balanceYear').value;
    const leaveType = document.getElementById('balanceLeaveType').value;
    const days = document.getElementById('bulkDays').value;
    const actionType = document.getElementById('bulkActionType').value;
    
    if (!leaveType) {
        Swal.fire('Error!', 'Please select a leave type first', 'error');
        return;
    }
    
    if (!days || days < 0) {
        Swal.fire('Error!', 'Please enter a valid number of days', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Apply Bulk Action?',
        text: `This will ${actionType === 'set' ? 'set' : 'add'} ${days} days to ALL active employees for the selected leave type in ${year}.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, apply to all',
        cancelButtonText: 'Cancel'
    }).then(async (result) => {
        if (result.isConfirmed) {
            try {
                const response = await fetch('api/leaves.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        bulk_update_balances: true,
                        year: year,
                        leave_type_id: leaveType,
                        days: days,
                        action_type: actionType
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success');
                    loadLeaveBalances();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            } catch(err) {
                Swal.fire('Error!', 'Failed to apply bulk action', 'error');
            }
        }
    });
}

// ============= EXPORT FUNCTION =============
function exportLeaves() {
    if (!canExport()) {
        let message = 'You do not have permission to export leave data.';
        if (currentUserRole === 'ClinicAdmin' && hasHR) {
            message = 'HR manages exports. You are in oversight mode.';
        }
        Swal.fire('Access Denied', message, 'error');
        return;
    }
    
    // Create CSV content
    let csv = 'Employee,Leave Type,Start Date,End Date,Days,Pay Type,Status,Reason\n';
    
    leavesData.forEach(leave => {
        const employeeId = leave.employee_id || leave.employee_db_id;
        const employee = employeesData.find(e => e.id == employeeId) || {};
        const employeeName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();
        
        const leaveTypeId = leave.leave_type_id;
        const leaveTypeName = leave.leave_type;
        const leaveType = leaveTypesData.find(t => t.id == leaveTypeId) || {type_name: leaveTypeName};
        
        csv += `"${employeeName}","${leaveType.type_name || leaveTypeName}","${leave.start_date}","${leave.end_date}",${leave.number_of_days},"${leave.leave_with_pay === 'with_pay' ? 'With Pay' : 'Without Pay'}","${leave.status}","${leave.reason || ''}"\n`;
    });
    
    // Create and download file
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `leaves_export_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    Swal.fire('Exported!', 'Leave data exported successfully', 'success');
}

// ============= HELPER FUNCTIONS =============
function getStatusBadge(status) {
    const classes = {
        'Pending': 'status-pending',
        'Approved': 'status-approved',
        'Rejected': 'status-rejected',
        'Cancelled': 'status-cancelled'
    };
    const badgeClass = classes[status] || '';
    return `<span class="status-badge ${badgeClass}">${status}</span>`;
}

function getStatusClass(status) {
    const classes = {
        'Pending': 'status-pending',
        'Approved': 'status-approved',
        'Rejected': 'status-rejected',
        'Cancelled': 'status-cancelled'
    };
    return classes[status] || '';
}

function getInitials(name) {
    if (!name || name === ' ') return '?';
    return name.split(' ').map(word => word[0]).join('').toUpperCase().substring(0, 2);
}

function formatDate(dateStr, dateOnly=false) {
    if (!dateStr) return 'N/A';
    try {
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return 'Invalid Date';
        if (dateOnly) return date.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
        return date.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
    } catch(e) {
        return 'Invalid Date';
    }
}

// ============= DEBUG FUNCTION =============
function showPermissions() {
    Swal.fire({
        title: 'Your Leave Management Permissions',
        html: `
            <div class="text-start">
                <p><strong>Role:</strong> ${currentUserRole}</p>
                <p><strong>Has HR:</strong> ${hasHR ? 'Yes' : 'No'}</p>
                <p><strong>User ID:</strong> ${currentUserId}</p>
                <p><strong>Permissions:</strong></p>
                <ul>
                    <li>View Leaves: ${userPermissions.view_leaves ? '✅' : '❌'}</li>
                    <li>View Employees: ${userPermissions.view_employees ? '✅' : '❌'}</li>
                    <li>View Leave Types: ${userPermissions.view_leave_types ? '✅' : '❌'}</li>
                    <li>View Balances: ${userPermissions.view_balances ? '✅' : '❌'}</li>
                    <li>Approve Leave: ${userPermissions.approve_leave ? '✅' : '❌'}</li>
                    <li>Reject Leave: ${userPermissions.reject_leave ? '✅' : '❌'}</li>
                    <li>Manage Leave Types: ${userPermissions.manage_leave_types ? '✅' : '❌'}</li>
                    <li>Manage Balances: ${userPermissions.manage_balances ? '✅' : '❌'}</li>
                    <li>Export: ${userPermissions.export ? '✅' : '❌'}</li>
                </ul>
            </div>
        `,
        icon: 'info'
    });
}

// Modal cleanup
const leaveBalanceModal = document.getElementById('leaveBalanceModal');
if (leaveBalanceModal) {
    leaveBalanceModal.addEventListener('hidden.bs.modal', function () {
        document.body.focus();
    });
}