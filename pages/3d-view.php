<?php
// ============================================
// 3D-VIEW.PHP — VIEWING MODE
// Product details viewing lang — walang order button
// May "View Full Product Page" link papunta sa product-view.php
// ============================================

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

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

// ============================================
// GET PRODUCT + CLINIC DETAILS
// ============================================
$product_query = mysqli_query($conn, "
    SELECT p.*,
           p3d.model_file,
           p3d.model_type,
           p3d.has_3d,
           c.id as clinic_id,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.hours as clinic_hours,
           c.logo as clinic_logo,
           c.downpayment_percentage
    FROM products p
    LEFT JOIN product_3d_models p3d ON p.inventory_id = p3d.inventory_id OR p.id = p3d.product_id
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.id = $product_id
");

if (mysqli_num_rows($product_query) == 0) { header('Location: dashboard.php'); exit(); }
$product = mysqli_fetch_assoc($product_query);
$clinic_id = $product['clinic_id'];

// ============================================
// CATEGORY LOGIC
// ============================================
$category = $product['category'];
$NEEDS_LENS_SELECTION = in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses', 'Contact Lenses']);
$IS_ACCESSORY = in_array($category, ['Accessories', 'Parts', 'Cleaning Kits']);
$IS_SERVICE = in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening']);
$IS_CONTACT_LENS = ($category === 'Contact Lenses');
$IS_LENS_ONLY = ($category === 'Lenses');

// ============================================
// EXTRA FIELDS (for sizes)
// ============================================
$extra_fields = [];
if (!empty($product['extra_fields_json'])) {
    $extra_fields = json_decode($product['extra_fields_json'], true);
}
$available_sizes = [];
if (!empty($extra_fields['sizes_available']) && is_array($extra_fields['sizes_available'])) {
    $available_sizes = $extra_fields['sizes_available'];
}
$has_sizes = !empty($available_sizes) && in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses']);

// ============================================
// SALE DETECTION
// ============================================
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

// ============================================
// 3D MODEL CHECK
// ============================================
$has_3d = !empty($product['model_file']) && ($product['has_3d'] == 1 || $product['has_3d'] == '1');
$model_file = $product['model_file'] ?? '';

// ============================================
// EXISTING RESERVATION / APPOINTMENT CHECK
// ============================================
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

// ============================================
// GET COLORS
// ============================================
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
$has_stock_tracking = $has_colors;

// ============================================
// FAVORITE CHECK
// ============================================
$fav_check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
$is_product_favorited = mysqli_num_rows($fav_check) > 0;

// ============================================
// PAYMENT POLICY
// ============================================
$clinic_payment_config = getClinicPaymentPolicy($conn, $clinic_id);
$clinic_payment_policy = $clinic_payment_config['payment_policy'];
$clinic_downpayment_percent = (float)($clinic_payment_config['downpayment_percentage'] ?? 30);
$clinic_booking_flow = $clinic_payment_config['booking_flow'] ?? 'approve_first';

$policy_labels = [
    'full_payment' => '100% Full Payment',
    'downpayment_30' => '30% Downpayment',
    'downpayment_custom' => $clinic_downpayment_percent . '% Downpayment',
    'pay_on_site' => 'Pay On-Site Only',
    'no_payment' => 'Free Service'
];
$clinic_payment_policy_display = $policy_labels[$clinic_payment_policy] ?? 'Standard Payment';

// Tax/discount display
$tax_calc = applyTaxAndDiscount($conn, $display_price, false);
$vat_rate_display      = $tax_calc['vat_rate'];
$discount_rate_display = $tax_calc['discount_rate'];

$payment_info = getPaymentDisplayInfo($conn, $clinic_id, $display_price, false);
$payment_type = $payment_info['payment_type'] ?? 'downpayment';
$downpayment_percent = $clinic_downpayment_percent;
$downpayment_amount = $payment_info['downpayment_amount'] ?? round($display_price * ($downpayment_percent / 100), 2);
$balance_amount = $payment_info['balance_amount'] ?? ($display_price - $downpayment_amount);

// Navbar vars
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;
$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;
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

    .main-content { max-width: 1400px; margin: 0 auto; padding: 28px 20px 80px; }
    @media (min-width: 1024px) { .main-content { padding: 32px 40px 60px; } }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* Breadcrumb */
    .breadcrumb { display: flex; align-items: center; gap: 8px; margin-bottom: 24px; font-size: 13px; flex-wrap: wrap; }
    .breadcrumb a {
        color: var(--primary); text-decoration: none; font-weight: 500;
        display: flex; align-items: center; gap: 6px; padding: 6px 14px;
        background: var(--bg-secondary); border-radius: var(--radius-full);
        border: 1px solid var(--border-light); transition: all 0.2s;
    }
    .breadcrumb a:hover { background: var(--primary); color: white; }
    .breadcrumb .sep { color: var(--border-color); }
    .breadcrumb .current { color: var(--text-secondary); font-size: 13px; }

    .viewer-layout { display: grid; grid-template-columns: 1.6fr 1fr; gap: 28px; align-items: start; }
    @media (max-width: 1024px) { .viewer-layout { grid-template-columns: 1fr; } }

    /* 3D VIEWER */
    .viewer-wrapper {
        background: var(--bg-secondary); border-radius: var(--radius-lg);
        border: 1px solid var(--border-light); overflow: hidden;
        box-shadow: var(--shadow-sm); position: sticky; top: 80px;
    }
    @media (max-width: 1024px) { .viewer-wrapper { position: static; } }

    .viewer-canvas { position: relative; width: 100%; height: 480px; background: var(--viewer-bg); overflow: hidden; }
    @media (max-width: 768px) { .viewer-canvas { height: 320px; } }
    #viewer3D { width: 100%; height: 100%; }

    .no-model-msg {
        position: absolute; inset: 0; display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        color: rgba(255,255,255,0.6); gap: 12px; text-align: center; padding: 20px;
    }
    .no-model-msg i { font-size: 64px; opacity: 0.2; }
    .no-model-msg h3 { color: white; font-size: 18px; }
    .no-model-msg p { font-size: 13px; }

    .viewer-controls { display: flex; justify-content: center; gap: 8px; padding: 16px; background: var(--bg-secondary); border-top: 1px solid var(--border-light); flex-wrap: wrap; }
    .ctrl-btn {
        width: 38px; height: 38px; border-radius: 50%;
        border: 1.5px solid var(--border-color); background: var(--bg-primary);
        color: var(--text-secondary); font-size: 14px; cursor: pointer;
        display: flex; align-items: center; justify-content: center; transition: all 0.2s;
    }
    .ctrl-btn:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.1); }
    .ctrl-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

    .color-strip { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--border-light); background: var(--bg-secondary); flex-wrap: wrap; }
    .color-strip-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); flex-shrink: 0; }
    .color-swatch {
        width: 28px; height: 28px; border-radius: 50%;
        border: 2.5px solid white; box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        cursor: pointer; transition: all 0.2s; position: relative;
    }
    .color-swatch:hover { transform: scale(1.2); }
    .color-swatch.active { box-shadow: 0 0 0 3px var(--primary); transform: scale(1.1); }
    .color-swatch.reset-btn { background: linear-gradient(45deg, #ccc 25%, #eee 25%, #eee 50%, #ccc 50%, #ccc 75%, #eee 75%); background-size: 8px 8px; display: flex; align-items: center; justify-content: center; }
    .color-swatch.reset-btn i { font-size: 11px; color: #666; }

    .viewer-hint { display: flex; gap: 16px; padding: 10px 16px; background: var(--bg-primary); border-top: 1px solid var(--border-light); flex-wrap: wrap; }
    .hint-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-muted); }
    .hint-item i { color: var(--primary); font-size: 12px; }

    /* INFO PANEL */
    .info-panel { display: flex; flex-direction: column; gap: 16px; }
    .info-card { background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); overflow: hidden; }
    .info-card-head { padding: 16px 20px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--text-primary); }
    .info-card-head i { color: var(--primary); }
    .info-card-body { padding: 20px; }

    .category-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; background: var(--primary-light); color: var(--primary); border-radius: var(--radius-full); font-size: 11px; font-weight: 600; margin-bottom: 10px; }
    .product-title { font-family: var(--font-display); font-size: 24px; color: var(--text-primary); margin-bottom: 14px; line-height: 1.2; }

    .price-box { background: var(--primary-light); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 14px; }
    .price-main { font-size: 28px; font-weight: 700; color: var(--primary); font-family: var(--font-display); }
    .price-main.sale { color: #EF4444; }
    .price-orig { font-size: 14px; color: var(--text-muted); text-decoration: line-through; margin-left: 8px; }

    .sale-banner-sm { display: flex; align-items: center; gap: 6px; padding: 7px 12px; background: linear-gradient(135deg, #EF4444, #FF6B6B); border-radius: var(--radius-md); margin-bottom: 10px; font-size: 12px; color: white; font-weight: 600; }

    .product-desc { font-size: 13px; color: var(--text-secondary); line-height: 1.7; margin-bottom: 16px; }

    /* PAYMENT POLICY CARD */
    .payment-policy-card { margin-top: 16px; padding: 14px; background: var(--bg-primary); border-radius: var(--radius-md); border-left: 4px solid var(--primary); font-size: 13px; }
    .payment-policy-card i { color: var(--primary); margin-right: 8px; }
    .payment-policy-card .policy-title { font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
    .payment-policy-card .policy-desc { color: var(--text-secondary); font-size: 12px; }

    .existing-notice { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: var(--radius-md); font-size: 12px; font-weight: 500; margin-bottom: 12px; }
    .existing-notice.reservation { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .existing-notice.appointment { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
    .existing-notice a { color: inherit; font-weight: 700; }

    /* ✅ VIEW FULL PRODUCT PAGE BUTTON */
    .btn-view-full-page {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 15px 20px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-full);
        font-size: 15px;
        font-weight: 700;
        font-family: var(--font-main);
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 14px rgba(0,183,97,0.3);
        margin-top: 10px;
    }
    .btn-view-full-page:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,183,97,0.4);
        color: white;
    }

    /* FLOW CARD */
    .flow-card { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 18px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); }
    .flow-card-title { font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 7px; margin-bottom: 14px; }
    .flow-card-title i { color: var(--primary); }

    .lens-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 6px; }
    .lens-btn {
        padding: 11px 10px; border: 2px solid var(--border-color); border-radius: var(--radius-md);
        background: var(--bg-primary); cursor: pointer; transition: all 0.2s; text-align: left;
        display: flex; flex-direction: column; gap: 3px;
    }
    .lens-btn:hover { border-color: var(--primary); background: var(--primary-light); }
    .lens-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
    .lens-btn .ln { font-weight: 700; font-size: 13px; color: var(--text-primary); }
    .lens-btn .lp { font-size: 11px; color: var(--primary); font-weight: 600; }
    .lens-btn .ld { font-size: 10px; color: var(--text-muted); }

    .rx-toggle { display: flex; gap: 10px; margin: 12px 0 8px; flex-wrap: wrap; }
    .rx-option {
        flex: 1; min-width: 130px; display: flex; align-items: center; gap: 8px;
        padding: 10px 12px; border: 2px solid var(--border-color); border-radius: var(--radius-md);
        cursor: pointer; background: var(--bg-primary); transition: all 0.2s;
        font-size: 12px; font-weight: 600; color: var(--text-secondary);
    }
    .rx-option input[type="radio"] { display: none; }
    .rx-option:has(input:checked) { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
    .rx-option .icon { width: 28px; height: 28px; border-radius: 7px; background: var(--border-light); display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .rx-option:has(input:checked) .icon { background: var(--primary); color: white; }

    .rx-form { background: var(--bg-primary); border-radius: var(--radius-md); padding: 14px; margin-top: 10px; border: 1px solid var(--border-light); }
    .rx-form h4 { font-size: 12px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 5px; }
    .rx-eyes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .rx-eye-box { background: var(--bg-secondary); border-radius: 9px; padding: 10px; border: 1px solid var(--border-light); }
    .rx-eye-label { font-size: 11px; font-weight: 700; color: var(--primary); margin-bottom: 7px; display: flex; align-items: center; gap: 4px; }
    .rx-eye-label span { background: var(--primary); color: white; padding: 1px 6px; border-radius: 3px; font-size: 9px; }
    .rx-inputs { display: flex; gap: 5px; }
    .rx-input-group { flex: 1; }
    .rx-input-group label { font-size: 8px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 2px; font-weight: 600; }
    .rx-input-group input { width: 100%; padding: 6px 4px; border: 1.5px solid var(--border-color); border-radius: 6px; background: var(--bg-primary); color: var(--text-primary); font-size: 12px; text-align: center; font-family: var(--font-main); }
    .rx-input-group input:focus { outline: none; border-color: var(--primary); }
    .rx-note { font-size: 10px; color: var(--text-muted); margin-top: 10px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px; }
    .rx-note i { color: var(--primary); }

    .eye-exam-box { background: var(--primary-light); border: 1px solid rgba(0,183,97,0.2); border-radius: var(--radius-md); padding: 14px; margin-top: 10px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 6px; }
    .eye-exam-box i { font-size: 24px; color: var(--primary); }
    .eye-exam-box p { font-size: 12px; color: var(--text-secondary); line-height: 1.5; }

    /* COLOR SELECTOR */
    .color-selector-box { background: var(--bg-primary); border-radius: var(--radius-md); padding: 14px; margin-bottom: 8px; border: 1px solid var(--border-light); }
    .cs-title { font-size: 13px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 6px; margin-bottom: 12px; }
    .cs-title i { color: var(--primary); }
    .cs-selected-label { font-weight: 500; color: var(--text-secondary); font-size: 12px; margin-left: auto; }
    .cs-selected-label.chosen { color: var(--primary); font-weight: 600; }
    .color-btns { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 8px; }
    .color-btn { display: flex; align-items: center; gap: 6px; padding: 7px 12px; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); cursor: pointer; transition: all 0.2s; font-family: inherit; }
    .color-btn:hover:not(:disabled):not(.oos) { border-color: var(--primary); background: var(--primary-light); }
    .color-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
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

    /* SIZE SELECTOR */
    .size-selector-box { background: var(--bg-primary); border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px; border: 1px solid var(--border-light); }
    .size-btns { display: flex; flex-wrap: wrap; gap: 8px; }
    .size-btn { padding: 8px 16px; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.2s; }
    .size-btn:hover, .size-btn.selected { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }

    /* FAVORITE BUTTON */
    .fav-product-wrap { margin-bottom: 12px; }
    .btn-fav-product-full {
        width: 100%; display: flex; align-items: center; justify-content: center;
        gap: 10px; padding: 13px 20px; border-radius: var(--radius-full);
        border: 2px solid #e5e7eb; background: transparent;
        color: var(--text-secondary); font-size: 15px; font-weight: 600;
        cursor: pointer; transition: all 0.2s; font-family: inherit;
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
        background: var(--bg-secondary); border-radius: var(--radius-lg);
        padding: 16px; border: 1px solid var(--border-light);
        display: flex; gap: 12px; align-items: flex-start;
        text-decoration: none; transition: all 0.2s; box-shadow: var(--shadow-sm);
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
        <a href="#" onclick="goBack(); return false;" id="backButton">
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

            <!-- Color swatches -->
            <?php if ($has_colors && !$is_fully_sold_out): ?>
            <div class="color-strip">
                <span class="color-strip-label">3D Color:</span>
                <div class="color-swatch reset-btn" onclick="resetColor()" title="Restore original">
                    <i class="fas fa-undo"></i>
                </div>
                <?php foreach ($product_colors as $c): if ($c['quantity'] <= 0) continue; ?>
                <div class="color-swatch"
                     style="background:<?php echo htmlspecialchars($c['code']); ?>"
                     onclick="changeColor('<?php echo htmlspecialchars($c['code']); ?>', this)"
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

                    <!-- PAYMENT POLICY CARD -->
                    <div class="payment-policy-card">
                        <div class="policy-title">
                            <i class="fas fa-credit-card"></i> Payment Policy: <?php echo $clinic_payment_policy_display; ?>
                            <?php if ($clinic_booking_flow == 'pay_first'): ?>
                                <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-left: 8px;">Pay First</span>
                            <?php else: ?>
                                <span style="background: var(--warning); color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-left: 8px;">Approve First</span>
                            <?php endif; ?>
                        </div>
                        <div class="policy-desc">
                            <?php if ($payment_type == 'downpayment'): ?>
                                <i class="fas fa-percent"></i> <?php echo $downpayment_percent; ?>% downpayment (₱<?php echo number_format($downpayment_amount, 2); ?>) required online.
                                Balance of ₱<?php echo number_format($balance_amount, 2); ?> to be paid at the clinic.
                            <?php elseif ($payment_type == 'full'): ?>
                                <i class="fas fa-cash"></i> 100% full payment of ₱<?php echo number_format($downpayment_amount, 2); ?> required online.
                            <?php elseif ($payment_type == 'onsite'): ?>
                                <i class="fas fa-store"></i> Pay ₱<?php echo number_format($display_price, 2); ?> directly at the clinic. No online payment required.
                            <?php elseif ($payment_type == 'free'): ?>
                                <i class="fas fa-gift"></i> This is a free service. No payment required.
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- COLOR / VARIANT SELECTOR -->
                    <?php if ($has_stock_tracking && !$IS_SERVICE): ?>
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
                                            onclick="selectColor(this)"
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
                                <i class="fas fa-info-circle"></i> Click a color to preview on 3D model.
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- SIZE SELECTOR -->
                    <?php if ($has_sizes): ?>
                    <div class="size-selector-box">
                        <div class="cs-title">
                            <i class="fas fa-ruler-combined"></i>
                            Frame Size
                            <span class="cs-selected-label" id="sizeSelectedLabel">— Select a size</span>
                        </div>
                        <div class="size-btns" id="sizeBtns">
                            <?php foreach ($available_sizes as $size): ?>
                            <button class="size-btn" data-size="<?php echo htmlspecialchars($size); ?>" onclick="selectSize(this)">
                                <?php echo htmlspecialchars($size); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="cs-hint" id="sizeHint">
                            <i class="fas fa-info-circle"></i> Select your preferred frame size.
                        </p>
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

                    <!-- ✅ VIEW FULL PRODUCT PAGE — Ito lang ang action button -->
                    <a href="product-view.php?id=<?php echo $product_id; ?>" class="btn-view-full-page">
                        <i class="fas fa-external-link-alt"></i> View Full Product Page
                    </a>
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
// GO BACK FUNCTION
// ============================================
function goBack() {
    if (document.referrer && document.referrer.indexOf(window.location.hostname) !== -1) {
        window.history.back();
    } else {
        window.location.href = 'product-view.php?id=<?php echo $product_id; ?>';
    }
}

// ============================================
// VARIABLES
// ============================================
let selectedLens = '<?php echo ($IS_ACCESSORY || $IS_SERVICE) ? '' : ($IS_LENS_ONLY ? 'single_vision' : ($IS_CONTACT_LENS ? 'contact_daily' : 'frame_only')); ?>';
let rxKnowledge = null;
let selectedColor = null;
let selectedSize = null;

const IS_LENS_ONLY       = <?php echo $IS_LENS_ONLY ? 'true' : 'false'; ?>;
const IS_CONTACT         = <?php echo $IS_CONTACT_LENS ? 'true' : 'false'; ?>;
const NEEDS_LENS         = <?php echo $NEEDS_LENS_SELECTION ? 'true' : 'false'; ?>;

// ============================================
// SIZE SELECTION
// ============================================
function selectSize(btn) {
    document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedSize = btn.dataset.size;
    const label = document.getElementById('sizeSelectedLabel');
    if (label) { label.textContent = selectedSize; label.classList.add('chosen'); }
}

// ============================================
// COLOR SELECTION
// ============================================
function selectColor(btn) {
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedColor = {
        code: btn.dataset.code,
        name: btn.dataset.name,
        qty:  parseInt(btn.dataset.qty),
    };
    const label = document.getElementById('csSelectedLabel');
    if (label) { label.textContent = selectedColor.name + ' (' + selectedColor.qty + ' left)'; label.classList.add('chosen'); }
    changeColor(selectedColor.code, null);
}

// ============================================
// LENS SELECTION
// ============================================
function selectLens(btn) {
    document.querySelectorAll('.lens-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLens = btn.dataset.lens;

    const rxSection = document.getElementById('rxSection');
    const isFrameOnly = (selectedLens === 'frame_only');
    if (rxSection) {
        rxSection.style.display = (!isFrameOnly && NEEDS_LENS) ? 'block' : 'none';
        if (isFrameOnly) {
            rxKnowledge = null;
            document.querySelectorAll('input[name="rx_know"]').forEach(r => r.checked = false);
            document.getElementById('rxFormBox').style.display = 'none';
            document.getElementById('rxExamBox').style.display = 'none';
        }
    }
}

function handleRxKnowledge(radio) {
    rxKnowledge = radio.value;
    document.getElementById('rxFormBox').style.display = rxKnowledge === 'know' ? 'block' : 'none';
    document.getElementById('rxExamBox').style.display = rxKnowledge === 'dont_know' ? 'block' : 'none';
}

// ============================================
// TOAST
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
// FAVORITE TOGGLE
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

    // Initialize rx section visibility
    const rxSection = document.getElementById('rxSection');
    if (rxSection) {
        if (IS_LENS_ONLY || IS_CONTACT) {
            rxSection.style.display = 'block';
        } else if (NEEDS_LENS && selectedLens !== 'frame_only' && selectedLens !== '') {
            rxSection.style.display = 'block';
        } else if (selectedLens === 'frame_only') {
            rxSection.style.display = 'none';
        }
    }
});

function isLensMaterial(node, material) {
    const materialName = (material.name || '').toLowerCase();
    const nodeName = (node.name || '').toLowerCase();
    const lensKeywords = ['lens', 'glass', 'clear', 'transparent', 'window', 'lense', 'optic', 'lenses'];
    for (let keyword of lensKeywords) {
        if (materialName.includes(keyword) || nodeName.includes(keyword)) {
            return true;
        }
    }
    if (material.transparent === true || material.opacity < 1) {
        return true;
    }
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
    const ambient = new THREE.AmbientLight(0xffffff, 0.6);
    scene.add(ambient);
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
        if (originalLensColors.size === 0) {
            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = `
                position: absolute; bottom: 60px; left: 16px; right: 16px;
                background: #fff3cd; border: 1px solid #ffeeba; color: #856404;
                padding: 8px 12px; border-radius: 8px; font-size: 11px;
                text-align: center; z-index: 100;
            `;
            warningDiv.innerHTML = `<i class="fas fa-info-circle"></i> <strong>Note:</strong> No lens material detected. The entire frame (including lens area) will change color.`;
            const viewerCanvas = document.querySelector('.viewer-canvas');
            if (viewerCanvas) viewerCanvas.style.position = 'relative';
            if (viewerCanvas && !viewerCanvas.querySelector('.lens-warning')) {
                warningDiv.classList.add('lens-warning');
                viewerCanvas.appendChild(warningDiv);
                setTimeout(() => warningDiv.remove(), 5000);
            }
        }
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

function changeColor(colorCode, el) {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
    if (el) el.classList.add('active');
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
    showToast('Color changed', 'info');
}

function resetColor() {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
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