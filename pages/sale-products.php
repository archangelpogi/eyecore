<?php

include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user info
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get user avatar and created_at
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// ============================================
// NOTIFICATION VARIABLES
// ============================================
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

// Get sale count for badge
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

// ============================================
// PAGINATION SETUP
// ============================================
$items_per_page = 9;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// ============================================
// FILTERS AND SORTING
// ============================================
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'discount_desc';
$min_discount = isset($_GET['min_discount']) ? (int)$_GET['min_discount'] : 0;

// Build base query for counting total items
$count_query = "
    SELECT COUNT(*) as total
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.is_on_sale = 1 
    AND p.sale_start <= CURDATE() 
    AND p.sale_end >= CURDATE()
";

// Apply category filter to count query
if ($category_filter != 'all') {
    $count_query .= " AND p.category = '" . mysqli_real_escape_string($conn, $category_filter) . "'";
}

// Apply minimum discount filter to count query
if ($min_discount > 0) {
    $count_query .= " AND ((p.price - p.sale_price) / p.price * 100) >= $min_discount";
}

$count_result = mysqli_query($conn, $count_query);
$total_items = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_items / $items_per_page);

// Ensure page is within valid range
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Build main query with pagination
$query = "
    SELECT p.*, 
           c.name as clinic_name, 
           c.id as clinic_id,
           c.city,
           ROUND(((p.price - p.sale_price) / p.price) * 100) as discount_percent,
           DATEDIFF(p.sale_end, CURDATE()) as days_left
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.is_on_sale = 1 
    AND p.sale_start <= CURDATE() 
    AND p.sale_end >= CURDATE()
";

// Apply category filter
if ($category_filter != 'all') {
    $query .= " AND p.category = '" . mysqli_real_escape_string($conn, $category_filter) . "'";
}

// Apply minimum discount filter
if ($min_discount > 0) {
    $query .= " AND ((p.price - p.sale_price) / p.price * 100) >= $min_discount";
}

// Apply sorting
switch($sort_by) {
    case 'discount_asc':
        $query .= " ORDER BY discount_percent ASC";
        break;
    case 'price_asc':
        $query .= " ORDER BY p.sale_price ASC";
        break;
    case 'price_desc':
        $query .= " ORDER BY p.sale_price DESC";
        break;
    case 'ending_soon':
        $query .= " ORDER BY p.sale_end ASC";
        break;
    case 'discount_desc':
    default:
        $query .= " ORDER BY discount_percent DESC, p.sale_end ASC";
}

// Add pagination
$query .= " LIMIT $offset, $items_per_page";

$sale_products_query = mysqli_query($conn, $query);

// Get unique categories for filter (unfiltered)
$categories_query = mysqli_query($conn, "
    SELECT DISTINCT p.category 
    FROM products p
    WHERE p.is_on_sale = 1 
    AND p.sale_start <= CURDATE() 
    AND p.sale_end >= CURDATE()
    ORDER BY p.category
");

// Get user stats
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

// Get pending appointments count for badge
$pending_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_count)['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Hot Sales - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        html, body { margin: 0 !important; padding: 0 !important; width: 100%; overflow-x: hidden; background: var(--bg-primary); }
        body { min-height: 100vh; transition: background-color 0.3s, color 0.3s; }
        :root {
            --primary: #00B761; --primary-dark: #00994D; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --secondary: #FF8C42; --secondary-light: #FFF1E6;
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF; --card-bg: #FFFFFF;
            --text-primary: #1A1A1A; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04); --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08); --shadow-hover: 0 30px 50px -20px rgba(0,183,97,0.3);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 999px;
            --danger: #FF4444; --warning: #FF8C42; --info: #17A2B8; --success: #00B761;
            --sale-color: #FF4444; --sale-gradient: linear-gradient(135deg, #FF4444 0%, #FF6B6B 100%);
            --sale-light: #FFE5E5; --urgent-bg: #FFF3E0; --urgent-color: #FF8C42;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A; --card-bg: #242424;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.2); --shadow-md: 0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.4);
            --sale-color: #FF6B6B; --sale-gradient: linear-gradient(135deg, #FF6B6B 0%, #FF8888 100%);
            --sale-light: #4A2D2D; --urgent-bg: #5A4A2D; --urgent-color: #FFD966;
        }
        h1, h2, h3, h4, h5, h6, p { margin: 0; }

        /* NAVBAR */
        .navbar { display: flex; justify-content: space-between; align-items: center; background: var(--bg-secondary); padding: 12px 40px; box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid var(--border-light); width: 100%; }
        @media (max-width: 1024px) { .navbar { padding: 12px 24px; } }
        @media (max-width: 768px) { .navbar { display: none; } }
        .nav-left { display: flex; align-items: center; gap: 40px; }
        .logo { display: flex; align-items: center; gap: 10px; font-size: 24px; font-weight: 700; color: var(--primary); }
        .logo i { font-size: 28px; }
        .nav-links { display: flex; align-items: center; gap: 8px; }
        .nav-link { display: flex; align-items: center; gap: 8px; padding: 10px 20px; color: var(--text-secondary); text-decoration: none; border-radius: var(--radius-full); transition: all 0.2s; font-weight: 500; font-size: 14px; position: relative; background: none; border: none; cursor: pointer; }
        .nav-link:hover { color: var(--primary); background: var(--bg-primary); }
        .nav-link.active { background: var(--bg-primary); color: var(--primary); font-weight: 600; }
        .nav-link .badge { position: absolute; top: 2px; right: 2px; background: var(--danger); color: white; font-size: 9px; padding: 2px 5px; border-radius: var(--radius-full); min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; }
        .nav-dropdown { position: relative; }
        .dropdown-trigger { display: flex; align-items: center; gap: 6px; }
        .dropdown-trigger i { font-size: 12px; transition: transform 0.2s; }
        .dropdown-trigger.active i { transform: rotate(180deg); }
        .dropdown-menu { position: absolute; top: 100%; left: 0; min-width: 220px; background: var(--bg-secondary); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); padding: 8px; margin-top: 12px; display: none; z-index: 100; border: 1px solid var(--border-light); }
        .dropdown-menu.show { display: block; animation: fadeIn 0.2s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-menu a { display: flex; align-items: center; gap: 12px; padding: 14px 16px; color: var(--text-secondary); text-decoration: none; border-radius: var(--radius-md); transition: all 0.2s; position: relative; font-size: 14px; }
        .dropdown-menu a:hover { background: var(--bg-primary); color: var(--primary); }
        .dropdown-badge { position: absolute; right: 16px; background: var(--danger); color: white; font-size: 11px; padding: 2px 8px; border-radius: var(--radius-full); }
        .nav-right { display: flex; align-items: center; gap: 16px; }
        .search-container { position: relative; width: 280px; }
        .search-container i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 16px; }
        .search-container input { width: 100%; padding: 12px 20px 12px 48px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 40px; font-size: 14px; color: var(--text-primary); transition: all 0.2s; }
        .search-container input:focus { outline: none; border-color: var(--primary); }
        .user-stats-badge { display: flex; align-items: center; gap: 16px; background: var(--bg-primary); padding: 8px 20px; border-radius: var(--radius-full); }
        .stat-badge { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; }
        .icon-btn { width: 44px; height: 44px; background: var(--bg-primary); border: none; border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; color: var(--text-secondary); font-size: 18px; position: relative; }
        .icon-btn:hover { background: var(--primary); color: white; }
        .icon-btn .badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; font-size: 10px; padding: 3px 6px; border-radius: var(--radius-full); min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; }
        .profile-dropdown { position: relative; }
        .profile-trigger { display: flex; align-items: center; gap: 8px; background: var(--bg-primary); padding: 4px 4px 4px 16px; border-radius: var(--radius-full); cursor: pointer; border: 1px solid var(--border-light); }
        .profile-info { text-align: right; }
        .profile-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .profile-points { font-size: 11px; color: var(--primary); }
        .profile-avatar { width: 36px; height: 36px; border-radius: var(--radius-full); background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; overflow: hidden; }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-menu { position: absolute; top: 100%; right: 0; width: 220px; background: var(--bg-secondary); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); display: none; z-index: 1000; margin-top: 12px; border: 1px solid var(--border-light); overflow: hidden; }
        .profile-menu.show { display: block; }
        .profile-menu a { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: var(--text-secondary); text-decoration: none; transition: all 0.2s; border-bottom: 1px solid var(--border-light); font-size: 14px; }
        .profile-menu a:last-child { border-bottom: none; }
        .profile-menu a:hover { background: var(--primary-light); color: var(--primary); }
        .profile-menu a i { width: 20px; color: var(--primary); }

        /* MOBILE TOP */
        .mobile-top { display: none; position: sticky; top: 0; z-index: 100; background: var(--bg-secondary); padding: 12px 20px; border-bottom: 1px solid var(--border-light); width: 100%; }
        @media (max-width: 768px) { .mobile-top { display: flex; justify-content: space-between; align-items: center; } }
        .mobile-logo { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 700; color: var(--primary); }
        .mobile-actions { display: flex; align-items: center; gap: 12px; }
        .mobile-notification-dropdown { position: relative; }
        .mobile-notification-menu { position: fixed; top: 70px; left: 10px; right: 10px; background: var(--bg-secondary); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); display: none; z-index: 2000; border: 1px solid var(--border-light); overflow: hidden; max-height: 80vh; overflow-y: auto; }
        .mobile-notification-menu.show { display: block; }

        /* MOBILE BOTTOM NAV */
        .mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-secondary); box-shadow: 0 -5px 20px rgba(0,0,0,0.05); padding: 8px 16px; z-index: 1000; border-top: 1px solid var(--border-light); }
        @media (max-width: 768px) { .mobile-bottom-nav { display: block; } }
        .mobile-nav-items { display: flex; justify-content: space-around; align-items: center; }
        .mobile-nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: var(--text-muted); font-size: 11px; gap: 4px; position: relative; padding: 8px 0; }
        .mobile-nav-item i { font-size: 22px; }
        .mobile-nav-item.active { color: var(--primary); }
        .mobile-nav-item .badge { position: absolute; top: 0; right: -2px; background: var(--danger); color: white; font-size: 9px; padding: 2px 5px; border-radius: var(--radius-full); }

        /* MOBILE MENU */
        .mobile-menu-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1999; display: none; }
        .mobile-menu-overlay.show { display: block; }
        .mobile-menu { position: fixed; top: 0; right: -300px; width: 280px; height: 100vh; background: var(--bg-secondary); box-shadow: var(--shadow-lg); z-index: 2000; transition: right 0.3s ease; overflow-y: auto; }
        .mobile-menu.open { right: 0; }
        .mobile-menu-header { display: flex; justify-content: space-between; align-items: center; padding: 25px 20px; border-bottom: 1px solid var(--border-light); }
        .mobile-user { display: flex; align-items: center; gap: 15px; }
        .mobile-avatar { width: 50px; height: 50px; border-radius: var(--radius-full); background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; overflow: hidden; }
        .mobile-user h4 { font-size: 16px; margin-bottom: 4px; }
        .mobile-user p { font-size: 12px; color: var(--text-secondary); }
        .mobile-menu-header button { background: var(--bg-primary); border: none; width: 35px; height: 35px; border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-secondary); }
        .mobile-menu-items { padding: 15px; }
        .mobile-menu-items a { display: flex; align-items: center; gap: 15px; padding: 16px; color: var(--text-secondary); text-decoration: none; border-radius: var(--radius-md); transition: all 0.2s; margin-bottom: 5px; }
        .mobile-menu-items a i { width: 24px; color: var(--primary); font-size: 18px; }
        .mobile-menu-items a:hover { background: var(--primary-light); color: var(--primary); }
        .mobile-menu-items .logout-link { color: var(--danger); margin-top: 20px; border-top: 1px solid var(--border-light); padding-top: 20px; }
        .mobile-menu-items .logout-link i { color: var(--danger); }

        /* NOTIFICATION */
        .notification-dropdown { position: relative; display: inline-block; }
        .notification-menu { position: absolute; top: 100%; right: 0; width: 380px; background: var(--bg-secondary); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); display: none; z-index: 1000; margin-top: 12px; border: 1px solid var(--border-light); overflow: hidden; }
        .notification-menu.show { display: block; }
        .notification-header { padding: 20px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; }
        .notification-header h3 { font-size: 16px; display: flex; align-items: center; gap: 8px; color: var(--text-primary); }
        .notification-header button { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 5px; }
        .notification-list { max-height: 400px; overflow-y: auto; }
        .notification-item { display: flex; padding: 16px 20px; text-decoration: none; border-bottom: 1px solid var(--border-light); transition: all 0.2s; position: relative; }
        .notification-item:hover { background: var(--bg-primary); }
        .notification-item.unread { background: var(--primary-light); }
        .notification-icon { width: 44px; height: 44px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; margin-right: 16px; flex-shrink: 0; }
        .notification-icon.appointment { background: var(--primary-light); color: var(--primary); }
        .notification-icon.favorite { background: #FCE4EC; color: #C2185B; }
        .notification-icon.promo { background: var(--sale-light); color: var(--sale-color); }
        .notification-content { flex: 1; }
        .notification-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; color: var(--text-primary); }
        .notification-message { font-size: 12px; color: var(--text-secondary); margin-bottom: 4px; }
        .notification-time { font-size: 11px; color: var(--text-muted); }
        .notification-dot { position: absolute; top: 20px; right: 20px; width: 8px; height: 8px; background: var(--primary); border-radius: 50%; }
        .notification-empty { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .notification-empty i { font-size: 50px; margin-bottom: 15px; opacity: 0.5; }
        .notification-footer { padding: 16px; text-align: center; border-top: 1px solid var(--border-light); }
        .notification-footer a { color: var(--primary); text-decoration: none; font-size: 13px; font-weight: 600; }

        /* TOAST */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast-notification { display: flex; align-items: center; gap: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px 24px; box-shadow: var(--shadow-lg); margin-bottom: 12px; min-width: 320px; animation: slideIn 0.3s ease; border-left: 4px solid var(--primary); }
        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* MAIN CONTENT */
        .main-content { max-width: 1400px; margin: 0 auto; padding: 30px 40px; }
        @media (max-width: 768px) { .main-content { padding: 20px 16px 100px; } }

        /* PAGE HEADER */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
        .page-header h1 i { color: var(--sale-color); background: var(--sale-light); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-full); font-size: 24px; }
        .total-badge { background: var(--bg-secondary); padding: 12px 24px; border-radius: 30px; border: 1px solid var(--border-light); display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; color: var(--text-secondary); }
        .total-badge i { color: var(--sale-color); }
        .total-badge span { font-weight: 600; color: var(--sale-color); margin-right: 4px; }

        /* FILTER BAR */
        .filter-bar { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 20px 24px; margin-bottom: 24px; border: 1px solid var(--border-light); }
        .filter-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: center; }
        .filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
        .filter-label i { color: var(--sale-color); }
        .filter-select { padding: 8px 16px; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); border-radius: var(--radius-full); font-size: 12px; font-weight: 500; cursor: pointer; outline: none; min-width: 150px; }
        .discount-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .discount-btn { padding: 6px 12px; border: 1px solid var(--sale-color); background: var(--bg-primary); color: var(--sale-color); border-radius: var(--radius-full); font-size: 12px; cursor: pointer; transition: all 0.2s; }
        .discount-btn:hover, .discount-btn.active { background: var(--sale-gradient); color: white; border-color: transparent; }
        .clear-filters { margin-left: auto; padding: 8px 20px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-full); color: var(--text-secondary); font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .clear-filters:hover { background: var(--sale-color); color: white; border-color: var(--sale-color); }

        /* RESULTS */
        .results-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .results-count { background: var(--bg-secondary); padding: 8px 16px; border-radius: var(--radius-full); font-size: 14px; border: 1px solid var(--border-light); color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
        .results-count i { color: var(--sale-color); }
        .results-count span { font-weight: 600; color: var(--sale-color); margin: 0 4px; }
        .sort-options { display: flex; align-items: center; gap: 10px; }
        .sort-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .sort-select { padding: 8px 16px; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); border-radius: var(--radius-full); font-size: 12px; font-weight: 500; cursor: pointer; outline: none; }

        /* PRODUCTS GRID */
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; margin-bottom: 30px; }

        .product-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid var(--border-light);
            position: relative;
            text-decoration: none;
            display: flex;
            flex-direction: column;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); border-color: var(--sale-color); }

        .sale-badge { position: absolute; top: 15px; right: 15px; background: var(--sale-gradient); color: white; padding: 6px 14px; border-radius: var(--radius-full); font-size: 13px; font-weight: 700; z-index: 2; }
        .urgent-badge { position: absolute; top: 15px; left: 15px; background: var(--urgent-bg); color: var(--urgent-color); padding: 5px 11px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; z-index: 2; display: flex; align-items: center; gap: 4px; border: 1px solid #FED7AA; }

        /* Product image — gradient placeholder when no image */
        .product-image {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 48px;
            overflow: hidden;
            border-bottom: 1px solid var(--border-light);
            position: relative;
            flex-shrink: 0;
        }
        .product-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-image.no-image { background: linear-gradient(135deg, #E3FCE9, #B7F5D2); }
        .product-image.no-image i { color: #00994D; opacity: 0.5; }

        .product-info { padding: 20px; display: flex; flex-direction: column; flex: 1; }
        .clinic-name { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
        .clinic-name i { color: var(--primary); font-size: 10px; }
        .product-name { font-size: 16px; color: var(--text-primary); font-weight: 700; margin-bottom: 12px; line-height: 1.3; }
        .price-section { margin-bottom: 10px; display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; }
        .sale-price { font-size: 22px; color: var(--sale-color); font-weight: 700; }
        .original-price { font-size: 15px; color: var(--text-muted); text-decoration: line-through; }

        /* Progress bar for sale urgency */
        .sale-progress-wrap { margin-bottom: 8px; }
        .sale-progress-bar { height: 4px; background: var(--border-light); border-radius: 999px; overflow: hidden; margin-bottom: 6px; }
        .sale-progress-fill { height: 100%; background: var(--sale-gradient); border-radius: 999px; transition: width 0.3s; }

        .timer { display: flex; align-items: center; gap: 5px; font-size: 12px; margin-bottom: 15px; color: var(--text-secondary); }
        .timer i { color: var(--warning); font-size: 11px; }
        .timer.urgent { color: var(--sale-color); font-weight: 700; }
        .timer.urgent i { color: var(--sale-color); }

        .product-actions { display: flex; gap: 8px; margin-top: auto; }
        .btn-view, .btn-book { flex: 1; padding: 10px; border-radius: var(--radius-md); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-view { background: var(--bg-primary); color: var(--primary); border: 1.5px solid var(--primary); }
        .btn-view:hover { background: var(--primary); color: white; }
        .btn-book { background: var(--sale-gradient); color: white; border: none; flex: 2; }
        .btn-book:hover { transform: translateY(-2px); box-shadow: 0 8px 15px -5px var(--sale-color); }

        /* EMPTY STATE */
        .empty-state { grid-column: 1 / -1; text-align: center; background: var(--bg-secondary); padding: 80px 40px; border-radius: var(--radius-lg); border: 1px solid var(--border-light); }
        .empty-state i { font-size: 80px; color: var(--text-muted); margin-bottom: 20px; opacity: 0.5; }
        .empty-state h2 { font-size: 24px; color: var(--text-primary); margin-bottom: 10px; }
        .empty-state p { color: var(--text-secondary); margin-bottom: 30px; }
        .browse-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 30px; background: var(--primary-gradient); color: white; text-decoration: none; border-radius: var(--radius-full); font-weight: 600; transition: all 0.3s; }

        /* PAGINATION */
        .pagination-container { margin-top: 40px; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 15px; }
        .pagination-btn { min-width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; background: var(--bg-secondary); border: 1px solid var(--border-light); border-radius: var(--radius-md); color: var(--text-secondary); text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; padding: 0 12px; }
        .pagination-btn:hover { background: var(--sale-color); color: white; border-color: var(--sale-color); }
        .pagination-btn.active { background: var(--sale-gradient); color: white; border-color: transparent; font-weight: 600; }
        .pagination-btn.disabled { opacity: 0.5; pointer-events: none; }
        .pagination-info { text-align: center; color: var(--text-muted); font-size: 13px; }

        @media (max-width: 768px) {
            .filter-row { flex-direction: column; align-items: flex-start; }
            .filter-select { width: 100%; }
            .clear-filters { margin-left: 0; width: 100%; justify-content: center; }
            .results-info { flex-direction: column; align-items: flex-start; }
            .sort-select { flex: 1; }
            .products-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>
<?php include '../includes/navbar.php'; ?>
    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-tags"></i> Hot Sales & Promos</h1>
            <div class="total-badge"><i class="fas fa-fire"></i> <span><?php echo $total_items; ?></span> Items on Sale</div>
        </div>

        <div class="filter-bar">
            <form method="GET" action="" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <span class="filter-label"><i class="fas fa-tag"></i> Category:</span>
                        <select name="category" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $category_filter == 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php mysqli_data_seek($categories_query, 0); while ($category = mysqli_fetch_assoc($categories_query)): ?>
                            <option value="<?php echo $category['category']; ?>" <?php echo $category_filter == $category['category'] ? 'selected' : ''; ?>><?php echo $category['category']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label"><i class="fas fa-percent"></i> Min Discount:</span>
                        <div class="discount-buttons">
                            <button type="button" class="discount-btn <?php echo $min_discount == 0 ? 'active' : ''; ?>" onclick="setDiscount(0)">All</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 20 ? 'active' : ''; ?>" onclick="setDiscount(20)">20%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 30 ? 'active' : ''; ?>" onclick="setDiscount(30)">30%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 40 ? 'active' : ''; ?>" onclick="setDiscount(40)">40%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 50 ? 'active' : ''; ?>" onclick="setDiscount(50)">50%+</button>
                        </div>
                        <input type="hidden" name="min_discount" id="min_discount" value="<?php echo $min_discount; ?>">
                    </div>
                    <a href="sale-products.php" class="clear-filters"><i class="fas fa-times"></i> Clear Filters</a>
                </div>
                <input type="hidden" name="page" value="1">
            </form>
        </div>

        <div class="results-info">
            <div class="results-count">
                <i class="fas fa-box"></i>
                Showing <span><?php echo min($items_per_page, $total_items - ($page - 1) * $items_per_page); ?></span> of <span><?php echo $total_items; ?></span> results
                <?php if ($category_filter != 'all'): ?> in <span><?php echo $category_filter; ?></span><?php endif; ?>
                <?php if ($min_discount > 0): ?> with <span><?php echo $min_discount; ?>%+ discount</span><?php endif; ?>
            </div>
            <div class="sort-options">
                <span class="sort-label">Sort by:</span>
                <select class="sort-select" onchange="window.location.href='?category=<?php echo $category_filter; ?>&min_discount=<?php echo $min_discount; ?>&sort='+this.value+'&page=1'">
                    <option value="discount_desc" <?php echo $sort_by == 'discount_desc' ? 'selected' : ''; ?>>Highest Discount</option>
                    <option value="discount_asc"  <?php echo $sort_by == 'discount_asc'  ? 'selected' : ''; ?>>Lowest Discount</option>
                    <option value="price_asc"     <?php echo $sort_by == 'price_asc'     ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_desc"    <?php echo $sort_by == 'price_desc'    ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="ending_soon"   <?php echo $sort_by == 'ending_soon'   ? 'selected' : ''; ?>>Ending Soon</option>
                </select>
            </div>
        </div>

        <div class="products-grid">
            <?php
            // Gradient placeholders when no image
            $grad_colors = [
                ['#E3FCE9','#B7F5D2','#00994D'],
                ['#DBEAFE','#BFDBFE','#1D4ED8'],
                ['#FEF3C7','#FDE68A','#D97706'],
                ['#EDE9FE','#DDD6FE','#6D28D9'],
                ['#FFE4E6','#FECDD3','#BE123C'],
                ['#CCFBF1','#99F6E4','#0F766E'],
            ];
            $grad_idx = 0;
            if ($total_items > 0): while ($product = mysqli_fetch_assoc($sale_products_query)):
                $urgent = $product['days_left'] <= 3;
                $gc = $grad_colors[$grad_idx % count($grad_colors)];
                $grad_idx++;
                // Sale duration for progress bar (assume max 30 days sale)
                $total_sale_days = 30;
                $days_used = $total_sale_days - $product['days_left'];
                $progress_pct = max(10, min(95, ($days_used / $total_sale_days) * 100));
                // Exact end date
                $end_date = date('M j', strtotime('+' . $product['days_left'] . ' days'));
            ?>
                <a href="product-details.php?id=<?php echo $product['id']; ?>" class="product-card">
                    <!-- Discount badge — shown once only -->
                    <div class="sale-badge">-<?php echo $product['discount_percent']; ?>%</div>
                    <!-- Urgency badge — only when <= 3 days -->
                    <?php if ($urgent): ?>
                    <div class="urgent-badge"><i class="fas fa-fire"></i> Last <?php echo $product['days_left']; ?> day<?php echo $product['days_left'] != 1 ? 's' : ''; ?>!</div>
                    <?php endif; ?>
                    <!-- Image — gradient placeholder when no image -->
                    <div class="product-image <?php echo empty($product['image']) ? 'no-image' : ''; ?>"
                         <?php if (empty($product['image'])): ?>style="background: linear-gradient(135deg, <?php echo $gc[0]; ?>, <?php echo $gc[1]; ?>);"<?php endif; ?>>
                        <?php if (!empty($product['image'])): ?>
                            <?php
$img_src = '';
if (!empty($product['image'])) {
    // Check if path starts with 'uploads' or contains full path
    if (strpos($product['image'], 'uploads/') === 0) {
        $img_src = '../' . $product['image'];
    } elseif (strpos($product['image'], '/eyecore/') !== false) {
        $img_src = '..' . $product['image'];
    } else {
        $img_src = '../assets/images/products/' . $product['image'];
    }
}
?>
<img src="<?php echo htmlspecialchars($img_src); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php else: ?>
                            <i class="fas fa-glasses" style="color:<?php echo $gc[2]; ?>;opacity:0.5;font-size:52px;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="product-info">
                        <!-- Clinic + city — uppercase small label -->
                        <div class="clinic-name">
                            <i class="fas fa-clinic-medical"></i>
                            <?php echo htmlspecialchars($product['clinic_name']); ?>
                            <?php if (!empty($product['city'])): ?> · <?php echo htmlspecialchars($product['city']); ?><?php endif; ?>
                        </div>
                        <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                        <!-- Price — no redundant discount pill here -->
                        <div class="price-section">
                            <span class="sale-price">₱<?php echo number_format($product['sale_price'], 2); ?></span>
                            <span class="original-price">₱<?php echo number_format($product['price'], 2); ?></span>
                        </div>
                        <!-- Progress bar showing how far into the sale we are -->
                        <div class="sale-progress-wrap">
                            <div class="sale-progress-bar">
                                <div class="sale-progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
                            </div>
                        </div>
                        <!-- Exact end date instead of vague "X days left" -->
                        <div class="timer <?php echo $urgent ? 'urgent' : ''; ?>">
                            <i class="fas fa-hourglass-half"></i>
                            <?php if ($product['days_left'] == 0): ?>
                                Last day today!
                            <?php elseif ($urgent): ?>
                                Ends <?php echo $end_date; ?> — only <?php echo $product['days_left']; ?> day<?php echo $product['days_left'] != 1 ? 's' : ''; ?> left!
                            <?php else: ?>
                                Sale ends <?php echo $end_date; ?>
                            <?php endif; ?>
                        </div>
                        <div class="product-actions">
                            <span class="btn-view"><i class="fas fa-eye"></i> View</span>
                            <span class="btn-book"><i class="fas fa-calendar-plus"></i> Book Now</span>
                        </div>
                    </div>
                </a>
            <?php endwhile; else: ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <h2>No Active Sales</h2>
                    <p>There are no ongoing promotions at the moment. Check back later!</p>
                    <a href="clinics-map.php" class="browse-btn"><i class="fas fa-map-marked-alt"></i> Browse Clinics</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?category=<?php echo $category_filter; ?>&min_discount=<?php echo $min_discount; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page-1; ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
                <?php else: ?><span class="pagination-btn disabled"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
                <?php
                $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2);
                if ($start_page > 1) { echo '<a href="?category='.$category_filter.'&min_discount='.$min_discount.'&sort='.$sort_by.'&page=1" class="pagination-btn">1</a>'; if ($start_page > 2) echo '<span class="pagination-btn disabled">...</span>'; }
                for ($i = $start_page; $i <= $end_page; $i++) { echo $i==$page ? '<span class="pagination-btn active">'.$i.'</span>' : '<a href="?category='.$category_filter.'&min_discount='.$min_discount.'&sort='.$sort_by.'&page='.$i.'" class="pagination-btn">'.$i.'</a>'; }
                if ($end_page < $total_pages) { if ($end_page < $total_pages-1) echo '<span class="pagination-btn disabled">...</span>'; echo '<a href="?category='.$category_filter.'&min_discount='.$min_discount.'&sort='.$sort_by.'&page='.$total_pages.'" class="pagination-btn">'.$total_pages.'</a>'; }
                ?>
                <?php if ($page < $total_pages): ?><a href="?category=<?php echo $category_filter; ?>&min_discount=<?php echo $min_discount; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page+1; ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
                <?php else: ?><span class="pagination-btn disabled"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
            </div>
            <div class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            toast.innerHTML = `<i class="fas fa-check-circle"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.style.animation = 'fadeOut 0.3s ease'; setTimeout(() => toast.remove(), 300); }, 3000);
        }

        function setDiscount(discount) { document.getElementById('min_discount').value = discount; document.getElementById('filterForm').submit(); }
    </script>
</body>
</html>