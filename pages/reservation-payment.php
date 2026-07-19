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
// GET RESERVATION + PRODUCT + CLINIC DETAILS
// ============================================
$res_query = mysqli_query($conn, "
    SELECT r.*,
           p.name as product_name,
           p.price as product_price,
           p.category as product_category,
           p.images_json, p.images, p.image,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.gcash_number,
           c.gcash_qr,
           c.payment_method_online,
           c.downpayment_percentage
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

// Already paid? redirect
if ($res['payment_status'] === 'paid') {
    header('Location: reservation-success.php?id=' . $reservation_id);
    exit();
}

// Get prescription if any
$prescription = null;
if ($res['prescription_id']) {
    $prescription = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM user_prescriptions WHERE id = {$res['prescription_id']}"
    ));
}

// Get product image
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
        $p = str_replace('uploads/uploads/', 'uploads/', $d);
        return strpos($p, 'uploads/') === 0 ? '/eyecore/' . $p : '/eyecore/uploads/products/' . $p;
    }
    if (!empty($product['image'])) {
        return '/eyecore/assets/images/products/' . $product['image'];
    }
    return '/eyecore/assets/img/no-image.png';
}
$product_img = getFirstImage($res);

// Lens type labels
$lens_labels = [
    'frame_only'      => 'Frame Only (No Lenses)',
    'single_vision'   => 'Single Vision Lenses',
    'progressive'     => 'Progressive Lenses',
    'blue_cut'        => 'Blue Cut Lenses',
    'contact_daily'   => 'Daily Disposable Contact Lens',
    'contact_monthly' => 'Monthly Wear Contact Lens',
];
$lens_label = $lens_labels[$res['lens_type']] ?? $res['lens_type'];

// Expiry countdown
$expires_at = strtotime($res['expires_at']);
$now = time();
$time_left = $expires_at - $now;
$is_expired = $time_left <= 0;

// ============================================
// HANDLE PAYMENT SUBMISSION (AJAX)
// ============================================
if (isset($_POST['ajax_submit_payment'])) {
    header('Content-Type: application/json');

    if ($is_expired) {
        // Auto-cancel expired reservation
        mysqli_query($conn, "UPDATE reservations SET status='cancelled' WHERE id=$reservation_id");
        echo json_encode(['success' => false, 'message' => 'Your reservation has expired. Please make a new one.']);
        exit();
    }

    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? '');
    $reference_number = mysqli_real_escape_string($conn, $_POST['reference_number'] ?? '');

    if (!$payment_method) {
        echo json_encode(['success' => false, 'message' => 'Please select a payment method.']);
        exit();
    }

    if ($payment_method === 'gcash' && !$reference_number) {
        echo json_encode(['success' => false, 'message' => 'Please enter your GCash reference number.']);
        exit();
    }

    // Handle screenshot upload
    $screenshot_path = null;
    if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === 0) {
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['payment_screenshot']['type'];
        $file_size = $_FILES['payment_screenshot']['size'];

        if (!in_array($file_type, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Only image files are allowed (JPG, PNG, GIF, WEBP).']);
            exit();
        }
        if ($file_size > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Screenshot must be under 5MB.']);
            exit();
        }

        $upload_dir = '../uploads/payment-proofs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $ext = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
        $filename = 'pay_' . $reservation_id . '_' . time() . '.' . $ext;
        $dest = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $dest)) {
            $screenshot_path = $filename;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload screenshot. Please try again.']);
            exit();
        }
    }

    // Update reservation
    $screenshot_sql = $screenshot_path ? ", payment_screenshot='$screenshot_path'" : '';
    mysqli_query($conn, "
        UPDATE reservations SET
            payment_status = 'partial',
            payment_reference = '$reference_number'
            $screenshot_sql,
            status = 'confirmed'
        WHERE id = $reservation_id AND user_id = $user_id
    ");

    // Notify user
    addNotification($user_id, 'payment', 'Payment Proof Submitted',
        "Your downpayment proof for {$res['product_name']} has been submitted. Waiting for clinic verification.",
        'my-reservations.php'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Payment proof submitted! Redirecting...',
        'redirect' => 'reservation-success.php?id=' . $reservation_id
    ]);
    exit();
}

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
    <title>Complete Payment — Eyecore</title>
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
        max-width: 960px;
        margin: 0 auto;
        padding: 28px 20px 80px;
    }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* Breadcrumb */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 24px;
        font-size: 13px;
        color: var(--text-muted);
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

    /* Page title */
    .page-title {
        font-family: var(--font-display);
        font-size: 28px;
        color: var(--text-primary);
        margin-bottom: 6px;
    }
    .page-subtitle {
        font-size: 14px;
        color: var(--text-secondary);
        margin-bottom: 28px;
    }

    /* Expiry banner */
    .expiry-banner {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-radius: var(--radius-md);
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 500;
    }
    .expiry-banner.urgent { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .expiry-banner.expired { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
    .expiry-banner i { font-size: 18px; flex-shrink: 0; }
    #countdownTimer { font-weight: 700; font-size: 15px; font-variant-numeric: tabular-nums; }

    /* Two column layout */
    .payment-grid {
        display: grid;
        grid-template-columns: 1fr 1.3fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 768px) { .payment-grid { grid-template-columns: 1fr; } }

    /* Cards */
    .card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 20px;
    }
    .card-head {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card-head h3 {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
    }
    .card-head i { color: var(--primary); font-size: 16px; }
    .card-body { padding: 20px; }

    /* Product summary */
    .product-summary {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        margin-bottom: 18px;
        padding-bottom: 18px;
        border-bottom: 1px solid var(--border-light);
    }
    .product-thumb {
        width: 72px;
        height: 72px;
        border-radius: var(--radius-sm);
        overflow: hidden;
        border: 1px solid var(--border-light);
        flex-shrink: 0;
        background: var(--bg-primary);
    }
    .product-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .product-details .pd-category {
        font-size: 11px;
        font-weight: 600;
        color: var(--primary);
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .product-details .pd-name {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
        line-height: 1.3;
    }
    .product-details .pd-lens {
        font-size: 12px;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* Info rows */
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 9px 0;
        border-bottom: 1px solid var(--border-light);
        font-size: 13px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .ir-label { color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
    .info-row .ir-label i { color: var(--primary); width: 14px; text-align: center; }
    .info-row .ir-value { font-weight: 600; color: var(--text-primary); }

    /* Price breakdown */
    .price-breakdown { margin-top: 4px; }
    .pb-row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        padding: 7px 0;
        color: var(--text-secondary);
        border-bottom: 1px dashed var(--border-light);
    }
    .pb-row:last-child { border-bottom: none; }
    .pb-row.total {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        padding-top: 10px;
        border-top: 2px solid var(--border-color);
        border-bottom: none;
        margin-top: 4px;
    }
    .pb-row.dp {
        background: #FEF3C7;
        padding: 10px 12px;
        border-radius: 10px;
        margin-top: 8px;
        color: #92400E;
        font-weight: 700;
        font-size: 14px;
        border-bottom: none;
    }
    .theme-dark .pb-row.dp { background: #2D1F00; color: #FCD34D; }
    .pb-row.balance { color: var(--text-muted); font-size: 12px; }

    /* Prescription display */
    .rx-display {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 14px;
        margin-top: 4px;
    }
    .rx-display-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
    }
    .rx-eyes-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .rx-eye { background: var(--bg-secondary); border-radius: 10px; padding: 10px; border: 1px solid var(--border-light); }
    .rx-eye-title { font-size: 11px; font-weight: 700; color: var(--primary); margin-bottom: 6px; }
    .rx-values { display: flex; gap: 8px; }
    .rx-val { flex: 1; text-align: center; }
    .rx-val .rv-label { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
    .rx-val .rv-num { font-size: 13px; font-weight: 700; color: var(--text-primary); }
    .rx-verify-note {
        font-size: 11px;
        color: var(--text-muted);
        text-align: center;
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }
    .rx-verify-note i { color: var(--primary); }

    /* Payment method selector */
    .payment-methods {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    .pm-option {
        flex: 1;
        min-width: 120px;
        padding: 14px 12px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }
    .pm-option:hover { border-color: var(--primary); }
    .pm-option.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
    }
    .pm-option .pm-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }
    .pm-option.gcash .pm-icon { background: #0070BA; color: white; }
    .pm-option.onsite .pm-icon { background: var(--primary-light); color: var(--primary); font-size: 18px; }
    .pm-option .pm-name { font-size: 13px; font-weight: 700; color: var(--text-primary); }
    .pm-option .pm-desc { font-size: 11px; color: var(--text-muted); }

    /* GCash details section */
    .gcash-section { display: none; }
    .gcash-section.show { display: block; }

    .gcash-info-box {
        background: #EFF6FF;
        border: 1px solid #BFDBFE;
        border-radius: var(--radius-md);
        padding: 16px;
        margin-bottom: 16px;
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }
    .theme-dark .gcash-info-box { background: #1E2A4A; border-color: #2D4A8A; }
    .gcash-number-badge {
        background: #0070BA;
        color: white;
        padding: 10px 16px;
        border-radius: var(--radius-md);
        font-size: 18px;
        font-weight: 700;
        letter-spacing: 1px;
        text-align: center;
        flex-shrink: 0;
    }
    .gcash-instructions { flex: 1; }
    .gcash-instructions strong {
        font-size: 13px;
        color: var(--text-primary);
        display: block;
        margin-bottom: 6px;
    }
    .gcash-instructions ol {
        padding-left: 16px;
        font-size: 12px;
        color: var(--text-secondary);
        line-height: 1.8;
    }

    .gcash-qr-box {
        text-align: center;
        margin-bottom: 16px;
    }
    .gcash-qr-box img {
        max-width: 180px;
        border-radius: var(--radius-md);
        border: 3px solid #0070BA;
        padding: 8px;
        background: white;
    }
    .gcash-qr-box p {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 6px;
    }

    .amount-to-pay {
        background: var(--primary-light);
        border: 1px solid rgba(0,183,97,0.2);
        border-radius: var(--radius-md);
        padding: 14px;
        text-align: center;
        margin-bottom: 16px;
    }
    .amount-to-pay .atp-label { font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; }
    .amount-to-pay .atp-amount { font-family: var(--font-display); font-size: 32px; color: var(--primary); font-weight: 700; }

    /* Onsite section */
    .onsite-section { display: none; }
    .onsite-section.show { display: block; }
    .onsite-info {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 18px;
        text-align: center;
    }
    .onsite-info i { font-size: 36px; color: var(--primary); margin-bottom: 10px; display: block; }
    .onsite-info h4 { font-size: 15px; font-weight: 700; margin-bottom: 6px; color: var(--text-primary); }
    .onsite-info p { font-size: 13px; color: var(--text-secondary); line-height: 1.6; }

    /* Form elements */
    .form-group { margin-bottom: 16px; }
    .form-label {
        font-size: 12px;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 7px;
        display: block;
    }
    .form-input {
        width: 100%;
        padding: 12px 14px;
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 14px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-family: var(--font-main);
        transition: border-color 0.2s;
    }
    .form-input:focus { outline: none; border-color: var(--primary); }

    /* Upload area */
    .upload-area {
        border: 2px dashed var(--border-color);
        border-radius: var(--radius-md);
        padding: 24px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        background: var(--bg-primary);
        position: relative;
    }
    .upload-area:hover, .upload-area.dragover {
        border-color: var(--primary);
        background: var(--primary-light);
    }
    .upload-area input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
    }
    .upload-area i { font-size: 28px; color: var(--text-muted); margin-bottom: 8px; display: block; }
    .upload-area .ua-title { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
    .upload-area .ua-sub { font-size: 12px; color: var(--text-muted); }
    .upload-preview {
        margin-top: 12px;
        display: none;
    }
    .upload-preview img {
        max-width: 100%;
        max-height: 200px;
        border-radius: var(--radius-sm);
        border: 2px solid var(--primary);
        object-fit: contain;
    }
    .upload-preview .up-remove {
        display: block;
        margin-top: 6px;
        font-size: 12px;
        color: var(--danger);
        cursor: pointer;
        background: none;
        border: none;
        font-family: var(--font-main);
    }

    /* Submit button */
    .btn-submit {
        width: 100%;
        padding: 16px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 16px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.2s;
        box-shadow: 0 4px 16px rgba(0,183,97,0.3);
        margin-top: 8px;
    }
    .btn-submit:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,183,97,0.4);
    }
    .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

    .btn-submit.onsite-style {
        background: linear-gradient(135deg, #F59E0B, #D97706);
        box-shadow: 0 4px 16px rgba(245,158,11,0.3);
    }

    .submit-note {
        font-size: 12px;
        color: var(--text-muted);
        text-align: center;
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }

    /* Expired overlay */
    .expired-overlay {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 40px 24px;
        text-align: center;
        border: 1px solid var(--border-light);
    }
    .expired-overlay i { font-size: 48px; color: var(--danger); margin-bottom: 16px; display: block; }
    .expired-overlay h3 { font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--text-primary); }
    .expired-overlay p { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }
    .btn-new-reservation {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background: var(--primary-gradient);
        color: white;
        border-radius: var(--radius-md);
        text-decoration: none;
        font-weight: 700;
        font-size: 14px;
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
        min-width: 300px;
        animation: toastIn 0.3s ease;
        border-left: 4px solid var(--success);
    }
    .toast.error { border-left-color: var(--danger); }
    .toast i { font-size: 18px; flex-shrink: 0; }
    .toast.success i { color: var(--success); }
    .toast.error i { color: var(--danger); }
    .toast span { font-size: 13px; color: var(--text-primary); flex: 1; }
    @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

    #loadingOverlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .spinner {
        width: 48px; height: 48px;
        border: 4px solid rgba(255,255,255,0.2);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hidden { display: none !important; }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div id="loadingOverlay" class="hidden"><div class="spinner"></div></div>

<div class="main-content">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="my-reservations.php"><i class="fas fa-arrow-left"></i> My Reservations</a>
    </div>

    <h1 class="page-title">Complete Your Payment</h1>
    <p class="page-subtitle">Submit your downpayment proof to confirm your reservation.</p>

    <?php if ($is_expired): ?>
    <!-- EXPIRED STATE -->
    <div class="expired-overlay">
        <i class="fas fa-clock"></i>
        <h3>Reservation Expired</h3>
        <p>Your reservation window has passed. Please make a new reservation to proceed.</p>
        <a href="product-view.php?id=<?php echo $res['product_id']; ?>" class="btn-new-reservation">
            <i class="fas fa-redo"></i> Make New Reservation
        </a>
    </div>

    <?php else: ?>

    <!-- EXPIRY COUNTDOWN -->
    <div class="expiry-banner urgent" id="expiryBanner">
        <i class="fas fa-hourglass-half"></i>
        <div>
            Reserve expires in: <span id="countdownTimer">--:--:--</span>
            — Complete payment before it expires.
        </div>
    </div>

    <div class="payment-grid">

        <!-- LEFT: Reservation Summary -->
        <div>
            <!-- Product Summary Card -->
            <div class="card">
                <div class="card-head">
                    <i class="fas fa-box"></i>
                    <h3>Reservation Summary</h3>
                </div>
                <div class="card-body">
                    <div class="product-summary">
                        <div class="product-thumb">
                            <img src="<?php echo $product_img; ?>" alt=""
                                 onerror="this.src='/eyecore/assets/img/no-image.png'">
                        </div>
                        <div class="product-details">
                            <div class="pd-category"><?php echo htmlspecialchars($res['product_category']); ?></div>
                            <div class="pd-name"><?php echo htmlspecialchars($res['product_name']); ?></div>
                            <div class="pd-lens">
                                <i class="fas fa-glasses" style="color:var(--primary)"></i>
                                <?php echo htmlspecialchars($lens_label); ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-row">
                        <span class="ir-label"><i class="fas fa-store"></i> Clinic</span>
                        <span class="ir-value"><?php echo htmlspecialchars($res['clinic_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="ir-label"><i class="fas fa-calendar"></i> Preferred Date</span>
                        <span class="ir-value"><?php echo date('F j, Y', strtotime($res['preferred_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="ir-label"><i class="fas fa-clock"></i> Preferred Time</span>
                        <span class="ir-value"><?php echo date('g:i A', strtotime($res['preferred_time'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="ir-label"><i class="fas fa-hashtag"></i> Reservation Code</span>
                        <span class="ir-value" style="color:var(--primary); font-family:monospace;">
                            <?php echo htmlspecialchars($res['reservation_code']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Price Breakdown Card -->
            <div class="card">
                <div class="card-head">
                    <i class="fas fa-receipt"></i>
                    <h3>Price Breakdown</h3>
                </div>
                <div class="card-body">
                    <div class="price-breakdown">
                        <div class="pb-row">
                            <span>Product Price</span>
                            <span>₱<?php echo number_format($res['product_price'], 2); ?></span>
                        </div>
                        <?php
                        $lens_add = $res['total_amount'] - $res['product_price'];
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
                            <span><i class="fas fa-bolt"></i> Downpayment Required (<?php echo $res['downpayment_percentage']; ?>%)</span>
                            <span>₱<?php echo number_format($res['downpayment_amount'], 2); ?></span>
                        </div>
                        <div class="pb-row balance">
                            <span>Balance (pay upon clinic visit)</span>
                            <span>₱<?php echo number_format($res['balance_amount'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prescription Card (if applicable) -->
            <?php if ($prescription && ($prescription['od_sph'] || $prescription['os_sph'])): ?>
            <div class="card">
                <div class="card-head">
                    <i class="fas fa-prescription"></i>
                    <h3>Your Prescription</h3>
                </div>
                <div class="card-body">
                    <div class="rx-display">
                        <div class="rx-display-title">Submitted Prescription</div>
                        <div class="rx-eyes-row">
                            <div class="rx-eye">
                                <div class="rx-eye-title">Right Eye (OD)</div>
                                <div class="rx-values">
                                    <div class="rx-val">
                                        <div class="rv-label">SPH</div>
                                        <div class="rv-num"><?php echo $prescription['od_sph'] ?: '—'; ?></div>
                                    </div>
                                    <div class="rx-val">
                                        <div class="rv-label">CYL</div>
                                        <div class="rv-num"><?php echo $prescription['od_cyl'] ?: '—'; ?></div>
                                    </div>
                                    <div class="rx-val">
                                        <div class="rv-label">AXIS</div>
                                        <div class="rv-num"><?php echo $prescription['od_axis'] ?: '—'; ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="rx-eye">
                                <div class="rx-eye-title">Left Eye (OS)</div>
                                <div class="rx-values">
                                    <div class="rx-val">
                                        <div class="rv-label">SPH</div>
                                        <div class="rv-num"><?php echo $prescription['os_sph'] ?: '—'; ?></div>
                                    </div>
                                    <div class="rx-val">
                                        <div class="rv-label">CYL</div>
                                        <div class="rv-num"><?php echo $prescription['os_cyl'] ?: '—'; ?></div>
                                    </div>
                                    <div class="rx-val">
                                        <div class="rv-label">AXIS</div>
                                        <div class="rv-num"><?php echo $prescription['os_axis'] ?: '—'; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="rx-verify-note">
                            <i class="fas fa-shield-alt"></i>
                            Will be verified by optometrist upon clinic visit.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Payment Form -->
        <div>
            <div class="card">
                <div class="card-head">
                    <i class="fas fa-credit-card"></i>
                    <h3>Payment Method</h3>
                </div>
                <div class="card-body">

                    <!-- Payment method selector -->
                    <div class="payment-methods">
                        <?php if ($res['payment_method_online'] || !empty($res['gcash_number'])): ?>
                        <div class="pm-option gcash selected" data-method="gcash" onclick="selectPayment(this)">
                            <div class="pm-icon"><i class="fas fa-mobile-alt"></i></div>
                            <div class="pm-name">GCash</div>
                            <div class="pm-desc">Online transfer</div>
                        </div>
                        <?php endif; ?>
                        <div class="pm-option onsite" data-method="onsite" onclick="selectPayment(this)">
                            <div class="pm-icon"><i class="fas fa-store"></i></div>
                            <div class="pm-name">Pay at Clinic</div>
                            <div class="pm-desc">Reserve slot, pay onsite</div>
                        </div>
                    </div>

                    <!-- GCash Section -->
                    <div class="gcash-section <?php echo ($res['payment_method_online'] || !empty($res['gcash_number'])) ? 'show' : ''; ?>" id="gcashSection">

                        <?php if (!empty($res['gcash_qr'])): ?>
                        <div class="gcash-qr-box">
                            <img src="/eyecore/uploads/gcash-qr/<?php echo $res['gcash_qr']; ?>" alt="GCash QR Code">
                            <p>Scan QR Code to pay</p>
                        </div>
                        <?php endif; ?>

                        <div class="amount-to-pay">
                            <div class="atp-label">Amount to send via GCash</div>
                            <div class="atp-amount">₱<?php echo number_format($res['downpayment_amount'], 2); ?></div>
                        </div>

                        <?php if (!empty($res['gcash_number'])): ?>
                        <div class="gcash-info-box">
                            <div>
                                <div class="gcash-number-badge">
                                    <?php echo htmlspecialchars($res['gcash_number']); ?>
                                </div>
                            </div>
                            <div class="gcash-instructions">
                                <strong>How to pay via GCash:</strong>
                                <ol>
                                    <li>Open your GCash app</li>
                                    <li>Tap <strong>Send Money</strong></li>
                                    <li>Enter the number above</li>
                                    <li>Send exactly <strong>₱<?php echo number_format($res['downpayment_amount'], 2); ?></strong></li>
                                    <li>Screenshot the confirmation</li>
                                    <li>Upload the screenshot below</li>
                                </ol>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">GCash Reference Number <span style="color:var(--danger)">*</span></label>
                            <input type="text" id="referenceNumber" class="form-input"
                                   placeholder="e.g. 1234567890123">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Screenshot <span style="color:var(--danger)">*</span></label>
                            <div class="upload-area" id="uploadArea">
                                <input type="file" id="screenshotInput" accept="image/*"
                                       onchange="handleFileSelect(event)">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <div class="ua-title">Upload GCash Screenshot</div>
                                <div class="ua-sub">JPG, PNG, or WEBP — max 5MB</div>
                            </div>
                            <div class="upload-preview" id="uploadPreview">
                                <img id="previewImg" src="" alt="Payment Screenshot">
                                <button class="up-remove" onclick="removeScreenshot()">
                                    <i class="fas fa-times"></i> Remove screenshot
                                </button>
                            </div>
                        </div>

                        <button class="btn-submit" id="gcashSubmitBtn" onclick="submitPayment('gcash')">
                            <i class="fas fa-paper-plane"></i> Submit Payment Proof
                        </button>
                        <p class="submit-note">
                            <i class="fas fa-shield-alt"></i>
                            Clinic will verify your payment within 24 hours.
                        </p>
                    </div>

                    <!-- Onsite Section -->
                    <div class="onsite-section" id="onsiteSection">
                        <div class="onsite-info">
                            <i class="fas fa-map-marker-alt"></i>
                            <h4>Pay When You Visit</h4>
                            <p>
                                You can confirm your reservation without paying online.
                                Visit <strong><?php echo htmlspecialchars($res['clinic_name']); ?></strong>
                                on your preferred date and pay the downpayment of
                                <strong>₱<?php echo number_format($res['downpayment_amount'], 2); ?></strong> onsite.
                            </p>
                            <br>
                            <p style="color:var(--warning); font-size:12px;">
                                <i class="fas fa-exclamation-triangle"></i>
                                Note: Your slot is not guaranteed until you pay. The clinic may confirm or adjust your schedule.
                            </p>
                        </div>

                        <button class="btn-submit onsite-style" style="margin-top:16px;" onclick="submitPayment('onsite')">
                            <i class="fas fa-check-circle"></i> Confirm Reservation (Pay Onsite)
                        </button>
                        <p class="submit-note">
                            <i class="fas fa-info-circle"></i>
                            Your reservation will be marked as pending until the clinic confirms.
                        </p>
                    </div>

                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /main-content -->

<script>
let selectedMethod = '<?php echo ($res['payment_method_online'] || !empty($res['gcash_number'])) ? 'gcash' : 'onsite'; ?>';
let selectedFile = null;
const expiresAt = <?php echo $expires_at * 1000; ?>;

// ============================================
// COUNTDOWN TIMER
// ============================================
function updateCountdown() {
    const now = Date.now();
    const diff = expiresAt - now;
    const timer = document.getElementById('countdownTimer');
    const banner = document.getElementById('expiryBanner');
    if (!timer) return;

    if (diff <= 0) {
        timer.textContent = 'EXPIRED';
        if (banner) {
            banner.className = 'expiry-banner expired';
            banner.innerHTML = '<i class="fas fa-times-circle"></i><div>Your reservation has expired. Please make a new one.</div>';
        }
        clearInterval(countdownInterval);
        return;
    }

    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    timer.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');

    // Turn red when under 30 minutes
    if (diff < 1800000 && banner) {
        banner.className = 'expiry-banner expired';
    }
}
const countdownInterval = setInterval(updateCountdown, 1000);
updateCountdown();

// ============================================
// PAYMENT METHOD TOGGLE
// ============================================
function selectPayment(el) {
    document.querySelectorAll('.pm-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    selectedMethod = el.dataset.method;

    document.getElementById('gcashSection').classList.toggle('show', selectedMethod === 'gcash');
    document.getElementById('onsiteSection').classList.toggle('show', selectedMethod === 'onsite');
}

// ============================================
// FILE UPLOAD
// ============================================
function handleFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        showToast('File must be under 5MB.', 'error');
        return;
    }

    selectedFile = file;
    const reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('previewImg').src = ev.target.result;
        document.getElementById('uploadPreview').style.display = 'block';
        document.getElementById('uploadArea').style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function removeScreenshot() {
    selectedFile = null;
    document.getElementById('screenshotInput').value = '';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadArea').style.display = 'block';
}

// Drag and drop
const uploadArea = document.getElementById('uploadArea');
if (uploadArea) {
    uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
    uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
    uploadArea.addEventListener('drop', e => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            const dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('screenshotInput').files = dt.files;
            handleFileSelect({ target: { files: [file] } });
        }
    });
}

// ============================================
// SUBMIT PAYMENT
// ============================================
function submitPayment(method) {
    if (method === 'gcash') {
        const ref = document.getElementById('referenceNumber').value.trim();
        if (!ref) { showToast('Please enter your GCash reference number.', 'error'); return; }
        if (!selectedFile) { showToast('Please upload your GCash screenshot.', 'error'); return; }
    }

    showLoading();

    const formData = new FormData();
    formData.append('ajax_submit_payment', '1');
    formData.append('payment_method', method);

    if (method === 'gcash') {
        formData.append('reference_number', document.getElementById('referenceNumber').value.trim());
        formData.append('payment_screenshot', selectedFile);
    }

    fetch('reservation-payment.php?id=<?php echo $reservation_id; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => { window.location.href = data.redirect; }, 1500);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(() => {
        hideLoading();
        showToast('Network error. Please try again.', 'error');
    });
}

// ============================================
// UTILITIES
// ============================================
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success: 'check-circle', error: 'exclamation-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 4000);
}

function showLoading() { document.getElementById('loadingOverlay').classList.remove('hidden'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.add('hidden'); }
</script>
</body>
</html>