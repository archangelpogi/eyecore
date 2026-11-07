<?php
// api/supplier_rider_check.php
// Check rider availability for a specific date/time

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['supplier_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$supplier_id = (int)$_SESSION['supplier_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// HELPER: Get day of week (1=Mon, 7=Sun)
// ============================================================
function getDayOfWeek($date) {
    $dow = (int)date('N', strtotime($date));
    return $dow; // 1 (Mon) to 7 (Sun)
}

// ============================================================
// HELPER: Check time overlap
// Returns true kung may overlap
// ============================================================
function timesOverlap($newStart, $newEnd, $existingStart, $existingEnd) {
    // Both are 'HH:MM:SS' strings
    // Overlap kung: newStart < existingEnd AND newEnd > existingStart
    return ($newStart < $existingEnd) && ($newEnd > $existingStart);
}

// ============================================================
// HELPER: Add buffer to time (in minutes)
// ============================================================
function addBuffer($time, $minutes) {
    return date('H:i:s', strtotime($time) + ($minutes * 60));
}

// ============================================================
// ACTION: check_availability
// Check kung anong riders ang available sa specific date/time
// ============================================================
if ($action === 'check_availability') {
    try {
        $date = $_GET['date'] ?? '';
        $time_start = $_GET['time_start'] ?? '';
        $time_end = $_GET['time_end'] ?? '';
        $exclude_po_id = (int)($_GET['exclude_po_id'] ?? 0); // Kung nag-e-edit
        
        // Validate
        if (empty($date) || empty($time_start) || empty($time_end)) {
            throw new Exception('Date, time start, and time end are required');
        }
        
        if ($time_start >= $time_end) {
            throw new Exception('Time start must be before time end');
        }
        
        // Normalize time (add :00 kung wala)
        if (strlen($time_start) === 5) $time_start .= ':00';
        if (strlen($time_end) === 5) $time_end .= ':00';
        
        $day_of_week = getDayOfWeek($date);
        $buffer_minutes = 30; // Buffer between deliveries
        
        // Get all active + verified riders ng supplier
        $ridersStmt = $pdo->prepare("
            SELECT 
                r.id, 
                r.full_name, 
                r.phone, 
                r.vehicle_type, 
                r.plate_number,
                r.photo_path,
                r.status,
                r.verification_status
            FROM supplier_riders r
            WHERE r.supplier_id = ?
              AND r.status = 'Active'
              AND r.verification_status = 'Verified'
            ORDER BY r.full_name ASC
        ");
        $ridersStmt->execute([$supplier_id]);
        $riders = $ridersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Kunin ang schedule ng lahat ng riders
        $schedules = [];
        
        if (!empty($riders)) {
            $rider_ids = array_map('intval', array_column($riders, 'id'));
            $placeholders = implode(',', array_fill(0, count($rider_ids), '?'));
            
            $schedStmt = $pdo->prepare("
                SELECT rider_id, time_in, time_out, break_start, break_end, is_active
                FROM supplier_rider_schedules
                WHERE rider_id IN ($placeholders)
                  AND day_of_week = ?
            ");
            
            $params = array_merge($rider_ids, [$day_of_week]);
            $schedStmt->execute($params);
            
            while ($row = $schedStmt->fetch(PDO::FETCH_ASSOC)) {
                $schedules[(int)$row['rider_id']] = $row;
            }
        }
        
        // Kunin ang existing deliveries sa parehong araw
        $conflictStmt = $pdo->prepare("
            SELECT 
                assigned_rider_id,
                scheduled_time_start,
                scheduled_time_end,
                po_number
            FROM purchase_orders
            WHERE supplier_id = ?
              AND scheduled_delivery_date = ?
              AND assigned_rider_id IS NOT NULL
              AND status IN ('To Ship', 'Shipped')
              AND id != ?
        ");
        $conflictStmt->execute([$supplier_id, $date, $exclude_po_id]);
        $existing = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group existing deliveries by rider
        $existingByRider = [];
        foreach ($existing as $e) {
            $rid = (int)$e['assigned_rider_id'];
            if (!isset($existingByRider[$rid])) {
                $existingByRider[$rid] = [];
            }
            $existingByRider[$rid][] = $e;
        }
        
        // Check each rider
        $results = [];
        foreach ($riders as $r) {
            $rid = (int)$r['id'];
$availability = [
    'id' => $rid,           // ✅ I-ADD ITO
    'rider_id' => $rid,     // ✅ I-keep din para sa compatibility
    'full_name' => $r['full_name'],
    'phone' => $r['phone'],
    'vehicle_type' => $r['vehicle_type'],
    'plate_number' => $r['plate_number'],
    'photo_path' => $r['photo_path'],
    'available' => true,
    'conflicts' => [],
    'shift_info' => null,
];
            
            // Check 1: May schedule ba sa day na ito?
            if (!isset($schedules[$rid])) {
                $availability['available'] = false;
                $availability['conflicts'][] = 'Walang schedule sa araw na ito';
                $results[] = $availability;
                continue;
            }
            
            $sched = $schedules[$rid];
            
            // Check 2: Active ba ang day (hindi day off)?
            if (!$sched['is_active']) {
                $availability['available'] = false;
                $availability['conflicts'][] = 'Day off sa araw na ito';
                $results[] = $availability;
                continue;
            }
            
            $shift_start = $sched['time_in'];
            $shift_end = $sched['time_out'];
            
            $availability['shift_info'] = [
                'time_in' => substr($shift_start, 0, 5),
                'time_out' => substr($shift_end, 0, 5),
                'break_start' => $sched['break_start'] ? substr($sched['break_start'], 0, 5) : null,
                'break_end' => $sched['break_end'] ? substr($sched['break_end'], 0, 5) : null,
            ];
            
            // Check 3: Nasa shift ba ang time window?
            if ($time_start < $shift_start || $time_end > $shift_end) {
                $availability['available'] = false;
                $availability['conflicts'][] = "Nasa labas ng shift ({$availability['shift_info']['time_in']} - {$availability['shift_info']['time_out']})";
            }
            
            // Check 4: Overlap sa break?
            if ($sched['break_start'] && $sched['break_end']) {
                if (timesOverlap($time_start, $time_end, $sched['break_start'], $sched['break_end'])) {
                    $availability['available'] = false;
                    $availability['conflicts'][] = "Kasama sa break ({$availability['shift_info']['break_start']} - {$availability['shift_info']['break_end']})";
                }
            }
            
            // Check 5: Conflict sa existing deliveries
            if (isset($existingByRider[$rid])) {
                // Add buffer sa new time
                $new_start_buffered = date('H:i:s', strtotime($time_start) - ($buffer_minutes * 60));
                $new_end_buffered = date('H:i:s', strtotime($time_end) + ($buffer_minutes * 60));
                
                foreach ($existingByRider[$rid] as $ex) {
                    if (empty($ex['scheduled_time_start']) || empty($ex['scheduled_time_end'])) {
                        continue;
                    }
                    
                    if (timesOverlap($time_start, $time_end, $ex['scheduled_time_start'], $ex['scheduled_time_end'])) {
                        $availability['available'] = false;
                        $availability['conflicts'][] = "May delivery na sa {$ex['po_number']} (" . substr($ex['scheduled_time_start'], 0, 5) . " - " . substr($ex['scheduled_time_end'], 0, 5) . ")";
                    }
                }
            }
            
            $results[] = $availability;
        }
        
        // Separate into available and unavailable
        $available = array_filter($results, fn($r) => $r['available']);
        $unavailable = array_filter($results, fn($r) => !$r['available']);
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'day_of_week' => $day_of_week,
            'day_name' => ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day_of_week],
            'time_window' => ['start' => $time_start, 'end' => $time_end],
            'available' => array_values($available),
            'unavailable' => array_values($unavailable),
            'total_riders' => count($results),
            'available_count' => count($available),
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: suggest_slots
// Suggest available time slots para sa specific rider
// ============================================================
if ($action === 'suggest_slots') {
    try {
        $rider_id = (int)($_GET['rider_id'] ?? 0);
        $date = $_GET['date'] ?? '';
        
        if (!$rider_id || empty($date)) {
            throw new Exception('Rider ID and date are required');
        }
        
        // Verify rider belongs to supplier
        $checkStmt = $pdo->prepare("
            SELECT id, full_name 
            FROM supplier_riders 
            WHERE id = ? AND supplier_id = ?
        ");
        $checkStmt->execute([$rider_id, $supplier_id]);
        $rider = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) {
            throw new Exception('Rider not found');
        }
        
        $day_of_week = getDayOfWeek($date);
        
        // Get schedule
        $schedStmt = $pdo->prepare("
            SELECT time_in, time_out, break_start, break_end, is_active
            FROM supplier_rider_schedules
            WHERE rider_id = ? AND day_of_week = ?
        ");
        $schedStmt->execute([$rider_id, $day_of_week]);
        $sched = $schedStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sched || !$sched['is_active']) {
            echo json_encode([
                'success' => true,
                'slots' => [],
                'message' => 'Walang schedule sa araw na ito',
            ]);
            exit;
        }
        
        // Get existing deliveries
        $existStmt = $pdo->prepare("
            SELECT scheduled_time_start, scheduled_time_end, po_number
            FROM purchase_orders
            WHERE assigned_rider_id = ?
              AND scheduled_delivery_date = ?
              AND status IN ('To Ship', 'Shipped')
            ORDER BY scheduled_time_start ASC
        ");
        $existStmt->execute([$rider_id, $date]);
        $existing = $existStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate slots every 30 minutes
        $slots = [];
        $slot_duration = 120; // 2 oras per slot (minutes)
        $step = 30; // every 30 min
        
        $shift_start = strtotime($sched['time_in']);
        $shift_end = strtotime($sched['time_out']);
        
        for ($t = $shift_start; $t + ($slot_duration * 60) <= $shift_end; $t += $step * 60) {
            $slot_start = date('H:i:s', $t);
            $slot_end = date('H:i:s', $t + ($slot_duration * 60));
            
            // Skip kung overlap sa break
            if ($sched['break_start'] && $sched['break_end']) {
                if (timesOverlap($slot_start, $slot_end, $sched['break_start'], $sched['break_end'])) {
                    continue;
                }
            }
            
            // Check conflicts
            $has_conflict = false;
            foreach ($existing as $ex) {
                if (timesOverlap($slot_start, $slot_end, $ex['scheduled_time_start'], $ex['scheduled_time_end'])) {
                    $has_conflict = true;
                    break;
                }
            }
            
            if (!$has_conflict) {
                $slots[] = [
                    'start' => substr($slot_start, 0, 5),
                    'end' => substr($slot_end, 0, 5),
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'rider_id' => $rider_id,
            'rider_name' => $rider['full_name'],
            'slots' => $slots,
            'existing_count' => count($existing),
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Default
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);