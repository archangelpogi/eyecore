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

$pr_id = $_GET['pr_id'] ?? 0;

// Fetch PR details
$prQuery = $pdo->prepare("
    SELECT pr.*, 
           CONCAT(u1.first_name, ' ', u1.last_name) as requested_by_name,
           CONCAT(u2.first_name, ' ', u2.last_name) as approved_by_name
    FROM purchase_requests pr
    LEFT JOIN users u1 ON pr.requested_by = u1.id
    LEFT JOIN users u2 ON pr.approved_by = u2.id
    WHERE pr.id = ? AND pr.status = 'Approved'
");
$prQuery->execute([$pr_id]);
$pr = $prQuery->fetch(PDO::FETCH_ASSOC);

if (!$pr) {
    header('Location: main.php?view=purchase_requests');
    exit;
}

// Fetch PR items
$itemsQuery = $pdo->prepare("
    SELECT pi.*, sp.photo_path 
    FROM pr_items pi
    LEFT JOIN supplier_products sp ON pi.supplier_product_id = sp.id
    WHERE pi.pr_id = ?
");
$itemsQuery->execute([$pr_id]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Group items by supplier
$suppliers = [];
foreach ($items as $item) {
    $supplier_id = $item['supplier_id'];
    if (!isset($suppliers[$supplier_id])) {
        // Fetch supplier details
        $supplierQuery = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $supplierQuery->execute([$supplier_id]);
        $supplier = $supplierQuery->fetch(PDO::FETCH_ASSOC);
        
        $suppliers[$supplier_id] = [
            'info' => $supplier,
            'items' => [],
            'total' => 0
        ];
    }
    
    $item['subtotal'] = $item['quantity'] * $item['unit_price'];
    $suppliers[$supplier_id]['items'][] = $item;
    $suppliers[$supplier_id]['total'] += $item['subtotal'];
}

$grand_total = array_sum(array_column($suppliers, 'total'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Purchase Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">
    <!-- Teal Header -->
    <nav class="navbar navbar-dark mb-4" style="background-color: #008080;">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-file-text me-2"></i>Create Purchase Order
            </span>
            <a href="../main.php?view=purchase_requests" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Back to PR List
            </a>
        </div>
    </nav>

    <div class="container">
        <form id="poForm" method="POST" action="../api/purchase_request.php" onsubmit="return submitPOForm(event)">
            <!-- Hidden inputs -->
            <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" name="items" id="itemsInput">

            <!-- PR INFO -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle me-2" style="color: #008080;"></i>
                        Purchase Request Details
                    </h5>
                </div>
                <div class="card-body px-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="text-secondary small">PR Number</label>
                            <div class="fw-bold"><?php echo $pr['pr_number']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small">Department</label>
                            <div><?php echo $pr['department']; ?></div>
                        </div>
                        <div class="col-md-2">
                            <label class="text-secondary small">Priority</label>
                            <div><span class="badge bg-info"><?php echo $pr['priority']; ?></span></div>
                        </div>
                        <div class="col-md-4">
                            <label class="text-secondary small">Requested By</label>
                            <div><?php echo $pr['requested_by_name']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small">Approved By</label>
                            <div class="text-success"><?php echo $pr['approved_by_name']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small">Approved Date</label>
                            <div><?php echo date('M d, Y', strtotime($pr['approved_at'])); ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="text-secondary small">Total Amount</label>
                            <div class="fw-bold text-primary">₱<?php echo number_format($pr['total_amount'], 2); ?></div>
                        </div>
                        <div class="col-12">
                            <label class="text-secondary small">Purpose</label>
                            <div class="bg-light p-3 rounded"><?php echo $pr['purpose']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Suppliers and Items -->
            <?php foreach ($suppliers as $supplier_id => $supplier): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="mb-1">
                                <i class="bi bi-shop me-2" style="color: #008080;"></i>
                                <?php echo htmlspecialchars($supplier['info']['supplier_name']); ?>
                            </h5>
                            <div class="small text-secondary">
                                <i class="bi bi-person"></i> <?php echo $supplier['info']['contact_person']; ?> |
                                <i class="bi bi-envelope"></i> <?php echo $supplier['info']['email']; ?> |
                                <i class="bi bi-telephone"></i> <?php echo $supplier['info']['mobile']; ?>
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
                                <?php foreach ($supplier['items'] as $item): 
                                    $imagePath = $item['photo_path'] ? '../' . $item['photo_path'] : '../assets/img/no-image.png';
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <?php if ($item['photo_path']): ?>
                                                <img src="../<?php echo $item['photo_path']; ?>" 
                                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;"
                                                     class="me-3 border">
                                            <?php else: ?>
                                                <div class="bg-light d-flex align-items-center justify-content-center me-3 border" 
                                                     style="width: 40px; height: 40px; border-radius: 4px;">
                                                    <i class="bi bi-image text-secondary"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <span class="fw-medium"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center"><?php echo $item['quantity']; ?></td>
                                    <td class="text-end text-secondary">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="text-end pe-4 fw-bold" style="color: #008080;">₱<?php echo number_format($item['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Grand Total -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="mb-0">Grand Total</h5>
                        </div>
                        <div class="col-md-6 text-end">
                            <h3 class="mb-0" style="color: #008080;">₱<?php echo number_format($grand_total, 2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="mb-0">
                        <i class="bi bi-truck me-2" style="color: #008080;"></i>
                        Order Details
                    </h5>
                </div>
                <div class="card-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary">Order Date</label>
                            <input type="date" class="form-control" name="order_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary">Expected Delivery</label>
                            <input type="date" class="form-control" name="expected_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary">Shipping Address</label>
                            <textarea class="form-control" name="shipping_address" rows="2">Clinic Address: 123 Eye Care Street, Manila</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary">Terms & Conditions</label>
                            <textarea class="form-control" name="terms" rows="2" placeholder="Optional"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <a href="main.php?view=purchase_requests" class="btn btn-outline-secondary w-100 py-2">
                        <i class="bi bi-arrow-left me-2"></i>Cancel
                    </a>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn w-100 py-2" style="background-color: #008080; color: white;">
                        <i class="bi bi-check-circle me-2"></i>Create Purchase Order
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    function submitPOForm(event) {
    event.preventDefault();
    
    // Get all suppliers
    const suppliers = <?php echo json_encode($suppliers); ?>;
    const suppliersList = Object.values(suppliers);
    
    if (suppliersList.length > 1) {
        // Multiple suppliers - create separate POs
        createMultiplePOs(suppliersList);
    } else {
        // Single supplier - create one PO
        createSinglePO();
    }
    
    return false;
}

function createMultiplePOs(suppliers) {
    Swal.fire({
        title: 'Creating Purchase Orders...',
        html: `Creating ${suppliers.length} separate POs`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const promises = suppliers.map(supplier => {
        const formData = new FormData(document.getElementById('poForm'));
        formData.set('supplier_id', supplier.info.id);
        formData.set('items', JSON.stringify(supplier.items));
        formData.set('total_amount', supplier.total);
        
        return fetch('../api/purchase_request.php', {
            method: 'POST',
            body: formData
        }).then(res => res.json());
    });
    
    Promise.all(promises)
        .then(results => {
            const successCount = results.filter(r => r.success).length;
            const poNumbers = results.map(r => r.po_number).join(', ');
            
            Swal.fire({
                icon: 'success',
                title: 'POs Created!',
                html: `
                    <p>Successfully created ${successCount} out of ${suppliers.length} Purchase Orders.</p>
                    <p class="fw-bold text-primary">${poNumbers}</p>
                `,
                confirmButtonColor: '#008080'
            }).then(() => {
                window.location.href = 'purchase_orders.php';
            });
        })
        .catch(error => {
            Swal.fire('Error', error.message, 'error');
        });
}

function createSinglePO() {
    const submitBtn = document.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating PO...';
    submitBtn.disabled = true;
    
    const formData = new FormData(document.getElementById('poForm'));
    formData.set('items', JSON.stringify(<?php echo json_encode($items); ?>));
    
    fetch('../api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'PO Created!',
                html: `
                    <p>Purchase Order has been created successfully.</p>
                    <p class="fw-bold text-primary">${data.po_number}</p>
                `,
                confirmButtonColor: '#008080'
            }).then(() => {
                window.location.href = '../main.php?view=purchase_requests'
            });
        } else {
            throw new Error(data.error);
        }
    })
    .catch(error => {
        Swal.fire('Error', error.message, 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}
    </script>
</body>
</html>