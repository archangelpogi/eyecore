<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /eyecore/admin/login.php');
    exit;
}

// GET PO ID
$po_id = $_GET['po_id'] ?? 0;

if (!$po_id) {
    die("ERROR: No PO ID provided");
}

// Get PO details with pr_id
$poQuery = $pdo->prepare("
    SELECT po.*, 
           s.supplier_name, s.contact_person, s.email, s.mobile, s.address,
           pr.pr_number, pr.department, pr.purpose, pr.id as pr_id
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    WHERE po.id = ? AND po.clinic_id = ?
");
$poQuery->execute([$po_id, $_SESSION['clinic_id']]);
$po = $poQuery->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    die("ERROR: PO not found for ID: $po_id");
}

// Get items from pr_items
$itemsQuery = $pdo->prepare("
    SELECT 
        pi.id as po_item_id,
        pi.item_name,
        pi.quantity,
        pi.unit_price,
        pi.total_price as total,
        pi.supplier_id,
        pi.supplier_name,
        pi.supplier_product_id
    FROM pr_items pi
    WHERE pi.pr_id = ?
");
$itemsQuery->execute([$po['pr_id']]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return/Refund - <?php echo htmlspecialchars($po['po_number']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .return-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
        }
        .return-card:hover {
            box-shadow: 0 4px 12px rgba(0,128,128,0.1);
        }
        .header-bar {
            background: white;
            border-bottom: 2px solid #008080;
            padding: 16px 0;
            margin-bottom: 24px;
        }
        .summary-badge {
            background: #e6f3f3;
            color: #008080;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #008080;
        }
        .btn-teal {
            background: #008080;
            color: white;
            border: none;
        }
        .btn-teal:hover {
            background: #006666;
            color: white;
        }
        .btn-outline-teal {
            background: transparent;
            color: #008080;
            border: 1px solid #008080;
        }
        .btn-outline-teal:hover {
            background: #008080;
            color: white;
        }
        .teal-border {
            border-color: #008080 !important;
        }
        .teal-text {
            color: #008080 !important;
        }
        .teal-bg-light {
            background: #e6f3f3 !important;
        }
        .upload-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .preview-item {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #008080;
        }
        .preview-item img, .preview-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-item .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .file-input-container {
            border: 2px dashed #008080;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-input-container:hover {
            border-color: #006666;
            background: #e6f3f3;
        }
        .file-input-container i {
            font-size: 2rem;
            color: #008080;
        }
        .badge-teal {
            background: #008080;
            color: white;
        }
        .form-control:focus, .form-select:focus {
            border-color: #008080;
            box-shadow: 0 0 0 0.2rem rgba(0,128,128,0.25);
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Order Summary Card -->
    <div class="return-card p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="fw-semibold mb-1"><?php echo htmlspecialchars($po['supplier_name']); ?></h6>
                <div class="small text-secondary">
                    <i class="bi bi-calendar3 me-1 teal-text"></i> Ordered: <?php echo date('M d, Y', strtotime($po['order_date'])); ?>
                </div>
                <div class="small text-secondary mt-1">
                    <i class="bi bi-tag me-1 teal-text"></i> PR#: <?php echo htmlspecialchars($po['pr_number']); ?>
                </div>
            </div>
            <div class="text-end">
                <span class="fw-bold teal-text">₱<?php echo number_format($po['total_amount'], 2); ?></span>
                <div class="summary-badge mt-1">
                    <i class="bi bi-arrow-return-left me-1"></i> Return Request
                </div>
            </div>
        </div>
    </div>

    <!-- Return Form Card -->
    <div class="return-card p-4">
        <h6 class="fw-semibold mb-3 teal-text">
            <i class="bi bi-box-seam me-2"></i>
            Select Items to Return
        </h6>
        
        <!-- Items List -->
        <div id="itemsList">
            <?php if (empty($items)): ?>
                <div class="text-center py-4 text-secondary">
                    <i class="bi bi-inbox fs-1 d-block mb-2 teal-text"></i>
                    <p class="text-secondary mt-3">No items found for this order.</p>
                </div>
            <?php else: ?>
                <?php foreach ($items as $index => $item): ?>
                <div class="border rounded-3 p-3 mb-2">
                    <div class="d-flex align-items-center gap-3">
                        <!-- Checkbox -->
                        <input type="checkbox" class="form-check-input return-checkbox" 
                               data-index="<?php echo $index; ?>" 
                               onchange="toggleItem(<?php echo $index; ?>)">
                        
                        <!-- Product Image - Placeholder -->
                        <div class="bg-light d-flex align-items-center justify-content-center product-image border">
                            <i class="bi bi-image text-secondary"></i>
                        </div>
                        
                        <!-- Item Details -->
                        <div class="flex-grow-1">
                            <div class="fw-medium"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="small text-secondary">
                                Ordered: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['unit_price'], 2); ?>
                            </div>
                        </div>
                        
                        <!-- Quantity Input -->
                        <div style="width: 100px;">
                            <input type="number" class="form-control form-control-sm return-qty" 
                                   id="qty_<?php echo $index; ?>" 
                                   value="0" min="0" max="<?php echo $item['quantity']; ?>" 
                                   data-index="<?php echo $index; ?>" 
                                   onchange="calculateTotal()" disabled>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Return Details Section -->
        <div class="mt-4">
            <h6 class="fw-semibold mb-3 teal-text">
                <i class="bi bi-pencil-square me-2"></i>
                Return Details
            </h6>
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Reason for Return <span class="text-danger">*</span></label>
                    <select class="form-select" id="reason">
                        <option value="">-- Select reason --</option>
                        <option value="Damaged">Damaged/Defective item</option>
                        <option value="Wrong Item">Wrong item received</option>
                        <option value="Missing Parts">Missing parts/accessories</option>
                        <option value="Quality Issue">Quality not as expected</option>
                        <option value="Expired">Expired/Short expiry</option>
                        <option value="Other">Other reason</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Refund Method <span class="text-danger">*</span></label>
                    <select class="form-select" id="refund_method">
                        <option value="Original">Original payment method</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Credit Note">Credit Note (for future orders)</option>
                        <option value="Replacement">Replacement item</option>
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">Detailed Description</label>
                <textarea class="form-control" id="description" rows="2" 
                          placeholder="Tell us more about the issue..."></textarea>
            </div>

            <!-- UPLOAD SECTION - PHOTOS & VIDEOS -->
            <div class="mt-4">
                <label class="form-label fw-semibold teal-text">
                    <i class="bi bi-camera me-2"></i>
                    Upload Evidence (Photos/Videos)
                </label>
                <div class="file-input-container" onclick="document.getElementById('fileInput').click()">
                    <i class="bi bi-cloud-upload"></i>
                    <p class="mt-2 mb-0">Click to upload or drag files here</p>
                    <small class="text-muted">Supported: JPG, PNG, GIF, MP4 (Max 10 files)</small>
                </div>
                <input type="file" id="fileInput" multiple accept="image/*,video/*" style="display: none;" onchange="handleFileSelect(this)">
                
                <!-- Preview Container -->
                <div id="previewContainer" class="upload-preview mt-3"></div>
            </div>

            <div class="mt-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="contact_number" 
                       placeholder="e.g., 09123456789">
                <small class="text-muted">Supplier will contact you for return instructions</small>
            </div>

            <!-- Refund Summary -->
            <div class="teal-bg-light p-3 rounded-3 mt-4 border teal-border">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-medium">Estimated Refund Amount:</span>
                    <span class="fw-bold fs-5 teal-text" id="refund_amount">₱0.00</span>
                </div>
                <small class="text-secondary d-block mt-1">
                    <i class="bi bi-info-circle me-1 teal-text"></i>
                    Final amount may vary based on supplier's assessment
                </small>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mt-4">
            <a href="purchase_orders.php" class="btn btn-outline-secondary flex-fill py-2">
                <i class="bi bi-x-lg me-1"></i> Cancel
            </a>
            <button type="button" class="btn btn-teal flex-fill py-2" onclick="submitReturn()">
                <i class="bi bi-send me-1"></i> Submit Return Request
            </button>
        </div>
    </div>
    
    <!-- Important Notes -->
    <div class="small text-secondary mt-3 text-center">
        <i class="bi bi-shield-check me-1 teal-text"></i>
        Your return request will be sent to the supplier for approval.
        You'll be notified once processed.
    </div>
</div>

<script>
// Pass PHP data to JavaScript
let items = <?php echo json_encode($items); ?>;
let selectedFiles = [];

// Toggle item selection
function toggleItem(index) {
    const checkbox = document.querySelector(`.return-checkbox[data-index="${index}"]`);
    const qtyInput = document.getElementById(`qty_${index}`);
    
    if (checkbox && qtyInput) {
        qtyInput.disabled = !checkbox.checked;
        if (checkbox.checked && qtyInput.value == 0) {
            qtyInput.value = 1;
        } else if (!checkbox.checked) {
            qtyInput.value = 0;
        }
        calculateTotal();
    }
}

// Calculate refund total
function calculateTotal() {
    let total = 0;
    
    document.querySelectorAll('.return-checkbox:checked').forEach(checkbox => {
        const index = checkbox.dataset.index;
        const qtyInput = document.getElementById(`qty_${index}`);
        const qty = parseInt(qtyInput?.value) || 0;
        const price = parseFloat(items[index]?.unit_price) || 0;
        
        total += price * qty;
    });
    
    document.getElementById('refund_amount').textContent = '₱' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Handle file selection
function handleFileSelect(input) {
    const files = Array.from(input.files);
    
    // Limit to 10 files
    if (selectedFiles.length + files.length > 10) {
        Swal.fire('Error', 'Maximum of 10 files allowed', 'error');
        return;
    }
    
    // Add new files
    files.forEach(file => {
        selectedFiles.push(file);
    });
    
    updatePreview();
    
    // Clear input so same file can be selected again if removed
    input.value = '';
}

// Remove file
function removeFile(index) {
    selectedFiles.splice(index, 1);
    updatePreview();
}

// Update preview container
function updatePreview() {
    const container = document.getElementById('previewContainer');
    container.innerHTML = '';
    
    selectedFiles.forEach((file, index) => {
        const reader = new FileReader();
        const previewItem = document.createElement('div');
        previewItem.className = 'preview-item';
        
        reader.onload = function(e) {
            if (file.type.startsWith('image/')) {
                previewItem.innerHTML = `
                    <img src="${e.target.result}" alt="Preview">
                    <button class="remove-btn" onclick="removeFile(${index})">
                        <i class="bi bi-x"></i>
                    </button>
                `;
            } else if (file.type.startsWith('video/')) {
                previewItem.innerHTML = `
                    <video src="${e.target.result}"></video>
                    <button class="remove-btn" onclick="removeFile(${index})">
                        <i class="bi bi-x"></i>
                    </button>
                `;
            }
        };
        
        reader.readAsDataURL(file);
        container.appendChild(previewItem);
    });
}

// Submit return request
function submitReturn() {
    // Get selected items
    const selectedItems = [];
    document.querySelectorAll('.return-checkbox:checked').forEach(checkbox => {
        const index = checkbox.dataset.index;
        const qty = parseInt(document.getElementById(`qty_${index}`).value) || 0;
        
        if (qty > 0) {
            selectedItems.push({
                po_item_id: items[index]?.po_item_id,
                item_id: items[index]?.item_id,
                item_name: items[index]?.item_name,
                quantity: items[index]?.quantity,
                unit_price: items[index]?.unit_price,
                return_quantity: qty,
                supplier_id: items[index]?.supplier_id,
                supplier_name: items[index]?.supplier_name,
                supplier_product_id: items[index]?.supplier_product_id || 0
            });
        }
    });
    
    // Validate
    if (selectedItems.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items Selected',
            text: 'Please select at least one item to return',
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    const reason = document.getElementById('reason').value;
    if (!reason) {
        Swal.fire({
            icon: 'warning',
            title: 'Reason Required',
            text: 'Please select a reason for return',
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    const contact = document.getElementById('contact_number').value.trim();
    if (!contact) {
        Swal.fire({
            icon: 'warning',
            title: 'Contact Number Required',
            text: 'Please provide a contact number',
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    // Confirm submission
    Swal.fire({
        title: 'Submit Return Request?',
        html: `
            <div class="text-start">
                <p><strong>Items:</strong> ${selectedItems.length}</p>
                <p><strong>Reason:</strong> ${reason}</p>
                <p><strong>Files:</strong> ${selectedFiles.length}</p>
                <p><strong>Refund Amount:</strong> ${document.getElementById('refund_amount').textContent}</p>
                <hr>
                <p class="text-muted small">This request will be sent to the supplier for approval.</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, submit',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            processReturn(selectedItems);
        }
    });
}

// Process return submission
function processReturn(selectedItems) {
    Swal.fire({
        title: 'Submitting...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'create_return');
    formData.append('po_id', <?php echo $po_id; ?>);
    formData.append('pr_id', <?php echo $po['pr_id']; ?>);
    formData.append('items', JSON.stringify(selectedItems));
    formData.append('reason', document.getElementById('reason').value);
    formData.append('description', document.getElementById('description').value);
    formData.append('refund_method', document.getElementById('refund_method').value);
    formData.append('contact_person', '<?php echo $_SESSION['name'] ?? 'Current User'; ?>');
    formData.append('contact_number', document.getElementById('contact_number').value);
    formData.append('email', '');
    
    // Add files
    selectedFiles.forEach((file, index) => {
        formData.append('photos[]', file);
    });
    
    // API path
    fetch('../api/returns.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response. Check API path.');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Return Request Submitted!',
                html: `
                    <strong>Reference #: ${data.return_number}</strong><br>
                    Amount: ₱${parseFloat(data.refund_amount).toFixed(2)}
                `,
                confirmButtonColor: '#008080'
            }).then(() => {
                window.location.href = '../main.php?view=purchase_orders';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                text: data.error || 'Something went wrong',
                confirmButtonColor: '#008080'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Please try again. Error: ' + error.message,
            confirmButtonColor: '#008080'
        });
    });
}

// Add event listeners when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('Returns page loaded with', items.length, 'items');
});
</script>

</body>
</html>