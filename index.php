<?php
// TEST SFTP
// RHAINE RIVERA
// Check if config file exists
session_start();

if (file_exists('includes/config.php')) {
    include 'includes/config.php';
} else {
    die('Config file missing. Please upload the includes folder.');
}

if (file_exists('includes/theme.php')) {
    include 'includes/theme.php';
} else {
    die('Theme file missing. Please upload the includes folder.');
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);

// ============================================
// DATABASE QUERIES
// ============================================

$clinics_count_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM clinics WHERE status = 'Active'");
$clinics_count = mysqli_fetch_assoc($clinics_count_q)['total'] ?? 0;

$rating_q = mysqli_query($conn, "
    SELECT 
        COALESCE(AVG(rating), 0) as avg_rating,
        COUNT(*) as total_reviews
    FROM clinic_reviews
    WHERE status = 'approved'
");
$rating_data = mysqli_fetch_assoc($rating_q);
$avg_rating_formatted = number_format($rating_data['avg_rating'], 1);
$total_reviews = $rating_data['total_reviews'] ?? 0;

$products_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM products");
$products_count = mysqli_fetch_assoc($products_q)['total'] ?? 0;

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

$featured_q = mysqli_query($conn, "
    SELECT 
        c.id, c.clinic_name, c.city, c.address, c.hours, c.clinic_image, c.logo, c.cover_photo,
        COALESCE(AVG(r.rating), 0) as avg_rating,
        COUNT(r.id) as review_count,
        MAX(r.created_at) as latest_review,
        (
            (COALESCE(AVG(r.rating), 0) / 5) * 0.60 +
            (LEAST(COUNT(r.id), 50) / 50) * 0.30 +
            (DATEDIFF(NOW(), COALESCE(MAX(r.created_at), NOW())) / 365) * 0.10
        ) * 100 as featured_score
    FROM clinics c
    LEFT JOIN clinic_reviews r ON c.id = r.clinic_id AND r.status = 'approved'
    WHERE c.status = 'Active'
    GROUP BY c.id
    HAVING review_count >= 3
    ORDER BY featured_score DESC, review_count DESC
    LIMIT 6
");

$has_3d_q = mysqli_query($conn, "
    SELECT COUNT(DISTINCT p.clinic_id) as total 
    FROM product_3d_models pm
    JOIN products p ON pm.product_id = p.id
    WHERE pm.has_3d = 1
");
$clinics_with_3d = mysqli_fetch_assoc($has_3d_q)['total'] ?? 0;

$sale_q = mysqli_query($conn, "
    SELECT p.name, p.price, p.sale_price, p.image,
           ROUND(((p.price - p.sale_price) / p.price) * 100) as discount_pct,
           c.clinic_name
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.is_on_sale = 1 AND p.sale_start <= CURDATE() AND p.sale_end >= CURDATE() AND c.status = 'Active'
    ORDER BY discount_pct DESC
    LIMIT 3
");
$sale_products = [];
while ($row = mysqli_fetch_assoc($sale_q)) {
    $sale_products[] = $row;
}
$sale_total = count($sale_products);

$category_counts = [];
$cat_q = mysqli_query($conn, "
    SELECT p.category, COUNT(*) as product_count
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.category IS NOT NULL AND p.category != '' AND c.status = 'Active'
    GROUP BY p.category
    ORDER BY product_count DESC
");
while ($row = mysqli_fetch_assoc($cat_q)) {
    $cat_key = strtolower($row['category']);
    $category_counts[$cat_key] = $row['product_count'];
}

$category_classes = [
    'eyeglasses' => 'c-eyeglasses', 'sunglasses' => 'c-sunglasses',
    'computer glasses' => 'c-computer', 'contact lens' => 'c-contact',
    'reading' => 'c-reading', 'kids' => 'c-kids',
    'prescription' => 'c-prescription', 'sports' => 'c-sports'
];

function getClinicImage($clinic) {
    if (!empty($clinic['cover_photo'])) return '/assets/images/clinic-covers/' . $clinic['cover_photo'];
    elseif (!empty($clinic['clinic_image'])) return '/assets/images/clinic-images/' . $clinic['clinic_image'];
    elseif (!empty($clinic['logo'])) return '/assets/images/clinic-logos/' . $clinic['logo'];
    return null;
}

$clinic_gradients = [
    'linear-gradient(135deg,#d1fae5,#6ee7b7)',
    'linear-gradient(135deg,#dbeafe,#93c5fd)',
    'linear-gradient(135deg,#fce7f3,#f9a8d4)',
    'linear-gradient(135deg,#ede9fe,#c4b5fd)',
    'linear-gradient(135deg,#fef9c3,#fde047)',
    'linear-gradient(135deg,#ccfbf1,#5eead4)',
];
$clinic_icon_colors = ['#059669','#1d4ed8','#9d174d','#5b21b6','#92400e','#0f766e'];

function getCitySampleClinics($conn, $city, $limit = 3) {
    $result = [];
    $q = mysqli_query($conn, "
        SELECT clinic_name, logo, clinic_image, cover_photo 
        FROM clinics 
        WHERE city = '".mysqli_real_escape_string($conn, $city)."' 
        AND status = 'Active' 
        LIMIT $limit
    ");
    while ($row = mysqli_fetch_assoc($q)) {
        $result[] = $row;
    }
    return $result;
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Eyecore — Cavite's Integrated Optical Clinic Platform</title>
    <link rel="icon" type="image/x-icon" href="assets/images/eyecore_logo.png">
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
        .navbar { position: sticky; top: 0; z-index: 200; background: var(--bg2); border-bottom: 1px solid var(--border-light); width: 100%; }
        .navbar-container { display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 64px; max-width: 1400px; margin: 0 auto; gap: 16px; }
        @media (max-width: 768px) { .navbar-container { padding: 0 16px; height: 58px; gap: 8px; } }
        @media (max-width: 480px) { .navbar-container { padding: 0 12px; height: 54px; } }

        .nav-left { display: flex; align-items: center; gap: 24px; flex-shrink: 0; }
        @media (max-width: 768px) { .nav-left { gap: 12px; } }

        .logo { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 800; color: var(--green); text-decoration: none; letter-spacing: -0.5px; flex-shrink: 0; }
        @media (max-width: 480px) { .logo { font-size: 18px; gap: 6px; } }
        .logo-mark { 
            width: 32px; 
            height: 32px; 
            background: var(--green); 
            border-radius: 9px; /* Mas rounded para kamukha ng app icon */
            display: flex; 
            align-items: center; 
            justify-content: center; 
            flex-shrink: 0;
        }
        .logo-mark svg { 
            width: 22px; 
            height: 22px; 
            display: block;
        }

        /* Pag mobile, bahagyang liliitan */
        @media (max-width: 480px) { 
            .logo-mark { width: 28px; height: 28px; border-radius: 8px; } 
            .logo-mark svg { width: 18px; height: 18px; } 
        }

        .nav-links { display: flex; gap: 4px; }
        @media (max-width: 860px) { .nav-links { display: none; } }
        .nav-link { padding: 6px 12px; border-radius: var(--r-full); font-size: 13px; font-weight: 500; color: var(--text2); text-decoration: none; transition: all 0.15s; white-space: nowrap; }
        .nav-link:hover { color: var(--green); background: var(--green-light); }
        .nav-link-highlight { color: var(--green); font-weight: 700; background: rgba(0, 183, 97, 0.08); }

        .nav-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        @media (max-width: 480px) { .nav-right { gap: 6px; } }

        .icon-btn { width: 36px; height: 36px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-full); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text2); font-size: 14px; transition: all 0.15s; flex-shrink: 0; }
        @media (max-width: 480px) { .icon-btn { width: 32px; height: 32px; font-size: 12px; } }
        .icon-btn:hover { background: var(--green-light); color: var(--green); }

        .btn-outline { padding: 6px 16px; border-radius: var(--r-full); border: 1.5px solid var(--green); background: transparent; color: var(--green); font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        @media (max-width: 480px) { .btn-outline { padding: 5px 12px; font-size: 11px; } .btn-outline i { font-size: 10px; } }
        .btn-outline:hover { background: var(--green); color: white; }

        .btn-primary { padding: 6px 16px; border-radius: var(--r-full); background: var(--green); border: none; color: white; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px; box-shadow: var(--shadow-green); white-space: nowrap; }
        @media (max-width: 480px) { .btn-primary { padding: 5px 12px; font-size: 11px; } .btn-primary i { font-size: 10px; } }
        .btn-primary:hover { background: var(--green-dark); transform: translateY(-1px); }

        .mobile-menu-btn { display: none; background: none; border: none; color: var(--text1); font-size: 20px; cursor: pointer; width: 36px; height: 36px; align-items: center; justify-content: center; border-radius: var(--r-full); transition: background 0.15s; }
        @media (max-width: 860px) { .mobile-menu-btn { display: flex; } }
        @media (max-width: 480px) { .mobile-menu-btn { width: 32px; height: 32px; font-size: 18px; } }
        .mobile-menu-btn:hover { background: var(--border-light); }

        /* ===== HERO SLIDER ===== */
        .slider-wrapper { position: relative; overflow: hidden; width: 100%; background: #000; }
        .slides-track { display: flex; transition: transform 0.55s cubic-bezier(0.77,0,0.175,1); will-change: transform; }
        .slide { min-width: 100%; height: 420px; display: flex; align-items: center; position: relative; overflow: hidden; }
        @media (max-width: 768px) { .slide { height: 350px; } }

        .s1 { background: linear-gradient(115deg, #002d16 0%, #005c2e 45%, #00a85a 100%); }
        .s2 { background: linear-gradient(115deg, #070d1a 0%, #0d2550 50%, #1a4080 100%); }
        .s3 { background: linear-gradient(115deg, #1a0a00 0%, #4a1f00 50%, #7a3800 100%); }

        .slide-inner { max-width: 1200px; margin: 0 auto; width: 100%; padding: 0 60px; display: flex; align-items: center; justify-content: space-between; gap: 40px; }
        @media (max-width: 768px) { .slide-inner { padding: 0 24px; } }

        .slide-text { flex: 1; max-width: 520px; }
        .slide-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; margin-bottom: 16px; letter-spacing: 0.5px; text-transform: uppercase; }
        .sb-green { background: rgba(0, 255, 150, 0.15); color: #7dffc0; border: 1px solid rgba(125, 255, 192, 0.3); }
        .sb-blue  { background: rgba(100, 180, 255, 0.15); color: #80d8ff; border: 1px solid rgba(128, 216, 255, 0.3); }
        .sb-amber { background: rgba(255, 180, 100, 0.15); color: #ffd180; border: 1px solid rgba(255, 209, 128, 0.3); }

        .slide-h1 { font-size: 38px; font-weight: 800; color: white; line-height: 1.15; margin-bottom: 14px; letter-spacing: -1px; }
        @media (max-width: 768px) { .slide-h1 { font-size: 26px; } }

        .slide-sub { font-size: 14px; color: rgba(255,255,255,0.8); line-height: 1.7; margin-bottom: 24px; max-width: 440px; }
        @media (max-width: 768px) { .slide-sub { display: none; } }

        .slide-btns { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .sb-white { padding: 11px 24px; border-radius: var(--r-full); background: white; color: #111; font-size: 13px; font-weight: 700; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s; }
        .sb-white:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
        .sb-ghost { padding: 11px 24px; border-radius: var(--r-full); background: transparent; color: white; font-size: 13px; font-weight: 600; border: 1.5px solid rgba(255,255,255,0.45); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.15s; }
        .sb-ghost:hover { background: rgba(255,255,255,0.12); }

        .slide-note { font-size: 11px; color: rgba(255,255,255,0.55); display: flex; flex-direction: column; gap: 4px; margin-top: 12px; }
        .slide-note span { display: flex; align-items: center; gap: 6px; }
        .slide-note i { font-size: 10px; }

        .slide-visual { flex: 0 0 auto; width: 320px; height: 280px; display: flex; align-items: center; justify-content: center; position: relative; }
        @media (max-width: 768px) { .slide-visual { display: none; } }

        .hero-float { position: absolute; background: rgba(255,255,255,0.13); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); border-radius: var(--r-lg); padding: 10px 16px; color: white; white-space: nowrap; }
        .hf-num { font-size: 17px; font-weight: 700; line-height: 1; margin-bottom: 2px; }
        .hf-lbl { font-size: 10px; opacity: 0.75; }
        .hf1 { top: 20px; right: 0; }
        .hf2 { bottom: 32px; right: 30px; }

        .s-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 10; width: 40px; height: 40px; background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25); border-radius: var(--r-full); display: flex; align-items: center; justify-content: center; cursor: pointer; color: white; font-size: 16px; transition: background 0.15s; }
        .s-arrow:hover { background: rgba(255,255,255,0.28); }
        .s-arr-l { left: 16px; }
        .s-arr-r { right: 16px; }

        .s-dots { position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 10; }
        .s-dot { width: 6px; height: 6px; border-radius: var(--r-full); background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.2s; }
        .s-dot.on { background: white; width: 24px; }

        /* ===== TRUST BAR ===== */
        .trust-bar { background: var(--bg2); border-bottom: 1px solid var(--border-light); }
        .trust-inner { max-width: 1200px; margin: 0 auto; padding: 0 40px; display: grid; grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 768px) { .trust-inner { grid-template-columns: repeat(2, 1fr); padding: 0 16px; } }
        .trust-item { display: flex; align-items: center; gap: 12px; padding: 18px 0; border-right: 1px solid var(--border-light); justify-content: center; }
        .trust-item:last-child { border-right: none; }
        @media (max-width: 768px) { .trust-item:nth-child(2) { border-right: none; } }
        .ti-ico { width: 36px; height: 36px; background: var(--green-light); border-radius: var(--r-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ti-ico i { color: var(--green); font-size: 15px; }
        .ti-num { font-size: 18px; font-weight: 800; color: var(--text1); line-height: 1; }
        .ti-lbl { font-size: 11px; color: var(--text2); margin-top: 2px; }

        /* ===== SECTION WRAPPER ===== */
        .section { max-width: 1200px; margin: 0 auto; padding: 40px 40px 0; }
        @media (max-width: 768px) { .section { padding: 32px 16px 0; } }

        .sec-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }
        .sec-title { font-size: 20px; font-weight: 800; letter-spacing: -0.5px; }
        .sec-link { font-size: 12px; color: var(--green); font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 4px; }
        .sec-link:hover { text-decoration: underline; }

        /* ===== MODULES ===== */
        .modules-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
            margin-top: 16px;
        }
        @media (max-width: 1024px) { .modules-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) { .modules-grid { grid-template-columns: 1fr; } }
        
        .module-card {
            background: var(--card); border: 1px solid var(--border-light);
            border-radius: var(--r-xl); padding: 24px; text-align: center;
            transition: all 0.25s;
        }
        .module-card:hover {
            transform: translateY(-6px); box-shadow: var(--shadow-md);
            border-color: var(--green);
        }
        .module-icon {
            width: 56px; height: 56px; border-radius: var(--r-lg);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px; font-size: 24px;
        }
        .module-name { font-size: 15px; font-weight: 800; margin-bottom: 12px; color: var(--text1); }
        .module-features {
            list-style: none; text-align: left; padding: 0;
            display: flex; flex-direction: column; gap: 6px;
        }
        .module-features li {
            font-size: 12px; color: var(--text2); display: flex; align-items: flex-start; gap: 6px;
        }
        .module-features li i {
            color: var(--green); font-size: 10px; margin-top: 4px; flex-shrink: 0;
        }

        /* ===== TUTORIAL SECTION ===== */
        .tut-tabs {
            display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap;
        }
        .tut-tab {
            padding: 8px 20px; border-radius: var(--r-full);
            background: var(--card); border: 1.5px solid var(--border);
            font-size: 13px; font-weight: 600; cursor: pointer;
            color: var(--text2); transition: all 0.15s;
            font-family: var(--font);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .tut-tab:hover { border-color: var(--green); color: var(--green); }
        .tut-tab.active {
            background: var(--green); color: white; border-color: var(--green);
            box-shadow: var(--shadow-green);
        }
        .tut-panel {
            display: grid; grid-template-columns: 1fr 1fr; gap: 24px;
        }
        @media (max-width: 768px) {
            .tut-panel { grid-template-columns: 1fr; }
        }
        .tut-video-wrap {
            border-radius: var(--r-lg); overflow: hidden;
            border: 1px solid var(--border-light);
            background: #000; position: relative;
            aspect-ratio: 16/9;
            box-shadow: var(--shadow-md);
        }
        .tut-video-wrap video {
            width: 100%; height: 100%; display: block; object-fit: cover;
        }
        .tut-video-placeholder {
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
            color: rgba(255,255,255,0.5); text-align: center; padding: 20px;
            gap: 12px;
        }
        .tut-video-placeholder i { font-size: 42px; opacity: 0.5; }
        .tut-video-placeholder p { font-size: 12px; line-height: 1.5; }
        .tut-video-placeholder code {
            font-size: 10px; background: rgba(255,255,255,0.08);
            padding: 3px 8px; border-radius: 4px; color: #7dffc0;
            margin-top: 4px; display: inline-block;
        }
        .tut-steps-title {
            font-size: 16px; font-weight: 800; margin-bottom: 16px;
            color: var(--text1);
            display: flex; align-items: center; gap: 8px;
        }
        .tut-steps-title i {
            width: 32px; height: 32px; border-radius: var(--r-md);
            background: var(--green-light); color: var(--green);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .tut-step {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--border-light);
            font-size: 13px; color: var(--text1); line-height: 1.5;
        }
        .tut-step:last-child { border-bottom: none; }
        .tut-step strong { font-weight: 700; display: block; margin-bottom: 2px; }
        .tut-step span.tut-desc { font-size: 12px; color: var(--text2); font-weight: 400; }
        .tut-num {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--green-light); color: var(--green);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 800; flex-shrink: 0;
            margin-top: 1px;
        }

        /* ===== DOWNLOAD APP SECTION ===== */
        .download-section {
            background: linear-gradient(135deg, #0a1f14 0%, #003d20 50%, #006633 100%);
            padding: 60px 40px; margin-top: 40px;
            position: relative; overflow: hidden;
        }
        .download-section::before {
            content: ''; position: absolute; top: -50%; right: -10%;
            width: 500px; height: 500px; border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 230, 118, 0.15) 0%, transparent 70%);
        }
        @media (max-width: 768px) { .download-section { padding: 40px 16px; } }
        .download-inner {
            max-width: 1000px; margin: 0 auto;
            display: flex; align-items: center; gap: 60px;
            position: relative; z-index: 2;
        }
        @media (max-width: 768px) { .download-inner { flex-direction: column; gap: 32px; text-align: center; } }
        .download-text { flex: 1; }
        .download-eyebrow { font-size: 12px; color: #7dffc0; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .download-h2 { font-size: 32px; font-weight: 800; color: white; line-height: 1.2; margin-bottom: 14px; letter-spacing: -0.5px; }
        @media (max-width: 768px) { .download-h2 { font-size: 24px; } }
        .download-p { font-size: 14px; color: rgba(255,255,255,0.75); line-height: 1.7; margin-bottom: 24px; max-width: 440px; }
        @media (max-width: 768px) { .download-p { margin-left: auto; margin-right: auto; } }
        
        .download-btns { display: flex; gap: 12px; flex-wrap: wrap; }
        @media (max-width: 768px) { .download-btns { justify-content: center; } }
        .apk-btn {
            padding: 14px 28px; border-radius: var(--r-full);
            background: white; color: #003d20; font-size: 14px; font-weight: 800;
            cursor: pointer; border: none; text-decoration: none;
            display: inline-flex; align-items: center; gap: 10px;
            transition: all 0.15s; box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        .apk-btn:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,0.4); }
        .apk-btn i { font-size: 20px; color: #3DDC84; }
        
        .download-visual {
            flex: 0 0 240px;
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        @media (max-width: 768px) { .download-visual { display: none; } }
        
        .phone-mockup {
            width: 200px; height: 380px;
            background: linear-gradient(145deg, #1a1a1a, #0a0a0a);
            border-radius: 32px; border: 8px solid #222;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            position: relative; overflow: hidden;
            transform: rotate(-4deg);
            transition: transform 0.3s;
        }
        .phone-mockup:hover { transform: rotate(0deg) translateY(-6px); }
        .phone-mockup::before {
            content: ''; position: absolute; top: 0; left: 50%;
            transform: translateX(-50%); width: 70px; height: 20px;
            background: #222; border-radius: 0 0 14px 14px; z-index: 2;
        }
        .phone-screen {
            width: 100%; height: 100%; background: linear-gradient(180deg, #00B761 0%, #003d20 100%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 20px; text-align: center; color: white;
        }
        .phone-screen i { font-size: 48px; margin-bottom: 14px; color: #7dffc0; }
        .phone-screen .app-name { font-size: 18px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.3px; }
        .phone-screen .app-sub { font-size: 11px; opacity: 0.7; }

        /* ===== CATEGORY TILES ===== */
        .cat-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; }
        @media (max-width: 1024px) { .cat-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 640px)  { .cat-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; } }

        .cat-tile { border-radius: var(--r-lg); overflow: hidden; cursor: pointer; text-decoration: none; display: block; transition: transform 0.2s; }
        .cat-tile:hover { transform: translateY(-4px); }

        .cat-img { aspect-ratio: 1/1; display: flex; align-items: center; justify-content: center; position: relative; }
        .c-eyeglasses { background: linear-gradient(150deg,#0d1a2e,#1a3a6e); }
        .c-sunglasses  { background: linear-gradient(150deg,#1a0a00,#5a2500); }
        .c-computer    { background: linear-gradient(150deg,#001820,#004466); }
        .c-contact     { background: linear-gradient(150deg,#12002e,#3d0080); }
        .c-kids        { background: linear-gradient(150deg,#1a1500,#665000); }
        .c-reading     { background: linear-gradient(150deg,#001a08,#004d1a); }
        .c-prescription { background: linear-gradient(150deg,#1a0033,#4d0099); }
        .c-sports      { background: linear-gradient(150deg,#1a0a00,#cc4400); }
        .c-default     { background: linear-gradient(150deg,#1a1a2e,#2d2d6e); }

        .cat-label { padding: 8px 10px; background: var(--card); border: 1px solid var(--border-light); border-top: none; }
        .cat-name { font-size: 11px; font-weight: 700; color: var(--text1); text-transform: capitalize; }
        .cat-sub  { font-size: 10px; color: var(--text2); margin-top: 1px; }

        /* ===== DUAL BANNER ===== */
        .dual-banner { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 640px) { .dual-banner { grid-template-columns: 1fr; } }

        .banner-card { border-radius: var(--r-xl); overflow: hidden; height: 148px; position: relative; display: flex; align-items: center; padding: 28px 28px; cursor: pointer; text-decoration: none; transition: transform 0.2s; }
        .banner-card:hover { transform: translateY(-3px); }
        .bn-green  { background: linear-gradient(120deg, #003d20 0%, #006633 55%, #00a854 100%); }
        .bn-dark   { background: linear-gradient(120deg, #060f20 0%, #0d2855 55%, #1a3f80 100%); }

        .bn-text { position: relative; z-index: 2; }
        .bn-eyebrow { font-size: 10px; color: rgba(255,255,255,0.65); font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 6px; }
        .bn-title { font-size: 20px; font-weight: 800; color: white; line-height: 1.2; margin-bottom: 12px; letter-spacing: -0.3px; }
        .bn-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; background: white; border-radius: var(--r-full); font-size: 11px; font-weight: 700; color: #111; border: none; cursor: pointer; }

        .bn-deco { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); opacity: 0.25; font-size: 80px; }

        /* ===== FEATURED CLINICS ===== */
        .clinics-scroll { overflow-x: auto; scrollbar-width: none; margin: 0 -40px; padding: 4px 40px 16px; }
        .clinics-scroll::-webkit-scrollbar { display: none; }
        @media (max-width: 768px) { .clinics-scroll { margin: 0 -16px; padding: 4px 16px 16px; } }

        .clinics-track { display: flex; gap: 14px; }

        .clinic-card { flex-shrink: 0; width: 220px; background: var(--card); border: 1px solid var(--border-light); border-radius: var(--r-xl); overflow: hidden; cursor: pointer; text-decoration: none; transition: all 0.25s; display: block; }
        .clinic-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-md); border-color: var(--green); }

        .cc-img { height: 130px; position: relative; display: flex; align-items: center; justify-content: center; }
        .cc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cc-img-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
        .cc-icon { width: 54px; height: 54px; background: rgba(255,255,255,0.85); border-radius: 16px; display: flex; align-items: center; justify-content: center; }
        .cc-icon i { font-size: 26px; }
        .cc-grad-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.32) 0%, transparent 55%); }
        .cc-rating { position: absolute; top: 9px; right: 9px; background: rgba(0,0,0,0.6); color: white; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: var(--r-full); display: flex; align-items: center; gap: 3px; }
        .cc-rating i { color: var(--warn); font-size: 9px; }
        .cc-3d { position: absolute; top: 9px; left: 9px; background: var(--green); color: white; font-size: 9px; font-weight: 700; padding: 3px 9px; border-radius: var(--r-full); }
        .cc-city { position: absolute; bottom: 9px; left: 9px; color: white; font-size: 10px; font-weight: 600; }

        .cc-body { padding: 12px 14px 14px; }
        .cc-name { font-size: 13px; font-weight: 700; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cc-loc { font-size: 11px; color: var(--text2); margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
        .cc-loc i { color: var(--green); font-size: 10px; }
        .cc-foot { display: flex; align-items: center; justify-content: space-between; }
        .cc-stars { color: var(--warn); font-size: 11px; }
        .cc-revs { font-size: 10px; color: var(--text2); margin-left: 3px; }
        .cc-book { font-size: 11px; font-weight: 700; color: var(--green); background: var(--green-light); padding: 5px 12px; border-radius: var(--r-full); }

        /* ===== CITIES GRID ===== */
        .cities-grid-enhanced { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        @media (max-width: 1024px) { .cities-grid-enhanced { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px)  { .cities-grid-enhanced { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px)  { .cities-grid-enhanced { grid-template-columns: 1fr; } }

        .city-card-enhanced { border-radius: var(--r-lg); overflow: hidden; cursor: pointer; text-decoration: none; display: block; transition: all 0.3s ease; border: 1px solid var(--border-light); background: var(--card); }
        .city-card-enhanced:hover { transform: translateY(-6px); box-shadow: var(--shadow-md); border-color: var(--green); }

        .city-bg { height: 110px; display: flex; flex-direction: column; justify-content: space-between; padding: 14px 16px 12px; position: relative; transition: all 0.3s; }
        .city-name-over { font-size: 16px; font-weight: 800; color: white; letter-spacing: -0.3px; text-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .city-clinic-previews { display: flex; gap: 6px; align-items: center; }
        .city-clinic-avatar { width: 28px; height: 28px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.8); background-size: cover; background-position: center; background-color: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; flex-shrink: 0; }
        .city-clinic-avatar i { font-size: 12px; opacity: 0.7; }
        .city-more { background: rgba(255,255,255,0.25); backdrop-filter: blur(4px); font-weight: 700; font-size: 10px; }

        .city-foot { padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; background: var(--card); }
        .city-count-lbl { font-size: 12px; color: var(--text2); display: flex; align-items: center; gap: 6px; }
        .city-count-lbl i { color: var(--green); }
        .city-arr { font-size: 12px; color: var(--green); font-weight: 700; display: flex; align-items: center; gap: 4px; transition: all 0.2s; }
        .city-card-enhanced:hover .city-arr { transform: translateX(4px); }

        .cb-0{background:linear-gradient(150deg,#003d20,#00874a);} .cb-1{background:linear-gradient(150deg,#0d2550,#1a4d99);}
        .cb-2{background:linear-gradient(150deg,#3d0052,#800080);} .cb-3{background:linear-gradient(150deg,#2d2d00,#666600);}
        .cb-4{background:linear-gradient(150deg,#3d1a00,#804000);} .cb-5{background:linear-gradient(150deg,#001a33,#003d66);}
        .cb-6{background:linear-gradient(150deg,#1a003d,#3d007a);} .cb-7{background:linear-gradient(150deg,#001a0d,#004d26);}

        /* ===== 3D VIEWER SECTION ===== */
        .tryon-section { background: linear-gradient(130deg, #060f1e 0%, #0b2540 45%, #082d1a 100%); padding: 60px 40px; margin-top: 40px; }
        @media (max-width: 768px) { .tryon-section { padding: 40px 16px; } }
        .tryon-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; gap: 60px; }
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
        .tryon-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: white; color: #060f1e; border-radius: var(--r-full); font-size: 13px; font-weight: 800; cursor: pointer; border: none; text-decoration: none; transition: all 0.15s; }
        .tryon-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }

        .tryon-right { flex: 0 0 320px; }
        @media (max-width: 768px) { .tryon-right { flex: auto; width: 100%; } }

        .tryon-viewer-card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--r-xl); overflow: hidden; }
        .tv-header { background: rgba(255,255,255,0.05); padding: 14px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 8px; }
        .tv-dot { width: 8px; height: 8px; border-radius: 50%; }
        .tv-title { font-size: 11px; color: rgba(255,255,255,0.6); margin-left: 4px; font-weight: 600; }
        .tv-body { padding: 28px 20px; text-align: center; }
        .tv-frame-display { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: var(--r-lg); padding: 28px 20px; margin-bottom: 16px; position: relative; }
        .tv-rotate-hint { position: absolute; bottom: 8px; right: 10px; font-size: 9px; color: rgba(255,255,255,0.35); display: flex; align-items: center; gap: 3px; }
        .tv-product-name { font-size: 12px; color: rgba(255,255,255,0.8); font-weight: 600; margin-bottom: 4px; }
        .tv-product-sub  { font-size: 10px; color: rgba(255,255,255,0.45); margin-bottom: 14px; }
        .tv-colors { display: flex; gap: 7px; justify-content: center; }
        .tv-color { width: 16px; height: 16px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; transition: border 0.15s; }
        .tv-color.active { border-color: rgba(255,255,255,0.7); }

        /* ===== FOOTER ===== */
        .footer { background: var(--bg2); border-top: 1px solid var(--border-light); padding: 48px 40px 24px; }
        @media (max-width: 768px) { .footer { padding: 40px 16px 20px; } }
        .footer-grid { max-width: 1200px; margin: 0 auto 36px; display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 32px; }
        @media (max-width: 1024px) { .footer-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px)  { .footer-grid { grid-template-columns: 1fr; } }

        .footer-logo { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .fl-mark { width: 28px; height: 28px; background: var(--green); border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .fl-mark svg { width: 14px; height: 14px; fill: white; }
        .fl-name { font-size: 18px; font-weight: 800; color: var(--green); letter-spacing: -0.4px; }
        .footer-p { font-size: 13px; color: var(--text2); line-height: 1.7; margin-bottom: 18px; }
        .soc-row { display: flex; gap: 8px; }
        .soc-btn { width: 34px; height: 34px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-full); display: flex; align-items: center; justify-content: center; color: var(--text2); font-size: 13px; text-decoration: none; transition: all 0.15s; }
        .soc-btn:hover { background: var(--green); color: white; border-color: var(--green); }
        .fh4 { font-size: 12px; font-weight: 800; color: var(--text1); margin-bottom: 14px; letter-spacing: 0.5px; text-transform: uppercase; }
        .flinks { list-style: none; display: flex; flex-direction: column; gap: 9px; }
        .flinks li a { font-size: 13px; color: var(--text2); text-decoration: none; transition: color 0.15s; }
        .flinks li a:hover { color: var(--green); }
        .footer-bottom { max-width: 1200px; margin: 0 auto; border-top: 1px solid var(--border-light); padding-top: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        
        .fb-copy { font-size: 12px; color: var(--text3); }
        .fb-researchers { font-size: 12px; color: var(--text2); font-weight: 500; margin-top: 4px; }
        .fb-right { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text2); }
        .fb-right i { color: var(--green); font-size: 11px; }

        /* ===== MOBILE MENU ===== */
        .mob-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 998; }
        .mob-menu { position: fixed; top: 0; right: -300px; width: 280px; height: 100vh; background: var(--bg2); z-index: 999; overflow-y: auto; transition: right 0.3s ease; box-shadow: var(--shadow-lg); }
        .mob-menu.open { right: 0; }
        .mob-top { display: flex; align-items: center; justify-content: space-between; padding: 20px; border-bottom: 1px solid var(--border-light); }
        .mob-links { padding: 16px; display: flex; flex-direction: column; gap: 4px; }
        .mob-link { padding: 12px 14px; border-radius: var(--r-md); font-size: 14px; font-weight: 500; color: var(--text1); text-decoration: none; transition: all 0.15s; display: flex; align-items: center; gap: 12px; }
        .mob-link i { width: 20px; color: var(--green); }
        .mob-link:hover { background: var(--green-light); color: var(--green); }
        .mob-divider { height: 1px; background: var(--border-light); margin: 10px 16px; }
        .mob-btns { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
        .mob-btns a { text-align: center; justify-content: center; width: 100%; }

        .section-gap { padding-top: 40px; }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
<nav class="navbar">
    <div class="navbar-container">
        <div class="nav-left">
            <a href="index.php" class="logo">
                <div class="logo-mark">
                    <!-- Bagong Eye Icon (Tugma sa picture) -->
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <ellipse cx="12" cy="12" rx="9" ry="5.5" fill="white"/>
                        <circle cx="12" cy="12" r="3.5" fill="#00B761"/>
                        <circle cx="12" cy="12" r="1.5" fill="white"/>
                    </svg>
                </div>
                eyecore
            </a>
            <div class="nav-links">
                <a href="#modules" class="nav-link">Modules</a>
                <a href="#tutorial" class="nav-link">Tutorial</a>
                <a href="#download" class="nav-link">Download App</a>
                <a href="#clinics" class="nav-link">Clinics</a>
                <a href="#contact" class="nav-link">Contact</a>
                <a href="admin/login.php" class="nav-link nav-link-highlight">Partner With Us</a>
                <a href="suppliers/supplier_login.php?register=1" class="nav-link">Supplier</a>
            </div>
        </div>
        <div class="nav-right">
            <button class="icon-btn" onclick="toggleTheme()" id="themeToggle">
                <i class="fas fa-moon"></i>
            </button>
            <?php if ($is_logged_in): ?>
                <a href="pages/dashboard.php" class="btn-primary"><i class="fas fa-user"></i> Login</a>
                <a href="auth/logout.php" class="btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php else: ?>
                <a href="auth/user_login.php" class="btn-outline"><i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="auth/user_register.php" class="btn-primary"><i class="fas fa-user-plus"></i> Sign Up</a>
            <?php endif; ?>
            <button class="mobile-menu-btn" onclick="openMobMenu()"><i class="fas fa-bars"></i></button>
        </div>
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
                            Cavite's #1 Integrated Optical Platform
                        </div>
                        <div class="slide-h1">One Platform.<br>Every Clinic Need.</div>
                        <div class="slide-sub">From patient bookings to supply chain management — Eyecore empowers optical clinics with an integrated system, complete with 3D frame viewer and mobile app.</div>
                        <div class="slide-btns">
                            <a href="admin/login.php" class="sb-white">
                                <i class="fas fa-desktop"></i> Clinic Web Portal
                            </a>
                            <a href="#download" class="sb-ghost">
                                <i class="fab fa-android"></i> Mobile App (APK)
                            </a>
                        </div>
                        <div class="slide-note">
                            <span><i class="fas fa-check-circle"></i> Seamless integration across Web & Mobile</span>
                            <span><i class="fas fa-check-circle"></i> Complete ERP for Optical Clinics</span>
                        </div>
                    </div>
                    <div class="slide-visual">
                        <svg width="300" height="260" viewBox="0 0 300 260" fill="none">
                            <ellipse cx="94" cy="136" rx="72" ry="58" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="8"/>
                            <ellipse cx="206" cy="136" rx="72" ry="58" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="8"/>
                            <ellipse cx="94" cy="136" rx="44" ry="36" fill="rgba(0,220,130,0.2)"/>
                            <ellipse cx="206" cy="136" rx="44" ry="36" fill="rgba(0,220,130,0.2)"/>
                            <path d="M166 136 Q150 126 134 136" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="7" stroke-linecap="round"/>
                            <line x1="22" y1="122" x2="0" y2="112" stroke="rgba(255,255,255,0.9)" stroke-width="7" stroke-linecap="round"/>
                            <line x1="278" y1="122" x2="300" y2="112" stroke="rgba(255,255,255,0.9)" stroke-width="7" stroke-linecap="round"/>
                            <circle cx="78" cy="122" r="11" fill="rgba(255,255,255,0.2)"/>
                            <circle cx="190" cy="122" r="11" fill="rgba(255,255,255,0.2)"/>
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
                            <a href="pages/explore-3d.php" class="sb-white">
                                <i class="fas fa-cube"></i> Explore 3D Frames
                            </a>
                            <a href="auth/user_register.php" class="sb-ghost">Sign Up Free</a>
                        </div>
                    </div>
                    <div class="slide-visual">
                        <svg width="260" height="220" viewBox="0 0 260 220" fill="none">
                            <rect x="80" y="60" width="100" height="80" rx="6" fill="rgba(255,255,255,0.05)" stroke="rgba(100,180,255,0.6)" stroke-width="1.5"/>
                            <rect x="94" y="72" width="72" height="56" rx="4" fill="rgba(100,180,255,0.1)" stroke="rgba(100,180,255,0.4)" stroke-width="1"/>
                            <ellipse cx="105" cy="100" rx="18" ry="14" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3.5"/>
                            <ellipse cx="155" cy="100" rx="18" ry="14" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3.5"/>
                            <ellipse cx="105" cy="100" rx="10" ry="8" fill="rgba(100,180,255,0.25)"/>
                            <ellipse cx="155" cy="100" rx="10" ry="8" fill="rgba(100,180,255,0.25)"/>
                            <path d="M123 100 Q130 96 137 100" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <line x1="87" y1="94" x2="80" y2="91" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <line x1="173" y1="94" x2="180" y2="91" stroke="rgba(255,255,255,0.85)" stroke-width="3" stroke-linecap="round"/>
                            <path d="M65 130 Q50 110 65 90" fill="none" stroke="rgba(100,180,255,0.6)" stroke-width="1.5" stroke-dasharray="3 3"/>
                            <path d="M195 130 Q210 110 195 90" fill="none" stroke="rgba(100,180,255,0.6)" stroke-width="1.5" stroke-dasharray="3 3"/>
                            <text x="130" y="165" text-anchor="middle" fill="rgba(255,255,255,0.4)" font-size="10" font-family="sans-serif">⟳ Drag to rotate</text>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3: Hot Deals -->
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
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="s-arrow s-arr-l" onclick="prevSlide()"><i class="fas fa-chevron-left"></i></div>
        <div class="s-arrow s-arr-r" onclick="nextSlide()"><i class="fas fa-chevron-right"></i></div>
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

    <!-- ===== CATEGORIES ===== -->
    <div class="section section-gap">
        <div class="sec-head">
            <div class="sec-title">Shop by Category</div>
            <a href="pages/dashboard.php" class="sec-link">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="cat-grid">
            <?php if (!empty($category_counts)): ?>
                <?php foreach ($category_counts as $cat_key => $count): 
                    $cat_display = ucwords($cat_key);
                    $cat_class = isset($category_classes[$cat_key]) ? $category_classes[$cat_key] : 'c-default';
                ?>
                <a href="pages/dashboard.php?category=<?php echo urlencode($cat_key); ?>" class="cat-tile">
                    <div class="cat-img <?php echo $cat_class; ?>">
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
                        <div class="cat-name"><?php echo $cat_display; ?></div>
                        <div class="cat-sub"><?php echo $count; ?> products</div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
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
            <span class="sec-link" style="font-size:11px;color:var(--text3);font-weight:400;">
                <i class="fas fa-star" style="color:var(--warn);"></i> 
                Top rated by patients
            </span>
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

    <!-- ===== CITIES - ENHANCED UI ===== -->
    <div class="section section-gap" id="cities">
        <div class="sec-head">
            <div class="sec-title">Explore by City</div>
            <a href="pages/dashboard.php" class="sec-link">See all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="cities-grid-enhanced">
            <?php foreach ($cities as $idx => $city): 
                $sample_clinics = getCitySampleClinics($conn, $city['city'], 3);
            ?>
            <a href="pages/dashboard.php?city=<?php echo urlencode($city['city']); ?>" class="city-card-enhanced">
                <div class="city-bg cb-<?php echo $idx % 8; ?>">
                    <div class="city-name-over"><?php echo htmlspecialchars($city['city']); ?></div>
                    <div class="city-clinic-previews">
                        <?php foreach ($sample_clinics as $sc): 
                            $img = getClinicImage($sc);
                        ?>
                        <div class="city-clinic-avatar" style="background-image: url('<?php echo $img ? htmlspecialchars($img) : ''; ?>');">
                            <?php if (!$img): ?>
                            <i class="fas fa-clinic-medical"></i>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($city['clinic_count'] > 3): ?>
                        <div class="city-clinic-avatar city-more">+<?php echo $city['clinic_count'] - 3; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="city-foot">
                    <div class="city-count-lbl">
                        <i class="fas fa-clinic-medical"></i> 
                        <?php echo $city['clinic_count']; ?> clinic<?php echo $city['clinic_count'] != 1 ? 's' : ''; ?>
                    </div>
                    <div class="city-arr">Explore <i class="fas fa-arrow-right"></i></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- ===== POWERFUL MODULES ===== -->
    <div class="section section-gap" id="modules" style="padding-top: 60px;">
        <div class="sec-head">
            <div class="sec-title">Powerful Clinic Management Modules</div>
        </div>
        <p style="font-size:14px;color:var(--text2);margin-bottom:20px;max-width:720px;">
            Everything your optical clinic needs to operate smoothly — from patient care and finance to inventory and payroll. All in one platform.
        </p>
        <div class="modules-grid">
            <div class="module-card">
                <div class="module-icon" style="background:#EFF6FF;color:#1D4ED8;"><i class="fas fa-cog"></i></div>
                <div class="module-name">Administration</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Dashboard & Analytics</li>
                    <li><i class="fas fa-check-circle"></i> Reports & Activity Logs</li>
                    <li><i class="fas fa-check-circle"></i> System Settings & Permissions</li>
                </ul>
            </div>
            <div class="module-card">
                <div class="module-icon" style="background:#F0FDFA;color:#0F766E;"><i class="fas fa-eye"></i></div>
                <div class="module-name">Optical</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Patient Consultation</li>
                    <li><i class="fas fa-check-circle"></i> Decision Support System</li>
                    <li><i class="fas fa-check-circle"></i> 3D Frame Viewer</li>
                </ul>
            </div>
            <div class="module-card">
                <div class="module-icon" style="background:#FFF1F2;color:#BE123C;"><i class="fas fa-headset"></i></div>
                <div class="module-name">Customer Care</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Support Center & Messages</li>
                    <li><i class="fas fa-check-circle"></i> Appointments & Patient Records</li>
                    <li><i class="fas fa-check-circle"></i> PWD/Senior Verification</li>
                    <li><i class="fas fa-check-circle"></i> Order Management & Reviews</li>
                </ul>
            </div>
            <div class="module-card">
                <div class="module-icon" style="background:#FFFBEB;color:#B45309;"><i class="fas fa-coins"></i></div>
                <div class="module-name">Finance & Payments</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Sales & Billing</li>
                    <li><i class="fas fa-check-circle"></i> Payment Configuration</li>
                    <li><i class="fas fa-check-circle"></i> Expenses Tracking</li>
                </ul>
            </div>
            <div class="module-card">
                <div class="module-icon" style="background:#F5F3FF;color:#6D28D9;"><i class="fas fa-boxes"></i></div>
                <div class="module-name">Supply Chain</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Inventory & Products Catalog</li>
                    <li><i class="fas fa-check-circle"></i> Purchase Requests & Orders</li>
                    <li><i class="fas fa-check-circle"></i> Supplier Management</li>
                </ul>
            </div>
            <div class="module-card">
                <div class="module-icon" style="background:#E3FCE9;color:#007A40;"><i class="fas fa-users"></i></div>
                <div class="module-name">Human Resources</div>
                <ul class="module-features">
                    <li><i class="fas fa-check-circle"></i> Employee Records & Roles</li>
                    <li><i class="fas fa-check-circle"></i> Attendance & Schedules</li>
                    <li><i class="fas fa-check-circle"></i> Payroll & Payslips</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ===== TUTORIAL / HOW TO USE SECTION ===== -->
    <div class="section section-gap" id="tutorial" style="padding-top: 60px;">
        <div class="sec-head">
            <div class="sec-title">How to Use Eyecore</div>
        </div>
        <p style="font-size:14px;color:var(--text2);margin-bottom:24px;max-width:720px;">
            Watch a quick video guide or follow the step-by-step instructions below. Choose your role to get started.
        </p>

        <div class="tut-tabs">
            <button class="tut-tab active" onclick="switchTut('patient', this)">
                <i class="fas fa-user"></i> For Patients
            </button>
            <button class="tut-tab" onclick="switchTut('clinic', this)">
                <i class="fas fa-clinic-medical"></i> For Clinics
            </button>
            <button class="tut-tab" onclick="switchTut('supplier', this)">
                <i class="fas fa-boxes"></i> For Suppliers
            </button>
        </div>

        <!-- PATIENT PANEL -->
        <div class="tut-panel" id="tut-patient">
            <div class="tut-video-wrap">
                <?php
                $patient_video = 'assets/videos/patient-tutorial.mp4';
                if (file_exists($patient_video)):
                ?>
                    <video controls preload="metadata" poster="assets/images/patient-thumb.jpg">
                        <source src="<?php echo $patient_video; ?>" type="video/mp4">
                        Your browser does not support video.
                    </video>
                <?php else: ?>
                    <div class="tut-video-placeholder">
                        <i class="fas fa-video"></i>
                        <p><strong>Video Tutorial: For Patients</strong></p>
                        <p>Upload the video to:<br><code>assets/videos/patient-tutorial.mp4</code></p>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="tut-steps-title">
                    <i class="fas fa-list-ol"></i>
                    Steps for Patients
                </div>
                <div class="tut-step">
                    <span class="tut-num">1</span>
                    <div>
                        <strong>Create a free account</strong>
                        <span class="tut-desc">Go to the Sign Up page and register using your email.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">2</span>
                    <div>
                        <strong>Log in to your account</strong>
                        <span class="tut-desc">Use your email and password to sign in.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">3</span>
                    <div>
                        <strong>Browse clinics by city or category</strong>
                        <span class="tut-desc">Search for optical clinics in Cavite.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">4</span>
                    <div>
                        <strong>View 3D frames</strong>
                        <span class="tut-desc">Rotate and zoom eyeglass frames before visiting.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">5</span>
                    <div>
                        <strong>Book an appointment</strong>
                        <span class="tut-desc">Choose your preferred date, time, and doctor.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">6</span>
                    <div>
                        <strong>Leave a review</strong>
                        <span class="tut-desc">Rate the clinic after your visit to help other patients.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- CLINIC PANEL -->
        <div class="tut-panel" id="tut-clinic" style="display:none;">
            <div class="tut-video-wrap">
                <?php
                $clinic_video = 'assets/videos/clinic-tutorial.mp4';
                if (file_exists($clinic_video)):
                ?>
                    <video controls preload="metadata" poster="assets/images/clinic-thumb.jpg">
                        <source src="<?php echo $clinic_video; ?>" type="video/mp4">
                        Your browser does not support video.
                    </video>
                <?php else: ?>
                    <div class="tut-video-placeholder">
                        <i class="fas fa-video"></i>
                        <p><strong>Video Tutorial: For Clinics</strong></p>
                        <p>Upload the video to:<br><code>assets/videos/clinic-tutorial.mp4</code></p>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="tut-steps-title">
                    <i class="fas fa-list-ol"></i>
                    Steps for Clinics
                </div>
                <div class="tut-step">
                    <span class="tut-num">1</span>
                    <div>
                        <strong>Log in to the admin portal</strong>
                        <span class="tut-desc">Go to Admin Login and sign in.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">2</span>
                    <div>
                        <strong>Add products to your catalog</strong>
                        <span class="tut-desc">Upload the eyewear products you sell.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">3</span>
                    <div>
                        <strong>Upload 3D models</strong>
                        <span class="tut-desc">Upload 3D frame files for the 3D viewer.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">4</span>
                    <div>
                        <strong>Manage patient appointments</strong>
                        <span class="tut-desc">Approve, reschedule, or cancel bookings.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">5</span>
                    <div>
                        <strong>Track sales & inventory</strong>
                        <span class="tut-desc">Monitor sales, stock, and reports on your dashboard.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SUPPLIER PANEL -->
        <div class="tut-panel" id="tut-supplier" style="display:none;">
            <div class="tut-video-wrap">
                <?php
                $supplier_video = 'assets/videos/supplier-tutorial.mp4';
                if (file_exists($supplier_video)):
                ?>
                    <video controls preload="metadata" poster="assets/images/supplier-thumb.jpg">
                        <source src="<?php echo $supplier_video; ?>" type="video/mp4">
                        Your browser does not support video.
                    </video>
                <?php else: ?>
                    <div class="tut-video-placeholder">
                        <i class="fas fa-video"></i>
                        <p><strong>Video Tutorial: For Suppliers</strong></p>
                        <p>Upload the video to:<br><code>assets/videos/supplier-tutorial.mp4</code></p>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="tut-steps-title">
                    <i class="fas fa-list-ol"></i>
                    Steps for Suppliers
                </div>
                <div class="tut-step">
                    <span class="tut-num">1</span>
                    <div>
                        <strong>Register as a supplier</strong>
                        <span class="tut-desc">Go to Supplier Login and click "Register".</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">2</span>
                    <div>
                        <strong>List your products</strong>
                        <span class="tut-desc">Add the supplies and frames you provide.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">3</span>
                    <div>
                        <strong>Receive purchase requests</strong>
                        <span class="tut-desc">View requests coming from clinics.</span>
                    </div>
                </div>
                <div class="tut-step">
                    <span class="tut-num">4</span>
                    <div>
                        <strong>Fulfill and track orders</strong>
                        <span class="tut-desc">Accept, pack, and ship orders to clinics.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== DOWNLOAD APP ===== -->
    <div class="download-section" id="download">
        <div class="download-inner">
            <div class="download-text">
                <div class="download-eyebrow">
                    <i class="fab fa-android"></i> Mobile App for Android
                </div>
                <div class="download-h2">Eyecore in your<br>pocket — anytime.</div>
                <div class="download-p">Book appointments, view 3D frames, and manage your clinic on the go. The Eyecore mobile app gives you full access to your favorite clinics and the complete management platform — right from your Android phone.</div>
                
                <div class="download-btns">
                    <a href="https://median.co/share/zpxbjne#apk" target="_blank" class="apk-btn">
                        <i class="fab fa-android"></i> Download APK
                    </a>
                    <a href="https://median.co/share/zpxbjne#apk" target="_blank" class="sb-ghost" style="border-color:rgba(255,255,255,0.5);color:white;">
                        <i class="fas fa-info-circle"></i> Learn More
                    </a>
                </div>
                <p style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:16px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-shield-alt"></i> Safe & verified APK. Only for Android devices.
                </p>
            </div>
            <div class="download-visual">
                <div class="phone-mockup">
                    <div class="phone-screen">
                        <i class="fas fa-eye"></i>
                        <div class="app-name">eyecore</div>
                        <div class="app-sub">Integrated Optical Platform</div>
                    </div>
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
                <a href="pages/explore-3d.php" class="tryon-btn">
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
                            <svg width="200" height="120" viewBox="0 0 200 120" fill="none" style="display:block;margin:0 auto;">
                                <ellipse cx="58" cy="60" rx="44" ry="36" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="5"/>
                                <ellipse cx="142" cy="60" rx="44" ry="36" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="5"/>
                                <ellipse cx="58" cy="60" rx="26" ry="21" fill="rgba(100,180,255,0.2)"/>
                                <ellipse cx="142" cy="60" rx="26" ry="21" fill="rgba(100,180,255,0.2)"/>
                                <path d="M100 60 Q100 52 100 60" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="4" stroke-linecap="round"/>
                                <line x1="14" y1="52" x2="0" y2="47" stroke="rgba(255,255,255,0.85)" stroke-width="5" stroke-linecap="round"/>
                                <line x1="186" y1="52" x2="200" y2="47" stroke="rgba(255,255,255,0.85)" stroke-width="5" stroke-linecap="round"/>
                                <ellipse cx="100" cy="110" rx="60" ry="6" fill="rgba(0,0,0,0.2)"/>
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
                            <div class="tv-color" style="background:#C0C0C0;"></div>
                        </div>
                    </div>
                </div>
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
                <div class="footer-p">Your trusted companion for all your eye care needs in Cavite. Integrated platform for clinics, suppliers, and patients — from booking to supply chain.</div>
                <div class="soc-row">
                    <a href="#" class="soc-btn"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="soc-btn"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="soc-btn"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div>
                <div class="fh4">Platform</div>
                <ul class="flinks">
                    <li><a href="#tutorial">Tutorial</a></li>
                    <li><a href="#modules">Modules</a></li>
                    <li><a href="#download">Mobile App</a></li>
                    <li><a href="admin/login.php">Clinic Login</a></li>
                    <li><a href="suppliers/supplier_login.php">Supplier Login</a></li>
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
            <div>
                <div class="fb-copy">© <?php echo date('Y'); ?> Eyecore. All rights reserved.</div>
                <div class="fb-researchers">Developed by Researchers: Mendoza, Rivera, Villanueva, Pacatang</div>
            </div>
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
            <a href="#tutorial" class="mob-link" onclick="closeMobMenu()"><i class="fas fa-play-circle"></i> Tutorial</a>
            <a href="#modules" class="mob-link" onclick="closeMobMenu()"><i class="fas fa-th-large"></i> Modules</a>
            <a href="#download" class="mob-link" onclick="closeMobMenu()"><i class="fab fa-android"></i> Download App</a>
            <a href="#cities"   class="mob-link" onclick="closeMobMenu()"><i class="fas fa-map-marker-alt"></i> Cities</a>
            <a href="#clinics"  class="mob-link" onclick="closeMobMenu()"><i class="fas fa-clinic-medical"></i> Clinics</a>
            <a href="#contact"  class="mob-link" onclick="closeMobMenu()"><i class="fas fa-envelope"></i> Contact</a>
        </div>
        <div class="mob-divider"></div>
        <div class="mob-btns">
            <?php if ($is_logged_in): ?>
                <a href="pages/dashboard.php" class="btn-primary"><i class="fas fa-user"></i> Dashboard</a>
                <a href="auth/logout.php" class="btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php else: ?>
                <a href="auth/user_login.php" class="btn-outline">Login</a>
                <a href="auth/user_register.php" class="btn-primary"><i class="fas fa-user-plus"></i> Sign Up Free</a>
            <?php endif; ?>
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

    let tStart = 0;
    document.getElementById('sliderWrap').addEventListener('touchstart', e => { tStart = e.touches[0].clientX; stopAuto(); });
    document.getElementById('sliderWrap').addEventListener('touchend', e => {
        const diff = tStart - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) diff > 0 ? nextSlide() : prevSlide();
        startAuto();
    });
    startAuto();

    // ===== TUTORIAL TAB SWITCHER =====
    function switchTut(role, btn) {
        document.querySelectorAll('.tut-panel').forEach(p => p.style.display = 'none');
        document.querySelectorAll('.tut-tab').forEach(t => t.classList.remove('active'));
        document.getElementById('tut-' + role).style.display = 'grid';
        btn.classList.add('active');
        document.querySelectorAll('.tut-video-wrap video').forEach(v => v.pause());
    }

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
        document.body.style.overflow = 'hidden';
    }
    function closeMobMenu() {
        document.getElementById('mobMenu').classList.remove('open');
        document.getElementById('mobOverlay').style.display = 'none';
        document.body.style.overflow = '';
    }

    // ===== SMOOTH SCROLL =====
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); closeMobMenu(); }
        });
    });

    // ===== 3D COLOR SWITCHER =====
    document.querySelectorAll('.tv-color').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tv-color').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });
    </script>
</body>
</html>