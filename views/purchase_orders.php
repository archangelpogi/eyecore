<?php
// views/purchase_orders.php
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

// ✅ RBAC Permission Check - MUST HAVE PURCHASE ORDERS VIEW PERMISSION
if (!RBACHelper::hasPermission('purchase_orders_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Purchase Orders.</p>
        </div>
    </div>
    <?php
    exit;
}

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('purchase_orders_view');
$canCreate = RBACHelper::hasPermission('purchase_orders_create');
$canEdit = RBACHelper::hasPermission('purchase_orders_edit');
$canDelete = RBACHelper::hasPermission('purchase_orders_delete');
$canApprove = RBACHelper::hasPermission('purchase_orders_approve');
$canReject = RBACHelper::hasPermission('purchase_orders_reject');

// Get session data
$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'SCM';
$clinic_id = $_SESSION['clinic_id'];

// Show success message if exists
if (isset($_SESSION['success_message'])) {
    echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '" . addslashes($_SESSION['success_message']) . "',
            timer: 2000,
            showConfirmButton: false
        });
    </script>";
    unset($_SESSION['success_message']);
}

// Get filter from URL
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// Status mapping for filters
$status_map = [
    'all' => '',
    'to_pay' => 'Pending Supplier Approval',
    'to_ship' => 'Supplier Approved',
    'to_receive' => 'Shipped',
    'completed' => 'Delivered',
    'cancelled' => 'Cancelled',
    'returns' => 'Returns'
];
$status_filter = $status_map[$filter] ?? '';

// Build query
$query = "
    SELECT po.*, 
           s.supplier_name,
           r.status as return_status,
           r.return_method,
           r.schedule_details,
           GROUP_CONCAT(CONCAT(pi.quantity, 'x ', pi.item_name) SEPARATOR ' | ') as item_summary
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN pr_items pi ON po.pr_id = pi.pr_id AND pi.supplier_id = po.supplier_id
    LEFT JOIN returns r ON po.id = r.po_id
    WHERE po.clinic_id = ?
";

if ($status_filter) {
    if ($filter == 'returns') {
        $query .= " AND r.status IN ('Return/Refund', 'For Pickup', 'For Drop-off', 'Return Completed')";
    } else {
        $query .= " AND po.status = ?";
    }
}

if ($search) {
    $query .= " AND (po.po_number LIKE ? OR s.supplier_name LIKE ? OR pi.item_name LIKE ?)";
}

$query .= " GROUP BY po.id ORDER BY po.created_at DESC";

$params = [$clinic_id];
if ($status_filter && $filter != 'returns') {
    $params[] = $status_filter;
}
if ($search) {
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for stats cards
$counts = [
    'all' => 0,
    'to_pay' => 0,
    'to_ship' => 0,
    'to_receive' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'returns' => 0
];

$countQuery = "
    SELECT 
        SUM(CASE WHEN po.status = 'Pending Supplier Approval' THEN 1 ELSE 0 END) as to_pay,
        SUM(CASE WHEN po.status = 'Supplier Approved' THEN 1 ELSE 0 END) as to_ship,
        SUM(CASE WHEN po.status = 'Shipped' THEN 1 ELSE 0 END) as to_receive,
        SUM(CASE WHEN po.status = 'Delivered' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN po.status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN r.status IN ('Return/Refund', 'For Pickup', 'For Drop-off', 'Return Completed') THEN 1 ELSE 0 END) as returns,
        COUNT(DISTINCT po.id) as total
    FROM purchase_orders po
    LEFT JOIN returns r ON po.id = r.po_id
    WHERE po.clinic_id = ?
";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute([$clinic_id]);
$countData = $countStmt->fetch(PDO::FETCH_ASSOC);

$counts = [
    'all' => $countData['total'] ?? 0,
    'to_pay' => $countData['to_pay'] ?? 0,
    'to_ship' => $countData['to_ship'] ?? 0,
    'to_receive' => $countData['to_receive'] ?? 0,
    'completed' => $countData['completed'] ?? 0,
    'cancelled' => $countData['cancelled'] ?? 0,
    'returns' => $countData['returns'] ?? 0
];

// Get total amount of pending orders
$totalPendingAmount = 0;
$pendingQuery = "SELECT SUM(total_amount) as total FROM purchase_orders WHERE clinic_id = ? AND status IN ('Pending Supplier Approval', 'Supplier Approved', 'Shipped')";
$pendingStmt = $pdo->prepare($pendingQuery);
$pendingStmt->execute([$clinic_id]);
$totalPendingAmount = $pendingStmt->fetchColumn() ?? 0;
?>

<!-- Main Content Area -->
<div class="container-fluid px-4">
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Purchase Orders</h1>
            <p class="text-muted">Track and manage your orders</p>
        </div>
        <?php if ($canCreate): ?>
        <a href="main.php?view=purchase_requests" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>New Purchase Request
        </a>
        <?php endif; ?>
    </div>

    <!-- STATS CARDS -->
    <div class="row mb-4">
        <!-- Total Orders Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2 stat-card" onclick="window.location.href='main.php?view=purchase_orders&filter=all'">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Orders
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $counts['all']; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-truck fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- To Receive Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2 stat-card" onclick="window.location.href='main.php?view=purchase_orders&filter=to_receive'">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                To Receive
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $counts['to_receive']; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-box-seam fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Amount Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2 stat-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Pending Amount
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                ₱<?php echo number_format($totalPendingAmount, 2); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-currency-dollar fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Completed Card -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2 stat-card" onclick="window.location.href='main.php?view=purchase_orders&filter=completed'">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Completed
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $counts['completed']; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH BAR -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <form method="GET" action="main.php" class="d-flex">
                        <input type="hidden" name="view" value="purchase_orders">
                        <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" 
                                   name="search"
                                   class="form-control border-start-0" 
                                   placeholder="Search by Order ID, Supplier Name, or Product..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary">
                                Search
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4">
                    <select class="form-select" onchange="window.location.href='main.php?view=purchase_orders&filter='+this.value<?php echo $search ? '+&search='.urlencode($search) : ''; ?>">
                        <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>All Orders</option>
                        <option value="to_pay" <?php echo $filter == 'to_pay' ? 'selected' : ''; ?>>To Pay</option>
                        <option value="to_ship" <?php echo $filter == 'to_ship' ? 'selected' : ''; ?>>To Ship</option>
                        <option value="to_receive" <?php echo $filter == 'to_receive' ? 'selected' : ''; ?>>To Receive</option>
                        <option value="completed" <?php echo $filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="returns" <?php echo $filter == 'returns' ? 'selected' : ''; ?>>Returns/Cancelled</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- NAV TABS -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'all' ? 'active fw-bold' : ''; ?>" 
               style="<?php echo $filter == 'all' ? 'color: #008080; border-bottom: 3px solid #008080;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=all<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                All <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $counts['all']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'to_pay' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'to_pay' ? 'color: #008080; border-bottom: 3px solid #008080;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=to_pay<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                To Pay <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $counts['to_pay']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'to_ship' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'to_ship' ? 'color: #008080; border-bottom: 3px solid #008080;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=to_ship<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                To Ship <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $counts['to_ship']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'to_receive' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'to_receive' ? 'color: #008080; border-bottom: 3px solid #008080;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=to_receive<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                To Receive <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $counts['to_receive']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'completed' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'completed' ? 'color: #008080; border-bottom: 3px solid #008080;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=completed<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                Completed <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1"><?php echo $counts['completed']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'cancelled' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'cancelled' ? 'color: #dc3545; border-bottom: 3px solid #dc3545;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=cancelled<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                Cancelled <span class="badge bg-danger bg-opacity-10 text-danger ms-1"><?php echo $counts['cancelled']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter == 'returns' ? 'active fw-bold' : ''; ?>"
               style="<?php echo $filter == 'returns' ? 'color: #fd7e14; border-bottom: 3px solid #fd7e14;' : 'color: #6c757d;'; ?>"
               href="main.php?view=purchase_orders&filter=returns<?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                <i class="bi bi-arrow-return-left me-1"></i>Returns/Cancelled 
                <span class="badge bg-warning bg-opacity-10 text-warning ms-1"><?php echo $counts['returns']; ?></span>
            </a>
        </li>
    </ul>

    <!-- Orders List -->
    <?php if (empty($orders)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox display-1 text-secondary opacity-50"></i>
            <h4 class="mt-3 text-secondary">No orders found</h4>
            <p class="text-secondary mb-4">Start by creating a new purchase request</p>
            <?php if ($canCreate): ?>
            <a href="main.php?view=purchase_requests" class="btn btn-primary btn-lg px-4">
                <i class="bi bi-plus-circle me-2"></i>New Purchase Request
            </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($orders as $order): 
            $item_summary = $order['item_summary'] ?? '';
            $item_count = substr_count($item_summary, '|') + 1;
            // Use return_status if exists, otherwise use po.status
            $current_status = !empty($order['return_status']) ? $order['return_status'] : $order['status'];
        ?>
            <!-- Order Card -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <div>
                        <span class="fw-bold"><?php echo htmlspecialchars($order['po_number']); ?></span>
                        <span class="badge bg-light text-secondary ms-2"><?php echo htmlspecialchars($order['supplier_name']); ?></span>
                    </div>
                    <div>
                    <?php
                    $display_status = match($current_status) {
                        'Pending Supplier Approval' => 'To Pay',
                        'Supplier Approved' => 'To Ship',
                        'Shipped' => 'To Receive',
                        'Delivered' => 'Completed',
                        'Cancelled' => 'Cancelled',
                        'Return/Refund' => 'Return Requested',
                        'For Pickup' => 'For Pickup',
                        'For Drop-off' => 'For Drop-off',
                        'Return Completed' => 'Return Completed',
                        default => $current_status
                    };

                    $status_badge = match($current_status) {
                        'Pending Supplier Approval' => 'warning',
                        'Supplier Approved' => 'info',
                        'Shipped' => 'primary',
                        'Delivered' => 'success',
                        'Cancelled' => 'danger',
                        'Return/Refund' => 'warning',
                        'For Pickup' => 'warning',
                        'For Drop-off' => 'info',
                        'Return Completed' => 'success',
                        default => 'secondary'
                    };
                    ?>
                    <span class="badge bg-<?php echo $status_badge; ?> bg-opacity-10 text-<?php echo $status_badge; ?> px-3 py-2">
                        <?php echo $display_status; ?>
                    </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-shop me-2" style="color: #008080;"></i>
                                <span class="fw-medium"><?php echo htmlspecialchars($order['supplier_name']); ?></span>
                            </div>
                            
                            <!-- Item Summary -->
                            <div class="small text-secondary">
                                <i class="bi bi-box-seam me-1"></i>
                                <?php 
                                if ($item_summary) {
                                    $items = explode(' | ', $item_summary);
                                    echo htmlspecialchars(implode(' • ', array_slice($items, 0, 2)));
                                    if (count($items) > 2) {
                                        echo ' and ' . (count($items) - 2) . ' more items';
                                    }
                                } else {
                                    echo $item_count . ' item(s)';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="col-md-5 text-md-end mt-3 mt-md-0">
                            <div class="fw-bold fs-5" style="color: #008080;">
                                ₱<?php echo number_format($order['total_amount'], 2); ?>
                            </div>
                            <div class="mt-2">
                                <?php if ($order['status'] == 'Shipped' && $canEdit): ?>
                                    <button class="btn btn-sm btn-success me-2" onclick="receiveOrder(<?php echo $order['id']; ?>)">
                                        <i class="bi bi-check-circle me-1"></i>Receive
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick="viewOrder(<?php echo $order['id']; ?>)">
                                    <i class="bi bi-eye me-1"></i>View Details
                                </button>
                                <?php if ($order['status'] == 'Pending Supplier Approval' && $canApprove): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="editOrder(<?php echo $order['id']; ?>)">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Styles -->
<style>
.stat-card {
    transition: transform 0.2s;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.border-left-primary {
    border-left: 4px solid #008080 !important;
}
.border-left-info {
    border-left: 4px solid #17a2b8 !important;
}
.border-left-warning {
    border-left: 4px solid #ffc107 !important;
}
.border-left-success {
    border-left: 4px solid #28a745 !important;
}
.nav-tabs .nav-link.active {
    color: #008080;
    border-bottom: 3px solid #008080;
}
.nav-tabs .nav-link {
    transition: all 0.2s;
}
.nav-tabs .nav-link:hover:not(.active) {
    color: #008080;
    border-bottom: 3px solid #00808080;
}
</style>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Pass permissions to JavaScript
const permissions = {
    canView: <?php echo json_encode($canView); ?>,
    canCreate: <?php echo json_encode($canCreate); ?>,
    canEdit: <?php echo json_encode($canEdit); ?>,
    canDelete: <?php echo json_encode($canDelete); ?>,
    canApprove: <?php echo json_encode($canApprove); ?>,
    canReject: <?php echo json_encode($canReject); ?>
};

function viewOrder(orderId) {
    window.location.href = 'views/po_details.php?id=' + orderId;
}

function receiveOrder(orderId) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to receive orders', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Receive Order?',
        text: 'Confirm that you have received all items in this order.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Receive',
        confirmButtonColor: '#28a745'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'views/receive_order.php?po_id=' + orderId;
        }
    });
}

function editOrder(orderId) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to edit orders', 'error');
        return;
    }
    window.location.href = 'views/edit_po.php?id=' + orderId;
}

function requestReturn(orderId) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to request returns', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Request Return',
        text: 'Are you sure you want to request a return for this order?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Request Return',
        confirmButtonColor: '#ffc107'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'views/request_return.php?po_id=' + orderId;
        }
    });
}

function confirmReturnCompleted(orderId) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to complete returns', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Complete Return?',
        text: 'Confirm that the return process has been completed.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Complete',
        confirmButtonColor: '#0d6efd'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'views/complete_return.php?po_id=' + orderId;
        }
    });
}
</script>