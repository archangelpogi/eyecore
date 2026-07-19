let currentPayrollId = null;
let selectedPayrolls = new Set();
let selectedPayrollsTotal = 0;
let currentApprovalModal = null;
let permissions = {
    view: false,
    create: false,
    edit: false,
    delete: false,
    approve: false,
    reject: false
};

let currentUserId = null;
let currentUserRole = null;
let currentUserName = null;


async function loadPermissions() {
    try {
        const response = await fetch('api/payroll-approval.php?get_permissions=true');
        const data = await response.json();
        
        if (data.success) {
            // Set permissions from API
            permissions.view = data.data.permissions.view || false;
            permissions.create = data.data.permissions.create || false;
            permissions.edit = data.data.permissions.edit || false;
            permissions.delete = data.data.permissions.delete || false;
            permissions.approve = data.data.permissions.approve || false;
            permissions.reject = data.data.permissions.reject || false;
            
            currentUserId = data.data.user_id;
            currentUserRole = data.data.role;
            currentUserName = data.data.user_name;
            
            console.log('Permissions loaded:', permissions);
            
            // Apply permission-based UI changes
            applyPermissionsToUI();
            
            return true;
        }
        return false;
    } catch (error) {
        console.error('Error loading permissions:', error);
        return false;
    }
}

function applyPermissionsToUI() {
    if (!permissions) {
        console.warn('Permissions not loaded yet');
        return;
    }
    
    // Approve button - use permissions.approve
    if (!permissions.approve) {
        document.querySelectorAll('[onclick*="confirmApprovePayroll"]').forEach(btn => {
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            }
        });
        const approveBtn = document.getElementById('approvePayrollBtn');
        if (approveBtn) approveBtn.style.display = 'none';
    }
    
    // Reject button - use permissions.reject
    if (!permissions.reject) {
        document.querySelectorAll('[onclick*="confirmRejectPayroll"]').forEach(btn => {
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            }
        });
        const rejectBtn = document.getElementById('rejectPayrollBtn');
        if (rejectBtn) rejectBtn.style.display = 'none';
    }
    
    // View permission - hide tables if no view
    if (!permissions.view) {
        document.querySelectorAll('.table-container').forEach(el => {
            el.style.display = 'none';
        });
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    // First load permissions from API
    const permissionsLoaded = await loadPermissions();
    
    // If permissions failed to load, try to use PHP-passed permissions
    if (!permissionsLoaded && typeof window.permissions !== 'undefined') {
        permissions = window.permissions;
        currentUserId = window.currentUserId;
        currentUserRole = window.currentUserRole;
        currentUserName = window.currentUserName;
        console.log('Using PHP-passed permissions:', permissions);
    }
    
    // Check if user has view permission
    if (!permissions.view) {
        showAccessDenied();
        return;
    }
    
    // Initialize all functions
    loadDashboardStats();
    loadPendingApprovals();
    loadApprovedPayrolls();
    loadReleasedPayrolls();
    loadRemittances();
    
    // Set current month
    const now = new Date();
    const currentMonthEl = document.getElementById('currentMonth');
    if (currentMonthEl) {
        currentMonthEl.textContent = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    
    // Initialize datepickers
    if (typeof flatpickr !== 'undefined') {
        flatpickr("#releasedMonth", { dateFormat: "Y-m" });
        flatpickr("#remittancePeriod", { dateFormat: "Y-m" });
        flatpickr("#approvedPeriod", { dateFormat: "Y-m" });
    }
    
    // Setup checkbox event
    const selectAllApproved = document.getElementById('selectAllApproved');
    if (selectAllApproved) {
        selectAllApproved.addEventListener('change', function() {
            if (!permissions.approve) {
                this.checked = false;
                Swal.fire('Access Denied', 'You don\'t have permission to approve payrolls', 'error');
                return;
            }
            const checkboxes = document.querySelectorAll('#approvedTableBody input[type="checkbox"]');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                if (this.checked) {
                    selectedPayrolls.add(cb.value);
                } else {
                    selectedPayrolls.delete(cb.value);
                }
            });
            calculateSelectedTotal();
        });
    }
    
    // Add tab event listeners
    const readyTab = document.getElementById('release-tab');
    if (readyTab) {
        readyTab.addEventListener('shown.bs.tab', function() {
            loadReadyForRelease();
        });
    }
    
    const releasedTab = document.getElementById('released-tab');
    if (releasedTab) {
        releasedTab.addEventListener('shown.bs.tab', function() {
            loadReleasedPayrolls();
        });
    }
    
    // Initialize select all for ready tab
    const selectAllReady = document.getElementById('selectAllReady');
    if (selectAllReady) {
        selectAllReady.addEventListener('change', function() {
            if (!permissions.approve) {
                this.checked = false;
                Swal.fire('Access Denied', 'You don\'t have permission to release payrolls', 'error');
                return;
            }
            const checkboxes = document.querySelectorAll('#releaseTableBody .release-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                const payrollId = parseInt(cb.value);
                const row = document.getElementById(`ready-row-${payrollId}`);
                
                if (this.checked) {
                    selectedForRelease.add(payrollId);
                    if (row) row.classList.add('table-success');
                } else {
                    selectedForRelease.delete(payrollId);
                    if (row) row.classList.remove('table-success');
                }
            });
            updateReleaseSummary();
        });
    }
    
    // Initialize search for ready tab
    const readySearch = document.getElementById('readySearch');
    if (readySearch) {
        readySearch.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                loadReadyForRelease();
            }
        });
    }
    
    // Set default release date to today
    const releaseDateInput = document.getElementById('releaseDate');
    if (releaseDateInput) {
        releaseDateInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Add modal close event listener
    const modal = document.getElementById('payrollDetailsModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            currentPayrollId = null;
        });
    }
    
    // Add government tab listener
    const governmentTab = document.getElementById('government-tab');
    if (governmentTab) {
        governmentTab.addEventListener('shown.bs.tab', function() {
            loadRemittances();
        });
    }
    
    // Apply UI permissions (disable buttons based on permissions)
    applyPermissionsToUI();
});

function loadDashboardStats() {
    fetch('api/payroll-approval.php?action=dashboard_stats')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pendingApprovalCount').textContent = data.pending_approval || 0;
                document.getElementById('approvedPayrollCount').textContent = data.approved_payrolls || 0;
                document.getElementById('monthlyTotalAmount').textContent = formatCurrency(data.monthly_total || 0);
                document.getElementById('releasedThisMonth').textContent = data.released_this_month || 0;
                document.getElementById('approvalBadge').textContent = data.pending_approval || 0;
            }
        });
}

function loadPendingApprovals() {
    fetch('api/payroll-approval.php?action=pending_approvals')
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('approvalTableBody');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-check2-all fs-1"></i>
                            <p class="mt-2">No pending approvals</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            data.forEach(payroll => {
                const daysPending = Math.ceil((new Date() - new Date(payroll.submitted_at || payroll.generated_at)) / (1000 * 60 * 60 * 24));
                
                tbody.innerHTML += `
                    <tr>
                        <td class="fw-bold">PAY-${String(payroll.id).padStart(5, '0')}</td>
                        <td>
                            <div class="fw-bold">${payroll.employee_name}</div>
                            <small class="text-muted">${payroll.employee_no}</small>
                        </td>
                        <td>
                            <div>${payroll.payroll_period}</div>
                            <small class="text-muted">${formatDate(payroll.period_start, true)} - ${formatDate(payroll.period_end, true)}</small>
                        </td>
                        <td class="fw-bold">${formatCurrency(payroll.gross_pay)}</td>
                        <td class="fw-bold text-success">${formatCurrency(payroll.net_pay)}</td>
                        <td>
                            <span class="badge ${daysPending > 3 ? 'bg-danger' : 'bg-warning'}">
                                ${daysPending} day${daysPending !== 1 ? 's' : ''}
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-info">
                                Level ${payroll.approval_level || 1}
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewPayrollForApproval(${payroll.id})">
                                <i class="bi bi-eye"></i> Review
                            </button>
                        </td>
                    </tr>
                `;
            });
        });
}

function loadApprovedPayrolls() {
    if (!permissions.view) return;
    const search = document.getElementById('approvedSearch').value;
    const period = document.getElementById('approvedPeriod').value;
    const department = document.getElementById('approvedDepartment').value;
    
    let url = 'api/payroll-approval.php?action=approved_payrolls';
    const params = [];
    
    if (search) params.push(`search=${encodeURIComponent(search)}`);
    if (period) params.push(`period=${period}`);
    if (department) params.push(`department=${department}`);
    
    if (params.length > 0) {
        url += '&' + params.join('&');
    }
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('approvedTableBody');
            tbody.innerHTML = '';
            selectedPayrolls.clear();
            
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-check2-circle fs-1"></i>
                            <p class="mt-2">No approved payrolls</p>
                        </td>
                    </tr>
                `;
                document.getElementById('selectedTotal').textContent = '₱0.00';
                return;
            }
            
            data.forEach(payroll => {
                const isSelected = selectedPayrolls.has(payroll.id.toString());
                
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <input type="checkbox" class="payroll-checkbox" 
                                   value="${payroll.id}" 
                                   ${isSelected ? 'checked' : ''}
                                   onchange="togglePayrollSelection(${payroll.id}, this.checked)">
                        </td>
                        <td class="fw-bold">PAY-${String(payroll.id).padStart(5, '0')}</td>
                        <td>
                            <div class="fw-bold">${payroll.employee_name}</div>
                            <small class="text-muted">${payroll.employee_no}</small>
                        </td>
                        <td>${payroll.department || 'N/A'}</td>
                        <td>${payroll.payroll_period}</td>
                        <td class="fw-bold text-success">${formatCurrency(payroll.net_pay)}</td>
                        <td>
                            <small class="text-muted">${formatDate(payroll.approved_at || payroll.updated_at, false)}</small>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewPayrollDetails(${payroll.id})">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            calculateSelectedTotal();
        });
}

// =============================================
// NEW FUNCTIONS FOR READY FOR RELEASE TAB
// =============================================

let readyPayrolls = new Map(); // payroll_id -> payroll data
let selectedForRelease = new Set();
let selectedReleaseTotal = 0;

function loadReadyForRelease() {
    if (!permissions.view) return;
    const search = document.getElementById('readySearch')?.value || '';
    
    fetch('api/payroll-approval.php?action=ready_for_release' + (search ? '&search=' + encodeURIComponent(search) : ''))
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('releaseTableBody');
            if (!tbody) return;
            
            tbody.innerHTML = '';
            readyPayrolls.clear();
            selectedForRelease.clear();
            updateReleaseSummary();
            
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="bi bi-check2-circle fs-1 text-muted"></i>
                            <p class="text-muted mt-2">No payrolls ready for release</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            data.forEach(payroll => {
                readyPayrolls.set(payroll.id, payroll);
                
                tbody.innerHTML += `
                <tr id="ready-row-${payroll.id}">
                    <td>
                        <input type="checkbox" class="form-check-input release-checkbox" 
                            value="${payroll.id}"
                            onchange="toggleReleaseSelection(${payroll.id}, this.checked)">
                    </td>
                    <td>
                        <span class="badge bg-warning">PAY-${String(payroll.id).padStart(5, '0')}</span>
                    </td>
                    <td>
                        <!-- PUMILI KA LANG NG ISA DITO -->
                        <div>
                            <div class="fw-bold">${payroll.employee_name}</div>
                            <small class="text-muted">${payroll.employee_code || payroll.employee_no || ''}</small>
                        </div>
                    </td>
                    <td>
                        ${payroll.bank_name ? `
                            <div class="small">${payroll.bank_name}</div>
                            <div class="text-muted smaller">${payroll.bank_account_number || 'N/A'}</div>
                        ` : '<span class="text-muted">No bank info</span>'}
                    </td>
                    <td class="text-end fw-bold">
                        ₱${parseFloat(payroll.net_pay || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                    </td>
                    <td>
                        <div class="small">${formatDate(payroll.ready_date || payroll.updated_at, false)}</div>
                        <div class="text-muted smaller">${calculateDaysAgo(payroll.ready_date || payroll.updated_at)} days ago</div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewReadyDetails(${payroll.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
                `;
            });
        })
        .catch(error => {
            console.error('Error loading ready payrolls:', error);
            showError('releaseTableBody', 'Failed to load payrolls');
        });
}

function toggleReleaseSelection(payrollId, isChecked) {
    if (!permissions.approve) {
        Swal.fire('Access Denied', 'You don\'t have permission to release payrolls', 'error');
        return;
    }
    const row = document.getElementById(`ready-row-${payrollId}`);
    
    if (isChecked) {
        selectedForRelease.add(payrollId);
        if (row) row.classList.add('table-success');
    } else {
        selectedForRelease.delete(payrollId);
        if (row) row.classList.remove('table-success');
    }
    
    // Update select all checkbox
    const totalCheckboxes = document.querySelectorAll('#releaseTableBody .release-checkbox').length;
    const checkedCount = selectedForRelease.size;
    const selectAllCheckbox = document.getElementById('selectAllReady');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = totalCheckboxes > 0 && checkedCount === totalCheckboxes;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
    }
    
    updateReleaseSummary();
}

// Update release summary panel
function updateReleaseSummary() {
    let total = 0;
    
    selectedForRelease.forEach(payrollId => {
        const payroll = readyPayrolls.get(payrollId);
        if (payroll) {
            total += parseFloat(payroll.net_pay || 0);
        }
    });
    
    selectedReleaseTotal = total;
    
    // Update UI
    const releaseTotal = document.getElementById('releaseTotalAmount');
    const employeeCount = document.getElementById('releaseEmployeeCount');
    const releaseBtn = document.getElementById('releaseBatchBtn');
    
    if (releaseTotal) {
        releaseTotal.textContent = formatCurrency(total);
    }
    
    if (employeeCount) {
        employeeCount.textContent = `${selectedForRelease.size} employee${selectedForRelease.size !== 1 ? 's' : ''}`;
    }
    
    if (releaseBtn) {
        releaseBtn.disabled = selectedForRelease.size === 0;
    }
}

function processReleaseBatch() {
    if (!permissions.approve) {
        Swal.fire('Access Denied', 'You don\'t have permission to release payrolls', 'error');
        return;
    }
    if (selectedForRelease.size === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one payroll to release.',
            confirmButtonColor: '#3085d6'
        });
        return;
    }
    
    // Get all form values
    const paymentMethod = document.getElementById('paymentMethod')?.value;
    const referenceNumber = document.getElementById('referenceNumber')?.value;
    const releaseDate = document.getElementById('releaseDate')?.value;
    const transactionTime = document.getElementById('transactionTime')?.value;
    const approverId = document.getElementById('approverId')?.value;
    const witnessName = document.getElementById('witnessName')?.value;
    const proofFiles = document.getElementById('proofFiles')?.files;
    const remarks = document.getElementById('releaseRemarks')?.value || '';
    const sendNotification = document.getElementById('sendNotification')?.checked || false;
    
    // Validate required fields
    const missingFields = [];
    if (!paymentMethod) missingFields.push('Payment Method');
    if (!referenceNumber) missingFields.push('Reference Number');
    if (!releaseDate) missingFields.push('Release Date');
    if (!transactionTime) missingFields.push('Transaction Time');
    if (!approverId) missingFields.push('Approved By');
    if (!proofFiles || proofFiles.length === 0) missingFields.push('Proof of Payment');
    
    if (missingFields.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Required Fields Missing',
            html: `
                <div class="text-start">
                    <p class="mb-2">Please fill in the following required fields:</p>
                    <ul class="text-danger">
                        ${missingFields.map(field => `<li>${field}</li>`).join('')}
                    </ul>
                </div>
            `,
            confirmButtonColor: '#dc3545'
        });
        return;
    }
    
    const releaseDateTime = `${releaseDate} ${transactionTime}:00`;
    
    Swal.fire({
        title: 'Process Batch Release?',
        html: `
            <div class="text-start">
                <p>You are about to release <strong>${selectedForRelease.size} payroll(s)</strong> with total amount:</p>
                <h4 class="text-center text-success">${formatCurrency(selectedReleaseTotal)}</h4>
                <hr>
                <p><strong>Payment Method:</strong> ${paymentMethod}</p>
                <p><strong>Reference #:</strong> ${referenceNumber}</p>
                <p><strong>Release Date/Time:</strong> ${formatDate(releaseDate, true)} ${transactionTime}</p>
                <p><strong>Approved By:</strong> ${document.querySelector('#approverId option:checked')?.text}</p>
                <p><strong>Proof Files:</strong> ${proofFiles.length} file(s)</p>
                ${witnessName ? `<p><strong>Witness:</strong> ${witnessName}</p>` : ''}
                ${remarks ? `<p><strong>Remarks:</strong> ${remarks}</p>` : ''}
                <p class="text-warning mt-3">
                    <i class="bi bi-exclamation-triangle"></i> This action will generate payslips and cannot be undone.
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Process Release',
        confirmButtonColor: '#198754',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing Release...',
                text: 'Please wait...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Create FormData for file upload
            const formData = new FormData();
            formData.append('action', 'release_batch_ready');
            formData.append('payroll_ids', JSON.stringify(Array.from(selectedForRelease)));
            formData.append('payment_method', paymentMethod);
            formData.append('reference_number', referenceNumber);
            formData.append('release_datetime', releaseDateTime);
            formData.append('approver_id', approverId);
            formData.append('witness_name', witnessName || '');
            formData.append('remarks', remarks);
            formData.append('send_notification', sendNotification);
            
            // IMPORTANT: Add proof files
            for (let i = 0; i < proofFiles.length; i++) {
                formData.append('proof_files[]', proofFiles[i]);
                console.log('Added file:', proofFiles[i].name); // Debug
            }
            
            // Send request
            fetch('api/payroll-approval.php', {
                method: 'POST',
                headers: { 
                    'Authorization': 'Bearer ' + (localStorage.getItem('token') || '')
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                 console.log('API response:', data); 
                Swal.close();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        html: `
                            <div class="text-start">
                                <p>${data.message}</p>
                                <div class="alert alert-light mt-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Released ${data.released_count} payroll(s)<br>
                                    Generated ${data.payslip_codes?.length || 0} payslip(s)<br>
                                    Total Amount: ${formatCurrency(data.total_amount || 0)}<br>
                                    Reference #: ${referenceNumber}
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#198754'
                    }).then(() => {
                        // Clear form
                        document.getElementById('referenceNumber').value = '';
                        document.getElementById('releaseRemarks').value = '';
                        document.getElementById('proofFiles').value = '';
                    });
                    
                    // Clear selection and reload data
                    selectedForRelease.clear();
                    updateReleaseSummary();
                    
                    // Reload all relevant data
                    loadDashboardStats();
                    loadReadyForRelease();
                    loadReleasedPayrolls();
                    
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Release Failed',
                        text: data.error || 'Failed to process batch release',
                        confirmButtonColor: '#dc3545'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please check your connection and try again.',
                    confirmButtonColor: '#dc3545'
                });
            });
        }
    });
}

// Preview batch before release
function previewBatch() {
    if (selectedForRelease.size === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select payrolls to preview.',
            confirmButtonColor: '#3085d6'
        });
        return;
    }
    
    const paymentMethod = document.getElementById('paymentMethod')?.value || 'Bank Transfer';
    const releaseDate = document.getElementById('releaseDate')?.value || 'Today';
    const referenceNumber = document.getElementById('referenceNumber')?.value || 'Not specified';
    const approverName = document.querySelector('#approverId option:checked')?.text || 'Not selected';
    
    let previewContent = '<div class="text-start"><h6>Selected Payrolls for Release:</h6><ul class="list-group">';
    
    selectedForRelease.forEach(payrollId => {
        const payroll = readyPayrolls.get(payrollId);
        if (payroll) {
            previewContent += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${payroll.employee_name}</strong><br>
                        <small class="text-muted">PAY-${String(payroll.id).padStart(5, '0')} • ${payroll.payroll_period}</small>
                    </div>
                    <span class="fw-bold">${formatCurrency(payroll.net_pay)}</span>
                </li>
            `;
        }
    });
    
    previewContent += `</ul>
        <div class="mt-3 p-2 bg-light rounded">
            <strong>Release Summary:</strong><br>
            • Total Employees: ${selectedForRelease.size}<br>
            • Total Amount: ${formatCurrency(selectedReleaseTotal)}<br>
            • Payment Method: ${paymentMethod}<br>
            • Reference #: ${referenceNumber}<br>
            • Release Date: ${releaseDate}<br>
            • Approved By: ${approverName}
        </div>
    </div>`;
    
    Swal.fire({
        title: 'Batch Release Preview',
        html: previewContent,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Proceed to Release',
        cancelButtonText: 'Close Preview'
    }).then((result) => {
        if (result.isConfirmed) {
            processReleaseBatch();
        }
    });
}

// Clear ready selection
function clearReadySelection() {
    const checkboxes = document.querySelectorAll('#releaseTableBody .release-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
        const payrollId = parseInt(cb.value);
        const row = document.getElementById(`ready-row-${payrollId}`);
        if (row) row.classList.remove('table-success');
    });
    
    selectedForRelease.clear();
    updateReleaseSummary();
    
    const selectAllCheckbox = document.getElementById('selectAllReady');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
}

// View payroll details from Approved tab (as modal)
function viewPayrollDetails(payrollId) {
    currentPayrollId = payrollId;
    loadPayrollDetails(payrollId);
}

// View payroll details from Ready for Release tab (as modal)
function viewReadyDetails(payrollId) {
    currentPayrollId = payrollId;
    loadPayrollDetails(payrollId);
}

// Main function to load payroll details
function loadPayrollDetails(payrollId) {
    if (!payrollId) {
        showPayrollError('Invalid payroll ID');
        return;
    }
    
    // Show loading state
    document.getElementById('payrollDetailsLoading').style.display = 'block';
    document.getElementById('payrollDetailsContent').style.display = 'none';
    document.getElementById('payrollDetailsError').style.display = 'none';
    
    // Reset modal
    resetPayrollModal();
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('payrollDetailsModal'));
    modal.show();
    
    // Fetch payroll details
    fetch(`api/payroll-approval.php?action=payroll_details&id=${payrollId}`)
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                displayPayrollDetails(data);
            } else {
                throw new Error(data.error || 'Failed to load payroll details');
            }
        })
        .catch(error => {
            console.error('Error loading payroll details:', error);
            showPayrollError(error.message || 'Failed to load payroll details');
        });
}

// Display payroll details in modal
function displayPayrollDetails(data) {
    const payroll = data.payroll;
    const approvals = data.approvals || [];
    
    // Hide loading, show content
    document.getElementById('payrollDetailsLoading').style.display = 'none';
    document.getElementById('payrollDetailsContent').style.display = 'block';
    
    // Set basic info
    document.getElementById('payrollEmployeeName').textContent = payroll.employee_name || 'N/A';
    document.getElementById('payrollEmployeeNo').textContent = payroll.employee_no || 'N/A';
    document.getElementById('payrollDepartment').textContent = payroll.department || 'N/A';
    document.getElementById('payrollPeriod').textContent = payroll.payroll_period || 'N/A';
    
    // Set amounts
    document.getElementById('payrollGrossPay').textContent = formatCurrency(payroll.gross_pay || 0);
    document.getElementById('payrollNetPay').textContent = formatCurrency(payroll.net_pay || 0);
    
    // Set status badge
    const statusBadge = document.getElementById('payrollStatusBadge');
    statusBadge.textContent = payroll.status || 'Unknown';
    statusBadge.className = 'badge ' + getStatusClass(payroll.status);
    
    // Earnings
    document.getElementById('earningsBasic').textContent = formatCurrency(payroll.basic_salary || 0);
    document.getElementById('earningsOvertime').textContent = formatCurrency(payroll.overtime || 0);
    document.getElementById('earningsHoliday').textContent = formatCurrency(payroll.holiday_pay || 0);
    document.getElementById('earningsAllowances').textContent = formatCurrency(payroll.allowances || 0);
    document.getElementById('earningsBonuses').textContent = formatCurrency(payroll.bonuses || 0);
    document.getElementById('earningsOther').textContent = formatCurrency(0); // Add if you have other earnings
    document.getElementById('earningsTotal').textContent = formatCurrency(payroll.gross_pay || 0);
    
    // Deductions
    document.getElementById('deductionsSSS').textContent = formatCurrency(payroll.sss_contribution || 0);
    document.getElementById('deductionsPhilhealth').textContent = formatCurrency(payroll.philhealth_contribution || 0);
    document.getElementById('deductionsPagibig').textContent = formatCurrency(payroll.pagibig_contribution || 0);
    document.getElementById('deductionsTax').textContent = formatCurrency(payroll.withholding_tax || 0);
    document.getElementById('deductionsAbsences').textContent = formatCurrency(payroll.absences || 0);
    document.getElementById('deductionsTardiness').textContent = formatCurrency(payroll.tardiness || 0);
    document.getElementById('deductionsLWOP').textContent = formatCurrency(payroll.leave_without_pay || 0);
    document.getElementById('deductionsOther').textContent = formatCurrency(payroll.other_deductions || 0);
    document.getElementById('deductionsTotal').textContent = formatCurrency(payroll.total_deductions || 0);
    
    // Bank Details
    document.getElementById('bankPaymentMethod').textContent = payroll.payment_method || 'Not specified';
    document.getElementById('bankPaymentStatus').textContent = payroll.status || 'Unknown';
    document.getElementById('bankPaymentStatus').className = 'badge ' + getStatusClass(payroll.status);
    document.getElementById('bankBankName').textContent = payroll.bank_name || 'Not specified';
    document.getElementById('bankAccountHolder').textContent = payroll.bank_account_holder || 'Not specified';
    document.getElementById('bankAccountNumber').textContent = maskAccountNumber(payroll.bank_account_number) || 'Not specified';
    document.getElementById('bankAccountType').textContent = payroll.bank_account_type || 'Savings Account';
    
    // Timeline
    updateTimeline(payroll, approvals);
    
    // Set up action buttons
    setupActionButtons(payroll.id, payroll.status);
}

// Update timeline based on payroll status
function updateTimeline(payroll, approvals) {
    // Generated
    const generatedDate = payroll.generated_at ? new Date(payroll.generated_at) : null;
    if (generatedDate) {
        document.getElementById('timelineGeneratedDate').textContent = formatDate(payroll.generated_at, false);
        document.getElementById('timelineGeneratedBy').textContent = `By: ${payroll.generated_by_name || 'System'}`;
        document.getElementById('timelineGenerated').style.display = 'block';
    }
    
    // Approved
    const approvedApproval = approvals.find(a => a.status === 'approved');
    if (approvedApproval && approvedApproval.approved_at) {
        document.getElementById('timelineApprovedDate').textContent = formatDate(approvedApproval.approved_at, false);
        document.getElementById('timelineApprovedBy').textContent = `By: ${approvedApproval.approver_name || 'Unknown'}`;
        document.getElementById('timelineApproved').style.display = 'block';
    }
    
    // Ready (if applicable)
    const readyApproval = approvals.find(a => a.status === 'ready');
    if (readyApproval && readyApproval.approved_at) {
        document.getElementById('timelineReadyDate').textContent = formatDate(readyApproval.approved_at, false);
        document.getElementById('timelineReady').style.display = 'block';
    } else if (payroll.status === 'Ready' && payroll.updated_at) {
        document.getElementById('timelineReadyDate').textContent = formatDate(payroll.updated_at, false);
        document.getElementById('timelineReady').style.display = 'block';
    }
    
    // Released
    if (payroll.status === 'Released' && payroll.released_at) {
        document.getElementById('timelineReleasedDate').textContent = formatDate(payroll.released_at, false);
        document.getElementById('timelineReleasedBy').textContent = `By: ${payroll.released_by_name || 'Unknown'}`;
        document.getElementById('timelinePaymentMethod').textContent = `Method: ${payroll.payment_method || 'Bank Transfer'}`;
        document.getElementById('timelineReleased').style.display = 'block';
    }
}

// Setup action buttons based on payroll status
function setupActionButtons(payrollId, status) {
    const printBtn = document.getElementById('btnPrintPayslip');
    const downloadBtn = document.getElementById('btnDownloadPayslip');
    
    // Always show print and download for released payrolls
    if (status === 'Released') {
        printBtn.style.display = 'inline-block';
        downloadBtn.style.display = 'inline-block';
        
        printBtn.onclick = () => printPayslip(payrollId);
        downloadBtn.onclick = () => downloadPayslip(payrollId);
    } else {
        // For non-released payrolls, show preview options
        printBtn.style.display = 'inline-block';
        downloadBtn.style.display = 'inline-block';
        
        printBtn.innerHTML = '<i class="bi bi-eye me-1"></i> Preview';
        downloadBtn.innerHTML = '<i class="bi bi-download me-1"></i> Preview PDF';
        
        printBtn.onclick = () => previewPayslip(payrollId);
        downloadBtn.onclick = () => previewPayslipPDF(payrollId);
    }
}

// Print payslip
function printPayslip(payrollId) {
    const printWindow = window.open(`views/generate_payslip.php?id=${payrollId}&print=1`, '_blank');
    
    // Focus the window
    if (printWindow) {
        printWindow.focus();
    }
}

// Download payslip
function downloadPayslip(payrollId) {
    window.open(`views/generate_payslip.php?id=${payrollId}&download=1`, '_blank');
}

// Preview payslip
function previewPayslip(payrollId) {
    Swal.fire({
        title: 'Preview Payslip',
        html: `
            <div class="text-start">
                <p>This payroll is <strong>not yet released</strong>. You can preview the payslip, but it will be marked as "DRAFT".</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Note:</strong> Final payslip will be generated after release.
                </div>
            </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Preview Now',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.open(`views/generate_payslip.php?id=${payrollId}&preview=1`, '_blank');
        }
    });
}


// Preview payslip as PDF
function previewPayslipPDF(payrollId) {
    Swal.fire({
        title: 'Generate PDF Preview?',
        text: 'This will generate a PDF preview of the payslip.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Generate PDF',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.open(`views/generate_payslip.php?id=${payrollId}&preview=1&format=pdf`, '_blank');
        }
    });
}

// Batch download payslips (for multiple selected payrolls)
function downloadBatchPayslips(payrollIds) {
    if (!payrollIds || payrollIds.length === 0) {
        Swal.fire('Warning!', 'Please select at least one payslip to download.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Download Multiple Payslips',
        html: `
            <div class="text-start">
                <p>Download <strong>${payrollIds.length} payslip(s)</strong> as:</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="batchMethod" id="batchZip" value="zip" checked>
                    <label class="form-check-label" for="batchZip">
                        <i class="bi bi-file-zip text-primary"></i> ZIP Archive (Multiple files)
                    </label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="batchMethod" id="batchPdf" value="pdf">
                    <label class="form-check-label" for="batchPdf">
                        <i class="bi bi-file-earmark-pdf text-danger"></i> Combined PDF (Single file)
                    </label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="includeSummary" checked>
                    <label class="form-check-label" for="includeSummary">
                        Include summary sheet
                    </label>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    <small>This feature requires backend implementation for ZIP/PDF generation.</small>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Generate Download',
        cancelButtonText: 'Cancel',
        width: '600px'
    }).then((result) => {
        if (result.isConfirmed) {
            const method = document.querySelector('input[name="batchMethod"]:checked').value;
            const includeSummary = document.getElementById('includeSummary').checked;
            
            // Show loading
            Swal.fire({
                title: 'Preparing Files...',
                text: 'Please wait while we generate your download',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Simulate backend processing
            setTimeout(() => {
                Swal.close();
                
                // In real implementation, this would call your backend
                // Example: window.open(`generate_batch_payslips.php?ids=${payrollIds.join(',')}&method=${method}`, '_blank');
                
                Swal.fire({
                    title: 'Ready for Download!',
                    html: `
                        <div class="text-start">
                            <p>Your batch download is ready:</p>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>${payrollIds.length} payslip(s)</strong> generated as ${method.toUpperCase()}
                            </div>
                            <p class="text-muted small">
                                <i class="bi bi-lightbulb me-1"></i>
                                In a real implementation, this would trigger a file download.
                            </p>
                        </div>
                    `,
                    icon: 'success',
                    confirmButtonText: 'OK'
                });
            }, 2000);
        }
    });
}

// Quick print function (for single click)
function quickPrintPayslip(payrollId) {
    const printWindow = window.open(`views/generate_payslip.php?id=${payrollId}&print=1`, '_blank', 'width=800,height=600');
    
    // Wait for window to load, then print
    if (printWindow) {
        printWindow.onload = function() {
            printWindow.print();
        };
    }
}

// Show error in modal
function showPayrollError(message) {
    document.getElementById('payrollDetailsLoading').style.display = 'none';
    document.getElementById('payrollDetailsContent').style.display = 'none';
    document.getElementById('payrollDetailsError').style.display = 'block';
    document.getElementById('payrollErrorMessage').textContent = message;
}

// Reset modal content
function resetPayrollModal() {
    // Hide all timeline items
    ['Generated', 'Approved', 'Ready', 'Released'].forEach(item => {
        const element = document.getElementById(`timeline${item}`);
        if (element) element.style.display = 'none';
    });
    
    // Reset action buttons
    const printBtn = document.getElementById('btnPrintPayslip');
    const downloadBtn = document.getElementById('btnDownloadPayslip');
    if (printBtn) printBtn.style.display = 'inline-block';
    if (downloadBtn) downloadBtn.style.display = 'inline-block';
}

// Get CSS class for status badge
function getStatusClass(status) {
    switch(status?.toLowerCase()) {
        case 'draft': return 'bg-draft';
        case 'generated': return 'bg-generated';
        case 'ready': return 'bg-ready';
        case 'released': return 'bg-released';
        case 'cancelled': return 'bg-cancelled';
        default: return 'bg-secondary';
    }
}

// Mask account number for security
function maskAccountNumber(accountNumber) {
    if (!accountNumber) return '';
    if (accountNumber.length <= 4) return accountNumber;
    
    const last4 = accountNumber.slice(-4);
    return '••••' + last4;
}

// Enhanced formatCurrency function
function formatCurrency(amount) {
    if (amount === null || amount === undefined) return '₱0.00';
    
    const num = parseFloat(amount);
    if (isNaN(num)) return '₱0.00';
    
    return '₱' + num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Enhanced formatDate function
function formatDate(dateString, dateOnly = false) {
    if (!dateString) return 'N/A';
    
    try {
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
            minute: '2-digit',
            hour12: true
        });
    } catch (error) {
        console.error('Error formatting date:', error);
        return 'N/A';
    }
}

// Add event listener for modal close
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('payrollDetailsModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            currentPayrollId = null;
        });
    }
});



// Preview batch before release
function previewBatch() {
    if (selectedForRelease.size === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select payrolls to preview.',
            confirmButtonColor: '#3085d6'
        });
        return;
    }
    
    let previewContent = '<div class="text-start"><h6>Selected Payrolls for Release:</h6><ul class="list-group">';
    
    selectedForRelease.forEach(payrollId => {
        const payroll = readyPayrolls.get(payrollId);
        if (payroll) {
            previewContent += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${payroll.employee_name}</strong><br>
                        <small class="text-muted">PAY-${String(payroll.id).padStart(5, '0')} • ${payroll.payroll_period}</small>
                    </div>
                    <span class="fw-bold">${formatCurrency(payroll.net_pay)}</span>
                </li>
            `;
        }
    });
    
    previewContent += `</ul>
        <div class="mt-3 p-2 bg-light rounded">
            <strong>Summary:</strong><br>
            • Total Employees: ${selectedForRelease.size}<br>
            • Total Amount: ${formatCurrency(selectedReleaseTotal)}<br>
            • Payment Method: ${document.getElementById('paymentMethod')?.value || 'Bank Transfer'}<br>
            • Release Date: ${document.getElementById('releaseDate')?.value || 'Today'}
        </div>
    </div>`;
    
    Swal.fire({
        title: 'Batch Release Preview',
        html: previewContent,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Proceed to Release',
        cancelButtonText: 'Close Preview'
    }).then((result) => {
        if (result.isConfirmed) {
            processReleaseBatch();
        }
    });
}

function loadReleasedPayrolls() {
    if (!permissions.view) return;
    const month = document.getElementById('releasedMonth')?.value || new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=released_payrolls&month=${month}`)
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('releasedTableBody');
            if (!tbody) return;
            
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-5">
                            <i class="bi bi-send-check fs-1 text-muted"></i>
                            <p class="text-muted mt-2">No released payrolls for this period</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            data.forEach(payroll => {
                // Generate payslip code if not exists
                const payslipCode = payroll.payslip_code || 'PS-' + String(payroll.id).padStart(5, '0');
                
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <span class="badge bg-success">${payslipCode}</span>
                        </td>
                    <td>
                        <!-- PUMILI KA LANG NG ISA DITO -->
                        <div>
                            <div class="fw-bold">${payroll.employee_name}</div>
                            <small class="text-muted">${payroll.employee_code || payroll.employee_no || ''}</small>
                        </div>
                    </td>
                        <td>
                            <div>${payroll.payroll_period}</div>
                            <small class="text-muted">${formatDate(payroll.period_start, true)} - ${formatDate(payroll.period_end, true)}</small>
                        </td>
                        <td class="text-end fw-bold text-success">
                            ₱${parseFloat(payroll.net_pay || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                        </td>
                        <td>
                            <span class="badge bg-info">${payroll.payment_method || 'Bank Transfer'}</span>
                        </td>
                        <td>
                            <div class="small">${formatDate(payroll.released_at, false)}</div>
                            <div class="text-muted smaller">${calculateDaysAgo(payroll.released_at)} days ago</div>
                        </td>
                        <td>
                            <div class="small">${payroll.released_by_name || 'N/A'}</div>
                        </td>
                        <td>
                            <span class="badge bg-success">Released</span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="viewPayslip(${payroll.id})" title="View Payslip">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-success" onclick="downloadPayslip(${payroll.id})" title="Download PDF">
                                    <i class="bi bi-download"></i>
                                </button>
                                <button class="btn btn-outline-info" onclick="resendPayslip(${payroll.id})" title="Resend to Employee">
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
        })
        .catch(error => {
            console.error('Error loading released payrolls:', error);
            showError('releasedTableBody', 'Failed to load released payrolls');
        });
}

function openGovernmentRemittanceModal() {
    if (!permissions.view) {
        Swal.fire('Access Denied', 'You don\'t have permission to view reports', 'error');
        return;
    }
    const month = document.getElementById('releasedMonth')?.value || new Date().toISOString().slice(0, 7);
    
    Swal.fire({
        title: 'Export Released Payroll Report?',
        html: `
            <div class="text-start">
                <p>Export released payrolls for <strong>${month}</strong> as:</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="exportFormat" id="exportExcel" value="excel" checked>
                    <label class="form-check-label" for="exportExcel">
                        <i class="bi bi-file-earmark-excel text-success"></i> Excel (.xlsx)
                    </label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="exportFormat" id="exportPDF" value="pdf">
                    <label class="form-check-label" for="exportPDF">
                        <i class="bi bi-file-earmark-pdf text-danger"></i> PDF (.pdf)
                    </label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" name="exportFormat" id="exportCSV" value="csv">
                    <label class="form-check-label" for="exportCSV">
                        <i class="bi bi-filetype-csv text-primary"></i> CSV (.csv)
                    </label>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Export Now',
        confirmButtonColor: '#0d6efd',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const format = document.querySelector('input[name="exportFormat"]:checked')?.value || 'excel';
            
            // Show loading
            Swal.fire({
                title: 'Generating Report...',
                text: 'Please wait while we prepare your export',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // In real implementation, this would call your backend export endpoint
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'Report Generated!',
                    html: `
                        <p>Released payroll report for ${month} has been generated.</p>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-download me-2"></i>
                            <strong>Note:</strong> In a real implementation, this would download the ${format.toUpperCase()} file.
                        </div>
                    `,
                    confirmButtonText: 'OK'
                });
            }, 1500);
        }
    });
}

// Download payslip
function downloadPayslip(payrollId) {
    // Method 1: Direct download (if backend generates PDF)
    // window.open(`generate_payslip.php?id=${payrollId}&download=1`, '_blank');
    
    // Method 2: For now, use print to PDF
    Swal.fire({
        title: 'Download Payslip',
        html: `
            <div class="text-start">
                <p>How would you like to download the payslip?</p>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="downloadMethod" id="methodPrint" value="print" checked>
                    <label class="form-check-label" for="methodPrint">
                        Print to PDF (Recommended)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="downloadMethod" id="methodDirect" value="direct">
                    <label class="form-check-label" for="methodDirect">
                        Direct Download (If available)
                    </label>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <small>Tip: Use "Save as PDF" in the print dialog to create a PDF file.</small>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Proceed',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const method = document.querySelector('input[name="downloadMethod"]:checked').value;
            
            if (method === 'print') {
                // Open print version
                const printWindow = window.open(`views/generate_payslip.php?id=${payrollId}&print=1`, '_blank');
                if (printWindow) {
                    printWindow.focus();
                }
            } else {
                // Try direct download (if your backend supports PDF generation)
                window.open(`views/generate_payslip.php?id=${payrollId}&download=1`, '_blank');
            }
        }
    });
}

// Resend payslip notification
function resendPayslip(payrollId) {
    Swal.fire({
        title: 'Resend Payslip Notification?',
        text: 'This will send an email notification to the employee.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Resend',
        confirmButtonColor: '#0dcaf0',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('api/payroll-approval.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + (localStorage.getItem('token') || '')
                },
                body: JSON.stringify({
                    action: 'resend_payslip',
                    payroll_id: payrollId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Notification Sent!',
                        text: data.message,
                        confirmButtonColor: '#198754'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to resend notification',
                        confirmButtonColor: '#dc3545'
                    });
                }
            });
        }
    });
}

// =============================================
// HELPER FUNCTIONS
// =============================================

// Calculate days ago
function calculateDaysAgo(dateString) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffTime = Math.abs(now - date);
    return Math.floor(diffTime / (1000 * 60 * 60 * 24));
}

// Show error in table
function showError(tableBodyId, message) {
    const tbody = document.getElementById(tableBodyId);
    if (tbody) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-5">
                    <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                    <p class="text-danger mt-2">${message}</p>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Retry
                    </button>
                </td>
            </tr>
        `;
    }
}

// Initialize tab event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add tab event listeners
    const readyTab = document.getElementById('release-tab');
    if (readyTab) {
        readyTab.addEventListener('shown.bs.tab', function() {
            loadReadyForRelease();
        });
    }
    
    const releasedTab = document.getElementById('released-tab');
    if (releasedTab) {
        releasedTab.addEventListener('shown.bs.tab', function() {
            loadReleasedPayrolls();
        });
    }
    
    // Initialize select all for ready tab
    const selectAllReady = document.getElementById('selectAllReady');
    if (selectAllReady) {
        selectAllReady.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#releaseTableBody .release-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
                const payrollId = parseInt(cb.value);
                const row = document.getElementById(`ready-row-${payrollId}`);
                
                if (this.checked) {
                    selectedForRelease.add(payrollId);
                    if (row) row.classList.add('table-success');
                } else {
                    selectedForRelease.delete(payrollId);
                    if (row) row.classList.remove('table-success');
                }
            });
            updateReleaseSummary();
        });
    }
    
    // Initialize search for ready tab
    const readySearch = document.getElementById('readySearch');
    if (readySearch) {
        readySearch.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                loadReadyForRelease();
            }
        });
    }
    
    // Set default release date to today
    const releaseDateInput = document.getElementById('releaseDate');
    if (releaseDateInput) {
        releaseDateInput.value = new Date().toISOString().split('T')[0];
    }
});

// Dagdagan mo ng mga function na ito sa payroll-approval.js mo

function loadRemittances() {
    fetch('api/payroll-approval.php?action=remittances')
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('remittanceTableBody');
            tbody.innerHTML = '';
            
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            <i class="bi bi-building fs-1"></i>
                            <p class="mt-2">No remittance records</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            data.forEach(remittance => {
                const statusClass = {
                    'pending': 'bg-warning',
                    'paid': 'bg-success',
                    'overdue': 'bg-danger'
                }[remittance.status] || 'bg-secondary';
                
                // Format period
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                    "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"
                ];
                const period = `${monthNames[remittance.period_month - 1]} ${remittance.period_year}`;
                
                tbody.innerHTML += `
                    <tr>
                        <td class="fw-bold">${remittance.remittance_type}</td>
                        <td>${period}</td>
                        <td class="fw-bold">${formatCurrency(remittance.total_amount)}</td>
                        <td>
                            ${remittance.due_date ? formatDate(remittance.due_date, false) : 'N/A'}
                            ${remittance.status === 'pending' && new Date(remittance.due_date) < new Date() ? 
                                '<span class="badge bg-danger ms-1">Overdue</span>' : ''}
                        </td>
                        <td>
                            <span class="badge ${statusClass}">${remittance.status}</span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewRemittance(${remittance.id})">
                                <i class="bi bi-eye"></i>
                            </button>
                            ${remittance.status === 'pending' ? `
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
        })
        .catch(err => console.error("Error loading remittances:", err));
}

function calculateRemittance() {
    const type = document.getElementById('remittanceType').value;
    const period = document.getElementById('remittancePeriod').value;
    
    if (!type || !period) {
        Swal.fire('Error!', 'Please select type and period', 'error');
        return;
    }
    
    fetch('api/payroll-approval.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'calculate_remittance',
            remittance_type: type,
            period: period
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Remittance Calculation',
                html: `
                    <div class="text-start">
                        <p><strong>Type:</strong> ${type}</p>
                        <p><strong>Period:</strong> ${period}</p>
                        <p><strong>Employee Count:</strong> ${data.employee_count || 0}</p>
                        <hr>
                        <p><strong>Employee Share:</strong> ${formatCurrency(data.employee_share)}</p>
                        <p><strong>Employer Share:</strong> ${formatCurrency(data.employer_share)}</p>
                        <hr>
                        <h5 class="text-primary">Total Amount: ${formatCurrency(data.total_amount)}</h5>
                    </div>
                `,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Save Remittance',
                cancelButtonText: 'Close'
            }).then(result => {
                if (result.isConfirmed) {
                    saveRemittance(type, period, data.employee_share, data.employer_share, data.total_amount);
                }
            });
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    })
    .catch(err => {
        console.error("Calculate remittance error:", err);
        Swal.fire('Error!', 'Failed to calculate remittance', 'error');
    });
}

function saveRemittance(type, period, employeeShare, employerShare, total) {
    fetch('api/payroll-approval.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'save_remittance',
            remittance_type: type,
            period: period,
            employee_share: employeeShare,
            employer_share: employerShare,
            total_amount: total
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', 'Remittance saved successfully', 'success');
            loadRemittances();
        } else {
            Swal.fire('Error!', data.error || 'Failed to save remittance', 'error');
        }
    });
}
function markRemittancePaid(id) {
    Swal.fire({
        title: 'Mark as Paid?',
        text: 'This will update the remittance status to paid.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, mark as paid'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('api/payroll-approval.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'mark_remittance_paid',
                    id: id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', 'Remittance marked as paid', 'success');
                    loadRemittances();
                } else {
                    Swal.fire('Error!', 'Failed to update remittance', 'error');
                }
            });
        }
    });
}


function viewRemittance(id) {
    fetch(`api/payroll-approval.php?action=view_remittance&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error!', data.error, 'error');
                return;
            }
            
            const remittance = data.remittance;
            const breakdown = data.breakdown || [];
            
            // Build breakdown table
            let breakdownHTML = '';
            if (breakdown.length > 0) {
                breakdownHTML = `
                    <div class="table-responsive mt-3">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Employee No.</th>
                                    <th>Employee Share</th>
                                    <th>Net Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${breakdown.map(item => `
                                    <tr>
                                        <td>${item.employee_name}</td>
                                        <td>${item.employee_no}</td>
                                        <td>${formatCurrency(item.employee_share)}</td>
                                        <td>${formatCurrency(item.net_pay)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            Swal.fire({
                title: `Remittance Details - ${remittance.remittance_type}`,
                html: `
                    <div class="text-start">
                        <p><strong>Clinic:</strong> ${remittance.clinic_name} (${remittance.clinic_code})</p>
                        <p><strong>Period:</strong> ${remittance.period_month}/${remittance.period_year}</p>
                        <p><strong>Status:</strong> <span class="badge bg-${remittance.status === 'paid' ? 'success' : 'warning'}">${remittance.status}</span></p>
                        <hr>
                        <p><strong>Employee Share:</strong> ${formatCurrency(remittance.employee_share)}</p>
                        <p><strong>Employer Share:</strong> ${formatCurrency(remittance.employer_share)}</p>
                        <h5 class="text-primary">Total: ${formatCurrency(remittance.total_amount)}</h5>
                        ${remittance.payment_date ? `<p><strong>Payment Date:</strong> ${formatDate(remittance.payment_date, false)}</p>` : ''}
                        ${remittance.reference_number ? `<p><strong>Reference No:</strong> ${remittance.reference_number}</p>` : ''}
                        ${breakdownHTML}
                    </div>
                `,
                width: '800px'
            });
        })
        .catch(err => {
            console.error("View remittance error:", err);
            Swal.fire('Error!', 'Failed to load remittance details', 'error');
        });
}

// Call this when tab is shown
document.getElementById('government-tab-pane').addEventListener('shown.bs.tab', function() {
    loadRemittances();
});

function viewPayrollForApproval(payrollId) {
    if (!permissions.approve && !permissions.reject) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve/reject payrolls', 'error');
        return;
    }
    currentPayrollId = payrollId;
    
    fetch(`api/payroll-approval.php?action=payroll_details&id=${payrollId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error', data.error || 'Failed to load payroll details', 'error');
                return;
            }

            const payroll = data.payroll;
            const attendance = data.attendance_records || [];
            const overtime = data.overtime_records || [];
            const leaves = data.leave_records || [];
            const budgetCheck = data.budget_check || {};
            const approvals = data.approvals || [];

            // ===== APPROVAL PROGRESS =====
            renderApprovalProgress(approvals);

            // ===== BUDGET CHECK DISPLAY =====
            const budgetSection = document.getElementById('budgetCheckSection');
            const budgetMessage = document.getElementById('budgetCheckMessage');
            const budgetDetails = document.getElementById('budgetCheckDetails');
            
            if (budgetSection) {
                if (budgetCheck.has_budget_plan) {
                    budgetSection.style.display = 'block';
                    document.getElementById('budgetAllocated').textContent = formatCurrency(budgetCheck.allocated);
                    document.getElementById('budgetRemaining').textContent = formatCurrency(budgetCheck.remaining);
                    document.getElementById('budgetPayrollAmount').textContent = formatCurrency(budgetCheck.payroll_amount);
                    budgetDetails.style.display = 'flex';
                    
                    if (budgetCheck.sufficient) {
                        budgetMessage.innerHTML = `<i class="bi bi-check-circle-fill text-success"></i> ${budgetCheck.message}`;
                        budgetMessage.className = 'fw-bold text-success';
                    } else {
                        budgetMessage.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-danger"></i> ${budgetCheck.message}`;
                        budgetMessage.className = 'fw-bold text-danger';
                    }
                } else {
                    budgetSection.style.display = 'block';
                    budgetMessage.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-warning"></i> ${budgetCheck.message}`;
                    budgetMessage.className = 'fw-bold text-warning';
                    budgetDetails.style.display = 'none';
                }
            }
            
            // Store budget check for approval validation
            window.currentBudgetCheck = budgetCheck;

            // ===== EMPLOYEE INFO =====
            document.getElementById('approvalEmployeeName').textContent = 
                `${payroll.employee_name} (${payroll.employee_no})`;
            document.getElementById('approvalPeriod').textContent = 
                `${payroll.payroll_period}: ${formatDate(payroll.period_start, true)} - ${formatDate(payroll.period_end, true)}`;
            document.getElementById('approvalEmployeeNo').textContent = 
                `Department: ${payroll.department || 'N/A'}`;
            document.getElementById('approvalNetPay').textContent = formatCurrency(payroll.net_pay);

            // ===== BANK DETAILS =====
            const bankNameEl = document.getElementById('approvalBankName');
            if (bankNameEl) bankNameEl.textContent = payroll.bank_name || 'Not Set';
            
            const bankHolderEl = document.getElementById('approvalBankHolder');
            if (bankHolderEl) bankHolderEl.textContent = payroll.bank_account_holder || payroll.employee_name || 'Not Set';
            
            const bankAccountEl = document.getElementById('approvalBankAccount');
            if (bankAccountEl) {
                let accountNumber = payroll.bank_account_number || 'Not Set';
                if (accountNumber !== 'Not Set' && accountNumber.length > 4) {
                    accountNumber = '****' + accountNumber.slice(-4);
                }
                bankAccountEl.textContent = accountNumber;
            }
            
            const bankStatusEl = document.getElementById('approvalBankStatus');
            if (bankStatusEl) {
                if (payroll.bank_name && payroll.bank_account_number && payroll.bank_account_holder) {
                    bankStatusEl.textContent = 'Complete';
                    bankStatusEl.className = 'badge bg-success';
                } else {
                    bankStatusEl.textContent = 'Incomplete';
                    bankStatusEl.className = 'badge bg-warning text-dark';
                }
            }

            // ===== ATTENDANCE SUMMARY =====
            if (attendance.length > 0) {
                let presentDays = 0, lateDays = 0;
                
                attendance.forEach(record => {
                    if (record.status === 'Present') presentDays++;
                    else if (record.status === 'Late') lateDays++;
                });
                
                document.getElementById('approvalPresentDays').textContent = presentDays;
                document.getElementById('approvalLateDays').textContent = lateDays;
                document.getElementById('approvalAbsentDays').textContent = '0';
                
                // Attendance records with approval status
                renderApprovalAttendanceRecords(attendance);
                document.getElementById('approvalAttendanceListSection').style.display = 'block';
            } else {
                document.getElementById('approvalPresentDays').textContent = '0';
                document.getElementById('approvalLateDays').textContent = '0';
                document.getElementById('approvalAbsentDays').textContent = '0';
                document.getElementById('approvalAttendanceListSection').style.display = 'none';
            }

            // ===== OVERTIME RECORDS =====
            if (overtime.length > 0) {
                renderApprovalOvertimeRecords(overtime, payroll);
                document.getElementById('approvalOvertimeSection').style.display = 'block';
            } else {
                document.getElementById('approvalOvertimeSection').style.display = 'none';
            }

            // ===== LEAVE RECORDS =====
            if (leaves.length > 0) {
                renderApprovalLeaveRecords(leaves);
                document.getElementById('approvalLeaveSection').style.display = 'block';
            } else {
                document.getElementById('approvalLeaveSection').style.display = 'none';
            }

            // ===== EARNINGS =====
            document.getElementById('approvalBasicSalary').textContent = formatCurrency(payroll.basic_salary);
            document.getElementById('approvalOvertime').textContent = formatCurrency(payroll.overtime);
            document.getElementById('approvalHolidayPay').textContent = formatCurrency(payroll.holiday_pay);
            document.getElementById('approvalAllowances').textContent = formatCurrency(payroll.allowances);
            document.getElementById('approvalBonuses').textContent = formatCurrency(payroll.bonuses);
            document.getElementById('approvalGrossPay').textContent = formatCurrency(payroll.gross_pay);

            // ===== DEDUCTIONS =====
            document.getElementById('approvalSSS').textContent = formatCurrency(payroll.sss_contribution);
            document.getElementById('approvalPhilhealth').textContent = formatCurrency(payroll.philhealth_contribution);
            document.getElementById('approvalPagibig').textContent = formatCurrency(payroll.pagibig_contribution);
            document.getElementById('approvalTax').textContent = formatCurrency(payroll.withholding_tax);
            document.getElementById('approvalOtherDeductions').textContent = formatCurrency(payroll.other_deductions);
            document.getElementById('approvalTotalDeductions').textContent = formatCurrency(payroll.total_deductions);

            // ===== PAYROLL INFO =====
            document.getElementById('approvalGeneratedBy').textContent = payroll.generated_by_name || 'N/A';
            document.getElementById('approvalGeneratedDate').textContent = formatDate(payroll.generated_at, false);
            document.getElementById('approvalSubmittedDate').textContent = payroll.submitted_at ? 
                formatDate(payroll.submitted_at, false) : 'Not submitted';

            // ===== SHOW MODAL =====
            const modalEl = document.getElementById('approvalDetailModal');
            currentApprovalModal = new bootstrap.Modal(modalEl);
            currentApprovalModal.show();
        })
        .catch(err => console.error("Error loading payroll details:", err));
}

function renderApprovalLeaveRecords(leaveRecords) {
    const tbody = document.getElementById('approvalLeaveList');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    leaveRecords.forEach(l => {
        let statusBadge = '';
        switch(l.status) {
            case 'approved':
                statusBadge = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
                break;
            case 'pending':
                statusBadge = '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Pending</span>';
                break;
            case 'rejected':
                statusBadge = '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + (l.status || 'Unknown') + '</span>';
        }
        
        let leaveType = l.leave_type || 'Leave';
        if (leaveType.includes('_')) {
            leaveType = leaveType.replace('_', ' ').toUpperCase();
        }
        
        tbody.innerHTML += `
             <tr>
                <td>${formatDate(l.start_date, true)}</td>
                <td>${formatDate(l.end_date, true)}</td>
                <td>${leaveType}</td>
                <td>${l.number_of_days || 0}</td>
                <td>${statusBadge}</td>
                <td><small>${l.reason || '-'}</small></td>
             </tr>
        `;
    });
}

function renderApprovalOvertimeRecords(overtimeRecords, payroll) {
    const tbody = document.getElementById('approvalOvertimeList');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    const basicSalary = parseFloat(payroll?.basic_salary || 0);
    const salaryFreq = payroll?.salary_frequency || 'monthly';
    let monthlyRate = basicSalary;
    if (salaryFreq === '15days') monthlyRate = basicSalary * 2;
    if (salaryFreq === '30days') monthlyRate = basicSalary;
    if (salaryFreq === 'weekly') monthlyRate = basicSalary * 4.33;
    if (salaryFreq === 'daily') monthlyRate = basicSalary * 22;
    const hourlyRate = monthlyRate > 0 ? monthlyRate / 22 / 8 : 0;
    
    overtimeRecords.forEach(ot => {
        const multiplier = ot.overtime_type === 'holiday' ? 2.0
                         : ot.overtime_type === 'restday' ? 1.3 : 1.25;
        const amount = parseFloat(ot.total_hours || 0) * hourlyRate * multiplier;
        
        let statusBadge = '';
        switch(ot.status) {
            case 'approved':
                statusBadge = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
                break;
            case 'pending':
                statusBadge = '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> Pending</span>';
                break;
            case 'rejected':
                statusBadge = '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + (ot.status || 'Unknown') + '</span>';
        }
        
        let typeDisplay = ot.overtime_type ? ot.overtime_type.replace('_', ' ') : '';
        typeDisplay = typeDisplay.charAt(0).toUpperCase() + typeDisplay.slice(1);
        
        tbody.innerHTML += `
             <tr>
                <td>${formatDate(ot.overtime_date, true)}</td>
                <td>${ot.total_hours} hrs</td>
                <td><span class="badge bg-info">${typeDisplay}</span></td>
                <td class="fw-bold">${formatCurrency(amount)}</td>
                <td>${statusBadge}</td>
             </tr>
        `;
    });
}

function confirmApprovePayroll() {
    if (!permissions.approve) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve payrolls', 'error');
        return;
    }
    if (!currentPayrollId) {
        Swal.fire('Error!', 'No payroll selected', 'error');
        return;
    }
    
    // ===== BUDGET CHECK BEFORE APPROVAL =====
    if (window.currentBudgetCheck && window.currentBudgetCheck.has_budget_plan) {
        if (!window.currentBudgetCheck.sufficient) {
            Swal.fire({
                icon: 'error',
                title: 'Cannot Approve - Insufficient Budget!',
                html: `
                    <div class="text-start">
                        <p><strong>Payroll Amount:</strong> ${formatCurrency(window.currentBudgetCheck.payroll_amount)}</p>
                        <p><strong>Remaining Budget:</strong> ${formatCurrency(window.currentBudgetCheck.remaining)}</p>
                        <p><strong>Shortage:</strong> ${formatCurrency(window.currentBudgetCheck.payroll_amount - window.currentBudgetCheck.remaining)}</p>
                        <hr>
                        <p class="text-danger">Please adjust the budget plan or reduce payroll amount before approving.</p>
                    </div>
                `,
                confirmButtonColor: '#dc3545'
            });
            return;
        }
    } else if (window.currentBudgetCheck && !window.currentBudgetCheck.has_budget_plan) {
        Swal.fire({
            icon: 'warning',
            title: 'No Budget Plan Found!',
            html: `
                <div class="text-start">
                    <p>No budget plan exists for this period.</p>
                    <p class="text-warning">Do you want to proceed with approval anyway?</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve Anyway',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ffc107'
        }).then(result => {
            if (result.isConfirmed) {
                processApprovePayroll();
            }
        });
        return;
    }
    
    // Close modal first
    if (currentApprovalModal) {
        currentApprovalModal.hide();
    }
    
    // Show confirmation
    Swal.fire({
        title: 'Approve Payroll?',
        text: 'This payroll will be marked as approved.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve it',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(result => {
        if (result.isConfirmed) {
            processApprovePayroll();
        } else {
            if (currentApprovalModal) {
                currentApprovalModal.show();
            }
        }
    });
}

// ===== PROCESS APPROVE =====
function processApprovePayroll() {
    Swal.fire({
        title: 'Processing...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/payroll-approval.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'approve_payroll',
            payroll_id: currentPayrollId,
            remarks: '' // Optional remarks
        })
    })
    .then(res => res.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            Swal.fire({
                title: 'Approved!',
                text: data.message || 'Payroll approved successfully',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                // Refresh data
                loadDashboardStats();
                loadPendingApprovals();
                loadApprovedPayrolls();
            });
        } else {
            Swal.fire('Error!', data.error, 'error').then(() => {
                // I-reopen ang modal kung may error
                if (currentApprovalModal) {
                    currentApprovalModal.show();
                }
            });
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('Error!', 'Failed to approve: ' + error.message, 'error').then(() => {
            // I-reopen ang modal kung may error
            if (currentApprovalModal) {
                currentApprovalModal.show();
            }
        });
    });
}

function confirmRejectPayroll() {
    if (!permissions.reject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject payrolls', 'error');
        return;
    }
    if (!currentPayrollId) {
        Swal.fire('Error!', 'No payroll selected', 'error');
        return;
    }
    
    // Isara muna ang modal
    if (currentApprovalModal) {
        currentApprovalModal.hide();
    }
    
    // Magpakita ng confirmation na may remarks
    Swal.fire({
        title: 'Reject Payroll?',
        input: 'textarea',
        inputLabel: 'Reason for rejection',
        inputPlaceholder: 'Enter detailed reason...',
        inputAttributes: {
            'required': 'required'
        },
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, reject it',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        preConfirm: (remarks) => {
            if (!remarks || !remarks.trim()) {
                Swal.showValidationMessage('Please provide a reason for rejection');
                return false;
            }
            return remarks;
        }
    }).then(result => {
        if (result.isConfirmed && result.value) {
            // Proceed with rejection
            processRejectPayroll(result.value);
        } else {
            // I-reopen ang modal kung nag-cancel
            if (currentApprovalModal) {
                currentApprovalModal.show();
            }
        }
    });
}

// ===== PROCESS REJECT =====
function processRejectPayroll(remarks) {
    Swal.fire({
        title: 'Processing...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/payroll-approval.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'reject_payroll',
            payroll_id: currentPayrollId,
            remarks: remarks
        })
    })
    .then(res => res.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            Swal.fire({
                title: 'Rejected!',
                text: data.message || 'Payroll rejected successfully',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                // Refresh data
                loadDashboardStats();
                loadPendingApprovals();
                if (typeof loadRejectedPayrolls === 'function') {
                    loadRejectedPayrolls();
                }
            });
        } else {
            Swal.fire('Error!', data.error, 'error').then(() => {
                // I-reopen ang modal kung may error
                if (currentApprovalModal) {
                    currentApprovalModal.show();
                }
            });
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('Error!', 'Failed to reject: ' + error.message, 'error').then(() => {
            // I-reopen ang modal kung may error
            if (currentApprovalModal) {
                currentApprovalModal.show();
            }
        });
    });
}


function renderApprovalAttendanceRecords(records) {
    const tbody = document.getElementById('approvalAttendanceRecordsList');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    records.forEach(record => {
        let statusClass = 'badge bg-success';
        let statusText = record.status || 'Present';
        if (statusText === 'Late') statusClass = 'badge bg-warning text-dark';
        else if (statusText === 'Absent') statusClass = 'badge bg-danger';
        else if (statusText === 'Half-day') statusClass = 'badge bg-info';
        
        // ✅ ADD APPROVAL STATUS BADGE
        let approvalBadge = '';
        switch(record.approval_status) {
            case 'approved':
                approvalBadge = '<span class="badge bg-success ms-1"><i class="bi bi-check-circle"></i></span>';
                break;
            case 'pending':
                approvalBadge = '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-clock-history"></i></span>';
                break;
            case 'rejected':
                approvalBadge = '<span class="badge bg-danger ms-1"><i class="bi bi-x-circle"></i></span>';
                break;
            default:
                approvalBadge = '';
        }
        
        const timeIn = record.time_in ? record.time_in.substring(0,5) : 'N/A';
        const timeOut = record.time_out ? record.time_out.substring(0,5) : 'N/A';
        const hours = record.total_hours ? parseFloat(record.total_hours).toFixed(2) : '0.00';
        
        tbody.innerHTML += `
             <tr>
                <td>${formatDate(record.date, true)}</td>
                <td>${timeIn}</td>
                <td>${timeOut}</td>
                <td>${hours}</td>
                <td><span class="${statusClass}">${statusText}</span> ${approvalBadge}</td>
             </tr>
        `;
    });
}


function renderApprovalProgress(approvals) {
    const container = document.getElementById('approvalProgressBar');
    container.innerHTML = '<h6 class="fw-bold mb-3">Approval Progress</h6>';
    
    const progressDiv = document.createElement('div');
    progressDiv.className = 'd-flex align-items-center gap-2 mb-3';
    
    approvals.forEach((approval, index) => {
        let stepClass = 'approval-step ';
        let icon = '';
        
        if (approval.status === 'approved') {
            stepClass += 'bg-success text-white';
            icon = '<i class="bi bi-check"></i>';
        } else if (approval.status === 'rejected') {
            stepClass += 'bg-danger text-white';
            icon = '<i class="bi bi-x"></i>';
        } else if (approval.status === 'pending') {
            stepClass += 'bg-warning text-dark';
            icon = `<span>${index + 1}</span>`;
        } else {
            stepClass += 'bg-secondary text-white';
            icon = '<i class="bi bi-question"></i>';
        }
        
        progressDiv.innerHTML += `
            <div class="d-flex flex-column align-items-center">
                <div class="${stepClass}">
                    ${icon}
                </div>
                <small class="mt-1">${approval.approver_name}</small>
            </div>
            ${index < approvals.length - 1 ? '<div class="flex-grow-1 border-top"></div>' : ''}
        `;
    });
    
    container.appendChild(progressDiv);
}

// ===== FIXED: Download CSV with Base64 decoding =====
function downloadCSV(filename, csvContent) {
    try {
        // Check if csvContent is actually the filename
        if (csvContent === filename) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid CSV Data',
                text: 'The CSV content is missing. Please try again.',
                confirmButtonColor: '#dc3545'
            });
            return;
        }
        
        // ===== IMPORTANT: DECODE BASE64 =====
        let decodedContent;
        try {
            decodedContent = atob(csvContent);
        } catch (e) {
            decodedContent = csvContent;
        }
        
        // Create blob with proper encoding
        const blob = new Blob(["\uFEFF" + decodedContent], { 
            type: 'text/csv;charset=utf-8;' 
        });
        
        // Download
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
    } catch (error) {
        console.error('Download failed:', error);
        Swal.fire({
            icon: 'error',
            title: 'Download Failed',
            text: error.message
        });
    }
}

function moveToReadyForRelease() {
    if (!permissions.approve) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve payrolls', 'error');
        return;
    }
    if (selectedPayrolls.size === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Selection',
            text: 'Please select at least one payroll to move to Ready for Release.',
            confirmButtonColor: '#3085d6'
        });
        return;
    }
    
    Swal.fire({
        title: 'Move to Ready for Release?',
        html: `
            <div class="text-start">
                <p>You are about to move <strong>${selectedPayrolls.size} payroll(s)</strong> to "Ready for Release".</p>
                <p class="text-info">
                    <i class="bi bi-info-circle"></i> This will also generate a CSV file for bank transfer.
                </p>
                <p><strong>Total Amount:</strong> ${document.getElementById('selectedTotal').textContent}</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Move & Generate CSV',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#6c757d',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                html: `
                    <div class="text-center">
                        <div class="spinner-border text-primary mb-3"></div>
                        <p>Moving payrolls and generating CSV...</p>
                    </div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false
            });
            
            const payrollIds = Array.from(selectedPayrolls);
            
            // First, move to ready
            fetch('api/payroll-approval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'move_to_ready',
                    payroll_ids: payrollIds
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to move payrolls');
                }
                
                // Then generate CSV
                return fetch('api/payroll-approval.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'generate_bank_csv',
                        payroll_ids: payrollIds 
                    })
                });
            })
            .then(res => res.json())
            .then(csvData => {
                if (!csvData.success) {
                    throw new Error(csvData.error || 'Failed to generate CSV');
                }
                
                Swal.close();
                
                // Show success with download button
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    html: `
                        <div class="text-start">
                            <p class="mb-2"><i class="bi bi-check-circle-fill text-success"></i> Payrolls moved to Ready</p>
                            <p><i class="bi bi-file-earmark-spreadsheet-fill text-primary"></i> CSV generated: <strong>${csvData.filename}</strong></p>
                            <p class="text-muted small">Total: ₱${csvData.total_amount.toLocaleString()}</p>
                            <p class="text-muted small">Records: ${csvData.record_count || 0}</p>
                            <hr>
                            <button class="btn btn-sm btn-success mt-2" onclick="downloadCSV('${csvData.filename}', '${csvData.csv_content}')">
                                <i class="bi bi-download"></i> Download CSV Now
                            </button>
                        </div>
                    `,
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#198754'
                });
                
                // Clear selection and reload data
                selectedPayrolls.clear();
                document.getElementById('selectAllApproved').checked = false;
                document.getElementById('selectedTotal').textContent = '₱0.00';
                document.getElementById('selectedCount').textContent = '0 selected';
                
                // Reload data
                loadDashboardStats();
                loadApprovedPayrolls();
                loadReadyForRelease();
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: error.message || 'Failed to process',
                    confirmButtonColor: '#dc3545'
                });
            });
        }
    });
}

function generateCSVAfterMove(payrollIds) {
    console.log('=== GENERATE CSV AFTER MOVE ===');
    console.log('Payroll IDs:', payrollIds);
    
    // FIXED: Removed query parameter, added action in body
    return fetch('api/payroll-approval.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'generate_bank_csv',  // Added action here
            payroll_ids: payrollIds 
        })
    })
    .then(response => {
        return response.json();
    })
    .then(data => {
        if (!data.success) {
            throw new Error(data.error || 'Failed to generate CSV');
        }
        return data;
    })
    .catch(error => {
        throw error;
    });
}

function togglePayrollSelection(payrollId, isChecked) {
    if (isChecked) {
        selectedPayrolls.add(payrollId.toString());
    } else {
        selectedPayrolls.delete(payrollId.toString());
    }
    
    // Update select all checkbox
    const totalCheckboxes = document.querySelectorAll('#approvedTableBody input[type="checkbox"]').length;
    const checkedCount = selectedPayrolls.size;
    document.getElementById('selectAllApproved').checked = totalCheckboxes > 0 && checkedCount === totalCheckboxes;
    document.getElementById('selectAllApproved').indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
    
    calculateSelectedTotal();
}

function calculateSelectedTotal() {
    if (selectedPayrolls.size === 0) {
        document.getElementById('selectedTotal').textContent = '₱0.00';
        return;
    }
    
    let total = 0;
    const rows = document.querySelectorAll('#approvedTableBody tr');
    
    rows.forEach(row => {
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            const netPayCell = row.cells[5]; // Net Pay is in 6th column (0-indexed)
            const netPayText = netPayCell.textContent.trim();
            const netPayValue = parseFloat(netPayText.replace(/[^0-9.-]+/g, '')) || 0;
            total += netPayValue;
        }
    });
    
    document.getElementById('selectedTotal').textContent = formatCurrency(total);
}

function releaseSelectedPayrolls() {
    if (selectedPayrolls.size === 0) {
        Swal.fire('Warning!', 'Please select at least one payroll to release.', 'warning');
        return;
    }
    
    openReleaseBatchModal();
}

function openReleaseBatchModal() {
    new bootstrap.Modal(document.getElementById('releaseBatchModal')).show();
}

function confirmBatchRelease() {
    const paymentMethod = document.getElementById('batchPaymentMethod').value;
    const releaseDate = document.getElementById('batchReleaseDate').value;
    const reference = document.getElementById('batchReference').value;
    
    const payrollIds = Array.from(selectedPayrolls);
    
    Swal.fire({
        title: 'Release Selected Payrolls?',
        html: `
            <div class="text-start">
                <p><strong>Number of Payrolls:</strong> ${payrollIds.length}</p>
                <p><strong>Total Amount:</strong> ${document.getElementById('selectedTotal').textContent}</p>
                <p><strong>Payment Method:</strong> ${paymentMethod}</p>
                <p class="text-warning"><i class="bi bi-exclamation-triangle"></i> This action will generate payslips and mark payrolls as released.</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, release them',
        confirmButtonColor: '#0d6efd'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('api/payroll-approval.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'release_batch',
                    payroll_ids: payrollIds,
                    payment_method: paymentMethod,
                    release_date: releaseDate,
                    reference: reference
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Released!',
                        html: `
                            <p>${data.message}</p>
                            <p><strong>Released:</strong> ${data.released_count} payrolls</p>
                            ${data.payslip_codes ? `<p><strong>Payslip Codes:</strong> ${data.payslip_codes.join(', ')}</p>` : ''}
                        `,
                        icon: 'success'
                    });
                    bootstrap.Modal.getInstance(document.getElementById('releaseBatchModal')).hide();
                    
                    // Reset selection
                    selectedPayrolls.clear();
                    document.getElementById('selectAllApproved').checked = false;
                    
                    // Reload data
                    loadDashboardStats();
                    loadApprovedPayrolls();
                    loadReleasedPayrolls();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }
    });
}



function openGovernmentRemittanceModal() {
    // Load current month's remittance data
    const currentMonth = new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=remittance_summary&month=${currentMonth}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('sssEmployeeShare').textContent = formatCurrency(data.sss_employee || 0);
                document.getElementById('sssEmployerShare').textContent = formatCurrency(data.sss_employer || 0);
                document.getElementById('sssTotal').textContent = formatCurrency(data.sss_total || 0);
                
                document.getElementById('philhealthEmployeeShare').textContent = formatCurrency(data.philhealth_employee || 0);
                document.getElementById('philhealthEmployerShare').textContent = formatCurrency(data.philhealth_employer || 0);
                document.getElementById('philhealthTotal').textContent = formatCurrency(data.philhealth_total || 0);
                
                document.getElementById('pagibigEmployeeShare').textContent = formatCurrency(data.pagibig_employee || 0);
                document.getElementById('pagibigEmployerShare').textContent = formatCurrency(data.pagibig_employer || 0);
                document.getElementById('pagibigTotal').textContent = formatCurrency(data.pagibig_total || 0);
                
                document.getElementById('taxWithheld').textContent = formatCurrency(data.tax_withheld || 0);
            }
        });
    
    new bootstrap.Modal(document.getElementById('governmentRemittanceModal')).show();
}


function generateRemittance() {
    const type = document.getElementById('remittanceType').value;
    const period = document.getElementById('remittancePeriod').value;
    
    Swal.fire({
        title: 'Generate Remittance Report?',
        text: `This will generate a ${type} report for ${period}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Generate Report'
    }).then(result => {
        if (result.isConfirmed) {
            // In real implementation, this would generate a PDF
            Swal.fire('Report Generated!', 'Report has been generated and saved.', 'success');
        }
    });
}

function generateSSSFile() {
    if (!permissions.view) {
        Swal.fire('Access Denied', 'You don\'t have permission to export', 'error');
        return;
    }
    const currentMonth = document.getElementById('remittancePeriod')?.value || new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=get_sss_data&period=${currentMonth}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.employees.length > 0) {
                let csv = "SSS Number,Employee Name,Employee Share,Employer Share,Total,Period\n";
                let totalEmpShare = 0, totalEmprShare = 0;
                
                data.employees.forEach(emp => {
                    const empShare = parseFloat(emp.sss_employee || 0);
                    const emprShare = parseFloat(emp.sss_employer || 0);
                    const total = empShare + emprShare;
                    
                    csv += `${emp.sss_number || 'N/A'},${emp.employee_name},`;
                    csv += `${empShare.toFixed(2)},${emprShare.toFixed(2)},`;
                    csv += `${total.toFixed(2)},${currentMonth}\n`;
                    
                    totalEmpShare += empShare;
                    totalEmprShare += emprShare;
                });
                
                // Add total row
                csv += `TOTAL:,,${totalEmpShare.toFixed(2)},${totalEmprShare.toFixed(2)},${(totalEmpShare + totalEmprShare).toFixed(2)},\n`;
                
                downloadCSV(csv, `SSS_Remittance_${currentMonth}.csv`);
            } else {
                Swal.fire('No Data', 'No SSS contributions found for this period', 'info');
            }
        });
}

// Generate PhilHealth CSV File
function generatePhilHealthFile() {
    const currentMonth = document.getElementById('remittancePeriod')?.value || new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=get_philhealth_data&period=${currentMonth}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.employees.length > 0) {
                let csv = "PhilHealth Number,Employee Name,Employee Share,Employer Share,Total,Period\n";
                let totalEmpShare = 0, totalEmprShare = 0;
                
                data.employees.forEach(emp => {
                    const empShare = parseFloat(emp.philhealth_employee || 0);
                    const emprShare = parseFloat(emp.philhealth_employer || 0);
                    const total = empShare + emprShare;
                    
                    csv += `${emp.philhealth_number || 'N/A'},${emp.employee_name},`;
                    csv += `${empShare.toFixed(2)},${emprShare.toFixed(2)},`;
                    csv += `${total.toFixed(2)},${currentMonth}\n`;
                    
                    totalEmpShare += empShare;
                    totalEmprShare += emprShare;
                });
                
                csv += `TOTAL:,,${totalEmpShare.toFixed(2)},${totalEmprShare.toFixed(2)},${(totalEmpShare + totalEmprShare).toFixed(2)},\n`;
                
                downloadCSV(csv, `PhilHealth_Remittance_${currentMonth}.csv`);
            } else {
                Swal.fire('No Data', 'No PhilHealth contributions found for this period', 'info');
            }
        });
}

// Generate Pag-IBIG CSV File
function generatePagIBIGFile() {
    const currentMonth = document.getElementById('remittancePeriod')?.value || new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=get_pagibig_data&period=${currentMonth}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.employees.length > 0) {
                let csv = "Pag-IBIG Number,Employee Name,Employee Share,Employer Share,Total,Period\n";
                let totalEmpShare = 0, totalEmprShare = 0;
                
                data.employees.forEach(emp => {
                    const empShare = parseFloat(emp.pagibig_employee || 0);
                    const emprShare = parseFloat(emp.pagibig_employer || 0);
                    const total = empShare + emprShare;
                    
                    csv += `${emp.pagibig_number || 'N/A'},${emp.employee_name},`;
                    csv += `${empShare.toFixed(2)},${emprShare.toFixed(2)},`;
                    csv += `${total.toFixed(2)},${currentMonth}\n`;
                    
                    totalEmpShare += empShare;
                    totalEmprShare += emprShare;
                });
                
                csv += `TOTAL:,,${totalEmpShare.toFixed(2)},${totalEmprShare.toFixed(2)},${(totalEmpShare + totalEmprShare).toFixed(2)},\n`;
                
                downloadCSV(csv, `PagIBIG_Remittance_${currentMonth}.csv`);
            } else {
                Swal.fire('No Data', 'No Pag-IBIG contributions found for this period', 'info');
            }
        });
}

// Generate BIR File (1601C Format)
function generateBIRFile() {
    const currentMonth = document.getElementById('remittancePeriod')?.value || new Date().toISOString().slice(0, 7);
    
    fetch(`api/payroll-approval.php?action=get_bir_data&period=${currentMonth}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.employees.length > 0) {
                // BIR 1601C Format
                let content = "BIR Form No. 1601C\n";
                content += `For the Month: ${currentMonth}\n`;
                content += "Generated: " + new Date().toLocaleString() + "\n";
                content += "=".repeat(80) + "\n";
                content += "TIN\tEmployee Name\tGross Pay\tTax Withheld\tStatus\n";
                
                let totalTax = 0;
                
                data.employees.forEach(emp => {
                    const tax = parseFloat(emp.withholding_tax || 0);
                    content += `${emp.tin_number || 'N/A'}\t`;
                    content += `${emp.employee_name}\t`;
                    content += `${parseFloat(emp.gross_pay || 0).toFixed(2)}\t`;
                    content += `${tax.toFixed(2)}\t`;
                    content += `${emp.civil_status || 'S/ME'}\n`;
                    
                    totalTax += tax;
                });
                
                content += "=".repeat(80) + "\n";
                content += `TOTAL TAX WITHHELD:\t\t\t${totalTax.toFixed(2)}\n`;
                
                downloadCSV(content, `BIR_1601C_${currentMonth}.txt`);
            } else {
                Swal.fire('No Data', 'No withholding tax found for this period', 'info');
            }
        });
}

// Generate all reports
function generateAllReports() {
    generateSSSFile();
    setTimeout(generatePhilHealthFile, 500);
    setTimeout(generatePagIBIGFile, 1000);
    setTimeout(generateBIRFile, 1500);
    
    Swal.fire('Processing', 'All reports are being generated', 'success');
}


function viewPayslip(payrollId) {
    // Generate or view payslip
    window.open(`views/generate_payslip.php?id=${payrollId}`, '_blank');
}

function viewRemittance(remittanceId) {
    fetch(`api/payroll-approval.php?action=view_remittance&id=${remittanceId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error!', data.error || 'Failed to load details', 'error');
                return;
            }
            
            const r = data.remittance;
            const breakdown = data.breakdown || [];
            
            // Format period
            const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            const periodText = `${monthNames[r.period_month - 1]} ${r.period_year}`;
            
            // Build breakdown table if exists
            let breakdownHTML = '';
            if (breakdown.length > 0) {
                breakdownHTML = `
                    <div class="mt-4">
                        <h6 class="fw-bold mb-2">Employee Breakdown</h6>
                        <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th class="text-end">Employee Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${breakdown.map(item => `
                                        <tr>
                                            <td>${item.employee_name || 'N/A'}</td>
                                            <td class="text-end">${formatCurrency(item.employee_share)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }
            
            // Status badge
            const statusBadge = {
                'pending': '<span class="badge bg-warning">Pending</span>',
                'paid': '<span class="badge bg-success">Paid</span>',
                'overdue': '<span class="badge bg-danger">Overdue</span>'
            }[r.status] || '<span class="badge bg-secondary">Unknown</span>';
            
            Swal.fire({
                title: `${r.remittance_type} Remittance - ${periodText}`,
                html: `
                    <div class="text-start">
                        <!-- Status -->
                        <div class="mb-3 text-center">
                            ${statusBadge}
                        </div>
                        
                        <!-- Summary Card -->
                        <div class="bg-light p-3 rounded mb-3">
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted d-block">Employee Share</small>
                                    <strong>${formatCurrency(r.employee_share)}</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Employer Share</small>
                                    <strong>${formatCurrency(r.employer_share)}</strong>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">Total Amount:</span>
                                <span class="fw-bold text-primary">${formatCurrency(r.total_amount)}</span>
                            </div>
                        </div>
                        
                        <!-- Details -->
                        <div class="row mb-2">
                            <div class="col-5 text-muted">Due Date:</div>
                            <div class="col-7">${r.due_date || 'N/A'}</div>
                        </div>
                        
                        ${r.status === 'paid' ? `
                            <div class="row mb-2">
                                <div class="col-5 text-muted">Payment Method:</div>
                                <div class="col-7">${r.payment_method || 'N/A'}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted">Reference #:</div>
                                <div class="col-7">${r.reference_number || 'N/A'}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted">Payment Date:</div>
                                <div class="col-7">${r.payment_date ? formatDate(r.payment_date) : 'N/A'}</div>
                            </div>
                            ${r.file_path ? `
                                <div class="row mb-2">
                                    <div class="col-5 text-muted">Proof:</div>
                                    <div class="col-7">
                                        <a href="${r.file_path}" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark"></i> View File
                                        </a>
                                    </div>
                                </div>
                            ` : ''}
                        ` : ''}
                        
                        ${breakdownHTML}
                    </div>
                `,
                width: '600px',
                showCancelButton: r.status === 'pending',
                confirmButtonText: r.status === 'pending' ? 'Mark as Paid' : 'Close',
                cancelButtonText: 'Close',
                confirmButtonColor: '#28a745',
                showCloseButton: true
            }).then(result => {
                if (result.isConfirmed && r.status === 'pending') {
                    markRemittancePaid(remittanceId);
                }
            });
        })
        .catch(err => {
            console.error("View remittance error:", err);
            Swal.fire('Error!', 'Failed to load remittance details', 'error');
        });
}

function markRemittancePaid(remittanceId) {
    // Get remittance details first
    fetch(`api/payroll-approval.php?action=view_remittance&id=${remittanceId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                Swal.fire('Error!', 'Failed to load remittance details', 'error');
                return;
            }
            
            const remittance = data.remittance;
            const totalAmount = remittance.total_amount || 0;
            
            Swal.fire({
                title: 'Mark Remittance as Paid',
                html: `
                    <div class="text-start" style="max-height: 70vh; overflow-y: auto; padding: 5px;">
                        <!-- Summary -->
                        <div class="alert alert-info p-2 mb-3">
                            <div class="d-flex justify-content-between">
                                <span><strong>Type:</strong> ${remittance.remittance_type}</span>
                                <span><strong>Period:</strong> ${remittance.period_month}/${remittance.period_year}</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span><strong>Total Amount:</strong></span>
                                <span class="fw-bold text-primary">${formatCurrency(totalAmount)}</span>
                            </div>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="paymentMethodRemit" required>
                                <option value="">Select payment method</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="GCash">GCash</option>
                                <option value="Maya">Maya</option>
                                <option value="Over the Counter">Over the Counter</option>
                                <option value="Check">Check</option>
                            </select>
                        </div>
                        
                        <!-- Reference Number -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Reference Number <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="referenceNumberRemit" 
                                   placeholder="OR number / Transaction ID" required>
                        </div>
                        
                        <!-- Payment Date -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Payment Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="paymentDateRemit" 
                                   value="${new Date().toISOString().split('T')[0]}" required>
                        </div>
                        
                        <!-- Amount Paid -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Amount Paid <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="amountPaidRemit" 
                                       step="0.01" min="0" value="${totalAmount}" required>
                            </div>
                        </div>
                        
                        <!-- Proof of Payment -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Proof of Payment</label>
                            <input type="file" class="form-control" id="proofFileRemit" 
                                   accept=".jpg,.jpeg,.png,.pdf">
                            <small class="text-muted">Upload receipt or screenshot</small>
                        </div>
                        
                        <!-- Remarks -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remarks (Optional)</label>
                            <textarea class="form-control" id="remarksRemit" rows="2" 
                                      placeholder="Additional notes..."></textarea>
                        </div>
                        
                        <!-- Confirmation -->
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmPaidRemit" required>
                            <label class="form-check-label" for="confirmPaidRemit">
                                I confirm that payment has been made
                            </label>
                        </div>
                    </div>
                `,
                width: '550px',
                showCancelButton: true,
                confirmButtonText: 'Confirm Payment',
                confirmButtonColor: '#28a745',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const paymentMethod = document.getElementById('paymentMethodRemit').value;
                    const referenceNumber = document.getElementById('referenceNumberRemit').value;
                    const paymentDate = document.getElementById('paymentDateRemit').value;
                    const amountPaid = document.getElementById('amountPaidRemit').value;
                    const proofFile = document.getElementById('proofFileRemit').files[0];
                    const remarks = document.getElementById('remarksRemit').value;
                    const isConfirmed = document.getElementById('confirmPaidRemit').checked;
                    
                    if (!paymentMethod) {
                        Swal.showValidationMessage('Please select payment method');
                        return false;
                    }
                    if (!referenceNumber) {
                        Swal.showValidationMessage('Please enter reference number');
                        return false;
                    }
                    if (!paymentDate) {
                        Swal.showValidationMessage('Please select payment date');
                        return false;
                    }
                    if (!amountPaid || amountPaid <= 0) {
                        Swal.showValidationMessage('Please enter valid amount');
                        return false;
                    }
                    if (!isConfirmed) {
                        Swal.showValidationMessage('Please confirm payment');
                        return false;
                    }
                    
                    return { paymentMethod, referenceNumber, paymentDate, amountPaid, proofFile, remarks };
                }
            }).then(result => {
                if (result.isConfirmed) {
                    const { paymentMethod, referenceNumber, paymentDate, amountPaid, proofFile, remarks } = result.value;
                    
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    
                    const formData = new FormData();
                    formData.append('action', 'mark_remittance_paid');
                    formData.append('id', remittanceId);
                    formData.append('payment_method', paymentMethod);
                    formData.append('reference_number', referenceNumber);
                    formData.append('payment_date', paymentDate);
                    formData.append('amount_paid', amountPaid);
                    formData.append('remarks', remarks);
                    
                    if (proofFile) {
                        formData.append('proof_file', proofFile);
                    }
                    
                    fetch('api/payroll-approval.php', {
                        method: 'POST',
                        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('token') || '') },
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment Recorded!',
                                html: `
                                    <div class="text-start">
                                        <p>Remittance marked as paid.</p>
                                        <small class="text-muted">Ref: ${referenceNumber}</small>
                                    </div>
                                `,
                                confirmButtonColor: '#28a745'
                            });
                            loadRemittances();
                        } else {
                            Swal.fire('Error!', data.error || 'Failed to update', 'error');
                        }
                    });
                }
            });
        });
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString, dateOnly = false) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    
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

