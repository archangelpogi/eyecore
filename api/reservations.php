<?php
// api/reservations.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

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
function canViewReservations() { return RBACHelper::hasPermission('reservations_view'); }
function canCreateReservations() { return RBACHelper::hasPermission('reservations_create'); }
function canEditReservations() { return RBACHelper::hasPermission('reservations_edit'); }
function canDeleteReservations() { return RBACHelper::hasPermission('reservations_delete'); }
function canApproveReservations() { return RBACHelper::hasPermission('reservations_approve'); }
function canRejectReservations() { return RBACHelper::hasPermission('reservations_reject'); }

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
        'view' => canViewReservations(),
        'create' => canCreateReservations(),
        'edit' => canEditReservations(),
        'delete' => canDeleteReservations(),
        'approve' => canApproveReservations(),
        'reject' => canRejectReservations()
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
function notifyUser(PDO $pdo, int $userId, string $title, string $message, string $type, string $link = 'my-reservations.php', ?int $refId = null): void
{
    $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, reference_id, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ")->execute([$userId, $title, $message, $type, $refId, $link]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════
    //  GET  — fetch reservations
    // ════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // ✅ Check view permission first
        if (!canViewReservations()) {
            echo json_encode([]);
            exit();
        }
        
        // ✅ HISTORY ENDPOINT
        if (isset($_GET['action']) && $_GET['action'] === 'history') {
            $search = $_GET['search'] ?? '';
            $fromDate = $_GET['from'] ?? '';
            $toDate = $_GET['to'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $sql = "
                SELECT 
                    r.*,
                    CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                    u.email AS user_email,
                    p.name AS product_name,
                    p.category AS product_category,
                    p.price AS product_price
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN products p ON r.product_id = p.id
                WHERE r.clinic_id = ?
            ";
            $params = [$clinicId];
            
            // Add status filter
            if (!empty($status)) {
                $sql .= " AND r.status = ?";
                $params[] = $status;
            } else {
                // Show completed and cancelled only
                $sql .= " AND r.status IN ('completed', 'cancelled')";
            }
            
            // Add search filter
            if (!empty($search)) {
                $sql .= " AND (
                    CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR 
                    u.email LIKE ? OR 
                    p.name LIKE ? OR
                    r.reservation_code LIKE ?
                )";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // Add date range filters
            if (!empty($fromDate)) {
                $sql .= " AND DATE(r.created_at) >= ?";
                $params[] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $sql .= " AND DATE(r.created_at) <= ?";
                $params[] = $toDate;
            }
            
            $sql .= " ORDER BY r.created_at DESC LIMIT 200";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data
            foreach ($history as &$row) {
                $row['formatted_date'] = date('M d, Y', strtotime($row['created_at']));
                $row['total_amount'] = floatval($row['total_amount']);
                $row['downpayment_amount'] = floatval($row['downpayment_amount']);
                $row['balance_amount'] = floatval($row['balance_amount']);
                $row['quantity'] = intval($row['quantity']);
            }
            
            echo json_encode($history);
            exit();
        }
        
        // ✅ REGULAR GET - fetch reservations by status
        $status = $_GET['status'] ?? '';
        
        if (!empty($status)) {
            $stmt = $pdo->prepare("
                SELECT r.*,
                       CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                       u.email AS user_email,
                       u.contact AS user_contact,
                       p.name AS product_name,
                       p.category AS product_category,
                       p.price AS product_price,
                       p.image AS product_image
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN products p ON r.product_id = p.id
                WHERE r.clinic_id = ? AND r.status = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$clinicId, $status]);
        } else {
            $stmt = $pdo->prepare("
                SELECT r.*,
                       CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                       u.email AS user_email,
                       u.contact AS user_contact,
                       p.name AS product_name,
                       p.category AS product_category,
                       p.price AS product_price,
                       p.image AS product_image
                FROM reservations r
                JOIN users u ON r.user_id = u.id
                JOIN products p ON r.product_id = p.id
                WHERE r.clinic_id = ?
                ORDER BY r.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$clinicId]);
        }
        
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        foreach ($reservations as &$res) {
            $res['total_amount'] = floatval($res['total_amount']);
            $res['downpayment_amount'] = floatval($res['downpayment_amount']);
            $res['balance_amount'] = floatval($res['balance_amount']);
            $res['quantity'] = intval($res['quantity']);
        }
        
        echo json_encode($reservations);
        exit();
    }

    // ════════════════════════════════════════════════════════════
    //  POST  — create reservation (admin side)
    // ════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        // ✅ Check create permission
        if (!canCreateReservations()) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to create reservations']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($data['user_id']) || empty($data['product_id'])) {
            echo json_encode(['success' => false, 'message' => 'User and product are required']);
            exit;
        }
        
        // Generate reservation code
        $reservationCode = 'RES-' . strtoupper(substr(md5(uniqid()), 0, 8));
        
        // Get product price
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$data['product_id'], $clinicId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }
        
        $quantity = $data['quantity'] ?? 1;
        $totalAmount = $product['price'] * $quantity;
        $downpaymentAmount = $totalAmount * 0.5; // 50% downpayment
        $balanceAmount = $totalAmount - $downpaymentAmount;
        
        // Insert reservation
        $stmt = $pdo->prepare("
            INSERT INTO reservations (
                reservation_code, user_id, product_id, clinic_id, lens_type,
                prescription_id, total_amount, downpayment_amount, balance_amount,
                preferred_date, preferred_time, color_code, color_name, quantity,
                notes, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        
        $stmt->execute([
            $reservationCode,
            $data['user_id'],
            $data['product_id'],
            $clinicId,
            $data['lens_type'] ?? 'frame_only',
            $data['prescription_id'] ?? null,
            $totalAmount,
            $downpaymentAmount,
            $balanceAmount,
            $data['preferred_date'] ?? date('Y-m-d'),
            $data['preferred_time'] ?? date('H:i:s'),
            $data['color_code'] ?? null,
            $data['color_name'] ?? null,
            $quantity,
            $data['notes'] ?? null
        ]);
        
        // Notify user
        notifyUser(
            $pdo,
            $data['user_id'],
            'New Reservation Created 📝',
            "A new reservation (Code: {$reservationCode}) has been created for you. Please wait for clinic confirmation.",
            'reservation',
            'my-reservations.php',
            $pdo->lastInsertId()
        );
        
        echo json_encode(['success' => true, 'message' => 'Reservation created successfully', 'reservation_code' => $reservationCode]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    //  PUT  — update reservation status
    // ════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        $reservationId = (int)($data['reservation_id'] ?? 0);
        
        if (!$reservationId) {
            echo json_encode(['success' => false, 'message' => 'Reservation ID required']);
            exit;
        }
        
        // Get reservation details
        $stmt = $pdo->prepare("
            SELECT r.*, u.id as user_id_val, u.email as user_email,
                   CONCAT(u.first_name, ' ', u.last_name) as user_name,
                   p.name as product_name
            FROM reservations r
            JOIN users u ON r.user_id = u.id
            JOIN products p ON r.product_id = p.id
            WHERE r.id = ? AND r.clinic_id = ?
        ");
        $stmt->execute([$reservationId, $clinicId]);
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            echo json_encode(['success' => false, 'message' => 'Reservation not found']);
            exit;
        }
        
        $userId = $reservation['user_id_val'];
        $resCode = $reservation['reservation_code'];
        
        // ──────────────────────────────────────────────────────────
        //  CONFIRM RESERVATION
        // ──────────────────────────────────────────────────────────
        if ($action === 'confirm') {
            if (!canApproveReservations()) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to confirm reservations']);
                exit();
            }
            
            if ($reservation['status'] !== 'pending') {
                echo json_encode(['success' => false, 'message' => 'Reservation cannot be confirmed in current status']);
                exit;
            }
            
            $pdo->prepare("UPDATE reservations SET status = 'confirmed', updated_at = NOW() WHERE id = ?")
                ->execute([$reservationId]);
            
            notifyUser(
                $pdo,
                $userId,
                'Reservation Confirmed ✅',
                "Your reservation #{$resCode} has been CONFIRMED! Please proceed with the downpayment of ₱" . number_format($reservation['downpayment_amount'], 2) . ".",
                'reservation',
                'my-reservations.php',
                $reservationId
            );
            
            echo json_encode(['success' => true, 'message' => 'Reservation confirmed. User notified.']);
            exit;
        }
        
        // ──────────────────────────────────────────────────────────
        //  COMPLETE RESERVATION
        // ──────────────────────────────────────────────────────────
        if ($action === 'complete') {
            if (!canEditReservations()) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to complete reservations']);
                exit();
            }
            
            if ($reservation['status'] !== 'confirmed') {
                echo json_encode(['success' => false, 'message' => 'Only confirmed reservations can be marked as completed']);
                exit;
            }
            
            $pdo->prepare("UPDATE reservations SET status = 'completed', updated_at = NOW() WHERE id = ?")
                ->execute([$reservationId]);
            
            notifyUser(
                $pdo,
                $userId,
                'Reservation Completed 🎉',
                "Your reservation #{$resCode} for {$reservation['product_name']} has been COMPLETED. Thank you for your purchase!",
                'reservation',
                'my-reservations.php',
                $reservationId
            );
            
            echo json_encode(['success' => true, 'message' => 'Reservation marked as completed.']);
            exit;
        }
        
        // ──────────────────────────────────────────────────────────
        //  CANCEL RESERVATION
        // ──────────────────────────────────────────────────────────
        if ($action === 'cancel') {
            if (!canRejectReservations()) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to cancel reservations']);
                exit();
            }
            
            $reason = trim($data['reason'] ?? 'Cancelled by clinic');
            
            $pdo->prepare("
                UPDATE reservations 
                SET status = 'cancelled', 
                    notes = CONCAT(IFNULL(notes,''), '\n[Cancelled: {$reason}]'),
                    updated_at = NOW() 
                WHERE id = ?
            ")->execute([$reservationId]);
            
            notifyUser(
                $pdo,
                $userId,
                'Reservation Cancelled ❌',
                "Your reservation #{$resCode} has been CANCELLED. Reason: {$reason}",
                'reservation',
                'my-reservations.php',
                $reservationId
            );
            
            echo json_encode(['success' => true, 'message' => 'Reservation cancelled.']);
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>