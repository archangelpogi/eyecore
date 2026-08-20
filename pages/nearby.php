<?php

include '../includes/config.php';
include '../includes/theme.php';

// ✅ SET TIMEZONE TO PHILIPPINES
date_default_timezone_set('Asia/Manila');

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

// ============================================
// ✅ FIXED: GET CLINIC IMAGE FUNCTION
// ============================================
function getClinicImage($clinic) {
    if (empty($clinic)) return null;
    
    $imageFields = [
        'cover_photo' => '/assets/images/clinic-covers/',
        'clinic_image' => '/assets/images/clinic-images/',
        'logo' => '/assets/images/clinic-logos/',
        'clinic_logo' => '/assets/images/clinic-logos/'
    ];
    
    foreach ($imageFields as $field => $path) {
        if (!empty($clinic[$field])) {
            $filename = trim($clinic[$field]);
            
            if (strpos($filename, 'http') === 0 || strpos($filename, '//') === 0) {
                return $filename;
            }
            if (strpos($filename, 'uploads/') === 0) {
                return '/' . $filename;
            }
            if (strpos($filename, '/uploads/') === 0) {
                return $filename;
            }
            if (strpos($filename, '/') === 0) {
                return $filename;
            }
            
            return $path . $filename;
        }
    }
    
    return null;
}

// ============================================
// ✅ FIXED: CHECK IF CLINIC IS OPEN - WITH TIMEZONE
// ============================================
function isClinicOpen($hours) {
    if (empty($hours) || strtolower(trim($hours)) === 'hours not set') {
        return false;
    }
    
    if (stripos($hours, '24/7') !== false || stripos($hours, '24 hours') !== false) {
        return true;
    }
    
    // Clean up hours string
    $hours = trim(preg_replace('/\s+/', ' ', $hours));
    
    // Patterns to match: with minutes or without
    $patterns = [
        '/(\d{1,2}:\d{2})\s*(AM|PM)\s*[-–]+\s*(\d{1,2}:\d{2})\s*(AM|PM)/i',
        '/(\d{1,2})\s*(AM|PM)\s*[-–]+\s*(\d{1,2})\s*(AM|PM)/i',
    ];
    
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $timezone);
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $hours, $matches)) {
            // Build time strings
            if (count($matches) == 5) {
                // with minutes
                $open_str = $matches[1] . ' ' . $matches[2];
                $close_str = $matches[3] . ' ' . $matches[4];
            } else {
                // without minutes, assume :00
                $open_str = $matches[1] . ':00 ' . $matches[2];
                $close_str = $matches[3] . ':00 ' . $matches[4];
            }
            
            $open = DateTime::createFromFormat('g:i A', $open_str, $timezone);
            $close = DateTime::createFromFormat('g:i A', $close_str, $timezone);
            
            if ($open && $close) {
                // Set both to today's date
                $open->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
                $close->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
                
                // If close is before open, it crosses midnight
                if ($close < $open) {
                    $close->modify('+1 day');
                }
                
                return ($now >= $open && $now < $close);
            }
        }
    }
    
    return false;
}

// Get all clinics with coordinates
$clinics_query = mysqli_query($conn, "SELECT * FROM clinics WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY name");
$clinics = [];
while($clinic = mysqli_fetch_assoc($clinics_query)) {
    $clinic['is_open'] = isClinicOpen($clinic['hours']);
    
    $rating_query = mysqli_query($conn, "SELECT AVG(rating) as avg_rating FROM clinic_reviews WHERE clinic_id = {$clinic['id']}");
    $rating_row = mysqli_fetch_assoc($rating_query);
    $clinic['rating'] = round($rating_row['avg_rating'] ?? 0, 1);
    
    if (strpos($clinic['name'], 'EO Optique') !== false) {
        $clinic['type'] = 'EO Optique';
        $clinic['type_color'] = '#00B761';
    } elseif (strpos($clinic['name'], 'Sunnies Specs') !== false) {
        $clinic['type'] = 'Sunnies Specs';
        $clinic['type_color'] = '#4158D0';
    } elseif (strpos($clinic['name'], 'Starfinder Optical') !== false) {
        $clinic['type'] = 'Starfinder Optical';
        $clinic['type_color'] = '#FF8C42';
    } else {
        $clinic['type'] = 'Other';
        $clinic['type_color'] = '#C850C0';
    }
    
    $clinic['image_path'] = getClinicImage($clinic);
    
    $clinics[] = $clinic;
}

// Get unique cities for filter
$cities_query = mysqli_query($conn, "SELECT DISTINCT city FROM clinics WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY city");
$cities = [];
while($city = mysqli_fetch_assoc($cities_query)) {
    $cities[] = $city['city'];
}

// Count appointments for badge
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

$reservation_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')");
$reservation_count_row = mysqli_fetch_assoc($reservation_count_query);
$reservation_count = $reservation_count_row['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $time_ago = strtotime($timestamp);
        $current_time = time();
        $time_difference = $current_time - $time_ago;
        $seconds = $time_difference;
        
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        $weeks = round($seconds / 604800);
        $months = round($seconds / 2629440);
        $years = round($seconds / 31553280);
        
        if ($seconds <= 60) {
            return "Just Now";
        } else if ($minutes <= 60) {
            return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
        } else if ($hours <= 24) {
            return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
        } else if ($days <= 7) {
            return ($days == 1) ? "yesterday" : "$days days ago";
        } else if ($weeks <= 4.3) {
            return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
        } else if ($months <= 12) {
            return ($months == 1) ? "1 month ago" : "$months months ago";
        } else {
            return ($years == 1) ? "1 year ago" : "$years years ago";
        }
    }
}

$active_nav = 'nearby';
include '../includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Nearby Clinics - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* ===== RESET AND BASE STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        html, body { margin: 0 !important; padding: 0 !important; width: 100%; overflow-x: hidden; background: var(--bg-primary); }
        body { min-height: 100vh; transition: background-color 0.3s, color 0.3s; }
        :root {
            --primary: #00B761; --primary-dark: #00994D; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --secondary: #FF8C42; --secondary-light: #FFF1E6;
            --accent-1: #4158D0; --accent-2: #C850C0;
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF; --card-bg: #FFFFFF;
            --text-primary: #1A1A1A; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04); --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08); --shadow-hover: 0 30px 50px -20px rgba(0,183,97,0.3);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 999px;
            --danger: #FF4444; --warning: #FF8C42; --info: #17A2B8; --success: #00B761;
            --open-bg: #d4edda; --open-text: #28a745; --closed-bg: #f8d7da; --closed-text: #721c24;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A; --card-bg: #242424;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.2); --shadow-md: 0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.4);
            --open-bg: #2d4a2d; --open-text: #7ac97a; --closed-bg: #5a2d2d; --closed-text: #ff9999;
        }
        h1, h2, h3, h4, h5, h6, p { margin: 0; }

        .main-content { max-width: 1400px; margin: 0 auto; padding: 30px 40px 30px 40px; }
        @media (max-width: 768px) { .main-content { padding: 20px 16px 100px; } }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
        .page-header h1 i { color: var(--primary); background: var(--primary-light); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-full); font-size: 24px; }
        .location-badge { background: var(--bg-secondary); padding: 12px 24px; border-radius: 30px; border: 1px solid var(--border-light); display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; color: var(--text-secondary); box-shadow: var(--shadow-sm); }
        .location-badge i.fa-check-circle { color: var(--success); }
        .location-badge i.fa-exclamation-triangle { color: var(--danger); }
        .location-badge i.fa-spinner { color: var(--primary); }

        .map-section { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 30px; border: 1px solid var(--border-light); box-shadow: var(--shadow-md); }
        .map-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .map-header h2 { font-size: 18px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .map-header h2 i { color: var(--primary); }
        .refresh-btn { padding: 10px 20px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-full); color: var(--text-secondary); font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px; }
        .refresh-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        #bigMap { height: 400px; width: 100%; border-radius: var(--radius-md); border: 1px solid var(--border-light); z-index: 1; box-shadow: var(--shadow-sm); }
        @media (max-width: 768px) { #bigMap { height: 300px; } }

        .filter-bar { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 20px 24px; margin-bottom: 24px; border: 1px solid var(--border-light); display: flex; flex-wrap: wrap; gap: 20px; align-items: center; box-shadow: var(--shadow-sm); }
        .filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
        .filter-label i { color: var(--primary); }
        .filter-btn { padding: 8px 16px; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-secondary); border-radius: var(--radius-full); font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary-gradient); color: white; border-color: transparent; }
        .filter-select { padding: 8px 16px; border: 1px solid var(--border-color); background: var(--bg-primary); color: var(--text-primary); border-radius: var(--radius-full); font-size: 12px; font-weight: 500; cursor: pointer; outline: none; }
        .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .sort-options { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        @media (max-width: 768px) { .filter-bar { flex-direction: column; align-items: flex-start; } .sort-options { margin-left: 0; width: 100%; } .sort-select { width: 100%; } }

        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .results-count { background: var(--bg-secondary); padding: 8px 16px; border-radius: var(--radius-full); font-size: 14px; border: 1px solid var(--border-light); color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
        .results-count i { color: var(--primary); }
        .results-count span { font-weight: 600; color: var(--text-primary); margin: 0 4px; }

        .clinics-list { display: flex; flex-direction: column; gap: 20px; margin-bottom: 30px; }

        .clinic-card { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 24px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); transition: all 0.3s; display: flex; flex-wrap: wrap; gap: 24px; position: relative; }
        .clinic-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: var(--primary); }

        .clinic-image-container { flex: 0 0 180px; height: 180px; border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-primary); position: relative; }
        .clinic-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .clinic-card:hover .clinic-image { transform: scale(1.05); }
        .clinic-image-placeholder { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: linear-gradient(135deg, var(--primary-light) 0%, var(--bg-primary) 100%); }
        .clinic-image-placeholder i { font-size: 48px; color: var(--primary); opacity: 0.6; }
        .clinic-image-placeholder span { font-size: 12px; color: var(--text-muted); font-weight: 500; }
        .clinic-type-badge { position: absolute; top: 12px; right: 12px; padding: 6px 12px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; color: white; letter-spacing: 0.5px; box-shadow: var(--shadow-sm); z-index: 1; }

        .clinic-info-main { flex: 2; min-width: 280px; }
        .clinic-info-main h3 { font-size: 20px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px; }
        .clinic-meta { display: flex; align-items: center; gap: 15px; margin-bottom: 12px; flex-wrap: wrap; }
        .clinic-rating { background: #FFC107; color: #333; padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 4px; }
        .clinic-distance { color: var(--primary); font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .travel-time { display: flex; gap: 15px; margin: 12px 0; flex-wrap: wrap; }
        .travel-time-item { display: flex; align-items: center; gap: 6px; color: var(--text-secondary); font-size: 12px; background: var(--bg-primary); padding: 6px 14px; border-radius: var(--radius-full); }
        .travel-time-item i { color: var(--primary); font-size: 12px; }
        .clinic-address, .clinic-contact { color: var(--text-secondary); font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .clinic-address i, .clinic-contact i { color: var(--primary); width: 16px; }

        .clinic-info-side { flex: 1; min-width: 220px; display: flex; flex-direction: column; border-left: 1px solid var(--border-light); padding-left: 24px; }
        .clinic-hours { color: var(--text-secondary); font-size: 13px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .clinic-hours i { color: var(--primary); width: 16px; }
        .clinic-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; margin-bottom: 16px; width: fit-content; }
        .status-open { background: var(--open-bg); color: var(--open-text); }
        .status-closed { background: var(--closed-bg); color: var(--closed-text); }

        .card-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: auto; }
        .btn-view, .btn-view-map, .btn-book { flex: 1; padding: 10px 16px; border-radius: var(--radius-md); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px; min-width: 80px; }
        .btn-view { background: var(--bg-primary); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-view:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .btn-view-map { background: var(--primary-light); color: var(--primary); border: 1px solid var(--primary-light); }
        .btn-view-map:hover { background: var(--primary); color: white; }
        .btn-book { background: var(--primary-gradient); color: white; border: none; }
        .btn-book:hover { transform: translateY(-2px); box-shadow: 0 8px 15px -5px var(--primary); }

        @media (max-width: 768px) {
            .clinic-image-container { flex: 0 0 100%; height: 160px; }
            .clinic-info-side { border-left: none; padding-left: 0; border-top: 1px solid var(--border-light); padding-top: 20px; }
            .travel-time { flex-direction: column; gap: 8px; }
            .clinic-type-badge { top: 8px; right: 8px; }
        }

        .load-more-container { text-align: center; margin: 20px 0 40px; }
        .btn-load-more { padding: 14px 32px; background: var(--bg-secondary); color: var(--primary); border: 2px solid var(--primary); border-radius: var(--radius-full); font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-load-more:hover:not(:disabled) { background: var(--primary); color: white; transform: translateY(-2px); box-shadow: 0 8px 20px -10px var(--primary); }
        .btn-load-more:disabled { opacity: 0.5; cursor: not-allowed; }

        .loading-location, .no-results { text-align: center; padding: 80px 20px; background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--border-light); }
        .loading-location i, .no-results i { font-size: 60px; color: var(--text-muted); margin-bottom: 20px; opacity: 0.5; }
        .loading-location h3, .no-results h3 { font-size: 20px; color: var(--text-primary); margin-bottom: 10px; }
        .loading-location p, .no-results p { color: var(--text-secondary); max-width: 400px; margin: 0 auto; }

        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast-notification { display: flex; align-items: center; gap: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px 24px; box-shadow: var(--shadow-lg); margin-bottom: 12px; min-width: 320px; animation: slideIn 0.3s ease; border-left: 4px solid var(--primary); }
        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }
        .toast-notification i { font-size: 20px; }
        .toast-notification.success i { color: var(--success); }
        .toast-notification.error i { color: var(--danger); }
        .toast-notification.info i { color: var(--info); }
        .toast-notification span { flex: 1; font-size: 14px; color: var(--text-primary); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }

        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; display: flex; align-items: center; justify-content: center; visibility: hidden; opacity: 0; transition: all 0.3s; }
        .loading-overlay.show { visibility: visible; opacity: 1; }
        .loading-spinner-large { width: 50px; height: 50px; border: 4px solid var(--border-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        [data-tooltip] { position: relative; cursor: help; }
        [data-tooltip]:hover::before { content: ''; position: absolute; top: -8px; left: 50%; transform: translateX(-50%); border-width: 5px; border-style: solid; border-color: transparent transparent var(--bg-secondary) transparent; z-index: 1001; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: var(--bg-secondary); color: var(--text-primary); padding: 8px 12px; border-radius: var(--radius-md); font-size: 12px; white-space: nowrap; box-shadow: var(--shadow-md); z-index: 1000; margin-bottom: 8px; border: 1px solid var(--border-light); font-weight: 500; }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-location-dot"></i>
                Find Clinics Near You
            </h1>
            <div class="location-badge" id="locationStatus">
                <i class="fas fa-spinner fa-pulse"></i>
                Detecting your location...
            </div>
        </div>

        <div class="map-section">
            <div class="map-header">
                <h2>
                    <i class="fas fa-map-marked-alt"></i>
                    Your Location & Nearby Clinics
                </h2>
                <button class="refresh-btn" onclick="getUserLocation()">
                    <i class="fas fa-sync-alt"></i> Refresh Location
                </button>
            </div>
            <div id="bigMap"></div>
        </div>

        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-filter"></i> Status:</span>
                <button class="filter-btn active" onclick="filterClinics('status', 'all', this)">All</button>
                <button class="filter-btn" onclick="filterClinics('status', 'open', this)">Open Now</button>
                <button class="filter-btn" onclick="filterClinics('status', 'closed', this)">Closed</button>
            </div>
            
            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-city"></i> City:</span>
                <select class="filter-select" id="cityFilter" onchange="filterByCity(this.value)">
                    <option value="all">All Cities</option>
                    <?php foreach($cities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <span class="filter-label"><i class="fas fa-circle-radiation"></i> Radius:</span>
                <select class="filter-select" id="radiusFilter" onchange="filterByRadius(this.value)">
                    <option value="2">Within 2 km</option>
                    <option value="5" selected>Within 5 km</option>
                    <option value="10">Within 10 km</option>
                    <option value="15">Within 15 km</option>
                </select>
            </div>

            <div class="sort-options">
                <span class="filter-label"><i class="fas fa-arrow-up-wide-short"></i> Sort by:</span>
                <select class="filter-select" id="sortSelect" onchange="sortClinics(this.value)">
                    <option value="distance" selected>📍 Distance (closest)</option>
                    <option value="rating">⭐ Highest rated</option>
                    <option value="open">🟢 Open now first</option>
                    <option value="name">📋 Name (A-Z)</option>
                </select>
            </div>
        </div>

        <div class="results-header">
            <div class="results-count">
                <i class="fas fa-clinic-medical"></i>
                <span id="clinicCount">0</span> clinics found
                <span id="showingCount"></span>
            </div>
        </div>

        <div class="clinics-list" id="clinicsList">
            <div class="loading-location">
                <i class="fas fa-map-pin fa-spin"></i>
                <h3>Detecting your location...</h3>
                <p>Please allow location access to see nearby clinics</p>
            </div>
        </div>

        <div class="load-more-container" id="loadMoreContainer" style="display: none;">
            <button class="btn-load-more" onclick="loadMore()" id="loadMoreBtn">
                <i class="fas fa-spinner"></i> Load More
            </button>
        </div>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            let icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'info') icon = 'info-circle';
            toast.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('show');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        document.addEventListener('DOMContentLoaded', function() {
            initBigMap();
            getUserLocation();
            setTimeout(() => {
                showToast('Welcome to Nearby Clinics! 👋', 'success');
            }, 500);
        });

        let bigMap;
        let userMarker;
        let clinicMarkers = [];
        let userPosition = null;
        const clinics = <?php echo json_encode($clinics); ?>;
        let currentStatusFilter = 'all';
        let currentCityFilter = 'all';
        let currentRadiusFilter = 5;
        let currentSort = 'distance';
        let filteredClinics = [];
        let itemsPerPage = 5;
        let currentPage = 1;
        let displayedClinics = [];

        function initBigMap() {
            const defaultCenter = [14.2994, 120.9596];
            bigMap = L.map('bigMap').setView(defaultCenter, 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(bigMap);
        }

        function getUserLocation() {
            showLoading();
            document.getElementById('locationStatus').innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Detecting your location...';
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        userPosition = [position.coords.latitude, position.coords.longitude];
                        document.getElementById('locationStatus').innerHTML = `<i class="fas fa-check-circle" style="color: var(--success);"></i> Location detected`;
                        if (userMarker) bigMap.removeLayer(userMarker);
                        userMarker = L.marker(userPosition, {
                            icon: L.divIcon({ html: `<div style="background-color: var(--primary); width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>`, iconSize: [26, 26], iconAnchor: [13, 13] }),
                            title: 'Your Location'
                        }).addTo(bigMap);
                        userMarker.bindPopup('<b>Your Location</b>').openPopup();
                        bigMap.setView(userPosition, 12);
                        calculateDistancesAndDisplay();
                        hideLoading();
                        showToast('Location detected successfully!', 'success');
                    },
                    (error) => {
                        document.getElementById('locationStatus').innerHTML = `<i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Location access denied`;
                        displayAllClinics();
                        hideLoading();
                        showToast('Please enable location access', 'error');
                    }
                );
            } else {
                document.getElementById('locationStatus').innerHTML = `<i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Geolocation not supported`;
                displayAllClinics();
                hideLoading();
                showToast('Geolocation not supported', 'error');
            }
        }

        function calculateDistancesAndDisplay() {
            if (!userPosition) return;
            clinics.forEach(clinic => {
                if (clinic.latitude && clinic.longitude) {
                    const clinicPos = [parseFloat(clinic.latitude), parseFloat(clinic.longitude)];
                    const distance = haversineDistance(userPosition, clinicPos);
                    clinic.distance = distance;
                    clinic.travelTime = {
                        car: Math.round(distance / 30 * 60),
                        foot: Math.round(distance / 5 * 60)
                    };
                } else {
                    clinic.distance = 9999;
                    clinic.travelTime = { car: '--', foot: '--' };
                }
            });
            applyFilters();
        }

        function haversineDistance(coords1, coords2) {
            const toRad = (x) => x * Math.PI / 180;
            const lat1 = coords1[0];
            const lon1 = coords1[1];
            const lat2 = coords2[0];
            const lon2 = coords2[1];
            const R = 6371;
            const dLat = toRad(lat2 - lat1);
            const dLon = toRad(lon2 - lon1);
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        function displayAllClinics() {
            clinics.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            applyFilters();
        }

        function filterClinics(type, value, btn) {
            if (type === 'status') {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentStatusFilter = value;
            }
            applyFilters();
            showToast(`Filtered by: ${value === 'all' ? 'All clinics' : value + ' now'}`, 'info');
        }

        function filterByCity(city) {
            currentCityFilter = city;
            applyFilters();
            showToast(`Filtered by city: ${city === 'all' ? 'All cities' : city}`, 'info');
        }

        function filterByRadius(radius) {
            currentRadiusFilter = parseInt(radius);
            applyFilters();
            showToast(`Radius set to: ${radius} km`, 'info');
        }

        function sortClinics(sortBy) {
            currentSort = sortBy;
            applyFilters();
            showToast('Clinics re-sorted', 'info');
        }

        function applyFilters() {
            filteredClinics = clinics.filter(clinic => {
                if (currentStatusFilter !== 'all') {
                    const status = clinic.is_open ? 'open' : 'closed';
                    if (status !== currentStatusFilter) return false;
                }
                if (currentCityFilter !== 'all' && clinic.city !== currentCityFilter) return false;
                if (userPosition && currentRadiusFilter > 0) {
                    if (!clinic.distance || clinic.distance > currentRadiusFilter) return false;
                }
                return true;
            });
            
            if (currentSort === 'distance') {
                filteredClinics.sort((a, b) => (a.distance || 9999) - (b.distance || 9999));
            } else if (currentSort === 'rating') {
                filteredClinics.sort((a, b) => (b.rating || 0) - (a.rating || 0));
            } else if (currentSort === 'name') {
                filteredClinics.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            } else if (currentSort === 'open') {
                filteredClinics.sort((a, b) => {
                    if (a.is_open && !b.is_open) return -1;
                    if (!a.is_open && b.is_open) return 1;
                    return (a.distance || 9999) - (b.distance || 9999);
                });
            }
            
            document.getElementById('clinicCount').textContent = filteredClinics.length;
            currentPage = 1;
            displayPaginatedClinics();
            updateMapMarkers();
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function displayPaginatedClinics() {
            const list = document.getElementById('clinicsList');
            if (filteredClinics.length === 0) {
                list.innerHTML = `<div class="no-results"><i class="fas fa-map-marked-alt"></i><h3>No clinics found</h3><p>Try adjusting your filters or increasing the radius</p></div>`;
                document.getElementById('loadMoreContainer').style.display = 'none';
                return;
            }
            
            const endIndex = Math.min(currentPage * itemsPerPage, filteredClinics.length);
            displayedClinics = filteredClinics.slice(0, endIndex);
            document.getElementById('showingCount').textContent = `(showing ${displayedClinics.length} of ${filteredClinics.length})`;
            
            if (displayedClinics.length < filteredClinics.length) {
                document.getElementById('loadMoreContainer').style.display = 'block';
                document.getElementById('loadMoreBtn').innerHTML = '<i class="fas fa-spinner"></i> Load More (' + (filteredClinics.length - displayedClinics.length) + ' remaining)';
            } else {
                document.getElementById('loadMoreContainer').style.display = 'none';
            }
            
            let html = '';
            displayedClinics.forEach(clinic => {
                const distanceText = clinic.distance ? `${clinic.distance.toFixed(1)} km away` : 'Distance unknown';
                const carTime = clinic.travelTime ? (clinic.travelTime.car < 60 ? `${clinic.travelTime.car} mins` : `${Math.floor(clinic.travelTime.car / 60)} hr ${clinic.travelTime.car % 60} mins`) : '--';
                const footTime = clinic.travelTime ? (clinic.travelTime.foot < 60 ? `${clinic.travelTime.foot} mins` : `${Math.floor(clinic.travelTime.foot / 60)} hr ${clinic.travelTime.foot % 60} mins`) : '--';
                const hasImage = clinic.image_path && clinic.image_path !== '';
                const imageHtml = hasImage ? 
                    `<img src="${clinic.image_path}" alt="${escapeHtml(clinic.name)}" class="clinic-image" onerror="this.parentElement.innerHTML = '<div class=\'clinic-image-placeholder\'><i class=\'fas fa-clinic-medical\'></i><span>No Image</span></div>'">` :
                    `<div class="clinic-image-placeholder"><i class="fas fa-clinic-medical"></i><span>No Image</span></div>`;
                
                html += `
                    <div class="clinic-card" data-id="${clinic.id}">
                        <div class="clinic-image-container">
                            ${imageHtml}
                            <span class="clinic-type-badge" style="background: ${clinic.type_color}">
                                ${escapeHtml(clinic.type)}
                            </span>
                        </div>
                        <div class="clinic-info-main">
                            <h3>${escapeHtml(clinic.name)}</h3>
                            <div class="clinic-meta">
                                <span class="clinic-rating">⭐ ${clinic.rating || '0.0'}</span>
                                <span class="clinic-distance"><i class="fas fa-location-arrow"></i> ${distanceText}</span>
                            </div>
                            <div class="travel-time">
                                <span class="travel-time-item"><i class="fas fa-car"></i> ${carTime} by car</span>
                                <span class="travel-time-item"><i class="fas fa-walking"></i> ${footTime} on foot</span>
                            </div>
                            <div class="clinic-address"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(clinic.address)}</div>
                            <div class="clinic-contact"><i class="fas fa-phone"></i> ${escapeHtml(clinic.contact)}</div>
                        </div>
                        <div class="clinic-info-side">
                            <div class="clinic-hours"><i class="fas fa-clock"></i> ${escapeHtml(clinic.hours)}</div>
                            <span class="clinic-status ${clinic.is_open ? 'status-open' : 'status-closed'}">
                                <i class="fas ${clinic.is_open ? 'fa-door-open' : 'fa-door-closed'}"></i>
                                ${clinic.is_open ? 'Open Now' : 'Closed'}
                            </span>
                            <div class="card-actions">
                                <a href="clinic-details.php?id=${clinic.id}" class="btn-view"><i class="fas fa-eye"></i> View</a>
                                <button class="btn-view-map" onclick="focusOnMap(${clinic.id})"><i class="fas fa-map-marker-alt"></i> Map</button>
                                <a href="book-appointment.php?clinic_id=${clinic.id}" class="btn-book"><i class="fas fa-calendar-check"></i> Book</a>
                            </div>
                        </div>
                    </div>
                `;
            });
            list.innerHTML = html;
        }

        function loadMore() {
            currentPage++;
            displayPaginatedClinics();
            updateMapMarkers();
        }

        function updateMapMarkers() {
            clinicMarkers.forEach(marker => bigMap.removeLayer(marker));
            clinicMarkers = [];
            displayedClinics.forEach(clinic => {
                if (clinic.latitude && clinic.longitude) {
                    const position = [parseFloat(clinic.latitude), parseFloat(clinic.longitude)];
                    const markerIcon = L.divIcon({
                        html: `<div style="position: relative;"><div style="background-color: ${clinic.type_color}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;"><i class="fas fa-eye"></i></div>${clinic.is_open ? '<div style="position:absolute; top:-2px; right:-2px; background:var(--success); width:10px; height:10px; border-radius:50%; border:2px solid white; box-shadow:0 2px 5px rgba(0,0,0,0.2);"></div>' : '<div style="position:absolute; top:-2px; right:-2px; background:var(--danger); width:10px; height:10px; border-radius:50%; border:2px solid white; box-shadow:0 2px 5px rgba(0,0,0,0.2);"></div>'}</div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        popupAnchor: [0, -15],
                        className: 'clinic-marker'
                    });
                    const marker = L.marker(position, { icon: markerIcon, title: clinic.name }).addTo(bigMap);
                    const carTime = clinic.travelTime ? (clinic.travelTime.car < 60 ? `${clinic.travelTime.car} mins` : `${Math.floor(clinic.travelTime.car / 60)} hr ${clinic.travelTime.car % 60} mins`) : '--';
                    marker.bindPopup(`<b>${escapeHtml(clinic.name)}</b><br>${escapeHtml(clinic.address)}<br><span style="color: ${clinic.is_open ? 'var(--success)' : 'var(--danger)'}"><i class="fas ${clinic.is_open ? 'fa-door-open' : 'fa-door-closed'}"></i> ${clinic.is_open ? 'Open Now' : 'Closed'}</span><br><i class="fas fa-location-arrow"></i> ${clinic.distance ? clinic.distance.toFixed(1) + ' km' : 'Unknown'}<br><i class="fas fa-car"></i> ${carTime} by car`);
                    clinicMarkers.push(marker);
                }
            });
            if (userPosition && clinicMarkers.length > 0) {
                const allPoints = [userPosition, ...clinicMarkers.map(m => m.getLatLng())];
                const bounds = L.latLngBounds(allPoints);
                bigMap.fitBounds(bounds, { padding: [50, 50] });
            } else if (userPosition) {
                bigMap.setView(userPosition, 12);
            } else if (clinicMarkers.length > 0) {
                const bounds = L.latLngBounds(clinicMarkers.map(m => m.getLatLng()));
                bigMap.fitBounds(bounds, { padding: [50, 50] });
            }
        }

        function focusOnMap(clinicId) {
            const clinic = displayedClinics.find(c => c.id == clinicId);
            if (clinic && clinic.latitude && clinic.longitude) {
                const position = [parseFloat(clinic.latitude), parseFloat(clinic.longitude)];
                bigMap.setView(position, 16);
                const marker = clinicMarkers.find(m => m.getLatLng().lat === position[0] && m.getLatLng().lng === position[1]);
                if (marker) marker.openPopup();
            }
        }

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase().trim();
                if (searchTerm === '') {
                    applyFilters();
                } else {
                    filteredClinics = clinics.filter(clinic => (clinic.name && clinic.name.toLowerCase().includes(searchTerm)) || (clinic.address && clinic.address.toLowerCase().includes(searchTerm)));
                    document.getElementById('clinicCount').textContent = filteredClinics.length;
                    currentPage = 1;
                    displayPaginatedClinics();
                    updateMapMarkers();
                }
            });
        }
    </script>
</body>
</html>