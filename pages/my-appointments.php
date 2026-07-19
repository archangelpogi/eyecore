<?php
include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../auth/user_login.php'); exit(); }

$user_id = $_SESSION['user_id'];

// Auto-mark missed
mysqli_query($conn, "UPDATE appointments SET status='missed', updated_at=NOW() WHERE user_id=$user_id AND status IN ('confirmed','pending') AND appointment_date < CURDATE()");

$user      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$user_id"));
$user_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id=$user_id"));
$pending   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id AND status='pending'"))['total'] ?? 0;

$unread_count = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user_id) : 0;
$recent_notifications = function_exists('getRecentNotifications') ? getRecentNotifications($user_id) : [];
$total_bookings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id"))['total'] ?? 0;
$total_points   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"))['t'] ?: 0;

// Status counts
$total_counts = [];
foreach (['pending','confirmed','paid','completed','cancelled','missed','no-show'] as $s) {
    $total_counts[$s] = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM appointments WHERE user_id=$user_id AND status='$s'"))['cnt'] ?? 0;
}
$total_all_appointments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id"))['total'] ?? 0;

$status_filter = $_GET['status'] ?? 'all';
$search_query  = trim($_GET['search'] ?? '');
$view_mode     = (($_GET['view'] ?? '') === 'list') ? 'list' : 'grid';
$page          = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 6;
$offset = ($page - 1) * $items_per_page;

$where = ["a.user_id=$user_id"];
if ($status_filter !== 'all') $where[] = "a.status='".mysqli_real_escape_string($conn,$status_filter)."'";
if ($search_query !== '') {
    $st = mysqli_real_escape_string($conn,$search_query);
    $where[] = "(c.clinic_name LIKE '%$st%' OR a.ref_no LIKE '%$st%' OR p.name LIKE '%$st%')";
}
$where_clause = implode(' AND ', $where);

$total_filtered  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments a JOIN clinics c ON a.clinic_id=c.id LEFT JOIN products p ON a.product_id=p.id WHERE $where_clause"))['total'] ?? 0;
$total_pages     = ceil($total_filtered / $items_per_page);

$appointments_query = mysqli_query($conn, "
    SELECT a.*, c.clinic_name, c.address, c.contact, c.logo, c.clinic_image, c.cover_photo,
           p.name as product_name, p.price as product_price,
           p.image as product_image, p.images as product_images_old, p.images_json as product_images_json,
           p.category as product_category,
           d.name as doctor_name, d.specialty as doctor_specialty
    FROM appointments a
    JOIN clinics c ON a.clinic_id=c.id
    LEFT JOIN products p ON a.product_id=p.id
    LEFT JOIN doctors d ON a.doctor_id=d.id
    WHERE $where_clause
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT $offset, $items_per_page
");
$filtered_count = mysqli_num_rows($appointments_query);

// ── Image helpers (no duplicates with theme.php) ─────────────────────────────
function resolveImgPathForAppointment($path) {
    if (empty($path)) return '';
    $path = str_replace(['uploads/uploads/','uploads//uploads/'], 'uploads/', $path);
    if (strpos($path,'/')===0) return $path;
    if (strpos($path,'uploads/')===0) return '/'.$path;
    return '/uploads/products/'.ltrim($path,'/');
}

function getProductImagesForAppointment($appointment) {
    $imgs = [];
    if (!empty($appointment['product_images_json'])) {
        $dec = json_decode($appointment['product_images_json'], true);
        if (is_array($dec)&&count($dec)) {
            foreach ($dec as $i){ $u=resolveImgPathForAppointment($i); if($u) $imgs[]=$u; }
            if ($imgs) return $imgs;
        }
    }
    if (!empty($appointment['product_images_old'])) {
        $raw = $appointment['product_images_old'];
        if (strpos($raw,'[')===0) {
            $dec = json_decode($raw, true);
            if (is_array($dec)&&count($dec)) {
                foreach ($dec as $i){ $u=resolveImgPathForAppointment($i); if($u) $imgs[]=$u; }
                if ($imgs) return $imgs;
            }
        } else { $u=resolveImgPathForAppointment($raw); if($u) return [$u]; }
    }
    if (!empty($appointment['product_image'])) {
        $p=$appointment['product_image'];
        if (strpos($p,'uploads/')===false&&strpos($p,'/')===false)
            return ['/assets/images/products/'.$p];
        $u=resolveImgPathForAppointment($p); if($u) return [$u];
    }
    return [];
}

function getClinicImgForAppointment($c) {
    if (!empty($c['cover_photo']))  return '/assets/images/clinic-covers/'.$c['cover_photo'];
    if (!empty($c['clinic_image'])) return '/assets/images/clinic-images/'.$c['clinic_image'];
    if (!empty($c['logo']))         return '/assets/images/clinic-logos/'.$c['logo'];
    return null;
}

function getStatusBadgeClass($s) {
    return ['pending'=>'pending','confirmed'=>'confirmed','paid'=>'paid','completed'=>'completed',
            'cancelled'=>'cancelled','missed'=>'missed','no-show'=>'cancelled'][$s]??'pending';
}

function getStatusIcon($s) {
    return ['pending'=>'fa-clock','confirmed'=>'fa-check-circle','paid'=>'fa-credit-card',
            'completed'=>'fa-check-double','cancelled'=>'fa-times-circle','missed'=>'fa-calendar-times',
            'no-show'=>'fa-user-slash'][$s]??'fa-clock';
}

include '../includes/navbar.php';

// Pre-collect slider data for ALL appointments
$all_slider_data = [];
$all_appointments_rows = [];
mysqli_data_seek($appointments_query, 0);
while ($ap = mysqli_fetch_assoc($appointments_query)) {
    $all_appointments_rows[] = $ap;
    $product_images = getProductImagesForAppointment($ap);
    $clinic_img = getClinicImgForAppointment($ap);
    
    // Fallback: if no product images but has clinic image, use clinic image
    if (empty($product_images) && $clinic_img) {
        $product_images = [$clinic_img];
    }
    
    // If still empty, use placeholder
    if (empty($product_images)) {
        $product_images = ['/assets/img/no-image.png'];
    }
    
    $all_slider_data[$ap['id']] = [
        'images' => $product_images,
        'count' => count($product_images)
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Appointments - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg-primary); color:var(--text-primary); min-height:100vh; transition:all 0.3s; }

        .main-content { max-width:1400px; margin:0 auto; padding:28px 40px; }
        @media(max-width:1024px){.main-content{padding:24px;}}
        @media(max-width:768px){.main-content{padding:18px 16px 100px;}}

        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;flex-wrap:wrap;gap:20px;}
        .page-title{display:flex;align-items:center;gap:12px;}
        .page-title i{font-size:28px;color:var(--primary);background:var(--primary-light);width:50px;height:50px;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-full);}
        .page-title h1{font-size:24px;font-weight:700;color:var(--text-primary);}
        .page-title span{font-size:14px;color:var(--text-muted);font-weight:500;margin-left:8px;}

        .view-toggle{display:flex;gap:8px;background:var(--bg-secondary);padding:4px;border-radius:var(--radius-full);border:1px solid var(--border-light);}
        .view-btn{padding:8px 16px;border-radius:var(--radius-full);background:transparent;border:none;color:var(--text-secondary);cursor:pointer;font-size:14px;transition:all 0.2s;display:flex;align-items:center;gap:6px;}
        .view-btn.active{background:var(--primary);color:white;}
        .view-btn:hover:not(.active){background:var(--bg-primary);color:var(--primary);}

        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:28px;}
        .stat-card{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:14px 16px;border:1px solid var(--border-light);transition:all 0.2s;cursor:pointer;text-align:center;}
        .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--primary);}
        .stat-card.active{border-color:var(--primary);background:var(--primary-light);}
        .stat-value{font-size:24px;font-weight:800;color:var(--primary);line-height:1;}
        .stat-label{font-size:11px;color:var(--text-muted);margin-top:5px;display:flex;align-items:center;justify-content:center;gap:4px;}

        .search-bar{margin-bottom:24px;}
        .search-container{position:relative;max-width:400px;}
        .search-container i{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:14px;}
        .search-container input{width:100%;padding:12px 16px 12px 44px;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius-full);font-size:14px;color:var(--text-primary);font-family:inherit;transition:all 0.2s;}
        .search-container input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light);}

        .results-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
        .results-count{background:var(--bg-secondary);padding:6px 14px;border-radius:var(--radius-full);font-size:13px;border:1px solid var(--border-light);color:var(--text-secondary);}
        .results-count span{font-weight:700;color:var(--primary);}

        /* Grid / List layouts */
        .appointments-grid{display:grid;grid-template-columns:repeat(1,1fr);gap:20px;}
        @media(min-width:768px){.appointments-grid{grid-template-columns:repeat(2,1fr);}}
        @media(min-width:1200px){.appointments-grid{grid-template-columns:repeat(3,1fr);}}
        .appointments-list{display:flex;flex-direction:column;gap:12px;}

        /* Card */
        .appointment-card{background:var(--bg-secondary);border-radius:var(--radius-lg);border:1px solid var(--border-light);overflow:hidden;transition:all 0.2s;cursor:pointer;text-decoration:none;display:block;position:relative;}
        .appointment-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--primary);}
        .card-content-grid{display:flex;flex-direction:column;}

        /* ══════════════════════════════════════════════
           PRODUCT IMAGE SLIDER (same as clinic-details.php)
           ══════════════════════════════════════════════ */
        .product-image-section {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: var(--bg-primary);
            flex-shrink: 0;
        }

        .pc-slider-track {
            display: flex;
            width: 100%; height: 100%;
            transition: transform 0.4s ease;
            will-change: transform;
        }

        .pc-slide {
            min-width: 100%; width: 100%; height: 100%;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--bg-secondary);
        }

        .pc-slide img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
            display: block;
        }

        .pc-dots {
            position: absolute; bottom: 7px; left: 0; right: 0;
            display: flex; justify-content: center; gap: 5px;
            z-index: 15; pointer-events: none;
        }

        .pc-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: rgba(255,255,255,0.75);
            border: none; padding: 0; cursor: pointer;
            pointer-events: all; transition: all 0.2s;
        }

        .pc-dot.active { background: var(--primary); width: 14px; border-radius: 4px; }

        .pc-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 26px; height: 26px;
            background: rgba(0,0,0,0.42);
            border: none; border-radius: 50%;
            color: white; font-size: 11px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 20;
            opacity: 0; transition: opacity 0.2s;
            backdrop-filter: blur(3px);
        }

        .product-image-section:hover .pc-arrow { opacity: 1; }
        .pc-arrow.prev { left: 6px; }
        .pc-arrow.next { right: 6px; }
        .pc-arrow:hover { background: var(--primary); }

        .pc-counter {
            position: absolute; bottom: 8px; right: 8px;
            background: rgba(0,0,0,0.55); color: white;
            padding: 2px 7px; border-radius: var(--radius-full);
            font-size: 10px; font-weight: 500; z-index: 15;
            backdrop-filter: blur(2px);
        }

        /* Placeholder */
        .product-placeholder{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:var(--bg-primary);}
        .product-placeholder i{font-size:48px;color:var(--primary);opacity:0.6;}
        .product-placeholder span{font-size:12px;color:var(--text-muted);}

        /* List view small image */
        .list-image{width:70px;height:70px;border-radius:var(--radius-md);overflow:hidden;flex-shrink:0;background:var(--bg-primary);position:relative;}
        .list-image .pc-slider-track img{object-fit:cover;}
        .list-image-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--primary-light);color:var(--primary);font-size:24px;}

        /* Card elements */
        .card-body{padding:16px;display:flex;flex-direction:column;gap:12px;}
        .clinic-header{display:flex;align-items:center;gap:12px;}
        .clinic-avatar{width:44px;height:44px;border-radius:var(--radius-md);overflow:hidden;flex-shrink:0;background:var(--bg-primary);display:flex;align-items:center;justify-content:center;}
        .clinic-avatar img{width:100%;height:100%;object-fit:cover;}
        .clinic-avatar-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--primary-light);color:var(--primary);font-size:20px;}
        .clinic-info{flex:1;}
        .clinic-name{font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        .ref-no{font-size:10px;color:var(--text-muted);font-weight:500;background:var(--bg-primary);padding:2px 6px;border-radius:var(--radius-full);}

        .status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:var(--radius-full);font-size:11px;font-weight:600;}
        .status-badge.pending{background:#FEF3C7;color:#92400E;}
        .status-badge.confirmed{background:#D1FAE5;color:#065F46;}
        .status-badge.paid{background:#DBEAFE;color:#1E40AF;}
        .status-badge.completed{background:#EDE9FE;color:#5B21B6;}
        .status-badge.cancelled{background:#FEE2E2;color:#991B1B;}
        .status-badge.missed{background:#F3E8FF;color:#7C3AED;}

        .appointment-details{display:flex;flex-direction:column;gap:8px;}
        .detail-row{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--text-secondary);}
        .detail-row i{width:16px;color:var(--primary);font-size:12px;}
        .detail-row .value{flex:1;}

        .product-badge{background:var(--bg-primary);border-radius:var(--radius-md);padding:8px 10px;margin-top:4px;}
        .product-badge-content{display:flex;align-items:center;gap:10px;}
        .product-badge-content i{color:var(--primary);font-size:14px;}
        .product-badge-content span{font-size:12px;font-weight:500;color:var(--text-primary);}
        .product-badge-content .price{margin-left:auto;font-weight:700;color:var(--primary);font-size:13px;}

        .doctor-info{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-secondary);}
        .doctor-info i{color:var(--primary);font-size:11px;}

        /* List view */
        .card-content-list{display:flex;align-items:center;gap:16px;padding:16px;flex-wrap:wrap;}
        .list-info{flex:2;min-width:200px;}
        .list-details{display:flex;flex-wrap:wrap;gap:12px;margin-top:6px;}
        .list-detail-item{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-secondary);}
        .list-detail-item i{color:var(--primary);font-size:11px;}
        .list-status{flex-shrink:0;}
        @media(max-width:768px){.card-content-list{flex-direction:column;align-items:flex-start;}.list-status{align-self:flex-start;}}

        /* Image Modal */
        .image-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);z-index:10000;cursor:pointer;align-items:center;justify-content:center;}
        .image-modal.show{display:flex;}
        .modal-image{max-width:90%;max-height:90%;object-fit:contain;border-radius:var(--radius-lg);}
        .modal-close{position:absolute;top:20px;right:30px;color:white;font-size:40px;cursor:pointer;transition:all 0.2s;}
        .modal-close:hover{color:var(--primary);}

        /* Pagination */
        .pagination{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:32px;flex-wrap:wrap;}
        .pagination-btn{padding:8px 14px;border-radius:var(--radius-full);background:var(--bg-secondary);border:1px solid var(--border-light);color:var(--text-secondary);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.2s;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
        .pagination-btn:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary);}
        .pagination-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
        .pagination-btn.disabled{opacity:0.5;cursor:not-allowed;pointer-events:none;}

        /* Empty State */
        .empty-state{grid-column:1/-1;text-align:center;padding:60px 20px;background:var(--bg-secondary);border-radius:var(--radius-lg);border:1px solid var(--border-light);}
        .empty-state i{font-size:64px;color:var(--text-muted);margin-bottom:16px;opacity:0.5;}
        .empty-state h3{font-size:18px;font-weight:700;margin-bottom:8px;color:var(--text-primary);}
        .empty-state p{color:var(--text-secondary);font-size:14px;margin-bottom:20px;}
        .btn-primary-sm{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--primary-gradient);color:white;border-radius:var(--radius-full);text-decoration:none;font-size:13px;font-weight:600;transition:all 0.2s;}
        .btn-primary-sm:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,183,97,0.3);}

        :root{--primary:#00B761;--primary-dark:#00874A;--primary-light:#E3FCE9;--primary-gradient:linear-gradient(135deg,#00B761,#00A86B);--secondary:#FF8C42;--bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;--card-bg:#FFFFFF;--text-primary:#111827;--text-secondary:#6B7280;--text-muted:#9CA3AF;--border-color:#E5E7EB;--border-light:#F3F4F6;--shadow-sm:0 1px 3px rgba(0,0,0,0.06);--shadow-md:0 4px 16px rgba(0,0,0,0.08);--shadow-lg:0 12px 40px rgba(0,0,0,0.10);--radius-sm:10px;--radius-md:14px;--radius-lg:20px;--radius-xl:28px;--radius-full:999px;--danger:#EF4444;--warning:#F59E0B;--success:#00B761;--info:#3B82F6;}
        .theme-dark{--primary:#00E676;--primary-dark:#00C853;--primary-light:#0D2818;--bg-primary:#0D0D0D;--bg-secondary:#161616;--card-bg:#1E1E1E;--text-primary:#F9FAFB;--text-secondary:#9CA3AF;--text-muted:#6B7280;--border-color:#2A2A2A;--border-light:#222222;}
    </style>
</head>
<body>
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-calendar-check"></i>
                <h1>My Appointments <span>(<?php echo $total_all_appointments; ?> total)</span></h1>
            </div>
            <div class="view-toggle">
                <button class="view-btn <?php echo $view_mode==='grid'?'active':''; ?>" onclick="switchView('grid')"><i class="fas fa-th-large"></i> Grid</button>
                <button class="view-btn <?php echo $view_mode==='list'?'active':''; ?>" onclick="switchView('list')"><i class="fas fa-list"></i> List</button>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $status_filter==='all'?'active':''; ?>" onclick="filterByStatus('all')">
                <div class="stat-value"><?php echo $total_all_appointments; ?></div>
                <div class="stat-label"><i class="fas fa-calendar-alt"></i> All</div>
            </div>
            <?php foreach(['pending'=>['fa-clock','Pending'],'confirmed'=>['fa-check-circle','Confirmed'],'paid'=>['fa-credit-card','Paid'],'completed'=>['fa-check-double','Completed'],'cancelled'=>['fa-times-circle','Cancelled'],'missed'=>['fa-calendar-times','Missed']] as $s=>[$ico,$lbl]): ?>
            <div class="stat-card <?php echo $status_filter===$s?'active':''; ?>" onclick="filterByStatus('<?php echo $s; ?>')">
                <div class="stat-value"><?php echo $total_counts[$s]; ?></div>
                <div class="stat-label"><i class="fas <?php echo $ico; ?>"></i> <?php echo $lbl; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Search -->
        <div class="search-bar">
            <div class="search-container">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search clinic, service, ref no..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
        </div>

        <!-- Results Info -->
        <div class="results-info">
            <div class="results-count">
                <i class="fas fa-eye"></i> Showing <span><?php echo $filtered_count; ?></span> of <span><?php echo $total_filtered; ?></span> appointments
            </div>
        </div>

        <!-- Appointments Container -->
        <div id="appointmentsContainer" class="<?php echo $view_mode==='grid'?'appointments-grid':'appointments-list'; ?>">
            <?php if ($filtered_count > 0): ?>
                <?php foreach ($all_appointments_rows as $appointment):
                    $slider_data = $all_slider_data[$appointment['id']];
                    $images = $slider_data['images'];
                    $img_count = $slider_data['count'];
                    $has_img = !($img_count===1 && strpos($images[0],'no-image.png')!==false);
                    $clinic_img = getClinicImgForAppointment($appointment);
                    $apd = new DateTime($appointment['appointment_date']);
                    $formatted_date = $apd->format('M j, Y');
                    $formatted_time = !empty($appointment['appointment_time']) ? date('g:i A', strtotime($appointment['appointment_time'])) : $apd->format('g:i A');
                    $aid = $appointment['id'];
                ?>
                <div class="appointment-card" onclick="window.location.href='appointment-details.php?id=<?php echo $aid; ?>'">
                    <?php if ($view_mode === 'grid'): ?>
                    <!-- GRID VIEW -->
                    <div class="card-content-grid">
                        <!-- Image Slider (same pattern as clinic-details.php) -->
                        <div class="product-image-section" id="pic-<?php echo $aid; ?>" onclick="event.stopPropagation();">
                            <?php if ($has_img): ?>
                                <div class="pc-slider-track" id="track-<?php echo $aid; ?>">
                                    <?php foreach ($images as $imgUrl): ?>
                                    <div class="pc-slide">
                                        <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                             alt="<?php echo htmlspecialchars($appointment['product_name']??$appointment['clinic_name']); ?>"
                                             onclick="showImageModal('<?php echo htmlspecialchars($imgUrl); ?>','<?php echo htmlspecialchars($appointment['product_name']??$appointment['clinic_name']); ?>')"
                                             style="cursor:zoom-in;"
                                             onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($img_count > 1): ?>
                                <div class="pc-dots" id="dots-<?php echo $aid; ?>">
                                    <?php for($di=0;$di<$img_count;$di++): ?>
                                    <button class="pc-dot <?php echo $di===0?'active':''; ?>"
                                            onclick="pcGo(<?php echo $aid; ?>,<?php echo $di; ?>,event)"></button>
                                    <?php endfor; ?>
                                </div>
                                <button class="pc-arrow prev" onclick="pcGo(<?php echo $aid; ?>,pcState[<?php echo $aid; ?>]-1,event)">&#8249;</button>
                                <button class="pc-arrow next" onclick="pcGo(<?php echo $aid; ?>,pcState[<?php echo $aid; ?>]+1,event)">&#8250;</button>
                                <div class="pc-counter" id="ctr-<?php echo $aid; ?>">
                                    <i class="fas fa-images"></i> 1/<?php echo $img_count; ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Fallback: clinic image or placeholder -->
                                <?php if ($clinic_img): ?>
                                    <div class="pc-slider-track">
                                        <div class="pc-slide">
                                            <img src="<?php echo htmlspecialchars($clinic_img); ?>"
                                                 alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>"
                                                 onclick="showImageModal('<?php echo htmlspecialchars($clinic_img); ?>','<?php echo htmlspecialchars($appointment['clinic_name']); ?>')"
                                                 style="cursor:zoom-in;"
                                                 onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="product-placeholder">
                                        <i class="fas fa-clinic-medical"></i>
                                        <span><?php echo htmlspecialchars($appointment['clinic_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body">
                            <div class="clinic-header">
                                <div class="clinic-avatar">
                                    <?php if ($clinic_img): ?>
                                        <img src="<?php echo htmlspecialchars($clinic_img); ?>" alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="clinic-avatar-placeholder" style="display:none;"><i class="fas fa-clinic-medical"></i></div>
                                    <?php else: ?>
                                        <div class="clinic-avatar-placeholder"><i class="fas fa-clinic-medical"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="clinic-info">
                                    <div class="clinic-name">
                                        <?php echo htmlspecialchars($appointment['clinic_name']); ?>
                                        <span class="ref-no">#<?php echo htmlspecialchars($appointment['ref_no']); ?></span>
                                    </div>
                                    <div class="status-badge <?php echo getStatusBadgeClass($appointment['status']); ?>">
                                        <i class="fas <?php echo getStatusIcon($appointment['status']); ?>"></i>
                                        <?php echo ucfirst($appointment['status']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="appointment-details">
                                <div class="detail-row"><i class="fas fa-calendar-alt"></i><span class="value"><?php echo $formatted_date; ?></span></div>
                                <div class="detail-row"><i class="fas fa-clock"></i><span class="value"><?php echo $formatted_time; ?></span></div>
                                <div class="detail-row"><i class="fas fa-map-marker-alt"></i><span class="value"><?php echo htmlspecialchars($appointment['address']); ?></span></div>
                                <?php if (!empty($appointment['doctor_name'])): ?>
                                <div class="doctor-info"><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($appointment['product_name'])): ?>
                            <div class="product-badge">
                                <div class="product-badge-content">
                                    <i class="fas fa-tag"></i>
                                    <span><?php echo htmlspecialchars($appointment['product_name']); ?></span>
                                    <?php if (!empty($appointment['product_price'])): ?>
                                    <span class="price">₱<?php echo number_format($appointment['product_price'],2); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- LIST VIEW -->
                    <div class="card-content-list">
                        <div class="list-image" id="lpic-<?php echo $aid; ?>" onclick="event.stopPropagation();">
                            <?php if ($has_img): ?>
                                <div class="pc-slider-track" id="ltrack-<?php echo $aid; ?>">
                                    <?php foreach ($images as $imgUrl): ?>
                                    <div class="pc-slide">
                                        <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                             alt="<?php echo htmlspecialchars($appointment['product_name']??$appointment['clinic_name']); ?>"
                                             onclick="showImageModal('<?php echo htmlspecialchars($imgUrl); ?>','<?php echo htmlspecialchars($appointment['product_name']??$appointment['clinic_name']); ?>')"
                                             style="cursor:zoom-in;object-fit:cover;"
                                             onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($img_count > 1): ?>
                                <button class="pc-arrow prev" onclick="pcGoList(<?php echo $aid; ?>,pcStateL[<?php echo $aid; ?>]-1,event)" style="width:20px;height:20px;font-size:9px;">&#8249;</button>
                                <button class="pc-arrow next" onclick="pcGoList(<?php echo $aid; ?>,pcStateL[<?php echo $aid; ?>]+1,event)" style="width:20px;height:20px;font-size:9px;">&#8250;</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($clinic_img): ?>
                                    <div class="pc-slider-track">
                                        <div class="pc-slide">
                                            <img src="<?php echo htmlspecialchars($clinic_img); ?>"
                                                 alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>"
                                                 style="object-fit:cover;"
                                                 onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="list-image-placeholder"><i class="fas fa-clinic-medical"></i></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="list-info">
                            <div class="clinic-name">
                                <?php echo htmlspecialchars($appointment['clinic_name']); ?>
                                <span class="ref-no">#<?php echo htmlspecialchars($appointment['ref_no']); ?></span>
                            </div>
                            <div class="list-details">
                                <div class="list-detail-item"><i class="fas fa-calendar-alt"></i><?php echo $formatted_date; ?> • <?php echo $formatted_time; ?></div>
                                <div class="list-detail-item"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($appointment['address']); ?></div>
                                <?php if (!empty($appointment['doctor_name'])): ?>
                                <div class="list-detail-item"><i class="fas fa-user-md"></i>Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($appointment['product_name'])): ?>
                                <div class="list-detail-item">
                                    <i class="fas fa-tag"></i>
                                    <?php echo htmlspecialchars($appointment['product_name']); ?>
                                    <?php if (!empty($appointment['product_price'])): ?> • ₱<?php echo number_format($appointment['product_price'],2); ?><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="list-status">
                            <div class="status-badge <?php echo getStatusBadgeClass($appointment['status']); ?>">
                                <i class="fas <?php echo getStatusIcon($appointment['status']); ?>"></i>
                                <?php echo ucfirst($appointment['status']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No appointments found</h3>
                    <p><?php echo $search_query?"No appointments matching '{$search_query}'":'You haven\'t booked any appointments yet.'; ?></p>
                    <a href="nearby.php" class="btn-primary-sm"><i class="fas fa-map-marker-alt"></i> Find a Clinic</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- PHP Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <a href="#" class="pagination-btn <?php echo $page<=1?'disabled':''; ?>" onclick="goToPage(<?php echo $page-1; ?>);return false;">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php for($i=1;$i<=$total_pages;$i++): ?>
                <a href="#" class="pagination-btn <?php echo $i===$page?'active':''; ?>" onclick="goToPage(<?php echo $i; ?>);return false;"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="#" class="pagination-btn <?php echo $page>=$total_pages?'disabled':''; ?>" onclick="goToPage(<?php echo $page+1; ?>);return false;">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal" onclick="closeImageModal()">
        <span class="modal-close" onclick="closeImageModal()">&times;</span>
        <img class="modal-image" id="modalImage" src="" alt="">
    </div>

    <script>
    // ──────────────────────────────────────────────────────
    // SLIDER STATE INITIALIZATION (same as clinic-details.php)
    // ──────────────────────────────────────────────────────
    const pcState  = {};
    const pcStateL = {};
    const pcTotal  = <?php 
        $totals_js = [];
        foreach ($all_slider_data as $id => $data) {
            $totals_js[$id] = $data['count'];
        }
        echo json_encode($totals_js); 
    ?>;

    // Initialize all states
    Object.keys(pcTotal).forEach(id => { 
        pcState[id] = 0; 
        pcStateL[id] = 0; 
    });

    // Grid slider function (same as clinic-details)
    function pcGo(id, index, event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        const total = pcTotal[id] || 1;
        index = ((index % total) + total) % total;
        pcState[id] = index;

        const track = document.getElementById('track-' + id);
        if (track) track.style.transform = 'translateX(' + (-index * 100) + '%)';

        const dotsEl = document.getElementById('dots-' + id);
        if (dotsEl) {
            dotsEl.querySelectorAll('.pc-dot').forEach((d, i) => {
                d.classList.toggle('active', i === index);
            });
        }

        const ctr = document.getElementById('ctr-' + id);
        if (ctr) {
            ctr.innerHTML = '<i class="fas fa-images"></i> ' + (index + 1) + '/' + total;
        }
    }

    // List slider function
    function pcGoList(id, index, event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }
        const total = pcTotal[id] || 1;
        index = ((index % total) + total) % total;
        pcStateL[id] = index;
        const track = document.getElementById('ltrack-' + id);
        if (track) track.style.transform = 'translateX(' + (-index * 100) + '%)';
    }

    // ──────────────────────────────────────────────────────
    // UI controls
    // ──────────────────────────────────────────────────────
    function filterByStatus(status) {
        const url = new URL(window.location.href);
        status==='all' ? url.searchParams.delete('status') : url.searchParams.set('status',status);
        url.searchParams.delete('search'); url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    function switchView(view) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', view); url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    function goToPage(page) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', page);
        window.location.href = url.toString();
    }

    function showImageModal(imageUrl, productName) {
        document.getElementById('modalImage').src = imageUrl;
        document.getElementById('modalImage').alt = productName;
        document.getElementById('imageModal').classList.add('show');
    }

    function closeImageModal() { 
        document.getElementById('imageModal').classList.remove('show'); 
    }
    
    document.addEventListener('keydown', e => { 
        if(e.key === 'Escape') closeImageModal(); 
    });

    // Search debouncer
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let t;
        searchInput.addEventListener('input', function() {
            clearTimeout(t);
            t = setTimeout(() => {
                const url = new URL(window.location.href);
                this.value.trim() ? url.searchParams.set('search',this.value.trim()) : url.searchParams.delete('search');
                url.searchParams.delete('page');
                window.location.href = url.toString();
            }, 500);
        });
    }
    </script>
</body>
</html>