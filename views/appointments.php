<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['clinic_id'])) {
    die("Clinic not selected.");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

$clinicId = $_SESSION['clinic_id'];
$today    = date('Y-m-d');

// ============================================
// RBAC PERMISSION HELPER FUNCTIONS
// ============================================
function canViewAppointments() { return RBACHelper::hasPermission('appointments_view'); }
function canCreateAppointments() { return RBACHelper::hasPermission('appointments_create'); }
function canEditAppointments() { return RBACHelper::hasPermission('appointments_edit'); }
function canDeleteAppointments() { return RBACHelper::hasPermission('appointments_delete'); }
function canApproveAppointments() { return RBACHelper::hasPermission('appointments_approve'); }
function canRejectAppointments() { return RBACHelper::hasPermission('appointments_reject'); }

// ── Stats for today ──────────────────────────────────────────────
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*)                                          AS total,
        SUM(status = 'pending')                           AS pending,
        SUM(status = 'confirmed')                         AS confirmed,
        SUM(status = 'paid')                               AS paid,
        SUM(status = 'completed')                         AS completed,
        SUM(status = 'cancelled')                         AS cancelled,
        SUM(status = 'no-show')                           AS noshow,
        SUM(status = 'refunded')                           AS refunded
    FROM appointments
    WHERE appointment_date = ? AND clinic_id = ?
");
$stmtStats->execute([$today, $clinicId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ── PENDING APPROVAL (status = 'pending') ───────────────────────
$stmtPending = $pdo->prepare("
    SELECT a.*, 
           a.user_id, 
           a.patient_id,
           COALESCE(u.first_name, p.first_name) as first_name,
           COALESCE(u.last_name, p.last_name) as last_name,
           u.email AS user_email,
           u.contact AS user_contact,
           p.email AS patient_email,
           p.contact AS patient_contact,
           pr.name  AS product_name,
           pr.price AS product_price,
           srv.name AS service_name,
           srv.price AS service_price,
           d.name   AS doctor_name,
           d.specialty AS doctor_specialty
    FROM appointments a
    LEFT JOIN users    u  ON a.user_id    = u.id
    LEFT JOIN patients p  ON a.patient_id = p.id
    LEFT JOIN products pr ON a.product_id = pr.id
    LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
    LEFT JOIN doctors  d  ON a.doctor_id  = d.id
    WHERE a.clinic_id = ? AND a.status = 'pending'
      AND a.appointment_type = 'online'
    ORDER BY a.created_at ASC
");
$stmtPending->execute([$clinicId]);
$pendingList = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

// ── CONFIRMED (status = 'confirmed') - waiting for payment ──────
$stmtConfirmed = $pdo->prepare("
    SELECT a.*,
           u.first_name, u.last_name,
           COALESCE(p.first_name, u.first_name) as first_name,
           COALESCE(p.last_name, u.last_name) as last_name,
           u.email AS user_email,
           u.contact AS user_contact,
           pr.name  AS product_name,
           pr.price AS product_price,
           srv.name AS service_name,
           srv.price AS service_price,
           d.name   AS doctor_name,
           d.specialty AS doctor_specialty,
           pay.payment_status AS pay_status,
           pay.payment_method,
           pay.reference_number
    FROM appointments a
    LEFT JOIN users    u   ON a.user_id    = u.id
    LEFT JOIN patients p   ON a.patient_id = p.id
    LEFT JOIN products pr  ON a.product_id = pr.id
    LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
    LEFT JOIN doctors  d   ON a.doctor_id  = d.id
    LEFT JOIN payments pay ON a.id         = pay.appointment_id
    WHERE a.clinic_id = ? AND a.status = 'confirmed'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$stmtConfirmed->execute([$clinicId]);
$confirmedList = $stmtConfirmed->fetchAll(PDO::FETCH_ASSOC);

// ── PAID (status = 'paid' or 'completed') - for display ──────────────
$stmtPaid = $pdo->prepare("
    SELECT a.*,
           u.first_name, u.last_name,
           COALESCE(p.first_name, u.first_name) as first_name,
           COALESCE(p.last_name, u.last_name) as last_name,
           u.email AS user_email,
           u.contact AS user_contact,
           pr.name  AS product_name,
           pr.price AS product_price,
           srv.name AS service_name,
           srv.price AS service_price,
           d.name   AS doctor_name,
           d.specialty AS doctor_specialty,
           pay.payment_method,
           pay.reference_number,
           pay.payment_date
    FROM appointments a
    LEFT JOIN users    u   ON a.user_id    = u.id
    LEFT JOIN patients p   ON a.patient_id = p.id
    LEFT JOIN products pr  ON a.product_id = pr.id
    LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
    LEFT JOIN doctors  d   ON a.doctor_id  = d.id
    LEFT JOIN payments pay ON a.id         = pay.appointment_id
    WHERE a.clinic_id = ? AND a.status IN ('paid', 'completed')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$stmtPaid->execute([$clinicId]);
$paidList = $stmtPaid->fetchAll(PDO::FETCH_ASSOC);

// ── REFUND REQUESTS (from refund_requests table) ─────────────────
$stmtRefunds = $pdo->prepare("
    SELECT rr.*,
           a.appointment_date, a.appointment_time,
           u.first_name, u.last_name,
           u.email, u.contact,
           pr.name AS product_name,
           pr.price AS product_price,
           a2.id AS new_appointment_id,
           a2.appointment_date AS new_date,
           a2.appointment_time AS new_time
    FROM refund_requests rr
    JOIN appointments a ON rr.appointment_id = a.id
    JOIN users u ON rr.user_id = u.id
    JOIN products pr ON a.product_id = pr.id
    LEFT JOIN appointments a2 ON rr.rebook_appointment_id = a2.id
    WHERE rr.clinic_id = ? AND rr.status = 'pending'
    ORDER BY rr.created_at DESC
");
$stmtRefunds->execute([$clinicId]);
$refundList = $stmtRefunds->fetchAll(PDO::FETCH_ASSOC);

// ── Today's schedule (all statuses) ─────────────────────────────
$stmtToday = $pdo->prepare("
    SELECT a.*,
           COALESCE(
               CONCAT(u.first_name, ' ', u.last_name),
               CONCAT(p.first_name, ' ', p.last_name),
               a.service_type,
               'Walk-in Patient'
           ) AS patient_name,
           COALESCE(pr.name, srv.name, a.service_type, '—') AS item_display_name,
           pr.name AS product_name,
           srv.name AS service_name,
           d.name  AS doctor_name,
           pay.payment_status AS pay_status,
           pay.payment_method,
           a.status as appointment_status,
           a.arrived_at,
           a.consultation_started_at,
           a.consultation_completed_at
    FROM appointments a
    LEFT JOIN users    u   ON a.user_id    = u.id
    LEFT JOIN patients p   ON a.patient_id = p.id
    LEFT JOIN products pr  ON a.product_id = pr.id
    LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
    LEFT JOIN doctors  d   ON a.doctor_id  = d.id
    LEFT JOIN payments pay ON a.id         = pay.appointment_id
    WHERE a.appointment_date = ? AND a.clinic_id = ?
    ORDER BY a.appointment_time ASC
");
$stmtToday->execute([$today, $clinicId]);
$todayList = $stmtToday->fetchAll(PDO::FETCH_ASSOC);

// ── Upcoming days ────────────────────────────────────────────────
$stmtUpcoming = $pdo->prepare("
    SELECT appointment_date, COUNT(*) AS cnt
    FROM appointments
    WHERE appointment_date > ? AND clinic_id = ?
    GROUP BY appointment_date
    ORDER BY appointment_date ASC
    LIMIT 5
");
$stmtUpcoming->execute([$today, $clinicId]);
$upcomingDays = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);

// ── Doctors list for modals ──────────────────────────────────────
$stmtDoctors = $pdo->prepare("SELECT * FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name ASC");
$stmtDoctors->execute([$clinicId]);
$doctorsList = $stmtDoctors->fetchAll(PDO::FETCH_ASSOC);

function statusBadge(string $s): string {
    return match($s) {
        'pending'        => '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed'      => '<span class="badge bg-info text-dark">Confirmed</span>',
        'arrived'        => '<span class="badge bg-success">Arrived</span>',
        'in_progress'    => '<span class="badge bg-primary">In Consultation</span>',
        'waiting_payment'=> '<span class="badge bg-warning">Waiting Payment</span>',
        'paid'           => '<span class="badge bg-primary">Paid</span>',
        'completed'      => '<span class="badge bg-success">Completed</span>',
        'cancelled'      => '<span class="badge bg-danger">Cancelled</span>',
        'no-show'        => '<span class="badge bg-secondary">No-show</span>',
        'refunded'       => '<span class="badge bg-dark">Refunded</span>',
        default          => '<span class="badge bg-secondary">'.ucfirst($s).'</span>',
    };
}

function getItemTypeBadge($appt) {
    if(!empty($appt['product_name'])) {
        return '<span class="badge bg-primary">Product</span>';
    } elseif(!empty($appt['service_name']) || !empty($appt['service_type'])) {
        return '<span class="badge bg-success">Service</span>';
    }
    return '<span class="badge bg-secondary">General</span>';
}

function getItemDetails($appt) {
    $html = '';
    
    if(!empty($appt['product_name'])) {
        $html = '<div class="small mb-1">
                    <i class="bi bi-box text-primary me-1"></i>
                    <strong>Product:</strong> ' . htmlspecialchars($appt['product_name']) . '
                    <br><span class="text-success ms-4">₱' . number_format($appt['product_price'] ?? 0, 2) . '</span>
                  </div>';
    } 
    elseif(!empty($appt['service_name'])) {
        $html = '<div class="small mb-1">
                    <i class="bi bi-star text-warning me-1"></i>
                    <strong>Service:</strong> ' . htmlspecialchars($appt['service_name']) . '
                    <br><span class="text-success ms-4">₱' . number_format($appt['service_price'] ?? 0, 2) . '</span>
                  </div>';
    }
    elseif(!empty($appt['service_type'])) {
        $html = '<div class="small mb-1">
                    <i class="bi bi-star text-warning me-1"></i>
                    <strong>Service:</strong> ' . htmlspecialchars($appt['service_type']) . '
                  </div>';
    } else {
        $html = '<div class="small mb-1 text-muted">
                    <i class="bi bi-question-circle me-1"></i>
                    <em>No item specified</em>
                  </div>';
    }
    
    return $html;
}

// ── Services list for dropdown ────────────
$stmtServices = $pdo->prepare("
    SELECT id, name, price, duration_minutes as duration
    FROM services 
    WHERE clinic_id = ? 
    ORDER BY name ASC
");
$stmtServices->execute([$clinicId]);
$servicesList = $stmtServices->fetchAll(PDO::FETCH_ASSOC);

// ── Products list for dropdown ──────────────────────────────────────
$stmtProducts = $pdo->prepare("
    SELECT id, name, price 
    FROM products 
    WHERE clinic_id = ? 
    ORDER BY name ASC
");
$stmtProducts->execute([$clinicId]);
$productsList = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

// ── WARRANTY CLAIMS ──────────────────────────────────────────────
$stmtWarranty = $pdo->prepare("
    SELECT wc.*,
           u.first_name, u.last_name, u.email, u.contact,
           p.name as product_name,
           r.reservation_code,
           r.preferred_date as visit_date,
           r.preferred_time as visit_time
    FROM warranty_claims wc
    JOIN users u ON wc.user_id = u.id
    JOIN products p ON wc.product_id = p.id
    LEFT JOIN reservations r ON wc.reservation_id = r.id
    WHERE wc.clinic_id = ?
    ORDER BY wc.created_at DESC
");
$stmtWarranty->execute([$clinicId]);
$warrantyClaims = $stmtWarranty->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Scheduling</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">Appointment Scheduling</h1>
            <p class="page-subtitle text-muted">Manage clinic appointments and bookings</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-secondary btn-sm" onclick="refreshPage()">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
            <?php if (canCreateAppointments()): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAppointmentModal">
                <i class="bi bi-calendar-plus me-1"></i>New Appointment
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="row g-3 mb-4">
        <?php
        $statItems = [
            ['label'=>"Today's Total",  'val'=>$stats['total'],     'color'=>'primary',   'icon'=>'bi-calendar2-week'],
            ['label'=>'Pending',        'val'=>$stats['pending'],   'color'=>'warning',   'icon'=>'bi-hourglass-split'],
            ['label'=>'Confirmed',      'val'=>$stats['confirmed'], 'color'=>'info',      'icon'=>'bi-check-circle'],
            ['label'=>'Paid',           'val'=>$stats['paid'],      'color'=>'primary',   'icon'=>'bi-credit-card'],
            ['label'=>'Completed',      'val'=>$stats['completed'], 'color'=>'success',   'icon'=>'bi-patch-check'],
            ['label'=>'Refund Requests','val'=>count($refundList),  'color'=>'dark',      'icon'=>'bi-arrow-repeat'],
            ['label'=>'Cancelled',      'val'=>$stats['cancelled'], 'color'=>'danger',    'icon'=>'bi-x-circle'],
        ];
        foreach($statItems as $s): ?>
        <div class="col-6 col-sm-4 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3 text-center">
                    <i class="bi <?= $s['icon'] ?> fs-4 text-<?= $s['color'] ?>"></i>
                    <div class="fw-bold fs-4 mt-1 text-<?= $s['color'] ?>"><?= $s['val'] ?></div>
                    <div class="text-muted small"><?= $s['label'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabs Navigation -->
 <ul class="nav nav-tabs mb-4 flex-wrap" id="apptTabs" role="tablist" style="gap: 4px; border-bottom: 2px solid var(--border-light);">
    <!-- Today's Schedule -->
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabToday" role="tab">
            <i class="bi bi-calendar-day me-1"></i>
            Today's Schedule
        </button>
    </li>
    
    <!-- Pending Approval (with count badge) -->
    <?php if (canApproveAppointments() || canRejectAppointments()): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tabPending" role="tab">
            <i class="bi bi-hourglass-split me-1"></i>
            Pending
            <?php if(count($pendingList) > 0): ?>
                <span class="badge rounded-pill bg-danger ms-1" style="font-size: 10px;"><?= count($pendingList) ?></span>
            <?php endif; ?>
        </button>
    </li>
    <?php endif; ?>
    
    <!-- Confirmed (awaiting payment) -->
    <li class="nav-item" role="presentation">
        <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tabConfirmed" role="tab">
            <i class="bi bi-check-circle me-1"></i>
            Confirmed
            <?php if(count($confirmedList) > 0): ?>
                <span class="badge rounded-pill bg-info ms-1" style="font-size: 10px;"><?= count($confirmedList) ?></span>
            <?php endif; ?>
        </button>
    </li>
    
    <!-- Paid -->
    <li class="nav-item" role="presentation">
        <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tabPaid" role="tab">
            <i class="bi bi-credit-card me-1"></i>
            Paid
            <?php if(count($paidList) > 0): ?>
                <span class="badge rounded-pill bg-primary ms-1" style="font-size: 10px;"><?= count($paidList) ?></span>
            <?php endif; ?>
        </button>
    </li>
    
    <!-- Refund Requests -->
    <?php if (canEditAppointments()): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tabRefunds" role="tab">
            <i class="bi bi-arrow-repeat me-1"></i>
            Refunds
            <?php if(count($refundList) > 0): ?>
                <span class="badge rounded-pill bg-dark ms-1" style="font-size: 10px;"><?= count($refundList) ?></span>
            <?php endif; ?>
        </button>
    </li>
    <?php endif; ?>
    
    <!-- Warranty Claims -->
    <li class="nav-item" role="presentation">
        <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tabWarrantyClaims" role="tab">
            <i class="bi bi-shield-check me-1"></i>
            Warranty
            <span class="badge rounded-pill bg-warning ms-1" id="warrantyClaimsBadge" style="font-size: 10px;">0</span>
        </button>
    </li>
    
    <!-- Upcoming -->
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabUpcoming" role="tab">
            <i class="bi bi-calendar-week me-1"></i>
            Upcoming
        </button>
    </li>
    
    <!-- History -->
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHistory" role="tab">
            <i class="bi bi-clock-history me-1"></i>
            History
        </button>
    </li>
</ul>

    <div class="tab-content" id="apptTabsContent">

        <!-- TODAY'S SCHEDULE TAB -->
        <div class="tab-pane fade show active" id="tabToday">
            <?php if(empty($todayList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                    No appointments scheduled for today.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Patient</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Doctor</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($todayList as $a): ?>
                        <tr>
                            <td><strong><?= date('h:i A', strtotime($a['appointment_time'])) ?></strong></td>
                            <td><?= htmlspecialchars($a['patient_name']) ?></td>
                            <td>
                                <?php if(!empty($a['product_name'])): ?>
                                    <?= htmlspecialchars($a['product_name']) ?>
                                <?php elseif(!empty($a['service_name'])): ?>
                                    <?= htmlspecialchars($a['service_name']) ?>
                                <?php elseif(!empty($a['service_type'])): ?>
                                    <?= htmlspecialchars($a['service_type']) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= getItemTypeBadge($a) ?></td>
                            <td><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                            <td><?= statusBadge($a['appointment_status'] ?? $a['status']) ?></td>
                            <td>
                                <?php if($a['pay_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif($a['status'] === 'confirmed'): ?>
                                    <span class="badge bg-warning text-dark">Awaiting Payment</span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($a['status'] === 'paid' && canEditAppointments()): ?>
                                    <button class="btn btn-sm btn-success me-1"
                                        onclick="updateStatus(<?= $a['id'] ?>, <?= (int)($a['user_id'] ?? 0) ?>, 'completed')">
                                        <i class="bi bi-patch-check"></i> Complete
                                    </button>
                                <?php elseif($a['status'] === 'confirmed' && canEditAppointments()): ?>
                                    <button class="btn btn-sm btn-success me-1"
                                        onclick="markArrived(<?= $a['id'] ?>, <?= (int)($a['user_id'] ?? 0) ?>, <?= (int)($a['patient_id'] ?? 0) ?>)">
                                        <i class="bi bi-door-open me-1"></i> Arrive
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                        onclick="updateStatus(<?= $a['id'] ?>, <?= (int)($a['user_id'] ?? 0) ?>, 'no-show')">
                                        No-show
                                    </button>
                                <?php elseif($a['status'] === 'waiting_payment' && canEditAppointments()): ?>
                                    <button class="btn btn-sm btn-warning me-1"
                                        onclick="goToSalesBilling(<?= $a['id'] ?>)">
                                        <i class="bi bi-credit-card"></i> Process Payment
                                    </button>
                                <?php elseif(in_array($a['status'], ['completed','cancelled','no-show','refunded'])): ?>
                                    <span class="text-muted small">Done</span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- PENDING APPROVAL TAB -->
        <?php if (canApproveAppointments() || canRejectAppointments()): ?>
        <div class="tab-pane fade" id="tabPending">
            <?php if(empty($pendingList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    No pending appointments.
                </div>
            <?php else: ?>
            <div class="row g-3">
            <?php foreach($pendingList as $a): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card shadow-sm border-start border-warning border-3 h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold">
                                        <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?>
                                    </h6>
                                    <div class="text-muted small"><?= htmlspecialchars($a['user_email'] ?? '') ?></div>
                                </div>
                                <?= statusBadge($a['status']) ?>
                            </div>
                            <hr class="my-2">
                            <div class="small mb-1">
                                <i class="bi bi-calendar text-primary me-1"></i>
                                <?= date('F j, Y', strtotime($a['appointment_date'])) ?>
                                &nbsp;
                                <i class="bi bi-clock text-primary ms-2 me-1"></i>
                                <?= date('h:i A', strtotime($a['appointment_time'])) ?>
                            </div>
                            <?= getItemTypeBadge($a) ?>
                            <?= getItemDetails($a) ?>
                            <div class="small mb-1">
                                <i class="bi bi-person-badge text-primary me-1"></i>
                                <strong>Doctor:</strong> 
                                <?= $a['doctor_name'] ? 'Dr. '.htmlspecialchars($a['doctor_name']) : '<em>Any available doctor</em>' ?>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent d-flex gap-2">
                            <?php if(canApproveAppointments()): ?>
                                <button class="btn btn-success btn-sm flex-fill"
                                    onclick="approveAppointment(<?= $a['id'] ?>, <?= (int)($a['user_id'] ?? 0) ?>, <?= (int)($a['patient_id'] ?? 0) ?>)">
                                    <i class="bi bi-check-lg me-1"></i>Approve
                                </button>
                            <?php endif; ?>
                            <?php if(canRejectAppointments()): ?>
                                <button class="btn btn-outline-danger btn-sm flex-fill"
                                    onclick="rejectModal(<?= $a['id'] ?>, <?= (int)$a['user_id'] ?>)">
                                    <i class="bi bi-x-lg me-1"></i>Reject
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- CONFIRMED TAB -->
        <div class="tab-pane fade" id="tabConfirmed">
            <?php if(empty($confirmedList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-check-circle fs-1 d-block mb-2"></i>
                    No confirmed appointments waiting for payment.
                </div>
            <?php else: ?>
            <div class="row g-3">
            <?php foreach($confirmedList as $a): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card shadow-sm border-start border-info border-3 h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold">
                                        <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?>
                                    </h6>
                                    <div class="text-muted small"><?= htmlspecialchars($a['user_email'] ?? '') ?></div>
                                </div>
                                <span class="badge bg-info text-dark">Confirmed</span>
                            </div>
                            <hr class="my-2">
                            <div class="small mb-1">
                                <i class="bi bi-calendar text-primary me-1"></i>
                                <?= date('F j, Y', strtotime($a['appointment_date'])) ?>
                                &nbsp;
                                <i class="bi bi-clock text-primary ms-2 me-1"></i>
                                <?= date('h:i A', strtotime($a['appointment_time'])) ?>
                            </div>
                            <?= getItemTypeBadge($a) ?>
                            <?= getItemDetails($a) ?>
                        </div>
                        <div class="card-footer bg-transparent">
                            <span class="text-muted small">Waiting for payment</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- PAID TAB -->
        <div class="tab-pane fade" id="tabPaid">
            <?php if(empty($paidList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-credit-card fs-1 d-block mb-2"></i>
                    No paid appointments.
                </div>
            <?php else: ?>
            <div class="row g-3">
            <?php foreach($paidList as $a): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card shadow-sm border-start border-success border-3 h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold">
                                        <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?>
                                    </h6>
                                    <div class="text-muted small"><?= htmlspecialchars($a['user_email'] ?? '') ?></div>
                                </div>
                                <span class="badge bg-success">Paid</span>
                            </div>
                            <hr class="my-2">
                            <div class="small mb-2">
                                <i class="bi bi-calendar text-primary me-1"></i>
                                <strong><?= date('F j, Y', strtotime($a['appointment_date'])) ?></strong>
                            </div>
                            <?= getItemTypeBadge($a) ?>
                            <?= getItemDetails($a) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- REFUND REQUESTS TAB -->
        <?php if (canEditAppointments()): ?>
        <div class="tab-pane fade" id="tabRefunds">
            <?php if(empty($refundList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-arrow-repeat fs-1 d-block mb-2"></i>
                    No refund requests.
                </div>
            <?php else: ?>
            <div class="row g-3">
            <?php foreach($refundList as $r): ?>
                <div class="col-12 col-md-6">
                    <div class="card shadow-sm border-start border-dark border-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold">
                                        <?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?>
                                    </h6>
                                    <div class="text-muted small"><?= htmlspecialchars($r['email']) ?></div>
                                </div>
                                <span class="badge bg-<?= $r['request_type'] === 'refund' ? 'danger' : 'info' ?>">
                                    <?= ucfirst($r['request_type']) ?>
                                </span>
                            </div>
                            <hr>
                            <div class="small mb-2">
                                <i class="bi bi-calendar-x text-danger me-1"></i>
                                Missed: <?= date('F j, Y', strtotime($r['appointment_date'])) ?> at <?= date('g:i A', strtotime($r['appointment_time'])) ?>
                            </div>
                            <div class="small mb-2">
                                <i class="bi bi-box text-primary me-1"></i>
                                <?= htmlspecialchars($r['product_name']) ?> - ₱<?= number_format($r['product_price'], 2) ?>
                            </div>
                            <div class="small mb-2">
                                <i class="bi bi-chat-left-text text-info me-1"></i>
                                <strong>Reason:</strong> <?= htmlspecialchars($r['reason']) ?>
                            </div>
                            <div class="mt-3">
                                <textarea class="form-control form-control-sm mb-2" id="adminNotes-<?= $r['id'] ?>" 
                                          placeholder="Admin notes (optional)" rows="2"></textarea>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-success btn-sm flex-fill" 
                                        onclick="processRefund(<?= $r['id'] ?>, <?= $r['user_id'] ?>, 'approve', document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                        <i class="bi bi-check-circle me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm flex-fill" 
                                        onclick="processRefund(<?= $r['id'] ?>, <?= $r['user_id'] ?>, 'reject', document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                        <i class="bi bi-x-circle me-1"></i>Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- WARRANTY CLAIMS TAB -->
<div class="tab-pane fade" id="tabWarrantyClaims">
    <div class="card">
        <div class="card-header bg-light">
            <div class="row g-2">
                <div class="col-md-4">
                    <input type="text" class="form-control" id="warrantySearch" placeholder="Search by claim #, customer, product...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="warrantyStatusFilter">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="reviewing">Reviewing</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="date" class="form-control" id="warrantyDateFrom" placeholder="From Date">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" id="warrantyDateTo" placeholder="To Date">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Claim #</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Issue</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="warrantyClaimsTableBody">
                        <tr><td colspan="8" class="text-center py-4">Loading warranty claims...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

        <!-- UPCOMING DAYS TAB -->
        <div class="tab-pane fade" id="tabUpcoming">
            <?php if(empty($upcomingDays)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                    No upcoming appointments.
                </div>
            <?php else: ?>
            <div class="row g-3">
            <?php foreach($upcomingDays as $day): ?>
                <div class="col-12 col-sm-6 col-lg-4">
                    <div class="card shadow-sm">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold"><?= date('F j, Y', strtotime($day['appointment_date'])) ?></div>
                                <div class="text-muted small"><?= date('l', strtotime($day['appointment_date'])) ?></div>
                            </div>
                            <span class="badge bg-primary rounded-pill fs-6"><?= $day['cnt'] ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- HISTORY TAB -->
        <div class="tab-pane fade" id="tabHistory">
            <div class="card">
                <div class="card-header bg-light">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" class="form-control" id="historySearch" placeholder="Search patient...">
                        </div>
                        <div class="col-md-3">
                            <input type="date" class="form-control" id="historyDateFrom" placeholder="From Date">
                        </div>
                        <div class="col-md-3">
                            <input type="date" class="form-control" id="historyDateTo" placeholder="To Date">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="historyStatus">
                                <option value="">All Status</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="no-show">No-show</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="historyTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Patient</th>
                                    <th>Service/Product</th>
                                    <th>Doctor</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr><td colspan="7" class="text-center py-4">Loading history...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Appointment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Please provide a reason so the patient is notified.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejectReason" rows="3"
                                  placeholder="e.g. Slot already filled, Doctor unavailable..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmReject()">
                        <i class="bi bi-x-circle me-1"></i>Confirm Reject
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="newAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="newAppointmentForm">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>New Appointment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Patient</label>
                            <select class="form-select" name="patient_id" required>
                                <option value="" disabled selected>Select Patient</option>
                                <?php
                                $stmtPat = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS fullname FROM patients WHERE clinic_id=? ORDER BY first_name ASC");
                                $stmtPat->execute([$clinicId]);
                                while($p = $stmtPat->fetch(PDO::FETCH_ASSOC)):
                                ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['fullname']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Doctor</label>
                            <select class="form-select" name="doctor_id" required>
                                <option value="" disabled selected>Select Doctor</option>
                                <?php foreach($doctorsList as $doc): ?>
                                    <option value="<?= $doc['id'] ?>">
                                        <?= htmlspecialchars($doc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Service / Product</label>
                            <select class="form-select" name="item_id" id="itemSelect" required>
                                <option value="" disabled selected>Select Service or Product</option>
                                <?php if(!empty($servicesList)): ?>
                                <optgroup label="Services">
                                    <?php foreach($servicesList as $service): ?>
                                    <option value="<?= $service['id'] ?>" data-type="service" data-name="<?= htmlspecialchars($service['name']) ?>" data-price="<?= $service['price'] ?>">
                                        <?= htmlspecialchars($service['name']) ?> - ₱<?= number_format($service['price'], 2) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                                <?php if(!empty($productsList)): ?>
                                <optgroup label="Products">
                                    <?php foreach($productsList as $product): ?>
                                    <option value="<?= $product['id'] ?>" data-type="product" data-name="<?= htmlspecialchars($product['name']) ?>" data-price="<?= $product['price'] ?>">
                                        <?= htmlspecialchars($product['name']) ?> - ₱<?= number_format($product['price'], 2) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="item_type" id="itemType">
                            <input type="hidden" name="service_type" id="serviceTypeName">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Date</label>
                                <input type="date" class="form-control" name="appointment_date"
                                       value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Time</label>
                                <input type="time" class="form-control" name="appointment_time" required>
                            </div>
                        </div>
                        <div class="mb-3 mt-2">
                            <label class="form-label fw-semibold">Notes (optional)</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Review Warranty Claim Modal -->
<div class="modal fade" id="reviewWarrantyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #00B761, #00A86B); color: white;">
                <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Warranty Claim</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- INFO SECTION -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Claim #:</strong> <span id="review_claim_number"></span></p>
                        <p><strong>Customer:</strong> <span id="review_customer_name"></span></p>
                        <p><strong>Contact:</strong> <span id="review_customer_contact"></span></p>
                        <p><strong>Email:</strong> <span id="review_customer_email"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Product:</strong> <span id="review_product_name"></span></p>
                        <p><strong>Reservation #:</strong> <span id="review_reservation_id"></span></p>
                        <p><strong>Submitted:</strong> <span id="review_submitted_date"></span></p>
                        <p><strong>Status:</strong> <span id="review_current_status"></span></p>
                    </div>
                </div>

                <div class="alert alert-info">
                    <strong>Schedule:</strong> <span id="review_schedule"></span>
                </div>

                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <strong><i class="bi bi-chat-text"></i> Issue Description</strong>
                    </div>
                    <div class="card-body">
                        <p id="review_description"></p>
                        <div id="review_photos" class="d-flex gap-2 flex-wrap mt-2"></div>
                    </div>
                </div>

                <!-- PENDING FORM — approve or reject -->
                <div id="form_pending" style="display:none;">
                    <hr>
                    <h6 class="fw-bold mb-3">Clinic Decision</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Offer Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="claim_resolution">
                                <option value="repair">🔧 Repair</option>
                                <option value="replacement">📦 Replacement</option>
                                <option value="store_credit">💳 Store Credit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Visit Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="claim_schedule_date" 
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes (optional)</label>
                            <textarea class="form-control" id="claim_resolution_notes" rows="2" 
                                      placeholder="Additional notes for the customer..."></textarea>
                        </div>
                    </div>

                    <!-- REJECT REASON — hidden by default -->
                    <div id="reject_reason_box" class="mt-3" style="display:none;">
                        <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="claim_rejection_reason" rows="2" 
                                  placeholder="Bakit hindi ma-approve ang claim?"></textarea>
                    </div>
                </div>

                <!-- APPROVED INFO — show schedule -->
                <div id="form_approved" style="display:none;">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        This claim is <strong>approved</strong>. Waiting for patient to arrive on 
                        <strong><span id="approved_schedule_display"></span></strong>.
                    </div>
                </div>

                <!-- ARRIVED INFO -->
                <div id="form_arrived" style="display:none;">
                    <div class="alert alert-primary">
                        <i class="bi bi-person-check me-2"></i>
                        Patient has <strong>arrived</strong>. Mark as completed once done.
                    </div>
                </div>

                <!-- COMPLETED INFO -->
                <div id="form_completed" style="display:none;">
                    <div class="alert alert-success">
                        <i class="bi bi-patch-check me-2"></i>
                        This warranty claim has been <strong>completed</strong>.
                    </div>
                </div>

                <!-- REJECTED INFO -->
                <div id="form_rejected" style="display:none;">
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle me-2"></i>
                        This claim was <strong>rejected</strong>.
                        <div class="mt-2"><strong>Reason:</strong> <span id="rejected_reason_display"></span></div>
                    </div>
                </div>

            </div>
            <div class="modal-footer" id="modal_footer_buttons">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <!-- Buttons inserted dynamically based on status -->
            </div>
        </div>
    </div>
</div>

<script>
// Pass permissions to JavaScript
const userPermissions = {
    view: <?= json_encode(canViewAppointments()) ?>,
    create: <?= json_encode(canCreateAppointments()) ?>,
    edit: <?= json_encode(canEditAppointments()) ?>,
    delete: <?= json_encode(canDeleteAppointments()) ?>,
    approve: <?= json_encode(canApproveAppointments()) ?>,
    reject: <?= json_encode(canRejectAppointments()) ?>
};

function canView() { return userPermissions.view; }
function canCreate() { return userPermissions.create; }
function canEdit() { return userPermissions.edit; }
function canDelete() { return userPermissions.delete; }
function canApprove() { return userPermissions.approve; }
function canReject() { return userPermissions.reject; }

const api = (url, opts) => fetch(url, opts).then(r => r.json());

function refreshPage() { location.reload(); }

// MARK ARRIVED FUNCTION - FIXED
function markArrived(appointmentId, userId, patientId) {
    console.log('markArrived called:', {appointmentId, userId, patientId});
    
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to mark patient arrival', 'error');
        return;
    }
    
    if (!appointmentId || appointmentId === 0) {
        Swal.fire('Error', 'Missing appointment ID', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Patient Arrived?',
        text: 'Mark this patient as arrived. They will now appear in the Consultation Module.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Mark as Arrived'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ 
                title: 'Processing...', 
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_arrived',
                    appointment_id: parseInt(appointmentId),
                    user_id: parseInt(userId || 0),
                    patient_id: parseInt(patientId || 0)
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Patient Marked as Arrived!', 
                        text: 'Patient is now ready for consultation.',
                        timer: 2000, 
                        showConfirmButton: false 
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: data.message || 'Failed to mark patient as arrived' 
                    });
                }
            })
            .catch(err => {
                console.error('Error:', err);
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Network Error', 
                    text: 'Please try again.' 
                });
            });
        }
    });
}

function approveAppointment(appointmentId, userId, patientId) {
    if (!canApprove()) {
        Swal.fire('Access Denied', 'You do not have permission to approve appointments', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Approve Appointment?',
        text: 'Are you sure you want to approve this appointment?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Approve'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'approve',
                    appointment_id: parseInt(appointmentId),
                    user_id: parseInt(userId || 0),
                    patient_id: parseInt(patientId || 0)
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Approved!', text: data.message, timer: 2000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            });
        }
    });
}

let _rejectId = null, _rejectUserId = null;

function rejectModal(appointmentId, userId) {
    if (!canReject()) {
        Swal.fire('Access Denied', 'You do not have permission to reject appointments', 'error');
        return;
    }
    _rejectId = appointmentId;
    _rejectUserId = userId;
    document.getElementById('rejectReason').value = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function confirmReject() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        Swal.fire({ icon: 'warning', title: 'Reason required', text: 'Please enter a rejection reason.' });
        return;
    }
    
    Swal.fire({ title: 'Rejecting…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
    
    api('api/appointments.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reject',
            appointment_id: _rejectId,
            user_id: _rejectUserId,
            reason: reason
        })
    }).then(d => {
        bootstrap.Modal.getInstance(document.getElementById('rejectModal'))?.hide();
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Rejected', text: 'Patient has been notified.', timer: 2000, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: d.message });
        }
    });
}

function updateStatus(appointmentId, userId, newStatus) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to update appointment status', 'error');
        return;
    }
    
    const labels = { completed: 'Complete', 'no-show': 'mark as No-show' };
    Swal.fire({
        title: `Mark as ${labels[newStatus] ?? newStatus}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Confirm'
    }).then(res => {
        if (!res.isConfirmed) return;
        
        Swal.fire({ title: 'Updating…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        
        api('api/appointments.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'update_status', 
                appointment_id: appointmentId,
                user_id: userId,
                status: newStatus 
            })
        }).then(d => {
            if (d.success) {
                Swal.fire({ icon: 'success', title: 'Updated!', timer: 1500, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: d.message });
            }
        });
    });
}

function processRefund(refundId, userId, action, adminNotes) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to process refunds', 'error');
        return;
    }
    
    const isApprove = action === 'approve';
    
    Swal.fire({
        title: isApprove ? 'Approve Refund?' : 'Reject Refund?',
        text: isApprove ? 'This will mark the appointment as refunded.' : 'Reject this refund request?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: isApprove ? 'Yes, approve' : 'Yes, reject'
    }).then((result) => {
        if (result.isConfirmed) {
            api('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: action,
                    admin_notes: adminNotes
                })
            }).then(d => {
                if (d.success) {
                    Swal.fire({ icon: 'success', title: isApprove ? 'Approved!' : 'Rejected', text: d.message, timer: 2000 })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: d.message });
                }
            });
        }
    });
}

function goToSalesBilling(appointmentId) {
    window.location.href = `main.php?view=sales&appointment_id=${appointmentId}`;
}

function loadAppointmentHistory() {
    const search = document.getElementById('historySearch')?.value || '';
    const fromDate = document.getElementById('historyDateFrom')?.value || '';
    const toDate = document.getElementById('historyDateTo')?.value || '';
    const status = document.getElementById('historyStatus')?.value || '';
    
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Loading...</td></tr>';
    
    fetch(`api/appointments.php?action=history&search=${encodeURIComponent(search)}&from=${fromDate}&to=${toDate}&status=${status}`)
        .then(r => r.json())
        .then(data => {
            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">No appointment history found</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(a => `
                <tr>
                    <td>${new Date(a.appointment_date).toLocaleDateString()}</td>
                    <td>${a.appointment_time || '—'}</td>
                    <td><strong>${escapeHtml(a.patient_name)}</strong></td>
                    <td>${escapeHtml(a.item_name || '—')}</td>
                    <td>${escapeHtml(a.doctor_name || '—')}</td>
                    <td>${getStatusBadge(a.status)}</td>
                    <td class="text-end">₱${parseFloat(a.total_amount || 0).toLocaleString()}</td>
                </tr>
            `).join('');
        })
        .catch(error => {
            console.error('Error:', error);
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger">Error loading history</td></tr>';
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed': '<span class="badge bg-info text-dark">Confirmed</span>',
        'paid': '<span class="badge bg-primary">Paid</span>',
        'completed': '<span class="badge bg-success">Completed</span>',
        'cancelled': '<span class="badge bg-danger">Cancelled</span>',
        'no-show': '<span class="badge bg-secondary">No-show</span>',
        'refunded': '<span class="badge bg-dark">Refunded</span>'
    };
    return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
}

document.addEventListener('DOMContentLoaded', function() {
    
    // ========================================
    // 1. HISTORY TAB LISTENER
    // ========================================
    const historyTabBtn = document.querySelector('button[data-bs-target="#tabHistory"]');
    if (historyTabBtn) {
        historyTabBtn.addEventListener('shown.bs.tab', function() {
            loadAppointmentHistory();
        });
    }
    
    // ========================================
    // 2. HISTORY FILTER LISTENERS
    // ========================================
    const historySearch = document.getElementById('historySearch');
    const historyDateFrom = document.getElementById('historyDateFrom');
    const historyDateTo = document.getElementById('historyDateTo');
    const historyStatus = document.getElementById('historyStatus');
    
    if (historySearch) historySearch.addEventListener('keyup', loadAppointmentHistory);
    if (historyDateFrom) historyDateFrom.addEventListener('change', loadAppointmentHistory);
    if (historyDateTo) historyDateTo.addEventListener('change', loadAppointmentHistory);
    if (historyStatus) historyStatus.addEventListener('change', loadAppointmentHistory);
    
    // ========================================
    // 3. WARRANTY CLAIMS TAB LISTENER
    // ========================================
    const warrantyTabBtn = document.querySelector('button[data-bs-target="#tabWarrantyClaims"]');
    if (warrantyTabBtn) {
        warrantyTabBtn.addEventListener('shown.bs.tab', function() {
            loadWarrantyClaims();
        });
    }
    
    // ========================================
    // 4. WARRANTY CLAIMS FILTER LISTENERS
    // ========================================
    const warrantySearch = document.getElementById('warrantySearch');
    const warrantyStatusFilter = document.getElementById('warrantyStatusFilter');
    const warrantyDateFrom = document.getElementById('warrantyDateFrom');
    const warrantyDateTo = document.getElementById('warrantyDateTo');
    
    if (warrantySearch) warrantySearch.addEventListener('keyup', loadWarrantyClaims);
    if (warrantyStatusFilter) warrantyStatusFilter.addEventListener('change', loadWarrantyClaims);
    if (warrantyDateFrom) warrantyDateFrom.addEventListener('change', loadWarrantyClaims);
    if (warrantyDateTo) warrantyDateTo.addEventListener('change', loadWarrantyClaims);
    
    // ========================================
    // 5. CLAIM DECISION CHANGE LISTENER
    // ========================================
    const claimDecision = document.getElementById('claim_decision');
    if (claimDecision) {
        claimDecision.addEventListener('change', function() {
            const resolutionBox = document.getElementById('resolution_box');
            const rejectionBox = document.getElementById('rejection_box');
            
            if (this.value === 'rejected') {
                if (resolutionBox) resolutionBox.style.display = 'none';
                if (rejectionBox) rejectionBox.style.display = 'block';
            } else if (this.value === 'approved' || this.value === 'repair') {
                if (resolutionBox) resolutionBox.style.display = 'block';
                if (rejectionBox) rejectionBox.style.display = 'none';
            } else {
                if (resolutionBox) resolutionBox.style.display = 'none';
                if (rejectionBox) rejectionBox.style.display = 'none';
            }
        });
    }
    
    // ========================================
    // 6. NEW APPOINTMENT FORM SUBMIT
    // ========================================
    const newAppointmentForm = document.getElementById('newAppointmentForm');
    if (newAppointmentForm) {
        newAppointmentForm.addEventListener('submit', function(e) {
            if (!canCreate()) {
                Swal.fire('Access Denied', 'You do not have permission to create appointments', 'error');
                e.preventDefault();
                return;
            }
            e.preventDefault();
            
            const formData = new FormData(e.target);
            const itemId = formData.get('item_id');
            const itemType = formData.get('item_type');
            
            if (!itemId) {
                Swal.fire('Error', 'Please select a service or product', 'error');
                return;
            }
            
            const data = {
                patient_id: formData.get('patient_id'),
                doctor_id: formData.get('doctor_id'),
                item_id: itemId,
                item_type: itemType,
                service_type: formData.get('service_type'),
                appointment_date: formData.get('appointment_date'),
                appointment_time: formData.get('appointment_time'),
                notes: formData.get('notes'),
                walk_in: true
            };
            
            console.log('Creating appointment:', data);
            
            Swal.fire({ 
                title: 'Saving…', 
                didOpen: () => Swal.showLoading(), 
                allowOutsideClick: false 
            });
            
            api('api/appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(d => {
                if (d.success) {
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Appointment Added!', 
                        timer: 2000, 
                        showConfirmButton: false 
                    }).then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: d.message });
                }
            }).catch(error => {
                console.error('Fetch error:', error);
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.' });
            });
        });
    }
    
    // ========================================
    // 7. ITEM SELECT HANDLER
    // ========================================
    const itemSelect = document.getElementById('itemSelect');
    const itemTypeInput = document.getElementById('itemType');
    const serviceTypeNameInput = document.getElementById('serviceTypeName');
    
    if (itemSelect) {
        itemSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const itemType = selectedOption.getAttribute('data-type');
            const itemName = selectedOption.getAttribute('data-name');
            
            if (itemTypeInput) itemTypeInput.value = itemType;
            if (serviceTypeNameInput) serviceTypeNameInput.value = itemName;
        });
    }
    
    // ========================================
    // 8. THEME CHECK (kung may theme switcher)
    // ========================================
    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        document.documentElement.classList.add('theme-dark');
    }
});
// ============================================
// WARRANTY CLAIMS FUNCTIONS - FIXED
// ============================================

let currentClaimId = null;
let currentUserId = null;

function loadWarrantyClaims() {
    const search = document.getElementById('warrantySearch')?.value || '';
    const status = document.getElementById('warrantyStatusFilter')?.value || '';
    const fromDate = document.getElementById('warrantyDateFrom')?.value || '';
    const toDate = document.getElementById('warrantyDateTo')?.value || '';
    
    const tbody = document.getElementById('warrantyClaimsTableBody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">Loading...</td></tr>';
    }
    
    fetch(`api/warranty.php?action=get_claims&search=${encodeURIComponent(search)}&status=${status}&from=${fromDate}&to=${toDate}`)
        .then(r => r.json())
        .then(data => {
            // ✅ Check if data is array or object with success/data structure
            let claims = [];
            if (Array.isArray(data)) {
                claims = data;
            } else if (data.success && Array.isArray(data.data)) {
                claims = data.data;
            } else if (data.data && Array.isArray(data.data)) {
                claims = data.data;
            } else {
                console.warn('Unexpected data format:', data);
                claims = [];
            }
            
            updateWarrantyTable(claims);
            
            // Update badge count
            const badge = document.getElementById('warrantyClaimsBadge');
            if (badge) {
                const pendingCount = claims.filter(c => c.status === 'pending').length;
                badge.textContent = pendingCount;
            }
        })
        .catch(err => {
            console.error('Error loading warranty claims:', err);
            const tbody = document.getElementById('warrantyClaimsTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Error loading claims</td></tr>';
            }
        });
}

function updateWarrantyTable(claims) {
    const tbody = document.getElementById('warrantyClaimsTableBody');
    if (!tbody) return;
    
    if (!claims || claims.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No warranty claims found</td></tr>';
        return;
    }
    
    tbody.innerHTML = claims.map(claim => {
        let statusBadge = '';
        switch(claim.status) {
            case 'pending': statusBadge = '<span class="badge bg-warning text-dark">Pending</span>'; break;
            case 'reviewing': statusBadge = '<span class="badge bg-info">Reviewing</span>'; break;
            case 'approved': statusBadge = '<span class="badge bg-success">Approved</span>'; break;
            case 'rejected': statusBadge = '<span class="badge bg-danger">Rejected</span>'; break;
            case 'completed': statusBadge = '<span class="badge bg-dark">Completed</span>'; break;
            default: statusBadge = '<span class="badge bg-secondary">' + (claim.status || 'Unknown') + '</span>';
        }
        
        const scheduleDate = claim.schedule_date ? new Date(claim.schedule_date).toLocaleDateString() : '—';
        const scheduleTime = claim.schedule_time || '';
        const scheduleDisplay = scheduleDate !== '—' ? scheduleDate + (scheduleTime ? ' ' + scheduleTime : '') : '—';
        
        return `
            <tr>
                <td><strong>${escapeHtml(claim.claim_number || '—')}</strong></td>
                <td>${escapeHtml(claim.first_name || '')} ${escapeHtml(claim.last_name || '')}</td>
                <td>${escapeHtml(claim.product_name || '—')}</td>
                <td>${escapeHtml((claim.issue_description || '—').substring(0, 50))}${(claim.issue_description || '').length > 50 ? '...' : ''}</td>
                <td>${scheduleDisplay}</td>
                <td>${statusBadge}</td>
                <td>${claim.created_at ? new Date(claim.created_at).toLocaleDateString() : '—'}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="openReviewModal(${claim.id}, ${claim.user_id})">
                        <i class="bi bi-eye"></i> Review
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function openReviewModal(claimId, userId) {
    currentClaimId = claimId;
    currentUserId = userId;

    const modal = new bootstrap.Modal(document.getElementById('reviewWarrantyModal'));
    modal.show();

    document.getElementById('reviewWarrantyModal').addEventListener('shown.bs.modal', function handler() {
        this.removeEventListener('shown.bs.modal', handler);

        // Reset all sections
        ['form_pending','form_approved','form_arrived','form_completed','form_rejected'].forEach(id => {
            document.getElementById(id).style.display = 'none';
        });

        // Loading placeholders
        ['review_claim_number','review_customer_name','review_customer_contact',
         'review_customer_email','review_product_name','review_reservation_id',
         'review_submitted_date','review_schedule','review_current_status'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = 'Loading...';
        });

        fetch(`api/warranty.php?action=get_claim&id=${claimId}`)
            .then(r => r.json())
            .then(data => {
                const claim = data.success ? data.data : null;
                if (!claim) {
                    Swal.fire('Error', 'Cannot load claim details', 'error');
                    return;
                }

                // Fill info
                const set = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val || '—';
                };

                set('review_claim_number', claim.claim_number);
                set('review_customer_name', (claim.first_name || '') + ' ' + (claim.last_name || ''));
                set('review_customer_contact', claim.contact);
                set('review_customer_email', claim.email);
                set('review_product_name', claim.product_name);
                set('review_reservation_id', claim.reservation_code || claim.reservation_id);
                set('review_submitted_date', claim.created_at ? new Date(claim.created_at).toLocaleString() : '—');

                // Schedule
                let sched = 'No schedule set';
                if (claim.schedule_date) {
                    sched = new Date(claim.schedule_date).toLocaleDateString('en-PH', {
                        year: 'numeric', month: 'long', day: 'numeric'
                    });
                    if (claim.schedule_time) sched += ' at ' + claim.schedule_time;
                }
                set('review_schedule', sched);

                // Status badge
                const statusEl = document.getElementById('review_current_status');
                if (statusEl) statusEl.innerHTML = getStatusBadge(claim.status);

                // Description
                const descEl = document.getElementById('review_description');
                if (descEl) descEl.innerHTML = claim.issue_description || '<em>No description</em>';

                // Photos
                const photosDiv = document.getElementById('review_photos');
                if (photosDiv) {
                    try {
                        let photos = typeof claim.photos === 'string' 
                            ? JSON.parse(claim.photos) 
                            : (claim.photos || []);
                        photosDiv.innerHTML = photos.length
                            ? photos.map(p => `
                                <a href="/eyecore/${p}" target="_blank" class="border rounded p-1">
                                    <img src="/eyecore/${p}" style="width:60px;height:60px;object-fit:cover;">
                                </a>`).join('')
                            : '<span class="text-muted small">No photos uploaded</span>';
                    } catch(e) {
                        photosDiv.innerHTML = '<span class="text-muted small">No photos uploaded</span>';
                    }
                }

                // Show correct section + footer buttons based on status
                renderModalByStatus(claim);
            })
            .catch(err => {
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    }, { once: true });
}

function renderModalByStatus(claim) {
    const footer = document.getElementById('modal_footer_buttons');

    // Hide all forms first
    ['form_pending','form_approved','form_arrived','form_completed','form_rejected'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });

    // Default footer
    footer.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;

    switch(claim.status) {
        case 'pending':
            document.getElementById('form_pending').style.display = 'block';
            document.getElementById('reject_reason_box').style.display = 'none';

            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="submitWarrantyClaim('rejected')">
                    <i class="bi bi-x-circle me-1"></i>Reject
                </button>
                <button type="button" class="btn btn-success" onclick="submitWarrantyClaim('approved')">
                    <i class="bi bi-check-circle me-1"></i>Approve
                </button>
            `;
            break;

        case 'approved':
            document.getElementById('form_approved').style.display = 'block';
            const approvedSched = document.getElementById('approved_schedule_display');
            if (approvedSched && claim.schedule_date) {
                approvedSched.textContent = new Date(claim.schedule_date).toLocaleDateString('en-PH', {
                    year: 'numeric', month: 'long', day: 'numeric'
                });
            }
            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="submitWarrantyClaim('arrived')">
                    <i class="bi bi-door-open me-1"></i>Mark as Arrived
                </button>
            `;
            break;

        case 'arrived':
            document.getElementById('form_arrived').style.display = 'block';
            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="submitWarrantyClaim('completed')">
                    <i class="bi bi-patch-check me-1"></i>Mark as Completed
                </button>
            `;
            break;

        case 'completed':
            document.getElementById('form_completed').style.display = 'block';
            break;

        case 'rejected':
            document.getElementById('form_rejected').style.display = 'block';
            const rejEl = document.getElementById('rejected_reason_display');
            if (rejEl) rejEl.textContent = claim.rejection_reason || '—';
            break;
    }
}

function submitWarrantyClaim(newStatus) {
    // Validation for PENDING → APPROVED
    if (newStatus === 'approved') {
        const schedDate = document.getElementById('claim_schedule_date')?.value;
        const resolution = document.getElementById('claim_resolution')?.value;

        if (!schedDate) {
            Swal.fire('Required', 'Please set a visit date for the customer.', 'warning');
            return;
        }
        if (!resolution) {
            Swal.fire('Required', 'Please select an offer type.', 'warning');
            return;
        }
    }

    // Validation for PENDING → REJECTED
    if (newStatus === 'rejected') {
        // Show rejection reason box first if hidden
        const rejectBox = document.getElementById('reject_reason_box');
        const rejectReason = document.getElementById('claim_rejection_reason')?.value?.trim();

        if (rejectBox.style.display === 'none') {
            rejectBox.style.display = 'block';
            document.getElementById('claim_rejection_reason').focus();
            return; // Wait for user to type reason then click Reject again
        }

        if (!rejectReason) {
            Swal.fire('Required', 'Please enter a rejection reason.', 'warning');
            return;
        }
    }

    const payload = {
        action: 'update_claim',
        claim_id: currentClaimId,
        user_id: currentUserId,
        status: newStatus,
        resolution: document.getElementById('claim_resolution')?.value || null,
        schedule_date: document.getElementById('claim_schedule_date')?.value || null,
        resolution_notes: document.getElementById('claim_resolution_notes')?.value || null,
        rejection_reason: document.getElementById('claim_rejection_reason')?.value || null,
    };

    Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('api/warranty.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('reviewWarrantyModal'))?.hide();
            Swal.fire({
                icon: 'success',
                title: getStatusTitle(newStatus),
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => loadWarrantyClaims());
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire('Error', 'Network error: ' + err.message, 'error');
    });
}

function getStatusTitle(status) {
    const titles = {
        approved: '✅ Claim Approved!',
        rejected: '❌ Claim Rejected',
        arrived: '👋 Patient Arrived!',
        completed: '🎉 Claim Completed!'
    };
    return titles[status] || 'Updated!';
}
function submitWarrantyDecision() {
    const decision = document.getElementById('claim_decision')?.value || 'pending';
    const resolution = document.getElementById('claim_resolution')?.value || 'repaired';
    const rejectionReason = document.getElementById('claim_rejection_reason')?.value || '';
    const adminNotes = document.getElementById('claim_admin_notes')?.value || '';
    
    if (decision === 'rejected' && !rejectionReason) {
        Swal.fire('Error', 'Please provide a rejection reason', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/warranty.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_claim',
            claim_id: currentClaimId,
            user_id: currentUserId,
            status: decision,
            resolution: resolution,
            rejection_reason: rejectionReason,
            admin_notes: adminNotes
        })
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('reviewWarrantyModal'));
            if (modal) modal.hide();
            
            Swal.fire('Success!', data.message || 'Claim updated successfully', 'success').then(() => {
                loadWarrantyClaims();
            });
        } else {
            Swal.fire('Error!', data.message || 'Failed to update claim', 'error');
        }
    })
    .catch(err => {
        Swal.close();
        console.error('Error:', err);
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed': '<span class="badge bg-info text-dark">Confirmed</span>',
        'paid': '<span class="badge bg-primary">Paid</span>',
        'completed': '<span class="badge bg-success">Completed</span>',
        'cancelled': '<span class="badge bg-danger">Cancelled</span>',
        'no-show': '<span class="badge bg-secondary">No-show</span>',
        'refunded': '<span class="badge bg-dark">Refunded</span>'
    };
    return badges[status] || '<span class="badge bg-secondary">' + status + '</span>';
}
</script>
</body>
</html>