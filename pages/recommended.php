<?php
include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 12;
$offset = ($page - 1) * $items_per_page;

// Get total count for pagination
$count_query = mysqli_query($conn, "
    SELECT COUNT(*) as total 
    FROM clinics c
    LEFT JOIN appointments a ON c.id = a.clinic_id
    WHERE c.id NOT IN (
        SELECT clinic_id FROM appointments WHERE user_id = $user_id
    )
");
$count_result = mysqli_fetch_assoc($count_query);
$total_items = $count_result['total'];
$total_pages = ceil($total_items / $items_per_page);

// Get recommended clinics with pagination
$recommended_query = mysqli_query($conn, "
    SELECT c.*, 
           COALESCE(AVG(r.rating), 0) as avg_rating,
           COUNT(r.id) as review_count,
           COUNT(DISTINCT a.id) as booking_count,
           COUNT(DISTINCT f.id) as favorite_count
    FROM clinics c
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id
    LEFT JOIN appointments a ON c.id = a.clinic_id
    LEFT JOIN favorites f ON c.id = f.clinic_id
    WHERE c.id NOT IN (
        SELECT clinic_id FROM appointments WHERE user_id = $user_id
    )
    GROUP BY c.id
    ORDER BY booking_count DESC, avg_rating DESC, favorite_count DESC
    LIMIT $items_per_page OFFSET $offset
");

// Get unique cities for filter
$cities = mysqli_query($conn, "SELECT DISTINCT city FROM clinics ORDER BY city");

// Get user stats for sidebar
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$favorites_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM favorites WHERE user_id = $user_id");
$favorites_row = mysqli_fetch_assoc($favorites_query);
$total_favorites = $favorites_row['total'] ?: 0;
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Recommended Clinics - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            
            --bg-primary: #F8F9FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border-color: #E8E8E8;
            --border-light: #F0F0F0;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.08);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.12);
            --shadow-hover: 0 15px 30px rgba(0,183,97,0.15);
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --radius-full: 999px;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            
            --bg-primary: #121212;
            --bg-secondary: #1E1E1E;
            --card-bg: #2D2D2D;
            --text-primary: #FFFFFF;
            --text-secondary: #E0E0E0;
            --text-muted: #A0A0A0;
            --border-color: #404040;
            --border-light: #333333;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        /* Bottom Navigation */
        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            box-shadow: 0 -5px 20px rgba(0,0,0,0.05);
            padding: 12px 20px;
            z-index: 1000;
            border-top: 1px solid var(--border-light);
        }

        @media (max-width: 768px) {
            .bottom-nav { display: block; }
        }

        .bottom-nav .nav-items {
            display: flex;
            justify-content: space-around;
            align-items: center;
        }

        .bottom-nav .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 11px;
            gap: 4px;
        }

        .bottom-nav .nav-item i { font-size: 22px; }
        .bottom-nav .nav-item.active { color: var(--primary); }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--bg-secondary);
            position: fixed;
            height: 100vh;
            box-shadow: var(--shadow-md);
            overflow-y: auto;
            z-index: 100;
            border-right: 1px solid var(--border-light);
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
        }

        .sidebar-header {
            padding: 30px 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .sidebar-logo {
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary);
            margin-bottom: 30px;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info-mini h4 {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .user-info-mini p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .sidebar-nav {
            padding: 20px 15px;
        }

        .sidebar-nav ul { list-style: none; }
        .sidebar-nav .nav-section {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 20px 15px 8px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }

        .sidebar-nav a i {
            width: 24px;
            font-size: 18px;
            color: var(--text-muted);
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: var(--primary-light);
            color: var(--primary);
        }

        .sidebar-nav a.active i {
            color: var(--primary);
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-light);
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            padding: 12px;
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }

        .sidebar-footer a:hover {
            background: #FFEBEE;
            color: var(--danger);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            background: var(--bg-primary);
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding-bottom: 80px;
            }
        }

        /* Top Bar */
        .top-bar {
            background: var(--bg-secondary);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 99;
            border-bottom: 1px solid var(--border-light);
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            font-size: 24px;
            color: var(--primary);
            background: var(--primary-light);
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .page-title h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .user-stats {
            display: flex;
            align-items: center;
            gap: 20px;
            background: var(--bg-primary);
            padding: 8px 20px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-color);
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }

        .stat-item i { font-size: 16px; }
        .stat-value { font-weight: 700; }
        .stat-label { color: var(--text-muted); font-size: 12px; }

        @media (max-width: 768px) {
            .user-stats { display: none; }
        }

        /* Container */
        .container {
            padding: 30px;
        }

        /* Header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 15px;
        }

        /* Filter Section */
        .filter-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border-light);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .filter-header h3 {
            font-size: 16px;
            font-weight: 600;
        }

        .clear-btn {
            color: var(--primary);
            background: none;
            border: none;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 20px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filter-tab:hover,
        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Results Info */
        .results-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .results-count {
            background: var(--bg-secondary);
            padding: 8px 16px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            font-size: 14px;
        }

        .sort-dropdown {
            padding: 8px 20px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
            outline: none;
        }

        /* Clinics Grid */
        .clinics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        @media (max-width: 1200px) {
            .clinics-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .clinics-grid { grid-template-columns: 1fr; }
        }

        .clinic-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 1px solid var(--border-light);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .clinic-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .clinic-image {
            height: 140px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            position: relative;
        }

        .clinic-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--secondary);
            color: white;
            padding: 5px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
        }

        .clinic-badge.popular { background: #FF4444; }
        .clinic-badge.trending { background: #FF8C42; }
        .clinic-badge.new { background: var(--primary); }

        .clinic-info {
            padding: 20px;
        }

        .clinic-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .clinic-name {
            font-size: 18px;
            font-weight: 600;
        }

        .clinic-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            background: #FFC107;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            color: #333;
            font-size: 13px;
            font-weight: 600;
        }

        .clinic-details {
            margin: 15px 0;
        }

        .clinic-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .clinic-detail i {
            width: 20px;
            color: var(--primary);
        }

        .clinic-stats {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            padding: 10px 0;
            border-top: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
        }

        .clinic-stat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .clinic-stat i { color: var(--primary); }

        .clinic-footer {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .view-btn {
            flex: 1;
            padding: 12px;
            background: var(--primary-gradient);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .view-btn:hover {
            transform: scale(1.05);
        }

        .favorite-btn {
            width: 45px;
            height: 45px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: #FF4444;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 18px;
        }

        .favorite-btn:hover {
            background: #FF4444;
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            text-decoration: none;
            border: 1px solid var(--border-light);
            min-width: 45px;
            text-align: center;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .pagination .active {
            background: var(--primary);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 70px;
            color: var(--text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 30px;
            background: var(--primary-gradient);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-full);
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,183,97,0.3);
        }
    </style>
</head>
<body>
    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <div class="nav-items">
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="nearby.php" class="nav-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>Nearby</span>
            </a>
            <a href="my-appointments.php" class="nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>
            <a href="favorites.php" class="nav-item">
                <i class="fas fa-heart"></i>
                <span>Favorites</span>
            </a>
            <a href="profile.php" class="nav-item">
                <i class="fas fa-user"></i>
                <span>Profile</span>
            </a>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-eye"></i>
                <span>eyecore</span>
            </div>
            <div class="user-profile-mini">
                <div class="user-avatar">
                    <?php 
                    $avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
                    $user_data = mysqli_fetch_assoc($avatar_query);
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
                <div class="user-info-mini">
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <p>⚡ <?php echo $total_points; ?> points • <?php echo $total_bookings; ?> bookings</p>
                </div>
            </div>
        </div>

        <div class="sidebar-nav">
            <ul>
                <li class="nav-section">MAIN</li>
                <li><a href="dashboard.php"><i class="fas fa-home"></i><span>Home</span></a></li>
                <li><a href="my-appointments.php"><i class="fas fa-calendar-check"></i><span>My Bookings</span></a></li>
                <li><a href="favorites.php"><i class="fas fa-heart"></i><span>Favorites</span></a></li>
                
                <li class="nav-section">DISCOVER</li>
                <li><a href="nearby.php"><i class="fas fa-location-dot"></i><span>Nearby Clinics</span></a></li>
                <li><a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i><span>Explore Map</span></a></li>
                
                <li class="nav-section">DEALS</li>
                <li><a href="sale-products.php"><i class="fas fa-tags"></i><span>Hot Sales</span></a></li>
                
                <li class="nav-section">ACCOUNT</li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
                <li><a href="user_settings.php"><i class="fas fa-cog"></i><span>user_settings</span></a></li>
            </ul>
        </div>

        <div class="sidebar-footer">
            <a href="../auth/user_logout.php">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <i class="fas fa-thumbs-up"></i>
                <h1>Recommended for You</h1>
            </div>
            <div class="user-stats">
                <div class="stat-item">
                    <i class="fas fa-star" style="color: #FFC107;"></i>
                    <span class="stat-value"><?php echo $total_points; ?></span>
                    <span class="stat-label">Points</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                    <span class="stat-value"><?php echo $total_bookings; ?></span>
                    <span class="stat-label">Bookings</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-heart" style="color: #FF4444;"></i>
                    <span class="stat-value"><?php echo $total_favorites; ?></span>
                    <span class="stat-label">Favorites</span>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <h2><i class="fas fa-magic" style="color: var(--primary);"></i> Personalized for You</h2>
                <p>Based on your booking history and popular clinics in Cavite</p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-header">
                    <h3>Filter by City</h3>
                    <button class="clear-btn" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterByCity('all', this)">All Cities</button>
                    <?php while($city = mysqli_fetch_assoc($cities)): ?>
                        <button class="filter-tab" onclick="filterByCity('<?php echo $city['city']; ?>', this)">
                            <?php echo $city['city']; ?>
                        </button>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Results Info -->
            <div class="results-info">
                <span class="results-count">
                    <i class="fas fa-eye"></i> Showing <span id="visibleCount"><?php echo $total_items; ?></span> clinics
                </span>
                <select class="sort-dropdown" onchange="sortClinics(this.value)">
                    <option value="recommended">Sort by: Recommended</option>
                    <option value="rating">Sort by: Highest Rated</option>
                    <option value="bookings">Sort by: Most Booked</option>
                    <option value="name">Sort by: Name A-Z</option>
                </select>
            </div>

            <!-- Clinics Grid -->
            <div class="clinics-grid" id="clinicsGrid">
                <?php if (mysqli_num_rows($recommended_query) > 0): ?>
                    <?php while($clinic = mysqli_fetch_assoc($recommended_query)): 
                        $badge_type = 'trending';
                        $badge_text = 'Recommended';
                        
                        if ($clinic['booking_count'] > 10) {
                            $badge_type = 'popular';
                            $badge_text = 'Popular 🔥';
                        } elseif ($clinic['avg_rating'] >= 4.5) {
                            $badge_type = 'trending';
                            $badge_text = 'Top Rated ⭐';
                        }
                    ?>
                    <div class="clinic-card" data-city="<?php echo $clinic['city']; ?>" data-rating="<?php echo $clinic['avg_rating']; ?>" data-bookings="<?php echo $clinic['booking_count']; ?>" data-name="<?php echo $clinic['name']; ?>">
                        <div class="clinic-image">
                            <i class="fas fa-eye"></i>
                            <div class="clinic-badge <?php echo $badge_type; ?>">
                                <?php echo $badge_text; ?>
                            </div>
                        </div>
                        <div class="clinic-info">
                            <div class="clinic-header">
                                <h3 class="clinic-name"><?php echo htmlspecialchars($clinic['name']); ?></h3>
                                <div class="clinic-rating">
                                    <i class="fas fa-star"></i> <?php echo round($clinic['avg_rating'], 1); ?>
                                </div>
                            </div>
                            
                            <div class="clinic-details">
                                <div class="clinic-detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($clinic['city']); ?></span>
                                </div>
                                <div class="clinic-detail">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo htmlspecialchars($clinic['hours']); ?></span>
                                </div>
                            </div>

                            <div class="clinic-stats">
                                <div class="clinic-stat">
                                    <i class="fas fa-calendar-check"></i>
                                    <span><?php echo $clinic['booking_count']; ?> bookings</span>
                                </div>
                                <div class="clinic-stat">
                                    <i class="fas fa-star" style="color: #FFC107;"></i>
                                    <span><?php echo $clinic['review_count']; ?> reviews</span>
                                </div>
                            </div>

                            <div class="clinic-footer">
                                <a href="clinic-details.php?id=<?php echo $clinic['id']; ?>" class="view-btn">
                                    View Details <i class="fas fa-arrow-right"></i>
                                </a>
                                <a href="#" class="favorite-btn" onclick="toggleFavorite(<?php echo $clinic['id']; ?>, this)">
                                    <i class="fas fa-heart"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-smile-wink"></i>
                        <h3>No recommendations yet</h3>
                        <p>Start booking clinics to get personalized recommendations!</p>
                        <a href="clinics.php" class="btn-primary">
                            <i class="fas fa-search"></i> Browse All Clinics
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Filter by city
        function filterByCity(city, element) {
            document.querySelectorAll('.filter-tab').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');
            
            const cards = document.querySelectorAll('.clinic-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                if (city === 'all' || card.dataset.city === city) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            document.getElementById('visibleCount').textContent = visibleCount;
        }

        // Clear filters
        function clearFilters() {
            document.querySelectorAll('.filter-tab').forEach(btn => {
                btn.classList.remove('active');
                if (btn.textContent.trim() === 'All Cities') {
                    btn.classList.add('active');
                }
            });
            
            const cards = document.querySelectorAll('.clinic-card');
            cards.forEach(card => card.style.display = 'block');
            document.getElementById('visibleCount').textContent = cards.length;
        }

        // Sort clinics
        function sortClinics(sortBy) {
            const grid = document.getElementById('clinicsGrid');
            const cards = Array.from(document.querySelectorAll('.clinic-card'));
            
            cards.sort((a, b) => {
                switch(sortBy) {
                    case 'rating':
                        return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
                    case 'bookings':
                        return parseInt(b.dataset.bookings) - parseInt(a.dataset.bookings);
                    case 'name':
                        return a.dataset.name.localeCompare(b.dataset.name);
                    default:
                        return 0;
                }
            });
            
            grid.innerHTML = '';
            cards.forEach(card => grid.appendChild(card));
        }

        // Toggle favorite
        function toggleFavorite(clinicId, element) {
            fetch('toggle-favorite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'clinic_id=' + clinicId
            })
            .then(response => response.json())
            .then(data => {
                if (data.favorited) {
                    element.style.background = '#FF4444';
                    element.style.color = 'white';
                } else {
                    element.style.background = 'var(--bg-primary)';
                    element.style.color = '#FF4444';
                }
            });
        }
    </script>
</body>
</html>