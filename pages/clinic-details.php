<?php

include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get user avatar and created_at for sidebar
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Get clinic ID from URL
$clinic_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get the referring page
$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

// Determine back URL based on referrer
$back_url = 'dashboard.php';
$back_text = 'Back to Clinics';

if (!empty($referrer)) {
    if (strpos($referrer, 'favorites.php') !== false) {
        $back_url = 'favorites.php';
        $back_text = 'Back to Favorites';
    } elseif (strpos($referrer, 'clinic-products.php') !== false) {
        $back_url = 'clinic-products.php?id=' . $clinic_id;
        $back_text = 'Back to Products';
    } elseif (strpos($referrer, 'clinics-map.php') !== false) {
        $back_url = 'clinics-map.php';
        $back_text = 'Back to Map';
    } elseif (strpos($referrer, 'nearby.php') !== false) {
        $back_url = 'nearby.php';
        $back_text = 'Back to Nearby Clinics';
    } elseif (strpos($referrer, 'dashboard.php') !== false) {
        $back_url = 'dashboard.php';
        $back_text = 'Back to Clinics';
    } else {
        $back_url = 'dashboard.php';
        $back_text = 'Back to Clinics';
    }
}

unset($_SESSION['last_page']);
unset($_SESSION['last_page_text']);

// Get clinic details
$clinic_query = mysqli_query($conn, "SELECT * FROM clinics WHERE id = $clinic_id");
$clinic = mysqli_fetch_assoc($clinic_query);

if (!$clinic) {
    header('Location: dashboard.php');
    exit();
}

// Get clinic gallery images
$gallery_query = mysqli_query($conn, "
    SELECT * FROM clinic_gallery 
    WHERE clinic_id = $clinic_id 
    ORDER BY sort_order ASC, is_primary DESC
");

$gallery_images = [];
$has_gallery = false;
while($img = mysqli_fetch_assoc($gallery_query)) {
    $gallery_images[] = $img;
    $has_gallery = true;
}

// Get clinic products with proper stock information from product_color_inventory
$products_query = mysqli_query($conn, "
    SELECT p.*, 
           COALESCE((
               SELECT SUM(quantity) 
               FROM product_color_inventory 
               WHERE product_id = p.id 
               AND clinic_id = p.clinic_id 
               AND quantity > 0 
               AND is_available = 1
           ), 0) as total_stock,
           COALESCE((
               SELECT COUNT(*) 
               FROM product_color_inventory 
               WHERE product_id = p.id 
               AND clinic_id = p.clinic_id 
               AND quantity > 0 
               AND is_available = 1
           ), 0) as available_colors,
           COALESCE((
               SELECT MAX(quantity) 
               FROM product_color_inventory 
               WHERE product_id = p.id 
               AND clinic_id = p.clinic_id 
               AND quantity > 0 
               AND is_available = 1
           ), 0) as max_available_qty,
           p3d.has_3d
    FROM products p 
    LEFT JOIN product_3d_models p3d ON p.id = p3d.product_id
    WHERE p.clinic_id = $clinic_id 
    AND p.approval_status = 'approved'
    ORDER BY 
        CASE p.category 
            WHEN 'Service' THEN 1 
            WHEN 'Eyeglasses' THEN 2 
            WHEN 'Frames' THEN 3 
            WHEN 'Lenses' THEN 4 
            WHEN 'Contact Lenses' THEN 5 
            WHEN 'Sunglasses' THEN 6 
            WHEN 'Accessories' THEN 7 
            ELSE 8 
        END, p.price
");

// Get total products count
$total_products_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE clinic_id = $clinic_id AND approval_status = 'approved'");
$total_products = mysqli_fetch_assoc($total_products_query)['total'];

// Count pending appointments for navbar badge
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

// Check if clinic is in favorites
$favorite_check = mysqli_query($conn, "SELECT * FROM favorites WHERE user_id = $user_id AND clinic_id = $clinic_id");
$is_favorite = mysqli_num_rows($favorite_check) > 0;

// Fetch user's favorited product IDs
$fav_result = mysqli_query($conn, "SELECT product_id FROM favorites WHERE user_id = $user_id AND product_id IS NOT NULL");
$user_favorited_products = [];
while ($frow = mysqli_fetch_assoc($fav_result)) {
    $user_favorited_products[] = (int)$frow['product_id'];
}

// ============================================
// CLINIC REVIEWS SECTION
// ============================================
$reviews_query = mysqli_query($conn, "
    SELECT r.*, u.fullname, u.avatar 
    FROM clinic_reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.clinic_id = $clinic_id
    ORDER BY r.created_at DESC
");

$avg_query = mysqli_query($conn, "
    SELECT AVG(rating) as avg_rating, COUNT(*) as total 
    FROM clinic_reviews 
    WHERE clinic_id = $clinic_id
");
$rating_stats = mysqli_fetch_assoc($avg_query);
$avg_rating = round($rating_stats['avg_rating'] ?? 0, 1);
$total_reviews = $rating_stats['total'] ?? 0;

$dist_query = mysqli_query($conn, "
    SELECT rating, COUNT(*) as count 
    FROM clinic_reviews 
    WHERE clinic_id = $clinic_id 
    GROUP BY rating 
    ORDER BY rating DESC
");
$distribution = [];
$total = 0;
while($row = mysqli_fetch_assoc($dist_query)) {
    $distribution[$row['rating']] = $row['count'];
    $total += $row['count'];
}
if ($total == 0) $total = 1;

// ============================================
// NOTIFICATION VARIABLES
// ============================================
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$active_nav = '';

$reservation_query = mysqli_query($conn, "
    SELECT COUNT(*) as total 
    FROM reservations 
    WHERE user_id = $user_id 
    AND status IN ('pending', 'confirmed')
");
$reservation_row = mysqli_fetch_assoc($reservation_query);
$reservation_count = $reservation_row['total'] ?? 0;

// Helper function to get product images
function getProductImages($product) {
    $images = [];
    $defaultImage = '/assets/img/no-image.png';
    
    if (!empty($product['images_json'])) {
        $decoded = json_decode($product['images_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $img) {
                $images[] = resolveImageUrl($img);
            }
        }
    }
    
    if (empty($images) && !empty($product['images'])) {
        if (strpos($product['images'], '[') === 0) {
            $decoded = json_decode($product['images'], true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($decoded as $img) {
                    $images[] = resolveImageUrl($img);
                }
            }
        } else {
            $images[] = resolveImageUrl($product['images']);
        }
    }
    
    if (empty($images) && !empty($product['image'])) {
        $images[] = resolveImageUrl($product['image']);
    }
    
    if (empty($images)) {
        $images[] = $defaultImage;
    }
    
    return $images;
}

function resolveImageUrl($path) {
    if (empty($path)) return '/assets/img/no-image.png';
    if (strpos($path, 'http') === 0 || strpos($path, '//') === 0) return $path;
    $path = str_replace('uploads/uploads/', 'uploads/', $path);
    $path = str_replace('uploads//uploads/', 'uploads/', $path);
    if (strpos($path, '/') === 0) return $path;
    if (strpos($path, 'uploads/') === 0) return '/' . $path;
    return '/uploads/products/' . ltrim($path, '/');
}

function formatPrice($price) {
    return '₱' . number_format($price, 2);
}

function isProductOnSale($product) {
    if (empty($product['is_on_sale']) || $product['is_on_sale'] != 1) return false;
    if (empty($product['sale_price']) || $product['sale_price'] <= 0) return false;
    if (!empty($product['sale_end']) && strtotime($product['sale_end']) < strtotime('today')) return false;
    return true;
}

// Pre-fetch all product images into a PHP array for JS use
$all_product_images_js = [];
mysqli_data_seek($products_query, 0);
while ($p = mysqli_fetch_assoc($products_query)) {
    $all_product_images_js[$p['id']] = getProductImages($p);
}
mysqli_data_seek($products_query, 0);
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($clinic['name']); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <?php include '../includes/navbar.php'; ?>
    
    <style>
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
            --primary-dark: #00874A;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761, #00A86B);
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
            --success: #00B761;
            --info: #3B82F6;
            --rating-bg: #ffc107;
            --rating-color: #333;
            --rating-empty: #E5E7EB;
            --carousel-dot: rgba(255,255,255,0.5);
            --carousel-dot-active: #00B761;
            --carousel-control: rgba(0,0,0,0.3);
            --carousel-control-hover: #00B761;
            --icon-bg: var(--primary-light);
            --icon-color: var(--primary);
            --favorite-bg: white;
            --favorite-color: var(--primary);
            --favorite-border: var(--primary);
            --favorite-active-bg: var(--primary-gradient);
            --favorite-active-color: white;
            --review-bg: var(--bg-primary);
            --review-border: var(--border-light);
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
            --rating-bg: #ffc107;
            --rating-color: #333;
            --rating-empty: #404040;
            --carousel-dot: rgba(255,255,255,0.3);
            --carousel-dot-active: #00E676;
            --carousel-control: rgba(0,0,0,0.5);
            --carousel-control-hover: #00E676;
            --icon-bg: #1E3A2E;
            --icon-color: #00E676;
            --favorite-bg: #242424;
            --favorite-color: #00E676;
            --favorite-border: #00E676;
            --review-bg: #1E1E1E;
            --review-border: #2D2D2D;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) { .main-content { padding: 30px 40px; } }
        @media (max-width: 768px) { .main-content { padding: 20px 16px 100px; } }

        /* Back Button */
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
            transition: all 0.2s;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
        }

        .back-button a:hover { background: var(--primary); color: white; transform: translateX(-3px); }
        .back-button span { color: var(--text-secondary); font-size: 14px; }

        /* Clinic Cover */
        .clinic-cover {
            width: 100%;
            height: 300px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            overflow: hidden;
            position: relative;
            margin-bottom: -70px;
        }

        .clinic-cover img { width: 100%; height: 100%; object-fit: cover; }

        .cover-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .cover-placeholder i { font-size: 80px; margin-bottom: 15px; opacity: 0.7; }
        .cover-placeholder span { font-size: 18px; font-weight: 500; opacity: 0.8; }

        /* Clinic Profile */
        .clinic-profile-section {
            display: flex;
            gap: 30px;
            padding: 20px 30px;
            position: relative;
            z-index: 2;
            margin-bottom: 30px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-md);
            flex-wrap: wrap;
        }

        .clinic-logo {
            width: 140px;
            height: 140px;
            border-radius: var(--radius-lg);
            border: 4px solid var(--bg-secondary);
            background: white;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-top: -70px;
            flex-shrink: 0;
        }

        .clinic-logo img { width: 100%; height: 100%; object-fit: cover; }

        .logo-placeholder {
            width: 100%;
            height: 100%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 50px;
        }

        .clinic-info-header { flex: 1; padding-top: 10px; }
        .clinic-info-header h1 { font-size: 32px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; }

        .clinic-rating {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .rating-score {
            background: var(--rating-bg);
            color: var(--rating-color);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 16px;
        }

        .rating-stars { display: flex; gap: 3px; }
        .rating-stars i { color: var(--rating-bg); font-size: 16px; }
        .rating-stars i.empty { color: var(--rating-empty); }
        .rating-count { color: var(--text-secondary); font-size: 14px; }

        .clinic-actions { display: flex; gap: 15px; margin-top: 10px; flex-wrap: wrap; }

        .btn-favorite {
            padding: 12px 25px;
            border: 2px solid var(--favorite-border);
            background: <?php echo $is_favorite ? 'var(--favorite-active-bg)' : 'var(--favorite-bg)'; ?>;
            color: <?php echo $is_favorite ? 'var(--favorite-active-color)' : 'var(--favorite-color)'; ?>;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-favorite:hover { background: var(--primary-gradient); color: white; transform: translateY(-2px); box-shadow: var(--shadow-md); }

        .btn-appointment {
            padding: 12px 25px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-appointment:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* Gallery */
        .gallery-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .gallery-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .gallery-header h3 { font-size: 18px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .gallery-header h3 i { color: var(--primary); }
        .image-count { background: var(--primary-light); color: var(--primary); padding: 4px 12px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }

        /* Carousel */
        .carousel-container { position: relative; width: 100%; border-radius: var(--radius-md); overflow: hidden; }
        .carousel { position: relative; width: 100%; }
        .carousel-main { position: relative; width: 100%; height: 450px; overflow: hidden; }

        .carousel-slide {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            visibility: hidden;
        }

        .carousel-slide.active { opacity: 1; visibility: visible; }
        .carousel-slide img { width: 100%; height: 100%; object-fit: cover; }

        .carousel-caption {
            position: absolute;
            bottom: 20px; left: 20px; right: 20px;
            background: rgba(0,0,0,0.6);
            color: white;
            padding: 10px 15px;
            border-radius: var(--radius-md);
            font-size: 14px;
            backdrop-filter: blur(5px);
        }

        .carousel-control {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 45px; height: 45px;
            background: var(--carousel-control);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            transition: all 0.3s;
            z-index: 10;
            backdrop-filter: blur(5px);
        }

        .carousel-control:hover { background: var(--carousel-control-hover); transform: translateY(-50%) scale(1.1); }
        .carousel-control.prev { left: 20px; }
        .carousel-control.next { right: 20px; }

        .carousel-dots {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 10;
            background: rgba(0,0,0,0.3);
            padding: 10px 20px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(5px);
        }

        .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--carousel-dot); cursor: pointer; transition: all 0.3s; }
        .dot:hover { background: white; transform: scale(1.2); }
        .dot.active { background: var(--carousel-dot-active); width: 25px; border-radius: 10px; }

        /* Clinic Info Grid */
        .clinic-info-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }

        .info-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .info-card h2 { font-size: 20px; font-weight: 600; color: var(--text-primary); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .info-card h2 i { color: var(--primary); }

        .info-detail { display: flex; gap: 15px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--border-light); }
        .info-detail:last-child { border-bottom: none; padding-bottom: 0; }

        .info-icon { width: 45px; height: 45px; background: var(--icon-bg); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--icon-color); font-size: 18px; flex-shrink: 0; }
        .info-content h3 { font-size: 14px; font-weight: 500; color: var(--text-secondary); margin-bottom: 5px; }
        .info-content p { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .info-content a { color: var(--primary); text-decoration: none; }
        .info-content a:hover { text-decoration: underline; }
        .clinic-description { color: var(--text-secondary); line-height: 1.7; font-size: 15px; }

        /* ===== PRODUCTS GRID ===== */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        /* Product Card */
        .product-card {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
            position: relative;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        /* =============================================
           PRODUCT IMAGE — Fixed height, no Swiper
           ============================================= */
        .product-image-container {
            position: relative;
            width: 100%;
            height: 200px;           /* fixed, no more padding-top trick */
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
            object-fit: contain;   /* show full image, no crop */
            padding: 8px;
            display: block;
        }

        /* Dot pagination */
        .pc-dots {
            position: absolute;
            bottom: 6px;
            left: 0; right: 0;
            display: flex;
            justify-content: center;
            gap: 5px;
            z-index: 15;
            pointer-events: none;
        }

        .pc-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: rgba(255,255,255,0.75);
            border: none; padding: 0;
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
            width: 26px; height: 26px;
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

        /* Sale price styles */
        .product-price-block { display: flex; flex-direction: column; gap: 2px; }
        .product-price.sale-color { color: #EF4444; }
        .product-price-original { font-size: 12px; color: var(--text-muted); text-decoration: line-through; }
        .product-sale-timer { font-size: 10px; color: var(--warning); font-weight: 600; display: flex; align-items: center; gap: 3px; }

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
        }

        .product-description {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 10px;
            line-height: 1.5;
            flex-grow: 1;
        }

        .product-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid var(--border-light);
        }

        .product-price {
            font-size: 19px;
            font-weight: 700;
            color: var(--success);
        }

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

        .view-all-link {
            display: inline-block;
            padding: 10px 25px;
            background: var(--primary-gradient);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            transition: all 0.2s;
            font-size: 13px;
            margin-top: 15px;
        }
        .view-all-link:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* Sidebar */
        .sidebar-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .sidebar-card h2 { font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .sidebar-card h2 i { color: var(--primary); }

        .hours-row { display: flex; align-items: baseline; padding: 8px 0; border-bottom: 1px solid var(--border-color); }
        .hours-row:last-child { border-bottom: none; }
        .days-label, .time-label { width: 70px; font-weight: 600; color: var(--text-muted); font-size: 14px; }
        .days-value, .time-value { flex: 1; font-weight: 500; color: var(--text-primary); }

        .open-now-indicator { margin-top: 15px; padding: 10px; background: var(--primary-light); border-radius: 8px; font-size: 13px; color: var(--primary); display: flex; align-items: center; gap: 8px; }

        .map-container { margin-bottom: 15px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-light); }
        .map-container iframe { width: 100%; height: 200px; border: 0; display: block; }

        .address-box { background: var(--bg-primary); border-radius: var(--radius-md); padding: 15px; margin-bottom: 15px; }
        .address-box p { color: var(--text-secondary); margin-bottom: 10px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .address-box i { color: var(--primary); }

        .directions-btn { display: block; background: var(--primary-gradient); color: white; padding: 12px 15px; border-radius: var(--radius-md); text-decoration: none; font-size: 14px; font-weight: 600; text-align: center; transition: all 0.2s; }
        .directions-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* Reviews */
        .average-rating-large { text-align: center; margin-bottom: 20px; }
        .average-rating-large .big-rating { font-size: 48px; font-weight: 700; color: var(--rating-bg); line-height: 1; }
        .average-rating-large .rating-stars { justify-content: center; margin: 5px 0; }
        .average-rating-large .total-reviews { color: var(--text-secondary); font-size: 13px; }

        .rating-bar-item { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .rating-bar-item .star-label { width: 30px; color: var(--text-secondary); font-size: 13px; }
        .rating-bar-item .bar-container { flex: 1; height: 8px; background: var(--border-light); border-radius: 4px; overflow: hidden; }
        .rating-bar-item .bar-fill { height: 100%; background: var(--rating-bg); border-radius: 4px; }
        .rating-bar-item .percent { width: 40px; color: var(--text-secondary); font-size: 13px; text-align: right; }

        .reviews-list { max-height: 500px; overflow-y: auto; padding-right: 5px; }
        .reviews-list::-webkit-scrollbar { width: 5px; }
        .reviews-list::-webkit-scrollbar-track { background: var(--border-light); border-radius: 10px; }
        .reviews-list::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }

        .review-item { background: var(--review-bg); border-radius: var(--radius-md); padding: 20px; margin-bottom: 15px; border: 1px solid var(--review-border); }
        .review-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }

        .reviewer-avatar { width: 45px; height: 45px; border-radius: 50%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; overflow: hidden; }
        .reviewer-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .reviewer-name { font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
        .review-date { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 3px; }

        .review-rating { display: flex; gap: 3px; margin: 8px 0; }
        .review-rating i { color: var(--rating-bg); font-size: 13px; }
        .review-rating i.empty { color: var(--rating-empty); }
        .review-text { color: var(--text-secondary); font-size: 13px; line-height: 1.6; margin: 8px 0; }

        .recommend-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; background: var(--success); color: white; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; }

        .no-reviews { text-align: center; padding: 40px 20px; background: var(--review-bg); border-radius: var(--radius-md); color: var(--text-muted); }
        .no-reviews i { font-size: 48px; margin-bottom: 10px; opacity: 0.5; }
        .no-reviews p { color: var(--text-secondary); font-size: 14px; }

        /* Toast */
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

        /* Loading */
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999; }
        .loading-spinner-large { width: 50px; height: 50px; border: 4px solid var(--primary-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Responsive */
        @media (max-width: 992px) {
            .clinic-info-grid { grid-template-columns: 1fr; }
            .carousel-main { height: 350px; }
        }

        @media (max-width: 768px) {
            .clinic-cover { height: 200px; margin-bottom: -50px; }
            .clinic-profile-section { flex-direction: column; align-items: center; text-align: center; padding: 15px; }
            .clinic-logo { margin-top: -70px; width: 120px; height: 120px; }
            .clinic-info-header h1 { font-size: 24px; }
            .clinic-actions { flex-direction: column; }
            .btn-favorite, .btn-appointment { width: 100%; justify-content: center; }
            .products-grid { grid-template-columns: 1fr 1fr; }
            .carousel-main { height: 250px; }
            .carousel-control { width: 35px; height: 35px; font-size: 16px; }
            .carousel-dots { bottom: 10px; padding: 6px 12px; }
            .product-image-container { height: 160px; }
        }

        @media (max-width: 480px) {
            .products-grid { grid-template-columns: 1fr; }
        }

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
        @keyframes favPop { 0% { transform: scale(1); } 50% { transform: scale(1.35); } 100% { transform: scale(1); } }
        .theme-dark .btn-fav-product { background: rgba(30,30,30,0.88); }
        .theme-dark .btn-fav-product i { color: #555; }
        .theme-dark .btn-fav-product.active i { color: #EF4444; }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <div class="main-content">
        <!-- Back Button -->
        <div class="back-button">
            <a href="<?php echo $back_url; ?>">
                <i class="fas fa-arrow-left"></i> <?php echo $back_text; ?>
            </a>
            <span>Clinic Details</span>
        </div>

        <!-- Clinic Cover -->
        <div class="clinic-cover">
            <?php if (!empty($clinic['cover_photo'])): ?>
                <img src="../assets/images/clinic-covers/<?php echo $clinic['cover_photo']; ?>" alt="<?php echo htmlspecialchars($clinic['name']); ?> Cover">
            <?php else: ?>
                <div class="cover-placeholder">
                    <i class="fas fa-image"></i>
                    <span>Clinic Cover</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Clinic Profile -->
        <div class="clinic-profile-section">
            <div class="clinic-logo">
                <?php if (!empty($clinic['logo'])): ?>
                    <img src="../assets/images/clinic-logos/<?php echo $clinic['logo']; ?>" alt="<?php echo htmlspecialchars($clinic['name']); ?>">
                <?php else: ?>
                    <div class="logo-placeholder"><i class="fas fa-clinic-medical"></i></div>
                <?php endif; ?>
            </div>
            
            <div class="clinic-info-header">
                <h1><?php echo htmlspecialchars($clinic['name']); ?></h1>
                
                <div class="clinic-rating">
                    <span class="rating-score"><?php echo number_format($avg_rating, 1); ?></span>
                    <div class="rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): 
                            if ($i <= round($avg_rating)) echo '<i class="fas fa-star"></i>';
                            elseif ($i - 0.5 <= $avg_rating) echo '<i class="fas fa-star-half-alt"></i>';
                            else echo '<i class="fas fa-star empty"></i>';
                        endfor; ?>
                    </div>
                    <span class="rating-count"><?php echo $total_reviews; ?> reviews</span>
                </div>

                <div class="clinic-actions">
                    <button class="btn-favorite" id="favoriteBtn" data-clinic-id="<?php echo $clinic_id; ?>">
                        <i class="fa<?php echo $is_favorite ? 's' : 'r'; ?> fa-heart"></i>
                        <span><?php echo $is_favorite ? 'Saved to Favorites' : 'Save to Favorites'; ?></span>
                    </button>
                    <a href="book-appointment.php?clinic_id=<?php echo $clinic_id; ?>" class="btn-appointment">
                        <i class="fas fa-calendar-plus"></i> Book Appointment
                    </a>
                </div>
            </div>
        </div>

        <!-- Gallery -->
        <?php if ($has_gallery): ?>
        <div class="gallery-section">
            <div class="gallery-header">
                <h3><i class="fas fa-images"></i> Clinic Gallery</h3>
                <span class="image-count"><?php echo count($gallery_images); ?> photos</span>
            </div>
            <div class="carousel-container">
                <div class="carousel">
                    <div class="carousel-main" id="clinicCarousel">
                        <?php foreach ($gallery_images as $index => $image): ?>
                            <div class="carousel-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                                <img src="../assets/images/clinic-gallery/<?php echo $image['image_path']; ?>" alt="<?php echo $image['caption'] ?? 'Clinic Photo'; ?>">
                                <?php if (!empty($image['caption'])): ?>
                                    <div class="carousel-caption"><?php echo htmlspecialchars($image['caption']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="carousel-control prev" onclick="prevSlide()"><i class="fas fa-chevron-left"></i></button>
                    <button class="carousel-control next" onclick="nextSlide()"><i class="fas fa-chevron-right"></i></button>
                    <div class="carousel-dots">
                        <?php foreach ($gallery_images as $index => $image): ?>
                            <span class="dot <?php echo $index === 0 ? 'active' : ''; ?>" onclick="goToGallerySlide(<?php echo $index; ?>)"></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="clinic-info-grid">
            <!-- LEFT COLUMN -->
            <div class="clinic-main-info">
                <!-- About -->
                <div class="info-card">
                    <h2><i class="fas fa-info-circle"></i> About the Clinic</h2>
                    <p class="clinic-description"><?php echo nl2br(htmlspecialchars($clinic['description'] ?? 'No description available.')); ?></p>
                </div>

                <!-- Contact -->
                <div class="info-card">
                    <h2><i class="fas fa-address-card"></i> Contact Information</h2>
                    <div class="info-detail">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="info-content">
                            <h3>Address</h3>
                            <p><?php echo htmlspecialchars($clinic['address'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($clinic['city'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                    <div class="info-detail">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div class="info-content">
                            <h3>Contact Number</h3>
                            <p><a href="tel:<?php echo $clinic['contact']; ?>"><?php echo htmlspecialchars($clinic['contact'] ?? 'N/A'); ?></a></p>
                        </div>
                    </div>
                    <div class="info-detail">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div class="info-content">
                            <h3>Business Hours</h3>
                            <p><?php echo htmlspecialchars($clinic['hours'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- PRODUCTS & SERVICES -->
                <div class="info-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin-bottom:0;"><i class="fas fa-box"></i> Products & Services</h2>
                        <?php if ($total_products > 4): ?>
                            <a href="clinic-products.php?id=<?php echo $clinic_id; ?>" style="color: var(--primary); text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 5px; font-size: 13px;">
                                View All (<?php echo $total_products; ?>) <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if (mysqli_num_rows($products_query) > 0): ?>
                        <?php
                        mysqli_data_seek($products_query, 0);
                        $display_products = 0;
                        ?>
                        <div class="products-grid">
                        <?php while($product = mysqli_fetch_assoc($products_query)):
                            if ($display_products >= 4) break;
                            $display_products++;

                            $is_service = in_array($product['category'], ['Service', 'Eye Exam', 'Treatment', 'Screening']);
                            $is_product = in_array($product['category'], ['Eyeglasses', 'Frames', 'Sunglasses', 'Contact Lenses', 'Lenses', 'Accessories']);

                            $total_stock    = (int)($product['total_stock'] ?? 0);
                            $available_colors = (int)($product['available_colors'] ?? 0);
                            $max_qty        = (int)($product['max_available_qty'] ?? 0);

                            $is_out_of_stock = $is_product && ($total_stock == 0 || $available_colors == 0);
                            $is_low_stock    = $is_product && !$is_out_of_stock && $max_qty <= 5 && $max_qty > 0;
                            $has_3d          = !empty($product['has_3d']) || !empty($product['model_file']);

                            $product_images   = getProductImages($product);
                            $img_count        = count($product_images);

                            $is_on_sale       = isProductOnSale($product);
                            $sale_price       = $is_on_sale ? (float)$product['sale_price'] : 0;
                            $original_price   = (float)$product['price'];
                            $display_price    = $is_on_sale ? $sale_price : $original_price;
                            $discount_percent = $is_on_sale ? round((($original_price - $sale_price) / $original_price) * 100) : 0;
                            $days_left        = $is_on_sale && !empty($product['sale_end'])
                                ? (int)ceil((strtotime($product['sale_end']) - strtotime('today')) / 86400) : 0;
                            $pid = $product['id'];
                        ?>

                            <!-- PRODUCT CARD -->
                            <div class="product-card" onclick="window.location.href='product-view.php?id=<?php echo $pid; ?>'">

                                <!-- Image Container with custom slider -->
                                <div class="product-image-container" id="pic-<?php echo $pid; ?>">

                                    <!-- Fav button -->
                                    <?php $is_fav_prod = in_array($pid, $user_favorited_products); ?>
                                    <button class="btn-fav-product <?php echo $is_fav_prod ? 'active' : ''; ?>"
                                            data-product-id="<?php echo $pid; ?>"
                                            onclick="toggleProductFav(this, event)"
                                            title="<?php echo $is_fav_prod ? 'Remove from favorites' : 'Add to favorites'; ?>">
                                        <i class="fa<?php echo $is_fav_prod ? 's' : 'r'; ?> fa-heart"></i>
                                    </button>

                                    <!-- Slider track -->
                                    <div class="pc-slider-track" id="track-<?php echo $pid; ?>">
                                        <?php foreach ($product_images as $imgUrl): ?>
                                        <div class="pc-slide">
                                            <img src="<?php echo $imgUrl; ?>"
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                 onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
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

                                    <!-- Badges -->
                                    <?php if ($is_on_sale): ?>
                                    <div class="product-badge sale-badge">
                                        <i class="fas fa-fire"></i> -<?php echo $discount_percent; ?>%
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($is_out_of_stock && $is_product): ?>
                                    <div class="product-badge out-of-stock-badge">
                                        <i class="fas fa-times-circle"></i> Out of Stock
                                    </div>
                                    <?php elseif ($is_low_stock): ?>
                                    <div class="product-badge stock-badge low-stock">
                                        <i class="fas fa-exclamation-triangle"></i> Only <?php echo $max_qty; ?> left!
                                    </div>
                                    <?php elseif (!$is_out_of_stock && $is_product && $total_stock > 0): ?>
                                    <div class="product-badge stock-badge">
                                        <i class="fas fa-box"></i> <?php echo $total_stock; ?> left
                                        <?php if ($available_colors > 1): ?>(<?php echo $available_colors; ?> colors)<?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($has_3d): ?>
                                    <a href="3d-view.php?id=<?php echo $pid; ?>" class="btn-3d-badge" onclick="event.stopPropagation()">
                                        <i class="fas fa-cube"></i> 3D View
                                    </a>
                                    <?php endif; ?>

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
                                                <div class="product-price-original"><?php echo formatPrice($original_price); ?></div>
                                                <?php if ($days_left > 0 && $days_left <= 7): ?>
                                                <div class="product-sale-timer">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo $days_left == 1 ? 'Ends tomorrow!' : $days_left . ' days left'; ?>
                                                </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="product-price"><?php echo formatPrice($original_price); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <a href="product-view.php?id=<?php echo $pid; ?>" class="btn-view-product" onclick="event.stopPropagation()">
                                            View <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div><!-- /product-card -->

                        <?php endwhile; ?>
                        </div><!-- /products-grid -->

                        <?php if ($total_products > 4): ?>
                        <div style="text-align: center; margin-top: 25px;">
                            <a href="clinic-products.php?id=<?php echo $clinic_id; ?>" class="view-all-link">
                                Show All <?php echo $total_products; ?> Products <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 30px;">No products or services listed yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN (Sidebar) -->
            <div class="clinic-sidebar">
                <!-- Business Hours -->
                <div class="sidebar-card">
                    <h2><i class="fas fa-clock"></i> Business Hours</h2>
                    <?php if (!empty($clinic['hours']) || !empty($clinic['days'])): ?>
                        <div class="business-hours-display">
                            <?php if (!empty($clinic['days'])): ?>
                            <div class="hours-row">
                                <span class="days-label"><i class="fas fa-calendar-alt"></i> Days:</span>
                                <span class="days-value"><?php echo htmlspecialchars($clinic['days']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($clinic['hours'])): ?>
                            <div class="hours-row">
                                <span class="time-label"><i class="fas fa-clock"></i> Hours:</span>
                                <span class="time-value"><?php echo htmlspecialchars($clinic['hours']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="open-now-indicator">
                            <i class="fas fa-info-circle"></i> Business hours may vary on holidays
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No business hours available.</p>
                    <?php endif; ?>
                </div>

                <!-- Location -->
                <div class="sidebar-card">
                    <h2><i class="fas fa-map-marker-alt"></i> Location</h2>
                    <div class="map-container">
                        <iframe src="https://www.google.com/maps?q=<?php echo urlencode(($clinic['address'] ?? '') . ', ' . ($clinic['city'] ?? '')); ?>&output=embed" allowfullscreen></iframe>
                    </div>
                    <div class="address-box">
                        <p><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($clinic['address'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($clinic['city'] ?? 'N/A'); ?></p>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode(($clinic['address'] ?? '') . ', ' . ($clinic['city'] ?? '')); ?>" target="_blank" class="directions-btn">
                            <i class="fas fa-directions"></i> Get Directions
                        </a>
                    </div>
                </div>

                <!-- Reviews -->
                <div class="sidebar-card">
                    <h2><i class="fas fa-star"></i> Patient Reviews</h2>
                    <?php if ($total_reviews > 0): ?>
                        <div class="reviews-summary">
                            <div class="average-rating-large">
                                <div class="big-rating"><?php echo number_format($avg_rating, 1); ?></div>
                                <div class="rating-stars">
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= round($avg_rating) ? '' : 'empty'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <div class="total-reviews"><?php echo $total_reviews; ?> reviews</div>
                            </div>
                            <div class="rating-bars">
                                <?php for($star = 5; $star >= 1; $star--):
                                    $count = $distribution[$star] ?? 0;
                                    $percent = round(($count / $total) * 100);
                                ?>
                                <div class="rating-bar-item">
                                    <span class="star-label"><?php echo $star; ?> ★</span>
                                    <div class="bar-container"><div class="bar-fill" style="width: <?php echo $percent; ?>%;"></div></div>
                                    <span class="percent"><?php echo $percent; ?>%</span>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="reviews-list">
                            <?php mysqli_data_seek($reviews_query, 0); ?>
                            <?php while($review = mysqli_fetch_assoc($reviews_query)): ?>
                                <div class="review-item">
                                    <div class="review-header">
                                        <div class="reviewer-avatar">
                                            <?php if (!empty($review['avatar']) && file_exists("../assets/images/profiles/{$review['avatar']}")): ?>
                                                <img src="../assets/images/profiles/<?php echo $review['avatar']; ?>" alt="">
                                            <?php else: ?>
                                                <i class="fas fa-user-circle"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="reviewer-info">
                                            <div class="reviewer-name"><?php echo htmlspecialchars($review['fullname']); ?></div>
                                            <div class="review-date"><i class="far fa-clock"></i> <?php echo timeAgo($review['created_at']); ?></div>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $review['rating'] ? '' : 'empty'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <?php if ($review['review']): ?>
                                        <div class="review-text"><?php echo htmlspecialchars($review['review']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($review['would_recommend']): ?>
                                        <div class="recommend-badge"><i class="fas fa-thumbs-up"></i> Recommends this clinic</div>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-reviews">
                            <i class="fas fa-star"></i>
                            <p>No reviews yet. Be the first to share your experience!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /main-content -->

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script>
    // ============================================
    // GALLERY CAROUSEL (clinic photos — unchanged)
    // ============================================
    let currentSlide = 0;
    const slides = document.querySelectorAll('.carousel-slide');
    const dots   = document.querySelectorAll('.dot');
    let slideInterval;
    const carousel = document.querySelector('.carousel');

    function showSlide(index) {
        if (!slides.length) return;
        slides.forEach(s => s.classList.remove('active'));
        dots.forEach(d => d.classList.remove('active'));
        slides[index].classList.add('active');
        dots[index].classList.add('active');
        currentSlide = index;
    }

    function nextSlide() { if (!slides.length) return; showSlide((currentSlide + 1) % slides.length); resetTimer(); }
    function prevSlide() { if (!slides.length) return; showSlide((currentSlide - 1 + slides.length) % slides.length); resetTimer(); }
    function goToGallerySlide(index) { showSlide(index); resetTimer(); }
    function startCarousel() { if (!slides.length) return; slideInterval = setInterval(nextSlide, 5000); }
    function resetTimer() { clearInterval(slideInterval); startCarousel(); }

    if (slides.length > 0) {
        showSlide(0);
        startCarousel();
        if (carousel) {
            carousel.addEventListener('mouseenter', () => clearInterval(slideInterval));
            carousel.addEventListener('mouseleave', startCarousel);
        }
    }

    // ============================================
    // PRODUCT CARD CUSTOM SLIDER
    // ============================================
    // pcState tracks current slide index per product id
    const pcState = {};
    // pcTotal tracks total slides per product id
    const pcTotal = <?php
        $totals = [];
        foreach ($all_product_images_js as $pid => $imgs) {
            $totals[$pid] = count($imgs);
        }
        echo json_encode($totals);
    ?>;

    // Initialize state
    Object.keys(pcTotal).forEach(pid => { pcState[pid] = 0; });

    function pcGo(pid, index, event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        const total = pcTotal[pid] || 1;
        // Wrap around
        index = ((index % total) + total) % total;
        pcState[pid] = index;

        // Move track
        const track = document.getElementById('track-' + pid);
        if (track) track.style.transform = 'translateX(' + (-index * 100) + '%)';

        // Update dots
        const dotsEl = document.getElementById('dots-' + pid);
        if (dotsEl) {
            dotsEl.querySelectorAll('.pc-dot').forEach((d, i) => {
                d.classList.toggle('active', i === index);
            });
        }
    }

    // ============================================
    // TOAST
    // ============================================
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-notification ' + type;
        const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
        toast.innerHTML = '<i class="fas fa-' + (icons[type] || 'info-circle') + '"></i><span>' + message + '</span>';
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function showLoading() { document.getElementById('loadingOverlay').style.display = 'flex'; }
    function hideLoading() { document.getElementById('loadingOverlay').style.display = 'none'; }

    // ============================================
    // CLINIC FAVORITE TOGGLE
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const favoriteBtn = document.getElementById('favoriteBtn');
        if (favoriteBtn) {
            favoriteBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleFavorite(this.getAttribute('data-clinic-id'), this);
            });
        }
    });

    function toggleFavorite(clinicId, button) {
        button.disabled = true;
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData();
        formData.append('clinic_id', clinicId);

        fetch('toggle-favorite.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.action === 'added') {
                        button.innerHTML = '<i class="fas fa-heart"></i> <span>Saved to Favorites</span>';
                        button.style.background = 'var(--primary-gradient)';
                        button.style.color = 'white';
                        showToast('✓ Added to Favorites!');
                    } else {
                        button.innerHTML = '<i class="far fa-heart"></i> <span>Save to Favorites</span>';
                        button.style.background = 'var(--favorite-bg)';
                        button.style.color = 'var(--favorite-color)';
                        showToast('✓ Removed from Favorites');
                    }
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                    button.innerHTML = originalHtml;
                }
            })
            .catch(() => {
                alert('Failed to connect to server. Please try again.');
                button.innerHTML = originalHtml;
            })
            .finally(() => button.disabled = false);
    }

    // ============================================
    // PRODUCT FAVORITE TOGGLE
    // ============================================
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
                    showToast(data.action === 'added' ? '❤️ Added to favorites!' : 'Removed from favorites',
                              data.action === 'added' ? 'success' : 'info');
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