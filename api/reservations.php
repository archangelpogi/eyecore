<?php
// api/reservations.php — Order Management API with COD Collection + Rider Endpoints
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ Determine request type
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$isRiderRequest    = str_starts_with($action, 'rider_');
$isCustomerRequest = in_array($action, ['mark_received', 'request_refund'], true);

// ✅ Allow: rider, customer, OR clinic requests
if (!$isRiderRequest && !$isCustomerRequest && !isset($_SESSION['clinic_id'])) {
    echo json_encode(['success' => false, 'message' => 'Clinic not selected']);
    exit;
}

$clinicId   = $_SESSION['clinic_id'] ?? 0;
$currentUid = (int)($_SESSION['user_id'] ?? 0);

// ════════════════════════════════════════════════════════════════
// RBAC
// ════════════════════════════════════════════════════════════════
function canViewReservations()   { return RBACHelper::hasPermission('reservations_view'); }
function canCreateReservations() { return RBACHelper::hasPermission('reservations_create'); }
function canEditReservations()   { return RBACHelper::hasPermission('reservations_edit'); }
function canDeleteReservations() { return RBACHelper::hasPermission('reservations_delete'); }
function canApproveReservations(){ return RBACHelper::hasPermission('reservations_approve'); }
function canRejectReservations() { return RBACHelper::hasPermission('reservations_reject'); }

// ════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════
function respond(bool $success, string $message = '', $data = null, int $code = 200): void
{
    http_response_code($code);
    $out = ['success' => $success];
    if ($message !== '') $out['message'] = $message;
    if ($data !== null) $out['data'] = $data;
    echo json_encode($out);
    exit;
}

function notifyUser(PDO $pdo, int $userId, string $title, string $message, string $type, string $link = 'my-reservations.php', ?int $refId = null): void
{
    $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, reference_id, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ")->execute([$userId, $title, $message, $type, $refId, $link]);
}

/**
 * ✅ Add pre-formatted server-side dates to an order row.
 */
function addFormattedDates(array &$row): void
{
    $row['created_at_formatted']        = !empty($row['created_at'])        ? date('M d, Y • g:i A', strtotime($row['created_at'])) : null;
    $row['updated_at_formatted']        = !empty($row['updated_at'])        ? date('M d, Y • g:i A', strtotime($row['updated_at'])) : null;
    $row['delivered_at_formatted']      = !empty($row['delivered_at'])      ? date('M d, Y • g:i A', strtotime($row['delivered_at'])) : null;
    $row['delivery_proof_at_formatted'] = !empty($row['delivery_proof_at']) ? date('M d, Y • g:i A', strtotime($row['delivery_proof_at'])) : null;
    $row['collected_at_formatted']      = !empty($row['collected_at'])      ? date('M d, Y • g:i A', strtotime($row['collected_at'])) : null;
    $row['delivery_date_formatted']     = !empty($row['delivery_date'])     ? date('M d, Y', strtotime($row['delivery_date'])) : null;
    $row['preferred_date_formatted']    = !empty($row['preferred_date'])    ? date('M d, Y', strtotime($row['preferred_date'])) : null;
    $row['created_at_date_only']        = !empty($row['created_at'])        ? date('M d, Y', strtotime($row['created_at'])) : null;
}

function fetchOrder(PDO $pdo, int $id, int $clinicId): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.*,
               u.id AS user_id_val,
               u.email AS user_email,
               u.contact AS user_contact,
               CONCAT(u.first_name,' ',u.last_name) AS user_name,
               p.name AS product_name,
               p.category AS product_category,
               p.image AS product_image,
               rd.name AS rider_name,
               rd.phone AS rider_phone,
               cu.first_name AS collector_first,
               cu.last_name  AS collector_last,
               up.od_sph, up.od_cyl, up.od_axis,
               up.os_sph, up.os_cyl, up.os_axis,
               up.prescription_source,
               up.prescription_file,
               up.created_at AS prescription_created_at
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN products p ON r.product_id = p.id
        LEFT JOIN riders rd ON r.assigned_rider_id = rd.id
        LEFT JOIN users cu ON r.collected_by = cu.id
        LEFT JOIN user_prescriptions up ON r.prescription_id = up.id
        WHERE r.id = ? AND r.clinic_id = ?
    ");
    $stmt->execute([$id, $clinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['collector_name'] = trim(($row['collector_first'] ?? '').' '.($row['collector_last'] ?? ''));
        addFormattedDates($row);
    }
    return $row ?: null;
}

function requiresCollection(array $order): bool
{
    return in_array($order['payment_status'], ['cod', 'onsite', 'unpaid', 'partial'], true);
}

function recordCollection(PDO $pdo, int $orderId, int $clinicId, float $amount, string $notes, int $userId): array
{
    $stmt = $pdo->prepare("SELECT total_amount, payment_status FROM reservations WHERE id=? AND clinic_id=?");
    $stmt->execute([$orderId, $clinicId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok'=>false, 'reason'=>'Order not found'];

    $expected = (float)$row['total_amount'];
    $newStatus = 'paid';
    if ($amount < $expected - 0.01) {
        $newStatus = $amount > 0 ? 'partial' : 'unpaid';
    }

    $pdo->prepare("
        UPDATE reservations
        SET collected_amount = ?,
            collected_at     = NOW(),
            collected_by     = ?,
            collection_notes = ?,
            payment_status   = ?,
            updated_at       = NOW()
        WHERE id=? AND clinic_id=?
    ")->execute([$amount, $userId, $notes ?: null, $newStatus, $orderId, $clinicId]);

    return ['ok'=>true, 'status'=>$newStatus];
}


// ════════════════════════════════════════════════════════════════
// ✅ RIDER SCHEDULE VALIDATION HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * ✅ Check kung ang delivery time ay within rider's working hours
 * Base sa employee_schedules table
 */
function isRiderWithinWorkingHours(
    PDO $pdo,
    int $riderEmployeeId,
    int $clinicId,
    string $deliveryDate,
    string $timeStart,
    string $timeEnd
): array {
    $stmt = $pdo->prepare("
        SELECT es.time_in, es.time_out, 
               es.break_start, es.break_end, es.schedule_name
        FROM employee_schedules es
        WHERE es.employee_id = ?
          AND es.clinic_id = ?
          AND es.is_active = 1
          AND (es.effective_from IS NULL OR es.effective_from <= ?)
          AND (es.effective_to   IS NULL OR es.effective_to   >= ?)
        ORDER BY es.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$riderEmployeeId, $clinicId, $deliveryDate, $deliveryDate]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    // Walang schedule = hindi puwede mag-deliver
    if (!$schedule) {
        return [
            'ok' => false,
            'reason' => 'Rider has no active working schedule. Please set working hours in Schedule Management first.',
            'schedule' => null
        ];
    }

    // Normalize times (HH:MM format)
    $timeIn     = substr($schedule['time_in'], 0, 5);
    $timeOut    = substr($schedule['time_out'], 0, 5);
    $breakStart = $schedule['break_start'] ? substr($schedule['break_start'], 0, 5) : null;
    $breakEnd   = $schedule['break_end'] ? substr($schedule['break_end'], 0, 5) : null;

    $dStart = substr($timeStart, 0, 5);
    $dEnd   = substr($timeEnd, 0, 5);

    // ✅ Check 1: Within working hours?
    if ($dStart < $timeIn || $dEnd > $timeOut) {
        return [
            'ok' => false,
            'reason' => "Delivery time ({$dStart}–{$dEnd}) is outside rider's working hours ({$timeIn}–{$timeOut}).",
            'schedule' => $schedule
        ];
    }

    // ✅ Check 2: Hindi overlap sa break?
    if ($breakStart && $breakEnd) {
        if ($dStart < $breakEnd && $dEnd > $breakStart) {
            return [
                'ok' => false,
                'reason' => "Delivery time overlaps with rider's break ({$breakStart}–{$breakEnd}).",
                'schedule' => $schedule
            ];
        }
    }

    return [
        'ok' => true,
        'reason' => 'Within working hours.',
        'schedule' => $schedule
    ];
}

/**
 * ✅ Check kung may conflict sa ibang delivery ng rider
 * Returns: conflict row or null
 */
function hasRiderConflict(
    PDO $pdo,
    int $riderId,
    int $clinicId,
    string $deliveryDate,
    string $timeStart,
    string $timeEnd,
    ?int $excludeOrderId = null
): ?array {
    $sql = "
        SELECT id, reservation_code, 
               TIME_FORMAT(delivery_time_start, '%H:%i') AS delivery_time_start,
               TIME_FORMAT(delivery_time_end, '%H:%i')   AS delivery_time_end,
               status
        FROM reservations
        WHERE assigned_rider_id = ?
          AND clinic_id = ?
          AND delivery_date = ?
          AND status NOT IN ('delivered','completed','cancelled')
          AND (
              (delivery_time_start < ? AND delivery_time_end > ?)
              OR (delivery_time_start < ? AND delivery_time_end IS NULL)
              OR (delivery_time_start IS NULL AND delivery_time_end > ?)
          )
    ";
    $params = [
        $riderId, $clinicId, $deliveryDate,
        $timeEnd, $timeStart,
        $timeEnd, $timeStart
    ];

    if ($excludeOrderId) {
        $sql .= " AND id != ?";
        $params[] = $excludeOrderId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
    return $conflict ?: null;
}

function handleRefundEvidenceUpload(int $reservationId): ?string
{
    if (!isset($_FILES['evidence']) || $_FILES['evidence']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES['evidence'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $uploadDir = __DIR__ . '/../uploads/refund_evidence/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'refund_' . $reservationId . '_' . time() . '_' . uniqid() . '.' . strtolower($ext);
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) return null;

    return 'uploads/refund_evidence/' . $filename;
}

/**
 * ✅ Send refund notification to customer
 */
function sendRefundNotification(PDO $pdo, int $userId, int $reservationId, string $status, string $message = '', ?float $amount = null): void
{
    try {
        $titles = [
            'submitted'  => '✅ Refund Request Submitted',
            'approved'   => '✅ Refund Approved',
            'processing' => '🔄 Refund Processing',
            'completed'  => '💰 Refund Completed',
            'rejected'   => '❌ Refund Request Rejected',
        ];
        
        $title = $titles[$status] ?? '🔄 Refund Update';
        
        $bodies = [
            'submitted'  => "Your refund request for order #{$reservationId} has been submitted. Please wait for clinic review.",
            'approved'   => "Your refund request for order #{$reservationId} has been approved. " . ($amount ? "Amount: ₱" . number_format($amount, 2) . ". " : "") . "It will be processed shortly.",
            'processing' => "Your refund for order #{$reservationId} is being processed. " . ($amount ? "Amount: ₱" . number_format($amount, 2) . ". " : "") . "Please wait 3-5 business days.",
            'completed'  => "Your refund for order #{$reservationId} has been completed. " . ($amount ? "Amount: ₱" . number_format($amount, 2) . ". " : "") . "Thank you!",
            'rejected'   => "Your refund request for order #{$reservationId} was not approved. " . ($message ? "Reason: {$message}" : "Please contact the clinic for more info."),
        ];
        
        $body = $bodies[$status] ?? "Your refund for order #{$reservationId} has been updated. Status: " . ucfirst($status);
        
        notifyUser($pdo, $userId, $title, $body, 'refund', 'my-reservations.php', $reservationId);
    } catch (Exception $e) {
        error_log("sendRefundNotification error: " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════
// RIDER FILE UPLOAD HELPER
// ════════════════════════════════════════════════════════════════
function handleDeliveryProofUpload(int $orderId): ?string
{
    if (!isset($_FILES['delivery_proof']) || $_FILES['delivery_proof']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES['delivery_proof'];

    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $uploadDir = __DIR__ . '/../uploads/delivery_proofs/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'proof_' . $orderId . '_' . time() . '_' . uniqid() . '.' . strtolower($ext);
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) return null;

    return 'uploads/delivery_proofs/' . $filename;
}

// ════════════════════════════════════════════════════════════════
// MAIN ROUTER
// ════════════════════════════════════════════════════════════════
try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════
    //  GET
    // ════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        if (!canViewReservations()) respond(false, 'No permission to view orders', null, 403);

        $action = $_GET['action'] ?? 'list';

        if (isset($_GET['get_permissions'])) {
            $userRole = $_SESSION['role'] ?? '';
            $hrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role = 'HR' AND status = 'Active'");
            $hrStmt->execute([$clinicId]);
            $hasHR = $hrStmt->fetchColumn() > 0;
            $permissions = [
                'view'=>canViewReservations(),'create'=>canCreateReservations(),
                'edit'=>canEditReservations(),'delete'=>canDeleteReservations(),
                'approve'=>canApproveReservations(),'reject'=>canRejectReservations(),
            ];
            if ($userRole === 'ClinicAdmin' && $hasHR && !$permissions['edit']) {
                $permissions = ['view'=>true,'create'=>false,'edit'=>false,'delete'=>false,'approve'=>true,'reject'=>true];
            }
            respond(true, '', [
                'role'=>$userRole,'permissions'=>$permissions,'hasHR'=>$hasHR,
                'isOwner'=>($userRole==='ClinicAdmin' && !$hasHR),'user_id'=>$_SESSION['user_id'],
            ]);
        }

        if ($action === 'riders_list') {
            $stmt = $pdo->prepare("
                SELECT id, name, phone, vehicle_type, plate_number, is_available
                FROM riders WHERE clinic_id=? AND status='active'
                ORDER BY is_available DESC, name ASC
            ");
            $stmt->execute([$clinicId]);
            respond(true, '', $stmt->fetchAll(PDO::FETCH_ASSOC));
        }


// ════════════════════════════════════════════════════════════════
// ✅ RIDER AVAILABILITY — para sa UI time slots picker
// ════════════════════════════════════════════════════════════════
if ($action === 'rider_availability') {
    $riderId = (int)($_GET['rider_id'] ?? 0);
    $date    = $_GET['date'] ?? date('Y-m-d');

    if (!$riderId) respond(false, 'Rider ID required', null, 400);

    // ✅ Get rider info
    $rStmt = $pdo->prepare("
        SELECT id, name, phone, vehicle_type, plate_number, 
               is_available, employee_id
        FROM riders
        WHERE id = ? AND clinic_id = ? AND status = 'active'
    ");
    $rStmt->execute([$riderId, $clinicId]);
    $rider = $rStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) respond(false, 'Rider not found', null, 404);

    // ✅ Get rider's working schedule (from employee_schedules)
    $schedule = null;
    if ($rider['employee_id']) {
        $sStmt = $pdo->prepare("
            SELECT time_in, time_out, break_start, break_end, schedule_name
            FROM employee_schedules
            WHERE employee_id = ?
              AND clinic_id = ?
              AND is_active = 1
              AND (effective_from IS NULL OR effective_from <= ?)
              AND (effective_to   IS NULL OR effective_to   >= ?)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $sStmt->execute([$rider['employee_id'], $clinicId, $date, $date]);
        $row = $sStmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $schedule = [
                'time_in'       => substr($row['time_in'], 0, 5),
                'time_out'      => substr($row['time_out'], 0, 5),
                'break_start'   => $row['break_start'] ? substr($row['break_start'], 0, 5) : null,
                'break_end'     => $row['break_end']   ? substr($row['break_end'], 0, 5)   : null,
                'schedule_name' => $row['schedule_name'],
            ];
        }
    }

    // ✅ Get booked deliveries for that date
    $bStmt = $pdo->prepare("
        SELECT id, reservation_code,
               TIME_FORMAT(delivery_time_start, '%H:%i') AS delivery_time_start,
               TIME_FORMAT(delivery_time_end, '%H:%i')   AS delivery_time_end,
               delivery_status, status
        FROM reservations
        WHERE assigned_rider_id = ?
          AND clinic_id = ?
          AND delivery_date = ?
          AND status NOT IN ('delivered','completed','cancelled')
        ORDER BY delivery_time_start
    ");
    $bStmt->execute([$riderId, $clinicId, $date]);
    $booked = $bStmt->fetchAll(PDO::FETCH_ASSOC);

    respond(true, '', [
        'rider'    => $rider,
        'date'     => $date,
        'schedule' => $schedule,
        'booked'   => $booked,
        'count'    => count($booked),
    ]);
}

        if ($action === 'details') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) respond(false, 'Order ID required', null, 400);
            $order = fetchOrder($pdo, $id, $clinicId);
            if (!$order) respond(false, 'Order not found', null, 404);
            respond(true, '', $order);
        }

        if ($action === 'list') {
            $status   = $_GET['status']   ?? '';
            $type     = $_GET['type']     ?? '';
            $payment  = $_GET['payment']  ?? '';
            $date     = $_GET['date']     ?? '';
            $search   = trim($_GET['search'] ?? '');
            $limit    = min((int)($_GET['limit'] ?? 200), 500);
            $offset   = max((int)($_GET['offset'] ?? 0), 0);

            $sql = "
                SELECT r.*,
                       CONCAT(u.first_name,' ',u.last_name) AS user_name,
                       u.email AS user_email, u.contact AS user_contact,
                       p.name AS product_name, p.category AS product_category, p.image AS product_image,
                       rd.name AS rider_name
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN products p ON r.product_id = p.id
                LEFT JOIN riders rd ON r.assigned_rider_id = rd.id
                WHERE r.clinic_id = ?
            ";
            $params = [$clinicId];
            if ($status !== '') {
                if ($status === 'active') {
                    $sql .= " AND r.status NOT IN ('completed','delivered','cancelled')";
                } else {
                    $sql .= " AND r.status = ?";
                    $params[] = $status;
                }
            }
            if ($type !== '')    { $sql .= " AND r.fulfillment_type = ?"; $params[] = $type; }
            if ($payment !== '') { $sql .= " AND r.payment_status = ?";   $params[] = $payment; }
            if ($date !== '')    { $sql .= " AND DATE(r.created_at) = ?"; $params[] = $date; }
            if ($search !== '') {
                $sql .= " AND (r.reservation_code LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR p.name LIKE ?)";
                $t = "%$search%";
                array_push($params, $t, $t, $t, $t);
            }
            $sql .= " ORDER BY r.created_at DESC LIMIT {$limit} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['total_amount']       = (float)$r['total_amount'];
                $r['downpayment_amount'] = (float)$r['downpayment_amount'];
                $r['balance_amount']     = (float)$r['balance_amount'];
                $r['delivery_fee']       = (float)$r['delivery_fee'];
                $r['collected_amount']   = $r['collected_amount'] !== null ? (float)$r['collected_amount'] : null;
                $r['quantity']           = (int)$r['quantity'];
                addFormattedDates($r);
            }
            respond(true, '', ['orders'=>$rows, 'count'=>count($rows), 'offset'=>$offset, 'limit'=>$limit]);
        }

// ════════════════════════════════════════════════════════════════
//  LIST REFUNDS (Clinic)
// ════════════════════════════════════════════════════════════════
if ($action === 'list_refunds') {
    $status = $_GET['status'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 100), 200);

$sql = "
    SELECT rr.*,
           COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown Customer') AS user_name,
           u.email AS user_email,
           u.contact AS user_contact,
           r.reservation_code,
           r.total_amount AS order_total,
           r.payment_status AS order_payment_status,
           r.paymongo_payment_id,
           r.status AS order_status,
           r.fulfillment_type,
           r.delivered_at,
           p.name AS product_name,
           p.image AS product_image,
           cb.first_name AS processed_by_first,
           cb.last_name AS processed_by_last
    FROM refund_requests rr
    LEFT JOIN users u ON rr.user_id = u.id
    LEFT JOIN reservations r ON rr.reservation_id = r.id
    LEFT JOIN products p ON r.product_id = p.id
    LEFT JOIN users cb ON rr.processed_by = cb.id
    WHERE rr.clinic_id = ?
      AND rr.reservation_id IS NOT NULL
      AND rr.reservation_id > 0
";
    $params = [$clinicId];

    if ($status !== '') {
        $sql .= " AND rr.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY rr.created_at DESC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Format each refund
    foreach ($refunds as &$rf) {
        // Numbers
        $rf['amount']      = (float)$rf['amount'];
        $rf['order_total'] = $rf['order_total'] !== null ? (float)$rf['order_total'] : 0;

        // Dates
        $rf['created_at_formatted']   = !empty($rf['created_at'])   ? date('M d, Y • g:i A', strtotime($rf['created_at']))   : null;
        $rf['processed_at_formatted'] = !empty($rf['processed_at']) ? date('M d, Y • g:i A', strtotime($rf['processed_at'])) : null;

        // Processed by name
        $rf['processed_by_name'] = trim(($rf['processed_by_first'] ?? '').' '.($rf['processed_by_last'] ?? ''));

        // Evidence: from separate column
        $rf['evidence_image']        = null;
        $rf['evidence_images_array'] = [];

        if (!empty($rf['evidence_path'])) {
            $rf['evidence_image']        = $rf['evidence_path'];
            $rf['evidence_images_array'] = [$rf['evidence_path']];
        }

        if (!empty($rf['evidence_images'])) {
            $decoded = json_decode($rf['evidence_images'], true);
            if (is_array($decoded)) {
                $valid = array_values(array_filter($decoded, fn($p) => !empty($p) && strpos($p, 'uploads/') === 0));
                if (!empty($valid)) {
                    $rf['evidence_images_array'] = $valid;
                    $rf['evidence_image']        = $valid[0];
                }
            }
        }

        // Fallback: extract from details (old records)
        if (empty($rf['evidence_image']) && !empty($rf['details'])) {
            if (preg_match('/\[Evidence:\s*([^\]]+)\]/', $rf['details'], $m)) {
                $path = trim($m[1]);
                if (strpos($path, 'uploads/') === 0) {
                    $rf['evidence_image']        = $path;
                    $rf['evidence_images_array'] = [$path];
                }
                $rf['details'] = trim(preg_replace('/\n?\[Evidence:\s*[^\]]+\]/', '', $rf['details']));
            }
        }

        // Return method formatted
        $rf['return_method'] = $rf['return_method'] ?? 'dropoff';

        $rf['return_scheduled_date_formatted'] = !empty($rf['return_scheduled_date'])
            ? date('M d, Y', strtotime($rf['return_scheduled_date']))
            : null;

        $rf['return_scheduled_time_formatted'] = !empty($rf['return_scheduled_time'])
            ? date('g:i A', strtotime($rf['return_scheduled_time']))
            : null;

        // Ensure fields present (avoid JS "undefined")
        $rf['return_address']        = $rf['return_address'] ?? null;
        $rf['return_contact_name']   = $rf['return_contact_name'] ?? null;
        $rf['return_contact_phone']  = $rf['return_contact_phone'] ?? null;
        $rf['return_notes']          = $rf['return_notes'] ?? null;
        $rf['return_status']         = $rf['return_status'] ?? 'pending';
        $rf['paymongo_refund_id']    = $rf['paymongo_refund_id'] ?? null;
        $rf['refund_date']           = $rf['refund_date'] ?? null;
        $rf['refund_amount_actual']  = $rf['refund_amount_actual'] !== null ? (float)$rf['refund_amount_actual'] : null;
    }
    unset($rf);

    respond(true, '', ['refunds' => $refunds, 'count' => count($refunds)]);
}

// ════════════════════════════════════════════════════════════════
// ✅ PHASE 5: LIST REMITTANCES (Clinic)
// ════════════════════════════════════════════════════════════════
if ($action === 'list_remittances') {
    $status = $_GET['status'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 100), 200);

    $sql = "
        SELECT 
            cr.id,
            cr.rider_id,
            cr.clinic_id,
            cr.remittance_code,
            cr.amount,
            cr.method,
            cr.status,
            cr.proof_image,
            cr.handed_to,
            cr.notes,
            cr.admin_notes,
            cr.rejection_reason,
            cr.submitted_at,
            cr.completed_at,
            cr.paymongo_checkout_id,
            cr.paymongo_status,
            r.name AS rider_name,
            r.email AS rider_email,
            r.phone AS rider_phone,
            CONCAT(hu.first_name, ' ', hu.last_name) AS handed_to_name
        FROM cod_remittances cr
        LEFT JOIN riders r ON cr.rider_id = r.id
        LEFT JOIN users hu ON cr.handed_to = hu.id
        WHERE cr.clinic_id = ?
    ";
    $params = [$clinicId];

    if ($status !== '') {
        $sql .= " AND cr.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY cr.submitted_at DESC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $remittances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Format each
    foreach ($remittances as &$rm) {
        $rm['amount'] = (float)$rm['amount'];
        $rm['submitted_at_formatted'] = !empty($rm['submitted_at']) 
            ? date('M d, Y • g:i A', strtotime($rm['submitted_at'])) 
            : null;
        $rm['completed_at_formatted'] = !empty($rm['completed_at']) 
            ? date('M d, Y • g:i A', strtotime($rm['completed_at'])) 
            : null;
        $rm['handed_to_name'] = $rm['handed_to_name'] ?? null;
    }
    unset($rm);

    respond(true, '', ['remittances' => $remittances, 'count' => count($remittances)]);
}

// ════════════════════════════════════════════════════════════════
//  REFUND DETAILS (Clinic)
// ════════════════════════════════════════════════════════════════
if ($action === 'refund_details') {
    $refundId = (int)($_GET['id'] ?? 0);
    if (!$refundId) respond(false, 'Refund ID required', null, 400);

    // ✅ FIXED: Removed r.payment_method (not in reservations table)
    // ✅ FIXED: All LEFT JOINs + COALESCE for user_name
    $stmt = $pdo->prepare("
        SELECT rr.*,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown Customer') AS user_name,
               u.email AS user_email,
               u.contact AS user_contact,
               r.reservation_code,
               r.total_amount AS order_total,
               r.downpayment_amount AS order_downpayment,
               r.balance_amount AS order_balance,
               r.payment_status AS order_payment_status,
               r.paymongo_payment_id,
               r.status AS order_status,
               r.fulfillment_type,
               r.delivered_at,
               r.delivery_proof_image,
               p.name AS product_name,
               p.image AS product_image,
               p.category AS product_category,
               cb.first_name AS processed_by_first,
               cb.last_name AS processed_by_last
        FROM refund_requests rr
        LEFT JOIN users u ON rr.user_id = u.id
        LEFT JOIN reservations r ON rr.reservation_id = r.id
        LEFT JOIN products p ON r.product_id = p.id
        LEFT JOIN users cb ON rr.processed_by = cb.id
        WHERE rr.id = ?
          AND rr.clinic_id = ?
          AND rr.reservation_id IS NOT NULL
    ");
    $stmt->execute([$refundId, $clinicId]);
    $refund = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$refund) respond(false, 'Refund request not found', null, 404);

    // ✅ Format
    $refund['amount']               = (float)$refund['amount'];
    $refund['order_total']          = $refund['order_total'] !== null ? (float)$refund['order_total'] : 0;
    $refund['order_downpayment']    = $refund['order_downpayment'] !== null ? (float)$refund['order_downpayment'] : 0;
    $refund['order_balance']        = $refund['order_balance'] !== null ? (float)$refund['order_balance'] : 0;
    $refund['refund_amount_actual'] = $refund['refund_amount_actual'] !== null ? (float)$refund['refund_amount_actual'] : null;

    // Dates
    $refund['created_at_formatted']   = !empty($refund['created_at'])   ? date('M d, Y • g:i A', strtotime($refund['created_at']))   : null;
    $refund['processed_at_formatted'] = !empty($refund['processed_at']) ? date('M d, Y • g:i A', strtotime($refund['processed_at'])) : null;
    $refund['delivered_at_formatted'] = !empty($refund['delivered_at']) ? date('M d, Y • g:i A', strtotime($refund['delivered_at'])) : null;
    $refund['refund_date_formatted']  = !empty($refund['refund_date'])  ? date('M d, Y • g:i A', strtotime($refund['refund_date']))  : null;

    // Processed by
    $refund['processed_by_name'] = trim(($refund['processed_by_first'] ?? '').' '.($refund['processed_by_last'] ?? ''));

    // ✅ Evidence: from separate column
    $refund['evidence_image']        = null;
    $refund['evidence_images_array'] = [];

    if (!empty($refund['evidence_path'])) {
        $refund['evidence_image']        = $refund['evidence_path'];
        $refund['evidence_images_array'] = [$refund['evidence_path']];
    }

    if (!empty($refund['evidence_images'])) {
        $decoded = json_decode($refund['evidence_images'], true);
        if (is_array($decoded)) {
            $valid = array_values(array_filter($decoded, fn($p) => !empty($p) && strpos($p, 'uploads/') === 0));
            if (!empty($valid)) {
                $refund['evidence_images_array'] = $valid;
                $refund['evidence_image']        = $valid[0];
            }
        }
    }

    // ✅ Fallback: extract from details (old records)
    if (empty($refund['evidence_image']) && !empty($refund['details'])) {
        if (preg_match('/\[Evidence:\s*([^\]]+)\]/', $refund['details'], $m)) {
            $path = trim($m[1]);
            if (strpos($path, 'uploads/') === 0) {
                $refund['evidence_image']        = $path;
                $refund['evidence_images_array'] = [$path];
            }
            $refund['details'] = trim(preg_replace('/\n?\[Evidence:\s*[^\]]+\]/', '', $refund['details']));
        }
    }

    // ✅ Return method formatted dates
    $refund['return_method'] = $refund['return_method'] ?? 'dropoff';

    $refund['return_scheduled_date_formatted'] = !empty($refund['return_scheduled_date'])
        ? date('M d, Y', strtotime($refund['return_scheduled_date']))
        : null;

    $refund['return_scheduled_time_formatted'] = !empty($refund['return_scheduled_time'])
        ? date('g:i A', strtotime($refund['return_scheduled_time']))
        : null;

    // ✅ Ensure fields present (avoid JS "undefined")
    $refund['return_address']       = $refund['return_address'] ?? null;
    $refund['return_contact_name']  = $refund['return_contact_name'] ?? null;
    $refund['return_contact_phone'] = $refund['return_contact_phone'] ?? null;
    $refund['return_notes']         = $refund['return_notes'] ?? null;
    $refund['return_status']        = $refund['return_status'] ?? 'pending';
    $refund['paymongo_refund_id']   = $refund['paymongo_refund_id'] ?? null;

    respond(true, '', $refund);
}

        if ($action === 'history') {
            $search   = $_GET['search'] ?? '';
            $fromDate = $_GET['from']   ?? '';
            $toDate   = $_GET['to']     ?? '';
            $status   = $_GET['status'] ?? '';

            $sql = "
                SELECT r.*, CONCAT(u.first_name,' ',u.last_name) AS user_name,
                       u.email AS user_email, p.name AS product_name,
                       p.category AS product_category, p.price AS product_price
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN products p ON r.product_id = p.id
                WHERE r.clinic_id = ?
            ";
            $params = [$clinicId];
            if (!empty($status)) {
                $sql .= " AND r.status = ?";
                $params[] = $status;
            } else {
                $sql .= " AND r.status IN ('completed','cancelled','delivered')";
            }
            if (!empty($search)) {
                $sql .= " AND (CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR r.reservation_code LIKE ?)";
                $t = "%$search%";
                array_push($params, $t, $t, $t, $t);
            }
            if (!empty($fromDate)) { $sql .= " AND DATE(r.created_at) >= ?"; $params[] = $fromDate; }
            if (!empty($toDate))   { $sql .= " AND DATE(r.created_at) <= ?"; $params[] = $toDate; }
            $sql .= " ORDER BY r.created_at DESC LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($history as &$row) {
                $row['formatted_date']     = date('M d, Y', strtotime($row['created_at']));
                $row['total_amount']       = (float)$row['total_amount'];
                $row['downpayment_amount'] = (float)$row['downpayment_amount'];
                $row['balance_amount']     = (float)$row['balance_amount'];
                $row['quantity']           = (int)$row['quantity'];
            }
            echo json_encode($history);
            exit;
        }

        respond(false, 'Unknown action', null, 400);
    }

    // ════════════════════════════════════════════════════════════
    //  POST
    // ════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $data = $_POST;
        if (empty($data)) {
            $json = json_decode(file_get_contents('php://input'), true);
            if (is_array($json)) $data = $json;
        }

        $action = $data['action'] ?? '';

        if (str_starts_with($action, 'rider_')) {
            handleRiderAction($pdo, $data, $action);
            exit;
        }

        if (str_starts_with($action, 'bulk_')) {
            handleBulkAction($pdo, $clinicId, $currentUid, $data, $action);
            exit;
        }

// ✅ Refund actions (Customer + Clinic)
if (in_array($action, ['mark_received', 'request_refund', 'approve_refund', 'reject_refund', 'mark_refunded'])) {
    handleRefundAction($pdo, $clinicId, $currentUid, $data, $action);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// ✅ PHASE 5: CASH REMITTANCE ACTIONS
// ═══════════════════════════════════════════════════════════════
if (in_array($action, ['submit_cash_remittance', 'approve_cash_remittance', 'reject_cash_remittance'])) {
    $riderId = (int)($_SESSION['rider_id'] ?? 0);
    $userId  = $currentUid; // already set above
    handleCashRemittance($pdo, $data, $action, $riderId, $userId, $clinicId);
    exit;
}

if ($action !== '' && $action !== 'create') {
    $reservationId = (int)($data['reservation_id'] ?? 0);
    if (!$reservationId) respond(false, 'Reservation ID required', null, 400);
    handleStatusUpdate($pdo, $clinicId, $currentUid, $data, $action, $reservationId);
    exit;
}

        if (!canCreateReservations()) respond(false, 'You do not have permission to create reservations', null, 403);
        if (empty($data['user_id']) || empty($data['product_id'])) respond(false, 'User and product are required', null, 400);

        $reservationCode = 'RES-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$data['product_id'], $clinicId]);
        $product = $stmt->fetch();
        if (!$product) respond(false, 'Product not found', null, 404);

        $quantity          = (int)($data['quantity'] ?? 1);
        $totalAmount       = $product['price'] * $quantity;
        $downpaymentAmount = $totalAmount * 0.5;
        $balanceAmount     = $totalAmount - $downpaymentAmount;

        $stmt = $pdo->prepare("
            INSERT INTO reservations (
                reservation_code, user_id, product_id, clinic_id, lens_type,
                prescription_id, total_amount, downpayment_amount, balance_amount,
                preferred_date, preferred_time, color_code, color_name, quantity,
                notes, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            $reservationCode, $data['user_id'], $data['product_id'], $clinicId,
            $data['lens_type'] ?? 'frame_only', $data['prescription_id'] ?? null,
            $totalAmount, $downpaymentAmount, $balanceAmount,
            $data['preferred_date'] ?? date('Y-m-d'), $data['preferred_time'] ?? date('H:i:s'),
            $data['color_code'] ?? null, $data['color_name'] ?? null, $quantity,
            $data['notes'] ?? null,
        ]);

        notifyUser($pdo, (int)$data['user_id'], 'New Reservation Created 📝',
            "A new reservation (Code: {$reservationCode}) has been created for you. Please wait for clinic confirmation.",
            'reservation', 'my-reservations.php', (int)$pdo->lastInsertId());

        respond(true, 'Reservation created successfully', ['reservation_code' => $reservationCode]);
    }

    // ════════════════════════════════════════════════════════════
    //  PUT
    // ════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = $_POST;
        $action = $data['action'] ?? '';

        if (str_starts_with($action, 'rider_')) {
            handleRiderAction($pdo, $data, $action);
            exit;
        }

        if (str_starts_with($action, 'bulk_')) {
            handleBulkAction($pdo, $clinicId, $currentUid, $data, $action);
            exit;
        }
        $reservationId = (int)($data['reservation_id'] ?? 0);
        if (!$reservationId) respond(false, 'Reservation ID required', null, 400);
        handleStatusUpdate($pdo, $clinicId, $currentUid, $data, $action, $reservationId);
    }

    respond(false, 'Invalid request method', null, 405);

} catch (Exception $e) {
    respond(false, $e->getMessage(), null, 500);
}


// ════════════════════════════════════════════════════════════════
// ✅ PHASE 2: COD WALLET — Record Collection Helper
// ════════════════════════════════════════════════════════════════
/**
 * Auto-add sa rider wallet kapag may COD collection.
 * 
 * @param PDO    $pdo
 * @param int    $reservationId   Order ID
 * @param int    $riderId         Rider ID (from riders table)
 * @param float  $amount          COD amount na na-collect
 * @param string $orderCode       Reservation code (for notes)
 * @return array Result: ['ok' => bool, 'reason' => ?, 'new_balance' => ?, 'status' => ?]
 */
function recordCodCollection(
    PDO $pdo, 
    int $reservationId, 
    int $riderId, 
    float $amount, 
    string $orderCode
): array {
    // ✅ Validation
    if ($riderId <= 0) {
        return ['ok' => false, 'reason' => 'Invalid rider ID'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'reason' => 'Amount must be positive'];
    }
    
    try {
        // ✅ Idempotency: Check if already recorded
        $check = $pdo->prepare("
            SELECT id FROM rider_wallet_transactions 
            WHERE reservation_id = ? 
              AND type = 'cod_collected'
            LIMIT 1
        ");
        $check->execute([$reservationId]);
        if ($check->fetch()) {
            // Already recorded — skip
            return ['ok' => false, 'reason' => 'Already recorded (idempotent skip)'];
        }
        
        // ✅ Get current rider wallet info
        $rStmt = $pdo->prepare("
            SELECT wallet_balance, wallet_limit 
            FROM riders 
            WHERE id = ? 
            LIMIT 1
        ");
        $rStmt->execute([$riderId]);
        $rider = $rStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) {
            return ['ok' => false, 'reason' => 'Rider not found'];
        }
        
        $oldBalance = (float)($rider['wallet_balance'] ?? 0);
        $limit      = (float)($rider['wallet_limit'] ?? 5000);
        $newBalance = $oldBalance + $amount;
        
        // ✅ Compute wallet status based sa limit
        // < limit*0.8      → normal
        // >= limit*0.8     → warning
        // >= limit         → blocked_cod
        // >= limit*1.6     → blocked_all
        $newStatus = 'normal';
        if ($newBalance >= $limit * 1.6) {
            $newStatus = 'blocked_all';
        } elseif ($newBalance >= $limit) {
            $newStatus = 'blocked_cod';
        } elseif ($newBalance >= $limit * 0.8) {
            $newStatus = 'warning';
        }
        
        // ✅ Update rider wallet
        $pdo->prepare("
            UPDATE riders 
            SET wallet_balance = ?,
                wallet_status  = ?,
                updated_at     = NOW()
            WHERE id = ?
        ")->execute([$newBalance, $newStatus, $riderId]);
        
        // ✅ Insert audit trail
        $noteText = "COD collected for order #{$orderCode}";
        $pdo->prepare("
            INSERT INTO rider_wallet_transactions 
                (rider_id, reservation_id, remittance_id, type, amount, balance_after, notes, created_at)
            VALUES 
                (?, ?, NULL, 'cod_collected', ?, ?, ?, NOW())
        ")->execute([
            $riderId,
            $reservationId,
            $amount,
            $newBalance,
            $noteText
        ]);
        
        return [
            'ok'          => true,
            'new_balance' => $newBalance,
            'old_balance' => $oldBalance,
            'status'      => $newStatus,
            'limit'       => $limit
        ];
        
    } catch (Exception $e) {
        error_log("recordCodCollection error: " . $e->getMessage());
        return ['ok' => false, 'reason' => 'DB error: ' . $e->getMessage()];
    }
}

// ════════════════════════════════════════════════════════════════
// ✅ PHASE 4: CHECK IF RIDER CAN ACCEPT ORDER
// ════════════════════════════════════════════════════════════════
/**
 * Check kung pwedeng tanggapin ng rider ang order base sa wallet status.
 * 
 * @param PDO   $pdo
 * @param int   $riderId
 * @param array $order    Order row (may payment_status, etc.)
 * @return array Result: ['ok' => bool, 'reason' => ?, 'code' => ?]
 */
function canAcceptOrder(PDO $pdo, int $riderId, array $order): array
{
    try {
        // ✅ Get fresh wallet status
        $stmt = $pdo->prepare("
            SELECT wallet_balance, wallet_limit, wallet_status
            FROM riders
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$riderId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) {
            return [
                'ok' => false,
                'reason' => 'Rider not found',
                'code' => 'rider_not_found'
            ];
        }
        
        $balance = (float)($rider['wallet_balance'] ?? 0);
        $status  = $rider['wallet_status'] ?? 'normal';
        
        // ✅ Fresh compute (in case stale ang DB status)
        $limit = (float)($rider['wallet_limit'] ?? 5000);
        if ($limit <= 0) $limit = 5000;
        
        if ($balance >= $limit * 1.6) {
            $status = 'blocked_all';
        } elseif ($balance >= $limit) {
            $status = 'blocked_cod';
        } elseif ($balance >= $limit * 0.8) {
            $status = 'warning';
        } else {
            $status = 'normal';
        }
        
        // ✅ Rule 1: blocked_all — walang exception
        if ($status === 'blocked_all') {
            return [
                'ok' => false,
                'reason' => 'Account restricted. You have ₱' . number_format($balance, 2) . 
                            ' pending COD. Please remit immediately to continue accepting orders.',
                'code' => 'blocked_all',
                'balance' => $balance,
                'status' => $status
            ];
        }
        
        // ✅ Rule 2: blocked_cod — COD orders lang
        if ($status === 'blocked_cod') {
            $isCOD = in_array($order['payment_status'] ?? '', ['cod', 'onsite', 'unpaid', 'partial'], true);
            
            if ($isCOD) {
                return [
                    'ok' => false,
                    'reason' => 'Cannot accept COD orders. You have ₱' . number_format($balance, 2) . 
                                ' pending COD. Please remit to continue.',
                    'code' => 'blocked_cod',
                    'balance' => $balance,
                    'status' => $status
                ];
            }
            // PAID orders — pwede
        }
        
        // ✅ Normal or warning — pwede lahat
        return [
            'ok' => true,
            'balance' => $balance,
            'status' => $status
        ];
        
    } catch (Exception $e) {
        error_log("canAcceptOrder error: " . $e->getMessage());
        // Fail-open: huwag i-block kung may DB error
        return ['ok' => true, 'reason' => 'Check skipped due to error'];
    }
}

// ════════════════════════════════════════════════════════════════
// ✅ PHASE 5: CASH REMITTANCE HANDLER
// ════════════════════════════════════════════════════════════════
function handleCashRemittance(PDO $pdo, array $data, string $action, int $riderId, int $userId, int $clinicId): void
{
    // ═══════════════════════════════════════════════════════════
    // 1. SUBMIT CASH REMITTANCE (Rider)
    // ═══════════════════════════════════════════════════════════
    if ($action === 'submit_cash_remittance') {
        
        // ✅ Get rider wallet
        $rStmt = $pdo->prepare("
            SELECT name, email, wallet_balance, wallet_status
            FROM riders
            WHERE id = ? AND clinic_id = ? AND status = 'active'
            LIMIT 1
        ");
        $rStmt->execute([$riderId, $clinicId]);
        $rider = $rStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) {
            respond(false, 'Rider not found or not active', null, 404);
        }
        
        $balance = (float)$rider['wallet_balance'];
        
        if ($balance <= 0) {
            respond(false, 'Walang balance na ire-remit', null, 400);
        }
        
        // ✅ Idempotency: Check if may pending cash remittance na
        $dupStmt = $pdo->prepare("
            SELECT id, remittance_code
            FROM cod_remittances
            WHERE rider_id = ?
              AND method = 'cash'
              AND status = 'pending'
            ORDER BY id DESC
            LIMIT 1
        ");
        $dupStmt->execute([$riderId]);
        $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            respond(false, 'May pending cash remittance ka na. Hintayin ang approval.', [
                'code' => 'duplicate_pending',
                'existing_code' => $existing['remittance_code']
            ], 409);
        }
        
        // ✅ Get handed_to + notes
        $handedTo = (int)($data['handed_to'] ?? 0);
        $notes = trim($data['notes'] ?? '');
        
        if (!$handedTo) {
            respond(false, 'Please select kung kanino mo ibinigay ang cash', null, 400);
        }
        
        // ✅ Get admin name for validation
        $aStmt = $pdo->prepare("
            SELECT id, CONCAT(first_name, ' ', last_name) AS name
            FROM users
            WHERE id = ? AND clinic_id = ? AND status = 'Active'
            LIMIT 1
        ");
        $aStmt->execute([$handedTo, $clinicId]);
        $admin = $aStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            respond(false, 'Invalid admin selected', null, 400);
        }
        
        // ✅ Handle proof photo upload (required for cash)
        $proofPath = null;
        
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['proof_photo'];
            
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                respond(false, 'Invalid image type. JPG, PNG, or WEBP only.', null, 400);
            }
            
            if ($file['size'] > 5 * 1024 * 1024) {
                respond(false, 'Image too large. Maximum 5MB.', null, 400);
            }
            
            $uploadDir = __DIR__ . '/../uploads/remittance_proofs/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $ext = 'jpg';
            }
            
            $filename = 'remit_' . $riderId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                respond(false, 'Failed to save proof photo', null, 500);
            }
            
            $proofPath = 'uploads/remittance_proofs/' . $filename;
        } else {
            respond(false, 'Proof photo is required for cash remittance', null, 400);
        }
        
        // ✅ Generate remittance code
        $remittanceCode = 'RMT-CASH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        
        // ✅ Insert record
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO cod_remittances 
                    (rider_id, clinic_id, remittance_code, amount, method, status,
                     proof_image, handed_to, notes, submitted_at)
                VALUES (?, ?, ?, ?, 'cash', 'pending', ?, ?, ?, NOW())
            ");
            $insertStmt->execute([
                $riderId,
                $clinicId,
                $remittanceCode,
                $balance,
                $proofPath,
                $handedTo,
                $notes ?: null
            ]);
            
            $remittanceId = (int)$pdo->lastInsertId();
            
            // ✅ Notify clinic admins
            $adminUsersStmt = $pdo->prepare("
                SELECT id FROM users
                WHERE clinic_id = ? 
                  AND role IN ('ClinicAdmin', 'Finance', 'SCM')
                  AND status = 'Active'
                LIMIT 10
            ");
            $adminUsersStmt->execute([$clinicId]);
            $clinicAdmins = $adminUsersStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($clinicAdmins as $adminId) {
                $title = '💰 New Cash Remittance';
                $message = "{$rider['name']} submitted ₱" . number_format($balance, 2) . 
                           " cash remittance ({$remittanceCode}). Review and approve.";
                
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'remittance', 'main.php?view=reservations&tab=remittances', 0, NOW())
                ")->execute([$adminId, $title, $message]);
            }
            
            respond(true, 'Cash remittance submitted. Waiting for clinic approval.', [
                'remittance_id'   => $remittanceId,
                'remittance_code' => $remittanceCode,
                'amount'          => $balance,
                'handed_to'       => $admin['name']
            ]);
            
        } catch (Exception $e) {
            error_log("Cash remittance insert error: " . $e->getMessage());
            respond(false, 'Database error: ' . $e->getMessage(), null, 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // 2. APPROVE CASH REMITTANCE (Clinic)
    // ═══════════════════════════════════════════════════════════
    if ($action === 'approve_cash_remittance') {
        
        if (!canEditReservations()) {
            respond(false, 'No permission', null, 403);
        }
        
        $remittanceId = (int)($data['remittance_id'] ?? 0);
        $adminNotes = trim($data['admin_notes'] ?? '');
        
        if (!$remittanceId) {
            respond(false, 'Remittance ID required', null, 400);
        }
        
        // ✅ Get remittance
        $stmt = $pdo->prepare("
            SELECT cr.*, r.name AS rider_name, r.user_id AS rider_user_id,
                   r.wallet_balance, r.wallet_limit
            FROM cod_remittances cr
            JOIN riders r ON cr.rider_id = r.id
            WHERE cr.id = ? AND cr.clinic_id = ?
            LIMIT 1
        ");
        $stmt->execute([$remittanceId, $clinicId]);
        $rem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rem) {
            respond(false, 'Remittance not found', null, 404);
        }
        
        if ($rem['status'] !== 'pending') {
            respond(false, 'Remittance is no longer pending', null, 400);
        }
        
        if ($rem['method'] !== 'cash') {
            respond(false, 'Only cash remittances need approval', null, 400);
        }
        
        $riderId = (int)$rem['rider_id'];
        $amount  = (float)$rem['amount'];
        $oldBalance = (float)$rem['wallet_balance'];
        $limit = (float)$rem['wallet_limit'];
        if ($limit <= 0) $limit = 5000;
        
        $newBalance = max(0, $oldBalance - $amount);
        
        // Recompute wallet status
        $newStatus = 'normal';
        if ($newBalance >= $limit * 1.6) {
            $newStatus = 'blocked_all';
        } elseif ($newBalance >= $limit) {
            $newStatus = 'blocked_cod';
        } elseif ($newBalance >= $limit * 0.8) {
            $newStatus = 'warning';
        }
        
        $pdo->beginTransaction();
        
        try {
            // ✅ Update remittance
            $pdo->prepare("
                UPDATE cod_remittances
                SET status = 'completed',
                    completed_at = NOW(),
                    approved_by = ?,
                    admin_notes = ?
                WHERE id = ?
            ")->execute([$userId, $adminNotes ?: null, $remittanceId]);
            
            // ✅ Update rider wallet
            $pdo->prepare("
                UPDATE riders
                SET wallet_balance = ?,
                    wallet_status = ?,
                    last_remit_at = NOW()
                WHERE id = ?
            ")->execute([$newBalance, $newStatus, $riderId]);
            
            // ✅ Insert wallet transaction (audit)
            $pdo->prepare("
                INSERT INTO rider_wallet_transactions 
                    (rider_id, reservation_id, remittance_id, type, amount, balance_after, notes, created_at)
                VALUES (?, NULL, ?, 'remit_cash', ?, ?, ?, NOW())
            ")->execute([
                $riderId,
                $remittanceId,
                -$amount,
                $newBalance,
                'Cash remittance — ' . $rem['remittance_code']
            ]);
            
            $pdo->commit();
            
            // ✅ Notify rider
            if (!empty($rem['rider_user_id'])) {
                $title = '✅ Cash Remittance Approved';
                $message = "Your cash remittance of ₱" . number_format($amount, 2) . 
                           " has been approved. New balance: ₱" . number_format($newBalance, 2) . ".";
                
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'wallet', 'rider_dashboard.php', 0, NOW())
                ")->execute([$rem['rider_user_id'], $title, $message]);
            }
            
            respond(true, 'Cash remittance approved. Wallet cleared.', [
                'remittance_id' => $remittanceId,
                'new_balance'   => $newBalance,
                'new_status'    => $newStatus
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Approve cash remittance error: " . $e->getMessage());
            respond(false, 'Transaction failed: ' . $e->getMessage(), null, 500);
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // 3. REJECT CASH REMITTANCE (Clinic)
    // ═══════════════════════════════════════════════════════════
    if ($action === 'reject_cash_remittance') {
        
        if (!canEditReservations()) {
            respond(false, 'No permission', null, 403);
        }
        
        $remittanceId = (int)($data['remittance_id'] ?? 0);
        $reason = trim($data['rejection_reason'] ?? '');
        
        if (!$remittanceId) {
            respond(false, 'Remittance ID required', null, 400);
        }
        if (!$reason) {
            respond(false, 'Rejection reason is required', null, 400);
        }
        
        // ✅ Get remittance
        $stmt = $pdo->prepare("
            SELECT cr.*, r.name AS rider_name, r.user_id AS rider_user_id
            FROM cod_remittances cr
            JOIN riders r ON cr.rider_id = r.id
            WHERE cr.id = ? AND cr.clinic_id = ?
            LIMIT 1
        ");
        $stmt->execute([$remittanceId, $clinicId]);
        $rem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rem) {
            respond(false, 'Remittance not found', null, 404);
        }
        
        if ($rem['status'] !== 'pending') {
            respond(false, 'Remittance is no longer pending', null, 400);
        }
        
        // ✅ Update remittance to rejected
        $pdo->prepare("
            UPDATE cod_remittances
            SET status = 'rejected',
                completed_at = NOW(),
                approved_by = ?,
                rejection_reason = ?
            WHERE id = ?
        ")->execute([$userId, $reason, $remittanceId]);
        
        // ✅ Notify rider (wallet NOT touched — intact pa rin)
        if (!empty($rem['rider_user_id'])) {
            $title = '❌ Cash Remittance Rejected';
            $message = "Your cash remittance of ₱" . number_format($rem['amount'], 2) . 
                       " was rejected. Reason: {$reason}. Please contact the clinic.";
            
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                VALUES (?, ?, ?, 'wallet', 'rider_dashboard.php', 0, NOW())
            ")->execute([$rem['rider_user_id'], $title, $message]);
        }
        
        respond(true, 'Cash remittance rejected. Rider notified.', [
            'remittance_id' => $remittanceId
        ]);
    }
    
    // Fallback
    respond(false, 'Unknown cash remittance action', null, 400);
}
// ════════════════════════════════════════════════════════════════
// RIDER ACTION HANDLER
// ════════════════════════════════════════════════════════════════
function handleRiderAction(PDO $pdo, array $data, string $action): void
{
    if (!isset($_SESSION['rider_id']) || !isset($_SESSION['user_id'])) {
        respond(false, 'Rider not authenticated', null, 401);
    }

    $riderId  = (int)$_SESSION['rider_id'];
    $userId   = (int)$_SESSION['user_id'];
    $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
    $orderId  = (int)($data['reservation_id'] ?? 0);

    if (!$orderId) respond(false, 'Order ID required', null, 400);

    $stmt = $pdo->prepare("
        SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) AS customer_name
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.assigned_rider_id = ? AND r.clinic_id = ?
    ");
    $stmt->execute([$orderId, $riderId, $clinicId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) respond(false, 'Order not found or not assigned to you', null, 404);

    $customerId = (int)$order['user_id'];
    $code       = $order['reservation_code'];

if ($action === 'rider_pickup') {
    if ($order['delivery_status'] !== 'assigned') {
        respond(false, 'Order is not in assigned state', null, 400);
    }
    
    // ═══════════════════════════════════════════════════════════
    // ✅ PHASE 4: CHECK KUNG MAKA-ACCEPT ANG RIDER
    // ═══════════════════════════════════════════════════════════
    $acceptCheck = canAcceptOrder($pdo, $riderId, $order);
    if (!$acceptCheck['ok']) {
        respond(
            false,
            $acceptCheck['reason'],
            [
                'code'    => $acceptCheck['code'],
                'balance' => $acceptCheck['balance'] ?? 0,
                'status'  => $acceptCheck['status'] ?? 'normal'
            ],
            403
        );
    }
    
    // ✅ Generate tracking number if not yet set
    $tracking = $order['tracking_number'];
    if (empty($tracking)) {
        $tracking = generateTrackingNumber($pdo);
    }

    $pdo->prepare("
        UPDATE reservations 
        SET delivery_status='picked_up', 
            status='dispatched',
            tracking_number = ?,
            updated_at=NOW() 
        WHERE id=?
    ")->execute([$tracking, $orderId]);

    notifyUser($pdo, $customerId, 'Order Picked Up 📦',
        "Your order #{$code} has been picked up by the rider. Tracking: {$tracking}",
        'reservation', 'my-reservations.php', $orderId);

    respond(true, 'Marked as picked up.', ['tracking_number' => $tracking]);
}

if ($action === 'rider_transit') {
    if ($order['delivery_status'] !== 'picked_up') {
        respond(false, 'Order is not picked up yet', null, 400);
    }
    
    // ✅ Generate tracking if not yet set (fallback)
    $tracking = $order['tracking_number'];
    if (empty($tracking)) {
        $tracking = generateTrackingNumber($pdo);
    }

    $pdo->prepare("
        UPDATE reservations 
        SET delivery_status='in_transit', 
            status='dispatched',
            tracking_number = ?,
            updated_at=NOW() 
        WHERE id=?
    ")->execute([$tracking, $orderId]);

    notifyUser($pdo, $customerId, 'Order In Transit 🚚',
        "Your order #{$code} is on the way! Tracking: {$tracking}",
        'reservation', 'my-reservations.php', $orderId);

    respond(true, 'Order is now in transit.', ['tracking_number' => $tracking]);
}

// ── RIDER DELIVER (with proof + COD collection) ──
if ($action === 'rider_deliver') {
    if ($order['delivery_status'] !== 'in_transit') {
        respond(false, 'Order is not in transit', null, 400);
    }

    $proofPath = handleDeliveryProofUpload($orderId);
    
    if (!$proofPath) {
        respond(false, 'Proof of delivery image is required. Please take a photo.', null, 400);
    }

    $notes           = trim($data['notes'] ?? '');
    $collectedAmount = (float)($data['collected_amount'] ?? 0);
    $changeAmount    = (float)($data['change_amount'] ?? 0);
    $totalDue        = (float)$order['total_amount'];
    $isCOD           = in_array($order['payment_status'], ['cod', 'onsite', 'unpaid', 'partial'], true);

    // ═══════════════════════════════════════════════════════════
    // ✅ COD VALIDATION: Block if payment is insufficient
    // ═══════════════════════════════════════════════════════════
    if ($isCOD) {
        if ($collectedAmount < $totalDue - 0.01) {
            $short = $totalDue - $collectedAmount;
            respond(
                false, 
                'Insufficient payment. Required: ₱' . number_format($totalDue, 2) . 
                '. Short by ₱' . number_format($short, 2) . '.', 
                null, 
                400
            );
        }
    }

    // ✅ Collection note
    $collectionNote = '';
    if ($isCOD) {
        $collectionNote = "\n[COD Collected: ₱" . number_format($collectedAmount, 2) . "]";
        if ($changeAmount > 0.01) {
            $collectionNote .= "\n[Change Given: ₱" . number_format($changeAmount, 2) . "]";
        }
    }

    $paymentUpdate = $isCOD ? ", payment_status='paid'" : "";

    $collectorUpdate = "";
    if ($isCOD) {
        $collectorUpdate = ", collected_amount = " . $collectedAmount . 
                          ", change_amount = " . $changeAmount .
                          ", collected_at = NOW()" .
                          ", collected_by = ?";
    }

    if ($collectorUpdate) {
        $pdo->prepare("
            UPDATE reservations 
            SET status='delivered',
                delivery_status='delivered',
                delivered_at=NOW()
                {$paymentUpdate},
                delivery_proof_image = ?,
                delivery_proof_notes = ?,
                delivery_proof_at    = NOW()
                {$collectorUpdate},
                notes = CONCAT(IFNULL(notes,''), ?),
                updated_at=NOW()
            WHERE id=?
        ")->execute([
            $proofPath,
            $notes ?: null,
            $userId,
            ($notes ? "\n[Rider: $notes]" : '') . $collectionNote,
            $orderId
        ]);
    } else {
        $pdo->prepare("
            UPDATE reservations 
            SET status='delivered',
                delivery_status='delivered',
                delivered_at=NOW()
                {$paymentUpdate},
                delivery_proof_image = ?,
                delivery_proof_notes = ?,
                delivery_proof_at    = NOW(),
                notes = CONCAT(IFNULL(notes,''), ?),
                updated_at=NOW()
            WHERE id=?
        ")->execute([
            $proofPath,
            $notes ?: null,
            $notes ? "\n[Rider: $notes]" : '',
            $orderId
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ✅ PHASE 2: AUTO-ADD SA WALLET
    // ═══════════════════════════════════════════════════════════
    $walletResult = null;
    if ($isCOD && $collectedAmount > 0) {
        $walletResult = recordCodCollection(
            $pdo,
            $orderId,
            $riderId,       // ← from session
            $collectedAmount,
            $code
        );
        
        // Non-blocking: log error lang kung fail
        if (!$walletResult['ok']) {
            error_log("Wallet update failed for order #{$code}: " . ($walletResult['reason'] ?? 'Unknown'));
        }
    }

    // ✅ Notify customer
    $msg = "Your order #{$code} has been delivered. Thank you!";
    if ($isCOD) {
        $msg .= " Payment received: ₱" . number_format($collectedAmount, 2) . ".";
        if ($changeAmount > 0.01) {
            $msg .= " Change given: ₱" . number_format($changeAmount, 2) . ".";
        }
    }

    notifyUser($pdo, $customerId, 'Order Delivered 🎉', $msg,
        'reservation', 'my-reservations.php', $orderId);

    // ✅ Notify rider ng bagong balance (kung successful)
    if ($walletResult && $walletResult['ok']) {
        $newBal = number_format($walletResult['new_balance'], 2);
        $warnMsg = "";
        
        if ($walletResult['status'] === 'warning') {
            $warnMsg = " ⚠️ Nearing limit. Please remit soon.";
        } elseif ($walletResult['status'] === 'blocked_cod') {
            $warnMsg = " 🚫 COD orders blocked. Remit to unlock.";
        } elseif ($walletResult['status'] === 'blocked_all') {
            $warnMsg = " 🚫 All orders blocked. Remit immediately.";
        }
        
        notifyUser($pdo, $userId, '💰 COD Balance Updated',
            "COD ₱" . number_format($collectedAmount, 2) . " added for order #{$code}. New balance: ₱{$newBal}.{$warnMsg}",
            'wallet', 'rider_dashboard.php', $orderId);
    }

    respond(true, 'Order delivered with proof.', [
        'collected_amount' => $collectedAmount,
        'change_amount'    => $changeAmount,
        'total_due'        => $totalDue,
        'wallet'           => $walletResult
    ]);
}

    respond(false, 'Unknown rider action', null, 400);
}

// ════════════════════════════════════════════════════════════════
// ✅ HANDOVER PHOTOS — Save base64 images with timestamps
// ════════════════════════════════════════════════════════════════
function saveHandoverPhotos(int $orderId, string $photosJson): array
{
    if (empty($photosJson)) return [];
    
    $photos = json_decode($photosJson, true);
    if (!is_array($photos) || empty($photos)) return [];
    
    $uploadDir = __DIR__ . '/../uploads/handover_photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $saved = [];
    $maxPhotos = 3;
    
    foreach (array_slice($photos, 0, $maxPhotos) as $idx => $photo) {
        if (empty($photo['data'])) continue;
        
        $dataUrl = $photo['data'];
        
        // ✅ Validate data URL format
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/i', $dataUrl, $matches)) {
            continue;
        }
        
        $ext  = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $data = base64_decode($matches[2]);
        
        if ($data === false || strlen($data) > 5 * 1024 * 1024) {
            continue; // invalid or >5MB
        }
        
        $filename = 'handover_' . $orderId . '_' . time() . '_' . $idx . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $filepath = $uploadDir . $filename;
        
        if (file_put_contents($filepath, $data) !== false) {
            $saved[] = [
                'path'      => 'uploads/handover_photos/' . $filename,
                'timestamp' => $photo['timestamp'] ?? date('M d, Y (D) H:i:s')
            ];
        }
    }
    
    return $saved;
}
function handleStatusUpdate(PDO $pdo, int $clinicId, int $currentUid, array $data, string $action, int $reservationId): void
{
    $order = fetchOrder($pdo, $reservationId, $clinicId);
    if (!$order) respond(false, 'Order not found', null, 404);

    $userId = (int)$order['user_id_val'];
    $code   = $order['reservation_code'];
    $prod   = $order['product_name'];

    if ($action === 'confirm') {
        if (!canApproveReservations()) respond(false, 'No permission to confirm', null, 403);
        if ($order['status'] !== 'pending') respond(false, 'Order cannot be confirmed in current status', null, 400);

        $pdo->prepare("UPDATE reservations SET status='confirmed', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Order Confirmed ✅',
            "Your order #{$code} has been CONFIRMED! Please proceed with the downpayment of ₱" . number_format($order['downpayment_amount'], 2) . ".",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Order confirmed. User notified.');
    }

    if ($action === 'ready_pickup') {
        if (!canApproveReservations()) respond(false, 'No permission', null, 403);
        if ($order['fulfillment_type'] !== 'pickup') respond(false, 'Not a pickup order', null, 400);

        $pdo->prepare("UPDATE reservations SET status='ready_for_pickup', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Ready for Pickup 🏪',
            "Your order #{$code} is ready for pickup at the clinic.",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Marked as ready for pickup.');
    }

    if ($action === 'preparing') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);
        if ($order['fulfillment_type'] !== 'delivery') respond(false, 'Not a delivery order', null, 400);

        $pdo->prepare("UPDATE reservations SET status='preparing', delivery_status='preparing', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Order Preparing 📦',
            "Your order #{$code} is now being prepared for delivery.",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Marked as preparing.');
    }

    // ═══════════════════════════════════════════════════════════
    // ASSIGN RIDER — with LIVE HANDOVER PHOTOS
    // ═══════════════════════════════════════════════════════════
    if ($action === 'assign_rider') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);

        $riderId   = (int)($data['rider_id'] ?? 0);
        $notes     = trim($data['delivery_notes'] ?? '');
        $eta       = $data['delivery_date'] ?? null;
        $timeStart = $data['delivery_time_start'] ?? null;
        $timeEnd   = $data['delivery_time_end'] ?? null;
        $handoverNotes = trim($data['handover_notes'] ?? '');
        $handoverPhotosJson = $data['handover_photos'] ?? '';

        // ✅ Basic validation
        // ✅ Basic validation
        if (!$riderId)   respond(false, 'Please select a rider', null, 400);
        if (!$eta)       respond(false, 'Delivery date is required', null, 400);
        if (!$timeStart) respond(false, 'Delivery time start is required', null, 400);
        if (!$timeEnd)   respond(false, 'Delivery time end is required', null, 400);

        // ═══════════════════════════════════════════════════════════
        // ✅ PHASE 4: CHECK KUNG MAKA-ACCEPT ANG RIDER
        // ═══════════════════════════════════════════════════════════
        $acceptCheck = canAcceptOrder($pdo, $riderId, $order);
        if (!$acceptCheck['ok']) {
            // Get rider name for message
            $rNameStmt = $pdo->prepare("SELECT name FROM riders WHERE id = ?");
            $rNameStmt->execute([$riderId]);
            $rName = $rNameStmt->fetchColumn() ?: 'Rider';
            
            $reason = $acceptCheck['code'] === 'blocked_all'
                ? "{$rName} has ₱" . number_format($acceptCheck['balance'] ?? 0, 2) . 
                  " pending COD. Account is restricted — cannot assign to any order."
                : "{$rName} has ₱" . number_format($acceptCheck['balance'] ?? 0, 2) . 
                  " pending COD. Cannot assign to COD orders. Try another rider or a paid order.";
            
            respond(false, $reason, [
                'code' => $acceptCheck['code'],
                'balance' => $acceptCheck['balance'] ?? 0
            ], 403);
        }

        // ✅ REQUIRE live photos
        if (empty($handoverPhotosJson)) {
            respond(false, 'Live parcel photo is required before assigning rider', null, 400);
        }

        if ($timeStart >= $timeEnd) {
            respond(false, 'Delivery time end must be after start time', null, 400);
        }

        // ✅ Save photos first
        $savedPhotos = saveHandoverPhotos($reservationId, $handoverPhotosJson);
        if (empty($savedPhotos)) {
            respond(false, 'Failed to save photos. Please retake and try again.', null, 400);
        }

        // ✅ Get rider info
        $rStmt = $pdo->prepare("
            SELECT name, employee_id 
            FROM riders 
            WHERE id = ? AND clinic_id = ? AND status = 'active'
        ");
        $rStmt->execute([$riderId, $clinicId]);
        $rider = $rStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rider) respond(false, 'Rider not found or not active', null, 404);
        if (!$rider['employee_id']) respond(false, 'Rider is not linked to an employee record', null, 400);

        $riderName = $rider['name'];

        // ✅ Check working hours
        $workingCheck = isRiderWithinWorkingHours(
            $pdo, (int)$rider['employee_id'], $clinicId, $eta, $timeStart, $timeEnd
        );
        if (!$workingCheck['ok']) {
            // Cleanup saved photos kung mag-fail
            foreach ($savedPhotos as $p) {
                @unlink(__DIR__ . '/../' . $p['path']);
            }
            respond(false, $workingCheck['reason'], ['error_type' => 'outside_working_hours'], 422);
        }

        // ✅ Check conflict
        $conflict = hasRiderConflict($pdo, $riderId, $clinicId, $eta, $timeStart, $timeEnd, $reservationId);
        if ($conflict) {
            // Cleanup saved photos kung may conflict
            foreach ($savedPhotos as $p) {
                @unlink(__DIR__ . '/../' . $p['path']);
            }
            respond(false,
                "Rider is already booked at this time. Conflict with order #{$conflict['reservation_code']} " .
                "({$conflict['delivery_time_start']}–{$conflict['delivery_time_end']}).",
                ['error_type' => 'time_conflict', 'conflict' => $conflict],
                409
            );
        }

        // ✅ Save all data
        $photosJsonToStore = json_encode(array_column($savedPhotos, 'path'));

        $pdo->prepare("
            UPDATE reservations
            SET assigned_rider_id   = ?,
                delivery_status     = 'assigned',
                delivery_date       = ?,
                delivery_time_start = ?,
                delivery_time_end   = ?,
                handover_photos     = ?,
                handover_photo_at   = NOW(),
                handover_notes      = ?,
                handover_by         = ?,
                notes               = CONCAT(IFNULL(notes,''), ?),
                updated_at          = NOW()
            WHERE id = ? AND clinic_id = ?
        ")->execute([
            $riderId,
            $eta,
            $timeStart,
            $timeEnd,
            $photosJsonToStore,
            $handoverNotes ?: null,
            $currentUid,
            $notes ? "\n[Rider note: $notes]" : '',
            $reservationId,
            $clinicId
        ]);

        // ✅ Notify customer
        notifyUser($pdo, $userId, 'Rider Assigned 🏍️',
            "A rider ({$riderName}) has been assigned to your order #{$code} on {$eta} from {$timeStart} to {$timeEnd}.",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Rider assigned successfully with ' . count($savedPhotos) . ' photo(s).', [
            'rider_name'          => $riderName,
            'delivery_date'       => $eta,
            'delivery_time_start' => $timeStart,
            'delivery_time_end'   => $timeEnd,
            'photos_saved'        => count($savedPhotos),
            'photos'              => $savedPhotos
        ]);
    }

    if ($action === 'dispatch') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);
        if (!$order['assigned_rider_id']) respond(false, 'Please assign a rider first', null, 400);

        // ✅ REQUIRE handover photos bago mag-dispatch
        if (empty($order['handover_photos'])) {
            respond(false, 'Cannot dispatch. Parcel photo is required. Please re-assign rider with live photo.', null, 400);
        }

        $tracking = $order['tracking_number'];
        if (empty($tracking)) {
            $tracking = generateTrackingNumber($pdo);
        }

        $pdo->prepare("
            UPDATE reservations 
            SET status='dispatched', 
                delivery_status='in_transit', 
                tracking_number = ?,
                updated_at=NOW() 
            WHERE id=? AND clinic_id=?
        ")->execute([$tracking, $reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Order Dispatched 🚚',
            "Your order #{$code} is on the way! Tracking: {$tracking}",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Order dispatched.', ['tracking_number' => $tracking]);
    }

    if ($action === 'deliver') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);

        $collectedAmount = isset($data['collected_amount']) ? (float)$data['collected_amount'] : null;
        $collectionNotes = trim($data['collection_notes'] ?? '');

        $paymentUpdate = "";
        $collectionResult = null;

        if (requiresCollection($order)) {
            if ($collectedAmount === null) {
                respond(false, 'Collection amount is required for this order', null, 400);
            }
            $collectionResult = recordCollection($pdo, $reservationId, $clinicId, $collectedAmount, $collectionNotes, $currentUid);
            if (!$collectionResult['ok']) {
                respond(false, $collectionResult['reason'] ?? 'Collection failed', null, 400);
            }
        } else {
            $paymentUpdate = ", payment_status='paid'";
        }

        $pdo->prepare("
            UPDATE reservations
            SET status='delivered', delivery_status='delivered',
                delivered_at=NOW() {$paymentUpdate}, updated_at=NOW()
            WHERE id=? AND clinic_id=?
        ")->execute([$reservationId, $clinicId]);

        // ═══════════════════════════════════════════════════════════
        // ✅ PHASE 2: AUTO-ADD SA WALLET (kung may assigned rider)
        // ═══════════════════════════════════════════════════════════
        $walletResult = null;
        $assignedRiderId = (int)($order['assigned_rider_id'] ?? 0);
        
        if ($assignedRiderId > 0 && $collectedAmount !== null && $collectedAmount > 0) {
            $walletResult = recordCodCollection(
                $pdo,
                $reservationId,
                $assignedRiderId,
                $collectedAmount,
                $code
            );
            
            if (!$walletResult['ok']) {
                error_log("Clinic deliver — wallet update failed for #{$code}: " . ($walletResult['reason'] ?? 'Unknown'));
            }
        }

        $msg = "Your order #{$code} has been delivered. Thank you!";
        if ($collectionResult && $collectionResult['status'] === 'paid') $msg .= " Payment received. ✅";
        elseif ($collectionResult && $collectionResult['status'] === 'partial') $msg .= " Partial payment received.";

        notifyUser($pdo, $userId, 'Order Delivered 🎉', $msg,
            'reservation', 'my-reservations.php', $reservationId);

        // ✅ Notify rider (kung may assigned)
        if ($walletResult && $walletResult['ok'] && $assignedRiderId > 0) {
            $rStmt2 = $pdo->prepare("SELECT user_id FROM riders WHERE id = ?");
            $rStmt2->execute([$assignedRiderId]);
            $riderUserId = (int)$rStmt2->fetchColumn();
            
            if ($riderUserId > 0) {
                $newBal = number_format($walletResult['new_balance'], 2);
                notifyUser($pdo, $riderUserId, '💰 COD Balance Updated',
                    "COD ₱" . number_format($collectedAmount, 2) . " added for order #{$code} (clinic delivery). New balance: ₱{$newBal}.",
                    'wallet', 'rider_dashboard.php', $reservationId);
            }
        }

        respond(true, 'Order delivered.', [
            'collection' => $collectionResult,
            'wallet'     => $walletResult
        ]);
    }

    if ($action === 'complete') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);

        $allowedStatuses = ['delivered', 'ready_for_pickup'];
        if (!in_array($order['status'], $allowedStatuses, true)) {
            respond(false, 'Order cannot be completed in current status', null, 400);
        }

        $collectedAmount = isset($data['collected_amount']) ? (float)$data['collected_amount'] : null;
        $collectionNotes = trim($data['collection_notes'] ?? '');
        $collectionResult = null;

        if (requiresCollection($order)) {
            if ($collectedAmount === null) {
                respond(false, 'Collection amount is required for this order', null, 400);
            }
            $collectionResult = recordCollection($pdo, $reservationId, $clinicId, $collectedAmount, $collectionNotes, $currentUid);
            if (!$collectionResult['ok']) {
                respond(false, $collectionResult['reason'] ?? 'Collection failed', null, 400);
            }
        }

        $pdo->prepare("UPDATE reservations SET status='completed', updated_at=NOW() WHERE id=? AND clinic_id=?")
            ->execute([$reservationId, $clinicId]);

        $msg = "Your order #{$code} has been COMPLETED. Thank you!";
        if ($collectionResult && $collectionResult['status'] === 'paid') $msg .= " Payment received. ✅";

        notifyUser($pdo, $userId, 'Order Completed 🎉', $msg,
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Order completed.', ['collection' => $collectionResult]);
    }

    if ($action === 'cancel') {
        if (!canRejectReservations()) respond(false, 'No permission', null, 403);
        $reason = trim($data['reason'] ?? 'Cancelled by clinic');

        $pdo->prepare("
            UPDATE reservations
            SET status='cancelled', delivery_status='cancelled',
                cancelled_reason=?, notes=CONCAT(IFNULL(notes,''),?), updated_at=NOW()
            WHERE id=? AND clinic_id=?
        ")->execute([$reason, "\n[Cancelled: {$reason}]", $reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Order Cancelled ❌',
            "Your order #{$code} has been CANCELLED. Reason: {$reason}",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Order cancelled.');
    }

    respond(false, 'Unknown action', null, 400);
}

function handleBulkAction(PDO $pdo, int $clinicId, int $currentUid, array $data, string $action): void
{
    $bulkActions = [
        'bulk_confirm'      => ['perm'=>'canApproveReservations', 'label'=>'confirmed'],
        'bulk_ready_pickup' => ['perm'=>'canApproveReservations', 'label'=>'ready for pickup'],
        'bulk_preparing'    => ['perm'=>'canEditReservations',    'label'=>'preparing'],
        'bulk_assign_rider' => ['perm'=>'canEditReservations',    'label'=>'assigned'],
        'bulk_dispatch'     => ['perm'=>'canEditReservations',    'label'=>'dispatched'],
        'bulk_deliver'      => ['perm'=>'canEditReservations',    'label'=>'delivered'],
        'bulk_complete'     => ['perm'=>'canEditReservations',    'label'=>'completed'],
        'bulk_cancel'       => ['perm'=>'canRejectReservations',  'label'=>'cancelled'],
    ];

    if (!isset($bulkActions[$action])) respond(false, 'Unknown bulk action', null, 400);
    if (!call_user_func($bulkActions[$action]['perm'])) respond(false, 'No permission', null, 403);

    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) respond(false, 'No orders selected', null, 400);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (empty($ids)) respond(false, 'Invalid order IDs', null, 400);
    if (count($ids) > 100) respond(false, 'Maximum 100 orders per batch', null, 400);

    $reason       = trim($data['reason'] ?? 'Bulk action by clinic');
    $riderId      = (int)($data['rider_id'] ?? 0);
    $deliveryDate = $data['delivery_date'] ?? null;

    $collectedAmounts = $data['collected_amounts'] ?? [];
    $collectionNotes  = trim($data['collection_notes'] ?? '');

    // ═══════════════════════════════════════════════════════════
    // BULK ASSIGN RIDER — with LIVE HANDOVER PHOTOS
    // ═══════════════════════════════════════════════════════════
    if ($action === 'bulk_assign_rider') {
        if (!$riderId) respond(false, 'Please select a rider', null, 400);
        if (!$deliveryDate) respond(false, 'Delivery date is required', null, 400);

        $bulkTimeStart = $data['delivery_time_start'] ?? null;
        $bulkTimeEnd   = $data['delivery_time_end'] ?? null;
        $handoverNotes = trim($data['handover_notes'] ?? '');
        $handoverPhotosJson = $data['handover_photos'] ?? '';

        if (!$bulkTimeStart || !$bulkTimeEnd) {
            respond(false, 'Delivery time start and end are required', null, 400);
        }

        if ($bulkTimeStart >= $bulkTimeEnd) {
            respond(false, 'Delivery time end must be after start time', null, 400);
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ PHASE 4: EARLY RIDER BLOCK CHECK (before photos)
        // ============================================================
        // Get rider wallet status once — check kung blocked
        $rBlockStmt = $pdo->prepare("
            SELECT name, wallet_balance, wallet_limit, wallet_status
            FROM riders
            WHERE id = ? AND clinic_id = ? AND status = 'active'
        ");
        $rBlockStmt->execute([$riderId, $clinicId]);
        $rBlockInfo = $rBlockStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rBlockInfo) {
            $rBlockBalance = (float)($rBlockInfo['wallet_balance'] ?? 0);
            $rBlockLimit   = (float)($rBlockInfo['wallet_limit'] ?? 5000);
            if ($rBlockLimit <= 0) $rBlockLimit = 5000;
            
            // Fresh compute
            $rBlockStatus = 'normal';
            if ($rBlockBalance >= $rBlockLimit * 1.6) {
                $rBlockStatus = 'blocked_all';
            } elseif ($rBlockBalance >= $rBlockLimit) {
                $rBlockStatus = 'blocked_cod';
            } elseif ($rBlockBalance >= $rBlockLimit * 0.8) {
                $rBlockStatus = 'warning';
            }
            
            if ($rBlockStatus === 'blocked_all') {
                respond(false, 
                    "{$rBlockInfo['name']} has ₱" . number_format($rBlockBalance, 2) . 
                    " pending COD. Account is restricted — cannot assign to any order.",
                    ['code' => 'blocked_all', 'balance' => $rBlockBalance],
                    403
                );
            }
            // blocked_cod: depende sa order type — i-check later per order
            // Para sa bulk, kung may mixed COD at PAID, selective lang
        }

        // ✅ REQUIRE live photos
        if (empty($handoverPhotosJson)) {
            respond(false, 'Live parcel photo is required before assigning rider', null, 400);
        }

        // ✅ REQUIRE live photos
        if (empty($handoverPhotosJson)) {
            respond(false, 'Live parcel photo is required before assigning rider', null, 400);
        }

        // ✅ Validate photos JSON structure once
        $photoData = json_decode($handoverPhotosJson, true);
        if (!is_array($photoData) || empty($photoData)) {
            respond(false, 'Invalid photo data', null, 400);
        }

        // ✅ Get rider info
        $rStmt = $pdo->prepare("
            SELECT name, employee_id 
            FROM riders 
            WHERE id = ? AND clinic_id = ? AND status = 'active'
        ");
        $rStmt->execute([$riderId, $clinicId]);
        $rider = $rStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rider) respond(false, 'Rider not found or not active', null, 404);
        if (!$rider['employee_id']) {
            respond(false, 'Rider is not linked to an employee record.', null, 400);
        }

        $riderName = $rider['name'];

        // ✅ Check working hours once
        $workingCheck = isRiderWithinWorkingHours(
            $pdo,
            (int)$rider['employee_id'],
            $clinicId,
            $deliveryDate,
            $bulkTimeStart,
            $bulkTimeEnd
        );

        if (!$workingCheck['ok']) {
            respond(false, $workingCheck['reason'], null, 422);
        }

    } else {
        $riderName     = null;
        $bulkTimeStart = null;
        $bulkTimeEnd   = null;
        $handoverNotes = '';
        $handoverPhotosJson = '';
    }

    $success = []; $failed = [];

    foreach ($ids as $id) {
        $order = fetchOrder($pdo, $id, $clinicId);
        if (!$order) { $failed[] = ['id'=>$id,'code'=>null,'reason'=>'Order not found']; continue; }
        $userId = (int)$order['user_id_val'];
        $code   = $order['reservation_code'];

        try {
            if ($action === 'bulk_confirm') {
                if ($order['status'] !== 'pending') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not pending']; continue; }
                $pdo->prepare("UPDATE reservations SET status='confirmed', updated_at=NOW() WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Confirmed ✅', "Your order #{$code} has been CONFIRMED!", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_ready_pickup') {
                if ($order['status'] !== 'confirmed' || $order['fulfillment_type'] !== 'pickup') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not a confirmed pickup']; continue; }
                $pdo->prepare("UPDATE reservations SET status='ready_for_pickup', updated_at=NOW() WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
                notifyUser($pdo, $userId, 'Ready for Pickup 🏪', "Your order #{$code} is ready for pickup.", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_preparing') {
                if ($order['status'] !== 'confirmed' || $order['fulfillment_type'] !== 'delivery') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not a confirmed delivery']; continue; }
                $pdo->prepare("UPDATE reservations SET status='preparing', delivery_status='preparing', updated_at=NOW() WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Preparing 📦', "Your order #{$code} is being prepared.", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            // ═══════════════════════════════════════════════════════
            // BULK ASSIGN RIDER — save photos per order
            // ═══════════════════════════════════════════════════════
            elseif ($action === 'bulk_assign_rider') {
                if ($order['fulfillment_type'] !== 'delivery') {
                    $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not a delivery'];
                    continue;
                }
                if (in_array($order['status'], ['delivered','completed','cancelled'], true)) {
                    $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Already finalized'];
                    continue;
                }

                // ═══════════════════════════════════════════════════════
                // ✅ PHASE 4: CHECK IF RIDER CAN ACCEPT THIS ORDER
                // ═══════════════════════════════════════════════════════
                $acceptCheck = canAcceptOrder($pdo, $riderId, $order);
                if (!$acceptCheck['ok']) {
                    $failed[] = [
                        'id'     => $id,
                        'code'   => $code,
                        'reason' => $acceptCheck['code'] === 'blocked_cod' 
                                    ? 'Rider blocked for COD orders' 
                                    : 'Rider account restricted'
                    ];
                    continue;
                }

                // ✅ Check conflict for THIS order
                $conflict = hasRiderConflict(
                    $pdo, $riderId, $clinicId,
                    $deliveryDate, $bulkTimeStart, $bulkTimeEnd,
                    $id
                );

                if ($conflict) {
                    $failed[] = [
                        'id'     => $id,
                        'code'   => $code,
                        'reason' => "Conflict with #{$conflict['reservation_code']}"
                    ];
                    continue;
                }

                // ✅ Save photos for THIS order (each order gets its own copies)
                $savedPhotos = saveHandoverPhotos($id, $handoverPhotosJson);
                if (empty($savedPhotos)) {
                    $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Photo save failed'];
                    continue;
                }

                $photosJsonToStore = json_encode(array_column($savedPhotos, 'path'));

                // ✅ Save with photos
                $pdo->prepare("
                    UPDATE reservations 
                    SET assigned_rider_id   = ?, 
                        delivery_status     = 'assigned', 
                        delivery_date       = ?,
                        delivery_time_start = ?,
                        delivery_time_end   = ?,
                        handover_photos     = ?,
                        handover_photo_at   = NOW(),
                        handover_notes      = ?,
                        handover_by         = ?,
                        updated_at          = NOW() 
                    WHERE id = ? AND clinic_id = ?
                ")->execute([
                    $riderId,
                    $deliveryDate,
                    $bulkTimeStart,
                    $bulkTimeEnd,
                    $photosJsonToStore,
                    $handoverNotes ?: null,
                    $currentUid,
                    $id,
                    $clinicId
                ]);

                notifyUser($pdo, $userId, 'Rider Assigned 🏍️',
                    "A rider ({$riderName}) has been assigned to your order #{$code} on {$deliveryDate} from {$bulkTimeStart} to {$bulkTimeEnd}.",
                    'reservation', 'my-reservations.php', $id);

                $success[] = $id;
            }
            elseif ($action === 'bulk_dispatch') {
                if (!$order['assigned_rider_id']) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'No rider assigned']; continue; }
                if (in_array($order['status'], ['dispatched','delivered','completed','cancelled'], true)) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Cannot dispatch']; continue; }
                
                // ✅ REQUIRE handover photos
                if (empty($order['handover_photos'])) {
                    $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Missing parcel photos'];
                    continue;
                }

                // ✅ Generate tracking number
                $tracking = $order['tracking_number'];
                if (empty($tracking)) {
                    $tracking = generateTrackingNumber($pdo);
                }

                $pdo->prepare("UPDATE reservations SET status='dispatched', delivery_status='in_transit', tracking_number=?, updated_at=NOW() WHERE id=? AND clinic_id=?")
                    ->execute([$tracking, $id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Dispatched 🚚', "Your order #{$code} is on the way! Tracking: {$tracking}", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_deliver') {
                if ($order['status'] !== 'dispatched') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not dispatched']; continue; }

                $paymentUpdate = "";
                $collectionResult = null;
                $amt = null;

                if (requiresCollection($order)) {
                    $amt = isset($collectedAmounts[$id]) ? (float)$collectedAmounts[$id] : null;
                    if ($amt === null) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Collection amount missing']; continue; }
                    $collectionResult = recordCollection($pdo, $id, $clinicId, $amt, $collectionNotes, $currentUid);
                    if (!$collectionResult['ok']) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>$collectionResult['reason']]; continue; }
                }

                $pdo->prepare("UPDATE reservations SET status='delivered', delivery_status='delivered', delivered_at=NOW() {$paymentUpdate}, updated_at=NOW() WHERE id=? AND clinic_id=?")
                    ->execute([$id, $clinicId]);

                // ═══════════════════════════════════════════════════════════
                // ✅ PHASE 2: AUTO-ADD SA WALLET
                // ═══════════════════════════════════════════════════════════
                $assignedRiderId = (int)($order['assigned_rider_id'] ?? 0);
                if ($assignedRiderId > 0 && $amt !== null && $amt > 0) {
                    $wResult = recordCodCollection($pdo, $id, $assignedRiderId, $amt, $code);
                    if (!$wResult['ok']) {
                        error_log("Bulk deliver — wallet update failed for #{$code}: " . ($wResult['reason'] ?? 'Unknown'));
                    }
                }

                $msg = "Your order #{$code} has been delivered. Thank you!";
                if ($collectionResult && $collectionResult['status'] === 'paid') $msg .= " Payment received. ✅";
                notifyUser($pdo, $userId, 'Order Delivered 🎉', $msg, 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_complete') {
                if ($order['status'] !== 'ready_for_pickup') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not ready for pickup']; continue; }

                $collectionResult = null;
                if (requiresCollection($order)) {
                    $amt = isset($collectedAmounts[$id]) ? (float)$collectedAmounts[$id] : null;
                    if ($amt === null) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Collection amount missing']; continue; }
                    $collectionResult = recordCollection($pdo, $id, $clinicId, $amt, $collectionNotes, $currentUid);
                    if (!$collectionResult['ok']) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>$collectionResult['reason']]; continue; }
                }

                $pdo->prepare("UPDATE reservations SET status='completed', updated_at=NOW() WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Completed 🎉', "Your order #{$code} has been completed.", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_cancel') {
                if (in_array($order['status'], ['delivered','completed','cancelled'], true)) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Already finalized']; continue; }
                $pdo->prepare("UPDATE reservations SET status='cancelled', delivery_status='cancelled', cancelled_reason=?, notes=CONCAT(IFNULL(notes,''),?), updated_at=NOW() WHERE id=? AND clinic_id=?")
                    ->execute([$reason, "\n[Cancelled: {$reason}]", $id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Cancelled ❌', "Your order #{$code} has been CANCELLED. Reason: {$reason}", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
        } catch (Exception $e) {
            $failed[] = ['id'=>$id, 'code'=>$code ?? '', 'reason'=>$e->getMessage()];
        }
    }

    $msg = count($success) . ' order(s) ' . $bulkActions[$action]['label'];
    if (count($failed) > 0) $msg .= ', ' . count($failed) . ' failed';

    respond(true, $msg, ['success'=>$success, 'failed'=>$failed, 'total'=>count($ids)]);
}

/**
 * ✅ Generate unique tracking number
 * Format: TRK-YYYYMMDD-XXXXX (e.g., TRK-20260918-A3F7B)
 */
function generateTrackingNumber(PDO $pdo): string
{
    $date = date('Ymd');
    $maxAttempts = 10;
    
    for ($i = 0; $i < $maxAttempts; $i++) {
        // Generate 5 random alphanumeric characters (uppercase)
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random = '';
        for ($j = 0; $j < 5; $j++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        $tracking = "TRK-{$date}-{$random}";
        
        // Check if already exists (rare collision)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE tracking_number = ?");
        $stmt->execute([$tracking]);
        if ($stmt->fetchColumn() == 0) {
            return $tracking;
        }
    }
    
    // Fallback: use uniqid
    return 'TRK-' . $date . '-' . strtoupper(substr(uniqid(), -5));
}

// ════════════════════════════════════════════════════════════════
// REFUND ACTION HANDLER (Clinic/Admin only)
// ════════════════════════════════════════════════════════════════
function handleRefundAction(PDO $pdo, int $clinicId, int $currentUid, array $data, string $action): void
{
if ($action === 'approve_refund') {
    if (!canEditReservations()) respond(false, 'No permission', null, 403);
    
    $refundId     = (int)($data['refund_id'] ?? 0);
    $refundMethod = $data['refund_method'] ?? 'manual_gcash';
    $adminNotes   = trim($data['admin_notes'] ?? '');
    $refundRef    = trim($data['refund_reference'] ?? '');
    
    if (!$refundId) respond(false, 'Refund ID required', null, 400);
    
    $stmt = $pdo->prepare("
        SELECT rr.*,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown Customer') AS user_name,
               u.email AS user_email,
               u.contact AS user_contact,
               u.id AS user_id_val,
               r.reservation_code,
               r.total_amount AS order_total,
               r.downpayment_amount AS order_downpayment,
               r.balance_amount AS order_balance,
               r.payment_status AS order_payment_status,
               r.paymongo_payment_id,
               r.status AS order_status,
               r.fulfillment_type,
               r.delivered_at,
               r.delivery_proof_image,
               p.name AS product_name,
               p.image AS product_image,
               p.category AS product_category,
               cb.first_name AS processed_by_first,
               cb.last_name AS processed_by_last
        FROM refund_requests rr
        LEFT JOIN users u ON rr.user_id = u.id
        LEFT JOIN reservations r ON rr.reservation_id = r.id
        LEFT JOIN products p ON r.product_id = p.id
        LEFT JOIN users cb ON rr.processed_by = cb.id
        WHERE rr.id = ?
          AND rr.clinic_id = ?
          AND rr.reservation_id IS NOT NULL
    ");
    $stmt->execute([$refundId, $clinicId]);
    $refund = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$refund) respond(false, 'Refund request not found', null, 404);
    if ($refund['status'] !== 'pending') {
        respond(false, 'Refund request is no longer pending', null, 400);
    }
    
    $userId = (int)$refund['user_id_val'];
    $reservationId = (int)$refund['reservation_id'];
    $refundAmount = (float)$refund['amount'];
    $paymentId = $refund['paymongo_payment_id'] ?? null;
    
    // ═══════════════════════════════════════════════════════
    // ✅ AUTO-PROCESS: Kung may PayMongo payment ID at online payment
    // ═══════════════════════════════════════════════════════
    $shouldAutoProcess = ($refundMethod === 'paymongo') 
                      && !empty($paymentId) 
                      && strpos($paymentId, 'pay_') === 0;
    
    if ($shouldAutoProcess) {
        // ✅ Mark as processing first
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'processing', 
                refund_status = 'processing',
                admin_notes = ?,
                processed_by = ?,
                processed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$adminNotes ?: null, $currentUid, $refundId]);
        
        // ✅ Load PayMongo helper
        require_once __DIR__ . '/../api/paymongos.php';
        $paymongo = new PayMongoRefund($pdo);
        
        // ✅ Call PayMongo Refunds API (AUTOMATIC)
        $result = $paymongo->processRefundDirect(
            $refundId,
            $paymentId,
            $refundAmount,
            'requested_by_customer'
        );
        
        if ($result['success']) {
            // ✅ Success — mark completed
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'completed',
                    refund_status = 'completed',
                    return_status = 'completed',
                    paymongo_refund_id = ?,
                    refund_date = NOW(),
                    refund_amount_actual = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $result['refund_id'] ?? null,
                $refundAmount,
                $refundId
            ]);
            
            // ✅ Update reservation
            $pdo->prepare("
                UPDATE reservations 
                SET payment_status = 'refunded',
                    refund_status = 'completed',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$reservationId]);
            
            // ✅ Notify customer
            sendRefundNotification($pdo, $userId, $reservationId, 'completed', '', $refundAmount);
            
            respond(true, 'Refund processed automatically!', [
                'refund_id' => $refundId,
                'paymongo_refund_id' => $result['refund_id'] ?? null,
                'amount' => $refundAmount,
                'auto' => true,
                'status' => 'completed'
            ]);
        } else {
            // ❌ API failed — fallback to manual approval
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'approved',
                    refund_status = 'approved',
                    error_message = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$result['message'] ?? 'Auto-refund failed', $refundId]);
            
            respond(false, 'Auto-refund failed: ' . ($result['message'] ?? 'Unknown error') . '. Manual processing required.', [
                'refund_id' => $refundId,
                'auto' => false,
                'requires_manual' => true,
                'error' => $result['message'] ?? 'Unknown error'
            ], 500);
        }
        exit;
    }
    
    // ═══════════════════════════════════════════════════════
    // ✅ MANUAL: Para sa cash/onsite/unpaid OR walang PayMongo ref
    // ═══════════════════════════════════════════════════════
    $pdo->prepare("
        UPDATE refund_requests 
        SET status = 'approved',
            refund_status = 'approved',
            admin_notes = ?,
            processed_by = ?,
            processed_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$adminNotes ?: null, $currentUid, $refundId]);
    
    // ✅ Update reservation (mark as refunded for manual)
    // ✅ Update reservation — refund_status LANG, HUWAG galawin ang payment_status
    // payment_status ay magiging 'refunded' LANG kapag na-mark_refunded na
    $pdo->prepare("
        UPDATE reservations 
        SET refund_status = 'approved',
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$reservationId]);
    
    // ✅ Notify customer
    sendRefundNotification($pdo, $userId, $reservationId, 'approved', '', $refundAmount);
    
    // ✅ Determine why manual
    $manualReason = 'No PayMongo reference';
    if ($refundMethod !== 'paymongo') {
        $manualReason = 'Manual refund method selected (' . $refundMethod . ')';
    } elseif (empty($paymentId)) {
        $manualReason = 'No PayMongo payment ID (cash/onsite payment)';
    }
    
    respond(true, 'Refund approved for manual processing', [
        'refund_id' => $refundId,
        'amount' => $refundAmount,
        'auto' => false,
        'requires_manual' => true,
        'manual_reason' => $manualReason,
        'status' => 'approved'
    ]);
}
    
    // ════════════════════════════════════════════════════════════════
    //  REJECT REFUND (Clinic)
    // ════════════════════════════════════════════════════════════════
    if ($action === 'reject_refund') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);
        
        $refundId   = (int)($data['refund_id'] ?? 0);
        $adminNotes = trim($data['admin_notes'] ?? '');
        
        if (!$refundId) respond(false, 'Refund ID required', null, 400);
        if (!$adminNotes) respond(false, 'Rejection reason is required', null, 400);
        
        // ✅ Get refund
        $stmt = $pdo->prepare("
            SELECT rr.*, r.reservation_code, u.id AS user_id_val
            FROM refund_requests rr
            JOIN reservations r ON rr.reservation_id = r.id
            JOIN users u ON rr.user_id = u.id
            WHERE rr.id = ? AND rr.clinic_id = ? AND rr.reservation_id IS NOT NULL
        ");
        $stmt->execute([$refundId, $clinicId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) respond(false, 'Refund request not found', null, 404);
        if ($refund['status'] !== 'pending') {
            respond(false, 'Refund request is no longer pending', null, 400);
        }
        
        $userId = (int)$refund['user_id_val'];
        $reservationId = (int)$refund['reservation_id'];
        
        // ✅ Update refund request
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'rejected',
                admin_notes = ?,
                processed_by = ?,
                processed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$adminNotes, $currentUid, $refundId]);
        
        // ✅ Update reservation
        $pdo->prepare("
            UPDATE reservations 
            SET refund_status = 'none', updated_at = NOW() 
            WHERE id = ?
        ")->execute([$reservationId]);
        
        // ✅ Notify customer
        sendRefundNotification($pdo, $userId, $reservationId, 'rejected', $adminNotes);
        
        respond(true, 'Refund request rejected');
    }
    
    // ════════════════════════════════════════════════════════════════
    //  MARK REFUNDED (Clinic — Manual)
    // ════════════════════════════════════════════════════════════════
    if ($action === 'mark_refunded') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);
        
        $refundId     = (int)($data['refund_id'] ?? 0);
        $refundRef    = trim($data['refund_reference'] ?? '');
        $adminNotes   = trim($data['admin_notes'] ?? '');
        
        if (!$refundId) respond(false, 'Refund ID required', null, 400);
        if (!$refundRef) respond(false, 'Refund reference is required', null, 400);
        
        // ✅ Get refund
        $stmt = $pdo->prepare("
            SELECT rr.*, r.reservation_code, r.total_amount AS order_total,
                   u.id AS user_id_val
            FROM refund_requests rr
            JOIN reservations r ON rr.reservation_id = r.id
            JOIN users u ON rr.user_id = u.id
            WHERE rr.id = ? AND rr.clinic_id = ? AND rr.reservation_id IS NOT NULL
        ");
        $stmt->execute([$refundId, $clinicId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) respond(false, 'Refund request not found', null, 404);
        if (!in_array($refund['status'], ['approved', 'processing'], true)) {
            respond(false, 'Refund must be approved first', null, 400);
        }
        
        $userId = (int)$refund['user_id_val'];
        $reservationId = (int)$refund['reservation_id'];
        $refundAmount = (float)$refund['amount'];
        
        $pdo->beginTransaction();
        
        try {
            // ✅ Update refund request
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'completed',
                    refund_status = 'completed',
                    refund_date = NOW(),
                    refund_amount_actual = ?,
                    admin_notes = CONCAT(IFNULL(admin_notes,''), ?),
                    processed_by = ?,
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $refundAmount,
                $refundRef ? "\n[Ref: {$refundRef}]" : '',
                $currentUid,
                $refundId
            ]);
            
            // ✅ Update reservation — payment_status = refunded (trigger will update sales)
            $pdo->prepare("
                UPDATE reservations 
                SET payment_status = 'refunded',
                    refund_status = 'completed',
                    updated_at = NOW() 
                WHERE id = ?
            ")->execute([$reservationId]);
            
            $pdo->commit();
            
            // ✅ Notify customer
            sendRefundNotification($pdo, $userId, $reservationId, 'completed', '', $refundAmount);
            
            respond(true, 'Refund marked as completed', [
                'refund_id' => $refundId,
                'amount' => $refundAmount,
                'reference' => $refundRef
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(false, 'Failed to mark refund: ' . $e->getMessage(), null, 500);
        }
    }
    
    respond(false, 'Unknown refund action', null, 400);
}