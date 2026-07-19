<?php
include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$clinic_id = isset($_GET['clinic']) ? (int)$_GET['clinic'] : 0;

// Get clinic details
$query = mysqli_query($conn, "SELECT * FROM clinics WHERE id = $clinic_id");
$clinic = mysqli_fetch_assoc($query);

if (!$clinic) {
    header('Location: nearby.php');
    exit();
}

// Get clinic reviews count and rating
$reviews_query = mysqli_query($conn, "
    SELECT AVG(rating) as avg_rating, COUNT(*) as review_count 
    FROM clinic_reviews 
    WHERE clinic_id = $clinic_id
");
$reviews = mysqli_fetch_assoc($reviews_query);
$avg_rating = round($reviews['avg_rating'] ?? 0, 1);
$review_count = $reviews['review_count'] ?? 0;

// Default center (Cavite area)
$default_lat = 14.2994;
$default_lng = 120.9596;

// Clinic coordinates
$clinic_lat = $clinic['latitude'];
$clinic_lng = $clinic['longitude'];

// Get user data for sidebar
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Get user stats
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$favorites_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM favorites WHERE user_id = $user_id");
$favorites_row = mysqli_fetch_assoc($favorites_query);
$total_favorites = $favorites_row['total'] ?: 0;

// Get recent notifications
$recent_notifications = getRecentNotifications($user_id);
$unread_count = getUnreadNotificationCount($user_id);

// ============================================
// SAMPLE IMAGES (for demo purposes)
// ============================================
$sample_clinic_images = [
    'https://images.unsplash.com/photo-1587351021759-3772687fe598?w=400',
    'https://images.unsplash.com/photo-1573497019940-1c28c88b4f3e?w=400',
    'https://images.unsplash.com/photo-1629909613654-28e377c37b09?w=400',
    'https://images.unsplash.com/photo-1580281657525-6c04363c5c8c?w=400',
    'https://images.unsplash.com/photo-1519494026892-80f09e81f852?w=400'
];
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Directions to <?php echo htmlspecialchars($clinic['name']); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            
            --secondary: #FF8C42;
            --secondary-light: #FFF1E6;
            
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
        }

        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg-primary);
        }

        body {
            min-height: 100vh;
        }

        /* ===== DESKTOP NAVBAR ===== */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-secondary);
            padding: 12px 40px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-light);
        }

        @media (max-width: 1024px) {
            .navbar {
                padding: 12px 24px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                display: none;
            }
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .logo i {
            font-size: 28px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-full);
            transition: all 0.2s;
            font-weight: 500;
            font-size: 14px;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link:hover {
            color: var(--primary);
            background: var(--bg-primary);
        }

        .nav-link.active {
            background: var(--bg-primary);
            color: var(--primary);
            font-weight: 600;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* User Stats Badge */
        .user-stats-badge {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--bg-primary);
            padding: 8px 20px;
            border-radius: var(--radius-full);
        }

        .stat-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .stat-badge i {
            font-size: 16px;
        }

        .stat-badge .value {
            color: var(--text-primary);
        }

        /* Icon Buttons */
        .icon-btn {
            width: 44px;
            height: 44px;
            background: var(--bg-primary);
            border: none;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-secondary);
            font-size: 18px;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .icon-btn .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: var(--radius-full);
        }

        /* Profile Dropdown */
        .profile-dropdown {
            position: relative;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-primary);
            padding: 4px 4px 4px 16px;
            border-radius: var(--radius-full);
            cursor: pointer;
            border: 1px solid var(--border-light);
        }

        .profile-info {
            text-align: right;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .profile-points {
            font-size: 11px;
            color: var(--primary);
        }

        .profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 1000;
            margin-top: 12px;
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .profile-menu.show {
            display: block;
        }

        .profile-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
        }

        .profile-menu a:last-child {
            border-bottom: none;
        }

        .profile-menu a:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .profile-menu a i {
            width: 20px;
            color: var(--primary);
        }

        /* ===== MOBILE TOP ===== */
        .mobile-top {
            display: none;
            background: var(--bg-secondary);
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
        }

        @media (max-width: 768px) {
            .mobile-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .mobile-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ===== MOBILE BOTTOM NAV ===== */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            box-shadow: 0 -5px 20px rgba(0,0,0,0.05);
            padding: 8px 16px;
            z-index: 1000;
            border-top: 1px solid var(--border-light);
        }

        @media (max-width: 768px) {
            .mobile-bottom-nav {
                display: block;
            }
        }

        .mobile-nav-items {
            display: flex;
            justify-content: space-around;
            align-items: center;
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 11px;
            gap: 4px;
            padding: 8px 0;
            position: relative;
        }

        .mobile-nav-item i {
            font-size: 22px;
        }

        .mobile-nav-item.active {
            color: var(--primary);
        }

        .mobile-nav-item.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: var(--primary);
            border-radius: 50%;
        }

        .mobile-nav-item .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 9px;
            padding: 2px 5px;
            border-radius: var(--radius-full);
        }

        /* ===== MOBILE MENU ===== */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1999;
            display: none;
        }

        .mobile-menu-overlay.show {
            display: block;
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            right: -300px;
            width: 280px;
            height: 100vh;
            background: var(--bg-secondary);
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            transition: right 0.3s ease;
            overflow-y: auto;
        }

        .mobile-menu.open {
            right: 0;
        }

        .mobile-menu-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .mobile-user {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mobile-avatar {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            overflow: hidden;
        }

        .mobile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mobile-user h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .mobile-user p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .mobile-menu-header button {
            background: var(--bg-primary);
            border: none;
            width: 35px;
            height: 35px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .mobile-menu-items {
            padding: 15px;
        }

        .mobile-menu-items a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            margin-bottom: 5px;
        }

        .mobile-menu-items a i {
            width: 24px;
            color: var(--primary);
            font-size: 18px;
        }

        .mobile-menu-items a:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .mobile-menu-items .logout-link {
            color: var(--danger);
            margin-top: 20px;
            border-top: 1px solid var(--border-light);
            padding-top: 20px;
        }

        .mobile-menu-items .logout-link i {
            color: var(--danger);
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) {
            .main-content {
                padding: 30px 40px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px 16px 100px;
            }
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
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

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Clinic Header */
        .clinic-header {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .clinic-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .clinic-image {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-md);
            background-size: cover;
            background-position: center;
            position: relative;
            overflow: hidden;
        }

        .clinic-details h2 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .clinic-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .clinic-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .clinic-meta i {
            color: var(--primary);
        }

        .rating {
            color: #FFC107;
            font-weight: 600;
        }

        .clinic-actions {
            display: flex;
            gap: 12px;
        }

        .btn-primary {
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 8px 20px -10px var(--primary);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -8px var(--primary);
        }

        .btn-outline {
            padding: 12px 24px;
            border: 1px solid var(--primary);
            background: transparent;
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Map Container */
        .map-container {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-md);
            position: relative;
        }

        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .map-header h3 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .map-header h3 i {
            color: var(--primary);
        }

        .location-status {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 8px 16px;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .location-status i {
            font-size: 16px;
        }

        .map-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .map-control-btn {
            padding: 10px 20px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .map-control-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .map-control-btn i {
            font-size: 16px;
        }

        .map-control-btn.primary {
            background: var(--primary-gradient);
            color: white;
            border: none;
        }

        .map-control-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -10px var(--primary);
        }

        #directionsMap {
            width: 100%;
            height: 400px;
            border-radius: var(--radius-md);
            z-index: 1;
        }

        /* Travel Options */
        .travel-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .travel-options {
                grid-template-columns: 1fr;
            }
        }

        .travel-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 20px;
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.2s;
        }

        .travel-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary);
        }

        .travel-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 22px;
            flex-shrink: 0;
        }

        .travel-info {
            flex: 1;
        }

        .travel-info h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .travel-info p {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .travel-time {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .travel-time small {
            font-size: 12px;
            font-weight: normal;
            color: var(--text-muted);
        }

        /* Travel Note */
        .travel-note {
            font-size: 13px;
            color: var(--text-muted);
            margin: -5px 0 25px 0;
            text-align: center;
            background: var(--bg-primary);
            padding: 12px 20px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .travel-note i {
            color: var(--primary);
            font-size: 14px;
        }

        .travel-note strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Address Card */
        .address-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .address-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 22px;
            flex-shrink: 0;
        }

        .address-content {
            flex: 1;
        }

        .address-content h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .address-content p {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .copy-btn {
            padding: 10px 20px;
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .copy-btn:hover {
            background: var(--primary);
            color: white;
        }

        /* Toast Notifications */
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
            padding: 16px 24px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 12px;
            min-width: 320px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }

        .toast-notification i {
            font-size: 20px;
        }

        .toast-notification.success i { color: var(--success); }
        .toast-notification.error i { color: var(--danger); }
        .toast-notification.info i { color: var(--info); }

        .toast-notification span {
            flex: 1;
            font-size: 14px;
            color: var(--text-primary);
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

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: relative;
        }

        .notification-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 1000;
            margin-top: 10px;
            border: 1px solid var(--border-light);
        }

        .notification-menu.show {
            display: block;
        }

        .notification-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h3 {
            font-size: 16px;
            font-weight: 600;
        }

        .notification-header button {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 12px;
        }

        .notification-list {
            max-height: 350px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            padding: 15px 20px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-light);
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: var(--bg-primary);
        }

        .notification-item.unread {
            background: var(--primary-light);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }

        .notification-icon.appointment {
            background: #E3F2FD;
            color: #1976D2;
        }

        .notification-icon.favorite {
            background: #FCE4EC;
            color: #C2185B;
        }

        .notification-icon.promo {
            background: #FFF3E0;
            color: #F57C00;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
        }

        .notification-empty i {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .notification-footer {
            padding: 12px;
            text-align: center;
            border-top: 1px solid var(--border-light);
        }

        .notification-footer a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>

    <!-- DESKTOP NAVBAR -->
    <div class="navbar">
        <div class="nav-left">
            <div class="logo">
                <i class="fas fa-eye"></i>
                <span>eyecore</span>
            </div>
            
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="nearby.php" class="nav-link">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Nearby</span>
                </a>
                <a href="favorites.php" class="nav-link">
                    <i class="fas fa-heart"></i>
                    <span>Favorites</span>
                </a>
                <a href="my-appointments.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                </a>
            </div>
        </div>
        
        <div class="nav-right">
            <!-- User Stats Badge -->
            <div class="user-stats-badge">
                <div class="stat-badge">
                    <i class="fas fa-star" style="color: #FFC107;"></i>
                    <span class="value"><?php echo $total_points; ?></span>
                </div>
                <div class="stat-badge">
                    <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                    <span class="value"><?php echo $total_bookings; ?></span>
                </div>
            </div>
            
            <!-- Theme Toggle -->
            <button class="icon-btn" onclick="toggleTheme()" id="themeToggle">
                <i class="fas fa-moon"></i>
            </button>
            
            <!-- Notifications -->
            <div class="notification-dropdown">
                <button class="icon-btn" onclick="toggleNotifications()" id="notificationBell">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notification-menu" id="notificationMenu">
                    <div class="notification-header">
                        <h3>Notifications</h3>
                        <?php if ($unread_count > 0): ?>
                            <button onclick="markAllAsRead()">Mark all read</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-list">
                        <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                            <?php while($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                                <a href="<?php echo $notif['link'] ?: '#'; ?>" class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fas <?php 
                                            if($notif['type'] == 'appointment') echo 'fa-calendar-check';
                                            elseif($notif['type'] == 'favorite') echo 'fa-heart';
                                            elseif($notif['type'] == 'promo') echo 'fa-tags';
                                            else echo 'fa-bell';
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo $notif['title']; ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-footer">
                        <a href="notifications.php">View all</a>
                    </div>
                </div>
            </div>
            
            <!-- Profile Dropdown -->
            <div class="profile-dropdown">
                <div class="profile-trigger" onclick="toggleProfileMenu()">
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="profile-points"><?php echo $total_points; ?> pts</div>
                    </div>
                    <div class="profile-avatar">
                        <?php 
                        $avatar = $user_data['avatar'] ?? null;
                        if ($avatar && file_exists("../assets/images/profiles/$avatar")): 
                        ?>
                            <img src="../assets/images/profiles/<?php echo $avatar; ?>" alt="Profile">
                        <?php else: 
                            $first_letter = strtoupper(substr($_SESSION['user_name'], 0, 1));
                        ?>
                            <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; color: white;">
                                <?php echo $first_letter; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-menu" id="profileMenu">
                    <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="user_settings.php"><i class="fas fa-cog"></i> user_settings</a>
                    <a href="sale-products.php"><i class="fas fa-tags"></i> Hot Sales</a>
                    <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
                    <a href="../auth/user_logout.php" style="color: var(--danger);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- MOBILE TOP BAR -->
    <div class="mobile-top">
        <div class="mobile-logo">
            <i class="fas fa-eye"></i>
            <span>eyecore</span>
        </div>
        <div class="mobile-actions">
            <button class="icon-btn" onclick="toggleTheme()" style="width: 40px; height: 40px;">
                <i class="fas fa-moon"></i>
            </button>
            <div class="notification-dropdown">
                <button class="icon-btn" onclick="toggleNotifications()" style="width: 40px; height: 40px;">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAV -->
    <div class="mobile-bottom-nav">
        <div class="mobile-nav-items">
            <a href="dashboard.php" class="mobile-nav-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="nearby.php" class="mobile-nav-item active">
                <i class="fas fa-map-marker-alt"></i>
                <span>Nearby</span>
            </a>
            <a href="favorites.php" class="mobile-nav-item">
                <i class="fas fa-heart"></i>
                <span>Fav</span>
            </a>
            <a href="my-appointments.php" class="mobile-nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Books</span>
            </a>
            <a href="#" class="mobile-nav-item" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </a>
        </div>
    </div>

    <!-- MOBILE MENU OVERLAY -->
    <div class="mobile-menu-overlay" id="mobileMenuOverlay" onclick="closeMobileMenu()"></div>
    
    <!-- MOBILE MENU -->
    <div class="mobile-menu" id="mobileMenu">
        <div class="mobile-menu-header">
            <div class="mobile-user">
                <div class="mobile-avatar">
                    <?php 
                    $avatar = $user_data['avatar'] ?? null;
                    if ($avatar && file_exists("../assets/images/profiles/$avatar")): 
                    ?>
                        <img src="../assets/images/profiles/<?php echo $avatar; ?>" alt="Profile">
                    <?php else: 
                        $first_letter = strtoupper(substr($_SESSION['user_name'], 0, 1));
                    ?>
                        <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: white;">
                            <?php echo $first_letter; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <p><?php echo $total_points; ?> points • <?php echo $total_bookings; ?> bookings</p>
                </div>
            </div>
            <button onclick="closeMobileMenu()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="mobile-menu-items">
            <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> user_settings</a>
            <a href="sale-products.php"><i class="fas fa-tags"></i> Hot Sales</a>
            <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
            <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-directions"></i>
                Directions
            </h1>
            <a href="javascript:history.back()" class="back-btn">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>

        <!-- Clinic Header -->
        <div class="clinic-header">
            <div class="clinic-info">
                <div class="clinic-image" style="background-image: url('<?php echo $clinic['clinic_image'] ?? $sample_clinic_images[array_rand($sample_clinic_images)]; ?>');"></div>
                <div class="clinic-details">
                    <h2><?php echo htmlspecialchars($clinic['name']); ?></h2>
                    <div class="clinic-meta">
                        <span><i class="fas fa-star" style="color: #FFC107;"></i> <?php echo $avg_rating; ?> (<?php echo $review_count; ?> reviews)</span>
                        <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($clinic['hours']); ?></span>
                        <span><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($clinic['contact']); ?></span>
                    </div>
                </div>
            </div>
            <div class="clinic-actions">
                <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-outline">
                    <i class="fas fa-info-circle"></i> Details
                </a>
                <a href="book-appointment.php?clinic=<?php echo $clinic_id; ?>" class="btn-primary">
                    <i class="fas fa-calendar-plus"></i> Book Now
                </a>
            </div>
        </div>

        <!-- Map Container -->
        <div class="map-container">
            <div class="map-header">
                <h3><i class="fas fa-map-marked-alt"></i> Route Map</h3>
                <div class="location-status" id="locationStatus">
                    <i class="fas fa-spinner fa-pulse"></i>
                    Detecting your location...
                </div>
            </div>
            
            <!-- Map Controls -->
            <div class="map-controls">
                <button class="map-control-btn primary" onclick="getUserLocation()">
                    <i class="fas fa-location-dot"></i> Find My Location
                </button>
                <button class="map-control-btn" onclick="resetToDefault()">
                    <i class="fas fa-map"></i> Reset View
                </button>
            </div>
            
            <!-- Leaflet Map -->
            <div id="directionsMap"></div>
        </div>

        <!-- Travel Options -->
        <div class="travel-options">
            <div class="travel-card">
                <div class="travel-icon">
                    <i class="fas fa-car"></i>
                </div>
                <div class="travel-info">
                    <h4>By Car</h4>
                    <p>via Aguinaldo Highway</p>
                </div>
                <div class="travel-time" id="carTime">-- <small>mins</small></div>
            </div>

            <div class="travel-card">
                <div class="travel-icon">
                    <i class="fas fa-motorcycle"></i>
                </div>
                <div class="travel-info">
                    <h4>By Motorcycle</h4>
                    <p>faster route, less traffic</p>
                </div>
                <div class="travel-time" id="motorcycleTime">-- <small>mins</small></div>
            </div>

            <div class="travel-card">
                <div class="travel-icon">
                    <i class="fas fa-walking"></i>
                </div>
                <div class="travel-info">
                    <h4>Walking</h4>
                    <p>via roads and pathways</p>
                </div>
                <div class="travel-time" id="walkTime">-- <small>mins</small></div>
            </div>
        </div>

        <!-- Travel Note -->
        <div class="travel-note">
            <i class="fas fa-info-circle"></i>
            <span>Travel times are estimates based on average speeds: <strong>Car (30km/h)</strong> • <strong>Motorcycle (40km/h)</strong> • <strong>Walking (5km/h)</strong></span>
        </div>

        <!-- Full Address -->
        <div class="address-card">
            <div class="address-icon">
                <i class="fas fa-map-pin"></i>
            </div>
            <div class="address-content">
                <h4>Complete Address</h4>
                <p><?php echo htmlspecialchars($clinic['address']); ?>, <?php echo htmlspecialchars($clinic['city']); ?>, Cavite</p>
                <button class="copy-btn" onclick="copyAddress()">
                    <i class="fas fa-copy"></i> Copy Address
                </button>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // ============================================
        // MAP VARIABLES
        // ============================================
        let directionsMap;
        let userMarker = null;
        let clinicMarker = null;
        let defaultMarker = null;
        let routeLine = null;
        let userPosition = null;
        let userLocationEnabled = false;

        const defaultLat = <?php echo $default_lat; ?>;
        const defaultLng = <?php echo $default_lng; ?>;
        const clinicLat = <?php echo $clinic_lat; ?>;
        const clinicLng = <?php echo $clinic_lng; ?>;

        // ============================================
        // INITIALIZE MAP
        // ============================================
        function initMap() {
            // Calculate center between default and clinic
            const centerLat = (defaultLat + clinicLat) / 2;
            const centerLng = (defaultLng + clinicLng) / 2;
            
            // Create map
            directionsMap = L.map('directionsMap').setView([centerLat, centerLng], 11);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(directionsMap);
            
            // Add DEFAULT LOCATION marker (purple)
            defaultMarker = L.marker([defaultLat, defaultLng], {
                icon: L.divIcon({
                    className: 'default-marker',
                    html: '<div style="background-color: #9C27B0; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><span style="color: white; font-size: 12px; font-weight: bold;">C</span></div>',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                })
            }).addTo(directionsMap).bindPopup('<b>Cavite Center</b><br>Default location');
            
            // Add CLINIC marker (green)
            clinicMarker = L.marker([clinicLat, clinicLng], {
                icon: L.divIcon({
                    className: 'clinic-marker',
                    html: '<div style="background-color: #00B761; width: 28px; height: 28px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><i class="fas fa-eye" style="color: white; font-size: 14px;"></i></div>',
                    iconSize: [34, 34],
                    iconAnchor: [17, 17]
                })
            }).addTo(directionsMap).bindPopup('<b><?php echo addslashes($clinic['name']); ?></b><br><?php echo addslashes($clinic['address']); ?>');
            
            // Draw DEFAULT ROUTE
            routeLine = L.polyline([[defaultLat, defaultLng], [clinicLat, clinicLng]], {
                color: '#00B761',
                weight: 4,
                opacity: 0.6,
                dashArray: '8, 10'
            }).addTo(directionsMap);
            
            // Fit map to show both markers
            const bounds = L.latLngBounds([[defaultLat, defaultLng], [clinicLat, clinicLng]]);
            directionsMap.fitBounds(bounds, { padding: [50, 50] });
        }

        // ============================================
        // GET USER LOCATION
        // ============================================
        function getUserLocation() {
            document.getElementById('locationStatus').innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Detecting your location...';
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    // SUCCESS
                    function(position) {
                        userPosition = [position.coords.latitude, position.coords.longitude];
                        
                        // Update location status
                        document.getElementById('locationStatus').innerHTML = `
                            <i class="fas fa-check-circle" style="color: #00B761;"></i>
                            Location detected
                        `;
                        
                        // Remove old user marker if exists
                        if (userMarker) {
                            directionsMap.removeLayer(userMarker);
                        }
                        
                        // Remove default marker
                        if (defaultMarker) {
                            directionsMap.removeLayer(defaultMarker);
                            defaultMarker = null;
                        }
                        
                        // Add USER marker (blue)
                        userMarker = L.marker(userPosition, {
                            icon: L.divIcon({
                                className: 'user-marker',
                                html: '<div style="background-color: #4285F4; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><div style="background-color: white; width: 10px; height: 10px; border-radius: 50%;"></div></div>',
                                iconSize: [30, 30],
                                iconAnchor: [15, 15]
                            })
                        }).addTo(directionsMap).bindPopup('<b>Your Location</b><br>You are here');
                        
                        // Remove old route line
                        if (routeLine) {
                            directionsMap.removeLayer(routeLine);
                        }
                        
                        // Draw NEW route line
                        routeLine = L.polyline([userPosition, [clinicLat, clinicLng]], {
                            color: '#00B761',
                            weight: 5,
                            opacity: 0.8
                        }).addTo(directionsMap);
                        
                        // Calculate distance and travel times
                        calculateDistanceAndTimes(userPosition[0], userPosition[1]);
                        
                        // Fit map to show both markers
                        const bounds = L.latLngBounds([userPosition, [clinicLat, clinicLng]]);
                        directionsMap.fitBounds(bounds, { padding: [50, 50] });
                        
                        userLocationEnabled = true;
                    },
                    // ERROR
                    function(error) {
                        let message = 'Could not get your location';
                        if (error.code === 1) {
                            message = 'Location permission denied. Using default view.';
                        } else if (error.code === 2) {
                            message = 'Location unavailable. Using default view.';
                        } else if (error.code === 3) {
                            message = 'Location request timed out. Using default view.';
                        }
                        
                        document.getElementById('locationStatus').innerHTML = `
                            <i class="fas fa-exclamation-triangle" style="color: #FF4444;"></i>
                            ${message}
                        `;
                        
                        resetToDefault();
                    }
                );
            } else {
                document.getElementById('locationStatus').innerHTML = `
                    <i class="fas fa-exclamation-triangle" style="color: #FF4444;"></i>
                    Geolocation not supported
                `;
                resetToDefault();
            }
        }

        // ============================================
        // CALCULATE DISTANCE AND TRAVEL TIMES
        // ============================================
        function calculateDistanceAndTimes(lat, lng) {
            // Calculate distance using Haversine formula
            const distance = haversineDistance(lat, lng, clinicLat, clinicLng);
            
            // Calculate travel times
            const carTime = Math.round(distance / 30 * 60);        // 30 km/h
            const motorcycleTime = Math.round(distance / 40 * 60); // 40 km/h
            const walkTime = Math.round(distance / 5 * 60);        // 5 km/h
            
            // Format times
            document.getElementById('carTime').innerHTML = formatTime(carTime) + ' <small>mins</small>';
            document.getElementById('motorcycleTime').innerHTML = formatTime(motorcycleTime) + ' <small>mins</small>';
            document.getElementById('walkTime').innerHTML = formatTime(walkTime) + ' <small>mins</small>';
        }

        function haversineDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth's radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }

        function formatTime(minutes) {
            if (minutes < 1) return '&lt;1';
            if (minutes < 60) return minutes;
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            if (mins === 0) {
                return hours + ' hr';
            } else {
                return hours + ' hr ' + mins + ' min';
            }
        }

        // ============================================
        // RESET TO DEFAULT VIEW
        // ============================================
        function resetToDefault() {
            // Remove user marker if exists
            if (userMarker) {
                directionsMap.removeLayer(userMarker);
                userMarker = null;
            }
            
            // Add back default marker if it was removed
            if (!defaultMarker) {
                defaultMarker = L.marker([defaultLat, defaultLng], {
                    icon: L.divIcon({
                        className: 'default-marker',
                        html: '<div style="background-color: #9C27B0; width: 24px; height: 24px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center;"><span style="color: white; font-size: 12px; font-weight: bold;">C</span></div>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).addTo(directionsMap).bindPopup('<b>Cavite Center</b><br>Default location');
            }
            
            // Remove old route line
            if (routeLine) {
                directionsMap.removeLayer(routeLine);
            }
            
            // Draw default route line
            routeLine = L.polyline([[defaultLat, defaultLng], [clinicLat, clinicLng]], {
                color: '#00B761',
                weight: 4,
                opacity: 0.6,
                dashArray: '8, 10'
            }).addTo(directionsMap);
            
            // Clear travel times
            document.getElementById('carTime').innerHTML = '-- <small>mins</small>';
            document.getElementById('motorcycleTime').innerHTML = '-- <small>mins</small>';
            document.getElementById('walkTime').innerHTML = '-- <small>mins</small>';
            
            // Fit map
            const bounds = L.latLngBounds([[defaultLat, defaultLng], [clinicLat, clinicLng]]);
            directionsMap.fitBounds(bounds, { padding: [50, 50] });
            
            userLocationEnabled = false;
        }

        // ============================================
        // UTILITY FUNCTIONS
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

        function toggleTheme() {
            const html = document.documentElement;
            const themeIcon = document.querySelector('#themeToggle i');
            
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
                if (themeIcon) themeIcon.className = 'fas fa-moon';
                showToast('Light mode activated', 'info');
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
                if (themeIcon) themeIcon.className = 'fas fa-sun';
                showToast('Dark mode activated', 'info');
            }
        }

        function toggleNotifications() {
            document.getElementById('notificationMenu').classList.toggle('show');
        }

        function markAllAsRead() {
            fetch('mark-all-notifications-read.php', { method: 'POST' })
            .then(() => {
                const badge = document.querySelector('.notification-badge .badge');
                if (badge) badge.remove();
                showToast('All notifications marked as read', 'success');
            });
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('show');
        }

        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('open');
            document.getElementById('mobileMenuOverlay').classList.toggle('show');
        }

        function closeMobileMenu() {
            document.getElementById('mobileMenu').classList.remove('open');
            document.getElementById('mobileMenuOverlay').classList.remove('show');
        }

        function copyAddress() {
            const address = "<?php echo addslashes($clinic['address'] . ', ' . $clinic['city'] . ', Cavite'); ?>";
            navigator.clipboard.writeText(address).then(() => {
                showToast('Address copied to clipboard!', 'success');
            });
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const profileMenu = document.getElementById('profileMenu');
            const profileTrigger = document.querySelector('.profile-trigger');
            if (profileMenu && profileTrigger && !profileTrigger.contains(event.target) && !profileMenu.contains(event.target)) {
                profileMenu.classList.remove('show');
            }
            
            const notifMenu = document.getElementById('notificationMenu');
            const notifTrigger = document.querySelector('.notification-badge');
            if (notifMenu && notifTrigger && !notifTrigger.contains(event.target) && !notifMenu.contains(event.target)) {
                notifMenu.classList.remove('show');
            }
        });

        // Load saved theme and initialize map
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const themeIcon = document.querySelector('#themeToggle i');
            
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
                if (themeIcon) themeIcon.className = 'fas fa-sun';
            } else {
                document.documentElement.classList.remove('theme-dark');
                if (themeIcon) themeIcon.className = 'fas fa-moon';
            }
            
            // Initialize map
            initMap();
            
            // Try to get user location
            setTimeout(() => {
                getUserLocation();
            }, 1000);
        });
    </script>
</body>
</html>