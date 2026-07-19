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

// Get cart items
$cart = $_SESSION['product_cart'] ?? [];
$suppliers = [];

if (!empty($cart)) {
    $ids = implode(',', array_keys($cart));
    $query = $pdo->query("
        SELECT sp.*, s.supplier_name, s.id as supplier_id,
               s.contact_person, s.email, s.mobile, s.city, s.payment_terms
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
                'contact_person' => $product['contact_person'],
                'email' => $product['email'],
                'mobile' => $product['mobile'],
                'city' => $product['city'],
                'payment_terms' => $product['payment_terms'],
                'products' => [],
                'total' => 0
            ];
        }
        
        $suppliers[$supplier_id]['products'][] = $product;
        $suppliers[$supplier_id]['total'] += $product['subtotal'];
    }
}

$supplier_count = count($suppliers);
$grand_total = array_sum(array_column($suppliers, 'total'));

// If no items in cart, redirect back to select products
if (empty($suppliers)) {
    header('Location: select_products.php');
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
    </style>
</head>
<body class="bg-light">
    <!-- Teal Header -->
    <nav class="navbar navbar-dark mb-4" style="background-color: #008080;">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-file-text me-2"></i>New Purchase Request
            </span>
            <a href="cart.php" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Back to Cart
            </a>
        </div>
    </nav>

    <div class="container">
        <form id="prForm">
            <!-- Hidden inputs -->
            <input type="hidden" name="action" value="create_multiple_prs">

            <!-- Multiple Suppliers Notice -->
            <?php if ($supplier_count > 1): ?>
            <div class="alert alert-info d-flex align-items-center mb-4">
                <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                <div>
                    <strong><?php echo $supplier_count; ?> Suppliers Detected</strong><br>
                    This will create <strong><?php echo $supplier_count; ?> separate Purchase Requests</strong> - one for each supplier.
                </div>
            </div>
            <?php endif; ?>

            <!-- Request Details Card (common for all PRs) -->
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

            <!-- Items by Supplier -->
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

            <!-- Grand Total Card -->
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

            <!-- Submit Buttons -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <a href="cart.php" class="btn btn-outline-secondary w-100 py-2">
                        <i class="bi bi-arrow-left me-2"></i>Back to Cart
                    </a>
                </div>
                <div class="col-md-6">
                    <button type="button" class="btn w-100 py-2" style="background-color: #008080; color: white;" onclick="submitMultiplePRs()">
                        <i class="bi bi-check-circle me-2"></i>Create <?php echo $supplier_count; ?> PR(s)
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    function submitMultiplePRs() {
        // Validate common fields
        const department = document.getElementById('department').value;
        const purpose = document.getElementById('purpose').value;
        
        if (!department || !purpose) {
            Swal.fire('Error', 'Please fill in all required fields', 'error');
            return;
        }
        
        const suppliers = <?php echo json_encode($suppliers); ?>;
        const supplierCount = Object.keys(suppliers).length;
        
        Swal.fire({
            title: 'Creating Purchase Requests...',
            html: `Creating <strong>${supplierCount}</strong> PR(s) - one per supplier`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        const promises = [];
        const formData = new FormData();
        formData.append('action', 'create_multiple_prs');
        formData.append('department', document.getElementById('department').value);
        formData.append('priority', document.getElementById('priority').value);
        formData.append('needed_by', document.getElementById('needed_by').value);
        formData.append('purpose', document.getElementById('purpose').value);
        formData.append('notes', document.getElementById('notes').value);
        formData.append('suppliers', JSON.stringify(suppliers));
        
        fetch('../api/purchase_request.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Clear cart
                return fetch('../api/cart.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=clear'
                }).then(() => data);
            } else {
                throw new Error(data.error);
            }
        })
        .then(data => {
            Swal.fire({
                icon: 'success',
                title: 'PRs Created!',
                html: `
                    <p>Successfully created <strong>${data.count}</strong> Purchase Request(s)</p>
                    <div class="text-start small bg-light p-3 rounded mt-3">
                        ${data.pr_numbers.map(pr => `<div><i class="bi bi-check-circle-fill text-success me-2"></i>${pr}</div>`).join('')}
                    </div>
                `,
                confirmButtonColor: '#008080',
                confirmButtonText: 'View Purchase Requests'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../main.php?view=purchase_requests';
                }
            });
        })
        .catch(error => {
            Swal.fire('Error', error.message, 'error');
        });
    }
    </script>
</body>
</html>