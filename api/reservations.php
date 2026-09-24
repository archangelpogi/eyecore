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
               cu.last_name  AS collector_last
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN products p ON r.product_id = p.id
        LEFT JOIN riders rd ON r.assigned_rider_id = rd.id
        LEFT JOIN users cu ON r.collected_by = cu.id
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
// REFUND HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * ✅ Handle refund evidence image upload
 * Returns: relative path, or null if failed
 */
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

    $msg = "Your order #{$code} has been delivered. Thank you!";
    if ($isCOD) {
        $msg .= " Payment received: ₱" . number_format($collectedAmount, 2) . ".";
        if ($changeAmount > 0.01) {
            $msg .= " Change given: ₱" . number_format($changeAmount, 2) . ".";
        }
    }

    notifyUser($pdo, $customerId, 'Order Delivered 🎉', $msg,
        'reservation', 'my-reservations.php', $orderId);

    respond(true, 'Order delivered with proof.', [
        'collected_amount' => $collectedAmount,
        'change_amount'    => $changeAmount,
        'total_due'        => $totalDue
    ]);
}

    respond(false, 'Unknown rider action', null, 400);
}

// ════════════════════════════════════════════════════════════════
// SINGLE STATUS UPDATE
// ════════════════════════════════════════════════════════════════
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

    if ($action === 'assign_rider') {
        if (!canEditReservations()) respond(false, 'No permission', null, 403);

        $riderId = (int)($data['rider_id'] ?? 0);
        $notes   = trim($data['delivery_notes'] ?? '');
        $eta     = $data['delivery_date'] ?? null;

        if (!$riderId) respond(false, 'Please select a rider', null, 400);

        $rStmt = $pdo->prepare("SELECT name FROM riders WHERE id=? AND clinic_id=? AND status='active'");
        $rStmt->execute([$riderId, $clinicId]);
        $riderName = $rStmt->fetchColumn();
        if (!$riderName) respond(false, 'Rider not found or not active', null, 404);

        $pdo->prepare("
            UPDATE reservations
            SET assigned_rider_id=?, delivery_status='assigned', delivery_date=?,
                notes=CONCAT(IFNULL(notes,''),?), updated_at=NOW()
            WHERE id=? AND clinic_id=?
        ")->execute([$riderId, $eta, $notes ? "\n[Rider note: $notes]" : '', $reservationId, $clinicId]);

        notifyUser($pdo, $userId, 'Rider Assigned 🏍️',
            "A rider ({$riderName}) has been assigned to your order #{$code}.",
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Rider assigned successfully.');
    }

if ($action === 'dispatch') {
    if (!canEditReservations()) respond(false, 'No permission', null, 403);
    if (!$order['assigned_rider_id']) respond(false, 'Please assign a rider first', null, 400);
    
    // ✅ Generate tracking number if not yet set
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

        $msg = "Your order #{$code} has been delivered. Thank you!";
        if ($collectionResult && $collectionResult['status'] === 'paid') $msg .= " Payment received. ✅";
        elseif ($collectionResult && $collectionResult['status'] === 'partial') $msg .= " Partial payment received.";

        notifyUser($pdo, $userId, 'Order Delivered 🎉', $msg,
            'reservation', 'my-reservations.php', $reservationId);

        respond(true, 'Order delivered.', ['collection' => $collectionResult]);
    }

if ($action === 'complete') {
    if (!canEditReservations()) respond(false, 'No permission', null, 403);
    
    // ✅ Allow completion from 'delivered' (delivery) OR 'ready_for_pickup' (pickup)
    $allowedStatuses = ['delivered', 'ready_for_pickup'];
    if (!in_array($order['status'], $allowedStatuses, true)) {
        respond(false, 'Order cannot be completed in current status', null, 400);
    }
    
    // ✅ Collection check (for COD/onsite/unpaid orders)
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

// ════════════════════════════════════════════════════════════════
// BULK ACTIONS
// ════════════════════════════════════════════════════════════════
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

    if ($action === 'bulk_assign_rider') {
        if (!$riderId) respond(false, 'Please select a rider', null, 400);
        $rStmt = $pdo->prepare("SELECT name FROM riders WHERE id=? AND clinic_id=? AND status='active'");
        $rStmt->execute([$riderId, $clinicId]);
        $riderName = $rStmt->fetchColumn();
        if (!$riderName) respond(false, 'Rider not found or not active', null, 404);
    } else {
        $riderName = null;
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
            elseif ($action === 'bulk_assign_rider') {
                if ($order['fulfillment_type'] !== 'delivery') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not a delivery']; continue; }
                if (in_array($order['status'], ['delivered','completed','cancelled'], true)) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Already finalized']; continue; }
                $pdo->prepare("UPDATE reservations SET assigned_rider_id=?, delivery_status='assigned', delivery_date=?, updated_at=NOW() WHERE id=? AND clinic_id=?")
                    ->execute([$riderId, $deliveryDate, $id, $clinicId]);
                notifyUser($pdo, $userId, 'Rider Assigned 🏍️', "A rider ({$riderName}) has been assigned to your order #{$code}.", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_dispatch') {
                if (!$order['assigned_rider_id']) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'No rider assigned']; continue; }
                if (in_array($order['status'], ['dispatched','delivered','completed','cancelled'], true)) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Cannot dispatch']; continue; }
                $pdo->prepare("UPDATE reservations SET status='dispatched', delivery_status='in_transit', updated_at=NOW() WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
                notifyUser($pdo, $userId, 'Order Dispatched 🚚', "Your order #{$code} is on the way!", 'reservation', 'my-reservations.php', $id);
                $success[] = $id;
            }
            elseif ($action === 'bulk_deliver') {
                if ($order['status'] !== 'dispatched') { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Not dispatched']; continue; }

                $paymentUpdate = "";
                $collectionResult = null;

                if (requiresCollection($order)) {
                    $amt = isset($collectedAmounts[$id]) ? (float)$collectedAmounts[$id] : null;
                    if ($amt === null) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>'Collection amount missing']; continue; }
                    $collectionResult = recordCollection($pdo, $id, $clinicId, $amt, $collectionNotes, $currentUid);
                    if (!$collectionResult['ok']) { $failed[] = ['id'=>$id,'code'=>$code,'reason'=>$collectionResult['reason']]; continue; }
                }

                $pdo->prepare("UPDATE reservations SET status='delivered', delivery_status='delivered', delivered_at=NOW() {$paymentUpdate}, updated_at=NOW() WHERE id=? AND clinic_id=?")
                    ->execute([$id, $clinicId]);
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
    $pdo->prepare("
        UPDATE reservations 
        SET payment_status = 'refunded',
            refund_status = 'approved',
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