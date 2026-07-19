<?php
// eyecore/views/reservations.php
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
    echo '<div class="alert alert-danger">You do not have permission to view reservations.</div>';
    exit;
}

// ── HANDLE AJAX ACTIONS ──────────────────────────────────────────
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $res_id = (int)($_POST['reservation_id'] ?? 0);

    if (!$res_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid reservation']);
        exit;
    }

    if ($action === 'confirm' && !canApproveReservations()) {
        echo json_encode(['success' => false, 'message' => 'No permission to confirm']);
        exit;
    }
    if ($action === 'cancel' && !canRejectReservations()) {
        echo json_encode(['success' => false, 'message' => 'No permission to cancel']);
        exit;
    }
    if ($action === 'complete' && !canEditReservations()) {
        echo json_encode(['success' => false, 'message' => 'No permission to complete']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.*, u.id as user_id_val, CONCAT(u.first_name,' ',u.last_name) as user_name
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.clinic_id = ?
    ");
    $stmt->execute([$res_id, $clinicId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Reservation not found']);
        exit;
    }

    if ($action === 'confirm') {
        $pdo->prepare("UPDATE reservations SET status='confirmed', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$res_id, $clinicId]);
        $uid = (int)$res['user_id_val'];
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$uid, 'reservation', 'Reservation Confirmed ✅',
                "Your reservation {$res['reservation_code']} has been confirmed! Please proceed with the downpayment.",
                'my-reservations.php']);
        echo json_encode(['success' => true, 'message' => 'Reservation confirmed successfully']);
        exit;
    }

    if ($action === 'complete') {
        $pdo->prepare("UPDATE reservations SET status='completed', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$res_id, $clinicId]);
        $uid = (int)$res['user_id_val'];
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$uid, 'reservation', 'Reservation Completed 🎉',
                "Your reservation {$res['reservation_code']} has been completed. Thank you!",
                'my-reservations.php']);
        echo json_encode(['success' => true, 'message' => 'Reservation marked as completed']);
        exit;
    }

    if ($action === 'cancel') {
        $reason = htmlspecialchars($_POST['reason'] ?? 'Cancelled by clinic');
        $pdo->prepare("UPDATE reservations SET status='cancelled', notes=CONCAT(IFNULL(notes,''),?), updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute(["\n[Clinic: $reason]", $res_id, $clinicId]);
        $uid = (int)$res['user_id_val'];
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$uid, 'reservation', 'Reservation Cancelled ❌',
                "Your reservation {$res['reservation_code']} has been cancelled. Reason: $reason",
                'my-reservations.php']);
        echo json_encode(['success' => true, 'message' => 'Reservation cancelled']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── FETCH STATS ──────────────────────────────────────────────────
$stmtStats = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(status='pending')   AS pending,
           SUM(status='confirmed') AS confirmed,
           SUM(status='completed') AS completed,
           SUM(status='cancelled') AS cancelled
    FROM reservations WHERE clinic_id=?
");
$stmtStats->execute([$clinicId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ── FETCH PENDING ────────────────────────────────────────────────
$stmtPending = $pdo->prepare("
    SELECT r.id AS res_id, r.*,
           CONCAT(u.first_name,' ',u.last_name) AS user_name,
           u.email AS user_email, u.contact AS user_contact,
           p.name AS product_name, p.category AS product_category,
           p.price AS product_price, p.image AS product_image
    FROM reservations r
    JOIN users u    ON r.user_id    = u.id
    JOIN products p ON r.product_id = p.id
    WHERE r.clinic_id=? AND r.status='pending'
    ORDER BY r.created_at ASC
");
$stmtPending->execute([$clinicId]);
$pendingList = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

// ── FETCH CONFIRMED ──────────────────────────────────────────────
$stmtConfirmed = $pdo->prepare("
    SELECT r.id AS res_id, r.*,
           CONCAT(u.first_name,' ',u.last_name) AS user_name,
           u.email AS user_email, u.contact AS user_contact,
           p.name AS product_name, p.category AS product_category,
           p.price AS product_price, p.image AS product_image
    FROM reservations r
    JOIN users u    ON r.user_id    = u.id
    JOIN products p ON r.product_id = p.id
    WHERE r.clinic_id=? AND r.status='confirmed'
    ORDER BY r.created_at DESC
");
$stmtConfirmed->execute([$clinicId]);
$confirmedList = $stmtConfirmed->fetchAll(PDO::FETCH_ASSOC);

// ── FETCH COMPLETED ──────────────────────────────────────────────
$stmtCompleted = $pdo->prepare("
    SELECT r.id AS res_id, r.*,
           CONCAT(u.first_name,' ',u.last_name) AS user_name,
           u.email AS user_email,
           p.name AS product_name, p.category AS product_category, p.price AS product_price
    FROM reservations r
    JOIN users u    ON r.user_id    = u.id
    JOIN products p ON r.product_id = p.id
    WHERE r.clinic_id=? AND r.status='completed'
    ORDER BY r.updated_at DESC LIMIT 50
");
$stmtCompleted->execute([$clinicId]);
$completedList = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);

// ── FETCH CANCELLED ──────────────────────────────────────────────
$stmtCancelled = $pdo->prepare("
    SELECT r.id AS res_id, r.*,
           CONCAT(u.first_name,' ',u.last_name) AS user_name,
           p.name AS product_name, p.price AS product_price
    FROM reservations r
    JOIN users u    ON r.user_id    = u.id
    JOIN products p ON r.product_id = p.id
    WHERE r.clinic_id=? AND r.status='cancelled'
    ORDER BY r.updated_at DESC LIMIT 30
");
$stmtCancelled->execute([$clinicId]);
$cancelledList = $stmtCancelled->fetchAll(PDO::FETCH_ASSOC);

function resBadge(string $s): string {
    return match($s) {
        'pending'   => '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed' => '<span class="badge bg-info text-dark">Confirmed</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        'cancelled' => '<span class="badge bg-danger">Cancelled</span>',
        default     => '<span class="badge bg-secondary">'.ucfirst($s).'</span>',
    };
}
?>

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="page-title mb-0"><i class="bi bi-bookmark-check me-2"></i>Reservations</h1>
        <p class="page-subtitle text-muted">Manage product reservations from customers</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary btn-sm" onclick="refreshPage()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>
</div>

<!-- STATS -->
<div class="row g-3 mb-4">
    <?php
    $statItems = [
        ['label'=>'Total',     'val'=>$stats['total'],     'color'=>'primary', 'icon'=>'bi-bookmark'],
        ['label'=>'Pending',   'val'=>$stats['pending'],   'color'=>'warning', 'icon'=>'bi-hourglass-split'],
        ['label'=>'Confirmed', 'val'=>$stats['confirmed'], 'color'=>'info',    'icon'=>'bi-check-circle'],
        ['label'=>'Completed', 'val'=>$stats['completed'], 'color'=>'success', 'icon'=>'bi-bag-check'],
        ['label'=>'Cancelled', 'val'=>$stats['cancelled'], 'color'=>'danger',  'icon'=>'bi-x-circle'],
    ];
    foreach ($statItems as $s): ?>
    <div class="col-6 col-sm-4 col-lg">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="bi <?= $s['icon'] ?> text-<?= $s['color'] ?> fs-4 mb-1 d-block"></i>
                <div class="fw-bold fs-4 text-<?= $s['color'] ?>"><?= $s['val'] ?></div>
                <small class="text-muted"><?= $s['label'] ?></small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- TABS -->
<ul class="nav nav-tabs mb-4" id="resTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pending">
            <i class="bi bi-hourglass-split me-1"></i>Pending Approval
            <?php if ($stats['pending'] > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?= $stats['pending'] ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-confirmed">
            <i class="bi bi-check-circle me-1"></i>Confirmed
            <?php if ($stats['confirmed'] > 0): ?>
                <span class="badge bg-info text-dark ms-1"><?= $stats['confirmed'] ?></span>
            <?php endif; ?>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-completed">
            <i class="bi bi-bag-check me-1"></i>Completed
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cancelled">
            <i class="bi bi-x-circle me-1"></i>Cancelled
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history">
            <i class="bi bi-clock-history me-1"></i>Reservation History
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- PENDING TAB -->
    <div class="tab-pane fade show active" id="tab-pending">
        <?php if (empty($pendingList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                No pending reservations
            </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($pendingList as $r): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-warning border-opacity-50 shadow-sm h-100">
                    <div class="card-header d-flex justify-content-between align-items-center py-2 bg-warning bg-opacity-10">
                        <span class="fw-bold small"><?= htmlspecialchars($r['reservation_code']) ?></span>
                        <?= resBadge($r['status']) ?>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                 style="width:38px;height:38px;font-size:14px;flex-shrink:0;">
                                <?= strtoupper(substr($r['user_name'],0,1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($r['user_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($r['user_email']) ?></small>
                                <div class="small text-muted"><i class="bi bi-phone"></i> <?= htmlspecialchars($r['user_contact'] ?? '—') ?></div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <span class="badge bg-light text-dark border mb-1"><?= htmlspecialchars($r['product_category']) ?></span>
                            <div class="fw-semibold"><?= htmlspecialchars($r['product_name']) ?></div>
                            <small class="text-muted">
                                Color: <?= htmlspecialchars($r['color_name'] ?? 'N/A') ?>
                                · Qty: <?= $r['quantity'] ?>
                            </small>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Total Amount</span>
                            <span class="fw-bold text-success">₱<?= number_format($r['total_amount'], 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted small">Downpayment Required</span>
                            <span class="fw-bold text-primary">₱<?= number_format($r['downpayment_amount'], 2) ?></span>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-clock me-1"></i>Reserved <?= date('M d, Y g:i A', strtotime($r['created_at'])) ?>
                        </small>
                        <?php if (!empty($r['notes'])): ?>
                        <div class="mt-2 p-2 bg-light rounded small">
                            <i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($r['notes']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (canApproveReservations() || canRejectReservations()): ?>
                    <div class="card-footer bg-transparent d-flex gap-2">
                        <?php if (canApproveReservations()): ?>
                        <button class="btn btn-success btn-sm flex-fill"
                                onclick="confirmReservation(<?= (int)$r['res_id'] ?>, '<?= addslashes($r['reservation_code']) ?>')">
                            <i class="bi bi-check-lg me-1"></i>Confirm
                        </button>
                        <?php endif; ?>
                        <?php if (canRejectReservations()): ?>
                        <button class="btn btn-outline-danger btn-sm flex-fill"
                                onclick="cancelReservation(<?= (int)$r['res_id'] ?>, '<?= addslashes($r['reservation_code']) ?>')">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- CONFIRMED TAB -->
    <div class="tab-pane fade" id="tab-confirmed">
        <?php if (empty($confirmedList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check-circle fs-1 d-block mb-3 opacity-50"></i>
                No confirmed reservations
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Code</th><th>Customer</th><th>Product</th>
                        <th>Color / Qty</th><th>Amount</th><th>Date</th>
                        <?php if (canEditReservations()): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($confirmedList as $r): ?>
                    <tr>
                        <td><span class="fw-bold text-primary"><?= htmlspecialchars($r['reservation_code']) ?></span></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['user_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($r['user_contact'] ?? '') ?></small>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($r['product_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($r['product_category']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($r['color_name'] ?? 'N/A') ?> · x<?= $r['quantity'] ?></td>
                        <td>
                            <div class="fw-bold text-success">₱<?= number_format($r['total_amount'], 2) ?></div>
                            <small class="text-muted">DP: ₱<?= number_format($r['downpayment_amount'], 2) ?></small>
                        </td>
                        <td><small><?= date('M d, Y', strtotime($r['created_at'])) ?></small></td>
                        <?php if (canEditReservations()): ?>
                        <td>
                            <button class="btn btn-success btn-sm"
                                    onclick="completeReservation(<?= (int)$r['res_id'] ?>, '<?= addslashes($r['reservation_code']) ?>')">
                                <i class="bi bi-bag-check me-1"></i>Complete
                            </button>
                            <?php if (canRejectReservations()): ?>
                            <button class="btn btn-outline-danger btn-sm ms-1"
                                    onclick="cancelReservation(<?= (int)$r['res_id'] ?>, '<?= addslashes($r['reservation_code']) ?>')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- COMPLETED TAB -->
    <div class="tab-pane fade" id="tab-completed">
        <?php if (empty($completedList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-bag-check fs-1 d-block mb-3 opacity-50"></i>
                No completed reservations yet
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr><th>Code</th><th>Customer</th><th>Product</th><th>Amount</th><th>Completed</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($completedList as $r): ?>
                    <tr>
                        <td><span class="fw-bold text-success"><?= htmlspecialchars($r['reservation_code']) ?></span></td>
                        <td><?= htmlspecialchars($r['user_name']) ?></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td class="fw-bold">₱<?= number_format($r['total_amount'], 2) ?></td>
                        <td><small class="text-muted"><?= date('M d, Y', strtotime($r['updated_at'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- CANCELLED TAB -->
    <div class="tab-pane fade" id="tab-cancelled">
        <?php if (empty($cancelledList)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-x-circle fs-1 d-block mb-3 opacity-50"></i>
                No cancelled reservations
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr><th>Code</th><th>Customer</th><th>Product</th><th>Amount</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cancelledList as $r): ?>
                    <tr class="text-muted">
                        <td><?= htmlspecialchars($r['reservation_code']) ?></td>
                        <td><?= htmlspecialchars($r['user_name']) ?></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td>₱<?= number_format($r['total_amount'], 2) ?></td>
                        <td><small><?= date('M d, Y', strtotime($r['updated_at'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- HISTORY TAB -->
    <div class="tab-pane fade" id="tab-history">
        <div class="card">
            <div class="card-header bg-light">
                <div class="row g-2">
                    <div class="col-md-4">
                        <input type="text" class="form-control" id="historySearch" placeholder="Search customer or product...">
                    </div>
                    <div class="col-md-3">
                        <input type="date" class="form-control" id="historyDateFrom">
                    </div>
                    <div class="col-md-3">
                        <input type="date" class="form-control" id="historyDateTo">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="historyStatus">
                            <option value="">All Status</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="historyTable">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th><th>Date Reserved</th><th>Customer</th>
                                <th>Product</th><th>Quantity</th><th>Total Amount</th><th>Status</th>
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

</div><!-- /tab-content -->

<!-- CANCEL MODAL -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Cancel Reservation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Cancel reservation <strong id="cancelCode"></strong>?</p>
                <div class="mb-3">
                    <label class="form-label">Reason <small class="text-muted">(optional)</small></label>
                    <textarea class="form-control" id="cancelReason" rows="3"
                              placeholder="e.g. Product out of stock, customer request..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                <button class="btn btn-danger" id="confirmCancelBtn">
                    <i class="bi bi-x-lg me-1"></i>Cancel Reservation
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const userPermissions = {
    view:    <?= json_encode(canViewReservations()) ?>,
    create:  <?= json_encode(canCreateReservations()) ?>,
    edit:    <?= json_encode(canEditReservations()) ?>,
    delete:  <?= json_encode(canDeleteReservations()) ?>,
    approve: <?= json_encode(canApproveReservations()) ?>,
    reject:  <?= json_encode(canRejectReservations()) ?>
};

function canApprove() { return userPermissions.approve; }
function canEdit()    { return userPermissions.edit; }
function canReject()  { return userPermissions.reject; }

let cancelModal = null;
let pendingCancelId = null;

document.addEventListener('DOMContentLoaded', function () {
    cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
});

function refreshPage() { location.reload(); }

// ── CONFIRM ──
function confirmReservation(id, code) {
    if (!canApprove()) {
        Swal.fire('Access Denied', 'You do not have permission to confirm reservations', 'error');
        return;
    }
    Swal.fire({
        title: 'Confirm Reservation?',
        html: `Confirm reservation <strong>${code}</strong>?<br><small class="text-muted">User will be notified to proceed with downpayment.</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-lg"></i> Yes, Confirm',
        cancelButtonText: 'Back'
    }).then(result => {
        if (result.isConfirmed) postAction('confirm', id);
    });
}

// ── COMPLETE ──
function completeReservation(id, code) {
    if (!canEdit()) {
        Swal.fire('Access Denied', 'You do not have permission to complete reservations', 'error');
        return;
    }
    Swal.fire({
        title: 'Mark as Completed?',
        html: `Mark reservation <strong>${code}</strong> as completed?<br><small class="text-muted">This means the customer has picked up the item.</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: '<i class="bi bi-bag-check"></i> Mark Complete'
    }).then(result => {
        if (result.isConfirmed) postAction('complete', id);
    });
}

// ── CANCEL ──
function cancelReservation(id, code) {
    if (!canReject()) {
        Swal.fire('Access Denied', 'You do not have permission to cancel reservations', 'error');
        return;
    }
    pendingCancelId = id;
    document.getElementById('cancelCode').textContent = code;
    document.getElementById('cancelReason').value = '';
    cancelModal.show();
}

document.getElementById('confirmCancelBtn')?.addEventListener('click', () => {
    if (!pendingCancelId) return;
    const reason = document.getElementById('cancelReason').value.trim() || 'Cancelled by clinic';
    cancelModal.hide();
    postAction('cancel', pendingCancelId, { reason });
    pendingCancelId = null;
});

// ── POST ACTION — posts directly to reservations.php via main.php ──
function postAction(action, reservationId, extra = {}) {
    const id = parseInt(reservationId);

    if (!id || id <= 0) {
        Swal.fire('Error', 'Invalid reservation ID: ' + reservationId, 'error');
        return;
    }

    Swal.fire({ title: 'Processing…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

    const formData = new FormData();
    formData.append('action', action);
    formData.append('reservation_id', id);
    Object.entries(extra).forEach(([k, v]) => formData.append(k, v));

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(text => {
            // Extract JSON from response (strip any HTML before it)
            const jsonStart = text.indexOf('{"');
            const jsonText  = jsonStart !== -1 ? text.substring(jsonStart) : text;
            let data;
            try {
                data = JSON.parse(jsonText);
            } catch (e) {
                console.error('Parse error, raw response:', text);
                Swal.fire('Error', 'Unexpected server response. Check console.', 'error');
                return;
            }
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Done!', text: data.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Something went wrong', 'error');
            }
        })
        .catch(err => {
            console.error('FETCH ERROR:', err);
            Swal.fire('Error', 'Network error: ' + err.message, 'error');
        });
}

// ── HISTORY TAB ──
document.querySelector('button[data-bs-target="#tab-history"]')
    ?.addEventListener('shown.bs.tab', loadReservationHistory);

document.getElementById('historySearch')   ?.addEventListener('keyup',  loadReservationHistory);
document.getElementById('historyDateFrom') ?.addEventListener('change', loadReservationHistory);
document.getElementById('historyDateTo')   ?.addEventListener('change', loadReservationHistory);
document.getElementById('historyStatus')   ?.addEventListener('change', loadReservationHistory);

function loadReservationHistory() {
    const search   = document.getElementById('historySearch')?.value   || '';
    const fromDate = document.getElementById('historyDateFrom')?.value || '';
    const toDate   = document.getElementById('historyDateTo')?.value   || '';
    const status   = document.getElementById('historyStatus')?.value   || '';
    const tbody    = document.getElementById('historyTableBody');

    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>';

    fetch(`api/reservations.php?action=history&search=${encodeURIComponent(search)}&from=${fromDate}&to=${toDate}&status=${status}`)
        .then(r => r.json())
        .then(data => {
            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No reservation history found</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(r => `
                <tr>
                    <td><span class="fw-bold">${escapeHtml(r.reservation_code)}</span></td>
                    <td><small>${new Date(r.created_at).toLocaleDateString()}</small></td>
                    <td><strong>${escapeHtml(r.user_name)}</strong><br>
                        <small class="text-muted">${escapeHtml(r.user_email || '')}</small></td>
                    <td>${escapeHtml(r.product_name)}</td>
                    <td class="text-center">${r.quantity}</td>
                    <td class="fw-bold text-success">₱${parseFloat(r.total_amount).toLocaleString()}</td>
                    <td>${getStatusBadge(r.status)}</td>
                </tr>
            `).join('');
        })
        .catch(err => {
            console.error('History error:', err);
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
        'pending':   '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed': '<span class="badge bg-info text-dark">Confirmed</span>',
        'completed': '<span class="badge bg-success">Completed</span>',
        'cancelled': '<span class="badge bg-danger">Cancelled</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
}
</script>