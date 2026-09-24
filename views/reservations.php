<?php
// eyecore/views/reservations.php — Order Management (Clinic Side) with Hybrid Flow
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

function canViewReservations()   { return RBACHelper::hasPermission('reservations_view'); }
function canCreateReservations() { return RBACHelper::hasPermission('reservations_create'); }
function canEditReservations()   { return RBACHelper::hasPermission('reservations_edit'); }
function canDeleteReservations() { return RBACHelper::hasPermission('reservations_delete'); }
function canApproveReservations(){ return RBACHelper::hasPermission('reservations_approve'); }
function canRejectReservations() { return RBACHelper::hasPermission('reservations_reject'); }

if (!canViewReservations()) {
    echo '<div class="alert alert-danger">You do not have permission to view orders.</div>';
    exit;
}

// ── FETCH STATS ──
$stmtStats = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(status='pending') AS pending,
           SUM(status='confirmed') AS confirmed,
           SUM(status='preparing') AS preparing,
           SUM(status='ready_for_pickup') AS ready,
           SUM(status='dispatched') AS dispatched,
           SUM(status='delivered' OR status='completed') AS completed,
           SUM(status='cancelled') AS cancelled,
           SUM(fulfillment_type='pickup') AS pickup,
           SUM(fulfillment_type='delivery') AS delivery,
           SUM(DATE(created_at)=CURDATE()) AS today
    FROM reservations WHERE clinic_id=?
");
$stmtStats->execute([$clinicId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ── FETCH ALL ORDERS ──
$stmt = $pdo->prepare("
    SELECT r.*,
           CONCAT(u.first_name,' ',u.last_name) AS user_name,
           u.email AS user_email, u.contact AS user_contact,
           p.name AS product_name, p.category AS product_category, p.image AS product_image,
           rd.name AS rider_name,
           cu.first_name AS collector_first, cu.last_name AS collector_last,
           -- ✅ BAGO: Prescription data
           up.od_sph, up.od_cyl, up.od_axis,
           up.os_sph, up.os_cyl, up.os_axis,
           up.prescription_source
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN products p ON r.product_id = p.id
    LEFT JOIN riders rd ON r.assigned_rider_id = rd.id
    LEFT JOIN users cu ON r.collected_by = cu.id
    LEFT JOIN user_prescriptions up ON r.prescription_id = up.id
    WHERE r.clinic_id = ?
    ORDER BY r.created_at DESC
    LIMIT 200
");
$stmt->execute([$clinicId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── FETCH RIDERS ──
$rStmt = $pdo->prepare("SELECT id, name, phone, vehicle_type, plate_number, is_available FROM riders WHERE clinic_id=? AND status='active' ORDER BY is_available DESC, name ASC");
$rStmt->execute([$clinicId]);
$riders = $rStmt->fetchAll(PDO::FETCH_ASSOC);
$rStmt = $pdo->prepare("SELECT id, name, phone, vehicle_type, plate_number, is_available FROM riders WHERE clinic_id=? AND status='active' ORDER BY is_available DESC, name ASC");
$rStmt->execute([$clinicId]);
$riders = $rStmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ FIXED: Return method helpers
function getReturnMethodInfo($method) {
    $map = [
        'dropoff' => [
            'label' => 'Drop-off at Clinic',
            'desc'  => 'Customer will bring the item to the clinic',
            'icon'  => 'fa-store',
            'color' => 'primary'
        ],
        'pickup' => [
            'label' => 'Clinic Pickup',
            'desc'  => 'Clinic will pick up the item from customer',
            'icon'  => 'fa-truck',
            'color' => 'warning'
        ],
        'courier' => [
            'label' => 'Courier / Ship',
            'desc'  => 'Customer will ship via courier (Lalamove, Grab, etc.)',
            'icon'  => 'fa-shipping-fast',
            'color' => 'info'
        ]
    ];
    return $map[$method] ?? $map['dropoff'];
}

function getReturnStatusInfo($status) {
    $map = [
        'pending'    => ['label' => 'Awaiting Approval', 'class' => 'pending',    'icon' => 'hourglass-split'],
        'scheduled'  => ['label' => 'Return Scheduled',  'class' => 'approved',   'icon' => 'calendar-check'],
        'picked_up'  => ['label' => 'Item Picked Up',    'class' => 'processing', 'icon' => 'box-open'],
        'received'   => ['label' => 'Item Received',     'class' => 'processing', 'icon' => 'check'],
        'inspecting' => ['label' => 'Inspecting',        'class' => 'processing', 'icon' => 'search'],
        'completed'  => ['label' => 'Return Completed',  'class' => 'completed',  'icon' => 'check-all'],
        'rejected'   => ['label' => 'Return Rejected',   'class' => 'rejected',   'icon' => 'x-circle'],
    ];
    return $map[$status] ?? $map['pending'];
}
?>

<style>
/* ✅ CRITICAL FIX: Allow flex parents to shrink properly */
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
}

/* ✅ CRITICAL: Prevent any horizontal overflow */
.ec-page,
.ec-page * {
    box-sizing: border-box;
}
.ec-page {
    color: var(--ec-text);
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
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

/* ✅ Tabs — contained with scroll */
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

.ec-bulk-toolbar {
    position: sticky; top: 0; z-index: 100;
    background: var(--ec-primary);
    color: #fff;
    border-radius: var(--ec-radius);
    padding: .75rem 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 12px rgba(13,110,110,.25);
    display: none;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.ec-bulk-toolbar.show { display: flex; }
.ec-bulk-toolbar .ec-bulk-count {
    font-weight: 600; font-size: .9rem;
    background: rgba(255,255,255,.15);
    padding: .35rem .75rem;
    border-radius: 999px;
}
.ec-bulk-toolbar .btn { font-size: .8rem; border-radius: 6px; }
.ec-bulk-toolbar .btn-light { background: #fff; color: var(--ec-primary); border: none; }
.ec-bulk-toolbar .btn-outline-light { color: #fff; border-color: rgba(255,255,255,.5); }
.ec-bulk-toolbar .btn-outline-light:hover { background: rgba(255,255,255,.15); color: #fff; }

.ec-select-all-bar {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem .75rem; margin-bottom: .5rem;
    font-size: .85rem; color: var(--ec-text-muted);
}

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
.ec-order-card:has(.dropdown-menu.show) {
    transform: none !important;
    box-shadow: var(--ec-shadow) !important;
    border-color: var(--ec-border) !important;
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
.ec-order-check {
    width: 18px; height: 18px;
    accent-color: var(--ec-primary);
    cursor: pointer; flex-shrink: 0; margin-top: 2px;
}

.ec-badge {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .2rem .55rem; border-radius: 6px;
    font-size: .7rem; font-weight: 600; line-height: 1.4;
}
.ec-badge-pending    { background: #fef3c7; color: #92400e; }
.ec-badge-confirmed  { background: #dbeafe; color: #1e40af; }
.ec-badge-preparing  { background: #ede9fe; color: #5b21b6; }
.ec-badge-ready      { background: #dcfce7; color: #166534; }
.ec-badge-dispatched { background: #dbeafe; color: #1e40af; }
.ec-badge-delivered  { background: #dcfce7; color: #166534; }
.ec-badge-completed  { background: #16a34a; color: #fff; }
.ec-badge-cancelled  { background: #fee2e2; color: #991b1b; }
.ec-badge-pickup     { background: #dcfce7; color: #166534; }
.ec-badge-delivery   { background: #dbeafe; color: #1e40af; }
.ec-badge-paid       { background: #dcfce7; color: #166534; }
.ec-badge-unpaid     { background: #fee2e2; color: #991b1b; }
.ec-badge-partial    { background: #fef3c7; color: #92400e; }
.ec-badge-cod        { background: #fef3c7; color: #92400e; border: 1px dashed #d97706; }
.ec-badge-onsite     { background: #fef3c7; color: #92400e; border: 1px dashed #d97706; }
.ec-badge-refunded   { background: #f1f5f9; color: #475569; }
.ec-badge-default    { background: #f1f5f9; color: #475569; }

.ec-timeline { position: relative; padding-left: 1.75rem; }
.ec-timeline-item { position: relative; padding-bottom: 1.25rem; }
.ec-timeline-item:last-child { padding-bottom: 0; }
.ec-timeline-item::before {
    content: ''; position: absolute; left: -1.5rem; top: 1.25rem; bottom: -.25rem;
    width: 2px; background: var(--ec-border);
}
.ec-timeline-item:last-child::before { display: none; }
.ec-timeline-dot {
    position: absolute; left: -1.75rem; top: 0;
    width: 1.25rem; height: 1.25rem; border-radius: 50%;
    background: #fff; border: 2px solid var(--ec-border);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; color: #fff;
}
.ec-timeline-item.done .ec-timeline-dot { background: #16a34a; border-color: #16a34a; }
.ec-timeline-item.current .ec-timeline-dot { background: var(--ec-primary); border-color: var(--ec-primary); box-shadow: 0 0 0 4px var(--ec-primary-light); }
.ec-timeline-title { font-size: .875rem; font-weight: 600; }
.ec-timeline-time  { font-size: .75rem; color: var(--ec-text-muted); }

.ec-detail-section { margin-bottom: 1.25rem; }
.ec-detail-section-title {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
    color: var(--ec-text-muted); font-weight: 700; margin-bottom: .5rem;
    display: flex; align-items: center; gap: .35rem;
}
.ec-detail-row { display: flex; justify-content: space-between; font-size: .85rem; padding: .3rem 0; }
.ec-detail-row .label { color: var(--ec-text-muted); }
.ec-detail-row .value { font-weight: 500; text-align: right; }

.ec-btn-primary { background: var(--ec-primary); border-color: var(--ec-primary); color: #fff; }
.ec-btn-primary:hover { background: var(--ec-primary-dark); border-color: var(--ec-primary-dark); color: #fff; }

.ec-dropdown-menu {
    border: 1px solid var(--ec-border); border-radius: var(--ec-radius-sm);
    box-shadow: var(--ec-shadow-hover); padding: .35rem;
    font-size: .85rem; min-width: 220px;
}
.ec-dropdown-menu .dropdown-item { border-radius: 6px; padding: .45rem .65rem; }
.ec-dropdown-menu .dropdown-item:hover { background: var(--ec-bg-soft); }

.ec-collect-hint {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .7rem; font-weight: 600;
    color: #92400e; background: #fef3c7;
    border: 1px dashed #d97706;
    padding: .15rem .5rem; border-radius: 6px;
    margin-top: .2rem;
}

.ec-status-pill {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .4rem .75rem; border-radius: 8px;
    font-size: .8rem; font-weight: 600;
    background: #dbeafe; color: #1e40af;
}
.ec-status-pill.picked { background: #ede9fe; color: #5b21b6; }
.ec-status-pill.transit { background: #dbeafe; color: #1e40af; }

.ec-proof-section {
    background: var(--ec-bg-soft);
    border: 1px solid var(--ec-border);
    border-radius: var(--ec-radius);
    padding: .75rem;
}
.ec-proof-img {
    width: 100%;
    max-height: 300px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--ec-border);
    cursor: pointer;
    transition: transform .2s;
}
.ec-proof-img:hover { transform: scale(1.02); }

/* ═══════════════════════════════════════════════════════════════ */
/* REFUND REQUESTS STYLES                                          */
/* ═══════════════════════════════════════════════════════════════ */

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

.ec-refund-id {
    font-weight: 700;
    font-size: .95rem;
    color: var(--ec-primary);
}

.ec-refund-date {
    font-size: .75rem;
    color: var(--ec-text-muted);
}

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
}

.ec-refund-info {
    flex: 1 1 200px;
    min-width: 0;
    overflow: hidden;
}

.ec-refund-customer {
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: .15rem;
}

.ec-refund-product-name {
    font-size: .875rem;
    color: var(--ec-text);
    margin-bottom: .1rem;
    word-break: break-word;
}

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

.ec-refund-reason strong {
    color: #92400e;
}

.ec-refund-right {
    text-align: right;
    min-width: 140px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: .35rem;
    align-items: flex-end;
}

.ec-refund-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: #dc2626;
}

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

.ec-refund-detail-row {
    display: flex;
    justify-content: space-between;
    font-size: .85rem;
    padding: .35rem 0;
    border-bottom: 1px solid var(--ec-border);
    gap: 1rem;
}
.ec-refund-detail-row:last-child { border-bottom: none; }
.ec-refund-detail-row .label { color: var(--ec-text-muted); font-weight: 500; flex-shrink: 0; }
.ec-refund-detail-row .value { font-weight: 600; text-align: right; word-break: break-word; }

.ec-refund-method-option {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .75rem 1rem;
    border: 2px solid var(--ec-border);
    border-radius: var(--ec-radius-sm);
    cursor: pointer;
    transition: all .2s;
    margin-bottom: .5rem;
    background: #fff;
}
.ec-refund-method-option:hover {
    border-color: var(--ec-primary);
    background: var(--ec-primary-light);
}
.ec-refund-method-option.selected {
    border-color: var(--ec-primary);
    background: var(--ec-primary-light);
}
.ec-refund-method-option input[type="radio"] {
    width: 18px;
    height: 18px;
    accent-color: var(--ec-primary);
    cursor: pointer;
}
.ec-refund-method-option .method-info { flex: 1; }
.ec-refund-method-option .method-name { font-weight: 600; font-size: .875rem; color: var(--ec-text); }
.ec-refund-method-option .method-desc { font-size: .75rem; color: var(--ec-text-muted); margin-top: .1rem; }
.ec-refund-method-option i { font-size: 1.25rem; color: var(--ec-primary); }

@media (max-width: 767.98px) {
    .ec-order-right { align-items: flex-start; text-align: left; }
    .ec-order-actions { justify-content: stretch; }
    .ec-order-actions .btn { flex: 1; }
    .ec-refund-right { align-items: flex-start; text-align: left; width: 100%; }
    .ec-refund-actions { justify-content: stretch; }
    .ec-refund-actions .btn { flex: 1 1 auto; }
}

/* ✅ FIXED: Return Method Display */
.ec-return-method-box {
    background: #f8fafc;
    border: 1px solid var(--ec-border);
    border-radius: var(--ec-radius);
    padding: .75rem 1rem;
    margin-top: .75rem;
    margin-bottom: .75rem;
}

.ec-return-method-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-weight: 700;
    font-size: .875rem;
    color: var(--ec-text);
    margin-bottom: .5rem;
    padding-bottom: .5rem;
    border-bottom: 1px dashed var(--ec-border);
}

.ec-return-method-header i {
    color: var(--ec-primary);
    font-size: 1rem;
}

.ec-return-badge {
    margin-left: auto;
    padding: .15rem .55rem;
    border-radius: 6px;
    font-size: .65rem;
    font-weight: 700;
    letter-spacing: .04em;
    white-space: nowrap;
}
.ec-return-primary { background: #dbeafe; color: #1e40af; }
.ec-return-warning { background: #fef3c7; color: #92400e; }
.ec-return-info    { background: #e0e7ff; color: #3730a3; }

.ec-return-method-details {
    display: flex;
    flex-direction: column;
    gap: .4rem;
}

.ec-return-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    font-size: .8rem;
    padding: .15rem 0;
}

.ec-return-detail-row .lbl {
    color: var(--ec-text-muted);
    font-weight: 500;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}

.ec-return-detail-row .lbl i {
    font-size: .75rem;
}

.ec-return-detail-row .val {
    color: var(--ec-text);
    font-weight: 600;
    text-align: right;
    word-break: break-word;
    max-width: 65%;
}

@media (max-width: 767.98px) {
    .ec-return-detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: .15rem;
    }
    .ec-return-detail-row .val {
        text-align: left;
        max-width: 100%;
        padding-left: 1.1rem;
    }
}

/* ✅ FIXED: Evidence Grid (multiple images) */
.ec-refund-evidence-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: .5rem;
    width: 100%;
}

.ec-evidence-thumb {
    position: relative;
    aspect-ratio: 1;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--ec-border);
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
    background: var(--ec-bg-soft);
}

.ec-evidence-thumb:hover {
    transform: scale(1.03);
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}

.ec-evidence-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.ec-evidence-number {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(0,0,0,.7);
    color: #fff;
    font-size: .7rem;
    font-weight: 700;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<div class="ec-page">

    <!-- ═══ HEADER ═══ -->
    <div class="ec-header d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="mb-1">Order Management</h1>
            <p class="mb-0">Manage customer orders, pickups, and deliveries</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="refreshPage()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>

    <!-- ═══ SUMMARY BAR ═══ -->
    <div class="ec-summary mb-4">
        <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Orders Today</span>
                    <span class="ec-summary-value primary"><?= (int)$stats['today'] ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Pending</span>
                    <span class="ec-summary-value warning"><?= (int)$stats['pending'] ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">To Prepare</span>
                    <span class="ec-summary-value info"><?= (int)$stats['preparing'] ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Ready for Pickup</span>
                    <span class="ec-summary-value success"><?= (int)$stats['ready'] ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">For Delivery</span>
                    <span class="ec-summary-value info"><?= (int)$stats['delivery'] ?></span>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="ec-summary-item">
                    <span class="ec-summary-label">Completed</span>
                    <span class="ec-summary-value success"><?= (int)$stats['completed'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ STATUS TABS ═══ -->
    <div class="ec-tabs mb-3">
        <button class="ec-tab active" data-status="">All Orders <span class="ec-tab-count"><?= (int)$stats['total'] ?></span></button>
        <button class="ec-tab" data-status="pending">Pending <span class="ec-tab-count"><?= (int)$stats['pending'] ?></span></button>
        <button class="ec-tab" data-status="confirmed">Confirmed <span class="ec-tab-count"><?= (int)$stats['confirmed'] ?></span></button>
        <button class="ec-tab" data-status="preparing">To Prepare <span class="ec-tab-count"><?= (int)$stats['preparing'] ?></span></button>
        <button class="ec-tab" data-status="ready_for_pickup">Ready for Pickup <span class="ec-tab-count"><?= (int)$stats['ready'] ?></span></button>
        <button class="ec-tab" data-status="dispatched">For Delivery <span class="ec-tab-count"><?= (int)$stats['dispatched'] ?></span></button>
        <button class="ec-tab" data-status="completed">Completed <span class="ec-tab-count"><?= (int)$stats['completed'] ?></span></button>
        <button class="ec-tab" data-status="cancelled">Cancelled <span class="ec-tab-count"><?= (int)$stats['cancelled'] ?></span></button>
        <button class="ec-tab" data-status="refund_requests" id="refundTab">
            💰 Refund Requests <span class="ec-tab-count" id="refundTabCount">0</span>
        </button>
    </div>

    <!-- ═══ FILTER BAR ═══ -->
    <div class="ec-filter-bar mb-4" id="filterBar">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" id="filterSearch" placeholder="Search order #, customer, product...">
                </div>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterType">
                    <option value="">All Types</option>
                    <option value="pickup">Pickup</option>
                    <option value="delivery">Delivery</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterPayment">
                    <option value="">All Payments</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                    <option value="cod">COD</option>
                    <option value="onsite">Onsite</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control form-control-sm" id="filterDate">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary btn-sm w-100" onclick="resetFilters()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ SELECT ALL BAR ═══ -->
    <div class="ec-select-all-bar" id="selectAllBar">
        <input type="checkbox" id="selectAllCheck" class="ec-order-check" onchange="toggleSelectAll(this)">
        <label for="selectAllCheck" class="mb-0" style="cursor:pointer;">Select all visible orders</label>
    </div>

    <!-- ═══ BULK TOOLBAR ═══ -->
    <div class="ec-bulk-toolbar" id="bulkToolbar">
        <span class="ec-bulk-count" id="bulkCount">0 selected</span>
        <div id="bulkActionsContainer" class="d-flex gap-2 flex-wrap"></div>
        <button class="btn btn-outline-light btn-sm ms-auto" onclick="clearSelection()">
            <i class="bi bi-x-lg me-1"></i>Clear
        </button>
    </div>

    <!-- ═══ ORDER LIST ═══ -->
    <div id="ordersContainer">
        <?php if (empty($orders)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                No orders yet
            </div>
        <?php else: ?>
            <?php foreach ($orders as $r): ?>
            <?php
                $isDelivery = $r['fulfillment_type'] === 'delivery';
                $statusMap = [
                    'pending' => ['label'=>'Pending','class'=>'pending','icon'=>'hourglass-split'],
                    'confirmed' => ['label'=>'Confirmed','class'=>'confirmed','icon'=>'check-circle'],
                    'preparing' => ['label'=>'To Prepare','class'=>'preparing','icon'=>'box-seam'],
                    'ready_for_pickup' => ['label'=>'Ready for Pickup','class'=>'ready','icon'=>'shop'],
                    'dispatched' => ['label'=>'Dispatched','class'=>'dispatched','icon'=>'truck'],
                    'delivered' => ['label'=>'Delivered','class'=>'delivered','icon'=>'check2-all'],
                    'completed' => ['label'=>'Completed','class'=>'completed','icon'=>'bag-check'],
                    'cancelled' => ['label'=>'Cancelled','class'=>'cancelled','icon'=>'x-circle'],
                ];
                $st = $statusMap[$r['status']] ?? ['label'=>ucfirst($r['status']),'class'=>'default','icon'=>'circle'];
                $payMap = [
                    'paid'=>'paid','unpaid'=>'unpaid','partial'=>'partial',
                    'cod'=>'cod','onsite'=>'onsite','refunded'=>'refunded','failed'=>'unpaid'
                ];
                $payClass = $payMap[$r['payment_status']] ?? 'default';
                $payLabel = match($r['payment_status']) {
                    'cod'     => '💵 COD',
                    'onsite'  => '🏪 ONSITE',
                    'paid'    => '✅ PAID',
                    'unpaid'  => '● UNPAID',
                    'partial' => '⚠ PARTIAL',
                    'refunded'=> '↩ REFUNDED',
                    'failed'  => '✗ FAILED',
                    default   => strtoupper($r['payment_status'])
                };
                $needsCollection = in_array($r['payment_status'], ['cod','onsite','unpaid','partial'], true)
                                   && !in_array($r['status'], ['cancelled','completed','delivered'], true);
                $collectorName = trim(($r['collector_first'] ?? '').' '.($r['collector_last'] ?? ''));
                $hasRider    = !empty($r['assigned_rider_id']);
                $dStatus     = $r['delivery_status'] ?? 'pending';
                $isPickedUp  = ($dStatus === 'picked_up');
                $isInTransit = ($dStatus === 'in_transit');
                $isFinalized = in_array($r['status'], ['delivered','completed','cancelled'], true);
            ?>
            <div class="ec-order-card order-row"
                 data-status="<?= htmlspecialchars($r['status']) ?>"
                 data-type="<?= htmlspecialchars($r['fulfillment_type']) ?>"
                 data-payment="<?= htmlspecialchars($r['payment_status']) ?>"
                 data-date="<?= date('Y-m-d', strtotime($r['created_at'])) ?>"
                 data-search="<?= htmlspecialchars(strtolower($r['reservation_code'].' '.$r['user_name'].' '.$r['product_name'])) ?>">

                <div class="ec-order-header">
                    <div>
                        <div class="d-flex align-items-start gap-2">
                            <input type="checkbox" class="ec-order-check order-checkbox"
                                   data-id="<?= (int)$r['id'] ?>"
                                   data-status="<?= htmlspecialchars($r['status']) ?>"
                                   data-type="<?= htmlspecialchars($r['fulfillment_type']) ?>"
                                   data-rider="<?= (int)($r['assigned_rider_id'] ?? 0) ?>"
                                   data-payment="<?= htmlspecialchars($r['payment_status']) ?>"
                                   data-total="<?= number_format((float)$r['total_amount'], 2, '.', '') ?>"
                                   onchange="updateBulkToolbar()">
                            <div>
                                <div class="ec-order-id"><?= htmlspecialchars($r['reservation_code']) ?></div>
                                <div class="ec-order-date">
                                    <i class="bi bi-clock me-1"></i><?= date('M d, Y • g:i A', strtotime($r['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <span class="ec-badge ec-badge-<?= $isDelivery ? 'delivery' : 'pickup' ?>">
                            <i class="bi bi-<?= $isDelivery ? 'truck' : 'shop' ?>"></i>
                            <?= $isDelivery ? 'DELIVERY' : 'PICKUP' ?>
                        </span>
                        <span class="ec-badge ec-badge-<?= $st['class'] ?>">
                            <i class="bi bi-<?= $st['icon'] ?>"></i><?= $st['label'] ?>
                        </span>
                    </div>
                </div>

                <div class="ec-order-body">
                    <?php if (!empty($r['product_image'])): ?>
                        <img src="<?= htmlspecialchars($r['product_image']) ?>" alt="" class="ec-order-product">
                    <?php else: ?>
                        <div class="ec-order-product d-flex align-items-center justify-content-center text-muted">
                            <i class="bi bi-eyeglasses fs-4"></i>
                        </div>
                    <?php endif; ?>

                    <div class="ec-order-info">
                        <div class="ec-order-customer"><?= htmlspecialchars($r['user_name']) ?></div>
                        <div class="ec-order-product-meta mb-1">
                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($r['user_contact'] ?? $r['user_email'] ?? '—') ?>
                        </div>
                        <div class="ec-order-product-name"><?= htmlspecialchars($r['product_name']) ?></div>
<div class="ec-order-product-meta">
    <?= htmlspecialchars($r['color_name'] ?? '') ?>
    <?php if (!empty($r['lens_type']) && $r['lens_type'] !== 'frame_only'): ?>
        • <?= htmlspecialchars(ucwords(str_replace('_',' ',$r['lens_type']))) ?>
        <?php if (!empty($r['lens_index'])): ?>
            • <strong style="color: var(--ec-primary);"><?= htmlspecialchars($r['lens_index']) ?></strong>
        <?php endif; ?>
    <?php endif; ?>
    • Qty <?= (int)$r['quantity'] ?>
</div>
                    </div>

                    <div class="ec-order-right">
                        <div class="ec-order-total">₱<?= number_format($r['total_amount'], 2) ?></div>
                        <span class="ec-badge ec-badge-<?= $payClass ?>"><?= htmlspecialchars($payLabel) ?></span>
                        <?php if ($needsCollection): ?>
                            <span class="ec-collect-hint">
                                <i class="bi bi-exclamation-triangle"></i>Collect on delivery
                            </span>
                        <?php endif; ?>
                        <?php if ($isDelivery && $r['rider_name']): ?>
                            <small class="text-muted"><i class="bi bi-bicycle me-1"></i><?= htmlspecialchars($r['rider_name']) ?></small>
                        <?php endif; ?>
                        <?php if (!empty($r['tracking_number'])): ?>
                            <small class="text-muted">
                                <i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($r['tracking_number']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ec-order-actions">
                    <button class="btn btn-outline-secondary btn-sm" onclick='viewOrder(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <i class="bi bi-eye me-1"></i>View Details
                    </button>

                    <?php if (canApproveReservations() && $r['status'] === 'pending'): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="postAction('confirm', <?= (int)$r['id'] ?>)">
                            <i class="bi bi-check-lg me-1"></i>Confirm Order
                        </button>
                    <?php elseif (canApproveReservations() && $r['status'] === 'confirmed' && !$isDelivery): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="postAction('ready_pickup', <?= (int)$r['id'] ?>)">
                            <i class="bi bi-shop me-1"></i>Ready for Pickup
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'confirmed' && $isDelivery): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="postAction('preparing', <?= (int)$r['id'] ?>)">
                            <i class="bi bi-box-seam me-1"></i>Start Preparing
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'preparing' && $isDelivery && !$hasRider): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick='openAssignRiderModal(<?= (int)$r['id'] ?>, "<?= addslashes($r['reservation_code']) ?>")'>
                            <i class="bi bi-bicycle me-1"></i>Assign Rider
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'preparing' && $isDelivery && $hasRider && $dStatus === 'assigned'): ?>
                        <button class="btn ec-btn-primary btn-sm" onclick="postAction('dispatch', <?= (int)$r['id'] ?>)">
                            <i class="bi bi-truck me-1"></i>Dispatch Now
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'preparing' && $isDelivery && $isPickedUp): ?>
                        <span class="ec-status-pill picked">
                            <i class="bi bi-box-seam"></i>Rider Picked Up
                        </span>
                    <?php elseif (canEditReservations() && $r['status'] === 'preparing' && $isDelivery && $isInTransit): ?>
                        <span class="ec-status-pill transit">
                            <i class="bi bi-truck"></i>Rider In Transit
                        </span>
                    <?php elseif (canEditReservations() && $r['status'] === 'dispatched'): ?>
                        <button class="btn ec-btn-primary btn-sm"
                                onclick='handleDeliverClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                            <i class="bi bi-check2-all me-1"></i>Mark Delivered
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'delivered'): ?>
                        <button class="btn btn-success btn-sm"
                                onclick='handleCompleteClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                            <i class="bi bi-bag-check me-1"></i>Mark as Completed
                        </button>
                    <?php elseif (canEditReservations() && $r['status'] === 'ready_for_pickup'): ?>
                        <button class="btn ec-btn-primary btn-sm"
                                onclick='handleCompleteClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                            <i class="bi bi-bag-check me-1"></i>Complete Order
                        </button>
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
                                <a class="dropdown-item" href="#" onclick='viewOrder(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>);return false;'>
                                    <i class="bi bi-eye me-2"></i>View Details
                                </a>
                            </li>
                            
                            <?php if ($isDelivery && canEditReservations() && !$isFinalized): ?>
                                
                                <?php if (!$hasRider): ?>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick='openAssignRiderModal(<?= (int)$r['id'] ?>, "<?= addslashes($r['reservation_code']) ?>");return false;'>
                                            <i class="bi bi-bicycle me-2"></i>Assign Rider
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php if ($hasRider && $dStatus === 'assigned'): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><small class="dropdown-header text-uppercase" style="font-size:.65rem;">Clinic Fallback</small></li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="postAction('dispatch', <?= (int)$r['id'] ?>);return false;">
                                            <i class="bi bi-truck me-2"></i>Dispatch (Clinic)
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php if ($hasRider && $isPickedUp): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><small class="dropdown-header text-uppercase" style="font-size:.65rem;">Clinic Fallback</small></li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="postAction('dispatch', <?= (int)$r['id'] ?>);return false;">
                                            <i class="bi bi-truck me-2"></i>Force Dispatch (Clinic)
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php if ($hasRider && $isInTransit): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><small class="dropdown-header text-uppercase" style="font-size:.65rem;">Clinic Fallback</small></li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick='handleDeliverClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>);return false;'>
                                            <i class="bi bi-check2-all me-2"></i>Force Deliver (Clinic)
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php if (!$hasRider || ($hasRider && $dStatus === 'assigned')): ?>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick='handleDeliverClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>);return false;'>
                                            <i class="bi bi-check2-all me-2"></i>Mark Delivered (Clinic)
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                            <?php endif; ?>
                            
                            <?php if (canEditReservations() && $r['status'] === 'delivered'): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick='handleCompleteClick(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>);return false;'>
                                        <i class="bi bi-bag-check me-2"></i>Mark as Completed
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php if (canRejectReservations() && !$isFinalized): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick='cancelOrder(<?= (int)$r['id'] ?>, "<?= addslashes($r['reservation_code']) ?>");return false;'>
                                        <i class="bi bi-x-lg me-2"></i>Cancel Order
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

    <div id="noResultsMsg" class="text-center py-5 text-muted d-none">
        <i class="bi bi-search fs-1 d-block mb-3 opacity-50"></i>
        No orders match your filters
    </div>

    <!-- ═══ REFUND REQUESTS CONTAINER (hidden by default) ═══ -->
    <div id="refundContainer" class="ec-refund-container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-cash-coin me-2"></i>Refund Requests</h5>
                <p class="text-muted small mb-0">Manage customer return/refund requests</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="loadRefunds()">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
            </button>
        </div>

        <!-- Filter Pills -->
        <div class="ec-refund-filters" id="refundFilters">
            <button class="ec-refund-filter active" data-filter="">All <span class="count" id="refundCountAll">0</span></button>
            <button class="ec-refund-filter" data-filter="pending">Pending <span class="count" id="refundCountPending">0</span></button>
            <button class="ec-refund-filter" data-filter="approved">Approved <span class="count" id="refundCountApproved">0</span></button>
            <button class="ec-refund-filter" data-filter="processing">Processing <span class="count" id="refundCountProcessing">0</span></button>
            <button class="ec-refund-filter" data-filter="completed">Completed <span class="count" id="refundCountCompleted">0</span></button>
            <button class="ec-refund-filter" data-filter="rejected">Rejected <span class="count" id="refundCountRejected">0</span></button>
        </div>

        <!-- Refund List -->
        <div id="refundList">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-3 mb-0">Loading refunds...</p>
            </div>
        </div>
    </div>

</div><!-- /ec-page -->

<!-- ═══ MODALS ═══ -->

<div class="modal fade" id="orderDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Order Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderDetailsBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="assignRiderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bicycle me-2"></i>Assign Rider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Order: <strong id="assignOrderCode"></strong></p>
                <?php if (empty($riders)): ?>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>No active riders. Please add riders first.
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">Select Rider</label>
                        <select class="form-select" id="assignRiderSelect">
                            <option value="">-- Choose rider --</option>
                            <?php foreach ($riders as $rd): ?>
                                <option value="<?= (int)$rd['id'] ?>" <?= !$rd['is_available'] ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($rd['name']) ?>
                                    <?= $rd['vehicle_type'] ? ' — '.htmlspecialchars($rd['vehicle_type']) : '' ?>
                                    <?= $rd['plate_number'] ? ' ('.htmlspecialchars($rd['plate_number']).')' : '' ?>
                                    <?= !$rd['is_available'] ? ' — BUSY' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Estimated Delivery Date</label>
                        <input type="date" class="form-control" id="assignDeliveryDate" value="<?= date('Y-m-d', strtotime('+2 days')) ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                        <textarea class="form-control" id="assignDeliveryNotes" rows="2" placeholder="Instructions for the rider..."></textarea>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <?php if (!empty($riders)): ?>
                    <button class="btn ec-btn-primary" id="confirmAssignBtn"><i class="bi bi-check-lg me-1"></i>Assign Rider</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Cancel Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Cancel order <strong id="cancelCode"></strong>?</p>
                <div class="mb-0">
                    <label class="form-label">Reason <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="cancelReason" rows="3" placeholder="e.g. Product out of stock, customer request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                <button class="btn btn-danger" id="confirmCancelBtn"><i class="bi bi-x-lg me-1"></i>Cancel Order</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="collectionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Confirm Collection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Order: <strong id="collectOrderCode"></strong></p>
                <p class="text-muted small mb-3">
                    Payment: <strong id="collectPaymentType"></strong> ·
                    Expected: <strong id="collectExpected"></strong>
                </p>
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Please confirm you have collected the payment before proceeding.
                </div>
                <div class="mb-3">
                    <label class="form-label">Amount Collected</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="collectAmount">
                    <small class="text-muted" id="collectHint"></small>
                </div>
                <div class="mb-0">
                    <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="collectNotes" rows="2" placeholder="e.g. Paid via cash, reference #..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn ec-btn-primary" id="confirmCollectBtn">
                    <i class="bi bi-check-lg me-1"></i>Confirm Collection
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkCollectionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Bulk Collection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="bulkCollectCount"></strong> order(s) selected</p>
                <p class="text-muted small mb-3">
                    Total expected: <strong id="bulkCollectTotal"></strong>
                </p>
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    The same amount will be recorded for each order. Adjust per-order after if needed.
                </div>
                <div class="mb-3">
                    <label class="form-label">Amount Per Order</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="bulkCollectAmount">
                </div>
                <div class="mb-0">
                    <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="bulkCollectNotes" rows="2" placeholder="e.g. Bulk COD collection, ref #..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn ec-btn-primary" id="confirmBulkCollectBtn">
                    <i class="bi bi-check-lg me-1"></i>Confirm Bulk Collection
                </button>
            </div>
        </div>
    </div>
</div>

<!-- REFUND DETAILS MODAL -->
<div class="modal fade" id="refundDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Refund Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="refundDetailsBody"></div>
            <div class="modal-footer" id="refundDetailsFooter"></div>
        </div>
    </div>
</div>

<!-- APPROVE REFUND MODAL -->
<div class="modal fade" id="approveRefundModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle text-success me-2"></i>Approve Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Order: <strong id="approveRefundOrder"></strong></p>
                <p class="text-muted small mb-3">
                    Amount: <strong id="approveRefundAmount" class="text-danger"></strong>
                </p>

                <label class="form-label fw-semibold">Refund Method</label>
                <div class="ec-refund-method-option selected" onclick="selectRefundMethod(this, 'manual_gcash')">
                    <input type="radio" name="refund_method" value="manual_gcash" checked>
                    <i class="bi bi-phone"></i>
                    <div class="method-info">
                        <div class="method-name">Manual GCash</div>
                        <div class="method-desc">Send via GCash and enter reference</div>
                    </div>
                </div>
                <div class="ec-refund-method-option" onclick="selectRefundMethod(this, 'manual_bank')">
                    <input type="radio" name="refund_method" value="manual_bank">
                    <i class="bi bi-bank"></i>
                    <div class="method-info">
                        <div class="method-name">Manual Bank Transfer</div>
                        <div class="method-desc">Send via bank and enter reference</div>
                    </div>
                </div>
                <div class="ec-refund-method-option" onclick="selectRefundMethod(this, 'manual_cash')">
                    <input type="radio" name="refund_method" value="manual_cash">
                    <i class="bi bi-cash"></i>
                    <div class="method-info">
                        <div class="method-name">Manual Cash</div>
                        <div class="method-desc">Hand over cash and confirm</div>
                    </div>
                </div>
                <div class="ec-refund-method-option" onclick="selectRefundMethod(this, 'paymongo')">
                    <input type="radio" name="refund_method" value="paymongo">
                    <i class="bi bi-credit-card"></i>
                    <div class="method-info">
                        <div class="method-name">PayMongo (Automatic)</div>
                        <div class="method-desc">Refund via original payment method</div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Admin Notes <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="approveRefundNotes" rows="2" placeholder="Internal notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn ec-btn-primary" id="confirmApproveRefundBtn">
                    <i class="bi bi-check-lg me-1"></i>Approve Refund
                </button>
            </div>
        </div>
    </div>
</div>

<!-- REJECT REFUND MODAL -->
<div class="modal fade" id="rejectRefundModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Reject Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Order: <strong id="rejectRefundOrder"></strong></p>
                <p class="text-muted small mb-3">Amount: <strong id="rejectRefundAmount" class="text-danger"></strong></p>

                <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" id="rejectRefundReason" rows="3" placeholder="Why is this refund being rejected?"></textarea>
                <small class="text-muted">This will be sent to the customer.</small>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="confirmRejectRefundBtn">
                    <i class="bi bi-x-lg me-1"></i>Reject Refund
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MARK AS REFUNDED MODAL -->
<div class="modal fade" id="markRefundedModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Mark as Refunded</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Order: <strong id="markRefundedOrder"></strong></p>
                <p class="text-muted small mb-3">Amount: <strong id="markRefundedAmount" class="text-danger"></strong></p>

                <label class="form-label fw-semibold">Refund Reference <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="markRefundedRef" placeholder="GCash Ref #, Bank Ref #, etc.">
                <small class="text-muted">Enter the reference number from your refund transaction.</small>

                <div class="mt-3">
                    <label class="form-label">Admin Notes <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="markRefundedNotes" rows="2" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success" id="confirmMarkRefundedBtn">
                    <i class="bi bi-check-lg me-1"></i>Mark as Refunded
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const API_URL = 'api/reservations.php';

const userPermissions = {
    view:    <?= json_encode(canViewReservations()) ?>,
    edit:    <?= json_encode(canEditReservations()) ?>,
    approve: <?= json_encode(canApproveReservations()) ?>,
    reject:  <?= json_encode(canRejectReservations()) ?>
};

let detailsModal, assignRiderModal, cancelModal, collectionModal, bulkCollectionModal;
let pendingCancelId = null;
let pendingAssignId = null;
let pendingBulkIds = null;
let pendingCollectData = null;
let activeStatus = '';

document.addEventListener('DOMContentLoaded', function () {
    detailsModal        = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
    assignRiderModal    = new bootstrap.Modal(document.getElementById('assignRiderModal'));
    cancelModal         = new bootstrap.Modal(document.getElementById('cancelModal'));
    collectionModal     = new bootstrap.Modal(document.getElementById('collectionModal'));
    bulkCollectionModal = new bootstrap.Modal(document.getElementById('bulkCollectionModal'));

    document.addEventListener('shown.bs.dropdown', function (e) {
        const card = e.target.closest('.ec-order-card');
        if (card) card.classList.add('dropdown-open');
    });
    document.addEventListener('hidden.bs.dropdown', function (e) {
        const card = e.target.closest('.ec-order-card');
        if (card) card.classList.remove('dropdown-open');
    });

    document.querySelectorAll('.ec-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.ec-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            activeStatus = this.dataset.status || '';

            if (activeStatus === 'refund_requests') {
                document.getElementById('filterBar').style.display = 'none';
                document.getElementById('selectAllBar').style.display = 'none';
                document.getElementById('bulkToolbar').classList.remove('show');
                document.getElementById('ordersContainer').style.display = 'none';
                document.getElementById('noResultsMsg').classList.add('d-none');
                document.getElementById('refundContainer').classList.add('active');
                loadRefunds();
            } else {
                document.getElementById('filterBar').style.display = '';
                document.getElementById('selectAllBar').style.display = '';
                document.getElementById('ordersContainer').style.display = '';
                document.getElementById('refundContainer').classList.remove('active');
                applyFilters();
            }
        });
    });

    ['filterSearch','filterType','filterPayment','filterDate'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', applyFilters);
        document.getElementById(id)?.addEventListener('change', applyFilters);
    });

    document.getElementById('confirmAssignBtn')?.addEventListener('click', function () {
        const riderId = document.getElementById('assignRiderSelect').value;
        const eta     = document.getElementById('assignDeliveryDate').value;
        const notes   = document.getElementById('assignDeliveryNotes').value.trim();
        if (!riderId) { Swal.fire('Missing','Please select a rider','warning'); return; }

        if (pendingBulkIds && pendingBulkIds.length > 0) {
            assignRiderModal.hide();
            bulkAction('bulk_assign_rider', pendingBulkIds, {
                rider_id: riderId, delivery_date: eta, delivery_notes: notes
            });
            pendingBulkIds = null;
            return;
        }

        if (!pendingAssignId) return;
        assignRiderModal.hide();
        postAction('assign_rider', pendingAssignId, { rider_id: riderId, delivery_date: eta, delivery_notes: notes });
        pendingAssignId = null;
    });

    document.getElementById('confirmCancelBtn')?.addEventListener('click', function () {
        if (!pendingCancelId) return;
        const reason = document.getElementById('cancelReason').value.trim() || 'Cancelled by clinic';
        cancelModal.hide();
        postAction('cancel', pendingCancelId, { reason });
        pendingCancelId = null;
    });

    document.getElementById('confirmCollectBtn')?.addEventListener('click', function () {
        if (!pendingCollectData) return;
        const amount = parseFloat(document.getElementById('collectAmount').value || 0);
        const notes  = document.getElementById('collectNotes').value.trim();

        if (isNaN(amount) || amount < 0) {
            Swal.fire('Invalid', 'Please enter a valid amount', 'warning');
            return;
        }

        const { id, action } = pendingCollectData;
        collectionModal.hide();
        postAction(action, id, { collected_amount: amount, collection_notes: notes });
        pendingCollectData = null;
    });

    document.getElementById('confirmBulkCollectBtn')?.addEventListener('click', function () {
        if (!pendingCollectData || !pendingCollectData.bulkIds) return;
        const amount = parseFloat(document.getElementById('bulkCollectAmount').value || 0);
        const notes  = document.getElementById('bulkCollectNotes').value.trim();

        if (isNaN(amount) || amount < 0) {
            Swal.fire('Invalid', 'Please enter a valid amount', 'warning');
            return;
        }

        const { action, bulkIds } = pendingCollectData;
        const collectedAmounts = {};
        bulkIds.forEach(oid => collectedAmounts[oid] = amount);

        bulkCollectionModal.hide();
        bulkAction(action, bulkIds, {
            collected_amounts: JSON.stringify(collectedAmounts),
            collection_notes:  notes
        });
        pendingCollectData = null;
    });
});

function refreshPage() { location.reload(); }

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterType').value = '';
    document.getElementById('filterPayment').value = '';
    document.getElementById('filterDate').value = '';
    activeStatus = '';
    document.querySelectorAll('.ec-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.ec-tab[data-status=""]')?.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const search  = document.getElementById('filterSearch').value.toLowerCase().trim();
    const type    = document.getElementById('filterType').value;
    const payment = document.getElementById('filterPayment').value;
    const date    = document.getElementById('filterDate').value;

    let visible = 0;
    document.querySelectorAll('.order-row').forEach(row => {
        const matchStatus  = !activeStatus || row.dataset.status === activeStatus;
        const matchType    = !type    || row.dataset.type === type;
        const matchPayment = !payment || row.dataset.payment === payment;
        const matchDate    = !date    || row.dataset.date === date;
        const matchSearch  = !search  || row.dataset.search.includes(search);

        const show = matchStatus && matchType && matchPayment && matchDate && matchSearch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const selectAll = document.getElementById('selectAllCheck');
    if (selectAll) selectAll.checked = false;

    document.getElementById('noResultsMsg').classList.toggle('d-none', visible > 0);
    updateBulkToolbar();
}

function handleDeliverClick(o) {
    if (['cod','onsite','unpaid','partial'].includes(o.payment_status)) {
        openCollectionModal(o.id, 'deliver', o.total_amount, o.payment_status, o.reservation_code);
    } else {
        postAction('deliver', o.id);
    }
}

function handleCompleteClick(o) {
    if (['cod','onsite','unpaid','partial'].includes(o.payment_status)) {
        openCollectionModal(o.id, 'complete', o.total_amount, o.payment_status, o.reservation_code);
    } else {
        postAction('complete', o.id);
    }
}

function openCollectionModal(id, action, expected, paymentType, code, bulkIds = null) {
    pendingCollectData = { id, action, expected, paymentType, code, bulkIds };
    document.getElementById('collectOrderCode').textContent = code;
    document.getElementById('collectPaymentType').textContent = paymentType.toUpperCase();
    document.getElementById('collectExpected').textContent = '₱' + parseFloat(expected).toFixed(2);
    document.getElementById('collectAmount').value = parseFloat(expected).toFixed(2);
    document.getElementById('collectNotes').value = '';

    const hint = document.getElementById('collectHint');
    if (paymentType === 'partial' || paymentType === 'unpaid') {
        hint.textContent = 'Enter partial amount if not fully paying. Full amount marks as PAID.';
    } else {
        hint.textContent = 'Full amount marks as PAID. Less than expected marks as PARTIAL.';
    }

    collectionModal.show();
}

const BULK_ACTIONS = [
    { key:'bulk_confirm',      label:'Confirm',      icon:'check-lg',   statuses:['pending'] },
    { key:'bulk_ready_pickup', label:'Ready Pickup', icon:'shop',       statuses:['confirmed'], type:'pickup' },
    { key:'bulk_preparing',    label:'Prepare',      icon:'box-seam',   statuses:['confirmed'], type:'delivery' },
    { key:'bulk_assign_rider', label:'Assign Rider', icon:'bicycle',    statuses:['confirmed','preparing'], type:'delivery' },
    { key:'bulk_dispatch',     label:'Dispatch',     icon:'truck',      statuses:['preparing'] },
    { key:'bulk_deliver',      label:'Deliver',      icon:'check2-all', statuses:['dispatched'] },
    { key:'bulk_complete',     label:'Complete',     icon:'bag-check',  statuses:['ready_for_pickup'] },
    { key:'bulk_cancel',       label:'Cancel',       icon:'x-lg',       statuses:['pending','confirmed','preparing','ready_for_pickup','dispatched'], danger:true },
];

function toggleSelectAll(cb) {
    document.querySelectorAll('.order-row').forEach(row => {
        if (row.style.display === 'none') return;
        const checkbox = row.querySelector('.order-checkbox');
        if (checkbox) checkbox.checked = cb.checked;
    });
    updateBulkToolbar();
}

function clearSelection() {
    document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAllCheck');
    if (sa) sa.checked = false;
    updateBulkToolbar();
}

function getSelectedOrders() {
    const selected = [];
    document.querySelectorAll('.order-checkbox:checked').forEach(cb => {
        selected.push({
            id: parseInt(cb.dataset.id),
            status: cb.dataset.status,
            type: cb.dataset.type,
            rider: parseInt(cb.dataset.rider || 0),
            payment: cb.dataset.payment,
            total: parseFloat(cb.dataset.total || 0)
        });
    });
    return selected;
}

function updateBulkToolbar() {
    const selected = getSelectedOrders();
    const toolbar  = document.getElementById('bulkToolbar');
    const countEl  = document.getElementById('bulkCount');
    const actionsEl= document.getElementById('bulkActionsContainer');
    const selectAll= document.getElementById('selectAllCheck');

    document.querySelectorAll('.order-row').forEach(row => {
        const cb = row.querySelector('.order-checkbox');
        row.classList.toggle('selected', cb && cb.checked);
    });

    const visibleCheckboxes = [...document.querySelectorAll('.order-row')]
        .filter(r => r.style.display !== 'none')
        .map(r => r.querySelector('.order-checkbox'))
        .filter(Boolean);
    const checkedVisible = visibleCheckboxes.filter(cb => cb.checked).length;
    if (selectAll) {
        selectAll.checked = visibleCheckboxes.length > 0 && checkedVisible === visibleCheckboxes.length;
    }

    if (selected.length === 0) {
        toolbar.classList.remove('show');
        return;
    }

    toolbar.classList.add('show');
    countEl.textContent = `${selected.length} selected`;

    const statuses = [...new Set(selected.map(o => o.status))];
    const types    = [...new Set(selected.map(o => o.type))];
    const isSingleStatus = statuses.length === 1;
    const isSingleType   = types.length === 1;

    const available = BULK_ACTIONS.filter(a => {
        if (!a.statuses.some(s => statuses.includes(s))) return false;
        if (a.type && !(isSingleType && types[0] === a.type)) return false;
        if (a.statuses.length === 1 && !isSingleStatus) return false;
        return true;
    });

    actionsEl.innerHTML = available.map(a => `
        <button class="btn btn-sm ${a.danger ? 'btn-outline-light' : 'btn-light'}"
                onclick="bulkActionClick('${a.key}')">
            <i class="bi bi-${a.icon} me-1"></i>${a.label}
        </button>
    `).join('');

    if (available.length === 0) {
        actionsEl.innerHTML = '<span style="opacity:.8;font-size:.8rem;">No common actions for selection</span>';
    }
}

function bulkActionClick(action) {
    const selected = getSelectedOrders();
    if (selected.length === 0) return;

    if (action === 'bulk_assign_rider') {
        pendingBulkIds = selected.map(o => o.id);
        document.getElementById('assignOrderCode').textContent = `${selected.length} order(s)`;
        assignRiderModal.show();
        return;
    }

    if (action === 'bulk_cancel') {
        Swal.fire({
            title: 'Cancel Orders?',
            html: `Cancel <strong>${selected.length}</strong> order(s)?`,
            input: 'textarea',
            inputPlaceholder: 'Reason (optional)...',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Cancel Orders',
            cancelButtonText: 'Back'
        }).then(result => {
            if (result.isConfirmed) {
                bulkAction(action, selected.map(o => o.id), { reason: result.value || 'Cancelled by clinic' });
            }
        });
        return;
    }

    if (action === 'bulk_deliver' || action === 'bulk_complete') {
        const needsCollection = selected.some(o => ['cod','onsite','unpaid','partial'].includes(o.payment));
        if (needsCollection) {
            const total = selected.reduce((sum, o) => sum + (o.total || 0), 0);
            const avg   = total / selected.length;

            pendingCollectData = {
                action,
                bulkIds: selected.map(o => o.id),
                expected: total
            };
            document.getElementById('bulkCollectCount').textContent = selected.length;
            document.getElementById('bulkCollectTotal').textContent = '₱' + total.toFixed(2);
            document.getElementById('bulkCollectAmount').value = avg.toFixed(2);
            document.getElementById('bulkCollectNotes').value = '';
            bulkCollectionModal.show();
            return;
        }
    }

    Swal.fire({
        title: 'Confirm Bulk Action?',
        html: `Apply <strong>${action.replace('bulk_','').replace(/_/g,' ')}</strong> to <strong>${selected.length}</strong> order(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6e6e',
        confirmButtonText: 'Yes, Proceed',
        cancelButtonText: 'Back'
    }).then(result => {
        if (result.isConfirmed) bulkAction(action, selected.map(o => o.id));
    });
}

function bulkAction(action, ids, extra = {}) {
    Swal.fire({ title: 'Processing…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

    const formData = new FormData();
    formData.append('action', action);
    ids.forEach(id => formData.append('ids[]', id));
    Object.entries(extra).forEach(([k,v]) => formData.append(k, v));

    fetch(API_URL, { method:'POST', body:formData, credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const failed = (data.data && data.data.failed) ? data.data.failed : [];
                if (failed.length > 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Partially Completed',
                        html: `<div><strong>${data.data.success.length}</strong> succeeded, <strong>${failed.length}</strong> failed.</div>
                               <div class="text-start small mt-2" style="max-height:150px;overflow:auto">
                               ${failed.map(f => `<div>• ${esc(f.code || f.id)}: ${esc(f.reason)}</div>`).join('')}
                               </div>`,
                        confirmButtonText: 'OK'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({ icon:'success', title:'Done!', text:data.message, timer:1500, showConfirmButton:false })
                        .then(() => location.reload());
                }
            } else {
                Swal.fire('Error', data.message || 'Something went wrong', 'error');
            }
        })
        .catch(err => {
            console.error('BULK FETCH ERROR:', err);
            Swal.fire('Error','Network error: '+err.message,'error');
        });
}

function viewOrder(o) {
    const isDelivery = o.fulfillment_type === 'delivery';
    const body = document.getElementById('orderDetailsBody');
    const statusMap = {
        pending:['Pending','pending'], confirmed:['Confirmed','confirmed'],
        preparing:['To Prepare','preparing'], ready_for_pickup:['Ready for Pickup','ready'],
        dispatched:['Dispatched','dispatched'], delivered:['Delivered','delivered'],
        completed:['Completed','completed'], cancelled:['Cancelled','cancelled']
    };
    const st = statusMap[o.status] || [o.status,'default'];

    const collectorName = [o.collector_first, o.collector_last].filter(Boolean).join(' ').trim();

    const createdAtFmt      = o.created_at_formatted        || new Date(o.created_at).toLocaleString();
    const deliveredAtFmt    = o.delivered_at_formatted      || (o.delivered_at ? new Date(o.delivered_at).toLocaleString() : '—');
    const collectedAtFmt    = o.collected_at_formatted      || (o.collected_at ? new Date(o.collected_at).toLocaleString() : '—');
    const proofAtFmt        = o.delivery_proof_at_formatted || (o.delivery_proof_at ? new Date(o.delivery_proof_at).toLocaleString() : '—');
    const deliveryDateFmt   = o.delivery_date_formatted     || (o.delivery_date ? new Date(o.delivery_date).toLocaleDateString() : '—');
    const preferredDateFmt  = o.preferred_date_formatted    || (o.preferred_date ? new Date(o.preferred_date).toLocaleDateString() : '—');

    body.innerHTML = `
        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-info-circle"></i>Order Information</div>
            <div class="ec-detail-row"><span class="label">Order Number</span><span class="value">${esc(o.reservation_code)}</span></div>
            <div class="ec-detail-row"><span class="label">Date Placed</span><span class="value">${esc(createdAtFmt)}</span></div>
            <div class="ec-detail-row"><span class="label">Status</span><span class="value"><span class="ec-badge ec-badge-${st[1]}">${st[0]}</span></span></div>
            <div class="ec-detail-row"><span class="label">Fulfillment</span><span class="value"><span class="ec-badge ec-badge-${isDelivery?'delivery':'pickup'}">${isDelivery?'Delivery':'Pickup'}</span></span></div>
        </div>

        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-person"></i>Customer</div>
            <div class="ec-detail-row"><span class="label">Name</span><span class="value">${esc(o.user_name)}</span></div>
            <div class="ec-detail-row"><span class="label">Email</span><span class="value">${esc(o.user_email||'—')}</span></div>
            <div class="ec-detail-row"><span class="label">Contact</span><span class="value">${esc(o.user_contact||'—')}</span></div>
        </div>
<div class="ec-detail-section">
    <div class="ec-detail-section-title"><i class="bi bi-bag"></i>Product</div>
    <div class="d-flex gap-3 align-items-start">
        ${o.product_image ? `<img src="${esc(o.product_image)}" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--ec-border)">` : `<div style="width:64px;height:64px;border-radius:8px;background:var(--ec-bg-soft);display:flex;align-items:center;justify-content:center"><i class="bi bi-eyeglasses fs-3 text-muted"></i></div>`}
        <div class="flex-grow-1">
            <div class="fw-semibold">${esc(o.product_name)}</div>
            <div class="small text-muted">${esc(o.color_name||'—')} • Qty ${o.quantity}</div>
            <div class="small text-muted">Lens: <strong>${esc(o.lens_type||'—')}</strong>${o.lens_index ? ' • Index: <strong style="color:var(--ec-primary)">' + esc(o.lens_index) + '</strong>' : ''}</div>
        </div>
    </div>
</div>

<!-- ✅ BAGO: Prescription Section -->
<div class="ec-detail-section">
    <div class="ec-detail-section-title"><i class="bi bi-prescription"></i>Prescription</div>
    ${o.prescription_id && o.od_sph ? `
        <div class="ec-detail-row">
            <span class="label">Right Eye (OD)</span>
            <span class="value">SPH: ${esc(o.od_sph || '—')}, CYL: ${esc(o.od_cyl || '—')}, AXIS: ${esc(o.od_axis || '—')}</span>
        </div>
        <div class="ec-detail-row">
            <span class="label">Left Eye (OS)</span>
            <span class="value">SPH: ${esc(o.os_sph || '—')}, CYL: ${esc(o.os_cyl || '—')}, AXIS: ${esc(o.os_axis || '—')}</span>
        </div>
        <div class="ec-detail-row">
            <span class="label">Source</span>
            <span class="value">${esc(o.prescription_source === 'user_input' ? 'Provided by customer' : 'From eye exam')}</span>
        </div>
    ` : `
        <div class="ec-detail-row">
            <span class="label">Status</span>
            <span class="value" style="color: var(--ec-text-muted);">
                <i class="bi bi-stethoscope me-1"></i>Eye exam required
            </span>
        </div>
        <div class="ec-detail-row">
            <span class="label">Note</span>
            <span class="value" style="color: var(--ec-text-muted); font-size: .8rem;">Prescription will be filled by optometrist during appointment</span>
        </div>
    `}
</div>

        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-credit-card"></i>Payment</div>
            <div class="ec-detail-row"><span class="label">Total</span><span class="value">₱${parseFloat(o.total_amount).toFixed(2)}</span></div>
            <div class="ec-detail-row"><span class="label">Downpayment</span><span class="value">₱${parseFloat(o.downpayment_amount||0).toFixed(2)}</span></div>
            <div class="ec-detail-row"><span class="label">Balance</span><span class="value">₱${parseFloat(o.balance_amount||0).toFixed(2)}</span></div>
            <div class="ec-detail-row"><span class="label">Status</span><span class="value">${esc(o.payment_status)}</span></div>
        </div>

        ${o.collected_at ? `
        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-cash-coin"></i>Collection Details</div>
            <div class="ec-detail-row"><span class="label">Total Amount Due</span><span class="value">₱${parseFloat(o.total_amount||0).toFixed(2)}</span></div>
            <div class="ec-detail-row"><span class="label">Amount Received</span><span class="value" style="color:#16a34a;font-weight:700;">₱${parseFloat(o.collected_amount||0).toFixed(2)}</span></div>
            ${parseFloat(o.change_amount||0) > 0 ? `
                <div class="ec-detail-row"><span class="label">Change Given</span><span class="value" style="color:#d97706;font-weight:700;">₱${parseFloat(o.change_amount||0).toFixed(2)}</span></div>
            ` : `
                <div class="ec-detail-row"><span class="label">Change Given</span><span class="value" style="color:#64748b;">Exact payment (no change)</span></div>
            `}
            <div class="ec-detail-row"><span class="label">Collected At</span><span class="value">${esc(collectedAtFmt)}</span></div>
            <div class="ec-detail-row"><span class="label">Collected By</span><span class="value">${esc(collectorName||'—')}</span></div>
            ${o.collection_notes ? `<div class="ec-detail-row"><span class="label">Notes</span><span class="value">${esc(o.collection_notes)}</span></div>` : ''}
        </div>` : ''}

        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-${isDelivery?'truck':'shop'}"></i>${isDelivery?'Delivery':'Pickup'}</div>
            ${isDelivery ? `
                <div class="ec-detail-row"><span class="label">Recipient</span><span class="value">${esc(o.delivery_name||'—')}</span></div>
                <div class="ec-detail-row"><span class="label">Phone</span><span class="value">${esc(o.delivery_phone||'—')}</span></div>
                <div class="ec-detail-row"><span class="label">Address</span><span class="value">${esc(o.delivery_address||'—')}${o.delivery_barangay?', '+esc(o.delivery_barangay):''}${o.delivery_city?', '+esc(o.delivery_city):''}${o.delivery_province?', '+esc(o.delivery_province):''}</span></div>
                <div class="ec-detail-row"><span class="label">Fee</span><span class="value">₱${parseFloat(o.delivery_fee||0).toFixed(2)}</span></div>
                <div class="ec-detail-row"><span class="label">ETA</span><span class="value">${esc(deliveryDateFmt)}</span></div>
                <div class="ec-detail-row"><span class="label">Rider</span><span class="value">${esc(o.rider_name||'Not assigned')}</span></div>
                <div class="ec-detail-row"><span class="label">Tracking</span><span class="value">${esc(o.tracking_number||'—')}</span></div>
            ` : `
                <div class="ec-detail-row"><span class="label">Preferred Date</span><span class="value">${esc(preferredDateFmt)}</span></div>
                <div class="ec-detail-row"><span class="label">Preferred Time</span><span class="value">${esc(o.preferred_time||'—')}</span></div>
            `}
        </div>

        ${o.delivery_proof_image ? `
        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-camera"></i>Proof of Delivery</div>
            <div class="ec-proof-section">
                <img src="../${esc(o.delivery_proof_image)}" 
                     alt="Proof of Delivery" 
                     class="ec-proof-img mb-2"
                     onclick="window.open('../${esc(o.delivery_proof_image)}', '_blank')">
                ${o.delivery_proof_notes ? `
                    <div class="ec-detail-row">
                        <span class="label">Rider Notes:</span>
                        <span class="value">${esc(o.delivery_proof_notes)}</span>
                    </div>
                ` : ''}
                ${o.delivery_proof_at ? `
                    <div class="ec-detail-row">
                        <span class="label">Captured:</span>
                        <span class="value">${esc(proofAtFmt)}</span>
                    </div>
                ` : ''}
            </div>
        </div>` : ''}

        <div class="ec-detail-section">
            <div class="ec-detail-section-title"><i class="bi bi-clock-history"></i>Order Timeline</div>
            ${renderTimeline(o.status, isDelivery, createdAtFmt, deliveredAtFmt)}
        </div>
    `;
    detailsModal.show();
}

function renderTimeline(currentStatus, isDelivery, createdAtFormatted, deliveredAtFormatted) {
    const steps = isDelivery
        ? [
            {key:'pending', label:'Order Placed', time:createdAtFormatted},
            {key:'confirmed', label:'Confirmed'},
            {key:'preparing', label:'Preparing'},
            {key:'dispatched', label:'Dispatched'},
            {key:'delivered', label:'Delivered', time:deliveredAtFormatted},
            {key:'completed', label:'Completed'}
        ]
        : [
            {key:'pending', label:'Order Placed', time:createdAtFormatted},
            {key:'confirmed', label:'Confirmed'},
            {key:'ready_for_pickup', label:'Ready for Pickup'},
            {key:'completed', label:'Completed'}
        ];

    if (currentStatus === 'cancelled') {
        return `<div class="ec-timeline-item done">
            <div class="ec-timeline-dot"><i class="bi bi-x-lg"></i></div>
            <div class="ec-timeline-title text-danger">Order Cancelled</div>
        </div>`;
    }

    const order = steps.map(s => s.key);
    const currentIdx = order.indexOf(currentStatus);
    const effectiveIdx = currentIdx >= 0 ? currentIdx : order.length - 1;

    return `<div class="ec-timeline">${steps.map((s, i) => {
        let cls = '';
        if (i < effectiveIdx) cls = 'done';
        else if (i === effectiveIdx) cls = 'current';
        const icon = cls === 'done' ? '<i class="bi bi-check-lg"></i>' : (cls === 'current' ? '<i class="bi bi-circle-fill" style="font-size:.4rem"></i>' : '');
        const time = s.time ? `<div class="ec-timeline-time">${esc(s.time)}</div>` : '';
        return `<div class="ec-timeline-item ${cls}">
            <div class="ec-timeline-dot">${icon}</div>
            <div class="ec-timeline-title">${s.label}</div>
            ${time}
        </div>`;
    }).join('')}</div>`;
}

function openAssignRiderModal(id, code) {
    pendingAssignId = id;
    pendingBulkIds  = null;
    document.getElementById('assignOrderCode').textContent = code;
    assignRiderModal.show();
}

function cancelOrder(id, code) {
    if (!userPermissions.reject) {
        Swal.fire('Access Denied','You do not have permission to cancel orders','error');
        return;
    }
    pendingCancelId = id;
    document.getElementById('cancelCode').textContent = code;
    document.getElementById('cancelReason').value = '';
    cancelModal.show();
}

function postAction(action, reservationId, extra = {}) {
    const id = parseInt(reservationId);
    if (!id || id <= 0) { Swal.fire('Error','Invalid order ID','error'); return; }

    Swal.fire({ title: 'Processing…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

    const formData = new FormData();
    formData.append('action', action);
    formData.append('reservation_id', id);
    Object.entries(extra).forEach(([k,v]) => formData.append(k, v));

    fetch(API_URL, { method:'POST', body:formData, credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon:'success', title:'Done!', text:data.message, timer:1500, showConfirmButton:false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Something went wrong','error');
            }
        })
        .catch(err => {
            console.error('FETCH ERROR:', err);
            Swal.fire('Error','Network error: '+err.message,'error');
        });
}

function esc(t) {
    if (t === null || t === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}

// ═══════════════════════════════════════════════════════════════
// REFUND REQUESTS
// ═══════════════════════════════════════════════════════════════

let refundDetailsModal, approveRefundModal, rejectRefundModal, markRefundedModal;
let allRefunds = [];
let currentRefundFilter = '';
let currentRefundId = null;
let currentRefundMethod = 'manual_gcash';

document.addEventListener('DOMContentLoaded', function() {
    refundDetailsModal  = new bootstrap.Modal(document.getElementById('refundDetailsModal'));
    approveRefundModal  = new bootstrap.Modal(document.getElementById('approveRefundModal'));
    rejectRefundModal   = new bootstrap.Modal(document.getElementById('rejectRefundModal'));
    markRefundedModal   = new bootstrap.Modal(document.getElementById('markRefundedModal'));

    document.querySelectorAll('.ec-refund-filter').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.ec-refund-filter').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentRefundFilter = this.dataset.filter || '';
            renderRefunds();
        });
    });

document.getElementById('confirmApproveRefundBtn')?.addEventListener('click', function() {
    if (!currentRefundId) return;
    const notes = document.getElementById('approveRefundNotes').value.trim();
    const isAuto = currentRefundMethod === 'paymongo';
    
    approveRefundModal.hide();
    
    Swal.fire({
        title: isAuto ? 'Processing automatic refund...' : 'Approving refund...',
        html: isAuto 
            ? 'Sending to PayMongo. This may take a few seconds.' 
            : 'Updating refund status.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'approve_refund');
    formData.append('refund_id', currentRefundId);
    formData.append('refund_method', currentRefundMethod);
    formData.append('admin_notes', notes);
    
    fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                // ✅ Success — automatic refund
                if (data.data && data.data.auto) {
                    Swal.fire({
                        icon: 'success',
                        title: '✅ Refund Processed Automatically!',
                        html: `
                            <p>Refund has been processed via PayMongo.</p>
                            <div style="background:#D1FAE5;padding:10px;border-radius:8px;margin:10px 0;text-align:left;">
                                <div><strong>Amount:</strong> ₱${parseFloat(data.data.amount).toFixed(2)}</div>
                                ${data.data.paymongo_refund_id ? `<div><strong>Refund ID:</strong> <code>${data.data.paymongo_refund_id}</code></div>` : ''}
                            </div>
                            <p class="text-muted small">Customer will receive the refund within 3-5 business days.</p>
                        `,
                        confirmButtonText: 'Okay'
                    }).then(() => {
                        loadRefunds();
                        setTimeout(() => location.reload(), 500);
                    });
                } else {
                    // ✅ Manual approval success
                    Swal.fire({
                        icon: 'success',
                        title: 'Refund Approved',
                        html: `
                            <p>Refund has been approved for manual processing.</p>
                            ${data.data && data.data.manual_reason ? `<p class="text-muted small">Reason: ${data.data.manual_reason}</p>` : ''}
                            <p class="text-muted small">Please send the refund via GCash/Bank and click "Mark as Refunded" when done.</p>
                        `,
                        confirmButtonText: 'Okay'
                    }).then(() => {
                        loadRefunds();
                        setTimeout(() => location.reload(), 500);
                    });
                }
            } else {
                // ❌ Error — check kung requires manual
                if (data.data && data.data.requires_manual) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Auto-refund Failed',
                        html: `
                            <p>${data.message}</p>
                            <div style="background:#FEF3C7;padding:10px;border-radius:8px;margin:10px 0;text-align:left;font-size:13px;">
                                <strong>What to do:</strong>
                                <ul style="margin:5px 0 0 20px;padding:0;">
                                    <li>Refund has been approved for manual processing</li>
                                    <li>Send the refund via GCash/Bank</li>
                                    <li>Click "Mark as Refunded" when done</li>
                                </ul>
                            </div>
                        `,
                        confirmButtonText: 'Okay'
                    }).then(() => {
                        loadRefunds();
                        setTimeout(() => location.reload(), 500);
                    });
                } else {
                    Swal.fire('Error', data.message || 'Something went wrong', 'error');
                }
            }
        })
        .catch(err => {
            Swal.close();
            console.error('Refund action error:', err);
            Swal.fire('Error', 'Network error: ' + err.message, 'error');
        });
});

    document.getElementById('confirmRejectRefundBtn')?.addEventListener('click', function() {
        if (!currentRefundId) return;
        const reason = document.getElementById('rejectRefundReason').value.trim();
        if (!reason) {
            Swal.fire('Missing', 'Please enter a rejection reason', 'warning');
            return;
        }
        rejectRefundModal.hide();
        postRefundAction('reject_refund', {
            refund_id: currentRefundId,
            admin_notes: reason
        });
    });

    document.getElementById('confirmMarkRefundedBtn')?.addEventListener('click', function() {
        if (!currentRefundId) return;
        const ref = document.getElementById('markRefundedRef').value.trim();
        if (!ref) {
            Swal.fire('Missing', 'Please enter a refund reference', 'warning');
            return;
        }
        const notes = document.getElementById('markRefundedNotes').value.trim();
        markRefundedModal.hide();
        postRefundAction('mark_refunded', {
            refund_id: currentRefundId,
            refund_reference: ref,
            admin_notes: notes
        });
    });
});

function loadRefunds() {
    const list = document.getElementById('refundList');
    list.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-primary" role="status"></div><p class="mt-3 mb-0">Loading refunds...</p></div>';

    fetch(API_URL + '?action=list_refunds', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                allRefunds = data.data.refunds || [];
                updateRefundCounts();
                renderRefunds();
            } else {
                list.innerHTML = '<div class="ec-refund-empty"><i class="bi bi-exclamation-triangle"></i><p>Failed to load refunds</p></div>';
            }
        })
        .catch(err => {
            console.error('Refund fetch error:', err);
            list.innerHTML = '<div class="ec-refund-empty"><i class="bi bi-exclamation-triangle"></i><p>Network error</p></div>';
        });
}

function updateRefundCounts() {
    const counts = {
        all: allRefunds.length,
        pending: 0, approved: 0, processing: 0, completed: 0, rejected: 0
    };
    allRefunds.forEach(r => {
        if (counts[r.status] !== undefined) counts[r.status]++;
    });

    document.getElementById('refundCountAll').textContent        = counts.all;
    document.getElementById('refundCountPending').textContent    = counts.pending;
    document.getElementById('refundCountApproved').textContent   = counts.approved;
    document.getElementById('refundCountProcessing').textContent = counts.processing;
    document.getElementById('refundCountCompleted').textContent  = counts.completed;
    document.getElementById('refundCountRejected').textContent   = counts.rejected;

    const tabCount = document.getElementById('refundTabCount');
    if (tabCount) {
        tabCount.textContent = counts.pending;
        tabCount.style.display = counts.pending > 0 ? '' : 'none';
    }
}

function renderRefunds() {
    const list = document.getElementById('refundList');
    const filtered = currentRefundFilter
        ? allRefunds.filter(r => r.status === currentRefundFilter)
        : allRefunds;

    if (filtered.length === 0) {
        list.innerHTML = '<div class="ec-refund-empty"><i class="bi bi-inbox"></i><p>No refund requests' + (currentRefundFilter ? ' with status "' + currentRefundFilter + '"' : '') + '</p></div>';
        return;
    }

    list.innerHTML = filtered.map(r => {
        const statusMap = {
            pending:    { label: 'Pending',    cls: 'pending',    icon: 'hourglass-split' },
            approved:   { label: 'Approved',   cls: 'approved',   icon: 'check-circle' },
            processing: { label: 'Processing', cls: 'processing', icon: 'arrow-repeat' },
            completed:  { label: 'Completed',  cls: 'completed',  icon: 'check2-all' },
            rejected:   { label: 'Rejected',   cls: 'rejected',   icon: 'x-circle' },
            failed:     { label: 'Failed',     cls: 'failed',     icon: 'exclamation-triangle' }
        };
        const st = statusMap[r.status] || statusMap.pending;

        const productImg = r.product_image
            ? `<img src="../${esc(r.product_image)}" alt="" class="ec-refund-product">`
            : `<div class="ec-refund-product d-flex align-items-center justify-content-center text-muted"><i class="bi bi-eyeglasses fs-4"></i></div>`;

        // ✅ Return method info
        const rm = getReturnMethodInfoJS(r.return_method || 'dropoff');
        const returnSchedule = r.return_scheduled_date_formatted
            ? `${r.return_scheduled_date_formatted}${r.return_scheduled_time_formatted ? ' at ' + r.return_scheduled_time_formatted : ''}`
            : null;

        // ✅ Build actions based on status
        let actions = '';
        
if (r.status === 'pending') {
    // ✅ Auto-detect: kung may pay_xxx ID, auto-capable
    const canAuto = r.paymongo_payment_id && String(r.paymongo_payment_id).startsWith('pay_');
            
            actions = `
                <button class="btn btn-outline-secondary btn-sm" onclick='viewRefund(${JSON.stringify(r).replace(/'/g, "\\'")})'>
                    <i class="bi bi-eye me-1"></i>View
                </button>
                <button class="btn btn-danger btn-sm" onclick='openRejectRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount})'>
                    <i class="bi bi-x-lg me-1"></i>Reject
                </button>
                ${canAuto ? `
                    <button class="btn btn-success btn-sm" onclick='openApproveRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount}, true)'>
                        <i class="bi bi-lightning-charge-fill me-1"></i>Process Refund Now
                    </button>
                ` : `
                    <button class="btn ec-btn-primary btn-sm" onclick='openApproveRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount}, false)'>
                        <i class="bi bi-check-lg me-1"></i>Approve Refund
                    </button>
                `}
            `;
        } else if (r.status === 'approved' || r.status === 'processing') {
            actions = `
                <button class="btn btn-outline-secondary btn-sm" onclick='viewRefund(${JSON.stringify(r).replace(/'/g, "\\'")})'>
                    <i class="bi bi-eye me-1"></i>View
                </button>
                <button class="btn btn-success btn-sm" onclick='openMarkRefunded(${r.id}, "${esc(r.reservation_code)}", ${r.amount})'>
                    <i class="bi bi-cash-stack me-1"></i>Mark Refunded
                </button>
            `;
        } else {
            actions = `
                <button class="btn btn-outline-secondary btn-sm" onclick='viewRefund(${JSON.stringify(r).replace(/'/g, "\\'")})'>
                    <i class="bi bi-eye me-1"></i>View
                </button>
            `;
        }

        return `
            <div class="ec-refund-card">
                <div class="ec-refund-header">
                    <div>
                        <div class="ec-refund-id">${esc(r.reservation_code)}</div>
                        <div class="ec-refund-date">
                            <i class="bi bi-clock me-1"></i>${esc(r.created_at_formatted)}
                        </div>
                    </div>
                    <span class="ec-refund-status ec-refund-${st.cls}">
                        <i class="bi bi-${st.icon}"></i>${st.label}
                    </span>
                </div>

                <div class="ec-refund-body">
                    ${productImg}
                    <div class="ec-refund-info">
                        <div class="ec-refund-customer">${esc(r.user_name)}</div>
                        <div class="ec-refund-product-name">${esc(r.product_name || '(Product not available)')}</div>
                        <div class="ec-refund-reason">
                            <strong>Reason:</strong> ${esc(r.reason || 'No reason given')}
                        </div>
                    </div>
                    <div class="ec-refund-right">
                        <div class="ec-refund-amount">₱${parseFloat(r.amount).toFixed(2)}</div>
                        <small class="text-muted">Order total: ₱${parseFloat(r.order_total || 0).toFixed(2)}</small>
                    </div>
                </div>

                <!-- ✅ Return Method Section -->
                <div class="ec-return-method-box">
                    <div class="ec-return-method-header">
                        <i class="bi bi-${rm.icon}"></i>
                        <span>${rm.label}</span>
                        <span class="ec-return-badge ec-return-${rm.color}">${rm.short}</span>
                    </div>
                    <div class="ec-return-method-details">
                        ${returnSchedule ? `
                            <div class="ec-return-detail-row">
                                <span class="lbl"><i class="bi bi-calendar-event"></i> Schedule:</span>
                                <span class="val">${esc(returnSchedule)}</span>
                            </div>
                        ` : ''}
                        
                        ${r.return_method === 'pickup' && r.return_address ? `
                            <div class="ec-return-detail-row">
                                <span class="lbl"><i class="bi bi-geo-alt"></i> Pickup Address:</span>
                                <span class="val">${esc(r.return_address)}</span>
                            </div>
                        ` : ''}
                        
                        ${r.return_contact_name ? `
                            <div class="ec-return-detail-row">
                                <span class="lbl"><i class="bi bi-person"></i> Contact:</span>
                                <span class="val">${esc(r.return_contact_name)}${r.return_contact_phone ? ' • ' + esc(r.return_contact_phone) : ''}</span>
                            </div>
                        ` : ''}
                        
                        ${r.return_notes ? `
                            <div class="ec-return-detail-row">
                                <span class="lbl"><i class="bi bi-chat-left-text"></i> Notes:</span>
                                <span class="val">${esc(r.return_notes)}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <div class="ec-refund-actions">
                    ${actions}
                </div>
            </div>
        `;
    }).join('');
}
// ✅ FIXED: Return method helper (JS version)
function getReturnMethodInfoJS(method) {
    const map = {
        'dropoff': {
            label: 'Drop-off at Clinic',
            short: 'DROP-OFF',
            icon: 'shop',
            color: 'primary'
        },
        'pickup': {
            label: 'Clinic Pickup',
            short: 'PICKUP',
            icon: 'truck',
            color: 'warning'
        },
        'courier': {
            label: 'Courier / Ship',
            short: 'COURIER',
            icon: 'truck-front',
            color: 'info'
        }
    };
    return map[method] || map['dropoff'];
}

function viewRefund(r) {
    const body = document.getElementById('refundDetailsBody');
    const footer = document.getElementById('refundDetailsFooter');

    const statusMap = {
        pending:    { label: 'Pending',    cls: 'pending' },
        approved:   { label: 'Approved',   cls: 'approved' },
        processing: { label: 'Processing', cls: 'processing' },
        completed:  { label: 'Completed',  cls: 'completed' },
        rejected:   { label: 'Rejected',   cls: 'rejected' },
        failed:     { label: 'Failed',     cls: 'failed' }
    };
    const st = statusMap[r.status] || statusMap.pending;

    // ✅ FIXED: Return method info
    const rm = getReturnMethodInfoJS(r.return_method || 'dropoff');
    const returnSchedule = r.return_scheduled_date_formatted
        ? `${r.return_scheduled_date_formatted}${r.return_scheduled_time_formatted ? ' at ' + r.return_scheduled_time_formatted : ''}`
        : null;

    body.innerHTML = `
        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-info-circle"></i>Order Information</div>
            <div class="ec-refund-detail-row"><span class="label">Order #</span><span class="value">${esc(r.reservation_code)}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Requested</span><span class="value">${esc(r.created_at_formatted)}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Status</span><span class="value"><span class="ec-refund-status ec-refund-${st.cls}">${st.label}</span></span></div>
            <div class="ec-refund-detail-row"><span class="label">Order Total</span><span class="value">₱${parseFloat(r.order_total).toFixed(2)}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Refund Amount</span><span class="value" style="color:#dc2626;">₱${parseFloat(r.amount).toFixed(2)}</span></div>
        </div>

        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-person"></i>Customer</div>
            <div class="ec-refund-detail-row"><span class="label">Name</span><span class="value">${esc(r.user_name)}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Email</span><span class="value">${esc(r.user_email || '—')}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Contact</span><span class="value">${esc(r.user_contact || '—')}</span></div>
        </div>

        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-bag"></i>Product</div>
            <div class="d-flex gap-3 align-items-start">
                ${r.product_image ? `<img src="../${esc(r.product_image)}" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--ec-border)">` : ''}
                <div>
                    <div class="fw-semibold">${esc(r.product_name)}</div>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-exclamation-circle"></i>Refund Request</div>
            <div class="ec-refund-detail-row"><span class="label">Reason</span><span class="value">${esc(r.reason || '—')}</span></div>
            ${r.details ? `<div class="ec-refund-detail-row"><span class="label">Details</span><span class="value">${esc(r.details)}</span></div>` : ''}
${(r.evidence_images_array && r.evidence_images_array.length > 0) ? `
    <div class="ec-refund-detail-row" style="flex-direction: column; align-items: flex-start; padding-top: .75rem;">
        <span class="label" style="margin-bottom: .5rem;">
            <i class="bi bi-images me-1"></i>Evidence Photos (${r.evidence_images_array.length}):
        </span>
        <div class="ec-refund-evidence-grid">
            ${r.evidence_images_array.map((img, idx) => `
                <div class="ec-evidence-thumb" onclick="window.open('../${esc(img)}', '_blank')">
                    <img src="../${esc(img)}" 
                         alt="Evidence ${idx + 1}" 
                         onerror="this.parentElement.style.display='none'">
                    <div class="ec-evidence-number">${idx + 1}</div>
                </div>
            `).join('')}
        </div>
    </div>
` : (r.evidence_image ? `
    <div class="ec-refund-detail-row" style="flex-direction: column; align-items: flex-start; padding-top: .75rem;">
        <span class="label" style="margin-bottom: .5rem;">Evidence Photo:</span>
        <div class="ec-refund-evidence" style="width: 100%;">
            <img src="../${esc(r.evidence_image)}" 
                 alt="Evidence" 
                 style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 1px solid var(--ec-border); cursor: pointer;"
                 onclick="window.open('../${esc(r.evidence_image)}', '_blank')"
                 onerror="this.style.display='none'">
        </div>
    </div>
` : '')}
            ${r.refund_percentage ? `<div class="ec-refund-detail-row"><span class="label">Refund %</span><span class="value">${r.refund_percentage}%</span></div>` : ''}
        </div>

        <!-- ✅ FIXED: Return Method Section -->
        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-arrow-return-left"></i>Return Method</div>
            <div class="ec-return-method-box">
                <div class="ec-return-method-header">
                    <i class="bi bi-${rm.icon}"></i>
                    <span>${rm.label}</span>
                    <span class="ec-return-badge ec-return-${rm.color}">${rm.short}</span>
                </div>
                <div class="ec-return-method-details">
                    ${returnSchedule ? `
                        <div class="ec-return-detail-row">
                            <span class="lbl"><i class="bi bi-calendar-event"></i> Schedule:</span>
                            <span class="val">${esc(returnSchedule)}</span>
                        </div>
                    ` : ''}
                    
                    ${r.return_method === 'pickup' && r.return_address ? `
                        <div class="ec-return-detail-row">
                            <span class="lbl"><i class="bi bi-geo-alt"></i> Pickup Address:</span>
                            <span class="val">${esc(r.return_address)}</span>
                        </div>
                    ` : ''}
                    
                    ${r.return_contact_name ? `
                        <div class="ec-return-detail-row">
                            <span class="lbl"><i class="bi bi-person"></i> Contact:</span>
                            <span class="val">${esc(r.return_contact_name)}${r.return_contact_phone ? ' • ' + esc(r.return_contact_phone) : ''}</span>
                        </div>
                    ` : ''}
                    
                    ${r.return_notes ? `
                        <div class="ec-return-detail-row">
                            <span class="lbl"><i class="bi bi-chat-left-text"></i> Notes:</span>
                            <span class="val">${esc(r.return_notes)}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        </div>

        ${r.admin_notes ? `
        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-chat-left-text"></i>Admin Notes</div>
            <div style="background:#f8fafc;padding:.75rem;border-radius:8px;font-size:.85rem;">
                ${esc(r.admin_notes)}
            </div>
        </div>` : ''}

        ${r.processed_at_formatted ? `
        <div class="mb-3">
            <div class="ec-detail-section-title"><i class="bi bi-clock-history"></i>Processing</div>
            <div class="ec-refund-detail-row"><span class="label">Processed</span><span class="value">${esc(r.processed_at_formatted)}</span></div>
            <div class="ec-refund-detail-row"><span class="label">Processed By</span><span class="value">${esc(r.processed_by_name || '—')}</span></div>
        </div>` : ''}
    `;

// ✅ Build footer actions based on status
let footerHtml = `<button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;

if (r.status === 'pending') {
    // ✅ Auto-detect: kung may pay_xxx ID, auto-capable
    const canAuto = r.paymongo_payment_id && String(r.paymongo_payment_id).startsWith('pay_');

    footerHtml += `
        <button class="btn btn-danger" onclick='refundDetailsModal.hide(); openRejectRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount})'>
            <i class="bi bi-x-lg me-1"></i>Reject
        </button>
        ${canAuto ? `
            <button class="btn btn-success" onclick='refundDetailsModal.hide(); openApproveRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount}, true)'>
                <i class="bi bi-lightning-charge-fill me-1"></i>Process Refund Now
            </button>
        ` : `
            <button class="btn ec-btn-primary" onclick='refundDetailsModal.hide(); openApproveRefund(${r.id}, "${esc(r.reservation_code)}", ${r.amount}, false)'>
                <i class="bi bi-check-lg me-1"></i>Approve Refund
            </button>
        `}
    `;
} else if (r.status === 'approved' || r.status === 'processing') {
    footerHtml += `
        <button class="btn btn-success" onclick='refundDetailsModal.hide(); openMarkRefunded(${r.id}, "${esc(r.reservation_code)}", ${r.amount})'>
            <i class="bi bi-cash-stack me-1"></i>Mark as Refunded
        </button>
    `;
}

footer.innerHTML = footerHtml;

    refundDetailsModal.show();
}
function selectRefundMethod(el, method) {
    document.querySelectorAll('.ec-refund-method-option').forEach(opt => opt.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input').checked = true;
    currentRefundMethod = method;
}

function openApproveRefund(refundId, code, amount, canAuto = false) {
    currentRefundId = refundId;
    
    // ✅ Default method based sa canAuto
    currentRefundMethod = canAuto ? 'paymongo' : 'manual_gcash';

    document.getElementById('approveRefundOrder').textContent = code;
    document.getElementById('approveRefundAmount').textContent = '₱' + parseFloat(amount).toFixed(2);
    document.getElementById('approveRefundNotes').value = '';

    // ✅ Preset method: paymongo kung auto-capable, manual_gcash kung hindi
    document.querySelectorAll('.ec-refund-method-option').forEach((opt) => {
        opt.classList.remove('selected');
    });
    
    const targetValue = canAuto ? 'paymongo' : 'manual_gcash';
    const targetOption = document.querySelector(`.ec-refund-method-option input[value="${targetValue}"]`);
    if (targetOption) {
        targetOption.checked = true;
        targetOption.closest('.ec-refund-method-option').classList.add('selected');
    }

    // ✅ Update modal title + button
    const modalTitle = document.querySelector('#approveRefundModal .modal-title');
    const confirmBtn = document.getElementById('confirmApproveRefundBtn');
    
    if (canAuto) {
        modalTitle.innerHTML = '<i class="bi bi-lightning-charge-fill text-success me-2"></i>Process Refund Now (Auto)';
        confirmBtn.innerHTML = '<i class="bi bi-lightning-charge-fill me-1"></i>Process Refund Now';
        confirmBtn.className = 'btn btn-success';
    } else {
        modalTitle.innerHTML = '<i class="bi bi-check-circle text-success me-2"></i>Approve Refund (Manual)';
        confirmBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Approve Refund';
        confirmBtn.className = 'btn ec-btn-primary';
    }

    approveRefundModal.show();
}
function openRejectRefund(refundId, code, amount) {
    currentRefundId = refundId;

    document.getElementById('rejectRefundOrder').textContent = code;
    document.getElementById('rejectRefundAmount').textContent = '₱' + parseFloat(amount).toFixed(2);
    document.getElementById('rejectRefundReason').value = '';

    rejectRefundModal.show();
}

function openMarkRefunded(refundId, code, amount) {
    currentRefundId = refundId;

    document.getElementById('markRefundedOrder').textContent = code;
    document.getElementById('markRefundedAmount').textContent = '₱' + parseFloat(amount).toFixed(2);
    document.getElementById('markRefundedRef').value = '';
    document.getElementById('markRefundedNotes').value = '';

    markRefundedModal.show();
}

function postRefundAction(action, extra = {}) {
    Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

    const formData = new FormData();
    formData.append('action', action);
    Object.entries(extra).forEach(([k, v]) => formData.append(k, v));

    fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Done!', text: data.message, timer: 1500, showConfirmButton: false })
                    .then(() => {
                        loadRefunds();
                        setTimeout(() => location.reload(), 500);
                    });
            } else {
                Swal.fire('Error', data.message || 'Something went wrong', 'error');
            }
        })
        .catch(err => {
            console.error('Refund action error:', err);
            Swal.fire('Error', 'Network error: ' + err.message, 'error');
        });
}
</script>