<?php
// api/supplier.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// Check if supplier is logged in
if (!isset($_SESSION['supplier_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$supplier_id = $_SESSION['supplier_id'];
$clinic_id = $_SESSION['clinic_id'] ?? 1;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================
// DASHBOARD MODULE
// ============================================

if ($action === 'dashboard_stats') {
    try {
        $stats = [];
        
        // Pending approval count
        $pending = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                   WHERE supplier_id = ? AND status = 'Pending Supplier Approval'");
        $pending->execute([$supplier_id]);
        $stats['pending_approval'] = $pending->fetchColumn();
        
        // Approved orders count
        $approved = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                    WHERE supplier_id = ? AND status = 'Supplier Approved'");
        $approved->execute([$supplier_id]);
        $stats['approved'] = $approved->fetchColumn();
        
        // To ship count
        $to_ship = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                   WHERE supplier_id = ? AND status = 'Supplier Approved'");
        $to_ship->execute([$supplier_id]);
        $stats['to_ship'] = $to_ship->fetchColumn();
        
        // Shipped count
        $shipped = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                   WHERE supplier_id = ? AND status = 'Shipped'");
        $shipped->execute([$supplier_id]);
        $stats['shipped'] = $shipped->fetchColumn();
        
        // Delivered count
        $delivered = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                     WHERE supplier_id = ? AND status = 'Delivered'");
        $delivered->execute([$supplier_id]);
        $stats['delivered'] = $delivered->fetchColumn();
        
        // Rejected count
        $rejected = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                    WHERE supplier_id = ? AND status = 'Supplier Rejected'");
        $rejected->execute([$supplier_id]);
        $stats['rejected'] = $rejected->fetchColumn();
        
        // Month total
        $month_total = $pdo->prepare("SELECT SUM(total_amount) FROM purchase_orders 
                                       WHERE supplier_id = ? AND status IN ('Supplier Approved', 'Shipped', 'Delivered')
                                       AND MONTH(created_at) = MONTH(CURDATE())
                                       AND YEAR(created_at) = YEAR(CURDATE())");
        $month_total->execute([$supplier_id]);
        $stats['month_total'] = $month_total->fetchColumn() ?? 0;
        
        // Recent pending orders (for dashboard)
        $recent = $pdo->prepare("SELECT po.*, pr.pr_number, pr.department 
                                  FROM purchase_orders po
                                  JOIN purchase_requests pr ON po.pr_id = pr.id
                                  WHERE po.supplier_id = ? AND po.status = 'Pending Supplier Approval'
                                  ORDER BY po.created_at DESC LIMIT 5");
        $recent->execute([$supplier_id]);
        $stats['recent_pending'] = $recent->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $stats]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_pos') {
    try {
        $status = $_GET['status'] ?? 'all';
        
        $sql = "SELECT po.*, 
                       pr.pr_number, 
                       pr.department, 
                       pr.purpose,
                       CONCAT(u.first_name, ' ', u.last_name) as requested_by,
                       r.full_name as rider_name,
                       r.phone as rider_phone,
                       (SELECT COUNT(*) FROM pr_items WHERE pr_id = po.pr_id) AS item_count
                FROM purchase_orders po
                JOIN purchase_requests pr ON po.pr_id = pr.id
                LEFT JOIN users u ON pr.requested_by = u.id
                LEFT JOIN supplier_riders r ON po.assigned_rider_id = r.id
                WHERE po.supplier_id = ?";
        
        $params = [$supplier_id];
        
        if ($status !== 'all') {
            $sql .= " AND po.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY po.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $pos]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// GET single PO details with items - WITH COMPLETE CLINIC AND REQUESTER INFO
if ($action === 'get_po_details') {
    try {
        $po_id = $_GET['id'] ?? 0;
        
        $po = $pdo->prepare("
            SELECT 
                po.*, 
                pr.pr_number, 
                pr.department, 
                pr.purpose, 
                pr.notes as pr_notes,
                pr.requested_by,
                u.first_name,
                u.last_name,
                u.email as requester_email,
                c.clinic_name,
                c.address as clinic_address,
                c.street_address,
                c.barangay,
                c.city as clinic_city,
                c.province as clinic_state,
                c.postal_code as clinic_postal,
                c.contact as clinic_contact,
                c.phone as clinic_phone,
                c.clinic_email as clinic_email
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            LEFT JOIN users u ON pr.requested_by = u.id
            LEFT JOIN clinics c ON pr.clinic_id = c.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $po->execute([$po_id, $supplier_id]);
        $po_data = $po->fetch(PDO::FETCH_ASSOC);
        
        if (!$po_data) {
            echo json_encode(['success' => false, 'error' => 'PO not found']);
            exit;
        }
        
        // Get items
        $items = $pdo->prepare("SELECT * FROM pr_items WHERE pr_id = ?");
        $items->execute([$po_data['pr_id']]);
        $po_data['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        
        // Format requester name
        $po_data['requester_name'] = trim(($po_data['first_name'] ?? '') . ' ' . ($po_data['last_name'] ?? ''));
        
        // ✅ Build full clinic address (structured)
        $clinic_address_parts = [];
        if (!empty($po_data['street_address'])) $clinic_address_parts[] = $po_data['street_address'];
        if (!empty($po_data['barangay']))       $clinic_address_parts[] = 'Brgy. ' . $po_data['barangay'];
        if (!empty($po_data['clinic_city']))    $clinic_address_parts[] = $po_data['clinic_city'];
        if (!empty($po_data['clinic_state']))   $clinic_address_parts[] = $po_data['clinic_state'];
        if (!empty($po_data['clinic_postal']))  $clinic_address_parts[] = $po_data['clinic_postal'];
        
        // Fallback sa simpleng address
        if (empty($clinic_address_parts) && !empty($po_data['clinic_address'])) {
            $clinic_address_parts[] = $po_data['clinic_address'];
        }
        
        $po_data['full_clinic_address'] = implode(', ', $clinic_address_parts);
        
        // ✅ Contact fallbacks
        if (empty($po_data['contact_person'])) {
            $po_data['contact_person'] = $po_data['clinic_contact'] ?? '';
        }
        if (empty($po_data['contact_phone'])) {
            $po_data['contact_phone'] = $po_data['clinic_phone'] ?? '';
        }
        if (empty($po_data['contact_email'])) {
            $po_data['contact_email'] = $po_data['clinic_email'] ?? '';
        }
        
        // ✅ Payment & VAT fallbacks
        $po_data['payment_method'] = $po_data['payment_method'] ?? 'Bank Transfer';
        $po_data['vat_rate']       = $po_data['vat_rate'] ?? 0.12;
        
        if (empty($po_data['subtotal']) && !empty($po_data['total_amount'])) {
            $po_data['subtotal']   = $po_data['total_amount'] / (1 + $po_data['vat_rate']);
            $po_data['vat_amount'] = $po_data['total_amount'] - $po_data['subtotal'];
        }
        
        echo json_encode(['success' => true, 'data' => $po_data]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// APPROVE PO
if ($action === 'approve_po') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $pr_id = $_POST['pr_id'] ?? 0;
        $estimated_delivery = $_POST['estimated_delivery'] ?? null;
        $notes = $_POST['notes'] ?? '';
        
        $pdo->beginTransaction();
        
        // Kunin ang supplier name para sa notification
        $getSupplier = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $getSupplier->execute([$supplier_id]);
        $supplier = $getSupplier->fetch(PDO::FETCH_ASSOC);
        $supplier_name = $supplier['supplier_name'] ?? 'Supplier';
        
        // Kunin ang PO at PR details para malaman kung sino ang iri-notify
        $getDetails = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $getDetails->execute([$po_id, $supplier_id]);
        $details = $getDetails->fetch(PDO::FETCH_ASSOC);
        
        if (!$details) {
            throw new Exception('PO not found');
        }
        
        $clinic_id = $details['clinic_id'];
        $pr_number = $details['pr_number'];
        
        // Update PO to PO Approved
        $update = $pdo->prepare("UPDATE purchase_orders SET 
                                  status = 'PO Approved',
                                  estimated_delivery = ?,
                                  supplier_response = ?,
                                  response_date = NOW()
                                  WHERE id = ? AND supplier_id = ?");
        $update->execute([$estimated_delivery, $notes, $po_id, $supplier_id]);
        
        // I-update ang PR status sa 'PO Approved'
        $update_pr = $pdo->prepare("UPDATE purchase_requests SET 
                                     status = 'PO Approved' 
                                     WHERE id = ?");
        $update_pr->execute([$pr_id]);
        
        // ========== INSERT NOTIFICATIONS ==========
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // Prepare notification message
        $message = "Supplier {$supplier_name} has APPROVED PO #{$details['po_number']} for PR #{$pr_number}.";
        if ($estimated_delivery) {
            $message .= " Estimated delivery: " . date('M d, Y', strtotime($estimated_delivery));
        }
        
        // 1. Notify SCM roles
        $getSCM = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'SCM'
            AND status = 'Active'
        ");
        $getSCM->execute([$clinic_id]);
        $scm_users = $getSCM->fetchAll();
        
        foreach ($scm_users as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Approved by Supplier',
                $message,
                'success',
                $details['po_number'],
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 2. Notify ClinicAdmin roles
        $getAdmin = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'ClinicAdmin'
            AND status = 'Active'
        ");
        $getAdmin->execute([$clinic_id]);
        $admin_users = $getAdmin->fetchAll();
        
        foreach ($admin_users as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Approved by Supplier',
                $message,
                'success',
                $details['po_number'],
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 3. Notify Finance roles
        $getFinance = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'Finance'
            AND status = 'Active'
        ");
        $getFinance->execute([$clinic_id]);
        $finance_users = $getFinance->fetchAll();
        
        foreach ($finance_users as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Approved by Supplier',
                $message,
                'info',
                $details['po_number'],
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 4. Notify the requester (yung gumawa ng PR)
        if ($details['requested_by']) {
            $insertNotif->execute([
                $details['requested_by'],
                'Your PO has been Approved',
                "Supplier {$supplier_name} has APPROVED PO #{$details['po_number']} for your PR #{$pr_number}.",
                'success',
                $details['po_number'],
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'PO Approved successfully. Notifications sent.']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// MOVE TO SHIP
if ($action === 'move_to_ship') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $pr_id = $_POST['pr_id'] ?? 0;
        
        $pdo->beginTransaction();
        
        // Update PO to To Ship
        $update = $pdo->prepare("UPDATE purchase_orders SET 
                                  status = 'To Ship'
                                  WHERE id = ? AND supplier_id = ?");
        $update->execute([$po_id, $supplier_id]);
        
        // ✅ I-update ang PR status sa 'To Ship' (para makita ni SCM)
        $update_pr = $pdo->prepare("UPDATE purchase_requests SET 
                                     status = 'To Ship' 
                                     WHERE id = ?");
        $update_pr->execute([$pr_id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Order moved to To Ship']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Tracking number generator function
function generateTrackingNumber() {
    $prefix = 'TRK';
    $date = date('Ymd');
    $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    return $prefix . '-' . $date . '-' . $random;
}

// SHIP ORDER - REQUIRED SHIPPING FEE, PHOTO UPLOAD, NO 3RD PARTY COURIER
if ($action === 'ship_order') {
    try {
        $po_id        = $_POST['po_id'] ?? 0;
        $pr_id        = $_POST['pr_id'] ?? 0;
        $remarks      = $_POST['remarks'] ?? '';
        $shipping_fee = floatval($_POST['shipping_fee'] ?? 0);

        // Validate shipping fee
        if ($shipping_fee <= 0) {
            throw new Exception('Shipping fee is required.');
        }

        // Auto-generate reference number
        $tracking = 'SHP-' . date('Ymd') . '-' . str_pad($po_id, 4, '0', STR_PAD_LEFT);

        // Handle photo uploads
        $photo_paths = [];
        if (!empty($_FILES['shipment_photos']['name'][0])) {
            $upload_dir = __DIR__ . '/../uploads/shipment_photos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $allowed  = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024;

            foreach ($_FILES['shipment_photos']['tmp_name'] as $i => $tmp) {
                $type  = $_FILES['shipment_photos']['type'][$i];
                $size  = $_FILES['shipment_photos']['size'][$i];
                $error = $_FILES['shipment_photos']['error'][$i];

                if ($error !== UPLOAD_ERR_OK)   continue;
                if (!in_array($type, $allowed)) continue;
                if ($size > $max_size)          continue;

                $ext      = pathinfo($_FILES['shipment_photos']['name'][$i], PATHINFO_EXTENSION);
                $filename = 'ship_' . $po_id . '_' . time() . '_' . $i . '.' . $ext;

                if (move_uploaded_file($tmp, $upload_dir . $filename)) {
                    $photo_paths[] = 'uploads/shipment_photos/' . $filename;
                }
            }

            if (empty($photo_paths)) {
                throw new Exception('Photo upload failed. Please try again.');
            }
        } else {
            throw new Exception('At least one photo is required.');
        }

        $photos_json = json_encode($photo_paths);

        $pdo->beginTransaction();

        // Get supplier name
        $getSupplier = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $getSupplier->execute([$supplier_id]);
        $supplier      = $getSupplier->fetch(PDO::FETCH_ASSOC);
        $supplier_name = $supplier['supplier_name'] ?? 'Supplier';

        // Get PO and PR details
        $getDetails = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id, pr.total_amount
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $getDetails->execute([$po_id, $supplier_id]);
        $details = $getDetails->fetch(PDO::FETCH_ASSOC);

        if (!$details) throw new Exception('PO not found.');

        $clinic_id = $details['clinic_id'];
        $pr_number = $details['pr_number'];
        $po_number = $details['po_number'];

        // Update PO — status = 'Shipped', carrier = NULL, with photos
        $update = $pdo->prepare("
            UPDATE purchase_orders SET
                status           = 'Shipped',
                tracking_number  = ?,
                carrier          = NULL,
                shipping_fee     = ?,
                shipping_photos  = ?,
                shipping_remarks = ?,
                shipped_date     = NOW()
            WHERE id = ? AND supplier_id = ?
        ");
        $update->execute([$tracking, $shipping_fee, $photos_json, $remarks, $po_id, $supplier_id]);

        // Update PR status
        $pdo->prepare("UPDATE purchase_requests SET status = 'Shipped' WHERE id = ?")
            ->execute([$pr_id]);

        // Update expense status
        $pdo->prepare("UPDATE expenses SET status = 'Waiting For Delivery' WHERE pr_id = ?")
            ->execute([$pr_id]);

        // ========== NOTIFICATIONS ==========
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $fee_display  = '₱' . number_format($shipping_fee, 2);
        $base_message = "Supplier {$supplier_name} has SHIPPED PO #{$po_number} (PR #{$pr_number}). "
                      . "Reference: {$tracking}. Shipping fee: {$fee_display}.";

        // Notify SCM
        $getSCM = $pdo->prepare("
            SELECT id FROM users WHERE clinic_id = ? AND role = 'SCM' AND status = 'Active'
        ");
        $getSCM->execute([$clinic_id]);
        foreach ($getSCM->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'Order Shipped by Supplier',
                $base_message . ' Please prepare for receiving.',
                'info', $po_number,
                "purchase_order.php?view=details&id={$po_id}&tab=tracking"
            ]);
        }

        // Notify ClinicAdmin
        $getAdmin = $pdo->prepare("
            SELECT id FROM users WHERE clinic_id = ? AND role = 'ClinicAdmin' AND status = 'Active'
        ");
        $getAdmin->execute([$clinic_id]);
        foreach ($getAdmin->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'Order Shipped',
                $base_message,
                'info', $po_number,
                "purchase_order.php?view=details&id={$po_id}"
            ]);
        }

        // Notify Finance — always since shipping fee is required
        $getFinance = $pdo->prepare("
            SELECT id FROM users WHERE clinic_id = ? AND role = 'Finance' AND status = 'Active'
        ");
        $getFinance->execute([$clinic_id]);
        foreach ($getFinance->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'Order Shipped - Shipping Fee Added',
                "Order #{$po_number} has been SHIPPED. Shipping fee: {$fee_display}. This will be added to the total amount.",
                'warning', $po_number,
                "purchase_order.php?view=details&id={$po_id}"
            ]);
        }

        // Notify requester
        if ($details['requested_by']) {
            $insertNotif->execute([
                $details['requested_by'],
                'Your Order has been Shipped',
                "Your order #{$po_number} (PR #{$pr_number}) has been SHIPPED. Reference: {$tracking}.",
                'success', $po_number,
                "purchase_request.php?view=details&id={$pr_id}"
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'message'      => 'Order shipped successfully. Notifications sent.',
            'tracking'     => $tracking,
            'photos'       => $photo_paths,
            'shipping_fee' => $shipping_fee
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// GET tracking info
if ($action === 'get_tracking') {
    try {
        $tracking_number = $_GET['tracking'] ?? '';
        
        $stmt = $pdo->prepare("SELECT po.*, pr.department 
                                FROM purchase_orders po
                                JOIN purchase_requests pr ON po.pr_id = pr.id
                                WHERE po.tracking_number = ? AND po.supplier_id = ?");
        $stmt->execute([$tracking_number, $supplier_id]);
        $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $tracking]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


// Reject PO - with notifications
if ($action === 'reject_po') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $pr_id = $_POST['pr_id'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        $pdo->beginTransaction();
        
        // Kunin ang supplier name
        $getSupplier = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $getSupplier->execute([$supplier_id]);
        $supplier = $getSupplier->fetch(PDO::FETCH_ASSOC);
        $supplier_name = $supplier['supplier_name'] ?? 'Supplier';
        
        // Kunin ang PO at PR details
        $getDetails = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $getDetails->execute([$po_id, $supplier_id]);
        $details = $getDetails->fetch(PDO::FETCH_ASSOC);
        
        if (!$details) {
            throw new Exception('PO not found');
        }
        
        $clinic_id = $details['clinic_id'];
        $pr_number = $details['pr_number'];
        $po_number = $details['po_number'];
        
        // Update PO to Rejected
        $update = $pdo->prepare("UPDATE purchase_orders SET 
                                  status = 'Supplier Rejected',
                                  supplier_response = ?,
                                  response_date = NOW()
                                  WHERE id = ? AND supplier_id = ?");
        $update->execute([$reason, $po_id, $supplier_id]);
        
        // PR status -> REJECTED
        $update_pr = $pdo->prepare("UPDATE purchase_requests SET 
                                    status = 'Rejected',
                                    rejection_note = CONCAT('Supplier rejected: ', ?)
                                    WHERE id = ?");
        $update_pr->execute([$reason, $pr_id]);
        
        // ========== INSERT NOTIFICATIONS ==========
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $reject_message = "Supplier {$supplier_name} has REJECTED PO #{$po_number} for PR #{$pr_number}. Reason: {$reason}";
        
        // 1. Notify SCM
        $getSCM = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'SCM' AND status = 'Active'");
        $getSCM->execute([$clinic_id]);
        foreach ($getSCM->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Rejected by Supplier',
                $reject_message,
                'danger',
                $po_number,
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 2. Notify ClinicAdmin
        $getAdmin = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'ClinicAdmin' AND status = 'Active'");
        $getAdmin->execute([$clinic_id]);
        foreach ($getAdmin->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Rejected by Supplier',
                $reject_message,
                'danger',
                $po_number,
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 3. Notify Finance
        $getFinance = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'Finance' AND status = 'Active'");
        $getFinance->execute([$clinic_id]);
        foreach ($getFinance->fetchAll() as $user) {
            $insertNotif->execute([
                $user['id'],
                'PO Rejected by Supplier',
                $reject_message,
                'warning',
                $po_number,
                "purchase_order.php?view=details&id=$po_id"
            ]);
        }
        
        // 4. Notify requester
        if ($details['requested_by']) {
            $insertNotif->execute([
                $details['requested_by'],
                'Your PO was Rejected',
                "Supplier {$supplier_name} rejected your PO #{$po_number}. Reason: {$reason}",
                'danger',
                $po_number,
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'PO rejected. Notifications sent.']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// MARK AS SHIPPED
if ($action === 'mark_shipped') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $tracking = $_POST['tracking_number'] ?? '';
        $carrier = $_POST['carrier'] ?? '';
        
        $update = $pdo->prepare("UPDATE purchase_orders SET 
                                  status = 'Shipped',
                                  tracking_number = ?,
                                  carrier = ?,
                                  shipped_date = NOW()
                                  WHERE id = ? AND supplier_id = ?");
        $update->execute([$tracking, $carrier, $po_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Order marked as shipped']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// PRODUCTS MODULE
// ============================================

// GET supplier products
if ($action === 'get_products') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM supplier_products 
                                WHERE supplier_id = ? ORDER BY created_at DESC");
        $stmt->execute([$supplier_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $products]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ADD product
if ($action === 'add_product') {
    try {
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = $_POST['price'] ?? 0;
        $stock = $_POST['stock'] ?? 0;
        $category = $_POST['category'] ?? '';
        $sku = $_POST['sku'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO supplier_products 
                                (supplier_id, name, description, price, stock, category, sku, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$supplier_id, $name, $description, $price, $stock, $category, $sku]);
        
        echo json_encode(['success' => true, 'message' => 'Product added successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// UPDATE product
if ($action === 'update_product') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = $_POST['price'] ?? 0;
        $stock = $_POST['stock'] ?? 0;
        $category = $_POST['category'] ?? '';
        $sku = $_POST['sku'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE supplier_products SET 
                                name = ?, description = ?, price = ?, 
                                stock = ?, category = ?, sku = ?
                                WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$name, $description, $price, $stock, $category, $sku, $product_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// DELETE product
if ($action === 'delete_product') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM supplier_products WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$product_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// UPDATE STOCK
if ($action === 'update_stock') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        $stock = $_POST['stock'] ?? 0;
        
        $stmt = $pdo->prepare("UPDATE supplier_products SET stock = ? WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$stock, $product_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Stock updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// DELIVERIES MODULE
// ============================================

// GET deliveries
if ($action === 'get_deliveries') {
    try {
        $status = $_GET['status'] ?? 'all';
        
        $sql = "SELECT po.*, pr.pr_number, pr.department 
                FROM purchase_orders po
                JOIN purchase_requests pr ON po.pr_id = pr.id
                WHERE po.supplier_id = ?";
        
        $params = [$supplier_id];
        
        if ($status !== 'all') {
            $sql .= " AND po.status = ?";
            $params[] = $status;
        } else {
            $sql .= " AND po.status IN ('Supplier Approved', 'Shipped', 'Delivered')";
        }
        
        $sql .= " ORDER BY po.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $deliveries]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// UPDATE DELIVERY (add tracking)
if ($action === 'update_delivery') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $tracking = $_POST['tracking_number'] ?? '';
        $carrier = $_POST['carrier'] ?? '';
        $status = $_POST['status'] ?? 'Shipped';
        
        $stmt = $pdo->prepare("UPDATE purchase_orders SET 
                                status = ?,
                                tracking_number = ?,
                                carrier = ?,
                                shipped_date = NOW()
                                WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$status, $tracking, $carrier, $po_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Delivery updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// INVOICES MODULE
// ============================================

// GET invoices
if ($action === 'get_invoices') {
    try {
        $status = $_GET['status'] ?? 'all';
        
        $sql = "SELECT i.*, po.po_number, pr.pr_number 
                FROM invoices i
                JOIN purchase_orders po ON i.po_id = po.id
                JOIN purchase_requests pr ON po.pr_id = pr.id
                WHERE po.supplier_id = ?";
        
        $params = [$supplier_id];
        
        if ($status !== 'all') {
            $sql .= " AND i.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY i.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $invoices]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// CREATE invoice
if ($action === 'create_invoice') {
    try {
        $po_id = $_POST['po_id'] ?? 0;
        $amount = $_POST['amount'] ?? 0;
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $invoice_number = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
        
        $stmt = $pdo->prepare("INSERT INTO invoices 
                                (invoice_number, po_id, supplier_id, amount, due_date, status, created_at)
                                VALUES (?, ?, ?, ?, ?, 'Unpaid', NOW())");
        $stmt->execute([$invoice_number, $po_id, $supplier_id, $amount, $due_date]);
        
        echo json_encode(['success' => true, 'message' => 'Invoice created successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// MARK invoice as paid (when clinic pays)
if ($action === 'mark_invoice_paid') {
    try {
        $invoice_id = $_POST['invoice_id'] ?? 0;
        $payment_ref = $_POST['payment_reference'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE invoices SET 
                                status = 'Paid',
                                payment_date = NOW(),
                                payment_reference = ?
                                WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$payment_ref, $invoice_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Invoice marked as paid']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// REPORTS MODULE
// ============================================

// GET sales report
if ($action === 'sales_report') {
    try {
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? 'all';
        
        if ($month === 'all') {
            // Yearly report by month
            $sql = "SELECT 
                        MONTH(created_at) as month,
                        COUNT(*) as order_count,
                        SUM(total_amount) as total_sales
                    FROM purchase_orders
                    WHERE supplier_id = ? 
                        AND YEAR(created_at) = ?
                        AND status IN ('Supplier Approved', 'Shipped', 'Delivered')
                    GROUP BY MONTH(created_at)
                    ORDER BY month";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplier_id, $year]);
        } else {
            // Monthly report by day
            $sql = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as order_count,
                        SUM(total_amount) as total_sales
                    FROM purchase_orders
                    WHERE supplier_id = ? 
                        AND YEAR(created_at) = ?
                        AND MONTH(created_at) = ?
                        AND status IN ('Supplier Approved', 'Shipped', 'Delivered')
                    GROUP BY DATE(created_at)
                    ORDER BY date";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplier_id, $year, $month]);
        }
        
        $report = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get summary
        $summary = $pdo->prepare("SELECT 
                                    COUNT(*) as total_orders,
                                    SUM(total_amount) as total_revenue,
                                    AVG(total_amount) as average_order
                                   FROM purchase_orders
                                   WHERE supplier_id = ? 
                                    AND YEAR(created_at) = ?
                                    AND status IN ('Supplier Approved', 'Shipped', 'Delivered')");
        $summary->execute([$supplier_id, $year]);
        $summary_data = $summary->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'data' => $report,
            'summary' => $summary_data
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// GET performance metrics
if ($action === 'performance_metrics') {
    try {
        $metrics = [];
        
        // On-time delivery rate
        $ontime = $pdo->prepare("SELECT 
                                    COUNT(*) as total,
                                    SUM(CASE WHEN actual_delivery <= expected_date THEN 1 ELSE 0 END) as ontime
                                  FROM purchase_orders
                                  WHERE supplier_id = ? AND status = 'Delivered'");
        $ontime->execute([$supplier_id]);
        $delivery = $ontime->fetch(PDO::FETCH_ASSOC);
        $metrics['ontime_rate'] = $delivery['total'] > 0 ? 
            round(($delivery['ontime'] / $delivery['total']) * 100, 2) : 0;
        
        // Average response time (hours)
        $response = $pdo->prepare("SELECT 
                                      AVG(TIMESTAMPDIFF(HOUR, created_at, response_date)) as avg_response
                                    FROM purchase_orders
                                    WHERE supplier_id = ? AND response_date IS NOT NULL");
        $response->execute([$supplier_id]);
        $metrics['avg_response_time'] = round($response->fetchColumn() ?? 0, 1);
        
        // Total orders
        $total = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
        $total->execute([$supplier_id]);
        $metrics['total_orders'] = $total->fetchColumn();
        
        // Approval rate
        $approved = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders 
                                    WHERE supplier_id = ? AND status IN ('Supplier Approved', 'Shipped', 'Delivered')");
        $approved->execute([$supplier_id]);
        $approved_count = $approved->fetchColumn();
        $metrics['approval_rate'] = $metrics['total_orders'] > 0 ? 
            round(($approved_count / $metrics['total_orders']) * 100, 2) : 0;
        
        echo json_encode(['success' => true, 'data' => $metrics]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// SETTINGS MODULE
// ============================================

// GET supplier profile
if ($action === 'get_profile') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get contacts
        $contacts = $pdo->prepare("SELECT * FROM supplier_contacts WHERE supplier_id = ?");
        $contacts->execute([$supplier_id]);
        $profile['contacts'] = $contacts->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $profile]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// UPDATE profile
if ($action === 'update_profile') {
    try {
        $company_name = $_POST['company_name'] ?? '';
        $contact_person = $_POST['contact_person'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $mobile = $_POST['mobile'] ?? '';
        $address = $_POST['address'] ?? '';
        $city = $_POST['city'] ?? '';
        $tax_id = $_POST['tax_id'] ?? '';
        $payment_terms = $_POST['payment_terms'] ?? 'Net 30';
        
        $stmt = $pdo->prepare("UPDATE suppliers SET 
                                supplier_name = ?, contact_person = ?, email = ?,
                                phone = ?, mobile = ?, address = ?, city = ?,
                                tax_id = ?, payment_terms = ?
                                WHERE id = ?");
        $stmt->execute([$company_name, $contact_person, $email, $phone, $mobile, 
                       $address, $city, $tax_id, $payment_terms, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ADD contact person
if ($action === 'add_contact') {
    try {
        $name = $_POST['name'] ?? '';
        $position = $_POST['position'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $is_primary = $_POST['is_primary'] ?? 0;
        
        $stmt = $pdo->prepare("INSERT INTO supplier_contacts 
                                (supplier_id, contact_name, position, email, phone, is_primary)
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$supplier_id, $name, $position, $email, $phone, $is_primary]);
        
        echo json_encode(['success' => true, 'message' => 'Contact added successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// UPDATE contact
if ($action === 'update_contact') {
    try {
        $contact_id = $_POST['contact_id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $position = $_POST['position'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $is_primary = $_POST['is_primary'] ?? 0;
        
        $stmt = $pdo->prepare("UPDATE supplier_contacts SET 
                                contact_name = ?, position = ?, email = ?, phone = ?, is_primary = ?
                                WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$name, $position, $email, $phone, $is_primary, $contact_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Contact updated successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// DELETE contact
if ($action === 'delete_contact') {
    try {
        $contact_id = $_POST['contact_id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM supplier_contacts WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$contact_id, $supplier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Contact deleted successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ASSIGN RIDER — with scheduling
// ============================================================
if ($action === 'assign_rider') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $delivery_date = $_POST['delivery_date'] ?? '';
        $time_start = $_POST['time_start'] ?? '';
        $time_end = $_POST['time_end'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        // Validate
        if (!$po_id || !$rider_id || empty($delivery_date) || empty($time_start) || empty($time_end)) {
            throw new Exception('PO ID, rider, date, and time window are required');
        }
        
        // Normalize time
        if (strlen($time_start) === 5) $time_start .= ':00';
        if (strlen($time_end) === 5) $time_end .= ':00';
        
        if ($time_start >= $time_end) {
            throw new Exception('Time start must be before time end');
        }
        
        // Verify PO belongs to supplier and is assignable
        $poStmt = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $poStmt->execute([$po_id, $supplier_id]);
        $po = $poStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$po) {
            throw new Exception('PO not found');
        }
        
        if (!in_array($po['status'], ['To Ship', 'Shipped'])) {
            throw new Exception('PO must be in "To Ship" or "Shipped" status');
        }
        
        // Verify rider belongs to supplier, active, verified
        $riderStmt = $pdo->prepare("
            SELECT id, full_name, phone 
            FROM supplier_riders
            WHERE id = ? AND supplier_id = ? 
              AND status = 'Active' 
              AND verification_status = 'Verified'
        ");
        $riderStmt->execute([$rider_id, $supplier_id]);
        $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) {
            throw new Exception('Rider not found, inactive, or not verified');
        }
        
        // ========== CHECK SCHEDULE CONFLICT ==========
        $day_of_week = (int)date('N', strtotime($delivery_date));
        
        $schedCheck = $pdo->prepare("
            SELECT time_in, time_out, break_start, break_end, is_active
            FROM supplier_rider_schedules
            WHERE rider_id = ? AND day_of_week = ?
        ");
        $schedCheck->execute([$rider_id, $day_of_week]);
        $sched = $schedCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$sched) {
            throw new Exception('Rider has no schedule for this day of week');
        }
        
        if (!$sched['is_active']) {
            throw new Exception('Rider is on day off for this date');
        }
        
        if ($time_start < $sched['time_in'] || $time_end > $sched['time_out']) {
            throw new Exception("Time window must be within shift: " . 
                substr($sched['time_in'], 0, 5) . " - " . substr($sched['time_out'], 0, 5));
        }
        
        if ($sched['break_start'] && $sched['break_end']) {
            if (($time_start < $sched['break_end']) && ($time_end > $sched['break_start'])) {
                throw new Exception("Time window conflicts with break: " . 
                    substr($sched['break_start'], 0, 5) . " - " . substr($sched['break_end'], 0, 5));
            }
        }
        
        // ========== CHECK EXISTING DELIVERY CONFLICT ==========
        $conflictCheck = $pdo->prepare("
            SELECT po_number, scheduled_time_start, scheduled_time_end
            FROM purchase_orders
            WHERE assigned_rider_id = ?
              AND scheduled_delivery_date = ?
              AND status IN ('To Ship', 'Shipped')
              AND id != ?
        ");
        $conflictCheck->execute([$rider_id, $delivery_date, $po_id]);
        $conflicts = $conflictCheck->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($conflicts as $c) {
            if (($time_start < $c['scheduled_time_end']) && ($time_end > $c['scheduled_time_start'])) {
                throw new Exception("Time conflict with existing delivery {$c['po_number']} (" . 
                    substr($c['scheduled_time_start'], 0, 5) . " - " . 
                    substr($c['scheduled_time_end'], 0, 5) . ")");
            }
        }
        
        // ========== ASSIGN ==========
        $pdo->beginTransaction();
        
        $updateStmt = $pdo->prepare("
            UPDATE purchase_orders SET
                assigned_rider_id = ?,
                rider_assigned_at = NOW(),
                scheduled_delivery_date = ?,
                scheduled_time_start = ?,
                scheduled_time_end = ?
            WHERE id = ? AND supplier_id = ?
        ");
        $updateStmt->execute([
            $rider_id,
            $delivery_date,
            $time_start,
            $time_end,
            $po_id,
            $supplier_id
        ]);
        
        // Log sa expense_activity_log (kung may table)
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO expense_activity_log 
                (expense_id, action, user_id, user_name, details, created_at)
                VALUES (?, 'RIDER_ASSIGNED', ?, 'Supplier', ?, NOW())
            ");
            // Kunin ang expense_id kung may matching na expense
            $logStmt->execute([
                $po_id,
                $_SESSION['user_id'] ?? 0,
                "Assigned to {$rider['full_name']} on {$delivery_date} {$time_start}-{$time_end}. {$notes}"
            ]);
        } catch (Exception $logErr) {
            // Silent fail kung walang expense_activity_log
            error_log('Activity log failed: ' . $logErr->getMessage());
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Assigned to {$rider['full_name']} on " . 
                date('M d, Y', strtotime($delivery_date)) . 
                " from " . substr($time_start, 0, 5) . " to " . substr($time_end, 0, 5),
            'rider' => [
                'id' => $rider_id,
                'name' => $rider['full_name'],
                'phone' => $rider['phone'],
            ],
            'schedule' => [
                'date' => $delivery_date,
                'time_start' => substr($time_start, 0, 5),
                'time_end' => substr($time_end, 0, 5),
            ]
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// UNASSIGN RIDER
// ============================================================
if ($action === 'unassign_rider') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        
        if (!$po_id) {
            throw new Exception('PO ID is required');
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE purchase_orders SET
                assigned_rider_id = NULL,
                rider_assigned_at = NULL,
                scheduled_delivery_date = NULL,
                scheduled_time_start = NULL,
                scheduled_time_end = NULL
            WHERE id = ? AND supplier_id = ?
              AND status IN ('To Ship', 'Shipped')
        ");
        $updateStmt->execute([$po_id, $supplier_id]);
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception('PO not found or cannot be unassigned');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Rider unassigned successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// SHIP AND ASSIGN RIDER — COMBINED ACTION
// ============================================================
if ($action === 'ship_and_assign') {
    try {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $pr_id = (int)($_POST['pr_id'] ?? 0);
        $shipping_fee = floatval($_POST['shipping_fee'] ?? 0);
        $delivery_date = $_POST['delivery_date'] ?? '';
        $time_start = $_POST['time_start'] ?? '';
        $time_end = $_POST['time_end'] ?? '';
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Validate
        if (!$po_id || !$rider_id || empty($delivery_date) || empty($time_start) || empty($time_end)) {
            throw new Exception('PO, rider, date, and time are required');
        }
        
        if ($shipping_fee <= 0) {
            throw new Exception('Shipping fee is required');
        }
        
        // Normalize time
        if (strlen($time_start) === 5) $time_start .= ':00';
        if (strlen($time_end) === 5) $time_end .= ':00';
        
        if ($time_start >= $time_end) {
            throw new Exception('Time start must be before time end');
        }
        
        // Get PO details
        $poStmt = $pdo->prepare("
            SELECT po.*, pr.pr_number, pr.requested_by, pr.clinic_id
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            WHERE po.id = ? AND po.supplier_id = ?
        ");
        $poStmt->execute([$po_id, $supplier_id]);
        $po = $poStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$po) throw new Exception('PO not found');
        
        if (!in_array($po['status'], ['To Ship', 'Shipped'])) {
            throw new Exception('PO must be in "To Ship" or "Shipped" status');
        }
        
        // Get rider
        $riderStmt = $pdo->prepare("
            SELECT id, full_name, phone FROM supplier_riders
            WHERE id = ? AND supplier_id = ? AND status = 'Active' AND verification_status = 'Verified'
        ");
        $riderStmt->execute([$rider_id, $supplier_id]);
        $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rider) throw new Exception('Rider not found, inactive, or not verified');
        
        // Check schedule conflict
        $day_of_week = (int)date('N', strtotime($delivery_date));
        
        $schedCheck = $pdo->prepare("
            SELECT time_in, time_out, break_start, break_end, is_active
            FROM supplier_rider_schedules
            WHERE rider_id = ? AND day_of_week = ?
        ");
        $schedCheck->execute([$rider_id, $day_of_week]);
        $sched = $schedCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$sched) throw new Exception('Rider has no schedule for this day');
        if (!$sched['is_active']) throw new Exception('Rider is on day off');
        if ($time_start < $sched['time_in'] || $time_end > $sched['time_out']) {
            throw new Exception("Time must be within shift: " . substr($sched['time_in'],0,5) . " - " . substr($sched['time_out'],0,5));
        }
        
        if ($sched['break_start'] && $sched['break_end']) {
            if (($time_start < $sched['break_end']) && ($time_end > $sched['break_start'])) {
                throw new Exception("Time conflicts with break");
            }
        }
        
        // Check existing deliveries conflict
        $conflictCheck = $pdo->prepare("
            SELECT po_number, scheduled_time_start, scheduled_time_end
            FROM purchase_orders
            WHERE assigned_rider_id = ? AND scheduled_delivery_date = ?
              AND status IN ('To Ship', 'Shipped') AND id != ?
        ");
        $conflictCheck->execute([$rider_id, $delivery_date, $po_id]);
        
        foreach ($conflictCheck->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($time_start < $c['scheduled_time_end']) && ($time_end > $c['scheduled_time_start'])) {
                throw new Exception("Time conflict with existing delivery {$c['po_number']}");
            }
        }
        
        // Handle photo uploads
        $photo_paths = [];
        if (!empty($_FILES['shipment_photos']['name'][0])) {
            $upload_dir = __DIR__ . '/../uploads/shipment_photos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024;
            
            foreach ($_FILES['shipment_photos']['tmp_name'] as $i => $tmp) {
                $type = $_FILES['shipment_photos']['type'][$i];
                $size = $_FILES['shipment_photos']['size'][$i];
                $error = $_FILES['shipment_photos']['error'][$i];
                
                if ($error !== UPLOAD_ERR_OK || !in_array($type, $allowed) || $size > $max_size) continue;
                
                $ext = pathinfo($_FILES['shipment_photos']['name'][$i], PATHINFO_EXTENSION);
                $filename = 'ship_' . $po_id . '_' . time() . '_' . $i . '.' . $ext;
                
                if (move_uploaded_file($tmp, $upload_dir . $filename)) {
                    $photo_paths[] = 'uploads/shipment_photos/' . $filename;
                }
            }
        }
        
        if (empty($photo_paths)) throw new Exception('At least one photo is required');
        
        $photos_json = json_encode($photo_paths);
        $tracking = 'SHP-' . date('Ymd') . '-' . str_pad($po_id, 4, '0', STR_PAD_LEFT);
        
        // Get supplier name
        $getSupplier = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $getSupplier->execute([$supplier_id]);
        $supplier = $getSupplier->fetch(PDO::FETCH_ASSOC);
        $supplier_name = $supplier['supplier_name'] ?? 'Supplier';
        
        $pdo->beginTransaction();
        
        // Update PO — ship + assign rider
        $update = $pdo->prepare("
            UPDATE purchase_orders SET
                status = 'Shipped',
                tracking_number = ?,
                carrier = NULL,
                shipping_fee = ?,
                shipping_photos = ?,
                shipping_remarks = ?,
                shipped_date = NOW(),
                assigned_rider_id = ?,
                rider_assigned_at = NOW(),
                scheduled_delivery_date = ?,
                scheduled_time_start = ?,
                scheduled_time_end = ?
            WHERE id = ? AND supplier_id = ?
        ");
        $update->execute([
            $tracking, $shipping_fee, $photos_json, $remarks,
            $rider_id, $delivery_date, $time_start, $time_end,
            $po_id, $supplier_id
        ]);
        
        // Update PR
        $pdo->prepare("UPDATE purchase_requests SET status = 'Shipped' WHERE id = ?")->execute([$pr_id]);
        
        // Update expense
        $pdo->prepare("UPDATE expenses SET status = 'Waiting For Delivery' WHERE pr_id = ?")->execute([$pr_id]);
        
        // Notification
        $clinic_id = $po['clinic_id'];
        $po_number = $po['po_number'];
        $pr_number = $po['pr_number'];
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $fee_display = '₱' . number_format($shipping_fee, 2);
        $message = "Supplier {$supplier_name} SHIPPED PO #{$po_number} (PR #{$pr_number}). "
                 . "Reference: {$tracking}. Shipping fee: {$fee_display}. "
                 . "Rider: {$rider['full_name']} (scheduled {$delivery_date} " 
                 . substr($time_start,0,5) . "-" . substr($time_end,0,5) . ").";
        
        // Notify SCM
        $getSCM = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'SCM' AND status = 'Active'");
        $getSCM->execute([$clinic_id]);
        foreach ($getSCM->fetchAll() as $user) {
            $insertNotif->execute([$user['id'], 'Order Shipped by Supplier', $message, 'info', $po_number, "purchase_order.php?view=details&id={$po_id}&tab=tracking"]);
        }
        
        // Notify ClinicAdmin
        $getAdmin = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'ClinicAdmin' AND status = 'Active'");
        $getAdmin->execute([$clinic_id]);
        foreach ($getAdmin->fetchAll() as $user) {
            $insertNotif->execute([$user['id'], 'Order Shipped', $message, 'info', $po_number, "purchase_order.php?view=details&id={$po_id}"]);
        }
        
        // Notify Finance
        $getFinance = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'Finance' AND status = 'Active'");
        $getFinance->execute([$clinic_id]);
        foreach ($getFinance->fetchAll() as $user) {
            $insertNotif->execute([$user['id'], 'Order Shipped - Shipping Fee Added', "Order #{$po_number} SHIPPED. Fee: {$fee_display}", 'warning', $po_number, "purchase_order.php?view=details&id={$po_id}"]);
        }
        
        // Notify requester
        if ($po['requested_by']) {
            $insertNotif->execute([$po['requested_by'], 'Your Order has been Shipped', "Order #{$po_number} SHIPPED. Ref: {$tracking}.", 'success', $po_number, "purchase_request.php?view=details&id={$pr_id}"]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Shipped and rider assigned successfully',
            'tracking' => $tracking,
            'shipping_fee' => $shipping_fee,
            'rider_name' => $rider['full_name'],
            'photos' => $photo_paths
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Default response
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>