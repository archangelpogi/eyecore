<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/db.php';

// Auth
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first.']);
    exit;
}

// Resolve employee_id
if (empty($_SESSION['employee_id'])) {
    $s = $pdo->prepare("SELECT id FROM employees WHERE user_id = ?");
    $s->execute([$_SESSION['user_id']]);
    $emp = $s->fetch();
    if (!$emp) {
        echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
        exit;
    }
    $_SESSION['employee_id'] = $emp['id'];
}

// Resolve clinic_id
if (empty($_SESSION['clinic_id'])) {
    $s = $pdo->prepare("SELECT clinic_id FROM users WHERE id = ?");
    $s->execute([$_SESSION['user_id']]);
    $u = $s->fetch();
    $_SESSION['clinic_id'] = $u['clinic_id'] ?? 0;
}

$employee_id = (int) $_SESSION['employee_id'];
$clinic_id   = (int) $_SESSION['clinic_id'];
$user_id     = (int) $_SESSION['user_id'];

try {
    // ── Input ────────────────────────────────────────────────────────
    $leave_type_id     = isset($_POST['leave_type_id']) ? (int) $_POST['leave_type_id'] : 0;
    $start_date        = trim($_POST['start_date']        ?? '');
    $end_date          = trim($_POST['end_date']          ?? '');
    $reason            = trim($_POST['reason']            ?? '');
    $leave_with_pay    = in_array($_POST['leave_with_pay'] ?? '', ['with_pay','without_pay'])
                         ? $_POST['leave_with_pay'] : 'with_pay';
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $contact_number    = trim($_POST['contact_number']    ?? '');
    $calculated_days   = max(0, (int)($_POST['calculated_days'] ?? 0));

    // ── Validation ───────────────────────────────────────────────────
    if (!$leave_type_id || !$start_date || !$end_date || !$reason) {
        throw new Exception('All required fields must be filled.');
    }

    $start = new DateTime($start_date);
    $end   = new DateTime($end_date);
    $today = new DateTime('today');

    if ($start < $today) {
        throw new Exception('Start date cannot be in the past.');
    }
    if ($end < $start) {
        throw new Exception('End date must be on or after start date.');
    }
    if ($calculated_days <= 0) {
        throw new Exception('Number of working days must be at least 1.');
    }

    // ── Leave type check ─────────────────────────────────────────────
    $s = $pdo->prepare("SELECT * FROM leave_types WHERE id = ? AND clinic_id = ? AND is_active = 1");
    $s->execute([$leave_type_id, $clinic_id]);
    $leave_type = $s->fetch();

    if (!$leave_type) {
        throw new Exception('Invalid or inactive leave type for your clinic.');
    }

    // ── Attachment ───────────────────────────────────────────────────
    $attachment_path = null;

    if ($leave_type['requires_attachment']) {
        if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('An attachment is required for this leave type.');
        }
    }

    if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png','doc','docx'])) {
            throw new Exception('Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX.');
        }
        if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) {
            throw new Exception('File too large. Maximum size is 5 MB.');
        }

        $uploadDir = __DIR__ . '/../uploads/leaves/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = 'leave_' . $employee_id . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $filename)) {
            throw new Exception('Failed to upload attachment. Please try again.');
        }
        $attachment_path = 'uploads/leaves/' . $filename;
    }

    // ── Overlap check ────────────────────────────────────────────────
    $s = $pdo->prepare("
        SELECT id FROM leaves
        WHERE employee_id = ?
          AND status IN ('Pending','Approved')
          AND (
              start_date BETWEEN ? AND ?  OR
              end_date   BETWEEN ? AND ?  OR
              ? BETWEEN start_date AND end_date
          )
        LIMIT 1
    ");
    $s->execute([$employee_id, $start_date, $end_date, $start_date, $end_date, $start_date]);
    if ($s->fetch()) {
        throw new Exception('You already have a pending or approved leave that overlaps these dates.');
    }

    // ── Leave balance check ──────────────────────────────────────────
    $s = $pdo->prepare("
        SELECT balance FROM leave_balances
        WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND clinic_id = ?
    ");
    $s->execute([$employee_id, $leave_type_id, date('Y'), $clinic_id]);
    $bal = $s->fetch();

    if ($bal !== false && (float)$bal['balance'] < $calculated_days && $leave_with_pay === 'with_pay') {
        throw new Exception(
            "Insufficient leave balance. You have {$bal['balance']} day(s) remaining but requested {$calculated_days}."
        );
    }

    // ── Insert ───────────────────────────────────────────────────────
    $s = $pdo->prepare("
        INSERT INTO leaves
            (clinic_id, employee_id, leave_type_id, start_date, end_date,
             number_of_days, reason, status, leave_with_pay,
             emergency_contact, contact_number, attachment, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?,
             ?, ?, 'Pending', ?,
             ?, ?, ?, NOW(), NOW())
    ");
    $s->execute([
        $clinic_id,
        $employee_id,
        $leave_type_id,
        $start_date,
        $end_date,
        $calculated_days,
        $reason,
        $leave_with_pay,
        $emergency_contact ?: null,
        $contact_number    ?: null,
        $attachment_path,
    ]);

    $leave_id = (int) $pdo->lastInsertId();

    // ── Audit log ────────────────────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, clinic_id, action, table_name, record_id, new_values, created_at)
            VALUES (?, ?, 'CREATE', 'leaves', ?, ?, NOW())
        ")->execute([
            $user_id,
            $clinic_id,
            $leave_id,
            json_encode([
                'leave_type'    => $leave_type['type_name'],
                'start_date'    => $start_date,
                'end_date'      => $end_date,
                'days'          => $calculated_days,
                'leave_with_pay'=> $leave_with_pay,
            ]),
        ]);
    } catch (Exception $e) {
        // Non-critical — don't fail the request
        error_log('Audit log insert failed: ' . $e->getMessage());
    }

    // ── Notify HR (optional, non-critical) ──────────────────────────
    try {
        $hrStmt = $pdo->prepare("
            SELECT id FROM users
            WHERE clinic_id = ? AND role IN ('HR','ClinicAdmin') AND status = 'Active'
        ");
        $hrStmt->execute([$clinic_id]);
        $hrUsers = $hrStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, clinic_id, title, message, type, created_at)
            VALUES (?, ?, 'New Leave Request', ?, 'info', NOW())
        ");
        $empName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $msg = "{$empName} filed a {$leave_type['type_name']} leave ({$start_date} – {$end_date}, {$calculated_days} day(s)).";
        foreach ($hrUsers as $hrId) {
            $notifStmt->execute([$hrId, $clinic_id, $msg]);
        }
    } catch (Exception $e) {
        error_log('HR notification failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Leave application submitted successfully.',
        'reference' => 'LV-' . str_pad($leave_id, 6, '0', STR_PAD_LEFT),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}