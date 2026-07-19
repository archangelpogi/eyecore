<?php
include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get clinic ID from URL
$clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$item_type = isset($_GET['type']) ? $_GET['type'] : ''; // 'service' or 'product'

if (!$clinic_id) {
    header('Location: dashboard.php');
    exit();
}

// Get clinic details
$clinic_query = mysqli_query($conn, "SELECT * FROM clinics WHERE id = $clinic_id");
$clinic = mysqli_fetch_assoc($clinic_query);

if (!$clinic) {
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

// Get user details
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get item if selected from URL (service or product)
$selected_item = null;
$selected_item_type = null;
$item_price = 0;

if ($item_id > 0 && $item_type == 'service') {
    $service_query = mysqli_query($conn, "SELECT *, 'service' as type FROM services WHERE id = $item_id AND clinic_id = $clinic_id");
    $selected_item = mysqli_fetch_assoc($service_query);
    $selected_item_type = 'service';
    $item_price = $selected_item['price'] ?? 0;
} elseif ($item_id > 0 && $item_type == 'product') {
    $product_query = mysqli_query($conn, "SELECT *, 'product' as type FROM products WHERE id = $item_id AND clinic_id = $clinic_id");
    $selected_item = mysqli_fetch_assoc($product_query);
    $selected_item_type = 'product';
    $item_price = $selected_item['price'] ?? 0;
}

// Get all services from this clinic
$services_query = mysqli_query($conn, "SELECT s.*, 
                                        GROUP_CONCAT(d.name SEPARATOR ', ') as available_doctors
                                        FROM services s
                                        LEFT JOIN service_doctors sd ON s.id = sd.service_id
                                        LEFT JOIN doctors d ON sd.doctor_id = d.id AND d.is_active = 1
                                        WHERE s.clinic_id = $clinic_id
                                        GROUP BY s.id
                                        ORDER BY s.category, s.name");

// Get all products from this clinic
$products_query = mysqli_query($conn, "SELECT *, 'product' as type FROM products WHERE clinic_id = $clinic_id ORDER BY category, name");

// Get doctors from this clinic with their schedules
$doctors_query = mysqli_query($conn, "SELECT * FROM doctors WHERE clinic_id = $clinic_id AND is_active = 1 ORDER BY name");
$doctors_list = [];
while($doc = mysqli_fetch_assoc($doctors_query)) {
    // Parse schedule JSON
    $doc['schedule'] = json_decode($doc['schedule'], true);
    $doctors_list[] = $doc;
}

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

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    $selected_item_id = (int)$_POST['item_id'];
    $selected_item_type_db = mysqli_real_escape_string($conn, $_POST['item_type']);
    $appointment_date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $appointment_time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $doctor_id = $_POST['doctor_id'] === 'any' ? 'NULL' : (int)$_POST['doctor_id'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number']);
    
    // Validate all fields
    if (empty($selected_item_id) || empty($appointment_date) || empty($appointment_time) || empty($contact_number)) {
        $error_message = 'Please complete all steps before confirming';
    } else {
        // ============================================
        // SIMPLE VALIDATION (SAME LOGIC AS book-specific-product.php)
        // ============================================
        
        // 1. Check if user already has appointment at this exact date and time (kahit ibang clinic)
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
        
        // 4. If specific doctor selected and it's a service, check if doctor is available on that date and time
        if (empty($error_message) && $doctor_id !== 'NULL' && $selected_item_type_db == 'service') {
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
// If no errors, proceed with booking
if (empty($error_message)) {
    // For products, doctor_id should be NULL
    if ($selected_item_type_db == 'product') {
        $doctor_id = 'NULL';
    }
    
    // ✅ STEP 1: Check kung may existing patient na ang user na ito
    $existingPatientId = null;
    
    // 1.1 Check sa users table kung may naka-link na patient_id
    $userCheck = mysqli_query($conn, "SELECT patient_id FROM users WHERE id = $user_id");
    if ($userCheck && mysqli_num_rows($userCheck) > 0) {
        $userData = mysqli_fetch_assoc($userCheck);
        if ($userData['patient_id']) {
            $existingPatientId = $userData['patient_id'];
        }
    }
    
    // 1.2 Kung wala, check sa patients table gamit ang email o contact
    if (!$existingPatientId) {
        $userEmail = mysqli_real_escape_string($conn, $user['email'] ?? '');
        $userContact = mysqli_real_escape_string($conn, $user['contact'] ?? '');
        
        if ($userEmail || $userContact) {
            $patientCheck = mysqli_query($conn, "
                SELECT id FROM patients 
                WHERE (email = '$userEmail' OR phone = '$userContact') 
                AND clinic_id = $clinic_id
                LIMIT 1
            ");
            
            if ($patientCheck && mysqli_num_rows($patientCheck) > 0) {
                $existingPatient = mysqli_fetch_assoc($patientCheck);
                $existingPatientId = $existingPatient['id'];
                mysqli_query($conn, "UPDATE users SET patient_id = $existingPatientId WHERE id = $user_id");
            }
        }
    }
    
    $isNewPatient = $existingPatientId ? 0 : 1;
    
    // ✅ STEP 2: Insert appointment with patient_id
    $insert_query = "INSERT INTO appointments 
                    (user_id, patient_id, clinic_id, item_id, item_type, doctor_id, 
                     appointment_date, appointment_time, notes, contact_number, status, is_new_patient, created_at) 
                    VALUES ($user_id, " . ($existingPatientId ?: 'NULL') . ", $clinic_id, $selected_item_id, '$selected_item_type_db', " . 
                    ($doctor_id === 'NULL' ? 'NULL' : $doctor_id) . ", 
                    '$appointment_date', '$appointment_time', '$notes', '$contact_number', 'pending', $isNewPatient, NOW())";
    
    if (mysqli_query($conn, $insert_query)) {
        $new_appointment_id = mysqli_insert_id($conn);
        
        // Add notification
        $formatted_date = date('F j, Y', strtotime($appointment_date));
        $formatted_time = date('g:i A', strtotime($appointment_time));
        $doctor_text = ($doctor_id === 'NULL' || $selected_item_type_db == 'product') 
                       ? '' 
                       : ' with Dr. ' . $_POST['doctor_name'];
        $item_text = $selected_item ? $selected_item['name'] : 'appointment';
        
        if (function_exists('addNotification')) {
            addNotification(
                $user_id,
                'appointment',
                'New Appointment Booked',
                "Your {$item_text} at {$clinic['name']}{$doctor_text} on {$formatted_date} at {$formatted_time} is pending confirmation.",
                'my-appointments.php'
            );
        }
        
        // ✅ GET ITEM PRICE
        $item_price = 0;
        if ($selected_item_type_db == 'service') {
            $price_query = mysqli_query($conn, "SELECT price FROM services WHERE id = $selected_item_id");
            $price_row = mysqli_fetch_assoc($price_query);
            $item_price = $price_row['price'] ?? 0;
        } else {
            $price_query = mysqli_query($conn, "SELECT price FROM products WHERE id = $selected_item_id");
            $price_row = mysqli_fetch_assoc($price_query);
            $item_price = $price_row['price'] ?? 0;
        }
        
        // ✅ CHECK PAYMENT POLICY
        $payment_info = calculatePaymentAmounts($conn, $clinic_id, $item_price);
        $booking_flow = $payment_info['booking_flow'] ?? 'approve_first';
        
        if ($payment_info['requires_payment']) {
            if ($booking_flow === 'pay_first') {
                header('Location: payment.php?appointment_id=' . $new_appointment_id);
                exit();
            } else {
                $success_message = 'Appointment booked successfully! Please wait for clinic approval before making payment.';
                $_POST = array();
            }
        } else {
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

// Check if item is pre-selected
$step1_completed = ($selected_item !== null);
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Book Appointment - <?php echo $clinic['name']; ?></title>
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

        .clinic-badge {
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

        .clinic-badge i {
            color: var(--primary);
        }

        .clinic-badge span {
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

        /* Product card image thumbnail */
        .product-img-thumb {
            width: 70px;
            height: 70px;
            border-radius: var(--radius-sm);
            margin: 0 auto 12px;
            overflow: hidden;
            border: 1px solid var(--border-light);
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .product-img-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .product-img-thumb .img-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 28px;
        }
        .doctor-card.selected .product-img-thumb {
            border-color: var(--primary);
        }

        /* Tabs for Services/Products */
        .booking-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        
        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.2s;
        }
        
        .tab-btn i {
            margin-right: 8px;
        }
        
        .tab-btn.active {
            background: var(--primary-gradient);
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .product-price {
            font-size: 18px;
            font-weight: 700;
            color: var(--success);
            margin-top: 8px;
        }
        
        .service-duration, .service-doctors {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 8px;
        }
        
        .service-duration i, .service-doctors i {
            margin-right: 4px;
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
  
    
    include '../includes/navbar.php';
    ?>

    
    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-plus"></i>
                Book Appointment
            </h1>
            <div class="clinic-badge">
                <i class="fas fa-clinic-medical"></i> <span><?php echo $clinic['name']; ?></span>
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
                    <p>Your appointment has been successfully scheduled.</p>
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
                <!-- Item Preview (if pre-selected) -->
                <?php if ($selected_item): ?>
                <div class="product-preview">
                    <div class="product-preview-image">
                        <?php if ($selected_item_type == 'service'): ?>
                            <div class="placeholder">
                                <i class="fas fa-stethoscope"></i>
                                <span>Service</span>
                            </div>
                        <?php else: ?>
                            <div class="placeholder">
                                <i class="fas fa-box-open"></i>
                                <span>Product</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="product-preview-details">
                        <div class="product-preview-category">
                            <?php echo $selected_item['category']; ?> 
                            (<?php echo $selected_item_type == 'service' ? 'Service' : 'Product'; ?>)
                        </div>
                        <div class="product-preview-name"><?php echo $selected_item['name']; ?></div>
                        <div class="product-preview-price">₱<?php echo number_format($selected_item['price'], 2); ?></div>
                        <div class="product-preview-badge">
                            <i class="fas fa-check-circle"></i> Selected
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Progress Steps -->
                <div class="booking-progress">
                    <div class="progress-step step1 <?php echo $step1_completed ? 'completed' : 'active'; ?>" onclick="scrollToStep('step1')">
                        <div class="step-number">1</div>
                        <div class="step-label">Select Item</div>
                    </div>
                    <div class="progress-step step2 <?php echo $step1_completed ? '' : 'disabled'; ?>" onclick="scrollToStep('step2')">
                        <div class="step-number">2</div>
                        <div class="step-label">Choose Doctor</div>
                    </div>
                    <div class="progress-step step3 disabled" onclick="scrollToStep('step3')">
                        <div class="step-number">3</div>
                        <div class="step-label">Date & Time</div>
                    </div>
                    <div class="progress-step step4 disabled" onclick="scrollToStep('step4')">
                        <div class="step-number">4</div>
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
                    <input type="hidden" name="item_type" id="selectedItemType" value="<?php echo $selected_item_type; ?>">
                    
                    <!-- STEP 1: Select Item -->
                    <div id="step1" class="step-section <?php echo $step1_completed ? 'completed' : 'active'; ?>">
                        <div class="step-header">
                            <h3><i class="fas fa-box"></i> Select Service or Product</h3>
                            <span class="step-status-badge <?php echo $step1_completed ? 'status-completed' : 'status-pending'; ?>" id="step1-status">
                                <?php echo $step1_completed ? '✓ Completed' : 'Required'; ?>
                            </span>
                        </div>
                        
                        <!-- Tabs for Services/Products -->
                        <div class="booking-tabs">
                            <button type="button" class="tab-btn active" onclick="switchTab('services')" id="servicesTab">
                                <i class="fas fa-stethoscope"></i> Services
                            </button>
                            <button type="button" class="tab-btn" onclick="switchTab('products')" id="productsTab">
                                <i class="fas fa-box"></i> Products
                            </button>
                        </div>
                        
                        <!-- Services Tab -->
                        <div id="services-tab" class="tab-content active">
                            <div class="doctors-grid">
                                <?php 
                                mysqli_data_seek($services_query, 0);
                                if (mysqli_num_rows($services_query) > 0):
                                    while($service = mysqli_fetch_assoc($services_query)): 
                                ?>
                                <label class="doctor-card <?php echo ($selected_item && $selected_item['id'] == $service['id'] && $selected_item_type == 'service') ? 'selected' : ''; ?>">
                                    <input type="radio" name="item_id" value="<?php echo $service['id']; ?>" 
                                           data-type="service" data-price="<?php echo $service['price']; ?>"
                                           data-name="<?php echo htmlspecialchars($service['name']); ?>"
                                           <?php echo ($selected_item && $selected_item['id'] == $service['id'] && $selected_item_type == 'service') ? 'checked' : ''; ?>
                                           onchange="handleItemSelection(this)">
                                    <div class="doctor-avatar" style="background: var(--primary-light); color: var(--primary);">
                                        <i class="fas fa-stethoscope"></i>
                                    </div>
                                    <div class="doctor-name"><?php echo $service['name']; ?></div>
                                    <div class="doctor-specialty"><?php echo $service['category']; ?></div>
                                    <div class="product-price">₱<?php echo number_format($service['price'], 2); ?></div>
                                    <span class="doctor-schedule-badge">
                                        <i class="fas fa-clock"></i> <?php echo $service['duration_minutes'] ?? '30'; ?> mins
                                    </span>
                                </label>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <div class="no-slots-message" style="grid-column: 1/-1;">
                                    <i class="fas fa-stethoscope"></i>
                                    <h4>No Services Available</h4>
                                    <p>This clinic hasn't added any services yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Products Tab -->
                        <div id="products-tab" class="tab-content">
                            <div class="doctors-grid">
                                <?php 
                                mysqli_data_seek($products_query, 0);
                                if (mysqli_num_rows($products_query) > 0):
                                    while($product = mysqli_fetch_assoc($products_query)): 
                                ?>
                                <label class="doctor-card <?php echo ($selected_item && $selected_item['id'] == $product['id'] && $selected_item_type == 'product') ? 'selected' : ''; ?>">
                                    <input type="radio" name="item_id" value="<?php echo $product['id']; ?>" 
                                           data-type="product" data-price="<?php echo $product['price']; ?>"
                                           data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                           <?php echo ($selected_item && $selected_item['id'] == $product['id'] && $selected_item_type == 'product') ? 'checked' : ''; ?>
                                           onchange="handleItemSelection(this)">
                                    <?php
                                    // Build product image URL (same logic as clinic-products.php)
                                    $prod_img = null;
                                    if (!empty($product['images_json'])) {
                                        $decoded = json_decode($product['images_json'], true);
                                        if (!empty($decoded[0])) {
                                            $p = str_replace('uploads/uploads/', 'uploads/', $decoded[0]);
                                            $prod_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                                        }
                                    }
                                    if (!$prod_img && !empty($product['images'])) {
                                        $d = $product['images'];
                                        if (strpos($d, '[') === 0) {
                                            $decoded = json_decode($d, true);
                                            if (!empty($decoded[0])) {
                                                $p = str_replace('uploads/uploads/', 'uploads/', $decoded[0]);
                                                $prod_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                                            }
                                        } else {
                                            $p = str_replace('uploads/uploads/', 'uploads/', $d);
                                            $prod_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                                        }
                                    }
                                    if (!$prod_img && !empty($product['image'])) {
                                        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
                                            $prod_img = '/assets/images/products/' . $product['image'];
                                        } else {
                                            $p = str_replace('uploads/uploads/', 'uploads/', $product['image']);
                                            $prod_img = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                                        }
                                    }
                                    ?>
                                    <div class="product-img-thumb">
                                        <?php if ($prod_img): ?>
                                            <img src="<?php echo $prod_img; ?>"
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                 onerror="this.parentElement.innerHTML='<div class=\'img-fallback\'><i class=\'fas fa-box-open\'></i></div>'">
                                        <?php else: ?>
                                            <div class="img-fallback">
                                                <i class="fas fa-box-open"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="doctor-name"><?php echo $product['name']; ?></div>
                                    <div class="doctor-specialty"><?php echo $product['category']; ?></div>
                                    <div class="product-price">₱<?php echo number_format($product['price'], 2); ?></div>
                                    <span class="doctor-schedule-badge">
                                        <i class="fas fa-tag"></i> Product
                                    </span>
                                </label>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <div class="no-slots-message" style="grid-column: 1/-1;">
                                    <i class="fas fa-box-open"></i>
                                    <h4>No Products Available</h4>
                                    <p>This clinic hasn't added any products yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: Choose Doctor -->
                    <div id="step2" class="step-section <?php echo $step1_completed ? 'active' : 'locked'; ?>">
                        <div class="step-header">
                            <h3><i class="fas fa-user-md"></i> Choose Your Doctor</h3>
                            <span class="step-status-badge <?php echo $step1_completed ? 'status-pending' : 'status-locked'; ?>" id="step2-status">
                                <?php echo $step1_completed ? 'Required' : '🔒 Locked'; ?>
                            </span>
                        </div>
                        
                        <div class="<?php echo $step1_completed ? '' : 'disabled-content'; ?>" id="step2-content">
                            <?php if (!empty($doctors_list)): ?>
                            <div class="doctors-grid" id="doctorsGrid">
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
                            
                            <!-- Message for Products (no doctor needed) -->
                            <div id="product-message" style="display: none; margin-top: 15px; padding: 15px; background: var(--primary-light); border-radius: var(--radius-md);">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Products don't require a doctor assignment.</strong> You can proceed to select date and time.
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: Choose Date & Time -->
                    <div id="step3" class="step-section locked">
                        <div class="step-header">
                            <h3><i class="fas fa-clock"></i> Choose Date & Time</h3>
                            <span class="step-status-badge status-locked" id="step3-status">🔒 Locked</span>
                        </div>
                        
                        <div class="disabled-content" id="step3-content">
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

                    <!-- STEP 4: Confirm Details -->
                    <div id="step4" class="step-section locked">
                        <div class="step-header">
                            <h3><i class="fas fa-check-circle"></i> Confirm Your Details</h3>
                            <span class="step-status-badge status-locked" id="step4-status">🔒 Locked</span>
                        </div>
                        
                        <div class="disabled-content" id="step4-content">
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
                // Payment info display
                $display_payment = getPaymentDisplayInfo($conn, $clinic_id, $item_price);
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
                        <span class="summary-label">Item:</span>
                        <span class="summary-value" id="summaryItem">
                            <?php echo $selected_item ? $selected_item['name'] : 'Not selected'; ?>
                        </span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Type:</span>
                        <span class="summary-value" id="summaryType">
                            <?php echo $selected_item_type ? ucfirst($selected_item_type) : '—'; ?>
                        </span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Price:</span>
                        <span class="summary-value" id="summaryPrice">
                            <?php echo $selected_item ? '₱' . number_format($selected_item['price'], 2) : '—'; ?>
                        </span>
                    </div>
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
                        <?php echo $selected_item ? '₱' . number_format($selected_item['price'], 2) : '₱0.00'; ?>
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
                        <i class="fas fa-stethoscope"></i>
                        Services require doctor selection
                    </p>
                    <p>
                        <i class="fas fa-box"></i>
                        Products don't need a doctor
                    </p>
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
                <li>Services require a doctor, products can be booked without one.</li>
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
            
            // Initialize booking page
            if (step1Completed) {
                document.getElementById('summaryItem').textContent = selectedItem;
                document.getElementById('summaryType').textContent = selectedItemType.charAt(0).toUpperCase() + selectedItemType.slice(1);
                document.getElementById('summaryPrice').textContent = '₱' + selectedPrice.toFixed(2);
                document.getElementById('totalPrice').textContent = '₱' + selectedPrice.toFixed(2);
                
                document.querySelector('.progress-step.step2').classList.remove('disabled');
                document.getElementById('step2').classList.remove('locked');
                document.getElementById('step2').classList.add('active');
                document.getElementById('step2-status').textContent = 'Required';
                document.getElementById('step2-status').className = 'step-status-badge status-pending';
                document.getElementById('step2-content').classList.remove('disabled-content');
            }
            
            // Initially select "Any Doctor" if not product
            if (document.getElementById('anyDoctor')) {
                document.getElementById('anyDoctor').checked = true;
            }
            
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

        // Tab switching
        function switchTab(tab) {
            const servicesTab = document.getElementById('services-tab');
            const productsTab = document.getElementById('products-tab');
            const servicesBtn = document.getElementById('servicesTab');
            const productsBtn = document.getElementById('productsTab');
            
            if (tab === 'services') {
                servicesTab.classList.add('active');
                productsTab.classList.remove('active');
                servicesBtn.classList.add('active');
                productsBtn.classList.remove('active');
            } else {
                servicesTab.classList.remove('active');
                productsTab.classList.add('active');
                servicesBtn.classList.remove('active');
                productsBtn.classList.add('active');
            }
        }

        // Store data from PHP
        const clinicBreaks = <?php echo json_encode($clinic_breaks); ?>;
        const clinicHours = '<?php echo $clinic['hours']; ?>';
        const bookedSlots = <?php echo json_encode($booked_slots); ?>;
        const doctorsData = <?php echo json_encode($doctors_list); ?>;
        
        // State management
        let step1Completed = <?php echo $step1_completed ? 'true' : 'false'; ?>;
        let step2Completed = false;
        let step3Completed = false;
        let selectedItem = <?php echo $selected_item ? json_encode($selected_item['name']) : 'null'; ?>;
        let selectedPrice = <?php echo $selected_item ? $selected_item['price'] : '0'; ?>;
        let selectedItemType = '<?php echo $selected_item_type; ?>';
        let selectedDoctor = 'Any Available Doctor';
        let selectedDoctorId = 'any';
        let selectedDoctorSchedule = null;
        let selectedDate = '';
        let selectedTime = '';
        let currentMonth = new Date();

        function handleItemSelection(radio) {
            document.querySelectorAll('#services-tab .doctor-card, #products-tab .doctor-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            radio.closest('.doctor-card').classList.add('selected');
            
            const itemType = radio.getAttribute('data-type');
            const itemName = radio.getAttribute('data-name');
            const itemPrice = parseFloat(radio.getAttribute('data-price'));
            
            selectedItem = itemName;
            selectedPrice = itemPrice;
            selectedItemType = itemType;
            
            document.getElementById('selectedItemType').value = itemType;
            
            step1Completed = true;
            
            // Update UI
            document.getElementById('step1-status').textContent = '✓ Completed';
            document.getElementById('step1-status').className = 'step-status-badge status-completed';
            document.getElementById('step1').classList.add('completed');
            document.getElementById('step1').classList.remove('active');
            
            document.querySelector('.progress-step.step1').classList.add('completed');
            document.querySelector('.progress-step.step1').classList.remove('active');
            document.querySelector('.progress-step.step2').classList.remove('disabled');
            document.querySelector('.progress-step.step2').classList.add('active');
            
            document.getElementById('step2').classList.remove('locked');
            document.getElementById('step2').classList.add('active');
            document.getElementById('step2-status').textContent = 'Required';
            document.getElementById('step2-status').className = 'step-status-badge status-pending';
            document.getElementById('step2-content').classList.remove('disabled-content');
            
            // Update summary
            document.getElementById('summaryItem').textContent = itemName;
            document.getElementById('summaryType').textContent = itemType.charAt(0).toUpperCase() + itemType.slice(1);
            document.getElementById('summaryPrice').textContent = '₱' + itemPrice.toFixed(2);
            document.getElementById('totalPrice').textContent = '₱' + itemPrice.toFixed(2);
            
            // Show/hide doctor selection based on item type
            if (itemType === 'product') {
                // For products, hide doctor selection and show message
                document.getElementById('doctorsGrid').style.display = 'none';
                document.getElementById('product-message').style.display = 'block';
                
                // Auto-mark step 2 as completed for products
                step2Completed = true;
                document.getElementById('step2-status').textContent = '✓ Completed (Not needed)';
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
                
                // Reset doctor selection
                selectedDoctor = 'Not needed';
                selectedDoctorId = 'any';
                document.getElementById('summaryDoctor').textContent = 'Not needed';
            } else {
                // For services, show doctor selection
                document.getElementById('doctorsGrid').style.display = 'grid';
                document.getElementById('product-message').style.display = 'none';
                
                // Reset step 2 status
                step2Completed = false;
                document.getElementById('step2-status').textContent = 'Required';
                document.getElementById('step2-status').className = 'step-status-badge status-pending';
                document.getElementById('step2').classList.remove('completed');
                
                // Select "Any Doctor" by default
                document.getElementById('anyDoctor').checked = true;
                selectedDoctor = 'Any Available Doctor';
                selectedDoctorId = 'any';
                selectedDoctorSchedule = null;
                document.getElementById('summaryDoctor').textContent = 'Any Available Doctor';
            }
            
            setTimeout(() => {
                scrollToStep('step2');
            }, 300);
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
            
            selectedDate = '';
            selectedTime = '';
            document.getElementById('selectedDate').value = '';
            document.getElementById('selectedTime').value = '';
            document.getElementById('summaryDate').textContent = '—';
            document.getElementById('summaryTime').textContent = '—';
            
            renderCalendar(currentMonth);
            
            document.getElementById('timeSlotsContainer').innerHTML = `
                <div class="text-center" style="padding: 20px; color: var(--text-muted);">
                    <i class="fas fa-clock"></i> Select a date to see available time slots
                </div>
            `;
            
            setTimeout(() => scrollToStep('step3'), 300);
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
                
                let canSelect = false;
                
                if (!isPast) {
                    if (selectedItemType === 'product' || selectedDoctorId === 'any') {
                        canSelect = true;
                    } else if (selectedDoctorSchedule && typeof selectedDoctorSchedule === 'object') {
                        if (selectedDoctorSchedule[dayOfWeek] && 
                            Array.isArray(selectedDoctorSchedule[dayOfWeek]) && 
                            selectedDoctorSchedule[dayOfWeek].length > 0) {
                            canSelect = true;
                        }
                    }
                }
                
                const isToday = cellDate.toDateString() === today.toDateString();
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
            if (selectedItemType === 'service' && selectedDoctorId !== 'any' && selectedDoctorSchedule) {
                if (!selectedDoctorSchedule[dayOfWeek] || 
                    !Array.isArray(selectedDoctorSchedule[dayOfWeek]) || 
                    selectedDoctorSchedule[dayOfWeek].length === 0) {
                    
                    alert('Doctor is not available on this date');
                    return;
                }
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
    // Check if clinic hours is empty or invalid
    if (!clinicHours || clinicHours.trim() === '') {
        document.getElementById('timeSlotsContainer').innerHTML = `
            <div class="no-slots-message">
                <i class="fas fa-exclamation-triangle"></i>
                <h4>Clinic hours not set</h4>
                <p>Please contact the clinic directly to schedule your appointment.</p>
                <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-primary" style="margin-top: 15px; display: inline-block;">
                    <i class="fas fa-phone"></i> Contact Clinic
                </a>
            </div>`;
        return;
    }
    
    // Check for 24/7 or special hours
    const lowerHours = clinicHours.toLowerCase();
    if (lowerHours.includes('24/7') || lowerHours.includes('24 hours') || lowerHours.includes('always open')) {
        // Use default hours 9 AM to 8 PM
        var clinicStartHour = 9;
        var clinicStartMinute = 0;
        var clinicStartAmPm = 'am';
        var clinicEndHour = 8;
        var clinicEndMinute = 0;
        var clinicEndAmPm = 'pm';
    } else {
        // Try to parse hours using multiple patterns
        let hoursMatch = null;
        
        // Pattern 1: 9:00 AM - 8:00 PM
        hoursMatch = clinicHours.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
        
        // Pattern 2: 9am-8pm (no spaces)
        if (!hoursMatch) {
            hoursMatch = clinicHours.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
        }
        
        // Pattern 3: 09:00 to 20:00 (24-hour format)
        if (!hoursMatch) {
            hoursMatch = clinicHours.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/i);
            if (hoursMatch) {
                clinicStartHour = parseInt(hoursMatch[1]);
                clinicStartMinute = parseInt(hoursMatch[2]);
                clinicEndHour = parseInt(hoursMatch[3]);
                clinicEndMinute = parseInt(hoursMatch[4]);
                // Use default 9 AM - 8 PM if parsing fails
                if (isNaN(clinicStartHour)) clinicStartHour = 9;
                if (isNaN(clinicStartMinute)) clinicStartMinute = 0;
                if (isNaN(clinicEndHour)) clinicEndHour = 20;
                if (isNaN(clinicEndMinute)) clinicEndMinute = 0;
                
                // Use default hours for time slot generation
                var startMins = clinicStartHour * 60 + clinicStartMinute;
                var endMins = clinicEndHour * 60 + clinicEndMinute;
                generateSlotsFromMinutes(startMins, endMins, date, dayOfWeek);
                return;
            }
        }
        
        if (!hoursMatch) {
            // Default to 9 AM - 8 PM if parsing fails
            var clinicStartHour = 9;
            var clinicStartMinute = 0;
            var clinicStartAmPm = 'am';
            var clinicEndHour = 8;
            var clinicEndMinute = 0;
            var clinicEndAmPm = 'pm';
        } else {
            var clinicStartHour = parseInt(hoursMatch[1]);
            var clinicStartMinute = hoursMatch[2] ? parseInt(hoursMatch[2]) : 0;
            var clinicStartAmPm = hoursMatch[3] ? hoursMatch[3].toLowerCase() : 'am';
            var clinicEndHour = parseInt(hoursMatch[4]);
            var clinicEndMinute = hoursMatch[5] ? parseInt(hoursMatch[5]) : 0;
            var clinicEndAmPm = hoursMatch[6] ? hoursMatch[6].toLowerCase() : 'pm';
        }
    }
    
    // Convert to 24-hour format
    let startHour24 = clinicStartHour;
    let endHour24 = clinicEndHour;
    
    if (clinicStartAmPm === 'pm' && clinicStartHour !== 12) startHour24 = clinicStartHour + 12;
    if (clinicStartAmPm === 'am' && clinicStartHour === 12) startHour24 = 0;
    if (clinicEndAmPm === 'pm' && clinicEndHour !== 12) endHour24 = clinicEndHour + 12;
    if (clinicEndAmPm === 'am' && clinicEndHour === 12) endHour24 = 0;
    
    const clinicStartMinutes = (startHour24 * 60) + clinicStartMinute;
    const clinicEndMinutes = (endHour24 * 60) + clinicEndMinute;
    
    generateSlotsFromMinutes(clinicStartMinutes, clinicEndMinutes, date, dayOfWeek);
}

function generateSlotsFromMinutes(startMins, endMins, date, dayOfWeek) {
    let doctorTimeRanges = [];
    if (selectedItemType === 'service' && selectedDoctorId !== 'any' && selectedDoctorSchedule && selectedDoctorSchedule[dayOfWeek]) {
        doctorTimeRanges = selectedDoctorSchedule[dayOfWeek];
    }
    
    let availableSlots = [];
    let breakSlots = [];
    let bookedSlotsForDate = [];
    let unavailableSlots = [];
    
    for (let mins = startMins; mins < endMins; mins += 30) {
        const hour = Math.floor(mins / 60);
        const minute = mins % 60;
        
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 === 0 ? 12 : hour % 12;
        const displayTime = displayHour + ':' + (minute < 10 ? '0' + minute : minute) + ' ' + ampm;
        const time24h = (hour < 10 ? '0' + hour : hour) + ':' + (minute < 10 ? '0' + minute : minute) + ':00';
        
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
        
        if (clinicBreaks && clinicBreaks.length > 0) {
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
        }
        
        if (isBreak) {
            breakSlots.push({ time: displayTime, breakName: breakName });
            continue;
        }
        
        if (selectedItemType === 'service' && selectedDoctorId !== 'any' && doctorTimeRanges.length > 0) {
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
    
    if (unavailableSlots.length > 0) {
        html += '<h5 style="margin: 15px 0 5px; color: var(--danger);">Outside Doctor Hours</h5>';
        html += '<div class="time-slots-grid">';
        unavailableSlots.forEach(slot => {
            html += `<div class="time-slot-card unavailable" title="Outside doctor's working hours">${slot.time}<span class="slot-label">Not available</span></div>`;
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
    
    if (availableSlots.length === 0 && breakSlots.length === 0 && bookedSlotsForDate.length === 0 && unavailableSlots.length === 0) {
        html = '<div class="no-slots-message"><i class="fas fa-calendar-times"></i><h4>No available slots</h4><p>Please select another date.</p></div>';
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
            
            step3Completed = true;
            
            document.getElementById('step3-status').textContent = '✓ Completed';
            document.getElementById('step3-status').className = 'step-status-badge status-completed';
            document.getElementById('step3').classList.add('completed');
            document.getElementById('step3').classList.remove('active');
            
            document.querySelector('.progress-step.step3').classList.add('completed');
            document.querySelector('.progress-step.step3').classList.remove('active');
            document.querySelector('.progress-step.step4').classList.remove('disabled');
            document.querySelector('.progress-step.step4').classList.add('active');
            
            document.getElementById('step4').classList.remove('locked');
            document.getElementById('step4').classList.add('active');
            document.getElementById('step4-status').textContent = 'Required';
            document.getElementById('step4-status').className = 'step-status-badge status-pending';
            document.getElementById('step4-content').classList.remove('disabled-content');
            
            document.querySelector('input[name="contact_number"]').disabled = false;
            document.querySelector('textarea[name="notes"]').disabled = false;
            
            setTimeout(() => {
                scrollToStep('step4');
            }, 300);
        }

        function handleContactInput() {
            const contact = document.querySelector('input[name="contact_number"]').value;
            
            if (contact && step1Completed && (step2Completed || selectedItemType === 'product') && step3Completed && selectedTime) {
                document.getElementById('step4-status').textContent = '✓ Ready';
                document.getElementById('step4-status').className = 'step-status-badge status-completed';
                document.getElementById('step4').classList.add('completed');
                document.getElementById('step4').classList.remove('active');
                
                document.querySelector('.progress-step.step4').classList.add('completed');
                document.querySelector('.progress-step.step4').classList.remove('active');
                
                document.getElementById('confirmBtn').disabled = false;
            } else {
                document.getElementById('confirmBtn').disabled = true;
            }
            
            document.getElementById('summaryContact').textContent = contact || '—';
        }

        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const contactInput = document.querySelector('input[name="contact_number"]');
            
            if (!step1Completed || !step3Completed || !selectedTime || !contactInput.value) {
                e.preventDefault();
                showToast('Please complete all steps before confirming your booking.', 'error');
                
                if (!step1Completed) {
                    scrollToStep('step1');
                } else if (selectedItemType === 'service' && !step2Completed) {
                    scrollToStep('step2');
                } else if (!step3Completed || !selectedTime) {
                    scrollToStep('step3');
                } else if (!contactInput.value) {
                    scrollToStep('step4');
                    contactInput.style.borderColor = 'var(--danger)';
                }
            }
        });
    </script>
</body>
</html>