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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Shopping Cart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
        <!-- Add SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
    <!-- Header with Teal theme -->
    <nav class="navbar navbar-dark mb-4" style="background-color: #008080;">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-cart3 me-2"></i>Purchasing Cart
            </span>
            <a href="select_products.php" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Continue Shopping
            </a>
        </div>
    </nav>

    <div class="container">
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
                            <a href="pr_form.php" class="btn btn-lg w-100" style="background-color: #008080; color: white;">
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
    
    fetch('../api/cart.php', {
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
    // Get product name and quantity for the message
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
            fetch('../api/cart.php', {
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