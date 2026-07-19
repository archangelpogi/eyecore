<?php
ob_start();

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) { ob_end_clean(); header('Location: ../auth/user_login.php'); exit(); }

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Auto-mark missed
mysqli_query($conn, "UPDATE appointments SET status='missed',updated_at=NOW() WHERE user_id=$user_id AND status='confirmed' AND CONCAT(appointment_date,' ',COALESCE(appointment_time,'23:59:59')) < NOW()");

// ── POST handlers ─────────────────────────────────────────────────────────────
if (isset($_POST['cancel_appointment'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $cid = (int)$_POST['appointment_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM appointments WHERE id=$cid AND user_id=$user_id AND status NOT IN ('cancelled','completed','missed')"));
    if ($row) {
        $apd = new DateTime($row['appointment_date']);
        if ($apd < new DateTime()) { echo json_encode(['success'=>false,'message'=>'Cannot cancel past appointments']); exit(); }
        $ok = mysqli_query($conn, "UPDATE appointments SET status='cancelled',updated_at=NOW() WHERE id=$cid AND user_id=$user_id");
        echo json_encode($ok ? ['success'=>true,'message'=>'Appointment cancelled successfully'] : ['success'=>false,'message'=>'Database error']);
    } else { echo json_encode(['success'=>false,'message'=>'Appointment not found or cannot be cancelled']); }
    exit();
}

if (isset($_POST['request_refund'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $rid = (int)$_POST['appointment_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT downpayment_amount,clinic_id,status FROM appointments WHERE id=$rid AND user_id=$user_id AND status='cancelled'"));
    if ($row && $row['downpayment_amount'] > 0) {
        $ex = mysqli_query($conn, "SELECT id FROM refund_requests WHERE appointment_id=$rid AND status IN ('pending','approved','processing')");
        if (mysqli_num_rows($ex) > 0) { echo json_encode(['success'=>false,'message'=>'Refund request already submitted.']); exit(); }
        $cid=(int)$row['clinic_id']; $amt=(float)$row['downpayment_amount'];
        $ok = mysqli_query($conn, "INSERT INTO refund_requests(appointment_id,user_id,clinic_id,amount,request_type,status,created_at) VALUES($rid,$user_id,$cid,$amt,'refund','pending',NOW())");
        echo json_encode($ok ? ['success'=>true,'message'=>'Refund request submitted successfully!'] : ['success'=>false,'message'=>'Failed: '.mysqli_error($conn)]);
    } else { echo json_encode(['success'=>false,'message'=>'No downpayment found for refund.']); }
    exit();
}

if (isset($_POST['delete_appointment'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $did = (int)$_POST['appointment_id'];
    $ok  = mysqli_query($conn, "DELETE FROM appointments WHERE id=$did AND user_id=$user_id AND status='cancelled'");
    echo json_encode(($ok && mysqli_affected_rows($conn) > 0) ? ['success'=>true,'message'=>'Appointment deleted successfully'] : ['success'=>false,'message'=>'Cannot delete this appointment']);
    exit();
}

if (isset($_POST['view_penalty'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $pid = (int)$_POST['appointment_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT c.penalty_amount FROM appointments a JOIN clinics c ON a.clinic_id=c.id WHERE a.id=$pid AND a.user_id=$user_id"));
    echo json_encode(['success'=>true,'penalty_amount'=>(float)($row['penalty_amount']??500),'message'=>'No-show penalty applies']);
    exit();
}

$query = mysqli_query($conn, "
    SELECT a.*, a.downpayment_amount,
           c.clinic_name, c.address, c.contact, c.clinic_email as email,
           c.latitude, c.longitude, c.hours, c.city,
           c.logo, c.clinic_image, c.cover_photo,
           c.refund_policy, c.reschedule_policy, c.penalty_amount,
           u.first_name, u.last_name, u.email as user_email, u.contact as user_phone, u.address as user_address,
           p.id as product_id, p.name as product_name, p.description as product_description,
           p.price as product_price,
           p.image as product_image, p.images as product_images_old, p.images_json as product_images_json,
           p.category as product_category,
           p.warranty_period, p.warranty_coverage, p.warranty_terms, p.warranty_premium_price,
           d.name as doctor_name, d.specialty as doctor_specialty,
           r.created_at as purchase_date,
           r.id as reservation_id
    FROM appointments a
    JOIN clinics c ON a.clinic_id=c.id
    JOIN users u ON a.user_id=u.id
    LEFT JOIN products p ON a.product_id=p.id
    LEFT JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN reservations r ON a.product_id = r.product_id AND r.user_id = a.user_id AND r.status IN ('confirmed', 'completed')
    WHERE a.id=$appointment_id AND a.user_id=$user_id
");
$appointment = mysqli_fetch_assoc($query);
if (!$appointment) { header('Location: my-appointments.php'); exit(); }

$downpayment_amount = max((float)($appointment['downpayment_amount']??0), (float)($appointment['downpayment']??0));
$refund_available = ($appointment['status']=='cancelled' && $downpayment_amount > 0);
$refund_pending   = false;
if ($refund_available) {
    $rc = mysqli_query($conn, "SELECT status FROM refund_requests WHERE appointment_id=$appointment_id AND status IN ('pending','approved','processing')");
    $refund_pending = mysqli_num_rows($rc) > 0;
}

// ── Image helpers ─────────────────────────────────────────────────────────────
function resolveImgPath3($path) {
    if (empty($path)) return '';
    $path = str_replace(['uploads/uploads/','uploads//uploads/'],'uploads/',$path);
    if (strpos($path,'/')===0) return $path;
    if (strpos($path,'uploads/')===0) return '/'.$path;
    return '/uploads/products/'.ltrim($path,'/');
}

function getProductImages($product) {
    $imgs=[];
    if (!empty($product['product_images_json'])) {
        $dec=json_decode($product['product_images_json'],true);
        if (is_array($dec)&&count($dec)){foreach($dec as $i){$u=resolveImgPath3($i);if($u)$imgs[]=$u;}if($imgs)return $imgs;}
    }
    if (!empty($product['product_images_old'])) {
        $raw=$product['product_images_old'];
        if (strpos($raw,'[')===0){$dec=json_decode($raw,true);if(is_array($dec)&&count($dec)){foreach($dec as $i){$u=resolveImgPath3($i);if($u)$imgs[]=$u;}if($imgs)return $imgs;}}
        else{$u=resolveImgPath3($raw);if($u)return[$u];}
    }
    if (!empty($product['product_image'])){
        $p=$product['product_image'];
        if(strpos($p,'uploads/')===false&&strpos($p,'/')===false)return['/assets/images/products/'.$p];
        $u=resolveImgPath3($p);if($u)return[$u];
    }
    return['/assets/img/no-image.png'];
}

$product_images = getProductImages($appointment);
$img_count      = count($product_images);
$has_product_img = !($img_count===1 && strpos($product_images[0],'no-image.png')!==false);

// Clinic image fallback if no product image
if (!function_exists('getClinicImg')) { function getClinicImg($c){if(!empty($c['cover_photo']))return'/assets/images/clinic-covers/'.$c['cover_photo'];if(!empty($c['clinic_image']))return'/assets/images/clinic-images/'.$c['clinic_image'];if(!empty($c['logo']))return'/assets/images/clinic-logos/'.$c['logo'];return null;} }
$clinic_img_fallback = getClinicImg($appointment);
if (!$has_product_img && $clinic_img_fallback) {
    $product_images = [$clinic_img_fallback];
    $img_count      = 1;
    $has_img        = true;
} else {
    $has_img = $has_product_img;
}

$category_icons=['Frames'=>'fa-glasses','Contact Lenses'=>'fa-eye','Service'=>'fa-stethoscope',
                 'Lenses'=>'fa-eye','Accessories'=>'fa-shopping-bag','Eyeglasses'=>'fa-glasses','Sunglasses'=>'fa-sunglasses'];
$category_icon = $category_icons[$appointment['product_category']]??'fa-box';

$user      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$user_id"));
$user_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT avatar FROM users WHERE id=$user_id"));
$pending   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id AND status='pending'"))['total']??0;
$unread_count = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user_id) : 0;
$recent_notifications = function_exists('getRecentNotifications') ? getRecentNotifications($user_id) : [];
$total_bookings = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id"))['total']??0;
$total_points   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"))['t']??0;

$clinic_rating_data = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(AVG(rating),0) as avg_rating,COUNT(*) as total_reviews FROM clinic_reviews WHERE clinic_id={$appointment['clinic_id']}"));
$clinic_avg_rating   = round($clinic_rating_data['avg_rating'],1);
$clinic_total_reviews = $clinic_rating_data['total_reviews'];

$apd = new DateTime($appointment['appointment_date']);
$formatted_date = $apd->format('F j, Y');
$formatted_time = $apd->format('g:i A');
$formatted_day  = $apd->format('l');

$reviews_query = mysqli_query($conn,"SELECT r.*,CONCAT(u.first_name,' ',u.last_name) as reviewer_name FROM clinic_reviews r JOIN users u ON r.user_id=u.id WHERE r.clinic_id={$appointment['clinic_id']} ORDER BY r.created_at DESC LIMIT 3");

$now=new DateTime(); $interval=$now->diff($apd); $days_until=$interval->days;
if ($apd<$now&&!in_array($appointment['status'],['completed','cancelled','missed'])){$status_message='Past appointment';$status_class='past';}
elseif($days_until==0){$status_message='Today!';$status_class='today';}
elseif($days_until==1){$status_message='Tomorrow';$status_class='upcoming';}
else{$status_message="In $days_until days";$status_class='upcoming';}

function renderStarRating($rating) {
    $full=$full=floor($rating); $half=($rating-$full)>=0.5; $empty=5-$full-($half?1:0);
    $h=''; for($i=0;$i<$full;$i++)$h.='<i class="fas fa-star"></i>'; if($half)$h.='<i class="fas fa-star-half-alt"></i>'; for($i=0;$i<$empty;$i++)$h.='<i class="far fa-star"></i>'; return $h;
}
if(!function_exists('timeAgo')){function timeAgo($ts){$d=time()-strtotime($ts);if($d<=60)return'Just Now';if($d<=3600)return round($d/60).'m ago';if($d<=86400)return round($d/3600).'h ago';if($d<=604800)return round($d/86400).'d ago';return date('M j, Y',strtotime($ts));}}
if(!function_exists('getThemeClass')){function getThemeClass(){return(isset($_COOKIE['theme'])&&$_COOKIE['theme']==='dark')?'theme-dark':'';}}
if(!function_exists('getClinicImg')){function getClinicImg($c){if(!empty($c['cover_photo']))return'/assets/images/clinic-covers/'.$c['cover_photo'];if(!empty($c['clinic_image']))return'/assets/images/clinic-images/'.$c['clinic_image'];if(!empty($c['logo']))return'/assets/images/clinic-logos/'.$c['logo'];return null;}}
function calculateWarrantyEndDate($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return null;
    
    $date = new DateTime($purchase_date);
    
    $period_map = [
        '3_months' => '+3 months',
        '6_months' => '+6 months',
        '12_months' => '+12 months',
        '24_months' => '+24 months',
        '36_months' => '+36 months'
    ];
    
    if (isset($period_map[$warranty_period])) {
        $date->modify($period_map[$warranty_period]);
        return $date->format('Y-m-d');
    }
    
    return null;
}

function isWithinWarranty($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return false;
    if ($warranty_period == 'no_warranty') return false;
    
    $end_date = calculateWarrantyEndDate($purchase_date, $warranty_period);
    if (!$end_date) return false;
    
    $today = date('Y-m-d');
    return $end_date >= $today;
}

function getWarrantyPeriodDisplay($warranty_period) {
    $labels = [
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '12_months' => '12 Months',
        '24_months' => '24 Months',
        '36_months' => '36 Months'
    ];
    return $labels[$warranty_period] ?? '';
}

// Calculate warranty info for this appointment
$has_warranty = false;
$is_within_warranty = false;
$warranty_end_date = null;
$warranty_display = '';
$can_claim_warranty = false;
$warranty_coverage = [];
$warranty_terms = '';

if (!empty($appointment['product_id']) && !empty($appointment['warranty_period']) && $appointment['warranty_period'] != 'no_warranty') {
    $has_warranty = true;
    $warranty_display = getWarrantyPeriodDisplay($appointment['warranty_period']);
    
    if (!empty($appointment['warranty_coverage'])) {
        $warranty_coverage = json_decode($appointment['warranty_coverage'], true);
        if (!is_array($warranty_coverage)) $warranty_coverage = [];
    }
    if (!empty($appointment['warranty_terms'])) {
        $warranty_terms = $appointment['warranty_terms'];
    }
    
    // Fallback sa appointment_date kung walang purchase_date
    $purchase_date_to_use = !empty($appointment['purchase_date']) 
        ? $appointment['purchase_date'] 
        : $appointment['appointment_date'];

if (!empty($purchase_date_to_use)) {
        $warranty_end_date = calculateWarrantyEndDate($purchase_date_to_use, $appointment['warranty_period']);
        $is_within_warranty = isWithinWarranty($purchase_date_to_use, $appointment['warranty_period']);
        $can_claim_warranty = ($appointment['status'] == 'completed' && $is_within_warranty);
    }
}

// Check existing claim — LABAS na sa if($has_warranty) block
// Para laging niche-check kahit ano ang status
$existing_claim = null;
if (!empty($appointment['product_id'])) {
$wc = mysqli_query($conn, "SELECT id, status FROM warranty_claims 
                           WHERE product_id = {$appointment['product_id']} 
                           AND user_id = $user_id 
                           AND status IN ('pending', 'processing', 'approved', 'arrived', 'completed', 'rejected')
                           LIMIT 1");
    if (mysqli_num_rows($wc) > 0) {
        $existing_claim = mysqli_fetch_assoc($wc);
        $can_claim_warranty = false;
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Appointment Details - Eyecore</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg-primary); color:var(--text-primary); min-height:100vh; transition:all 0.3s; }
        .main-content { max-width:1200px; margin:0 auto; padding:28px 40px; }
        @media(max-width:1024px){.main-content{padding:24px;}}
        @media(max-width:768px){.main-content{padding:18px 16px 100px;}}

        .top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;flex-wrap:wrap;gap:15px;}
        .page-title{display:flex;align-items:center;gap:12px;}
        .page-title i{font-size:28px;color:var(--primary);background:var(--primary-light);width:50px;height:50px;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-full);}
        .page-title h1{font-size:24px;font-weight:700;color:var(--text-primary);}
        .back-btn{display:flex;align-items:center;gap:8px;padding:10px 20px;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius-full);color:var(--text-secondary);text-decoration:none;font-size:14px;font-weight:500;transition:all 0.3s;}
        .back-btn:hover{background:var(--primary);color:white;border-color:var(--primary);}

        /* Status banner */
        .status-banner{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:20px 30px;margin-bottom:25px;border:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;}
        .status-info{display:flex;align-items:center;gap:15px;}
        .status-icon{width:60px;height:60px;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;font-size:28px;}
        .status-icon.pending{background:#FFF3E0;color:#F57C00;} .status-icon.confirmed{background:#E8F5E9;color:#388E3C;} .status-icon.cancelled{background:#FFEBEE;color:#EF4444;} .status-icon.completed{background:#E3F2FD;color:#1976D2;} .status-icon.missed{background:#F3E8FF;color:#7C3AED;} .status-icon.paid{background:#E8F5E9;color:#00B761;}
        .status-text h3{font-size:18px;font-weight:700;margin-bottom:5px;color:var(--text-primary);}
        .status-text p{color:var(--text-secondary);font-size:13px;}
        .status-badge{padding:10px 25px;border-radius:var(--radius-full);font-weight:600;font-size:14px;}
        .status-badge.pending{background:#FFF3E0;color:#F57C00;} .status-badge.confirmed{background:#E8F5E9;color:#388E3C;} .status-badge.cancelled{background:#FFEBEE;color:#EF4444;} .status-badge.completed{background:#E3F2FD;color:#1976D2;} .status-badge.missed{background:#F3E8FF;color:#7C3AED;} .status-badge.paid{background:#E8F5E9;color:#00B761;} .status-badge.upcoming{background:var(--primary-light);color:var(--primary-dark);} .status-badge.today{background:#FFF1E6;color:#FF8C42;} .status-badge.past{background:#F5F5F5;color:var(--text-muted);}

        /* Downpayment info */
        .downpayment-info{background:#FFF3E0;border-radius:var(--radius-md);padding:12px 15px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
        .downpayment-info i{color:#F57C00;} .downpayment-info .amount{font-weight:700;color:#F57C00;font-size:16px;}

        /* ══════════════════════════════════════════════
           PRODUCT GALLERY  (clinic-details style)
           ══════════════════════════════════════════════ */
        .product-gallery {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            overflow: hidden;
            margin-bottom: 25px;
        }

        /* Full-width slider */
        .gallery-slider-wrap {
            position: relative;
            width: 100%;
            height: 380px;
            overflow: hidden;
            background: var(--bg-primary);
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
            background: var(--bg-primary);
        }

        .pc-slide img {
            width: 100%; height: 100%;
            max-width: 100%; max-height: 380px;
            object-fit: contain;
            padding: 8px;
            cursor: zoom-in;
            display: block;
        }

        /* Dots row below slider */
        .gallery-dots-row {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 12px 0;
            background: var(--bg-secondary);
        }

        .gallery-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--border-color); cursor: pointer; transition: all 0.2s;
            border: none; padding: 0;
        }

        .gallery-dot.active { width: 24px; border-radius: 4px; background: var(--primary); }
        .gallery-dot:hover  { background: var(--primary); opacity: 0.7; }

        /* Arrows */
        .gallery-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 40px; height: 40px;
            background: rgba(0,0,0,0.45);
            border: none; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s; z-index: 10;
            backdrop-filter: blur(4px);
        }

        .gallery-arrow:hover { background: var(--primary); }
        .gallery-arrow i { color: white; font-size: 18px; }
        .gallery-arrow.prev { left: 16px; }
        .gallery-arrow.next { right: 16px; }

        /* Counter */
        .gallery-counter {
            position: absolute; bottom: 16px; right: 16px;
            background: rgba(0,0,0,0.6); color: white;
            padding: 4px 10px; border-radius: var(--radius-full);
            font-size: 12px; font-weight: 500; z-index: 10;
        }

        /* Gallery placeholder */
        .gallery-placeholder { text-align:center; padding:60px 20px; }
        .gallery-placeholder i { font-size:80px; color:var(--primary); opacity:0.5; margin-bottom:16px; display:block; }
        .gallery-placeholder h3 { font-size:18px; font-weight:600; margin-bottom:8px; }
        .gallery-placeholder p { color:var(--text-muted); font-size:13px; }

        /* Product info card */
        .product-info-card{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:20px;border:1px solid var(--border-light);margin-bottom:25px;}
        .product-info-header{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border-light);}
        .product-info-header i{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;}
        .product-info-header h2{font-size:18px;font-weight:700;color:var(--text-primary);}
        .product-name{font-size:20px;font-weight:700;color:var(--text-primary);margin-bottom:8px;}
        .product-price{font-size:24px;font-weight:800;color:var(--primary);margin-bottom:12px;}
        .product-description{color:var(--text-secondary);font-size:14px;line-height:1.6;margin-bottom:16px;}
        .product-category-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--bg-primary);border-radius:var(--radius-full);font-size:12px;color:var(--text-secondary);}

        /* Detail cards */
        .details-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:25px;margin-bottom:25px;}
        @media(max-width:768px){.details-grid{grid-template-columns:1fr;}}
        .detail-card{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:25px;border:1px solid var(--border-light);box-shadow:var(--shadow-sm);}
        .card-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:15px;border-bottom:1px solid var(--border-light);}
        .card-header i{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;}
        .card-header h2{font-size:18px;font-weight:700;color:var(--text-primary);}
        .appointment-info{display:flex;flex-direction:column;gap:15px;}
        .info-row{display:flex;align-items:flex-start;gap:15px;}
        .info-icon{width:36px;height:36px;background:var(--bg-primary);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:16px;flex-shrink:0;}
        .info-content{flex:1;}
        .info-label{font-size:12px;color:var(--text-muted);margin-bottom:4px;}
        .info-value{font-size:16px;font-weight:600;color:var(--text-primary);}
        .info-sub{font-size:13px;color:var(--text-secondary);margin-top:2px;}

        /* Clinic section */
        .clinic-header-block{display:flex;align-items:center;gap:15px;margin-bottom:20px;}
        .clinic-avatar{width:60px;height:60px;border-radius:var(--radius-md);overflow:hidden;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:white;font-size:28px;}
        .clinic-avatar img{width:100%;height:100%;object-fit:cover;}
        .clinic-header-block h3{font-size:18px;font-weight:700;margin-bottom:5px;color:var(--text-primary);}
        .clinic-rating{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .clinic-rating .stars{display:inline-flex;gap:2px;color:#FFC107;font-size:12px;}
        .clinic-rating .rating-value{font-weight:600;color:var(--text-primary);font-size:13px;}
        .clinic-rating .reviews-count{color:var(--text-muted);font-size:12px;}
        .no-rating{color:var(--text-muted);font-size:12px;display:flex;align-items:center;gap:5px;}
        .clinic-details-list{display:flex;flex-direction:column;gap:15px;margin-bottom:20px;}
        .clinic-detail-item{display:flex;align-items:flex-start;gap:12px;}
        .clinic-detail-item i{width:20px;color:var(--primary);font-size:14px;margin-top:2px;}
        .clinic-detail-item strong{display:block;font-size:13px;font-weight:600;margin-bottom:3px;color:var(--text-primary);}
        .clinic-detail-item span{font-size:13px;color:var(--text-secondary);}
        .clinic-actions{display:flex;gap:10px;margin-top:20px;}
        .btn-primary,.btn-outline{flex:1;padding:12px;border-radius:var(--radius-md);font-size:14px;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.3s;cursor:pointer;}
        .btn-primary{background:var(--primary-gradient);color:white;border:none;}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,183,97,0.3);}
        .btn-outline{border:1.5px solid var(--primary);color:var(--primary);background:transparent;}
        .btn-outline:hover{background:var(--primary);color:white;}

        /* Reviews */
        .reviews-section{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:25px;border:1px solid var(--border-light);margin-bottom:25px;}
        .reviews-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px;}
        .reviews-header h3{font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text-primary);}
        .review-card{padding:15px 0;border-bottom:1px solid var(--border-light);}
        .review-card:last-child{border-bottom:none;}
        .review-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
        .reviewer{display:flex;align-items:center;gap:10px;}
        .reviewer i{width:32px;height:32px;background:var(--bg-primary);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:14px;}
        .reviewer strong{font-size:14px;font-weight:600;color:var(--text-primary);}
        .review-rating{display:flex;gap:2px;color:#FFC107;font-size:12px;}
        .review-text{font-size:14px;color:var(--text-secondary);line-height:1.5;margin-bottom:8px;}
        .review-date{font-size:11px;color:var(--text-muted);}
        .btn-write-review{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-full);color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.3s;}
        .btn-write-review:hover{background:var(--primary);color:white;border-color:var(--primary);}
        .empty-reviews{text-align:center;padding:40px;color:var(--text-muted);}
        .empty-reviews i{font-size:48px;margin-bottom:10px;opacity:0.5;}

        /* Action buttons */
        .action-buttons{display:flex;gap:15px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px;}
        .action-btn{padding:12px 25px;border-radius:var(--radius-full);font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all 0.3s;border:none;text-decoration:none;}
        .action-btn-danger{background:#EF4444;color:white;} .action-btn-danger:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(239,68,68,0.3);}
        .action-btn-primary{background:var(--primary-gradient);color:white;} .action-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,183,97,0.3);}
        .action-btn-secondary{background:var(--bg-primary);border:1px solid var(--border-color);color:var(--text-primary);} .action-btn-secondary:hover{background:var(--primary);color:white;border-color:var(--primary);}
        .action-btn-warning{background:#F59E0B;color:white;} .action-btn-warning:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(245,158,11,0.3);}

        /* Image Modal */
        .image-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:10000;cursor:pointer;align-items:center;justify-content:center;}
        .image-modal.show{display:flex;}
        .modal-image{max-width:90%;max-height:90%;object-fit:contain;border-radius:var(--radius-lg);}
        .modal-close{position:absolute;top:20px;right:30px;color:white;font-size:40px;cursor:pointer;transition:all 0.2s;z-index:10001;}
        .modal-close:hover{color:var(--primary);}

        :root{--primary:#00B761;--primary-dark:#00874A;--primary-light:#E3FCE9;--primary-gradient:linear-gradient(135deg,#00B761,#00A86B);--secondary:#FF8C42;--bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;--card-bg:#FFFFFF;--text-primary:#111827;--text-secondary:#6B7280;--text-muted:#9CA3AF;--border-color:#E5E7EB;--border-light:#F3F4F6;--shadow-sm:0 1px 3px rgba(0,0,0,0.06);--shadow-md:0 4px 16px rgba(0,0,0,0.08);--radius-sm:10px;--radius-md:14px;--radius-lg:20px;--radius-xl:28px;--radius-full:999px;--danger:#EF4444;--warning:#F59E0B;--success:#00B761;--info:#3B82F6;}
        .theme-dark{--primary:#00E676;--primary-dark:#00C853;--primary-light:#0D2818;--bg-primary:#0D0D0D;--bg-secondary:#161616;--card-bg:#1E1E1E;--text-primary:#F9FAFB;--text-secondary:#9CA3AF;--text-muted:#6B7280;--border-color:#2A2A2A;--border-light:#222222;}
   /* Warranty Section Styles */
.warranty-section {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-top: 16px;
}

.warranty-badge {
    background: var(--primary-light);
    color: var(--primary);
    padding: 2px 8px;
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}

.warranty-status {
    padding: 8px 12px;
    border-radius: var(--radius-md);
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.warranty-status.active {
    background: #E8F5E9;
    color: #2E7D32;
}

.warranty-status.expired {
    background: #FFEBEE;
    color: #EF4444;
}

.theme-dark .warranty-status.active {
    background: #0D2818;
    color: #00E676;
}

.theme-dark .warranty-status.expired {
    background: #3B0F0F;
    color: #EF4444;
}

.coverage-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.coverage-tag {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    padding: 2px 10px;
    font-size: 11px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.coverage-tag i {
    color: var(--success);
    font-size: 9px;
}

.warranty-terms p {
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.5;
    margin: 0;
}

.btn-warranty-claim {
    background: linear-gradient(135deg, #00B761, #00A86B);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
}

.btn-warranty-claim:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,183,97,0.3);
}

.btn-warranty-expired {
    background: #9CA3AF;
    color: white;
    border: none;
    border-radius: var(--radius-md);
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 600;
    cursor: not-allowed;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    opacity: 0.7;
}

.warranty-note {
    text-align: center;
    padding: 8px;
}

.theme-dark .swal2-popup { background:#1E1E1E; color:#F9FAFB; }
.theme-dark .swal2-title { color:#F9FAFB; }
.theme-dark .swal2-html-container { color:#9CA3AF; }
   </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>  
<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-calendar-check"></i>
            <h1>Appointment Details</h1>
        </div>
        <a href="my-appointments.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Appointments</a>
    </div>

    <!-- Status Banner -->
    <div class="status-banner">
        <div class="status-info">
            <div class="status-icon <?php echo $appointment['status']; ?>">
                <i class="fas fa-<?php echo ['pending'=>'clock','confirmed'=>'check-circle','cancelled'=>'times-circle','missed'=>'calendar-times','paid'=>'money-bill-wave','completed'=>'check-double'][$appointment['status']]??'clock'; ?>"></i>
            </div>
            <div class="status-text">
                <h3>Appointment <?php echo $appointment['status']==='missed'?'Missed':ucfirst($appointment['status']); ?></h3>
                <p>Booking reference: #<?php echo str_pad($appointment['id'],6,'0',STR_PAD_LEFT); ?></p>
            </div>
        </div>
        <div class="status-badge <?php echo $status_class; ?>"><?php echo $status_message; ?></div>
    </div>

    <!-- Downpayment Info -->
    <?php if ($downpayment_amount > 0): ?>
    <div class="downpayment-info">
        <div><i class="fas fa-receipt"></i> <strong>Downpayment Paid:</strong> <span class="amount">₱<?php echo number_format($downpayment_amount,2); ?></span></div>
        <?php if ($appointment['status']=='cancelled'&&!$refund_pending): ?>
        <div><i class="fas fa-exclamation-circle"></i> <span>You can request a refund below</span></div>
        <?php endif; ?>
        <?php if ($refund_pending): ?><div><i class="fas fa-spinner fa-pulse"></i> <span>Refund request pending...</span></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════
         PRODUCT GALLERY  (clinic-details.php style)
         ══════════════════════════════════════════════ -->
    <?php if (!empty($appointment['product_name']) || $has_img): ?>
    <div class="product-gallery">
        <div class="gallery-slider-wrap" id="galleryWrap">
            <?php if ($has_img): ?>
                <!-- Slider Track -->
                <div class="pc-slider-track" id="galleryTrack">
                    <?php foreach ($product_images as $imgUrl): ?>
                    <div class="pc-slide">
                        <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                             alt="<?php echo htmlspecialchars($appointment['product_name']??'Product'); ?>"
                             onclick="openImageModal('<?php echo htmlspecialchars($imgUrl); ?>','<?php echo htmlspecialchars($appointment['product_name']??'Product'); ?>')"
                             onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($img_count > 1): ?>
                <!-- Arrows -->
                <button class="gallery-arrow prev" onclick="galleryGo(galleryCurrent-1)"><i class="fas fa-chevron-left"></i></button>
                <button class="gallery-arrow next" onclick="galleryGo(galleryCurrent+1)"><i class="fas fa-chevron-right"></i></button>
                <!-- Counter -->
                <div class="gallery-counter" id="galleryCounter"><i class="fas fa-images"></i> 1 / <?php echo $img_count; ?></div>
                <?php endif; ?>

            <?php else: ?>
                <div class="gallery-placeholder">
                    <i class="fas <?php echo $category_icon; ?>"></i>
                    <h3><?php echo htmlspecialchars($appointment['product_name']??'Product Image'); ?></h3>
                    <p>No image available</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($has_img && $img_count > 1): ?>
        <!-- Dots row -->
        <div class="gallery-dots-row" id="galleryDots">
            <?php for($i=0;$i<$img_count;$i++): ?>
            <button class="gallery-dot <?php echo $i===0?'active':''; ?>" onclick="galleryGo(<?php echo $i; ?>)"></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($appointment['product_name'])): ?>
    <div class="product-info-card">
        <div class="product-info-header"><i class="fas fa-box"></i><h2>Product Information</h2></div>
        <div class="product-name"><?php echo htmlspecialchars($appointment['product_name']); ?></div>
        <?php if (!empty($appointment['product_price'])): ?><div class="product-price">₱<?php echo number_format($appointment['product_price'],2); ?></div><?php endif; ?>
        <?php if (!empty($appointment['product_description'])): ?><div class="product-description"><?php echo nl2br(htmlspecialchars($appointment['product_description'])); ?></div><?php endif; ?>
        <?php if (!empty($appointment['product_category'])): ?><div class="product-category-badge"><i class="fas <?php echo $category_icon; ?>"></i> <?php echo htmlspecialchars($appointment['product_category']); ?></div><?php endif; ?>
        <!-- Warranty Section -->
<?php if ($has_warranty): ?>
<div class="warranty-section mt-3 pt-3 border-top">
    <div class="warranty-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
        <i class="fas fa-shield-alt" style="color: var(--primary);"></i>
        <strong>Warranty Information</strong>
        <?php if ($warranty_display): ?>
        <span class="warranty-badge"><?php echo $warranty_display; ?> Warranty</span>
        <?php endif; ?>
    </div>
    
    <?php if ($is_within_warranty && $warranty_end_date): ?>
    <div class="warranty-status active">
        <i class="fas fa-check-circle"></i> Warranty Active until <?php echo date('F d, Y', strtotime($warranty_end_date)); ?>
    </div>
    <?php elseif ($has_warranty && !$is_within_warranty && $warranty_end_date): ?>
    <div class="warranty-status expired">
        <i class="fas fa-clock"></i> Warranty Expired on <?php echo date('F d, Y', strtotime($warranty_end_date)); ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($warranty_coverage)): ?>
    <div class="warranty-coverage mt-2">
        <small class="text-muted">Covers:</small>
        <div class="coverage-tags mt-1">
            <?php foreach ($warranty_coverage as $cover): ?>
            <span class="coverage-tag"><i class="fas fa-check"></i> <?php echo ucfirst(str_replace('_', ' ', $cover)); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($warranty_terms)): ?>
    <div class="warranty-terms mt-2">
        <small class="text-muted">Terms:</small>
        <p class="small mt-1"><?php echo nl2br(htmlspecialchars(substr($warranty_terms, 0, 200))); ?><?php echo strlen($warranty_terms) > 200 ? '...' : ''; ?></p>
    </div>
    <?php endif; ?>
    
<!-- Claim Warranty Button -->
<?php if ($can_claim_warranty): ?>
<button class="btn-warranty-claim mt-3" onclick="openWarrantyClaim(<?php echo !empty($appointment['reservation_id']) ? $appointment['reservation_id'] : $appointment['id']; ?>, <?php echo $appointment['product_id']; ?>, '<?php echo htmlspecialchars($appointment['product_name']); ?>')">
    <i class="fas fa-tools"></i> Claim Warranty
</button>

<?php elseif (!empty($existing_claim)): ?>
<div class="warranty-status active mt-3" style="justify-content: center;">
    <?php if ($existing_claim['status'] == 'pending'): ?>
        <i class="fas fa-spinner fa-pulse"></i> Warranty Claim Pending Review
    <?php elseif ($existing_claim['status'] == 'processing'): ?>
        <i class="fas fa-cog fa-spin"></i> Warranty Claim Being Processed
    <?php elseif ($existing_claim['status'] == 'approved'): ?>
        <i class="fas fa-check-circle"></i> Warranty Claim Approved — Visit clinic on scheduled date
    <?php elseif ($existing_claim['status'] == 'arrived'): ?>
        <i class="fas fa-door-open"></i> You have arrived — Claim being processed
    <?php elseif ($existing_claim['status'] == 'completed'): ?>
        <i class="fas fa-patch-check"></i> Warranty Claim Already Completed
    <?php elseif ($existing_claim['status'] == 'rejected'): ?>
        <i class="fas fa-times-circle"></i> Warranty Claim Was Rejected
    <?php endif; ?>
</div>
<?php elseif ($has_warranty && $appointment['status'] == 'completed' && !$is_within_warranty): ?>
<button class="btn-warranty-expired mt-3" disabled>
    <i class="fas fa-clock"></i> Warranty Expired
</button>

<?php elseif ($has_warranty && $appointment['status'] != 'completed'): ?>
<div class="warranty-note mt-2">
    <small class="text-muted"><i class="fas fa-info-circle"></i> Warranty becomes available after appointment completion</small>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Details Grid -->
    <div class="details-grid">
        <!-- Appointment Schedule -->
        <div class="detail-card">
            <div class="card-header"><i class="fas fa-calendar-alt"></i><h2>Appointment Schedule</h2></div>
            <div class="appointment-info">
                <div class="info-row"><div class="info-icon"><i class="fas fa-calendar-day"></i></div><div class="info-content"><div class="info-label">Date</div><div class="info-value"><?php echo $formatted_date; ?></div><div class="info-sub"><?php echo $formatted_day; ?></div></div></div>
                <div class="info-row"><div class="info-icon"><i class="fas fa-clock"></i></div><div class="info-content"><div class="info-label">Time</div><div class="info-value"><?php echo $formatted_time; ?></div></div></div>
                <?php if (!empty($appointment['doctor_name'])): ?>
                <div class="info-row"><div class="info-icon"><i class="fas fa-user-md"></i></div><div class="info-content"><div class="info-label">Doctor</div><div class="info-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div><?php if(!empty($appointment['doctor_specialty'])): ?><div class="info-sub"><?php echo htmlspecialchars($appointment['doctor_specialty']); ?></div><?php endif; ?></div></div>
                <?php endif; ?>
                <?php if (!empty($appointment['notes'])): ?>
                <div class="info-row"><div class="info-icon"><i class="fas fa-sticky-note"></i></div><div class="info-content"><div class="info-label">Notes</div><div class="info-value"><?php echo htmlspecialchars($appointment['notes']); ?></div></div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Clinic Info -->
        <div class="detail-card">
            <div class="card-header"><i class="fas fa-clinic-medical"></i><h2>Clinic Information</h2></div>
            <div class="clinic-header-block">
                <?php $ci=getClinicImg($appointment); if($ci): ?>
                    <div class="clinic-avatar"><img src="<?php echo htmlspecialchars($ci); ?>" alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-eye\'></i>'"></div>
                <?php else: ?><div class="clinic-avatar"><i class="fas fa-eye"></i></div><?php endif; ?>
                <div>
                    <h3><?php echo htmlspecialchars($appointment['clinic_name']); ?></h3>
                    <div class="clinic-rating">
                        <?php if($clinic_total_reviews>0): ?>
                            <div class="stars"><?php echo renderStarRating($clinic_avg_rating); ?></div>
                            <span class="rating-value"><?php echo $clinic_avg_rating; ?></span>
                            <span class="reviews-count">(<?php echo $clinic_total_reviews; ?> reviews)</span>
                        <?php else: ?><div class="no-rating"><i class="far fa-star"></i> <span>No reviews yet</span></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="clinic-details-list">
                <div class="clinic-detail-item"><i class="fas fa-map-marker-alt"></i><div><strong>Address</strong><span><?php echo htmlspecialchars($appointment['address']); ?></span></div></div>
                <?php if (!empty($appointment['contact'])): ?><div class="clinic-detail-item"><i class="fas fa-phone-alt"></i><div><strong>Contact</strong><span><?php echo htmlspecialchars($appointment['contact']); ?></span></div></div><?php endif; ?>
                <?php if (!empty($appointment['hours'])): ?><div class="clinic-detail-item"><i class="fas fa-clock"></i><div><strong>Operating Hours</strong><span><?php echo htmlspecialchars($appointment['hours']); ?></span></div></div><?php endif; ?>
            </div>
            <div class="clinic-actions">
                <a href="directions.php?clinic=<?php echo $appointment['clinic_id']; ?>" class="btn-primary"><i class="fas fa-directions"></i> Directions</a>
                <?php if (!empty($appointment['contact'])): ?><a href="tel:<?php echo $appointment['contact']; ?>" class="btn-outline"><i class="fas fa-phone-alt"></i> Call</a><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reviews -->
    <div class="reviews-section">
        <div class="reviews-header">
            <h3><i class="fas fa-star" style="color:#FFC107;"></i> Clinic Reviews</h3>
            <?php if ($appointment['status']=='completed'): ?>
            <a href="write-review.php?clinic=<?php echo $appointment['clinic_id']; ?>&appointment=<?php echo $appointment['id']; ?>" class="btn-write-review"><i class="fas fa-pen"></i> Write a Review</a>
            <?php endif; ?>
        </div>
        <?php if (mysqli_num_rows($reviews_query)>0): ?>
            <?php while($review=mysqli_fetch_assoc($reviews_query)): ?>
            <div class="review-card">
                <div class="review-header">
                    <div class="reviewer"><i class="fas fa-user-circle"></i><strong><?php echo htmlspecialchars($review['reviewer_name']); ?></strong></div>
                    <div class="review-rating"><?php for($i=1;$i<=5;$i++): ?><i class="fas fa-star<?php echo $i<=$review['rating']?'':'-o'; ?>"></i><?php endfor; ?></div>
                </div>
                <div class="review-text"><?php echo htmlspecialchars($review['review']); ?></div>
                <div class="review-date"><?php echo timeAgo($review['created_at']); ?></div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-reviews"><i class="fas fa-star-of-life"></i><p>No reviews yet. Be the first to share your experience!</p></div>
        <?php endif; ?>
    </div>

<!-- Action Buttons -->
<div class="action-buttons">
    <?php if ($appointment['status'] == 'waiting_payment'): ?>
        <!-- Pay Now Button - DIRECT REDIRECT TO payment.php -->
        <a href="payment.php?appointment_id=<?php echo $appointment['id']; ?>&amount=<?php echo urlencode(number_format($appointment['downpayment_amount'] ?? $appointment['total_amount'] ?? 0, 2)); ?>" class="action-btn action-btn-primary">
            <i class="fas fa-credit-card"></i> Pay Now (₱<?php echo number_format($appointment['downpayment_amount'] ?? $appointment['total_amount'] ?? 0, 2); ?>)
        </a>
        <button onclick="cancelAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
            <i class="fas fa-times-circle"></i> Cancel Appointment
        </button>
        
    <?php elseif (in_array($appointment['status'], ['pending', 'confirmed', 'paid'])): ?>
        <button onclick="cancelAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
            <i class="fas fa-times-circle"></i> Cancel Appointment
        </button>
        <a href="reschedule-appointment.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary">
            <i class="fas fa-calendar-alt"></i> Reschedule
        </a>
        
    <?php endif; ?>
    
    <?php if ($appointment['status'] == 'paid'): ?>
        <a href="view-receipt.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary" target="_blank">
            <i class="fas fa-receipt"></i> View Receipt
        </a>
    <?php endif; ?>
    
    <?php if ($appointment['status'] == 'completed'): ?>
        <a href="write-review.php?clinic=<?php echo $appointment['clinic_id']; ?>&appointment=<?php echo $appointment['id']; ?>" class="action-btn action-btn-primary">
            <i class="fas fa-star"></i> Write a Review
        </a>
        <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
            <i class="fas fa-redo-alt"></i> Book Again
        </a>
        <a href="view-receipt.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary" target="_blank">
            <i class="fas fa-receipt"></i> View Receipt
        </a>
        <button onclick="shareExperience()" class="action-btn action-btn-secondary">
            <i class="fas fa-share-alt"></i> Share
        </button>
    <?php endif; ?>
    
    <?php if ($appointment['status'] == 'cancelled'): ?>
        <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
            <i class="fas fa-redo-alt"></i> Book Again
        </a>
        <button onclick="deleteAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
            <i class="fas fa-trash-alt"></i> Delete
        </button>
    <?php endif; ?>
    
    <?php if ($appointment['status'] == 'missed'): ?>
        <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
            <i class="fas fa-redo-alt"></i> Book Again
        </a>
        <button onclick="viewPenalty(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
            <i class="fas fa-exclamation-triangle"></i> View Penalty
        </button>
    <?php endif; ?>
</div>

<!-- Refund Button (for cancelled appointments with downpayment) -->
<?php if ($appointment['status'] == 'cancelled' && ($downpayment_amount ?? 0) > 0 && !($refund_pending ?? false)): ?>
<div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
    <button onclick="requestRefund(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
        <i class="fas fa-money-bill-wave"></i> Request Refund (₱<?php echo number_format($downpayment_amount, 2); ?>)
    </button>
</div>
<?php elseif ($appointment['status'] == 'cancelled' && ($downpayment_amount ?? 0) > 0 && ($refund_pending ?? false)): ?>
<div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
        <i class="fas fa-spinner fa-pulse"></i> Refund Request Pending
    </button>
</div>
<?php endif; ?>
<!-- Refund Button (for cancelled appointments with downpayment) -->
<?php if ($appointment['status'] == 'cancelled' && ($downpayment_amount ?? 0) > 0 && !($refund_pending ?? false)): ?>
<div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
    <button onclick="requestRefund(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
        <i class="fas fa-money-bill-wave"></i> Request Refund (₱<?php echo number_format($downpayment_amount, 2); ?>)
    </button>
</div>
<?php elseif ($appointment['status'] == 'cancelled' && ($downpayment_amount ?? 0) > 0 && ($refund_pending ?? false)): ?>
<div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
        <i class="fas fa-spinner fa-pulse"></i> Refund Request Pending
    </button>
</div>
<?php endif; ?>
    <!-- Refund Button -->
    <?php if ($appointment['status']=='cancelled' && $downpayment_amount>0 && !$refund_pending): ?>
    <div class="action-buttons" style="margin-top:15px;justify-content:flex-end;">
        <button onclick="requestRefund(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning"><i class="fas fa-money-bill-wave"></i> Request Refund (₱<?php echo number_format($downpayment_amount,2); ?>)</button>
    </div>
    <?php elseif ($appointment['status']=='cancelled' && $downpayment_amount>0 && $refund_pending): ?>
    <div class="action-buttons" style="margin-top:15px;justify-content:flex-end;">
        <button class="action-btn action-btn-secondary" disabled style="opacity:0.6;cursor:not-allowed;"><i class="fas fa-spinner fa-pulse"></i> Refund Request Pending</button>
    </div>
    <?php endif; ?>

</div><!-- closing ng main-content -->

<!-- Image Modal -->
<div class="image-modal" id="imageModal" onclick="closeImageModal()">
    <span class="modal-close" onclick="closeImageModal()">&times;</span>
    <img class="modal-image" id="modalImage" src="" alt="">
</div>

<!-- Warranty Claim Modal -->
<div class="modal fade" id="warrantyClaimModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #00B761, #00A86B); color: white;">
                <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Request Warranty Service</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="warrantyProductInfo" class="alert alert-light border mb-3">
                    <!-- Dynamic product info -->
                </div>
                
                <div class="alert alert-warning small">
                    <i class="fas fa-info-circle"></i>
                    <strong>Walk-in Service Only</strong><br>
                    Please bring your product to the clinic for inspection.
                </div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Preferred Date</label>
                        <input type="date" id="claim_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Preferred Time</label>
                        <input type="time" id="claim_time" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Issue Description</label>
                        <textarea id="claim_description" class="form-control" rows="2" placeholder="Describe the issue..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Upload Reference Photos (Optional)</label>
                        <input type="file" id="claim_photos" class="form-control" multiple accept="image/*">
                        <small class="text-muted">Upload up to 3 photos</small>
                    </div>
                </div>
                
                <input type="hidden" id="claim_reservation_id">
                <input type="hidden" id="claim_product_id">
                <input type="hidden" id="claim_product_name">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" style="background: #00B761; color: white;" onclick="submitWarrantyClaim()">Submit Request</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════════
// GALLERY SLIDER  (clinic-details.php pattern)
// ══════════════════════════════════════════════════════════════
let galleryCurrent = 0;
const galleryTotal = <?php echo $img_count; ?>;
const galleryTrack = document.getElementById('galleryTrack');
const galleryDots  = document.querySelectorAll('.gallery-dot');
const galleryCtr   = document.getElementById('galleryCounter');

function galleryGo(index) {
    if (!galleryTrack || galleryTotal <= 1) return;
    galleryCurrent = ((index % galleryTotal) + galleryTotal) % galleryTotal;
    galleryTrack.style.transform = 'translateX(' + (-galleryCurrent * 100) + '%)';
    galleryDots.forEach((d, i) => d.classList.toggle('active', i === galleryCurrent));
    if (galleryCtr) galleryCtr.innerHTML = '<i class="fas fa-images"></i> ' + (galleryCurrent + 1) + ' / ' + galleryTotal;
}

// Swipe support on gallery
(function() {
    if (!galleryTrack) return;
    let sx = 0;
    galleryTrack.addEventListener('touchstart', e => { sx = e.touches[0].clientX; });
    galleryTrack.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - sx;
        if (Math.abs(dx) > 40) galleryGo(galleryCurrent + (dx < 0 ? 1 : -1));
    });
})();

// ============================================
// WARRANTY CLAIM FUNCTIONS
// ============================================

function openWarrantyClaim(reservationId, productId, productName) {
    // Reset form
    document.getElementById('claim_date').value = '';
    document.getElementById('claim_time').value = '';
    document.getElementById('claim_description').value = '';
    document.getElementById('claim_photos').value = '';
    
    // Set hidden fields
    document.getElementById('claim_reservation_id').value = reservationId;
    document.getElementById('claim_product_id').value = productId;
    document.getElementById('claim_product_name').value = productName;
    
    // Set product info
    document.getElementById('warrantyProductInfo').innerHTML = `
        <div class="d-flex gap-3">
            <i class="fas fa-box" style="font-size: 40px; color: #00B761;"></i>
            <div>
                <strong>${escapeHtml(productName)}</strong><br>
                <small>Reservation #: ${reservationId}</small><br>
                <small class="text-teal">Warranty claim will be verified by the clinic</small>
            </div>
        </div>
    `;
    
    // Set default date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('claim_date').value = tomorrow.toISOString().split('T')[0];
    
    // Show modal using Bootstrap 5
    const modal = new bootstrap.Modal(document.getElementById('warrantyClaimModal'));
    modal.show();
}

function submitWarrantyClaim() {
    const reservationId = document.getElementById('claim_reservation_id').value;
    const productId = document.getElementById('claim_product_id').value;
    const productName = document.getElementById('claim_product_name').value;
    const claimDate = document.getElementById('claim_date').value;
    const claimTime = document.getElementById('claim_time').value;
    const description = document.getElementById('claim_description').value;
    
    if (!claimDate) {
        Swal.fire('Error', 'Please select a date', 'error');
        return;
    }
    if (!claimTime) {
        Swal.fire('Error', 'Please select a time', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Submitting...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'warranty_claim');
    formData.append('reservation_id', reservationId);
    formData.append('product_id', productId);
    formData.append('product_name', productName);
    formData.append('claim_date', claimDate);
    formData.append('claim_time', claimTime);
    formData.append('description', description);
    
    // Add photos
    const photosInput = document.getElementById('claim_photos');
    if (photosInput.files) {
        for (let i = 0; i < Math.min(photosInput.files.length, 3); i++) {
            formData.append('photos[]', photosInput.files[i]);
        }
    }
    
    fetch('/api/warranty-claim.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('warrantyClaimModal'));
            modal.hide();
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                confirmButtonColor: '#00B761'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error!', data.message, 'error');
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Image modal
function openImageModal(src, alt) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalImage').alt = alt;
    document.getElementById('imageModal').classList.add('show');
}
function closeImageModal() { document.getElementById('imageModal').classList.remove('show'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImageModal(); });

// ══════════════════════════════════════════════════════════════
// ACTION HANDLERS
// ══════════════════════════════════════════════════════════════
async function cancelAppointment(id) {
    const r = await Swal.fire({ title:'Cancel Appointment?', text:'This cannot be undone.', icon:'warning', showCancelButton:true, confirmButtonColor:'#EF4444', cancelButtonColor:'#6B7280', confirmButtonText:'Yes, cancel it!', cancelButtonText:'No, keep it' });
    if (!r.isConfirmed) return;
    Swal.fire({ title:'Cancelling...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new URLSearchParams(); fd.append('cancel_appointment','1'); fd.append('appointment_id',id);
    try {
        const res = await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() });
        const data = await res.json();
        if (data.success) { await Swal.fire({ icon:'success', title:'Cancelled!', text:data.message, confirmButtonColor:'#00B761' }); location.reload(); }
        else Swal.fire({ icon:'error', title:'Failed', text:data.message, confirmButtonColor:'#EF4444' });
    } catch { Swal.fire({ icon:'error', title:'Error', text:'An error occurred.' }); }
}

async function requestRefund(id) {
    const amt = <?php echo $downpayment_amount; ?>;
    const r = await Swal.fire({
        title:'Request Refund', icon:'info', showCancelButton:true,
        confirmButtonColor:'#F59E0B', cancelButtonColor:'#6B7280',
        confirmButtonText:'Yes, Request Refund', cancelButtonText:'Cancel',
        html:`<div style="text-align:left;"><p>Request a refund for this cancelled appointment?</p><div style="background:#FFF3E0;padding:15px;border-radius:10px;margin:15px 0;text-align:center;"><i class="fas fa-money-bill-wave" style="font-size:24px;color:#F59E0B;"></i><div style="font-size:20px;font-weight:bold;color:#F59E0B;">₱${amt.toFixed(2)}</div><div style="font-size:12px;color:#666;">Refund Amount</div></div><p style="font-size:13px;color:#6B7280;">Processed within 5–7 business days.</p></div>`
    });
    if (!r.isConfirmed) return;
    Swal.fire({ title:'Submitting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new URLSearchParams(); fd.append('request_refund','1'); fd.append('appointment_id',id);
    try {
        const res = await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() });
        const data = await res.json();
        if (data.success) { await Swal.fire({ icon:'success', title:'Submitted!', text:data.message, confirmButtonColor:'#00B761' }); location.reload(); }
        else Swal.fire({ icon:'error', title:'Request Failed', text:data.message, confirmButtonColor:'#EF4444' });
    } catch { Swal.fire({ icon:'error', title:'Error', text:'An error occurred.' }); }
}

async function deleteAppointment(id) {
    const r = await Swal.fire({ title:'Delete Appointment?', text:'This will be permanently removed.', icon:'warning', showCancelButton:true, confirmButtonColor:'#EF4444', cancelButtonColor:'#6B7280', confirmButtonText:'Yes, delete it!' });
    if (!r.isConfirmed) return;
    Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new URLSearchParams(); fd.append('delete_appointment','1'); fd.append('appointment_id',id);
    try {
        const res = await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() });
        const data = await res.json();
        if (data.success) { await Swal.fire({ icon:'success', title:'Deleted!', text:data.message, confirmButtonColor:'#00B761' }); window.location.href='my-appointments.php'; }
        else Swal.fire({ icon:'error', title:'Failed', text:data.message, confirmButtonColor:'#EF4444' });
    } catch { Swal.fire({ icon:'error', title:'Error', text:'An error occurred.' }); }
}

async function viewPenalty(id) {
    Swal.fire({ title:'Checking...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new URLSearchParams(); fd.append('view_penalty','1'); fd.append('appointment_id',id);
    try {
        const res = await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() });
        const data = await res.json();
        if (data.success) Swal.fire({ title:'No-Show Penalty', icon:'warning', confirmButtonColor:'#7C3AED', confirmButtonText:'Got it', html:`<div style="text-align:left;"><p>You missed your scheduled appointment.</p><div style="background:#F3E8FF;padding:12px;border-radius:10px;margin:10px 0;text-align:center;"><i class="fas fa-exclamation-triangle" style="font-size:24px;color:#7C3AED;"></i><div style="font-size:20px;font-weight:bold;color:#7C3AED;margin-top:5px;">₱${data.penalty_amount.toFixed(2)}</div><div style="font-size:12px;">Penalty Fee</div></div><p style="font-size:13px;">Please contact the clinic for more information.</p></div>` });
    } catch { Swal.fire({ icon:'error', title:'Error', text:'Could not retrieve penalty information.' }); }
}

function shareExperience() {
    const clinicName = "<?php echo addslashes($appointment['clinic_name']); ?>";
    if (navigator.share) {
        navigator.share({ title:'My Appointment at '+clinicName, url:window.location.href });
    } else {
        navigator.clipboard.writeText(window.location.href).then(()=>{
            Swal.fire({ icon:'success', title:'Link Copied!', text:'Link copied to clipboard.', timer:2000, showConfirmButton:false });
        });
    }
}
</script>
</body>
</html>