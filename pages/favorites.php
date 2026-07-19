<?php
include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ===== NAVBAR VARIABLES - LAHAT NG KAILANGAN =====
// Get user info
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Total bookings for navbar
$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?? 0;

// Pending appointments count for navbar
$pending_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending_result = mysqli_fetch_assoc($pending_count_query);
$pending = $pending_result['total'] ?? 0;

// Total points for navbar
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?? 0;

// Unread notifications for navbar
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

// Sale count for navbar
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

// ============================================
// HELPER: Clinic Image Path
// ============================================
function getClinicImg($c) {
    if (!empty($c['cover_photo']))  return '/assets/images/clinic-covers/' . $c['cover_photo'];
    if (!empty($c['clinic_image'])) return '/assets/images/clinic-images/' . $c['clinic_image'];
    if (!empty($c['logo']))         return '/assets/images/clinic-logos/' . $c['logo'];
    if (!empty($c['clinic_logo'])) {
        $l = $c['clinic_logo'];
        if (strpos($l, 'uploads/') !== false) return '/' . $l;
        if (strpos($l, 'clinic_') !== false)  return '/assets/images/clinic-logos/' . $l;
        return '/assets/images/clinic-logos/' . $l;
    }
    return null;
}

// Gradient fallback colors
$grads = [
    ['bg'=>'linear-gradient(135deg,#d1fae5,#6ee7b7)','text'=>'#065f46'],
    ['bg'=>'linear-gradient(135deg,#dbeafe,#93c5fd)','text'=>'#1e40af'],
    ['bg'=>'linear-gradient(135deg,#fce7f3,#f9a8d4)','text'=>'#9d174d'],
    ['bg'=>'linear-gradient(135deg,#ede9fe,#c4b5fd)','text'=>'#4c1d95'],
    ['bg'=>'linear-gradient(135deg,#fef9c3,#fde047)','text'=>'#78350f'],
    ['bg'=>'linear-gradient(135deg,#ccfbf1,#5eead4)','text'=>'#134e4e'],
];

function isClinicOpen($hours) {
    if (empty($hours)) return false;
    $current_time = date('H:i');
    $current_day = date('D');
    if (stripos($hours, '24/7') !== false || stripos($hours, '24 hours') !== false) return true;
    $hours = strtolower($hours);
    $hours = str_replace([' ', ':', 'am', 'pm'], ['', '', ' am', ' pm'], $hours);
    $schedules = explode(',', $hours);
    foreach ($schedules as $schedule) {
        $schedule = trim($schedule);
        if (strpos($schedule, 'closed') !== false) continue;
        if (preg_match('/([a-z]{3})-([a-z]{3})?\s*([0-9]+[amp\s]+-[0-9]+[amp\s]+)/i', $schedule, $matches)) {
            if (isDayInRange($current_day, $matches[1], $matches[2] ?? $matches[1])) {
                if (isTimeInRange($matches[3], $current_time)) return true;
            }
        } elseif (preg_match('/(mon-sun|daily|everyday)\s+([0-9]+[amp\s]+-[0-9]+[amp\s]+)/i', $schedule, $matches)) {
            if (isTimeInRange($matches[2], $current_time)) return true;
        } elseif (preg_match('/([0-9]+[amp\s]+)-([0-9]+[amp\s]+)/i', $schedule, $matches)) {
            if (isTimeInRange($matches[1].'-'.$matches[2], $current_time)) return true;
        }
    }
    return false;
}
function isDayInRange($current_day, $start_day, $end_day) {
    $days = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7];
    $c = $days[strtolower(substr($current_day,0,3))] ?? 0;
    $s = $days[strtolower(substr($start_day,0,3))] ?? 0;
    $e = $days[strtolower(substr($end_day,0,3))] ?? 0;
    if ($s <= $e) return ($c >= $s && $c <= $e);
    return ($c >= $s || $c <= $e);
}
function isTimeInRange($time_range, $current_time) {
    if (preg_match('/([0-9]+[amp\s]+)-([0-9]+[amp\s]+)/i', $time_range, $m)) {
        $s = convertTo24Hour(trim($m[1]));
        $e = convertTo24Hour(trim($m[2]));
        if ($e < $s) return ($current_time >= $s || $current_time <= $e);
        return ($current_time >= $s && $current_time <= $e);
    }
    return false;
}
function convertTo24Hour($time_str) {
    $time_str = strtolower(trim($time_str));
    if (preg_match('/([0-9]+)([amp]+)?/i', $time_str, $m)) {
        $hour = (int)$m[1];
        $ampm = $m[2] ?? '';
        if ($ampm == 'pm' && $hour < 12) $hour += 12;
        elseif ($ampm == 'am' && $hour == 12) $hour = 0;
        return sprintf('%02d:00', $hour);
    }
    return '00:00';
}

// ============================================
// HANDLE REMOVE FROM FAVORITES
// ============================================
if (isset($_GET['remove'])) {
    $remove_id = (int)$_GET['remove'];
    $clinic_query = mysqli_query($conn, "SELECT name FROM clinics WHERE id = $remove_id");
    $clinic = mysqli_fetch_assoc($clinic_query);
    $clinic_name = $clinic['name'];
    $result = mysqli_query($conn, "DELETE FROM favorites WHERE user_id = $user_id AND clinic_id = $remove_id");
    if ($result) {
        addNotification($user_id, 'favorite', 'Clinic Removed from Favorites ❌', "You removed $clinic_name from your favorites.", 'favorites.php');
    }
    header('Location: favorites.php?removed=1');
    exit();
}

// ============================================
// HANDLE ADD TO FAVORITES
// ============================================
if (isset($_GET['add'])) {
    $clinic_id = (int)$_GET['add'];
    $check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND clinic_id = $clinic_id");
    if (mysqli_num_rows($check) == 0) {
        $clinic_query = mysqli_query($conn, "SELECT name FROM clinics WHERE id = $clinic_id");
        $clinic = mysqli_fetch_assoc($clinic_query);
        $clinic_name = $clinic['name'];
        $add = mysqli_query($conn, "INSERT INTO favorites (user_id, clinic_id, created_at) VALUES ($user_id, $clinic_id, NOW())");
        if ($add) {
            addNotification($user_id, 'favorite', 'New Favorite Clinic ❤️', "You added $clinic_name to your favorites.", "clinic-details.php?id=$clinic_id");
            addNotification($user_id, 'promo', 'Stay Updated 🔔', "You'll now receive updates and promos from $clinic_name.", "clinic-products.php?clinic_id=$clinic_id");
        }
    }
    header('Location: favorites.php?added=1');
    exit();
}

// ============================================
// GET FAVORITE CLINICS
// ============================================
$favorites_query = mysqli_query($conn, "
    SELECT c.*, 
           f.created_at as favorited_date,
           COALESCE(AVG(r.rating), 0) as avg_rating,
           COUNT(r.id) as review_count,
           (SELECT COUNT(*) FROM appointments WHERE clinic_id = c.id AND user_id = $user_id) as visit_count,
           (SELECT MAX(appointment_date) FROM appointments WHERE clinic_id = c.id AND user_id = $user_id) as last_visit,
           (SELECT COUNT(*) FROM favorites WHERE clinic_id = c.id) as total_favorites
    FROM favorites f
    JOIN clinics c ON f.clinic_id = c.id
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id
    WHERE f.user_id = $user_id AND f.clinic_id IS NOT NULL
    GROUP BY c.id
    ORDER BY f.created_at DESC
");

// ============================================
// GET FAVORITE PRODUCTS
// ============================================
$fav_products_query = mysqli_query($conn, "
    SELECT p.*,
           f.created_at as favorited_date,
           c.name as clinic_name,
           c.city as clinic_city,
           c.id as clinic_id,
           (SELECT SUM(quantity) FROM product_color_inventory
            WHERE product_id = p.id AND clinic_id = p.clinic_id AND quantity > 0 AND is_available = 1) as total_stock
    FROM favorites f
    JOIN products p ON f.product_id = p.id
    JOIN clinics c ON p.clinic_id = c.id
    WHERE f.user_id = $user_id AND f.product_id IS NOT NULL
    ORDER BY f.created_at DESC
");
$total_fav_products = mysqli_num_rows($fav_products_query);
$fav_products_list  = [];
while ($fp = mysqli_fetch_assoc($fav_products_query)) {
    $fav_products_list[] = $fp;
}

// Get price ranges from services and products
$price_ranges = [];
$temp_query = mysqli_query($conn, "
    SELECT clinic_id, MIN(price) as min_price, MAX(price) as max_price 
    FROM services
    WHERE clinic_id IN (SELECT clinic_id FROM favorites WHERE user_id = $user_id)
    GROUP BY clinic_id
");
while($price = mysqli_fetch_assoc($temp_query)) {
    $price_ranges[$price['clinic_id']] = $price;
}
$temp2 = mysqli_query($conn, "
    SELECT clinic_id, MIN(price) as min_price, MAX(price) as max_price 
    FROM products 
    WHERE clinic_id IN (SELECT clinic_id FROM favorites WHERE user_id = $user_id)
    GROUP BY clinic_id
");
while($price = mysqli_fetch_assoc($temp2)) {
    if (!isset($price_ranges[$price['clinic_id']])) {
        $price_ranges[$price['clinic_id']] = $price;
    }
}

// Get service categories per clinic
$service_cats = [];
$svc_q = mysqli_query($conn, "
    SELECT DISTINCT clinic_id, category 
    FROM services 
    WHERE clinic_id IN (SELECT clinic_id FROM favorites WHERE user_id = $user_id)
    ORDER BY clinic_id, category
");
while($svc = mysqli_fetch_assoc($svc_q)) {
    $service_cats[$svc['clinic_id']][] = $svc['category'];
}

$total_favorites = mysqli_num_rows($favorites_query);

// Stats
$cities_query = mysqli_query($conn, "SELECT COUNT(DISTINCT city) as total FROM favorites f JOIN clinics c ON f.clinic_id = c.id WHERE f.user_id = $user_id");
$cities_count = mysqli_fetch_assoc($cities_query)['total'] ?? 0;

$avg_query = mysqli_query($conn, "
    SELECT AVG(r.rating) as avg 
    FROM favorites f JOIN clinics c ON f.clinic_id = c.id 
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id 
    WHERE f.user_id = $user_id
");
$avg = mysqli_fetch_assoc($avg_query)['avg'] ?? 0;

$appointments_in_fav_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE clinic_id IN (SELECT clinic_id FROM favorites WHERE user_id = $user_id) AND user_id = $user_id");
$appts_in_fav = mysqli_fetch_assoc($appointments_in_fav_query)['total'] ?? 0;

// Build JS-accessible clinics array
$clinic_coords = [];
mysqli_data_seek($favorites_query, 0);
while($c = mysqli_fetch_assoc($favorites_query)) {
    $clinic_coords[] = ['id'=>$c['id'], 'lat'=>$c['latitude'], 'lng'=>$c['longitude']];
}
mysqli_data_seek($favorites_query, 0);

// ============================================
// SET ACTIVE NAV FOR NAVBAR
// ============================================
$active_nav = 'favorites';

// ============================================
// INCLUDE THE SHARED NAVBAR
// ============================================
include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Favorite Clinics — Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { width:100%; overflow-x:hidden; background:var(--bg-primary); }
        body { min-height:100vh; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; transition:background-color 0.3s,color 0.3s; }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --secondary: #FF8C42;
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            --shadow-hover: 0 20px 40px -10px rgba(0,183,97,0.25);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
            --info: #3B82F6;
            --success: #10B981;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A; --card-bg: #242424;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
        }

        .main-content { background:var(--bg-primary); min-height:100vh; }
        .content-wrapper { max-width:1400px; margin:0 auto; padding:30px 20px; }
        @media(min-width:1024px){ .content-wrapper { padding:32px 40px; } }
        @media(max-width:768px){ .content-wrapper { padding:20px 16px 110px; } }

        .alert-banner { padding:14px 20px; border-radius:var(--radius-md); margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:500; animation:fadeOut 4s forwards; }
        .alert-banner.success { background:var(--primary-light); color:var(--primary-dark); border:1px solid #a7f3d0; }
        .alert-banner.info { background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; }
        @keyframes fadeOut { 0%{opacity:1} 80%{opacity:1} 100%{opacity:0;display:none} }

        .page-header { margin-bottom:28px; }
        .page-header-top { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .page-title { display:flex; align-items:center; gap:12px; }
        .page-title-icon { width:48px; height:48px; background:rgba(239,68,68,0.1); border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center; color:var(--danger); font-size:22px; }
        .page-title h1 { font-size:26px; font-weight:800; color:var(--text-primary); }
        .page-title p { font-size:13px; color:var(--text-muted); margin-top:2px; }

        .stats-strip { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:28px; }
        @media(max-width:900px){ .stats-strip { grid-template-columns:repeat(2,1fr); } }
        .stat-card { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:18px; display:flex; align-items:center; gap:14px; border:1px solid var(--border-light); transition:all 0.25s; }
        .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
        .stat-icon { width:46px; height:46px; border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .stat-icon.fav { background:rgba(239,68,68,0.1); color:var(--danger); }
        .stat-icon.city { background:rgba(0,183,97,0.1); color:var(--primary); }
        .stat-icon.star { background:rgba(245,158,11,0.1); color:var(--warning); }
        .stat-icon.appt { background:rgba(59,130,246,0.1); color:var(--info); }
        .stat-info h3 { font-size:24px; font-weight:800; color:var(--text-primary); line-height:1; }
        .stat-info p { font-size:12px; color:var(--text-muted); margin-top:3px; font-weight:500; }

        .controls-bar { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:16px 20px; border:1px solid var(--border-light); margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
        .search-wrap { flex:2; min-width:200px; display:flex; align-items:center; gap:8px; background:var(--bg-primary); border-radius:var(--radius-full); padding:10px 16px; border:1.5px solid var(--border-color); }
        .search-wrap input { border:none; background:none; outline:none; font-size:14px; color:var(--text-primary); flex:1; }
        .sort-wrap { flex:1; min-width:150px; }
        .sort-wrap select { width:100%; padding:10px 14px; border:1.5px solid var(--border-color); border-radius:var(--radius-full); font-size:14px; background:var(--bg-primary); color:var(--text-primary); cursor:pointer; }

        .filter-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
        .filter-tab { padding:8px 18px; background:var(--bg-secondary); border:1.5px solid var(--border-color); border-radius:var(--radius-full); cursor:pointer; transition:all 0.2s; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:7px; color:var(--text-secondary); text-decoration:none; }
        .filter-tab:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-light); }
        .filter-tab.active { background:var(--primary-gradient); color:white; border-color:transparent; }
        .filter-tab.fire.active { background:linear-gradient(135deg,#ef4444,#f97316); }

        .favorites-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:20px; margin-bottom:30px; }
        @media(max-width:768px){ .favorites-grid { grid-template-columns:1fr; gap:16px; } }

        .favorite-card { background:var(--card-bg); border-radius:var(--radius-xl); overflow:hidden; transition:all 0.3s; border:1px solid var(--border-light); position:relative; }
        .favorite-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-hover); border-color:transparent; }

        .card-image { position:relative; height:180px; overflow:hidden; }
        .card-image img { width:100%; height:100%; object-fit:cover; transition:transform 0.4s; }
        .favorite-card:hover .card-image img { transform:scale(1.05); }
        .card-image-placeholder { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; font-size:13px; font-weight:600; }
        .card-image-placeholder .initials { font-size:42px; font-weight:800; line-height:1; }

        .card-badges { position:absolute; top:12px; left:12px; right:12px; display:flex; gap:6px; pointer-events:none; }
        .badge-pill { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:var(--radius-full); font-size:11px; font-weight:700; backdrop-filter:blur(8px); }
        .badge-pill.open { background:rgba(16,185,129,0.9); color:white; }
        .badge-pill.closed { background:rgba(239,68,68,0.9); color:white; }
        .badge-pill.popular { background:rgba(245,158,11,0.9); color:white; }

        .clinic-logo-badge { position:absolute; bottom:-18px; left:16px; width:52px; height:52px; border-radius:var(--radius-md); overflow:hidden; border:3px solid var(--card-bg); box-shadow:var(--shadow-md); background:var(--bg-secondary); }
        .clinic-logo-badge .logo-initials { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:800; }

        .card-body { padding:28px 18px 18px; }
        .clinic-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; }
        .clinic-name-wrap { flex:1; padding-right:10px; }
        .clinic-name { font-size:17px; font-weight:700; color:var(--text-primary); line-height:1.2; margin-bottom:3px; }
        .clinic-location { display:flex; align-items:center; gap:5px; color:var(--text-muted); font-size:12px; }
        .clinic-location i { color:var(--primary); font-size:11px; }
        .rating-badge { display:flex; align-items:center; gap:4px; background:#FEF3C7; color:#92400E; padding:5px 10px; border-radius:var(--radius-full); font-size:13px; font-weight:700; }

        .info-rows { margin:12px 0; display:flex; flex-direction:column; gap:6px; }
        .info-row { display:flex; align-items:center; gap:9px; font-size:13px; color:var(--text-secondary); }
        .info-row i { color:var(--primary); font-size:13px; width:16px; text-align:center; }
        .info-row .distance-tag { color:var(--info); font-weight:600; font-size:12px; }

        .service-tags { display:flex; flex-wrap:wrap; gap:6px; margin:12px 0 14px; }
        .service-tag { padding:4px 10px; border-radius:var(--radius-full); font-size:11px; font-weight:600; background:var(--primary-light); color:var(--primary-dark); }

        .card-footer { border-top:1px solid var(--border-light); padding-top:14px; margin-top:4px; }
        .saved-on { font-size:11px; color:var(--text-muted); display:flex; align-items:center; gap:5px; margin-bottom:12px; }
        .saved-on i { color:var(--danger); }

        .card-actions { display:flex; gap:8px; align-items:center; }
        .btn-view { flex:1; padding:10px; background:var(--bg-primary); border:1.5px solid var(--border-color); color:var(--text-primary); border-radius:var(--radius-md); font-size:13px; font-weight:600; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; }
        .btn-view:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-light); }
        .btn-book { flex:2; padding:10px; background:var(--primary-gradient); color:white; border:none; border-radius:var(--radius-md); font-size:13px; font-weight:700; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; }
        .btn-book:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(0,183,97,0.35); }
        .btn-icon { width:38px; height:38px; display:flex; align-items:center; justify-content:center; border:1.5px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-primary); color:var(--text-muted); cursor:pointer; }

        .kebab-wrap { position:relative; }
        .kebab-menu { position:absolute; bottom:calc(100% + 8px); right:0; min-width:140px; background:var(--bg-secondary); border-radius:var(--radius-lg); box-shadow:var(--shadow-lg); border:1px solid var(--border-light); padding:6px; z-index:50; display:none; }
        .kebab-menu.show { display:block; }
        .kebab-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); font-size:13px; cursor:pointer; color:var(--text-secondary); text-decoration:none; width:100%; background:none; border:none; }
        .kebab-item:hover { background:var(--bg-primary); color:var(--text-primary); }
        .kebab-item.danger { color:var(--danger); }

        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.show { display:flex; }
        .modal-container { background:var(--bg-secondary); border-radius:var(--radius-xl); padding:32px; max-width:420px; width:90%; box-shadow:var(--shadow-lg); }
        .modal-header { text-align:center; margin-bottom:20px; }
        .modal-header i { font-size:48px; color:var(--danger); margin-bottom:12px; display:block; }
        .modal-header h3 { font-size:20px; font-weight:700; color:var(--text-primary); }
        .modal-body { text-align:center; margin-bottom:24px; color:var(--text-secondary); font-size:15px; }
        .modal-footer { display:flex; gap:12px; }
        .modal-btn { flex:1; padding:13px; border-radius:var(--radius-md); font-size:15px; font-weight:600; cursor:pointer; border:none; }
        .modal-btn.cancel { background:var(--bg-primary); color:var(--text-secondary); }
        .modal-btn.confirm { background:var(--danger); color:white; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px; }

        .empty-state { text-align:center; padding:80px 20px; }
        .empty-state i { font-size:64px; color:var(--border-color); margin-bottom:20px; display:block; }
        .empty-state h2 { font-size:22px; font-weight:700; color:var(--text-primary); margin-bottom:10px; }
        .empty-state p { color:var(--text-muted); margin-bottom:24px; font-size:15px; }
        .btn-browse { display:inline-flex; align-items:center; gap:8px; background:var(--primary-gradient); color:white; padding:13px 28px; border-radius:var(--radius-full); font-weight:700; text-decoration:none; }

        .toast { position:fixed; bottom:80px; left:50%; transform:translateX(-50%) translateY(20px); background:var(--text-primary); color:var(--bg-secondary); padding:12px 20px; border-radius:var(--radius-full); font-size:14px; font-weight:600; z-index:9999; opacity:0; transition:all 0.3s; }
        .toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast.success { background:var(--success); color:white; }

        .results-header { display:flex; justify-content:space-between; margin-bottom:16px; }
        .results-count { font-size:14px; color:var(--text-muted); }
        .results-count strong { color:var(--text-primary); }

        /* ===== MAIN TAB SWITCHER (Clinics / Products) ===== */
        .main-tab-switcher {
            display: flex;
            gap: 0;
            background: var(--bg-secondary);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-full);
            padding: 4px;
            width: fit-content;
            margin-bottom: 28px;
        }
        .main-tab-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: var(--radius-full);
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.2s;
            font-family: inherit;
            white-space: nowrap;
        }
        .main-tab-btn .tab-badge {
            background: var(--border-color);
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: var(--radius-full);
            transition: all 0.2s;
        }
        .main-tab-btn.active {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0,183,97,0.3);
        }
        .main-tab-btn.active .tab-badge { background: rgba(255,255,255,0.25); color: white; }
        .main-tab-btn:not(.active):hover { color: var(--primary); }

        /* ===== PRODUCTS TAB CONTENT ===== */
        .fav-products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }
        @media(max-width:640px) { .fav-products-grid { grid-template-columns: repeat(2,1fr); gap:12px; } }

        .fav-product-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            border: 1.5px solid var(--border-light);
            overflow: hidden;
            transition: all 0.2s;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .fav-product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: var(--primary); }

        .fav-product-img {
            position: relative;
            width: 100%;
            height: 160px;
            overflow: hidden;
            background: var(--bg-primary);
        }
        .fav-product-img img { width:100%; height:100%; object-fit:cover; transition: transform 0.3s; }
        .fav-product-card:hover .fav-product-img img { transform: scale(1.04); }

        .fav-product-cat {
            position: absolute;
            bottom: 8px; left: 8px;
            background: rgba(0,0,0,0.55);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(4px);
        }
        .fav-product-out {
            position: absolute;
            top: 8px; left: 8px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: var(--radius-full);
        }
        .btn-unfav-product {
            position: absolute;
            top: 8px; right: 8px;
            width: 30px; height: 30px;
            border-radius: 50%;
            background: rgba(255,255,255,0.92);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transition: all 0.2s;
            backdrop-filter: blur(4px);
        }
        .btn-unfav-product i { font-size: 13px; color: #EF4444; transition: all 0.2s; }
        .btn-unfav-product:hover { transform: scale(1.15); background: white; }
        .fav-product-body { padding: 12px 14px 14px; flex: 1; display: flex; flex-direction: column; }
        .fav-product-name { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; line-height: 1.3; display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
        .fav-product-clinic { font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; display:flex; align-items:center; gap:4px; }
        .fav-product-price { font-size: 16px; font-weight: 700; color: var(--primary); margin-top: auto; }
        .fav-product-price.sale { color: #EF4444; }
        .fav-product-price .orig { font-size:11px; color:var(--text-muted); text-decoration:line-through; font-weight:400; margin-left:4px; }
        .fav-product-actions { display:flex; gap:8px; margin-top:10px; }
        .fav-product-actions a {
            flex: 1; text-align:center; padding:8px 0;
            border-radius: var(--radius-full);
            font-size: 12px; font-weight: 600;
            text-decoration: none; transition: all 0.2s;
        }
        .btn-view-prod { background: var(--primary-light); color: var(--primary); }
        .btn-view-prod:hover { background: var(--primary); color: white; }

        /* Product remove confirm modal */
        #removeProductModal .modal-container { max-width: 360px; }

        /* Tab panel visibility */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>

<div class="main-content">
    <div class="content-wrapper">

        <?php if (isset($_GET['removed'])): ?>
        <div class="alert-banner info"><i class="fas fa-check-circle"></i> Clinic removed from your favorites.</div>
        <?php endif; ?>
        <?php if (isset($_GET['added'])): ?>
        <div class="alert-banner success"><i class="fas fa-heart"></i> Clinic added to your favorites!</div>
        <?php endif; ?>

        <div class="page-header">
            <div class="page-header-top">
                <div class="page-title">
                    <div class="page-title-icon"><i class="fas fa-heart"></i></div>
                    <div>
                        <h1>My Favorites</h1>
                        <p><?php echo $total_favorites; ?> clinic<?php echo $total_favorites != 1 ? 's' : ''; ?> · <?php echo $total_fav_products; ?> product<?php echo $total_fav_products != 1 ? 's' : ''; ?> saved</p>
                    </div>
                </div>
                <?php if ($total_favorites > 0): ?>
                <button class="btn-view" id="exportBtn" onclick="exportFavorites()" style="padding:10px 18px; display:none;"><i class="fas fa-download"></i> Export</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-strip">
            <div class="stat-card"><div class="stat-icon fav"><i class="fas fa-heart"></i></div><div class="stat-info"><h3><?php echo $total_favorites; ?></h3><p>Saved Clinics</p></div></div>
            <div class="stat-card"><div class="stat-icon city"><i class="fas fa-map-marker-alt"></i></div><div class="stat-info"><h3><?php echo $cities_count; ?></h3><p>Cities Covered</p></div></div>
            <div class="stat-card"><div class="stat-icon star"><i class="fas fa-star"></i></div><div class="stat-info"><h3><?php echo $avg ? number_format($avg, 1) : '—'; ?></h3><p>Avg. Rating</p></div></div>
            <div class="stat-card"><div class="stat-icon appt"><i class="fas fa-box"></i></div><div class="stat-info"><h3><?php echo $total_fav_products; ?></h3><p>Saved Products</p></div></div>
        </div>

        <!-- ===== MAIN TAB SWITCHER ===== -->
        <div class="main-tab-switcher">
            <button class="main-tab-btn active" id="tabBtnClinics" onclick="switchMainTab('clinics')">
                <i class="fas fa-clinic-medical"></i> Clinics
                <span class="tab-badge"><?php echo $total_favorites; ?></span>
            </button>
            <button class="main-tab-btn" id="tabBtnProducts" onclick="switchMainTab('products')">
                <i class="fas fa-glasses"></i> Products
                <span class="tab-badge"><?php echo $total_fav_products; ?></span>
            </button>
        </div>

        <!-- ===== CLINICS TAB ===== -->
        <div class="tab-panel active" id="panelClinics">

        <?php if ($total_favorites > 0): ?>

        <div class="controls-bar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search clinic..." onkeyup="applyFilter()">
            </div>
            <div class="sort-wrap">
                <select id="sortSelect" onchange="applyFilter()">
                    <option value="recent">Most Recent</option>
                    <option value="rating">Highest Rated</option>
                    <option value="name">A – Z</option>
                    <option value="popular">Most Popular</option>
                </select>
            </div>
        </div>

        <div class="filter-tabs">
            <a href="javascript:void(0)" onclick="setFilter('all',this)" class="filter-tab active" data-filter="all"><i class="fas fa-list"></i> All</a>
            <a href="javascript:void(0)" onclick="setFilter('open',this)" class="filter-tab" data-filter="open"><i class="fas fa-door-open"></i> Open Now</a>
            <a href="javascript:void(0)" onclick="setFilter('rated',this)" class="filter-tab" data-filter="rated"><i class="fas fa-star"></i> 4+ Rated</a>
            <a href="javascript:void(0)" onclick="setFilter('popular',this)" class="filter-tab fire" data-filter="popular"><i class="fas fa-fire"></i> Popular</a>
        </div>

        <div class="results-header">
            <div class="results-count" id="resultsCount">Showing <strong><?php echo $total_favorites; ?></strong> clinic<?php echo $total_favorites != 1 ? 's' : ''; ?></div>
        </div>

        <div class="favorites-grid" id="favoritesGrid">
            <?php 
            $card_idx = 0;
            mysqli_data_seek($favorites_query, 0);
            while($clinic = mysqli_fetch_assoc($favorites_query)):
                $is_open = isClinicOpen($clinic['hours']);
                $price_range = $price_ranges[$clinic['id']] ?? null;
                $avg_rating  = round($clinic['avg_rating'], 1);
                $clinic_img  = getClinicImg($clinic);
                $svc_list    = $service_cats[$clinic['id']] ?? [];
                $grad        = $grads[$card_idx % count($grads)];
                $name_initial = strtoupper(substr($clinic['name'], 0, 1));
                $card_idx++;
            ?>
            <div class="favorite-card"
                 data-id="<?php echo $clinic['id']; ?>"
                 data-rating="<?php echo $avg_rating; ?>"
                 data-name="<?php echo htmlspecialchars(strtolower($clinic['name'])); ?>"
                 data-date="<?php echo $clinic['favorited_date']; ?>"
                 data-popular="<?php echo $clinic['total_favorites']; ?>"
                 data-status="<?php echo $is_open ? 'open' : 'closed'; ?>"
                 data-lat="<?php echo $clinic['latitude'] ?? ''; ?>"
                 data-lng="<?php echo $clinic['longitude'] ?? ''; ?>">

                <div class="card-image">
                    <?php if ($clinic_img): ?>
                        <img src="<?php echo $clinic_img; ?>" alt="<?php echo htmlspecialchars($clinic['name']); ?>">
                    <?php else: ?>
                        <div class="card-image-placeholder" style="background:<?php echo $grad['bg']; ?>; color:<?php echo $grad['text']; ?>">
                            <div class="initials"><?php echo $name_initial; ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="card-badges">
                        <span class="badge-pill <?php echo $is_open ? 'open' : 'closed'; ?>">
                            <i class="fas <?php echo $is_open ? 'fa-circle' : 'fa-moon'; ?>" style="font-size:7px"></i>
                            <?php echo $is_open ? 'Open' : 'Closed'; ?>
                        </span>
                        <?php if ($clinic['total_favorites'] > 10): ?>
                        <span class="badge-pill popular"><i class="fas fa-fire"></i> Popular</span>
                        <?php endif; ?>
                    </div>
                    <div class="clinic-logo-badge">
                        <div class="logo-initials" style="background:<?php echo $grad['bg']; ?>;color:<?php echo $grad['text']; ?>"><?php echo $name_initial; ?></div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="clinic-header">
                        <div class="clinic-name-wrap">
                            <div class="clinic-name"><?php echo htmlspecialchars($clinic['name']); ?></div>
                            <div class="clinic-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($clinic['city'] ?: 'Cavite'); ?></span>
                            </div>
                        </div>
                        <div class="rating-badge"><i class="fas fa-star"></i> <?php echo $avg_rating ?: 'New'; ?></div>
                    </div>

                    <div class="info-rows">
                        <div class="info-row"><i class="fas fa-location-arrow"></i><span class="distance-tag" id="dist-<?php echo $clinic['id']; ?>">-- km</span></div>
                        <?php if ($price_range): ?>
                        <div class="info-row"><i class="fas fa-tag"></i><span>₱<?php echo number_format($price_range['min_price']); ?> – ₱<?php echo number_format($price_range['max_price']); ?></span></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($svc_list)): ?>
                    <div class="service-tags">
                        <?php foreach(array_slice($svc_list, 0, 3) as $svc): ?>
                        <span class="service-tag"><?php echo htmlspecialchars($svc); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="card-footer">
                        <div class="saved-on"><i class="fas fa-bookmark"></i> Saved <?php echo date('M j, Y', strtotime($clinic['favorited_date'])); ?></div>
                        <div class="card-actions">
                            <a href="clinic-details.php?id=<?php echo $clinic['id']; ?>" class="btn-view"><i class="fas fa-eye"></i> View</a>
                            <a href="book-appointment.php?clinic_id=<?php echo $clinic['id']; ?>" class="btn-book"><i class="fas fa-calendar-plus"></i> Book</a>
                            <div class="kebab-wrap">
                                <button class="btn-icon" onclick="toggleKebab(this)"><i class="fas fa-ellipsis-v"></i></button>
                                <div class="kebab-menu">
                                    <button class="kebab-item" onclick="shareClinic(<?php echo $clinic['id']; ?>,'<?php echo addslashes($clinic['name']); ?>')"><i class="fas fa-share-alt"></i> Share</button>
                                    <button class="kebab-item danger" onclick="showRemoveModal(<?php echo $clinic['id']; ?>,'<?php echo addslashes($clinic['name']); ?>')"><i class="fas fa-heart-broken"></i> Remove</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-heart-broken"></i>
            <h2>No Favorite Clinics Yet</h2>
            <p>Explore clinics and tap the heart icon to save them here.</p>
            <a href="dashboard.php" class="btn-browse"><i class="fas fa-search"></i> Browse Clinics</a>
        </div>
        <?php endif; ?>

        </div><!-- /panelClinics -->

        <!-- ===== PRODUCTS TAB ===== -->
        <div class="tab-panel" id="panelProducts">
            <?php if ($total_fav_products > 0): ?>
            <div class="fav-products-grid" id="favProductsGrid">
                <?php foreach ($fav_products_list as $fp):
                    $fp_id       = $fp['id'];
                    $is_on_sale  = !empty($fp['is_on_sale']) && $fp['is_on_sale'] == 1
                                   && !empty($fp['sale_price']) && !empty($fp['sale_end'])
                                   && strtotime($fp['sale_end']) >= strtotime('today');
                    $display_price = $is_on_sale ? (float)$fp['sale_price'] : (float)$fp['price'];
                    $orig_price    = (float)$fp['price'];
                    $is_out        = ($fp['total_stock'] !== null && (int)$fp['total_stock'] === 0);

                    // Build image URL
                    $fp_img = '/assets/img/no-image.png';
                    if (!empty($fp['images_json'])) {
                        $dec = json_decode($fp['images_json'], true);
                        if (!empty($dec[0])) {
                            $p = str_replace('uploads/uploads/', 'uploads/', $dec[0]);
                            $fp_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                        }
                    } elseif (!empty($fp['images'])) {
                        if (strpos($fp['images'], '[') === 0) {
                            $dec = json_decode($fp['images'], true);
                            if (!empty($dec[0])) {
                                $p = str_replace('uploads/uploads/', 'uploads/', $dec[0]);
                                $fp_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                            }
                        } else {
                            $p = str_replace('uploads/uploads/', 'uploads/', $fp['images']);
                            $fp_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                        }
                    } elseif (!empty($fp['image'])) {
                        $fp_img = '/uploads/products/' . $fp['image'];
                    }
                ?>
                <div class="fav-product-card">
                    <div class="fav-product-img">
                        <img src="<?php echo $fp_img; ?>"
                             alt="<?php echo htmlspecialchars($fp['name']); ?>"
                             onerror="this.src='/assets/img/no-image.png'">

                        <?php if ($is_out): ?>
                            <div class="fav-product-out"><i class="fas fa-times-circle"></i> Out of Stock</div>
                        <?php endif; ?>

                        <div class="fav-product-cat"><?php echo htmlspecialchars($fp['category']); ?></div>

                        <!-- Remove from favorites (heart) -->
                        <button class="btn-unfav-product"
                                onclick="showRemoveProductModal(<?php echo $fp_id; ?>, '<?php echo addslashes($fp['name']); ?>')"
                                title="Remove from favorites">
                            <i class="fas fa-heart"></i>
                        </button>
                    </div>
                    <div class="fav-product-body">
                        <div class="fav-product-name"><?php echo htmlspecialchars($fp['name']); ?></div>
                        <div class="fav-product-clinic">
                            <i class="fas fa-clinic-medical"></i>
                            <?php echo htmlspecialchars($fp['clinic_name']); ?>
                        </div>
                        <div class="fav-product-price <?php echo $is_on_sale ? 'sale' : ''; ?>">
                            ₱<?php echo number_format($display_price, 2); ?>
                            <?php if ($is_on_sale): ?>
                                <span class="orig">₱<?php echo number_format($orig_price, 2); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="fav-product-actions">
                            <a href="product-view.php?id=<?php echo $fp_id; ?>" class="btn-view-prod">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-glasses"></i>
                <h2>No Favorite Products Yet</h2>
                <p>Browse products and tap the ❤️ icon to save them here.</p>
                <a href="dashboard.php" class="btn-browse"><i class="fas fa-search"></i> Browse Products</a>
            </div>
            <?php endif; ?>
        </div><!-- /panelProducts -->

    </div>
</div>

<div class="toast" id="toast"></div>

<!-- Remove Clinic Modal -->
<div class="modal-overlay" id="removeModal">
    <div class="modal-container">
        <div class="modal-header"><i class="fas fa-heart-broken"></i><h3>Remove from Favorites?</h3></div>
        <div class="modal-body"><p>Remove <strong id="modalClinicName"></strong> from your saved clinics?</p></div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeModal()">Cancel</button>
            <a href="#" class="modal-btn confirm" id="confirmRemoveBtn"><i class="fas fa-trash-alt"></i> Remove</a>
        </div>
    </div>
</div>

<!-- Remove Product Modal -->
<div class="modal-overlay" id="removeProductModal">
    <div class="modal-container" id="removeProductModal">
        <div class="modal-header"><i class="fas fa-heart-broken"></i><h3>Remove Product?</h3></div>
        <div class="modal-body"><p>Remove <strong id="modalProductName"></strong> from your favorite products?</p></div>
        <div class="modal-footer">
            <button class="modal-btn cancel" onclick="closeProductModal()">Cancel</button>
            <button class="modal-btn confirm" id="confirmRemoveProductBtn" onclick="confirmRemoveProduct()"><i class="fas fa-trash-alt"></i> Remove</button>
        </div>
    </div>
</div>

<script>
function calcDistance(lat1, lon1, lat2, lon2) {
    if (!lat1 || !lon1 || !lat2 || !lon2) return null;
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180) * Math.cos(lat2*Math.PI/180) * Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function updateDistances(userLat, userLng) {
    document.querySelectorAll('.favorite-card').forEach(card => {
        const lat = parseFloat(card.dataset.lat);
        const lng = parseFloat(card.dataset.lng);
        const el = card.querySelector('[id^="dist-"]');
        if (!el) return;
        if (isNaN(lat) || isNaN(lng)) { el.textContent = 'Location N/A'; return; }
        const dist = calcDistance(userLat, userLng, lat, lng);
        if (dist !== null) el.textContent = dist < 1 ? Math.round(dist*1000) + ' m' : dist.toFixed(1) + ' km';
    });
}

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        pos => updateDistances(pos.coords.latitude, pos.coords.longitude),
        () => console.log('Location denied')
    );
}

let currentFilter = 'all';

function setFilter(filter, el) {
    currentFilter = filter;
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    applyFilter();
}

function applyFilter() {
    const cards = document.querySelectorAll('.favorite-card');
    const searchTerm = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const sortBy = document.getElementById('sortSelect')?.value || 'recent';
    let visibleCards = [];

    cards.forEach(card => {
        const name = card.dataset.name || '';
        const status = card.dataset.status;
        const rating = parseFloat(card.dataset.rating) || 0;
        const popular = parseInt(card.dataset.popular) || 0;
        let show = true;
        
        if (searchTerm && !name.includes(searchTerm)) show = false;
        if (currentFilter === 'open' && status !== 'open') show = false;
        if (currentFilter === 'rated' && rating < 4) show = false;
        if (currentFilter === 'popular' && popular <= 10) show = false;
        
        card.style.display = show ? '' : 'none';
        if (show) visibleCards.push(card);
    });
    
    const grid = document.getElementById('favoritesGrid');
    visibleCards.sort((a, b) => {
        if (sortBy === 'rating') return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
        if (sortBy === 'name') return a.dataset.name.localeCompare(b.dataset.name);
        if (sortBy === 'popular') return parseInt(b.dataset.popular) - parseInt(a.dataset.popular);
        return new Date(b.dataset.date) - new Date(a.dataset.date);
    });
    
    visibleCards.forEach(card => grid.appendChild(card));
    document.getElementById('resultsCount').innerHTML = `Showing <strong>${visibleCards.length}</strong> clinic${visibleCards.length !== 1 ? 's' : ''}`;
}

let searchTimeout;
document.getElementById('searchInput')?.addEventListener('keyup', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilter, 300);
});

function toggleKebab(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.kebab-menu.show').forEach(m => m.classList.remove('show'));
    menu.classList.toggle('show');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.kebab-wrap')) document.querySelectorAll('.kebab-menu.show').forEach(m => m.classList.remove('show'));
});

function showRemoveModal(id, name) {
    document.getElementById('modalClinicName').textContent = name;
    document.getElementById('confirmRemoveBtn').href = 'favorites.php?remove=' + id;
    document.getElementById('removeModal').classList.add('show');
}
function closeModal() { document.getElementById('removeModal').classList.remove('show'); }

function shareClinic(id, name) {
    const url = window.location.origin + '/pages/clinic-details.php?id=' + id;
    if (navigator.share) navigator.share({ title: name, url }).catch(()=> copyToClipboard(url));
    else copyToClipboard(url);
}
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => showToast('Link copied!'));
}
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2000);
}

function exportFavorites() {
    const cards = document.querySelectorAll('.favorite-card');
    let csv = 'Clinic Name,City,Rating,Status\n';
    cards.forEach(c => {
        const name = c.querySelector('.clinic-name')?.textContent || '';
        const city = c.querySelector('.clinic-location span')?.textContent || '';
        const rating = c.dataset.rating || '0';
        const status = c.dataset.status || '';
        csv += `"${name}","${city}","${rating}","${status}"\n`;
    });
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'my-favorite-clinics.csv';
    a.click();
    URL.revokeObjectURL(a.href);
    showToast('Exported!');
}

setTimeout(() => document.querySelectorAll('.alert-banner').forEach(b => b.style.display = 'none'), 3000);
applyFilter();

// ===== MAIN TAB SWITCHER =====
function switchMainTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));

    if (tab === 'clinics') {
        document.getElementById('panelClinics').classList.add('active');
        document.getElementById('tabBtnClinics').classList.add('active');
        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) exportBtn.style.display = '';
    } else {
        document.getElementById('panelProducts').classList.add('active');
        document.getElementById('tabBtnProducts').classList.add('active');
        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) exportBtn.style.display = 'none';
    }
}

// ===== PRODUCT REMOVE MODAL =====
let pendingRemoveProductId = null;

function showRemoveProductModal(id, name) {
    pendingRemoveProductId = id;
    document.getElementById('modalProductName').textContent = name;
    document.getElementById('removeProductModal').classList.add('show');
}
function closeProductModal() {
    document.getElementById('removeProductModal').classList.remove('show');
    pendingRemoveProductId = null;
}
function confirmRemoveProduct() {
    if (!pendingRemoveProductId) return;

    const formData = new FormData();
    formData.append('product_id', pendingRemoveProductId);

    fetch('toggle-product-favorite.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Remove the card from DOM
                const grid = document.getElementById('favProductsGrid');
                const cards = grid ? grid.querySelectorAll('.fav-product-card') : [];
                cards.forEach(card => {
                    const btn = card.querySelector('.btn-unfav-product');
                    if (btn && btn.getAttribute('onclick').includes(pendingRemoveProductId)) {
                        card.style.transition = 'all 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => card.remove(), 300);
                    }
                });
                showToast('Removed from favorites');
                closeProductModal();

                // Update badge count
                const badge = document.querySelector('#tabBtnProducts .tab-badge');
                if (badge) {
                    const count = parseInt(badge.textContent) - 1;
                    badge.textContent = count;
                }
            } else {
                showToast('Something went wrong.');
                closeProductModal();
            }
        })
        .catch(() => { showToast('Network error.'); closeProductModal(); });
}

// Close modals on overlay click
document.getElementById('removeProductModal').addEventListener('click', function(e) {
    if (e.target === this) closeProductModal();
});

// Check URL param to auto-switch tab
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('tab') === 'products') {
    switchMainTab('products');
}
</script>
</body>
</html>