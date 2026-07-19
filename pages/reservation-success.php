<?php

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$reservation_id) {
    header('Location: my-reservations.php');
    exit();
}

// ============================================
// GET RESERVATION DETAILS
// ============================================
$res_query = mysqli_query($conn, "
    SELECT r.*,
           p.name as product_name,
           p.category as product_category,
           p.images_json, p.images, p.image,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.hours as clinic_hours,
           c.logo as clinic_logo
    FROM reservations r
    JOIN products p ON r.product_id = p.id
    JOIN clinics c ON r.clinic_id = c.id
    WHERE r.id = $reservation_id
    AND r.user_id = $user_id
");

if (mysqli_num_rows($res_query) == 0) {
    header('Location: my-reservations.php');
    exit();
}
$res = mysqli_fetch_assoc($res_query);

// Get prescription if any
$prescription = null;
if ($res['prescription_id']) {
    $prescription = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM user_prescriptions WHERE id = {$res['prescription_id']}"
    ));
}

// Lens type labels
$lens_labels = [
    'frame_only'      => 'Frame Only (No Lenses)',
    'single_vision'   => 'Single Vision Lenses',
    'progressive'     => 'Progressive Lenses',
    'blue_cut'        => 'Blue Cut Lenses',
    'contact_daily'   => 'Daily Disposable Contact Lens',
    'contact_monthly' => 'Monthly Wear Contact Lens',
];
$lens_label = $lens_labels[$res['lens_type']] ?? ($res['lens_type'] ?? 'N/A');

// Payment status labels
$payment_labels = [
    'unpaid'  => ['label' => 'Pending Payment', 'color' => '#F59E0B', 'bg' => '#FEF3C7', 'icon' => 'clock'],
    'partial' => ['label' => 'Downpayment Submitted', 'color' => '#3B82F6', 'bg' => '#DBEAFE', 'icon' => 'check-circle'],
    'paid'    => ['label' => 'Fully Paid', 'color' => '#00B761', 'bg' => '#E8FAF0', 'icon' => 'check-double'],
];
$pay_info = $payment_labels[$res['payment_status']] ?? $payment_labels['unpaid'];

// Product image
function getFirstImage($product) {
    if (!empty($product['images_json'])) {
        $imgs = json_decode($product['images_json'], true);
        if (!empty($imgs[0])) {
            $p = str_replace('uploads/uploads/', 'uploads/', $imgs[0]);
            return strpos($p, 'uploads/') === 0 ? '/eyecore/' . $p : '/eyecore/uploads/products/' . $p;
        }
    }
    if (!empty($product['images'])) {
        $d = $product['images'];
        if (strpos($d, '[') === 0) {
            $imgs = json_decode($d, true);
            if (!empty($imgs[0])) {
                $p = str_replace('uploads/uploads/', 'uploads/', $imgs[0]);
                return strpos($p, 'uploads/') === 0 ? '/eyecore/' . $p : '/eyecore/uploads/products/' . $p;
            }
        }
    }
    if (!empty($product['image'])) return '/eyecore/assets/images/products/' . $product['image'];
    return '/eyecore/assets/img/no-image.png';
}
$product_img = getFirstImage($res);

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
$reservation_query_nav = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending','confirmed')");
$reservation_row_nav = mysqli_fetch_assoc($reservation_query_nav);
$reservation_count = $reservation_row_nav['total'] ?? 0;
$active_nav = '';

include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reservation Confirmed — Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; overflow-x: hidden; }

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
        --font-main: 'DM Sans', sans-serif;
        --font-display: 'DM Serif Display', serif;
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
    }

    body {
        font-family: var(--font-main);
        background: var(--bg-primary);
        color: var(--text-primary);
        transition: background 0.3s, color 0.3s;
    }

    .main-content {
        max-width: 760px;
        margin: 0 auto;
        padding: 28px 20px 80px;
    }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* ===== SUCCESS HERO ===== */
    .success-hero {
        text-align: center;
        padding: 40px 20px 32px;
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }

    /* Confetti background dots */
    .success-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            radial-gradient(circle, rgba(0,183,97,0.08) 1px, transparent 1px);
        background-size: 28px 28px;
        pointer-events: none;
    }

    .success-icon-wrap {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 90px;
        height: 90px;
        margin-bottom: 20px;
    }

    .success-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 3px solid var(--primary);
        opacity: 0.2;
        animation: ringPulse 2s ease-in-out infinite;
    }

    .success-ring-2 {
        position: absolute;
        inset: -10px;
        border-radius: 50%;
        border: 2px solid var(--primary);
        opacity: 0.1;
        animation: ringPulse 2s ease-in-out infinite 0.4s;
    }

    @keyframes ringPulse {
        0%, 100% { transform: scale(1); opacity: 0.2; }
        50% { transform: scale(1.08); opacity: 0.05; }
    }

    .success-icon {
        width: 80px;
        height: 80px;
        background: var(--primary-gradient);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
        color: white;
        box-shadow: 0 8px 24px rgba(0,183,97,0.35);
        animation: iconPop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
    }

    @keyframes iconPop {
        from { transform: scale(0); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    .success-hero h1 {
        font-family: var(--font-display);
        font-size: 30px;
        color: var(--text-primary);
        margin-bottom: 8px;
        animation: fadeUp 0.5s ease 0.2s both;
    }

    .success-hero p {
        font-size: 14px;
        color: var(--text-secondary);
        max-width: 420px;
        margin: 0 auto 20px;
        line-height: 1.6;
        animation: fadeUp 0.5s ease 0.3s both;
    }

    @keyframes fadeUp {
        from { transform: translateY(16px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Reservation code badge */
    .res-code-badge {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: var(--bg-primary);
        border: 2px dashed var(--primary);
        border-radius: var(--radius-md);
        padding: 12px 20px;
        font-family: monospace;
        font-size: 18px;
        font-weight: 700;
        color: var(--primary);
        letter-spacing: 2px;
        cursor: pointer;
        transition: all 0.2s;
        animation: fadeUp 0.5s ease 0.4s both;
        position: relative;
    }
    .res-code-badge:hover { background: var(--primary-light); }
    .res-code-badge .copy-hint {
        font-size: 11px;
        font-weight: 500;
        color: var(--text-muted);
        font-family: var(--font-main);
        letter-spacing: 0;
    }

    /* Payment status badge */
    .pay-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 16px;
        border-radius: var(--radius-full);
        font-size: 13px;
        font-weight: 700;
        margin-top: 14px;
        animation: fadeUp 0.5s ease 0.5s both;
    }

    /* ===== CARDS ===== */
    .card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 20px;
        animation: fadeUp 0.5s ease 0.3s both;
    }

    .card-head {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card-head h3 { font-size: 15px; font-weight: 700; color: var(--text-primary); }
    .card-head i { color: var(--primary); }
    .card-body { padding: 20px; }

    /* Product summary */
    .product-row {
        display: flex;
        gap: 14px;
        align-items: center;
    }
    .product-thumb {
        width: 70px;
        height: 70px;
        border-radius: var(--radius-sm);
        overflow: hidden;
        border: 1px solid var(--border-light);
        flex-shrink: 0;
        background: var(--bg-primary);
    }
    .product-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .product-meta .pm-cat {
        font-size: 11px;
        font-weight: 600;
        color: var(--primary);
        text-transform: uppercase;
        margin-bottom: 3px;
    }
    .product-meta .pm-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    .product-meta .pm-lens {
        font-size: 12px;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .product-meta .pm-lens i { color: var(--primary); }

    /* Detail rows */
    .detail-list { margin-top: 4px; }
    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 10px 0;
        border-bottom: 1px solid var(--border-light);
        font-size: 13px;
        gap: 12px;
    }
    .detail-row:last-child { border-bottom: none; padding-bottom: 0; }
    .dr-label {
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 7px;
        flex-shrink: 0;
        min-width: 130px;
    }
    .dr-label i { color: var(--primary); width: 14px; text-align: center; }
    .dr-value { font-weight: 600; color: var(--text-primary); text-align: right; }
    .dr-value.code { font-family: monospace; color: var(--primary); font-size: 14px; }

    /* Price breakdown */
    .price-box {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 16px;
    }
    .pb-row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: var(--text-secondary);
        padding: 6px 0;
        border-bottom: 1px dashed var(--border-light);
    }
    .pb-row:last-child { border-bottom: none; }
    .pb-row.total {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        border-top: 2px solid var(--border-color);
        border-bottom: none;
        padding-top: 10px;
        margin-top: 4px;
    }
    .pb-row.dp {
        font-weight: 700;
        color: var(--warning);
        font-size: 14px;
    }
    .pb-row.balance { color: var(--text-muted); font-size: 12px; }

    /* Prescription display */
    .rx-box {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 16px;
    }
    .rx-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 12px;
    }
    .rx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .rx-eye {
        background: var(--bg-secondary);
        border-radius: 10px;
        padding: 12px;
        border: 1px solid var(--border-light);
    }
    .rx-eye-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .rx-vals { display: flex; gap: 6px; }
    .rx-val { flex: 1; text-align: center; }
    .rx-val .rvl { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
    .rx-val .rvn { font-size: 13px; font-weight: 700; color: var(--text-primary); }
    .rx-verify {
        font-size: 11px;
        color: var(--text-muted);
        text-align: center;
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }
    .rx-verify i { color: var(--primary); }

    /* What's next steps */
    .next-steps { counter-reset: steps; }
    .next-step {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        padding: 14px 0;
        border-bottom: 1px solid var(--border-light);
    }
    .next-step:last-child { border-bottom: none; padding-bottom: 0; }
    .step-num {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--primary-light);
        color: var(--primary);
        font-size: 14px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .step-content .sc-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 3px;
    }
    .step-content .sc-desc {
        font-size: 12px;
        color: var(--text-secondary);
        line-height: 1.5;
    }

    /* Clinic card */
    .clinic-strip {
        display: flex;
        gap: 14px;
        align-items: center;
    }
    .clinic-logo-box {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: var(--primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .clinic-logo-box img { width: 100%; height: 100%; object-fit: cover; }
    .clinic-logo-box i { font-size: 22px; color: var(--primary); }
    .clinic-info .ci-name { font-size: 15px; font-weight: 700; color: var(--text-primary); margin-bottom: 3px; }
    .clinic-info .ci-addr {
        font-size: 12px;
        color: var(--text-secondary);
        display: flex;
        align-items: flex-start;
        gap: 4px;
    }
    .clinic-info .ci-addr i { color: var(--primary); margin-top: 1px; flex-shrink: 0; }
    .clinic-info .ci-hours {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 3px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .clinic-info .ci-hours i { color: var(--primary); }

    /* Action buttons */
    .action-buttons {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 4px;
    }
    .btn-action {
        flex: 1;
        min-width: 140px;
        padding: 13px 16px;
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
        border: none;
    }
    .btn-action.primary {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 4px 14px rgba(0,183,97,0.3);
    }
    .btn-action.primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,183,97,0.4); }
    .btn-action.secondary {
        background: var(--bg-primary);
        color: var(--text-secondary);
        border: 1.5px solid var(--border-color);
    }
    .btn-action.secondary:hover { border-color: var(--primary); color: var(--primary); }

    /* Screenshot proof */
    .screenshot-proof {
        text-align: center;
    }
    .screenshot-proof img {
        max-width: 100%;
        max-height: 220px;
        border-radius: var(--radius-md);
        border: 2px solid var(--border-color);
        object-fit: contain;
    }
    .screenshot-proof .sp-ref {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 8px;
        font-family: monospace;
    }

    /* Toast */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9990; }
    .toast {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        padding: 14px 20px;
        box-shadow: var(--shadow-lg);
        margin-bottom: 10px;
        min-width: 280px;
        animation: toastIn 0.3s ease;
        border-left: 4px solid var(--success);
    }
    .toast.success i { color: var(--success); }
    .toast span { font-size: 13px; color: var(--text-primary); }
    @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="main-content">

    <!-- ===== SUCCESS HERO ===== -->
    <div class="success-hero">
        <div class="success-icon-wrap">
            <div class="success-ring"></div>
            <div class="success-ring-2"></div>
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
        </div>

        <h1>
            <?php if ($res['payment_status'] === 'partial'): ?>
                Payment Submitted!
            <?php else: ?>
                Reservation Confirmed!
            <?php endif; ?>
        </h1>

        <p>
            <?php if ($res['payment_status'] === 'partial'): ?>
                Your downpayment proof has been submitted. The clinic will verify and confirm your reservation within 24 hours.
            <?php else: ?>
                Your reservation has been recorded. Please pay the downpayment at the clinic on your scheduled visit.
            <?php endif; ?>
        </p>

        <!-- Reservation Code -->
        <div class="res-code-badge" onclick="copyCode('<?php echo $res['reservation_code']; ?>')">
            <i class="fas fa-hashtag"></i>
            <?php echo htmlspecialchars($res['reservation_code']); ?>
            <span class="copy-hint"><i class="fas fa-copy"></i> Tap to copy</span>
        </div>

        <!-- Payment Status Badge -->
        <div>
            <div class="pay-status-badge" style="background: <?php echo $pay_info['bg']; ?>; color: <?php echo $pay_info['color']; ?>;">
                <i class="fas fa-<?php echo $pay_info['icon']; ?>"></i>
                <?php echo $pay_info['label']; ?>
            </div>
        </div>
    </div>

    <!-- ===== PRODUCT SUMMARY ===== -->
    <div class="card">
        <div class="card-head">
            <i class="fas fa-box"></i>
            <h3>What You Reserved</h3>
        </div>
        <div class="card-body">
            <div class="product-row">
                <div class="product-thumb">
                    <img src="<?php echo $product_img; ?>" alt=""
                         onerror="this.src='/eyecore/assets/img/no-image.png'">
                </div>
                <div class="product-meta">
                    <div class="pm-cat"><?php echo htmlspecialchars($res['product_category']); ?></div>
                    <div class="pm-name"><?php echo htmlspecialchars($res['product_name']); ?></div>
                    <div class="pm-lens">
                        <i class="fas fa-glasses"></i>
                        <?php echo htmlspecialchars($lens_label); ?>
                    </div>
                </div>
            </div>

            <div class="detail-list" style="margin-top: 16px;">
                <div class="detail-row">
                    <span class="dr-label"><i class="fas fa-store"></i> Clinic</span>
                    <span class="dr-value"><?php echo htmlspecialchars($res['clinic_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="dr-label"><i class="fas fa-calendar"></i> Preferred Date</span>
                    <span class="dr-value"><?php echo date('F j, Y', strtotime($res['preferred_date'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="dr-label"><i class="fas fa-clock"></i> Preferred Time</span>
                    <span class="dr-value"><?php echo date('g:i A', strtotime($res['preferred_time'])); ?></span>
                </div>
                <?php if (!empty($res['notes'])): ?>
                <div class="detail-row">
                    <span class="dr-label"><i class="fas fa-sticky-note"></i> Notes</span>
                    <span class="dr-value"><?php echo htmlspecialchars($res['notes']); ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="dr-label"><i class="fas fa-hashtag"></i> Reference Code</span>
                    <span class="dr-value code"><?php echo htmlspecialchars($res['reservation_code']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== PRICE BREAKDOWN ===== -->
    <div class="card">
        <div class="card-head">
            <i class="fas fa-receipt"></i>
            <h3>Payment Summary</h3>
        </div>
        <div class="card-body">
            <div class="price-box">
                <div class="pb-row">
                    <span>Product Price</span>
                    <span>₱<?php echo number_format($res['product_price'] ?? $res['total_amount'], 2); ?></span>
                </div>
                <?php
                $lens_add = $res['total_amount'] - ($res['product_price'] ?? $res['total_amount']);
                if ($lens_add > 0):
                ?>
                <div class="pb-row">
                    <span>Lens Upgrade</span>
                    <span>+₱<?php echo number_format($lens_add, 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="pb-row total">
                    <span>Total Amount</span>
                    <span>₱<?php echo number_format($res['total_amount'], 2); ?></span>
                </div>
                <div class="pb-row dp">
                    <span><i class="fas fa-bolt"></i> Downpayment</span>
                    <span>₱<?php echo number_format($res['downpayment_amount'], 2); ?></span>
                </div>
                <div class="pb-row balance">
                    <span>Balance (pay at clinic)</span>
                    <span>₱<?php echo number_format($res['balance_amount'], 2); ?></span>
                </div>
            </div>

            <!-- Payment proof if submitted -->
            <?php if (!empty($res['payment_screenshot']) || !empty($res['payment_reference'])): ?>
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-light);">
                <div style="font-size: 13px; font-weight: 700; color: var(--text-secondary); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-file-image" style="color:var(--primary)"></i> Payment Proof Submitted
                </div>
                <?php if (!empty($res['payment_reference'])): ?>
                <div class="detail-row" style="padding-top: 0;">
                    <span class="dr-label"><i class="fas fa-hashtag"></i> GCash Ref No.</span>
                    <span class="dr-value code"><?php echo htmlspecialchars($res['payment_reference']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($res['payment_screenshot'])): ?>
                <div class="screenshot-proof" style="margin-top: 12px;">
                    <img src="/eyecore/uploads/payment-proofs/<?php echo $res['payment_screenshot']; ?>"
                         alt="Payment Screenshot">
                    <div class="sp-ref">Screenshot submitted for verification</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== PRESCRIPTION (if applicable) ===== -->
    <?php if ($prescription && ($prescription['od_sph'] || $prescription['os_sph'])): ?>
    <div class="card">
        <div class="card-head">
            <i class="fas fa-prescription"></i>
            <h3>Your Prescription</h3>
        </div>
        <div class="card-body">
            <div class="rx-box">
                <div class="rx-title">Submitted Prescription (to be verified at clinic)</div>
                <div class="rx-grid">
                    <div class="rx-eye">
                        <div class="rx-eye-label"><i class="fas fa-eye"></i> Right Eye (OD)</div>
                        <div class="rx-vals">
                            <div class="rx-val">
                                <div class="rvl">SPH</div>
                                <div class="rvn"><?php echo $prescription['od_sph'] ?: '—'; ?></div>
                            </div>
                            <div class="rx-val">
                                <div class="rvl">CYL</div>
                                <div class="rvn"><?php echo $prescription['od_cyl'] ?: '—'; ?></div>
                            </div>
                            <div class="rx-val">
                                <div class="rvl">AXIS</div>
                                <div class="rvn"><?php echo $prescription['od_axis'] ?: '—'; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="rx-eye">
                        <div class="rx-eye-label"><i class="fas fa-eye"></i> Left Eye (OS)</div>
                        <div class="rx-vals">
                            <div class="rx-val">
                                <div class="rvl">SPH</div>
                                <div class="rvn"><?php echo $prescription['os_sph'] ?: '—'; ?></div>
                            </div>
                            <div class="rx-val">
                                <div class="rvl">CYL</div>
                                <div class="rvn"><?php echo $prescription['os_cyl'] ?: '—'; ?></div>
                            </div>
                            <div class="rx-val">
                                <div class="rvl">AXIS</div>
                                <div class="rvn"><?php echo $prescription['os_axis'] ?: '—'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="rx-verify">
                    <i class="fas fa-shield-alt"></i>
                    The optometrist will verify your prescription when you visit.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== WHAT HAPPENS NEXT ===== -->
    <div class="card">
        <div class="card-head">
            <i class="fas fa-list-check"></i>
            <h3>What Happens Next</h3>
        </div>
        <div class="card-body">
            <div class="next-steps">
                <?php if ($res['payment_status'] === 'partial'): ?>
                <div class="next-step">
                    <div class="step-num">1</div>
                    <div class="step-content">
                        <div class="sc-title">Clinic Verifies Your Payment</div>
                        <div class="sc-desc">The clinic will review your GCash screenshot and reference number within 24 hours.</div>
                    </div>
                </div>
                <div class="next-step">
                    <div class="step-num">2</div>
                    <div class="step-content">
                        <div class="sc-title">You'll Receive a Confirmation</div>
                        <div class="sc-desc">Once verified, you'll get a notification confirming your reservation slot.</div>
                    </div>
                </div>
                <div class="next-step">
                    <div class="step-num">3</div>
                    <div class="step-content">
                        <div class="sc-title">Visit the Clinic</div>
                        <div class="sc-desc">
                            Go to <strong><?php echo htmlspecialchars($res['clinic_name']); ?></strong> on
                            <strong><?php echo date('F j, Y', strtotime($res['preferred_date'])); ?></strong>
                            at <strong><?php echo date('g:i A', strtotime($res['preferred_time'])); ?></strong>.
                            Bring your reservation code: <strong style="color:var(--primary); font-family:monospace;"><?php echo $res['reservation_code']; ?></strong>
                        </div>
                    </div>
                </div>
                <?php if ($prescription): ?>
                <div class="next-step">
                    <div class="step-num">4</div>
                    <div class="step-content">
                        <div class="sc-title">Prescription Verification</div>
                        <div class="sc-desc">The optometrist will verify your submitted prescription. Minor adjustments may be made for accuracy.</div>
                    </div>
                </div>
                <div class="next-step">
                    <div class="step-num">5</div>
                    <div class="step-content">
                        <div class="sc-title">Pay the Remaining Balance</div>
                        <div class="sc-desc">Settle the balance of <strong>₱<?php echo number_format($res['balance_amount'], 2); ?></strong> at the clinic to complete your purchase.</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="next-step">
                    <div class="step-num">4</div>
                    <div class="step-content">
                        <div class="sc-title">Pay the Remaining Balance</div>
                        <div class="sc-desc">Settle the balance of <strong>₱<?php echo number_format($res['balance_amount'], 2); ?></strong> at the clinic to complete your purchase.</div>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="next-step">
                    <div class="step-num">1</div>
                    <div class="step-content">
                        <div class="sc-title">Clinic Reviews Your Reservation</div>
                        <div class="sc-desc">The clinic will review and confirm your preferred schedule.</div>
                    </div>
                </div>
                <div class="next-step">
                    <div class="step-num">2</div>
                    <div class="step-content">
                        <div class="sc-title">Visit & Pay the Downpayment</div>
                        <div class="sc-desc">
                            Go to <strong><?php echo htmlspecialchars($res['clinic_name']); ?></strong> and pay
                            <strong>₱<?php echo number_format($res['downpayment_amount'], 2); ?></strong> to secure your reservation.
                        </div>
                    </div>
                </div>
                <div class="next-step">
                    <div class="step-num">3</div>
                    <div class="step-content">
                        <div class="sc-title">Pay the Remaining Balance</div>
                        <div class="sc-desc">Settle the remaining <strong>₱<?php echo number_format($res['balance_amount'], 2); ?></strong> when you pick up your order.</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== CLINIC INFO ===== -->
    <div class="card">
        <div class="card-head">
            <i class="fas fa-store-alt"></i>
            <h3>Clinic Details</h3>
        </div>
        <div class="card-body">
            <div class="clinic-strip">
                <div class="clinic-logo-box">
                    <?php if (!empty($res['clinic_logo'])): ?>
                        <img src="/eyecore/assets/images/clinic-logos/<?php echo $res['clinic_logo']; ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-store-alt"></i>
                    <?php endif; ?>
                </div>
                <div class="clinic-info">
                    <div class="ci-name"><?php echo htmlspecialchars($res['clinic_name']); ?></div>
                    <div class="ci-addr">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($res['clinic_address'] . ', ' . $res['clinic_city']); ?>
                    </div>
                    <?php if (!empty($res['clinic_hours'])): ?>
                    <div class="ci-hours">
                        <i class="fas fa-clock"></i>
                        <?php echo htmlspecialchars($res['clinic_hours']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($res['clinic_contact'])): ?>
            <div style="margin-top: 14px;">
                <a href="tel:<?php echo $res['clinic_contact']; ?>"
                   style="display:inline-flex; align-items:center; gap:7px; padding:10px 18px; background:var(--primary-light); color:var(--primary); border-radius:var(--radius-full); font-size:13px; font-weight:700; text-decoration:none;">
                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($res['clinic_contact']); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== ACTION BUTTONS ===== -->
    <div class="action-buttons">
        <a href="my-reservations.php" class="btn-action primary">
            <i class="fas fa-bookmark"></i> View My Reservations
        </a>
        <a href="dashboard.php" class="btn-action secondary">
            <i class="fas fa-store"></i> Browse More
        </a>
    </div>

</div><!-- /main-content -->

<script>
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast('Reservation code copied!', 'success');
    }).catch(() => {
        // Fallback
        const el = document.createElement('textarea');
        el.value = code;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        showToast('Reservation code copied!', 'success');
    });
}

function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<i class="fas fa-check-circle"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
}
</script>
</body>
</html>