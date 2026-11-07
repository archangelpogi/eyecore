<?php
// ✅ SET TIMEZONE TO PHILIPPINES
date_default_timezone_set('Asia/Manila');

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
        exit();
    }
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* ═══════════════════════════════════════════════════════════════
   ⚠️  AJAX HANDLERS — HUWAG GALAWIN (backend logic intact)
   ═══════════════════════════════════════════════════════════════ */

// ===== AJAX: CANCEL ORDER WITH HYBRID REFUND LOGIC (Option C) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    header('Content-Type: application/json');

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $cancel_reason = trim($_POST['reason'] ?? 'Cancelled by customer');

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit();
    }

    $check = mysqli_query($conn,
        "SELECT r.*, p.name as product_name, c.name as clinic_name
         FROM reservations r
         JOIN products p ON r.product_id = p.id
         JOIN clinics c ON r.clinic_id = c.id
         WHERE r.id = $id AND r.user_id = $user_id LIMIT 1"
    );

    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
        exit();
    }

    $res = mysqli_fetch_assoc($check);
    $current_status = $res['status'];
    $cancellable = ['pending', 'confirmed', 'ready_for_pickup'];

    if (!in_array($current_status, $cancellable)) {
        $status_display = ucfirst(str_replace('_', ' ', $current_status));
        echo json_encode(['success' => false, 'message' => "Cannot cancel an order that is already \"{$status_display}\"."]);
        exit();
    }

    $payment_status      = $res['payment_status'] ?? 'unpaid';
    $paymongo_payment_id = $res['paymongo_payment_id'] ?? null;
    $total_amount        = (float)($res['total_amount'] ?? 0);
    $downpayment_amount  = (float)($res['downpayment_amount'] ?? 0);
    $balance_amount      = (float)($res['balance_amount'] ?? 0);
    $created_at          = $res['created_at'] ?? null;

    $amount_paid = 0;
    if ($payment_status === 'paid') {
        $amount_paid = $total_amount;
    } elseif ($payment_status === 'partial') {
        $amount_paid = $downpayment_amount > 0 ? $downpayment_amount : $balance_amount;
    } elseif ($payment_status === 'refunded') {
        $amount_paid = 0;
    }

    $is_paid = in_array($payment_status, ['paid', 'partial'], true) && $amount_paid > 0;
    $is_cash_based = in_array($payment_status, ['cod', 'onsite', 'unpaid', 'free'], true);
    $has_paymongo_ref = !empty($paymongo_payment_id) && strpos($paymongo_payment_id, 'pay_') === 0;

    $paid_at = $res['paid_at'] ?? null;
    $within_15_min = false;
    
    if ($paid_at) {
        $paid_time = strtotime($paid_at);
        $within_15_min = (time() - $paid_time) <= (24 * 60 * 60);
    } elseif ($created_at) {
        $created_time = strtotime($created_at);
        $within_15_min = (time() - $created_time) <= (24 * 60 * 60);
    }

    $reason_esc = mysqli_real_escape_string($conn, $cancel_reason);
    $update = mysqli_query($conn,
        "UPDATE reservations 
         SET status = 'cancelled', 
             cancelled_reason = '$reason_esc',
             updated_at = NOW() 
         WHERE id = $id AND user_id = $user_id"
    );

    if (!$update || mysqli_affected_rows($conn) === 0) {
        echo json_encode(['success' => false, 'message' => 'Failed to cancel. Please try again.']);
        exit();
    }

    if (!empty($res['color_code'])) {
        mysqli_query($conn, "
            UPDATE product_color_inventory 
            SET quantity = quantity + 1 
            WHERE product_id = {$res['product_id']} 
            AND color_code = '{$res['color_code']}'
            AND clinic_id = {$res['clinic_id']}
        ");
    }

    $refund_message = '';
    $refund_created = false;
    $auto_refunded = false;

    if ($is_cash_based) {
        $refund_message = ' No payment involved';
    } elseif ($is_paid) {
        if ($has_paymongo_ref && $within_15_min) {
            $auto_refund_result = attemptAutoRefund(
                $conn,
                $paymongo_payment_id,
                $amount_paid,
                $id,
                $user_id,
                $res['clinic_id'],
                $res['reservation_code'] ?? ('ORD-' . $id),
                $res['product_name']
            );

            if ($auto_refund_result['success']) {
                $auto_refunded = true;
                $refund_message = ' ✅ Auto-refund of ₱' . number_format($amount_paid, 2) . ' is being processed.';
                
                mysqli_query($conn, "UPDATE reservations SET payment_status = 'refunded', refund_status = 'processing' WHERE id = $id");
                
                if (function_exists('addNotification')) {
                    addNotification(
                        $user_id, 'refund',
                        '💰 Auto-Refund Initiated',
                        'Your order #' . ($res['reservation_code'] ?? $id) . ' has been auto-refunded ₱' . number_format($amount_paid, 2) . '. Amount will reflect in your account within 3-5 business days.',
                        'my-reservations.php'
                    );
                }
            } else {
                $refund_created = createRefundRequest(
                    $conn, $id, $user_id, $res['clinic_id'],
                    $amount_paid, $total_amount,
                    'Auto-created: cancellation (auto-refund unavailable)',
                    'Your order was cancelled. Refund request has been created for admin review.',
                    $res['reservation_code'] ?? ('ORD-' . $id),
                    $res['product_name']
                );
                
                $refund_message = ' Refund request created (₱' . number_format($amount_paid, 2) . '). Please wait for clinic approval.';
            }
        } else {
            $reason_text = $within_15_min 
                ? 'Order cancelled by customer' 
                : 'Order cancelled after 15-minute window';
            
            $refund_created = createRefundRequest(
                $conn, $id, $user_id, $res['clinic_id'],
                $amount_paid, $total_amount,
                $reason_text,
                'Your cancelled order has a refund request. Please wait for clinic approval.',
                $res['reservation_code'] ?? ('ORD-' . $id),
                $res['product_name']
            );
            $refund_message = ' Refund request created (₱' . number_format($amount_paid, 2) . '). Please wait for clinic approval.';
        }
    }

    if (function_exists('addNotification') && !$auto_refunded) {
        addNotification(
            $user_id, 'reservation',
            'Order Cancelled',
            'Your order for "' . $res['product_name'] . '" has been cancelled.' . $refund_message,
            'my-reservations.php'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Order cancelled successfully.' . $refund_message,
        'auto_refunded' => $auto_refunded,
        'refund_created' => $refund_created,
    ]);
    exit();
}

// ═══════════════════════════════════════════════════
// ✅ HELPER: Attempt Auto-Refund via PayMongo
// ═══════════════════════════════════════════════════
function attemptAutoRefund($conn, $paymongo_payment_id, $amount, $reservation_id, $user_id, $clinic_id, $order_code, $product_name) {
    $paymongo_secret = defined('PAYMONGO_SECRET_KEY') ? PAYMONGO_SECRET_KEY : null;
    if (empty($paymongo_secret)) {
        error_log("❌ Auto-refund failed: PAYMONGO_SECRET_KEY not defined");
        return ['success' => false, 'error' => 'PayMongo not configured'];
    }

    $ch = curl_init('https://api.paymongo.com/v1/payments/' . urlencode($paymongo_payment_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($paymongo_secret . ':')
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $payment_response = curl_exec($ch);
    $payment_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $payment_curl_error = curl_error($ch);
    curl_close($ch);

    if ($payment_curl_error) {
        error_log("❌ Auto-refund: Failed to fetch payment — $payment_curl_error");
        return ['success' => false, 'error' => 'Curl: ' . $payment_curl_error];
    }

    $payment_result = json_decode($payment_response, true);

    if ($payment_http_code < 200 || $payment_http_code >= 300 || empty($payment_result['data']['attributes']['amount'])) {
        error_log("❌ Auto-refund: Failed to fetch payment — HTTP $payment_http_code — $payment_response");
        return ['success' => false, 'error' => 'Cannot fetch payment'];
    }

    $actual_paid_centavos = (int) $payment_result['data']['attributes']['amount'];
    $actual_paid = $actual_paid_centavos / 100;

    $refundable_centavos = isset($payment_result['data']['attributes']['amount_refundable'])
        ? (int) $payment_result['data']['attributes']['amount_refundable']
        : $actual_paid_centavos;
    $refundable = $refundable_centavos / 100;

    $refund_amount = min($amount, $refundable);
    $refund_centavos = (int) round($refund_amount * 100);

    if ($refund_centavos > $refundable_centavos) {
        $refund_centavos = $refundable_centavos;
        $refund_amount = $refundable;
    }

    if ($refund_centavos <= 0) {
        error_log("❌ Auto-refund: No refundable amount (already refunded?)");
        return ['success' => false, 'error' => 'No refundable amount'];
    }

    $refund_data = [
        'data' => [
            'attributes' => [
                'amount' => $refund_centavos,
                'payment_id' => $paymongo_payment_id,
                'reason' => 'requested_by_customer',
                'notes' => 'Order cancelled within 15 minutes — auto-refund'
            ]
        ]
    ];

    $ch = curl_init('https://api.paymongo.com/v1/refunds');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($refund_data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($paymongo_secret . ':')
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("❌ Auto-refund curl error: $curl_error");
        return ['success' => false, 'error' => $curl_error];
    }

    $result = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && !empty($result['data']['id'])) {
        $refund_id = $result['data']['id'];
        $refund_status = $result['data']['attributes']['status'] ?? 'pending';

        $refund_id_esc = mysqli_real_escape_string($conn, $refund_id);
        $amount_esc = (float) $refund_amount;

        $insert = mysqli_query($conn, "
            INSERT INTO refund_requests (
                appointment_id, reservation_id, user_id, clinic_id,
                amount, refund_percentage, request_type, reason, details,
                status, refund_status, paymongo_refund_id, refund_date, created_at,
                return_method, return_status
            ) VALUES (
                0, $reservation_id, $user_id, $clinic_id,
                $amount_esc, 100, 'refund', 'auto_refund', 'Auto-refund within 15 min',
                'approved', 'processing', '$refund_id_esc', NOW(), NOW(),
                'none', 'completed'
            )
        ");

        return [
            'success' => true,
            'refund_id' => $refund_id,
            'refund_amount' => $refund_amount,
            'status' => $refund_status
        ];
    }

    $error_detail = 'Unknown error';
    if (!empty($result['errors'][0]['detail'])) {
        $error_detail = $result['errors'][0]['detail'];
    } elseif (!empty($result['errors'][0]['code'])) {
        $error_detail = $result['errors'][0]['code'];
    }

    return ['success' => false, 'error' => $error_detail];
}

// ═══════════════════════════════════════════════════
// ✅ HELPER: Create Refund Request
// ═══════════════════════════════════════════════════
function createRefundRequest($conn, $reservation_id, $user_id, $clinic_id, $amount, $total_amount, $reason, $details, $order_code, $product_name) {
    $amount_esc    = (float) $amount;
    $total_esc     = (float) $total_amount;
    $reason_esc    = mysqli_real_escape_string($conn, $reason);
    $details_esc   = mysqli_real_escape_string($conn, $details);
    $percentage    = ($total_esc > 0) ? round(($amount_esc / $total_esc) * 100) : 100;

    $existing = mysqli_query($conn, "
        SELECT id FROM refund_requests 
        WHERE reservation_id = $reservation_id 
        AND status IN ('pending','approved','processing')
    ");
    if ($existing && mysqli_num_rows($existing) > 0) {
        return false;
    }

    $insert = mysqli_query($conn, "
        INSERT INTO refund_requests (
            appointment_id, reservation_id, user_id, clinic_id,
            amount, refund_percentage, request_type, reason, details,
            status, refund_status, created_at,
            return_method, return_status
        ) VALUES (
            0, $reservation_id, $user_id, $clinic_id,
            $amount_esc, $percentage, 'refund', '$reason_esc', '$details_esc',
            'pending', 'pending', NOW(),
            'dropoff', 'pending'
        )
    ");

    if (!$insert) {
        error_log("Failed to create refund request: " . mysqli_error($conn));
        return false;
    }

    mysqli_query($conn, "UPDATE reservations SET refund_status = 'pending' WHERE id = $reservation_id");

    $staff_q = mysqli_query($conn, "
        SELECT id FROM users WHERE clinic_id = $clinic_id AND role IN ('ClinicAdmin','Staff')
    ");
    if ($staff_q && function_exists('addNotification')) {
        while ($staff = mysqli_fetch_assoc($staff_q)) {
            addNotification(
                (int) $staff['id'], 'refund',
                'New Refund Request 🙋',
                'Order #' . $order_code . ' refund request. Amount: ₱' . number_format($amount_esc, 2) . '. Reason: ' . $reason,
                'main.php?view=reservations'
            );
        }
    }

    return true;
}

// ===== AJAX: MARK ORDER AS RECEIVED =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_received') {
    header('Content-Type: application/json');

    $reservation_id = isset($_POST['reservation_id']) ? (int) $_POST['reservation_id'] : 0;

    if ($reservation_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit();
    }

    $check = mysqli_query($conn, "
        SELECT r.*, p.name AS product_name
        FROM reservations r
        JOIN products p ON r.product_id = p.id
        WHERE r.id = $reservation_id AND r.user_id = $user_id LIMIT 1
    ");

    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
        exit();
    }

    $order = mysqli_fetch_assoc($check);

    if ($order['status'] !== 'delivered') {
        echo json_encode(['success' => false, 'message' => 'Order is not in delivered state.']);
        exit();
    }

    $update = mysqli_query($conn, "
        UPDATE reservations SET status = 'completed', updated_at = NOW() WHERE id = $reservation_id AND user_id = $user_id
    ");

    if ($update && mysqli_affected_rows($conn) > 0) {
        $clinic_id = (int) $order['clinic_id'];
        $staff_q = mysqli_query($conn, "
            SELECT id FROM users WHERE clinic_id = $clinic_id AND role IN ('ClinicAdmin','Staff')
        ");
        if ($staff_q && function_exists('addNotification')) {
            while ($staff = mysqli_fetch_assoc($staff_q)) {
                addNotification(
                    (int) $staff['id'], 'reservation',
                    'Order Received by Customer ✅',
                    'Order #' . $order['reservation_code'] . ' has been marked as received.',
                    'main.php?view=reservations'
                );
            }
        }

        echo json_encode(['success' => true, 'message' => 'Order marked as received. Thank you!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update. Please try again.']);
    }
    exit();
}

// ===== AJAX: REQUEST REFUND / RETURN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_refund') {
    header('Content-Type: application/json');

    $reservation_id = isset($_POST['reservation_id']) ? (int) $_POST['reservation_id'] : 0;
    $reason         = trim($_POST['reason'] ?? '');
    $details        = trim($_POST['details'] ?? '');
    $refund_type    = ($_POST['refund_type'] ?? 'full') === 'partial' ? 'partial' : 'full';
    $partial_amount = isset($_POST['partial_amount']) ? (float) $_POST['partial_amount'] : null;
    
    $return_method        = trim($_POST['return_method'] ?? 'dropoff');
    $return_address       = trim($_POST['return_address'] ?? '');
    $return_scheduled_date = trim($_POST['return_scheduled_date'] ?? '');
    $return_scheduled_time = trim($_POST['return_scheduled_time'] ?? '');
    $return_contact_name  = trim($_POST['return_contact_name'] ?? '');
    $return_contact_phone = trim($_POST['return_contact_phone'] ?? '');
    $return_notes         = trim($_POST['return_notes'] ?? '');
    
    $valid_methods = ['dropoff', 'pickup', 'courier'];
    if (!in_array($return_method, $valid_methods)) {
        $return_method = 'dropoff';
    }
    
    if ($return_method === 'pickup' && empty($return_address)) {
        echo json_encode(['success' => false, 'message' => 'Pickup address is required for pickup method.']);
        exit();
    }

    if ($reservation_id <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Order ID and reason are required.']);
        exit();
    }

    $check = mysqli_query($conn, "
        SELECT r.*, c.name AS clinic_name
        FROM reservations r
        JOIN clinics c ON r.clinic_id = c.id
        WHERE r.id = $reservation_id AND r.user_id = $user_id LIMIT 1
    ");

    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
        exit();
    }

    $order = mysqli_fetch_assoc($check);

    if (!in_array($order['status'], ['delivered', 'completed'], true)) {
        echo json_encode(['success' => false, 'message' => 'Order is not eligible for refund.']);
        exit();
    }

    if (!empty($order['delivered_at'])) {
        $delivered_time = strtotime($order['delivered_at']);
        $deadline = $delivered_time + (7 * 24 * 60 * 60);
        if (time() > $deadline) {
            echo json_encode(['success' => false, 'message' => 'Refund window has expired (7 days from delivery).']);
            exit();
        }
    }

    $existing = mysqli_query($conn, "
        SELECT id FROM refund_requests
        WHERE reservation_id = $reservation_id AND status IN ('pending','approved','processing')
    ");
    if ($existing && mysqli_num_rows($existing) > 0) {
        echo json_encode(['success' => false, 'message' => 'A refund request already exists for this order.']);
        exit();
    }

    $refund_amount = ($refund_type === 'partial' && $partial_amount)
        ? $partial_amount
        : (float) $order['total_amount'];

    if ($refund_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid refund amount.']);
        exit();
    }
    if ($refund_amount > (float) $order['total_amount']) {
        echo json_encode(['success' => false, 'message' => 'Refund amount cannot exceed order total.']);
        exit();
    }

$evidence_paths = [];
$evidence_path = null;

if (isset($_FILES['evidence']) && is_array($_FILES['evidence']['name'])) {
    $file_count = count($_FILES['evidence']['name']);
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    
    $upload_dir = __DIR__ . '/../uploads/refund_evidence/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    for ($i = 0; $i < min($file_count, 5); $i++) {
        if ($_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['evidence']['size'][$i] > 5 * 1024 * 1024) continue;
        
        $tmp_name = $_FILES['evidence']['tmp_name'][$i];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types, true)) continue;
        
        $ext = pathinfo($_FILES['evidence']['name'][$i], PATHINFO_EXTENSION);
        $filename = 'refund_' . $reservation_id . '_' . time() . '_' . uniqid() . '.' . strtolower($ext);
        
        if (move_uploaded_file($tmp_name, $upload_dir . $filename)) {
            $evidence_paths[] = 'uploads/refund_evidence/' . $filename;
        }
    }
    
    if (!empty($evidence_paths)) {
        $evidence_path = $evidence_paths[0];
    }
} elseif (isset($_FILES['evidence']) && !is_array($_FILES['evidence']['name'])) {
    $file = $_FILES['evidence'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (in_array($mime_type, $allowed_types, true) && $file['size'] <= 5 * 1024 * 1024) {
        $upload_dir = __DIR__ . '/../uploads/refund_evidence/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'refund_' . $reservation_id . '_' . time() . '_' . uniqid() . '.' . strtolower($ext);
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            $evidence_path = 'uploads/refund_evidence/' . $filename;
            $evidence_paths[] = $evidence_path;
        }
    }
}

    $reason_esc  = mysqli_real_escape_string($conn, $reason);
    $details_esc = mysqli_real_escape_string($conn, $details);
    $clinic_id   = (int) $order['clinic_id'];
    
    $return_method_esc         = mysqli_real_escape_string($conn, $return_method);
    $return_address_esc        = mysqli_real_escape_string($conn, $return_address);
    $return_contact_name_esc   = mysqli_real_escape_string($conn, $return_contact_name);
    $return_contact_phone_esc  = mysqli_real_escape_string($conn, $return_contact_phone);
    $return_notes_esc          = mysqli_real_escape_string($conn, $return_notes);
    
    $return_date_sql = !empty($return_scheduled_date) 
        ? "'" . mysqli_real_escape_string($conn, $return_scheduled_date) . "'" 
        : "NULL";
    $return_time_sql = !empty($return_scheduled_time) 
        ? "'" . mysqli_real_escape_string($conn, $return_scheduled_time) . "'" 
        : "NULL";

$evidence_path_esc = $evidence_path ? mysqli_real_escape_string($conn, $evidence_path) : null;
$evidence_path_sql = $evidence_path_esc ? "'$evidence_path_esc'" : 'NULL';

$evidence_images_json = !empty($evidence_paths) ? json_encode($evidence_paths) : null;
$evidence_images_esc = $evidence_images_json ? mysqli_real_escape_string($conn, $evidence_images_json) : null;
$evidence_images_sql = $evidence_images_esc ? "'$evidence_images_esc'" : 'NULL';

$insert = mysqli_query($conn, "
    INSERT INTO refund_requests (
        appointment_id, reservation_id, user_id, clinic_id,
        amount, refund_percentage, request_type, reason, details,
        evidence_path, evidence_images,
        status, refund_status, created_at,
        return_method, return_address, 
        return_scheduled_date, return_scheduled_time,
        return_contact_name, return_contact_phone, return_notes,
        return_status
    ) VALUES (
        0, $reservation_id, $user_id, $clinic_id,
        $refund_amount, 100, 'refund', '$reason_esc', '$details_esc',
        $evidence_path_sql, $evidence_images_sql,
        'pending', 'pending', NOW(),
        '$return_method_esc', '$return_address_esc',
        $return_date_sql, $return_time_sql,
        '$return_contact_name_esc', '$return_contact_phone_esc', '$return_notes_esc',
        'pending'
    )
");

    if (!$insert) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit refund request: ' . mysqli_error($conn)]);
        exit();
    }

    $refund_id = mysqli_insert_id($conn);

    mysqli_query($conn, "
        UPDATE reservations SET refund_status = 'pending', updated_at = NOW() WHERE id = $reservation_id
    ");

    $staff_q = mysqli_query($conn, "
        SELECT id FROM users WHERE clinic_id = $clinic_id AND role IN ('ClinicAdmin','Staff')
    ");
    if ($staff_q && function_exists('addNotification')) {
        $reason_display = htmlspecialchars($reason, ENT_QUOTES);
        $return_method_labels = [
            'dropoff' => 'Drop-off at clinic',
            'pickup'  => 'Clinic pickup',
            'courier' => 'Courier'
        ];
        $rm_label = $return_method_labels[$return_method] ?? $return_method;
        
        while ($staff = mysqli_fetch_assoc($staff_q)) {
            addNotification(
                (int) $staff['id'], 'refund',
                'New Refund Request 🙋',
                'Order #' . $order['reservation_code'] . ' refund request. Amount: ₱' . number_format($refund_amount, 2) . '. Return: ' . $rm_label . '. Reason: ' . $reason_display,
                'main.php?view=reservations'
            );
        }
    }

    if (function_exists('addNotification')) {
        addNotification(
            $user_id, 'refund',
            '✅ Refund Request Submitted',
            "Your refund request for order #{$order['reservation_code']} has been submitted with return method: " . ($return_method_labels[$return_method] ?? $return_method) . ". Please wait for clinic review.",
            'my-reservations.php'
        );
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Refund request submitted successfully',
        'refund_id' => $refund_id
    ]);
    exit();
}

/* ═══════════════════════════════════════════════════════════════
   END AJAX HANDLERS
   ═══════════════════════════════════════════════════════════════ */

// Get user data for navbar
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

$pending_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

$unread_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $unread_count = getUnreadNotificationCount($user_id);
}
$recent_notifications = [];
if (function_exists('getRecentNotifications')) {
    $recent_notifications = getRecentNotifications($user_id);
}

$total_bookings_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($total_bookings_q)['total'] ?? 0;

$points_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points = $points_row['t'] ?: 0;

$reservations_query = mysqli_query($conn, "
    SELECT r.*, 
           p.name as product_name, 
           p.price, 
           p.image as product_image,
           p.images as product_images_old,
           p.images_json as product_images_json,
           p.category as product_category,
           p.warranty_period, 
           p.warranty_coverage, 
           p.warranty_terms, 
           p.warranty_premium_price,
           c.name as clinic_name,
           c.logo as clinic_logo,
           c.clinic_image,
           c.cover_photo,
           rr.id as refund_request_id,
           rr.amount as refund_amount,
           rr.refund_amount_actual,
           rr.refund_date,
           rr.paymongo_refund_id,
           rr.refund_status as rr_refund_status,
           rr.status as rr_status,
           rr.return_method,
           rr.return_address,
           rr.return_scheduled_date,
           rr.return_scheduled_time,
           rr.return_contact_name,
           rr.return_contact_phone,
           rr.return_notes,
           rr.return_status,
           rider.name as rider_name,
           rider.phone as rider_phone,
           rider.vehicle_type as rider_vehicle,
           rider.plate_number as rider_plate
    FROM reservations r
    JOIN products p ON r.product_id = p.id
    JOIN clinics c ON r.clinic_id = c.id
    LEFT JOIN refund_requests rr ON r.id = rr.reservation_id AND rr.id = (
        SELECT MAX(id) FROM refund_requests WHERE reservation_id = r.id
    )
    LEFT JOIN riders rider ON r.assigned_rider_id = rider.id
    WHERE r.user_id = $user_id
    ORDER BY r.created_at DESC
");

function getProductImageUrl($product) {
    $imageUrl = '/assets/img/no-image.png';
    
    if (!empty($product['product_images_json'])) {
        $images = json_decode($product['product_images_json'], true);
        if (!empty($images) && isset($images[0])) {
            $imagePath = $images[0];
            $imagePath = str_replace('uploads/uploads/', 'uploads/', $imagePath);
            
            if (strpos($imagePath, 'uploads/') === 0) {
                $imageUrl = '/' . $imagePath;
            } else {
                $imageUrl = '/uploads/products/' . $imagePath;
            }
            return $imageUrl;
        }
    }
    
    if (!empty($product['product_images_old'])) {
        $imagePath = $product['product_images_old'];
        if (strpos($imagePath, '[') === 0) {
            $images = json_decode($imagePath, true);
            if (!empty($images) && isset($images[0])) {
                $imagePath = $images[0];
            }
        }
        $imagePath = str_replace('uploads/uploads/', 'uploads/', $imagePath);
        
        if (strpos($imagePath, 'uploads/') === 0) {
            $imageUrl = '/' . $imagePath;
        } else {
            $imageUrl = '/uploads/products/' . $imagePath;
        }
        return $imageUrl;
    }
    
    if (!empty($product['product_image'])) {
        if (strpos($product['product_image'], 'uploads/') === false && strpos($product['product_image'], '/') === false) {
            $imageUrl = '/assets/images/products/' . $product['product_image'];
        } else {
            $imagePath = str_replace('uploads/uploads/', 'uploads/', $product['product_image']);
            if (strpos($imagePath, 'uploads/') === 0) {
                $imageUrl = '/' . $imagePath;
            } else {
                $imageUrl = '/uploads/products/' . $imagePath;
            }
        }
        return $imageUrl;
    }
    
    return $imageUrl;
}

function getClinicLogo($reservation) {
    if (!empty($reservation['logo'])) {
        $logo = trim($reservation['logo']);
        if (strpos($logo, 'uploads/') === 0) return '/' . $logo;
        if (strpos($logo, '/uploads/') === 0) return $logo;
        return '/assets/images/clinic-logos/' . $logo;
    }
    if (!empty($reservation['clinic_image'])) {
        $img = trim($reservation['clinic_image']);
        if (strpos($img, 'uploads/') === 0) return '/' . $img;
        if (strpos($img, '/uploads/') === 0) return $img;
        return '/assets/images/clinic-images/' . $img;
    }
    if (!empty($reservation['cover_photo'])) {
        $cover = trim($reservation['cover_photo']);
        if (strpos($cover, 'uploads/') === 0) return '/' . $cover;
        if (strpos($cover, '/uploads/') === 0) return $cover;
        return '/assets/images/clinic-covers/' . $cover;
    }
    return null;
}

function getCategoryIcon($category) {
    $icons = [
        'Frames' => 'fa-glasses',
        'Contact Lenses' => 'fa-eye',
        'Service' => 'fa-stethoscope',
        'Lenses' => 'fa-eye',
        'Accessories' => 'fa-shopping-bag',
        'Eyeglasses' => 'fa-glasses',
        'Sunglasses' => 'fa-sunglasses'
    ];
    return $icons[$category] ?? 'fa-box';
}

function getDeliveryStatusInfo($status) {
    $map = [
        'pending'    => ['label' => 'Order Placed',    'class' => 'ds-pending',    'icon' => 'fa-clock',        'step' => 1],
        'preparing'  => ['label' => 'Preparing',       'class' => 'ds-preparing',  'icon' => 'fa-box',          'step' => 2],
        'assigned'   => ['label' => 'Rider Assigned',  'class' => 'ds-assigned',   'icon' => 'fa-user-check',   'step' => 3],
        'picked_up'  => ['label' => 'Picked Up',       'class' => 'ds-picked-up',  'icon' => 'fa-box-open',     'step' => 4],
        'in_transit' => ['label' => 'In Transit',      'class' => 'ds-in-transit', 'icon' => 'fa-truck',        'step' => 5],
        'delivered'  => ['label' => 'Delivered',       'class' => 'ds-delivered',  'icon' => 'fa-check-circle', 'step' => 6],
        'failed'     => ['label' => 'Delivery Failed', 'class' => 'ds-failed',     'icon' => 'fa-times-circle', 'step' => 0],
        'cancelled'  => ['label' => 'Cancelled',       'class' => 'ds-cancelled',  'icon' => 'fa-ban',          'step' => 0],
    ];
    return $map[$status] ?? $map['pending'];
}

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
            'label' => 'Courier',
            'desc'  => 'Customer will ship via courier (Lalamove, Grab, etc.)',
            'icon'  => 'fa-shipping-fast',
            'color' => 'info'
        ]
    ];
    return $map[$method] ?? $map['dropoff'];
}

function getReturnStatusInfo($status) {
    $map = [
        'pending'    => ['label' => 'Awaiting Approval', 'class' => 'refund-pending',    'icon' => 'fa-clock'],
        'scheduled'  => ['label' => 'Return Scheduled',  'class' => 'refund-approved',   'icon' => 'fa-calendar-check'],
        'picked_up'  => ['label' => 'Item Picked Up',    'class' => 'refund-processing', 'icon' => 'fa-box-open'],
        'received'   => ['label' => 'Item Received',     'class' => 'refund-processing', 'icon' => 'fa-check'],
        'inspecting' => ['label' => 'Inspecting',        'class' => 'refund-processing', 'icon' => 'fa-search'],
        'completed'  => ['label' => 'Return Completed',  'class' => 'refund-completed',  'icon' => 'fa-check-double'],
        'rejected'   => ['label' => 'Return Rejected',   'class' => 'refund-rejected',   'icon' => 'fa-times-circle'],
    ];
    return $map[$status] ?? $map['pending'];
}

$active_nav = 'reservations';

$total_reservations = mysqli_num_rows($reservations_query);
$pending_count = 0;
$confirmed_count = 0;
$reservations_data = [];
while($temp = mysqli_fetch_assoc($reservations_query)) {
    if($temp['status'] == 'pending') $pending_count++;
    if($temp['status'] == 'confirmed') $confirmed_count++;
    $reservations_data[] = $temp;
}

function calculateWarrantyEndDateRes($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return null;
    
    $date = new DateTime($purchase_date);
    
    $period_map = [
        '3_months' => '+3 months',
        '6_months' => '+6 months',
        '12_months' => '+12 months',
        '24_months' => '+24 months',
        '36_months' => '+36 months'
    ];
    
    if (isset($period_map[$warranty_period])) {
        $date->modify($period_map[$warranty_period]);
        return $date->format('Y-m-d');
    }
    
    return null;
}

function isWithinWarrantyRes($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return false;
    if ($warranty_period == 'no_warranty') return false;
    
    $end_date = calculateWarrantyEndDateRes($purchase_date, $warranty_period);
    if (!$end_date) return false;
    
    $today = date('Y-m-d');
    return $end_date >= $today;
}

function getWarrantyPeriodDisplayRes($warranty_period) {
    $labels = [
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '12_months' => '12 Months',
        '24_months' => '24 Months',
        '36_months' => '36 Months'
    ];
    return $labels[$warranty_period] ?? '';
}

mysqli_data_seek($reservations_query, 0);
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Orders - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00874A;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg,#00B761,#00A86B);
            --secondary: #FF8C42;
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
            --success: #00B761;
            --info: #3B82F6;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #0D2818;
            --bg-primary: #0D0D0D;
            --bg-secondary: #161616;
            --card-bg: #1E1E1E;
            --text-primary: #F9FAFB;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #2A2A2A;
            --border-light: #222222;
        }

        .main-content { max-width: 1200px; margin: 0 auto; padding: 28px 40px 100px; }
        @media (max-width: 1024px) { .main-content { padding: 24px; } }
        @media (max-width: 768px) { .main-content { padding: 18px 16px 100px; } }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-title { display: flex; align-items: center; gap: 12px; }
        .page-title i { font-size: 28px; color: var(--primary); background: var(--primary-light); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-full); }
        .page-title h1 { font-size: 24px; font-weight: 700; color: var(--text-primary); }
        .back-btn { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-full); color: var(--text-secondary); text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.3s; }
        .back-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 16px 20px; border: 1px solid var(--border-light); transition: all 0.2s; }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--primary); line-height: 1; }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-top: 5px; display: flex; align-items: center; gap: 5px; }

        /* ═══ ORDERS TOOLBAR (Tabs + Search) ═══ */
        .orders-toolbar {
            display: flex;
            gap: 16px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .orders-search {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 380px;
            margin-left: auto;
        }

        .orders-search i.fa-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 13px;
            pointer-events: none;
        }

        .orders-search input {
            width: 100%;
            padding: 10px 38px 10px 38px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 13px;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.2s;
            outline: none;
        }

        .orders-search input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
        }

        .orders-search input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }

        .orders-search .search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--bg-primary);
            border: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 10px;
            transition: all 0.2s;
        }

        .orders-search .search-clear:hover {
            background: var(--danger);
            color: white;
        }

        @media (max-width: 640px) {
            .orders-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .orders-search {
                max-width: 100%;
                margin-left: 0;
            }
        }

        /* ═══ FILTER TABS ═══ */
        .filter-tabs {
            display: flex;
            flex-wrap: nowrap;           /* ✅ HUWAG mag-wrap pababa */
            gap: 8px;
            overflow-x: auto;             /* ✅ Scroll pakanan */
            overflow-y: hidden;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 2px;
            width: 100%;
            max-width: 100%;
        }
        .filter-tabs::-webkit-scrollbar { display: none; }

        /* ✅ Siguraduhing hindi mag-shrink 'yung tabs */
        .filter-tab {
            flex-shrink: 0;
            white-space: nowrap;
        }

        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: var(--radius-full);
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            font-family: inherit;
            flex-shrink: 0;
        }
        .filter-tab:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .filter-tab.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(0,183,97,0.25);
        }
        .filter-tab .tab-count {
            background: rgba(0,0,0,0.08);
            color: inherit;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.4;
        }
        .filter-tab.active .tab-count {
            background: rgba(255,255,255,0.25);
        }
        .theme-dark .filter-tab .tab-count {
            background: rgba(255,255,255,0.08);
        }

        .reservations-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .reservation-card { background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--border-light); overflow: hidden; transition: all 0.2s; }
        .reservation-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--primary); }

        .product-image-section { position: relative; height: 200px; overflow: hidden; background: linear-gradient(135deg, var(--primary-light), var(--bg-primary)); cursor: pointer; }
        .product-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .product-image-section:hover .product-image { transform: scale(1.05); }
        .image-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
        .product-image-section:hover .image-overlay { opacity: 1; }
        .image-overlay i { color: white; font-size: 32px; background: rgba(0,0,0,0.6); padding: 12px; border-radius: 50%; }
        .product-placeholder { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; background: var(--bg-primary); }
        .product-placeholder i { font-size: 56px; color: var(--primary); opacity: 0.5; }
        .product-placeholder span { font-size: 13px; color: var(--text-muted); }

        .card-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; }
        .card-header-info { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
        .clinic-badge { display: inline-flex; align-items: center; gap: 6px; background: var(--bg-primary); padding: 4px 10px; border-radius: var(--radius-full); font-size: 11px; color: var(--text-secondary); }
        .clinic-badge i { color: var(--primary); font-size: 10px; }
        .product-name { font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }

        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; }
        .status-pending { background: #FEF3C7; color: #92400E; }
        .status-confirmed { background: #D1FAE5; color: #065F46; }
        .status-cancelled { background: #FEE2E2; color: #991B1B; }
        .status-completed { background: #EDE9FE; color: #5B21B6; }
        .status-ready { background: #DBEAFE; color: #1E40AF; }
        .status-expired { background: #F3F4F6; color: #6B7280; }
        .status-noshow { background: #FFF7ED; color: #9A3412; }

        .reservation-details { display: flex; flex-direction: column; gap: 8px; margin: 8px 0; padding: 8px 0; border-top: 1px solid var(--border-light); border-bottom: 1px solid var(--border-light); }
        .detail-row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
        .detail-label { color: var(--text-muted); }
        .detail-value { font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 6px; }
        .color-preview { width: 16px; height: 16px; border-radius: 50%; display: inline-block; border: 2px solid white; box-shadow: 0 0 0 1px var(--border-color); }

        .payment-info { background: var(--bg-primary); border-radius: var(--radius-md); padding: 10px 12px; margin-top: 4px; }
        .payment-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; }
        .payment-row:last-child { margin-bottom: 0; }
        .payment-label { color: var(--text-muted); }
        .payment-amount { font-weight: 700; color: var(--primary); }
        .payment-balance { font-weight: 700; color: var(--warning); }
                /* ═══ PAYMENT METHOD BADGE ═══ */
        .payment-method-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
        }
        .payment-method-row.cod {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FCD34D;
        }
        .payment-method-row.paid {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #6EE7B7;
        }
        .payment-method-row.partial {
            background: #DBEAFE;
            color: #1E40AF;
            border: 1px solid #93C5FD;
        }
        .payment-method-row.unpaid {
            background: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FCA5A5;
        }
        .payment-method-row.onsite {
            background: #FFEDD5;
            color: #9A3412;
            border: 1px solid #FDBA74;
        }
        .payment-method-row.free {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #6EE7B7;
        }
        .payment-method-row.refunded {
            background: #EDE9FE;
            color: #5B21B6;
            border: 1px solid #C4B5FD;
        }
        .payment-method-row i {
            font-size: 14px;
            flex-shrink: 0;
        }
        .payment-method-row .pm-text {
            flex: 1;
            line-height: 1.4;
        }
        .payment-method-row .pm-text strong {
            font-weight: 800;
        }

        .theme-dark .payment-method-row.cod {
            background: #3B2F0F;
            color: #FDE68A;
            border-color: #B45309;
        }
        .theme-dark .payment-method-row.paid,
        .theme-dark .payment-method-row.free {
            background: #0D2818;
            color: #6EE7B7;
            border-color: #065F46;
        }
        .theme-dark .payment-method-row.partial {
            background: #1E3A5F;
            color: #93C5FD;
            border-color: #1E40AF;
        }
        .theme-dark .payment-method-row.unpaid {
            background: #3B0F0F;
            color: #FCA5A5;
            border-color: #991B1B;
        }
        .theme-dark .payment-method-row.onsite {
            background: #3B1F0F;
            color: #FDBA74;
            border-color: #9A3412;
        }
        .theme-dark .payment-method-row.refunded {
            background: #3B1F5C;
            color: #D8B4FE;
            border-color: #7C3AED;
        }
        .card-actions { display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .btn { flex: 1; padding: 10px; border-radius: var(--radius-md); font-size: 13px; font-weight: 600; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s; cursor: pointer; border: none; min-width: 80px; }
        .btn-primary { background: var(--primary-gradient); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,183,97,0.3); }
        .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); }
        .btn-outline:hover { background: var(--bg-primary); border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: transparent; border: 1px solid var(--danger); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }
        .btn-warning { background: var(--warning); color: white; border: none; }
        .btn-warning:hover { background: #e67e22; transform: translateY(-2px); }

        .warranty-section { background: var(--bg-primary); border-radius: var(--radius-md); padding: 10px 12px; margin-top: 8px; }
        .warranty-badge { background: var(--primary-light); color: var(--primary); padding: 2px 6px; border-radius: var(--radius-full); font-size: 10px; font-weight: 600; margin-left: 6px; }
        .warranty-status.active { background: #E8F5E9; color: #2E7D32; border-radius: var(--radius-md); padding: 4px 8px; font-size: 11px; }
        .warranty-status.expired { background: #FFEBEE; color: #EF4444; border-radius: var(--radius-md); padding: 4px 8px; font-size: 11px; }
        .theme-dark .warranty-status.active { background: #0D2818; color: #00E676; }
        .theme-dark .warranty-status.expired { background: #3B0F0F; color: #EF4444; }

        .btn-warranty-claim-small { background: linear-gradient(135deg, #00B761, #00A86B); color: white; border: none; border-radius: var(--radius-sm); padding: 8px 12px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; }
        .btn-warranty-claim-small:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,183,97,0.3); }
        .btn-warranty-expired-small { background: #9CA3AF; color: white; border: none; border-radius: var(--radius-sm); padding: 8px 12px; font-size: 12px; font-weight: 600; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; opacity: 0.7; }
        .warranty-note small { color: var(--text-muted); font-size: 11px; }

        .image-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 10000; cursor: pointer; align-items: center; justify-content: center; }
        .image-modal.show { display: flex; }
        .modal-image { max-width: 90%; max-height: 90%; object-fit: contain; border-radius: var(--radius-lg); }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 40px; cursor: pointer; transition: all 0.2s; }
        .modal-close:hover { color: var(--primary); }

        .empty-state-reservations { text-align: center; padding: 80px 40px; background: var(--bg-secondary); border-radius: var(--radius-xl); border: 2px dashed var(--border-color); position: relative; overflow: hidden; max-width: 600px; margin: 0 auto; width: 100%; }
        .empty-state-reservations::before { content: ''; position: absolute; top: -40%; right: -20%; width: 250px; height: 250px; background: radial-gradient(circle, rgba(0,183,97,0.04) 0%, transparent 70%); border-radius: 50%; pointer-events: none; }
        .empty-state-reservations .empty-icon-wrap { display: inline-block; background: linear-gradient(135deg, var(--primary-light), #B7F5D2); padding: 24px; border-radius: var(--radius-full); margin-bottom: 20px; }
        .empty-state-reservations .empty-icon-wrap i { font-size: 56px; color: var(--primary); display: block; }
        .empty-state-reservations h2 { font-size: 24px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
        .empty-state-reservations p { color: var(--text-secondary); font-size: 15px; line-height: 1.6; max-width: 400px; margin: 0 auto 24px; }
        .empty-state-reservations .empty-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .empty-state-reservations .empty-btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: var(--primary-gradient); color: white; border-radius: var(--radius-full); font-size: 14px; font-weight: 600; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 14px rgba(0,183,97,0.25); }
        .empty-state-reservations .empty-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,183,97,0.35); }
        .empty-state-reservations .empty-btn-secondary { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: transparent; color: var(--text-secondary); border-radius: var(--radius-full); font-size: 14px; font-weight: 600; text-decoration: none; border: 2px solid var(--border-color); transition: all 0.2s; }
        .empty-state-reservations .empty-btn-secondary:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }
        .empty-state-reservations .empty-tip { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-light); display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
        .empty-state-reservations .empty-tip i { color: var(--warning); }

        /* ===== DELIVERY FEATURE STYLES ===== */
        .delivery-section { background: linear-gradient(135deg, rgba(0,183,97,0.06), rgba(0,183,97,0.02)); border: 1px solid rgba(0,183,97,0.2); border-radius: var(--radius-md); padding: 12px 14px; margin-top: 4px; display: flex; flex-direction: column; gap: 10px; }
        .delivery-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .delivery-header > div { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--text-primary); }
        .delivery-header > div i { color: var(--primary); font-size: 14px; }
        .delivery-status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; white-space: nowrap; }
        .ds-pending    { background: #F3F4F6; color: #4B5563; }
        .ds-preparing  { background: #FEF3C7; color: #92400E; }
        .ds-assigned   { background: #CFFAFE; color: #0E7490; }
        .ds-picked-up  { background: #DBEAFE; color: #1E40AF; }
        .ds-in-transit { background: #E9D5FF; color: #6B21A8; }
        .ds-delivered  { background: #D1FAE5; color: #065F46; }
        .ds-failed     { background: #FEE2E2; color: #991B1B; }
        .ds-cancelled  { background: #FEE2E2; color: #991B1B; }
        .theme-dark .ds-pending    { background: #2A2A2A; color: #9CA3AF; }
        .theme-dark .ds-preparing  { background: #3B2F0F; color: #FDE68A; }
        .theme-dark .ds-assigned   { background: #0E3A44; color: #67E8F9; }
        .theme-dark .ds-picked-up  { background: #1E3A5F; color: #93C5FD; }
        .theme-dark .ds-in-transit { background: #3B1F5C; color: #D8B4FE; }
        .theme-dark .ds-delivered  { background: #0D2818; color: #6EE7B7; }
        .theme-dark .ds-failed     { background: #3B0F0F; color: #FCA5A5; }
        .theme-dark .ds-cancelled  { background: #3B0F0F; color: #FCA5A5; }

        .delivery-address { display: flex; gap: 10px; font-size: 12px; color: var(--text-secondary); line-height: 1.5; padding: 8px 10px; background: var(--bg-secondary); border-radius: var(--radius-sm); }
        .delivery-address > i { color: var(--primary); font-size: 14px; margin-top: 2px; flex-shrink: 0; }
        .delivery-address strong { color: var(--text-primary); font-size: 12.5px; display: block; margin-bottom: 2px; }
        .delivery-tracking { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 12px; color: var(--text-secondary); }
        .delivery-tracking i { color: var(--primary); font-size: 14px; }
        .delivery-tracking strong { color: var(--text-primary); font-family: 'Courier New', monospace; letter-spacing: 0.5px; }
        .delivery-fee-row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding-top: 6px; border-top: 1px dashed rgba(0,183,97,0.25); }
        .delivery-fee-row span { color: var(--text-muted); }
        .delivery-fee-row strong { color: var(--primary); font-size: 13px; }
        .delivery-fee-row strong.free { color: var(--success); text-transform: uppercase; letter-spacing: 0.5px; }
        .fulfillment-badge-pickup { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--bg-primary); border-radius: var(--radius-full); font-size: 11px; font-weight: 700; color: var(--text-secondary); margin-top: 4px; align-self: flex-start; }
        .fulfillment-badge-pickup i { color: var(--primary); font-size: 12px; }
        .delivery-progress { display: flex; gap: 3px; margin-top: 4px; }
        .delivery-progress-step { height: 4px; flex: 1; background: var(--border-color); border-radius: 2px; transition: background 0.3s; }
        .delivery-progress-step.active { background: var(--primary); }

        /* REFUND / RETURN STYLES */
        .refund-section { background: linear-gradient(135deg, rgba(239,68,68,0.04), rgba(239,68,68,0.01)); border: 1px solid rgba(239,68,68,0.15); border-radius: var(--radius-md); padding: 12px 14px; margin-top: 4px; display: flex; flex-direction: column; gap: 8px; }
        .refund-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; white-space: nowrap; }
        .refund-pending    { background: #FEF3C7; color: #92400E; }
        .refund-approved   { background: #DBEAFE; color: #1E40AF; }
        .refund-processing { background: #E9D5FF; color: #6B21A8; }
        .refund-completed  { background: #D1FAE5; color: #065F46; }
        .refund-rejected   { background: #FEE2E2; color: #991B1B; }
        .refund-failed     { background: #FEE2E2; color: #991B1B; }
        .theme-dark .refund-pending    { background: #3B2F0F; color: #FDE68A; }
        .theme-dark .refund-approved   { background: #1E3A5F; color: #93C5FD; }
        .theme-dark .refund-processing { background: #3B1F5C; color: #D8B4FE; }
        .theme-dark .refund-completed  { background: #0D2818; color: #6EE7B7; }
        .theme-dark .refund-rejected   { background: #3B0F0F; color: #FCA5A5; }
        .theme-dark .refund-failed     { background: #3B0F0F; color: #FCA5A5; }
        .refund-reason { font-size: 12px; color: var(--text-secondary); line-height: 1.5; padding: 6px 10px; background: var(--bg-secondary); border-radius: var(--radius-sm); border-left: 3px solid var(--danger); }
        .refund-reason strong { color: var(--text-primary); }
        .refund-amount { font-size: 13px; font-weight: 700; color: var(--danger); }

        .return-method-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            border-left: 3px solid var(--primary);
        }
        .return-method-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .return-method-header i {
            color: var(--primary);
            font-size: 14px;
        }
        .return-method-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .return-method-details .row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
        }
        .return-method-details .row .lbl {
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .return-method-details .row .val {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }

        .refund-modal-reasons { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
        .refund-reason-option { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 2px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s; background: var(--bg-secondary); }
        .refund-reason-option:hover { border-color: var(--primary); background: var(--primary-light); }
        .refund-reason-option.selected { border-color: var(--primary); background: var(--primary-light); }
        .refund-reason-option input[type="radio"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        .refund-reason-option label { cursor: pointer; font-size: 13px; font-weight: 500; flex: 1; margin: 0; }
        .refund-reason-option i { font-size: 16px; color: var(--primary); }

        .refund-type-toggle { display: flex; gap: 10px; margin-bottom: 16px; }
        .refund-type-option { flex: 1; padding: 10px 14px; border: 2px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s; background: var(--bg-secondary); text-align: center; }
        .refund-type-option:hover { border-color: var(--primary); }
        .refund-type-option.selected { border-color: var(--primary); background: var(--primary-light); }
        .refund-type-option input[type="radio"] { display: none; }
        .refund-type-option label { cursor: pointer; font-size: 13px; font-weight: 600; margin: 0; display: block; }
        .refund-type-option small { display: block; font-size: 11px; color: var(--text-muted); margin-top: 4px; font-weight: 400; }

        .return-method-toggle {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .return-method-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-secondary);
        }
        .return-method-option:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .return-method-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 2px 8px rgba(0,183,97,0.15);
        }
        .return-method-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .return-method-option .rm-content {
            flex: 1;
        }
        .return-method-option .rm-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .return-method-option .rm-label i {
            color: var(--primary);
            font-size: 14px;
        }
        .return-method-option .rm-desc {
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .conditional-fields {
            display: none;
            margin-top: 12px;
            padding: 12px 14px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            border: 1px dashed var(--border-color);
        }
        .conditional-fields.show {
            display: block;
        }

        .refund-completed-card {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-top: 8px;
        }

        .refund-completed-header {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #065F46;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #6EE7B7;
        }

        .refund-completed-header i {
            font-size: 16px;
        }

        .refund-completed-details {
            color: #065F46;
            font-size: 12px;
            line-height: 1.7;
        }

        .refund-completed-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 4px;
        }

        .refund-completed-row .lbl {
            color: #047857;
            flex-shrink: 0;
        }

        .refund-completed-row .val {
            font-weight: 700;
            color: #065F46;
            text-align: right;
            word-break: break-word;
        }

        .refund-completed-note {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #6EE7B7;
            font-size: 11px;
            color: #047857;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .refund-completed-note i {
            font-size: 12px;
        }

        /* ═══ CARD ACCORDION (Details toggle) ═══ */
        .card-details-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-primary);
            border: 1px dashed var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.2s;
            user-select: none;
        }
        .card-details-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .card-details-toggle .chevron {
            transition: transform 0.25s;
            font-size: 11px;
        }
        .card-details-toggle.open .chevron {
            transform: rotate(180deg);
        }
        .card-details-body {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--border-color);
            animation: detailsSlide 0.25s ease;
        }
        .card-details-body.open {
            display: block;
        }
        @keyframes detailsSlide {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ═══ MORE MENU (secondary actions) ═══ */
        .more-menu-wrap {
            position: relative;
        }
        .more-menu-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .more-menu-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .more-menu-list {
            position: absolute;
            bottom: calc(100% + 6px);
            right: 0;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            min-width: 200px;
            padding: 6px;
            display: none;
            z-index: 50;
        }
        .more-menu-list.open { display: block; }
        .more-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            text-decoration: none;
            font-family: inherit;
        }
        .more-menu-item:hover {
            background: var(--bg-primary);
        }
        .more-menu-item.danger { color: var(--danger); }
        .more-menu-item.danger:hover { background: #FEE2E2; }
        .theme-dark .more-menu-item.danger:hover { background: #3B0F0F; }
        .more-menu-item i {
            width: 16px;
            text-align: center;
            font-size: 13px;
        }

        /* Mobile: More menu becomes full-width bottom */
        @media (max-width: 480px) {
            .more-menu-list {
                min-width: 180px;
            }
        }
    </style>
</head>
<body>
   <?php include '../includes/navbar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-shopping-bag"></i>
                <h1>My Orders</h1>
            </div>
        </div>

        <!-- ═══ ORDER FILTER TABS + SEARCH ═══ -->
        <?php
            $count_all = 0;
            $count_pending = 0;
            $count_to_receive = 0;
            $count_completed = 0;
            $count_cancelled = 0;

            foreach ($reservations_data as $r) {
                $count_all++;
                $st = $r['status'];
                if (in_array($st, ['pending', 'confirmed', 'ready_for_pickup'])) {
                    $count_pending++;
                } elseif (in_array($st, ['delivered'])) {
                    $count_to_receive++;
                } elseif (in_array($st, ['completed'])) {
                    $count_completed++;
                } elseif (in_array($st, ['cancelled', 'expired', 'no_show'])) {
                    $count_cancelled++;
                }
            }
        ?>
        <?php if ($total_reservations > 0): ?>
        <div class="orders-toolbar">
            <div class="filter-tabs" id="orderFilterTabs">
                <button type="button" class="filter-tab active" data-filter="all">
                    <i class="fas fa-list"></i> All
                    <span class="tab-count"><?php echo $count_all; ?></span>
                </button>
                <button type="button" class="filter-tab" data-filter="pending">
                    <i class="fas fa-clock"></i> Confirmed
                    <span class="tab-count"><?php echo $count_pending; ?></span>
                </button>
                <button type="button" class="filter-tab" data-filter="to_receive">
                    <i class="fas fa-truck"></i> To Receive
                    <span class="tab-count"><?php echo $count_to_receive; ?></span>
                </button>
                <button type="button" class="filter-tab" data-filter="completed">
                    <i class="fas fa-check-double"></i> Completed
                    <span class="tab-count"><?php echo $count_completed; ?></span>
                </button>
                <button type="button" class="filter-tab" data-filter="cancelled">
                    <i class="fas fa-times-circle"></i> Cancelled
                    <span class="tab-count"><?php echo $count_cancelled; ?></span>
                </button>
            </div>

            <div class="orders-search">
                <i class="fas fa-search"></i>
                <input type="text" id="ordersSearchInput" placeholder="Search by order #, product, or clinic..." autocomplete="off">
                <button type="button" class="search-clear" id="searchClearBtn" onclick="clearOrderSearch()" style="display:none;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ ORDER FILTER TABS ═══ -->
        <?php
            $count_all = 0;
            $count_pending = 0;
            $count_to_receive = 0;
            $count_completed = 0;
            $count_cancelled = 0;

            foreach ($reservations_data as $r) {
                $count_all++;
                $st = $r['status'];
                if (in_array($st, ['pending', 'confirmed', 'ready_for_pickup'])) {
                    $count_pending++;
                } elseif (in_array($st, ['delivered'])) {
                    $count_to_receive++;
                } elseif (in_array($st, ['completed'])) {
                    $count_completed++;
                } elseif (in_array($st, ['cancelled', 'expired', 'no_show'])) {
                    $count_cancelled++;
                }
            }
        ?>

        <?php if ($total_reservations > 0): ?>
            <div class="reservations-grid">
                <?php foreach($reservations_data as $res): 
                    $status_class = '';
                    $status_text = '';
                    
                    switch($res['status']) {
                        case 'pending': $status_class = 'status-pending'; $status_text = 'Pending'; break;
                        case 'confirmed': $status_class = 'status-confirmed'; $status_text = 'Confirmed'; break;
                        case 'ready_for_pickup': $status_class = 'status-ready'; $status_text = 'Ready for Pickup'; break;
                        case 'completed': $status_class = 'status-completed'; $status_text = 'Completed'; break;
                        case 'cancelled': $status_class = 'status-cancelled'; $status_text = 'Cancelled'; break;
                        case 'expired': $status_class = 'status-expired'; $status_text = 'Expired'; break;
                        case 'no_show': $status_class = 'status-noshow'; $status_text = 'No Show'; break;
                        default: $status_class = 'status-pending'; $status_text = ucfirst(str_replace('_', ' ', $res['status']));
                    }
                    
                    // ✅ Filter group
                    $filter_group = 'other';
                    if (in_array($res['status'], ['pending', 'confirmed', 'ready_for_pickup'])) {
                        $filter_group = 'pending';
                    } elseif ($res['status'] === 'delivered') {
                        $filter_group = 'to_receive';
                    } elseif ($res['status'] === 'completed') {
                        $filter_group = 'completed';
                    } elseif (in_array($res['status'], ['cancelled', 'expired', 'no_show'])) {
                        $filter_group = 'cancelled';
                    }
                    
                    $product_image_url = getProductImageUrl($res);
                    $category_icon = getCategoryIcon($res['product_category']);
                    $clinic_logo = getClinicLogo($res);
                    
                    $downpayment_percent = 30;
                    if (!empty($res['downpayment_amount']) && $res['downpayment_amount'] > 0 && $res['total_amount'] > 0) {
                        $downpayment_percent = round(($res['downpayment_amount'] / $res['total_amount']) * 100);
                    }

                    $has_warranty = false;
                    $is_within_warranty = false;
                    $warranty_end_date = null;
                    $warranty_display = '';
                    $can_claim_warranty = false;
                    $warranty_coverage = [];
                    $warranty_terms = '';

                    if (!empty($res['product_id']) && !empty($res['warranty_period']) && $res['warranty_period'] != 'no_warranty') {
                        $has_warranty = true;
                        $warranty_display = getWarrantyPeriodDisplayRes($res['warranty_period']);
                        
                        if (!empty($res['warranty_coverage'])) {
                            $warranty_coverage = json_decode($res['warranty_coverage'], true);
                            if (!is_array($warranty_coverage)) $warranty_coverage = [];
                        }
                        if (!empty($res['warranty_terms'])) {
                            $warranty_terms = $res['warranty_terms'];
                        }
                        
                        if (!empty($res['created_at'])) {
                            $warranty_end_date = calculateWarrantyEndDateRes($res['created_at'], $res['warranty_period']);
                            $is_within_warranty = isWithinWarrantyRes($res['created_at'], $res['warranty_period']);
                            $can_claim_warranty = ($res['status'] == 'completed' && $is_within_warranty);
                        }
                        
                        $existing_claim = null;
                        $claim_status_check = mysqli_query($conn, "
                            SELECT id, status 
                            FROM warranty_claims 
                            WHERE product_id = {$res['product_id']} 
                            AND user_id = $user_id 
                            AND status IN ('pending', 'processing', 'approved', 'arrived', 'completed')
                            ORDER BY id DESC
                            LIMIT 1
                        ");
                        
                        if ($claim_status_check && mysqli_num_rows($claim_status_check) > 0) {
                            $existing_claim = mysqli_fetch_assoc($claim_status_check);
                            $can_claim_warranty = false;
                        }
                    }
                ?>
                    <div class="reservation-card" data-reservation-id="<?php echo $res['id']; ?>" data-filter-group="<?php echo $filter_group; ?>">
                        <div class="product-image-section" onclick="showImageModal('<?php echo htmlspecialchars($product_image_url); ?>', '<?php echo htmlspecialchars($res['product_name']); ?>')">
                            <?php if ($product_image_url && strpos($product_image_url, 'no-image.png') === false): ?>
                                <img src="<?php echo htmlspecialchars($product_image_url); ?>" alt="<?php echo htmlspecialchars($res['product_name']); ?>" class="product-image"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'product-placeholder\'><i class=\'fas <?php echo $category_icon; ?>\'></i><span><?php echo htmlspecialchars($res['product_name']); ?></span></div>'">
                            <?php else: ?>
                                <div class="product-placeholder">
                                    <i class="fas <?php echo $category_icon; ?>"></i>
                                    <span><?php echo htmlspecialchars($res['product_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="image-overlay">
                                <i class="fas fa-search-plus"></i>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="card-header-info">
                                <div class="clinic-badge">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($res['clinic_name']); ?>
                                </div>
                                <div style="margin-left: auto;">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas <?php 
                                            $icon_map = [
                                                'pending' => 'fa-clock', 'confirmed' => 'fa-check-circle',
                                                'ready_for_pickup' => 'fa-box-open', 'completed' => 'fa-check-double',
                                                'cancelled' => 'fa-times-circle', 'expired' => 'fa-hourglass-end',
                                                'no_show' => 'fa-user-times',
                                            ];
                                            echo $icon_map[$res['status']] ?? 'fa-circle';
                                        ?>"></i>
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="product-name">
                                <?php echo htmlspecialchars($res['product_name']); ?>
                            </div>

                            <?php if (($res['fulfillment_type'] ?? 'pickup') === 'delivery'): ?>
                                <?php 
                                    $delivery_info = getDeliveryStatusInfo($res['delivery_status'] ?? 'pending'); 
                                    $progress_step = $delivery_info['step'];
                                ?>
                                <div class="delivery-section">
                                    <div class="delivery-header">
                                        <div>
                                            <i class="fas fa-truck"></i>
                                            <span>Delivery</span>
                                        </div>
                                        <span class="delivery-status-badge <?php echo $delivery_info['class']; ?>">
                                            <i class="fas <?php echo $delivery_info['icon']; ?>"></i>
                                            <?php echo $delivery_info['label']; ?>
                                        </span>
                                    </div>

                                    <?php if ($progress_step > 0): ?>
                                    <div class="delivery-progress">
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                            <div class="delivery-progress-step <?php echo ($i <= $progress_step) ? 'active' : ''; ?>"></div>
                                        <?php endfor; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="delivery-address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <div>
                                            <strong><?php echo htmlspecialchars($res['delivery_name'] ?? ''); ?></strong>
                                            <?php
                                            $addr_parts = array_filter([
                                                $res['delivery_address'] ?? '',
                                                $res['delivery_barangay'] ?? '',
                                                $res['delivery_city'] ?? '',
                                                $res['delivery_province'] ?? '',
                                                $res['delivery_zip'] ?? '',
                                            ]);
                                            echo htmlspecialchars(implode(', ', $addr_parts));
                                            ?>
                                            <?php if (!empty($res['delivery_landmark'])): ?>
                                                <br><small style="color: var(--text-muted);"><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($res['delivery_landmark']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($res['tracking_number'])): ?>
                                    <div class="delivery-tracking">
                                        <i class="fas fa-barcode"></i>
                                        <span>Tracking #: <strong><?php echo htmlspecialchars($res['tracking_number']); ?></strong></span>
                                    </div>
                                    <?php endif; ?>

                                    <!-- ✅ RIDER INFO CARD (dynamic badge) -->
                                    <?php if (!empty($res['rider_name']) && !in_array($res['delivery_status'] ?? '', ['cancelled', 'failed', 'delivered'])): ?>
                                    <div style="background: linear-gradient(135deg, rgba(0,183,97,0.06), rgba(0,183,97,0.02)); border: 1px solid rgba(0,183,97,0.2); border-radius: var(--radius-md); padding: 10px 12px;">
                                        <?php 
                                            $rider_status = $res['delivery_status'] ?? 'pending';
                                            $badge_map = [
                                                'assigned'   => ['label' => 'ASSIGNED',   'bg' => '#CFFAFE', 'color' => '#0E7490', 'dot' => 'fa-circle'],
                                                'picked_up'  => ['label' => 'PICKED UP',  'bg' => '#DBEAFE', 'color' => '#1E40AF', 'dot' => 'fa-circle'],
                                                'in_transit' => ['label' => 'ON THE WAY', 'bg' => '#E9D5FF', 'color' => '#6B21A8', 'dot' => 'fa-circle'],
                                                'delivered'  => ['label' => 'DELIVERED',  'bg' => '#D1FAE5', 'color' => '#065F46', 'dot' => 'fa-check-circle'],
                                                'failed'     => ['label' => 'FAILED',     'bg' => '#FEE2E2', 'color' => '#991B1B', 'dot' => 'fa-times-circle'],
                                                'cancelled'  => ['label' => 'CANCELLED',  'bg' => '#FEE2E2', 'color' => '#991B1B', 'dot' => 'fa-ban'],
                                            ];
                                            $rbadge = $badge_map[$rider_status] ?? $badge_map['assigned'];
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                            <i class="fas fa-motorcycle" style="color: var(--primary); font-size: 13px;"></i>
                                            <strong style="font-size: 12.5px; color: var(--text-primary);">Your Rider</strong>
                                            <span style="margin-left: auto; background: <?php echo $rbadge['bg']; ?>; color: <?php echo $rbadge['color']; ?>; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas <?php echo $rbadge['dot']; ?>" style="font-size: 8px;"></i> <?php echo $rbadge['label']; ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0; border: 2px solid white; box-shadow: 0 0 0 1px var(--border-color);">
                                                <?php echo strtoupper(substr($res['rider_name'], 0, 1)); ?>
                                            </div>
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="font-size: 13px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">
                                                    <?php echo htmlspecialchars($res['rider_name']); ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                                    <?php if (!empty($res['rider_vehicle'])): ?>
                                                        <i class="fas fa-motorcycle"></i> <?php echo htmlspecialchars($res['rider_vehicle']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($res['rider_plate'])): ?>
                                                        <span style="font-family: monospace; margin-left: 4px;">• <?php echo htmlspecialchars($res['rider_plate']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($res['rider_phone'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($res['rider_phone']); ?>"
                                               style="display: inline-flex; align-items: center; gap: 5px; padding: 8px 14px; background: var(--primary); color: white; border-radius: var(--radius-full); font-size: 12px; font-weight: 700; text-decoration: none; flex-shrink: 0; transition: all 0.2s;"
                                               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,183,97,0.3)';"
                                               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                                <i class="fas fa-phone"></i> Call
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($res['delivered_at'])): ?>
                                    <div class="delivery-tracking">
                                        <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                        <span>Delivered on <strong><?php echo date('M d, Y g:i A', strtotime($res['delivered_at'])); ?></strong></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (($res['delivery_status'] ?? '') === 'cancelled' && !empty($res['cancelled_reason'])): ?>
                                    <div class="delivery-tracking" style="background: rgba(239,68,68,0.08);">
                                        <i class="fas fa-ban" style="color: var(--danger);"></i>
                                        <span style="color: var(--danger);">Reason: <?php echo htmlspecialchars($res['cancelled_reason']); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php 
                                    $delivery_status_now = $res['delivery_status'] ?? 'pending';
                                    $has_eta = !empty($res['delivery_date']);
                                    $eta_date_display = '';
                                    $eta_time_display = '';
                                    $eta_generic = false; // ✅ NEW: flag for generic ETA

                                    if ($has_eta) {
                                        $eta_date_display = date('D, M d, Y', strtotime($res['delivery_date']));
                                        
                                        if (!empty($res['delivery_time_start']) && !empty($res['delivery_time_end'])) {
                                            $eta_time_display = date('g:i A', strtotime($res['delivery_time_start'])) . ' – ' . date('g:i A', strtotime($res['delivery_time_end']));
                                        } elseif (!empty($res['delivery_time_start'])) {
                                            $eta_time_display = 'from ' . date('g:i A', strtotime($res['delivery_time_start']));
                                        }
                                    }

                                    $eta_message = 'Estimated arrival';
                                    $eta_icon = 'fa-calendar-alt';
                                    $eta_color = 'var(--primary)';
                                    $eta_bg = 'rgba(0,183,97,0.08)';

                                    if ($delivery_status_now === 'delivered') {
                                        $eta_message = 'Delivered on';
                                        $eta_icon = 'fa-check-circle';
                                        $eta_color = 'var(--success)';
                                        $eta_bg = 'rgba(0,183,97,0.1)';
                                        if (!empty($res['delivered_at'])) {
                                            $eta_date_display = date('D, M d, Y', strtotime($res['delivered_at']));
                                            $eta_time_display = date('g:i A', strtotime($res['delivered_at']));
                                        }
                                    } elseif ($delivery_status_now === 'in_transit') {
                                        $eta_message = 'Arriving today';
                                        $eta_icon = 'fa-truck';
                                        $eta_color = 'var(--info)';
                                        $eta_bg = 'rgba(59,130,246,0.08)';
                                    } elseif ($delivery_status_now === 'picked_up') {
                                        $eta_message = 'Rider on the way';
                                        $eta_icon = 'fa-box-open';
                                        $eta_color = '#6B21A8';
                                        $eta_bg = 'rgba(107,33,168,0.08)';
                                    } elseif ($delivery_status_now === 'assigned') {
                                        $eta_message = 'Rider assigned — ETA';
                                        $eta_icon = 'fa-user-check';
                                        $eta_color = '#0E7490';
                                        $eta_bg = 'rgba(14,116,144,0.08)';
                                    } elseif (!$has_eta && in_array($delivery_status_now, ['pending', 'preparing'])) {
                                        // ✅ NEW: Generic ETA kapag bagong order pa lang
                                        $eta_message = 'Estimated arrival';
                                        $eta_icon = 'fa-clock';
                                        $eta_color = 'var(--primary)';
                                        $eta_bg = 'rgba(0,183,97,0.08)';
                                        $eta_generic = true;
                                        $eta_date_display = '2-3 business days';
                                        $eta_time_display = '';
                                    } elseif (!$has_eta) {
                                        $eta_message = 'Awaiting schedule';
                                        $eta_icon = 'fa-hourglass-half';
                                        $eta_color = 'var(--text-muted)';
                                        $eta_bg = 'rgba(156,163,175,0.08)';
                                    }
                                    ?>

                                    <div class="delivery-tracking" style="background: <?php echo $eta_bg; ?>; border-left: 3px solid <?php echo $eta_color; ?>;">
                                        <i class="fas <?php echo $eta_icon; ?>" style="color: <?php echo $eta_color; ?>;"></i>
                                        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 8px; flex-wrap: wrap;">
                                            <span style="color: var(--text-secondary); font-size: 12px;"><?php echo $eta_message; ?></span>
                                            <strong style="color: <?php echo $eta_color; ?>; font-size: 12.5px; text-align: right;">
                                                <?php if ($eta_generic): ?>
                                                    <i class="fas fa-hourglass-half" style="font-size: 11px; opacity: 0.7;"></i>
                                                    <?php echo $eta_date_display; ?>
                                                <?php elseif ($has_eta || $delivery_status_now === 'delivered'): ?>
                                                    <?php echo $eta_date_display; ?>
                                                    <?php if ($eta_time_display): ?>
                                                        <span style="font-weight: 500; opacity: 0.8;">• <?php echo $eta_time_display; ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <?php if ($res['delivery_fee'] > 0): ?>
                                    <div class="delivery-fee-row">
                                        <span>Delivery Fee:</span>
                                        <strong>₱<?php echo number_format($res['delivery_fee'], 2); ?></strong>
                                    </div>
                                    <?php else: ?>
                                    <div class="delivery-fee-row">
                                        <span>Delivery Fee:</span>
                                        <strong class="free">Free</strong>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="fulfillment-badge-pickup">
                                    <i class="fas fa-store"></i> Pickup at Clinic
                                </div>
                            <?php endif; ?>

                            <!-- ═══════════════════════════════════════ -->
                            <!-- ✅ SECONDARY INFO (accordion)          -->
                            <!-- ═══════════════════════════════════════ -->
                            <?php
                                // Compute display total
                                $display_total = (float)($res['total_amount'] ?: $res['price']);
                                $display_delivery_fee = (float)($res['delivery_fee'] ?? 0);
                                $display_downpayment = (float)($res['downpayment_amount'] ?? 0);
                                $display_balance = (float)($res['balance_amount'] ?? 0);

                                if ($display_downpayment > 0 && $display_downpayment == (float)($res['total_amount'] ?: $res['price'])) {
                                    $display_downpayment = $display_total;
                                    $display_balance = 0;
                                }

                                // Compute effective refund status (for accordion toggle condition)
                                $effective_refund_status = $res['refund_status'] ?? null;
                                if (empty($effective_refund_status) || $effective_refund_status === 'none') {
                                    $effective_refund_status = $res['rr_refund_status'] ?? null;
                                }

                                $has_secondary_info = 
                                    !empty($res['color_name']) ||
                                    !empty($res['notes']) ||
                                    $display_total > 0 ||
                                    $has_warranty ||
                                    (!empty($effective_refund_status) && $effective_refund_status !== 'none') ||
                                    !empty($res['return_method'] ?? null);
                            ?>

                            <?php if ($has_secondary_info): ?>
                            <div class="card-details-toggle" onclick="toggleCardDetails(this)">
                                <span><i class="fas fa-info-circle"></i> View more details</span>
                                <i class="fas fa-chevron-down chevron"></i>
                            </div>
                            <div class="card-details-body">
                            <?php endif; ?>

                            <!-- ═══════════════════════════════════════════════════ -->
                            <!-- ✅ PROOF OF DELIVERY CARD                            -->
                            <!-- ═══════════════════════════════════════════════════ -->
                            <?php 
                            $has_delivery_proof = !empty($res['delivery_proof_image']);
                            $proof_url = null;
                            if ($has_delivery_proof) {
                                $proof_path = $res['delivery_proof_image'];
                                $proof_path = str_replace('uploads/uploads/', 'uploads/', $proof_path);
                                if (strpos($proof_path, 'uploads/') === 0) {
                                    $proof_url = '/' . $proof_path;
                                } elseif (strpos($proof_path, '/') === 0) {
                                    $proof_url = $proof_path;
                                } else {
                                    $proof_url = '/uploads/' . $proof_path;
                                }
                            }
                            $proof_notes = $res['delivery_proof_notes'] ?? '';
                            $proof_at = $res['delivery_proof_at'] ?? $res['delivered_at'];
                            ?>

                            <?php if ($has_delivery_proof): ?>
                            <div style="
                                background: linear-gradient(135deg, rgba(0,183,97,0.08), rgba(0,183,97,0.02));
                                border: 1px solid rgba(0,183,97,0.25);
                                border-radius: var(--radius-md);
                                padding: 12px 14px;
                                margin-bottom: 10px;
                            ">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                    <i class="fas fa-camera" style="color: var(--primary); font-size: 14px;"></i>
                                    <strong style="font-size: 13px; color: var(--text-primary);">Proof of Delivery</strong>
                                    <span style="
                                        margin-left: auto;
                                        background: #D1FAE5;
                                        color: #065F46;
                                        font-size: 10px;
                                        font-weight: 700;
                                        padding: 3px 8px;
                                        border-radius: 999px;
                                        letter-spacing: 0.5px;
                                        display: inline-flex;
                                        align-items: center;
                                        gap: 4px;
                                    ">
                                        <i class="fas fa-check-circle"></i> VERIFIED
                                    </span>
                                </div>

                                <div style="display: flex; gap: 12px; align-items: flex-start;">
                                    <div 
                                        onclick="event.stopPropagation(); showImageModal('<?php echo htmlspecialchars($proof_url); ?>', 'Proof of Delivery')"
                                        style="
                                            width: 90px;
                                            height: 90px;
                                            border-radius: var(--radius-md);
                                            overflow: hidden;
                                            cursor: pointer;
                                            flex-shrink: 0;
                                            border: 2px solid white;
                                            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                            position: relative;
                                            transition: transform 0.2s;
                                        "
                                        onmouseover="this.style.transform='scale(1.05)'"
                                        onmouseout="this.style.transform='scale(1)'"
                                    >
                                        <img 
                                            src="<?php echo htmlspecialchars($proof_url); ?>" 
                                            alt="Proof of Delivery"
                                            style="width: 100%; height: 100%; object-fit: cover;"
                                            onerror="this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--bg-primary);\'><i class=\'fas fa-image\' style=\'color:var(--text-muted);font-size:22px;\'></i></div>'"
                                        >
                                    </div>

                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 12px; color: var(--text-secondary); line-height: 1.6;">
                                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                                <i class="fas fa-user-check" style="color: var(--primary); width: 14px;"></i>
                                                <span>Received by: <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($res['delivery_name'] ?? 'Customer'); ?></strong></span>
                                            </div>
                                            <?php if (!empty($proof_at)): ?>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fas fa-clock" style="color: var(--primary); width: 14px;"></i>
                                                <span><?php echo date('M d, Y • g:i A', strtotime($proof_at)); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($res['rider_name'])): ?>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fas fa-motorcycle" style="color: var(--primary); width: 14px;"></i>
                                                <span>Delivered by: <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($res['rider_name']); ?></strong></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($res['tracking_number'])): ?>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fas fa-barcode" style="color: var(--primary); width: 14px;"></i>
                                                <span style="font-family: monospace; font-size: 11.5px;"><?php echo htmlspecialchars($res['tracking_number']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($proof_notes)): ?>
                                            <div style="display: flex; align-items: flex-start; gap: 6px; margin-top: 4px;">
                                                <i class="fas fa-comment" style="color: var(--primary); width: 14px; margin-top: 3px;"></i>
                                                <span style="font-style: italic;">"<?php echo htmlspecialchars($proof_notes); ?>"</span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <button 
                                    type="button"
                                    onclick="event.stopPropagation(); showImageModal('<?php echo htmlspecialchars($proof_url); ?>', 'Proof of Delivery')"
                                    style="
                                        width: 100%;
                                        margin-top: 10px;
                                        padding: 8px;
                                        background: var(--primary-light);
                                        color: var(--primary);
                                        border: 1px solid var(--primary);
                                        border-radius: var(--radius-md);
                                        font-size: 12px;
                                        font-weight: 700;
                                        cursor: pointer;
                                        transition: all 0.2s;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        gap: 6px;
                                        font-family: inherit;
                                    "
                                    onmouseover="this.style.background='var(--primary)'; this.style.color='white';"
                                    onmouseout="this.style.background='var(--primary-light)'; this.style.color='var(--primary)';"
                                >
                                    <i class="fas fa-expand"></i> View Full Photo
                                </button>
                            </div>
                            <?php endif; ?>
                            <!-- ═══════════════════════════════════════════════════ -->
                            <!-- END PROOF OF DELIVERY CARD                          -->
                            <!-- ═══════════════════════════════════════════════════ -->

                            <div class="reservation-details">
                                <div class="detail-row">
                                    <span class="detail-label">Order #:</span>
                                    <span class="detail-value">ORD-<?php echo str_pad($res['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                </div>
                                <?php if (!empty($res['color_name'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Color:</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($res['color_name']); ?>
                                        <?php if (!empty($res['color_code'])): ?>
                                            <span class="color-preview" style="background-color: <?php echo htmlspecialchars($res['color_code']); ?>;"></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <div class="detail-row">
                                    <span class="detail-label">Order Date:</span>
                                    <span class="detail-value"><?php 
                                        $res_date = !empty($res['created_at']) ? $res['created_at'] : $res['preferred_date'];
                                        echo date('M d, Y', strtotime($res_date)); 
                                    ?></span>
                                </div>
                                <?php if (($res['fulfillment_type'] ?? 'pickup') === 'delivery' && !empty($res['delivery_date'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Delivery Date:</span>
                                    <span class="detail-value" style="color: var(--primary);">
                                        <i class="fas fa-truck" style="font-size: 11px;"></i>
                                        <?php echo date('M d, Y', strtotime($res['delivery_date'])); ?>
                                    </span>
                                </div>
                                <?php elseif (($res['fulfillment_type'] ?? 'pickup') === 'pickup' && !empty($res['preferred_date'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Pickup Date:</span>
                                    <span class="detail-value" style="color: var(--primary);">
                                        <i class="fas fa-store" style="font-size: 11px;"></i>
                                        <?php echo date('M d, Y', strtotime($res['preferred_date'])); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($res['notes'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Notes:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars(substr($res['notes'], 0, 50)) . (strlen($res['notes']) > 50 ? '...' : ''); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($display_total > 0): ?>
                            <div class="payment-info">
                                <div class="payment-row">
                                    <span class="payment-label">Total Amount:</span>
                                    <span class="payment-amount">₱<?php echo number_format($display_total, 2); ?></span>
                                </div>
                                
                                <?php if ($display_downpayment > 0): ?>
                                <div class="payment-row">
                                    <span class="payment-label">
                                        <?php 
                                        if ($downpayment_percent >= 100 || $display_balance <= 0) {
                                            echo 'Amount Paid:';
                                        } else {
                                            echo 'Downpayment (' . $downpayment_percent . '%):';
                                        }
                                        ?>
                                    </span>
                                    <span class="payment-amount">₱<?php echo number_format($display_downpayment, 2); ?></span>
                                </div>
                                <div class="payment-row">
                                    <span class="payment-label">Balance:</span>
                                    <span class="payment-balance">₱<?php echo number_format($display_balance, 2); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php 
                                // ✅ Payment Method Badge
                                $pm_status = $res['payment_status'] ?? 'unpaid';
                                $pm_class = '';
                                $pm_icon = 'fa-circle';
                                $pm_text = '';
                                
                                switch ($pm_status) {
                                    case 'cod':
                                        $pm_class = 'cod';
                                        $pm_icon = 'fa-money-bill-wave';
                                        $pm_text = '<strong>Cash on Delivery</strong> — Pay <strong>₱' . number_format($display_total, 2) . '</strong> upon delivery';
                                        break;
                                    
                                    case 'paid':
                                        $pm_class = 'paid';
                                        $pm_icon = 'fa-check-circle';
                                        $pm_text = '<strong>Paid</strong> - Thank you for your payment';
                                        break;
                                    
                                    case 'partial':
                                        $pm_class = 'partial';
                                        $pm_icon = 'fa-hand-holding-usd';
                                        $pm_text = '<strong>Downpayment paid</strong> — <strong>₱' . number_format($display_balance, 2) . '</strong> remaining';
                                        break;
                                    
                                    case 'onsite':
                                        $pm_class = 'onsite';
                                        $pm_icon = 'fa-store';
                                        $pm_text = '<strong>Pay at Clinic</strong> — ₱' . number_format($display_total, 2);
                                        break;
                                    
                                    case 'free':
                                        $pm_class = 'free';
                                        $pm_icon = 'fa-gift';
                                        $pm_text = '<strong>Free</strong> — No payment required';
                                        break;
                                    
                                    case 'refunded':
                                        $pm_class = 'refunded';
                                        $pm_icon = 'fa-undo';
                                        $pm_text = '<strong>Refunded</strong>';
                                        break;
                                    
                                    case 'unpaid':
                                    default:
                                        $pm_class = 'unpaid';
                                        $pm_icon = 'fa-exclamation-circle';
                                        $pm_text = '<strong>Unpaid</strong> — Please complete payment';
                                        break;
                                }
                                ?>
                                <div class="payment-method-row <?php echo $pm_class; ?>">
                                    <i class="fas <?php echo $pm_icon; ?>"></i>
                                    <span class="pm-text">
                                        <i class="fas fa-wallet" style="font-size: 11px; opacity: 0.7;"></i>
                                        <?php echo $pm_text; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php 
                            $hide_warranty = in_array($res['status'], ['cancelled', 'refunded']) 
                                          || (isset($res['payment_status']) && $res['payment_status'] === 'refunded')
                                          || (isset($res['refund_status']) && in_array($res['refund_status'], ['pending', 'approved', 'processing', 'completed']))
                                          || (isset($res['rr_refund_status']) && in_array($res['rr_refund_status'], ['pending', 'approved', 'processing', 'completed']))
                                          || (isset($res['rr_status']) && in_array($res['rr_status'], ['pending', 'approved', 'processing', 'completed']));

                            if ($has_warranty && !$hide_warranty): 
                            ?>
                            <div class="warranty-section">
                                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 8px; font-size: 12px;">
                                    <i class="fas fa-shield-alt" style="color: var(--primary);"></i>
                                    <strong>Warranty</strong>
                                    <?php if ($warranty_display): ?>
                                    <span class="warranty-badge"><?php echo $warranty_display; ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($is_within_warranty && $warranty_end_date): ?>
                                <div class="warranty-status active">
                                    <i class="fas fa-check-circle"></i> Active until <?php echo date('M d, Y', strtotime($warranty_end_date)); ?>
                                </div>
                                <?php elseif ($has_warranty && !$is_within_warranty && $warranty_end_date): ?>
                                <div class="warranty-status expired">
                                    <i class="fas fa-clock"></i> Expired on <?php echo date('M d, Y', strtotime($warranty_end_date)); ?>
                                </div>
                                <?php endif; ?>
                                
                            <?php if (!empty($existing_claim)): ?>
                                <?php
                                    $claim_status_map = [
                                        'pending'    => ['label' => 'Warranty Claim Pending Review',  'icon' => 'fa-hourglass-half', 'class' => 'warranty-status active'],
                                        'processing' => ['label' => 'Warranty Claim Being Processed', 'icon' => 'fa-cog fa-spin',    'class' => 'warranty-status active'],
                                        'approved'   => ['label' => 'Warranty Claim Approved — Visit clinic on scheduled date', 'icon' => 'fa-check-circle', 'class' => 'warranty-status active'],
                                        'arrived'    => ['label' => 'You have arrived — Claim being processed', 'icon' => 'fa-door-open', 'class' => 'warranty-status active'],
                                        'completed'  => ['label' => 'Warranty Claim Already Completed', 'icon' => 'fa-check-double', 'class' => 'warranty-status active'],
                                    ];
                                    $claim_info = $claim_status_map[$existing_claim['status']] ?? ['label' => 'Warranty Claim in Progress', 'icon' => 'fa-info-circle', 'class' => 'warranty-status active'];
                                ?>
                                <div class="<?php echo $claim_info['class']; ?> mt-2" style="justify-content: center; text-align: center;">
                                    <i class="fas <?php echo $claim_info['icon']; ?>"></i>
                                    <?php echo $claim_info['label']; ?>
                                </div>
                            <?php elseif ($can_claim_warranty): ?>
                                <button class="btn-warranty-claim-small mt-2" onclick="openWarrantyClaimRes(<?php echo (int)$res['id']; ?>, <?php echo (int)$res['product_id']; ?>, '<?php echo addslashes($res['product_name']); ?>')">
                                    <i class="fas fa-tools"></i> Claim Warranty
                                </button>
                            <?php elseif ($has_warranty && in_array($res['status'], ['delivered', 'completed']) && !$is_within_warranty && $warranty_end_date): ?>
                                <button class="btn-warranty-expired-small mt-2" disabled>
                                    <i class="fas fa-clock"></i> Warranty Expired
                                </button>
                            <?php elseif ($has_warranty && !in_array($res['status'], ['delivered', 'completed'])): ?>
                                <div class="warranty-note mt-1">
                                    <small><i class="fas fa-info-circle"></i> Warranty available after delivery</small>
                                </div>
                            <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- ═══════════════════════════════════════════════════════ -->
                            <!-- ✅ Refund & Return Status Section (UNCHANGED)         -->
                            <!-- ═══════════════════════════════════════════════════════ -->
                            <?php 
                            $effective_refund_status2 = $res['refund_status'] ?? null;
                            if (empty($effective_refund_status2) || $effective_refund_status2 === 'none') {
                                $effective_refund_status2 = $res['rr_refund_status'] ?? null;
                            }

                            if (!empty($effective_refund_status2) && $effective_refund_status2 !== 'none'): 
                            ?>
                            <?php
                                $refund_status_map = [
                                    'pending'    => ['label' => 'Refund Pending',    'class' => 'refund-pending',    'icon' => 'fa-clock'],
                                    'approved'   => ['label' => 'Refund Approved',   'class' => 'refund-approved',   'icon' => 'fa-check-circle'],
                                    'processing' => ['label' => 'Refund Processing', 'class' => 'refund-processing', 'icon' => 'fa-spinner'],
                                    'completed'  => ['label' => 'Refunded',          'class' => 'refund-completed',  'icon' => 'fa-check-double'],
                                    'rejected'   => ['label' => 'Refund Rejected',   'class' => 'refund-rejected',   'icon' => 'fa-times-circle'],
                                    'failed'     => ['label' => 'Refund Failed',     'class' => 'refund-failed',     'icon' => 'fa-exclamation-circle'],
                                ];
                                $rs = $refund_status_map[$effective_refund_status2] ?? ['label' => ucfirst($effective_refund_status2), 'class' => 'refund-pending', 'icon' => 'fa-circle'];
                                
                                $display_refund_amount = 0;
                                if (!empty($res['refund_amount']) && (float)$res['refund_amount'] > 0) {
                                    $display_refund_amount = (float)$res['refund_amount'];
                                } elseif (!empty($res['refund_amount_actual']) && (float)$res['refund_amount_actual'] > 0) {
                                    $display_refund_amount = (float)$res['refund_amount_actual'];
                                } elseif (!empty($res['eligible_refund_amount']) && (float)$res['eligible_refund_amount'] > 0) {
                                    $display_refund_amount = (float)$res['eligible_refund_amount'];
                                } else {
                                    $display_refund_amount = (float)$res['total_amount'];
                                }
                                
                                $display_refund_percent = 0;
                                if ((float)$res['total_amount'] > 0) {
                                    $display_refund_percent = round(($display_refund_amount / (float)$res['total_amount']) * 100);
                                }
                            ?>
                            <div class="refund-section">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                                    <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700;">
                                        <i class="fas fa-undo" style="color: var(--danger);"></i>
                                        <span>Return / Refund</span>
                                    </div>
                                    <span class="refund-badge <?php echo $rs['class']; ?>">
                                        <i class="fas <?php echo $rs['icon']; ?>"></i>
                                        <?php echo $rs['label']; ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($res['cancelled_reason'])): ?>
                                <div class="refund-reason">
                                    <strong>Reason:</strong> <?php echo htmlspecialchars($res['cancelled_reason']); ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($effective_refund_status2 === 'completed'): ?>
                                <div class="refund-completed-card">
                                    <div class="refund-completed-header">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Refund Completed</span>
                                    </div>
                                    <div class="refund-completed-details">
                                        <div class="refund-completed-row">
                                            <span class="lbl">Amount Refunded:</span>
                                            <span class="val">₱<?php echo number_format($display_refund_amount, 2); ?> (<?php echo $display_refund_percent; ?>%)</span>
                                        </div>
                                        <?php if (!empty($res['paymongo_refund_id'])): ?>
                                        <div class="refund-completed-row">
                                            <span class="lbl">Refund ID:</span>
                                            <span class="val" style="font-family:monospace; font-size:11px; word-break:break-all;"><?php echo htmlspecialchars($res['paymongo_refund_id']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($res['refund_date'])): ?>
                                        <div class="refund-completed-row">
                                            <span class="lbl">Refunded on:</span>
                                            <span class="val"><?php echo date('F j, Y g:i A', strtotime($res['refund_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="refund-completed-note">
                                            <i class="fas fa-info-circle"></i> Amount will reflect in your account within 3-5 business days.
                                        </div>
                                    </div>
                                </div>
                                
                                <?php elseif (in_array($effective_refund_status2, ['pending', 'approved', 'processing'])): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 12px; color: var(--text-muted);">Refund Amount:</span>
                                    <span class="refund-amount">₱<?php echo number_format($display_refund_amount, 2); ?></span>
                                </div>
                                
                                <?php elseif (in_array($effective_refund_status2, ['rejected', 'failed'])): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 12px; color: var(--text-muted);">Requested Amount:</span>
                                    <span class="refund-amount" style="text-decoration:line-through; opacity:0.6;">₱<?php echo number_format($display_refund_amount, 2); ?></span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($res['return_method'])): ?>
                                <?php 
                                    $rm_info = getReturnMethodInfo($res['return_method']);
                                    
                                    $effective_return_status = $res['return_status'] ?? 'pending';
                                    if ($effective_refund_status2 === 'completed') {
                                        $effective_return_status = 'completed';
                                    } elseif ($effective_refund_status2 === 'rejected') {
                                        $effective_return_status = 'rejected';
                                    } elseif (in_array($effective_refund_status2, ['approved', 'processing'])) {
                                        if ($effective_return_status === 'pending') {
                                            $effective_return_status = 'scheduled';
                                        }
                                    }
                                    
                                    $ret_status_info = getReturnStatusInfo($effective_return_status);
                                ?>
                                <div class="return-method-section" style="margin-top: 4px;">
                                    <div class="return-method-header">
                                        <i class="fas <?php echo $rm_info['icon']; ?>"></i>
                                        <span><?php echo $rm_info['label']; ?></span>
                                    </div>
                                    <div class="return-method-details">
                                        <?php if (!empty($res['return_scheduled_date'])): ?>
                                        <div class="row">
                                            <span class="lbl">Schedule:</span>
                                            <span class="val">
                                                <?php echo date('M d, Y', strtotime($res['return_scheduled_date'])); ?>
                                                <?php if (!empty($res['return_scheduled_time'])): ?>
                                                    at <?php echo date('g:i A', strtotime($res['return_scheduled_time'])); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($res['return_method'] === 'pickup' && !empty($res['return_address'])): ?>
                                        <div class="row">
                                            <span class="lbl">Pickup Address:</span>
                                            <span class="val" style="font-weight: 400; font-size: 11px;"><?php echo htmlspecialchars($res['return_address']); ?></span>
                                        </div>
                                        <?php endif; ?>

                                        <div class="row">
                                            <span class="lbl">Status:</span>
                                            <span class="val">
                                                <span class="refund-badge <?php echo $ret_status_info['class']; ?>" style="font-size: 10px; padding: 2px 8px;">
                                                    <i class="fas <?php echo $ret_status_info['icon']; ?>"></i>
                                                    <?php echo $ret_status_info['label']; ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($has_secondary_info): ?>
                            </div><!-- /.card-details-body -->
                            <?php endif; ?>

                            <!-- ═══════════════════════════════════════════════════════ -->
                            <!-- ✅ PRIMARY CTA + MORE MENU                             -->
                            <!-- ═══════════════════════════════════════════════════════ -->
                            <div class="card-actions">
                                <?php 
                                // ✅ Primary CTA
                                $primary_cta = null;
                                
                                if ($res['status'] === 'pending') {
                                    if ($res['payment_status'] == 'unpaid') {
                                        $primary_cta = ['type' => 'link', 'href' => 'payment.php?reservation_id=' . $res['id'], 'class' => 'btn-warning', 'icon' => 'fa-credit-card', 'label' => 'Pay Now'];
                                    } else {
                                        $primary_cta = ['type' => 'link', 'href' => '3d-view.php?id=' . $res['product_id'], 'class' => 'btn-primary', 'icon' => 'fa-cube', 'label' => 'View 3D'];
                                    }
                                } elseif ($res['status'] === 'confirmed') {
                                    $primary_cta = ['type' => 'link', 'href' => 'book-appointment.php?clinic_id=' . $res['clinic_id'] . '&item_id=' . $res['product_id'] . '&type=product', 'class' => 'btn-primary', 'icon' => 'fa-calendar-plus', 'label' => 'Book Now'];
                                } elseif ($res['status'] === 'ready_for_pickup') {
                                    $primary_cta = ['type' => 'link', 'href' => 'clinic-details.php?id=' . $res['clinic_id'], 'class' => 'btn-primary', 'icon' => 'fa-map-marker-alt', 'label' => 'Clinic Details'];
                                } elseif ($res['status'] === 'delivered') {
                                    $has_refund_now = false;
                                    if (!empty($res['refund_status']) && $res['refund_status'] !== 'none') $has_refund_now = true;
                                    if (!empty($res['rr_status']) && in_array($res['rr_status'], ['pending','approved','processing','completed'])) $has_refund_now = true;
                                    if (!empty($res['rr_refund_status']) && $res['rr_refund_status'] !== 'none') $has_refund_now = true;
                                    
                                    if ($has_refund_now) {
                                        $primary_cta = ['type' => 'link', 'href' => 'product-view.php?id=' . $res['product_id'], 'class' => 'btn-outline', 'icon' => 'fa-eye', 'label' => 'View Product'];
                                    } else {
                                        $primary_cta = ['type' => 'button', 'onclick' => 'markReceived(' . $res['id'] . ')', 'class' => 'btn-primary', 'icon' => 'fa-check-circle', 'label' => 'Mark as Received'];
                                    }
                                } elseif ($res['status'] === 'completed') {
                                    $primary_cta = ['type' => 'link', 'href' => 'product-view.php?id=' . $res['product_id'], 'class' => 'btn-primary', 'icon' => 'fa-eye', 'label' => 'View Product'];
                                } else {
                                    $primary_cta = ['type' => 'link', 'href' => 'product-view.php?id=' . $res['product_id'], 'class' => 'btn-outline', 'icon' => 'fa-eye', 'label' => 'View Product'];
                                }
                                ?>
                                
                                <?php if ($primary_cta['type'] === 'link'): ?>
                                    <a href="<?php echo $primary_cta['href']; ?>" class="btn <?php echo $primary_cta['class']; ?>">
                                        <i class="fas <?php echo $primary_cta['icon']; ?>"></i> <?php echo $primary_cta['label']; ?>
                                    </a>
                                <?php else: ?>
                                    <button class="btn <?php echo $primary_cta['class']; ?>" onclick="<?php echo $primary_cta['onclick']; ?>">
                                        <i class="fas <?php echo $primary_cta['icon']; ?>"></i> <?php echo $primary_cta['label']; ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php 
                                // ✅ Secondary actions list
                                $secondary_actions = [];
                                
                                if (in_array($res['status'], ['pending', 'confirmed'])) {
                                    $secondary_actions[] = [
                                        'type' => 'button',
                                        'onclick' => "cancelReservation({$res['id']}, '" . addslashes($res['product_name']) . "', '" . addslashes($res['color_name'] ?? '') . "')",
                                        'icon' => 'fa-times',
                                        'label' => 'Cancel Order',
                                        'class' => 'danger'
                                    ];
                                }
                                
                                if ($res['status'] !== 'pending' && !empty($res['product_id'])) {
                                    $secondary_actions[] = [
                                        'type' => 'link',
                                        'href' => '3d-view.php?id=' . $res['product_id'],
                                        'icon' => 'fa-cube',
                                        'label' => 'View 3D Model',
                                        'class' => ''
                                    ];
                                }
                                
                                if ($res['status'] === 'delivered') {
                                    $within_window_more = false;
                                    if (!empty($res['delivered_at'])) {
                                        $within_window_more = time() < (strtotime($res['delivered_at']) + (7 * 24 * 60 * 60));
                                    }
                                    $has_refund_more = false;
                                    if (!empty($res['refund_status']) && $res['refund_status'] !== 'none') $has_refund_more = true;
                                    if (!empty($res['rr_status']) && in_array($res['rr_status'], ['pending','approved','processing','completed'])) $has_refund_more = true;
                                    if (!empty($res['rr_refund_status']) && $res['rr_refund_status'] !== 'none') $has_refund_more = true;
                                    
                                    if ($within_window_more && !$has_refund_more) {
                                        $secondary_actions[] = [
                                            'type' => 'button',
                                            'onclick' => "openRefundModal({$res['id']}, '" . addslashes($res['product_name']) . "', '" . addslashes($res['reservation_code'] ?? 'ORD-'.str_pad($res['id'], 6, '0', STR_PAD_LEFT)) . "', " . (float)$res['total_amount'] . ")",
                                            'icon' => 'fa-undo',
                                            'label' => 'Return / Refund',
                                            'class' => 'danger'
                                        ];
                                    }
                                }
                                
                                if ($res['status'] === 'completed') {
                                    $secondary_actions[] = [
                                        'type' => 'link',
                                        'href' => 'clinic-details.php?id=' . $res['clinic_id'],
                                        'icon' => 'fa-clinic-medical',
                                        'label' => 'Visit Clinic',
                                        'class' => ''
                                    ];
                                }
                                
                                if ($res['status'] !== 'completed') {
                                    $secondary_actions[] = [
                                        'type' => 'link',
                                        'href' => 'product-view.php?id=' . $res['product_id'],
                                        'icon' => 'fa-eye',
                                        'label' => 'View Product',
                                        'class' => ''
                                    ];
                                }
                                ?>
                                
                                <?php if (!empty($secondary_actions)): ?>
                                <div class="more-menu-wrap">
                                    <button type="button" class="more-menu-btn" onclick="toggleMoreMenu(this, event)">
                                        <i class="fas fa-ellipsis-h"></i> More
                                    </button>
                                    <div class="more-menu-list">
                                        <?php foreach ($secondary_actions as $act): ?>
                                            <?php if ($act['type'] === 'link'): ?>
                                                <a href="<?php echo $act['href']; ?>" class="more-menu-item <?php echo $act['class']; ?>">
                                                    <i class="fas <?php echo $act['icon']; ?>"></i>
                                                    <?php echo $act['label']; ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="more-menu-item <?php echo $act['class']; ?>" onclick="<?php echo $act['onclick']; ?>">
                                                    <i class="fas <?php echo $act['icon']; ?>"></i>
                                                    <?php echo $act['label']; ?>
                                                </button>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state-reservations">
                <div class="empty-icon-wrap">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <h2>No Orders Yet</h2>
                <p>Browse products and order your favorite items! Your orders will appear here.</p>
                <div class="empty-actions">
                    <a href="dashboard.php" class="empty-btn-primary">
                        <i class="fas fa-shopping-bag"></i> Browse Products
                    </a>
                    <a href="sale-products.php" class="empty-btn-secondary">
                        <i class="fas fa-tags"></i> View Sales
                    </a>
                </div>
                <div class="empty-tip">
                    <i class="fas fa-lightbulb"></i>
                    Tip: Save your favorite products to quickly order them later!
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal" onclick="closeImageModal()">
        <span class="modal-close" onclick="closeImageModal()">&times;</span>
        <img class="modal-image" id="modalImage" src="" alt="Product Image">
    </div>

    <!-- Warranty Claim Modal -->
    <div class="modal fade" id="warrantyClaimModalRes" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #00B761, #00A86B); color: white;">
                    <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Request Warranty Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="warrantyProductInfoRes" class="alert alert-light border mb-3"></div>
                    
                    <div class="alert alert-warning small">
                        <i class="fas fa-info-circle"></i>
                        <strong>Walk-in Service Only</strong><br>
                        Please bring your product to the clinic for inspection.
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Preferred Date</label>
                            <input type="date" id="claim_date_res" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred Time</label>
                            <input type="time" id="claim_time_res" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Issue Description</label>
                            <textarea id="claim_description_res" class="form-control" rows="2" placeholder="Describe the issue..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Upload Reference Photos (Optional)</label>
                            <input type="file" id="claim_photos_res" class="form-control" multiple accept="image/*">
                            <small class="text-muted">Upload up to 3 photos</small>
                        </div>
                    </div>
                    
                    <input type="hidden" id="claim_reservation_id_res">
                    <input type="hidden" id="claim_product_id_res">
                    <input type="hidden" id="claim_product_name_res">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" style="background: #00B761; color: white;" onclick="submitWarrantyClaimRes()">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Refund Request Modal with Return Method -->
    <div class="modal fade" id="refundModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: var(--radius-lg);">
                <div class="modal-header" style="background: linear-gradient(135deg, #EF4444, #DC2626); color: white; border-radius: var(--radius-lg) var(--radius-lg) 0 0;">
                    <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Return / Refund Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding: 20px; max-height: 75vh; overflow-y: auto;">
                    <div style="background: var(--bg-primary); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px;">
                        <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Order</div>
                        <div style="font-weight: 700; color: var(--text-primary); font-size: 14px;" id="refundOrderCode"></div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;" id="refundProductName"></div>
                        <div style="font-size: 14px; font-weight: 700; color: var(--primary); margin-top: 8px;" id="refundOrderTotal"></div>
                    </div>

                    <label class="form-label" style="font-weight: 600; font-size: 13px;">Reason for Return <span style="color: var(--danger);">*</span></label>
                    <div class="refund-modal-reasons" id="refundReasons">
                        <div class="refund-reason-option" onclick="selectRefundReason(this, 'damaged')">
                            <input type="radio" name="refund_reason" value="damaged">
                            <i class="fas fa-box-open"></i>
                            <label>Product is damaged / defective</label>
                        </div>
                        <div class="refund-reason-option" onclick="selectRefundReason(this, 'wrong_item')">
                            <input type="radio" name="refund_reason" value="wrong_item">
                            <i class="fas fa-exchange-alt"></i>
                            <label>Wrong item received</label>
                        </div>
                        <div class="refund-reason-option" onclick="selectRefundReason(this, 'other')">
                            <input type="radio" name="refund_reason" value="other">
                            <i class="fas fa-ellipsis-h"></i>
                            <label>Other reason</label>
                        </div>
                    </div>

                    <label class="form-label" style="font-weight: 600; font-size: 13px;">Details <span style="color: var(--text-muted);">(optional)</span></label>
                    <textarea id="refundDetails" class="form-control" rows="2" placeholder="Describe the issue..." style="border-radius: var(--radius-md); font-size: 13px;"></textarea>

                    <label class="form-label mt-3" style="font-weight: 600; font-size: 13px;">Evidence Photo <span style="color: var(--text-muted);">(optional)</span></label>
                    <input type="file" id="refundEvidence" class="form-control" accept="image/*" style="border-radius: var(--radius-md); font-size: 13px;">
                    <small style="color: var(--text-muted); font-size: 11px;">JPG, PNG, WebP. Max 5MB.</small>

                    <label class="form-label mt-3" style="font-weight: 600; font-size: 13px;">Return Method <span style="color: var(--danger);">*</span></label>
                    <div class="return-method-toggle" id="returnMethodToggle">
                        <div class="return-method-option selected" onclick="selectReturnMethod(this, 'dropoff')">
                            <input type="radio" name="return_method" value="dropoff" checked>
                            <div class="rm-content">
                                <div class="rm-label">
                                    <i class="fas fa-store"></i>
                                    Drop-off at Clinic
                                </div>
                                <div class="rm-desc">You will bring the item to the clinic yourself</div>
                            </div>
                        </div>
                        <div class="return-method-option" onclick="selectReturnMethod(this, 'pickup')">
                            <input type="radio" name="return_method" value="pickup">
                            <div class="rm-content">
                                <div class="rm-label">
                                    <i class="fas fa-truck"></i>
                                    Clinic Pickup
                                </div>
                                <div class="rm-desc">Clinic will pick up the item from your address</div>
                            </div>
                        </div>
                        <div class="return-method-option" onclick="selectReturnMethod(this, 'courier')">
                            <input type="radio" name="return_method" value="courier">
                            <div class="rm-content">
                                <div class="rm-label">
                                    <i class="fas fa-shipping-fast"></i>
                                    Courier / Ship
                                </div>
                                <div class="rm-desc">You will ship the item via Lalamove, Grab, etc.</div>
                            </div>
                        </div>
                    </div>

                    <div class="conditional-fields" id="returnDetailsFields">
                        <div id="pickupAddressWrap" style="display: none;">
                            <label class="form-label" style="font-weight: 600; font-size: 12px;">
                                <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i>
                                Pickup Address <span style="color: var(--danger);">*</span>
                            </label>
                            <textarea id="returnAddress" class="form-control" rows="2" 
                                      placeholder="Enter complete address (street, barangay, city, province, zip)"
                                      style="border-radius: var(--radius-md); font-size: 13px;"></textarea>
                        </div>

                        <div id="courierInfoWrap" style="display: none;">
                            <div style="background: #DBEAFE; border-radius: var(--radius-md); padding: 10px 12px; font-size: 12px; color: #1E40AF;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> You will shoulder the courier fee. Please send the item to the clinic address and update the tracking number in your order once shipped.
                            </div>
                        </div>

                        <div id="scheduleWrap">
                            <label class="form-label mt-3" style="font-weight: 600; font-size: 12px;">
                                <i class="fas fa-calendar-alt" style="color: var(--primary);"></i>
                                Preferred Schedule <span style="color: var(--text-muted);">(optional)</span>
                            </label>
                            <div class="row g-2">
                                <div class="col-7">
                                    <input type="date" id="returnScheduledDate" class="form-control" 
                                           min="<?php echo date('Y-m-d'); ?>"
                                           style="border-radius: var(--radius-md); font-size: 13px;">
                                </div>
                                <div class="col-5">
                                    <input type="time" id="returnScheduledTime" class="form-control" 
                                           style="border-radius: var(--radius-md); font-size: 13px;">
                                </div>
                            </div>
                        </div>

                        <label class="form-label mt-3" style="font-weight: 600; font-size: 12px;">
                            <i class="fas fa-user" style="color: var(--primary);"></i>
                            Contact Person
                        </label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="text" id="returnContactName" class="form-control" 
                                       placeholder="Full name"
                                       style="border-radius: var(--radius-md); font-size: 13px;">
                            </div>
                            <div class="col-6">
                                <input type="text" id="returnContactPhone" class="form-control" 
                                       placeholder="Phone number"
                                       style="border-radius: var(--radius-md); font-size: 13px;">
                            </div>
                        </div>

                        <label class="form-label mt-3" style="font-weight: 600; font-size: 12px;">
                            <i class="fas fa-comment" style="color: var(--primary);"></i>
                            Additional Notes <span style="color: var(--text-muted);">(optional)</span>
                        </label>
                        <textarea id="returnNotes" class="form-control" rows="2" 
                                  placeholder="Any additional info for the clinic..."
                                  style="border-radius: var(--radius-md); font-size: 13px;"></textarea>
                    </div>

                    <label class="form-label mt-3" style="font-weight: 600; font-size: 13px;">Refund Type</label>
                    <div class="refund-type-toggle">
                        <div class="refund-type-option selected" onclick="selectRefundType(this, 'full')">
                            <input type="radio" name="refund_type" value="full" checked>
                            <label>Full Refund</label>
                            <small id="refundFullAmount">₱0.00</small>
                        </div>
                        <div class="refund-type-option" onclick="selectRefundType(this, 'partial')">
                            <input type="radio" name="refund_type" value="partial">
                            <label>Partial Refund</label>
                            <small>Enter amount below</small>
                        </div>
                    </div>

                    <div id="partialAmountWrap" style="display: none;">
                        <label class="form-label" style="font-weight: 600; font-size: 13px;">Partial Amount (₱)</label>
                        <input type="number" id="refundPartialAmount" class="form-control" step="0.01" min="0" placeholder="0.00" style="border-radius: var(--radius-md);">
                    </div>

                    <div style="background: #FEF3C7; border: 1px solid #F59E0B; border-radius: var(--radius-md); padding: 10px 12px; margin-top: 16px; font-size: 12px; color: #92400E;">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Note:</strong> Your request will be reviewed by the clinic. You will be notified once approved or rejected.
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-light);">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitRefund()">
                        <i class="fas fa-paper-plane me-1"></i>Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
function cancelReservation(reservationId, productName, colorName) {
    if (!reservationId || reservationId <= 0) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Invalid order ID. Please refresh the page and try again.',
            background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });
        return;
    }
    
    Swal.fire({
        title: 'Cancel Order?',
        html: `<div style="text-align: left;">
                <p>Are you sure you want to cancel your order for:</p>
                <p style="font-weight: 600; color: var(--primary); margin: 10px 0;">${escapeHtml(productName)}</p>
                ${colorName ? `<p style="font-weight: 500;">Color: ${escapeHtml(colorName)}</p>` : ''}
                <div style="background: #FEF3C7; border-radius: 10px; padding: 10px; margin-top: 12px; font-size: 12px; color: #92400E;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Refund Policy:</strong><br>
                    • Within 15 minutes ng payment → <strong>Auto-refund</strong><br>
                    • After 15 minutes → Refund request (admin review)
                </div>
                <p class="text-muted" style="margin-top: 15px; color: var(--text-muted);">This action cannot be undone.</p>
            </div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-times"></i> Yes, cancel it',
        cancelButtonText: 'No, keep it',
        reverseButtons: true,
        background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF',
        color: document.documentElement.classList.contains('theme-dark') ? '#FFFFFF' : '#111827'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Cancelling...', text: 'Please wait while we process your request', allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); },
                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });

            const formData = new URLSearchParams();
            formData.append('action', 'cancel');
            formData.append('id', reservationId);
            formData.append('reason', 'Cancelled by customer');
            
            fetch('my-reservations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let title = 'Cancelled!';
                    let icon = 'success';
                    
                    if (data.auto_refunded) {
                        title = 'Cancelled & Auto-Refunded! 💰';
                    } else if (data.refund_created) {
                        title = 'Cancelled — Refund Request Created';
                    }
                    
                    Swal.fire({ 
                        icon: icon, 
                        title: title, 
                        text: data.message, 
                        confirmButtonColor: '#00B761',
                        background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                    }).then(() => { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to cancel order.',
                        background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });
                }
            })
            .catch((error) => {
                console.error('Fetch error:', error);
                Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred. Please try again.',
                    background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });
            });
        }
    });
}
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============================================
        // IMAGE MODAL FUNCTIONS
        // ============================================
        function showImageModal(imageUrl, productName) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = imageUrl;
            modalImg.alt = productName;
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });

        // ============================================
        // THEME FUNCTIONS
        // ============================================
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            if (savedTheme === 'dark') document.documentElement.classList.add('theme-dark');
        });

        // ============================================
        // WARRANTY CLAIM FUNCTIONS FOR ORDERS
        // ============================================
        function openWarrantyClaimRes(reservationId, productId, productName) {
            document.getElementById('claim_date_res').value = '';
            document.getElementById('claim_time_res').value = '';
            document.getElementById('claim_description_res').value = '';
            document.getElementById('claim_photos_res').value = '';
            
            document.getElementById('claim_reservation_id_res').value = reservationId;
            document.getElementById('claim_product_id_res').value = productId;
            document.getElementById('claim_product_name_res').value = productName;
            
            document.getElementById('warrantyProductInfoRes').innerHTML = `
                <div class="d-flex gap-3">
                    <i class="fas fa-box" style="font-size: 40px; color: #00B761;"></i>
                    <div>
                        <strong>${escapeHtml(productName)}</strong><br>
                        <small>Order #: ${reservationId}</small><br>
                        <small class="text-teal">Warranty claim will be verified by the clinic</small>
                    </div>
                </div>
            `;
            
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('claim_date_res').value = tomorrow.toISOString().split('T')[0];
            
            const modal = new bootstrap.Modal(document.getElementById('warrantyClaimModalRes'));
            modal.show();
        }

        function submitWarrantyClaimRes() {
            const reservationId = document.getElementById('claim_reservation_id_res').value;
            const productId = document.getElementById('claim_product_id_res').value;
            const productName = document.getElementById('claim_product_name_res').value;
            const claimDate = document.getElementById('claim_date_res').value;
            const claimTime = document.getElementById('claim_time_res').value;
            const description = document.getElementById('claim_description_res').value;
            
            if (!claimDate) { Swal.fire('Error', 'Please select a date', 'error'); return; }
            if (!claimTime) { Swal.fire('Error', 'Please select a time', 'error'); return; }
            
            Swal.fire({ title: 'Submitting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            const formData = new FormData();
            formData.append('action', 'warranty_claim');
            formData.append('reservation_id', reservationId);
            formData.append('product_id', productId);
            formData.append('product_name', productName);
            formData.append('claim_date', claimDate);
            formData.append('claim_time', claimTime);
            formData.append('description', description);
            
            const photosInput = document.getElementById('claim_photos_res');
            if (photosInput.files) {
                for (let i = 0; i < Math.min(photosInput.files.length, 3); i++) {
                    formData.append('photos[]', photosInput.files[i]);
                }
            }
            
            fetch('/api/warranty-claim.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('warrantyClaimModalRes'));
                    modal.hide();
                    Swal.fire({ icon: 'success', title: 'Success!', text: data.message, confirmButtonColor: '#00B761' })
                        .then(() => { location.reload(); });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error!', 'Network error. Please try again.', 'error');
            });
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ MARK RECEIVED
        // ═══════════════════════════════════════════════════════════
        function markReceived(reservationId) {
            Swal.fire({
                title: 'Order Received?',
                html: 'Confirm that you have received your order in good condition.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#00B761',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check-circle"></i> Yes, Received',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF',
                color: document.documentElement.classList.contains('theme-dark') ? '#FFFFFF' : '#111827'
            }).then((result) => {
                if (!result.isConfirmed) return;
                
                Swal.fire({ title: 'Processing...', allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });
                
                const formData = new URLSearchParams();
                formData.append('action', 'mark_received');
                formData.append('reservation_id', reservationId);
                
                fetch('my-reservations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData.toString()
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Confirmed!', text: 'Order received. Thank you!', timer: 1500, showConfirmButton: false,
                            background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message || 'Failed', 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    Swal.fire('Error', 'Network error. Please try again.', 'error');
                });
            });
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ REFUND MODAL WITH RETURN METHOD
        // ═══════════════════════════════════════════════════════════
        let refundModalInstance = null;
        let currentRefundReservationId = null;

        function openRefundModal(reservationId, productName, orderCode, totalAmount) {
            currentRefundReservationId = reservationId;
            
            document.getElementById('refundOrderCode').textContent = orderCode;
            document.getElementById('refundProductName').textContent = productName;
            document.getElementById('refundOrderTotal').textContent = '₱' + parseFloat(totalAmount).toFixed(2);
            document.getElementById('refundFullAmount').textContent = '₱' + parseFloat(totalAmount).toFixed(2);
            
            document.querySelectorAll('#refundReasons .refund-reason-option').forEach(opt => {
                opt.classList.remove('selected');
                opt.querySelector('input').checked = false;
            });
            
            document.querySelectorAll('.return-method-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.return-method-option:first-child').classList.add('selected');
            document.querySelector('input[name="return_method"][value="dropoff"]').checked = true;
            
            document.getElementById('returnDetailsFields').classList.remove('show');
            document.getElementById('pickupAddressWrap').style.display = 'none';
            document.getElementById('courierInfoWrap').style.display = 'none';
            document.getElementById('returnAddress').value = '';
            document.getElementById('returnScheduledDate').value = '';
            document.getElementById('returnScheduledTime').value = '';
            document.getElementById('returnContactName').value = '';
            document.getElementById('returnContactPhone').value = '';
            document.getElementById('returnNotes').value = '';
            
            document.getElementById('refundDetails').value = '';
            document.getElementById('refundEvidence').value = '';
            document.getElementById('refundPartialAmount').value = '';
            
            document.querySelectorAll('.refund-type-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector('.refund-type-option:first-child').classList.add('selected');
            document.querySelector('input[name="refund_type"][value="full"]').checked = true;
            document.getElementById('partialAmountWrap').style.display = 'none';
            
            if (!refundModalInstance) {
                refundModalInstance = new bootstrap.Modal(document.getElementById('refundModal'));
            }
            refundModalInstance.show();
        }

        function selectRefundReason(el, reason) {
            document.querySelectorAll('#refundReasons .refund-reason-option').forEach(opt => opt.classList.remove('selected'));
            el.classList.add('selected');
            el.querySelector('input').checked = true;
        }

        function selectRefundType(el, type) {
            document.querySelectorAll('.refund-type-option').forEach(opt => opt.classList.remove('selected'));
            el.classList.add('selected');
            el.querySelector('input').checked = true;
            
            document.getElementById('partialAmountWrap').style.display = (type === 'partial') ? 'block' : 'none';
        }

        function selectReturnMethod(el, method) {
            document.querySelectorAll('.return-method-option').forEach(opt => opt.classList.remove('selected'));
            el.classList.add('selected');
            el.querySelector('input').checked = true;
            
            document.getElementById('returnDetailsFields').classList.add('show');
            document.getElementById('pickupAddressWrap').style.display = (method === 'pickup') ? 'block' : 'none';
            document.getElementById('courierInfoWrap').style.display = (method === 'courier') ? 'block' : 'none';
            document.getElementById('scheduleWrap').style.display = (method === 'courier') ? 'none' : 'block';
        }

        function submitRefund() {
            const reasonInput = document.querySelector('input[name="refund_reason"]:checked');
            if (!reasonInput) {
                Swal.fire('Missing Reason', 'Please select a reason for your refund request.', 'warning');
                return;
            }
            
            const returnMethodInput = document.querySelector('input[name="return_method"]:checked');
            if (!returnMethodInput) {
                Swal.fire('Missing Return Method', 'Please select how you want to return the item.', 'warning');
                return;
            }
            const returnMethod = returnMethodInput.value;
            
            const returnAddress = document.getElementById('returnAddress').value.trim();
            if (returnMethod === 'pickup' && !returnAddress) {
                Swal.fire('Missing Address', 'Please enter your pickup address.', 'warning');
                return;
            }
            
            const refundType = document.querySelector('input[name="refund_type"]:checked').value;
            const partialAmount = parseFloat(document.getElementById('refundPartialAmount').value || 0);
            
            if (refundType === 'partial' && partialAmount <= 0) {
                Swal.fire('Invalid Amount', 'Please enter a valid partial amount.', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'request_refund');
            formData.append('reservation_id', currentRefundReservationId);
            formData.append('reason', reasonInput.value);
            formData.append('details', document.getElementById('refundDetails').value.trim());
            formData.append('refund_type', refundType);
            if (refundType === 'partial') {
                formData.append('partial_amount', partialAmount);
            }
            
            formData.append('return_method', returnMethod);
            formData.append('return_address', returnAddress);
            formData.append('return_scheduled_date', document.getElementById('returnScheduledDate').value);
            formData.append('return_scheduled_time', document.getElementById('returnScheduledTime').value);
            formData.append('return_contact_name', document.getElementById('returnContactName').value.trim());
            formData.append('return_contact_phone', document.getElementById('returnContactPhone').value.trim());
            formData.append('return_notes', document.getElementById('returnNotes').value.trim());
            
            const evidenceFile = document.getElementById('refundEvidence').files[0];
            if (evidenceFile) {
                formData.append('evidence', evidenceFile);
            }
            
            Swal.fire({ title: 'Submitting...', allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF' });
            
            fetch('my-reservations.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    refundModalInstance.hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Submitted!',
                        text: 'Your refund request has been submitted. Please wait for the clinic to review.',
                        confirmButtonColor: '#00B761',
                        background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed to submit refund request.', 'error');
                }
            })
            .catch(err => {
                Swal.close();
                console.error('Error:', err);
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            });
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ FILTER TABS (presentation only)
        // ═══════════════════════════════════════════════════════════
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('#orderFilterTabs .filter-tab');
            const cards = document.querySelectorAll('.reservation-card');
            const grid = document.querySelector('.reservations-grid');

            if (!tabs.length || !grid) return;

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const filter = this.dataset.filter;
                    
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    applyOrderFilters();
                });
            });
        });

        // ═══════════════════════════════════════════════════════════
        // ✅ CARD ACCORDION
        // ═══════════════════════════════════════════════════════════
        function toggleCardDetails(btn) {
            const body = btn.nextElementSibling;
            if (!body) return;
            
            const isOpen = body.classList.contains('open');
            
            if (isOpen) {
                body.classList.remove('open');
                btn.classList.remove('open');
                btn.querySelector('span').innerHTML = '<i class="fas fa-info-circle"></i> View more details';
            } else {
                body.classList.add('open');
                btn.classList.add('open');
                btn.querySelector('span').innerHTML = '<i class="fas fa-info-circle"></i> Hide details';
            }
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ MORE MENU (secondary actions dropdown)
        // ═══════════════════════════════════════════════════════════
        function toggleMoreMenu(btn, event) {
            if (event) event.stopPropagation();
            
            const menu = btn.nextElementSibling;
            if (!menu) return;
            
            document.querySelectorAll('.more-menu-list.open').forEach(m => {
                if (m !== menu) m.classList.remove('open');
            });
            
            menu.classList.toggle('open');
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.more-menu-wrap')) {
                document.querySelectorAll('.more-menu-list.open').forEach(m => {
                    m.classList.remove('open');
                });
            }
        });

        // ═══════════════════════════════════════════════════════════
        // ✅ SEARCH BAR (filter cards by text)
        // ═══════════════════════════════════════════════════════════
        function clearOrderSearch() {
            const input = document.getElementById('ordersSearchInput');
            const clearBtn = document.getElementById('searchClearBtn');
            if (input) input.value = '';
            if (clearBtn) clearBtn.style.display = 'none';
            applyOrderFilters();
        }

        function applyOrderFilters() {
            const searchInput = document.getElementById('ordersSearchInput');
            const clearBtn = document.getElementById('searchClearBtn');
            if (!searchInput) return;

            const search = searchInput.value.toLowerCase().trim();
            const activeTab = document.querySelector('#orderFilterTabs .filter-tab.active');
            const filter = activeTab ? activeTab.dataset.filter : 'all';

            // Toggle clear button
            if (clearBtn) clearBtn.style.display = search ? 'flex' : 'none';

            const cards = document.querySelectorAll('.reservation-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const group = card.dataset.filterGroup;
                const matchesFilter = (filter === 'all') || (group === filter);

                // Search sa text content ng card
                const cardText = card.textContent.toLowerCase();
                const matchesSearch = !search || cardText.includes(search);

                const show = matchesFilter && matchesSearch;
                card.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // Empty message
            let emptyMsg = document.getElementById('filterEmptyMsg');
            if (visibleCount === 0) {
                if (!emptyMsg) {
                    emptyMsg = document.createElement('div');
                    emptyMsg.id = 'filterEmptyMsg';
                    emptyMsg.className = 'empty-state-reservations';
                    emptyMsg.style.gridColumn = '1 / -1';
                    document.querySelector('.reservations-grid').appendChild(emptyMsg);
                }
                emptyMsg.innerHTML = search
                    ? `<div class="empty-icon-wrap"><i class="fas fa-search"></i></div>
                       <h2>No results for "${search.replace(/"/g, '&quot;')}"</h2>
                       <p>Try a different keyword or filter tab.</p>`
                    : `<div class="empty-icon-wrap"><i class="fas fa-inbox"></i></div>
                       <h2>No orders in this filter</h2>
                       <p>Try a different filter tab.</p>`;
                emptyMsg.style.display = 'block';
            } else if (emptyMsg) {
                emptyMsg.style.display = 'none';
            }
        }

        // Hook up search input + filter tabs sa bagong combined function
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('ordersSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', applyOrderFilters);
            }
        });
    </script>
</body>
</html>