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

            $appt = $pdo->prepare("
                SELECT a.*, 
                       pr.name AS product_name, 
                       pr.price AS product_price,
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

            $payment_info = calculatePaymentAmounts($pdo, $row['clinic_id'], $row['product_price'] ?? 0);
            
            $payment_type = $payment_info['payment_type'];
            $downpayment_amount = $payment_info['downpayment_amount'];
            $total_amount = $payment_info['total_amount'];
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
                
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, $payment_status, $total_amount, 
                    $downpayment_amount, $balance_amount, $payment_type, $apptId
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
                
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, $payment_status, $total_amount, 
                    $downpayment_amount, $balance_amount, $payment_type, $apptId
                ]);
                
            } else {
                $new_status = 'confirmed';
                $payment_status = 'pending';
                $notification_message = "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been CONFIRMED.";
                
                $updateStmt = $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?,
                        payment_status = ?,
                        total_amount = ?,
                        downpayment_amount = ?,
                        balance_amount = ?,
                        payment_type = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $new_status, $payment_status, $total_amount, 
                    $downpayment_amount, $balance_amount, $payment_type, $apptId
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
                'appointment_type' => $row['appointment_type']
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

            $check = $pdo->prepare("SELECT id, user_id, payment_status FROM appointments WHERE id = ? AND clinic_id = ?");
            $check->execute([$apptId, $clinicId]);
            $appt = $check->fetch();
            
            if (!$appt) {
                echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                exit;
            }

            if (!$userId && $appt['user_id']) {
                $userId = $appt['user_id'];
            }

            if ($newStatus === 'no-show') {
                if ($appt['payment_status'] === 'paid') {
                    $stmt = $pdo->prepare("UPDATE appointments SET status='paid', updated_at=NOW() WHERE id=? AND clinic_id=?");
                    $stmt->execute([$apptId, $clinicId]);
                    $message = "Appointment marked as no-show but kept as paid (refund available)";
                } else {
                    $stmt = $pdo->prepare("UPDATE appointments SET status='no-show', updated_at=NOW() WHERE id=? AND clinic_id=?");
                    $stmt->execute([$apptId, $clinicId]);
                    $message = "Appointment marked as no-show";
                }
            } else {
                $stmt = $pdo->prepare("UPDATE appointments SET status=?, updated_at=NOW() WHERE id=? AND clinic_id=?");
                $stmt->execute([$newStatus, $apptId, $clinicId]);
                $message = "Appointment marked as $newStatus";
            }

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
                    $formattedTime = date('g:i A',  strtotime($row['appointment_time']));

                    if ($newStatus === 'completed') {
                        notifyUser($pdo, $userId,
                            'Appointment Completed ✅',
                            "Your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime} has been completed. Thank you!",
                            'appointment', 'my-appointments.php', $apptId
                        );
                    } elseif ($newStatus === 'no-show') {
                        if ($appt['payment_status'] === 'paid') {
                            notifyUser($pdo, $userId,
                                'Missed Appointment - Refund Available',
                                "You missed your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime}. Since you already paid, you can request a refund or rebook.",
                                'appointment', 'my-appointments.php', $apptId
                            );
                        } else {
                            notifyUser($pdo, $userId,
                                'Marked as No-show',
                                "You were marked as no-show for your appointment at {$row['clinic_name']} on {$formattedDate} at {$formattedTime}.",
                                'appointment', 'my-appointments.php', $apptId
                            );
                        }
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => $message]);
            exit;
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

        // ──────────────────────────────────────────────────────────
        //  PROCESS REFUND WITH PAYMONGO INTEGRATION (UPDATED)
        // ──────────────────────────────────────────────────────────
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
            
            // Get refund request details
            $stmt = $pdo->prepare("
                SELECT rr.*, a.paymongo_payment_id, a.downpayment_amount, a.total_amount,
                       a.id as appointment_id, a.user_id
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
            // REJECT: Simple update, no PayMongo
            // ==========================================
            if ($refundAction === 'reject') {
                $stmt = $pdo->prepare("
                    UPDATE refund_requests 
                    SET status = 'rejected', 
                        admin_notes = CONCAT(IFNULL(admin_notes, ''), ' Rejected: ', ?),
                        refund_status = 'failed',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$adminNotes, $refundId]);
                
                $pdo->prepare("UPDATE appointments SET status = 'paid' WHERE id = ?")
                    ->execute([$refund['appointment_id']]);
                
                sendRefundNotification($pdo, $refund['user_id'], $refund['appointment_id'], 'rejected', $adminNotes);
                
                echo json_encode(['success' => true, 'message' => 'Refund request rejected']);
                exit;
            }
            
// ==========================================
// APPROVE: Create NEW PayMongo Checkout Session for Refund
// ==========================================
if ($refundAction === 'approve') {
    
    // ✅ Load PayMongoRefund class
    require_once __DIR__ . '/paymongos.php';
    $paymongo = new PayMongoRefund($pdo);
    
    // ✅ Determine refund amount
    $refundAmount = $refund['amount'] ?? $refund['downpayment_amount'] ?? 0;
    
    if ($refundAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid refund amount']);
        exit;
    }
    
    // ✅ Create NEW checkout session for refund (HINDI yung luma!)
    $result = $paymongo->createRefundCheckout(
        $refundId,
        $refund['appointment_id'],
        $refundAmount,
        $refund['reason'] ?? 'Customer requested refund'
    );
    
    if ($result['success']) {
        // ✅ Update refund status to 'processing'
        $pdo->prepare("
            UPDATE refund_requests 
            SET refund_status = 'processing', 
                status = 'processing',
                paymongo_refund_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$result['checkout_id'], $refundId]);
        
        // ✅ Update admin notes if provided
        if (!empty($adminNotes)) {
            $pdo->prepare("
                UPDATE refund_requests 
                SET admin_notes = CONCAT(IFNULL(admin_notes, ''), ' Admin note: ', ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$adminNotes, $refundId]);
        }
        
        // ✅ Return the NEW redirect URL
        echo json_encode([
            'success' => true,
            'redirect' => true,
            'checkout_url' => $result['checkout_url'],  // ✅ NEW checkout URL
            'checkout_id' => $result['checkout_id'],
            'ref_no' => $result['ref_no'],
            'message' => 'Redirecting to PayMongo to process refund...'
        ]);
        exit;
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'requires_manual' => true
        ]);
        exit;
    }
}
            
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
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