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

// ============================================
// HANDLE RETURN STATUS UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_return') {
    header('Content-Type: application/json');
    
    $po_id = $_POST['po_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    if (!$po_id || !$status) {
        echo json_encode(['success' => false, 'error' => 'PO ID and status required']);
        exit;
    }
    
    $allowed_statuses = ['Ready for Pickup', 'Dropped Off'];
    if (!in_array($status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status update']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $update = $pdo->prepare("UPDATE returns SET status = ?, updated_at = NOW() WHERE po_id = ?");
        $update->execute([$status, $po_id]);
        
        $updatePo = $pdo->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $updatePo->execute([$status, $po_id]);
        
        $timeline = $pdo->prepare("
            INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
            SELECT id, ?, 'Clinic has prepared the items for return', ?, NOW()
            FROM returns WHERE po_id = ?
        ");
        $timeline->execute([$status, $_SESSION['user_id'], $po_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Return status updated']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$po_id = $_GET['id'] ?? 0;

$poQuery = $pdo->prepare("
    SELECT po.*, 
           s.supplier_name, s.contact_person, s.email, s.mobile, s.address,
           pr.pr_number, pr.department, pr.purpose,
           r.id as return_id,
           r.status as return_status,
           r.schedule_details,
           rider.full_name as rider_name,
           rider.phone as rider_phone,
           rider.photo_path as rider_photo,
           rider.vehicle_type as rider_vehicle_type,
           rider.plate_number as rider_plate_number
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requests pr ON po.pr_id = pr.id
    LEFT JOIN returns r ON po.id = r.po_id
    LEFT JOIN supplier_riders rider ON po.assigned_rider_id = rider.id
    WHERE po.id = ?
");
$poQuery->execute([$po_id]);
$po = $poQuery->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    header('Location: purchase_orders.php');
    exit;
}

$itemsQuery = $pdo->prepare("
    SELECT pi.*, sp.photo_path 
    FROM pr_items pi
    LEFT JOIN supplier_products sp ON pi.supplier_product_id = sp.id
    WHERE pi.pr_id = ? AND pi.supplier_id = ?
");
$itemsQuery->execute([$po['pr_id'], $po['supplier_id']]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

$invQuery = $pdo->prepare("SELECT id, name, stock FROM inventory WHERE clinic_id = ? ORDER BY name");
$invQuery->execute([$_SESSION['clinic_id']]);
$inventory_items = $invQuery->fetchAll(PDO::FETCH_ASSOC);

$shipping_photos = !empty($po['shipping_photos']) ? json_decode($po['shipping_photos'], true) : [];
$schedule_details = !empty($po['schedule_details']) ? json_decode($po['schedule_details'], true) : [];

$display_status = !empty($po['return_status']) ? $po['return_status'] : $po['status'];

// ✅ FIX: Compute subtotal and VAT
$vat_rate = (float)($po['vat_rate'] ?? 0.12);
$subtotal = (float)($po['subtotal'] ?? ($po['total_amount'] / (1 + $vat_rate)));
$vat_amount = (float)($po['vat_amount'] ?? ($po['total_amount'] - $subtotal));

// Return window check
$can_request_return = false;
$return_window_days = 7;
$days_remaining = 0;

if ($po['status'] === 'Delivered' && empty($po['return_id']) && !empty($po['actual_delivery'])) {
    $delivery_date = new DateTime($po['actual_delivery']);
    $now = new DateTime();
    $days_since_delivery = $now->diff($delivery_date)->days;
    
    if ($days_since_delivery <= $return_window_days) {
        $can_request_return = true;
        $days_remaining = $return_window_days - $days_since_delivery;
    }
}

// ✅ NEW: Check kung naka-receive na
$is_received = !empty($po['received_at']);
$has_return = !empty($po['return_id']);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo $po['po_number']; ?> — Order Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { -webkit-font-smoothing: antialiased; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f5f5;
            color: #1e293b;
            font-size: 14px;
            padding-bottom: 100px;
            margin: 0;
        }

        /* ═══ TOP NAV ═══ */
        .top-nav {
            background: #fff;
            border-bottom: 1px solid #eef2f6;
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .top-nav-inner {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .top-nav-back {
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            transition: all .15s;
        }
        .top-nav-back:hover { background: #f1f5f9; color: #008080; }
        .top-nav-title { font-weight: 700; font-size: 15px; color: #0f172a; }

        /* ═══ MAIN ═══ */
        .main-wrap { max-width: 900px; margin: 0 auto; padding: 16px; }

        /* ═══ STATUS HERO ═══ */
        .status-hero {
            background: linear-gradient(135deg, #008080 0%, #006666 100%);
            border-radius: 16px;
            padding: 20px 24px;
            color: #fff;
            margin-bottom: 12px;
            position: relative;
            overflow: hidden;
        }
        .status-hero::before {
            content: '';
            position: absolute;
            top: -60%; right: -20%;
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 65%);
            border-radius: 50%;
        }
        .hero-status-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .hero-status-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -.02em;
            margin-bottom: 4px;
            position: relative;
            z-index: 1;
        }
        .hero-status-sub {
            font-size: 13px;
            opacity: .85;
            position: relative;
            z-index: 1;
        }
        .hero-po-badge {
            position: absolute;
            top: 20px; right: 24px;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            z-index: 1;
        }

        /* ═══ CARD ═══ */
        .card-x {
            background: #fff;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid #f1f5f9;
        }
        .card-x-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-x-title i { color: #008080; }

        /* ═══ TRACKING ═══ */
        .tracking-flow {
            display: flex;
            align-items: flex-start;
            overflow-x: auto;
            padding: 8px 0;
            scrollbar-width: none;
        }
        .tracking-flow::-webkit-scrollbar { display: none; }
        .track-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 90px;
            position: relative;
            flex: 1;
        }
        .track-step-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #94a3b8;
            position: relative;
            z-index: 1;
            transition: all .2s;
        }
        .track-step.done .track-step-dot {
            background: #10b981;
            border-color: #10b981;
            color: #fff;
        }
        .track-step.active .track-step-dot {
            background: #008080;
            border-color: #008080;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(0,128,128,.15);
        }
        .track-step::before {
            content: '';
            position: absolute;
            top: 16px;
            left: 50%;
            right: -50%;
            height: 2px;
            background: #e2e8f0;
            z-index: 0;
        }
        .track-step:last-child::before { display: none; }
        .track-step.done::before { background: #10b981; }
        .track-step-label {
            font-size: 10px;
            font-weight: 600;
            color: #94a3b8;
            text-align: center;
            margin-top: 8px;
            line-height: 1.2;
            padding: 0 4px;
        }
        .track-step.done .track-step-label,
        .track-step.active .track-step-label { color: #0f172a; }
        .track-step-time {
            font-size: 9px;
            color: #cbd5e1;
            margin-top: 2px;
            text-align: center;
        }

        /* ═══ SHOPEE ITEM ═══ */
        .shopee-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .shopee-item:last-child { border-bottom: none; }
        .shopee-item-img {
            width: 64px; height: 64px;
            border-radius: 10px;
            object-fit: cover;
            background: #f1f5f9;
            flex-shrink: 0;
            border: 1px solid #f1f5f9;
        }
        .shopee-item-info { flex: 1; min-width: 0; }
        .shopee-item-name {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 2px;
            line-height: 1.3;
        }
        .shopee-item-sub {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .shopee-item-price {
            font-size: 14px;
            font-weight: 700;
            color: #008080;
        }

        /* ═══ INFO LIST ═══ */
        .info-list { font-size: 13px; }
        .info-list-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .info-list-row:last-child { border-bottom: none; }
        .info-list-label { color: #94a3b8; }
        .info-list-value { color: #0f172a; font-weight: 600; text-align: right; max-width: 60%; }

        /* ═══ CONTACT ═══ */
        .contact-box {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 8px 0;
        }
        .contact-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: #f0fdfa;
            color: #008080;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
        }
        .contact-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .contact-avatar.warm { background: #fff7ed; color: #c2410c; }
        .contact-text { flex: 1; min-width: 0; font-size: 13px; }
        .contact-name { font-weight: 700; color: #0f172a; }
        .contact-sub { color: #64748b; font-size: 12px; }

        /* ═══ FORM ═══ */
        .input-soft {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            padding: 10px 12px;
            width: 100%;
            font-family: inherit;
        }
        .input-soft:focus {
            outline: none;
            border-color: #008080;
            box-shadow: 0 0 0 3px rgba(0,128,128,.08);
        }
        select.input-soft { background: #fff; }

        /* ═══ STICKY ═══ */
        .sticky-actions {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 1px solid #eef2f6;
            padding: 12px 16px;
            z-index: 99;
            box-shadow: 0 -4px 20px rgba(0,0,0,.04);
        }
        .sticky-actions-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-primary-x {
            flex: 1;
            background: #008080;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            transition: all .15s;
            font-family: inherit;
            cursor: pointer;
        }
        .btn-primary-x:hover { background: #006666; color: #fff; }
        .btn-outline-x {
            flex: 1;
            background: #fff;
            color: #dc2626;
            border: 1.5px solid #fecaca;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-outline-x:hover { background: #fef2f2; }

        /* ═══ PHOTOS ═══ */
        .photo-strip {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 8px;
        }
        .photo-strip img {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            border: 1px solid #f1f5f9;
        }
        .photo-single img {
            width: 100%;
            max-height: 260px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
        }

        /* ═══ ALERT ═══ */
        .alert-x {
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 13px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .alert-x.info { background: #eff6ff; color: #1e40af; }
        .alert-x.success { background: #f0fdf4; color: #065f46; }
        .alert-x i { font-size: 18px; flex-shrink: 0; }

        /* ═══ RATING ═══ */
        .rating-star {
            cursor: pointer;
            font-size: 28px;
            color: #e2e8f0;
            transition: all .15s;
            margin-right: 2px;
            display: inline-block;
        }
        .rating-star.active { color: #f59e0b; }
        .rating-star:hover { color: #fbbf24; transform: scale(1.1); }

        /* ═══ TOTAL ═══ */
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-top: 1px dashed #e2e8f0;
            margin-top: 8px;
        }
        .total-label { font-size: 13px; color: #64748b; }
        .total-value {
            font-size: 20px;
            font-weight: 800;
            color: #008080;
            letter-spacing: -.02em;
        }

        /* ═══ RECEIVE ITEM ═══ */
        .receive-item-row {
            padding: 12px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .receive-item-row:last-child { border-bottom: none; }

        @media (max-width: 576px) {
            .hero-status-title { font-size: 18px; }
            .track-step { min-width: 80px; }
            .track-step-label { font-size: 9px; }
            .contact-avatar { width: 38px; height: 38px; font-size: 14px; }
        }
    </style>
</head>
<body>

<!-- ═══ TOP NAV ═══ -->
<div class="top-nav">
    <div class="top-nav-inner">
        <a href="../main.php?view=purchase_orders" class="top-nav-back">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div class="top-nav-title">Order Details</div>
        <div style="width: 60px;"></div>
    </div>
</div>

<div class="main-wrap">

    <!-- ═══ STATUS HERO ═══ -->
    <div class="status-hero">
        <div class="hero-po-badge"><?php echo $po['po_number']; ?></div>
        <div class="hero-status-icon">
            <i class="bi bi-<?php 
                echo match($display_status) {
                    'Delivered' => 'check-circle-fill',
                    'Shipped' => 'truck',
                    'Pending Supplier Approval' => 'hourglass-split',
                    'Cancelled' => 'x-circle-fill',
                    'Return Completed' => 'arrow-return-left',
                    default => 'box-seam'
                };
            ?>"></i>
        </div>
        <div class="hero-status-title"><?php echo $display_status; ?></div>
        <div class="hero-status-sub">
            <i class="bi bi-calendar3 me-1"></i>
            Ordered <?php echo date('M d, Y', strtotime($po['order_date'])); ?>
            • Expected <?php echo date('M d, Y', strtotime($po['expected_date'])); ?>
        </div>
    </div>

    <!-- ═══ TRACKING ═══ -->
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-geo-alt-fill me-1"></i> Order Tracking</span>
        </div>
        <div class="tracking-flow">
            <div class="track-step done">
                <div class="track-step-dot"><i class="bi bi-check-lg"></i></div>
                <div class="track-step-label">Placed</div>
                <div class="track-step-time"><?php echo date('M d', strtotime($po['created_at'])); ?></div>
            </div>
            <div class="track-step <?php echo $po['status'] != 'Pending Supplier Approval' ? 'done' : 'active'; ?>">
                <div class="track-step-dot">
                    <?php echo $po['status'] != 'Pending Supplier Approval' ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:6px;"></i>'; ?>
                </div>
                <div class="track-step-label">Approved</div>
                <?php if (!empty($po['response_date'])): ?>
                    <div class="track-step-time"><?php echo date('M d', strtotime($po['response_date'])); ?></div>
                <?php endif; ?>
            </div>
            <div class="track-step <?php echo !empty($po['shipped_date']) ? 'done' : ''; ?>">
                <div class="track-step-dot">
                    <?php echo !empty($po['shipped_date']) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:6px;"></i>'; ?>
                </div>
                <div class="track-step-label">Shipped</div>
                <?php if (!empty($po['shipped_date'])): ?>
                    <div class="track-step-time"><?php echo date('M d', strtotime($po['shipped_date'])); ?></div>
                <?php endif; ?>
            </div>
            <div class="track-step <?php echo !empty($po['out_for_delivery_at']) ? 'done' : ''; ?>">
                <div class="track-step-dot">
                    <?php echo !empty($po['out_for_delivery_at']) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:6px;"></i>'; ?>
                </div>
                <div class="track-step-label">In Transit</div>
                <?php if (!empty($po['out_for_delivery_at'])): ?>
                    <div class="track-step-time"><?php echo date('M d', strtotime($po['out_for_delivery_at'])); ?></div>
                <?php endif; ?>
            </div>
            <div class="track-step <?php echo !empty($po['actual_delivery']) ? 'done' : ''; ?>">
                <div class="track-step-dot">
                    <?php echo !empty($po['actual_delivery']) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:6px;"></i>'; ?>
                </div>
                <div class="track-step-label">Delivered</div>
                <?php if (!empty($po['actual_delivery'])): ?>
                    <div class="track-step-time"><?php echo date('M d', strtotime($po['actual_delivery'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══ RECEIPT CONFIRMED BADGE ═══ -->
    <?php if ($is_received && !$has_return): ?>
    <div class="alert-x success">
        <i class="bi bi-check-circle-fill"></i>
        <div>
            <strong>Receipt Confirmed</strong>
            Received on <?php echo date('F d, Y g:i A', strtotime($po['received_at'])); ?>
            <?php if ($can_request_return): ?>
                <br><small>You can still request a return within <?php echo $days_remaining; ?> day(s).</small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ RECEIVE FORM (ONLY IF NOT YET RECEIVED) ═══ -->
    <?php if ($po['status'] === 'Delivered' && !$is_received && !$has_return): ?>
    <div class="card-x" style="border: 1.5px solid #d1fae5;">
        <div class="card-x-title">
            <span><i class="bi bi-box-seam-fill me-1" style="color: #10b981;"></i> Confirm Receipt</span>
        </div>

        <div class="alert-x success">
            <i class="bi bi-info-circle-fill"></i>
            <div>Order has been delivered. Confirm receipt, link items to inventory, and rate the supplier.</div>
        </div>

        <?php foreach ($items as $index => $item): ?>
        <div class="receive-item-row">
            <div class="shopee-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
            <div class="shopee-item-sub">
                Qty: <strong><?php echo $item['quantity']; ?></strong>
                • ₱<?php echo number_format($item['unit_price'], 2); ?> each
            </div>
            <select class="input-soft inventory-select mt-2" 
                    id="inventory_select_<?php echo $index; ?>" 
                    data-index="<?php echo $index; ?>" style="max-width: 280px;">
                <option value="">— Link to Inventory —</option>
                <option value="new">➕ Create New Item</option>
                <?php foreach ($inventory_items as $inv): ?>
                    <option value="<?php echo $inv['id']; ?>">
                        <?php echo htmlspecialchars($inv['name']); ?> (<?php echo $inv['stock']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" class="input-soft mt-2" 
                   id="new_inventory_name_<?php echo $index; ?>" 
                   placeholder="New item name" 
                   value="<?php echo htmlspecialchars($item['item_name']); ?>"
                   style="display: none; max-width: 280px;">
        </div>
        <?php endforeach; ?>

        <div style="margin-top: 16px;">
            <label style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .05em; display: block; margin-bottom: 6px;">
                Delivery Notes
            </label>
            <textarea class="input-soft" id="delivery_notes" rows="2" 
                      placeholder="Any issues with delivery? (optional)"></textarea>
        </div>

        <div style="margin-top: 16px;">
            <label style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .05em; display: block; margin-bottom: 6px;">
                Rate Supplier
            </label>
            <div id="ratingStars">
                <i class="bi bi-star rating-star" data-rating="1"></i>
                <i class="bi bi-star rating-star" data-rating="2"></i>
                <i class="bi bi-star rating-star" data-rating="3"></i>
                <i class="bi bi-star rating-star" data-rating="4"></i>
                <i class="bi bi-star rating-star" data-rating="5"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ RETURN INFO ═══ -->
    <?php if (in_array($display_status, ['For Pickup', 'For Drop-off']) && !empty($schedule_details)): ?>
    <div class="card-x" style="border: 1.5px solid #fcd34d;">
        <div class="card-x-title">
            <span><i class="bi bi-truck me-1" style="color:#f59e0b;"></i> <?php echo $display_status; ?></span>
        </div>
        <div class="info-list">
            <div class="info-list-row">
                <span class="info-list-label">Date</span>
                <span class="info-list-value"><?php echo !empty($schedule_details['pickup_date']) ? date('M d, Y', strtotime($schedule_details['pickup_date'])) : 'TBD'; ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Address</span>
                <span class="info-list-value"><?php echo htmlspecialchars($schedule_details['pickup_address'] ?? $schedule_details['dropoff_address'] ?? 'N/A'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($display_status == 'Return Completed'): ?>
    <div class="alert-x success">
        <i class="bi bi-check-circle-fill"></i>
        <div><strong>Return Completed</strong> This return has been processed successfully.</div>
    </div>
    <?php endif; ?>

    <!-- ═══ ITEMS ORDERED ═══ -->
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-box-seam me-1"></i> Items Ordered (<?php echo count($items); ?>)</span>
        </div>

        <?php foreach ($items as $item): ?>
        <div class="shopee-item">
            <?php if ($item['photo_path']): ?>
                <img src="../<?php echo $item['photo_path']; ?>" class="shopee-item-img"
                     onerror="this.src='../assets/img/no-image.png'">
            <?php else: ?>
                <div class="shopee-item-img d-flex align-items-center justify-content-center">
                    <i class="bi bi-image text-secondary"></i>
                </div>
            <?php endif; ?>
            <div class="shopee-item-info">
                <div class="shopee-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                <div class="shopee-item-sub">
                    <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['unit_price'], 2); ?>
                </div>
                <div class="shopee-item-price">₱<?php echo number_format($item['total_price'], 2); ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top: 12px;">
            <div class="info-list-row">
                <span class="info-list-label">Subtotal</span>
                <span class="info-list-value">₱<?php echo number_format($subtotal, 2); ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">VAT (<?php echo number_format($vat_rate * 100, 0); ?>%)</span>
                <span class="info-list-value">₱<?php echo number_format($vat_amount, 2); ?></span>
            </div>
            <div class="total-row">
                <span class="total-label">Grand Total</span>
                <span class="total-value">₱<?php echo number_format($po['total_amount'], 2); ?></span>
            </div>
        </div>
    </div>

    <!-- ═══ SELLER ═══ -->
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-shop me-1"></i> Seller</span>
        </div>
        <div class="contact-box">
            <div class="contact-avatar"><?php echo strtoupper(substr($po['supplier_name'], 0, 1)); ?></div>
            <div class="contact-text">
                <div class="contact-name"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                <div class="contact-sub"><?php echo htmlspecialchars($po['contact_person'] ?? 'N/A'); ?></div>
                <div class="contact-sub"><?php echo htmlspecialchars($po['mobile'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </div>

    <!-- ═══ RIDER ═══ -->
    <?php if (!empty($po['rider_name'])): ?>
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-person-badge me-1"></i> Rider</span>
        </div>
        <div class="contact-box">
            <div class="contact-avatar warm">
                <?php if (!empty($po['rider_photo'])): ?>
                    <img src="../<?php echo $po['rider_photo']; ?>">
                <?php else: ?>
                    <?php echo strtoupper(substr($po['rider_name'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="contact-text">
                <div class="contact-name"><?php echo htmlspecialchars($po['rider_name']); ?></div>
                <div class="contact-sub"><?php echo htmlspecialchars($po['rider_phone'] ?? 'N/A'); ?></div>
                <?php if (!empty($po['rider_vehicle_type'])): ?>
                    <div class="contact-sub"><?php echo $po['rider_vehicle_type']; ?> • <?php echo $po['rider_plate_number'] ?? ''; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ SHIPPING INFO ═══ -->
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-geo-alt me-1"></i> Shipping Information</span>
        </div>
        <div class="info-list">
            <div class="info-list-row">
                <span class="info-list-label">Ship To</span>
                <span class="info-list-value"><?php echo htmlspecialchars($po['shipping_address'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Contact</span>
                <span class="info-list-value"><?php echo htmlspecialchars($po['contact_person'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Phone</span>
                <span class="info-list-value"><?php echo htmlspecialchars($po['contact_phone'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Payment</span>
                <span class="info-list-value"><?php echo $po['payment_method'] ?? 'Bank Transfer'; ?></span>
            </div>
        </div>
    </div>

    <!-- ═══ PACKAGE PHOTOS ═══ -->
    <?php if (!empty($shipping_photos)): ?>
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-camera me-1"></i> Package Photos</span>
        </div>
        <div class="photo-strip">
            <?php foreach ($shipping_photos as $photo): ?>
            <a href="../<?php echo $photo; ?>" data-lightbox="package">
                <img src="../<?php echo $photo; ?>">
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ DELIVERY PROOF ═══ -->
    <?php if (!empty($po['delivery_proof_photo'])): ?>
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-patch-check-fill me-1" style="color:#10b981;"></i> Delivery Proof</span>
        </div>
        <div class="photo-single">
            <a href="../<?php echo $po['delivery_proof_photo']; ?>" data-lightbox="proof">
                <img src="../<?php echo $po['delivery_proof_photo']; ?>">
            </a>
        </div>
        <?php if (!empty($po['delivery_receiver_name'])): ?>
            <div class="contact-sub mt-2">
                <i class="bi bi-check-circle-fill text-success me-1"></i>
                Received by <strong><?php echo htmlspecialchars($po['delivery_receiver_name']); ?></strong>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ═══ ORDER INFO ═══ -->
    <div class="card-x">
        <div class="card-x-title">
            <span><i class="bi bi-info-circle me-1"></i> Order Information</span>
        </div>
        <div class="info-list">
            <div class="info-list-row">
                <span class="info-list-label">PR Number</span>
                <span class="info-list-value"><?php echo $po['pr_number']; ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Department</span>
                <span class="info-list-value"><?php echo $po['department']; ?></span>
            </div>
            <div class="info-list-row">
                <span class="info-list-label">Purpose</span>
                <span class="info-list-value"><?php echo $po['purpose']; ?></span>
            </div>
        </div>
    </div>

</div>

<!-- ═══ STICKY BOTTOM BAR ═══ -->
<?php if ($po['status'] === 'Delivered' && !$is_received && !$has_return): ?>
<div class="sticky-actions">
    <div class="sticky-actions-inner">
        <?php if ($can_request_return): ?>
        <button type="button" class="btn-outline-x" onclick="requestReturn(<?php echo $po_id; ?>)">
            <i class="bi bi-arrow-return-left me-1"></i> Return
        </button>
        <?php endif; ?>
        <button type="button" class="btn-primary-x" id="confirmReceiptBtn">
            <i class="bi bi-check-lg me-1"></i> Confirm Receipt
        </button>
    </div>
</div>
<?php elseif ($is_received && !$has_return && $can_request_return): ?>
<!-- Naka-receive na pero pwede pa mag-return -->
<div class="sticky-actions">
    <div class="sticky-actions-inner">
        <button type="button" class="btn-outline-x" style="flex: 1;" onclick="requestReturn(<?php echo $po_id; ?>)">
            <i class="bi bi-arrow-return-left me-1"></i> Request Return (<?php echo $days_remaining; ?> days left)
        </button>
    </div>
</div>
<?php elseif (in_array($display_status, ['For Pickup', 'For Drop-off']) && !empty($schedule_details)): ?>
<div class="sticky-actions">
    <div class="sticky-actions-inner">
        <button type="button" class="btn-primary-x" 
                onclick="confirmReturnReady(<?php echo $po_id; ?>, '<?php echo $display_status; ?>')">
            <i class="bi bi-check-lg me-1"></i>
            <?php echo $display_status == 'For Pickup' ? 'Ready for Pickup' : 'Mark as Dropped Off'; ?>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ═══ SCRIPTS ═══ -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>

<script>
// ═══════════════════════════════════════════════════
// GLOBAL STATE
// ═══════════════════════════════════════════════════
var currentRating = 0;
var currentOrderItems = <?php echo json_encode($items); ?>;

// ═══════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {

    // Lightbox safe init
    if (typeof lightbox !== 'undefined' && lightbox && typeof lightbox.option === 'function') {
        lightbox.option({
            resizeDuration: 200,
            wrapAround: true,
            albumLabel: 'Photo %1 of %2'
        });
    }

    // Rating stars
    document.querySelectorAll('.rating-star').forEach(function (star) {
        star.addEventListener('click', function () {
            currentRating = parseInt(this.dataset.rating, 10);
            document.querySelectorAll('.rating-star').forEach(function (s, i) {
                s.className = (i < currentRating)
                    ? 'bi bi-star-fill rating-star active'
                    : 'bi bi-star rating-star';
            });
        });
    });

    // Inventory select — show/hide new input
    document.querySelectorAll('.inventory-select').forEach(function (select) {
        select.addEventListener('change', function () {
            var index = this.dataset.index;
            var newInput = document.getElementById('new_inventory_name_' + index);
            if (newInput) {
                newInput.style.display = (this.value === 'new') ? 'block' : 'none';
            }
        });
    });

    // Confirm Receipt button
    var confirmBtn = document.getElementById('confirmReceiptBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', submitReceiveOrder);
    }
});

// ═══════════════════════════════════════════════════
// SUBMIT RECEIVE ORDER
// ═══════════════════════════════════════════════════
function submitReceiveOrder() {

    if (currentRating === 0) {
        Swal.fire('Rate Supplier', 'Please rate the supplier first.', 'warning');
        return;
    }

    var items = [];
    var hasError = false;
    var errorMessage = '';

    document.querySelectorAll('.inventory-select').forEach(function (select, index) {
        var itemName = (currentOrderItems[index] && currentOrderItems[index].item_name)
            ? currentOrderItems[index].item_name
            : 'Item ' + (index + 1);

        if (!select.value) {
            hasError = true;
            errorMessage = 'Select inventory for "' + itemName + '"';
            return;
        }

        var inventoryAction;
        if (select.value === 'new') {
            var newName = document.getElementById('new_inventory_name_' + index);
            inventoryAction = {
                type: 'create',
                name: (newName && newName.value) ? newName.value : itemName
            };
        } else {
            inventoryAction = {
                type: 'add',
                inventory_id: parseInt(select.value, 10)
            };
        }

        items.push({
            index: index,
            item_name: itemName,
            received: (currentOrderItems[index] && currentOrderItems[index].quantity) || 0,
            remark: '',
            inventory_action: inventoryAction
        });
    });

    if (hasError) {
        Swal.fire('Missing', errorMessage, 'warning');
        return;
    }

    Swal.fire({
        title: 'Confirm Receipt?',
        text: 'Add items to inventory and mark as received.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, Confirm'
    }).then(function (result) {
        if (!result.isConfirmed) return;

        var formData = new FormData();
        formData.append('action', 'receive_order');
        formData.append('po_id', <?php echo (int)$po_id; ?>);
        formData.append('pr_id', <?php echo (int)$po['pr_id']; ?>);
        formData.append('received_items', JSON.stringify(items));
        formData.append('delivery_notes', (document.getElementById('delivery_notes') || {}).value || '');
        formData.append('supplier_rating', currentRating);

        Swal.fire({
            title: 'Processing...',
            allowOutsideClick: false,
            didOpen: function () { Swal.showLoading(); }
        });

        fetch('../api/purchase_request.php', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                Swal.close();
                if (data.success) {
                    Swal.fire('Success!', 'Order received.', 'success')
                        .then(function () { location.reload(); });
                } else {
                    Swal.fire('Error', data.error || 'Failed', 'error');
                }
            })
            .catch(function (err) {
                Swal.close();
                console.error('Error:', err);
                Swal.fire('Error', 'Network error', 'error');
            });
    });
}

// ═══════════════════════════════════════════════════
// CONFIRM RETURN READY
// ═══════════════════════════════════════════════════
function confirmReturnReady(poId, status) {
    var newStatus = (status === 'For Pickup') ? 'Ready for Pickup' : 'Dropped Off';

    Swal.fire({
        title: (status === 'For Pickup') ? 'Ready for Pickup?' : 'Mark as Dropped Off?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes'
    }).then(function (result) {
        if (!result.isConfirmed) return;

        fetch(window.location.href + '&action=update_return', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'po_id=' + poId + '&status=' + encodeURIComponent(newStatus)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                Swal.fire('Error', data.error || 'Failed', 'error');
            }
        })
        .catch(function () {
            Swal.fire('Error', 'Network error', 'error');
        });
    });
}

// ═══════════════════════════════════════════════════
// REQUEST RETURN
// ═══════════════════════════════════════════════════
function requestReturn(poId) {
    Swal.fire({
        title: 'Request Return / Refund?',
        html: ''
            + '<p>Subject for supplier approval.</p>'
            + '<div class="alert alert-info small text-start mb-0">'
            + '  <i class="bi bi-clock-history me-1"></i>'
            + '  Return window: <?php echo (int)$return_window_days; ?> days '
            + '  (<?php echo (int)$days_remaining; ?> remaining)'
            + '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Request',
        confirmButtonColor: '#dc2626'
    }).then(function (result) {
        if (result.isConfirmed) {
            window.location.href = '../main.php?view=returns&po_id=' + poId;
        }
    });
}
</script>
</body>
</html>