<?php
include '../includes/config.php';
include '../includes/theme.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id      = $_SESSION['user_id'];
$clinic_id    = isset($_GET['clinic'])      ? (int)$_GET['clinic']      : 0;
$appointment_id = isset($_GET['appointment']) ? (int)$_GET['appointment'] : 0;

if (!$clinic_id || !$appointment_id) {
    header('Location: my-appointments.php');
    exit();
}

// Verify appointment belongs to user and is paid/completed
$apt_query = mysqli_query($conn, "
    SELECT a.*,
           c.clinic_name,
           c.address,
           c.logo,
           c.clinic_image,
           c.cover_photo,
           p.name as product_name,
           p.category as product_category
    FROM appointments a
    JOIN clinics c ON a.clinic_id = c.id
    LEFT JOIN products p ON a.product_id = p.id
    WHERE a.id = $appointment_id
      AND a.user_id = $user_id
      AND a.clinic_id = $clinic_id
");

$appointment = mysqli_fetch_assoc($apt_query);

if (!$appointment) {
    header('Location: my-appointments.php');
    exit();
}

// Check if user already reviewed this appointment
$existing_review = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM clinic_reviews
     WHERE user_id = $user_id AND clinic_id = $clinic_id AND appointment_id = $appointment_id
     LIMIT 1"
));

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $review = mysqli_real_escape_string($conn, trim($_POST['review'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        $error_message = 'Please select a rating from 1 to 5 stars.';
    } elseif (empty($review)) {
        $error_message = 'Please write your review before submitting.';
    } elseif (strlen($review) < 10) {
        $error_message = 'Your review is too short. Please write at least 10 characters.';
    } elseif ($existing_review) {
        $error_message = 'You have already submitted a review for this appointment.';
    } else {
        $insert = mysqli_query($conn, "
            INSERT INTO clinic_reviews (clinic_id, user_id, appointment_id, rating, review, created_at)
            VALUES ($clinic_id, $user_id, $appointment_id, $rating, '$review', NOW())
        ");

        if ($insert) {
            // Add notification
            if (function_exists('addNotification')) {
                addNotification(
                    $user_id,
                    'appointment',
                    'Review Submitted',
                    "Your review for {$appointment['clinic_name']} has been submitted. Thank you!",
                    'my-appointments.php'
                );
            }
            $success_message = 'Your review has been submitted successfully!';
            $existing_review = ['id' => mysqli_insert_id($conn)]; // mark as submitted
        } else {
            $error_message = 'Something went wrong. Please try again.';
        }
    }
}

// ============================================
// NAVBAR DATA
// ============================================
$user_query  = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user        = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data   = mysqli_fetch_assoc($avatar_query);

$pending_q   = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending     = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

$unread_count       = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user_id) : 0;
$recent_notifications = function_exists('getRecentNotifications')   ? getRecentNotifications($user_id)     : [];

$total_bookings_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings   = mysqli_fetch_assoc($total_bookings_q)['total'] ?? 0;

$points_row   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points = $points_row['t'] ?: 0;

// Clinic rating
$rating_data    = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(AVG(rating),0) as avg_rating, COUNT(*) as total FROM clinic_reviews WHERE clinic_id = $clinic_id"
));
$clinic_avg_rating    = round($rating_data['avg_rating'], 1);
$clinic_total_reviews = $rating_data['total'];

// Helper — theme
if (!function_exists('getThemeClass')) {
    function getThemeClass() {
        return isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'theme-dark' : '';
    }
}

// Helper — clinic image
if (!function_exists('getClinicImg')) {
    function getClinicImg($c) {
        if (!empty($c['cover_photo']))  return '/eyecore/assets/images/clinic-covers/'  . $c['cover_photo'];
        if (!empty($c['clinic_image'])) return '/eyecore/assets/images/clinic-images/'  . $c['clinic_image'];
        if (!empty($c['logo']))         return '/eyecore/assets/images/clinic-logos/'   . $c['logo'];
        return null;
    }
}

// Helper — timeAgo
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $diff = time() - strtotime($timestamp);
        if ($diff < 60)       return 'Just Now';
        if ($diff < 3600)     return round($diff/60)   . ' minutes ago';
        if ($diff < 86400)    return round($diff/3600)  . ' hours ago';
        if ($diff < 604800)   return round($diff/86400) . ' days ago';
        return date('M j, Y', strtotime($timestamp));
    }
}

// Helper — stars
if (!function_exists('renderStarRating')) {
    function renderStarRating($rating) {
        $full  = floor($rating);
        $half  = ($rating - $full) >= 0.5;
        $empty = 5 - $full - ($half ? 1 : 0);
        $html  = str_repeat('<i class="fas fa-star"></i>', $full);
        if ($half)  $html .= '<i class="fas fa-star-half-alt"></i>';
        $html .= str_repeat('<i class="far fa-star"></i>', $empty);
        return $html;
    }
}

// Helper — notifications
if (!function_exists('getNotificationIcon')) {
    function getNotificationIcon($t) {
        return ['appointment'=>'fa-calendar-check','favorite'=>'fa-heart','promo'=>'fa-tags'][$t] ?? 'fa-bell';
    }
}

$active_nav = 'appointments';
include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Write a Review - <?php echo htmlspecialchars($appointment['clinic_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        /* ===== TOAST ===== */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-notification {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-secondary);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-md);
            padding: 15px 20px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 10px;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
        }

        .toast-notification.success { border-left-color: var(--primary); }
        .toast-notification.error   { border-left-color: var(--danger); }
        .toast-notification.info    { border-left-color: var(--info); }
        .toast-notification.success i { color: var(--primary); }
        .toast-notification.error   i { color: var(--danger); }
        .toast-notification.info    i { color: var(--info); }
        .toast-notification i   { font-size: 20px; }
        .toast-notification span { flex: 1; font-size: 14px; color: var(--text-primary); }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to   { opacity: 0; }
        }

        /* ===== LAYOUT ===== */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 40px;
        }

        @media (max-width: 1024px) { .main-content { padding: 24px; } }
        @media (max-width: 768px)  { .main-content { padding: 18px 16px 100px; } }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 24px;
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* ===== MAIN GRID ===== */
        .review-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 25px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .review-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== CARDS ===== */
        .card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-light);
        }

        .card-header i {
            width: 40px;
            height: 40px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-body {
            padding: 25px;
        }

        /* ===== CLINIC INFO SIDEBAR ===== */
        .clinic-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .clinic-avatar {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            flex-shrink: 0;
        }

        .clinic-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .clinic-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .clinic-rating-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .stars-display {
            display: inline-flex;
            gap: 2px;
            color: #FFC107;
            font-size: 12px;
        }

        .rating-value {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
        }

        .reviews-count {
            font-size: 12px;
            color: var(--text-muted);
        }

        .no-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Clinic detail rows */
        .clinic-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .clinic-detail-item:last-child { margin-bottom: 0; }

        .clinic-detail-item i {
            width: 20px;
            color: var(--primary);
            font-size: 14px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .clinic-detail-item div { flex: 1; }

        .clinic-detail-item strong {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .clinic-detail-item span {
            font-size: 14px;
            color: var(--text-primary);
        }

        /* Appointment ref card */
        .apt-ref-card {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            border: 1px solid var(--border-light);
            margin-top: 20px;
        }

        .apt-ref-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .apt-ref-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .apt-ref-row:last-child { margin-bottom: 0; }

        .apt-ref-row i {
            color: var(--primary);
            font-size: 13px;
            width: 16px;
        }

        .apt-ref-row span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .apt-ref-row strong {
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 600;
        }

        /* ===== REVIEW FORM ===== */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .alert-error   { background: #FFF0F0; color: var(--danger);  border: 1px solid #FFD5D5; }
        .alert-success { background: #F0FFF6; color: #1a7a3c;        border: 1px solid #b3f0cc; }
        .alert-info    { background: var(--primary-light); color: var(--primary-dark); border: 1px solid #b3f0cc; }

        /* Star rating picker */
        .star-picker-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: block;
        }

        .star-picker {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }

        .star-picker input[type="radio"] {
            display: none;
        }

        .star-picker label {
            font-size: 36px;
            color: var(--border-color);
            cursor: pointer;
            transition: color 0.15s, transform 0.15s;
            line-height: 1;
        }

        /* Highlight hovered and all previous stars */
        .star-picker label:hover,
        .star-picker label:hover ~ label,
        .star-picker input[type="radio"]:checked ~ label {
            color: #FFC107;
        }

        .star-picker label:hover {
            transform: scale(1.15);
        }

        .star-rating-text {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: -18px;
            margin-bottom: 24px;
            min-height: 18px;
            transition: all 0.2s;
        }

        /* Textarea */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .form-label span {
            font-size: 12px;
            font-weight: 400;
            color: var(--text-muted);
            margin-left: 6px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg-primary);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            color: var(--text-primary);
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            resize: vertical;
            min-height: 140px;
        }

        .form-control::placeholder { color: var(--text-muted); }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 183, 97, 0.1);
        }

        .char-count {
            text-align: right;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            transition: color 0.2s;
        }

        .char-count.warn  { color: var(--warning); }
        .char-count.limit { color: var(--danger); }

        /* Tip chips */
        .tip-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }

        .tip-chip {
            padding: 6px 14px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .tip-chip:hover,
        .tip-chip.active {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Submit button */
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            font-family: inherit;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.35);
        }

        .btn-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== ALREADY REVIEWED STATE ===== */
        .already-reviewed {
            text-align: center;
            padding: 40px 20px;
        }

        .already-reviewed i {
            font-size: 56px;
            color: #FFC107;
            margin-bottom: 16px;
            display: block;
        }

        .already-reviewed h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .already-reviewed p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .btn-back-apt {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-back-apt:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.3);
        }

        /* ===== SUCCESS STATE ===== */
        .success-state {
            text-align: center;
            padding: 50px 20px;
        }

        .success-icon-wrap {
            width: 80px;
            height: 80px;
            background: var(--primary-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .success-icon-wrap i {
            font-size: 36px;
            color: var(--primary);
        }

        @keyframes popIn {
            from { transform: scale(0); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }

        .success-state h3 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .success-state p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 28px;
        }

        .success-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary-solid {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary-solid:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.3);
        }

        .btn-outline-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: transparent;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-outline-pill:hover {
            background: var(--primary);
            color: white;
        }

        /* ===== LOADING OVERLAY ===== */
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<div class="toast-container" id="toastContainer"></div>

<div class="main-content">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-pen"></i>
            <h1>Write a Review</h1>
        </div>
        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Appointment
        </a>
    </div>

    <div class="review-grid">

        <!-- LEFT: Review Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-star"></i>
                <h2>Share Your Experience</h2>
            </div>
            <div class="card-body">

                <?php if ($success_message): ?>
                <!-- ===== SUCCESS STATE ===== -->
                <div class="success-state">
                    <div class="success-icon-wrap">
                        <i class="fas fa-check"></i>
                    </div>
                    <h3>Review Submitted!</h3>
                    <p>Thank you for sharing your experience at <strong><?php echo htmlspecialchars($appointment['clinic_name']); ?></strong>. Your feedback helps others make better decisions.</p>
                    <div class="success-actions">
                        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="btn-primary-solid">
                            <i class="fas fa-calendar-check"></i> View Appointment
                        </a>
                        <a href="my-appointments.php" class="btn-outline-pill">
                            <i class="fas fa-list"></i> All Appointments
                        </a>
                    </div>
                </div>

                <?php elseif ($existing_review && !$success_message): ?>
                <!-- ===== ALREADY REVIEWED STATE ===== -->
                <div class="already-reviewed">
                    <i class="fas fa-star"></i>
                    <h3>Already Reviewed</h3>
                    <p>You've already submitted a review for this appointment. Thank you for your feedback!</p>
                    <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="btn-back-apt">
                        <i class="fas fa-arrow-left"></i> Back to Appointment
                    </a>
                </div>

                <?php else: ?>
                <!-- ===== REVIEW FORM ===== -->

                <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="reviewForm">

                    <!-- Star Rating -->
                    <span class="star-picker-label">Your Rating <span style="color:var(--danger)">*</span></span>
                    <div class="star-picker" id="starPicker">
                        <input type="radio" name="rating" id="star5" value="5">
                        <label for="star5" title="5 - Excellent"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star4" value="4">
                        <label for="star4" title="4 - Very Good"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star3" value="3">
                        <label for="star3" title="3 - Good"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star2" value="2">
                        <label for="star2" title="2 - Fair"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star1" value="1">
                        <label for="star1" title="1 - Poor"><i class="fas fa-star"></i></label>
                    </div>
                    <div class="star-rating-text" id="ratingText">Click a star to rate</div>

                    <!-- Quick Tips -->
                    <div class="form-group">
                        <label class="form-label">Quick Phrases <span>tap to add</span></label>
                        <div class="tip-chips">
                            <div class="tip-chip" onclick="addTip('Great service!')">Great service!</div>
                            <div class="tip-chip" onclick="addTip('Very professional staff.')">Professional staff</div>
                            <div class="tip-chip" onclick="addTip('Clean and comfortable clinic.')">Clean clinic</div>
                            <div class="tip-chip" onclick="addTip('Highly recommended!')">Highly recommended</div>
                            <div class="tip-chip" onclick="addTip('Fast and efficient.')">Fast & efficient</div>
                            <div class="tip-chip" onclick="addTip('Friendly doctors.')">Friendly doctors</div>
                            <div class="tip-chip" onclick="addTip('Good value for money.')">Good value</div>
                            <div class="tip-chip" onclick="addTip('Will visit again.')">Will visit again</div>
                        </div>
                    </div>

                    <!-- Review Text -->
                    <div class="form-group">
                        <label class="form-label" for="review">Your Review <span>minimum 10 characters</span></label>
                        <textarea
                            name="review"
                            id="review"
                            class="form-control"
                            maxlength="1000"
                            placeholder="Share your experience — how was the service, the staff, the clinic environment? Your honest feedback helps others."
                            oninput="updateCharCount(this)"
                        ><?php echo isset($_POST['review']) ? htmlspecialchars($_POST['review']) : ''; ?></textarea>
                        <div class="char-count" id="charCount">0 / 1000</div>
                    </div>

                    <button type="submit" name="submit_review" class="btn-submit" id="submitBtn" disabled>
                        <i class="fas fa-paper-plane"></i> Submit Review
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>

        <!-- RIGHT: Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Clinic Info -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clinic-medical"></i>
                    <h2>Clinic Info</h2>
                </div>
                <div class="card-body">
                    <div class="clinic-header">
                        <?php $clinic_img = getClinicImg($appointment); ?>
                        <div class="clinic-avatar">
                            <?php if ($clinic_img): ?>
                                <img src="<?php echo htmlspecialchars($clinic_img); ?>"
                                     alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>"
                                     onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-eye\'></i>'">
                            <?php else: ?>
                                <i class="fas fa-eye"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="clinic-name"><?php echo htmlspecialchars($appointment['clinic_name']); ?></div>
                            <div class="clinic-rating-row">
                                <?php if ($clinic_total_reviews > 0): ?>
                                    <div class="stars-display"><?php echo renderStarRating($clinic_avg_rating); ?></div>
                                    <span class="rating-value"><?php echo $clinic_avg_rating; ?></span>
                                    <span class="reviews-count">(<?php echo $clinic_total_reviews; ?>)</span>
                                <?php else: ?>
                                    <div class="no-rating">
                                        <i class="far fa-star"></i> No reviews yet
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($appointment['address'])): ?>
                    <div class="clinic-detail-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <strong>Address</strong>
                            <span><?php echo htmlspecialchars($appointment['address']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($appointment['hours'])): ?>
                    <div class="clinic-detail-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Hours</strong>
                            <span><?php echo htmlspecialchars($appointment['hours']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Appointment Summary -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-check"></i>
                    <h2>Appointment Summary</h2>
                </div>
                <div class="card-body">
                    <div class="apt-ref-label">Details</div>

                    <?php if (!empty($appointment['ref_no'])): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-hashtag"></i>
                        <span>Ref:</span>
                        <strong><?php echo htmlspecialchars($appointment['ref_no']); ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="apt-ref-row">
                        <i class="fas fa-calendar"></i>
                        <span>Date:</span>
                        <strong><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></strong>
                    </div>

                    <div class="apt-ref-row">
                        <i class="fas fa-clock"></i>
                        <span>Time:</span>
                        <strong><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></strong>
                    </div>

                    <?php if (!empty($appointment['product_name'])): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-box"></i>
                        <span>Item:</span>
                        <strong><?php echo htmlspecialchars($appointment['product_name']); ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="apt-ref-row">
                        <i class="fas fa-info-circle"></i>
                        <span>Status:</span>
                        <strong style="color: var(--primary); text-transform: capitalize;">
                            <?php echo htmlspecialchars($appointment['status']); ?>
                        </strong>
                    </div>
                </div>
            </div>

            <!-- Review Tips -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-lightbulb"></i>
                    <h2>Tips for a Good Review</h2>
                </div>
                <div class="card-body" style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <i class="fas fa-check-circle" style="color: var(--primary); margin-top: 2px; font-size: 14px;"></i>
                        <span style="font-size: 13px; color: var(--text-secondary);">Be specific about your experience — what did you like or dislike?</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <i class="fas fa-check-circle" style="color: var(--primary); margin-top: 2px; font-size: 14px;"></i>
                        <span style="font-size: 13px; color: var(--text-secondary);">Mention the service quality, staff attitude, and clinic cleanliness.</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <i class="fas fa-check-circle" style="color: var(--primary); margin-top: 2px; font-size: 14px;"></i>
                        <span style="font-size: 13px; color: var(--text-secondary);">Keep it honest and respectful — your review helps the community.</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: flex-start;">
                        <i class="fas fa-check-circle" style="color: var(--primary); margin-top: 2px; font-size: 14px;"></i>
                        <span style="font-size: 13px; color: var(--text-secondary);">Reviews cannot be edited after submission, so review carefully.</span>
                    </div>
                </div>
            </div>

        </div><!-- end sidebar -->
    </div><!-- end grid -->
</div><!-- end main-content -->

<script>
    const ratingLabels = {
        1: '⭐ Poor — Not what I expected.',
        2: '⭐⭐ Fair — Could be better.',
        3: '⭐⭐⭐ Good — Decent experience.',
        4: '⭐⭐⭐⭐ Very Good — Happy with the service.',
        5: '⭐⭐⭐⭐⭐ Excellent — Highly recommended!'
    };

    let selectedRating = 0;

    // Star picker interactions
    document.querySelectorAll('.star-picker input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function () {
            selectedRating = parseInt(this.value);
            document.getElementById('ratingText').textContent = ratingLabels[selectedRating] || '';
            validateForm();
        });
    });

    // Quick tip chips
    function addTip(text) {
        const ta = document.getElementById('review');
        const current = ta.value.trim();
        ta.value = current ? current + ' ' + text : text;
        updateCharCount(ta);

        // Toggle active state
        document.querySelectorAll('.tip-chip').forEach(c => {
            if (c.textContent.trim() === text.trim() || c.getAttribute('onclick')?.includes(text)) {
                c.classList.toggle('active');
            }
        });
    }

    // Char count
    function updateCharCount(el) {
        const count = el.value.length;
        const display = document.getElementById('charCount');
        display.textContent = count + ' / 1000';
        display.className = 'char-count' + (count > 900 ? ' limit' : count > 700 ? ' warn' : '');
        validateForm();
    }

    // Validate — enable submit only when rating + min chars are filled
    function validateForm() {
        const review = document.getElementById('review').value.trim();
        const btn = document.getElementById('submitBtn');
        if (!btn) return;
        btn.disabled = !(selectedRating > 0 && review.length >= 10);
    }

    // Loading overlay on submit
    document.getElementById('reviewForm')?.addEventListener('submit', function (e) {
        const review = document.getElementById('review').value.trim();
        if (selectedRating < 1) {
            e.preventDefault();
            showToast('Please select a star rating.', 'error');
            return;
        }
        if (review.length < 10) {
            e.preventDefault();
            showToast('Please write at least 10 characters in your review.', 'error');
            return;
        }
        document.getElementById('loadingOverlay').classList.add('show');
    });

    // Toast
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type}`;
        const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
        toast.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // Init char count on load (in case of post-back)
    const reviewEl = document.getElementById('review');
    if (reviewEl) updateCharCount(reviewEl);
</script>

</body>
</html>