<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Schedule Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
    --bg:#f0f2f5;--surface:#fff;--surface2:#f8f9fb;
    --border:#e4e7ec;--text:#101828;--muted:#667085;--hint:#98a2b3;
    --primary:#2563eb;--primary-bg:#eff6ff;--primary-hover:#1d4ed8;
    --success:#059669;--success-bg:#ecfdf5;
    --warning:#d97706;--warning-bg:#fffbeb;
    --danger:#dc2626;--danger-bg:#fef2f2;
    --purple:#7c3aed;--purple-bg:#f5f3ff;
    --r:12px;--rs:8px;--rx:6px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);font-family:'DM Sans',system-ui,sans-serif;color:var(--text);font-size:14px}
.page{max-width:1300px;margin:0 auto;padding:28px 24px}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;gap:16px;flex-wrap:wrap}
.page-title{font-size:22px;font-weight:700;letter-spacing:-.3px;margin-bottom:3px;display:flex;align-items:center;gap:8px}
.page-sub{font-size:13px;color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px}
.stat-lbl{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--hint);margin-bottom:8px}
.stat-val{font-size:28px;font-weight:700;line-height:1;margin-bottom:4px}
.stat-note{font-size:11px;color:var(--muted)}
.wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.tab-bar{display:flex;border-bottom:1px solid var(--border);background:var(--surface2);padding:0 20px;gap:2px}
.tb{padding:13px 18px;font-size:13px;font-weight:500;color:var(--muted);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;display:flex;align-items:center;gap:7px;transition:color .15s}
.tb:hover{color:var(--text)}
.tb.active{color:var(--primary);border-bottom-color:var(--primary)}
.tc{font-size:11px;padding:1px 7px;border-radius:10px;background:#e9eef6;color:var(--muted);font-weight:600}
.tb.active .tc{background:var(--primary-bg);color:var(--primary)}
.card-top{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:var(--surface2)}
.card-top-l{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600}
.dbbadge{display:inline-flex;align-items:center;gap:5px;font-size:11px;background:#f1f3f9;border:1px solid var(--border);border-radius:4px;padding:2px 8px;color:var(--muted);font-family:monospace}
.toolbar{display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.sw{position:relative;flex:1;min-width:200px}
.sw input{width:100%;padding:7px 12px 7px 34px;border:1px solid var(--border);border-radius:var(--rs);font-size:13px;background:var(--surface);color:var(--text)}
.sw input:focus{outline:none;border-color:var(--primary)}
.sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--hint);font-size:14px;pointer-events:none}
table{width:100%;border-collapse:collapse}
th{padding:10px 18px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--hint);background:var(--surface2);border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:13px 18px;border-bottom:1px solid #f2f4f7;vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:#fafbfc}
.av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
.ec{display:flex;align-items:center;gap:11px}
.en{font-weight:600;font-size:13px}
.em{font-size:11px;color:var(--muted);margin-top:1px}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.b-active{background:var(--success-bg);color:var(--success)}
.b-inactive{background:#f2f4f7;color:var(--muted)}
.b-override{background:var(--primary-bg);color:var(--primary)}
.b-position{background:var(--warning-bg);color:var(--warning)}
.b-none{background:#f2f4f7;color:var(--hint);font-style:italic}
.b-optom{background:var(--purple-bg);color:var(--purple)}
.days{display:flex;gap:3px}
.dp{width:23px;height:23px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;background:#f2f4f7;color:var(--hint);border:1px solid var(--border)}
.dp.on{background:var(--primary-bg);color:var(--primary);border-color:#bfdbfe}
.tc2{display:inline-flex;align-items:center;gap:4px;font-size:11px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:2px 7px;color:var(--muted);white-space:nowrap}
.act{display:flex;gap:5px}
.ab{width:30px;height:30px;border-radius:var(--rx);border:1px solid var(--border);background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--muted);transition:all .12s}
.ab:hover{background:var(--surface2)}
.ab.edit:hover{background:var(--primary-bg);color:var(--primary);border-color:#bfdbfe}
.ab.del:hover{background:var(--danger-bg);color:var(--danger);border-color:#fca5a5}
.btn{padding:8px 16px;font-size:13px;font-weight:500;border-radius:var(--rs);border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn:hover{background:var(--surface2)}
.btn-p{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-p:hover{background:var(--primary-hover);color:#fff}
.btn-sm{padding:6px 12px;font-size:12px}
.empty{padding:48px;text-align:center;color:var(--muted)}
.empty i{font-size:36px;margin-bottom:12px;display:block;opacity:.4}
.modal-content{border-radius:var(--r);border:1px solid var(--border)}
.modal-header{border-bottom:1px solid var(--border);padding:16px 22px}
.m-title{font-size:16px;font-weight:700}
.m-sub{font-size:12px;color:var(--muted);margin-top:2px}
.modal-body{padding:22px}
.modal-footer{border-top:1px solid var(--border);padding:14px 22px}
.fl{font-size:12px;font-weight:600;margin-bottom:5px;display:block}
.fh{font-size:11px;color:var(--muted);margin-top:3px}
.fc,.fs{font-size:13px;border:1px solid var(--border);border-radius:var(--rs);padding:7px 11px;color:var(--text);background:var(--surface);width:100%;transition:border-color .15s}
.fc:focus,.fs:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.dr{display:flex;align-items:flex-start;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--rs);margin-bottom:6px;background:var(--surface)}
.dr.on{background:var(--primary-bg);border-color:#bfdbfe}
.dt{display:flex;align-items:center;gap:6px;min-width:100px;padding-top:4px}
.dt label{font-size:13px;font-weight:500;cursor:pointer}
.di{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;flex:1}
.tg{display:flex;flex-direction:column}
.tg label{font-size:10px;color:var(--muted);margin-bottom:3px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.tg input{width:108px;padding:6px 10px;border:1px solid var(--border);border-radius:var(--rx);font-size:12px;background:var(--surface);color:var(--text)}
.tg input:focus{outline:none;border-color:var(--primary)}
.rest{font-size:12px;color:var(--hint);padding:6px 0;flex:1;display:flex;align-items:center}
.pmt{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);padding-bottom:3px;white-space:nowrap;cursor:pointer}
.sp{margin-top:16px;padding:14px;background:var(--surface2);border-radius:var(--rs);border:1px solid var(--border)}
.sp-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.sp-h span{font-size:12px;font-weight:600}
.sp-h small{font-size:11px;color:var(--muted)}
.sw2{display:flex;flex-wrap:wrap;gap:4px}
.spill{font-size:11px;padding:2px 8px;background:var(--primary-bg);color:var(--primary);border-radius:4px;border:1px solid #bfdbfe}
.loader{display:flex;align-items:center;justify-content:center;padding:40px;gap:10px;color:var(--muted);font-size:13px}
.spin{width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:768px){.stats{grid-template-columns:repeat(2,1fr)}.page{padding:16px}}
</style>
</head>
<body>
<div class="page">

    <div class="page-head">
        <div>
            <div class="page-title">
                <i class="bi bi-calendar3" style="color:var(--primary)"></i>
                Schedule Management
            </div>
            <div class="page-sub">Manage doctor availability and employee work shifts</div>
        </div>
        <button class="btn btn-p" id="btnAdd" onclick="handleAdd()">
            <i class="bi bi-plus-lg"></i><span id="btnLbl">Add schedule</span>
        </button>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-lbl">Doctors</div>
            <div class="stat-val" style="color:var(--purple)" id="sDoctors">—</div>
            <div class="stat-note" id="sDoctorNote">loading...</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Employee override</div>
            <div class="stat-val" style="color:var(--primary)" id="sOverride">—</div>
            <div class="stat-note">per-employee schedule set</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Position default</div>
            <div class="stat-val" style="color:var(--warning)" id="sPosition">—</div>
            <div class="stat-note">using attendance_schedules</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">No schedule</div>
            <div class="stat-val" style="color:var(--danger)" id="sNone">—</div>
            <div class="stat-note">needs setup</div>
        </div>
    </div>

    <div class="wrap">
        <div class="tab-bar">
            <button class="tb active" onclick="switchTab('doctors',this)">
                <i class="bi bi-person-badge"></i> Doctor availability
                <span class="tc" id="tcDoc">0</span>
            </button>
            <button class="tb" onclick="switchTab('employees',this)">
                <i class="bi bi-people"></i> Employee shifts
                <span class="tc" id="tcEmp">0</span>
            </button>
        </div>

        <!-- DOCTORS -->
        <div id="pDoctors">
            <div class="card-top">
                <div class="card-top-l">
                    <i class="bi bi-circle-fill" style="font-size:8px;color:var(--purple)"></i>
                    Doctor schedule
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="font-size:11px;color:var(--muted)">Slot duration:</span>
                    <select id="slotDur" class="fs" style="width:110px;font-size:12px;padding:4px 8px" onchange="renderDoctors()">
                        <option value="30">30 min</option>
                        <option value="20">20 min</option>
                        <option value="60">60 min</option>
                    </select>
                </div>
            </div>
            <div class="toolbar">
                <div class="sw"><i class="bi bi-search"></i><input type="text" placeholder="Search doctor..." id="searchDoc" oninput="renderDoctors()"></div>
                <select class="fs" style="width:140px;font-size:12px;padding:6px 10px" id="fDocStatus" onchange="renderDoctors()">
                    <option value="">All status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Doctor</th><th>Specialty</th><th>Active days</th><th>Time slots</th><th>Slots/week</th><th>Status</th><th style="width:80px">Actions</th></tr></thead>
                    <tbody id="doctorTbody"><tr><td colspan="7"><div class="loader"><div class="spin"></div> Loading...</div></td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- EMPLOYEES -->
        <div id="pEmployees" style="display:none">
            <div class="card-top">
                <div class="card-top-l">
                    <i class="bi bi-circle-fill" style="font-size:8px;color:var(--primary)"></i>
                    Per-employee schedule 
                </div>
                <button class="btn btn-sm btn-p" onclick="openEmpModal(null)"><i class="bi bi-plus-lg"></i> Assign schedule</button>
            </div>
            <div class="toolbar">
                <div class="sw"><i class="bi bi-search"></i><input type="text" placeholder="Search employee..." id="searchEmp" oninput="renderEmployees()"></div>
                <select class="fs" style="width:170px;font-size:12px;padding:6px 10px" id="fEmpSrc" onchange="renderEmployees()">
                    <option value="">All sources</option>
                    <option value="employee">Override (per-employee)</option>
                    <option value="position">Position default</option>
                    <option value="none">No schedule</option>
                </select>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>Employee</th><th>Role</th><th>Schedule name</th><th>Time in / out</th><th>Break</th><th>Grace</th><th>Source</th><th style="width:80px">Actions</th></tr></thead>
                    <tbody id="empTbody"><tr><td colspan="8"><div class="loader"><div class="spin"></div> Loading...</div></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: DOCTOR SCHEDULE -->
<div class="modal fade" id="mDoc" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="m-title" id="mDocTitle">Set doctor schedule</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mDocId">
                <div id="docBar" style="display:flex;align-items:center;gap:14px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--rs);margin-bottom:18px">
                    <div class="av" id="mDocAv" style="background:var(--purple-bg);color:var(--purple);width:42px;height:42px;font-size:15px">?</div>
                    <div style="flex:1"><div style="font-weight:700;font-size:15px" id="mDocName">—</div><div style="font-size:12px;color:var(--muted)" id="mDocSpec">—</div></div>
                    <div><div style="font-size:11px;color:var(--muted);margin-bottom:4px">Slot duration</div>
                    <select class="fs" id="mSlotDur" style="width:110px;font-size:12px"><option value="30">30 min</option><option value="20">20 min</option><option value="60">60 min</option></select></div>
                </div>
                <div style="font-size:13px;font-weight:600;margin-bottom:10px;display:flex;align-items:center;gap:6px"><i class="bi bi-calendar-week" style="color:var(--primary)"></i> Weekly availability</div>
                <div id="dayRows"></div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn btn-sm" onclick="previewSlots()"><i class="bi bi-eye"></i> Preview slots</button>
                    <button class="btn btn-sm" onclick="clearAllDays()"><i class="bi bi-x-circle"></i> Clear all</button>
                </div>
                <div id="slotPrev" style="display:none" class="sp">
                    <div class="sp-h"><span><i class="bi bi-grid-3x3-gap" style="color:var(--success);margin-right:4px"></i>Appointment slots preview</span><small id="slotCnt"></small></div>
                    <div class="sw2" id="slotWrap"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-p" onclick="saveDocSchedule()" id="btnSaveDoc"><i class="bi bi-check-lg"></i> Save schedule</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: EMPLOYEE SCHEDULE -->
<div class="modal fade" id="mEmp" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><div class="m-title" id="mEmpTitle">Assign employee schedule</div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="mEmpSchedId">
                <input type="hidden" id="mEmpId">
                <div class="mb-3" id="empSelectWrap">
                    <label class="fl">Employee <span class="text-danger">*</span></label>
                    <select class="fs" id="empSel"><option value="">Select employee...</option></select>
                </div>
                <div id="empInfo" style="display:none;margin-bottom:16px">
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--rs)">
                        <div class="av" id="mEmpAv" style="background:var(--primary-bg);color:var(--primary)">?</div>
                        <div><div style="font-weight:600;font-size:13px" id="mEmpName">—</div><div style="font-size:11px;color:var(--muted)" id="mEmpMeta">—</div></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="fl">Schedule name</label>
                    <input type="text" class="fc" id="mSchedName" placeholder="e.g. Morning Shift, Flexi Hours">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="fl">Schedule type</label>
                        <select class="fs" id="mSchedType"><option value="fixed">Fixed</option><option value="flexible">Flexible</option><option value="shifting">Shifting</option></select></div>
                    <div class="col-6"><label class="fl">Required hours/day</label><input type="number" class="fc" id="mReqHrs" value="8" step="0.5" min="1" max="12"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="fl">Time in <span class="text-danger">*</span></label><input type="time" class="fc" id="mTimeIn" value="08:00"></div>
                    <div class="col-6"><label class="fl">Time out <span class="text-danger">*</span></label><input type="time" class="fc" id="mTimeOut" value="17:00"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="fl">Break start</label><input type="time" class="fc" id="mBreakS" value="12:00"></div>
                    <div class="col-6"><label class="fl">Break end</label><input type="time" class="fc" id="mBreakE" value="13:00"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4"><label class="fl">Grace period (min)</label><input type="number" class="fc" id="mGrace" value="15" min="0" max="60"><div class="fh">attendance_action.php</div></div>
                    <div class="col-4"><label class="fl">Effective from</label><input type="date" class="fc" id="mEffFrom"><div class="fh">Blank = always</div></div>
                    <div class="col-4"><label class="fl">Effective to</label><input type="date" class="fc" id="mEffTo"><div class="fh">Blank = no end</div></div>
                </div>
                <div class="mb-3"><label class="fl">Notes</label><textarea class="fc" id="mNotes" rows="2" placeholder="Optional notes" style="height:auto;resize:vertical"></textarea></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="mIsActive" checked><label class="form-check-label" style="font-size:13px" for="mIsActive">Active</label></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-p" onclick="saveEmpSchedule()" id="btnSaveEmp"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API  = 'api/schedule.php';
const DAYS = ['mon','tue','wed','thu','fri','sat','sun'];
const DL   = {mon:'M',tue:'T',wed:'W',thu:'Th',fri:'F',sat:'S',sun:'Su'};
const DF   = {mon:'Monday',tue:'Tuesday',wed:'Wednesday',thu:'Thursday',fri:'Friday',sat:'Saturday',sun:'Sunday'};
const AVC  = [['#f5f3ff','#7c3aed'],['#eff6ff','#2563eb'],['#ecfdf5','#059669'],['#fef3c7','#92400e'],['#fce7f3','#831843'],['#ffedd5','#9a3412']];
const RBADGE = {HR:'background:#fef3c7;color:#92400e',Finance:'background:#ecfdf5;color:#065f46',Optometrist:'background:#f5f3ff;color:#7c3aed',Staff:'background:#eff6ff;color:#075985',CRM:'background:#fce7f3;color:#831843',SCM:'background:#ffedd5;color:#9a3412',ClinicAdmin:'background:#e0e7ff;color:#3730a3'};

let doctors=[],empSchedules=[],employees=[],perms={},currentTab='doctors';

document.addEventListener('DOMContentLoaded', async () => {
    await loadPermissions();
    await Promise.all([loadDoctors(), loadEmployeeSchedules(), loadEmployees()]);
});

async function apiFetch(url) {
    const r = await fetch(url);
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Unknown error');
    return d.data;
}

async function apiPost(body) {
    const r = await fetch(API, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    return r.json();
}

async function loadPermissions() {
    try {
        const res = await fetch('api/schedule.php?get_permissions=true');
        const data = await res.json();
        
        if (data.success) {
            perms = data.data;
            console.log('✅ Schedule permissions loaded:', perms.permissions);
            
            // Apply permission-based UI
            if (!perms.permissions.view) {
                // Show access denied
                document.getElementById('scheduleContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-shield-lock"></i> 
                        You don't have permission to view schedules.
                    </div>
                `;
            }
            
            // Hide edit buttons if no edit permission
            if (!perms.permissions.edit_doctor_schedule) {
                document.querySelectorAll('.edit-doctor-btn').forEach(btn => btn.style.display = 'none');
            }
            if (!perms.permissions.manage_employee_sched) {
                document.querySelectorAll('.edit-employee-btn').forEach(btn => btn.style.display = 'none');
            }
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
    }
}
async function loadDoctors() {
    try {
        doctors = await apiFetch(`${API}?action=get_doctors`);
        renderDoctors(); updateStats();
    } catch(e) {
        document.getElementById('doctorTbody').innerHTML = errRow(7, e.message);
    }
}

async function loadEmployeeSchedules() {
    try {
        empSchedules = await apiFetch(`${API}?action=get_employee_schedules`);
        renderEmployees(); updateStats();
    } catch(e) {
        document.getElementById('empTbody').innerHTML = errRow(8, e.message);
    }
}

async function loadEmployees() {
    try {
        employees = await apiFetch(`${API}?action=get_employees`);
        populateEmpDropdown();
    } catch(e) { console.error(e); }
}

function errRow(cols, msg) {
    return `<tr><td colspan="${cols}"><div class="empty"><i class="bi bi-exclamation-triangle"></i><p>${msg}</p></div></td></tr>`;
}

function updateStats() {
    const ws = doctors.filter(d=>d.schedule&&Object.keys(d.schedule).length>0).length;
    document.getElementById('sDoctors').textContent   = doctors.length;
    document.getElementById('sDoctorNote').textContent= `${ws} with schedule`;
    document.getElementById('tcDoc').textContent      = doctors.length;
    const ov=empSchedules.filter(e=>e.schedule_source==='employee').length;
    const ps=empSchedules.filter(e=>e.schedule_source==='position').length;
    const nn=empSchedules.filter(e=>e.schedule_source==='none').length;
    document.getElementById('sOverride').textContent  = ov;
    document.getElementById('sPosition').textContent  = ps;
    document.getElementById('sNone').textContent      = nn;
    document.getElementById('tcEmp').textContent      = empSchedules.length;
}

function switchTab(tab, el) {
    currentTab = tab;
    document.querySelectorAll('.tb').forEach(t=>t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('pDoctors').style.display   = tab==='doctors'   ? '':'none';
    document.getElementById('pEmployees').style.display = tab==='employees' ? '':'none';
    document.getElementById('btnLbl').textContent = tab==='doctors' ? 'Add doctor schedule' : 'Assign schedule';
}

function handleAdd() {
    if (currentTab === 'doctors') {
        if (!perms.permissions?.edit_doctor_schedule) {
            Swal.fire('Access Denied', 'You do not have permission to add doctor schedules.', 'warning');
            return;
        }
        openDocModal(null);
    } else {
        if (!perms.permissions?.manage_employee_sched) {
            Swal.fire('Access Denied', 'You do not have permission to assign employee schedules.', 'warning');
            return;
        }
        openEmpModal(null);
    }
}

function renderDoctors() {
    const tbody  = document.getElementById('doctorTbody');
    const search = document.getElementById('searchDoc').value.toLowerCase();
    const status = document.getElementById('fDocStatus').value;
    const dur    = parseInt(document.getElementById('slotDur').value);
    const canEdit= perms.permissions?.edit_doctor_schedule;

    const rows = doctors.filter(d => {
        if (search && !d.name.toLowerCase().includes(search) && !(d.specialty||'').toLowerCase().includes(search)) return false;
        if (status !== '' && String(d.is_active) !== status) return false;
        return true;
    });

    if (!rows.length) { tbody.innerHTML=`<tr><td colspan="7"><div class="empty"><i class="bi bi-person-badge"></i><p>No doctors found</p></div></td></tr>`; return; }

    tbody.innerHTML = rows.map((doc, i) => {
        const [bg,fg]    = AVC[i%AVC.length];
        const initials   = doc.name.split(' ').filter(Boolean).map(w=>w[0]).join('').toUpperCase().substring(0,2);
        const activeDays = DAYS.filter(d=>doc.schedule?.[d]?.length>0);
        const hasSched   = activeDays.length>0;

        const dayPills = DAYS.map(d=>`<span class="dp ${activeDays.includes(d)?'on':''}">${DL[d]}</span>`).join('');

        let timeHtml = '<span style="font-size:12px;color:var(--hint);font-style:italic">No schedule</span>';
        if (hasSched) {
            const sample = doc.schedule[activeDays[0]];
            timeHtml = sample.map(t=>`<span class="tc2"><i class="bi bi-clock" style="font-size:10px"></i>${t}</span>`).join(' ');
        }

        let slotCount = 0;
        if (hasSched) DAYS.forEach(d=>(doc.schedule[d]||[]).forEach(slot=>{
            const [s,e]=slot.split('-');
            slotCount+=Math.floor((timeToMins(e)-timeToMins(s))/dur);
        }));

        const userBadge = doc.user_id
            ? `<span style="font-size:10px;color:var(--primary);background:var(--primary-bg);padding:1px 6px;border-radius:10px;font-weight:600">linked</span>`
            : `<span style="font-size:10px;color:var(--hint);background:#f2f4f7;padding:1px 6px;border-radius:10px">no account</span>`;

        const editBtn = canEdit
            ? `<button class="ab edit" title="Edit" onclick="openDocModal(${doc.id})"><i class="bi bi-pencil"></i></button>`
            : `<button class="ab" title="View only" style="opacity:.4" disabled><i class="bi bi-lock"></i></button>`;

        return `<tr>
            <td><div class="ec"><div class="av" style="background:${bg};color:${fg}">${initials}</div>
                <div><div class="en">${doc.name}</div><div class="em">${userBadge} clinic ${doc.clinic_id}</div></div></div></td>
            <td>${doc.specialty?`<span class="badge b-optom">${doc.specialty}</span>`:'<span style="color:var(--hint);font-size:12px">—</span>'}</td>
            <td><div class="days">${dayPills}</div></td>
            <td>${timeHtml}</td>
            <td>${hasSched?`<strong>${slotCount}</strong><span style="font-size:11px;color:var(--muted)"> slots</span>`:'—'}</td>
            <td><span class="badge ${doc.is_active?'b-active':'b-inactive'}">${doc.is_active?'Active':'Inactive'}</span></td>
            <td><div class="act">${editBtn}<button class="ab" title="Preview slots" onclick="previewDocSlots(${doc.id})"><i class="bi bi-grid-3x3-gap"></i></button></div></td>
        </tr>`;
    }).join('');
}

function renderEmployees() {
    const tbody   = document.getElementById('empTbody');
    const search  = document.getElementById('searchEmp').value.toLowerCase();
    const srcFil  = document.getElementById('fEmpSrc').value;
    const canEdit = perms.permissions?.manage_employee_sched;

    const rows = empSchedules.filter(e => {
        if (search && !e.employee_name.toLowerCase().includes(search) && !(e.employee_no||'').toLowerCase().includes(search)) return false;
        if (srcFil && e.schedule_source!==srcFil) return false;
        return true;
    });

    if (!rows.length) { tbody.innerHTML=`<tr><td colspan="8"><div class="empty"><i class="bi bi-people"></i><p>No employees found</p></div></td></tr>`; return; }

    tbody.innerHTML = rows.map((e, i) => {
        const [bg,fg] = AVC[i%AVC.length];
        const initials = e.employee_name.split(' ').filter(Boolean).map(w=>w[0]).join('').toUpperCase().substring(0,2);
        const rsty = RBADGE[e.role]||'background:#f2f4f7;color:#667085';

        let src, timeH, breakH, graceH, nameH;
        if (e.schedule_source==='employee') {
            src   = `<span class="badge b-override"><i class="bi bi-person-check me-1"></i>Per-employee</span>`;
            timeH = `<span class="tc2">${(e.time_in||'').substring(0,5)}</span> → <span class="tc2">${(e.time_out||'').substring(0,5)}</span>`;
            breakH= `<span class="tc2">${(e.break_start||'').substring(0,5)}</span> – <span class="tc2">${(e.break_end||'').substring(0,5)}</span>`;
            graceH= `<strong>${e.grace_period}</strong><span style="font-size:11px;color:var(--muted)">min</span>`;
            nameH = `<span style="font-size:13px;font-weight:500">${e.schedule_name||'—'}</span>`;
        } else if (e.schedule_source==='position') {
            src   = `<span class="badge b-position"><i class="bi bi-diagram-3 me-1"></i>Position default</span>`;
            timeH = `<span class="tc2">${(e.time_in||'').substring(0,5)}</span> → <span class="tc2">${(e.time_out||'').substring(0,5)}</span>`;
            breakH= `<span class="tc2">${(e.break_start||'').substring(0,5)}</span> – <span class="tc2">${(e.break_end||'').substring(0,5)}</span>`;
            graceH= `<strong>${e.grace_period}</strong><span style="font-size:11px;color:var(--muted)">min</span>`;
            nameH = `<span style="font-size:12px;color:var(--muted);font-style:italic">From position</span>`;
        } else {
            src='<span class="badge b-none">No schedule</span>';
            timeH='<span style="font-size:12px;color:var(--danger)">Not set</span>';
            breakH='—'; graceH='—'; nameH='—';
        }

        const editBtn = canEdit
            ? `<button class="ab edit" title="Edit" onclick="openEmpModal(${e.employee_id})"><i class="bi bi-pencil"></i></button>`
            : '';
        const delBtn  = canEdit && e.schedule_id
            ? `<button class="ab del" title="Remove override" onclick="removeEmpSched(${e.schedule_id},'${e.employee_name.replace(/'/g,"\\'")}')"><i class="bi bi-x-circle"></i></button>`
            : '';

        return `<tr>
            <td><div class="ec"><div class="av" style="background:${bg};color:${fg}">${initials}</div>
                <div><div class="en">${e.employee_name}</div><div class="em">${e.employee_no} · ${e.employment_type||''}</div></div></div></td>
            <td><span class="badge" style="${rsty}">${e.role}</span></td>
            <td>${nameH}</td>
            <td>${timeH}</td>
            <td>${breakH}</td>
            <td>${graceH}</td>
            <td>${src}</td>
            <td><div class="act">${editBtn}${delBtn}</div></td>
        </tr>`;
    }).join('');
}

// ---- DOCTOR MODAL ----
function openDocModal(doctorId) {
    const doc = doctorId ? doctors.find(d=>d.id===doctorId) : null;
    document.getElementById('mDocId').value         = doctorId||'';
    document.getElementById('mDocTitle').textContent= doc?`Edit — ${doc.name}`:'Set doctor schedule';
    document.getElementById('mDocName').textContent = doc?.name||'—';
    document.getElementById('mDocSpec').textContent = doc?.specialty||'No specialty';
    document.getElementById('mDocAv').textContent   = doc ? doc.name.split(' ').filter(Boolean).map(w=>w[0]).join('').toUpperCase().substring(0,2) : '?';
    buildDayRows(doc?.schedule||null);
    document.getElementById('slotPrev').style.display = 'none';
    new bootstrap.Modal(document.getElementById('mDoc')).show();
}

function buildDayRows(schedule) {
    document.getElementById('dayRows').innerHTML = DAYS.map(day => {
        const slots = schedule?.[day]||[];
        const active= slots.length>0;
        const amS   = parseSlot(slots,0,'start'), amE=parseSlot(slots,0,'end');
        const hasPM = slots.length>1;
        const pmS   = parseSlot(slots,1,'start'), pmE=parseSlot(slots,1,'end');
        return `<div class="dr ${active?'on':''}" id="drow_${day}">
            <div class="dt"><input type="checkbox" id="dck_${day}" ${active?'checked':''} onchange="toggleDay('${day}')"><label for="dck_${day}">${DF[day]}</label></div>
            <div id="dinp_${day}" style="display:${active?'flex':'none'}" class="di">
                <div class="tg"><label>AM start</label><input type="time" id="ams_${day}" value="${amS}"></div>
                <div class="tg"><label>AM end</label><input type="time" id="ame_${day}" value="${amE}"></div>
                <label class="pmt"><input type="checkbox" id="hpm_${day}" ${hasPM?'checked':''} onchange="togglePM('${day}')"> PM session</label>
                <div id="pminp_${day}" style="display:${hasPM?'flex':'none'};gap:8px">
                    <div class="tg"><label>PM start</label><input type="time" id="pms_${day}" value="${pmS}"></div>
                    <div class="tg"><label>PM end</label><input type="time" id="pme_${day}" value="${pmE}"></div>
                </div>
            </div>
            <div id="doff_${day}" style="display:${active?'none':'flex'}" class="rest"><i class="bi bi-dash-circle me-2"></i>Rest day</div>
        </div>`;
    }).join('');
}

function parseSlot(slots,idx,part){if(!slots[idx])return part==='start'?'09:00':'12:00';const[s,e]=slots[idx].split('-');return part==='start'?(s||'09:00'):(e||'12:00');}
function toggleDay(day){const on=document.getElementById('dck_'+day).checked;document.getElementById('dinp_'+day).style.display=on?'flex':'none';document.getElementById('doff_'+day).style.display=on?'none':'flex';document.getElementById('drow_'+day).classList.toggle('on',on);}
function togglePM(day){document.getElementById('pminp_'+day).style.display=document.getElementById('hpm_'+day).checked?'flex':'none';}
function clearAllDays(){DAYS.forEach(d=>{const c=document.getElementById('dck_'+d);if(c){c.checked=false;toggleDay(d);}});}

function buildSchedFromForm(){
    const s={};
    DAYS.forEach(day=>{
        if(!document.getElementById('dck_'+day)?.checked)return;
        const amS=document.getElementById('ams_'+day)?.value;
        const amE=document.getElementById('ame_'+day)?.value;
        const ps=[];
        if(amS&&amE)ps.push(`${amS}-${amE}`);
        if(document.getElementById('hpm_'+day)?.checked){
            const pmS=document.getElementById('pms_'+day)?.value;
            const pmE=document.getElementById('pme_'+day)?.value;
            if(pmS&&pmE)ps.push(`${pmS}-${pmE}`);
        }
        if(ps.length)s[day]=ps;
    });
    return s;
}

function previewSlots(){
    const dur=parseInt(document.getElementById('mSlotDur').value);
    const sched=buildSchedFromForm();
    const slots=[];
    Object.entries(sched).forEach(([day,periods])=>periods.forEach(p=>{
        const[s,e]=p.split('-');let cur=timeToMins(s);const end=timeToMins(e);
        while(cur+dur<=end){slots.push({day,time:minsToTime(cur)});cur+=dur;}
    }));
    const wrap=document.getElementById('slotWrap');
    wrap.innerHTML=slots.slice(0,40).map(s=>`<span class="spill">${s.day.substring(0,3).toUpperCase()} ${s.time}</span>`).join('');
    if(slots.length>40)wrap.innerHTML+=`<span style="font-size:11px;color:var(--muted)">+${slots.length-40} more</span>`;
    document.getElementById('slotCnt').textContent=`${slots.length} slots/week`;
    document.getElementById('slotPrev').style.display='';
}

function previewDocSlots(id){openDocModal(id);setTimeout(previewSlots,400);}

async function saveDocSchedule(){
    const doctorId=document.getElementById('mDocId').value;
    if(!doctorId){Swal.fire('Error','No doctor selected','error');return;}
    const schedule=buildSchedFromForm();
    const dur=parseInt(document.getElementById('mSlotDur').value);
    const btn=document.getElementById('btnSaveDoc');
    btn.disabled=true;btn.innerHTML='<div class="spin" style="width:14px;height:14px;border-width:2px;margin-right:6px"></div> Saving...';
    const res=await apiPost({action:'save_doctor_schedule',doctor_id:parseInt(doctorId),schedule,slot_duration_mins:dur});
    btn.disabled=false;btn.innerHTML='<i class="bi bi-check-lg"></i> Save schedule';
    if(res.success){
        bootstrap.Modal.getInstance(document.getElementById('mDoc')).hide();
        Swal.fire({icon:'success',title:'Schedule saved!',html:`<div style="font-size:13px"><p>Updated <code>doctors.schedule</code></p><p class="text-muted mt-1">Appointment slots regenerated for next 30 days.</p></div>`,timer:3000,showConfirmButton:false});
        await loadDoctors();
    }else Swal.fire('Error',res.error||'Failed','error');
}

// ---- EMPLOYEE MODAL ----
function populateEmpDropdown(){
    const sel=document.getElementById('empSel');
    sel.innerHTML='<option value="">Select employee...</option>';
    employees.forEach(e=>{const o=document.createElement('option');o.value=e.id;o.textContent=`${e.employee_no} — ${e.full_name} (${e.role})`;sel.appendChild(o);});
    sel.addEventListener('change',function(){
        const emp=employees.find(e=>e.id==this.value);
        if(!emp){document.getElementById('empInfo').style.display='none';return;}
        document.getElementById('mEmpAv').textContent  =emp.full_name.split(' ').filter(Boolean).map(w=>w[0]).join('').toUpperCase().substring(0,2);
        document.getElementById('mEmpName').textContent=emp.full_name;
        document.getElementById('mEmpMeta').textContent=`${emp.employee_no} · ${emp.role} · ${emp.employment_type}`;
        document.getElementById('empInfo').style.display='';
    });
}

async function openEmpModal(employeeId){
    document.getElementById('mEmpSchedId').value='';
    document.getElementById('mEmpId').value=employeeId||'';
    document.getElementById('mEmpTitle').textContent='Assign employee schedule';
    document.getElementById('empInfo').style.display='none';
    document.getElementById('empSel').value=employeeId||'';
    ['mSchedName','mNotes'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('mSchedType').value='fixed';
    document.getElementById('mReqHrs').value=8;
    document.getElementById('mTimeIn').value='08:00';
    document.getElementById('mTimeOut').value='17:00';
    document.getElementById('mBreakS').value='12:00';
    document.getElementById('mBreakE').value='13:00';
    document.getElementById('mGrace').value=15;
    document.getElementById('mEffFrom').value='';
    document.getElementById('mEffTo').value='';
    document.getElementById('mIsActive').checked=true;
    document.getElementById('empSelectWrap').style.display=employeeId?'none':'';

    if(employeeId){
        const emp=employees.find(e=>e.id==employeeId);
        if(emp){
            document.getElementById('mEmpAv').textContent  =emp.full_name.split(' ').filter(Boolean).map(w=>w[0]).join('').toUpperCase().substring(0,2);
            document.getElementById('mEmpName').textContent=emp.full_name;
            document.getElementById('mEmpMeta').textContent=`${emp.employee_no} · ${emp.role} · ${emp.employment_type}`;
            document.getElementById('empInfo').style.display='';
        }
        try{
            const ex=await apiFetch(`${API}?action=get_employee_schedule&employee_id=${employeeId}`);
            if(ex){
                document.getElementById('mEmpSchedId').value =ex.id||'';
                document.getElementById('mSchedName').value  =ex.schedule_name||'';
                document.getElementById('mSchedType').value  =ex.schedule_type||'fixed';
                document.getElementById('mReqHrs').value     =ex.required_work_hours||8;
                document.getElementById('mTimeIn').value     =(ex.time_in||'08:00:00').substring(0,5);
                document.getElementById('mTimeOut').value    =(ex.time_out||'17:00:00').substring(0,5);
                document.getElementById('mBreakS').value     =(ex.break_start||'12:00:00').substring(0,5);
                document.getElementById('mBreakE').value     =(ex.break_end||'13:00:00').substring(0,5);
                document.getElementById('mGrace').value      =ex.grace_period||15;
                document.getElementById('mEffFrom').value    =ex.effective_from||'';
                document.getElementById('mEffTo').value      =ex.effective_to||'';
                document.getElementById('mNotes').value      =ex.notes||'';
                document.getElementById('mIsActive').checked =ex.is_active==1;
                document.getElementById('mEmpTitle').textContent=`Edit schedule — ${ex.employee_name||'Employee'}`;
            }
        }catch(e){/* no existing schedule — new */}
    }
    new bootstrap.Modal(document.getElementById('mEmp')).show();
}

async function saveEmpSchedule(){
    const empId=document.getElementById('mEmpId').value||document.getElementById('empSel').value;
    if(!empId){Swal.fire('Error','Please select an employee','error');return;}
    const ti=document.getElementById('mTimeIn').value;
    const to=document.getElementById('mTimeOut').value;
    if(!ti||!to){Swal.fire('Error','Time in and Time out are required','error');return;}
    const btn=document.getElementById('btnSaveEmp');
    btn.disabled=true;btn.innerHTML='<div class="spin" style="width:14px;height:14px;border-width:2px;margin-right:6px"></div> Saving...';
    const schedId = document.getElementById('mEmpSchedId') ? document.getElementById('mEmpSchedId').value : null;
    const isEdit  = schedId && schedId !== '' && schedId !== 'null';
    const res=await apiPost({
        action: isEdit ? 'update_employee_schedule' : 'save_employee_schedule',
        id: isEdit ? parseInt(schedId) : null,
        employee_id:parseInt(empId),
        schedule_name:document.getElementById('mSchedName').value.trim()||null,
        schedule_type:document.getElementById('mSchedType').value,
        time_in:ti,time_out:to,
        break_start:document.getElementById('mBreakS').value||null,
        break_end:document.getElementById('mBreakE').value||null,
        grace_period:parseInt(document.getElementById('mGrace').value)||15,
        required_work_hours:parseFloat(document.getElementById('mReqHrs').value)||8,
        effective_from:document.getElementById('mEffFrom').value||null,
        effective_to:document.getElementById('mEffTo').value||null,
        notes:document.getElementById('mNotes').value.trim()||null,
        is_active:document.getElementById('mIsActive').checked?1:0,
    });
    btn.disabled=false;btn.innerHTML='<i class="bi bi-check-lg"></i> Save';
    if(res.success){
        bootstrap.Modal.getInstance(document.getElementById('mEmp')).hide();
        Swal.fire({icon:'success',title:'Schedule saved!',timer:2000,showConfirmButton:false});
        await loadEmployeeSchedules();
    }else Swal.fire('Error',res.error||'Failed','error');
}

async function removeEmpSched(scheduleId, name){
    const r=await Swal.fire({title:`Remove schedule for ${name}?`,html:`<div style="font-size:13px"><p>Per-employee override will be removed.</p><p class="text-muted mt-1">Employee falls back to <strong>position default</strong>.</p></div>`,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc2626',confirmButtonText:'Yes, remove'});
    if(!r.isConfirmed)return;
    const res=await apiPost({action:'delete_employee_schedule',id:scheduleId});
    if(res.success){Swal.fire({icon:'success',title:'Removed!',text:res.message,timer:2000,showConfirmButton:false});await loadEmployeeSchedules();}
    else Swal.fire('Error',res.error,'error');
}

function timeToMins(t){const[h,m]=t.split(':').map(Number);return h*60+m;}
function minsToTime(m){return`${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;}
</script>
</body>
</html>