<?php

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$apc_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($apc_q)['total'] ?? 0;

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$reservation_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')");
$reservation_row = mysqli_fetch_assoc($reservation_query);
$reservation_count = $reservation_row['total'] ?? 0;

// Fetch user's favorited product IDs
$fav_result = mysqli_query($conn, "SELECT product_id FROM favorites WHERE user_id = $user_id AND product_id IS NOT NULL");
$user_favorited_products = [];
while ($frow = mysqli_fetch_assoc($fav_result)) {
    $user_favorited_products[] = (int)$frow['product_id'];
}

$category = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : '';

$category_map = [
    'eyeglasses'       => 'Eyeglasses',
    'sunglasses'       => 'Sunglasses',
    'computer glasses' => 'Computer Glasses',
    'computer_glasses' => 'Computer Glasses',
    'contact lens'     => 'Contact Lens',
    'contact_lens'     => 'Contact Lens',
    'reading'          => 'Reading',
    'kids'             => 'Kids',
    'accessories'      => 'Accessories',
    'lenses'           => 'Lenses',
    'frames'           => 'Frames',
    'eyewear'          => 'Eyewear',
    'package'          => 'Package',
    'service'          => 'Service',
    'eye exam'         => 'Eye Exam',
    'eye_exam'         => 'Eye Exam',
];

$category_icons = [
    'eyeglasses'       => ['icon' => 'fas fa-glasses',     'color' => '#1a3a6e', 'bg' => 'linear-gradient(135deg,#1a3a6e,#0d1a2e)'],
    'sunglasses'       => ['icon' => 'fas fa-sun',          'color' => '#ff9800', 'bg' => 'linear-gradient(135deg,#ff9800,#e65100)'],
    'computer glasses' => ['icon' => 'fas fa-desktop',      'color' => '#4fc3f7', 'bg' => 'linear-gradient(135deg,#0284c7,#0e4a6e)'],
    'computer_glasses' => ['icon' => 'fas fa-desktop',      'color' => '#4fc3f7', 'bg' => 'linear-gradient(135deg,#0284c7,#0e4a6e)'],
    'contact lens'     => ['icon' => 'fas fa-circle',       'color' => '#ce93d8', 'bg' => 'linear-gradient(135deg,#7e22ce,#4c1d95)'],
    'contact_lens'     => ['icon' => 'fas fa-circle',       'color' => '#ce93d8', 'bg' => 'linear-gradient(135deg,#7e22ce,#4c1d95)'],
    'reading'          => ['icon' => 'fas fa-book-open',    'color' => '#a5d6a7', 'bg' => 'linear-gradient(135deg,#16a34a,#14532d)'],
    'kids'             => ['icon' => 'fas fa-child',         'color' => '#ffe082', 'bg' => 'linear-gradient(135deg,#eab308,#854d0e)'],
    'accessories'      => ['icon' => 'fas fa-tag',           'color' => '#f9a8d4', 'bg' => 'linear-gradient(135deg,#9d174d,#4c1d95)'],
    'lenses'           => ['icon' => 'fas fa-eye',           'color' => '#67e8f9', 'bg' => 'linear-gradient(135deg,#0e7490,#164e63)'],
    'frames'           => ['icon' => 'fas fa-glasses',       'color' => '#fdba74', 'bg' => 'linear-gradient(135deg,#c2410c,#7c2d12)'],
    'eyewear'          => ['icon' => 'fas fa-glasses',       'color' => '#c4b5fd', 'bg' => 'linear-gradient(135deg,#5b21b6,#2e1065)'],
    'package'          => ['icon' => 'fas fa-box-open',      'color' => '#6ee7b7', 'bg' => 'linear-gradient(135deg,#059669,#064e3b)'],
    'service'          => ['icon' => 'fas fa-stethoscope',   'color' => '#93c5fd', 'bg' => 'linear-gradient(135deg,#1d4ed8,#1e3a8a)'],
    'eye exam'         => ['icon' => 'fas fa-eye',           'color' => '#f9a8d4', 'bg' => 'linear-gradient(135deg,#be185d,#831843)'],
    'eye_exam'         => ['icon' => 'fas fa-eye',           'color' => '#f9a8d4', 'bg' => 'linear-gradient(135deg,#be185d,#831843)'],
];

$display_category = $category_map[$category] ?? ucfirst(str_replace('_', ' ', $category));
$category_info    = $category_icons[$category] ?? ['icon' => 'fas fa-tag', 'color' => '#00B761', 'bg' => 'linear-gradient(135deg,#00B761,#00874A)'];

// Is this a service category (appointment only)?
$IS_SERVICE_CAT = in_array(strtolower($display_category), ['service', 'eye exam', 'treatment', 'screening']);

// Get products with 3D model check
$products_query = mysqli_query($conn, "
    SELECT p.*, c.name as clinic_name, c.id as clinic_id, c.address, c.contact, c.city, c.logo,
           (SELECT completed_model_file FROM custom_3d_requests
            WHERE inventory_id = p.inventory_id
            AND status = 'completed'
            AND completed_model_file IS NOT NULL
            LIMIT 1) as model_file
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.category = '$display_category'
    AND c.status = 'Active'
    ORDER BY p.price ASC
");
$total_products = mysqli_num_rows($products_query);

function getProductImage($product) {
    if (!empty($product['images_json'])) {
        $images = json_decode($product['images_json'], true);
        if (!empty($images) && isset($images[0])) {
            $imgPath = str_replace('uploads/uploads/', 'uploads/', $images[0]);
            return strpos($imgPath, 'uploads/') === 0 ? '/' . $imgPath : '/uploads/products/' . $imgPath;
        }
    }
    if (!empty($product['images'])) {
        $imgPath = $product['images'];
        if (strpos($imgPath, '[') === 0) {
            $images = json_decode($imgPath, true);
            if (!empty($images) && isset($images[0])) $imgPath = $images[0];
        }
        $imgPath = str_replace('uploads/uploads/', 'uploads/', $imgPath);
        return strpos($imgPath, 'uploads/') === 0 ? '/' . $imgPath : '/uploads/products/' . $imgPath;
    }
    if (!empty($product['image'])) {
        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
            return '/assets/images/products/' . $product['image'];
        }
        $imgPath = str_replace('uploads/uploads/', 'uploads/', $product['image']);
        return strpos($imgPath, 'uploads/') === 0 ? '/' . $imgPath : '/uploads/products/' . $imgPath;
    }
    return '/assets/img/no-image.png';
}

$active_nav = 'discover';
include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?php echo htmlspecialchars($display_category); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #00B761; --primary-dark: #00874A; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg,#00B761,#00A86B);
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF; --card-bg: #FFFFFF;
            --text-primary: #111827; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
            --shadow-hover: 0 20px 40px -12px rgba(0,183,97,0.25);
            --radius-sm: 10px; --radius-md: 14px; --radius-lg: 20px;
            --radius-xl: 28px; --radius-full: 999px;
            --danger: #EF4444; --warning: #F59E0B; --success: #00B761;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #0D2818;
            --bg-primary: #0D0D0D; --bg-secondary: #161616; --card-bg: #1E1E1E;
            --text-primary: #F9FAFB; --text-secondary: #9CA3AF; --text-muted: #6B7280;
            --border-color: #2A2A2A; --border-light: #222222;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.4);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.5);
        }

        body { font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; background: var(--bg-primary); color: var(--text-primary); font-size: 14px; transition: background 0.3s, color 0.3s; }

        .main-content { max-width: 1400px; margin: 0 auto; padding: 28px 40px; min-height: calc(100vh - 70px); }
        @media (max-width: 1024px) { .main-content { padding: 24px; } }
        @media (max-width: 768px)  { .main-content { padding: 18px 16px 100px; } }

        /* CATEGORY HEADER */
        .category-header { background: <?php echo $category_info['bg']; ?>; border-radius: var(--radius-xl); padding: 48px 40px; margin-bottom: 32px; text-align: center; color: white; position: relative; overflow: hidden; }
        .category-header::before { content: ''; position: absolute; top: -50%; right: -20%; width: 400px; height: 400px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%; }
        .category-header::after  { content: ''; position: absolute; bottom: -30%; left: -10%; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%; }
        .cat-hdr-icon { font-size: 64px; margin-bottom: 20px; position: relative; z-index: 1; }
        .category-header h1 { font-size: 40px; font-weight: 800; margin-bottom: 12px; position: relative; z-index: 1; letter-spacing: -0.5px; }
        .category-header p  { font-size: 16px; opacity: 0.9; margin-bottom: 24px; position: relative; z-index: 1; }
        .cat-stats { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: var(--radius-full); font-size: 13px; font-weight: 600; position: relative; z-index: 1; }
        @media (max-width: 768px) { .category-header { padding: 32px 24px; } .category-header h1 { font-size: 28px; } .cat-hdr-icon { font-size: 48px; } }

        /* FILTER BAR */
        .filter-bar { background: var(--card-bg); border-radius: var(--radius-lg); padding: 16px 24px; margin-bottom: 24px; border: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
        .filter-sort { display: flex; align-items: center; gap: 12px; }
        .filter-sort label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .sort-select { padding: 8px 16px; border: 1px solid var(--border-color); border-radius: var(--radius-full); background: var(--bg-primary); color: var(--text-primary); font-size: 13px; cursor: pointer; outline: none; font-family: inherit; }
        .sort-select:focus { border-color: var(--primary); }
        .results-count { font-size: 13px; color: var(--text-secondary); }
        .results-count span { font-weight: 700; color: var(--primary); }
        @media (max-width: 640px) { .filter-bar { flex-direction: column; align-items: flex-start; } }

        /* PRODUCTS GRID */
        .products-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 24px; margin-bottom: 40px; }
        @media (max-width: 1024px) { .products-grid { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 640px)  { .products-grid { grid-template-columns: 1fr; } }

        /* PRODUCT CARD — fixed height structure like Image 1 */
        .product-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .product-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); border-color: var(--primary); }

        /* Product image — FIXED height like Image 1 */
        .product-image {
            height: 220px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f5f7fa, #e5e7eb);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .product-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .product-card:hover .product-image img { transform: scale(1.05); }

        /* Category badge — moved to text section, no longer inside image */
        .badge-category { display: none; } /* hide old absolute version if still rendered elsewhere */
        .product-category-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        /* Sale badge top-left */
        .badge-sale { position: absolute; top: 12px; left: 12px; background: var(--danger); color: white; padding: 4px 10px; border-radius: var(--radius-full); font-size: 11px; font-weight: 700; z-index: 5; }

        /* 3D badge bottom-left */
        .badge-3d {
            position: absolute;
            bottom: 10px;
            left: 10px;
            background: linear-gradient(135deg,#0EA5E9,#0284C7);
            color: white;
            padding: 5px 11px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 8px rgba(14,165,233,0.35);
            transition: all 0.2s;
        }
        .badge-3d:hover { transform: scale(1.06); box-shadow: 0 4px 12px rgba(14,165,233,0.5); }

        /* Product info */
        .product-info { padding: 20px; }
        .product-title { font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; line-height: 1.4; }
        .product-description { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        /* Price */
        .price-wrap { margin-bottom: 12px; }
        .price-main { font-size: 22px; font-weight: 800; color: var(--primary); }
        .price-main.sale { color: var(--danger); }
        .price-orig { font-size: 13px; color: var(--text-muted); text-decoration: line-through; margin-left: 6px; }
        .price-tax  { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-left: 4px; }

        /* Clinic meta — with store icon on the right */
        .clinic-meta { display: flex; align-items: center; gap: 10px; padding: 12px 0 0; border-top: 1px solid var(--border-light); flex-wrap: nowrap; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary); min-width: 0; }
        .meta-item span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .meta-item i { color: var(--primary); font-size: 12px; flex-shrink: 0; }

        /* CTA row — same layout as Image 1 */
        .product-actions { display: flex; gap: 12px; }
        .btn-main {
            flex: 1;
            padding: 12px 20px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-main:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,183,97,0.3); }
        .btn-main.apt { background: linear-gradient(135deg,#3B82F6,#2563EB); }
        .btn-main.apt:hover { box-shadow: 0 8px 20px rgba(59,130,246,0.35); }

        .btn-clinic {
            width: 44px; height: 44px; flex-shrink: 0;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            display: flex; align-items: center; justify-content: center;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-clinic:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }

        /* EMPTY STATE */
        .empty-state { text-align: center; padding: 80px 40px; background: var(--card-bg); border-radius: var(--radius-lg); border: 1px solid var(--border-light); grid-column: 1/-1; }
        .empty-state i  { font-size: 80px; color: var(--text-muted); margin-bottom: 24px; display: block; opacity: 0.4; }
        .empty-state h2 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
        .empty-state p  { color: var(--text-secondary); font-size: 14px; margin-bottom: 28px; max-width: 400px; margin-left: auto; margin-right: auto; }

        /* BACK BUTTON */
        .back-button { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-full); color: var(--text-secondary); text-decoration: none; font-size: 13px; font-weight: 600; margin-bottom: 20px; transition: all 0.2s; }
        .back-button:hover { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }

        .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: var(--primary-gradient); color: white; text-decoration: none; border-radius: var(--radius-full); font-weight: 600; transition: all 0.2s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,183,97,0.3); }

        /* TOAST */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; pointer-events: none; }
        .toast-notification { pointer-events: auto; display: flex; align-items: center; gap: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 14px 20px; box-shadow: var(--shadow-lg); margin-bottom: 10px; min-width: 300px; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); }
        .toast-notification i { font-size: 18px; }
        .toast-notification.success i { color: var(--success); }
        .toast-notification.error   i { color: var(--danger); }
        .toast-notification span { font-size: 13px; color: var(--text-primary); flex: 1; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeOut { to { opacity: 0; } }

        /* ===== PRODUCT FAVORITE HEART BUTTON ===== */
        .btn-fav-product {
            position: absolute;
            top: 8px; right: 8px;
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.90);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 25;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18);
            transition: all 0.2s;
            backdrop-filter: blur(4px);
        }
        .btn-fav-product i { font-size: 14px; color: #ccc; transition: all 0.2s; }
        .btn-fav-product.active i { color: #EF4444; }
        .btn-fav-product:hover { transform: scale(1.15); background: white; }
        .btn-fav-product:hover i { color: #EF4444; }
        .btn-fav-product.pop { animation: favPop 0.3s ease; }
        @keyframes favPop { 0%{transform:scale(1);} 50%{transform:scale(1.35);} 100%{transform:scale(1);} }
        .theme-dark .btn-fav-product { background: rgba(30,30,30,0.88); }
        .theme-dark .btn-fav-product i { color: #555; }
        .theme-dark .btn-fav-product.active i { color: #EF4444; }
    </style>
</head>
<body>
<div class="main-content">

    <a href="dashboard.php" class="back-button">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <!-- Category Header -->
    <div class="category-header">
        <div class="cat-hdr-icon"><i class="<?php echo $category_info['icon']; ?>"></i></div>
        <h1><?php echo htmlspecialchars($display_category); ?></h1>
        <p>Discover the best <?php echo strtolower(htmlspecialchars($display_category)); ?> from trusted eye clinics near you</p>
        <div class="cat-stats">
            <i class="fas fa-box"></i>
            <span><?php echo $total_products; ?> products available</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <?php if ($total_products > 0): ?>
    <div class="filter-bar">
        <div class="filter-sort">
            <label><i class="fas fa-sort-amount-down"></i> Sort by:</label>
            <select id="sortSelect" class="sort-select" onchange="sortProducts(this.value)">
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
                <option value="name_asc">Name: A to Z</option>
                <option value="name_desc">Name: Z to A</option>
            </select>
        </div>
        <div class="results-count">
            <i class="fas fa-eye"></i> Showing <span id="productCount"><?php echo $total_products; ?></span> products
        </div>
    </div>
    <?php endif; ?>

    <!-- Products Grid -->
    <div class="products-grid" id="productsGrid">
        <?php if ($total_products > 0):
            $products_array = [];
            while ($product = mysqli_fetch_assoc($products_query)) {
                $products_array[] = $product;
            }
            foreach ($products_array as $product):
                $has_3d     = !empty($product['model_file']);
                $is_on_sale = !empty($product['is_on_sale'])
                              && $product['is_on_sale'] == 1
                              && !empty($product['sale_price'])
                              && !empty($product['sale_end'])
                              && strtotime($product['sale_end']) >= strtotime('today');
                $display_price = $is_on_sale ? $product['sale_price'] : $product['price'];
                $discount_pct  = $is_on_sale ? round((($product['price'] - $product['sale_price']) / $product['price']) * 100) : 0;
        ?>
        <div class="product-card"
             data-price="<?php echo $display_price; ?>"
             data-name="<?php echo strtolower(htmlspecialchars($product['name'])); ?>"
             onclick="window.location.href='product-view.php?id=<?php echo $product['id']; ?>'">

            <div class="product-image" style="position:relative;">
                <img src="<?php echo getProductImage($product); ?>"
                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                     onerror="this.src='/assets/img/no-image.png'">

                <!-- Favorite heart button — top-right, consistent sa lahat ng pages -->
                <?php $is_fav_prod = in_array($product['id'], $user_favorited_products); ?>
                <button class="btn-fav-product <?php echo $is_fav_prod ? 'active' : ''; ?>"
                        data-product-id="<?php echo $product['id']; ?>"
                        onclick="toggleProductFav(this, event)"
                        title="<?php echo $is_fav_prod ? 'Remove from favorites' : 'Add to favorites'; ?>">
                    <i class="fa<?php echo $is_fav_prod ? 's' : 'r'; ?> fa-heart"></i>
                </button>

                <?php if ($is_on_sale): ?>
                    <span class="badge-sale">-<?php echo $discount_pct; ?>%</span>
                <?php endif; ?>

                <?php if ($has_3d): ?>
                    <a href="3d-view.php?id=<?php echo $product['id']; ?>"
                       class="badge-3d"
                       onclick="event.stopPropagation()">
                        <i class="fas fa-cube"></i> 3D
                    </a>
                <?php endif; ?>
            </div>

            <div class="product-info">
                <!-- Category label moved here — mas malinis ang image area -->
                <div class="product-category-label"><?php echo htmlspecialchars($product['category']); ?></div>
                <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                <p class="product-description"><?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 100)); ?></p>

                <div class="price-wrap">
                    <span class="price-main <?php echo $is_on_sale ? 'sale' : ''; ?>">
                        ₱<?php echo number_format($display_price, 2); ?>
                    </span>
                    <?php if ($is_on_sale): ?>
                        <span class="price-orig">₱<?php echo number_format($product['price'], 2); ?></span>
                    <?php else: ?>
                        <small class="price-tax">+ tax</small>
                    <?php endif; ?>
                </div>

                <div class="clinic-meta">
                    <div class="meta-item" style="flex:1;">
                        <i class="fas fa-clinic-medical"></i>
                        <span><?php echo htmlspecialchars($product['clinic_name']); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($product['city'] ?? 'Cavite'); ?></span>
                    </div>
                    <a href="clinic-details.php?id=<?php echo $product['clinic_id']; ?>"
                       class="btn-clinic"
                       title="View Clinic"
                       onclick="event.stopPropagation()">
                        <i class="fas fa-store"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach;
        else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h2>No Products Found</h2>
                <p>We couldn't find any <?php echo strtolower(htmlspecialchars($display_category)); ?> available at the moment.</p>
                <a href="dashboard.php" class="btn-primary">
                    <i class="fas fa-arrow-left"></i> Browse Other Categories
                </a>
            </div>
        <?php endif; ?>
    </div>

</div>

<div id="toastContainer" class="toast-container"></div>

<script>
function showToast(msg, type='success') {
    const c = document.getElementById('toastContainer');
    if (!c) return;
    const t = document.createElement('div');
    t.className = `toast-notification ${type}`;
    const icon = type === 'error' ? 'fa-exclamation-circle' : (type === 'info' ? 'fa-info-circle' : 'fa-check-circle');
    t.innerHTML = `<i class="fas ${icon}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.animation = 'fadeOut 0.3s ease'; setTimeout(() => t.remove(), 300); }, 3000);
}

function sortProducts(sortBy) {
    const grid = document.getElementById('productsGrid');
    const cards = Array.from(grid.querySelectorAll('.product-card'));
    if (!cards.length) return;
    cards.sort((a, b) => {
        switch (sortBy) {
            case 'price_asc':  return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
            case 'price_desc': return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
            case 'name_asc':   return a.dataset.name.localeCompare(b.dataset.name);
            case 'name_desc':  return b.dataset.name.localeCompare(a.dataset.name);
            default: return 0;
        }
    });
    cards.forEach(c => grid.appendChild(c));
    showToast('Products sorted', 'success');
}

document.addEventListener('DOMContentLoaded', () => {
    const cards = document.querySelectorAll('.product-card');
    cards.forEach((card, i) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, i * 50);
    });
    setTimeout(() => showToast('<?php echo addslashes($display_category); ?> collection 👓', 'success'), 500);
});

function toggleProductFav(btn, event) {
    event.preventDefault();
    event.stopPropagation();
    const productId = btn.dataset.productId;
    const isActive  = btn.classList.contains('active');
    const formData  = new FormData();
    formData.append('product_id', productId);

    btn.classList.toggle('active');
    btn.classList.add('pop');
    btn.querySelector('i').className = btn.classList.contains('active') ? 'fas fa-heart' : 'far fa-heart';
    setTimeout(() => btn.classList.remove('pop'), 300);

    fetch('toggle-product-favorite.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.action === 'added' ? '❤️ Added to favorites!' : 'Removed from favorites', data.action === 'added' ? 'success' : 'info');
            } else {
                btn.classList.toggle('active');
                btn.querySelector('i').className = isActive ? 'fas fa-heart' : 'far fa-heart';
                showToast('Something went wrong.', 'error');
            }
        })
        .catch(() => {
            btn.classList.toggle('active');
            btn.querySelector('i').className = isActive ? 'fas fa-heart' : 'far fa-heart';
            showToast('Network error. Please try again.', 'error');
        });
}
</script>
</body>
</html>