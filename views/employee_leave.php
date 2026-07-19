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
// Prevents race condition on first page load
$user_id = (int) $_SESSION['user_id'];

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

if (!$emp_row) {
    // No active employee record — check if user exists
    $stmt2 = $pdo->prepare("SELECT clinic_id, role FROM users WHERE id = ?");
    $stmt2->execute([$user_id]);
    $user_row = $stmt2->fetch();

    if (!$user_row) {
        // User gone entirely — re-login
        session_destroy();
        header('Location: ../login.php');
        exit;
    }

    // User exists but no employee record
    // Try inactive employees too (maybe status issue)
    $stmt3 = $pdo->prepare("SELECT id AS employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $stmt3->execute([$user_id]);
    $emp_any = $stmt3->fetch();

    if ($emp_any) {
        // Found but inactive — use it anyway for leave viewing
        $emp_row = ['employee_id' => $emp_any['employee_id'], 'clinic_id' => $user_row['clinic_id']];
    } else {
        // Truly no employee record — show friendly error inline, not die()
        $_SESSION['clinic_id'] = $user_row['clinic_id'];
    }

    $no_employee_record = true;
} else {
    // Keep session in sync with DB result
    $_SESSION['employee_id'] = $emp_row['employee_id'];
    $_SESSION['clinic_id']   = $emp_row['clinic_id'];
    $no_employee_record = false;
}

// Flush session writes immediately so other pages see updated values
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();

}

$employee_id = (int) ($_SESSION['employee_id'] ?? 0);
$clinic_id   = (int) ($_SESSION['clinic_id']   ?? 0);
$current_year = date('Y');

// Employee info
if ($no_employee_record || $employee_id === 0) {
    // No employee record — show a proper page instead of dying
    $employee = null;
} else {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Leave Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
    <style>
        /* Minimal custom CSS - only teal accent colors */
        .bg-teal { background-color: #14b8a6 !important; }
        .text-teal { color: #14b8a6 !important; }
        .btn-teal { background-color: #14b8a6; color: white; }
        .btn-teal:hover { background-color: #0d9488; color: white; }
        .border-teal { border-color: #14b8a6 !important; }
        .table-teal thead { background-color: #14b8a6; color: white; }
        .badge-pending { background-color: #ffc107; color: #000; }
        .badge-approved { background-color: #198754; color: #fff; }
        .badge-rejected { background-color: #dc3545; color: #fff; }
        .badge-cancelled { background-color: #6c757d; color: #fff; }
        .badge-withpay { background-color: #198754; color: #fff; }
        .badge-nopay { background-color: #dc3545; color: #fff; }
        .balance-card { transition: transform 0.2s; cursor: default; }
        .balance-card:hover { transform: translateY(-3px); }
        .bal-bar { background: #e9ecef; border-radius: 20px; height: 6px; margin-top: 6px; overflow: hidden; }
        .bal-fill { height: 6px; border-radius: 20px; background: #14b8a6; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-3 py-md-4">

        <?php if ($no_employee_record || !$employee): ?>
        <!-- No employee record — friendly message, not a hard crash -->
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 text-center">
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

        <!-- Header Section - Mobile Responsive -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-0 text-teal">My Leave Requests</h4>
                <small class="text-muted"><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?> &middot; <?= htmlspecialchars($employee['employee_no'] ?? '') ?></small>
            </div>
            <button class="btn btn-teal  w-sm-auto" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                <i class="bi bi-plus-circle me-1"></i> Apply for Leave
            </button>
        </div>

        <!-- Leave Balances Section -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-teal text-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-piggy-bank me-2"></i>Leave Balances (<?= $current_year ?>)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($leave_balances)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Leave balances will be available after HR configuration.
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($leave_balances as $b):
                            $pct = $b['total_entitled'] > 0
                                ? min(100, round(($b['used'] / $b['total_entitled']) * 100))
                                : 0;
                            $low = (float)$b['balance'] <= 2;
                        ?>
                            <div class="col-6 col-md-3">
                                <div class="card balance-card h-100 border-0 shadow-sm">
                                    <div class="card-body text-center p-3">
                                        <div class="text-muted small mb-1"><?= htmlspecialchars($b['type_name']) ?></div>
                                        <h3 class="fw-bold <?= $low ? 'text-danger' : 'text-teal' ?> mb-0">
                                            <?= number_format((float)$b['balance'], 1) ?>
                                        </h3>
                                        <small class="text-muted">of <?= $b['total_entitled'] ?> days</small>
                                        <div class="bal-bar">
                                            <div class="bal-fill" style="width:<?= $pct ?>%;<?= $low ? 'background:#dc3545' : '' ?>"></div>
                                        </div>
                                        <small class="text-muted"><?= $b['used'] ?> used</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leave History Section - Mobile Responsive Table -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2 text-teal"></i>Leave History</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($leave_history)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i>
                        No leave applications yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-teal">
                                <tr>
                                    <th class="text-white">Filed</th>
                                    <th class="text-white">Leave Type</th>
                                    <th class="text-white">Date Range</th>
                                    <th class="text-white">Days</th>
                                    <th class="text-white d-none d-md-table-cell">Pay</th>
                                    <th class="text-white">Status</th>
                                    <th class="text-white">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_history as $l):
                                    $withPay = ($l['with_pay'] == 1 || $l['leave_with_pay'] === 'with_pay');
                                    $statusClass = match($l['status']) {
                                        'Pending'   => 'badge-pending',
                                        'Approved'  => 'badge-approved',
                                        'Rejected'  => 'badge-rejected',
                                        'Cancelled' => 'badge-cancelled',
                                        default     => 'badge-cancelled',
                                    };
                                ?>
                                    <tr>
                                        <td><small><?= date('M d, Y', strtotime($l['created_at'])) ?></small></td>
                                        <td><?= htmlspecialchars($l['type_name'] ?? $l['leave_type'] ?? '—') ?></td>
                                        <td>
                                            <small>
                                                <?= date('M d', strtotime($l['start_date'])) ?>
                                                – <?= date('M d, Y', strtotime($l['end_date'])) ?>
                                            </small>
                                        </td>
                                        <td><strong><?= $l['number_of_days'] ?></strong></td>
                                        <td class="d-none d-md-table-cell">
                                            <span class="badge <?= $withPay ? 'badge-withpay' : 'badge-nopay' ?>">
                                                <?= $withPay ? 'With Pay' : 'No Pay' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $statusClass ?>"><?= $l['status'] ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-teal btn-sm"
                                                        onclick="viewLeave(<?= $l['id'] ?>)" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($l['status'] === 'Pending'): ?>
                                                    <button class="btn btn-outline-danger btn-sm"
                                                            onclick="cancelLeave(<?= $l['id'] ?>)" title="Cancel">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- end container -->

    <?php endif; // end no_employee_record check ?>

<!-- MODAL: Apply Leave - Mobile Responsive (SCROLLABLE) -->
<div class="modal fade" id="applyLeaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="max-height: 90vh;">
            <div class="modal-header bg-teal text-white">
                <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Apply for Leave</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="leaveForm" enctype="multipart/form-data">
                <!-- MODAL BODY WITH SCROLL -->
                <div class="modal-body" style="max-height: calc(90vh - 130px); overflow-y: auto;">
                    <div class="row g-3">
                        <!-- Left Column -->
                        <div class="col-12 col-lg-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-tag text-teal me-1"></i>Leave Type <span class="text-danger">*</span>
                                </label>
                                <select class="form-select border-teal" id="leave_type" name="leave_type_id" required>
                                    <option value="">Select leave type...</option>
                                    <?php foreach ($leave_types as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>"
                                                data-with-pay="<?= (int)$t['with_pay'] ?>"
                                                data-requires-attachment="<?= (int)$t['requires_attachment'] ?>">
                                            <?= htmlspecialchars($t['type_name']) ?>
                                            (<?= $t['with_pay'] ? 'With Pay' : 'Without Pay' ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-info-circle text-teal me-1"></i>Classification
                                </label>
                                <div id="payClass" class="form-control bg-light border-teal">
                                    <span class="text-muted">Select leave type first</span>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar text-teal me-1"></i>Start Date <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control datepicker border-teal" id="start_date" name="start_date" required autocomplete="off">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar text-teal me-1"></i>End Date <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control datepicker border-teal" id="end_date" name="end_date" required autocomplete="off">
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <label class="form-label fw-semibold text-teal">Total Days</label>
                                    <input type="text" class="form-control bg-light" id="num_days" readonly>
                                </div>
                                <div class="col-4">
                                    <label class="form-label fw-semibold text-teal">Working Days</label>
                                    <input type="text" class="form-control bg-light" id="work_days" readonly>
                                </div>
                                <div class="col-4">
                                    <label class="form-label fw-semibold text-teal">Balance</label>
                                    <input type="text" class="form-control bg-light" id="bal_display" readonly>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-file-text text-teal me-1"></i>Reason <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control border-teal" id="reason" name="reason" rows="3"
                                          placeholder="Please provide a reason for your leave..." required></textarea>
                            </div>
                            
                            <div class="mb-3" id="attachSection" style="display:none">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-paperclip text-teal me-1"></i>Attachment <span class="text-danger">*</span>
                                </label>
                                <div class="alert alert-info py-2 small mb-2">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Supporting document required for this leave type (e.g., Medical Certificate)
                                </div>
                                <input type="file" class="form-control border-teal" id="attachment" name="attachment"
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small class="text-muted">Max 5MB — PDF, JPG, PNG, DOC allowed</small>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-person text-teal me-1"></i>Emergency Contact
                                    </label>
                                    <input type="text" class="form-control border-teal" name="emergency_contact" placeholder="Contact person name">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-telephone text-teal me-1"></i>Contact Number
                                    </label>
                                    <input type="tel" class="form-control border-teal" name="contact_number" placeholder="Phone number">
                                </div>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="terms" required>
                                <label class="form-check-label small" for="terms">
                                    I understand that leave applications must be submitted in advance, falsification of reasons
                                    may lead to disciplinary action, and without-pay leaves will have salary deductions.
                                </label>
                            </div>
                        </div>
                        
                        <!-- Right Column - Calendar Preview (Desktop only, hidden on mobile) -->
                        <div class="col-12 col-lg-6 d-none d-lg-block">
                            <div class="card border-0 bg-light">
                                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                                    <h6 class="mb-0 text-teal fw-semibold">
                                        <i class="bi bi-calendar-week me-2"></i>Leave Period Preview
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div id="calendarPreview" style="min-height: 250px;"></div>
                                    
                                    <div class="mt-3 pt-2 border-top">
                                        <div class="d-flex justify-content-between small mb-2">
                                            <span class="text-muted">Selected Period:</span>
                                            <span id="previewDateRange" class="fw-semibold text-teal">—</span>
                                        </div>
                                        <div class="d-flex justify-content-between small mb-2">
                                            <span class="text-muted">Total Days:</span>
                                            <span id="previewTotalDays" class="fw-semibold">0</span>
                                        </div>
                                        <div class="d-flex justify-content-between small">
                                            <span class="text-muted">Working Days:</span>
                                            <span id="previewWorkingDays" class="fw-semibold">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="leave_with_pay" id="leave_with_pay" value="with_pay">
                    <input type="hidden" name="calculated_days" id="calc_days" value="0">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal">
                        <i class="bi bi-send me-1"></i> Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
    <!-- MODAL: Leave Details -->
    <div class="modal fade" id="leaveDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-teal text-white">
                    <h5 class="modal-title">Leave Application Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="leaveDetailBody">
                    <div class="text-center py-4"><div class="spinner-border text-teal"></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script>
    // Leave balances from PHP for JS validation
    const leaveBalances = <?= json_encode(
        array_column($leave_balances, 'balance', 'type_name')
    ) ?>;

    $(function () {
        // Init datepicker
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true,
            startDate: '+1d'
        });

        $('#start_date, #end_date').on('change', calcDays);

        // Leave type change
        $('#leave_type').on('change', function () {
            const opt = $(this).find('option:selected');
            const withPay = opt.data('with-pay') == 1;
            const needsAttach = opt.data('requires-attachment') == 1;

            $('#payClass').html(
                withPay
                ? '<span class="badge bg-success">With Pay</span>'
                : '<span class="badge bg-danger">Without Pay</span> <small class="text-danger ms-1">Salary deduction applies</small>'
            );
            $('#leave_with_pay').val(withPay ? 'with_pay' : 'without_pay');

            if (needsAttach) {
                $('#attachSection').show();
                $('#attachment').prop('required', true);
            } else {
                $('#attachSection').hide();
                $('#attachment').prop('required', false).val('');
            }

            calcDays();
        });

        // Form submit
        $('#leaveForm').on('submit', function (e) {
            e.preventDefault();

            const start = $('#start_date').val();
            const end   = $('#end_date').val();
            const days  = parseInt($('#calc_days').val());

            if (!start || !end) {
                return Swal.fire('Error', 'Please select start and end dates.', 'error');
            }
            if (new Date(end) < new Date(start)) {
                return Swal.fire('Error', 'End date must be after start date.', 'error');
            }
            if (days <= 0) {
                return Swal.fire('Error', 'Please select valid dates.', 'error');
            }

            Swal.fire({ title: 'Submitting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            fetch('api/leave_apply.php', { method: 'POST', body: new FormData(this) })
                .then(r => r.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Application Submitted!',
                            html: `<p>${data.message}</p>
                                   <small class="text-muted">Reference: <strong>${data.reference}</strong></small>`,
                        }).then(() => { $('#applyLeaveModal').modal('hide'); location.reload(); });
                    } else {
                        Swal.fire('Failed', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
        });
    });

    function calcDays() {
        const start = $('#start_date').val();
        const end   = $('#end_date').val();
        if (!start || !end) return;

        const s = new Date(start), e = new Date(end);
        if (e < s) { $('#num_days,#work_days,#calc_days').val(''); return; }

        const total = Math.ceil((e - s) / 86400000) + 1;
        let working = 0;
        const cur = new Date(s);
        while (cur <= e) {
            const d = cur.getDay();
            if (d !== 0 && d !== 6) working++;
            cur.setDate(cur.getDate() + 1);
        }

        $('#num_days').val(total);
        $('#work_days').val(working);
        $('#calc_days').val(working);

        // Show balance if leave type selected
        const typeName = $('#leave_type option:selected').text().split('(')[0].trim();
        if (typeName && leaveBalances[typeName] !== undefined) {
            const bal = parseFloat(leaveBalances[typeName]);
            $('#bal_display').val(bal + ' days').toggleClass('text-danger', bal < working);
        } else {
            $('#bal_display').val('—');
        }
    }

    function viewLeave(id) {
        $('#leaveDetailBody').html('<div class="text-center py-4"><div class="spinner-border text-teal"></div></div>');
        new bootstrap.Modal(document.getElementById('leaveDetailModal')).show();

        fetch('api/leave_details.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.error) { $('#leaveDetailBody').html('<div class="alert alert-danger">' + data.error + '</div>'); return; }

                const withPay = data.with_pay == 1 || data.leave_with_pay === 'with_pay';
                const statusMap = { Pending:'warning', Approved:'success', Rejected:'danger', Cancelled:'secondary' };
                const sc = statusMap[data.status] || 'secondary';

                let html = `
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <h6 class="text-muted text-uppercase small mb-2">Leave Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr><th class="text-muted fw-normal w-40">Leave type</th><td>${data.type_name || data.leave_type || '—'}</td></tr>
                            <tr><th class="text-muted fw-normal">Start date</th><td>${fmtDate(data.start_date)}</td></tr>
                            <tr><th class="text-muted fw-normal">End date</th><td>${fmtDate(data.end_date)}</td></tr>
                            <tr><th class="text-muted fw-normal">Duration</th><td>${data.number_of_days} day(s)</td></tr>
                            <tr><th class="text-muted fw-normal">Pay status</th>
                                <td><span class="badge ${withPay ? 'bg-success' : 'bg-danger'}">${withPay ? 'With Pay' : 'Without Pay'}</span></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-12 col-md-6">
                        <h6 class="text-muted text-uppercase small mb-2">Application Details</h6>
                        <table class="table table-sm table-borderless">
                            <tr><th class="text-muted fw-normal w-40">Status</th>
                                <td><span class="badge bg-${sc}">${data.status}</span></td>
                            </tr>
                            <tr><th class="text-muted fw-normal">Filed on</th><td>${fmtDate(data.created_at)}</td></tr>
                            ${data.approved_by_name ? `
                            <tr><th class="text-muted fw-normal">Actioned by</th><td>${data.approved_by_name}</td></tr>
                            <tr><th class="text-muted fw-normal">Actioned on</th><td>${fmtDate(data.approved_at)}</td></tr>` : ''}
                            ${data.rejection_notes ? `
                            <tr><th class="text-muted fw-normal text-danger">Rejection reason</th>
                                <td class="text-danger">${data.rejection_notes}</td>
                            </tr>` : ''}
                        </table>
                    </div>
                </div>
                <hr>
                <h6 class="text-muted text-uppercase small mb-2">Reason</h6>
                <p class="bg-light rounded p-3 mb-0">${data.reason || '—'}</p>`;

                if (data.emergency_contact || data.contact_number) {
                    html += `<hr>
                    <h6 class="text-muted text-uppercase small mb-2">Emergency Contact</h6>
                    <p class="mb-0">${data.emergency_contact || '—'} &nbsp;|&nbsp; ${data.contact_number || '—'}</p>`;
                }
                if (data.attachment) {
                    html += `<hr>
                    <a href="${data.attachment}" target="_blank" class="btn btn-sm btn-outline-teal">
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
            confirmButtonColor: '#14b8a6',
            confirmButtonText: 'Yes, cancel it'
        }).then(r => {
            if (!r.isConfirmed) return;
            fetch('api/leave_cancel.php?id=' + id, { method: 'POST' })
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

    function fmtDate(d) {
        if (!d) return '—';
        return new Date(d).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
    }

    // Calendar Preview
    document.addEventListener('DOMContentLoaded', function() {
        let calendarPreview = null;
        
        function initCalendarPreview() {
            const calendarEl = document.getElementById('calendarPreview');
            if (calendarEl && !calendarPreview) {
                calendarPreview = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: ''
                    },
                    height: 'auto',
                    contentHeight: 250,
                    dayMaxEvents: true,
                    selectable: true
                });
                calendarPreview.render();
            }
        }
        
        $('#applyLeaveModal').on('shown.bs.modal', function() {
            initCalendarPreview();
        });
        
        function updateCalendarPreview(startDate, endDate) {
            if (calendarPreview) {
                calendarPreview.removeAllEvents();
                
                if (startDate && endDate) {
                    calendarPreview.addEvent({
                        title: 'Leave',
                        start: startDate,
                        end: new Date(new Date(endDate).setDate(new Date(endDate).getDate() + 1)),
                        display: 'background',
                        backgroundColor: '#14b8a6',
                        borderColor: '#0d9488',
                        opacity: 0.3
                    });
                    
                    document.getElementById('previewDateRange').innerHTML = 
                        new Date(startDate).toLocaleDateString() + ' - ' + 
                        new Date(endDate).toLocaleDateString();
                    
                    const totalDays = document.getElementById('num_days').value;
                    const workingDays = document.getElementById('work_days').value;
                    
                    document.getElementById('previewTotalDays').innerHTML = totalDays || '0';
                    document.getElementById('previewWorkingDays').innerHTML = workingDays || '0';
                }
            }
        }
        
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        function onDatesChange() {
            const startDate = startDateInput.value;
            const endDate = endDateInput.value;
            if (startDate && endDate) {
                updateCalendarPreview(startDate, endDate);
            }
        }
        
        if (startDateInput) startDateInput.addEventListener('change', onDatesChange);
        if (endDateInput) endDateInput.addEventListener('change', onDatesChange);
    });
    </script>
</body>
</html>