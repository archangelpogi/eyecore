<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get clinic ID and product ID from URL
$clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if (!$clinic_id || !$product_id) {
    header('Location: dashboard.php');
    exit();
}

// Get clinic details
$clinic_query = mysqli_query($conn, "SELECT * FROM clinics WHERE id = $clinic_id");
$clinic = mysqli_fetch_assoc($clinic_query);

// Get specific product details
$product_query = mysqli_query($conn, "SELECT * FROM products WHERE id = $product_id AND clinic_id = $clinic_id");
$product = mysqli_fetch_assoc($product_query);

if (!$clinic || !$product) {
    header('Location: dashboard.php');
    exit();
}

// Get clinic breaks
$breaks_query = mysqli_query($conn, "SELECT * FROM clinic_breaks 
                                      WHERE clinic_id = $clinic_id 
                                      AND is_active = 1 
                                      ORDER BY break_start");
$clinic_breaks = [];
while($break = mysqli_fetch_assoc($breaks_query)) {
    $clinic_breaks[] = $break;
}

// Get doctors from this clinic with their schedules
$doctors_query = mysqli_query($conn, "SELECT * FROM doctors WHERE clinic_id = $clinic_id AND is_active = 1 ORDER BY name");
$doctors_list = [];
while($doc = mysqli_fetch_assoc($doctors_query)) {
    // Parse schedule JSON
    $doc['schedule'] = json_decode($doc['schedule'], true);
    $doctors_list[] = $doc;
}

// Get user details
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get user avatar for sidebar
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Get appointments count for badge
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

// Get unread notification count
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

// Set active navigation for this page
$active_nav = 'discover';

// ============================================
// PRESCRIPTION DATA FROM product-view redirect
// ============================================
$rx_knowledge = isset($_GET['rx_knowledge']) ? $_GET['rx_knowledge'] : '';
$from_lens    = isset($_GET['lens'])         ? $_GET['lens']         : '';
$from_color   = isset($_GET['color'])        ? $_GET['color']        : '';
// ✅ LENS PRICES - PARA MA-ADD SA TOTAL
$lens_prices = [
    'frame_only'      => 0,
    'single_vision'   => 500,
    'progressive'     => 1500,
    'blue_cut'        => 800,
    'contact_daily'   => 0,
    'contact_monthly' => 0,
];
$lens_price = $lens_prices[$from_lens] ?? 0;
$total_product_price = $product['price'] + $lens_price;
$from_od_sph  = isset($_GET['od_sph'])       ? $_GET['od_sph']       : '';
$from_color_name = '';
if (!empty($from_color)) {
    $color_name_query = mysqli_query($conn, "
        SELECT color_name FROM product_color_inventory 
        WHERE product_id = $product_id AND color_code = '" . mysqli_real_escape_string($conn, $from_color) . "'
        LIMIT 1
    ");
    if ($color_row = mysqli_fetch_assoc($color_name_query)) {
        $from_color_name = $color_row['color_name'];
    }
}
$from_od_cyl  = isset($_GET['od_cyl'])       ? $_GET['od_cyl']       : '';
$from_od_axis = isset($_GET['od_axis'])      ? $_GET['od_axis']      : '';
$from_os_sph  = isset($_GET['os_sph'])       ? $_GET['os_sph']       : '';
$from_os_cyl  = isset($_GET['os_cyl'])       ? $_GET['os_cyl']       : '';
$from_os_axis = isset($_GET['os_axis'])      ? $_GET['os_axis']      : '';

$NEEDS_LENS_SELECTION = in_array($product['category'], ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses', 'Contact Lens']);

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    $appointment_date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $appointment_time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $doctor_id = $_POST['doctor_id'] === 'any' ? 'NULL' : (int)$_POST['doctor_id'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number']);

    // Prescription data passed from product-view
    $rx_knowledge_post = mysqli_real_escape_string($conn, $_POST['rx_knowledge'] ?? '');
    $lens_type_post    = mysqli_real_escape_string($conn, $_POST['lens_type']    ?? '');
    $post_od_sph       = mysqli_real_escape_string($conn, $_POST['od_sph']       ?? '');
    $post_od_cyl       = mysqli_real_escape_string($conn, $_POST['od_cyl']       ?? '');
    $post_od_axis      = mysqli_real_escape_string($conn, $_POST['od_axis']      ?? '');
    $post_os_sph       = mysqli_real_escape_string($conn, $_POST['os_sph']       ?? '');
    $post_os_cyl       = mysqli_real_escape_string($conn, $_POST['os_cyl']       ?? '');
    $post_os_axis      = mysqli_real_escape_string($conn, $_POST['os_axis']      ?? '');

    // Append prescription info to notes
    if ($rx_knowledge_post === 'dont_know') {
        $notes .= ($notes ? ' | ' : '') . 'Eye exam needed before lens fitting.';
    } elseif ($rx_knowledge_post === 'know' && ($post_od_sph || $post_os_sph)) {
        $notes .= ($notes ? ' | ' : '') . "Prescription — OD: SPH {$post_od_sph}, CYL {$post_od_cyl}, AXIS {$post_od_axis} | OS: SPH {$post_os_sph}, CYL {$post_os_cyl}, AXIS {$post_os_axis}";
        if ($lens_type_post) $notes .= " | Lens: {$lens_type_post}";
    }
    
    // Validate all fields
    if (empty($appointment_date) || empty($appointment_time) || empty($contact_number)) {
        $error_message = 'Please fill in all required fields';
    } else {
        // ============================================
        // SIMPLE VALIDATION
        // ============================================

        // 0. ✅ SERVER-SIDE: Bawal mag-book ng petsa/oras na nasa nakaraan na
        //    (kahit ma-bypass ang JS validation, hindi papasa dito)
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
        $now_server = new DateTime('now', $tz);
        $appointment_datetime_str = $appointment_date . ' ' . $appointment_time;
        $appointment_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $appointment_datetime_str, $tz);

        if (!$appointment_datetime) {
            $error_message = 'Invalid date or time format.';
        } elseif ($appointment_datetime < $now_server) {
            $error_message = 'You cannot book an appointment in the past. Please select a valid date and time.';
        }
        
        // 1. Check if user already has appointment at this exact date and time (kahit ibang clinic)
        if (empty($error_message)) {
        $conflict_query = mysqli_query($conn, "
            SELECT a.*, c.name as clinic_name 
            FROM appointments a
            JOIN clinics c ON a.clinic_id = c.id
            WHERE a.user_id = $user_id 
            AND a.appointment_date = '$appointment_date' 
            AND a.appointment_time = '$appointment_time'
            AND a.status != 'cancelled'
        ");
        
        if (mysqli_num_rows($conflict_query) > 0) {
            $conflict = mysqli_fetch_assoc($conflict_query);
            $formatted_time = date('g:i A', strtotime($appointment_time));
            $formatted_date = date('F j, Y', strtotime($appointment_date));
            $error_message = "You already have an appointment at {$conflict['clinic_name']} on {$formatted_date} at {$formatted_time}. Please choose another time.";
        }
        }
        
        // 2. Check daily limit (max 3 appointments per day)
        if (empty($error_message)) {
            $daily_count_query = mysqli_query($conn, "
                SELECT COUNT(*) as total 
                FROM appointments 
                WHERE user_id = $user_id 
                AND appointment_date = '$appointment_date'
                AND status != 'cancelled'
            ");
            $daily_count = mysqli_fetch_assoc($daily_count_query)['total'];
            
            if ($daily_count >= 3) {
                $error_message = 'You can only book up to 3 appointments per day. Please choose another date.';
            }
        }
        
        // 3. Check if slot is already taken sa clinic na ito
        if (empty($error_message)) {
            $slot_taken_query = mysqli_query($conn, "
                SELECT id FROM appointments 
                WHERE clinic_id = $clinic_id 
                AND appointment_date = '$appointment_date' 
                AND appointment_time = '$appointment_time'
                AND status != 'cancelled'
            ");
            
            if (mysqli_num_rows($slot_taken_query) > 0) {
                $error_message = 'This time slot is already taken. Please choose another time.';
            }
        }
        
        // 4. If specific doctor selected, check if doctor is available on that date and time
        if (empty($error_message) && $doctor_id !== 'NULL') {
            // Get doctor's schedule
            $doctor_query = mysqli_query($conn, "SELECT schedule FROM doctors WHERE id = $doctor_id");
            $doctor = mysqli_fetch_assoc($doctor_query);
            $schedule = json_decode($doctor['schedule'], true);
            
            // Get day of week (3-letter format: Mon, Tue, etc.)
            $day_of_week = strtolower(date('D', strtotime($appointment_date)));
            
            // Check if doctor works on that day
            if (!isset($schedule[$day_of_week])) {
                $error_message = 'Selected doctor is not available on this date. Please choose another doctor or date.';
            } else {
                // Parse the selected time
                $time_parts = explode(':', $appointment_time);
                $selected_hour = (int)$time_parts[0];
                $selected_min = (int)$time_parts[1];
                $selected_time_mins = $selected_hour * 60 + $selected_min;
                
                // Check if time is within doctor's schedule for that day
                $is_valid_time = false;
                
                foreach ($schedule[$day_of_week] as $time_range) {
                    // Parse time range like "09:00-12:00"
                    $range_parts = explode('-', $time_range);
                    $start_time = $range_parts[0];
                    $end_time = $range_parts[1];
                    
                    $start_parts = explode(':', $start_time);
                    $end_parts = explode(':', $end_time);
                    
                    $start_hour = (int)$start_parts[0];
                    $start_min = (int)($start_parts[1] ?? 0);
                    $start_mins = $start_hour * 60 + $start_min;
                    
                    $end_hour = (int)$end_parts[0];
                    $end_min = (int)($end_parts[1] ?? 0);
                    $end_mins = $end_hour * 60 + $end_min;
                    
                    if ($selected_time_mins >= $start_mins && $selected_time_mins < $end_mins) {
                        $is_valid_time = true;
                        break;
                    }
                }
                
                if (!$is_valid_time) {
                    $error_message = 'Selected time is outside doctor\'s working hours. Please choose another time.';
                }
            }
        }
        
        // If no errors, proceed with booking
        if (empty($error_message)) {
            // ✅ STEP 1: Check kung may existing patient na ang user na ito
            $existingPatientId = null;

            // 1.1 Search sa patients table by email or contact
            $userEmail   = mysqli_real_escape_string($conn, trim($user['email']   ?? ''));
            $userContact = mysqli_real_escape_string($conn, trim($user['contact'] ?? ''));

            if ($userEmail !== '' || $userContact !== '') {
                $conditions = [];
                if ($userEmail   !== '') $conditions[] = "email = '$userEmail'";
                if ($userContact !== '') $conditions[] = "phone = '$userContact'";
                $whereClause = implode(' OR ', $conditions);

                $patientCheck = mysqli_query($conn, "
                    SELECT id FROM patients 
                    WHERE ($whereClause)
                    ORDER BY id ASC
                    LIMIT 1
                ");

                if ($patientCheck && mysqli_num_rows($patientCheck) > 0) {
                    $existingPatient   = mysqli_fetch_assoc($patientCheck);
                    $existingPatientId = (int)$existingPatient['id'];
                }
            }

            // 1.2 Last resort: check previous appointments ng same user
            if (!$existingPatientId) {
                $prevApptCheck = mysqli_query($conn, "
                    SELECT patient_id FROM appointments 
                    WHERE user_id = $user_id 
                      AND patient_id IS NOT NULL 
                      AND patient_id > 0
                    ORDER BY id DESC 
                    LIMIT 1
                ");
                if ($prevApptCheck && mysqli_num_rows($prevApptCheck) > 0) {
                    $prevAppt = mysqli_fetch_assoc($prevApptCheck);
                    if (!empty($prevAppt['patient_id'])) {
                        $existingPatientId = (int)$prevAppt['patient_id'];
                    }
                }
            }

            // ✅ STEP 2: Determine if new patient
            $isNewPatient = ($existingPatientId && $existingPatientId > 0) ? 0 : 1;
            
            // ✅ STEP 3: Generate reference number
            $ref_no = 'APP-' . time() . '-' . rand(1000, 9999);
            
            // ✅ STEP 4: Insert appointment with ALL fields including payment fields
            if ($doctor_id === 'NULL') {
                $insert_query = "INSERT INTO appointments 
                                (ref_no, user_id, patient_id, clinic_id, product_id, doctor_id, 
                                 appointment_date, appointment_time, notes, status, is_new_patient, 
                                 color_code, color_name, lens_type, contact_number,
                                 total_amount, subtotal, amount_paid, payment_status, payment_type,
                                 created_at) 
                                VALUES ('$ref_no', $user_id, " . ($existingPatientId ?: 'NULL') . ", $clinic_id, $product_id, NULL, 
                                '$appointment_date', '$appointment_time', '$notes', 'pending', $isNewPatient, 
                                '" . mysqli_real_escape_string($conn, $from_color) . "', 
                                '" . mysqli_real_escape_string($conn, $from_color_name) . "',
                                '" . mysqli_real_escape_string($conn, $from_lens) . "',
                                '$contact_number',
                                0, 0, 0, 'pending', 'full',
                                NOW())";
            } else {
                $insert_query = "INSERT INTO appointments 
                                (ref_no, user_id, patient_id, clinic_id, product_id, doctor_id, 
                                 appointment_date, appointment_time, notes, status, is_new_patient, 
                                 color_code, color_name, lens_type, contact_number,
                                 total_amount, subtotal, amount_paid, payment_status, payment_type,
                                 created_at) 
                                VALUES ('$ref_no', $user_id, " . ($existingPatientId ?: 'NULL') . ", $clinic_id, $product_id, $doctor_id, 
                                '$appointment_date', '$appointment_time', '$notes', 'pending', $isNewPatient, 
                                '" . mysqli_real_escape_string($conn, $from_color) . "', 
                                '" . mysqli_real_escape_string($conn, $from_color_name) . "',
                                '" . mysqli_real_escape_string($conn, $from_lens) . "',
                                '$contact_number',
                                0, 0, 0, 'pending', 'full',
                                NOW())";
            }
            
            if (mysqli_query($conn, $insert_query)) {
                $new_appointment_id = mysqli_insert_id($conn);
                
                // ============================================
                // ✅ CALCULATE PAYMENT
                // ============================================
                $payment_info = calculatePaymentAmounts($conn, $clinic_id, $total_product_price);
                $total_amount_calc = $payment_info['total_amount'];
                $downpayment_amount_calc = $payment_info['downpayment_amount'];
                $balance_amount_calc = $payment_info['balance_amount'];
                $payment_type_calc = $payment_info['payment_type'];
                $booking_flow_calc = $payment_info['booking_flow'] ?? 'approve_first';
                $requires_payment_calc = $payment_info['requires_payment'];
                
                // ✅ STEP 5: Update appointment with payment info
                $update_payment = mysqli_query($conn, "
                    UPDATE appointments 
                    SET total_amount = $total_amount_calc,
                        downpayment_amount = $downpayment_amount_calc,
                        balance_amount = $balance_amount_calc,
                        payment_type = '$payment_type_calc',
                        amount_paid = 0,
                        subtotal = $total_product_price,
                        payment_status = 'pending'
                    WHERE id = $new_appointment_id
                ");
                
                // Add notification
                $formatted_date = date('F j, Y', strtotime($appointment_date));
                $formatted_time = date('g:i A', strtotime($appointment_time));
                $doctor_text = ($doctor_id === 'NULL') ? 'any available doctor' : 'Dr. ' . $_POST['doctor_name'];
                
                if (function_exists('addNotification')) {
                    addNotification(
                        $user_id,
                        'appointment',
                        'New Appointment Booked',
                        "Your appointment at {$clinic['name']} for {$product['name']} with {$doctor_text} on {$formatted_date} at {$formatted_time} is pending confirmation.",
                        'my-appointments.php'
                    );
                }
                
                // CHECK PAYMENT POLICY AT BOOKING FLOW NG CLINIC
                if ($requires_payment_calc) {
                    
                    if ($booking_flow_calc === 'pay_first') {
                        // 💳 PAY FIRST: Redirect to payment immediately
                        header('Location: payment.php?appointment_id=' . $new_appointment_id);
                        exit();
                    } else {
                        // ✅ APPROVE FIRST: Show success message, wait for clinic approval
                        $success_message = 'Appointment booked successfully! Please wait for clinic approval before making payment.';
                        $_POST = array();
                    }
                    
                } else {
                    // Walang bayad (free o pay on-site) — show success
                    $success_message = 'Appointment booked successfully!';
                    $_POST = array();
                }
            } else {
                $error_message = 'Error booking appointment: ' . mysqli_error($conn);
            }
        }
    }
}

// Get clinic hours
$clinic_hours = $clinic['hours'];

$min_date = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+30 days'));

// Get all booked slots for this clinic for JavaScript
$booked_slots_query = mysqli_query($conn, "
    SELECT appointment_date, appointment_time
    FROM appointments 
    WHERE clinic_id = $clinic_id 
    AND appointment_date >= '$min_date'
    AND appointment_date <= '$max_date'
    AND status != 'cancelled'
");
$booked_slots = [];
while ($row = mysqli_fetch_assoc($booked_slots_query)) {
    $booked_slots[] = $row;
}
if (isset($_POST['action']) && $_POST['action'] === 'get_cart_total') {
    $clinic_id = $_POST['clinic_id'] ?? null;
    $product_ids = $_POST['product_ids'] ?? [];
    
    $total = 0;
    foreach ($product_ids as $id) {
        $stmt = $pdo->prepare("
            SELECT price FROM products 
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$id, $clinic_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $total += $product['price'] ?? 0;
    }
    
    $payment_info = calculatePaymentAmounts($conn, $clinic_id, $total_product_price);
    
    echo json_encode([
        'success' => true,
        'total_amount' => $total,
        'payment' => $payment_info
    ]);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Book <?php echo $product['name']; ?> - <?php echo $clinic['name']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== RESET AND BASE STYLES ===== */
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
            
            --input-bg: var(--bg-primary);
            --input-border: var(--border-color);
            --input-focus: var(--primary);
            --disabled-bg: var(--bg-secondary);
            
            --break-bg: #fff3cd;
            --break-color: #856404;
            --booked-bg: #f8d7da;
            --booked-color: #721c24;
            
            --unavailable-bg: #f8d7da;
            --unavailable-color: #721c24;
            
            --calendar-available: #d4edda;
            --calendar-unavailable: #f8d7da;
            --calendar-partial: #fff3cd;
            --calendar-today: #cce5ff;
            --calendar-selected: var(--primary-gradient);
            --calendar-border: var(--border-color);
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
            
            --input-bg: #1E1E1E;
            --input-border: #2D2D2D;
            --input-focus: #00E676;
            --disabled-bg: #1A1A1A;
            
            --break-bg: #5a4c2d;
            --break-color: #ffd966;
            --booked-bg: #5a2d2d;
            --booked-color: #ff9999;
            
            --unavailable-bg: #5a2d2d;
            --unavailable-color: #ff9999;
            
            --calendar-available: #1e4a1e;
            --calendar-unavailable: #4a2d2d;
            --calendar-partial: #5a4c2d;
            --calendar-today: #1e4a5a;
            --calendar-selected: var(--primary-gradient);
            --calendar-border: #2D2D2D;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

        /* ===== LOADING OVERLAY ===== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .loading-spinner-large {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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

        /* ===== FLOATING ACTION BUTTON ===== */
        .fab {
            position: fixed;
            bottom: 100px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 98;
            border: none;
        }

        .fab:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 15px 30px rgba(0,183,97,0.4);
        }

        .fab.active {
            transform: rotate(45deg);
            background: var(--danger);
        }

        .fab-menu {
            position: fixed;
            bottom: 180px;
            right: 20px;
            display: none;
            flex-direction: column;
            gap: 10px;
            z-index: 97;
        }

        .fab-menu.show {
            display: flex;
            animation: slideIn 0.2s ease;
        }

        .fab-menu-item {
            width: 50px;
            height: 50px;
            background: var(--bg-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            text-decoration: none;
            box-shadow: var(--shadow-md);
            transition: all 0.2s;
            font-size: 20px;
            border: 1px solid var(--border-light);
        }

        .fab-menu-item:hover {
            transform: scale(1.1);
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        @media (max-width: 768px) {
            .fab {
                bottom: 90px;
            }
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

        /* ===== BACK BUTTON ===== */
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

        .back-button a:hover {
            background: var(--primary);
            color: white;
            transform: translateX(-3px);
        }

        .back-button span {
            color: var(--text-secondary);
            font-size: 14px;
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

        .product-badge {
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

        .product-badge i {
            color: var(--primary);
        }

        .product-badge span {
            font-weight: 600;
            color: var(--primary);
            margin-right: 4px;
        }

        /* ===== BOOKING GRID ===== */
        .booking-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }

        /* Booking Main */
        .booking-main {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        /* Product Preview */
        .product-preview {
            display: flex;
            gap: 25px;
            padding: 20px;
            background: var(--primary-light);
            border: 2px solid var(--primary);
            border-radius: var(--radius-lg);
            margin-bottom: 30px;
            align-items: center;
        }

        .product-preview-image {
            width: 100px;
            height: 100px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 3px solid white;
            flex-shrink: 0;
        }

        .product-preview-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-preview-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            color: var(--primary);
        }

        .product-preview-image .placeholder i {
            font-size: 30px;
            margin-bottom: 5px;
        }

        .product-preview-image .placeholder span {
            font-size: 10px;
            color: var(--text-muted);
        }

        .product-preview-details {
            flex: 1;
        }

        .product-preview-category {
            font-size: 13px;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .product-preview-name {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .product-preview-price {
            font-size: 24px;
            font-weight: 700;
            color: var(--success);
        }

        .product-preview-badge {
            background: var(--success);
            color: white;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-top: 8px;
        }

        /* Progress Steps */
        .booking-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            padding: 15px;
            border: 1px solid var(--border-light);
        }

        .booking-progress::before {
            content: '';
            position: absolute;
            top: 35px;
            left: 60px;
            right: 60px;
            height: 2px;
            background: var(--border-color);
            z-index: 1;
        }

        .progress-step {
            position: relative;
            z-index: 2;
            background: var(--bg-primary);
            padding: 0 10px;
            text-align: center;
            flex: 1;
            cursor: pointer;
        }

        .progress-step.disabled {
            cursor: not-allowed;
            opacity: 0.5;
            pointer-events: none;
        }

        .step-number {
            width: 50px;
            height: 50px;
            background: var(--bg-secondary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 700;
            color: var(--text-secondary);
            transition: all 0.2s;
            border: 2px solid var(--border-light);
        }

        .progress-step.active .step-number {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .progress-step.completed .step-number {
            background: var(--success);
            color: white;
            border-color: transparent;
        }

        .step-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .progress-step.active .step-label {
            color: var(--primary);
        }

        .progress-step.completed .step-label {
            color: var(--success);
        }

        /* Step Sections */
        .step-section {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }

        .step-section.active {
            border-left: 4px solid var(--primary);
        }

        .step-section.completed {
            border-left: 4px solid var(--success);
        }

        .step-section.locked {
            opacity: 0.7;
            background: var(--disabled-bg);
        }

        .step-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .step-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
            flex: 1;
        }

        .step-header h3 i {
            color: var(--primary);
            margin-right: 8px;
        }

        .step-status-badge {
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
        }

        .status-pending {
            background: var(--warning);
            color: white;
        }

        .status-completed {
            background: var(--success);
            color: white;
        }

        .status-locked {
            background: var(--text-muted);
            color: white;
        }

        .disabled-content {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Doctors Grid */
        .doctors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .doctor-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 15px;
            transition: all 0.2s;
            border: 2px solid transparent;
            cursor: pointer;
            position: relative;
            text-align: center;
            border: 1px solid var(--border-light);
        }

        .doctor-card:hover:not(.unavailable) {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .doctor-card.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .doctor-card.unavailable {
            opacity: 0.5;
            pointer-events: none;
            background: var(--unavailable-bg);
            border-color: var(--border-color);
            position: relative;
            cursor: not-allowed;
        }

        .doctor-card.unavailable::after {
            content: 'Not available this day';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px;
            border-radius: 0 0 var(--radius-md) var(--radius-md);
        }

        .doctor-card.no-schedule {
            opacity: 0.7;
            border-color: #999 !important;
            cursor: not-allowed;
            background: var(--bg-secondary);
        }

        .doctor-card.no-schedule .doctor-avatar {
            background: #999 !important;
        }

        .doctor-card.no-schedule .doctor-schedule-badge {
            background: #999 !important;
            color: white !important;
        }

        .doctor-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .doctor-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: white;
            font-size: 28px;
        }

        .any-doctor-card .doctor-avatar {
            background: var(--success);
        }

        .doctor-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .doctor-specialty {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .doctor-schedule-badge {
            display: inline-block;
            padding: 4px 10px;
            background: var(--bg-primary);
            color: var(--primary);
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 600;
        }

        .any-doctor-card .doctor-schedule-badge {
            background: var(--success);
            color: white;
        }

        /* Calendar Styles */
        .calendar-container {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-top: 15px;
            border: 1px solid var(--border-light);
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-header h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .calendar-nav-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--bg-primary);
            border-radius: var(--radius-full);
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s;
            font-size: 14px;
        }

        .calendar-nav-btn:hover {
            background: var(--primary);
            color: white;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 600;
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            font-size: 14px;
            font-weight: 500;
            border: 2px solid transparent;
        }

        .calendar-day:hover:not(.empty):not(.unavailable) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary);
        }

        .calendar-day.available {
            background: var(--calendar-available);
            color: var(--success);
            border: 2px solid var(--success);
        }

        .calendar-day.unavailable {
            background: var(--calendar-unavailable);
            color: var(--danger);
            opacity: 0.5;
            cursor: not-allowed;
            border: 2px solid var(--danger);
        }

        .calendar-day.partial {
            background: var(--calendar-partial);
            color: var(--warning);
            border: 2px solid var(--warning);
        }

        .calendar-day.today {
            border: 2px solid var(--info);
            background: var(--calendar-today);
            color: var(--info);
        }

        .calendar-day.selected {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .calendar-day.empty {
            background: transparent;
            cursor: default;
            border: none;
        }

        /* Time Slots */
        .time-slots-container {
            margin-top: 20px;
        }

        .time-slots-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .time-slots-header h4 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .time-slots-header span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
        }

        .time-slot-card {
            padding: 12px 8px;
            text-align: center;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            font-weight: 500;
            position: relative;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .time-slot-card:hover:not(.disabled):not(.booked):not(.unavailable) {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .time-slot-card.selected {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        .time-slot-card.available {
            border-color: var(--success);
        }

        .time-slot-card.disabled {
            background: var(--break-bg);
            color: var(--break-color);
            border-color: var(--border-color);
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.7;
        }

        .time-slot-card.booked {
            background: var(--booked-bg);
            color: var(--booked-color);
            border-color: var(--danger);
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.7;
        }

        .time-slot-card.unavailable {
            background: var(--unavailable-bg);
            color: var(--unavailable-color);
            border-color: var(--danger);
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.7;
        }

        .no-slots-message {
            grid-column: 1 / -1;
            text-align: center;
            padding: 30px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            color: var(--text-muted);
        }

        .no-slots-message i {
            font-size: 40px;
            color: var(--text-muted);
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .no-slots-message h4 {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .no-slots-message p {
            font-size: 13px;
        }

        /* Calendar Legend */
        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-light);
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-color.available { background: var(--calendar-available); border: 2px solid var(--success); }
        .legend-color.partial { background: var(--calendar-partial); border: 2px solid var(--warning); }
        .legend-color.unavailable { background: var(--calendar-unavailable); border: 2px solid var(--danger); }
        .legend-color.today { background: var(--calendar-today); border: 2px solid var(--info); }
        .legend-color.selected { background: var(--primary-gradient); }

        /* Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

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
            background: var(--input-bg);
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
        }

        /* Alerts */
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

        /* Validation Message */
        .validation-message {
            margin-top: 8px;
            padding: 8px 12px;
            background: var(--unavailable-bg);
            color: var(--unavailable-color);
            border-radius: var(--radius-md);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-left: 4px solid var(--danger);
        }

        /* Break Info */
        .break-info {
            background: var(--break-bg);
            border-left: 4px solid var(--warning);
            padding: 15px;
            border-radius: var(--radius-md);
            margin: 20px 0;
            font-size: 13px;
        }

        .break-info i {
            color: var(--warning);
            margin-right: 8px;
        }

        .break-info ul {
            margin-top: 10px;
            padding-left: 25px;
            color: var(--break-color);
        }

        .break-info li {
            margin-bottom: 5px;
        }

        /* Daily Limit Warning */
        .daily-limit-warning {
            background: var(--break-bg);
            color: var(--break-color);
            padding: 10px 15px;
            border-radius: var(--radius-md);
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            border-left: 4px solid var(--warning);
        }

        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid var(--border-color);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* ===== BOOKING SIDEBAR ===== */
        .booking-sidebar {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .clinic-info-sidebar {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .clinic-info-sidebar > i:first-child {
            font-size: 40px;
            color: var(--primary);
            background: var(--primary-light);
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .clinic-info-sidebar h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .clinic-info-sidebar p {
            color: var(--text-secondary);
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .clinic-info-sidebar p i {
            font-size: 12px;
            color: var(--primary);
            width: 16px;
        }

        /* Flow Indicator */
        .flow-indicator {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-bottom: 20px;
        }

        /* Summary Card */
        .summary-card {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 20px 0;
        }

        .summary-card h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-card h4 i {
            color: var(--primary);
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
            font-size: 13px;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .summary-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        .total-price {
            font-size: 24px;
            font-weight: 700;
            color: var(--success);
            margin-top: 15px;
            text-align: right;
        }

        /* Confirm Button */
        .btn-confirm {
            width: 100%;
            padding: 15px;
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
            gap: 10px;
            margin-top: 20px;
        }

        .btn-confirm:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-confirm:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Quick Info */
        .quick-info {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }

        .quick-info p {
            color: var(--text-secondary);
            font-size: 12px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-info i {
            color: var(--primary);
            width: 18px;
            font-size: 12px;
        }

        /* ===== SUCCESS PAGE ===== */
        .success-container {
            grid-column: 1 / -1;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 60px 40px;
            text-align: center;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .success-container i {
            font-size: 80px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .success-container h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .success-container p {
            color: var(--text-secondary);
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .success-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            padding: 12px 30px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-secondary {
            padding: 12px 30px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: var(--radius-full);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary:hover,
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Info Card */
        .info-card {
            grid-column: 1 / -1;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            margin-top: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .info-card h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card h2 i {
            color: var(--primary);
        }

        .info-card ul {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.8;
            padding-left: 20px;
        }

        .info-card li {
            margin-bottom: 8px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .booking-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .booking-progress::before {
                left: 30px;
                right: 30px;
            }
            
            .product-preview {
                flex-direction: column;
                text-align: center;
            }
            
            .product-preview-image {
                width: 120px;
                height: 120px;
                margin: 0 auto;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .doctors-grid {
                grid-template-columns: 1fr;
            }
            
            .clinic-info-sidebar {
                flex-direction: column;
                text-align: center;
            }
            
            .clinic-info-sidebar p {
                justify-content: center;
            }
            
            .calendar-days {
                gap: 3px;
            }
            
            .calendar-day {
                font-size: 12px;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .success-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <?php
    // Define variables for the shared navbar
    $user = $user;
    $user_data = $user_data;
    $unread_count = $unread_count;
    $recent_notifications = $recent_notifications;
    $total_points = $total_points;
    $total_bookings = $total_bookings;
    $pending = $pending;
    $sale_count = $sale_count;
    $active_nav = 'discover';
    
    include '../includes/navbar.php';
    ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-plus"></i>
                Book Specific Service
            </h1>
            <div class="product-badge">
                <i class="fas fa-box"></i> <span><?php echo $product['name']; ?></span>
            </div>
        </div>

        <!-- Back Button -->
        <div class="back-button">
            <a href="javascript:history.back()">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <span><?php echo $clinic['name']; ?></span>
        </div>

        <?php if ($success_message): ?>
            <!-- Success Page -->
            <div class="booking-grid">
                <div class="success-container">
                    <i class="fas fa-check-circle"></i>
                    <h2>Booking Confirmed!</h2>
                    <p>Your appointment for <strong><?php echo $product['name']; ?></strong> has been successfully scheduled.</p>
                    <div class="success-actions">
                        <a href="my-appointments.php" class="btn-primary">
                            <i class="fas fa-calendar-check"></i> View Appointments
                        </a>
                        <a href="dashboard.php" class="btn-secondary">
                            <i class="fas fa-home"></i> Back to Home
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>

        <!-- Main Content Grid -->
        <div class="booking-grid">
            <!-- LEFT COLUMN - Booking Form -->
            <div class="booking-main">
                <!-- Product Preview -->
                <div class="product-preview">
                    <div class="product-preview-image">
                        <?php 
                        // Image handling
                        $imageUrl = '/assets/img/no-image.png';
                        if (!empty($product['images_json'])) {
                            $images = json_decode($product['images_json'], true);
                            if (!empty($images) && isset($images[0])) {
                                $imagePath = str_replace('uploads/uploads/', 'uploads/', $images[0]);
                                if (strpos($imagePath, 'uploads/') === 0) {
                                    $imageUrl = '/' . $imagePath;
                                } else {
                                    $imageUrl = '/uploads/products/' . $imagePath;
                                }
                            }
                        } elseif (!empty($product['images'])) {
                            $imagePath = $product['images'];
                            if (strpos($imagePath, '[') === 0) {
                                $images = json_decode($imagePath, true);
                                if (!empty($images) && isset($images[0])) {
                                    $imagePath = $images[0];
                                }
                            }
                            $imagePath = str_replace('uploads/uploads/', 'uploads/', $imagePath);
                            if (strpos($imagePath, 'uploads/') === 0) {
                                $imageUrl = '/' . $imagePath;
                            } else {
                                $imageUrl = '/uploads/products/' . $imagePath;
                            }
                        } elseif (!empty($product['image'])) {
                            if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
                                $imageUrl = '/assets/images/products/' . $product['image'];
                            } else {
                                $imagePath = str_replace('uploads/uploads/', 'uploads/', $product['image']);
                                if (strpos($imagePath, 'uploads/') === 0) {
                                    $imageUrl = '/' . $imagePath;
                                } else {
                                    $imageUrl = '/uploads/products/' . $imagePath;
                                }
                            }
                        }
                        ?>
                        <img src="<?php echo $imageUrl; ?>" 
                             alt="<?php echo $product['name']; ?>"
                             onerror="this.onerror=null; this.src='/assets/img/no-image.png';">
                    </div>
                    <div class="product-preview-details">
                        <div class="product-preview-category"><?php echo $product['category']; ?></div>
                        <div class="product-preview-name"><?php echo $product['name']; ?></div>
                        <div class="product-preview-price">₱<?php echo number_format($product['price'], 2); ?></div>
                        <div class="product-preview-badge">
                            <i class="fas fa-check-circle"></i> Selected Service
                        </div>
                    </div>
                </div>

                <!-- Progress Steps - 3 steps (Doctor, Date/Time, Confirm) -->
                <div class="booking-progress">
                    <div class="progress-step step1 active" onclick="scrollToStep('step1')">
                        <div class="step-number">1</div>
                        <div class="step-label">Choose Doctor</div>
                    </div>
                    <div class="progress-step step2 disabled" onclick="scrollToStep('step2')">
                        <div class="step-number">2</div>
                        <div class="step-label">Date & Time</div>
                    </div>
                    <div class="progress-step step3 disabled" onclick="scrollToStep('step3')">
                        <div class="step-number">3</div>
                        <div class="step-label">Confirm</div>
                    </div>
                </div>

                <!-- Error Message -->
                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="bookingForm">
                    <input type="hidden" name="doctor_name" id="selectedDoctorName" value="">
                    <input type="hidden" name="rx_knowledge" value="<?php echo htmlspecialchars($rx_knowledge); ?>">
                    <input type="hidden" name="lens_type"    value="<?php echo htmlspecialchars($from_lens); ?>">
                    <?php if ($from_color): ?>
                    <input type="hidden" name="color_code"   value="<?php echo htmlspecialchars($from_color); ?>">
                    <?php endif; ?>

                    <?php if ($NEEDS_LENS_SELECTION && $rx_knowledge): ?>
                    <!-- ===== PRESCRIPTION INFO FROM PRODUCT PAGE ===== -->
                    <div class="step-section completed" style="margin-bottom: 20px;">
                        <div class="step-header">
                            <h3><i class="fas fa-glasses"></i> Prescription Details</h3>
                            <?php if ($rx_knowledge === 'dont_know'): ?>
                                <span class="step-status-badge" style="background: var(--warning); color: white;">
                                    <i class="fas fa-stethoscope"></i> Eye Exam Needed
                                </span>
                            <?php else: ?>
                                <span class="step-status-badge status-completed">✓ Provided</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($rx_knowledge === 'dont_know'): ?>
                            <div style="background: var(--primary-light); border-radius: var(--radius-md); padding: 14px 18px; font-size: 14px; color: var(--text-primary); line-height: 1.6;">
                                <i class="fas fa-info-circle" style="color: var(--primary);"></i>
                                You indicated that you <strong>don't know your prescription yet</strong>.<br>
                                The doctor will conduct an <strong>eye exam</strong> during your appointment before fitting your <em><?php echo htmlspecialchars($product['name']); ?></em>.
                            </div>
                        <?php else: ?>
                            <?php if ($from_lens): ?>
                            <div style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <span style="font-size: 13px; color: var(--text-secondary);">Lens Type:</span>
                                <span style="background: var(--primary-light); color: var(--primary); padding: 4px 12px; border-radius: var(--radius-full); font-size: 13px; font-weight: 600;">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $from_lens))); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 14px; border: 1px solid var(--border-light);">
                                    <div style="font-weight: 600; margin-bottom: 10px; font-size: 13px; color: var(--primary);">
                                        <i class="fas fa-eye"></i> Right Eye (OD)
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">SPH</label>
                                            <input type="text" name="od_sph" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_od_sph); ?>">
                                        </div>
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">CYL</label>
                                            <input type="text" name="od_cyl" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_od_cyl); ?>">
                                        </div>
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">AXIS</label>
                                            <input type="text" name="od_axis" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_od_axis); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 14px; border: 1px solid var(--border-light);">
                                    <div style="font-weight: 600; margin-bottom: 10px; font-size: 13px; color: var(--primary);">
                                        <i class="fas fa-eye"></i> Left Eye (OS)
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">SPH</label>
                                            <input type="text" name="os_sph" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_os_sph); ?>">
                                        </div>
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">CYL</label>
                                            <input type="text" name="os_cyl" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_os_cyl); ?>">
                                        </div>
                                        <div>
                                            <label style="font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 2px;">AXIS</label>
                                            <input type="text" name="os_axis" class="form-control" style="font-size: 13px; padding: 6px 8px;"
                                                   value="<?php echo htmlspecialchars($from_os_axis); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                                <i class="fas fa-info-circle"></i> You can edit these values if needed before confirming your booking.
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- STEP 1: Choose Doctor -->
                    <div id="step1" class="step-section active">
                        <div class="step-header">
                            <h3><i class="fas fa-user-md"></i> Choose Your Doctor</h3>
                            <span class="step-status-badge status-pending" id="step1-status">Required</span>
                        </div>
                        
                        <div id="step1-content">
                            <?php if (!empty($doctors_list)): ?>
                            <div class="doctors-grid">
                                <!-- ANY DOCTOR Option -->
                                <label class="doctor-card any-doctor-card" id="anyDoctorCard">
                                    <input type="radio" name="doctor_id" value="any" id="anyDoctor" onchange="handleDoctorSelection(this)" checked>
                                    <div class="doctor-avatar">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="doctor-name">Any Available Doctor</div>
                                    <div class="doctor-specialty">First available doctor</div>
                                    <span class="doctor-schedule-badge">
                                        <i class="fas fa-clock"></i> Auto-assign
                                    </span>
                                </label>
                                
                            <!-- Individual Doctors -->
                            <?php foreach($doctors_list as $doctor): 
                                // Check if doctor has schedule
                                $has_schedule = false;
                                $schedule_text = 'No schedule';
                                
                                if (!empty($doctor['schedule']) && is_array($doctor['schedule'])) {
                                    $days_with_schedule = [];
                                    foreach($doctor['schedule'] as $day => $times) {
                                        if (!empty($times) && is_array($times)) {
                                            $days_with_schedule[] = $day;
                                        }
                                    }
                                    
                                    if (!empty($days_with_schedule)) {
                                        $has_schedule = true;
                                        if (count($days_with_schedule) > 3) {
                                            $schedule_text = count($days_with_schedule) . ' days';
                                        } else {
                                            $schedule_text = implode(', ', array_map('ucfirst', $days_with_schedule));
                                        }
                                    }
                                }
                            ?>
                            <label class="doctor-card <?php echo !$has_schedule ? 'no-schedule' : ''; ?>" 
                                data-doctor-id="<?php echo $doctor['id']; ?>" 
                                data-schedule='<?php echo json_encode($doctor['schedule']); ?>'>
                                <input type="radio" name="doctor_id" value="<?php echo $doctor['id']; ?>" 
                                    onchange="handleDoctorSelection(this)" 
                                    <?php echo !$has_schedule ? 'disabled' : ''; ?>>
                                <div class="doctor-avatar">
                                    <i class="fas fa-user-md"></i>
                                </div>
                                <div class="doctor-name"><?php echo $doctor['name']; ?></div>
                                <div class="doctor-specialty"><?php echo $doctor['specialty'] ?? 'Optometrist'; ?></div>
                                <span class="doctor-schedule-badge" style="<?php echo !$has_schedule ? 'background: #999;' : ''; ?>">
                                    <i class="fas fa-calendar-alt"></i> <?php echo $schedule_text; ?>
                                </span>
                                <?php if (!$has_schedule): ?>
                                    <div style="font-size: 10px; color: #dc3545; margin-top: 5px;">
                                        No schedule set
                                    </div>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="no-slots-message">
                                <i class="fas fa-user-md"></i>
                                <h4>No Doctors Available</h4>
                                <p>This clinic hasn't added any doctors yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- STEP 2: Choose Date & Time -->
                    <div id="step2" class="step-section locked">
                        <div class="step-header">
                            <h3><i class="fas fa-clock"></i> Choose Date & Time</h3>
                            <span class="step-status-badge status-locked" id="step2-status">🔒 Locked</span>
                        </div>
                        
                        <div class="disabled-content" id="step2-content">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Select Date</label>
                                <div class="calendar-container" id="calendarContainer">
                                    <!-- Calendar will be generated here via JavaScript -->
                                </div>
                                <div id="dateValidationMessage" class="validation-message" style="display: none;"></div>
                            </div>
                            
                            <!-- Time Slots Container -->
                            <div id="timeSlotsContainer" class="time-slots-container">
                                <div class="text-center" style="padding: 20px; color: var(--text-muted);">
                                    <i class="fas fa-clock"></i> Select a date to see available time slots
                                </div>
                            </div>
                            
                            <!-- Hidden input for selected date and time -->
                            <input type="hidden" name="appointment_date" id="selectedDate" value="">
                            <input type="hidden" name="appointment_time" id="selectedTime" value="">
                            
                            <!-- Calendar Legend -->
                            <div class="calendar-legend">
                                <div class="legend-item">
                                    <div class="legend-color available"></div>
                                    <span>Fully Available</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color partial"></div>
                                    <span>Partial Slots</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color unavailable"></div>
                                    <span>Not Available</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color today"></div>
                                    <span>Today</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-color selected"></div>
                                    <span>Selected</span>
                                </div>
                            </div>
                            
                            <!-- Daily Limit Warning -->
                            <div id="dailyLimitWarning" class="daily-limit-warning" style="display: none;">
                                <i class="fas fa-exclamation-triangle"></i>
                                You have <span id="dailyCount">0</span> appointment(s) today. Maximum is 3 per day.
                            </div>
                            
                            <!-- Break Information -->
                            <?php if (!empty($clinic_breaks)): ?>
                            <div class="break-info">
                                <i class="fas fa-info-circle"></i> <strong>Break Times:</strong>
                                <ul>
                                    <?php foreach($clinic_breaks as $break): ?>
                                    <li><?php echo $break['break_name']; ?>: <?php echo $break['break_start']; ?> - <?php echo $break['break_end']; ?> (<?php echo $break['break_days']; ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- STEP 3: Confirm Details -->
                    <div id="step3" class="step-section locked">
                        <div class="step-header">
                            <h3><i class="fas fa-check-circle"></i> Confirm Your Details</h3>
                            <span class="step-status-badge status-locked" id="step3-status">🔒 Locked</span>
                        </div>
                        
                        <div class="disabled-content" id="step3-content">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Contact Number *</label>
                                <input type="text" name="contact_number" class="form-control" 
                                       value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ($user['contact'] ?? ''); ?>" 
                                       placeholder="e.g., 0917-123-4567"
                                       oninput="handleContactInput()" disabled>
                            </div>

                            <div class="form-group">
                                <label><i class="fas fa-sticky-note"></i> Additional Notes (Optional)</label>
                                <textarea name="notes" class="form-control" placeholder="Any specific concerns?" disabled><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- RIGHT COLUMN - Sidebar -->
            <div class="booking-sidebar">
                <div class="clinic-info-sidebar">
                    <i class="fas fa-clinic-medical"></i>
                    <div>
                        <h4><?php echo $clinic['name']; ?></h4>
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo $clinic['address']; ?>, <?php echo $clinic['city']; ?></span>
                        </p>
                        <p>
                            <i class="fas fa-phone"></i>
                            <span><?php echo $clinic['contact']; ?></span>
                        </p>
                        <p>
                            <i class="fas fa-clock"></i>
                            <span><?php echo $clinic['hours']; ?></span>
                        </p>
                    </div>
                </div>

                <?php
                // Kunin ang payment info para sa display
                $display_payment = getPaymentDisplayInfo($conn, $clinic_id, $total_product_price);
                $booking_flow = $display_payment['booking_flow'] ?? 'approve_first';
                $payment_type = $display_payment['payment_type'] ?? 'downpayment';
                
                $flow_color = ($booking_flow === 'pay_first') ? 'var(--primary)' : 'var(--warning)';
                $flow_icon = ($booking_flow === 'pay_first') ? 'fa-credit-card' : 'fa-clock';
                $flow_title = ($booking_flow === 'pay_first') ? 'Pay First Booking' : 'Approve First Booking';
                ?>
                
                <div class="flow-indicator" style="border-left: 4px solid <?php echo $flow_color; ?>;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <i class="fas <?php echo $flow_icon; ?>" 
                           style="color: <?php echo $flow_color; ?>; font-size: 20px;"></i>
                        <h5 style="margin: 0; font-weight: 600; color: var(--text-primary);">
                            <?php echo $flow_title; ?>
                        </h5>
                    </div>
                    
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: var(--text-secondary); line-height: 1.5;">
                        <?php if ($booking_flow === 'pay_first'): ?>
                            <i class="fas fa-check-circle" style="color: var(--primary); font-size: 12px;"></i> 
                            You will pay the downpayment <strong>immediately</strong> after booking.<br>
                            <i class="fas fa-check-circle" style="color: var(--primary); font-size: 12px;"></i> 
                            Clinic will review and approve after payment.
                        <?php else: ?>
                            <i class="fas fa-check-circle" style="color: var(--warning); font-size: 12px;"></i> 
                            Clinic will review your appointment first.<br>
                            <i class="fas fa-check-circle" style="color: var(--warning); font-size: 12px;"></i> 
                            You will pay <strong>after</strong> the clinic approves.
                        <?php endif; ?>
                    </p>
                    
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border-color);">
                        
                        <?php if ($payment_type === 'downpayment'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 8px;">
                                <span style="color: var(--text-secondary);">Total Amount:</span>
                                <strong style="color: var(--text-primary);">₱<?php echo number_format($display_payment['total_amount'], 2); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--primary-light); padding: 8px; border-radius: var(--radius-sm);">
                                <span style="font-weight: 600;">Downpayment Due:</span>
                                <strong style="color: var(--primary); font-size: 16px;">₱<?php echo number_format($display_payment['downpayment_amount'], 2); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                <span style="color: var(--text-secondary);">Balance at clinic:</span>
                                <strong style="color: var(--warning);">₱<?php echo number_format($display_payment['balance_amount'], 2); ?></strong>
                            </div>
                            
                        <?php elseif ($payment_type === 'full'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--primary-light); padding: 8px; border-radius: var(--radius-sm);">
                                <span style="font-weight: 600;">Total Amount Due:</span>
                                <strong style="color: var(--primary); font-size: 16px;">₱<?php echo number_format($display_payment['total_amount'], 2); ?></strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                                <i class="fas fa-info-circle"></i> Full payment required
                            </p>
                            
                        <?php elseif ($payment_type === 'onsite'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--bg-secondary); padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                                <span style="font-weight: 600;">Pay at Clinic:</span>
                                <strong style="color: var(--warning); font-size: 16px;">₱<?php echo number_format($display_payment['total_amount'], 2); ?></strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                                <i class="fas fa-store"></i> No online payment required
                            </p>
                            
                        <?php elseif ($payment_type === 'free'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--success); color: white; padding: 8px; border-radius: var(--radius-sm);">
                                <span style="font-weight: 600;">FREE SERVICE</span>
                                <strong>₱0.00</strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                                <i class="fas fa-check-circle"></i> No payment required
                            </p>
                        <?php endif; ?>
                        
                        <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($display_payment['payment_method_online']): ?>
                                <span style="background: var(--bg-secondary); padding: 4px 10px; border-radius: 20px; font-size: 11px; border: 1px solid var(--border-color);">
                                    <i class="fas fa-mobile-alt" style="color: var(--primary);"></i> Online
                                </span>
                            <?php endif; ?>
                            <?php if ($display_payment['payment_method_onsite']): ?>
                                <span style="background: var(--bg-secondary); padding: 4px 10px; border-radius: 20px; font-size: 11px; border: 1px solid var(--border-color);">
                                    <i class="fas fa-cash-register" style="color: var(--warning);"></i> On-Site
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
<!-- Booking Summary -->
<div class="summary-card">
    <h4><i class="fas fa-receipt"></i> Booking Summary</h4>
    <div class="summary-item">
        <span class="summary-label">Service:</span>
        <span class="summary-value"><?php echo $product['name']; ?></span>
    </div>
    <div class="summary-item">
        <span class="summary-label">Price:</span>
        <span class="summary-value">₱<?php echo number_format($product['price'], 2); ?></span>
    </div>
    
    <?php if ($from_lens && $from_lens != 'frame_only'): ?>
    <div class="summary-item">
        <span class="summary-label">Lens Upgrade (<?php echo ucwords(str_replace('_', ' ', $from_lens)); ?>):</span>
        <span class="summary-value">+₱<?php echo number_format($lens_price, 2); ?></span>
    </div>
    <?php endif; ?>
    
    <div class="summary-item">
        <span class="summary-label">Doctor:</span>
        <span class="summary-value" id="summaryDoctor">Any Available Doctor</span>
    </div>
    <div class="summary-item">
        <span class="summary-label">Date:</span>
        <span class="summary-value" id="summaryDate">—</span>
    </div>
    <div class="summary-item">
        <span class="summary-label">Time:</span>
        <span class="summary-value" id="summaryTime">—</span>
    </div>
    <div class="summary-item">
        <span class="summary-label">Contact:</span>
        <span class="summary-value" id="summaryContact">—</span>
    </div>
    <div class="total-price" id="totalPrice">
        ₱<?php echo number_format($total_product_price, 2); ?>
    </div>
</div>
                <!-- Confirm Button -->
                <button type="submit" form="bookingForm" name="confirm" class="btn-confirm" id="confirmBtn" disabled>
                    <i class="fas fa-check-circle"></i> Confirm Booking
                </button>

                <!-- Quick Info -->
                <div class="quick-info">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        Grayed out times are break hours
                    </p>
                    <p>
                        <i class="fas fa-clock"></i>
                        Red times are already booked
                    </p>
                    <p>
                        <i class="fas fa-exclamation-triangle"></i>
                        Max 3 appointments per day
                    </p>
                    <p>
                        <i class="fas fa-users"></i>
                        "Any Doctor" option available
                    </p>
                    <?php if ($booking_flow === 'pay_first'): ?>
                        <p style="color: var(--primary);">
                            <i class="fas fa-credit-card"></i>
                            Payment required immediately after booking
                        </p>
                    <?php else: ?>
                        <p style="color: var(--warning);">
                            <i class="fas fa-clock"></i>
                            Wait for clinic approval before payment
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Important Information -->
        <div class="info-card">
            <h2><i class="fas fa-info-circle"></i> Important Information</h2>
            <ul>
                <li>Please arrive at least 10 minutes before your scheduled appointment.</li>
                <li>Bring any previous prescription glasses or medical records if available.</li>
                <li>Cancellations must be made at least 2 hours before your appointment.</li>
                <li>For contact lens fitting, please don't wear your lenses 24 hours before the appointment.</li>
                <li>Payment can be made at the clinic via cash, credit card, or GCash.</li>
                <li>You can only book up to 3 appointments per day.</li>
                <li>Select "Any Available Doctor" if you don't have a preference.</li>
            </ul>
        </div>

        <?php endif; ?>
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
            
            // Initially select "Any Doctor"
            document.getElementById('anyDoctor').checked = true;
            
            // Initialize calendar
            initCalendar();
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

        // Scroll to step
        function scrollToStep(stepId) {
            const section = document.getElementById(stepId);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Store data from PHP
        const clinicBreaks = <?php echo json_encode($clinic_breaks); ?>;
        const clinicHours = '<?php echo $clinic['hours']; ?>';
        const bookedSlots = <?php echo json_encode($booked_slots); ?>;
        const doctorsData = <?php echo json_encode($doctors_list); ?>;
        
        // State management
        let step1Completed = false;
        let step2Completed = false;
        let step3Completed = false;
        let selectedDoctor = 'Any Available Doctor';
        let selectedDoctorId = 'any';
        let selectedDoctorSchedule = null;
        let selectedDate = '';
        let selectedTime = '';
        let currentMonth = new Date();
        let availableDatesCache = {};

        // ✅ Buffer (minutes) bago ang oras ngayon — pumipigil sa pag-book ng slot
        // na napakalapit na (halimbawa 5 minuto na lang bago dumating)
        const BOOKING_BUFFER_MINUTES = 30;

        // ✅ Helper: kunin ang clinic open/close minutes (24h format) mula sa clinicHours string
        function getClinicHoursRange() {
            const hoursMatch = clinicHours.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
            if (!hoursMatch) return null;

            let startHour = parseInt(hoursMatch[1]);
            let startMinute = hoursMatch[2] ? parseInt(hoursMatch[2]) : 0;
            let startAmPm = hoursMatch[3] ? hoursMatch[3].toLowerCase() : 'am';

            let endHour = parseInt(hoursMatch[4]);
            let endMinute = hoursMatch[5] ? parseInt(hoursMatch[5]) : 0;
            let endAmPm = hoursMatch[6] ? hoursMatch[6].toLowerCase() : 'pm';

            if (startAmPm === 'pm' && startHour !== 12) startHour += 12;
            if (startAmPm === 'am' && startHour === 12) startHour = 0;
            if (endAmPm === 'pm' && endHour !== 12) endHour += 12;
            if (endAmPm === 'am' && endHour === 12) endHour = 0;

            return {
                startMinutes: (startHour * 60) + startMinute,
                endMinutes: (endHour * 60) + endMinute
            };
        }

        // ✅ Helper: kunin ang YYYY-MM-DD ng "today" gamit ang LOCAL time (hindi UTC)
        function getTodayDateStr() {
            const now = new Date();
            return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        }

        // ✅ Helper: base sa selected doctor (o "any"), kunin ang pinaka-huling available
        //    na oras (end minutes) para sa ibinigay na araw ng linggo
        function getEffectiveEndMinutesForDay(dayOfWeek) {
            const clinicRange = getClinicHoursRange();
            if (!clinicRange) return null;

            let effectiveEnd = clinicRange.endMinutes;

            if (selectedDoctorId !== 'any' && selectedDoctorSchedule && selectedDoctorSchedule[dayOfWeek]) {
                const ranges = selectedDoctorSchedule[dayOfWeek];
                if (!Array.isArray(ranges) || ranges.length === 0) {
                    return null; // walang schedule ang doctor sa araw na ito
                }
                let latestEnd = 0;
                ranges.forEach(r => {
                    const parts = r.split('-');
                    if (parts.length < 2) return;
                    const endParts = parts[1].split(':');
                    const eh = parseInt(endParts[0]);
                    const em = parseInt(endParts[1] || '0');
                    const mins = (eh * 60) + em;
                    if (mins > latestEnd) latestEnd = mins;
                });
                effectiveEnd = Math.min(effectiveEnd, latestEnd);
            }

            return effectiveEnd;
        }

        // ✅ Helper: kung "today" ang dateStr, may matitira pa bang future slot
        //    (base sa kasalukuyang oras + buffer) bago mag-close ang clinic/doctor?
        function hasFutureSlotToday(dateStr, dayOfWeek) {
            const todayStr = getTodayDateStr();
            if (dateStr !== todayStr) return true; // hindi today, walang epekto ang oras ngayon

            const now = new Date();
            const nowMinutes = now.getHours() * 60 + now.getMinutes();

            const effectiveEnd = getEffectiveEndMinutesForDay(dayOfWeek);
            if (effectiveEnd === null) return false; // walang schedule

            return (nowMinutes + BOOKING_BUFFER_MINUTES) < effectiveEnd;
        }

        function handleDoctorSelection(radio) {
            const doctorCard = radio.closest('.doctor-card');
            
            if (doctorCard.classList.contains('no-schedule')) {
                alert('This doctor has no schedule set. Please choose another doctor.');
                document.getElementById('anyDoctor').checked = true;
                document.querySelectorAll('.doctor-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.getElementById('anyDoctorCard').classList.add('selected');
                return false;
            }
            
            document.querySelectorAll('.doctor-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            doctorCard.classList.add('selected');
            
            const isAnyDoctor = radio.value === 'any';
            
            if (isAnyDoctor) {
                selectedDoctor = 'Any Available Doctor';
                selectedDoctorId = 'any';
                selectedDoctorSchedule = null;
                document.getElementById('summaryDoctor').textContent = 'Any Available Doctor';
                document.getElementById('selectedDoctorName').value = '';
            } else {
                selectedDoctor = doctorCard.querySelector('.doctor-name').textContent;
                selectedDoctorId = radio.value;
                document.getElementById('summaryDoctor').textContent = selectedDoctor;
                document.getElementById('selectedDoctorName').value = selectedDoctor;
                
                const scheduleData = doctorCard.getAttribute('data-schedule');
                if (scheduleData && scheduleData !== 'null' && scheduleData !== '[]') {
                    try {
                        selectedDoctorSchedule = JSON.parse(scheduleData);
                    } catch(e) {
                        console.log('Error parsing schedule, using empty object');
                        selectedDoctorSchedule = {};
                    }
                } else {
                    selectedDoctorSchedule = {};
                }
            }
            
            step1Completed = true;
            
            document.getElementById('step1-status').textContent = '✓ Completed';
            document.getElementById('step1-status').className = 'step-status-badge status-completed';
            document.querySelector('.progress-step.step1').classList.add('completed');
            document.querySelector('.progress-step.step1').classList.remove('active');
            document.querySelector('.progress-step.step2').classList.remove('disabled');
            document.querySelector('.progress-step.step2').classList.add('active');
            
            document.getElementById('step2').classList.remove('locked');
            document.getElementById('step2').classList.add('active');
            document.getElementById('step2-status').textContent = 'Required';
            document.getElementById('step2-status').className = 'step-status-badge status-pending';
            document.getElementById('step2-content').classList.remove('disabled-content');
            
            selectedDate = '';
            selectedTime = '';
            document.getElementById('selectedDate').value = '';
            document.getElementById('selectedTime').value = '';
            document.getElementById('summaryDate').textContent = '—';
            document.getElementById('summaryTime').textContent = '—';
            
            renderCalendar(currentMonth);
            
            document.getElementById('timeSlotsContainer').innerHTML = `
                <div class="text-center" style="padding: 20px; color: #666;">
                    Select a date to see available time slots
                </div>
            `;
            
            setTimeout(() => scrollToStep('step2'), 300);
        }

        function initCalendar() {
            currentMonth = new Date();
            renderCalendar(currentMonth);
        }

        function renderCalendar(date) {
            const year = date.getFullYear();
            const month = date.getMonth();
            
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            
            const daysInMonth = lastDay.getDate();
            const startingDay = firstDay.getDay();
            
            let startOffset = startingDay === 0 ? 6 : startingDay - 1;
            
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                               'July', 'August', 'September', 'October', 'November', 'December'];
            
            let html = `
                <div class="calendar-header">
                    <button class="calendar-nav-btn" onclick="changeMonth(-1)">←</button>
                    <h4>${monthNames[month]} ${year}</h4>
                    <button class="calendar-nav-btn" onclick="changeMonth(1)">→</button>
                </div>
                <div class="calendar-weekdays">
                    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                </div>
                <div class="calendar-days">
            `;
            
            for (let i = 0; i < startOffset; i++) {
                html += '<div class="calendar-day empty"></div>';
            }
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const cellDate = new Date(year, month, day);
                
                const isPast = cellDate < today;
                const dayOfWeek = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][cellDate.getDay()];
                const isToday = cellDate.toDateString() === today.toDateString();
                
                let canSelect = false;
                
                if (!isPast) {
                    if (selectedDoctorId === 'any') {
                        canSelect = true;
                    } else if (selectedDoctorSchedule && typeof selectedDoctorSchedule === 'object') {
                        if (selectedDoctorSchedule[dayOfWeek] && 
                            Array.isArray(selectedDoctorSchedule[dayOfWeek]) && 
                            selectedDoctorSchedule[dayOfWeek].length > 0) {
                            canSelect = true;
                        }
                    }
                }

                // ✅ BAGO: kung "today" ang date at wala nang matitirang oras
                // (nakalampas na ang lahat ng available hours), i-disable ito
                if (canSelect && isToday && !hasFutureSlotToday(dateStr, dayOfWeek)) {
                    canSelect = false;
                }
                
                const isSelected = selectedDate === dateStr;
                
                let classes = 'calendar-day';
                if (canSelect) classes += ' available';
                if (isPast || !canSelect) classes += ' unavailable';
                if (isToday) classes += ' today';
                if (isSelected) classes += ' selected';
                
                let onclick = canSelect ? `selectDate('${dateStr}', '${dayOfWeek}')` : '';
                
                html += `
                    <div class="${classes}" ${onclick ? `onclick="${onclick}"` : ''}>
                        ${day}
                    </div>
                `;
            }
            
            html += '</div>';
            
            document.getElementById('calendarContainer').innerHTML = html;
        }

        function changeMonth(delta) {
            currentMonth.setMonth(currentMonth.getMonth() + delta);
            renderCalendar(currentMonth);
        }

        function selectDate(dateStr, dayOfWeek) {
            if (selectedDoctorId !== 'any' && selectedDoctorSchedule) {
                if (!selectedDoctorSchedule[dayOfWeek] || 
                    !Array.isArray(selectedDoctorSchedule[dayOfWeek]) || 
                    selectedDoctorSchedule[dayOfWeek].length === 0) {
                    
                    alert('Doctor is not available on this date');
                    return;
                }
            }

            // ✅ BAGO: huling check kung "today" at nakalampas na ang oras
            if (!hasFutureSlotToday(dateStr, dayOfWeek)) {
                alert('No more available time slots for today. Please select another date.');
                return;
            }
            
            selectedDate = dateStr;
            document.getElementById('selectedDate').value = dateStr;
            
            renderCalendar(currentMonth);
            
            const dateObj = new Date(dateStr);
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                year: 'numeric', month: 'long', day: 'numeric' 
            });
            document.getElementById('summaryDate').textContent = formattedDate;
            
            selectedTime = '';
            document.getElementById('selectedTime').value = '';
            document.getElementById('summaryTime').textContent = '—';
            
            loadTimeSlots(dateStr, dayOfWeek);
        }

        function loadTimeSlots(date, dayOfWeek) {
            document.getElementById('timeSlotsContainer').innerHTML = `
                <div class="time-slots-header">
                    <h4>Available Time Slots</h4>
                    <span>${new Date(date).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })}</span>
                </div>
                <div class="text-center" style="padding: 20px;">
                    <div class="loading-spinner" style="margin: 0 auto 10px;"></div>
                    <p style="color: var(--text-muted);">Loading available time slots...</p>
                </div>
            `;
            
            setTimeout(() => {
                generateTimeSlots(date, dayOfWeek);
            }, 500);
        }

        function generateTimeSlots(date, dayOfWeek) {
            const clinicRange = getClinicHoursRange();

            if (!clinicRange) {
                document.getElementById('timeSlotsContainer').innerHTML = '<div class="no-slots-message"><i class="fas fa-exclamation-triangle"></i><h4>Unable to parse clinic hours</h4><p>Please contact the clinic directly.</p></div>';
                return;
            }

            const clinicStartMinutes = clinicRange.startMinutes;
            const clinicEndMinutes = clinicRange.endMinutes;
            
            let doctorTimeRanges = [];
            if (selectedDoctorId !== 'any' && selectedDoctorSchedule && selectedDoctorSchedule[dayOfWeek]) {
                doctorTimeRanges = selectedDoctorSchedule[dayOfWeek];
            }

            // ✅ BAGO: kung "today" ang piniling petsa, kunin ang current time
            //    para masabing anong mga oras ang nakalipas na
            const todayStr = getTodayDateStr();
            const isSelectedDateToday = (date === todayStr);
            const now = new Date();
            const nowMinutes = now.getHours() * 60 + now.getMinutes();
            
            let availableSlots = [];
            let breakSlots = [];
            let bookedSlotsForDate = [];
            let unavailableSlots = [];
            let pastSlots = []; // ✅ BAGO: mga oras na nakalipas na (kung today)
            
            for (let mins = clinicStartMinutes; mins < clinicEndMinutes; mins += 30) {
                const hour = Math.floor(mins / 60);
                const minute = mins % 60;
                
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const displayHour = hour % 12 === 0 ? 12 : hour % 12;
                const displayTime = displayHour + ':' + (minute < 10 ? '0' + minute : minute) + ' ' + ampm;
                const time24h = (hour < 10 ? '0' + hour : hour) + ':' + (minute < 10 ? '0' + minute : minute) + ':00';

                // ✅ BAGO: skip/disable kung "today" at nakalipas na ang oras na ito
                // (may buffer para hindi puwedeng mag-book ng slot na masyadong malapit na)
                if (isSelectedDateToday && mins < (nowMinutes + BOOKING_BUFFER_MINUTES)) {
                    pastSlots.push({ time: displayTime });
                    continue;
                }
                
                const isBooked = bookedSlots.some(slot => 
                    slot.appointment_date === date && 
                    slot.appointment_time === time24h
                );
                
                if (isBooked) {
                    bookedSlotsForDate.push({ time: displayTime, time24h: time24h });
                    continue;
                }
                
                let isBreak = false;
                let breakName = '';
                
                clinicBreaks.forEach(breakItem => {
                    const startMatch = breakItem.break_start.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i);
                    const endMatch = breakItem.break_end.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i);
                    
                    if (startMatch && endMatch) {
                        let bsHour = parseInt(startMatch[1]);
                        let bsMinute = startMatch[2] ? parseInt(startMatch[2]) : 0;
                        let bsAmPm = startMatch[3].toLowerCase();
                        
                        let beHour = parseInt(endMatch[1]);
                        let beMinute = endMatch[2] ? parseInt(endMatch[2]) : 0;
                        let beAmPm = endMatch[3].toLowerCase();
                        
                        if (bsAmPm === 'pm' && bsHour !== 12) bsHour += 12;
                        if (bsAmPm === 'am' && bsHour === 12) bsHour = 0;
                        if (beAmPm === 'pm' && beHour !== 12) beHour += 12;
                        if (beAmPm === 'am' && beHour === 12) beHour = 0;
                        
                        const breakStartMins = (bsHour * 60) + bsMinute;
                        const breakEndMins = (beHour * 60) + beMinute;
                        
                        if (mins >= breakStartMins && mins < breakEndMins) {
                            isBreak = true;
                            breakName = breakItem.break_name;
                        }
                    }
                });
                
                if (isBreak) {
                    breakSlots.push({ time: displayTime, breakName: breakName });
                    continue;
                }
                
                if (selectedDoctorId !== 'any' && doctorTimeRanges.length > 0) {
                    let isWithinDoctorSchedule = false;
                    
                    for (const timeRange of doctorTimeRanges) {
                        const [rangeStart, rangeEnd] = timeRange.split('-');
                        
                        const startParts = rangeStart.split(':');
                        const endParts = rangeEnd.split(':');
                        
                        const rangeStartHour = parseInt(startParts[0]);
                        const rangeStartMin = parseInt(startParts[1] || '0');
                        const rangeEndHour = parseInt(endParts[0]);
                        const rangeEndMin = parseInt(endParts[1] || '0');
                        
                        const rangeStartMins = rangeStartHour * 60 + rangeStartMin;
                        const rangeEndMins = rangeEndHour * 60 + rangeEndMin;
                        
                        if (mins >= rangeStartMins && mins < rangeEndMins) {
                            isWithinDoctorSchedule = true;
                            break;
                        }
                    }
                    
                    if (!isWithinDoctorSchedule) {
                        unavailableSlots.push({ time: displayTime, reason: 'Outside doctor hours' });
                        continue;
                    }
                }
                
                availableSlots.push({ time: displayTime, time24h: time24h });
            }
            
            let html = `
                <div class="time-slots-header">
                    <h4>Available Time Slots</h4>
                    <span>${new Date(date).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })}</span>
                </div>
            `;
            
            if (availableSlots.length > 0) {
                html += '<div class="time-slots-grid">';
                availableSlots.forEach(slot => {
                    html += `<div class="time-slot-card available" onclick="selectTime('${slot.time}', '${slot.time24h}', this)">${slot.time}</div>`;
                });
                html += '</div>';
            }

            // ✅ BAGO: ipakita ang mga oras na nakalipas na (para malinaw sa user)
            if (pastSlots.length > 0) {
                html += '<h5 style="margin: 15px 0 5px; color: var(--text-muted);">Already Passed</h5>';
                html += '<div class="time-slots-grid">';
                pastSlots.forEach(slot => {
                    html += `<div class="time-slot-card disabled" title="This time has already passed">${slot.time}<span class="slot-label">Passed</span></div>`;
                });
                html += '</div>';
            }
            
            if (unavailableSlots.length > 0) {
                html += '<h5 style="margin: 15px 0 5px; color: var(--danger);">Outside Doctor Hours</h5>';
                html += '<div class="time-slots-grid">';
                unavailableSlots.forEach(slot => {
                    html += `<div class="time-slot-card unavailable" title="Outside doctor\'s working hours">${slot.time}<span class="slot-label">Not available</span></div>`;
                });
                html += '</div>';
            }
            
            if (bookedSlotsForDate.length > 0) {
                html += '<h5 style="margin: 15px 0 5px; color: var(--danger);">Already Booked</h5>';
                html += '<div class="time-slots-grid">';
                bookedSlotsForDate.forEach(slot => {
                    html += `<div class="time-slot-card booked" title="Already booked">${slot.time}<span class="slot-label">Booked</span></div>`;
                });
                html += '</div>';
            }
            
            if (breakSlots.length > 0) {
                html += '<h5 style="margin: 15px 0 5px; color: var(--warning);">Break Times</h5>';
                html += '<div class="time-slots-grid">';
                breakSlots.forEach(slot => {
                    html += `<div class="time-slot-card disabled" title="${slot.breakName}">${slot.time}<span class="slot-label">${slot.breakName}</span></div>`;
                });
                html += '</div>';
            }
            
            if (availableSlots.length === 0 && breakSlots.length === 0 && bookedSlotsForDate.length === 0 && unavailableSlots.length === 0 && pastSlots.length === 0) {
                html = '<div class="no-slots-message"><i class="fas fa-calendar-times"></i><h4>No available slots</h4><p>Please select another date.</p></div>';
            }

            // ✅ BAGO: kung wala nang natitirang available slot dahil lahat
            // ay nakalipas na (today) at walang break/booked/unavailable, palitan ang message
            if (availableSlots.length === 0 && isSelectedDateToday && pastSlots.length > 0 &&
                unavailableSlots.length === 0 && bookedSlotsForDate.length === 0) {
                html = `
                    <div class="time-slots-header">
                        <h4>Available Time Slots</h4>
                        <span>${new Date(date).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })}</span>
                    </div>
                    <div class="no-slots-message">
                        <i class="fas fa-clock"></i>
                        <h4>No more slots today</h4>
                        <p>All remaining time slots for today have passed. Please select another date.</p>
                    </div>
                `;
            }
            
            document.getElementById('timeSlotsContainer').innerHTML = html;
        }

        function selectTime(displayTime, time24h, element) {
            document.querySelectorAll('.time-slot-card').forEach(slot => {
                slot.classList.remove('selected');
            });
            
            element.classList.add('selected');
            
            document.getElementById('selectedTime').value = time24h;
            selectedTime = displayTime;
            
            document.getElementById('summaryTime').textContent = displayTime;
            
            step2Completed = true;
            
            document.getElementById('step2-status').textContent = '✓ Completed';
            document.getElementById('step2-status').className = 'step-status-badge status-completed';
            document.getElementById('step2').classList.add('completed');
            document.getElementById('step2').classList.remove('active');
            
            document.querySelector('.progress-step.step2').classList.add('completed');
            document.querySelector('.progress-step.step2').classList.remove('active');
            document.querySelector('.progress-step.step3').classList.remove('disabled');
            document.querySelector('.progress-step.step3').classList.add('active');
            
            document.getElementById('step3').classList.remove('locked');
            document.getElementById('step3').classList.add('active');
            document.getElementById('step3-status').textContent = 'Required';
            document.getElementById('step3-status').className = 'step-status-badge status-pending';
            document.getElementById('step3-content').classList.remove('disabled-content');
            
            document.querySelector('input[name="contact_number"]').disabled = false;
            document.querySelector('textarea[name="notes"]').disabled = false;
            
            setTimeout(() => {
                scrollToStep('step3');
            }, 300);
        }

        function handleContactInput() {
            const contact = document.querySelector('input[name="contact_number"]').value;
            
            if (contact && step1Completed && step2Completed && selectedTime) {
                document.getElementById('step3-status').textContent = '✓ Ready';
                document.getElementById('step3-status').className = 'step-status-badge status-completed';
                document.getElementById('step3').classList.add('completed');
                document.getElementById('step3').classList.remove('active');
                
                document.querySelector('.progress-step.step3').classList.add('completed');
                document.querySelector('.progress-step.step3').classList.remove('active');
                
                document.getElementById('confirmBtn').disabled = false;
            } else {
                document.getElementById('confirmBtn').disabled = true;
            }
            
            document.getElementById('summaryContact').textContent = contact || '—';
        }

        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const contactInput = document.querySelector('input[name="contact_number"]');
            
            if (!step1Completed || !step2Completed || !selectedTime || !contactInput.value) {
                e.preventDefault();
                showToast('Please complete all steps before confirming your booking.', 'error');
                
                if (!step1Completed) {
                    scrollToStep('step1');
                } else if (!step2Completed || !selectedTime) {
                    scrollToStep('step2');
                } else if (!contactInput.value) {
                    scrollToStep('step3');
                    contactInput.style.borderColor = 'var(--danger)';
                }
                return;
            }

            // ✅ BAGO: huling client-side check bago mag-submit — kung sakaling
            // nakaupo lang ang user sa page at nakalipas na ang piniling oras
            const selDate = document.getElementById('selectedDate').value;
            const selTimeVal = document.getElementById('selectedTime').value;
            const todayStr = getTodayDateStr();

            if (selDate === todayStr) {
                const now = new Date();
                const nowMinutes = now.getHours() * 60 + now.getMinutes();
                const [h, m] = selTimeVal.split(':').map(Number);
                const selMinutes = (h * 60) + m;

                if (selMinutes < nowMinutes) {
                    e.preventDefault();
                    showToast('That time has already passed. Please choose another time.', 'error');
                    scrollToStep('step2');
                }
            }
        });
    </script>
</body>
</html>