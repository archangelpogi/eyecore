<?php
// appointments.php
include __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    try {
        switch($action) {
            case 'add':
                $patient_id = $_POST['patient_id'] ?? '';
                $clinic = $_POST['clinic'] ?? '';
                $doctor = $_POST['doctor'] ?? '';
                $date = $_POST['date'] ?? '';
                $time = $_POST['time'] ?? '';
                $type = $_POST['type'] ?? '';
                $status = $_POST['status'] ?? 'pending';
                
                // Generate appointment ID
                $apt_id = 'APT' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Get patient name
                $stmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE patient_code = ?");
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch();
                $patient_name = $patient ? $patient['first_name'] . ' ' . $patient['last_name'] : '';
                
                // Get clinic ID
                $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_name = ?");
                $stmt->execute([$clinic]);
                $clinic_data = $stmt->fetch();
                $clinic_id = $clinic_data['id'] ?? 1;
                
                // Convert status
                $db_status = match($status) {
                    'confirmed' => 'Approved',
                    'pending' => 'Pending',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    default => 'Pending'
                };
                
                $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, appointment_date, appointment_time, service_type, optometrist, status, clinic_id, notes) 
                                      VALUES ((SELECT id FROM patients WHERE patient_code = ?), ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$patient_id, $date, $time, $type, $doctor, $db_status, $clinic_id, '']);
                
                echo json_encode(['success' => true, 'message' => 'Appointment scheduled!']);
                break;
                
            case 'update':
                $status = $_POST['status'] ?? '';
                $db_status = match($status) {
                    'confirmed' => 'Approved',
                    'pending' => 'Pending',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    default => 'Pending'
                };
                
                $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->execute([$db_status, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Appointment updated!']);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Appointment deleted!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Load appointments
$search = $_GET['search'] ?? '';
$clinic_filter = $_GET['clinic'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Get clinics and patients
$clinics = $pdo->query("SELECT clinic_name FROM clinics WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);
$patients = $pdo->query("SELECT patient_code, CONCAT(first_name, ' ', last_name) as name FROM patients WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$sql = "SELECT a.*, p.first_name, p.last_name, p.patient_code, c.clinic_name 
        FROM appointments a 
        LEFT JOIN patients p ON a.patient_id = p.id 
        LEFT JOIN clinics c ON a.clinic_id = c.id 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($clinic_filter != 'all') {
    $sql .= " AND c.clinic_name = ?";
    $params[] = $clinic_filter;
}
if ($status_filter != 'all') {
    $db_status = match($status_filter) {
        'confirmed' => 'Approved',
        'pending' => 'Pending',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Pending'
    };
    $sql .= " AND a.status = ?";
    $params[] = $db_status;
}

$sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert database status to React format
foreach($appointments as &$apt) {
    $apt['status_react'] = match($apt['status']) {
        'Approved' => 'confirmed',
        'Pending' => 'pending',
        'Completed' => 'completed',
        'Cancelled' => 'cancelled',
        'No-show' => 'cancelled',
        default => 'pending'
    };
    $apt['apt_id'] = 'APT' . str_pad($apt['id'], 4, '0', STR_PAD_LEFT);
}

// Calculate stats
$totalToday = count(array_filter($appointments, fn($a) => $a['appointment_date'] == date('Y-m-d')));
$confirmed = count(array_filter($appointments, fn($a) => $a['status'] == 'Approved'));
$pending = count(array_filter($appointments, fn($a) => $a['status'] == 'Pending'));
$completed = count(array_filter($appointments, fn($a) => $a['status'] == 'Completed'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointment Oversight</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
    body{background:#f8fafc;font-family:'Segoe UI',sans-serif}
    .card-soft{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
    .icon-box{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center}
    .gradient-btn{background:linear-gradient(to right,#0d9488,#3b82f6);color:#fff;border:none;border-radius:8px;padding:10px 24px}
    .gradient-btn:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(13,148,136,0.2)}
    .badge-confirmed{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px}
    .badge-pending{background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:20px}
    .badge-completed{background:#dbeafe;color:#1e40af;padding:6px 12px;border-radius:20px}
    .badge-cancelled{background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:20px}
    .search-input{padding-left:40px;border-radius:8px}
    .patient-avatar{width:40px;height:40px;background:linear-gradient(to right,#0d9488,#3b82f6);border-radius:50%;display:flex;align-items:center;justify-content:center}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Appointment Oversight</h2>
            <p class="text-muted">Multi-clinic appointment scheduling and management</p>
        </div>
        <button class="btn gradient-btn px-4 py-2 d-flex align-items-center gap-2" onclick="openAddModal()">
            <i data-lucide="plus"></i> Schedule Appointment
        </button>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Total Today</div><div class="stats-number"><?= $totalToday ?></div></div><div class="icon-box bg-teal-100"><i data-lucide="calendar" class="text-teal-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Confirmed</div><div class="stats-number"><?= $confirmed ?></div></div><div class="icon-box bg-green-100"><i data-lucide="check-circle" class="text-green-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Pending</div><div class="stats-number"><?= $pending ?></div></div><div class="icon-box bg-yellow-100"><i data-lucide="alert-circle" class="text-yellow-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Completed</div><div class="stats-number"><?= $completed ?></div></div><div class="icon-box bg-blue-100"><i data-lucide="check-circle" class="text-blue-600"></i></div></div></div>
    </div>

    <!-- SEARCH & FILTERS -->
    <div class="card-soft p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-5 position-relative"><i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i><input type="text" id="searchInput" class="form-control search-input ps-5" placeholder="Search by patient name or ID..." value="<?= htmlspecialchars($search) ?>"></div>
            <div class="col-md-3"><select id="clinicFilter" class="form-select"><option value="all">All Clinics</option><?php foreach($clinics as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= $clinic_filter==$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select id="statusFilter" class="form-select"><option value="all">All Status</option><option value="confirmed" <?= $status_filter=='confirmed'?'selected':'' ?>>Confirmed</option><option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>Pending</option><option value="completed" <?= $status_filter=='completed'?'selected':'' ?>>Completed</option><option value="cancelled" <?= $status_filter=='cancelled'?'selected':'' ?>>Cancelled</option></select></div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()"><i data-lucide="refresh-ccw" class="me-2"></i>Reset</button></div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card-soft overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Appointment ID</th>
                        <th>Patient</th>
                        <th class="d-none d-md-table-cell">Doctor</th>
                        <th>Date & Time</th>
                        <th class="d-none d-lg-table-cell">Type</th>
                        <th>Clinic</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="appointmentsTableBody">
                    <?php if(empty($appointments)): ?>
                    <tr><td colspan="8" class="text-center py-5"><div class="d-flex flex-column align-items-center"><i data-lucide="calendar" class="text-muted mb-3" style="width:48px;height:48px"></i><h5 class="text-muted">No appointments found</h5></div></td></tr>
                    <?php else: foreach($appointments as $apt): 
                        $badge = 'badge-' . $apt['status_react'];
                        $patient_name = htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']);
                    ?>
                    <tr id="appointment-<?= $apt['id'] ?>">
                        <td class="ps-4"><span class="fw-semibold text-muted"><?= $apt['apt_id'] ?></span></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="patient-avatar"><i data-lucide="user-circle" class="text-white"></i></div>
                                <div><p class="fw-medium mb-0"><?= $patient_name ?></p><p class="text-sm text-muted"><?= htmlspecialchars($apt['patient_code']) ?></p></div>
                            </div>
                        </td>
                        <td class="d-none d-md-table-cell"><p class="text-muted"><?= htmlspecialchars($apt['optometrist']) ?></p></td>
                        <td>
                            <div class="space-y-1">
                                <div class="d-flex align-items-center gap-2 text-sm"><i data-lucide="calendar" class="text-muted" style="width:16px;height:16px"></i><?= date('M d, Y', strtotime($apt['appointment_date'])) ?></div>
                                <div class="d-flex align-items-center gap-2 text-sm text-muted"><i data-lucide="clock" class="text-muted" style="width:16px;height:16px"></i><?= date('g:i A', strtotime($apt['appointment_time'])) ?></div>
                            </div>
                        </td>
                        <td class="d-none d-lg-table-cell"><p class="text-sm text-muted"><?= htmlspecialchars($apt['service_type']) ?></p></td>
                        <td><div class="d-flex align-items-center gap-2 text-sm"><i data-lucide="building-2" class="text-muted" style="width:16px;height:16px"></i><?= htmlspecialchars($apt['clinic_name'] ?? 'N/A') ?></div></td>
                        <td><span class="<?= $badge ?>"><?= ucfirst($apt['status_react']) ?></span></td>
                        <td class="text-end pe-4">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="updateStatus(<?= $apt['id'] ?>, 'confirmed')"><i data-lucide="check" style="width:16px;height:16px"></i></button>
                                <button class="btn btn-sm btn-outline-warning" onclick="updateStatus(<?= $apt['id'] ?>, 'pending')"><i data-lucide="clock" style="width:16px;height:16px"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteAppointment(<?= $apt['id'] ?>,'<?= addslashes($patient_name) ?>')"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- PAGINATION -->
        <div class="border-top px-6 py-4">
            <div class="d-flex flex-column flex-sm-row justify-content-between gap-3">
                <p class="text-sm text-muted">Showing <span class="fw-medium"><?= count($appointments) ?></span> appointments</p>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm">Previous</button>
                    <button class="btn btn-primary btn-sm">1</button>
                    <button class="btn btn-outline-secondary btn-sm">Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL -->
<div class="modal fade" id="appointmentModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Schedule Appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="appointmentForm"><input type="hidden" name="action" value="add">
                    <div class="mb-3"><label class="form-label">Patient *</label><select name="patient_id" class="form-select" required><option value="">Select Patient</option><?php foreach($patients as $p): ?><option value="<?= htmlspecialchars($p['patient_code']) ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="form-label">Clinic *</label><select name="clinic" class="form-select" required><option value="">Select Clinic</option><?php foreach($clinics as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
                    <div class="row mb-3">
                        <div class="col-md-6"><label class="form-label">Doctor *</label><input type="text" name="doctor" class="form-control" placeholder="Dr. Name" required></div>
                        <div class="col-md-6"><label class="form-label">Service Type *</label><select name="type" class="form-select" required><option value="">Select Type</option><option value="Eye Examination">Eye Examination</option><option value="Follow-up">Follow-up</option><option value="Consultation">Consultation</option><option value="Check-up">Check-up</option></select></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6"><label class="form-label">Date *</label><input type="date" name="date" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Time *</label><input type="time" name="time" class="form-control" required></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="pending">Pending</option><option value="confirmed">Confirmed</option></select></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn gradient-btn" onclick="saveAppointment()"><span id="saveBtnText">Schedule</span><span class="spinner-border spinner-border-sm d-none"></span></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));

function showToast(msg,icon='success'){
    Swal.fire({toast:true,position:'top-end',icon,title:msg,showConfirmButton:false,timer:3000})
}

function openAddModal(){
    document.getElementById('appointmentForm').reset();
    document.getElementById('appointmentForm').querySelector('[name="action"]').value='add';
    appointmentModal.show();
}

async function saveAppointment(){
    const form=document.getElementById('appointmentForm');
    if(!form.checkValidity()) return form.reportValidity();
    
    const btn=form.querySelector('.btn.gradient-btn');
    const spinner=btn.querySelector('.spinner-border');
    const text=btn.querySelector('#saveBtnText');
    
    btn.disabled=true;
    spinner.classList.remove('d-none');
    
    try{
        const formData=new FormData(form);
        const res=await fetch('appointments.php',{method:'POST',body:formData});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success){
            appointmentModal.hide();
            location.reload();
        }
    }catch(e){
        showToast('Error saving appointment','error');
    }finally{
        btn.disabled=false;
        spinner.classList.add('d-none');
    }
}

async function updateStatus(id,status){
    try{
        const formData=new FormData();
        formData.append('action','update');
        formData.append('id',id);
        formData.append('status',status);
        
        const res=await fetch('appointments.php',{method:'POST',body:formData});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success) location.reload();
    }catch(e){
        showToast('Error updating status','error');
    }
}

async function deleteAppointment(id,name){
    const result=await Swal.fire({title:'Delete?',html:`Delete appointment for <strong>${name}</strong>?`,icon:'warning',showCancelButton:true,confirmButtonText:'Yes, delete'});
    if(result.isConfirmed){
        try{
            const formData=new FormData();
            formData.append('action','delete');
            formData.append('id',id);
            const res=await fetch('appointments.php',{method:'POST',body:formData});
            const data=await res.json();
            showToast(data.message,data.success?'success':'error');
            if(data.success) location.reload();
        }catch(e){
            showToast('Error deleting appointment','error');
        }
    }
}

function applyFilters(){
    const search=document.getElementById('searchInput').value;
    const clinic=document.getElementById('clinicFilter').value;
    const status=document.getElementById('statusFilter').value;
    let url='appointments.php?';
    if(search) url+=`search=${encodeURIComponent(search)}&`;
    if(clinic!='all') url+=`clinic=${encodeURIComponent(clinic)}&`;
    if(status!='all') url+=`status=${encodeURIComponent(status)}&`;
    window.location.href=url.replace(/[&?]$/,'');
}

function resetFilters(){
    window.location.href='appointments.php';
}

// Event listeners
document.getElementById('searchInput').addEventListener('keypress',e=>{if(e.key=='Enter')applyFilters()});
document.getElementById('clinicFilter').addEventListener('change',applyFilters);
document.getElementById('statusFilter').addEventListener('change',applyFilters);

// Set default date to today
document.addEventListener('DOMContentLoaded',()=>{
    const dateInput=document.querySelector('input[name="date"]');
    if(dateInput) dateInput.valueAsDate=new Date();
    
    // Initialize icons
    lucide.createIcons();
});
</script>
</body>
</html>