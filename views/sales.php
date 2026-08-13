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
                            <th>Amount Paid</th>
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
                            
                            // ✅ FIXED: Get items and compute subtotal
                            $items_raw = trim($invoice['items'] ?? '[]');
                            $items = json_decode($items_raw, true);
                            
                            // Fix JSON if needed
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $items_raw = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $items_raw);
                                $items = json_decode($items_raw, true);
                            }
                            
                            // Compute subtotal from items
                            $computed_subtotal = 0;
                            if (is_array($items) && !empty($items)) {
                                foreach ($items as $item) {
                                    $price = floatval($item['price'] ?? 0);
                                    $qty = intval($item['quantity'] ?? 1);
                                    $computed_subtotal += $price * $qty;
                                }
                            }
                            
                            // Use computed subtotal if available
                            $display_subtotal = ($computed_subtotal > 0) ? $computed_subtotal : ($invoice['subtotal'] ?? $invoice['total_amount'] ?? 0);
                            
                            // Get item names for display
                            $itemNames = [];
                            if (is_array($items) && !empty($items)) {
                                foreach ($items as $item) {
                                    $itemNames[] = $item['name'] ?? $item['item_name'] ?? 'Item';
                                }
                            }
                        ?>
                        <tr>
                            <td class="fw-semibold"><?php echo $invoice['invoice_id']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($invoice['sale_date'])); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                                <div class="text-muted small"><?php echo $invoice['customer_code']; ?></div>
                            </td>
                            <td>
                                <?php if (!empty($itemNames)): ?>
                                    <div><?php echo count($itemNames); ?> item<?php echo count($itemNames) > 1 ? 's' : ''; ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(implode(', ', array_slice($itemNames, 0, 2))); ?><?php echo count($itemNames) > 2 ? '...' : ''; ?></small>
                                <?php else: ?>
                                    <span class="text-muted">No items</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold">₱<?php echo number_format($display_subtotal, 2); ?></td>
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
                                ₱<?php echo number_format($amount_paid, 2); ?>
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

                    <!-- PWD/SENIOR VERIFICATION DISPLAY -->
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
        <?php if(empty($patients)): ?>
            <div class="text-muted small mt-1">
                <i class="fas fa-info-circle"></i> 
                No active patients found. Please add patients first.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ✅ PHASE 2: Manual Discount for Unverified -->
    <div id="manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
        <div class="d-flex align-items-center mb-2">
            <i class="fas fa-id-card text-warning me-2"></i>
            <strong class="text-warning">Manual Discount (Unverified Patient)</strong>
        </div>
        <p class="small text-muted">Patient must present physical PWD/Senior ID before applying discount.</p>
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Discount %</label>
                <input type="number" class="form-control" id="manual_discount" name="manual_discount" value="0" min="0" max="100" step="0.01" onchange="calculateTotal()">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Verified By</label>
                <input type="text" class="form-control" id="verified_by" name="verified_by" placeholder="Staff name who verified the ID">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Number</label>
                <input type="text" class="form-control" id="id_number" name="id_number" placeholder="PWD/Senior ID number">
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
    
<!-- ✅ PHASE 6: Manual Discount for Walk-in -->
<div id="walkin_manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
    <div class="d-flex align-items-center mb-2">
        <i class="fas fa-id-card text-warning me-2"></i>
        <strong class="text-warning">Manual Discount (Walk-in with Physical ID)</strong>
    </div>
    <p class="small text-muted">Customer must present physical PWD/Senior ID before applying discount.</p>
    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Discount Type <span class="text-danger">*</span></label>
            <select class="form-select" id="walkin_manual_discount_type" name="walkin_manual_discount_type" onchange="calculateTotal()">
                <option value="senior">👴 Senior Citizen</option>
                <option value="pwd">♿ PWD</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Discount %</label>
            <input type="number" class="form-control" id="walkin_manual_discount" name="walkin_manual_discount" value="20" min="0" max="100" step="0.01" onchange="calculateTotal()" oninput="calculateTotal()">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">ID Verified By</label>
            <input type="text" class="form-control" id="walkin_verified_by" name="walkin_verified_by" placeholder="Staff name">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">ID Number</label>
            <input type="text" class="form-control" id="walkin_id_number" name="walkin_id_number" placeholder="PWD/Senior ID number">
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
                                        <td colspan="4" class="text-end fw-semibold text-success">
                                            <span id="discountLabel">PWD/Senior Discount:</span>
                                        </td>
                                        <td id="discountDisplay" class="fw-bold text-success">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr id="vatRow">
                                        <td colspan="4" class="text-end fw-semibold text-warning">VAT (12%):</td>
                                        <td id="vatDisplay" class="fw-bold text-warning">₱0.00</td>
                                        <td></td>
                                    </tr>
                                    <!-- ✅ PHASE 1: Manual Discount Section (Hidden by default) -->
                                    <tr id="manual_discount_section" style="display: none;">
                                        <td colspan="4" class="text-end fw-semibold">
                                            <span class="text-warning">Manual Discount (for unverified customers only):</span>
                                            <br><small class="text-muted">Requires physical PWD/Senior ID</small>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">%</span>
                                                <input type="number" class="form-control" id="discount" value="0" min="0" max="100" step="0.01" placeholder="e.g., 20">
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
                            <select class="form-select" name="payment_method" id="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="gcash">GCash</option>
                                <option value="paymaya">PayMaya</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount Paid</label>
                            <input type="number" class="form-control" name="amount_paid" step="0.01" min="0" value="0" required>
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
                    
<!-- ✅ PHASE 6: Manual Discount for Unverified -->
<div id="manual_discount_section" style="display: none;" class="mt-3 p-3 border border-warning rounded">
    <div class="d-flex align-items-center mb-2">
        <i class="fas fa-id-card text-warning me-2"></i>
        <strong class="text-warning">Manual Discount (Unverified Patient)</strong>
    </div>
    <p class="small text-muted">Patient must present physical PWD/Senior ID before applying discount.</p>
    <div class="row g-2">
        <div class="col-md-3">
            <label class="form-label fw-semibold">Discount Type <span class="text-danger">*</span></label>
            <select class="form-select" id="manual_discount_type" name="manual_discount_type" onchange="calculateTotal()">
                <option value="senior">👴 Senior Citizen</option>
                <option value="pwd">♿ PWD</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Discount %</label>
            <input type="number" class="form-control" id="manual_discount" name="manual_discount" value="20" min="0" max="100" step="0.01" onchange="calculateTotal()">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">ID Verified By</label>
            <input type="text" class="form-control" id="verified_by" name="verified_by" placeholder="Staff name">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">ID Number</label>
            <input type="text" class="form-control" id="id_number" name="id_number" placeholder="PWD/Senior ID number">
        </div>
    </div>
</div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Amount</label>
                        <input type="number" class="form-control" name="amount" id="payment_amount" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select class="form-select" name="payment_method" id="payment_method_modal" required>
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

function calculateTotal() {
    // ✅ DEBUG: Check flags
    console.log('=== FLAGS DEBUG ===');
    console.log('window.isPwdSeniorVerified:', window.isPwdSeniorVerified);
    console.log('window.isWalkInPwdSenior:', window.isWalkInPwdSenior);
    console.log('saleItems:', saleItems);
    
    let subtotal = 0;
    saleItems.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    // ✅ Check manual discount values
    var manualDiscountPercentRegistered = parseFloat(document.getElementById('manual_discount').value) || 0;
    var manualDiscountPercentWalkin = parseFloat(document.getElementById('walkin_manual_discount').value) || 0;
    var manualDiscountPercent = manualDiscountPercentRegistered || manualDiscountPercentWalkin;
    var hasManualDiscount = manualDiscountPercent > 0;
    
    console.log('manualDiscountPercent:', manualDiscountPercent);
    console.log('hasManualDiscount:', hasManualDiscount);
    
    // ✅ Check verification status using flags
    let isPwdSenior = window.isPwdSeniorVerified === true;
    let isWalkInVerified = window.isWalkInPwdSenior === true;
    
    // ✅ If manual discount is applied, force VAT exempt
    if (hasManualDiscount) {
        isWalkInVerified = true; // Treat as verified for VAT exemption
        console.log('✅ Manual discount detected - forcing VAT exempt');
    }
    
    console.log('isPwdSenior:', isPwdSenior);
    console.log('isWalkInVerified:', isWalkInVerified);
    
    const discountRate = <?php echo $pwd_senior_discount ?? 0.20; ?>;
    const vatRate = <?php echo $vat_rate ?? 0.12; ?>;
    
    let discount = 0;
    let vat = 0;
    let total = subtotal;
    let discountType = 'none';
    let discountPercentage = 0;
    
    // ✅ VERIFIED OR MANUAL: Apply discount
    if (isPwdSenior || isWalkInVerified) {
        // ✅ Use manual discount if applied, otherwise auto
        if (hasManualDiscount && manualDiscountPercent > 0) {
            discount = subtotal * (manualDiscountPercent / 100);
            discountType = 'manual';
            discountPercentage = manualDiscountPercent;
            console.log('✅ MANUAL DISCOUNT applied:', discount);
        } else {
            discount = subtotal * discountRate;
            discountType = 'senior';
            discountPercentage = discountRate * 100;
            console.log('✅ AUTO DISCOUNT applied:', discount);
        }
        total = subtotal - discount;
        vat = 0; // VAT Exempt
    } 
    // ✅ No discount - Regular customer
    else {
        vat = subtotal * vatRate;
        total = subtotal + vat;
        console.log('❌ NO DISCOUNT: Regular customer');
    }
    
    console.log('Final - Subtotal:', subtotal, 'Discount:', discount, 'VAT:', vat, 'Total:', total);
    
    // ============================================
    // ✅ PHASE 6: GET DISCOUNT TYPE FOR DISPLAY (FIXED)
    // ============================================
    var discountTypeDisplay = 'senior';
    var discountTypeIcon = '👴';
    var discountTypeLabel = 'Senior';
    
    // ✅ DEBUG: Check customer type
    var isWalkInSelected = document.querySelector('input[name="customer_type"]:checked')?.value === 'walkin';
    var isRegisteredSelected = document.querySelector('input[name="customer_type"]:checked')?.value === 'registered';
    
    console.log('=== DISCOUNT TYPE DEBUG ===');
    console.log('isWalkInSelected:', isWalkInSelected);
    console.log('isRegisteredSelected:', isRegisteredSelected);
    console.log('hasManualDiscount:', hasManualDiscount);
    console.log('discountType:', discountType);
    console.log('isPwdSenior:', isPwdSenior);
    console.log('isWalkInVerified:', isWalkInVerified);
    
    // ✅ FIXED: Check if it's MANUAL discount first
    if (discountType === 'manual' && hasManualDiscount) {
        // ✅ MANUAL DISCOUNT - get type from dropdown
        var walkinTypeSelect = document.getElementById('walkin_manual_discount_type');
        var typeSelect = document.getElementById('manual_discount_type');
        
        console.log('walkinTypeSelect value:', walkinTypeSelect?.value);
        console.log('typeSelect value:', typeSelect?.value);
        
        // ✅ PRIORITY: If walk-in is selected, use walk-in discount type
        if (isWalkInSelected && walkinTypeSelect && walkinTypeSelect.value) {
            discountTypeDisplay = walkinTypeSelect.value;
            console.log('✅ Using WALK-IN discount type:', discountTypeDisplay);
        } 
        // ✅ If registered is selected, use registered discount type
        else if (isRegisteredSelected && typeSelect && typeSelect.value) {
            discountTypeDisplay = typeSelect.value;
            console.log('✅ Using REGISTERED discount type:', discountTypeDisplay);
        }
        // ✅ Fallback: try both
        else {
            if (typeSelect && typeSelect.value) {
                discountTypeDisplay = typeSelect.value;
                console.log('✅ Fallback - Using REGISTERED discount type:', discountTypeDisplay);
            } else if (walkinTypeSelect && walkinTypeSelect.value) {
                discountTypeDisplay = walkinTypeSelect.value;
                console.log('✅ Fallback - Using WALK-IN discount type:', discountTypeDisplay);
            }
        }
        
        discountTypeIcon = discountTypeDisplay === 'pwd' ? '♿' : '👴';
        discountTypeLabel = discountTypeDisplay === 'pwd' ? 'PWD' : 'Senior';
        
        console.log('✅ MANUAL DISCOUNT type final:', discountTypeDisplay);
        console.log('✅ MANUAL DISCOUNT icon:', discountTypeIcon);
        console.log('✅ MANUAL DISCOUNT label:', discountTypeLabel);
        
    } 
    // ✅ AUTO DISCOUNT (Verified PWD/Senior)
    else if (isPwdSenior || isWalkInVerified) {
        // Get from verification status text
        var statusDiv = document.getElementById('pwd_senior_status');
        var statusText = statusDiv ? statusDiv.innerText : '';
        console.log('statusText:', statusText);
        
        if (statusText.includes('PWD')) {
            discountTypeDisplay = 'pwd';
            discountTypeIcon = '♿';
            discountTypeLabel = 'PWD';
        } else if (statusText.includes('SENIOR')) {
            discountTypeDisplay = 'senior';
            discountTypeIcon = '👴';
            discountTypeLabel = 'Senior';
        } else {
            // Fallback
            if (window.pwdSeniorType === 'pwd') {
                discountTypeDisplay = 'pwd';
                discountTypeIcon = '♿';
                discountTypeLabel = 'PWD';
            } else {
                discountTypeDisplay = 'senior';
                discountTypeIcon = '👴';
                discountTypeLabel = 'Senior';
            }
        }
        console.log('✅ AUTO DISCOUNT type detected:', discountTypeDisplay);
    }
    
    // ✅ BUILD DISCOUNT LABEL
    var discountLabelText = '';
    if (discountType === 'manual') {
        discountLabelText = 'Manual ' + discountTypeIcon + ' ' + discountTypeLabel + ' Discount';
    } else if (discountType === 'senior' || discountType === 'pwd') {
        discountLabelText = discountTypeIcon + ' ' + discountTypeLabel + ' Discount (Verified)';
    } else {
        discountLabelText = 'Discount';
    }
    
    console.log('✅ Final discount label:', discountLabelText);
    
    // ✅ UPDATE UI
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('totalAmount').textContent = '₱' + total.toFixed(2);
    document.getElementById('vatDisplay').textContent = '₱' + vat.toFixed(2);
    document.getElementById('discountDisplay').textContent = '₱' + discount.toFixed(2);
    
    // ✅ Show/hide discount row with proper label
    var discountRow = document.getElementById('discountRow');
    var discountLabel = document.getElementById('discountLabel');
    
    if (discount > 0) {
        discountRow.style.display = 'table-row';
        discountLabel.textContent = discountLabelText + ' (' + discountPercentage + '%):';
    } else {
        discountRow.style.display = 'none';
    }
    
    // ✅ Show/hide VAT row
    var vatRow = document.getElementById('vatRow');
    if (vat > 0) {
        vatRow.style.display = 'table-row';
        document.getElementById('vatDisplay').textContent = '₱' + vat.toFixed(2);
    } else {
        vatRow.style.display = 'none';
        document.getElementById('vatDisplay').textContent = '₱0.00';
    }
    
    // ✅ Update hidden inputs
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
    payment_type: document.getElementById('paymentTypeInput').value,
    // ✅ PHASE 7: Registered manual discount with type
    manual_discount: customerType === 'registered' ? document.getElementById('manual_discount').value : 0,
    manual_discount_type: customerType === 'registered' ? document.getElementById('manual_discount_type').value : 'senior',
    verified_by: customerType === 'registered' ? document.getElementById('verified_by').value : null,
    id_number: customerType === 'registered' ? document.getElementById('id_number').value : null,
    // ✅ PHASE 7: Walk-in manual discount with type
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
    
    // ✅ PHASE 4: Check if patient is verified
    // Show manual discount section only if NOT verified
    // This will be checked by calling the API
    checkAppointmentVerification(id);
    
    // Generate invoice ID
    var invoiceId = 'INV-' + new Date().toISOString().slice(0,7).replace('-','') + '-' + String(id).padStart(4, '0');
    document.getElementById('payment_invoice_id').value = invoiceId;
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

// ✅ PHASE 4: Check appointment verification status
function checkAppointmentVerification(appointmentId) {
fetch('api/sales.php?action=check_appointment_verification&appointment_id=' + appointmentId + '&clinic_id=' + <?php echo $clinic_id; ?>, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
})
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var section = document.getElementById('payment_manual_discount_section');
            if (data.success && !data.is_verified) {
                // ✅ NOT VERIFIED - Show manual discount section
                section.style.display = 'block';
                document.getElementById('payment_manual_discount').value = 0;
                document.getElementById('payment_manual_discount').disabled = false;
                document.getElementById('payment_verified_by').value = '';
                document.getElementById('payment_id_number').value = '';
            } else {
                // ✅ VERIFIED - Hide manual discount section
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
    var subtotal = parseFloat(bill.subtotal || 0);
    var discountAmount = parseFloat(bill.discount_amount || 0);
    var discountPercentage = parseFloat(bill.discount_percentage || 0);
    var discountType = bill.discount_type || 'none';
    var vatAmount = parseFloat(bill.vat_amount || 0);
    var vatPercentage = parseFloat(bill.vat_percentage || 0);
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;
    
    // ✅ PHASE 5: Get manual discount info
    var verifiedBy = bill.verified_by || null;
    var idNumber = bill.id_number || null;
    var isManualDiscount = bill.is_manual_discount || false;
    var isVerifiedDiscount = bill.is_verified_discount || false;
    
    // Determine discount label and note
    var discountLabel = '';
    var discountNote = '';
    
    if (isManualDiscount) {
        discountLabel = 'Manual Discount (Unverified with Physical ID)';
        discountNote = '✅ ID Verified by: ' + (verifiedBy || 'N/A') + ' | ID #: ' + (idNumber || 'N/A');
    } else if (isVerifiedDiscount) {
        discountLabel = discountType.toUpperCase() + ' Discount (Verified)';
        discountNote = '✅ Auto-applied - Verified ' + discountType.toUpperCase();
    } else {
        discountLabel = 'Discount';
    }
    
    var status = 'Unpaid';
    if (totalPaid >= totalAmount && totalAmount > 0) {
        status = 'Paid';
    } else if (totalPaid > 0 && totalPaid < totalAmount) {
        status = 'Partial';
    }
    
    var items = bill.items || [];
    
    // Compute subtotal from items
    var computedSubtotal = 0;
    if (Array.isArray(items) && items.length > 0) {
        items.forEach(function(item) {
            var price = parseFloat(item.unit_price || item.price || 0);
            var qty = parseInt(item.quantity || 1);
            computedSubtotal += price * qty;
        });
    }
    if (computedSubtotal > 0 && computedSubtotal > subtotal) {
        subtotal = computedSubtotal;
    }
    
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
    
    // ✅ Build discount display with note
    var discountDisplay = '';
    if (discountType !== 'none' && discountAmount > 0) {
        discountDisplay = `
            <div class="d-flex justify-content-between text-danger">
                <span>${discountLabel} (${discountPercentage}%):</span>
                <span>-₱${discountAmount.toFixed(2)}</span>
            </div>
            ${discountNote ? `
            <div class="d-flex justify-content-between text-muted small" style="font-size: 11px;">
                <span><i class="fas fa-info-circle"></i> ${discountNote}</span>
                <span></span>
            </div>
            ` : ''}
            <div class="d-flex justify-content-between">
                <span class="text-muted">Subtotal after discount:</span>
                <span>₱${(subtotal - discountAmount).toFixed(2)}</span>
            </div>
        `;
    }
    
    // Build VAT display
    var vatDisplay = '';
    if (vatAmount > 0) {
        vatDisplay = `
            <div class="d-flex justify-content-between text-warning">
                <span>VAT (${vatPercentage}%):</span>
                <span>+₱${vatAmount.toFixed(2)}</span>
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
    
    // Build payment display
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
    
    var statusClass = status === 'Paid' ? 'success' : status === 'Partial' ? 'warning' : 'danger';
    
    // Build the invoice HTML
    document.getElementById('invoiceDetails').innerHTML = `
        <!-- Invoice Header -->
        <div class="row mb-4">
            <div class="col-6">
                <h4 class="fw-bold" style="color: var(--teal);">INVOICE</h4>
                <p class="text-muted small mb-0">${escapeHtml(bill.invoice_id || 'N/A')}</p>
                <p class="text-muted small">${bill.source_type === 'walkin' ? 'Walk-in Sale' : 'Appointment'}</p>
            </div>
            <div class="col-6 text-end">
                <p class="mb-1"><strong>Date:</strong> ${bill.sale_date || 'N/A'}</p>
                <p class="mb-0"><strong>Status:</strong> <span class="badge bg-${statusClass}">${status}</span></p>
            </div>
        </div>
        
        <!-- Customer Info -->
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
                        ${isManualDiscount ? `<p><strong>ID Verified By:</strong> ${escapeHtml(verifiedBy || 'N/A')}</p>` : ''}
                        ${isManualDiscount ? `<p><strong>ID Number:</strong> ${escapeHtml(idNumber || 'N/A')}</p>` : ''}
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Items Table -->
        <div class="row mb-4">
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
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end fw-semibold">Subtotal:</td>
                                <td class="text-end fw-bold">₱${subtotal.toFixed(2)}</td>
                            </tr>
                            ${discountDisplay}
                            ${vatDisplay}
                            <tr class="table-primary">
                                <td colspan="4" class="text-end fw-bold fs-5">TOTAL:</td>
                                <td class="text-end fw-bold fs-5" style="color: var(--teal);">₱${totalAmount.toFixed(2)}</td>
                            </tr>
                            ${paymentDisplay}
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Footer Actions -->
        <div class="row mt-3">
            <div class="col-12 text-end">
                ${balanceAmount > 0 ? `
                    <button class="btn btn-warning me-2" onclick="recordPayment(${bill.id}, ${totalAmount}, ${totalPaid}, '${bill.source_type || 'appointment'}')">
                        <i class="bi bi-credit-card me-2"></i>Record Payment
                    </button>
                ` : ''}
                <button class="btn btn-teal me-2" onclick="printInvoice(${bill.id}, '${bill.source_type || 'appointment'}')">
                    <i class="bi bi-printer me-2"></i>Print
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
    var subtotal = parseFloat(bill.subtotal || 0);
    var discountAmount = parseFloat(bill.discount_amount || 0);
    var discountPercentage = parseFloat(bill.discount_percentage || 0);
    var discountType = bill.discount_type || 'none';
    var vatAmount = parseFloat(bill.vat_amount || 0);
    var vatPercentage = parseFloat(bill.vat_percentage || 0);
    var balanceAmount = totalAmount - totalPaid;
    if (balanceAmount < 0) balanceAmount = 0;
    
    // Parse items
    var items = bill.items || [];
    if (typeof items === 'string') {
        try {
            items = JSON.parse(items);
        } catch(e) {
            items = [];
        }
    }
    if (!Array.isArray(items)) {
        items = [];
    }
    
    // Compute subtotal from items if available
    var computedSubtotal = 0;
    if (items.length > 0) {
        items.forEach(function(item) {
            var price = parseFloat(item.unit_price || item.price || 0);
            var qty = parseInt(item.quantity || 1);
            computedSubtotal += price * qty;
        });
    }
    if (computedSubtotal > 0 && computedSubtotal > subtotal) {
        subtotal = computedSubtotal;
    }
    
    // Build items rows
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
    
    // Build discount rows
    var discountRows = '';
    if (discountType !== 'none' && discountAmount > 0) {
        discountRows += `
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;color:#dc2626;">${discountType.toUpperCase()} Discount (${discountPercentage}%):</td>
                <td style="text-align:right;color:#dc2626;">-₱${discountAmount.toFixed(2)}</td>
            </tr>
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;">Subtotal after discount:</td>
                <td style="text-align:right;">₱${(subtotal - discountAmount).toFixed(2)}</td>
            </tr>
        `;
    }
    
    // Build VAT rows
    var vatRows = '';
    if (vatAmount > 0) {
        vatRows = `
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;color:#d97706;">VAT (${vatPercentage}%):</td>
                <td style="text-align:right;color:#d97706;">+₱${vatAmount.toFixed(2)}</td>
            </tr>
        `;
    } else {
        vatRows = `
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;color:#065f46;">VAT:</td>
                <td style="text-align:right;color:#065f46;">Exempt</td>
            </tr>
        `;
    }
    
    // Build payment rows
    var paymentRows = '';
    if (totalPaid > 0) {
        paymentRows = `
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;color:#065f46;">Amount Paid:</td>
                <td style="text-align:right;color:#065f46;font-weight:700;">₱${totalPaid.toFixed(2)}</td>
            </tr>
            <tr>
                <td colspan="3" style="text-align:right;font-weight:600;${balanceAmount > 0 ? 'color:#dc2626;' : 'color:#065f46;'}">Balance:</td>
                <td style="text-align:right;font-weight:700;${balanceAmount > 0 ? 'color:#dc2626;' : 'color:#065f46;'}">₱${balanceAmount.toFixed(2)}</td>
            </tr>
        `;
    }
    
    // Get status
    var status = 'Unpaid';
    if (totalPaid >= totalAmount && totalAmount > 0) {
        status = 'Paid';
    } else if (totalPaid > 0 && totalPaid < totalAmount) {
        status = 'Partial';
    }
    
    // Generate print window
    var printWindow = window.open('', '_blank', 'width=800,height=900');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(bill.invoice_id)}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: Arial, Helvetica, sans-serif; 
                    padding: 40px; 
                    color: #0f172a; 
                    max-width: 800px; 
                    margin: 0 auto;
                }
                .header { 
                    display: flex; 
                    justify-content: space-between; 
                    align-items: start; 
                    border-bottom: 2px solid #0d9488; 
                    padding-bottom: 15px; 
                    margin-bottom: 20px;
                }
                .header h1 { 
                    font-size: 24px; 
                    color: #0d9488; 
                    margin-bottom: 4px; 
                }
                .header .sub { color: #64748b; font-size: 14px; }
                .info-grid { 
                    display: grid; 
                    grid-template-columns: 1fr 1fr; 
                    gap: 15px; 
                    margin-bottom: 25px; 
                }
                .info-grid .label { font-weight: 600; color: #475569; }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin: 20px 0; 
                }
                th { 
                    background: #f8fafc; 
                    padding: 10px 12px; 
                    text-align: left; 
                    font-weight: 600; 
                    border-bottom: 2px solid #e2e8f0;
                }
                td { 
                    padding: 8px 12px; 
                    border-bottom: 1px solid #e2e8f0; 
                }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .fw-bold { font-weight: 700; }
                .total-row { 
                    border-top: 2px solid #0d9488; 
                    font-size: 18px; 
                }
                .total-row td { padding-top: 12px; }
                .footer { 
                    margin-top: 30px; 
                    padding-top: 15px; 
                    border-top: 1px solid #e2e8f0; 
                    color: #64748b; 
                    font-size: 12px; 
                    text-align: center;
                }
                .status-badge {
                    display: inline-block;
                    padding: 4px 12px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-paid { background: #d1fae5; color: #065f46; }
                .status-partial { background: #fef3c7; color: #92400e; }
                .status-unpaid { background: #fee2e2; color: #991b1b; }
                .discount { color: #dc2626; }
                .vat { color: #d97706; }
                .vat-exempt { color: #065f46; }
                .paid { color: #065f46; }
                .balance-positive { color: #dc2626; }
                .balance-zero { color: #065f46; }
                @media print {
                    body { padding: 20px; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>INVOICE</h1>
                    <div class="sub">${escapeHtml(bill.invoice_id || 'N/A')}</div>
                    <div class="sub">${bill.source_type === 'walkin' ? 'Walk-in Sale' : 'Appointment'}</div>
                </div>
                <div style="text-align:right;">
                    <div><strong>Date:</strong> ${bill.sale_date || 'N/A'}</div>
                    <div><strong>Status:</strong> 
                        <span class="status-badge status-${status.toLowerCase()}">${status}</span>
                    </div>
                </div>
            </div>
            
            <!-- Customer Info -->
            <div class="info-grid">
                <div>
                    <div class="label">Customer Name</div>
                    <div>${escapeHtml(bill.customer_name || 'N/A')}</div>
                    <div class="label" style="margin-top:8px;">Type</div>
                    <div>${bill.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</div>
                </div>
                <div>
                    ${bill.doctor_name ? `
                        <div class="label">Doctor</div>
                        <div>Dr. ${escapeHtml(bill.doctor_name)}</div>
                    ` : ''}
                    ${bill.reference_number ? `
                        <div class="label" style="margin-top:8px;">Reference</div>
                        <div>${escapeHtml(bill.reference_number)}</div>
                    ` : ''}
                </div>
            </div>
            
            <!-- Items Table -->
            <table>
                <thead>
                    <tr>
                        <th style="width:50%;">Item</th>
                        <th style="width:20%;text-align:right;">Price</th>
                        <th style="width:15%;text-align:center;">Qty</th>
                        <th style="width:25%;text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsRows}
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right;font-weight:600;">Subtotal:</td>
                        <td style="text-align:right;font-weight:700;">₱${subtotal.toFixed(2)}</td>
                    </tr>
                    ${discountRows}
                    ${vatRows}
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right;font-weight:700;font-size:18px;color:#0d9488;">TOTAL:</td>
                        <td style="text-align:right;font-weight:700;font-size:18px;color:#0d9488;">₱${totalAmount.toFixed(2)}</td>
                    </tr>
                    ${paymentRows}
                </tfoot>
            </table>
            
            <!-- Footer -->
            <div class="footer">
                <p>Thank you for your business!</p>
                <p>Generated on ${new Date().toLocaleString()}</p>
            </div>
            
            <script>
                // Auto-print when loaded
                window.onload = function() {
                    window.print();
                    window.close();
                };
            <\/script>
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
// ✅ PHASE 3: CHECK PWD/SENIOR STATUS FOR WALK-IN
// ============================================
function checkWalkInPwdSenior(name) {
    console.log('🔍 checkWalkInPwdSenior called with name:', name);
    
    if (!name || name.length < 3) {
        console.log('❌ Name too short, hiding discount section');
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
    .then(function(res) { 
        console.log('📡 API Response Status:', res.status);
        return res.json(); 
    })
    .then(function(data) {
        console.log('📦 API Response Data:', data);
        
        // ✅ Handle data inside data array
        var responseData = data.data && data.data.length > 0 ? data.data[0] : data;
        console.log('📦 Processed data:', responseData);
        
        var statusDiv = document.getElementById('pwd_senior_status');
        var manualDiscountDiv = document.getElementById('walkin_manual_discount_section');
        var discountInput = document.getElementById('walkin_manual_discount');
        
        if (responseData.success && responseData.is_pwd_senior) {
            // ✅ FOUND: Existing verified patient
            console.log('✅ Walk-in VERIFIED!');
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
            
            manualDiscountDiv.style.display = 'none';
            discountInput.value = 0;
            discountInput.disabled = true;
            window.isWalkInPwdSenior = true;
            
        } else {
            // ❌ NOT FOUND: Allow manual discount
            console.log('❌ Walk-in NOT VERIFIED - Manual discount available');
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
            
            manualDiscountDiv.style.display = 'block';
            discountInput.disabled = false;
            discountInput.value = 0;
            discountInput.placeholder = 'Enter discount % (e.g., 20)';
            window.isWalkInPwdSenior = false;
        }
        calculateTotal();
    })
    .catch(function(err) {
        console.error('Error checking PWD/Senior status:', err);
        window.isWalkInPwdSenior = false;
        calculateTotal();
    });
}
function checkPwdSenior(patientId) {
    if (!patientId) {
        document.getElementById('pwd_senior_status').style.display = 'none';
        document.getElementById('manual_discount_section').style.display = 'none';
        document.getElementById('discount').disabled = false;
        return;
    }
    
    var url = 'api/sales.php?action=check_pwd_senior&patient_id=' + patientId + '&clinic_id=' + <?php echo $clinic_id; ?>;
    console.log('Checking PWD/Senior status:', url);
    
    fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(function(res) { 
        console.log('Response status:', res.status);
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        return res.json(); 
    })
    .then(function(response) {
        console.log('Full response:', response);
        
        // ✅ FIXED: Handle data inside data array
        var data = response.data && response.data.length > 0 ? response.data[0] : response;
        
        console.log('Processed data:', data);
        
        if (data.is_pwd_senior) {
            // ✅ VERIFIED
            document.getElementById('pwd_senior_status').style.display = 'block';
            document.getElementById('pwd_senior_status').style.background = '#d1fae5';
            document.getElementById('pwd_senior_status').style.border = '2px solid #10b981';
            document.getElementById('pwd_senior_status').style.borderRadius = '8px';
            document.getElementById('pwd_senior_status').style.padding = '12px';
            document.getElementById('pwd_senior_status').innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-check-circle" style="color: #10b981; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #065f46;">✅ VERIFIED ' + (data.verification_type || '').toUpperCase() + '</span>' +
                    '<span class="ms-2 badge bg-success">' + (data.discount_percentage || 20) + '% Discount</span>' +
                    '<span class="ms-2 badge bg-info">VAT Exempt</span>' +
                    '<span class="ms-2 text-muted small">(Auto-applied - No manual override needed)</span>' +
                '</div>';
            
            document.getElementById('manual_discount_section').style.display = 'none';
            document.getElementById('discount').value = 0;
            document.getElementById('discount').disabled = true;
            window.isPwdSeniorVerified = true;
            
        } else {
            // ❌ NOT VERIFIED
            document.getElementById('pwd_senior_status').style.display = 'block';
            document.getElementById('pwd_senior_status').style.background = '#fef3c7';
            document.getElementById('pwd_senior_status').style.border = '2px solid #f59e0b';
            document.getElementById('pwd_senior_status').style.borderRadius = '8px';
            document.getElementById('pwd_senior_status').style.padding = '12px';
            document.getElementById('pwd_senior_status').innerHTML = 
                '<div class="d-flex align-items-center">' +
                    '<i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 20px;"></i>' +
                    '<span class="ms-2 fw-bold" style="color: #92400e;">⚠️ NOT VERIFIED</span>' +
                    '<span class="ms-2 text-muted small">No PWD/Senior verification found</span>' +
                '</div>';
            
            document.getElementById('manual_discount_section').style.display = 'block';
            document.getElementById('discount').disabled = false;
            document.getElementById('discount').value = 0;
            window.isPwdSeniorVerified = false;
        }
        calculateTotal();
    })
    .catch(function(err) {
        console.error('Error:', err);
        document.getElementById('pwd_senior_status').style.display = 'block';
        document.getElementById('pwd_senior_status').style.background = '#fee2e2';
        document.getElementById('pwd_senior_status').style.border = '2px solid #dc2626';
        document.getElementById('pwd_senior_status').innerHTML = 
            '<div class="d-flex align-items-center">' +
                '<i class="fas fa-exclamation-circle" style="color: #dc2626; font-size: 20px;"></i>' +
                '<span class="ms-2 text-danger">Error: ' + err.message + '</span>' +
            '</div>';
        document.getElementById('manual_discount_section').style.display = 'block';
        document.getElementById('discount').disabled = false;
        calculateTotal();
    });
}

calculateTotal();
togglePaymentFields();
</script>
</body>
</html>