let employeesData = [], positionsData = [];
let currentStep = 0;
const totalSteps = 6;
const tabIds = ['basic', 'employment', 'government', 'bank', 'documents', 'rolesTab'];
const tabButtons = ['basic-tab', 'employment-tab', 'government-tab', 'bank-tab', 'documents-tab', 'roles-tab'];
const SCHED_DAYS = ['mon','tue','wed','thu','fri','sat','sun'];
const SCHED_LABELS = { mon:'Mon', tue:'Tue', wed:'Wed', thu:'Thu', fri:'Fri', sat:'Sat', sun:'Sun' };

const schedState = {
    mon: { enabled: false, slots: [] },
    tue: { enabled: false, slots: [] },
    wed: { enabled: false, slots: [] },
    thu: { enabled: false, slots: [] },
    fri: { enabled: false, slots: [] },
    sat: { enabled: false, slots: [] },
    sun: { enabled: false, slots: [] }
};

function renderScheduleBuilder() {
    const container = document.getElementById('schedDayRows');
    if (!container) return;
    container.innerHTML = '';

    SCHED_DAYS.forEach(day => {
        const d = schedState[day];
        const wrap = document.createElement('div');
        wrap.style.cssText = 'margin-bottom:6px;';

        // Checkbox + label row
        const headerRow = document.createElement('div');
        headerRow.style.cssText = 'display:flex;align-items:center;gap:8px;';

        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = d.enabled;
        chk.onchange = () => {
            d.enabled = chk.checked;
            if (!d.enabled) d.slots = [];
            else if (d.slots.length === 0) d.slots = [['09:00','17:00']];
            renderScheduleBuilder();
        };

        const lbl = document.createElement('span');
        lbl.textContent = SCHED_LABELS[day];
        lbl.style.cssText = 'font-size:13px;font-weight:500;width:36px;';

        headerRow.appendChild(chk);
        headerRow.appendChild(lbl);

        if (d.enabled) {
            d.slots.forEach((slot, idx) => {
                const slotRow = document.createElement('div');
                slotRow.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:4px;';

                const t1 = document.createElement('input');
                t1.type = 'time'; t1.value = slot[0];
                t1.className = 'form-control form-control-sm';
                t1.style.width = '110px';
                t1.oninput = () => { slot[0] = t1.value; updateScheduleJSON(); };

                const sep = document.createElement('span');
                sep.textContent = '–'; sep.style.color = '#6c757d';

                const t2 = document.createElement('input');
                t2.type = 'time'; t2.value = slot[1];
                t2.className = 'form-control form-control-sm';
                t2.style.width = '110px';
                t2.oninput = () => { slot[1] = t2.value; updateScheduleJSON(); };

                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'btn btn-sm btn-outline-danger';
                rm.innerHTML = '&times;';
                rm.onclick = () => { d.slots.splice(idx, 1); renderScheduleBuilder(); updateScheduleJSON(); };

                slotRow.appendChild(t1); slotRow.appendChild(sep);
                slotRow.appendChild(t2); slotRow.appendChild(rm);
                headerRow.appendChild(slotRow);
            });

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn btn-sm btn-outline-secondary';
            addBtn.style.cssText = 'font-size:11px;padding:2px 8px;margin-left:4px;';
            addBtn.textContent = '+ slot';
            addBtn.onclick = () => { d.slots.push(['09:00','17:00']); renderScheduleBuilder(); updateScheduleJSON(); };
            headerRow.appendChild(addBtn);
        }

        wrap.appendChild(headerRow);
        container.appendChild(wrap);
    });

    updateScheduleJSON();
}

function updateScheduleJSON() {
    const out = {};
    SCHED_DAYS.forEach(day => {
        const d = schedState[day];
        if (d.enabled && d.slots.length > 0) {
            out[day] = d.slots.map(s => s[0] + '-' + s[1]);
        }
    });
    const hiddenInput = document.getElementById('add_schedule');
    if (hiddenInput) hiddenInput.value = JSON.stringify(out);
}

function resetScheduleBuilder() {
    SCHED_DAYS.forEach(day => { schedState[day].enabled = false; schedState[day].slots = []; });
    renderScheduleBuilder();
}

let currentUserRole = null;
let userPermissions = {};
let hasHR = false;
let isOwner = false;

// ✅ Global storage for currently viewed employee IDs
let _currentViewUserId   = null;  // users.id  — for editEmployee()
let _currentViewEmpDocId = null;  // employees.id — for document operations

async function loadUserPermissions() {
    try {
        const response = await fetch('api/users.php?get_permissions=true');
        const data = await response.json();
        if (data.success) {
            currentUserRole = data.data.role;
            userPermissions = data.data.permissions || {};
            hasHR = data.data.hasHR || false;
            isOwner = currentUserRole === 'ClinicAdmin';
            applyPermissionBasedUI();
        } else {
            console.error('Failed to load permissions:', data.error);
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
    }
}

function can(permission) {
    if (userPermissions && typeof userPermissions === 'object') {
        return userPermissions[permission] === true;
    }
    return false;
}

function canCreate()          { return can('can_create_employees'); }
function canRead()            { return can('can_view_employees'); }
function canUpdate()          { return can('can_edit_employees'); }
function canDelete()          { return can('can_delete_employees'); }
function canExport()          { return can('can_export') || can('can_create_employees'); }
function canManageDocuments() { return can('can_manage_documents') || can('can_edit_employees'); }
function canViewSalary()      { return can('can_view_salary') || can('can_edit_employees'); }
function canViewAudit()       { return can('can_view_audit'); }

function applyPermissionBasedUI() {
    const addButton = document.querySelector('button[onclick="openAddEmployeeModal()"]');
    if (addButton) addButton.style.display = canCreate() ? 'inline-block' : 'none';
    const exportButton = document.querySelector('button[onclick="exportToExcel()"]');
    if (exportButton) exportButton.style.display = canExport() ? 'inline-block' : 'none';
    if (!canRead()) {
        const container = document.querySelector('.container-fluid');
        if (container) { container.innerHTML = `<div class="container-fluid p-5 text-center"><div class="alert alert-danger"><i class="bi bi-shield-lock display-4 d-block mb-3"></i><h3>Access Denied</h3><p>You don't have permission to view employee records.</p></div></div>`; }
    }
}

// ============================================================
// TAB NAVIGATION
// ============================================================
function nextStep() {
    if (currentStep < totalSteps - 1) {
        if (!validateCurrentStep()) return;
        currentStep++;
        showStep(currentStep);
        updateButtonVisibility();
    }
}

function previousStep() {
    if (currentStep > 0) { currentStep--; showStep(currentStep); updateButtonVisibility(); }
}

function showStep(stepIndex) {
    tabButtons.forEach((btn, idx) => { 
        const tabPane = document.getElementById(tabIds[idx]); 
        if (tabPane) tabPane.classList.remove('show', 'active'); 
    });
    if (stepIndex < tabButtons.length) {
        const currentTabBtn = document.getElementById(tabButtons[stepIndex]);
        const currentTab    = document.getElementById(tabIds[stepIndex]);
        if (currentTabBtn && currentTab) { 
            currentTab.classList.add('show', 'active'); 
            try { new bootstrap.Tab(currentTabBtn).show(); } catch(e) {} 
        }
    }
    
    // ✅ Restore rider/optometrist fields visibility after tab switch
    setTimeout(() => {
        const role = document.getElementById('add_role')?.value;
        if (role === 'Rider') {
            const rf = document.getElementById('riderFields');
            if (rf) rf.style.display = 'block';
        } else if (role === 'Optometrist') {
            const of = document.getElementById('optometristFields');
            if (of) of.style.display = 'block';
        }
    }, 50);
}

function validateCurrentStep() {
    const requiredFields = {
        0: [{ id:'add_first_name',name:'First Name' },{ id:'add_last_name',name:'Last Name' },{ id:'add_email',name:'Email' },{ id:'add_password',name:'Password' },{ id:'add_role',name:'Role' },{ id:'add_status',name:'Status' }],
        1: [{ id:'add_position_id',name:'Position' },{ id:'add_date_hired',name:'Date Hired' },{ id:'add_employment_type',name:'Employment Type' },{ id:'add_salary_frequency',name:'Salary Frequency' },{ id:'add_basic_salary',name:'Basic Salary' }],
        2: [], 3: [], 4: []
    };
    const fields = requiredFields[currentStep] || [];
    const missingFields = [];
    for (let field of fields) { const el = document.getElementById(field.id); if (!el || !el.value || el.value.trim() === '') missingFields.push(field.name); }
    if (missingFields.length > 0) { Swal.fire({ icon:'warning', title:'Incomplete Form', text:`Please fill in: ${missingFields.join(', ')}`, confirmButtonText:'OK' }); return false; }
    if (currentStep === 2) return validateGovIdsStep();
    if (currentStep === 3) return validateBankStep();
    return true;
}

function updateButtonVisibility() {
    const backBtn = document.getElementById('backBtn'), nextBtn = document.getElementById('nextBtn'), createBtn = document.getElementById('createBtn');
    if (backBtn && nextBtn && createBtn) {
        backBtn.style.display   = currentStep > 0               ? 'block' : 'none';
        nextBtn.style.display   = currentStep < totalSteps - 1  ? 'block' : 'none';
        createBtn.style.display = currentStep === totalSteps - 1 ? 'block' : 'none';
    }
}

function resetAddEmployeeModal() {
    resetScheduleBuilder(); 
    const form = document.getElementById('addEmployeeForm');
    if (form) { form.reset(); form.classList.remove('was-validated'); form.querySelectorAll('.form-control, .form-select').forEach(input => input.classList.remove('is-valid','is-invalid')); form.querySelectorAll('.invalid-feedback').forEach(fb => fb.textContent = ''); }
    currentStep = 0;
    setTimeout(() => { const basicTabBtn = document.getElementById('basic-tab'); if (basicTabBtn) new bootstrap.Tab(basicTabBtn).show(); updateButtonVisibility(); }, 50);
}

document.getElementById('addEmployeeModal').addEventListener('hidden.bs.modal', resetAddEmployeeModal);

// ============================================================
// DOM READY
// ============================================================
document.addEventListener('DOMContentLoaded', async () => {
    await loadUserPermissions();
    if (canRead()) { await loadEmployees(); await loadPositions(); }

    const addEmployeeForm = document.getElementById('addEmployeeForm');
    if (addEmployeeForm) addEmployeeForm.addEventListener('submit', saveEmployee);
    const addPositionForm = document.getElementById('addPositionForm');
    if (addPositionForm) addPositionForm.addEventListener('submit', savePosition);
    const editEmployeeForm = document.getElementById('editEmployeeForm');
    if (editEmployeeForm) editEmployeeForm.addEventListener('submit', updateEmployeeInfo);
    const searchInput = document.getElementById('searchEmployees');
    if (searchInput) searchInput.addEventListener('keyup', searchEmployees);
    const employeeDocumentForm = document.getElementById('employeeDocumentForm');
    if (employeeDocumentForm) employeeDocumentForm.addEventListener('submit', uploadDocument);

    document.querySelectorAll('#addEmployeeTabs button').forEach(tab => {
        tab.addEventListener('shown.bs.tab', (event) => {
            const tabIndex = tabButtons.indexOf(event.target.id);
            if (tabIndex !== -1) { currentStep = tabIndex; updateButtonVisibility(); }
        });
    });

    ['add_resume','add_government_id','add_medical_cert','add_clearance'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener('change', updateFilePreview); });
    attachIDValidationEvents();
});

// ============================================================
// BLOCK LETTERS & ATTACH EVENTS
// ============================================================
function blockNonDigits(e) {
    const allowed = ['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
    if (allowed.includes(e.key)) return;
    if ((e.ctrlKey || e.metaKey) && ['a','c','v','x'].includes(e.key.toLowerCase())) return;
    if (!/^\d$/.test(e.key)) e.preventDefault();
}

function attachIDValidationEvents() {
    const govFields = [
        { id:'add_sss_number',fmt:formatSSS }, { id:'add_philhealth_number',fmt:formatPhilHealth },
        { id:'add_pagibig_number',fmt:formatPagIbig }, { id:'add_tin_number',fmt:formatTIN },
        { id:'add_bank_account_number',fmt:formatBankAccount }
    ];
    govFields.forEach(({ id, fmt }) => {
        const el = document.getElementById(id); if (!el) return;
        el.addEventListener('keydown', blockNonDigits);
        el.addEventListener('paste',   () => setTimeout(() => fmt(el), 0));
        el.addEventListener('input',   () => fmt(el));
        el.addEventListener('blur',    () => fmt(el));
    });
    ['add_phone_number','add_emergency_contact_number'].forEach(id => {
        const el = document.getElementById(id); if (!el) return;
        el.addEventListener('keydown', blockNonDigits);
        el.addEventListener('paste',   () => setTimeout(() => formatPhoneNumber(el), 0));
        el.addEventListener('input',   () => formatPhoneNumber(el));
        el.addEventListener('blur',    () => formatPhoneNumber(el));
    });
}

// ============================================================
// FORMAT + VALIDATE
// ============================================================
function digitsOnly(value) { return value.replace(/\D/g, ''); }

function setFeedback(input, message) {
    let fb = input.parentNode.querySelector('.invalid-feedback');
    if (!fb) { fb = document.createElement('div'); fb.className = 'invalid-feedback'; input.parentNode.appendChild(fb); }
    fb.textContent = message;
}
function clearFeedback(input) { const fb = input.parentNode.querySelector('.invalid-feedback'); if (fb) fb.textContent = ''; }

function formatSSS(input) {
    let d = digitsOnly(input.value).slice(0, 10), f = d;
    if (d.length > 2) f = d.slice(0,2) + '-' + d.slice(2);
    if (d.length > 9) f = d.slice(0,2) + '-' + d.slice(2,9) + '-' + d.slice(9);
    input.value = f; return validateSSS(input);
}
function validateSSS(input) {
    const d = digitsOnly(input.value);
    if (d.length === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); return true; }
    if (d.length === 10 && /^\d{2}-\d{7}-\d$/.test(input.value)) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
    input.classList.remove('is-valid'); input.classList.add('is-invalid'); setFeedback(input, `SSS requires exactly 10 digits. You entered ${d.length}.`); return false;
}

function formatPhilHealth(input) {
    let d = digitsOnly(input.value).slice(0, 12), f = d;
    if (d.length > 2)  f = d.slice(0,2) + '-' + d.slice(2);
    if (d.length > 11) f = d.slice(0,2) + '-' + d.slice(2,11) + '-' + d.slice(11);
    input.value = f; return validatePhilHealth(input);
}
function validatePhilHealth(input) {
    const d = digitsOnly(input.value);
    if (d.length === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); return true; }
    if (d.length === 12 && /^\d{2}-\d{9}-\d$/.test(input.value)) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
    input.classList.remove('is-valid'); input.classList.add('is-invalid'); setFeedback(input, `PhilHealth requires exactly 12 digits. You entered ${d.length}.`); return false;
}

function formatPagIbig(input) {
    let d = digitsOnly(input.value).slice(0, 12), f = d;
    if (d.length > 4) f = d.slice(0,4) + '-' + d.slice(4);
    if (d.length > 8) f = d.slice(0,4) + '-' + d.slice(4,8) + '-' + d.slice(8);
    input.value = f; return validatePagIbig(input);
}
function validatePagIbig(input) {
    const d = digitsOnly(input.value);
    if (d.length === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); return true; }
    if (d.length === 12 && /^\d{4}-\d{4}-\d{4}$/.test(input.value)) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
    input.classList.remove('is-valid'); input.classList.add('is-invalid'); setFeedback(input, `Pag-IBIG requires exactly 12 digits. You entered ${d.length}.`); return false;
}

function formatTIN(input) {
    let d = digitsOnly(input.value).slice(0, 12), f = d;
    if (d.length > 3) f = d.slice(0,3) + '-' + d.slice(3);
    if (d.length > 6) f = d.slice(0,3) + '-' + d.slice(3,6) + '-' + d.slice(6);
    if (d.length > 9) f = d.slice(0,3) + '-' + d.slice(3,6) + '-' + d.slice(6,9) + '-' + d.slice(9);
    input.value = f; return validateTIN(input);
}
function validateTIN(input) {
    const d = digitsOnly(input.value);
    if (d.length === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); return true; }
    const ok9 = d.length === 9 && /^\d{3}-\d{3}-\d{3}$/.test(input.value);
    const ok12 = d.length === 12 && /^\d{3}-\d{3}-\d{3}-\d{3}$/.test(input.value);
    if (ok9 || ok12) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
    input.classList.remove('is-valid'); input.classList.add('is-invalid'); setFeedback(input, `TIN requires 9 or 12 digits. You entered ${d.length}.`); return false;
}

const bankAccountRules = {
    'BDO':{ min:10, max:12, hint:'BDO: 10–12 digits' }, 'BPI':{ min:10, max:10, hint:'BPI: exactly 10 digits' },
    'Metrobank':{ min:13, max:16, hint:'Metrobank: 13–16 digits' }, 'Other':{ min:6, max:16, hint:'Enter account number (6–16 digits)' }
};

function updateBankAccountHint() {
    const bankEl = document.getElementById('add_bank_name'), hint = document.getElementById('bank_account_hint'), input = document.getElementById('add_bank_account_number');
    if (!bankEl || !hint || !input) return;
    const rules = bankAccountRules[bankEl.value];
    if (rules) { hint.textContent = rules.hint; input.maxLength = rules.max; input.placeholder = rules.min === rules.max ? `${'X'.repeat(rules.min)} (${rules.min} digits)` : `${'X'.repeat(rules.min)}–${'X'.repeat(rules.max)}`; }
    else { hint.textContent = 'Select a bank first to see the required digit count.'; input.maxLength = 16; input.placeholder = 'Enter account number'; }
    if (input.value) validateBankAccount(input);
}

function formatBankAccount(input) { input.value = digitsOnly(input.value); return validateBankAccount(input); }

function validateBankAccount(input) {
    const bankEl = document.getElementById('add_bank_name') || document.getElementById('edit_bank_name');
    const bank = bankEl ? bankEl.value : '', rules = bankAccountRules[bank], len = input.value.length;
    const errEl = document.getElementById('bank_account_error');
    if (len === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); return true; }
    if (!/^\d+$/.test(input.value)) { input.classList.remove('is-valid'); input.classList.add('is-invalid'); if (errEl) errEl.textContent = 'Digits only.'; return false; }
    if (!bank || !rules) {
        if (len >= 6 && len <= 16) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
        input.classList.remove('is-valid'); input.classList.add('is-invalid'); if (errEl) errEl.textContent = 'Must be 6–16 digits.'; return false;
    }
    if (len >= rules.min && len <= rules.max) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); return true; }
    input.classList.remove('is-valid'); input.classList.add('is-invalid'); if (errEl) errEl.textContent = rules.hint; setFeedback(input, rules.hint); return false;
}

function formatPhoneNumber(input) {
    let d = digitsOnly(input.value).slice(0, 11), f = d;
    if (d.length > 4) f = d.slice(0,4) + '-' + d.slice(4);
    if (d.length > 7) f = d.slice(0,4) + '-' + d.slice(4,7) + '-' + d.slice(7);
    input.value = f;
    if (d.length === 0) { input.classList.remove('is-valid','is-invalid'); clearFeedback(input); }
    else if (d.length === 11 && /^09\d{9}$/.test(d)) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); clearFeedback(input); }
    else { input.classList.remove('is-valid'); input.classList.add('is-invalid'); setFeedback(input, !d.startsWith('09') ? 'Must start with 09.' : `Requires 11 digits. You entered ${d.length}.`); }
}

function validateGovIdsStep() {
    const checks = [{ id:'add_sss_number',fn:formatSSS },{ id:'add_philhealth_number',fn:formatPhilHealth },{ id:'add_pagibig_number',fn:formatPagIbig },{ id:'add_tin_number',fn:formatTIN }];
    const errors = [];
    checks.forEach(({ id, fn }) => { const el = document.getElementById(id); if (!el || el.value.trim() === '') return; if (!fn(el)) errors.push(el.previousElementSibling?.textContent?.trim() || id); });
    if (errors.length > 0) { Swal.fire({ icon:'warning', title:'Invalid ID format', html:`Please fix: <b>${errors.join(', ')}</b>`, confirmButtonText:'OK' }); return false; }
    return true;
}

function validateBankStep() {
    const el = document.getElementById('add_bank_account_number');
    if (!el || el.value.trim() === '') return true;
    if (!formatBankAccount(el)) { Swal.fire({ icon:'warning', title:'Invalid account number', text:'Please fix the bank account number format.', confirmButtonText:'OK' }); return false; }
    return true;
}

function validateGovIdsAndBank() {
    const checks = [{ id:'add_sss_number',label:'SSS Number',fn:formatSSS },{ id:'add_philhealth_number',label:'PhilHealth Number',fn:formatPhilHealth },{ id:'add_pagibig_number',label:'Pag-IBIG Number',fn:formatPagIbig },{ id:'add_tin_number',label:'TIN Number',fn:formatTIN },{ id:'add_bank_account_number',label:'Account Number',fn:formatBankAccount }];
    const errors = [];
    checks.forEach(({ id, label, fn }) => { const el = document.getElementById(id); if (!el || el.value.trim() === '') return; if (!fn(el)) errors.push(label); });
    if (errors.length > 0) {
        const hasGov = errors.some(e => ['SSS Number','PhilHealth Number','Pag-IBIG Number','TIN Number'].includes(e));
        const hasBank = errors.includes('Account Number');
        if (hasGov) { const t = document.getElementById('government-tab'); if (t) new bootstrap.Tab(t).show(); }
        else if (hasBank) { const t = document.getElementById('bank-tab'); if (t) new bootstrap.Tab(t).show(); }
        Swal.fire({ icon:'warning', title:'Invalid format', html:`Please fix before submitting:<br><br><b>${errors.join('<br>')}</b>`, confirmButtonText:'OK' });
        return false;
    }
    return true;
}

function validateEditGovIdsAndBank() {
    const checks = [{ id:'edit_sss_number',label:'SSS Number',fn:formatSSS },{ id:'edit_philhealth_number',label:'PhilHealth Number',fn:formatPhilHealth },{ id:'edit_pagibig_number',label:'Pag-IBIG Number',fn:formatPagIbig },{ id:'edit_tin_number',label:'TIN Number',fn:formatTIN },{ id:'edit_bank_account_number',label:'Account Number',fn:_validateEditBank }];
    const errors = [];
    checks.forEach(({ id, label, fn }) => { const el = document.getElementById(id); if (!el || el.value.trim() === '') return; if (!fn(el)) errors.push(label); });
    if (errors.length > 0) { Swal.fire({ icon:'warning', title:'Invalid format', html:`Please fix before saving:<br><br><b>${errors.join('<br>')}</b>`, confirmButtonText:'OK' }); return false; }
    return true;
}

function _validateEditBank(el) {
    el.value = digitsOnly(el.value);
    const bankEl = document.getElementById('edit_bank_name'), bank = bankEl ? bankEl.value : '', rules = bankAccountRules[bank], len = el.value.length;
    if (len === 0) { el.classList.remove('is-valid','is-invalid'); return true; }
    if (!bank || !rules) {
        if (len >= 6 && len <= 16) { el.classList.remove('is-invalid'); el.classList.add('is-valid'); return true; }
        el.classList.remove('is-valid'); el.classList.add('is-invalid'); setFeedback(el, 'Must be 6–16 digits.'); return false;
    }
    if (len >= rules.min && len <= rules.max) { el.classList.remove('is-invalid'); el.classList.add('is-valid'); return true; }
    el.classList.remove('is-valid'); el.classList.add('is-invalid'); setFeedback(el, rules.hint); return false;
}

// ============================================================
// FILE PREVIEW
// ============================================================
function updateFilePreview() {
    const filePreviewSection = document.getElementById('filePreviewSection'), filesList = document.getElementById('selectedFilesList'), allFiles = [];
    const fileInputs = [{ id:'add_resume',label:'Resume/CV' },{ id:'add_government_id',label:'Government ID' },{ id:'add_medical_cert',label:'Medical Certificate' },{ id:'add_clearance',label:'NBI/Police Clearance' }];
    fileInputs.forEach(fi => { const input = document.getElementById(fi.id); if (input && input.files.length > 0) allFiles.push({ label:fi.label, file:input.files[0], id:fi.id }); });
    if (allFiles.length > 0) {
        filesList.innerHTML = allFiles.map((item, index) => `<div class="list-group-item d-flex justify-content-between align-items-center"><div><i class="bi ${getFileIcon(item.file.name)} me-2"></i><strong>${item.label}:</strong> ${item.file.name}<small class="d-block text-muted">${formatFileSize(item.file.size)}</small></div><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFile('${item.id}', ${index})"><i class="bi bi-x"></i></button></div>`).join('');
        filePreviewSection.style.display = 'block';
    } else { filePreviewSection.style.display = 'none'; }
}

function formatFileSize(bytes) { if (bytes === 0) return '0 Bytes'; const k = 1024, sizes = ['Bytes','KB','MB','GB'], i = Math.floor(Math.log(bytes) / Math.log(k)); return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]; }
function removeFile(inputId, index) { const input = document.getElementById(inputId); if (input) { const dt = new DataTransfer(); Array.from(input.files).forEach((file, i) => { if (i !== index) dt.items.add(file); }); input.files = dt.files; input.dispatchEvent(new Event('change')); } }

// ============================================================
// LOAD DATA
// ============================================================
function loadEmployees() { fetch('api/users.php').then(res => res.json()).then(data => { employeesData = data; renderEmployeesTable(); updateStats(); }); }
function loadPositions() { fetch('api/users.php?get_positions=true').then(res => res.json()).then(data => { positionsData = data; populatePositionDropdowns(); }); }

function populatePositionDropdowns() {
    const addSelect = document.getElementById('add_position_id'), filterSelect = document.getElementById('positionFilter');
    addSelect.innerHTML = '<option value="">Select Position</option>'; filterSelect.innerHTML = '<option value="">All Positions</option>';
    positionsData.forEach(p => { addSelect.innerHTML += `<option value="${p.id}">${p.position_name}</option>`; filterSelect.innerHTML += `<option value="${p.id}">${p.position_name}</option>`; });
}

function renderEmployeesTable() {
    const tbody         = document.getElementById('employeesTableBody');
    const canUpdateEmp  = canUpdate();
    const canDeleteEmp  = canDelete();
    const canViewSalaryEmp = canViewSalary();
    // Only ClinicAdmin can assign roles
    const canAssignRoles = (currentUserRole === 'ClinicAdmin' || isOwner);
 
    tbody.innerHTML = employeesData.map(emp => {
        const fullName = `${emp.first_name} ${emp.last_name}`;
 
        let actionButtons = '';
 
        // View
        actionButtons += `<button class="btn btn-outline-info btn-sm" onclick="viewEmployee(${emp.id})" title="View">
            <i class="bi bi-eye"></i></button>`;
 
        // Edit
        if (canUpdateEmp)
            actionButtons += `<button class="btn btn-outline-primary btn-sm" onclick="editEmployee(${emp.id})" title="Edit">
                <i class="bi bi-pencil"></i></button>`;
 
        // Toggle status
        if (canUpdateEmp)
            actionButtons += `<button class="btn btn-outline-${emp.user_status === 'Active' ? 'danger' : 'success'} btn-sm"
                onclick="toggleEmployeeStatus(${emp.id})" title="${emp.user_status === 'Active' ? 'Deactivate' : 'Activate'}">
                <i class="bi bi-${emp.user_status === 'Active' ? 'archive' : 'check-circle'}"></i></button>`;
 
        // ✅ NEW — Assign Roles button (shield icon, secondary color)
        if (canAssignRoles)
            actionButtons += `<button class="btn btn-outline-secondary btn-sm"
                onclick="openAssignRolesModal(${emp.id}, '${fullName.replace(/'/g,"\\'")}', '${(emp.role||'').replace(/'/g,"\\'")}', '${((emp.first_name?.charAt(0)||'')+(emp.last_name?.charAt(0)||'')).toUpperCase()}')"
                title="Assign Access Roles">
                <i class="bi bi-shield-lock"></i></button>`;
 
        // Delete
        if (canDeleteEmp)
            actionButtons += `<button class="btn btn-outline-danger btn-sm" onclick="deleteEmployee(${emp.id})" title="Delete">
                <i class="bi bi-trash"></i></button>`;
 
        const salaryDisplay = canViewSalaryEmp
            ? (emp.basic_salary ? '₱' + parseFloat(emp.basic_salary).toLocaleString() : 'N/A')
            : '*** Hidden ***';
 
        return `
        <tr>
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                         style="width:40px;height:40px;font-weight:bold;font-size:14px">
                        ${(emp.first_name?.charAt(0)||'')+(emp.last_name?.charAt(0)||'')}
                    </div>
                    <div>
                        <div class="fw-bold">${emp.first_name} ${emp.last_name}</div>
                        <small class="text-muted">${emp.email}</small>
                        <div class="small"><span class="badge bg-secondary">${emp.employee_no}</span></div>
                    </div>
                </div>
            </td>
            <td>
                <div class="fw-bold">${emp.position_name || 'Not assigned'}</div>
                <small class="text-muted">${emp.department || ''}</small>
            </td>
            <td>
                <span class="badge ${getEmploymentBadgeClass(emp.employment_type)}">${emp.employment_type || 'N/A'}</span><br>
                <small class="text-muted">${emp.date_hired ? formatDate(emp.date_hired, true) : 'N/A'}</small>
            </td>
            <td><div class="fw-bold text-success">${salaryDisplay}</div></td>
            <td><span class="badge ${getStatusBadgeClass(emp.employment_status || emp.user_status)}">${emp.employment_status || emp.user_status}</span></td>
            <td><div class="btn-group btn-group-sm">${actionButtons}</div></td>
        </tr>`;
    }).join('');
}

function getEmploymentBadgeClass(type) { return ({'Regular':'bg-success','Probationary':'bg-warning','Contractual':'bg-info','Part-time':'bg-secondary'}[type])||'bg-light text-dark'; }
function getStatusBadgeClass(status)   { return ({'Active':'bg-success','On-Leave':'bg-warning','Resigned':'bg-danger','Terminated':'bg-dark','Inactive':'bg-secondary'}[status])||'bg-light text-dark'; }

function updateStats() {
    document.getElementById('activeEmployees').textContent = employeesData.filter(e=>(e.employment_status==='Active'||e.user_status==='Active')).length;
    document.getElementById('onLeave').textContent         = employeesData.filter(e=>e.employment_status==='On-Leave').length;
    document.getElementById('monthlyPayroll').textContent  = '₱'+employeesData.reduce((s,e)=>s+(parseFloat(e.basic_salary)||0),0).toLocaleString();
    document.getElementById('positionsCount').textContent  = positionsData.length;
}

function openAddEmployeeModal() {
    if (!canCreate()) { Swal.fire('Access Denied','You do not have permission to add employees','error'); return; }
    const form = document.getElementById('addEmployeeForm');
    if (form) { form.reset(); form.classList.remove('was-validated'); form.querySelectorAll('.form-control,.form-select').forEach(input => input.classList.remove('is-valid','is-invalid')); form.querySelectorAll('.invalid-feedback').forEach(fb => fb.textContent = ''); }
    document.getElementById('add_date_hired').value = new Date().toISOString().split('T')[0];
    document.getElementById('add_status').value = 'Active';
    document.getElementById('add_salary_type').value = 'Fixed';
    loadPositions(); 
    
    // ✅ ADD THIS LINE - Load RBAC roles for the new employee
    loadRolesForNewEmployee();
    
    currentStep = 0;
    setTimeout(() => { 
        const b = document.getElementById('basic-tab'); 
        if (b) { 
            new bootstrap.Tab(b).show(); 
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active')); 
            const bp = document.getElementById('basic'); 
            if (bp) bp.classList.add('show','active'); 
        }
        
        // ✅ Reset role fields visibility
        const optFields = document.getElementById('optometristFields');
        const riderFields = document.getElementById('riderFields');
        if (optFields) optFields.style.display = 'none';
        if (riderFields) riderFields.style.display = 'none';
        
        updateButtonVisibility(); 
    }, 100);
    new bootstrap.Modal(document.getElementById('addEmployeeModal')).show();
}

function validateAllSteps() {
    let allValid = true;
    ['add_first_name','add_last_name','add_email','add_password','add_role','add_status'].forEach(id => { const el = document.getElementById(id); if (!el.value.trim()) { el.classList.add('is-invalid'); allValid = false; } });
    ['add_position_id','add_date_hired','add_employment_type','add_basic_salary'].forEach(id => { const el = document.getElementById(id); if (!el.value.trim()) { el.classList.add('is-invalid'); allValid = false; } });
    return allValid;
}

async function saveEmployee(e) {
    e.preventDefault();
    if (!canCreate()) { Swal.fire('Access Denied','You do not have permission to add employees','error'); return; }
    if (!validateAllSteps()) { Swal.fire('Validation Error','Please fill all required fields before submitting','warning'); return; }
    if (!validateGovIdsAndBank()) return;

    const formData = new FormData();
    formData.append('add_user_with_employee', true);
    formData.append('first_name', document.getElementById('add_first_name').value);
    formData.append('last_name',  document.getElementById('add_last_name').value);
    formData.append('email',      document.getElementById('add_email').value);
    formData.append('password',   document.getElementById('add_password').value);
    formData.append('role',       document.getElementById('add_role').value);
    formData.append('status',     document.getElementById('add_status').value);
    formData.append('position_id',      document.getElementById('add_position_id').value);
    formData.append('date_hired',       document.getElementById('add_date_hired').value);
    formData.append('employment_type',  document.getElementById('add_employment_type').value);
    formData.append('salary_frequency', document.getElementById('add_salary_frequency').value);
    formData.append('basic_salary',     document.getElementById('add_basic_salary').value);
    formData.append('salary_type',      document.getElementById('add_salary_type').value || 'Fixed');

        ['add_middle_name','add_phone_number','add_birth_date','add_gender','add_marital_status','add_address','add_date_regularized','add_emergency_contact_relationship','add_sss_number','add_philhealth_number','add_pagibig_number','add_tin_number','add_bank_name','add_bank_account_holder','add_bank_account_number','add_emergency_contact_name','add_emergency_contact_number','add_specialty','add_schedule','add_vehicle_type','add_plate_number','add_driver_license_no','add_license_expiration_date','add_vehicle_brand_model','add_or_cr_number'].forEach(id => { 
        const el = document.getElementById(id); 
        if (el) formData.append(id.replace('add_', ''), el.value || ''); 
    });
    formData.append('upload_documents_with_employee', true);

    let hasFiles = false;
    [{ input:'add_resume',type:'Resume' },{ input:'add_government_id',type:'Government ID' },{ input:'add_medical_cert',type:'Medical Certificate' },{ input:'add_clearance',type:'Police Clearance' }].forEach(fi => { const el = document.getElementById(fi.input); if (el && el.files.length > 0) { formData.append(`${fi.input}_type`, fi.type); formData.append(`${fi.input}_file`, el.files[0]); hasFiles = true; } });

    Swal.fire({ title:'Creating Employee...', text:hasFiles?'Creating and uploading documents...':'Creating employee...', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });

    try {
        const response = await fetch('api/users.php', { method:'POST', body:formData });
        const responseText = await response.text();
        let data;
        try { data = JSON.parse(responseText); } catch { throw new Error(`Server returned invalid response. Status: ${response.status}`); }
        if (!response.ok) throw new Error(data.error || `Server error: ${response.status}`);
        if (data.success) {
            Swal.close();
            
            // ✅ ADD THIS BLOCK - Assign RBAC roles after employee is created
            const roleIds = [...document.querySelectorAll('.new-emp-role-cb:checked')].map(cb => parseInt(cb.value));
            if (roleIds.length > 0 && data.user_id) {
                try {
                    const roleRes = await fetch('api/roles.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            action: 'assign_user_roles',
                            user_id: data.user_id,
                            role_ids: roleIds
                        })
                    });
                    const roleData = await roleRes.json();
                    if (roleData.success) {
                        console.log('Roles assigned successfully');
                    }
                } catch (roleErr) {
                    console.error('Role assignment error:', roleErr);
                }
            }
            
            let msg = `<div class="text-center"><i class="bi bi-check-circle text-success display-4 mb-3"></i><p>${data.message}</p><div class="alert alert-success"><strong>Employee No:</strong> ${data.employee_no}<br><strong>User Code:</strong> ${data.user_code}</div>`;
            if (roleIds.length > 0) {
                msg += `<div class="alert alert-info mt-2"><strong>${roleIds.length} RBAC role(s)</strong> assigned</div>`;
            }
            if (data.documents_uploaded > 0) msg += `<div class="alert alert-info mt-2"><strong>${data.documents_uploaded} document(s)</strong> uploaded</div>`;
            if (data.email_sent === false)   msg += `<div class="alert alert-warning mt-2">Welcome email was not sent</div>`;
            msg += `</div>`;
            await Swal.fire({ title:'Success!', html:msg, icon:'success', confirmButtonText:'OK' });
            const modal = bootstrap.Modal.getInstance(document.getElementById('addEmployeeModal'));
            if (modal) modal.hide();
            document.getElementById('addEmployeeForm').reset();
            loadEmployees(); loadPositions();
        } else { throw new Error(data.error || 'Unknown error'); }
    } catch (error) {
        Swal.close();
        let errorMessage = error.message;
        if (error.message.includes('Failed to fetch')) errorMessage = 'Cannot connect to server.';
        if (error.message.includes('invalid response')) errorMessage = 'Server returned unexpected response.';
        Swal.fire({ title:'Error!', text:errorMessage, icon:'error', confirmButtonText:'OK' });
    }
}

// ============================================================
// SAVE POSITION
// ============================================================
function savePosition(e) {
    e.preventDefault();
    const data = { add_position:true, position_name:document.getElementById('position_name').value, salary_rate:document.getElementById('salary_rate').value, department:document.getElementById('position_department').value };
    if (!data.position_name) { Swal.fire('Error!','Position name required','error'); return; }
    fetch('api/users.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) }).then(r=>r.json()).then(d=>{ if(d.success){ Swal.fire('Success!',d.message,'success'); bootstrap.Modal.getInstance(document.getElementById('addPositionModal')).hide(); loadPositions(); } else Swal.fire('Error!',d.error,'error'); });
}
function openAddPositionModal() { new bootstrap.Modal(document.getElementById('addPositionModal')).show(); }

// ============================================================
// ✅ VIEW EMPLOYEE — Fixed ID handling + real-time docs
//
// BACKGROUND: Backend does SELECT u.*, e.*
// Because employees.* comes after users.* in the SELECT,
// employees.id OVERWRITES users.id in the PHP result row.
//
// After fetch:
//   e.id      = employees.id  (NOT users.id — it got overwritten!)
//   e.user_id = employees.user_id = actual users table PK
//
// The `userId` param passed to viewEmployee() is always safe because
// renderEmployeesTable() uses `u.id` explicitly in the getAllUsers query.
// ============================================================
function viewEmployee(userId) {
    document.getElementById('viewEmployeeContent').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading...</p></div>';

    fetch(`api/users.php?user_id=${userId}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { Swal.fire('Error!', d.error, 'error'); return; }
            const e = d.data;

            // e.id      = employees.id  (use this for document queries)
            // userId    = users.id      (the param — safe for editEmployee)
            const empDocId   = e.id;     // employees.id — for getDocuments
            const realUserId = userId;   // users.id — for editEmployee fetch

            _currentViewUserId   = realUserId;
            _currentViewEmpDocId = empDocId;

            const fmtGov = n => (!n || n === 'null' || n === '') ? 'Not provided' : n;
            const fmtSalary = () => {
                if (!e.basic_salary) return 'Not set';
                let s = `₱${parseFloat(e.basic_salary).toLocaleString()}`;
                if (e.salary_frequency) { const fm = {'15days':'Every 15 Days','30days':'Every 30 Days','monthly':'Monthly'}; s += ` (${fm[e.salary_frequency]||e.salary_frequency})`; }
                if (e.salary_type && e.salary_type !== 'Fixed') s += ` - ${e.salary_type}`;
                return s;
            };

            loadEmployeeDocuments(empDocId).then(docsHTML => {
                document.getElementById('viewEmployeeContent').innerHTML = `
                <div style="max-height:70vh;overflow-y:auto;padding-right:15px;">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width:100px;height:100px;font-size:2.5rem">${e.first_name?.charAt(0)||''}${e.last_name?.charAt(0)||''}</div>
                            <h4>${e.first_name} ${e.last_name}</h4>
                            <span class="badge ${getEmploymentBadgeClass(e.employment_type)}">${e.employment_type||'N/A'}</span>
                            <span class="badge ${getStatusBadgeClass(e.status||e.employment_status)}">${e.status||e.employment_status||'N/A'}</span><br>
                            <span class="badge bg-info mt-1">${e.user_code||''}</span><br>
                            <small class="text-muted">${e.employee_no||''}</small>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="border-bottom pb-2 mb-3">Basic Information</h6>
                                    ${[['Email',e.email],['Phone',e.phone_number||'Not provided'],['Role',e.role],['Date Hired',formatDate(e.date_hired,true)],['Birth Date',formatDate(e.birth_date,true)||'Not provided'],['Gender',e.gender||'Not provided'],['Marital Status',e.marital_status||'Not provided']].map(([k,v])=>`<div class="row mb-2"><div class="col-4"><small class="text-muted">${k}:</small></div><div class="col-8"><div class="fw-bold">${v}</div></div></div>`).join('')}
                                </div>
                                <div class="col-md-6">
                                    <h6 class="border-bottom pb-2 mb-3">Employment Details</h6>
                                    ${[['Position',e.position_name||'Not set'],['Department',e.department||'N/A'],['Salary',fmtSalary()],['Emp. Type',e.employment_type||'N/A'],['Date Regularized',formatDate(e.date_regularized,true)||'Not regularized']].map(([k,v])=>`<div class="row mb-2"><div class="col-5"><small class="text-muted">${k}:</small></div><div class="col-7"><div class="fw-bold">${v}</div></div></div>`).join('')}
                                </div>
                            </div>
                            <h6 class="border-bottom pb-2 mb-3 mt-3">Government IDs</h6>
                            <div class="row">${[['SSS',fmtGov(e.sss_number)],['PhilHealth',fmtGov(e.philhealth_number)],['Pag-IBIG',fmtGov(e.pagibig_number)],['TIN',fmtGov(e.tin_number)]].map(([k,v])=>`<div class="col-md-6 mb-2"><small class="text-muted">${k}:</small><div class="fw-bold">${v}</div></div>`).join('')}</div>
                            <div class="row mt-3">
                                <div class="col-md-6"><h6 class="border-bottom pb-2 mb-3">Bank Information</h6>${[['Bank',e.bank_name||'Not provided'],['Holder',e.bank_account_holder||'Not provided'],['Account',e.bank_account_number||'Not provided']].map(([k,v])=>`<div class="mb-2"><small class="text-muted">${k}:</small><div class="fw-bold">${v}</div></div>`).join('')}</div>
                                <div class="col-md-6"><h6 class="border-bottom pb-2 mb-3">Emergency Contact</h6>${[['Name',e.emergency_contact_name||'Not provided'],['Relationship',e.emergency_contact_relationship||'Not provided'],['Number',e.emergency_contact_number||'Not provided']].map(([k,v])=>`<div class="mb-2"><small class="text-muted">${k}:</small><div class="fw-bold">${v}</div></div>`).join('')}</div>
                            </div>
                            <h6 class="border-bottom pb-2 mb-3 mt-3">Uploaded Documents</h6>
                            <!-- ✅ Stable ID — target for real-time refresh -->
                            <div id="viewDocsContainer">${docsHTML}</div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-upload me-2"></i>Upload New Document</h6></div>
                        <div class="card-body">
                            <form id="viewEmployeeDocumentForm">
                                <!-- ✅ TWO hidden fields: empDocId for upload, realUserId for edit button -->
                                <input type="hidden" id="view_doc_emp_id"  value="${empDocId}">
                                <input type="hidden" id="view_doc_user_id" value="${realUserId}">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Document Type</label>
                                        <select class="form-select" id="view_document_type" required>
                                            <option value="">Select Type</option>
                                            <option value="Resume">Resume / CV</option>
                                            <option value="Employment Contract">Employment Contract</option>
                                            <option value="SSS ID">SSS ID</option>
                                            <option value="PhilHealth ID">PhilHealth ID</option>
                                            <option value="Pag-IBIG ID">Pag-IBIG ID</option>
                                            <option value="TIN ID">TIN ID</option>
                                            <option value="Diploma">Diploma</option>
                                            <option value="Certificate">Certificate</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Select File</label>
                                        <input type="file" class="form-control" id="view_document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                                        <div class="form-text">PDF, Images, Word (Max: 10MB)</div>
                                    </div>
                                    <div class="col-md-2 mb-3 d-flex align-items-end">
                                        <button type="button" class="btn btn-primary w-100" onclick="uploadDocumentFromView()">
                                            <i class="bi bi-upload me-1"></i> Upload
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="text-center mt-4 mb-3">
                        <!-- ✅ Pass realUserId (users.id) to editEmployee — NOT empDocId -->
                        <button class="btn btn-primary me-2" onclick="editEmployee(${realUserId})">
                            <i class="bi bi-pencil me-2"></i>Edit Employee
                        </button>
                        <button class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Close
                        </button>
                    </div>
                </div>`;

                if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
                const modalEl = document.getElementById('viewEmployeeModal');
                let modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (!modalInstance) modalInstance = new bootstrap.Modal(modalEl, { backdrop:'static', keyboard:true });
                modalEl.addEventListener('hide.bs.modal', () => { if (document.activeElement && modalEl.contains(document.activeElement)) document.activeElement.blur(); }, { once:true });
                modalInstance.show();
                modalEl.addEventListener('shown.bs.modal', () => { const cb = modalEl.querySelector('[data-bs-dismiss="modal"]'); if (cb) cb.focus(); }, { once:true });
            });
        })
        .catch(() => Swal.fire('Error!', 'Failed to load employee data', 'error'));
}

// ============================================================
// DOCUMENTS
// ============================================================
function loadEmployeeDocuments(employeeId) {
    return fetch(`api/users.php?get_documents=${employeeId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.documents || data.documents.length === 0) {
                return `<div class="text-center py-4"><i class="bi bi-file-earmark-text display-4 text-muted mb-3"></i><p class="text-muted">No documents uploaded yet</p></div>`;
            }
            const san = s => (!s ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'));
            return `
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Type</th><th>Document</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        ${data.documents.map(doc => {
                            const dType = san(doc.document_type), dName = san(doc.document_name);
                            const fUrl  = san(doc.file_url_correct || doc.file_url_relative);
                            const isImg = /\.(jpg|jpeg|png|gif|webp|bmp)$/i.test(doc.document_name);
                            let safeUrl = '#';
                            try { const p = new URL(fUrl, window.location.origin); if (p.protocol === 'http:' || p.protocol === 'https:') safeUrl = fUrl; } catch {}
                            return `<tr>
                                <td>${dType}</td>
                                <td><div class="d-flex align-items-center">${isImg ? `<img src="${safeUrl}" class="rounded me-3" style="width:50px;height:50px;object-fit:cover;" onerror="this.style.display='none'">` : `<i class="bi ${getFileIcon(doc.document_name)} me-3 fs-4"></i>`}<div><div class="fw-bold">${dName}</div>${doc.file_exists === false ? `<small class="text-danger">File missing</small>` : ''}</div></div></td>
                                <td><small class="text-muted">${san(doc.file_size_formatted)||'N/A'}</small></td>
                                <td><small class="text-muted">${san(doc.upload_date_formatted)||'N/A'}</small></td>
                                <td><div class="btn-group btn-group-sm">
                                    ${isImg ? `<button class="btn btn-outline-primary" onclick="previewImage('${safeUrl.replace(/'/g,"\\'")}','${dName.replace(/'/g,"\\'")}')"><i class="bi bi-eye"></i></button>` : `<a href="${safeUrl}" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer" ${safeUrl==='#'?'onclick="return false;"':''}><i class="bi bi-download"></i></a>`}
                                    <button class="btn btn-outline-danger" onclick="deleteDocument(${parseInt(doc.id)},${parseInt(employeeId)})"><i class="bi bi-trash"></i></button>
                                </div></td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
            <div class="alert alert-info mt-2"><i class="bi bi-info-circle me-2"></i><strong>${data.documents.length} document(s)</strong> found.</div>`;
        })
        .catch(err => `<div class="alert alert-danger">Error loading documents: ${err.message}</div>`);
}

// ✅ Refresh #viewDocsContainer in place — no page reload
function refreshViewDocs(empDocId) {
    const id = empDocId || document.getElementById('view_doc_emp_id')?.value || _currentViewEmpDocId;
    if (!id) return;
    const container = document.getElementById('viewDocsContainer');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Refreshing...</div>';
    loadEmployeeDocuments(id).then(html => { container.innerHTML = html; });
}

function previewImage(imageUrl, imageName) {
    const modalEl = document.getElementById('viewEmployeeModal'), modal = bootstrap.Modal.getInstance(modalEl);
    document.activeElement?.blur();
    if (modal) modal.hide();
    Swal.fire({ title:imageName, imageUrl, showCloseButton:true, showConfirmButton:false, width:'80%', didClose:()=>{ if(modal) modal.show(); } });
}

// ✅ Fixed upload — uses view_doc_emp_id (employees.id), refreshes #viewDocsContainer
function uploadDocumentFromView() {
    if (!canManageDocuments()) { Swal.fire('Access Denied','No permission to upload','error'); return; }

    const empId   = document.getElementById('view_doc_emp_id')?.value || _currentViewEmpDocId;
    const docType = document.getElementById('view_document_type')?.value;
    const fileEl  = document.getElementById('view_document');

    if (!empId)                              { Swal.fire('Error!','Employee ID missing. Please close and reopen.','error'); return; }
    if (!docType)                            { Swal.fire('Error!','Please select document type','error'); return; }
    if (!fileEl || fileEl.files.length === 0) { Swal.fire('Error!','Please select a file','error'); return; }

    const formData = new FormData();
    formData.append('employee_id',   empId);
    formData.append('document_type', docType);
    formData.append('document',      fileEl.files[0]);

    Swal.fire({ title:'Uploading...', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });

    fetch('api/users.php', { method:'POST', body:formData })
        .then(async r => {
            const text = await r.text();
            let d; try { d = JSON.parse(text); } catch { throw new Error('Server returned invalid response.'); }
            if (!r.ok) throw new Error(d.error || 'Upload failed');
            return d;
        })
        .then(d => {
            Swal.close();
            if (d.success) {
                // ✅ Refresh in place — no page reload
                refreshViewDocs(empId);
                document.getElementById('viewEmployeeDocumentForm')?.reset();
                Swal.fire({ icon:'success', title:'Uploaded!', text:d.message, timer:1800, showConfirmButton:false });
            } else { throw new Error(d.error || 'Upload failed'); }
        })
        .catch(err => { Swal.close(); Swal.fire('Error!', err.message, 'error'); });
}

// ✅ Fixed delete — refreshes #viewDocsContainer in place
function deleteDocument(documentId, employeeId) {
    if (!canManageDocuments()) { Swal.fire('Access Denied','No permission to delete documents','error'); return; }
    Swal.fire({ title:'Are you sure?', text:'This document will be permanently deleted!', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete it!' })
        .then(result => {
            if (!result.isConfirmed) return;
            fetch('api/users.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ delete_document:true, document_id:documentId }) })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon:'success', title:'Deleted!', text:data.message, timer:1500, showConfirmButton:false });
                        refreshViewDocs(employeeId || _currentViewEmpDocId);
                    } else { Swal.fire('Error!', data.error, 'error'); }
                })
                .catch(() => Swal.fire('Error!','Failed to delete document','error'));
        });
}

// ============================================================
// DELETE EMPLOYEE
// ============================================================
function deleteEmployee(id) {
    if (!canDelete()) { Swal.fire('Access Denied','No permission to delete employees','error'); return; }
    Swal.fire({ title:'Are you sure?', text:'All employee records will be permanently deleted.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Yes, delete permanently!' })
        .then(result => {
            if (!result.isConfirmed) return;
            Swal.fire({ title:'Deleting...', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });
            fetch('api/users.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ delete_employee_id:id }) })
                .then(r => r.json())
                .then(data => { Swal.close(); if (data.success) { Swal.fire('Deleted!', data.message, 'success'); loadEmployees(); } else Swal.fire('Error!', data.error, 'error'); })
                .catch(() => { Swal.close(); Swal.fire('Error!','Failed to delete employee','error'); });
        });
}

// ============================================================
// ✅ EDIT EMPLOYEE — receives users.id
// ============================================================
function editEmployee(userId) {
    if (!canUpdate()) { Swal.fire('Access Denied','No permission to edit employees','error'); return; }

    fetch(`api/users.php?user_id=${userId}`).then(r => r.json()).then(d => {
        if (!d.success) { Swal.fire('Error!', d.error, 'error'); return; }
        const e = d.data;

        // e.id      = employees.id  (for employee_id field in updateEmployeeInfo)
        // userId    = users.id      (for user_id field in updateEmployeeInfo)
        const empRecordId  = e.id;
        const userRecordId = userId;

        document.getElementById('editEmployeeContent').innerHTML = `
            <div style="max-height:70vh;overflow-y:auto;padding-right:10px;">
                <form id="editEmployeeForm">
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_first_name" value="${e.first_name||''}" required></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_last_name" value="${e.last_name||''}" required></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Middle Name</label><input type="text" class="form-control" id="edit_middle_name" value="${e.middle_name||''}"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="edit_email" value="${e.email||''}" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Phone Number</label><input type="tel" class="form-control" id="edit_phone_number" value="${e.phone_number||''}" placeholder="09XX-XXX-XXXX" oninput="formatPhoneNumber(this)" onkeydown="blockNonDigits(event)"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="form-label">Birth Date</label><input type="date" class="form-control" id="edit_birth_date" value="${e.birth_date||''}"></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Gender</label><select class="form-select" id="edit_gender"><option value="">Select</option><option value="Male" ${e.gender=='Male'?'selected':''}>Male</option><option value="Female" ${e.gender=='Female'?'selected':''}>Female</option><option value="Other" ${e.gender=='Other'?'selected':''}>Other</option></select></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Marital Status</label><select class="form-select" id="edit_marital_status"><option value="">Select</option><option value="Single" ${e.marital_status=='Single'?'selected':''}>Single</option><option value="Married" ${e.marital_status=='Married'?'selected':''}>Married</option><option value="Widowed" ${e.marital_status=='Widowed'?'selected':''}>Widowed</option><option value="Separated" ${e.marital_status=='Separated'?'selected':''}>Separated</option></select></div>
                    </div>
                    <div class="row"><div class="col-12 mb-3"><label class="form-label">Address</label><textarea class="form-control" id="edit_address" rows="2">${e.address||''}</textarea></div></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Role <span class="text-danger">*</span></label><select class="form-select" id="edit_role" required><option value="">Select</option><option value="Optometrist" ${e.role=='Optometrist'?'selected':''}>Optometrist</option><option value="Staff" ${e.role=='Staff'?'selected':''}>Staff</option><option value="HR" ${e.role=='HR'?'selected':''}>HR</option><option value="Finance" ${e.role=='Finance'?'selected':''}>Finance</option><option value="CRM" ${e.role=='CRM'?'selected':''}>CRM</option><option value="SCM" ${e.role=='SCM'?'selected':''}>SCM</option><option value="Rider" ${e.role=='Rider'?'selected':''}>Rider</option></select></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Status <span class="text-danger">*</span></label><select class="form-select" id="edit_status" required><option value="Active" ${e.status=='Active'?'selected':''}>Active</option><option value="On-Leave" ${e.status=='On-Leave'?'selected':''}>On Leave</option><option value="Resigned" ${e.status=='Resigned'?'selected':''}>Resigned</option><option value="Terminated" ${e.status=='Terminated'?'selected':''}>Terminated</option><option value="Inactive" ${e.status=='Inactive'?'selected':''}>Inactive</option></select></div>
                    </div>

${e.role === 'Rider' ? `
<h6 class="border-bottom pb-2 mb-3 mt-3"><i class="bi bi-bicycle me-2"></i>Rider Details</h6>
<div class="row" id="editRiderFields">
    <div class="col-md-6 mb-3">
        <label class="form-label">Driver's License No.</label>
        <input type="text" class="form-control" id="edit_driver_license_no" value="${e.rider_driver_license_no||''}" maxlength="50" placeholder="e.g. N01-23-456789">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">License Expiration Date</label>
        <input type="date" class="form-control" id="edit_license_expiration_date" value="${e.rider_license_expiration_date||''}">
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Vehicle Type</label>
        <select class="form-select" id="edit_vehicle_type">
            <option value="">Select Vehicle</option>
            <option value="Motorcycle" ${e.rider_vehicle_type=='Motorcycle'?'selected':''}>Motorcycle</option>
            <option value="Car" ${e.rider_vehicle_type=='Car'?'selected':''}>Car</option>
            <option value="Bike" ${e.rider_vehicle_type=='Bike'?'selected':''}>Bicycle</option>
            <option value="Van" ${e.rider_vehicle_type=='Van'?'selected':''}>Van</option>
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Vehicle Brand &amp; Model</label>
        <input type="text" class="form-control" id="edit_vehicle_brand_model" value="${e.rider_vehicle_brand_model||''}" maxlength="100" placeholder="e.g. Honda Click 125i">
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Plate Number</label>
        <input type="text" class="form-control" id="edit_plate_number" value="${e.rider_plate_number||''}" maxlength="20" placeholder="e.g. ABC-1234">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">OR/CR Number</label>
        <input type="text" class="form-control" id="edit_or_cr_number" value="${e.rider_or_cr_number||''}" maxlength="50" placeholder="e.g. 1234567890">
    </div>
</div>` : ''}

                    <h6 class="border-bottom pb-2 mb-3 mt-3">Employment Details</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Position <span class="text-danger">*</span></label><select class="form-select" id="edit_position_id" required><option value="">Select Position</option>${positionsData.map(p=>`<option value="${p.id}" ${e.position_id==p.id?'selected':''}>${p.position_name}</option>`).join('')}</select></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Employment Type <span class="text-danger">*</span></label><select class="form-select" id="edit_employment_type" required><option value="">Select</option><option value="Regular" ${e.employment_type=='Regular'?'selected':''}>Regular</option><option value="Probationary" ${e.employment_type=='Probationary'?'selected':''}>Probationary</option><option value="Contractual" ${e.employment_type=='Contractual'?'selected':''}>Contractual</option><option value="Part-time" ${e.employment_type=='Part-time'?'selected':''}>Part-time</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Date Hired <span class="text-danger">*</span></label><input type="date" class="form-control" id="edit_date_hired" value="${e.date_hired||''}" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Date Regularized</label><input type="date" class="form-control" id="edit_date_regularized" value="${e.date_regularized||''}"></div>
                    </div>
                    <h6 class="border-bottom pb-2 mb-3 mt-3">Salary Configuration</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Basic Salary (₱) <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="edit_basic_salary" value="${e.basic_salary||''}" step="0.01" min="0" required></div><div class="form-text">Current: ₱${parseFloat(e.basic_salary||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Salary Type <span class="text-danger">*</span></label><select class="form-select" id="edit_salary_type" required><option value="">Select</option><option value="Fixed" ${(!e.salary_type||e.salary_type=='Fixed')?'selected':''}>Fixed</option><option value="Hourly" ${e.salary_type=='Hourly'?'selected':''}>Hourly Rate</option><option value="Commission" ${e.salary_type=='Commission'?'selected':''}>Commission-based</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Salary Frequency <span class="text-danger">*</span></label><select class="form-select" id="edit_salary_frequency" required><option value="">Select</option><option value="15days" ${e.salary_frequency=='15days'?'selected':''}>Every 15 Days</option><option value="30days" ${e.salary_frequency=='30days'?'selected':''}>Every 30 Days</option><option value="monthly" ${e.salary_frequency=='monthly'?'selected':''}>Monthly</option></select></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Salary Change Reason</label><input type="text" class="form-control" id="edit_salary_change_reason" placeholder="Required if changing salary"><div class="form-text">Required only if changing salary</div></div>
                    </div>
                    <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Changing salary creates a history record.</div>
                    <h6 class="border-bottom pb-2 mb-3 mt-3">Government IDs</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">SSS Number</label><input type="text" class="form-control" id="edit_sss_number" value="${e.sss_number||''}" placeholder="XX-XXXXXXX-X" maxlength="12" oninput="formatSSS(this)" onkeydown="blockNonDigits(event)" onpaste="setTimeout(()=>formatSSS(this),0)"><div class="form-text text-muted">10 digits</div><div class="invalid-feedback"></div></div>
                        <div class="col-md-6 mb-3"><label class="form-label">PhilHealth Number</label><input type="text" class="form-control" id="edit_philhealth_number" value="${e.philhealth_number||''}" placeholder="XX-XXXXXXXXX-X" maxlength="14" oninput="formatPhilHealth(this)" onkeydown="blockNonDigits(event)" onpaste="setTimeout(()=>formatPhilHealth(this),0)"><div class="form-text text-muted">12 digits</div><div class="invalid-feedback"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Pag-IBIG Number</label><input type="text" class="form-control" id="edit_pagibig_number" value="${e.pagibig_number||''}" placeholder="XXXX-XXXX-XXXX" maxlength="14" oninput="formatPagIbig(this)" onkeydown="blockNonDigits(event)" onpaste="setTimeout(()=>formatPagIbig(this),0)"><div class="form-text text-muted">12 digits</div><div class="invalid-feedback"></div></div>
                        <div class="col-md-6 mb-3"><label class="form-label">TIN Number</label><input type="text" class="form-control" id="edit_tin_number" value="${e.tin_number||''}" placeholder="XXX-XXX-XXX" maxlength="15" oninput="formatTIN(this)" onkeydown="blockNonDigits(event)" onpaste="setTimeout(()=>formatTIN(this),0)"><div class="form-text text-muted">9 or 12 digits</div><div class="invalid-feedback"></div></div>
                    </div>
                    <h6 class="border-bottom pb-2 mb-3 mt-3">Bank Information</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="form-label">Bank Name</label><select class="form-select" id="edit_bank_name" onchange="updateEditBankHint()"><option value="">Select Bank</option><option value="BDO" ${e.bank_name=='BDO'?'selected':''}>BDO Unibank</option><option value="BPI" ${e.bank_name=='BPI'?'selected':''}>BPI</option><option value="Metrobank" ${e.bank_name=='Metrobank'?'selected':''}>Metrobank</option><option value="Other" ${e.bank_name&&!['BDO','BPI','Metrobank'].includes(e.bank_name)?'selected':''}>Other Bank</option></select></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Account Holder</label><input type="text" class="form-control" id="edit_bank_account_holder" value="${e.bank_account_holder||''}"></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Account Number</label><input type="text" class="form-control" id="edit_bank_account_number" value="${e.bank_account_number||''}" maxlength="16" oninput="formatEditBankAccount(this)" onkeydown="blockNonDigits(event)" onpaste="setTimeout(()=>formatEditBankAccount(this),0)"><div class="form-text text-muted" id="edit_bank_account_hint">Digits only</div><div class="invalid-feedback" id="edit_bank_account_error"></div></div>
                    </div>
                    <h6 class="border-bottom pb-2 mb-3 mt-3">Emergency Contact</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3"><label class="form-label">Contact Name</label><input type="text" class="form-control" id="edit_emergency_contact_name" value="${e.emergency_contact_name||''}"></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Contact Number</label><input type="tel" class="form-control" id="edit_emergency_contact_number" value="${e.emergency_contact_number||''}" placeholder="09XX-XXX-XXXX" maxlength="13" oninput="formatPhoneNumber(this)" onkeydown="blockNonDigits(event)"></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Relationship</label><select class="form-select" id="edit_emergency_contact_relationship"><option value="">Select</option><option value="Spouse" ${e.emergency_contact_relationship=='Spouse'?'selected':''}>Spouse</option><option value="Parent" ${e.emergency_contact_relationship=='Parent'?'selected':''}>Parent</option><option value="Sibling" ${e.emergency_contact_relationship=='Sibling'?'selected':''}>Sibling</option><option value="Child" ${e.emergency_contact_relationship=='Child'?'selected':''}>Child</option><option value="Friend" ${e.emergency_contact_relationship=='Friend'?'selected':''}>Friend</option><option value="Relative" ${e.emergency_contact_relationship=='Relative'?'selected':''}>Relative</option><option value="Other" ${e.emergency_contact_relationship=='Other'?'selected':''}>Other</option></select></div>
                    </div>
                    <!-- ✅ Correct IDs: empRecordId = employees.id, userRecordId = users.id -->
                    <input type="hidden" id="edit_employee_id" value="${empRecordId}">
                    <input type="hidden" id="edit_user_id"     value="${userRecordId}">
                    <input type="hidden" id="edit_old_salary"  value="${e.basic_salary||0}">
                    <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-secondary me-3" data-bs-dismiss="modal"><i class="bi bi-x-circle me-2"></i>Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-2"></i>Update Employee</button>
                    </div>
                </form>
            </div>`;

        if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
        const modalEl = document.getElementById('editEmployeeModal');
        const modal   = new bootstrap.Modal(modalEl);

        setTimeout(() => {
            ['edit_sss_number','edit_philhealth_number','edit_pagibig_number','edit_tin_number'].forEach(id => {
                const el = document.getElementById(id); if (!el || !el.value) return;
                if (id === 'edit_sss_number')       formatSSS(el);
                if (id === 'edit_philhealth_number') formatPhilHealth(el);
                if (id === 'edit_pagibig_number')    formatPagIbig(el);
                if (id === 'edit_tin_number')        formatTIN(el);
            });
            updateEditBankHint();
        }, 150);

        document.getElementById('editEmployeeForm').addEventListener('submit', updateEmployeeInfo);
        modal.show();
    }).catch(() => Swal.fire('Error!','Failed to load employee data','error'));
}

function updateEditBankHint() {
    const bankEl = document.getElementById('edit_bank_name'), hint = document.getElementById('edit_bank_account_hint'), input = document.getElementById('edit_bank_account_number');
    if (!bankEl || !hint || !input) return;
    const rules = bankAccountRules[bankEl.value];
    if (rules) { hint.textContent = rules.hint; input.maxLength = rules.max; } else { hint.textContent = 'Digits only'; input.maxLength = 16; }
    if (input.value) _validateEditBank(input);
}
function formatEditBankAccount(input) { input.value = digitsOnly(input.value); _validateEditBank(input); }

// ============================================================
// UPDATE EMPLOYEE
// ============================================================
function updateEmployeeInfo(e) {
    e.preventDefault();
    if (!validateEditGovIdsAndBank()) return;

    const oldSalary          = parseFloat(document.getElementById('edit_old_salary').value) || 0;
    const newSalary          = parseFloat(document.getElementById('edit_basic_salary').value) || 0;
    const salaryChangeReason = document.getElementById('edit_salary_change_reason').value;

    if (newSalary !== oldSalary && !salaryChangeReason) { Swal.fire('Warning!','Please provide a reason for the salary change','warning'); return; }

    const data = {
        update_employee_info: true,
        employee_id:    document.getElementById('edit_employee_id').value,
        user_id:        document.getElementById('edit_user_id').value,
        first_name:     document.getElementById('edit_first_name').value,
        last_name:      document.getElementById('edit_last_name').value,
        middle_name:    document.getElementById('edit_middle_name').value || null,
        email:          document.getElementById('edit_email').value,
        phone_number:   document.getElementById('edit_phone_number').value || null,
        birth_date:     document.getElementById('edit_birth_date').value || null,
        gender:         document.getElementById('edit_gender').value || null,
        marital_status: document.getElementById('edit_marital_status').value || null,
        address:        document.getElementById('edit_address').value || null,
        role:           document.getElementById('edit_role').value,
        position_id:    document.getElementById('edit_position_id').value,
        employment_type: document.getElementById('edit_employment_type').value,
        date_hired:     document.getElementById('edit_date_hired').value,
        date_regularized: document.getElementById('edit_date_regularized').value || null,
        basic_salary:   newSalary,
        salary_frequency: document.getElementById('edit_salary_frequency').value,
        salary_type:    document.getElementById('edit_salary_type').value,
        status:         document.getElementById('edit_status').value,
        sss_number:     document.getElementById('edit_sss_number').value || null,
        philhealth_number: document.getElementById('edit_philhealth_number').value || null,
        pagibig_number: document.getElementById('edit_pagibig_number').value || null,
        tin_number:     document.getElementById('edit_tin_number').value || null,
        bank_name:      document.getElementById('edit_bank_name').value || null,
        bank_account_holder: document.getElementById('edit_bank_account_holder').value || null,
        bank_account_number: document.getElementById('edit_bank_account_number').value || null,
        emergency_contact_name:         document.getElementById('edit_emergency_contact_name').value || null,
        emergency_contact_number:       document.getElementById('edit_emergency_contact_number').value || null,
        emergency_contact_relationship: document.getElementById('edit_emergency_contact_relationship').value || null,
        vehicle_type:    document.getElementById('edit_vehicle_type')?.value || null,
        plate_number:    document.getElementById('edit_plate_number')?.value || null,
        driver_license_no:       document.getElementById('edit_driver_license_no')?.value || null,
        license_expiration_date: document.getElementById('edit_license_expiration_date')?.value || null,
        vehicle_brand_model:     document.getElementById('edit_vehicle_brand_model')?.value || null,
        or_cr_number:            document.getElementById('edit_or_cr_number')?.value || null
    };

    if (newSalary !== oldSalary) { data.salary_change_reason = salaryChangeReason; data.old_salary = oldSalary; }

    const required = ['first_name','last_name','email','role','position_id','employment_type','date_hired','basic_salary','salary_frequency','salary_type','status'];
    for (const field of required) { if (!data[field]) { Swal.fire('Error!',`Missing: ${field.replace(/_/g,' ')}`,'error'); return; } }

    Swal.fire({ title:'Updating Employee...', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });
    fetch('api/users.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) })
        .then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.error||'Server error'); return d; })
        .then(d => {
            Swal.close();
            if (d.success) {
                const msg = newSalary !== oldSalary ? `Updated. Salary changed from ₱${oldSalary.toLocaleString('en-PH',{minimumFractionDigits:2})} to ₱${newSalary.toLocaleString('en-PH',{minimumFractionDigits:2})}.` : d.message;
                Swal.fire('Success!', msg, 'success');
                bootstrap.Modal.getInstance(document.getElementById('editEmployeeModal')).hide();
                loadEmployees(); loadPositions();
            } else throw new Error(d.error);
        })
        .catch(err => { Swal.close(); Swal.fire('Error!', err.message, 'error'); });
}

// ============================================================
// OTHER FUNCTIONS
// ============================================================
function toggleEmployeeStatus(id) {
    Swal.fire({ title:'Confirm', text:'Change employee status?', icon:'warning', showCancelButton:true, confirmButtonText:'Yes' })
        .then(r => { if (r.isConfirmed) fetch('api/users.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ toggle_status_id:id }) }).then(r=>r.json()).then(d=>{ if(d.success){ Swal.fire('Success!',d.message,'success'); loadEmployees(); } else Swal.fire('Error!',d.error,'error'); }); });
}

function filterEmployees() {
    const position = document.getElementById('positionFilter').value, status = document.getElementById('statusFilter').value, type = document.getElementById('employmentTypeFilter').value;
    let filtered = employeesData;
    if (position) filtered = filtered.filter(e => e.position_id == position);
    if (status)   filtered = filtered.filter(e => e.employment_status === status || e.user_status === status);
    if (type)     filtered = filtered.filter(e => e.employment_type === type);
    renderFilteredEmployees(filtered);
}

function renderFilteredEmployees(emps) {
    const canViewSalaryEmp = canViewSalary();
    const canAssignRoles   = (currentUserRole === 'ClinicAdmin' || isOwner);
 
    document.getElementById('employeesTableBody').innerHTML = emps.map(emp => {
        const fullName = `${emp.first_name} ${emp.last_name}`;
 
        let actionButtons = '';
 
        actionButtons += `<button class="btn btn-outline-info btn-sm" onclick="viewEmployee(${emp.id})">
            <i class="bi bi-eye"></i></button>`;
 
        if (canUpdate())
            actionButtons += `<button class="btn btn-outline-primary btn-sm" onclick="editEmployee(${emp.id})">
                <i class="bi bi-pencil"></i></button>`;
 
        // ✅ NEW — Assign Roles
        if (canAssignRoles)
            actionButtons += `<button class="btn btn-outline-secondary btn-sm"
                onclick="openAssignRolesModal(${emp.id}, '${fullName.replace(/'/g,"\\'")}', '${(emp.role||'').replace(/'/g,"\\'")}', '${((emp.first_name?.charAt(0)||'')+(emp.last_name?.charAt(0)||'')).toUpperCase()}')"
                title="Assign Access Roles">
                <i class="bi bi-shield-lock"></i></button>`;
 
        const salaryDisplay = canViewSalaryEmp
            ? (emp.basic_salary ? '₱' + parseFloat(emp.basic_salary).toLocaleString() : 'N/A')
            : '*** Hidden ***';
 
        return `
        <tr>
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                         style="width:40px;height:40px;font-weight:bold;font-size:14px">
                        ${(emp.first_name?.charAt(0)||'')+(emp.last_name?.charAt(0)||'')}
                    </div>
                    <div>
                        <div class="fw-bold">${emp.first_name} ${emp.last_name}</div>
                        <small class="text-muted">${emp.email}</small>
                    </div>
                </div>
            </td>
            <td><div class="fw-bold">${emp.position_name || 'Not assigned'}</div></td>
            <td><span class="badge ${getEmploymentBadgeClass(emp.employment_type)}">${emp.employment_type || 'N/A'}</span></td>
            <td><div class="fw-bold text-success">${salaryDisplay}</div></td>
            <td><span class="badge ${getStatusBadgeClass(emp.employment_status || emp.user_status)}">${emp.employment_status || emp.user_status}</span></td>
            <td><div class="btn-group btn-group-sm">${actionButtons}</div></td>
        </tr>`;
    }).join('');
}

async function openAssignRolesModal(userId, userName, userRole, initials) {
    // Set header info
    document.getElementById('assign_user_id').value         = userId;
    document.getElementById('assign_user_name').textContent = userName;
    document.getElementById('assign_user_role').textContent = userRole || 'Employee';
    document.getElementById('assign_user_avatar').textContent = initials || '??';
 
    // Reset UI
    document.getElementById('assignRolesLoading').style.display = 'block';
    document.getElementById('assignRolesList').style.removeProperty('display');
    document.getElementById('assignRolesList').innerHTML = '';
    document.getElementById('assignRolesEmpty').style.display = 'none';
 
    new bootstrap.Modal(document.getElementById('assignRolesModal')).show();
 
    try {
        // Load all roles + current user roles in parallel
        const [rolesRes, userRolesRes] = await Promise.all([
            fetch('api/roles.php?action=get_roles').then(r => r.json()),
            fetch(`api/roles.php?action=get_user_roles&user_id=${userId}`).then(r => r.json())
        ]);
 
        document.getElementById('assignRolesLoading').style.display = 'none';
 
        if (!rolesRes.success) throw new Error(rolesRes.error || 'Failed to load roles');
 
        const allRoles      = rolesRes.roles || [];
        const currentRoleIds = new Set((userRolesRes.roles || []).map(r => Number(r.id)));
 
        if (!allRoles.length) {
            document.getElementById('assignRolesEmpty').style.display = 'block';
            document.getElementById('saveRolesBtn').disabled = true;
            return;
        }
 
        document.getElementById('saveRolesBtn').disabled = false;
 
        document.getElementById('assignRolesList').innerHTML = allRoles.map(role => `
            <div class="form-check p-3 border rounded" style="cursor:pointer"
                 onclick="this.querySelector('input').click()">
                <input class="form-check-input assign-role-cb"
                       type="checkbox"
                       value="${role.id}"
                       id="assign_role_${role.id}"
                       ${currentRoleIds.has(Number(role.id)) ? 'checked' : ''}
                       onclick="event.stopPropagation()">
                <label class="form-check-label w-100" for="assign_role_${role.id}" style="cursor:pointer">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">${esc(role.role_name)}</span>
                        <span class="badge bg-primary">${role.permission_count} permissions</span>
                    </div>
                    <small class="text-muted">${esc(role.description || 'No description')}</small>
                </label>
            </div>
        `).join('');
 
    } catch (e) {
        document.getElementById('assignRolesLoading').style.display = 'none';
        document.getElementById('assignRolesList').innerHTML =
            `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${e.message}</div>`;
    }
}
 
 
// ── saveUserRoles — i-save ang selected roles ─────────────────
async function saveUserRoles() {
    const userId  = parseInt(document.getElementById('assign_user_id').value);
    const roleIds = [...document.querySelectorAll('.assign-role-cb:checked')].map(cb => parseInt(cb.value));
 
    Swal.fire({
        title: 'Saving roles...',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });
 
    try {
        const res  = await fetch('api/roles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:   'assign_user_roles',
                user_id:  userId,
                role_ids: roleIds
            })
        });
        const data = await res.json();
 
        Swal.close();
 
        if (!data.success) throw new Error(data.error || 'Failed to save roles');
 
        bootstrap.Modal.getInstance(document.getElementById('assignRolesModal')).hide();
 
        Swal.fire({
            icon:  'success',
            title: 'Roles assigned!',
            html:  roleIds.length
                ? `<strong>${roleIds.length} role(s)</strong> assigned successfully.`
                : 'All roles removed from this employee.',
            timer: 1800,
            showConfirmButton: false
        });
 
    } catch (e) {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Error', text: e.message });
    }
}
 
 
// ── Helper: HTML escape ───────────────────────────────────────
function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function searchEmployees() {
    const term = document.getElementById('searchEmployees').value.toLowerCase();
    const filtered = employeesData.filter(e => e.first_name.toLowerCase().includes(term) || e.last_name.toLowerCase().includes(term) || e.email.toLowerCase().includes(term) || (e.employee_no||'').toLowerCase().includes(term));
    renderFilteredEmployees(filtered);
}

function formatDate(dateString, dateOnly = false) {
    if (!dateString || dateString === 'N/A') return 'N/A';
    const d = new Date(dateString);
    if (dateOnly) return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    return d.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

function exportToExcel() {
    if (!canExport()) { Swal.fire('Access Denied','No permission to export','error'); return; }
    let csv = 'Employee No,Name,Email,Position,Employment Type,Date Hired,Salary,Status\n';
    employeesData.forEach(e => { csv += `"${e.employee_no}","${e.first_name} ${e.last_name}","${e.email}","${e.position_name||''}","${e.employment_type||''}","${e.date_hired||''}","${e.basic_salary||''}","${e.employment_status||e.user_status}"\n`; });
    const blob = new Blob([csv],{ type:'text/csv' }), url = window.URL.createObjectURL(blob), a = document.createElement('a');
    a.href = url; a.download = `employees_${new Date().toISOString().split('T')[0]}.csv`; document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

function uploadDocument(e) {
    e.preventDefault();
    if (!canManageDocuments()) { Swal.fire('Access Denied','No permission to upload documents','error'); return; }
    const formData = new FormData(document.getElementById('employeeDocumentForm'));
    Swal.fire({ title:'Uploading...', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });
    fetch('api/users.php',{ method:'POST', body:formData }).then(async r=>{ const d=await r.json(); if(!r.ok) throw new Error(d.error||'Upload failed'); return d; }).then(d=>{ Swal.close(); if(d.success) Swal.fire('Success!',d.message,'success'); else throw new Error(d.error); }).catch(e=>{ Swal.close(); Swal.fire('Error!',e.message,'error'); });
}

function togglePassword(id) {
    const input = document.getElementById(id), icon = input.nextElementSibling.querySelector('i');
    if (input.type === 'password') { input.type = 'text'; icon.classList.replace('bi-eye','bi-eye-slash'); }
    else { input.type = 'password'; icon.classList.replace('bi-eye-slash','bi-eye'); }
}

function getFileIcon(fileName) {
    if (!fileName) return 'bi-file-earmark';
    const ext = fileName.split('.').pop().toLowerCase();
    const icons = { pdf:'bi-file-pdf text-danger', jpg:'bi-file-image text-success', jpeg:'bi-file-image text-success', png:'bi-file-image text-success', gif:'bi-file-image text-success', bmp:'bi-file-image text-success', doc:'bi-file-word text-primary', docx:'bi-file-word text-primary', xls:'bi-file-excel text-success', xlsx:'bi-file-excel text-success', csv:'bi-file-excel text-success', txt:'bi-file-text', zip:'bi-file-zip text-warning', rar:'bi-file-zip text-warning' };
    return icons[ext] || 'bi-file-earmark';
}

function showPermissions() {
    Swal.fire({ title:'Your Permissions', html:`<div class="text-start"><p><strong>Role:</strong> ${currentUserRole}</p><ul>${Object.entries(userPermissions).map(([k,v])=>`<li>${k}: ${v?'✅':'❌'}</li>`).join('')}</ul></div>`, icon:'info' });
}

function toggleOptometristFields() {
    const role        = document.getElementById('add_role').value;
    const optFields   = document.getElementById('optometristFields');
    const riderFields = document.getElementById('riderFields');

    if (optFields)   optFields.style.display   = 'none';
    if (riderFields) riderFields.style.display = 'none';

    if (role === 'Optometrist') {
        if (optFields) optFields.style.display = 'block';
        if (typeof renderScheduleBuilder === 'function' && document.getElementById('schedDayRows')) {
            renderScheduleBuilder();
        }
    } else if (role === 'Rider') {
        if (riderFields) riderFields.style.display = 'block';
    }

    if (role !== 'Optometrist') {
        const spec = document.getElementById('add_specialty');
        if (spec) spec.value = '';
        if (typeof resetScheduleBuilder === 'function') resetScheduleBuilder();
    }
}

async function loadRolesForNewEmployee() {
    console.log('🔵 loadRolesForNewEmployee STARTED');
    
    try {
        console.log('🔵 Fetching roles from API...');
        const res = await fetch('api/roles.php?action=get_roles');
        const data = await res.json();
        console.log('🔵 API Response:', data);
        
        if (!data.success) {
            console.error('🔴 API error:', data.error);
            const container = document.getElementById('addEmployeeRolesList');
            if (container) {
                container.innerHTML = '<div class="alert alert-danger">Error loading roles</div>';
            }
            return;
        }

        const container = document.getElementById('addEmployeeRolesList');
        console.log('🔵 Container found:', container);
        
        if (!container) {
            console.error('🔴 Container #addEmployeeRolesList NOT FOUND!');
            return;
        }

        if (!data.roles || data.roles.length === 0) {
            console.log('🔵 No roles found');
            container.innerHTML = '<div class="text-center text-muted py-3">No roles created yet. Create roles first.</div>';
            return;
        }

        console.log('🔵 Found', data.roles.length, 'roles');
        
        // ✅ FILTER OUT ClinicAdmin role (system role)
        // Exclude roles named "ClinicAdmin" (case insensitive)
        const filteredRoles = data.roles.filter(role => {
            const isClinicAdmin = role.role_name && role.role_name.toLowerCase() === 'clinicadmin';
            const hasValidId = role.id && role.id > 0;
            return hasValidId && !isClinicAdmin;  // ✅ Remove ClinicAdmin
        });
        
        console.log('🔵 After filtering out ClinicAdmin:', filteredRoles.length, 'roles');
        
        if (filteredRoles.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-3">
                    <i class="bi bi-info-circle me-2"></i>
                    No additional roles available.<br>
                    <small>ClinicAdmin is a system role and cannot be assigned here.</small>
                </div>`;
            return;
        }

        // Build HTML
        let html = '';
        filteredRoles.forEach(r => {
            html += `
                <div class="form-check p-3 border rounded mb-2" style="cursor:pointer; background: #fff;" onclick="this.querySelector('input').click()">
                    <input class="form-check-input new-emp-role-cb" 
                           type="checkbox" 
                           value="${r.id}" 
                           id="new_role_${r.id}" 
                           onclick="event.stopPropagation()">
                    <label class="form-check-label w-100" for="new_role_${r.id}">
                        <div class="fw-semibold">${escapeHtml(r.role_name)}</div>
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            ${r.permission_count || 0} permission(s)
                            ${r.description ? '· ' + escapeHtml(r.description) : ''}
                        </small>
                    </label>
                </div>
            `;
        });
        
        console.log('🔵 Setting HTML, length:', html.length);
        container.innerHTML = html;
        console.log('✅ SUCCESS! Roles loaded (ClinicAdmin excluded)');
        
    } catch (e) {
        console.error('🔴 ERROR in loadRolesForNewEmployee:', e);
        const container = document.getElementById('addEmployeeRolesList');
        if (container) {
            container.innerHTML = '<div class="alert alert-danger">Error loading roles: ' + e.message + '</div>';
        }
    }
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

/**
 * After employee is created, assign the selected roles
 */
async function assignRolesAfterCreate(newUserId) {
    const roleIds = [...document.querySelectorAll('.new-emp-role-cb:checked')]
                    .map(cb => parseInt(cb.value));
    if (!roleIds.length) return; // Skip if none selected

    try {
        const res  = await fetch('api/roles.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action:   'assign_user_roles',
                user_id:  newUserId,
                role_ids: roleIds
            })
        });
        const data = await res.json();
        if (!data.success) console.error('Role assign failed:', data.error);
    } catch (e) {
        console.error('Role assign error:', e);
    }
}