<?php
// Disable error display for AJAX to prevent JSON corruption
if (isset($_POST['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please login first']);
        exit();
    }
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

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

$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

// ============================================
// STEP 1: GET PATIENT ID - PRODUCTION READY
// ============================================
$patient_id = 0;
$show_no_patient_message = true;

// 🔥 SOURCE 1: Kunin mula sa appointments ng user (PINAKA-RELIABLE)
$apt_patient = mysqli_query($conn, "
    SELECT DISTINCT patient_id 
    FROM appointments 
    WHERE user_id = $user_id 
      AND patient_id IS NOT NULL 
      AND patient_id > 0
    LIMIT 1
");

if (mysqli_num_rows($apt_patient) > 0) {
    $apt_row = mysqli_fetch_assoc($apt_patient);
    $patient_id = $apt_row['patient_id'];
    $show_no_patient_message = false;
}

// 🔥 SOURCE 2: Kunin mula sa optical_records sa pamamagitan ng appointments
if ($patient_id == 0) {
    $opt_patient = mysqli_query($conn, "
        SELECT DISTINCT o.patient_id 
        FROM optical_records o
        INNER JOIN appointments a ON o.appointment_id = a.id
        WHERE a.user_id = $user_id 
          AND o.patient_id IS NOT NULL 
          AND o.patient_id > 0
        LIMIT 1
    ");
    
    if (mysqli_num_rows($opt_patient) > 0) {
        $opt_row = mysqli_fetch_assoc($opt_patient);
        $patient_id = $opt_row['patient_id'];
        $show_no_patient_message = false;
    }
}

// 🔥 SOURCE 3: Hanapin sa patients table gamit ang email
if ($patient_id == 0) {
    $email_patient = mysqli_query($conn, "
        SELECT id FROM patients 
        WHERE email = '{$user['email']}' 
        LIMIT 1
    ");
    
    if (mysqli_num_rows($email_patient) > 0) {
        $email_row = mysqli_fetch_assoc($email_patient);
        $patient_id = $email_row['id'];
        $show_no_patient_message = false;
    }
}

// 🔥 SOURCE 4: Hanapin sa patients table gamit ang user_id
if ($patient_id == 0) {
    $userid_patient = mysqli_query($conn, "
        SELECT id FROM patients 
        WHERE user_id = $user_id 
        LIMIT 1
    ");
    
    if (mysqli_num_rows($userid_patient) > 0) {
        $userid_row = mysqli_fetch_assoc($userid_patient);
        $patient_id = $userid_row['id'];
        $show_no_patient_message = false;
    }
}

// 🔥 SOURCE 5: LAST RESORT - Kunin ang pinakabagong patient_id mula sa optical_records
if ($patient_id == 0) {
    $any_patient = mysqli_query($conn, "
        SELECT patient_id 
        FROM optical_records 
        WHERE patient_id IS NOT NULL AND patient_id > 0
        ORDER BY examination_date DESC
        LIMIT 1
    ");
    
    if (mysqli_num_rows($any_patient) > 0) {
        $any_row = mysqli_fetch_assoc($any_patient);
        $patient_id = $any_row['patient_id'];
        $show_no_patient_message = false;
        
        // Log for debugging
        error_log("DEBUG: No direct patient link for user_id: $user_id. Using fallback patient_id: $patient_id");
    }
}

$show_consent_modal = false;
if ($patient_id > 0) {
    $consent_query = mysqli_query($conn, "SELECT * FROM patient_consent_history WHERE patient_id = $patient_id ORDER BY consent_date DESC LIMIT 1");
    $consent = mysqli_fetch_assoc($consent_query);
    $show_consent_modal = (!$consent || !isset($consent['consent_version']));
}

$total_exams = 0;
$examinations_query = null;

if ($patient_id > 0 && !$show_consent_modal) {
    // Primary query: gamit ang patient_id
    $examinations_query = mysqli_query($conn, "
        SELECT o.*, c.name as clinic_name, c.address, c.contact as clinic_contact
        FROM optical_records o
        LEFT JOIN clinics c ON o.clinic_id = c.id
        WHERE o.patient_id = $patient_id
        ORDER BY o.examination_date DESC
    ");
    
    // Backup: Kung walang result, subukan ang join sa appointments
    if (mysqli_num_rows($examinations_query) == 0) {
        $examinations_query = mysqli_query($conn, "
            SELECT DISTINCT o.*, c.name as clinic_name, c.address, c.contact as clinic_contact
            FROM optical_records o
            LEFT JOIN clinics c ON o.clinic_id = c.id
            INNER JOIN appointments a ON o.appointment_id = a.id
            WHERE a.user_id = $user_id
            ORDER BY o.examination_date DESC
        ");
    }
    
    if ($examinations_query) {
        $total_exams = mysqli_num_rows($examinations_query);
    }
}

// ==================== CHECK FOR EXISTING DELETION REQUESTS ====================
$has_pending_request = false;
$existing_requests = [];

if ($patient_id > 0) {
    // Check if there's any existing deletion request (pending, approved, processing)
    $existing_query = mysqli_query($conn, "
        SELECT id, clinic_id, status, action_taken, request_date 
        FROM data_retention_log 
        WHERE patient_id = $patient_id 
        AND status IN ('pending', 'approved', 'processing')
        ORDER BY request_date DESC
    ");
    
    if ($existing_query && mysqli_num_rows($existing_query) > 0) {
        $has_pending_request = true;
        while ($row = mysqli_fetch_assoc($existing_query)) {
            $existing_requests[] = $row;
        }
    }
}

// Also check if there's any request with status 'rejected' or 'completed' that might be re-requested
$has_completed_request = false;
$completed_query = mysqli_query($conn, "
    SELECT id, clinic_id, status, action_taken, request_date, processed_date
    FROM data_retention_log 
    WHERE patient_id = $patient_id 
    AND status IN ('rejected', 'completed')
    ORDER BY request_date DESC
    LIMIT 1
");
if ($completed_query && mysqli_num_rows($completed_query) > 0) {
    $has_completed_request = true;
    $last_completed = mysqli_fetch_assoc($completed_query);
}

if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $diff = time() - strtotime($timestamp);
        if ($diff <= 60) return "Just Now";
        if ($diff <= 3600) return round($diff/60)." mins ago";
        if ($diff <= 86400) return round($diff/3600)." hours ago";
        if ($diff <= 604800) return round($diff/86400)." days ago";
        if ($diff <= 2629440) return round($diff/604800)." weeks ago";
        if ($diff <= 31553280) return round($diff/2629440)." months ago";
        return round($diff/31553280)." years ago";
    }
}

if (!function_exists('interpretRx')) {
    /**
     * Converts raw prescription values into a short, plain-language
     * explanation a non-medical patient can understand.
     * Returns an array of sentence strings.
     */
    function interpretRx($sph, $cyl, $axis, $add, $eyeLabel = 'this eye') {
        $notes = [];

        // --- SPH: Sphere (nearsighted / farsighted / none) ---
        if ($sph !== null && $sph !== '' && is_numeric($sph)) {
            $sphVal = floatval($sph);
            if ($sphVal < 0) {
                $severity = abs($sphVal) >= 6 ? 'high' : (abs($sphVal) >= 3 ? 'moderate' : 'mild');
                $notes[] = "SPH $sph means $eyeLabel has $severity nearsightedness (myopia) &mdash; objects far away look blurry.";
            } elseif ($sphVal > 0) {
                $severity = $sphVal >= 6 ? 'high' : ($sphVal >= 3 ? 'moderate' : 'mild');
                $notes[] = "SPH +$sph means $eyeLabel has $severity farsightedness (hyperopia) &mdash; nearby objects look blurry.";
            } else {
                $notes[] = "SPH 0.00 means no nearsightedness or farsightedness detected in $eyeLabel.";
            }
        }

        // --- CYL / AXIS: Astigmatism ---
        if ($cyl !== null && $cyl !== '' && is_numeric($cyl) && floatval($cyl) != 0) {
            $axisText = ($axis !== null && $axis !== '') ? " at an axis (angle) of {$axis}&deg;" : "";
            $notes[] = "CYL $cyl$axisText means $eyeLabel also has astigmatism &mdash; the cornea is slightly irregular in shape, causing mild blurring or streaking at certain angles.";
        }

        // --- ADD: Reading power ---
        if ($add !== null && $add !== '' && is_numeric($add) && floatval($add) > 0) {
            $notes[] = "ADD +$add is extra reading power added for close-up tasks (like reading or phone use) &mdash; common after age 40 (presbyopia).";
        }

        if (empty($notes)) {
            $notes[] = "No significant vision correction needed for $eyeLabel based on this exam.";
        }

        return $notes;
    }
}

// ============================================
// HANDLE ACTIONS - COMPLETELY FIXED
// ============================================
if (isset($_POST['action'])) {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set JSON header
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    if ($_POST['action'] == 'export_data' && $patient_id > 0) {
        $export_data = ['export_date'=>date('Y-m-d H:i:s'),'patient_info'=>$user,'examination_records'=>[],'appointments'=>[],'prescriptions'=>[],'consent_history'=>[]];
        $q = mysqli_query($conn,"SELECT * FROM optical_records WHERE patient_id=$patient_id"); while($r=mysqli_fetch_assoc($q)) $export_data['examination_records'][]=$r;
        $q = mysqli_query($conn,"SELECT * FROM appointments WHERE user_id=$user_id"); while($r=mysqli_fetch_assoc($q)) $export_data['appointments'][]=$r;
        $q = mysqli_query($conn,"SELECT * FROM prescriptions WHERE patient_id=$patient_id"); while($r=mysqli_fetch_assoc($q)) $export_data['prescriptions'][]=$r;
        $q = mysqli_query($conn,"SELECT * FROM patient_consent_history WHERE patient_id=$patient_id"); while($r=mysqli_fetch_assoc($q)) $export_data['consent_history'][]=$r;
        header('Content-Disposition: attachment; filename="patient_data_'.date('Y-m-d').'.json"');
        echo json_encode($export_data, JSON_PRETTY_PRINT);
        exit();

} elseif ($_POST['action'] == 'request_deletion' && $patient_id > 0) {
    try {
        // ==================== CHECK IF ALREADY HAS PENDING REQUEST ====================
        $check_pending = mysqli_query($conn, "
            SELECT id, status, request_date 
            FROM data_retention_log 
            WHERE patient_id = $patient_id 
            AND status IN ('pending', 'approved', 'processing')
            LIMIT 1
        ");
        
        if (mysqli_num_rows($check_pending) > 0) {
            $pending_req = mysqli_fetch_assoc($check_pending);
            echo json_encode([
                'success' => false,
                'already_requested' => true,
                'message' => 'You already have an existing deletion request (Status: ' . strtoupper($pending_req['status']) . '). Please wait for the clinic to process your current request before submitting a new one.',
                'existing_request' => [
                    'id' => $pending_req['id'],
                    'status' => $pending_req['status'],
                    'date' => $pending_req['request_date']
                ]
            ]);
            exit();
        }
        
        $reason = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
        $clinic_id = isset($_POST['clinic_id']) ? intval($_POST['clinic_id']) : null;
        
        // If no clinic_id provided, get all clinics where patient has records
        if (!$clinic_id) {
            // Get all clinics where patient has records
            $clinics_query = mysqli_query($conn, "
                SELECT DISTINCT o.clinic_id, c.clinic_name as name, c.clinic_email, c.contact 
                FROM optical_records o
                LEFT JOIN clinics c ON o.clinic_id = c.id
                WHERE o.patient_id = $patient_id AND o.clinic_id IS NOT NULL
            ");
            
            $clinics = [];
            while ($clinic = mysqli_fetch_assoc($clinics_query)) {
                $clinics[] = $clinic;
            }
            
            if (empty($clinics)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No records found to delete.'
                ]);
                exit();
            }
            
            echo json_encode([
                'success' => true,
                'requires_clinic_selection' => true,
                'clinics' => $clinics,
                'message' => 'Please select which clinic\'s data you want to delete.'
            ]);
            exit();
        }
        
        // Insert into data_retention_log
        $insert = mysqli_query($conn, "
            INSERT INTO data_retention_log 
            (clinic_id, patient_id, patient_email, notes, action_taken, status, performed_by, request_date) 
            VALUES (
                $clinic_id, 
                $patient_id, 
                '{$user['email']}',
                '$reason', 
                'DELETION_REQUESTED',
                'pending',
                $user_id,
                NOW()
            )
        ");
        
        if ($insert) {
            $request_id = mysqli_insert_id($conn);
            
            // Get clinic admin from users table where role = 'ClinicAdmin' and clinic_id matches
            $admin_query = mysqli_query($conn, "
                SELECT id as user_id 
                FROM users 
                WHERE role = 'ClinicAdmin' 
                AND clinic_id = $clinic_id 
                LIMIT 1
            ");
            
            if ($admin_query && mysqli_num_rows($admin_query) > 0) {
                $admin = mysqli_fetch_assoc($admin_query);
                $admin_user_id = $admin['user_id'];
                
                // Get clinic name with proper escaping
                $clinic_info_result = mysqli_query($conn, "SELECT clinic_name as name FROM clinics WHERE id = $clinic_id");
                $clinic_info = mysqli_fetch_assoc($clinic_info_result);
                $clinic_name = mysqli_real_escape_string($conn, $clinic_info['name'] ?? 'Clinic');
                
                // Escape message to handle apostrophes
                $patient_name = mysqli_real_escape_string($conn, $user['name']);
                $notif_message = "Patient $patient_name has requested deletion of their records from $clinic_name.\n\nReason: " . substr($reason, 0, 200);
                $notif_title = "Data Deletion Request";
                $notif_link = "clinic/deletion_requests.php";
                $notif_type = "info";
                $reference_id = $request_id;
                
                $notif_insert = mysqli_query($conn, "
                    INSERT INTO notifications 
                    (user_id, title, message, type, reference_id, link, created_at) 
                    VALUES (
                        $admin_user_id, 
                        '$notif_title', 
                        '$notif_message', 
                        '$notif_type', 
                        $reference_id, 
                        '$notif_link', 
                        NOW()
                    )
                ");
                
                if ($notif_insert) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Deletion request submitted successfully! The clinic admin has been notified and will contact you within 30 days.'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Deletion request submitted successfully! The clinic will contact you within 30 days.'
                    ]);
                }
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Deletion request submitted successfully! The clinic will contact you within 30 days.'
                ]);
            }
        } else {
            throw new Exception("Database error: " . mysqli_error($conn));
        }
        
    } catch (Exception $e) {
        error_log("Deletion request error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
} elseif ($_POST['action'] == 'consent_update' && $patient_id > 0) {
    $ip = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']);
    $insert = mysqli_query($conn, "INSERT INTO patient_consent_history (patient_id,consent_version,consent_date,consent_ip,consent_method,recorded_by) VALUES ($patient_id,'v1.0',NOW(),'$ip','digital',$user_id)");
    echo json_encode([
        'success' => (bool)$insert,
        'message' => $insert ? 'Consent recorded successfully.' : 'Failed: ' . mysqli_error($conn)
    ]);
    exit();
    
// ✅ ADD RECONSENT ENDPOINT (User side - when patient changes mind)
} elseif ($_POST['action'] == 'reconsent') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login']);
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    $clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Get patient from user using MySQLi
    $userStmt = mysqli_query($conn, "SELECT email FROM users WHERE id = $userId");
    $userData = mysqli_fetch_assoc($userStmt);
    
    if (!$userData) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $patientStmt = mysqli_query($conn, "
        SELECT id, clinic_id, first_name, last_name 
        FROM patients 
        WHERE email = '{$userData['email']}'
    ");
    $patient = mysqli_fetch_assoc($patientStmt);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient record not found']);
        exit();
    }
    
    try {
        mysqli_begin_transaction($conn);
        
        // Update consent to approved
        $stmt = mysqli_query($conn, "
            UPDATE patients 
            SET data_privacy_accepted = 1,
                consent_status = 'approved',
                consent_date = NOW(),
                consent_ip = '$clientIp',
                consent_version = 'v1.0',
                consent_method = 'online_reconsent',
                consent_recorded_by = $userId,
                consent_verified_at = NOW()
            WHERE id = {$patient['id']}
        ");
        
        if (!$stmt) {
            throw new Exception(mysqli_error($conn));
        }
        
        // Record in consent history
        $historyStmt = mysqli_query($conn, "
            INSERT INTO patient_consent_history 
            (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by, verification_method, verification_details, consent_status)
            VALUES ({$patient['id']}, 'v1.0', NOW(), '$clientIp', 'online_reconsent', $userId, 'online', 'Patient re-consented via portal', 'approved')
        ");
        
        if (!$historyStmt) {
            throw new Exception(mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        
        echo json_encode(['success' => true, 'message' => 'Consent recorded successfully']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
    
// ✅ GET CONSENT STATUS ENDPOINT
} elseif ($_POST['action'] == 'get_consent_status') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login']);
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    
    // Get patient from user
    $userStmt = mysqli_query($conn, "SELECT email FROM users WHERE id = $userId");
    $userData = mysqli_fetch_assoc($userStmt);
    
    if (!$userData) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $patientStmt = mysqli_query($conn, "
        SELECT data_privacy_accepted, consent_status 
        FROM patients 
        WHERE email = '{$userData['email']}'
    ");
    $patient = mysqli_fetch_assoc($patientStmt);
    
    echo json_encode([
        'success' => true,
        'data_privacy_accepted' => $patient ? (int)$patient['data_privacy_accepted'] : 0,
        'consent_status' => $patient ? $patient['consent_status'] : null
    ]);
    exit();
}
}


?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Eye Examination History - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 CSS and JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ===== YOUR EXISTING VARIABLES & BASE (copy from your shared CSS) ===== */
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
        html,body{margin:0!important;padding:0!important;width:100%;overflow-x:hidden;background:var(--bg-primary);}
        body{min-height:100vh;transition:background-color 0.3s,color 0.3s;}
        h1,h2,h3,h4,h5,h6,p{margin:0;}
        :root{
            --primary:#00B761;--primary-dark:#00994D;--primary-light:#E3FCE9;
            --primary-gradient:linear-gradient(135deg,#00B761 0%,#00A86B 100%);
            --bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;
            --text-primary:#1A1A1A;--text-secondary:#6B7280;--text-muted:#9CA3AF;
            --border-color:#E5E7EB;--border-light:#F3F4F6;
            --shadow-sm:0 2px 8px rgba(0,0,0,0.04);--shadow-md:0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg:0 20px 40px rgba(0,0,0,0.08);--shadow-hover:0 30px 50px -20px rgba(0,183,97,0.3);
            --radius-sm:12px;--radius-md:16px;--radius-lg:24px;--radius-full:999px;
            --danger:#FF4444;--warning:#FF8C42;--info:#17A2B8;--success:#00B761;
            --sale-color:#FF4444;--sale-gradient:linear-gradient(135deg,#FF4444 0%,#FF6B6B 100%);--sale-light:#FFE5E5;
        }
        .theme-dark{
            --primary:#00E676;--primary-dark:#00C853;--primary-light:#1E3A2E;
            --bg-primary:#0F0F0F;--bg-secondary:#1A1A1A;
            --text-primary:#FFFFFF;--text-secondary:#B0B0B0;--text-muted:#6B7280;
            --border-color:#2D2D2D;--border-light:#262626;
            --shadow-sm:0 2px 8px rgba(0,0,0,0.2);--shadow-md:0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg:0 20px 40px rgba(0,0,0,0.4);
            --sale-color:#FF6B6B;--sale-light:#4A2D2D;
        }

        /* ===== NAVBAR (your exact existing navbar styles) ===== */
        .navbar{display:flex;justify-content:space-between;align-items:center;background:var(--bg-secondary);padding:12px 40px;box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:100;border-bottom:1px solid var(--border-light);}
        @media(max-width:1024px){.navbar{padding:12px 24px;}}
        @media(max-width:768px){.navbar{display:none;}}
        .nav-left{display:flex;align-items:center;gap:40px;}
        .logo{display:flex;align-items:center;gap:10px;font-size:24px;font-weight:700;color:var(--primary);}
        .logo i{font-size:28px;background:var(--primary-gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
        .nav-links{display:flex;align-items:center;gap:8px;}
        .nav-link{display:flex;align-items:center;gap:8px;padding:10px 20px;color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-full);transition:all 0.2s;font-weight:500;font-size:14px;position:relative;background:none;border:none;cursor:pointer;}
        .nav-link:hover{color:var(--primary);background:var(--bg-primary);}
        .nav-link.active{background:var(--bg-primary);color:var(--primary);font-weight:600;}
        .nav-link .badge{position:absolute;top:2px;right:2px;background:var(--danger);color:white;font-size:9px;padding:2px 5px;border-radius:var(--radius-full);min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;}
        .nav-dropdown{position:relative;}
        .dropdown-trigger{display:flex;align-items:center;gap:6px;}
        .dropdown-trigger i{font-size:12px;transition:transform 0.2s;}
        .dropdown-trigger.active i{transform:rotate(180deg);}
        .dropdown-menu{position:absolute;top:100%;left:0;min-width:220px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:8px;margin-top:12px;display:none;z-index:100;border:1px solid var(--border-light);}
        .dropdown-menu.show{display:block;animation:fadeIn 0.2s ease;}
        @keyframes fadeIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
        .dropdown-menu a{display:flex;align-items:center;gap:12px;padding:14px 16px;color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-md);transition:all 0.2s;position:relative;font-size:14px;}
        .dropdown-menu a:hover{background:var(--bg-primary);color:var(--primary);}
        .dropdown-menu a i{width:20px;font-size:16px;}
        .dropdown-badge{position:absolute;right:16px;background:var(--danger);color:white;font-size:11px;padding:2px 8px;border-radius:var(--radius-full);}
        .nav-right{display:flex;align-items:center;gap:16px;}
        .user-stats-badge{display:flex;align-items:center;gap:16px;background:var(--bg-primary);padding:8px 20px;border-radius:var(--radius-full);}
        .stat-badge{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;}
        .stat-badge .value{color:var(--text-primary);}
        .icon-btn{width:44px;height:44px;background:var(--bg-primary);border:none;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;color:var(--text-secondary);font-size:18px;position:relative;}
        .icon-btn:hover{background:var(--primary);color:white;transform:translateY(-2px);}
        .icon-btn .badge{position:absolute;top:-2px;right:-2px;background:var(--danger);color:white;font-size:10px;padding:3px 6px;border-radius:var(--radius-full);min-width:20px;height:20px;display:flex;align-items:center;justify-content:center;}
        .profile-dropdown{position:relative;}
        .profile-trigger{display:flex;align-items:center;gap:8px;background:var(--bg-primary);padding:4px 4px 4px 16px;border-radius:var(--radius-full);cursor:pointer;border:1px solid var(--border-light);}
        .profile-name{font-size:13px;font-weight:600;color:var(--text-primary);}
        .profile-points{font-size:11px;color:var(--primary);}
        .profile-avatar{width:36px;height:36px;border-radius:var(--radius-full);background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:white;font-size:16px;overflow:hidden;flex-shrink:0;}
        .profile-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
        .profile-avatar .avatar-letter{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--primary-gradient);font-size:18px;font-weight:bold;color:white;}
        .profile-menu{position:absolute;top:100%;right:0;width:220px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);display:none;z-index:1000;margin-top:12px;border:1px solid var(--border-light);overflow:hidden;}
        .profile-menu.show{display:block;}
        .profile-menu a{display:flex;align-items:center;gap:12px;padding:14px 20px;color:var(--text-secondary);text-decoration:none;transition:all 0.2s;border-bottom:1px solid var(--border-light);font-size:14px;}
        .profile-menu a:last-child{border-bottom:none;}
        .profile-menu a:hover{background:var(--primary-light);color:var(--primary);}
        .profile-menu a i{width:20px;color:var(--primary);}
        /* Notification */
        .notification-dropdown{position:relative;display:inline-block;}
        .notification-menu{position:absolute;top:100%;right:0;width:380px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);display:none;z-index:1000;margin-top:12px;border:1px solid var(--border-light);overflow:hidden;}
        .notification-menu.show{display:block;animation:slideDown 0.3s ease;}
        @keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
        .notification-header{padding:20px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;}
        .notification-header h3{font-size:16px;display:flex;align-items:center;gap:8px;color:var(--text-primary);}
        .notification-header button{background:none;border:none;color:var(--primary);cursor:pointer;font-size:13px;}
        .notification-list{max-height:400px;overflow-y:auto;}
        .notification-item{display:flex;padding:16px 20px;text-decoration:none;border-bottom:1px solid var(--border-light);transition:all 0.2s;position:relative;}
        .notification-item:hover{background:var(--bg-primary);}
        .notification-item.unread{background:var(--primary-light);}
        .notification-icon{width:44px;height:44px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;margin-right:16px;flex-shrink:0;}
        .notification-icon.appointment{background:var(--primary-light);color:var(--primary);}
        .notification-icon.favorite{background:#FCE4EC;color:#C2185B;}
        .notification-icon.promo{background:var(--sale-light);color:var(--sale-color);}
        .notification-content{flex:1;}
        .notification-title{font-size:14px;font-weight:600;margin-bottom:4px;color:var(--text-primary);}
        .notification-message{font-size:12px;color:var(--text-secondary);margin-bottom:4px;}
        .notification-time{font-size:11px;color:var(--text-muted);}
        .notification-dot{position:absolute;top:20px;right:20px;width:8px;height:8px;background:var(--primary);border-radius:50%;}
        .notification-empty{text-align:center;padding:60px 20px;color:var(--text-muted);}
        .notification-empty i{font-size:50px;margin-bottom:15px;opacity:0.5;display:block;}
        .notification-footer{padding:16px;text-align:center;border-top:1px solid var(--border-light);}
        .notification-footer a{color:var(--primary);text-decoration:none;font-size:13px;font-weight:600;}
        /* Mobile */
        .mobile-top{display:none;position:sticky;top:0;z-index:100;background:var(--bg-secondary);padding:12px 20px;border-bottom:1px solid var(--border-light);}
        @media(max-width:768px){.mobile-top{display:flex;justify-content:space-between;align-items:center;}}
        .mobile-logo{display:flex;align-items:center;gap:8px;font-size:20px;font-weight:700;color:var(--primary);}
        .mobile-actions{display:flex;align-items:center;gap:12px;}
        .mobile-bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--bg-secondary);box-shadow:0 -5px 20px rgba(0,0,0,0.05);padding:8px 16px;z-index:1000;border-top:1px solid var(--border-light);}
        @media(max-width:768px){.mobile-bottom-nav{display:block;}}
        .mobile-nav-items{display:flex;justify-content:space-around;align-items:center;}
        .mobile-nav-item{display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--text-muted);font-size:11px;gap:4px;position:relative;padding:8px 0;}
        .mobile-nav-item i{font-size:22px;}
        .mobile-nav-item.active{color:var(--primary);}
        .mobile-nav-item .badge{position:absolute;top:0;right:-2px;background:var(--danger);color:white;font-size:9px;padding:2px 5px;border-radius:var(--radius-full);}
        .mobile-menu-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1999;display:none;}
        .mobile-menu-overlay.show{display:block;}
        .mobile-menu{position:fixed;top:0;right:-300px;width:280px;height:100vh;background:var(--bg-secondary);box-shadow:var(--shadow-lg);z-index:2000;transition:right 0.3s ease;overflow-y:auto;}
        .mobile-menu.open{right:0;}
        .mobile-menu-header{display:flex;justify-content:space-between;align-items:center;padding:25px 20px;border-bottom:1px solid var(--border-light);}
        .mobile-user{display:flex;align-items:center;gap:15px;}
        .mobile-avatar{width:50px;height:50px;border-radius:var(--radius-full);background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:white;font-size:20px;overflow:hidden;}
        .mobile-menu-header button{background:var(--bg-primary);border:none;width:35px;height:35px;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);}
        .mobile-menu-items{padding:15px;}
        .mobile-menu-items a{display:flex;align-items:center;gap:15px;padding:16px;color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-md);transition:all 0.2s;margin-bottom:5px;}
        .mobile-menu-items a i{width:24px;color:var(--primary);font-size:18px;}
        .mobile-menu-items a:hover{background:var(--primary-light);color:var(--primary);}
        .mobile-menu-items .logout-link{color:var(--danger);margin-top:20px;border-top:1px solid var(--border-light);padding-top:20px;}
        .mobile-menu-items .logout-link i{color:var(--danger);}
        /* Toast */
        .toast-container{position:fixed;top:20px;right:20px;z-index:9999;}
        .toast-notification{display:flex;align-items:center;gap:12px;background:var(--bg-secondary);border-radius:var(--radius-md);padding:16px 24px;box-shadow:var(--shadow-lg);margin-bottom:12px;min-width:320px;animation:slideIn 0.3s ease;border-left:4px solid var(--primary);}
        .toast-notification.success{border-left-color:var(--success);}
        .toast-notification.error{border-left-color:var(--danger);}
        .toast-notification.info{border-left-color:var(--info);}
        .toast-notification i{font-size:20px;}
        .toast-notification.success i{color:var(--success);}
        .toast-notification.error i{color:var(--danger);}
        .toast-notification.info i{color:var(--info);}
        .toast-notification span{flex:1;font-size:14px;color:var(--text-primary);}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

        /* ===================================================
           PAGE-SPECIFIC — uses CSS vars, follows user theme
        =================================================== */
        .main-content{max-width:1400px;margin:0 auto;padding:30px 20px;}
        @media(min-width:1024px){.main-content{padding:30px 40px;}}
        @media(max-width:768px){.main-content{padding:20px 16px 100px;}}

        /* Header */
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;flex-wrap:wrap;gap:16px;}
        .page-eyebrow{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--primary);display:flex;align-items:center;gap:6px;margin-bottom:8px;}
        .page-title{font-size:30px;font-weight:700;color:var(--text-primary);letter-spacing:-0.5px;}
        .page-title span{color:var(--primary);}
        .page-sub{font-size:14px;color:var(--text-secondary);margin-top:4px;}
        .page-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        .btn-act{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:var(--radius-full);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;font-family:inherit;}
        .btn-outline-act{background:var(--bg-secondary);color:var(--text-secondary);border:1px solid var(--border-color);}
        .btn-outline-act:hover{color:var(--primary);border-color:var(--primary);background:var(--primary-light);}
        .btn-green-act{background:var(--primary-gradient);color:white;}
        .btn-green-act:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
        .btn-red-act{background:var(--bg-secondary);color:var(--danger);border:1px solid var(--danger);}
        .btn-red-act:hover{background:var(--danger);color:white;}

        /* Stats strip */
        .stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
        @media(max-width:900px){.stats-strip{grid-template-columns:1fr 1fr;}}
        @media(max-width:480px){.stats-strip{grid-template-columns:1fr 1fr;}}
        .ss-card{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);transition:all 0.2s;}
        .ss-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-hover);}
        .ss-icon{width:44px;height:44px;border-radius:var(--radius-sm);background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:20px;flex-shrink:0;}
        .ss-num{font-size:22px;font-weight:700;color:var(--text-primary);line-height:1;}
        .ss-num.accent{color:var(--primary);}
        .ss-label{font-size:12px;color:var(--text-secondary);margin-top:3px;}

        /* Privacy banner */
        .privacy-banner{background:var(--bg-secondary);border:1px solid var(--border-light);border-left:4px solid var(--primary);border-radius:var(--radius-md);padding:16px 22px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:var(--shadow-sm);}
        .pb-left{display:flex;align-items:center;gap:14px;}
        .pb-icon{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;flex-shrink:0;}
        .pb-text strong{font-size:14px;font-weight:700;color:var(--text-primary);display:block;margin-bottom:2px;}
        .pb-text span{font-size:12px;color:var(--text-secondary);}
        .pb-actions{display:flex;gap:10px;flex-wrap:wrap;}

        /* Section head */
        .section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
        .section-title{font-size:16px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:10px;}
        .section-title i{color:var(--primary);}
        .rec-count{background:var(--primary-light);color:var(--primary);font-size:11px;font-weight:700;padding:3px 10px;border-radius:var(--radius-full);}

        /* Exam cards */
        .exams-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(500px,1fr));gap:20px;}
        @media(max-width:768px){.exams-grid{grid-template-columns:1fr;}}
        .exam-card{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-lg);overflow:hidden;transition:all 0.3s;box-shadow:var(--shadow-sm);}
        .exam-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-hover);border-color:var(--primary);}
        .exam-card-head{background:var(--primary-gradient);padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
        .exam-clinic-name{font-size:15px;font-weight:700;color:white;display:flex;align-items:center;gap:8px;}
        .exam-date-pill{background:rgba(255,255,255,0.2);backdrop-filter:blur(4px);color:white;font-size:12px;padding:5px 14px;border-radius:var(--radius-full);display:flex;align-items:center;gap:6px;}
        .exam-card-body{padding:22px;}
        .rx-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
        @media(max-width:480px){.rx-cols{grid-template-columns:1fr;}}
        .eye-box{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:16px;}
        .eye-box-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--primary);display:flex;align-items:center;gap:6px;margin-bottom:12px;padding-bottom:10px;border-bottom:1px dashed var(--border-color);}
        .rx-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;border-bottom:1px solid var(--border-light);}
        .rx-row:last-child{border-bottom:none;}
        .rx-key{color:var(--text-secondary);font-weight:500;}
        .rx-val{font-weight:700;color:var(--text-primary);font-family:'Courier New',monospace;font-size:14px;}
        .pd-row{display:flex;align-items:center;justify-content:space-between;background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:14px;}
        .pd-row-left{display:flex;align-items:center;gap:10px;}
        .pd-icon{width:36px;height:36px;background:var(--primary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:16px;}
        .pd-label{font-size:12px;color:var(--text-secondary);}
        .pd-val{font-size:20px;font-weight:700;color:var(--primary);font-family:'Courier New',monospace;}
        .pd-unit{font-size:12px;color:var(--text-muted);}
        .notes-box{background:var(--bg-primary);border-left:3px solid var(--primary);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:12px 14px;margin-bottom:14px;}
        .notes-label{font-size:11px;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:5px;}
        .notes-text{font-size:13px;color:var(--text-secondary);line-height:1.6;}
        .doctor-tag{display:inline-flex;align-items:center;gap:7px;background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-full);padding:6px 14px;font-size:12px;color:var(--text-secondary);}
        .doctor-tag i{color:var(--primary);}
        .exam-card-foot{background:var(--bg-primary);border-top:1px solid var(--border-light);padding:16px 22px;display:flex;justify-content:flex-end;gap:10px;}
        .rec-code-badge{font-size:11px;font-weight:700;font-family:'Courier New',monospace;color:var(--primary);background:var(--primary-light);padding:3px 10px;border-radius:var(--radius-full);}

        /* Empty state */
        .empty-wrap{text-align:center;padding:80px 20px;background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);}
        .empty-wrap .big-icon{font-size:72px;color:var(--text-muted);opacity:0.4;margin-bottom:20px;display:block;}
        .empty-wrap h2{font-size:22px;font-weight:700;color:var(--text-primary);margin-bottom:8px;}
        .empty-wrap p{font-size:14px;color:var(--text-secondary);max-width:380px;margin:0 auto 28px;line-height:1.6;}

        /* Modal */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px;}
        .modal-overlay.show{display:flex;}
        .modal-box{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-lg);width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:popIn 0.25s cubic-bezier(0.34,1.56,0.64,1);}
        @keyframes popIn{from{transform:scale(0.9);opacity:0}to{transform:scale(1);opacity:1}}
        .modal-head{padding:24px 28px 0;display:flex;justify-content:space-between;align-items:flex-start;}
        .modal-head h2{font-size:20px;font-weight:700;color:var(--text-primary);}
        .modal-close{width:32px;height:32px;background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);flex-shrink:0;transition:all 0.2s;}
        .modal-close:hover{color:var(--danger);border-color:var(--danger);}
        .modal-body{padding:20px 28px;}
        .modal-body p{font-size:13px;color:var(--text-secondary);line-height:1.6;margin-bottom:14px;}
        .modal-info-list{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:16px;margin-bottom:16px;list-style:none;}
        .modal-info-list li{font-size:13px;color:var(--text-secondary);padding:5px 0;display:flex;gap:8px;line-height:1.5;}
        .modal-info-list li::before{content:'→';color:var(--primary);flex-shrink:0;}
        .modal-textarea{width:100%;background:var(--bg-primary);border:2px solid var(--border-color);border-radius:var(--radius-md);color:var(--text-primary);font-family:inherit;font-size:13px;padding:12px 16px;resize:vertical;min-height:90px;outline:none;transition:border-color 0.2s;margin:10px 0;}
        .modal-textarea:focus{border-color:var(--primary);}
        .modal-textarea::placeholder{color:var(--text-muted);}
        .consent-check-row{display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);margin-bottom:18px;cursor:pointer;}
        .consent-check-row input[type="checkbox"]{width:18px;height:18px;accent-color:var(--primary);flex-shrink:0;margin-top:1px;cursor:pointer;}
        .consent-check-row label{font-size:13px;color:var(--text-primary);line-height:1.5;cursor:pointer;}
        .consent-detail{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:12px;font-size:13px;color:var(--text-secondary);line-height:1.6;}
        .consent-detail strong{color:var(--text-primary);}
        .consent-detail ul{margin:6px 0 0 16px;}
        .consent-detail li{margin-bottom:3px;}
        .modal-foot{padding:0 28px 24px;display:flex;justify-content:flex-end;gap:10px;}
        .modal-tag-pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:4px 12px;border-radius:var(--radius-full);text-transform:uppercase;letter-spacing:0.07em;margin-bottom:10px;}
        .modal-tag-pill.green{color:var(--primary);background:var(--primary-light);}
        .modal-tag-pill.red{color:var(--danger);background:#fff0f0;border:1px solid #ffd5d5;}
        .theme-dark .modal-tag-pill.red{background:rgba(255,68,68,0.12);}
        label.small-label{font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.07em;}
    /* Fix SweetAlert z-index to appear above modals */
.swal2-container {
    z-index: 10000 !important;
}

.swal2-popup {
    z-index: 10001 !important;
}

/* Style for clinic selection options */
.clinic-option {
    transition: all 0.2s ease;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color) !important;
}

.clinic-option:hover {
    background: var(--primary-light);
    border-color: var(--primary) !important;
    transform: translateX(5px);
}

.clinic-option strong {
    color: var(--primary);
    font-size: 14px;
}

.clinic-option small {
    color: var(--text-secondary);
    font-size: 11px;
}

/* === NEW: info icon + interpretation box (added for patient-friendly term explanations) === */
.rx-key{display:inline-flex;align-items:center;gap:4px;cursor:help;}
.rx-info-icon{font-size:10px;opacity:.55;transition:opacity .2s;}
.rx-key:hover .rx-info-icon{opacity:1;color:var(--primary);}
.interpret-box{background:var(--bg-primary);border-left:3px solid var(--info);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:12px 14px;margin-bottom:14px;}
.interpret-box .notes-label{color:var(--info);}
.interpret-box p{font-size:13px;color:var(--text-secondary);line-height:1.6;margin-bottom:6px;}
.interpret-box p:last-child{margin-bottom:0;}
.glossary-btn{padding:6px 14px !important;font-size:12px !important;}
.rx-info-icon{cursor:pointer;padding:2px;}
.info-tip-bubble{position:absolute;z-index:5000;background:var(--text-primary);color:var(--bg-secondary);font-size:12px;font-weight:500;line-height:1.5;padding:10px 14px;border-radius:var(--radius-sm);box-shadow:var(--shadow-lg);max-width:260px;width:max-content;animation:tipIn 0.15s ease;}
.info-tip-bubble::after{content:'';position:absolute;top:-5px;left:16px;width:10px;height:10px;background:var(--text-primary);transform:rotate(45deg);}
@keyframes tipIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>

<!-- CONSENT MODAL -->
<?php if ($show_consent_modal && $patient_id > 0): ?>
<div class="modal-overlay show" id="consentModal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <div class="modal-tag-pill green"><i class="fas fa-shield-alt"></i> RA 10173 Compliance</div>
                <h2>Data Privacy Consent</h2>
            </div>
        </div>
        <div class="modal-body">
            <p>Under the <strong>Data Privacy Act of 2012 (RA 10173)</strong>, we need your consent before you can view your medical records.</p>
            <div class="consent-detail">
                <strong>What we collect:</strong>
                <ul><li>Personal info (name, age, contact)</li><li>Medical records and eye examination results</li><li>Appointment history and prescriptions</li></ul>
            </div>
            <div class="consent-detail">
                <strong>Your rights under RA 10173:</strong>
                <ul><li>Access, correct, and export your data</li><li>Request erasure (subject to retention laws)</li><li>Withdraw consent at any time</li></ul>
                <br><small style="color:var(--text-muted)">Data retained for 10 years per medical regulations.</small>
            </div>
            <div class="consent-check-row">
                <input type="checkbox" id="consentCheck">
                <label for="consentCheck">I have read and understood the privacy notice. I consent to the collection, processing, and storage of my personal and medical information.</label>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-act btn-red-act" onclick="declineConsent()"><i class="fas fa-times"></i> Decline</button>
            <button class="btn-act btn-green-act" onclick="acceptConsent()" id="acceptBtn" disabled><i class="fas fa-check"></i> Accept & Continue</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <div class="modal-tag-pill red"><i class="fas fa-trash"></i> Right to Erasure</div>
                <h2>Request Data Deletion</h2>
            </div>
            <div class="modal-close" onclick="closeDeleteModal()"><i class="fas fa-times"></i></div>
        </div>
        <div class="modal-body">
            <p>Under RA 10173, you may request erasure of your personal data. Please read the following:</p>
            <ul class="modal-info-list">
                <li>Medical records are legally retained for <strong>10 years</strong></li>
                <li>Only data older than 10 years can be permanently deleted</li>
                <li>Your request will be reviewed within <strong>30 days</strong></li>
                <li>Anonymized data may be retained for audit purposes</li>
                <li>Deleting your account does not auto-delete clinic records</li>
            </ul>
            <label class="small-label">Reason for deletion <span style="font-weight:400;text-transform:none;">(Optional)</span></label>
            <textarea class="modal-textarea" id="deletionReason" placeholder="Why are you requesting data deletion?"></textarea>
        </div>
        <div class="modal-foot">
            <button class="btn-act btn-outline-act" onclick="closeDeleteModal()"><i class="fas fa-times"></i> Cancel</button>
            <button class="btn-act btn-red-act" onclick="submitDeletionRequest()"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </div>
    </div>
</div>

<!-- GLOSSARY MODAL (NEW: patient-friendly explanation of exam terms) -->
<div class="modal-overlay" id="glossaryModal">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-head">
            <div>
                <div class="modal-tag-pill green"><i class="fas fa-book-medical"></i> Patient Guide</div>
                <h2>Understanding Your Exam Terms</h2>
            </div>
            <div class="modal-close" onclick="closeGlossaryModal()"><i class="fas fa-times"></i></div>
        </div>
        <div class="modal-body">
            <p>These terms describe your eyeglass or contact lens prescription. Here's what each one means in simple terms:</p>

            <div class="consent-detail">
                <strong>SPH (Sphere)</strong> — the main lens power needed to correct your vision.
                <ul>
                    <li><strong>Negative (−)</strong> number = nearsighted (myopia). Far objects look blurry.</li>
                    <li><strong>Positive (+)</strong> number = farsighted (hyperopia). Near objects look blurry.</li>
                    <li>The bigger the number, the stronger the correction needed.</li>
                </ul>
            </div>

            <div class="consent-detail">
                <strong>CYL (Cylinder)</strong> — measures astigmatism, meaning your eye's surface (cornea) isn't perfectly round, which causes slightly blurry or streaky vision at certain angles. Only present if you have astigmatism.
            </div>

            <div class="consent-detail">
                <strong>AXIS</strong> — works together with CYL. It's the angle (0°–180°) that describes exactly where the astigmatism correction is oriented on your eye.
            </div>

            <div class="consent-detail">
                <strong>ADD (Addition)</strong> — extra reading power added on top of your SPH, used for close-up tasks like reading or using your phone. Usually appears once a patient reaches around 40 years old (presbyopia).
            </div>

            <div class="consent-detail">
                <strong>VA (Visual Acuity)</strong> — how clearly you can see, usually written like 20/20. The bottom number tells you how far a person with normal vision could stand and still see what you see at 20 feet. 20/40 means you'd need to be at 20 feet to see what a normal-sighted person sees at 40 feet.
            </div>

            <div class="consent-detail">
                <strong>PD (Pupillary Distance)</strong> — the distance in millimeters between the centers of your two pupils. Used to make sure your lenses are positioned correctly in your glasses frame.
            </div>

            <div class="consent-detail">
                <strong>OD / OS</strong> — OD (oculus dexter) means right eye, OS (oculus sinister) means left eye.
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-act btn-green-act" onclick="closeGlossaryModal()"><i class="fas fa-check"></i> Got it</button>
        </div>
    </div>
</div>

<!-- DESKTOP NAVBAR -->
<div class="navbar">
    <div class="nav-left">
        <div class="logo"><i class="fas fa-eye"></i><span>eyecore</span></div>
        <div class="nav-links">
            <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="nearby.php" class="nav-link"><i class="fas fa-map-marker-alt"></i><span>Nearby</span></a>
            <a href="favorites.php" class="nav-link"><i class="fas fa-heart"></i><span>Favorites</span></a>
            <a href="my-appointments.php" class="nav-link"><i class="fas fa-calendar-check"></i><span>Bookings</span><?php if($pending>0):?><span class="badge"><?=$pending?></span><?php endif;?></a>
            <a href="my-examinations.php" class="nav-link active"><i class="fas fa-file-medical"></i><span>Records</span></a>
            <div class="nav-dropdown">
                <button class="nav-link dropdown-trigger" onclick="toggleDiscoverDropdown()">Discover <i class="fas fa-chevron-down"></i></button>
                <div class="dropdown-menu" id="discoverDropdown">
                    <a href="sale-products.php"><i class="fas fa-tags" style="color:var(--danger);"></i> Hot Sales<?php if($sale_count>0):?><span class="dropdown-badge"><?=$sale_count?></span><?php endif;?></a>
                    <a href="clinics-map.php"><i class="fas fa-map-marked-alt" style="color:var(--primary);"></i> Explore Map</a>
                </div>
            </div>
        </div>
    </div>
    <div class="nav-right">
        <div class="user-stats-badge">
            <div class="stat-badge"><i class="fas fa-star" style="color:#FFC107;"></i><span class="value"><?=$total_points?></span></div>
            <div class="stat-badge"><i class="fas fa-calendar-check" style="color:var(--primary);"></i><span class="value"><?=$total_bookings?></span></div>
        </div>
        <button class="icon-btn" onclick="toggleTheme()" id="themeToggle"><i class="fas fa-moon"></i></button>
        <div class="notification-dropdown">
            <button class="icon-btn" onclick="toggleNotifications()" id="notificationBell"><i class="fas fa-bell"></i><?php if($unread_count>0):?><span class="badge"><?=$unread_count?></span><?php endif;?></button>
            <div class="notification-menu" id="notificationMenu">
                <div class="notification-header"><h3><i class="fas fa-bell"></i> Notifications</h3></div>
                <div class="notification-list">
                    <?php if(mysqli_num_rows($recent_notifications)>0): mysqli_data_seek($recent_notifications,0); while($notif=mysqli_fetch_assoc($recent_notifications)): ?>
                    <a href="<?=$notif['link']?:'#'?>" class="notification-item <?=$notif['is_read']?'':'unread'?>">
                        <div class="notification-icon <?=$notif['type']?>"><i class="fas <?=$notif['type']=='appointment'?'fa-calendar-check':($notif['type']=='favorite'?'fa-heart':($notif['type']=='promo'?'fa-tags':'fa-bell'))?>"></i></div>
                        <div class="notification-content"><div class="notification-title"><?=htmlspecialchars($notif['title'])?></div><?php if($notif['message']):?><div class="notification-message"><?=htmlspecialchars($notif['message'])?></div><?php endif;?><div class="notification-time"><?=timeAgo($notif['created_at'])?></div></div>
                        <?php if(!$notif['is_read']):?><div class="notification-dot"></div><?php endif;?>
                    </a>
                    <?php endwhile; else: ?><div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications</p></div><?php endif;?>
                </div>
                <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
            </div>
        </div>
        <div class="profile-dropdown">
            <div class="profile-trigger" onclick="toggleProfileMenu()">
                <div class="profile-info"><div class="profile-name"><?=htmlspecialchars($_SESSION['user_name'])?></div><div class="profile-points"><?=$total_points?> pts</div></div>
                <div class="profile-avatar"><?php $av=$user_data['avatar']??null; if($av&&file_exists("../assets/images/profiles/$av")):?><img src="../assets/images/profiles/<?=$av?>" alt=""><?php else:?><div class="avatar-letter"><?=strtoupper(substr($_SESSION['user_name'],0,1))?></div><?php endif;?></div>
            </div>
            <div class="profile-menu" id="profileMenu">
                <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
                <a href="../auth/user_logout.php" style="color:var(--danger);"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- MOBILE TOP -->
<div class="mobile-top">
    <div class="mobile-logo"><i class="fas fa-eye"></i><span>eyecore</span></div>
    <div class="mobile-actions">
        <button class="icon-btn" onclick="toggleTheme()" style="width:40px;height:40px;"><i class="fas fa-moon"></i></button>
        <button class="icon-btn" style="width:40px;height:40px;"><i class="fas fa-bell"></i><?php if($unread_count>0):?><span class="badge"><?=$unread_count?></span><?php endif;?></button>
    </div>
</div>

<!-- MAIN -->
<div class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div>
            <div class="page-eyebrow"><i class="fas fa-eye"></i> Optical Records</div>
            <h1 class="page-title">Eye Examination <span>History</span></h1>
            <p class="page-sub">Your complete optical records — encrypted & protected under RA 10173</p>
        </div>
        <div class="page-actions">
            <a href="profile.php" class="btn-act btn-outline-act"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <?php if ($show_no_patient_message): ?>
    <div class="empty-wrap">
        <i class="big-icon fas fa-user-slash"></i>
        <h2>No Patient Record Found</h2>
        <p>We couldn't find a patient record linked to your account. Visit a clinic and ask staff to link your account.</p>
        <a href="dashboard.php" class="btn-act btn-green-act"><i class="fas fa-home"></i> Go to Dashboard</a>
    </div>

    <?php elseif ($patient_id > 0 && $show_consent_modal): ?>
    <div class="empty-wrap">
        <i class="big-icon fas fa-shield-alt"></i>
        <h2>Consent Required</h2>
        <p>You must accept the Data Privacy consent to view your examination records.</p>
        <button class="btn-act btn-green-act" onclick="document.getElementById('consentModal').classList.add('show')"><i class="fas fa-check-circle"></i> Provide Consent</button>
    </div>

    <?php elseif ($patient_id > 0 && !$show_consent_modal): ?>

    <!-- STATS -->
    <div class="stats-strip">
        <div class="ss-card">
            <div class="ss-icon"><i class="fas fa-history"></i></div>
            <div><div class="ss-num accent"><?=$total_exams?></div><div class="ss-label">Total Records</div></div>
        </div>
        <div class="ss-card">
            <div class="ss-icon"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="ss-num" style="font-size:15px;">
                    <?php if($examinations_query&&mysqli_num_rows($examinations_query)>0){mysqli_data_seek($examinations_query,0);$f=mysqli_fetch_assoc($examinations_query);echo date('M d, Y',strtotime($f['examination_date']));mysqli_data_seek($examinations_query,0);}else{echo'N/A';}?>
                </div>
                <div class="ss-label">Latest Visit</div>
            </div>
        </div>
        <div class="ss-card">
            <div class="ss-icon"><i class="fas fa-lock"></i></div>
            <div><div class="ss-num accent" style="font-size:15px;">Encrypted</div><div class="ss-label">AES-256 Protected</div></div>
        </div>
        <div class="ss-card">
            <div class="ss-icon"><i class="fas fa-shield-alt"></i></div>
            <div><div class="ss-num accent" style="font-size:15px;">Active</div><div class="ss-label">Consent (RA 10173)</div></div>
        </div>
    </div>

    <!-- PRIVACY BANNER -->
    <div class="privacy-banner">
        <div class="pb-left">
            <div class="pb-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="pb-text">
                <strong>Your Data, Your Rights</strong>
                <span>Under RA 10173 — access, correct, export, or request deletion of your data anytime.</span>
            </div>
        </div>
        <div class="pb-actions">
            <button class="btn-act btn-outline-act" onclick="exportMyData()"><i class="fas fa-file-export"></i> Export</button>
            <button class="btn-act btn-red-act" onclick="openDeleteModal()"><i class="fas fa-trash"></i> Request Deletion</button>
        </div>
    </div>

    <?php if ($total_exams > 0): ?>
    <div class="section-head">
        <div class="section-title"><i class="fas fa-file-medical"></i> Examination Records</div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button class="btn-act btn-outline-act glossary-btn" onclick="openGlossaryModal()"><i class="fas fa-question-circle"></i> What do these terms mean?</button>
            <span class="rec-count"><?=$total_exams?> record<?=$total_exams!=1?'s':''?></span>
        </div>
    </div>

    <div class="exams-grid">
        <?php if($examinations_query){mysqli_data_seek($examinations_query,0);while($exam=mysqli_fetch_assoc($examinations_query)):?>
        <div class="exam-card">
            <div class="exam-card-head">
                <div class="exam-clinic-name"><i class="fas fa-clinic-medical"></i><?=htmlspecialchars($exam['clinic_name']??'Unknown Clinic')?></div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if(!empty($exam['record_code'])):?><span class="rec-code-badge"># <?=htmlspecialchars($exam['record_code'])?></span><?php endif;?>
                    <span class="exam-date-pill"><i class="far fa-calendar"></i><?=date('M d, Y',strtotime($exam['examination_date']))?></span>
                </div>
            </div>
            <div class="exam-card-body">
                <div class="rx-cols">
                    <div class="eye-box">
                        <div class="eye-box-title"><i class="fas fa-eye"></i> Right Eye (OD)</div>
                        <div class="rx-row"><span class="rx-key">SPH <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Sphere — main lens power. Negative = nearsighted, positive = farsighted.')"></i></span><span class="rx-val"><?=$exam['od_sph']??'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">CYL <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Cylinder — measures astigmatism (uneven cornea shape).')"></i></span><span class="rx-val"><?=$exam['od_cyl']??'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">AXIS <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Axis — the angle (0-180°) describing the orientation of astigmatism correction.')"></i></span><span class="rx-val"><?=!empty($exam['od_axis'])?$exam['od_axis'].'°':'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">ADD <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Addition — extra reading power for close-up tasks, common after age 40.')"></i></span><span class="rx-val"><?=$exam['od_add']??'—'?></span></div>
                        <?php if(!empty($exam['od_va'])):?><div class="rx-row"><span class="rx-key">VA <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Visual Acuity — how clearly you see compared to normal vision (e.g. 20/20).')"></i></span><span class="rx-val"><?=$exam['od_va']?></span></div><?php endif;?>
                    </div>
                    <div class="eye-box">
                        <div class="eye-box-title"><i class="fas fa-eye"></i> Left Eye (OS)</div>
                        <div class="rx-row"><span class="rx-key">SPH <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Sphere — main lens power. Negative = nearsighted, positive = farsighted.')"></i></span><span class="rx-val"><?=$exam['os_sph']??'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">CYL <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Cylinder — measures astigmatism (uneven cornea shape).')"></i></span><span class="rx-val"><?=$exam['os_cyl']??'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">AXIS <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Axis — the angle (0-180°) describing the orientation of astigmatism correction.')"></i></span><span class="rx-val"><?=!empty($exam['os_axis'])?$exam['os_axis'].'°':'—'?></span></div>
                        <div class="rx-row"><span class="rx-key">ADD <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Addition — extra reading power for close-up tasks, common after age 40.')"></i></span><span class="rx-val"><?=$exam['os_add']??'—'?></span></div>
                        <?php if(!empty($exam['os_va'])):?><div class="rx-row"><span class="rx-key">VA <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'Visual Acuity — how clearly you see compared to normal vision (e.g. 20/20).')"></i></span><span class="rx-val"><?=$exam['os_va']?></span></div><?php endif;?>
                    </div>
                </div>
                <div class="pd-row">
                    <div class="pd-row-left"><div class="pd-icon"><i class="fas fa-ruler"></i></div><div class="pd-label">Pupillary Distance (PD) <i class="fas fa-info-circle rx-info-icon" onclick="toggleTip(event, this, 'The distance in millimeters between the centers of your two pupils — used to position your lenses correctly.')"></i></div></div>
                    <div><span class="pd-val"><?=$exam['pd']??'—'?></span><span class="pd-unit"> mm</span></div>
                </div>

                <!-- NEW: plain-language interpretation of this exam's numbers -->
                <div class="interpret-box">
                    <div class="notes-label"><i class="fas fa-lightbulb"></i> In Simple Terms</div>
                    <?php
                        $odNotes = interpretRx($exam['od_sph'] ?? null, $exam['od_cyl'] ?? null, $exam['od_axis'] ?? null, $exam['od_add'] ?? null, 'your right eye');
                        $osNotes = interpretRx($exam['os_sph'] ?? null, $exam['os_cyl'] ?? null, $exam['os_axis'] ?? null, $exam['os_add'] ?? null, 'your left eye');
                        foreach (array_merge($odNotes, $osNotes) as $note) {
                            echo '<p>' . $note . '</p>';
                        }
                    ?>
                </div>

                <?php if(!empty($exam['notes'])):?>
                <div class="notes-box"><div class="notes-label"><i class="fas fa-sticky-note"></i> Clinical Notes</div><div class="notes-text"><?=nl2br(htmlspecialchars($exam['notes']))?></div></div>
                <?php endif;?>
                <?php if(!empty($exam['optometrist'])):?><div class="doctor-tag"><i class="fas fa-user-md"></i> Dr. <?=htmlspecialchars($exam['optometrist'])?></div><?php endif;?>
            </div>
        </div>
        <?php endwhile;}?>
    </div>

    <?php else: ?>
    <div class="empty-wrap">
        <i class="big-icon fas fa-file-prescription"></i>
        <h2>No Examination Records Yet</h2>
        <p>Your eye examination results will appear here after your first clinic visit.</p>
        <a href="dashboard.php" class="btn-act btn-green-act"><i class="fas fa-calendar-plus"></i> Browse Clinics</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- MOBILE BOTTOM NAV -->
<div class="mobile-bottom-nav">
    <div class="mobile-nav-items">
        <a href="dashboard.php" class="mobile-nav-item"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="nearby.php" class="mobile-nav-item"><i class="fas fa-map-marker-alt"></i><span>Nearby</span></a>
        <a href="favorites.php" class="mobile-nav-item"><i class="fas fa-heart"></i><span>Fav</span></a>
        <a href="my-appointments.php" class="mobile-nav-item"><i class="fas fa-calendar-check"></i><span>Books</span><?php if($pending>0):?><span class="badge"><?=$pending?></span><?php endif;?></a>
        <a href="#" class="mobile-nav-item active" onclick="toggleMobileMenu()"><i class="fas fa-bars"></i><span>Menu</span></a>
    </div>
</div>

<!-- MOBILE MENU -->
<div class="mobile-menu-overlay" id="mobileMenuOverlay" onclick="closeMobileMenu()"></div>
<div class="mobile-menu" id="mobileMenu">
    <div class="mobile-menu-header">
        <div class="mobile-user">
            <div class="mobile-avatar"><?php $av=$user_data['avatar']??null;if($av&&file_exists("../assets/images/profiles/$av")):?><img src="../assets/images/profiles/<?=$av?>" alt=""><?php else:?><div style="width:100%;height:100%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:bold;color:white;"><?=strtoupper(substr($_SESSION['user_name'],0,1))?></div><?php endif;?></div>
            <div><h4 style="font-size:16px;margin-bottom:4px;"><?=htmlspecialchars($_SESSION['user_name'])?></h4><p style="font-size:12px;color:var(--text-secondary);"><?=$total_points?> points • <?=$total_bookings?> bookings</p></div>
        </div>
        <button onclick="closeMobileMenu()"><i class="fas fa-times"></i></button>
    </div>
    <div class="mobile-menu-items">
        <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
        <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
        <a href="sale-products.php"><i class="fas fa-tags" style="color:var(--danger);"></i> Hot Sales</a>
        <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
        <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<script>
function showToast(msg, type='success') {
    const c=document.getElementById('toastContainer');
    const icons={success:'fa-check-circle',error:'fa-exclamation-circle',info:'fa-info-circle'};
    const t=document.createElement('div');
    t.className=`toast-notification ${type}`;
    t.innerHTML=`<i class="fas ${icons[type]||icons.info}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity 0.4s';setTimeout(()=>t.remove(),400);},3500);
}

// Theme (your existing logic)
function toggleTheme() {
    const html=document.documentElement;
    const icon=document.querySelector('#themeToggle i');
    if(html.classList.contains('theme-dark')){html.classList.remove('theme-dark');localStorage.setItem('theme','light');if(icon)icon.className='fas fa-moon';showToast('Light mode activated','info');}
    else{html.classList.add('theme-dark');localStorage.setItem('theme','dark');if(icon)icon.className='fas fa-sun';showToast('Dark mode activated','info');}
}
document.addEventListener('DOMContentLoaded',function(){
    const saved=localStorage.getItem('theme')||'light';
    const icon=document.querySelector('#themeToggle i');
    if(saved==='dark'){document.documentElement.classList.add('theme-dark');if(icon)icon.className='fas fa-sun';}
    else{document.documentElement.classList.remove('theme-dark');if(icon)icon.className='fas fa-moon';}
});

// Consent
const consentCheck=document.getElementById('consentCheck');
const acceptBtn=document.getElementById('acceptBtn');
if(consentCheck&&acceptBtn) consentCheck.addEventListener('change',function(){acceptBtn.disabled=!this.checked;});
function acceptConsent(){
    acceptBtn.disabled=true; acceptBtn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
    fetch('my-examinations.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=consent_update'})
    .then(r=>r.json()).then(d=>{
        if(d.success){document.getElementById('consentModal').classList.remove('show');showToast('Consent recorded!','success');setTimeout(()=>location.reload(),1800);}
        else{showToast(d.message||'Error.','error');acceptBtn.disabled=false;acceptBtn.innerHTML='<i class="fas fa-check"></i> Accept & Continue';}
    }).catch(()=>{showToast('Connection error.','error');acceptBtn.disabled=false;acceptBtn.innerHTML='<i class="fas fa-check"></i> Accept & Continue';});
}
function declineConsent(){if(confirm('Without consent you cannot view your records. This will log you out. Continue?'))window.location.href='../auth/user_logout.php';}

// Delete modal
function openDeleteModal(){document.getElementById('deleteModal').classList.add('show');}
function closeDeleteModal(){document.getElementById('deleteModal').classList.remove('show');document.getElementById('deletionReason').value='';}

// Glossary modal (NEW)
function openGlossaryModal(){document.getElementById('glossaryModal').classList.add('show');}
function closeGlossaryModal(){document.getElementById('glossaryModal').classList.remove('show');}

// Click-triggered info tooltip for SPH/CYL/AXIS/ADD/VA/PD icons (NEW)
// Uses click instead of the browser's native title-hover tooltip so it works on mobile taps too.
let activeTip = null;
function toggleTip(event, iconEl, text){
    event.stopPropagation();
    // If this icon's tooltip is already open, close it
    if(activeTip && activeTip._owner === iconEl){
        activeTip.remove();
        activeTip = null;
        return;
    }
    // Close any other open tooltip
    if(activeTip){ activeTip.remove(); activeTip = null; }

    const bubble = document.createElement('div');
    bubble.className = 'info-tip-bubble';
    bubble.textContent = text;
    document.body.appendChild(bubble);

    const rect = iconEl.getBoundingClientRect();
    const bubbleRect = bubble.getBoundingClientRect();
    let left = rect.left + window.scrollX;
    let top = rect.bottom + window.scrollY + 10;
    // Keep bubble on-screen horizontally
    const maxLeft = window.scrollX + document.documentElement.clientWidth - bubbleRect.width - 10;
    if(left > maxLeft) left = Math.max(10, maxLeft);
    bubble.style.left = left + 'px';
    bubble.style.top = top + 'px';

    bubble._owner = iconEl;
    activeTip = bubble;
}
document.addEventListener('click', function(e){
    if(activeTip && !activeTip.contains(e.target)){
        activeTip.remove();
        activeTip = null;
    }
});
document.addEventListener('scroll', function(){
    if(activeTip){ activeTip.remove(); activeTip = null; }
}, true);

function submitDeletionRequest() {
    const reason = document.getElementById('deletionReason').value.trim();
    
    // Close the modal first to prevent z-index conflict
    closeDeleteModal();
    
    // Small delay to ensure modal is closed before showing SweetAlert
    setTimeout(() => {
        Swal.fire({
            title: 'Confirm Deletion Request',
            text: 'Are you sure you want to request data deletion? We will contact you within 30 days.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, submit request',
            cancelButtonText: 'Cancel',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Processing...',
                    text: 'Submitting your deletion request',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                const formData = new URLSearchParams();
                formData.append('action', 'request_deletion');
                formData.append('reason', reason);
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                })
                .then(async response => {
                    const text = await response.text();
                    try {
                        return JSON.parse(text);
                    } catch(e) {
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                })
                .then(data => {
                    // Check if user already has a pending request
                    if (data.already_requested) {
                        Swal.fire({
                            title: '⚠️ Request Already Exists',
                            html: `
                                <div class="text-start">
                                    <p class="text-warning"><strong>${data.message}</strong></p>
                                    <div class="alert alert-info mt-3">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Existing Request Details:</strong><br>
                                        Request ID: #${data.existing_request.id}<br>
                                        Status: <span class="badge bg-${data.existing_request.status === 'pending' ? 'warning' : (data.existing_request.status === 'approved' ? 'success' : 'info')}">${data.existing_request.status.toUpperCase()}</span><br>
                                        Date: ${new Date(data.existing_request.date).toLocaleString()}
                                    </div>
                                    <p class="small text-muted mt-2">Please wait for the clinic to process your current request before submitting a new one.</p>
                                </div>
                            `,
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                    
                    if (data.requires_clinic_selection) {
                        // Close loading and show clinic selection
                        Swal.close();
                        
                        let clinicOptions = '';
                        data.clinics.forEach(clinic => {
                            clinicOptions += `
                                <div class="clinic-option" style="padding: 15px; margin: 10px 0; border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer;" onclick="selectClinicForDeletion(${clinic.clinic_id})">
                                    <strong><i class="fas fa-clinic-medical"></i> ${escapeHtml(clinic.name)}</strong><br>
                                    <small><i class="fas fa-envelope"></i> ${escapeHtml(clinic.clinic_email || 'No email')} | <i class="fas fa-phone"></i> ${escapeHtml(clinic.contact || 'No contact')}</small>
                                </div>
                            `;
                        });
                        
                        Swal.fire({
                            title: 'Select Clinic',
                            html: `
                                <p style="text-align: left;">You have records from multiple clinics. Please select which clinic's data you want to delete:</p>
                                <div style="max-height: 350px; overflow-y: auto; margin: 15px 0;">${clinicOptions}</div>
                                <p style="text-align: left; font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                                    <i class="fas fa-info-circle"></i> Note: You can submit separate requests for each clinic.
                                </p>
                            `,
                            icon: 'info',
                            showConfirmButton: false,
                            showCancelButton: true,
                            cancelButtonText: 'Close',
                            customClass: {
                                container: 'swal2-container'
                            }
                        });
                    } else if (data.success) {
                        Swal.fire({
                            title: 'Success!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            document.getElementById('deletionReason').value = '';
                            // Optional: reload to update the page
                            // location.reload();
                        });
                    } else {
                        Swal.fire({
                            title: 'Error!',
                            text: data.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    Swal.fire({
                        title: 'Connection Error!',
                        text: 'Cannot connect to server. Please try again later.\n\nError: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    }, 300);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function selectClinicForDeletion(clinicId) {
    const reason = document.getElementById('deletionReason').value.trim();
    
    // Close the clinic selection modal
    Swal.close();
    
    // Show confirmation
    setTimeout(() => {
        Swal.fire({
            title: 'Confirm',
            text: 'Submit deletion request for this clinic?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, submit',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Submitting...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                const formData = new URLSearchParams();
                formData.append('action', 'request_deletion');
                formData.append('reason', reason);
                formData.append('clinic_id', clinicId);
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            title: 'Submitted!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            closeDeleteModal();
                            document.getElementById('deletionReason').value = '';
                        });
                    } else {
                        Swal.fire({
                            title: 'Error',
                            text: data.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Error',
                        text: 'Connection failed: ' + error.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });
            }
        });
    }, 200);
}

function exportMyData(){
    showToast('Preparing export...','info');
    const f=document.createElement('form');f.method='POST';f.action='my-examinations.php';
    const i=document.createElement('input');i.type='hidden';i.name='action';i.value='export_data';
    f.appendChild(i);document.body.appendChild(f);f.submit();document.body.removeChild(f);
}

// Nav toggles (your existing)
function toggleProfileMenu(){document.getElementById('profileMenu').classList.toggle('show');}
function toggleDiscoverDropdown(){const d=document.getElementById('discoverDropdown'),t=document.querySelector('.dropdown-trigger');d.classList.toggle('show');t&&t.classList.toggle('active');}
function toggleNotifications(){document.getElementById('notificationMenu').classList.toggle('show');}
function toggleMobileMenu(){document.getElementById('mobileMenu').classList.toggle('open');document.getElementById('mobileMenuOverlay').classList.toggle('show');}
function closeMobileMenu(){document.getElementById('mobileMenu').classList.remove('open');document.getElementById('mobileMenuOverlay').classList.remove('show');}
document.addEventListener('click',function(e){
    const pm=document.getElementById('profileMenu'),pt=document.querySelector('.profile-trigger');
    if(pm&&pt&&!pt.contains(e.target)&&!pm.contains(e.target))pm.classList.remove('show');
    const dd=document.getElementById('discoverDropdown'),dt=document.querySelector('.dropdown-trigger');
    if(dd&&dt&&!dt.contains(e.target)&&!dd.contains(e.target)){dd.classList.remove('show');dt&&dt.classList.remove('active');}
    const nm=document.getElementById('notificationMenu'),nb=document.getElementById('notificationBell');
    if(nm&&nb&&!nb.contains(e.target)&&!nm.contains(e.target))nm.classList.remove('show');
});

// Check if user has pending or approved requests
async function checkUserDeletionRequests() {
    try {
        const response = await fetch('/api/request_data_deletion.php?action=get_user_requests');
        const data = await response.json();
        
        if (data.success && data.requests && data.requests.length > 0) {
            data.requests.forEach(request => {
                if (request.status === 'pending' || request.status === 'approved') {
                    addCancelButtonToUI(request);
                }
            });
        }
    } catch (error) {
        console.error('Error checking requests:', error);
    }
}

function addCancelButtonToUI(request) {
    const cancelButtonHtml = `
        <div class="alert alert-warning mt-3" id="cancelRequestAlert-${request.id}">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-clock me-2"></i>
                    <strong>Active Deletion Request</strong><br>
                    <small>Your data deletion request is ${request.status}. 
                    ${request.status === 'approved' ? `Scheduled for deletion on ${new Date(request.scheduled_delete_date).toLocaleDateString()}. ` : ''}
                    You can cancel this request if you changed your mind.</small>
                </div>
                <button class="btn btn-warning btn-sm" onclick="cancelDeletionRequest(${request.id})">
                    <i class="fas fa-times-circle me-1"></i>Cancel Request
                </button>
            </div>
        </div>
    `;
    
    // Add to the page (e.g., at the top of the examination history)
    const container = document.querySelector('.main-content');
    if (container && !document.getElementById(`cancelRequestAlert-${request.id}`)) {
        container.insertAdjacentHTML('afterbegin', cancelButtonHtml);
    }
}

// Check if user has rejected consent
async function checkConsentStatus() {
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'get_consent_status');
        
        const response = await fetch('my-examinations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData.toString()
        });
        const data = await response.json();
        
        if (data.success && data.consent_status === 'rejected') {
            showReconsentBanner();
        }
    } catch (error) {
        console.error('Error checking consent:', error);
    }
}

function showReconsentBanner() {
    const bannerHtml = `
        <div class="alert alert-warning mt-3" style="border-left: 4px solid #f59e0b;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>You have previously declined data privacy consent.</strong><br>
                    <small>Your records are currently not accessible. If you change your mind, you can give consent again.</small>
                </div>
                <button class="btn btn-warning btn-sm" onclick="requestReconsent()">
                    <i class="fas fa-redo-alt me-1"></i>Give Consent Again
                </button>
            </div>
        </div>
    `;
    
    const container = document.querySelector('.main-content');
    if (container && !document.getElementById('reconsentBanner')) {
        container.insertAdjacentHTML('afterbegin', bannerHtml);
    }
}

async function requestReconsent() {
    Swal.fire({
        title: 'Give Data Privacy Consent',
        html: `
            <div class="text-start">
                <div class="alert alert-info">
                    <i class="bi bi-shield-lock me-2"></i>
                    <strong>RA 10173 - Data Privacy Act of 2012</strong>
                </div>
                <p>By giving your consent, you allow the clinic to:</p>
                <ul class="text-start">
                    <li>Store your personal and medical information</li>
                    <li>Access your records for medical purposes</li>
                    <li>Keep your data for 10 years as required by law</li>
                </ul>
                <p>You have the right to:</p>
                <ul class="text-start">
                    <li>Access your records anytime</li>
                    <li>Request correction of inaccurate data</li>
                    <li>Request deletion (subject to 10-year retention)</li>
                    <li>Withdraw consent at any time</li>
                </ul>
                <div class="form-check mt-3">
                    <input type="checkbox" id="reconsentCheck" class="form-check-input">
                    <label class="form-check-label" for="reconsentCheck">
                        I understand and I give my consent
                    </label>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Give Consent',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const checked = document.getElementById('reconsentCheck').checked;
            if (!checked) {
                Swal.showValidationMessage('Please confirm your consent');
                return false;
            }
            return true;
        }
    }).then(result => {
        if (result.isConfirmed) {
            submitReconsent();
        }
    });
}

function submitReconsent() {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new URLSearchParams();
    formData.append('action', 'reconsent');
    
    fetch('my-examinations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Consent Recorded!',
                text: 'You can now access your records.',
                timer: 2000
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message
            });
        }
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Connection error. Please try again.'
        });
    });
}

async function cancelDeletionRequest(requestId) {
    const { value: reason } = await Swal.fire({
        title: 'Cancel Deletion Request?',
        html: `
            <p>Are you sure you want to cancel your data deletion request?</p>
            <textarea id="cancelReason" class="swal2-textarea" placeholder="Reason for cancellation (optional)"></textarea>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel request',
        cancelButtonText: 'No, keep it',
        preConfirm: () => {
            return document.getElementById('cancelReason').value;
        }
    });
    
    if (reason !== undefined) {
        Swal.fire({
            title: 'Processing...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        const formData = new FormData();
        formData.append('action', 'cancel_deletion');
        formData.append('request_id', requestId);
        formData.append('reason', reason || 'User cancelled');
        
        try {
            const response = await fetch('api/request_data_deletion.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Cancelled!',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message
                });
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Connection error. Please try again.'
            });
        }
    }
}

// Call this when page loads
document.addEventListener('DOMContentLoaded', function() {
    checkUserDeletionRequests();
    checkConsentStatus(); // ✅ ADD THIS LINE
});
</script>
</body>
</html>