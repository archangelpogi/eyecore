<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Always resolve employee from DB — never trust session alone
$user_id = (int) $_SESSION['user_id'];

$employee_id        = 0;
$clinic_id          = 0;
$no_employee_record = false;

$stmt = $pdo->prepare("
    SELECT e.id AS employee_id, u.clinic_id
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.user_id = ?
    AND e.status = 'Active'
    LIMIT 1
");
$stmt->execute([$user_id]);
$emp_row = $stmt->fetch();

if ($emp_row) {
    $employee_id = (int) $emp_row['employee_id'];
    $clinic_id   = (int) $emp_row['clinic_id'];
} else {
    $stmt2 = $pdo->prepare("SELECT clinic_id, role FROM users WHERE id = ?");
    $stmt2->execute([$user_id]);
    $user_row = $stmt2->fetch();

    if (!$user_row) {
        session_destroy();
        header('Location: ../login.php');
        exit;
    }

    $clinic_id = (int) $user_row['clinic_id'];

    // Try inactive employees too (maybe status issue)
    $stmt3 = $pdo->prepare("SELECT id AS employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $stmt3->execute([$user_id]);
    $emp_any = $stmt3->fetch();

    if ($emp_any) {
        $employee_id = (int) $emp_any['employee_id'];
    } else {
        $no_employee_record = true;
    }
}

// Keep session in sync, then flush so other pages see updated values
$_SESSION['employee_id'] = $employee_id;
$_SESSION['clinic_id']   = $clinic_id;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$current_year = date('Y');

// Employee info
$employee = null;
if (!$no_employee_record && $employee_id > 0) {
    $stmt = $pdo->prepare("
        SELECT e.*, u.first_name, u.last_name, u.email, u.clinic_id
        FROM employees e
        JOIN users u ON e.user_id = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();
}

// Leave types
try {
    $stmt = $pdo->prepare("
        SELECT * FROM leave_types
        WHERE clinic_id = ? AND is_active = 1
        ORDER BY type_name
    ");
    $stmt->execute([$clinic_id]);
    $leave_types = $stmt->fetchAll();
} catch (Exception $e) {
    $leave_types = [];
}

// Leave balances
try {
    $stmt = $pdo->prepare("
        SELECT lt.type_name, lb.balance, lb.used, lb.total_entitled,
               lt.max_days_per_year, lt.with_pay
        FROM leave_balances lb
        JOIN leave_types lt ON lb.leave_type_id = lt.id
        WHERE lb.employee_id = ? AND lb.year = ? AND lt.clinic_id = ?
        ORDER BY lt.type_name
    ");
    $stmt->execute([$employee_id, $current_year, $clinic_id]);
    $leave_balances = $stmt->fetchAll();
} catch (Exception $e) {
    $leave_balances = [];
}

// Leave history — approved_by is user_id directly in leaves table
try {
    $stmt = $pdo->prepare("
        SELECT l.*,
               lt.type_name,
               lt.with_pay,
               CONCAT(u.first_name, ' ', u.last_name) AS approved_by_name
        FROM leaves l
        LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN users u ON l.approved_by = u.id
        WHERE l.employee_id = ? AND l.clinic_id = ?
        ORDER BY l.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$employee_id, $clinic_id]);
    $leave_history = $stmt->fetchAll();
} catch (Exception $e) {
    $leave_history = [];
}

// Pre-compute display fields once (used by both table + mobile cards)
$history_rows = [];
foreach ($leave_history as $l) {
    $withPay = (($l['with_pay'] ?? 0) == 1 || ($l['leave_with_pay'] ?? '') === 'with_pay');
    $statusClass = match($l['status']) {
        'Pending'   => 'badge-pending',
        'Approved'  => 'badge-approved',
        'Rejected'  => 'badge-rejected',
        'Cancelled' => 'badge-cancelled',
        default     => 'badge-cancelled',
    };
    $history_rows[] = [
        'id'     => (int) $l['id'],
        'filed'  => date('M d, Y', strtotime($l['created_at'])),
        'type'   => $l['type_name'] ?? $l['leave_type'] ?? '—',
        'range'  => date('M d', strtotime($l['start_date'])) . ' to ' . date('M d, Y', strtotime($l['end_date'])),
        'days'   => $l['number_of_days'],
        'pay'    => $withPay,
        'status' => $l['status'],
        'class'  => $statusClass,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Leave Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
    <style>
        :root {
            --teal: #0d9488;
            --teal-light: #14b8a6;
            --teal-bg: #f0fdfa;
        }

        html { -webkit-text-size-adjust: 100%; }
        body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; overflow-x: hidden; }

        /* Theme helpers */
        .text-teal { color: var(--teal) !important; }
        .bg-teal { background-color: var(--teal) !important; }
        .btn-teal { background-color: var(--teal); color: white; border: none; transition: 0.2s; }
        .btn-teal:hover, .btn-teal:focus { background-color: #0f766e; color: white; transform: translateY(-1px); }
        .btn-outline-teal { color: var(--teal); border-color: var(--teal); }
        .btn-outline-teal:hover { background-color: var(--teal); color: white; }
        .spinner-border.text-teal { color: var(--teal) !important; }

        /* Cards */
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { border-bottom: 1px solid #e2e8f0; border-radius: 12px 12px 0 0 !important; }

        /* Tables */
        .table-teal thead { background-color: var(--teal-bg); color: var(--teal); border-bottom: 2px solid #ccfbf1; }
        .table-teal th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; padding: 12px 16px; white-space: nowrap; }
        .table-teal td { padding: 16px; vertical-align: middle; font-size: 0.875rem; }

        /* Badges */
        .badge-pending   { background-color: #fef3c7; color: #d97706; border: 1px solid #fcd34d; }
        .badge-approved  { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-rejected  { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .badge-cancelled { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        /* Balance Cards */
        .balance-card { transition: transform 0.2s; border-radius: 12px; }
        .balance-card:hover { transform: translateY(-3px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .bal-bar { background: #e2e8f0; border-radius: 20px; height: 6px; margin-top: 8px; overflow: hidden; }
        .bal-fill { height: 6px; border-radius: 20px; background: var(--teal); }

        /* Mobile history cards */
        .leave-card { border-bottom: 1px solid #e2e8f0; padding: 14px 16px; }
        .leave-card:last-child { border-bottom: none; }

        /* ---------- Modal ---------- */
        .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-header { border-bottom: none; padding: 20px 24px 10px; }
        .modal-body { padding: 20px 24px; background-color: #f8fafc; }
        .modal-footer { border-top: 1px solid #e2e8f0; padding: 16px 24px; }

        /* FIX: form wraps body+footer, so make it a flex column so the body can scroll */
        #leaveForm {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }
        #leaveForm .modal-body {
            flex: 1 1 auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        }
        #leaveForm .modal-footer { flex-shrink: 0; }

        /* Visible custom scrollbar */
        #leaveForm .modal-body::-webkit-scrollbar,
        #leaveDetailModal .modal-body::-webkit-scrollbar { width: 8px; }
        #leaveForm .modal-body::-webkit-scrollbar-track,
        #leaveDetailModal .modal-body::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 8px; }
        #leaveForm .modal-body::-webkit-scrollbar-thumb,
        #leaveDetailModal .modal-body::-webkit-scrollbar-thumb { background: var(--teal-light); border-radius: 8px; }
        #leaveForm .modal-body, #leaveDetailModal .modal-body { scrollbar-width: thin; scrollbar-color: var(--teal-light) #e2e8f0; }

        /* Form inputs */
        .form-control, .form-select { border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 14px; }
        .form-control:focus, .form-select:focus { border-color: var(--teal-light); box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.1); }
        .form-label { font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .js-date { cursor: pointer; background-color: #fff !important; }

        /* ---------- Datepicker (Bootstrap 5 friendly) ---------- */
        .datepicker.datepicker-dropdown {
            z-index: 2000 !important;
            padding: 8px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            background: #fff;
            width: 290px;
            max-width: calc(100vw - 32px);
        }
        .datepicker table { width: 100%; }
        .datepicker table tr td, .datepicker table tr th { width: 14.28%; height: 38px; border-radius: 8px; font-size: 0.9rem; }
        .datepicker table tr td.active.active,
        .datepicker table tr td.active:hover.active { background: var(--teal) !important; border-color: var(--teal); color: #fff; text-shadow: none; }
        .datepicker table tr td.today { background: var(--teal-bg) !important; color: var(--teal); }
        .datepicker table tr td.disabled, .datepicker table tr td.disabled:hover { color: #cbd5e1; }
        .datepicker .datepicker-switch { font-weight: 600; }

        /* ---------- Mobile ---------- */
        @media (max-width: 575.98px) {
            .container { padding-left: 12px; padding-right: 12px; }
            h3 { font-size: 1.3rem; }
            .modal-header { padding: 16px 16px 8px; }
            .modal-body { padding: 16px; }
            .modal-footer { padding: 12px 16px; padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px)); }
            .modal-footer .btn { flex: 1; }
            .modal.modal-fullscreen-sm-down .modal-content { border-radius: 0; }
            .form-control, .form-select { font-size: 16px; } /* prevents iOS zoom on focus */
            .balance-card h2 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>
    <div class="container py-4 py-md-5">

        <?php if ($no_employee_record || !$employee): ?>
            <div class="row justify-content-center mt-5">
                <div class="col-12 col-md-6 text-center">
                    <div class="card shadow-sm border-0">
                        <div class="card-body py-5">
                            <i class="bi bi-person-x text-warning" style="font-size:3rem"></i>
                            <h5 class="mt-3 fw-bold">No Employee Record Found</h5>
                            <p class="text-muted">Your employee profile has not been set up yet.<br>
                            Please contact HR or your administrator.</p>
                            <a href="main.php?view=dashboard" class="btn btn-outline-primary mt-2">
                                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <!-- Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-3 mb-4">
                <div>
                    <h3 class="fw-bold mb-1 text-dark">My Leave Requests</h3>
                    <p class="text-muted mb-0 small">
                        <i class="bi bi-person-badge me-1"></i>
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                        &middot; <?= htmlspecialchars($employee['employee_no'] ?? '') ?>
                    </p>
                </div>
                <button class="btn btn-teal shadow-sm px-4 py-2" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                    <i class="bi bi-plus-lg me-2"></i> Apply for Leave
                </button>
            </div>

            <!-- Balances -->
            <div class="card mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-teal"><i class="bi bi-wallet2 me-2"></i>Leave Balances (<?= htmlspecialchars($current_year) ?>)</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($leave_balances)): ?>
                        <div class="alert alert-light border mb-0 text-muted">
                            <i class="bi bi-info-circle me-2"></i>Leave balances will be available after HR configuration.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($leave_balances as $b):
                                $pct = $b['total_entitled'] > 0 ? min(100, round(($b['used'] / $b['total_entitled']) * 100)) : 0;
                                $low = (float)$b['balance'] <= 2;
                            ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="card balance-card h-100 border-0 bg-light">
                                        <div class="card-body text-center p-3">
                                            <div class="text-muted small fw-semibold mb-1 text-uppercase text-truncate"><?= htmlspecialchars($b['type_name']) ?></div>
                                            <h2 class="fw-bold <?= $low ? 'text-danger' : 'text-teal' ?> mb-0">
                                                <?= number_format((float)$b['balance'], 1) ?>
                                            </h2>
                                            <small class="text-muted d-block mb-2">of <?= htmlspecialchars($b['total_entitled']) ?> days</small>
                                            <div class="bal-bar">
                                                <div class="bal-fill" style="width:<?= (int)$pct ?>%;<?= $low ? 'background:#ef4444' : '' ?>"></div>
                                            </div>
                                            <small class="text-muted mt-1 d-block" style="font-size: 0.7rem;"><?= htmlspecialchars($b['used']) ?> used</small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- History -->
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-teal"><i class="bi bi-clock-history me-2"></i>Leave History</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($history_rows)): ?>
                        <div class="text-center py-5 px-3 text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-3 opacity-25"></i>
                            <h6 class="fw-bold">No leave applications yet</h6>
                            <p class="small mb-0">Click "Apply for Leave" to submit your first request.</p>
                        </div>
                    <?php else: ?>

                        <!-- Desktop / tablet table -->
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover align-middle mb-0 table-teal">
                                <thead>
                                    <tr>
                                        <th>Filed Date</th>
                                        <th>Leave Type</th>
                                        <th>Date Range</th>
                                        <th>Days</th>
                                        <th>Pay Type</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_rows as $r): ?>
                                        <tr>
                                            <td class="text-muted"><?= $r['filed'] ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($r['type']) ?></td>
                                            <td class="text-nowrap"><?= $r['range'] ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['days']) ?> days</span></td>
                                            <td>
                                                <span class="badge <?= $r['pay'] ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-danger bg-opacity-10 text-danger border border-danger' ?>">
                                                    <?= $r['pay'] ? 'With Pay' : 'No Pay' ?>
                                                </span>
                                            </td>
                                            <td><span class="badge <?= $r['class'] ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-secondary border-0" onclick="viewLeave(<?= $r['id'] ?>)" title="View Details">
                                                        <i class="bi bi-eye text-teal"></i>
                                                    </button>
                                                    <?php if ($r['status'] === 'Pending'): ?>
                                                        <button class="btn btn-outline-secondary border-0" onclick="cancelLeave(<?= $r['id'] ?>)" title="Cancel Request">
                                                            <i class="bi bi-x-circle text-danger"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile cards -->
                        <div class="d-md-none">
                            <?php foreach ($history_rows as $r): ?>
                                <div class="leave-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                        <div class="fw-semibold"><?= htmlspecialchars($r['type']) ?></div>
                                        <span class="badge <?= $r['class'] ?>"><?= htmlspecialchars($r['status']) ?></span>
                                    </div>
                                    <div class="small mb-1"><i class="bi bi-calendar-event text-teal me-1"></i><?= $r['range'] ?></div>
                                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($r['days']) ?> days</span>
                                        <span class="badge <?= $r['pay'] ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-danger bg-opacity-10 text-danger border border-danger' ?>">
                                            <?= $r['pay'] ? 'With Pay' : 'No Pay' ?>
                                        </span>
                                        <small class="text-muted ms-auto">Filed <?= $r['filed'] ?></small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-teal flex-fill" onclick="viewLeave(<?= $r['id'] ?>)">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
                                        <?php if ($r['status'] === 'Pending'): ?>
                                            <button class="btn btn-sm btn-outline-danger flex-fill" onclick="cancelLeave(<?= $r['id'] ?>)">
                                                <i class="bi bi-x-circle me-1"></i>Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

            <!-- MODAL: Apply Leave -->
            <div class="modal fade" id="applyLeaveModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
                    <div class="modal-content">

                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-calendar-plus text-teal me-2"></i>Apply for Leave</h5>
                                <p class="text-muted small mb-0 mt-1">Fill out the details below to submit your request.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <form id="leaveForm" enctype="multipart/form-data" novalidate>
                            <div class="modal-body">
                                <div class="row g-4">

                                    <!-- Left: fields -->
                                    <div class="col-12 col-lg-7">

                                        <div class="mb-4">
                                            <label class="form-label" for="leave_type">Leave Type <span class="text-danger">*</span></label>
                                            <select class="form-select" id="leave_type" name="leave_type_id" required>
                                                <option value="">Select a leave type...</option>
                                                <?php foreach ($leave_types as $t): ?>
                                                    <option value="<?= (int)$t['id'] ?>"
                                                            data-name="<?= htmlspecialchars($t['type_name']) ?>"
                                                            data-with-pay="<?= (int)$t['with_pay'] ?>"
                                                            data-requires-attachment="<?= (int)($t['requires_attachment'] ?? 0) ?>">
                                                        <?= htmlspecialchars($t['type_name']) ?> (<?= $t['with_pay'] ? 'With Pay' : 'Without Pay' ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div id="payClass" class="mt-2 text-muted small">
                                                <i class="bi bi-info-circle me-1"></i> Select a leave type to see pay classification.
                                            </div>
                                        </div>

                                        <div class="row g-3 mb-4">
                                            <div class="col-6">
                                                <label class="form-label" for="start_date">Start Date <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control js-date" id="start_date" name="start_date"
                                                       placeholder="Select date" inputmode="none" autocomplete="off" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label" for="end_date">End Date <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control js-date" id="end_date" name="end_date"
                                                       placeholder="Select date" inputmode="none" autocomplete="off" required>
                                            </div>
                                        </div>

                                        <div class="row g-2 mb-4 p-3 bg-white border rounded-3 mx-0 shadow-sm text-center">
                                            <div class="col-4 border-end">
                                                <label class="form-label text-muted small mb-1">Total Days</label>
                                                <div class="fw-bold fs-5" id="num_days">0</div>
                                            </div>
                                            <div class="col-4 border-end">
                                                <label class="form-label text-muted small mb-1">Working Days</label>
                                                <div class="fw-bold fs-5 text-teal" id="work_days">0</div>
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label text-muted small mb-1">Your Balance</label>
                                                <div class="fw-bold fs-5" id="bal_display">—</div>
                                            </div>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label" for="reason">Reason for Leave <span class="text-danger">*</span></label>
                                            <textarea class="form-control bg-light" id="reason" name="reason" rows="3" placeholder="Briefly explain why you need this leave..." required></textarea>
                                        </div>

                                        <div class="mb-4" id="attachSection" style="display:none">
                                            <label class="form-label" for="attachment">Supporting Document <span class="text-danger">*</span></label>
                                            <div class="alert alert-warning py-2 small mb-2 border-0 bg-warning bg-opacity-10 text-warning-emphasis">
                                                <i class="bi bi-exclamation-triangle me-1"></i> Required for this leave type (e.g., Medical Certificate).
                                            </div>
                                            <input type="file" class="form-control bg-light" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                        </div>

                                        <div class="row g-3 mb-4">
                                            <div class="col-12 col-sm-6">
                                                <label class="form-label text-muted">Emergency Contact <span class="text-muted fw-normal">(Optional)</span></label>
                                                <input type="text" class="form-control bg-light" name="emergency_contact" placeholder="Full name">
                                            </div>
                                            <div class="col-12 col-sm-6">
                                                <label class="form-label text-muted">Contact Number <span class="text-muted fw-normal">(Optional)</span></label>
                                                <input type="tel" class="form-control bg-light" name="contact_number" placeholder="09XX XXX XXXX">
                                            </div>
                                        </div>

                                        <div class="form-check bg-white p-3 border rounded-3 shadow-sm d-flex align-items-start">
                                            <input class="form-check-input ms-0 me-2 mt-1 flex-shrink-0" type="checkbox" id="terms" required>
                                            <label class="form-check-label small text-muted" for="terms">
                                                I certify that the information provided is accurate. I understand that filing false claims may lead to disciplinary action, and unpaid leaves will incur salary deductions.
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Right: calendar preview (desktop only) -->
                                    <div class="col-12 col-lg-5 d-none d-lg-block">
                                        <div class="card h-100 border-0 bg-white shadow-sm">
                                            <div class="card-header bg-transparent border-0 pt-3 pb-0">
                                                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar3 text-teal me-2"></i>Preview</h6>
                                            </div>
                                            <div class="card-body d-flex flex-column">
                                                <div id="calendarPreview" class="flex-grow-1 mb-3"></div>

                                                <div class="mt-auto pt-3 border-top">
                                                    <div class="d-flex justify-content-between small mb-2">
                                                        <span class="text-muted">Selected Dates:</span>
                                                        <span id="previewDateRange" class="fw-semibold text-dark text-end">—</span>
                                                    </div>
                                                    <div class="d-flex justify-content-between small mb-2">
                                                        <span class="text-muted">Total Calendar Days:</span>
                                                        <span id="previewTotalDays" class="fw-semibold text-dark">0</span>
                                                    </div>
                                                    <div class="d-flex justify-content-between small">
                                                        <span class="text-muted">Working Days (Deducted):</span>
                                                        <span id="previewWorkingDays" class="fw-bold text-teal fs-6">0</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <input type="hidden" name="leave_with_pay" id="leave_with_pay" value="with_pay">
                                <input type="hidden" name="calculated_days" id="calc_days" value="0">
                            </div>

                            <div class="modal-footer bg-white">
                                <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-teal px-4 shadow-sm">
                                    <i class="bi bi-send me-2"></i> Submit Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- MODAL: Leave Details -->
            <div class="modal fade" id="leaveDetailModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <div class="modal-header bg-teal text-white">
                            <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Leave Details</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body bg-white" id="leaveDetailBody">
                            <div class="text-center py-5"><div class="spinner-border text-teal"></div></div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div><!-- /container -->

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script>
    // ---------- Data from PHP ----------
    const leaveBalances = <?= json_encode(array_column($leave_balances, 'balance', 'type_name')) ?>;

    // Earliest allowed start date. '0d' = today, '+1d' = tomorrow.
    const MIN_START = '0d';

    // ---------- Helpers ----------
    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    // Parse "yyyy-mm-dd" as LOCAL date (avoids timezone off-by-one)
    function parseYMD(str) {
        if (!str) return null;
        const p = str.split('-');
        if (p.length !== 3) return null;
        return new Date(+p[0], +p[1] - 1, +p[2]);
    }
    function toYMD(d) {
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }
    function fmtDate(d) {
        if (!d) return '—';
        const dt = /^\d{4}-\d{2}-\d{2}$/.test(d) ? parseYMD(d) : new Date(String(d).replace(' ', 'T'));
        if (isNaN(dt)) return '—';
        return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    let calendarPreview = null;

    // ---------- Calculation + preview ----------
    function calcDays() {
        const s = parseYMD($('#start_date').val());
        const e = parseYMD($('#end_date').val());

        if (!s || !e || e < s) {
            $('#num_days, #work_days, #previewTotalDays, #previewWorkingDays').text('0');
            $('#calc_days').val(0);
            $('#previewDateRange').text('—');
            $('#bal_display').text('—').removeClass('text-danger');
            if (calendarPreview) calendarPreview.removeAllEvents();
            return;
        }

        const total = Math.round((e - s) / 86400000) + 1;
        let working = 0;
        const cur = new Date(s);
        while (cur <= e) {
            const d = cur.getDay();
            if (d !== 0 && d !== 6) working++;
            cur.setDate(cur.getDate() + 1);
        }

        $('#num_days, #previewTotalDays').text(total);
        $('#work_days, #previewWorkingDays').text(working);
        $('#calc_days').val(working);

        $('#previewDateRange').text(
            s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' - ' +
            e.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        );

        // Balance
        const typeName = $('#leave_type option:selected').data('name');
        if (typeName && leaveBalances[typeName] !== undefined) {
            const bal = parseFloat(leaveBalances[typeName]);
            $('#bal_display').text(bal + ' days').toggleClass('text-danger', bal < working);
        } else {
            $('#bal_display').text('—').removeClass('text-danger');
        }

        // Calendar preview
        if (calendarPreview) {
            calendarPreview.removeAllEvents();
            const endExclusive = new Date(e);
            endExclusive.setDate(endExclusive.getDate() + 1);
            calendarPreview.addEvent({
                title: 'Leave',
                start: toYMD(s),
                end: toYMD(endExclusive),
                display: 'background',
                backgroundColor: '#0d9488'
            });
            calendarPreview.gotoDate(toYMD(s));
        }
    }

    function initCalendarPreview() {
        const el = document.getElementById('calendarPreview');
        if (!el || el.offsetParent === null) return; // hidden on mobile
        if (!calendarPreview) {
            calendarPreview = new FullCalendar.Calendar(el, {
                initialView: 'dayGridMonth',
                headerToolbar: { left: 'prev,next', center: 'title', right: '' },
                height: 'auto',
                contentHeight: 250,
                dayMaxEvents: true
            });
            calendarPreview.render();
        } else {
            calendarPreview.updateSize();
        }
        calcDays();
    }

    // ---------- Init ----------
    $(function () {
        const $start = $('#start_date');
        const $end   = $('#end_date');

        // FIX: container INSIDE the modal body so the popup is not hidden behind the modal
        // and positions correctly while the body scrolls.
        $('.js-date').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true,
            startDate: MIN_START,
            container: '#applyLeaveModal .modal-body',
            zIndexOffset: 2000,
            orientation: 'bottom auto',
            disableTouchKeyboard: true,
            language: 'en'
        });

        // Link start -> end
        $start.on('changeDate', function () {
            const s = parseYMD($start.val());
            if (s) {
                $end.datepicker('setStartDate', s);
                const e = parseYMD($end.val());
                if (e && e < s) $end.datepicker('clearDates');
            }
            calcDays();
        });
        $end.on('changeDate clearDate', calcDays);
        $start.on('clearDate', function () {
            $end.datepicker('setStartDate', MIN_START);
            calcDays();
        });

        // Leave type change
        $('#leave_type').on('change', function () {
            const opt = $(this).find('option:selected');
            const withPay = opt.data('with-pay') == 1;
            const needsAttach = opt.data('requires-attachment') == 1;

            if (!opt.val()) {
                $('#payClass').html('<i class="bi bi-info-circle me-1"></i> Select a leave type to see pay classification.');
            } else {
                $('#payClass').html(
                    withPay
                    ? '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle me-1"></i>With Pay</span>'
                    : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle me-1"></i>Without Pay</span> <small class="text-danger ms-1">Salary deduction applies</small>'
                );
            }
            $('#leave_with_pay').val(withPay ? 'with_pay' : 'without_pay');

            if (needsAttach) {
                $('#attachSection').slideDown();
                $('#attachment').prop('required', true);
            } else {
                $('#attachSection').slideUp();
                $('#attachment').prop('required', false).val('');
            }
            calcDays();
        });

        // Modal events
        const applyModalEl = document.getElementById('applyLeaveModal');
        if (applyModalEl) {
            applyModalEl.addEventListener('shown.bs.modal', initCalendarPreview);
            applyModalEl.addEventListener('hidden.bs.modal', function () {
                document.getElementById('leaveForm').reset();
                $('.js-date').datepicker('clearDates');
                $end.datepicker('setStartDate', MIN_START);
                $('#attachSection').hide();
                $('#payClass').html('<i class="bi bi-info-circle me-1"></i> Select a leave type to see pay classification.');
                calcDays();
            });
        }
        $(window).on('resize', function () { if (calendarPreview) calendarPreview.updateSize(); });

        // Submit
        $('#leaveForm').on('submit', function (e) {
            e.preventDefault();

            const start = $start.val();
            const end   = $end.val();
            const days  = parseInt($('#calc_days').val()) || 0;

            if (!$('#leave_type').val()) return Swal.fire('Error', 'Please select a leave type.', 'error');
            if (!start || !end)          return Swal.fire('Error', 'Please select start and end dates.', 'error');
            if (parseYMD(end) < parseYMD(start)) return Swal.fire('Error', 'End date must be after start date.', 'error');
            if (days <= 0)               return Swal.fire('Error', 'Selected dates have no working days.', 'error');
            if (!$('#reason').val().trim()) return Swal.fire('Error', 'Please enter a reason for your leave.', 'error');
            if ($('#attachment').prop('required') && !$('#attachment').val())
                return Swal.fire('Error', 'Please attach the required supporting document.', 'error');
            if (!$('#terms').is(':checked')) return Swal.fire('Error', 'Please accept the certification.', 'error');

            Swal.fire({ title: 'Submitting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            fetch('api/leave_apply.php', { method: 'POST', body: new FormData(this) })
                .then(r => r.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Application Submitted!',
                            html: `<p>${esc(data.message)}</p>
                                   <small class="text-muted">Reference: <strong>${esc(data.reference)}</strong></small>`,
                        }).then(() => {
                            bootstrap.Modal.getOrCreateInstance(applyModalEl).hide();
                            location.reload();
                        });
                    } else {
                        Swal.fire('Failed', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
        });
    });

    // ---------- View / Cancel ----------
    function viewLeave(id) {
        $('#leaveDetailBody').html('<div class="text-center py-5"><div class="spinner-border text-teal"></div></div>');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('leaveDetailModal')).show();

        fetch('api/leave_details.php?id=' + encodeURIComponent(id))
            .then(r => r.json())
            .then(data => {
                if (data.error) { $('#leaveDetailBody').html('<div class="alert alert-danger">' + esc(data.error) + '</div>'); return; }

                const withPay = data.with_pay == 1 || data.leave_with_pay === 'with_pay';
                const statusMap = { Pending:'warning', Approved:'success', Rejected:'danger', Cancelled:'secondary' };
                const sc = statusMap[data.status] || 'secondary';

                let html = `
                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <h6 class="text-teal fw-bold text-uppercase small mb-3 border-bottom pb-2">Leave Information</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted fw-normal">Leave type</th><td class="fw-semibold">${esc(data.type_name || data.leave_type || '—')}</td></tr>
                            <tr><th class="text-muted fw-normal">Start date</th><td>${fmtDate(data.start_date)}</td></tr>
                            <tr><th class="text-muted fw-normal">End date</th><td>${fmtDate(data.end_date)}</td></tr>
                            <tr><th class="text-muted fw-normal">Duration</th><td>${esc(data.number_of_days)} day(s)</td></tr>
                            <tr><th class="text-muted fw-normal">Pay status</th>
                                <td><span class="badge ${withPay ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-danger bg-opacity-10 text-danger border border-danger'}">${withPay ? 'With Pay' : 'Without Pay'}</span></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-12 col-md-6">
                        <h6 class="text-teal fw-bold text-uppercase small mb-3 border-bottom pb-2">Application Details</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted fw-normal">Status</th>
                                <td><span class="badge bg-${sc}">${esc(data.status)}</span></td>
                            </tr>
                            <tr><th class="text-muted fw-normal">Filed on</th><td>${fmtDate(data.created_at)}</td></tr>
                            ${data.approved_by_name ? `
                            <tr><th class="text-muted fw-normal">Actioned by</th><td>${esc(data.approved_by_name)}</td></tr>
                            <tr><th class="text-muted fw-normal">Actioned on</th><td>${fmtDate(data.approved_at)}</td></tr>` : ''}
                            ${data.rejection_notes ? `
                            <tr><th class="text-muted fw-normal text-danger">Rejection reason</th>
                                <td class="text-danger fw-semibold">${esc(data.rejection_notes)}</td>
                            </tr>` : ''}
                        </table>
                    </div>
                </div>
                <hr class="my-3">
                <h6 class="text-teal fw-bold text-uppercase small mb-2">Reason</h6>
                <p class="bg-light border rounded p-3 mb-0 text-dark" style="word-break:break-word">${esc(data.reason || '—')}</p>`;

                if (data.emergency_contact || data.contact_number) {
                    html += `<hr class="my-3">
                    <h6 class="text-teal fw-bold text-uppercase small mb-2">Emergency Contact</h6>
                    <p class="mb-0 text-dark"><i class="bi bi-person me-1"></i> ${esc(data.emergency_contact || '—')} &nbsp;|&nbsp; <i class="bi bi-telephone me-1"></i> ${esc(data.contact_number || '—')}</p>`;
                }
                if (data.attachment) {
                    html += `<hr class="my-3">
                    <a href="${esc(data.attachment)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-teal">
                        <i class="bi bi-paperclip me-1"></i>View Attachment
                    </a>`;
                }

                $('#leaveDetailBody').html(html);
            })
            .catch(() => $('#leaveDetailBody').html('<div class="alert alert-danger">Failed to load details.</div>'));
    }

    function cancelLeave(id) {
        Swal.fire({
            title: 'Cancel leave application?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d9488',
            confirmButtonText: 'Yes, cancel it'
        }).then(r => {
            if (!r.isConfirmed) return;
            fetch('api/leave_cancel.php?id=' + encodeURIComponent(id), { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Cancelled', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error', 'error'));
        });
    }
    </script>
</body>
</html>