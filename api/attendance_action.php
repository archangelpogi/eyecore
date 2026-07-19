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

$employee_id = (int) $_SESSION['employee_id'];
$today       = date('Y-m-d');
$now         = date('Y-m-d H:i:s');
$time_now    = date('H:i:s');

// ============= AUTO-APPROVE ATTENDANCE FUNCTION =============
function autoApproveAttendance($pdo, $attendance_id, $employee_id, $clinic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT time_in, break_start, break_end, time_out, approval_status, remarks
            FROM attendance 
            WHERE id = ?
        ");
        $stmt->execute([$attendance_id]);
        $attendance = $stmt->fetch();
        
        if (!$attendance) {
            return ['status' => 'error', 'message' => 'Attendance record not found'];
        }
        
        $has_time_in = !empty($attendance['time_in']);
        $has_break_start = !empty($attendance['break_start']);
        $has_break_end = !empty($attendance['break_end']);
        $has_time_out = !empty($attendance['time_out']);
        
        // COMPLETE LOGS = AUTO-APPROVE
        if ($has_time_in && $has_break_start && $has_break_end && $has_time_out) {
            if ($attendance['approval_status'] === 'approved') {
                return ['status' => 'approved', 'message' => 'Already approved'];
            }
            
            $updateStmt = $pdo->prepare("
                UPDATE attendance 
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    remarks = CONCAT(IFNULL(remarks, ''), ' | Auto-approved (Complete logs)')
                WHERE id = ?
            ");
            $updateStmt->execute([$employee_id, $attendance_id]);
            
            return ['status' => 'approved', 'message' => 'Attendance auto-approved'];
        } 
        // INCOMPLETE LOGS = FLAG FOR MANUAL APPROVAL
        else {
            if ($attendance['approval_status'] !== 'pending') {
                $missing = [];
                if (!$has_time_in) $missing[] = 'Time In';
                if (!$has_break_start) $missing[] = 'Break Start';
                if (!$has_break_end) $missing[] = 'Break End';
                if (!$has_time_out) $missing[] = 'Time Out';
                
                $missing_text = implode(', ', $missing);
                
                $updateStmt = $pdo->prepare("
                    UPDATE attendance 
                    SET approval_status = 'pending',
                        remarks = CONCAT(IFNULL(remarks, ''), ' | FLAGGED: Missing logs - ', ?)
                    WHERE id = ?
                ");
                $updateStmt->execute([$missing_text, $attendance_id]);
            }
            
            return ['status' => 'pending', 'message' => 'Attendance flagged for manual approval'];
        }
        
    } catch (Exception $e) {
        error_log("Auto-approve error: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}


try {
    // ── Parse input ──────────────────────────────────────────────────
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid request data.');
    }

    $action   = $input['action']   ?? '';
    $photo    = $input['photo']    ?? '';
    $lat      = isset($input['latitude'])  ? (float)$input['latitude']  : null;
    $lng      = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $accuracy = isset($input['accuracy'])  ? (float)$input['accuracy']  : null;

    $validActions = ['time_in', 'time_out', 'break_start', 'break_end'];
    if (!in_array($action, $validActions)) {
        throw new Exception('Invalid action.');
    }

    // ── Employee + clinic info ────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            e.id        AS employee_id,
            e.employee_no,
            e.position_id,
            e.status    AS emp_status,
            u.id        AS user_id,
            u.clinic_id,
            u.first_name,
            u.last_name,
            c.clinic_name,
            c.latitude  AS clinic_lat,
            c.longitude AS clinic_lng,
            c.radius    AS clinic_radius
        FROM employees e
        INNER JOIN users u ON u.id = e.user_id
        LEFT JOIN clinics c ON c.id = u.clinic_id
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();

    if (!$employee) {
        throw new Exception('Employee information not found.');
    }
    if ($employee['emp_status'] !== 'Active') {
        throw new Exception('Employee account is not active.');
    }

    $clinic_id = (int) $employee['clinic_id'];

    // ── Fetch today's attendance (with row lock) ──────────────────────
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT * FROM attendance
        WHERE employee_id = ? AND date = ?
        FOR UPDATE
    ");
    $stmt->execute([$employee_id, $today]);
    $attendance = $stmt->fetch();

    // ── Validate action state ─────────────────────────────────────────
    switch ($action) {
        case 'time_in':
            if ($attendance && !empty($attendance['time_in'])) {
                throw new Exception('Already timed in for today.');
            }
            // Block if on approved leave
            $ls = $pdo->prepare("
                SELECT id FROM leaves
                WHERE employee_id = ? AND status = 'Approved'
                  AND ? BETWEEN start_date AND end_date
                LIMIT 1
            ");
            $ls->execute([$employee_id, $today]);
            if ($ls->fetch()) {
                throw new Exception('Cannot time in while on approved leave.');
            }
            break;
        case 'break_start':
            if (!$attendance || empty($attendance['time_in'])) {
                throw new Exception('Cannot start break without time in.');
            }
            if (!empty($attendance['break_start'])) {
                throw new Exception('Break already started.');
            }
            break;
        case 'break_end':
            if (!$attendance || empty($attendance['break_start'])) {
                throw new Exception('No break started.');
            }
            if (!empty($attendance['break_end'])) {
                throw new Exception('Break already ended.');
            }
            break;
        case 'time_out':
            if (!$attendance || empty($attendance['time_in'])) {
                throw new Exception('Cannot time out without time in.');
            }
            if (!empty($attendance['time_out'])) {
                throw new Exception('Already timed out for today.');
            }
            break;
    }

    // ── Geofence check (time_in and time_out only) ────────────────────
    $distance      = null;
    $is_within_geo = 1;
    $location_id   = null;
    $remarks       = '';

    if (in_array($action, ['time_in', 'time_out'])) {
        if (!$lat || !$lng) {
            throw new Exception('Location is required for ' . str_replace('_', ' ', $action) . '.');
        }

        if ($employee['clinic_lat'] && $employee['clinic_lng']) {
            $R    = 6371000;
            $lat1 = deg2rad($lat);
            $lng1 = deg2rad($lng);
            $lat2 = deg2rad($employee['clinic_lat']);
            $lng2 = deg2rad($employee['clinic_lng']);
            $dlat = $lat2 - $lat1;
            $dlng = $lng2 - $lng1;
            $a    = pow(sin($dlat/2), 2) + cos($lat1)*cos($lat2)*pow(sin($dlng/2), 2);
            $distance = $R * 2 * asin(sqrt($a));

            $radius        = (int)($employee['clinic_radius'] ?? 100);
            $is_within_geo = $distance <= $radius ? 1 : 0;

            if (!$is_within_geo) {
                throw new Exception(
                    'Attendance blocked. You are ' . round($distance) . 'm away from the clinic. '
                    . 'Must be within ' . $radius . 'm.'
                );
            }
        } else {
            $remarks = 'Geofencing not configured for this clinic.';
        }

        // Nearest attendance location (optional)
        $ls = $pdo->prepare("
            SELECT id, allowed_radius,
                   (6371000 * ACOS(
                       COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                       COS(RADIANS(longitude) - RADIANS(?)) +
                       SIN(RADIANS(?)) * SIN(RADIANS(latitude))
                   )) AS dist
            FROM attendance_locations
            WHERE is_active = 1
            ORDER BY dist
            LIMIT 5
        ");
        $ls->execute([$lat, $lng, $lat]);
        $loc = null;
        foreach ($ls->fetchAll() as $row) {
            if ($row['dist'] <= ($row['allowed_radius'] * 2)) {
                $loc = $row;
                break;
            }
        }
        if ($loc) $location_id = (int)$loc['id'];
    }

    // ── Photo save ────────────────────────────────────────────────────
    $photo_path = null;
    if ($action === 'time_in' && empty($photo)) {
        throw new Exception('Photo is required for Time In.');
    }
    if (!empty($photo) && strpos($photo, 'data:image') === 0) {
        $uploadDir = __DIR__ . '/../uploads/attendance/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        [, $b64] = explode(',', $photo, 2);
        $imgData  = base64_decode($b64);
        $filename = 'att_' . $employee_id . '_' . date('Ymd_His') . '_' . $action . '.jpg';
        if (file_put_contents($uploadDir . $filename, $imgData) !== false) {
            $photo_path = 'uploads/attendance/' . $filename;
        }
    }

    // ── Create attendance row if not exists ───────────────────────────
    $attendance_method = 'location_photo';
    if (!$attendance) {
        $pdo->prepare("
            INSERT INTO attendance
                (clinic_id, employee_id, date, location_id, attendance_method,
                 scan_location_lat, scan_location_lng, distance_meters, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $clinic_id, $employee_id, $today, $location_id,
            $attendance_method, $lat, $lng, $distance
        ]);
        $attendance_id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->execute([$attendance_id]);
        $attendance = $stmt->fetch();
    } else {
        $attendance_id = (int)$attendance['id'];
    }

    // ── Action-specific update ────────────────────────────────────────
    $nextStatus  = 'not_in';
    $message     = '';
    $total_hours = null;

    switch ($action) {
        case 'time_in':
            // Use ScheduleResolver for per-employee or position schedule
            $schedule = ScheduleResolver::get($pdo, $employee_id, $clinic_id);
            $status   = 'Present';

            if ($schedule && !empty($schedule['time_in'])) {
                $grace   = (int)($schedule['grace_period'] ?? 15) * 60;
                $lateSec = strtotime($time_now) - strtotime($schedule['time_in']);
                if ($lateSec > $grace) {
                    $status  = 'Late';
                    $message = 'Time In recorded (Late)';
                    $remarks = 'Arrived ' . $time_now . ' — Scheduled: ' . substr($schedule['time_in'], 0, 5)
                             . ' (Source: ' . ($schedule['source'] ?? 'unknown') . ')';
                } else {
                    $message = 'Time In recorded successfully';
                }
            } else {
                $message = 'Time In recorded successfully';
            }

            $pdo->prepare("
                UPDATE attendance
                SET time_in = ?, status = ?,
                    location_id = COALESCE(?, location_id),
                    scan_location_lat = ?, scan_location_lng = ?,
                    attendance_method = ?,
                    remarks = COALESCE(NULLIF(?, ''), remarks),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $time_now, $status, $location_id,
                $lat, $lng, $attendance_method,
                $remarks, $attendance_id
            ]);
            $nextStatus = 'in_work';
            break;

        case 'break_start':
            $pdo->prepare("UPDATE attendance SET break_start = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$time_now, $attendance_id]);
            $nextStatus = 'on_break';
            $message    = 'Break started';
            break;

        case 'break_end':
            $pdo->prepare("UPDATE attendance SET break_end = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$time_now, $attendance_id]);
            $nextStatus = 'in_work';
            $message    = 'Break ended';
            break;

                case 'time_out':
            // Auto-close unclosed break
            if (!empty($attendance['break_start']) && empty($attendance['break_end'])) {
                $pdo->prepare("UPDATE attendance SET break_end = ? WHERE id = ?")
                    ->execute([$time_now, $attendance_id]);
            }
            $pdo->prepare("UPDATE attendance SET time_out = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$time_now, $attendance_id]);

            // Calculate total hours
            $r = $pdo->prepare("SELECT time_in, break_start, break_end FROM attendance WHERE id = ?");
            $r->execute([$attendance_id]);
            $rec = $r->fetch();

            $secs = strtotime($time_now) - strtotime($rec['time_in']);
            if (!empty($rec['break_start']) && !empty($rec['break_end'])) {
                $secs -= strtotime($rec['break_end']) - strtotime($rec['break_start']);
            }
            $total_hours = round($secs / 3600, 2);

            $pdo->prepare("UPDATE attendance SET total_hours = ? WHERE id = ?")
                ->execute([$total_hours, $attendance_id]);

            $nextStatus = 'done';
            $message    = 'Time Out recorded. Total: ' . $total_hours . ' hrs';
            
            // ✅ ADD THIS: Auto-approve after time_out
            $autoApproveResult = autoApproveAttendance($pdo, $attendance_id, $employee_id, $clinic_id);
            if ($autoApproveResult['status'] === 'approved') {
                $message .= ' | Attendance auto-approved (Complete logs)';
            } elseif ($autoApproveResult['status'] === 'pending') {
                $message .= ' | Attendance flagged for review (Missing logs)';
            }
            break;
    }

    // ── Attendance log ────────────────────────────────────────────────
    $pdo->prepare("
        INSERT INTO attendance_logs
            (clinic_id, attendance_id, employee_id, action_type, attendance_method,
             photo_path, scan_time, client_time, device_info, ip_address,
             latitude, longitude, distance_meters, is_within_geo, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $clinic_id, $attendance_id, $employee_id, $action, $attendance_method,
        $photo_path, $now, $now,
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255),
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $lat, $lng, $distance, $is_within_geo, $remarks
    ]);

    $pdo->commit();

    // ── Response ──────────────────────────────────────────────────────
    $resp = [
        'success'   => true,
        'message'   => $message,
        'status'    => $nextStatus,
        'action'    => $action,
        'timestamp' => $time_now,
        'employee'  => [
            'id'          => $employee_id,
            'employee_no' => $employee['employee_no'],
            'name'        => trim($employee['first_name'] . ' ' . $employee['last_name'])
        ],
        'geolocation' => [
            'latitude'             => $lat,
            'longitude'            => $lng,
            'accuracy'             => $accuracy,
            'distance_from_clinic' => $distance !== null ? round($distance, 2) : null,
            'within_geofence'      => (bool)$is_within_geo,
        ],
        'photo' => ['saved' => !empty($photo_path)],
    ];

    if ($total_hours !== null) $resp['total_hours'] = $total_hours;
    if ($remarks)              $resp['remarks']     = $remarks;

    echo json_encode($resp);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}