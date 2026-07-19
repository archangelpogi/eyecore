<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --bg: #f0f2f5;
    --surface: #ffffff;
    --surface2: #f8f9fb;
    --border: #e4e7ec;
    --text: #101828;
    --muted: #667085;
    --hint: #98a2b3;
    --primary: #2563eb;
    --primary-bg: #eff6ff;
    --primary-hover: #1d4ed8;
    --success: #059669;
    --success-bg: #ecfdf5;
    --warning: #d97706;
    --warning-bg: #fffbeb;
    --danger: #dc2626;
    --danger-bg: #fef2f2;
    --purple: #7c3aed;
    --purple-bg: #f5f3ff;
    --today-border: #818cf8;
    --today-bg: #eef2ff;
    --radius: 12px;
    --radius-sm: 8px;
    --radius-xs: 6px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);font-family:'Segoe UI',system-ui,sans-serif;color:var(--text);font-size:14px}

.page{max-width:1200px;margin:0 auto;padding:28px 24px}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;gap:12px;flex-wrap:wrap}
.page-title{font-size:22px;font-weight:700;letter-spacing:-.3px;margin-bottom:3px;display:flex;align-items:center;gap:8px}
.page-sub{font-size:13px;color:var(--muted)}

/* PROFILE */
.profile-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.big-av{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:700;flex-shrink:0}
.profile-name{font-size:17px;font-weight:700;margin-bottom:4px}
.profile-meta{display:flex;gap:14px;flex-wrap:wrap}
.meta-item{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted)}
.meta-item i{font-size:13px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px}
.stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--hint);margin-bottom:6px}
.stat-val{font-size:26px;font-weight:700;line-height:1;margin-bottom:3px}
.stat-note{font-size:11px;color:var(--muted)}

/* LAYOUT */
.main-grid{display:grid;grid-template-columns:1fr 310px;gap:18px}

/* WEEK CALENDAR */
.week-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.week-nav{display:flex;align-items:center;gap:8px}
.week-lbl{font-size:14px;font-weight:600;min-width:185px;text-align:center}
.nav-btn{width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--muted);transition:all .12s}
.nav-btn:hover{background:var(--surface2);color:var(--text)}
.today-btn{padding:6px 12px;font-size:12px;font-weight:500;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);cursor:pointer;color:var(--muted);transition:all .12s}
.today-btn:hover{background:var(--surface2);color:var(--text)}

.week-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.day-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);min-height:140px;overflow:hidden}
.day-card.today{border-color:var(--today-border);background:var(--today-bg)}
.day-card.rest{background:var(--surface2)}
.day-card.past{opacity:.6}

.day-hd{padding:8px 10px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.day-name{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--hint)}
.day-num{font-size:17px;font-weight:700}
.day-card.today .day-name{color:var(--primary)}
.day-card.today .day-num{color:var(--primary)}
.today-dot{width:6px;height:6px;background:var(--primary);border-radius:50%}

.day-body{padding:8px 10px}
.shift-block{border-radius:5px;padding:5px 8px;margin-bottom:5px}
.shift-block.emp{background:#dbeafe;border:1px solid #bfdbfe}
.shift-block.doc{background:#ede9fe;border:1px solid #c4b5fd}
.shift-time{font-size:11px;font-weight:600;color:#1e40af}
.shift-block.doc .shift-time{color:#5b21b6}
.shift-sub{font-size:10px;color:var(--muted);margin-top:1px}
.rest-txt{font-size:11px;color:var(--hint);padding:8px 0;text-align:center}

.leave-tag{display:inline-block;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600;margin-top:3px}
.leave-approved{background:var(--success-bg);color:var(--success)}
.leave-pending{background:var(--warning-bg);color:var(--warning)}

/* SHIFT DETAIL */
.info-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-top:16px}
.info-card-hd{padding:12px 16px;border-bottom:1px solid var(--border);background:var(--surface2);font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px}
.detail-list{padding:0}
.detail-item{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f2f4f7;font-size:13px}
.detail-item:last-child{border-bottom:none}
.detail-label{color:var(--muted);font-size:12px}
.detail-val{font-weight:500}
.src-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600}

/* SIDEBAR */
.side-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:14px}
.side-hd{padding:12px 16px;border-bottom:1px solid var(--border);background:var(--surface2);font-size:13px;font-weight:600;display:flex;justify-content:space-between;align-items:center}

/* BALANCE */
.bal-item{padding:12px 16px;border-bottom:1px solid #f2f4f7}
.bal-item:last-child{border-bottom:none}
.bal-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px}
.bal-name{font-weight:500}
.bal-nums{color:var(--muted)}
.bal-bar{background:#e2e8f0;border-radius:20px;height:6px;overflow:hidden}
.bal-fill{height:6px;border-radius:20px;background:var(--primary);transition:width .4s}

/* UPCOMING LEAVES */
.leave-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f2f4f7}
.leave-row:last-child{border-bottom:none}
.leave-icon-wrap{width:34px;height:34px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.leave-info .leave-type{font-size:13px;font-weight:500}
.leave-info .leave-dates{font-size:11px;color:var(--muted)}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-pending{background:var(--warning-bg);color:var(--warning)}
.badge-approved{background:var(--success-bg);color:var(--success)}
.badge-rejected{background:var(--danger-bg);color:var(--danger)}

/* DOC SCHEDULE EDITOR */
.day-row{display:flex;align-items:flex-start;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:6px;background:var(--surface);transition:background .15s}
.day-row.on{background:var(--primary-bg);border-color:#bfdbfe}
.day-check{min-width:95px;display:flex;align-items:center;gap:6px;padding-top:3px}
.day-check label{font-size:13px;font-weight:500;cursor:pointer}
.day-inputs{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;flex:1}
.ti{display:flex;flex-direction:column}
.ti label{font-size:10px;color:var(--muted);margin-bottom:3px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.ti input{width:105px;padding:6px 9px;border:1px solid var(--border);border-radius:var(--radius-xs);font-size:12px;color:var(--text);background:var(--surface)}
.ti input:focus{outline:none;border-color:var(--primary)}
.rest-lbl{font-size:12px;color:var(--hint);padding:6px 0;flex:1;display:flex;align-items:center;gap:4px}
.pm-chk{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);padding-bottom:3px;cursor:pointer}

.btn{padding:8px 16px;font-size:13px;font-weight:500;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn:hover{background:var(--surface2)}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:var(--primary-hover);color:#fff}
.btn-sm{padding:6px 12px;font-size:12px}

.form-label{font-size:12px;font-weight:600;margin-bottom:5px;color:var(--text);display:block}
.form-control,.form-select{font-size:13px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:7px 11px;color:var(--text);background:var(--surface);width:100%}
.form-control:focus,.form-select:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.modal-content{border-radius:var(--radius);border:1px solid var(--border)}
.modal-header{border-bottom:1px solid var(--border);padding:16px 22px}
.modal-title{font-size:16px;font-weight:700}
.modal-sub{font-size:12px;color:var(--muted);margin-top:2px}
.modal-body{padding:22px}
.modal-footer{border-top:1px solid var(--border);padding:14px 22px}

.empty-side{padding:24px 16px;text-align:center;color:var(--hint);font-size:12px}
.loading-txt{padding:16px;text-align:center;color:var(--hint);font-size:12px}

@media(max-width:900px){.main-grid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.week-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:600px){.week-grid{grid-template-columns:repeat(2,1fr)}.stats{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="page">

    <!-- HEAD -->
    <div class="page-head">
        <div>
            <div class="page-title"><i class="bi bi-calendar-check" style="color:var(--primary)"></i> My Schedule</div>
            <div class="page-sub">Your work schedule, leave balances, and upcoming leaves</div>
        </div>
        <!-- Doctor edit button (shown only if role = Optometrist) -->
        <div id="docEditWrap" style="display:none">
            <button class="btn btn-primary" onclick="openDocSelfEdit()">
                <i class="bi bi-pencil"></i> Edit my availability
            </button>
        </div>
    </div>

    <!-- PROFILE -->
    <div class="profile-card" id="profileCard">
        <div class="big-av" id="profileAv" style="background:#eff6ff;color:#2563eb">—</div>
        <div style="flex:1">
            <div class="profile-name" id="profileName">Loading...</div>
            <div class="profile-meta">
                <span class="meta-item" id="metaEmpNo"><i class="bi bi-person-badge"></i> —</span>
                <span class="meta-item" id="metaRole"><i class="bi bi-briefcase"></i> —</span>
                <span class="meta-item" id="metaType"><i class="bi bi-calendar-check"></i> —</span>
                <span class="meta-item" id="metaSchedule"><i class="bi bi-clock"></i> —</span>
            </div>
        </div>
        <div id="schedSourceWrap"></div>
    </div>

    <!-- STATS -->
    <div class="stats">
        <div class="stat">
            <div class="stat-label">Time in today</div>
            <div class="stat-val" id="sTodayIn" style="color:var(--success)">—</div>
            <div class="stat-note" id="sTodayStatus">—</div>
        </div>
        <div class="stat">
            <div class="stat-label" id="sLeaveLabel">Leave balance</div>
            <div class="stat-val" id="sLeaveVal" style="color:var(--primary)">—</div>
            <div class="stat-note" id="sLeaveSub">days remaining</div>
        </div>
        <div class="stat">
            <div class="stat-label">Absences this month</div>
            <div class="stat-val" id="sAbsent" style="color:var(--danger)">—</div>
            <div class="stat-note" id="sAbsentSub">—</div>
        </div>
        <div class="stat">
            <div class="stat-label" id="sStat4Label">Late this month</div>
            <div class="stat-val" id="sStat4Val" style="color:var(--warning)">—</div>
            <div class="stat-note" id="sStat4Sub">—</div>
        </div>
    </div>

    <div class="main-grid">
        <!-- LEFT: WEEKLY CALENDAR -->
        <div>
            <div class="week-header">
                <div class="week-nav">
                    <button class="nav-btn" onclick="changeWeek(-1)"><i class="bi bi-chevron-left"></i></button>
                    <span class="week-lbl" id="weekLabel">—</span>
                    <button class="nav-btn" onclick="changeWeek(1)"><i class="bi bi-chevron-right"></i></button>
                </div>
                <button class="today-btn" onclick="goToday()"><i class="bi bi-calendar-event"></i> Today</button>
            </div>

            <div class="week-grid" id="weekGrid"></div>

            <!-- SHIFT DETAIL -->
            <div class="info-card" id="shiftDetailCard" style="display:none">
                <div class="info-card-hd">
                    <i class="bi bi-clock-history" style="color:var(--primary)"></i> My work schedule
                    <span id="shiftSourceBadge" class="src-badge ms-2"></span>
                </div>
                <div class="detail-list" id="shiftDetailBody"></div>
            </div>
        </div>

        <!-- RIGHT: SIDEBAR -->
        <div>
            <!-- Leave balances -->
            <div class="side-card">
                <div class="side-hd">
                    <span><i class="bi bi-calendar-heart" style="color:var(--success);margin-right:5px"></i>Leave balances</span>
                    <span id="balYear" style="font-size:11px;color:var(--muted);font-weight:400"></span>
                </div>
                <div id="leaveBalBody"><div class="loading-txt">Loading...</div></div>
            </div>

            <!-- Upcoming leaves -->
            <div class="side-card">
                <div class="side-hd">
                    <span><i class="bi bi-calendar-x" style="color:var(--warning);margin-right:5px"></i>Leave requests</span>
                    <a href="?view=employee_leave" class="text-teal small">File Leave</a>
                </div>
                <div id="leaveListBody"><div class="loading-txt">Loading...</div></div>
            </div>

            <!-- Doctor: upcoming appointments count -->
            <div class="side-card" id="docApptCard" style="display:none">
                <div class="side-hd">
                    <span><i class="bi bi-person-lines-fill" style="color:var(--purple);margin-right:5px"></i>Today's appointments</span>
                </div>
                <div id="docApptBody"><div class="loading-txt">Loading...</div></div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: DOCTOR SELF-EDIT SCHEDULE -->
<div class="modal fade" id="docSelfModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title">Edit my availability</div>
                    <div class="modal-sub">Updates <code>doctors.schedule</code> — affects patient appointment booking</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="background:var(--primary-bg);border:1px solid #bfdbfe;border-radius:var(--radius-sm);padding:10px 14px;font-size:12px;margin-bottom:16px;color:#1e40af">
                    <i class="bi bi-info-circle me-1"></i>
                    Changes here update your appointment availability for patients.
                    <strong>Existing confirmed appointments are not affected.</strong>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <span style="font-size:13px;font-weight:600">Weekly availability</span>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="font-size:11px;color:var(--muted)">Slot duration:</span>
                        <select id="selfSlotDur" style="font-size:12px;padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:var(--surface)">
                            <option value="30">30 min</option>
                            <option value="20">20 min</option>
                            <option value="60">60 min</option>
                        </select>
                    </div>
                </div>

                <div id="selfDayRows"></div>
            </div>
            <div class="modal-footer">
                <div style="font-size:11px;color:var(--muted);flex:1">
                    <code>UPDATE doctors SET schedule=? WHERE user_id=? AND clinic_id=?</code>
                </div>
                <button class="btn btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-primary" onclick="saveSelfSchedule()">
                    <i class="bi bi-check-lg"></i> Save
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================================================================
// STATE
// ================================================================
let myData       = null;   // from get_my_schedule
let myLeaves     = [];
let myBalances   = [];
let weekOffset   = 0;
let todayDate    = new Date();
todayDate.setHours(0,0,0,0);

const DAYS     = ['mon','tue','wed','thu','fri','sat','sun'];
const DAY_L    = {mon:'Mon',tue:'Tue',wed:'Wed',thu:'Thu',fri:'Fri',sat:'Sat',sun:'Sun'};
const DAY_FULL = {mon:'Monday',tue:'Tuesday',wed:'Wednesday',thu:'Thursday',fri:'Friday',sat:'Saturday',sun:'Sunday'};
const AV_COLORS = [
    ['#eff6ff','#2563eb'],['#f5f3ff','#7c3aed'],['#ecfdf5','#059669'],
    ['#fef3c7','#92400e'],['#fce7f3','#831843'],['#ffedd5','#9a3412']
];

// ================================================================
// INIT
// ================================================================
document.addEventListener('DOMContentLoaded', async () => {
    await Promise.all([
        loadMySchedule(),
        loadMyLeaves(),
        loadMyBalances()
    ]);
    renderWeek();
});

// ================================================================
// API CALLS
// ================================================================
async function loadMySchedule() {
    try {
        const res  = await fetch('api/schedule.php?action=get_my_schedule');
        const data = await res.json();
        if (data.success && data.data) {
            myData = data.data;
            renderProfile();
            renderShiftDetail();
            renderStats();

            // Show doctor edit button only for Optometrist
            if (myData.role === 'Optometrist' && myData.doctor_id) {
                document.getElementById('docEditWrap').style.display = '';
                document.getElementById('docApptCard').style.display = '';
                loadDocAppointments();
            }
        }
    } catch (e) {
        console.error('loadMySchedule', e);
    }
}

async function loadMyLeaves() {
    try {
        const res  = await fetch('api/schedule.php?action=get_my_leaves');
        const data = await res.json();
        myLeaves = data.success ? (data.data || []) : [];
        renderLeaveList();
    } catch(e) { myLeaves = []; renderLeaveList(); }
}

async function loadMyBalances() {
    try {
        const res  = await fetch('api/schedule.php?action=get_my_balances');
        const data = await res.json();
        myBalances = data.success ? (data.data || []) : [];
        renderLeaveBalances();
    } catch(e) { myBalances = []; renderLeaveBalances(); }
}

async function loadDocAppointments() {
    try {
        const res  = await fetch('api/schedule.php?action=get_today_appointments');
        const data = await res.json();
        const body = document.getElementById('docApptBody');
        if (!data.success || !data.data?.length) {
            body.innerHTML = '<div class="empty-side">No appointments today</div>';
            return;
        }
        body.innerHTML = data.data.map(a => `
            <div class="leave-row">
                <div class="leave-icon-wrap" style="background:var(--purple-bg)">
                    <i class="bi bi-clock" style="color:var(--purple);font-size:15px"></i>
                </div>
                <div class="leave-info">
                    <div class="leave-type">${a.patient_name || 'Patient'}</div>
                    <div class="leave-dates">${a.slot_time} · ${a.service || 'Consultation'}</div>
                </div>
                <span class="badge" style="background:${a.status==='confirmed'?'var(--success-bg)':'var(--warning-bg)'};color:${a.status==='confirmed'?'var(--success)':'var(--warning)'}">
                    ${a.status}
                </span>
            </div>
        `).join('');
    } catch(e) {
        document.getElementById('docApptBody').innerHTML = '<div class="empty-side">Could not load appointments</div>';
    }
}

// ================================================================
// RENDER PROFILE
// ================================================================
function renderProfile() {
    if (!myData) return;
    const { name, employee_no, role, employment_type, salary_frequency,
            time_in, time_out, schedule_source } = myData;

    const init = initials(name);
    const colors = AV_COLORS[Math.abs(hashCode(name)) % AV_COLORS.length];
    document.getElementById('profileAv').textContent       = init;
    document.getElementById('profileAv').style.background = colors[0];
    document.getElementById('profileAv').style.color      = colors[1];
    document.getElementById('profileName').textContent    = name;

    document.getElementById('metaEmpNo').innerHTML    = `<i class="bi bi-person-badge"></i> ${employee_no || '—'}`;
    document.getElementById('metaRole').innerHTML     = `<i class="bi bi-briefcase"></i> ${role || '—'}`;
    document.getElementById('metaType').innerHTML     = `<i class="bi bi-calendar-check"></i> ${employment_type || '—'} · ${salary_frequency || '—'}`;
    document.getElementById('metaSchedule').innerHTML = time_in
        ? `<i class="bi bi-clock"></i> ${fmt(time_in)} – ${fmt(time_out)}`
        : `<i class="bi bi-clock"></i> No schedule`;

    // Source badge
    const srcWrap = document.getElementById('schedSourceWrap');
    if (schedule_source === 'employee') {
        srcWrap.innerHTML = `<span class="src-badge" style="background:var(--primary-bg);color:var(--primary)"><i class="bi bi-person" style="font-size:10px"></i> Personal schedule</span>`;
    } else if (schedule_source === 'position') {
        srcWrap.innerHTML = `<span class="src-badge" style="background:var(--warning-bg);color:var(--warning)"><i class="bi bi-diagram-3" style="font-size:10px"></i> Position default</span>`;
    } else {
        srcWrap.innerHTML = `<span class="src-badge" style="background:#f2f4f7;color:var(--muted)">No schedule</span>`;
    }
}

// ================================================================
// RENDER STATS
// ================================================================
function renderStats() {
    if (!myData) return;
    const { time_in_today, attendance_status, absences_this_month,
            lates_this_month, role, appointments_today } = myData;

    document.getElementById('sTodayIn').textContent     = time_in_today || '—';
    document.getElementById('sTodayStatus').textContent = attendance_status || '—';

    const totalBal = myBalances.reduce((s, b) => s + parseFloat(b.balance || 0), 0);
    document.getElementById('sLeaveVal').textContent    = totalBal.toFixed(1);
    document.getElementById('sLeaveLabel').textContent  = 'Total leave balance';
    document.getElementById('sLeaveSub').textContent    = 'days remaining (all types)';

    document.getElementById('sAbsent').textContent      = absences_this_month ?? '—';
    document.getElementById('sAbsentSub').textContent   = new Date().toLocaleString('en-US',{month:'long'}) + ' ' + new Date().getFullYear();

    if (role === 'Optometrist') {
        document.getElementById('sStat4Label').textContent = 'Appointments today';
        document.getElementById('sStat4Val').textContent   = appointments_today ?? '—';
        document.getElementById('sStat4Val').style.color   = 'var(--purple)';
        document.getElementById('sStat4Sub').textContent   = 'Scheduled patients';
    } else {
        document.getElementById('sStat4Label').textContent = 'Late this month';
        document.getElementById('sStat4Val').textContent   = lates_this_month ?? '—';
        document.getElementById('sStat4Sub').textContent   = 'Late clock-ins';
    }
}

// ================================================================
// RENDER SHIFT DETAIL
// ================================================================
function renderShiftDetail() {
    if (!myData) return;
    const card = document.getElementById('shiftDetailCard');
    const body = document.getElementById('shiftDetailBody');

    const { schedule_name, schedule_type, time_in, time_out,
            break_start, break_end, grace_period, required_work_hours,
            schedule_source, effective_from, effective_to } = myData;

    if (!time_in) {
        card.style.display = 'none';
        return;
    }
    card.style.display = '';

    const srcBadge = document.getElementById('shiftSourceBadge');
    if (schedule_source === 'employee') {
        srcBadge.style.cssText = 'background:var(--primary-bg);color:var(--primary)';
        srcBadge.textContent = 'Per-employee';
    } else {
        srcBadge.style.cssText = 'background:var(--warning-bg);color:var(--warning)';
        srcBadge.textContent = 'Position default';
    }

    const rows = [
        ['Schedule name',    schedule_name || '—'],
        ['Type',             schedule_type || '—'],
        ['Time in',          fmt(time_in)],
        ['Time out',         fmt(time_out)],
        ['Break',            (break_start && break_end) ? `${fmt(break_start)} – ${fmt(break_end)}` : '—'],
        ['Grace period',     grace_period != null ? `${grace_period} min` : '—'],
        ['Required hours',   required_work_hours != null ? `${required_work_hours} hrs/day` : '—'],
        ['Effective from',   effective_from || 'Always'],
        ['Effective to',     effective_to   || 'No end date'],
    ];

    body.innerHTML = rows.map(([label, val]) => `
        <div class="detail-item">
            <span class="detail-label">${label}</span>
            <span class="detail-val">${val}</span>
        </div>
    `).join('');
}

// ================================================================
// RENDER LEAVE BALANCES
// ================================================================
function renderLeaveBalances() {
    const body = document.getElementById('leaveBalBody');
    document.getElementById('balYear').textContent = new Date().getFullYear();

    if (!myBalances.length) {
        body.innerHTML = '<div class="empty-side">No leave balances found</div>';
        return;
    }

    body.innerHTML = myBalances.map(b => {
        const total   = parseFloat(b.total_entitled || 0);
        const used    = parseFloat(b.used || 0);
        const balance = parseFloat(b.balance || 0);
        const pct     = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
        const lowColor = balance <= 2 ? 'var(--danger)' : (balance <= 5 ? 'var(--warning)' : 'var(--primary)');

        return `
        <div class="bal-item">
            <div class="bal-top">
                <span class="bal-name">${b.type_name}</span>
                <span class="bal-nums"><strong style="color:${lowColor}">${balance}</strong> / ${total} days</span>
            </div>
            <div class="bal-bar"><div class="bal-fill" style="width:${pct}%;background:${lowColor}"></div></div>
        </div>`;
    }).join('');
}

// ================================================================
// RENDER LEAVE LIST
// ================================================================
function renderLeaveList() {
    const body = document.getElementById('leaveListBody');

    if (!myLeaves.length) {
        body.innerHTML = '<div class="empty-side">No leave requests</div>';
        return;
    }

    const iconMap = {
        'Vacation Leave':'bi-umbrella-fill|#0ea5e9',
        'Sick Leave':'bi-heart-pulse-fill|#ef4444',
        'Emergency Leave':'bi-lightning-fill|#f59e0b',
        'Maternity Leave':'bi-person-heart|#ec4899',
        'Paternity Leave':'bi-person-hearts|#8b5cf6',
    };

    body.innerHTML = myLeaves.slice(0, 5).map(l => {
        const key  = iconMap[l.type_name] || 'bi-calendar-x-fill|#667085';
        const [icon, color] = key.split('|');
        const badgeClass = l.status === 'Approved' ? 'badge-approved' : (l.status === 'Rejected' ? 'badge-rejected' : 'badge-pending');
        const startFmt = fmtDate(l.start_date);
        const endFmt   = l.end_date && l.end_date !== l.start_date ? ` – ${fmtDate(l.end_date)}` : '';

        return `
        <div class="leave-row">
            <div class="leave-icon-wrap" style="background:${color}18">
                <i class="bi ${icon}" style="color:${color};font-size:15px"></i>
            </div>
            <div class="leave-info" style="flex:1">
                <div class="leave-type">${l.type_name}</div>
                <div class="leave-dates">${startFmt}${endFmt} · ${l.number_of_days} day(s)</div>
            </div>
            <span class="badge ${badgeClass}">${l.status}</span>
        </div>`;
    }).join('');
}

// ================================================================
// WEEK CALENDAR
// ================================================================
function getWeekDates(offset) {
    const monday = new Date(todayDate);
    const day    = monday.getDay();
    const diff   = day === 0 ? -6 : 1 - day;
    monday.setDate(monday.getDate() + diff + offset * 7);
    return Array.from({length:7}, (_, i) => {
        const d = new Date(monday);
        d.setDate(monday.getDate() + i);
        return d;
    });
}

function renderWeek() {
    const dates  = getWeekDates(weekOffset);
    const start  = dates[0];
    const end    = dates[6];
    document.getElementById('weekLabel').textContent =
        `${start.toLocaleDateString('en-US',{month:'short',day:'numeric'})} – ${end.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}`;

    const sched    = myData ? parseSchedule(myData.doctor_schedule || myData.schedule) : {};
    const isDoctor = myData?.role === 'Optometrist' && myData?.doctor_id;
    const timeIn   = myData?.time_in;
    const timeOut  = myData?.time_out;

    // Build leave date set for quick lookup
    const leaveDates = new Set();
    const leaveByDate = {};
    myLeaves.forEach(l => {
        if (!l.start_date) return;
        const start = new Date(l.start_date);
        const end   = new Date(l.end_date || l.start_date);
        for (let d = new Date(start); d <= end; d.setDate(d.getDate()+1)) {
            const key = d.toISOString().split('T')[0];
            leaveDates.add(key);
            leaveByDate[key] = l;
        }
    });

    const grid = document.getElementById('weekGrid');
    grid.innerHTML = dates.map((date, i) => {
        const dayKey   = DAYS[i];
        const isToday  = date.getTime() === todayDate.getTime();
        const isPast   = date < todayDate;
        const dateStr  = date.toISOString().split('T')[0];
        const hasLeave = leaveDates.has(dateStr);
        const leave    = leaveByDate[dateStr];

        let cls = 'day-card';
        if (isToday) cls += ' today';
        else if (isPast) cls += ' past';

        // Doctor schedule from doctors.schedule JSON
        let shiftHtml = '';
        if (isDoctor && sched[dayKey]?.length) {
            sched[dayKey].forEach(period => {
                shiftHtml += `
                <div class="shift-block doc">
                    <div class="shift-time">${period}</div>
                    <div class="shift-sub">Available</div>
                </div>`;
            });
        } else if (!isDoctor && timeIn) {
            // Non-doctor: show shift block on weekdays
            const isWeekend = i >= 5;
            if (!isWeekend) {
                shiftHtml = `
                <div class="shift-block emp">
                    <div class="shift-time">${fmt(timeIn)} – ${fmt(timeOut)}</div>
                    <div class="shift-sub">Work shift</div>
                </div>`;
            } else {
                shiftHtml = `<div class="rest-txt"><i class="bi bi-moon-stars"></i> Rest day</div>`;
                cls += ' rest';
            }
        } else if (!isDoctor && !timeIn) {
            shiftHtml = `<div class="rest-txt" style="color:var(--danger)">No schedule</div>`;
        }

        // Leave overlay
        let leaveHtml = '';
        if (hasLeave && leave) {
            const lc = leave.status === 'Approved' ? 'leave-approved' : 'leave-pending';
            leaveHtml = `<span class="leave-tag ${lc}">${leave.status === 'Approved' ? '✓ ' : '⏳ '}${leave.type_name || 'Leave'}</span>`;
        }

        return `
        <div class="${cls}">
            <div class="day-hd">
                <span class="day-name">${DAY_L[dayKey]}</span>
                <span class="day-num">${date.getDate()}</span>
                ${isToday ? '<span class="today-dot"></span>' : ''}
            </div>
            <div class="day-body">
                ${shiftHtml}
                ${leaveHtml}
            </div>
        </div>`;
    }).join('');
}

function changeWeek(dir) { weekOffset += dir; renderWeek(); }
function goToday()        { weekOffset = 0;   renderWeek(); }

// ================================================================
// DOCTOR SELF-EDIT MODAL
// ================================================================
function openDocSelfEdit() {
    const sched = myData ? parseSchedule(myData.doctor_schedule) : {};
    buildSelfDayRows(sched);
    new bootstrap.Modal(document.getElementById('docSelfModal')).show();
}

function buildSelfDayRows(sched) {
    const container = document.getElementById('selfDayRows');
    container.innerHTML = '';
    DAYS.forEach(day => {
        const periods  = sched[day] || [];
        const isActive = periods.length > 0;
        const amStart  = periods[0] ? periods[0].split('-')[0] : '09:00';
        const amEnd    = periods[0] ? periods[0].split('-')[1] : '12:00';
        const hasPM    = periods.length > 1;
        const pmStart  = periods[1] ? periods[1].split('-')[0] : '13:00';
        const pmEnd    = periods[1] ? periods[1].split('-')[1] : '17:00';

        const div = document.createElement('div');
        div.className = `day-row${isActive?' on':''}`;
        div.id = `sr_${day}`;
        div.innerHTML = `
            <div class="day-check">
                <input type="checkbox" id="sc_${day}" ${isActive?'checked':''} onchange="toggleSelfDay('${day}')">
                <label for="sc_${day}">${DAY_FULL[day]}</label>
            </div>
            <div class="day-inputs" id="si_${day}" style="display:${isActive?'flex':'none'}">
                <div class="ti"><label>AM start</label><input type="time" id="sas_${day}" value="${amStart}"></div>
                <div class="ti"><label>AM end</label><input type="time" id="sae_${day}" value="${amEnd}"></div>
                <label class="pm-chk">
                    <input type="checkbox" id="spm_${day}" ${hasPM?'checked':''} onchange="toggleSelfPM('${day}')">
                    PM session
                </label>
                <div id="spi_${day}" style="display:${hasPM?'flex':'none'};gap:8px">
                    <div class="ti"><label>PM start</label><input type="time" id="sps_${day}" value="${pmStart}"></div>
                    <div class="ti"><label>PM end</label><input type="time" id="spe_${day}" value="${pmEnd}"></div>
                </div>
            </div>
            <div class="rest-lbl" id="srl_${day}" style="display:${isActive?'none':'flex'}">
                <i class="bi bi-dash-circle"></i> Rest day
            </div>`;
        container.appendChild(div);
    });
}

function toggleSelfDay(day) {
    const on = document.getElementById(`sc_${day}`).checked;
    document.getElementById(`si_${day}`).style.display  = on ? 'flex' : 'none';
    document.getElementById(`srl_${day}`).style.display = on ? 'none' : 'flex';
    document.getElementById(`sr_${day}`).classList.toggle('on', on);
}
function toggleSelfPM(day) {
    const on = document.getElementById(`spm_${day}`).checked;
    document.getElementById(`spi_${day}`).style.display = on ? 'flex' : 'none';
}

async function saveSelfSchedule() {
    if (!myData?.doctor_id) return;

    const schedule = {};
    DAYS.forEach(day => {
        if (!document.getElementById(`sc_${day}`)?.checked) return;
        const periods = [];
        const amS = document.getElementById(`sas_${day}`)?.value;
        const amE = document.getElementById(`sae_${day}`)?.value;
        if (amS && amE) periods.push(`${amS}-${amE}`);
        if (document.getElementById(`spm_${day}`)?.checked) {
            const pmS = document.getElementById(`sps_${day}`)?.value;
            const pmE = document.getElementById(`spe_${day}`)?.value;
            if (pmS && pmE) periods.push(`${pmS}-${pmE}`);
        }
        if (periods.length) schedule[day] = periods;
    });

    if (!Object.keys(schedule).length) {
        Swal.fire('Error','Please set at least one active day.','error'); return;
    }

    const slotDur = parseInt(document.getElementById('selfSlotDur')?.value || '30');

    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });

    try {
        const res  = await fetch('api/schedule.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action:        'save_doctor_schedule',
                doctor_id:     myData.doctor_id,
                schedule:      schedule,
                slot_duration: slotDur
            })
        });
        const data = await res.json();
        Swal.close();
        if (data.success) {
            Swal.fire({ icon:'success', title:'Saved!', text: data.message, timer:2000, showConfirmButton:false });
            bootstrap.Modal.getInstance(document.getElementById('docSelfModal'))?.hide();
            // Refresh
            myData.doctor_schedule = schedule;
            renderWeek();
        } else {
            Swal.fire('Error', data.error, 'error');
        }
    } catch(e) {
        Swal.fire('Error','Network error. Please try again.','error');
    }
}

// ================================================================
// REDIRECT TO LEAVE MODULE
// ================================================================
function redirectToLeave() {
    window.location.href = 'views/employee_leave.php';
}

// ================================================================
// HELPERS
// ================================================================
function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().substring(0,2);
}
function hashCode(str) {
    let h = 0;
    for (let c of str) { h = ((h << 5) - h) + c.charCodeAt(0); h |= 0; }
    return h;
}
function fmt(t) {
    if (!t) return '—';
    return t.substring(0,5);
}
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function parseSchedule(s) {
    if (!s) return {};
    if (typeof s === 'object') return s;
    try { return JSON.parse(s); } catch(e) { return {}; }
}
</script>
</body>
</html>