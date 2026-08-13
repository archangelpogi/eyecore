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

// Check session - allow AJAX requests
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    // ✅ Allow if it's an AJAX request with proper headers
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        // Try to get session from cookie
        if (isset($_COOKIE['PHPSESSID'])) {
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id'])) {
                // Session restored, continue
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ============================================
// ✅ REUSABLE SQL FRAGMENT: always prefer the stored a.total_amount;
// only fall back to the computed (subtotal - discount + vat) formula
// when total_amount is NULL or 0. This matches what's actually in the DB.
// ============================================
define('TOTAL_AMOUNT_SQL', "COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)))");

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
    // ✅ SIMPLIFIED: Same as booking process - direktang check sa user_verifications
    $stmt = $pdo->prepare("
        SELECT 
            status, 
            verification_type, 
            verified_at
        FROM user_verifications 
        WHERE user_id = ? 
        AND clinic_id = ? 
        AND status = 'verified'
        ORDER BY verified_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $clinic_id]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    // ✅ If no verification found, return not verified
    if (!$verification) {
        return [
            'is_verified' => false,
            'verification_type' => null,
            'status' => 'none',
            'verified_at' => null,
            'message' => 'No verification found'
        ];
    }

    // ✅ If verified, return the verification data
    return [
        'is_verified' => true,
        'verification_type' => $verification['verification_type'],
        'status' => 'verified',
        'verified_at' => $verification['verified_at'],
        'message' => 'Verified ' . strtoupper($verification['verification_type'])
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
// ✅ FIXED: buildBillFromAppointment - with proper items priority
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
            a.ref_no as reference_number,
            a.items as appointment_items
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

    // ============================================
    // ✅ FIXED: Get items with priority order
    // ============================================
    $items = [];
    
    // 1. FIRST PRIORITY: sales.items (most complete - has frame + lens + services)
    $salesItemsStmt = $pdo->prepare("
        SELECT items FROM sales 
        WHERE appointment_id = ? AND clinic_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $salesItemsStmt->execute([$appointmentId, $clinic_id]);
    $salesItems = $salesItemsStmt->fetchColumn();
    
    if ($salesItems) {
        $decoded = json_decode($salesItems, true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $it) {
                $price = floatval($it['price'] ?? 0);
                $qty = intval($it['quantity'] ?? 1);
                $items[] = [
                    'item_name' => $it['name'] ?? 'Item',
                    'item_type' => $it['type'] ?? 'product',
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'total_price' => $price * $qty
                ];
            }
        }
    }
    
    // 2. SECOND PRIORITY: appointments.items (has frame + lens)
    if (empty($items) && !empty($appointment['appointment_items'])) {
        $decoded = json_decode($appointment['appointment_items'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $it) {
                $price = floatval($it['price'] ?? 0);
                $qty = intval($it['quantity'] ?? 1);
                $items[] = [
                    'item_name' => $it['name'] ?? 'Item',
                    'item_type' => $it['type'] ?? 'product',
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'total_price' => $price * $qty
                ];
            }
        }
    }
    
    // 3. THIRD PRIORITY: appointment_services + product (fallback)
    if (empty($items)) {
        // Get services from appointment_services
        $servicesStmt = $pdo->prepare("
            SELECT s.name as item_name, aps.price as unit_price, 1 as quantity, 'service' as item_type
            FROM appointment_services aps
            JOIN services s ON aps.service_id = s.id
            WHERE aps.appointment_id = ?
        ");
        $servicesStmt->execute([$appointmentId]);
        foreach ($servicesStmt->fetchAll(PDO::FETCH_ASSOC) as $service) {
            $items[] = [
                'item_name' => $service['item_name'],
                'item_type' => 'service',
                'unit_price' => floatval($service['unit_price']),
                'quantity' => intval($service['quantity']),
                'total_price' => floatval($service['unit_price']) * intval($service['quantity'])
            ];
        }
        
        // Get product (frame) from appointments
        $productStmt = $pdo->prepare("
            SELECT pr.name as item_name, pr.price as unit_price, 1 as quantity, 'product' as item_type
            FROM appointments a
            JOIN products pr ON a.product_id = pr.id
            WHERE a.id = ? AND a.product_id IS NOT NULL AND a.product_id > 0
        ");
        $productStmt->execute([$appointmentId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if ($product) {
            $items[] = [
                'item_name' => $product['item_name'],
                'item_type' => 'product',
                'unit_price' => floatval($product['unit_price']),
                'quantity' => intval($product['quantity']),
                'total_price' => floatval($product['unit_price']) * intval($product['quantity'])
            ];
        }
    }

    // ✅ Use stored values from database
    $subtotal = floatval($appointment['subtotal'] ?? 0);
    $discountAmount = floatval($appointment['discount_amount'] ?? 0);
    $vatAmount = floatval($appointment['vat_amount'] ?? 0);
    $storedTotal = floatval($appointment['total_amount'] ?? 0);

    // ✅ FIXED: Trust the stored total_amount first
    $finalTotal = $storedTotal > 0 ? $storedTotal : ($subtotal - $discountAmount + $vatAmount);
    $amountPaid = floatval($appointment['amount_paid'] ?? 0);

    // ✅ CORRECT status computation
    if ($amountPaid >= $finalTotal && $finalTotal > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $finalTotal) {
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
        'discount_type' => $appointment['discount_type'] ?? 'none',
        'discount_percentage' => floatval($appointment['discount_percentage'] ?? 0),
        'discount_amount' => $discountAmount,
        'vat_percentage' => floatval($appointment['vat_percentage'] ?? 0),
        'vat_amount' => $vatAmount,
        'total_amount' => $finalTotal,
        'amount_paid' => $amountPaid,
        'total_paid' => $amountPaid,
        'status' => $status_label,
        'payment_method' => $lastPaymentMethod ?: 'cash',
        'reference_number' => $appointment['reference_number'],
        'doctor_name' => $appointment['doctor_name'],
        'items' => $items,
        'walk_in_name' => null,
        'source_type' => 'appointment',
        // ✅ PHASE 5: Add manual discount info
        'verified_by' => $appointment['verified_by'] ?? null,
        'id_number' => $appointment['id_number'] ?? null,
        'is_manual_discount' => ($appointment['discount_type'] ?? '') === 'manual',
        'is_verified_discount' => ($appointment['discount_type'] ?? '') !== 'none' && ($appointment['discount_type'] ?? '') !== 'manual'
    ];
}

// ============================================
// ✅ FIXED: buildBillFromSales - with proper items handling
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

    // ============================================
    // ✅ FIXED: Get items with priority order
    // ============================================
    $items = [];
    
    // 1. FIRST PRIORITY: sale_items (normalized table from POS)
    $itemsStmt = $pdo->prepare("
        SELECT item_name, item_type, unit_price, quantity, total_price
        FROM sale_items
        WHERE sale_id = ? AND clinic_id = ?
    ");
    $itemsStmt->execute([$saleId, $clinic_id]);
    $saleItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($saleItems)) {
        foreach ($saleItems as $item) {
            $items[] = [
                'item_name' => $item['item_name'],
                'item_type' => $item['item_type'] ?? 'product',
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'quantity' => intval($item['quantity'] ?? 1),
                'total_price' => floatval($item['total_price'] ?? 0)
            ];
        }
    }
    
    // 2. SECOND PRIORITY: sales.items JSON (from appointments trigger)
    if (empty($items) && !empty($sale['items'])) {
        $decoded = json_decode($sale['items'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $it) {
                $price = floatval($it['price'] ?? 0);
                $qty = intval($it['quantity'] ?? 1);
                $items[] = [
                    'item_name' => $it['name'] ?? 'Item',
                    'item_type' => $it['type'] ?? 'product',
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'total_price' => $price * $qty
                ];
            }
        }
    }
    
    // 3. THIRD PRIORITY: Single item from sale (fallback)
    if (empty($items) && !empty($sale['item_name'])) {
        $items[] = [
            'item_name' => $sale['item_name'],
            'item_type' => $sale['item_type'] ?? 'product',
            'unit_price' => floatval($sale['unit_price'] ?? 0),
            'quantity' => intval($sale['quantity'] ?? 1),
            'total_price' => floatval($sale['total_price'] ?? $sale['total_amount'] ?? 0)
        ];
    }

    // ✅ Use stored values from database
    $totalAmount = floatval($sale['total_amount'] ?? 0);
    $amountPaid = floatval($sale['amount_paid'] ?? 0);
    $subtotal = floatval($sale['subtotal'] ?? 0);
    $discountAmount = floatval($sale['discount'] ?? 0);
    $vatAmount = floatval($sale['vat_amount'] ?? 0);
    $vatPercentage = floatval($sale['vat_percentage'] ?? 0);

    // ✅ If subtotal is 0 but we have items, compute from items
    if ($subtotal == 0 && !empty($items)) {
        $subtotal = array_sum(array_column($items, 'total_price'));
    }

    // ✅ CORRECT status computation
    if ($amountPaid >= $totalAmount && $totalAmount > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $totalAmount) {
        $status_label = 'Partial';
    } else {
        $status_label = 'Unpaid';
    }

    // ✅ Determine discount type
    $discountType = $sale['discount_type'] ?? 'none';
    $discountPercentage = floatval($sale['discount_percentage'] ?? 0);
    
    // If discount amount exists but type is none, set to 'manual'
    if ($discountAmount > 0 && $discountType === 'none') {
        $discountType = 'manual';
    }

    return [
        'id' => $sale['id'],
        'appointment_id' => $sale['appointment_id'] ?? null,
        'invoice_id' => $sale['invoice_id'],
        'sale_date' => $sale['sale_date'],
        'customer_name' => $sale['customer_name'] ?? $sale['walk_in_name'] ?? 'Walk-in',
        'customer_code' => $sale['patient_id'] ?? 'WALK-IN',
        'patient_id' => $sale['patient_id'],
        'subtotal' => $subtotal,
        'discount_type' => $discountType,
        'discount_percentage' => $discountPercentage,
        'discount_amount' => $discountAmount,
        'vat_percentage' => $vatPercentage,
        'vat_amount' => $vatAmount,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'total_paid' => floatval($sale['total_paid'] ?? $amountPaid),
        'status' => $status_label,
        'payment_method' => $sale['payment_method'] ?? 'cash',
        'reference_number' => $sale['ref_no'] ?? null,
        'doctor_name' => null,
        'items' => $items,
        'walk_in_name' => $sale['walk_in_name'] ?? null,
        'walk_in_contact' => $sale['walk_in_contact'] ?? null,
        'walk_in_email' => $sale['walk_in_email'] ?? null,
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
    
    // ============================================
    // ✅ PHASE 4: CHECK APPOINTMENT VERIFICATION (PINAKA-NAUNA)
    // ============================================
    if (isset($_GET['action']) && $_GET['action'] === 'check_appointment_verification') {
        $appointmentId = (int)($_GET['appointment_id'] ?? 0);
        $clinic_id = (int)($_GET['clinic_id'] ?? 0);
        
        if (!$appointmentId || !$clinic_id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT a.patient_id, a.user_id, a.discount_type, a.discount_percentage
            FROM appointments a
            WHERE a.id = ? AND a.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            exit;
        }
        
        $is_verified = !empty($appointment['discount_type']) && $appointment['discount_type'] !== 'none';
        
        if (!$is_verified && $appointment['user_id']) {
            $verification_result = checkPwdSeniorStatus($pdo, $appointment['user_id'], $clinic_id);
            $is_verified = $verification_result['is_verified'];
        }
        
        echo json_encode([
            'success' => true,
            'is_verified' => $is_verified,
            'appointment_id' => $appointmentId,
            'discount_type' => $appointment['discount_type'] ?? 'none',
            'discount_percentage' => $appointment['discount_percentage'] ?? 0
        ]);
        exit;
    }
    
    // ============================================
    // ✅ CHECK PWD/SENIOR STATUS (PANGALAWA)
    // ============================================
    if (isset($_GET['action']) && $_GET['action'] === 'check_pwd_senior') {
        $patient_id = (int)($_GET['patient_id'] ?? 0);
        $clinic_id = (int)($_GET['clinic_id'] ?? 0);
        
        if (!$patient_id || !$clinic_id) {
            echo json_encode(['success' => false, 'message' => 'Missing patient_id or clinic_id']);
            exit;
        }
        
        $userStmt = $pdo->prepare("
            SELECT id, first_name, last_name, user_id 
            FROM patients 
            WHERE id = ? AND clinic_id = ?
        ");
        $userStmt->execute([$patient_id, $clinic_id]);
        $patientUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patientUser) {
            echo json_encode(['success' => false, 'is_pwd_senior' => false, 'message' => 'Patient not found']);
            exit;
        }
        
        if (!$patientUser['user_id']) {
            echo json_encode([
                'success' => true, 
                'is_pwd_senior' => false, 
                'message' => 'Patient is not linked to a user account'
            ]);
            exit;
        }
        
        $verification_result = checkPwdSeniorStatus($pdo, $patientUser['user_id'], $clinic_id);
        
        echo json_encode([
            'success' => true,
            'is_pwd_senior' => $verification_result['is_verified'],
            'verification_type' => $verification_result['verification_type'],
            'status' => $verification_result['status'],
            'message' => $verification_result['message'],
            'discount_percentage' => $verification_result['is_verified'] ? 20 : 0
        ]);
        exit;
    }
    
    // ============================================
    // ✅ CHECK PWD/SENIOR BY NAME (WALK-IN) (PANGATLO)
    // ============================================
    if (isset($_GET['action']) && $_GET['action'] === 'check_pwd_senior_by_name') {
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
            'message' => $verification_result['message'],
            'discount_percentage' => $verification_result['is_verified'] ? 20 : 0
        ]);
        exit;
    }
    
    // ============================================
    // ✅ GET PERMISSIONS (PANG-APAT)
    // ============================================
    if (isset($_GET['action']) && $_GET['action'] === 'get_permissions') {
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
    
    // ============================================
    // ✅ VIEW INVOICE BY ID (PANG-LIMA)
    // ============================================
    if (isset($_GET['id'])) {
        SalesPermission::check('view');
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
    
    // ============================================
    // ✅ EXPORT (PANG-ANIM)
    // ============================================
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
                " . TOTAL_AMOUNT_SQL . " as total_amount,
                a.amount_paid,
                CASE 
                    WHEN a.amount_paid >= (" . TOTAL_AMOUNT_SQL . ") THEN 'Paid'
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
    
    // ============================================
    // ✅ VIEW INVOICE BY APPOINTMENT ID (PANG-PITO)
    // ============================================
    if (isset($_GET['appointment_id'])) {
        SalesPermission::check('view');
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
    
    // ============================================
    // ✅ LIST ALL INVOICES (PINAKA-HULI - DEFAULT)
    // ============================================
    SalesPermission::check('view');

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
            " . TOTAL_AMOUNT_SQL . " as total_amount,
            a.amount_paid,
            CASE 
                WHEN a.amount_paid >= (" . TOTAL_AMOUNT_SQL . ") THEN 'Paid'
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
        $is_vat_exempt = false;
        $discount_type_final = 'none';
        $discount_percentage_final = 0;
        $discount_amount = 0;
        $vat_percentage = 0;
        $vat_amount = 0;
        $subtotal = floatval($data['subtotal'] ?? 0);
        
        // ✅ PHASE 2 & 6: Get manual discount data with type
        $manual_discount = isset($data['manual_discount']) ? floatval($data['manual_discount']) : 0;
        $manual_discount_type = isset($data['manual_discount_type']) ? $data['manual_discount_type'] : 'senior';
        $verified_by = $data['verified_by'] ?? null;
        $id_number = $data['id_number'] ?? null;

        // ✅ PHASE 6: Get walk-in manual discount with type
        $walkin_manual_discount = isset($data['walkin_manual_discount']) ? floatval($data['walkin_manual_discount']) : 0;
        $walkin_manual_discount_type = isset($data['walkin_manual_discount_type']) ? $data['walkin_manual_discount_type'] : 'senior';
        $walkin_verified_by = $data['walkin_verified_by'] ?? null;
        $walkin_id_number = $data['walkin_id_number'] ?? null;

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
                    $discount_type_final = $verification_result['verification_type'] ?? 'senior';
                    $discount_percentage_final = $pwd_senior_discount * 100;
                    
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
                        $discount_type_final = $verification_result['verification_type'] ?? 'senior';
                        $discount_percentage_final = $pwd_senior_discount * 100;
                        
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
                        $discount_type_final = $verification_result['verification_type'] ?? 'senior';
                        $discount_percentage_final = $pwd_senior_discount * 100;
                        
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

        // ============================================
        // ✅ PHASE 1, 2 & 6: APPLY DISCOUNT
        // ============================================
        
        // ✅ Check if customer is verified PWD/Senior
        if ($is_pwd_senior) {
            // ✅ Auto discount for verified patients (NO manual override)
            $discount_amount = $subtotal * $pwd_senior_discount;
            $discount_type_final = $discount_type_final ?: 'senior';
            $discount_percentage_final = $pwd_senior_discount * 100;
            $is_vat_exempt = true;
            $manual_discount = 0; // Reset manual discount
        } 
        // ✅ PHASE 2 & 6: Manual discount for registered unverified patients
        elseif ($manual_discount > 0) {
            $discount_amount = $subtotal * ($manual_discount / 100);
            $discount_type_final = $manual_discount_type;
            $discount_percentage_final = $manual_discount;
            $is_vat_exempt = true;
        } 
        // ✅ PHASE 3 & 6: Manual discount for walk-in
        elseif ($walkin_manual_discount > 0) {
            $discount_amount = $subtotal * ($walkin_manual_discount / 100);
            $discount_type_final = $walkin_manual_discount_type;
            $discount_percentage_final = $walkin_manual_discount;
            $is_vat_exempt = true;
            $verified_by = $walkin_verified_by;
            $id_number = $walkin_id_number;
        } 
        // ✅ No discount
        else {
            $discount_amount = 0;
            $discount_type_final = 'none';
            $discount_percentage_final = 0;
            $is_vat_exempt = false;
        }

        // ============================================
        // ✅ COMPUTE VAT
        // ============================================
        $amount_after_discount = $subtotal - $discount_amount;
        
        // ✅ If PWD/Senior, VAT exempt per law
        if ($is_pwd_senior && $pwd_senior_vat_exempt == 1) {
            $vat_percentage = 0;
            $vat_amount = 0;
            $is_vat_exempt = true;
        } 
        // ✅ If manual discount, VAT exempt
        elseif ($manual_discount > 0 || $walkin_manual_discount > 0) {
            $vat_percentage = 0;
            $vat_amount = 0;
            $is_vat_exempt = true;
        } 
        // ✅ Regular customer - with VAT
        else {
            $vat_percentage = $vat_rate * 100;
            $vat_amount = $amount_after_discount * $vat_rate;
            $is_vat_exempt = false;
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

        // ============================================
        // ✅ PHASE 2 & 6: INSERT SALE WITH DISCOUNT TYPE
        // ============================================
        $stmt = $pdo->prepare("
            INSERT INTO sales
            (clinic_id, sale_date, patient_id, appointment_id, walk_in_name, walk_in_contact, walk_in_email,
             items, subtotal, discount, discount_type, discount_percentage, vat_percentage, vat_amount, total_amount, amount_paid,
             payment_method, status, created_by, verified_by, id_number, created_at)
            VALUES (?, CURDATE(), ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
            $discount_type_final,
            $discount_percentage_final,
            $vat_percentage,
            $vat_amount,
            $total_amount,
            $amount_paid,
            $data['payment_method'] ?? 'cash',
            $sale_status,
            $user_id,
            $verified_by,
            $id_number
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
            'discount_type' => $discount_type_final,
            'discount_percentage' => $discount_percentage_final,
            'vat' => $vat_amount,
            'total_amount' => $total_amount,
            'is_pwd_senior' => $is_pwd_senior,
            'manual_discount' => $manual_discount,
            'walkin_manual_discount' => $walkin_manual_discount,
            'discount_type_selected' => $manual_discount_type,
            'walkin_discount_type' => $walkin_manual_discount_type,
            'verified_by' => $verified_by,
            'items' => $item_details
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sale created successfully',
            'sale_id' => $sale_id,
            'subtotal' => $subtotal,
            'discount' => $discount_amount,
            'discount_type' => $discount_type_final,
            'discount_percentage' => $discount_percentage_final,
            'vat' => $vat_amount,
            'total_amount' => $total_amount,
            'is_pwd_senior' => $is_pwd_senior,
            'is_vat_exempt' => $is_vat_exempt,
            'manual_discount' => $manual_discount,
            'walkin_manual_discount' => $walkin_manual_discount,
            'manual_discount_type' => $manual_discount_type,
            'walkin_discount_type' => $walkin_manual_discount_type,
            'verified_by' => $verified_by,
            'id_number' => $id_number,
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
            // ... existing walkin code ...
        } else {
            // ✅ FIXED: SELECT with COALESCE to ensure items is never NULL
            // ✅ PHASE 4 & 6: Added discount_type and discount_percentage to SELECT
            $stmt = $pdo->prepare("
                SELECT 
                    a.id,
                    a.patient_id,
                    a.subtotal,
                    a.discount_amount,
                    a.discount_type,
                    a.discount_percentage,
                    a.vat_amount,
                    a.total_amount as stored_total,
                    a.amount_paid,
                    a.status as appt_status,
                    " . TOTAL_AMOUNT_SQL . " as computed_total,
                    COALESCE(a.items, '[]') as appointment_items,
                    a.product_id,
                    a.lens_type,
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

            // ✅ PHASE 4 & 6: Check for manual discount override
            $manual_discount = isset($data['manual_discount']) ? floatval($data['manual_discount']) : 0;
            $manual_discount_type = isset($data['manual_discount_type']) ? $data['manual_discount_type'] : 'senior';
            $verified_by = $data['verified_by'] ?? null;
            $id_number = $data['id_number'] ?? null;
            $is_manual_discount = false;

            // ✅ Only apply manual discount if NOT already verified
            $existing_discount_type = $appointment['discount_type'] ?? 'none';
            $is_already_verified = ($existing_discount_type !== 'none' && $existing_discount_type !== 'manual');

            if ($manual_discount > 0 && !$is_already_verified) {
                $is_manual_discount = true;
                $discount_amount = $appointment['subtotal'] * ($manual_discount / 100);
                $discount_type = $manual_discount_type;
                $discount_percentage = $manual_discount;
                $is_vat_exempt = true;
                $vat_amount = 0;
                $totalAmount = $appointment['subtotal'] - $discount_amount;
                
                // ✅ Override the computed total
                $appointment['computed_total'] = $totalAmount;
                $appointment['discount_amount'] = $discount_amount;
                $appointment['discount_type'] = $discount_type;
                $appointment['discount_percentage'] = $discount_percentage;
                $appointment['vat_amount'] = 0;
            }

            $totalAmount = floatval($appointment['computed_total']);
            $currentPaid = floatval($appointment['amount_paid'] ?? 0);
            $newAmountPaid = $currentPaid + $amount;
            $isFullyPaid = $newAmountPaid >= $totalAmount;

            $saleStatus = $isFullyPaid ? 'Paid' : 'Partial';
            $appointmentStatus = $isFullyPaid ? 'completed' : 'waiting_payment';
            $paymentType = $isFullyPaid ? 'full' : 'partial';

            // ✅ Determine final discount type for saving
            $discount_type_final = $appointment['discount_type'] ?? 'none';
            $discount_percentage_final = $appointment['discount_percentage'] ?? 0;
            $discount_amount_final = $appointment['discount_amount'] ?? 0;

            // ✅ UPDATE APPOINTMENT
            if ($is_manual_discount) {
                $pdo->prepare("
                    UPDATE appointments
                    SET amount_paid = ?,
                        payment_status = ?,
                        status = ?,
                        discount_amount = ?,
                        discount_type = ?,
                        discount_percentage = ?,
                        vat_amount = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ")->execute([
                    $newAmountPaid,
                    $isFullyPaid ? 'paid' : 'partial',
                    $appointmentStatus,
                    $discount_amount_final,
                    $discount_type_final,
                    $discount_percentage_final,
                    $appointment['vat_amount'] ?? 0,
                    $appointmentId,
                    $clinic_id
                ]);
            } else {
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
            }

            $saleId = $appointment['sale_id'];
            
            // ============================================
            // ✅ FIXED: GET ITEMS FROM APPOINTMENT WITH VALIDATION
            // ============================================
            $items_json = $appointment['appointment_items'] ?? '[]';
            
            // ✅ Check if items_json is valid JSON
            $items_data = json_decode($items_json, true);
            
            // ✅ Validate: must be array, not empty, and first item must have 'name' key
            $is_valid_items = is_array($items_data) && !empty($items_data) && isset($items_data[0]['name']);
            
            // ✅ If items is invalid, rebuild from product + lens
            if (!$is_valid_items || json_last_error() !== JSON_ERROR_NONE) {
                $items_data = [];
                
                // Get product (frame)
                $productStmt = $pdo->prepare("
                    SELECT name, price FROM products WHERE id = ? AND clinic_id = ?
                ");
                $productStmt->execute([$appointment['product_id'] ?? 0, $clinic_id]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    $items_data[] = [
                        'name' => $product['name'] . ' (Frame)',
                        'price' => floatval($product['price']),
                        'quantity' => 1,
                        'type' => 'product',
                        'product_id' => $appointment['product_id'] ?? null
                    ];
                }
                
                // Get lens
                $lensType = $appointment['lens_type'] ?? '';
                $lensPrices = [
                    'single_vision' => 500,
                    'progressive' => 1500,
                    'blue_cut' => 800,
                    'contact_daily' => 0,
                    'contact_monthly' => 0
                ];
                $lensPrice = $lensPrices[$lensType] ?? 0;
                
                if ($lensType && $lensType !== 'frame_only' && $lensPrice > 0) {
                    $lensName = ucwords(str_replace('_', ' ', $lensType));
                    $items_data[] = [
                        'name' => $lensName . ' Lens',
                        'price' => floatval($lensPrice),
                        'quantity' => 1,
                        'type' => 'service',
                        'lens_type' => $lensType
                    ];
                }
                
                $items_json = json_encode($items_data);
            }
            
            // ✅ If we have valid items, make sure they're properly formatted
            if (is_array($items_data) && !empty($items_data)) {
                foreach ($items_data as &$item) {
                    if (!isset($item['name'])) $item['name'] = 'Item';
                    if (!isset($item['price'])) $item['price'] = 0;
                    if (!isset($item['quantity'])) $item['quantity'] = 1;
                    if (!isset($item['type'])) $item['type'] = 'product';
                }
                $items_json = json_encode($items_data);
            }
            
            // ============================================
            // ✅ PHASE 4 & 6: SAVE OR UPDATE SALES RECORD WITH DISCOUNT TYPE
            // ============================================
            $vat_amount_save = $appointment['vat_amount'] ?? 0;
            $subtotal_save = $appointment['subtotal'] ?? 0;

            if ($saleId) {
                // ✅ UPDATE EXISTING SALE WITH DISCOUNT TYPE
                $updateSale = $pdo->prepare("
                    UPDATE sales
                    SET amount_paid = ?,
                        status = ?,
                        items = ?,
                        discount = ?,
                        discount_type = ?,
                        discount_percentage = ?,
                        vat_amount = ?,
                        total_amount = ?,
                        verified_by = ?,
                        id_number = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $updateSale->execute([
                    $newAmountPaid,
                    $saleStatus,
                    $items_json,
                    $discount_amount_final,
                    $discount_type_final,
                    $discount_percentage_final,
                    $vat_amount_save,
                    $totalAmount,
                    $verified_by,
                    $id_number,
                    $saleId,
                    $clinic_id
                ]);
            } else {
                // ✅ CREATE NEW SALE WITH DISCOUNT TYPE
                $saleStmt = $pdo->prepare("
                    INSERT INTO sales
                    (clinic_id, sale_date, patient_id, appointment_id, 
                     items, subtotal, discount, discount_type, discount_percentage,
                     vat_percentage, vat_amount, total_amount, amount_paid,
                     payment_method, status, created_by, verified_by, id_number, created_at)
                    VALUES (?, CURDATE(), ?, ?, 
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, NOW())
                ");
                $saleStmt->execute([
                    $clinic_id,
                    $appointment['patient_id'],
                    $appointmentId,
                    $items_json,
                    $subtotal_save,
                    $discount_amount_final,
                    $discount_type_final,
                    $discount_percentage_final,
                    $appointment['vat_percentage'] ?? 0,
                    $vat_amount_save,
                    $totalAmount,
                    $newAmountPaid,
                    $paymentMethod,
                    $saleStatus,
                    $user_id,
                    $verified_by,
                    $id_number
                ]);
                $saleId = $pdo->lastInsertId();

                // ✅ INSERT SALE ITEMS
                if (is_array($items_data) && !empty($items_data)) {
                    foreach ($items_data as $item) {
                        $itemStmt = $pdo->prepare("
                            INSERT INTO sale_items
                            (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $itemStmt->execute([
                            $saleId,
                            $clinic_id,
                            $item['product_id'] ?? $item['id'] ?? null,
                            $item['type'] ?? 'product',
                            $item['name'] ?? 'Item',
                            $item['quantity'] ?? 1,
                            $item['price'] ?? 0,
                            ($item['price'] ?? 0) * ($item['quantity'] ?? 1)
                        ]);
                    }
                }
            }

            // ✅ INSERT PAYMENT
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

            // ✅ UPDATE INVENTORY IF FULLY PAID
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
                'discount_type' => $discount_type_final,
                'discount_percentage' => $discount_percentage_final,
                'discount_amount' => $discount_amount_final,
                'is_manual_discount' => $is_manual_discount,
                'message' => $isFullyPaid ? 'Payment completed. Appointment is now completed and inventory updated.' : 'Partial payment recorded',
                'items' => $items_data ?? []
            ]);

        }
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============= CHECK APPOINTMENT VERIFICATION =============
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_appointment_verification') {
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);
    $clinic_id = (int)($_GET['clinic_id'] ?? 0);
    
    if (!$appointmentId || !$clinic_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit;
    }
    
    // Get patient/user info from appointment
    $stmt = $pdo->prepare("
        SELECT a.patient_id, a.user_id, a.discount_type, a.discount_percentage
        FROM appointments a
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $stmt->execute([$appointmentId, $clinic_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // Check if already verified (may discount_type at discount_percentage)
    $is_verified = !empty($appointment['discount_type']) && $appointment['discount_type'] !== 'none';
    
    // If not verified by discount_type, check user_verifications
    if (!$is_verified && $appointment['user_id']) {
        $verification_result = checkPwdSeniorStatus($pdo, $appointment['user_id'], $clinic_id);
        $is_verified = $verification_result['is_verified'];
    }
    
    echo json_encode([
        'success' => true,
        'is_verified' => $is_verified,
        'appointment_id' => $appointmentId,
        'discount_type' => $appointment['discount_type'] ?? 'none',
        'discount_percentage' => $appointment['discount_percentage'] ?? 0
    ]);
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
    $userStmt = $pdo->prepare("
        SELECT id, first_name, last_name, user_id 
        FROM patients 
        WHERE id = ? AND clinic_id = ?
    ");
    $userStmt->execute([$patient_id, $clinic_id]);
    $patientUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patientUser) {
        echo json_encode(['success' => false, 'is_pwd_senior' => false, 'message' => 'Patient not found']);
        exit;
    }
    
    // ✅ Check if patient has user_id
    if (!$patientUser['user_id']) {
        echo json_encode([
            'success' => true, 
            'is_pwd_senior' => false, 
            'message' => 'Patient is not linked to a user account',
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
        'message' => $verification_result['message'],
        'discount_percentage' => $verification_result['is_verified'] ? 20 : 0,
        'debug' => [
            'patient_id' => $patient['id'],
            'user_id' => $patient['user_id'],
            'verification_status' => $verification_result['status']
        ]
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
                    " . TOTAL_AMOUNT_SQL . " as computed_total,
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