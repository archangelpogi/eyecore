<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include '../includes/config.php';
include '../includes/theme.php';

// ============================================
// ADDRESS AJAX HANDLERS
// ============================================
if (isset($_GET['addr_action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $uid = (int)$_SESSION['user_id'];
    $action = $_GET['addr_action'];
    
    // ── GET ALL ADDRESSES ──
    if ($action === 'list') {
        $q = mysqli_query($conn, "
            SELECT id, label, recipient_name, recipient_phone,
                   street_address, barangay, city, province, zip_code, landmark,
                   latitude, longitude, is_default
            FROM user_addresses
            WHERE user_id = $uid
            ORDER BY is_default DESC, created_at DESC
        ");
        $list = [];
        while ($r = mysqli_fetch_assoc($q)) {
            $r['is_default'] = (int)$r['is_default'];
            $list[] = $r;
        }
        echo json_encode(['success' => true, 'addresses' => $list]);
        exit;
    }
    
    // ── SAVE ADDRESS (INSERT or UPDATE) ──
    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $label          = mysqli_real_escape_string($conn, trim($_POST['label'] ?? 'Home'));
        $rname          = mysqli_real_escape_string($conn, trim($_POST['recipient_name'] ?? ''));
        $rphone         = mysqli_real_escape_string($conn, trim($_POST['recipient_phone'] ?? ''));
        $street         = mysqli_real_escape_string($conn, trim($_POST['street_address'] ?? ''));
        $brgy           = mysqli_real_escape_string($conn, trim($_POST['barangay'] ?? ''));
        $city           = mysqli_real_escape_string($conn, trim($_POST['city'] ?? ''));
        $prov           = mysqli_real_escape_string($conn, trim($_POST['province'] ?? 'Cavite'));
        $zip            = mysqli_real_escape_string($conn, trim($_POST['zip_code'] ?? ''));
        $landmark       = mysqli_real_escape_string($conn, trim($_POST['landmark'] ?? ''));
        $lat            = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng            = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $is_default     = !empty($_POST['is_default']) ? 1 : 0;
        
        // Validation
        if (!$rname)  { echo json_encode(['success'=>false,'message'=>'Recipient name required.']); exit; }
        if (!$rphone) { echo json_encode(['success'=>false,'message'=>'Phone number required.']); exit; }
        if (!$street) { echo json_encode(['success'=>false,'message'=>'Street address required.']); exit; }
        if (!$brgy)   { echo json_encode(['success'=>false,'message'=>'Barangay required.']); exit; }
        if (!$city)   { echo json_encode(['success'=>false,'message'=>'City required.']); exit; }
        if (!$zip)    { echo json_encode(['success'=>false,'message'=>'ZIP code required.']); exit; }
        
        if (stripos($prov, 'Cavite') === false) {
            echo json_encode(['success'=>false,'message'=>'Only addresses in Cavite are supported.']);
            exit;
        }
        
        $lat_sql = $lat !== null ? $lat : 'NULL';
        $lng_sql = $lng !== null ? $lng : 'NULL';
        $landmark_sql = $landmark ? "'$landmark'" : 'NULL';
        
        // First address is automatically default
        $c = (int)mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM user_addresses WHERE user_id = $uid"))['c'];
        if ($c === 0) $is_default = 1;
        
        // If setting default, unset others
        if ($is_default) {
            mysqli_query($conn, "UPDATE user_addresses SET is_default = 0 WHERE user_id = $uid");
        }
        
        if ($id > 0) {
            // UPDATE — verify ownership
            if (mysqli_num_rows(mysqli_query($conn,
                "SELECT id FROM user_addresses WHERE id = $id AND user_id = $uid")) === 0) {
                echo json_encode(['success'=>false,'message'=>'Address not found.']);
                exit;
            }
            mysqli_query($conn, "
                UPDATE user_addresses SET
                    label='$label', recipient_name='$rname', recipient_phone='$rphone',
                    street_address='$street', barangay='$brgy', city='$city',
                    province='$prov', zip_code='$zip', landmark=$landmark_sql,
                    latitude=$lat_sql, longitude=$lng_sql, is_default=$is_default,
                    updated_at=NOW()
                WHERE id = $id AND user_id = $uid
            ");
            $new_id = $id;
        } else {
            mysqli_query($conn, "
                INSERT INTO user_addresses
                (user_id, label, recipient_name, recipient_phone, street_address,
                 barangay, city, province, zip_code, landmark, latitude, longitude, is_default)
                VALUES
                ($uid, '$label', '$rname', '$rphone', '$street',
                 '$brgy', '$city', '$prov', '$zip', $landmark_sql,
                 $lat_sql, $lng_sql, $is_default)
            ");
            $new_id = mysqli_insert_id($conn);
        }
        
        // Sync default to users.delivery_*
        if ($is_default) {
            mysqli_query($conn, "
                UPDATE users SET
                    delivery_address = '$street',
                    delivery_barangay = '$brgy',
                    delivery_city = '$city',
                    delivery_province = '$prov',
                    delivery_zip = '$zip'
                WHERE id = $uid
            ");
        }
        
        echo json_encode(['success'=>true,'message'=>'Address saved.','id'=>$new_id]);
        exit;
    }
    
    // ── DELETE ──
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT is_default FROM user_addresses WHERE id = $id AND user_id = $uid"));
        
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Address not found.']); exit; }
        
        mysqli_query($conn, "DELETE FROM user_addresses WHERE id = $id AND user_id = $uid");
        
        // If deleted was default, promote next newest
        if ((int)$row['is_default'] === 1) {
            $next = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM user_addresses WHERE user_id = $uid ORDER BY created_at DESC LIMIT 1"));
            if ($next) {
                mysqli_query($conn, "UPDATE user_addresses SET is_default = 1 WHERE id = {$next['id']}");
                $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM user_addresses WHERE id = {$next['id']}"));
                mysqli_query($conn, "
                    UPDATE users SET
                        delivery_address = '{$a['street_address']}',
                        delivery_barangay = '{$a['barangay']}',
                        delivery_city = '{$a['city']}',
                        delivery_province = '{$a['province']}',
                        delivery_zip = '{$a['zip_code']}'
                    WHERE id = $uid
                ");
            } else {
                mysqli_query($conn, "
                    UPDATE users SET delivery_address=NULL, delivery_barangay=NULL,
                        delivery_city=NULL, delivery_province=NULL, delivery_zip=NULL
                    WHERE id = $uid
                ");
            }
        }
        
        echo json_encode(['success'=>true,'message'=>'Address deleted.']);
        exit;
    }
    
    // ── SET DEFAULT ──
    if ($action === 'set_default') {
        $id = (int)($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM user_addresses WHERE id = $id AND user_id = $uid"));
        
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Address not found.']); exit; }
        
        mysqli_query($conn, "UPDATE user_addresses SET is_default = 0 WHERE user_id = $uid");
        mysqli_query($conn, "UPDATE user_addresses SET is_default = 1 WHERE id = $id");
        
        mysqli_query($conn, "
            UPDATE users SET
                delivery_address = '{$row['street_address']}',
                delivery_barangay = '{$row['barangay']}',
                delivery_city = '{$row['city']}',
                delivery_province = '{$row['province']}',
                delivery_zip = '{$row['zip_code']}'
            WHERE id = $uid
        ");
        
        echo json_encode(['success'=>true,'message'=>'Default updated.']);
        exit;
    }
    
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user info
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Update session with latest user data
$_SESSION['user_name'] = $user['fullname'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_avatar'] = $user['avatar'] ?? null;

// Get user avatar and created_at para sa sidebar
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// ============================================
// PWD/SENIOR VERIFICATION - GET ALL FROM user_verifications TABLE
// ============================================
$verifications_query = mysqli_query($conn, "
    SELECT 
        uv.*,
        c.name as clinic_name,
        u.fullname as verified_by_name
    FROM user_verifications uv
    LEFT JOIN clinics c ON uv.clinic_id = c.id
    LEFT JOIN users u ON uv.verified_by = u.id
    WHERE uv.user_id = $user_id 
    AND uv.status != 'none'
    ORDER BY uv.status = 'pending' DESC, uv.created_at DESC
");
$verifications = [];
while ($row = mysqli_fetch_assoc($verifications_query)) {
    $verifications[] = $row;
}

// Check if user has ANY pending or verified verification
$has_active_verification = false;
foreach ($verifications as $v) {
    if ($v['status'] == 'pending' || $v['status'] == 'verified') {
        $has_active_verification = true;
        break;
    }
}

// ============================================
// HANDLE PWD/SENIOR VERIFICATION SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_pwd_senior'])) {
    $pwd_senior_type = mysqli_real_escape_string($conn, $_POST['pwd_senior_type'] ?? '');
    $pwd_senior_id_number = mysqli_real_escape_string($conn, trim($_POST['pwd_senior_id_number'] ?? ''));
    $clinic_id = intval($_POST['clinic_id'] ?? 0);
    
    // ✅ NEW: Get date_issued and valid_until
    $date_issued = $_POST['date_issued'] ?? '';
    $valid_until = $_POST['valid_until'] ?? '';
    
    // Validate clinic selection
    if (empty($clinic_id) || $clinic_id <= 0) {
        $_SESSION['error_message'] = 'Please select a clinic first.';
        header('Location: profile.php');
        exit();
    }
    
    // Validate type
    if (empty($pwd_senior_type) || !in_array($pwd_senior_type, ['pwd', 'senior'])) {
        $_SESSION['error_message'] = 'Please select a valid type (PWD or Senior Citizen).';
        header('Location: profile.php');
        exit();
    }
    
    // Validate ID number
    if (empty($pwd_senior_id_number)) {
        $_SESSION['error_message'] = 'Please enter your ID number.';
        header('Location: profile.php');
        exit();
    }
    
    // ✅ NEW: Validate date_issued and valid_until
    if (empty($date_issued)) {
        $_SESSION['error_message'] = 'Please select the date issued.';
        header('Location: profile.php');
        exit();
    }
    
    if (empty($valid_until)) {
        $_SESSION['error_message'] = 'Please select the valid until date.';
        header('Location: profile.php');
        exit();
    }
    
    if (strtotime($valid_until) < strtotime($date_issued)) {
        $_SESSION['error_message'] = 'Valid Until must be after Date Issued.';
        header('Location: profile.php');
        exit();
    }
    
    // Handle file upload
    $uploaded_image = '';
    if (isset($_FILES['pwd_senior_id_image']) && $_FILES['pwd_senior_id_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $filename = $_FILES['pwd_senior_id_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $_SESSION['error_message'] = 'Invalid file type. Allowed: JPG, PNG, PDF';
            header('Location: profile.php');
            exit();
        }
        
        if ($_FILES['pwd_senior_id_image']['size'] > 5 * 1024 * 1024) {
            $_SESSION['error_message'] = 'File too large. Max 5MB.';
            header('Location: profile.php');
            exit();
        }
        
        $upload_dir = '../assets/images/pwd_ids/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $new_filename = 'pwd_' . $user_id . '_' . time() . '.' . $ext;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['pwd_senior_id_image']['tmp_name'], $upload_path)) {
            $uploaded_image = $new_filename;
        } else {
            $_SESSION['error_message'] = 'Failed to upload ID image.';
            header('Location: profile.php');
            exit();
        }
    } else {
        $_SESSION['error_message'] = 'Please upload your valid ID.';
        header('Location: profile.php');
        exit();
    }
    
    // ✅ INSERT INTO user_verifications with date_issued and valid_until
    $insert_query = mysqli_query($conn, "
        INSERT INTO user_verifications (
            user_id, 
            clinic_id, 
            verification_type, 
            id_number, 
            id_image, 
            date_issued,
            valid_until,
            status, 
            created_at
        ) VALUES (
            $user_id, 
            $clinic_id, 
            '$pwd_senior_type', 
            '$pwd_senior_id_number', 
            '$uploaded_image',
            '$date_issued',
            '$valid_until',
            'pending', 
            NOW()
        ) ON DUPLICATE KEY UPDATE
            verification_type = '$pwd_senior_type',
            id_number = '$pwd_senior_id_number',
            id_image = '$uploaded_image',
            date_issued = '$date_issued',
            valid_until = '$valid_until',
            status = 'pending',
            rejection_reason = NULL,
            verified_by = NULL,
            verified_at = NULL,
            updated_at = NOW()
    ");
    
    if ($insert_query) {
        // Log the submission
        mysqli_query($conn, "
            INSERT INTO pwd_senior_verification_logs (user_id, clinic_id, action, created_at)
            VALUES ($user_id, $clinic_id, 'submitted', NOW())
        ");
        
        $_SESSION['success_message'] = 'Your PWD/Senior verification request has been submitted to the clinic. Please wait for clinic approval.';
    } else {
        $_SESSION['error_message'] = 'Failed to submit verification request. Please try again.';
    }
    
    header('Location: profile.php');
    exit();
}

// ============================================
// HANDLE CANCEL REQUEST - SPECIFIC CLINIC
// ============================================
if (isset($_GET['cancel_pwd_request']) && isset($_GET['clinic_id'])) {
    $cancel_clinic_id = intval($_GET['clinic_id']);
    mysqli_query($conn, "
        UPDATE user_verifications 
        SET status = 'none'
        WHERE user_id = $user_id AND clinic_id = $cancel_clinic_id
    ");
    
    $_SESSION['success_message'] = 'Verification request cancelled for this clinic.';
    header('Location: profile.php');
    exit();
}

// Handle cancel all (backward compatibility)
if (isset($_GET['cancel_pwd_request']) && !isset($_GET['clinic_id'])) {
    mysqli_query($conn, "
        UPDATE user_verifications 
        SET status = 'none'
        WHERE user_id = $user_id
    ");
    
    $_SESSION['success_message'] = 'All verification requests cancelled.';
    header('Location: profile.php');
    exit();
}

// ============================================
// NOTIFICATION VARIABLES
// ============================================
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

// Get sale count for sidebar badge
$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

// Get user stats
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

// Count appointments for badge
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

// Get user statistics
$total_appointments = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_appts = mysqli_fetch_assoc($total_appointments)['total'] ?? 0;

$total_favorites = mysqli_query($conn, "SELECT COUNT(*) as total FROM favorites WHERE user_id = $user_id");
$total_favs = mysqli_fetch_assoc($total_favorites)['total'] ?? 0;

$completed_appointments = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'completed'");
$completed = mysqli_fetch_assoc($completed_appointments)['total'] ?? 0;

// ============================================
// GET PATIENT ID FROM EMAIL
// ============================================
$patient_id_query = mysqli_query($conn, "SELECT id FROM patients WHERE email = '{$_SESSION['user_email']}' LIMIT 1");
$patient_row = mysqli_fetch_assoc($patient_id_query);
$patient_id = $patient_row['id'] ?? 0;

// Get total examination records count
$total_exams_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM optical_records WHERE patient_id = $patient_id");
$total_exams = mysqli_fetch_assoc($total_exams_query)['total'] ?? 0;

// Get ALL recent activities
$recent_activity = mysqli_query($conn, "
    (SELECT 
        'appointment' as type, 
        id as reference_id,
        created_at, 
        clinic_id,
        status,
        CONCAT('Booked an appointment at ', 
            (SELECT name FROM clinics WHERE id = clinic_id)
        ) as description
     FROM appointments 
     WHERE user_id = $user_id)
    
    UNION ALL
    
    (SELECT 
        'favorite' as type,
        id as reference_id,
        created_at, 
        clinic_id,
        NULL as status,
        CONCAT('Added ', 
            (SELECT name FROM clinics WHERE id = clinic_id), 
            ' to favorites'
        ) as description
     FROM favorites 
     WHERE user_id = $user_id)
    
    ORDER BY created_at DESC 
    LIMIT 10
");

// Handle profile update
$success_message = '';
$error_message = '';

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {

        $fullname = trim(mysqli_real_escape_string($conn, $_POST['fullname']));
        $email    = trim(mysqli_real_escape_string($conn, $_POST['email']));
        $contact  = trim(mysqli_real_escape_string($conn, $_POST['contact']));
        $address  = trim(mysqli_real_escape_string($conn, $_POST['address']));

        // ✅ NEW: Delivery address fields
        $delivery_address   = trim(mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? ''));
        $delivery_barangay  = trim(mysqli_real_escape_string($conn, $_POST['delivery_barangay'] ?? ''));
        $delivery_city      = trim(mysqli_real_escape_string($conn, $_POST['delivery_city'] ?? ''));
        $delivery_province  = trim(mysqli_real_escape_string($conn, $_POST['delivery_province'] ?? ''));
        $delivery_zip       = trim(mysqli_real_escape_string($conn, $_POST['delivery_zip'] ?? ''));

        // Huwag i-save ang "Not provided" — i-blank nalang
        if ($contact === 'Not provided') $contact = '';
        if ($address === 'Not provided') $address = '';

        // Huwag i-update kung blank ang fullname
        if (empty($fullname)) {
            $_SESSION['error_message'] = 'Full name cannot be empty!';
            header('Location: profile.php');
            exit();
        }

        $check_email = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email' AND id != $user_id");
        if (mysqli_num_rows($check_email) > 0) {
            $_SESSION['error_message'] = 'Email already exists!';
        } else {
            $name_parts = explode(' ', $fullname, 2);
            $first_name = mysqli_real_escape_string($conn, $name_parts[0]);
            $last_name  = mysqli_real_escape_string($conn, $name_parts[1] ?? '');

            // ✅ I-update LAHAT ng fields (kasama ang delivery address)
            $update_query = "UPDATE users SET 
                first_name = '$first_name', 
                last_name = '$last_name', 
                email = '$email', 
                contact = '$contact', 
                address = '$address',
                delivery_address = '$delivery_address',
                delivery_barangay = '$delivery_barangay',
                delivery_city = '$delivery_city',
                delivery_province = '$delivery_province',
                delivery_zip = '$delivery_zip'
                WHERE id = $user_id";

            if (mysqli_query($conn, $update_query)) {
                $affected = mysqli_affected_rows($conn);
                $verify = mysqli_query($conn, "SELECT fullname FROM users WHERE id = $user_id");
                $verify_row = mysqli_fetch_assoc($verify);
                
                $_SESSION['user_name']        = $fullname;
                $_SESSION['user_email']       = $email;
                $_SESSION['success_message']  = "Profile updated successfully!";
            } else {
                // Ipakita ang exact MySQL error para malaman natin kung ano problema
                $_SESSION['error_message'] = 'DB Error: ' . mysqli_error($conn);
            }
        }
        header('Location: profile.php');
        exit();
    }
}

// ============================================
// HANDLE AVATAR UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_picture'])) {
    // Check avatar lock (24-hour limit)
    if (!empty($user['avatar_updated_at']) && strtotime($user['avatar_updated_at']) > strtotime('-24 hours')) {
        $_SESSION['error_message'] = 'You can only change your avatar once every 24 hours.';
        header('Location: profile.php');
        exit();
    }
    
    $file = $_FILES['profile_picture'];
    
    if ($file['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $_SESSION['error_message'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP';
            header('Location: profile.php');
            exit();
        }
        
        if ($file['size'] > 5 * 1024 * 1024) { // 5MB
            $_SESSION['error_message'] = 'File too large. Max 5MB.';
            header('Location: profile.php');
            exit();
        }
        
        $upload_dir = '../assets/images/profiles/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Delete old avatar
        if (!empty($user['avatar']) && file_exists($upload_dir . $user['avatar'])) {
            unlink($upload_dir . $user['avatar']);
        }
        
        $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        $upload_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            mysqli_query($conn, "UPDATE users SET avatar = '$new_filename', avatar_updated_at = NOW() WHERE id = $user_id");
            $_SESSION['user_avatar'] = $new_filename;
            $_SESSION['success_message'] = 'Profile picture updated successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to upload image.';
        }
    } else {
        $_SESSION['error_message'] = 'Please select a valid image.';
    }
    
    header('Location: profile.php');
    exit();
}
$avatar_path = '../assets/images/profiles/' . ($user['avatar'] ?? '');
$avatar_exists = !empty($user['avatar']) && file_exists($avatar_path);

// ============================================
// AVATAR CHANGE DAILY LIMIT CHECK (24-hour rolling window)
// ============================================
$avatar_locked = false;
$avatar_unlock_time = null;
if (!empty($user['avatar_updated_at']) && strtotime($user['avatar_updated_at']) > strtotime('-24 hours')) {
    $avatar_locked = true;
    $avatar_unlock_time = date('g:i A, M j', strtotime($user['avatar_updated_at'] . ' +24 hours'));
}

// Get current page name for sidebar active state
$current_page = basename($_SERVER['PHP_SELF']);

// List of pages where HOME should be active
$home_active_pages = [
    'clinic-details.php',
    'clinic-products.php',
    'book-appointment.php',
    'book-specific-product.php'
];
$is_home_active = in_array($current_page, $home_active_pages);
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Profile - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== ALL YOUR EXISTING CSS HERE (KEEP AS IS) ===== */
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
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            
            --secondary: #FF8C42;
            --secondary-light: #FFF1E6;
            
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            --shadow-hover: 0 30px 50px -20px rgba(0,183,97,0.3);
            
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 999px;
            
            --danger: #FF4444;
            --warning: #FF8C42;
            --info: #17A2B8;
            --success: #00B761;
            
            --open-bg: #d4edda;
            --open-text: #28a745;
            --closed-bg: #f8d7da;
            --closed-text: #721c24;
            
            --verified-bg: #d4edda;
            --verified-color: #28a745;
            --unverified-bg: #fff3cd;
            --unverified-color: #856404;
            
            --stat-gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            
            --sale-color: #FF4444;
            --sale-gradient: linear-gradient(135deg, #FF4444 0%, #FF6B6B 100%);
            --sale-light: #FFE5E5;
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
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.2);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.4);
            
            --open-bg: #2d4a2d;
            --open-text: #7ac97a;
            --closed-bg: #5a2d2d;
            --closed-text: #ff9999;
            
            --verified-bg: #2d4a2d;
            --verified-color: #7ac97a;
            --unverified-bg: #5a4c2d;
            --unverified-color: #ffd966;
            
            --sale-color: #FF6B6B;
            --sale-gradient: linear-gradient(135deg, #FF6B6B 0%, #FF8888 100%);
            --sale-light: #4A2D2D;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

        /* ============================================
           PWD/SENIOR STYLES - UPDATED FOR MULTI CLINIC
           ============================================ */
        .pwd-senior-section {
            margin-top: 20px;
            border-top: 1px solid var(--border-light);
            padding-top: 20px;
        }

        .pwd-senior-section .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pwd-senior-section .section-title i {
            color: var(--primary);
        }

        .pwd-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .pwd-status-badge.verified {
            background: var(--verified-bg);
            color: var(--verified-color);
        }

        .pwd-status-badge.pending {
            background: var(--unverified-bg);
            color: var(--unverified-color);
        }

        .pwd-status-badge.rejected {
            background: var(--closed-bg);
            color: var(--closed-text);
        }

        .pwd-status-badge.none {
            background: var(--border-light);
            color: var(--text-muted);
        }

        .pwd-verification-form {
            background: var(--bg-primary);
            padding: 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            margin-top: 10px;
        }

        .pwd-verification-form .form-group {
            margin-bottom: 15px;
        }

        .pwd-verification-form .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .pwd-verification-form .form-group label i {
            color: var(--primary);
            margin-right: 5px;
        }

        .pwd-verification-form .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .pwd-verification-form .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .pwd-verification-form .form-control[readonly] {
            background: var(--border-light);
            cursor: not-allowed;
        }

        .pwd-verification-form .btn-submit-verify {
            padding: 12px 30px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .pwd-verification-form .btn-submit-verify:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .pwd-verification-form .btn-submit-verify:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .pwd-verification-form .btn-cancel-verify {
            padding: 12px 30px;
            background: var(--danger);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 10px;
        }

        .pwd-verification-form .btn-cancel-verify:hover {
            opacity: 0.8;
        }

        .pwd-info-box {
            background: var(--bg-primary);
            padding: 15px 20px;
            border-radius: var(--radius-md);
            border-left: 4px solid var(--primary);
            margin: 10px 0;
        }

        .pwd-info-box .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 14px;
        }

        .pwd-info-box .info-row .label {
            color: var(--text-secondary);
        }

        .pwd-info-box .info-row .value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .pwd-id-preview {
            max-width: 200px;
            margin-top: 10px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .pwd-id-preview img {
            width: 100%;
            height: auto;
            display: block;
        }

        .pwd-helper-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }

        .pwd-helper-text i {
            margin-right: 4px;
        }

        .apply-verification-btn {
            padding: 12px 30px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .apply-verification-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .apply-verification-btn i {
            font-size: 16px;
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* ============================================
           EXISTING STYLES (KEEP AS IS)
           ============================================ */

        /* ===== DESKTOP NAVBAR ===== */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-secondary);
            padding: 12px 40px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-light);
            width: 100%;
            margin: 0;
        }

        @media (max-width: 1024px) {
            .navbar {
                padding: 12px 24px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                display: none;
            }
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .logo i {
            font-size: 28px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-full);
            transition: all 0.2s;
            font-weight: 500;
            font-size: 14px;
            position: relative;
            background: none;
            border: none;
            cursor: pointer;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link:hover {
            color: var(--primary);
            background: var(--bg-primary);
        }

        .nav-link.active {
            background: var(--bg-primary);
            color: var(--primary);
            font-weight: 600;
        }

        .nav-link .badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: var(--danger);
            color: white;
            font-size: 9px;
            padding: 2px 5px;
            border-radius: var(--radius-full);
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Discover Dropdown */
        .nav-dropdown {
            position: relative;
        }

        .dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .dropdown-trigger i {
            font-size: 12px;
            transition: transform 0.2s;
        }

        .dropdown-trigger.active i {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 220px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 8px;
            margin-top: 12px;
            display: none;
            z-index: 100;
            border: 1px solid var(--border-light);
        }

        .dropdown-menu.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            position: relative;
            font-size: 14px;
        }

        .dropdown-menu a:hover {
            background: var(--bg-primary);
            color: var(--primary);
        }

        .dropdown-menu a i {
            width: 20px;
            font-size: 16px;
        }

        .dropdown-badge {
            position: absolute;
            right: 16px;
            background: var(--danger);
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* User Stats Badge */
        .user-stats-badge {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--bg-primary);
            padding: 8px 20px;
            border-radius: var(--radius-full);
        }

        .stat-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .stat-badge i {
            font-size: 16px;
        }

        .stat-badge .value {
            color: var(--text-primary);
        }

        /* Icon Buttons */
        .icon-btn {
            width: 44px;
            height: 44px;
            background: var(--bg-primary);
            border: none;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-secondary);
            font-size: 18px;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .icon-btn .badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 6px;
            border-radius: var(--radius-full);
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Profile Dropdown - FIXED */
        .profile-dropdown {
            position: relative;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-primary);
            padding: 4px 4px 4px 16px;
            border-radius: var(--radius-full);
            cursor: pointer;
            border: 1px solid var(--border-light);
        }

        .profile-info {
            text-align: right;
        }

        .profile-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .profile-points {
            font-size: 11px;
            color: var(--primary);
        }

        /* FIXED: Profile avatar container */
        .profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            overflow: hidden;
            flex-shrink: 0;
        }

        /* FIXED: Image inside profile avatar */
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* FIXED: Letter avatar (pag walang image) */
        .profile-avatar .avatar-letter {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-gradient);
            font-size: 18px;
            font-weight: bold;
            color: white;
        }

        .profile-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 1000;
            margin-top: 12px;
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .profile-menu.show {
            display: block;
        }

        .profile-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
        }

        .profile-menu a:last-child {
            border-bottom: none;
        }

        .profile-menu a:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .profile-menu a i {
            width: 20px;
            color: var(--primary);
        }

        /* ===== MOBILE TOP (STICKY) ===== */
        .mobile-top {
            display: none;
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--bg-secondary);
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
            width: 100%;
            margin: 0;
        }

        @media (max-width: 768px) {
            .mobile-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .mobile-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ===== MOBILE NOTIFICATION DROPDOWN ===== */
        .mobile-notification-dropdown {
            position: relative;
        }

        .mobile-notification-menu {
            position: fixed;
            top: 70px;
            left: 10px;
            right: 10px;
            width: auto;
            max-width: none;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 2000;
            border: 1px solid var(--border-light);
            overflow: hidden;
            max-height: 80vh;
            overflow-y: auto;
        }

        .mobile-notification-menu.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @media (min-width: 769px) {
            .mobile-notification-menu {
                display: none !important;
            }
        }

        .mobile-notification-menu .notification-header {
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .mobile-notification-menu .notification-header h3 {
            color: white;
            font-size: 16px;
        }

        .mobile-notification-menu .notification-header button {
            color: white;
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .mobile-notification-menu .notification-item {
            padding: 12px;
        }

        /* ===== MOBILE BOTTOM NAV ===== */
        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            box-shadow: 0 -5px 20px rgba(0,0,0,0.05);
            padding: 8px 16px;
            z-index: 1000;
            border-top: 1px solid var(--border-light);
        }

        @media (max-width: 768px) {
            .mobile-bottom-nav {
                display: block;
            }
        }

        .mobile-nav-items {
            display: flex;
            justify-content: space-around;
            align-items: center;
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 11px;
            gap: 4px;
            position: relative;
            padding: 8px 0;
        }

        .mobile-nav-item i {
            font-size: 22px;
        }

        .mobile-nav-item.active {
            color: var(--primary);
        }

        .mobile-nav-item.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            background: var(--primary);
            border-radius: 50%;
        }

        .mobile-nav-item .badge {
            position: absolute;
            top: 0;
            right: -2px;
            background: var(--danger);
            color: white;
            font-size: 9px;
            padding: 2px 5px;
            border-radius: var(--radius-full);
        }

        /* ===== MOBILE MENU ===== */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1999;
            display: none;
        }

        .mobile-menu-overlay.show {
            display: block;
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            right: -300px;
            width: 280px;
            height: 100vh;
            background: var(--bg-secondary);
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            transition: right 0.3s ease;
            overflow-y: auto;
        }

        .mobile-menu.open {
            right: 0;
        }

        .mobile-menu-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .mobile-user {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mobile-avatar {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            overflow: hidden;
        }

        .mobile-user h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .mobile-user p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .mobile-menu-header button {
            background: var(--bg-primary);
            border: none;
            width: 35px;
            height: 35px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .mobile-menu-items {
            padding: 15px;
        }

        .mobile-menu-items a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            margin-bottom: 5px;
        }

        .mobile-menu-items a i {
            width: 24px;
            color: var(--primary);
            font-size: 18px;
        }

        .mobile-menu-items a:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .mobile-menu-items .logout-link {
            color: var(--danger);
            margin-top: 20px;
            border-top: 1px solid var(--border-light);
            padding-top: 20px;
        }

        .mobile-menu-items .logout-link i {
            color: var(--danger);
        }

        /* ===== TOOLTIPS ===== */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:hover::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: transparent transparent var(--bg-secondary) transparent;
            z-index: 1001;
        }

        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            white-space: nowrap;
            box-shadow: var(--shadow-md);
            z-index: 1000;
            margin-bottom: 8px;
            border: 1px solid var(--border-light);
            font-weight: 500;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) {
            .main-content {
                padding: 30px 40px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px 16px 100px;
            }
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            font-size: 24px;
        }

        .profile-badge {
            background: var(--bg-secondary);
            padding: 12px 24px;
            border-radius: 30px;
            border: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            box-shadow: var(--shadow-sm);
        }

        .profile-badge i {
            color: var(--primary);
        }

        .profile-badge span {
            font-weight: 600;
            color: var(--primary);
            margin-right: 4px;
        }

        /* ===== PROFILE HEADER ===== */
        .profile-header {
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .profile-avatar-section {
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
        }

        .avatar-container {
            position: relative;
            width: 120px;
            height: 120px;
            flex-shrink: 0;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: var(--primary);
            box-shadow: var(--shadow-md);
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            background: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            cursor: pointer;
            box-shadow: var(--shadow-md);
            transition: all 0.2s;
            border: 2px solid white;
        }

        .avatar-upload:hover {
            transform: scale(1.1);
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .avatar-upload-disabled {
            cursor: not-allowed;
            opacity: 0.6;
            color: var(--text-muted);
        }

        .avatar-upload-disabled:hover {
            transform: none;
            background: white;
            color: var(--text-muted);
        }

        #fileInput {
            display: none;
        }

        .profile-title h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            color: white;
        }

        .profile-title p {
            opacity: 0.9;
            font-size: 16px;
            margin-bottom: 10px;
            color: white;
        }

        .verification-badges {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: white;
            backdrop-filter: blur(5px);
        }

        .badge.verified i {
            color: var(--success);
        }

        .badge.unverified i {
            color: var(--warning);
        }

        .online-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            margin-top: 10px;
            background: rgba(255,255,255,0.1);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(5px);
        }

        .online-status .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: var(--primary-gradient);
            color: white;
        }

        .stat-details h3 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .stat-details p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* ===== PROFILE GRID ===== */
        .profile-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        /* Profile Cards */
        .profile-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-light);
        }

        .card-header i {
            font-size: 24px;
            color: var(--primary);
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            flex: 1;
        }

        .edit-badge {
            background: var(--bg-primary);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .edit-badge:hover {
            background: var(--primary);
            color: white;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 13px;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 6px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            transition: all 0.2s;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .form-control[readonly] {
            background: var(--disabled-bg);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .btn-save {
            width: 100%;
            padding: 14px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .edit-mode .form-control {
            background: var(--bg-primary);
            border-color: var(--primary);
        }

        .view-mode .form-control {
            background: var(--bg-primary);
            opacity: 0.8;
        }

        .view-mode .btn-save {
            display: none;
        }

        /* ===== ACTIVITY TIMELINE ===== */
        .timeline {
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .timeline::-webkit-scrollbar {
            width: 5px;
        }

        .timeline::-webkit-scrollbar-track {
            background: var(--border-light);
            border-radius: 10px;
        }

        .timeline::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            border-radius: var(--radius-md);
            transition: all 0.2s;
            margin-bottom: 10px;
            background: var(--bg-primary);
            border: 1px solid var(--border-light);
            cursor: pointer;
            text-decoration: none;
        }

        .timeline-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .timeline-icon {
            width: 45px;
            height: 45px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            flex-shrink: 0;
        }

        .timeline-icon.appointment {
            background: var(--primary-gradient);
        }

        .timeline-icon.favorite {
            background: var(--sale-gradient);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-content h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .timeline-content p {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .timeline-content p i {
            font-size: 10px;
            color: var(--primary);
        }

        .timeline-badge {
            font-size: 10px;
            padding: 3px 10px;
            border-radius: var(--radius-full);
            display: inline-block;
            font-weight: 600;
        }

        .timeline-badge.pending {
            background: var(--warning);
            color: white;
        }

        .timeline-badge.completed {
            background: var(--success);
            color: white;
        }

        .timeline-badge.cancelled {
            background: var(--danger);
            color: white;
        }

        .view-all-links {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-light);
        }

        .view-all-link {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .view-all-link.appointments {
            background: var(--bg-primary);
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .view-all-link.appointments:hover {
            background: var(--primary);
            color: white;
        }

        .view-all-link.favorites {
            background: var(--bg-primary);
            color: var(--sale-color);
            border: 1px solid var(--sale-color);
        }

        .view-all-link.favorites:hover {
            background: var(--sale-gradient);
            color: white;
            border-color: transparent;
        }

        .view-all-link i {
            margin-right: 5px;
        }

        /* ===== EYE EXAMINATION HISTORY CARD STYLES ===== */
        .exam-preview-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: 12px;
            border: 1px solid var(--border-light);
            transition: all 0.2s;
            margin-bottom: 10px;
            text-decoration: none;
        }

        .exam-preview-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .exam-icon {
            width: 45px;
            height: 45px;
            background: var(--primary-light);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 20px;
            flex-shrink: 0;
        }

        .exam-info {
            flex: 1;
        }

        .exam-clinic-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
            font-size: 14px;
        }

        .exam-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .exam-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .exam-meta i {
            color: var(--primary);
            font-size: 11px;
        }

        .exam-chevron {
            color: var(--primary);
            font-size: 14px;
        }

        .view-all-exams {
            text-align: center;
            margin-top: 15px;
        }

        .view-all-exams a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 16px;
            border-radius: var(--radius-full);
            transition: all 0.2s;
        }

        .view-all-exams a:hover {
            background: var(--primary-light);
            gap: 8px;
        }

        .empty-exams {
            text-align: center;
            padding: 30px;
            background: var(--bg-primary);
            border-radius: 12px;
        }

        .empty-exams i {
            font-size: 40px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .empty-exams p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 15px;
        }

        .empty-exams .btn-primary {
            display: inline-block;
            padding: 8px 20px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-size: 13px;
        }

        /* ===== INFO CARD ===== */
        .info-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .info-item {
            padding: 20px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid var(--border-light);
        }

        .info-item i {
            font-size: 24px;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .info-item h4 {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .info-item p {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* ===== ALERTS ===== */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: var(--open-bg);
            color: var(--open-text);
            border: 1px solid var(--open-text);
        }

        .alert-error {
            background: var(--closed-bg);
            color: var(--closed-text);
            border: 1px solid var(--closed-text);
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 60px;
            color: var(--text-muted);
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .empty-state .btn-primary {
            display: inline-block;
            padding: 10px 25px;
            background: var(--primary-gradient);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .empty-state .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ===== NOTIFICATION STYLES (DESKTOP) ===== */
        .notification-dropdown {
            position: relative;
            display: inline-block;
        }

        .notification-menu {
            position: absolute;
            top: 100%;
            right: 0;
            width: 380px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: none;
            z-index: 1000;
            margin-top: 12px;
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .notification-menu.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .notification-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h3 {
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }

        .notification-header button {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            padding: 16px 20px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-light);
            transition: all 0.2s;
            position: relative;
        }

        .notification-item:hover {
            background: var(--bg-primary);
        }

        .notification-item.unread {
            background: var(--primary-light);
        }

        .notification-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            flex-shrink: 0;
        }

        .notification-icon.appointment {
            background: var(--primary-light);
            color: var(--primary);
        }

        .notification-icon.favorite {
            background: #FCE4EC;
            color: #C2185B;
        }

        .notification-icon.promo {
            background: var(--sale-light);
            color: var(--sale-color);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .notification-message {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .notification-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        .notification-dot {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
        }

        .notification-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .notification-empty i {
            font-size: 50px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .notification-footer {
            padding: 16px;
            text-align: center;
            border-top: 1px solid var(--border-light);
        }

        .notification-footer a {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-notification {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 16px 24px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 12px;
            min-width: 320px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--primary);
        }

        .toast-notification.success { border-left-color: var(--success); }
        .toast-notification.error { border-left-color: var(--danger); }
        .toast-notification.info { border-left-color: var(--info); }

        .toast-notification i {
            font-size: 20px;
        }

        .toast-notification.success i { color: var(--success); }
        .toast-notification.error i { color: var(--danger); }
        .toast-notification.info i { color: var(--info); }

        .toast-notification span {
            flex: 1;
            font-size: 14px;
            color: var(--text-primary);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        /* New Notification Alert */
        .new-notification-alert {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary-gradient);
            color: white;
            padding: 15px 25px;
            border-radius: var(--radius-full);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transform: translateY(200%);
            transition: transform 0.3s ease;
            z-index: 10002;
            font-weight: 600;
        }

        .new-notification-alert.show {
            transform: translateY(0);
        }

        .new-notification-alert i {
            font-size: 20px;
            animation: ring 2s infinite;
        }

        @keyframes ring {
            0% { transform: rotate(0); }
            10% { transform: rotate(15deg); }
            20% { transform: rotate(-15deg); }
            30% { transform: rotate(10deg); }
            40% { transform: rotate(-10deg); }
            50% { transform: rotate(5deg); }
            60% { transform: rotate(-5deg); }
            100% { transform: rotate(0); }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .profile-avatar-section {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-title h1 {
                font-size: 24px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .verification-badges {
                justify-content: center;
            }
            
            .online-status {
                justify-content: center;
            }
            
            .view-all-links {
                flex-direction: column;
            }

            .exam-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .pwd-verification-form .btn-cancel-verify {
                margin-left: 0;
                margin-top: 10px;
                display: block;
                width: 100%;
                text-align: center;
            }
            
            .pwd-verification-form .btn-submit-verify {
                width: 100%;
                justify-content: center;
            }
            
            .pwd-info-box .info-row {
                flex-direction: column;
                gap: 2px;
            }
            
            .clinic-search-dropdown .dropdown-list {
                position: static;
                border-radius: 0 0 var(--radius-md) var(--radius-md);
                max-height: 150px;
            }
            
            .clinic-search-dropdown .form-control {
                border-radius: var(--radius-md);
            }
        }
        
        .profile-card .btn-save {
            display: none;
        }

        .profile-card.edit-mode .btn-save {
            display: block;
        }

        /* ═══════════════════════════════════════════════════════
   ADDRESS MANAGER STYLES
   ═══════════════════════════════════════════════════════ */
.addr-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    z-index: 8000; display: none;
    align-items: center; justify-content: center; padding: 16px;
}
.addr-modal-overlay.show { display: flex; }

.addr-modal {
    background: var(--bg-secondary); border-radius: 20px;
    width: 100%; max-width: 560px; max-height: 92vh;
    overflow: hidden; box-shadow: var(--shadow-lg);
    display: flex; flex-direction: column;
    animation: addrModalIn 0.28s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes addrModalIn {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to   { opacity: 1; transform: none; }
}

.addr-modal-head {
    background: var(--primary-gradient); color: white;
    padding: 18px 22px; display: flex;
    align-items: center; justify-content: space-between; flex-shrink: 0;
}
.addr-modal-head h3 {
    font-size: 16px; font-weight: 700;
    display: flex; align-items: center; gap: 10px; margin: 0;
}
.addr-modal-head .addr-close {
    background: rgba(255,255,255,0.2); border: none; color: white;
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 15px; transition: background 0.2s;
}
.addr-modal-head .addr-close:hover { background: rgba(255,255,255,0.35); }

.addr-modal-body {
    padding: 20px 22px; overflow-y: auto; flex: 1;
}
.addr-modal-body::-webkit-scrollbar { width: 6px; }
.addr-modal-body::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 6px; }

/* ── Address cards ── */
.addr-list { display: flex; flex-direction: column; gap: 12px; }

.addr-card {
    border: 2px solid var(--border-color); border-radius: 14px;
    padding: 15px; transition: all 0.2s; position: relative;
    background: var(--bg-primary);
}
.addr-card:hover {
    border-color: var(--primary); background: var(--primary-light);
}
.addr-card.is-default::before {
    content: 'DEFAULT'; position: absolute; top: -8px; right: 12px;
    background: var(--primary); color: white;
    font-size: 9px; font-weight: 800; letter-spacing: 0.06em;
    padding: 3px 8px; border-radius: 20px;
}
.addr-card-label {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--primary-light); color: var(--primary);
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase;
    letter-spacing: 0.04em; margin-bottom: 8px;
}
.addr-card-name {
    font-size: 14px; font-weight: 700;
    color: var(--text-primary); margin-bottom: 3px;
}
.addr-card-phone {
    font-size: 12px; color: var(--text-secondary); margin-bottom: 6px;
}
.addr-card-address {
    font-size: 12.5px; color: var(--text-secondary); line-height: 1.5;
}
.addr-card-actions {
    display: flex; gap: 8px; margin-top: 12px;
    padding-top: 12px; border-top: 1px dashed var(--border-color);
}
.addr-action-btn {
    flex: 1; padding: 7px 10px; border-radius: 8px;
    border: 1px solid var(--border-color); background: var(--bg-secondary);
    color: var(--text-secondary); font-size: 11.5px; font-weight: 600;
    cursor: pointer; transition: all 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.addr-action-btn:hover {
    border-color: var(--primary); color: var(--primary);
    background: var(--primary-light);
}
.addr-action-btn.danger:hover {
    border-color: var(--danger); color: var(--danger);
    background: #fff5f5;
}
.addr-action-btn.primary {
    background: var(--primary); color: white; border-color: var(--primary);
}
.addr-action-btn.primary:hover { background: var(--primary-dark); }

/* ── Add new button ── */
.addr-add-btn {
    width: 100%; padding: 12px;
    border: 2px dashed var(--primary); border-radius: 12px;
    background: var(--primary-light); color: var(--primary);
    font-size: 13px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all 0.2s; font-family: inherit;
}
.addr-add-btn:hover {
    background: var(--primary); color: white; border-style: solid;
}

/* ── Empty state ── */
.addr-empty {
    text-align: center; padding: 30px 20px;
    background: var(--bg-primary); border-radius: 12px;
}
.addr-empty i {
    font-size: 40px; color: var(--text-muted);
    margin-bottom: 12px; display: block;
}
.addr-empty p {
    font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;
}

/* ── Form controls ── */
.addr-form-group { margin-bottom: 14px; }
.addr-form-label {
    display: block; font-size: 11.5px; font-weight: 700;
    color: var(--text-secondary); text-transform: uppercase;
    letter-spacing: 0.05em; margin-bottom: 6px;
}
.addr-form-label .req { color: var(--danger); }
.addr-form-input, .addr-form-select {
    width: 100%; padding: 10px 14px;
    border: 2px solid var(--border-color); border-radius: 10px;
    font-size: 13.5px; background: var(--bg-primary);
    color: var(--text-primary); font-family: inherit;
    transition: all 0.2s; box-sizing: border-box;
}
.addr-form-input:focus, .addr-form-select:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}
.addr-form-row-2 {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
}
@media (max-width: 480px) { .addr-form-row-2 { grid-template-columns: 1fr; } }

.addr-label-picker { display: flex; gap: 8px; flex-wrap: wrap; }
.addr-label-btn {
    padding: 8px 16px; border: 2px solid var(--border-color);
    border-radius: 10px; background: var(--bg-primary);
    font-size: 12.5px; font-weight: 600; color: var(--text-secondary);
    cursor: pointer; transition: all 0.2s;
    display: flex; align-items: center; gap: 6px;
}
.addr-label-btn:hover { border-color: var(--primary); }
.addr-label-btn.selected {
    background: var(--primary); color: white; border-color: var(--primary);
}

.addr-location-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; border: 2px solid var(--primary);
    border-radius: 10px; background: var(--primary-light);
    color: var(--primary); font-size: 12.5px; font-weight: 700;
    cursor: pointer; transition: all 0.2s; margin-bottom: 8px;
}
.addr-location-btn:hover {
    background: var(--primary); color: white;
}
.addr-location-btn.loading { opacity: 0.7; pointer-events: none; }

.addr-location-status {
    font-size: 12px; margin-top: 4px; margin-bottom: 8px;
    padding: 9px 12px; border-radius: 8px; display: none;
}
.addr-location-status.show { display: block; }
.addr-location-status.success {
    background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
}
.theme-dark .addr-location-status.success {
    background: #064e3b; color: #6ee7b7; border-color: #047857;
}
.addr-location-status.error {
    background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
}
.theme-dark .addr-location-status.error {
    background: #7f1d1d; color: #fca5a5; border-color: #991b1b;
}

#addrMap {
    width: 100%; height: 200px; border-radius: 12px;
    margin-top: 8px; border: 2px solid var(--border-color);
    display: none;
}
#addrMap.show { display: block; }

.addr-default-row {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px; background: var(--primary-light);
    border: 1px solid rgba(0,183,97,0.3); border-radius: 10px;
    margin-top: 14px;
}
.addr-default-row input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: var(--primary);
    cursor: pointer; flex-shrink: 0;
}
.addr-default-row label {
    font-size: 13px; color: var(--primary-dark);
    cursor: pointer; font-weight: 600; user-select: none;
}
.theme-dark .addr-default-row label { color: var(--primary); }

.addr-form-actions {
    display: flex; gap: 10px; margin-top: 18px;
    padding-top: 16px; border-top: 1px solid var(--border-color);
}
.addr-form-actions button {
    flex: 1; padding: 12px; border-radius: 10px;
    font-size: 13.5px; font-weight: 700; cursor: pointer;
    border: none; font-family: inherit; transition: all 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.addr-btn-save {
    background: var(--primary-gradient); color: white;
}
.addr-btn-save:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
.addr-btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.addr-btn-cancel {
    background: var(--bg-primary); color: var(--text-secondary);
    border: 2px solid var(--border-color);
}
.addr-btn-cancel:hover { background: var(--border-color); }

@media (max-width: 480px) {
    .addr-modal { max-height: 96vh; border-radius: 16px; }
    .addr-modal-head { padding: 14px 18px; }
    .addr-modal-body { padding: 16px 18px; }
    .addr-card { padding: 13px; }
}
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <!-- DESKTOP NAVBAR - GAYA NG IBANG PAGES -->
    <div class="navbar">
        <div class="nav-left">
            <div class="logo">
                <i class="fas fa-eye"></i>
                <span>eyecore</span>
            </div>
            
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </a>
                <a href="nearby.php" class="nav-link">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Nearby</span>
                </a>
                <a href="favorites.php" class="nav-link">
                    <i class="fas fa-heart"></i>
                    <span>Favorites</span>
                </a>
                <a href="my-appointments.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span>Bookings</span>
                    <?php if ($pending > 0): ?>
                        <span class="badge"><?php echo $pending; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Discover Dropdown -->
                <div class="nav-dropdown">
                    <button class="nav-link dropdown-trigger" onclick="toggleDiscoverDropdown()">
                        Discover <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="discoverDropdown">
                        <a href="sale-products.php">
                            <i class="fas fa-tags" style="color: var(--danger);"></i> Hot Sales
                            <?php if ($sale_count > 0): ?>
                                <span class="dropdown-badge"><?php echo $sale_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="clinics-map.php">
                            <i class="fas fa-map-marked-alt" style="color: var(--primary);"></i> Explore Map
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="nav-right">
            <!-- User Stats Badge -->
            <div class="user-stats-badge">
                <div class="stat-badge" data-tooltip="Total points earned">
                    <i class="fas fa-star" style="color: #FFC107;"></i>
                    <span class="value"><?php echo $total_points; ?></span>
                </div>
                <div class="stat-badge" data-tooltip="Total bookings made">
                    <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                    <span class="value"><?php echo $total_bookings; ?></span>
                </div>
            </div>
            
            <!-- Theme Toggle -->
            <button class="icon-btn" onclick="toggleTheme()" id="themeToggle" data-tooltip="Toggle dark/light mode">
                <i class="fas fa-moon"></i>
            </button>
            
            <!-- Notifications (Desktop) -->
            <div class="notification-dropdown">
                <button class="icon-btn" onclick="toggleNotifications()" id="notificationBell" data-tooltip="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge" id="notificationBadge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notification-menu" id="notificationMenu">
                    <div class="notification-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <?php if ($unread_count > 0): ?>
                            <button onclick="markAllAsRead()" id="markAllBtn">
                                <i class="fas fa-check-double"></i> Mark all read
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-list">
                        <?php if (mysqli_num_rows($recent_notifications) > 0): ?>
                            <?php 
                            mysqli_data_seek($recent_notifications, 0);
                            while($notif = mysqli_fetch_assoc($recent_notifications)): 
                            ?>
                                <a href="<?php echo $notif['link'] ?: '#'; ?>" 
                                   class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                                   onclick="handleNotificationClick(event, this, <?php echo $notif['id']; ?>)">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fas <?php 
                                            if($notif['type'] == 'appointment') echo 'fa-calendar-check';
                                            elseif($notif['type'] == 'favorite') echo 'fa-heart';
                                            elseif($notif['type'] == 'promo') echo 'fa-tags';
                                            else echo 'fa-bell';
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <?php if ($notif['message']): ?>
                                            <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <?php endif; ?>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-footer">
                        <a href="notifications.php">View all notifications</a>
                    </div>
                </div>
            </div>
            
            <!-- Profile Dropdown - FIXED -->
            <div class="profile-dropdown">
                <div class="profile-trigger" onclick="toggleProfileMenu()">
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="profile-points"><?php echo $total_points; ?> pts</div>
                    </div>
                    <div class="profile-avatar">
                        <?php 
                        $avatar = $user_data['avatar'] ?? null;
                        if ($avatar && file_exists("../assets/images/profiles/$avatar")): 
                        ?>
                            <img src="../assets/images/profiles/<?php echo $avatar; ?>" alt="Profile">
                        <?php else: 
                            $first_letter = strtoupper(substr($_SESSION['user_name'], 0, 1));
                        ?>
                            <div class="avatar-letter">
                                <?php echo $first_letter; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-menu" id="profileMenu">
                    <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
                    <a href="../auth/user_logout.php" style="color: var(--danger);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- MOBILE TOP BAR (STICKY) -->
    <div class="mobile-top">
        <div class="mobile-logo">
            <i class="fas fa-eye"></i>
            <span>eyecore</span>
        </div>
        <div class="mobile-actions">
            <button class="icon-btn" onclick="toggleTheme()" style="width: 40px; height: 40px;" data-tooltip="Toggle theme">
                <i class="fas fa-moon"></i>
            </button>
            
            <!-- MOBILE NOTIFICATION DROPDOWN -->
            <div class="mobile-notification-dropdown">
                <button class="icon-btn" onclick="toggleMobileNotifications()" id="mobileNotificationBell" style="width: 40px; height: 40px;" data-tooltip="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </button>
                
                <!-- Mobile Notification Menu -->
                <div class="mobile-notification-menu" id="mobileNotificationMenu">
                    <div class="notification-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <?php if ($unread_count > 0): ?>
                            <button onclick="markAllAsReadMobile()" class="mark-all-btn">
                                <i class="fas fa-check-double"></i> Mark all read
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-list">
                        <?php 
                        mysqli_data_seek($recent_notifications, 0);
                        if (mysqli_num_rows($recent_notifications) > 0): 
                        ?>
                            <?php while($notif = mysqli_fetch_assoc($recent_notifications)): ?>
                                <a href="<?php echo $notif['link'] ?: '#'; ?>" 
                                   class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                                   onclick="handleMobileNotificationClick(event, this, <?php echo $notif['id']; ?>)">
                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                        <i class="fas <?php 
                                            if($notif['type'] == 'appointment') echo 'fa-calendar-check';
                                            elseif($notif['type'] == 'favorite') echo 'fa-heart';
                                            elseif($notif['type'] == 'promo') echo 'fa-tags';
                                            else echo 'fa-bell';
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <?php if ($notif['message']): ?>
                                            <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <?php endif; ?>
                                        <div class="notification-time"><?php echo timeAgo($notif['created_at']); ?></div>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                        <div class="notification-dot"></div>
                                    <?php endif; ?>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="notification-empty">
                                <i class="fas fa-bell-slash"></i>
                                <p>No notifications</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notification-footer">
                        <a href="notifications.php">View all notifications</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAV -->
    <div class="mobile-bottom-nav">
        <div class="mobile-nav-items">
            <a href="dashboard.php" class="mobile-nav-item">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="nearby.php" class="mobile-nav-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>Nearby</span>
            </a>
            <a href="favorites.php" class="mobile-nav-item">
                <i class="fas fa-heart"></i>
                <span>Fav</span>
            </a>
            <a href="my-appointments.php" class="mobile-nav-item">
                <i class="fas fa-calendar-check"></i>
                <span>Books</span>
                <?php if ($pending > 0): ?>
                    <span class="badge"><?php echo $pending; ?></span>
                <?php endif; ?>
            </a>
            <a href="#" class="mobile-nav-item" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </a>
        </div>
    </div>

    <!-- MOBILE MENU OVERLAY -->
    <div class="mobile-menu-overlay" id="mobileMenuOverlay" onclick="closeMobileMenu()"></div>
    
    <!-- MOBILE MENU -->
    <div class="mobile-menu" id="mobileMenu">
        <div class="mobile-menu-header">
            <div class="mobile-user">
                <div class="mobile-avatar">
                    <?php 
                    $avatar = $user_data['avatar'] ?? null;
                    if ($avatar && file_exists("../assets/images/profiles/$avatar")): 
                    ?>
                        <img src="../assets/images/profiles/<?php echo $avatar; ?>" alt="Profile">
                    <?php else: 
                        $first_letter = strtoupper(substr($_SESSION['user_name'], 0, 1));
                    ?>
                        <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: white;">
                            <?php echo $first_letter; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
                    <p><?php echo $total_points; ?> points • <?php echo $total_bookings; ?> bookings</p>
                </div>
            </div>
            <button onclick="closeMobileMenu()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="mobile-menu-items">
            <a href="profile.php" class="active"><i class="fas fa-user-circle"></i> My Profile</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="sale-products.php"><i class="fas fa-tags" style="color: var(--danger);"></i> Hot Sales</a>
            <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
            <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>


    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-user-circle"></i>
                My Profile
            </h1>
            <div class="profile-badge">
                <i class="fas fa-user"></i> <span><?php echo $user['fullname']; ?></span>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Profile Header with Avatar -->
        <div class="profile-header">
            <div class="profile-avatar-section">
                <div class="avatar-container">
                    <?php if ($avatar_exists): ?>
                        <img src="../assets/images/profiles/<?php echo $user['avatar']; ?>" alt="Profile" class="profile-avatar-large">
                    <?php else: ?>
                        <div class="profile-avatar-large">
                            <i class="fas fa-user-circle"></i>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($avatar_locked): ?>
                        <span class="avatar-upload avatar-upload-disabled" data-tooltip="You'll be able to upload again at <?php echo $avatar_unlock_time; ?>">
                            <i class="fas fa-lock"></i>
                        </span>
                    <?php else: ?>
                        <label for="fileInput" class="avatar-upload">
                            <i class="fas fa-camera"></i>
                        </label>
                        <form method="POST" enctype="multipart/form-data" id="avatarForm">
                            <input type="file" id="fileInput" name="profile_picture" accept="image/*" onchange="document.getElementById('avatarForm').submit()">
                        </form>
                    <?php endif; ?>
                </div>
                
                <div class="profile-title">
                    <h1><?php echo $user['fullname']; ?></h1>
                    <p><?php echo $user['email']; ?></p>
                    
                    <div class="verification-badges">
                        <span class="badge verified">
                            <i class="fas fa-check-circle"></i> Email Verified
                        </span>
                        <span class="badge unverified">
                            <i class="fas fa-exclamation-circle"></i> Phone Unverified
                        </span>
                    </div>
                    
                    <div class="online-status">
                        <span class="dot"></span>
                        <span>Online Now</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='my-appointments.php'">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $total_appts; ?></h3>
                    <p>Total Appointments</p>
                </div>
            </div>
            <div class="stat-card" onclick="window.location.href='favorites.php'">
                <div class="stat-icon">
                    <i class="fas fa-heart"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $total_favs; ?></h3>
                    <p>Favorite Clinics</p>
                </div>
            </div>
            <div class="stat-card" onclick="window.location.href='my-appointments.php?status=completed'">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $completed; ?></h3>
                    <p>Completed</p>
                </div>
            </div>
            <div class="stat-card" onclick="window.location.href='my-appointments.php?status=pending'">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $pending; ?></h3>
                    <p>Pending</p>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="profile-grid">
            <!-- Personal Information Card -->
            <div class="profile-card view-mode" id="profileCard">
                <div class="card-header">
                    <i class="fas fa-user-circle"></i>
                    <h2>Personal Information</h2>
                    <span class="edit-badge" onclick="toggleEditMode()">
                        <i class="fas fa-edit"></i> Edit
                    </span>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                       <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Contact Number</label>
                        <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($user['contact'] ?? 'Not provided'); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Personal Address</label>
                        <textarea name="address" class="form-control" rows="3" readonly><?php echo htmlspecialchars($user['address'] ?? 'Not provided'); ?></textarea>
                    </div>

<!-- ═══════════════════════════════════════════════════ -->
<!-- ✅ NEW: MY ADDRESSES SECTION (Shopee-style)         -->
<!-- ═══════════════════════════════════════════════════ -->
<div style="margin-top: 25px; padding-top: 20px; border-top: 2px dashed var(--border-light);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; margin:0;">
            <i class="fas fa-map-marked-alt" style="color: var(--primary);"></i>
            My Addresses
            <span style="font-size: 11px; font-weight: 400; color: var(--text-muted); margin-left: 8px;">(for product deliveries)</span>
        </h3>
        <button type="button" onclick="openAddressForm()" class="addr-add-btn" style="width:auto; padding:8px 16px; font-size:12.5px; margin:0;">
            <i class="fas fa-plus"></i> Add New Address
        </button>
    </div>
    
    <div id="addressListContainer">
        <div style="text-align:center; padding:30px; color:var(--text-muted);">
            <i class="fas fa-spinner fa-spin" style="font-size:20px;"></i>
            <p style="margin-top:8px; font-size:13px;">Loading addresses...</p>
        </div>
    </div>
</div>

                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>

<!-- ============================================ -->
<!-- PWD/SENIOR VERIFICATION - MULTI CLINIC -->
<!-- ============================================ -->
<div class="pwd-senior-section">
    <div class="section-title">
        <i class="fas fa-id-card"></i>
        PWD / Senior Citizen Verification
        <?php if (count($verifications) > 0): ?>
            <span class="badge" style="background: var(--primary-light); color: var(--primary); font-size: 11px; padding: 2px 10px; margin-left: 10px;">
                <?php echo count($verifications); ?> application(s)
            </span>
        <?php endif; ?>
    </div>
    
    <?php if (count($verifications) > 0): ?>
        <!-- Show all verifications -->
        <?php foreach ($verifications as $v): 
            $status = $v['status'] ?? 'none';
            $type = $v['verification_type'] ?? '';
            $clinic_name = $v['clinic_name'] ?? 'Unknown Clinic';
            $clinic_id = $v['clinic_id'] ?? 0;
            $id_number = $v['id_number'] ?? '';
            $id_image = $v['id_image'] ?? '';
            $verified_at = $v['verified_at'] ?? '';
            $rejection_reason = $v['rejection_reason'] ?? '';
            
            $status_class = 'none';
            $status_text = 'Not Verified';
            $status_icon = 'fa-times-circle';
            $status_color = 'var(--text-muted)';
            
            if ($status == 'verified') {
                $status_class = 'verified';
                $status_text = '✅ Verified';
                $status_icon = 'fa-check-circle';
                $status_color = 'var(--success)';
            } elseif ($status == 'pending') {
                $status_class = 'pending';
                $status_text = '⏳ Pending';
                $status_icon = 'fa-clock';
                $status_color = 'var(--warning)';
            } elseif ($status == 'rejected') {
                $status_class = 'rejected';
                $status_text = '❌ Rejected';
                $status_icon = 'fa-exclamation-circle';
                $status_color = 'var(--danger)';
            }
        ?>
            <div class="pwd-info-box" style="margin-bottom: 15px; border-left-color: <?php echo $status_color; ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($clinic_name); ?></strong>
                        <span class="pwd-status-badge <?php echo $status_class; ?>" style="margin-bottom: 0; font-size: 11px; padding: 4px 12px;">
                            <i class="fas <?php echo $status_icon; ?>"></i>
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                    <span class="type-badge" style="background: <?php echo $type == 'senior' ? 'var(--primary-light)' : 'var(--secondary-light)'; ?>; color: <?php echo $type == 'senior' ? 'var(--primary)' : 'var(--secondary)'; ?>; padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $type == 'senior' ? '👴 Senior' : '♿ PWD'; ?>
                    </span>
                </div>
                
                <?php if ($status == 'verified'): ?>
                    <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                        <i class="fas fa-id-card"></i> ID: <?php echo htmlspecialchars($id_number); ?>
                        <?php if ($verified_at): ?>
                            <span style="margin-left: 15px;">
                                <i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($verified_at)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($id_image): ?>
                        <div class="pwd-id-preview" style="max-width: 100px; margin-top: 5px;">
                            <img src="../assets/images/pwd_ids/<?php echo $id_image; ?>" alt="ID">
                        </div>
                    <?php endif; ?>
                <?php elseif ($status == 'pending'): ?>
                    <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                        <i class="fas fa-id-card"></i> ID: <?php echo htmlspecialchars($id_number); ?>
                        <span style="margin-left: 15px; color: var(--warning);">
                            <i class="fas fa-hourglass-half"></i> Awaiting approval
                        </span>
                    </div>
                    <?php if ($id_image): ?>
                        <div class="pwd-id-preview" style="max-width: 100px; margin-top: 5px;">
                            <img src="../assets/images/pwd_ids/<?php echo $id_image; ?>" alt="ID">
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 10px;">
                        <a href="?cancel_pwd_request=1&clinic_id=<?php echo $clinic_id; ?>" class="btn-cancel-verify" style="padding: 6px 16px; font-size: 12px;" onclick="return confirm('Are you sure you want to cancel your verification request for <?php echo htmlspecialchars($clinic_name); ?>?')">
                            <i class="fas fa-times"></i> Cancel Request
                        </a>
                    </div>
                <?php elseif ($status == 'rejected'): ?>
                    <div style="margin-top: 8px; font-size: 13px; color: var(--danger);">
                        <i class="fas fa-exclamation-circle"></i> 
                        <?php echo htmlspecialchars($rejection_reason ?: 'No reason provided'); ?>
                    </div>
                    <div style="margin-top: 10px;">
                        <button onclick="resubmitForClinic(<?php echo $clinic_id; ?>, '<?php echo htmlspecialchars($clinic_name, ENT_QUOTES); ?>')" class="apply-verification-btn" style="padding: 6px 16px; font-size: 12px;">
                            <i class="fas fa-redo"></i> Resubmit
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <!-- Apply for another clinic button -->
        <div style="margin-top: 15px;">
            <button onclick="document.getElementById('pwdSeniorForm').style.display='block'; this.style.display='none';" class="apply-verification-btn" style="width: 100%; justify-content: center;">
                <i class="fas fa-plus"></i> Apply for Another Clinic
            </button>
        </div>
        
    <?php else: ?>
        <!-- No applications yet -->
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:15px;">
            <i class="fas fa-info-circle" style="color:var(--primary);"></i>
            Apply for PWD or Senior Citizen verification to get <strong>20% discount</strong> on all bookings at a specific clinic.
            Once verified, discount is automatically applied to all your appointments at that clinic.
        </p>
        
        <button onclick="document.getElementById('pwdSeniorForm').style.display='block'; this.style.display='none';" class="apply-verification-btn">
            <i class="fas fa-plus"></i> Apply for Verification
        </button>
    <?php endif; ?>
    
    <!-- The form (hidden by default) -->
    <div id="pwdSeniorForm" style="display:none;margin-top:15px;">
        <?php include 'pwd_senior_form.php'; ?>
    </div>
</div>
<!-- END OF PWD/SENIOR SECTION -->
            </div>

            <!-- Recent Activity Card -->
            <div class="profile-card">
                <div class="card-header">
                    <i class="fas fa-history"></i>
                    <h2>Recent Activity</h2>
                </div>
                
                <div class="timeline">
                    <?php if (mysqli_num_rows($recent_activity) > 0): ?>
                        <?php while($activity = mysqli_fetch_assoc($recent_activity)): ?>
                            <a href="<?php echo $activity['type'] == 'appointment' ? 'my-appointments.php' : 'favorites.php'; ?>" class="timeline-item">
                                <div class="timeline-icon <?php echo $activity['type']; ?>">
                                    <i class="fas fa-<?php echo $activity['type'] == 'appointment' ? 'calendar-check' : 'heart'; ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <h4><?php echo $activity['description']; ?></h4>
                                    <p>
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('M j, Y \a\t g:i A', strtotime($activity['created_at'])); ?>
                                        
                                        <?php if ($activity['type'] == 'appointment' && isset($activity['status'])): ?>
                                            <span class="timeline-badge <?php echo $activity['status']; ?>">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </a>
                        <?php endwhile; ?>
                        
                        <div class="view-all-links">
                            <a href="my-appointments.php" class="view-all-link appointments">
                                <i class="fas fa-calendar-check"></i> All Appointments
                            </a>
                            <a href="favorites.php" class="view-all-link favorites">
                                <i class="fas fa-heart"></i> All Favorites
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h2>No Activity Yet</h2>
                            <p>Start booking appointments or adding favorites!</p>
                            <a href="dashboard.php" class="btn-primary">
                                <i class="fas fa-search"></i> Browse Clinics
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- EYE EXAMINATION HISTORY CARD -->
        <div class="profile-card" style="margin-bottom: 25px;">
            <div class="card-header">
                <i class="fas fa-file-prescription" style="color: var(--primary);"></i>
                <h2>Eye Examination History</h2>
                <a href="my-examinations.php" class="edit-badge">
                    <i class="fas fa-history"></i> View Full History
                </a>
            </div>
            
            <?php
            $optical_query = mysqli_query($conn, "
                SELECT DISTINCT o.*, c.name as clinic_name
                FROM optical_records o
                LEFT JOIN clinics c ON o.clinic_id = c.id
                INNER JOIN appointments a ON o.appointment_id = a.id
                WHERE a.user_id = $user_id
                ORDER BY o.examination_date DESC
                LIMIT 10
            ");
            
            if (mysqli_num_rows($optical_query) > 0):
            ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php while($exam = mysqli_fetch_assoc($optical_query)): ?>
                        <a href="examination-details.php?id=<?php echo $exam['id']; ?>" class="exam-preview-item">
                            <div class="exam-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <div class="exam-info">
                                <div class="exam-clinic-name">
                                    <?php echo htmlspecialchars($exam['clinic_name'] ?? 'EYECORE Clinic'); ?>
                                </div>
                                <div class="exam-meta">
                                    <span>
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($exam['examination_date'])); ?>
                                    </span>
                                    <?php if ($exam['od_sph'] != 0 || $exam['os_sph'] != 0): ?>
                                        <span>
                                            <i class="fas fa-glasses"></i>
                                            OD: <?php echo $exam['od_sph']; ?> / OS: <?php echo $exam['os_sph']; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($exam['pd'] && $exam['pd'] > 0): ?>
                                        <span>
                                            <i class="fas fa-ruler"></i>
                                            PD: <?php echo $exam['pd']; ?>mm
                                        </span>
                                    <?php endif; ?>
                                    <span>
                                        <i class="fas fa-user-md"></i>
                                        Dr. <?php echo $exam['optometrist']; ?>
                                    </span>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right exam-chevron"></i>
                        </a>
                    <?php endwhile; ?>
                </div>
                
                <?php 
                $total_count = mysqli_num_rows(mysqli_query($conn, "
                    SELECT DISTINCT o.id FROM optical_records o
                    INNER JOIN appointments a ON o.appointment_id = a.id
                    WHERE a.user_id = $user_id
                "));
                if ($total_count > 10): 
                ?>
                    <div class="view-all-exams">
                        <a href="my-examinations.php">
                            View All <?php echo $total_count; ?> Records <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
                
            <?php 
            else:
            ?>
                <div class="empty-exams">
                    <i class="fas fa-file-prescription"></i>
                    <p>No examination records found.</p>
                    <p style="font-size: 12px; margin-top: 5px;">Complete an eye exam appointment to see your records here.</p>
                    <a href="dashboard.php" class="btn-primary">
                        <i class="fas fa-calendar-plus"></i> Book an Appointment
                    </a>
                </div>
            <?php 
            endif;
            ?>
        </div>

        <!-- Account Information Card -->
        <div class="info-card">
            <div class="card-header">
                <i class="fas fa-shield-alt"></i>
                <h2>Account Information</h2>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <h4>Member Since</h4>
                    <p><?php echo date('M j, Y', strtotime($user['created_at'])); ?></p>
                </div>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <h4>Last Login</h4>
                    <p>Today</p>
                </div>
                <div class="info-item">
                    <i class="fas fa-shield-alt"></i>
                    <h4>Account Status</h4>
                    <p><span style="color: var(--success);">●</span> Active</p>
                </div>
                <div class="info-item">
                    <i class="fas fa-id-card"></i>
                    <h4>Member ID</h4>
                    <p>#EYE<?php echo str_pad($user['id'], 5, '0', STR_PAD_LEFT); ?></p>
                </div>
            </div>
        </div>
    </div>
<!-- ═══════════════════════════════════════════════ -->
<!-- ADDRESS FORM MODAL                               -->
<!-- ═══════════════════════════════════════════════ -->
<div class="addr-modal-overlay" id="addrFormOverlay">
    <div class="addr-modal">
        <div class="addr-modal-head">
            <h3>
                <i class="fas fa-map-marker-alt"></i>
                <span id="addrFormTitle">Add New Address</span>
            </h3>
            <button class="addr-close" type="button" onclick="closeAddressForm()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="addr-modal-body">
            <input type="hidden" id="addr_id" value="0">
            
            <!-- Label -->
            <div class="addr-form-group">
                <label class="addr-form-label">Label</label>
                <div class="addr-label-picker">
                    <button type="button" class="addr-label-btn selected" data-label="Home" onclick="pickLabel(this)">
                        <i class="fas fa-home"></i> Home
                    </button>
                    <button type="button" class="addr-label-btn" data-label="Work" onclick="pickLabel(this)">
                        <i class="fas fa-briefcase"></i> Work
                    </button>
                    <button type="button" class="addr-label-btn" data-label="Other" onclick="pickLabel(this)">
                        <i class="fas fa-map-pin"></i> Other
                    </button>
                </div>
            </div>
            
            <!-- Recipient -->
            <div class="addr-form-row-2">
                <div class="addr-form-group">
                    <label class="addr-form-label">Recipient Name <span class="req">*</span></label>
                    <input type="text" id="addr_rname" class="addr-form-input" placeholder="Full name">
                </div>
                <div class="addr-form-group">
                    <label class="addr-form-label">Phone Number <span class="req">*</span></label>
                    <input type="tel" id="addr_rphone" class="addr-form-input" placeholder="09XX XXX XXXX">
                </div>
            </div>
            
            <!-- Use Current Location -->
            <div class="addr-form-group">
                <label class="addr-form-label">Auto-fill via GPS</label>
                <button type="button" class="addr-location-btn" id="addrLocationBtn" onclick="addrGetLocation()">
                    <i class="fas fa-location-crosshairs"></i>
                    Use Current Location
                </button>
                <div class="addr-location-status" id="addrLocationStatus"></div>
                <div id="addrMap"></div>
            </div>
            
            <!-- Street -->
            <div class="addr-form-group">
                <label class="addr-form-label">Street Address <span class="req">*</span></label>
                <input type="text" id="addr_street" class="addr-form-input" placeholder="House no., street, subdivision">
            </div>
            
            <div class="addr-form-row-2">
                <div class="addr-form-group">
                    <label class="addr-form-label">Barangay <span class="req">*</span></label>
                    <input type="text" id="addr_barangay" class="addr-form-input" placeholder="Barangay">
                </div>
                <div class="addr-form-group">
                    <label class="addr-form-label">City/Municipality <span class="req">*</span></label>
                    <select id="addr_city" class="addr-form-select">
                        <option value="">— Select City —</option>
                        <option value="Cavite City">Cavite City</option>
                        <option value="Bacoor">Bacoor</option>
                        <option value="Imus">Imus</option>
                        <option value="Dasmariñas">Dasmariñas</option>
                        <option value="Tagaytay">Tagaytay</option>
                        <option value="General Trias">General Trias</option>
                        <option value="Kawit">Kawit</option>
                        <option value="Tanza">Tanza</option>
                        <option value="Trece Martires">Trece Martires</option>
                        <option value="Naic">Naic</option>
                        <option value="Silang">Silang</option>
                        <option value="Amadeo">Amadeo</option>
                        <option value="Alfonso">Alfonso</option>
                        <option value="Carmona">Carmona</option>
                        <option value="Gen. Mariano Alvarez">Gen. Mariano Alvarez</option>
                        <option value="Magallanes">Magallanes</option>
                        <option value="Maragondon">Maragondon</option>
                        <option value="Mendez">Mendez</option>
                        <option value="Ternate">Ternate</option>
                    </select>
                </div>
            </div>
            
            <div class="addr-form-row-2">
                <div class="addr-form-group">
                    <label class="addr-form-label">Province</label>
                    <input type="text" id="addr_province" class="addr-form-input" value="Cavite" readonly style="background:#f0fdf4; color:#065f46; font-weight:700;">
                </div>
                <div class="addr-form-group">
                    <label class="addr-form-label">ZIP Code <span class="req">*</span></label>
                    <input type="text" id="addr_zip" class="addr-form-input" placeholder="4114" maxlength="4" inputmode="numeric">
                </div>
            </div>
            
            <div class="addr-form-group">
                <label class="addr-form-label">Landmark (Optional)</label>
                <input type="text" id="addr_landmark" class="addr-form-input" placeholder="e.g. Near church, beside SM">
            </div>
            
            <!-- Hidden coords -->
            <input type="hidden" id="addr_lat" value="">
            <input type="hidden" id="addr_lng" value="">
            
            <!-- Default checkbox -->
            <div class="addr-default-row">
                <input type="checkbox" id="addr_default">
                <label for="addr_default">Set as default address</label>
            </div>
            
            <!-- Actions -->
            <div class="addr-form-actions">
                <button type="button" class="addr-btn-cancel" onclick="closeAddressForm()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="addr-btn-save" id="addrSaveBtn" onclick="saveAddress()">
                    <i class="fas fa-check"></i> Save Address
                </button>
            </div>
        </div>
    </div>
</div>
    <!-- New Notification Alert -->
    <div class="new-notification-alert" id="newNotificationAlert" onclick="showNotifications()">
        <i class="fas fa-bell"></i>
        <span id="newNotificationMessage">You have new notifications!</span>
    </div>

    <script>
        // Toast Notification Function
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            
            let icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'info') icon = 'info-circle';
            
            toast.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Loading Overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const themeIcon = document.querySelector('#themeToggle i');
            
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
                if (themeIcon) themeIcon.className = 'fas fa-moon';
                showToast('Light mode activated', 'info');
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
                if (themeIcon) themeIcon.className = 'fas fa-sun';
                showToast('Dark mode activated', 'info');
            }
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const themeIcon = document.querySelector('#themeToggle i');
            
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
                if (themeIcon) themeIcon.className = 'fas fa-sun';
            } else {
                document.documentElement.classList.remove('theme-dark');
                if (themeIcon) themeIcon.className = 'fas fa-moon';
            }
        });

        // FAB Menu
        function toggleFabMenu() {
            document.getElementById('fabMenu').classList.toggle('show');
            document.getElementById('fab').classList.toggle('active');
        }

        // Close FAB menu when clicking outside
        document.addEventListener('click', function(event) {
            const fab = document.getElementById('fab');
            const fabMenu = document.getElementById('fabMenu');
            
            if (fab && fabMenu && !fab.contains(event.target) && !fabMenu.contains(event.target)) {
                fabMenu.classList.remove('show');
                fab.classList.remove('active');
            }
        });

        // Profile Menu Toggle
        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('show');
        }

        // Discover Dropdown Toggle
        function toggleDiscoverDropdown() {
            document.getElementById('discoverDropdown').classList.toggle('show');
            document.querySelector('.dropdown-trigger').classList.toggle('active');
        }

        // Mobile Menu Functions
        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('open');
            document.getElementById('mobileMenuOverlay').classList.toggle('show');
        }

        function closeMobileMenu() {
            document.getElementById('mobileMenu').classList.remove('open');
            document.getElementById('mobileMenuOverlay').classList.remove('show');
        }

        // Notifications (Desktop)
        function toggleNotifications() {
            document.getElementById('notificationMenu').classList.toggle('show');
            document.getElementById('newNotificationAlert').classList.remove('show');
        }

        function handleNotificationClick(event, element, notificationId) {
            if (!element.getAttribute('href') || element.getAttribute('href') === '#') {
                event.preventDefault();
            }
            
            markAsRead(notificationId, function() {
                element.classList.remove('unread');
                const dot = element.querySelector('.notification-dot');
                if (dot) dot.remove();
                updateBadgeCount();
                showToast('Notification marked as read', 'info');
            });
        }

        // Mobile Notifications
        function toggleMobileNotifications() {
            const menu = document.getElementById('mobileNotificationMenu');
            menu.classList.toggle('show');
            
            if (menu.classList.contains('show')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        function handleMobileNotificationClick(event, element, notificationId) {
            if (!element.getAttribute('href') || element.getAttribute('href') === '#') {
                event.preventDefault();
            }
            
            markAsRead(notificationId, function() {
                element.classList.remove('unread');
                const dot = element.querySelector('.notification-dot');
                if (dot) dot.remove();
                
                const mobileBadge = document.querySelector('#mobileNotificationBell .badge');
                const unreadCount = document.querySelectorAll('.mobile-notification-menu .notification-item.unread').length;
                
                if (unreadCount === 0) {
                    if (mobileBadge) mobileBadge.remove();
                } else {
                    if (mobileBadge) mobileBadge.textContent = unreadCount;
                }
                
                showToast('Notification marked as read', 'info');
            });
        }

        function markAllAsReadMobile() {
            fetch('mark-all-notifications-read.php', { method: 'POST' })
            .then(() => {
                document.querySelectorAll('.mobile-notification-menu .notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                    const dot = item.querySelector('.notification-dot');
                    if (dot) dot.remove();
                });
                
                const mobileBadge = document.querySelector('#mobileNotificationBell .badge');
                if (mobileBadge) mobileBadge.remove();
                
                showToast('All notifications marked as read', 'success');
            });
        }

        // Common Notification Functions
        function markAsRead(notificationId, callback) {
            fetch('mark-notification-read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && callback) callback();
            });
        }

        function markAllAsRead() {
            fetch('mark-all-notifications-read.php', { method: 'POST' })
            .then(() => {
                location.reload();
            });
        }

        function updateBadgeCount() {
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badge = document.getElementById('notificationBadge');
            
            if (unreadCount === 0 && badge) {
                badge.remove();
            }
        }

        // Resubmit for a specific clinic
        function resubmitForClinic(clinicId, clinicName) {
            // Show the form
            document.getElementById('pwdSeniorForm').style.display = 'block';
            
            // Scroll to form
            document.getElementById('pwdSeniorForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Pre-select the clinic
            setTimeout(function() {
                // Find the clinic in dropdown and select it
                const items = document.querySelectorAll('#clinicDropdown .dropdown-item');
                let found = false;
                items.forEach(item => {
                    if (parseInt(item.getAttribute('data-id')) === clinicId) {
                        const hasApplied = item.getAttribute('data-has-applied') === 'true';
                        if (!hasApplied) {
                            selectClinic(item);
                            found = true;
                        } else {
                            showToast('You already have an application for this clinic.', 'error');
                        }
                    }
                });
                
                if (found) {
                    // Go to step 2 after selecting
                    setTimeout(function() {
                        goToStep(2);
                        showToast('Resubmitting for ' + clinicName, 'info');
                    }, 300);
                } else if (!found) {
                    showToast('Clinic not found in list. Please search manually.', 'error');
                }
            }, 300);
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(event) {
            // Profile menu
            const profileMenu = document.getElementById('profileMenu');
            const profileTrigger = document.querySelector('.profile-trigger');
            if (profileMenu && profileTrigger && !profileTrigger.contains(event.target) && !profileMenu.contains(event.target)) {
                profileMenu.classList.remove('show');
            }
            
            // Discover dropdown
            const discoverDropdown = document.getElementById('discoverDropdown');
            const discoverTrigger = document.querySelector('.dropdown-trigger');
            if (discoverDropdown && discoverTrigger && !discoverTrigger.contains(event.target) && !discoverDropdown.contains(event.target)) {
                discoverDropdown.classList.remove('show');
                discoverTrigger?.classList.remove('active');
            }
            
            // Desktop notifications
            const desktopMenu = document.getElementById('notificationMenu');
            const desktopBell = document.getElementById('notificationBell');
            if (desktopMenu && desktopBell && !desktopBell.contains(event.target) && !desktopMenu.contains(event.target)) {
                desktopMenu.classList.remove('show');
            }
            
            // Mobile notifications
            const mobileMenu = document.getElementById('mobileNotificationMenu');
            const mobileBell = document.getElementById('mobileNotificationBell');
            if (mobileMenu && mobileBell && !mobileBell.contains(event.target) && !mobileMenu.contains(event.target)) {
                mobileMenu.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

function toggleEditMode() {
    const profileCard = document.getElementById('profileCard');
    const inputs = profileCard.querySelectorAll('.form-control');
    const isEditMode = profileCard.classList.contains('edit-mode');
    const saveBtn = profileCard.querySelector('.btn-save');
    
    if (isEditMode) {
        profileCard.classList.remove('edit-mode');
        profileCard.classList.add('view-mode');
        inputs.forEach(input => input.readOnly = true);
        if (saveBtn) saveBtn.style.display = 'none';
        showToast('Edit mode disabled', 'info');
        // ❌ REMOVE: location.reload();  <-- HUWAG I-RELOAD DITO
    } else {
        profileCard.classList.remove('view-mode');
        profileCard.classList.add('edit-mode');
        inputs.forEach(input => input.readOnly = false);
        if (saveBtn) saveBtn.style.display = 'block';
        showToast('Edit mode enabled - make your changes then click Save Changes', 'info');
    }
}
        
        window.addEventListener('beforeunload', function() {
            // Silent cleanup - no error
        });

/* ═══════════════════════════════════════════════════════
   ADDRESS MANAGER
   ═══════════════════════════════════════════════════════ */
let addrMap = null;
let addrMarker = null;
let selectedLabel = 'Home';

// ── Load list ──
function loadAddresses() {
    fetch('profile.php?addr_action=list')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('addressListContainer');
            if (!data.success) {
                container.innerHTML = '<div class="addr-empty"><p>Failed to load addresses.</p></div>';
                return;
            }
            const list = data.addresses || [];
            if (list.length === 0) {
                container.innerHTML = `
                    <div class="addr-empty">
                        <i class="fas fa-map-marked-alt"></i>
                        <p>You haven't added any addresses yet.</p>
                        <button type="button" class="addr-add-btn" onclick="openAddressForm()" style="width:auto; padding:10px 20px; display:inline-flex;">
                            <i class="fas fa-plus"></i> Add Your First Address
                        </button>
                    </div>`;
                return;
            }
            let html = '<div class="addr-list">';
            list.forEach(a => {
                const isDef = a.is_default ? 'is-default' : '';
                const labelIcon = a.label === 'Work' ? 'fa-briefcase'
                                : a.label === 'Other' ? 'fa-map-pin' : 'fa-home';
                html += `
                    <div class="addr-card ${isDef}">
                        <span class="addr-card-label">
                            <i class="fas ${labelIcon}"></i> ${escapeHtml(a.label)}
                        </span>
                        <div class="addr-card-name">${escapeHtml(a.recipient_name)}</div>
                        <div class="addr-card-phone">
                            <i class="fas fa-phone" style="font-size:10px; opacity:0.7;"></i>
                            ${escapeHtml(a.recipient_phone)}
                        </div>
                        <div class="addr-card-address">
                            ${escapeHtml(a.street_address)}, ${escapeHtml(a.barangay)}<br>
                            ${escapeHtml(a.city)}, ${escapeHtml(a.province)} ${escapeHtml(a.zip_code)}
                            ${a.landmark ? `<br><em style="font-size:11.5px; color:var(--text-muted);">📍 ${escapeHtml(a.landmark)}</em>` : ''}
                        </div>
                        <div class="addr-card-actions">
                            ${!a.is_default ? `<button type="button" class="addr-action-btn" onclick="setDefaultAddress(${a.id})">
                                <i class="fas fa-star"></i> Set Default
                            </button>` : ''}
                            <button type="button" class="addr-action-btn" onclick='editAddress(${JSON.stringify(a).replace(/'/g, "&#39;")})'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="addr-action-btn danger" onclick="deleteAddress(${a.id})">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('addressListContainer').innerHTML =
                '<div class="addr-empty"><p>Network error.</p></div>';
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ── Open form (add or edit) ──
function openAddressForm(data) {
    document.getElementById('addr_id').value = data ? data.id : 0;
    document.getElementById('addrFormTitle').textContent = data ? 'Edit Address' : 'Add New Address';

    // Set label
    selectedLabel = data ? data.label : 'Home';
    document.querySelectorAll('.addr-label-btn').forEach(b => {
        b.classList.toggle('selected', b.dataset.label === selectedLabel);
    });

    // Fill fields
    document.getElementById('addr_rname').value    = data ? data.recipient_name : '<?php echo htmlspecialchars($user["fullname"] ?? ""); ?>';
    document.getElementById('addr_rphone').value   = data ? data.recipient_phone : '<?php echo htmlspecialchars($user["contact"] ?? ""); ?>';
    document.getElementById('addr_street').value   = data ? data.street_address : '';
    document.getElementById('addr_barangay').value = data ? data.barangay : '';
    document.getElementById('addr_city').value     = data ? data.city : '';
    document.getElementById('addr_province').value = data ? data.province : 'Cavite';
    document.getElementById('addr_zip').value      = data ? data.zip_code : '';
    document.getElementById('addr_landmark').value = data ? (data.landmark || '') : '';
    document.getElementById('addr_lat').value      = data && data.latitude ? data.latitude : '';
    document.getElementById('addr_lng').value      = data && data.longitude ? data.longitude : '';
    document.getElementById('addr_default').checked = data ? !!data.is_default : false;

    document.getElementById('addrFormOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editAddress(data) {
    openAddressForm(data);
}

function closeAddressForm() {
    document.getElementById('addrFormOverlay').classList.remove('show');
    document.body.style.overflow = '';
    // Reset map
    if (addrMap) { addrMap.remove(); addrMap = null; addrMarker = null; }
    document.getElementById('addrMap').classList.remove('show');
    document.getElementById('addrLocationStatus').classList.remove('show');
}

function pickLabel(btn) {
    document.querySelectorAll('.addr-label-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLabel = btn.dataset.label;
}

// ── Save ──
function saveAddress() {
    const btn = document.getElementById('addrSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData();
    fd.append('id', document.getElementById('addr_id').value);
    fd.append('label', selectedLabel);
    fd.append('recipient_name', document.getElementById('addr_rname').value.trim());
    fd.append('recipient_phone', document.getElementById('addr_rphone').value.trim());
    fd.append('street_address', document.getElementById('addr_street').value.trim());
    fd.append('barangay', document.getElementById('addr_barangay').value.trim());
    fd.append('city', document.getElementById('addr_city').value);
    fd.append('province', document.getElementById('addr_province').value);
    fd.append('zip_code', document.getElementById('addr_zip').value.trim());
    fd.append('landmark', document.getElementById('addr_landmark').value.trim());
    fd.append('latitude', document.getElementById('addr_lat').value);
    fd.append('longitude', document.getElementById('addr_lng').value);
    fd.append('is_default', document.getElementById('addr_default').checked ? '1' : '0');

    fetch('profile.php?addr_action=save', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Address';
            if (data.success) {
                showToast('Address saved successfully!', 'success');
                closeAddressForm();
                loadAddresses();
            } else {
                showToast(data.message || 'Failed to save.', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Address';
            showToast('Network error.', 'error');
        });
}

// ── Delete ──
function deleteAddress(id) {
    if (!confirm('Delete this address?')) return;
    const fd = new FormData();
    fd.append('id', id);
    fetch('profile.php?addr_action=delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Address deleted.', 'success');
                loadAddresses();
            } else {
                showToast(data.message || 'Failed.', 'error');
            }
        });
}

// ── Set Default ──
function setDefaultAddress(id) {
    const fd = new FormData();
    fd.append('id', id);
    fetch('profile.php?addr_action=set_default', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Default address updated.', 'success');
                loadAddresses();
            } else {
                showToast(data.message || 'Failed.', 'error');
            }
        });
}

// ── GPS / Location ──
function addrGetLocation() {
    if (!navigator.geolocation) {
        showToast('Geolocation not supported.', 'error');
        return;
    }
    const btn = document.getElementById('addrLocationBtn');
    const status = document.getElementById('addrLocationStatus');

    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';
    status.className = 'addr-location-status';
    status.textContent = '';

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            await addrReverseGeocode(pos.coords.latitude, pos.coords.longitude);
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Refresh Location';
        },
        (err) => {
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Use Current Location';
            status.className = 'addr-location-status error show';
            const msgs = { 1: 'Permission denied.', 2: 'Unavailable.', 3: 'Timed out.' };
            status.textContent = msgs[err.code] || 'Unknown error.';
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
}

async function addrReverseGeocode(lat, lng) {
    const status = document.getElementById('addrLocationStatus');
    document.getElementById('addr_lat').value = lat;
    document.getElementById('addr_lng').value = lng;

    addrShowMap(lat, lng);

    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&zoom=18`,
            { headers: { 'Accept-Language': 'en' } });
        const data = await res.json();
        if (!data || !data.address) throw new Error('no address');

        const a = data.address;
        const province = [a.state, a.county, a.state_district].filter(Boolean).join(' ');
        const isCavite = province.toLowerCase().includes('cavite');

        if (!isCavite) {
            status.className = 'addr-location-status error show';
            status.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Outside Cavite. Only Cavite addresses are supported.';
            return;
        }

        const road     = a.road || a.pedestrian || '';
        const houseNum = a.house_number || '';
        const street   = [houseNum, road].filter(Boolean).join(' ');
        const barangay = a.village || a.suburb || a.quarter || a.neighbourhood || '';
        const zip      = a.postcode || '';
        const city     = a.city || a.town || a.municipality || a.county || '';

        if (street)   document.getElementById('addr_street').value = street;
        if (barangay) document.getElementById('addr_barangay').value = barangay;
        if (zip)      document.getElementById('addr_zip').value = zip;
        tryPickCity(city);

        status.className = 'addr-location-status success show';
        status.innerHTML = '<i class="fas fa-check-circle"></i> Location confirmed. Fields auto-filled — feel free to adjust.';
    } catch (e) {
        status.className = 'addr-location-status error show';
        status.textContent = 'Address lookup failed. Coordinates saved.';
    }
}

function tryPickCity(cityName) {
    if (!cityName) return;
    const sel = document.getElementById('addr_city');
    const norm = cityName.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();
    for (let i = 0; i < sel.options.length; i++) {
        const v = sel.options[i].value.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();
        if (!v) continue;
        if (v === norm || v.includes(norm) || norm.includes(v)) {
            sel.selectedIndex = i;
            return true;
        }
    }
    return false;
}

function addrShowMap(lat, lng) {
    const div = document.getElementById('addrMap');
    div.classList.add('show');

    if (typeof L === 'undefined') {
        // Dynamically load Leaflet if not loaded
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);

        const s = document.createElement('script');
        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = () => addrShowMap(lat, lng);
        document.head.appendChild(s);
        return;
    }

    if (!addrMap) {
        addrMap = L.map('addrMap').setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap', maxZoom: 19
        }).addTo(addrMap);
        addrMarker = L.marker([lat, lng], { draggable: true }).addTo(addrMap);
        addrMarker.on('dragend', () => {
            const p = addrMarker.getLatLng();
            addrReverseGeocode(p.lat, p.lng);
        });
        addrMap.on('click', (e) => {
            addrMarker.setLatLng(e.latlng);
            addrReverseGeocode(e.latlng.lat, e.latlng.lng);
        });
    } else {
        addrMap.setView([lat, lng], 16);
        addrMarker.setLatLng([lat, lng]);
    }
    setTimeout(() => addrMap.invalidateSize(), 200);
}

// ── Load list on page load ──
document.addEventListener('DOMContentLoaded', function() {
    loadAddresses();
});
    </script>
</body>
</html>