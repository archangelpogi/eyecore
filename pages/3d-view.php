<?php
// ============================================
// 3D-VIEW.PHP — VIEW-ONLY PAGE
// Preview lang ng 3D model + product details
// Reservation flow nasa product-view.php
// ============================================

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$product_id) { header('Location: dashboard.php'); exit(); }

// GET PRODUCT + CLINIC DETAILS
$product_query = mysqli_query($conn, "
    SELECT p.*,
           p3d.model_file,
           p3d.has_3d,
           c.id as clinic_id,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.hours as clinic_hours,
           c.logo as clinic_logo
    FROM products p
    LEFT JOIN product_3d_models p3d ON p.inventory_id = p3d.inventory_id OR p.id = p3d.product_id
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.id = $product_id
");

if (mysqli_num_rows($product_query) == 0) { header('Location: dashboard.php'); exit(); }
$product = mysqli_fetch_assoc($product_query);
$clinic_id = $product['clinic_id'];
$category = $product['category'];
$IS_SERVICE = in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening']);

// SALE DETECTION
$is_on_sale = !empty($product['is_on_sale'])
    && $product['is_on_sale'] == 1
    && !empty($product['sale_price'])
    && $product['sale_price'] > 0
    && !empty($product['sale_end'])
    && strtotime($product['sale_end']) >= strtotime('today');

$sale_price     = $is_on_sale ? (float)$product['sale_price'] : 0;
$original_price = (float)$product['price'];
$display_price  = $is_on_sale ? $sale_price : $original_price;
$discount_pct   = $is_on_sale ? round((($original_price - $sale_price) / $original_price) * 100) : 0;
$savings        = $is_on_sale ? ($original_price - $sale_price) : 0;

// 3D MODEL CHECK
$has_3d = !empty($product['model_file']) && ($product['has_3d'] == 1 || $product['has_3d'] == '1');
$model_file = $product['model_file'] ?? '';

// GET COLORS
$colors_query = mysqli_query($conn, "
    SELECT color_code, color_name, quantity
    FROM product_color_inventory
    WHERE product_id = $product_id
    AND clinic_id = $clinic_id
    AND is_available = 1
    ORDER BY color_name
");
$product_colors = [];
$total_available_stock = 0;
while ($c = mysqli_fetch_assoc($colors_query)) {
    $product_colors[] = [
        'code'     => $c['color_code'],
        'name'     => $c['color_name'],
        'quantity' => (int)$c['quantity'],
    ];
    if ((int)$c['quantity'] > 0) {
        $total_available_stock += (int)$c['quantity'];
    }
}
$has_colors         = !empty($product_colors);
$is_fully_sold_out  = $has_colors && $total_available_stock === 0;

// EXISTING RESERVATION / APPOINTMENT
$existing_reservation = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, reservation_code FROM reservations
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending','confirmed') LIMIT 1"
)) ?? null;

$existing_appointment = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, ref_no FROM appointments
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending','confirmed') LIMIT 1"
)) ?? null;

// FAVORITED
$fav_check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
$is_product_favorited = mysqli_num_rows($fav_check) > 0;

// Navbar vars
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($appointments_count)['total'] ?? 0;
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$total_points = mysqli_fetch_assoc($points_query)['total_points'] ?: 0;
$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($bookings_query)['total'] ?: 0;
$reservation_query_nav = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending','confirmed')");
$reservation_count = mysqli_fetch_assoc($reservation_query_nav)['total'] ?? 0;
$active_nav = 'discover';

include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>3D View - <?php echo htmlspecialchars($product['name']); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; overflow-x: hidden; background: var(--bg-primary); min-height: 100vh; }
    body { font-family: 'DM Sans', -apple-system, sans-serif; transition: background 0.3s, color 0.3s; }

    :root {
        --primary: #00B761;
        --primary-dark: #008F4C;
        --primary-light: #E8FAF0;
        --primary-gradient: linear-gradient(135deg, #00B761, #00A86B);
        --bg-primary: #F4F6F9;
        --bg-secondary: #FFFFFF;
        --text-primary: #0D1117;
        --text-secondary: #5A6478;
        --text-muted: #9CA3AF;
        --border-color: #E5E7EB;
        --border-light: #F0F2F5;
        --shadow-sm: 0 1px 4px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
        --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
        --radius-sm: 10px;
        --radius-md: 16px;
        --radius-lg: 24px;
        --radius-full: 999px;
        --danger: #EF4444;
        --warning: #F59E0B;
        --success: #00B761;
        --info: #3B82F6;
        --font-main: 'DM Sans', sans-serif;
        --font-display: 'DM Serif Display', serif;
        --viewer-bg: #1A1A2E;
    }

    .theme-dark {
        --bg-primary: #0D0F14;
        --bg-secondary: #161B25;
        --text-primary: #F0F4FF;
        --text-secondary: #8892A4;
        --text-muted: #4B5563;
        --border-color: #252D3D;
        --border-light: #1C2235;
        --primary-light: #0A2018;
        --viewer-bg: #0A0A1A;
    }

    .main-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 28px 20px 80px;
    }
    @media (min-width: 1024px) { .main-content { padding: 32px 40px 60px; } }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* Breadcrumb */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 24px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    .breadcrumb a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: var(--bg-secondary);
        border-radius: var(--radius-full);
        border: 1px solid var(--border-light);
        transition: all 0.2s;
    }
    .breadcrumb a:hover { background: var(--primary); color: white; }
    .breadcrumb .sep { color: var(--border-color); }
    .breadcrumb .current { color: var(--text-secondary); font-size: 13px; }

    /* Main layout */
    .viewer-layout {
        display: grid;
        grid-template-columns: 1.6fr 1fr;
        gap: 28px;
        align-items: start;
    }
    @media (max-width: 1024px) { .viewer-layout { grid-template-columns: 1fr; } }

    /* ===== 3D VIEWER ===== */
    .viewer-wrapper {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        position: sticky;
        top: 80px;
    }
    @media (max-width: 1024px) { .viewer-wrapper { position: static; } }

    .viewer-canvas {
        position: relative;
        width: 100%;
        height: 480px;
        background: var(--viewer-bg);
        overflow: hidden;
    }
    @media (max-width: 768px) { .viewer-canvas { height: 320px; } }

    #viewer3D { width: 100%; height: 100%; }

    .no-model-msg {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: rgba(255,255,255,0.6);
        gap: 12px;
        text-align: center;
        padding: 20px;
    }
    .no-model-msg i { font-size: 64px; opacity: 0.2; }
    .no-model-msg h3 { color: white; font-size: 18px; }
    .no-model-msg p { font-size: 13px; }

    .viewer-controls {
        display: flex;
        justify-content: center;
        gap: 8px;
        padding: 16px;
        background: var(--bg-secondary);
        border-top: 1px solid var(--border-light);
        flex-wrap: wrap;
    }

    .ctrl-btn {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: 1.5px solid var(--border-color);
        background: var(--bg-primary);
        color: var(--text-secondary);
        font-size: 14px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .ctrl-btn:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.1); }
    .ctrl-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

    .color-strip {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-top: 1px solid var(--border-light);
        background: var(--bg-secondary);
        flex-wrap: wrap;
    }
    .color-strip-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); flex-shrink: 0; }
    .color-swatch {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 2.5px solid white;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
    }
    .color-swatch:hover { transform: scale(1.2); }
    .color-swatch.active { box-shadow: 0 0 0 3px var(--primary); transform: scale(1.1); }
    .color-swatch.reset-btn {
        background: linear-gradient(45deg, #ccc 25%, #eee 25%, #eee 50%, #ccc 50%, #ccc 75%, #eee 75%);
        background-size: 8px 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .color-swatch.reset-btn i { font-size: 11px; color: #666; }

    .viewer-hint {
        display: flex;
        gap: 16px;
        padding: 10px 16px;
        background: var(--bg-primary);
        border-top: 1px solid var(--border-light);
        flex-wrap: wrap;
    }
    .hint-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-muted); }
    .hint-item i { color: var(--primary); font-size: 12px; }

    /* ===== INFO PANEL ===== */
    .info-panel { display: flex; flex-direction: column; gap: 16px; }

    .info-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .info-card-head {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
    }
    .info-card-head i { color: var(--primary); }
    .info-card-body { padding: 20px; }

    .category-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        background: var(--primary-light);
        color: var(--primary);
        border-radius: var(--radius-full);
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 10px;
    }
    .product-title {
        font-family: var(--font-display);
        font-size: 24px;
        color: var(--text-primary);
        margin-bottom: 14px;
        line-height: 1.2;
    }

    .price-box {
        background: var(--primary-light);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-bottom: 14px;
    }
    .price-main { font-size: 28px; font-weight: 700; color: var(--primary); font-family: var(--font-display); }
    .price-main.sale { color: #EF4444; }
    .price-orig { font-size: 14px; color: var(--text-muted); text-decoration: line-through; margin-left: 8px; }

    .sale-banner-sm {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 7px 12px;
        background: linear-gradient(135deg, #EF4444, #FF6B6B);
        border-radius: var(--radius-md);
        margin-bottom: 10px;
        font-size: 12px;
        color: white;
        font-weight: 600;
    }

    .product-desc {
        font-size: 13px;
        color: var(--text-secondary);
        line-height: 1.7;
        margin-bottom: 16px;
    }

    /* COLOR / VARIANT SELECTOR */
    .color-selector-box { background: var(--bg-primary); border-radius: var(--radius-md); padding: 14px; margin-bottom: 14px; border: 1px solid var(--border-light); }
    .cs-title { font-size: 13px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 6px; margin-bottom: 12px; }
    .cs-title i { color: var(--primary); }
    .cs-selected-label { font-weight: 500; color: var(--text-secondary); font-size: 12px; margin-left: auto; }
    .cs-selected-label.chosen { color: var(--primary); font-weight: 600; }
    .color-btns { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 8px; }
    .color-btn { display: flex; align-items: center; gap: 6px; padding: 7px 12px; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); cursor: pointer; transition: all 0.2s; font-family: inherit; }
    .color-btn:hover:not(:disabled):not(.oos) { border-color: var(--primary); background: var(--primary-light); }
    .color-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.15); }
    .color-btn.oos { opacity: 0.45; cursor: not-allowed; border-style: dashed; }
    .color-dot { width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid rgba(0,0,0,0.15); flex-shrink: 0; }
    .color-btn-name { font-size: 12px; font-weight: 600; color: var(--text-primary); }
    .color-btn-qty { font-size: 10px; color: var(--text-muted); margin-left: 2px; }
    .color-btn-qty.low { color: var(--warning); font-weight: 600; }
    .color-btn-qty.oos-label { color: var(--danger); font-weight: 600; }
    .cs-hint { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
    .cs-hint i { color: var(--primary); }
    .sold-out-notice { display: flex; align-items: flex-start; gap: 10px; background: #FEE2E2; border: 1px solid #FECACA; border-radius: var(--radius-md); padding: 12px; }
    .theme-dark .sold-out-notice { background: #3B0F0F; border-color: #7F1D1D; }
    .sold-out-notice > i { color: var(--danger); font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .sold-out-notice strong { font-size: 13px; color: var(--danger); display: block; margin-bottom: 2px; }
    .sold-out-notice p { font-size: 11px; color: var(--text-secondary); }

    .existing-notice {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 12px;
    }
    .existing-notice.reservation { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .existing-notice.appointment { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
    .existing-notice a { color: inherit; font-weight: 700; }

    .btn-main-action {
        width: 100%;
        padding: 15px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 15px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        box-shadow: 0 4px 14px rgba(0,183,97,0.3);
        margin-bottom: 8px;
        text-decoration: none;
    }
    .btn-main-action:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,183,97,0.4); }
    .btn-main-action:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
    .btn-main-action.apt { background: linear-gradient(135deg, #3B82F6, #2563EB); box-shadow: 0 4px 14px rgba(59,130,246,0.3); }
    .btn-main-action.apt:hover:not(:disabled) { box-shadow: 0 8px 20px rgba(59,130,246,0.4); }

    .action-helper {
        font-size: 11px;
        color: var(--text-muted);
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    .fav-product-wrap { margin-bottom: 12px; }
    .btn-fav-product-full {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 13px 20px;
        border-radius: var(--radius-full, 999px);
        border: 2px solid #e5e7eb;
        background: transparent;
        color: var(--text-secondary, #6B7280);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        font-family: inherit;
    }
    .btn-fav-product-full i { font-size: 16px; color: #ccc; transition: all 0.2s; }
    .btn-fav-product-full:hover { border-color: #EF4444; color: #EF4444; }
    .btn-fav-product-full:hover i { color: #EF4444; }
    .btn-fav-product-full.active { border-color: #EF4444; color: #EF4444; background: #fff5f5; }
    .btn-fav-product-full.active i { color: #EF4444; }
    .btn-fav-product-full.pop { animation: favPop 0.3s ease; }
    @keyframes favPop { 0%{transform:scale(1);} 50%{transform:scale(1.04);} 100%{transform:scale(1);} }
    .theme-dark .btn-fav-product-full { border-color: #333; color: #888; }
    .theme-dark .btn-fav-product-full.active { background: #2a1a1a; border-color: #EF4444; color: #EF4444; }

    /* CLINIC CARD */
    .clinic-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 16px;
        border: 1px solid var(--border-light);
        display: flex;
        gap: 12px;
        align-items: flex-start;
        text-decoration: none;
        transition: all 0.2s;
        box-shadow: var(--shadow-sm);
    }
    .clinic-card:hover { border-color: var(--primary); }
    .clinic-logo-box { width: 44px; height: 44px; border-radius: 12px; background: var(--primary-light); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
    .clinic-logo-box img { width: 100%; height: 100%; object-fit: cover; }
    .clinic-logo-box i { font-size: 20px; color: var(--primary); }
    .clinic-info .cn { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; }
    .clinic-info .ca { font-size: 11px; color: var(--text-secondary); display: flex; align-items: flex-start; gap: 4px; }
    .clinic-info .ca i { color: var(--primary); margin-top: 1px; flex-shrink: 0; }
    .clinic-info .ch { font-size: 11px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
    .clinic-info .ch i { color: var(--primary); }

    /* Toast */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9990; }
    .toast { display: flex; align-items: center; gap: 10px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 12px 18px; box-shadow: var(--shadow-lg); margin-bottom: 10px; min-width: 280px; animation: toastIn 0.3s ease; border-left: 4px solid var(--success); }
    .toast.error { border-left-color: var(--danger); }
    .toast.info { border-left-color: var(--info); }
    .toast i { font-size: 16px; flex-shrink: 0; }
    .toast.success i { color: var(--success); }
    .toast.error i { color: var(--danger); }
    .toast.info i { color: var(--info); }
    .toast span { font-size: 13px; color: var(--text-primary); flex: 1; }
    @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="main-content">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="#" onclick="goBack(); return false;">
            <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($product['name']); ?>
        </a>
        <span class="sep">/</span>
        <span class="current"><i class="fas fa-cube"></i> 3D View</span>
    </div>

    <div class="viewer-layout">

        <!-- LEFT: 3D Viewer -->
        <div class="viewer-wrapper">
            <div class="viewer-canvas">
                <div id="viewer3D"></div>
                <?php if (!$has_3d): ?>
                <div class="no-model-msg">
                    <i class="fas fa-cube"></i>
                    <h3>No 3D Model Available</h3>
                    <p><?php echo htmlspecialchars($product['name']); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Viewer controls -->
            <div class="viewer-controls">
                <button class="ctrl-btn" onclick="resetView()" title="Reset View"><i class="fas fa-undo"></i></button>
                <button class="ctrl-btn" id="rotateBtn" onclick="toggleAutoRotate()" title="Auto Rotate"><i class="fas fa-sync-alt"></i></button>
                <button class="ctrl-btn" id="wireBtn" onclick="toggleWireframe()" title="Wireframe"><i class="fas fa-border-all"></i></button>
                <button class="ctrl-btn" onclick="zoomIn()" title="Zoom In"><i class="fas fa-search-plus"></i></button>
                <button class="ctrl-btn" onclick="zoomOut()" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
            </div>

            <!-- 3D Color swatches (visual preview) -->
            <?php if ($has_colors && !$is_fully_sold_out): ?>
            <div class="color-strip">
                <span class="color-strip-label">3D Preview Color:</span>
                <div class="color-swatch reset-btn" onclick="resetColor()" title="Restore original">
                    <i class="fas fa-undo"></i>
                </div>
                <?php foreach ($product_colors as $c): if ($c['quantity'] <= 0) continue; ?>
                <div class="color-swatch"
                     style="background:<?php echo htmlspecialchars($c['code']); ?>"
                     onclick="pickColorFromSwatch('<?php echo htmlspecialchars($c['code']); ?>', '<?php echo htmlspecialchars($c['name']); ?>', this)"
                     title="<?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['quantity']; ?> left)">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Controls hint -->
            <div class="viewer-hint">
                <div class="hint-item"><i class="fas fa-mouse-pointer"></i> Drag to rotate</div>
                <div class="hint-item"><i class="fas fa-search"></i> Scroll to zoom</div>
                <div class="hint-item"><i class="fas fa-arrows-alt"></i> Pinch (mobile)</div>
            </div>
        </div>

        <!-- RIGHT: Info Panel -->
        <div class="info-panel">

            <!-- Product Info Card -->
            <div class="info-card">
                <div class="info-card-head">
                    <i class="fas fa-box"></i> Product Details
                </div>
                <div class="info-card-body">
                    <div class="category-pill">
                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                    </div>
                    <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                    <?php if ($is_on_sale): ?>
                    <div class="sale-banner-sm">
                        <i class="fas fa-fire"></i> On Sale! — Save ₱<?php echo number_format($savings, 2); ?>
                        <span style="margin-left:auto; opacity:0.85; font-size:11px;">-<?php echo $discount_pct; ?>%</span>
                    </div>
                    <?php endif; ?>

                    <div class="price-box">
                        <span class="price-main <?php echo $is_on_sale ? 'sale' : ''; ?>">
                            ₱<?php echo number_format($display_price, 2); ?>
                        </span>
                        <?php if ($is_on_sale): ?>
                            <span class="price-orig">₱<?php echo number_format($original_price, 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($product['description'])): ?>
                    <p class="product-desc"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    <?php endif; ?>

                    <!-- COLOR / VARIANT SELECTOR -->
                    <?php if ($has_colors && !$IS_SERVICE): ?>
                    <div class="color-selector-box">
                        <div class="cs-title">
                            <i class="fas fa-palette"></i>
                            Color / Variant
                            <span class="cs-selected-label" id="csSelectedLabel">— Select a color</span>
                        </div>
                        <?php if ($is_fully_sold_out): ?>
                            <div class="sold-out-notice">
                                <i class="fas fa-times-circle"></i>
                                <div>
                                    <strong>Out of Stock</strong>
                                    <p>All variants are currently unavailable.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="color-btns" id="colorBtns">
                                <?php foreach ($product_colors as $color): ?>
                                    <?php $oos = $color['quantity'] <= 0; ?>
                                    <button class="color-btn <?php echo $oos ? 'oos' : ''; ?>"
                                            data-code="<?php echo htmlspecialchars($color['code']); ?>"
                                            data-name="<?php echo htmlspecialchars($color['name']); ?>"
                                            data-qty="<?php echo $color['quantity']; ?>"
                                            onclick="selectColorFromButton(this)"
                                            <?php echo $oos ? 'disabled' : ''; ?>>
                                        <?php if (!empty($color['code']) && strlen($color['code']) >= 4): ?>
                                            <span class="color-dot" style="background:<?php echo htmlspecialchars($color['code']); ?>"></span>
                                        <?php endif; ?>
                                        <span class="color-btn-name"><?php echo htmlspecialchars($color['name']); ?></span>
                                        <?php if ($oos): ?>
                                            <span class="color-btn-qty oos-label">Out of Stock</span>
                                        <?php elseif ($color['quantity'] <= 5): ?>
                                            <span class="color-btn-qty low"><?php echo $color['quantity']; ?> left</span>
                                        <?php else: ?>
                                            <span class="color-btn-qty"><?php echo $color['quantity']; ?> left</span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <p class="cs-hint" id="csHint">
                                <i class="fas fa-info-circle"></i> Piliin ang variant para makita sa 3D at ma-prefill sa product page.
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Existing notices -->
                    <?php if ($existing_reservation): ?>
                    <div class="existing-notice reservation">
                        <i class="fas fa-bookmark"></i>
                        Already reserved. <a href="my-reservations.php">View →</a>
                    </div>
                    <?php elseif ($existing_appointment): ?>
                    <div class="existing-notice appointment">
                        <i class="fas fa-calendar-check"></i>
                        Already booked. <a href="my-appointments.php">View →</a>
                    </div>
                    <?php endif; ?>

                    <!-- Favorite Button -->
                    <div class="fav-product-wrap">
                        <button class="btn-fav-product-full <?php echo $is_product_favorited ? 'active' : ''; ?>"
                                id="productFavBtn"
                                data-product-id="<?php echo $product_id; ?>"
                                onclick="toggleProductFav(this)">
                            <i class="fa<?php echo $is_product_favorited ? 's' : 'r'; ?> fa-heart"></i>
                            <span><?php echo $is_product_favorited ? 'Saved to Favorites' : 'Save to Favorites'; ?></span>
                        </button>
                    </div>

                    <!-- Action Button -->
                    <?php if ($existing_reservation): ?>
                        <a href="my-reservations.php" class="btn-main-action">
                            <i class="fas fa-bookmark"></i> View My Reservation
                        </a>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> May active reservation ka na para sa product na 'to.</p>
                    <?php elseif ($existing_appointment): ?>
                        <a href="my-appointments.php" class="btn-main-action apt">
                            <i class="fas fa-calendar-check"></i> View My Appointment
                        </a>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> May pending appointment ka na para sa product na 'to.</p>
                    <?php elseif ($is_fully_sold_out): ?>
                        <button class="btn-main-action" disabled>
                            <i class="fas fa-times-circle"></i> Out of Stock
                        </button>
                        <p class="action-helper" style="color:var(--danger);">
                            <i class="fas fa-info-circle"></i> All variants are currently unavailable.
                        </p>
                    <?php elseif ($IS_SERVICE): ?>
                        <a href="product-view.php?id=<?php echo $product_id; ?>" class="btn-main-action apt">
                            <i class="fas fa-calendar-plus"></i> Book Appointment
                        </a>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> Buksan ang product page para mag-book.</p>
                    <?php else: ?>
                        <button class="btn-main-action" onclick="goToReserve()">
                            <i class="fas fa-shopping-bag"></i> Reserve This Product
                        </button>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> Dadalhin ka sa product page para sa reservation.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Clinic Card -->
            <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="clinic-card">
                <div class="clinic-logo-box">
                    <?php if (!empty($product['clinic_logo'])): ?>
                        <img src="/assets/images/clinic-logos/<?php echo $product['clinic_logo']; ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-store-alt"></i>
                    <?php endif; ?>
                </div>
                <div class="clinic-info">
                    <div class="cn"><?php echo htmlspecialchars($product['clinic_name']); ?></div>
                    <div class="ca"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(($product['clinic_address'] ?? '') . ', ' . ($product['clinic_city'] ?? '')); ?></div>
                    <?php if (!empty($product['clinic_hours'])): ?>
                    <div class="ch"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($product['clinic_hours']); ?></div>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-right" style="color:var(--primary); flex-shrink:0; margin-top:2px;"></i>
            </a>

        </div><!-- /info-panel -->
    </div><!-- /viewer-layout -->
</div><!-- /main-content -->

<script>
// ============================================
// GO BACK
// ============================================
function goBack() {
    if (document.referrer && document.referrer.indexOf(window.location.hostname) !== -1) {
        window.history.back();
    } else {
        window.location.href = 'product-view.php?id=<?php echo $product_id; ?>';
    }
}

// ============================================
// RESERVE — redirect to product-view.php
// ============================================
function goToReserve() {
    let url = 'product-view.php?id=<?php echo $product_id; ?>';
    if (selectedColor && selectedColor.code) {
        url += '&color=' + encodeURIComponent(selectedColor.code);
    }
    window.location.href = url;
}

// ============================================
// COLOR SELECTION — from info panel buttons
// ============================================
let selectedColor = null;

function selectColorFromButton(btn) {
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    selectedColor = {
        code: btn.dataset.code,
        name: btn.dataset.name
    };

    const label = document.getElementById('csSelectedLabel');
    if (label) {
        label.textContent = selectedColor.name;
        label.classList.add('chosen');
    }
    const hint = document.getElementById('csHint');
    if (hint) hint.style.display = 'none';

    // Update 3D model color
    changeColorModel(selectedColor.code);

    // Also visually highlight the matching 3D swatch (if any)
    document.querySelectorAll('.color-swatch').forEach(s => {
        s.classList.toggle('active', s.style.background.includes(selectedColor.code.toLowerCase()) || s.title.startsWith(selectedColor.name));
    });
}

// ============================================
// COLOR SELECTION — from 3D swatches
// ============================================
function pickColorFromSwatch(code, name, el) {
    selectedColor = { code: code, name: name };

    // Highlight swatch
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
    if (el) el.classList.add('active');

    // Update info panel color buttons selection
    document.querySelectorAll('.color-btn').forEach(b => {
        b.classList.toggle('selected', b.dataset.code === code);
    });

    // Update label
    const label = document.getElementById('csSelectedLabel');
    if (label) {
        label.textContent = name;
        label.classList.add('chosen');
    }
    const hint = document.getElementById('csHint');
    if (hint) hint.style.display = 'none';

    // Update 3D model
    changeColorModel(code);
}

// ============================================
// UTILITIES — Toast
// ============================================
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success:'check-circle', error:'exclamation-circle', info:'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type]||'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity 0.3s'; setTimeout(()=>t.remove(),300); }, 3500);
}

// ============================================
// FAVORITE
// ============================================
function toggleProductFav(btn) {
    const productId = btn.dataset.productId;
    const isActive  = btn.classList.contains('active');
    const formData  = new FormData();
    formData.append('product_id', productId);
    btn.classList.toggle('active');
    btn.classList.add('pop');
    const icon  = btn.querySelector('i');
    const label = btn.querySelector('span');
    icon.className    = btn.classList.contains('active') ? 'fas fa-heart' : 'far fa-heart';
    label.textContent = btn.classList.contains('active') ? 'Saved to Favorites' : 'Save to Favorites';
    setTimeout(() => btn.classList.remove('pop'), 300);
    fetch('toggle-product-favorite.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.action === 'added' ? '❤️ Added to favorites!' : 'Removed from favorites', data.action === 'added' ? 'success' : 'info');
            } else {
                btn.classList.toggle('active');
                icon.className    = isActive ? 'fas fa-heart' : 'far fa-heart';
                label.textContent = isActive ? 'Saved to Favorites' : 'Save to Favorites';
                showToast('Something went wrong. Please try again.', 'error');
            }
        })
        .catch(() => {
            btn.classList.toggle('active');
            icon.className    = isActive ? 'fas fa-heart' : 'far fa-heart';
            label.textContent = isActive ? 'Saved to Favorites' : 'Save to Favorites';
            showToast('Network error. Please try again.', 'error');
        });
}

// ============================================
// 3D VIEWER
// ============================================
let scene, camera, renderer, controls, model;
let autoRotate = false, wireframeMode = false;
let originalMaterials = [];
let originalLensColors = new Map();

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($has_3d): ?>
    init3DViewer('<?php echo addslashes($model_file); ?>');
    <?php endif; ?>
});

function isLensMaterial(node, material) {
    const materialName = (material.name || '').toLowerCase();
    const nodeName = (node.name || '').toLowerCase();
    const lensKeywords = ['lens', 'glass', 'clear', 'transparent', 'window', 'lense', 'optic', 'lenses'];
    for (let keyword of lensKeywords) {
        if (materialName.includes(keyword) || nodeName.includes(keyword)) return true;
    }
    if (material.transparent === true || material.opacity < 1) return true;
    return false;
}

function init3DViewer(modelPath) {
    const container = document.getElementById('viewer3D');
    if (!container) return;
    originalLensColors.clear();
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0xFFFFFF);
    const w = container.clientWidth || 600;
    const h = container.clientHeight || 480;
    camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 1000);
    camera.position.set(3, 1.5, 4);
    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(w, h);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer.domElement);
    controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;
    controls.autoRotate = false;
    controls.autoRotateSpeed = 2.0;
    controls.enableZoom = true;
    controls.enablePan = false;
    controls.target.set(0, 1.5, 0);
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
    mainLight.position.set(2, 5, 3);
    mainLight.castShadow = true;
    scene.add(mainLight);
    const fillLight = new THREE.DirectionalLight(0xffddbb, 0.8);
    fillLight.position.set(-2, 2, 2);
    scene.add(fillLight);
    const backLight = new THREE.PointLight(0x88aaff, 0.6);
    backLight.position.set(0, 2, -3);
    scene.add(backLight);
    const loader = new THREE.GLTFLoader();
    const fullPath = '/' + modelPath;
    loader.load(fullPath, function(gltf) {
        model = gltf.scene;
        model.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                materials.forEach((mat, idx) => {
                    if (isLensMaterial(node, mat) && mat.color) {
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        originalLensColors.set(key, {
                            color: mat.color.getHex(),
                            transparent: mat.transparent || false,
                            opacity: mat.opacity !== undefined ? mat.opacity : 1
                        });
                    }
                });
            }
        });
        const box = new THREE.Box3().setFromObject(model);
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        const scale = 2.5 / maxDim;
        model.scale.set(scale, scale, scale);
        model.position.set(0, 1.5, 0);
        model.traverse(node => {
            if (node.isMesh) {
                node.castShadow = true;
                node.receiveShadow = true;
                if (node.material) {
                    const mats = Array.isArray(node.material) ? node.material : [node.material];
                    mats.forEach((mat, idx) => {
                        if (mat && mat.color) {
                            originalMaterials.push({ node, idx: Array.isArray(node.material) ? idx : -1, color: mat.color.clone() });
                        }
                    });
                }
            }
        });
        scene.add(model);
        showToast('3D model loaded!', 'success');
    }, null, function(err) { console.error('3D load error:', err); showToast('Failed to load 3D model.', 'error'); });
    function animate() {
        requestAnimationFrame(animate);
        if (controls) { controls.autoRotate = autoRotate; controls.update(); }
        if (renderer && scene && camera) renderer.render(scene, camera);
    }
    animate();
    window.addEventListener('resize', () => {
        const w2 = container.clientWidth;
        const h2 = container.clientHeight;
        if (camera && renderer && w2 && h2) {
            camera.aspect = w2 / h2;
            camera.updateProjectionMatrix();
            renderer.setSize(w2, h2);
        }
    });
}

function resetView() {
    if (camera && controls) { camera.position.set(3, 1.5, 4); controls.target.set(0, 1.5, 0); controls.update(); }
    showToast('View reset', 'info');
}

function toggleAutoRotate() {
    autoRotate = !autoRotate;
    document.getElementById('rotateBtn').classList.toggle('active', autoRotate);
    showToast(autoRotate ? 'Auto-rotate on' : 'Auto-rotate off', 'info');
}

function toggleWireframe() {
    wireframeMode = !wireframeMode;
    document.getElementById('wireBtn').classList.toggle('active', wireframeMode);
    if (model) {
        model.traverse(node => {
            if (node.isMesh) {
                const mats = Array.isArray(node.material) ? node.material : [node.material];
                mats.forEach(mat => { if (mat) mat.wireframe = wireframeMode; });
            }
        });
    }
    showToast(wireframeMode ? 'Wireframe on' : 'Wireframe off', 'info');
}

function zoomIn() { if (camera) camera.position.multiplyScalar(0.9); }
function zoomOut() { if (camera) camera.position.multiplyScalar(1.1); }

function changeColorModel(colorCode) {
    if (!model) return;
    model.traverse(node => {
        if (node.isMesh && node.material) {
            const materials = Array.isArray(node.material) ? node.material : [node.material];
            materials.forEach((mat, idx) => {
                if (mat && mat.color) {
                    const isLens = isLensMaterial(node, mat);
                    const key = `${node.uuid}_${mat.uuid}_${idx}`;
                    if (!isLens) {
                        mat.color.set(colorCode);
                        const darkColors = ['#000000', '#2C2C2C', '#111111', '#2C3539', '#000080', '#800000'];
                        mat.emissiveIntensity = darkColors.includes(colorCode.toLowerCase()) ? 0.1 : 0;
                    } else if (originalLensColors.has(key)) {
                        const original = originalLensColors.get(key);
                        mat.color.setHex(original.color);
                        mat.transparent = original.transparent;
                        mat.opacity = original.opacity;
                    }
                }
            });
        }
    });
}

function resetColor() {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('selected'));
    selectedColor = null;
    const label = document.getElementById('csSelectedLabel');
    if (label) { label.textContent = '— Select a color'; label.classList.remove('chosen'); }
    const hint = document.getElementById('csHint');
    if (hint) hint.style.display = '';

    if (!model) return;
    let idx = 0;
    model.traverse(node => {
        if (node.isMesh && node.material) {
            const materials = Array.isArray(node.material) ? node.material : [node.material];
            materials.forEach((mat, matIdx) => {
                if (mat && mat.color && originalMaterials[idx]) {
                    const isLens = isLensMaterial(node, mat);
                    if (!isLens) {
                        mat.color.copy(originalMaterials[idx].color);
                    } else if (originalLensColors.has(`${node.uuid}_${mat.uuid}_${matIdx}`)) {
                        const original = originalLensColors.get(`${node.uuid}_${mat.uuid}_${matIdx}`);
                        mat.color.setHex(original.color);
                        mat.transparent = original.transparent;
                        mat.opacity = original.opacity;
                    }
                }
                idx++;
            });
        }
    });
    showToast('Original colors restored', 'info');
}
</script>
</body>
</html>