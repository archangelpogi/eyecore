<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../includes/payment-helper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!isset($_SESSION['clinic_id'])) {
    echo json_encode(['success' => false, 'message' => 'Clinic not selected']);
    exit;
}

$clinicId = $_SESSION['clinic_id'];

// ============================================
// RBAC PERMISSION HELPER FUNCTIONS
// ============================================
function canViewAppointments() { return RBACHelper::hasPermission('appointments_view'); }
function canCreateAppointments() { return RBACHelper::hasPermission('appointments_create'); }
function canEditAppointments() { return RBACHelper::hasPermission('appointments_edit'); }
function canDeleteAppointments() { return RBACHelper::hasPermission('appointments_delete'); }
function canApproveAppointments() { return RBACHelper::hasPermission('appointments_approve'); }
function canRejectAppointments() { return RBACHelper::hasPermission('appointments_reject'); }

// ============================================
// GET: Permissions endpoint
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_permissions'])) {
    $userRole = $_SESSION['role'] ?? '';
    $hasHR = false;
    
    $hrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role = 'HR' AND status = 'Active'");
    $hrStmt->execute([$clinicId]);
    $hasHR = $hrStmt->fetchColumn() > 0;
    
    $permissions = [
        'view' => canViewAppointments(),
        'create' => canCreateAppointments(),
        'edit' => canEditAppointments(),
        'delete' => canDeleteAppointments(),
        'approve' => canApproveAppointments(),
        'reject' => canRejectAppointments()
    ];
    
    if ($userRole === 'ClinicAdmin' && $hasHR && !$permissions['edit']) {
        $permissions = [
            'view' => true,
            'create' => false,
            'edit' => false,
            'delete' => false,
            'approve' => true,
            'reject' => true
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $userRole,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($userRole === 'ClinicAdmin' && !$hasHR),
            'user_id' => $_SESSION['user_id']
        ]
    ]);
    exit();
}

// ── helper: insert notification to a USER ────────────────────────
function notifyUser(PDO $pdo, int $userId, string $title, string $message, string $type, string $link = 'my-appointments.php', ?int $refId = null): void
{
    $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, reference_id, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ")->execute([$userId, $title, $message, $type, $refId, $link]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════
    //  GET  — fetch appointments for a specific date (refresh)
    // ════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        if (!canViewAppointments()) {
            echo json_encode([]);
            exit();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'get_appointment') {
            $apptId = (int)($_GET['id'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT a.*, pr.price as product_price
                FROM appointments a
                LEFT JOIN products pr ON a.product_id = pr.id
                WHERE a.id = ? AND a.clinic_id = ?
            ");
            $stmt->execute([$apptId, $clinicId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($appt) {
                $payment_info = calculatePaymentAmounts($pdo, $clinicId, $appt['product_price'] ?? 0);
                
                echo json_encode([
                    'success' => true,
                    'payment_type' => $payment_info['payment_type'],
                    'total_amount' => $payment_info['total_amount'],
                    'downpayment_amount' => $payment_info['downpayment_amount'],
                    'balance_amount' => $payment_info['balance_amount']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            }
            exit;
        }
        
        if (isset($_GET['action']) && $_GET['action'] === 'history') {
            $search = $_GET['search'] ?? '';
            $fromDate = $_GET['from'] ?? '';
            $toDate = $_GET['to'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $sql = "
                SELECT 
                    a.*,
                    COALESCE(
                        CONCAT(u.first_name, ' ', u.last_name),
                        CONCAT(p.first_name, ' ', p.last_name),
                        a.service_type,
                        'Unknown Patient'
                    ) AS patient_name,
                    COALESCE(
                        pr.name,
                        srv.name,
                        a.service_type,
                        'General'
                    ) AS item_name,
                    d.name AS doctor_name,
                    COALESCE(pr.price, srv.price, 0) AS total_amount
                FROM appointments a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN products pr ON a.product_id = pr.id
                LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
                LEFT JOIN doctors d ON a.doctor_id = d.id
                WHERE a.clinic_id = ?
            ";
            $params = [$clinicId];
            
            if (!empty($status)) {
                $sql .= " AND a.status = ?";
                $params[] = $status;
            } else {
                $sql .= " AND a.status IN ('completed', 'cancelled', 'no-show', 'refunded')";
            }
            
            if (!empty($search)) {
                $sql .= " AND (
                    CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR 
                    CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR 
                    a.service_type LIKE ?
                )";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($fromDate)) {
                $sql .= " AND a.appointment_date >= ?";
                $params[] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $sql .= " AND a.appointment_date <= ?";
                $params[] = $toDate;
            }
            
            $sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 200";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($history as &$row) {
                $row['appointment_time'] = substr($row['appointment_time'], 0, 5);
                $row['formatted_date'] = date('M d, Y', strtotime($row['appointment_date']));
                $row['total_amount'] = floatval($row['total_amount']);
            }
            
            echo json_encode($history);
            exit();
        }

        // ============================================
// GET NO-SHOW INFO (using clinic refund_policy)
// ============================================
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_no_show_info') {
    if (!canEditAppointments()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            a.downpayment_amount,
            a.amount_paid,
            a.patient_id,
            a.user_id,
            c.refund_policy,
            c.penalty_amount,
            c.name as clinic_name
        FROM appointments a
        JOIN clinics c ON a.clinic_id = c.id
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $stmt->execute([$appointmentId, $clinicId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    $downpayment = (float)($appointment['downpayment_amount'] ?? $appointment['amount_paid'] ?? 0);
    $refund_policy = $appointment['refund_policy'] ?? '2:100|1:50|0:0';
    $penaltyAmount = (float)($appointment['penalty_amount'] ?? 500);
    
    // ✅ NO-SHOW = same day = 0 days before
    $days_until = 0;
    $refund_percent = 0;
    
    // ✅ Parse policy
    $policy_parts = explode('|', $refund_policy);
    foreach ($policy_parts as $part) {
        list($days, $percent) = explode(':', $part);
        if ($days_until >= (int)$days) {
            $refund_percent = (int)$percent;
            break;
        }
    }
    
    // ✅ Compute forfeit amount
    $forfeitAmount = $downpayment;
    $refundAmount = 0;
    if ($refund_percent > 0) {
        $refundAmount = $downpayment * ($refund_percent / 100);
        $forfeitAmount = $downpayment - $refundAmount;
    }
    
    echo json_encode([
        'success' => true,
        'downpayment' => $downpayment,
        'forfeit_amount' => $forfeitAmount,
        'refund_amount' => $refundAmount,
        'penalty_amount' => $penaltyAmount,
        'refund_percent' => $refund_percent,
        'refund_policy' => $refund_policy,
        'refund_eligible' => ($refund_percent > 0),
        'clinic_name' => $appointment['clinic_name'],
        'patient_id' => $appointment['patient_id'],
        'user_id' => $appointment['user_id']
    ]);
    exit;
}
        
        $date = $_GET['date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT a.*,
                   COALESCE(CONCAT(u.first_name,' ',u.last_name), a.service_type) AS patient_name,
                   d.name   AS doctor_name,
                   pr.name  AS product_name,
                   pay.payment_status AS pay_status
            FROM appointments a
            LEFT JOIN users    u   ON a.user_id    = u.id
            LEFT JOIN doctors  d   ON a.doctor_id  = d.id
            LEFT JOIN products pr  ON a.product_id = pr.id
            LEFT JOIN payments pay ON a.id         = pay.appointment_id
            WHERE a.appointment_date = ? AND a.clinic_id = ?
            ORDER BY a.appointment_time ASC
        ");
        $stmt->execute([$date, $clinicId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }

    // ════════════════════════════════════════════════════════════
    //  POST — create new appointment (walk-in or online)
    // ════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        if (!canCreateAppointments()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['patient_id'])) {
            echo json_encode(['success' => false, 'message' => 'Patient is required']);
            exit;
        }
        
        if (empty($data['item_id'])) {
            echo json_encode(['success' => false, 'message' => 'Service or Product is required']);
            exit;
        }
        
        if (empty($data['appointment_date']) || empty($data['appointment_time'])) {
            echo json_encode(['success' => false, 'message' => 'Date and time are required']);
            exit;
        }
        
        $price = 0;
        if ($data['item_type'] === 'product') {
            $priceStmt = $pdo->prepare("SELECT price FROM products WHERE id = ? AND clinic_id = ?");
            $priceStmt->execute([$data['item_id'], $clinicId]);
            $product = $priceStmt->fetch();
            $price = $product['price'] ?? 0;
        } elseif ($data['item_type'] === 'service') {
            $priceStmt = $pdo->prepare("SELECT price FROM services WHERE id = ? AND clinic_id = ?");
            $priceStmt->execute([$data['item_id'], $clinicId]);
            $service = $priceStmt->fetch();
            $price = $service['price'] ?? 0;
        }
        
        $payment_info = calculatePaymentAmounts($pdo, $clinicId, $price);
        $booking_flow = $payment_info['booking_flow'] ?? 'approve_first';
        
        $isWalkIn = !empty($data['walk_in']);
        $initialStatus = $isWalkIn ? 'confirmed' : 'pending';
        
        $stmt = $pdo->prepare("
            INSERT INTO appointments
                (clinic_id, patient_id, doctor_id, item_id, item_type, service_type, 
                 appointment_date, appointment_time, notes, status, appointment_type, 
                 total_amount, downpayment_amount, balance_amount, payment_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $clinicId,
            $data['patient_id'],
            $data['doctor_id'] ?? null,
            $data['item_id'],
            $data['item_type'],
            $data['service_type'] ?? null,
            $data['appointment_date'],
            $data['appointment_time'],
            $data['notes'] ?? null,
            $initialStatus,
            $isWalkIn ? 'walk_in' : 'online',
            $payment_info['total_amount'],
            $payment_info['downpayment_amount'],
            $payment_info['balance_amount'],
            $payment_info['payment_type']
        ]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Appointment created successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create appointment']);
        }
        exit;
    }

    // ════════════════════════════════════════════════════════════
    //  PUT  — update status / approve / reject / process refund / mark arrived
    // ════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        // ──────────────────────────────────────────────────────────
        //  APPROVE - FIXED!
        // ──────────────────────────────────────────────────────────
        if ($action === 'approve') {
            if (!canApproveAppointments()) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to approve appointments']);
                exit();
            }
            
            $apptId = (int)($data['appointment_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $patientId = (int)($data['patient_id'] ?? 0);

            if (!$apptId) {
                echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
                exit;
            }
            
            if ($userId <= 0 && $patientId <= 0) {
                $fetchIds = $pdo->prepare("SELECT user_id, patient_id FROM appointments WHERE id = ? AND clinic_id = ?");
                $fetchIds->execute([$apptId, $clinicId]);
                $ids = $fetchIds->fetch(PDO::FETCH_ASSOC);
                
                if ($ids) {
                    $userId = (int)($ids['user_id'] ?? 0);
                    $patientId = (int)($ids['patient_id'] ?? 0);
                }
            }
            
            $notifyId = $userId > 0 ? $userId : $patientId;
            
            if ($notifyId <= 0) {
                echo json_encode(['success' => false, 'message' => 'No user or patient associated with this appointment']);
                exit;
            }

            // ✅ FIX: Include lens_price and all discount fields
            $appt = $pdo->prepare("
                SELECT a.*, 
                       pr.name AS product_name, 
                       pr.price AS product_price,
                       a.lens_type,
                       a.lens_price,
                       a.subtotal,
                       a.discount_amount,
                       a.discount_type,
                       a.discount_percentage,
                       a.total_amount,
                       c.name AS clinic_name,
                       c.id AS clinic_id
                FROM appointments a
                LEFT JOIN products pr ON a.product_id = pr.id
                LEFT JOIN clinics c ON a.clinic_id = c.id
                WHERE a.id = ? AND a.clinic_id = ? AND a.status = 'pending'
            ");
            $appt->execute([$apptId, $clinicId]);
            $row = $appt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found or already processed']);
                exit;
            }

            // ✅ FIX: Use existing values, don't recompute!
            $subtotal = (float)($row['subtotal'] ?? 0);
            $discount_amount = (float)($row['discount_amount'] ?? 0);
            $total_amount = (float)($row['total_amount'] ?? 0);
            $discount_type = $row['discount_type'] ?? 'none';
            $discount_percentage = (float)($row['discount_percentage'] ?? 0);
            $lens_price = (float)($row['lens_price'] ?? 0);
            $lens_type = $row['lens_type'] ?? '';
            
            // Compute payment based on existing total
            $payment_info = calculatePaymentAmounts(
                $pdo, 
                $row['clinic_id'], 
                $total_amount,
                ($discount_type !== 'none')
            );
            
            $payment_type = $payment_info['payment_type'];
            $downpayment_amount = $payment_info['downpayment_amount'];
            $balance_amount = $payment_info['balance_amount'];
            $requires_payment = $payment_info['requires_payment'];
            
            $formattedDate = date('F j, Y', strtotime($row['appointment_date']));
            $formattedTime = date('g:i A',  strtotime($row['appointment_time']));
            
            $isOnlineBooking = ($row['appointment_type'] ?? '') === 'online';
            $isWalkIn = ($row['appointment_type'] ?? '') === 'walk_in';
            
            if ($isWalkIn) {
                $new_status = 'confirmed';
                $payment_status = 'pending';
                $notification_message = "Your walk-in appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been CONFIRMED.";
                
                // ✅ FIX: Preserve existing subtotal, discount_amount, total_amount
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        subtotal = ?,
                        discount_amount = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, 
                    $payment_status, 
                    $subtotal,           // ✅ Preserve
                    $discount_amount,    // ✅ Preserve
                    $total_amount,       // ✅ Preserve
                    $downpayment_amount, 
                    $balance_amount, 
                    $payment_type, 
                    $apptId
                ]);
                
            } else if ($isOnlineBooking) {
                $new_status = 'waiting_payment';
                $payment_status = 'pending';
                
                if ($payment_type === 'downpayment') {
                    if ($total_amount > 0) {
                        $percent = round(($downpayment_amount / $total_amount) * 100);
                    } else {
                        $percent = 0;
                    }
                    $notification_message = "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been APPROVED. "
                        . "Please pay the {$percent}% downpayment of ₱" . number_format($downpayment_amount, 2) 
                        . " to confirm your slot. Balance of ₱" . number_format($balance_amount, 2) . " to be paid at the clinic.";
                } else {
                    $notification_message = "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been APPROVED. "
                        . "Please pay the full amount of ₱" . number_format($total_amount, 2) . " to confirm your slot.";
                }
                
                // ✅ FIX: Preserve existing subtotal, discount_amount, total_amount
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        subtotal = ?,
                        discount_amount = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, 
                    $payment_status,
                    $subtotal,           // ✅ Preserve
                    $discount_amount,    // ✅ Preserve
                    $total_amount,       // ✅ Preserve
                    $downpayment_amount, 
                    $balance_amount, 
                    $payment_type, 
                    $apptId
                ]);
                
            } else {
                $new_status = 'confirmed';
                $payment_status = 'pending';
                $notification_message = "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been CONFIRMED.";
                
                // ✅ FIX: Preserve existing subtotal, discount_amount, total_amount
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        subtotal = ?,
                        discount_amount = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, 
                    $payment_status,
                    $subtotal,           // ✅ Preserve
                    $discount_amount,    // ✅ Preserve
                    $total_amount,       // ✅ Preserve
                    $downpayment_amount, 
                    $balance_amount, 
                    $payment_type, 
                    $apptId
                ]);
            }
            
            if ($updateStmt->rowCount() === 0 && $new_status !== 'waiting_payment') {
                echo json_encode(['success' => false, 'message' => 'Failed to update appointment status']);
                exit;
            }
            
            if ($userId > 0) {
                notifyUser($pdo, $userId, $isWalkIn ? 'Appointment Confirmed! ✅' : 'Appointment Approved! 🎉', $notification_message, 'appointment', 'my-appointments.php', $apptId);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $isWalkIn ? 'Walk-in appointment confirmed' : 'Appointment approved, waiting for payment',
                'payment_required' => $isOnlineBooking && $requires_payment,
                'payment_amount' => $downpayment_amount ?? $total_amount,
                'payment_type' => $payment_type,
                'new_status' => $new_status,
                'appointment_type' => $row['appointment_type'],
                'subtotal' => $subtotal,
                'discount_amount' => $discount_amount,
                'total_amount' => $total_amount,
                'lens_price' => $lens_price
            ]);
            exit;
        }

        // ──────────────────────────────────────────────────────────
        //  PROCESS PAYMENT (called by webhook or manual)
        // ──────────────────────────────────────────────────────────
        if ($action === 'process_payment') {
            if (!canEditAppointments()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit();
            }
            
            $apptId = (int)($data['appointment_id'] ?? 0);
            $payment_method = $data['payment_method'] ?? 'online';
            $reference_no = $data['reference_no'] ?? '';
            $is_full_payment = $data['is_full_payment'] ?? false;
            
            if (!$apptId) {
                echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT a.*, u.id as user_id, c.name as clinic_name
                FROM appointments a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN clinics c ON a.clinic_id = c.id
                WHERE a.id = ? AND a.clinic_id = ?
            ");
            $stmt->execute([$apptId, $clinicId]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appt) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                exit;
            }
            
            $payment_type = $appt['payment_type'] ?? 'full';
            $amount_paid = ($payment_type === 'full' || $is_full_payment) 
                ? $appt['total_amount'] 
                : $appt['downpayment_amount'];
            
            if ($payment_type === 'full' || $is_full_payment) {
                $new_status = 'paid';
                $payment_status = 'paid';
                $message = "Full payment received. Your appointment is confirmed.";
            } else {
                $new_status = 'confirmed';
                $payment_status = 'downpayment_paid';
                $message = "Downpayment of ₱" . number_format($amount_paid, 2) . " received. "
                         . "Please pay the remaining balance of ₱" . number_format($appt['balance_amount'], 2) . " at the clinic.";
            }
            
            $update = $pdo->prepare("
                UPDATE appointments 
                SET status = ?, 
                    payment_status = ?,
                    payment_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$new_status, $payment_status, $apptId]);
            
            $insertPayment = $pdo->prepare("
                INSERT INTO payments (appointment_id, clinic_id, amount, payment_method, reference_number, payment_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $insertPayment->execute([$apptId, $clinicId, $amount_paid, $payment_method, $reference_no, $payment_status]);
            
            if ($appt['user_id']) {
                notifyUser(
                    $pdo,
                    $appt['user_id'],
                    'Payment Received ✅',
                    $message,
                    'appointment',
                    'my-appointments.php',
                    $apptId
                );
            }
            
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
        }

        // ──────────────────────────────────────────────────────────
        //  REJECT
        // ──────────────────────────────────────────────────────────
        if ($action === 'reject') {
            if (!canRejectAppointments()) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to reject appointments']);
                exit();
            }
            
            $apptId = (int)($data['appointment_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');

            if (!$apptId) {
                echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
                exit;
            }
            if (!$reason) {
                echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
                exit;
            }

            $appt = $pdo->prepare("
                SELECT a.*, c.name AS clinic_name
                FROM appointments a
                LEFT JOIN clinics c ON a.clinic_id = c.id
                WHERE a.id = ? AND a.clinic_id = ? AND a.status = 'pending'
            ");
            $appt->execute([$apptId, $clinicId]);
            $row = $appt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found or already processed']);
                exit;
            }

            $pdo->prepare("
                UPDATE appointments
                SET status='cancelled', cancellation_reason=?, cancelled_at=NOW(), updated_at=NOW()
                WHERE id=?
            ")->execute([$reason, $apptId]);

            if ($row && $userId) {
                $formattedDate = date('F j, Y', strtotime($row['appointment_date']));
                $formattedTime = date('g:i A',  strtotime($row['appointment_time']));

                notifyUser(
                    $pdo,
                    $userId,
                    'Appointment Rejected',
                    "Sorry, your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} was not confirmed. "
                    . "Reason: {$reason}. Please book another slot.",
                    'appointment',
                    'my-appointments.php',
                    $apptId
                );
            }

            echo json_encode(['success' => true, 'message' => 'Appointment rejected and patient notified']);
            exit;
        }

// ──────────────────────────────────────────────────────────
//  UPDATE STATUS (completed / no-show)
// ──────────────────────────────────────────────────────────
if ($action === 'update_status') {
    if (!canEditAppointments()) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to update appointment status']);
        exit();
    }
    
    $apptId    = (int)($data['appointment_id'] ?? 0);
    $userId    = (int)($data['user_id'] ?? 0);
    $newStatus = $data['status'] ?? '';

    $allowed = ['completed', 'no-show', 'cancelled'];
    if (!$apptId || !in_array($newStatus, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    // ✅ Get appointment with clinic refund_policy
    $check = $pdo->prepare("
        SELECT a.*, 
               c.refund_policy,
               c.penalty_amount,
               c.name as clinic_name
        FROM appointments a
        JOIN clinics c ON a.clinic_id = c.id
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $check->execute([$apptId, $clinicId]);
    $appt = $check->fetch();
    
    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }

    if (!$userId && $appt['user_id']) {
        $userId = $appt['user_id'];
    }

// ==========================================
// ✅ NO-SHOW - TAMA: Forfeit + Customer Request
// ==========================================
if ($newStatus === 'no-show') {
    $downpayment = (float)($appt['downpayment_amount'] ?? $appt['amount_paid'] ?? 0);
    $refund_policy = $appt['refund_policy'] ?? '2:100|1:50|0:0';
    $penaltyAmount = (float)($appt['penalty_amount'] ?? 500);
    
    // ✅ NO-SHOW = same day = 0 days before
    $days_until = 0;
    $refund_percent = 0;
    
    // ✅ Parse policy
    $policy_parts = explode('|', $refund_policy);
    foreach ($policy_parts as $part) {
        list($days, $percent) = explode(':', $part);
        if ($days_until >= (int)$days) {
            $refund_percent = (int)$percent;
            break;
        }
    }
    
    // ✅ Determine what happens based on refund percent
    if ($refund_percent == 0) {
        // ❌ No refund - forfeit all
        $forfeitedAmount = $downpayment;
        $refundAmount = 0;
        $paymentStatus = 'forfeited';
        $apptStatus = 'no-show';
        $refundEligible = false;
        $eligibleRefund = 0;
        $refundWindow = 0;
        $message = "Downpayment of ₱" . number_format($forfeitedAmount, 2) . " forfeited due to no-show. No refund available.";
    } else {
        // ✅ May refund - pero customer ang magre-request!
        $refundAmount = $downpayment * ($refund_percent / 100);
        $forfeitedAmount = $downpayment - $refundAmount;
        $paymentStatus = 'forfeited';     // ✅ Forfeited muna!
        $apptStatus = 'no-show';          // ✅ No-show status!
        $refundEligible = true;
        $eligibleRefund = $refundAmount;
        $refundWindow = 7; // Days to request refund
        $message = "Downpayment of ₱" . number_format($forfeitedAmount, 2) . " forfeited. "
                 . "You are eligible for {$refund_percent}% refund (₱" . number_format($refundAmount, 2) . "). "
                 . "Please request a refund within {$refundWindow} days.";
    }

    // ✅ Start transaction
    $pdo->beginTransaction();
    
    // ✅ 1. Update appointment
    $stmt = $pdo->prepare("
        UPDATE appointments 
        SET status = ?,
            no_show_marked_by = 'staff',
            no_show_marked_at = NOW(),
            forfeited_amount = ?,
            refund_eligible = ?,
            eligible_refund_amount = ?,
            refund_percentage = ?,
            refund_eligible_until = DATE_ADD(NOW(), INTERVAL ? DAY),
            updated_at = NOW()
        WHERE id = ? AND clinic_id = ?
    ");
    $stmt->execute([
        $apptStatus,
        $forfeitedAmount,
        $refundEligible ? 1 : 0,
        $eligibleRefund,
        $refund_percent,
        $refundWindow,
        $apptId,
        $clinicId
    ]);

    // ✅ 2. Update payment status - FORFEITED muna!
    if ($downpayment > 0) {
        $pdo->prepare("
            UPDATE payments 
            SET payment_status = ?,
                forfeited_amount = ?,
                refunded_amount = ?,
                updated_at = NOW()
            WHERE appointment_id = ? AND clinic_id = ?
        ")->execute([
            $paymentStatus,      // 'forfeited'
            $forfeitedAmount,
            0,                   // Walang refunded amount yet
            $apptId,
            $clinicId
        ]);
    }

    // ✅ 3. Update sales status - CANCELLED muna!
    $pdo->prepare("
        UPDATE sales 
        SET status = ?,
            forfeited_amount = ?,
            updated_at = NOW()
        WHERE appointment_id = ? AND clinic_id = ?
    ")->execute([
        'Cancelled',
        $forfeitedAmount,
        $apptId,
        $clinicId
    ]);

    // ✅ 4. HUWAG gumawa ng refund request dito!
    // Customer ang magre-request later
    
    // ✅ 5. Send notification to customer
    if ($userId > 0) {
        $formattedDate = date('F j, Y', strtotime($appt['appointment_date']));
        $formattedTime = date('g:i A', strtotime($appt['appointment_time']));
        
        if ($refundEligible) {
            $notifMessage = "You missed your appointment at {$appt['clinic_name']} on {$formattedDate} at {$formattedTime}. "
                . "Your downpayment of ₱" . number_format($forfeitedAmount, 2) . " has been forfeited. "
                . "You are eligible for a {$refund_percent}% refund (₱" . number_format($eligibleRefund, 2) . "). "
                . "Please request a refund within {$refundWindow} days.";
                
            notifyUser($pdo, $userId,
                'Missed Appointment - Refund Available 💰',
                $notifMessage,
                'refund',
                'my-appointments.php',
                $apptId
            );
        } else {
            $notifMessage = "You missed your appointment at {$appt['clinic_name']} on {$formattedDate} at {$formattedTime}. "
                . "Your downpayment of ₱" . number_format($forfeitedAmount, 2) . " has been forfeited. "
                . "No refund is available per clinic policy.";
                
            notifyUser($pdo, $userId,
                'Missed Appointment - No Refund ❌',
                $notifMessage,
                'appointment',
                'my-appointments.php',
                $apptId
            );
        }
    }

    // ✅ 6. Log audit
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
        VALUES (?, ?, 'NO_SHOW', 'appointments', ?, NULL, ?, ?, ?, NOW())
    ");
    $auditStmt->execute([
        $_SESSION['user_id'],
        $clinicId,
        $apptId,
        json_encode([
            'refund_percent' => $refund_percent,
            'refund_policy' => $refund_policy,
            'forfeited_amount' => $forfeitedAmount,
            'refund_eligible' => $refundEligible,
            'eligible_refund' => $eligibleRefund,
            'payment_status' => $paymentStatus,
            'appointment_status' => $apptStatus,
            'refund_window' => $refundWindow
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // ✅ 7. Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'appointment_status' => $apptStatus,
            'payment_status' => $paymentStatus,
            'forfeited_amount' => $forfeitedAmount,
            'refund_amount' => $refundAmount,
            'refund_percent' => $refund_percent,
            'refund_eligible' => $refundEligible,
            'eligible_refund' => $eligibleRefund,
            'refund_window' => $refundWindow,
            'refund_policy' => $refund_policy
        ]
    ]);
    exit;
}
    
    // ==========================================
    // ✅ COMPLETED - Normal lang
    // ==========================================
    if ($newStatus === 'completed') {
        $stmt = $pdo->prepare("UPDATE appointments SET status=?, completed_at=NOW(), updated_at=NOW() WHERE id=? AND clinic_id=?");
        $stmt->execute([$newStatus, $apptId, $clinicId]);
        
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'No changes made']);
            exit;
        }

        if ($userId > 0) {
            $apptDetails = $pdo->prepare("SELECT a.*, c.name AS clinic_name FROM appointments a LEFT JOIN clinics c ON a.clinic_id = c.id WHERE a.id = ?");
            $apptDetails->execute([$apptId]);
            $row = $apptDetails->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $formattedDate = date('F j, Y', strtotime($row['appointment_date']));
                $formattedTime = date('g:i A', strtotime($row['appointment_time']));
                notifyUser($pdo, $userId,
                    'Appointment Completed ✅',
                    "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been completed. Thank you!",
                    'appointment', 'my-appointments.php', $apptId
                );
            }
        }

        echo json_encode(['success' => true, 'message' => 'Appointment marked as completed']);
        exit;
    }
}

        // ──────────────────────────────────────────────────────────
        //  MARK ARRIVED
        // ──────────────────────────────────────────────────────────
        if ($action === 'mark_arrived') {
            if (!canEditAppointments()) {
                echo json_encode(['success' => false, 'message' => 'Permission denied']);
                exit();
            }
            
            $apptId = (int)($data['appointment_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $patientId = (int)($data['patient_id'] ?? 0);
            
            if (!$apptId) {
                echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
                exit;
            }
            
            if ($userId <= 0 && $patientId <= 0) {
                $fetchIds = $pdo->prepare("SELECT user_id, patient_id FROM appointments WHERE id = ? AND clinic_id = ?");
                $fetchIds->execute([$apptId, $clinicId]);
                $ids = $fetchIds->fetch(PDO::FETCH_ASSOC);
                
                if ($ids) {
                    $userId = (int)($ids['user_id'] ?? 0);
                    $patientId = (int)($ids['patient_id'] ?? 0);
                }
            }
            
            $check = $pdo->prepare("
                SELECT id, status, patient_id, appointment_date, appointment_time
                FROM appointments 
                WHERE id = ? AND clinic_id = ? AND status = 'confirmed'
            ");
            $check->execute([$apptId, $clinicId]);
            $appt = $check->fetch();
            
            if (!$appt) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found or not confirmed']);
                exit;
            }
            
            $updateStmt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'arrived', 
                    arrived_at = NOW(),
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$apptId]);
            
            if ($userId > 0) {
                notifyUser(
                    $pdo,
                    $userId,
                    'You Have Arrived! 🏥',
                    "You have been marked as arrived for your appointment. Please proceed to the consultation area.",
                    'appointment',
                    'my-appointments.php',
                    $apptId
                );
            }
            
            echo json_encode(['success' => true, 'message' => 'Patient marked as arrived']);
            exit;
        }
if ($action === 'process_refund') {
    if (!canEditAppointments()) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to process refunds']);
        exit();
    }
    
    $refundId = (int)($data['refund_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    $refundAction = $data['refund_action'] ?? '';
    $adminNotes = trim($data['admin_notes'] ?? '');
    
    if (!$refundId || !$refundAction) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    // ✅ Get refund + appointment + payment reference
    $stmt = $pdo->prepare("
        SELECT rr.*, 
               a.paymongo_payment_id,
               a.downpayment_amount, 
               a.total_amount,
               a.id as appointment_id, 
               a.user_id,
               a.patient_id
        FROM refund_requests rr
        JOIN appointments a ON rr.appointment_id = a.id
        WHERE rr.id = ? AND rr.clinic_id = ?
    ");
    $stmt->execute([$refundId, $clinicId]);
    $refund = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$refund) {
        echo json_encode(['success' => false, 'message' => 'Refund request not found']);
        exit;
    }
    
    if ($refund['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'This refund request is no longer pending']);
        exit;
    }
    
    // ==========================================
    // REJECT
    // ==========================================
    if ($refundAction === 'reject') {
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'rejected', 
                admin_notes = CONCAT(IFNULL(admin_notes, ''), ' Rejected: ', ?),
                refund_status = 'failed',
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$adminNotes, $refundId]);
        
        $pdo->prepare("UPDATE appointments SET status = 'paid' WHERE id = ?")
            ->execute([$refund['appointment_id']]);
        
        sendRefundNotification($pdo, $refund['user_id'], $refund['appointment_id'], 'rejected', $adminNotes);
        
        echo json_encode(['success' => true, 'message' => 'Refund request rejected']);
        exit;
    }
    
    // ==========================================
    // APPROVE — AUTOMATIC REFUND via Refunds API
    // ==========================================
    if ($refundAction === 'approve') {
        require_once __DIR__ . '/../api/paymongos.php';
        $paymongo = new PayMongoRefund($pdo);
        
        $refundAmount = (float)($refund['amount'] ?? $refund['downpayment_amount'] ?? 0);
        
        if ($refundAmount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid refund amount']);
            exit;
        }
        
        // ✅ CHECK: may PayMongo payment reference ba?
        $paymentRef = $refund['paymongo_payment_id'] ?? null;
        
        if (empty($paymentRef)) {
            // ⚠️ Walang reference — manual na lang
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'processing',
                    refund_status = 'processing',
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), ' [MANUAL] No PayMongo reference: ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminNotes, $refundId]);
            
            echo json_encode([
                'success' => false,
                'message' => 'No PayMongo payment reference found. Please process refund manually via PayMongo dashboard.',
                'requires_manual' => true
            ]);
            exit;
        }
        
        // ✅ Mark as processing first
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'processing',
                refund_status = 'processing',
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$refundId]);
        
        // ✅ DIRECT REFUND — automatic, no redirect
        $result = $paymongo->processRefundDirect(
            $refundId,
            $paymentRef,
            $refundAmount,
            'requested_by_customer'
        );
        
        if ($result['success']) {
            // ✅ Success — mark completed
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'completed',
                    refund_status = 'completed',
                    paymongo_refund_id = ?,
                    refund_date = NOW(),
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), ' ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $result['refund_id'] ?? null,
                $adminNotes,
                $refundId
            ]);
            
            // ✅ BAGO: I-update ang appointments table
            $pdo->prepare("
                UPDATE appointments 
                SET status = 'refunded',
                    refund_status = 'completed',
                    refund_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$refund['appointment_id']]);
            
            // ✅ BAGO: I-update ang payments table
            $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ")->execute([$refundAmount, $refund['appointment_id']]);
            
            // ✅ BAGO: I-update ang sales table
            $pdo->prepare("
                UPDATE sales 
                SET status = 'Refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ")->execute([$refundAmount, $refund['appointment_id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Refund processed automatically! Amount: ₱' . number_format($refundAmount, 2),
                'refund_id' => $result['refund_id'] ?? null,
                'amount' => $refundAmount,
                'status' => 'completed'
            ]);
            exit;
        } else {
            // ❌ Failed — mark for manual
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'processing',
                    refund_status = 'failed',
                    error_message = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$result['message'] ?? 'Unknown error', $refundId]);
            
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Refund failed. Manual intervention required.',
                'requires_manual' => true
            ]);
            exit;
        }
    }
    
    // ==========================================
    // RETRY (for failed refunds)
    // ==========================================
    if ($refundAction === 'retry') {
        require_once __DIR__ . '/../api/paymongos.php';
        $paymongo = new PayMongoRefund($pdo);
        
        $refundAmount = (float)($refund['amount'] ?? $refund['downpayment_amount'] ?? 0);
        $paymentRef = $refund['paymongo_payment_id'] ?? null;
        
        if (empty($paymentRef)) {
            echo json_encode(['success' => false, 'message' => 'No PayMongo reference found']);
            exit;
        }
        
        $result = $paymongo->processRefundDirect(
            $refundId,
            $paymentRef,
            $refundAmount,
            'requested_by_customer'
        );
        
        if ($result['success']) {
            $pdo->prepare("
                UPDATE refund_requests 
                SET status = 'completed',
                    refund_status = 'completed',
                    paymongo_refund_id = ?,
                    refund_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$result['refund_id'] ?? null, $refundId]);
            
            // ✅ BAGO: I-update ang appointments table
            $pdo->prepare("
                UPDATE appointments 
                SET status = 'refunded',
                    refund_status = 'completed',
                    refund_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$refund['appointment_id']]);
            
            // ✅ BAGO: I-update ang payments table
            $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ")->execute([$refundAmount, $refund['appointment_id']]);
            
            // ✅ BAGO: I-update ang sales table
            $pdo->prepare("
                UPDATE sales 
                SET status = 'Refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ")->execute([$refundAmount, $refund['appointment_id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Refund retry successful!',
                'refund_id' => $result['refund_id'] ?? null
            ]);
        } else {
            $pdo->prepare("
                UPDATE refund_requests 
                SET error_message = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$result['message'] ?? 'Retry failed', $refundId]);
            
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Retry failed'
            ]);
        }
        exit;
    }
    
    // ==========================================
    // MARK MANUAL (fallback kung talagang manual)
    // ==========================================
    if ($refundAction === 'mark_manual') {
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'completed',
                refund_status = 'completed',
                refund_date = NOW(),
                admin_notes = CONCAT(IFNULL(admin_notes, ''), ' [MANUAL REFUND] ', ?),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$adminNotes, $refundId]);
        
        // Update appointment + sales
        $pdo->prepare("UPDATE appointments SET status = 'refunded', refund_status = 'completed', refund_date = NOW() WHERE id = ?")
            ->execute([$refund['appointment_id']]);
        
        $pdo->prepare("UPDATE payments SET payment_status = 'refunded', refunded_amount = ?, updated_at = NOW() WHERE appointment_id = ?")
            ->execute([$refund['amount'], $refund['appointment_id']]);
        
        $pdo->prepare("UPDATE sales SET status = 'Refunded', refunded_amount = ?, updated_at = NOW() WHERE appointment_id = ?")
            ->execute([$refund['amount'], $refund['appointment_id']]);
        
        sendRefundNotification($pdo, $refund['user_id'], $refund['appointment_id'], 'completed', 'Manual refund completed.');
        
        echo json_encode(['success' => true, 'message' => 'Marked as manually refunded']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid refund action: ' . $refundAction]);
    exit;
}

        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request method']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ============================================
// HELPER: Send Refund Notification
// ============================================
function sendRefundNotification($pdo, $userId, $appointmentId, $status, $message = '') {
    try {
        if ($status === 'approved' || $status === 'completed') {
            $title = "✅ Refund Approved & Processed";
            $body = "Your refund for appointment #$appointmentId has been approved and processed. The amount will reflect in your account within 3-5 business days.";
        } elseif ($status === 'rejected') {
            $title = "❌ Refund Request Rejected";
            $body = "Your refund request for appointment #$appointmentId was not approved. Reason: " . ($message ?: 'No reason provided.');
        } else {
            $title = "🔄 Refund Update";
            $body = "Your refund request for appointment #$appointmentId has been updated. Status: " . ucfirst($status);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, 'refund', '/profile.php?tab=appointments', 0, NOW())
        ");
        $stmt->execute([$userId, $title, $body]);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// ERROR LOGGING
// ============================================
function logError($message, $data = null) {
    $log = date('Y-m-d H:i:s') . " - " . $message;
    if ($data) {
        $log .= " - " . json_encode($data);
    }
    error_log($log);
    file_put_contents(__DIR__ . '/../logs/refund_error.log', $log . PHP_EOL, FILE_APPEND);
}
?>