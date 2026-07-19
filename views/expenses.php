<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ ITO ANG KULANG!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Finance module (Professional or Enterprise plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('finance')) {
    header('Location: ../views/subscription.php');
    exit;
}

// ✅ RBAC Permission Check - MUST HAVE EXPENSES VIEW PERMISSION
if (!RBACHelper::hasPermission('expenses_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Expenses & Approvals.</p>
        </div>
    </div>
    <?php
    exit;
}

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo "<div class='alert alert-danger'>Please login first.</div>";
    exit;
}

$user_role = $_SESSION['role'] ?? 'Finance';
$current_user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];
$user_name = $_SESSION['name'] ?? 'User';

// ✅ Get user permissions using RBACHelper
$can_view_expenses = RBACHelper::hasPermission('expenses_view');
$can_create_expense = RBACHelper::hasPermission('expenses_create');
$can_edit_expense = RBACHelper::hasPermission('expenses_edit');
$can_delete_expense = RBACHelper::hasPermission('expenses_delete');
$can_approve_expense = RBACHelper::hasPermission('expenses_approve');
$can_reject_expense = RBACHelper::hasPermission('expenses_reject');
$can_export_expense = RBACHelper::hasPermission('expenses_view'); // export uses view permission
$can_approve_pr = RBACHelper::hasPermission('purchase_requests_approve');

// Get current month for default filter
$current_month = date('Y-m');

// Get categories for filters
$categories = [];
try {
    $catQuery = $pdo->query("SELECT category_name FROM expense_categories WHERE is_active = TRUE ORDER BY category_name");
    $categories = $catQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories = ['Rent', 'Utilities', 'Office Supplies', 'Equipment', 'Maintenance', 'Marketing', 'Travel', 'Training', 'Salaries', 'Other'];
}

// Get months for filter
$months = [];
for ($i = 0; $i < 6; $i++) {
    $date = strtotime("-$i months");
    $months[date('Y-m', $date)] = date('F Y', $date);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses & PR Approval - <?php echo $user_role; ?> Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --success-color: #06d6a0;
            --warning-color: #ffb703;
            --danger-color: #ef476f;
            --info-color: #4cc9f0;
            --dark-color: #2b2d42;
            --light-color: #f8f9fa;
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .container-fluid {
            max-width: 1600px;
        }
        
        .expense-card {
            background: white;
            border-radius: 16px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .expense-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(67, 97, 238, 0.08);
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            background: linear-gradient(145deg, white, #f8faff);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .badge-category {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-status {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-paid {
            background: #e3f9ed;
            color: #0b6e4f;
        }
        
        .status-pending {
            background: #fff3d6;
            color: #9c6b1a;
        }
        
        .status-overdue {
            background: #ffe8e8;
            color: #b33a3a;
        }
        
        .nav-tabs {
            border-bottom: none;
            gap: 8px;
            margin-bottom: 24px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            color: #64748b;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .nav-tabs .nav-link:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25);
        }
        
        .table th {
            background: #f8fafc;
            color: #334155;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: none;
            padding: 1rem 0.75rem;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            color: #1e293b;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .btn-approve {
            background: linear-gradient(145deg, #06d6a0, #05b586);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .btn-approve:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(6, 214, 160, 0.3);
            color: white;
        }
        
        .btn-reject {
            background: white;
            color: #ef476f;
            border: 1.5px solid #ef476f;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .btn-reject:hover {
            background: #ef476f;
            color: white;
        }
        
        .amount-display {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .amount-change {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .priority-critical {
            color: #ef476f;
            background: rgba(239, 71, 111, 0.1);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-high {
            color: #ffb703;
            background: rgba(255, 183, 3, 0.1);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-medium {
            color: #4cc9f0;
            background: rgba(76, 201, 240, 0.1);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-low {
            color: #64748b;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 30px;
            margin-bottom: 20px;
        }
        
        .timeline-dot {
            position: absolute;
            left: 0;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 2px solid white;
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }
        
        .timeline-line {
            position: absolute;
            left: 5px;
            top: 16px;
            width: 2px;
            height: calc(100% + 8px);
            background: #e2e8f0;
        }
        
        .timeline-item:last-child .timeline-line {
            display: none;
        }
        
        .receipt-preview {
            width: 60px;
            height: 40px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
        }
        
        .receipt-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .btn-approve, .btn-reject {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }

        .word-break-all {
    word-break: break-all;
}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
<!-- HEADER WITH ROLE BADGE -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="display-6 fw-bold mb-1">
            <i class="bi bi-receipt-cutoff text-primary me-2"></i>
            Expenses & Approvals
        </h1>
        <p class="text-muted mb-0">
<?php if ($can_create_expense || $can_approve_expense): ?>
                Manage purchase requests, track expenses, and monitor budget
            <?php else: ?>
                Track your department expenses and requests
            <?php endif; ?>
        </p>
    </div>
    
    <div class="d-flex gap-2">
        <select class="form-select form-select-lg bg-white border-0 shadow-sm" id="monthSelector" style="width: auto;">
            <?php foreach ($months as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php echo $value === $current_month ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>
        
<button class="btn btn-primary btn-lg px-4 d-flex align-items-center gap-2" 
        id="addExpenseBtn" 
        style="display: none;" 
        onclick="handleAddExpense()">
    <i class="bi bi-plus-circle"></i> Add Expense
</button>
    </div>
</div>
    
    <!-- STATS CARDS - DYNAMIC FROM DATABASE -->
    <div class="row g-4 mb-4" id="statsContainer">
        <!-- Stats will be loaded via AJAX -->
        <div class="col-12 text-center py-4">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2 text-muted">Loading statistics...</p>
        </div>
    </div>
    
<!-- TABS NAVIGATION -->
<ul class="nav nav-tabs" id="expenseTabs" role="tablist">
    <!-- PR Approval Tab - LAGING VISIBLE -->
    <li class="nav-item" id="prTabContainer" role="presentation">
        <button class="nav-link active" id="pr-tab" data-bs-toggle="tab" data-bs-target="#prForApproval" type="button" role="tab">
            <i class="bi bi-clipboard-check me-1"></i> PR For Approval
            <span class="badge bg-danger ms-2" id="pendingPrCount">0</span>
        </button>
    </li>
    
    <li class="nav-item" role="presentation">
        <button class="nav-link" 
                id="pending-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#pendingExpenses" 
                type="button" 
                role="tab">
            <i class="bi bi-clock-history me-1"></i> Pending Approval
            <span class="badge bg-warning ms-2" id="pendingExpenseCount">0</span>
        </button>
    </li>
    
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="all-tab" data-bs-toggle="tab" data-bs-target="#allExpenses" type="button" role="tab">
            <i class="bi bi-list-ul me-1"></i> Other Expenses
        </button>
    </li>
    
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="paid-tab" data-bs-toggle="tab" data-bs-target="#paidExpenses" type="button" role="tab">
            <i class="bi bi-check-circle me-1"></i> Paid
        </button>
    </li>
    
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="overdue-tab" data-bs-toggle="tab" data-bs-target="#overdueExpenses" type="button" role="tab">
            <i class="bi bi-exclamation-triangle me-1"></i> Overdue
        </button>
    </li>
    
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="budget-tab" data-bs-toggle="tab" data-bs-target="#budgetView" type="button" role="tab">
            <i class="bi bi-pie-chart me-1"></i> Budget Overview
        </button>
    </li>
</ul>
    
    <!-- TAB CONTENT -->
    <div class="tab-content mt-4" id="expenseTabsContent">
        
    <!-- TAB 1: PURCHASE REQUESTS FOR APPROVAL (DYNAMIC - controlled by JS) -->
<div class="tab-pane fade show active" id="prForApproval" role="tabpanel">
        <div class="expense-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-clipboard-check text-primary me-2"></i>
                    Purchase Requests Awaiting Approval
                </h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm" onclick="refreshPendingPRs()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover" id="pendingPRTable">
                    <thead>
                        <tr>
                            <th>PR Number</th>
                            <th>Department</th>
                            <th>Purpose</th>
                            <th>Items</th>
                            <th>Total Amount</th>
                            <th>Priority</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th width="100">Actions</th>
                        </thead>
                    <tbody id="pendingPRBody">
                        <tr><td colspan="9" class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading pending requests...</p></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
        
        <!-- TAB 2: PENDING EXPENSES -->
<div class="tab-pane fade" id="pendingExpenses" role="tabpanel">
            <div class="expense-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-clock-history text-warning me-2"></i>
                        Expenses Pending Approval
                    </h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover" id="pendingExpensesTable">
                        <thead>
                            <tr>
                                <th>Expense Code</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th width="150">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingExpensesBody">
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="spinner-border text-primary"></div>
                                    <p class="mt-2 text-muted">Loading expenses...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- TAB 3: ALL EXPENSES -->
        <div class="tab-pane fade" id="allExpenses" role="tabpanel">
            <div class="expense-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-list-ul text-primary me-2"></i>
                        Other Expenses
                    </h5>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="categoryFilter" style="width: 150px;">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary btn-sm" onclick="exportExpenses()">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover" id="allExpensesTable">
                        <thead>
                            <tr>
                                <th>Expense Code</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Receipt</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="allExpensesBody">
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <div class="spinner-border text-primary"></div>
                                    <p class="mt-2 text-muted">Loading expenses...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="text-muted small" id="expensePaginationInfo"></div>
                    <nav>
                        <ul class="pagination pagination-sm" id="expensePagination"></ul>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- TAB 4: PAID EXPENSES -->
        <div class="tab-pane fade" id="paidExpenses" role="tabpanel">
            <div class="expense-card p-4">
                <h5 class="fw-bold mb-4">
                    <i class="bi bi-check-circle text-success me-2"></i>
                    Paid Expenses
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Expense Code</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Payment Date</th>
                                <th>Payment Method</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="paidExpensesBody">
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="spinner-border text-primary"></div>
                                    <p class="mt-2 text-muted">Loading paid expenses...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- TAB 5: OVERDUE EXPENSES -->
        <div class="tab-pane fade" id="overdueExpenses" role="tabpanel">
            <div class="expense-card p-4">
                <h5 class="fw-bold mb-4">
                    <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                    Overdue Expenses
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Expense Code</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Vendor</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="overdueExpensesBody">
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="spinner-border text-primary"></div>
                                    <p class="mt-2 text-muted">Loading overdue expenses...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
<!-- TAB 6: BUDGET OVERVIEW -->
<div class="tab-pane fade" id="budgetView" role="tabpanel">
    <div class="expense-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0">
                <i class="bi bi-pie-chart text-info me-2"></i>
                Budget Overview
            </h5>
            <!-- IISA LANG NA BUTTON -->
<button class="btn btn-primary btn-sm" onclick="handleSetBudget()">
    <i class="bi bi-gear me-1"></i> Set Budget
</button>
        </div>
        
        <!-- Budget Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Allocated</th>
                        <th>Spent</th>
                        <th>Remaining</th>
                        <th>Utilization</th>
                    </tr>
                </thead>
                <tbody id="budgetTableBody">
                    <tr><td colspan="5" class="text-center py-4">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
    </div>
    
</div>

<!-- MODALS -->

<!-- Approve PR Modal - SIMPLIFIED (APPROVAL ONLY) -->
<div class="modal fade" id="approvePRModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="approvePRForm">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Purchase Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Hidden inputs -->
                    <input type="hidden" name="pr_id" id="approvePRId">
                    <input type="hidden" name="action" value="approve_pr">
                    <input type="hidden" name="convert_to_expense" value="yes">
                    
                    <!-- Display Info -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-file-text me-2"></i>
                        <strong>PR Number:</strong> 
                        <span id="approvePRNumberDisplay" class="ms-2"></span>
                    </div>
                    
                    <div class="alert alert-success mb-3">
                        <i class="bi bi-cash-stack me-2"></i>
                        <strong>Total Amount:</strong> 
                        <span id="approvePRAmountDisplay" class="ms-2"></span>
                    </div>
                    
                    <!-- Budget Summary (if available) -->
                    <div class="alert alert-warning mb-3" id="approveBudgetSummary" style="display: none;">
                        <i class="bi bi-pie-chart me-2"></i>
                        <small>Budget check will be displayed here</small>
                    </div>
                    
                    <!-- Confirmation -->
                    <p class="text-center mb-0">
                        <i class="bi bi-question-circle text-warning fs-4 d-block mb-2"></i>
                        Are you sure you want to approve this purchase request?
                    </p>
                    <p class="text-center text-muted small mt-2">
                        This will create an expense record for tracking.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Yes, Approve
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject PR Modal - HIDDEN (using SweetAlert2 instead) -->
<div class="modal fade" id="rejectPRModal" tabindex="-1" style="display: none;">
    <!-- This modal is no longer used. SweetAlert2 handles rejection now. -->
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Add New Expense
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addExpenseForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_expense">
                    
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Description</label>
                            <input type="text" class="form-control" name="description" 
                                   placeholder="What was this expense for?" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label required">Amount (₱)</label>
                            <input type="number" class="form-control" name="amount" 
                                   step="0.01" min="0" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Category</label>
                            <select class="form-select" name="category" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label required">Expense Date</label>
                            <input type="date" class="form-control" name="expense_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+15 days')); ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Vendor/Supplier</label>
                            <input type="text" class="form-control" name="vendor" 
                                   placeholder="Company or person paid">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department">
                                <option value="">Select Department</option>
                                <option>Administration</option>
                                <option>Human Resources</option>
                                <option>Finance</option>
                                <option>Optometry</option>
                                <option>Inventory</option>
                                <option>IT</option>
                                <option>Marketing</option>
                                <option>Sales</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Paid">Paid</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" 
                                      placeholder="Additional information..."></textarea>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_receipt" id="hasReceipt">
                                <label class="form-check-label" for="hasReceipt">
                                    I have a receipt to upload later
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Expense Modal - With Scrollbar -->
<div class="modal fade" id="viewExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <!-- Header - fixed with teal accent -->
            <div class="modal-header py-3" style="border-bottom: 2px solid #008080;">
                <h5 class="modal-title fw-light">
                    <i class="bi bi-eye me-2" style="color: #008080;"></i>
                    Expense Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- Body - scrollable with max height -->
            <div class="modal-body p-4" id="viewExpenseContent" style="max-height: 70vh; overflow-y: auto;">
                <!-- Content loaded via AJAX -->
            </div>
            
            <!-- Footer - fixed -->
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary px-4" data-bs-dismiss="modal">Close</button>

            </div>
        </div>
    </div>
</div>

<!-- Upload Receipt Modal -->
<div class="modal fade" id="uploadReceiptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cloud-upload me-2"></i>
                    Upload Receipt
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="uploadReceiptForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="expense_id" id="receiptExpenseId">
                    <input type="hidden" name="action" value="upload_receipt">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Receipt Image/PDF</label>
                        <input type="file" class="form-control" name="receipt" 
                               accept=".jpg,.jpeg,.png,.pdf" required>
                        <div class="form-text">Max file size: 10MB. Supported: JPG, PNG, PDF</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Upload a clear image of the receipt or invoice.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload"></i> Upload Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal for Ready to Pay Expenses -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: #008080; color: white;">
                <h5 class="modal-title">
                    <i class="bi bi-cash-stack me-2"></i> Process Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
               <input type="hidden" id="paymentExpenseId">
<input type="hidden" id="paymentAmountRaw" value="0">   <!-- ADD THIS LINE -->

                
                <!-- Expense Summary -->
                <div class="alert alert-light border mb-3">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Expense Code:</span>
                        <strong id="paymentExpenseCode">-</strong>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="text-muted">Amount:</span>
                        <strong class="text-success" id="paymentAmount">₱0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="text-muted">Vendor:</span>
                        <span id="paymentVendor">-</span>
                    </div>
                </div>
                
                <!-- Payment Method Selection -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
<select class="form-select" id="paymentMethodSelect" required>
    <option value="">Select payment method</option>
    <!-- Manual Payment Methods -->
    <option value="Cash">Cash</option>
    <option value="Bank Transfer">Bank Transfer</option>
    <option value="Check">Check</option>
    <!-- PayMongo Online Methods -->
    <optgroup label="── Online Payment (PayMongo) ──">
        <option value="GCash">GCash</option>
        <option value="PayMaya">PayMaya / Maya</option>
        <option value="ATM">ATM / Online Banking</option>
        <option value="Card">Credit / Debit Card</option>
    </optgroup>
</select>
                </div>
                
<!-- PayMongo Section - Shows when GCash, ATM, or PayMongo is selected -->
<div id="paymongoSection" style="display: none;">
    <div class="alert alert-info mb-3">
        <i class="bi bi-credit-card me-2"></i>
        <strong>Payment</strong><br>
        <small>Generate payment link to send to vendor/supplier</small>
    </div>
    
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer/Recipient Email <span class="text-danger">*</span></label>
        <input type="email" class="form-control" id="paymongoEmail" placeholder="vendor@example.com">
    </div>
    
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer Name</label>
        <input type="text" class="form-control" id="paymongoName" placeholder="Recipient name">
    </div>
    
    <div class="mb-3">
        <label class="form-label fw-semibold">Description</label>
        <input type="text" class="form-control" id="paymongoDescription" placeholder="Payment for order #...">
    </div>
    
<button class="btn btn-primary w-100 mb-3" onclick="redirectToPayMongo()">
    <i class="bi bi-credit-card me-2"></i> Proceed to Checkout
</button>

</div>
                
                <!-- Regular Payment Fields (for non-PayMongo methods) -->
                <div id="regularPaymentFields">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="paymentDate" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reference Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="paymentRef" placeholder="Check # / Transaction ID / OR #">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Proof of Payment</label>
                        <input type="file" class="form-control" id="paymentProof" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="text-muted">Upload receipt or screenshot (optional)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks (Optional)</label>
                        <textarea class="form-control" id="paymentRemarks" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="processPayment()">
                    <i class="bi bi-check-lg me-1"></i> Confirm Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SIMPLEST BUDGET MODAL EVER -->
<div class="modal fade" id="budgetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- SIMPLE BUT ELEGANT HEADER -->
            <div class="modal-header text-white" style="background: #4361ee;">
                <h6 class="modal-title"><i class="bi bi-pie-chart me-2"></i>Set Monthly Budget</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-3">
                <!-- Month -->
                <div class="mb-3">
                    <label class="small text-muted mb-1">Month</label>
                    <input type="month" class="form-control" id="budgetMonth" value="<?php echo date('Y-m'); ?>">
                </div>

<!-- Categories - Complete list -->
<div style="max-height: 300px; overflow-y: auto;">
    <!-- Equipment -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-tools text-secondary me-2"></i>
            <span class="flex-grow-1">Equipment</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetEquipment" data-category="Equipment" placeholder="0.00">
        </div>
    </div>
    
    <!-- Office Supplies -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-pencil text-primary me-2"></i>
            <span class="flex-grow-1">Office Supplies</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetOffice" data-category="Office Supplies" placeholder="0.00">
        </div>
    </div>
    
    <!-- Medical Equipment -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-heart-pulse text-success me-2"></i>
            <span class="flex-grow-1">Medical Equipment</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetMedical" data-category="Medical Equipment" placeholder="0.00">
        </div>
    </div>
    
    <!-- Optical Supplies -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-eye text-info me-2"></i>
            <span class="flex-grow-1">Optical Supplies</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetOptical" data-category="Optical Supplies" placeholder="0.00">
        </div>
    </div>
    
    <!-- Maintenance -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-tools text-warning me-2"></i>
            <span class="flex-grow-1">Maintenance</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetMaintenance" data-category="Maintenance" placeholder="0.00">
        </div>
    </div>
    
    <!-- Utilities -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-lightning text-danger me-2"></i>
            <span class="flex-grow-1">Utilities</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetUtilities" data-category="Utilities" placeholder="0.00">
        </div>
    </div>
    
    <!-- Marketing -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-megaphone text-info me-2"></i>
            <span class="flex-grow-1">Marketing</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetMarketing" data-category="Marketing" placeholder="0.00">
        </div>
    </div>
    
    <!-- Rent -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-building text-secondary me-2"></i>
            <span class="flex-grow-1">Rent</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetRent" data-category="Rent" placeholder="0.00">
        </div>
    </div>
    
    <!-- Salaries -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-people text-primary me-2"></i>
            <span class="flex-grow-1">Salaries</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetSalaries" data-category="Salaries" placeholder="0.00">
        </div>
    </div>
    
    <!-- Training -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-book text-warning me-2"></i>
            <span class="flex-grow-1">Training</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetTraining" data-category="Training" placeholder="0.00">
        </div>
    </div>
    
    <!-- Travel -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-airplane text-success me-2"></i>
            <span class="flex-grow-1">Travel</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetTravel" data-category="Travel" placeholder="0.00">
        </div>
    </div>
    
    <!-- Others -->
    <div class="mb-2 p-2 bg-light rounded">
        <div class="d-flex align-items-center">
            <i class="bi bi-grid text-secondary me-2"></i>
            <span class="flex-grow-1">Others</span>
            <input type="number" class="form-control form-control-sm w-50 budget-amount" 
                   id="budgetOthers" data-category="Other" placeholder="0.00">
        </div>
    </div>
</div>
                
                <!-- Total Display -->
                <div class="mt-3 p-2 bg-primary bg-opacity-10 rounded d-flex justify-content-between">
                    <span class="fw-bold">Total Budget:</span>
                    <span class="fw-bold text-primary" id="totalBudgetDisplay">₱0.00</span>
                </div>
            </div>
            
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" onclick="saveBudget()">
                    <i class="bi bi-check-lg"></i> Save
                </button>
            </div>
        </div>
    </div>
</div>



<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
let userRole = '<?php echo $user_role; ?>';
let clinicId = <?php echo $clinic_id; ?>;
let currentMonth = '<?php echo $current_month; ?>';
let currentExpenseId = null;
let currentPage = 1;
let budgetChart = null;
let userPermissions = {
    can_view: <?php echo json_encode($can_view_expenses); ?>,
    can_create: <?php echo json_encode($can_create_expense); ?>,
    can_edit: <?php echo json_encode($can_edit_expense); ?>,
    can_delete: <?php echo json_encode($can_delete_expense); ?>,
    can_approve: <?php echo json_encode($can_approve_expense); ?>,
    can_reject: <?php echo json_encode($can_reject_expense); ?>,
    can_export: <?php echo json_encode($can_export_expense); ?>,
    can_approve_pr: <?php echo json_encode($can_approve_expense); ?>  // ← PR approve uses expenses_approve
};

const currentUserId = <?php echo json_encode($current_user_id); ?>;
const currentUserRole = <?php echo json_encode($user_role); ?>;
const currentUserName = <?php echo json_encode($user_name); ?>;

console.log('RBAC Permissions loaded:', userPermissions);

$(document).ready(function() {
    try {
        console.log('=== DOCUMENT READY START ===');
        
        // ✅ Load permissions first - siya na bahala mag-load ng data
        loadPermissions();
        
        // Set up event listeners (pero hindi pa maglo-load ng data)
        setupEventListeners();
        
        // Initialize DataTables
        initializeDataTables();
        
        console.log('=== DOCUMENT READY COMPLETE ===');
        
    } catch(e) {
        console.error('ERROR in document ready:', e);
    }
});


// Load on tab click
$('button[data-bs-target="#budgetView"]').on('click', loadBudgetData);

// Auto-compute total
$('.budget-amount').on('input', function() {
    let total = 0;
    $('.budget-amount').each(function() {
        total += parseFloat($(this).val()) || 0;
    });
    $('#totalBudgetDisplay').text('₱' + total.toFixed(2));
});

function setupEventListeners() {
    // Month selector change
    $('#monthSelector').on('change', function() {
        currentMonth = $(this).val();
        loadStats();
        loadExpenses('all');
        loadBudgetData();
    });
    
    // Category filter change
    $('#categoryFilter').on('change', function() {
        loadExpenses('all');
    });
    
    // Approve PR form submit
    $('#approvePRForm').on('submit', function(e) {
        e.preventDefault();
        approvePurchaseRequest();
    });
    
    // Add Expense form submit
    $('#addExpenseForm').on('submit', function(e) {
        e.preventDefault();
        createExpense();
    });
    
    // Upload Receipt form submit
    $('#uploadReceiptForm').on('submit', function(e) {
        e.preventDefault();
        uploadReceipt();
    });
    
    // ✅ ADD THIS: Payment method change event for PayMongo
    $('#paymentMethodSelect').on('change', function() {
        const method = $(this).val();
        const paymongoMethods = ['GCash', 'PayMaya', 'ATM', 'Card'];
        
        console.log('Payment method selected:', method);
        console.log('Is PayMongo method?', paymongoMethods.includes(method));
        
        if (paymongoMethods.includes(method)) {
            $('#paymongoSection').show();
            $('#regularPaymentFields').hide();
            $('#paymentLinkContainer').hide();
        } else {
            $('#paymongoSection').hide();
            $('#regularPaymentFields').show();
            $('#paymentLinkContainer').hide();
        }
    });
}
// ============================================
// STATS FUNCTIONS
// ============================================
function loadStats() {
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_expenses',
            month: currentMonth,
            limit: 1
        },
        success: function(response) {
            if (response.success && response.stats) {
                renderStats(response.stats);
            }
        },
        error: function() {
            console.error('Failed to load stats');
        }
    });
}

function renderStats(stats) {
    const totalMonth = stats.total_month || 0;
    const totalPaid = stats.total_paid || 0;
    const totalPending = stats.total_pending || 0;
    const totalOverdue = stats.total_overdue || 0;
    const totalForApproval = stats.total_for_approval || 0;
    const totalReturns = stats.total_returns || 0;  // ✅ BAGO
    const budgetAllocated = stats.budget_allocated || 0;
    const budgetSpent = stats.budget_spent || 0;
    
    const budgetUtilization = budgetAllocated > 0 ? 
        ((budgetSpent / budgetAllocated) * 100).toFixed(2) : 0;
    
    const html = `
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Total This Month</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalMonth)}</h3>
                    <small class="text-success">
                        <i class="bi bi-arrow-down"></i> All expenses this month
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Pending Approval</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalForApproval)}</h3>
                    <small class="text-muted">${stats.total_count || 0} PRs awaiting approval</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Overdue</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalOverdue)}</h3>
                    <small class="text-muted">Requires immediate attention</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                    <i class="bi bi-pie-chart"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Budget Utilization</small>
                    <h3 class="fw-bold mb-0">${budgetUtilization}%</h3>
                    <small class="text-muted">₱${formatNumber(budgetSpent)} of ₱${formatNumber(budgetAllocated)}</small>
                    <div class="progress mt-2" style="height: 6px; width: 150px;">
                        <div class="progress-bar bg-info" style="width: ${budgetUtilization}%"></div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // ✅ Magdagdag ng optional row para sa returns summary
    if (totalReturns > 0) {
        html += `
        <div class="col-md-12 mt-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3">
                    <i class="bi bi-arrow-return-left"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Returns This Month</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalReturns)}</h3>
                    <small class="text-muted">Total refund amount processed</small>
                </div>
            </div>
        </div>
        `;
    }
    
    $('#statsContainer').html(html);
}

function loadPendingPRs() {
    // ✅ Check if user has permission to view PRs (view permission is enough to see)
    if (!userPermissions.can_view) {
        console.log('User does not have permission to view PRs');
        $('#prTabContainer').hide();
        renderPendingPRs([]);
        return;
    }
    
    console.log('=== loadPendingPRs FUNCTION CALLED ===');
    
    // Show the container
    $('#prTabContainer').show();
    
    $('#pendingPRBody').html(`
        
            <td colspan="9" class="text-center py-4">
                <div class="spinner-border text-primary"></div>
                <p class="mt-2 text-muted">Loading pending requests...</p>
            </td>
    `);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_pending_prs'
        },
        success: function(response) {
            console.log('Pending PRs response:', response);
            if (response.success) {
                renderPendingPRs(response.data);
                $('#pendingPrCount').text(response.data.length || 0);
            } else {
                console.error('Failed to load PRs:', response.error);
                renderPendingPRs([]);
                
                if (response.error === 'You do not have permission to view pending purchase requests') {
                    $('#prTabContainer').hide();
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load PRs:', status, error);
            renderPendingPRs([]);
            if (xhr.status === 403) {
                $('#prTabContainer').hide();
            }
        }
    });
}
function renderPendingPRs(prs) {
    const tbody = $('#pendingPRBody');
    
    // ✅ Check if user has permission
    if (!userPermissions.can_create) {
        tbody.html(`
            <tr>
                <td colspan="9" class="text-center py-5 text-muted">
                    <i class="bi bi-shield-lock fs-1"></i>
                    <p class="mt-3">You don't have permission to view purchase requests.</p>
                </td>
            </tr>
        `);
        return;
    }
    
    if (!prs || prs.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="9" class="text-center py-5">
                    <i class="bi bi-check-circle text-success fs-1"></i>
                    <p class="mt-3 text-muted">No pending purchase requests for approval</p>
                </td>
            </tr>
        `);
        return;
    }
    
    // ✅ Render the data
    let html = '';
    prs.forEach(pr => {
        const priorityClass = getPriorityClass(pr.priority);
        const createdDate = new Date(pr.created_at).toLocaleDateString();
        
        html += `
            <tr>
                <td><strong>${pr.pr_number}</strong></td>
                <td>${pr.department}</td>
                <td><span title="${pr.purpose}">${truncate(pr.purpose, 40)}</span></td>
                <td>${pr.item_count || 0} items</td>
                <td class="fw-bold">₱${formatNumber(pr.total_amount)}</td>
                <td><span class="${priorityClass}">${pr.priority}</span></td>
                <td>${pr.requested_by_name || 'Unknown'}</td>
                <td><small>${createdDate}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-info" onclick="viewPRDetails(${pr.id})" title="View Details">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
    console.log(`Rendered ${prs.length} PRs successfully`);
}
function loadExpenses(tab) {
    // ✅ I-check muna kung may view permission
    if (!userPermissions.can_view) {
        console.log('No permission to view expenses');
        const tbodyId = getTbodyId(tab);
        if (tbodyId) {
            $(tbodyId).html(`
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-shield-lock fs-1"></i>
                        <p class="mt-3">You don't have permission to view expenses.</p>
                    </td>
                </tr>
            `);
        }
        return;
    }
    
    console.log(`Loading ${tab} expenses...`);
    
    if (!currentMonth) {
        currentMonth = new Date().toISOString().slice(0, 7);
        console.log('Set current month to:', currentMonth);
    }
    
    let url = 'api/expenses.php?action=get_expenses';
    url += `&month=${currentMonth}&tab=${tab}`;
    
    if (tab === 'all') {
        const category = $('#categoryFilter').val();
        if (category && category !== 'all') {
            url += `&category=${encodeURIComponent(category)}`;
        }
    }
    
    console.log('Fetching:', url);
    
    $.ajax({
        url: url,
        method: 'GET',
        success: function(response) {
            console.log(`Response for ${tab}:`, response);
            
            if (response.success) {
                renderExpenses(tab, response.data, response.pagination);
                
                if (tab === 'pending') {
                    $('#pendingExpenseCount').text(response.data.length || 0);
                }
            } else {
                console.error('Failed to load expenses:', response.error);
                showError(`Failed to load ${tab} expenses`);
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load expenses for tab:', tab, error);
            showError(`Error loading ${tab} expenses`);
        }
    });
}

function getTbodyId(tab) {
    switch(tab) {
        case 'pending': return '#pendingExpensesBody';
        case 'all': return '#allExpensesBody';
        case 'paid': return '#paidExpensesBody';
        case 'overdue': return '#overdueExpensesBody';
        default: return null;
    }
}

function renderExpenses(tab, expenses, pagination) {
    console.log(`Rendering ${tab} expenses:`, expenses);
    
    let tbodyId;
    switch(tab) {
        case 'pending':
            tbodyId = '#pendingExpensesBody';
            break;
        case 'all':
            tbodyId = '#allExpensesBody';
            $('#expensePaginationInfo').text(
                `Showing ${expenses.length} of ${pagination?.total || 0} records`
            );
            renderPagination(pagination);
            break;
        case 'paid':
            tbodyId = '#paidExpensesBody';
            break;
        case 'overdue':
            tbodyId = '#overdueExpensesBody';
            break;
        default:
            return;
    }
    
    const tbody = $(tbodyId);
    
    if (!expenses || expenses.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="10" class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="mt-3 text-muted">No expenses found</p>
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    expenses.forEach(exp => {
        if (tab === 'pending') {
            html += renderPendingExpenseRow(exp);
        } else if (tab === 'all') {
            html += renderAllExpenseRow(exp);
        } else if (tab === 'paid') {
            html += renderPaidExpenseRow(exp);
        } else if (tab === 'overdue') {
            html += renderOverdueExpenseRow(exp);
        }
    });
    
    tbody.html(html);
    console.log(`Rendered ${expenses.length} rows for ${tab}`);
}

function renderPendingExpenseRow(exp) {
    let statusClass = '';
    let statusText = exp.status;
    
    switch(exp.status) {
        case 'Pending':
            statusClass = 'status-pending';
            break;
        case 'Return Requested':      // ✅ BAGO
            statusClass = 'status-pending';
            statusText = 'Return Requested 🔄';
            break;
        case 'Return Completed':       // ✅ BAGO
            statusClass = 'status-paid';
            statusText = 'Return Completed ✅';
            break;
        case 'Overdue':
            statusClass = 'status-overdue';
            break;
        case 'Ready to Pay':
            statusClass = 'status-warning';
            break;
        default:
            statusClass = 'status-pending';
    }
    
    const isReadyToPay = exp.status === 'Ready to Pay';
    
    let actionButtons = `<button class="btn btn-outline-primary btn-sm" onclick="viewExpense(${exp.id})" title="View">
                            <i class="bi bi-eye"></i>
                         </button>`;
    
    if (userPermissions.can_approve && isReadyToPay) {
        actionButtons += `<button class="btn btn-outline-success btn-sm" onclick="openPaymentModal(${exp.id})" title="Process Payment">
                              <i class="bi bi-cash-stack"></i> Pay
                          </button>`;
    }
    
    if (userPermissions.can_edit && isReadyToPay) {
        actionButtons += `<button class="btn btn-outline-secondary btn-sm" onclick="uploadReceiptModal(${exp.id})" title="Upload Receipt">
                              <i class="bi bi-cloud-upload"></i>
                          </button>`;
    }
    
    let amountDisplay = `₱${formatNumber(exp.amount)}`;
    let amountClass = '';
    
    if (exp.status === 'Return Requested') {
        amountDisplay = `-₱${formatNumber(Math.abs(exp.amount))}`;
        amountClass = 'text-danger';
    }
    
    return `
        <tr>
            <td><strong>${escapeHtml(exp.expense_code || 'N/A')}</strong></td>
            <td>
                <div class="fw-bold">${escapeHtml(exp.description)}</div>
                <small class="text-muted">${escapeHtml(exp.vendor || 'No vendor')}</small>
            </td>
            <td>
                <span class="badge-category" style="background: ${exp.color_code || '#4361ee'}20; color: ${exp.color_code || '#4361ee'}">
                    <i class="bi ${exp.icon || 'bi-receipt'} me-1"></i>
                    ${escapeHtml(exp.category)}
                </span>
            </td>
            <td class="fw-bold ${amountClass}">${amountDisplay}</td>
            <td><small>${formatDate(exp.due_date)}</small></td>
            <td>${escapeHtml(exp.department || 'N/A')}</td>
            <td><span class="badge-status ${statusClass}">${escapeHtml(statusText)}</span></td>
            <td><div class="btn-group btn-group-sm">${actionButtons}</div></td>
        </tr>
    `;
}

function renderAllExpenseRow(exp) {
    // I-update ang statusClass para sa bagong statuses
    let statusClass = '';
    let statusText = exp.status;
    let statusIcon = '';
    
    switch(exp.status) {
        case 'Paid':
            statusClass = 'status-paid';
            break;
        case 'Pending':
            statusClass = 'status-pending';
            break;
        case 'Return Requested':      // ✅ BAGO
            statusClass = 'status-pending';
            statusText = 'Return Requested 🔄';
            statusIcon = '<i class="bi bi-arrow-return-left me-1"></i>';
            break;
        case 'Return Completed':       // ✅ BAGO
            statusClass = 'status-paid';
            statusText = 'Return Completed ✅';
            statusIcon = '<i class="bi bi-check-circle me-1"></i>';
            break;
        case 'Ready to Pay':
            statusClass = 'status-warning';
            break;
        case 'Overdue':
            statusClass = 'status-overdue';
            break;
        case 'Waiting For Delivery':
            statusClass = 'status-pending';
            statusText = 'Waiting For Delivery 🚚';
            break;
        default:
            statusClass = 'status-pending';
    }
    
    let receiptIcon = '';
    if (exp.has_receipt || exp.attachment_count > 0) {
        receiptIcon = '<i class="bi bi-file-earmark-check text-success"></i>';
    } else {
        receiptIcon = '<i class="bi bi-file-earmark text-muted"></i>';
    }
    
    let actionButtons = `<button class="btn btn-outline-primary btn-sm" onclick="viewExpense(${exp.id})" title="View">
                            <i class="bi bi-eye"></i>
                         </button>`;

    if (userPermissions.can_approve && exp.status !== 'Paid' && exp.status !== 'Return Completed') {
        actionButtons += `<button class="btn btn-outline-success btn-sm" onclick="openPaymentModal(${exp.id})" title="Process Payment">
                              <i class="bi bi-cash-stack"></i> Pay
                          </button>`;
    }

    if (userPermissions.can_edit && exp.status !== 'Return Completed') {
        actionButtons += `<button class="btn btn-outline-warning btn-sm" onclick="editExpense(${exp.id})" title="Edit">
                              <i class="bi bi-pencil"></i>
                          </button>`;
    }
    
    if (userPermissions.can_delete && exp.status !== 'Return Completed') {
        actionButtons += `<button class="btn btn-outline-danger btn-sm" onclick="deleteExpense(${exp.id})" title="Delete">
                              <i class="bi bi-trash"></i>
                          </button>`;
    }
    
    // Para sa return transactions, magdagdag ng negative sign sa amount
    let amountDisplay = `₱${formatNumber(exp.amount)}`;
    let amountClass = '';
    
    if (exp.status === 'Return Requested' || exp.status === 'Return Completed') {
        // Ipakita ang return amount na may negative sign
        amountDisplay = `-₱${formatNumber(Math.abs(exp.amount))}`;
        amountClass = 'text-danger';
    }
    
    return `
        <tr>
            <td><strong>${escapeHtml(exp.expense_code || 'N/A')}</strong></td>
            <td>
                <div class="fw-bold">${escapeHtml(truncate(exp.description, 30))}</div>
                <small class="text-muted">${escapeHtml(exp.vendor || '')}</small>
            </td>
            <td>
                <span class="badge-category" style="background: ${exp.color_code || '#4361ee'}20; color: ${exp.color_code || '#4361ee'}">
                    <i class="bi ${exp.icon || 'bi-receipt'} me-1"></i>
                    ${escapeHtml(exp.category)}
                </span>
            </td>
            <td class="fw-bold ${amountClass}">${amountDisplay}</td>
            <td><small>${formatDate(exp.expense_date)}</small></td>
            <td>${escapeHtml(exp.vendor || 'N/A')}</td>
            <td>${escapeHtml(exp.department || 'N/A')}</td>
            <td><span class="badge-status ${statusClass}">${statusIcon} ${escapeHtml(statusText)}</span></td>
            <td class="text-center"><span onclick="viewReceipt(${exp.id})" style="cursor: pointer;">${receiptIcon}</span></td>
            <td><div class="btn-group btn-group-sm">${actionButtons}</div></td>
        </tr>
    `;
}
function renderPaidExpenseRow(exp) {
    return `
        <tr>
            <td><strong>${exp.expense_code}</strong></td>
            <td>${truncate(exp.description, 30)}</td>
            <td class="fw-bold">₱${formatNumber(exp.amount)}</td>
            <td>${formatDate(exp.payment_date)}</td>
            <td>${exp.payment_method || 'N/A'}</td>
            <td>${exp.payment_reference || 'N/A'}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="viewExpense(${exp.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `;
}

function renderOverdueExpenseRow(exp) {
    const dueDate = new Date(exp.due_date);
    const today = new Date();
    const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));

    const actionButton = userPermissions.can_approve
        ? `<div class="btn-group btn-group-sm">
               <button class="btn btn-outline-primary btn-sm" onclick="viewExpense(${exp.id})" title="View">
                   <i class="bi bi-eye"></i>
               </button>
               ${exp.status !== 'Return Completed' ? 
                   `<button class="btn btn-sm btn-success" onclick="openPaymentModal(${exp.id})" title="Process Payment">
                       <i class="bi bi-cash-stack"></i> Pay Now
                   </button>` : ''}
           </div>`
        : `<button class="btn btn-sm btn-secondary" disabled>
               <i class="bi bi-lock"></i> No Access
           </button>`;

    let amountDisplay = `₱${formatNumber(exp.amount)}`;
    let amountClass = 'text-danger';
    
    if (exp.status === 'Return Requested') {
        amountDisplay = `-₱${formatNumber(Math.abs(exp.amount))}`;
    }

    return `
        <tr>
            <td><strong>${escapeHtml(exp.expense_code || 'N/A')}</strong></td>
            <td>
                <div class="fw-bold">${escapeHtml(truncate(exp.description, 30))}</div>
                <small class="text-muted">${escapeHtml(exp.vendor || 'No vendor')}</small>
            </td>
            <td class="fw-bold ${amountClass}">${amountDisplay}</td>
            <td><span class="text-danger">${formatDate(exp.due_date)}</span></td>
            <td><span class="badge bg-danger">${daysOverdue} days</span></td>
            <td>
                <div class="fw-bold">${escapeHtml(exp.vendor || 'N/A')}</div>
                ${exp.vendor_contact ? `<small class="text-muted">${escapeHtml(exp.vendor_contact)}</small>` : ''}
            </td>
            <td>${actionButton}</td>
        </tr>
    `;
}

function renderPagination(pagination) {
    if (!pagination || pagination.pages <= 1) {
        $('#expensePagination').empty();
        return;
    }
    
    let html = '';
    const current = pagination.page || 1;
    const total = pagination.pages || 1;
    
    html += `<li class="page-item ${current === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${current - 1})">&laquo;</a>
    </li>`;
    
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - 2 && i <= current + 2)) {
            html += `<li class="page-item ${i === current ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
            </li>`;
        } else if (i === current - 3 || i === current + 3) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    html += `<li class="page-item ${current === total ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${current + 1})">&raquo;</a>
    </li>`;
    
    $('#expensePagination').html(html);
}

function changePage(page) {
    currentPage = page;
    loadExpenses('all');
}

function addNewExpense() {
    $('#addExpenseForm')[0].reset();
    $('#addExpenseModal').modal('show');
}

function createExpense() {
    const formData = new FormData(document.getElementById('addExpenseForm'));
    const amount = formData.get('amount');
    
    if (parseFloat(amount) <= 0) {
        Swal.fire('Error!', 'Amount must be greater than 0', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Saving Expense...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    $('#addExpenseModal').modal('hide');
                    setTimeout(() => {
                        loadExpenses('all');
                        loadExpenses('pending');
                        loadExpenses('paid');
                        loadExpenses('overdue');
                        loadStats();
                    }, 500);
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to create expense', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}

function viewExpense(id) {
    $.ajax({
        url: `api/expenses.php?action=get_expense&id=${id}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderExpenseDetails(response.data);
                currentExpenseId = id;
                $('#viewExpenseModal').modal('show');
            } else {
                Swal.fire('Error!', response.error || 'Expense not found', 'error');
            }
        },
        error: function() {
            Swal.fire('Error!', 'Failed to load expense details', 'error');
        }
    });
}

function renderExpenseDetails(expense) {
    const statusClass = expense.status === 'Paid' ? 'bg-success' : 
                       expense.status === 'Pending' ? 'bg-warning' : 'bg-danger';
    
    const approvedByName = expense.approved_by_name || 
                          (expense.activity_log && expense.activity_log.length > 0 ? 
                          expense.activity_log[0].user_name : 'N/A');
    
    let attachmentsHtml = '';
    if (expense.attachments && expense.attachments.length > 0) {
        attachmentsHtml = '<h6 class="mt-4">Attachments</h6><div class="d-flex gap-2">';
        expense.attachments.forEach(att => {
            attachmentsHtml += `
                <div class="border rounded p-2">
                    <i class="bi bi-file-earmark-text"></i>
                    <small>${att.file_name}</small>
                    <a href="${att.file_path}" target="_blank" class="ms-2">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            `;
        });
        attachmentsHtml += '</div>';
    }
    
    let activityHtml = '';
    if (expense.activity_log && expense.activity_log.length > 0) {
        activityHtml = '<h6 class="mt-4">Activity Log</h6><div class="timeline">';
        expense.activity_log.forEach(log => {
            activityHtml += `
                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-line"></div>
                    <small class="text-muted">${formatDateTime(log.created_at)}</small>
                    <div class="fw-bold">${log.details}</div>
                    <small>by ${log.user_name}</small>
                </div>
            `;
        });
        activityHtml += '</div>';
    }
    
    const html = `
        <div class="row">
            <div class="col-md-8">
                <h5 class="fw-bold">${expense.expense_code}</h5>
                <p class="mb-2">${expense.description}</p>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><td width="120"><strong>Category</strong></td><td><span class="badge-category">${expense.category}</span></td></tr>
                            <tr><td><strong>Amount</strong></td><td class="fw-bold fs-5">₱${formatNumber(expense.amount)}</td></tr>
                            <tr><td><strong>Status</strong></td><td><span class="badge ${statusClass}">${expense.status}</span></td></tr>
                            <tr><td><strong>Expense Date</strong></td><td>${formatDate(expense.expense_date)}</td></tr>
                            <tr><td><strong>Due Date</strong></td><td>${formatDate(expense.due_date) || 'N/A'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><td width="120"><strong>Vendor</strong></td><td>${expense.vendor || 'N/A'}</td></tr>
                            <tr><td><strong>Department</strong></td><td>${expense.department || 'N/A'}</td></tr>
                            <tr><td><strong>Requested By</strong></td><td>${expense.requested_by_name || expense.requested_by || 'N/A'}</td></tr>
                            <tr><td><strong>Approved By</strong></td><td>${approvedByName}</td></tr>
                            <tr><td><strong>Payment Method</strong></td><td>${expense.payment_method || 'N/A'}</td></tr>
                        </table>
                    </div>
                </div>
                ${expense.notes ? `<div class="mt-3"><strong>Notes:</strong><p class="text-muted">${expense.notes}</p></div>` : ''}
                ${attachmentsHtml}
                ${activityHtml}
            </div>
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-body">
                        <h6 class="card-title">Quick Actions</h6>
                        ${expense.status === 'Pending' ? `<button class="btn btn-success w-100 mb-2" onclick="approveExpense(${expense.id})"><i class="bi bi-check-circle"></i> Approve & Pay</button>` : ''}
                        <button class="btn btn-outline-primary w-100 mb-2" onclick="uploadReceiptModal(${expense.id})"><i class="bi bi-cloud-upload"></i> Upload Receipt</button>
                        <button class="btn btn-outline-secondary w-100" onclick="downloadExpense(${expense.id})"><i class="bi bi-download"></i> Download Details</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#viewExpenseContent').html(html);
}



function editExpense(id) {
    if (!userPermissions.can_edit) {
        showNoPermissionWarning('edit expenses');
        return;
    }
    Swal.fire({
        title: 'Edit Expense',
        text: 'Edit expense #' + id,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Edit',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            addNewExpense();
        }
    });
}

function originalApproveExpense(id) {
    Swal.fire({
        title: 'Approve & Pay Expense',
        html: `
            <div class="text-start">
                <p>Process payment for expense #${id}</p>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" id="paymentMethod">
                        <option>Bank Transfer</option>
                        <option>Check</option>
                        <option>Cash</option>
                        <option>Credit Card</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Date</label>
                    <input type="date" class="form-control" id="paymentDate" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference Number</label>
                    <input type="text" class="form-control" id="paymentRef" placeholder="Check # / Ref #">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Approve & Pay',
        confirmButtonColor: '#06d6a0',
        preConfirm: () => {
            return {
                payment_method: document.getElementById('paymentMethod').value,
                payment_date: document.getElementById('paymentDate').value,
                payment_reference: document.getElementById('paymentRef').value
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'approve_expense');
            formData.append('id', id);
            formData.append('payment_method', result.value.payment_method);
            formData.append('payment_date', result.value.payment_date);
            formData.append('payment_reference', result.value.payment_reference);
            
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            $.ajax({
                url: 'api/expenses.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            loadExpenses('pending');
                            loadExpenses('all');
                            loadExpenses('paid');
                            loadStats();
                            loadBudgetData();
                        });
                    } else {
                        Swal.fire('Error!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error!', 'Failed to approve expense', 'error');
                }
            });
        }
    });
}

function approvePurchaseRequest() {
    const formData = new FormData(document.getElementById('approvePRForm'));
    
    Swal.fire({
        title: 'Approving PR...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Approved!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#approvePRModal').modal('hide');
                    loadPendingPRs();
                    loadStats();
                    loadBudgetData();
                    loadExpenses('pending');
                    loadExpenses('all');
                    loadExpenses('paid');
                    loadExpenses('overdue');
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to approve PR', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}

// ============================================
// RECEIPT FUNCTIONS
// ============================================
function uploadReceiptModal(expenseId) {
    $('#receiptExpenseId').val(expenseId);
    $('#uploadReceiptModal').modal('show');
}

function uploadReceipt() {
    const formData = new FormData(document.getElementById('uploadReceiptForm'));
    
    Swal.fire({
        title: 'Uploading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Uploaded!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    $('#uploadReceiptModal').modal('hide');
                    loadExpenses('all');
                    if (currentExpenseId) viewExpense(currentExpenseId);
                });
            } else {
                Swal.fire('Error!', response.error || 'Upload failed', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error!', 'Failed to upload receipt', 'error');
        }
    });
}

function viewReceipt(expenseId) {
    $.ajax({
        url: `api/expenses.php?action=get_expense&id=${expenseId}`,
        method: 'GET',
        success: function(response) {
            if (response.success && response.data.attachments && response.data.attachments.length > 0) {
                const attachment = response.data.attachments[0];
                Swal.fire({
                    title: 'Receipt',
                    html: `<div class="text-center"><img src="${attachment.file_path}" class="img-fluid border rounded" style="max-height: 400px;" alt="Receipt"><p class="mt-3">${attachment.file_name}</p><a href="${attachment.file_path}" download class="btn btn-primary"><i class="bi bi-download"></i> Download</a></div>`,
                    width: 600,
                    showConfirmButton: false,
                    showCloseButton: true
                });
            } else {
                Swal.fire({
                    title: 'No Receipt',
                    html: `<div class="text-center py-4"><i class="bi bi-receipt fs-1 text-muted"></i><p class="mt-3">No receipt uploaded for this expense.</p><button class="btn btn-primary" onclick="uploadReceiptModal(${expenseId})"><i class="bi bi-cloud-upload"></i> Upload Receipt</button></div>`,
                    showConfirmButton: false,
                    showCloseButton: true
                });
            }
        }
    });
}

// ============================================
// BUDGET FUNCTIONS
// ============================================
function loadBudgetData() {
    const month = $('#monthSelector').val() || new Date().toISOString().slice(0, 7);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_budget',
            month: month
        },
        success: function(response) {
            if (response.success) {
                renderBudgetTable(response.data);
                updateBudgetSummary(response.data);
            } else {
                showError('Failed to load budget data');
            }
        },
        error: function() {
            $('#budgetTableBody').html('<tr><td colspan="5" class="text-center py-4 text-danger">Error loading budget data</td></tr>');
        }
    });
}

function renderBudgetTable(data) {
    if (!data || data.length === 0) {
        $('#budgetTableBody').html(`
            <tr><td colspan="5" class="text-center py-5">
                <i class="bi bi-inbox text-muted fs-1"></i>
                <p class="mt-3 text-muted">No budget set for this month</p>
                <button class="btn btn-primary btn-sm" onclick="loadBudgetToModal()"><i class="bi bi-plus-circle"></i> Set Budget</button>
            </td></tr>
        `);
        return;
    }
    
    let html = '';
    data.forEach(item => {
        const utilization = item.allocated > 0 ? ((item.spent / item.allocated) * 100).toFixed(1) : 0;
        const statusClass = utilization > 90 ? 'bg-danger' : utilization > 75 ? 'bg-warning' : 'bg-success';
        
        html += `<tr>
            <td><span class="fw-bold">${item.category}</span></td>
            <td class="fw-bold">₱${formatNumber(item.allocated)}</td>
            <td>₱${formatNumber(item.spent)}</td>
            <td class="fw-bold ${item.remaining > 0 ? 'text-success' : 'text-danger'}">₱${formatNumber(item.remaining)}</td>
            <td><div class="d-flex align-items-center gap-2"><span class="badge ${statusClass}" style="min-width: 45px;">${utilization}%</span><div class="progress flex-grow-1" style="height: 6px;"><div class="progress-bar ${statusClass}" style="width: ${utilization}%"></div></div></div></td>
        </tr>`;
    });
    
    $('#budgetTableBody').html(html);
}

function updateBudgetSummary(data) {
    let totalAllocated = 0, totalSpent = 0;
    data.forEach(item => { totalAllocated += item.allocated; totalSpent += item.spent; });
    const totalRemaining = totalAllocated - totalSpent;
    $('#totalAllocated').text('₱' + formatNumber(totalAllocated));
    $('#totalSpent').text('₱' + formatNumber(totalSpent));
    $('#totalRemaining').text('₱' + formatNumber(totalRemaining));
}

function loadBudgetToModal() {
    const month = $('#monthSelector').val() || new Date().toISOString().slice(0, 7);
    $('#budgetMonth').val(month);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: { action: 'get_budget', month: month },
        success: function(response) {
            if (response.success) {
                $('.budget-amount').val('');
                response.data.forEach(item => {
                    if (item.category === 'Equipment') $('#budgetEquipment').val(item.allocated);
                    if (item.category === 'Office Supplies') $('#budgetOffice').val(item.allocated);
                    if (item.category === 'Medical Equipment') $('#budgetMedical').val(item.allocated);
                    if (item.category === 'Optical Supplies') $('#budgetOptical').val(item.allocated);
                    if (item.category === 'Maintenance') $('#budgetMaintenance').val(item.allocated);
                    if (item.category === 'Utilities') $('#budgetUtilities').val(item.allocated);
                    if (item.category === 'Marketing') $('#budgetMarketing').val(item.allocated);
                    if (item.category === 'Rent') $('#budgetRent').val(item.allocated);
                    if (item.category === 'Salaries') $('#budgetSalaries').val(item.allocated);
                    if (item.category === 'Training') $('#budgetTraining').val(item.allocated);
                    if (item.category === 'Travel') $('#budgetTravel').val(item.allocated);
                    if (item.category === 'Other') $('#budgetOthers').val(item.allocated);
                });
                updateBudgetTotal();
            }
            $('#budgetModal').modal('show');
        }
    });
}

function saveBudget() {
    const month = $('#budgetMonth').val();
    const budgets = [
        { category: 'Office Supplies', amount: $('#budgetOffice').val() || 0 },
        { category: 'Medical Equipment', amount: $('#budgetMedical').val() || 0 },
        { category: 'Optical Supplies', amount: $('#budgetOptical').val() || 0 },
        { category: 'Maintenance', amount: $('#budgetMaintenance').val() || 0 },
        { category: 'Utilities', amount: $('#budgetUtilities').val() || 0 },
        { category: 'Other', amount: $('#budgetOthers').val() || 0 },
        { category: 'Equipment', amount: $('#budgetEquipment').val() || 0 },
        { category: 'Marketing', amount: $('#budgetMarketing').val() || 0 },
        { category: 'Rent', amount: $('#budgetRent').val() || 0 },
        { category: 'Salaries', amount: $('#budgetSalaries').val() || 0 },
        { category: 'Training', amount: $('#budgetTraining').val() || 0 },
        { category: 'Travel', amount: $('#budgetTravel').val() || 0 }
    ];
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: { action: 'save_budget', month: month, budgets: JSON.stringify(budgets) },
        success: function(response) {
            if (response.success) {
                Swal.fire({ icon: 'success', title: 'Budget Saved!', timer: 1500, showConfirmButton: false }).then(() => {
                    $('#budgetModal').modal('hide');
                    loadBudgetData();
                });
            } else {
                Swal.fire('Error', response.error || 'Failed to save budget', 'error');
            }
        },
        error: function(xhr) {
            console.error('Save error:', xhr.responseText);
            Swal.fire('Error', 'Server error', 'error');
        }
    });
}

function updateBudgetTotal() {
    let total = 0;
    $('.budget-amount').each(function() { total += parseFloat($(this).val()) || 0; });
    $('#totalBudgetDisplay').text('₱' + formatNumber(total));
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatNumber(num) {
    return parseFloat(num || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function truncate(str, length) {
    if (!str) return '';
    return str.length > length ? str.substring(0, length) + '...' : str;
}

function getPriorityClass(priority) {
    switch(priority) {
        case 'Critical': return 'priority-critical';
        case 'High': return 'priority-high';
        case 'Medium': return 'priority-medium';
        case 'Low': return 'priority-low';
        default: return 'priority-medium';
    }
}

function showError(message) {
    console.error(message);
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'Error', text: message, timer: 3000, showConfirmButton: false });
    }
}

function refreshPendingPRs() {
    loadPendingPRs();
}

function exportExpenses() {
    window.location.href = `api/expenses.php?action=export&month=${currentMonth}`;
}

function downloadExpense(id) {
    Swal.fire({ icon: 'info', title: 'Download', text: `Downloading expense #${id} details...`, timer: 1500, showConfirmButton: false });
}

function initializeDataTables() {
    // Initialize DataTables if needed
}

// ============================================
// LOAD PERMISSIONS - Already from PHP
// ============================================
function loadPermissions() {
    console.log('RBAC Permissions loaded from PHP:', userPermissions);
    
    // Apply permission-based UI
    applyPermissionBasedUI();
    
    // Load expense data if user has view permission
    if (userPermissions.can_view) {
        loadStats();
        loadExpenses('pending');
        loadExpenses('all');
        loadExpenses('paid');
        loadExpenses('overdue');
        loadBudgetData();
    }
    
    // ALWAYS LOAD PR DATA - but show/hide based on permission
    console.log('Loading PR data');
    loadPendingPRs();
}

function applyPermissionBasedUI() {
    // Add Expense Button
    if (userPermissions.can_create) {
        $('#addExpenseBtn').show();
    } else {
        $('#addExpenseBtn').hide();
    }
    
    // Set Budget Button
    if (userPermissions.can_create) {
        $('button[onclick="handleSetBudget()"]').show();
    } else {
        $('button[onclick="handleSetBudget()"]').hide();
    }
    
    // Export Button
    if (userPermissions.can_export) {
        $('button[onclick="exportExpenses()"]').show();
    } else {
        $('button[onclick="exportExpenses()"]').hide();
    }
    
    // ✅ PR Tab - show/hide based on permissions
    // Users with approve permission can approve PRs
    // Users with view permission can see PRs (read-only)
    if (userPermissions.can_approve) {
        // Can approve PRs
        $('#prTabContainer').show();
        $('.approve-pr-btn').prop('disabled', false);
    } else if (userPermissions.can_view) {
        // View only - can see but not approve
        $('#prTabContainer').show();
        $('.approve-pr-btn').prop('disabled', true);
        $('.approve-pr-btn').attr('title', 'You need approve permission to approve PRs');
    } else {
        // No permission - hide tab
        $('#prTabContainer').hide();
        const pendingTab = new bootstrap.Tab(document.getElementById('pending-tab'));
        pendingTab.show();
    }
    
    // Hide expense cards if no view permission
    if (!userPermissions.can_view) {
        $('.expense-card').hide();
    } else {
        $('.expense-card').show();
    }
}
function viewPRDetails(prId) {
    // ✅ Check if user has permission to view
    if (!userPermissions.can_view && !userPermissions.can_approve_pr) {
        showNoPermissionWarning('view purchase request details');
        return;
    }
    
    // Show loading
    Swal.fire({
        title: 'Loading PR Details...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    // ✅ Use expenses API instead of purchase_request.php
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_pr_details',
            id: prId
        },
        success: function(response) {
            Swal.close();
            if (response.success) {
                showPRDetailsModal(response.data);
            } else {
                Swal.fire('Error', response.error || 'Failed to load PR details', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            console.error('Error loading PR details:', error);
            Swal.fire('Error', 'Network error: ' + error, 'error');
        }
    });
}
function showPRDetailsModal(pr) {
    console.log('PR Data:', pr);
    
    const items = pr.items || [];
    let itemsHtml = '';
    let totalAmount = parseFloat(pr.total_amount || 0);
    
    // Determine category based on department
    const categoryMap = {
        'SCM': 'Office Supplies',
        'Optical': 'Equipment',
        'Clinic': 'Equipment',
        'Admin': 'Office Supplies',
        'Pharmacy': 'Medical Supplies',
        'Laboratory': 'Equipment'
    };
    const expenseCategory = categoryMap[pr.department] || 'Other';
    
    // Get current month for budget check
    const currentDate = new Date();
    const currentMonth = currentDate.getFullYear() + '-' + String(currentDate.getMonth() + 1).padStart(2, '0');
    
    // ✅ FIXED: Items table with proper HTML structure
    items.forEach(item => {
        // Supplier details
        const supplierContact = item.supplier_contact ? `<br><span class="text-secondary small">${escapeHtml(item.supplier_contact)}</span>` : '';
        const supplierEmail = item.supplier_email ? `<br><span class="text-secondary small">${escapeHtml(item.supplier_email)}</span>` : '';
        const supplierPhone = item.supplier_mobile ? `<br><span class="text-secondary small">${escapeHtml(item.supplier_mobile)}</span>` : '';
        
        itemsHtml += `
            <tr>
                <td data-label="Item Name" class="py-2">${escapeHtml(item.item_name)}</td>
                <td data-label="Description" class="py-2 text-secondary">${escapeHtml(item.description || '—')}</td>
                <td data-label="Qty" class="py-2 text-center">${item.quantity}</td>
                <td data-label="Unit Price" class="py-2 text-end">₱${formatNumber(item.unit_price)}</td>
                <td data-label="Total" class="py-2 text-end fw-medium">₱${formatNumber(item.total_price)}</td>
                <td data-label="Supplier" class="py-2">
                    <div class="fw-medium">${escapeHtml(item.supplier_name || '—')}</div>
                    ${supplierContact}
                    ${supplierEmail}
                    ${supplierPhone}
                </td>
            </tr>
        `;
    });
    
    // BUDGET CHECK - only show if user has permission
    let budgetCheckHtml = '';
    if (userPermissions.can_approve_pr) {
        budgetCheckHtml = `
            <div class="mb-4" id="budgetCheckCard">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-light text-secondary">Budget Check</span>
                    <span class="badge bg-light text-dark px-3 py-2 fw-light">${escapeHtml(expenseCategory)}</span>
                </div>
                <div class="text-center py-4 bg-light rounded-3">
                    <div class="spinner-border text-secondary" style="width: 2rem; height: 2rem;"></div>
                    <p class="mt-2 text-muted small fw-light">checking budget...</p>
                </div>
            </div>
        `;
    }
    
    const canApprove = userPermissions.can_approve;
    
    const modalHtml = `
        <div class="modal fade" id="prDetailsModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    
                    <!-- HEADER -->
                    <div class="modal-header py-3" style="border-bottom: 2px solid #008080;">
                        <div>
                            <span class="badge bg-light text-dark fw-light mb-1 px-3 py-2">${escapeHtml(pr.pr_number)}</span>
                            <h5 class="modal-title fw-light mt-2">Purchase Request Details</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <!-- BODY -->
                    <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                        
                        <!-- REQUEST INFO GRID -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Department</small>
                                    <span class="fw-light fs-6">${escapeHtml(pr.department)}</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Priority</small>
                                    <span class="badge ${getPriorityClass(pr.priority)} px-3 py-2 fw-light">${escapeHtml(pr.priority)}</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Requested By</small>
                                    <span class="fw-light">${escapeHtml(pr.requested_by_name || 'Unknown')}</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Date Requested</small>
                                    <span class="fw-light">${new Date(pr.created_at).toLocaleDateString()}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PURPOSE -->
                        <div class="mb-4">
                            <small class="text-secondary d-block fw-light mb-2">Purpose</small>
                            <div class="bg-light p-3 rounded-3">
                                <p class="mb-0 fw-light">${escapeHtml(pr.purpose)}</p>
                            </div>
                        </div>
                        
                        <!-- BUDGET CHECK -->
                        ${budgetCheckHtml}
                        
                        <!-- REQUESTED ITEMS TABLE -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-light text-secondary">Requested Items</span>
                                <span class="fw-light fs-5" style="color: #008080;">₱${formatNumber(pr.total_amount)}</span>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px;">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light" style="position: sticky; top: 0; z-index: 1;">
                                        <tr>
                                            <th class="fw-light py-2" style="width: 20%;">Item Name</th>
                                            <th class="fw-light py-2" style="width: 20%;">Description</th>
                                            <th class="fw-light py-2 text-center" style="width: 8%;">Qty</th>
                                            <th class="fw-light py-2 text-end" style="width: 12%;">Unit Price</th>
                                            <th class="fw-light py-2 text-end" style="width: 12%;">Total</th>
                                            <th class="fw-light py-2" style="width: 28%;">Supplier</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml || '<tr><td colspan="6" class="text-center py-4 text-muted fw-light">No items found</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Grand total footer -->
                            <div class="d-flex justify-content-end mt-3 pt-2" style="border-top: 1px dashed #dee2e6;">
                                <div style="width: 300px;">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-light">GRAND TOTAL</span>
                                        <span class="fw-light" style="color: #008080;">₱${formatNumber(pr.total_amount)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NOTES -->
                        ${pr.notes ? `
                        <div class="mb-4">
                            <small class="text-secondary d-block fw-light mb-2">Notes</small>
                            <div class="bg-light p-3 rounded-3">
                                <p class="mb-0 small fw-light">${escapeHtml(pr.notes)}</p>
                            </div>
                        </div>
                        ` : ''}
                        
                        <!-- ACTION BUTTONS - Only show if user can approve -->
                        ${canApprove ? `
                        <div class="d-flex gap-3 pt-4 mt-2" style="border-top: 2px solid #f0f0f0;">
                            <button class="btn flex-fill py-2 text-white border-0 rounded-pill" 
                                    style="background-color: #008080;" 
                                    onclick="approveFromDetails(${pr.id}, '${pr.pr_number}', ${pr.total_amount})">
                                <i class="bi bi-check-lg me-2"></i>Approve Request
                            </button>
                            <button class="btn btn-outline-secondary flex-fill py-2 rounded-pill" 
                                    style="color: #dc3545; border-color: #dc3545;" 
                                    onclick="rejectFromDetails(${pr.id}, '${pr.pr_number}')">
                                <i class="bi bi-x-lg"></i>Reject Request
                            </button>
                        </div>
                        ` : `
                        <div class="alert alert-info text-center mt-4">
                            <i class="bi bi-info-circle me-2"></i>
                            You have view-only access. Contact Finance/Admin to approve this request.
                        </div>
                        `}
                        
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    $('#prDetailsModal').remove();
    $('body').append(modalHtml);
    $('#prDetailsModal').modal('show');
    
    // Check budget if user has permission
    if (userPermissions.can_approve_pr) {
        checkBudgetForPR(expenseCategory, totalAmount, currentMonth);
    }
    
    $('#prDetailsModal').on('hidden.bs.modal', function() { 
        $(this).remove(); 
    });
}

function checkBudgetForPR(category, amount, month) {
    console.log('Checking budget for:', { category, amount, month });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_budget',
            month: month
        },
        success: function(response) {
            console.log('Budget response:', response);
            
            if (response.success) {
                if (response.data && response.data.length > 0) {
                    displayBudgetCheck(response.data, category, amount);
                } else {
                    $('#budgetCheckCard').html(`
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-medium text-secondary">Budget Check</span>
                            <span class="badge bg-light text-dark px-3 py-2">${escapeHtml(category)}</span>
                        </div>
                        <div class="alert alert-info mb-0 py-3">
                            <i class="bi bi-info-circle me-2"></i>
                            No budget set for this month
                        </div>
                    `);
                }
            } else {
                $('#budgetCheckCard').html(`
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-medium text-secondary">Budget Check</span>
                        <span class="badge bg-light text-dark px-3 py-2">${escapeHtml(category)}</span>
                    </div>
                    <div class="alert alert-warning mb-0 py-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Unable to load budget data
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('Budget check error:', error);
            $('#budgetCheckCard').html(`
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-medium text-secondary">Budget Check</span>
                    <span class="badge bg-light text-dark px-3 py-2">${escapeHtml(category)}</span>
                </div>
                <div class="alert alert-danger mb-0 py-3">
                    <i class="bi bi-x-circle me-2"></i>
                    Error checking budget
                </div>
            `);
        }
    });
}
function displayBudgetCheck(budgetData, prCategory, prAmount) {
    console.log('Displaying budget for:', { prCategory, prAmount, budgetData }); // Debug
    
    // Find budget for this category
    const categoryBudget = budgetData.find(b => b.category === prCategory) || { 
        allocated: 0, 
        spent: 0, 
        remaining: 0 
    };
    
    console.log('Category budget:', categoryBudget); // Debug
    
    const allocated = parseFloat(categoryBudget.allocated || 0);
    const spent = parseFloat(categoryBudget.spent || 0);
    const remaining = parseFloat(categoryBudget.remaining || 0);
    const prAmountNum = parseFloat(prAmount || 0);
    
    const wouldRemain = remaining - prAmountNum;
    const isWithinBudget = wouldRemain >= 0;
    
    // Calculate percentages (avoid division by zero)
    const spentPercent = allocated > 0 ? (spent / allocated * 100) : 0;
    const wouldSpentPercent = allocated > 0 ? ((spent + prAmountNum) / allocated * 100) : 0;
    
    // Determine status
    let statusColor = '#6c757d'; // gray
    let statusText = 'No Budget Set';
    let statusIcon = 'bi-dash-circle';
    
    if (allocated === 0 && spent > 0) {
        statusColor = '#dc3545'; // red
        statusText = 'No Budget, Has Spendings';
        statusIcon = 'bi-exclamation-triangle';
    } else if (allocated > 0) {
        if (!isWithinBudget) {
            statusColor = '#dc3545'; // red
            statusText = 'Exceeds Budget';
            statusIcon = 'bi-exclamation-triangle';
        } else if (wouldSpentPercent > 80) {
            statusColor = '#ffc107'; // yellow
            statusText = 'Near Budget Limit';
            statusIcon = 'bi-exclamation-circle';
        } else {
            statusColor = '#198754'; // green
            statusText = 'Within Budget';
            statusIcon = 'bi-check-circle';
        }
    }
    
    const html = `
        <div class="row g-3">
            <!-- Left - Summary -->
            <div class="col-md-5">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Monthly Budget</span>
                        <span class="fw-semibold">₱${formatNumber(allocated)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Spent so far</span>
                        <span class="fw-semibold ${spent > 0 ? 'text-danger' : ''}">₱${formatNumber(spent)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-secondary">Current Remaining</span>
                        <span class="fw-semibold ${remaining < 0 ? 'text-danger' : 'text-success'}">₱${formatNumber(remaining)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top">
                        <span class="fw-medium">This Request</span>
                        <span class="fw-semibold text-primary">₱${formatNumber(prAmountNum)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-2">
                        <span class="fw-medium">Would Remain</span>
                        <span class="fw-semibold ${wouldRemain < 0 ? 'text-danger' : 'text-success'}">
                            ₱${formatNumber(wouldRemain)}
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Right - Status & Progress -->
            <div class="col-md-7">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge px-3 py-2" 
                              style="background-color: ${statusColor}20; color: ${statusColor}; border: 1px solid ${statusColor}40;">
                            <i class="bi ${statusIcon} me-1"></i>
                            ${statusText}
                        </span>
                    </div>
                    
                    ${allocated > 0 ? `
                        <!-- Current Progress -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-secondary">Current spent</span>
                                <span>${spentPercent.toFixed(1)}%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar ${spentPercent > 100 ? 'bg-danger' : 'bg-secondary'}" 
                                     style="width: ${Math.min(spentPercent, 100)}%"></div>
                            </div>
                            <small class="text-muted">₱${formatNumber(spent)} of ₱${formatNumber(allocated)}</small>
                        </div>
                        
                        <!-- Projected Progress -->
                        <div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-secondary">If approved</span>
                                <span>${wouldSpentPercent.toFixed(1)}%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar ${wouldSpentPercent > 100 ? 'bg-danger' : 'bg-primary'}" 
                                     style="width: ${Math.min(wouldSpentPercent, 100)}%"></div>
                            </div>
                            <small class="text-muted">₱${formatNumber(spent + prAmountNum)} total</small>
                        </div>
                    ` : `
                        <!-- No budget set -->
                        <div class="alert alert-warning mb-0 py-2 small">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            No budget allocated for this category
                            ${spent > 0 ? `<br><span class="text-danger">But has ₱${formatNumber(spent)} spendings</span>` : ''}
                        </div>
                    `}
                </div>
            </div>
        </div>
    `;
    
    $('#budgetCheckCard').html(`
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-medium text-secondary">Budget Check</span>
            <span class="badge bg-light text-dark px-3 py-2">${prCategory}</span>
        </div>
        ${html}
    `);
}

// ============================================
// PERMISSION-BASED HANDLERS
// ============================================

function handleAddExpense() {
    if (!userPermissions.can_create) {
        showNoPermissionWarning('create expenses');
        return;
    }
    addNewExpense();
}

function handleSetBudget() {
    if (!userPermissions.can_create) {
        showNoPermissionWarning('manage budget');
        return;
    }
    loadBudgetToModal();
}

function showNoPermissionWarning(action = 'perform this action') {
    Swal.fire({
        icon: 'warning',
        title: 'Access Denied',
        text: `You do not have permission to ${action}.`,
        confirmButtonColor: '#4361ee',
        confirmButtonText: 'OK'
    });
}

// ✅ I-override ang approveExpense para may permission check
function approveExpense(id) {
    if (!userPermissions.can_create) {
        showNoPermissionWarning('approve expenses');
        return;
    }
    originalApproveExpense(id);
}



function openPaymentModal(id) {
    // First, get expense details
    $.ajax({
        url: `api/expenses.php?action=get_expense&id=${id}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                const expense = response.data;
                
                console.log('Expense data:', expense);
                
                // Populate payment modal
                $('#paymentExpenseId').val(expense.id);
                $('#paymentExpenseCode').text(expense.expense_code);
                
let rawAmount = 0;
if (typeof expense.amount === 'number') {
    rawAmount = expense.amount;
} else if (typeof expense.amount === 'string') {
    rawAmount = parseFloat(expense.amount.replace(/[₱,]/g, '').trim());
}

console.log('rawAmount computed:', rawAmount); // ← add this
$('#paymentAmount').text(formatCurrency(rawAmount));
$('#paymentAmountRaw').val(rawAmount);    // ← CHANGE THIS LINE
                
                $('#paymentVendor').text(expense.vendor || 'N/A');
                
                // Auto-fetch vendor details
                let vendorEmail = '';
                let vendorName = '';
                let vendorDescription = '';
// Check supplier from items first (PR-based expenses)
if (expense.items && expense.items.length > 0) {
    const firstItem = expense.items[0];
    if (firstItem.supplier_email) vendorEmail = firstItem.supplier_email;
    if (firstItem.supplier_name) vendorName = firstItem.supplier_name;
}

// Check suppliers array (another format)
if (!vendorEmail && expense.suppliers && expense.suppliers.length > 0) {
    const firstSupplier = expense.suppliers[0];
    if (firstSupplier.email) vendorEmail = firstSupplier.email;
    if (firstSupplier.name && !vendorName) vendorName = firstSupplier.name;
}

// Fallback to vendor fields on expense itself
if (!vendorName && expense.vendor) vendorName = expense.vendor;
if (!vendorEmail && expense.vendor_email) vendorEmail = expense.vendor_email;

// Auto-populate vendor contact display
$('#paymentVendor').text(
    vendorName + (expense.vendor_contact ? ' — ' + expense.vendor_contact : '')
);
                
                // Build description
                vendorDescription = `Payment for ${expense.expense_code}`;
                if (expense.description) {
                    vendorDescription += ` - ${expense.description.substring(0, 50)}`;
                }
                
                // Auto-populate PayMongo fields
                $('#paymongoEmail').val(vendorEmail);
                $('#paymongoName').val(vendorName);
                $('#paymongoDescription').val(vendorDescription);
                
                // Reset form
                $('#paymentMethodSelect').val('');
                $('#paymongoSection').hide();
                $('#regularPaymentFields').show();
                $('#paymentLinkContainer').hide();
                $('#paymentDate').val(new Date().toISOString().split('T')[0]);
                $('#paymentRef').val('');
                $('#paymentProof').val('');
                $('#paymentRemarks').val('');
                
                // Re-attach change event for payment method
                $('#paymentMethodSelect').off('change').on('change', function() {
                    const method = $(this).val();
                    const paymongoMethods = ['GCash', 'PayMaya', 'ATM', 'Card'];
                    const amount = $('#paymentAmount').data('raw') || 0;
                    
                    console.log('Payment method changed to:', method);
                    
                    if (paymongoMethods.includes(method)) {
                        $('#paymongoSection').show();
                        $('#regularPaymentFields').hide();
                        $('#paymentLinkContainer').hide();
                    } else {
                        $('#paymongoSection').hide();
                        $('#regularPaymentFields').show();
                        $('#paymentLinkContainer').hide();
                    }
                });
                
                // Show modal
                $('#paymentModal').modal('show');
            } else {
                Swal.fire('Error!', 'Failed to load expense details', 'error');
            }
        },
        error: function(xhr) {
            console.error('Error loading expense:', xhr);
            Swal.fire('Error!', 'Failed to load expense details', 'error');
        }
    });
}


function deleteExpense(id) {
    if (!userPermissions.can_delete) {
        showNoPermissionWarning('delete expenses');
        return;
    }
    Swal.fire({
        title: 'Delete Expense?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef476f',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_expense');
            formData.append('id', id);
            
            $.ajax({
                url: 'api/expenses.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            loadExpenses('all');
                            loadStats();
                        });
                    } else {
                        Swal.fire('Error!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error!', 'Failed to delete expense', 'error');
                }
            });
        }
    });
}

function approveFromDetails(prId, prNumber, totalAmount) {
    if (!userPermissions.can_approve_pr) {
        showNoPermissionWarning('approve purchase requests');
        return;
    }
    
    // Close PR details modal
    const prModal = bootstrap.Modal.getInstance(document.getElementById('prDetailsModal'));
    if (prModal) {
        prModal.hide();
    }
    
    setTimeout(() => {
        Swal.fire({
            title: 'Approve Purchase Request',
            html: `
                <div class="text-start">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-file-text me-2"></i>
                        <strong>PR Number:</strong> ${escapeHtml(prNumber)}<br>
                        <strong>Total Amount:</strong> ₱${formatNumber(totalAmount)}
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea id="approveRemarks" class="form-control" rows="2" 
                                  placeholder="Add any remarks..."></textarea>
                    </div>
                    <div class="alert alert-warning small">
                        <i class="bi bi-exclamation-triangle"></i>
                        This will create an expense record and deduct from budget.
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve',
            confirmButtonColor: '#008080',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                return {
                    remarks: document.getElementById('approveRemarks').value || ''
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                processApprovePR(prId, result.value.remarks);
            }
        });
    }, 300);
}

function processApprovePR(prId, remarks) {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'approve_pr');
    formData.append('pr_id', prId);
    formData.append('convert_to_expense', 'yes');
    if (remarks) formData.append('remarks', remarks);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Approved!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    loadPendingPRs();
                    loadStats();
                    loadBudgetData();
                    loadExpenses('pending');
                    loadExpenses('all');
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to approve PR', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}
function rejectFromDetails(prId, prNumber) {
    if (!userPermissions.can_approve_pr) {
        showNoPermissionWarning('reject purchase requests');
        return;
    }
    
    // Close PR details modal
    const prModal = bootstrap.Modal.getInstance(document.getElementById('prDetailsModal'));
    if (prModal) {
        prModal.hide();
    }
    
    setTimeout(() => {
        Swal.fire({
            title: 'Reject Purchase Request',
            html: `
                <div class="text-start">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>PR Number:</strong> ${escapeHtml(prNumber)}
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for rejection <span class="text-danger">*</span></label>
                        <textarea id="rejectReason" class="form-control" rows="4" 
                                  placeholder="Enter detailed reason for rejection..."></textarea>
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const textarea = document.getElementById('rejectReason');
                if (!textarea) {
                    Swal.showValidationMessage('Please provide a reason for rejection');
                    return false;
                }
                
                const reason = textarea.value;
                console.log('Reason entered:', reason);
                
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('Please provide a reason for rejection');
                    return false;
                }
                return reason.trim();
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                console.log('Final reason to send:', result.value);
                processRejectPR(prId, result.value);
            }
        });
    }, 300);
}
function processRejectPR(prId, reason) {
    console.log('processRejectPR called with:', { prId, reason }); // DEBUG
    
    if (!prId || !reason) {
        console.error('Missing data:', { prId, reason });
        Swal.fire('Error!', 'Missing PR ID or reason', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Processing...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'reject_pr');
    formData.append('pr_id', prId);
    // ✅ FIXED: Use 'rejection_reason' (as required by backend)
    formData.append('rejection_reason', reason);
    
    // DEBUG: Log what we're sending
    console.log('Sending reject request:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            console.log('Success response:', response);
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Rejected!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    loadPendingPRs();
                    loadStats();
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to reject PR', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            console.error('Reject error details:', {
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText
            });
            
            let errorMsg = 'Server error';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMsg = response.error || response.message || errorMsg;
            } catch(e) {
                errorMsg = xhr.responseText || errorMsg;
            }
            
            Swal.fire('Error!', errorMsg, 'error');
        }
    });
}
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add this if not already present
function truncate(str, length) {
    if (!str) return '';
    return str.length > length ? str.substring(0, length) + '...' : str;
}

function formatNumber(num) {
    return parseFloat(num || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function getPriorityClass(priority) {
    switch(priority) {
        case 'Critical': return 'priority-critical';
        case 'High': return 'priority-high';
        case 'Medium': return 'priority-medium';
        case 'Low': return 'priority-low';
        default: return 'priority-medium';
    }
}

function showError(message) {
    console.error(message);
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'Error', text: message, timer: 3000, showConfirmButton: false });
    }
}

function showNoPermissionWarning(action = 'perform this action') {
    Swal.fire({
        icon: 'warning',
        title: 'Access Denied',
        text: `You do not have permission to ${action}.`,
        confirmButtonColor: '#4361ee',
        confirmButtonText: 'OK'
    });
}

function processPayment() {
    const expenseId = $('#paymentExpenseId').val();
    const method = $('#paymentMethodSelect').val();
    const paymentDate = $('#paymentDate').val();
    const paymentRef = $('#paymentRef').val();
    const remarks = $('#paymentRemarks').val();
    const proofFile = $('#paymentProof')[0].files[0];
    
    // Validate required fields
    if (!method) {
        Swal.fire('Error!', 'Please select payment method', 'error');
        return;
    }
    
    if (!paymentDate) {
        Swal.fire('Error!', 'Please select payment date', 'error');
        return;
    }
    
const paymongoMethods = ['GCash', 'PayMaya', 'ATM', 'Card'];
if (paymongoMethods.includes(method)) {
    redirectToPayMongo();
    return;
}
    
    // Show loading
    Swal.fire({
        title: 'Processing Payment...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'approve_expense');
    formData.append('id', expenseId);
    formData.append('payment_method', method);
    formData.append('payment_date', paymentDate);
    formData.append('payment_reference', paymentRef || '');
    if (remarks) formData.append('remarks', remarks);
    if (proofFile) formData.append('proof_file', proofFile);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Processed!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    $('#paymentModal').modal('hide');
                    // Reset paymongo section
                    $('#paymongoSection').hide();
                    $('#regularPaymentFields').show();
                    loadExpenses('pending');
                    loadExpenses('all');
                    loadExpenses('paid');
                    loadStats();
                    loadBudgetData();
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to process payment', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            console.error('Payment error:', xhr);
            let errorMsg = 'Failed to process payment';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMsg = response.error || errorMsg;
            } catch(e) {}
            Swal.fire('Error!', errorMsg, 'error');
        }
    });
}
// ============================================
// UTILITY FUNCTIONS
// ============================================
function formatCurrency(amount) {
    if (amount === null || amount === undefined) return '₱0.00';
    const num = parseFloat(amount);
    if (isNaN(num)) return '₱0.00';
    return '₱' + num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatNumber(num) {
    return parseFloat(num || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function truncate(str, length) {
    if (!str) return '';
    return str.length > length ? str.substring(0, length) + '...' : str;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function redirectToPayMongo() {
    const expenseId   = $('#paymentExpenseId').val();
    const amount      = parseFloat($('#paymentAmountRaw').val());
    const method      = $('#paymentMethodSelect').val();
    const email       = $('#paymongoEmail').val().trim();
    const name        = $('#paymongoName').val().trim();
    const description = $('#paymongoDescription').val().trim();

    // Validations
    if (!email) {
        Swal.fire('Error!', 'Please enter recipient email', 'error');
        return;
    }
    if (!name) {
        Swal.fire('Error!', 'Please enter recipient name', 'error');
        return;
    }

    Swal.fire({
        title: 'Redirecting to PayMongo...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: 'api/paymongo.php',
        method: 'POST',
        data: {
            action:         'create_checkout',
            expense_id:     expenseId,
            amount:         amount,
            payment_method: method,
            description:    description,
            name:           name,
            email:          email
        },
        success: function(response) {
            Swal.close();
            if (response.success && response.checkout_url) {
                // Show summary before redirect
                let amountHtml = `<strong>₱${parseFloat(response.total_amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</strong>`;
                if (response.shipping_fee > 0) {
                    amountHtml += `<br><small class="text-muted">Includes ₱${parseFloat(response.shipping_fee).toLocaleString('en-PH', {minimumFractionDigits:2})} shipping fee</small>`;
                }

                Swal.fire({
                    title: 'Proceed to Payment?',
                    html: `
                        <div class="text-start">
                            <div class="alert alert-info">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Amount:</span>${amountHtml}
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Method:</span><strong>${method}</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Payee:</span><strong>${escapeHtml(name)}</strong>
                                </div>
                            </div>
                            <p class="text-muted small">You will be redirected to PayMongo's secure checkout page.</p>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="bi bi-credit-card me-1"></i> Proceed to Checkout',
                    confirmButtonColor: '#008080',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // REDIRECT to PayMongo checkout
                        window.location.href = response.checkout_url;
                    }
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to create checkout session', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}
function switchToCash() {
    $('#paymentMethodSelect').val('Cash').trigger('change');
    $('#paymongoSection').hide();
    $('#regularPaymentFields').show();
    $('#paymentLinkContainer').hide();
    Swal.close();
}

function switchToBankTransfer() {
    $('#paymentMethodSelect').val('Bank Transfer').trigger('change');
    $('#paymongoSection').hide();
    $('#regularPaymentFields').show();
    $('#paymentLinkContainer').hide();
    Swal.close();
}

function copyPaymentLink() {
    const linkInput = document.getElementById('paymentLinkField') || document.getElementById('paymentLink');
    if (linkInput) {
        linkInput.select();
        document.execCommand('copy');
        
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'Payment link copied to clipboard',
            timer: 1500,
            showConfirmButton: false
        });
    }
}
</script>
</body>
</html>