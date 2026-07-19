<?php

include '../includes/config.php';
include '../includes/theme.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$ref_no = isset($_GET['ref_no']) ? mysqli_real_escape_string($conn, $_GET['ref_no']) : '';

if (!$appointment_id) {
    header('Location: my-appointments.php');
    exit();
}

// Update payment status to cancelled/failed
mysqli_query($conn, "UPDATE appointments SET payment_status = 'unpaid' WHERE id = $appointment_id AND user_id = $user_id");

// Update payments table if may record
mysqli_query($conn, "UPDATE payments SET payment_status = 'failed' WHERE appointment_id = $appointment_id AND reference_number = '$ref_no'");

// Get appointment details
$query = mysqli_query($conn, "
    SELECT a.*, 
           c.name as clinic_name,
           c.id as clinic_id,
           CASE 
               WHEN a.item_type = 'product' THEN (SELECT name FROM products WHERE id = a.item_id)
               WHEN a.item_type = 'service' THEN (SELECT name FROM services WHERE id = a.item_id)
           END as product_name,
           CASE 
               WHEN a.item_type = 'product' THEN (SELECT price FROM products WHERE id = a.item_id)
               WHEN a.item_type = 'service' THEN (SELECT price FROM services WHERE id = a.item_id)
           END as amount
    FROM appointments a
    JOIN clinics c ON a.clinic_id = c.id
    WHERE a.id = $appointment_id AND a.user_id = $user_id
");

$appointment = mysqli_fetch_assoc($query);

// Get user stats for sidebar
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$pending_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_count)['total'];

$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Payment Cancelled - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --balance: #FF8C42;
            
            /* Payment method colors */
            --gcash: #0057e0;
            --gcash-light: #e6f0ff;
            --paymaya: #ff4d4d;
            --paymaya-light: #ffe6e6;
            --grab: #00b14f;
            --grab-light: #e0ffe8;
            --card: #6f42c1;
            --card-light: #f0e6ff;
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
            
            --gcash: #1a73e8;
            --gcash-light: #1e3a5a;
            --paymaya: #ff6666;
            --paymaya-light: #5a2d2d;
            --grab: #00cc66;
            --grab-light: #1e4a2d;
            --card: #8b5cf6;
            --card-light: #3a2d5a;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
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
            width: 100%;
            margin: 0;
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
            position: relative;
            background: none;
            border: none;
            cursor: pointer;
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

        .nav-link .badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: var(--danger);
            color: white;
            font-size: 9px;
            padding: 2px 5px;
            border-radius: var(--radius-full);
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Discover Dropdown */
        .nav-dropdown {
            position: relative;
        }

        .dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .dropdown-trigger i {
            font-size: 12px;
            transition: transform 0.2s;
        }

        .dropdown-trigger.active i {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 220px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 8px;
            margin-top: 12px;
            display: none;
            z-index: 100;
            border: 1px solid var(--border-light);
        }

        .dropdown-menu.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            position: relative;
            font-size: 14px;
        }

        .dropdown-menu a:hover {
            background: var(--bg-primary);
            color: var(--primary);
        }

        .dropdown-menu a i {
            width: 20px;
            font-size: 16px;
        }

        .dropdown-badge {
            position: absolute;
            right: 16px;
            background: var(--danger);
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Search Bar */
        .search-container {
            position: relative;
            width: 280px;
        }

        .search-container i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        .search-container input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 40px;
            font-size: 14px;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .search-container input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
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
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 6px;
            border-radius: var(--radius-full);
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
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

        /* ===== MOBILE TOP (STICKY) ===== */
        .mobile-top {
            display: none;
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--bg-secondary);
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
            width: 100%;
            margin: 0;
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

        /* ===== MOBILE NOTIFICATION DROPDOWN ===== */
        .mobile-notification-dropdown {
            position: relative;
        }

        .mobile-notification-menu {
            position: fixed;
            top: 70px;
            left: 10px;
            right: 10px;
            width: auto;
            max-width: none;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 2000;
            border: 1px solid var(--border-light);
            overflow: hidden;
            max-height: 80vh;
            overflow-y: auto;
        }

        .mobile-notification-menu.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @media (min-width: 769px) {
            .mobile-notification-menu {
                display: none !important;
            }
        }

        .mobile-notification-menu .notification-header {
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .mobile-notification-menu .notification-header h3 {
            color: white;
            font-size: 16px;
        }

        .mobile-notification-menu .notification-header button {
            color: white;
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .mobile-notification-menu .notification-item {
            padding: 12px;
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
            position: relative;
            padding: 8px 0;
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
            top: 0;
            right: -2px;
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

        /* ===== FLOATING ACTION BUTTON ===== */
        .fab {
            position: fixed;
            bottom: 100px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 98;
            border: none;
        }

        .fab:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 15px 30px rgba(0,183,97,0.4);
        }

        .fab.active {
            transform: rotate(45deg);
            background: var(--danger);
        }

        .fab-menu {
            position: fixed;
            bottom: 180px;
            right: 20px;
            display: none;
            flex-direction: column;
            gap: 10px;
            z-index: 97;
        }

        .fab-menu.show {
            display: flex;
            animation: slideIn 0.2s ease;
        }

        .fab-menu-item {
            width: 50px;
            height: 50px;
            background: var(--bg-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            text-decoration: none;
            box-shadow: var(--shadow-md);
            transition: all 0.2s;
            font-size: 20px;
            border: 1px solid var(--border-light);
        }

        .fab-menu-item:hover {
            transform: scale(1.1);
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        @media (max-width: 768px) {
            .fab {
                bottom: 90px;
            }
        }

        /* ===== TOOLTIPS ===== */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:hover::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: transparent transparent var(--bg-secondary) transparent;
            z-index: 1001;
        }

        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            white-space: nowrap;
            box-shadow: var(--shadow-md);
            z-index: 1000;
            margin-bottom: 8px;
            border: 1px solid var(--border-light);
            font-weight: 500;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            margin-left: 0;
            background: var(--bg-primary);
            min-height: 100vh;
        }

        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) {
            .content-wrapper {
                padding: 30px 40px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px 16px 100px;
            }
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
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

        .back-link {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            font-size: 14px;
        }

        .back-link a:hover {
            background: var(--primary);
            color: white;
            transform: translateX(-5px);
        }

        /* ===== PAYMENT GRID ===== */
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 768px) {
            .payment-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== ORDER SUMMARY CARD ===== */
        .order-summary {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .order-summary h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .order-summary h2 i {
            color: var(--primary);
        }

        .clinic-info {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .clinic-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .clinic-details h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .clinic-details p {
            color: var(--text-secondary);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .clinic-details p i {
            color: var(--primary);
            font-size: 12px;
        }

        .product-details {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .product-image {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            color: var(--primary);
            font-size: 20px;
        }

        .product-info-details {
            flex: 1;
        }

        .product-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .product-category {
            font-size: 12px;
            color: var(--primary);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .doctor-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border-color);
        }

        .doctor-info i {
            color: var(--primary);
            font-size: 14px;
        }

        .doctor-info span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .doctor-info strong {
            color: var(--text-primary);
        }

        .order-items {
            margin-bottom: 15px;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
        }

        .order-item:last-child {
            border-bottom: none;
        }

        .order-total {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            margin-top: 10px;
            border-top: 2px solid var(--border-color);
            font-size: 20px;
            font-weight: 700;
        }

        .total-label {
            color: var(--text-primary);
        }

        .total-amount {
            color: var(--success);
        }

        .appointment-dates {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-top: 20px;
        }

        .date-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .date-row:last-child {
            margin-bottom: 0;
        }

        .date-row i {
            color: var(--primary);
            width: 20px;
        }

        .date-row strong {
            color: var(--text-primary);
            margin-left: auto;
        }

        /* ===== DOWNPAYMENT SPECIFIC STYLES ===== */
        .payment-breakdown {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--bg-primary) 100%);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 20px 0;
            border: 2px solid var(--primary);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-label {
            font-weight: 600;
            color: var(--text-primary);
        }

        .breakdown-value {
            font-weight: 700;
        }

        .breakdown-value.total {
            color: var(--primary);
            font-size: 18px;
        }

        .breakdown-value.downpayment {
            color: var(--success);
            font-size: 18px;
        }

        .breakdown-value.balance {
            color: var(--balance);
        }

        .downpayment-badge {
            display: inline-block;
            background: var(--success);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .balance-badge {
            display: inline-block;
            background: var(--balance);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .info-box {
            background: var(--bg-primary);
            border-left: 4px solid var(--info);
            padding: 15px;
            border-radius: var(--radius-md);
            margin: 20px 0;
        }

        .info-box i {
            color: var(--info);
            margin-right: 10px;
        }

        /* ===== PAYMENT METHODS CARD ===== */
        .payment-methods-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .payment-methods-card h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-methods-card h2 i {
            color: var(--primary);
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .payment-option {
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .payment-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .payment-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .payment-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .payment-option i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        .payment-option.gcash i { color: var(--gcash); }
        .payment-option.paymaya i { color: var(--paymaya); }
        .payment-option.grab i { color: var(--grab); }
        .payment-option.card i { color: var(--card); }

        .payment-option span {
            font-weight: 600;
            font-size: 14px;
        }

        .payment-option small {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 5px;
        }

        .payment-form {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid var(--border-light);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            transition: all 0.2s;
            background: var(--input-bg, var(--bg-primary));
            color: var(--text-primary);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .form-control[readonly] {
            background: var(--disabled-bg, var(--bg-primary));
            opacity: 0.7;
            cursor: not-allowed;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn-pay {
            width: 100%;
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-pay:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-pay.gcash { background: var(--gcash); }
        .btn-pay.paymaya { background: var(--paymaya); }
        .btn-pay.grab { background: var(--grab); }
        .btn-pay.card { background: var(--card); }

        .secure-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-top: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .secure-badge i {
            color: var(--success);
            font-size: 20px;
        }

        .test-creds {
            margin-top: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            border-left: 4px solid var(--warning);
            font-size: 13px;
        }

        .test-creds p {
            margin-bottom: 8px;
            color: var(--warning);
            font-weight: 600;
        }

        .test-creds ul {
            color: var(--text-secondary);
            padding-left: 20px;
        }

        .test-creds li {
            margin-bottom: 4px;
        }

        /* ===== ALERT MESSAGES ===== */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: var(--cancelled-bg, #f8d7da);
            color: var(--cancelled-text, #721c24);
            border: 1px solid var(--cancelled-text, #f5c6cb);
        }

        .theme-dark .alert-error {
            background: #4d2d2d;
            color: #ff9999;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .theme-dark .alert-info {
            background: #1e4a5a;
            color: #7ac9e0;
        }

        .alert-success {
            background: var(--confirmed-bg, #d4edda);
            color: var(--confirmed-text, #155724);
            border: 1px solid var(--confirmed-text, #c3e6cb);
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ===== NOTIFICATION DROPDOWN (DESKTOP) ===== */
        .notification-dropdown {
            position: relative;
        }

        .notification-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 380px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 1000;
            margin-top: 12px;
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .notification-menu.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .notification-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h3 {
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }

        .notification-header button {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            padding: 16px 20px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-light);
            transition: all 0.2s;
            position: relative;
        }

        .notification-item:hover {
            background: var(--bg-primary);
        }

        .notification-item.unread {
            background: var(--primary-light);
        }

        .notification-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .notification-message {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .notification-dot {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
        }

        .notification-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .notification-empty i {
            font-size: 50px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .notification-footer {
            padding: 16px;
            text-align: center;
            border-top: 1px solid var(--border-light);
        }

        .notification-footer a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ===== CANCEL PAGE STYLES ===== */
.cancel-container {
    max-width: 600px;
    margin: 40px auto;
    text-align: center;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    padding: 40px 30px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border-light);
}

.cancel-icon {
    font-size: 80px;
    color: var(--danger);
    margin-bottom: 20px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.cancel-title {
    font-size: 32px;
    font-weight: 700;
    color: var(--danger);
    margin-bottom: 15px;
}

.cancel-message {
    font-size: 16px;
    color: var(--text-secondary);
    margin-bottom: 30px;
    line-height: 1.6;
}

.payment-details-card {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 25px;
    margin-bottom: 30px;
    border: 1px solid var(--border-light);
    text-align: left;
}

.payment-details-card .detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-light);
}

.payment-details-card .detail-row:last-child {
    border-bottom: none;
}

.payment-details-card .detail-label {
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
}

.payment-details-card .detail-value {
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 600;
}

.payment-details-card .reference-number {
    font-family: monospace;
    background: var(--bg-secondary);
    padding: 5px 10px;
    border-radius: var(--radius-sm);
    color: var(--primary);
}

.payment-details-card .amount-highlight {
    color: var(--danger);
    font-size: 20px;
    font-weight: 700;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.action-buttons .btn {
    padding: 14px 25px;
    border-radius: var(--radius-md);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 160px;
}

.action-buttons .btn-primary {
    background: var(--primary-gradient);
    color: white;
    border: none;
}

.action-buttons .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.action-buttons .btn-outline {
    background: transparent;
    color: var(--primary);
    border: 2px solid var(--primary);
}

.action-buttons .btn-outline:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
}

.action-buttons .btn-secondary {
    background: var(--bg-primary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.action-buttons .btn-secondary:hover {
    background: var(--text-secondary);
    color: white;
    transform: translateY(-2px);
}

.help-box {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 25px;
    text-align: left;
    border-left: 4px solid var(--info);
}

.help-box h4 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.help-box h4 i {
    color: var(--info);
}

.help-box p {
    color: var(--text-secondary);
    margin-bottom: 10px;
    font-size: 14px;
    line-height: 1.6;
}

.help-box ul {
    padding-left: 20px;
    margin-bottom: 15px;
}

.help-box li {
    color: var(--text-secondary);
    font-size: 14px;
    margin-bottom: 8px;
    line-height: 1.5;
}

.help-box i {
    width: 20px;
    color: var(--primary);
    margin-right: 8px;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .cancel-container {
        margin: 20px 16px;
        padding: 30px 20px;
    }
    
    .cancel-icon {
        font-size: 60px;
    }
    
    .cancel-title {
        font-size: 28px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .action-buttons .btn {
        width: 100%;
        min-width: auto;
    }
    
    .payment-details-card .detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
}
    </style>
</head>
<body>
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
                <a href="my-appointments.php" class="nav-link active">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                    <?php if ($pending > 0): ?>
                        <span class="badge"><?php echo $pending; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Discover Dropdown -->
                <div class="nav-dropdown">
                    <button class="nav-link dropdown-trigger" onclick="toggleDiscoverDropdown()">
                        Discover <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="discoverDropdown">
                        <a href="sale-products.php">
                            <i class="fas fa-tags" style="color: var(--danger);"></i> Hot Sales
                            <?php if ($sale_count > 0): ?>
                                <span class="dropdown-badge"><?php echo $sale_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="clinics-map.php">
                            <i class="fas fa-map-marked-alt" style="color: var(--primary);"></i> Explore Map
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="nav-right">
            <!-- User Stats Badge -->
            <div class="user-stats-badge">
                <div class="stat-badge" data-tooltip="Total points earned">
                    <i class="fas fa-star" style="color: #FFC107;"></i>
                    <span class="value"><?php echo $total_points; ?></span>
                </div>
                <div class="stat-badge" data-tooltip="Total bookings made">
                    <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                    <span class="value"><?php echo $total_bookings; ?></span>
                </div>
            </div>
            
            <!-- Theme Toggle -->
            <button class="icon-btn" onclick="toggleTheme()" id="themeToggle" data-tooltip="Toggle dark/light mode">
                <i class="fas fa-moon"></i>
            </button>
            
            <!-- Notifications -->
            <div class="notification-dropdown">
                <button class="icon-btn" onclick="toggleNotifications()" id="notificationBell" data-tooltip="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge" id="notificationBadge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notification-menu" id="notificationMenu">
                    <div class="notification-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <?php if ($unread_count > 0): ?>
                            <button onclick="markAllAsRead()">Mark all read</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-list">
                        <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                            <?php while($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                                <a href="<?php echo $notif['link'] ?: '#'; ?>" class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo $notif['title']; ?></div>
                                        <div class="notification-message"><?php echo $notif['message']; ?></div>
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
                </div>
            </div>
            
            <!-- Profile Dropdown -->
            <div class="profile-dropdown">
                <div class="profile-trigger" onclick="toggleProfileMenu()">
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
                        <div class="profile-points"><?php echo $total_points; ?> pts</div>
                    </div>
                    <div class="profile-avatar">
                        <?php if (!empty($user_data['avatar']) && file_exists("../assets/images/profiles/" . $user_data['avatar'])): ?>
                            <img src="../assets/images/profiles/<?php echo $user_data['avatar']; ?>" alt="Profile">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-menu" id="profileMenu">
                    <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
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
            <button class="icon-btn" onclick="toggleMobileNotifications()" style="width: 40px; height: 40px;">
                <i class="fas fa-bell"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAV -->
    <div class="mobile-bottom-nav">
        <div class="mobile-nav-items">
            <a href="dashboard.php" class="mobile-nav-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="nearby.php" class="mobile-nav-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>Nearby</span>
            </a>
            <a href="favorites.php" class="mobile-nav-item">
                <i class="fas fa-heart"></i>
                <span>Fav</span>
            </a>
            <a href="my-appointments.php" class="mobile-nav-item active">
                <i class="fas fa-calendar-check"></i>
                <span>Books</span>
                <?php if ($pending > 0): ?>
                    <span class="badge"><?php echo $pending; ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="mobile-nav-item" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </a>
        </div>
    </div>

    <!-- MOBILE MENU -->
    <div class="mobile-menu-overlay" id="mobileMenuOverlay" onclick="closeMobileMenu()"></div>
    <div class="mobile-menu" id="mobileMenu">
        <div class="mobile-menu-header">
            <div class="mobile-user">
                <div class="mobile-avatar">
                    <?php 
                    $first_letter = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));
                    ?>
                    <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: white;">
                        <?php echo $first_letter; ?>
                    </div>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></h4>
                    <p><?php echo $total_points; ?> points • <?php echo $total_bookings; ?> bookings</p>
                </div>
            </div>
            <button onclick="closeMobileMenu()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="mobile-menu-items">
            <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
            <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="sale-products.php"><i class="fas fa-tags"></i> Hot Sales</a>
            <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
            <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="content-wrapper">
            <!-- Back Button -->
            <div class="back-link" style="margin-bottom: 20px;">
                <a href="my-appointments.php" style="color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-arrow-left"></i> Back to My Appointments
                </a>
            </div>

            <div class="cancel-container">
                <div class="cancel-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                
                <h1 class="cancel-title">Payment Cancelled</h1>
                <p class="cancel-message">
                    Your payment was not completed. Don't worry, your appointment is still reserved.
                </p>

                <div class="payment-details-card">
                    <div class="detail-row">
                        <span class="detail-label">Reference Number</span>
                        <span class="detail-value reference-number"><?php echo $ref_no ?: 'EYE-' . str_pad($appointment_id, 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Amount</span>
                        <span class="detail-value amount-highlight">₱<?php echo number_format($appointment['amount'] ?? 0, 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Clinic</span>
                        <span class="detail-value"><?php echo $appointment['clinic_name'] ?? 'N/A'; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Service</span>
                        <span class="detail-value"><?php echo $appointment['product_name'] ?? 'N/A'; ?></span>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="payment.php?appointment_id=<?php echo $appointment_id; ?>" class="btn btn-primary">
                        <i class="fas fa-credit-card"></i> Try Again
                    </a>
                    <a href="my-appointments.php" class="btn btn-outline">
                        <i class="fas fa-calendar-check"></i> View Appointments
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>

                <div class="help-box">
                    <h4><i class="fas fa-question-circle"></i> Need Help?</h4>
                    <p>If you're having trouble with your payment, here are some tips:</p>
                    <ul>
                        <li>Make sure you have sufficient balance in your chosen payment method</li>
                        <li>Check if your internet connection is stable</li>
                        <li>Try using a different payment method</li>
                        <li>If the problem persists, contact our support team</li>
                    </ul>
                    <p style="margin-top: 15px;">
                        <i class="fas fa-envelope"></i> support@eyecore.com<br>
                        <i class="fas fa-phone"></i> (02) 1234 5678
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- FLOATING ACTION BUTTON -->
    <div class="fab" onclick="toggleFabMenu()" id="fab">
        <i class="fas fa-plus"></i>
    </div>
    <div class="fab-menu" id="fabMenu">
        <a href="book-appointment.php" class="fab-menu-item" data-tooltip="Book New Appointment">
            <i class="fas fa-calendar-plus"></i>
        </a>
        <a href="dashboard.php" class="fab-menu-item" data-tooltip="Browse Clinics">
            <i class="fas fa-search"></i>
        </a>
        <a href="nearby.php" class="fab-menu-item" data-tooltip="Nearby Clinics">
            <i class="fas fa-location-dot"></i>
        </a>
    </div>

    <script>
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.querySelector('#themeToggle i');
            
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
                icon.className = 'fas fa-moon';
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
                icon.className = 'fas fa-sun';
            }
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const icon = document.querySelector('#themeToggle i');
            
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
                if (icon) icon.className = 'fas fa-sun';
            }
        });

        // Notification functions
        function toggleNotifications() {
            document.getElementById('notificationMenu').classList.toggle('show');
        }

        function toggleMobileNotifications() {
            // Implement mobile notifications if needed
        }

        function markAllAsRead() {
            fetch('../includes/mark-all-notifications-read.php', { method: 'POST' })
                .then(() => location.reload());
        }

        // Profile menu
        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('show');
        }

        // Discover dropdown
        function toggleDiscoverDropdown() {
            document.getElementById('discoverDropdown').classList.toggle('show');
            document.querySelector('.dropdown-trigger').classList.toggle('active');
        }

        // Mobile menu
        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('open');
            document.getElementById('mobileMenuOverlay').classList.toggle('show');
        }

        function closeMobileMenu() {
            document.getElementById('mobileMenu').classList.remove('open');
            document.getElementById('mobileMenuOverlay').classList.remove('show');
        }

        // FAB menu
        function toggleFabMenu() {
            document.getElementById('fabMenu').classList.toggle('show');
            document.getElementById('fab').classList.toggle('active');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.profile-dropdown')) {
                document.getElementById('profileMenu')?.classList.remove('show');
            }
            if (!e.target.closest('.nav-dropdown')) {
                document.getElementById('discoverDropdown')?.classList.remove('show');
                document.querySelector('.dropdown-trigger')?.classList.remove('active');
            }
            if (!e.target.closest('.notification-dropdown')) {
                document.getElementById('notificationMenu')?.classList.remove('show');
            }
            if (!e.target.closest('.fab') && !e.target.closest('.fab-menu')) {
                document.getElementById('fabMenu')?.classList.remove('show');
                document.getElementById('fab')?.classList.remove('active');
            }
        });
    </script>
</body>
</html>