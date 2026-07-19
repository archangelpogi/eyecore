<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/schedule_resolver.php';

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'status' => 'not_in', 'message' => 'Not authenticated.']);
    exit;
}

try {
    $user_id = (int) $_SESSION['user_id'];

    // Resolve employee — always from DB, not session cache
    $stmt = $pdo->prepare("SELECT id, employee_no FROM employees WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $empRow = $stmt->fetch();

    if (!$empRow) {
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'No employee record found for your account.'
        ]);
        exit;
    }

    $employee_id = (int) $empRow['id'];
    $_SESSION['employee_id'] = $employee_id; // keep session in sync

    $today = date('Y-m-d');

    // ── Employee + clinic details ─────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            e.id        AS employee_id,
            e.employee_no,
            e.position_id,
            e.status    AS emp_status,
            u.first_name,
            u.last_name,
            u.email,
            u.role,
            u.clinic_id,
            c.clinic_name,
            c.latitude  AS clinic_lat,
            c.longitude AS clinic_lng,
            c.radius    AS clinic_radius
        FROM employees e
        JOIN users u ON u.id = e.user_id
        LEFT JOIN clinics c ON c.id = u.clinic_id
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch();

    if (!$emp) throw new Exception('Employee details not found.');

    $clinic_id = (int) $emp['clinic_id'];

    // ── Today's attendance ────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
    $stmt->execute([$employee_id, $today]);
    $attendance = $stmt->fetch();

    // ── Determine status ──────────────────────────────────────────────
    if ($attendance) {
        if (!empty($attendance['time_out'])) {
            $status = 'done';
        } elseif (!empty($attendance['break_start']) && empty($attendance['break_end'])) {
            $status = 'on_break';
        } elseif (!empty($attendance['time_in'])) {
            $status = 'in_work';
        } else {
            $status = 'not_in';
        }
    } else {
        // Check approved leave
        $ls = $pdo->prepare("
            SELECT id FROM leaves
            WHERE employee_id = ? AND status = 'Approved'
              AND ? BETWEEN start_date AND end_date
            LIMIT 1
        ");
        $ls->execute([$employee_id, $today]);
        $status = $ls->fetch() ? 'on_leave' : 'not_in';
    }

    // ── Effective schedule (per-employee > position) ──────────────────
    $schedule = ScheduleResolver::get($pdo, $employee_id, $clinic_id);

    // ── Response ──────────────────────────────────────────────────────
    $response = [
        'success'     => true,
        'status'      => $status,
        'employee'    => [
            'id'          => $emp['employee_id'],
            'employee_no' => $emp['employee_no'],
            'name'        => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'role'        => $emp['role'],
            'clinic_id'   => $clinic_id,
            'clinic_name' => $emp['clinic_name'],
            'status'      => $emp['emp_status'],
        ],
        'attendance'   => $attendance ?: null,
        'current_time' => date('H:i:s'),
        'current_date' => $today,
    ];
    
    // ✅ ADD THIS: Include schedule if available
    if ($schedule) {
        $response['schedule'] = [
            'time_in'             => $schedule['time_in'] ? substr($schedule['time_in'], 0, 5) : null,
            'time_out'            => $schedule['time_out'] ? substr($schedule['time_out'], 0, 5) : null,
            'grace_period'        => $schedule['grace_period'],
            'required_work_hours' => $schedule['required_work_hours'],
            'source'              => $schedule['source'], // 'employee' or 'position'
        ];
    }
    
    // ✅ ADD THIS: Include approval status if attendance exists
    if ($attendance) {
        $response['approval_status'] = $attendance['approval_status'] ?? 'pending';
        $response['approval_status_text'] = getApprovalStatusText($attendance['approval_status'] ?? 'pending');
    }
    
    // ✅ ADD THIS: Include schedule info in attendance if available
    if ($attendance && $schedule) {
        $response['attendance']['schedule_info'] = [
            'expected_time_in' => $schedule['time_in'] ? substr($schedule['time_in'], 0, 5) : null,
            'expected_time_out' => $schedule['time_out'] ? substr($schedule['time_out'], 0, 5) : null,
            'grace_period' => $schedule['grace_period'],
        ];
    }

    if ($emp['clinic_lat']) {
        $response['geofence'] = [
            'latitude'  => (float) $emp['clinic_lat'],
            'longitude' => (float) $emp['clinic_lng'],
            'radius'    => (int)   $emp['clinic_radius'],
        ];
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'System error. Please try again.'
    ]);
}

// Helper function for approval status text
function getApprovalStatusText($status) {
    switch ($status) {
        case 'approved':
            return 'Approved ✓';
        case 'rejected':
            return 'Rejected ✗';
        case 'pending':
        default:
            return 'Pending Review ⏳';
    }
}