<?php
// views/assign_suppliers.php
// SCM Officer page — assign suppliers per PR item with price comparison

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

RBACHelper::init($pdo);

// Subscription check
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('supply_chain')) {
    header('Location: ../views/subscription.php');
    exit;
}

// Load permissions
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// RBAC check
$canAssign = RBACHelper::hasPermission('purchase_orders_create') 
          || RBACHelper::hasPermission('purchase_requests_approve');

if (!$canAssign) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to assign suppliers.</p>
        </div>
    </div>
    <?php
    exit;
}

$current_user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];

// Get PR ID
$pr_id = (int)($_GET['pr_id'] ?? 0);

if (!$pr_id) {
    header('Location: ../main.php?view=purchase_orders');
    exit;
}

try {
    // Fetch PR details
    $prQuery = $pdo->prepare("
        SELECT pr.*, 
               CONCAT(u.first_name, ' ', u.last_name) as requested_by_name
        FROM purchase_requests pr
        LEFT JOIN users u ON pr.requested_by = u.id
        WHERE pr.id = ? AND pr.clinic_id = ? AND pr.status = 'Approved'
    ");
    $prQuery->execute([$pr_id, $clinic_id]);
    $pr = $prQuery->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        header('Location: ../main.php?view=purchase_orders&error=pr_not_approved');
        exit;
    }

    // Store original estimated total BEFORE any assignment
    $original_estimated_total = (float)$pr['total_amount'];

    // Fetch PR items
    $itemsQuery = $pdo->prepare("
        SELECT pi.*, sp.photo_path 
        FROM pr_items pi
        LEFT JOIN supplier_products sp ON pi.supplier_product_id = sp.id
        WHERE pi.pr_id = ?
        ORDER BY pi.id ASC
    ");
    $itemsQuery->execute([$pr_id]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        header('Location: ../main.php?view=purchase_orders&error=no_items');
        exit;
    }

    // For each item, find matching suppliers (strict matching)
    $itemOptions = [];
    foreach ($items as $item) {
        $itemName = trim($item['item_name']);
        
        // Extract keywords from item name (remove common words)
        $keywords = preg_split('/\s+/', $itemName);
        $keywords = array_filter($keywords, function($w) {
            return strlen($w) >= 3 && !in_array(strtolower($w), ['the','and','for','with','pcs','set']);
        });
        
        // Build a search term — use the most specific word
        $searchTerms = [];
        if (count($keywords) > 0) {
            // Use first 2 keywords for matching
            $searchTerms[] = '%' . implode(' ', array_slice($keywords, 0, 2)) . '%';
            // Also try each keyword individually
            foreach (array_slice($keywords, 0, 3) as $kw) {
                $searchTerms[] = '%' . $kw . '%';
            }
        }
        
        if (empty($searchTerms)) {
            $itemOptions[$item['id']] = [];
            continue;
        }
        
        // Build OR conditions
        $conditions = [];
        $params = [];
        foreach ($searchTerms as $term) {
            $conditions[] = "LOWER(sp.product_name) LIKE LOWER(?)";
            $params[] = $term;
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        $optQuery = $pdo->prepare("
            SELECT 
                sp.id as supplier_product_id,
                sp.product_name,
                sp.cost_price,
                sp.stock,
                sp.lead_time_days,
                sp.stock_status,
                s.id as supplier_id,
                s.supplier_name,
                s.city
            FROM supplier_products sp
            JOIN suppliers s ON sp.supplier_id = s.id
            WHERE s.status = 'Active'
              AND sp.is_active = 1
              AND ({$whereClause})
            ORDER BY sp.cost_price ASC
            LIMIT 10
        ");
        $optQuery->execute($params);
        $itemOptions[$item['id']] = $optQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    // All active suppliers — for manual fallback
    $allSuppliersQuery = $pdo->prepare("
        SELECT id, supplier_name, city 
        FROM suppliers 
        WHERE status = 'Active' 
        ORDER BY supplier_name ASC
    ");
    $allSuppliersQuery->execute();
    $allSuppliers = $allSuppliersQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error in assign_suppliers: " . $e->getMessage());
    header('Location: ../main.php?view=purchase_orders&error=db_error');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Suppliers — <?php echo htmlspecialchars($pr['pr_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary: #008080; }
        body { background: #f8fafc; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        .page-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 24px; background: transparent;
            position: sticky; top: 0; z-index: 1000;
        }
        .nav-back {
            display: inline-flex; align-items: center; gap: 6px;
            color: #4b5563; text-decoration: none; font-weight: 500;
            font-size: 0.9rem; padding: 8px 12px; border-radius: 8px;
            transition: all 0.15s ease;
        }
        .nav-back:hover { background: #f3f4f6; color: var(--primary); }
        .nav-title {
            display: inline-flex; align-items: center; gap: 6px;
            color: #111827; font-weight: 600; font-size: 1rem;
            flex: 1; justify-content: center;
        }
        .nav-title i { color: var(--primary); }
        
        .summary-card {
            background: linear-gradient(135deg, var(--primary) 0%, #006666 100%);
            color: white; border-radius: 16px;
            padding: 24px; margin-bottom: 24px;
        }
        
        .item-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .item-card.assigned {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .item-title {
            font-weight: 600;
            color: #0f172a;
            font-size: 1rem;
        }
        
        .item-meta {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 4px;
        }
        
        .options-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .options-table thead th {
            background: #f8fafc;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            padding: 10px 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .options-table tbody tr {
            transition: background 0.15s;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .options-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .options-table tbody tr.selected {
            background: #e6f7f7;
            border-left: 3px solid var(--primary);
        }
        
        .options-table tbody tr.cheapest {
            background: #fef9e7;
        }
        
        .options-table tbody tr.cheapest.selected {
            background: #d4f4dd;
        }
        
        .options-table tbody td {
            padding: 12px;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        
        .radio-col { width: 40px; text-align: center; }
        .price-col { width: 140px; text-align: right; font-weight: 600; }
        .stock-col { width: 100px; text-align: center; }
        .lead-col { width: 100px; text-align: center; }
        
        .cheapest-badge {
            display: inline-block;
            background: #f59e0b;
            color: white;
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 6px;
        }
        
        .no-options {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 16px;
            color: #92400e;
            font-size: 0.9rem;
        }
        
        .progress-indicator {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.15); padding: 6px 14px;
            border-radius: 50px; font-size: 0.85rem;
        }
        
        .manual-toggle {
            font-size: 0.85rem;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
        }
        
        .manual-toggle:hover {
            color: var(--primary);
        }
        
        .manual-wrapper {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #e2e8f0;
        }
    </style>
</head>
<body>

<div class="page-navbar">
    <a href="../main.php?view=purchase_orders&filter=awaiting" class="nav-back">
        <i class="bi bi-arrow-left"></i>
        <span>Back to Purchase Orders</span>
    </a>
    <div class="nav-title">
        <i class="bi bi-person-plus-fill"></i>
        <span>Assign Suppliers</span>
    </div>
    <div style="width: 200px;"></div>
</div>

<div class="container px-3 px-md-4 py-4" style="max-width: 1100px;">

    <!-- SUMMARY CARD -->
    <div class="summary-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h4 class="mb-1 fw-bold"><?php echo htmlspecialchars($pr['pr_number']); ?></h4>
                <p class="mb-2 opacity-75">
                    <?php echo htmlspecialchars($pr['department']); ?> • 
                    Requested by <?php echo htmlspecialchars($pr['requested_by_name'] ?? 'Unknown'); ?>
                </p>
                <p class="mb-0 opacity-75 small">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo htmlspecialchars($pr['purpose']); ?>
                </p>
            </div>
            <div class="text-end">
                <div class="mb-2">
                    <span class="badge bg-white text-dark px-3 py-2" style="font-size: 0.75rem;">Approved</span>
                </div>
                <h3 class="mb-0 fw-bold" id="summaryTotal">₱<?php echo number_format($pr['total_amount'], 2); ?></h3>
                <small class="opacity-75">Total Amount</small>
            </div>
        </div>
    </div>

    <!-- PROGRESS -->
    <div class="d-flex justify-content-between align-items-center mb-3 px-1">
        <h5 class="mb-0">
            <i class="bi bi-list-check me-2" style="color: var(--primary);"></i>
            Compare Prices & Assign Suppliers
        </h5>
        <div class="progress-indicator" style="background: #e0f2fe; color: #0369a1;">
            <i class="bi bi-check-circle"></i>
            <span id="progressText">0 of <?php echo count($items); ?> assigned</span>
        </div>
    </div>

    <!-- ITEMS -->
    <form id="assignForm">
        <?php foreach ($items as $item): 
            $currentSupplierId = $item['supplier_id'] ?? null;
            $options = $itemOptions[$item['id']] ?? [];
            
            $cheapestPrice = null;
            if (!empty($options)) {
                $cheapestPrice = min(array_column($options, 'cost_price'));
            }
            
            $isAssigned = !empty($currentSupplierId);
        ?>
        <div class="item-card <?php echo $isAssigned ? 'assigned' : ''; ?>" 
             data-item-id="<?php echo $item['id']; ?>"
             data-quantity="<?php echo (int)$item['quantity']; ?>"
             data-est-price="<?php echo (float)$item['unit_price']; ?>">
            
            <!-- ITEM HEADER -->
            <div class="item-header">
                <div>
                    <div class="item-title">
                        <i class="bi bi-box me-2" style="color: var(--primary);"></i>
                        <?php echo htmlspecialchars($item['item_name']); ?>
                    </div>
                    <div class="item-meta">
                        Qty: <strong><?php echo $item['quantity']; ?></strong> • 
                        Est. Price: <strong>₱<?php echo number_format($item['unit_price'], 2); ?></strong> • 
                        Total: <strong>₱<?php echo number_format($item['total_price'], 2); ?></strong>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-light text-dark px-3 py-2" id="selectedBadge_<?php echo $item['id']; ?>">
                        <?php if ($isAssigned): ?>
                            Assigned: <?php echo htmlspecialchars($item['supplier_name'] ?? 'Unknown'); ?>
                        <?php else: ?>
                            Not assigned
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- OPTIONS TABLE -->
            <?php if (!empty($options)): ?>
                <table class="options-table">
                    <thead>
                        <tr>
                            <th class="radio-col"></th>
                            <th>Supplier</th>
                            <th class="price-col">Unit Price</th>
                            <th class="stock-col">Stock</th>
                            <th class="lead-col">Lead Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($options as $opt): 
                            $isSelected = ($currentSupplierId == $opt['supplier_id']);
                            $isCheapest = ($opt['cost_price'] == $cheapestPrice);
                        ?>
                        <tr class="option-row <?php echo $isSelected ? 'selected' : ''; ?> <?php echo $isCheapest ? 'cheapest' : ''; ?>"
                            data-item-id="<?php echo $item['id']; ?>"
                            data-supplier-id="<?php echo $opt['supplier_id']; ?>"
                            data-supplier-name="<?php echo htmlspecialchars($opt['supplier_name']); ?>"
                            data-supplier-product-id="<?php echo $opt['supplier_product_id']; ?>"
                            data-unit-price="<?php echo $opt['cost_price']; ?>"
                            onclick="selectOption(this)">
                            <td class="radio-col">
                                <input type="radio" 
                                       name="item_<?php echo $item['id']; ?>" 
                                       value="<?php echo $opt['supplier_id']; ?>"
                                       data-supplier-name="<?php echo htmlspecialchars($opt['supplier_name']); ?>"
                                       data-supplier-product-id="<?php echo $opt['supplier_product_id']; ?>"
                                       data-unit-price="<?php echo $opt['cost_price']; ?>"
                                       <?php echo $isSelected ? 'checked' : ''; ?>>
                            </td>
                            <td>
                                <div class="fw-medium">
                                    <?php echo htmlspecialchars($opt['supplier_name']); ?>
                                    <?php if ($isCheapest): ?>
                                        <span class="cheapest-badge">⭐ Best Price</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($opt['city'])): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($opt['city']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="price-col" style="color: var(--primary);">
                                ₱<?php echo number_format($opt['cost_price'], 2); ?>
                            </td>
                            <td class="stock-col">
                                <?php 
                                $stockClass = $opt['stock'] > 10 ? 'text-success' : ($opt['stock'] > 0 ? 'text-warning' : 'text-danger');
                                ?>
                                <span class="<?php echo $stockClass; ?>">
                                    <?php echo $opt['stock']; ?>
                                </span>
                            </td>
                            <td class="lead-col">
                                <small class="text-muted">
                                    <?php echo $opt['lead_time_days'] ? $opt['lead_time_days'] . ' days' : 'N/A'; ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- MANUAL TOGGLE (optional) -->
                <div>
                    <a href="#" class="manual-toggle" 
                       onclick="toggleManual('<?php echo $item['id']; ?>'); return false;">
                        <i class="bi bi-plus-circle"></i> Choose a different supplier manually
                    </a>
                    <div id="manualWrapper_<?php echo $item['id']; ?>" class="manual-wrapper" style="display: none;">
                        <select class="form-select form-select-sm manual-supplier-select" 
                                data-item-id="<?php echo $item['id']; ?>" style="max-width: 400px;">
                            <option value="">-- Select Supplier Manually --</option>
                            <?php foreach ($allSuppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"
                                        <?php echo ($currentSupplierId == $s['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['supplier_name']); ?>
                                    <?php if (!empty($s['city'])): ?>
                                        (<?php echo htmlspecialchars($s['city']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- NO OPTIONS — manual only -->
                <div class="no-options">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>No matching supplier products found for this item.</strong>
                    <p class="mb-2 mt-2 small">Please select a supplier manually:</p>
                    <select class="form-select form-select-sm manual-supplier-select" 
                            data-item-id="<?php echo $item['id']; ?>" style="max-width: 400px;">
                        <option value="">-- Select Supplier Manually --</option>
                        <?php foreach ($allSuppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"
                                    <?php echo ($currentSupplierId == $s['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['supplier_name']); ?>
                                <?php if (!empty($s['city'])): ?>
                                    (<?php echo htmlspecialchars($s['city']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- ACTION BUTTONS -->
        <div class="row g-3 mb-4 mt-4">
            <div class="col-md-6">
                <a href="../main.php?view=purchase_orders&filter=awaiting" 
                   class="btn btn-outline-secondary w-100 py-2">
                    <i class="bi bi-x-circle me-2"></i>Cancel
                </a>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn w-100 py-2" 
                        style="background-color: var(--primary); color: white;" id="submitBtn">
                    <i class="bi bi-check-circle me-2"></i>Save & Continue to PO
                </button>
            </div>
        </div>
    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PR_ID = <?php echo (int)$pr_id; ?>;
const TOTAL_ITEMS = <?php echo count($items); ?>;
const ESTIMATED_TOTAL = <?php echo json_encode((float)$original_estimated_total); ?>;

// ============================================
// SELECT OPTION (click row)
// ============================================
function selectOption(row) {
    const itemId = row.dataset.itemId;
    
    document.querySelectorAll(`tr.option-row[data-item-id="${itemId}"]`).forEach(r => {
        r.classList.remove('selected');
    });
    
    row.classList.add('selected');
    
    const radio = row.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
    
    const badge = document.getElementById(`selectedBadge_${itemId}`);
    if (badge) {
        badge.className = 'badge bg-success text-white px-3 py-2';
        badge.textContent = 'Assigned: ' + row.dataset.supplierName;
    }
    
    const card = row.closest('.item-card');
    if (card) card.classList.add('assigned');
    
    const manualSelect = card.querySelector('.manual-supplier-select');
    if (manualSelect) manualSelect.value = '';
    
    updateProgress();
}

// ============================================
// MANUAL SELECT
// ============================================
document.querySelectorAll('.manual-supplier-select').forEach(select => {
    select.addEventListener('change', function() {
        const itemId = this.dataset.itemId;
        const card = this.closest('.item-card');
        const badge = document.getElementById(`selectedBadge_${itemId}`);
        
        if (this.value) {
            if (badge) {
                badge.className = 'badge bg-success text-white px-3 py-2';
                badge.textContent = 'Assigned: ' + this.options[this.selectedIndex].text.split('(')[0].trim();
            }
            if (card) card.classList.add('assigned');
            card.querySelectorAll('input[type="radio"]').forEach(r => {
                r.checked = false;
                r.closest('tr').classList.remove('selected');
            });
        } else {
            if (badge) {
                badge.className = 'badge bg-light text-dark px-3 py-2';
                badge.textContent = 'Not assigned';
            }
            if (card) card.classList.remove('assigned');
        }
        
        updateProgress();
    });
});

// ============================================
// TOGGLE MANUAL SELECT
// ============================================
function toggleManual(itemId) {
    const wrapper = document.getElementById('manualWrapper_' + itemId);
    if (wrapper) {
        wrapper.style.display = wrapper.style.display === 'none' ? 'block' : 'none';
    }
}

// ============================================
// PROGRESS TRACKER
// ============================================
function updateProgress() {
    let assigned = 0;
    document.querySelectorAll('.item-card').forEach(card => {
        const hasRadio = card.querySelector('input[type="radio"]:checked');
        const hasManual = card.querySelector('.manual-supplier-select');
        const manualValue = hasManual ? hasManual.value : null;
        if (hasRadio || manualValue) assigned++;
    });
    document.getElementById('progressText').textContent = `${assigned} of ${TOTAL_ITEMS} assigned`;
}

updateProgress();

// ============================================
// FORMAT HELPER
// ============================================
function peso(num) {
    return '₱' + parseFloat(num || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ============================================
// FORM SUBMIT — WITH PRICE CHANGE DETECTION
// ============================================
document.getElementById('assignForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const assignments = {};
    let missingItems = [];
    let actualTotal = 0;
    let itemChanges = [];
    
    document.querySelectorAll('.item-card').forEach(card => {
        const itemId = card.dataset.itemId;
        const itemName = card.querySelector('.item-title').textContent.trim();
        
        // Get quantity & estimated price from data attributes
        const quantity = parseInt(card.dataset.quantity) || 0;
        const estPrice = parseFloat(card.dataset.estPrice) || 0;
        
        let actualPrice = 0;
        let supplierName = '';
        
        // Check radio first
        const checkedRadio = card.querySelector('input[type="radio"]:checked');
        if (checkedRadio) {
            actualPrice = parseFloat(checkedRadio.dataset.unitPrice) || 0;
            supplierName = checkedRadio.dataset.supplierName;
            assignments[itemId] = {
                supplier_id: checkedRadio.value,
                supplier_name: supplierName,
                supplier_product_id: checkedRadio.dataset.supplierProductId || null,
                unit_price: actualPrice
            };
        } else {
            // Check manual select
            const manualSelect = card.querySelector('.manual-supplier-select');
            if (manualSelect && manualSelect.value) {
                supplierName = manualSelect.options[manualSelect.selectedIndex].text.split('(')[0].trim();
                actualPrice = estPrice; // fallback
                assignments[itemId] = {
                    supplier_id: manualSelect.value,
                    supplier_name: supplierName,
                    supplier_product_id: null,
                    unit_price: null
                };
            } else {
                missingItems.push(itemName);
                return;
            }
        }
        
        const lineTotal = actualPrice * quantity;
        actualTotal += lineTotal;
        
        if (estPrice > 0 && actualPrice > 0 && Math.abs(estPrice - actualPrice) > 0.01) {
            itemChanges.push({
                item: itemName,
                quantity: quantity,
                estimated: estPrice,
                actual: actualPrice,
                diff: (actualPrice - estPrice) * quantity
            });
        }
    });
    
    // Validate
    if (missingItems.length > 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Assignment Incomplete',
            html: `<p>Please assign a supplier for:</p>
                   <ul class="text-start small">${missingItems.map(i => `<li>${i}</li>`).join('')}</ul>`,
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    // ============================================
    // BUILD PRICE COMPARISON MODAL
    // ============================================
    const totalDiff = actualTotal - ESTIMATED_TOTAL;
    const percentChange = ESTIMATED_TOTAL > 0 ? (totalDiff / ESTIMATED_TOTAL) * 100 : 0;
    const needsReapproval = percentChange > 10;
    
    let modalHtml = '';
    
    if (itemChanges.length > 0) {
        modalHtml = `
            <div style="text-align:left;font-size:0.85rem;">
                <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px;border-radius:8px;margin-bottom:12px;">
                    <div style="font-weight:700;color:#92400e;margin-bottom:8px;">
                        ⚠️ Price Changes Detected
                    </div>
                    <table style="width:100%;font-size:0.78rem;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid #fcd34d;">
                                <th style="text-align:left;padding:6px 4px;">Item</th>
                                <th style="text-align:right;padding:6px 4px;">Est.</th>
                                <th style="text-align:right;padding:6px 4px;">Actual</th>
                                <th style="text-align:right;padding:6px 4px;">Diff</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemChanges.map(c => `
                                <tr style="border-bottom:1px solid #fef3c7;">
                                    <td style="padding:6px 4px;">
                                        ${c.item}
                                        <br><small style="color:#6b7280;">${c.quantity} × ${peso(c.actual)}</small>
                                    </td>
                                    <td style="text-align:right;padding:6px 4px;color:#6b7280;">${peso(c.estimated)}</td>
                                    <td style="text-align:right;padding:6px 4px;font-weight:600;">${peso(c.actual)}</td>
                                    <td style="text-align:right;padding:6px 4px;font-weight:700;color:${c.diff > 0 ? '#dc2626' : '#16a34a'};">
                                        ${c.diff > 0 ? '+' : ''}${peso(c.diff)}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                
                <div style="background:#f8fafc;border-radius:8px;padding:12px;">
                    <div style="display:flex;justify-content:space-between;padding:4px 0;">
                        <span style="color:#6b7280;">Estimated Total:</span>
                        <strong>${peso(ESTIMATED_TOTAL)}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0;">
                        <span style="color:#6b7280;">Actual Total:</span>
                        <strong>${peso(actualTotal)}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid #e2e8f0;margin-top:4px;">
                        <span style="font-weight:700;">Difference:</span>
                        <strong style="color:${totalDiff > 0 ? '#dc2626' : '#16a34a'};">
                            ${totalDiff > 0 ? '+' : ''}${peso(totalDiff)} (${percentChange.toFixed(1)}%)
                        </strong>
                    </div>
                </div>
                
                ${needsReapproval ? `
                    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-top:12px;color:#991b1b;">
                        <div style="font-weight:700;margin-bottom:4px;">
                            🚨 Finance Re-approval Required
                        </div>
                        <div style="font-size:0.8rem;">
                            Ang price ay tumaas ng <strong>${percentChange.toFixed(1)}%</strong> 
                            (higit sa 10% threshold). Kailangan ng Finance approval bago mag-create ng PO.
                        </div>
                    </div>
                ` : `
                    <div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:12px;margin-top:12px;color:#166534;">
                        <div style="font-weight:700;margin-bottom:4px;">
                            ✅ Within Threshold
                        </div>
                        <div style="font-size:0.8rem;">
                            Ang price change ay <strong>${percentChange.toFixed(1)}%</strong> — pwede nang mag-proceed sa PO creation.
                        </div>
                    </div>
                `}
            </div>
        `;
    } else {
        modalHtml = `
            <div style="text-align:center;padding:12px;">
                <div style="font-size:3rem;color:#16a34a;margin-bottom:8px;">✅</div>
                <div style="font-weight:600;color:#166534;">Prices Match Estimates</div>
                <div style="font-size:0.85rem;color:#6b7280;margin-top:8px;">
                    Total: <strong>${peso(actualTotal)}</strong>
                </div>
            </div>
        `;
    }
    
    // ============================================
    // CONFIRM DIALOG
    // ============================================
    Swal.fire({
        title: itemChanges.length > 0 ? 'Confirm Supplier Assignments' : 'Confirm Assignment',
        html: modalHtml,
        width: itemChanges.length > 0 ? '600px' : '450px',
        icon: needsReapproval ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: needsReapproval ? '#f59e0b' : '#008080',
        confirmButtonText: needsReapproval ? 'Save (Re-approval needed)' : 'Yes, Save & Continue',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: () => {
            const formData = new FormData();
            formData.append('action', 'assign_suppliers');
            formData.append('pr_id', PR_ID);
            formData.append('assignments', JSON.stringify(assignments));
            
            return fetch('../api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to save');
                }
                return data;
            })
            .catch(err => {
                Swal.showValidationMessage('Error: ' + err.message);
                return false;
            });
        }
    }).then((result) => {
        if (!result.isConfirmed || !result.value) return;
        
        const data = result.value;
        
        if (data.needs_reapproval) {
            // ✅ Re-approval needed — redirect sa PR list
            Swal.fire({
                icon: 'warning',
                title: 'Finance Re-approval Required',
                html: `
                    <div style="text-align:left;font-size:0.9rem;">
                        <p>Suppliers assigned successfully.</p>
                        <div style="background:#fef3c7;border-radius:8px;padding:12px;margin-top:12px;">
                            <div><strong>Estimated:</strong> ${peso(data.estimated_total)}</div>
                            <div><strong>Actual:</strong> ${peso(data.actual_total)}</div>
                            <div><strong>Change:</strong> 
                                <span style="color:#dc2626;font-weight:700;">
                                    +${data.percentage_change}%
                                </span>
                            </div>
                        </div>
                        <p style="margin-top:12px;color:#6b7280;font-size:0.85rem;">
                            Finance has been notified. Hindi ka makakapag-create ng PO 
                            hangga't hindi na-approve ng Finance ang bagong price.
                        </p>
                    </div>
                `,
                confirmButtonColor: '#f59e0b',
                confirmButtonText: 'Back to Purchase Orders'
            }).then(() => {
                window.location.href = '../main.php?view=purchase_orders&filter=awaiting';
            });
        } else {
            // ✅ Within threshold — proceed sa PO form
            Swal.fire({
                icon: 'success',
                title: 'Suppliers Assigned!',
                html: `
                    <div style="font-size:0.9rem;">
                        ${data.message || 'Suppliers assigned successfully'}
                        ${data.percentage_change !== 0 ? `
                            <div style="margin-top:8px;color:#6b7280;">
                                Price change: <strong>${data.percentage_change > 0 ? '+' : ''}${data.percentage_change}%</strong>
                            </div>
                        ` : ''}
                    </div>
                `,
                confirmButtonColor: '#008080',
                confirmButtonText: 'Continue to PO',
                timer: 1800,
                timerProgressBar: true,
                showConfirmButton: true
            }).then(() => {
                window.location.href = 'po_form.php?pr_id=' + PR_ID;
            });
        }
    });
});
</script>
</body>
</html>