<?php


include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
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

$clinic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$clinic_query = mysqli_query($conn, "SELECT * FROM clinics WHERE id = $clinic_id");
$clinic = mysqli_fetch_assoc($clinic_query);

if (!$clinic) {
    header('Location: dashboard.php');
    exit();
}

$products_query = mysqli_query($conn, "
    SELECT p.*,
           (SELECT SUM(quantity) FROM product_color_inventory 
            WHERE product_id = p.id AND clinic_id = p.clinic_id AND quantity > 0 AND is_available = 1) as total_stock,
           (SELECT COUNT(*) FROM product_color_inventory 
            WHERE product_id = p.id AND clinic_id = p.clinic_id AND quantity > 0 AND is_available = 1) as available_colors,
           (SELECT MAX(quantity) FROM product_color_inventory 
            WHERE product_id = p.id AND clinic_id = p.clinic_id AND quantity > 0 AND is_available = 1) as max_available_qty,
           (SELECT i.stock FROM inventory i
            WHERE i.id = p.inventory_id
              AND i.clinic_id = p.clinic_id
              AND i.is_archived = 0
            LIMIT 1) as general_stock
    FROM products p
    WHERE p.clinic_id = $clinic_id
    ORDER BY
        CASE p.category
            WHEN 'Service' THEN 1
            WHEN 'Eyeglasses' THEN 2
            WHEN 'Frames' THEN 3
            WHEN 'Lenses' THEN 4
            WHEN 'Contact Lens' THEN 5
            WHEN 'Sunglasses' THEN 6
            WHEN 'Accessories' THEN 7
            ELSE 8
        END, p.price
");

// ✅ DEBUG CHECK
if (!$products_query) {
    die("
        <div style='background:#fee;border:3px solid #f00;padding:25px;margin:20px;
                    font-family:monospace;font-size:14px;border-radius:10px;'>
            <h2 style='color:#c00;margin:0 0 15px;'>❌ SQL Error</h2>
            <pre style='background:#fff;padding:15px;border-radius:6px;
                        overflow:auto;white-space:pre-wrap;'>" 
                . mysqli_error($conn) . 
            "</pre>
            <p><strong>Clinic ID:</strong> " . $clinic_id . "</p>
        </div>
    ");
}

// ✅ DEBUG CHECK
if (!$products_query) {
    die("
        <div style='background:#fee;border:3px solid #f00;padding:25px;margin:20px;
                    font-family:monospace;font-size:14px;border-radius:10px;'>
            <h2 style='color:#c00;margin:0 0 15px;'>❌ SQL Error Detected</h2>
            <p><strong>Error:</strong></p>
            <pre style='background:#fff;padding:15px;border-radius:6px;
                        overflow:auto;white-space:pre-wrap;'>" 
                . mysqli_error($conn) . 
            "</pre>
            <p><strong>Clinic ID:</strong> " . $clinic_id . "</p>
        </div>
    ");
}

$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

$categories_query = mysqli_query($conn, "SELECT DISTINCT category FROM products WHERE clinic_id = $clinic_id ORDER BY category");

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

$active_nav = 'discover';

// Fetch user's favorited product IDs for this page
$fav_result = mysqli_query($conn, "SELECT product_id FROM favorites WHERE user_id = $user_id AND product_id IS NOT NULL");
$user_favorited_products = [];
while ($frow = mysqli_fetch_assoc($fav_result)) {
    $user_favorited_products[] = (int)$frow['product_id'];
}

// Helper function to get product images
function getProductImages($product) {
    $images = [];
    $defaultImage = '/eyecore/assets/img/no-image.png';
    
    if (!empty($product['images_json'])) {
        $decoded = json_decode($product['images_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $img) {
                $p = str_replace('uploads/uploads/', 'uploads/', $img);
                $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
            }
        }
    }
    
    if (empty($images) && !empty($product['images'])) {
        if (strpos($product['images'], '[') === 0) {
            $decoded = json_decode($product['images'], true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($decoded as $img) {
                    $p = str_replace('uploads/uploads/', 'uploads/', $img);
                    $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                }
            }
        } else {
            $p = str_replace('uploads/uploads/', 'uploads/', $product['images']);
            $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    
    if (empty($images) && !empty($product['image'])) {
        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
            $images[] = '/assets/images/products/' . $product['image'];
        } else {
            $p = str_replace('uploads/uploads/', 'uploads/', $product['image']);
            $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    
    if (empty($images)) {
        $images[] = $defaultImage;
    }
    
    return $images;
}

function isProductOnSale($product) {
    if (empty($product['is_on_sale']) || $product['is_on_sale'] != 1) return false;
    if (empty($product['sale_price']) || $product['sale_price'] <= 0) return false;
    if (!empty($product['sale_end']) && strtotime($product['sale_end']) < strtotime('today')) return false;
    return true;
}

function formatPrice($price) {
    return '₱' . number_format($price, 2);
}

// Store products into array with images
$service_categories = ['Service', 'Eye Exam', 'Treatment', 'Screening'];
$product_categories = ['Eyeglasses', 'Frames', 'Sunglasses', 'Contact Lens', 'Contact Lenses', 'Accessories', 'Lenses'];

$products_list = [];
while ($product = mysqli_fetch_assoc($products_query)) {
    // Check 3D model
    $has_3d = false;
    if (!empty($product['inventory_id'])) {
        $r3d = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT completed_model_file FROM custom_3d_requests
             WHERE inventory_id = {$product['inventory_id']}
             AND status = 'completed'
             AND completed_model_file IS NOT NULL
             ORDER BY completed_at DESC LIMIT 1"
        ));
        if ($r3d && !empty($r3d['completed_model_file'])) $has_3d = true;
    }
    $product['has_3d'] = $has_3d;
    $product['images_array'] = getProductImages($product);
    $products_list[] = $product;
}

include '../includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Products - <?php echo htmlspecialchars($clinic['name']); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; overflow-x: hidden; background: var(--bg-primary); min-height: 100vh; }
        body { font-family: 'Inter', -apple-system, sans-serif; transition: background 0.3s, color 0.3s; }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-hover: 0 20px 40px -10px rgba(0,183,97,0.25);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
            --success: #00B761;
            --info: #3B82F6;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #6B7280;
            --border-color: #2D2D2D;
            --border-light: #262626;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        @media (min-width: 1024px) { .main-content { padding: 30px 40px; } }
        @media (max-width: 768px) { .main-content { padding: 20px 16px 100px; } }

        /* Back button */
        .back-button {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .back-button a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            font-size: 14px;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }
        .back-button a:hover { background: var(--primary); color: white; transform: translateX(-3px); }
        .back-button span { color: var(--text-secondary); font-size: 14px; }

        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 {
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i {
            color: var(--primary);
            background: var(--primary-light);
            width: 46px;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            font-size: 20px;
        }
        .clinic-badge {
            background: var(--bg-secondary);
            padding: 10px 20px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .clinic-badge i { color: var(--primary); }
        .clinic-badge span { font-weight: 600; color: var(--primary); }

        /* Clinic info strip */
        .clinic-info-strip {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 20px 25px;
            margin-bottom: 24px;
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .clinic-strip-icon {
            width: 56px;
            height: 56px;
            background: var(--primary-light);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 26px;
            flex-shrink: 0;
        }
        .clinic-strip-info h2 { font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
        .clinic-strip-info p {
            color: var(--text-secondary);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .clinic-strip-info p i { color: var(--primary); }

        /* Filter tabs */
        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            background: var(--bg-secondary);
            padding: 14px 18px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        .filter-tab {
            padding: 7px 18px;
            background: var(--bg-primary);
            border: 1.5px solid var(--primary);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            font-weight: 500;
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .filter-tab:hover, .filter-tab.active {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        /* ===== PRODUCTS GRID ===== */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        /* Product Card - SAME AS CLINIC-DETAILS */
        .product-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
            position: relative;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        /* Product Image Container - FIXED HEIGHT (same as clinic-details) */
        .product-image-container {
            position: relative;
            width: 100%;
            height: 200px;
            overflow: hidden;
            background: var(--bg-secondary);
            flex-shrink: 0;
        }

        /* Custom slider track */
        .pc-slider-track {
            display: flex;
            width: 100%;
            height: 100%;
            transition: transform 0.4s ease;
            will-change: transform;
        }

        /* Each slide */
        .pc-slide {
            min-width: 100%;
            width: 100%;
            height: 100%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-secondary);
        }

        .pc-slide img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
            display: block;
        }

        /* Dot pagination */
        .pc-dots {
            position: absolute;
            bottom: 6px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 5px;
            z-index: 15;
            pointer-events: none;
        }

        .pc-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: rgba(255,255,255,0.75);
            border: none;
            padding: 0;
            cursor: pointer;
            pointer-events: all;
            transition: all 0.2s;
        }

        .pc-dot.active {
            background: var(--primary);
            width: 14px;
            border-radius: 4px;
        }

        /* Prev / Next arrows inside card */
        .pc-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 26px;
            height: 26px;
            background: rgba(0,0,0,0.40);
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 20;
            opacity: 0;
            transition: opacity 0.2s;
            backdrop-filter: blur(3px);
        }

        .product-image-container:hover .pc-arrow { opacity: 1; }
        .pc-arrow.prev { left: 6px; }
        .pc-arrow.next { right: 6px; }
        .pc-arrow:hover { background: var(--primary); }

        /* Badges */
        .product-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 3px 9px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            z-index: 15;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: var(--shadow-sm);
        }

        .stock-badge { background: var(--success); color: white; }
        .stock-badge.low-stock { background: var(--warning); }
        .out-of-stock-badge { background: var(--danger); color: white; }

        .sale-badge {
            background: linear-gradient(135deg, #FF4D4D, #FF0000);
            color: white;
            top: 8px;
            right: 44px;
            left: auto;
        }

        /* Product Info */
        .product-info {
            padding: 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category {
            font-size: 11px;
            font-weight: 600;
            color: var(--primary);
            text-transform: uppercase;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .product-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-description {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            line-height: 1.5;
            flex-grow: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid var(--border-light);
        }

        .product-price-block {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .product-price {
            font-size: 18px;
            font-weight: 700;
            color: var(--success);
        }

        .product-price.sale-color { color: #EF4444; }
        .product-price-original { font-size: 12px; color: var(--text-muted); text-decoration: line-through; }
        .product-sale-timer { font-size: 10px; color: var(--warning); font-weight: 600; display: flex; align-items: center; gap: 3px; }

        .btn-view-product {
            padding: 7px 14px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-view-product:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        .btn-3d-badge {
            position: absolute;
            bottom: 8px;
            left: 8px;
            padding: 4px 10px;
            background: linear-gradient(135deg, #0EA5E9, #0284C7);
            color: white;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            z-index: 15;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(14,165,233,0.3);
        }
        .btn-3d-badge:hover { transform: scale(1.05); }

        .image-counter {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(0,0,0,0.55);
            color: white;
            padding: 2px 7px;
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 500;
            z-index: 15;
            backdrop-filter: blur(2px);
        }

        /* Favorite heart button */
        .btn-fav-product {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 32px;
            height: 32px;
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
        @keyframes favPop {
            0% { transform: scale(1); }
            50% { transform: scale(1.35); }
            100% { transform: scale(1); }
        }
        .theme-dark .btn-fav-product { background: rgba(30,30,30,0.88); }
        .theme-dark .btn-fav-product i { color: #555; }
        .theme-dark .btn-fav-product.active i { color: #EF4444; }

        /* Empty state */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            background: var(--bg-secondary);
            padding: 60px 30px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        .empty-state i { font-size: 52px; color: var(--text-muted); margin-bottom: 16px; opacity: 0.5; }
        .empty-state h2 { font-size: 20px; color: var(--text-primary); margin-bottom: 8px; }
        .empty-state p { color: var(--text-secondary); margin-bottom: 20px; font-size: 14px; }
        .btn-back-large {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-back-large:hover { transform: translateY(-2px); }

        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 48px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        .no-results i { font-size: 42px; color: var(--text-muted); margin-bottom: 12px; opacity: 0.5; }
        .no-results h3 { font-size: 16px; color: var(--text-primary); margin-bottom: 4px; }
        .no-results p { color: var(--text-secondary); font-size: 13px; }

        /* Toast */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast-notification {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 14px 20px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.10);
            margin-bottom: 10px;
            min-width: 300px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--success);
        }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }
        .toast-notification i { font-size: 18px; }
        .toast-notification.success i { color: var(--success); }
        .toast-notification.error i { color: var(--danger); }
        .toast-notification.info i { color: var(--info); }
        .toast-notification span { flex: 1; font-size: 13px; color: var(--text-primary); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

        @media (max-width: 992px) { .products-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; } }
        @media (max-width: 600px) { .products-grid { grid-template-columns: 1fr; gap: 16px; } }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-box"></i> Products & Services</h1>
        <div class="clinic-badge">
            <i class="fas fa-clinic-medical"></i>
            <span><?php echo htmlspecialchars($clinic['name']); ?></span>
        </div>
    </div>

    <!-- Back Button -->
    <div class="back-button">
        <a href="clinic-details.php?id=<?php echo $clinic_id; ?>">
            <i class="fas fa-arrow-left"></i> Back to Clinic Details
        </a>
        <span>All Products</span>
    </div>

    <!-- Clinic Info Strip -->
    <div class="clinic-info-strip">
        <div class="clinic-strip-icon"><i class="fas fa-eye"></i></div>
        <div class="clinic-strip-info">
            <h2><?php echo htmlspecialchars($clinic['name']); ?></h2>
            <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($clinic['address']); ?>, <?php echo htmlspecialchars($clinic['city']); ?></p>
            <p style="margin-top:2px;"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($clinic['contact']); ?></p>
        </div>
    </div>

    <!-- Filter Tabs -->
    <?php if (mysqli_num_rows($categories_query) > 0): ?>
    <div class="filter-tabs" id="filterTabs">
        <button class="filter-tab active" onclick="filterByCategory('all', this)">
            <i class="fas fa-list"></i> All
        </button>
        <?php mysqli_data_seek($categories_query, 0);
        while ($cat = mysqli_fetch_assoc($categories_query)): ?>
            <button class="filter-tab" onclick="filterByCategory('<?php echo htmlspecialchars($cat['category']); ?>', this)">
                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($cat['category']); ?>
            </button>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

<!-- Products Grid -->
    <div class="products-grid" id="productsGrid">
        <?php if (!empty($products_list)): ?>
            <?php foreach ($products_list as $product):
            
                $pid = $product['id'];
                
$is_service = in_array($product['category'], $service_categories);
$is_product = in_array($product['category'], $product_categories);

$total_stock = (int)($product['total_stock'] ?? 0);
$max_qty = (int)($product['max_available_qty'] ?? 0);
$avail_colors = (int)($product['available_colors'] ?? 0);
$general_stock = $product['general_stock'] !== null ? (int)$product['general_stock'] : null;

$has_colors = ($avail_colors > 0);

// ✅ Determine stock value with fallback
if ($has_colors) {
    $stock_value = $total_stock;
    
    // ✅ Fallback para sa Contact Lenses at iba pang may colors pero walang product_color_inventory
    if ($stock_value == 0 && $general_stock !== null && $general_stock > 0) {
        $stock_value = $general_stock;
    }
} else {
    $stock_value = $general_stock;
}

$is_out = $is_product && $stock_value !== null && $stock_value == 0;
$is_low = $is_product && !$is_out && $stock_value !== null && $stock_value > 0 && $stock_value <= 5;

                // ✅ Sale logic
                $is_on_sale = isProductOnSale($product);
                $sale_price = $is_on_sale ? (float)$product['sale_price'] : 0;
                $orig_price = (float)$product['price'];
                $display_price = $is_on_sale ? $sale_price : $orig_price;
                $discount_pct = $is_on_sale ? round((($orig_price - $sale_price) / $orig_price) * 100) : 0;
                $days_left = $is_on_sale && !empty($product['sale_end']) 
                    ? (int)ceil((strtotime($product['sale_end']) - strtotime('today')) / 86400) : 0;

                $product_images = $product['images_array'];
                $img_count = count($product_images);
            ?>

            <div class="product-card" onclick="window.location.href='product-view.php?id=<?php echo $pid; ?>'">

                <!-- Image Container with custom slider -->
                <div class="product-image-container" id="pic-<?php echo $pid; ?>">

                    <!-- Favorite heart button -->
                    <?php $is_fav = in_array($pid, $user_favorited_products); ?>
                    <button class="btn-fav-product <?php echo $is_fav ? 'active' : ''; ?>"
                            data-product-id="<?php echo $pid; ?>"
                            onclick="toggleProductFav(this, event)"
                            title="<?php echo $is_fav ? 'Remove from favorites' : 'Add to favorites'; ?>">
                        <i class="fa<?php echo $is_fav ? 's' : 'r'; ?> fa-heart"></i>
                    </button>

                    <!-- Slider track -->
                    <div class="pc-slider-track" id="track-<?php echo $pid; ?>">
                        <?php foreach ($product_images as $imgUrl): ?>
                        <div class="pc-slide">
                            <img src="<?php echo $imgUrl; ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 onerror="this.onerror=null;this.src='/eyecore/assets/img/no-image.png'">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Dots (only if multiple images) -->
                    <?php if ($img_count > 1): ?>
                    <div class="pc-dots" id="dots-<?php echo $pid; ?>">
                        <?php for ($di = 0; $di < $img_count; $di++): ?>
                        <button class="pc-dot <?php echo $di === 0 ? 'active' : ''; ?>"
                                onclick="pcGo(<?php echo $pid; ?>, <?php echo $di; ?>, event)">
                        </button>
                        <?php endfor; ?>
                    </div>
                    <!-- Arrows -->
                    <button class="pc-arrow prev" onclick="pcGo(<?php echo $pid; ?>, pcState[<?php echo $pid; ?>]-1, event)">&#8249;</button>
                    <button class="pc-arrow next" onclick="pcGo(<?php echo $pid; ?>, pcState[<?php echo $pid; ?>]+1, event)">&#8250;</button>
                    <?php endif; ?>

                    <!-- Sale badge -->
                    <?php if ($is_on_sale): ?>
                    <div class="product-badge sale-badge">
                        <i class="fas fa-fire"></i> -<?php echo $discount_pct; ?>%
                    </div>
                    <?php endif; ?>

                    <!-- Stock badges -->
                    <?php if ($is_out && $is_product): ?>
                    <div class="product-badge out-of-stock-badge">
                        <i class="fas fa-times-circle"></i> Out of Stock
                    </div>
                    <?php elseif ($is_low): ?>
                    <div class="product-badge stock-badge low-stock">
                        <i class="fas fa-exclamation-triangle"></i> Only <?php echo $stock_value; ?> left!
                    </div>
                    <?php elseif (!$is_out && $is_product && $stock_value !== null && $stock_value > 0): ?>
                    <div class="product-badge stock-badge">
                        <i class="fas fa-box"></i> <?php echo $stock_value; ?> left
                        <?php if ($avail_colors > 1): ?>(<?php echo $avail_colors; ?> colors)<?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 3D badge -->
                    <?php if ($product['has_3d']): ?>
                    <a href="3d-view.php?id=<?php echo $pid; ?>" class="btn-3d-badge" onclick="event.stopPropagation()">
                        <i class="fas fa-cube"></i> 3D View
                    </a>
                    <?php endif; ?>

                    <!-- Image counter -->
                    <?php if ($img_count > 1): ?>
                    <div class="image-counter"><i class="fas fa-images"></i> <?php echo $img_count; ?></div>
                    <?php endif; ?>
                </div><!-- /product-image-container -->

                <!-- Product Info -->
                <div class="product-info">
                    <div class="product-category"><?php echo htmlspecialchars($product['category']); ?></div>
                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <div class="product-description">
                        <?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 80)); ?>
                        <?php if (strlen($product['description'] ?? '') > 80): ?>...<?php endif; ?>
                    </div>
                    <div class="product-footer">
                        <div class="product-price-block">
                            <?php if ($is_on_sale): ?>
                                <div class="product-price sale-color"><?php echo formatPrice($sale_price); ?></div>
                                <div class="product-price-original"><?php echo formatPrice($orig_price); ?></div>
                                <?php if ($days_left > 0 && $days_left <= 7): ?>
                                <div class="product-sale-timer">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $days_left == 1 ? 'Ends tomorrow!' : $days_left . ' days left'; ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="product-price"><?php echo formatPrice($orig_price); ?></div>
                            <?php endif; ?>
                        </div>
                        <a href="product-view.php?id=<?php echo $pid; ?>" class="btn-view-product" onclick="event.stopPropagation()">
                            View <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div><!-- /product-card -->

            <?php endforeach; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h2>No products found</h2>
                <p>This clinic hasn't listed any products or services yet.</p>
                <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-back-large">
                    <i class="fas fa-arrow-left"></i> Back to Clinic
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Product card custom slider state
const pcState = {};
const pcTotal = <?php 
    $totals = [];
    foreach ($products_list as $product) {
        $totals[$product['id']] = count($product['images_array']);
    }
    echo json_encode($totals);
?>;

// Initialize state
Object.keys(pcTotal).forEach(pid => { pcState[pid] = 0; });

function pcGo(pid, index, event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const total = pcTotal[pid] || 1;
    index = ((index % total) + total) % total;
    pcState[pid] = index;

    const track = document.getElementById('track-' + pid);
    if (track) track.style.transform = 'translateX(' + (-index * 100) + '%)';

    const dotsEl = document.getElementById('dots-' + pid);
    if (dotsEl) {
        dotsEl.querySelectorAll('.pc-dot').forEach((d, i) => {
            d.classList.toggle('active', i === index);
        });
    }
}

// Category filter
function filterByCategory(category, el) {
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');

    let visible = 0;
    document.querySelectorAll('.product-card').forEach(card => {
        const cardCategory = card.querySelector('.product-category')?.innerText;
        const match = category === 'all' || cardCategory === category;
        card.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    });

    const existing = document.querySelector('.no-results');
    if (existing) existing.remove();

    if (visible === 0 && category !== 'all') {
        const msg = document.createElement('div');
        msg.className = 'no-results';
        msg.innerHTML = `
            <i class="fas fa-filter"></i>
            <h3>No ${category} found</h3>
            <p>Try selecting another category</p>
        `;
        document.getElementById('productsGrid').appendChild(msg);
    }
}

// Toast
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast-notification ' + type;
    const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
}

// Product favorite toggle
function toggleProductFav(btn, event) {
    event.preventDefault();
    event.stopPropagation();

    const productId = btn.dataset.productId;
    const isActive = btn.classList.contains('active');
    const formData = new FormData();
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
                showToast('Something went wrong. Please try again.', 'error');
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