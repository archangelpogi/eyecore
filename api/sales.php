<?php
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

// Check session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ RBAC Permission Helper Class for Sales
class SalesPermission {
    private static $module = 'sales';

    public static function can($action) {
        $permissionMap = [
            'view' => self::$module . '_view',
            'create' => self::$module . '_create',
            'edit' => self::$module . '_edit',
            'delete' => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject' => self::$module . '_reject',
            'export' => self::$module . '_view'
        ];

        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }

    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot ' . $action . ' sales records']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ============= AUDIT LOG FUNCTION =============
function logAudit($pdo, $user_id, $clinic_id, $action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;

    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    return $stmt->execute([$user_id, $clinic_id, $action, $table_name, $record_id, $old_json, $new_json, $ip_address, $user_agent]);
}

// ============================================
// ✅ GET SYSTEM SETTINGS
// ============================================
function getSystemSettings($pdo) {
    $stmt = $pdo->prepare("
        SELECT vat_rate, pwd_senior_discount, pwd_senior_vat_exempt
        FROM system_settings
        LIMIT 1
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// ✅ CHECK PWD/SENIOR VERIFICATION STATUS
// ============================================
function checkPwdSeniorStatus($pdo, $user_id, $clinic_id) {
    // ✅ Check main verification table (source of truth)
    $stmt = $pdo->prepare("
        SELECT 
            status, 
            verification_type, 
            verified_at,
            DATE_ADD(verified_at, INTERVAL 1 YEAR) as expiry_date
        FROM user_verifications 
        WHERE user_id = ? 
        AND clinic_id = ? 
        AND status = 'verified'
        ORDER BY verified_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $clinic_id]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verification) {
        return [
            'is_verified' => false,
            'verification_type' => null,
            'status' => 'none',
            'expiry_date' => null,
            'message' => 'No verification found'
        ];
    }

    // ✅ Check if verification has expired (1 year validity)
    $expiry_date = $verification['expiry_date'] ?? null;
    $is_expired = false;

    if ($expiry_date) {
        $today = new DateTime();
        $expiry = new DateTime($expiry_date);
        if ($today > $expiry) {
            $is_expired = true;
        }
    }

    if ($is_expired) {
        // ✅ Log the expired verification
        logVerificationAction(
            $pdo,
            $user_id,
            $clinic_id,
            'expired',
            null,
            'Verification expired on ' . $expiry_date
        );

        return [
            'is_verified' => false,
            'verification_type' => $verification['verification_type'],
            'status' => 'expired',
            'expiry_date' => $expiry_date,
            'message' => 'Verification has expired on ' . date('F j, Y', strtotime($expiry_date))
        ];
    }

    return [
        'is_verified' => true,
        'verification_type' => $verification['verification_type'],
        'status' => 'verified',
        'verified_at' => $verification['verified_at'],
        'expiry_date' => $expiry_date,
        'message' => 'Verified ' . strtoupper($verification['verification_type']) . ' - Valid until ' . date('F j, Y', strtotime($expiry_date))
    ];
}

// ============================================
// ✅ LOG VERIFICATION ACTIONS
// ============================================
function logVerificationAction($pdo, $user_id, $clinic_id, $action, $admin_id = null, $notes = null) {
    $stmt = $pdo->prepare("
        INSERT INTO pwd_senior_verification_logs 
        (user_id, clinic_id, action, admin_id, notes, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$user_id, $clinic_id, $action, $admin_id, $notes]);
}

// ============================================
// ✅ buildBillFromAppointment - for appointment-based sales
// ============================================
function buildBillFromAppointment($pdo, $appointmentId, $clinic_id) {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date as sale_date,
            a.user_id,
            a.patient_id,
            a.subtotal,
            a.discount_type,
            a.discount_percentage,
            a.discount_amount,
            a.vat_percentage,
            a.vat_amount,
            a.total_amount,
            a.amount_paid,
            a.status,
            a.ref_no,
            a.id as appointment_id,
            CONCAT('INV-', DATE_FORMAT(a.appointment_date, '%Y%m'), '-', LPAD(a.id, 4, '0')) AS invoice_id,
            CASE
                WHEN a.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                ELSE 'Walk-in'
            END AS customer_name,
            CASE
                WHEN a.patient_id IS NOT NULL THEN p.id
                WHEN u.id IS NOT NULL THEN u.id
                ELSE 'WALK-IN'
            END AS customer_code,
            a.amount_paid as total_paid,
            d.name as doctor_name,
            a.ref_no as reference_number
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $stmt->execute([$appointmentId, $clinic_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        return null;
    }

    // ✅ Get payment method
    $paymentMethodStmt = $pdo->prepare("
        SELECT payment_method
        FROM payments
        WHERE appointment_id = ? AND clinic_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $paymentMethodStmt->execute([$appointmentId, $clinic_id]);
    $lastPaymentMethod = $paymentMethodStmt->fetchColumn();

    // ✅ Get items from appointment_services
    $servicesStmt = $pdo->prepare("
        SELECT s.name as item_name, aps.price as unit_price, 1 as quantity, 'service' as item_type
        FROM appointment_services aps
        JOIN services s ON aps.service_id = s.id
        WHERE aps.appointment_id = ?
    ");
    $servicesStmt->execute([$appointmentId]);

    $items = [];
    foreach ($servicesStmt->fetchAll(PDO::FETCH_ASSOC) as $service) {
        $items[] = [
            'item_name' => $service['item_name'],
            'item_type' => 'service',
            'unit_price' => floatval($service['unit_price']),
            'quantity' => intval($service['quantity']),
            'total_price' => floatval($service['unit_price']) * intval($service['quantity'])
        ];
    }

    $subtotal = floatval($appointment['subtotal'] ?? 0);
    $discountAmount = floatval($appointment['discount_amount'] ?? 0);
    $vatAmount = floatval($appointment['vat_amount'] ?? 0);
    $storedTotal = floatval($appointment['total_amount'] ?? 0);

    // ✅ Recompute total
    $computedTotal = $subtotal > 0 ? ($subtotal - $discountAmount + $vatAmount) : $storedTotal;
    $amountPaid = floatval($appointment['amount_paid'] ?? 0);

    // ✅ CORRECT status computation
    if ($amountPaid >= $computedTotal && $computedTotal > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $computedTotal) {
        $status_label = 'Partial';
    } else {
        $status_label = 'Unpaid';
    }

    return [
        'id' => $appointment['id'],
        'appointment_id' => $appointment['appointment_id'],
        'invoice_id' => $appointment['invoice_id'],
        'sale_date' => $appointment['sale_date'],
        'customer_name' => $appointment['customer_name'],
        'customer_code' => $appointment['customer_code'],
        'patient_id' => $appointment['patient_id'],
        'subtotal' => $subtotal,
        'discount_type' => $appointment['discount_type'],
        'discount_percentage' => floatval($appointment['discount_percentage'] ?? 0),
        'discount_amount' => $discountAmount,
        'vat_percentage' => floatval($appointment['vat_percentage'] ?? 0),
        'vat_amount' => $vatAmount,
        'total_amount' => $computedTotal,
        'amount_paid' => $amountPaid,
        'total_paid' => $amountPaid,
        'status' => $status_label,
        'payment_method' => $lastPaymentMethod ?: 'cash',
        'reference_number' => $appointment['reference_number'],
        'doctor_name' => $appointment['doctor_name'],
        'items' => $items,
        'walk_in_name' => null,
        'source_type' => 'appointment'
    ];
}

// ============================================
// ✅ buildBillFromSales - for walk-in sales
// ============================================
function buildBillFromSales($pdo, $saleId, $clinic_id) {
    $stmt = $pdo->prepare("
        SELECT s.*,
               CASE
                   WHEN s.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                   ELSE s.walk_in_name
               END as customer_name,
               CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
               (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id AND payment_status IN ('paid', 'completed')) as total_paid
        FROM sales s
        LEFT JOIN patients p ON s.patient_id = p.id
        WHERE s.id = ? AND s.clinic_id = ?
    ");
    $stmt->execute([$saleId, $clinic_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        return null;
    }

    // ✅ Get items from sale_items
    $itemsStmt = $pdo->prepare("
        SELECT item_name, item_type, unit_price, quantity, total_price
        FROM sale_items
        WHERE sale_id = ? AND clinic_id = ?
    ");
    $itemsStmt->execute([$saleId, $clinic_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAmount = floatval($sale['total_amount'] ?? 0);
    $amountPaid = floatval($sale['amount_paid'] ?? 0);

    // ✅ CORRECT status computation
    if ($amountPaid >= $totalAmount && $totalAmount > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $totalAmount) {
        $status_label = 'Partial';
    } else {
        $status_label = 'Unpaid';
    }

    return [
        'id' => $sale['id'],
        'appointment_id' => $sale['appointment_id'],
        'invoice_id' => $sale['invoice_id'],
        'sale_date' => $sale['sale_date'],
        'customer_name' => $sale['customer_name'] ?? $sale['walk_in_name'] ?? 'Walk-in',
        'customer_code' => $sale['patient_id'] ?? 'WALK-IN',
        'patient_id' => $sale['patient_id'],
        'subtotal' => floatval($sale['subtotal'] ?? 0),
        'discount_type' => 'none',
        'discount_percentage' => 0,
        'discount_amount' => floatval($sale['discount'] ?? 0),
        'vat_percentage' => 0,
        'vat_amount' => 0,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'total_paid' => $amountPaid,
        'status' => $status_label,
        'payment_method' => $sale['payment_method'] ?? 'cash',
        'reference_number' => $sale['ref_no'] ?? null,
        'doctor_name' => null,
        'items' => $items,
        'walk_in_name' => $sale['walk_in_name'],
        'walk_in_contact' => $sale['walk_in_contact'],
        'walk_in_email' => $sale['walk_in_email'],
        'source_type' => 'walkin'
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ============= GET PERMISSIONS ENDPOINT =============
    if ($method === 'GET' && isset($_GET['get_permissions'])) {
        $hasHR = false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'HR' AND status = 'Active'");
        $stmt->execute([$user_id]);
        $hasHR = $stmt->fetchColumn() > 0;

        $permissions = [
            'view' => SalesPermission::can('view'),
            'create' => SalesPermission::can('create'),
            'edit' => SalesPermission::can('edit'),
            'delete' => SalesPermission::can('delete'),
            'approve' => SalesPermission::can('approve'),
            'reject' => SalesPermission::can('reject'),
            'export' => SalesPermission::can('view')
        ];

        echo json_encode([
            'success' => true,
            'data' => [
                'role' => $user_role,
                'permissions' => $permissions,
                'hasHR' => $hasHR,
                'isOwner' => ($user_role === 'ClinicAdmin' && !$hasHR),
                'user_id' => $user_id,
                'user_name' => $user_name
            ]
        ]);
        exit;
    }

    // ============= GET SALES =============
    if ($method === 'GET') {
        SalesPermission::check('view');

        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            
            $bill = buildBillFromAppointment($pdo, $id, $clinic_id);
            
            if (!$bill) {
                $bill = buildBillFromSales($pdo, $id, $clinic_id);
            }

            if (!$bill) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }

            $paymentsStmt = $pdo->prepare("
                SELECT id, amount, payment_method, payment_status, payment_type,
                       reference_number, notes, payment_date, created_at
                FROM payments
                WHERE (sale_id = ? OR appointment_id = ?) AND clinic_id = ?
                ORDER BY payment_date DESC, created_at DESC
            ");
            $paymentsStmt->execute([$id, $id, $clinic_id]);
            $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

            $bill['payments'] = $payments;

            logAudit($pdo, $user_id, $clinic_id, 'VIEW', 'sales', $id);
            echo json_encode([
                'success' => true,
                'bill' => $bill,
                'balance' => max(0, $bill['total_amount'] - $bill['amount_paid'])
            ]);
            exit;
        }

        if (isset($_GET['export'])) {
            SalesPermission::check('view');

            $stmt = $pdo->prepare("
                SELECT 
                    a.id,
                    a.appointment_date as sale_date,
                    a.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) as customer_name,
                    a.subtotal,
                    a.discount_amount as discount,
                    a.vat_amount,
                    CASE 
                        WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
                        ELSE a.total_amount
                    END as total_amount,
                    a.amount_paid,
                    CASE 
                        WHEN a.amount_paid >= (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Paid'
                        WHEN a.amount_paid > 0 THEN 'Partial'
                        ELSE 'Unpaid'
                    END as status,
                    CONCAT('INV-', DATE_FORMAT(a.appointment_date, '%Y%m'), '-', LPAD(a.id, 4, '0')) AS invoice_id,
                    'appointment' as source_type
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                WHERE a.clinic_id = ? AND a.status IN ('paid', 'completed', 'confirmed', 'pending')
                
                UNION ALL
                
                SELECT 
                    s.id,
                    s.sale_date,
                    s.patient_id,
                    COALESCE(s.walk_in_name, CONCAT(p2.first_name, ' ', p2.last_name), 'Walk-in') as customer_name,
                    s.subtotal,
                    s.discount,
                    0 as vat_amount,
                    s.total_amount,
                    s.amount_paid,
                    CASE 
                        WHEN s.amount_paid >= s.total_amount THEN 'Paid'
                        WHEN s.amount_paid > 0 THEN 'Partial'
                        ELSE 'Unpaid'
                    END as status,
                    CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                    'walkin' as source_type
                FROM sales s
                LEFT JOIN patients p2 ON s.patient_id = p2.id
                WHERE s.clinic_id = ? AND s.appointment_id IS NULL
                ORDER BY sale_date DESC
            ");
            $stmt->execute([$clinic_id, $clinic_id]);
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

            logAudit($pdo, $user_id, $clinic_id, 'EXPORT', 'sales', null, null, ['count' => count($sales)]);
            echo json_encode($sales);
            exit;
        }

        if (isset($_GET['appointment_id'])) {
            $appointmentId = (int)$_GET['appointment_id'];

            $bill = buildBillFromAppointment($pdo, $appointmentId, $clinic_id);

            if (!$bill) {
                $saleStmt = $pdo->prepare("
                    SELECT id FROM sales WHERE appointment_id = ? AND clinic_id = ?
                ");
                $saleStmt->execute([$appointmentId, $clinic_id]);
                $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sale) {
                    $bill = buildBillFromSales($pdo, $sale['id'], $clinic_id);
                }
            }

            if ($bill === null) {
                echo json_encode(['success' => false, 'message' => 'Bill not found for this appointment']);
                exit;
            }

            logAudit($pdo, $user_id, $clinic_id, 'VIEW', 'appointments', $appointmentId);

            echo json_encode([
                'success' => true,
                'bill' => $bill,
                'balance' => max(0, $bill['total_amount'] - $bill['amount_paid'])
            ]);
            exit;
        }

        $search = $_GET['search'] ?? '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

        $sql = "
            SELECT 
                a.id,
                a.appointment_date as sale_date,
                a.patient_id,
                CONCAT(p.first_name, ' ', p.last_name) as customer_name,
                a.subtotal,
                a.discount_amount as discount,
                a.vat_amount,
                CASE 
                    WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
                    ELSE a.total_amount
                END as total_amount,
                a.amount_paid,
                CASE 
                    WHEN a.amount_paid >= (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Paid'
                    WHEN a.amount_paid > 0 THEN 'Partial'
                    ELSE 'Unpaid'
                END as payment_status,
                CONCAT('INV-', DATE_FORMAT(a.appointment_date, '%Y%m'), '-', LPAD(a.id, 4, '0')) AS invoice_id,
                'appointment' as source_type,
                NULL as walk_in_name
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            WHERE a.clinic_id = ?
            AND a.status IN ('paid', 'completed', 'confirmed', 'pending')
            
            UNION ALL
            
            SELECT 
                s.id,
                s.sale_date,
                s.patient_id,
                COALESCE(s.walk_in_name, CONCAT(p2.first_name, ' ', p2.last_name), 'Walk-in') as customer_name,
                s.subtotal,
                s.discount,
                0 as vat_amount,
                s.total_amount,
                s.amount_paid,
                CASE 
                    WHEN s.amount_paid >= s.total_amount THEN 'Paid'
                    WHEN s.amount_paid > 0 THEN 'Partial'
                    ELSE 'Unpaid'
                END as payment_status,
                CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
                'walkin' as source_type,
                s.walk_in_name
            FROM sales s
            LEFT JOIN patients p2 ON s.patient_id = p2.id
            WHERE s.clinic_id = ?
            AND s.appointment_id IS NULL
        ";

        $params = [$clinic_id, $clinic_id];

        if (!empty($search)) {
            $sql .= " AND (
                id LIKE ?
                OR invoice_id LIKE ?
                OR customer_name LIKE ?
                OR walk_in_name LIKE ?
                OR patient_id LIKE ?
            )";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        $sql .= " ORDER BY sale_date DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $invoices]);
        exit;
    }

// ============= CREATE SALE =============
if ($method === 'POST' && (!isset($_GET['action']) || $_GET['action'] === '')) {
    SalesPermission::check('create');

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['total_amount']) || $data['total_amount'] === '') {
        echo json_encode(['success' => false, 'message' => 'Total amount is required']);
        exit;
    }

    if (!isset($data['items']) || empty($data['items'])) {
        echo json_encode(['success' => false, 'message' => 'At least one item is required']);
        exit;
    }

    if ($data['customer_type'] === 'registered') {
        if (!isset($data['patient_id']) || empty($data['patient_id'])) {
            echo json_encode(['success' => false, 'message' => 'Please select a patient']);
            exit;
        }
    } else {
        if (!isset($data['walk_in_name']) || empty(trim($data['walk_in_name']))) {
            echo json_encode(['success' => false, 'message' => 'Please enter customer name for walk-in']);
            exit;
        }
    }

    try {
        $pdo->beginTransaction();

        $settings = getSystemSettings($pdo);
        $vat_rate = floatval($settings['vat_rate'] ?? 0.12);
        $pwd_senior_discount = floatval($settings['pwd_senior_discount'] ?? 0.20);
        $pwd_senior_vat_exempt = intval($settings['pwd_senior_vat_exempt'] ?? 1);

        $patient_id = null;
        $walk_in_name = null;
        $walk_in_contact = null;
        $walk_in_email = null;
        $user_id_for_verification = null;
        $is_pwd_senior = false;
        $discount_type = 'none';
        $discount_percentage = 0;
        $discount_amount = 0;
        $vat_percentage = 0;
        $vat_amount = 0;
        $subtotal = floatval($data['subtotal'] ?? 0);

        // ✅ CHECK IF CUSTOMER IS PWD/SENIOR
        if ($data['customer_type'] === 'registered') {
            $patient_id = $data['patient_id'];
            
            // ✅ Get user_id from patients table
            $userStmt = $pdo->prepare("SELECT user_id FROM patients WHERE id = ? AND clinic_id = ?");
            $userStmt->execute([$patient_id, $clinic_id]);
            $patientUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($patientUser && $patientUser['user_id']) {
                $user_id_for_verification = $patientUser['user_id'];
                $verification_result = checkPwdSeniorStatus($pdo, $user_id_for_verification, $clinic_id);
                
                if ($verification_result['is_verified']) {
                    $is_pwd_senior = true;
                    $discount_type = $verification_result['verification_type'] ?? 'pwd';
                    $discount_percentage = $pwd_senior_discount * 100;
                    
                    logVerificationAction(
                        $pdo, 
                        $user_id_for_verification, 
                        $clinic_id, 
                        'used_for_sale', 
                        $user_id,
                        'PWD/Senior discount applied to sale (Patient ID: ' . $patient_id . ')'
                    );
                }
            }
        } else {
            // ✅ WALK-IN: Check by phone or email if provided
            $walk_in_name = trim($data['walk_in_name']);
            $walk_in_contact = $data['walk_in_contact'] ?? null;
            $walk_in_email = $data['walk_in_email'] ?? null;
            
            $patient_found = false;
            
            // ✅ First try to find patient by phone or email
            $conditions = [];
            $params = [$clinic_id];
            
            if (!empty($walk_in_contact)) {
                $conditions[] = "phone = ?";
                $params[] = $walk_in_contact;
            }
            if (!empty($walk_in_email)) {
                $conditions[] = "email = ?";
                $params[] = $walk_in_email;
            }
            
            if (!empty($conditions)) {
                $whereClause = implode(' OR ', $conditions);
                $patientStmt = $pdo->prepare("
                    SELECT id, user_id FROM patients 
                    WHERE clinic_id = ? AND ($whereClause)
                    LIMIT 1
                ");
                $patientStmt->execute($params);
                $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($patient && $patient['user_id']) {
                    $patient_found = true;
                    $user_id_for_verification = $patient['user_id'];
                    $patient_id = $patient['id'];
                    
                    $verification_result = checkPwdSeniorStatus($pdo, $user_id_for_verification, $clinic_id);
                    
                    if ($verification_result['is_verified']) {
                        $is_pwd_senior = true;
                        $discount_type = $verification_result['verification_type'] ?? 'pwd';
                        $discount_percentage = $pwd_senior_discount * 100;
                        
                        logVerificationAction(
                            $pdo, 
                            $user_id_for_verification, 
                            $clinic_id, 
                            'used_for_sale_walkin', 
                            $user_id,
                            'PWD/Senior discount applied to walk-in sale by phone/email (Name: ' . $walk_in_name . ')'
                        );
                    }
                }
            }
            
            // ✅ If not found by phone/email, try by name (fallback for known patients)
            if (!$patient_found && !empty($walk_in_name)) {
                $verifyStmt = $pdo->prepare("
                    SELECT p.id as patient_id, p.user_id, v.verification_type, v.status
                    FROM patients p
                    JOIN user_verifications v ON p.user_id = v.user_id
                    WHERE CONCAT(p.first_name, ' ', p.last_name) = ? 
                    AND v.clinic_id = ? 
                    AND v.status = 'verified'
                    ORDER BY v.verified_at DESC
                    LIMIT 1
                ");
                $verifyStmt->execute([$walk_in_name, $clinic_id]);
                $verification = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($verification && $verification['status'] === 'verified') {
                    $user_id_for_verification = $verification['user_id'];
                    $patient_id = $verification['patient_id'];
                    
                    $verification_result = checkPwdSeniorStatus($pdo, $user_id_for_verification, $clinic_id);
                    
                    if ($verification_result['is_verified']) {
                        $is_pwd_senior = true;
                        $discount_type = $verification_result['verification_type'] ?? 'pwd';
                        $discount_percentage = $pwd_senior_discount * 100;
                        
                        logVerificationAction(
                            $pdo, 
                            $user_id_for_verification, 
                            $clinic_id, 
                            'used_for_sale_walkin', 
                            $user_id,
                            'PWD/Senior discount applied to walk-in sale by name (Name: ' . $walk_in_name . ')'
                        );
                    }
                }
            }
        }

        // ✅ APPLY DISCOUNT
        if ($is_pwd_senior) {
            $discount_amount = $subtotal * $pwd_senior_discount;
        }

        // ✅ COMPUTE VAT
        $amount_after_discount = $subtotal - $discount_amount;
        $is_vat_exempt = ($is_pwd_senior && $pwd_senior_vat_exempt == 1);
        
        if (!$is_vat_exempt) {
            $vat_percentage = $vat_rate * 100;
            $vat_amount = $amount_after_discount * $vat_rate;
        }

        $total_amount = $amount_after_discount + $vat_amount;
        $amount_paid = floatval($data['amount_paid'] ?? 0);

        if ($amount_paid >= $total_amount && $total_amount > 0) {
            $sale_status = 'Paid';
        } elseif ($amount_paid > 0 && $amount_paid < $total_amount) {
            $sale_status = 'Partial';
        } else {
            $sale_status = 'Unpaid';
        }

        // ✅ INSERT SALE
        $stmt = $pdo->prepare("
            INSERT INTO sales
            (clinic_id, sale_date, patient_id, appointment_id, walk_in_name, walk_in_contact, walk_in_email,
             items, subtotal, discount, vat_percentage, vat_amount, total_amount, amount_paid,
             payment_method, status, created_by, created_at)
            VALUES (?, CURDATE(), ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $clinic_id,
            $patient_id,
            $walk_in_name,
            $walk_in_contact,
            $walk_in_email,
            json_encode($data['items']),
            $subtotal,
            $discount_amount,
            $vat_percentage,
            $vat_amount,
            $total_amount,
            $amount_paid,
            $data['payment_method'] ?? 'cash',
            $sale_status,
            $user_id
        ]);

        $sale_id = $pdo->lastInsertId();

        // Insert sale items
        $items = $data['items'];
        $item_details = [];

        foreach ($items as $item) {
            $itemType = $item['type'] ?? 'product';

            $itemStmt = $pdo->prepare("
                INSERT INTO sale_items
                (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $itemStmt->execute([
                $sale_id,
                $clinic_id,
                $item['id'],
                $itemType,
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['price'] * $item['quantity']
            ]);

            if ($itemType === 'product') {
                $updateStmt = $pdo->prepare("
                    UPDATE inventory
                    SET stock = stock - ?,
                        item_status = CASE
                            WHEN (stock - ?) <= 0 THEN 'out-of-stock'
                            WHEN (stock - ?) <= min_stock THEN 'low-stock'
                            ELSE 'in-stock'
                        END,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ? AND stock >= ?
                ");
                $updateStmt->execute([
                    $item['quantity'],
                    $item['quantity'],
                    $item['quantity'],
                    $item['id'],
                    $clinic_id,
                    $item['quantity']
                ]);

                if ($updateStmt->rowCount() === 0 && $item['quantity'] > 0) {
                    throw new Exception("Insufficient stock for product: {$item['name']}");
                }
            }

            $item_details[] = [
                'name' => $item['name'],
                'type' => $itemType,
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ];
        }

        // Record payment if any
        if ($amount_paid > 0) {
            $payment_method = $data['payment_method'] ?? 'cash';
            $reference_number = $data['reference_number'] ?? null;
            $payment_type = $data['payment_type'] ?? ($amount_paid >= $total_amount ? 'full' : 'partial');
            $online_provider = in_array($payment_method, ['gcash', 'paymaya', 'credit_card', 'bank_transfer']) ? $payment_method : null;

            $paymentStmt = $pdo->prepare("
                INSERT INTO payments
                (sale_id, appointment_id, clinic_id, user_id, amount, payment_method, payment_status, payment_type,
                 reference_number, payment_reference, online_provider, payment_date, created_at)
                VALUES (?, NULL, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), NOW())
            ");
            $paymentStmt->execute([
                $sale_id,
                $clinic_id,
                $user_id,
                $amount_paid,
                $payment_method,
                $payment_type,
                $reference_number,
                $reference_number,
                $online_provider
            ]);
        }

        logAudit($pdo, $user_id, $clinic_id, 'CREATE', 'sales', $sale_id, null, [
            'customer_type' => $data['customer_type'],
            'subtotal' => $subtotal,
            'discount' => $discount_amount,
            'vat' => $vat_amount,
            'total_amount' => $total_amount,
            'is_pwd_senior' => $is_pwd_senior,
            'items' => $item_details
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sale created successfully',
            'sale_id' => $sale_id,
            'subtotal' => $subtotal,
            'discount' => $discount_amount,
            'vat' => $vat_amount,
            'total_amount' => $total_amount,
            'is_pwd_senior' => $is_pwd_senior,
            'discount_type' => $discount_type,
            'status' => $sale_status
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

    // ============= UPDATE SALE =============
    if ($method === 'PUT') {
        SalesPermission::check('edit');

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['id']) || empty($data['id'])) {
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit;
        }

        $sale_id = $data['id'];

        try {
            $oldStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$sale_id, $clinic_id]);
            $old_sale = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$old_sale) {
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }

            $pdo->beginTransaction();

            $updateFields = [];
            $params = [];

            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['payment_method'])) {
                $updateFields[] = "payment_method = ?";
                $params[] = $data['payment_method'];
            }
            if (isset($data['notes'])) {
                $updateFields[] = "notes = ?";
                $params[] = $data['notes'];
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $params[] = $sale_id;
                $params[] = $clinic_id;

                $updateSql = "UPDATE sales SET " . implode(", ", $updateFields) . " WHERE id = ? AND clinic_id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($params);
            }

            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'sales', $sale_id, $old_sale, $data);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Sale updated successfully']);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ============= DELETE SALE =============
    if ($method === 'DELETE') {
        SalesPermission::check('delete');

        $data = json_decode(file_get_contents('php://input'), true);
        $sale_id = $data['id'] ?? ($_GET['id'] ?? 0);

        if (!$sale_id) {
            echo json_encode(['success' => false, 'message' => 'Sale ID is required']);
            exit;
        }

        try {
            $checkStmt = $pdo->prepare("SELECT status, total_amount, amount_paid FROM sales WHERE id = ? AND clinic_id = ?");
            $checkStmt->execute([$sale_id, $clinic_id]);
            $sale = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Sale not found']);
                exit;
            }

            if ($sale['status'] === 'Paid' && $sale['amount_paid'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete a paid sale']);
                exit;
            }

            $oldStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$sale_id, $clinic_id]);
            $old_sale = $oldStmt->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            $deleteItemsStmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ? AND clinic_id = ?");
            $deleteItemsStmt->execute([$sale_id, $clinic_id]);

            $deletePaymentsStmt = $pdo->prepare("DELETE FROM payments WHERE sale_id = ? AND clinic_id = ?");
            $deletePaymentsStmt->execute([$sale_id, $clinic_id]);

            $deleteStmt = $pdo->prepare("DELETE FROM sales WHERE id = ? AND clinic_id = ?");
            $deleteStmt->execute([$sale_id, $clinic_id]);

            logAudit($pdo, $user_id, $clinic_id, 'DELETE', 'sales', $sale_id, $old_sale, null);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Sale deleted successfully']);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ============= RECORD PAYMENT =============
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'record_payment') {
        SalesPermission::check('edit');

        $data = json_decode(file_get_contents('php://input'), true);
        $appointmentId = (int)($data['appointment_id'] ?? 0);
        $amount = floatval($data['amount'] ?? 0);
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $referenceNumber = $data['reference_number'] ?? null;
        $notes = $data['notes'] ?? null;
        $sourceType = $data['source_type'] ?? 'appointment';

        if (!$appointmentId || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($sourceType === 'walkin') {
                $stmt = $pdo->prepare("
                    SELECT s.*
                    FROM sales s
                    WHERE s.id = ? AND s.clinic_id = ?
                ");
                $stmt->execute([$appointmentId, $clinic_id]);
                $sale = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sale) {
                    throw new Exception('Sale not found');
                }

                $saleId = $sale['id'];
                $totalAmount = floatval($sale['total_amount'] ?? 0);
                $currentPaid = floatval($sale['amount_paid'] ?? 0);
                $newAmountPaid = $currentPaid + $amount;
                $isFullyPaid = $newAmountPaid >= $totalAmount;

                $saleStatus = $isFullyPaid ? 'Paid' : 'Partial';
                $paymentType = $isFullyPaid ? 'full' : 'partial';

                $pdo->prepare("
                    UPDATE sales
                    SET amount_paid = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ")->execute([$newAmountPaid, $saleStatus, $saleId, $clinic_id]);

                $paymentStmt = $pdo->prepare("
                    INSERT INTO payments
                    (sale_id, appointment_id, clinic_id, user_id, amount, payment_method,
                     payment_status, payment_type, reference_number, notes, payment_date, created_at)
                    VALUES (?, 0, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW(), NOW())
                ");
                $paymentStmt->execute([
                    $saleId,
                    $clinic_id,
                    $user_id,
                    $amount,
                    $paymentMethod,
                    $paymentType,
                    $referenceNumber,
                    $notes
                ]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'sale_id' => $saleId,
                    'sale_status' => $saleStatus,
                    'amount_paid' => $newAmountPaid,
                    'balance' => max(0, $totalAmount - $newAmountPaid),
                    'fully_paid' => $isFullyPaid,
                    'message' => $isFullyPaid ? 'Payment completed. Sale is now fully paid.' : 'Partial payment recorded'
                ]);

            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        a.id,
                        a.patient_id,
                        a.subtotal,
                        a.discount_amount,
                        a.vat_amount,
                        a.total_amount as stored_total,
                        a.amount_paid,
                        a.status as appt_status,
                        CASE 
                            WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
                            ELSE a.total_amount
                        END as computed_total,
                        s.id as sale_id,
                        s.status as sale_status,
                        s.total_amount as sale_total
                    FROM appointments a
                    LEFT JOIN sales s ON a.id = s.appointment_id AND s.clinic_id = a.clinic_id
                    WHERE a.id = ? AND a.clinic_id = ?
                ");
                $stmt->execute([$appointmentId, $clinic_id]);
                $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$appointment) {
                    throw new Exception('Appointment not found');
                }

                $totalAmount = $appointment['computed_total'] > 0 ? $appointment['computed_total'] : $appointment['stored_total'];
                $currentPaid = floatval($appointment['amount_paid'] ?? 0);
                $newAmountPaid = $currentPaid + $amount;
                $isFullyPaid = $newAmountPaid >= $totalAmount;

                $saleStatus = $isFullyPaid ? 'Paid' : 'Partial';
                $appointmentStatus = $isFullyPaid ? 'completed' : 'waiting_payment';
                $paymentType = $isFullyPaid ? 'full' : 'partial';

                $pdo->prepare("
                    UPDATE appointments
                    SET amount_paid = ?,
                        payment_status = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ")->execute([
                    $newAmountPaid,
                    $isFullyPaid ? 'paid' : 'partial',
                    $appointmentStatus,
                    $appointmentId,
                    $clinic_id
                ]);

                $saleId = $appointment['sale_id'];
                
                if ($saleId) {
                    $pdo->prepare("
                        UPDATE sales
                        SET amount_paid = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ? AND clinic_id = ?
                    ")->execute([$newAmountPaid, $saleStatus, $saleId, $clinic_id]);
                } else {
                    $saleStmt = $pdo->prepare("
                        INSERT INTO sales
                        (clinic_id, sale_date, patient_id, appointment_id, 
                         items, subtotal, discount, total_amount, amount_paid,
                         payment_method, status, created_by, created_at)
                        VALUES (?, CURDATE(), ?, ?, 
                                NULL, ?, ?, ?, ?,
                                ?, ?, ?, NOW())
                    ");
                    $saleStmt->execute([
                        $clinic_id,
                        $appointment['patient_id'],
                        $appointmentId,
                        $appointment['subtotal'] ?? 0,
                        $appointment['discount_amount'] ?? 0,
                        $totalAmount,
                        $newAmountPaid,
                        $paymentMethod,
                        $saleStatus,
                        $user_id
                    ]);
                    $saleId = $pdo->lastInsertId();

                    $itemsStmt = $pdo->prepare("
                        INSERT INTO sale_items (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                        SELECT 
                            ? as sale_id,
                            aps.clinic_id,
                            aps.service_id as item_id,
                            'service' as item_type,
                            s.name as item_name,
                            1 as quantity,
                            aps.price as unit_price,
                            aps.price as total_price
                        FROM appointment_services aps
                        JOIN services s ON aps.service_id = s.id
                        WHERE aps.appointment_id = ?
                    ");
                    $itemsStmt->execute([$saleId, $appointmentId]);
                }

                $paymentStmt = $pdo->prepare("
                    INSERT INTO payments
                    (sale_id, appointment_id, clinic_id, user_id, amount, payment_method,
                     payment_status, payment_type, reference_number, notes, payment_date, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW(), NOW())
                ");
                $paymentStmt->execute([
                    $saleId,
                    $appointmentId,
                    $clinic_id,
                    $user_id,
                    $amount,
                    $paymentMethod,
                    $paymentType,
                    $referenceNumber,
                    $notes
                ]);

                if ($isFullyPaid) {
                    $itemsStmt = $pdo->prepare("
                        SELECT item_id, item_type, quantity
                        FROM sale_items
                        WHERE sale_id = ? AND clinic_id = ?
                    ");
                    $itemsStmt->execute([$saleId, $clinic_id]);
                    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($items as $item) {
                        if ($item['item_type'] === 'product') {
                            $updateStmt = $pdo->prepare("
                                UPDATE inventory
                                SET stock = stock - ?,
                                    item_status = CASE
                                        WHEN (stock - ?) <= 0 THEN 'out-of-stock'
                                        WHEN (stock - ?) <= min_stock THEN 'low-stock'
                                        ELSE 'in-stock'
                                    END,
                                    updated_at = NOW()
                                WHERE id = ? AND clinic_id = ? AND stock >= ?
                            ");
                            $updateStmt->execute([
                                $item['quantity'],
                                $item['quantity'],
                                $item['quantity'],
                                $item['item_id'],
                                $clinic_id,
                                $item['quantity']
                            ]);
                        }
                    }

                    $pdo->prepare("
                        UPDATE appointments
                        SET inventory_deducted = 1, 
                            inventory_deducted_at = NOW()
                        WHERE id = ? AND clinic_id = ?
                    ")->execute([$appointmentId, $clinic_id]);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'sale_id' => $saleId,
                    'sale_status' => $saleStatus,
                    'appointment_status' => $appointmentStatus,
                    'amount_paid' => $newAmountPaid,
                    'balance' => max(0, $totalAmount - $newAmountPaid),
                    'fully_paid' => $isFullyPaid,
                    'message' => $isFullyPaid ? 'Payment completed. Appointment is now completed and inventory updated.' : 'Partial payment recorded'
                ]);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
// ============= CHECK PWD/SENIOR STATUS =============
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_pwd_senior') {
    $patient_id = (int)($_GET['patient_id'] ?? 0);
    $clinic_id = (int)($_GET['clinic_id'] ?? 0);
    
    if (!$patient_id || !$clinic_id) {
        echo json_encode(['success' => false, 'message' => 'Missing patient_id or clinic_id']);
        exit;
    }
    
    // ✅ Get user_id from patients table
    $userStmt = $pdo->prepare("SELECT id, first_name, last_name, user_id FROM patients WHERE id = ? AND clinic_id = ?");
    $userStmt->execute([$patient_id, $clinic_id]);
    $patientUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patientUser) {
        echo json_encode(['success' => false, 'is_pwd_senior' => false, 'message' => 'Patient not found']);
        exit;
    }
    
    // ✅ Check if patient has user_id
    if (!$patientUser['user_id']) {
        echo json_encode([
            'success' => false, 
            'is_pwd_senior' => false, 
            'message' => 'Patient is not linked to a user account. Please contact clinic admin.',
            'debug' => [
                'patient_id' => $patient_id,
                'patient_name' => $patientUser['first_name'] . ' ' . $patientUser['last_name'],
                'user_id' => null
            ]
        ]);
        exit;
    }
    
    $user_id = $patientUser['user_id'];
    $verification_result = checkPwdSeniorStatus($pdo, $user_id, $clinic_id);
    
    echo json_encode([
        'success' => true,
        'is_pwd_senior' => $verification_result['is_verified'],
        'verification_type' => $verification_result['verification_type'],
        'status' => $verification_result['status'],
        'expiry_date' => $verification_result['expiry_date'] ?? null,
        'message' => $verification_result['message'],
        'discount_percentage' => $verification_result['is_verified'] ? 20 : 0,
        'debug' => [
            'patient_id' => $patient_id,
            'user_id' => $user_id,
            'verification_status' => $verification_result['status']
        ]
    ]);
    exit;
}

    // ============= CHECK PWD/SENIOR STATUS BY NAME (for walk-in) =============
    if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_pwd_senior_by_name') {
        $name = $_GET['name'] ?? '';
        $clinic_id = (int)($_GET['clinic_id'] ?? 0);
        
        if (empty($name) || !$clinic_id) {
            echo json_encode(['success' => false, 'message' => 'Missing name or clinic_id']);
            exit;
        }
        
        $patientStmt = $pdo->prepare("
            SELECT id, user_id FROM patients 
            WHERE CONCAT(first_name, ' ', last_name) = ? 
            LIMIT 1
        ");
        $patientStmt->execute([$name]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            echo json_encode(['success' => true, 'is_pwd_senior' => false, 'message' => 'Patient not found']);
            exit;
        }
        
        $verification_result = checkPwdSeniorStatus($pdo, $patient['user_id'], $clinic_id);
        
        echo json_encode([
            'success' => true,
            'is_pwd_senior' => $verification_result['is_verified'],
            'verification_type' => $verification_result['verification_type'],
            'status' => $verification_result['status'],
            'expiry_date' => $verification_result['expiry_date'] ?? null,
            'message' => $verification_result['message'],
            'discount_percentage' => $verification_result['is_verified'] ? 20 : 0
        ]);
        exit;
    }

    // ============= COMPLETE SERVICE =============
    if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'complete_service') {
        SalesPermission::check('edit');

        $data = json_decode(file_get_contents('php://input'), true);
        $appointmentId = (int)($data['appointment_id'] ?? 0);

        if (!$appointmentId) {
            echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT 
                    a.status as appt_status,
                    a.amount_paid,
                    CASE 
                        WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
                        ELSE a.total_amount
                    END as computed_total,
                    s.id as sale_id,
                    s.status as sale_status
                FROM appointments a
                LEFT JOIN sales s ON a.id = s.appointment_id
                WHERE a.id = ? AND a.clinic_id = ?
            ");
            $stmt->execute([$appointmentId, $clinic_id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                throw new Exception('Appointment not found');
            }

            $isFullyPaid = floatval($record['amount_paid']) >= floatval($record['computed_total']);

            if (!$isFullyPaid && $record['appt_status'] !== 'paid') {
                echo json_encode(['success' => false, 'message' => 'Payment not yet completed. Cannot complete service.']);
                exit;
            }

            $pdo->prepare("
                UPDATE appointments
                SET status = 'completed', completed_at = NOW(), updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ")->execute([$appointmentId, $clinic_id]);

            if ($record['sale_id']) {
                $pdo->prepare("
                    UPDATE sales
                    SET status = 'completed', updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ")->execute([$record['sale_id'], $clinic_id]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Service completed successfully',
                'appointment_status' => 'completed'
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}