<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/config.php';
include '../includes/theme.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id      = $_SESSION['user_id'];
$clinic_id    = isset($_GET['clinic'])      ? (int)$_GET['clinic']      : 0;
$appointment_id = isset($_GET['appointment']) ? (int)$_GET['appointment'] : 0;

if (!$clinic_id || !$appointment_id) {
    header('Location: my-appointments.php');
    exit();
}

// Verify appointment belongs to user
$apt_query = mysqli_query($conn, "
    SELECT a.*,
           c.clinic_name,
           c.address,
           c.logo,
           c.clinic_image,
           c.cover_photo,
           p.name as product_name,
           p.category as product_category
    FROM appointments a
    JOIN clinics c ON a.clinic_id = c.id
    LEFT JOIN products p ON a.product_id = p.id
    WHERE a.id = $appointment_id
      AND a.user_id = $user_id
      AND a.clinic_id = $clinic_id
");

$appointment = mysqli_fetch_assoc($apt_query);

if (!$appointment) {
    header('Location: my-appointments.php');
    exit();
}

// Check if user already reviewed this appointment
$existing_review = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM clinic_reviews
     WHERE user_id = $user_id AND clinic_id = $clinic_id AND appointment_id = $appointment_id
     LIMIT 1"
));

// Pull any photos already attached to that review
$existing_review_images = [];
if ($existing_review) {
    $existing_review_id = (int)$existing_review['id'];
    $img_q = mysqli_query($conn, "SELECT image_path FROM clinic_review_images WHERE review_id = $existing_review_id");
    while ($row = mysqli_fetch_assoc($img_q)) {
        $existing_review_images[] = $row['image_path'];
    }
}

$uploaded_review_images = [];

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$success_message = '';
$error_message   = '';
$debug_info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $review = mysqli_real_escape_string($conn, trim($_POST['review'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        $error_message = 'Please select a rating from 1 to 5 stars.';
    } elseif (empty($review)) {
        $error_message = 'Please write your review before submitting.';
    } elseif (strlen($review) < 10) {
        $error_message = 'Your review is too short. Please write at least 10 characters.';
    } elseif ($existing_review) {
        $error_message = 'You have already submitted a review for this appointment.';
    } else {
        // Insert review
        $insert = mysqli_query($conn, "
            INSERT INTO clinic_reviews (clinic_id, user_id, appointment_id, rating, review, created_at)
            VALUES ($clinic_id, $user_id, $appointment_id, $rating, '$review', NOW())
        ");

        if ($insert) {
            $new_review_id = mysqli_insert_id($conn);
            $existing_review = ['id' => $new_review_id];
            
            // ============================================================
            // PHOTO UPLOAD - FIXED WITH ABSOLUTE PATH
            // ============================================================
            $upload_errors = [];
            $upload_success_count = 0;
            
            $debug_info[] = "🔍 Checking for files...";
            $debug_info[] = "Document Root: " . $_SERVER['DOCUMENT_ROOT'];
            
            // 🔥 FIX: GAMITIN ANG ABSOLUTE PATH
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/eyecore/assets/images/review-photos/';
            $debug_info[] = "📁 Upload directory: " . $upload_dir;
            
            // CREATE FOLDER IF NOT EXISTS
            if (!is_dir($upload_dir)) {
                if (mkdir($upload_dir, 0777, true)) {
                    $debug_info[] = "✅ Folder created successfully!";
                } else {
                    $debug_info[] = "❌ Failed to create folder!";
                    $upload_errors[] = 'Failed to create upload folder.';
                }
            }
            
            // CHECK IF WRITABLE
            if (is_writable($upload_dir)) {
                $debug_info[] = "✅ Folder is writable!";
            } else {
                $debug_info[] = "❌ Folder is NOT writable!";
                $upload_errors[] = 'Upload folder is not writable. Please set permission to 755 or 777.';
                // Try to set permission
                @chmod($upload_dir, 0777);
            }
            
            // CHECK IF FILES EXIST
            if (isset($_FILES['review_images']) && !empty($_FILES['review_images']['name'][0])) {
                
                $debug_info[] = "✅ FILES DETECTED! Count: " . count($_FILES['review_images']['name']);
                
                $total_files = count($_FILES['review_images']['name']);
                $max_photos = 5;
                
                for ($i = 0; $i < $total_files && $i < $max_photos; $i++) {
                    
                    $debug_info[] = "--- Processing file $i ---";
                    $debug_info[] = "Name: " . $_FILES['review_images']['name'][$i];
                    $debug_info[] = "Size: " . $_FILES['review_images']['size'][$i];
                    $debug_info[] = "Error: " . $_FILES['review_images']['error'][$i];
                    
                    if ($_FILES['review_images']['error'][$i] !== UPLOAD_ERR_OK) {
                        $upload_errors[] = 'File ' . ($i+1) . ' upload error: ' . $_FILES['review_images']['error'][$i];
                        continue;
                    }
                    
                    $tmp_path = $_FILES['review_images']['tmp_name'][$i];
                    $file_size = $_FILES['review_images']['size'][$i];
                    $file_name = $_FILES['review_images']['name'][$i];
                    
                    // VALIDATE SIZE
                    if ($file_size > 5 * 1024 * 1024) {
                        $upload_errors[] = 'File ' . ($i+1) . ' (' . $file_name . ') is too large. Max 5MB.';
                        continue;
                    }
                    
                    // GET EXTENSION
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    
                    if (!in_array($ext, $allowed)) {
                        $upload_errors[] = 'File ' . ($i+1) . ' (' . $file_name . ') invalid format. Use JPG, PNG, or WEBP.';
                        continue;
                    }
                    
                    // GENERATE NEW FILENAME
                    $new_filename = 'review_' . $new_review_id . '_' . time() . '_' . $i . '.' . $ext;
                    $dest_path = $upload_dir . $new_filename;
                    
                    $debug_info[] = "📁 Dest: " . $dest_path;
                    
                    // MOVE FILE
                    if (move_uploaded_file($tmp_path, $dest_path)) {
                        $debug_info[] = "✅ File moved successfully!";
                        
                        // 🔥 FIX: GAMITIN ANG TAMANG WEB PATH
                        $web_path = '/eyecore/assets/images/review-photos/' . $new_filename;
                        
                        // SAVE TO DATABASE
                        $img_insert = mysqli_query($conn, "
                            INSERT INTO clinic_review_images (review_id, image_path, created_at)
                            VALUES ($new_review_id, '" . mysqli_real_escape_string($conn, $web_path) . "', NOW())
                        ");
                        
                        if ($img_insert) {
                            $upload_success_count++;
                            $uploaded_review_images[] = $web_path;
                            $debug_info[] = "✅✅✅ SAVED TO DB: " . $web_path;
                        } else {
                            $upload_errors[] = 'File ' . ($i+1) . ' DB error: ' . mysqli_error($conn);
                            $debug_info[] = "❌ DB Error: " . mysqli_error($conn);
                            if (file_exists($dest_path)) {
                                unlink($dest_path);
                            }
                        }
                    } else {
                        $upload_errors[] = 'File ' . ($i+1) . ' (' . $file_name . ') failed to move.';
                        $debug_info[] = "❌ Move failed!";
                    }
                }
            } else {
                $debug_info[] = "❌ NO FILES DETECTED!";
                $debug_info[] = "FILES array: " . print_r($_FILES, true);
            }
            
            // DISPLAY UPLOAD ERRORS
            if (!empty($upload_errors)) {
                $error_message = '⚠️ Some images failed to upload:<br><ul>';
                foreach ($upload_errors as $err) {
                    $error_message .= '<li>' . htmlspecialchars($err) . '</li>';
                }
                $error_message .= '</ul>';
            }
            
            // SHOW DEBUG INFO (only if there were upload issues)
            if (!empty($debug_info) && empty($uploaded_review_images)) {
                $error_message .= '<br><details style="margin-top:10px;"><summary>🔍 Debug Info</summary><pre style="background:#1a1a2e;color:#00ff88;padding:15px;border-radius:8px;font-size:12px;max-height:300px;overflow:auto;margin-top:10px;">' . implode("\n", $debug_info) . '</pre></details>';
            }
            
            // Add notification
            if (function_exists('addNotification')) {
                addNotification(
                    $user_id,
                    'appointment',
                    'Review Submitted',
                    "Your review for {$appointment['clinic_name']} has been submitted. Thank you!",
                    'my-appointments.php'
                );
            }
            
            $success_message = 'Your review has been submitted successfully!';
            
        } else {
            $error_message = 'Something went wrong. Please try again.';
        }
    }
}

// ============================================
// NAVBAR DATA (shortened for brevity)
// ============================================
$user_query  = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user        = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data   = mysqli_fetch_assoc($avatar_query);

$pending_q   = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending     = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

$unread_count       = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user_id) : 0;
$recent_notifications = function_exists('getRecentNotifications')   ? getRecentNotifications($user_id)     : [];

$total_bookings_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings   = mysqli_fetch_assoc($total_bookings_q)['total'] ?? 0;

$points_row   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points = $points_row['t'] ?: 0;

// Clinic rating
$rating_data    = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(AVG(rating),0) as avg_rating, COUNT(*) as total FROM clinic_reviews WHERE clinic_id = $clinic_id"
));
$clinic_avg_rating    = round($rating_data['avg_rating'], 1);
$clinic_total_reviews = $rating_data['total'];

// Helper functions
if (!function_exists('getThemeClass')) {
    function getThemeClass() {
        return isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'theme-dark' : '';
    }
}

if (!function_exists('getClinicImg')) {
    function getClinicImg($c) {
        if (!empty($c['cover_photo']))  return '/eyecore/assets/images/clinic-covers/'  . $c['cover_photo'];
        if (!empty($c['clinic_image'])) return '/eyecore/assets/images/clinic-images/'  . $c['clinic_image'];
        if (!empty($c['logo']))         return '/eyecore/assets/images/clinic-logos/'   . $c['logo'];
        return null;
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $diff = time() - strtotime($timestamp);
        if ($diff < 60)       return 'Just Now';
        if ($diff < 3600)     return round($diff/60)   . ' minutes ago';
        if ($diff < 86400)    return round($diff/3600)  . ' hours ago';
        if ($diff < 604800)   return round($diff/86400) . ' days ago';
        return date('M j, Y', strtotime($timestamp));
    }
}

if (!function_exists('renderStarRating')) {
    function renderStarRating($rating) {
        $full  = floor($rating);
        $half  = ($rating - $full) >= 0.5;
        $empty = 5 - $full - ($half ? 1 : 0);
        $html  = str_repeat('<i class="fas fa-star"></i>', $full);
        if ($half)  $html .= '<i class="fas fa-star-half-alt"></i>';
        $html .= str_repeat('<i class="far fa-star"></i>', $empty);
        return $html;
    }
}

if (!function_exists('getNotificationIcon')) {
    function getNotificationIcon($t) {
        return ['appointment'=>'fa-calendar-check','favorite'=>'fa-heart','promo'=>'fa-tags'][$t] ?? 'fa-bell';
    }
}

$active_nav = 'appointments';
include '../includes/navbar.php';
?>

<!-- HTML AND CSS (same as before - kept short for brevity) -->
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Write a Review - <?php echo htmlspecialchars($appointment['clinic_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* YOUR EXISTING CSS HERE (copy from your current file) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #1a1a2e;
            --text-secondary: #4a4a6a;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            --primary: #00b761;
            --primary-light: #e8f5e9;
            --primary-gradient: linear-gradient(135deg, #00b761 0%, #008a4a 100%);
            --danger: #ef4444;
            --warning: #f59e0b;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-full: 9999px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.08);
        }
        .theme-dark {
            --bg-primary: #0f0f1a;
            --bg-secondary: #1a1a2e;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: #2d2d44;
            --border-light: #25253a;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 40px;
        }
        @media (max-width: 768px) { .main-content { padding: 18px 16px; } }
        
        .card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-light);
        }
        .card-header i {
            width: 40px;
            height: 40px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 18px;
            flex-shrink: 0;
        }
        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .card-body { padding: 25px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .form-label span {
            font-size: 12px;
            font-weight: 400;
            color: var(--text-muted);
            margin-left: 6px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px;
            background: var(--bg-primary);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            color: var(--text-primary);
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            resize: vertical;
            min-height: 140px;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 183, 97, 0.1);
        }
        
        .star-picker {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        .star-picker input[type="radio"] { display: none; }
        .star-picker label {
            font-size: 36px;
            color: var(--border-color);
            cursor: pointer;
            transition: color 0.15s, transform 0.15s;
            line-height: 1;
        }
        .star-picker label:hover,
        .star-picker label:hover ~ label,
        .star-picker input[type="radio"]:checked ~ label {
            color: #FFC107;
        }
        .star-picker label:hover { transform: scale(1.15); }
        
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            font-family: inherit;
        }
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.35);
        }
        .btn-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .alert-error   { background: #FFF0F0; color: var(--danger); border: 1px solid #FFD5D5; }
        .alert-success { background: #F0FFF6; color: #1a7a3c; border: 1px solid #b3f0cc; }
        .alert ul { margin: 5px 0 0 20px; }
        
        .photo-upload-zone {
            border: 1.5px dashed var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            text-align: center;
            background: var(--bg-primary);
        }
        .photo-upload-zone input[type="file"] {
            display: block;
            margin: 10px auto 0;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            width: 100%;
            background: var(--bg-secondary);
        }
        .photo-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .photo-thumb {
            width: 80px;
            height: 80px;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--border-light);
        }
        .photo-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-count-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }
        .photo-count-hint.limit { color: var(--danger); }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title i {
            font-size: 24px;
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }
        .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .back-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .review-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 25px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .review-grid { grid-template-columns: 1fr; }
        }
        
        .clinic-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        .clinic-avatar {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            flex-shrink: 0;
        }
        .clinic-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .clinic-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .clinic-rating-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .stars-display {
            display: inline-flex;
            gap: 2px;
            color: #FFC107;
            font-size: 12px;
        }
        .rating-value {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
        }
        .reviews-count {
            font-size: 12px;
            color: var(--text-muted);
        }
        .clinic-detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }
        .clinic-detail-item i {
            width: 20px;
            color: var(--primary);
            font-size: 14px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .clinic-detail-item strong {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .clinic-detail-item span {
            font-size: 14px;
            color: var(--text-primary);
        }
        .apt-ref-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }
        .apt-ref-row i {
            color: var(--primary);
            font-size: 13px;
            width: 16px;
        }
        .apt-ref-row span {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .apt-ref-row strong {
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 600;
        }
        .apt-ref-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .success-state {
            text-align: center;
            padding: 40px 20px;
        }
        .success-icon-wrap {
            width: 80px;
            height: 80px;
            background: var(--primary-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .success-icon-wrap i {
            font-size: 36px;
            color: var(--primary);
        }
        .success-state h3 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        .success-state p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 28px;
        }
        .success-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-primary-solid {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary-solid:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.3);
        }
        .btn-outline-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: transparent;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-outline-pill:hover {
            background: var(--primary);
            color: white;
        }
        .already-reviewed {
            text-align: center;
            padding: 40px 20px;
        }
        .already-reviewed i {
            font-size: 56px;
            color: #FFC107;
            margin-bottom: 16px;
            display: block;
        }
        .already-reviewed h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .btn-back-apt {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-back-apt:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.3);
        }
        .tip-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .tip-chip {
            padding: 6px 14px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        .tip-chip:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }
        .char-count {
            text-align: right;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }
        .char-count.limit { color: var(--danger); }
        .star-rating-text {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: -18px;
            margin-bottom: 24px;
            min-height: 18px;
        }
        .star-picker-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: block;
        }
        .review-photos-display {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
            justify-content: center;
        }
        .review-photos-display img {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
        }
        .review-photos-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }
        .review-photos-section h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
            align-items: center;
            justify-content: center;
        }
        .loading-overlay.show { display: flex; }
        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<div class="main-content">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-pen"></i>
            <h1>Write a Review</h1>
        </div>
        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Appointment
        </a>
    </div>

    <div class="review-grid">

        <!-- LEFT: Review Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-star"></i>
                <h2>Share Your Experience</h2>
            </div>
            <div class="card-body">

                <?php if ($success_message): ?>
                <!-- SUCCESS STATE -->
                <div class="success-state">
                    <div class="success-icon-wrap">
                        <i class="fas fa-check"></i>
                    </div>
                    <h3>Review Submitted!</h3>
                    <p>Thank you for sharing your experience at <strong><?php echo htmlspecialchars($appointment['clinic_name']); ?></strong>.</p>

                    <?php if (!empty($uploaded_review_images)): ?>
                    <div class="review-photos-section">
                        <h4>📸 Your Uploaded Photos</h4>
                        <div class="review-photos-display">
                            <?php foreach ($uploaded_review_images as $img_path): ?>
                                <img src="<?php echo htmlspecialchars($img_path); ?>" alt="Review photo">
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="success-actions">
                        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="btn-primary-solid">
                            <i class="fas fa-calendar-check"></i> View Appointment
                        </a>
                        <a href="my-appointments.php" class="btn-outline-pill">
                            <i class="fas fa-list"></i> All Appointments
                        </a>
                    </div>
                </div>

                <?php elseif ($existing_review && !$success_message): ?>
                <!-- ALREADY REVIEWED -->
                <div class="already-reviewed">
                    <i class="fas fa-star"></i>
                    <h3>Already Reviewed</h3>
                    <p>You've already submitted a review for this appointment.</p>
                    <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="btn-back-apt">
                        <i class="fas fa-arrow-left"></i> Back to Appointment
                    </a>
                </div>

                <?php else: ?>
                <!-- REVIEW FORM -->
                
                <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error_message; ?></div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="reviewForm" enctype="multipart/form-data">

                    <!-- Star Rating -->
                    <span class="star-picker-label">Your Rating <span style="color:var(--danger)">*</span></span>
                    <div class="star-picker" id="starPicker">
                        <input type="radio" name="rating" id="star5" value="5">
                        <label for="star5"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star4" value="4">
                        <label for="star4"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star3" value="3">
                        <label for="star3"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star2" value="2">
                        <label for="star2"><i class="fas fa-star"></i></label>
                        <input type="radio" name="rating" id="star1" value="1">
                        <label for="star1"><i class="fas fa-star"></i></label>
                    </div>
                    <div class="star-rating-text" id="ratingText">Click a star to rate</div>

                    <!-- Quick Tips -->
                    <div class="form-group">
                        <label class="form-label">Quick Phrases <span>tap to add</span></label>
                        <div class="tip-chips">
                            <div class="tip-chip" onclick="addTip('Great service!')">Great service!</div>
                            <div class="tip-chip" onclick="addTip('Very professional staff.')">Professional staff</div>
                            <div class="tip-chip" onclick="addTip('Clean and comfortable clinic.')">Clean clinic</div>
                            <div class="tip-chip" onclick="addTip('Highly recommended!')">Highly recommended</div>
                            <div class="tip-chip" onclick="addTip('Fast and efficient.')">Fast & efficient</div>
                            <div class="tip-chip" onclick="addTip('Friendly doctors.')">Friendly doctors</div>
                            <div class="tip-chip" onclick="addTip('Good value for money.')">Good value</div>
                            <div class="tip-chip" onclick="addTip('Will visit again.')">Will visit again</div>
                        </div>
                    </div>

                    <!-- Review Text -->
                    <div class="form-group">
                        <label class="form-label" for="review">Your Review <span>minimum 10 characters</span></label>
                        <textarea
                            name="review"
                            id="review"
                            class="form-control"
                            maxlength="1000"
                            placeholder="Share your experience — how was the service, the staff, the clinic environment?"
                            oninput="updateCharCount(this)"
                        ><?php echo isset($_POST['review']) ? htmlspecialchars($_POST['review']) : ''; ?></textarea>
                        <div class="char-count" id="charCount">0 / 1000</div>
                    </div>

                    <!-- Photo Upload -->
                    <div class="form-group">
                        <label class="form-label">Add Photos <span>optional, up to 5 images</span></label>
                        <div class="photo-upload-zone">
                            <i class="fas fa-camera" style="font-size:26px;color:var(--primary);display:block;margin-bottom:8px;"></i>
                            <p style="font-size:13px;color:var(--text-secondary);font-weight:500;">Click to select photos</p>
                            <span style="font-size:12px;color:var(--text-muted);">JPG, PNG or WEBP · Max 5MB each</span>
                            <input type="file" name="review_images[]" id="reviewImages" accept="image/jpeg,image/png,image/webp" multiple style="display:block;margin-top:10px;padding:10px;border:1px solid var(--border-color);border-radius:8px;width:100%;background:var(--bg-secondary);">
                        </div>
                        <div class="photo-previews" id="photoPreviews"></div>
                        <div class="photo-count-hint" id="photoCountHint"></div>
                    </div>

                    <button type="submit" name="submit_review" class="btn-submit" id="submitBtn" disabled>
                        <i class="fas fa-paper-plane"></i> Submit Review
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>

        <!-- RIGHT: Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Clinic Info -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clinic-medical"></i>
                    <h2>Clinic Info</h2>
                </div>
                <div class="card-body">
                    <div class="clinic-header">
                        <?php $clinic_img = getClinicImg($appointment); ?>
                        <div class="clinic-avatar">
                            <?php if ($clinic_img): ?>
                                <img src="<?php echo htmlspecialchars($clinic_img); ?>" alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>">
                            <?php else: ?>
                                <i class="fas fa-eye"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="clinic-name"><?php echo htmlspecialchars($appointment['clinic_name']); ?></div>
                            <div class="clinic-rating-row">
                                <?php if ($clinic_total_reviews > 0): ?>
                                    <div class="stars-display"><?php echo renderStarRating($clinic_avg_rating); ?></div>
                                    <span class="rating-value"><?php echo $clinic_avg_rating; ?></span>
                                    <span class="reviews-count">(<?php echo $clinic_total_reviews; ?>)</span>
                                <?php else: ?>
                                    <div class="no-rating"><i class="far fa-star"></i> No reviews yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($appointment['address'])): ?>
                    <div class="clinic-detail-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <strong>Address</strong>
                            <span><?php echo htmlspecialchars($appointment['address']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Appointment Summary -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-check"></i>
                    <h2>Appointment Summary</h2>
                </div>
                <div class="card-body">
                    <div class="apt-ref-label">Details</div>
                    <div class="apt-ref-row">
                        <i class="fas fa-calendar"></i>
                        <span>Date:</span>
                        <strong><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></strong>
                    </div>
                    <div class="apt-ref-row">
                        <i class="fas fa-clock"></i>
                        <span>Time:</span>
                        <strong><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></strong>
                    </div>
                    <div class="apt-ref-row">
                        <i class="fas fa-info-circle"></i>
                        <span>Status:</span>
                        <strong style="color: var(--primary); text-transform: capitalize;">
                            <?php echo htmlspecialchars($appointment['status']); ?>
                        </strong>
                    </div>
                </div>
            </div>

        </div><!-- end sidebar -->
    </div><!-- end grid -->
</div><!-- end main-content -->

<script>
    const ratingLabels = {
        1: '⭐ Poor — Not what I expected.',
        2: '⭐⭐ Fair — Could be better.',
        3: '⭐⭐⭐ Good — Decent experience.',
        4: '⭐⭐⭐⭐ Very Good — Happy with the service.',
        5: '⭐⭐⭐⭐⭐ Excellent — Highly recommended!'
    };

    let selectedRating = 0;

    document.querySelectorAll('.star-picker input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function () {
            selectedRating = parseInt(this.value);
            document.getElementById('ratingText').textContent = ratingLabels[selectedRating] || '';
            validateForm();
        });
    });

    function addTip(text) {
        const ta = document.getElementById('review');
        const current = ta.value.trim();
        ta.value = current ? current + ' ' + text : text;
        updateCharCount(ta);
    }

    function updateCharCount(el) {
        const count = el.value.length;
        const display = document.getElementById('charCount');
        display.textContent = count + ' / 1000';
        display.className = 'char-count' + (count > 900 ? ' limit' : '');
        validateForm();
    }

    const photoInput = document.getElementById('reviewImages');
    const photoPreviews = document.getElementById('photoPreviews');
    const photoHint = document.getElementById('photoCountHint');

    if (photoInput) {
        photoInput.addEventListener('change', function() {
            const files = this.files;
            const totalFiles = files.length;
            
            photoPreviews.innerHTML = '';
            
            for (let i = 0; i < totalFiles && i < 5; i++) {
                const file = files[i];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const thumb = document.createElement('div');
                    thumb.className = 'photo-thumb';
                    thumb.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                    photoPreviews.appendChild(thumb);
                };
                reader.readAsDataURL(file);
            }
            
            photoHint.textContent = totalFiles > 0 ? `${totalFiles} / 5 photos selected` : '';
            photoHint.className = 'photo-count-hint' + (totalFiles >= 5 ? ' limit' : '');
            validateForm();
        });
    }

    function validateForm() {
        const review = document.getElementById('review').value.trim();
        const btn = document.getElementById('submitBtn');
        if (!btn) return;
        btn.disabled = !(selectedRating > 0 && review.length >= 10);
    }

    document.getElementById('reviewForm')?.addEventListener('submit', function (e) {
        const review = document.getElementById('review').value.trim();
        if (selectedRating < 1) {
            e.preventDefault();
            alert('Please select a star rating.');
            return;
        }
        if (review.length < 10) {
            e.preventDefault();
            alert('Please write at least 10 characters in your review.');
            return;
        }
        document.getElementById('loadingOverlay').classList.add('show');
    });

    const reviewEl = document.getElementById('review');
    if (reviewEl) updateCharCount(reviewEl);
</script>

</body>
</html>