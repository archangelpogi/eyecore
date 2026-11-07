<?php
include __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ✅ CHECK MODE
$action = $_GET['action'] ?? '';
$mode = $_GET['mode'] ?? '';

// ✅ NEW: Check kung galing sa cart
$isCart = ($action === 'cart');
$isReorder = ($action === 'reorder') || $isCart;
$isManual = ($mode === 'manual');
$isEdit = isset($_GET['id']);
$reorderItems = [];

if ($isReorder) {
    if ($isCart && isset($_SESSION['pending_pr_items'])) {
        // ✅ Cart Flow: Kunin mula sa session (galing sa cart.php)
        $reorderItems = $_SESSION['pending_pr_items'];
    } elseif (isset($_GET['items'])) {
        // ✅ SCM Reorder Flow: Kunin mula sa URL
        $reorderItems = json_decode(urldecode($_GET['items']), true);
    }
}

$suppliers = [];

if ($isReorder && !empty($reorderItems)) {
    // ✅ FLOW 1: From Reorder Suggestions (SCM) or Cart
    // Group items by supplier_id (0 = unassigned)
    foreach ($reorderItems as $item) {
        $supplier_id = $item['supplier_id'] ?? 0;
        $supplier_key = $supplier_id ?: 'unassigned'; // Group all without supplier together

        // Get supplier details (only if supplier_id exists)
        if (!isset($suppliers[$supplier_key])) {
            if ($supplier_id) {
                $supplierQuery = $pdo->prepare("
                    SELECT id, supplier_name, contact_person, email, mobile, city, payment_terms
                    FROM suppliers WHERE id = ?
                ");
                $supplierQuery->execute([$supplier_id]);
                $supplierInfo = $supplierQuery->fetch(PDO::FETCH_ASSOC);

                $suppliers[$supplier_key] = [
                    'name' => $supplierInfo['supplier_name'] ?? $item['supplier'] ?? 'Unknown Supplier',
                    'contact_person' => $supplierInfo['contact_person'] ?? '',
                    'email' => $supplierInfo['email'] ?? '',
                    'mobile' => $supplierInfo['mobile'] ?? '',
                    'city' => $supplierInfo['city'] ?? '',
                    'payment_terms' => $supplierInfo['payment_terms'] ?? '',
                    'products' => [],
                    'total' => 0,
                    'unassigned' => false
                ];
            } else {
                // ✅ Unassigned group (no supplier yet)
                $suppliers[$supplier_key] = [
                    'name' => 'Unassigned (SCM Officer to Assign)',
                    'contact_person' => '',
                    'email' => '',
                    'mobile' => '',
                    'city' => '',
                    'payment_terms' => '',
                    'products' => [],
                    'total' => 0,
                    'unassigned' => true
                ];
            }
        }

        // Use the data from reorder (estimated_cost based)
        $suggestedQty = $item['suggested_quantity'] ?? 1;
        $estimatedCost = $item['estimated_cost'] ?? 0;
        $unitPrice = $suggestedQty > 0 ? ($estimatedCost / $suggestedQty) : 0;

        $suppliers[$supplier_key]['products'][] = [
            'id' => $item['id'] ?? 0,
            'product_name' => $item['name'] ?? '',
            'photo_path' => '',
            'cart_qty' => $suggestedQty,
            'cost_price' => $unitPrice,
            'subtotal' => $estimatedCost,
            'stock' => $item['current_stock'] ?? 0,
            'min_order_qty' => 1,
            'brand' => $item['brand'] ?? '',
            'item_id' => $item['item_id'] ?? '',
            'supplier_id' => $supplier_id
        ];

        $suppliers[$supplier_key]['total'] += $estimatedCost;
    }
} elseif ($isManual) {
    // ✅ FLOW 2: Manual mode — BLANK form (walang items)
    $suppliers = [];
} else {
    // ❌ FLOW 3: Cart flow — DEPRECATED (Ito ang dati mong error, pero ngayon fixed na)
    // Redirect sa inventory kung walang reorder
    header('Location: ../main.php?view=inventory');
    exit;
}

$supplier_count = count($suppliers);
$grand_total = array_sum(array_column($suppliers, 'total'));

// If no items AND not manual mode, redirect
if (empty($suppliers) && !$isManual) {
    header('Location: ../main.php?view=inventory');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>New Purchase Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .product-image {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 6px;
    }

    .page-navbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 24px;
        background: transparent;
        border-bottom: none;
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .nav-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #4b5563;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.9rem;
        padding: 8px 12px;
        border-radius: 8px;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }

    .nav-back:hover {
        background: #f3f4f6;
        color: #008080;
    }

    .nav-back i {
        font-size: 1rem;
    }

    .nav-title {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #111827;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 6px 16px;
        flex: 1;
        justify-content: center;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .nav-title i {
        font-size: 1rem;
        color: #008080;
        flex-shrink: 0;
    }

    .nav-title span {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    @media (max-width: 768px) {
        .page-navbar {
            padding: 10px 14px;
            gap: 8px;
        }

        .nav-back {
            padding: 6px 10px;
            font-size: 0.85rem;
        }

        .nav-back span {
            display: none;
        }

        .nav-back i {
            font-size: 1.1rem;
        }

        .nav-title {
            font-size: 0.85rem;
            padding: 6px 10px;
        }

        .nav-title span {
            max-width: 140px;
        }
    }
</style>
</head>
<body class="bg-light">
<div class="page-navbar">
<a class="nav-back" href="<?php echo $isReorder ? '../main.php?view=inventory' : '../main.php?view=purchase_requests'; ?>">
    <i class="bi bi-arrow-left"></i>
    <span><?php echo $isReorder ? 'Back to Inventory' : 'Back to Purchase Requests'; ?></span>
</a>

<div class="nav-title">
    <i class="bi bi-file-text"></i>
    <span><?php 
        if ($isReorder) echo 'Purchase Request (from Reorder Suggestions)';
        elseif ($isManual) echo 'New Purchase Request (Manual)';
        else echo 'New Purchase Request';
    ?></span>
</div>

    <div style="width: 140px;"></div>
</div>

<div class="container px-3 px-md-4 py-4">
    <form id="prForm">
            <input type="hidden" name="action" value="create_multiple_prs">

            <?php if ($supplier_count > 1): ?>
            <div class="alert alert-info d-flex align-items-center mb-4">
                <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                <div>
                    <strong><?php echo $supplier_count; ?> Suppliers Detected</strong><br>
                    This will create <strong><?php echo $supplier_count; ?> separate Purchase Requests</strong> - one for each supplier.
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle me-2" style="color: #008080;"></i>
                        Request Details (Common for all PRs)
                    </h5>
                </div>
                <div class="card-body px-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label text-secondary">Department</label>
                            <select class="form-select" name="department" id="department" required>
                                <option value="SCM">Supply Chain Management</option>
                                <option value="Optical">Optical Department</option>
                                <option value="Clinic">Clinic</option>
                                <option value="Admin">Administration</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary">Priority</label>
                            <select class="form-select" name="priority" id="priority" required>
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary">Needed By</label>
                            <input type="date" class="form-control" name="needed_by" id="needed_by" 
                                   value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary">Purpose / Justification</label>
                            <textarea class="form-control" name="purpose" id="purpose" rows="2" required 
                                      placeholder="Explain why these items are needed..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary">Additional Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2" 
                                      placeholder="Any additional information..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

<?php if ($isManual): ?>
<h5 class="mb-3 px-2">
    <i class="bi bi-list-check me-2" style="color: #008080;"></i>
    Items
</h5>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="manualItemsTable">
                <thead class="bg-light">
                    <tr>
                        <th>Item Name *</th>
                        <th width="100">Quantity *</th>
                        <th width="150">Est. Unit Price *</th>
                        <th width="130" class="text-end">Total</th>
                        <th width="60"></th>
                    </tr>
                </thead>
                <tbody id="manualItemsBody">
                    <tr id="noItemsRow">
                        <td colspan="5" class="text-center text-muted py-4">
                            No items added. Click "Add Item" to start.
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-light">
                        <td colspan="3" class="text-end fw-bold">GRAND TOTAL:</td>
                        <td class="text-end fw-bold" style="color: #008080;" id="manualGrandTotal">₱0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm mt-3" onclick="addManualItem()">
            <i class="bi bi-plus-circle me-1"></i>Add Item
        </button>
    </div>
</div>

<?php else: ?>
<h5 class="mb-3 px-2">
    <i class="bi bi-shop me-2" style="color: #008080;"></i>
    Items by Supplier
</h5>

<?php foreach ($suppliers as $supplier_id => $supplier): ?>
<div class="card border-0 shadow-sm mb-4 supplier-card" data-supplier-id="<?php echo $supplier_id; ?>">
    <div class="card-header bg-white border-0 pt-4 px-4">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h5 class="mb-1"><?php echo htmlspecialchars($supplier['name']); ?></h5>
                <div class="small text-secondary">
                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?> |
                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($supplier['email'] ?? 'N/A'); ?> |
                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($supplier['mobile'] ?? 'N/A'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Product</th>
                        <th class="text-center">Quantity</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end pe-4">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplier['products'] as $product): 
                        $imagePath = $product['photo_path'] ? '../' . $product['photo_path'] : '../assets/img/no-image.png';
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <?php if ($product['photo_path']): ?>
                                    <img src="../<?php echo $product['photo_path']; ?>" 
                                         class="product-image me-3 border"
                                         onerror="this.src='../assets/img/no-image.png'">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center me-3 border" 
                                         style="width: 50px; height: 50px; border-radius: 6px;">
                                        <i class="bi bi-image text-secondary"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <span class="fw-medium"><?php echo htmlspecialchars($product['product_name']); ?></span>
                                    <div class="small text-secondary">
                                        Stock: <?php echo $product['stock']; ?> | Min: <?php echo $product['min_order_qty']; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark px-3 py-2"><?php echo $product['cart_qty']; ?></span>
                        </td>
                        <td class="text-end text-secondary">₱<?php echo number_format($product['cost_price'], 2); ?></td>
                        <td class="text-end pe-4 fw-bold" style="color: #008080;">₱<?php echo number_format($product['subtotal'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!$isManual): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5 class="mb-0">Total</h5>
            </div>
            <div class="col-md-6 text-end">
                <h3 class="mb-0" style="color: #008080;">₱<?php echo number_format($grand_total, 2); ?></h3>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <a href="<?php echo $isReorder ? '../main.php?view=inventory' : '../main.php?view=purchase_requests'; ?>" class="btn btn-outline-secondary w-100 py-2">
            <i class="bi bi-arrow-left me-2"></i>Back to <?php echo $isReorder ? 'Inventory' : 'Purchase Requests'; ?>
        </a>
    </div>
    <div class="col-md-6">
        <?php if ($isReorder): ?>
            <button type="button" class="btn w-100 py-2" style="background-color: #008080; color: white;" onclick="submitReorderPR()">
                <i class="bi bi-send me-2"></i>Submit PR for Approval
            </button>
        <?php elseif ($isManual): ?>
            <button type="button" class="btn w-100 py-2" style="background-color: #008080; color: white;" onclick="submitManualPR()">
                <i class="bi bi-send me-2"></i>Submit PR for Approval
            </button>
        <?php endif; ?>
    </div>
</div>
        </form>
    </div>

    <script>

function submitReorderPR() {
    const department = document.getElementById('department').value;
    const purpose = document.getElementById('purpose').value;

    if (!department || !purpose) {
        Swal.fire('Error', 'Please fill in all required fields', 'error');
        return;
    }

    const suppliers = <?php echo json_encode($suppliers); ?>;
    const allItems = [];

    for (const supplierKey in suppliers) {
        const supplier = suppliers[supplierKey];
        for (const product of supplier.products) {
            allItems.push({
                id: product.id,
                name: product.product_name,
                item_id: product.item_id || '',
                brand: product.brand || '',
                quantity: product.cart_qty,
                unit_price: product.cost_price,
                estimated_cost: product.subtotal,
                supplier_id: supplier.unassigned ? null : supplierKey,
                supplier_name: supplier.unassigned ? null : supplier.name
            });
        }
    }

    const grandTotal = <?php echo $grand_total; ?>;

    Swal.fire({
        title: 'Submitting PR for Approval...',
        html: `Submitting <strong>${allItems.length}</strong> item(s) from reorder suggestions`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'create_from_reorder');
    formData.append('items', JSON.stringify(allItems));
    formData.append('total_cost', grandTotal);
    formData.append('department', department);
    formData.append('priority', document.getElementById('priority').value);
    formData.append('needed_by', document.getElementById('needed_by').value);
    formData.append('purpose', purpose);
    formData.append('notes', document.getElementById('notes').value);

    fetch('../api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetch('../api/cart.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clear'
            })
            .catch(err => console.warn('Cart clear failed (non-critical):', err))
            .finally(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'PR Submitted!',
                    html: `
                        <p>Successfully created Purchase Request</p>
                        <div class="text-start small bg-light p-3 rounded mt-3">
                            <div><i class="bi bi-check-circle-fill text-success me-2"></i>${data.pr_number}</div>
                        </div>
                        <p class="mt-2 small text-muted">Submitted for Finance approval. SCM Officer will assign supplier after approval.</p>
                    `,
                    confirmButtonColor: '#008080',
                    confirmButtonText: 'View Purchase Requests'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '../main.php?view=purchase_requests';
                    }
                });
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to create PR', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error. Please try again.', 'error');
    });
}

let manualItemCounter = 0;

function addManualItem() {
    const tbody = document.getElementById('manualItemsBody');
    if (!tbody) return;

    const noItemsRow = document.getElementById('noItemsRow');
    if (noItemsRow) noItemsRow.remove();

    manualItemCounter++;
    const rowId = 'manual_' + manualItemCounter;

    const row = `
        <tr id="row_${rowId}">
            <td>
                <input type="text" class="form-control form-control-sm" 
                       id="item_name_${rowId}" 
                       placeholder="Enter item name..." required>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm" 
                       id="qty_${rowId}" 
                       value="1" min="1" required
                       onchange="calculateManualRow('${rowId}')">
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control form-control-sm" 
                           id="price_${rowId}" 
                           value="0" min="0" step="0.01" required
                           onchange="calculateManualRow('${rowId}')">
                </div>
            </td>
            <td class="text-end fw-bold" style="color: #008080;" id="subtotal_${rowId}">
                ₱0.00
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger" 
                        onclick="removeManualItem('${rowId}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;

    tbody.insertAdjacentHTML('beforeend', row);
}

function calculateManualRow(rowId) {
    const qty = parseFloat(document.getElementById(`qty_${rowId}`)?.value) || 0;
    const price = parseFloat(document.getElementById(`price_${rowId}`)?.value) || 0;
    const subtotal = qty * price;

    const subtotalEl = document.getElementById(`subtotal_${rowId}`);
    if (subtotalEl) subtotalEl.textContent = '₱' + subtotal.toFixed(2);

    calculateManualGrandTotal();
}

function calculateManualGrandTotal() {
    let total = 0;
    document.querySelectorAll('[id^="subtotal_manual_"]').forEach(el => {
        const val = parseFloat(el.textContent.replace('₱', '').replace(',', '')) || 0;
        total += val;
    });
    const grandTotalEl = document.getElementById('manualGrandTotal');
    if (grandTotalEl) grandTotalEl.textContent = '₱' + total.toFixed(2);
}

function removeManualItem(rowId) {
    const row = document.getElementById(`row_${rowId}`);
    if (row) row.remove();

    const remaining = document.querySelectorAll('#manualItemsBody tr[id^="row_"]').length;
    if (remaining === 0) {
        const tbody = document.getElementById('manualItemsBody');
        if (tbody) {
            tbody.innerHTML = `
                <tr id="noItemsRow">
                    <td colspan="5" class="text-center text-muted py-4">
                        No items added. Click "Add Item" to start.
                    </td>
                </tr>
            `;
        }
    }
    calculateManualGrandTotal();
}

function submitManualPR() {
    const department = document.getElementById('department').value;
    const purpose = document.getElementById('purpose').value;

    if (!department || !purpose) {
        Swal.fire('Error', 'Please fill in Department and Purpose', 'error');
        return;
    }

    const items = [];
    document.querySelectorAll('#manualItemsBody tr[id^="row_"]').forEach(row => {
        const rowId = row.id.replace('row_', '');
        const name = document.getElementById(`item_name_${rowId}`)?.value?.trim();
        const qty = parseFloat(document.getElementById(`qty_${rowId}`)?.value) || 0;
        const price = parseFloat(document.getElementById(`price_${rowId}`)?.value) || 0;

        if (name && qty > 0) {
            items.push({
                id: null,
                name: name,
                item_id: null,
                brand: '',
                quantity: qty,
                unit_price: price,
                estimated_cost: qty * price,
                supplier_id: null,
                supplier_name: null
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }

    const grandTotal = items.reduce((sum, i) => sum + i.estimated_cost, 0);

    Swal.fire({
        title: 'Submitting PR for Approval...',
        html: `Submitting <strong>${items.length}</strong> item(s)`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'create_from_reorder');
    formData.append('items', JSON.stringify(items));
    formData.append('total_cost', grandTotal);
    formData.append('department', department);
    formData.append('priority', document.getElementById('priority').value);
    formData.append('needed_by', document.getElementById('needed_by').value);
    formData.append('purpose', purpose);
    formData.append('notes', document.getElementById('notes').value);

    fetch('../api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetch('../api/cart.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clear'
            })
            .catch(err => console.warn('Cart clear failed (non-critical):', err))
            .finally(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'PR Submitted!',
                    html: `
                        <p>Successfully created Purchase Request</p>
                        <div class="text-start small bg-light p-3 rounded mt-3">
                            <div><i class="bi bi-check-circle-fill text-success me-2"></i>${data.pr_number}</div>
                        </div>
                        <p class="mt-2 small text-muted">Submitted for Finance approval. SCM Officer will assign supplier after approval.</p>
                    `,
                    confirmButtonColor: '#008080',
                    confirmButtonText: 'View Purchase Requests'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '../main.php?view=purchase_requests';
                    }
                });
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to create PR', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error. Please try again.', 'error');
    });
}
    </script>
</body>
</html>