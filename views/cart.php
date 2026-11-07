<?php
include __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

$cart = $_SESSION['product_cart'] ?? [];
$suppliers = [];

if (!empty($cart)) {
    $ids = implode(',', array_keys($cart));
    $query = $pdo->query("
        SELECT sp.*, s.supplier_name, s.id as supplier_id 
        FROM supplier_products sp
        LEFT JOIN suppliers s ON sp.supplier_id = s.id
        WHERE sp.id IN ($ids)
    ");
    
    while ($product = $query->fetch(PDO::FETCH_ASSOC)) {
        $supplier_id = $product['supplier_id'];
        $product['cart_qty'] = $cart[$product['id']];
        $product['subtotal'] = $product['cost_price'] * $product['cart_qty'];
        
        if (!isset($suppliers[$supplier_id])) {
            $suppliers[$supplier_id] = [
                'name' => $product['supplier_name'],
                'products' => [],
                'total' => 0
            ];
        }
        
        $suppliers[$supplier_id]['products'][] = $product;
        $suppliers[$supplier_id]['total'] += $product['subtotal'];
    }
}

$grand_total = array_sum(array_column($suppliers, 'total'));

// ✅ BUILD PAYLOAD AND SAVE TO SESSION
$cartItemsPayload = [];
foreach ($suppliers as $supplier_id => $supplier) {
    foreach ($supplier['products'] as $p) {
        $cartItemsPayload[] = [
            'id'                 => $p['id'],
            'name'               => $p['product_name'],
            'item_id'            => '',
            'supplier_id'        => $supplier_id ?: 0,
            'supplier'           => $supplier['name'] ?? '',
            'suggested_quantity' => $p['cart_qty'],
            'estimated_cost'     => $p['subtotal'],
            'current_stock'      => $p['stock'] ?? 0,
        ];
    }
}
$_SESSION['pending_pr_items'] = $cartItemsPayload;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Shopping Cart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
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
    .nav-back i { font-size: 1rem; }
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
    .nav-title i { font-size: 1rem; color: #008080; flex-shrink: 0; }
    .nav-title span { overflow: hidden; text-overflow: ellipsis; }
    @media (max-width: 768px) {
        .page-navbar { padding: 10px 14px; gap: 8px; }
        .nav-back { padding: 6px 10px; font-size: 0.85rem; }
        .nav-back span { display: none; }
        .nav-back i { font-size: 1.1rem; }
        .nav-title { font-size: 0.85rem; padding: 6px 10px; }
        .nav-title span { max-width: 140px; }
    }
    </style>
</head>
<body class="bg-light">
<div class="page-navbar">
    <a class="nav-back" href="select_products.php">
        <i class="bi bi-arrow-left"></i>
        <span>Continue Shopping</span>
    </a>

    <div class="nav-title">
        <i class="bi bi-cart3"></i>
        <span>Purchasing Cart</span>
    </div>

    <div style="width: 140px;"></div>
</div>

<div class="container px-3 px-md-4 py-4">
    <?php if (empty($suppliers)): ?>
            <!-- Empty Cart State -->
            <div class="text-center py-5">
                <i class="bi bi-cart-x display-1 text-secondary opacity-50"></i>
                <h4 class="mt-3 text-secondary">Your cart is empty</h4>
                <p class="text-secondary mb-4">Start adding items from the product catalog</p>
                <a href="select_products.php" class="btn btn-lg px-4" style="background-color: #008080; color: white;">
                    <i class="bi bi-shop me-2"></i>Browse Products
                </a>
            </div>
        <?php else: ?>
            
            <?php foreach ($suppliers as $supplier_id => $supplier): ?>
                <!-- Supplier Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">
                                    <i class="bi bi-shop me-2" style="color: #008080;"></i>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </h5>
                                <span class="badge bg-light text-secondary">Supplier ID: <?php echo $supplier_id; ?></span>
                            </div>
                            <h5 class="mb-0" style="color: #008080;">₱<?php echo number_format($supplier['total'], 2); ?></h5>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Product</th>
                                        <th>Price</th>
                                        <th style="width: 150px;">Quantity</th>
                                        <th>Subtotal</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($supplier['products'] as $product): 
                                        $imagePath = $product['photo_path'] ? '../' . $product['photo_path'] : '../assets/img/no-image.png';
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <img src="<?php echo $imagePath; ?>" 
                                                     style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;"
                                                     class="me-3 border"
                                                     onerror="this.src='../assets/img/no-image.png'">
                                                <div>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($product['product_name']); ?></span>
                                                    <div class="small text-secondary">
                                                        <i class="bi bi-box-seam me-1"></i>Min: <?php echo $product['min_order_qty']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-secondary">₱<?php echo number_format($product['cost_price'], 2); ?></td>
                                        <td>
                                            <div class="input-group input-group-sm" style="width: 120px;">
                                            <button class="btn btn-outline-secondary border" type="button" 
                                                    onclick="updateQuantity(<?php echo $product['id']; ?>, 'decrease', <?php echo $product['cart_qty']; ?>, <?php echo $product['min_order_qty']; ?>)">
                                                <i class="bi bi-dash"></i>
                                            </button>
                                                <input type="text" class="form-control text-center bg-white border" 
                                                       value="<?php echo $product['cart_qty']; ?>" 
                                                       readonly>
                                            <button class="btn btn-outline-secondary border" type="button" 
                                                    onclick="updateQuantity(<?php echo $product['id']; ?>, 'increase', <?php echo $product['cart_qty']; ?>, <?php echo $product['min_order_qty']; ?>)">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                            </div>
                                        </td>
                                        <td class="fw-bold" style="color: #008080;">₱<?php echo number_format($product['subtotal'], 2); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-link text-danger p-0" 
                                                    onclick="removeFromCart(<?php echo $product['id']; ?>)"
                                                    title="Remove item">
                                                <i class="bi bi-trash fs-5"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Grand Total & Actions -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <a href="select_products.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Continue Shopping
                    </a>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-secondary">Subtotal</span>
                                <span class="fw-bold">₱<?php echo number_format($grand_total, 2); ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="mb-0">Total</h5>
                                <h4 class="mb-0" style="color: #008080;">₱<?php echo number_format($grand_total, 2); ?></h4>
                            </div>
                            <!-- ✅ FIXED BUTTON: Absolute path at direct to action=cart -->
                            <a href="/views/pr_form.php?action=cart" class="btn btn-lg w-100" style="background-color: #008080; color: white;">
                                <i class="bi bi-check-circle me-2"></i>Proceed to PR Form
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
function updateQuantity(productId, action, currentQty, minQty) {
    let newQty = currentQty;
    
    if (action === 'increase') {
        newQty = currentQty + 1;
    } else if (action === 'decrease') {
        if (currentQty <= minQty) {
            Swal.fire({
                title: 'Minimum Quantity',
                text: `Minimum order quantity is ${minQty}`,
                icon: 'info',
                timer: 1500,
                showConfirmButton: false
            });
            return;
        }
        newQty = currentQty - 1;
    }
    
    fetch('/api/cart.php', { // Absolute path
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=update&product_id=' + productId + '&update_action=' + action
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}
    
function removeFromCart(productId) {
    const row = event.target.closest('tr');
    const productName = row.querySelector('.fw-medium').textContent;
    const quantity = row.querySelector('input[readonly]').value;
    
    Swal.fire({
        title: 'Remove Item?',
        html: `
            <div class="text-start">
                <p class="mb-2">Do you want to remove this item?</p>
                <div class="bg-light p-3 rounded">
                    <strong>${productName}</strong><br>
                    <span class="text-secondary">Quantity: ${quantity}</span>
                </div>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, remove it',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('/api/cart.php', { // Absolute path
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=remove&product_id=' + productId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Removed!',
                        text: 'Item has been removed from your cart.',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }
    });
}
    </script>
</body>
</html>