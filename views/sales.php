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
        CASE 
            WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
            ELSE a.total_amount
        END AS total_amount,
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
            WHEN a.amount_paid >= (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Paid'
            WHEN a.amount_paid > 0 AND a.amount_paid < (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Partial'
            ELSE 'Unpaid'
        END AS payment_status,
        'appointment' AS source_type,
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
        NULL AS discount_type,
        0 AS discount_percentage,
        s.discount,
        0 AS vat_percentage,
        0 AS vat_amount,
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

// ✅ FIXED: Both clinic_id parameters for both UNION parts
$params = [$clinic_id, $clinic_id];

// ✅ FIXED: Add search filter for both UNION parts (simplified approach)
if (!empty($search)) {
    // Since UNION ALL has two parts with different column structures,
    // we wrap the whole UNION in a subquery and apply search there
    $query = "SELECT * FROM (" . $query . ") AS combined 
              WHERE customer_name LIKE ? 
                 OR invoice_id LIKE ? 
                 OR id LIKE ? 
                 OR walk_in_name LIKE ?";
    $searchTerm = "%$search%";
    $params = array_merge([$clinic_id, $clinic_id], [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    
    // ✅ FIXED: ORDER BY using the ALIAS column name
    $query .= " ORDER BY sale_date DESC LIMIT 20";
} else {
    // ✅ FIXED: ORDER BY using the ALIAS column name
    $query .= " ORDER BY sale_date DESC LIMIT 20";
}

// ✅ Debug: Log the query for troubleshooting
error_log("=== SALES QUERY DEBUG ===");
error_log("Clinic ID: " . $clinic_id);
error_log("Search: " . $search);
error_log("Query: " . str_replace("\n", " ", $query));
error_log("Params: " . json_encode($params));

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Debug: Log results
error_log("Invoices found: " . count($invoices));

// ============================================
// ✅ FIXED: FETCH STATS FROM BOTH APPOINTMENTS AND SALES
// ============================================
$statsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN sale_date = ? AND status = 'Paid' THEN total_amount END), 0) as today_sales,
        COUNT(CASE WHEN sale_date = ? AND status = 'Paid' THEN 1 END) as today_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN status = 'Partial' THEN total_amount END), 0) as partial_amount,
        COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN total_amount END), 0) as unpaid_amount
    FROM (
        -- ✅ Appointment-based sales
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
        
        -- ✅ Walk-in sales
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

// ✅ Ensure stats are not null
$stats['today_sales'] = $stats['today_sales'] ?? 0;
$stats['today_count'] = $stats['today_count'] ?? 0;
$stats['paid_amount'] = $stats['paid_amount'] ?? 0;
$stats['partial_amount'] = $stats['partial_amount'] ?? 0;
$stats['unpaid_amount'] = $stats['unpaid_amount'] ?? 0;

// Fetch patients for dropdown
$patientsStmt = $pdo->prepare("
    SELECT id, CONCAT(first_name, ' ', last_name) as full_name, email, phone 
    FROM patients 
    WHERE clinic_id = ? AND status = 'Active' 
    ORDER BY first_name ASC
");
$patientsStmt->execute([$clinic_id]);
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Combine items
$allItems = array_merge($products, $services);

// ============================================
// ✅ FIXED: Process invoices with correct balance
// ============================================
foreach ($invoices as &$invoice) {
    $total_amount = $invoice['total_amount'] ?? 0;
    $amount_paid = $invoice['amount_paid'] ?? 0;
    $balance = $total_amount - $amount_paid;
    
    // ✅ FIXED: If balance is negative, set to 0 (fully paid)
    if ($balance < 0) {
        $balance = 0;
    }
    $invoice['balance'] = $balance;
    
    // ✅ FIXED: Recalculate payment status
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
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--teal-light));
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }
        .stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #0f172a;
        }
        .page-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
        }
        
        .table-card {
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        .discount-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 12px;
        }
        .discount-badge.pwd { background: #dbeafe; color: #1d4ed8; }
        .discount-badge.senior { background: #fce7f3; color: #be185d; }
        .discount-badge.none { background: #f3f4f6; color: #6b7280; }
        
        .vat-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 12px;
        }
        .vat-badge.exempt { background: #d1fae5; color: #065f46; }
        .vat-badge.standard { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body>
<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">
                <i class="bi bi-receipt me-2" style="color: var(--teal);"></i>Sales & Billing
            </h1>
            <p class="page-subtitle mt-1">Manage transactions, invoices, and payments</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($canView): ?>
                <button class="btn btn-outline-primary" onclick="exportData()">
                    <i class="bi bi-download me-2"></i>Export
                </button>
            <?php endif; ?>
            
            <?php if ($canCreate): ?>
                <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#newSaleModal">
                    <i class="bi bi-receipt me-2"></i>New Sale
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-10">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by invoice ID, patient name, walk-in name, or patient ID..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-teal w-100">Search</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label">Today's Sales</div>
                <div class="stat-value">₱<?php echo number_format($stats['today_sales'] ?? 0); ?></div>
                <div class="stat-label mt-1"><?php echo $stats['today_count'] ?? 0; ?> transactions</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label">Paid</div>
                <div class="stat-value text-success">₱<?php echo number_format($stats['paid_amount'] ?? 0); ?></div>
                <div class="stat-label">fully paid invoices</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label">Pending Balance</div>
                <div class="stat-value text-warning">₱<?php echo number_format($stats['partial_amount'] ?? 0); ?></div>
                <div class="stat-label">confirmed awaiting payment</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label">Unpaid</div>
                <div class="stat-value text-danger">₱<?php echo number_format($stats['unpaid_amount'] ?? 0); ?></div>
                <div class="stat-label">overdue appointments</div>
            </div>
        </div>
    </div>

    <!-- Recent Invoices -->
    <div class="card shadow table-card">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 fw-semibold">Recent Invoices</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice ID</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Subtotal</th>
                            <th>Discount</th>
                            <th>VAT</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($invoices)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <?php echo empty($search) ? 'No invoices found.' : 'No invoices match your search.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
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
        CASE 
            WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0))
            ELSE a.total_amount
        END AS total_amount,
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
            WHEN a.amount_paid >= (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Paid'
            WHEN a.amount_paid > 0 AND a.amount_paid < (CASE WHEN a.subtotal > 0 THEN (a.subtotal - COALESCE(a.discount_amount, 0) + COALESCE(a.vat_amount, 0)) ELSE a.total_amount END) THEN 'Partial'
            ELSE 'Unpaid'
        END AS payment_status,
        'appointment' AS source_type,
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
        NULL AS discount_type,
        0 AS discount_percentage,
        s.discount,
        0 AS vat_percentage,
        0 AS vat_amount,
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

// ✅ FIXED: Both clinic_id parameters for both UNION parts
$params = [$clinic_id, $clinic_id];

// ✅ FIXED: Add search filter for both UNION parts (simplified approach)
if (!empty($search)) {
    // Since UNION ALL has two parts with different column structures,
    // we wrap the whole UNION in a subquery and apply search there
    $query = "SELECT * FROM (" . $query . ") AS combined 
              WHERE customer_name LIKE ? 
                 OR invoice_id LIKE ? 
                 OR id LIKE ? 
                 OR walk_in_name LIKE ?";
    $searchTerm = "%$search%";
    $params = array_merge([$clinic_id, $clinic_id], [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    
    // ✅ FIXED: ORDER BY using the ALIAS column name
    $query .= " ORDER BY sale_date DESC LIMIT 20";
} else {
    // ✅ FIXED: ORDER BY using the ALIAS column name
    $query .= " ORDER BY sale_date DESC LIMIT 20";
}

// ✅ Debug: Log the query for troubleshooting
error_log("=== SALES QUERY DEBUG ===");
error_log("Clinic ID: " . $clinic_id);
error_log("Search: " . $search);
error_log("Query: " . str_replace("\n", " ", $query));
error_log("Params: " . json_encode($params));

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Debug: Log results
error_log("Invoices found: " . count($invoices));

// ============================================
// ✅ FIXED: FETCH STATS FROM BOTH APPOINTMENTS AND SALES
// ============================================
$statsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN sale_date = ? AND status = 'Paid' THEN total_amount END), 0) as today_sales,
        COUNT(CASE WHEN sale_date = ? AND status = 'Paid' THEN 1 END) as today_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' THEN total_amount END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN status = 'Partial' THEN total_amount END), 0) as partial_amount,
        COALESCE(SUM(CASE WHEN status = 'Unpaid' THEN total_amount END), 0) as unpaid_amount
    FROM (
        -- ✅ Appointment-based sales
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
        
        -- ✅ Walk-in sales
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

// ✅ Ensure stats are not null
$stats['today_sales'] = $stats['today_sales'] ?? 0;
$stats['today_count'] = $stats['today_count'] ?? 0;
$stats['paid_amount'] = $stats['paid_amount'] ?? 0;
$stats['partial_amount'] = $stats['partial_amount'] ?? 0;
$stats['unpaid_amount'] = $stats['unpaid_amount'] ?? 0;

// Fetch patients for dropdown
$patientsStmt = $pdo->prepare("
    SELECT id, CONCAT(first_name, ' ', last_name) as full_name, email, phone 
    FROM patients 
    WHERE clinic_id = ? AND status = 'Active' 
    ORDER BY first_name ASC
");
$patientsStmt->execute([$clinic_id]);
$patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

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

// Combine items
$allItems = array_merge($products, $services);

// ============================================
// ✅ FIXED: Process invoices with correct balance
// ============================================
foreach ($invoices as &$invoice) {
    $total_amount = $invoice['total_amount'] ?? 0;
    $amount_paid = $invoice['amount_paid'] ?? 0;
    $balance = $total_amount - $amount_paid;
    
    // ✅ FIXED: If balance is negative, set to 0 (fully paid)
    if ($balance < 0) {
        $balance = 0;
    }
    $invoice['balance'] = $balance;
    
    // ✅ FIXED: Recalculate payment status
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

<!-- ============================================ -->
<!-- HTML TABLE DISPLAY - FIXED BALANCE -->
<!-- ============================================ -->
<!-- Sa table row, gamitin ang computed balance: -->

<?php foreach ($invoices as $invoice): 
    $total_amount = $invoice['total_amount'] ?? 0;
    $amount_paid = $invoice['amount_paid'] ?? 0;
    $balance = $invoice['balance'] ?? 0;
    $payment_status = $invoice['payment_status'] ?? 'Unpaid';
    $statusColors = [
        'Paid' => 'success',
        'Partial' => 'warning',
        'Unpaid' => 'danger'
    ];
    $color = $statusColors[$payment_status] ?? 'secondary';
?>
<tr>
    <td class="fw-semibold"><?php echo $invoice['invoice_id']; ?></td>
    <td><?php echo date('M d, Y', strtotime($invoice['sale_date'])); ?></td>
    <td>
        <div><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
        <div class="text-muted small"><?php echo $invoice['customer_code']; ?></div>
    </td>
    <td>
        <?php 
        $items = json_decode($invoice['items'] ?? '[]', true);
        if(is_array($items) && !empty($items)) {
            $count = count($items);
            $itemNames = array_slice(array_column($items, 'name'), 0, 2);
            echo '<div>' . $count . ' item' . ($count > 1 ? 's' : '') . '</div>';
            echo '<small class="text-muted">' . htmlspecialchars(implode(', ', $itemNames));
            if(count($items) > 2) echo '...';
            echo '</small>';
        } else {
            echo 'No items';
        }
        ?>
    </td>
    <td class="fw-bold">₱<?php echo number_format($invoice['subtotal'] ?? $invoice['total_amount'], 2); ?></td>
    <td>
        <?php if(!empty($invoice['discount_type']) && $invoice['discount_type'] !== 'none'): ?>
            <span class="discount-badge <?php echo $invoice['discount_type']; ?>">
                <?php echo strtoupper($invoice['discount_type']); ?> 
                <?php echo round($invoice['discount_percentage'] ?? 0); ?>%
            </span>
            <br><small class="text-danger">-₱<?php echo number_format($invoice['discount_amount'] ?? 0, 2); ?></small>
        <?php else: ?>
            <span class="text-muted small">—</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if(($invoice['vat_percentage'] ?? 0) > 0): ?>
            <span class="vat-badge standard">VAT <?php echo round($invoice['vat_percentage']); ?>%</span>
            <br><small class="text-warning">+₱<?php echo number_format($invoice['vat_amount'] ?? 0, 2); ?></small>
        <?php else: ?>
            <span class="vat-badge exempt">VAT Exempt</span>
        <?php endif; ?>
    </td>
    <td class="fw-bold text-primary">
        ₱<?php echo number_format($total_amount, 2); ?>
    </td>
    <td>
        <span class="badge bg-<?php echo $color; ?>"><?php echo $payment_status; ?></span>
    </td>
    <td>
        <div class="d-flex gap-1">
            <?php if ($canView): ?>
                <button class="btn btn-sm btn-outline-info" onclick="viewInvoice(<?php echo $invoice['id']; ?>, '<?php echo $invoice['source_type']; ?>')">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="printInvoice(<?php echo $invoice['id']; ?>, '<?php echo $invoice['source_type']; ?>')">
                    <i class="bi bi-printer"></i>
                </button>
            <?php endif; ?>
            
            <?php if ($canEdit && $payment_status !== 'Paid'): ?>
                <button class="btn btn-sm btn-outline-success" onclick="recordPayment(<?php echo $invoice['id']; ?>, <?php echo $total_amount; ?>, <?php echo $amount_paid; ?>, '<?php echo $invoice['source_type']; ?>')">
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
        </div>
    </div>
</div>

<!-- New Sale Modal -->
<div class="modal fade" id="newSaleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px;">
            <form id="newSaleForm">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-receipt me-2"></i>New Sale / Invoice
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Customer Type Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Customer Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="customer_type" id="registered_patient" value="registered" checked>
                            <label class="btn btn-outline-primary" for="registered_patient">Registered Patient</label>
                            
                            <input type="radio" class="btn-check" name="customer_type" id="walk_in_customer" value="walkin">
                            <label class="btn btn-outline-primary" for="walk_in_customer">Walk-in Customer</label>
                        </div>
                    </div>

                    <!-- ✅ PWD/SENIOR VERIFICATION DISPLAY -->
                    <div id="pwd_senior_status" style="display: none; padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle" style="font-size: 20px;"></i>
                            <span class="ms-2" id="pwd_senior_label">PWD/Senior Verified - 20% Discount & VAT Exempt</span>
                        </div>
                    </div>

                    <!-- Registered Patient Section -->
                    <div id="registered_section">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Patient</label>
                            <select class="form-select patient-select" name="patient_id" id="patient_select" style="width: 100%;" onchange="checkPwdSenior(this.value)">
                                <option value="" disabled selected>Search and select patient...</option>
                                <?php foreach($patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>" 
                                            data-email="<?php echo $patient['email']; ?>"
                                            data-phone="<?php echo $patient['phone']; ?>">
                                        <?php echo htmlspecialchars($patient['full_name']); ?> (<?php echo $patient['email'] ?? 'No email'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Walk-in Customer Section -->
                    <div id="walkin_section" style="display: none;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="walk_in_name" id="walk_in_name" placeholder="Enter customer name" onchange="checkWalkInPwdSenior(this.value)">
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
                            <button type="button" class="btn btn-teal" onclick="addItem()">Add</button>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mb-3">
                        <div class="table-responsive">
                            <table class="table table-sm" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Quantity</th>
                                        <th>Total</th>
                                        <th>Action</th>
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
                                        <td colspan="4" class="text-end fw-semibold text-success">PWD/Senior Discount:</td>
                                        <td id="discountDisplay" class="fw-bold text-success">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr id="vatRow">
                                        <td colspan="4" class="text-end fw-semibold text-warning">VAT (12%):</td>
                                        <td id="vatDisplay" class="fw-bold text-warning">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Manual Discount:</td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" class="form-control" id="discount" value="0" min="0" step="0.01" onchange="calculateTotal()">
                                            </div>
                                        </td>
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
                            <input type="number" class="form-control" name="amount_paid" step="0.01" min="0" value="0" required onchange="updateStatus()">
                        </div>
                        <div class="col-md-4" id="reference_field" style="display: none;">
                            <label class="form-label fw-semibold">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="e.g., GCash ref no.">
                        </div>
                    </div>

                    <input type="hidden" name="items" id="itemsInput">
                    <input type="hidden" name="subtotal" id="subtotalInput">
                    <input type="hidden" name="discount" id="discountInput">
                    <input type="hidden" name="vat" id="vatInput">
                    <input type="hidden" name="total" id="totalInput">
                    <input type="hidden" name="status" id="statusInput" value="Unpaid">
                    <input type="hidden" name="payment_type" id="paymentTypeInput" value="full">
                </div>
                <div class="modal-footer border-0 pb-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal">Create Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
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
                <button type="button" class="btn btn-teal" onclick="submitPayment()">Record Payment</button>
            </div>
        </div>
    </div>
</div>

<!-- View Invoice Modal -->
<div class="modal fade" id="viewInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ============================================
// RBAC PERMISSIONS
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

// Initialize Select2
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
});

// Toggle between registered and walk-in customer
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

// Toggle reference number field based on payment method
function togglePaymentFields() {
    var method = document.getElementById('payment_method').value;
    var referenceField = document.getElementById('reference_field');
    if(method === 'cash') {
        referenceField.style.display = 'none';
    } else {
        referenceField.style.display = 'block';
    }
}

function toggleModalReferenceField() {
    var method = document.getElementById('payment_method_modal').value;
    var referenceField = document.getElementById('modal_reference_field');
    if(method === 'cash') {
        referenceField.style.display = 'none';
    } else {
        referenceField.style.display = 'block';
    }
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

// Update quantity
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

// Remove item
function removeItem(index) {
    saleItems.splice(index, 1);
    updateItemsList();
}

// Calculate totals with VAT and Discount
function calculateTotal() {
    let subtotal = 0;
    saleItems.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    // ✅ Check if PWD/Senior is verified
    let isPwdSenior = document.getElementById('pwd_senior_status').style.display === 'block' &&
                      document.getElementById('pwd_senior_status').style.background === '#d1fae5';
    
    const discountRate = <?php echo $pwd_senior_discount ?? 0.20; ?>;
    const vatRate = <?php echo $vat_rate ?? 0.12; ?>;
    const isVatExempt = <?php echo $pwd_senior_vat_exempt ?? 1; ?>;
    
    let discount = 0;
    let vat = 0;
    let total = subtotal;
    
    if (isPwdSenior) {
        discount = subtotal * discountRate;
        total = subtotal - discount;
        // VAT Exempt for PWD/Senior
        if (isVatExempt) {
            vat = 0;
        } else {
            vat = total * vatRate;
            total = total + vat;
        }
    } else {
        // Standard customer - with VAT
        vat = subtotal * vatRate;
        total = subtotal + vat;
    }
    
    // Apply manual discount if any
    const manualDiscount = parseFloat(document.getElementById('discount').value) || 0;
    total = total - manualDiscount;
    
    document.getElementById('subtotal').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('totalAmount').textContent = `₱${total.toFixed(2)}`;
    document.getElementById('vatDisplay').textContent = `₱${vat.toFixed(2)}`;
    document.getElementById('discountDisplay').textContent = `₱${discount.toFixed(2)}`;
    
    document.getElementById('itemsInput').value = JSON.stringify(saleItems);
    document.getElementById('subtotalInput').value = subtotal;
    document.getElementById('discountInput').value = discount + manualDiscount;
    document.getElementById('vatInput').value = vat;
    document.getElementById('totalInput').value = total;
    
    updateStatus();
}
// Update status
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

// Submit new sale
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
        payment_type: document.getElementById('paymentTypeInput').value
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

// Record payment for existing invoice
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
    
    // Generate invoice ID
    var invoiceId = 'INV-' + new Date().toISOString().slice(0,7).replace('-','') + '-' + String(id).padStart(4, '0');
    document.getElementById('payment_invoice_id').value = invoiceId;
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

// Submit payment
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
        source_type: sourceType
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

// View Invoice
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
    
    var endpoint = 'api/sales.php';
    if (sourceType === 'walkin') {
        endpoint = 'api/sales.php?id=' + id;
    } else {
        endpoint = 'api/sales.php?appointment_id=' + id;
    }
    
    fetch(endpoint)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        Swal.close();
        
        if (data.success && data.bill) {
            var b = data.bill;
            var bill = {
                id: b.id,
                appointment_id: b.appointment_id || b.id,
                invoice_id: b.invoice_id || 'INV-' + String(b.id).padStart(4, '0'),
                sale_date: b.sale_date,
                customer_name: b.customer_name || 'N/A',
                customer_code: b.customer_code,
                patient_id: b.patient_id,
                subtotal: parseFloat(b.subtotal || 0),
                discount_type: b.discount_type || 'none',
                discount_percentage: parseFloat(b.discount_percentage || 0),
                discount_amount: parseFloat(b.discount_amount || 0),
                vat_percentage: parseFloat(b.vat_percentage || 0),
                vat_amount: parseFloat(b.vat_amount || 0),
                total_amount: parseFloat(b.total_amount || 0),
                amount_paid: parseFloat(b.amount_paid || b.total_paid || 0),
                total_paid: parseFloat(b.total_paid || b.amount_paid || 0),
                status: b.status || 'Unpaid',
                payment_method: b.payment_method || 'cash',
                source_type: b.source_type || sourceType || 'appointment',
                items: Array.isArray(b.items) ? b.items : []
            };
            
            showBillFromAppointment(bill);
        } else {
            Swal.fire('Error!', data.message || 'Invoice not found. Please try again.', 'error');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire('Error!', 'Error loading invoice. Please try again.', 'error');
    });
}

// Show Bill From Appointment
function showBillFromAppointment(bill) {
    var totalAmount = parseFloat(bill.total_amount || 0);
    var totalPaid = parseFloat(bill.amount_paid || bill.total_paid || 0);
    
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;
    
    var status = 'Unpaid';
    if (totalPaid >= totalAmount && totalAmount > 0) {
        status = 'Paid';
    } else if (totalPaid > 0 && totalPaid < totalAmount) {
        status = 'Partial';
    }
    
    var items = bill.items || [];
    
    if (items.length === 0 && bill.item_name) {
        items = [{
            item_name: bill.item_name || bill.service_type || 'Service',
            item_type: bill.item_type || 'service',
            unit_price: parseFloat(bill.item_price || bill.subtotal || 0),
            quantity: 1,
            total_price: parseFloat(bill.subtotal || 0)
        }];
    }
    
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
            itemsHtml += `
                <tr>
                    <td>${escapeHtml(item.item_name || item.name)}</td>
                    <td>${typeBadge}</td>
                    <td class="text-end">₱${price.toFixed(2)}</td>
                    <td class="text-center">${qty}</td>
                    <td class="text-end fw-bold">₱${(price * qty).toFixed(2)}</td>
                </tr>
            `;
        });
    }
    
    var discountDisplay = '';
    if (bill.discount_type && bill.discount_type !== 'none') {
        discountDisplay = `
            <div class="d-flex justify-content-between text-danger">
                <span>${bill.discount_type.toUpperCase()} Discount (${bill.discount_percentage || 0}%):</span>
                <span>-₱${parseFloat(bill.discount_amount || 0).toFixed(2)}</span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Subtotal after discount:</span>
                <span>₱${(parseFloat(bill.subtotal || 0) - parseFloat(bill.discount_amount || 0)).toFixed(2)}</span>
            </div>
        `;
    }
    
    var vatDisplay = '';
    if (parseFloat(bill.vat_percentage || 0) > 0) {
        vatDisplay = `
            <div class="d-flex justify-content-between text-warning">
                <span>VAT (${bill.vat_percentage}%):</span>
                <span>+₱${parseFloat(bill.vat_amount || 0).toFixed(2)}</span>
            </div>
        `;
    } else {
        vatDisplay = `
            <div class="d-flex justify-content-between text-success">
                <span>VAT:</span>
                <span>Exempt</span>
            </div>
        `;
    }
    
    document.getElementById('invoiceDetails').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Invoice Information</h6>
                <p><strong>Invoice ID:</strong> ${bill.invoice_id || 'N/A'}</p>
                <p><strong>Date:</strong> ${bill.sale_date || bill.appointment_date || 'N/A'}</p>
                <p><strong>Status:</strong> <span class="badge bg-${status === 'Paid' ? 'success' : status === 'Partial' ? 'warning' : 'danger'}">${status || 'Unpaid'}</span></p>
                <p><strong>Appointment ID:</strong> ${bill.appointment_id || bill.id || 'N/A'}</p>
                <p><strong>Source:</strong> ${bill.source_type === 'walkin' ? 'Walk-in Sale' : 'Appointment'}</p>
            </div>
            <div class="col-md-6">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Customer Information</h6>
                <p><strong>Name:</strong> ${escapeHtml(bill.customer_name || bill.walk_in_name || 'N/A')}</p>
                <p><strong>Type:</strong> ${bill.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</p>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-12">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th>Type</th>
                                <th class="text-end">Price</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>${itemsHtml}</tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Subtotal:</td>
                                <td class="text-end fw-bold">₱${parseFloat(bill.subtotal || 0).toFixed(2)}</td>
                            </tr>
                            ${bill.discount_type && bill.discount_type !== 'none' ? `
                            <tr>
                                <td colspan="4" class="text-end fw-bold text-danger">${bill.discount_type.toUpperCase()} Discount:</td>
                                <td class="text-end text-danger">-₱${parseFloat(bill.discount_amount || 0).toFixed(2)}</td>
                            </tr>
                            ` : ''}
                            ${parseFloat(bill.vat_percentage || 0) > 0 ? `
                            <tr>
                                <td colspan="4" class="text-end fw-bold text-warning">VAT (${bill.vat_percentage}%):</td>
                                <td class="text-end text-warning">+₱${parseFloat(bill.vat_amount || 0).toFixed(2)}</td>
                            </tr>
                            ` : ''}
                            <tr class="table-primary">
                                <td colspan="4" class="text-end fw-bold">Total Amount:</td>
                                <td class="text-end fw-bold text-primary fs-5">₱${totalAmount.toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Amount Paid:</td>
                                <td class="text-end fw-bold text-success">₱${totalPaid.toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Balance:</td>
                                <td class="text-end fw-bold ${balanceAmount > 0 ? 'text-danger' : 'text-success'}">₱${balanceAmount.toFixed(2)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-12">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Payment Breakdown</h6>
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span>Subtotal:</span>
                            <span>₱${parseFloat(bill.subtotal || 0).toFixed(2)}</span>
                        </div>
                        ${discountDisplay}
                        ${vatDisplay}
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>TOTAL:</span>
                            <span style="color: var(--teal);">₱${totalAmount.toFixed(2)}</span>
                        </div>
                        ${totalPaid > 0 ? `
                        <div class="d-flex justify-content-between mt-2">
                            <span class="text-success">Amount Paid:</span>
                            <span class="text-success fw-bold">₱${totalPaid.toFixed(2)}</span>
                        </div>
                        <div class="d-flex justify-content-between ${balanceAmount > 0 ? 'text-danger' : 'text-success'}">
                            <span>Balance:</span>
                            <span class="fw-bold">₱${balanceAmount.toFixed(2)}</span>
                        </div>
                        ` : ''}
                    </div>
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
                <button class="btn btn-teal" onclick="printInvoice(${bill.id}, '${bill.source_type || 'appointment'}')">
                    <i class="bi bi-printer me-2"></i>Print Invoice
                </button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    `;
    
    var modal = new bootstrap.Modal(document.getElementById('viewInvoiceModal'));
    modal.show();
}

// Print Invoice
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
    
    var endpoint = 'api/sales.php';
    if (sourceType === 'walkin') {
        endpoint = 'api/sales.php?id=' + id;
    } else {
        endpoint = 'api/sales.php?appointment_id=' + id;
    }
    
    fetch(endpoint)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        Swal.close();
        
        if (!data.success || !data.bill) {
            Swal.fire('Error!', data.message || 'Bill not found. Please try again.', 'error');
            return;
        }
        
        var b = data.bill;
        var bill = {
            id: b.id,
            invoice_id: b.invoice_id || 'INV-' + String(b.id).padStart(4, '0'),
            sale_date: b.sale_date || new Date().toISOString().split('T')[0],
            customer_name: b.customer_name || 'N/A',
            patient_id: b.patient_id,
            subtotal: parseFloat(b.subtotal || 0),
            total_amount: parseFloat(b.total_amount || 0),
            amount_paid: parseFloat(b.amount_paid || 0),
            total_paid: parseFloat(b.total_paid || b.amount_paid || 0),
            status: b.status || 'Unpaid',
            payment_method: b.payment_method || 'cash',
            source_type: b.source_type || sourceType || 'appointment',
            items: Array.isArray(b.items) ? b.items : []
        };
        
        generatePrintView(bill);
    })
    .catch(function(error) {
        console.error('Error:', error);
        Swal.close();
        Swal.fire('Error!', 'Error generating print. Please try again.', 'error');
    });
}

// Export data
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
        var csvContent = 'Invoice ID,Date,Customer,Status,Subtotal,Discount Type,Discount %,Discount Amount,VAT %,VAT Amount,Total\n';
        data.forEach(function(inv) {
            csvContent += [
                inv.invoice_id,
                inv.sale_date,
                inv.customer_name || inv.walk_in_name,
                inv.status,
                inv.subtotal || inv.total_amount,
                inv.discount_type || 'none',
                inv.discount_percentage || 0,
                inv.discount_amount || 0,
                inv.vat_percentage || 0,
                inv.vat_amount || 0,
                inv.total_amount
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

// Generate Print View
function generatePrintView(bill) {
    var totalAmount = parseFloat(bill.total_amount || 0);
    var totalPaid = parseFloat(bill.total_paid || bill.amount_paid || 0);
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;

    var itemsRows = '';
    (bill.items || []).forEach(function(item) {
        var price = parseFloat(item.unit_price || item.price || 0);
        var qty = parseInt(item.quantity || 1);
        itemsRows += `
            <tr>
                <td>${escapeHtml(item.item_name || item.name || '')}</td>
                <td style="text-align:right;">₱${price.toFixed(2)}</td>
                <td style="text-align:center;">${qty}</td>
                <td style="text-align:right;">₱${(price * qty).toFixed(2)}</td>
            </tr>
        `;
    });

    var printWindow = window.open('', '_blank', 'width=800,height=900');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(bill.invoice_id)}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 30px; color: #0f172a; }
                h1 { font-size: 20px; margin-bottom: 4px; }
                table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
                th { text-align: left; background: #f8fafc; }
                .totals td { border: none; }
                .totals .label { text-align: right; font-weight: 600; }
                .totals .value { text-align: right; }
                .grand-total { font-size: 16px; font-weight: 700; color: #0d9488; }
            </style>
        </head>
        <body>
            <h1>EyeCore Clinic</h1>
            <p>Invoice: <strong>${escapeHtml(bill.invoice_id)}</strong><br>
               Date: ${escapeHtml(bill.sale_date)}<br>
               Customer: ${escapeHtml(bill.customer_name)}<br>
               Status: ${escapeHtml(bill.status)}</p>
            <table>
                <thead>
                    <tr><th>Item</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Total</th></tr>
                </thead>
                <tbody>
                    ${itemsRows || '<tr><td colspan="4" style="text-align:center;color:#64748b;">No items found</td></tr>'}
                </tbody>
<tfoot>
    <tr class="table-light">
        <td colspan="4" class="text-end fw-semibold">Subtotal:</td>
        <td id="subtotal" class="fw-bold">₱0.00</td>
        <td></td>
    </tr>
    <tr id="discountRow" style="display: none;">
        <td colspan="4" class="text-end fw-semibold text-success">PWD/Senior Discount:</td>
        <td id="discountDisplay" class="fw-bold text-success">₱0.00</td>
        <td></td>
    </tr>
    <tr id="vatRow">
        <td colspan="4" class="text-end fw-semibold text-warning">VAT (12%):</td>
        <td id="vatDisplay" class="fw-bold text-warning">₱0.00</td>
        <td></td>
    </tr>
    <tr>
        <td colspan="4" class="text-end fw-semibold">Manual Discount:</td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₱</span>
                <input type="number" class="form-control" id="discount" value="0" min="0" step="0.01" onchange="calculateTotal()">
            </div>
        </td>
        <td></td>
    </tr>
    <tr class="table-primary">
        <td colspan="4" class="text-end fw-bold">Total:</td>
        <td id="totalAmount" class="fw-bold text-primary fs-5">₱0.00</td>
        <td></td>
    </tr>
</tfoot>
            </table>
            <script>window.onload = function() { window.print(); };<\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// ✅ CHECK PWD/SENIOR STATUS FOR REGISTERED PATIENT
// ============================================
function checkPwdSenior(patientId) {
    if (!patientId) {
        document.getElementById('pwd_senior_status').style.display = 'none';
        return;
    }
    
    // ✅ Use api/sales.php instead of api/patients.php
    fetch('api/sales.php?action=check_pwd_senior&patient_id=' + patientId + '&clinic_id=' + <?php echo $clinic_id; ?>)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.is_pwd_senior) {
                document.getElementById('pwd_senior_status').style.display = 'block';
                document.getElementById('pwd_senior_status').style.background = '#d1fae5';
                document.getElementById('pwd_senior_status').style.border = '1px solid #10b981';
                document.getElementById('pwd_senior_label').innerHTML = 
                    '<strong>' + data.verification_type.toUpperCase() + '</strong> Verified - ' + 
                    data.discount_percentage + '% Discount & VAT Exempt';
                document.getElementById('pwd_senior_status').querySelector('i').className = 'fas fa-check-circle';
                document.getElementById('pwd_senior_status').querySelector('i').style.color = '#10b981';
            } else {
                document.getElementById('pwd_senior_status').style.display = 'block';
                document.getElementById('pwd_senior_status').style.background = '#fef3c7';
                document.getElementById('pwd_senior_status').style.border = '1px solid #f59e0b';
                document.getElementById('pwd_senior_label').innerHTML = 
                    '⚠️ No PWD/Senior verification found. Standard VAT (12%) will apply.';
                document.getElementById('pwd_senior_status').querySelector('i').className = 'fas fa-exclamation-triangle';
                document.getElementById('pwd_senior_status').querySelector('i').style.color = '#f59e0b';
            }
            calculateTotal();
        })
        .catch(function(err) {
            console.error('Error checking PWD/Senior status:', err);
        });
}

// ============================================
// ✅ CHECK PWD/SENIOR STATUS FOR WALK-IN
// ============================================
function checkWalkInPwdSenior(name) {
    if (!name || name.length < 3) {
        document.getElementById('pwd_senior_status').style.display = 'none';
        return;
    }
    
    // ✅ Use api/sales.php instead of api/patients.php
    fetch('api/sales.php?action=check_pwd_senior_by_name&name=' + encodeURIComponent(name) + '&clinic_id=' + <?php echo $clinic_id; ?>)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.is_pwd_senior) {
                document.getElementById('pwd_senior_status').style.display = 'block';
                document.getElementById('pwd_senior_status').style.background = '#d1fae5';
                document.getElementById('pwd_senior_status').style.border = '1px solid #10b981';
                document.getElementById('pwd_senior_label').innerHTML = 
                    '<strong>' + data.verification_type.toUpperCase() + '</strong> Verified - ' + 
                    data.discount_percentage + '% Discount & VAT Exempt';
                document.getElementById('pwd_senior_status').querySelector('i').className = 'fas fa-check-circle';
                document.getElementById('pwd_senior_status').querySelector('i').style.color = '#10b981';
            } else {
                document.getElementById('pwd_senior_status').style.display = 'block';
                document.getElementById('pwd_senior_status').style.background = '#fef3c7';
                document.getElementById('pwd_senior_status').style.border = '1px solid #f59e0b';
                document.getElementById('pwd_senior_label').innerHTML = 
                    '⚠️ No PWD/Senior verification found. Standard VAT (12%) will apply.';
                document.getElementById('pwd_senior_status').querySelector('i').className = 'fas fa-exclamation-triangle';
                document.getElementById('pwd_senior_status').querySelector('i').style.color = '#f59e0b';
            }
            calculateTotal();
        })
        .catch(function(err) {
            console.error('Error checking PWD/Senior status:', err);
        });
}

// Initialize
calculateTotal();
togglePaymentFields();
</script>
</body>
</html>