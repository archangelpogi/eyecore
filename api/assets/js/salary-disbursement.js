let currentPageUrl = window.location.href;
let selectedPayments = [];
let disbursementData = [];
let dataTableInstance = null;

// Document Ready
$(document).ready(function() {
    loadStats();
    loadDisbursements();
    loadPeriodOptions();
    
    // Period filter change handler
    $('#periodFilter').change(function() {
        if ($(this).val() === 'custom') {
            $('#customDateRange').slideDown();
        } else {
            $('#customDateRange').slideUp();
            applyFilters();
        }
    });
    
    // Select all checkbox in header
    $('#selectAllCheckbox').change(function() {
        toggleAllRows();
    });
});

function loadPeriodOptions() {
    fetch('api/salary_disbursement.php?action=get_periods')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.periods && data.periods.length > 0) {
                let options = '<option value="current">Current Period</option>';
                options += '<option value="previous">Previous Period</option>';
                
                // Add available periods from database
                data.periods.forEach(p => {
                    const startDate = new Date(p.period_start);
                    const endDate = new Date(p.period_end);
                    const label = `${startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} (${p.record_count} employees)`;
                    options += `<option value="${p.period_start}_${p.period_end}">${label}</option>`;
                });
                
                options += '<option value="custom">Custom Range</option>';
                $('#periodFilter').html(options);
                
                // Set to the latest period automatically
                if (data.periods.length > 0) {
                    const latest = data.periods[0];
                    $('#periodFilter').val(`${latest.period_start}_${latest.period_end}`);
                    loadDisbursements();
                }
            }
        })
        .catch(error => console.error('Error loading periods:', error));
}

// DESTROY DataTable
function destroyDataTable() {
    if ($.fn.DataTable && $.fn.DataTable.isDataTable('#disbursementTable')) {
        try {
            $('#disbursementTable').DataTable().destroy();
        } catch (e) {
            console.log('DataTable destroy error:', e);
        }
    }
    $('#disbursementTable').removeClass('dataTable no-footer');
    $('#disbursementTable thead').removeClass('no-border');
    dataTableInstance = null;
}

// INITIALIZE DataTable
function initDataTable() {
    destroyDataTable();
    
    setTimeout(function() {
        const hasColspan = $('#disbursementTable tbody tr td[colspan]').length > 0;
        
        if (hasColspan) {
            return;
        }
        
        try {
            dataTableInstance = $('#disbursementTable').DataTable({
                paging: true,
                pageLength: 25,
                lengthChange: false,
                searching: false,
                info: true,
                order: [[1, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [0, 9] }, // Checkbox and Actions (reduced to 9 columns)
                    { className: 'text-end', targets: [3, 4, 5, 6] }
                ],
                language: {
                    emptyTable: "No data available",
                    zeroRecords: "No matching records",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)"
                },
                autoWidth: false,
                deferRender: true,
                destroy: true
            });
        } catch (e) {
            console.error('DataTable init error:', e);
        }
    }, 100);
}

// ===== RENDER FUNCTION - CLEANED UP =====
function renderDisbursementTable(disbursements) {
    const tbody = $('#disbursementTableBody');
    
    destroyDataTable();
    tbody.empty();
    
    if (!disbursements || disbursements.length === 0) {
        tbody.html(`
            <tr class="no-data-row">
                <td colspan="10" class="text-center py-5">
                    <i class="bi bi-cash-stack display-6 text-muted"></i>
                    <p class="mt-2 text-muted">No disbursements found for this period</p>
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    disbursements.forEach(dis => {
        const statusClass = getStatusClass(dis.status);
        const statusIcon = getStatusIcon(dis.status);
        
        // Only 'queued' and 'failed' are selectable for CSV generation
        const isSelectable = dis.status === 'queued' || dis.status === 'failed';
        
        html += `
            <tr class="hover-row" data-id="${dis.id}">
                <td>
                    <input type="checkbox" class="form-check-input row-checkbox" 
                           data-id="${dis.id}" data-amount="${dis.net_pay || 0}"
                           onchange="updateSelection(this)"
                           ${!isSelectable ? 'disabled' : ''}>
                </td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-2">
                            <i class="bi bi-person text-primary"></i>
                        </div>
                        <div>
                            <div class="fw-bold">${escapeHtml(dis.employee_name || 'N/A')}</div>
                            <small class="text-muted">${escapeHtml(dis.employee_no || 'N/A')}</small>
                            <div class="mt-1">
                                <span class="badge bg-light text-dark">${escapeHtml(dis.position || 'N/A')}</span>
                                <span class="badge bg-info">${escapeHtml(dis.department || 'N/A')}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="fw-bold">${escapeHtml(dis.bank_name || 'Not Set')}</div>
                    <small class="text-muted">${escapeHtml(dis.bank_account_number || '****0000')}</small>
                    <div><small class="text-muted">${escapeHtml(dis.bank_account_holder || dis.employee_name || 'N/A')}</small></div>
                </td>
                <td class="text-end fw-bold">₱${formatNumber(dis.basic_salary)}</td>
                <td class="text-end">₱${formatNumber(dis.allowances)}</td>
                <td class="text-end text-danger">₱${formatNumber(dis.total_deductions)}</td>
                <td class="text-end">
                    <span class="fw-bold text-primary">₱${formatNumber(dis.net_pay)}</span>
                </td>
                <td>
                    <span class="badge ${statusClass}">
                        <i class="bi ${statusIcon} me-1"></i>
                        ${capitalizeFirst(dis.status)}
                    </span>
                </td>
                <td>
                    <small>${formatDate(dis.due_date)}</small>
                    ${dis.paid_date ? `
                        <br><small class="text-success">Paid: ${formatDate(dis.paid_date)}</small>
                    ` : ''}
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="viewPaymentDetails(${dis.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                        ${dis.payslip_code ? `
                            <button class="btn btn-outline-info" onclick="viewPayslip('${escapeHtml(dis.payslip_code)}')">
                                <i class="bi bi-file-pdf"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
    $('#selectAllCheckbox').prop('checked', false);
    updateSelectedCount();
    initDataTable();
}

// LOAD DISBURSEMENTS
function loadDisbursements() {
    const period = $('#periodFilter').val() || 'current';
    const status = $('#statusFilter').val() || 'all';
    const department = $('#deptFilter').val() || 'all';
    const search = $('#searchFilter').val() || '';
    
    let url = `api/salary_disbursement.php?action=get_disbursements&period=${encodeURIComponent(period)}&status=${encodeURIComponent(status)}&department=${encodeURIComponent(department)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    destroyDataTable();
    
    $('#disbursementTableBody').empty().html(`
        <tr>
            <td colspan="10" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading disbursement data...</p>
            </td>
        </tr>
    `);
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                disbursementData = data.data || [];
                renderDisbursementTable(disbursementData);
                if (data.period) {
                    updatePeriodLabel(data.period);
                }
                $('#tableSummary').text(`Showing ${disbursementData.length} of ${data.total || 0} records`);
            } else {
                console.error('API Error:', data.error);
                showError(data.error || 'Failed to load data');
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            showError('Failed to connect to server. Please try again.');
        });
}

// Show error message
function showError(message) {
    destroyDataTable();
    $('#disbursementTableBody').html(`
        <tr>
            <td colspan="10" class="text-center py-5 text-danger">
                <i class="bi bi-exclamation-triangle display-6"></i>
                <p class="mt-2">${message}</p>
                <button class="btn btn-primary mt-3" onclick="refreshData()">
                    <i class="bi bi-arrow-clockwise"></i> Try Again
                </button>
            </td>
        </tr>
    `);
}

// ===== LOAD STATS - SIMPLIFIED =====
function loadStats() {
    const period = $('#periodFilter').val() || 'current';
    
    fetch(`api/salary_disbursement.php?action=get_stats&period=${encodeURIComponent(period)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const s = data.stats || {};
                
                $('#totalNetPay').text('₱' + formatNumber(s.total_net || 0));
                $('#totalEmployees').text((s.total_employees || 0) + ' employees');
                
                $('#pendingCount').text(s.queued_count || 0);
                $('#pendingAmount').text('₱' + formatNumber(s.total_queued || 0));
                
                $('#completedCount').text(s.released_count || 0);
                $('#completedAmount').text('₱' + formatNumber(s.total_released || 0));
                
                $('#failedCount').text(s.failed_count || 0);
                $('#failedAmount').text('₱' + formatNumber(s.total_failed || 0));
            }
        })
        .catch(error => console.error('Error loading stats:', error));
}

// UPDATE PERIOD LABEL
function updatePeriodLabel(period) {
    if (period && period.start && period.end) {
        try {
            const start = new Date(period.start);
            const end = new Date(period.end);
            const label = `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
            $('#currentPeriodLabel').text(label);
        } catch (e) {
            console.error('Error formatting date:', e);
        }
    }
}

// SELECTION HANDLERS
function toggleAll(source) {
    const checkboxes = $('.row-checkbox:not(:disabled)');
    checkboxes.prop('checked', source.checked);
    updateSelection();
}

function toggleAllRows() {
    const isChecked = $('#selectAllCheckbox').is(':checked');
    $('.row-checkbox:not(:disabled)').each(function() {
        $(this).prop('checked', isChecked);
    });
    updateSelection();
}

function updateSelection() {
    selectedPayments = [];
    let totalAmount = 0;
    let totalDeductions = 0;
    
    $('.row-checkbox:checked').each(function() {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const netPay = parseFloat($(this).data('amount')) || 0;
        
        const deductionsText = row.find('td:eq(5)').text().replace(/[₱,]/g, '');
        const deductions = parseFloat(deductionsText) || 0;
        
        selectedPayments.push(id);
        totalAmount += netPay;
        totalDeductions += deductions;
    });
    
    $('#batchTotal').text('₱' + formatNumber(totalAmount));
    $('#batchDeductions').text('₱' + formatNumber(totalDeductions));
    $('#batchNetPayable').text('₱' + formatNumber(totalAmount - totalDeductions));
    $('#batchCount').text(`(${selectedPayments.length} selected)`);
    $('#selectedCount').text(`${selectedPayments.length} selected`);
    
    const totalCheckboxes = $('.row-checkbox:not(:disabled)').length;
    const checkedCheckboxes = $('.row-checkbox:checked').length;
    
    if (totalCheckboxes > 0) {
        $('#selectAllCheckbox').prop('checked', totalCheckboxes === checkedCheckboxes);
        $('#selectAllCheckbox').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
    }
}

function updateSelectedCount() {
    const count = $('.row-checkbox:checked').length;
    $('#selectedCount').text(count + ' selected');
    $('#batchCount').text('(' + count + ' selected)');
}

// FILTER FUNCTIONS
function applyFilters() {
    const params = new URLSearchParams(window.location.search);
    
    let period = $('#periodFilter').val();
    if (period && period.includes('_') && !period.startsWith('custom_')) {
        params.set('period', 'custom');
        const dates = period.split('_');
        params.set('date_from', dates[0]);
        params.set('date_to', dates[1]);
    } else {
        params.set('period', period);
    }
    
    params.set('status', $('#statusFilter').val());
    params.set('department', $('#deptFilter').val());
    
    const queryString = params.toString();
    const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.pushState({}, '', newUrl);
    
    loadDisbursements();
    loadStats();
}

function applyCustomDate() {
    const from = $('#dateFromFilter').val();
    const to = $('#dateToFilter').val();
    
    if (!from || !to) {
        Swal.fire('Warning', 'Please select both from and to dates', 'warning');
        return;
    }
    
    const customOption = `${from}_${to}`;
    const optionText = `${new Date(from).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${new Date(to).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} (Custom)`;
    
    if ($('#periodFilter option[value="' + customOption + '"]').length === 0) {
        $('#periodFilter').append(`<option value="${customOption}">${optionText}</option>`);
    }
    
    $('#periodFilter').val(customOption);
    $('#customDateRange').hide();
    applyFilters();
}

function clearFilters() {
    $('#periodFilter').val('current');
    $('#statusFilter').val('all');
    $('#deptFilter').val('all');
    $('#searchFilter').val('');
    $('#customDateRange').hide();
    
    window.history.pushState({}, '', window.location.pathname);
    applyFilters();
}

// REFRESH DATA
function refreshData() {
    loadDisbursements();
    loadStats();
}

// ===== CSV GENERATOR FUNCTION - WORKING SOLUTION =====
function generateBankCSV() {
    if (selectedPayments.length === 0) {
        Swal.fire('No Selection', 'Please select payments to process.', 'info');
        return;
    }
    
    // Check if selected payments are processable (queued or failed)
    const selectedData = disbursementData.filter(d => selectedPayments.includes(d.id));
    const invalidStatuses = selectedData.filter(d => d.status !== 'queued' && d.status !== 'failed');
    
    if (invalidStatuses.length > 0) {
        Swal.fire('Invalid Selection', 
            'Only payments with "Queued" or "Failed" status can be processed.', 
            'warning');
        return;
    }
    
    const totalAmount = selectedData.reduce((sum, d) => sum + d.net_pay, 0);
    
    Swal.fire({
        title: 'Generate Bank CSV',
        html: `
            <div class="text-start">
                <p><strong>Selected:</strong> ${selectedPayments.length} employees</p>
                <p><strong>Total Amount:</strong> ₱${formatNumber(totalAmount)}</p>
                <p class="text-info"><i class="bi bi-info-circle"></i> 
                   This will generate a CSV file you can upload to your bank.
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Generate CSV',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            generateCSVFile();
        }
    });
}

/**
 * Generate and download CSV file
 */
function generateCSVFile() {
    Swal.fire({
        title: 'Generating CSV',
        html: '<div class="spinner-border text-primary"></div><p class="mt-2">Please wait...</p>',
        allowOutsideClick: false,
        showConfirmButton: false
    });
    
    fetch('api/salary_disbursement.php?action=generate_bank_csv', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ payroll_ids: selectedPayments })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Download CSV file
            const blob = new Blob([atob(data.csv_content)], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            Swal.fire({
                title: 'Success!',
                html: `
                    <p>CSV file generated successfully!</p>
                    <p><strong>Reference:</strong> ${data.reference_no}</p>
                    <p><strong>Records:</strong> ${data.record_count}</p>
                    <p><strong>Total:</strong> ₱${formatNumber(data.total_amount)}</p>
                    <p class="text-muted mt-2">You can now upload this file to your bank.</p>
                `,
                icon: 'success'
            }).then(() => {
                refreshData();
            });
        } else {
            throw new Error(data.error || 'Failed to generate CSV');
        }
    })
    .catch(error => {
        Swal.fire('Error', 'Failed to generate CSV: ' + error.message, 'error');
    });
}

// ===== LEGACY FUNCTIONS (KEPT FOR UI COMPATIBILITY) =====
function processSelectedPayments() {
    generateBankCSV(); // Redirect to CSV generator
}

function showBulkDisbursementModal() {
    generateBankCSV(); // Redirect to CSV generator
}

function generateBankFileForSelected() {
    generateBankCSV(); // Redirect to CSV generator
}

/**
 * Mark selected payments as completed (after bank upload)
 */
function markAsCompleted() {
    if (selectedPayments.length === 0) {
        Swal.fire('No Selection', 'Please select payments to mark as completed.', 'info');
        return;
    }
    
    Swal.fire({
        title: 'Mark as Completed',
        text: `Mark ${selectedPayments.length} payment(s) as completed?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, mark completed',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Call API to update status to Released
            fetch('api/salary_disbursement.php?action=mark_completed', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ payroll_ids: selectedPayments })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', `${data.updated_count} payments marked as completed.`, 'success')
                        .then(() => refreshData());
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Failed to update: ' + error.message, 'error');
            });
        }
    });
}

function viewPaymentDetails(payrollId) {
    const payment = disbursementData.find(d => d.id === payrollId);
    if (!payment) {
        Swal.fire('Error', 'Payment not found', 'error');
        return;
    }
    
    let detailsHtml = `
        <div class="text-start" style="max-height: 500px; overflow-y: auto;">
            <h6 class="border-bottom pb-2">Employee Information</h6>
            <p><strong>Name:</strong> ${escapeHtml(payment.employee_name)}</p>
            <p><strong>Employee No:</strong> ${escapeHtml(payment.employee_no)}</p>
            <p><strong>Department:</strong> ${escapeHtml(payment.department)}</p>
            <p><strong>Position:</strong> ${escapeHtml(payment.position)}</p>
            
            <h6 class="border-bottom pb-2 mt-3">Bank Information</h6>
            <p><strong>Bank:</strong> ${escapeHtml(payment.bank_name)}</p>
            <p><strong>Account Holder:</strong> ${escapeHtml(payment.bank_account_holder)}</p>
            <p><strong>Account Number:</strong> ${escapeHtml(payment.bank_account_number)}</p>
            
            <h6 class="border-bottom pb-2 mt-3">Salary Details</h6>
            <p><strong>Basic Salary:</strong> ₱${formatNumber(payment.basic_salary)}</p>
            <p><strong>Allowances:</strong> ₱${formatNumber(payment.allowances)}</p>
            <p><strong>Deductions:</strong> ₱${formatNumber(payment.total_deductions)}</p>
            <p><strong>Net Pay:</strong> ₱${formatNumber(payment.net_pay)}</p>
            
            <h6 class="border-bottom pb-2 mt-3">Payment Status</h6>
            <p><strong>Status:</strong> 
                <span class="badge ${getStatusClass(payment.status)}">
                    ${capitalizeFirst(payment.status)}
                </span>
            </p>
    `;
    
    if (payment.payment_reference) {
        detailsHtml += `<p><strong>Reference:</strong> ${payment.payment_reference}</p>`;
    }
    
    if (payment.paid_date) {
        detailsHtml += `<p><strong>Paid Date:</strong> ${formatDate(payment.paid_date)}</p>`;
    }
    
    detailsHtml += `
        <div class="mt-3">
            <button class="btn btn-primary" onclick="refreshData()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </div>`;
    
    Swal.fire({
        title: 'Payment Details',
        html: detailsHtml,
        icon: 'info',
        width: '500px',
        showConfirmButton: false,
        showCloseButton: true
    });
}

function viewPayslip(code) {
    window.open(`payslip_view.php?code=${code}`, '_blank');
}

function printPayslip() {
    window.print();
}

function toggleColumns() {
    $('#columnVisibilityModal').modal('show');
}

function exportReport() {
    const period = $('#periodFilter').val() || 'current';
    window.location.href = `api/salary_disbursement.php?action=export_report&period=${period}`;
}

function downloadSummary() {
    const csv = 'Period,Selected,Amount\n' + 
                $('#periodFilter').val() + ',' + 
                selectedPayments.length + ',' + 
                $('#batchTotal').text();
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `summary_${new Date().getTime()}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

// UTILITY FUNCTIONS
function formatNumber(num) {
    if (num === null || num === undefined || isNaN(parseFloat(num))) {
        return '0.00';
    }
    return parseFloat(num).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return 'N/A';
        return date.toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    } catch (e) {
        return 'N/A';
    }
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// ===== STATUS FUNCTIONS =====
function getStatusClass(status) {
    const classes = {
        'queued': 'badge-queued bg-secondary',
        'pending': 'badge-pending bg-warning text-dark',
        'processing': 'badge-processing bg-info text-dark',
        'completed': 'badge-completed bg-success',
        'failed': 'badge-failed bg-danger',
        'draft': 'badge-draft bg-light text-dark'
    };
    return classes[status] || 'bg-secondary text-white';
}

function getStatusIcon(status) {
    const icons = {
        'queued': 'bi-hourglass-split',
        'pending': 'bi-clock',
        'processing': 'bi-arrow-repeat',
        'completed': 'bi-check-circle',
        'failed': 'bi-x-circle',
        'draft': 'bi-file-earmark'
    };
    return icons[status] || 'bi-question-circle';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Handle browser back/forward
window.onpopstate = function() {
    loadDisbursements();
    loadStats();
};

// Column visibility
$(document).on('change', '[data-column]', function() {
    const colIndex = $(this).data('column');
    const isChecked = $(this).is(':checked');
    $('#disbursementTable').find('tr').each(function() {
        $(this).find('th, td').eq(colIndex).toggle(isChecked);
    });
});

// Initialize tooltips
$(document).ready(function() {
    $('[data-bs-toggle="tooltip"]').tooltip();
});

// ===== DEBUG FUNCTION =====
function debugCheckboxes() {
    console.log('=== CHECKBOX DEBUG ===');
    console.log('Total disbursementData:', disbursementData.length);
    
    disbursementData.forEach(p => {
        const checkbox = $(`.row-checkbox[data-id="${p.id}"]`);
        const isDisabled = checkbox.prop('disabled');
        console.log(`ID: ${p.id}, Status: "${p.status}", Checkbox Disabled: ${isDisabled}`);
    });
    
    const selectable = disbursementData.filter(d => 
        d.status === 'queued' || d.status === 'failed'
    );
    console.log('Selectable records (queued/failed):', selectable.length);
}