<?php
include 'includes/config.php';
include 'includes/theme.php';

// ============================================
// DATABASE QUERIES
// ============================================

// Total active clinics count
$clinics_count_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM clinics WHERE status = 'Active'");
$clinics_count = mysqli_fetch_assoc($clinics_count_q)['total'] ?? 0;

// Overall rating stats from actual reviews
$rating_q = mysqli_query($conn, "
    SELECT 
        COALESCE(AVG(rating), 0) as avg_rating,
        COUNT(*) as total_reviews
    FROM clinic_reviews
");
$rating_data = mysqli_fetch_assoc($rating_q);
$avg_rating_formatted = number_format($rating_data['avg_rating'], 1);
$total_reviews = $rating_data['total_reviews'] ?? 0;

// Total products count
$products_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM products");
$products_count = mysqli_fetch_assoc($products_q)['total'] ?? 0;

// Cities with clinic counts (active clinics only)
$cities_q = mysqli_query($conn, "
    SELECT city, COUNT(*) as clinic_count 
    FROM clinics 
    WHERE status = 'Active' AND city IS NOT NULL AND city != ''
    GROUP BY city 
    ORDER BY clinic_count DESC, city ASC
");
$cities = [];
while ($row = mysqli_fetch_assoc($cities_q)) {
    $cities[] = $row;
}
$total_cities = count($cities);

// Featured clinics — top rated active clinics with actual review data
$featured_q = mysqli_query($conn, "
    SELECT 
        c.id,
        c.clinic_name,
        c.city,
        c.address,
        c.hours,
        c.clinic_image,
        c.logo,
        c.cover_photo,
        COALESCE(AVG(r.rating), 0) as avg_rating,
        COUNT(r.id) as review_count
    FROM clinics c
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id
    WHERE c.status = 'Active'
    GROUP BY c.id
    ORDER BY review_count DESC, avg_rating DESC, c.id ASC
    LIMIT 6
");

// Count clinics with 3D models
$has_3d_q = mysqli_query($conn, "
    SELECT COUNT(DISTINCT p.clinic_id) as total 
    FROM product_3d_models pm
    JOIN products p ON pm.product_id = p.id
    WHERE pm.has_3d = 1
");
$clinics_with_3d = mysqli_fetch_assoc($has_3d_q)['total'] ?? 0;

// Hot sale products (for slide 3)
$sale_q = mysqli_query($conn, "
    SELECT p.name, p.price, p.sale_price, p.image,
           ROUND(((p.price - p.sale_price) / p.price) * 100) as discount_pct,
           c.clinic_name
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.is_on_sale = 1
      AND p.sale_start <= CURDATE()
      AND p.sale_end >= CURDATE()
      AND c.status = 'Active'
    ORDER BY discount_pct DESC
    LIMIT 3
");
$sale_products = [];
while ($row = mysqli_fetch_assoc($sale_q)) {
    $sale_products[] = $row;
}
$sale_total = count($sale_products);

// ===== DYNAMIC CATEGORIES - Get categories with product counts =====
$categories_data = [];
$cat_q = mysqli_query($conn, "
    SELECT category, COUNT(*) as product_count 
    FROM products 
    GROUP BY category 
    ORDER BY 
        CASE category 
            WHEN 'Eyeglasses' THEN 1
            WHEN 'Sunglasses' THEN 2
            WHEN 'Computer Glasses' THEN 3
            WHEN 'Contact Lens' THEN 4
            WHEN 'Reading' THEN 5
            WHEN 'Kids' THEN 6
            ELSE 7
        END
");

$category_counts = [
    'eyeglasses' => 0,
    'sunglasses' => 0,
    'computer glasses' => 0,
    'contact lens' => 0,
    'reading' => 0,
    'kids' => 0
];

while ($row = mysqli_fetch_assoc($cat_q)) {
    $cat_key = strtolower($row['category']);
    if (isset($category_counts[$cat_key])) {
        $category_counts[$cat_key] = $row['product_count'];
    }
    $categories_data[] = $row;
}

// Helper: get clinic image path
function getClinicImage($clinic) {
    if (!empty($clinic['cover_photo'])) {
        return '/eyecore/assets/images/clinic-covers/' . $clinic['cover_photo'];
    } elseif (!empty($clinic['clinic_image'])) {
        return '/eyecore/assets/images/clinic-images/' . $clinic['clinic_image'];
    } elseif (!empty($clinic['logo'])) {
        return '/eyecore/assets/images/clinic-logos/' . $clinic['logo'];
    }
    return null;
}

// Gradient colors per clinic index
$clinic_gradients = [
    'linear-gradient(135deg,#d1fae5,#6ee7b7)',
    'linear-gradient(135deg,#dbeafe,#93c5fd)',
    'linear-gradient(135deg,#fce7f3,#f9a8d4)',
    'linear-gradient(135deg,#ede9fe,#c4b5fd)',
    'linear-gradient(135deg,#fef9c3,#fde047)',
    'linear-gradient(135deg,#ccfbf1,#5eead4)',
];
$clinic_icon_colors = ['#059669','#1d4ed8','#9d174d','#5b21b6','#92400e','#0f766e'];
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Eyecore — Cavite's Eye Care Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        html, body { width: 100%; overflow-x: hidden; }

        :root {
            --green: #00B761;
            --green-dark: #007A40;
            --green-light: #E3FCE9;
            --green-mid: #00994D;
            --danger: #EF4444;
            --warn: #F59E0B;

            --bg:    #F5F7FA;
            --bg2:   #FFFFFF;
            --card:  #FFFFFF;
            --text1: #111827;
            --text2: #6B7280;
            --text3: #9CA3AF;
            --border:#E5E7EB;
            --border-light: #F3F4F6;

            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
            --shadow-green: 0 8px 24px -8px rgba(0,183,97,0.35);

            --r-sm: 10px;
            --r-md: 14px;
            --r-lg: 20px;
            --r-xl: 28px;
            --r-full: 999px;

            --font: 'Plus Jakarta Sans', -apple-system, sans-serif;
        }

        .theme-dark {
            --bg:    #0D0D0D;
            --bg2:   #161616;
            --card:  #1E1E1E;
            --text1: #F9FAFB;
            --text2: #9CA3AF;
            --text3: #6B7280;
            --border:#2A2A2A;
            --border-light: #222222;
            --green: #00E676;
            --green-light: #0D2818;
        }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text1);
            font-size: 14px;
            line-height: 1.6;
            transition: background 0.3s, color 0.3s;
        }

        /* ===== NAVBAR ===== */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            height: 64px;
            background: var(--bg2);
            border-bottom: 1px solid var(--border-light);
        }
        @media (max-width: 768px) { .navbar { padding: 0 16px; } }

        .nav-left { display: flex; align-items: center; gap: 32px; }

        .logo {
            display: flex; align-items: center; gap: 8px;
            font-size: 20px; font-weight: 800; color: var(--green);
            text-decoration: none; letter-spacing: -0.5px;
        }
        .logo-mark {
            width: 30px; height: 30px; background: var(--green);
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
        }
        .logo-mark svg { width: 16px; height: 16px; fill: white; }

        .nav-links { display: flex; gap: 2px; }
        @media (max-width: 768px) { .nav-links { display: none; } }

        .nav-link {
            padding: 8px 14px; border-radius: var(--r-full);
            font-size: 13px; font-weight: 500; color: var(--text2);
            text-decoration: none; transition: all 0.15s;
        }
        .nav-link:hover { color: var(--green); background: var(--green-light); }

        .nav-right { display: flex; align-items: center; gap: 10px; }

        .icon-btn {
            width: 38px; height: 38px; background: var(--bg);
            border: 1px solid var(--border); border-radius: var(--r-full);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--text2); font-size: 15px;
            transition: all 0.15s;
        }
        .icon-btn:hover { background: var(--green-light); color: var(--green); }

        .btn-outline {
            padding: 8px 20px; border-radius: var(--r-full);
            border: 1.5px solid var(--green); background: transparent;
            color: var(--green); font-size: 13px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-outline:hover { background: var(--green); color: white; }

        .btn-primary {
            padding: 8px 20px; border-radius: var(--r-full);
            background: var(--green); border: none;
            color: white; font-size: 13px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: var(--shadow-green);
        }
        .btn-primary:hover { background: var(--green-dark); transform: translateY(-1px); }

        .mobile-menu-btn {
            display: none; background: none; border: none;
            color: var(--text1); font-size: 22px; cursor: pointer;
        }
        @media (max-width: 768px) { .mobile-menu-btn { display: block; } }

        /* ===== HERO SLIDER ===== */
        .slider-wrapper {
            position: relative; overflow: hidden; width: 100%;
            background: #000;
        }
        .slides-track {
            display: flex;
            transition: transform 0.55s cubic-bezier(0.77,0,0.175,1);
            will-change: transform;
        }
        .slide {
            min-width: 100%; height: 380px;
            display: flex; align-items: center;
            position: relative; overflow: hidden;
        }
        @media (max-width: 768px) { .slide { height: 300px; } }

        .s1 { background: linear-gradient(115deg, #002d16 0%, #005c2e 45%, #00a85a 100%); }
        .s2 { background: linear-gradient(115deg, #070d1a 0%, #0d2550 50%, #1a4080 100%); }
        .s3 { background: linear-gradient(115deg, #1a0a00 0%, #4a1f00 50%, #7a3800 100%); }

        .slide-inner {
            max-width: 1200px; margin: 0 auto; width: 100%;
            padding: 0 60px; display: flex; align-items: center;
            justify-content: space-between; gap: 40px;
        }
        @media (max-width: 768px) { .slide-inner { padding: 0 24px; } }

        .slide-text { flex: 1; max-width: 480px; }
        .slide-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: var(--r-full);
            font-size: 11px; font-weight: 600; margin-bottom: 14px;
            letter-spacing: 0.3px;
        }
        .sb-green { background: rgba(255,255,255,0.12); color: #7dffc0; }
        .sb-blue  { background: rgba(255,255,255,0.12); color: #80d8ff; }
        .sb-amber { background: rgba(255,255,255,0.12); color: #ffd180; }

        .slide-h1 {
            font-size: 34px; font-weight: 800; color: white;
            line-height: 1.18; margin-bottom: 12px; letter-spacing: -0.5px;
        }
        @media (max-width: 768px) { .slide-h1 { font-size: 24px; } }

        .slide-sub {
            font-size: 13px; color: rgba(255,255,255,0.72);
            line-height: 1.65; margin-bottom: 22px; max-width: 380px;
        }
        @media (max-width: 768px) { .slide-sub { display: none; } }

        .slide-btns { display: flex; gap: 10px; flex-wrap: wrap; }
        .sb-white {
            padding: 10px 22px; border-radius: var(--r-full);
            background: white; color: #111; font-size: 13px; font-weight: 700;
            border: none; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 7px;
            transition: all 0.15s;
        }
        .sb-white:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
        .sb-ghost {
            padding: 10px 22px; border-radius: var(--r-full);
            background: transparent; color: white; font-size: 13px; font-weight: 600;
            border: 1.5px solid rgba(255,255,255,0.45); cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
            transition: all 0.15s;
        }
        .sb-ghost:hover { background: rgba(255,255,255,0.12); }

        .slide-visual {
            flex: 0 0 auto; width: 300px; height: 260px;
            display: flex; align-items: center; justify-content: center; position: relative;
        }
        @media (max-width: 768px) { .slide-visual { display: none; } }

        /* Floating stat cards on hero */
        .hero-float {
            position: absolute; background: rgba(255,255,255,0.13);
            backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);
            border-radius: var(--r-lg); padding: 10px 16px; color: white;
            white-space: nowrap;
        }
        .hf-num { font-size: 17px; font-weight: 700; line-height: 1; margin-bottom: 2px; }
        .hf-lbl { font-size: 10px; opacity: 0.75; }
        .hf1 { top: 20px; right: 0; }
        .hf2 { bottom: 32px; right: 30px; }

        /* Slider controls */
        .s-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            z-index: 10; width: 38px; height: 38px;
            background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25);
            border-radius: var(--r-full); display: flex; align-items: center;
            justify-content: center; cursor: pointer; color: white; font-size: 16px;
            transition: background 0.15s;
        }
        .s-arrow:hover { background: rgba(255,255,255,0.28); }
        .s-arr-l { left: 16px; }
        .s-arr-r { right: 16px; }

        .s-dots {
            position: absolute; bottom: 14px; left: 50%;
            transform: translateX(-50%); display: flex; gap: 7px; z-index: 10;
        }
        .s-dot {
            width: 6px; height: 6px; border-radius: var(--r-full);
            background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.2s;
        }
        .s-dot.on { background: white; width: 20px; }

        /* ===== TRUST BAR ===== */
        .trust-bar {
            background: var(--bg2); border-bottom: 1px solid var(--border-light);
        }
        .trust-inner {
            max-width: 1200px; margin: 0 auto; padding: 0 40px;
            display: grid; grid-template-columns: repeat(4, 1fr);
        }
        @media (max-width: 768px) {
            .trust-inner { grid-template-columns: repeat(2, 1fr); padding: 0 16px; }
        }
        .trust-item {
            display: flex; align-items: center; gap: 12px;
            padding: 18px 0; border-right: 1px solid var(--border-light);
            justify-content: center;
        }
        .trust-item:last-child { border-right: none; }
        @media (max-width: 768px) {
            .trust-item:nth-child(2) { border-right: none; }
        }
        .ti-ico {
            width: 36px; height: 36px; background: var(--green-light);
            border-radius: var(--r-md); display: flex; align-items: center;
            justify-content: center; flex-shrink: 0;
        }
        .ti-ico i { color: var(--green); font-size: 15px; }
        .ti-num { font-size: 18px; font-weight: 800; color: var(--text1); line-height: 1; }
        .ti-lbl { font-size: 11px; color: var(--text2); margin-top: 2px; }

        /* ===== SECTION WRAPPER ===== */
        .section { max-width: 1200px; margin: 0 auto; padding: 40px 40px 0; }
        @media (max-width: 768px) { .section { padding: 32px 16px 0; } }

        .sec-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .sec-title { font-size: 18px; font-weight: 800; letter-spacing: -0.3px; }
        .sec-link {
            font-size: 12px; color: var(--green); font-weight: 600;
            text-decoration: none; display: flex; align-items: center; gap: 4px;
        }
        .sec-link:hover { text-decoration: underline; }

        /* ===== CATEGORY TILES ===== */
        .cat-grid {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px;
        }
        @media (max-width: 1024px) { .cat-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 640px)  { .cat-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; } }

        .cat-tile {
            border-radius: var(--r-lg); overflow: hidden; cursor: pointer;
            text-decoration: none; display: block; transition: transform 0.2s;
        }
        .cat-tile:hover { transform: translateY(-4px); }

        .cat-img {
            aspect-ratio: 1/1; display: flex; align-items: center;
            justify-content: center; position: relative;
        }
        .c-eyeglasses { background: linear-gradient(150deg,#0d1a2e,#1a3a6e); }
        .c-sunglasses  { background: linear-gradient(150deg,#1a0a00,#5a2500); }
        .c-computer    { background: linear-gradient(150deg,#001820,#004466); }
        .c-contact     { background: linear-gradient(150deg,#12002e,#3d0080); }
        .c-kids        { background: linear-gradient(150deg,#1a1500,#665000); }
        .c-reading     { background: linear-gradient(150deg,#001a08,#004d1a); }

        .cat-label {
            padding: 8px 10px; background: var(--card);
            border: 1px solid var(--border-light);
            border-top: none;
        }
        .cat-name { font-size: 11px; font-weight: 700; color: var(--text1); }
        .cat-sub  { font-size: 10px; color: var(--text2); margin-top: 1px; }

        /* ===== DUAL BANNER ===== */
        .dual-banner {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
        }
        @media (max-width: 640px) { .dual-banner { grid-template-columns: 1fr; } }

        .banner-card {
            border-radius: var(--r-xl); overflow: hidden; height: 148px;
            position: relative; display: flex; align-items: center;
            padding: 28px 28px; cursor: pointer; text-decoration: none;
            transition: transform 0.2s;
        }
        .banner-card:hover { transform: translateY(-3px); }
        .bn-green  { background: linear-gradient(120deg, #003d20 0%, #006633 55%, #00a854 100%); }
        .bn-dark   { background: linear-gradient(120deg, #060f20 0%, #0d2855 55%, #1a3f80 100%); }

        .bn-text { position: relative; z-index: 2; }
        .bn-eyebrow { font-size: 10px; color: rgba(255,255,255,0.65); font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 6px; }
        .bn-title { font-size: 20px; font-weight: 800; color: white; line-height: 1.2; margin-bottom: 12px; letter-spacing: -0.3px; }
        .bn-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 16px; background: white; border-radius: var(--r-full);
            font-size: 11px; font-weight: 700; color: #111;
            border: none; cursor: pointer;
        }

        .bn-deco {
            position: absolute; right: 20px; top: 50%;
            transform: translateY(-50%); opacity: 0.25; font-size: 80px;
        }

        /* ===== FEATURED CLINICS — horizontal scroll ===== */
        .clinics-scroll {
            overflow-x: auto; scrollbar-width: none; margin: 0 -40px;
            padding: 4px 40px 16px;
        }
        .clinics-scroll::-webkit-scrollbar { display: none; }
        @media (max-width: 768px) { .clinics-scroll { margin: 0 -16px; padding: 4px 16px 16px; } }

        .clinics-track { display: flex; gap: 14px; }

        .clinic-card {
            flex-shrink: 0; width: 220px; background: var(--card);
            border: 1px solid var(--border-light); border-radius: var(--r-xl);
            overflow: hidden; cursor: pointer; text-decoration: none;
            transition: all 0.25s; display: block;
        }
        .clinic-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-md); border-color: var(--green); }

        .cc-img {
            height: 130px; position: relative;
            display: flex; align-items: center; justify-content: center;
        }
        .cc-img img {
            width: 100%; height: 100%; object-fit: cover;
            display: block;
        }
        .cc-img-placeholder {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
        }
        .cc-icon {
            width: 54px; height: 54px; background: rgba(255,255,255,0.85);
            border-radius: 16px; display: flex; align-items: center;
            justify-content: center;
        }
        .cc-icon i { font-size: 26px; }
        .cc-grad-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.32) 0%, transparent 55%);
        }
        .cc-rating {
            position: absolute; top: 9px; right: 9px;
            background: rgba(0,0,0,0.6); color: white;
            font-size: 10px; font-weight: 700; padding: 3px 8px;
            border-radius: var(--r-full); display: flex; align-items: center; gap: 3px;
        }
        .cc-rating i { color: var(--warn); font-size: 9px; }
        .cc-3d {
            position: absolute; top: 9px; left: 9px;
            background: var(--green); color: white;
            font-size: 9px; font-weight: 700; padding: 3px 9px;
            border-radius: var(--r-full);
        }
        .cc-city {
            position: absolute; bottom: 9px; left: 9px;
            color: white; font-size: 10px; font-weight: 600;
        }

        .cc-body { padding: 12px 14px 14px; }
        .cc-name {
            font-size: 13px; font-weight: 700; margin-bottom: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .cc-loc { font-size: 11px; color: var(--text2); margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        .cc-loc i { color: var(--green); font-size: 10px; }
        .cc-foot { display: flex; align-items: center; justify-content: space-between; }
        .cc-stars { color: var(--warn); font-size: 11px; }
        .cc-revs { font-size: 10px; color: var(--text2); margin-left: 3px; }
        .cc-book {
            font-size: 11px; font-weight: 700; color: var(--green);
            background: var(--green-light); padding: 5px 12px;
            border-radius: var(--r-full);
        }

        /* ===== CITIES GRID ===== */
        .cities-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
        }
        @media (max-width: 1024px) { .cities-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 640px)  { .cities-grid { grid-template-columns: repeat(2, 1fr); } }

        .city-card {
            border-radius: var(--r-lg); overflow: hidden; cursor: pointer;
            text-decoration: none; display: block; transition: transform 0.2s;
            border: 1px solid var(--border-light);
        }
        .city-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .city-bg {
            height: 88px; display: flex; align-items: flex-end;
            padding: 10px 14px; position: relative;
        }
        .city-name-over {
            position: relative; z-index: 2;
            font-size: 13px; font-weight: 800; color: white; letter-spacing: -0.2px;
        }
        .city-foot {
            padding: 8px 14px; background: var(--card);
            display: flex; align-items: center; justify-content: space-between;
        }
        .city-count-lbl { font-size: 11px; color: var(--text2); }
        .city-arr { font-size: 13px; color: var(--green); font-weight: 700; }

        /* City bg colors */
        .cb-0{background:linear-gradient(150deg,#003d20,#00874a);}
        .cb-1{background:linear-gradient(150deg,#0d2550,#1a4d99);}
        .cb-2{background:linear-gradient(150deg,#3d0052,#800080);}
        .cb-3{background:linear-gradient(150deg,#2d2d00,#666600);}
        .cb-4{background:linear-gradient(150deg,#3d1a00,#804000);}
        .cb-5{background:linear-gradient(150deg,#001a33,#003d66);}
        .cb-6{background:linear-gradient(150deg,#1a003d,#3d007a);}
        .cb-7{background:linear-gradient(150deg,#001a0d,#004d26);}

        /* ===== HOW IT WORKS ===== */
        .how-bg {
            background: var(--bg2); border-top: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
            padding: 40px 0;
            margin-top: 40px;
        }
        .how-inner { max-width: 1200px; margin: 0 auto; padding: 0 40px; }
        @media (max-width: 768px) { .how-inner { padding: 0 16px; } }
        .how-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 0;
            margin-top: 24px;
        }
        @media (max-width: 640px) { .how-grid { grid-template-columns: 1fr; } }
        .how-step {
            padding: 24px 28px; border-right: 1px solid var(--border-light);
            text-align: center;
        }
        .how-step:last-child { border-right: none; }
        @media (max-width: 640px) { .how-step { border-right: none; border-bottom: 1px solid var(--border-light); } }
        @media (max-width: 640px) { .how-step:last-child { border-bottom: none; } }

        .how-num {
            width: 28px; height: 28px; background: var(--green);
            border-radius: var(--r-full); color: white; font-size: 12px;
            font-weight: 800; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
        }
        .how-ico {
            width: 52px; height: 52px; background: var(--green-light);
            border-radius: var(--r-lg); display: flex; align-items: center;
            justify-content: center; margin: 0 auto 12px;
        }
        .how-ico i { color: var(--green); font-size: 22px; }
        .how-title { font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        .how-desc  { font-size: 12px; color: var(--text2); line-height: 1.65; }

        /* ===== FEATURES ===== */
        .features-bg { background: var(--bg); padding: 40px 0; }
        .features-inner { max-width: 1200px; margin: 0 auto; padding: 0 40px; }
        @media (max-width: 768px) { .features-inner { padding: 0 16px; } }
        .feat-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
            margin-top: 24px;
        }
        @media (max-width: 1024px) { .feat-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px)  { .feat-grid { grid-template-columns: 1fr; } }
        .feat-card {
            background: var(--card); border: 1px solid var(--border-light);
            border-radius: var(--r-xl); padding: 22px 20px;
            display: flex; gap: 14px; align-items: flex-start;
            transition: all 0.2s;
        }
        .feat-card:hover { border-color: var(--green); box-shadow: var(--shadow-green); transform: translateY(-3px); }
        .fi {
            width: 40px; height: 40px; border-radius: var(--r-md);
            flex-shrink: 0; display: flex; align-items: center; justify-content: center;
        }
        .fi i { font-size: 18px; }
        .fi-g  { background: var(--green-light); } .fi-g i  { color: var(--green); }
        .fi-b  { background: #EFF6FF; }            .fi-b i  { color: #1D4ED8; }
        .fi-a  { background: #FFFBEB; }            .fi-a i  { color: #B45309; }
        .fi-r  { background: #FFF1F2; }            .fi-r i  { color: #BE123C; }
        .fi-t  { background: #F0FDFA; }            .fi-t i  { color: #0F766E; }
        .fi-p  { background: #F5F3FF; }            .fi-p i  { color: #6D28D9; }
        .ft  { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        .fd  { font-size: 12px; color: var(--text2); line-height: 1.6; }

        /* ===== 3D VIEWER SECTION ===== */
        .tryon-section {
            background: linear-gradient(130deg, #060f1e 0%, #0b2540 45%, #082d1a 100%);
            padding: 60px 40px;
            margin-top: 40px;
        }
        @media (max-width: 768px) { .tryon-section { padding: 40px 16px; } }
        .tryon-inner {
            max-width: 1200px; margin: 0 auto;
            display: flex; align-items: center; gap: 60px;
        }
        @media (max-width: 768px) { .tryon-inner { flex-direction: column; gap: 32px; } }
        .tryon-left { flex: 1; }
        .tryon-tag { font-size: 11px; color: #7dffc0; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 10px; }
        .tryon-h2 { font-size: 28px; font-weight: 800; color: white; line-height: 1.2; margin-bottom: 12px; letter-spacing: -0.5px; }
        @media (max-width: 768px) { .tryon-h2 { font-size: 22px; } }
        .tryon-p { font-size: 13px; color: rgba(255,255,255,0.65); line-height: 1.7; margin-bottom: 24px; max-width: 420px; }
        .tryon-features { display: flex; flex-direction: column; gap: 10px; margin-bottom: 28px; }
        .tf-item { display: flex; align-items: center; gap: 10px; }
        .tf-dot { width: 7px; height: 7px; background: var(--green); border-radius: 50%; flex-shrink: 0; }
        .tf-text { font-size: 12px; color: rgba(255,255,255,0.8); }
        .tryon-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 28px; background: white; color: #060f1e;
            border-radius: var(--r-full); font-size: 13px; font-weight: 800;
            cursor: pointer; border: none; text-decoration: none;
            transition: all 0.15s;
        }
        .tryon-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }

        .tryon-right { flex: 0 0 320px; }
        @media (max-width: 768px) { .tryon-right { flex: auto; width: 100%; } }

        .tryon-viewer-card {
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
            border-radius: var(--r-xl); overflow: hidden;
        }
        .tv-header {
            background: rgba(255,255,255,0.05); padding: 14px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex; align-items: center; gap: 8px;
        }
        .tv-dot { width: 8px; height: 8px; border-radius: 50%; }
        .tv-title { font-size: 11px; color: rgba(255,255,255,0.6); margin-left: 4px; font-weight: 600; }
        .tv-body { padding: 28px 20px; text-align: center; }
        .tv-frame-display {
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1);
            border-radius: var(--r-lg); padding: 28px 20px; margin-bottom: 16px;
            position: relative;
        }
        .tv-rotate-hint {
            position: absolute; bottom: 8px; right: 10px;
            font-size: 9px; color: rgba(255,255,255,0.35);
            display: flex; align-items: center; gap: 3px;
        }
        .tv-product-name { font-size: 12px; color: rgba(255,255,255,0.8); font-weight: 600; margin-bottom: 4px; }
        .tv-product-sub  { font-size: 10px; color: rgba(255,255,255,0.45); margin-bottom: 14px; }
        .tv-colors { display: flex; gap: 7px; justify-content: center; }
        .tv-color {
            width: 16px; height: 16px; border-radius: 50%;
            border: 2px solid transparent; cursor: pointer; transition: border 0.15s;
        }
        .tv-color.active { border-color: rgba(255,255,255,0.7); }

        /* ===== CTA SECTION ===== */
        .cta-section {
            padding: 60px 40px; text-align: center;
        }
        @media (max-width: 768px) { .cta-section { padding: 48px 16px; } }
        .cta-inner { max-width: 560px; margin: 0 auto; }
        .cta-h2 { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 10px; }
        .cta-h2 span { color: var(--green); }
        .cta-sub { font-size: 13px; color: var(--text2); line-height: 1.7; margin-bottom: 28px; }
        .cta-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-bottom: 20px; }
        .cta-btn-main {
            padding: 13px 32px; background: var(--green); color: white;
            border-radius: var(--r-full); font-size: 14px; font-weight: 700;
            border: none; cursor: pointer; text-decoration: none;
            box-shadow: var(--shadow-green); transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .cta-btn-main:hover { background: var(--green-dark); transform: translateY(-2px); }
        .cta-btn-sec {
            padding: 13px 32px; background: transparent; color: var(--green);
            border-radius: var(--r-full); font-size: 14px; font-weight: 700;
            border: 1.5px solid var(--green); cursor: pointer; text-decoration: none;
            transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px;
        }
        .cta-btn-sec:hover { background: var(--green); color: white; }
        .cta-trust {
            display: flex; align-items: center; justify-content: center;
            gap: 20px; flex-wrap: wrap;
        }
        .ct-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text2); }
        .ct-item i { color: var(--green); font-size: 11px; }

        /* ===== FOOTER ===== */
        .footer { background: var(--bg2); border-top: 1px solid var(--border-light); padding: 48px 40px 24px; }
        @media (max-width: 768px) { .footer { padding: 40px 16px 20px; } }
        .footer-grid {
            max-width: 1200px; margin: 0 auto 36px;
            display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 32px;
        }
        @media (max-width: 1024px) { .footer-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px)  { .footer-grid { grid-template-columns: 1fr; } }

        .footer-logo { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .fl-mark { width: 26px; height: 26px; background: var(--green); border-radius: 7px; display: flex; align-items: center; justify-content: center; }
        .fl-mark svg { width: 14px; height: 14px; fill: white; }
        .fl-name { font-size: 17px; font-weight: 800; color: var(--green); letter-spacing: -0.4px; }
        .footer-p { font-size: 12px; color: var(--text2); line-height: 1.7; margin-bottom: 18px; }
        .soc-row { display: flex; gap: 8px; }
        .soc-btn {
            width: 32px; height: 32px; background: var(--bg);
            border: 1px solid var(--border); border-radius: var(--r-full);
            display: flex; align-items: center; justify-content: center;
            color: var(--text2); font-size: 13px; text-decoration: none;
            transition: all 0.15s;
        }
        .soc-btn:hover { background: var(--green); color: white; border-color: var(--green); }
        .fh4 { font-size: 12px; font-weight: 800; color: var(--text1); margin-bottom: 14px; letter-spacing: 0.3px; text-transform: uppercase; }
        .flinks { list-style: none; display: flex; flex-direction: column; gap: 9px; }
        .flinks li a { font-size: 13px; color: var(--text2); text-decoration: none; transition: color 0.15s; }
        .flinks li a:hover { color: var(--green); }
        .footer-bottom {
            max-width: 1200px; margin: 0 auto; border-top: 1px solid var(--border-light);
            padding-top: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
        }
        .fb-copy { font-size: 12px; color: var(--text3); }
        .fb-right { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text2); }
        .fb-right i { color: var(--green); font-size: 11px; }

        /* ===== MOBILE MENU ===== */
        .mob-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 998;
        }
        .mob-menu {
            position: fixed; top: 0; right: -300px; width: 280px; height: 100vh;
            background: var(--bg2); z-index: 999; overflow-y: auto;
            transition: right 0.3s ease; box-shadow: var(--shadow-lg);
        }
        .mob-menu.open { right: 0; }
        .mob-top {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px; border-bottom: 1px solid var(--border-light);
        }
        .mob-links { padding: 16px; display: flex; flex-direction: column; gap: 4px; }
        .mob-link {
            padding: 12px 14px; border-radius: var(--r-md);
            font-size: 14px; font-weight: 500; color: var(--text1);
            text-decoration: none; transition: all 0.15s;
        }
        .mob-link:hover { background: var(--green-light); color: var(--green); }
        .mob-divider { height: 1px; background: var(--border-light); margin: 10px 16px; }
        .mob-btns { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
        .mob-btns a { text-align: center; justify-content: center; width: 100%; }

        /* ===== SECTION SPACING ===== */
        .section-gap { padding-top: 40px; }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
    <nav class="navbar">
        <div class="nav-left">
            <a href="index.php" class="logo">
                <div class="logo-mark">
                    <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                </div>
                eyecore
            </a>
<div class="nav-links">
    <a href="#features" class="nav-link">Features</a>
    <a href="#cities" class="nav-link">Cities</a>
    <a href="#clinics" class="nav-link">Clinics</a>
    <a href="#contact" class="nav-link">Contact</a>
    <a href="auth/register.php" class="nav-link" style="color: var(--green); font-weight: 700;">
        <i></i> Partner With Us
    </a>
    <a href="suppliers/supplier_login.php?register=1" class="nav-link">
        <i></i>Supplier
    </a>
</div>
        </div>
        <div class="nav-right">
            <button class="icon-btn" onclick="toggleTheme()" id="themeToggle">
                <i class="fas fa-moon"></i>
            </button>
            <a href="auth/user_login.php" class="btn-outline">Login</a>
            <a href="auth/user_register.php" class="btn-primary">
                <i class="fas fa-user-plus"></i> Sign Up Free
            </a>
            <button class="mobile-menu-btn" onclick="openMobMenu()">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- ===== HERO SLIDER ===== -->
    <div class="slider-wrapper" id="sliderWrap">
        <div class="slides-track" id="slidesTrack">

            <!-- SLIDE 1: Main Hero -->
            <div class="slide s1">
                <div class="slide-inner">
                    <div class="slide-text">
                        <div class="slide-badge sb-green">
                            <i class="fas fa-circle" style="font-size:6px;"></i>
                            Cavite's #1 Eye Care Platform
                        </div>
                        <div class="slide-h1">Your Vision,<br>Our Mission.</div>
                        <div class="slide-sub">Find the best optical clinics in Cavite, book appointments instantly, and explore eyewear — all in one place.</div>
                        <div class="slide-btns">
                            <a href="auth/user_register.php" class="sb-white">
                                <i class="fas fa-arrow-right"></i> Get Started Free
                            </a>
                            <a href="pages/dashboard.php" class="sb-ghost">
                                Explore Clinics
                            </a>
                        </div>
                    </div>
                    <div class="slide-visual">
                        <svg width="280" height="240" viewBox="0 0 280 240" fill="none">
                            <ellipse cx="88" cy="126" rx="68" ry="55" fill="none" stroke="rgba(255,255,255,0.88)" stroke-width="8"/>
                            <ellipse cx="192" cy="126" rx="68" ry="55" fill="none" stroke="rgba(255,255,255,0.88)" stroke-width="8"/>
                            <ellipse cx="88" cy="126" rx="42" ry="34" fill="rgba(0,220,130,0.18)"/>
                            <ellipse cx="192" cy="126" rx="42" ry="34" fill="rgba(0,220,130,0.18)"/>
                            <path d="M156 126 Q140 116 124 126" fill="none" stroke="rgba(255,255,255,0.88)" stroke-width="7" stroke-linecap="round"/>
                            <line x1="20" y1="112" x2="0" y2="104" stroke="rgba(255,255,255,0.88)" stroke-width="7" stroke-linecap="round"/>
                            <line x1="260" y1="112" x2="280" y2="104" stroke="rgba(255,255,255,0.88)" stroke-width="7" stroke-linecap="round"/>
                            <circle cx="73" cy="112" r="11" fill="rgba(255,255,255,0.18)"/>
                            <circle cx="177" cy="112" r="11" fill="rgba(255,255,255,0.18)"/>
                        </svg>
                        <div class="hero-float hf1">
                            <div class="hf-num"><?php echo $clinics_count; ?>+</div>
                            <div class="hf-lbl">Active Clinics</div>
                        </div>
                        <div class="hero-float hf2">
                            <div class="hf-num"><?php echo $total_cities; ?> Cities</div>
                            <div class="hf-lbl">Across Cavite</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 2: 3D Frame Viewer -->
            <div class="slide s2">
                <div class="slide-inner">
                    <div class="slide-text">
                        <div class="slide-badge sb-blue">
                            <i class="fas fa-cube" style="font-size:10px;"></i>
                            3D Frame Viewer
                        </div>
                        <div class="slide-h1">See Every Frame<br>in Full 3D.</div>
                        <div class="slide-sub">Rotate, zoom, and inspect eyeglass frames in detailed 3D view before you book. Available on select frames from partner clinics.</div>
                        <div class="slide-btns">
                            <a href="pages/dashboard.php" class="sb-white">
                                <i class="fas fa-cube"></i> Explore 3D Frames
                            </a>
                            <a href="auth/user_register.php" class="sb-ghost">Sign Up Free</a>
                        </div>
                    </div>
                    <div class="slide-visual">
                        <svg width="260" height="220" viewBox="0 0 260 220" fill="none">
                            <!-- 3D cube/frame illusion -->
                            <rect x="80" y="60" width="100" height="80" rx="6" fill="rgba(255,255,255,0.05)" stroke="rgba(100,180,255,0.6)" stroke-width="1.5"/>
                            <rect x="94" y="72" width="72" height="56" rx="4" fill="rgba(100,180,255,0.1)" stroke="rgba(100,180,255,0.4)" stroke-width="1"/>
                            <!-- glasses inside -->
                            <ellipse cx="105" cy="100" rx="18" ry="14" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3.5"/>
                            <ellipse cx="155" cy="100" rx="18" ry="14" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3.5"/>
                            <ellipse cx="105" cy="100" rx="10" ry="8" fill="rgba(100,180,255,0.25)"/>
                            <ellipse cx="155" cy="100" rx="10" ry="8" fill="rgba(100,180,255,0.25)"/>
                            <path d="M123 100 Q130 96 137 100" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <line x1="87" y1="94" x2="80" y2="91" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <line x1="173" y1="94" x2="180" y2="91" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <!-- rotate arrows -->
                            <path d="M65 130 Q50 110 65 90" fill="none" stroke="rgba(100,180,255,0.6)" stroke-width="1.5" stroke-dasharray="3 3"/>
                            <path d="M195 130 Q210 110 195 90" fill="none" stroke="rgba(100,180,255,0.6)" stroke-width="1.5" stroke-dasharray="3 3"/>
                            <!-- rotate icon -->
                            <text x="130" y="165" text-anchor="middle" fill="rgba(255,255,255,0.4)" font-size="10" font-family="sans-serif">⟳ Drag to rotate</text>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3: Hot Deals — only show if there are active sales -->
            <?php if ($sale_total > 0): ?>
            <div class="slide s3">
                <div class="slide-inner">
                    <div class="slide-text">
                        <div class="slide-badge sb-amber">
                            <i class="fas fa-fire" style="font-size:10px;"></i>
                            Hot Deals This Week
                        </div>
                        <div class="slide-h1">Up to 50% Off<br>on Eyewear.</div>
                        <div class="slide-sub">Exclusive discounts on frames, lenses, and services from top optical clinics in Cavite. Limited time only.</div>
                        <div class="slide-btns">
                            <a href="pages/sale-products.php" class="sb-white">
                                <i class="fas fa-tags"></i> See All Deals
                            </a>
                            <a href="auth/user_register.php" class="sb-ghost">Browse Clinics</a>
                        </div>
                    </div>
                    <div class="slide-visual" style="flex-direction:column;gap:10px;padding:0 20px 0 0;justify-content:center;">
                        <?php if (!empty($sale_products)): ?>
                            <?php foreach ($sale_products as $sp): ?>
                            <div style="background:rgba(255,255,255,0.10);border:1px solid rgba(255,255,255,0.18);border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                                <div style="width:38px;height:38px;background:rgba(255,255,255,0.14);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-glasses" style="color:rgba(255,255,255,0.8);font-size:16px;"></i>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12px;color:white;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($sp['name']); ?></div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                                        <span style="font-size:13px;color:#ffb347;font-weight:700;">₱<?php echo number_format($sp['sale_price']); ?></span>
                                        <span style="font-size:10px;color:rgba(255,255,255,0.45);text-decoration:line-through;">₱<?php echo number_format($sp['price']); ?></span>
                                    </div>
                                </div>
                                <div style="background:#EF4444;color:white;font-size:9px;font-weight:700;padding:3px 8px;border-radius:10px;flex-shrink:0;">-<?php echo $sp['discount_pct']; ?>%</div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align:center;padding:20px 0;">
                                <i class="fas fa-tags" style="font-size:36px;color:rgba(255,255,255,0.25);margin-bottom:12px;display:block;"></i>
                                <div style="font-size:13px;color:rgba(255,255,255,0.6);margin-bottom:6px;">No active deals right now</div>
                                <div style="font-size:11px;color:rgba(255,255,255,0.4);">Check back soon for hot offers from Cavite clinics.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; // end sale_total check for slide 3 ?>

        </div><!-- end slides-track -->

        <!-- Arrows -->
        <div class="s-arrow s-arr-l" onclick="prevSlide()"><i class="fas fa-chevron-left"></i></div>
        <div class="s-arrow s-arr-r" onclick="nextSlide()"><i class="fas fa-chevron-right"></i></div>
        <!-- Dots -->
        <div class="s-dots">
            <div class="s-dot on" onclick="goSlide(0)"></div>
            <div class="s-dot" onclick="goSlide(1)"></div>
            <?php if ($sale_total > 0): ?><div class="s-dot" onclick="goSlide(2)"></div><?php endif; ?>
        </div>
    </div>

    <!-- ===== TRUST BAR ===== -->
    <div class="trust-bar">
        <div class="trust-inner">
            <div class="trust-item">
                <div class="ti-ico"><i class="fas fa-clinic-medical"></i></div>
                <div>
                    <div class="ti-num"><?php echo $clinics_count; ?>+</div>
                    <div class="ti-lbl">Eye Clinics</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="ti-ico"><i class="fas fa-glasses"></i></div>
                <div>
                    <div class="ti-num"><?php echo $products_count; ?>+</div>
                    <div class="ti-lbl">Eyewear Products</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="ti-ico"><i class="fas fa-map-marker-alt"></i></div>
                <div>
                    <div class="ti-num"><?php echo $total_cities; ?></div>
                    <div class="ti-lbl">Cities in Cavite</div>
                </div>
            </div>
            <div class="trust-item">
                <div class="ti-ico" style="background:#FFFBEB;"><i class="fas fa-star" style="color:#B45309;"></i></div>
                <div>
                    <div class="ti-num"><?php echo ($total_reviews > 0 ? $avg_rating_formatted . ' ★' : 'New'); ?></div>
                    <div class="ti-lbl"><?php echo ($total_reviews > 0 ? 'Avg. Rating' : 'Be First to Review'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== CATEGORIES - DYNAMIC VERSION ===== -->
    <div class="section section-gap">
        <div class="sec-head">
            <div class="sec-title">Shop by Category</div>
            <a href="pages/dashboard.php" class="sec-link">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="cat-grid">
            <?php if (isset($category_counts['eyeglasses']) && $category_counts['eyeglasses'] > 0): ?>
            <a href="pages/dashboard.php?category=eyeglasses" class="cat-tile">
                <div class="cat-img c-eyeglasses">
                    <svg width="80" height="52" viewBox="0 0 90 52" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <ellipse cx="24" cy="27" rx="20" ry="17" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="4.5"/>
                        <ellipse cx="66" cy="27" rx="20" ry="17" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="4.5"/>
                        <ellipse cx="24" cy="27" rx="11" ry="9" fill="rgba(100,180,255,0.3)"/>
                        <ellipse cx="66" cy="27" rx="11" ry="9" fill="rgba(100,180,255,0.3)"/>
                        <path d="M44 27 Q45 23 46 27" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="3.5" stroke-linecap="round"/>
                        <line x1="4" y1="22" x2="0" y2="20" stroke="rgba(255,255,255,0.9)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="86" y1="22" x2="90" y2="20" stroke="rgba(255,255,255,0.9)" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Eyeglasses</div>
                    <div class="cat-sub"><?php echo $category_counts['eyeglasses']; ?> frames</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (isset($category_counts['sunglasses']) && $category_counts['sunglasses'] > 0): ?>
            <a href="pages/dashboard.php?category=sunglasses" class="cat-tile">
                <div class="cat-img c-sunglasses">
                    <svg width="80" height="52" viewBox="0 0 90 52" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <ellipse cx="24" cy="27" rx="20" ry="17" fill="rgba(80,30,0,0.55)" stroke="rgba(255,180,60,0.9)" stroke-width="4.5"/>
                        <ellipse cx="66" cy="27" rx="20" ry="17" fill="rgba(80,30,0,0.55)" stroke="rgba(255,180,60,0.9)" stroke-width="4.5"/>
                        <path d="M44 27 Q45 23 46 27" fill="none" stroke="rgba(255,180,60,0.9)" stroke-width="3.5" stroke-linecap="round"/>
                        <line x1="4" y1="22" x2="0" y2="20" stroke="rgba(255,180,60,0.9)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="86" y1="22" x2="90" y2="20" stroke="rgba(255,180,60,0.9)" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Sunglasses</div>
                    <div class="cat-sub"><?php echo $category_counts['sunglasses']; ?> products</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (isset($category_counts['computer glasses']) && $category_counts['computer glasses'] > 0): ?>
            <a href="pages/dashboard.php?category=computer" class="cat-tile">
                <div class="cat-img c-computer">
                    <svg width="80" height="52" viewBox="0 0 90 52" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <ellipse cx="24" cy="27" rx="20" ry="17" fill="rgba(0,50,80,0.5)" stroke="rgba(80,220,255,0.9)" stroke-width="4.5"/>
                        <ellipse cx="66" cy="27" rx="20" ry="17" fill="rgba(0,50,80,0.5)" stroke="rgba(80,220,255,0.9)" stroke-width="4.5"/>
                        <path d="M44 27 Q45 23 46 27" fill="none" stroke="rgba(80,220,255,0.9)" stroke-width="3.5" stroke-linecap="round"/>
                        <line x1="4" y1="22" x2="0" y2="20" stroke="rgba(80,220,255,0.9)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="86" y1="22" x2="90" y2="20" stroke="rgba(80,220,255,0.9)" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Computer Glasses</div>
                    <div class="cat-sub"><?php echo $category_counts['computer glasses']; ?> products</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (isset($category_counts['contact lens']) && $category_counts['contact lens'] > 0): ?>
            <a href="pages/dashboard.php?category=contact" class="cat-tile">
                <div class="cat-img c-contact">
                    <svg width="56" height="56" viewBox="0 0 56 56" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <circle cx="28" cy="28" r="23" fill="none" stroke="rgba(180,100,255,0.9)" stroke-width="4.5"/>
                        <circle cx="28" cy="28" r="14" fill="rgba(100,0,180,0.4)" stroke="rgba(180,100,255,0.6)" stroke-width="2"/>
                        <circle cx="28" cy="28" r="6" fill="rgba(180,100,255,0.8)"/>
                        <circle cx="21" cy="21" r="4" fill="rgba(255,255,255,0.25)"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Contact Lens</div>
                    <div class="cat-sub"><?php echo $category_counts['contact lens']; ?> products</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (isset($category_counts['kids']) && $category_counts['kids'] > 0): ?>
            <a href="pages/dashboard.php?category=kids" class="cat-tile">
                <div class="cat-img c-kids">
                    <svg width="80" height="52" viewBox="0 0 90 52" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <ellipse cx="24" cy="27" rx="20" ry="17" fill="rgba(60,40,0,0.4)" stroke="rgba(255,215,50,0.9)" stroke-width="4.5"/>
                        <ellipse cx="66" cy="27" rx="20" ry="17" fill="rgba(60,40,0,0.4)" stroke="rgba(255,215,50,0.9)" stroke-width="4.5"/>
                        <path d="M44 27 Q45 23 46 27" fill="none" stroke="rgba(255,215,50,0.9)" stroke-width="3.5" stroke-linecap="round"/>
                        <line x1="4" y1="22" x2="0" y2="20" stroke="rgba(255,215,50,0.9)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="86" y1="22" x2="90" y2="20" stroke="rgba(255,215,50,0.9)" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Kids Glasses</div>
                    <div class="cat-sub">Ages 4 – 14</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (isset($category_counts['reading']) && $category_counts['reading'] > 0): ?>
            <a href="pages/dashboard.php?category=reading" class="cat-tile">
                <div class="cat-img c-reading">
                    <svg width="80" height="52" viewBox="0 0 90 52" fill="none" style="filter:drop-shadow(0 4px 8px rgba(0,0,0,0.4));">
                        <ellipse cx="24" cy="27" rx="20" ry="17" fill="rgba(0,40,10,0.5)" stroke="rgba(80,255,130,0.9)" stroke-width="4.5"/>
                        <ellipse cx="66" cy="27" rx="20" ry="17" fill="rgba(0,40,10,0.5)" stroke="rgba(80,255,130,0.9)" stroke-width="4.5"/>
                        <path d="M44 27 Q45 23 46 27" fill="none" stroke="rgba(80,255,130,0.9)" stroke-width="3.5" stroke-linecap="round"/>
                        <line x1="4" y1="22" x2="0" y2="20" stroke="rgba(80,255,130,0.9)" stroke-width="4" stroke-linecap="round"/>
                        <line x1="86" y1="22" x2="90" y2="20" stroke="rgba(80,255,130,0.9)" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="cat-label">
                    <div class="cat-name">Reading Glasses</div>
                    <div class="cat-sub"><?php echo $category_counts['reading']; ?> products</div>
                </div>
            </a>
            <?php endif; ?>

            <?php if (empty($category_counts) || array_sum($category_counts) == 0): ?>
            <div class="empty-state" style="grid-column: 1/-1; text-align:center; padding:40px;">
                <i class="fas fa-box-open" style="font-size:48px; color:var(--text-muted); opacity:0.5;"></i>
                <h3 style="margin-top:12px;">No products available yet</h3>
                <p style="color:var(--text2);">Check back soon for eyewear products from our partner clinics.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== DUAL BANNERS ===== -->
    <div class="section section-gap">
        <div class="dual-banner">
            <a href="pages/clinics-map.php" class="banner-card bn-green">
                <div class="bn-text">
                    <div class="bn-eyebrow">Explore Cavite</div>
                    <div class="bn-title">Find clinics<br>near you</div>
                    <div class="bn-btn"><i class="fas fa-map-marked-alt"></i> Explore Map</div>
                </div>
                <div class="bn-deco"><i class="fas fa-map-marker-alt"></i></div>
            </a>
            <?php if ($sale_total > 0): ?>
            <a href="pages/sale-products.php" class="banner-card bn-dark">
                <div class="bn-text">
                    <div class="bn-eyebrow">Limited Time</div>
                    <div class="bn-title">Up to 50% off<br>this week only</div>
                    <div class="bn-btn"><i class="fas fa-tags"></i> Shop Deals</div>
                </div>
                <div class="bn-deco"><i class="fas fa-percent"></i></div>
            </a>
            <?php else: ?>
            <a href="auth/user_register.php" class="banner-card bn-dark">
                <div class="bn-text">
                    <div class="bn-eyebrow">Join Free</div>
                    <div class="bn-title">Create your<br>free account today</div>
                    <div class="bn-btn"><i class="fas fa-user-plus"></i> Sign Up</div>
                </div>
                <div class="bn-deco"><i class="fas fa-user-circle"></i></div>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== FEATURED CLINICS ===== -->
    <div class="section section-gap" id="clinics">
        <div class="sec-head">
            <div class="sec-title">Featured Clinics</div>
            <a href="pages/dashboard.php" class="sec-link">View all <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
    <div class="section" style="padding-top:0;">
        <div class="clinics-scroll">
            <div class="clinics-track">
                <?php
                $ci = 0;
                if (mysqli_num_rows($featured_q) > 0):
                    while ($clinic = mysqli_fetch_assoc($featured_q)):
                        $avg = round($clinic['avg_rating'], 1);
                        $img_path = getClinicImage($clinic);
                        $grad = $clinic_gradients[$ci % count($clinic_gradients)];
                        $icon_color = $clinic_icon_colors[$ci % count($clinic_icon_colors)];
                        // Check if this clinic has 3D models
                        $has3d_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM product_3d_models pm JOIN products p ON pm.product_id = p.id WHERE p.clinic_id = {$clinic['id']} AND pm.has_3d = 1");
                        $has3d = mysqli_fetch_assoc($has3d_q)['c'] > 0;
                ?>
                <a href="pages/clinic-details.php?id=<?php echo $clinic['id']; ?>" class="clinic-card">
                    <div class="cc-img">
                        <?php if ($img_path): ?>
                            <img src="<?php echo htmlspecialchars($img_path); ?>"
                                 alt="<?php echo htmlspecialchars($clinic['clinic_name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="cc-img-placeholder" style="background:<?php echo $grad; ?>;display:none;">
                                <div class="cc-icon"><i class="fas fa-clinic-medical" style="color:<?php echo $icon_color; ?>;font-size:26px;"></i></div>
                            </div>
                        <?php else: ?>
                            <div class="cc-img-placeholder" style="background:<?php echo $grad; ?>;">
                                <div class="cc-icon"><i class="fas fa-clinic-medical" style="color:<?php echo $icon_color; ?>;font-size:26px;"></i></div>
                            </div>
                        <?php endif; ?>
                        <div class="cc-grad-overlay"></div>
                        <div class="cc-rating"><i class="fas fa-star"></i> <?php echo ($avg > 0 ? $avg : 'New'); ?></div>
                        <?php if ($has3d): ?><div class="cc-3d">3D View</div><?php endif; ?>
                        <div class="cc-city"><?php echo htmlspecialchars($clinic['city'] ?? ''); ?></div>
                    </div>
                    <div class="cc-body">
                        <div class="cc-name"><?php echo htmlspecialchars($clinic['clinic_name']); ?></div>
                        <div class="cc-loc">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($clinic['city'] ?? 'Cavite'); ?>
                        </div>
                        <div class="cc-foot">
                            <div>
                                <span class="cc-stars">
                                    <?php for ($s = 1; $s <= 5; $s++) echo ($s <= round($avg) ? '★' : '☆'); ?>
                                </span>
                                <span class="cc-revs">(<?php echo $clinic['review_count']; ?>)</span>
                            </div>
                            <div class="cc-book">Book</div>
                        </div>
                    </div>
                </a>
                <?php $ci++; endwhile;
                else: ?>
                    <div style="padding:40px;color:var(--text2);text-align:center;width:100%;">
                        <i class="fas fa-clinic-medical" style="font-size:40px;opacity:0.3;margin-bottom:12px;display:block;"></i>
                        No featured clinics yet. Check back soon!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== CITIES ===== -->
    <div class="section section-gap" id="cities">
        <div class="sec-head">
            <div class="sec-title">Explore by City</div>
            <a href="pages/dashboard.php" class="sec-link">See all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="cities-grid">
            <?php foreach ($cities as $idx => $city): ?>
            <a href="pages/dashboard.php?city=<?php echo urlencode($city['city']); ?>" class="city-card">
                <div class="city-bg cb-<?php echo $idx % 8; ?>">
                    <div class="city-name-over"><?php echo htmlspecialchars($city['city']); ?></div>
                </div>
                <div class="city-foot">
                    <div class="city-count-lbl"><?php echo $city['clinic_count']; ?> clinic<?php echo $city['clinic_count'] != 1 ? 's' : ''; ?></div>
                    <div class="city-arr">›</div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== HOW IT WORKS ===== -->
    <div class="how-bg">
        <div class="how-inner">
            <div class="sec-head">
                <div class="sec-title">How it works</div>
            </div>
            <div class="how-grid">
                <div class="how-step">
                    <div class="how-num">1</div>
                    <div class="how-ico"><i class="fas fa-search"></i></div>
                    <div class="how-title">Find a clinic near you</div>
                    <div class="how-desc">Search optical clinics in your city in Cavite. Filter by location, rating, or services offered.</div>
                </div>
                <div class="how-step">
                    <div class="how-num">2</div>
                    <div class="how-ico"><i class="fas fa-calendar-plus"></i></div>
                    <div class="how-title">Book an appointment</div>
                    <div class="how-desc">Choose your preferred doctor, date, and time. Confirmed in seconds — no phone calls needed.</div>
                </div>
                <div class="how-step">
                    <div class="how-num">3</div>
                    <div class="how-ico"><i class="fas fa-check-circle"></i></div>
                    <div class="how-title">Get your eye care done</div>
                    <div class="how-desc">Visit the clinic and explore eyewear with our 3D frame viewer before buying.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 3D FRAME VIEWER CTA ===== -->
    <div class="tryon-section">
        <div class="tryon-inner">
            <div class="tryon-left">
                <div class="tryon-tag">3D Frame Viewer Technology</div>
                <div class="tryon-h2">See every frame<br>in stunning 3D detail.</div>
                <div class="tryon-p">Before booking, explore eyeglass frames in full 360° 3D view. Rotate, zoom in, and inspect every angle — available on select frames from our partner clinics.</div>
                <div class="tryon-features">
                    <div class="tf-item"><div class="tf-dot"></div><div class="tf-text">Rotate frame 360° in any direction</div></div>
                    <div class="tf-item"><div class="tf-dot"></div><div class="tf-text">Zoom in to see frame details & finish</div></div>
                    <div class="tf-item"><div class="tf-dot"></div><div class="tf-text">Switch between available color variants</div></div>
                    <div class="tf-item"><div class="tf-dot"></div><div class="tf-text">Available on <?php echo ($clinics_with_3d ?: 'select'); ?>+ partner clinic frames</div></div>
                </div>
                <a href="pages/dashboard.php" class="tryon-btn">
                    <i class="fas fa-cube"></i> Explore 3D Frames
                </a>
            </div>
            <div class="tryon-right">
                <div class="tryon-viewer-card">
                    <div class="tv-header">
                        <div class="tv-dot" style="background:#FF5F57;"></div>
                        <div class="tv-dot" style="background:#FEBC2E;"></div>
                        <div class="tv-dot" style="background:#28C840;"></div>
                        <div class="tv-title">3D Frame Viewer</div>
                    </div>
                    <div class="tv-body">
                        <div class="tv-frame-display">
                            <!-- 3D Frame SVG illustration -->
                            <svg width="200" height="120" viewBox="0 0 200 120" fill="none" style="display:block;margin:0 auto;">
                                <ellipse cx="58" cy="60" rx="44" ry="36" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="5"/>
                                <ellipse cx="142" cy="60" rx="44" ry="36" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="5"/>
                                <ellipse cx="58" cy="60" rx="26" ry="21" fill="rgba(100,180,255,0.2)"/>
                                <ellipse cx="142" cy="60" rx="26" ry="21" fill="rgba(100,180,255,0.2)"/>
                                <path d="M100 60 Q100 52 100 60" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="4" stroke-linecap="round"/>
                                <line x1="14" y1="52" x2="0" y2="47" stroke="rgba(255,255,255,0.85)" stroke-width="5" stroke-linecap="round"/>
                                <line x1="186" y1="52" x2="200" y2="47" stroke="rgba(255,255,255,0.85)" stroke-width="5" stroke-linecap="round"/>
                                <!-- shadow/depth -->
                                <ellipse cx="100" cy="110" rx="60" ry="6" fill="rgba(0,0,0,0.2)"/>
                                <!-- rotation arrows -->
                                <path d="M18 95 Q10 75 20 55" fill="none" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" stroke-dasharray="3 3"/>
                                <path d="M182 95 Q190 75 180 55" fill="none" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" stroke-dasharray="3 3"/>
                            </svg>
                            <div class="tv-rotate-hint"><i class="fas fa-sync-alt" style="font-size:8px;"></i> Drag to rotate</div>
                        </div>
                        <div class="tv-product-name">Classic Rectangular Frame</div>
                        <div class="tv-product-sub">Full-rim · Anti-radiation lens available</div>
                        <div class="tv-colors">
                            <div class="tv-color active" style="background:#1a1a1a;"></div>
                            <div class="tv-color" style="background:#8B4513;"></div>
                            <div class="tv-color" style="background:#2c5f8a;"></div>
                            <div class="tv-color" style="background:#7a2a2a;"></div>
                            <div class="tv-color" style="background:#silver;background:#C0C0C0;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== FEATURES ===== -->
    <div class="features-bg" id="features">
        <div class="features-inner">
            <div class="sec-head">
                <div class="sec-title">Why choose Eyecore?</div>
            </div>
            <div class="feat-grid">
                <div class="feat-card">
                    <div class="fi fi-g"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="ft">Easy Booking</div>
                        <div class="fd">Schedule with top clinics in a few taps. No phone calls needed — everything online.</div>
                    </div>
                </div>
                <div class="feat-card">
                    <div class="fi fi-b"><i class="fas fa-cube"></i></div>
                    <div>
                        <div class="ft">3D Frame Viewer</div>
                        <div class="fd">Inspect eyeglass frames in full 360° 3D rotation before you book or buy.</div>
                    </div>
                </div>
                <div class="feat-card">
                    <div class="fi fi-a"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="ft">Real Patient Reviews</div>
                        <div class="fd">Honest ratings from verified patients. Know what to expect before you visit.</div>
                    </div>
                </div>
                <div class="feat-card">
                    <div class="fi fi-r"><i class="fas fa-heart"></i></div>
                    <div>
                        <div class="ft">Save Favorites</div>
                        <div class="fd">Bookmark preferred clinics and products for quick access anytime.</div>
                    </div>
                </div>
                <div class="feat-card">
                    <div class="fi fi-t"><i class="fas fa-tags"></i></div>
                    <div>
                        <div class="ft">Hot Deals</div>
                        <div class="fd">Discover exclusive discounts on eyewear and eye care services from partner clinics.</div>
                    </div>
                </div>
                <div class="feat-card">
                    <div class="fi fi-p"><i class="fas fa-map-marked-alt"></i></div>
                    <div>
                        <div class="ft">Explore Map</div>
                        <div class="fd">Find clinics near you using our interactive map. Filter by city across all of Cavite.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== CTA SECTION ===== -->
    <div class="cta-section">
        <div class="cta-inner">
            <div class="cta-h2">Ready to find your perfect<br><span>eye care clinic?</span></div>
            <div class="cta-sub">Join thousands of Caviteños who already found their eye care home on Eyecore. Free to use, always.</div>
            <div class="cta-btns">
                <a href="auth/user_register.php" class="cta-btn-main">
                    <i class="fas fa-user-plus"></i> Create Free Account
                </a>
                <a href="pages/dashboard.php" class="cta-btn-sec">
                    Browse Clinics
                </a>
            </div>
            <div class="cta-trust">
                <div class="ct-item"><i class="fas fa-check-circle"></i> No credit card required</div>
                <div class="ct-item"><i class="fas fa-check-circle"></i> Free for all users</div>
                <div class="ct-item"><i class="fas fa-check-circle"></i> Book in minutes</div>
            </div>
        </div>
    </div>

    <!-- ===== FOOTER ===== -->
    <footer class="footer" id="contact">
        <div class="footer-grid">
            <div>
                <div class="footer-logo">
                    <div class="fl-mark">
                        <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5z"/></svg>
                    </div>
                    <span class="fl-name">eyecore</span>
                </div>
                <div class="footer-p">Your trusted companion for all your eye care needs in Cavite. Find clinics, book appointments, and discover the perfect eyewear.</div>
                <div class="soc-row">
                    <a href="#" class="soc-btn"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="soc-btn"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="soc-btn"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div>
                <div class="fh4">Quick Links</div>
                <ul class="flinks">
                    <li><a href="#features">Features</a></li>
                    <li><a href="#cities">Cities</a></li>
                    <li><a href="#clinics">Clinics</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </div>
            <div>
                <div class="fh4">Cities</div>
                <ul class="flinks">
                    <?php foreach (array_slice($cities, 0, 5) as $city): ?>
                    <li><a href="pages/dashboard.php?city=<?php echo urlencode($city['city']); ?>"><?php echo htmlspecialchars($city['city']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <div class="fh4">Contact</div>
                <ul class="flinks">
                    <li><a href="mailto:eyecore@gmail.com"><i class="fas fa-envelope" style="margin-right:6px;color:var(--green);"></i> eyecore@gmail.com</a></li>
                    <li><i class="fas fa-map-marker-alt" style="margin-right:6px;color:var(--green);"></i> Cavite, Philippines</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="fb-copy">© <?php echo date('Y'); ?> Eyecore. All rights reserved. Your Cavite eye care companion.</div>
            <div class="fb-right"><i class="fas fa-map-marker-alt"></i> Cavite, Philippines</div>
        </div>
    </footer>

    <!-- ===== MOBILE MENU ===== -->
    <div class="mob-overlay" id="mobOverlay" onclick="closeMobMenu()"></div>
    <div class="mob-menu" id="mobMenu">
        <div class="mob-top">
            <div class="logo">
                <div class="logo-mark">
                    <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5z"/></svg>
                </div>
                eyecore
            </div>
            <button onclick="closeMobMenu()" style="background:none;border:none;color:var(--text2);font-size:20px;cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <div class="mob-links">
            <a href="#features" class="mob-link" onclick="closeMobMenu()"><i class="fas fa-star" style="margin-right:8px;color:var(--green);"></i> Features</a>
            <a href="#cities"   class="mob-link" onclick="closeMobMenu()"><i class="fas fa-map-marker-alt" style="margin-right:8px;color:var(--green);"></i> Cities</a>
            <a href="#clinics"  class="mob-link" onclick="closeMobMenu()"><i class="fas fa-clinic-medical" style="margin-right:8px;color:var(--green);"></i> Clinics</a>
            <a href="#contact"  class="mob-link" onclick="closeMobMenu()"><i class="fas fa-envelope" style="margin-right:8px;color:var(--green);"></i> Contact</a>
        </div>
        <div class="mob-divider"></div>
        <div class="mob-btns">
            <a href="auth/user_login.php" class="btn-outline">Login</a>
            <a href="auth/user_register.php" class="btn-primary"><i class="fas fa-user-plus"></i> Sign Up Free</a>
        </div>
    </div>

    <script>
    // ===== SLIDER =====
    let cur = 0, total = <?php echo ($sale_total > 0 ? 3 : 2); ?>, autoTimer;

    function goSlide(n) {
        cur = n;
        document.getElementById('slidesTrack').style.transform = 'translateX(-' + (cur * 100) + '%)';
        document.querySelectorAll('.s-dot').forEach((d, i) => d.classList.toggle('on', i === cur));
    }
    function nextSlide() { goSlide((cur + 1) % total); }
    function prevSlide() { goSlide((cur - 1 + total) % total); }
    function startAuto() { autoTimer = setInterval(nextSlide, 4500); }
    function stopAuto()  { clearInterval(autoTimer); }

    document.getElementById('sliderWrap').addEventListener('mouseenter', stopAuto);
    document.getElementById('sliderWrap').addEventListener('mouseleave', startAuto);

    // Touch support
    let tStart = 0;
    document.getElementById('sliderWrap').addEventListener('touchstart', e => { tStart = e.touches[0].clientX; stopAuto(); });
    document.getElementById('sliderWrap').addEventListener('touchend', e => {
        const diff = tStart - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) diff > 0 ? nextSlide() : prevSlide();
        startAuto();
    });
    startAuto();

    // ===== THEME TOGGLE =====
    function toggleTheme() {
        const html = document.documentElement;
        const icon = document.querySelector('#themeToggle i');
        if (html.classList.contains('theme-dark')) {
            html.classList.remove('theme-dark');
            localStorage.setItem('theme', 'light');
            if (icon) icon.className = 'fas fa-moon';
        } else {
            html.classList.add('theme-dark');
            localStorage.setItem('theme', 'dark');
            if (icon) icon.className = 'fas fa-sun';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const saved = localStorage.getItem('theme') || 'light';
        const icon  = document.querySelector('#themeToggle i');
        if (saved === 'dark') {
            document.documentElement.classList.add('theme-dark');
            if (icon) icon.className = 'fas fa-sun';
        }
    });

    // ===== MOBILE MENU =====
    function openMobMenu() {
        document.getElementById('mobMenu').classList.add('open');
        document.getElementById('mobOverlay').style.display = 'block';
    }
    function closeMobMenu() {
        document.getElementById('mobMenu').classList.remove('open');
        document.getElementById('mobOverlay').style.display = 'none';
    }

    // ===== SMOOTH SCROLL =====
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); closeMobMenu(); }
        });
    });

    // ===== 3D COLOR SWITCHER (UI only demo) =====
    document.querySelectorAll('.tv-color').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tv-color').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    </script>
</body>
</html>