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

// Handle AJAX request for updating return status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_return') {
    header('Content-Type: application/json');
    
    $po_id = $_POST['po_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    if (!$po_id || !$status) {
        echo json_encode(['success' => false, 'error' => 'PO ID and status required']);
        exit;
    }
    
    // ✅ Allow only valid clinic-updated statuses
    $allowed_statuses = ['Ready for Pickup', 'Dropped Off'];
    if (!in_array($status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status update from clinic']);
        exit;
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Update return status in returns table (HINDI completed_at)
        $update = $pdo->prepare("
            UPDATE returns r
            SET r.status = ?, 
                r.updated_at = NOW()
            WHERE r.po_id = ?
        ");
        $update->execute([$status, $po_id]);
        
        // Update purchase_orders status
        $updatePo = $pdo->prepare("
            UPDATE purchase_orders 
            SET status = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        $updatePo->execute([$status, $po_id]);
        
        // Add to timeline
        $timeline = $pdo->prepare("
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            SELECT id, ?, 'Clinic has prepared the items for return', ?, NOW()
            FROM returns WHERE po_id = ?
        ");
        $timeline->execute([$status, $_SESSION['user_id'], $po_id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Return status updated successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$po_id = $_GET['id'] ?? 0;

// Get PO details with return info
$poQuery = $pdo->prepare("
    SELECT po.*, 
           s.supplier_name, s.contact_person, s.email, s.mobile, s.address,
           pr.pr_number, pr.department, pr.purpose,
           r.id as return_id,
           r.status as return_status,
           r.return_method,
           r.schedule_details,
           r.supplier_response as return_response,
           r.response_date as return_response_date
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN returns r ON po.id = r.po_id
    WHERE po.id = ?
");
$poQuery->execute([$po_id]);
$po = $poQuery->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

// Get PO items from pr_items
$itemsQuery = $pdo->prepare("
    SELECT pi.*, sp.photo_path 
    FROM pr_items pi
    LEFT JOIN supplier_products sp ON pi.supplier_product_id = sp.id
    WHERE pi.pr_id = ? AND pi.supplier_id = ?
");
$itemsQuery->execute([$po['pr_id'], $po['supplier_id']]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Decode shipping photos
$shipping_photos = !empty($po['shipping_photos']) ? json_decode($po['shipping_photos'], true) : [];

// Decode return schedule details
$schedule_details = !empty($po['schedule_details']) ? json_decode($po['schedule_details'], true) : [];

// Determine which status to display (return status has priority)
$display_status = !empty($po['return_status']) ? $po['return_status'] : $po['status'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Order Details - <?php echo $po['po_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
        .shipment-photo {
            height: 150px;
            width: 100%;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .shipment-photo:hover {
            transform: scale(1.02);
        }
        .return-info-card {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cc 100%);
            border-left: 4px solid #ffc107;
        }
        .pickup-info-card {
            background: linear-gradient(135deg, #e6f3ff 0%, #cce5ff 100%);
            border-left: 4px solid #0d6efd;
        }
        .dropoff-info-card {
            background: linear-gradient(135deg, #e6ffe6 0%, #ccffcc 100%);
            border-left: 4px solid #28a745;
        }
        .info-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-dark mb-4" style="background-color: #008080;">
        <div class="container">
            <span class="navbar-brand mb-0 h1">
                <i class="bi bi-box-seam me-2"></i>Order Details
            </span>
            <a href="../main.php?view=purchase_orders" class="btn btn-light">
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
                        <?php
                        $status_badge = match($display_status) {
                            'Pending Supplier Approval' => 'warning',
                            'Supplier Approved' => 'info',
                            'Shipped' => 'primary',
                            'Delivered' => 'success',
                            'Cancelled' => 'danger',
                            'Return/Refund' => 'warning',
                            'For Pickup' => 'warning',
                            'For Drop-off' => 'success',
                            'Return Completed' => 'success',
                            default => 'secondary'
                        };
                        ?>
                        <span class="badge bg-<?php echo $status_badge; ?> bg-opacity-10 text-<?php echo $status_badge; ?> px-4 py-2 fs-6">
                            <?php echo $display_status; ?>
                        </span>
                        <h3 class="mt-3" style="color: #008080;">₱<?php echo number_format($po['total_amount'], 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- RETURN INFO CARD - For Pickup or For Drop-off -->
        <?php if (in_array($display_status, ['For Pickup', 'For Drop-off']) && !empty($schedule_details)): ?>
        <div class="card border-0 shadow-sm mb-4 <?php echo $display_status == 'For Pickup' ? 'pickup-info-card' : 'dropoff-info-card'; ?>">
            <div class="card-body">
                <div class="d-flex align-items-start">
                    <div class="info-icon me-3 <?php echo $display_status == 'For Pickup' ? 'bg-primary bg-opacity-10' : 'bg-success bg-opacity-10'; ?>">
                        <i class="bi bi-<?php echo $display_status == 'For Pickup' ? 'truck' : 'building'; ?> fs-4 text-<?php echo $display_status == 'For Pickup' ? 'primary' : 'success'; ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="mb-3">
                            <?php if ($display_status == 'For Pickup'): ?>
                                <i class="bi bi-truck me-2"></i>Ready for Pickup
                            <?php else: ?>
                                <i class="bi bi-building me-2"></i>Ready for Drop-off
                            <?php endif; ?>
                        </h5>
                        
                        <?php if ($display_status == 'For Pickup'): ?>
                            <!-- Pickup Details -->
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-calendar-event text-primary me-2"></i>
                                        <strong>Pickup Date:</strong>
                                        <span class="ms-2"><?php echo !empty($schedule_details['pickup_date']) ? date('F d, Y', strtotime($schedule_details['pickup_date'])) : 'To be scheduled'; ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-clock text-primary me-2"></i>
                                        <strong>Time Window:</strong>
                                        <span class="ms-2"><?php echo !empty($schedule_details['pickup_time']) ? $schedule_details['pickup_time'] : 'Business hours'; ?></span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-start mb-3">
                                        <i class="bi bi-geo-alt text-primary me-2 mt-1"></i>
                                        <div>
                                            <strong>Pickup Location:</strong>
                                            <p class="mb-0 text-secondary mt-1"><?php echo !empty($schedule_details['pickup_address']) ? nl2br(htmlspecialchars($schedule_details['pickup_address'])) : 'Address will be provided'; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Instructions:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>📦 Prepare the items for pickup</li>
                                    <li>👤 Ensure someone is available during the scheduled time</li>
                                    <li>📋 Have the return reference number ready</li>
                                    <li>✅ Click "Ready for Pickup" when items are prepared</li>
                                </ul>
                            </div>
                            
                        <?php else: ?>
                            <!-- Drop-off Details -->
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="d-flex align-items-start mb-3">
                                        <i class="bi bi-geo-alt text-success me-2 mt-1"></i>
                                        <div>
                                            <strong>Drop-off Location:</strong>
                                            <p class="mb-0 text-secondary mt-1"><?php echo !empty($schedule_details['dropoff_address']) ? nl2br(htmlspecialchars($schedule_details['dropoff_address'])) : 'Address will be provided'; ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($schedule_details['dropoff_contact']) || !empty($schedule_details['dropoff_phone'])): ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-person text-success me-2"></i>
                                        <strong>Contact Person:</strong>
                                        <span class="ms-2"><?php echo htmlspecialchars($schedule_details['dropoff_contact'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-telephone text-success me-2"></i>
                                        <strong>Contact Number:</strong>
                                        <span class="ms-2"><?php echo htmlspecialchars($schedule_details['dropoff_phone'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($schedule_details['dropoff_hours'])): ?>
                                <div class="col-12">
                                    <div class="d-flex align-items-start mb-2">
                                        <i class="bi bi-clock text-success me-2 mt-1"></i>
                                        <div>
                                            <strong>Operating Hours:</strong>
                                            <p class="mb-0 text-secondary mt-1"><?php echo nl2br(htmlspecialchars($schedule_details['dropoff_hours'])); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="alert alert-success mt-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Instructions:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>📦 Bring the items to the drop-off location</li>
                                    <li>📋 Present the return reference number</li>
                                    <li>🆔 Bring a valid ID for verification</li>
                                    <li>✅ Click "Mark as Dropped Off" when completed</li>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($schedule_details['notes'])): ?>
                        <div class="mt-3 p-2 bg-light rounded">
                            <i class="bi bi-chat-dots me-1"></i>
                            <strong>Additional Notes:</strong>
                            <p class="mb-0 mt-1 text-secondary"><?php echo nl2br(htmlspecialchars($schedule_details['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Action Buttons -->
                        <div class="mt-4">
                            <button class="btn btn-<?php echo $display_status == 'For Pickup' ? 'warning' : 'success'; ?> btn-lg" 
                                    onclick="confirmReturnReady(<?php echo $po_id; ?>, '<?php echo $display_status; ?>')">
                                <i class="bi bi-check-lg me-2"></i>
                                <?php echo $display_status == 'For Pickup' ? '✔ Ready for Pickup' : '✔ Mark as Dropped Off'; ?>
                            </button>
                            <button class="btn btn-outline-secondary btn-lg ms-2" onclick="contactSupplier()">
                                <i class="bi bi-chat-dots me-2"></i>Contact Supplier
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- RETURN COMPLETED CARD -->
        <?php if ($display_status == 'Return Completed'): ?>
        <div class="card border-0 shadow-sm mb-4 bg-success bg-opacity-10">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="info-icon bg-success bg-opacity-20 me-3">
                        <i class="bi bi-check-circle-fill text-success fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-1 text-success">Return Process Completed</h5>
                        <p class="mb-0 text-secondary">This return has been successfully completed. Thank you for your cooperation.</p>
                        <?php if (!empty($po['return_response_date'])): ?>
                        <small class="text-muted">Completed on: <?php echo date('F d, Y g:i A', strtotime($po['return_response_date'])); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column - Tracking and Supplier -->
            <div class="col-md-4">
                <!-- Order Tracking -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-truck me-2" style="color: #008080;"></i>
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
                                    <div class="bg-<?php echo $po['status'] != 'Pending Supplier Approval' ? 'success' : 'light'; ?> bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-<?php echo $po['status'] != 'Pending Supplier Approval' ? 'check-circle-fill text-success' : 'circle text-secondary'; ?>"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Supplier Approved</p>
                                    <small class="text-secondary">
                                        <?php echo $po['status'] != 'Pending Supplier Approval' ? date('M d, Y', strtotime($po['updated_at'])) : 'Pending'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tracking-step mb-3 ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-<?php echo $po['status'] == 'Shipped' || $po['status'] == 'Delivered' ? 'success' : 'light'; ?> bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-<?php echo $po['status'] == 'Shipped' || $po['status'] == 'Delivered' ? 'check-circle-fill text-success' : 'circle text-secondary'; ?>"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Shipped</p>
                                    <small class="text-secondary">
                                        <?php echo $po['status'] == 'Shipped' || $po['status'] == 'Delivered' ? date('M d, Y', strtotime($po['shipped_date'] ?? 'now')) : 'Pending'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tracking-step ps-4">
                            <div class="d-flex">
                                <div class="me-3">
                                    <div class="bg-<?php echo $po['status'] == 'Delivered' ? 'success' : 'light'; ?> bg-opacity-10 rounded-circle p-2">
                                        <i class="bi bi-<?php echo $po['status'] == 'Delivered' ? 'check-circle-fill text-success' : 'circle text-secondary'; ?>"></i>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-0 fw-bold">Delivered</p>
                                    <small class="text-secondary">
                                        <?php echo $po['status'] == 'Delivered' ? date('M d, Y', strtotime($po['actual_delivery'] ?? 'now')) : 'Pending'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($po['tracking_number']): ?>
                        <hr>
                        <div class="mt-3">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td width="120"><strong>Tracking #:</strong></td>
                                    <td><?php echo $po['tracking_number']; ?></td>
                                </tr>
                                <?php if (isset($po['shipping_fee']) && $po['shipping_fee'] > 0): ?>
                                <tr>
                                    <td><strong>Shipping Fee:</strong></td>
                                    <td class="text-success fw-bold">₱<?php echo number_format($po['shipping_fee'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($po['shipping_remarks'])): ?>
                                <tr>
                                    <td><strong>Remarks:</strong></td>
                                    <td><small><?php echo htmlspecialchars($po['shipping_remarks']); ?></small></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($shipping_photos)): ?>
                                <tr>
                                    <td><strong>Photos:</strong></td>
                                    <td>
                                        <i class="bi bi-camera-fill text-primary me-1"></i>
                                        <span><?php echo count($shipping_photos); ?> photo(s) attached</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                            <small class="text-secondary d-block mt-1">* Shipping fee charged to clinic</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Supplier Info -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-shop me-2" style="color: #008080;"></i>
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
                    </div>
                </div>
            </div>

            <!-- Right Column - Items and Details -->
            <div class="col-md-8">
                <!-- Items -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-box-seam me-2" style="color: #008080;"></i>
                            Items Ordered
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Product</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end pe-4">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <?php if ($item['photo_path']): ?>
                                                    <img src="../<?php echo $item['photo_path']; ?>" 
                                                         class="product-image me-3 border"
                                                         onerror="this.src='../assets/img/no-image.png'">
                                                <?php else: ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center me-3 border" 
                                                         style="width: 60px; height: 60px; border-radius: 8px;">
                                                        <i class="bi bi-image text-secondary fs-4"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <span class="fw-medium"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                                        <td class="text-end">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                        <td class="text-end pe-4 fw-bold" style="color: #008080;">
                                            ₱<?php echo number_format($item['total_price'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="3" class="text-end fw-bold ps-4">Total Amount:</td>
                                        <td class="text-end pe-4 fw-bold" style="color: #008080;">
                                            ₱<?php echo number_format($po['total_amount'], 2); ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Shipment Photos -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-camera me-2" style="color: #008080;"></i>
                            Shipment Photos
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($shipping_photos) && is_array($shipping_photos)): ?>
                            <div class="row g-3">
                                <?php foreach ($shipping_photos as $index => $photo): 
                                    $photo_path = '../' . $photo;
                                ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="position-relative">
                                        <a href="<?php echo $photo_path; ?>" data-lightbox="shipment-photos" data-title="Shipment Photo <?php echo $index + 1; ?>">
                                            <img src="<?php echo $photo_path; ?>" 
                                                 class="shipment-photo rounded border"
                                                 onerror="this.src='../assets/img/no-image.png'">
                                        </a>
                                        <span class="position-absolute top-0 end-0 bg-dark bg-opacity-75 text-white rounded px-2 py-1 m-2 small">
                                            <?php echo $index + 1; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3 text-muted small">
                                <i class="bi bi-info-circle"></i> Click on any photo to view full size
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-secondary">
                                <i class="bi bi-image fs-1"></i>
                                <p class="mb-0 mt-2">No shipment photos available yet.</p>
                                <small>Photos will appear here once supplier ships the order.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle me-2" style="color: #008080;"></i>
                            Order Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>PR Number:</strong></p>
                                <p class="text-secondary"><?php echo $po['pr_number']; ?></p>
                                
                                <p class="mb-1"><strong>Department:</strong></p>
                                <p class="text-secondary"><?php echo $po['department']; ?></p>
                                
                                <p class="mb-1"><strong>Purpose:</strong></p>
                                <p class="text-secondary"><?php echo $po['purpose']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Shipping Address:</strong></p>
                                <p class="text-secondary"><?php echo nl2br(htmlspecialchars($po['shipping_address'] ?? 'N/A')); ?></p>
                                
                                <p class="mb-1"><strong>Terms & Conditions:</strong></p>
                                <p class="text-secondary"><?php echo nl2br(htmlspecialchars($po['terms'] ?? 'None')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
    <script>
        lightbox.option({
            'resizeDuration': 200,
            'wrapAround': true,
            'albumLabel': 'Photo %1 of %2'
        });
        
function confirmReturnReady(poId, status) {
    let title = status === 'For Pickup' ? 'Ready for Pickup?' : 'Mark as Dropped Off?';
    let text = status === 'For Pickup' 
        ? 'Confirm that you have prepared all items for pickup. The supplier will be notified.' 
        : 'Confirm that you have dropped off all items at the designated location.';
    let confirmText = status === 'For Pickup' ? 'Yes, Ready for Pickup' : 'Yes, Dropped Off';
    let newStatus = status === 'For Pickup' ? 'Ready for Pickup' : 'Dropped Off';  // ✅ TAMA NA STATUS
    
    Swal.fire({
        title: title,
        text: text,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: confirmText,
        confirmButtonColor: status === 'For Pickup' ? '#ffc107' : '#28a745',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // ✅ TAMA: I-set sa 'Ready for Pickup' or 'Dropped Off', HINDI 'Return Completed'
            fetch(window.location.href + '&action=update_return', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'po_id=' + poId + '&status=' + newStatus  // ✅ ITO ANG TAMA
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: status === 'For Pickup' 
                            ? 'Items marked as ready for pickup. The supplier will be notified.' 
                            : 'Items marked as dropped off. The supplier will process the return.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.error || 'Something went wrong', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            });
        }
    });
}
        function contactSupplier() {
            Swal.fire({
                title: 'Contact Supplier',
                text: 'How would you like to contact the supplier?',
                icon: 'info',
                showCancelButton: true,
                showDenyButton: true,
                confirmButtonText: '<i class="bi bi-telephone"></i> Call',
                denyButtonText: '<i class="bi bi-envelope"></i> Email',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'tel:<?php echo htmlspecialchars($po['mobile'] ?? ''); ?>';
                } else if (result.isDenied) {
                    window.location.href = 'mailto:<?php echo htmlspecialchars($po['email'] ?? ''); ?>';
                }
            });
        }
    </script>
</body>
</html>