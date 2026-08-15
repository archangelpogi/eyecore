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

// ── REFUND REQUESTS (for stats count) ──────────────────────────
$stmtRefundsCount = $pdo->prepare("
    SELECT COUNT(*) as total 
    FROM refund_requests 
    WHERE clinic_id = ? AND status = 'pending'
");
$stmtRefundsCount->execute([$clinicId]);
$pendingRefunds = $stmtRefundsCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// ── REFUND REQUESTS (ALL) ──────────────────────────────────────
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
    LEFT JOIN products pr ON a.product_id = pr.id
    LEFT JOIN appointments a2 ON rr.rebook_appointment_id = a2.id
    WHERE rr.clinic_id = ? 
      AND rr.status != 'rejected'
    ORDER BY 
        FIELD(rr.status, 'pending', 'processing', 'failed') ASC,
        rr.created_at DESC
");
$stmtRefunds->execute([$clinicId]);
$refundList = $stmtRefunds->fetchAll(PDO::FETCH_ASSOC);

// ── ALL APPOINTMENTS (Unified query for the table) ─────────────
$stmtAll = $pdo->prepare("
    SELECT a.*,
           a.id as appointment_id,
           a.subtotal,
           a.discount_type,
           a.discount_percentage,
           a.discount_amount,
           a.vat_percentage,
           a.vat_amount,
           a.total_amount,
           a.amount_paid,
           a.downpayment_amount,
           a.balance_amount,
           COALESCE(
               CONCAT(u.first_name, ' ', u.last_name),
               CONCAT(p.first_name, ' ', p.last_name),
               a.service_type,
               'Walk-in Patient'
           ) AS patient_name,
           u.email AS user_email,
           u.contact AS user_contact,
           COALESCE(pr.name, srv.name, a.service_type, '—') AS item_display_name,
           pr.name AS product_name,
           pr.price AS product_price,
           srv.name AS service_name,
           srv.price AS service_price,
           d.name  AS doctor_name,
           d.specialty AS doctor_specialty,
           pay.payment_status AS pay_status,
           pay.payment_method,
           pay.reference_number,
           pay.payment_date,
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
    WHERE a.clinic_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$stmtAll->execute([$clinicId]);
$allAppointments = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// ── Doctors list for modals ──────────────────────────────────────
$stmtDoctors = $pdo->prepare("SELECT * FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name ASC");
$stmtDoctors->execute([$clinicId]);
$doctorsList = $stmtDoctors->fetchAll(PDO::FETCH_ASSOC);

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

// ============================================
// EXISTING FUNCTIONS - HINDI GAGALAWIN
// ============================================
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
    global $pdo;
    $aptId = $appt['appointment_id'] ?? $appt['id'] ?? 0;
    if ($aptId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM appointment_services WHERE appointment_id = ?");
            $stmt->execute([$aptId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            if ($count > 1) {
                return '<span class="badge bg-success">' . $count . ' Services</span>';
            } elseif ($count === 1) {
                return '<span class="badge bg-success">Service</span>';
            }
        } catch (Exception $e) {}
    }
    if(!empty($appt['product_name'])) {
        return '<span class="badge bg-primary">Product</span>';
    } elseif(!empty($appt['service_name']) || !empty($appt['service_type'])) {
        return '<span class="badge bg-success">Service</span>';
    }
    return '<span class="badge bg-secondary">General</span>';
}

function getItemDetails($appt) {
    global $pdo;
    $html = '';
    $aptId = $appt['appointment_id'] ?? $appt['id'] ?? 0;
    if ($aptId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT s.name, aps.price FROM appointment_services aps JOIN services s ON aps.service_id = s.id WHERE aps.appointment_id = ?");
            $stmt->execute([$aptId]);
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($services) > 1) {
                $html .= '<div class="small mb-1"><i class="bi bi-list-ul text-primary me-1"></i><strong>Services (' . count($services) . '):</strong></div>';
                $total = 0;
                foreach ($services as $s) {
                    $total += $s['price'];
                    $html .= '<div class="small ms-3 text-muted">• ' . htmlspecialchars($s['name']) . ' - ₱' . number_format($s['price'], 2) . '</div>';
                }
                $html .= '<div class="small ms-3 fw-bold text-success">Total: ₱' . number_format($total, 2) . '</div>';
                return $html;
            } elseif (count($services) === 1) {
                $s = $services[0];
                return '<div class="small mb-1"><i class="bi bi-star text-warning me-1"></i><strong>Service:</strong> ' . htmlspecialchars($s['name']) . '<br><span class="text-success ms-4">₱' . number_format($s['price'], 2) . '</span></div>';
            }
        } catch (Exception $e) {}
    }
    if(!empty($appt['product_name'])) {
        return '<div class="small mb-1"><i class="bi bi-box text-primary me-1"></i><strong>Product:</strong> ' . htmlspecialchars($appt['product_name']) . '<br><span class="text-success ms-4">₱' . number_format($appt['product_price'] ?? 0, 2) . '</span></div>';
    } 
    elseif(!empty($appt['service_name'])) {
        return '<div class="small mb-1"><i class="bi bi-star text-warning me-1"></i><strong>Service:</strong> ' . htmlspecialchars($appt['service_name']) . '<br><span class="text-success ms-4">₱' . number_format($appt['service_price'] ?? 0, 2) . '</span></div>';
    }
    elseif(!empty($appt['service_type'])) {
        return '<div class="small mb-1"><i class="bi bi-star text-warning me-1"></i><strong>Service:</strong> ' . htmlspecialchars($appt['service_type']) . '</div>';
    }
    return '<div class="small mb-1 text-muted"><i class="bi bi-question-circle me-1"></i><em>No item specified</em></div>';
}

function getDiscountBadge($appt) {
    $html = '';
    if(!empty($appt['discount_type']) && $appt['discount_type'] !== 'none') {
        $html .= '<div class="small mt-1">';
        $html .= '<span class="badge bg-info">';
        $html .= '<i class="bi bi-tag me-1"></i>';
        $html .= strtoupper($appt['discount_type']) . ' ' . round($appt['discount_percentage'] ?? 0) . '% OFF';
        $html .= '</span>';
        if(($appt['vat_percentage'] ?? 0) == 0) {
            $html .= '<span class="badge bg-success ms-1">VAT Exempt</span>';
        }
        $html .= '</div>';
    }
    return $html;
}

function getPriceBreakdown($appt) {
    $html = '';
    $subtotal = $appt['subtotal'] ?? 0;
    $discount_type = $appt['discount_type'] ?? 'none';
    $discount_percentage = $appt['discount_percentage'] ?? 0;
    $discount_amount = $appt['discount_amount'] ?? 0;
    $vat_percentage = $appt['vat_percentage'] ?? 0;
    $vat_amount = $appt['vat_amount'] ?? 0;
    $total_amount = $appt['total_amount'] ?? 0;
    $amount_paid = $appt['amount_paid'] ?? 0;
    $status = $appt['status'] ?? '';
    
    if ($status === 'confirmed' || $status === 'waiting_payment') {
        $display_total = $total_amount;
    } else {
        $display_total = $amount_paid > 0 ? $amount_paid : $total_amount;
    }
    
    $html .= '<div class="small mt-2 pt-2 border-top">';
    
    if ($discount_type !== 'none' && $discount_amount > 0) {
        $html .= '<div class="d-flex justify-content-between"><span class="text-muted">Subtotal:</span><span>₱' . number_format($subtotal, 2) . '</span></div>';
        $html .= '<div class="d-flex justify-content-between text-danger"><span>' . strtoupper($discount_type) . ' Discount (' . round($discount_percentage) . '%):</span><span>-₱' . number_format($discount_amount, 2) . '</span></div>';
        $html .= '<div class="d-flex justify-content-between"><span class="text-muted">Subtotal after discount:</span><span>₱' . number_format($subtotal - $discount_amount, 2) . '</span></div>';
        if ($vat_amount > 0) {
            $html .= '<div class="d-flex justify-content-between text-warning"><span>VAT (' . round($vat_percentage) . '%):</span><span>+₱' . number_format($vat_amount, 2) . '</span></div>';
        } else {
            $html .= '<div class="d-flex justify-content-between text-success"><span>VAT:</span><span>Exempt</span></div>';
        }
    } else {
        $html .= '<div class="d-flex justify-content-between"><span class="text-muted">Subtotal:</span><span>₱' . number_format($subtotal, 2) . '</span></div>';
        if ($vat_amount > 0) {
            $html .= '<div class="d-flex justify-content-between text-warning"><span>VAT (' . round($vat_percentage) . '%):</span><span>+₱' . number_format($vat_amount, 2) . '</span></div>';
        }
    }
    
    $html .= '<div class="d-flex justify-content-between fw-bold"><span>TOTAL PAID:</span><span style="color: var(--success);">₱' . number_format($display_total, 2) . '</span></div>';
    $html .= '</div>';
    return $html;
}

function getPaymentStatusBadge($appt) {
    $status = $appt['status'] ?? '';
    $payStatus = $appt['pay_status'] ?? '';
    $amountPaid = floatval($appt['amount_paid'] ?? 0);
    $totalAmount = floatval($appt['total_amount'] ?? 0);
    
    if ($status === 'paid' || $status === 'completed' || $payStatus === 'paid') {
        return '<span class="badge bg-success">Paid</span>';
    } elseif ($status === 'confirmed' || $status === 'waiting_payment') {
        return '<span class="badge bg-warning text-dark">Awaiting Payment</span>';
    } elseif ($status === 'no-show' && $amountPaid > 0) {
        return '<span class="badge bg-secondary">Forfeited</span>';
    } elseif ($status === 'refunded') {
        return '<span class="badge bg-dark">Refunded</span>';
    } elseif ($status === 'cancelled' && $amountPaid > 0) {
        return '<span class="badge bg-danger">Refund Pending</span>';
    }
    return '<span class="text-muted small">—</span>';
}

function getActionButtons($a) {
    $buttons = '';
    $apptId = $a['id'] ?? 0;
    $userId = $a['user_id'] ?? 0;
    $patientId = $a['patient_id'] ?? 0;
    $status = $a['status'] ?? '';
    
    $buttons .= '<a href="appointment-details.php?id=' . $apptId . '" class="btn btn-sm btn-outline-primary me-1" title="View Details"><i class="bi bi-eye"></i></a>';
    
    if ($status === 'pending') {
        if (canApproveAppointments()) {
            $buttons .= '<button class="btn btn-sm btn-success me-1" onclick="approveAppointment(' . $apptId . ', ' . $userId . ', ' . $patientId . ')" title="Approve"><i class="bi bi-check-lg"></i></button>';
        }
        if (canRejectAppointments()) {
            $buttons .= '<button class="btn btn-sm btn-outline-danger me-1" onclick="rejectModal(' . $apptId . ', ' . $userId . ')" title="Reject"><i class="bi bi-x-lg"></i></button>';
        }
    }
    
    if ($status === 'confirmed' && canEditAppointments()) {
        $buttons .= '<button class="btn btn-sm btn-success me-1" onclick="markArrived(' . $apptId . ', ' . $userId . ', ' . $patientId . ')" title="Mark Arrived"><i class="bi bi-door-open"></i></button>';
        $buttons .= '<button class="btn btn-sm btn-outline-danger me-1" onclick="updateStatus(' . $apptId . ', ' . $userId . ', \'no-show\')" title="No-show"><i class="bi bi-person-x"></i></button>';
    }
    
    if ($status === 'paid' && canEditAppointments()) {
        $buttons .= '<button class="btn btn-sm btn-success me-1" onclick="updateStatus(' . $apptId . ', ' . $userId . ', \'completed\')" title="Complete"><i class="bi bi-patch-check"></i></button>';
    }
    
    return $buttons;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Scheduling</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
    :root{
        --ec-primary:#0f6e5f;
        --ec-primary-dark:#0b5347;
        --ec-primary-light:#e6f5f1;
        --ec-accent:#00B761;
        --ec-accent-dark:#00A86B;
        --ec-ink:#16211f;
        --ec-muted:#6b7a78;
        --ec-border:#e6ece9;
        --ec-bg:#f4f7f6;
        --ec-card:#ffffff;
        --ec-warning:#f5a524;
        --ec-danger:#e5484d;
        --ec-info:#3aa0c9;
        --ec-radius:14px;
        --ec-radius-sm:10px;
        --ec-shadow:0 2px 10px rgba(16,40,34,.06);
        --ec-shadow-md:0 8px 24px rgba(16,40,34,.10);
        --success:#0f9d58;
    }

    * { box-sizing:border-box; }

    html, body{ height:100%; }
    body{
        background:var(--ec-bg);
        font-family:'Manrope','Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
        color:var(--ec-ink);
        min-height:100vh;
    }

    .container-fluid.p-4{
        max-width:100%;
        margin:0;
        padding:1.5rem clamp(1rem, 2.5vw, 2.25rem) 2.5rem !important;
        min-height:100vh;
    }

    .page-title{
        font-family:'Plus Jakarta Sans',sans-serif;
        font-weight:800;
        font-size:1.85rem;
        letter-spacing:-.02em;
        color:var(--ec-ink);
    }
    .page-subtitle{ font-size:.92rem; color:var(--ec-muted); margin-top:2px; }

    .btn{
        font-weight:600;
        border-radius:10px;
        padding:.55rem 1.1rem;
        font-size:.88rem;
        transition:all .15s ease;
        letter-spacing:.01em;
    }
    .btn-sm{ padding:.4rem .8rem; font-size:.8rem; border-radius:8px; }
    .btn-primary{
        background:linear-gradient(135deg,var(--ec-primary),var(--ec-primary-dark));
        border:none;
        box-shadow:0 4px 12px rgba(15,110,95,.25);
    }
    .btn-primary:hover{
        background:linear-gradient(135deg,var(--ec-primary-dark),var(--ec-primary-dark));
        box-shadow:0 6px 16px rgba(15,110,95,.32);
        transform:translateY(-1px);
    }

    /* ── Stats Cards ───────────────────────────────── */
    .row.g-3.mb-4{ --bs-gutter-x: 1.5rem; --bs-gutter-y: 1.5rem; margin-bottom:2.25rem !important; }
    .row.g-3.mb-4 > div > .card{
        border-radius:16px;
        border:1px solid var(--ec-border);
        box-shadow:none;
        background:var(--ec-card);
        transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        height:100%;
    }
    .row.g-3.mb-4 > div > .card:hover{
        transform:translateY(-2px);
        box-shadow:var(--ec-shadow-md);
        border-color:#d3e3df;
    }
    .row.g-3.mb-4 .card-body{
        padding:1.5rem 1.9rem !important;
        display:grid;
        grid-template-columns:1fr auto;
        grid-template-rows:auto auto;
        grid-template-areas:"lbl icon" "num icon";
        align-items:center;
        row-gap:.9rem;
        text-align:left !important;
    }
    .row.g-3.mb-4 .card-body > i.bi{
        grid-area:icon;
        color:var(--ec-muted) !important;
        background:var(--ec-bg);
        border:1px solid var(--ec-border);
        width:50px;
        height:50px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:1.4rem !important;
    }
    .row.g-3.mb-4 .card-body > .fw-bold.fs-4{
        grid-area:num;
        font-size:2.2rem !important;
        line-height:1;
        font-family:'Plus Jakarta Sans',sans-serif;
        font-weight:800;
        margin-top:0 !important;
    }
    .row.g-3.mb-4 .card-body > .text-muted.small{
        grid-area:lbl;
        font-size:0.95rem !important;
        font-weight:600;
        text-transform:none;
        letter-spacing:0;
        color:var(--ec-ink) !important;
        opacity:.75;
    }

    /* ── Tabs ──────────────────────────────────────── */
    .nav-tabs{
        border:none !important;
        border-bottom:2px solid var(--ec-border) !important;
        padding-bottom:0.5rem;
        margin-bottom:2rem !important;
        gap:0.5rem !important;
    }
    .nav-tabs .nav-link{
        border:none;
        border-radius:12px;
        color:var(--ec-muted);
        font-weight:600;
        font-size:1rem;
        padding:0.7rem 1.5rem;
        background:transparent;
        transition:all .15s ease;
        position:relative;
    }
    .nav-tabs .nav-link i.bi{ font-size:1.1rem; margin-right:0.4rem; }
    .nav-tabs .nav-link:hover{ background:var(--ec-primary-light); color:var(--ec-primary-dark); }
    .nav-tabs .nav-link.active{
        background:linear-gradient(135deg,var(--ec-primary),var(--ec-primary-dark));
        color:#fff;
        box-shadow:0 4px 12px rgba(15,110,95,.28);
    }
    .nav-tabs .nav-link.active .badge{
        background:rgba(255,255,255,.25) !important;
        color:#fff;
    }
    .nav-tabs .badge{ font-size:0.75rem; padding:0.3em 0.6em; }

    /* ── Toolbar (Filters) - Parang Sales & Billing ── */
    .toolbar{
        background:var(--ec-card);
        border-radius:var(--ec-radius);
        border:1px solid var(--ec-border);
        padding:1rem 1.25rem;
        margin-bottom:1.5rem;
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        justify-content:space-between;
        gap:0.75rem;
    }
    .toolbar-left{
        display:flex;
        align-items:center;
        gap:0.75rem;
        flex-wrap:wrap;
    }
    .toolbar-left .filter-group{
        display:flex;
        align-items:center;
        gap:0.4rem;
    }
    .toolbar-left .filter-group label{
        font-size:0.75rem;
        font-weight:600;
        color:var(--ec-muted);
        text-transform:uppercase;
        letter-spacing:.03em;
    }
    .toolbar-left .filter-group select{
        border:1px solid var(--ec-border);
        border-radius:var(--ec-radius-sm);
        padding:0.4rem 0.7rem;
        font-size:0.85rem;
        background:white;
        color:var(--ec-ink);
        transition:all .15s ease;
    }
    .toolbar-left .filter-group select:focus{
        border-color:var(--ec-primary);
        box-shadow:0 0 0 3px rgba(15,110,95,.12);
        outline:none;
    }
    .btn-reset{
        background:transparent;
        border:1px solid var(--ec-border);
        border-radius:var(--ec-radius-sm);
        padding:0.4rem 1rem;
        font-size:0.8rem;
        color:var(--ec-muted);
        transition:all .15s ease;
        cursor:pointer;
    }
    .btn-reset:hover{
        background:var(--ec-bg);
        border-color:var(--ec-border);
    }

    .toolbar-right{
        display:flex;
        align-items:center;
        gap:0.75rem;
        flex-wrap:wrap;
    }
    .toolbar-right .entries-select{
        display:flex;
        align-items:center;
        gap:0.4rem;
        font-size:0.85rem;
        color:var(--ec-muted);
    }
    .toolbar-right .entries-select select{
        border:1px solid var(--ec-border);
        border-radius:var(--ec-radius-sm);
        padding:0.4rem 0.6rem;
        font-size:0.85rem;
        background:white;
    }
    .search-box{
        display:flex;
        align-items:center;
        border:1px solid var(--ec-border);
        border-radius:var(--ec-radius-sm);
        padding:0 0.75rem;
        background:white;
        transition:all .15s ease;
    }
    .search-box:focus-within{
        border-color:var(--ec-primary);
        box-shadow:0 0 0 3px rgba(15,110,95,.12);
    }
    .search-box i{ color:var(--ec-muted); font-size:0.9rem; }
    .search-box input{
        border:none;
        padding:0.45rem 0.6rem;
        font-size:0.85rem;
        background:transparent;
        width:180px;
        color:var(--ec-ink);
    }
    .search-box input:focus{ outline:none; }

    /* ── Table Card ─────────────────────────────────── */
    .table-card{
        background:var(--ec-card);
        border-radius:var(--ec-radius);
        border:1px solid var(--ec-border);
        overflow:hidden;
    }
    .table-wrapper{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .table-custom{
        width:100%;
        border-collapse:collapse;
        font-size:0.9rem;
    }
    .table-custom thead th{
        background:var(--ec-bg);
        padding:0.9rem 1.1rem;
        text-align:left;
        font-weight:700;
        font-size:0.75rem;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:var(--ec-muted);
        border-bottom:2px solid var(--ec-border);
        white-space:nowrap;
    }
    .table-custom tbody td{
        padding:0.9rem 1.1rem;
        border-bottom:1px solid var(--ec-border);
        vertical-align:middle;
        color:var(--ec-ink);
    }
    .table-custom tbody tr:hover{ background:var(--ec-primary-light); }
    .table-custom tbody tr:last-child td{ border-bottom:none; }

    /* ── Badges ─────────────────────────────────────── */
    .badge{
        font-weight:600;
        font-size:0.7rem;
        padding:0.35em 0.65em;
        border-radius:6px;
        letter-spacing:.01em;
    }
    .badge.bg-warning.text-dark{ color:#5c3d00 !important; }

    /* ── Status Badge with dot ─────────────────────── */
    .badge-status{
        display:inline-flex;
        align-items:center;
        gap:0.35rem;
        padding:0.25rem 0.65rem;
        border-radius:20px;
        font-size:0.7rem;
        font-weight:600;
    }
    .badge-status .dot{
        width:6px;
        height:6px;
        border-radius:50%;
        display:inline-block;
    }
    .badge-status.pending{ background:#FEF3C7; color:#92400E; }
    .badge-status.pending .dot{ background:#F59E0B; }
    .badge-status.confirmed{ background:#DBEAFE; color:#1E40AF; }
    .badge-status.confirmed .dot{ background:#3B82F6; }
    .badge-status.paid{ background:#D1FAE5; color:#065F46; }
    .badge-status.paid .dot{ background:#10B981; }
    .badge-status.completed{ background:#D1FAE5; color:#065F46; }
    .badge-status.completed .dot{ background:#10B981; }
    .badge-status.cancelled{ background:#FEE2E2; color:#991B1B; }
    .badge-status.cancelled .dot{ background:#EF4444; }
    .badge-status.no-show{ background:#F3F4F6; color:#4B5563; }
    .badge-status.no-show .dot{ background:#9CA3AF; }
    .badge-status.refunded{ background:#F3F4F6; color:#1F2937; }
    .badge-status.refunded .dot{ background:#6B7280; }

    /* ── Table Footer ──────────────────────────────── */
    .table-footer{
        display:flex;
        justify-content:space-between;
        align-items:center;
        padding:1rem 1.25rem;
        border-top:1px solid var(--ec-border);
        background:var(--ec-card);
        flex-wrap:wrap;
        gap:0.75rem;
    }
    .table-footer .info-text{
        font-size:0.85rem;
        color:var(--ec-muted);
    }
    .table-footer .info-text strong{ color:var(--ec-ink); }
    .pagination-custom{
        display:flex;
        gap:0.25rem;
        align-items:center;
    }
    .pagination-custom button{
        padding:0.35rem 0.8rem;
        border:1px solid var(--ec-border);
        border-radius:var(--ec-radius-sm);
        background:white;
        font-size:0.8rem;
        color:var(--ec-muted);
        transition:all .15s ease;
        cursor:pointer;
    }
    .pagination-custom button:hover:not(:disabled){
        background:var(--ec-bg);
        border-color:var(--ec-border);
    }
    .pagination-custom button:disabled{
        opacity:0.5;
        cursor:not-allowed;
    }
    .pagination-custom button.active{
        background:var(--ec-primary);
        color:white;
        border-color:var(--ec-primary);
    }

    /* ── Action Buttons ────────────────────────────── */
    .action-btns{
        display:flex;
        gap:0.2rem;
        flex-wrap:wrap;
    }
    .action-btns .btn-sm{
        padding:0.2rem 0.45rem;
        font-size:0.75rem;
        border-radius:6px;
    }

    /* ── Refund & Warranty Cards ──────────────────── */
    .card-header.bg-light{
        background:var(--ec-card) !important;
        border-bottom:1px solid var(--ec-border);
        border-radius:var(--ec-radius) var(--ec-radius) 0 0 !important;
        padding:1rem 1.25rem;
    }
    .refund-item .card{ transition:transform .15s ease; }
    .refund-item .card:hover{ transform:translateY(-2px); box-shadow:var(--ec-shadow-md); }

    /* ── Modals ─────────────────────────────────────── */
    .modal-content{
        border-radius:var(--ec-radius);
        border:none;
        box-shadow:0 20px 60px rgba(0,0,0,.2);
        overflow:hidden;
    }
    .modal-header{
        border-bottom:1px solid var(--ec-border);
        background:var(--ec-card);
        padding:1.1rem 1.4rem;
    }
    .modal-header.bg-danger{ background:linear-gradient(135deg,#e5484d,#c53338) !important; }
    .modal-title{ font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; }
    .modal-body{ padding:1.4rem; }
    .modal-footer{ border-top:1px solid var(--ec-border); padding:1rem 1.4rem; background:#fbfcfc; }

    /* ── Responsive ─────────────────────────────────── */
    @media(max-width:1024px){
        .container-fluid.p-4{ padding:1.25rem !important; }
    }
    @media(max-width:768px){
        .container-fluid.p-4{ padding:1rem 0.75rem !important; }
        .nav-tabs{ gap:0.3rem !important; }
        .nav-tabs .nav-link{ font-size:0.85rem; padding:0.5rem 1rem; }
        .toolbar{ flex-direction:column; align-items:stretch; }
        .toolbar-left{ flex-wrap:wrap; }
        .toolbar-right{ flex-wrap:wrap; justify-content:space-between; }
        .search-box input{ width:120px; }
        .row.g-3.mb-4 .card-body{ padding:1rem 1.25rem !important; }
        .row.g-3.mb-4 .card-body > .fw-bold.fs-4{ font-size:1.6rem !important; }
        .table-custom{ font-size:0.8rem; }
        .table-custom thead th, .table-custom tbody td{ padding:0.5rem 0.6rem; }
        .table-footer{ flex-direction:column; text-align:center; }
        .action-btns .btn-sm{ padding:0.15rem 0.35rem; font-size:0.65rem; }
    }
    @media(max-width:480px){
        .row.g-3.mb-4 .card-body{ grid-template-columns:1fr; grid-template-areas:"lbl" "num" "icon"; text-align:center !important; }
        .row.g-3.mb-4 .card-body > i.bi{ margin:0 auto; width:44px; height:44px; font-size:1.2rem !important; }
        .nav-tabs .nav-link{ font-size:0.75rem; padding:0.4rem 0.7rem; }
        .nav-tabs .nav-link i.bi{ font-size:0.9rem; }
    }
    </style>
</head>
<body>
<div class="container-fluid p-4">
    <!-- HEADER -->
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

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <?php
        $statItems = [
            ['label'=>"Today's Total",  'val'=>$stats['total'] ?? 0,     'color'=>'primary',   'icon'=>'bi-calendar2-week'],
            ['label'=>'Pending',        'val'=>$stats['pending'] ?? 0,   'color'=>'warning',   'icon'=>'bi-hourglass-split'],
            ['label'=>'Confirmed',      'val'=>$stats['confirmed'] ?? 0, 'color'=>'info',      'icon'=>'bi-check-circle'],
            ['label'=>'Paid',           'val'=>$stats['paid'] ?? 0,      'color'=>'primary',   'icon'=>'bi-credit-card'],
            ['label'=>'Completed',      'val'=>$stats['completed'] ?? 0, 'color'=>'success',   'icon'=>'bi-patch-check'],
            ['label'=>'Refund Requests','val'=>$pendingRefunds,          'color'=>'dark',      'icon'=>'bi-arrow-repeat'],
            ['label'=>'No-Show',        'val'=>$stats['noshow'] ?? 0,    'color'=>'secondary', 'icon'=>'bi-person-x'],
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

    <!-- ============================================ -->
    <!-- 3 TABS LANG: Appointments | Refunds | Warranty -->
    <!-- ============================================ -->
    <ul class="nav nav-tabs mb-4" id="apptTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tabAppointments" data-bs-toggle="tab" data-bs-target="#tabAppointmentsContent" type="button" role="tab">
                <i class="bi bi-calendar2-week"></i> Appointments
                <span class="badge bg-secondary rounded-pill ms-1"><?= count($allAppointments) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabRefunds" data-bs-toggle="tab" data-bs-target="#tabRefundsContent" type="button" role="tab">
                <i class="bi bi-arrow-repeat"></i> Refunds
                <?php if($pendingRefunds > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= $pendingRefunds ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tabWarranty" data-bs-toggle="tab" data-bs-target="#tabWarrantyContent" type="button" role="tab">
                <i class="bi bi-shield-check"></i> Warranty
                <span class="badge bg-warning rounded-pill ms-1" id="warrantyTabBadge">0</span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="apptTabsContent">

        <!-- ============================================ -->
        <!-- TAB 1: APPOINTMENTS (Unified Table + Filters) -->
        <!-- ============================================ -->
        <div class="tab-pane fade show active" id="tabAppointmentsContent" role="tabpanel">
            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <div class="filter-group">
                        <label>Status</label>
                        <select id="filterStatus">
                            <option value="all">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="paid">Paid</option>
                            <option value="completed">Completed</option>
                            <option value="no-show">No-Show</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Payment</label>
                        <select id="filterPayment">
                            <option value="all">All Payment</option>
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="partial">Partial</option>
                            <option value="forfeited">Forfeited</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Date</label>
                        <select id="filterDate">
                            <option value="all">All Dates</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                            <option value="past">Past</option>
                            <option value="upcoming">Upcoming</option>
                        </select>
                    </div>
                    <button class="btn-reset" onclick="resetFilters()">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                </div>
                <div class="toolbar-right">
                    <div class="entries-select">
                        Show
                        <select id="entriesPerPage">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        entries
                    </div>
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search patient, ID..." onkeyup="filterTable()">
                    </div>
                </div>
            </div>

            <!-- Unified Table -->
            <div class="table-card">
                <div class="table-wrapper">
                    <table class="table-custom" id="appointmentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Date/Time</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Discount</th>
                                <th>Doctor</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if(empty($allAppointments)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
                                    No appointments found.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($allAppointments as $a): 
                                $status = $a['status'] ?? '';
                                $statusClass = match($status) {
                                    'pending' => 'pending',
                                    'confirmed' => 'confirmed',
                                    'paid' => 'paid',
                                    'completed' => 'completed',
                                    'cancelled' => 'cancelled',
                                    'no-show' => 'no-show',
                                    'refunded' => 'refunded',
                                    default => 'pending'
                                };
                                $paymentBadge = getPaymentStatusBadge($a);
                            ?>
                            <tr data-status="<?= $status ?>" data-payment="<?= $a['pay_status'] ?? '' ?>" data-date="<?= $a['appointment_date'] ?? '' ?>">
                                <td><strong>#<?= str_pad($a['id'] ?? 0, 6, '0', STR_PAD_LEFT) ?></strong></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($a['patient_name'] ?? 'N/A') ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($a['user_email'] ?? '') ?></div>
                                </td>
                                <td>
                                    <div><?= date('M d, Y', strtotime($a['appointment_date'] ?? 'now')) ?></div>
                                    <div class="text-muted small"><?= date('h:i A', strtotime($a['appointment_time'] ?? '00:00')) ?></div>
                                </td>
                                <td><?= getItemDetails($a) ?></td>
                                <td><?= getItemTypeBadge($a) ?></td>
                                <td><?= getDiscountBadge($a) ?></td>
                                <td><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
                                <td>
                                    <span class="badge-status <?= $statusClass ?>">
                                        <span class="dot"></span>
                                        <?= ucfirst(str_replace('-', ' ', $status)) ?>
                                    </span>
                                </td>
                                <td><?= $paymentBadge ?></td>
<td>
    <div class="action-btns">
        <?php 
        $apptId = $a['id'] ?? 0;
        $userId = $a['user_id'] ?? 0;
        $patientId = $a['patient_id'] ?? 0;
        $status = $a['status'] ?? '';
        ?>
        
        <!-- ✅ View Button - Laging meron -->
        <a href="appointment-details.php?id=<?= $apptId ?>" class="btn btn-sm btn-outline-primary me-1" title="View Details">
            <i class="bi bi-eye"></i>
        </a>
        
        <?php if($status === 'pending'): ?>
            <?php if(canApproveAppointments()): ?>
                <button class="btn btn-sm btn-success me-1" onclick="approveAppointment(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>)" title="Approve">
                    <i class="bi bi-check-lg"></i>
                </button>
            <?php endif; ?>
            <?php if(canRejectAppointments()): ?>
                <button class="btn btn-sm btn-outline-danger me-1" onclick="rejectModal(<?= $apptId ?>, <?= $userId ?>)" title="Reject">
                    <i class="bi bi-x-lg"></i>
                </button>
            <?php endif; ?>
            
        <?php elseif($status === 'confirmed' && canEditAppointments()): ?>
            <!-- ✅ Confirmed: Arrive + No-show -->
            <button class="btn btn-sm btn-success me-1" onclick="markArrived(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>)" title="Mark Arrived">
                <i class="bi bi-door-open"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger me-1" onclick="markNoShow(<?= $apptId ?>, <?= $userId ?>)" title="No-show">
                <i class="bi bi-person-x"></i>
            </button>
            
        <?php elseif($status === 'missed' && canEditAppointments()): ?>
            <!-- ✅ Missed: No-show LANG (walang Arrive!) -->
            <button class="btn btn-sm btn-outline-danger me-1" onclick="markNoShow(<?= $apptId ?>, <?= $userId ?>)" title="Convert to No-show">
                <i class="bi bi-person-x me-1"></i> No-show
            </button>
            
        <?php elseif($status === 'paid' && canEditAppointments()): ?>
            <button class="btn btn-sm btn-success me-1" onclick="updateStatus(<?= $apptId ?>, <?= $userId ?>, 'completed')" title="Complete">
                <i class="bi bi-patch-check"></i>
            </button>
            
        <?php elseif($status === 'no-show'): ?>
            <!-- ✅ No-show Status - WALANG ACTIONS! -->
            <span class="badge bg-secondary">
                <i class="bi bi-person-x me-1"></i> No-show
            </span>
            <?php if(($a['forfeited_amount'] ?? 0) > 0): ?>
                <br><small class="text-muted">Forfeited: ₱<?= number_format($a['forfeited_amount'], 2) ?></small>
            <?php endif; ?>
            <?php if(($a['refund_eligible'] ?? 0) == 1): ?>
                <br><small class="text-success">Refunded: <?= $a['refund_percentage'] ?? 0 ?>%</small>
            <?php endif; ?>
            
        <?php elseif(in_array($status, ['completed','cancelled','refunded'])): ?>
            <span class="text-muted small">Done</span>
            
        <?php else: ?>
            <span class="text-muted small">—</span>
        <?php endif; ?>
    </div>
</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <div class="info-text" id="tableInfo">
                        Showing <strong id="startCount">0</strong> to <strong id="endCount">0</strong> of <strong id="totalCount">0</strong> entries
                    </div>
                    <div class="pagination-custom" id="paginationControls">
                        <button onclick="changePage('prev')" id="prevBtn" disabled>Previous</button>
                        <button class="active" id="pageBtn1">1</button>
                        <button onclick="changePage('next')" id="nextBtn" disabled>Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- TAB 2: REFUNDS (Preserved)                    -->
        <!-- ============================================ -->
        <div class="tab-pane fade" id="tabRefundsContent" role="tabpanel">
            <?php if (canEditAppointments()): ?>
                <?php if(empty($refundList)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-arrow-repeat fs-1 d-block mb-2"></i>
                        No refund requests.
                    </div>
                <?php else: ?>
                    <!-- Filter tabs for refund status -->
                    <div class="mb-3">
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-secondary active" onclick="filterRefunds('all')">All</button>
                            <button class="btn btn-outline-warning" onclick="filterRefunds('pending')">Pending</button>
                            <button class="btn btn-outline-info" onclick="filterRefunds('processing')">Processing</button>
                            <button class="btn btn-outline-danger" onclick="filterRefunds('failed')">Failed</button>
                            <button class="btn btn-outline-success" onclick="filterRefunds('completed')">Completed</button>
                            <button class="btn btn-outline-secondary" onclick="filterRefunds('rejected')">Rejected</button>
                        </div>
                    </div>
                    
                    <div class="row g-3" id="refundListContainer">
                        <?php foreach($refundList as $r): 
                            $statusClass = match($r['refund_status'] ?? $r['status']) {
                                'completed' => 'success',
                                'processing' => 'warning',
                                'failed' => 'danger',
                                'rejected' => 'secondary',
                                default => 'dark'
                            };
                            $statusIcon = match($r['refund_status'] ?? $r['status']) {
                                'completed' => '✅ Refunded',
                                'processing' => '⏳ Processing',
                                'failed' => '❌ Failed',
                                'rejected' => 'Rejected',
                                default => 'Pending'
                            };
                        ?>
                        <div class="col-12 col-md-6 refund-item" data-status="<?= $r['refund_status'] ?? $r['status'] ?>">
                            <div class="card shadow-sm border-start border-<?= $statusClass ?> border-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-0 fw-bold">
                                                <?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?>
                                            </h6>
                                            <div class="text-muted small"><?= htmlspecialchars($r['email']) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?= $statusClass ?>">
                                                <?= $statusIcon ?>
                                            </span>
                                        </div>
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
                                        <i class="bi bi-currency-dollar text-success me-1"></i>
                                        <strong>Refund Amount:</strong> ₱<?= number_format($r['amount'] ?? 0, 2) ?>
                                        <?php if ($r['refund_percentage'] ?? 0 > 0): ?>
                                            <span class="badge bg-info"><?= $r['refund_percentage'] ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($r['paymongo_refund_id'])): ?>
                                    <div class="small mb-2">
                                        <i class="bi bi-qr-code text-secondary me-1"></i>
                                        <strong>Refund ID:</strong> <?= htmlspecialchars($r['paymongo_refund_id']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($r['error_message'])): ?>
                                    <div class="alert alert-danger small py-1 mb-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Error:</strong> <?= htmlspecialchars($r['error_message']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="small mb-2">
                                        <i class="bi bi-chat-left-text text-info me-1"></i>
                                        <strong>Reason:</strong> <?= htmlspecialchars($r['reason']) ?>
                                    </div>
                                    
                                    <!-- ACTION BUTTONS BASED ON STATUS -->
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <div class="mt-3">
                                            <textarea class="form-control form-control-sm mb-2" id="adminNotes-<?= $r['id'] ?>" 
                                                      placeholder="Admin notes (optional)" rows="2"></textarea>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-success btn-sm flex-fill" 
                                                    onclick="processRefundRedirect(<?= $r['id'] ?>, <?= $r['user_id'] ?>, 'approve', document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i> Process on PayMongo
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm flex-fill" 
                                                    onclick="processRefund(<?= $r['id'] ?>, <?= $r['user_id'] ?>, 'reject', document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                                    <i class="bi bi-x-circle me-1"></i> Reject
                                                </button>
                                            </div>
                                            <small class="text-muted d-block mt-1">
                                                <i class="bi bi-info-circle me-1"></i>
                                                You will be redirected to PayMongo to process this refund.
                                            </small>
                                        </div>
                                        
                                    <?php elseif ($r['refund_status'] === 'processing'): ?>
                                        <div class="mt-3">
                                            <div class="alert alert-info small py-1 mb-2">
                                                <i class="bi bi-hourglass-split me-1"></i>
                                                <strong>Refund being processed on PayMongo.</strong>
                                                <br>Once completed on PayMongo, click "Mark as Done".
                                            </div>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-success btn-sm flex-fill" 
                                                    onclick="markRefundManual(<?= $r['id'] ?>, <?= $r['user_id'] ?>)">
                                                    <i class="bi bi-check-circle me-1"></i> Mark as Done
                                                </button>
                                                <button class="btn btn-outline-secondary btn-sm flex-fill" 
                                                    onclick="location.reload()">
                                                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                                                </button>
                                            </div>
                                        </div>
                                        
                                    <?php elseif ($r['refund_status'] === 'failed'): ?>
                                        <div class="mt-3">
                                            <div class="alert alert-warning small py-1 mb-2">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                <strong>Auto-refund failed.</strong> Please process manually.
                                            </div>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-outline-danger btn-sm flex-fill" 
                                                    onclick="retryRefund(<?= $r['id'] ?>, <?= $r['user_id'] ?>)">
                                                    <i class="bi bi-arrow-clockwise me-1"></i> Retry Auto
                                                </button>
                                                <button class="btn btn-primary btn-sm flex-fill" 
                                                    onclick="processRefundRedirect(<?= $r['id'] ?>, <?= $r['user_id'] ?>, 'approve', '')">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i> PayMongo
                                                </button>
                                                <button class="btn btn-outline-success btn-sm flex-fill" 
                                                    onclick="markRefundManual(<?= $r['id'] ?>, <?= $r['user_id'] ?>)">
                                                    <i class="bi bi-check-circle me-1"></i> Mark Done
                                                </button>
                                            </div>
                                            <?php if (!empty($r['paymongo_refund_id'])): ?>
                                                <small class="text-muted d-block mt-1">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    Refund ID: <?= htmlspecialchars($r['paymongo_refund_id']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        
                                    <?php elseif ($r['refund_status'] === 'completed'): ?>
                                        <div class="mt-3 text-success small">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Refunded on <?= date('F j, Y g:i A', strtotime($r['refund_date'] ?? $r['updated_at'])) ?>
                                            <?php if (!empty($r['paymongo_refund_id'])): ?>
                                                <br><small class="text-muted">Refund ID: <?= htmlspecialchars($r['paymongo_refund_id']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        
                                    <?php elseif ($r['status'] === 'rejected'): ?>
                                        <div class="mt-3 text-muted small">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Rejected on <?= date('F j, Y', strtotime($r['updated_at'] ?? $r['created_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-lock fs-1 d-block mb-2"></i>
                    You don't have permission to view refund requests.
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================ -->
        <!-- TAB 3: WARRANTY (Preserved)                   -->
        <!-- ============================================ -->
        <div class="tab-pane fade" id="tabWarrantyContent" role="tabpanel">
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
    </div>

    <!-- ============================================ -->
    <!-- MODALS (PRESERVED)                           -->
    <!-- ============================================ -->
    <!-- Reject Modal -->
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

    <!-- New Appointment Modal -->
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
</div>

<!-- ============================================ -->
<!-- SCRIPTS - LAHAT PRESERVED                    -->
<!-- ============================================ -->
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

// ============================================
// FILTER FUNCTIONS - UI LANG
// ============================================
function filterTable() {
    const status = document.getElementById('filterStatus').value;
    const payment = document.getElementById('filterPayment').value;
    const date = document.getElementById('filterDate').value;
    const search = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#tableBody tr');
    let visibleCount = 0;
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    rows.forEach(row => {
        let show = true;
        const rowStatus = row.getAttribute('data-status') || '';
        const rowPayment = row.getAttribute('data-payment') || '';
        const rowDate = row.getAttribute('data-date') || '';
        const rowText = row.textContent.toLowerCase();

        if (status !== 'all' && rowStatus !== status) show = false;
        if (payment !== 'all' && rowPayment !== payment) show = false;

        if (date !== 'all' && rowDate) {
            const apptDate = new Date(rowDate);
            apptDate.setHours(0, 0, 0, 0);
            const weekAgo = new Date(today);
            weekAgo.setDate(weekAgo.getDate() - 7);
            const monthAgo = new Date(today);
            monthAgo.setMonth(monthAgo.getMonth() - 1);

            if (date === 'today' && apptDate.getTime() !== today.getTime()) show = false;
            else if (date === 'week' && apptDate < weekAgo) show = false;
            else if (date === 'month' && apptDate < monthAgo) show = false;
            else if (date === 'past' && apptDate >= today) show = false;
            else if (date === 'upcoming' && apptDate < today) show = false;
        }

        if (search && !rowText.includes(search)) show = false;

        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    updatePaginationInfo(visibleCount);
}

function resetFilters() {
    document.getElementById('filterStatus').value = 'all';
    document.getElementById('filterPayment').value = 'all';
    document.getElementById('filterDate').value = 'all';
    document.getElementById('searchInput').value = '';
    filterTable();
}

function updatePaginationInfo(count) {
    const total = document.querySelectorAll('#tableBody tr').length;
    document.getElementById('totalCount').textContent = total;
    document.getElementById('startCount').textContent = count > 0 ? 1 : 0;
    document.getElementById('endCount').textContent = count;
}

function changePage(direction) {
    Swal.fire('Info', 'All entries are shown. Use filters to narrow down results.', 'info');
}

// ============================================
// MARK ARRIVED - PRESERVED
// ============================================
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

// ============================================
// APPROVE APPOINTMENT - PRESERVED
// ============================================
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

// ============================================
// REJECT MODAL - PRESERVED
// ============================================
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

// ============================================
// UPDATE STATUS - PRESERVED
// ============================================
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


// ============================================
// MARK NO-SHOW - WITH CONFIRMATION
// ============================================
function markNoShow(appointmentId, userId) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to mark no-show', 'error');
        return;
    }
    
    if (!appointmentId || appointmentId === 0) {
        Swal.fire('Error', 'Missing appointment ID', 'error');
        return;
    }
    
    // ✅ Get no-show policy info first
    Swal.fire({
        title: 'Checking No-Show Policy...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false
    });
    
    fetch(`api/appointments.php?action=get_no_show_info&appointment_id=${appointmentId}`)
        .then(r => r.json())
        .then(data => {
            Swal.close();
            
            if (!data.success) {
                Swal.fire('Error', data.message || 'Cannot get no-show policy', 'error');
                return;
            }
            
            const forfeitAmount = data.forfeit_amount || 0;
            const downpayment = data.downpayment || 0;
            const refundPercent = data.refund_percent || 0;
            const refundPolicy = data.refund_policy || '2:100|1:50|0:0';
            const refundEligible = data.refund_eligible || false;
            
            // ✅ Build policy display
            let policyDisplay = '';
            if (refundEligible && refundPercent > 0) {
                const refundAmount = (downpayment * refundPercent / 100);
                policyDisplay = `
                    <div style="background:#D1FAE5;padding:10px;border-radius:8px;margin:10px 0;">
                        <strong style="color:#065F46;">✅ Refund Available</strong>
                        <div style="color:#065F46;">${refundPercent}% refund (₱${refundAmount.toFixed(2)})</div>
                        <div style="color:#065F46;">Forfeited: ₱${forfeitAmount.toFixed(2)}</div>
                    </div>
                `;
            } else {
                policyDisplay = `
                    <div style="background:#FEE2E2;padding:10px;border-radius:8px;margin:10px 0;">
                        <strong style="color:#991B1B;">❌ No Refund</strong>
                        <div style="color:#991B1B;">100% forfeited (₱${forfeitAmount.toFixed(2)})</div>
                    </div>
                `;
            }
            
            // ✅ Show confirmation
            Swal.fire({
                title: '⚠️ Mark as No-Show?',
                html: `
                    <div style="text-align:left;">
                        <p class="text-danger"><strong>This patient did not show up for their appointment.</strong></p>
                        <div style="background:#FEF3C7;padding:15px;border-radius:10px;margin:15px 0;">
                            <strong style="color:#92400E;">📋 Clinic Refund Policy:</strong>
                            <ul style="margin:10px 0 0 0;padding-left:20px;color:#78350F;list-style:none;">
                                <li>💰 Amount Paid: <strong>₱${downpayment.toFixed(2)}</strong></li>
                                <li>📜 Policy: <strong>${refundPolicy}</strong></li>
                            </ul>
                        </div>
                        ${policyDisplay}
                        <p style="color:#6B7280;font-size:13px;">
                            <i class="bi bi-info-circle me-1"></i>
                            Patient will be notified.
                        </p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#DC2626',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Yes, Mark as No-Show',
                cancelButtonText: 'Cancel'
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
                            action: 'update_status',
                            appointment_id: parseInt(appointmentId),
                            user_id: parseInt(userId || 0),
                            status: 'no-show'
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            let msg = 'Patient marked as no-show.';
                            if (data.data) {
                                if (data.data.forfeited_amount > 0) {
                                    msg += ` Forfeited: ₱${data.data.forfeited_amount.toFixed(2)}`;
                                }
                                if (data.data.refund_eligible && data.data.refund_amount > 0) {
                                    msg += ` Refund: ${data.data.refund_percent}% (₱${data.data.refund_amount.toFixed(2)})`;
                                }
                            }
                            Swal.fire({ 
                                icon: 'warning', 
                                title: 'No-Show Marked!', 
                                text: msg,
                                timer: 3000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Error', 
                                text: data.message || 'Failed to mark no-show' 
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
        })
        .catch(err => {
            console.error('Error:', err);
            Swal.close();
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Could not retrieve no-show policy.' 
            });
        });
}
// ============================================
// PROCESS REFUND - PRESERVED
// ============================================
function processRefund(refundId, userId, action, adminNotes) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to process refunds', 'error');
        return;
    }
    
    if (action === 'reject') {
        Swal.fire({
            title: 'Reject Refund Request?',
            html: `
                <p>Reject this refund request?</p>
                <div class="mt-3">
                    <label class="form-label fw-semibold">Reason for rejection (shown to patient)</label>
                    <textarea class="form-control" id="rejectReasonInput" rows="2" 
                              placeholder="Enter reason..."></textarea>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Reject',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const reason = document.getElementById('rejectReasonInput').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Please enter a rejection reason');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                executeRefundAction(refundId, userId, 'reject', adminNotes, result.value);
            }
        });
    }
}

function processRefundRedirect(refundId, userId, action, adminNotes) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to process refunds', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Process Refund on PayMongo?',
        html: `
            <div style="text-align:left;">
                <p>You will be redirected to <strong>PayMongo</strong> to process this refund.</p>
                <p class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    After completing the refund on PayMongo, come back and click <strong>"Mark as Done"</strong>.
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Go to PayMongo',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            executeRefundRedirect(refundId, userId, 'approve', adminNotes);
        }
    });
}

function executeRefundRedirect(refundId, userId, action, adminNotes) {
    Swal.fire({ 
        title: 'Getting PayMongo link...', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading() 
    });
    
    const payload = {
        action: 'process_refund',
        refund_id: refundId,
        user_id: userId,
        refund_action: action,
        admin_notes: adminNotes || ''
    };
    
    fetch('api/appointments.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        Swal.close();
        
        if (data.success && data.redirect && data.checkout_url) {
            window.open(data.checkout_url, '_blank');
            Swal.fire({
                title: 'PayMongo Opened! ✅',
                html: `
                    <p>After processing the refund on PayMongo:</p>
                    <ol class="text-start mt-2" style="font-size:14px;">
                        <li>Complete the refund on PayMongo</li>
                        <li>Come back to this page</li>
                        <li>Click <strong>"Mark as Done"</strong> on the refund request</li>
                    </ol>
                `,
                icon: 'success',
                confirmButtonText: 'Okay, I\'ll do that'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'Failed to get PayMongo link.',
                confirmButtonColor: '#dc3545'
            });
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Please try again.',
            confirmButtonColor: '#dc3545'
        });
    });
}

function markRefundManual(refundId, userId) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to mark refunds as done', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Mark as Manually Refunded?',
        html: `
            <p>This will mark the refund as <strong>completed</strong> without calling PayMongo API.</p>
            <div class="alert alert-warning mt-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Make sure you have already processed the refund on PayMongo.
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, mark as refunded'
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
                    action: 'mark_refund_manual',
                    refund_id: refundId,
                    user_id: userId
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', 'Network error', 'error');
            });
        }
    });
}

function retryRefund(refundId, userId) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to retry refunds', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Retry Refund?',
        text: 'This will attempt to process the refund again via PayMongo API.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Retry'
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
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: 'approve',
                    admin_notes: 'Retry after failed attempt'
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    if (data.redirect && data.checkout_url) {
                        window.open(data.checkout_url, '_blank');
                        Swal.fire({
                            title: 'PayMongo Opened!',
                            text: 'Complete the refund on PayMongo, then click "Mark as Done".',
                            icon: 'success',
                            confirmButtonText: 'Okay'
                        });
                    } else {
                        Swal.fire('Success!', data.message, 'success').then(() => location.reload());
                    }
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', 'Network error', 'error');
            });
        }
    });
}

function filterRefunds(status) {
    document.querySelectorAll('.btn-group .btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`.btn-group .btn[onclick="filterRefunds('${status}')"]`)?.classList.add('active');
    
    document.querySelectorAll('.refund-item').forEach(item => {
        const itemStatus = item.getAttribute('data-status') || 'pending';
        if (status === 'all' || itemStatus === status) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

// ============================================
// WARRANTY CLAIMS FUNCTIONS - PRESERVED
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
            
            const badge = document.getElementById('warrantyTabBadge');
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

        ['form_pending','form_approved','form_arrived','form_completed','form_rejected'].forEach(id => {
            document.getElementById(id).style.display = 'none';
        });

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

                let sched = 'No schedule set';
                if (claim.schedule_date) {
                    sched = new Date(claim.schedule_date).toLocaleDateString('en-PH', {
                        year: 'numeric', month: 'long', day: 'numeric'
                    });
                    if (claim.schedule_time) sched += ' at ' + claim.schedule_time;
                }
                set('review_schedule', sched);

                const statusEl = document.getElementById('review_current_status');
                if (statusEl) statusEl.innerHTML = getStatusBadge(claim.status);

                const descEl = document.getElementById('review_description');
                if (descEl) descEl.innerHTML = claim.issue_description || '<em>No description</em>';

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

                renderModalByStatus(claim);
            })
            .catch(err => {
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    }, { once: true });
}

function renderModalByStatus(claim) {
    const footer = document.getElementById('modal_footer_buttons');

    ['form_pending','form_approved','form_arrived','form_completed','form_rejected'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });

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

    if (newStatus === 'rejected') {
        const rejectBox = document.getElementById('reject_reason_box');
        const rejectReason = document.getElementById('claim_rejection_reason')?.value?.trim();

        if (rejectBox.style.display === 'none') {
            rejectBox.style.display = 'block';
            document.getElementById('claim_rejection_reason').focus();
            return;
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

// ============================================
// DOM READY
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // ✅ Attach event listeners to filters
    document.getElementById('filterStatus').addEventListener('change', filterTable);
    document.getElementById('filterPayment').addEventListener('change', filterTable);
    document.getElementById('filterDate').addEventListener('change', filterTable);
    document.getElementById('searchInput').addEventListener('keyup', filterTable);
    document.getElementById('entriesPerPage').addEventListener('change', filterTable);

    // ✅ Initial filter
    filterTable();

    // ✅ Item select handler
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

    // ✅ New appointment form
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
            if (!itemId) {
                Swal.fire('Error', 'Please select a service or product', 'error');
                return;
            }
            const data = {
                patient_id: formData.get('patient_id'),
                doctor_id: formData.get('doctor_id'),
                item_id: itemId,
                item_type: formData.get('item_type'),
                service_type: formData.get('service_type'),
                appointment_date: formData.get('appointment_date'),
                appointment_time: formData.get('appointment_time'),
                notes: formData.get('notes'),
                walk_in: true
            };
            Swal.fire({ title: 'Saving…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
            api('api/appointments.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(d => {
                if (d.success) {
                    Swal.fire({ icon: 'success', title: 'Appointment Added!', timer: 2000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: d.message });
                }
            });
        });
    }

    // ✅ Reset button
    document.querySelector('.btn-reset')?.addEventListener('click', resetFilters);

    // ✅ Load warranty claims
    loadWarrantyClaims();

    // ✅ Theme check
    const savedTheme = localStorage.getItem('theme') || 'light';
    if (savedTheme === 'dark') {
        document.documentElement.classList.add('theme-dark');
    }
});
</script>
</body>
</html>