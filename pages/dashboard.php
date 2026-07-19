<?php
include '../includes/config.php';
include '../includes/theme.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// User info
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Cities for filter
$cities = mysqli_query($conn, "SELECT DISTINCT city FROM clinics WHERE status='Active' AND city IS NOT NULL AND city!='' ORDER BY city");

// Pending appointments count
$apc_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($apc_q)['total'] ?? 0;

// All active clinics with ratings + 3D check
$clinics = mysqli_query($conn, "
    SELECT c.*,
           COALESCE(AVG(r.rating), 0) as avg_rating,
           COUNT(DISTINCT r.id) as review_count,
           (SELECT COUNT(*) FROM product_3d_models pm JOIN products p ON pm.product_id = p.id WHERE p.clinic_id = c.id AND pm.has_3d = 1) as has_3d
    FROM clinics c
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id
    WHERE c.status = 'Active'
    GROUP BY c.id
    ORDER BY avg_rating DESC, c.clinic_name ASC
");
$total_clinics = mysqli_num_rows($clinics);

// Notifications
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

// Sale products
$sale_products_query = mysqli_query($conn, "
    SELECT p.*, c.name as clinic_name, c.id as clinic_id,
           ROUND(((p.price - p.sale_price) / p.price) * 100) as discount_percent,
           DATEDIFF(p.sale_end, CURDATE()) as days_left
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.is_on_sale = 1
      AND p.sale_start <= CURDATE()
      AND p.sale_end >= CURDATE()
      AND c.status = 'Active'
    ORDER BY discount_percent DESC, p.sale_end ASC
    LIMIT 4
");
$sale_total_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM products p JOIN clinics c ON p.clinic_id=c.id WHERE p.is_on_sale=1 AND p.sale_start<=CURDATE() AND p.sale_end>=CURDATE() AND c.status='Active'");
$sale_total = mysqli_fetch_assoc($sale_total_q)['total'] ?? 0;

// Active category filter from URL
$active_category = isset($_GET['category']) ? trim($_GET['category']) : '';

// ===== DYNAMIC CATEGORIES =====
$dynamic_categories = [];
$cat_q = mysqli_query($conn, "
    SELECT category, COUNT(*) as product_count
    FROM products 
    GROUP BY category
    ORDER BY 
        CASE category 
            WHEN 'Eyeglasses' THEN 1
            WHEN 'Sunglasses' THEN 2
            WHEN 'Computer Glasses' THEN 3
            WHEN 'Contact Lens' THEN 4
            WHEN 'Reading' THEN 5
            WHEN 'Kids' THEN 6
            ELSE 7
        END
");
while ($crow = mysqli_fetch_assoc($cat_q)) {
    $dynamic_categories[] = $crow;
}

// ===== CATEGORY HOVER PREVIEW =====
$categories_with_products = [];
foreach ($dynamic_categories as $cat_data) {
    $display_name = $cat_data['category'];
    $preview_query = mysqli_query($conn, "
        SELECT p.*, c.name as clinic_name, c.id as clinic_id
        FROM products p
        JOIN clinics c ON p.clinic_id = c.id
        WHERE p.category = '$display_name'
        LIMIT 3
    ");
    $products_preview = [];
    while ($row = mysqli_fetch_assoc($preview_query)) {
        $products_preview[] = $row;
    }
    $categories_with_products[$display_name] = [
        'key'      => strtolower($display_name),
        'products' => $products_preview,
        'count'    => $cat_data['product_count']
    ];
}

// Category icons
$category_icons = [
    'eyeglasses'       => ['icon' => 'fas fa-glasses',   'bg' => 'linear-gradient(150deg,#0d1a2e,#1a3a6e)', 'text' => 'rgba(255,255,255,0.9)'],
    'sunglasses'       => ['icon' => 'fas fa-sun',        'bg' => 'linear-gradient(150deg,#1a0a00,#5a2500)', 'text' => 'rgba(255,180,60,0.9)'],
    'computer glasses' => ['icon' => 'fas fa-desktop',    'bg' => 'linear-gradient(150deg,#001820,#004466)', 'text' => 'rgba(80,220,255,0.9)'],
    'contact lens'     => ['icon' => 'fas fa-circle',     'bg' => 'linear-gradient(150deg,#12002e,#3d0080)', 'text' => 'rgba(180,100,255,0.9)'],
    'reading'          => ['icon' => 'fas fa-book-open',  'bg' => 'linear-gradient(150deg,#001a08,#004d1a)', 'text' => 'rgba(80,255,130,0.9)'],
    'kids'             => ['icon' => 'fas fa-child',       'bg' => 'linear-gradient(150deg,#1a1500,#665000)', 'text' => 'rgba(255,215,50,0.9)'],
    'accessories'      => ['icon' => 'fas fa-tag',         'bg' => 'linear-gradient(150deg,#1a001a,#4d004d)', 'text' => 'rgba(255,150,255,0.9)'],
    'lenses'           => ['icon' => 'fas fa-eye',         'bg' => 'linear-gradient(150deg,#001a1a,#004d4d)', 'text' => 'rgba(80,255,255,0.9)'],
    'frames'           => ['icon' => 'fas fa-glasses',     'bg' => 'linear-gradient(150deg,#1a0a00,#4d2000)', 'text' => 'rgba(255,200,100,0.9)'],
    'eyewear'          => ['icon' => 'fas fa-glasses',     'bg' => 'linear-gradient(150deg,#0a001a,#2d0060)', 'text' => 'rgba(200,150,255,0.9)'],
    'package'          => ['icon' => 'fas fa-box-open',    'bg' => 'linear-gradient(150deg,#001a0a,#003d1a)', 'text' => 'rgba(100,255,160,0.9)'],
    'service'          => ['icon' => 'fas fa-stethoscope', 'bg' => 'linear-gradient(150deg,#001020,#002d50)', 'text' => 'rgba(100,180,255,0.9)'],
    'eye exam'         => ['icon' => 'fas fa-eye',         'bg' => 'linear-gradient(150deg,#1a0010,#4d0030)', 'text' => 'rgba(255,100,200,0.9)'],
];

// Upcoming appointment
$upcoming_query = mysqli_query($conn, "
    SELECT a.*, c.clinic_name, c.address, c.id as clinic_id,
           c.clinic_image, c.cover_photo, c.logo,
           c.contact as clinic_contact, c.clinic_email, c.hours as clinic_hours,
           sp.product_name, sp.selling_price as product_price, sp.category as product_category,
           d.name as doctor_name, d.specialty as doctor_specialty
    FROM appointments a
    JOIN clinics c ON a.clinic_id = c.id
    LEFT JOIN supplier_products sp ON a.product_id = sp.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE a.user_id = $user_id
      AND a.appointment_date >= CURDATE()
      AND a.status IN ('pending','confirmed','paid')
    ORDER BY a.appointment_date ASC
    LIMIT 1
");
$upcoming_appointment = mysqli_fetch_assoc($upcoming_query);

// User stats
$points_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points    = $points_row['t'] ?: 0;
$total_favorites = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM favorites WHERE user_id=$user_id"))['t'] ?: 0;
$total_bookings  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM appointments WHERE user_id=$user_id"))['t'] ?: 0;

$points_next_reward = 100;
$points_percentage  = (($total_points % $points_next_reward) / $points_next_reward) * 100;

// Helpers
function getClinicImg($c) {
    if (!empty($c['cover_photo']))  return '/assets/images/clinic-covers/'  . $c['cover_photo'];
    if (!empty($c['clinic_image'])) return '/assets/images/clinic-images/'  . $c['clinic_image'];
    if (!empty($c['logo']))         return '/assets/images/clinic-logos/'   . $c['logo'];
    return null;
}
function getProductImage($product) {
    if (!empty($product['image'])) {
        $p = '/assets/images/products/' . $product['image'];
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $p)) return $p;
    }
    return '';
}
if (!function_exists('timeAgo')) {
    function timeAgo($ts) {
        $diff = time() - strtotime($ts);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return round($diff/60)   . ' min ago';
        if ($diff < 86400)  return round($diff/3600)  . ' hr ago';
        if ($diff < 604800) return round($diff/86400) . ' days ago';
        return date('M d', strtotime($ts));
    }
}
if (!function_exists('getNotificationIcon')) {
    function getNotificationIcon($t) {
        return ['appointment'=>'fa-calendar-check','favorite'=>'fa-heart','promo'=>'fa-tags'][$t] ?? 'fa-bell';
    }
}
if (!function_exists('getTravelIcon')) {
    function getTravelIcon($m) {
        return ['walking'=>'fa-person-walking','driving'=>'fa-car'][$m] ?? 'fa-bus';
    }
}
if (!function_exists('getTravelText')) {
    function getTravelText($m, $d) {
        if ($m === 'walking') return round($d*12).' min walk';
        if ($m === 'driving') return round($d*2).' min drive';
        return $d.' km away';
    }
}
if (!function_exists('isClinicOpen')) {
    function isClinicOpen($hours) {
        // FORCE OPEN - TEMPORARY FIX
        // Para laging "Open now" ang status ng lahat ng clinics
        return ['open' => true, 'label' => 'Open now'];
        
        /* ORIGINAL CODE - COMMENTED OUT
        if (empty($hours) || strtolower(trim($hours)) === 'hours not set'
            || trim($hours) === 'NULL' || trim($hours) === '0.0') {
            return ['open' => null, 'label' => 'Hours N/A'];
        }

        $now     = new DateTime('now');
        $today   = (int)$now->format('N');
        $nowMins = (int)$now->format('H') * 60 + (int)$now->format('i');

        $dayMap = [
            'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7,
            'monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,
            'friday'=>5,'saturday'=>6,'sunday'=>7,
        ];

        $toMins = function($str) {
            $str = strtolower(trim($str));
            $pm  = strpos($str, 'pm') !== false;
            $am  = strpos($str, 'am') !== false;
            $str = preg_replace('/[^0-9:]/', '', $str);
            $parts = explode(':', $str);
            $h = (int)$parts[0];
            $m = isset($parts[1]) ? (int)$parts[1] : 0;
            if ($pm && $h !== 12) $h += 12;
            if ($am && $h === 12) $h = 0;
            return $h * 60 + $m;
        };

        if (!preg_match(
            '/(\d{1,2}(?::\d{2})?\s*(?:am|pm))\s*[-–to]+\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm))/i',
            $hours, $tm
        )) {
            return ['open' => null, 'label' => htmlspecialchars($hours)];
        }

        $openMins  = $toMins($tm[1]);
        $closeMins = $toMins($tm[2]);

        $dayPart  = strtolower(preg_replace('/' . preg_quote($tm[0], '/') . '.*$/i', '', $hours));
        $inRange  = true;

        if (preg_match('/([a-z]+)\s*[-–]\s*([a-z]+)/i', $dayPart, $dr)) {
            $startDay = $dayMap[strtolower(substr($dr[1], 0, 3))] ?? null;
            $endDay   = $dayMap[strtolower(substr($dr[2], 0, 3))] ?? null;
            if ($startDay && $endDay) {
                $inRange = ($startDay <= $endDay)
                    ? ($today >= $startDay && $today <= $endDay)
                    : ($today >= $startDay || $today <= $endDay);
            }
        }

        if (!$inRange) {
            return ['open' => false, 'label' => 'Closed today'];
        }

        $open = ($nowMins >= $openMins && $nowMins < $closeMins);
        return ['open' => $open, 'label' => $open ? 'Open now' : 'Closed now'];
        */
    }
}

if (!function_exists('calculateDistance')) {
    function calculateDistance($lat1,$lon1,$lat2,$lon2) {
        if (!$lat1||!$lon1||!$lat2||!$lon2) return '';
        $theta = $lon1 - $lon2;
        $dist  = sin(deg2rad($lat1))*sin(deg2rad($lat2)) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*cos(deg2rad($theta));
        return round(rad2deg(acos(max(-1,min(1,$dist)))*60*1.1515*1.609344), 1);
    }
}

$grads       = ['linear-gradient(135deg,#d1fae5,#6ee7b7)','linear-gradient(135deg,#dbeafe,#93c5fd)','linear-gradient(135deg,#fce7f3,#f9a8d4)','linear-gradient(135deg,#ede9fe,#c4b5fd)','linear-gradient(135deg,#fef9c3,#fde047)','linear-gradient(135deg,#ccfbf1,#5eead4)'];
$icon_colors = ['#059669','#1d4ed8','#9d174d','#5b21b6','#92400e','#0f766e'];

$user_lat = 14.2994;
$user_lng = 120.9596;

$nearby_clinics = mysqli_query($conn, "
    SELECT c.*,
           COALESCE(AVG(r.rating), 0) as avg_rating,
           COUNT(r.id) as review_count,
           ROUND(6371 * acos(GREATEST(-1, LEAST(1,
               cos(radians($user_lat)) * cos(radians(c.latitude)) *
               cos(radians(c.longitude) - radians($user_lng)) +
               sin(radians($user_lat)) * sin(radians(c.latitude))
           ))), 1) AS distance_km,
           CASE
               WHEN ROUND(6371 * acos(GREATEST(-1, LEAST(1,
                   cos(radians($user_lat)) * cos(radians(c.latitude)) *
                   cos(radians(c.longitude) - radians($user_lng)) +
                   sin(radians($user_lat)) * sin(radians(c.latitude))
               ))), 1) <= 1 THEN 'walking'
               WHEN ROUND(6371 * acos(GREATEST(-1, LEAST(1,
                   cos(radians($user_lat)) * cos(radians(c.latitude)) *
                   cos(radians(c.longitude) - radians($user_lng)) +
                   sin(radians($user_lat)) * sin(radians(c.latitude))
               ))), 1) <= 3 THEN 'driving'
               ELSE 'transport'
           END as travel_mode
    FROM clinics c
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id
    WHERE c.status = 'Active' AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL
    GROUP BY c.id
    HAVING distance_km <= 10
    ORDER BY distance_km ASC
    LIMIT 5
");

$recent_activities = mysqli_query($conn, "
    (SELECT 'appointment' as type, CONCAT('Booked at ', c.clinic_name) as description, a.created_at as date
     FROM appointments a JOIN clinics c ON a.clinic_id = c.id
     WHERE a.user_id = $user_id ORDER BY a.created_at DESC LIMIT 2)
    UNION ALL
    (SELECT 'favorite' as type, CONCAT('Saved ', c.clinic_name) as description, f.created_at as date
     FROM favorites f JOIN clinics c ON f.clinic_id = c.id
     WHERE f.user_id = $user_id ORDER BY f.created_at DESC LIMIT 2)
    ORDER BY date DESC LIMIT 4
");

include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Eyecore — Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    html, body { width: 100%; overflow-x: hidden; }

    :root {
        --primary: #00B761; --primary-dark: #00874A; --primary-light: #E3FCE9;
        --primary-gradient: linear-gradient(135deg,#00B761,#00A86B);
        --secondary: #FF8C42;
        --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF; --card-bg: #FFFFFF;
        --text-primary: #111827; --text-secondary: #6B7280; --text-muted: #9CA3AF;
        --border-color: #E5E7EB; --border-light: #F3F4F6;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
        --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
        --shadow-hover: 0 20px 40px -12px rgba(0,183,97,0.25);
        --radius-sm: 10px; --radius-md: 14px; --radius-lg: 20px;
        --radius-xl: 28px; --radius-full: 999px;
        --danger: #EF4444; --warning: #F59E0B; --success: #00B761; --info: #3B82F6;
        --pending-bg: #FEF3C7; --pending-text: #92400E;
        --confirmed-bg: #D1FAE5; --confirmed-text: #065F46;
        --paid-bg: #DBEAFE; --paid-text: #1E40AF;
        --completed-bg: #EDE9FE; --completed-text: #5B21B6;
        --cancelled-bg: #FEE2E2; --cancelled-text: #991B1B;
    }
    .theme-dark {
        --primary: #00E676; --primary-dark: #00C853; --primary-light: #0D2818;
        --bg-primary: #0D0D0D; --bg-secondary: #161616; --card-bg: #1E1E1E;
        --text-primary: #F9FAFB; --text-secondary: #9CA3AF; --text-muted: #6B7280;
        --border-color: #2A2A2A; --border-light: #222222;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.4);
        --shadow-lg: 0 12px 40px rgba(0,0,0,0.5);
        --pending-bg: #3d2e00; --pending-text: #fbbf24;
        --confirmed-bg: #052e16; --confirmed-text: #6ee7b7;
        --paid-bg: #1e3a5f; --paid-text: #93c5fd;
        --completed-bg: #2e1065; --completed-text: #c4b5fd;
        --cancelled-bg: #450a0a; --cancelled-text: #fca5a5;
    }

    body { font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; background: var(--bg-primary); color: var(--text-primary); font-size: 14px; transition: background 0.3s, color 0.3s; }

    /* TOAST */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; pointer-events: none; }
    .toast-notification { pointer-events: auto; display: flex; align-items: center; gap: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 14px 20px; box-shadow: var(--shadow-lg); margin-bottom: 10px; min-width: 300px; animation: slideIn 0.3s ease; border-left: 4px solid var(--primary); }
    .toast-notification.success { border-left-color: var(--success); }
    .toast-notification.error   { border-left-color: var(--danger); }
    .toast-notification.info    { border-left-color: var(--info); }
    .toast-notification i { font-size: 18px; }
    .toast-notification.success i { color: var(--success); }
    .toast-notification.error   i { color: var(--danger); }
    .toast-notification.info    i { color: var(--info); }
    .toast-notification span { font-size: 13px; color: var(--text-primary); flex: 1; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @keyframes fadeOut { to { opacity: 0; } }

    /* MAIN CONTENT */
    .main-content { max-width: 1400px; margin: 0 auto; padding: 28px 40px; }
    @media (max-width: 1024px) { .main-content { padding: 24px; } }
    @media (max-width: 768px)  { .main-content { padding: 18px 16px 100px; } }

    /* WELCOME HERO */
    .welcome-hero { background: linear-gradient(115deg, #003d20 0%, #006633 45%, #00a854 100%); border-radius: var(--radius-xl); padding: 32px 36px; margin-bottom: 24px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between; gap: 24px; box-shadow: 0 12px 40px -8px rgba(0,183,97,0.35); }
    @media (max-width: 768px) { .welcome-hero { padding: 24px 20px; flex-direction: column; align-items: flex-start; } }
    .wh-text { position: relative; z-index: 2; }
    .wh-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; background: rgba(255,255,255,0.15); border-radius: var(--radius-full); font-size: 11px; color: rgba(255,255,255,0.9); font-weight: 600; margin-bottom: 10px; letter-spacing: 0.3px; }
    .wh-badge i { font-size: 8px; }
    .wh-title { font-size: 26px; font-weight: 800; color: white; margin-bottom: 6px; letter-spacing: -0.4px; line-height: 1.2; }
    @media (max-width: 768px) { .wh-title { font-size: 20px; } }
    .wh-sub { font-size: 13px; color: rgba(255,255,255,0.78); margin-bottom: 20px; }
    .wh-stats { display: flex; gap: 20px; flex-wrap: wrap; }
    .wh-stat { text-align: center; }
    .wh-stat-num   { font-size: 20px; font-weight: 800; color: white; line-height: 1; }
    .wh-stat-label { font-size: 10px; color: rgba(255,255,255,0.65); margin-top: 2px; font-weight: 500; }
    .wh-divider { width: 1px; background: rgba(255,255,255,0.2); align-self: stretch; }
    .wh-visual { position: relative; z-index: 2; flex-shrink: 0; }
    @media (max-width: 768px) { .wh-visual { display: none; } }
    .wh-pts-ring { text-align: center; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: var(--radius-lg); padding: 16px 20px; }
    .wh-pts-num  { font-size: 28px; font-weight: 800; color: white; line-height: 1; }
    .wh-pts-lbl  { font-size: 10px; color: rgba(255,255,255,0.65); margin-top: 3px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
    .wh-pts-bar  { height: 4px; background: rgba(255,255,255,0.2); border-radius: 2px; margin-top: 10px; width: 100px; }
    .wh-pts-fill { height: 100%; background: #7dffc0; border-radius: 2px; transition: width 0.8s ease; }
    .wh-pts-next { font-size: 9px; color: rgba(255,255,255,0.5); margin-top: 4px; }
    .welcome-hero::before { content: ''; position: absolute; top: -60%; right: -10%; width: 400px; height: 400px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 60%); border-radius: 50%; }

    /* QUICK ACTIONS */
    .quick-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 28px; }
    @media (max-width: 1024px) { .quick-grid { grid-template-columns: repeat(2,1fr); } }
    .quick-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 20px 16px; text-decoration: none; display: flex; flex-direction: column; align-items: center; gap: 10px; transition: all 0.2s; border: 1px solid var(--border-light); }
    .quick-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); border-color: var(--primary); }
    .qc-icon { width: 52px; height: 52px; border-radius: var(--radius-full); background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 22px; transition: all 0.2s; }
    .quick-card:hover .qc-icon { background: var(--primary); color: white; }
    .qc-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
    .qc-sub   { font-size: 11px; color: var(--text-muted); }

    /* SECTION HEADER */
    .sec-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .sec-head h2 { font-size: 16px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
    .sec-head h2 i { color: var(--primary); font-size: 18px; }
    .view-all { color: var(--primary); text-decoration: none; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 5px; }
    .view-all:hover { text-decoration: underline; }

    /* UPCOMING APPOINTMENT */
    .appt-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 20px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 28px; }
    .appt-info { display: flex; align-items: center; gap: 16px; }
    .appt-img  { width: 70px; height: 70px; border-radius: var(--radius-md); overflow: hidden; flex-shrink: 0; background: var(--bg-primary); }
    .appt-img img { width: 100%; height: 100%; object-fit: cover; }
    .appt-img-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
    .appt-img-placeholder i { font-size: 28px; color: var(--primary); }
    .appt-details h3 { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
    .appt-details p  { display: flex; align-items: center; gap: 6px; color: var(--text-secondary); font-size: 12px; margin-bottom: 3px; }
    .appt-details p i { color: var(--primary); font-size: 11px; }
    .appt-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; margin-top: 5px; }
    .status-pending   { background: var(--pending-bg);   color: var(--pending-text); }
    .status-confirmed { background: var(--confirmed-bg); color: var(--confirmed-text); }
    .status-paid      { background: var(--paid-bg);      color: var(--paid-text); }
    .status-completed { background: var(--completed-bg); color: var(--completed-text); }
    .status-cancelled { background: var(--cancelled-bg); color: var(--cancelled-text); }
    .appt-actions { display: flex; gap: 10px; }
    .btn-outline { padding: 9px 20px; border: 1.5px solid var(--primary); background: transparent; color: var(--primary); border-radius: var(--radius-full); font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.15s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-outline:hover { background: var(--primary); color: white; }
    .btn-primary { padding: 9px 20px; background: var(--primary-gradient); color: white; border: none; border-radius: var(--radius-full); font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.15s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 6px 16px -6px var(--primary); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -8px var(--primary); }

    /* NEARBY */
    .nearby-scroll { overflow-x: auto; scrollbar-width: none; margin: 0 -40px; padding: 4px 40px 14px; }
    .nearby-scroll::-webkit-scrollbar { display: none; }
    @media (max-width: 768px) { .nearby-scroll { margin: 0 -16px; padding: 4px 16px 14px; } }
    .nearby-track { display: flex; gap: 12px; }
    .nearby-card { flex-shrink: 0; width: 200px; background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius-lg); overflow: hidden; cursor: pointer; text-decoration: none; display: block; transition: all 0.2s; }
    .nearby-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); border-color: var(--primary); }
    .nc-img { height: 110px; position: relative; display: flex; align-items: center; justify-content: center; }
    .nc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .nc-img-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
    .nc-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.3) 0%, transparent 50%); }
    .nc-dist   { position: absolute; bottom: 7px; left: 7px; background: rgba(0,0,0,0.65); color: white; font-size: 9px; font-weight: 600; padding: 2px 8px; border-radius: var(--radius-full); display: flex; align-items: center; gap: 3px; }
    .nc-3d     { position: absolute; top: 7px; left: 7px; background: var(--primary); color: white; font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: var(--radius-full); }
    .nc-rating { position: absolute; top: 7px; right: 7px; background: rgba(0,0,0,0.65); color: white; font-size: 9px; padding: 2px 7px; border-radius: var(--radius-full); display: flex; align-items: center; gap: 2px; }
    .nc-rating i { color: var(--warning); font-size: 8px; }
    .nc-body { padding: 10px 12px 12px; }
    .nc-name { font-size: 12px; font-weight: 700; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); }
    .nc-city { font-size: 10px; color: var(--text-muted); margin-bottom: 7px; }
    .nc-foot { display: flex; align-items: center; justify-content: space-between; }
    .nc-book { font-size: 10px; font-weight: 700; color: var(--primary); background: var(--primary-light); padding: 4px 10px; border-radius: var(--radius-full); }

    /* ACTIVITY */
    .activity-list { background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border-light); overflow: hidden; margin-bottom: 28px; }
    .activity-item { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid var(--border-light); transition: all 0.15s; }
    .activity-item:last-child { border-bottom: none; }
    .activity-item:hover { background: var(--bg-primary); }
    .activity-icon { width: 38px; height: 38px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .activity-icon.appointment { background: #EFF6FF; color: #1D4ED8; }
    .activity-icon.favorite    { background: #FFF1F2; color: #BE123C; }
    .activity-icon.default     { background: var(--bg-primary); color: var(--text-muted); }
    .activity-title { font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 2px; }
    .activity-time  { font-size: 11px; color: var(--text-muted); }

    /* 3D PROMO */
    .tryon-strip { background: linear-gradient(120deg, #060f1e 0%, #0b2540 50%, #082d1a 100%); border-radius: var(--radius-xl); padding: 24px 28px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
    @media (max-width: 640px) { .tryon-strip { flex-direction: column; align-items: flex-start; } }
    .ts-tag   { font-size: 10px; color: #7dffc0; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 6px; }
    .ts-title { font-size: 17px; font-weight: 800; color: white; margin-bottom: 5px; line-height: 1.25; letter-spacing: -0.3px; }
    .ts-sub   { font-size: 12px; color: rgba(255,255,255,0.6); line-height: 1.5; }
    .ts-btn   { display: inline-flex; align-items: center; gap: 7px; padding: 10px 22px; background: white; color: #060f1e; border-radius: var(--radius-full); font-size: 12px; font-weight: 800; cursor: pointer; text-decoration: none; white-space: nowrap; flex-shrink: 0; transition: all 0.15s; }
    .ts-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.25); }

    /* HOT SALES */
    .sales-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 28px; }
    @media (max-width: 1024px) { .sales-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 640px)  { .sales-grid { grid-template-columns: 1fr; } }
    .sale-card { background: var(--card-bg); border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-light); transition: all 0.25s; text-decoration: none; display: block; }
    .sale-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); border-color: var(--danger); }
    .sale-img { height: 150px; position: relative; display: flex; align-items: center; justify-content: center; }
    .sale-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .sale-img-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
    .sale-img-placeholder i { font-size: 40px; opacity: 0.5; }
    .sale-badge-pct { position: absolute; top: 10px; right: 10px; background: var(--danger); color: white; padding: 5px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 800; box-shadow: 0 4px 10px rgba(239,68,68,0.3); }
    .sale-body { padding: 16px; }
    .sale-clinic { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
    .sale-clinic i { color: var(--primary); font-size: 10px; }
    .sale-name   { font-size: 13px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; line-height: 1.3; }
    .sale-prices { display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; }
    .sale-price  { font-size: 18px; font-weight: 800; color: var(--danger); }
    .sale-orig   { font-size: 12px; color: var(--text-muted); text-decoration: line-through; }
    .sale-timer  { font-size: 11px; color: var(--text-secondary); display: flex; align-items: center; gap: 5px; }
    .sale-timer i { color: var(--warning); }
    .sale-timer.urgent { color: var(--danger); font-weight: 700; }

    /* FILTER */
    .filter-bar { background: var(--card-bg); border-radius: var(--radius-lg); padding: 16px 20px; margin-bottom: 16px; border: 1px solid var(--border-light); display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .filter-label { display: flex; align-items: center; gap: 7px; color: var(--text-secondary); font-size: 13px; font-weight: 600; }
    .filter-label i { color: var(--primary); }
    .filter-select { padding: 9px 20px; border: 1px solid var(--border-color); border-radius: var(--radius-full); background: var(--bg-primary); color: var(--text-primary); font-size: 13px; cursor: pointer; outline: none; font-family: inherit; min-width: 200px; }
    .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
    .results-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .results-count { background: var(--card-bg); padding: 7px 14px; border-radius: var(--radius-full); font-size: 12px; border: 1px solid var(--border-light); color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
    .results-count i { color: var(--primary); }
    .results-count span { font-weight: 700; color: var(--primary); }

    /* CLINICS GRID */
    .clinics-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 32px; }
    @media (max-width: 1024px) { .clinics-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 640px)  { .clinics-grid { grid-template-columns: 1fr; } }
    .clinic-card { background: var(--card-bg); border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border-light); transition: all 0.25s; }
    .clinic-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); border-color: var(--primary); }
    .clinic-img-area { height: 170px; position: relative; display: flex; align-items: center; justify-content: center; }
    .clinic-img-area img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .clinic-img-area .img-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
    .clinic-img-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.28) 0%, transparent 55%); }
    .clinic-badge-rating { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.65); color: white; padding: 3px 9px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; display: flex; align-items: center; gap: 4px; }
    .clinic-badge-rating i { color: var(--warning); font-size: 10px; }
    .clinic-3d-badge   { position: absolute; top: 10px; left: 10px; background: var(--primary); color: white; font-size: 9px; font-weight: 800; padding: 3px 9px; border-radius: var(--radius-full); }
    .clinic-dist-badge { position: absolute; bottom: 10px; left: 10px; background: rgba(0,0,0,0.65); color: white; font-size: 10px; padding: 3px 9px; border-radius: var(--radius-full); display: flex; align-items: center; gap: 4px; }
    .clinic-dist-badge i { color: var(--primary); font-size: 9px; }
    .clinic-info { padding: 16px; }
    .clinic-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 8px; gap: 8px; }
    .clinic-name   { font-size: 14px; font-weight: 700; color: var(--text-primary); }
    .clinic-rating-text { font-size: 11px; color: var(--warning); font-weight: 700; white-space: nowrap; }
    .clinic-detail { display: flex; align-items: center; gap: 7px; color: var(--text-secondary); font-size: 12px; margin-bottom: 5px; }
    .clinic-detail i { color: var(--primary); font-size: 11px; width: 14px; }
    .clinic-footer { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .clinic-view-btn { padding: 8px 16px; background: var(--bg-primary); color: var(--primary); border-radius: var(--radius-full); font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.15s; display: flex; align-items: center; gap: 5px; border: 1px solid var(--border-light); }
    .clinic-view-btn:hover { background: var(--primary-light); border-color: var(--primary); }
    .clinic-book-btn { padding: 8px 16px; background: var(--primary-gradient); color: white; border-radius: var(--radius-full); font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.15s; display: flex; align-items: center; gap: 5px; box-shadow: 0 4px 12px -4px var(--primary); }
    .clinic-book-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 16px -6px var(--primary); }
    /* Message button style */
    .clinic-msg-btn { width: 36px; height: 36px; background: var(--bg-primary); border: 1px solid var(--border-light); border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; color: var(--primary); text-decoration: none; transition: all 0.15s; font-size: 13px; }
    .clinic-msg-btn:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.05); }

    /* EMPTY STATES */
    .empty-state { text-align: center; padding: 48px 20px; background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border-light); }
    .empty-state i { font-size: 52px; color: var(--text-muted); margin-bottom: 14px; display: block; opacity: 0.4; }
    .empty-state h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
    .empty-state p  { color: var(--text-secondary); font-size: 13px; margin-bottom: 20px; max-width: 340px; margin-left: auto; margin-right: auto; }
    .no-results-dynamic { grid-column: 1/-1; text-align: center; padding: 60px 20px; background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border-light); }
    .no-results-dynamic i { font-size: 48px; opacity: 0.3; margin-bottom: 14px; display: block; }
    .no-results-dynamic h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
    .no-results-dynamic p  { color: var(--text-secondary); font-size: 13px; margin-bottom: 16px; }
    .clear-btn { padding: 9px 22px; background: var(--primary); color: white; border: none; border-radius: var(--radius-full); font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s; font-family: inherit; }
    .clear-btn:hover { background: var(--primary-dark); }

    /* PAGINATION */
    .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; padding-bottom: 20px; }
    .pagination.hidden { display: none; }
    .pagination a, .pagination span { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-full); font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.15s; border: 1px solid var(--border-light); color: var(--text-secondary); background: var(--card-bg); }
    .pagination a:hover   { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
    .pagination span.active { background: var(--primary); color: white; border-color: var(--primary); }

    /* MODAL */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; z-index: 10000; opacity: 0; visibility: hidden; transition: all 0.25s; }
    .modal-overlay.show { opacity: 1; visibility: visible; }
    .modal-container { background: var(--bg-secondary); border-radius: var(--radius-xl); width: 90%; max-width: 520px; max-height: 86vh; overflow-y: auto; transform: scale(0.85); transition: transform 0.25s; box-shadow: var(--shadow-lg); }
    .modal-overlay.show .modal-container { transform: scale(1); }
    .modal-header { padding: 22px 24px; background: var(--primary-gradient); color: white; border-radius: var(--radius-xl) var(--radius-xl) 0 0; position: sticky; top: 0; z-index: 1; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 800; }
    .modal-close { background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 18px; transition: all 0.15s; }
    .modal-close:hover { background: rgba(255,255,255,0.35); }
    .modal-body   { padding: 22px 24px; color: var(--text-primary); }
    .modal-footer { padding: 16px 24px; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--border-light); }
    .modal-btn { padding: 9px 20px; border-radius: var(--radius-full); font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s; border: none; display: inline-flex; align-items: center; gap: 7px; font-family: inherit; }
    .modal-btn.primary { background: var(--primary-gradient); color: white; box-shadow: 0 4px 12px -4px var(--primary); }
    .modal-btn.primary:hover { transform: translateY(-2px); }
    .modal-btn.outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-secondary); }
    .modal-btn.outline:hover { background: var(--bg-primary); color: var(--primary); border-color: var(--primary); }
    .detail-section { margin-bottom: 18px; }
    .detail-section h4 { font-size: 13px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; color: var(--text-primary); }
    .detail-section h4 i { color: var(--primary); }
    .detail-card { background: var(--bg-primary); border-radius: var(--radius-md); padding: 13px; border: 1px solid var(--border-light); }
    .detail-row { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }
    .detail-row:last-child { margin-bottom: 0; }
    .detail-icon { width: 30px; height: 30px; background: var(--primary-light); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 13px; flex-shrink: 0; }
    .detail-label { font-size: 10px; color: var(--text-muted); margin-bottom: 1px; }
    .detail-value { font-weight: 600; color: var(--text-primary); font-size: 13px; }
    .doctor-info { display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--bg-primary); border-radius: var(--radius-md); }
    .doctor-avatar { width: 36px; height: 36px; background: var(--primary-light); border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 16px; flex-shrink: 0; }
    .doctor-name      { font-size: 13px; font-weight: 700; }
    .doctor-specialty { font-size: 11px; color: var(--text-muted); }
    .doctor-badge     { background: var(--primary); color: white; font-size: 9px; padding: 2px 8px; border-radius: var(--radius-full); font-weight: 700; margin-left: auto; }
    .appointment-status { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; }

    /* TOOLTIPS */
    [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: var(--bg-secondary); color: var(--text-primary); padding: 6px 10px; border-radius: var(--radius-md); font-size: 11px; white-space: nowrap; box-shadow: var(--shadow-md); z-index: 1000; margin-bottom: 6px; border: 1px solid var(--border-light); font-weight: 500; pointer-events: none; }
    [data-tooltip] { position: relative; cursor: help; }

    /* RESPONSIVE HELPERS */
    @media (max-width: 768px) {
        .appt-card { flex-direction: column; align-items: flex-start; }
        .appt-actions { width: 100%; }
        .appt-actions .btn-outline, .appt-actions .btn-primary { flex: 1; justify-content: center; }
        .filter-bar  { flex-direction: column; align-items: flex-start; }
        .filter-select { width: 100%; }
        .clinic-footer { flex-wrap: wrap; }
        .clinic-view-btn, .clinic-book-btn { flex: 1; justify-content: center; }
    }

    /* ===== CATEGORY TILES ===== */
    .cat-grid { display: grid; grid-template-columns: repeat(6,1fr); gap: 12px; margin-bottom: 28px; }
    @media (max-width: 1024px) { .cat-grid { grid-template-columns: repeat(3,1fr); } }
    @media (max-width: 640px)  { .cat-grid { grid-template-columns: repeat(3,1fr); gap: 8px; } }

    .cat-tile { border-radius: var(--radius-md); overflow: hidden; text-decoration: none; display: block; transition: transform .2s, box-shadow .2s; border: 2px solid transparent; cursor: pointer; }
    .cat-tile:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
    .cat-tile.cat-active { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-light); }
    .cat-img-box { aspect-ratio: 1/1; display: flex; align-items: center; justify-content: center; position: relative; }
    .cat-active-check { position: absolute; top: 7px; right: 7px; width: 20px; height: 20px; background: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .cat-active-check i { font-size: 10px; color: #fff; -webkit-text-fill-color: #fff !important; }
    .cat-info  { padding: 8px 10px; background: var(--card-bg); border-top: 1px solid var(--border-light); }
    .cat-name  { font-size: 11px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cat-count { font-size: 10px; color: var(--text-muted); margin-top: 1px; }

    /* ===== DESKTOP HOVER DROPDOWN ===== */
    .category-hover-item { position: relative; cursor: default; }

    .category-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        width: 320px;
        background: var(--card-bg);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.25s ease;
        border: 1px solid var(--border-light);
        overflow: hidden;
    }
    .category-hover-item:hover .category-dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    /* ===== MOBILE: hide dropdown, click goes direct ===== */
    @media (max-width: 768px) {
        .category-dropdown { display: none !important; }
    }

    .dropdown-header { padding: 12px 16px; background: var(--primary-gradient); color: white; display: flex; justify-content: space-between; align-items: center; }
    .dropdown-header h4 { font-size: 13px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 6px; }
    .dropdown-header a  { color: white; font-size: 11px; text-decoration: none; opacity: 0.9; }
    .dropdown-header a:hover { opacity: 1; text-decoration: underline; }

    .dropdown-products { max-height: 320px; overflow-y: auto; padding: 8px; }

    .dropdown-product-item { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: var(--radius-md); transition: all 0.2s; text-decoration: none; border-bottom: 1px solid var(--border-light); }
    .dropdown-product-item:last-child { border-bottom: none; }
    .dropdown-product-item:hover { background: var(--bg-primary); transform: translateX(4px); }

    .dropdown-product-img { width: 45px; height: 45px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; background: var(--bg-primary); overflow: hidden; }
    .dropdown-product-img img { width: 100%; height: 100%; object-fit: cover; }
    .dropdown-product-name   { font-size: 12px; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
    .dropdown-product-clinic { font-size: 10px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
    .dropdown-product-clinic i { font-size: 9px; color: var(--primary); }
    .dropdown-product-price  { font-size: 13px; font-weight: 700; color: var(--primary); }

    .dropdown-empty { padding: 30px; text-align: center; color: var(--text-muted); }
    .dropdown-empty i { font-size: 40px; margin-bottom: 8px; opacity: 0.5; }
    .dropdown-empty p { font-size: 12px; }
    /* Open/Closed badge */
    .clinic-status-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.3px;
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.18);
        z-index: 2;
    }
    .clinic-status-badge.open  { background: rgba(0,183,97,0.88);  color: #fff; }
    .clinic-status-badge.closed { background: rgba(220,38,38,0.82); color: #fff; }
    .clinic-status-badge.unknown { background: rgba(100,100,100,0.7); color: #fff; }
    .clinic-status-dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        background: #fff;
        display: inline-block;
        animation: none;
    }
    .clinic-status-badge.open .clinic-status-dot {
        animation: pulse-dot 1.6s infinite;
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%       { opacity: 0.5; transform: scale(1.4); }
    }

    /* Open/Closed inline tag inside clinic-info */
    .clinic-open-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 99px;
        margin-left: 6px;
        vertical-align: middle;
    }
    .clinic-open-tag.open   { background: #D1FAE5; color: #065F46; }
    .clinic-open-tag.closed { background: #FEE2E2; color: #991B1B; }
    .clinic-open-tag.unknown { background: #F3F4F6; color: #6B7280; }
    .theme-dark .clinic-open-tag.open   { background: #064E3B; color: #6EE7B7; }
    .theme-dark .clinic-open-tag.closed { background: #450A0A; color: #FCA5A5; }
    .theme-dark .clinic-open-tag.unknown { background: #1F2937; color: #9CA3AF; }

    </style>
</head>
<body>
    <div class="main-content">

        <!-- WELCOME HERO -->
        <div class="welcome-hero">
            <div class="wh-text">
                <?php
                $first_name = $user['first_name'] ?? 'Guest';
                $hr = (int)date('H');
                $greeting = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
                ?>
                <div class="wh-badge"><i class="fas fa-circle" style="font-size:6px;"></i> Eyecore Dashboard</div>
                <div class="wh-title"><?php echo $greeting; ?>, <?php echo htmlspecialchars($first_name); ?>! 👋</div>
                <div class="wh-sub">Here's what's happening with your eye care today.</div>
                <div class="wh-stats">
                    <div class="wh-stat"><div class="wh-stat-num"><?php echo $total_bookings; ?></div><div class="wh-stat-label">Bookings</div></div>
                    <div class="wh-divider"></div>
                    <div class="wh-stat"><div class="wh-stat-num"><?php echo $pending; ?></div><div class="wh-stat-label">Pending</div></div>
                    <div class="wh-divider"></div>
                    <div class="wh-stat"><div class="wh-stat-num"><?php echo $total_favorites; ?></div><div class="wh-stat-label">Favorites</div></div>
                    <div class="wh-divider"></div>
                    <div class="wh-stat"><div class="wh-stat-num"><?php echo $total_clinics; ?></div><div class="wh-stat-label">Clinics</div></div>
                </div>
            </div>
            <div class="wh-visual">
                <div class="wh-pts-ring">
                    <div class="wh-pts-num"><?php echo $total_points; ?></div>
                    <div class="wh-pts-lbl">Reward Points</div>
                    <div class="wh-pts-bar"><div class="wh-pts-fill" style="width:<?php echo $points_percentage; ?>%"></div></div>
                    <div class="wh-pts-next"><?php echo (100 - ($total_points % 100)); ?> pts to next reward</div>
                </div>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="quick-grid">
            <a href="nearby.php" class="quick-card">
                <div class="qc-icon"><i class="fas fa-location-dot"></i></div>
                <span class="qc-title">Nearby</span>
                <span class="qc-sub">Find clinics near you</span>
            </a>
            <a href="my-appointments.php" class="quick-card">
                <div class="qc-icon"><i class="fas fa-calendar-check"></i></div>
                <span class="qc-title">Bookings</span>
                <span class="qc-sub"><?php echo $pending; ?> pending</span>
            </a>
            <a href="favorites.php" class="quick-card">
                <div class="qc-icon" style="background:#FFF1F2;color:#BE123C;"><i class="fas fa-heart"></i></div>
                <span class="qc-title">Favorites</span>
                <span class="qc-sub"><?php echo $total_favorites; ?> saved</span>
            </a>
            <a href="clinics-map.php" class="quick-card">
                <div class="qc-icon" style="background:#EFF6FF;color:#1D4ED8;"><i class="fas fa-map"></i></div>
                <span class="qc-title">Explore Map</span>
                <span class="qc-sub">All cities in Cavite</span>
            </a>
        </div>

        <!-- UPCOMING APPOINTMENT -->
        <?php if ($upcoming_appointment):
            $ap_img = getClinicImg($upcoming_appointment);
        ?>
        <div class="sec-head" style="margin-bottom:12px;">
            <h2><i class="fas fa-calendar-star"></i> Next Appointment</h2>
            <a href="my-appointments.php" class="view-all">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="appt-card">
            <div class="appt-info">
                <div class="appt-img">
                    <?php if ($ap_img): ?>
                        <img src="<?php echo htmlspecialchars($ap_img); ?>" alt="clinic"
                             onerror="this.parentElement.innerHTML='<div class=\'appt-img-placeholder\'><i class=\'fas fa-clinic-medical\'></i></div>'">
                    <?php else: ?>
                        <div class="appt-img-placeholder"><i class="fas fa-clinic-medical"></i></div>
                    <?php endif; ?>
                </div>
                <div class="appt-details">
                    <h3><?php echo htmlspecialchars($upcoming_appointment['clinic_name']); ?></h3>
                    <p><i class="fas fa-clock"></i> <?php echo date('D, M d · g:i A', strtotime($upcoming_appointment['appointment_date'].' '.$upcoming_appointment['appointment_time'])); ?></p>
                    <p><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($upcoming_appointment['address']); ?></p>
                    <?php if (!empty($upcoming_appointment['doctor_name'])): ?>
                    <p><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($upcoming_appointment['doctor_name']); ?></p>
                    <?php endif; ?>
                    <span class="appt-badge status-<?php echo $upcoming_appointment['status']; ?>">
                        <i class="fas <?php echo $upcoming_appointment['status'] == 'confirmed' ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                        <?php echo ucfirst($upcoming_appointment['status']); ?>
                    </span>
                </div>
            </div>
            <div class="appt-actions">
                <a href="#" class="btn-outline" onclick="showApptDetails(<?php echo htmlspecialchars(json_encode($upcoming_appointment)); ?>); return false;">
                    <i class="fas fa-info-circle"></i> Details
                </a>
                <a href="directions.php?clinic=<?php echo $upcoming_appointment['clinic_id']; ?>" class="btn-primary">
                    <i class="fas fa-directions"></i> Directions
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- NEARBY CLINICS -->
        <div class="sec-head">
            <h2><i class="fas fa-location-dot"></i> Near You</h2>
            <a href="nearby.php" class="view-all">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (mysqli_num_rows($nearby_clinics) > 0): ?>
        <div class="nearby-scroll" style="margin-bottom:28px;">
            <div class="nearby-track">
                <?php $nci = 0; while ($nc = mysqli_fetch_assoc($nearby_clinics)):
                    $nc_img = getClinicImg($nc);
                    $nc_grad = $grads[$nci % count($grads)];
                    $nc_color = $icon_colors[$nci % count($icon_colors)];
                    $nc_avg = round($nc['avg_rating'], 1);
                    $nc_travel_text = getTravelText($nc['travel_mode'], $nc['distance_km']);
                    $nc_travel_icon = getTravelIcon($nc['travel_mode']);
                ?>
                <a href="clinic-details.php?id=<?php echo $nc['id']; ?>" class="nearby-card">
                    <div class="nc-img">
                        <?php if ($nc_img): ?>
                            <img src="<?php echo htmlspecialchars($nc_img); ?>" alt="<?php echo htmlspecialchars($nc['clinic_name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="nc-img-placeholder" style="background:<?php echo $nc_grad; ?>;display:none;">
                                <div style="width:46px;height:46px;background:rgba(255,255,255,0.8);border-radius:14px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-clinic-medical" style="font-size:22px;color:<?php echo $nc_color; ?>;"></i>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="nc-img-placeholder" style="background:<?php echo $nc_grad; ?>;">
                                <div style="width:46px;height:46px;background:rgba(255,255,255,0.8);border-radius:14px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-clinic-medical" style="font-size:22px;color:<?php echo $nc_color; ?>;"></i>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="nc-overlay"></div>
                        <div class="nc-rating"><i class="fas fa-star"></i> <?php echo ($nc_avg > 0 ? $nc_avg : 'New'); ?></div>
                        <?php if ($nc['has_3d'] ?? false): ?><div class="nc-3d">3D View</div><?php endif; ?>
                        <div class="nc-dist"><i class="fas <?php echo $nc_travel_icon; ?>"></i> <?php echo $nc_travel_text; ?></div>
                    </div>
                    <div class="nc-body">
                        <div class="nc-name"><?php echo htmlspecialchars($nc['clinic_name']); ?></div>
                        <div class="nc-city"><i class="fas fa-map-marker-alt" style="color:var(--primary);font-size:9px;"></i> <?php echo htmlspecialchars($nc['city'] ?? 'Cavite'); ?></div>
                        <div class="nc-foot">
                            <div style="font-size:11px;color:var(--warning);">
                                <?php for ($s=1;$s<=5;$s++) echo ($s <= round($nc_avg) ? '★' : '☆'); ?>
                                <span style="color:var(--text-muted);font-size:9px;">(<?php echo $nc['review_count']; ?>)</span>
                            </div>
                            <div class="nc-book">Book</div>
                        </div>
                    </div>
                </a>
                <?php $nci++; endwhile; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state" style="margin-bottom:28px;">
            <i class="fas fa-map-marker-alt"></i>
            <h3>No clinics nearby</h3>
            <p>No clinics found within 10 km. Try the full map to explore all of Cavite.</p>
            <a href="clinics-map.php" class="btn-primary"><i class="fas fa-map"></i> Explore Map</a>
        </div>
        <?php endif; ?>

        <!-- RECENT ACTIVITY -->
        <div class="sec-head">
            <h2><i class="fas fa-history"></i> Recent Activity</h2>
        </div>
        <div class="activity-list">
            <?php if (mysqli_num_rows($recent_activities) > 0):
                while ($act = mysqli_fetch_assoc($recent_activities)): ?>
                <div class="activity-item">
                    <div class="activity-icon <?php echo $act['type']; ?>">
                        <i class="fas fa-<?php echo $act['type'] === 'appointment' ? 'calendar-check' : 'heart'; ?>"></i>
                    </div>
                    <div>
                        <div class="activity-title"><?php echo htmlspecialchars($act['description']); ?></div>
                        <div class="activity-time"><?php echo timeAgo($act['date']); ?></div>
                    </div>
                </div>
                <?php endwhile;
            else: ?>
                <div class="activity-item">
                    <div class="activity-icon default"><i class="fas fa-info-circle"></i></div>
                    <div>
                        <div class="activity-title">No recent activity yet</div>
                        <div class="activity-time">Start by booking an appointment or saving a favorite clinic.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3D PROMO -->
        <div class="tryon-strip">
            <div class="ts-left">
                <div class="ts-tag"><i class="fas fa-cube" style="margin-right:4px;"></i> 3D Frame Viewer</div>
                <div class="ts-title">See frames in full 360° 3D detail.</div>
                <div class="ts-sub">Rotate, zoom, and inspect eyeglass frames before you book.</div>
            </div>
            <a href="explore-3d.php" class="ts-btn"><i class="fas fa-cube"></i> Explore 3D Frames</a>
        </div>

        <!-- HOT DEALS -->
        <?php if ($sale_total > 0): ?>
        <div class="sec-head">
            <h2><i class="fas fa-fire" style="color:var(--danger);"></i> Hot Deals</h2>
            <a href="sale-products.php" class="view-all">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="sales-grid">
            <?php mysqli_data_seek($sale_products_query, 0);
            $pi = 0;
            while ($sale = mysqli_fetch_assoc($sale_products_query)):
                $urgent = $sale['days_left'] <= 2;
                $prod_img = !empty($sale['image']) ? '/assets/images/products/' . $sale['image'] : null;
                $sale_grad = $grads[$pi % count($grads)];
                $sale_icon_color = $icon_colors[$pi % count($icon_colors)];
                $pi++;
            ?>
            <a href="product-view.php?id=<?php echo $sale['id']; ?>" class="sale-card">
                <div class="sale-img">
                    <?php if ($prod_img): ?>
                        <img src="<?php echo htmlspecialchars($prod_img); ?>" alt="<?php echo htmlspecialchars($sale['name']); ?>"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="sale-img-placeholder" style="background:<?php echo $sale_grad; ?>;display:none;">
                            <i class="fas fa-glasses" style="color:<?php echo $sale_icon_color; ?>;"></i>
                        </div>
                    <?php else: ?>
                        <div class="sale-img-placeholder" style="background:<?php echo $sale_grad; ?>;">
                            <i class="fas fa-glasses" style="color:<?php echo $sale_icon_color; ?>;"></i>
                        </div>
                    <?php endif; ?>
                    <div class="sale-badge-pct">-<?php echo $sale['discount_percent']; ?>%</div>
                </div>
                <div class="sale-body">
                    <div class="sale-clinic"><i class="fas fa-clinic-medical"></i> <?php echo htmlspecialchars($sale['clinic_name']); ?></div>
                    <div class="sale-name"><?php echo htmlspecialchars($sale['name']); ?></div>
                    <div class="sale-prices">
                        <span class="sale-price">₱<?php echo number_format($sale['sale_price']); ?></span>
                        <span class="sale-orig">₱<?php echo number_format($sale['price']); ?></span>
                    </div>
                    <div class="sale-timer <?php echo $urgent ? 'urgent' : ''; ?>">
                        <i class="fas fa-hourglass-half"></i>
                        <?php echo $sale['days_left'] == 0 ? 'Last day!' : $sale['days_left'].' days left'; ?>
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <!-- SHOP BY CATEGORY -->
        <div class="sec-head" style="margin-top:10px">
            <h2><i class="fas fa-th" style="color:var(--primary)"></i> Shop by Category</h2>
        </div>

        <?php if (count($dynamic_categories) > 0): ?>
        <div class="cat-grid" id="catGrid">
            <?php foreach ($dynamic_categories as $cat):
                $cat_name = $cat['category'];
                $cat_key  = strtolower($cat_name);
                $count    = $cat['product_count'];
                $isActive = strtolower($active_category) === $cat_key;
                $hover_products = $categories_with_products[$cat_name]['products'] ?? [];
                $icon_info = $category_icons[$cat_key] ?? [
                    'icon' => 'fas fa-tag',
                    'bg'   => 'linear-gradient(150deg,#1a1a2e,#16213e)',
                    'text' => 'rgba(255,255,255,0.9)'
                ];
            ?>
            <div class="category-hover-item">
                <!-- Tile — onclick goes to category page always -->
                <div class="cat-tile <?php echo $isActive ? 'cat-active' : ''; ?>"
                     onclick="filterByCategory('<?php echo addslashes($cat_key); ?>', this)">
                    <div class="cat-img-box" style="background:<?php echo $icon_info['bg']; ?>">
                        <i class="<?php echo $icon_info['icon']; ?>" style="font-size:32px;color:<?php echo $icon_info['text']; ?>;"></i>
                        <?php if ($isActive): ?><div class="cat-active-check"><i class="fas fa-check"></i></div><?php endif; ?>
                    </div>
                    <div class="cat-info">
                        <div class="cat-name"><?php echo htmlspecialchars($cat_name); ?></div>
                        <div class="cat-count"><?php echo $count; ?> items</div>
                    </div>
                </div>

                <!-- Hover dropdown (desktop only, hidden on mobile via CSS) -->
                <div class="category-dropdown">
                    <div class="dropdown-header">
                        <h4><i class="<?php echo $icon_info['icon']; ?>"></i> <?php echo htmlspecialchars($cat_name); ?></h4>
                        <a href="category-products.php?category=<?php echo urlencode($cat_key); ?>">View all <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="dropdown-products">
                        <?php if (count($hover_products) > 0): ?>
                            <?php foreach ($hover_products as $product): ?>
                            <a href="product-view.php?id=<?php echo $product['id']; ?>" class="dropdown-product-item">
                                <div class="dropdown-product-img">
                                    <?php $img_src = getProductImage($product); if ($img_src): ?>
                                        <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <?php else: ?>
                                        <i class="<?php echo $icon_info['icon']; ?>" style="font-size:24px;color:<?php echo $icon_info['text']; ?>;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown-product-info">
                                    <div class="dropdown-product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="dropdown-product-clinic">
                                        <i class="fas fa-clinic-medical"></i> <?php echo htmlspecialchars($product['clinic_name']); ?>
                                    </div>
                                </div>
                                <div class="dropdown-product-price">₱<?php echo number_format($product['price'], 2); ?></div>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dropdown-empty">
                                <i class="fas fa-box-open"></i>
                                <p>No products available yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="margin-bottom:28px;">
            <i class="fas fa-box-open"></i>
            <h3>No categories available</h3>
            <p>Products will appear here once clinics add them.</p>
        </div>
        <?php endif; ?>

        <!-- ALL CLINICS -->
        <div class="sec-head">
            <h2><i class="fas fa-clinic-medical"></i> All Clinics</h2>
        </div>
        <div class="filter-bar">
            <div class="filter-label"><i class="fas fa-filter"></i> Filter by city:</div>
            <select id="cityFilterDropdown" onchange="filterByCityDropdown(this.value)" class="filter-select">
                <option value="all">📍 All Cities in Cavite</option>
                <?php mysqli_data_seek($cities, 0); while ($city = mysqli_fetch_assoc($cities)): ?>
                    <option value="<?php echo htmlspecialchars($city['city']); ?>">🏥 <?php echo htmlspecialchars($city['city']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="results-info">
            <div class="results-count"><i class="fas fa-eye"></i> Showing <span id="visibleCount"><?php echo $total_clinics; ?></span> clinics</div>
        </div>
        <div class="clinics-grid" id="clinicsGrid">
            <?php mysqli_data_seek($clinics, 0); $ci = 0;
            while ($clinic = mysqli_fetch_assoc($clinics)):
                $avg_rating  = round($clinic['avg_rating'], 1);
                $cl_img      = getClinicImg($clinic);
                $cl_grad     = $grads[$ci % count($grads)];
                $cl_icon_color = $icon_colors[$ci % count($icon_colors)];
                $dist = '';
                if (!empty($clinic['latitude']) && !empty($clinic['longitude'])) {
                    $d = calculateDistance($user_lat, $user_lng, $clinic['latitude'], $clinic['longitude']);
                    if ($d !== '') $dist = $d . ' km';
                }
                $clinic_status = isClinicOpen($clinic['hours'] ?? '');
                $status_open   = $clinic_status['open'];   // true, false, or null
                $status_label  = $clinic_status['label'];
                $status_class  = $status_open === true ? 'open' : ($status_open === false ? 'closed' : 'unknown');
                $status_dot_icon = $status_open === true ? 'fa-circle' : ($status_open === false ? 'fa-circle' : 'fa-question-circle');
            ?>
            <div class="clinic-card"
                 data-city="<?php echo htmlspecialchars($clinic['city'] ?? ''); ?>"
                 data-name="<?php echo strtolower(htmlspecialchars($clinic['clinic_name'])); ?>"
                 data-address="<?php echo strtolower(htmlspecialchars($clinic['address'] ?? '')); ?>">
                <div class="clinic-img-area">
                    <?php if ($cl_img): ?>
                        <img src="<?php echo htmlspecialchars($cl_img); ?>" alt="<?php echo htmlspecialchars($clinic['clinic_name']); ?>"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="img-placeholder" style="background:<?php echo $cl_grad; ?>;display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                            <i class="fas fa-clinic-medical" style="font-size:40px;color:<?php echo $cl_icon_color; ?>;opacity:0.7;"></i>
                        </div>
                    <?php else: ?>
                        <div class="img-placeholder" style="background:<?php echo $cl_grad; ?>;width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-clinic-medical" style="font-size:40px;color:<?php echo $cl_icon_color; ?>;opacity:0.7;"></i>
                        </div>
                    <?php endif; ?>
                    <div class="clinic-img-overlay"></div>
                    <!-- Open / Closed badge -->
                    <div class="clinic-status-badge <?php echo $status_class; ?>">
                        <span class="clinic-status-dot"></span>
                        <?php echo htmlspecialchars($status_label); ?>
                    </div>
                    <div class="clinic-badge-rating">
                        <i class="fas fa-star"></i>
                        <?php echo ($avg_rating > 0 ? $avg_rating . ' (' . $clinic['review_count'] . ')' : 'New'); ?>
                    </div>
                    <?php if ($clinic['has_3d'] > 0): ?><div class="clinic-3d-badge">3D View</div><?php endif; ?>
                    <?php if ($dist): ?><div class="clinic-dist-badge"><i class="fas fa-location-dot"></i> <?php echo $dist; ?></div><?php endif; ?>
                </div>
                <div class="clinic-info">
                    <div class="clinic-header">
                        <h3 class="clinic-name"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h3>
                        <?php if ($avg_rating > 0): ?>
                        <span class="clinic-rating-text">★ <?php echo $avg_rating; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="clinic-detail"><i class="fas fa-map-marker-alt"></i><span><?php echo htmlspecialchars($clinic['city'] ?? 'Cavite'); ?></span></div>
                    <div class="clinic-detail"><i class="fas fa-clock"></i><span><?php echo htmlspecialchars($clinic['hours'] ?? 'Hours not set'); ?></span>
                        <span class="clinic-open-tag <?php echo $status_class; ?>">
                            <?php if ($status_open === true): ?>
                                <i class="fas fa-circle" style="font-size:6px;"></i> Open
                            <?php elseif ($status_open === false): ?>
                                <i class="fas fa-circle" style="font-size:6px;"></i> Closed
                            <?php else: ?>
                                <i class="fas fa-question-circle" style="font-size:9px;"></i> N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="clinic-footer">
                        <a href="clinic-details.php?id=<?php echo $clinic['id']; ?>" class="clinic-view-btn"><i class="fas fa-eye"></i> View</a>
                        <a href="book-appointment.php?clinic_id=<?php echo $clinic['id']; ?>" class="clinic-book-btn"><i class="fas fa-calendar-plus"></i> Book</a>
                        <!-- Message button that goes to messages.php with clinic context -->
                        <a href="messages.php?clinic_id=<?php echo $clinic['id']; ?>&clinic_name=<?php echo urlencode($clinic['clinic_name']); ?>" class="clinic-msg-btn" title="Message <?php echo htmlspecialchars($clinic['clinic_name']); ?>">
                            <i class="fas fa-comment-dots"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php $ci++; endwhile; ?>
            <?php if ($total_clinics === 0): ?>
            <div class="no-results-dynamic">
                <i class="fas fa-clinic-medical"></i>
                <h3>No clinics found</h3>
                <p>There are no active clinics yet. Check back soon!</p>
            </div>
            <?php endif; ?>
        </div>
        <div class="pagination" id="pagination"></div>
    </div>

    <!-- APPOINTMENT DETAILS MODAL -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer">
                <button class="modal-btn outline" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>
                <a href="#" class="modal-btn primary" id="modalDirectionsBtn"><i class="fas fa-directions"></i> Directions</a>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
    // ===== TOAST =====
    function showToast(msg, type='success') {
        const c = document.getElementById('toastContainer');
        if (!c) return;
        const t = document.createElement('div');
        t.className = `toast-notification ${type}`;
        const icons = {success:'check-circle', error:'exclamation-circle', info:'info-circle'};
        t.innerHTML = `<i class="fas fa-${icons[type]||'check-circle'}"></i><span>${msg}</span>`;
        c.appendChild(t);
        setTimeout(()=>{ t.style.animation='fadeOut 0.3s ease'; setTimeout(()=>t.remove(),300); }, 3000);
    }

    // ===== FILTER + PAGINATION =====
    const ITEMS_PER_PAGE = 12;
    let curPage=1, curFilter='all', curSearch='';
    const allCards = Array.from(document.querySelectorAll('.clinic-card'));

    function filterClinics() {
        const filtered = allCards.filter(c => {
            const city    = c.dataset.city?.toLowerCase() || '';
            const name    = c.dataset.name || '';
            const address = c.dataset.address || '';
            const cm = curFilter==='all' || city===curFilter.toLowerCase();
            const sm = curSearch==='' || name.includes(curSearch) || address.includes(curSearch) || city.includes(curSearch);
            return cm && sm;
        });
        const visibleSpan = document.getElementById('visibleCount');
        if (visibleSpan) visibleSpan.textContent = filtered.length;
        updatePagination(filtered.length);
        showPage(filtered);
        toggleNoResults(filtered.length);
    }
    function showPage(filtered) {
        const start = (curPage-1)*ITEMS_PER_PAGE;
        allCards.forEach(c=>c.style.display='none');
        filtered.slice(start, start+ITEMS_PER_PAGE).forEach(c=>c.style.display='block');
    }
    function updatePagination(total) {
        const pages = Math.ceil(total/ITEMS_PER_PAGE);
        const div = document.getElementById('pagination');
        if (!div) return;
        if (pages<=1) { div.classList.add('hidden'); return; }
        div.classList.remove('hidden');
        if (curPage>pages) curPage=pages;
        let html='';
        if (curPage>1) html+=`<a href="#" onclick="changePage(${curPage-1});return false;"><i class="fas fa-chevron-left"></i></a>`;
        for (let i=1;i<=pages;i++) html+=i===curPage?`<span class="active">${i}</span>`:`<a href="#" onclick="changePage(${i});return false;">${i}</a>`;
        if (curPage<pages) html+=`<a href="#" onclick="changePage(${curPage+1});return false;"><i class="fas fa-chevron-right"></i></a>`;
        div.innerHTML=html;
    }
    function changePage(p) { curPage=p; filterClinics(); document.querySelector('.clinics-grid')?.scrollIntoView({behavior:'smooth'}); }
    function filterByCityDropdown(city) { curFilter=city; curPage=1; filterClinics(); showToast(`Filtered: ${city==='all'?'All cities':city}`,'info'); }
    function toggleNoResults(count) {
        let msg=document.querySelector('.no-results-dynamic');
        if (count===0 && allCards.length>0) {
            if (!msg) { msg=document.createElement('div'); msg.className='no-results-dynamic'; msg.innerHTML=`<i class="fas fa-search"></i><h3>No clinics found</h3><p>Try adjusting your search or filter</p><button onclick="clearFilters()" class="clear-btn">Clear Filters</button>`; document.getElementById('clinicsGrid').appendChild(msg); }
        } else if (msg && allCards.length>0) msg.remove();
    }
    function clearFilters() {
        const cityFilter = document.getElementById('cityFilterDropdown');
        if (cityFilter) cityFilter.value='all';
        curSearch=''; curFilter='all'; curPage=1; filterClinics(); showToast('Filters cleared','success');
    }

    // ===== APPOINTMENT MODAL =====
    function showApptDetails(appt) {
        const modalBtn = document.getElementById('modalDirectionsBtn');
        if (modalBtn) modalBtn.href = 'directions.php?clinic='+appt.clinic_id;
        const d = new Date(appt.appointment_date+'T'+appt.appointment_time);
        const fDate = d.toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'});
        const fTime = d.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',hour12:true});
        let docHtml='', prodHtml='';
        if (appt.doctor_name) {
            docHtml=`<div class="detail-section"><h4><i class="fas fa-user-md"></i> Doctor</h4><div class="doctor-info"><div class="doctor-avatar"><i class="fas fa-user-md"></i></div><div><div class="doctor-name">Dr. ${appt.doctor_name}</div><div class="doctor-specialty">${appt.doctor_specialty||'Optometrist'}</div></div><span class="doctor-badge">Assigned</span></div></div>`;
        }
        if (appt.product_name) {
            prodHtml=`<div class="detail-section"><h4><i class="fas fa-box"></i> Service</h4><div class="detail-card"><div class="detail-row"><div class="detail-icon"><i class="fas fa-glasses"></i></div><div class="detail-content"><div class="detail-label">Product</div><div class="detail-value">${appt.product_name}</div></div><div style="font-weight:800;color:var(--primary);font-size:14px;">₱${parseFloat(appt.product_price||0).toLocaleString()}</div></div></div></div>`;
        }
        const modalBody = document.getElementById('modalBody');
        if (modalBody) {
            modalBody.innerHTML=`
                <div class="detail-section"><h4><i class="fas fa-clinic-medical"></i> Clinic</h4>
                <div class="detail-card">
                    <div class="detail-row"><div class="detail-icon"><i class="fas fa-building"></i></div><div class="detail-content"><div class="detail-label">Name</div><div class="detail-value">${appt.clinic_name}</div></div></div>
                    <div class="detail-row"><div class="detail-icon"><i class="fas fa-map-marker-alt"></i></div><div class="detail-content"><div class="detail-label">Address</div><div class="detail-value">${appt.address||'—'}</div></div></div>
                    ${appt.clinic_contact?`<div class="detail-row"><div class="detail-icon"><i class="fas fa-phone"></i></div><div class="detail-content"><div class="detail-label">Contact</div><div class="detail-value">${appt.clinic_contact}</div></div></div>`:''}
                </div></div>
                ${docHtml}
                <div class="detail-section"><h4><i class="fas fa-clock"></i> Schedule</h4>
                <div class="detail-card">
                    <div class="detail-row"><div class="detail-icon"><i class="fas fa-calendar"></i></div><div class="detail-content"><div class="detail-label">Date</div><div class="detail-value">${fDate}</div></div></div>
                    <div class="detail-row"><div class="detail-icon"><i class="fas fa-clock"></i></div><div class="detail-content"><div class="detail-label">Time</div><div class="detail-value">${fTime}</div></div></div>
                    <div class="detail-row"><div class="detail-icon"><i class="fas fa-tag"></i></div><div class="detail-content"><div class="detail-label">Status</div><div class="detail-value"><span class="appointment-status status-${appt.status}">${appt.status.charAt(0).toUpperCase()+appt.status.slice(1)}</span></div></div></div>
                </div></div>
                ${prodHtml}
                ${appt.notes?`<div class="detail-section"><h4><i class="fas fa-sticky-note"></i> Notes</h4><div class="detail-card"><p style="font-size:13px;color:var(--text-secondary);line-height:1.6;">${appt.notes}</p></div></div>`:''}
            `;
        }
        const modal = document.getElementById('detailsModal');
        if (modal) modal.classList.add('show');
    }
    function closeModal() {
        const modal = document.getElementById('detailsModal');
        if (modal) modal.classList.remove('show');
    }
    window.addEventListener('click', e=>{ if (e.target===document.getElementById('detailsModal')) closeModal(); });

    // ===== CATEGORY FILTER =====
    function filterByCategory(category, el) {
        const isMobile = window.innerWidth <= 768;

        if (isMobile) {
            // Mobile: one tap = direct redirect, no dropdown
            window.location.href = 'category-products.php?category=' + encodeURIComponent(category);
            return;
        }

        // Desktop: highlight active tile + redirect
        document.querySelectorAll('.cat-tile').forEach(t => {
            t.classList.remove('cat-active');
            const check = t.querySelector('.cat-active-check');
            if (check) check.remove();
        });
        el.classList.add('cat-active');
        if (!el.querySelector('.cat-active-check')) {
            const div = document.createElement('div');
            div.className = 'cat-active-check';
            div.innerHTML = '<i class="fas fa-check"></i>';
            el.querySelector('.cat-img-box').appendChild(div);
        }

        const url = new URL(window.location);
        url.searchParams.set('category', category);
        window.history.replaceState({}, '', url);

        window.location.href = 'category-products.php?category=' + encodeURIComponent(category);
    }

    // ===== INIT =====
    document.addEventListener('DOMContentLoaded', () => {
        filterClinics();
        const fill = document.querySelector('.wh-pts-fill');
        if (fill) { const w=fill.style.width; fill.style.width='0'; setTimeout(()=>fill.style.width=w, 300); }
        setTimeout(()=>showToast('Welcome back! 👋','success'), 600);

        // Scroll to cat grid if category param present
        const params = new URLSearchParams(window.location.search);
        if (params.get('category')) {
            setTimeout(() => {
                document.getElementById('catGrid')?.scrollIntoView({behavior:'smooth', block:'start'});
            }, 400);
        }
    });

    // Browser geolocation
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            sessionStorage.setItem('user_lat', pos.coords.latitude);
            sessionStorage.setItem('user_lng', pos.coords.longitude);
        }, ()=>{});
    }
    </script>
</body>
</html>