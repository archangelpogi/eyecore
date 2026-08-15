<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Finance module (Professional or Enterprise plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('finance')) {
    header('Location: ../views/subscription.php');
    exit;
}

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check
if (!RBACHelper::hasPermission('sales_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Sales & Billing.</p>
        </div>
    </div>
    <?php
    exit;
}

// Get session data
$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'SCM';
$clinic_id = $_SESSION['clinic_id'];
$user_name = $_SESSION['name'] ?? 'User';

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('sales_view');
$canCreate = RBACHelper::hasPermission('sales_create');
$canEdit = RBACHelper::hasPermission('sales_edit');
$canDelete = RBACHelper::hasPermission('sales_delete');
$canApprove = RBACHelper::hasPermission('sales_approve');
$canReject = RBACHelper::hasPermission('sales_reject');

// Get filter parameters
$search = $_GET['search'] ?? '';
$today = date('Y-m-d');

// ✅ GET SYSTEM SETTINGS
$settingsStmt = $pdo->prepare("SELECT vat_rate, pwd_senior_discount, pwd_senior_vat_exempt FROM system_settings LIMIT 1");
$settingsStmt->execute();
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
$vat_rate = floatval($settings['vat_rate'] ?? 0.12);
$pwd_senior_discount = floatval($settings['pwd_senior_discount'] ?? 0.20);
$pwd_senior_vat_exempt = intval($settings['pwd_senior_vat_exempt'] ?? 1);

// ============================================
// ✅ FIXED: FETCH FROM BOTH APPOINTMENTS AND SALES
// ============================================
$query = "
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
        COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))) AS total_amount,
        a.amount_paid,
        a.status,
        a.ref_no,
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
        a.amount_paid,
        CASE 
            WHEN a.amount_paid >= COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))) 
                AND COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))) > 0 
            THEN 'Paid'
            WHEN a.amount_paid > 0 AND a.amount_paid < COALESCE(NULLIF(a.total_amount, 0), (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))) 
            THEN 'Partial'
            ELSE 'Unpaid'
        END AS payment_status,
        'appointment' AS source_type,
        COALESCE(
            (SELECT s3.items FROM sales s3 WHERE s3.appointment_id = a.id LIMIT 1),
            a.items,
            (
                SELECT CONCAT('[', GROUP_CONCAT(
                    JSON_OBJECT(
                        'name', s.name, 
                        'price', aps.price, 
                        'quantity', 1, 
                        'type', 'service'
                    )
                ), ']') 
                FROM appointment_services aps 
                JOIN services s ON aps.service_id = s.id 
                WHERE aps.appointment_id = a.id
            )
        ) AS items,
        d.name as doctor_name,
        NULL AS walk_in_name,
        NULL AS walk_in_contact,
        NULL AS walk_in_email
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE a.clinic_id = ?
    AND a.status IN ('paid', 'completed', 'confirmed', 'pending')
    
    UNION ALL
    
    SELECT 
        s.id,
        s.sale_date,
        NULL AS user_id,
        s.patient_id,
        s.subtotal,
        s.discount_type,
        s.discount_percentage,
        s.discount,
        s.vat_percentage,
        s.vat_amount,
        s.total_amount,
        s.amount_paid,
        s.status,
        NULL AS ref_no,
        CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id,
        COALESCE(s.walk_in_name, CONCAT(p2.first_name, ' ', p2.last_name), 'Walk-in') AS customer_name,
        COALESCE(s.patient_id, 'WALK-IN') AS customer_code,
        s.amount_paid,
        CASE 
            WHEN s.amount_paid >= s.total_amount THEN 'Paid'
            WHEN s.amount_paid > 0 AND s.amount_paid < s.total_amount THEN 'Partial'
            ELSE 'Unpaid'
        END AS payment_status,
        'walkin' AS source_type,
        s.items,
        NULL AS doctor_name,
        s.walk_in_name,
        s.walk_in_contact,
        s.walk_in_email
    FROM sales s
    LEFT JOIN patients p2 ON s.patient_id = p2.id
    WHERE s.clinic_id = ?
    AND s.appointment_id IS NULL
    AND s.status IN ('Paid', 'Partial', 'Unpaid')
";

$params = [$clinic_id, $clinic_id];

if (!empty($search)) {
    $query = "SELECT * FROM (" . $query . ") AS combined 
              WHERE customer_name LIKE ? 
                 OR invoice_id LIKE ? 
                 OR id LIKE ? 
                 OR walk_in_name LIKE ?";
    $searchTerm = "%$search%";
    $params = array_merge([$clinic_id, $clinic_id], [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $query .= " ORDER BY sale_date DESC LIMIT 20";
} else {
    $query .= " ORDER BY sale_date DESC LIMIT 20";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ✅ FETCH STATS FROM BOTH APPOINTMENTS AND SALES
// ============================================
$statsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN sale_date = ? AND status = 'Paid' THEN total_amount END), 0) as today_sales,
        COUNT(CASE WHEN sale_date = ? AND status = 'Paid' THEN 1 END) as today_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN status = 'Partial' THEN total_amount END), 0) as partial_amount,
        COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN total_amount END), 0) as unpaid_amount
    FROM (
        SELECT 
            appointment_date as sale_date,
            CASE 
                WHEN a.amount_paid >= (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Paid'
                WHEN a.amount_paid > 0 THEN 'Partial'
                ELSE 'Unpaid'
            END as status,
            CASE 
                WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
                ELSE a.total_amount
            END as total_amount
        FROM appointments a
        WHERE a.clinic_id = ?
        AND a.status IN ('paid', 'completed', 'confirmed', 'pending')
        
        UNION ALL
        
        SELECT 
            sale_date,
            CASE 
                WHEN amount_paid >= total_amount THEN 'Paid'
                WHEN amount_paid > 0 THEN 'Partial'
                ELSE 'Unpaid'
            END as status,
            total_amount
        FROM sales s
        WHERE s.clinic_id = ?
        AND s.appointment_id IS NULL
        AND s.status IN ('Paid', 'Partial', 'Unpaid')
    ) combined
");
$statsStmt->execute([$today, $today, $clinic_id, $clinic_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$stats['today_sales'] = $stats['today_sales'] ?? 0;
$stats['today_count'] = $stats['today_count'] ?? 0;
$stats['paid_amount'] = $stats['paid_amount'] ?? 0;
$stats['partial_amount'] = $stats['partial_amount'] ?? 0;
$stats['unpaid_amount'] = $stats['unpaid_amount'] ?? 0;

// ============================================
// ✅ FIXED: Fetch patients for dropdown
// ============================================
$patientsStmt = $pdo->prepare("
    SELECT 
        p.id, 
        CONCAT(p.first_name, ' ', p.last_name) as full_name, 
        p.email, 
        p.phone, 
        p.user_id,
        p.clinic_id,
        COALESCE(v.status, 'none') as verified_status,
        v.verification_type
    FROM patients p
    LEFT JOIN user_verifications v ON p.user_id = v.user_id AND v.clinic_id = ? AND v.status = 'verified'
    WHERE p.clinic_id = ? AND p.status = 'Active' 
    ORDER BY p.first_name ASC
");
$patientsStmt->execute([$clinic_id, $clinic_id]);
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Debug: Log results
if (empty($patients)) {
    error_log("⚠️ WARNING: No patients found for clinic_id = " . $clinic_id);
    // Try a direct query without user_verifications join
    $checkStmt = $pdo->prepare("
        SELECT id, CONCAT(first_name, ' ', last_name) as full_name, email, phone, user_id
        FROM patients 
        WHERE clinic_id = ? AND status = 'Active'
    ");
    $checkStmt->execute([$clinic_id]);
    $patients = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Patients found without verification join: " . count($patients));
}

// Fetch PRODUCTS from inventory
$productsStmt = $pdo->prepare("
    SELECT id, name, selling_price as price, 'product' as item_type, stock
    FROM inventory 
    WHERE clinic_id = ? AND stock > 0 AND is_archived = 0
    ORDER BY name ASC
");
$productsStmt->execute([$clinic_id]);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch SERVICES from services table
$servicesStmt = $pdo->prepare("
    SELECT id, name, price, 'service' as item_type, NULL as stock
    FROM services 
    WHERE clinic_id = ? AND status = 'active'
    ORDER BY name ASC
");
$servicesStmt->execute([$clinic_id]);
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

$allItems = array_merge($products, $services);

// Process invoices with correct balance
foreach ($invoices as &$invoice) {
    $total_amount = $invoice['total_amount'] ?? 0;
    $amount_paid = $invoice['amount_paid'] ?? 0;
    $balance = $total_amount - $amount_paid;
    if ($balance < 0) {
        $balance = 0;
    }
    $invoice['balance'] = $balance;
    
    if ($amount_paid >= $total_amount && $total_amount > 0) {
        $invoice['payment_status'] = 'Paid';
    } elseif ($amount_paid > 0 && $amount_paid < $total_amount) {
        $invoice['payment_status'] = 'Partial';
    } else {
        $invoice['payment_status'] = 'Unpaid';
    }
}
unset($invoice);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Billing - EyeCore</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --primary-light: #99f6e4;
            --primary-bg: #f0fdfa;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --radius: 12px;
            --radius-sm: 8px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            padding: 24px;
        }

        /* Layout */
        .container {
            max-width: 1440px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header-left .icon-wrapper {
            width: 48px;
            height: 48px;
            background: var(--primary-bg);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 24px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.5px;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
            margin-top: 2px;
        }

        .page-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline-custom {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-outline-custom:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 20px 24px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
        }

        .stat-card:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-md);
        }

        .stat-card .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            margin-top: 4px;
        }

        .stat-card .stat-change {
            font-size: 13px;
            font-weight: 500;
            margin-top: 6px;
        }

        .stat-card .stat-change.up { color: #059669; }
        .stat-card .stat-change.down { color: #dc2626; }

        /* Toolbar - parang sa image */
        .toolbar {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-left .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toolbar-left .filter-group label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-600);
        }

        .toolbar-left .filter-group select,
        .toolbar-left .filter-group input {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 6px 12px;
            font-size: 13px;
            background: white;
            color: var(--gray-700);
            transition: all 0.2s;
        }

        .toolbar-left .filter-group select:focus,
        .toolbar-left .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
        }

        .toolbar-left .btn-reset {
            background: transparent;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 6px 16px;
            font-size: 13px;
            color: var(--gray-600);
            transition: all 0.2s;
        }

        .toolbar-left .btn-reset:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toolbar-right .entries-select {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--gray-600);
        }

        .toolbar-right .entries-select select {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
            font-size: 13px;
            background: white;
        }

        .toolbar-right .search-box {
            display: flex;
            align-items: center;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            padding: 0 12px;
            background: white;
            transition: all 0.2s;
        }

        .toolbar-right .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
        }

        .toolbar-right .search-box i {
            color: var(--gray-400);
            font-size: 14px;
        }

        .toolbar-right .search-box input {
            border: none;
            padding: 7px 10px;
            font-size: 13px;
            background: transparent;
            width: 200px;
            color: var(--gray-700);
        }

        .toolbar-right .search-box input:focus {
            outline: none;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table-custom thead th {
            background: var(--gray-50);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--gray-500);
            border-bottom: 1px solid var(--gray-200);
            white-space: nowrap;
        }

        .table-custom tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            color: var(--gray-700);
        }

        .table-custom tbody tr:hover {
            background: var(--primary-bg);
        }

        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-status .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
        }

        .badge-status.paid { background: #d1fae5; color: #065f46; }
        .badge-status.paid .dot { background: #10b981; }

        .badge-status.partial { background: #fef3c7; color: #92400e; }
        .badge-status.partial .dot { background: #f59e0b; }

        .badge-status.unpaid { background: #fee2e2; color: #991b1b; }
        .badge-status.unpaid .dot { background: #ef4444; }

        .badge-status.cancelled { background: #f1f5f9; color: #475569; }
        .badge-status.cancelled .dot { background: #94a3b8; }

        /* Table Footer - parang sa image */
        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-top: 1px solid var(--gray-200);
            background: white;
            border-radius: 0 0 var(--radius) var(--radius);
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-footer .info-text {
            font-size: 14px;
            color: var(--gray-500);
        }

        .table-footer .info-text strong {
            color: var(--gray-700);
        }

        .table-footer .pagination-custom {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .table-footer .pagination-custom button {
            padding: 6px 14px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            background: white;
            font-size: 13px;
            color: var(--gray-600);
            transition: all 0.2s;
        }

        .table-footer .pagination-custom button:hover:not(:disabled) {
            background: var(--gray-50);
            border-color: var(--gray-300);
        }

        .table-footer .pagination-custom button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .table-footer .pagination-custom button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 4px;
        }

        .action-btns .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            border: none;
            background: transparent;
            color: var(--gray-500);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .action-btns .btn-icon:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .action-btns .btn-icon.view:hover { color: var(--primary); background: var(--primary-bg); }
        .action-btns .btn-icon.print:hover { color: #6366f1; background: #eef2ff; }
        .action-btns .btn-icon.pay:hover { color: #059669; background: #d1fae5; }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            body { padding: 16px; }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .page-header-actions {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar-left {
                flex-wrap: wrap;
            }
            
            .toolbar-right {
                flex-wrap: wrap;
                justify-content: space-between;
            }
            
            .toolbar-right .search-box input {
                width: 140px;
            }
            
            .table-footer {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .toolbar-left .filter-group {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ============================================ -->
    <!-- PAGE HEADER -->
    <!-- ============================================ -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="icon-wrapper">
                <i class="bi bi-receipt"></i>
            </div>
            <div>
                <h1 class="page-title">Sales & Billing</h1>
                <p class="page-subtitle">Manage transactions, invoices, and payments</p>
            </div>
        </div>
        <div class="page-header-actions">
            <button class="btn-outline-custom" onclick="exportData()">
                <i class="bi bi-download"></i> Export
            </button>
            <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#newSaleModal">
                <i class="bi bi-plus-lg"></i> New Sale
            </button>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- STATS CARDS -->
    <!-- ============================================ -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Today's Sales</div>
            <div class="stat-value">₱<?php echo number_format($stats['today_sales'] ?? 0); ?></div>
            <div class="stat-change up">↑ <?php echo $stats['today_count'] ?? 0; ?> transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Paid</div>
            <div class="stat-value" style="color: #059669;">₱<?php echo number_format($stats['paid_amount'] ?? 0); ?></div>
            <div class="stat-change up">Fully paid invoices</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Balance</div>
            <div class="stat-value" style="color: #d97706;">₱<?php echo number_format($stats['partial_amount'] ?? 0); ?></div>
            <div class="stat-change" style="color: #d97706;">Awaiting payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unpaid</div>
            <div class="stat-value" style="color: #dc2626;">₱<?php echo number_format($stats['unpaid_amount'] ?? 0); ?></div>
            <div class="stat-change down">Overdue appointments</div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- TOOLBAR - PARANG SA IMAGE -->
    <!-- ============================================ -->
    <div class="toolbar">
        <div class="toolbar-left">
            <!-- Filter: All Status -->
            <div class="filter-group">
                <label>All Status</label>
                <select id="filterStatus">
                    <option value="all">All Status</option>
                    <option value="paid">Paid</option>
                    <option value="partial">Partial</option>
                    <option value="unpaid">Unpaid</option>
                </select>
            </div>

            <!-- Filter: All Payment Method -->
            <div class="filter-group">
                <label>All Payment</label>
                <select id="filterPayment">
                    <option value="all">All Payment</option>
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="paymaya">PayMaya</option>
                    <option value="credit_card">Credit Card</option>
                </select>
            </div>

            <!-- Reset Button -->
            <button class="btn-reset" onclick="resetFilters()">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
        </div>

        <div class="toolbar-right">
            <!-- Show Entries -->
            <div class="entries-select">
                Show
                <select id="entriesPerPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                entries
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" placeholder="Search..." onkeyup="handleSearch()">
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- TABLE - PARANG SA IMAGE -->
    <!-- ============================================ -->
<div class="table-card">
    <div class="table-wrapper">
        <table class="table-custom" id="salesTable">
            <thead>
                <tr>
                    <th>INVOICE</th>
                    <th>PATIENT</th>
                    <th>DATE</th>
                    <th>ITEMS</th>
                    <th>SUBTOTAL</th>  <!-- ✅ PINALITAN: TOTAL -> SUBTOTAL -->
                    <th>PAID</th>
                    <th>BALANCE</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--gray-400);">
                        <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 8px;"></i>
                        <?php echo empty($search) ? 'No invoices found.' : 'No invoices match your search.'; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($invoices as $invoice): 
                    $total_amount = $invoice['total_amount'] ?? 0;
                    $amount_paid = $invoice['amount_paid'] ?? 0;
                    $balance = $invoice['balance'] ?? 0;
                    $payment_status = $invoice['payment_status'] ?? 'Unpaid';
                    $statusClass = strtolower($payment_status);
                    
                    // ✅ Get subtotal from invoice
                    $subtotal = $invoice['subtotal'] ?? $total_amount;  // If subtotal is empty, use total_amount as fallback
                    
                    // Get items count
                    $items_raw = trim($invoice['items'] ?? '[]');
                    $items = json_decode($items_raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $items_raw = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $items_raw);
                        $items = json_decode($items_raw, true);
                    }
                    $itemCount = is_array($items) ? count($items) : 0;
                    $itemNames = [];
                    if (is_array($items) && !empty($items)) {
                        foreach ($items as $item) {
                            $itemNames[] = $item['name'] ?? $item['item_name'] ?? 'Item';
                        }
                    }
                ?>
                <tr>
                    <td>
                        <strong style="color: var(--primary);"><?php echo $invoice['invoice_id']; ?></strong>
                    </td>
                    <td>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                        <div style="font-size: 12px; color: var(--gray-400);"><?php echo $invoice['customer_code']; ?></div>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($invoice['sale_date'])); ?></td>
                    <td>
                        <div><?php echo $itemCount; ?> item<?php echo $itemCount > 1 ? 's' : ''; ?></div>
                        <?php if (!empty($itemNames)): ?>
                            <div style="font-size: 12px; color: var(--gray-400); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo htmlspecialchars(implode(', ', array_slice($itemNames, 0, 2))); ?>
                                <?php echo count($itemNames) > 2 ? '...' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 600;">₱<?php echo number_format($subtotal, 2); ?></td>  <!-- ✅ SUBTOTAL -->
                    <td style="color: #059669;">₱<?php echo number_format($amount_paid, 2); ?></td>
                    <td style="font-weight: 500; <?php echo $balance > 0 ? 'color: #dc2626;' : 'color: #059669;'; ?>">
                        ₱<?php echo number_format($balance, 2); ?>
                    </td>
                    <td>
                        <span class="badge-status <?php echo $statusClass; ?>">
                            <span class="dot"></span>
                            <?php echo $payment_status; ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-icon view" onclick="viewInvoice(<?php echo $invoice['id']; ?>, '<?php echo $invoice['source_type']; ?>')" title="View">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn-icon print" onclick="printInvoice(<?php echo $invoice['id']; ?>, '<?php echo $invoice['source_type']; ?>')" title="Print">
                                <i class="bi bi-printer"></i>
                            </button>
                            <?php if ($canEdit && $payment_status !== 'Paid'): ?>
                            <button class="btn-icon pay" onclick="recordPayment(<?php echo $invoice['id']; ?>, <?php echo $total_amount; ?>, <?php echo $amount_paid; ?>, '<?php echo $invoice['source_type']; ?>')" title="Record Payment">
                                <i class="bi bi-cash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Table Footer -->
    <div class="table-footer">
        <div class="info-text">
            Showing <strong>1</strong> to <strong>1</strong> of <strong>1</strong> entries
        </div>
        <div class="pagination-custom">
            <button disabled>Previous</button>
            <button class="active">1</button>
            <button disabled>Next</button>
        </div>
    </div>
</div>

</div>

<!-- New Sale Modal - SCROLLABLE VERSION -->
<div class="modal fade" id="newSaleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; max-height: 95vh;">
            <form id="newSaleForm">
                <!-- FIXED HEADER -->
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: 20px 20px 0 0; flex-shrink: 0;">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-receipt me-2"></i>New Sale / Invoice
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <!-- SCROLLABLE BODY -->
                <div class="modal-body p-4" style="overflow-y: auto; max-height: calc(95vh - 180px);">
                    <!-- Customer Type -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Customer Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="customer_type" id="registered_patient" value="registered" checked>
                            <label class="btn btn-outline-primary" for="registered_patient">Registered Patient</label>
                            
                            <input type="radio" class="btn-check" name="customer_type" id="walk_in_customer" value="walkin">
                            <label class="btn btn-outline-primary" for="walk_in_customer">Walk-in Customer</label>
                        </div>
                    </div>

                    <!-- PWD/SENIOR VERIFICATION DISPLAY -->
                    <div id="pwd_senior_status" style="display: none; padding: 10px; border-radius: 8px; margin-bottom: 15px;"></div>

                    <!-- Registered Patient Section -->
                    <div id="registered_section">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Patient</label>
                            <select class="form-select patient-select" name="patient_id" id="patient_select" style="width: 100%;" onchange="checkPwdSenior(this.value)">
                                <option value="" disabled selected>Search and select patient...</option>
                                <?php if(!empty($patients)): ?>
                                    <?php foreach($patients as $patient): ?>
                                        <option value="<?php echo $patient['id']; ?>" 
                                                data-email="<?php echo $patient['email']; ?>"
                                                data-phone="<?php echo $patient['phone']; ?>"
                                                data-user-id="<?php echo $patient['user_id']; ?>">
                                            <?php echo htmlspecialchars($patient['full_name']); ?> 
                                            <?php if(!empty($patient['email'])): ?>
                                                (<?php echo htmlspecialchars($patient['email']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No patients found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <!-- Manual Discount for Registered -->
                        <div id="manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-id-card text-warning me-2"></i>
                                <strong class="text-warning">Manual Discount (Unverified Patient)</strong>
                            </div>
                            <p class="small text-muted">Patient must present physical PWD/Senior ID before applying discount.</p>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Discount Type</label>
                                    <select class="form-select" id="manual_discount_type" name="manual_discount_type" onchange="calculateTotal()">
                                        <option value="senior">👴 Senior Citizen</option>
                                        <option value="pwd">♿ PWD</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Discount %</label>
                                    <input type="number" class="form-control" id="manual_discount" name="manual_discount" value="0" min="0" max="100" step="0.01" onchange="calculateTotal()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Verified By</label>
                                    <input type="text" class="form-control" id="verified_by" name="verified_by" placeholder="Staff name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">ID Number</label>
                                    <input type="text" class="form-control" id="id_number" name="id_number" placeholder="PWD/Senior ID">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Walk-in Customer Section -->
                    <div id="walkin_section" style="display: none;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="walk_in_name" id="walk_in_name" placeholder="Enter customer name" oninput="checkWalkInPwdSenior(this.value)" onchange="checkWalkInPwdSenior(this.value)">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Contact Number</label>
                                <input type="text" class="form-control" name="walk_in_contact" placeholder="Optional">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-semibold">Email Address</label>
                                <input type="email" class="form-control" name="walk_in_email" placeholder="Optional">
                            </div>
                        </div>
                        
                        <!-- Manual Discount for Walk-in -->
                        <div id="walkin_manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-id-card text-warning me-2"></i>
                                <strong class="text-warning">Manual Discount (Walk-in with Physical ID)</strong>
                            </div>
                            <p class="small text-muted">Customer must present physical PWD/Senior ID before applying discount.</p>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Discount Type</label>
                                    <select class="form-select" id="walkin_manual_discount_type" name="walkin_manual_discount_type" onchange="calculateTotal()">
                                        <option value="senior">👴 Senior Citizen</option>
                                        <option value="pwd">♿ PWD</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Discount %</label>
                                    <input type="number" class="form-control" id="walkin_manual_discount" name="walkin_manual_discount" value="0" min="0" max="100" step="0.01" onchange="calculateTotal()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Verified By</label>
                                    <input type="text" class="form-control" id="walkin_verified_by" name="walkin_verified_by" placeholder="Staff name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">ID Number</label>
                                    <input type="text" class="form-control" id="walkin_id_number" name="walkin_id_number" placeholder="PWD/Senior ID">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Add Items (Products & Services)</label>
                        <div class="input-group mb-2">
                            <select class="form-select item-select" id="itemSelect" style="width: 100%;">
                                <option value="" disabled selected>Search and select item...</option>
                                <optgroup label="Products">
                                    <?php foreach($products as $item): ?>
                                        <option value="<?php echo $item['id']; ?>" 
                                                data-price="<?php echo $item['price']; ?>"
                                                data-type="product"
                                                data-stock="<?php echo $item['stock']; ?>">
                                            <?php echo htmlspecialchars($item['name']); ?> (₱<?php echo number_format($item['price'], 2); ?>) - Stock: <?php echo $item['stock']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Services">
                                    <?php foreach($services as $service): ?>
                                        <option value="<?php echo $service['id']; ?>" 
                                                data-price="<?php echo $service['price']; ?>"
                                                data-type="service">
                                            <?php echo htmlspecialchars($service['name']); ?> (₱<?php echo number_format($service['price'], 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <button type="button" class="btn btn-primary" onclick="addItem()">Add</button>
                        </div>
                    </div>

                    <!-- Items Table - SCROLLABLE -->
                    <div class="mb-3">
                        <div class="table-responsive" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--gray-200); border-radius: var(--radius-sm);">
                            <table class="table table-sm mb-0" id="itemsTable">
                                <thead class="table-light" style="position: sticky; top: 0; z-index: 10;">
                                    <tr>
                                        <th>ITEM</th>
                                        <th>TYPE</th>
                                        <th>PRICE</th>
                                        <th>QTY</th>
                                        <th>TOTAL</th>
                                        <th>ACTION</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsList"></tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="4" class="text-end fw-semibold">Subtotal:</td>
                                        <td id="subtotal" class="fw-bold">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr id="discountRow" style="display: none;">
                                        <td colspan="4" class="text-end fw-semibold text-success">
                                            <span id="discountLabel">Discount:</span>
                                        </td>
                                        <td id="discountDisplay" class="fw-bold text-success">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr id="vatRow">
                                        <td colspan="4" class="text-end fw-semibold text-warning">VAT (12%):</td>
                                        <td id="vatDisplay" class="fw-bold text-warning">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td colspan="4" class="text-end fw-bold">Total:</td>
                                        <td id="totalAmount" class="fw-bold text-primary fs-5">₱0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Payment Section -->
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-select" name="payment_method" id="payment_method" required onchange="togglePaymentFields()">
                                <option value="cash">Cash</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="gcash">GCash</option>
                                <option value="paymaya">PayMaya</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount Paid</label>
                            <input type="number" class="form-control" name="amount_paid" step="0.01" min="0" value="0" oninput="updateStatus()" required>
                        </div>
                        <div class="col-md-4" id="reference_field" style="display: none;">
                            <label class="form-label fw-semibold">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="e.g., GCash ref no.">
                        </div>
                    </div>

                    <!-- Hidden Inputs -->
                    <input type="hidden" name="items" id="itemsInput">
                    <input type="hidden" name="subtotal" id="subtotalInput">
                    <input type="hidden" name="discount" id="discountInput">
                    <input type="hidden" name="vat" id="vatInput">
                    <input type="hidden" name="total" id="totalInput">
                    <input type="hidden" name="status" id="statusInput" value="Unpaid">
                    <input type="hidden" name="payment_type" id="paymentTypeInput" value="full">
                </div>
                
                <!-- FIXED FOOTER -->
                <div class="modal-footer border-0 pb-4" style="flex-shrink: 0; background: white; border-radius: 0 0 20px 20px;">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-cash me-2"></i>Record Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="recordPaymentForm">
                    <input type="hidden" name="appointment_id" id="payment_appointment_id">
                    <input type="hidden" name="source_type" id="payment_source_type" value="appointment">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Invoice ID</label>
                        <input type="text" class="form-control bg-light" id="payment_invoice_id" readonly>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Total Amount</label>
                            <input type="text" class="form-control bg-light" id="payment_total_amount" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Already Paid</label>
                            <input type="text" class="form-control bg-light" id="payment_already_paid" readonly>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-warning">Remaining Balance</label>
                        <input type="text" class="form-control bg-light text-warning fw-bold" id="payment_remaining_balance" readonly>
                    </div>
                    <div id="payment_manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-id-card text-warning me-2"></i>
                            <strong class="text-warning">Manual Discount (Unverified Patient)</strong>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Discount Type</label>
                                <select class="form-select" id="payment_manual_discount_type" name="payment_manual_discount_type">
                                    <option value="senior">👴 Senior Citizen</option>
                                    <option value="pwd">♿ PWD</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Discount %</label>
                                <input type="number" class="form-control" id="payment_manual_discount" name="payment_manual_discount" value="0" min="0" max="100" step="0.01">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Verified By</label>
                                <input type="text" class="form-control" id="payment_verified_by" name="payment_verified_by" placeholder="Staff name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">ID Number</label>
                                <input type="text" class="form-control" id="payment_id_number" name="payment_id_number" placeholder="PWD/Senior ID">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Amount</label>
                        <input type="number" class="form-control" name="amount" id="payment_amount" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select class="form-select" name="payment_method" id="payment_method_modal" required onchange="toggleModalReferenceField()">
                            <option value="cash">Cash</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="gcash">GCash</option>
                            <option value="paymaya">PayMaya</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3" id="modal_reference_field" style="display: none;">
                        <label class="form-label fw-semibold">Reference Number</label>
                        <input type="text" class="form-control" name="reference_number" placeholder="e.g., GCash ref no.">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitPayment()">Record Payment</button>
            </div>
        </div>
    </div>
</div>

<!-- View Invoice Modal -->
<div class="modal fade" id="viewInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-receipt me-2"></i>Invoice Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="invoiceDetails">
                <div class="text-center py-5">
                    <div class="spinner-border text-teal" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading invoice details...</p>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- SCRIPTS -->
<!-- ============================================ -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ============================================
// PERMISSIONS
// ============================================
const permissions = {
    canView: <?php echo json_encode($canView); ?>,
    canCreate: <?php echo json_encode($canCreate); ?>,
    canEdit: <?php echo json_encode($canEdit); ?>,
    canDelete: <?php echo json_encode($canDelete); ?>,
    canApprove: <?php echo json_encode($canApprove); ?>,
    canReject: <?php echo json_encode($canReject); ?>
};

const currentUserId = <?php echo json_encode($current_user_id); ?>;
let saleItems = [];
let allInvoices = <?php echo json_encode($invoices); ?>;

// ============================================
// INIT
// ============================================
$(document).ready(function() {
    $('.patient-select').select2({
        placeholder: 'Search patient by name or email...',
        allowClear: true,
        dropdownParent: $('#newSaleModal')
    });
    
    $('#itemSelect').select2({
        placeholder: 'Search product or service...',
        dropdownParent: $('#newSaleModal')
    });
    
    // Filter listeners
    $('#filterStatus, #filterPayment, #entriesPerPage').on('change', function() {
        filterTable();
    });
});

// ============================================
// FILTER FUNCTIONS
// ============================================
function filterTable() {
    const status = $('#filterStatus').val();
    const payment = $('#filterPayment').val();
    const search = $('#searchInput').val().toLowerCase();
    const entries = parseInt($('#entriesPerPage').val());
    
    let filtered = allInvoices.filter(function(inv) {
        let match = true;
        
        if (status !== 'all' && inv.payment_status?.toLowerCase() !== status) {
            match = false;
        }
        
        if (payment !== 'all') {
            // Check payment method if available
            const invPayment = inv.payment_method || 'cash';
            if (invPayment.toLowerCase() !== payment) {
                match = false;
            }
        }
        
        if (search) {
            const searchable = [
                inv.invoice_id || '',
                inv.customer_name || '',
                inv.customer_code || '',
                inv.walk_in_name || ''
            ].join(' ').toLowerCase();
            if (!searchable.includes(search)) {
                match = false;
            }
        }
        
        return match;
    });
    
    // Pagination
    const total = filtered.length;
    const totalPages = Math.ceil(total / entries);
    const currentPage = 1; // Start at page 1
    
    const start = 0;
    const end = Math.min(entries, total);
    const paged = filtered.slice(start, end);
    
    // Render table
    renderTableRows(paged);
    renderPagination(currentPage, totalPages, total);
}

function resetFilters() {
    $('#filterStatus').val('all');
    $('#filterPayment').val('all');
    $('#searchInput').val('');
    $('#entriesPerPage').val('10');
    filterTable();
}

function handleSearch() {
    filterTable();
}

function renderTableRows(rows) {
    const tbody = document.getElementById('tableBody');
    
    if (rows.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--gray-400);">
                    <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 8px;"></i>
                    No invoices found.
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    rows.forEach(function(invoice) {
        const total_amount = invoice.total_amount || 0;
        const amount_paid = invoice.amount_paid || 0;
        const balance = invoice.balance || 0;
        const payment_status = invoice.payment_status || 'Unpaid';
        const statusClass = payment_status.toLowerCase();
        
        // Parse items
        let items = [];
        try {
            const itemsRaw = invoice.items || '[]';
            items = typeof itemsRaw === 'string' ? JSON.parse(itemsRaw) : itemsRaw;
        } catch(e) {
            items = [];
        }
        const itemCount = Array.isArray(items) ? items.length : 0;
        const itemNames = Array.isArray(items) ? items.map(function(item) { return item.name || item.item_name || 'Item'; }) : [];
        
        html += `
            <tr>
                <td>
                    <strong style="color: var(--primary);">${invoice.invoice_id || 'N/A'}</strong>
                </td>
                <td>
                    <div style="font-weight: 500;">${escapeHtml(invoice.customer_name || 'N/A')}</div>
                    <div style="font-size: 12px; color: var(--gray-400);">${escapeHtml(invoice.customer_code || 'WALK-IN')}</div>
                </td>
                <td>${invoice.sale_date ? new Date(invoice.sale_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</td>
                <td>
                    <div>${itemCount} item${itemCount > 1 ? 's' : ''}</div>
                    ${itemNames.length > 0 ? `<div style="font-size: 12px; color: var(--gray-400); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(itemNames.slice(0, 2).join(', '))}${itemNames.length > 2 ? '...' : ''}</div>` : ''}
                </td>
                <td style="font-weight: 600;">₱${Number(total_amount).toFixed(2)}</td>
                <td style="color: #059669;">₱${Number(amount_paid).toFixed(2)}</td>
                <td style="font-weight: 500; ${balance > 0 ? 'color: #dc2626;' : 'color: #059669;'}">
                    ₱${Number(balance).toFixed(2)}
                </td>
                <td>
                    <span class="badge-status ${statusClass}">
                        <span class="dot"></span>
                        ${payment_status}
                    </span>
                </td>
                <td>
                    <div class="action-btns">
                        <button class="btn-icon view" onclick="viewInvoice(${invoice.id}, '${invoice.source_type || 'appointment'}')" title="View">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn-icon print" onclick="printInvoice(${invoice.id}, '${invoice.source_type || 'appointment'}')" title="Print">
                            <i class="bi bi-printer"></i>
                        </button>
                        ${permissions.canEdit && payment_status !== 'Paid' ? `
                        <button class="btn-icon pay" onclick="recordPayment(${invoice.id}, ${Number(total_amount)}, ${Number(amount_paid)}, '${invoice.source_type || 'appointment'}')" title="Record Payment">
                            <i class="bi bi-cash"></i>
                        </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function renderPagination(currentPage, totalPages, total) {
    const start = (currentPage - 1) * parseInt($('#entriesPerPage').val()) + 1;
    const end = Math.min(currentPage * parseInt($('#entriesPerPage').val()), total);
    
    // Update info text
    const infoText = document.querySelector('.table-footer .info-text');
    if (infoText) {
        infoText.innerHTML = `Showing <strong>${total > 0 ? start : 0}</strong> to <strong>${end}</strong> of <strong>${total}</strong> entries`;
    }
    
    // Update pagination buttons
    const paginationDiv = document.querySelector('.table-footer .pagination-custom');
    if (paginationDiv) {
        let html = `
            <button ${currentPage <= 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">Previous</button>
        `;
        
        for (let i = 1; i <= Math.min(totalPages, 10); i++) {
            html += `<button class="${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
        }
        
        if (totalPages > 10) {
            html += `<span style="color: var(--gray-400); padding: 0 8px;">...</span>`;
            html += `<button onclick="goToPage(${totalPages})">${totalPages}</button>`;
        }
        
        html += `
            <button ${currentPage >= totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">Next</button>
        `;
        
        paginationDiv.innerHTML = html;
    }
}

function goToPage(page) {
    // This would implement full pagination
    // For now, just filter again
    filterTable();
}

// ============================================
// MODAL FUNCTIONS (same as original)
// ============================================

// Toggle customer type
document.querySelectorAll('input[name="customer_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        if(this.value === 'registered') {
            document.getElementById('registered_section').style.display = 'block';
            document.getElementById('walkin_section').style.display = 'none';
        } else {
            document.getElementById('registered_section').style.display = 'none';
            document.getElementById('walkin_section').style.display = 'block';
        }
    });
});

// Toggle payment fields
function togglePaymentFields() {
    var method = document.getElementById('payment_method').value;
    document.getElementById('reference_field').style.display = method === 'cash' ? 'none' : 'block';
}

function toggleModalReferenceField() {
    var method = document.getElementById('payment_method_modal').value;
    document.getElementById('modal_reference_field').style.display = method === 'cash' ? 'none' : 'block';
}

// Add item
function addItem() {
    var select = document.getElementById('itemSelect');
    var selected = select.options[select.selectedIndex];
    if(!selected.value) return;
    
    var itemType = selected.dataset.type;
    var itemPrice = parseFloat(selected.dataset.price);
    
    if(itemType === 'product') {
        var stock = parseInt(selected.dataset.stock) || 0;
        if(stock <= 0) {
            Swal.fire('Error!', 'This item is out of stock', 'error');
            return;
        }
    }
    
    var existing = saleItems.find(function(item) {
        return item.id == selected.value && item.type == itemType;
    });
    
    if(existing) {
        if(itemType === 'product') {
            var stock = parseInt(selected.dataset.stock) || 0;
            if(existing.quantity + 1 > stock) {
                Swal.fire('Error!', 'Not enough stock available', 'error');
                return;
            }
        }
        existing.quantity += 1;
    } else {
        var name = selected.text.split(' (')[0];
        saleItems.push({
            id: selected.value,
            name: name,
            price: itemPrice,
            quantity: 1,
            type: itemType,
            stock: itemType === 'product' ? parseInt(selected.dataset.stock) : null
        });
    }
    
    updateItemsList();
    $('#itemSelect').val(null).trigger('change');
}

// Update items list
function updateItemsList() {
    var tbody = document.getElementById('itemsList');
    tbody.innerHTML = '';
    
    saleItems.forEach(function(item, index) {
        var total = item.price * item.quantity;
        var typeBadge = item.type === 'product' ? 
            '<span class="badge bg-primary">Product</span>' : 
            '<span class="badge bg-success">Service</span>';
        
        tbody.innerHTML += `
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>${typeBadge}</td>
                <td>₱${item.price.toFixed(2)}</td>
                <td>
                    <input type="number" class="form-control form-control-sm" 
                           value="${item.quantity}" min="1" 
                           max="${item.type === 'product' ? item.stock : ''}"
                           onchange="updateQuantity(${index}, this.value)">
                </td>
                <td class="fw-bold">₱${total.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    calculateTotal();
}

function updateQuantity(index, quantity) {
    quantity = parseInt(quantity) || 1;
    var item = saleItems[index];
    
    if(item.type === 'product' && quantity > item.stock) {
        Swal.fire('Error!', 'Only ' + item.stock + ' item(s) available in stock', 'error');
        quantity = item.stock;
    }
    
    saleItems[index].quantity = Math.max(1, quantity);
    updateItemsList();
}

function removeItem(index) {
    saleItems.splice(index, 1);
    updateItemsList();
}

// ============================================
// CALCULATE TOTAL
// ============================================
function calculateTotal() {
    let subtotal = 0;
    saleItems.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    // Check manual discounts
    var manualDiscountPercentRegistered = parseFloat(document.getElementById('manual_discount').value) || 0;
    var manualDiscountPercentWalkin = parseFloat(document.getElementById('walkin_manual_discount').value) || 0;
    var manualDiscountPercent = manualDiscountPercentRegistered || manualDiscountPercentWalkin;
    var hasManualDiscount = manualDiscountPercent > 0;
    
    let isPwdSenior = window.isPwdSeniorVerified === true;
    let isWalkInVerified = window.isWalkInPwdSenior === true;
    
    if (hasManualDiscount) {
        isWalkInVerified = true;
    }
    
    const discountRate = <?php echo $pwd_senior_discount ?? 0.20; ?>;
    const vatRate = <?php echo $vat_rate ?? 0.12; ?>;
    
    let discount = 0;
    let vat = 0;
    let total = subtotal;
    let discountType = 'none';
    let discountPercentage = 0;
    
    if (isPwdSenior || isWalkInVerified) {
        if (hasManualDiscount && manualDiscountPercent > 0) {
            discount = subtotal * (manualDiscountPercent / 100);
            discountType = 'manual';
            discountPercentage = manualDiscountPercent;
        } else {
            discount = subtotal * discountRate;
            discountType = 'senior';
            discountPercentage = discountRate * 100;
        }
        total = subtotal - discount;
        vat = 0;
    } else {
        vat = subtotal * vatRate;
        total = subtotal + vat;
    }
    
    // Build discount label
    var discountLabelText = '';
    var isWalkInSelected = document.querySelector('input[name="customer_type"]:checked')?.value === 'walkin';
    var isRegisteredSelected = document.querySelector('input[name="customer_type"]:checked')?.value === 'registered';
    var discountTypeDisplay = 'senior';
    var discountTypeIcon = '👴';
    var discountTypeLabel = 'Senior';
    
    if (discountType === 'manual' && hasManualDiscount) {
        var walkinTypeSelect = document.getElementById('walkin_manual_discount_type');
        var typeSelect = document.getElementById('manual_discount_type');
        
        if (isWalkInSelected && walkinTypeSelect && walkinTypeSelect.value) {
            discountTypeDisplay = walkinTypeSelect.value;
        } else if (isRegisteredSelected && typeSelect && typeSelect.value) {
            discountTypeDisplay = typeSelect.value;
        } else if (typeSelect && typeSelect.value) {
            discountTypeDisplay = typeSelect.value;
        } else if (walkinTypeSelect && walkinTypeSelect.value) {
            discountTypeDisplay = walkinTypeSelect.value;
        }
        
        discountTypeIcon = discountTypeDisplay === 'pwd' ? '♿' : '👴';
        discountTypeLabel = discountTypeDisplay === 'pwd' ? 'PWD' : 'Senior';
        discountLabelText = 'Manual ' + discountTypeIcon + ' ' + discountTypeLabel + ' Discount';
    } else if (isPwdSenior || isWalkInVerified) {
        var statusDiv = document.getElementById('pwd_senior_status');
        var statusText = statusDiv ? statusDiv.innerText : '';
        
        if (statusText.includes('PWD')) {
            discountTypeDisplay = 'pwd';
            discountTypeIcon = '♿';
            discountTypeLabel = 'PWD';
        } else if (statusText.includes('SENIOR')) {
            discountTypeDisplay = 'senior';
            discountTypeIcon = '👴';
            discountTypeLabel = 'Senior';
        } else if (window.pwdSeniorType === 'pwd') {
            discountTypeDisplay = 'pwd';
            discountTypeIcon = '♿';
            discountTypeLabel = 'PWD';
        }
        discountLabelText = discountTypeIcon + ' ' + discountTypeLabel + ' Discount (Verified)';
    } else {
        discountLabelText = 'Discount';
    }
    
    // Update UI
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('totalAmount').textContent = '₱' + total.toFixed(2);
    document.getElementById('vatDisplay').textContent = '₱' + vat.toFixed(2);
    document.getElementById('discountDisplay').textContent = '₱' + discount.toFixed(2);
    
    var discountRow = document.getElementById('discountRow');
    var discountLabel = document.getElementById('discountLabel');
    
    if (discount > 0) {
        discountRow.style.display = 'table-row';
        discountLabel.textContent = discountLabelText + ' (' + discountPercentage + '%):';
    } else {
        discountRow.style.display = 'none';
    }
    
    var vatRow = document.getElementById('vatRow');
    if (vat > 0) {
        vatRow.style.display = 'table-row';
    } else {
        vatRow.style.display = 'none';
    }
    
    document.getElementById('itemsInput').value = JSON.stringify(saleItems);
    document.getElementById('subtotalInput').value = subtotal;
    document.getElementById('discountInput').value = discount;
    document.getElementById('vatInput').value = vat;
    document.getElementById('totalInput').value = total;
    
    updateStatus();
}

function updateStatus() {
    var amountPaid = parseFloat(document.querySelector('input[name="amount_paid"]').value) || 0;
    var total = parseFloat(document.getElementById('totalInput').value) || 0;
    
    var status = 'Unpaid';
    if(amountPaid >= total && total > 0) {
        status = 'Paid';
    } else if(amountPaid > 0 && amountPaid < total) {
        status = 'Partial';
    }
    
    document.getElementById('statusInput').value = status;
    document.getElementById('paymentTypeInput').value = amountPaid >= total ? 'full' : 'partial';
}

// ============================================
// CHECK PWD/SENIOR STATUS
// ============================================
function checkPwdSenior(patientId) {
    if (!patientId) {
        document.getElementById('pwd_senior_status').style.display = 'none';
        document.getElementById('manual_discount_section').style.display = 'none';
        document.getElementById('manual_discount').disabled = false;
        window.isPwdSeniorVerified = false;
        calculateTotal();
        return;
    }
    
    var url = 'api/sales.php?action=check_pwd_senior&patient_id=' + patientId + '&clinic_id=' + <?php echo $clinic_id; ?>;
    
    fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(response) {
        var data = response.data && response.data.length > 0 ? response.data[0] : response;
        
        var statusDiv = document.getElementById('pwd_senior_status');
        var manualDiv = document.getElementById('manual_discount_section');
        var discountInput = document.getElementById('manual_discount');
        
        if (data.is_pwd_senior) {
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#d1fae5';
            statusDiv.style.border = '2px solid #10b981';
            statusDiv.style.borderRadius = '8px';
            statusDiv.style.padding = '12px';
            statusDiv.innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-check-circle" style="color: #10b981; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #065f46;">✅ VERIFIED ' + (data.verification_type || '').toUpperCase() + '</span>' +
                    '<span class="ms-2 badge bg-success">' + (data.discount_percentage || 20) + '% Discount</span>' +
                    '<span class="ms-2 badge bg-info">VAT Exempt</span>' +
                    '<span class="ms-2 text-muted small">(Auto-applied - No manual override needed)</span>' +
                '</div>';
            
            manualDiv.style.display = 'none';
            discountInput.value = 0;
            discountInput.disabled = true;
            window.isPwdSeniorVerified = true;
            window.pwdSeniorType = data.verification_type || 'senior';
            
        } else {
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#fef3c7';
            statusDiv.style.border = '2px solid #f59e0b';
            statusDiv.style.borderRadius = '8px';
            statusDiv.style.padding = '12px';
            statusDiv.innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #92400e;">⚠️ NOT VERIFIED</span>' +
                    '<span class="ms-2 text-muted small">No PWD/Senior verification found</span>' +
                '</div>';
            
            manualDiv.style.display = 'block';
            discountInput.disabled = false;
            discountInput.value = 0;
            window.isPwdSeniorVerified = false;
        }
        calculateTotal();
    })
    .catch(function(err) {
        console.error('Error:', err);
        window.isPwdSeniorVerified = false;
        calculateTotal();
    });
}

function checkWalkInPwdSenior(name) {
    if (!name || name.length < 3) {
        document.getElementById('pwd_senior_status').style.display = 'none';
        document.getElementById('walkin_manual_discount_section').style.display = 'none';
        document.getElementById('walkin_manual_discount').disabled = false;
        window.isWalkInPwdSenior = false;
        calculateTotal();
        return;
    }
    
    fetch('api/sales.php?action=check_pwd_senior_by_name&name=' + encodeURIComponent(name) + '&clinic_id=' + <?php echo $clinic_id; ?>, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        var responseData = data.data && data.data.length > 0 ? data.data[0] : data;
        
        var statusDiv = document.getElementById('pwd_senior_status');
        var manualDiv = document.getElementById('walkin_manual_discount_section');
        var discountInput = document.getElementById('walkin_manual_discount');
        
        if (responseData.success && responseData.is_pwd_senior) {
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#d1fae5';
            statusDiv.style.border = '2px solid #10b981';
            statusDiv.style.borderRadius = '8px';
            statusDiv.style.padding = '12px';
            statusDiv.innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-check-circle" style="color: #10b981; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #065f46;">✅ VERIFIED ' + (responseData.verification_type || '').toUpperCase() + '</span>' +
                    '<span class="ms-2 badge bg-success">' + (responseData.discount_percentage || 20) + '% Discount</span>' +
                    '<span class="ms-2 badge bg-info">VAT Exempt</span>' +
                    '<span class="ms-2 text-muted small">(Auto-applied - No manual override needed)</span>' +
                '</div>';
            
            manualDiv.style.display = 'none';
            discountInput.value = 0;
            discountInput.disabled = true;
            window.isWalkInPwdSenior = true;
            window.pwdSeniorType = responseData.verification_type || 'senior';
            
        } else {
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#fef3c7';
            statusDiv.style.border = '2px solid #f59e0b';
            statusDiv.style.borderRadius = '8px';
            statusDiv.style.padding = '12px';
            statusDiv.innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #92400e;">⚠️ NO RECORD FOUND</span>' +
                    '<span class="ms-2 text-muted small">Manual discount available with physical ID</span>' +
                '</div>';
            
            manualDiv.style.display = 'block';
            discountInput.disabled = false;
            discountInput.value = 0;
            window.isWalkInPwdSenior = false;
        }
        calculateTotal();
    })
    .catch(function(err) {
        console.error('Error:', err);
        window.isWalkInPwdSenior = false;
        calculateTotal();
    });
}

// ============================================
// VIEW / PRINT / PAYMENT FUNCTIONS
// ============================================

function viewInvoice(id, sourceType) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to view invoices', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Loading...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });
    
    var endpoint = sourceType === 'walkin' ? 'api/sales.php?id=' + id : 'api/sales.php?appointment_id=' + id;
    
    fetch(endpoint)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        Swal.close();
        
        if (data.success && data.bill) {
            showBillFromAppointment(data.bill);
        } else {
            Swal.fire('Error!', data.message || 'Invoice not found.', 'error');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire('Error!', 'Error loading invoice.', 'error');
    });
}

function showBillFromAppointment(bill) {
    var totalAmount = parseFloat(bill.total_amount || 0);
    var totalPaid = parseFloat(bill.amount_paid || bill.total_paid || 0);
    var subtotal = parseFloat(bill.subtotal || 0);
    var discountAmount = parseFloat(bill.discount_amount || 0);
    var discountPercentage = parseFloat(bill.discount_percentage || 0);
    var discountType = bill.discount_type || 'none';
    var vatAmount = parseFloat(bill.vat_amount || 0);
    var vatPercentage = parseFloat(bill.vat_percentage || 0);
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;
    
    var items = bill.items || [];
    if (typeof items === 'string') {
        try { items = JSON.parse(items); } catch(e) { items = []; }
    }
    if (!Array.isArray(items)) items = [];
    
    var status = 'Unpaid';
    if (totalPaid >= totalAmount && totalAmount > 0) {
        status = 'Paid';
    } else if (totalPaid > 0 && totalPaid < totalAmount) {
        status = 'Partial';
    }
    
    var statusClass = status === 'Paid' ? 'success' : status === 'Partial' ? 'warning' : 'danger';
    
    // Build items HTML
    var itemsHtml = '';
    if (items.length === 0) {
        itemsHtml = '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
    } else {
        items.forEach(function(item) {
            var typeBadge = item.item_type === 'product' ? 
                '<span class="badge bg-primary">Product</span>' : 
                '<span class="badge bg-success">Service</span>';
            var price = parseFloat(item.unit_price || item.price || 0);
            var qty = parseInt(item.quantity || 1);
            var itemName = item.item_name || item.name || 'Item';
            itemsHtml += `
                <tr>
                    <td><strong>${escapeHtml(itemName)}</strong></td>
                    <td>${typeBadge}</td>
                    <td class="text-end">₱${price.toFixed(2)}</td>
                    <td class="text-center">${qty}</td>
                    <td class="text-end fw-bold">₱${(price * qty).toFixed(2)}</td>
                </tr>
            `;
        });
    }
    
    // Build discount display
    var discountDisplay = '';
    if (discountType !== 'none' && discountAmount > 0) {
        var discountLabel = discountType.toUpperCase() + ' Discount';
        if (bill.is_manual_discount) {
            discountLabel = 'Manual Discount (Unverified)';
        } else if (bill.is_verified_discount) {
            discountLabel = discountType.toUpperCase() + ' Discount (Verified)';
        }
        discountDisplay = `
            <div class="d-flex justify-content-between text-danger">
                <span>${discountLabel} (${discountPercentage}%):</span>
                <span>-₱${discountAmount.toFixed(2)}</span>
            </div>
        `;
    }
    
    var vatDisplay = vatAmount > 0 ? 
        `<div class="d-flex justify-content-between text-warning"><span>VAT (${vatPercentage}%):</span><span>+₱${vatAmount.toFixed(2)}</span></div>` :
        `<div class="d-flex justify-content-between text-success"><span>VAT:</span><span>Exempt</span></div>`;
    
    var paymentDisplay = '';
    if (totalPaid > 0) {
        paymentDisplay = `
            <div class="d-flex justify-content-between text-success">
                <span>Amount Paid:</span>
                <span class="fw-bold">₱${totalPaid.toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between ${balanceAmount > 0 ? 'text-danger' : 'text-success'}">
                <span>Balance:</span>
                <span class="fw-bold">₱${balanceAmount.toFixed(2)}</span>
            </div>
        `;
    }
    
    var modalBody = document.getElementById('invoiceDetails');
    modalBody.innerHTML = `
        <div class="row mb-4">
            <div class="col-6">
                <h4 class="fw-bold" style="color: var(--primary);">INVOICE</h4>
                <p class="text-muted small mb-0">${escapeHtml(bill.invoice_id || 'N/A')}</p>
                <p class="text-muted small">${bill.source_type === 'walkin' ? 'Walk-in Sale' : 'Appointment'}</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-1"><strong>Date:</strong> ${bill.sale_date || 'N/A'}</p>
                <p class="mb-0"><strong>Status:</strong> <span class="badge bg-${statusClass}">${status}</span></p>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-12">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Customer Information</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Name:</strong> ${escapeHtml(bill.customer_name || 'N/A')}</p>
                        <p><strong>Type:</strong> ${bill.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</p>
                    </div>
                    <div class="col-md-6">
                        ${bill.doctor_name ? `<p><strong>Doctor:</strong> Dr. ${escapeHtml(bill.doctor_name)}</p>` : ''}
                        ${bill.reference_number ? `<p><strong>Reference:</strong> ${escapeHtml(bill.reference_number)}</p>` : ''}
                    </div>
                </div>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-12">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr><th>Item</th><th>Type</th><th class="text-end">Price</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>${itemsHtml}</tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end fw-semibold">Subtotal:</td><td class="text-end fw-bold">₱${subtotal.toFixed(2)}</td></tr>
                            ${discountDisplay}
                            ${vatDisplay}
                            <tr class="table-primary"><td colspan="4" class="text-end fw-bold fs-5">TOTAL:</td><td class="text-end fw-bold fs-5" style="color: var(--primary);">₱${totalAmount.toFixed(2)}</td></tr>
                            ${paymentDisplay}
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12 text-end">
                ${balanceAmount > 0 ? `
                    <button class="btn btn-warning me-2" onclick="recordPayment(${bill.id}, ${totalAmount}, ${totalPaid}, '${bill.source_type || 'appointment'}')">
                        <i class="bi bi-credit-card me-2"></i>Record Payment
                    </button>
                ` : ''}
                <button class="btn btn-primary me-2" onclick="printInvoice(${bill.id}, '${bill.source_type || 'appointment'}')">
                    <i class="bi bi-printer me-2"></i>Print
                </button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    `;
    
    new bootstrap.Modal(document.getElementById('viewInvoiceModal')).show();
}

function printInvoice(id, sourceType) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to print invoices', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Preparing Print...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });
    
    var endpoint = sourceType === 'walkin' ? 'api/sales.php?id=' + id : 'api/sales.php?appointment_id=' + id;
    
    fetch(endpoint)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        Swal.close();
        if (data.success && data.bill) {
            generatePrintView(data.bill);
        } else {
            Swal.fire('Error!', data.message || 'Bill not found.', 'error');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire('Error!', 'Error generating print.', 'error');
    });
}

function generatePrintView(bill) {
    var totalAmount = parseFloat(bill.total_amount || 0);
    var totalPaid = parseFloat(bill.total_paid || bill.amount_paid || 0);
    var subtotal = parseFloat(bill.subtotal || 0);
    var discountAmount = parseFloat(bill.discount_amount || 0);
    var discountPercentage = parseFloat(bill.discount_percentage || 0);
    var discountType = bill.discount_type || 'none';
    var vatAmount = parseFloat(bill.vat_amount || 0);
    var vatPercentage = parseFloat(bill.vat_percentage || 0);
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;
    
    var items = bill.items || [];
    if (typeof items === 'string') {
        try { items = JSON.parse(items); } catch(e) { items = []; }
    }
    if (!Array.isArray(items)) items = [];
    
    var status = 'Unpaid';
    if (totalPaid >= totalAmount && totalAmount > 0) {
        status = 'Paid';
    } else if (totalPaid > 0 && totalPaid < totalAmount) {
        status = 'Partial';
    }
    
    var itemsRows = '';
    if (items.length === 0) {
        itemsRows = '<tr><td colspan="4" style="text-align:center;color:#64748b;padding:20px;">No items found</td></tr>';
    } else {
        items.forEach(function(item) {
            var price = parseFloat(item.unit_price || item.price || 0);
            var qty = parseInt(item.quantity || 1);
            var total = price * qty;
            var itemName = item.item_name || item.name || 'Item';
            itemsRows += `
                <tr>
                    <td>${escapeHtml(itemName)}</td>
                    <td style="text-align:right;">₱${price.toFixed(2)}</td>
                    <td style="text-align:center;">${qty}</td>
                    <td style="text-align:right;">₱${total.toFixed(2)}</td>
                </tr>
            `;
        });
    }
    
    var discountRows = '';
    if (discountType !== 'none' && discountAmount > 0) {
        var discountLabel = discountType.toUpperCase() + ' Discount';
        if (bill.is_manual_discount) {
            discountLabel = 'Manual Discount (Unverified)';
        } else if (bill.is_verified_discount) {
            discountLabel = discountType.toUpperCase() + ' Discount (Verified)';
        }
        discountRows = `
            <tr><td colspan="3" style="text-align:right;font-weight:600;color:#dc2626;">${discountLabel} (${discountPercentage}%):</td><td style="text-align:right;color:#dc2626;">-₱${discountAmount.toFixed(2)}</td></tr>
            <tr><td colspan="3" style="text-align:right;font-weight:600;">Subtotal after discount:</td><td style="text-align:right;">₱${(subtotal - discountAmount).toFixed(2)}</td></tr>
        `;
    }
    
    var vatRows = vatAmount > 0 ?
        `<tr><td colspan="3" style="text-align:right;font-weight:600;color:#d97706;">VAT (${vatPercentage}%):</td><td style="text-align:right;color:#d97706;">+₱${vatAmount.toFixed(2)}</td></tr>` :
        `<tr><td colspan="3" style="text-align:right;font-weight:600;color:#065f46;">VAT:</td><td style="text-align:right;color:#065f46;">Exempt</td></tr>`;
    
    var paymentRows = '';
    if (totalPaid > 0) {
        paymentRows = `
            <tr><td colspan="3" style="text-align:right;font-weight:600;color:#065f46;">Amount Paid:</td><td style="text-align:right;color:#065f46;font-weight:700;">₱${totalPaid.toFixed(2)}</td></tr>
            <tr><td colspan="3" style="text-align:right;font-weight:600;${balanceAmount > 0 ? 'color:#dc2626;' : 'color:#065f46;'}">Balance:</td><td style="text-align:right;font-weight:700;${balanceAmount > 0 ? 'color:#dc2626;' : 'color:#065f46;'}">₱${balanceAmount.toFixed(2)}</td></tr>
        `;
    }
    
    var printWindow = window.open('', '_blank', 'width=800,height=900');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(bill.invoice_id)}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: Arial, sans-serif; padding: 40px; color: #0f172a; max-width: 800px; margin: 0 auto; }
                .header { display: flex; justify-content: space-between; align-items: start; border-bottom: 2px solid #0d9488; padding-bottom: 15px; margin-bottom: 20px; }
                .header h1 { font-size: 24px; color: #0d9488; }
                .header .sub { color: #64748b; font-size: 14px; }
                .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
                .info-grid .label { font-weight: 600; color: #475569; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background: #f8fafc; padding: 10px 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
                td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .fw-bold { font-weight: 700; }
                .total-row { border-top: 2px solid #0d9488; font-size: 18px; }
                .total-row td { padding-top: 12px; }
                .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 12px; text-align: center; }
                .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
                .status-paid { background: #d1fae5; color: #065f46; }
                .status-partial { background: #fef3c7; color: #92400e; }
                .status-unpaid { background: #fee2e2; color: #991b1b; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <div>
                    <h1>INVOICE</h1>
                    <div class="sub">${escapeHtml(bill.invoice_id || 'N/A')}</div>
                    <div class="sub">${bill.source_type === 'walkin' ? 'Walk-in Sale' : 'Appointment'}</div>
                </div>
                <div style="text-align:right;">
                    <div><strong>Date:</strong> ${bill.sale_date || 'N/A'}</div>
                    <div><strong>Status:</strong> <span class="status-badge status-${status.toLowerCase()}">${status}</span></div>
                </div>
            </div>
            <div class="info-grid">
                <div>
                    <div class="label">Customer Name</div>
                    <div>${escapeHtml(bill.customer_name || 'N/A')}</div>
                    <div class="label" style="margin-top:8px;">Type</div>
                    <div>${bill.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</div>
                </div>
                <div>
                    ${bill.doctor_name ? `<div class="label">Doctor</div><div>Dr. ${escapeHtml(bill.doctor_name)}</div>` : ''}
                    ${bill.reference_number ? `<div class="label" style="margin-top:8px;">Reference</div><div>${escapeHtml(bill.reference_number)}</div>` : ''}
                </div>
            </div>
            <table>
                <thead><tr><th style="width:50%;">Item</th><th style="width:20%;text-align:right;">Price</th><th style="width:15%;text-align:center;">Qty</th><th style="width:25%;text-align:right;">Total</th></tr></thead>
                <tbody>${itemsRows}</tbody>
                <tfoot>
                    <tr><td colspan="3" style="text-align:right;font-weight:600;">Subtotal:</td><td style="text-align:right;font-weight:700;">₱${subtotal.toFixed(2)}</td></tr>
                    ${discountRows}
                    ${vatRows}
                    <tr class="total-row"><td colspan="3" style="text-align:right;font-weight:700;font-size:18px;color:#0d9488;">TOTAL:</td><td style="text-align:right;font-weight:700;font-size:18px;color:#0d9488;">₱${totalAmount.toFixed(2)}</td></tr>
                    ${paymentRows}
                </tfoot>
            </table>
            <div class="footer">
                <p>Thank you for your business!</p>
                <p>Generated on ${new Date().toLocaleString()}</p>
            </div>
            <script>
                window.onload = function() { window.print(); window.close(); };
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function recordPayment(id, totalAmount, alreadyPaid, sourceType) {
    if(!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to record payments', 'error');
        return;
    }
    
    var remainingBalance = totalAmount - alreadyPaid;
    if(remainingBalance <= 0) {
        Swal.fire('Info!', 'This invoice is already fully paid', 'info');
        return;
    }
    
    document.getElementById('payment_appointment_id').value = id;
    document.getElementById('payment_source_type').value = sourceType || 'appointment';
    document.getElementById('payment_total_amount').value = '₱' + totalAmount.toFixed(2);
    document.getElementById('payment_already_paid').value = '₱' + alreadyPaid.toFixed(2);
    document.getElementById('payment_remaining_balance').value = '₱' + remainingBalance.toFixed(2);
    document.getElementById('payment_amount').value = remainingBalance;
    document.getElementById('payment_amount').max = remainingBalance;
    
    var invoiceId = 'INV-' + new Date().toISOString().slice(0,7).replace('-','') + '-' + String(id).padStart(4, '0');
    document.getElementById('payment_invoice_id').value = invoiceId;
    
    // Check verification for manual discount
    checkAppointmentVerification(id);
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

function checkAppointmentVerification(appointmentId) {
    fetch('api/sales.php?action=check_appointment_verification&appointment_id=' + appointmentId + '&clinic_id=' + <?php echo $clinic_id; ?>, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        var section = document.getElementById('payment_manual_discount_section');
        if (data.success && !data.is_verified) {
            section.style.display = 'block';
            document.getElementById('payment_manual_discount').value = 0;
            document.getElementById('payment_manual_discount').disabled = false;
        } else {
            section.style.display = 'none';
            document.getElementById('payment_manual_discount').value = 0;
            document.getElementById('payment_manual_discount').disabled = true;
        }
    })
    .catch(function(err) {
        console.error('Error checking verification:', err);
        document.getElementById('payment_manual_discount_section').style.display = 'none';
    });
}

function submitPayment() {
    var amount = parseFloat(document.getElementById('payment_amount').value);
    var remainingBalance = parseFloat(document.getElementById('payment_remaining_balance').value.replace('₱', ''));
    var id = document.getElementById('payment_appointment_id').value;
    var sourceType = document.getElementById('payment_source_type').value;
    
    if(amount <= 0) {
        Swal.fire('Error!', 'Please enter a valid amount', 'error');
        return;
    }
    
    if(amount > remainingBalance) {
        Swal.fire('Error!', 'Amount cannot exceed remaining balance', 'error');
        return;
    }
    
    var formData = {
        appointment_id: id,
        amount: amount,
        payment_method: document.querySelector('#recordPaymentForm select[name="payment_method"]').value,
        reference_number: document.querySelector('#recordPaymentForm input[name="reference_number"]').value,
        notes: document.querySelector('#recordPaymentForm textarea[name="notes"]').value,
        payment_type: amount >= remainingBalance ? 'full' : 'partial',
        source_type: sourceType,
        manual_discount: parseFloat(document.getElementById('payment_manual_discount').value) || 0,
        manual_discount_type: document.getElementById('payment_manual_discount_type').value,
        verified_by: document.getElementById('payment_verified_by').value,
        id_number: document.getElementById('payment_id_number').value
    };
    
    Swal.fire({
        title: 'Recording Payment...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });
    
    fetch('api/sales.php?action=record_payment', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(formData)
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if(resp.success) {
            Swal.fire({
                icon: 'success',
                title: 'Payment Recorded!',
                text: resp.message || 'Payment recorded successfully',
                timer: 2000,
                showConfirmButton: false
            }).then(function() {
                bootstrap.Modal.getInstance(document.getElementById('recordPaymentModal')).hide();
                location.reload();
            });
        } else {
            throw new Error(resp.message);
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.fire('Error!', error.message || 'Error recording payment', 'error');
    });
}

// ============================================
// EXPORT DATA
// ============================================
function exportData() {
    if(!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to export', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Exporting...',
        text: 'Preparing export data',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });
    
    fetch('api/sales.php?export=1')
    .then(function(res) { return res.json(); })
    .then(function(data) {
        var csvContent = 'Invoice ID,Date,Customer,Status,Subtotal,Total,Amount Paid,Balance,Payment Method\n';
        data.forEach(function(inv) {
            var balance = (inv.total_amount || 0) - (inv.amount_paid || 0);
            csvContent += [
                inv.invoice_id,
                inv.sale_date,
                inv.customer_name || inv.walk_in_name,
                inv.status,
                inv.subtotal || 0,
                inv.total_amount || 0,
                inv.amount_paid || 0,
                balance,
                inv.payment_method || 'cash'
            ].join(',') + '\n';
        });
        
        var blob = new Blob([csvContent], { type: 'text/csv' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'sales_export_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        Swal.close();
        Swal.fire({
            icon: 'success',
            title: 'Exported!',
            text: 'Data exported successfully',
            timer: 1500,
            showConfirmButton: false
        });
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.fire('Error!', 'Error exporting data', 'error');
    });
}

// ============================================
// SUBMIT NEW SALE
// ============================================
document.getElementById('newSaleForm').addEventListener('submit', function(e){
    e.preventDefault();
    
    if(!permissions.canCreate) {
        Swal.fire('Access Denied', 'You don\'t have permission to create sales', 'error');
        return;
    }
    
    if(saleItems.length === 0) {
        Swal.fire('Error!', 'Please add at least one item', 'error');
        return;
    }
    
    calculateTotal();
    
    var customerType = document.querySelector('input[name="customer_type"]:checked').value;
    
    var data = {
        customer_type: customerType,
        patient_id: customerType === 'registered' ? document.querySelector('select[name="patient_id"]').value : null,
        walk_in_name: customerType === 'walkin' ? document.querySelector('input[name="walk_in_name"]').value : null,
        walk_in_contact: customerType === 'walkin' ? document.querySelector('input[name="walk_in_contact"]').value : null,
        walk_in_email: customerType === 'walkin' ? document.querySelector('input[name="walk_in_email"]').value : null,
        payment_method: document.querySelector('select[name="payment_method"]').value,
        amount_paid: document.querySelector('input[name="amount_paid"]').value,
        reference_number: document.querySelector('input[name="reference_number"]').value,
        items: saleItems,
        subtotal: document.getElementById('subtotalInput').value,
        discount: document.getElementById('discountInput').value,
        total_amount: document.getElementById('totalInput').value,
        status: document.getElementById('statusInput').value,
        payment_type: document.getElementById('paymentTypeInput').value,
        manual_discount: customerType === 'registered' ? document.getElementById('manual_discount').value : 0,
        manual_discount_type: customerType === 'registered' ? document.getElementById('manual_discount_type').value : 'senior',
        verified_by: customerType === 'registered' ? document.getElementById('verified_by').value : null,
        id_number: customerType === 'registered' ? document.getElementById('id_number').value : null,
        walkin_manual_discount: customerType === 'walkin' ? document.getElementById('walkin_manual_discount').value : 0,
        walkin_manual_discount_type: customerType === 'walkin' ? document.getElementById('walkin_manual_discount_type').value : 'senior',
        walkin_verified_by: customerType === 'walkin' ? document.getElementById('walkin_verified_by').value : null,
        walkin_id_number: customerType === 'walkin' ? document.getElementById('walkin_id_number').value : null
    };
    
    if(customerType === 'walkin' && !data.walk_in_name) {
        Swal.fire('Error!', 'Please enter customer name for walk-in', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Creating Invoice...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });
    
    fetch('api/sales.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if(resp.success){
            Swal.fire({
                icon: 'success',
                title: 'Created!',
                text: 'Invoice created successfully',
                timer: 1500,
                showConfirmButton: false
            }).then(function() {
                document.getElementById('newSaleForm').reset();
                saleItems = [];
                updateItemsList();
                document.getElementById('registered_section').style.display = 'block';
                document.getElementById('walkin_section').style.display = 'none';
                document.querySelector('input[name="customer_type"][value="registered"]').checked = true;
                $('#itemSelect').val(null).trigger('change');
                $('.patient-select').val(null).trigger('change');
                bootstrap.Modal.getInstance(document.getElementById('newSaleModal')).hide();
                location.reload();
            });
        } else {
            throw new Error(resp.message);
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.fire('Error!', error.message || 'Error creating invoice', 'error');
    });
});

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize
calculateTotal();
togglePaymentFields();
</script>
</body>
</html>