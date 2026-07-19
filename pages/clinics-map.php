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

// Get all clinics with coordinates
$clinics_query = mysqli_query($conn, "SELECT * FROM clinics WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY name");
$clinics = [];
while($clinic = mysqli_fetch_assoc($clinics_query)) {
    // Add open status to each clinic
    $clinic['is_open'] = isClinicOpen($clinic['hours']);
    
    // Get average rating from reviews
    $rating_query = mysqli_query($conn, "SELECT AVG(rating) as avg_rating FROM clinic_reviews WHERE clinic_id = {$clinic['id']}");
    $rating_row = mysqli_fetch_assoc($rating_query);
    $clinic['rating'] = round($rating_row['avg_rating'] ?? 0, 1);
    
    // Determine clinic category/type based on name
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
    
    // Get clinic image path
    $clinic['image_path'] = getClinicImage($clinic);
    
    $clinics[] = $clinic;
}

function getClinicImage($clinic) {
    $basePath = '/eyecore';
    
    if (!empty($clinic['cover_photo'])) {
        $path = $basePath . '/assets/images/clinic-covers/' . $clinic['cover_photo'];
    } elseif (!empty($clinic['clinic_image'])) {
        $path = $basePath . '/assets/images/clinic-images/' . $clinic['clinic_image'];
    } elseif (!empty($clinic['logo'])) {
        $path = $basePath . '/assets/images/clinic-logos/' . $clinic['logo'];
    } elseif (!empty($clinic['clinic_logo'])) {
        $logo = $clinic['clinic_logo'];
        if (strpos($logo, 'uploads/') !== false) {
            $path = $basePath . '/' . $logo;
        } else {
            $path = $basePath . '/assets/images/clinic-logos/' . $logo;
        }
    } else {
        return null;
    }
    
    // Check if file exists before returning path
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $path;
    if (file_exists($fullPath)) {
        return $path;
    }
    
    return null;
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

// Count reservations for badge
$reservation_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')");
$reservation_count_row = mysqli_fetch_assoc($reservation_count_query);
$reservation_count = $reservation_count_row['total'] ?? 0;

// Get total points
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

// Get sale count for badge
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

function isClinicOpen($hours) {
    // Always return true - clinic is always open
    return true;
}
function isDayInRange($current_day, $start_day, $end_day) {
    $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    
    $current = $days[strtolower(substr($current_day, 0, 3))];
    $start = $days[strtolower(substr($start_day, 0, 3))];
    $end = $days[strtolower(substr($end_day, 0, 3))];
    
    if ($start <= $end) {
        return ($current >= $start && $current <= $end);
    } else {
        return ($current >= $start || $current <= $end);
    }
}

function isTimeInRange($time_range, $current_time) {
    if (preg_match('/([0-9]+[amp\s]+)-([0-9]+[amp\s]+)/i', $time_range, $matches)) {
        $start_str = trim($matches[1]);
        $end_str = trim($matches[2]);
        
        $start_24 = convertTo24Hour($start_str);
        $end_24 = convertTo24Hour($end_str);
        
        if ($end_24 < $start_24) {
            if ($current_time >= $start_24 || $current_time <= $end_24) {
                return true;
            }
        } else {
            if ($current_time >= $start_24 && $current_time <= $end_24) {
                return true;
            }
        }
    }
    
    return false;
}

function convertTo24Hour($time_str) {
    $time_str = strtolower(trim($time_str));
    
    if (preg_match('/([0-9]+)([amp]+)?/i', $time_str, $matches)) {
        $hour = (int)$matches[1];
        $ampm = $matches[2] ?? '';
        
        if ($ampm == 'pm' && $hour < 12) {
            $hour += 12;
        } else if ($ampm == 'am' && $hour == 12) {
            $hour = 0;
        }
        
        return sprintf('%02d:00', $hour);
    }
    
    return '00:00';
}

// ============================================
// HELPER FUNCTIONS
// ============================================
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

// ============================================
// SET ACTIVE NAV FOR NAVBAR
// ============================================
$active_nav = 'discover';

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
    <title>Clinic Map - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Leaflet CSS and JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Leaflet Marker Cluster -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
    <style>
        /* ===== RESET AND BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
            overflow-x: hidden;
            background: var(--bg-primary);
        }

        body {
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            
            --secondary: #FF8C42;
            --secondary-light: #FFF1E6;
            
            --accent-1: #4158D0;
            --accent-2: #C850C0;
            --accent-gradient: linear-gradient(43deg, #4158D0 0%, #C850C0 46%, #FFCC70 100%);
            
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
            --shadow-hover: 0 30px 50px -20px rgba(0,183,97,0.3);
            
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 999px;
            
            --danger: #FF4444;
            --warning: #FF8C42;
            --info: #17A2B8;
            --success: #00B761;
            
            --open-bg: #d4edda;
            --open-text: #28a745;
            --closed-bg: #f8d7da;
            --closed-text: #721c24;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --card-bg: #242424;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #6B7280;
            --border-color: #2D2D2D;
            --border-light: #262626;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.2);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.4);
            
            --open-bg: #2d4a2d;
            --open-text: #7ac97a;
            --closed-bg: #5a2d2d;
            --closed-text: #ff9999;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            max-width: 100%;
            padding: 0;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
            padding: 20px 20px 0 20px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            font-size: 24px;
        }

        .total-badge {
            background: var(--bg-secondary);
            padding: 12px 24px;
            border-radius: 30px;
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            box-shadow: var(--shadow-sm);
        }

        .total-badge i {
            color: var(--primary);
        }

        .total-badge span {
            font-weight: 600;
            color: var(--primary);
            margin-right: 4px;
        }

        /* ===== MAP CONTAINER ===== */
        .map-wrapper {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 0;
            height: calc(100vh - 140px);
            background: var(--bg-secondary);
            overflow: hidden;
        }

        /* Map Section */
        .map-section {
            position: relative;
            height: 100%;
            background: #e0e0e0;
        }

        #map {
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        .map-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--bg-secondary);
            padding: 20px 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 10;
            color: var(--text-primary);
            border: 1px solid var(--border-light);
        }

        .map-loading i {
            font-size: 24px;
            color: var(--primary);
        }

        /* Map Controls - Compact */
        .map-controls {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 2;
            display: flex;
            gap: 8px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(8px);
            padding: 8px 12px;
            border-radius: var(--radius-full);
        }

        .map-control-btn {
            width: 38px;
            height: 38px;
            background: var(--bg-secondary);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--text-secondary);
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }

        .map-control-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Zoom Level Indicator */
        .zoom-level {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            color: white;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            z-index: 2;
            font-family: monospace;
        }

        /* Legend - Simplified */
        .map-legend {
            position: absolute;
            bottom: 20px;
            left: 80px;
            background: var(--bg-secondary);
            padding: 8px 12px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            z-index: 2;
            font-size: 10px;
            border: 1px solid var(--border-light);
            display: flex;
            gap: 12px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-secondary);
        }

        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        /* Sidebar */
        .map-sidebar {
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            border-left: 1px solid var(--border-light);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sidebar-header h2 i {
            color: var(--primary);
        }

        .stats-row {
            display: flex;
            gap: 20px;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-primary);
            padding: 6px 12px;
            border-radius: var(--radius-full);
        }

        .stat i {
            color: var(--primary);
            font-size: 12px;
        }

        .stat span {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Search Box */
        .search-box {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-light);
            position: relative;
        }

        .search-box .search-icon {
            position: absolute;
            left: 32px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            z-index: 1;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            transition: all 0.2s;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .search-box input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        /* Filter Section - Simplified */
        .filter-section {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .filter-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .filter-select {
            flex: 1;
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 13px;
            background: var(--bg-primary);
            color: var(--text-primary);
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .sort-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .sort-row span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .sort-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .sort-chip {
            padding: 5px 12px;
            border: 1px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-secondary);
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .sort-chip:hover,
        .sort-chip.active {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        /* Active Filters */
        .active-filters {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--primary-light);
            color: var(--primary-dark);
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 500;
        }

        .filter-tag i {
            font-size: 10px;
            cursor: pointer;
        }

        .filter-tag i:hover {
            color: var(--danger);
        }

        .clear-filters-btn {
            padding: 4px 10px;
            background: none;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            font-size: 11px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .clear-filters-btn:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        /* Clinics List with Images */
        .clinics-list {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }

        /* Skeleton Loading */
        .clinic-skeleton {
            padding: 15px;
        }

        .skeleton-item {
            background: linear-gradient(90deg, var(--border-light) 25%, var(--border-color) 50%, var(--border-light) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: var(--radius-lg);
            margin-bottom: 12px;
            height: 100px;
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Clinic Item with Image */
        .clinic-item {
            display: flex;
            gap: 15px;
            padding: 16px;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
            position: relative;
        }

        .clinic-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .clinic-item.active {
            border-color: var(--danger);
        }

        .clinic-item-image {
            width: 70px;
            height: 70px;
            border-radius: var(--radius-md);
            overflow: hidden;
            flex-shrink: 0;
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
        }

        .clinic-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .clinic-item-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-light), var(--bg-primary));
            color: var(--primary);
            font-size: 24px;
        }

        .clinic-item-content {
            flex: 1;
            min-width: 0;
        }

        .clinic-type-badge {
            display: inline-block;
            margin-bottom: 6px;
            padding: 3px 8px;
            border-radius: var(--radius-full);
            font-size: 9px;
            font-weight: 600;
            color: white;
        }

        .clinic-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 5px;
        }

        .clinic-item-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .clinic-item-rating {
            background: #ffc107;
            color: #333;
            padding: 2px 6px;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 600;
        }

        .clinic-item-details {
            font-size: 11px;
            color: var(--text-secondary);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .clinic-item-details i {
            color: var(--primary);
            width: 14px;
            font-size: 11px;
        }

        .clinic-item-distance {
            font-size: 11px;
            color: var(--success);
            font-weight: 600;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .clinic-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 600;
            margin-top: 8px;
        }

        .status-open {
            background: var(--open-bg);
            color: var(--open-text);
        }

        .status-closed {
            background: var(--closed-bg);
            color: var(--closed-text);
        }

        /* Enhanced Empty State */
        .empty-state-enhanced {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }

        .empty-state-enhanced i {
            font-size: 64px;
            color: var(--text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state-enhanced h3 {
            font-size: 20px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .empty-state-enhanced p {
            color: var(--text-secondary);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .btn-reset {
            padding: 10px 24px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Popup with Image */
        .leaflet-popup-content {
            margin: 0 !important;
            min-width: 260px;
            max-width: 280px;
        }

        .leaflet-popup-content-wrapper {
            padding: 0 !important;
            border-radius: 14px !important;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15) !important;
        }

        .leaflet-popup-tip-container {
            margin-top: -1px;
        }

        .clinic-popup {
            font-family: 'Inter', -apple-system, sans-serif;
        }

        .popup-image {
            width: 100%;
            height: 110px;
            overflow: hidden;
            background: var(--bg-primary);
            position: relative;
        }

        .popup-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .popup-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #E3FCE9, #F5F7FA);
            color: #00B761;
            font-size: 36px;
        }

        .popup-body {
            padding: 14px 16px 0;
        }

        .popup-body h3 {
            font-size: 14px;
            font-weight: 700;
            color: #1A1A1A;
            margin: 0 0 8px;
            line-height: 1.3;
        }

        .popup-body p {
            font-size: 11px;
            color: #6B7280;
            margin: 0 0 5px;
            display: flex;
            align-items: flex-start;
            gap: 6px;
            line-height: 1.4;
        }

        .popup-body p i {
            color: #00B761;
            font-size: 11px;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .popup-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            margin: 8px 0 12px;
        }

        .popup-status.status-open  { background: #D1FAE5; color: #065F46; }
        .popup-status.status-closed { background: #FEE2E2; color: #991B1B; }

        .popup-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0;
            border-top: 1px solid #F3F4F6;
        }

        .popup-buttons a,
        .popup-buttons button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 11px 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: background 0.15s;
            color: #374151 !important;
            font-family: inherit;
            border-right: 1px solid #F3F4F6;
        }

        .popup-buttons a:last-child,
        .popup-buttons button:last-child {
            border-right: none;
        }

        .popup-btn-view { color: #1D4ED8 !important; }
        .popup-btn-dir  { color: #6B7280 !important; }
        .popup-btn-book { color: #00B761 !important; font-weight: 700 !important; }

        .popup-btn-view:hover { background: #EFF6FF; }
        .popup-btn-dir:hover  { background: #F9FAFB; }
        .popup-btn-book:hover { background: #D1FAE5; }

        .popup-btn-view i { color: #1D4ED8; }
        .popup-btn-dir  i { color: #6B7280; }
        .popup-btn-book i { color: #00B761; }

        /* Sidebar quick book button — polished */
        .quick-book-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            margin-top: 10px;
            padding: 9px 12px;
            background: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            color: white !important;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.2px;
            font-family: inherit;
            box-shadow: 0 2px 8px rgba(0,183,97,0.25);
        }

        .quick-book-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,183,97,0.35);
        }

        .quick-book-btn i {
            font-size: 12px;
        }

        /* ===== TOAST NOTIFICATIONS ===== */
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
            border-radius: var(--radius-md);
            padding: 14px 20px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 12px;
            min-width: 280px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--primary);
            font-size: 13px;
        }

        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }

        .toast-notification i {
            font-size: 18px;
        }

        .toast-notification.success i { color: var(--success); }
        .toast-notification.error i { color: var(--danger); }
        .toast-notification.info i { color: var(--info); }

        .toast-notification span {
            flex: 1;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ===== LOADING OVERLAY ===== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s;
        }

        .loading-overlay.show {
            visibility: visible;
            opacity: 1;
        }

        .loading-spinner-large {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }



        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .map-wrapper {
                grid-template-columns: 1fr;
                grid-template-rows: 1fr 450px;
                height: calc(100vh - 140px);
            }
            
            .map-section {
                height: 100%;
            }
            
            .map-sidebar {
                border-left: none;
                border-top: 1px solid var(--border-light);
            }
            
            .map-controls {
                bottom: 10px;
                right: 10px;
                padding: 6px 10px;
            }
            
            .map-control-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            
            .map-legend {
                bottom: 10px;
                left: 10px;
                font-size: 9px;
                padding: 6px 10px;
            }
            
            .zoom-level {
                bottom: 10px;
                left: 100px;
                font-size: 10px;
            }
            
            .stats-row {
                flex-wrap: wrap;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .clinic-item {
                padding: 12px;
                gap: 12px;
            }
            
            .clinic-item-image {
                width: 55px;
                height: 55px;
            }
            
            .clinic-item-name {
                font-size: 14px;
            }
            
            .page-header {
                padding: 15px 15px 0 15px;
            }
            
            .total-badge {
                padding: 8px 16px;
                font-size: 12px;
            }
        }

        /* ===== TOOLTIPS ===== */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--text-primary);
            color: var(--bg-secondary);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <!-- MAIN CONTENT (Navbar is already included above) -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-map-marked-alt"></i>
                Clinic Map
            </h1>
            <div class="total-badge">
                <i class="fas fa-map-pin"></i> <span id="totalClinicCount"><?php echo count($clinics); ?></span> Clinics on Map
            </div>
        </div>

        <!-- Map Wrapper -->
        <div class="map-wrapper">
            <!-- Map Section -->
            <div class="map-section">
                <div id="map"></div>
                <div class="map-loading" id="mapLoading">
                    <i class="fas fa-spinner fa-pulse"></i>
                    <span>Loading map...</span>
                </div>
                
                <!-- Map Controls - Compact -->
                <div class="map-controls">
                    <button class="map-control-btn" onclick="locateMe()" data-tooltip="My Location">
                        <i class="fas fa-location-dot"></i>
                    </button>
                    <button class="map-control-btn" onclick="centerMap()" data-tooltip="Center Map">
                        <i class="fas fa-crosshairs"></i>
                    </button>
                    <button class="map-control-btn" onclick="zoomIn()" data-tooltip="Zoom In">
                        <i class="fas fa-plus"></i>
                    </button>
                    <button class="map-control-btn" onclick="zoomOut()" data-tooltip="Zoom Out">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>

                <!-- Zoom Level Indicator -->
                <div class="zoom-level" id="zoomLevel">Zoom: 11</div>

                <!-- Legend - Simplified -->
                <div class="map-legend">
                    <div class="legend-item">
                        <div class="legend-color" style="background: #00B761;"></div>
                        <span>EO</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #4158D0;"></div>
                        <span>SS</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #FF8C42;"></div>
                        <span>SF</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background: #C850C0;"></div>
                        <span>Other</span>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="map-sidebar">
                <div class="sidebar-header">
                    <h2>
                        <i class="fas fa-map-marker-alt"></i>
                        Nearby Clinics
                    </h2>
                    <div class="stats-row">
                        <div class="stat">
                            <i class="fas fa-store"></i>
                            <span id="clinicCount"><?php echo count($clinics); ?></span>
                            <small>Clinics</small>
                        </div>
                        <div class="stat">
                            <i class="fas fa-city"></i>
                            <span><?php echo count($cities); ?></span>
                            <small>Cities</small>
                        </div>
                    </div>
                </div>

                <!-- Search with Icon -->
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" placeholder="Search clinic name or city...">
                </div>

                <!-- Simplified Filters -->
                <div class="filter-section">
                    <div class="filter-row">
                        <select id="statusFilter" class="filter-select">
                            <option value="all">🏥 All Clinics</option>
                            <option value="open">🟢 Open Now</option>
                            <option value="closed">🔴 Closed</option>
                        </select>
                        
                        <select id="cityFilter" class="filter-select">
                            <option value="all">📍 All Cities</option>
                            <?php foreach($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="sort-row">
                        <span>Sort by:</span>
                        <div class="sort-chips">
                            <button class="sort-chip active" data-sort="name">Name</button>
                            <button class="sort-chip" data-sort="distance">Distance</button>
                            <button class="sort-chip" data-sort="rating">Rating</button>
                        </div>
                    </div>
                    
                    <!-- Active Filters Display -->
                    <div id="activeFilters" class="active-filters" style="display: none;"></div>
                </div>

                <!-- Clinics List with Skeleton -->
                <div class="clinics-list" id="clinicsList">
                    <!-- Skeleton Loading -->
                    <div class="clinic-skeleton" id="clinicSkeleton">
                        <div class="skeleton-item"></div>
                        <div class="skeleton-item"></div>
                        <div class="skeleton-item"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <script>
        // ============================================
        // GLOBAL VARIABLES
        // ============================================
        let map;
        let markers = [];
        let markerCluster;
        let userMarker;
        let userPosition = null;
        let bounds;
        
        // Clinic data from PHP
        const clinics = <?php echo json_encode($clinics); ?>;
        
        // Current filters
        let currentStatusFilter = 'all';
        let currentCityFilter = 'all';
        let currentSearchTerm = '';
        let currentSort = 'name';
        let searchTimeout;
        
        // ============================================
        // TOAST NOTIFICATION
        // ============================================
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            
            let icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'info') icon = 'info-circle';
            
            toast.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // ============================================
        // LOADING OVERLAY
        // ============================================
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('show');
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }
        
        // ============================================
        // ESCAPE HTML
        // ============================================
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // ============================================
        // FAB MENU
        // ============================================
        function toggleFabMenu() {
            document.getElementById('fabMenu').classList.toggle('show');
            document.getElementById('fab').classList.toggle('active');
        }
        
        document.addEventListener('click', function(event) {
            const fab = document.getElementById('fab');
            const fabMenu = document.getElementById('fabMenu');
            
            if (fab && fabMenu && !fab.contains(event.target) && !fabMenu.contains(event.target)) {
                fabMenu.classList.remove('show');
                fab.classList.remove('active');
            }
        });
        
        // ============================================
        // MAP INITIALIZATION
        // ============================================
        function initMap() {
            const defaultCenter = [14.2994, 120.9596];
            
            map = L.map('map').setView(defaultCenter, 11);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19
            }).addTo(map);
            
            // Track zoom level
            map.on('zoomend', function() {
                document.getElementById('zoomLevel').textContent = `Zoom: ${map.getZoom()}`;
            });
            
            markerCluster = L.markerClusterGroup({
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
                zoomToBoundsOnClick: true,
                maxClusterRadius: 50
            });
            
            map.addLayer(markerCluster);
            bounds = L.latLngBounds();
            
            // Show skeleton then load clinics
            showSkeleton();
            setTimeout(() => {
                addMarkers();
                hideSkeleton();
            }, 500);
            
            document.getElementById('mapLoading').style.display = 'none';
            
            // Try to get user location
            setTimeout(() => {
                getUserLocation();
            }, 1000);
        }
        
        // ============================================
        // SKELETON LOADING
        // ============================================
        function showSkeleton() {
            const skeleton = document.getElementById('clinicSkeleton');
            const list = document.getElementById('clinicsList');
            if (skeleton) skeleton.style.display = 'block';
            if (list) list.style.opacity = '0.5';
        }
        
        function hideSkeleton() {
            const skeleton = document.getElementById('clinicSkeleton');
            const list = document.getElementById('clinicsList');
            if (skeleton) skeleton.style.display = 'none';
            if (list) list.style.opacity = '1';
        }
        
        // ============================================
        // ADD MARKERS TO MAP
        // ============================================
        function addMarkers() {
            markerCluster.clearLayers();
            markers = [];
            
            clinics.forEach(clinic => {
                if (clinic.latitude && clinic.longitude) {
                    const position = [parseFloat(clinic.latitude), parseFloat(clinic.longitude)];
                    const visible = checkVisibility(clinic);
                    
                    const markerColor = clinic.type_color;
                    
                    const markerIcon = L.divIcon({
                        className: 'custom-marker',
                        html: `<div style="background-color: ${markerColor}; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;"><i class="fas fa-eye"></i></div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        popupAnchor: [0, -15]
                    });
                    
                    const marker = L.marker(position, { icon: markerIcon, title: clinic.name });
                    
                    const hasImage = clinic.image_path && clinic.image_path !== null && clinic.image_path !== '';
const imageHtml = hasImage ? 
    `<img src="${clinic.image_path}" alt="${escapeHtml(clinic.name)}" onerror="this.parentElement.innerHTML = '<div class=\'popup-image-placeholder\'><i class=\'fas fa-clinic-medical\'></i></div>'; this.style.display='none';">` :
    `<div class="popup-image-placeholder"><i class="fas fa-clinic-medical"></i></div>`;
                    
                    const popupContent = `
                        <div class="clinic-popup">
                            <div class="popup-image">
                                ${imageHtml}
                            </div>
                            <div class="popup-body">
                                <h3>${escapeHtml(clinic.name)}</h3>
                                <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(clinic.address || clinic.city || '—')}</p>
                                ${clinic.contact ? `<p><i class="fas fa-phone"></i> ${escapeHtml(clinic.contact)}</p>` : ''}
                                <p><i class="fas fa-clock"></i> ${escapeHtml(clinic.hours || 'Hours not available')}</p>
                                <span class="popup-status ${clinic.is_open ? 'status-open' : 'status-closed'}">
                                    <i class="fas fa-circle" style="font-size:6px;"></i>
                                    ${clinic.is_open ? 'Open Now' : 'Closed Now'}
                                </span>
                            </div>
                            <div class="popup-buttons">
                                <a href="clinic-details.php?id=${clinic.id}" class="popup-btn-view"><i class="fas fa-eye"></i> View</a>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=${clinic.latitude},${clinic.longitude}" target="_blank" class="popup-btn-dir"><i class="fas fa-directions"></i> Go</a>
                                <button class="popup-btn-book" onclick="quickBookFromPopup(${clinic.id})"><i class="fas fa-calendar-plus"></i> Book</button>
                            </div>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent);
                    
                    markers.push({
                        id: clinic.id,
                        marker: marker,
                        clinic: clinic,
                        visible: visible
                    });
                    
                    if (visible) {
                        markerCluster.addLayer(marker);
                        bounds.extend(position);
                    }
                }
            });
            
            if (markerCluster.getLayers().length > 0) {
                map.fitBounds(bounds);
            }
            
            // Render clinics list
            renderClinicsList();
        }
        
        // ============================================
        // CHECK VISIBILITY
        // ============================================
        function checkVisibility(clinic) {
            let visible = true;
            
            if (currentStatusFilter !== 'all') {
                const status = clinic.is_open ? 'open' : 'closed';
                if (status !== currentStatusFilter) visible = false;
            }
            
            if (currentCityFilter !== 'all' && clinic.city !== currentCityFilter) {
                visible = false;
            }
            
            if (currentSearchTerm) {
                const name = (clinic.name || '').toLowerCase();
                const city = (clinic.city || '').toLowerCase();
                const search = currentSearchTerm.toLowerCase();
                if (!name.includes(search) && !city.includes(search)) {
                    visible = false;
                }
            }
            
            return visible;
        }
        
        // ============================================
        // RENDER CLINICS LIST
        // ============================================
        function renderClinicsList() {
            const list = document.getElementById('clinicsList');
            
            // Filter clinics
            let filteredClinics = clinics.filter(clinic => checkVisibility(clinic));
            
            // Sort clinics
            filteredClinics.sort((a, b) => {
                if (currentSort === 'name') {
                    return (a.name || '').localeCompare(b.name || '');
                } else if (currentSort === 'rating') {
                    return (b.rating || 0) - (a.rating || 0);
                } else if (currentSort === 'distance') {
                    const distA = a.distance || 9999;
                    const distB = b.distance || 9999;
                    return distA - distB;
                }
                return 0;
            });
            
            // Update counts
            document.getElementById('clinicCount').textContent = filteredClinics.length;
            document.getElementById('totalClinicCount').textContent = filteredClinics.length;
            
            // Update active filters display
            updateActiveFiltersDisplay();
            
            // Show empty state if no results
            if (filteredClinics.length === 0) {
                list.innerHTML = `
                    <div class="empty-state-enhanced">
                        <i class="fas fa-map-marked-alt"></i>
                        <h3>No clinics found</h3>
                        <p>Try adjusting your filters or search term</p>
                        <button class="btn-reset" onclick="resetAllFilters()">
                            <i class="fas fa-undo"></i> Reset Filters
                        </button>
                    </div>
                `;
                return;
            }
            
            // Render clinics
            let html = '';
            filteredClinics.forEach(clinic => {
                const distanceText = clinic.distance ? `${clinic.distance.toFixed(1)} km away` : 'Calculating...';
                const hasImage = clinic.image_path && clinic.image_path !== null && clinic.image_path !== '';
                

const imageHtml = hasImage ? 
    `<img src="${clinic.image_path}" alt="${escapeHtml(clinic.name)}" onerror="this.parentElement.innerHTML = '<div class=\'popup-image-placeholder\'><i class=\'fas fa-clinic-medical\'></i></div>'">` :
    `<div class="popup-image-placeholder"><i class="fas fa-clinic-medical"></i></div>`;
                
                html += `
                    <div class="clinic-item" 
                         data-id="${clinic.id}"
                         data-lat="${clinic.latitude}"
                         data-lng="${clinic.longitude}"
                         onclick="focusClinic(${clinic.id}, ${clinic.latitude}, ${clinic.longitude})">
                        
                        <div class="clinic-item-image">
                            ${imageHtml}
                        </div>
                        
                        <div class="clinic-item-content">
                            <span class="clinic-type-badge" style="background: ${clinic.type_color}">
                                ${clinic.type}
                            </span>
                            
                            <div class="clinic-item-header">
                                <h4 class="clinic-item-name">${escapeHtml(clinic.name)}</h4>
                                <span class="clinic-item-rating">⭐ ${clinic.rating || '0.0'}</span>
                            </div>
                            
                            <div class="clinic-item-details">
                                <i class="fas fa-map-marker-alt"></i> ${escapeHtml(clinic.city || (clinic.address ? clinic.address.substring(0, 30) : 'Unknown'))}
                            </div>
                            <div class="clinic-item-details">
                                <i class="fas fa-clock"></i> ${escapeHtml(clinic.hours)}
                            </div>
                            
                            <div class="clinic-item-distance">
                                <i class="fas fa-location-arrow"></i> 
                                <span class="distance-${clinic.id}">${distanceText}</span>
                            </div>
                            
                            <span class="clinic-status ${clinic.is_open ? 'status-open' : 'status-closed'}">
                                <i class="fas ${clinic.is_open ? 'fa-door-open' : 'fa-door-closed'}"></i>
                                ${clinic.is_open ? 'Open Now' : 'Closed'}
                            </span>
                            
                            <button class="quick-book-btn" onclick="quickBook(event, ${clinic.id})">
                                <i class="fas fa-calendar-check"></i> Quick Book
                            </button>
                        </div>
                    </div>
                `;
            });
            
            list.innerHTML = html;
        }
        
        // ============================================
        // UPDATE ACTIVE FILTERS DISPLAY
        // ============================================
        function updateActiveFiltersDisplay() {
            const container = document.getElementById('activeFilters');
            const filters = [];
            
            if (currentStatusFilter !== 'all') {
                filters.push(`<span class="filter-tag">Status: ${currentStatusFilter === 'open' ? 'Open Now' : 'Closed'} <i class="fas fa-times" onclick="removeFilter('status')"></i></span>`);
            }
            
            if (currentCityFilter !== 'all') {
                filters.push(`<span class="filter-tag">City: ${escapeHtml(currentCityFilter)} <i class="fas fa-times" onclick="removeFilter('city')"></i></span>`);
            }
            
            if (currentSearchTerm) {
                filters.push(`<span class="filter-tag">Search: ${escapeHtml(currentSearchTerm)} <i class="fas fa-times" onclick="removeFilter('search')"></i></span>`);
            }
            
            if (filters.length > 0) {
                container.innerHTML = filters.join('') + `<button class="clear-filters-btn" onclick="resetAllFilters()">Clear all</button>`;
                container.style.display = 'flex';
            } else {
                container.style.display = 'none';
            }
        }
        
        // ============================================
        // REMOVE FILTER
        // ============================================
        function removeFilter(filterType) {
            if (filterType === 'status') {
                currentStatusFilter = 'all';
                document.getElementById('statusFilter').value = 'all';
                document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                if (document.querySelector('.filter-btn[data-status="all"]')) {
                    document.querySelector('.filter-btn[data-status="all"]').classList.add('active');
                }
            } else if (filterType === 'city') {
                currentCityFilter = 'all';
                document.getElementById('cityFilter').value = 'all';
            } else if (filterType === 'search') {
                currentSearchTerm = '';
                document.getElementById('searchInput').value = '';
            }
            
            applyFilters();
            showToast('Filter removed', 'info');
        }
        
        // ============================================
        // RESET ALL FILTERS
        // ============================================
        function resetAllFilters() {
            currentStatusFilter = 'all';
            currentCityFilter = 'all';
            currentSearchTerm = '';
            currentSort = 'name';
            
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('cityFilter').value = 'all';
            document.getElementById('searchInput').value = '';
            
            document.querySelectorAll('.sort-chip').forEach(chip => chip.classList.remove('active'));
            document.querySelector('.sort-chip[data-sort="name"]').classList.add('active');
            
            applyFilters();
            showToast('All filters reset', 'success');
        }
        
        // ============================================
        // APPLY FILTERS
        // ============================================
        function applyFilters() {
            // Update markers visibility
            markerCluster.clearLayers();
            bounds = L.latLngBounds();
            
            markers.forEach(markerData => {
                const clinic = markerData.clinic;
                const visible = checkVisibility(clinic);
                
                if (visible) {
                    markerCluster.addLayer(markerData.marker);
                    if (clinic.latitude && clinic.longitude) {
                        bounds.extend([clinic.latitude, clinic.longitude]);
                    }
                }
            });
            
            if (markerCluster.getLayers().length > 0) {
                map.fitBounds(bounds);
            }
            
            // Re-render clinics list
            renderClinicsList();
        }
        
        // ============================================
        // FILTER FUNCTIONS
        // ============================================
        function filterByStatus() {
            currentStatusFilter = document.getElementById('statusFilter').value;
            applyFilters();
            showToast(`Filtered by: ${currentStatusFilter === 'all' ? 'All clinics' : currentStatusFilter + ' now'}`, 'info');
        }
        
        function filterByCity() {
            currentCityFilter = document.getElementById('cityFilter').value;
            applyFilters();
            showToast(`Filtered by city: ${currentCityFilter === 'all' ? 'All cities' : currentCityFilter}`, 'info');
        }
        
        function sortClinics(sortType, element) {
            currentSort = sortType;
            document.querySelectorAll('.sort-chip').forEach(chip => chip.classList.remove('active'));
            element.classList.add('active');
            applyFilters();
            showToast(`Sorted by ${sortType}`, 'info');
        }
        
        // Debounced search
        function debouncedSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearchTerm = document.getElementById('searchInput').value;
                applyFilters();
                if (currentSearchTerm) {
                    showToast(`Searching: "${currentSearchTerm}"`, 'info');
                }
            }, 300);
        }
        
        // ============================================
        // LOCATION FUNCTIONS
        // ============================================
        function getUserLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        userPosition = [position.coords.latitude, position.coords.longitude];
                        
                        if (userMarker) map.removeLayer(userMarker);
                        
                        userMarker = L.marker(userPosition, {
                            icon: L.divIcon({
                                html: `<div style="background-color: var(--primary); width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>`,
                                iconSize: [26, 26],
                                iconAnchor: [13, 13]
                            }),
                            title: 'Your Location'
                        }).addTo(map);
                        
                        userMarker.bindPopup('<b>Your Location</b>');
                        
                        calculateDistances(userPosition);
                        
                        if (currentSort === 'distance') {
                            applyFilters();
                        }
                        
                        showToast('Location detected!', 'success');
                    },
                    (error) => {
                        console.log('Geolocation failed');
                        showToast('Please enable location access', 'error');
                    }
                );
            } else {
                showToast('Geolocation not supported', 'error');
            }
        }
        
        function locateMe() {
            if (userPosition) {
                map.setView(userPosition, 14);
                if (currentSort !== 'distance') {
                    currentSort = 'distance';
                    document.querySelectorAll('.sort-chip').forEach(chip => chip.classList.remove('active'));
                    document.querySelector('.sort-chip[data-sort="distance"]').classList.add('active');
                    applyFilters();
                }
                showToast('Showing clinics near you', 'info');
            } else {
                getUserLocation();
            }
        }
        
        function calculateDistances(userPos) {
            if (!userPos) return;
            
            clinics.forEach(clinic => {
                if (clinic.latitude && clinic.longitude) {
                    const clinicPos = [parseFloat(clinic.latitude), parseFloat(clinic.longitude)];
                    const distance = haversineDistance(userPos, clinicPos);
                    clinic.distance = distance;
                    
                    const distanceElement = document.querySelector(`.distance-${clinic.id}`);
                    if (distanceElement) {
                        distanceElement.textContent = `${distance.toFixed(1)} km away`;
                    }
                }
            });
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
        
        // ============================================
        // MAP CONTROL FUNCTIONS
        // ============================================
        function centerMap() {
            if (userMarker) {
                map.setView(userMarker.getLatLng(), 14);
            } else {
                getUserLocation();
            }
        }
        
        function zoomIn() {
            map.setZoom(map.getZoom() + 1);
        }
        
        function zoomOut() {
            map.setZoom(map.getZoom() - 1);
        }
        
        function focusClinic(id, lat, lng) {
            const position = [parseFloat(lat), parseFloat(lng)];
            map.setView(position, 16);
            
            const markerData = markers.find(m => m.id == id);
            if (markerData) {
                markerData.marker.openPopup();
            }
            
            document.querySelectorAll('.clinic-item').forEach(item => item.classList.remove('active'));
            const activeItem = document.querySelector(`.clinic-item[data-id="${id}"]`);
            if (activeItem) activeItem.classList.add('active');
        }
        
        // ============================================
        // QUICK BOOK
        // ============================================
        function quickBook(event, clinicId) {
            event.stopPropagation();
            window.location.href = `book-appointment.php?clinic_id=${clinicId}`;
        }
        
        function quickBookFromPopup(clinicId) {
            window.location.href = `book-appointment.php?clinic_id=${clinicId}`;
        }
        
        // ============================================
        // EVENT LISTENERS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            // Filter event listeners
            document.getElementById('statusFilter').addEventListener('change', filterByStatus);
            document.getElementById('cityFilter').addEventListener('change', filterByCity);
            document.getElementById('searchInput').addEventListener('input', debouncedSearch);
            
            // Sort chip listeners
            document.querySelectorAll('.sort-chip').forEach(chip => {
                chip.addEventListener('click', function() {
                    sortClinics(this.dataset.sort, this);
                });
            });
        });
    </script>
</body>
</html>