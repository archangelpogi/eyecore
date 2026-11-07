<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
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
// ✅ REUSABLE SQL FRAGMENT
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

    if (!$verification) {
        return [
            'is_verified' => false,
            'verification_type' => null,
            'status' => 'none',
            'verified_at' => null,
            'message' => 'No verification found'
        ];
    }

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
            a.product_id,
            a.color_name,
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
            a.items as appointment_items,
            -- ✅ Doctor's recommendation from clinical_notes
            cn.rec_lens_design,
            cn.rec_lens_index,
            cn.rec_lens_features,
            cn.rec_lens_notes,
            cn.diagnosis,
            -- ✅ Product details for frame
            pr.name AS product_name,
            pr.price AS product_price
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN clinical_notes cn ON a.id = cn.appointment_id
        LEFT JOIN products pr ON a.product_id = pr.id
        WHERE a.id = ? AND a.clinic_id = ?
    ");
    $stmt->execute([$appointmentId, $clinic_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        return null;
    }

    $paymentMethodStmt = $pdo->prepare("
        SELECT payment_method
        FROM payments
        WHERE appointment_id = ? AND clinic_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $paymentMethodStmt->execute([$appointmentId, $clinic_id]);
    $lastPaymentMethod = $paymentMethodStmt->fetchColumn();

    // ═══════════════════════════════════════════════════════
    // STEP 1: Build items array (frame items only — from sales or appointments)
    // ═══════════════════════════════════════════════════════
    $items = [];

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

    if (empty($items)) {
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

    // ═══════════════════════════════════════════════════════
    // STEP 2: ADD lens items from clinical_notes (doctor's recommendation)
    // ═══════════════════════════════════════════════════════
    if (!empty($appointment['rec_lens_design'])
        || !empty($appointment['rec_lens_index'])
        || !empty($appointment['rec_lens_features'])) {

        // ✅ Get lens prices from lens_options table
        $lensPrices = ['design' => [], 'index' => [], 'feature' => []];
        try {
            $lensStmt = $pdo->prepare("
                SELECT option_type, option_name, price
                FROM lens_options
                WHERE clinic_id = ? AND is_active = 1
            ");
            $lensStmt->execute([$clinic_id]);
            foreach ($lensStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                $lensPrices[$opt['option_type']][] = $opt;
            }
        } catch (Exception $e) {
            // Fallback kung walang lens_options table
        }

        // ✅ Helper: Find price by matching option name
        $findPrice = function($optionType, $searchName) use ($lensPrices) {
            if (empty($lensPrices[$optionType]) || empty($searchName)) return 0;

            $searchNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $searchName));

            foreach ($lensPrices[$optionType] as $opt) {
                $optNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $opt['option_name']));
                if (strpos($optNorm, $searchNorm) !== false
                    || strpos($searchNorm, $optNorm) !== false) {
                    return floatval($opt['price']);
                }
            }
            return 0;
        };

        // ✅ Add Lens Design
        if (!empty($appointment['rec_lens_design'])) {
            $designPrice = $findPrice('design', $appointment['rec_lens_design']);
            $items[] = [
                'item_name' => $appointment['rec_lens_design'] . ' Lens',
                'item_type' => 'service',
                'unit_price' => $designPrice,
                'quantity' => 1,
                'total_price' => $designPrice,
                'source' => 'doctor_recommendation'
            ];
        }

        // ✅ Add Lens Index
        if (!empty($appointment['rec_lens_index'])) {
            $indexPrice = $findPrice('index', $appointment['rec_lens_index']);
            $items[] = [
                'item_name' => $appointment['rec_lens_index'],
                'item_type' => 'service',
                'unit_price' => $indexPrice,
                'quantity' => 1,
                'total_price' => $indexPrice,
                'source' => 'doctor_recommendation'
            ];
        }

        // ✅ Add Features
        $recFeatures = [];
        if (!empty($appointment['rec_lens_features'])) {
            $decodedFeatures = json_decode($appointment['rec_lens_features'], true);
            if (is_array($decodedFeatures)) {
                $recFeatures = $decodedFeatures;
            }
        }

        foreach ($recFeatures as $featureName) {
            $featurePrice = $findPrice('feature', $featureName);
            $items[] = [
                'item_name' => $featureName,
                'item_type' => 'service',
                'unit_price' => $featurePrice,
                'quantity' => 1,
                'total_price' => $featurePrice,
                'source' => 'doctor_recommendation'
            ];
        }
    }

    // ═══════════════════════════════════════════════════════
    // STEP 3: RECOMPUTE subtotal, discount, at total base sa COMPLETE items
    // ═══════════════════════════════════════════════════════
    $completeSubtotal = 0;
    foreach ($items as $item) {
        $completeSubtotal += floatval($item['total_price'] ?? 0);
    }

    // ✅ FIXED: Tamang RA 9994 / RA 10754 computation
    $discountPct = floatval($appointment['discount_percentage'] ?? 0);
    $discountType = $appointment['discount_type'] ?? 'none';

    if ($discountType === 'senior' || $discountType === 'pwd') {
        // ✅ PWD/Senior: VAT-EXCLUSIVE base, 20% discount, VAT-exempt
        $vatExclusiveSubtotal = $completeSubtotal / 1.12;
        $discountAmount = $vatExclusiveSubtotal * ($discountPct / 100);
        $vatAmount = 0;
        $finalTotal = $vatExclusiveSubtotal - $discountAmount;
    } elseif ($discountType !== 'none' && $discountPct > 0) {
        // ✅ Manual discount (may VAT)
        $discountAmount = $completeSubtotal * ($discountPct / 100);
        $vatAmount = ($completeSubtotal - $discountAmount) * 0.12;
        $finalTotal = ($completeSubtotal - $discountAmount) + $vatAmount;
    } else {
        // ✅ Walang discount (VAT-inclusive)
        $discountAmount = 0;
        $vatAmount = $completeSubtotal - ($completeSubtotal / 1.12);
        $finalTotal = $completeSubtotal;
    }

    $amountPaid = floatval($appointment['amount_paid'] ?? 0);

    // ✅ FIX: Declare $subtotal and $status_label (dati undefined ito!)
    $subtotal = $completeSubtotal;

    if ($amountPaid >= $finalTotal && $finalTotal > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $finalTotal) {
        $status_label = 'Partial';
    } else {
        $status_label = 'Unpaid';
    }

    // ═══════════════════════════════════════════════════════
    // STEP 4: Return complete bill data
    // ═══════════════════════════════════════════════════════
    return [
        'id' => $appointment['id'],
        'appointment_id' => $appointment['appointment_id'],
        'invoice_id' => $appointment['invoice_id'],
        'sale_date' => $appointment['sale_date'],
        'customer_name' => $appointment['customer_name'],
        'customer_code' => $appointment['customer_code'],
        'patient_id' => $appointment['patient_id'],
        'product_id' => $appointment['product_id'],
        'product_name' => $appointment['product_name'],
        'product_price' => floatval($appointment['product_price'] ?? 0),
        'color_name' => $appointment['color_name'],
        'subtotal' => $subtotal,
        'discount_type' => $appointment['discount_type'] ?? 'none',
        'discount_percentage' => floatval($appointment['discount_percentage'] ?? 0),
        'discount_amount' => $discountAmount,
        'vat_percentage' => ($discountType === 'senior' || $discountType === 'pwd') ? 0 : 12,
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
        'verified_by' => $appointment['verified_by'] ?? null,
        'id_number' => $appointment['id_number'] ?? null,
        'is_manual_discount' => ($appointment['discount_type'] ?? '') === 'manual',
        'is_verified_discount' => ($appointment['discount_type'] ?? '') !== 'none' && ($appointment['discount_type'] ?? '') !== 'manual',
        // ✅ Doctor's recommendation (for UI display)
        'rec_lens_design' => $appointment['rec_lens_design'] ?? null,
        'rec_lens_index' => $appointment['rec_lens_index'] ?? null,
        'rec_lens_features' => $appointment['rec_lens_features'] ?? null,
        'rec_lens_notes' => $appointment['rec_lens_notes'] ?? null,
        'diagnosis' => $appointment['diagnosis'] ?? null
    ];
}

// ============================================
// ✅ buildBillFromSales
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

    $items = [];
    
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

    $totalAmount = floatval($sale['total_amount'] ?? 0);
    $amountPaid = floatval($sale['amount_paid'] ?? 0);
    $subtotal = floatval($sale['subtotal'] ?? 0);
    $discountAmount = floatval($sale['discount'] ?? 0);
    $vatAmount = floatval($sale['vat_amount'] ?? 0);
    $vatPercentage = floatval($sale['vat_percentage'] ?? 0);

    if ($subtotal == 0 && !empty($items)) {
        $subtotal = array_sum(array_column($items, 'total_price'));
    }

    if ($amountPaid >= $totalAmount && $totalAmount > 0) {
        $status_label = 'Paid';
    } elseif ($amountPaid > 0 && $amountPaid < $totalAmount) {
        $status_label = 'Partial';
    } else {
        $status_label = 'Unpaid';
    }

    $discountType = $sale['discount_type'] ?? 'none';
    $discountPercentage = floatval($sale['discount_percentage'] ?? 0);
    
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
    
if (isset($_GET['action']) && $_GET['action'] === 'get_order_details') {
    SalesPermission::check('view');
    
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        // ✅ MAIN QUERY — with payment info + product category
        $stmt = $pdo->prepare("
            SELECT 
                a.id AS appointment_id,
                a.id,
                a.status,
                a.patient_id,
                a.user_id,
                a.doctor_id,
                a.product_id,
                a.frame_name AS appointment_frame_name,
                a.order_source,
                a.sent_to_sales_at,
                a.sent_to_sales_by,
                a.color_code,
                a.color_name,
                
                -- ✅ PAYMENT INFO
                a.total_amount AS stored_total,
                a.subtotal,
                a.discount_type,
                a.discount_percentage,
                a.discount_amount,
                a.vat_percentage,
                a.vat_amount,
                a.amount_paid,
                a.payment_status,
                a.payment_type,
                a.paymongo_payment_id,
                a.paid_at,
                a.downpayment_ref,
                a.balance_amount,
                a.items AS appointment_items,
                
                p.first_name, p.last_name, p.age, p.gender, p.patient_type,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                d.name AS doctor_name,
                prod.name AS product_frame_name,
                prod.price AS product_frame_price,
                prod.category AS product_category,
                srv.name AS service_name,
                srv.category AS service_category
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN doctors d ON a.doctor_id = d.id
            LEFT JOIN products prod ON a.product_id = prod.id AND prod.clinic_id = a.clinic_id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            WHERE a.id = ? AND a.clinic_id = ?
            LIMIT 1
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        // ✅ DETECT PRODUCT TYPE
        $rawCategory = strtolower(
            $order['product_category'] 
            ?? $order['service_category'] 
            ?? ''
        );
        $isContactLens = (strpos($rawCategory, 'contact') !== false);
        $isEyeglass = in_array($rawCategory, ['frames', 'eyeglasses', 'sunglasses', 'lenses']);
        $isService = ($order['product_category'] === null && $order['service_name'] !== null);
        
        // ✅ PARSE ITEMS
        $items = [];
        if (!empty($order['appointment_items'])) {
            $decoded = json_decode($order['appointment_items'], true);
            if (is_array($decoded)) $items = $decoded;
        }
        
        // ✅ Frame name/price
        $frameItem = null;
        foreach ($items as $item) {
            $itemName = strtolower($item['name'] ?? '');
            $itemType = $item['type'] ?? '';
            if ($itemType === 'product' && (strpos($itemName, 'frame') !== false || strpos($itemName, 'aviator') !== false)) {
                $frameItem = $item;
                break;
            }
        }
        
        $frameName = $order['appointment_frame_name'] 
                  ?? $order['product_frame_name'] 
                  ?? ($frameItem['name'] ?? null);
        $framePrice = floatval($order['product_frame_price'] ?? ($frameItem['price'] ?? 0));
        $age = !empty($order['age']) ? (int)$order['age'] : null;
        
        // ═══════════════════════════════════════════════════════
        // ✅ Fetch clinical notes (doctor's recommendation + CL assessment)
        // ═══════════════════════════════════════════════════════
        $notesStmt = $pdo->prepare("
            SELECT 
                rec_lens_design, 
                rec_lens_index, 
                rec_lens_features, 
                rec_lens_notes, 
                diagnosis,
                -- ✅ CONTACT LENS ASSESSMENT FIELDS
                cl_base_curve, 
                cl_diameter, 
                cl_power, 
                cl_cylinder, 
                cl_axis, 
                cl_lens_type, 
                cl_fit_assessment, 
                cl_trial_lens
            FROM clinical_notes
            WHERE appointment_id = ? AND clinic_id = ?
            LIMIT 1
        ");
        $notesStmt->execute([$appointmentId, $clinic_id]);
        $notes = $notesStmt->fetch(PDO::FETCH_ASSOC);
        
        // ✅ Parse features
        $recFeatures = [];
        if (!empty($notes['rec_lens_features'])) {
            $raw = $notes['rec_lens_features'];
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $recFeatures = $decoded;
            } else {
                $recFeatures = array_map('trim', explode(',', $raw));
                $recFeatures = array_filter($recFeatures);
                $recFeatures = array_values($recFeatures);
            }
        }
        
        // ✅ Fetch prescription
        $rxStmt = $pdo->prepare("
            SELECT sph_r, cyl_r, axis_r, add_r, sph_l, cyl_l, axis_l, add_l, pd, pd_type
            FROM prescriptions
            WHERE appointment_id = ? AND clinic_id = ?
            LIMIT 1
        ");
        $rxStmt->execute([$appointmentId, $clinic_id]);
        $rx = $rxStmt->fetch(PDO::FETCH_ASSOC);
        
        // ✅ Validation helpers
        $validateDiopter = fn($v) => ($v === null || $v === '') ? null : (is_numeric($v) ? floatval($v) : null);
        $validateAxis = function($v) {
            if ($v === null || $v === '') return null;
            $n = intval($v);
            return ($n >= 0 && $n <= 180) ? $n : null;
        };
        $validatePd = function($v) {
            if ($v === null || $v === '') return null;
            if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:\s*mm)?$/i', trim($v), $m)) return floatval($m[1]);
            return null;
        };
        
        // ✅ Fetch lens options
        $grouped = ['designs' => [], 'indexes' => [], 'features' => []];
        try {
            $lensStmt = $pdo->prepare("
                SELECT id, option_type, option_name, option_code, price
                FROM lens_options
                WHERE clinic_id = ? AND is_active = 1
                ORDER BY option_type, display_order
            ");
            $lensStmt->execute([$clinic_id]);
            $lensOptions = $lensStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($lensOptions as $opt) {
                if ($opt['option_type'] === 'design') $grouped['designs'][] = $opt;
                elseif ($opt['option_type'] === 'index') $grouped['indexes'][] = $opt;
                elseif ($opt['option_type'] === 'feature') $grouped['features'][] = $opt;
            }
        } catch (Exception $e) {
            $grouped['designs'] = [
                ['id' => 'single_vision', 'option_name' => 'Single Vision', 'price' => 500.00],
                ['id' => 'progressive', 'option_name' => 'Progressive', 'price' => 1500.00],
            ];
            $grouped['indexes'] = [
                ['id' => '1.56', 'option_name' => '1.56 Standard', 'price' => 0.00],
                ['id' => '1.60', 'option_name' => '1.60 Thin', 'price' => 300.00],
                ['id' => '1.67', 'option_name' => '1.67 Ultra Thin', 'price' => 800.00],
                ['id' => '1.74', 'option_name' => '1.74 High Index', 'price' => 1500.00],
            ];
            $grouped['features'] = [
                ['id' => 'blue_cut', 'option_name' => 'Blue Cut', 'price' => 800.00],
                ['id' => 'ar', 'option_name' => 'Anti-Reflective (AR)', 'price' => 500.00],
                ['id' => 'photochromic', 'option_name' => 'Photochromic', 'price' => 1200.00],
            ];
        }
        
        // ═══════════════════════════════════════════════════════
        // ✅ BUILD FINAL ITEMS — base sa product type
        // ═══════════════════════════════════════════════════════
        $final_items = [];
        
        if ($isContactLens) {
            // ═══════════════════════════════════════════════════════
            // CONTACT LENS: Contact lens lang ang item
            // ═══════════════════════════════════════════════════════
            
            if (!empty($frameName)) {
                $final_items[] = [
                    'name' => $frameName,
                    'price' => $framePrice,
                    'quantity' => 1,
                    'type' => 'product',
                    'category' => 'contact_lens',
                    'source' => 'paid'
                ];
            }
            
            // ✅ Walang lens design, index, o features para sa CL
            
        } elseif ($isEyeglass) {
            // ═══════════════════════════════════════════════════════
            // EYEGLASS: Frame + Lens Design + Index + Features
            // ═══════════════════════════════════════════════════════
            
            // 1. Frame
            if (!empty($frameName)) {
                $final_items[] = [
                    'name' => $frameName,
                    'price' => $framePrice,
                    'quantity' => 1,
                    'type' => 'product',
                    'source' => 'paid'
                ];
            }
            
            // 2. Lens Design
            if (!empty($notes['rec_lens_design'])) {
                $designPrice = 0;
                foreach ($grouped['designs'] as $d) {
                    if (stripos($d['option_name'], $notes['rec_lens_design']) !== false) {
                        $designPrice = floatval($d['price']);
                        break;
                    }
                }
                $final_items[] = [
                    'name' => $notes['rec_lens_design'] . ' Lens',
                    'price' => $designPrice,
                    'quantity' => 1,
                    'type' => 'service',
                    'source' => 'doctor_rec'
                ];
            }
            
            // 3. Lens Index
            if (!empty($notes['rec_lens_index'])) {
                $indexPrice = 0;
                foreach ($grouped['indexes'] as $idx) {
                    if (stripos($idx['option_name'], $notes['rec_lens_index']) !== false) {
                        $indexPrice = floatval($idx['price']);
                        break;
                    }
                }
                $final_items[] = [
                    'name' => $notes['rec_lens_index'] . ' Index',
                    'price' => $indexPrice,
                    'quantity' => 1,
                    'type' => 'service',
                    'source' => 'doctor_rec'
                ];
            }
            
            // 4. Features
            foreach ($recFeatures as $feature) {
                $featurePrice = 0;
                foreach ($grouped['features'] as $f) {
                    if (stripos($f['option_name'], $feature) !== false) {
                        $featurePrice = floatval($f['price']);
                        break;
                    }
                }
                $final_items[] = [
                    'name' => $feature,
                    'price' => $featurePrice,
                    'quantity' => 1,
                    'type' => 'service',
                    'source' => 'doctor_rec'
                ];
            }
            
        } else {
            // ═══════════════════════════════════════════════════════
            // SERVICE / OTHER: Items lang
            // ═══════════════════════════════════════════════════════
            
            foreach ($items as $item) {
                $final_items[] = [
                    'name' => $item['name'] ?? 'Item',
                    'price' => floatval($item['price'] ?? 0),
                    'quantity' => intval($item['quantity'] ?? 1),
                    'type' => $item['type'] ?? 'service',
                    'source' => 'paid'
                ];
            }
        }
        
        // ✅ Compute final subtotal
        $final_subtotal = 0;
        foreach ($final_items as $item) {
            $final_subtotal += floatval($item['price']) * intval($item['quantity'] ?? 1);
        }
        
        // ✅ Compute discount (based on DB)
        $discountType = $order['discount_type'] ?? 'none';
        $discountPct = floatval($order['discount_percentage'] ?? 0);
if ($discountType !== 'none' && $discountPct > 0) {
    // ✅ PWD/Senior: VAT-EXCLUSIVE → discount → VAT-exempt
    $vatExclusiveSubtotal = $final_subtotal / 1.12;
    $final_discount = $vatExclusiveSubtotal * ($discountPct / 100);
    $final_vat = 0;
    $final_total = $vatExclusiveSubtotal - $final_discount;
} else {
    // ✅ Regular: VAT-inclusive
    $final_discount = 0;
    $final_vat = $final_subtotal - ($final_subtotal / 1.12);
    $final_total = $final_subtotal;
}
        
        // ✅ Online payment
        $onlinePaid = floatval($order['amount_paid'] ?? 0);
        $remaining = max(0, $final_total - $onlinePaid);
        $refundDue = max(0, $onlinePaid - $final_total);
        
        // ═══════════════════════════════════════════════════════
        // ✅ RETURN
        // ═══════════════════════════════════════════════════════
        echo json_encode([
            'success' => true,
            'order' => [
                'appointment_id' => $order['id'],
                'patient_id' => $order['patient_id'],
                'patient_name' => $order['patient_name'],
                'age' => $age,
                'gender' => $order['gender'],
                'patient_type' => $order['patient_type'],
                'doctor_name' => $order['doctor_name'],
                'order_source' => $order['order_source'],
                'sent_to_sales_at' => $order['sent_to_sales_at'],
                
                // ✅ CATEGORY FLAGS
                'product_category' => $order['product_category'],
                'service_category' => $order['service_category'],
                'is_contact_lens' => $isContactLens,
                'is_eyeglass' => $isEyeglass,
                'is_service' => $isService,
                
                // ✅ FRAME / PRODUCT
                'frame_name' => $frameName,
                'frame_price' => $framePrice,
                'frame_product_id' => $order['product_id'],
                'color_code' => $order['color_code'],
                'color_name' => $order['color_name'],
                
                // ✅ PRESCRIPTION
                'sph_r' => $validateDiopter($rx['sph_r'] ?? null),
                'cyl_r' => $validateDiopter($rx['cyl_r'] ?? null),
                'axis_r' => $validateAxis($rx['axis_r'] ?? null),
                'add_r' => $validateDiopter($rx['add_r'] ?? null),
                'sph_l' => $validateDiopter($rx['sph_l'] ?? null),
                'cyl_l' => $validateDiopter($rx['cyl_l'] ?? null),
                'axis_l' => $validateAxis($rx['axis_l'] ?? null),
                'add_l' => $validateDiopter($rx['add_l'] ?? null),
                'pd' => $validatePd($rx['pd'] ?? null),
                'pd_type' => $rx['pd_type'] ?? 'Binocular',
                
                // ✅ DOCTOR'S RECOMMENDATION (eyeglass)
                'rec_lens_design' => $notes['rec_lens_design'] ?? null,
                'rec_lens_index' => $notes['rec_lens_index'] ?? null,
                'rec_lens_features' => $recFeatures,
                'rec_lens_notes' => $notes['rec_lens_notes'] ?? null,
                'diagnosis' => $notes['diagnosis'] ?? null,
                
                // ✅ CONTACT LENS ASSESSMENT (new)
                'cl_base_curve' => $notes['cl_base_curve'] ?? null,
                'cl_diameter' => $notes['cl_diameter'] ?? null,
                'cl_power' => $notes['cl_power'] ?? null,
                'cl_cylinder' => $notes['cl_cylinder'] ?? null,
                'cl_axis' => $notes['cl_axis'] ?? null,
                'cl_lens_type' => $notes['cl_lens_type'] ?? null,
                'cl_fit_assessment' => $notes['cl_fit_assessment'] ?? null,
                'cl_trial_lens' => $notes['cl_trial_lens'] ?? null,
                
                // ✅ FINAL ITEMS
                'final_items' => $final_items,
                'final_subtotal' => $final_subtotal,
                'final_discount' => $final_discount,
                'final_vat' => $final_vat,
                'final_total' => $final_total,
                
                // ✅ ORIGINAL PAID ITEMS
                'paid_items' => $items,
                'paid_subtotal' => floatval($order['subtotal'] ?? 0),
                'paid_total' => floatval($order['stored_total'] ?? 0),
                
                // ✅ ONLINE PAYMENT
                'amount_paid' => $onlinePaid,
                'remaining_balance' => $remaining,
                'refund_due' => $refundDue,
                'payment_status' => $order['payment_status'] ?? 'pending',
                'payment_type' => $order['payment_type'] ?? 'full',
                'paymongo_payment_id' => $order['paymongo_payment_id'],
                'paid_at' => $order['paid_at'],
                
                // ✅ DISCOUNT
                'discount_type' => $discountType,
                'discount_percentage' => $discountPct,
                'is_pwd_senior' => ($discountType !== 'none'),
                
                // ✅ RAW ITEMS
                'items' => $items,
            ],
            'lens_options' => $grouped
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}
    
    // ============================================
    // ✅ CHECK APPOINTMENT VERIFICATION
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
    // ✅ CHECK PWD/SENIOR STATUS
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
    // ✅ CHECK PWD/SENIOR BY NAME (WALK-IN)
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
    // ✅ VIEW INVOICE BY ID
    // ============================================
    if (isset($_GET['id']) && !isset($_GET['action'])) {
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
    // ✅ EXPORT
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
    // ✅ VIEW INVOICE BY APPOINTMENT ID (walk-in fallback)
    // ✅ Only if NO action specified (para hindi mag-conflict sa get_order_details)
    // ============================================
    if (isset($_GET['appointment_id']) && !isset($_GET['action'])) {
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
    // ✅ LIST ALL INVOICES (DEFAULT)
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
        
        $manual_discount = isset($data['manual_discount']) ? floatval($data['manual_discount']) : 0;
        $manual_discount_type = isset($data['manual_discount_type']) ? $data['manual_discount_type'] : 'senior';
        $verified_by = $data['verified_by'] ?? null;
        $id_number = $data['id_number'] ?? null;

        $walkin_manual_discount = isset($data['walkin_manual_discount']) ? floatval($data['walkin_manual_discount']) : 0;
        $walkin_manual_discount_type = isset($data['walkin_manual_discount_type']) ? $data['walkin_manual_discount_type'] : 'senior';
        $walkin_verified_by = $data['walkin_verified_by'] ?? null;
        $walkin_id_number = $data['walkin_id_number'] ?? null;

        if ($data['customer_type'] === 'registered') {
            $patient_id = $data['patient_id'];
            
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
            $walk_in_name = trim($data['walk_in_name']);
            $walk_in_contact = $data['walk_in_contact'] ?? null;
            $walk_in_email = $data['walk_in_email'] ?? null;
            
            $patient_found = false;
            
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

        if ($is_pwd_senior) {
            $discount_amount = $subtotal * $pwd_senior_discount;
            $discount_type_final = $discount_type_final ?: 'senior';
            $discount_percentage_final = $pwd_senior_discount * 100;
            $is_vat_exempt = true;
            $manual_discount = 0;
        } elseif ($manual_discount > 0) {
            $discount_amount = $subtotal * ($manual_discount / 100);
            $discount_type_final = $manual_discount_type;
            $discount_percentage_final = $manual_discount;
            $is_vat_exempt = true;
        } elseif ($walkin_manual_discount > 0) {
            $discount_amount = $subtotal * ($walkin_manual_discount / 100);
            $discount_type_final = $walkin_manual_discount_type;
            $discount_percentage_final = $walkin_manual_discount;
            $is_vat_exempt = true;
            $verified_by = $walkin_verified_by;
            $id_number = $walkin_id_number;
        } else {
            $discount_amount = 0;
            $discount_type_final = 'none';
            $discount_percentage_final = 0;
            $is_vat_exempt = false;
        }

        $amount_after_discount = $subtotal - $discount_amount;
        
        if ($is_pwd_senior && $pwd_senior_vat_exempt == 1) {
            $vat_percentage = 0;
            $vat_amount = 0;
            $is_vat_exempt = true;
        } elseif ($manual_discount > 0 || $walkin_manual_discount > 0) {
            $vat_percentage = 0;
            $vat_amount = 0;
            $is_vat_exempt = true;
        } else {
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
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit;
    }
    
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $referenceNumber = $data['reference_number'] ?? null;
    $notes = $data['notes'] ?? null;
    $sourceType = $data['source_type'] ?? 'appointment';
    
    if (!$appointmentId || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment data: appointment_id and amount are required']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $appointment = null;
        $sale = null;
        $recordType = 'appointment';
        $recordId = $appointmentId;
        
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.patient_id,
                a.user_id,
                a.subtotal,
                a.discount_amount,
                a.discount_type,
                a.discount_percentage,
                a.vat_amount,
                a.total_amount as stored_total,
                a.amount_paid,
                a.status as appt_status,
                COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))) as computed_total,
                COALESCE(a.items, '[]') as appointment_items,
                a.product_id,
                a.lens_type,
                s.id as sale_id,
                s.status as sale_status,
                s.total_amount as sale_total,
                NULL as walk_in_name,
                NULL as walk_in_contact,
                NULL as walk_in_email
            FROM appointments a
            LEFT JOIN sales s ON a.id = s.appointment_id AND s.clinic_id = a.clinic_id
            WHERE a.id = ? AND a.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            $stmt = $pdo->prepare("
                SELECT 
                    s.id,
                    s.patient_id,
                    NULL as user_id,
                    s.subtotal,
                    s.discount as discount_amount,
                    s.discount_type,
                    s.discount_percentage,
                    s.vat_amount,
                    s.total_amount as stored_total,
                    s.amount_paid,
                    s.status as sale_status,
                    s.total_amount as computed_total,
                    s.items as appointment_items,
                    NULL as product_id,
                    NULL as lens_type,
                    s.id as sale_id,
                    s.status as sale_status,
                    s.total_amount as sale_total,
                    s.walk_in_name,
                    s.walk_in_contact,
                    s.walk_in_email,
                    'walkin' as record_type
                FROM sales s
                WHERE s.id = ? AND s.clinic_id = ?
            ");
            $stmt->execute([$appointmentId, $clinic_id]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sale) {
                throw new Exception('Record not found in appointments or sales');
            }
            $recordType = 'walkin';
            $appointment = $sale;
        }

        $totalAmount = floatval($appointment['computed_total'] ?? $appointment['stored_total'] ?? 0);
        $currentPaid = floatval($appointment['amount_paid'] ?? 0);
        $newAmountPaid = $currentPaid + $amount;
        $isFullyPaid = $newAmountPaid >= $totalAmount;

        $saleStatus = $isFullyPaid ? 'Paid' : 'Partial';
        $paymentType = $isFullyPaid ? 'full' : 'partial';

        $items_json = $appointment['appointment_items'] ?? '[]';
        $items_data = json_decode($items_json, true);
        
        if (!is_array($items_data) || empty($items_data)) {
            $items_data = [];
            if ($recordType === 'appointment' && $appointment['product_id']) {
                $productStmt = $pdo->prepare("SELECT name, price FROM products WHERE id = ? AND clinic_id = ?");
                $productStmt->execute([$appointment['product_id'], $clinic_id]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                if ($product) {
                    $items_data[] = [
                        'name' => $product['name'],
                        'price' => floatval($product['price']),
                        'quantity' => 1,
                        'type' => 'product'
                    ];
                }
            }
            $items_json = json_encode($items_data);
        }

        $discount_type_final = $appointment['discount_type'] ?? 'none';
        $discount_percentage_final = $appointment['discount_percentage'] ?? 0;
        $discount_amount_final = $appointment['discount_amount'] ?? 0;
        $vat_amount_save = $appointment['vat_amount'] ?? 0;
        $subtotal_save = $appointment['subtotal'] ?? 0;

        $saleId = $appointment['sale_id'];

        if ($recordType === 'walkin') {
            $updateSale = $pdo->prepare("
                UPDATE sales
                SET amount_paid = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $updateSale->execute([
                $newAmountPaid,
                $saleStatus,
                $appointmentId,
                $clinic_id
            ]);
            
            $saleId = $appointmentId;
            
        } else {
            $updateAppt = $pdo->prepare("
                UPDATE appointments
                SET amount_paid = ?,
                    payment_status = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $updateAppt->execute([
                $newAmountPaid,
                $isFullyPaid ? 'paid' : 'partial',
                $isFullyPaid ? 'paid' : 'confirmed',
                $appointmentId,
                $clinic_id
            ]);

            if ($saleId) {
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
                    $saleId,
                    $clinic_id
                ]);
            } else {
                $saleStmt = $pdo->prepare("
                    INSERT INTO sales
                    (clinic_id, sale_date, patient_id, appointment_id, 
                     items, subtotal, discount, discount_type, discount_percentage,
                     vat_percentage, vat_amount, total_amount, amount_paid,
                     payment_method, status, created_by, created_at)
                    VALUES (?, CURDATE(), ?, ?, 
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?, NOW())
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
                    $user_id
                ]);
                $saleId = $pdo->lastInsertId();

                if (!empty($items_data)) {
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
        }

        $paymentStmt = $pdo->prepare("
            INSERT INTO payments
            (sale_id, appointment_id, clinic_id, user_id, amount, payment_method,
             payment_status, payment_type, reference_number, notes, payment_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, NOW(), NOW())
        ");
        $paymentStmt->execute([
            $saleId,
            ($recordType === 'walkin' ? null : $appointmentId),
            $clinic_id,
            $user_id,
            $amount,
            $paymentMethod,
            $paymentType,
            $referenceNumber,
            $notes
        ]);

        if ($isFullyPaid && $recordType === 'appointment') {
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

        $customerName = $appointment['walk_in_name'] ?? $appointment['patient_name'] ?? 'Customer';
        
        echo json_encode([
            'success' => true,
            'message' => $isFullyPaid ? 'Payment completed successfully!' : 'Partial payment recorded',
            'data' => [
                'record_type' => $recordType,
                'record_id' => $recordId,
                'sale_id' => $saleId,
                'sale_status' => $saleStatus,
                'amount_paid' => $newAmountPaid,
                'balance' => max(0, $totalAmount - $newAmountPaid),
                'fully_paid' => $isFullyPaid,
                'discount_type' => $discount_type_final,
                'discount_percentage' => $discount_percentage_final,
                'discount_amount' => $discount_amount_final,
                'customer_name' => $customerName,
                'payment_method' => $paymentMethod,
                'items' => $items_data
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ============= FINALIZE ORDER =============
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'finalize_order') {
    SalesPermission::check('edit');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // ✅ STEP 1: Fetch appointment details WITH category detection
        $stmt = $pdo->prepare("
            SELECT 
                a.*, 
                p.first_name, 
                p.last_name,
                prod.category AS product_category,
                prod.name AS product_name,
                srv.category AS service_category,
                srv.name AS service_name
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN products prod ON a.product_id = prod.id AND prod.clinic_id = a.clinic_id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            WHERE a.id = ? AND a.clinic_id = ? AND a.status = 'waiting_payment'
        ");
        $stmt->execute([$appointmentId, $clinic_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            throw new Exception('Appointment not found or already finalized');
        }
        
        // ✅ STEP 2: Detect product type
        $rawCategory = strtolower(
            $appointment['product_category'] 
            ?? $appointment['service_category'] 
            ?? ''
        );
        $isContactLens = (strpos($rawCategory, 'contact') !== false);
        $isEyeglass = in_array($rawCategory, ['frames', 'eyeglasses', 'sunglasses', 'lenses']);
        
        // ✅ STEP 3: Build items base sa product type
        $items = [];
        $subtotal = 0;
        
        if ($isContactLens) {
            // ═══════════════════════════════════════════════════════
            // CONTACT LENS: Contact lens lang ang item
            // ═══════════════════════════════════════════════════════
            
            if (!empty($data['frame_name'])) {
                $items[] = [
                    'name' => $data['frame_name'],
                    'type' => 'product',
                    'price' => floatval($data['frame_price']),
                    'quantity' => 1,
                    'category' => 'contact_lens'
                ];
                $subtotal += floatval($data['frame_price']);
            }
            
            // ✅ Walang lens design, index, o features para sa CL
            
        } else {
            // ═══════════════════════════════════════════════════════
            // EYEGLASS / SERVICE: existing logic
            // ═══════════════════════════════════════════════════════
            
            if (!empty($data['frame_name'])) {
                $items[] = [
                    'name' => $data['frame_name'],
                    'type' => 'product',
                    'price' => floatval($data['frame_price']),
                    'quantity' => 1
                ];
                $subtotal += floatval($data['frame_price']);
            }
            
            if (!empty($data['lens_design_name'])) {
                $items[] = [
                    'name' => $data['lens_design_name'],
                    'type' => 'service',
                    'price' => floatval($data['lens_design_price']),
                    'quantity' => 1
                ];
                $subtotal += floatval($data['lens_design_price']);
            }
            
            if (!empty($data['lens_index_name'])) {
                $items[] = [
                    'name' => $data['lens_index_name'],
                    'type' => 'service',
                    'price' => floatval($data['lens_index_price']),
                    'quantity' => 1
                ];
                $subtotal += floatval($data['lens_index_price']);
            }
            
            foreach (($data['features'] ?? []) as $feature) {
                $items[] = [
                    'name' => $feature['name'],
                    'type' => 'service',
                    'price' => floatval($feature['price']),
                    'quantity' => 1
                ];
                $subtotal += floatval($feature['price']);
            }
        }
        
        // ✅ STEP 4: Compute totals
        $discountType = $data['discount_type'] ?? 'none';
        $discountAmount = floatval($data['discount_amount'] ?? 0);
        $vatAmount = floatval($data['vat_amount'] ?? 0);
        $finalTotal = floatval($data['final_total'] ?? 0);
        $amountPaid = floatval($data['amount_paid'] ?? 0);
        
        // ✅ Compute online payment at remaining balance
        $onlinePaid = floatval($data['online_paid'] ?? 0);
        $remainingBalance = max(0, $finalTotal - $onlinePaid);
        
        // ✅ Total paid = online + cash
        $totalPaid = $onlinePaid + $amountPaid;
        
        $receiptNo = 'RCP-' . date('Ymd') . '-' . str_pad($appointmentId, 5, '0', STR_PAD_LEFT);
        $status = ($totalPaid >= $finalTotal) ? 'Paid' : 'Partial';
        
        // ✅ STEP 5: INSERT into sales
        $saleStmt = $pdo->prepare("
            INSERT INTO sales 
                (clinic_id, sale_date, patient_id, appointment_id, items,
                 subtotal, discount, discount_type, discount_percentage,
                 vat_percentage, vat_amount, total_amount, amount_paid,
                 payment_method, status, created_by, created_at)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $saleStmt->execute([
            $clinic_id,
            $appointment['patient_id'],
            $appointmentId,
            json_encode($items),
            $subtotal,
            $discountAmount,
            $discountType,
            ($discountType === 'senior' || $discountType === 'pwd') ? 20 : 0,
            ($discountType === 'senior' || $discountType === 'pwd') ? 0 : 12,
            $vatAmount,
            $finalTotal,
            $totalPaid,  // ✅ Total paid = online + cash
            $data['payment_method'] ?? 'cash',
            $status,
            $user_id
        ]);
        $saleId = $pdo->lastInsertId();
        
        // ✅ STEP 6: INSERT sale_items
        foreach ($items as $item) {
            $pdo->prepare("
                INSERT INTO sale_items 
                    (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $saleId, 
                $clinic_id,
                null, 
                $item['type'], 
                $item['name'],
                $item['quantity'], 
                $item['price'],
                $item['price'] * $item['quantity']
            ]);
        }
        
        // ✅ STEP 7: INSERT payment record (cash payment only)
        if ($amountPaid > 0) {
            $pdo->prepare("
                INSERT INTO payments 
                    (sale_id, appointment_id, clinic_id, user_id, amount,
                     payment_method, payment_status, payment_type, reference_number,
                     payment_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW(), NOW())
            ")->execute([
                $saleId, 
                $appointmentId, 
                $clinic_id, 
                $user_id,
                $amountPaid,
                $data['payment_method'] ?? 'cash',
                ($amountPaid >= $remainingBalance) ? 'full' : 'partial',
                $data['reference_no'] ?? null
            ]);
        }
        
        // ✅ STEP 8: Update appointment
        $newStatus = ($totalPaid >= $finalTotal) ? 'completed' : 'waiting_payment';
        $pdo->prepare("
            UPDATE appointments 
            SET status = ?, 
                total_amount = ?,
                subtotal = ?,
                discount_amount = ?,
                vat_amount = ?,
                amount_paid = ?,
                payment_status = ?,
                finalized_at = NOW(),
                finalized_by = ?,
                updated_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ")->execute([
            $newStatus,
            $finalTotal,
            $subtotal,
            $discountAmount,
            $vatAmount,
            $totalPaid,
            ($totalPaid >= $finalTotal) ? 'paid' : 'partial',
            $user_id,
            $appointmentId,
            $clinic_id
        ]);
        
        // ✅ STEP 9: Stock deduction (with color inventory support)
        if (!empty($appointment['product_id'])) {
            $hasColorInventory = false;
            
            if (!empty($appointment['color_code'])) {
                // ✅ Contact Lens / Frame na may color variant
                $colorStmt = $pdo->prepare("
                    SELECT id, quantity 
                    FROM product_color_inventory 
                    WHERE product_id = ? 
                      AND clinic_id = ? 
                      AND color_code = ?
                      AND is_available = 1
                    LIMIT 1
                ");
                $colorStmt->execute([$appointment['product_id'], $clinic_id, $appointment['color_code']]);
                $colorRow = $colorStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($colorRow) {
                    $hasColorInventory = true;
                    
                    if ($colorRow['quantity'] > 0) {
                        $pdo->prepare("
                            UPDATE product_color_inventory 
                            SET quantity = quantity - 1,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$colorRow['id']]);
                        
                        error_log("Stock deducted (color): product_id={$appointment['product_id']}, color={$appointment['color_code']}");
                    } else {
                        error_log("WARNING: Out of stock for product {$appointment['product_id']} color {$appointment['color_code']}");
                    }
                }
            }
            
            // ✅ Fallback: general inventory
            if (!$hasColorInventory) {
                $pdo->prepare("
                    UPDATE inventory 
                    SET stock = stock - 1,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ? AND stock > 0
                ")->execute([$appointment['product_id'], $clinic_id]);
                
                error_log("Stock deducted (general): product_id={$appointment['product_id']}");
            }
        }
        
        // ✅ STEP 10: Log status change (optional, wrapped in try-catch)
        try {
            $pdo->prepare("
                INSERT INTO appointment_status_logs 
                    (appointment_id, status, changed_by, changed_at, notes)
                VALUES (?, ?, ?, NOW(), 'Order finalized by Sales via modal')
            ")->execute([$appointmentId, $newStatus, $user_id]);
        } catch (Exception $e) {
            // Log table might not exist
        }
        
        // ✅ STEP 11: Audit log
        logAudit($pdo, $user_id, $clinic_id, 'FINALIZE', 'sales', $saleId, null, [
            'appointment_id' => $appointmentId,
            'is_contact_lens' => $isContactLens,
            'is_eyeglass' => $isEyeglass,
            'total_amount' => $finalTotal,
            'amount_paid' => $amountPaid,
            'online_paid' => $onlinePaid,
            'total_paid' => $totalPaid,
            'status' => $status,
            'items' => $items
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'sale_id' => $saleId,
            'receipt_no' => $receiptNo,
            'total_amount' => $finalTotal,
            'amount_paid' => $amountPaid,
            'status' => $status,
            'is_contact_lens' => $isContactLens,
            'message' => 'Order finalized successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
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

    // ============= DEFAULT: Method not allowed =============
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}