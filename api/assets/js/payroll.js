// ================================================================
// PAYROLL.JS — UPDATED with RBACHelper
// Status flow: Draft → For Approval → Approved → Released
//              Any step → Rejected / Cancelled
// ================================================================

// ── Permission state ─────────────────────────────────────────────────
let currentUserRole = null;
let userPermissions = {
    view: false, create: false, edit: false, delete: false,
    approve: false, reject: false, release: false, cancel: false, export: false
};
let hasHR = false;
let isOwner = false;
let currentUserId = null;

// ── Data state ───────────────────────────────────────────────────────
let payrollData = [];
let employees   = [];
let currentPayrollId = null;
let currentPayrollStatus = null;
let currentPayrollApprovals = [];

// ── Permission helpers ───────────────────────────────────────────────
const canView    = () => userPermissions.view;
const canCreate  = () => userPermissions.create;
const canEdit    = () => userPermissions.edit;
const canDelete  = () => userPermissions.delete;
const canApprove = () => userPermissions.approve;
const canReject  = () => userPermissions.reject;
const canRelease = () => userPermissions.release;
const canCancel  = () => userPermissions.cancel;
const canExport  = () => userPermissions.export;

// ✅ UPDATED: Load permissions using RBACHelper
async function loadPermissions() {
    try {
        const res  = await fetch('api/payroll.php?get_permissions=true');
        const data = await res.json();
        if (data.success) {
            currentUserRole  = data.data.role;
            userPermissions  = data.data.permissions;
            hasHR            = data.data.hasHR;
            isOwner          = data.data.isOwner;
            currentUserId    = data.data.user_id;
            
            console.log('✅ Payroll Permissions loaded:', userPermissions);
            console.log('  - view:', userPermissions.view);
            console.log('  - create:', userPermissions.create);
            console.log('  - edit:', userPermissions.edit);
            console.log('  - approve:', userPermissions.approve);
            console.log('  - reject:', userPermissions.reject);
            console.log('  - release:', userPermissions.release);
            console.log('  - cancel:', userPermissions.cancel);
            console.log('  - export:', userPermissions.export);
            
            applyPermissionBasedUI();
        } else {
            console.error('Failed to load permissions:', data.error);
        }
    } catch (e) { 
        console.error('loadPermissions error:', e); 
    }
}

function applyPermissionBasedUI() {
    // Hide/Show Generate button
    const genBtn = document.querySelector('button[onclick="openGeneratePayrollModal()"]');
    if (genBtn) {
        genBtn.style.display = canCreate() ? 'inline-flex' : 'none';
    }
    
    // Hide/Show Export button (if exists)
    const exportBtn = document.querySelector('button[onclick="exportPayroll()"]');
    if (exportBtn) {
        exportBtn.style.display = canExport() ? 'inline-flex' : 'none';
    }
    
    // If no view permission, show access denied
    if (!canView()) {
        const container = document.querySelector('.container-fluid');
        if (container) {
            container.innerHTML = `
                <div class="container-fluid p-5 text-center">
                    <div class="alert alert-danger">
                        <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                        <h3>Access Denied</h3>
                        <p>You don't have permission to view payroll records.</p>
                    </div>
                </div>`;
        }
        return;
    }
    
    // Add oversight indicator for ClinicAdmin with HR
    if (currentUserRole === 'ClinicAdmin' && hasHR && !canCreate()) {
        addOversightIndicator();
    }
}

function addOversightIndicator() {
    if (document.getElementById('oversightIndicator')) return;
    const header = document.querySelector('.d-flex.justify-content-between');
    if (!header) return;
    const el = document.createElement('div');
    el.id        = 'oversightIndicator';
    el.className = 'alert alert-info mb-3 py-2 px-3';
    el.innerHTML = `<i class="bi bi-eye me-2"></i>
        <strong>Oversight Mode</strong> — HR manages payroll creation. You can view and approve.`;
    header.parentNode.insertBefore(el, header.nextSibling);
}

// ── Init ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await loadPermissions();
    
    // Only load data if user has view permission
    if (canView()) {
        loadEmployees();
        loadPayrolls();
        loadSummary();
    }

    document.getElementById('generatePayrollForm')?.addEventListener('submit', generateBulkPayroll);
    document.getElementById('editPayrollForm')?.addEventListener('submit', savePayrollChanges);

    document.getElementById('edit_adjustment_amount')?.addEventListener('input', function () {
        const d = document.getElementById('edit_adjustment_reason_div');
        if (d) d.style.display = this.value && parseFloat(this.value) > 0 ? 'block' : 'none';
    });

    const now = new Date();
    const currentMonthEl = document.getElementById('currentMonth');
    if (currentMonthEl) {
        currentMonthEl.textContent = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }

    if (typeof flatpickr !== 'undefined') {
        flatpickr('#payroll_period', { dateFormat: 'Y-m' });
        flatpickr('#filterPeriod',   { dateFormat: 'Y-m' });
    }

    setupCutoffListener();
    document.getElementById('payroll_period')?.addEventListener('change', updateDateRange);
});

// ── Load employees ───────────────────────────────────────────────────
function loadEmployees() {
    if (!canView()) return;
    fetch('api/payroll.php?active_only=true')
        .then(r => r.json())
        .then(data => {
            employees = data;
            const sel = document.getElementById('filterEmployee');
            if (!sel) return;
            sel.innerHTML = '<option value="">All Employees</option>';
            data.forEach(e => {
                sel.innerHTML += `<option value="${e.id}">${e.employee_no} — ${e.first_name} ${e.last_name}</option>`;
            });
        })
        .catch(e => console.error('loadEmployees:', e));
}

// ── Load payrolls ────────────────────────────────────────────────────
function loadPayrolls() {
    if (!canView()) { renderPayrollTable(); return; }

    const params = [];
    const emp    = document.getElementById('filterEmployee')?.value;
    const period = document.getElementById('filterPeriod')?.value;
    const status = document.getElementById('filterStatus')?.value;
    if (emp)    params.push(`employee_id=${emp}`);
    if (period) params.push(`payroll_period=${period}`);
    if (status) params.push(`status=${encodeURIComponent(status)}`);

    const url = 'api/payroll.php' + (params.length ? '?' + params.join('&') : '');

    fetch(url)
        .then(r => r.json())
        .then(data => {
            payrollData = Array.isArray(data) ? data : [];
            renderPayrollTable();
            updateStats();
        })
        .catch(() => { payrollData = []; renderPayrollTable(); });
}

// ── Load summary ─────────────────────────────────────────────────────
function loadSummary() {
    if (!canView()) return;
    const month = new Date().toISOString().slice(0, 7);
    fetch(`api/payroll.php?summary=true&month=${month}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const s = data.data;
            
            console.log('Summary Data:', s);
            
            safeSetText('totalPayrolls',   s.total_payrolls    || 0);
            safeSetText('monthlyTotal',    formatCurrency(s.total_net_pay || 0));
            safeSetText('releasedPayrolls', s.released_payrolls || 0);
            safeSetText('pendingApprovals', s.pending_approval  || 0);
        })
        .catch(e => console.error('loadSummary:', e));
}

// ── Render table ─────────────────────────────────────────────────────
function renderPayrollTable() {
    const tbody = document.getElementById('payrollTableBody');
    if (!tbody) return;

    if (!canView()) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-muted">
            <i class="bi bi-shield-lock fs-1 d-block mb-2"></i>No permission to view payroll</td></tr>`;
        return;
    }

    if (!payrollData.length) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>No payroll records found</td></tr>`;
        return;
    }

    tbody.innerHTML = payrollData.map(p => {
        const sc    = `status-${p.status.toLowerCase().replace(/\s+/g, '_')}`;
        const apBadge = ['For Approval','Approved'].includes(p.status)
            ? `<span class="badge bg-info">${p.approval_count||0}/${p.total_approvers||0} Approved</span>` : '';

let btns = '';
if (canView()) btns += `<button class="btn btn-outline-primary btn-sm" onclick="viewPayrollDetails(${p.id})"><i class="bi bi-eye"></i></button>`;

// Edit button: LALABAS lang kung DRAFT, CANCELLED, o REJECTED
// HINDI lalabas kapag: FOR APPROVAL, READY, APPROVED, RELEASED
const editableStatuses = ['Draft', 'Cancelled', 'Rejected'];
if (canEdit() && editableStatuses.includes(p.status))
    btns += `<button class="btn btn-outline-warning btn-sm" onclick="openEditPayrollModal(${p.id})"><i class="bi bi-pencil"></i></button>`;

        return `<tr>
            <td><div class="fw-bold">${p.payroll_period}</div>
                <small class="text-muted">${formatDate(p.period_start,true)} – ${formatDate(p.period_end,true)}</small></td>
            <td><div class="fw-bold">${p.employee_name}</div><small class="text-muted">${p.employee_no}</small></td>
            <td class="fw-bold">${formatCurrency(p.basic_salary)}</td>
            <td class="fw-bold">${formatCurrency(p.gross_pay)}</td>
            <td class="text-danger">${formatCurrency(p.total_deductions)}</td>
            <td class="fw-bold text-success">${formatCurrency(p.net_pay)}</td>
            <td><span class="badge rounded-pill ${sc}">${p.status}</span></td>
            <td>${apBadge}</td>
            <td><small class="text-muted">${formatDate(p.generated_at)}</small></td>
            <td><div class="btn-group btn-group-sm">${btns}</div></td>
        </tr>`;
    }).join('');
}

function updateStats() {
    safeSetText('totalPayrolls',   payrollData.length);
    safeSetText('releasedPayrolls',payrollData.filter(p => p.status === 'Released').length);
}

function viewPayrollDetails(id) {
    if (!canView()) { 
        Swal.fire('Access Denied','No permission to view payroll details','error'); 
        return; 
    }
    currentPayrollId = id;

    fetch(`api/payroll.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { Swal.fire('Error','Failed to load payroll','error'); return; }
            const p = data.data;
            currentPayrollStatus   = p.status;
            currentPayrollApprovals = data.approvals || [];

            // Approval bar
            const bar = document.getElementById('approvalStatusBar');
            if (bar) bar.style.display = data.approvals?.length ? 'block' : 'none';
            if (data.approvals?.length) renderApprovalStatus(data.approvals);

            safeSetText('detailEmployee', `${p.employee_name} (${p.employee_no})`);
            safeSetText('detailPeriod',   `${formatDate(p.period_start,true)} to ${formatDate(p.period_end,true)}`);

            const sb = document.getElementById('detailStatus');
            if (sb) sb.innerHTML = `<span class="badge rounded-pill status-${p.status.toLowerCase().replace(/\s+/g,'_')}">${p.status}</span>`;

            safeSetText('detailNetPay', formatCurrency(p.net_pay));

            // Attendance
            if (data.attendance_records?.length) {
                let present = 0, late = 0, absent = 0;
                data.attendance_records.forEach(r => { 
                    if (r.status === 'Present') present++; 
                    else if (r.status === 'Late') late++;
                    else if (r.status === 'Absent') absent++;
                });
                safeSetText('detailPresentDays', present);
                safeSetText('detailLateDays',    late);
                safeSetText('detailAbsentDays',  absent); // ✅ ADD THIS
                renderAttendanceRecords(data.attendance_records);
                const as = document.getElementById('attendanceListSection');
                if (as) as.style.display = 'block';
            }

            // ✅ RENDER LEAVE RECORDS
            if (data.leave_records?.length) {
                renderLeaveRecords(data.leave_records);
                const ls = document.getElementById('leaveSection');
                if (ls) ls.style.display = 'block';
            } else {
                document.getElementById('leaveSection').style.display = 'none';
            }

            // Bank
            safeSetText('detailBankName',   p.bank_name           || 'Not Set');
            safeSetText('detailBankHolder', p.bank_account_holder || p.employee_name || 'Not Set');
            let acct = p.bank_account_number || 'Not Set';
            if (acct !== 'Not Set' && acct.length > 4) acct = '****' + acct.slice(-4);
            safeSetText('detailBankAccount', acct);
            const bse = document.getElementById('detailBankStatus');
            if (bse) {
                const ok = p.bank_name && p.bank_account_number && p.bank_account_holder;
                bse.textContent = ok ? 'Complete' : 'Incomplete';
                bse.className   = ok ? 'badge bg-success' : 'badge bg-warning text-dark';
            }

            // Earnings
            safeSetText('detailBasicSalary', formatCurrency(p.basic_salary));
            safeSetText('detailOvertime',    formatCurrency(p.overtime     || 0));
            safeSetText('detailHolidayPay',  formatCurrency(p.holiday_pay  || 0));
            safeSetText('detailAllowances',  formatCurrency(p.allowances   || 0));
            safeSetText('detailBonuses',     formatCurrency(p.bonuses      || 0));
            safeSetText('detailGrossPay',    formatCurrency(p.gross_pay));

            // Deductions
            safeSetText('detailTardiness',      formatCurrency(p.tardiness                 || 0));
            safeSetText('detailAbsences',        formatCurrency(p.absences                  || 0));
            safeSetText('detailSSS',             formatCurrency(p.sss_contribution          || 0));
            safeSetText('detailPhilhealth',      formatCurrency(p.philhealth_contribution   || 0));
            safeSetText('detailPagibig',         formatCurrency(p.pagibig_contribution      || 0));
            safeSetText('detailTax',             formatCurrency(p.withholding_tax           || 0));
            safeSetText('detailOtherDeductions', formatCurrency(p.other_deductions          || 0));
            safeSetText('detailTotalDeductions', formatCurrency(p.total_deductions));

            // Adjustments
            const adjSec = document.getElementById('adjustmentsSection');
            if (adjSec) {
                adjSec.style.display = data.adjustments?.length ? 'block' : 'none';
                if (data.adjustments?.length) renderAdjustments(data.adjustments);
            }

            // Overtime (with approval status)
            const otSec = document.getElementById('overtimeSection');
            if (otSec) {
                otSec.style.display = data.overtime?.length ? 'block' : 'none';
                if (data.overtime?.length) renderOvertime(data.overtime, p);
            }

            // Info
            safeSetText('detailGeneratedBy',   p.generated_by_name || 'N/A');
            safeSetText('detailGeneratedDate', formatDate(p.generated_at));
            safeSetText('detailReleasedDate',  p.released_at ? formatDate(p.released_at) : 'Not released');

            updateActionButtons(p.status, data.approvals || []);
            new bootstrap.Modal(document.getElementById('viewPayrollModal')).show();
        })
        .catch(() => Swal.fire('Error','Failed to load payroll details','error'));
}

function renderAttendanceRecords(records) {
    const tbody = document.getElementById('attendanceRecordsList');
    if (!tbody) return;
    
    tbody.innerHTML = records.map(r => {
        let sc = 'badge bg-success', st = r.status || 'Present';
        if (st === 'Late')    sc = 'badge bg-warning text-dark';
        if (st === 'Absent')  sc = 'badge bg-danger';
        if (st === 'Half-day') sc = 'badge bg-info';
        
        const ti = r.time_in  ? r.time_in.substring(0,5)  : 'N/A';
        const to = r.time_out ? r.time_out.substring(0,5) : 'N/A';
        const h  = r.total_hours ? parseFloat(r.total_hours).toFixed(2) : '0.00';
        
        // ✅ ADD APPROVAL STATUS BADGE
        let approvalBadge = '';
        switch(r.approval_status) {
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
        
        return `
            <tr>
                <td>${formatDate(r.date,true)}</td>
                <td>${ti}</td>
                <td>${to}</td>
                <td>${h}</td>
                <td><span class="${sc}">${st}</span> ${approvalBadge}</td>
            </tr>
        `;
    }).join('');
}

function renderLeaveRecords(leaveRecords) {
    const tbody = document.getElementById('leaveList');
    if (!tbody) return;
    
    tbody.innerHTML = leaveRecords.map(l => {
        // Get approval status badge
        let statusBadge = '';
        let statusClass = '';
        
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
            case 'cancelled':
                statusBadge = '<span class="badge bg-secondary"><i class="bi bi-ban"></i> Cancelled</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + (l.status || 'Unknown') + '</span>';
        }
        
        // Format leave type
        let leaveType = l.leave_type || 'Leave';
        if (leaveType.includes('_')) {
            leaveType = leaveType.replace('_', ' ').toUpperCase();
        }
        
        return `
            <tr>
                <td>${formatDate(l.start_date, true)}</td>
                <td>${formatDate(l.end_date, true)}</td>
                <td>${leaveType}</td>
                <td>${l.number_of_days || 0}</td>
                <td>${statusBadge}</td>
                <td><small>${l.reason || '-'}</small></td>
            </tr>
        `;
    }).join('');
}

function renderApprovalStatus(approvals) {
    const pc = document.getElementById('approvalProgress');
    const hc = document.getElementById('approvalHistory');
    if (!pc || !hc) return;
    pc.innerHTML = hc.innerHTML = '';

    approvals.forEach((a, i) => {
        let cls = 'rounded-circle border p-2 d-flex align-items-center justify-content-center';
        let icon = 'bi-clock', text = 'pending';
        if (a.status === 'approved') { cls += ' bg-success text-white border-success'; icon = 'bi-check-circle'; text = 'approved'; }
        else if (a.status === 'rejected') { cls += ' bg-danger text-white border-danger'; icon = 'bi-x-circle'; text = 'rejected'; }
        else { cls += ' bg-warning text-dark border-warning'; }

        pc.innerHTML += `<div class="d-flex flex-column align-items-center" style="flex:1">
            <div class="${cls}" style="width:40px;height:40px"><i class="bi ${icon}"></i></div>
            <small class="mt-1 text-center">${a.approver_name}<br><span class="text-muted">${text}</span></small>
            ${i < approvals.length-1 ? '<div class="flex-grow-1 border-top mt-3" style="width:100%"></div>' : ''}
        </div>`;

        if (a.status !== 'pending') {
            hc.innerHTML += `<div class="d-flex justify-content-between mb-1">
                <span>${a.approver_name} (${a.role}) ${a.status} this payroll</span>
                <span class="text-muted">${a.approved_at ? formatDate(a.approved_at) : ''}</span>
            </div>${a.remarks ? `<div class="text-muted small mb-2">Remarks: ${a.remarks}</div>` : ''}`;
        }
    });
}

function renderAdjustments(adjustments) {
    const tbody = document.getElementById('adjustmentsList');
    if (!tbody) return;
    tbody.innerHTML = adjustments.map(a => {
        const tc = a.adjustment_type === 'addition' ? 'text-success' : 'text-danger';
        const sg = a.adjustment_type === 'addition' ? '+' : '-';
        return `<tr><td><span class="badge ${tc}">${a.adjustment_type}</span></td>
                <td>${a.item_name||'—'}</td>
                <td class="${tc}">${sg}${formatCurrency(a.amount)}</td>
                <td>${a.reason||'—'}</td></tr>`;
    }).join('');
}

function renderOvertime(overtimeRecords, payroll) {
    const tbody = document.getElementById('overtimeList');
    if (!tbody) return;

    const basicSalary    = parseFloat(payroll?.basic_salary || 0);
    const salaryFreq     = payroll?.salary_frequency || 'monthly';
    let   monthlyRate    = basicSalary;
    if (salaryFreq === '15days')   monthlyRate = basicSalary * 2;
    if (salaryFreq === '30days')   monthlyRate = basicSalary;
    if (salaryFreq === 'weekly')   monthlyRate = basicSalary * 4.33;
    if (salaryFreq === 'daily')    monthlyRate = basicSalary * 22;
    const hourlyRate = monthlyRate > 0 ? monthlyRate / 22 / 8 : 0;

    tbody.innerHTML = overtimeRecords.map(ot => {
        const multiplier = ot.overtime_type === 'holiday' ? 2.0
                         : ot.overtime_type === 'restday' ? 1.3 : 1.25;
        const amount = parseFloat(ot.total_hours || 0) * hourlyRate * multiplier;
        
        // ✅ ADD APPROVAL STATUS BADGE
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
            case 'cancelled':
                statusBadge = '<span class="badge bg-secondary"><i class="bi bi-ban"></i> Cancelled</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + (ot.status || 'Unknown') + '</span>';
        }
        
        let typeDisplay = ot.overtime_type ? ot.overtime_type.replace('_', ' ') : '';
        typeDisplay = typeDisplay.charAt(0).toUpperCase() + typeDisplay.slice(1);
        
        return `
            <tr>
                <td>${formatDate(ot.overtime_date, true)}</td>
                <td>${ot.total_hours} hrs</td>
                <td><span class="badge bg-info">${typeDisplay}</span></td>
                <td class="fw-bold">${formatCurrency(amount)}</td>
                <td>${statusBadge}</td>
            </tr>
        `;
    }).join('');
}

// ── Action buttons (updated with permission checks) ───────────────────
function updateActionButtons(status, approvals) {
    ['submitBtn','editBtn','approveBtn','rejectBtn','releaseBtn','cancelBtn']
        .forEach(id => setElementDisplay(id, 'none'));

switch (status) {
    case 'Draft':
        if (canEdit())   { setElementDisplay('submitBtn','inline-block'); setElementDisplay('editBtn','inline-block'); }
        if (canCancel()) setElementDisplay('cancelBtn','inline-block');
        break;

    case 'For Approval':
        if (canApprove() || canReject()) {
            const mine = approvals.find(a => a.approver_id == currentUserId && a.status === 'pending');
            if (mine) {
                if (canApprove()) setElementDisplay('approveBtn','inline-block');
                if (canReject())  setElementDisplay('rejectBtn', 'inline-block');
            }
        }
        // TANGGALIN ANG EDIT BUTTON SA FOR APPROVAL
        // if (canEdit()) setElementDisplay('editBtn','inline-block');
        break;

    case 'Approved':
        if (canRelease()) setElementDisplay('releaseBtn','inline-block');
        // TANGGALIN ANG EDIT BUTTON SA APPROVED
        // if (canEdit())    setElementDisplay('editBtn',   'inline-block');
        break;

    case 'Rejected':
        if (canEdit()) { setElementDisplay('editBtn','inline-block'); setElementDisplay('submitBtn','inline-block'); }
        break;
        
    case 'Cancelled':
        if (canEdit()) setElementDisplay('editBtn','inline-block');
        break;
        
    case 'Released':
        // WALANG EDIT BUTTON SA RELEASED
        break;
}
}

// ── Generate payroll modal ───────────────────────────────────────────
function openGeneratePayrollModal() {
    if (!canCreate()) {
        Swal.fire('Access Denied', hasHR && currentUserRole==='ClinicAdmin'
            ? 'HR manages payroll generation. You are in oversight mode.'
            : 'No permission to generate payroll.', 'error');
        return;
    }
    document.getElementById('generatePayrollForm')?.reset();

    const now   = new Date();
    const y     = now.getFullYear();
    const m     = String(now.getMonth()+1).padStart(2,'0');
    safeSetValue('payroll_period', `${y}-${m}`);
    safeSetValue('period_start',   `${y}-${m}-01`);
    safeSetValue('period_end',     `${y}-${m}-15`);

    ['validate_attendance','include_sss','include_philhealth','include_pagibig','include_tax']
        .forEach(id => { const el = document.getElementById(id); if (el) el.checked = true; });
    ['prorate_salary'].forEach(id => { const el = document.getElementById(id); if (el) el.checked = false; });
    ['manual_bonus','manual_deduction','manual_holiday_pay','manual_allowances']
        .forEach(id => safeSetValue(id, '0'));
    safeSetValue('adjustment_remarks', '');

    new bootstrap.Modal(document.getElementById('generatePayrollModal')).show();
}

// ============= GENERATE PAYROLL FUNCTION =============
function generateBulkPayroll(e) {
    e.preventDefault();
    if (!canCreate()) { 
        Swal.fire('Access Denied', 'No permission to generate payroll', 'error'); 
        return; 
    }

    const period = safeGetValue('payroll_period');
    const start  = safeGetValue('period_start');
    const end    = safeGetValue('period_end');
    
    if (!period || !start || !end) { 
        Swal.fire('Error', 'Please fill all required fields', 'error'); 
        return; 
    }

    const payload = {
        generate_bulk_payroll:      true,
        payroll_period:             period,
        period_start:               start,
        period_end:                 end,
        auto_submit:                safeGetChecked('auto_submit'),
        use_manual_contributions:   safeGetChecked('use_manual_contributions'),
        manual_sss:                 parseFloat(safeGetValue('manual_sss'))        || 0,
        manual_philhealth:          parseFloat(safeGetValue('manual_philhealth')) || 0,
        manual_pagibig:             parseFloat(safeGetValue('manual_pagibig'))    || 0,
        manual_tax:                 parseFloat(safeGetValue('manual_tax'))        || 0,
        validate_attendance:        safeGetChecked('validate_attendance'),
        prorate_salary:             safeGetChecked('prorate_salary'),
        manual_bonus:               parseFloat(safeGetValue('manual_bonus'))      || 0,
        manual_deduction:           parseFloat(safeGetValue('manual_deduction'))  || 0,
        manual_holiday_pay:         parseFloat(safeGetValue('manual_holiday_pay'))|| 0,
        manual_allowances:          parseFloat(safeGetValue('manual_allowances')) || 0,
        adjustment_remarks:         safeGetValue('adjustment_remarks'),
        include_sss:                safeGetChecked('include_sss'),
        include_philhealth:         safeGetChecked('include_philhealth'),
        include_pagibig:            safeGetChecked('include_pagibig'),
        include_tax:                safeGetChecked('include_tax'),
    };

    Swal.fire({
        title: 'Generate Payroll for All Employees?',
        html: `<div class="text-start">
            <p><strong>Period:</strong> ${period}</p>
            <p><strong>Range:</strong> ${formatDate(start,true)} – ${formatDate(end,true)}</p>
            <p><strong>Attendance:</strong> ${payload.validate_attendance ? 'Validate' : 'Skip'}</p>
        </div>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, generate',
        showLoaderOnConfirm: true,
        preConfirm: () => fetch('api/payroll.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        }).then(r => r.json()),
    }).then(result => {
        if (!result.isConfirmed || !result.value) return;
        const v = result.value;
        
        if (v.success) {
            let successHtml = `<p>${v.message}</p>`;
            successHtml += `<p><strong>Generated:</strong> ${v.generated_count || 0}</p>`;
            successHtml += `<p><strong>No attendance:</strong> ${v.no_attendance_count || 0}</p>`;
            successHtml += `<p><strong>Skipped:</strong> ${v.skipped_count || 0}</p>`;
            
            if (v.validation_errors && v.validation_errors.length > 0) {
                successHtml += `<hr><div class="alert alert-warning mt-3">`;
                successHtml += `<h6><i class="bi bi-exclamation-triangle"></i> Employees Skipped Due to Unapproved Records:</h6>`;
                successHtml += `<ul class="mb-0" style="max-height: 300px; overflow-y: auto;">`;
                v.validation_errors.forEach(err => {
                    successHtml += `<li><strong>${err.employee_name}</strong>:<ul>`;
                    err.issues.forEach(issue => {
                        successHtml += `<li class="text-danger">⚠️ ${issue.message}</li>`;
                    });
                    successHtml += `</ul></li>`;
                });
                successHtml += `</ul></div>`;
            }
            
            Swal.fire({ title: 'Done!', html: successHtml, icon: 'success' });
            bootstrap.Modal.getInstance(document.getElementById('generatePayrollModal'))?.hide();
            loadPayrolls(); 
            loadSummary();
        } else {
            Swal.fire('Error', v.error || 'Failed to generate payroll', 'error');
        }
    }).catch(error => {
        console.error('Generate payroll error:', error);
        Swal.fire('Error', 'Network error. Please try again.', 'error');
    });
}

// ── Edit payroll (unchanged) ─────────────────────────────────────────
function openEditPayrollModal(payrollId) {
    if (!canEdit()) { Swal.fire('Access Denied','No permission to edit payroll','error'); return; }
    currentPayrollId = payrollId;

    fetch(`api/payroll.php?id=${payrollId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const p = data.data;
            safeSetValue('edit_overtime',         p.overtime          || 0);
            safeSetValue('edit_holiday_pay',      p.holiday_pay       || 0);
            safeSetValue('edit_allowances',       p.allowances        || 0);
            safeSetValue('edit_bonuses',          p.bonuses           || 0);
            safeSetValue('edit_other_deductions', p.other_deductions  || 0);
            new bootstrap.Modal(document.getElementById('editPayrollModal')).show();
        });
}

function recalculatePayroll() {
    fetch('api/payroll.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            preview_payroll:  true,
            payroll_id:       currentPayrollId,
            overtime:         parseFloat(safeGetValue('edit_overtime'))         || 0,
            holiday_pay:      parseFloat(safeGetValue('edit_holiday_pay'))      || 0,
            allowances:       parseFloat(safeGetValue('edit_allowances'))       || 0,
            bonuses:          parseFloat(safeGetValue('edit_bonuses'))          || 0,
            other_deductions: parseFloat(safeGetValue('edit_other_deductions')) || 0,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { Swal.fire('Error', data.error, 'error'); return; }
        Swal.fire({
            title: 'Recalculation Preview',
            html: `<p><strong>Gross Pay:</strong> ${formatCurrency(data.gross_pay)}</p>
                   <p><strong>Total Deductions:</strong> ${formatCurrency(data.total_deductions)}</p>
                   <hr><p class="fw-bold text-success">Net Pay: ${formatCurrency(data.net_pay)}</p>`,
            icon: 'info'
        });
    });
}

function savePayrollChanges(e) {
    e.preventDefault();
    if (!canEdit()) { Swal.fire('Access Denied','No permission','error'); return; }

    const payload = {
        update_payroll:   true,
        payroll_id:       currentPayrollId,
        overtime:         parseFloat(safeGetValue('edit_overtime'))         || 0,
        holiday_pay:      parseFloat(safeGetValue('edit_holiday_pay'))      || 0,
        allowances:       parseFloat(safeGetValue('edit_allowances'))       || 0,
        bonuses:          parseFloat(safeGetValue('edit_bonuses'))          || 0,
        other_deductions: parseFloat(safeGetValue('edit_other_deductions')) || 0,
    };
    const adj = parseFloat(safeGetValue('edit_adjustment_amount')) || 0;
    if (adj > 0) {
        payload.adjustment_amount = adj;
        payload.adjustment_type   = safeGetValue('edit_adjustment_type');
        payload.adjustment_reason = safeGetValue('edit_adjustment_reason') || '';
    }

    fetch('api/payroll.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire('Updated!','Payroll updated successfully','success');
            bootstrap.Modal.getInstance(document.getElementById('editPayrollModal'))?.hide();
            bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
            loadPayrolls();
        } else {
            Swal.fire('Error', result.error, 'error');
        }
    });
}

// ── Workflow actions (unchanged, uses permission checks) ─────────────
function submitForApproval() {
    if (!canEdit()) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({
        title: 'Submit for Approval?', text: 'Payroll will be sent to approvers.',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, submit'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/payroll.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ submit_for_approval: true, payroll_id: currentPayrollId })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                Swal.fire('Submitted!', data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
                loadPayrolls(); loadSummary();
            } else Swal.fire('Error', data.error, 'error');
        });
    });
}

function approvePayroll() {
    if (!canApprove()) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({
        title: 'Approve this payroll?', input: 'textarea', inputLabel: 'Remarks (optional)',
        showCancelButton: true, confirmButtonText: 'Approve'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/payroll.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ approve_payroll: true, payroll_id: currentPayrollId, remarks: r.value || '' })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                Swal.fire('Approved!', data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
                loadPayrolls(); loadSummary();
            } else Swal.fire('Error', data.error, 'error');
        });
    });
}

function rejectPayroll() {
    if (!canReject()) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({
        title: 'Reject this payroll?', input: 'textarea', inputLabel: 'Reason for rejection',
        inputValidator: v => !v ? 'Please provide a reason' : null,
        showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#dc3545'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/payroll.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ reject_payroll: true, payroll_id: currentPayrollId, remarks: r.value })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                Swal.fire('Rejected!', data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
                loadPayrolls(); loadSummary();
            } else Swal.fire('Error', data.error, 'error');
        });
    });
}

function releasePayroll() {
    if (!canRelease()) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({
        title: 'Release Payroll?',
        html: `<div class="text-start"><p>This will:</p><ul>
            <li>Mark payroll as Released</li><li>Generate payslip</li>
            <li>Notify employee</li><li>Mark overtime as paid</li></ul>
            <p class="text-warning mt-2"><strong>This action cannot be undone.</strong></p></div>`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, release'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/payroll.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ release_payroll: true, payroll_id: currentPayrollId })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                Swal.fire('Released!', `${data.message}${data.payslip_code ? `<br><strong>Payslip: ${data.payslip_code}</strong>` : ''}`, 'success');
                bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
                loadPayrolls(); loadSummary();
            } else Swal.fire('Error', data.error, 'error');
        });
    });
}

function cancelPayroll() {
    if (!canCancel()) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({
        title: 'Cancel Payroll?', text: 'Cancelled payrolls cannot be recovered.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, cancel', confirmButtonColor: '#dc3545'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/payroll.php', { method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ cancel_payroll: true, payroll_id: currentPayrollId })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                Swal.fire('Cancelled!','Payroll has been cancelled','success');
                bootstrap.Modal.getInstance(document.getElementById('viewPayrollModal'))?.hide();
                loadPayrolls();
            }
        });
    });
}

// ── Filters (unchanged) ──────────────────────────────────────────────
function filterPayrolls() { loadPayrolls(); }
function resetFilters() {
    ['filterEmployee','filterPeriod','filterStatus'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    loadPayrolls();
}

// ── Cutoff helper (unchanged) ────────────────────────────────────────
function setupCutoffListener() {
    document.getElementById('cutoff_type')?.addEventListener('change', function () {
        const cd = document.getElementById('custom_dates');
        if (cd) cd.style.display = this.value === 'custom' ? 'flex' : 'none';
        if (this.value !== 'custom') updateDateRange();
    });
}

function updateDateRange() {
    const cutoff = safeGetValue('cutoff_type');
    const period = safeGetValue('payroll_period');
    if (!period) return;

    const [y, m] = period.split('-').map(Number);
    const last   = new Date(y, m, 0).getDate();
    const mm     = String(m).padStart(2, '0');

    const ranges = {
        first_half:  [`${y}-${mm}-01`, `${y}-${mm}-15`],
        second_half: [`${y}-${mm}-16`, `${y}-${mm}-${last}`],
        whole_month: [`${y}-${mm}-01`, `${y}-${mm}-${last}`],
    };
    const r = ranges[cutoff];
    if (!r) return;
    safeSetValue('period_start', r[0]);
    safeSetValue('period_end',   r[1]);
}

// ── Export ───────────────────────────────────────────────────────────
function exportPayroll() {
    if (!canExport()) { Swal.fire('Access Denied','No permission to export','error'); return; }
    const emp    = safeGetValue('filterEmployee');
    const period = safeGetValue('filterPeriod');
    const status = safeGetValue('filterStatus');
    window.location.href = `api/payroll.php?export=true&employee_id=${emp}&payroll_period=${period}&status=${encodeURIComponent(status)}`;
}

// ── Utility (unchanged) ──────────────────────────────────────────────
function safeSetText(id, val, def = '—') {
    const el = document.getElementById(id);
    if (el) el.textContent = (val !== undefined && val !== null) ? val : def;
}
function safeSetValue(id, val) {
    const el = document.getElementById(id); if (el) el.value = val;
}
function safeGetValue(id, def = '') {
    return document.getElementById(id)?.value ?? def;
}
function safeGetChecked(id, def = false) {
    return document.getElementById(id)?.checked ?? def;
}
function setElementDisplay(id, val) {
    const el = document.getElementById(id); if (el) el.style.display = val;
}
function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatDate(d, dateOnly = false) {
    if (!d) return 'N/A';
    const dt = new Date(d);
    return dateOnly
        ? dt.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' })
        : dt.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}
function formatDateForInput(d) { return d.toISOString().split('T')[0]; }