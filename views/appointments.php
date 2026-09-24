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

RBACHelper::init($pdo);

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
    /* ═══════════════════════════════════════════════════════════════ */
    /* RESERVATIONS UI DESIGN SYSTEM                                    */
    /* ═══════════════════════════════════════════════════════════════ */
    :root {
        --ec-primary: #0d6e6e;
        --ec-primary-dark: #095050;
        --ec-primary-light: #e6f4f4;
        --ec-text: #1e293b;
        --ec-text-muted: #64748b;
        --ec-border: #e2e8f0;
        --ec-bg-soft: #f8fafc;
        --ec-radius: 12px;
        --ec-radius-sm: 8px;
        --ec-shadow: 0 1px 3px rgba(0,0,0,.04), 0 1px 2px rgba(0,0,0,.02);
        --ec-shadow-hover: 0 4px 12px rgba(0,0,0,.06);
        --success: #16a34a;
        --ec-warning: #d97706;
        --ec-danger: #dc2626;
        --ec-info: #2563eb;
    }

    .wrapper,
    .main-content,
    .content-area {
        min-width: 0 !important;
        max-width: 100% !important;
    }

    .wrapper {
        overflow-x: hidden !important;
        width: 100% !important;
    }

    .content-area {
        overflow-x: hidden !important;
        width: 100% !important;
    }

    .ec-page,
    .ec-page * {
        box-sizing: border-box;
    }
    .ec-page {
        color: var(--ec-text);
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    .ec-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--ec-text); }
    .ec-header p  { font-size: .875rem; color: var(--ec-text-muted); }

    .ec-summary {
        background: #fff; border: 1px solid var(--ec-border);
        border-radius: var(--ec-radius); padding: 1rem 1.25rem;
        box-shadow: var(--ec-shadow);
    }
    .ec-summary-item {
        display: flex; flex-direction: column; gap: .125rem;
        padding: .5rem .75rem; border-radius: var(--ec-radius-sm);
        transition: background .15s; text-align: left;
    }
    .ec-summary-item:hover { background: var(--ec-bg-soft); }
    .ec-summary-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ec-text-muted); font-weight: 600; }
    .ec-summary-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
    .ec-summary-value.warning { color: #d97706; }
    .ec-summary-value.info    { color: #2563eb; }
    .ec-summary-value.success { color: #16a34a; }
    .ec-summary-value.primary { color: var(--ec-primary); }
    .ec-summary-value.danger  { color: #dc2626; }
    .ec-summary-value.dark    { color: #1e293b; }

    /* ═══ Tabs ═══ */
    .ec-tabs {
        display: flex;
        gap: .25rem;
        overflow-x: auto;
        overflow-y: hidden;
        border-bottom: 1px solid var(--ec-border);
        width: 100%;
        max-width: 100%;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }
    .ec-tabs::-webkit-scrollbar { height: 4px; }
    .ec-tabs::-webkit-scrollbar-thumb { background: var(--ec-border); border-radius: 4px; }

    .ec-tab {
        background: transparent; border: none; padding: .65rem 1rem;
        font-size: .875rem; font-weight: 500; color: var(--ec-text-muted);
        white-space: nowrap; border-bottom: 2px solid transparent;
        transition: all .15s; cursor: pointer;
        flex-shrink: 0;
    }
    .ec-tab:hover { color: var(--ec-text); }
    .ec-tab.active { color: var(--ec-primary); border-bottom-color: var(--ec-primary); font-weight: 600; }
    .ec-tab .ec-tab-count {
        background: var(--ec-bg-soft); color: var(--ec-text-muted);
        padding: .1rem .5rem; border-radius: 999px;
        font-size: .7rem; font-weight: 600; margin-left: .35rem;
    }
    .ec-tab.active .ec-tab-count { background: var(--ec-primary-light); color: var(--ec-primary); }

    .ec-filter-bar {
        background: #fff; border: 1px solid var(--ec-border);
        border-radius: var(--ec-radius); padding: .75rem 1rem;
        box-shadow: var(--ec-shadow);
        width: 100%;
        max-width: 100%;
    }

    /* ═══ Order Card ═══ */
    .ec-order-card {
        background: #fff; border: 1px solid var(--ec-border);
        border-radius: var(--ec-radius); padding: 1.1rem 1.25rem;
        box-shadow: var(--ec-shadow); transition: all .18s ease;
        margin-bottom: .75rem; position: relative;
        width: 100%;
        max-width: 100%;
    }
    .ec-order-card:hover {
        box-shadow: var(--ec-shadow-hover);
        border-color: #cbd5e1; transform: translateY(-1px);
    }
    .ec-order-card.selected {
        border-color: var(--ec-primary);
        background: var(--ec-primary-light);
    }
    .ec-order-card.dropdown-open,
    .ec-order-card.dropdown-open:hover {
        transform: none !important;
        box-shadow: var(--ec-shadow) !important;
        border-color: var(--ec-border) !important;
    }
    .ec-order-header {
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: 1rem; margin-bottom: .9rem; flex-wrap: wrap;
    }
    .ec-order-id { font-weight: 700; font-size: .95rem; color: var(--ec-primary); }
    .ec-order-date { font-size: .75rem; color: var(--ec-text-muted); }
    .ec-order-body { display: flex; gap: 1.25rem; align-items: flex-start; flex-wrap: wrap; min-width: 0; }
    .ec-order-product {
        width: 56px; height: 56px; border-radius: var(--ec-radius-sm);
        object-fit: cover; background: var(--ec-bg-soft);
        border: 1px solid var(--ec-border); flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .ec-order-info { flex: 1 1 200px; min-width: 0; }
    .ec-order-customer { font-weight: 600; font-size: .9rem; margin-bottom: .15rem; }
    .ec-order-product-name { font-size: .875rem; color: var(--ec-text); margin-bottom: .1rem; word-break: break-word; }
    .ec-order-product-meta { font-size: .75rem; color: var(--ec-text-muted); }
    .ec-order-right {
        text-align: right; min-width: 160px;
        display: flex; flex-direction: column; gap: .35rem; align-items: flex-end;
        flex-shrink: 0;
    }
    .ec-order-total { font-size: 1.1rem; font-weight: 700; color: var(--ec-text); }
    .ec-order-actions {
        display: flex; gap: .5rem; justify-content: flex-end;
        margin-top: .9rem; padding-top: .9rem;
        border-top: 1px solid var(--ec-border); flex-wrap: wrap;
    }

    /* ═══ Badges ═══ */
    .ec-badge {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .2rem .55rem; border-radius: 6px;
        font-size: .7rem; font-weight: 600; line-height: 1.4;
    }
    .ec-badge-pending    { background: #fef3c7; color: #92400e; }
    .ec-badge-confirmed  { background: #dbeafe; color: #1e40af; }
    .ec-badge-paid       { background: #dcfce7; color: #166534; }
    .ec-badge-completed  { background: #16a34a; color: #fff; }
    .ec-badge-cancelled  { background: #fee2e2; color: #991b1b; }
    .ec-badge-noshow     { background: #f1f5f9; color: #475569; }
    .ec-badge-refunded   { background: #f1f5f9; color: #475569; }
    .ec-badge-default    { background: #f1f5f9; color: #475569; }
    .ec-badge-service    { background: #dcfce7; color: #166534; }
    .ec-badge-product    { background: #dbeafe; color: #1e40af; }
    .ec-badge-general    { background: #f1f5f9; color: #475569; }
    .ec-badge-discount   { background: #e0e7ff; color: #3730a3; }
    .ec-badge-vat        { background: #dcfce7; color: #166534; }

    /* ═══ Buttons ═══ */
    .ec-btn-primary { background: var(--ec-primary); border-color: var(--ec-primary); color: #fff; }
    .ec-btn-primary:hover { background: var(--ec-primary-dark); border-color: var(--ec-primary-dark); color: #fff; }

    /* ═══ Dropdown ═══ */
    .ec-dropdown-menu {
        border: 1px solid var(--ec-border); border-radius: var(--ec-radius-sm);
        box-shadow: var(--ec-shadow-hover); padding: .35rem;
        font-size: .85rem; min-width: 220px;
    }
    .ec-dropdown-menu .dropdown-item { border-radius: 6px; padding: .45rem .65rem; }
    .ec-dropdown-menu .dropdown-item:hover { background: var(--ec-bg-soft); }

    /* ═══ Detail Sections ═══ */
    .ec-detail-section { margin-bottom: 1.25rem; }
    .ec-detail-section-title {
        font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
        color: var(--ec-text-muted); font-weight: 700; margin-bottom: .5rem;
        display: flex; align-items: center; gap: .35rem;
    }
    .ec-detail-row { display: flex; justify-content: space-between; font-size: .85rem; padding: .3rem 0; }
    .ec-detail-row .label { color: var(--ec-text-muted); }
    .ec-detail-row .value { font-weight: 500; text-align: right; }

    /* ═══ Refund Cards ═══ */
    .ec-refund-container {
        display: none;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    .ec-refund-container.active {
        display: block;
    }

    .ec-refund-card {
        background: #fff;
        border: 1px solid var(--ec-border);
        border-radius: var(--ec-radius);
        padding: 1.1rem 1.25rem;
        box-shadow: var(--ec-shadow);
        transition: all .18s ease;
        margin-bottom: .75rem;
        width: 100%;
        max-width: 100%;
    }
    .ec-refund-card:hover {
        box-shadow: var(--ec-shadow-hover);
        border-color: #cbd5e1;
    }

    .ec-refund-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: .9rem;
        flex-wrap: wrap;
    }

    .ec-refund-id { font-weight: 700; font-size: .95rem; color: var(--ec-primary); }
    .ec-refund-date { font-size: .75rem; color: var(--ec-text-muted); }

    .ec-refund-body {
        display: flex;
        gap: 1.25rem;
        align-items: flex-start;
        flex-wrap: wrap;
        min-width: 0;
    }

    .ec-refund-product {
        width: 56px;
        height: 56px;
        border-radius: var(--ec-radius-sm);
        object-fit: cover;
        background: var(--ec-bg-soft);
        border: 1px solid var(--ec-border);
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ec-refund-info {
        flex: 1 1 200px;
        min-width: 0;
        overflow: hidden;
    }

    .ec-refund-customer { font-weight: 600; font-size: .9rem; margin-bottom: .15rem; }
    .ec-refund-product-name { font-size: .875rem; color: var(--ec-text); margin-bottom: .1rem; word-break: break-word; }
    .ec-refund-reason {
        font-size: .8rem;
        color: var(--ec-text-muted);
        padding: .4rem .6rem;
        background: #fef3c7;
        border-left: 3px solid #d97706;
        border-radius: 4px;
        margin-top: .4rem;
        word-break: break-word;
    }
    .ec-refund-reason strong { color: #92400e; }

    .ec-refund-right {
        text-align: right;
        min-width: 140px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: .35rem;
        align-items: flex-end;
    }

    .ec-refund-amount { font-size: 1.1rem; font-weight: 700; color: #dc2626; }

    .ec-refund-status {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .25rem .6rem;
        border-radius: 6px;
        font-size: .7rem;
        font-weight: 700;
        line-height: 1.4;
        white-space: nowrap;
    }
    .ec-refund-pending    { background: #fef3c7; color: #92400e; }
    .ec-refund-approved   { background: #dbeafe; color: #1e40af; }
    .ec-refund-processing { background: #e9d5ff; color: #6b21a8; }
    .ec-refund-completed  { background: #dcfce7; color: #166534; }
    .ec-refund-rejected   { background: #fee2e2; color: #991b1b; }
    .ec-refund-failed     { background: #fee2e2; color: #991b1b; }

    .ec-refund-actions {
        display: flex;
        gap: .5rem;
        justify-content: flex-end;
        margin-top: .9rem;
        padding-top: .9rem;
        border-top: 1px solid var(--ec-border);
        flex-wrap: wrap;
    }

    .ec-refund-filters {
        display: flex;
        gap: .5rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
        width: 100%;
        max-width: 100%;
    }
    .ec-refund-filter {
        padding: .4rem .8rem;
        border: 1px solid var(--ec-border);
        border-radius: 999px;
        background: #fff;
        font-size: .8rem;
        font-weight: 500;
        color: var(--ec-text-muted);
        cursor: pointer;
        transition: all .15s;
    }
    .ec-refund-filter:hover {
        background: var(--ec-bg-soft);
        color: var(--ec-text);
    }
    .ec-refund-filter.active {
        background: var(--ec-primary);
        color: #fff;
        border-color: var(--ec-primary);
        font-weight: 600;
    }
    .ec-refund-filter .count {
        background: rgba(0,0,0,.1);
        padding: .05rem .4rem;
        border-radius: 999px;
        font-size: .7rem;
        margin-left: .25rem;
    }
    .ec-refund-filter.active .count {
        background: rgba(255,255,255,.25);
    }

    .ec-refund-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--ec-text-muted);
    }
    .ec-refund-empty i {
        font-size: 4rem;
        opacity: .3;
        display: block;
        margin-bottom: 1rem;
    }

    /* ═══ Warranty Cards ═══ */
    .ec-warranty-card {
        background: #fff;
        border: 1px solid var(--ec-border);
        border-radius: var(--ec-radius);
        padding: 1.1rem 1.25rem;
        box-shadow: var(--ec-shadow);
        transition: all .18s ease;
        margin-bottom: .75rem;
    }
    .ec-warranty-card:hover {
        box-shadow: var(--ec-shadow-hover);
        border-color: #cbd5e1;
    }

    /* ═══ Modals ═══ */
    .modal-content {
        border-radius: var(--ec-radius);
        border: none;
        box-shadow: 0 20px 60px rgba(0,0,0,.2);
        overflow: hidden;
    }
    .modal-header {
        border-bottom: 1px solid var(--ec-border);
        background: var(--ec-card);
        padding: 1.1rem 1.4rem;
    }
    .modal-title { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; }
    .modal-body { padding: 1.4rem; }
    .modal-footer { border-top: 1px solid var(--ec-border); padding: 1rem 1.4rem; background: #fbfcfc; }

    /* ═══ Responsive ═══ */
    @media (max-width: 767.98px) {
        .ec-order-right { align-items: flex-start; text-align: left; }
        .ec-order-actions { justify-content: stretch; }
        .ec-order-actions .btn { flex: 1; }
        .ec-refund-right { align-items: flex-start; text-align: left; width: 100%; }
        .ec-refund-actions { justify-content: stretch; }
        .ec-refund-actions .btn { flex: 1 1 auto; }
    }
    </style>
</head>
<body>
<div class="ec-page p-4">

    <!-- ═══ HEADER ═══ -->
    <div class="ec-header d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="mb-1">Appointment Scheduling</h1>
            <p class="mb-0">Manage clinic appointments and bookings</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-secondary btn-sm" onclick="refreshPage()">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
            <?php if (canCreateAppointments()): ?>
            <button class="btn ec-btn-primary" data-bs-toggle="modal" data-bs-target="#newAppointmentModal">
                <i class="bi bi-calendar-plus me-1"></i>New Appointment
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ SUMMARY BAR ═══ -->
    <div class="ec-summary mb-4">
        <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Today's Total</span>
                    <span class="ec-summary-value primary"><?= (int)($stats['total'] ?? 0) ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Pending</span>
                    <span class="ec-summary-value warning"><?= (int)($stats['pending'] ?? 0) ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Confirmed</span>
                    <span class="ec-summary-value info"><?= (int)($stats['confirmed'] ?? 0) ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Paid</span>
                    <span class="ec-summary-value success"><?= (int)($stats['paid'] ?? 0) ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Refund Requests</span>
                    <span class="ec-summary-value danger"><?= $pendingRefunds ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">No-Show</span>
                    <span class="ec-summary-value dark"><?= (int)($stats['noshow'] ?? 0) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ TABS ═══ -->
    <div class="ec-tabs mb-3">
        <button class="ec-tab active" data-tab="appointments">
            <i class="bi bi-calendar2-week me-1"></i>Appointments
            <span class="ec-tab-count"><?= count($allAppointments) ?></span>
        </button>
        <button class="ec-tab" data-tab="refunds">
            <i class="bi bi-arrow-repeat me-1"></i>Refunds
            <?php if($pendingRefunds > 0): ?>
            <span class="ec-tab-count" style="background:#fee2e2;color:#991b1b;"><?= $pendingRefunds ?></span>
            <?php else: ?>
            <span class="ec-tab-count">0</span>
            <?php endif; ?>
        </button>
        <button class="ec-tab" data-tab="warranty">
            <i class="bi bi-shield-check me-1"></i>Warranty
            <span class="ec-tab-count" id="warrantyTabCount">0</span>
        </button>
    </div>

    <!-- ═══ FILTER BAR (Appointments Tab) ═══ -->
    <div class="ec-filter-bar mb-4" id="appointmentsFilterBar">
        <div class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" id="filterSearch" placeholder="Search patient, ID, service...">
                </div>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="paid">Paid</option>
                    <option value="completed">Completed</option>
                    <option value="no-show">No-Show</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterPayment">
                    <option value="">All Payment</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                    <option value="forfeited">Forfeited</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterDate">
                    <option value="">All Dates</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="past">Past</option>
                    <option value="upcoming">Upcoming</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary btn-sm w-100" onclick="resetFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ APPOINTMENTS LIST ═══ -->
    <div id="appointmentsContainer">
        <?php if(empty($allAppointments)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                No appointments found.
            </div>
        <?php else: ?>
            <?php foreach($allAppointments as $a): 
                $status = $a['status'] ?? '';
                $statusClass = match($status) {
                    'pending' => 'pending',
                    'confirmed' => 'confirmed',
                    'paid' => 'paid',
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    'no-show' => 'noshow',
                    'refunded' => 'refunded',
                    default => 'default'
                };
                $statusLabel = match($status) {
                    'pending' => 'Pending',
                    'confirmed' => 'Confirmed',
                    'paid' => 'Paid',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'no-show' => 'No-Show',
                    'refunded' => 'Refunded',
                    default => ucfirst($status)
                };
                $statusIcon = match($status) {
                    'pending' => 'hourglass-split',
                    'confirmed' => 'check-circle',
                    'paid' => 'credit-card',
                    'completed' => 'patch-check',
                    'cancelled' => 'x-circle',
                    'no-show' => 'person-x',
                    'refunded' => 'arrow-repeat',
                    default => 'circle'
                };
                $paymentBadge = getPaymentStatusBadge($a);
                $itemTypeBadge = getItemTypeBadge($a);
                $hasDiscount = !empty($a['discount_type']) && $a['discount_type'] !== 'none';
                $apptId = $a['id'] ?? 0;
                $userId = $a['user_id'] ?? 0;
                $patientId = $a['patient_id'] ?? 0;
            ?>
            <div class="ec-order-card appointment-row"
                 data-status="<?= htmlspecialchars($status) ?>"
                 data-payment="<?= htmlspecialchars($a['pay_status'] ?? '') ?>"
                 data-date="<?= htmlspecialchars($a['appointment_date'] ?? '') ?>"
                 data-search="<?= htmlspecialchars(strtolower(($a['patient_name'] ?? '').' '.($a['user_email'] ?? '').' '.($a['product_name'] ?? '').' '.($a['service_name'] ?? ''))) ?>">

                <div class="ec-order-header">
                    <div>
                        <div class="ec-order-id">#<?= str_pad($apptId, 6, '0', STR_PAD_LEFT) ?></div>
                        <div class="ec-order-date">
                            <i class="bi bi-clock me-1"></i>
                            <?= date('M d, Y', strtotime($a['appointment_date'] ?? 'now')) ?> • 
                            <?= date('h:i A', strtotime($a['appointment_time'] ?? '00:00')) ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <?= $itemTypeBadge ?>
                        <span class="ec-badge ec-badge-<?= $statusClass ?>">
                            <i class="bi bi-<?= $statusIcon ?>"></i><?= $statusLabel ?>
                        </span>
                    </div>
                </div>

                <div class="ec-order-body">
                    <div class="ec-order-product">
                        <i class="bi bi-<?= !empty($a['product_name']) ? 'box' : 'star' ?> fs-4 text-muted"></i>
                    </div>

                    <div class="ec-order-info">
                        <div class="ec-order-customer"><?= htmlspecialchars($a['patient_name'] ?? 'N/A') ?></div>
                        <div class="ec-order-product-meta mb-1">
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($a['user_email'] ?? '—') ?>
                            <?php if(!empty($a['user_contact'])): ?>
                                • <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($a['user_contact']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="ec-order-product-name">
                            <?php if(!empty($a['product_name'])): ?>
                                <?= htmlspecialchars($a['product_name']) ?>
                            <?php elseif(!empty($a['service_name'])): ?>
                                <?= htmlspecialchars($a['service_name']) ?>
                            <?php elseif(!empty($a['service_type'])): ?>
                                <?= htmlspecialchars($a['service_type']) ?>
                            <?php else: ?>
                                <em class="text-muted">No item specified</em>
                            <?php endif; ?>
                        </div>
                        <div class="ec-order-product-meta">
                            <?php if(!empty($a['doctor_name'])): ?>
                                <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($a['doctor_name']) ?>
                                <?php if(!empty($a['doctor_specialty'])): ?>
                                    • <?= htmlspecialchars($a['doctor_specialty']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No doctor assigned</span>
                            <?php endif; ?>
                        </div>
                        <?php if($hasDiscount): ?>
                        <div class="mt-1">
                            <span class="ec-badge ec-badge-discount">
                                <i class="bi bi-tag"></i>
                                <?= strtoupper($a['discount_type']) ?> <?= round($a['discount_percentage'] ?? 0) ?>% OFF
                            </span>
                            <?php if(($a['vat_percentage'] ?? 0) == 0): ?>
                                <span class="ec-badge ec-badge-vat ms-1">VAT Exempt</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="ec-order-right">
                        <div class="ec-order-total">₱<?= number_format($a['total_amount'] ?? 0, 2) ?></div>
                        <?= $paymentBadge ?>
                        <?php if(!empty($a['reference_number'])): ?>
                            <small class="text-muted">
                                <i class="bi bi-hash me-1"></i><?= htmlspecialchars($a['reference_number']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ec-order-actions">
                    <a href="appointment-details.php?id=<?= $apptId ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye me-1"></i>View Details
                    </a>

                    <?php if($status === 'pending'): ?>
                        <?php if(canApproveAppointments()): ?>
                            <button class="btn ec-btn-primary btn-sm" onclick="approveAppointment(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>)">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                        <?php endif; ?>
                        <?php if(canRejectAppointments()): ?>
                            <button class="btn btn-outline-danger btn-sm" onclick="rejectModal(<?= $apptId ?>, <?= $userId ?>)">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                        <?php endif; ?>

                    <?php elseif($status === 'confirmed' && canEditAppointments()): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="markArrived(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>)">
                            <i class="bi bi-door-open me-1"></i>Mark Arrived
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="markNoShow(<?= $apptId ?>, <?= $userId ?>)">
                            <i class="bi bi-person-x me-1"></i>No-show
                        </button>

                    <?php elseif($status === 'paid' && canEditAppointments()): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="updateStatus(<?= $apptId ?>, <?= $userId ?>, 'completed')">
                            <i class="bi bi-patch-check me-1"></i>Complete
                        </button>

                    <?php elseif($status === 'no-show'): ?>
                        <span class="ec-badge ec-badge-noshow">
                            <i class="bi bi-person-x"></i>No-show
                        </span>

                    <?php elseif(in_array($status, ['completed','cancelled','refunded'])): ?>
                        <span class="text-muted small">Done</span>
                    <?php endif; ?>

                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" 
                                type="button"
                                data-bs-toggle="dropdown" 
                                data-bs-auto-close="outside"
                                aria-expanded="false"
                                onclick="event.stopPropagation();">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end ec-dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="appointment-details.php?id=<?= $apptId ?>">
                                    <i class="bi bi-eye me-2"></i>View Details
                                </a>
                            </li>
                            <?php if($status === 'pending' && canApproveAppointments()): ?>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="approveAppointment(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>);return false;">
                                        <i class="bi bi-check-lg me-2"></i>Approve
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if($status === 'pending' && canRejectAppointments()): ?>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="rejectModal(<?= $apptId ?>, <?= $userId ?>);return false;">
                                        <i class="bi bi-x-lg me-2"></i>Reject
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if($status === 'confirmed' && canEditAppointments()): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="markArrived(<?= $apptId ?>, <?= $userId ?>, <?= $patientId ?>);return false;">
                                        <i class="bi bi-door-open me-2"></i>Mark Arrived
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="markNoShow(<?= $apptId ?>, <?= $userId ?>);return false;">
                                        <i class="bi bi-person-x me-2"></i>Mark No-show
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if($status === 'paid' && canEditAppointments()): ?>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="updateStatus(<?= $apptId ?>, <?= $userId ?>, 'completed');return false;">
                                        <i class="bi bi-patch-check me-2"></i>Mark Completed
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="noAppointmentsMsg" class="text-center py-5 text-muted d-none">
        <i class="bi bi-search fs-1 d-block mb-3 opacity-50"></i>
        No appointments match your filters
    </div>

    <!-- ═══ REFUND REQUESTS CONTAINER ═══ -->
    <div id="refundContainer" class="ec-refund-container">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-arrow-repeat me-2"></i>Refund Requests</h5>
                <p class="text-muted small mb-0">Manage customer refund requests</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
        </div>

        <!-- Filter Pills -->
        <div class="ec-refund-filters" id="refundFilters">
            <button class="ec-refund-filter active" data-filter="">All <span class="count" id="refundCountAll">0</span></button>
            <button class="ec-refund-filter" data-filter="pending">Pending <span class="count" id="refundCountPending">0</span></button>
            <button class="ec-refund-filter" data-filter="processing">Processing <span class="count" id="refundCountProcessing">0</span></button>
            <button class="ec-refund-filter" data-filter="failed">Failed <span class="count" id="refundCountFailed">0</span></button>
            <button class="ec-refund-filter" data-filter="completed">Completed <span class="count" id="refundCountCompleted">0</span></button>
            <button class="ec-refund-filter" data-filter="rejected">Rejected <span class="count" id="refundCountRejected">0</span></button>
        </div>

        <!-- Refund List -->
        <div id="refundList">
            <?php if (canEditAppointments()): ?>
                <?php if(empty($refundList)): ?>
                    <div class="ec-refund-empty">
                        <i class="bi bi-arrow-repeat"></i>
                        <p>No refund requests.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($refundList as $r): 
                        $refundStatus = $r['refund_status'] ?? $r['status'];
                        $statusClass = match($refundStatus) {
                            'completed' => 'completed',
                            'processing' => 'processing',
                            'failed' => 'failed',
                            'rejected' => 'rejected',
                            default => 'pending'
                        };
                        $statusLabel = match($refundStatus) {
                            'completed' => 'Completed',
                            'processing' => 'Processing',
                            'failed' => 'Failed',
                            'rejected' => 'Rejected',
                            default => 'Pending'
                        };
                        $statusIcon = match($refundStatus) {
                            'completed' => 'check2-all',
                            'processing' => 'arrow-repeat',
                            'failed' => 'exclamation-triangle',
                            'rejected' => 'x-circle',
                            default => 'hourglass-split'
                        };
                    ?>
                    <div class="ec-refund-card refund-item" data-status="<?= htmlspecialchars($refundStatus) ?>">
                        <div class="ec-refund-header">
                            <div>
                                <div class="ec-refund-id">#<?= htmlspecialchars($r['appointment_id'] ?? '—') ?></div>
                                <div class="ec-refund-date">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= date('M d, Y g:i A', strtotime($r['created_at'] ?? 'now')) ?>
                                </div>
                            </div>
                            <span class="ec-refund-status ec-refund-<?= $statusClass ?>">
                                <i class="bi bi-<?= $statusIcon ?>"></i><?= $statusLabel ?>
                            </span>
                        </div>

                        <div class="ec-refund-body">
                            <div class="ec-refund-product">
                                <i class="bi bi-box fs-4 text-muted"></i>
                            </div>
                            <div class="ec-refund-info">
                                <div class="ec-refund-customer">
                                    <?= htmlspecialchars(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?>
                                </div>
                                <div class="ec-refund-product-name">
                                    <?= htmlspecialchars($r['product_name'] ?? 'N/A') ?>
                                </div>
                                <div class="ec-refund-reason">
                                    <strong>Reason:</strong> <?= htmlspecialchars($r['reason'] ?? 'No reason given') ?>
                                </div>
                            </div>
                            <div class="ec-refund-right">
                                <div class="ec-refund-amount">₱<?= number_format($r['amount'] ?? 0, 2) ?></div>
                                <?php if (($r['refund_percentage'] ?? 0) > 0): ?>
                                    <small class="text-muted"><?= $r['refund_percentage'] ?>% refund</small>
                                <?php endif; ?>
                                <?php if (!empty($r['paymongo_refund_id'])): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-qr-code me-1"></i><?= htmlspecialchars($r['paymongo_refund_id']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($r['error_message'])): ?>
                        <div class="alert alert-danger small py-1 mb-2 mt-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>Error:</strong> <?= htmlspecialchars($r['error_message']) ?>
                        </div>
                        <?php endif; ?>

                        <div class="ec-refund-actions">
                            <?php if ($r['status'] === 'pending'): ?>
                                <textarea class="form-control form-control-sm mb-2" 
                                          id="adminNotes-<?= $r['id'] ?>" 
                                          placeholder="Admin notes (optional)" rows="2"></textarea>
                                <button class="btn btn-success btn-sm" 
                                    onclick="processRefundAuto(<?= $r['id'] ?>, <?= $r['user_id'] ?>, document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                    <i class="bi bi-lightning-charge me-1"></i>Process Refund Now
                                </button>
                                <button class="btn btn-outline-danger btn-sm" 
                                    onclick="rejectRefund(<?= $r['id'] ?>, <?= $r['user_id'] ?>, document.getElementById('adminNotes-<?= $r['id'] ?>').value)">
                                    <i class="bi bi-x-circle me-1"></i>Reject
                                </button>
                            
                            <?php elseif ($refundStatus === 'processing'): ?>
                                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                                </button>
                            
                            <?php elseif ($refundStatus === 'failed'): ?>
                                <button class="btn btn-warning btn-sm" onclick="retryRefundAuto(<?= $r['id'] ?>, <?= $r['user_id'] ?>)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Retry Auto
                                </button>
                                <button class="btn btn-outline-success btn-sm" onclick="markRefundManual(<?= $r['id'] ?>, <?= $r['user_id'] ?>)">
                                    <i class="bi bi-check-circle me-1"></i>Mark Manual
                                </button>
                            
                            <?php elseif ($refundStatus === 'completed'): ?>
                                <span class="text-success small">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Refunded on <?= date('M d, Y g:i A', strtotime($r['refund_date'] ?? $r['updated_at'] ?? 'now')) ?>
                                </span>
                            
                            <?php elseif ($r['status'] === 'rejected'): ?>
                                <span class="text-muted small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Rejected on <?= date('M d, Y', strtotime($r['updated_at'] ?? $r['created_at'] ?? 'now')) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="ec-refund-empty">
                    <i class="bi bi-lock"></i>
                    <p>You don't have permission to view refund requests.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ WARRANTY CONTAINER ═══ -->
    <div id="warrantyContainer" class="ec-refund-container">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-shield-check me-2"></i>Warranty Claims</h5>
                <p class="text-muted small mb-0">Manage warranty claims from customers</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadWarrantyClaims()">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
        </div>

        <!-- Filter Bar -->
        <div class="ec-filter-bar mb-4">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="warrantySearch" placeholder="Search claim #, customer...">
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="warrantyStatusFilter">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="reviewing">Reviewing</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="warrantyDateFrom">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="warrantyDateTo">
                </div>
            </div>
        </div>

        <!-- Warranty List -->
        <div id="warrantyClaimsTableBody">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-3 mb-0">Loading warranty claims...</p>
            </div>
        </div>
    </div>

    <!-- ═══ MODALS (ALL PRESERVED) ═══ -->
    
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
                        <button type="submit" class="btn ec-btn-primary">
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
                <div class="modal-header" style="background: linear-gradient(135deg, #0d6e6e, #095050); color: white;">
                    <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Warranty Claim</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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

                        <div id="reject_reason_box" class="mt-3" style="display:none;">
                            <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="claim_rejection_reason" rows="2" 
                                      placeholder="Bakit hindi ma-approve ang claim?"></textarea>
                        </div>
                    </div>

                    <div id="form_approved" style="display:none;">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            This claim is <strong>approved</strong>. Waiting for patient to arrive on 
                            <strong><span id="approved_schedule_display"></span></strong>.
                        </div>
                    </div>

                    <div id="form_arrived" style="display:none;">
                        <div class="alert alert-primary">
                            <i class="bi bi-person-check me-2"></i>
                            Patient has <strong>arrived</strong>. Mark as completed once done.
                        </div>
                    </div>

                    <div id="form_completed" style="display:none;">
                        <div class="alert alert-success">
                            <i class="bi bi-patch-check me-2"></i>
                            This warranty claim has been <strong>completed</strong>.
                        </div>
                    </div>

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
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SCRIPTS - ALL PRESERVED ═══ -->
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
// TAB SWITCHING
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    document.querySelectorAll('.ec-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.ec-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            const tabName = this.dataset.tab;
            
            // Hide all containers
            document.getElementById('appointmentsContainer').style.display = 'none';
            document.getElementById('appointmentsFilterBar').style.display = 'none';
            document.getElementById('noAppointmentsMsg').classList.add('d-none');
            document.getElementById('refundContainer').classList.remove('active');
            document.getElementById('warrantyContainer').classList.remove('active');
            
            // Show selected
            if (tabName === 'appointments') {
                document.getElementById('appointmentsContainer').style.display = '';
                document.getElementById('appointmentsFilterBar').style.display = '';
                applyFilters();
            } else if (tabName === 'refunds') {
                document.getElementById('refundContainer').classList.add('active');
                updateRefundCounts();
            } else if (tabName === 'warranty') {
                document.getElementById('warrantyContainer').classList.add('active');
                loadWarrantyClaims();
            }
        });
    });

    // Filter listeners
    document.getElementById('filterSearch')?.addEventListener('input', applyFilters);
    document.getElementById('filterStatus')?.addEventListener('change', applyFilters);
    document.getElementById('filterPayment')?.addEventListener('change', applyFilters);
    document.getElementById('filterDate')?.addEventListener('change', applyFilters);

    // Refund filter pills
    document.querySelectorAll('.ec-refund-filter').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.ec-refund-filter').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const filter = this.dataset.filter || '';
            filterRefunds(filter);
        });
    });

    // Warranty search
    document.getElementById('warrantySearch')?.addEventListener('input', debounce(loadWarrantyClaims, 300));
    document.getElementById('warrantyStatusFilter')?.addEventListener('change', loadWarrantyClaims);
    document.getElementById('warrantyDateFrom')?.addEventListener('change', loadWarrantyClaims);
    document.getElementById('warrantyDateTo')?.addEventListener('change', loadWarrantyClaims);

    // Item select handler
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

    // New appointment form
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

    // Initial filter
    applyFilters();

    // Load warranty claims
    loadWarrantyClaims();
});

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const search = document.getElementById('filterSearch')?.value.toLowerCase().trim() || '';
    const status = document.getElementById('filterStatus')?.value || '';
    const payment = document.getElementById('filterPayment')?.value || '';
    const dateFilter = document.getElementById('filterDate')?.value || '';

    let visible = 0;
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    document.querySelectorAll('.appointment-row').forEach(row => {
        const rowStatus = row.dataset.status || '';
        const rowPayment = row.dataset.payment || '';
        const rowDate = row.dataset.date || '';
        const rowSearch = row.dataset.search || '';

        let show = true;

        if (status && rowStatus !== status) show = false;
        if (payment && rowPayment !== payment) show = false;
        if (search && !rowSearch.includes(search)) show = false;

        if (dateFilter && rowDate) {
            const apptDate = new Date(rowDate);
            apptDate.setHours(0, 0, 0, 0);
            const weekAgo = new Date(today);
            weekAgo.setDate(weekAgo.getDate() - 7);
            const monthAgo = new Date(today);
            monthAgo.setMonth(monthAgo.getMonth() - 1);

            if (dateFilter === 'today' && apptDate.getTime() !== today.getTime()) show = false;
            else if (dateFilter === 'week' && apptDate < weekAgo) show = false;
            else if (dateFilter === 'month' && apptDate < monthAgo) show = false;
            else if (dateFilter === 'past' && apptDate >= today) show = false;
            else if (dateFilter === 'upcoming' && apptDate < today) show = false;
        }

        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('noAppointmentsMsg').classList.toggle('d-none', visible > 0);
}

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterPayment').value = '';
    document.getElementById('filterDate').value = '';
    applyFilters();
}

// ============================================
// REFUND FUNCTIONS
// ============================================
function updateRefundCounts() {
    const items = document.querySelectorAll('.refund-item');
    const counts = { all: 0, pending: 0, processing: 0, failed: 0, completed: 0, rejected: 0 };
    
    items.forEach(item => {
        counts.all++;
        const status = item.dataset.status;
        if (counts[status] !== undefined) counts[status]++;
    });

    document.getElementById('refundCountAll').textContent = counts.all;
    document.getElementById('refundCountPending').textContent = counts.pending;
    document.getElementById('refundCountProcessing').textContent = counts.processing;
    document.getElementById('refundCountFailed').textContent = counts.failed;
    document.getElementById('refundCountCompleted').textContent = counts.completed;
    document.getElementById('refundCountRejected').textContent = counts.rejected;
}

function filterRefunds(filter) {
    document.querySelectorAll('.refund-item').forEach(item => {
        const itemStatus = item.dataset.status || 'pending';
        if (!filter || itemStatus === filter) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
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
// REFUND FUNCTIONS - PRESERVED
// ============================================
function processRefundAuto(refundId, userId, adminNotes) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to process refunds', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Process Refund Automatically?',
        html: `
            <div style="text-align:left;">
                <p>This will refund the customer via <strong>PayMongo Refunds API</strong>.</p>
                <div style="background:#D1FAE5;padding:10px;border-radius:8px;margin:10px 0;">
                    <strong style="color:#065F46;">✅ Automatic</strong>
                    <div style="color:#065F46;font-size:13px;">No manual step needed. Refund is sent directly.</div>
                </div>
                <p class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Cards: 3-5 business days. E-wallets: usually instant.
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Refund Now',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ 
                title: 'Processing refund...', 
                html: 'Please wait. Do not close this window.',
                allowOutsideClick: false, 
                didOpen: () => Swal.showLoading() 
            });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: 'approve',
                    admin_notes: adminNotes || ''
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Refund Processed! ✅',
                        html: `
                            <p>${data.message}</p>
                            ${data.refund_id ? `<small class="text-muted">Refund ID: ${data.refund_id}</small>` : ''}
                        `,
                        confirmButtonText: 'Okay'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Refund Failed',
                        html: `
                            <p>${data.message}</p>
                            ${data.requires_manual ? '<div class="alert alert-warning mt-2 small">Manual intervention required. Please process via PayMongo dashboard.</div>' : ''}
                        `
                    }).then(() => location.reload());
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Network Error', 'Please check your connection and try again.', 'error');
            });
        }
    });
}

function rejectRefund(refundId, userId, adminNotes) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to reject refunds', 'error');
        return;
    }
    
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
            Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: 'reject',
                    admin_notes: (adminNotes || '') + ' | ' + result.value
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Rejected', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(() => {
                Swal.close();
                Swal.fire('Error', 'Network error', 'error');
            });
        }
    });
}

function retryRefundAuto(refundId, userId) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Retry Refund?',
        text: 'This will attempt to process the refund again via PayMongo Refunds API.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Retry'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Retrying...', didOpen: () => Swal.showLoading() });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: 'retry',
                    admin_notes: 'Retry after failed attempt'
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Retry Failed', data.message, 'error');
                }
            })
            .catch(() => {
                Swal.close();
                Swal.fire('Error', 'Network error', 'error');
            });
        }
    });
}

function markRefundManual(refundId, userId) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Mark as Manually Refunded?',
        html: `
            <p>This will mark the refund as <strong>completed</strong> without calling PayMongo API.</p>
            <div class="alert alert-warning mt-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Make sure you have already processed the refund on the PayMongo dashboard.
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#6c757d',
        confirmButtonText: 'Yes, mark as refunded'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
            
            fetch('api/appointments.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'process_refund',
                    refund_id: refundId,
                    user_id: userId,
                    refund_action: 'mark_manual',
                    admin_notes: 'Manually marked as refunded by admin'
                })
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Success!', data.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(() => {
                Swal.close();
                Swal.fire('Error', 'Network error', 'error');
            });
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
    
    const container = document.getElementById('warrantyClaimsTableBody');
    if (container) {
        container.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 mb-0">Loading...</p></div>';
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
            
            updateWarrantyList(claims);
            
            const badge = document.getElementById('warrantyTabCount');
            if (badge) {
                const pendingCount = claims.filter(c => c.status === 'pending').length;
                badge.textContent = pendingCount;
            }
        })
        .catch(err => {
            console.error('Error loading warranty claims:', err);
            const container = document.getElementById('warrantyClaimsTableBody');
            if (container) {
                container.innerHTML = '<div class="ec-refund-empty"><i class="bi bi-exclamation-triangle"></i><p>Error loading claims</p></div>';
            }
        });
}

function updateWarrantyList(claims) {
    const container = document.getElementById('warrantyClaimsTableBody');
    if (!container) return;
    
    if (!claims || claims.length === 0) {
        container.innerHTML = '<div class="ec-refund-empty"><i class="bi bi-inbox"></i><p>No warranty claims found</p></div>';
        return;
    }
    
    container.innerHTML = claims.map(claim => {
        let statusClass = '';
        let statusLabel = '';
        let statusIcon = '';
        
        switch(claim.status) {
            case 'pending': 
                statusClass = 'pending'; statusLabel = 'Pending'; statusIcon = 'hourglass-split';
                break;
            case 'reviewing': 
                statusClass = 'processing'; statusLabel = 'Reviewing'; statusIcon = 'search';
                break;
            case 'approved': 
                statusClass = 'approved'; statusLabel = 'Approved'; statusIcon = 'check-circle';
                break;
            case 'rejected': 
                statusClass = 'rejected'; statusLabel = 'Rejected'; statusIcon = 'x-circle';
                break;
            case 'completed': 
                statusClass = 'completed'; statusLabel = 'Completed'; statusIcon = 'check2-all';
                break;
            default: 
                statusClass = 'pending'; statusLabel = claim.status || 'Unknown'; statusIcon = 'circle';
        }
        
        const scheduleDate = claim.schedule_date ? new Date(claim.schedule_date).toLocaleDateString() : '—';
        const scheduleTime = claim.schedule_time || '';
        const scheduleDisplay = scheduleDate !== '—' ? scheduleDate + (scheduleTime ? ' ' + scheduleTime : '') : '—';
        
        return `
            <div class="ec-refund-card">
                <div class="ec-refund-header">
                    <div>
                        <div class="ec-refund-id">${escapeHtml(claim.claim_number || '—')}</div>
                        <div class="ec-refund-date">
                            <i class="bi bi-clock me-1"></i>
                            ${claim.created_at ? new Date(claim.created_at).toLocaleDateString() : '—'}
                        </div>
                    </div>
                    <span class="ec-refund-status ec-refund-${statusClass}">
                        <i class="bi bi-${statusIcon}"></i>${statusLabel}
                    </span>
                </div>
                <div class="ec-refund-body">
                    <div class="ec-refund-product">
                        <i class="bi bi-shield-check fs-4 text-muted"></i>
                    </div>
                    <div class="ec-refund-info">
                        <div class="ec-refund-customer">${escapeHtml(claim.first_name || '')} ${escapeHtml(claim.last_name || '')}</div>
                        <div class="ec-refund-product-name">${escapeHtml(claim.product_name || '—')}</div>
                        <div class="ec-refund-reason">
                            <strong>Issue:</strong> ${escapeHtml((claim.issue_description || '—').substring(0, 80))}${(claim.issue_description || '').length > 80 ? '...' : ''}
                        </div>
                    </div>
                    <div class="ec-refund-right">
                        <small class="text-muted">Schedule: ${scheduleDisplay}</small>
                    </div>
                </div>
                <div class="ec-refund-actions">
                    <button class="btn btn-outline-secondary btn-sm" onclick="openReviewModal(${claim.id}, ${claim.user_id})">
                        <i class="bi bi-eye me-1"></i>Review
                    </button>
                </div>
            </div>
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
                <button type="button" class="btn ec-btn-primary" onclick="submitWarrantyClaim('approved')">
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
                <button type="button" class="btn ec-btn-primary" onclick="submitWarrantyClaim('arrived')">
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
</script>
</body>
</html>