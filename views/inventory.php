<?php
// views/inventory.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

RBACHelper::init($pdo);

$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('supply_chain')) {
    header('Location: ../views/subscription.php');
    exit;
}

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!RBACHelper::hasPermission('inventory_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Inventory Management.</p>
        </div>
    </div>
    <?php
    exit;
}

$canView   = RBACHelper::hasPermission('inventory_view');
$canCreate = RBACHelper::hasPermission('inventory_create');
$canEdit   = RBACHelper::hasPermission('inventory_edit');
$canDelete = RBACHelper::hasPermission('inventory_delete');
$canApprove= RBACHelper::hasPermission('inventory_approve');
$canReject = RBACHelper::hasPermission('inventory_reject');

$clinicId = $_SESSION['clinic_id'];

try {
    $totalItems     = $pdo->query("SELECT COUNT(*) FROM inventory WHERE clinic_id = $clinicId AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
    $lowStockItems  = $pdo->query("SELECT COUNT(*) FROM inventory WHERE clinic_id = $clinicId AND stock <= reorder_level AND stock > 0 AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
    $outOfStockItems= $pdo->query("SELECT COUNT(*) FROM inventory WHERE clinic_id = $clinicId AND stock <= 0 AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
    $totalValue     = $pdo->query("SELECT SUM(selling_price * stock) FROM inventory WHERE clinic_id = $clinicId AND selling_price IS NOT NULL AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $totalItems = $lowStockItems = $outOfStockItems = $totalValue = 0;
}

$categories = ['Frames','Eyeglasses','Sunglasses','Contact Lenses','Lenses','Accessories'];
$search = $_GET['search'] ?? '';

$suppliers = [];
try {
    $supplierStmt = $pdo->prepare("SELECT id, supplier_name, contact_person, email, mobile, city FROM suppliers WHERE status = 'Active' ORDER BY supplier_name ASC");
    $supplierStmt->execute();
    $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }
?>

<div class="container-fluid px-4">
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Inventory Management</h1>
            <p class="text-muted">Manage clinic supplies, track stock levels, and process sales</p>
        </div>
        <?php if ($canCreate): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-circle me-1"></i>Add Item
        </button>
        <?php endif; ?>
    </div>

    <!-- STATS -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2 stat-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Items</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statTotalItems"><?= $totalItems ?></div>
                        </div>
                        <div class="col-auto"><i class="bi bi-box-seam fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2 stat-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Low Stock Items</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statLowStock"><?= $lowStockItems ?></div>
                        </div>
                        <div class="col-auto"><i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2 stat-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Out of Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statOutOfStock"><?= $outOfStockItems ?></div>
                        </div>
                        <div class="col-auto"><i class="bi bi-x-circle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2 stat-card">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="statTotalValue">₱<?= number_format($totalValue, 2) ?></div>
                        </div>
                        <div class="col-auto"><i class="bi bi-currency-dollar fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" id="searchInput"
                               placeholder="Search by name, item ID, brand, or type..."
                               value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-primary" onclick="loadInventoryData()">Search</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex gap-2">
                        <select class="form-select" id="categoryFilter" onchange="loadInventoryData()">
                            <option value="all">All Categories</option>
                            <?php foreach($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" id="statusFilter" onchange="loadInventoryData()" style="max-width:150px;">
                            <option value="all">All Status</option>
                            <option value="in-stock">In Stock</option>
                            <option value="low-stock">Low Stock</option>
                            <option value="out-of-stock">Out of Stock</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Inventory Items</h6>
            <?php if ($canView): ?>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-danger" onclick="showLowStockReport()">
                    <i class="bi bi-exclamation-triangle me-1"></i>Low Stock Report
                </button>
                <button class="btn btn-sm btn-outline-warning" onclick="showReorderSuggestions()">
                    <i class="bi bi-cart-plus me-1"></i>Reorder Suggestions
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Item ID</th><th>Name</th><th>Category</th><th>Brand/Type</th>
                            <th>Stock</th><th>Selling Price</th><th>Cost/Profit</th>
                            <th>Status</th><th>Supplier</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <tr><td colspan="10" class="text-center py-5">
                            <div class="spinner-border text-primary"></div>
                            <p class="mt-2 text-muted">Loading inventory data...</p>
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-muted" id="paginationInfo">Loading...</div>
                <nav><ul class="pagination pagination-sm justify-content-end" id="paginationContainer"></ul></nav>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- ADD ITEM MODAL -->
<!-- ============================================================ -->
<?php if ($canCreate): ?>
<div class="modal fade" id="addItemModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
        <div class="modal-content" style="max-height:90vh;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Inventory Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addItemForm">
                <div class="modal-body" style="max-height:calc(90vh - 130px);overflow-y:auto;">

                    <!-- Basic Info -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>Basic Information</h6></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                    <select class="form-select" name="category" id="addCategory" required onchange="toggleColorVariantsSection()">
                                        <option value="" disabled selected>-- Select Category --</option>
                                        <?php foreach($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-bold">Item Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" placeholder="Enter item name" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Brand</label>
                                    <input type="text" class="form-control" name="brand" placeholder="e.g., Ray-Ban, Oakley">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Type/Model</label>
                                    <input type="text" class="form-control" name="type" placeholder="e.g., Aviator, Progressive">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stock & Pricing -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="fas fa-tags me-2"></i>Stock & Pricing</h6></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold" id="stockLabel">Stock Quantity <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="stock" id="mainStockInput" value="0" min="0" required>
                                    <small class="text-muted" id="stockHelpText">Enter the total quantity</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Reorder Level <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="reorder_level" value="5" min="0" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Cost Price (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="cost" value="0" id="addCost">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Selling Price (₱) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control" name="selling_price" value="0" required id="addPrice">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Supplier Price (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="supplier_product_price" value="0">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Profit Preview</label>
                                    <div id="addProfitPreview" class="alert alert-success py-2 mb-0">Profit: ₱0.00 (0%)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="fas fa-truck me-2"></i>Supplier Information</h6></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-bold">Preferred Supplier</label>
                                    <select class="form-select" name="supplier_id" id="supplierSelect">
                                        <option value="">-- Select Supplier (Optional) --</option>
                                        <?php foreach($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['id'] ?>"
                                                data-contact="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                                                data-mobile="<?= htmlspecialchars($supplier['mobile'] ?? '') ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                            <?php if (!empty($supplier['city'])): ?>(<?= htmlspecialchars($supplier['city']) ?>)<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div id="supplierDetails" class="alert alert-info mt-2" style="display:none;">
                                <div class="row">
                                    <div class="col-md-4"><small><strong>Contact:</strong> <span id="supplierContact"></span></small></div>
                                    <div class="col-md-4"><small><strong>Email:</strong> <span id="supplierEmail"></span></small></div>
                                    <div class="col-md-4"><small><strong>Mobile:</strong> <span id="supplierMobile"></span></small></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ✅ PRODUCT DETAILS CARD (auto-rendered by JS) -->
                    <div id="productDetailsContainer_add" style="display:none;" class="mb-3"></div>

                    <!-- Color Variants -->
                    <div class="card mb-3 border-primary" id="colorVariantsCard" style="display:none;">
                        <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-palette me-2"></i>Color Variants (for 3D Viewer)</h6>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addColorRow()"><i class="fas fa-plus me-1"></i>Add Color</button>
                        </div>
                        <div class="card-body">
                            <div id="colorRowsContainer">
                                <div class="text-center text-muted py-4" id="noColorsMessage">
                                    <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                                    <p class="mb-2">No colors added yet.</p>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addColorRow()"><i class="fas fa-plus me-1"></i>Add Your First Color</button>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3" id="stockTotalAlert" style="display:none;">
                                <i class="fas fa-calculator me-2"></i>
                                <strong>Total Stock from Colors: <span id="totalStockFromColors">0</span></strong>
                                <br><small>The main stock field will be automatically updated with this total.</small>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- EDIT ITEM MODAL -->
<!-- ============================================================ -->
<?php if ($canEdit): ?>
<div class="modal fade" id="editItemModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
        <div class="modal-content" style="max-height:90vh;">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editItemForm">
                <div class="modal-body" style="max-height:calc(90vh - 130px);overflow-y:auto;">
                    <input type="hidden" name="id" id="editItemId">

                    <!-- Basic Info -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2"></i>Basic Information</h6></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Category</label>
                                    <select class="form-select" id="editCategory" name="category" required onchange="toggleEditColorVariantsSection()">
                                        <option value="" disabled>Select Category</option>
                                        <?php foreach($categories as $cat): ?>
                                        <option value="<?= $cat ?>"><?= $cat ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label fw-bold">Item Name</label>
                                    <input type="text" class="form-control" id="editName" name="name" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Brand</label>
                                    <input type="text" class="form-control" id="editBrand" name="brand">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Type/Model</label>
                                    <input type="text" class="form-control" id="editType" name="type">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stock & Pricing -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><h6 class="mb-0 fw-bold"><i class="fas fa-tags me-2"></i>Stock & Pricing</h6></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold" id="editStockLabel">Stock Quantity</label>
                                    <input type="number" class="form-control" id="editStock" name="stock" required>
                                    <small class="text-muted" id="editStockHelpText">Enter the total quantity</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Reorder Level</label>
                                    <input type="number" class="form-control" id="editReorderLevel" name="reorder_level" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Cost Price (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="editCost" name="cost" value="0.00">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Selling Price (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="editPrice" name="selling_price" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Supplier Price (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="editSupplierPrice" name="supplier_product_price" value="0.00">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Profit Preview</label>
                                    <div id="editProfitPreview" class="alert alert-success py-2 mb-0">Profit: ₱0.00 (0%)</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ✅ PRODUCT DETAILS CARD (auto-rendered by JS) -->
                    <div id="productDetailsContainer_edit" style="display:none;" class="mb-3"></div>

                    <!-- Edit Color Variants -->
                    <div class="card mb-3 border-primary" id="editColorVariantsCard" style="display:none;">
                        <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-palette me-2"></i>Color Variants (for 3D Viewer)</h6>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addEditColorRow()"><i class="fas fa-plus me-1"></i>Add Color</button>
                        </div>
                        <div class="card-body">
                            <div id="editColorRowsContainer">
                                <div class="text-center text-muted py-4" id="editNoColorsMessage">
                                    <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                                    <p class="mb-2">No colors added yet.</p>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addEditColorRow()"><i class="fas fa-plus me-1"></i>Add Your First Color</button>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3" id="editStockTotalAlert" style="display:none;">
                                <i class="fas fa-calculator me-2"></i>
                                <strong>Total Stock from Colors: <span id="editTotalStockFromColors">0</span></strong>
                                <br><small>The main stock field will be automatically updated with this total.</small>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- RESTOCK MODAL -->
<?php if ($canEdit): ?>
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Restock Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="restockForm">
                <div class="modal-body">
                    <input type="hidden" name="item_id" id="restockItemId">
                    <div class="alert alert-info">
                        <h6 id="restockItemName" class="mb-1 fw-bold">Loading item...</h6>
                        <div class="row mt-2">
                            <div class="col-6"><small>Current Stock:</small> <span id="currentStock" class="fw-bold ms-1">0</span></div>
                            <div class="col-6"><small>Reorder Level:</small> <span id="reorderLevel" class="fw-bold ms-1">0</span></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Quantity to Add</label>
                        <input type="number" class="form-control" name="quantity" min="1" value="10" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ============================================================
// PERMISSIONS
// ============================================================
const permissions = {
    canView:   <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit:   <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove:<?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};

// ============================================================
// PRODUCT DETAILS CONFIG (per category)
// ============================================================
const PRODUCT_DETAILS_CONFIG = {
    'Frames': {
        title: 'Frames Details', icon: 'bi-eyeglasses', color: 'primary',
        selects: [
            { name: 'frame_material', label: 'Frame Material', options: ['Acetate','Metal','Titanium','TR-90','Wood','Horn','Mixed'] },
            { name: 'frame_style',    label: 'Frame Shape',    options: ['Rectangle','Round','Oval','Square','Cat-Eye','Aviator','Geometric','Rimless','Semi-Rimless'] },
            { name: 'frame_type',     label: 'Frame Type',     options: ['Full Rim','Half Rim','Rimless'] },
            { name: 'gender',         label: 'Gender',         options: ['Unisex','Men','Women','Kids'] },
            { name: 'weight_group',   label: 'Weight Group',   options: ['Ultralight','Light','Standard','Heavy'] },
        ],
        numbers: [
            { name: 'lens_width_mm',    label: 'Lens Width (mm)',   placeholder: 'e.g. 52', min:40, max:65 },
            { name: 'bridge_width_mm',  label: 'Bridge Width (mm)',  placeholder: 'e.g. 18', min:10, max:30 },
            { name: 'temple_length_mm', label: 'Temple Length (mm)', placeholder: 'e.g. 140', min:120, max:160 },
        ],
        texts: [
            { name: 'collection', label: 'Collection / Series', placeholder: 'e.g. Classic, Sport' },
            { name: 'model_no',   label: 'Model No.',           placeholder: 'e.g. RB3025' },
        ],
        sizeField: 'sizes_available', sizeLabel: 'Frame Size/s',
        sizeOptions: ['XS','S','M','L','XL','One Size'],
    },
    'Eyeglasses': {
        title: 'Eyeglasses Details', icon: 'bi-eyeglasses', color: 'primary',
        selects: [
            { name: 'frame_material', label: 'Frame Material', options: ['Acetate','Metal','Titanium','TR-90','Wood','Horn','Mixed'] },
            { name: 'frame_style',    label: 'Frame Shape',    options: ['Rectangle','Round','Oval','Square','Cat-Eye','Aviator','Geometric','Rimless','Semi-Rimless'] },
            { name: 'frame_type',     label: 'Frame Type',     options: ['Full Rim','Half Rim','Rimless'] },
            { name: 'gender',         label: 'Gender',         options: ['Unisex','Men','Women','Kids'] },
            { name: 'lens_type',      label: 'Lens Type',      options: ['Single Vision','Bifocal','Progressive','Reading','Computer'] },
            { name: 'lens_material',  label: 'Lens Material',  options: ['CR-39','Polycarbonate','Trivex','High-Index 1.67','High-Index 1.74','Glass'] },
            { name: 'lens_coating',   label: 'Lens Coating',   options: ['None','Anti-Reflective','Anti-Scratch','UV Protection','Blue Light Filter','Photochromic','Polarized'] },
        ],
        numbers: [
            { name: 'lens_width_mm',    label: 'Lens Width (mm)',   placeholder: 'e.g. 52', min:40, max:65 },
            { name: 'bridge_width_mm',  label: 'Bridge Width (mm)',  placeholder: 'e.g. 18', min:10, max:30 },
            { name: 'temple_length_mm', label: 'Temple Length (mm)', placeholder: 'e.g. 140', min:120, max:160 },
        ],
        texts: [
            { name: 'collection', label: 'Collection / Series', placeholder: 'e.g. Classic' },
            { name: 'model_no',   label: 'Model No.',           placeholder: 'e.g. RB3025' },
        ],
        sizeField: 'sizes_available', sizeLabel: 'Frame Size/s',
        sizeOptions: ['XS','S','M','L','XL','One Size'],
    },
    'Sunglasses': {
        title: 'Sunglasses Details', icon: 'bi-sunglasses', color: 'warning',
        selects: [
            { name: 'frame_material', label: 'Frame Material', options: ['Acetate','Metal','Titanium','TR-90','Wood','Horn','Mixed'] },
            { name: 'frame_style',    label: 'Frame Shape',    options: ['Rectangle','Round','Oval','Square','Cat-Eye','Aviator','Geometric','Rimless','Semi-Rimless'] },
            { name: 'frame_type',     label: 'Frame Type',     options: ['Full Rim','Half Rim','Rimless'] },
            { name: 'gender',         label: 'Gender',         options: ['Unisex','Men','Women','Kids'] },
            { name: 'lens_type',      label: 'Lens Type',      options: ['Polarized','Non-Polarized','Mirrored','Photochromic','Gradient'] },
            { name: 'lens_coating',   label: 'UV Protection',  options: ['UV400','UV380','None'] },
        ],
        numbers: [
            { name: 'lens_width_mm',    label: 'Lens Width (mm)',   placeholder: 'e.g. 58' },
            { name: 'bridge_width_mm',  label: 'Bridge Width (mm)',  placeholder: 'e.g. 20' },
            { name: 'temple_length_mm', label: 'Temple Length (mm)', placeholder: 'e.g. 145' },
        ],
        texts: [
            { name: 'collection', label: 'Collection / Series', placeholder: 'e.g. Summer 2025' },
            { name: 'model_no',   label: 'Model No.',           placeholder: 'e.g. OO9102' },
        ],
        sizeField: 'sizes_available', sizeLabel: 'Frame Size/s',
        sizeOptions: ['XS','S','M','L','XL','One Size'],
    },
    'Contact Lenses': {
        title: 'Contact Lens Details', icon: 'bi-eye', color: 'info',
        selects: [
            { name: 'cl_type',       label: 'CL Type',     options: ['Daily','Bi-weekly','Monthly','Quarterly','Yearly'] },
            { name: 'cl_material',   label: 'CL Material', options: ['Silicone Hydrogel','Hydrogel','PMMA','Hybrid'] },
            { name: 'cl_color_type', label: 'Color Type',  options: ['Clear','Tinted','Colored','Cosmetic'] },
        ],
        numbers: [
            { name: 'cl_water_content',   label: 'Water Content (%)',  placeholder: 'e.g. 58', min:30, max:80 },
            { name: 'cl_base_curve',      label: 'Base Curve (mm)',     placeholder: 'e.g. 8.6', step:'0.1' },
            { name: 'cl_diameter',        label: 'Diameter (mm)',       placeholder: 'e.g. 14.2', step:'0.1' },
            { name: 'cl_pieces_per_box',  label: 'Pieces per Box',      placeholder: 'e.g. 30', min:1 },
        ],
        texts: [
            { name: 'cl_power_range',    label: 'Power Range (text)',    placeholder: 'e.g. -0.50 to -6.00' },
            { name: 'cl_cylinder_range', label: 'Cylinder Range',        placeholder: 'e.g. 0 to -2.00' },
            { name: 'cl_axis',           label: 'Axis',                  placeholder: 'e.g. 0-180' },
            { name: 'cl_add',            label: 'Add Power',             placeholder: 'e.g. +0.75 to +3.50' },
        ],
        sizeField: 'cl_powers_array', sizeLabel: 'Available Powers',
        sizeOptions: ['-0.50','-1.00','-1.50','-2.00','-2.50','-3.00','-3.50','-4.00','-4.50','-5.00','-5.50','-6.00','+0.50','+1.00','+1.50','+2.00'],
    },
    'Lenses': {
        title: 'Optical Lens Details', icon: 'bi-search', color: 'success',
        selects: [
            { name: 'lens_type',     label: 'Lens Type',     options: ['Single Vision','Bifocal','Progressive','Reading','Computer','Anti-fatigue'] },
            { name: 'lens_material', label: 'Lens Material', options: ['CR-39','Polycarbonate','Trivex','High-Index 1.67','High-Index 1.74','Glass'] },
            { name: 'lens_coating',  label: 'Lens Coating',  options: ['None','Anti-Reflective','Anti-Scratch','UV Protection','Blue Light Filter','Photochromic','Polarized','Mirror'] },
        ],
        numbers: [
            { name: 'lens_diameter',   label: 'Diameter (mm)',  placeholder: 'e.g. 70' },
            { name: 'lens_base_curve', label: 'Base Curve',     placeholder: 'e.g. 6.0', step:'0.1' },
        ],
        texts: [
            { name: 'lens_sphere_range',   label: 'Sphere Range',   placeholder: 'e.g. -6.00 to +4.00' },
            { name: 'lens_cylinder_range', label: 'Cylinder Range', placeholder: 'e.g. 0 to -4.00' },
            { name: 'lens_add_range',      label: 'Add Range',      placeholder: 'e.g. +0.75 to +3.50' },
        ],
        sizeField: 'lens_index', sizeLabel: 'Available Index',
        sizeOptions: ['1.50','1.53','1.56','1.60','1.67','1.74'],
    },
    'Accessories': {
        title: 'Accessory Details', icon: 'bi-bag', color: 'secondary',
        selects: [
            { name: 'accessory_type',     label: 'Accessory Type', options: ['Case','Cleaning Kit','Strap / Cord','Lens Cloth','Repair Kit','Solution','Nose Pad','Screw Set','Display Stand','Other'] },
            { name: 'accessory_material', label: 'Material',       options: ['Plastic','Leather','Fabric','Metal','Silicone','Microfiber','Mixed'] },
            { name: 'solution_type',      label: 'Solution Type',  options: ['N/A','Multi-Purpose','Hydrogen Peroxide','Saline','Daily Cleaner','Enzymatic'] },
        ],
        numbers: [
            { name: 'solution_volume', label: 'Solution Volume (ml)', placeholder: 'e.g. 120', min:0 },
        ],
        texts: [
            { name: 'accessory_colors',   label: 'Available Colors', placeholder: 'e.g. Black, Blue, Red' },
            { name: 'part_compatibility', label: 'Compatibility',     placeholder: 'e.g. Universal, Ray-Ban' },
        ],
        sizeField: 'sizes_available', sizeLabel: 'Available Sizes',
        sizeOptions: ['XS','S','M','L','XL','One Size'],
    }
};

// ============================================================
// PRODUCT DETAILS STATE
// ============================================================
const pdSizeState = { add: [], edit: [] };

function chunkArray(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i+size));
    return out;
}

function parseSizeArray(val) {
    if (!val) return [];
    if (Array.isArray(val)) return val;
    try { return JSON.parse(val); } catch(e) { return []; }
}

function getCurrentCategory(mode) {
    return mode === 'add'
        ? (document.getElementById('addCategory')?.value || '')
        : (document.getElementById('editCategory')?.value || '');
}

// ============================================================
// BUILD PRODUCT DETAILS HTML
// ============================================================
function buildProductDetailsHTML(category, mode, existingData) {
    const config = PRODUCT_DETAILS_CONFIG[category];
    if (!config) return '';

    const headerBgMap = {
        primary:'bg-primary text-white', warning:'bg-warning text-dark',
        info:'bg-info text-dark', success:'bg-success text-white', secondary:'bg-secondary text-white'
    };
    const headerClass = headerBgMap[config.color] || 'bg-primary text-white';

    let html = `
    <div class="card border-${config.color}">
        <div class="card-header ${headerClass}">
            <h6 class="mb-0 fw-bold"><i class="bi ${config.icon} me-2"></i>${config.title}</h6>
        </div>
        <div class="card-body">`;

    // SELECT FIELDS — 3 per row
    chunkArray(config.selects || [], 3).forEach(chunk => {
        html += `<div class="row">`;
        chunk.forEach(sel => {
            const val = existingData ? (existingData[sel.name] || '') : '';
            html += `
            <div class="col-md-4 mb-3">
                <label class="form-label fw-bold">${sel.label}</label>
                <select class="form-select" name="pd_${sel.name}" id="${mode}_pd_${sel.name}">
                    <option value="">-- Select --</option>
                    ${sel.options.map(o => `<option value="${o}"${val===o?' selected':''}>${o}</option>`).join('')}
                </select>
            </div>`;
        });
        html += `</div>`;
    });

    // NUMBER FIELDS — 4 per row
    chunkArray(config.numbers || [], 4).forEach(chunk => {
        html += `<div class="row">`;
        chunk.forEach(num => {
            const val = existingData ? (existingData[num.name] || '') : '';
            html += `
            <div class="col-md-3 mb-3">
                <label class="form-label fw-bold">${num.label}</label>
                <input type="number" class="form-control"
                       name="pd_${num.name}" id="${mode}_pd_${num.name}"
                       placeholder="${num.placeholder||''}"
                       ${num.min!==undefined?`min="${num.min}"`:''}
                       ${num.max!==undefined?`max="${num.max}"`:''}
                       ${num.step!==undefined?`step="${num.step}"`:``}
                       value="${val}">
            </div>`;
        });
        html += `</div>`;
    });

    // TEXT FIELDS — 2 per row
    chunkArray(config.texts || [], 2).forEach(chunk => {
        html += `<div class="row">`;
        chunk.forEach(txt => {
            const val = existingData ? (existingData[txt.name] || '') : '';
            html += `
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold">${txt.label}</label>
                <input type="text" class="form-control"
                       name="pd_${txt.name}" id="${mode}_pd_${txt.name}"
                       placeholder="${txt.placeholder||''}"
                       value="${escapeHtml(val)}">
            </div>`;
        });
        html += `</div>`;
    });

    // SIZE ARRAY
    if (config.sizeOptions && config.sizeOptions.length > 0) {
        const existingSizes = existingData ? parseSizeArray(existingData[config.sizeField]) : [];
        pdSizeState[mode] = [...existingSizes];

        const safeField = config.sizeField.replace(/[^a-zA-Z0-9_]/g,'_');

        html += `
        <div class="mb-2">
            <label class="form-label fw-bold">${config.sizeLabel}
                <small class="text-muted fw-normal ms-1">(select all that apply)</small>
            </label>
            <div id="${mode}_pd_size_tags"
                 class="d-flex flex-wrap gap-1 align-items-center border rounded p-2 mb-2"
                 style="min-height:38px;background:#f8f9fa;">
                <span class="text-muted small" id="${mode}_pd_no_size_msg"
                      style="display:${existingSizes.length===0?'inline':'none'}">
                    No sizes selected yet
                </span>
                ${existingSizes.map(s => buildSizeTagHTML(s, mode)).join('')}
            </div>
            <div class="d-flex flex-wrap gap-2 mb-2">
                ${config.sizeOptions.map(s => {
                    const safeId = s.replace(/[^a-z0-9]/gi,'_');
                    const isActive = existingSizes.includes(s);
                    return `<button type="button"
                        class="btn btn-sm ${isActive?`btn-${config.color}`:'btn-outline-secondary'}"
                        id="${mode}_pd_sizebtn_${safeId}"
                        onclick="pdToggleSize('${mode}','${s}','${config.color}')">${s}</button>`;
                }).join('')}
            </div>
            <input type="hidden" name="pd_${config.sizeField}"
                   id="${mode}_pd_${safeField}_hidden"
                   value='${JSON.stringify(existingSizes)}'>
        </div>`;
    }

    html += `</div></div>`; // close card-body + card
    return html;
}

function buildSizeTagHTML(val, mode) {
    const safeId = val.replace(/[^a-z0-9]/gi,'_');
    return `<span class="badge bg-primary d-inline-flex align-items-center gap-1" id="${mode}_pd_tag_${safeId}">
        ${escapeHtml(val)}
        <button type="button" style="background:none;border:none;color:inherit;padding:0;line-height:1;cursor:pointer;font-size:13px;"
                onclick="pdRemoveSize('${mode}','${val}')">×</button>
    </span>`;
}

// ============================================================
// RENDER PRODUCT DETAILS CARD
// ============================================================
function renderProductDetailsCard(category, mode, existingData) {
    const container = document.getElementById(`productDetailsContainer_${mode}`);
    if (!container) return;

    if (!category || !PRODUCT_DETAILS_CONFIG[category]) {
        container.style.display = 'none';
        container.innerHTML = '';
        pdSizeState[mode] = [];
        return;
    }

    pdSizeState[mode] = [];
    container.innerHTML = buildProductDetailsHTML(category, mode, existingData);
    container.style.display = 'block';
}

// ============================================================
// SIZE TOGGLE
// ============================================================
function pdToggleSize(mode, val, colorClass) {
    const arr = pdSizeState[mode];
    const config = PRODUCT_DETAILS_CONFIG[getCurrentCategory(mode)];
    const safeId = val.replace(/[^a-z0-9]/gi,'_');
    const btn = document.getElementById(`${mode}_pd_sizebtn_${safeId}`);
    const idx = arr.indexOf(val);

    if (idx === -1) {
        arr.push(val);
        if (btn) btn.className = `btn btn-sm btn-${colorClass}`;
        const tagsDiv = document.getElementById(`${mode}_pd_size_tags`);
        if (tagsDiv) {
            const tmp = document.createElement('div');
            tmp.innerHTML = buildSizeTagHTML(val, mode);
            tagsDiv.appendChild(tmp.firstChild);
        }
    } else {
        arr.splice(idx, 1);
        if (btn) btn.className = `btn btn-sm btn-outline-secondary`;
        const tag = document.getElementById(`${mode}_pd_tag_${safeId}`);
        if (tag) tag.remove();
    }

    const noMsg = document.getElementById(`${mode}_pd_no_size_msg`);
    if (noMsg) noMsg.style.display = arr.length === 0 ? 'inline' : 'none';

    if (config) {
        const safeField = config.sizeField.replace(/[^a-zA-Z0-9_]/g,'_');
        const hidden = document.getElementById(`${mode}_pd_${safeField}_hidden`);
        if (hidden) hidden.value = JSON.stringify(arr);
    }
}

function pdRemoveSize(mode, val) {
    const arr = pdSizeState[mode];
    const idx = arr.indexOf(val);
    if (idx !== -1) arr.splice(idx, 1);

    const safeId = val.replace(/[^a-z0-9]/gi,'_');
    const tag = document.getElementById(`${mode}_pd_tag_${safeId}`);
    if (tag) tag.remove();
    const btn = document.getElementById(`${mode}_pd_sizebtn_${safeId}`);
    if (btn) btn.className = `btn btn-sm btn-outline-secondary`;

    const noMsg = document.getElementById(`${mode}_pd_no_size_msg`);
    if (noMsg) noMsg.style.display = arr.length === 0 ? 'inline' : 'none';

    const config = PRODUCT_DETAILS_CONFIG[getCurrentCategory(mode)];
    if (config) {
        const safeField = config.sizeField.replace(/[^a-zA-Z0-9_]/g,'_');
        const hidden = document.getElementById(`${mode}_pd_${safeField}_hidden`);
        if (hidden) hidden.value = JSON.stringify(arr);
    }
}

// ============================================================
// COLLECT PRODUCT DETAILS FROM FORM
// ============================================================
function collectProductDetails(mode) {
    const category = getCurrentCategory(mode);
    const config = PRODUCT_DETAILS_CONFIG[category];
    if (!config) return {};

    const details = {};

    (config.selects || []).forEach(sel => {
        const el = document.getElementById(`${mode}_pd_${sel.name}`);
        if (el) details[sel.name] = el.value;
    });

    (config.numbers || []).forEach(num => {
        const el = document.getElementById(`${mode}_pd_${num.name}`);
        if (el && el.value !== '') details[num.name] = parseFloat(el.value) || null;
    });

    (config.texts || []).forEach(txt => {
        const el = document.getElementById(`${mode}_pd_${txt.name}`);
        if (el) details[txt.name] = el.value;
    });

    const safeField = config.sizeField.replace(/[^a-zA-Z0-9_]/g,'_');
    const hidden = document.getElementById(`${mode}_pd_${safeField}_hidden`);
    if (hidden) {
        try { details[config.sizeField] = JSON.parse(hidden.value); }
        catch(e) { details[config.sizeField] = []; }
    }

    return details;
}

// ============================================================
// COLOR VARIANTS (original code)
// ============================================================
let colorRowCount = 0;
let editColorRowCount = 0;
const colorCategories = ['Frames','Eyeglasses','Sunglasses','Contact Lenses','Lenses'];

function isColorProduct(category) { return colorCategories.includes(category); }

function toggleColorVariantsSection() {
    const categorySelect = document.getElementById('addCategory');
    const colorCard      = document.getElementById('colorVariantsCard');
    const stockInput     = document.getElementById('mainStockInput');
    const stockLabel     = document.getElementById('stockLabel');
    const stockHelpText  = document.getElementById('stockHelpText');
    if (!categorySelect || !colorCard) return;

    const category   = categorySelect.value;
    const needsColors= isColorProduct(category);

    if (needsColors) {
        colorCard.style.display = 'block';
        stockInput.readOnly = true;
        stockInput.classList.add('bg-light');
        stockLabel.innerHTML = 'Total Stock (Auto-calculated) <span class="text-danger">*</span>';
        stockHelpText.innerHTML = 'Automatically calculated from color quantities';
        updateTotalStock();
    } else {
        colorCard.style.display = 'none';
        stockInput.readOnly = false;
        stockInput.classList.remove('bg-light');
        stockLabel.innerHTML = 'Stock Quantity <span class="text-danger">*</span>';
        stockHelpText.innerHTML = 'Enter the total quantity';
    }

    // ✅ Render product details card
    renderProductDetailsCard(category, 'add', null);
}

function toggleEditColorVariantsSection() {
    const categorySelect = document.getElementById('editCategory');
    const colorCard      = document.getElementById('editColorVariantsCard');
    const stockInput     = document.getElementById('editStock');
    const stockLabel     = document.getElementById('editStockLabel');
    const stockHelpText  = document.getElementById('editStockHelpText');
    if (!categorySelect || !colorCard) return;

    const category   = categorySelect.value;
    const needsColors= isColorProduct(category);

    if (needsColors) {
        colorCard.style.display = 'block';
        stockInput.readOnly = true;
        stockInput.classList.add('bg-light');
        stockLabel.innerHTML = 'Total Stock (Auto-calculated) <span class="text-danger">*</span>';
        stockHelpText.innerHTML = 'Automatically calculated from color quantities';
        updateEditTotalStock();
    } else {
        colorCard.style.display = 'none';
        stockInput.readOnly = false;
        stockInput.classList.remove('bg-light');
        stockLabel.innerHTML = 'Stock Quantity <span class="text-danger">*</span>';
        stockHelpText.innerHTML = 'Enter the total quantity';
    }

    // ✅ Render product details only when category changed (avoid overwriting existing data)
    const container = document.getElementById('productDetailsContainer_edit');
    if (container && container.dataset.category !== category) {
        renderProductDetailsCard(category, 'edit', null);
        container.dataset.category = category;
    }
}

function updateTotalStock() {
    const stockInput = document.getElementById('mainStockInput');
    const totalSpan  = document.getElementById('totalStockFromColors');
    const alertDiv   = document.getElementById('stockTotalAlert');
    if (!stockInput) return;
    let total = 0;
    document.querySelectorAll('#colorRowsContainer .color-row').forEach(row => {
        const id = row.id.match(/\d+$/)?.[0];
        if (id) total += parseInt(document.getElementById(`color_qty_${id}`)?.value || 0);
    });
    stockInput.value = total;
    if (totalSpan) totalSpan.textContent = total;
    if (alertDiv) alertDiv.style.display = document.querySelectorAll('#colorRowsContainer .color-row').length > 0 ? 'block' : 'none';
    return total;
}

function updateEditTotalStock() {
    const stockInput = document.getElementById('editStock');
    const totalSpan  = document.getElementById('editTotalStockFromColors');
    const alertDiv   = document.getElementById('editStockTotalAlert');
    if (!stockInput) return;
    let total = 0;
    document.querySelectorAll('#editColorRowsContainer .color-row').forEach(row => {
        const id = row.id.split('_')[3];
        if (id) total += parseInt(document.getElementById(`edit_color_qty_${id}`)?.value || 0);
    });
    stockInput.value = total;
    if (totalSpan) totalSpan.textContent = total;
    if (alertDiv) alertDiv.style.display = document.querySelectorAll('#editColorRowsContainer .color-row').length > 0 ? 'block' : 'none';
    return total;
}

const colorSuggestions = {
    'red':'#FF0000','blue':'#0000FF','green':'#00FF00','yellow':'#FFFF00',
    'black':'#000000','white':'#FFFFFF','purple':'#800080','orange':'#FFA500',
    'pink':'#FFC0CB','brown':'#8B4513','gray':'#808080','silver':'#C0C0C0',
    'gold':'#FFD700','navy':'#000080','teal':'#008080','maroon':'#800000'
};
const codeToName = {};
Object.keys(colorSuggestions).forEach(k => { codeToName[colorSuggestions[k]] = k; });

function addColorRow() {
    colorRowCount++;
    const container  = document.getElementById('colorRowsContainer');
    const noColorsMsg= document.getElementById('noColorsMessage');
    if (noColorsMsg) noColorsMsg.style.display = 'none';
    const rowId = `color_row_${colorRowCount}`;
    const colorRow = document.createElement('div');
    colorRow.className = 'color-row';
    colorRow.id = rowId;
    colorRow.innerHTML = buildColorRowHTML(colorRowCount, 'add');
    container.appendChild(colorRow);
    updateTotalStock();
}

function addEditColorRow() {
    editColorRowCount++;
    const container  = document.getElementById('editColorRowsContainer');
    const noColorsMsg= document.getElementById('editNoColorsMessage');
    if (noColorsMsg) noColorsMsg.style.display = 'none';
    const rowId = `edit_color_row_${editColorRowCount}`;
    const colorRow = document.createElement('div');
    colorRow.className = 'color-row';
    colorRow.id = rowId;
    colorRow.innerHTML = buildColorRowHTML(editColorRowCount, 'edit');
    container.appendChild(colorRow);
    updateEditTotalStock();
}

function buildColorRowHTML(id, mode) {
    const prefix = mode === 'edit' ? 'edit_' : '';
    const removeFunc = mode === 'edit' ? `removeEditColorRow('edit_color_row_${id}')` : `removeColorRow('color_row_${id}')`;
    const updateNameFunc = mode === 'edit' ? `updateEditColorFromName('${id}', this.value)` : `updateColorFromName('${id}', this.value)`;
    const updateCodeFunc = mode === 'edit' ? `updateEditColorFromCode('${id}', this.value)` : `updateColorFromCode('${id}', this.value)`;
    const availFunc = mode === 'edit' ? `updateEditAvailabilityBadge('${id}', this.checked)` : `updateAvailabilityBadge('${id}', this.checked)`;
    const totalFunc = mode === 'edit' ? 'updateEditTotalStock()' : 'updateTotalStock()';

    return `<div class="row align-items-center g-3">
        <div class="col-lg-3">
            <div class="d-flex align-items-center gap-3">
                <div class="color-preview" id="${prefix}preview_${id}" style="background:#FF0000;"></div>
                <div class="flex-grow-1">
                    <label class="form-label small text-muted mb-1">Color Name</label>
                    <input type="text" class="form-control form-control-sm"
                           id="${prefix}color_name_${id}" value="Red"
                           oninput="${updateNameFunc}" list="colorSuggestionsList">
                </div>
            </div>
        </div>
        <div class="col-lg-2">
            <label class="form-label small text-muted mb-1">Color Code</label>
            <div class="d-flex align-items-center gap-2">
                <input type="color" class="form-control form-control-sm"
                       id="${prefix}color_code_${id}" value="#FF0000"
                       onchange="${updateCodeFunc}" style="width:50px;">
                <span id="${prefix}code_text_${id}" style="font-size:11px;">#FF0000</span>
            </div>
        </div>
        <div class="col-lg-1">
            <label class="form-label small text-muted mb-1">Qty</label>
            <input type="number" class="form-control form-control-sm"
                   id="${prefix}color_qty_${id}" value="1" min="0"
                   onchange="${totalFunc}" onkeyup="${totalFunc}">
        </div>
        <div class="col-lg-2">
            <label class="form-label small text-muted mb-1">Status</label>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox"
                       id="${prefix}color_available_${id}" checked
                       onchange="${availFunc}">
                <label><span class="badge bg-success" id="${prefix}avail_badge_${id}">Available</span></label>
            </div>
        </div>
        <div class="col-lg-2">
            <button type="button" class="btn-delete-color" onclick="${removeFunc}">
                <i class="fas fa-trash-alt me-1"></i>Remove
            </button>
        </div>
    </div>`;
}

function updateColorFromName(rowId, name) {
    const code = colorSuggestions[name.toLowerCase().trim()];
    if (!code) return;
    const codeInput = document.getElementById(`color_code_${rowId}`);
    if (codeInput) codeInput.value = code;
    const preview = document.getElementById(`preview_${rowId}`);
    if (preview) preview.style.backgroundColor = code;
    const codeText = document.getElementById(`code_text_${rowId}`);
    if (codeText) codeText.textContent = code;
}
function updateColorFromCode(rowId, code) {
    const preview = document.getElementById(`preview_${rowId}`);
    if (preview) preview.style.backgroundColor = code;
    const codeText = document.getElementById(`code_text_${rowId}`);
    if (codeText) codeText.textContent = code;
    const name = codeToName[code.toUpperCase()];
    if (name) {
        const nameInput = document.getElementById(`color_name_${rowId}`);
        if (nameInput) nameInput.value = name.charAt(0).toUpperCase() + name.slice(1);
    }
}
function updateAvailabilityBadge(rowId, isAvailable) {
    const badge = document.getElementById(`avail_badge_${rowId}`);
    if (badge) { badge.className = isAvailable ? 'badge bg-success' : 'badge bg-secondary'; badge.textContent = isAvailable ? 'Available' : 'Unavailable'; }
}
function removeColorRow(rowId) {
    Swal.fire({ title:'Remove Color?', icon:'question', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, remove it' })
        .then(r => { if (r.isConfirmed) { document.getElementById(rowId)?.remove(); updateTotalStock(); checkNoColors('colorRowsContainer','noColorsMessage'); } });
}
function updateEditColorFromName(rowId, name) {
    const code = colorSuggestions[name.toLowerCase().trim()];
    if (!code) return;
    const codeInput = document.getElementById(`edit_color_code_${rowId}`);
    if (codeInput) codeInput.value = code;
    const preview = document.getElementById(`edit_preview_${rowId}`);
    if (preview) preview.style.backgroundColor = code;
    const codeText = document.getElementById(`edit_code_text_${rowId}`);
    if (codeText) codeText.textContent = code;
}
function updateEditColorFromCode(rowId, code) {
    const preview = document.getElementById(`edit_preview_${rowId}`);
    if (preview) preview.style.backgroundColor = code;
    const codeText = document.getElementById(`edit_code_text_${rowId}`);
    if (codeText) codeText.textContent = code;
    const name = codeToName[code.toUpperCase()];
    if (name) {
        const nameInput = document.getElementById(`edit_color_name_${rowId}`);
        if (nameInput) nameInput.value = name.charAt(0).toUpperCase() + name.slice(1);
    }
}
function updateEditAvailabilityBadge(rowId, isAvailable) {
    const badge = document.getElementById(`edit_avail_badge_${rowId}`);
    if (badge) { badge.className = isAvailable ? 'badge bg-success' : 'badge bg-secondary'; badge.textContent = isAvailable ? 'Available' : 'Unavailable'; }
}
function removeEditColorRow(rowId) {
    Swal.fire({ title:'Remove Color?', icon:'question', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, remove it' })
        .then(r => { if (r.isConfirmed) { document.getElementById(rowId)?.remove(); updateEditTotalStock(); checkNoColors('editColorRowsContainer','editNoColorsMessage'); } });
}
function checkNoColors(containerId, msgId) {
    const c = document.getElementById(containerId);
    const m = document.getElementById(msgId);
    if (c && m && c.querySelectorAll('.color-row').length === 0) m.style.display = 'block';
}
function getAllColorRows() {
    return [...document.querySelectorAll('#colorRowsContainer .color-row')].map(row => {
        const id = row.id.match(/\d+$/)?.[0];
        if (!id) return null;
        return {
            color_name: document.getElementById(`color_name_${id}`)?.value || '',
            color_code: document.getElementById(`color_code_${id}`)?.value || '#000000',
            quantity:   parseInt(document.getElementById(`color_qty_${id}`)?.value || 0),
            is_available: document.getElementById(`color_available_${id}`)?.checked ? 1 : 0
        };
    }).filter(Boolean);
}
function getAllEditColorRows() {
    return [...document.querySelectorAll('#editColorRowsContainer .color-row')].map(row => {
        const id = row.id.split('_')[3];
        if (!id) return null;
        return {
            color_name: document.getElementById(`edit_color_name_${id}`)?.value || '',
            color_code: document.getElementById(`edit_color_code_${id}`)?.value || '#000000',
            quantity:   parseInt(document.getElementById(`edit_color_qty_${id}`)?.value || 0),
            is_available: document.getElementById(`edit_color_available_${id}`)?.checked ? 1 : 0
        };
    }).filter(Boolean);
}
function loadEditColorRows(colors) {
    if (!colors || colors.length === 0) return;
    colors.forEach(color => {
        addEditColorRow();
        const id = editColorRowCount;
        setTimeout(() => {
            const n = document.getElementById(`edit_color_name_${id}`);
            const c = document.getElementById(`edit_color_code_${id}`);
            const q = document.getElementById(`edit_color_qty_${id}`);
            const a = document.getElementById(`edit_color_available_${id}`);
            if (n) n.value = color.color_name || color.name || '';
            if (c) c.value = color.color_code || color.code || '#FF0000';
            if (q) q.value = color.quantity || 0;
            if (a) a.checked = color.is_available !== false;
            updateEditColorFromCode(id, c?.value || '#FF0000');
        }, 50);
    });
    setTimeout(() => updateEditTotalStock(), 100);
}

// ============================================================
// INVENTORY CRUD
// ============================================================
let currentPage = 1, currentSearch = '', currentCategory = 'all', currentStatus = 'all';

document.addEventListener('DOMContentLoaded', function() {
    if (permissions.canView) {
        loadInventoryData();
        loadSuppliersForDropdown();
    } else {
        document.getElementById('inventoryTableBody').innerHTML =
            `<tr><td colspan="10" class="text-center py-5 text-danger"><i class="bi bi-shield-lock fs-1"></i><p class="mt-2">No permission to view inventory</p></td></tr>`;
    }

    document.getElementById('addItemForm')?.addEventListener('submit', handleAddItem);
    document.getElementById('restockForm')?.addEventListener('submit', handleRestockItem);
    document.getElementById('editItemForm')?.addEventListener('submit', handleEditItem);

    document.getElementById('addCost')?.addEventListener('input', updateAddProfitPreview);
    document.getElementById('addPrice')?.addEventListener('input', updateAddProfitPreview);
    document.getElementById('editCost')?.addEventListener('input', updateEditProfitPreview);
    document.getElementById('editPrice')?.addEventListener('input', updateEditProfitPreview);

    document.getElementById('supplierSelect')?.addEventListener('change', function() {
        const sel = this.options[this.selectedIndex];
        const details = document.getElementById('supplierDetails');
        if (this.value && sel) {
            document.getElementById('supplierContact').textContent = sel.dataset.contact || 'N/A';
            document.getElementById('supplierEmail').textContent   = sel.dataset.email   || 'N/A';
            document.getElementById('supplierMobile').textContent  = sel.dataset.mobile  || 'N/A';
            details.style.display = 'block';
        } else { details.style.display = 'none'; }
    });

    // Reset product details on modal close
    document.getElementById('addItemModal')?.addEventListener('hidden.bs.modal', function() {
        const c = document.getElementById('productDetailsContainer_add');
        if (c) { c.innerHTML = ''; c.style.display = 'none'; }
        pdSizeState['add'] = [];
        colorRowCount = 0;
        document.getElementById('colorRowsContainer').innerHTML =
            `<div class="text-center text-muted py-4" id="noColorsMessage">
                <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                <p class="mb-2">No colors added yet.</p>
                <button type="button" class="btn btn-sm btn-primary" onclick="addColorRow()"><i class="fas fa-plus me-1"></i>Add Your First Color</button>
            </div>`;
        document.getElementById('supplierDetails').style.display = 'none';
        document.getElementById('colorVariantsCard').style.display = 'none';
    });

    document.getElementById('editItemModal')?.addEventListener('hidden.bs.modal', function() {
        const c = document.getElementById('productDetailsContainer_edit');
        if (c) { c.innerHTML = ''; c.style.display = 'none'; c.dataset.category = ''; }
        pdSizeState['edit'] = [];
        editColorRowCount = 0;
    });
});

function updateAddProfitPreview() {
    const cost   = parseFloat(document.getElementById('addCost')?.value)  || 0;
    const price  = parseFloat(document.getElementById('addPrice')?.value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    const el = document.getElementById('addProfitPreview');
    if (el) { el.innerHTML = `Profit: ₱${profit.toFixed(2)} (${margin}%)`; el.className = profit >= 0 ? 'alert alert-success py-2 mb-0' : 'alert alert-danger py-2 mb-0'; }
}
function updateEditProfitPreview() {
    const cost   = parseFloat(document.getElementById('editCost')?.value)  || 0;
    const price  = parseFloat(document.getElementById('editPrice')?.value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    const el = document.getElementById('editProfitPreview');
    if (el) { el.innerHTML = `Profit: ₱${profit.toFixed(2)} (${margin}%)`; el.className = profit >= 0 ? 'alert alert-success py-2 mb-0' : 'alert alert-danger py-2 mb-0'; }
}

function loadSuppliersForDropdown() {
    const select = document.getElementById('supplierSelect');
    if (!select) {
        console.warn('Supplier select element not found');
        return;
    }
    
    // Change from 'get_all' to 'get_suppliers' to match the API
    fetch('api/supplier.php?action=get_suppliers&limit=100')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                select.innerHTML = '<option value="">-- Select Supplier (Optional) --</option>';
                data.data.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = `${s.supplier_name} (${s.city || 'N/A'})`;
                    opt.dataset.contact = s.contact_person || '';
                    opt.dataset.email = s.email || '';
                    opt.dataset.mobile = s.mobile || '';
                    select.appendChild(opt);
                });
            } else {
                console.warn('No suppliers found or error:', data);
                select.innerHTML = '<option value="">-- No suppliers available --</option>';
            }
        })
        .catch(err => {
            console.error('Error loading suppliers:', err);
            if (select) {
                select.innerHTML = '<option value="">-- Error loading suppliers --</option>';
            }
        });
}
function loadInventoryData(page = null) {
    if (!permissions.canView) return;
    
    if (page !== null) currentPage = page;
    currentSearch = document.getElementById('searchInput')?.value || '';
    currentCategory = document.getElementById('categoryFilter')?.value || 'all';
    currentStatus = document.getElementById('statusFilter')?.value || 'all';

    const tbody = document.getElementById('inventoryTableBody');
    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading...</p></td></tr>`;
    }

    fetch(`api/inventory.php?action=get_items&page=${currentPage}&search=${encodeURIComponent(currentSearch)}&category=${currentCategory}&status=${currentStatus}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderInventoryTable(data.data);
                renderPagination(data.pagination);
                updateStats();
            } else {
                showError('Failed to load inventory data: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Network error:', err);
            showError('Network error: ' + err.message);
        });
}

function updateStats() {
    // Check if we're on the inventory page
    const statTotalItems = document.getElementById('statTotalItems');
    if (!statTotalItems) {
        console.log('Stats elements not found, skipping update');
        return;
    }
    
    fetch('api/inventory.php?action=get_stats')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Only update if elements exist
                const totalItemsElem = document.getElementById('statTotalItems');
                const lowStockElem = document.getElementById('statLowStock');
                const outOfStockElem = document.getElementById('statOutOfStock');
                const totalValueElem = document.getElementById('statTotalValue');
                
                if (totalItemsElem) totalItemsElem.textContent = data.total_items || 0;
                if (lowStockElem) lowStockElem.textContent = data.low_stock || 0;
                if (outOfStockElem) outOfStockElem.textContent = data.out_of_stock || 0;
                if (totalValueElem) totalValueElem.textContent = `₱${Number(data.total_value || 0).toFixed(2)}`;
            }
        })
        .catch(err => {
            console.error('Error updating stats:', err);
        });
}
function renderInventoryTable(items) {
    const tbody = document.getElementById('inventoryTableBody');
    if (!tbody) {
        console.warn('Inventory table body not found');
        return;
    }
    
    if (!items || items.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-5"><i class="bi bi-inbox fs-1"></i><p>No items found</p></td></tr>`;
        return;
    }
    
    tbody.innerHTML = items.map(item => {
        const stock = parseInt(item.stock) || 0;
        const reorderLevel = parseInt(item.reorder_level) || 5;
        const sellingPrice = parseFloat(item.selling_price) || 0;
        const cost = parseFloat(item.cost) || 0;
        const profit = sellingPrice - cost;
        const margin = sellingPrice > 0 ? ((profit / sellingPrice) * 100).toFixed(1) : 0;
        const statusBadge = stock <= 0 ? 'bg-danger' : (stock <= reorderLevel ? 'bg-warning' : 'bg-success');
        const statusText = stock <= 0 ? 'Out of Stock' : (stock <= reorderLevel ? 'Low Stock' : 'In Stock');

        const editBtn = permissions.canEdit ? `<button class="btn btn-sm btn-outline-primary" onclick="showEditModal(${item.id})" title="Edit"><i class="bi bi-pencil"></i></button>` : '';
        const restockBtn = permissions.canEdit ? `<button class="btn btn-sm btn-outline-success" onclick="showRestockModal(${item.id})" title="Restock"><i class="bi bi-plus-circle"></i></button>` : '';
        const deleteBtn = permissions.canDelete ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteItem(${item.id})" title="Archive"><i class="bi bi-trash"></i></button>` : '';

        let sizesBadge = '';
        if (item.product_details) {
            const pd = item.product_details;
            const sizesField = pd.sizes_available || pd.lens_index || pd.cl_powers_array;
            if (sizesField && Array.isArray(sizesField) && sizesField.length > 0) {
                sizesBadge = `<br><small class="text-muted">${sizesField.slice(0,3).map(s => `<span class="badge bg-light text-dark border">${escapeHtml(s)}</span>`).join(' ')}${sizesField.length > 3 ? ` +${sizesField.length-3}` : ''}</small>`;
            }
        }

        return `<tr>
            <td><span class="badge bg-light text-dark border">${escapeHtml(item.item_id || '')}</span></td>
            <td class="fw-bold">${escapeHtml(item.name || '')}${sizesBadge}</td>
            <td><span class="badge bg-info bg-opacity-10 text-info">${escapeHtml(item.category || '')}</span></td>
            <td>${item.brand ? `<div>${escapeHtml(item.brand)}</div>` : ''}${item.type ? `<small class="text-muted">${escapeHtml(item.type)}</small>` : ''}</td>
            <td>
                <span class="fw-bold ${stock<=0?'text-danger':(stock<=reorderLevel?'text-warning':'')}">${stock}</span>
                <div class="progress" style="height:4px;">
                    <div class="progress-bar ${stock<=0?'bg-danger':(stock<=reorderLevel?'bg-warning':'bg-success')}"
                         style="width:${Math.min(100, (stock/(reorderLevel*3))*100)}%"></div>
                </div>
            </td>
            <td class="text-end"><strong>₱${sellingPrice.toFixed(2)}</strong><br><small class="text-muted">Cost: ₱${cost.toFixed(2)}</small></td>
            <td class="text-end"><span class="${profit>=0?'text-success':'text-danger'}">₱${profit.toFixed(2)} (${margin}%)</span></td>
            <td><span class="badge ${statusBadge}">${statusText}</span></td>
            <td>${item.supplier ? escapeHtml(item.supplier) : '-'}</td>
            <td class="text-center"><div class="btn-group btn-group-sm">${editBtn}${restockBtn}${deleteBtn}</div></td>
        </tr>`;
    }).join('');
}

function renderPagination(pagination) {
    const container = document.getElementById('paginationContainer');
    const info = document.getElementById('paginationInfo');
    
    // Check if elements exist
    if (!container || !info) {
        console.warn('Pagination elements not found');
        return;
    }
    
    if (!pagination || !pagination.total) {
        container.innerHTML = '';
        info.textContent = '';
        return;
    }

    const { total, page, limit, total_pages } = pagination;
    info.textContent = `Showing ${(page-1)*limit+1} to ${Math.min(page*limit,total)} of ${total} items`;

    let html = `<li class="page-item ${page===1?'disabled':''}"><a class="page-link" href="#" onclick="loadInventoryData(${page-1})">&laquo;</a></li>`;
    const start = Math.max(1, page-2), end = Math.min(total_pages, page+2);
    for (let i = start; i <= end; i++) {
        html += `<li class="page-item ${i===page?'active':''}"><a class="page-link" href="#" onclick="loadInventoryData(${i})">${i}</a></li>`;
    }
    html += `<li class="page-item ${page===total_pages?'disabled':''}"><a class="page-link" href="#" onclick="loadInventoryData(${page+1})">&raquo;</a></li>`;
    container.innerHTML = html;
}
function handleAddItem(e) {
    e.preventDefault();
    if (!permissions.canCreate) { Swal.fire('Access Denied','No permission to add items','error'); return; }

    const form     = document.getElementById('addItemForm');
    const formData = new FormData(form);
    const data     = Object.fromEntries(formData);
    data.action    = 'add';

    // Colors
    const colors   = getAllColorRows();
    const category = data.category;
    if (isColorProduct(category) && colors.length > 0) {
        data.stock  = colors.reduce((sum,c) => sum + (parseInt(c.quantity)||0), 0);
        data.colors = colors;
    } else {
        data.stock  = parseInt(data.stock) || 0;
        data.colors = [];
    }

    // ✅ Product details
    data.product_details = collectProductDetails('add');

    data.reorder_level         = parseInt(data.reorder_level) || 5;
    data.selling_price         = parseFloat(data.selling_price) || 0;
    data.cost                  = parseFloat(data.cost) || 0;
    data.supplier_product_price= parseFloat(data.supplier_product_price) || 0;

    const supplierSelect = document.getElementById('supplierSelect');
    if (supplierSelect?.value) {
        data.supplier_id   = supplierSelect.value;
        data.supplier_name = supplierSelect.options[supplierSelect.selectedIndex]?.text?.split('(')[0]?.trim() || '';
    }

    Swal.fire({ title:'Saving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    fetch('api/inventory.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) })
        .then(r => r.json())
        .then(resp => {
            Swal.close();
            if (resp.success) {
                Swal.fire({ icon:'success', title:'Success!', text:'Item added successfully', timer:1500, showConfirmButton:false });
                bootstrap.Modal.getInstance(document.getElementById('addItemModal'))?.hide();
                loadInventoryData(1);
            } else {
                Swal.fire('Error!', resp.message || 'Failed to save item', 'error');
            }
        })
        .catch(err => { Swal.close(); Swal.fire('Error!', `Failed to save: ${err.message}`, 'error'); });
}

function showEditModal(id) {
    if (!permissions.canEdit) { Swal.fire('Access Denied','No permission to edit items','error'); return; }
    Swal.fire({ title:'Loading...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    fetch(`api/inventory.php?action=get_item&id=${id}`)
        .then(r => r.json())
        .then(resp => {
            Swal.close();
            if (!resp.success || !resp.data) { Swal.fire('Error', resp.message || 'Failed to load item', 'error'); return; }
            const item = resp.data;

            document.getElementById('editItemId').value      = item.id || '';
            document.getElementById('editName').value        = item.name || '';
            document.getElementById('editCategory').value    = item.category || '';
            document.getElementById('editStock').value       = item.stock || 0;
            document.getElementById('editReorderLevel').value= item.reorder_level || 5;
            document.getElementById('editPrice').value       = item.selling_price || 0;
            document.getElementById('editCost').value        = item.cost || 0;
            document.getElementById('editSupplierPrice').value= item.supplier_product_price || 0;
            document.getElementById('editBrand').value       = item.brand || '';
            document.getElementById('editType').value        = item.type || '';

            // Reset & load color rows
            document.getElementById('editColorRowsContainer').innerHTML =
                `<div class="text-center text-muted py-4" id="editNoColorsMessage">
                    <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                    <p class="mb-2">No colors added yet.</p>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addEditColorRow()"><i class="fas fa-plus me-1"></i>Add Your First Color</button>
                </div>`;
            editColorRowCount = 0;
            if (item.colors && item.colors.length > 0) loadEditColorRows(item.colors);

            // ✅ Load product details card WITH existing data
            const pdContainer = document.getElementById('productDetailsContainer_edit');
            if (pdContainer) pdContainer.dataset.category = '';
            renderProductDetailsCard(item.category, 'edit', item.product_details || null);
            if (pdContainer) pdContainer.dataset.category = item.category;

            // Toggle color card
            setTimeout(() => toggleEditColorVariantsSection(), 100);

            updateEditProfitPreview();
            new bootstrap.Modal(document.getElementById('editItemModal')).show();
        })
        .catch(err => { Swal.close(); Swal.fire('Error','Failed to load item details','error'); });
}

function deleteItem(id) {
    if (!permissions.canDelete) {
        Swal.fire('Access Denied', 'No permission to delete items', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Archive Item?',
        text: "This item will be archived and won't appear in active inventory. You can restore it later.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, archive it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Archiving...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            // Use POST method with action parameter
            fetch('api/inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'delete',  // ← IMPORTANT!
                    id: parseInt(id) 
                })
            })
            .then(r => r.json())
            .then(resp => {
                Swal.close();
                if (resp.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Archived!',
                        text: 'Item has been archived successfully.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    loadInventoryData();
                } else {
                    Swal.fire('Error!', resp.message || 'Failed to archive item', 'error');
                }
            })
            .catch(err => {
                Swal.close();
                console.error('Archive error:', err);
                Swal.fire('Error!', 'Failed to archive item', 'error');
            });
        }
    });
}
function handleEditItem(e) {
    e.preventDefault();
    if (!permissions.canEdit) { Swal.fire('Access Denied','No permission to edit items','error'); return; }

    const form     = document.getElementById('editItemForm');
    const formData = new FormData(form);
    const data     = Object.fromEntries(formData);
    data.action    = 'update';

    const colors   = getAllEditColorRows();
    const category = data.category;
    if (isColorProduct(category) && colors.length > 0) {
        data.stock  = colors.reduce((sum,c) => sum + (parseInt(c.quantity)||0), 0);
        data.colors = colors;
    } else {
        data.stock  = parseInt(data.stock) || 0;
        data.colors = [];
    }

    // ✅ Product details
    data.product_details = collectProductDetails('edit');

    data.reorder_level         = parseInt(data.reorder_level) || 5;
    data.selling_price         = parseFloat(data.selling_price) || 0;
    data.cost                  = parseFloat(data.cost) || 0;
    data.supplier_product_price= parseFloat(data.supplier_product_price) || 0;

    Swal.fire({ title:'Updating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

    fetch('api/inventory.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) })
        .then(r => r.json())
        .then(resp => {
            Swal.close();
            if (resp.success) {
                Swal.fire({ icon:'success', title:'Success!', text:'Item updated successfully', timer:1500, showConfirmButton:false });
                bootstrap.Modal.getInstance(document.getElementById('editItemModal'))?.hide();
                loadInventoryData(currentPage);
            } else { Swal.fire('Error!', resp.message, 'error'); }
        })
        .catch(() => { Swal.close(); Swal.fire('Error!','Failed to update item','error'); });
}

function showRestockModal(id) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'No permission to restock', 'error');
        return;
    }
    
    // Check if modal elements exist
    const restockModal = document.getElementById('restockModal');
    if (!restockModal) {
        console.error('Restock modal not found');
        Swal.fire('Error', 'Restock modal not found', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Loading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/inventory.php?action=get_item&id=${id}`)
        .then(r => r.json())
        .then(resp => {
            Swal.close();
            if (resp.success && resp.data) {
                const item = resp.data;
                
                const restockItemId = document.getElementById('restockItemId');
                const restockItemName = document.getElementById('restockItemName');
                const currentStock = document.getElementById('currentStock');
                const reorderLevel = document.getElementById('reorderLevel');
                
                if (restockItemId) restockItemId.value = item.id;
                if (restockItemName) restockItemName.textContent = item.name || 'Unknown Item';
                if (currentStock) currentStock.textContent = item.stock || 0;
                if (reorderLevel) reorderLevel.textContent = item.reorder_level || 5;
                
                // Show modal using Bootstrap
                const modal = new bootstrap.Modal(restockModal);
                modal.show();
            } else {
                Swal.fire('Error', resp.message || 'Failed to load item', 'error');
            }
        })
        .catch(err => {
            Swal.close();
            console.error('Error loading item:', err);
            Swal.fire('Error', 'Failed to load item details', 'error');
        });
}

function handleRestockItem(e) {
    e.preventDefault();
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'No permission to restock', 'error');
        return;
    }
    
    const itemId = document.getElementById('restockItemId')?.value;
    const quantityInput = document.querySelector('#restockForm input[name="quantity"]');
    const quantity = quantityInput?.value;
    
    if (!itemId || !quantity) {
        Swal.fire('Error', 'Missing item ID or quantity', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Restocking...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/inventory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'restock', 
            id: parseInt(itemId), 
            quantity: parseInt(quantity) 
        })
    })
    .then(r => r.json())
    .then(resp => {
        Swal.close();
        if (resp.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Item restocked successfully',
                timer: 1500,
                showConfirmButton: false
            });
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('restockModal'));
            if (modal) modal.hide();
            
            // Reload inventory data
            loadInventoryData();
        } else {
            Swal.fire('Error!', resp.message || 'Failed to restock', 'error');
        }
    })
    .catch(err => {
        Swal.close();
        console.error('Restock error:', err);
        Swal.fire('Error!', 'Failed to restock item', 'error');
    });
}

function safeGetElement(id) {
    const elem = document.getElementById(id);
    if (!elem) {
        console.warn(`Element with id "${id}" not found`);
    }
    return elem;
}

function showLowStockReport() {
    fetch('api/inventory.php?action=low_stock_report').then(r => r.json()).then(data => {
        if (data.success && data.data.length > 0) {
            let html = `<div class="text-start"><h6>Low Stock Items</h6><div class="table-responsive">
                <table class="table table-sm"><thead><tr><th>Item</th><th>Stock/Level</th><th>Suggested</th></tr></thead><tbody>`;
            data.data.forEach(item => {
                html += `<tr><td><strong>${escapeHtml(item.name)}</strong><br><small class="text-muted">${item.item_id}</small></td>
                    <td><span class="badge bg-warning">${item.stock} / ${item.reorder_level}</span></td>
                    <td class="text-warning fw-bold">${item.suggested_pr}</td></tr>`;
            });
            html += `</tbody></table></div></div>`;
            Swal.fire({ title:'Low Stock Report', html, width:600, showCloseButton:true, showConfirmButton:false });
        } else {
            Swal.fire({ icon:'info', title:'Good News!', text:'No items are currently low on stock.' });
        }
    });
}

function showReorderSuggestions() {
    if (!permissions.canView) { Swal.fire('Access Denied','No permission','error'); return; }
    Swal.fire({ title:'Loading...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    fetch('api/inventory.php?action=get_reorder_suggestions').then(r => r.json()).then(data => {
        Swal.close();
        if (data.success && data.data && data.data.length > 0) {
            const items = data.data.map(item => ({
                id: item.id, name: item.name, item_id: item.item_id, brand: item.brand,
                category: item.category, current_stock: item.stock, reorder_level: item.reorder_level,
                suggested_quantity: item.suggested_pr, estimated_cost: item.estimated_cost,
                supplier_id: item.supplier_id, supplier: item.supplier
            }));
            const encodedData = encodeURIComponent(JSON.stringify(items));
            window.location.href = `views/select_products.php?action=reorder&items=${encodedData}&total_cost=${data.total_estimated_cost}`;
        } else {
            Swal.fire({ icon:'info', title:'No Reorder Needed', text:'No items need reordering at this time.', timer:2000, showConfirmButton:false });
        }
    }).catch(err => { Swal.close(); Swal.fire('Error','Failed to load reorder suggestions','error'); });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function showError(msg) { Swal.fire({ icon:'error', title:'Error', text:msg }); }
</script>

<datalist id="colorSuggestionsList">
    <?php foreach(['Red','Blue','Green','Yellow','Black','White','Purple','Orange','Pink','Brown','Gray','Silver','Gold','Navy','Teal','Maroon'] as $c): ?>
    <option value="<?= $c ?>">
    <?php endforeach; ?>
</datalist>

<style>
.stat-card { position:relative; overflow:hidden; cursor:pointer; transition:transform 0.2s; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 8px rgba(0,0,0,.1); }
.border-left-primary { border-left:4px solid #4e73df !important; }
.border-left-warning { border-left:4px solid #f6c23e !important; }
.border-left-danger  { border-left:4px solid #e74a3b !important; }
.border-left-success { border-left:4px solid #1cc88a !important; }
.color-row { background:#f8f9fa; border-radius:8px; padding:12px; margin-bottom:12px; border:1px solid #e9ecef; transition:all 0.2s; }
.color-row:hover { background:#fff; border-color:#0d6efd; }
.color-preview { width:40px; height:40px; border-radius:8px; border:2px solid #fff; box-shadow:0 1px 3px rgba(0,0,0,.1); flex-shrink:0; }
.btn-delete-color { background:#fff; border:1px solid #dc3545; color:#dc3545; padding:4px 12px; border-radius:6px; font-size:12px; cursor:pointer; transition:all 0.2s; }
.btn-delete-color:hover { background:#dc3545; color:#fff; }
</style>