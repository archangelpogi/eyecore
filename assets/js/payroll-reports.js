let currentReportId = 0;
let currentPage = 1;
let pageSize = 10;
let totalReports = 0;
let reportsData = [];
let reportDistributionChart = null;
let payrollTrendChart = null;
let departmentChart = null;
let currentFilter = {
    type: 'all',
    search: '',
    period: '',
    limit: 10,
    offset: 0
};

// ===================================================================
// DOCUMENT READY - INITIALIZATION
// ===================================================================
$(document).ready(function() {
    // Initialize date pickers
    flatpickr(".flatpickr-input", {
        dateFormat: "Y-m-d"
    });
    
    // Load initial data
    loadStats();
    loadReports();
    loadChartData();
    loadPendingApprovals();
    
    // Checkbox handlers
    $('#sendEmail').change(function() {
        $('#emailField').toggle(this.checked);
    });
    
    $('#saveTemplate').change(function() {
        $('#templateNameField').toggle(this.checked);
    });
});

// ===================================================================
// LOADING OVERLAY
// ===================================================================
function showLoading(message = 'Loading...') {
    $('#loadingMessage').text(message);
    $('#loadingOverlay').fadeIn(200);
}

function hideLoading() {
    $('#loadingOverlay').fadeOut(200);
}

// ===================================================================
// API CALLS - REPORTS
// ===================================================================
function loadReports() {
    showLoading('Loading reports...');
    
    let params = {
        action: 'get_reports',
        limit: pageSize,
        offset: (currentPage - 1) * pageSize,
        type: currentFilter.type,
        search: currentFilter.search,
        period: currentFilter.period
    };
    
    $.ajax({
        url: '/eyecoreph/api/payroll-reports.php',
        type: 'GET',
        data: params,
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (response.success) {
                reportsData = response.data;
                totalReports = response.total;
                renderReportsTable(response.data);
                updatePagination();
            } else {
                Swal.fire('Error', response.error || 'Failed to load reports', 'error');
            }
        },
        error: function(xhr) {
            hideLoading();
            Swal.fire('Error', 'Server error: ' + xhr.status, 'error');
        }
    });
}

function renderReportsTable(reports) {
    let tbody = $('#reportsTableBody');
    
    if (!reports || reports.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="8" class="text-center py-5">
                    <i class="bi bi-file-earmark-x fs-1 text-muted"></i>
                    <p class="text-muted mt-2 mb-0">No reports found</p>
                    <button class="btn btn-sm btn-primary mt-3" onclick="openGenerateReportModal()">
                        <i class="bi bi-plus-circle"></i> Generate Report
                    </button>
                </td>
            </tr>
        `);
        
        $('#reportStartCount').text('0');
        $('#reportEndCount').text('0');
        $('#reportTotalCount').text('0');
        return;
    }
    
    let html = '';
    
    reports.forEach(function(report) {
        // Determine badge color based on type
        let typeBadge = '';
        switch(report.report_type) {
            case 'Monthly Payroll': typeBadge = 'bg-primary'; break;
            case 'Tax Report': typeBadge = 'bg-danger'; break;
            case 'Government': typeBadge = 'bg-success'; break;
            case 'Analytics': typeBadge = 'bg-info'; break;
            case 'Bonus': typeBadge = 'bg-warning text-dark'; break;
            default: typeBadge = 'bg-secondary';
        }
        
        html += `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <i class="bi bi-file-earmark-${report.file_format === 'PDF' ? 'pdf' : 'excel'} me-2 fs-5 text-${report.file_format === 'PDF' ? 'danger' : 'success'}"></i>
                        <div>
                            <span class="fw-semibold">${report.report_name}</span>
                            <small class="d-block text-muted">Generated: ${report.generated_at_formatted}</small>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="badge ${typeBadge}">${report.report_type}</span>
                </td>
                <td>
                    <small>${report.period}</small>
                    <small class="d-block text-muted">${report.employee_count} employee(s)</small>
                </td>
                <td>
                    <span class="department-badge">${report.department}</span>
                </td>
                <td>
                    <span class="fw-bold">${report.formatted_net_pay}</span>
                    <small class="d-block text-muted">${report.formatted_gross_pay}</small>
                </td>
                <td>
                    ${report.status_badge}
                </td>
                <td>
                    <small>${report.generated_by}</small>
                    <small class="d-block text-muted">${report.generated_at_formatted}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="viewReportDetails(${report.id})" title="View Details">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-outline-success" onclick="downloadReport(${report.id})" title="Download">
                            <i class="bi bi-download"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="shareReportModal(${report.id})" title="Share">
                            <i class="bi bi-share"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="deleteReport(${report.id}, '${report.report_name}')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
    
    // Update pagination info
    let start = ((currentPage - 1) * pageSize) + 1;
    let end = Math.min(currentPage * pageSize, totalReports);
    
    $('#reportStartCount').text(start);
    $('#reportEndCount').text(end);
    $('#reportTotalCount').text(totalReports);
}

// ===================================================================
// STATS LOADING
// ===================================================================
function loadStats() {
    $.ajax({
        url: '/eyecoreph/api/payroll-reports.php?action=get_stats',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let stats = response.data;
                
                $('#statsReportsGenerated').text(stats.reports_generated_formatted || stats.reports_generated);
                $('#statsReportsSubtext').text(`This year (${new Date().getFullYear()})`);
                
                $('#statsTotalPayroll').text(stats.total_payroll_ytd_formatted || '₱0');
                $('#statsPayrollSubtext').text(`Year to Date (${new Date().getFullYear()})`);
                
                $('#statsAverageSalary').text(stats.average_salary_formatted || '₱0');
                $('#statsComplianceRate').text(stats.compliance_rate_formatted || '0%');
                $('#complianceProgressBar').css('width', stats.compliance_rate + '%');
                
                // Update distribution badges
                let byType = stats.reports_by_type || {};
                $('#badgeMonthly').text(byType['Monthly Payroll'] || 0);
                $('#badgeTax').text(byType['Tax Report'] || 0);
                $('#badgeGov').text(byType['Government'] || 0);
                $('#badgeAnalytics').text(byType['Analytics'] || 0);
                
                $('#distMonthly').text(byType['Monthly Payroll'] || 0);
                $('#distTax').text(byType['Tax Report'] || 0);
                $('#distGov').text(byType['Government'] || 0);
                $('#distAnalytics').text(byType['Analytics'] || 0);
                
                let totalReports = (byType['Monthly Payroll'] || 0) + 
                                 (byType['Tax Report'] || 0) + 
                                 (byType['Government'] || 0) + 
                                 (byType['Analytics'] || 0);
                $('#totalReportsBadge').text(totalReports + ' total');
            }
        }
    });
}

// ===================================================================
// CHART FUNCTIONS
// ===================================================================
function loadChartData() {
    let year = $('#trendYear').val();
    
    $.ajax({
        url: `/eyecoreph/api/payroll-reports.php?action=get_chart_data&year=${year}`,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let data = response.data;
                initPayrollTrendChart(data.months, data.monthly_totals);
                initDepartmentChart(data.departments, data.department_totals);
                initReportDistributionChart();
            }
        }
    });
}

function initPayrollTrendChart(labels, data) {
    let ctx = document.getElementById('payrollTrendChart').getContext('2d');
    
    if (payrollTrendChart) {
        payrollTrendChart.destroy();
    }
    
    payrollTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Payroll',
                data: data,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                borderWidth: 2,
                pointBackgroundColor: '#0d6efd',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '₱' + context.raw.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '₱' + (value / 1000) + 'k';
                        }
                    }
                }
            }
        }
    });
}

function initDepartmentChart(labels, data) {
    let ctx = document.getElementById('departmentChart').getContext('2d');
    
    if (departmentChart) {
        departmentChart.destroy();
    }
    
    let backgroundColors = [
        '#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6f42c1', '#fd7e14'
    ];
    
    departmentChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: backgroundColors.slice(0, labels.length),
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ₱${value.toLocaleString()} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

function initReportDistributionChart() {
    let ctx = document.getElementById('reportDistributionChart').getContext('2d');
    
    if (reportDistributionChart) {
        reportDistributionChart.destroy();
    }
    
    let monthly = parseInt($('#badgeMonthly').text()) || 12;
    let tax = parseInt($('#badgeTax').text()) || 4;
    let gov = parseInt($('#badgeGov').text()) || 8;
    let analytics = parseInt($('#badgeAnalytics').text()) || 6;
    
    reportDistributionChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Monthly Payroll', 'Tax Reports', 'Government', 'Analytics'],
            datasets: [{
                data: [monthly, tax, gov, analytics],
                backgroundColor: ['#0d6efd', '#dc3545', '#198754', '#0dcaf0'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            cutout: '70%'
        }
    });
}

// ===================================================================
// PENDING APPROVALS
// ===================================================================
function loadPendingApprovals() {
    $.ajax({
        url: '/eyecoreph/api/payroll-reports.php?action=get_reports&type=Generated&limit=3',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data.length > 0) {
                let pending = response.data;
                $('#pendingApprovalsBadge').text(pending.length);
                
                let html = '';
                pending.forEach(function(report) {
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div>
                                <small class="fw-semibold d-block">${report.employee_name}</small>
                                <small class="text-muted">${report.period}</small>
                            </div>
                            <div>
                                <span class="fw-bold small">${report.formatted_net_pay}</span>
                                <button class="btn btn-sm btn-link p-0 ms-2" onclick="viewReportDetails(${report.id})">
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                
                $('#pendingApprovalsList').html(html);
            } else {
                $('#pendingApprovalsBadge').text('0');
            }
        }
    });
}

// ===================================================================
// FILTER FUNCTIONS
// ===================================================================
function filterByType(type) {
    currentFilter.type = type;
    currentPage = 1;
    $('#reportTypeFilter').val(type);
    loadReports();
}

function filterReports() {
    currentFilter.type = $('#reportTypeFilter').val();
    currentPage = 1;
    loadReports();
}

function searchReports() {
    currentFilter.search = $('#reportSearch').val();
    currentPage = 1;
    loadReports();
}

function applyDateFilter() {
    let start = $('#filterPeriodStart').val();
    let end = $('#filterPeriodEnd').val();
    
    if (start && end) {
        // Use start month as period filter
        currentFilter.period = start.substring(0, 7);
        currentPage = 1;
        loadReports();
    }
}

function clearDateFilter() {
    $('#filterPeriodStart').val('');
    $('#filterPeriodEnd').val('');
    currentFilter.period = '';
    currentPage = 1;
    loadReports();
}

// ===================================================================
// PAGINATION
// ===================================================================
function updatePagination() {
    let totalPages = Math.ceil(totalReports / pageSize);
    let pagination = $('#pagination');
    let html = '';
    
    // Previous button
    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage - 1})">Previous</a>
             </li>`;
    
    // Page numbers
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if (startPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(1)">1</a></li>`;
        if (startPage > 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                 </li>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${totalPages})">${totalPages}</a></li>`;
    }
    
    // Next button
    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="changePage(${currentPage + 1})">Next</a>
             </li>`;
    
    pagination.html(html);
}

function changePage(page) {
    if (page < 1) return;
    currentPage = page;
    loadReports();
}

// ===================================================================
// REPORT DETAILS
// ===================================================================
function viewReportDetails(id) {
    currentReportId = id;
    
    $('#reportDetailsLoading').show();
    $('#reportDetailsContent').hide();
    $('#reportDetailsError').hide();
    $('#reportDetailsModal').modal('show');
    
    $.ajax({
        url: `/eyecoreph/api/payroll-reports.php?action=get_report_details&id=${id}`,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            $('#reportDetailsLoading').hide();
            
            if (response.success) {
                let r = response.data;
                
                // Basic info
                $('#detailEmployeeName').text(r.employee_name);
                $('#detailEmployeeNo').text(r.employee_no);
                $('#detailDepartment').text(r.department);
                $('#detailPeriod').text(r.period_formatted);
                $('#detailNetPay').text('₱' + r.net_pay.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('#detailGrossPay').text('₱' + r.gross_pay.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                
                // Status badge
                let statusClass = '';
                switch(r.status) {
                    case 'Draft': statusClass = 'bg-secondary'; break;
                    case 'Generated': statusClass = 'bg-primary'; break;
                    case 'Approved': statusClass = 'bg-success'; break;
                    case 'Ready': statusClass = 'bg-warning text-dark'; break;
                    case 'Released': statusClass = 'bg-info'; break;
                    case 'Cancelled': statusClass = 'bg-danger'; break;
                    default: statusClass = 'bg-secondary';
                }
                $('#detailStatusBadge').text(r.status).attr('class', 'badge ' + statusClass);
                
                // Earnings
                $('#earningsBasic').text('₱' + (r.basic_salary || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsOvertime').text('₱' + (r.overtime || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsHoliday').text('₱' + (r.holiday_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsNightDiff').text('₱' + (r.night_differential || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsAllowances').text('₱' + (r.allowances || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsBonuses').text('₱' + (r.bonuses || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earnings13th').text('₱' + (r.thirteenth_month || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#earningsTotal').text('₱' + (r.gross_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                
                // Deductions
                $('#deductionsSSS').text('₱' + (r.sss_contribution || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsPhilhealth').text('₱' + (r.philhealth_contribution || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsPagibig').text('₱' + (r.pagibig_contribution || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsTax').text('₱' + (r.withholding_tax || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsAbsences').text('₱' + (r.absences || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsTardiness').text('₱' + (r.tardiness || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsLWOP').text('₱' + (r.leave_without_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsOther').text('₱' + (r.other_deductions || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                $('#deductionsTotal').text('₱' + (r.total_deductions || 0).toLocaleString(undefined, {minimumFractionDigits: 2}));
                
                // Bank Details
                $('#bankPaymentMethod').text(r.payment_method);
                $('#bankPaymentStatus').text(r.status).attr('class', 'badge ' + statusClass);
                $('#bankBankName').text(r.bank_name);
                $('#bankAccountHolder').text(r.bank_account_holder);
                $('#bankAccountNumber').text(r.bank_account_number);
                
                // Timeline
                $('#timelineGeneratedDate').text(r.generated_at ? new Date(r.generated_at).toLocaleString() : 'Not available');
                $('#timelineGeneratedBy').text('By: ' + (r.generated_by || 'System'));
                
                if (r.approved_at) {
                    $('#timelineApprovedDate').text(new Date(r.approved_at).toLocaleString());
                    $('#timelineApprovedBy').text('By: ' + (r.approved_by || 'Finance'));
                    $('#timelineApproved').show();
                } else {
                    $('#timelineApproved').hide();
                }
                
                if (r.released_at) {
                    $('#timelineReleasedDate').text(new Date(r.released_at).toLocaleString());
                    $('#timelineReleasedBy').text('By: ' + (r.released_by || 'Finance'));
                    $('#timelineReleased').show();
                } else {
                    $('#timelineReleased').hide();
                }
                
                $('#reportDetailsContent').show();
            } else {
                $('#reportDetailsError').show();
                $('#reportErrorMessage').text(response.error || 'Failed to load report details');
            }
        },
        error: function() {
            $('#reportDetailsLoading').hide();
            $('#reportDetailsError').show();
            $('#reportErrorMessage').text('Server error. Please try again.');
        }
    });
}

// ===================================================================
// GENERATE REPORT
// ===================================================================
function openGenerateReportModal() {
    // Reset form
    $('#generateReportForm')[0].reset();
    $('#emailField').hide();
    $('#templateNameField').hide();
    $('#periodStart').val(getFirstDayOfMonth());
    $('#periodEnd').val(getLastDayOfMonth());
    $('#generateReportModal').modal('show');
}

function getFirstDayOfMonth() {
    let date = new Date();
    let year = date.getFullYear();
    let month = (date.getMonth() + 1).toString().padStart(2, '0');
    return `${year}-${month}-01`;
}

function getLastDayOfMonth() {
    let date = new Date();
    let year = date.getFullYear();
    let month = date.getMonth() + 1;
    let lastDay = new Date(year, month, 0).getDate();
    month = month.toString().padStart(2, '0');
    return `${year}-${month}-${lastDay}`;
}

function submitGenerateReport() {
    // Validate form
    if (!$('#reportType').val()) {
        Swal.fire('Validation Error', 'Please select report type', 'warning');
        return;
    }
    
    if (!$('#periodStart').val() || !$('#periodEnd').val()) {
        Swal.fire('Validation Error', 'Please select period dates', 'warning');
        return;
    }
    
    let formData = new FormData();
    formData.append('action', 'generate_report');
    formData.append('report_type', $('#reportType').val());
    formData.append('period_start', $('#periodStart').val());
    formData.append('period_end', $('#periodEnd').val());
    formData.append('department', $('#department').val());
    formData.append('file_format', $('#fileFormat').val());
    formData.append('include_charts', $('#includeCharts').is(':checked') ? 1 : 0);
    
    showLoading('Generating report...');
    
    $.ajax({
        url: '/eyecoreph/api/payroll-reports.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            hideLoading();
            $('#generateReportModal').modal('hide');
            
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Report Generated!',
                    text: response.message || 'Report has been generated successfully',
                    showConfirmButton: true,
                    timer: 3000
                });
                
                // Reload reports
                loadReports();
                loadStats();
            } else {
                Swal.fire('Error', response.error || 'Failed to generate report', 'error');
            }
        },
        error: function(xhr) {
            hideLoading();
            Swal.fire('Error', 'Server error: ' + xhr.status, 'error');
        }
    });
}

// ===================================================================
// QUICK GENERATE
// ===================================================================
function quickGenerate(type) {
    let reportName = '';
    let periodStart = getFirstDayOfMonth();
    let periodEnd = getLastDayOfMonth();
    
    switch(type) {
        case 'monthly':
            reportName = 'Monthly Payroll';
            break;
        case 'tax':
            reportName = 'Tax Report';
            break;
        case 'sss':
        case 'philhealth':
        case 'pagibig':
            reportName = 'Government';
            break;
        case 'bonus':
            reportName = 'Bonus';
            break;
        default:
            reportName = 'Monthly Payroll';
    }
    
    Swal.fire({
        title: 'Generate Report',
        text: `Generate ${reportName} for ${periodStart} to ${periodEnd}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Generate',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            let formData = new FormData();
            formData.append('action', 'generate_report');
            formData.append('report_type', reportName);
            formData.append('period_start', periodStart);
            formData.append('period_end', periodEnd);
            formData.append('department', 'all');
            formData.append('file_format', 'PDF');
            
            showLoading('Generating report...');
            
            $.ajax({
                url: '/eyecoreph/api/payroll-reports.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        Swal.fire('Success', 'Report generated successfully', 'success');
                        loadReports();
                        loadStats();
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    Swal.fire('Error', 'Server error', 'error');
                }
            });
        }
    });
}

// ===================================================================
// DOWNLOAD REPORT
// ===================================================================
function downloadReport(id, format = 'PDF') {
    Swal.fire({
        title: 'Downloading...',
        html: 'Preparing your report for download',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            
            $.ajax({
                url: `/eyecoreph/api/payroll-reports.php?action=download_report&id=${id}&format=${format}`,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Download Started!',
                            text: `File: ${response.data.file_name} (${response.data.file_size})`,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Failed to download report', 'error');
                }
            });
        }
    });
}

// ===================================================================
// SHARE REPORT
// ===================================================================
function shareReportModal(id) {
    currentReportId = id;
    $('#shareReportId').val(id);
    $('#shareEmail').val('');
    $('#shareMessage').val('');
    $('#sendCopyToMe').prop('checked', true);
    $('#shareReportModal').modal('show');
}

function submitShareReport() {
    let email = $('#shareEmail').val();
    if (!email) {
        Swal.fire('Validation Error', 'Please enter recipient email', 'warning');
        return;
    }
    
    let formData = new FormData();
    formData.append('action', 'share_report');
    formData.append('id', currentReportId);
    formData.append('email', email);
    formData.append('message', $('#shareMessage').val());
    
    Swal.fire({
        title: 'Sharing...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            
            $.ajax({
                url: '/eyecoreph/api/payroll-reports.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    $('#shareReportModal').modal('hide');
                    
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success');
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Failed to share report', 'error');
                }
            });
        }
    });
}

// ===================================================================
// DELETE REPORT
// ===================================================================
function deleteReport(id, name) {
    currentReportId = id;
    $('#deleteReportName').text(name);
    $('#deleteConfirmModal').modal('show');
}

function confirmDeleteReport() {
    $('#deleteConfirmModal').modal('hide');
    
    let formData = new FormData();
    formData.append('action', 'delete_report');
    formData.append('id', currentReportId);
    
    Swal.fire({
        title: 'Deleting...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            
            $.ajax({
                url: '/eyecoreph/api/payroll-reports.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire('Deleted!', response.message, 'success');
                        loadReports();
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Failed to delete report', 'error');
                }
            });
        }
    });
}

// ===================================================================
// PRINT REPORT
// ===================================================================
function printReport(id) {
    Swal.fire({
        title: 'Print Report',
        text: 'Preparing report for printing...',
        icon: 'info',
        timer: 1500,
        showConfirmButton: false
    });
    
    // In real implementation, open print-friendly version
    setTimeout(() => {
        Swal.fire('Print Dialog', 'Use your browser\'s print function', 'info');
    }, 1500);
}

// ===================================================================
// EXPORT ALL
// ===================================================================
function exportAllReports() {
    $('#exportAllModal').modal('show');
}

function confirmExportAll() {
    $('#exportAllModal').modal('hide');
    
    let period = $('#exportPeriod').val();
    let format = $('#exportFormat').val();
    
    Swal.fire({
        title: 'Exporting Reports',
        html: 'Preparing your ZIP file...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            
            $.ajax({
                url: `/eyecoreph/api/payroll-reports.php?action=export_all&period=${period}&format=${format}`,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Export Complete!',
                            html: `
                                <p>${response.data.total_reports} reports exported</p>
                                <small class="text-muted">File: ${response.data.file_name}</small>
                            `
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Failed to export reports', 'error');
                }
            });
        }
    });
}

// ===================================================================
// UTILITY FUNCTIONS
// ===================================================================
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatCurrency(num) {
    return '₱' + formatNumber(num.toFixed(2));
}

// Refresh data every 60 seconds
setInterval(function() {
    loadStats();
    loadPendingApprovals();
}, 60000);