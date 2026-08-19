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

// Get unique categories for filter with counts
$categories_query = mysqli_query($conn, "
    SELECT p.category, COUNT(*) as total 
    FROM products p
    WHERE p.is_on_sale = 1 
    AND p.sale_start <= CURDATE() 
    AND p.sale_end >= CURDATE()
    GROUP BY p.category
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

// ============================================
// HELPER: Get product image URL
// ============================================
function getProductImageUrl($product) {
    if (empty($product['image'])) {
        return null;
    }
    
    $image = $product['image'];
    
    // Already a full URL
    if (strpos($image, 'http') === 0 || strpos($image, '//') === 0) {
        return $image;
    }
    
    // Already has uploads/ prefix
    if (strpos($image, 'uploads/') === 0) {
        return '/' . $image;
    }
    
    // Already has /uploads/ prefix
    if (strpos($image, '/uploads/') === 0) {
        return $image;
    }
    
    // Product from color inventory (prod_XXX_...)
    if (strpos($image, 'prod_') === 0) {
        return '/uploads/products/' . $image;
    }
    
    // Default: filename only → /uploads/products/
    return '/uploads/products/' . $image;
}

// ============================================
// CHECK IF ANY SALE PRODUCTS EXIST
// ============================================
$has_sales = $total_items > 0;

// Gradient placeholders for no-image
$grad_colors = [
    ['#E3FCE9','#B7F5D2','#00994D'],
    ['#DBEAFE','#BFDBFE','#1D4ED8'],
    ['#FEF3C7','#FDE68A','#D97706'],
    ['#EDE9FE','#DDD6FE','#6D28D9'],
    ['#FFE4E6','#FECDD3','#BE123C'],
    ['#CCFBF1','#99F6E4','#0F766E'],
];
$grad_idx = 0;
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

        /* ACTIVE FILTER BADGES */
        .active-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; align-items: center; }
        .filter-badge { background: var(--primary-light); color: var(--primary); padding: 4px 12px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
        .filter-badge a { color: var(--danger); text-decoration: none; font-size: 10px; margin-left: 4px; }
        .filter-badge a:hover { color: var(--danger); }

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

        .product-image {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-bottom: 1px solid var(--border-light);
            position: relative;
            flex-shrink: 0;
            background: #f8f9fa;
        }
        .product-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-image.no-image { background: linear-gradient(135deg, #E3FCE9, #B7F5D2); }
        .product-image.no-image i { color: #00994D; opacity: 0.5; font-size: 52px; }

        .product-info { padding: 20px; display: flex; flex-direction: column; flex: 1; }
        .clinic-name { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
        .clinic-name i { color: var(--primary); font-size: 10px; }
        .product-name { font-size: 16px; color: var(--text-primary); font-weight: 700; margin-bottom: 12px; line-height: 1.3; }
        .price-section { margin-bottom: 10px; display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; }
        .sale-price { font-size: 22px; color: var(--sale-color); font-weight: 700; }
        .original-price { font-size: 15px; color: var(--text-muted); text-decoration: line-through; }

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

        /* ===== IMPROVED EMPTY STATE ===== */
        .empty-state-sales {
            grid-column: 1 / -1;
            text-align: center;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 2px dashed var(--border-color);
            padding: 60px 40px;
            max-width: 560px;
            margin: 0 auto;
            width: 100%;
            position: relative;
            overflow: hidden;
        }
        .empty-state-sales::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,68,68,0.05) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .empty-state-sales .empty-icon {
            font-size: 64px;
            color: var(--text-muted);
            margin-bottom: 16px;
            opacity: 0.4;
            display: block;
        }
        .empty-state-sales h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .empty-state-sales p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        .empty-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .empty-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .empty-btn.primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 14px rgba(0,183,97,0.25);
        }
        .empty-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,183,97,0.35);
        }
        .empty-btn.secondary {
            background: var(--bg-primary);
            color: var(--text-secondary);
            border: 1.5px solid var(--border-color);
        }
        .empty-btn.secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .empty-tip {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            padding-top: 16px;
            border-top: 1px solid var(--border-light);
        }
        .empty-tip i { color: var(--warning); }

        /* PAGINATION */
        .pagination-container { margin-top: 40px; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 15px; }
        .pagination-btn { min-width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; background: var(--bg-secondary); border: 1px solid var(--border-light); border-radius: var(--radius-md); color: var(--text-secondary); text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; padding: 0 12px; }
        .pagination-btn:hover { background: var(--sale-color); color: white; border-color: var(--sale-color); }
        .pagination-btn.active { background: var(--sale-gradient); color: white; border-color: transparent; font-weight: 600; }
        .pagination-btn.disabled { opacity: 0.5; pointer-events: none; }
        .pagination-info { text-align: center; color: var(--text-muted); font-size: 13px; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .filter-row { flex-direction: column; align-items: flex-start; }
            .filter-select { width: 100%; }
            .clear-filters { margin-left: 0; width: 100%; justify-content: center; }
            .results-info { flex-direction: column; align-items: flex-start; }
            .sort-select { flex: 1; }
            .products-grid { grid-template-columns: 1fr; }
            .empty-state-sales { padding: 40px 24px; }
            .empty-state-sales .empty-icon { font-size: 48px; }
            .empty-state-sales h2 { font-size: 20px; }
            .page-header h1 { font-size: 24px; }
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
                            <option value="all" <?php echo $category_filter == 'all' ? 'selected' : ''; ?>>All Categories <?php if ($total_items > 0): ?>(<?php echo $total_items; ?>)<?php endif; ?></option>
                            <?php 
                            // Reset categories query pointer
                            if (mysqli_num_rows($categories_query) > 0) {
                                mysqli_data_seek($categories_query, 0);
                            }
                            while ($cat = mysqli_fetch_assoc($categories_query)): 
                            ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                    <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?> (<?php echo $cat['total']; ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label"><i class="fas fa-percent"></i> Min Discount:</span>
                        <div class="discount-buttons">
                            <button type="button" class="discount-btn <?php echo $min_discount == 0 ? 'active' : ''; ?>" onclick="setDiscount(0, event)">All</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 20 ? 'active' : ''; ?>" onclick="setDiscount(20, event)">20%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 30 ? 'active' : ''; ?>" onclick="setDiscount(30, event)">30%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 40 ? 'active' : ''; ?>" onclick="setDiscount(40, event)">40%+</button>
                            <button type="button" class="discount-btn <?php echo $min_discount == 50 ? 'active' : ''; ?>" onclick="setDiscount(50, event)">50%+</button>
                        </div>
                        <input type="hidden" name="min_discount" id="min_discount" value="<?php echo $min_discount; ?>">
                    </div>
                    <a href="sale-products.php" class="clear-filters"><i class="fas fa-times"></i> Clear Filters</a>
                </div>
                <input type="hidden" name="page" value="1">
            </form>
        </div>

        <!-- Active Filters Badges -->
        <?php if ($category_filter != 'all' || $min_discount > 0): ?>
        <div class="active-filters">
            <span class="filter-label"><i class="fas fa-filter"></i> Active Filters:</span>
            <?php if ($category_filter != 'all'): ?>
            <span class="filter-badge">
                <?php echo htmlspecialchars($category_filter); ?>
                <a href="?category=all&min_discount=<?php echo $min_discount; ?>&sort=<?php echo $sort_by; ?>">
                    <i class="fas fa-times"></i>
                </a>
            </span>
            <?php endif; ?>
            <?php if ($min_discount > 0): ?>
            <span class="filter-badge">
                <?php echo $min_discount; ?>%+ off
                <a href="?category=<?php echo $category_filter; ?>&min_discount=0&sort=<?php echo $sort_by; ?>">
                    <i class="fas fa-times"></i>
                </a>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="results-info">
            <div class="results-count">
                <i class="fas fa-box"></i>
                Showing <span><?php echo min($items_per_page, $total_items - ($page - 1) * $items_per_page); ?></span> of <span><?php echo $total_items; ?></span> results
                <?php if ($category_filter != 'all'): ?> in <span><?php echo htmlspecialchars($category_filter); ?></span><?php endif; ?>
                <?php if ($min_discount > 0): ?> with <span><?php echo $min_discount; ?>%+ discount</span><?php endif; ?>
            </div>
            <div class="sort-options">
                <span class="sort-label">Sort by:</span>
                <select class="sort-select" onchange="window.location.href='?category=<?php echo urlencode($category_filter); ?>&min_discount=<?php echo $min_discount; ?>&sort='+this.value+'&page=1'">
                    <option value="discount_desc" <?php echo $sort_by == 'discount_desc' ? 'selected' : ''; ?>>Highest Discount</option>
                    <option value="discount_asc"  <?php echo $sort_by == 'discount_asc'  ? 'selected' : ''; ?>>Lowest Discount</option>
                    <option value="price_asc"     <?php echo $sort_by == 'price_asc'     ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_desc"    <?php echo $sort_by == 'price_desc'    ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="ending_soon"   <?php echo $sort_by == 'ending_soon'   ? 'selected' : ''; ?>>Ending Soon</option>
                </select>
            </div>
        </div>

        <div class="products-grid">
            <?php if ($has_sales && mysqli_num_rows($sale_products_query) > 0): 
                // Reset pointer before looping
                mysqli_data_seek($sale_products_query, 0);
                while ($product = mysqli_fetch_assoc($sale_products_query)): 
                    $urgent = $product['days_left'] <= 3;
                    $gc = $grad_colors[$grad_idx % count($grad_colors)];
                    $grad_idx++;
                    $total_sale_days = 30;
                    $days_used = $total_sale_days - $product['days_left'];
                    $progress_pct = max(10, min(95, ($days_used / $total_sale_days) * 100));
                    $end_date = date('M j', strtotime('+' . $product['days_left'] . ' days'));
                    $img_src = getProductImageUrl($product);
            ?>
                <a href="product-details.php?id=<?php echo $product['id']; ?>" class="product-card">
                    <div class="sale-badge">-<?php echo $product['discount_percent']; ?>%</div>
                    <?php if ($urgent): ?>
                    <div class="urgent-badge"><i class="fas fa-fire"></i> Last <?php echo $product['days_left']; ?> day<?php echo $product['days_left'] != 1 ? 's' : ''; ?>!</div>
                    <?php endif; ?>
                    
                    <div class="product-image <?php echo empty($img_src) ? 'no-image' : ''; ?>"
                         <?php if (empty($img_src)): ?>style="background: linear-gradient(135deg, <?php echo $gc[0]; ?>, <?php echo $gc[1]; ?>);"<?php endif; ?>>
                        <?php if (!empty($img_src)): ?>
                            <img src="<?php echo htmlspecialchars($img_src); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 onerror="this.style.display='none';this.parentElement.classList.add('no-image');this.parentElement.innerHTML='<i class=\'fas fa-glasses\' style=\'color:<?php echo $gc[2]; ?>;opacity:0.5;font-size:52px;\'></i>'">
                        <?php else: ?>
                            <i class="fas fa-glasses" style="color:<?php echo $gc[2]; ?>;opacity:0.5;font-size:52px;"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="product-info">
                        <div class="clinic-name">
                            <i class="fas fa-clinic-medical"></i>
                            <?php echo htmlspecialchars($product['clinic_name']); ?>
                            <?php if (!empty($product['city'])): ?> · <?php echo htmlspecialchars($product['city']); ?><?php endif; ?>
                        </div>
                        <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                        <div class="price-section">
                            <span class="sale-price">₱<?php echo number_format($product['sale_price'], 2); ?></span>
                            <span class="original-price">₱<?php echo number_format($product['price'], 2); ?></span>
                        </div>
                        <div class="sale-progress-wrap">
                            <div class="sale-progress-bar">
                                <div class="sale-progress-fill" style="width:<?php echo $progress_pct; ?>%"></div>
                            </div>
                        </div>
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
            <?php endwhile; ?>

            <?php else: ?>
                <!-- ===== IMPROVED EMPTY STATE ===== -->
                <div class="empty-state-sales">
                    <span class="empty-icon">🎉</span>
                    <h2>No Sales Right Now</h2>
                    <p>
                        Don't worry! Sales come and go. 
                        Check out our clinics or explore our full catalog for great deals.
                    </p>
                    <div class="empty-actions">
                        <a href="clinics-map.php" class="empty-btn primary">
                            <i class="fas fa-map-marked-alt"></i> Browse Clinics
                        </a>
                        <a href="dashboard.php" class="empty-btn secondary">
                            <i class="fas fa-arrow-right"></i> Explore Products
                        </a>
                    </div>
                    <div class="empty-tip">
                        <i class="fas fa-lightbulb"></i>
                        Tip: Follow clinics to get notified when they launch new promotions!
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?category=<?php echo urlencode($category_filter); ?>&min_discount=<?php echo $min_discount; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page-1; ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i></a>
                <?php else: ?><span class="pagination-btn disabled"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
                <?php
                $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2);
                if ($start_page > 1) { echo '<a href="?category='.urlencode($category_filter).'&min_discount='.$min_discount.'&sort='.$sort_by.'&page=1" class="pagination-btn">1</a>'; if ($start_page > 2) echo '<span class="pagination-btn disabled">...</span>'; }
                for ($i = $start_page; $i <= $end_page; $i++) { echo $i==$page ? '<span class="pagination-btn active">'.$i.'</span>' : '<a href="?category='.urlencode($category_filter).'&min_discount='.$min_discount.'&sort='.$sort_by.'&page='.$i.'" class="pagination-btn">'.$i.'</a>'; }
                if ($end_page < $total_pages) { if ($end_page < $total_pages-1) echo '<span class="pagination-btn disabled">...</span>'; echo '<a href="?category='.urlencode($category_filter).'&min_discount='.$min_discount.'&sort='.$sort_by.'&page='.$total_pages.'" class="pagination-btn">'.$total_pages.'</a>'; }
                ?>
                <?php if ($page < $total_pages): ?><a href="?category=<?php echo urlencode($category_filter); ?>&min_discount=<?php echo $min_discount; ?>&sort=<?php echo $sort_by; ?>&page=<?php echo $page+1; ?>" class="pagination-btn"><i class="fas fa-chevron-right"></i></a>
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

        function setDiscount(discount, event) {
            if (event) event.preventDefault();
            document.getElementById('min_discount').value = discount; 
            document.getElementById('filterForm').submit(); 
        }
    </script>
</body>
</html>