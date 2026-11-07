<?php
// api/supplier_rider.php
// Rider-side API endpoints

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// ✅ Check kung rider ang naka-login
if (!isset($_SESSION['rider_id']) || ($_SESSION['role'] ?? '') !== 'Rider') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$rider_id = (int)$_SESSION['rider_id'];
$supplier_id = (int)$_SESSION['supplier_id'];
$rider_name = $_SESSION['rider_name'] ?? 'Rider';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================
// HELPER: Format date
// ============================================
function formatDateTime($dt) {
    if (!$dt) return null;
    return date('M d, Y h:i A', strtotime($dt));
}

// ============================================
// ACTION: my_deliveries
// ============================================
if ($action === 'my_deliveries') {
    try {
        $status = $_GET['status'] ?? 'active';

$sql = "
    SELECT 
        po.id,
        po.po_number,
        po.pr_id,
        po.status,
        po.total_amount,
        po.payment_method,
        po.tracking_number,
        po.shipping_photos,
        po.shipping_remarks,
        po.shipped_date,
        po.scheduled_delivery_date,
        po.scheduled_time_start,
        po.scheduled_time_end,
        po.rider_assigned_at,
        po.picked_up_at,
        po.out_for_delivery_at,
        po.actual_delivery,
        po.delivery_attempts,
        e.estimated_amount,
        e.price_change_amount,
        e.price_change_percent,
        pr.pr_number,
        pr.department,
        pr.purpose,
        c.clinic_name,
        c.address as clinic_address,
        c.phone as clinic_phone,
        CASE 
            WHEN po.status = 'Delivered' THEN 'Delivered'
            WHEN po.out_for_delivery_at IS NOT NULL THEN 'In Transit'
            WHEN po.picked_up_at IS NOT NULL THEN 'Picked Up'
            WHEN po.status = 'Shipped' THEN 'Assigned'
            ELSE po.status
        END AS rider_status
    FROM purchase_orders po
    JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN clinics c ON pr.clinic_id = c.id
    LEFT JOIN expenses e ON po.pr_id = e.pr_id
    WHERE po.assigned_rider_id = ?
";

        $params = [$rider_id];

        if ($status === 'active') {
            $sql .= " AND po.status IN ('To Ship', 'Shipped')";
        } elseif ($status === 'completed') {
            $sql .= " AND po.status = 'Delivered'";
        }

        $sql .= " ORDER BY 
                    CASE 
                        WHEN po.status = 'Shipped' THEN 1
                        WHEN po.status = 'To Ship' THEN 2
                        WHEN po.status = 'Delivered' THEN 3
                        ELSE 4
                    END,
                    po.scheduled_delivery_date ASC,
                    po.scheduled_time_start ASC
                 LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveries as &$d) {
            $d['scheduled_date_formatted'] = $d['scheduled_delivery_date'] 
                ? date('M d, Y', strtotime($d['scheduled_delivery_date'])) 
                : null;
            $d['shipped_date_formatted'] = formatDateTime($d['shipped_date']);
            $d['delivered_date_formatted'] = formatDateTime($d['actual_delivery']);
            $d['time_window'] = ($d['scheduled_time_start'] && $d['scheduled_time_end'])
                ? substr($d['scheduled_time_start'], 0, 5) . ' - ' . substr($d['scheduled_time_end'], 0, 5)
                : null;
            $d['photos'] = $d['shipping_photos'] ? json_decode($d['shipping_photos'], true) : [];
        }

        echo json_encode(['success' => true, 'data' => $deliveries]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: stats
// ============================================
if ($action === 'stats') {
    try {
        $stats = [];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ? AND status IN ('To Ship', 'Shipped')");
        $stmt->execute([$rider_id]);
        $stats['assigned'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Shipped' AND out_for_delivery_at IS NOT NULL");
        $stmt->execute([$rider_id]);
        $stats['in_transit'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Delivered' AND DATE(actual_delivery) = CURDATE()");
        $stmt->execute([$rider_id]);
        $stats['delivered_today'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Delivered'");
        $stmt->execute([$rider_id]);
        $stats['total'] = (int)$stmt->fetchColumn();

        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: shift_info
// ============================================
if ($action === 'shift_info') {
    try {
        $day_of_week = (int)date('N');

        $stmt = $pdo->prepare("SELECT time_in, time_out, break_start, break_end, is_active FROM supplier_rider_schedules WHERE rider_id = ? AND day_of_week = ?");
        $stmt->execute([$rider_id, $day_of_week]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift || !$shift['is_active']) {
            echo json_encode(['success' => true, 'data' => [
                'is_day_off' => true,
                'day_name' => date('l'),
                'message' => 'Day off sa araw na ito',
            ]]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => [
            'is_day_off' => false,
            'day_name' => date('l'),
            'time_in' => substr($shift['time_in'], 0, 5),
            'time_out' => substr($shift['time_out'], 0, 5),
            'break_start' => $shift['break_start'] ? substr($shift['break_start'], 0, 5) : null,
            'break_end' => $shift['break_end'] ? substr($shift['break_end'], 0, 5) : null,
            'hours' => round((strtotime($shift['time_out']) - strtotime($shift['time_in'])) / 3600, 1),
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: performance
// ============================================
if ($action === 'performance') {
    try {
        $perf = [];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Delivered' AND MONTH(actual_delivery) = MONTH(CURDATE()) AND YEAR(actual_delivery) = YEAR(CURDATE())");
        $stmt->execute([$rider_id]);
        $perf['deliveries_this_month'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total, SUM(CASE WHEN actual_delivery IS NOT NULL AND scheduled_delivery_date IS NOT NULL AND DATE(actual_delivery) <= scheduled_delivery_date THEN 1 ELSE 0 END) as ontime
            FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Delivered' AND MONTH(actual_delivery) = MONTH(CURDATE())
        ");
        $stmt->execute([$rider_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $perf['on_time_rate'] = $row['total'] > 0 ? round(($row['ontime'] / $row['total']) * 100, 1) : 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as total_delivered, SUM(delivery_attempts) as total_attempts FROM purchase_orders WHERE assigned_rider_id = ? AND status = 'Delivered'");
        $stmt->execute([$rider_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_attempts = ($row['total_attempts'] ?? 0) + ($row['total_delivered'] ?? 0);
        $perf['success_rate'] = $total_attempts > 0 ? round(($row['total_delivered'] / $total_attempts) * 100, 1) : 100;

        $stmt = $pdo->prepare("SELECT rating FROM supplier_riders WHERE id = ?");
        $stmt->execute([$rider_id]);
        $perf['rating'] = (float)($stmt->fetchColumn() ?? 0);

        echo json_encode(['success' => true, 'data' => $perf]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: pick_up
// Rider confirms picked up parcel from supplier
// ============================================
if ($action === 'pick_up') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if (!$po_id) throw new Exception('PO ID is required');

        $checkStmt = $pdo->prepare("SELECT id, status, picked_up_at FROM purchase_orders WHERE id = ? AND assigned_rider_id = ?");
        $checkStmt->execute([$po_id, $rider_id]);
        $po = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) throw new Exception('Delivery not found or not assigned to you');
        if ($po['status'] !== 'Shipped') throw new Exception('Parcel must be in Shipped status');
        if ($po['picked_up_at']) throw new Exception('Parcel already picked up');

        $pdo->prepare("UPDATE purchase_orders SET picked_up_at = NOW() WHERE id = ? AND assigned_rider_id = ?")
            ->execute([$po_id, $rider_id]);

        echo json_encode(['success' => true, 'message' => 'Parcel picked up! Ready to start transit.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: start_transit
// Rider starts transit — nasa daan na
// ============================================
if ($action === 'start_transit') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if (!$po_id) throw new Exception('PO ID is required');

        $checkStmt = $pdo->prepare("SELECT id, status, picked_up_at, out_for_delivery_at FROM purchase_orders WHERE id = ? AND assigned_rider_id = ?");
        $checkStmt->execute([$po_id, $rider_id]);
        $po = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) throw new Exception('Delivery not found or not assigned to you');
        if ($po['status'] !== 'Shipped') throw new Exception('Parcel must be in Shipped status');
        if (!$po['picked_up_at']) throw new Exception('Please pick up the parcel first');
        if ($po['out_for_delivery_at']) throw new Exception('Transit already started');

        $pdo->prepare("UPDATE purchase_orders SET out_for_delivery_at = NOW() WHERE id = ? AND assigned_rider_id = ?")
            ->execute([$po_id, $rider_id]);

        echo json_encode(['success' => true, 'message' => 'Transit started. Ingat sa biyahe!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'mark_delivered') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $receiver_name = trim($_POST['receiver_name'] ?? 'Receiver');
        $notes = trim($_POST['notes'] ?? '');
        $collected_amount = floatval($_POST['collected_amount'] ?? 0);
        $change_amount = floatval($_POST['change_amount'] ?? 0);

        if (!$po_id) throw new Exception('PO ID is required');

        $checkStmt = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.assigned_rider_id = ?
        ");
        $checkStmt->execute([$po_id, $rider_id]);
        $po = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) throw new Exception('Delivery not found or not assigned to you');
        if ($po['status'] === 'Delivered') throw new Exception('Delivery already marked as delivered');

        // ✅ Determine if COD
        $payment_method = $po['payment_method'] ?? 'Bank Transfer';
        $is_cod = in_array($payment_method, ['COD', 'Cash']);

        // ✅ Only require collected_amount for COD
        if ($is_cod && $collected_amount <= 0) {
            throw new Exception('COD orders require collected amount');
        }

        // ✅ Force collected_amount = 0 for non-COD
        if (!$is_cod) {
            $collected_amount = 0;
            $change_amount = 0;
        }

        // Photo upload
        $photo_path = null;
        if (!empty($_FILES['delivery_proof']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/delivery_proofs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024;

            $file = $_FILES['delivery_proof'];
            if ($file['error'] === UPLOAD_ERR_OK && in_array($file['type'], $allowed) && $file['size'] <= $max_size) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'delivery_' . $po_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $photo_path = 'uploads/delivery_proofs/' . $filename;
                }
            }
        }

        if (!$photo_path) throw new Exception('Photo proof is required');

        $pdo->beginTransaction();

        // ✅ Update PO
        $pdo->prepare("
            UPDATE purchase_orders SET
                status = 'Delivered',
                actual_delivery = NOW(),
                delivery_proof_photo = ?,
                delivery_receiver_name = ?,
                delivery_notes = ?,
                collected_amount = ?,
                change_amount = ?,
                payment_collected_at = CASE WHEN ? > 0 THEN NOW() ELSE NULL END
            WHERE id = ? AND assigned_rider_id = ?
        ")->execute([
            $photo_path, $receiver_name, $notes,
            $collected_amount, $change_amount, $collected_amount,
            $po_id, $rider_id
        ]);

        $pdo->prepare("UPDATE purchase_requests SET status = 'Delivered' WHERE id = ?")
            ->execute([$po['pr_id']]);

        $pdo->prepare("UPDATE supplier_riders SET total_deliveries = total_deliveries + 1 WHERE id = ?")
            ->execute([$rider_id]);

        // ✅ Update expense based on payment method
        if ($is_cod && $collected_amount > 0) {
            $pdo->prepare("
                UPDATE expenses SET 
                    status = 'Paid',
                    payment_method = 'COD',
                    payment_date = CURDATE(),
                    payment_reference = CONCAT('COD-', ?),
                    delivery_confirmed_at = NOW(),
                    updated_at = NOW()
                WHERE pr_id = ? 
                  AND clinic_id = ?
                  AND status IN ('Approved', 'Waiting For Delivery', 'Ready to Pay', 'Pending')
            ")->execute([$po['po_number'], $po['pr_id'], $po['clinic_id']]);
        } else {
            $pdo->prepare("
                UPDATE expenses SET 
                    status = 'Ready to Pay',
                    delivery_confirmed_at = NOW(),
                    updated_at = NOW()
                WHERE pr_id = ? 
                  AND clinic_id = ?
                  AND status IN ('Approved', 'Waiting For Delivery', 'Pending')
            ")->execute([$po['pr_id'], $po['clinic_id']]);
        }

        // Notify supplier
        try {
            $notifMsg = "Rider {$rider_name} delivered PO #{$po['po_number']}.";
            if ($is_cod && $collected_amount > 0) {
                $notifMsg .= " COD collected: ₱" . number_format($collected_amount, 2);
            }
            $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, reference_number, link, created_at)
                SELECT created_by, 'Delivery Completed', ?, 'success', ?, '#', NOW()
                FROM suppliers WHERE id = ?
            ")->execute([$notifMsg, $po['po_number'], $supplier_id]);
        } catch (Exception $e) {}

        // Notify Finance
        try {
            $financeTitle = $is_cod ? 'COD Payment Received' : 'Delivery Confirmed - Ready to Pay';
            $financeMsg = $is_cod 
                ? "PO #{$po['po_number']} delivered. COD collected: ₱" . number_format($collected_amount, 2) . " — Auto-marked as Paid."
                : "PO #{$po['po_number']} delivered. Payment method: {$payment_method}. Ready for payment processing.";
            
            $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, reference_number, link, created_at)
                SELECT id, ?, ?, ?, ?, 'expenses.php', NOW()
                FROM users 
                WHERE clinic_id = ? AND role = 'Finance' AND status = 'Active'
            ")->execute([$financeTitle, $financeMsg, 'success', $po['po_number'], $po['clinic_id']]);
        } catch (Exception $e) {}

        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Delivery confirmed!',
            'photo' => $photo_path,
            'payment_method' => $payment_method,
            'is_cod' => $is_cod,
            'auto_paid' => ($is_cod && $collected_amount > 0)
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: report_failed
// ============================================
if ($action === 'report_failed') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$po_id) throw new Exception('PO ID is required');
        if (!$reason) throw new Exception('Reason is required');

        $checkStmt = $pdo->prepare("
            SELECT po.*, pr.pr_number 
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.assigned_rider_id = ?
        ");
        $checkStmt->execute([$po_id, $rider_id]);
        $po = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) throw new Exception('Delivery not found or not assigned to you');

        $fullReason = $reason;
        if ($notes) $fullReason .= ' — ' . $notes;

        $pdo->prepare("
            UPDATE purchase_orders SET
                failure_reason = ?,
                delivery_attempts = delivery_attempts + 1
            WHERE id = ? AND assigned_rider_id = ?
        ")->execute([$fullReason, $po_id, $rider_id]);

        echo json_encode(['success' => true, 'message' => 'Failed delivery reported.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_delivery') {
    try {
        $po_id = (int)($_GET['id'] ?? 0);
        if (!$po_id) throw new Exception('PO ID is required');

        $stmt = $pdo->prepare("
            SELECT po.*, 
                   pr.pr_number, pr.department, pr.purpose,
                   c.clinic_name, c.address as clinic_address, c.phone as clinic_phone, c.clinic_email,
                   e.estimated_amount,
                   e.price_change_amount,
                   e.price_change_percent,
                   CASE 
                       WHEN po.status = 'Delivered' THEN 'Delivered'
                       WHEN po.out_for_delivery_at IS NOT NULL THEN 'In Transit'
                       WHEN po.picked_up_at IS NOT NULL THEN 'Picked Up'
                       WHEN po.status = 'Shipped' THEN 'Assigned'
                       ELSE po.status
                   END AS rider_status
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            LEFT JOIN clinics c ON pr.clinic_id = c.id
            LEFT JOIN expenses e ON po.pr_id = e.pr_id
            WHERE po.id = ? AND po.assigned_rider_id = ?
        ");
        $stmt->execute([$po_id, $rider_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) throw new Exception('Delivery not found or not assigned to you');

        // ✅ Determine if COD
        $payment_method = $delivery['payment_method'] ?? 'Bank Transfer';
        $is_cod = in_array($payment_method, ['COD', 'Cash']);
        
        $delivery['payment_method'] = $payment_method;
        $delivery['is_cod'] = $is_cod;

        // Get items
        $stmt = $pdo->prepare("SELECT * FROM pr_items WHERE pr_id = ?");
        $stmt->execute([$delivery['pr_id']]);
        $delivery['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $delivery['photos'] = $delivery['shipping_photos'] ? json_decode($delivery['shipping_photos'], true) : [];
        $delivery['time_window'] = ($delivery['scheduled_time_start'] && $delivery['scheduled_time_end'])
            ? substr($delivery['scheduled_time_start'], 0, 5) . ' - ' . substr($delivery['scheduled_time_end'], 0, 5)
            : null;

        echo json_encode(['success' => true, 'data' => $delivery]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Default
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);