<?php
// ============================================
// SESSION START - MUST BE FIRST
// ============================================
session_name('eyecore_user');
session_start();

include '../includes/config.php';
include '../includes/theme.php';

// ============================================
// CHECK IF USER IS LOGGED IN
// ============================================
if (!isset($_SESSION['user_id'])) {
    // Save the intended destination
    $_SESSION['redirect_after_login'] = 'pages/explore-3d.php';
    header('Location: ../auth/user_login.php?redirect=explore-3d');
    exit();
}

// ============================================
// CONTINUE WITH PAGE
// ============================================
$user_id = $_SESSION['user_id'];

$user_query  = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user        = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data   = mysqli_fetch_assoc($avatar_query);

$unread_count        = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$pending_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending   = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count       = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$total_points = mysqli_fetch_assoc($points_query)['total_points'] ?? 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($bookings_query)['total'] ?? 0;

$reservation_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending','confirmed')");
$reservation_count = mysqli_fetch_assoc($reservation_query)['total'] ?? 0;

// ============================================
// GET CATEGORY FILTER
// ============================================
$filter_cat = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : 'all';

// ============================================
// GET ALL PRODUCTS WITH 3D MODELS
// ============================================
$cat_where = $filter_cat !== 'all' ? "AND p.category = '$filter_cat'" : '';

$products_query = mysqli_query($conn, "
    SELECT DISTINCT p.*,
           c.name  AS clinic_name,
           c.id    AS clinic_id,
           c.city  AS clinic_city,
           c.logo  AS clinic_logo,
           pm.model_file,
           pm.model_type,
           pm.frame_style,
           pm.is_instant_3d,
           (SELECT SUM(quantity) FROM product_color_inventory
            WHERE product_id = p.id AND clinic_id = p.clinic_id
            AND quantity > 0 AND is_available = 1) as total_stock
    FROM product_3d_models pm
    JOIN products p  ON pm.product_id = p.id OR pm.inventory_id = p.inventory_id
    JOIN clinics  c  ON p.clinic_id = c.id
    WHERE pm.has_3d = 1
    AND   pm.model_file IS NOT NULL
    AND   c.status = 'Active'
    $cat_where
    ORDER BY pm.is_instant_3d DESC, p.name ASC
");

$products_list  = [];
$all_categories = [];
$seen_ids = [];
while ($row = mysqli_fetch_assoc($products_query)) {
    // Deduplicate by product id (LEFT JOIN may return multiple rows)
    if (!isset($seen_ids[$row['id']])) {
        $seen_ids[$row['id']] = true;
        $products_list[]  = $row;
        $all_categories[] = $row['category'];
    }
}
$all_categories = array_unique($all_categories);
sort($all_categories);
$total_3d = count($products_list);

// ============================================
// FETCH USER FAVORITED PRODUCTS
// ============================================
$fav_result = mysqli_query($conn, "SELECT product_id FROM favorites WHERE user_id = $user_id AND product_id IS NOT NULL");
$user_favorited_products = [];
while ($frow = mysqli_fetch_assoc($fav_result)) {
    $user_favorited_products[] = (int)$frow['product_id'];
}

// ============================================
// HELPER: Build product image URL
// ============================================
function getProductImg($product) {
    if (!empty($product['images_json'])) {
        $imgs = json_decode($product['images_json'], true);
        if (!empty($imgs[0])) {
            $p = str_replace('uploads/uploads/', 'uploads/', $imgs[0]);
            return strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    if (!empty($product['images'])) {
        $d = $product['images'];
        if (strpos($d, '[') === 0) {
            $imgs = json_decode($d, true);
            if (!empty($imgs[0])) {
                $p = str_replace('uploads/uploads/', 'uploads/', $imgs[0]);
                return strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
            }
        } else {
            $p = str_replace('uploads/uploads/', 'uploads/', $d);
            return strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    if (!empty($product['image'])) {
        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false)
            return '/assets/images/products/' . $product['image'];
        $p = str_replace('uploads/uploads/', 'uploads/', $product['image']);
        return strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
    }
    return '/assets/img/no-image.png';
}

$active_nav = 'discover';
include '../includes/navbar.php';
?>
<!-- REST OF YOUR HTML STAYS THE SAME -->
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Explore 3D Frames — Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; overflow-x: hidden; background: var(--bg-primary); min-height: 100vh; }
        body { font-family: 'Inter', -apple-system, sans-serif; transition: background 0.3s, color 0.3s; }

        :root {
            --primary: #00B761; --primary-dark: #00994D; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-hover: 0 20px 40px -10px rgba(0,183,97,0.25);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 999px;
            --danger: #EF4444; --warning: #F59E0B; --success: #00B761; --info: #3B82F6;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
        }

        .main-content { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        @media (min-width: 1024px) { .main-content { padding: 30px 40px; } }
        @media (max-width: 768px)  { .main-content { padding: 20px 16px 100px; } }

        /* ===== HERO BANNER ===== */
        .hero-banner {
            background: linear-gradient(120deg, #060f1e 0%, #0b2540 50%, #082d1a 100%);
            border-radius: var(--radius-lg);
            padding: 36px 40px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        @media (max-width: 640px) { .hero-banner { flex-direction: column; padding: 28px 24px; } }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 220px; height: 220px;
            background: radial-gradient(circle, rgba(0,230,118,0.12), transparent 70%);
            border-radius: 50%;
        }
        .hero-tag   { font-size: 10px; color: #7dffc0; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .hero-title { font-size: 26px; font-weight: 800; color: white; margin-bottom: 6px; line-height: 1.2; letter-spacing: -0.5px; }
        .hero-sub   { font-size: 13px; color: rgba(255,255,255,0.6); line-height: 1.5; max-width: 400px; }
        .hero-stat  { display: flex; align-items: center; gap: 20px; margin-top: 16px; flex-wrap: wrap; }
        .hero-stat-item { display: flex; align-items: center; gap: 7px; }
        .hero-stat-item i { color: #7dffc0; font-size: 13px; }
        .hero-stat-item span { font-size: 12px; color: rgba(255,255,255,0.7); font-weight: 500; }
        .hero-stat-item strong { color: white; }
        .hero-icon-wrap {
            width: 90px; height: 90px; flex-shrink: 0;
            background: rgba(0,230,118,0.1);
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            border: 1px solid rgba(0,230,118,0.2);
        }
        .hero-icon-wrap i { font-size: 40px; color: #7dffc0; }

        /* ===== BACK + CONTROLS ===== */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--primary); text-decoration: none;
            font-size: 13px; font-weight: 500;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }
        .btn-back:hover { background: var(--primary); color: white; }
        .results-info { font-size: 13px; color: var(--text-secondary); }
        .results-info strong { color: var(--text-primary); }

        /* ===== FILTER TABS ===== */
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; }
        .filter-tab {
            padding: 8px 18px;
            background: var(--bg-secondary);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-full);
            cursor: pointer; font-size: 13px; font-weight: 600;
            color: var(--text-secondary); text-decoration: none;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 7px;
        }
        .filter-tab:hover { border-color: var(--primary); color: var(--primary); }
        .filter-tab.active { background: var(--primary-gradient); color: white; border-color: transparent; }

        /* ===== PRODUCTS GRID ===== */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 20px;
        }
        @media (max-width: 640px) { .products-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; } }

        /* ===== PRODUCT CARD ===== */
        .product-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1.5px solid var(--border-light);
            overflow: hidden;
            transition: all 0.22s;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            position: relative;
        }
        .product-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); border-color: var(--primary); }

        /* Image area */
        .product-img {
            position: relative;
            width: 100%;
            height: 175px;
            overflow: hidden;
            background: var(--bg-primary);
        }
        .product-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .product-card:hover .product-img img { transform: scale(1.04); }

        /* 3D badge — top-left */
        .badge-3d-view {
            position: absolute;
            top: 8px; left: 8px;
            background: linear-gradient(135deg, #0EA5E9, #0284C7);
            color: white;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(14,165,233,0.45);
        }

        /* Sale badge */
        .badge-sale {
            position: absolute;
            top: 8px; left: 8px;
            background: linear-gradient(135deg, #EF4444, #FF6B6B);
            color: white;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 10px; font-weight: 700;
            z-index: 10;
        }
        /* If both 3D and sale badges, push sale down */
        .badge-sale.with-3d { top: 34px; }

        /* Out of stock */
        .badge-out {
            position: absolute;
            top: 8px; left: 8px;
            background: var(--danger);
            color: white;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 10px; font-weight: 700;
            z-index: 10;
            display: flex; align-items: center; gap: 4px;
        }

        /* Heart button — top-right */
        .btn-fav-product {
            position: absolute;
            top: 8px; right: 8px;
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.90);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            z-index: 15;
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

        /* Card body */
        .product-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; }
        .product-cat  { font-size: 10px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .product-name { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .product-clinic { font-size: 11px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; margin-bottom: 10px; }
        .product-price { font-size: 17px; font-weight: 800; color: var(--primary); margin-top: auto; }
        .product-price.sale { color: #EF4444; }
        .product-price .orig { font-size: 11px; color: var(--text-muted); text-decoration: line-through; font-weight: 400; margin-left: 5px; }

        /* View 3D CTA button on card */
        .btn-view-3d {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            margin-top: 12px;
            padding: 10px;
            background: linear-gradient(135deg, #0EA5E9, #0284C7);
            color: white;
            border-radius: var(--radius-full);
            font-size: 12px; font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-view-3d:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(14,165,233,0.4); color: white; }

        /* Empty state */
        .empty-state { grid-column: 1/-1; text-align: center; padding: 70px 20px; background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--border-light); }
        .empty-state i { font-size: 52px; color: var(--text-muted); margin-bottom: 16px; opacity: 0.4; display: block; }
        .empty-state h2 { font-size: 20px; color: var(--text-primary); margin-bottom: 8px; }
        .empty-state p  { color: var(--text-secondary); font-size: 14px; }

        /* Toast */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast-notification { display: flex; align-items: center; gap: 12px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 14px 20px; box-shadow: 0 12px 40px rgba(0,0,0,0.10); margin-bottom: 10px; min-width: 280px; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info  { border-left-color: var(--info); }
        .toast-notification i { font-size: 16px; }
        .toast-notification.success i { color: var(--success); }
        .toast-notification.error   i { color: var(--danger); }
        .toast-notification.info    i { color: var(--info); }
        .toast-notification span { font-size: 13px; color: var(--text-primary); flex: 1; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="main-content">

    <!-- Hero Banner -->
    <div class="hero-banner">
        <div>
            <div class="hero-tag"><i class="fas fa-cube"></i> 3D Frame Viewer</div>
            <div class="hero-title">Explore Frames in 360°</div>
            <div class="hero-sub">Rotate, zoom, and inspect eyeglass frames in full 3D detail — before you book or reserve.</div>
            <div class="hero-stat">
                <div class="hero-stat-item">
                    <i class="fas fa-cube"></i>
                    <span><strong><?php echo $total_3d; ?></strong> frame<?php echo $total_3d != 1 ? 's' : ''; ?> with 3D model</span>
                </div>
                <div class="hero-stat-item">
                    <i class="fas fa-clinic-medical"></i>
                    <span>From <strong><?php echo count(array_unique(array_column($products_list, 'clinic_id'))); ?></strong> clinic<?php echo count(array_unique(array_column($products_list, 'clinic_id'))) != 1 ? 's' : ''; ?></span>
                </div>
            </div>
        </div>
        <div class="hero-icon-wrap">
            <i class="fas fa-cube"></i>
        </div>
    </div>

    <!-- Top bar -->
    <div class="top-bar">
        <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Home</a>
        <div class="results-info">
            Showing <strong><?php echo $total_3d; ?></strong> 3D frame<?php echo $total_3d != 1 ? 's' : ''; ?>
            <?php if ($filter_cat !== 'all'): ?>
                in <strong><?php echo htmlspecialchars($filter_cat); ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <!-- Category Filter Tabs -->
    <?php if (!empty($all_categories)): ?>
    <div class="filter-tabs">
        <a href="explore-3d.php" class="filter-tab <?php echo $filter_cat === 'all' ? 'active' : ''; ?>">
            <i class="fas fa-th"></i> All
        </a>
        <?php foreach ($all_categories as $cat): ?>
        <a href="explore-3d.php?category=<?php echo urlencode($cat); ?>"
           class="filter-tab <?php echo $filter_cat === $cat ? 'active' : ''; ?>">
            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($cat); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Products Grid -->
    <div class="products-grid">
        <?php if (!empty($products_list)): ?>
            <?php foreach ($products_list as $product):
                $pid        = $product['id'];
                $is_on_sale = !empty($product['is_on_sale']) && $product['is_on_sale'] == 1
                              && !empty($product['sale_price']) && !empty($product['sale_end'])
                              && strtotime($product['sale_end']) >= strtotime('today');
                $display_price = $is_on_sale ? (float)$product['sale_price'] : (float)$product['price'];
                $orig_price    = (float)$product['price'];
                $discount_pct  = $is_on_sale ? round((($orig_price - $display_price) / $orig_price) * 100) : 0;
                $is_out        = ($product['total_stock'] !== null && (int)$product['total_stock'] === 0);
                $is_fav        = in_array($pid, $user_favorited_products);
                $img_url       = getProductImg($product);
            ?>
            <div class="product-card">

                <!-- Image -->
                <div class="product-img">
                    <img src="<?php echo $img_url; ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         onerror="this.src='/assets/img/no-image.png'">

                    <!-- 3D badge — top-left -->
                    <?php if (!$is_out): ?>
                        <div class="badge-3d-view"><i class="fas fa-cube"></i> 3D View</div>
                    <?php else: ?>
                        <div class="badge-out"><i class="fas fa-times-circle"></i> Out of Stock</div>
                    <?php endif; ?>

                    <!-- Sale badge -->
                    <?php if ($is_on_sale && !$is_out): ?>
                        <span class="badge-sale with-3d">-<?php echo $discount_pct; ?>%</span>
                    <?php endif; ?>

                    <!-- Heart button — top-right -->
                    <button class="btn-fav-product <?php echo $is_fav ? 'active' : ''; ?>"
                            data-product-id="<?php echo $pid; ?>"
                            onclick="toggleProductFav(this, event)"
                            title="<?php echo $is_fav ? 'Remove from favorites' : 'Add to favorites'; ?>">
                        <i class="fa<?php echo $is_fav ? 's' : 'r'; ?> fa-heart"></i>
                    </button>
                </div>

                <!-- Body -->
                <div class="product-body">
                    <div class="product-cat"><?php echo htmlspecialchars($product['category']); ?></div>
                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <div class="product-clinic">
                        <i class="fas fa-clinic-medical"></i>
                        <?php echo htmlspecialchars($product['clinic_name']); ?>
                        <?php if (!empty($product['clinic_city'])): ?>
                            · <?php echo htmlspecialchars($product['clinic_city']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="product-price <?php echo $is_on_sale ? 'sale' : ''; ?>">
                        ₱<?php echo number_format($display_price, 2); ?>
                        <?php if ($is_on_sale): ?>
                            <span class="orig">₱<?php echo number_format($orig_price, 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- View 3D button -->
                    <a href="3d-view.php?id=<?php echo $pid; ?>" class="btn-view-3d">
                        <i class="fas fa-cube"></i> View in 3D
                    </a>
                </div>

            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-cube"></i>
                <h2>No 3D Frames Found</h2>
                <p>
                    <?php if ($filter_cat !== 'all'): ?>
                        No 3D models available for <strong><?php echo htmlspecialchars($filter_cat); ?></strong> yet.
                        <a href="explore-3d.php" style="color:var(--primary);">View all categories</a>
                    <?php else: ?>
                        No products with 3D models are available yet. Check back soon!
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast-notification ' + type;
    const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
}

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