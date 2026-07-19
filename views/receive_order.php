<?php
include __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: admin/login.php');
    exit;
}

$po_id = $_GET['po_id'] ?? 0;

// Get PO details
$poQuery = $pdo->prepare("
    SELECT po.*, 
           s.supplier_name, s.contact_person, s.email, s.mobile, s.address,
           pr.pr_number, pr.department, pr.purpose, pr.approved_at,
           CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN users u ON pr.approved_by = u.id
    WHERE po.id = ? AND po.status = 'Shipped'
");
$poQuery->execute([$po_id]);
$po = $poQuery->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: main.php?view=purchase_orders');
    exit;
}

// Get PO items
$itemsQuery = $pdo->prepare("
    SELECT pi.*, sp.photo_path 
    FROM pr_items pi
    LEFT JOIN supplier_products sp ON pi.supplier_product_id = sp.id
    WHERE pi.pr_id = ? AND pi.supplier_id = ?
");
$itemsQuery->execute([$po['pr_id'], $po['supplier_id']]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Get inventory items for dropdown
$invQuery = $pdo->prepare("SELECT id, name, stock FROM inventory WHERE clinic_id = ? ORDER BY name");
$invQuery->execute([$_SESSION['clinic_id']]);
$inventory_items = $invQuery->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Receive Order - <?php echo $po['po_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #008080;
            --teal-dark: #006666;
            --teal-light: #e6f3f3;
        }
        
        body {
            background: #f8f9fa;
        }
        
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .tracking-step {
            position: relative;
        }
        
        .tracking-step:not(:last-child):before {
            content: '';
            position: absolute;
            left: 15px;
            top: 30px;
            height: calc(100% - 30px);
            width: 2px;
            background: #dee2e6;
        }
        
        .rating-star {
            cursor: pointer;
            font-size: 1.5rem;
            color: #ffc107;
            transition: transform 0.2s;
        }
        
        .rating-star:hover {
            transform: scale(1.1);
        }
        
        .tag-badge {
            cursor: pointer;
            transition: all 0.2s;
            padding: 8px 16px;
            border-radius: 30px;
        }
        
        .tag-badge:hover {
            transform: translateY(-2px);
        }
        
        .tag-badge.selected {
            background-color: var(--teal) !important;
            color: white !important;
        }
        
        .btn-teal {
            background-color: var(--teal);
            border-color: var(--teal);
            color: white;
        }
        
        .btn-teal:hover {
            background-color: var(--teal-dark);
            border-color: var(--teal-dark);
        }
        
        .btn-outline-teal {
            border-color: var(--teal);
            color: var(--teal);
        }
        
        .btn-outline-teal:hover {
            background-color: var(--teal);
            color: white;
        }
        
        .card {
            border: none;
            border-radius: 12px;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
        }
        
        .badge-status {
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 500;
        }
        
        .status-shipped {
            background-color: #0d6efd20;
            color: #0d6efd;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-dark mb-4" style="background-color: var(--teal);">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-box-seam me-2"></i>Receive Order
            </span>
            <a href="main.php?view=purchase_orders&filter=to_receive" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Back to Orders
            </a>
        </div>
    </nav>

    <div class="container">
        <!-- Order Header -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1"><?php echo $po['po_number']; ?></h4>
                        <p class="text-secondary mb-0">
                            <i class="bi bi-calendar me-1"></i>Ordered: <?php echo date('M d, Y', strtotime($po['order_date'])); ?>
                        </p>
                        <p class="text-secondary">
                            <i class="bi bi-truck me-1"></i>Expected: <?php echo date('M d, Y', strtotime($po['expected_date'])); ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="badge-status status-shipped">
                            <i class="bi bi-truck me-1"></i>Out for Delivery
                        </span>
                        <h3 class="mt-3" style="color: var(--teal);">₱<?php echo number_format($po['total_amount'], 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column - Tracking and Supplier -->
            <div class="col-md-4">
                <!-- Order Tracking -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-truck me-2" style="color: var(--teal);"></i>
                            Order Tracking
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="tracking-step mb-3 ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-success bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Order Placed</p>
                                    <small class="text-secondary"><?php echo date('M d, Y', strtotime($po['created_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tracking-step mb-3 ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-success bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Supplier Approved</p>
                                    <small class="text-secondary"><?php echo date('M d, Y', strtotime($po['approved_at'] ?? $po['created_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tracking-step mb-3 ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-success bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Shipped</p>
                                    <small class="text-secondary"><?php echo date('M d, Y', strtotime($po['shipped_date'] ?? $po['updated_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tracking-step ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-primary bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-truck text-primary"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Delivering</p>
                                    <small class="text-secondary">Today</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($po['tracking_number']): ?>
                        <hr class="mt-3">
                        <div class="mt-2">
                            <small class="text-muted">Tracking #: <?php echo $po['tracking_number']; ?></small>
                            <?php if ($po['carrier']): ?>
                            <br><small class="text-muted">Carrier: <?php echo $po['carrier']; ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Supplier Info with Rating -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-shop me-2" style="color: var(--teal);"></i>
                            Seller Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><?php echo htmlspecialchars($po['supplier_name']); ?></h6>
                        
                        <div class="d-flex mb-2">
                            <i class="bi bi-person text-secondary me-2" style="width: 20px;"></i>
                            <span><?php echo htmlspecialchars($po['contact_person'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex mb-2">
                            <i class="bi bi-envelope text-secondary me-2" style="width: 20px;"></i>
                            <span><?php echo htmlspecialchars($po['email'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex mb-2">
                            <i class="bi bi-telephone text-secondary me-2" style="width: 20px;"></i>
                            <span><?php echo htmlspecialchars($po['mobile'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex">
                            <i class="bi bi-geo-alt text-secondary me-2" style="width: 20px;"></i>
                            <span><?php echo htmlspecialchars($po['address'] ?? 'N/A'); ?></span>
                        </div>
                        
                        <hr class="mt-3">
                        
                        <!-- Rating Stars -->
                        <div class="mt-2">
                            <label class="form-label small text-secondary mb-2">Rate this supplier</label>
                            <div class="rating-stars" id="ratingStars">
                                <i class="bi bi-star rating-star" data-rating="1"></i>
                                <i class="bi bi-star rating-star" data-rating="2"></i>
                                <i class="bi bi-star rating-star" data-rating="3"></i>
                                <i class="bi bi-star rating-star" data-rating="4"></i>
                                <i class="bi bi-star rating-star" data-rating="5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Items and Details -->
            <div class="col-md-8">
                <!-- Items to Receive -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-box-seam me-2" style="color: var(--teal);"></i>
                                Items to Receive
                            </h5>
                            <span class="badge bg-light text-secondary">Full quantity will be received</span>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Product</th>
                                        <th class="text-center">Ordered</th>
                                        <th class="text-center">To Receive</th>
                                        <th class="ps-3">Inventory</th>
                                        <th class="pe-4">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $index => $item): 
                                        $imagePath = $item['photo_path'] ? '../' . $item['photo_path'] : '../assets/img/no-image.png';
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <?php if ($item['photo_path']): ?>
                                                    <img src="<?php echo $imagePath; ?>" class="product-image me-3 border">
                                                <?php else: ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center me-3 border" 
                                                         style="width: 60px; height: 60px; border-radius: 8px;">
                                                        <i class="bi bi-image text-secondary fs-4"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                                    <div class="small text-secondary">₱<?php echo number_format($item['unit_price'], 2); ?> / pc</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                                        <td class="text-center">
                                            <input type="number" class="form-control form-control-sm received-qty" 
                                                id="received_<?php echo $index; ?>" 
                                                value="<?php echo $item['quantity']; ?>" 
                                                min="0" max="<?php echo $item['quantity']; ?>" 
                                                data-index="<?php echo $index; ?>"
                                                readonly
                                                style="width: 80px; background-color: #e9ecef; text-align: center; margin: 0 auto;">
                                        </td>
                                        <td class="ps-3">
                                            <select class="form-select form-select-sm inventory-select" 
                                                    id="inventory_select_<?php echo $index; ?>" 
                                                    data-index="<?php echo $index; ?>">
                                                <option value="">-- Select --</option>
                                                <option value="new">➕ Create New</option>
                                                <?php foreach ($inventory_items as $inv): ?>
                                                    <option value="<?php echo $inv['id']; ?>">
                                                        <?php echo $inv['name']; ?> (Stock: <?php echo $inv['stock']; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <!-- New Inventory Name Input -->
                                            <div id="new_inventory_container_<?php echo $index; ?>" style="display: none; margin-top: 8px;">
                                                <input type="text" class="form-control form-control-sm" 
                                                       id="new_inventory_name_<?php echo $index; ?>" 
                                                       placeholder="New item name" 
                                                       value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                            </div>
                                        </td>
                                        <td class="pe-4">
                                            <input type="text" class="form-control form-control-sm" 
                                                   id="remark_<?php echo $index; ?>" 
                                                   placeholder="Optional">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="4" class="text-end fw-bold ps-4">Total to Receive:</td>
                                        <td class="pe-4 fw-bold" style="color: var(--teal);">
                                            <?php echo count($items); ?> item(s)
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Delivery Notes -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-pencil-square me-2" style="color: var(--teal);"></i>
                            Delivery Notes
                        </h5>
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="delivery_notes" rows="3" 
                                  placeholder="Any issues with delivery? (Damaged items, missing items, packaging issues, etc.)"></textarea>
                    </div>
                </div>

                <!-- Rating Tags -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-tags me-2" style="color: var(--teal);"></i>
                            Quick Feedback Tags
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">👍 On-time Delivery</span>
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">📦 Good Packaging</span>
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">✅ Complete Order</span>
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">💰 Fair Price</span>
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">🤝 Professional Service</span>
                            <span class="badge bg-light text-dark p-3 tag-badge" onclick="toggleTag(this)">📞 Good Communication</span>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex gap-3 mb-5">
                    <a href="main.php?view=purchase_orders&filter=to_receive" class="btn btn-outline-secondary flex-fill py-2">
                        <i class="bi bi-x-lg me-2"></i>Cancel
                    </a>
                    <a href="/main.php?view=returns&po_id=<?php echo $po_id; ?>" class="btn btn-outline-warning flex-fill py-2">
                        <i class="bi bi-arrow-return-left me-2"></i>Return/Refund
                    </a>
                    <button type="button" class="btn btn-teal flex-fill py-2" onclick="submitReceiveOrder()">
                        <i class="bi bi-check-lg me-2"></i>Confirm Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Global variables
    let currentRating = 0;
    let selectedTags = [];
    let currentOrderItems = <?php echo json_encode($items); ?>;
    let inventoryList = <?php echo json_encode($inventory_items); ?>;

    // Rating stars
    document.querySelectorAll('.rating-star').forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.dataset.rating);
            setRating(rating);
        });
    });

    function setRating(rating) {
        currentRating = rating;
        document.querySelectorAll('.rating-star').forEach((star, index) => {
            if (index < rating) {
                star.className = 'bi bi-star-fill rating-star';
            } else {
                star.className = 'bi bi-star rating-star';
            }
        });
    }

    function toggleTag(element) {
        const tag = element.textContent.trim();
        
        if (element.classList.contains('selected')) {
            element.classList.remove('selected', 'bg-success', 'text-white');
            element.classList.add('bg-light', 'text-dark');
            selectedTags = selectedTags.filter(t => t !== tag);
        } else {
            element.classList.add('selected', 'bg-success', 'text-white');
            element.classList.remove('bg-light', 'text-dark');
            selectedTags.push(tag);
        }
    }

    function toggleNewInventoryInput(index) {
        const select = document.getElementById(`inventory_select_${index}`);
        const container = document.getElementById(`new_inventory_container_${index}`);
        
        if (select && select.value === 'new') {
            container.style.display = 'block';
        } else if (select) {
            container.style.display = 'none';
        }
    }

    // Add event listeners for inventory selects
    document.querySelectorAll('.inventory-select').forEach(select => {
        select.addEventListener('change', function() {
            const index = this.dataset.index;
            toggleNewInventoryInput(index);
        });
    });

    function submitReceiveOrder() {
        if (currentRating === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Rate Supplier',
                text: 'Please rate the supplier before confirming receipt',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        const items = [];
        let hasMissingInventory = false;
        
        document.querySelectorAll('.received-qty').forEach((input, index) => {
            const received = parseInt(input.value) || 0;
            const inventorySelect = document.getElementById(`inventory_select_${index}`);
            const itemName = currentOrderItems[index]?.item_name || `Item ${index + 1}`;
            
            if (received === 0) return;
            
            if (!inventorySelect || !inventorySelect.value) {
                hasMissingInventory = true;
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Inventory Selection',
                    text: `Please select inventory for "${itemName}"`,
                    timer: 2000
                });
                return;
            }
            
            let inventoryAction = null;
            if (inventorySelect.value === 'new') {
                const newName = document.getElementById(`new_inventory_name_${index}`)?.value || itemName;
                inventoryAction = {
                    type: 'create',
                    name: newName
                };
            } else if (inventorySelect.value) {
                inventoryAction = {
                    type: 'add',
                    inventory_id: parseInt(inventorySelect.value)
                };
            }
            
            items.push({
                index: index,
                item_name: itemName,
                received: received,
                remark: document.getElementById(`remark_${index}`)?.value || '',
                inventory_action: inventoryAction
            });
        });
        
        if (hasMissingInventory) return;
        
        if (items.length === 0) {
            Swal.fire('Error', 'No items to receive', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Confirm Receipt',
            html: generateReceiptSummary(items),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#008080',
            confirmButtonText: 'Yes, Confirm Receipt',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                processReceiveOrder(items);
            }
        });
    }

    function generateReceiptSummary(items) {
        let html = '<div class="text-start">';
        
        html += '<div class="mb-3 pb-2 border-bottom">';
        html += '<span class="fw-medium">Rating: </span>';
        for (let i = 1; i <= 5; i++) {
            html += i <= currentRating ? 
                '<i class="bi bi-star-fill text-warning ms-1"></i>' : 
                '<i class="bi bi-star text-warning ms-1"></i>';
        }
        html += '</div>';
        
        html += '<div class="small">';
        html += '<span class="fw-medium">Items to receive:</span>';
        items.forEach(item => {
            let inventoryText = '';
            if (item.inventory_action) {
                if (item.inventory_action.type === 'create') {
                    inventoryText = ` <span class="text-info">(New item: ${item.inventory_action.name})</span>`;
                } else {
                    const inv = inventoryList.find(i => i.id === item.inventory_action.inventory_id);
                    inventoryText = inv ? ` <span class="text-info">(Add to: ${inv.name})</span>` : '';
                }
            }
            
            html += `<div class="mt-2 p-2 bg-light rounded">
                <div class="fw-medium">${item.item_name}</div>
                <div class="text-success">✓ Received: ${item.received}</div>
                ${inventoryText}
                ${item.remark ? `<div class="text-muted small mt-1">Note: ${escapeHtml(item.remark)}</div>` : ''}
            </div>`;
        });
        html += '</div>';
        
        html += '</div>';
        return html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

function processReceiveOrder(items) {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'receive_order');
    formData.append('po_id', <?php echo $po_id; ?>);
    formData.append('pr_id', <?php echo $po['pr_id']; ?>);
    formData.append('received_items', JSON.stringify(items));
    formData.append('delivery_notes', document.getElementById('delivery_notes')?.value || '');
    formData.append('supplier_rating', currentRating);
    formData.append('supplier_tags', JSON.stringify(selectedTags));
    
    console.log('Sending FormData:');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + pair[1]);
    }
    
    // ✅ Correct API path - go back one level from views folder
    fetch('../api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        Swal.close();
        console.log('Response data:', data);
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Order Received!',
                html: data.message || 'Order has been successfully received.',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                // ✅ Correct redirect - go back to root main.php
                window.location.href = '../main.php?view=purchase_orders&filter=completed';
            });
        } else {
            Swal.fire('Error', data.error || data.message || 'Failed to process order', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Fetch error:', error);
        Swal.fire('Error', 'Network error: ' + error.message, 'error');
    });
}
    </script>
</body>
</html>