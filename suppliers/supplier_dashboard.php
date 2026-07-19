<?php
// C:\xampp\htdocs\eyecore\suppliers\supplier_dashboard.php
session_start();
require_once __DIR__ . '/../config/db.php';

// Check if supplier is logged in
if (!isset($_SESSION['supplier_id'])) {
    header("Location: supplier_login.php");
    exit();
}

global $pdo;

try {
    // Get supplier information
    $query = "SELECT s.*, u.last_login, u.email as user_email
              FROM suppliers s 
              JOIN users u ON s.created_by = u.id 
              WHERE s.id = :supplier_id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':supplier_id', $_SESSION['supplier_id']);
    $stmt->execute();
    $supplier = $stmt->fetch();

    // Get supplier contacts
    $contactQuery = "SELECT * FROM supplier_contacts WHERE supplier_id = :supplier_id";
    $contactStmt = $pdo->prepare($contactQuery);
    $contactStmt->bindParam(':supplier_id', $_SESSION['supplier_id']);
    $contactStmt->execute();
    $contacts = $contactStmt->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Supplier Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --sidebar-width: 280px;
        }
        body {
            background-color: #f3f4f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        .sidebar {
            background: white;
            height: 100vh;
            width: var(--sidebar-width);
            position: fixed;
            left: 0;
            top: 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .sidebar .nav-link {
            color: #4b5563;
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 0;
            border-radius: 0;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover {
            background-color: #f3f4f6;
            color: var(--teal-600);
        }
        .sidebar .nav-link.active {
            background: linear-gradient(90deg, var(--teal-600) 0%, #14b8a6 100%);
            color: white;
            box-shadow: 0 4px 10px rgba(13, 148, 136, 0.3);
        }
        .sidebar .nav-link i {
            width: 24px;
            margin-right: 10px;
        }
        .sidebar .nav-section {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            padding: 1rem 1.5rem 0.5rem;
            font-weight: 600;
        }
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px 30px;
            min-height: 100vh;
        }
        .content-header {
            background: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .page-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .btn-teal {
            background-color: var(--teal-600);
            color: white;
            border: none;
        }
        .btn-teal:hover {
            background-color: var(--teal-700);
            color: white;
        }
        .badge-pending { background-color: #fbbf24; color: #92400e; }
        .badge-approved { background-color: #34d399; color: #065f46; }
        .badge-rejected { background-color: #f87171; color: #991b1b; }
        .badge-shipped { background-color: #60a5fa; color: #1e3a8a; }
        .badge-delivered { background-color: #a78bfa; color: #5b21b6; }
        .module-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .module-content.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .quick-action-card {
            background: #f9fafb;
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .quick-action-card:hover {
            background: var(--teal-600);
            color: white;
            border-color: var(--teal-600);
        }
        .quick-action-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="d-flex align-items-center">
                <div class="bg-teal-600 text-white p-3 rounded-circle me-3">
                    <i class="fas fa-eye fa-lg"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold">Eyecore</h5>
                    <small class="text-muted">Supplier Portal</small>
                </div>
            </div>
        </div>
        
        <div class="sidebar-welcome px-3 py-2 bg-light">
            <small class="text-muted">Welcome back,</small>
            <div class="fw-bold"><?= htmlspecialchars($supplier['contact_person'] ?? $_SESSION['contact_person']) ?></div>
            <small class="text-muted"><?= htmlspecialchars($supplier['supplier_name'] ?? $_SESSION['supplier_name']) ?></small>
        </div>
        
        <nav class="nav flex-column mt-3">
            <!-- MAIN DASHBOARD -->
            <div class="nav-section">Main</div>
            <a class="nav-link active" href="#" onclick="showModule('dashboard')">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            
            <!-- PURCHASE ORDERS MODULE -->
            <div class="nav-section mt-3">Purchase Orders</div>
            <a class="nav-link" href="#" onclick="showModule('po_pending')">
                <i class="fas fa-clock"></i> Pending Approval
                <span class="badge bg-danger rounded-pill float-end" id="pendingCount">0</span>
            </a>
            <a class="nav-link" href="#" onclick="showModule('po_approved')">
                <i class="fas fa-check-circle"></i> Approved Orders
            </a>
            <a class="nav-link" href="#" onclick="showModule('po_toship')">
                <i class="fas fa-box"></i> To Ship
            </a>
            <a class="nav-link" href="#" onclick="showModule('po_shipped')">
                <i class="fas fa-truck"></i> Shipped
            </a>
            <a class="nav-link" href="#" onclick="showModule('po_delivered')">
                <i class="fas fa-check-double"></i> Delivered
            </a>
            <a class="nav-link" href="#" onclick="showModule('po_rejected')">
                <i class="fas fa-times-circle"></i> Rejected
            </a>

            <!-- RETURNS/REFUNDS MODULE - IISA LANG -->
            <div class="nav-section mt-3">Returns & Refunds</div>
            <a class="nav-link" href="#" onclick="showModule('returns_management')">
                <i class="fas fa-undo-alt"></i> Return Requests
                <span class="badge bg-danger rounded-pill float-end" id="totalReturnsCount">0</span>
            </a>
            
            <!-- PRODUCTS MODULE -->
            <div class="nav-section mt-3">Products</div>
            <a class="nav-link" href="#" onclick="showModule('products_list')">
                <i class="fas fa-boxes"></i> My Products
            </a>
            <a class="nav-link" href="#" onclick="showModule('products_add')">
                <i class="fas fa-plus-circle"></i> Add Product
            </a>
            <a class="nav-link" href="#" onclick="showModule('products_price')">
                <i class="fas fa-tag"></i> Price List
            </a>
            <a class="nav-link" href="#" onclick="showModule('products_stock')">
                <i class="fas fa-warehouse"></i> Stock Levels
            </a>
            
            <!-- DELIVERIES MODULE -->
            <div class="nav-section mt-3">Deliveries</div>
            <a class="nav-link" href="#" onclick="showModule('delivery_history')">
                <i class="fas fa-history"></i> Delivery History
            </a>

            <!-- INVOICES MODULE - ISA LANG PAGE NA MAY TABS -->
            <div class="nav-section mt-3">Invoices</div>
            <a class="nav-link" href="#" onclick="showModule('invoices')">
                <i class="fas fa-file-invoice"></i> Invoices
                <span class="badge bg-danger rounded-pill float-end" id="totalUnpaidInvoices">0</span>
            </a>
            
            <!-- SETTINGS -->
            <div class="nav-section mt-3">Account</div>
            <a class="nav-link" href="#" onclick="showModule('settings_profile')">
                <i class="fas fa-user-cog"></i> Profile
            </a>
            <a class="nav-link" href="#" onclick="showModule('settings_contacts')">
                <i class="fas fa-address-book"></i> Contacts
            </a>
            <hr>
            <a class="nav-link text-danger" href="logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="content-header">
            <h1 class="page-title" id="currentPageTitle">Dashboard</h1>
            <div class="text-muted">
                <i class="fas fa-calendar me-2"></i><?= date('F d, Y') ?>
            </div>
        </div>

        <!-- DASHBOARD MODULE -->
        <div id="module-dashboard" class="module-content active">
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Pending Approval</div>
                                <div class="h3 mb-0" id="statPending">0</div>
                            </div>
                            <div class="text-warning">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Approved Orders</div>
                                <div class="h3 mb-0" id="statApproved">0</div>
                            </div>
                            <div class="text-success">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">To Ship</div>
                                <div class="h3 mb-0" id="statToShip">0</div>
                            </div>
                            <div class="text-info">
                                <i class="fas fa-box fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Month Total</div>
                                <div class="h5 mb-0" id="statMonthTotal">₱0.00</div>
                            </div>
                            <div class="text-primary">
                                <i class="fas fa-peso-sign fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <h5>Quick Actions</h5>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('po_pending')">
                        <i class="fas fa-clock"></i>
                        <h6>Review Pending Orders</h6>
                        <small class="text-muted"><?= $pendingCount ?? 0 ?> waiting</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('products_add')">
                        <i class="fas fa-plus-circle"></i>
                        <h6>Add New Product</h6>
                        <small class="text-muted">Update catalog</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('delivery_history')">
                        <i class="fas fa-history"></i>
                        <h6>Delivery History</h6>
                        <small class="text-muted">Completed deliveries</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('invoices')">
                        <i class="fas fa-file-invoice"></i>
                        <h6>View Invoices</h6>
                        <small class="text-muted">Payment status</small>
                    </div>
                </div>
            </div>

            <!-- Recent Pending Orders -->
            <div class="dashboard-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Recent Pending Orders</h5>
                    <button class="btn btn-sm btn-teal" onclick="showModule('po_pending')">View All</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="recentPendingTable">
                            <tr><td colspan="5" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Company Info Summary -->
            <div class="row">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-3"><i class="fas fa-building me-2"></i>Company Information</h5>
                        <div class="info-row">
                            <span class="text-muted">Company Name:</span>
                            <span class="fw-bold"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Contact Person:</span>
                            <span><?= htmlspecialchars($supplier['contact_person'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Email:</span>
                            <span><?= htmlspecialchars($supplier['email'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Phone:</span>
                            <span><?= htmlspecialchars($supplier['phone'] ?: 'N/A') ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Performance</h5>
                        <div class="info-row">
                            <span class="text-muted">On-Time Delivery:</span>
                            <span class="fw-bold text-success">98%</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Order Accuracy:</span>
                            <span class="fw-bold text-success">100%</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Response Time:</span>
                            <span class="fw-bold">Within 24 hrs</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Rating:</span>
                            <span>
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?= $i <= ($supplier['rating'] ?? 3) ? 'text-warning' : 'text-muted' ?>"></i>
                                <?php endfor; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PURCHASE ORDERS MODULES -->
        <div id="module-po_pending" class="module-content">
            <h4 class="mb-3">Pending Approval</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover" id="pendingTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date</th>
                                <th>Department</th>
                                <th>Purpose</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTableBody">
                            <tr><td colspan="7" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_approved" class="module-content">
            <h4 class="mb-3">Approved Orders</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover" id="approvedTable">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>PR Number</th>
                            <th>Date</th>
                            <th>Department</th>
                            <th>Total</th>
                            <th>Est. Delivery</th>
                            <th>Status</th>
                            <th>Action</th>  <!-- ✅ Added Action column -->
                        </tr>
                    </thead>
                        <tbody id="approvedTableBody">
                            <tr><td colspan="7" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<div id="module-po_toship" class="module-content">
    <h4 class="mb-3">To Ship</h4>
    <div class="dashboard-card">
        <p class="text-muted">Orders ready for shipping</p>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="toShipTableBody">
                    <tr><td colspan="4" class="text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="module-po_shipped" class="module-content">
    <h4 class="mb-3">Shipped Orders</h4>
    <div class="dashboard-card">
        <p class="text-muted">Orders on the way</p>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>PR Number</th>
                        <th>Shipped Date</th>
                        <th>Department</th>
                        <th>Total</th>
                        <th>Tracking</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="shippedTableBody">
                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delivered Module -->
<div id="module-po_delivered" class="module-content">
    <h4 class="mb-3">Delivered Orders</h4>
    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>PR Number</th>
                        <th>Date Ordered</th>
                        <th>Department</th>
                        <th>Total</th>
                        <th>Delivered Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="deliveredTableBody">
                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Rejected Module -->
<div id="module-po_rejected" class="module-content">
    <h4 class="mb-3">Rejected Orders</h4>
    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>PR Number</th>
                        <th>Date Ordered</th>
                        <th>Department</th>
                        <th>Total</th>
                        <th>Rejected Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="rejectedTableBody">
                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- DELIVERY HISTORY MODULE (connected to Delivered POs) -->
<!-- ============================================ -->
<div id="module-delivery_history" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-history me-2"></i>Delivery History</h4>
    </div>

    <!-- Filters -->
    <div class="dashboard-card mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">From Date</label>
                <input type="date" id="dhFromDate" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">To Date</label>
                <input type="date" id="dhToDate" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" id="deliveryHistorySearch" class="form-control form-control-sm" placeholder="Search PO #, Department...">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button class="btn btn-sm btn-primary flex-fill" onclick="applyDeliveryFilter()"><i class="fas fa-search"></i> Filter</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearDeliveryFilter()" title="Clear filters"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="dashboard-card text-center">
                <div class="text-muted small mb-1">Total Delivered</div>
                <h3 class="mb-0 text-success" id="dhTotalDelivered">0</h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card text-center">
                <div class="text-muted small mb-1">Total Value</div>
                <h3 class="mb-0 text-primary" id="dhTotalValue">₱0.00</h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dashboard-card text-center">
                <div class="text-muted small mb-1">This Month</div>
                <h3 class="mb-0 text-info" id="dhThisMonth">0</h3>
            </div>
        </div>
    </div>

    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>PO Number</th>
                        <th>PR Number</th>
                        <th>Date Ordered</th>
                        <th>Department</th>
                        <th>Total Amount</th>
                        <th>Delivered Date</th>
                        <th>Tracking</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="deliveryHistoryTableBody">
                    <tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-teal"></div> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- SETTINGS: COMPANY PROFILE -->
<!-- ============================================ -->
<div id="module-settings_profile" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-building me-2"></i>Company Profile</h4>
        <button class="btn btn-teal" id="editProfileBtn" onclick="toggleProfileEdit()">
            <i class="fas fa-edit me-1"></i> Edit Profile
        </button>
    </div>

    <!-- View Mode -->
    <div id="profileViewMode">
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="dashboard-card text-center">
                    <div class="mb-3">
                        <div class="bg-light rounded-circle mx-auto d-flex align-items-center justify-content-center" style="width:100px;height:100px;">
                            <i class="fas fa-building fa-3x text-muted"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold" id="pv_name"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></h5>
                    <p class="text-muted mb-1" id="pv_code"><?= htmlspecialchars($supplier['supplier_code'] ?? '') ?></p>
                    <span class="badge bg-success" id="pv_status"><?= htmlspecialchars($supplier['status'] ?? 'Active') ?></span>
                    <hr>
                    <div class="text-start">
                        <div class="mb-2"><i class="fas fa-star text-warning me-2"></i>
                            <?php for($i=1;$i<=5;$i++): ?>
                                <i class="fas fa-star <?= $i<=($supplier['rating']??3)?'text-warning':'text-muted' ?>"></i>
                            <?php endfor; ?> <small class="text-muted">(<?= $supplier['rating']??3 ?>/5)</small>
                        </div>
                        <div class="mb-2"><i class="fas fa-tag text-primary me-2"></i><?= htmlspecialchars($supplier['category'] ?? 'General') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-8 mb-4">
                <div class="dashboard-card">
                    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row"><span class="text-muted">Company Name</span><span class="fw-bold" id="pv_supplier_name"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></span></div>
                            <div class="info-row"><span class="text-muted">Contact Person</span><span id="pv_contact_person"><?= htmlspecialchars($supplier['contact_person'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Email</span><span id="pv_email"><?= htmlspecialchars($supplier['email'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Phone</span><span id="pv_phone"><?= htmlspecialchars($supplier['phone'] ?? 'N/A') ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row"><span class="text-muted">Mobile</span><span id="pv_mobile"><?= htmlspecialchars($supplier['mobile'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Website</span><span id="pv_website"><?= htmlspecialchars($supplier['website'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Tax ID</span><span id="pv_tax_id"><?= htmlspecialchars($supplier['tax_id'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Payment Terms</span><span id="pv_payment_terms"><?= htmlspecialchars($supplier['payment_terms'] ?? 'Net 30') ?></span></div>
                        </div>
                    </div>

                    <h6 class="text-primary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row"><span class="text-muted">Address</span><span id="pv_address"><?= htmlspecialchars($supplier['address'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">City</span><span id="pv_city"><?= htmlspecialchars($supplier['city'] ?? 'N/A') ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row"><span class="text-muted">State / Province</span><span id="pv_state"><?= htmlspecialchars($supplier['state'] ?? 'N/A') ?></span></div>
                            <div class="info-row"><span class="text-muted">Country</span><span id="pv_country"><?= htmlspecialchars($supplier['country'] ?? 'Philippines') ?></span></div>
                        </div>
                    </div>

                    <?php if (!empty($supplier['notes'])): ?>
                    <h6 class="text-primary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                    <p id="pv_notes" class="text-muted"><?= htmlspecialchars($supplier['notes']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Performance Block -->
        <div class="dashboard-card">
            <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-chart-bar me-2"></i>Performance Summary</h6>
            <div class="row text-center">
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded">
                        <div class="text-success fw-bold fs-4">98%</div>
                        <small class="text-muted">On-Time Delivery</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded">
                        <div class="text-success fw-bold fs-4">100%</div>
                        <small class="text-muted">Order Accuracy</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded">
                        <div class="text-primary fw-bold fs-4">&lt; 24h</div>
                        <small class="text-muted">Response Time</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 bg-light rounded">
                        <div class="text-warning fw-bold fs-4">
                            <?php for($i=1;$i<=5;$i++) echo $i<=($supplier['rating']??3)?'★':'☆'; ?>
                        </div>
                        <small class="text-muted">Supplier Rating</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Mode -->
    <div id="profileEditMode" style="display:none;">
        <div class="dashboard-card">
            <form id="profileEditForm">
                <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" name="supplier_name" value="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($supplier['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Mobile</label>
                        <input type="text" class="form-control" name="mobile" value="<?= htmlspecialchars($supplier['mobile'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Website</label>
                        <input type="text" class="form-control" name="website" value="<?= htmlspecialchars($supplier['website'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tax ID / TIN</label>
                        <input type="text" class="form-control" name="tax_id" value="<?= htmlspecialchars($supplier['tax_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Payment Terms</label>
                        <select class="form-select" name="payment_terms">
                            <?php foreach(['Net 15','Net 30','Net 45','Net 60','COD','Upon Delivery'] as $term): ?>
                            <option value="<?= $term ?>" <?= ($supplier['payment_terms']??'Net 30')===$term?'selected':'' ?>><?= $term ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <h6 class="text-primary border-bottom pb-2 mb-3 mt-2"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Street Address</label>
                        <input type="text" class="form-control" name="address" value="<?= htmlspecialchars($supplier['address'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">City</label>
                        <input type="text" class="form-control" name="city" value="<?= htmlspecialchars($supplier['city'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">State / Province</label>
                        <input type="text" class="form-control" name="state" value="<?= htmlspecialchars($supplier['state'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="country" value="<?= htmlspecialchars($supplier['country'] ?? 'Philippines') ?>">
                    </div>
                </div>

                <h6 class="text-primary border-bottom pb-2 mb-3 mt-2"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                <div class="mb-3">
                    <textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($supplier['notes'] ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-secondary" onclick="toggleProfileEdit()">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-teal">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- SETTINGS: CONTACTS -->
<!-- ============================================ -->
<div id="module-settings_contacts" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-address-book me-2"></i>Contact Persons</h4>
        <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#addContactModal">
            <i class="fas fa-plus me-1"></i> Add Contact
        </button>
    </div>

    <!-- Contacts Grid -->
    <div id="contactsGrid">
        <?php if (!empty($contacts)): ?>
            <div class="row" id="contactsContainer">
            <?php foreach ($contacts as $contact): ?>
                <div class="col-md-4 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-teal-100 d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:48px;height:48px;background:#e6f4f3;">
                                    <i class="fas fa-user" style="color:var(--teal-600);"></i>
                                </div>
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($contact['contact_name'] ?? '') ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($contact['position'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <?php if (!empty($contact['is_primary'])): ?>
                                <span class="badge bg-success">Primary</span>
                            <?php endif; ?>
                        </div>
                        <hr class="my-2">
                        <div class="small">
                            <?php if (!empty($contact['email'])): ?>
                            <div class="mb-1"><i class="fas fa-envelope text-muted me-2"></i><?= htmlspecialchars($contact['email']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($contact['phone'])): ?>
                            <div class="mb-1"><i class="fas fa-phone text-muted me-2"></i><?= htmlspecialchars($contact['phone']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($contact['mobile'])): ?>
                            <div class="mb-1"><i class="fas fa-mobile-alt text-muted me-2"></i><?= htmlspecialchars($contact['mobile']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($contact['department'])): ?>
                            <div class="mb-1"><i class="fas fa-building text-muted me-2"></i><?= htmlspecialchars($contact['department']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editContact(<?= $contact['id'] ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteContact(<?= $contact['id'] ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="dashboard-card text-center py-5" id="contactsEmptyState">
                <i class="fas fa-address-book fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No contact persons yet</h5>
                <p class="text-muted">Add the people from your company who coordinate with Eyecore.</p>
                <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#addContactModal">
                    <i class="fas fa-plus me-1"></i> Add First Contact
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Contact Modal -->
<div class="modal fade" id="addContactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--teal-600);color:white;">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Contact Person</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addContactForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="contact_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position / Title</label>
                            <input type="text" class="form-control" name="position" placeholder="e.g. Sales Manager">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mobile</label>
                            <input type="text" class="form-control" name="mobile">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" placeholder="e.g. Sales, Logistics">
                        </div>
                        <div class="col-12 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_primary" id="isPrimary" value="1">
                                <label class="form-check-label" for="isPrimary">Set as Primary Contact</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal">
                        <i class="fas fa-save me-1"></i> Save Contact
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Contact Modal -->
<div class="modal fade" id="editContactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--teal-600);color:white;">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Contact Person</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editContactForm">
                <div class="modal-body">
                    <input type="hidden" name="contact_id" id="editContactId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="contact_name" id="editContactName" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Position / Title</label>
                            <input type="text" class="form-control" name="position" id="editContactPosition">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="editContactEmail">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="editContactPhone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Mobile</label>
                            <input type="text" class="form-control" name="mobile" id="editContactMobile">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" id="editContactDept">
                        </div>
                        <div class="col-12 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_primary" id="editIsPrimary" value="1">
                                <label class="form-check-label" for="editIsPrimary">Set as Primary Contact</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-teal">
                        <i class="fas fa-save me-1"></i> Update Contact
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- PRODUCTS MANAGEMENT MODULE - COMPREHENSIVE -->
<!-- ============================================ -->

<!-- Products List View -->
<div id="module-products_list" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-boxes me-2"></i>My Products</h4>
        <button class="btn btn-success" onclick="showModule('products_add')">
            <i class="fas fa-plus-circle"></i> Add New Product
        </button>
    </div>
    
    <!-- Filters -->
    <div class="row mb-3">
        <div class="col-md-4">
            <select id="product_category_filter" class="form-select" onchange="loadProducts()">
                <option value="all">All Categories</option>
                <option value="Frames">Frames</option>
                <option value="Lenses">Lenses</option>
                <option value="Contact Lenses">Contact Lenses</option>
                <option value="Accessories">Accessories</option>
                <option value="Others">Others</option>
            </select>
        </div>
        <div class="col-md-5">
            <div class="input-group">
                <input type="text" id="product_search" class="form-control" placeholder="Search by name, code, brand..." onkeyup="if(event.keyCode==13) loadProducts()">
                <button class="btn btn-primary" onclick="loadProducts()"><i class="fas fa-search"></i></button>
            </div>
        </div>
        <div class="col-md-3 text-end">
            <button class="btn btn-outline-secondary" onclick="exportProducts()">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <!-- Products Table -->
    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Cost Price</th>
                        <th>Selling Price</th>
                        <th>In Inventory</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="products_table_body">
                    <tr><td colspan="9" class="text-center">Loading products...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div id="products_pagination_info"></div>
            <nav>
                <ul class="pagination" id="products_pagination"></ul>
            </nav>
        </div>
    </div>
</div>

<!-- Add Product View -->
<div id="module-products_add" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-plus-circle me-2"></i>Add New Product</h4>
        <button class="btn btn-outline-secondary" onclick="showModule('products_list')">
            <i class="fas fa-arrow-left"></i> Back to List
        </button>
    </div>
    
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="dashboard-card">
                <form id="add_product_form" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Code <span class="text-danger">*</span></label>
                            <input type="text" name="product_code" id="product_code" class="form-control" required>
                            <small class="text-muted">Unique code from your system</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" id="product_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="product_category" class="form-select" required>
                                <option value="">Select Category</option>
                                <option value="Frames">Frames</option>
                                <option value="Lenses">Lenses</option>
                                <option value="Contact Lenses">Contact Lenses</option>
                                <option value="Accessories">Accessories</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" id="product_brand" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cost Price <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="cost_price" id="cost_price" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Wholesale Price</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="wholesale_price" id="wholesale_price" class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <div class="row">
    <div class="col-md-4 mb-3">
        <label class="form-label">Initial Stock</label>
        <input type="number" name="initial_stock" id="initial_stock" class="form-control" value="0" min="0">
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Low Stock Threshold</label>
        <input type="number" name="low_stock_threshold" id="low_stock_threshold" class="form-control" value="5" min="1">
    </div>
</div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit</label>
                            <select name="unit" id="product_unit" class="form-select">
                                <option value="pcs">Pieces (pcs)</option>
                                <option value="box">Box</option>
                                <option value="pair">Pair</option>
                                <option value="set">Set</option>
                                <option value="dozen">Dozen</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Min Order Qty</label>
                            <input type="number" name="min_order_qty" id="min_order_qty" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Lead Time (days)</label>
                            <input type="number" name="lead_time_days" id="lead_time_days" class="form-control" min="1">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="product_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <!-- PHOTO UPLOAD - OPTIONAL -->
                    <div class="mb-3">
                        <label class="form-label">Product Photo <span class="text-muted">(Optional)</span></label>
                        <input type="file" name="photo" id="product_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP only.</small>
                        <div id="photo_preview" class="mt-2" style="display: none;">
                            <img id="preview_image" src="#" alt="Preview" style="max-height: 150px; border-radius: 5px;">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="product_notes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <hr>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-secondary" onclick="showModule('products_list')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Price List View -->
<div id="module-products_price" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-tag me-2"></i>Price List</h4>
        <button class="btn btn-outline-primary" onclick="exportPriceList()">
            <i class="fas fa-file-excel"></i> Export
        </button>
    </div>
    
    <div class="row mb-3">
        <div class="col-md-6 offset-md-6">
            <div class="input-group">
                <input type="text" id="price_search" class="form-control" placeholder="Search products..." onkeyup="if(event.keyCode==13) loadPriceList()">
                <button class="btn btn-primary" onclick="loadPriceList()"><i class="fas fa-search"></i></button>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="table-light">
                    <tr>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th>Cost Price</th>
                        <th>Selling Price</th>
                        <th>Wholesale</th>
                        <th>Margin %</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="price_list_table_body">
                    <tr><td colspan="9" class="text-center">Loading price list...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Stock Levels View -->
<div id="module-products_stock" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-warehouse me-2"></i>Stock Levels</h4>
        <button class="btn btn-warning" onclick="showReorderSummary()">
            <i class="fas fa-exclamation-triangle"></i> Reorder Suggestions
        </button>
    </div>
    
    <!-- Stock Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="dashboard-card bg-success text-white">
                <div class="d-flex justify-content-between">
                    <div>
                        <small>In Stock</small>
                        <h3 id="summary_in_stock">0</h3>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-warning text-dark">
                <div class="d-flex justify-content-between">
                    <div>
                        <small>Low Stock</small>
                        <h3 id="summary_low_stock">0</h3>
                    </div>
                    <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-danger text-white">
                <div class="d-flex justify-content-between">
                    <div>
                        <small>Out of Stock</small>
                        <h3 id="summary_out_of_stock">0</h3>
                    </div>
                    <i class="fas fa-times-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-secondary text-white">
                <div class="d-flex justify-content-between">
                    <div>
                        <small>Not in Inventory</small>
                        <h3 id="summary_not_in_inventory">0</h3>
                    </div>
                    <i class="fas fa-question-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="input-group">
                <input type="text" id="stock_search" class="form-control" placeholder="Search products..." onkeyup="if(event.keyCode==13) loadStockLevels()">
                <button class="btn btn-primary" onclick="loadStockLevels()"><i class="fas fa-search"></i></button>
            </div>
        </div>
    </div>
    
    <!-- Stock Table -->
    <div class="dashboard-card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Code</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Suggested Order</th>
                        <th>Est. Cost</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="stock_levels_table_body">
                    <tr><td colspan="8" class="text-center">Loading stock levels...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Product Details Modal -->
<div class="modal fade" id="productDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-box me-2"></i>Product Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="product_details_body">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="edit_product_form" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Code</label>
                            <input type="text" name="product_code" id="edit_product_code" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Name</label>
                            <input type="text" name="product_name" id="edit_product_name" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category</label>
                            <select name="category" id="edit_product_category" class="form-select" required>
                                <option value="Frames">Frames</option>
                                <option value="Lenses">Lenses</option>
                                <option value="Contact Lenses">Contact Lenses</option>
                                <option value="Accessories">Accessories</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" id="edit_product_brand" class="form-control">
                        </div>
                    </div>

                    <div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Current Stock</label>
        <input type="number" name="stock" id="edit_stock" class="form-control" min="0" value="0">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Low Stock Threshold</label>
        <input type="number" name="low_stock_threshold" id="edit_low_stock_threshold" class="form-control" min="1" value="5">
    </div>
</div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Cost Price</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="cost_price" id="edit_cost_price" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Selling Price</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="selling_price" id="edit_selling_price" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Wholesale</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="wholesale_price" id="edit_wholesale_price" class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_product_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Product Photo <span class="text-muted">(Leave empty to keep current)</span></label>
                        <input type="file" name="photo" id="edit_product_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_photo" id="remove_photo" value="1">
                            <label class="form-check-label">Remove current photo</label>
                        </div>
                        <div id="current_photo_display" class="mt-2"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateProduct()">Update Product</button>
            </div>
        </div>
    </div>
</div>

<!-- RETURNS MANAGEMENT MODULE - WITH MANUAL TABS (FIXED) -->
<div id="module-returns_management" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-undo-alt me-2"></i>Return Requests Management</h4>
        <div>
            <span class="badge bg-warning me-2" id="returnsPendingBadge">Pending: 0</span>
            <span class="badge bg-info me-2" id="returnsApprovedBadge">Approved: 0</span>
            <span class="badge bg-success me-2" id="returnsCompletedBadge">Completed: 0</span>
            <span class="badge bg-danger" id="returnsRejectedBadge">Rejected: 0</span>
        </div>
    </div>
    
    <!-- Tabs Navigation - MANUAL (no data-bs-toggle) -->
    <ul class="nav nav-tabs mb-3" id="returnsTabs">
        <li class="nav-item">
            <a class="nav-link active" href="#" onclick="switchReturnTab('pending'); return false;">
                <i class="fas fa-clock me-1"></i>Pending
                <span class="badge bg-danger ms-1" id="pendingCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" onclick="switchReturnTab('approved'); return false;">
                <i class="fas fa-check-circle me-1"></i>Approved
                <span class="badge bg-info ms-1" id="approvedCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" onclick="switchReturnTab('completed'); return false;">
                <i class="fas fa-check-double me-1"></i>Completed
                <span class="badge bg-success ms-1" id="completedCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" onclick="switchReturnTab('rejected'); return false;">
                <i class="fas fa-times-circle me-1"></i>Rejected
                <span class="badge bg-danger ms-1" id="rejectedCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" onclick="switchReturnTab('all'); return false;">
                <i class="fas fa-list me-1"></i>All Returns
            </a>
        </li>
    </ul>
    
    <!-- Tab Content - all visible but controlled by CSS -->
    <div id="pending" class="return-tab-pane active">
        <div class="dashboard-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Return #</th>
                            <th>PO #</th>
                            <th>Clinic</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Reason</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pendingReturnsTable">
                        <tr><td colspan="8" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="approved" class="return-tab-pane" style="display:none;">
        <div class="dashboard-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Return #</th>
                            <th>PO #</th>
                            <th>Clinic</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Reason</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="approvedReturnsTable">
                        <tr><td colspan="9" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="completed" class="return-tab-pane" style="display:none;">
        <div class="dashboard-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Return #</th>
                            <th>PO #</th>
                            <th>Clinic</th>
                            <th>Completed Date</th>
                            <th>Items</th>
                            <th>Refund Method</th>
                            <th>Amount</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody id="completedReturnsTable">
                        <tr><td colspan="8" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="rejected" class="return-tab-pane" style="display:none;">
        <div class="dashboard-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Return #</th>
                            <th>PO #</th>
                            <th>Clinic</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Reason</th>
                            <th>Amount</th>
                            <th>Rejection Reason</th>
                        </tr>
                    </thead>
                    <tbody id="rejectedReturnsTable">
                        <td><td colspan="8" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="all" class="return-tab-pane" style="display:none;">
        <div class="dashboard-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Return #</th>
                            <th>PO #</th>
                            <th>Clinic</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Reason</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="allReturnsTable">
                        <tr><td colspan="9" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<!-- Return Details Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-undo-alt me-2"></i>Return Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                Loading...
            </div>
            <div class="modal-footer" id="returnDetailsFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Return Modal — with Pickup / Drop-off -->
<div class="modal fade" id="approveReturnModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header" style="background:#0d9488;color:white;">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Approve Return — Set Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approve_return_id">

                <!-- Summary strip -->
                <div class="alert alert-light py-2 mb-3 d-flex justify-content-between small">
                    <span id="approve_return_summary"></span>
                </div>

                <!-- Method toggle -->
                <p class="fw-bold mb-2 small">Return method <span class="text-danger">*</span></p>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="border rounded p-3 h-100" id="card_pickup"
                             onclick="selectReturnMethod('pickup')"
                             style="cursor:pointer;border-width:2px!important;border-color:#0d9488!important;background:#e1f5ee;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <input type="radio" name="return_method" id="rm_pickup" value="Pickup" checked
                                       style="accent-color:#0d9488;">
                                <label for="rm_pickup" class="fw-bold mb-0 small" style="cursor:pointer;color:#085041;">
                                    <i class="fas fa-truck me-1"></i>Pickup
                                </label>
                            </div>
                            <div class="text-muted" style="font-size:11px;">
                                Supplier will fetch items from the clinic
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="border rounded p-3 h-100" id="card_dropoff"
                             onclick="selectReturnMethod('dropoff')"
                             style="cursor:pointer;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <input type="radio" name="return_method" id="rm_dropoff" value="Drop-off"
                                       style="accent-color:#0d9488;">
                                <label for="rm_dropoff" class="fw-bold mb-0 small" style="cursor:pointer;">
                                    <i class="fas fa-store me-1"></i>Drop-off
                                </label>
                            </div>
                            <div class="text-muted" style="font-size:11px;">
                                Clinic will send items to supplier
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PICKUP FIELDS -->
                <div id="fields_pickup">
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">
                                Pickup date <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control form-control-sm"
                                   id="pickup_date" name="pickup_date"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                   value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">Time window</label>
                            <select class="form-select form-select-sm" id="pickup_time" name="pickup_time">
                                <option value="9AM-12NN">9AM – 12NN</option>
                                <option value="1PM-5PM">1PM – 5PM</option>
                                <option value="Morning (flexible)">Morning (flexible)</option>
                                <option value="Afternoon (flexible)">Afternoon (flexible)</option>
                                <option value="Any time">Any time</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Pickup address</label>
                        <input type="text" class="form-control form-control-sm"
                               id="pickup_address" name="pickup_address"
                               placeholder="Auto-filled from clinic address">
                        <div class="form-text" style="font-size:11px;">
                            Clinic address will be used if left blank.
                        </div>
                    </div>
                    <div>
                        <label class="form-label small mb-1">Notes (optional)</label>
                        <textarea class="form-control form-control-sm" id="pickup_notes"
                                  name="pickup_notes" rows="2"
                                  placeholder="e.g. Call before arriving, ask for Juan"></textarea>
                    </div>
                </div>

                <!-- DROP-OFF FIELDS -->
                <div id="fields_dropoff" style="display:none;">
                    <div class="mb-2">
                        <label class="form-label small mb-1">
                            Return address <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-sm"
                               id="dropoff_address" name="dropoff_address"
                               placeholder="e.g. 456 Supplier Ave, Manila"
                               value="<?= htmlspecialchars($supplier['address'] ?? '') ?>">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-1">
                                Contact person <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="dropoff_contact" name="dropoff_contact"
                                   placeholder="Name"
                                   value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1">
                                Contact number <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="dropoff_phone" name="dropoff_phone"
                                   placeholder="09XX XXX XXXX"
                                   value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1">Available hours</label>
                        <input type="text" class="form-control form-control-sm"
                               id="dropoff_hours" name="dropoff_hours"
                               placeholder="e.g. Mon–Fri, 9AM–5PM">
                    </div>
                    <div>
                        <label class="form-label small mb-1">Instructions (optional)</label>
                        <textarea class="form-control form-control-sm" id="dropoff_notes"
                                  name="dropoff_notes" rows="2"
                                  placeholder="e.g. Leave at reception, mention RET number"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm text-white"
                        style="background:#0d9488;"
                        onclick="submitApproveReturn()">
                    <i class="fas fa-check me-1"></i>Confirm return details
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Return Modal (simple, separate) -->
<div class="modal fade" id="rejectReturnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Reject Return Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="reject_return_id">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Ang clinic ay maabisuhan ng rejection at ng reason mo.
                </div>
                <div class="mb-3">
                    <label class="form-label">
                        Reason for rejection <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="reject_reason" rows="4"
                              placeholder="e.g. Items were already used, return window expired, not covered by warranty..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger"
                        onclick="submitRejectReturn()">
                    <i class="fas fa-times me-1"></i>Reject Return
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ============================================ -->
<!-- INVOICES MODULE - SINGLE PAGE WITH TABS -->
<!-- ============================================ -->
<div id="module-invoices" class="module-content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-file-invoice me-2"></i>Invoices & Payments</h4>
        <div>
            <span class="badge bg-danger me-2" id="unpaidBadge">Unpaid: 0</span>
            <span class="badge bg-success me-2" id="paidBadge">Paid: 0</span>
            <span class="badge bg-info" id="overdueBadge">Overdue: 0</span>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="dashboard-card bg-danger text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small>Total Unpaid</small>
                        <h3 class="mb-0" id="totalUnpaidAmount">₱0.00</h3>
                    </div>
                    <i class="fas fa-exclamation-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-success text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small>Total Paid</small>
                        <h3 class="mb-0" id="totalPaidAmount">₱0.00</h3>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-warning text-dark">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small>This Month</small>
                        <h3 class="mb-0" id="monthTotal">₱0.00</h3>
                    </div>
                    <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card bg-info text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small>On-Time Rate</small>
                        <h3 class="mb-0" id="onTimeRate">0%</h3>
                    </div>
                    <i class="fas fa-chart-line fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-3" id="invoiceTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="unpaid-tab" onclick="switchInvoiceTab('unpaid')" type="button" role="tab">
                <i class="fas fa-exclamation-circle me-1"></i>Unpaid
                <span class="badge bg-danger ms-1" id="unpaidCount">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="paid-tab" onclick="switchInvoiceTab('paid')" type="button" role="tab">
                <i class="fas fa-check-circle me-1"></i>Paid
                <span class="badge bg-success ms-1" id="paidCount">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" onclick="switchInvoiceTab('history')" type="button" role="tab">
                <i class="fas fa-history me-1"></i>Payment History
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div id="invoiceTabContent">
        <!-- UNPAID TAB -->
        <div style="display:block;" id="unpaid" role="tabpanel">
            <div class="dashboard-card">
                <!-- Filters -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <select class="form-select" id="unpaidFilter" onchange="loadUnpaidInvoices()">
                            <option value="all">All Unpaid</option>
                            <option value="Pending">Pending</option>
                            <option value="Approved">Approved</option>
                            <option value="Ready to Pay">Ready to Pay</option>
                            <option value="Overdue">Overdue</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" id="unpaidSearch" placeholder="Search by PO # or Description..." onkeyup="if(event.keyCode==13) loadUnpaidInvoices()">
                            <button class="btn btn-primary" onclick="loadUnpaidInvoices()"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-3 text-end">
                        <button class="btn btn-outline-secondary" onclick="exportInvoices('unpaid')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>PO #</th>
                                <th>Description</th>
                                <th>Date</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="unpaidInvoicesTable">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div id="unpaidPaginationInfo"></div>
                    <nav>
                        <ul class="pagination" id="unpaidPagination"></ul>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- PAID TAB -->
        <div style="display:none;" id="paid" role="tabpanel">
            <div class="dashboard-card">
                <!-- Filters -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <select class="form-select" id="paidFilter" onchange="loadPaidInvoices()">
                            <option value="all">All Paid</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cash">Cash</option>
                            <option value="Check">Check</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" id="paidSearch" placeholder="Search by PO # or Description..." onkeyup="if(event.keyCode==13) loadPaidInvoices()">
                            <button class="btn btn-primary" onclick="loadPaidInvoices()"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-3 text-end">
                        <button class="btn btn-outline-secondary" onclick="exportInvoices('paid')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>PO #</th>
                                <th>Description</th>
                                <th>Paid Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="paidInvoicesTable">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div id="paidPaginationInfo"></div>
                    <nav>
                        <ul class="pagination" id="paidPagination"></ul>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- PAYMENT HISTORY TAB -->
        <div style="display:none;" id="history" role="tabpanel">
            <div class="dashboard-card">
                <!-- Date Range Filter -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <input type="date" class="form-control" id="historyFrom" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="date" class="form-control" id="historyTo" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="text" class="form-control" id="historySearch" placeholder="Search..." onkeyup="if(event.keyCode==13) loadPaymentHistory()">
                            <button class="btn btn-primary" onclick="loadPaymentHistory()"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-2 text-end">
                        <button class="btn btn-outline-secondary" onclick="exportPaymentHistory()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice #</th>
                                <th>PO #</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="paymentHistoryTable">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="alert alert-info mb-0">
                            <strong>Total for selected period:</strong> <span id="historyTotal">₱0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Details Modal -->
<div class="modal fade" id="invoiceDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Invoice Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="invoiceDetailsContent">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
            <div class="modal-footer" id="invoiceDetailsFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

        <!-- Add more modules as needed... -->
    </div>

<!-- PO Details Modal - FIXED VERSION -->
<div class="modal fade" id="poDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #008080;">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Purchase Order Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="poDetailsContent">
                <div class="text-center py-3">
                    <div class="spinner-border" style="color: #008080;"></div>
                </div>
            </div>
            <div class="modal-footer" id="poDetailsFooter">
                <button type="button" class="btn text-white" style="background-color: #008080;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

    <!-- Approve PO Modal -->
    <div class="modal fade" id="approvePOModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="approvePOForm">
                    <div class="modal-body">
                        <input type="hidden" name="po_id" id="approve_po_id">
                        <input type="hidden" name="pr_id" id="approve_pr_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Estimated Delivery Date</label>
                            <input type="date" class="form-control" name="estimated_delivery" 
                                   value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject PO Modal -->
    <div class="modal fade" id="rejectPOModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="rejectPOForm">
                    <div class="modal-body">
                        <input type="hidden" name="po_id" id="reject_po_id">
                        <input type="hidden" name="pr_id" id="reject_pr_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection</label>
                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


<!-- Ship Order Modal -->
<div class="modal fade" id="shipPOModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-truck me-2"></i>Confirm Shipment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="shipPOForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="po_id" id="ship_po_id">
                    <input type="hidden" name="pr_id" id="ship_pr_id">

                    <!-- Auto-generated ref number notice -->
                    <div class="alert alert-info py-2 mb-3">
                        <i class="fas fa-hashtag me-1"></i>
                        A shipment reference number will be auto-generated upon confirmation.
                    </div>

                    <!-- Photo Upload -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-camera me-1"></i>
                            Package Photo Proof <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" name="shipment_photos[]"
                               id="shipment_photos" accept="image/jpeg,image/png,image/webp"
                               multiple required>
                        <small class="text-muted">Upload 1–5 photos of the packed order. JPG/PNG/WEBP, max 5MB each.</small>
                        <div id="shipPhotoPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>

                    <!-- Shipping Fee — REQUIRED -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-peso-sign me-1"></i>
                            Shipping Fee <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control" name="shipping_fee"
                                   id="shipping_fee" step="0.01" min="1" required
                                   placeholder="e.g. 150.00">
                        </div>
                        <small class="text-muted">Actual delivery cost charged to buyer.</small>
                    </div>

                    <!-- Remarks -->
                    <div class="mb-3">
                        <label class="form-label">
                            Remarks <span class="text-muted">(Optional)</span>
                        </label>
                        <textarea class="form-control" name="remarks" rows="2"
                                  placeholder="e.g. Fragile items packed with bubble wrap, expected 1-2 days delivery"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i> Confirm Shipment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentProductPage = 1;
let supplierId = <?= $_SESSION['supplier_id'] ?>;
// Module navigation
function showModule(moduleId) {
    // Hide all modules
    $('.module-content').removeClass('active');
    
    // Show selected module
    $(`#module-${moduleId}`).addClass('active');
    
    // Update active state in sidebar
    $('.nav-link').removeClass('active');
    $(event.target).closest('.nav-link').addClass('active');
    
    // Update page title
    const titles = {
        'dashboard': 'Dashboard',
        'po_pending': 'Pending Approval',
        'po_approved': 'Approved Orders',
        'po_toship': 'To Ship',
        'po_shipped': 'Shipped',
        'po_delivered': 'Delivered',
        'po_rejected': 'Rejected Orders',
        'products_list': 'My Products',
        'products_add': 'Add Product',
        'products_price': 'Price List',
        'products_stock': 'Stock Levels',
        'delivery_history': 'Delivery History',
        'invoices': 'Invoices & Payments',
        'settings_profile': 'Company Profile',
        'settings_contacts': 'Contact Persons'
    };
    $('#currentPageTitle').text(titles[moduleId] || 'Dashboard');
    
    // Load data based on module
    switch(moduleId) {
        case 'dashboard':
            loadDashboardStats();
            loadRecentPending();
            break;
        case 'po_pending':
            loadPendingPOs();
            break;
        case 'po_approved':
            loadApprovedPOs();
            break;
        // Sa switch statement, i-add ang case for 'po_toship'
        case 'po_toship':
            loadToShipOrders();
            break;
        case 'po_shipped':
        loadShippedOrders();
        break;
        // Sa showModule function, i-add ang cases
        case 'po_delivered':
            loadDeliveredOrders();
            break;
        case 'po_rejected':
            loadRejectedOrders();
            break;
        case 'delivery_history':
            loadDeliveryHistory();
            break;
        case 'settings_profile':
            // profile is pre-rendered from PHP, nothing extra to load
            break;
        case 'settings_contacts':
            loadContacts();
            break;
                // Add more cases as needed
    }
}

// ✅ FIXED: Load dashboard stats
function loadDashboardStats() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { action: 'dashboard_stats' },
        success: function(response) {
            if (response.success) {
                $('#statPending').text(response.data.pending_approval || 0);
                $('#statApproved').text(response.data.approved || 0);
                $('#statToShip').text(response.data.to_ship || 0);
                $('#statMonthTotal').text('₱' + parseFloat(response.data.month_total || 0).toFixed(2));
                $('#pendingCount').text(response.data.pending_approval || 0);
            } else {
                console.error('Error loading stats:', response.error);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
        }
    });
}

// ✅ FIXED: Load recent pending for dashboard
function loadRecentPending() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'Pending Supplier Approval'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.slice(0, 5).forEach(po => {
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="openApproveModal(${po.id}, ${po.pr_id})">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="openRejectModal(${po.id}, ${po.pr_id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No pending orders</td></tr>';
                }
                $('#recentPendingTable').html(html);
            }
        }
    });
}

// ✅ FIXED: Load all pending POs
function loadPendingPOs() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'Pending Supplier Approval'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                <td>${po.department}</td>
                                <td>${po.purpose || '-'}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="openApproveModal(${po.id}, ${po.pr_id})">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="openRejectModal(${po.id}, ${po.pr_id})">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="7" class="text-center">No pending orders</td></tr>';
                }
                $('#pendingTableBody').html(html);
            }
        }
    });
}

/// ✅ FIXED: Load approved POs (PO Approved status)
function loadApprovedPOs() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'PO Approved'  // ✅ Changed from 'Supplier Approved'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        // Format estimated delivery if exists
                        const estDelivery = po.estimated_delivery 
                            ? new Date(po.estimated_delivery).toLocaleDateString() 
                            : 'Not set';
                        
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                <td>${po.department}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>${estDelivery}</td>
                                <td>
                                    <span class="badge bg-success">PO Approved</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="moveToShip(${po.id}, ${po.pr_id})" title="Move to Ship">
                                        <i class="fas fa-box"></i> To Ship
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center">No approved orders</td></tr>';
                }
                $('#approvedTableBody').html(html);
            }
        }
    });
}

// Move to Ship function
function moveToShip(poId, prId) {
    Swal.fire({
        title: 'Move to Ship?',
        text: 'Are you ready to prepare this order for shipping?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, move to ship',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/supplier_po.php',
                method: 'POST',
                data: {
                    action: 'move_to_ship',
                    po_id: poId,
                    pr_id: prId
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success').then(() => {
                            loadApprovedPOs(); // Reload the list
                            loadDashboardStats();
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                }
            });
        }
    });
}

// Load To Ship orders (status = 'To Ship')
function loadToShipOrders() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'To Ship'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        // Kunin ang item count (estimated)
                        const itemCount = po.item_count || '?';
                        
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong><br>
                                    <small class="text-muted">PR: ${po.pr_number}</small>
                                </td>
                                <td>
                                    <strong>${po.department || 'N/A'}</strong><br>
                                    <small class="text-muted">${po.requested_by || 'Unknown'}</small>
                                </td>
                                <td class="text-center">${itemCount} items</td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="openShipModal(${po.id}, ${po.pr_id})" title="Ship Order">
                                        <i class="fas fa-truck"></i> Ship Now
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="4" class="text-center py-4"><i class="fas fa-box-open fa-2x mb-2"></i><br>No orders ready to ship</td></tr>';
                }
                $('#toShipTableBody').html(html);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading to ship orders:', error);
            $('#toShipTableBody').html('<tr><td colspan="4" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// Load Shipped orders (view-only)
function loadShippedOrders() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'Shipped'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const shippedDate = po.shipped_date 
                            ? new Date(po.shipped_date).toLocaleDateString() 
                            : 'Unknown';
                        
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${shippedDate}</td>
                                <td>${po.department}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>
                                    <span class="badge bg-info">${po.tracking_number || 'No tracking'}</span><br>
                                    <small>${po.carrier || 'N/A'}</small>
                                </td>
                                <td>
                                    <span class="badge bg-primary">Shipped</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="viewTracking('${po.tracking_number}')" title="Track">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center">No shipped orders</td></tr>';
                }
                $('#shippedTableBody').html(html);
            }
        }
    });
}
// Open ship modal
function openShipModal(poId, prId) {
    $('#ship_po_id').val(poId);
    $('#ship_pr_id').val(prId);
    document.getElementById('shipPOForm').reset();
    document.getElementById('shipPhotoPreview').innerHTML = '';
    $('#shipPOModal').modal('show');
}

// Photo preview handler
document.addEventListener('DOMContentLoaded', function () {
    const photoInput = document.getElementById('shipment_photos');
    if (photoInput) {
        photoInput.addEventListener('change', function () {
            const preview = document.getElementById('shipPhotoPreview');
            preview.innerHTML = '';
            const files = Array.from(this.files).slice(0, 5);
            files.forEach(file => {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;';
                    preview.appendChild(img);
                };
                reader.readAsDataURL(file);
            });
        });
    }
});

// Modal focus management
$('#shipPOModal').on('shown.bs.modal', function () {
    $(this).find('#shipment_photos').focus();
});

$('#shipPOModal').on('hide.bs.modal', function () {
    if ($(this).find(':focus').length) {
        $(this).find(':focus').blur();
    }
});

// Submit handler
$('#shipPOForm').submit(function (e) {
    e.preventDefault();

    // Validate photos
    const photos = document.getElementById('shipment_photos').files;
    if (!photos || photos.length === 0) {
        Swal.fire('Required', 'Please upload at least one photo of the package.', 'warning');
        return;
    }

    // Validate shipping fee
    const shippingFee = parseFloat($('#shipping_fee').val());
    if (!shippingFee || shippingFee <= 0) {
        Swal.fire('Required', 'Please enter the shipping fee.', 'warning');
        return;
    }

    const formData = new FormData(this);
    formData.append('action', 'ship_order');

    Swal.fire({
        title: 'Confirming shipment...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: '../api/supplier_po.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Order is Out for Delivery!',
                    html: `Reference #: <strong>${response.tracking}</strong><br>
                           Shipping Fee: <strong>₱${parseFloat(response.shipping_fee).toFixed(2)}</strong><br>
                           <small class="text-muted">Photos saved. Buyer can now see the status update.</small>`,
                    timer: 4000
                }).then(() => {
                    $('#shipPOModal').modal('hide');
                    loadToShipOrders();
                    loadDashboardStats();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        },
        error: function (xhr, status, error) {
            Swal.fire('Error', 'Network error: ' + error, 'error');
        }
    });
});
function viewPODetails(poId) {
    $('#poDetailsContent').html('<div class="text-center py-3"><div class="spinner-border" style="color: #008080;"></div></div>');
    $('#poDetailsModal').modal('show');
    
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { action: 'get_po_details', id: poId },
        success: function(response) {
            if (response.success) {
                const po = response.data;
                let itemsHtml = '';
                let grandTotal = 0;
                
                po.items.forEach(item => {
                    const total = parseFloat(item.total_price || 0);
                    grandTotal += total;
                    itemsHtml += `
                        <tr>
                            <td>${item.item_name}</td>
                            <td>${item.description || '-'}</td>
                            <td class="text-center">${item.quantity}</td>
                            <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td class="text-end">₱${total.toFixed(2)}</td>
                        </tr>
                    `;
                });
                
                // Format dates
                const orderDate = po.order_date ? new Date(po.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                const expectedDate = po.expected_date ? new Date(po.expected_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Not set';
                
                const html = `
                    <!-- Single Card containing all information -->
                    <div class="card border" style="border-color: #008080 !important;">
                        <div class="card-body">
                            <!-- Order Summary Section -->
                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Order Summary</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0">
                                            <tr>
                                                <th width="40%">PO Number:</th>
                                                <td class="fw-bold" style="color: #008080;">${po.po_number}</td>
                                            </tr>
                                            <tr>
                                                <th>PR Number:</th>
                                                <td>${po.pr_number}</td>
                                            </tr>
                                            <tr>
                                                <th>Order Date:</th>
                                                <td>${orderDate}</td>
                                            </tr>
                                            <tr>
                                                <th>Expected Delivery:</th>
                                                <td>${expectedDate}</td>
                                            </tr>
                                            <tr>
                                                <th>Total Amount:</th>
                                                <td class="fw-bold text-success">₱${grandTotal.toFixed(2)}</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0">
                                            <tr>
                                                <th width="40%">Department:</th>
                                                <td>${po.department || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Purpose:</th>
                                                <td>${po.purpose || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Terms:</th>
                                                <td>${po.terms || 'Standard terms apply'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Customer/Clinic Information Section -->
                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Customer Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0">
                                            <tr>
                                                <th width="35%">Clinic Name:</th>
                                                <td class="fw-bold">${po.clinic_name || 'Eyecore Optical Clinic'}</td>
                                            </tr>
                                            <tr>
                                                <th>Address:</th>
                                                <td>${po.full_clinic_address || po.shipping_address || 'No address provided'}</td>
                                            </tr>
                                            <tr>
                                                <th>Phone:</th>
                                                <td>${po.clinic_phone || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Email:</th>
                                                <td>${po.clinic_email || 'N/A'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0">
                                            <tr>
                                                <th width="35%">Requested By:</th>
                                                <td class="fw-bold">${po.requester_name || po.requested_by || 'Unknown'}</td>
                                            </tr>
                                            <tr>
                                                <th>Email:</th>
                                                <td>${po.requester_email || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <th>Phone:</th>
                                                <td>${po.requester_phone || 'N/A'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Items Section with Table -->
                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Order Items</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead style="background-color: #00808020; color: #008080;">
                                            <tr>
                                                <th>Item</th>
                                                <th>Description</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${itemsHtml}
                                        </tbody>
                                        <tfoot style="background-color: #00808020;">
                                            <tr>
                                                <td colspan="4" class="text-end fw-bold">GRAND TOTAL:</td>
                                                <td class="text-end fw-bold" style="color: #008080;">₱${grandTotal.toFixed(2)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Additional Notes Section -->
                            ${po.pr_notes ? `
                            <div>
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Additional Notes</h6>
                                <p class="mb-0">${po.pr_notes}</p>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
                
                $('#poDetailsContent').html(html);
            } else {
                $('#poDetailsContent').html('<div class="alert alert-danger">Failed to load PO details</div>');
            }
        },
        error: function() {
            $('#poDetailsContent').html('<div class="alert alert-danger">Network error</div>');
        }
    });
}
function openApproveModal(poId, prId) {
    $('#approve_po_id').val(poId);
    $('#approve_pr_id').val(prId);
    $('#approvePOModal').modal('show');
}

$('#approvePOForm').submit(function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'approve_po');
    
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                Swal.fire('Success', response.message, 'success').then(() => {
                    $('#approvePOModal').modal('hide');
                    loadDashboardStats();
                    loadPendingPOs();
                    loadRecentPending();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
});

// Reject PO
function openRejectModal(poId, prId) {
    $('#reject_po_id').val(poId);
    $('#reject_pr_id').val(prId);
    $('#rejectPOModal').modal('show');
}

$('#rejectPOForm').submit(function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'reject_po');
    
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                Swal.fire('Rejected', response.message, 'info').then(() => {
                    $('#rejectPOModal').modal('hide');
                    loadDashboardStats();
                    loadPendingPOs();
                    loadRecentPending();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
});

// Initialize on page load
$(document).ready(function() {
    loadDashboardStats();
    loadRecentPending();
    loadReturns();
});

// ============================================
// DELIVERED MODULE
// ============================================
function loadDeliveredOrders() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'Delivered'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const deliveredDate = po.actual_delivery 
                            ? new Date(po.actual_delivery).toLocaleDateString() 
                            : 'Unknown';
                        
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                <td>${po.department || 'N/A'}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>${deliveredDate}</td>
                                <td>
                                    <span class="badge bg-success">Delivered</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    ${po.tracking_number ? 
                                        `<button class="btn btn-sm btn-secondary" onclick="viewTracking('${po.tracking_number}')" title="Track">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </button>` : ''}
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-check-double fa-2x mb-2"></i><br>No delivered orders</td></tr>';
                }
                $('#deliveredTableBody').html(html);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading delivered orders:', error);
            $('#deliveredTableBody').html('<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// ============================================
// REJECTED MODULE
// ============================================
function loadRejectedOrders() {
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { 
            action: 'get_pos',
            status: 'Supplier Rejected'
        },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const rejectDate = po.response_date 
                            ? new Date(po.response_date).toLocaleDateString() 
                            : 'Unknown';
                        const reason = po.supplier_response || 'No reason provided';
                        
                        html += `
                            <tr>
                                <td><strong>${po.po_number}</strong></td>
                                <td>${po.pr_number}</td>
                                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                <td>${po.department || 'N/A'}</td>
                                <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                                <td>${rejectDate}</td>
                                <td>
                                    <span class="badge bg-danger">Rejected</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="viewRejectReason('${reason}')" title="Reason">
                                        <i class="fas fa-question-circle"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-times-circle fa-2x mb-2"></i><br>No rejected orders</td></tr>';
                }
                $('#rejectedTableBody').html(html);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading rejected orders:', error);
            $('#rejectedTableBody').html('<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// View reject reason
function viewRejectReason(reason) {
    Swal.fire({
        title: 'Rejection Reason',
        text: reason,
        icon: 'info',
        confirmButtonText: 'OK'
    });
}

// View tracking info
function viewTracking(trackingNumber) {
    Swal.fire({
        title: 'Tracking Information',
        html: `Tracking Number: <strong>${trackingNumber}</strong>`,
        icon: 'info',
        confirmButtonText: 'OK'
    });
}

// ============================================
// DELIVERY HISTORY (connected to Delivered POs)
// ============================================
let allDeliveryData = []; // cached for client-side filtering

function loadDeliveryHistory() {
    $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Loading...</td></tr>');
    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { action: 'get_pos', status: 'Delivered' },
        success: function(response) {
            if (response.success) {
                allDeliveryData = response.data || [];
                renderDeliveryHistory(allDeliveryData);
            } else {
                $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center text-danger">Failed to load delivery history</td></tr>');
            }
        },
        error: function() {
            $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center text-danger">Error loading delivery history</td></tr>');
        }
    });
}

function applyDeliveryFilter() {
    const search = ($('#deliveryHistorySearch').val() || '').toLowerCase().trim();
    const fromVal = $('#dhFromDate').val();
    const toVal   = $('#dhToDate').val();
    const fromDate = fromVal ? new Date(fromVal) : null;
    const toDate   = toVal   ? new Date(toVal + 'T23:59:59') : null;

    const filtered = allDeliveryData.filter(po => {
        if (search) {
            const haystack = `${po.po_number} ${po.pr_number} ${po.department}`.toLowerCase();
            if (!haystack.includes(search)) return false;
        }
        if (fromDate || toDate) {
            const d = po.actual_delivery ? new Date(po.actual_delivery) : null;
            if (!d) return false;
            if (fromDate && d < fromDate) return false;
            if (toDate  && d > toDate)   return false;
        }
        return true;
    });

    renderDeliveryHistory(filtered);
}

function clearDeliveryFilter() {
    $('#deliveryHistorySearch').val('');
    $('#dhFromDate').val('');
    $('#dhToDate').val('');
    renderDeliveryHistory(allDeliveryData);
}

function renderDeliveryHistory(data) {
    let html = '';
    let totalValue = 0;
    const now = new Date();
    let thisMonthCount = 0;

    if (data.length > 0) {
        data.forEach(po => {
            const amount = parseFloat(po.total_amount || 0);
            totalValue += amount;
            const deliveredDate = po.actual_delivery ? new Date(po.actual_delivery) : null;
            if (deliveredDate && deliveredDate.getMonth() === now.getMonth() && deliveredDate.getFullYear() === now.getFullYear()) {
                thisMonthCount++;
            }
            html += `<tr>
                <td><strong>${po.po_number}</strong></td>
                <td>${po.pr_number || '-'}</td>
                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                <td>${po.department || 'N/A'}</td>
                <td class="fw-bold">₱${amount.toFixed(2)}</td>
                <td>${deliveredDate ? deliveredDate.toLocaleDateString() : '<span class="text-muted">N/A</span>'}</td>
                <td>${po.tracking_number
                    ? `<span class="badge bg-info">${po.tracking_number}</span><br><small class="text-muted">${po.carrier || ''}</small>`
                    : '<span class="text-muted">-</span>'}</td>
                <td><span class="badge bg-success">Delivered</span></td>
                <td>
                    <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>`;
        });
    } else {
        html = '<tr><td colspan="9" class="text-center py-4"><i class="fas fa-truck fa-2x text-muted mb-2 d-block"></i>No delivery records found</td></tr>';
    }

    $('#deliveryHistoryTableBody').html(html);
    $('#dhTotalDelivered').text(data.length);
    $('#dhTotalValue').text('₱' + totalValue.toFixed(2));
    $('#dhThisMonth').text(thisMonthCount);
}

// ============================================
// COMPANY PROFILE EDIT
// ============================================
function toggleProfileEdit() {
    const view = document.getElementById('profileViewMode');
    const edit = document.getElementById('profileEditMode');
    const btn  = document.getElementById('editProfileBtn');
    if (edit.style.display === 'none') {
        view.style.display = 'none';
        edit.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times me-1"></i> Cancel';
        btn.classList.replace('btn-teal', 'btn-secondary');
    } else {
        view.style.display = 'block';
        edit.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-edit me-1"></i> Edit Profile';
        btn.classList.replace('btn-secondary', 'btn-teal');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const profileForm = document.getElementById('profileEditForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update');
            formData.append('id', supplierId);

            $.ajax({
                url: '../api/supplier.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Profile Updated!', timer: 1500 }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.error || 'Could not update profile', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Network error. Please try again.', 'error');
                }
            });
        });
    }
});

// ============================================
// CONTACTS MANAGEMENT
// ============================================
function loadContacts() {
    $.ajax({
        url: '../api/supplier.php',
        method: 'GET',
        data: { action: 'get_contacts', supplier_id: supplierId },
        success: function(response) {
            if (response.success && response.data) {
                renderContacts(response.data);
            }
        }
    });
}

function renderContacts(contacts) {
    const grid = document.getElementById('contactsContainer');
    const emptyState = document.getElementById('contactsEmptyState');

    if (!contacts || contacts.length === 0) {
        if (grid) grid.innerHTML = '';
        if (emptyState) emptyState.style.display = 'block';
        return;
    }
    if (emptyState) emptyState.style.display = 'none';

    let html = '';
    contacts.forEach(c => {
        html += `<div class="col-md-4 mb-3">
            <div class="dashboard-card">
                <div class="d-flex align-items-start justify-content-between">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:48px;height:48px;background:#e6f4f3;">
                            <i class="fas fa-user" style="color:var(--teal-600);"></i>
                        </div>
                        <div>
                            <div class="fw-bold">${escapeHtml(c.contact_name)}</div>
                            <div class="text-muted small">${escapeHtml(c.position || 'N/A')}</div>
                        </div>
                    </div>
                    ${c.is_primary ? '<span class="badge bg-success">Primary</span>' : ''}
                </div>
                <hr class="my-2">
                <div class="small">
                    ${c.email ? `<div class="mb-1"><i class="fas fa-envelope text-muted me-2"></i>${escapeHtml(c.email)}</div>` : ''}
                    ${c.phone ? `<div class="mb-1"><i class="fas fa-phone text-muted me-2"></i>${escapeHtml(c.phone)}</div>` : ''}
                    ${c.mobile ? `<div class="mb-1"><i class="fas fa-mobile-alt text-muted me-2"></i>${escapeHtml(c.mobile)}</div>` : ''}
                    ${c.department ? `<div class="mb-1"><i class="fas fa-building text-muted me-2"></i>${escapeHtml(c.department)}</div>` : ''}
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editContact(${c.id})">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteContact(${c.id})">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>`;
    });

    if (grid) grid.innerHTML = html;
}

// Add contact form submit
document.addEventListener('DOMContentLoaded', function() {
    const addContactForm = document.getElementById('addContactForm');
    if (addContactForm) {
        addContactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_contact');
            formData.append('supplier_id', supplierId);

            $.ajax({
                url: '../api/supplier.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Contact Added!', timer: 1200 }).then(() => {
                            bootstrap.Modal.getInstance(document.getElementById('addContactModal')).hide();
                            addContactForm.reset();
                            loadContacts();
                        });
                    } else {
                        Swal.fire('Error', response.error || 'Could not add contact', 'error');
                    }
                }
            });
        });
    }

    const editContactForm = document.getElementById('editContactForm');
    if (editContactForm) {
        editContactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_contact');
            formData.append('supplier_id', supplierId);

            $.ajax({
                url: '../api/supplier.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Contact Updated!', timer: 1200 }).then(() => {
                            bootstrap.Modal.getInstance(document.getElementById('editContactModal')).hide();
                            loadContacts();
                        });
                    } else {
                        Swal.fire('Error', response.error || 'Could not update contact', 'error');
                    }
                }
            });
        });
    }
});

function editContact(id) {
    $.get('../api/supplier.php', { action: 'get_contact', id: id, supplier_id: supplierId }, function(r) {
        if (r.success && r.data) {
            const c = r.data;
            $('#editContactId').val(c.id);
            $('#editContactName').val(c.contact_name || '');
            $('#editContactPosition').val(c.position || '');
            $('#editContactEmail').val(c.email || '');
            $('#editContactPhone').val(c.phone || '');
            $('#editContactMobile').val(c.mobile || '');
            $('#editContactDept').val(c.department || '');
            $('#editIsPrimary').prop('checked', c.is_primary == 1);
            $('#editContactModal').modal('show');
        } else {
            Swal.fire('Error', 'Could not load contact details', 'error');
        }
    }).fail(function() {
        Swal.fire('Error', 'Network error loading contact', 'error');
    });
}

function deleteContact(id) {
    Swal.fire({
        title: 'Delete Contact?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('../api/supplier.php', { action: 'delete_contact', contact_id: id, supplier_id: supplierId }, function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Contact deleted', timer: 1200 }).then(() => loadContacts());
                } else {
                    Swal.fire('Error', response.error || 'Could not delete contact', 'error');
                }
            });
        }
    });
}

// ============================================
// PRODUCTS MANAGEMENT MODULE
// ============================================


// Photo preview for add form
document.addEventListener('DOMContentLoaded', function() {
    const productPhoto = document.getElementById('product_photo');
    if (productPhoto) {
        productPhoto.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('preview_image');
                    const previewDiv = document.getElementById('photo_preview');
                    if (preview && previewDiv) {
                        preview.src = e.target.result;
                        previewDiv.style.display = 'block';
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    }

    // Add product form submit
    const addForm = document.getElementById('add_product_form');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            addProduct();
        });
    }

    // Edit product form submit
    const editForm = document.getElementById('edit_product_form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateProduct();
        });
    }

    // Radio button toggle for photo remove
    const removePhotoCheck = document.getElementById('remove_photo');
    if (removePhotoCheck) {
        removePhotoCheck.addEventListener('change', function() {
            const photoInput = document.getElementById('edit_product_photo');
            if (photoInput) {
                photoInput.disabled = this.checked;
            }
        });
    }
});

// ============================================
// LOAD PRODUCTS (My Products)
// ============================================
function loadProducts(page = 1) {
    currentProductPage = page;
    const category = document.getElementById('product_category_filter')?.value || 'all';
    const search = document.getElementById('product_search')?.value || '';
    
    let url = `../api/supplier_products.php?action=get_products&supplier_id=${supplierId}&page=${page}&limit=10`;
    if (category && category !== 'all') url += `&category=${encodeURIComponent(category)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayProducts(data.data);
                updateProductPagination(data.pagination);
            } else {
                document.getElementById('products_table_body').innerHTML = `<tr><td colspan="9" class="text-center text-danger">${data.message}</td></tr>`;
            }
        })
        .catch(err => {
            console.error('Error loading products:', err);
            document.getElementById('products_table_body').innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error loading products</td></tr>';
        });
}

function displayProducts(products) {
    const tbody = document.getElementById('products_table_body');
    
    if (!tbody) return;
    
    if (!products || products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4"><i class="fas fa-box-open fa-3x mb-3"></i><br>No products found</td></tr>';
        return;
    }
    
    let html = '';
    products.forEach(p => {
        const hasPhoto = p.has_photo ? 
            `<img src="../${p.photo_path}" alt="Product" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">` : 
            '<div class="bg-light text-center p-2" style="width: 50px; height: 50px; border-radius: 5px;"><i class="fas fa-image text-muted"></i></div>';
        
        const inInventory = p.linked_count > 0 ? 
            `<span class="badge bg-success">${p.linked_count} items</span>` : 
            '<span class="badge bg-secondary">Not linked</span>';
        
        html += `<tr>
            <td>${hasPhoto}</td>
            <td><strong>${escapeHtml(p.product_code)}</strong></td>
            <td>${escapeHtml(p.product_name)}</td>
            <td>${escapeHtml(p.category)}</td>
            <td>${escapeHtml(p.brand || '-')}</td>
            <td>₱${parseFloat(p.cost_price).toFixed(2)}</td>
            <td>₱${parseFloat(p.selling_price).toFixed(2)}</td>
            <td>${inInventory}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewProduct(${p.id})" title="View">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-warning" onclick="editProduct(${p.id})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                ${p.linked_count === 0 ? 
                    `<button class="btn btn-sm btn-danger" onclick="deleteProduct(${p.id})" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>` : ''}
            </td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateProductPagination(pagination) {
    const info = document.getElementById('products_pagination_info');
    const ul = document.getElementById('products_pagination');
    
    if (!info || !ul) return;
    
    if (!pagination || pagination.total_pages <= 1) {
        info.innerHTML = `Showing ${pagination?.total || 0} products`;
        ul.innerHTML = '';
        return;
    }
    
    info.innerHTML = `Page ${pagination.page} of ${pagination.total_pages} (${pagination.total} products)`;
    
    let pages = '';
    for (let i = 1; i <= pagination.total_pages; i++) {
        pages += `<li class="page-item ${i === pagination.page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadProducts(${i}); return false;">${i}</a>
        </li>`;
    }
    
    ul.innerHTML = pages;
}

// ============================================
// ADD PRODUCT
// ============================================
function addProduct() {
    const form = document.getElementById('add_product_form');
    if (!form) return;
    
    const formData = new FormData(form);
    formData.append('action', 'add_product');
    formData.append('supplier_id', supplierId);
    
    Swal.fire({
        title: 'Saving product...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('../api/supplier_products.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Product Added!',
                text: data.message,
                timer: 1500
            }).then(() => {
                form.reset();
                const preview = document.getElementById('photo_preview');
                if (preview) preview.style.display = 'none';
                showModule('products_list');
                loadProducts(1); // ✅ I-load ang page 1 para makita agad
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire('Error', 'Failed to add product', 'error');
    });
}

function viewProduct(id) {
    fetch(`../api/supplier_products.php?action=get_product&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayProductDetails(data.data);
                const modal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
                modal.show();
            } else {
                Swal.fire('Error', 'Product not found', 'error');
            }
        })
        .catch(err => console.error('Error:', err));
}

function displayProductDetails(p) {
    const modalBody = document.getElementById('product_details_body');
    if (!modalBody) return;
    
    const hasPhoto = p.has_photo ? 
        `<img src="../${p.photo_path}" alt="Product" style="max-width: 200px; max-height: 200px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">` : 
        '<div class="bg-light text-center p-5 rounded"><i class="fas fa-image fa-4x text-muted"></i><p class="mt-2">No photo</p></div>';
    
    let linkedHtml = '';
    if (p.linked_inventory && p.linked_inventory.length > 0) {
        linkedHtml = '<h6 class="mt-3">Linked Inventory Items:</h6><ul class="list-group">';
        p.linked_inventory.forEach(inv => {
            linkedHtml += `<li class="list-group-item d-flex justify-content-between align-items-center">
                ${escapeHtml(inv.item_code)} - ${escapeHtml(inv.name)}
                <span class="badge bg-${inv.stock > 0 ? 'success' : 'danger'}">Stock: ${inv.stock}</span>
            </li>`;
        });
        linkedHtml += '</ul>';
    }
    
    modalBody.innerHTML = `
        <div class="row">
            <div class="col-md-4 text-center mb-3">
                ${hasPhoto}
            </div>
            <div class="col-md-8">
                <h4 class="mb-3">${escapeHtml(p.product_name)}</h4>
                <table class="table table-sm">
                    <tr><th width="35%">Product Code:</th><td>${escapeHtml(p.product_code)}</td></tr>
                    <tr><th>Category:</th><td>${escapeHtml(p.category)}</td></tr>
                    <tr><th>Brand:</th><td>${escapeHtml(p.brand || 'N/A')}</td></tr>
                    <tr><th>Cost Price:</th><td class="fw-bold text-primary">₱${parseFloat(p.cost_price).toFixed(2)}</td></tr>
                    <tr><th>Selling Price:</th><td class="fw-bold text-success">₱${parseFloat(p.selling_price).toFixed(2)}</td></tr>
                    ${p.wholesale_price ? `<tr><th>Wholesale:</th><td>₱${parseFloat(p.wholesale_price).toFixed(2)}</td></tr>` : ''}
                    <tr><th>Unit:</th><td>${escapeHtml(p.unit)}</td></tr>
                    <tr><th>Min Order:</th><td>${p.min_order_qty}</td></tr>
                    ${p.lead_time_days ? `<tr><th>Lead Time:</th><td>${p.lead_time_days} days</td></tr>` : ''}
                </table>
                
                ${p.description ? `
                    <div class="mt-3">
                        <strong>Description:</strong>
                        <p>${escapeHtml(p.description)}</p>
                    </div>
                ` : ''}
                
                ${p.notes ? `
                    <div class="mt-2">
                        <strong>Notes:</strong>
                        <p class="text-muted">${escapeHtml(p.notes)}</p>
                    </div>
                ` : ''}
                
                ${linkedHtml}
            </div>
        </div>
    `;
}

function editProduct(id) {
    fetch(`../api/supplier_products.php?action=get_product&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const p = data.data;
                
                document.getElementById('edit_product_id').value = p.id;
                document.getElementById('edit_product_code').value = p.product_code;
                document.getElementById('edit_product_name').value = p.product_name;
                document.getElementById('edit_product_category').value = p.category;
                document.getElementById('edit_product_brand').value = p.brand || '';
                document.getElementById('edit_cost_price').value = p.cost_price;
                document.getElementById('edit_selling_price').value = p.selling_price;
                document.getElementById('edit_wholesale_price').value = p.wholesale_price || '';
                document.getElementById('edit_product_description').value = p.description || '';
                
                // ✅ ADD THIS: Stock fields
                document.getElementById('edit_stock').value = p.stock || 0;
                document.getElementById('edit_low_stock_threshold').value = p.low_stock_threshold || 5;
                
                // Show current photo if exists
                const photoDiv = document.getElementById('current_photo_display');
                if (photoDiv) {
                    if (p.has_photo) {
                        photoDiv.innerHTML = `
                            <div class="border p-2 rounded">
                                <small>Current Photo:</small><br>
                                <img src="../${p.photo_path}" style="max-height: 100px;">
                            </div>
                        `;
                    } else {
                        photoDiv.innerHTML = '<p class="text-muted"><small>No current photo</small></p>';
                    }
                }
                
                const modal = new bootstrap.Modal(document.getElementById('editProductModal'));
                modal.show();
            } else {
                Swal.fire('Error', 'Failed to load product', 'error');
            }
        })
        .catch(err => console.error('Error:', err));
}


function updateProduct() {
    const form = document.getElementById('edit_product_form');
    if (!form) return;
    
    const formData = new FormData(form);
    formData.append('action', 'update_product');
    
    // ✅ DEBUG: Ipakita ang laman ng formData
    console.log('=== DEBUG FORM DATA ===');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    // ✅ Check specifically ang product_id
    const productId = formData.get('product_id');
    console.log('Product ID from form:', productId);
    
    if (!productId) {
        // ✅ Kung walang product_id, hanapin kung may ibang name
        console.log('Checking alternative field names...');
        console.log('id:', formData.get('id'));
        console.log('ID:', formData.get('ID'));
        console.log('productId:', formData.get('productId'));
        
        Swal.fire('Error', 'Product ID is missing from form', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Updating...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('../api/supplier_products.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        console.log('Server response:', data); // ✅ Debug
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Updated!',
                text: data.message,
                timer: 1500
            }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editProductModal'));
                if (modal) modal.hide();
                loadProducts(currentProductPage);
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire('Error', 'Failed to update', 'error');
    });
}

function deleteProduct(id) {
    Swal.fire({
        title: 'Delete Product?',
        text: 'This will deactivate the product. You can restore it later.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('../api/supplier_products.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_product', id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success');
                    loadProducts(currentProductPage);
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

// ============================================
// PRICE LIST
// ============================================
function loadPriceList() {
    const search = document.getElementById('price_search')?.value || '';
    
    let url = `../api/supplier_products.php?action=get_price_list&supplier_id=${supplierId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayPriceList(data.data);
            } else {
                const tbody = document.getElementById('price_list_table_body');
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${data.message}</td></tr>`;
                }
            }
        })
        .catch(err => {
            console.error('Error:', err);
            const tbody = document.getElementById('price_list_table_body');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error loading price list</td></tr>';
            }
        });
}

function displayPriceList(prices) {
    const tbody = document.getElementById('price_list_table_body');
    if (!tbody) return;
    
    if (!prices || prices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No price list data</td></tr>';
        return;
    }
    
    let html = '';
    prices.forEach(p => {
        const margin = p.margin || 0;
        const marginClass = margin > 30 ? 'text-success' : (margin > 15 ? 'text-warning' : 'text-danger');
        
        html += `<tr>
            <td>${escapeHtml(p.product_code)}</td>
            <td>${escapeHtml(p.product_name)}</td>
            <td>${escapeHtml(p.brand || '-')}</td>
            <td>${escapeHtml(p.category)}</td>
            <td class="text-end">₱${parseFloat(p.cost_price).toFixed(2)}</td>
            <td class="text-end fw-bold">₱${parseFloat(p.selling_price).toFixed(2)}</td>
            <td class="text-end">${p.wholesale_price ? '₱' + parseFloat(p.wholesale_price).toFixed(2) : '-'}</td>
            <td class="text-end ${marginClass}">${margin}%</td>
            <td>
                ${p.in_inventory > 0 ? 
                    '<span class="badge bg-success">Active</span>' : 
                    '<span class="badge bg-warning">Not in Inv</span>'}
            </td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

// ============================================
// STOCK LEVELS
// ============================================
function loadStockLevels() {
    const search = document.getElementById('stock_search')?.value || '';
    
    let url = `../api/supplier_products.php?action=get_stock_levels&supplier_id=${supplierId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayStockLevels(data.data);
                updateStockSummary(data.summary);
            } else {
                const tbody = document.getElementById('stock_levels_table_body');
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">${data.message}</td></tr>`;
                }
            }
        })
        .catch(err => {
            console.error('Error:', err);
            const tbody = document.getElementById('stock_levels_table_body');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading stock levels</td></tr>';
            }
        });
}

function displayStockLevels(stocks) {
    const tbody = document.getElementById('stock_levels_table_body');
    if (!tbody) return;
    
    if (!stocks || stocks.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No stock data</td></tr>';
        return;
    }
    
    let html = '';
    stocks.forEach(s => {
        let statusBadge = '';
        let rowClass = '';
        
        switch(s.stock_status) {
            case 'Low Stock':
                statusBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';
                rowClass = 'table-warning';
                break;
            case 'Out of Stock':
                statusBadge = '<span class="badge bg-danger">Out of Stock</span>';
                rowClass = 'table-danger';
                break;
            case 'In Stock':
                statusBadge = '<span class="badge bg-success">In Stock</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">Not in Inventory</span>';
                rowClass = 'table-secondary';
        }
        
        const suggestedOrder = s.suggested_order || 0;
        const estCost = s.estimated_cost || 0;
        
        html += `<tr class="${rowClass}">
            <td>
                <strong>${escapeHtml(s.product_name)}</strong><br>
                <small class="text-muted">${escapeHtml(s.brand || '')}</small>
            </td>
            <td>${escapeHtml(s.product_code)}</td>
            <td class="text-center fw-bold">${s.current_stock}</td>
            <td class="text-center">${s.reorder_level}</td>
            <td>${statusBadge}</td>
            <td class="text-center">${suggestedOrder}</td>
            <td class="text-end">₱${estCost.toFixed(2)}</td>
            <td>
                ${suggestedOrder > 0 ? 
                    `<button class="btn btn-sm btn-warning" onclick="createReorder(${s.id})" title="Create Purchase Request">
                        <i class="fas fa-cart-plus"></i>
                    </button>` : 
                    `<button class="btn btn-sm btn-secondary" disabled title="No need to reorder">
                        <i class="fas fa-check"></i>
                    </button>`}
            </td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
}

function updateStockSummary(summary) {
    const inStock = document.getElementById('summary_in_stock');
    const lowStock = document.getElementById('summary_low_stock');
    const outOfStock = document.getElementById('summary_out_of_stock');
    const notInInv = document.getElementById('summary_not_in_inventory');
    
    if (inStock) inStock.textContent = summary.in_stock || 0;
    if (lowStock) lowStock.textContent = summary.low_stock || 0;
    if (outOfStock) outOfStock.textContent = summary.out_of_stock || 0;
    if (notInInv) notInInv.textContent = summary.not_in_inventory || 0;
}

// ============================================
// REORDER SUGGESTIONS
// ============================================
function showReorderSummary() {
    fetch(`../api/supplier_products.php?action=get_stock_levels&supplier_id=${supplierId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const lowStock = data.data.filter(s => s.suggested_order > 0);
                
                if (lowStock.length === 0) {
                    Swal.fire({
                        icon: 'info',
                        title: 'All Good!',
                        text: 'No items need reordering at this time.'
                    });
                    return;
                }
                
                let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Current</th><th>Reorder Level</th><th>Suggested</th><th>Est. Cost</th></tr></thead><tbody>';
                let totalCost = 0;
                
                lowStock.forEach(s => {
                    const cost = s.estimated_cost || 0;
                    totalCost += cost;
                    html += `<tr>
                        <td>${escapeHtml(s.product_name)}</td>
                        <td class="text-center">${s.current_stock}</td>
                        <td class="text-center">${s.reorder_level}</td>
                        <td class="text-center fw-bold">${s.suggested_order}</td>
                        <td class="text-end">₱${cost.toFixed(2)}</td>
                    </tr>`;
                });
                
                html += `<tr class="table-info fw-bold">
                    <td colspan="4" class="text-end">TOTAL ESTIMATED COST:</td>
                    <td class="text-end">₱${totalCost.toFixed(2)}</td>
                </tr></tbody></table></div>`;
                
                Swal.fire({
                    title: 'Reorder Suggestions',
                    html: html,
                    icon: 'warning',
                    width: '800px',
                    confirmButtonText: 'OK'
                });
            }
        });
}

function createReorder(productId) {
    Swal.fire({
        title: 'Create Purchase Request?',
        text: 'This will create a purchase request for this item',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, create'
    }).then((result) => {
        if (result.isConfirmed) {
            showModule('po_pending');
            Swal.fire('Info', 'Purchase request feature coming soon', 'info');
        }
    });
}

// ============================================
// EXPORT FUNCTIONS
// ============================================
function exportProducts() {
    window.location.href = `../api/supplier_products.php?action=export_products&supplier_id=${supplierId}`;
}

function exportPriceList() {
    window.location.href = `../api/supplier_products.php?action=export_price_list&supplier_id=${supplierId}`;
}

// ============================================
// COMBINED showModule FUNCTION (Products + Returns)
// ============================================
const originalShowModule = showModule;
showModule = function(moduleId) {
    originalShowModule(moduleId);
    
    setTimeout(() => {
        switch(moduleId) {
            case 'products_list':
                loadProducts();
                break;
            case 'products_price':
                loadPriceList();
                break;
            case 'products_stock':
                loadStockLevels();
                break;
            case 'returns_management':
                loadReturns();
                break;
        }
    }, 100);
};

// ============================================
// RETURNS MANAGEMENT FUNCTIONS
// ============================================

// Load all returns data
function loadReturns() {
    $.ajax({
        url: '../api/supplier_returns.php',
        method: 'GET',
        data: { action: 'get_returns', supplier_id: supplierId },
        success: function(response) {
            if (response.success) {
                displayReturnsByStatus(response.data);
                updateReturnCounts(response.counts);
            } else {
                console.error('Error loading returns:', response.error);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
        }
    });
}


function displayReturnsByStatus(returns) {
    // Clear existing content
    $('#pendingReturnsTable, #approvedReturnsTable, #completedReturnsTable, #rejectedReturnsTable, #allReturnsTable').html('');
    
    if (!returns || returns.length === 0) {
        $('#pendingReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#approvedReturnsTable').html('<tr><td colspan="9" class="text-center py-4">No return requests found</td></tr>');
        $('#completedReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#rejectedReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#allReturnsTable').html('<tr><td colspan="9" class="text-center py-4">No return requests found</td></tr>');
        return;
    }
    
    let pendingRows = '', approvedRows = '', completedRows = '', rejectedRows = '', allRows = '';
    
    returns.forEach(r => {
        allRows += createReturnRow(r);
        
        switch(r.status) {
            case 'Pending':
                pendingRows += createPendingRow(r);
                break;
            case 'For Pickup':
            case 'For Drop-off':
            case 'Ready for Pickup':
            case 'Dropped Off':
                approvedRows += createApprovedRow(r);
                break;
            case 'Return Completed':
            case 'Completed':
                completedRows += createCompletedRow(r);
                break;
            case 'Rejected':
                rejectedRows += createRejectedRow(r);
                break;
        }
    });
    
    $('#pendingReturnsTable').html(pendingRows || '<tr><td colspan="8" class="text-center py-4">No pending returns</td></tr>');
    $('#approvedReturnsTable').html(approvedRows || '<tr><td colspan="9" class="text-center py-4">No approved returns</td></tr>');
    $('#completedReturnsTable').html(completedRows || '<tr><td colspan="8" class="text-center py-4">No completed returns</td></tr>');
    $('#rejectedReturnsTable').html(rejectedRows || '<tr><td colspan="8" class="text-center py-4">No rejected returns</td></tr>');
    $('#allReturnsTable').html(allRows || '<tr><td colspan="9" class="text-center py-4">No returns found</td></tr>');
}

function createPendingRow(r) {
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td>
            <button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View">
                <i class="fas fa-eye"></i>
            </button>
<button class="btn btn-sm btn-success"
    onclick="openApproveReturnModal(${r.id}, '${r.return_number}', '${r.po_number}', ${r.refund_amount})"
    title="Approve">
    <i class="fas fa-check"></i>
</button>
<button class="btn btn-sm btn-danger"
    onclick="openRejectReturnModal(${r.id})"
    title="Reject">
    <i class="fas fa-times"></i>
</button>
        </td>
    </tr>`;
}

// Create row for approved/for pickup/ready returns
function createApprovedRow(r) {
    // Determine kung anong action button ang ipapakita
    let actionButton = '';
    
    if (r.status === 'Ready for Pickup' || r.status === 'Dropped Off') {
        // Supplier can complete the return
        actionButton = `
            <button class="btn btn-sm btn-success" onclick="completeReturn(${r.id})" title="Complete Return">
                <i class="fas fa-check-double"></i> Complete
            </button>
        `;
    } else if (r.status === 'For Pickup' || r.status === 'For Drop-off') {
        // Waiting for clinic to prepare
        actionButton = `
            <button class="btn btn-sm btn-secondary" disabled title="Waiting for clinic">
                <i class="fas fa-clock"></i> Waiting
            </button>
        `;
    }
    
    // Determine status badge color
    let statusBadgeClass = 'info';
    let statusText = r.status;
    
    if (r.status === 'Ready for Pickup') {
        statusBadgeClass = 'warning';
        statusText = 'Ready for Pickup';
    } else if (r.status === 'Dropped Off') {
        statusBadgeClass = 'warning';
        statusText = 'Dropped Off';
    } else if (r.status === 'For Pickup') {
        statusBadgeClass = 'primary';
    } else if (r.status === 'For Drop-off') {
        statusBadgeClass = 'primary';
    }
    
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td><span class="badge bg-${statusBadgeClass}">${statusText}</span></td>
        <td>
            <button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View">
                <i class="fas fa-eye"></i>
            </button>
            ${actionButton}
        </td>
    </tr>`;
}
// Create row for completed returns
function createCompletedRow(r) {
    let completedDate = 'N/A';
    if (r.completed_at) {
        completedDate = new Date(r.completed_at).toLocaleDateString();
    } else if (r.response_date) {
        completedDate = new Date(r.response_date).toLocaleDateString();
    } else if (r.updated_at) {
        completedDate = new Date(r.updated_at).toLocaleDateString();
    }
    
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${completedDate}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.refund_method}</td>
        <td class="fw-bold text-success">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td>
            <button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View Details">
                <i class="fas fa-eye"></i>
            </button>
        </td>
    </tr>`;
}

// Complete return function (supplier clicks this after receiving items)
function completeReturn(returnId) {
    Swal.fire({
        title: 'Complete Return?',
        text: 'Confirm that you have received the returned items from the clinic.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, complete return',
        confirmButtonColor: '#28a745',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            $.ajax({
                url: '../api/supplier_returns.php',
                method: 'POST',
                data: {
                    action: 'complete_return',
                    return_id: returnId
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Return Completed!',
                            text: 'The return process has been completed.',
                            timer: 2000
                        }).then(() => {
                            loadReturns(); // Reload the data
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Network error. Please try again.', 'error');
                }
            });
        }
    });
}
// Create row for rejected returns
function createRejectedRow(r) {
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td>${r.supplier_response || 'No reason provided'}</td>
    </tr>`;
}

// Create row for all returns - FIXED
function createReturnRow(r) {
    let statusClass = 'secondary';
    let statusText = r.status;
    
    // Map status to badge color
    const statusMap = {
        'Pending': 'warning',
        'For Pickup': 'primary',
        'For Drop-off': 'primary',
        'Ready for Pickup': 'warning',
        'Dropped Off': 'warning',
        'Return Completed': 'success',
        'Completed': 'success',
        'Rejected': 'danger'
    };
    
    statusClass = statusMap[r.status] || 'secondary';
    
    // Rename display text
    if (r.status === 'Ready for Pickup') statusText = 'Ready for Pickup';
    if (r.status === 'Dropped Off') statusText = 'Dropped Off';
    if (r.status === 'Return Completed') statusText = 'Return Completed';
    
    // Action buttons based on status
    let actionButtons = `<button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})"><i class="fas fa-eye"></i></button>`;
    
    if (r.status === 'Pending') {
        actionButtons += `
            <button class="btn btn-sm btn-success" onclick="openApproveReturnModal(${r.id}, '${r.return_number}', '${r.po_number}', ${r.refund_amount})">
                <i class="fas fa-check"></i>
            </button>
            <button class="btn btn-sm btn-danger" onclick="openRejectReturnModal(${r.id})">
                <i class="fas fa-times"></i>
            </button>
        `;
    } else if (r.status === 'Ready for Pickup' || r.status === 'Dropped Off') {
        actionButtons += `
            <button class="btn btn-sm btn-success" onclick="completeReturn(${r.id})">
                <i class="fas fa-check-double"></i> Complete
            </button>
        `;
    }
    
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
        <td>${actionButtons}</td>
    </tr>`;
}
updateReturnCounts
// Open process modal
function openProcessModal(returnId, currentStatus) {
    $('#process_return_id').val(returnId);
    $('#process_current_status').val(currentStatus);
    $('#process_action').val('');
    $('#process_notes').val('');
    $('#processReturnModal').modal('show');
}

// Process return (approve/reject/complete)
function processReturn() {
    const returnId = $('#process_return_id').val();
    const action = $('#process_action').val();
    const notes = $('#process_notes').val();
    
    if (!action) {
        Swal.fire('Error', 'Please select an action', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Process Return?',
        text: `Are you sure you want to ${action} this return request?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, proceed'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/supplier_returns.php',
                method: 'POST',
                data: {
                    action: 'process_return',
                    return_id: returnId,
                    status_action: action,
                    notes: notes
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success').then(() => {
                            $('#processReturnModal').modal('hide');
                            loadReturns(); // Reload the data
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                }
            });
        }
    });
}

// View return details
function viewReturnDetails(returnId) {
    $('#returnDetailsContent').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#returnDetailsModal').modal('show');
    
    $.ajax({
        url: '../api/supplier_returns.php',
        method: 'GET',
        data: { action: 'get_return_details', id: returnId },
        success: function(response) {
            if (response.success) {
                displayReturnDetails(response.data);
            } else {
                $('#returnDetailsContent').html('<div class="alert alert-danger">Failed to load details</div>');
            }
        }
    });
}

// Display return details in modal
function displayReturnDetails(r) {
    let itemsHtml = '';
    r.items.forEach(item => {
        itemsHtml += `<tr>
            <td>${item.item_name}</td>
            <td class="text-center">${item.quantity}</td>
            <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
            <td class="text-end">₱${parseFloat(item.total).toFixed(2)}</td>
        </tr>`;
    });
    
    let photosHtml = '';
    if (r.photos && r.photos.length > 0) {
        r.photos.forEach(photo => {
            photosHtml += `
                <div class="col-3">
                    <img src="../${photo.photo_path}" class="img-fluid rounded border" style="cursor: pointer;" 
                         onclick="window.open('../${photo.photo_path}', '_blank')">
                </div>
            `;
        });
    }
    
    const statusClass = {
        'Pending': 'warning',
        'Approved': 'info',
        'Completed': 'success',
        'Rejected': 'danger'
    }[r.status] || 'secondary';
    
    const html = `
        <div class="row">
            <div class="col-md-8">
                <h6 class="text-primary">${r.return_number}</h6>
                <p><strong>PO Number:</strong> ${r.po_number}</p>
                <p><strong>Clinic:</strong> ${r.clinic_name}</p>
                <p><strong>Requested By:</strong> ${r.requested_by_name}</p>
                <p><strong>Date Requested:</strong> ${new Date(r.created_at).toLocaleString()}</p>
                <p><strong>Reason:</strong> ${r.reason}</p>
                <p><strong>Description:</strong> ${r.description || 'N/A'}</p>
                <p><strong>Contact:</strong> ${r.contact_person} - ${r.contact_number}</p>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                        <h5><span class="badge bg-${statusClass}">${r.status}</span></h5>
                        
                        <h6 class="card-subtitle mt-3 mb-2 text-muted">Refund Method</h6>
                        <p>${r.refund_method}</p>
                        
                        <h6 class="card-subtitle mt-3 mb-2 text-muted">Refund Amount</h6>
                        <h4 class="text-primary">₱${parseFloat(r.refund_amount).toFixed(2)}</h4>
                    </div>
                </div>
            </div>
        </div>
        
        <h6 class="mt-4">Items Returned:</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
        </div>
        
        ${photosHtml ? `
            <h6 class="mt-3">Evidence Photos:</h6>
            <div class="row g-2">
                ${photosHtml}
            </div>
        ` : ''}
    `;
    
    $('#returnDetailsContent').html(html);
    
    // Add action buttons to footer if pending
    if (r.status === 'Pending') {
        $('#returnDetailsFooter').html(`
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-success" onclick="openProcessModal(${r.id}, '${r.status}')">
                <i class="fas fa-check me-1"></i>Approve
            </button>
            <button type="button" class="btn btn-danger" onclick="openProcessModal(${r.id}, '${r.status}')">
                <i class="fas fa-times me-1"></i>Reject
            </button>
        `);
    } else {
        $('#returnDetailsFooter').html(`
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        `);
    }
}

// ============================================
// INVOICES MODULE FUNCTIONS
// ============================================

// Pagination variables
let unpaidPage = 1;
let paidPage = 1;
let historyPage = 1;

// Load invoice dashboard stats
function loadInvoiceStats() {
    $.ajax({
        url: '../api/supplier_invoices.php',
        method: 'GET',
        data: { action: 'get_stats', supplier_id: supplierId },
        success: function(response) {
            if (response.success) {
                // Update badges
                $('#totalUnpaidInvoices').text(response.data.unpaid_count || 0);
                $('#unpaidBadge').text(`Unpaid: ${response.data.unpaid_count || 0}`);
                $('#paidBadge').text(`Paid: ${response.data.paid_count || 0}`);
                $('#overdueBadge').text(`Overdue: ${response.data.overdue_count || 0}`);
                
                // Update summary cards
                $('#totalUnpaidAmount').text('₱' + parseFloat(response.data.unpaid_total || 0).toFixed(2));
                $('#totalPaidAmount').text('₱' + parseFloat(response.data.paid_total || 0).toFixed(2));
                $('#monthTotal').text('₱' + parseFloat(response.data.month_total || 0).toFixed(2));
                $('#onTimeRate').text((response.data.on_time_rate || 0) + '%');
                
                // Update tab counts
                $('#unpaidCount').text(response.data.unpaid_count || 0);
                $('#paidCount').text(response.data.paid_count || 0);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading invoice stats:', error);
        }
    });
}

// Load unpaid invoices
function loadUnpaidInvoices(page = 1) {
    unpaidPage = page;
    const filter = $('#unpaidFilter').val();
    const search = $('#unpaidSearch').val();
    
    $.ajax({
        url: '../api/supplier_invoices.php',
        method: 'GET',
        data: {
            action: 'get_invoices',
            supplier_id: supplierId,
            status: 'unpaid',
            filter: filter,
            search: search,
            page: page,
            limit: 10
        },
        success: function(response) {
            if (response.success) {
                displayUnpaidInvoices(response.data);
                updatePagination('unpaid', response.pagination);
            } else {
                $('#unpaidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading unpaid invoices:', error);
            $('#unpaidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// Display unpaid invoices
function displayUnpaidInvoices(invoices) {
    const tbody = $('#unpaidInvoicesTable');
    
    if (!invoices || invoices.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><br>No unpaid invoices</td></tr>');
        return;
    }
    
    let html = '';
    invoices.forEach(inv => {
        // Determine status badge
        let statusBadge = '';
        let statusClass = '';
        
        switch(inv.status) {
            case 'Pending':
                statusBadge = '<span class="badge bg-warning text-dark">Pending</span>';
                break;
            case 'Approved':
                statusBadge = '<span class="badge bg-info">Approved</span>';
                break;
            case 'Ready to Pay':
                statusBadge = '<span class="badge bg-primary">Ready to Pay</span>';
                break;
            case 'Overdue':
                statusBadge = '<span class="badge bg-danger">Overdue</span>';
                statusClass = 'table-danger';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + inv.status + '</span>';
        }
        
        // Check if overdue
        const dueDate = new Date(inv.due_date);
        const today = new Date();
        const isOverdue = inv.status !== 'Paid' && dueDate < today;
        
        if (isOverdue && inv.status !== 'Overdue') {
            statusBadge = '<span class="badge bg-danger">Overdue</span>';
        }
        
        html += `<tr class="${statusClass}">
            <td><strong>${inv.expense_code || inv.invoice_number || '-'}</strong></td>
            <td>${inv.po_number || '-'}</td>
            <td>${escapeHtml(inv.description || '')}</td>
            <td>${inv.expense_date ? new Date(inv.expense_date).toLocaleDateString() : '-'}</td>
            <td>${inv.due_date ? new Date(inv.due_date).toLocaleDateString() : '-'}</td>
            <td class="fw-bold text-danger">₱${parseFloat(inv.amount).toFixed(2)}</td>
            <td>${statusBadge}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewInvoiceDetails(${inv.id})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-success" onclick="markAsPaid(${inv.id})" title="Mark as Paid">
                    <i class="fas fa-check-circle"></i>
                </button>
                <button class="btn btn-sm btn-secondary" onclick="printInvoicePreview(${inv.id})" title="Print">
                    <i class="fas fa-print"></i>
                </button>
            </td>
        </tr>`;
    });
    
    tbody.html(html);
}

// Load paid invoices
function loadPaidInvoices(page = 1) {
    paidPage = page;
    const filter = $('#paidFilter').val();
    const search = $('#paidSearch').val();
    
    $.ajax({
        url: '../api/supplier_invoices.php',
        method: 'GET',
        data: {
            action: 'get_invoices',
            supplier_id: supplierId,
            status: 'paid',
            filter: filter,
            search: search,
            page: page,
            limit: 10
        },
        success: function(response) {
            if (response.success) {
                displayPaidInvoices(response.data);
                updatePagination('paid', response.pagination);
            } else {
                $('#paidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading paid invoices:', error);
            $('#paidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// Display paid invoices
function displayPaidInvoices(invoices) {
    const tbody = $('#paidInvoicesTable');
    
    if (!invoices || invoices.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-receipt fa-2x mb-2"></i><br>No paid invoices</td></tr>');
        return;
    }
    
    let html = '';
    invoices.forEach(inv => {
        html += `<tr>
            <td><strong>${inv.expense_code || inv.invoice_number || '-'}</strong></td>
            <td>${inv.po_number || '-'}</td>
            <td>${escapeHtml(inv.description || '')}</td>
            <td>${inv.payment_date ? new Date(inv.payment_date).toLocaleDateString() : '-'}</td>
            <td class="fw-bold text-success">₱${parseFloat(inv.amount).toFixed(2)}</td>
            <td>${inv.payment_method || '-'}</td>
            <td>${inv.payment_reference || '-'}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewInvoiceDetails(${inv.id})" title="View">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-secondary" onclick="printInvoicePreview(${inv.id})" title="Print">
                    <i class="fas fa-print"></i>
                </button>
                ${inv.receipt_path ? `
                    <button class="btn btn-sm btn-primary" onclick="viewReceipt('${inv.receipt_path}')" title="View Receipt">
                        <i class="fas fa-receipt"></i>
                    </button>
                ` : ''}
            </td>
        </tr>`;
    });
    
    tbody.html(html);
}

// Load payment history
function loadPaymentHistory(page = 1) {
    historyPage = page;
    const from = $('#historyFrom').val();
    const to = $('#historyTo').val();
    const search = $('#historySearch').val();
    
    $.ajax({
        url: '../api/supplier_invoices.php',
        method: 'GET',
        data: {
            action: 'get_payment_history',
            supplier_id: supplierId,
            from: from,
            to: to,
            search: search,
            page: page,
            limit: 15
        },
        success: function(response) {
            if (response.success) {
                displayPaymentHistory(response.data);
                $('#historyTotal').text('₱' + parseFloat(response.total || 0).toFixed(2));
            } else {
                $('#paymentHistoryTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading payment history:', error);
            $('#paymentHistoryTable').html('<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

// Display payment history
function displayPaymentHistory(payments) {
    const tbody = $('#paymentHistoryTable');
    
    if (!payments || payments.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4">No payment history found</td></tr>');
        return;
    }
    
    let html = '';
    payments.forEach(p => {
        html += `<tr>
            <td>${p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '-'}</td>
            <td><strong>${p.expense_code || p.invoice_number || '-'}</strong></td>
            <td>${p.po_number || '-'}</td>
            <td>${escapeHtml(p.description || '')}</td>
            <td class="fw-bold text-success">₱${parseFloat(p.amount).toFixed(2)}</td>
            <td>${p.payment_method || '-'}</td>
            <td>${p.payment_reference || '-'}</td>
            <td><span class="badge bg-success">Paid</span></td>
        </tr>`;
    });
    
    tbody.html(html);
}

// Update pagination
function updatePagination(type, pagination) {
    const infoId = '#' + type + 'PaginationInfo';
    const paginationId = '#' + type + 'Pagination';
    
    if (!pagination || pagination.total_pages <= 1) {
        $(infoId).html(`Showing ${pagination?.total || 0} items`);
        $(paginationId).html('');
        return;
    }
    
    $(infoId).html(`Page ${pagination.page} of ${pagination.total_pages} (${pagination.total} items)`);
    
    let pages = '';
    for (let i = 1; i <= pagination.total_pages; i++) {
        const activeClass = i === pagination.page ? 'active' : '';
        pages += `<li class="page-item ${activeClass}">
            <a class="page-link" href="#" onclick="load${type.charAt(0).toUpperCase() + type.slice(1)}Invoices(${i}); return false;">${i}</a>
        </li>`;
    }
    
    $(paginationId).html(pages);
}

// View invoice details
function viewInvoiceDetails(invoiceId) {
    $('#invoiceDetailsContent').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#invoiceDetailsModal').modal('show');
    
    $.ajax({
        url: '../api/supplier_invoices.php',
        method: 'GET',
        data: { action: 'get_invoice_details', id: invoiceId },
        success: function(response) {
            if (response.success) {
                displayInvoiceDetails(response.data);
            } else {
                $('#invoiceDetailsContent').html('<div class="alert alert-danger">Failed to load invoice details</div>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading invoice details:', error);
            $('#invoiceDetailsContent').html('<div class="alert alert-danger">Error loading details</div>');
        }
    });
}

// Display invoice details
function displayInvoiceDetails(inv) {
    const isPaid = inv.status === 'Paid';
    
    let itemsHtml = '';
    if (inv.items && inv.items.length > 0) {
        inv.items.forEach(item => {
            itemsHtml += `<tr>
                <td>${escapeHtml(item.item_name)}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td class="text-end">₱${parseFloat(item.total_price).toFixed(2)}</td>
            </tr>`;
        });
    }
    
    const statusClass = isPaid ? 'success' : (inv.status === 'Overdue' ? 'danger' : 'warning');
    
    const html = `
        <div class="row">
            <div class="col-md-7">
                <h6 class="text-primary">${inv.expense_code || inv.invoice_number || 'N/A'}</h6>
                <p><strong>PO Number:</strong> ${inv.po_number || 'N/A'}</p>
                <p><strong>PR Number:</strong> ${inv.pr_number || 'N/A'}</p>
                <p><strong>Description:</strong> ${escapeHtml(inv.description || '')}</p>
                <p><strong>Date:</strong> ${inv.expense_date ? new Date(inv.expense_date).toLocaleDateString() : 'N/A'}</p>
                <p><strong>Due Date:</strong> ${inv.due_date ? new Date(inv.due_date).toLocaleDateString() : 'N/A'}</p>
                <p><strong>Department:</strong> ${inv.department || 'N/A'}</p>
            </div>
            <div class="col-md-5">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                        <h5><span class="badge bg-${statusClass}">${inv.status}</span></h5>
                        
                        <h6 class="card-subtitle mt-3 mb-2 text-muted">Total Amount</h6>
                        <h3 class="text-${isPaid ? 'success' : 'danger'}">₱${parseFloat(inv.amount).toFixed(2)}</h3>
                        
                        ${isPaid ? `
                            <h6 class="card-subtitle mt-3 mb-2 text-muted">Payment Details</h6>
                            <p><strong>Date:</strong> ${inv.payment_date ? new Date(inv.payment_date).toLocaleDateString() : 'N/A'}</p>
                            <p><strong>Method:</strong> ${inv.payment_method || 'N/A'}</p>
                            <p><strong>Reference:</strong> ${inv.payment_reference || 'N/A'}</p>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
        
        ${itemsHtml ? `
            <h6 class="mt-4">Items:</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
            </div>
        ` : ''}
        
        ${inv.notes ? `
            <div class="mt-3">
                <strong>Notes:</strong>
                <p class="text-muted">${escapeHtml(inv.notes)}</p>
            </div>
        ` : ''}
    `;
    
    $('#invoiceDetailsContent').html(html);
    
    // Update footer buttons
    let footerHtml = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    
    if (!isPaid && inv.status !== 'Paid') {
        footerHtml += `
            <button type="button" class="btn btn-success" onclick="markAsPaid(${inv.id})">
                <i class="fas fa-check-circle me-1"></i>Mark as Paid
            </button>
        `;
    }
    
    footerHtml += `<button type="button" class="btn btn-primary" onclick="printInvoice(${inv.id})">
        <i class="fas fa-print me-1"></i>Print
    </button>`;
    
    $('#invoiceDetailsFooter').html(footerHtml);
}

// Mark invoice as paid
function markAsPaid(invoiceId) {
    Swal.fire({
        title: 'Mark as Paid',
        html: `
            <div class="text-start">
                <div class="mb-3">
                    <label class="form-label">Payment Date</label>
                    <input type="date" id="payment_date" class="form-control" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select id="payment_method" class="form-select">
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="GCash">GCash</option>
                        <option value="PayMaya">PayMaya</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference Number</label>
                    <input type="text" id="payment_reference" class="form-control" placeholder="e.g., Transaction ID">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea id="payment_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Mark as Paid',
        confirmButtonColor: '#28a745',
        preConfirm: () => {
            return {
                payment_date: $('#payment_date').val(),
                payment_method: $('#payment_method').val(),
                payment_reference: $('#payment_reference').val(),
                notes: $('#payment_notes').val()
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/supplier_invoices.php',
                method: 'POST',
                data: {
                    action: 'mark_paid',
                    invoice_id: invoiceId,
                    ...result.value
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', 'Invoice marked as paid', 'success').then(() => {
                            $('#invoiceDetailsModal').modal('hide');
                            loadInvoiceStats();
                            
                            // Reload current tab
                            if ($('#unpaid-tab').hasClass('active')) {
                                loadUnpaidInvoices();
                            } else if ($('#paid-tab').hasClass('active')) {
                                loadPaidInvoices();
                            } else {
                                loadPaymentHistory();
                            }
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                }
            });
        }
    });
}

// Print invoice
function printInvoice(invoiceId) {
    window.open(`../api/supplier_invoices.php?action=print_invoice&id=${invoiceId}`, '_blank');
}

function printInvoicePreview(invoiceId) {
    printInvoice(invoiceId);
}

// View receipt
function viewReceipt(path) {
    window.open('../' + path, '_blank');
}

// Export functions
function exportInvoices(type) {
    let url = `../api/supplier_invoices.php?action=export_invoices&supplier_id=${supplierId}&type=${type}`;
    
    if (type === 'unpaid') {
        url += '&filter=' + $('#unpaidFilter').val();
        url += '&search=' + encodeURIComponent($('#unpaidSearch').val());
    } else if (type === 'paid') {
        url += '&filter=' + $('#paidFilter').val();
        url += '&search=' + encodeURIComponent($('#paidSearch').val());
    }
    
    window.location.href = url;
}

function exportPaymentHistory() {
    const from = $('#historyFrom').val();
    const to = $('#historyTo').val();
    const search = $('#historySearch').val();
    
    window.location.href = `../api/supplier_invoices.php?action=export_history&supplier_id=${supplierId}&from=${from}&to=${to}&search=${encodeURIComponent(search)}`;
}

// ============================================
// INVOICE TAB SWITCHING FUNCTION
// ============================================
function switchInvoiceTab(tabName) {
    // Hide all tab panes
    document.getElementById('unpaid').style.display = 'none';
    document.getElementById('paid').style.display = 'none';
    document.getElementById('history').style.display = 'none';

    // Remove active from all tab buttons
    document.getElementById('unpaid-tab').classList.remove('active');
    document.getElementById('paid-tab').classList.remove('active');
    document.getElementById('history-tab').classList.remove('active');

    // Show selected tab pane and set button active
    document.getElementById(tabName).style.display = 'block';
    document.getElementById(tabName + '-tab').classList.add('active');

    // Load data for the selected tab
    if (tabName === 'unpaid') {
        loadUnpaidInvoices();
    } else if (tabName === 'paid') {
        loadPaidInvoices();
    } else if (tabName === 'history') {
        loadPaymentHistory();
    }
}

// ============================================
// RETURN APPROVAL — ENHANCED FLOW
// ============================================

function selectReturnMethod(method) {
    const isPickup = method === 'pickup';

    document.getElementById('rm_pickup').checked  = isPickup;
    document.getElementById('rm_dropoff').checked = !isPickup;

    const cardPickup  = document.getElementById('card_pickup');
    const cardDropoff = document.getElementById('card_dropoff');
    const fieldsPickup  = document.getElementById('fields_pickup');
    const fieldsDropoff = document.getElementById('fields_dropoff');

    if (isPickup) {
        cardPickup.style.cssText  = 'cursor:pointer;border:2px solid #0d9488!important;background:#e1f5ee;';
        cardDropoff.style.cssText = 'cursor:pointer;border:0.5px solid #dee2e6;background:;';
        fieldsPickup.style.display  = '';
        fieldsDropoff.style.display = 'none';
    } else {
        cardDropoff.style.cssText = 'cursor:pointer;border:2px solid #0d9488!important;background:#e1f5ee;';
        cardPickup.style.cssText  = 'cursor:pointer;border:0.5px solid #dee2e6;background:;';
        fieldsDropoff.style.display = '';
        fieldsPickup.style.display  = 'none';
    }
}

// Open approve modal (replaces old openProcessModal for 'approve')
function openApproveReturnModal(returnId, returnNumber, poNumber, amount) {
    document.getElementById('approve_return_id').value = returnId;
    document.getElementById('approve_return_summary').innerHTML =
        `<strong>${returnNumber}</strong> &nbsp;·&nbsp; ${poNumber} &nbsp;·&nbsp; ₱${parseFloat(amount).toFixed(2)}`;

    // Reset to Pickup default
    selectReturnMethod('pickup');
    document.getElementById('pickup_date').value =
        new Date(Date.now() + 2*864e5).toISOString().split('T')[0];

    $('#approveReturnModal').modal('show');
}

// Open reject modal
function openRejectReturnModal(returnId) {
    document.getElementById('reject_return_id').value = returnId;
    document.getElementById('reject_reason').value = '';
    $('#rejectReturnModal').modal('show');
}

// Submit approval with pickup/dropoff details
function submitApproveReturn() {
    const returnId = document.getElementById('approve_return_id').value;
    const method   = document.querySelector('input[name="return_method"]:checked').value;

    let extraData = { return_method: method };

    if (method === 'Pickup') {
        const date    = document.getElementById('pickup_date').value;
        const time    = document.getElementById('pickup_time').value;
        const address = document.getElementById('pickup_address').value;
        const notes   = document.getElementById('pickup_notes').value;

        if (!date) {
            Swal.fire('Required', 'Please set a pickup date.', 'warning');
            return;
        }
        extraData = { ...extraData,
            pickup_date: date, pickup_time: time,
            pickup_address: address, notes: notes
        };
    } else {
        const address = document.getElementById('dropoff_address').value;
        const contact = document.getElementById('dropoff_contact').value;
        const phone   = document.getElementById('dropoff_phone').value;
        const hours   = document.getElementById('dropoff_hours').value;
        const notes   = document.getElementById('dropoff_notes').value;

        if (!address || !contact || !phone) {
            Swal.fire('Required', 'Please fill in address, contact person, and number.', 'warning');
            return;
        }
        extraData = { ...extraData,
            dropoff_address: address, dropoff_contact: contact,
            dropoff_phone: phone, dropoff_hours: hours, notes: notes
        };
    }

    Swal.fire({
        title: 'Confirm approval?',
        html: `Method: <strong>${method}</strong><br>
               ${method === 'Pickup'
                   ? `Date: <strong>${extraData.pickup_date}</strong>, ${extraData.pickup_time}`
                   : `Address: <strong>${extraData.dropoff_address}</strong>`}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        confirmButtonText: 'Yes, approve'
    }).then(result => {
        if (!result.isConfirmed) return;

        $.ajax({
            url: '../api/supplier_returns.php',
            method: 'POST',
            data: {
                action: 'approve_return',
                return_id: returnId,
                ...extraData
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Return Approved!',
                        html: `Status updated to <strong>${method === 'Pickup' ? 'For Pickup' : 'For Drop-off'}</strong>.<br>
                               Clinic has been notified.`,
                        timer: 2500
                    }).then(() => {
                        $('#approveReturnModal').modal('hide');
                        loadReturns();
                    });
                } else {
                    Swal.fire('Error', response.error, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        });
    });
}

// Submit rejection
function submitRejectReturn() {
    const returnId = document.getElementById('reject_return_id').value;
    const reason   = document.getElementById('reject_reason').value.trim();

    if (!reason) {
        Swal.fire('Required', 'Please provide a reason for rejection.', 'warning');
        return;
    }

    $.ajax({
        url: '../api/supplier_returns.php',
        method: 'POST',
        data: { action: 'reject_return', return_id: returnId, reason: reason },
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'info', title: 'Return Rejected',
                    text: 'The clinic has been notified.', timer: 2000
                }).then(() => {
                    $('#rejectReturnModal').modal('hide');
                    loadReturns();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
}

// ============================================
// MANUAL TAB SWITCHING FUNCTION
// ============================================
function switchReturnTab(tabName) {
    // Hide all tab panes
    document.getElementById('pending').style.display = 'none';
    document.getElementById('approved').style.display = 'none';
    document.getElementById('completed').style.display = 'none';
    document.getElementById('rejected').style.display = 'none';
    document.getElementById('all').style.display = 'none';
    
    // Show selected tab pane
    document.getElementById(tabName).style.display = 'block';
    
    // Update active class on nav links
    const navLinks = document.querySelectorAll('#returnsTabs .nav-link');
    navLinks.forEach(link => {
        link.classList.remove('active');
    });
    
    // Find and activate the clicked link
    const activeLink = Array.from(navLinks).find(link => {
        return link.textContent.toLowerCase().includes(tabName);
    });
    if (activeLink) {
        activeLink.classList.add('active');
    }
}

// ============================================
// UPDATE showModule FUNCTION (i-add ang 'invoices' case)
// ============================================
const originalShowModule2 = showModule;
showModule = function(moduleId) {
    originalShowModule2(moduleId);
    
    setTimeout(() => {
        switch(moduleId) {
            case 'products_list':
                loadProducts();
                break;
            case 'products_price':
                loadPriceList();
                break;
            case 'products_stock':
                loadStockLevels();
                break;
            case 'returns_management':
                loadReturns();
                break;
            case 'invoices':
                loadInvoiceStats();
                // Always reset to unpaid tab when opening invoices module
                switchInvoiceTab('unpaid');
                break;
        }
    }, 100);
};


</script>
</body>
</html>