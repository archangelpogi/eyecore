<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Supply Chain module (Professional or Enterprise plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('supply_chain')) {
    header('Location: ../views/subscription.php');
    exit;
}

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check - MUST HAVE PURCHASE REQUESTS VIEW PERMISSION
if (!RBACHelper::hasPermission('purchase_requests_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Purchase Requests.</p>
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
$canView = RBACHelper::hasPermission('purchase_requests_view');
$canCreate = RBACHelper::hasPermission('purchase_requests_create');
$canEdit = RBACHelper::hasPermission('purchase_requests_edit');
$canDelete = RBACHelper::hasPermission('purchase_requests_delete');
$canApprove = RBACHelper::hasPermission('purchase_requests_approve');
$canReject = RBACHelper::hasPermission('purchase_requests_reject');



// Get filter parameters
$status = $_GET['status'] ?? 'all';
$priority = $_GET['priority'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Get stats safely
$stats = [
    'draft' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'on_order' => 0,
    'completed' => 0,
    'total' => 0
];

// Initialize suppliers array
$suppliers = [];

try {
    // Check if purchase_requests table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'purchase_requests'")->fetch();
    
    if ($tableExists) {
        // Get user's name for comparison
        $userQuery = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE id = ?");
        $userQuery->execute([$current_user_id]);
        $user = $userQuery->fetch();
        $user_full_name = $user['full_name'] ?? '';
        
        if ($user_role === 'SCM' || $user_role === 'User') {
            $statsQuery = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) as draft,
                    SUM(CASE WHEN status = 'Pending Approval' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'On Order' THEN 1 ELSE 0 END) as on_order,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                    COUNT(*) as total
                FROM purchase_requests 
                WHERE clinic_id = ? 
                AND (requested_by = ? OR requested_by LIKE ?)
            ");
            $statsQuery->execute([$clinic_id, $current_user_id, "%$user_full_name%"]);
        } else {
            $statsQuery = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN status = 'Pending Approval' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'On Order' THEN 1 ELSE 0 END) as on_order,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                    COUNT(*) as total
                FROM purchase_requests 
                WHERE clinic_id = ?
            ");
            $statsQuery->execute([$clinic_id]);
        }
        
        $statsRow = $statsQuery->fetch(PDO::FETCH_ASSOC);
        
        if ($user_role === 'SCM' || $user_role === 'User') {
            $stats = [
                'draft' => $statsRow['draft'] ?? 0,
                'pending' => $statsRow['pending'] ?? 0,
                'approved' => $statsRow['approved'] ?? 0,
                'rejected' => 0,
                'on_order' => $statsRow['on_order'] ?? 0,
                'completed' => $statsRow['completed'] ?? 0,
                'total' => $statsRow['total'] ?? 0
            ];
        } else {
            $stats = [
                'draft' => 0,
                'pending' => $statsRow['pending'] ?? 0,
                'approved' => $statsRow['approved'] ?? 0,
                'rejected' => $statsRow['rejected'] ?? 0,
                'on_order' => $statsRow['on_order'] ?? 0,
                'completed' => $statsRow['completed'] ?? 0,
                'total' => $statsRow['total'] ?? 0
            ];
        }
    }
    
    // Get suppliers
    $supplierQuery = "SELECT id, supplier_name, contact_person, email, mobile, city, payment_terms 
                      FROM suppliers 
                      WHERE status = 'Active' 
                      ORDER BY supplier_name ASC";
    $supplierStmt = $pdo->prepare($supplierQuery);
    $supplierStmt->execute();
    $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error in PR page: " . $e->getMessage());
}

$departments = ['SCM', 'Optical', 'Clinic', 'Admin', 'Pharmacy', 'Laboratory'];

// Convert suppliers to JSON for JavaScript
$suppliers_json = json_encode($suppliers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requests - EyeCore</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
    .supplier-product-info td {
        background-color: #f8f9fa !important;
        border-top: 1px dashed #dee2e6 !important;
        border-bottom: 1px solid #dee2e6 !important;
    }
    .supplier-product-info .badge {
        font-size: 0.7rem;
    }

    /* Minimal scrollbar */
    .modal-body::-webkit-scrollbar {
        width: 4px;
    }
    .modal-body::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 4px;
    }

    /* Item cards */
    .border.rounded-3 {
        border-color: #eee !important;
        transition: all 0.2s;
    }
    .border.rounded-3:hover {
        border-color: #28a745 !important;
    }
    
    /* RBAC Permission Indicator */
    .permission-badge {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: var(--bs-info);
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
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Purchase Requests</h1>
            <p class="text-muted">Manage purchase orders and reorder requests</p>
        </div>
        <?php if ($canCreate): ?>
        <a href="views/select_products.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>New Purchase Request
        </a>
        <?php endif; ?>
    </div>
    

    
    <!-- STATS CARDS -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Draft</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['draft']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-earmark fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['pending']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Approved</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['approved']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">On Order</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['on_order']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-truck fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Completed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['completed']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clipboard-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-2 col-md-4 col-6 mb-4">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">Total PRs</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Draft" <?php echo $status === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="Pending Approval" <?php echo $status === 'Pending Approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="Approved" <?php echo $status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="On Order" <?php echo $status === 'On Order' ? 'selected' : ''; ?>>On Order</option>
                        <option value="Completed" <?php echo $status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select class="form-select" id="priorityFilter">
                        <option value="">All Priorities</option>
                        <option value="Critical" <?php echo $priority === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                        <option value="High" <?php echo $priority === 'High' ? 'selected' : ''; ?>>High</option>
                        <option value="Medium" <?php echo $priority === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="Low" <?php echo $priority === 'Low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFromFilter" value="<?php echo $date_from; ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateToFilter" value="<?php echo $date_to; ?>">
                </div>
                
                <div class="col-md-8">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="searchFilter" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by PR number or item...">
                        <button class="btn btn-primary" type="button" onclick="applyFilters()">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </div>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                        <i class="bi bi-x-circle me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Purchase Requests</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="120">PR Number</th>
                            <th>Department</th>
                            <th width="100">Priority</th>
                            <th width="100">Items</th>
                            <th width="120">Total Amount</th>
                            <th width="100">Status</th>
                            <th width="100">Created</th>
                            <th width="150" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="prTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading purchase requests...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View PR Modal -->
<div class="modal fade" id="viewPRModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-3 bg-white" style="border-bottom: 2px solid #008080;">
                <h5 class="modal-title fw-light">
                    <i class="bi bi-eye me-2" style="color: #008080;"></i>
                    Purchase Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4" id="viewPRContent" style="max-height: 70vh; overflow-y: auto;">
                <!-- Dynamic content will be loaded here -->
            </div>
            
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm px-4" style="background-color: #008080; color: white;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- New PR Modal -->
<div class="modal fade" id="newPRModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-cart-plus me-2"></i>
                    <span id="modalTitle">Create Purchase Request</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="newPRForm">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <input type="hidden" name="pr_id" id="pr_id">
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Department *</label>
                            <select name="department" class="form-select" required>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority *</label>
                            <select name="priority" class="form-select" required>
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date Needed *</label>
                            <input type="date" name="needed_by" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Purpose/Reason *</label>
                        <textarea name="purpose" class="form-control" rows="2" placeholder="Why is this purchase needed?" required></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any special instructions or notes..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold">Requested Items</label>
                            <button type="button" class="btn btn-sm btn-success" onclick="openProductSelector()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th width="100">Quantity</th>
                                        <th width="120">Unit Price</th>
                                        <th width="120">Total</th>
                                        <th width="150">Supplier</th>
                                        <th width="50"></th>
                                    </tr>
                                </thead>
                                <tbody id="prItemsBody">
                                    <tr id="noItemsRow">
                                        <td colspan="6" class="text-center text-muted py-3">
                                            No items added. Click "Add Item" to start.
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="3" class="text-end fw-bold">GRAND TOTAL:</td>
                                        <td class="fw-bold text-primary" id="prTotal">₱0.00</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-warning" onclick="saveAsDraft()">
                        <i class="bi bi-save"></i> Save as Draft
                    </button>
                    <button type="button" class="btn btn-primary" onclick="submitPRForm('submit')">
                        <i class="bi bi-send"></i> Submit for Approval
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create PO Modal -->
<div class="modal fade" id="createPOModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <div>
                    <span class="badge bg-light text-dark mb-2">Purchase Order Inquiry</span>
                    <h5 class="modal-title fw-semibold" id="poPRNumber"></h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <form id="createPOForm">
                <div class="modal-body pt-2" style="max-height: 70vh; overflow-y: auto;">
                    <input type="hidden" name="pr_id" id="poPRId">
                    <input type="hidden" name="supplier_id" id="poSupplierId">
                    <input type="hidden" id="poItemsData" name="items_data">
                    
                    <div class="row g-2 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Department</small>
                                <span class="fw-medium" id="poDepartmentDisplay"></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Priority</small>
                                <span class="badge bg-info bg-opacity-10 text-info px-3 py-2" id="poPriorityDisplay"></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Requested By</small>
                                <span class="fw-medium" id="poRequestedBy"></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Approved By</small>
                                <span class="fw-medium text-success" id="poApprovedBy"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Approved Date</small>
                                <span id="poApprovedDate"></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-light rounded-3">
                                <small class="text-secondary d-block">Total Amount</small>
                                <span class="fw-bold text-primary" id="poTotalAmount">₱0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <small class="text-secondary d-block mb-1">Purpose</small>
                        <p class="bg-light p-3 rounded-3 mb-0" id="poPurposeDisplay"></p>
                    </div>
                    
                    <div class="mb-4">
                        <small class="text-secondary d-block mb-2">Supplier Information</small>
                        <div class="bg-light p-3 rounded-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-semibold mb-0" id="poSupplierName"></h6>
                                <span class="badge bg-light text-dark border" id="poPaymentTerms"></span>
                            </div>
                            <div class="row small">
                                <div class="col-md-6">
                                    <div class="d-flex mb-2">
                                        <i class="bi bi-person text-secondary me-2" style="width: 16px;"></i>
                                        <span id="poContactPerson"></span>
                                    </div>
                                    <div class="d-flex mb-2">
                                        <i class="bi bi-envelope text-secondary me-2" style="width: 16px;"></i>
                                        <span id="poSupplierEmail"></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex mb-2">
                                        <i class="bi bi-phone text-secondary me-2" style="width: 16px;"></i>
                                        <span id="poSupplierPhone"></span>
                                    </div>
                                    <div class="d-flex mb-2">
                                        <i class="bi bi-geo-alt text-secondary me-2" style="width: 16px;"></i>
                                        <span id="poSupplierAddress"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-secondary">Items to Order</small>
                            <span class="fw-semibold">Total: <span id="poGrandTotal">₱0.00</span></span>
                        </div>
                        <div class="bg-light rounded-3 p-2" style="max-height: 300px; overflow-y: auto;" id="poItemsContainer">
                            <div class="text-center text-muted py-4" id="poItemsLoading">
                                <div class="spinner-border spinner-border-sm text-secondary mb-2"></div>
                                <p class="small">Loading items...</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-secondary d-block mb-2">Order Details</small>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Order Date</label>
                                <input type="date" class="form-control form-control-sm" name="order_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Expected Delivery</label>
                                <input type="date" class="form-control form-control-sm" name="expected_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small mb-1">Shipping Address</label>
                            <textarea class="form-control form-control-sm" name="shipping_address" rows="2">Clinic Address: 123 Eye Care Street, Cavite</textarea>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small mb-1">Terms</label>
                            <textarea class="form-control form-control-sm" name="terms" rows="2" placeholder="Optional"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                    <?php if ($canApprove): ?>
                    <button type="submit" class="btn btn-success btn-sm px-4">
                        <i class="bi bi-check-lg me-1"></i>Create PO
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
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

// ============================================
// GLOBAL VARIABLES
// ============================================
let suppliersList = <?php echo $suppliers_json ?: '[]'; ?>;
let currentPRId = null;
let itemCounter = 0;
let inventoryItems = [];
let currentPageUrl = window.location.href;


// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('RBAC Permissions:', permissions);
    
    // Check if user has view permission
    if (!permissions.canView) {
        document.getElementById('prTableBody').innerHTML = `
            60;<td colspan="8" class="text-center py-5">
                <div class="alert alert-danger">
                    <i class="bi bi-shield-lock"></i> You don't have permission to view purchase requests.
                </div>
             </td>
        `;
        return;
    }
    
    initForms();
    loadPRTable();
    setupFilterListeners();
    console.log('Suppliers loaded:', suppliersList);
});

// ============================================
// FORM INITIALIZATION
// ============================================
function initForms() {
    // Set default needed_by date (7 days from now)
    const date = new Date();
    date.setDate(date.getDate() + 7);
    const neededByInput = document.querySelector('input[name="needed_by"]');
    if (neededByInput) {
        neededByInput.value = date.toISOString().split('T')[0];
    }
    
    // New PR form
    const newPRForm = document.getElementById('newPRForm');
    if (newPRForm) {
        newPRForm.addEventListener('submit', function(e) {
            e.preventDefault();
        });
    }
    
    // Create PO form
    const createPOForm = document.getElementById('createPOForm');
    if (createPOForm) {
        createPOForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (permissions.canApprove) {
                createPOFromForm();
            } else {
                Swal.fire('Access Denied', 'You don\'t have permission to create POs', 'error');
            }
        });
    }
    
    // Initialize modal reset
    const newPRModal = document.getElementById('newPRModal');
    if (newPRModal) {
        newPRModal.addEventListener('hidden.bs.modal', function() {
            resetNewPRForm();
        });
    }
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function setupFilterListeners() {
    const filters = ['statusFilter', 'priorityFilter', 'dateFromFilter', 'dateToFilter'];
    filters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', applyFilters);
        }
    });
    
    const searchFilter = document.getElementById('searchFilter');
    if (searchFilter) {
        searchFilter.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    }
}

function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const priority = document.getElementById('priorityFilter').value;
    const dateFrom = document.getElementById('dateFromFilter').value;
    const dateTo = document.getElementById('dateToFilter').value;
    const search = document.getElementById('searchFilter').value;
    
    const params = new URLSearchParams();
    if (status !== 'all') params.set('status', status);
    if (priority) params.set('priority', priority);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (search) params.set('search', search);
    
    const queryString = params.toString();
    const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.pushState({}, '', newUrl);
    
    loadPRTable();
}

function clearFilters() {
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('priorityFilter').value = '';
    document.getElementById('dateFromFilter').value = '';
    document.getElementById('dateToFilter').value = '';
    document.getElementById('searchFilter').value = '';
    
    window.history.pushState({}, '', window.location.pathname);
    loadPRTable();
}

// ============================================
// TABLE LOADING
// ============================================
function loadPRTable() {
    if (!permissions.canView) return;
    
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status') || 'all';
    const priority = params.get('priority') || '';
    const date_from = params.get('date_from') || '';
    const date_to = params.get('date_to') || '';
    const search = params.get('search') || '';
    
    let url = `api/purchase_request.php?action=get_prs&status=${status}`;
    if (priority) url += `&priority=${priority}`;
    if (date_from) url += `&date_from=${date_from}`;
    if (date_to) url += `&date_to=${date_to}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
    .then(response => response.json())
    .then(data => {
        const tbody = document.getElementById('prTableBody');
        
        if (data.success && data.data.length > 0) {
            let html = '';
            const currentUserRole = data.user_role || 'SCM';
            
            data.data.forEach(pr => {
                const createdDate = new Date(pr.created_at).toLocaleDateString();
                const totalAmount = parseFloat(pr.total_amount || 0).toFixed(2);
                
                const rejectionNote = pr.rejection_note ? 
                    `<br><small class="text-danger"><i class="bi bi-exclamation-circle"></i> ${escapeHtml(pr.rejection_note)}</small>` : '';
                
                html += `
                    <tr>
                        <td>
                            <strong>${pr.pr_number || 'N/A'}</strong>
                            <br><small class="text-muted">${createdDate}</small>
                        </td>
                        <td>${pr.department || 'N/A'}</td>
                        <td><span class="badge bg-${getPriorityColor(pr.priority)}">${pr.priority || 'Medium'}</span></td>
                        <td>${pr.item_count || 0} items</td>
                        <td class="fw-bold">₱${totalAmount}</td>
                        <td><span class="badge bg-${getStatusColor(pr.status)}">${pr.status || 'Draft'}</span>${rejectionNote}</td>
                        <td>${createdDate}</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-info" onclick="viewPR(${pr.id})" title="View"><i class="bi bi-eye"></i></button>
                                
                                ${(pr.status === 'Draft' || pr.status === 'Pending Approval' || pr.status === 'Supplier Rejected') && permissions.canEdit ? 
                                    `<button class="btn btn-outline-warning" onclick="editPR(${pr.id})" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>` : ''}
                                
                                ${(pr.status === 'Draft' || pr.status === 'Supplier Rejected') && permissions.canEdit ? 
                                    `<button class="btn btn-outline-primary" onclick="submitPR(${pr.id})" title="Submit for Approval">
                                        <i class="bi bi-send"></i>
                                    </button>` : ''}
                                
                                ${pr.status === 'Approved' && permissions.canApprove ? 
                                    `<button class="btn btn-outline-success" onclick="createPO(${pr.id})" title="Create PO">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </button>` : ''}
                                
                                ${(pr.status === 'Draft' || (pr.status === 'Pending Approval' && currentUserRole === 'SCM')) && permissions.canDelete ? 
                                    `<button class="btn btn-outline-danger" onclick="deletePR(${pr.id}, '${pr.pr_number || ''}')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox display-6"></i>
                            <p class="mt-2">No purchase requests found</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading PRs:', error);
        document.getElementById('prTableBody').innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading data. Please try again.
                </td>
            </tr>
        `;
    });
}

// ============================================
// PR FORM RESET
// ============================================
function resetNewPRForm() {
    document.getElementById('newPRForm').reset();
    const deptSelect = document.querySelector('select[name="department"]');
    if (deptSelect) deptSelect.value = 'SCM';
    const prioritySelect = document.querySelector('select[name="priority"]');
    if (prioritySelect) prioritySelect.value = 'Medium';

    const date = new Date();
    date.setDate(date.getDate() + 7);
    const neededByInput = document.querySelector('input[name="needed_by"]');
    if (neededByInput) neededByInput.value = date.toISOString().split('T')[0];

    const prItemsBody = document.getElementById('prItemsBody');
    if (prItemsBody) {
        prItemsBody.innerHTML = `
            <tr id="noItemsRow">
                <td colspan="6" class="text-center text-muted py-3">
                    No items added. Click "Add Item" to start.
                </td>
            </tr>
        `;
    }

    const prTotal = document.getElementById('prTotal');
    if (prTotal) prTotal.textContent = '₱0.00';
    currentPRId = null;
    itemCounter = 0;
}

// ============================================
// OPEN NEW PR MODAL
// ============================================
function openNewPRModal() {
    if (!permissions.canCreate) {
        Swal.fire('Access Denied', 'You don\'t have permission to create purchase requests', 'error');
        return;
    }
    resetNewPRForm();
    const modal = new bootstrap.Modal(document.getElementById('newPRModal'));
    modal.show();
}

// ============================================
// OPEN PRODUCT SELECTOR
// ============================================
function openProductSelector() {
    const prModal = bootstrap.Modal.getInstance(document.getElementById('newPRModal'));
    if (prModal) {
        prModal.hide();
    }
    
    Swal.fire({
        title: 'Select Products',
        html: `
            <div class="row mb-3">
                <div class="col-md-8">
                    <input type="text" id="searchQuery" class="form-control" 
                           placeholder="🔍 Search products...">
                </div>
                <div class="col-md-4">
                    <select id="categoryFilter" class="form-select">
                        <option value="">All Categories</option>
                        <option value="Frames">Frames</option>
                        <option value="Lenses">Lenses</option>
                        <option value="Contact Lenses">Contact Lenses</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
            </div>
            <div id="productList" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                    <p>Search for products or select a category</p>
                </div>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Close',
        didOpen: () => {
            let timeout = null;
            
            document.getElementById('searchQuery').addEventListener('keyup', function(e) {
                clearTimeout(timeout);
                const query = this.value.trim();
                const category = document.getElementById('categoryFilter').value;
                
                if(query.length >= 2 || category) {
                    timeout = setTimeout(() => {
                        loadProducts(query, category);
                    }, 500);
                }
            });
            
            document.getElementById('categoryFilter').addEventListener('change', function() {
                const query = document.getElementById('searchQuery').value.trim();
                loadProducts(query, this.value);
            });
        }
    }).then((result) => {
        if (result.dismiss) {
            const prModal = new bootstrap.Modal(document.getElementById('newPRModal'));
            prModal.show();
        }
    });
}

// ============================================
// LOAD PRODUCTS
// ============================================
function loadProducts(search = '', category = '') {
    const productList = document.getElementById('productList');
    if (!productList) return;
    
    productList.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div></div>';
    
    let url = 'api/supplier_products.php?action=search';
    if (search) {
        url += `&q=${encodeURIComponent(search)}`;
    } else if (category) {
        url += `&category=${encodeURIComponent(category)}`;
    } else {
        url += '&featured=1';
    }
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                displayProductGrid(data.data);
            } else {
                productList.innerHTML = `
                    <div class="alert alert-info text-center">
                        No products found
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error('Error:', err);
            productList.innerHTML = '<div class="alert alert-danger">Error loading products</div>';
        });
}

// ============================================
// DISPLAY PRODUCT GRID
// ============================================
function displayProductGrid(products) {
    const productList = document.getElementById('productList');
    if (!productList) return;
    
    let html = '<div class="row g-3">';
    
    products.forEach(prod => {
        const isAvailable = prod.stock >= prod.min_order_qty;
        const stockBadge = !isAvailable ? 
            '<span class="badge bg-warning">Insufficient Stock</span>' :
            (prod.stock > 0 ? '<span class="badge bg-success">In Stock</span>' : '<span class="badge bg-danger">Out of Stock</span>');
        
        const photoHtml = prod.photo_path ? 
            `<img src="${prod.photo_path}" class="card-img-top" style="height: 150px; object-fit: cover;">` : 
            `<div class="bg-light d-flex align-items-center justify-content-center" style="height: 150px;">
                <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
            </div>`;
        
        html += `
            <div class="col-md-6">
                <div class="card h-100 ${!isAvailable ? 'opacity-50' : ''}">
                    ${photoHtml}
                    <div class="card-body">
                        <h6 class="card-title">${escapeHtml(prod.product_name)}</h6>
                        <p class="card-text small">
                            <span class="text-primary fw-bold">₱${parseFloat(prod.cost_price).toFixed(2)}</span><br>
                            <span>Supplier: ${escapeHtml(prod.supplier_name)}</span><br>
                            <span>Stock: ${prod.stock} | Min: ${prod.min_order_qty}</span><br>
                            <span>Lead: ${prod.lead_time_days || 3} days</span>
                        </p>
                        ${stockBadge}
                    </div>
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <input type="number" class="form-control form-control-sm" 
                                   style="width: 80px;" id="qty_${prod.id}" 
                                   value="${prod.min_order_qty || 1}" 
                                   min="${prod.min_order_qty || 1}" max="${prod.stock}">
                            <button class="btn btn-sm btn-primary" 
                                    onclick="addToCart(${prod.id}, ${prod.supplier_id}, '${escapeHtml(prod.supplier_name)}', '${escapeHtml(prod.product_name)}', ${prod.cost_price}, ${prod.stock}, ${prod.min_order_qty || 1}, ${prod.lead_time_days || 3}, '${prod.photo_path || ''}')"
                                    ${!isAvailable ? 'disabled' : ''}>
                                <i class="bi bi-cart-plus"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    productList.innerHTML = html;
}

// ============================================
// ADD TO CART / ADD PR ITEM
// ============================================
function addToCart(productId, supplierId, supplierName, productName, price, stock, minOrder, leadTime, photoPath) {
    const qtyInput = document.getElementById(`qty_${productId}`);
    const quantity = parseInt(qtyInput.value);
    
    if (quantity < minOrder) {
        Swal.fire('Error', `Minimum order is ${minOrder}`, 'error');
        return;
    }
    
    if (quantity > stock) {
        Swal.fire('Error', `Only ${stock} available`, 'error');
        return;
    }
    
    const itemData = {
        supplier_product_id: productId,
        supplier_id: supplierId,
        supplier_name: supplierName,
        item_name: productName,
        quantity: quantity,
        unit_price: price,
        photo_path: photoPath,
        min_order: minOrder,
        lead_time: leadTime,
        stock: stock
    };
    
    Swal.close();
    
    setTimeout(() => {
        const prModal = new bootstrap.Modal(document.getElementById('newPRModal'));
        prModal.show();
        addPRItem(itemData, 'search');
    }, 300);
}

function addPRItem(item = null, mode = 'category') {
    console.log('Adding PR item:', item, 'Mode:', mode);
    
    const tbody = document.getElementById('prItemsBody');
    if (!tbody) return;
    
    const itemCount = document.querySelectorAll('tr[id^="itemRow_"]').length;
    const newId = 'new_' + Date.now() + '_' + itemCount;
    
    const noItemsRow = document.getElementById('noItemsRow');
    if (noItemsRow) noItemsRow.remove();
    
    let itemSelectionHtml = '';
    
    // Handle different modes
    if (mode === 'category' && !item) {
        itemSelectionHtml = `
            <div class="flex-grow-1">
                <select class="form-select form-select-sm category-select" 
                        data-row="${newId}" id="category_${newId}" style="width: 100%;">
                    <option value="">-- Select Category --</option>
                    <option value="Frames">Frames</option>
                    <option value="Lenses">Lenses</option>
                    <option value="Contact Lenses">Contact Lenses</option>
                    <option value="Accessories">Accessories</option>
                    <option value="Others">Others</option>
                </select>
                <div id="supplier_products_${newId}" style="display: none; margin-top: 10px;">
                    <select class="form-select form-select-sm product-select" 
                            data-row="${newId}" id="product_${newId}" style="width: 100%;">
                        <option value="">-- Select Supplier Product --</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="items[${newId}][item_name]" id="item_name_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_id]" id="supplier_id_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_name]" id="supplier_name_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_product_id]" id="supplier_product_id_${newId}" value="">
        `;
    } else {
        const photoHtml = item?.photo_path ? 
            `<img src="${item.photo_path}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px; margin-right: 8px;">` : 
            '';
        
        const isEditMode = (mode === 'edit');
        
        itemSelectionHtml = `
            <div class="d-flex align-items-center">
                ${photoHtml}
                <div>
                    <strong>${escapeHtml(item?.item_name || '')}</strong>
                    <input type="hidden" name="items[${newId}][item_name]" value="${escapeHtml(item?.item_name || '')}">
                    <input type="hidden" name="items[${newId}][supplier_product_id]" value="${item?.supplier_product_id || ''}">
                    <input type="hidden" name="items[${newId}][description]" value="${escapeHtml(item?.description || '')}">
                    ${isEditMode ? `
                        <input type="hidden" name="items[${newId}][supplier_id]" value="${item?.supplier_id || ''}">
                        <input type="hidden" name="items[${newId}][supplier_name]" value="${escapeHtml(item?.supplier_name || '')}">
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    const newRow = `
        <tr id="itemRow_${newId}">
            <td>${itemSelectionHtml}</td>
            <td>
                <input type="number" class="form-control form-control-sm" name="items[${newId}][quantity]" 
                       id="quantity_${newId}" value="${item?.quantity || 1}" min="${item?.min_order || 1}" 
                       onchange="calculatePRItemTotal('${newId}')" required>
                <small class="text-muted" id="min_order_${newId}">${item?.min_order ? 'Min: '+item.min_order : ''}</small>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light">₱</span>
                    <input type="number" step="0.01" class="form-control form-control-sm bg-light" 
                           name="items[${newId}][unit_price]" id="unit_price_${newId}" value="${item?.unit_price || 0}" 
                           ${item?.unit_price ? 'readonly style="background-color: #f8f9fa;"' : 'disabled'}>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light">₱</span>
                    <input type="text" class="form-control form-control-sm bg-light fw-bold text-primary" 
                           name="items[${newId}][total_price]" id="total_price_${newId}" readonly 
                           value="${((item?.quantity || 1) * (item?.unit_price || 0)).toFixed(2)}"
                           style="background-color: #f8f9fa;" disabled>
                </div>
            </td>
            <td>
                <div class="small p-1 bg-light rounded" id="supplier_display_${newId}">
                    ${item?.supplier_name ? '<span class="badge bg-info">' + escapeHtml(item.supplier_name) + '</span>' : '<span class="text-muted">Select product first</span>'}
                </div>
                <input type="hidden" name="items[${newId}][supplier_id]" id="supplier_id_${newId}" value="${item?.supplier_id || ''}">
                <input type="hidden" name="items[${newId}][supplier_name]" id="supplier_name_${newId}" value="${escapeHtml(item?.supplier_name || '')}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-danger" onclick="removePRItem('${newId}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    tbody.insertAdjacentHTML('beforeend', newRow);
    
    if (mode === 'category' && !item) {
        setTimeout(() => {
            const categorySelect = document.getElementById(`category_${newId}`);
            if (categorySelect) {
                categorySelect.addEventListener('change', function() {
                    loadSupplierProductsByCategory(this, newId);
                });
            }
        }, 100);
    }
    
    calculatePRTotal();
}

function loadSupplierProductsByCategory(select, rowId) {
    const category = select.value;
    const productsDiv = document.getElementById(`supplier_products_${rowId}`);
    
    if (!category) {
        if (productsDiv) productsDiv.style.display = 'none';
        return;
    }
    
    if (productsDiv) productsDiv.style.display = 'block';
    
    const productSelect = document.getElementById(`product_${rowId}`);
    if (productSelect) {
        productSelect.innerHTML = '<option value="">Loading products...</option>';
    }
    
    fetch(`api/supplier_products.php?action=get_by_category&category=${encodeURIComponent(category)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let options = '<option value="">-- Select Supplier Product --</option>';
                
                data.data.forEach(p => {
                    const stockStatus = p.stock > 0 ? 'In Stock' : 'Out of Stock';
                    options += `<option value="${p.id}" 
                                data-supplier-id="${p.supplier_id}"
                                data-supplier-name="${escapeHtml(p.supplier_name)}"
                                data-price="${p.cost_price}"
                                data-stock="${p.stock}"
                                data-min-order="${p.min_order_qty || 1}"
                                data-lead-time="${p.lead_time_days || 3}"
                                data-photo="${p.photo_path || ''}">
                                ${escapeHtml(p.product_name)} - ₱${parseFloat(p.cost_price).toFixed(2)} (${stockStatus}) - ${escapeHtml(p.supplier_name)}
                            </option>`;
                });
                
                if (productSelect) {
                    productSelect.innerHTML = options;
                    productSelect.addEventListener('change', function() {
                        selectSupplierProduct(this, rowId);
                    });
                }
            } else {
                if (productSelect) {
                    productSelect.innerHTML = '<option value="">No products found in this category</option>';
                }
            }
        })
        .catch(err => {
            console.error('Error loading products:', err);
            if (productSelect) {
                productSelect.innerHTML = '<option value="">Error loading products</option>';
            }
        });
}

function selectSupplierProduct(select, rowId) {
    const selectedOption = select.options[select.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;
    
    const productId = selectedOption.value;
    const supplierId = selectedOption.dataset.supplierId;
    const supplierName = selectedOption.dataset.supplierName;
    const unitPrice = selectedOption.dataset.price;
    const minOrder = selectedOption.dataset.minOrder;
    const photoPath = selectedOption.dataset.photo;
    const itemName = selectedOption.text.split(' - ')[0];
    
    const itemNameInput = document.getElementById(`item_name_${rowId}`);
    if (itemNameInput) itemNameInput.value = itemName;
    
    const supplierIdInput = document.getElementById(`supplier_id_${rowId}`);
    const supplierNameInput = document.getElementById(`supplier_name_${rowId}`);
    const supplierDisplay = document.getElementById(`supplier_display_${rowId}`);
    const supplierProductIdInput = document.getElementById(`supplier_product_id_${rowId}`);
    
    if (supplierIdInput) supplierIdInput.value = supplierId;
    if (supplierNameInput) supplierNameInput.value = supplierName;
    if (supplierProductIdInput) supplierProductIdInput.value = productId;
    if (supplierDisplay) supplierDisplay.innerHTML = `<span class="badge bg-info">${escapeHtml(supplierName)}</span>`;
    
    const unitPriceInput = document.getElementById(`unit_price_${rowId}`);
    if (unitPriceInput) {
        unitPriceInput.value = unitPrice;
        unitPriceInput.disabled = false;
        unitPriceInput.readOnly = true;
    }
    
    const minOrderDisplay = document.getElementById(`min_order_${rowId}`);
    if (minOrderDisplay) minOrderDisplay.textContent = `Min: ${minOrder}`;
    
    const quantityInput = document.getElementById(`quantity_${rowId}`);
    if (quantityInput) {
        quantityInput.min = minOrder;
        if (parseInt(quantityInput.value) < minOrder) {
            quantityInput.value = minOrder;
        }
    }
    
    calculatePRItemTotal(rowId);
}

// ============================================
// CALCULATION FUNCTIONS
// ============================================
function calculatePRItemTotal(rowId) {
    const quantity = parseFloat(document.getElementById(`quantity_${rowId}`)?.value) || 0;
    const unitPrice = parseFloat(document.getElementById(`unit_price_${rowId}`)?.value) || 0;
    const total = quantity * unitPrice;
    
    const totalInput = document.getElementById(`total_price_${rowId}`);
    if (totalInput) totalInput.value = total.toFixed(2);
    calculatePRTotal();
}

function calculatePRTotal() {
    let total = 0;
    document.querySelectorAll('input[id^="total_price_"]').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    const prTotal = document.getElementById('prTotal');
    if (prTotal) prTotal.textContent = '₱' + total.toFixed(2);
}

function removePRItem(rowId) {
    const row = document.getElementById(`itemRow_${rowId}`);
    if (row) {
        row.remove();
        calculatePRTotal();
        
        if (document.querySelectorAll('tr[id^="itemRow_"]').length === 0) {
            const prItemsBody = document.getElementById('prItemsBody');
            if (prItemsBody) {
                prItemsBody.innerHTML = `
                    <tr id="noItemsRow">
                        <td colspan="6" class="text-center text-muted py-3">
                            No items added. Click "Add Item" to start.
                        </td>
                    </tr>
                `;
            }
        }
    }
}

// ============================================
// SUBMIT PR FORM
// ============================================
function submitPRForm(status = 'submit') {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to edit purchase requests', 'error');
        return;
    }
    
    const form = document.getElementById('newPRForm');
    if (!form) return;
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    
    const items = [];
    document.querySelectorAll('tr[id^="itemRow_"]').forEach(row => {
        const rowId = row.id.replace('itemRow_', '');
        
        const itemName = document.querySelector(`input[name="items[${rowId}][item_name]"]`)?.value;
        const supplierId = document.querySelector(`input[name="items[${rowId}][supplier_id]"]`)?.value;
        const supplierName = document.querySelector(`input[name="items[${rowId}][supplier_name]"]`)?.value;
        const quantity = document.getElementById(`quantity_${rowId}`)?.value;
        const unitPrice = document.getElementById(`unit_price_${rowId}`)?.value;
        const totalPrice = document.getElementById(`total_price_${rowId}`)?.value;
        
        if (!itemName || !supplierId) {
            return;
        }
        
        items.push({
            item_name: itemName,
            description: document.getElementById(`description_${rowId}`)?.value || '',
            current_stock: 0,
            quantity: quantity,
            unit_price: unitPrice,
            total_price: totalPrice,
            supplier_id: supplierId,
            supplier_name: supplierName
        });
    });
    
    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }
    
    const action = currentPRId ? 'update' : 'create';
    formData.append('action', action);
    if (currentPRId) formData.append('pr_id', currentPRId);
    formData.append('status', status === 'draft' ? 'Draft' : 'Pending Approval');
    formData.append('items', JSON.stringify(items));
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('newPRModal'));
                if (modal) modal.hide();
                loadPRTable();
            });
        } else {
            Swal.fire('Error', data.error || 'An error occurred', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred', 'error');
    });
}

function saveAsDraft() {
    submitPRForm('draft');
}

// ============================================
// VIEW PR
// ============================================
function viewPR(prId) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to view purchase requests', 'error');
        return;
    }
    
    fetch(`api/purchase_request.php?action=get&id=${prId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const pr = data.data;
            let itemsHtml = '';
            
            if (pr.items && pr.items.length > 0) {
                itemsHtml = `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                                <th>Supplier</th>
                            </thead>
                            <tbody>`;
                
                pr.items.forEach(item => {
                    const total = item.total_price || (item.quantity * item.unit_price);
                    
                    itemsHtml += `——
                        <td>
                            <strong>${escapeHtml(item.item_name)}</strong>
                            ${item.description ? `<br><small class="text-muted">${escapeHtml(item.description)}</small>` : ''}
                        </td>
                        <td class="text-center">${item.quantity}</td>
                        <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td class="text-end fw-bold">₱${parseFloat(total).toFixed(2)}</td>
                        <td>
                            <span class="badge bg-info">${escapeHtml(item.supplier_name || 'Not specified')}</span>
                        </td>
                    </tr>`;
                });
                
                itemsHtml += '</tbody></table></div>';
            }
            
            // Rejection History Section
            let rejectionHtml = '';
            if (pr.rejection_notes && pr.rejection_notes.length > 0) {
                rejectionHtml = `
                <div class="mt-4">
                    <h6 class="text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Rejection History:
                    </h6>
                    <div class="border-start border-danger border-3 ps-3">`;
                
                pr.rejection_notes.forEach((note, index) => {
                    const date = new Date(note.date).toLocaleString();
                    const icon = note.type === 'supplier' ? 'bi-shop' : 'bi-cash-stack';
                    const color = note.type === 'supplier' ? 'danger' : 'warning';
                    
                    rejectionHtml += `
                        <div class="mb-3">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-${color} me-2">
                                    <i class="bi ${icon}"></i> ${note.type === 'supplier' ? 'Supplier' : 'Finance'}
                                </span>
                                <small class="text-muted">${date}</small>
                            </div>
                            <p class="mb-1">${escapeHtml(note.message)}</p>
                            ${note.po_number ? `<small class="text-muted">PO #: ${note.po_number}</small>` : ''}
                            ${index < pr.rejection_notes.length - 1 ? '<hr class="my-2">' : ''}
                        </div>`;
                });
                
                rejectionHtml += '</div></div>';
            }
            
            // Approval Notes Section
            let approvalHtml = '';
            if (pr.approval_notes) {
                approvalHtml = `
                <div class="mt-3">
                    <strong>Finance Notes:</strong>
                    <div class="border p-2 rounded bg-light">
                        <i class="bi bi-chat-quote me-1"></i>
                        ${escapeHtml(pr.approval_notes)}
                    </div>
                </div>`;
            }
            
            const html = `
                <div class="row mb-3">
                    <div class="col-md-8">
                        <h5 class="text-primary">${escapeHtml(pr.pr_number)}</h5>
                        <p><strong>Department:</strong> ${escapeHtml(pr.department || 'N/A')}</p>
                        <p><strong>Purpose:</strong> ${escapeHtml(pr.purpose || 'N/A')}</p>
                        <p><strong>Requested by:</strong> ${escapeHtml(pr.requested_by_name || 'Unknown')}</p>
                        <p><strong>Date Needed:</strong> ${pr.needed_by ? new Date(pr.needed_by).toLocaleDateString() : 'N/A'}</p>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                                <h5><span class="badge bg-${getStatusColor(pr.status)}">${pr.status || 'Draft'}</span></h5>
                                
                                <h6 class="card-subtitle mt-3 mb-2 text-muted">Priority</h6>
                                <h5><span class="badge bg-${getPriorityColor(pr.priority)}">${pr.priority || 'Medium'}</span></h5>
                                
                                <h6 class="card-subtitle mt-3 mb-2 text-muted">Total Amount</h6>
                                <h4 class="text-primary">₱${parseFloat(pr.total_amount || 0).toFixed(2)}</h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h6 class="mt-4 mb-2">Requested Items:</h6>
                ${itemsHtml || '<p class="text-muted">No items found</p>'}
                
                ${approvalHtml}
                
                ${pr.notes ? `
                <div class="mt-3">
                    <strong>Requestor Notes:</strong>
                    <div class="border p-2 rounded bg-light">
                        <i class="bi bi-chat-dots me-1"></i>
                        ${escapeHtml(pr.notes)}
                    </div>
                </div>` : ''}
                
                ${rejectionHtml}
            `;
            
            const viewContent = document.getElementById('viewPRContent');
            if (viewContent) viewContent.innerHTML = html;
            const viewModal = new bootstrap.Modal(document.getElementById('viewPRModal'));
            viewModal.show();
        } else {
            Swal.fire('Error', data.error || 'Failed to load PR details', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to load PR details', 'error');
    });
}

// ============================================
// EDIT PR - FIXED VERSION (No inventory dependency)
// ============================================
function editPR(prId) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to edit purchase requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Loading PR data...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/purchase_request.php?action=get&id=${prId}`)
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const pr = data.data;
                
                if (!['Draft', 'Pending Approval'].includes(pr.status)) {
                    Swal.fire('Error', 'This PR cannot be edited because it is already ' + pr.status, 'error');
                    return;
                }
                
                if (!permissions.canApprove && !permissions.canReject) {
                    if (pr.requested_by != currentUserId) {
                        Swal.fire('Error', 'You can only edit your own PRs', 'error');
                        return;
                    }
                }
                
                const deptSelect = document.querySelector('select[name="department"]');
                if (deptSelect) deptSelect.value = pr.department;
                
                const prioritySelect = document.querySelector('select[name="priority"]');
                if (prioritySelect) prioritySelect.value = pr.priority;
                
                const neededByInput = document.querySelector('input[name="needed_by"]');
                if (neededByInput) neededByInput.value = pr.needed_by;
                
                const purposeTextarea = document.querySelector('textarea[name="purpose"]');
                if (purposeTextarea) purposeTextarea.value = pr.purpose;
                
                const notesTextarea = document.querySelector('textarea[name="notes"]');
                if (notesTextarea) notesTextarea.value = pr.notes || '';
                
                const prItemsBody = document.getElementById('prItemsBody');
                if (prItemsBody) prItemsBody.innerHTML = '';
                
                if (pr.items && pr.items.length > 0) {
                    pr.items.forEach((item) => {
                        const itemData = {
                            supplier_product_id: item.supplier_product_id || null,
                            supplier_id: item.supplier_id || null,
                            supplier_name: item.supplier_name || '',
                            item_name: item.item_name,
                            description: item.description || '',
                            quantity: item.quantity,
                            unit_price: item.unit_price,
                            total_price: item.total_price,
                            photo_path: item.photo_path || null,
                            min_order: item.min_order || 1,
                            lead_time: item.lead_time || 3
                        };
                        
                        addPRItem(itemData, 'edit');
                    });
                } else {
                    if (prItemsBody) {
                        prItemsBody.innerHTML = `
                            <tr id="noItemsRow">
                                <td colspan="6" class="text-center text-muted py-3">
                                    No items found. Click "Add Item" to add items.
                                  </td>
                            </tr>
                        `;
                    }
                }
                
                currentPRId = prId;
                
                const modalTitle = document.getElementById('modalTitle');
                if (modalTitle) modalTitle.textContent = 'Edit Purchase Request: ' + (pr.pr_number || '');
                
                const modal = new bootstrap.Modal(document.getElementById('newPRModal'));
                modal.show();
                
            } else {
                Swal.fire('Error', data.error || 'Failed to load PR details', 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load purchase request: ' + error.message, 'error');
        });
}

// ============================================
// SUBMIT/APPROVE/REJECT/DELETE PR
// ============================================
function submitPR(prId) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to submit purchase requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Submit for Approval',
        text: 'Are you sure?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Submit'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'submit');
            formData.append('pr_id', prId);
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                       loadPRTable();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

function approvePR(prId) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve purchase requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Approve PR?',
        input: 'textarea',
        inputLabel: 'Approval Notes (optional)',
        inputPlaceholder: 'Enter any notes...',
        showCancelButton: true,
        confirmButtonText: 'Approve',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'approve');
            formData.append('pr_id', prId);
            formData.append('notes', result.value || '');
            
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                        loadPRTable();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

function rejectPR(prId) {
    if (!permissions.canReject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject purchase requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Reject PR?',
        input: 'textarea',
        inputLabel: 'Reason for rejection *',
        inputPlaceholder: 'Enter reason for rejection...',
        inputValidator: (value) => {
            if (!value) {
                return 'Please provide a reason for rejection';
            }
        },
        showCancelButton: true,
        confirmButtonText: 'Reject',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'reject');
            formData.append('pr_id', prId);
            formData.append('reason', result.value);
            
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                        loadPRTable();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

function deletePR(prId, prNumber) {
    if (!permissions.canDelete) {
        Swal.fire('Access Denied', 'You don\'t have permission to delete purchase requests', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Delete PR?',
        html: `Delete <strong>${escapeHtml(prNumber)}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('pr_id', prId);
            
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success').then(() => {
                        loadPRTable();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

// ============================================
// CREATE PO FUNCTIONS
// ============================================
function createPO(prId) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to create POs', 'error');
        return;
    }
    
    const modalElement = document.getElementById('createPOModal');
    if (!modalElement) {
        Swal.fire('Error', 'Modal not found', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Loading Approved PR Details...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/purchase_request.php?action=get_approved_pr_details&id=${prId}`)
    .then(response => response.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            const pr = data.data;
            
            const poPRId = document.getElementById('poPRId');
            if (poPRId) poPRId.value = prId;
            const poPRNumber = document.getElementById('poPRNumber');
            if (poPRNumber) poPRNumber.textContent = pr.pr_number || 'N/A';
            const poDepartmentDisplay = document.getElementById('poDepartmentDisplay');
            if (poDepartmentDisplay) poDepartmentDisplay.textContent = pr.department || 'N/A';
            const poPriorityDisplay = document.getElementById('poPriorityDisplay');
            if (poPriorityDisplay) poPriorityDisplay.textContent = pr.priority || 'Medium';
            const poPurposeDisplay = document.getElementById('poPurposeDisplay');
            if (poPurposeDisplay) poPurposeDisplay.textContent = pr.purpose || 'N/A';
            const poRequestedBy = document.getElementById('poRequestedBy');
            if (poRequestedBy) poRequestedBy.textContent = pr.requested_by_name || 'Unknown';
            const poApprovedBy = document.getElementById('poApprovedBy');
            if (poApprovedBy) poApprovedBy.textContent = pr.approved_by_name || 'N/A';
            const poApprovedDate = document.getElementById('poApprovedDate');
            if (poApprovedDate) poApprovedDate.textContent = pr.approved_at ? new Date(pr.approved_at).toLocaleDateString() : 'N/A';
            
            const totalAmount = parseFloat(pr.total_amount || 0).toFixed(2);
            const poTotalAmount = document.getElementById('poTotalAmount');
            if (poTotalAmount) poTotalAmount.textContent = '₱' + totalAmount;
            const poGrandTotal = document.getElementById('poGrandTotal');
            if (poGrandTotal) poGrandTotal.textContent = '₱' + totalAmount;
            
            if (pr.supplier_info) {
                const poSupplierId = document.getElementById('poSupplierId');
                if (poSupplierId) poSupplierId.value = pr.supplier_info.id || '';
                const poSupplierName = document.getElementById('poSupplierName');
                if (poSupplierName) poSupplierName.textContent = pr.supplier_info.name || 'No supplier assigned';
                const poContactPerson = document.getElementById('poContactPerson');
                if (poContactPerson) poContactPerson.textContent = pr.supplier_info.contact_person || 'No contact person';
                const poSupplierEmail = document.getElementById('poSupplierEmail');
                if (poSupplierEmail) poSupplierEmail.textContent = pr.supplier_info.email || 'No email';
                const poSupplierPhone = document.getElementById('poSupplierPhone');
                if (poSupplierPhone) poSupplierPhone.textContent = pr.supplier_info.phone || pr.supplier_info.mobile || 'No phone';
                const poSupplierAddress = document.getElementById('poSupplierAddress');
                if (poSupplierAddress) poSupplierAddress.textContent = pr.supplier_info.address || 'No address provided';
                const poPaymentTerms = document.getElementById('poPaymentTerms');
                if (poPaymentTerms) poPaymentTerms.textContent = pr.supplier_info.payment_terms || 'Net 30';
            } else {
                const poSupplierName = document.getElementById('poSupplierName');
                if (poSupplierName) poSupplierName.textContent = 'No supplier assigned';
                const poContactPerson = document.getElementById('poContactPerson');
                if (poContactPerson) poContactPerson.textContent = 'N/A';
                const poSupplierEmail = document.getElementById('poSupplierEmail');
                if (poSupplierEmail) poSupplierEmail.textContent = 'N/A';
                const poSupplierPhone = document.getElementById('poSupplierPhone');
                if (poSupplierPhone) poSupplierPhone.textContent = 'N/A';
                const poSupplierAddress = document.getElementById('poSupplierAddress');
                if (poSupplierAddress) poSupplierAddress.textContent = 'No address provided';
                const poPaymentTerms = document.getElementById('poPaymentTerms');
                if (poPaymentTerms) poPaymentTerms.textContent = 'Net 30';
            }
            
            renderPOItemsWithPhotos(pr.items || []);
            
            const itemsInput = document.getElementById('poItemsData');
            if (itemsInput) {
                itemsInput.value = JSON.stringify(pr.items || []);
            }
            
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        } else {
            Swal.fire('Error', data.error || 'Failed to load PR details', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to load purchase request details', 'error');
    });
}

function renderPOItemsWithPhotos(items) {
    const container = document.getElementById('poItemsContainer');
    const loadingEl = document.getElementById('poItemsLoading');
    const grandTotalEl = document.getElementById('poGrandTotal');
    
    if (!container) return;
    
    if (loadingEl) loadingEl.style.display = 'none';
    
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No items found</div>';
        return;
    }
    
    let html = '';
    let grandTotal = 0;
    
    items.forEach(item => {
        const total = parseFloat(item.total_price || 0);
        grandTotal += total;
        
        const photoPath = item.photo_path || 'assets/img/no-image.png';
        const hasPhoto = item.photo_path ? true : false;
        
        html += `
            <div class="d-flex align-items-center gap-3 p-2 mb-2 bg-white rounded-3 border-0 shadow-sm">
                <div class="flex-shrink-0">
                    ${hasPhoto ? 
                        `<img src="${photoPath}" class="rounded-2" style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #eee;" 
                              onerror="this.onerror=null; this.src='assets/img/no-image.png';">` : 
                        `<div class="bg-secondary bg-opacity-10 rounded-2 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-image text-secondary"></i>
                        </div>`
                    }
                </div>
                
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-medium">${escapeHtml(item.item_name || '')}</span>
                        <span class="fw-bold text-primary">₱${formatNumber(total)}</span>
                    </div>
                    <div class="d-flex justify-content-between small text-secondary">
                        <span>${item.quantity || 0} × ₱${formatNumber(item.unit_price || 0)}</span>
                        <span>${escapeHtml(item.supplier_name || '')}</span>
                    </div>
                    ${item.description ? `<div class="small text-muted mt-1">${escapeHtml(item.description)}</div>` : ''}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    if (grandTotalEl) {
        grandTotalEl.textContent = '₱' + formatNumber(grandTotal);
    }
}

function createPOFromForm() {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to create POs', 'error');
        return;
    }
    
    const form = document.getElementById('createPOForm');
    if (!form) return;
    
    const formData = new FormData(form);
    formData.append('action', 'create_po');
    
    Swal.fire({
        title: 'Creating Purchase Order...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('createPOModal'));
                if (modal) modal.hide();
                loadPRTable();
            });
        } else {
            Swal.fire('Error', data.error || 'Failed to create PO', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error', 'error');
    });
}

// ============================================
// EXPORT FUNCTIONS
// ============================================
function exportPRs() {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to export', 'error');
        return;
    }
    
    const params = new URLSearchParams(window.location.search);
    const url = `api/purchase_request.php?action=export&${params.toString()}`;
    window.open(url, '_blank');
}

function generateReorderReport() {
    if (!permissions.canView) {
        Swal.fire('Access Denied', 'You don\'t have permission to view reports', 'error');
        return;
    }
    
    window.open('reports/reorder_report.php', '_blank');
}



// ============================================
// HELPER FUNCTIONS
// ============================================
function formatNumber(num) {
    if (num === null || num === undefined || isNaN(num)) return '0.00';
    return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusColor(status) {
    const colors = {
        'Draft': 'secondary',
        'Pending Approval': 'warning',
        'Approved': 'success',
        'Rejected': 'danger',
        'PO Created': 'info',           
        'Pending Supplier Approval': 'warning', 
        'Supplier Approved': 'success', 
        'Supplier Rejected': 'danger',  
        'On Order': 'primary',
        'Shipped': 'info',
        'Completed': 'success'
    };
    return colors[status] || 'secondary';
}

function getPriorityColor(priority) {
    const colors = {
        'Critical': 'danger',
        'High': 'warning',
        'Medium': 'info',
        'Low': 'secondary'
    };
    return colors[priority] || 'secondary';
}
</script>
</body>
</html>