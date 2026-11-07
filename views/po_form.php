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
$clinic_id = $_SESSION['clinic_id'] ?? 1;

// ============================================
// FETCH CLINIC DETAILS
// ============================================
$clinicQuery = $pdo->prepare("
    SELECT 
        clinic_name, address, street_address, barangay, city, province, postal_code,
        contact, phone, clinic_email, branch, building_name, unit_floor,
        allow_cod, payment_method_online, payment_method_onsite, payment_policy,
        gcash_number, gcash_name,
        bank_name, bank_account_number, bank_account_name,
        offers_delivery, delivery_fee, free_delivery_minimum
    FROM clinics 
    WHERE id = ?
");
$clinicQuery->execute([$clinic_id]);
$clinic = $clinicQuery->fetch(PDO::FETCH_ASSOC);

if (!$clinic) {
    $clinic = [
        'clinic_name' => 'Eye Care Clinic',
        'address' => '',
        'contact' => '',
        'clinic_email' => '',
        'allow_cod' => 0,
        'payment_method_online' => 1,
        'payment_method_onsite' => 1,
        'payment_policy' => 'full_payment',
    ];
}

// ============================================
// FETCH SYSTEM SETTINGS (VAT)
// ============================================
$settingsQuery = $pdo->prepare("
    SELECT site_name, currency, vat_rate
    FROM system_settings 
    LIMIT 1
");
$settingsQuery->execute();
$settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $settings = [
        'site_name' => 'Eyecore',
        'currency' => 'PHP',
        'vat_rate' => 0.12,
    ];
}

$vat_rate = (float)$settings['vat_rate'];
$currency = $settings['currency'];

// ============================================
// BUILD SHIPPING ADDRESS (walang email/phone)
// ============================================
$address_parts = [];
if (!empty($clinic['building_name']))  $address_parts[] = $clinic['building_name'];
if (!empty($clinic['unit_floor']))     $address_parts[] = 'Unit ' . $clinic['unit_floor'];
if (!empty($clinic['street_address'])) $address_parts[] = $clinic['street_address'];
if (!empty($clinic['barangay']))       $address_parts[] = 'Brgy. ' . $clinic['barangay'];
if (!empty($clinic['city']))           $address_parts[] = $clinic['city'];
if (!empty($clinic['province']))       $address_parts[] = $clinic['province'];
if (!empty($clinic['postal_code']))    $address_parts[] = $clinic['postal_code'];

$full_address = !empty($address_parts) 
    ? implode(', ', $address_parts) 
    : ($clinic['address'] ?? '');

$default_shipping_address = trim(
    $clinic['clinic_name'] . 
    (!empty($clinic['branch']) ? ' (' . $clinic['branch'] . ')' : '') . "\n" .
    $full_address
);

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

// VAT computation (VAT-inclusive)
$net_total = $grand_total / (1 + $vat_rate);
$vat_amount = $grand_total - $net_total;

// ============================================
// BUILD ALLOWED PAYMENT METHODS
// ============================================
$allowed_payment_methods = [];

if (!empty($clinic['allow_cod'])) {
    $allowed_payment_methods['COD'] = 'COD (Cash on Delivery)';
}

if (!empty($clinic['bank_name']) && !empty($clinic['bank_account_number'])) {
    $allowed_payment_methods['Bank Transfer'] = 'Bank Transfer (' . $clinic['bank_name'] . ')';
} elseif (!empty($clinic['payment_method_online'])) {
    $allowed_payment_methods['Bank Transfer'] = 'Bank Transfer';
}

$allowed_payment_methods['Check'] = 'Check';
$allowed_payment_methods['Net 30'] = 'Net 30 (Payment after 30 days)';

if (empty($allowed_payment_methods)) {
    $allowed_payment_methods['Bank Transfer'] = 'Bank Transfer';
}

$suggested_method = 'Bank Transfer';
if ($grand_total < 5000 && !empty($clinic['allow_cod'])) {
    $suggested_method = 'COD';
}
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
    <nav class="navbar navbar-dark mb-4" style="background-color: #008080;">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-file-text me-2"></i>Create Purchase Order
            </span>
            <a href="../main.php?view=purchase_orders&filter=awaiting" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Back to Purchase Orders
            </a>
        </div>
    </nav>

    <div class="container">
        <form id="poForm" method="POST" action="../api/purchase_request.php" onsubmit="return submitPOForm(event)">
            <input type="hidden" name="pr_id" value="<?php echo $pr_id; ?>">
            <input type="hidden" name="action" value="create_po">
            <input type="hidden" name="items" id="itemsInput">
            <input type="hidden" name="grand_total" value="<?php echo $grand_total; ?>">
            <input type="hidden" name="net_total" value="<?php echo $net_total; ?>">
            <input type="hidden" name="vat_amount" value="<?php echo $vat_amount; ?>">
            <input type="hidden" name="vat_rate" value="<?php echo $vat_rate; ?>">

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
                                <?php foreach ($supplier['items'] as $item): ?>
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

            <!-- PAYMENT SUMMARY with VAT -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-5">
                            <h5 class="mb-3">
                                <i class="bi bi-calculator me-2" style="color: #008080;"></i>
                                Payment Summary
                            </h5>
                            <div class="small text-muted">
                                VAT-inclusive ang mga presyo. Naka-base sa system VAT rate: 
                                <strong><?php echo number_format($vat_rate * 100, 0); ?>%</strong>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-secondary">Subtotal (Net of VAT):</td>
                                    <td class="text-end fw-medium">₱<?php echo number_format($net_total, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-secondary">VAT (<?php echo number_format($vat_rate * 100, 0); ?>%):</td>
                                    <td class="text-end fw-medium">₱<?php echo number_format($vat_amount, 2); ?></td>
                                </tr>
                                <tr class="border-top">
                                    <td class="fw-bold fs-5 pt-3">Grand Total:</td>
                                    <td class="text-end fw-bold fs-5 pt-3" style="color: #008080;">
                                        ₱<?php echo number_format($grand_total, 2); ?>
                                    </td>
                                </tr>
                            </table>
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

                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" name="payment_method" id="poPaymentMethod" required>
                                <option value="">-- Select Payment Method --</option>
                                <?php foreach ($allowed_payment_methods as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>"
                                            <?php echo ($value === $suggested_method) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="paymentHint">
                                <?php if ($grand_total < 5000 && !empty($clinic['allow_cod'])): ?>
                                    <i class="bi bi-cash text-success me-1"></i>
                                    COD auto-suggested (order < ₱5,000)
                                <?php else: ?>
                                    <i class="bi bi-info-circle me-1"></i>
                                    Bank Transfer recommended for orders ≥ ₱5,000
                                <?php endif; ?>
                            </small>
                        </div>

                        <div class="col-md-6" id="paymentTermsWrapper" style="display: none;">
                            <label class="form-label text-secondary">Payment Terms</label>
                            <input type="text" class="form-control" name="payment_terms" 
                                   placeholder="e.g., Net 30, 50% DP" value="">
                        </div>

                        <div class="col-12">
                            <label class="form-label text-secondary">Shipping Address</label>
                            <textarea class="form-control" name="shipping_address" rows="3"><?php echo htmlspecialchars($default_shipping_address); ?></textarea>
                            <small class="text-muted">
                                <i class="bi bi-pencil me-1"></i>
                                Auto-filled from clinic profile. Pwede mong i-edit kung iba ang delivery address.
                            </small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label text-secondary">
                                <i class="bi bi-person me-1"></i> Contact Person
                            </label>
                            <input type="text" class="form-control" name="contact_person" 
                                   value="<?php echo htmlspecialchars($clinic['contact'] ?? ''); ?>"
                                   placeholder="Contact person">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label text-secondary">
                                <i class="bi bi-telephone me-1"></i> Telephone
                            </label>
                            <input type="text" class="form-control" name="contact_phone" 
                                   value="<?php echo htmlspecialchars($clinic['contact'] ?? ''); ?>"
                                   placeholder="+63 9XX XXX XXXX">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label text-secondary">
                                <i class="bi bi-envelope me-1"></i> Email
                            </label>
                            <input type="email" class="form-control" name="contact_email" 
                                   value="<?php echo htmlspecialchars($clinic['clinic_email'] ?? ''); ?>"
                                   placeholder="email@example.com">
                        </div>

                        <div class="col-12">
                            <label class="form-label text-secondary">Terms & Conditions</label>
                            <textarea class="form-control" name="terms" rows="2" placeholder="Optional"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <a href="../main.php?view=purchase_orders&filter=awaiting" class="btn btn-outline-secondary w-100 py-2">
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
    const PR_ID = <?php echo (int)$pr_id; ?>;
    const GRAND_TOTAL = <?php echo (float)$grand_total; ?>;
    const CLINIC_ALLOWS_COD = <?php echo !empty($clinic['allow_cod']) ? 'true' : 'false'; ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const paymentSelect = document.getElementById('poPaymentMethod');
        const hint = document.getElementById('paymentHint');
        const termsWrapper = document.getElementById('paymentTermsWrapper');
        
        const hints = {
            'COD': '<i class="bi bi-cash text-success me-1"></i> Rider will collect cash upon delivery. Auto-marked as Paid.',
            'Bank Transfer': '<i class="bi bi-bank text-info me-1"></i> Finance will transfer after delivery. Status: Ready to Pay.',
            'Check': '<i class="bi bi-file-earmark-text text-warning me-1"></i> Finance will issue a check after delivery.',
            'Net 30': '<i class="bi bi-calendar-check text-primary me-1"></i> Payment due 30 days after delivery.'
        };
        
        function updatePaymentUI() {
            const value = paymentSelect.value;
            
            if (value === 'Net 30' || value === 'Bank Transfer' || value === 'Check') {
                termsWrapper.style.display = 'block';
            } else {
                termsWrapper.style.display = 'none';
            }
            
            if (hints[value]) {
                hint.innerHTML = hints[value];
                hint.className = '';
            }
        }
        
        paymentSelect.addEventListener('change', updatePaymentUI);
        updatePaymentUI();
    });

    function submitPOForm(event) {
        event.preventDefault();
        
        const paymentMethod = document.getElementById('poPaymentMethod').value;
        if (!paymentMethod) {
            Swal.fire('Error', 'Please select a payment method', 'error');
            return false;
        }
        
        const suppliers = <?php echo json_encode($suppliers); ?>;
        const suppliersList = Object.values(suppliers);
        
        if (suppliersList.length > 1) {
            createMultiplePOs(suppliersList, paymentMethod);
        } else {
            createSinglePO(paymentMethod);
        }
        
        return false;
    }

    function createMultiplePOs(suppliers, paymentMethod) {
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
            formData.set('payment_method', paymentMethod);
            
            const supplierVatRate = <?php echo $vat_rate; ?>;
            const supplierNet = supplier.total / (1 + supplierVatRate);
            const supplierVat = supplier.total - supplierNet;
            
            formData.set('net_total', supplierNet.toFixed(2));
            formData.set('vat_amount', supplierVat.toFixed(2));
            formData.set('vat_rate', supplierVatRate);
            
            return fetch('../api/purchase_request.php', {
                method: 'POST',
                body: formData
            }).then(res => res.json());
        });
        
        Promise.all(promises)
            .then(results => {
                const successCount = results.filter(r => r.success).length;
                const poNumbers = results.map(r => r.po_number).filter(Boolean).join(', ');
                
                Swal.fire({
                    icon: 'success',
                    title: 'POs Created!',
                    html: `
                        <p>Successfully created ${successCount} out of ${suppliers.length} Purchase Orders.</p>
                        <p class="fw-bold text-primary">${poNumbers}</p>
                        <p class="small text-muted">Payment Method: <strong>${paymentMethod}</strong></p>
                    `,
                    confirmButtonColor: '#008080',
                    confirmButtonText: 'View Purchase Orders'
                }).then(() => {
                    window.location.href = '../main.php?view=purchase_orders';
                });
            })
            .catch(error => {
                Swal.fire('Error', error.message, 'error');
            });
    }

    function createSinglePO(paymentMethod) {
        const submitBtn = document.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating PO...';
        submitBtn.disabled = true;
        
        const formData = new FormData(document.getElementById('poForm'));
        formData.set('items', JSON.stringify(<?php echo json_encode($items); ?>));
        formData.set('payment_method', paymentMethod);
        
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
                        <p class="small text-muted">Payment Method: <strong>${paymentMethod}</strong></p>
                    `,
                    confirmButtonColor: '#008080',
                    confirmButtonText: 'View Purchase Orders'
                }).then(() => {
                    window.location.href = '../main.php?view=purchase_orders';
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