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

// ✅ RBAC Permission Check - MUST HAVE SALES VIEW PERMISSION
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

// Fetch recent invoices
$query = "
    SELECT s.*, 
           CASE 
               WHEN s.patient_id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
               ELSE s.walk_in_name
           END AS customer_name,
           CASE 
               WHEN s.patient_id IS NOT NULL THEN p.id
               ELSE 'WALK-IN'
           END AS customer_code,
           CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) AS invoice_id
    FROM sales s
    LEFT JOIN patients p ON s.patient_id = p.id
    WHERE s.clinic_id = ?
";

$params = [$clinic_id];

if (!empty($search)) {
    $query .= " AND (
        s.id LIKE ? 
        OR CONCAT('INV-', DATE_FORMAT(s.sale_date, '%Y%m'), '-', LPAD(s.id, 4, '0')) LIKE ?
        OR p.first_name LIKE ? 
        OR p.last_name LIKE ? 
        OR p.id LIKE ?
        OR s.walk_in_name LIKE ?
    )";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

$query .= " ORDER BY s.sale_date DESC, s.created_at DESC LIMIT 10";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$statsStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN sale_date = ? THEN total_amount END), 0) as today_sales,
        COUNT(CASE WHEN sale_date = ? THEN 1 END) as today_count,
        COALESCE(SUM(CASE WHEN status = 'Paid' AND sale_date = ? THEN total_amount END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN status = 'Partial' AND sale_date = ? THEN (total_amount - amount_paid) END), 0) as partial_amount,
        COALESCE(SUM(CASE WHEN status = 'Unpaid' AND sale_date = ? THEN total_amount END), 0) as unpaid_amount
    FROM sales 
    WHERE clinic_id = ?
");
$statsStmt->execute([$today, $today, $today, $today, $today, $clinic_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

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
        
        .permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--teal);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 9999;
            opacity: 0.7;
        }
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
                <div class="stat-label">Partial Payment</div>
                <div class="stat-value text-warning">₱<?php echo number_format($stats['partial_amount'] ?? 0); ?></div>
                <div class="stat-label">pending balance</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-label">Unpaid</div>
                <div class="stat-value text-danger">₱<?php echo number_format($stats['unpaid_amount'] ?? 0); ?></div>
                <div class="stat-label">outstanding amount</div>
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
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </thead>
                        <tbody>
                            <?php if(empty($invoices)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <?php echo empty($search) ? 'No invoices found.' : 'No invoices match your search.'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($invoices as $invoice): ?>
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
                                            $itemNames = array_slice(array_column($items, 'name'), 0, 2);
                                            echo htmlspecialchars(implode(', ', $itemNames));
                                            if(count($items) > 2) echo '...';
                                        } else {
                                            echo 'No items';
                                        }
                                        ?>
                                    </td>
                                    <td class="fw-bold">₱<?php echo number_format($invoice['total_amount'], 2); ?></td>
                                    <td>₱<?php echo number_format($invoice['amount_paid'], 2); ?></td>
                                    <td>
                                        <?php 
                                        $statusColors = [
                                            'Paid' => 'success',
                                            'Partial' => 'warning',
                                            'Unpaid' => 'danger'
                                        ];
                                        $color = $statusColors[$invoice['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>"><?php echo $invoice['status']; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <?php if ($canView): ?>
                                                <button class="btn btn-sm btn-outline-info" onclick="viewInvoice(<?php echo $invoice['id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="printInvoice(<?php echo $invoice['id']; ?>)">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($canEdit && $invoice['status'] !== 'Paid'): ?>
                                                <button class="btn btn-sm btn-outline-success" onclick="recordPayment(<?php echo $invoice['id']; ?>, <?php echo $invoice['total_amount']; ?>, <?php echo $invoice['amount_paid']; ?>)">
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

                    <!-- Registered Patient Section -->
                    <div id="registered_section">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Patient</label>
                            <select class="form-select patient-select" name="patient_id" style="width: 100%;">
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
                                <input type="text" class="form-control" name="walk_in_name" placeholder="Enter customer name">
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
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Discount:</td>
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
                    <input type="hidden" name="sale_id" id="payment_sale_id">
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
// RBAC PERMISSIONS - Passed from PHP to JavaScript
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
document.querySelectorAll('input[name="customer_type"]').forEach(radio => {
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
    const method = document.getElementById('payment_method').value;
    const referenceField = document.getElementById('reference_field');
    if(method === 'cash') {
        referenceField.style.display = 'none';
    } else {
        referenceField.style.display = 'block';
    }
}

function toggleModalReferenceField() {
    const method = document.getElementById('payment_method_modal').value;
    const referenceField = document.getElementById('modal_reference_field');
    if(method === 'cash') {
        referenceField.style.display = 'none';
    } else {
        referenceField.style.display = 'block';
    }
}

// Add item
function addItem() {
    const select = document.getElementById('itemSelect');
    const selected = select.options[select.selectedIndex];
    if(!selected.value) return;
    
    const itemType = selected.dataset.type;
    const itemPrice = parseFloat(selected.dataset.price);
    
    if(itemType === 'product') {
        const stock = parseInt(selected.dataset.stock) || 0;
        if(stock <= 0) {
            Swal.fire('Error!', 'This item is out of stock', 'error');
            return;
        }
    }
    
    const existing = saleItems.find(item => item.id == selected.value && item.type == itemType);
    if(existing) {
        if(itemType === 'product') {
            const stock = parseInt(selected.dataset.stock) || 0;
            if(existing.quantity + 1 > stock) {
                Swal.fire('Error!', 'Not enough stock available', 'error');
                return;
            }
        }
        existing.quantity += 1;
    } else {
        saleItems.push({
            id: selected.value,
            name: selected.text.split(' (')[0],
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
    const tbody = document.getElementById('itemsList');
    tbody.innerHTML = '';
    
    saleItems.forEach((item, index) => {
        const total = item.price * item.quantity;
        const typeBadge = item.type === 'product' ? 
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
    const item = saleItems[index];
    
    if(item.type === 'product' && quantity > item.stock) {
        Swal.fire('Error!', `Only ${item.stock} item(s) available in stock`, 'error');
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

// Calculate totals
function calculateTotal() {
    let subtotal = 0;
    saleItems.forEach(item => {
        subtotal += item.price * item.quantity;
    });
    
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const total = subtotal - discount;
    
    document.getElementById('subtotal').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('totalAmount').textContent = `₱${total.toFixed(2)}`;
    
    document.getElementById('itemsInput').value = JSON.stringify(saleItems);
    document.getElementById('subtotalInput').value = subtotal;
    document.getElementById('discountInput').value = discount;
    document.getElementById('totalInput').value = total;
    
    updateStatus();
}

// Update status
function updateStatus() {
    const amountPaid = parseFloat(document.querySelector('input[name="amount_paid"]').value) || 0;
    const total = parseFloat(document.getElementById('totalInput').value) || 0;
    
    let status = 'Unpaid';
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
    
    const customerType = document.querySelector('input[name="customer_type"]:checked').value;
    
    const data = {
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
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/sales.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(resp => {
        if(resp.success){
            Swal.fire({
                icon: 'success',
                title: 'Created!',
                text: 'Invoice created successfully',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
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
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error!', error.message || 'Error creating invoice', 'error');
    });
});

// Record payment for existing invoice
function recordPayment(saleId, totalAmount, alreadyPaid) {
    if(!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to record payments', 'error');
        return;
    }
    
    const remainingBalance = totalAmount - alreadyPaid;
    
    if(remainingBalance <= 0) {
        Swal.fire('Info!', 'This invoice is already fully paid', 'info');
        return;
    }
    
    document.getElementById('payment_sale_id').value = saleId;
    document.getElementById('payment_total_amount').value = `₱${totalAmount.toFixed(2)}`;
    document.getElementById('payment_already_paid').value = `₱${alreadyPaid.toFixed(2)}`;
    document.getElementById('payment_remaining_balance').value = `₱${remainingBalance.toFixed(2)}`;
    document.getElementById('payment_amount').value = remainingBalance;
    document.getElementById('payment_amount').max = remainingBalance;
    
    fetch(`api/sales.php?id=${saleId}`)
        .then(res => res.json())
        .then(invoice => {
            document.getElementById('payment_invoice_id').value = invoice.invoice_id;
        });
    
    new bootstrap.Modal(document.getElementById('recordPaymentModal')).show();
}

// Submit payment - UPDATED
function submitPayment() {
    const amount = parseFloat(document.getElementById('payment_amount').value);
    const remainingBalance = parseFloat(document.getElementById('payment_remaining_balance').value.replace('₱', ''));
    
    if(amount <= 0) {
        Swal.fire('Error!', 'Please enter a valid amount', 'error');
        return;
    }
    
    if(amount > remainingBalance) {
        Swal.fire('Error!', 'Amount cannot exceed remaining balance', 'error');
        return;
    }
    
    // ✅ Get appointment_id from the sale/invoice if available
    let appointmentId = null;
    // You can store appointment_id in a hidden field when viewing invoice
    
    const formData = {
        sale_id: document.getElementById('payment_sale_id').value,
        appointment_id: appointmentId,  // ✅ Include appointment_id
        amount: amount,
        payment_method: document.querySelector('#recordPaymentForm select[name="payment_method"]').value,
        reference_number: document.querySelector('#recordPaymentForm input[name="reference_number"]').value,
        notes: document.querySelector('#recordPaymentForm textarea[name="notes"]').value,
        payment_type: amount >= remainingBalance ? 'full' : 'partial'
    };
    
    Swal.fire({
        title: 'Recording Payment...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/payments.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(formData)
    })
    .then(res => res.json())
    .then(resp => {
        if(resp.success) {
            let title = 'Success!';
            let message = resp.message || 'Payment recorded successfully';
            
            if(resp.fully_paid) {
                title = '✅ FULLY PAID!';
                message = 'Appointment is now COMPLETED.';
            }
            
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('recordPaymentModal')).hide();
                location.reload();
            });
        } else {
            throw new Error(resp.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error!', error.message || 'Error recording payment', 'error');
    });
}
// View invoice with payment history
function viewInvoice(id) {
    Swal.fire({
        title: 'Loading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/sales.php?id=${id}`)
        .then(res => res.json())
        .then(invoice => {
            return fetch(`api/payments.php?sale_id=${id}`)
                .then(res => res.json())
                .then(payments => {
                    return { invoice, payments };
                });
        })
        .then(({ invoice, payments }) => {
            Swal.close();
            
            const items = invoice.items ? JSON.parse(invoice.items) : [];
            let itemsHtml = '';
            items.forEach(item => {
                const typeBadge = item.type === 'product' ? 
                    '<span class="badge bg-primary">Product</span>' : 
                    '<span class="badge bg-success">Service</span>';
                itemsHtml += `
                    <tr>
                        <td>${escapeHtml(item.name)}</td>
                        <td>${typeBadge}</td>
                        <td class="text-end">₱${parseFloat(item.price).toFixed(2)}</td>
                        <td class="text-center">${item.quantity}</td>
                        <td class="text-end fw-bold">₱${(item.price * item.quantity).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            let paymentsHtml = '';
            if(payments && payments.length > 0) {
                paymentsHtml = `
                    <div class="row mt-4">
                        <div class="col-12">
                            <h6 class="fw-semibold mb-3">Payment History</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${payments.map(p => `
                                            <tr>
                                                <td>${new Date(p.payment_date || p.created_at).toLocaleString()}</td>
                                                <td class="text-end fw-bold">₱${parseFloat(p.amount).toFixed(2)}</td>
                                                <td><span class="badge bg-secondary">${p.payment_method}</span></td>
                                                <td>${p.reference_number || p.payment_reference || 'N/A'}</td>
                                                <td><span class="badge ${p.payment_type === 'full' ? 'bg-success' : 'bg-warning'}">${p.payment_type || 'full'}</span></td>
                                                <td><span class="badge ${p.payment_status === 'paid' ? 'bg-success' : 'bg-secondary'}">${p.payment_status}</span></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <td colspan="2" class="text-end fw-bold">Total Paid:</td>
                                            <td colspan="4"><strong class="text-success">₱${payments.reduce((sum, p) => sum + parseFloat(p.amount), 0).toFixed(2)}</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                paymentsHtml = `
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle me-2"></i> No payment records found for this invoice.
                            </div>
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('invoiceDetails').innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="fw-semibold border-bottom pb-2 mb-3">Invoice Information</h6>
                        <p><strong>Invoice ID:</strong> ${invoice.invoice_id}</p>
                        <p><strong>Date:</strong> ${invoice.sale_date}</p>
                        <p><strong>Status:</strong> <span class="badge bg-${invoice.status === 'Paid' ? 'success' : invoice.status === 'Partial' ? 'warning' : 'danger'}">${invoice.status}</span></p>
                        <p><strong>Payment Method:</strong> ${invoice.payment_method || 'N/A'}</p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-semibold border-bottom pb-2 mb-3">Customer Information</h6>
                        <p><strong>Name:</strong> ${escapeHtml(invoice.customer_name || invoice.walk_in_name || 'N/A')}</p>
                        <p><strong>Type:</strong> ${invoice.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</p>
                        ${invoice.patient_id ? `<p><strong>Patient ID:</strong> ${invoice.patient_code}</p>` : ''}
                        ${invoice.walk_in_contact ? `<p><strong>Contact:</strong> ${escapeHtml(invoice.walk_in_contact)}</p>` : ''}
                        ${invoice.walk_in_email ? `<p><strong>Email:</strong> ${escapeHtml(invoice.walk_in_email)}</p>` : ''}
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
                                        <td class="text-end fw-bold">₱${parseFloat(invoice.subtotal).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">Discount:</td>
                                        <td class="text-end">₱${parseFloat(invoice.discount).toFixed(2)}</td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td colspan="4" class="text-end fw-bold">Total Amount:</td>
                                        <td class="text-end fw-bold text-primary fs-5">₱${parseFloat(invoice.total_amount).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">Amount Paid:</td>
                                        <td class="text-end fw-bold text-success">₱${parseFloat(invoice.amount_paid).toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold">Balance:</td>
                                        <td class="text-end fw-bold text-danger">₱${(parseFloat(invoice.total_amount) - parseFloat(invoice.amount_paid)).toFixed(2)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                ${paymentsHtml}
            `;
            
            new bootstrap.Modal(document.getElementById('viewInvoiceModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Error loading invoice details', 'error');
        });
}

// Print invoice
function printInvoice(id) {
    Swal.fire({
        title: 'Preparing Print...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/sales.php?id=${id}`)
        .then(res => res.json())
        .then(invoice => {
            Swal.close();
            
            const items = invoice.items ? JSON.parse(invoice.items) : [];
            let itemsHtml = '';
            items.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td>${escapeHtml(item.name)} ${item.type === 'product' ? '(Product)' : '(Service)'}</td>
                        <td class="text-center">${item.quantity}</td>
                        <td class="text-end">₱${parseFloat(item.price).toFixed(2)}</td>
                        <td class="text-end">₱${(item.price * item.quantity).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Invoice ${invoice.invoice_id}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; margin-bottom: 30px; }
                        .header h2 { color: #0d9488; margin-bottom: 5px; }
                        .info { margin-bottom: 20px; }
                        .info table { width: 100%; }
                        .info td { padding: 5px 0; }
                        .items { margin: 20px 0; }
                        .items table { width: 100%; border-collapse: collapse; }
                        .items th, .items td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        .items th { background-color: #f2f2f2; }
                        .total { margin-top: 20px; }
                        .total table { width: 50%; margin-left: auto; }
                        .total td { padding: 5px; }
                        .total .label { font-weight: bold; }
                        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #666; }
                        @media print { 
                            body { margin: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>EyeCore Optical Clinic</h2>
                        <p>123 Vision Street, Makati City</p>
                        <p>Tel: (02) 123-4567 | Email: info@eyecore.com</p>
                        <h3>INVOICE</h3>
                    </div>
                    
                    <div class="info">
                        <table>
                            <tr>
                                <td><strong>Invoice #:</strong> ${invoice.invoice_id}</td>
                                <td><strong>Date:</strong> ${invoice.sale_date}</td>
                            </tr>
                            <tr>
                                <td><strong>Customer:</strong> ${escapeHtml(invoice.customer_name || invoice.walk_in_name || 'N/A')}</td>
                                <td><strong>Status:</strong> ${invoice.status}</td>
                            </tr>
                            <tr>
                                <td><strong>Customer Type:</strong> ${invoice.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</td>
                                <td><strong>Payment Method:</strong> ${invoice.payment_method || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="items">
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>${itemsHtml}</tbody>
                        </table>
                    </div>
                    
                    <div class="total">
                        <table>
                            <tr>
                                <td class="label">Subtotal:</td>
                                <td class="text-end">₱${parseFloat(invoice.subtotal).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td class="label">Discount:</td>
                                <td class="text-end">₱${parseFloat(invoice.discount).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td class="label"><strong>Total Amount:</strong></td>
                                <td class="text-end"><strong>₱${parseFloat(invoice.total_amount).toFixed(2)}</strong></td>
                            </tr>
                            <tr>
                                <td class="label">Amount Paid:</td>
                                <td class="text-end">₱${parseFloat(invoice.amount_paid).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td class="label"><strong>Balance:</strong></td>
                                <td class="text-end"><strong>₱${(parseFloat(invoice.total_amount) - parseFloat(invoice.amount_paid)).toFixed(2)}</strong></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="footer">
                        <p>Thank you for your business!</p>
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                    </div>
                    
                    <div class="no-print" style="margin-top: 20px; text-align: center;">
                        <button onclick="window.print()" style="padding: 10px 20px; background: #0d9488; color: white; border: none; cursor: pointer; border-radius: 5px;">
                            Print This Invoice
                        </button>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Error generating print', 'error');
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
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/sales.php?export=1')
        .then(res => res.json())
        .then(data => {
            const csvContent = 'Invoice ID,Date,Customer,Status,Subtotal,Discount,Total,Paid,Balance\n' +
                data.map(inv => [
                    inv.invoice_id,
                    inv.sale_date,
                    inv.customer_name || inv.walk_in_name,
                    inv.status,
                    inv.subtotal,
                    inv.discount,
                    inv.total_amount,
                    inv.amount_paid,
                    (inv.total_amount - inv.amount_paid).toFixed(2)
                ].join(',')).join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
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
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Error exporting data', 'error');
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Check if there's an appointment_id in URL
$(document).ready(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const appointmentId = urlParams.get('appointment_id');
    
    if(appointmentId) {
        loadBillByAppointment(appointmentId);
    }
});

// Load bill by appointment ID
function loadBillByAppointment(appointmentId) {
    Swal.fire({
        title: 'Loading Bill...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/sales.php?appointment_id=${appointmentId}`)
        .then(res => res.json())
        .then(data => {
            Swal.close();
            
            if(data.success && data.bill) {
                // Show the bill in view invoice modal
                showBillFromAppointment(data.bill);
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Bill Pending',
                    text: 'Your consultation is complete! The clinic will generate your bill shortly. Please refresh in a few minutes or contact the clinic.',
                    confirmButtonText: 'OK'
                }).then(() => {
                    // Clear URL parameter
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Error loading bill', 'error');
        });
}
// Show bill from appointment
function showBillFromAppointment(bill) {
    const items = bill.items || [];
    let itemsHtml = '';
    items.forEach(item => {
        const typeBadge = item.item_type === 'product' ? 
            '<span class="badge bg-primary">Product</span>' : 
            '<span class="badge bg-success">Service</span>';
        itemsHtml += `
            <tr>
                <td>${escapeHtml(item.item_name)}</span> </span>
                <td>${typeBadge}</span> </span>
                <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</span> </span>
                <td class="text-center">${item.quantity}</span> </span>
                <td class="text-end fw-bold">₱${parseFloat(item.total_price).toFixed(2)}</span> </span>
             </span>
        `;
    });
    
    const totalPaid = parseFloat(bill.total_paid || 0);
    const balance = parseFloat(bill.total_amount) - totalPaid;
    
    document.getElementById('invoiceDetails').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Invoice Information</h6>
                <p><strong>Invoice ID:</strong> ${bill.invoice_id}</p>
                <p><strong>Date:</strong> ${bill.sale_date}</p>
                <p><strong>Status:</strong> <span class="badge bg-${bill.status === 'Paid' ? 'success' : bill.status === 'Partial' ? 'warning' : 'danger'}">${bill.status}</span></p>
                <p><strong>Appointment ID:</strong> ${bill.appointment_id || 'N/A'}</p>
            </div>
            <div class="col-md-6">
                <h6 class="fw-semibold border-bottom pb-2 mb-3">Customer Information</h6>
                <p><strong>Name:</strong> ${escapeHtml(bill.customer_name || bill.walk_in_name || 'N/A')}</p>
                <p><strong>Type:</strong> ${bill.patient_id ? 'Registered Patient' : 'Walk-in Customer'}</p>
                ${bill.walk_in_contact ? `<p><strong>Contact:</strong> ${escapeHtml(bill.walk_in_contact)}</p>` : ''}
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
                            </span>
                        </thead>
                        <tbody>${itemsHtml}</tbody>
                        <tfoot class="table-light">
                            <tr class="table-primary">
                                <td colspan="4" class="text-end fw-bold">Total Amount:</td>
                                <td class="text-end fw-bold text-primary fs-5">₱${parseFloat(bill.total_amount).toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Amount Paid:</td>
                                <td class="text-end fw-bold text-success">₱${totalPaid.toFixed(2)}</td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Balance:</td>
                                <td class="text-end fw-bold text-danger">₱${balance.toFixed(2)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-12 text-end">
                ${balance > 0 ? `
                    <button class="btn btn-warning me-2" onclick="recordPaymentFromModal(${bill.id}, ${parseFloat(bill.total_amount)}, ${totalPaid})">
                        <i class="bi bi-credit-card me-2"></i>Record Payment
                    </button>
                ` : ''}
                <button class="btn btn-teal" onclick="printInvoice(${bill.id})">
                    <i class="bi bi-printer me-2"></i>Print Invoice
                </button>
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    `;
    
    new bootstrap.Modal(document.getElementById('viewInvoiceModal')).show();
    
    // Clear URL parameter
    window.history.replaceState({}, document.title, window.location.pathname);
}

// Record payment from modal
function recordPaymentFromModal(saleId, totalAmount, alreadyPaid) {
    bootstrap.Modal.getInstance(document.getElementById('viewInvoiceModal')).hide();
    recordPayment(saleId, totalAmount, alreadyPaid);
}



// Initialize
calculateTotal();
togglePaymentFields();
</script>
</body>
</html>