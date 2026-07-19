<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

// Resolve clinic name for badge
$clinic_name = $_SESSION['clinic_name'] ?? 'EyeCore Clinic';
$user_role   = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Attendance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body { background: #f4f6f9; }
.attendance-card { max-width: 520px; }
.camera-box {
    width: 100%;
    height: 280px;
    background: #000;
    border-radius: 12px;
    overflow: hidden;
}
video, canvas { width: 100%; height: 100%; object-fit: cover; }
.action-btn { width: 100%; padding: 14px; font-size: 1.1rem; }
.status-pill { font-size: .85rem; }
.btn-teal            { background-color: #008080 !important; border-color: #008080 !important; color: #fff !important; }
.btn-teal:hover      { background-color: #006666 !important; border-color: #006666 !important; }
.btn-outline-teal    { border-color: #008080 !important; color: #008080 !important; }
.btn-outline-teal:hover { background-color: #008080 !important; color: #fff !important; }
.bg-teal  { background-color: #008080 !important; }
.text-teal { color: #008080 !important; }
</style>
</head>
<body>

<!-- Overtime quick-link (floating) -->
<a href="main.php?view=overtime_requests"
   class="btn btn-teal rounded-circle position-fixed bottom-0 end-0 m-4
          d-flex align-items-center justify-content-center"
   style="width:60px;height:60px;z-index:1000" title="File Overtime Request">
    <i class="bi bi-clock-history fs-4"></i>
</a>

<div class="container py-4">

    <div class="text-center mb-4">
        <h3 class="fw-bold">
            <i class="bi bi-calendar-check text-teal me-2"></i>Attendance System
        </h3>
        <span class="badge bg-teal text-white status-pill" id="clinicBadge">
            <?= htmlspecialchars($clinic_name) ?>
        </span>
    </div>

    <div class="card shadow attendance-card mx-auto">
        <div class="card-body">

            <div class="camera-box mb-3">
                <video id="video" autoplay playsinline></video>
                <canvas id="canvas" class="d-none"></canvas>
            </div>

            <div class="alert alert-info small d-flex align-items-center"
                 style="background:#e6f3f3;border-color:#008080;color:#004d4d">
                <i class="bi bi-geo-alt-fill me-2" style="color:#008080"></i>
                Location and photo verification required
            </div>

            <div class="d-grid gap-2 mt-2">
                <button id="btn-time-in"    class="btn btn-success  action-btn"            onclick="submitAttendance('time_in')">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Time In
                </button>
                <button id="btn-break-start" class="btn btn-warning  action-btn d-none"    onclick="submitAttendance('break_start')">
                    <i class="bi bi-clock-history me-2"></i> Start Break
                </button>
                <button id="btn-break-end"  class="btn btn-info     action-btn d-none"     onclick="submitAttendance('break_end')">
                    <i class="bi bi-clock me-2"></i> End Break
                </button>
                <button id="btn-time-out"   class="btn btn-danger   action-btn d-none"     onclick="submitAttendance('time_out')">
                    <i class="bi bi-box-arrow-left me-2"></i> Time Out
                </button>
            </div>

            <div id="status" class="text-center small mt-3 text-muted">
                Checking status…
            </div>

        </div>
    </div>

    <!-- Schedule Information Card -->
<div class="card mt-3 bg-light" id="scheduleCard" style="display: none;">
    <div class="card-body py-2">
        <div class="row text-center">
            <div class="col-6">
                <small class="text-muted">Expected Time In</small>
                <h6 class="mb-0 fw-bold text-teal" id="expectedTimeIn">--:--</h6>
            </div>
            <div class="col-6">
                <small class="text-muted">Expected Time Out</small>
                <h6 class="mb-0 fw-bold text-teal" id="expectedTimeOut">--:--</h6>
            </div>
        </div>
        <div class="row text-center mt-2">
            <div class="col-6">
                <small class="text-muted">Grace Period</small>
                <h6 class="mb-0" id="gracePeriod">-- mins</h6>
            </div>
            <div class="col-6">
                <small class="text-muted">Required Hours</small>
                <h6 class="mb-0" id="requiredHours">-- hrs</h6>
            </div>
        </div>
        <div class="text-center mt-2">
            <small class="text-muted" id="scheduleSource"></small>
        </div>
    </div>
</div>
</div>

<script>
const video      = document.getElementById('video');
const canvas     = document.getElementById('canvas');
const statusEl   = document.getElementById('status');

// ── Camera ───────────────────────────────────────────────────────────
navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    .then(stream => { video.srcObject = stream; })
    .catch(() => Swal.fire('Camera Error', 'Camera access is required for attendance.', 'error'));

// ── Geolocation ──────────────────────────────────────────────────────
function getLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject('Geolocation is not supported by this browser.');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            pos => resolve({
                lat:      pos.coords.latitude,
                lng:      pos.coords.longitude,
                accuracy: pos.coords.accuracy
            }),
            err => {
                const msgs = {
                    1: 'Location permission denied. Please enable location services.',
                    2: 'Location information unavailable.',
                    3: 'Location request timed out.'
                };
                reject(msgs[err.code] || 'Unknown location error.');
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });
}

// ── Capture photo ────────────────────────────────────────────────────
function capturePhoto() {
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    ctx.font      = '14px Arial';
    ctx.fillStyle = 'rgba(255,255,255,0.85)';
    ctx.fillRect(0, 0, 260, 26);
    ctx.fillStyle = '#333';
    ctx.fillText(new Date().toLocaleString(), 6, 18);
    return canvas.toDataURL('image/jpeg', 0.8);
}

// ── Submit attendance ────────────────────────────────────────────────
async function submitAttendance(actionType) {
    const buttons = document.querySelectorAll('.action-btn');
    buttons.forEach(b => b.disabled = true);
    setStatus('Verifying location…', 'warning');

    let location;
    try {
        location = await getLocation();
    } catch (err) {
        buttons.forEach(b => b.disabled = false);
        Swal.fire({ icon: 'warning', title: 'Location Required', text: err, confirmButtonText: 'Try Again' });
        setStatus('Location verification failed.', 'danger');
        return;
    }

    setStatus('Capturing photo…', 'muted');
    const photo = capturePhoto();

    setStatus('Submitting attendance…', 'muted');

    try {
        const res  = await fetch('api/attendance_action.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:    actionType,
                photo:     photo,
                latitude:  location.lat,
                longitude: location.lng,
                accuracy:  location.accuracy
            })
        });

        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            throw new Error('Unexpected server response. Please try again.');
        }

        const data = await res.json();

        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Success', text: data.message, timer: 3000, showConfirmButton: false });
            setStatus(data.message, 'success');
            updateButtons(data.status);
        } else {
            Swal.fire({ icon: 'error', title: 'Failed', text: data.message });
            setStatus(data.message, 'danger');
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message || 'Network error. Please check your connection.' });
        setStatus('Submission failed: ' + (err.message || 'Network error'), 'danger');
    } finally {
        buttons.forEach(b => b.disabled = false);
    }
}

// ── Update button visibility ─────────────────────────────────────────
function updateButtons(status, approvalStatus = null) {
    ['btn-time-in','btn-break-start','btn-break-end','btn-time-out']
        .forEach(id => document.getElementById(id).classList.add('d-none'));

    // Check if attendance is already approved or rejected
    if (approvalStatus === 'approved') {
        setStatus('<i class="bi bi-check-circle-fill text-success"></i> Attendance Approved! Complete logs recorded.', 'success');
        return;
    }
    if (approvalStatus === 'rejected') {
        setStatus('<i class="bi bi-x-circle-fill text-danger"></i> Attendance Rejected. Please contact HR.', 'danger');
        return;
    }

    // ✅ Get schedule info for late detection
    const expectedTimeIn = document.getElementById('expectedTimeIn').textContent;
    const currentTime = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
    
    switch (status) {
        case 'not_in':
            document.getElementById('btn-time-in').classList.remove('d-none');
            // Check if it's past expected time in
            if (expectedTimeIn !== '--:--' && currentTime > expectedTimeIn) {
                setStatus('<i class="bi bi-exclamation-triangle text-warning"></i> You are late! Please time in now.', 'warning');
            } else {
                setStatus('<i class="bi bi-person-circle"></i> Ready for Time In', 'primary');
            }
            break;
        case 'in_work':
            document.getElementById('btn-break-start').classList.remove('d-none');
            document.getElementById('btn-time-out').classList.remove('d-none');
            setStatus('<i class="bi bi-check-circle"></i> You are timed in', 'success');
            break;
        case 'on_break':
            document.getElementById('btn-break-end').classList.remove('d-none');
            document.getElementById('btn-time-out').classList.remove('d-none');
            setStatus('<i class="bi bi-clock-history"></i> On break', 'warning');
            break;
        case 'done':
            if (approvalStatus === 'pending') {
                setStatus('<i class="bi bi-hourglass-split text-warning"></i> Shift completed — Waiting for HR approval.', 'warning');
            } else {
                setStatus('<i class="bi bi-check-circle"></i> Shift completed — See you tomorrow!', 'success');
            }
            break;
        case 'on_leave':
            setStatus('<i class="bi bi-calendar-check"></i> You are on approved leave', 'info');
            break;
        default:
            document.getElementById('btn-time-in').classList.remove('d-none');
            setStatus('<i class="bi bi-person-circle"></i> Ready for Time In', 'primary');
    }
}
// ── Check status on load ─────────────────────────────────────────────
async function checkStatus() {
    setStatus('<i class="bi bi-hourglass-split"></i> Loading…', 'info');
    try {
        const res  = await fetch('api/attendance_status.php');
        const ct   = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) throw new Error('Server error loading status.');

        const data = await res.json();
        if (data.success) {
            if (data.employee?.clinic_name) {
                document.getElementById('clinicBadge').textContent = data.employee.clinic_name;
            }
            
            // ✅ ADD THIS: Display schedule information
            if (data.schedule) {
                const scheduleCard = document.getElementById('scheduleCard');
                scheduleCard.style.display = 'block';
                
                document.getElementById('expectedTimeIn').textContent = data.schedule.time_in || '--:--';
                document.getElementById('expectedTimeOut').textContent = data.schedule.time_out || '--:--';
                document.getElementById('gracePeriod').textContent = (data.schedule.grace_period || 0) + ' mins';
                document.getElementById('requiredHours').textContent = (data.schedule.required_work_hours || 0) + ' hrs';
                
                const sourceText = data.schedule.source === 'employee' ? 'Personal Schedule' : 'Position Schedule';
                document.getElementById('scheduleSource').innerHTML = `<i class="bi bi-calendar me-1"></i> ${sourceText}`;
            } else {
                document.getElementById('scheduleCard').style.display = 'none';
            }
            
            // Pass approval status to updateButtons
            updateButtons(data.status, data.approval_status);
            
            // Optional: Show approval status in status area if pending
            if (data.status === 'done' && data.approval_status === 'pending') {
                setStatus('<i class="bi bi-hourglass-split text-warning"></i> Shift completed — Waiting for HR approval.', 'warning');
            }
        } else {
            updateButtons('not_in');
            setStatus(data.message || 'Could not load status.', 'danger');
        }
    } catch (err) {
        updateButtons('not_in');
        setStatus('System temporarily unavailable.', 'warning');
    }
}
function setStatus(html, color) {
    statusEl.className = `text-center small mt-3 text-${color}`;
    statusEl.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', () => {
    checkStatus();
    setInterval(checkStatus, 30000);
});
</script>
</body>
</html>