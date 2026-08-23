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
$item_type_param = isset($_GET['item_type']) ? $_GET['item_type'] : '';

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

// ============================================
// ✅ CHECK IF PRODUCT IS PRESCRIPTION
// ============================================
function isPrescriptionProduct($product) {
    $category = $product['category'] ?? '';
    
    // Services are not products
    if (in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening'])) {
        return false;
    }
    
    // Frames and Accessories are NOT prescription by default
    if (in_array($category, ['Frames', 'Accessories', 'Parts', 'Cleaning Kits'])) {
        return false;
    }
    
    // ============================================
    // ✅ EYEWEAR & CONTACT LENSES are ALWAYS prescription
    // Eyeglasses, Lenses, Contact Lenses, Contact Lens
    // ============================================
    if (in_array($category, ['Eyeglasses', 'Lenses', 'Contact Lenses', 'Contact Lens'])) {
        return true;
    }
    
    // Check extra_fields_json for any prescription indicators
    if (!empty($product['extra_fields_json'])) {
        $extra = json_decode($product['extra_fields_json'], true);
        if (is_array($extra)) {
            // Check if contact lens has power (for future reference)
            if (isset($extra['cl_power_range']) && !empty($extra['cl_power_range'])) {
                return true;
            }
            // Check if eyewear has prescription flag
            if (isset($extra['has_prescription']) && $extra['has_prescription'] == 1) {
                return true;
            }
            if (isset($extra['requires_prescription']) && $extra['requires_prescription'] == 1) {
                return true;
            }
        }
    }
    
    // Default: treat as non-prescription
    return false;
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

// Get item if selected from URL
$selected_item = null;
$selected_item_type = null;
$item_price = 0;
$selected_item_id = 0;

if ($item_id > 0 && $item_type_param == 'service') {
    $service_query = mysqli_query($conn, "SELECT *, 'service' as type FROM services WHERE id = $item_id AND clinic_id = $clinic_id");
    $selected_item = mysqli_fetch_assoc($service_query);
    $selected_item_type = 'service';
    $item_price = $selected_item['price'] ?? 0;
    $selected_item_id = $item_id;
} elseif ($item_id > 0 && $item_type_param == 'product') {
    $product_query = mysqli_query($conn, "SELECT *, 'product' as type FROM products WHERE id = $item_id AND clinic_id = $clinic_id");
    $selected_item = mysqli_fetch_assoc($product_query);
    $selected_item_type = 'product';
    $item_price = $selected_item['price'] ?? 0;
    $selected_item_id = $item_id;
}

// Get all services
$services_query = mysqli_query($conn, "SELECT s.*, 
                                        GROUP_CONCAT(d.name SEPARATOR ', ') as available_doctors
                                        FROM services s
                                        LEFT JOIN service_doctors sd ON s.id = sd.service_id
                                        LEFT JOIN doctors d ON sd.doctor_id = d.id AND d.is_active = 1
                                        WHERE s.clinic_id = $clinic_id
                                        GROUP BY s.id
                                        ORDER BY s.category, s.name");

// Get all products
$all_products_query = mysqli_query($conn, "SELECT *, 'product' as type FROM products WHERE clinic_id = $clinic_id ORDER BY category, name");

// ============================================
// ✅ CATEGORIZE PRODUCTS FOR TABS
// ============================================
$eyewear_products = [];
$contact_lens_products = [];

while($prod = mysqli_fetch_assoc($all_products_query)) {
    $cat = $prod['category'] ?? '';
    $is_prescription = isPrescriptionProduct($prod);
    
    // SKIP: Services (handled separately)
    if (in_array($cat, ['Service', 'Eye Exam', 'Treatment', 'Screening'])) {
        continue;
    }
    
    // Skip accessories (not needed for appointments)
    if (in_array($cat, ['Accessories', 'Parts', 'Cleaning Kits'])) {
        continue;
    }
    
    // Skip non-prescription products (e.g., Frames, Sunglasses without Rx)
    if (!$is_prescription) {
        continue;
    }
    
    // Categorize
    if (in_array($cat, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses'])) {
        $eyewear_products[] = $prod;
    } elseif (in_array($cat, ['Contact Lenses', 'Contact Lens'])) {
        $contact_lens_products[] = $prod;
    } else {
        // Fallback
        $eyewear_products[] = $prod;
    }
}

// Get doctors
$doctors_query = mysqli_query($conn, "SELECT * FROM doctors WHERE clinic_id = $clinic_id AND is_active = 1 ORDER BY name");
$doctors_list = [];
while($doc = mysqli_fetch_assoc($doctors_query)) {
    $doc['schedule'] = json_decode($doc['schedule'], true);
    $doctors_list[] = $doc;
}

// Get user avatar
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Navbar counts
$appointments_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$appointments = mysqli_fetch_assoc($appointments_count);
$pending = $appointments['total'] ?? 0;

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

// Set active navigation
$active_nav = 'discover';
$step1_completed = ($selected_item !== null);
$clinic_hours = $clinic['hours'];
$min_date = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+30 days'));

// Get booked slots
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

// Determine active tab from pre-selected item
$active_tab = 'services';
if ($selected_item_type === 'product') {
    $cat = $selected_item['category'] ?? '';
    if (in_array($cat, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses'])) {
        $active_tab = 'eyewear';
    } elseif (in_array($cat, ['Contact Lenses', 'Contact Lens'])) {
        $active_tab = 'contact_lenses';
    }
}

// ============================================
// ✅ AJAX ENDPOINT: Get Product Details (Colors, Sizes)
// ============================================
if (isset($_GET['ajax_get_product_details']) && isset($_GET['product_id'])) {
    header('Content-Type: application/json');
    $product_id = (int)$_GET['product_id'];

    // Get colors
    $colors = [];
    $colors_query = mysqli_query($conn, "
        SELECT color_code, color_name, quantity
        FROM product_color_inventory
        WHERE product_id = $product_id AND is_available = 1
        ORDER BY color_name
    ");
    while ($c = mysqli_fetch_assoc($colors_query)) {
        $colors[] = $c;
    }

    // Get extra fields (for sizes)
    $extra_fields = [];
    $prod_query = mysqli_query($conn, "SELECT extra_fields_json FROM products WHERE id = $product_id");
    $prod_data = mysqli_fetch_assoc($prod_query);
    if ($prod_data && !empty($prod_data['extra_fields_json'])) {
        $extra_fields = json_decode($prod_data['extra_fields_json'], true);
    }
    $sizes = $extra_fields['sizes_available'] ?? [];

    echo json_encode(['colors' => $colors, 'sizes' => $sizes]);
    exit();
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    $item_type_db = mysqli_real_escape_string($conn, $_POST['item_type']);
    $appointment_date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $appointment_time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $doctor_id = $_POST['doctor_id'] === 'any' ? 'NULL' : (int)$_POST['doctor_id'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number']);

    $selected_lens_type = mysqli_real_escape_string($conn, $_POST['selected_lens_type'] ?? '');
    $selected_color_code = mysqli_real_escape_string($conn, $_POST['selected_color_code'] ?? '');
    $selected_color_name = mysqli_real_escape_string($conn, $_POST['selected_color_name'] ?? '');
    $selected_frame_size = mysqli_real_escape_string($conn, $_POST['selected_frame_size'] ?? '');
    
    // Prescription fields
    $prescription_knowledge = mysqli_real_escape_string($conn, $_POST['prescription_knowledge'] ?? '');
    $od_sph = mysqli_real_escape_string($conn, $_POST['od_sph'] ?? '');
    $od_cyl = mysqli_real_escape_string($conn, $_POST['od_cyl'] ?? '');
    $od_axis = mysqli_real_escape_string($conn, $_POST['od_axis'] ?? '');
    $os_sph = mysqli_real_escape_string($conn, $_POST['os_sph'] ?? '');
    $os_cyl = mysqli_real_escape_string($conn, $_POST['os_cyl'] ?? '');
    $os_axis = mysqli_real_escape_string($conn, $_POST['os_axis'] ?? '');

    $selected_service_ids = [];
    $selected_product_id = 0;

    if ($item_type_db === 'service') {
        $selected_service_ids = isset($_POST['service_ids']) ? array_map('intval', $_POST['service_ids']) : [];
    } else {
        $selected_product_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    }

    $hasSelection = ($item_type_db === 'service') ? !empty($selected_service_ids) : $selected_product_id > 0;

    if (!$hasSelection || empty($appointment_date) || empty($appointment_time) || empty($contact_number)) {
        $error_message = 'Please complete all steps before confirming';
    } else {
        $is_product = ($item_type_db === 'product' && $selected_product_id > 0);
        $selected_product_data = null;
        
        if ($is_product) {
            $prod_query = mysqli_query($conn, "SELECT * FROM products WHERE id = $selected_product_id AND clinic_id = $clinic_id");
            $selected_product_data = mysqli_fetch_assoc($prod_query);
        }

        // Past date check
        if (empty($error_message)) {
            $tz = new DateTimeZone('Asia/Manila');
            $now_server = new DateTime('now', $tz);
            $appointment_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $appointment_date . ' ' . $appointment_time, $tz);
            
            if (!$appointment_datetime) {
                $error_message = 'Invalid date or time format.';
            } elseif ($appointment_datetime < $now_server) {
                $error_message = 'You cannot book an appointment in the past. Please select a valid date and time.';
            }
        }

        // Conflict checks
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

        if (empty($error_message) && $doctor_id !== 'NULL' && $item_type_db == 'service') {
            $doctor_query = mysqli_query($conn, "SELECT schedule FROM doctors WHERE id = $doctor_id");
            $doctor = mysqli_fetch_assoc($doctor_query);
            $schedule = json_decode($doctor['schedule'], true);
            $day_of_week = strtolower(date('D', strtotime($appointment_date)));

            if (!isset($schedule[$day_of_week])) {
                $error_message = 'Selected doctor is not available on this date. Please choose another doctor or date.';
            } else {
                $time_parts = explode(':', $appointment_time);
                $selected_time_mins = (int)$time_parts[0] * 60 + (int)$time_parts[1];
                $is_valid_time = false;

                foreach ($schedule[$day_of_week] as $time_range) {
                    $range_parts = explode('-', $time_range);
                    $start_parts = explode(':', $range_parts[0]);
                    $end_parts = explode(':', $range_parts[1]);
                    $start_mins = (int)$start_parts[0] * 60 + (int)($start_parts[1] ?? 0);
                    $end_mins = (int)$end_parts[0] * 60 + (int)($end_parts[1] ?? 0);
                    
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

        // Service combination validation
        $service_rows = [];
        if (empty($error_message) && $item_type_db === 'service' && !empty($selected_service_ids)) {
            $ids_str = implode(',', $selected_service_ids);
            $svc_check = mysqli_query($conn, "SELECT id, name, price, booking_group, max_per_booking FROM services WHERE id IN ($ids_str) AND clinic_id = $clinic_id");
            
            $groupCounts = [];
            $hasStandalone = false;
            
            while ($svcRow = mysqli_fetch_assoc($svc_check)) {
                $service_rows[] = $svcRow;
                $grp = $svcRow['booking_group'];
                if ($grp === 'treatment' || $grp === 'repair' || $grp === null || $grp === '') {
                    $hasStandalone = true;
                }
                $groupCounts[$grp] = ($groupCounts[$grp] ?? 0) + 1;
            }
            
            if ($hasStandalone && count($selected_service_ids) > 1) {
                $error_message = 'Treatment, repair, or standalone services cannot be combined with other services.';
            } else {
                foreach ($groupCounts as $grp => $cnt) {
                    $max_q = mysqli_query($conn, "SELECT max_per_booking FROM services WHERE booking_group = '$grp' AND clinic_id = $clinic_id LIMIT 1");
                    $max_row = mysqli_fetch_assoc($max_q);
                    $maxAllowed = $max_row['max_per_booking'] ?? 1;
                    if ($cnt > $maxAllowed) {
                        $error_message = "Only $maxAllowed service(s) from the '$grp' group can be combined per booking.";
                        break;
                    }
                }
            }
        } elseif (empty($error_message) && $item_type_db === 'service' && !empty($selected_service_ids)) {
            $ids_str = implode(',', $selected_service_ids);
            $svc_check2 = mysqli_query($conn, "SELECT id, name, price, booking_group, max_per_booking FROM services WHERE id IN ($ids_str) AND clinic_id = $clinic_id");
            while ($svcRow2 = mysqli_fetch_assoc($svc_check2)) {
                $service_rows[] = $svcRow2;
            }
        }

        // Proceed with booking
        if (empty($error_message)) {
            // Save to appointments
            if ($item_type_db == 'product') {
                $doctor_id = 'NULL';
            }

            $existingPatientId = null;
            $userCheck = mysqli_query($conn, "SELECT patient_id FROM users WHERE id = $user_id");
            if ($userCheck && mysqli_num_rows($userCheck) > 0) {
                $userData = mysqli_fetch_assoc($userCheck);
                if ($userData['patient_id']) {
                    $existingPatientId = $userData['patient_id'];
                }
            }

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
            $total_price = 0;
            $item_names = [];
            $primary_item_id = 0;

            if ($item_type_db === 'service') {
                foreach ($service_rows as $svc) {
                    $total_price += $svc['price'];
                    $item_names[] = $svc['name'];
                }
                $primary_item_id = $selected_service_ids[0];
            } else {
                $price_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, price FROM products WHERE id = $selected_product_id"));
                $total_price = $price_row['price'] ?? 0;
                $item_names[] = $price_row['name'] ?? 'Product';
                $primary_item_id = $selected_product_id;
            }

            $appointment_notes = $notes;
            if (!empty($selected_lens_type)) $appointment_notes .= ' | Lens: ' . $selected_lens_type;
            if (!empty($selected_color_name)) $appointment_notes .= ' | Color: ' . $selected_color_name;
            if (!empty($selected_frame_size)) $appointment_notes .= ' | Size: ' . $selected_frame_size;
            if (!empty($prescription_knowledge)) {
                $appointment_notes .= ' | Prescription: ' . $prescription_knowledge;
                if ($prescription_knowledge === 'know') {
                    $appointment_notes .= ' | OD: ' . $od_sph . '/' . $od_cyl . 'x' . $od_axis;
                    $appointment_notes .= ' | OS: ' . $os_sph . '/' . $os_cyl . 'x' . $os_axis;
                }
            }

            $insert_query = "INSERT INTO appointments 
                (user_id, patient_id, clinic_id, item_id, item_type, doctor_id, 
                 appointment_date, appointment_time, notes, contact_number, status, is_new_patient, created_at) 
                VALUES ($user_id, " . ($existingPatientId ?: 'NULL') . ", $clinic_id, $primary_item_id, '$item_type_db', " .
                ($doctor_id === 'NULL' ? 'NULL' : $doctor_id) . ", 
                '$appointment_date', '$appointment_time', '$appointment_notes', '$contact_number', 'pending', $isNewPatient, NOW())";

            if (mysqli_query($conn, $insert_query)) {
                $new_appointment_id = mysqli_insert_id($conn);

                if ($item_type_db === 'service' && !empty($service_rows)) {
                    foreach ($service_rows as $svc) {
                        $svc_id = (int)$svc['id'];
                        $svc_price = (float)$svc['price'];
                        mysqli_query($conn, "INSERT INTO appointment_services (appointment_id, service_id, price) 
                                              VALUES ($new_appointment_id, $svc_id, $svc_price)");
                    }
                }

                $formatted_date = date('F j, Y', strtotime($appointment_date));
                $formatted_time = date('g:i A', strtotime($appointment_time));
                $doctor_text = ($doctor_id === 'NULL' || $item_type_db == 'product') ? '' : ' with Dr. ' . $_POST['doctor_name'];
                $item_text = implode(' + ', $item_names);

                if (function_exists('addNotification')) {
                    addNotification($user_id, 'appointment', 'New Appointment Booked',
                        "Your {$item_text} at {$clinic['name']}{$doctor_text} on {$formatted_date} at {$formatted_time} is pending confirmation.",
                        'my-appointments.php'
                    );
                }

                $payment_info = calculatePaymentAmounts($conn, $clinic_id, $total_price);
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
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Book Appointment - <?php echo $clinic['name']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        html, body { margin: 0 !important; padding: 0 !important; width: 100%; overflow-x: hidden; background: var(--bg-primary); }
        body { min-height: 100vh; transition: background-color 0.3s, color 0.3s; }

        :root {
            --primary: #00B761; --primary-dark: #00994D; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 999px;
            --danger: #FF4444; --warning: #FF8C42; --info: #17A2B8; --success: #00B761;
            --open-bg: #d4edda; --open-text: #28a745; --closed-bg: #f8d7da; --closed-text: #721c24;
            --input-bg: var(--bg-primary); --input-border: var(--border-color);
            --input-focus: var(--primary); --disabled-bg: var(--bg-secondary);
            --break-bg: #fff3cd; --break-color: #856404; --booked-bg: #f8d7da; --booked-color: #721c24;
            --calendar-available: #d4edda; --calendar-unavailable: #f8d7da;
            --calendar-partial: #fff3cd; --calendar-today: #cce5ff;
            --calendar-selected: var(--primary-gradient);
        }

        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
            --open-bg: #2d4a2d; --open-text: #7ac97a; --closed-bg: #5a2d2d; --closed-text: #ff9999;
            --break-bg: #5a4c2d; --break-color: #ffd966; --booked-bg: #5a2d2d; --booked-color: #ff9999;
            --calendar-available: #1e4a1e; --calendar-unavailable: #4a2d2d;
            --calendar-partial: #5a4c2d; --calendar-today: #1e4a5a;
            --calendar-selected: var(--primary-gradient);
        }

        h1, h2, h3, h4, h5, h6, p { margin: 0; }

        .loading-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; display: flex; align-items: center; justify-content: center; }
        .loading-spinner-large { width: 50px; height: 50px; border: 4px solid var(--border-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

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

        .main-content { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
        @media (min-width: 1024px) { .main-content { padding: 30px 40px; } }
        @media (max-width: 768px) { .main-content { padding: 20px 16px 100px; } }

        .back-button { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .back-button a { color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 14px; transition: all 0.2s; padding: 8px 16px; background: var(--bg-secondary); border-radius: var(--radius-full); border: 1px solid var(--border-light); }
        .back-button a:hover { background: var(--primary); color: white; transform: translateX(-3px); }
        .back-button span { color: var(--text-secondary); font-size: 14px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 28px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 12px; }
        .page-header h1 i { color: var(--primary); background: var(--primary-light); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-full); font-size: 24px; }
        .clinic-badge { background: var(--bg-secondary); padding: 12px 24px; border-radius: 30px; border: 1px solid var(--border-light); display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; color: var(--text-secondary); box-shadow: var(--shadow-sm); }
        .clinic-badge i { color: var(--primary); }
        .clinic-badge span { font-weight: 600; color: var(--primary); margin-right: 4px; }

        .booking-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }
        .booking-main { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 25px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); }

        .product-preview { display: flex; gap: 25px; padding: 20px; background: var(--primary-light); border: 2px solid var(--primary); border-radius: var(--radius-lg); margin-bottom: 30px; align-items: center; }
        .product-preview-image { width: 100px; height: 100px; background: var(--bg-secondary); border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-md); border: 3px solid white; flex-shrink: 0; }
        .product-preview-image img { width: 100%; height: 100%; object-fit: cover; }
        .product-preview-image .placeholder { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--bg-primary); color: var(--primary); }
        .product-preview-image .placeholder i { font-size: 30px; margin-bottom: 5px; }
        .product-preview-image .placeholder span { font-size: 10px; color: var(--text-muted); }
        .product-preview-details { flex: 1; }
        .product-preview-category { font-size: 13px; color: var(--primary); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 5px; }
        .product-preview-name { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
        .product-preview-price { font-size: 24px; font-weight: 700; color: var(--success); }
        .product-preview-badge { background: var(--success); color: white; padding: 4px 12px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; display: inline-block; margin-top: 8px; }

        .booking-progress { display: flex; justify-content: space-between; margin-bottom: 30px; position: relative; background: var(--bg-primary); border-radius: var(--radius-lg); padding: 15px; border: 1px solid var(--border-light); }
        .booking-progress::before { content: ''; position: absolute; top: 35px; left: 60px; right: 60px; height: 2px; background: var(--border-color); z-index: 1; }
        .progress-step { position: relative; z-index: 2; background: var(--bg-primary); padding: 0 10px; text-align: center; flex: 1; cursor: pointer; }
        .progress-step.disabled { cursor: not-allowed; opacity: 0.5; pointer-events: none; }
        .step-number { width: 50px; height: 50px; background: var(--bg-secondary); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-weight: 700; color: var(--text-secondary); transition: all 0.2s; border: 2px solid var(--border-light); }
        .progress-step.active .step-number { background: var(--primary-gradient); color: white; border-color: transparent; }
        .progress-step.completed .step-number { background: var(--success); color: white; border-color: transparent; }
        .step-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .progress-step.active .step-label { color: var(--primary); }
        .progress-step.completed .step-label { color: var(--success); }

        .step-section { margin-bottom: 30px; padding: 20px; background: var(--bg-primary); border-radius: var(--radius-md); border: 1px solid var(--border-light); transition: all 0.2s; }
        .step-section.active { border-left: 4px solid var(--primary); }
        .step-section.completed { border-left: 4px solid var(--success); }
        .step-section.locked { opacity: 0.7; background: var(--disabled-bg); }
        .step-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .step-header h3 { font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0; flex: 1; }
        .step-header h3 i { color: var(--primary); margin-right: 8px; }
        .step-status-badge { padding: 4px 12px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; }
        .status-pending { background: var(--warning); color: white; }
        .status-completed { background: var(--success); color: white; }
        .status-locked { background: var(--text-muted); color: white; }
        .disabled-content { opacity: 0.5; pointer-events: none; }

        .main-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 4px; flex-wrap: wrap; }
        .main-tab-btn { padding: 10px 20px; background: transparent; border: none; border-radius: var(--radius-md) var(--radius-md) 0 0; cursor: pointer; font-weight: 600; color: var(--text-secondary); transition: all 0.2s; font-size: 14px; display: flex; align-items: center; gap: 6px; border-bottom: 3px solid transparent; }
        .main-tab-btn i { font-size: 16px; }
        .main-tab-btn:hover { color: var(--primary); background: var(--primary-light); }
        .main-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); background: transparent; }
        .main-tab-btn .tab-badge { background: var(--bg-primary); color: var(--text-muted); font-size: 10px; padding: 1px 6px; border-radius: var(--radius-full); margin-left: 4px; }
        .main-tab-btn.active .tab-badge { background: var(--primary-light); color: var(--primary); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
        .service-card { background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px; border: 1px solid var(--border-light); transition: all 0.2s; cursor: pointer; text-align: center; position: relative; }
        .service-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .service-card.selected { border-color: var(--primary); background: var(--primary-light); }
        .service-card input[type="checkbox"] { position: absolute; top: 10px; right: 10px; width: 18px; height: 18px; cursor: pointer; }
        .service-card .doctor-avatar { width: 60px; height: 60px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; color: var(--primary); font-size: 28px; }
        .service-card .doctor-name { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .service-card .doctor-specialty { font-size: 12px; color: var(--text-secondary); margin-bottom: 6px; }
        .service-card .product-price { font-size: 16px; font-weight: 700; color: var(--success); }
        .service-card .doctor-schedule-badge { display: inline-block; padding: 4px 10px; background: var(--bg-primary); color: var(--primary); border-radius: var(--radius-full); font-size: 10px; font-weight: 600; margin-top: 4px; }
        .service-card .service-group-badge { display: inline-block; padding: 2px 8px; background: var(--secondary-light); color: var(--secondary); border-radius: var(--radius-full); font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }
        .service-card.blocked { opacity: 0.4; cursor: not-allowed; background: var(--disabled-bg); }
        .service-card.blocked input[type="checkbox"] { cursor: not-allowed; }

        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-top: 10px; }
        .product-card { background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px; border: 1px solid var(--border-light); transition: all 0.2s; cursor: pointer; text-align: center; }
        .product-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .product-card.selected { border-color: var(--primary); background: var(--primary-light); }
        .product-card .product-img-thumb { width: 70px; height: 70px; border-radius: var(--radius-sm); margin: 0 auto 12px; overflow: hidden; border: 1px solid var(--border-light); background: var(--bg-primary); display: flex; align-items: center; justify-content: center; }
        .product-card .product-img-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-card .product-img-thumb .img-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--primary-light); color: var(--primary); font-size: 24px; }
        .product-card .product-name { font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .product-card .product-category { font-size: 11px; color: var(--text-muted); margin-bottom: 4px; }
        .product-card .product-price { font-size: 15px; font-weight: 700; color: var(--success); }

        .lens-selection-box {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 20px 0;
            border: 1px solid var(--border-light);
            display: none;
        }
        .lens-selection-box.visible { display: block; animation: fadeIn 0.3s ease; }
        .lens-options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
        @media (max-width: 500px) { .lens-options-grid { grid-template-columns: 1fr; } }
        .lens-option-btn {
            padding: 14px 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            cursor: pointer;
            transition: all 0.2s;
            text-align: left;
            font-family: inherit;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .lens-option-btn:hover { border-color: var(--primary); background: var(--primary-light); }
        .lens-option-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
        .lens-option-btn .ln { font-weight: 700; font-size: 14px; color: var(--text-primary); }
        .lens-option-btn .lp { font-size: 12px; color: var(--primary); font-weight: 600; }
        .lens-option-btn .ld { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        .color-size-selector {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 16px;
            border: 1px solid var(--border-light);
            display: none;
        }
        .color-size-selector.visible { display: block; }
        .cs-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 14px;
        }
        .cs-title i { color: var(--primary); }
        .cs-selected-label { font-weight: 500; color: var(--text-secondary); font-size: 13px; margin-left: auto; }
        .cs-selected-label.chosen { color: var(--primary); font-weight: 600; }
        .color-btns, .size-btns { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        .color-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .color-btn:hover:not(.oos) { border-color: var(--primary); background: var(--primary-light); }
        .color-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
        .color-btn.oos { opacity: 0.45; cursor: not-allowed; border-style: dashed; }
        .color-dot { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid rgba(0,0,0,0.15); flex-shrink: 0; }
        .color-btn-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .color-btn-qty { font-size: 11px; color: var(--text-muted); margin-left: 2px; }
        .color-btn-qty.low { color: var(--warning); font-weight: 600; }
        .color-btn-qty.oos-label { color: var(--danger); font-weight: 600; }
        .size-btn {
            padding: 8px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
        }
        .size-btn:hover, .size-btn.selected { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
        .cs-hint { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
        .cs-hint i { color: var(--primary); }

        .clinic-visit-required {
            background: #FEF3C7;
            border: 1px solid #FDE68A;
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 16px;
            display: none;
            align-items: flex-start;
            gap: 12px;
        }
        .clinic-visit-required.visible { display: flex; }
        .clinic-visit-required i { color: var(--warning); font-size: 20px; flex-shrink: 0; }
        .theme-dark .clinic-visit-required { background: #3B2F0F; border-color: #7F6B1D; }

        /* ============================================ */
        /* ✅ RX SECTION - From product-view */
        /* ============================================ */
        .rx-toggle {
            display: flex;
            gap: 12px;
            margin: 16px 0 10px;
            flex-wrap: wrap;
        }
        .rx-option {
            flex: 1;
            min-width: 140px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            background: var(--bg-primary);
            transition: all 0.2s;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .rx-option input[type="radio"] { display: none; }
        .rx-option:has(input:checked) {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary);
        }
        .rx-option .icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .rx-option:has(input:checked) .icon { background: var(--primary); color: white; }

        .rx-form {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 18px;
            margin-top: 12px;
            border: 1px solid var(--border-light);
        }
        .rx-form h4 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .rx-eyes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        @media (max-width: 500px) { .rx-eyes { grid-template-columns: 1fr; } }

        .rx-eye-box {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 12px;
            border: 1px solid var(--border-light);
        }
        .rx-eye-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .rx-eye-label span {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
        }
        .rx-inputs { display: flex; gap: 6px; }
        .rx-input-group { flex: 1; }
        .rx-input-group label { 
            font-size: 9px; 
            color: var(--text-muted); 
            text-transform: uppercase; 
            display: block; 
            margin-bottom: 3px; 
            font-weight: 600; 
            letter-spacing: 0.5px; 
        }
        .rx-input-group input {
            width: 100%;
            padding: 8px 6px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 13px;
            text-align: center;
            transition: border-color 0.2s;
        }
        .rx-input-group input:focus { outline: none; border-color: var(--primary); }

        .rx-note {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 12px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .rx-note i { color: var(--primary); }

        .eye-exam-box {
            background: var(--primary-light);
            border: 1px solid rgba(0,183,97,0.2);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 12px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .eye-exam-box i { font-size: 28px; color: var(--primary); }
        .eye-exam-box p { font-size: 13px; color: var(--text-secondary); line-height: 1.5; }

        .pagination-container { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; padding: 10px 0; flex-wrap: wrap; }
        .pagination-container button { padding: 6px 12px; border: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: var(--radius-md); cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.2s; color: var(--text-secondary); min-width: 32px; }
        .pagination-container button:hover:not(:disabled) { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }
        .pagination-container button.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination-container button:disabled { opacity: 0.4; cursor: not-allowed; }

        .doctors-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }
        .doctor-card { background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px; transition: all 0.2s; border: 2px solid transparent; cursor: pointer; position: relative; text-align: center; border: 1px solid var(--border-light); }
        .doctor-card:hover:not(.no-schedule) { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .doctor-card.selected { border-color: var(--primary); background: var(--primary-light); }
        .doctor-card.no-schedule { opacity: 0.6; cursor: not-allowed; background: var(--bg-primary); }
        .doctor-card input[type="radio"] { position: absolute; opacity: 0; }
        .doctor-avatar { width: 70px; height: 70px; border-radius: 50%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: white; font-size: 28px; }
        .any-doctor-card .doctor-avatar { background: var(--success); }
        .doctor-name { font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .doctor-specialty { font-size: 12px; color: var(--text-secondary); margin-bottom: 8px; }
        .doctor-schedule-badge { display: inline-block; padding: 4px 10px; background: var(--bg-primary); color: var(--primary); border-radius: var(--radius-full); font-size: 10px; font-weight: 600; }
        .any-doctor-card .doctor-schedule-badge { background: var(--success); color: white; }

        .calendar-container { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 20px; margin-top: 15px; border: 1px solid var(--border-light); }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .calendar-header h4 { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .calendar-nav-btn { width: 36px; height: 36px; border: none; background: var(--bg-primary); border-radius: var(--radius-full); cursor: pointer; color: var(--text-secondary); transition: all 0.2s; font-size: 14px; }
        .calendar-nav-btn:hover { background: var(--primary); color: white; }

        .calendar-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: 600; font-size: 12px; color: var(--text-secondary); margin-bottom: 10px; }
        .calendar-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .calendar-day { aspect-ratio: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--bg-primary); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s; position: relative; font-size: 14px; font-weight: 500; border: 2px solid transparent; }
        .calendar-day:hover:not(.empty):not(.unavailable) { transform: translateY(-2px); box-shadow: var(--shadow-sm); border-color: var(--primary); }
        .calendar-day.available { background: var(--calendar-available); color: var(--success); border: 2px solid var(--success); }
        .calendar-day.unavailable { background: var(--calendar-unavailable); color: var(--danger); opacity: 0.5; cursor: not-allowed; border: 2px solid var(--danger); }
        .calendar-day.partial { background: var(--calendar-partial); color: var(--warning); border: 2px solid var(--warning); }
        .calendar-day.today { border: 2px solid var(--info); background: var(--calendar-today); color: var(--info); }
        .calendar-day.selected { background: var(--primary-gradient); color: white; border-color: transparent; }
        .calendar-day.empty { background: transparent; cursor: default; border: none; }

        .time-slots-container { margin-top: 20px; }
        .time-slots-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .time-slots-header h4 { font-size: 15px; font-weight: 600; color: var(--text-primary); }
        .time-slots-header span { font-size: 13px; color: var(--text-secondary); }
        .time-slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; }
        .time-slot-card { padding: 12px 8px; text-align: center; border: 1px solid var(--border-color); border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s; font-size: 13px; font-weight: 500; position: relative; background: var(--bg-secondary); color: var(--text-primary); }
        .time-slot-card:hover:not(.disabled):not(.booked):not(.unavailable) { border-color: var(--primary); background: var(--primary-light); transform: translateY(-2px); }
        .time-slot-card.selected { background: var(--primary-gradient); color: white; border-color: transparent; }
        .time-slot-card.available { border-color: var(--success); }
        .time-slot-card.disabled { background: var(--break-bg); color: var(--break-color); border-color: var(--border-color); cursor: not-allowed; pointer-events: none; opacity: 0.7; }
        .time-slot-card.booked { background: var(--booked-bg); color: var(--booked-color); border-color: var(--danger); cursor: not-allowed; pointer-events: none; opacity: 0.7; }
        .time-slot-card.unavailable { background: var(--unavailable-bg); color: var(--unavailable-color); border-color: var(--danger); cursor: not-allowed; pointer-events: none; opacity: 0.7; }
        .no-slots-message { grid-column: 1 / -1; text-align: center; padding: 30px; background: var(--bg-primary); border-radius: var(--radius-md); color: var(--text-muted); }
        .no-slots-message i { font-size: 40px; color: var(--text-muted); margin-bottom: 10px; opacity: 0.5; }
        .no-slots-message h4 { font-size: 16px; color: var(--text-primary); margin-bottom: 5px; }
        .no-slots-message p { font-size: 13px; }

        .calendar-legend { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-light); }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-secondary); }
        .legend-color { width: 16px; height: 16px; border-radius: 4px; }
        .legend-color.available { background: var(--calendar-available); border: 2px solid var(--success); }
        .legend-color.partial { background: var(--calendar-partial); border: 2px solid var(--warning); }
        .legend-color.unavailable { background: var(--calendar-unavailable); border: 2px solid var(--danger); }
        .legend-color.today { background: var(--calendar-today); border: 2px solid var(--info); }
        .legend-color.selected { background: var(--primary-gradient); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-weight: 600; font-size: 13px; }
        .form-group label i { color: var(--primary); margin-right: 6px; }
        .form-control { width: 100%; padding: 12px 15px; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 14px; transition: all 0.2s; background: var(--input-bg); color: var(--text-primary); }
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px var(--primary-light); }
        .form-control[readonly] { background: var(--disabled-bg); cursor: not-allowed; }

        .alert { padding: 15px 20px; border-radius: var(--radius-md); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: var(--open-bg); color: var(--open-text); border: 1px solid var(--open-text); }
        .alert-error { background: var(--closed-bg); color: var(--closed-text); border: 1px solid var(--closed-text); }

        .break-info { background: var(--break-bg); border-left: 4px solid var(--warning); padding: 15px; border-radius: var(--radius-md); margin: 20px 0; font-size: 13px; }
        .break-info i { color: var(--warning); margin-right: 8px; }
        .break-info ul { margin-top: 10px; padding-left: 25px; color: var(--break-color); }
        .break-info li { margin-bottom: 5px; }

        .daily-limit-warning { background: var(--break-bg); color: var(--break-color); padding: 10px 15px; border-radius: var(--radius-md); margin-top: 10px; display: flex; align-items: center; gap: 8px; font-size: 13px; border-left: 4px solid var(--warning); }

        .booking-sidebar { background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 25px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); height: fit-content; position: sticky; top: 20px; }
        .clinic-info-sidebar { display: flex; align-items: flex-start; gap: 15px; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border-light); }
        .clinic-info-sidebar > i:first-child { font-size: 40px; color: var(--primary); background: var(--primary-light); width: 60px; height: 60px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .clinic-info-sidebar h4 { font-size: 16px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px; }
        .clinic-info-sidebar p { color: var(--text-secondary); font-size: 13px; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .clinic-info-sidebar p i { font-size: 12px; color: var(--primary); width: 16px; }

        .flow-indicator { background: var(--bg-primary); border-radius: var(--radius-md); padding: 15px; margin-bottom: 20px; }

        .summary-card { background: var(--bg-primary); border-radius: var(--radius-md); padding: 20px; margin: 20px 0; }
        .summary-card h4 { font-size: 16px; font-weight: 600; color: var(--text-primary); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .summary-card h4 i { color: var(--primary); }
        .summary-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-light); font-size: 13px; }
        .summary-item:last-child { border-bottom: none; }
        .summary-label { color: var(--text-secondary); font-weight: 500; }
        .summary-value { color: var(--text-primary); font-weight: 600; }
        .summary-services-list { padding: 10px 0; border-bottom: 1px solid var(--border-light); }
        .summary-services-list .summary-label { display: block; margin-bottom: 6px; }
        .summary-service-row { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-primary); padding: 3px 0; }
        .total-price { font-size: 24px; font-weight: 700; color: var(--success); margin-top: 15px; text-align: right; }

        .btn-confirm { width: 100%; padding: 15px; background: var(--primary-gradient); color: white; border: none; border-radius: var(--radius-md); font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 20px; }
        .btn-confirm:hover:not(:disabled) { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

        .quick-info { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-light); }
        .quick-info p { color: var(--text-secondary); font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .quick-info i { color: var(--primary); width: 18px; font-size: 12px; }

        .success-container { grid-column: 1 / -1; background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 60px 40px; text-align: center; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); }
        .success-container i { font-size: 80px; color: var(--success); margin-bottom: 20px; }
        .success-container h2 { font-size: 28px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; }
        .success-container p { color: var(--text-secondary); margin-bottom: 30px; max-width: 500px; margin-left: auto; margin-right: auto; }
        .success-actions { display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; }
        .btn-primary { padding: 12px 30px; background: var(--primary-gradient); color: white; border: none; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-secondary { padding: 12px 30px; background: transparent; color: var(--primary); border: 2px solid var(--primary); border-radius: var(--radius-full); font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }

        .info-card { grid-column: 1 / -1; background: var(--bg-secondary); border-radius: var(--radius-lg); padding: 25px; margin-top: 25px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm); }
        .info-card h2 { font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .info-card h2 i { color: var(--primary); }
        .info-card ul { color: var(--text-secondary); font-size: 14px; line-height: 1.8; padding-left: 20px; }
        .info-card li { margin-bottom: 8px; }

        .service-combine-note { background: var(--primary-light); border-left: 4px solid var(--primary); padding: 12px 15px; border-radius: var(--radius-md); margin-bottom: 15px; font-size: 13px; color: var(--text-secondary); }
        .service-combine-note i { color: var(--primary); margin-right: 6px; }
        .clinic-visit-notice { padding: 12px 16px; background: var(--primary-light); border-radius: var(--radius-md); margin-top: 12px; border-left: 4px solid var(--warning); display: flex; align-items: flex-start; gap: 10px; display: none; }
        .clinic-visit-notice i { color: var(--warning); font-size: 18px; margin-top: 2px; }

        @media (max-width: 992px) { .booking-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .booking-progress::before { left: 30px; right: 30px; }
            .product-preview { flex-direction: column; text-align: center; }
            .product-preview-image { width: 120px; height: 120px; margin: 0 auto; }
            .doctors-grid { grid-template-columns: 1fr; }
            .clinic-info-sidebar { flex-direction: column; text-align: center; }
            .calendar-days { gap: 3px; }
            .calendar-day { font-size: 12px; }
            .time-slots-grid { grid-template-columns: repeat(2, 1fr); }
            .success-actions { flex-direction: column; }
            .products-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .main-tab-btn { font-size: 12px; padding: 6px 12px; }
            .rx-eyes { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="loading-spinner-large"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <?php include '../includes/navbar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-plus"></i>
                Book Appointment
            </h1>
            <div class="clinic-badge">
                <i class="fas fa-clinic-medical"></i> <span><?php echo $clinic['name']; ?></span>
            </div>
        </div>

        <div class="back-button">
            <a href="clinic-details.php?id=<?php echo $clinic_id; ?>">
                <i class="fas fa-arrow-left"></i> Back to Clinic
            </a>
            <span><?php echo $clinic['name']; ?></span>
        </div>

        <?php if ($success_message): ?>
            <div class="booking-grid">
                <div class="success-container">
                    <i class="fas fa-check-circle"></i>
                    <h2>Appointment Confirmed!</h2>
                    <p>Your appointment has been successfully scheduled.</p>
                    <div class="success-actions">
                        <a href="my-appointments.php" class="btn-primary"><i class="fas fa-calendar-check"></i> View Appointments</a>
                        <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-secondary"><i class="fas fa-store"></i> Back to Clinic</a>
                    </div>
                </div>
            </div>
        <?php else: ?>

        <div class="booking-grid">
            <div class="booking-main">
                <?php if ($selected_item): ?>
                <div class="product-preview">
                    <div class="product-preview-image">
                        <?php if ($selected_item_type == 'service'): ?>
                            <div class="placeholder"><i class="fas fa-stethoscope"></i><span>Service</span></div>
                        <?php else: ?>
                            <div class="placeholder"><i class="fas fa-box-open"></i><span>Product</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="product-preview-details">
                        <div class="product-preview-category"><?php echo $selected_item['category']; ?> (<?php echo $selected_item_type == 'service' ? 'Service' : 'Product'; ?>)</div>
                        <div class="product-preview-name"><?php echo $selected_item['name']; ?></div>
                        <div class="product-preview-price">₱<?php echo number_format($selected_item['price'], 2); ?></div>
                        <div class="product-preview-badge"><i class="fas fa-check-circle"></i> Selected</div>
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

                <!-- ERROR - ONLY SHOW ON POST -->
                <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($error_message)): ?>
                    <div class="alert alert-error" id="errorAlert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="bookingForm">
                    <input type="hidden" name="doctor_name" id="selectedDoctorName" value="">
                    <input type="hidden" name="item_type" id="selectedItemType" value="<?php echo $selected_item_type; ?>">
                    <input type="hidden" name="selected_lens_type" id="selectedLensType" value="">
                    <input type="hidden" name="selected_color_code" id="selectedColorCode" value="">
                    <input type="hidden" name="selected_color_name" id="selectedColorName" value="">
                    <input type="hidden" name="selected_frame_size" id="selectedFrameSize" value="">
                    <input type="hidden" name="item_id" id="selectedItemId" value="<?php echo $selected_item_id; ?>">
                    
                    <!-- Prescription fields -->
                    <input type="hidden" name="prescription_knowledge" id="prescriptionKnowledge" value="">
                    <input type="hidden" name="od_sph" id="od_sph_hidden" value="">
                    <input type="hidden" name="od_cyl" id="od_cyl_hidden" value="">
                    <input type="hidden" name="od_axis" id="od_axis_hidden" value="">
                    <input type="hidden" name="os_sph" id="os_sph_hidden" value="">
                    <input type="hidden" name="os_cyl" id="os_cyl_hidden" value="">
                    <input type="hidden" name="os_axis" id="os_axis_hidden" value="">
                    
                    <!-- STEP 1 -->
                    <div id="step1" class="step-section <?php echo $step1_completed ? 'completed' : 'active'; ?>">
                        <div class="step-header">
                            <h3><i class="fas fa-box"></i> Select Service or Product</h3>
                            <span class="step-status-badge <?php echo $step1_completed ? 'status-completed' : 'status-pending'; ?>" id="step1-status">
                                <?php echo $step1_completed ? '✓ Completed' : 'Required'; ?>
                            </span>
                        </div>

                        <div class="main-tabs" id="mainTabs">
                            <?php if (mysqli_num_rows($services_query) > 0): ?>
                            <button type="button" class="main-tab-btn <?php echo $active_tab === 'services' ? 'active' : ''; ?>" data-tab="services" onclick="switchMainTab('services')">
                                <i class="fas fa-stethoscope"></i> Services <span class="tab-badge"><?php echo mysqli_num_rows($services_query); ?></span>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($eyewear_products)): ?>
                            <button type="button" class="main-tab-btn <?php echo $active_tab === 'eyewear' ? 'active' : ''; ?>" data-tab="eyewear" onclick="switchMainTab('eyewear')">
                                <i class="fas fa-glasses"></i> Eyewear <span class="tab-badge"><?php echo count($eyewear_products); ?></span>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($contact_lens_products)): ?>
                            <button type="button" class="main-tab-btn <?php echo $active_tab === 'contact_lenses' ? 'active' : ''; ?>" data-tab="contact_lenses" onclick="switchMainTab('contact_lenses')">
                                <i class="fas fa-eye"></i> Contact Lenses <span class="tab-badge"><?php echo count($contact_lens_products); ?></span>
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- SERVICES TAB -->
                        <?php if (mysqli_num_rows($services_query) > 0): ?>
                        <div id="tab-services" class="tab-content <?php echo $active_tab === 'services' ? 'active' : ''; ?>">
                            <div class="service-combine-note">
                                <i class="fas fa-info-circle"></i>
                                You can select more than one service. Exam, screening, and fitting services can be combined — treatment and repair services must be booked alone.
                            </div>
                            <div class="services-grid" id="servicesGrid">
                                <?php 
                                mysqli_data_seek($services_query, 0);
                                if (mysqli_num_rows($services_query) > 0):
                                    while($service = mysqli_fetch_assoc($services_query)): 
                                        $svc_group = $service['booking_group'] ?? '';
                                        $svc_max = $service['max_per_booking'] ?? 1;
                                        $is_preselected = ($selected_item && $selected_item['id'] == $service['id'] && $selected_item_type == 'service');
                                ?>
                                <label class="service-card <?php echo $is_preselected ? 'selected' : ''; ?>" data-service-id="<?php echo $service['id']; ?>">
                                    <input type="checkbox" name="service_ids[]" value="<?php echo $service['id']; ?>" 
                                           class="service-checkbox"
                                           data-group="<?php echo htmlspecialchars($svc_group); ?>" 
                                           data-max="<?php echo (int)$svc_max; ?>"
                                           data-price="<?php echo $service['price']; ?>"
                                           data-name="<?php echo htmlspecialchars($service['name']); ?>"
                                           <?php echo $is_preselected ? 'checked' : ''; ?>
                                           onchange="handleServiceSelection(this)">
                                    <div class="doctor-avatar" style="background: var(--primary-light); color: var(--primary);">
                                        <i class="fas fa-stethoscope"></i>
                                    </div>
                                    <div class="doctor-name"><?php echo $service['name']; ?></div>
                                    <div class="doctor-specialty"><?php echo $service['category']; ?></div>
                                    <div class="product-price">₱<?php echo number_format($service['price'], 2); ?></div>
                                    <span class="doctor-schedule-badge"><i class="fas fa-clock"></i> <?php echo $service['duration_minutes'] ?? '30'; ?> mins</span>
                                    <?php if ($svc_group): ?>
                                    <div class="service-group-badge"><?php echo htmlspecialchars($svc_group); ?></div>
                                    <?php else: ?>
                                    <div class="service-group-badge" style="background:#f8d7da;color:#721c24;">standalone</div>
                                    <?php endif; ?>
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
                            <div class="pagination-container" id="servicesPagination"></div>
                        </div>
                        <?php endif; ?>

                        <!-- EYEWEAR TAB -->
                        <?php if (!empty($eyewear_products)): ?>
                        <div id="tab-eyewear" class="tab-content <?php echo $active_tab === 'eyewear' ? 'active' : ''; ?>">
                            <div class="products-grid" id="eyewearGrid"></div>
                            <div class="pagination-container" id="eyewearPagination"></div>

                            <div id="eyewearLensSelection" class="lens-selection-box">
                                <div class="lens-options-grid" id="eyewearLensOptions">
                                    <button type="button" class="lens-option-btn" data-lens="single_vision" onclick="selectEyewearLens(this)"><span class="ln">Single Vision</span><span class="lp">+₱500</span><span class="ld">For near or far sight</span></button>
                                    <button type="button" class="lens-option-btn" data-lens="progressive" onclick="selectEyewearLens(this)"><span class="ln">Progressive</span><span class="lp">+₱1,500</span><span class="ld">Near, mid & far vision</span></button>
                                    <button type="button" class="lens-option-btn" data-lens="blue_cut" onclick="selectEyewearLens(this)"><span class="ln">Blue Cut</span><span class="lp">+₱800</span><span class="ld">Reduces screen glare</span></button>
                                </div>

                                <!-- Color & Size Selector -->
                                <div id="eyewearColorSize" class="color-size-selector">
                                    <div class="cs-title"><i class="fas fa-palette"></i> Color / Variant <span class="cs-selected-label" id="eyewearColorSelected">— Select a color</span></div>
                                    <div class="color-btns" id="eyewearColorBtns"></div>
                                    <p class="cs-hint" id="eyewearColorHint"><i class="fas fa-info-circle"></i> Please select a color to continue.</p>
                                    <div style="margin-top:16px; border-top:1px solid var(--border-light); padding-top:16px;">
                                        <div class="cs-title"><i class="fas fa-ruler-combined"></i> Frame Size <span class="cs-selected-label" id="eyewearSizeSelected">— Select a size</span></div>
                                        <div class="size-btns" id="eyewearSizeBtns"></div>
                                        <p class="cs-hint" id="eyewearSizeHint"><i class="fas fa-info-circle"></i> Please select your preferred frame size.</p>
                                    </div>
                                </div>

                                <!-- Clinic Visit Required Notice -->
                                <div id="eyewearClinicVisit" class="clinic-visit-required visible">
                                    <i class="fas fa-info-circle"></i>
                                    <div><strong>Clinic Visit Required</strong><br>Please visit the clinic on your scheduled appointment. Your prescription will be verified by the optometrist.</div>
                                </div>

                                <!-- ============================================ -->
                                <!-- ✅ PRESCRIPTION SECTION -->
                                <!-- ============================================ -->
                                <div id="rxSection" style="display: none; margin-top: 16px;">
                                    <div style="font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-prescription" style="color: var(--primary);"></i> Your Prescription
                                    </div>

                                    <div class="rx-toggle">
                                        <label class="rx-option">
                                            <input type="radio" name="rx_know" value="know" onchange="handleRxKnowledge(this)">
                                            <div class="icon"><i class="fas fa-check"></i></div>
                                            <span>I know my prescription</span>
                                        </label>
                                        <label class="rx-option">
                                            <input type="radio" name="rx_know" value="dont_know" onchange="handleRxKnowledge(this)">
                                            <div class="icon"><i class="fas fa-question"></i></div>
                                            <span>I need an eye exam</span>
                                        </label>
                                    </div>

                                    <div id="rxFormBox" class="rx-form" style="display:none;">
                                        <h4><i class="fas fa-edit" style="color:var(--primary)"></i> Enter Your Prescription</h4>
                                        <div class="rx-eyes">
                                            <div class="rx-eye-box">
                                                <div class="rx-eye-label">Right Eye <span>OD</span></div>
                                                <div class="rx-inputs">
                                                    <div class="rx-input-group">
                                                        <label>SPH</label>
                                                        <input type="text" id="od_sph" placeholder="e.g. -1.50" oninput="updatePrescriptionFields()">
                                                    </div>
                                                    <div class="rx-input-group">
                                                        <label>CYL</label>
                                                        <input type="text" id="od_cyl" placeholder="e.g. -0.50" oninput="updatePrescriptionFields()">
                                                    </div>
                                                    <div class="rx-input-group">
                                                        <label>AXIS</label>
                                                        <input type="text" id="od_axis" placeholder="e.g. 180" oninput="updatePrescriptionFields()">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="rx-eye-box">
                                                <div class="rx-eye-label">Left Eye <span>OS</span></div>
                                                <div class="rx-inputs">
                                                    <div class="rx-input-group">
                                                        <label>SPH</label>
                                                        <input type="text" id="os_sph" placeholder="e.g. -1.25" oninput="updatePrescriptionFields()">
                                                    </div>
                                                    <div class="rx-input-group">
                                                        <label>CYL</label>
                                                        <input type="text" id="os_cyl" placeholder="e.g. -0.25" oninput="updatePrescriptionFields()">
                                                    </div>
                                                    <div class="rx-input-group">
                                                        <label>AXIS</label>
                                                        <input type="text" id="os_axis" placeholder="e.g. 175" oninput="updatePrescriptionFields()">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="rx-note">
                                            <i class="fas fa-shield-alt"></i>
                                            Your prescription will be verified by the optometrist upon visit.
                                        </p>
                                    </div>

                                    <div id="rxExamBox" class="eye-exam-box" style="display:none;">
                                        <i class="fas fa-calendar-check"></i>
                                        <strong style="color:var(--primary)">Eye Exam Required</strong>
                                        <p>We'll schedule an appointment for you. Our optometrist will check your vision and recommend the right prescription for your lenses.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- CONTACT LENSES TAB -->
                        <?php if (!empty($contact_lens_products)): ?>
                        <div id="tab-contact_lenses" class="tab-content <?php echo $active_tab === 'contact_lenses' ? 'active' : ''; ?>">
                            <div class="products-grid" id="contactLensGrid"></div>
                            <div class="pagination-container" id="contactLensPagination"></div>

                            <div id="contactLensSelection" class="lens-selection-box">
                                <div class="lens-options-grid">
                                    <button type="button" class="lens-option-btn selected" data-lens="daily" onclick="selectContactLens(this)"><span class="ln">Daily Disposable</span><span class="lp">Included</span><span class="ld">Fresh pair every day</span></button>
                                    <button type="button" class="lens-option-btn" data-lens="monthly" onclick="selectContactLens(this)"><span class="ln">Monthly Wear</span><span class="lp">Included</span><span class="ld">Reusable for 30 days</span></button>
                                </div>

                                <div id="contactLensColorSize" class="color-size-selector">
                                    <div class="cs-title"><i class="fas fa-palette"></i> Color / Variant <span class="cs-selected-label" id="clColorSelected">— Select a color</span></div>
                                    <div class="color-btns" id="contactLensColorBtns"></div>
                                    <p class="cs-hint" id="clColorHint"><i class="fas fa-info-circle"></i> Please select a color to continue.</p>
                                </div>

                                <div id="contactLensClinicVisit" class="clinic-visit-required">
                                    <i class="fas fa-info-circle"></i>
                                    <div><strong>Clinic Visit Required</strong><br>This product requires a prescription. Please visit the clinic on your scheduled appointment. Delivery is not available.</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div id="clinicVisitNotice" class="clinic-visit-notice" style="display: none;">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Clinic Visit Required</strong><br>
                                Please visit the clinic on your scheduled appointment to complete your purchase.
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: Choose Doctor (Always required) -->
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
                                <label class="doctor-card any-doctor-card" id="anyDoctorCard">
                                    <input type="radio" name="doctor_id" value="any" id="anyDoctor" onchange="handleDoctorSelection(this)" checked>
                                    <div class="doctor-avatar"><i class="fas fa-users"></i></div>
                                    <div class="doctor-name">Any Available Doctor</div>
                                    <div class="doctor-specialty">First available doctor</div>
                                    <span class="doctor-schedule-badge"><i class="fas fa-clock"></i> Auto-assign</span>
                                </label>
                                
                                <?php foreach($doctors_list as $doctor): 
                                    $has_schedule = false; $schedule_text = 'No schedule';
                                    if (!empty($doctor['schedule']) && is_array($doctor['schedule'])) {
                                        $days_with_schedule = [];
                                        foreach($doctor['schedule'] as $day => $times) {
                                            if (!empty($times) && is_array($times)) $days_with_schedule[] = $day;
                                        }
                                        if (!empty($days_with_schedule)) {
                                            $has_schedule = true;
                                            $schedule_text = count($days_with_schedule) > 3 ? count($days_with_schedule) . ' days' : implode(', ', array_map('ucfirst', $days_with_schedule));
                                        }
                                    }
                                ?>
                                <label class="doctor-card <?php echo !$has_schedule ? 'no-schedule' : ''; ?>" 
                                    data-doctor-id="<?php echo $doctor['id']; ?>" 
                                    data-schedule='<?php echo json_encode($doctor['schedule']); ?>'>
                                    <input type="radio" name="doctor_id" value="<?php echo $doctor['id']; ?>" 
                                        onchange="handleDoctorSelection(this)" 
                                        <?php echo !$has_schedule ? 'disabled' : ''; ?>>
                                    <div class="doctor-avatar"><i class="fas fa-user-md"></i></div>
                                    <div class="doctor-name"><?php echo $doctor['name']; ?></div>
                                    <div class="doctor-specialty"><?php echo $doctor['specialty'] ?? 'Optometrist'; ?></div>
                                    <span class="doctor-schedule-badge" style="<?php echo !$has_schedule ? 'background: #999;' : ''; ?>">
                                        <i class="fas fa-calendar-alt"></i> <?php echo $schedule_text; ?>
                                    </span>
                                    <?php if (!$has_schedule): ?>
                                        <div style="font-size: 10px; color: #dc3545; margin-top: 5px;">No schedule set</div>
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

                    <!-- STEP 3: Date & Time -->
                    <div id="step3" class="step-section locked">
                        <div class="step-header">
                            <h3><i class="fas fa-clock"></i> Choose Date & Time</h3>
                            <span class="step-status-badge status-locked" id="step3-status">🔒 Locked</span>
                        </div>
                        
                        <div class="disabled-content" id="step3-content">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-alt"></i> Select Date</label>
                                <div class="calendar-container" id="calendarContainer">
                                    <div class="calendar-header">
                                        <button type="button" class="calendar-nav-btn" onclick="changeMonth(-1)">←</button>
                                        <h4 id="calendarMonthYear"></h4>
                                        <button type="button" class="calendar-nav-btn" onclick="changeMonth(1)">→</button>
                                    </div>
                                    <div class="calendar-weekdays"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                                    <div class="calendar-days" id="calendarDays"></div>
                                </div>
                            </div>
                            
                            <div id="timeSlotsContainer" class="time-slots-container">
                                <div class="text-center" style="padding: 20px; color: var(--text-muted);">
                                    <i class="fas fa-clock"></i> Select a date to see available time slots
                                </div>
                            </div>
                            
                            <input type="hidden" name="appointment_date" id="selectedDate" value="">
                            <input type="hidden" name="appointment_time" id="selectedTime" value="">
                            
                            <div class="calendar-legend">
                                <div class="legend-item"><div class="legend-color available"></div><span>Fully Available</span></div>
                                <div class="legend-item"><div class="legend-color partial"></div><span>Partial Slots</span></div>
                                <div class="legend-item"><div class="legend-color unavailable"></div><span>Not Available</span></div>
                                <div class="legend-item"><div class="legend-color today"></div><span>Today</span></div>
                                <div class="legend-item"><div class="legend-color selected"></div><span>Selected</span></div>
                            </div>
                            
                            <div id="dailyLimitWarning" class="daily-limit-warning" style="display: none;">
                                <i class="fas fa-exclamation-triangle"></i>
                                You have <span id="dailyCount">0</span> appointment(s) today. Maximum is 3 per day.
                            </div>
                            
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

                    <!-- STEP 4: Confirm -->
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

            <!-- SIDEBAR -->
            <div class="booking-sidebar">
                <div class="clinic-info-sidebar">
                    <i class="fas fa-clinic-medical"></i>
                    <div>
                        <h4><?php echo $clinic['name']; ?></h4>
                        <p><i class="fas fa-map-marker-alt"></i> <span><?php echo $clinic['address']; ?>, <?php echo $clinic['city']; ?></span></p>
                        <p><i class="fas fa-phone"></i> <span><?php echo $clinic['contact']; ?></span></p>
                        <p><i class="fas fa-clock"></i> <span><?php echo $clinic['hours']; ?></span></p>
                    </div>
                </div>

                <?php
                $display_payment = getPaymentDisplayInfo($conn, $clinic_id, $item_price);
                $booking_flow = $display_payment['booking_flow'] ?? 'approve_first';
                $payment_type = $display_payment['payment_type'] ?? 'downpayment';
                $flow_color = ($booking_flow === 'pay_first') ? 'var(--primary)' : 'var(--warning)';
                $flow_icon = ($booking_flow === 'pay_first') ? 'fa-credit-card' : 'fa-clock';
                ?>
                
                <div class="flow-indicator" style="border-left: 4px solid <?php echo $flow_color; ?>;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <i class="fas <?php echo $flow_icon; ?>" style="color: <?php echo $flow_color; ?>; font-size: 20px;"></i>
                        <h5 style="margin: 0; font-weight: 600; color: var(--text-primary);"><?php echo $booking_flow === 'pay_first' ? 'Pay First Booking' : 'Approve First Booking'; ?></h5>
                    </div>
                    <p style="margin: 0 0 12px 0; font-size: 13px; color: var(--text-secondary); line-height: 1.5;">
                        <?php if ($booking_flow === 'pay_first'): ?>
                            <i class="fas fa-check-circle" style="color: var(--primary); font-size: 12px;"></i> You will pay the downpayment <strong>immediately</strong> after booking.<br>
                            <i class="fas fa-check-circle" style="color: var(--primary); font-size: 12px;"></i> Clinic will review and approve after payment.
                        <?php else: ?>
                            <i class="fas fa-check-circle" style="color: var(--warning); font-size: 12px;"></i> Clinic will review your appointment first.<br>
                            <i class="fas fa-check-circle" style="color: var(--warning); font-size: 12px;"></i> You will pay <strong>after</strong> the clinic approves.
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
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;"><i class="fas fa-info-circle"></i> Full payment required</p>
                        <?php elseif ($payment_type === 'onsite'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--bg-secondary); padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                                <span style="font-weight: 600;">Pay at Clinic:</span>
                                <strong style="color: var(--warning); font-size: 16px;">₱<?php echo number_format($display_payment['total_amount'], 2); ?></strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;"><i class="fas fa-store"></i> No online payment required</p>
                        <?php elseif ($payment_type === 'free'): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 5px; background: var(--success); color: white; padding: 8px; border-radius: var(--radius-sm);">
                                <span style="font-weight: 600;">FREE SERVICE</span>
                                <strong>₱0.00</strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;"><i class="fas fa-check-circle"></i> No payment required</p>
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

                <div class="summary-card">
                    <h4><i class="fas fa-receipt"></i> Booking Summary</h4>
                    <div class="summary-services-list" id="summaryServicesWrap" style="display:none;">
                        <span class="summary-label">Selected Services:</span>
                        <div id="summaryServicesList"></div>
                    </div>
                    <div class="summary-item" id="summarySingleItemRow">
                        <span class="summary-label">Item:</span>
                        <span class="summary-value" id="summaryItem"><?php echo $selected_item ? $selected_item['name'] : 'Not selected'; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Type:</span>
                        <span class="summary-value" id="summaryType"><?php echo $selected_item_type ? ucfirst($selected_item_type) : '—'; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Lens:</span>
                        <span class="summary-value" id="summaryLens">—</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Color:</span>
                        <span class="summary-value" id="summaryColor">—</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Size:</span>
                        <span class="summary-value" id="summarySize">—</span>
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
                    <div class="total-price" id="totalPrice" data-base-total="<?php echo $item_price; ?>">
                        <?php echo $selected_item ? '₱' . number_format($selected_item['price'], 2) : '₱0.00'; ?>
                    </div>
                </div>

                <button type="submit" form="bookingForm" name="confirm" class="btn-confirm" id="confirmBtn" disabled>
                    <i class="fas fa-check-circle"></i> Confirm Booking
                </button>

                <div class="quick-info">
                    <p><i class="fas fa-info-circle"></i> Grayed out times are break hours</p>
                    <p><i class="fas fa-clock"></i> Red times are already booked</p>
                    <p><i class="fas fa-exclamation-triangle"></i> Max 3 appointments per day</p>
                    <p><i class="fas fa-stethoscope"></i> Services and prescription products require doctor selection</p>
                    <p id="quickClinicVisitInfo" style="display: none; color: var(--warning);"><i class="fas fa-store"></i> Clinic visit required for this item</p>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h2><i class="fas fa-info-circle"></i> Important Information</h2>
            <ul>
                <li>Please arrive at least 10 minutes before your scheduled appointment.</li>
                <li>Bring any previous prescription glasses or medical records if available.</li>
                <li>Cancellations must be made at least 2 hours before your appointment.</li>
                <li>For contact lens fitting, please don't wear your lenses 24 hours before the appointment.</li>
                <li>Payment can be made at the clinic via cash, credit card, or GCash.</li>
                <li>You can only book up to 3 appointments per day.</li>
                <li>Services and prescription products require a doctor selection.</li>
                <li>Not all services can be combined in one booking — treatment and repair services must be booked separately.</li>
                <li id="clinicVisitFooter" style="display: none;"><strong>Clinic visit required for this item.</strong> Please visit the clinic.</li>
            </ul>
        </div>

        <?php endif; ?>
    </div>

    <script>
    // ============================================
    // CLEAR ERROR
    // ============================================
    function clearErrorMessage() {
        const alert = document.getElementById('errorAlert');
        if (alert) alert.remove();
        document.querySelectorAll('.alert-error').forEach(el => el.remove());
        const toast = document.getElementById('toastContainer');
        if (toast) toast.innerHTML = '';
        document.querySelectorAll('[class*="alert"]').forEach(el => {
            if (el.textContent.includes('complete all steps') || 
                el.textContent.includes('Please complete')) {
                el.remove();
            }
        });
    }

    function showToast(message, type) {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast-notification ${type || 'info'}`;
        const icon = type === 'error' ? 'exclamation-circle' : 'check-circle';
        toast.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }

    function scrollToStep(stepId) {
        const section = document.getElementById(stepId);
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ============================================
    // STORE DATA FROM PHP
    // ============================================
    const clinicBreaks = <?php echo json_encode($clinic_breaks); ?>;
    const clinicHours = '<?php echo $clinic['hours']; ?>';
    const bookedSlots = <?php echo json_encode($booked_slots); ?>;
    const eyewearProducts = <?php echo json_encode($eyewear_products); ?>;
    const contactLensProducts = <?php echo json_encode($contact_lens_products); ?>;

    // ============================================
    // STATE
    // ============================================
    let step1Completed = <?php echo $step1_completed ? 'true' : 'false'; ?>;
    let step2Completed = false;
    let step3Completed = false;
    let selectedItemType = '<?php echo $selected_item_type; ?>';
    let selectedItemId = <?php echo $selected_item_id; ?>;
    let selectedPrice = <?php echo $item_price; ?>;
    let selectedDoctor = 'Any Available Doctor';
    let selectedDoctorId = 'any';
    let selectedDoctorSchedule = null;
    let selectedDate = '';
    let selectedTime = '';
    let currentMonth = new Date();

    let selectedEyewearLens = null;
    let selectedContactLens = 'daily';
    let selectedColorCode = '';
    let selectedColorName = '';
    let selectedFrameSize = '';
    let currentProductColors = [];
    let currentProductSizes = [];
    let selectedEyewearProductId = null;
    let selectedContactProductId = null;
    
    // Prescription state
    let rxKnowledge = null;

    const ITEMS_PER_PAGE = 6;
    let currentPages = {
        services: 1,
        eyewear: 1,
        contact_lenses: 1
    };

    function htmlspecialchars(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ============================================
    // PRESCRIPTION FUNCTIONS (from product-view)
    // ============================================
    function handleRxKnowledge(radio) {
        rxKnowledge = radio.value;
        document.getElementById('prescriptionKnowledge').value = rxKnowledge;
        document.getElementById('rxFormBox').style.display = (rxKnowledge === 'know') ? 'block' : 'none';
        document.getElementById('rxExamBox').style.display = (rxKnowledge === 'dont_know') ? 'block' : 'none';
        checkStep1Complete();
    }

    function updatePrescriptionFields() {
        // Get values from inputs
        const od_sph = document.getElementById('od_sph')?.value || '';
        const od_cyl = document.getElementById('od_cyl')?.value || '';
        const od_axis = document.getElementById('od_axis')?.value || '';
        const os_sph = document.getElementById('os_sph')?.value || '';
        const os_cyl = document.getElementById('os_cyl')?.value || '';
        const os_axis = document.getElementById('os_axis')?.value || '';
        
        // Store in hidden fields
        document.getElementById('od_sph_hidden').value = od_sph;
        document.getElementById('od_cyl_hidden').value = od_cyl;
        document.getElementById('od_axis_hidden').value = od_axis;
        document.getElementById('os_sph_hidden').value = os_sph;
        document.getElementById('os_cyl_hidden').value = os_cyl;
        document.getElementById('os_axis_hidden').value = os_axis;
        
        checkStep1Complete();
    }

    // ============================================
    // CHECK STEP 1 COMPLETE
    // ============================================
    function checkStep1Complete() {
        const activeTab = document.querySelector('.main-tab-btn.active')?.dataset.tab;
        let isComplete = false;

        if (selectedItemType === 'service') {
            isComplete = (selectedServices.length > 0);
        } else if (selectedItemType === 'product') {
            if (activeTab === 'eyewear' && selectedEyewearProductId) {
                if (selectedEyewearLens) {
                    let lensOk = true;
                    // Need prescription knowledge and if 'know', need at least one eye's SPH
                    if (!rxKnowledge) {
                        lensOk = false;
                    } else if (rxKnowledge === 'know') {
                        const od_sph = document.getElementById('od_sph')?.value || '';
                        const os_sph = document.getElementById('os_sph')?.value || '';
                        if (!od_sph && !os_sph) {
                            lensOk = false;
                        }
                    }
                    const hasColors = currentProductColors && currentProductColors.length > 0;
                    const hasSizes = currentProductSizes && currentProductSizes.length > 0;
                    if (hasColors) lensOk = lensOk && (selectedColorCode && selectedColorName);
                    if (hasSizes) lensOk = lensOk && (selectedFrameSize);
                    isComplete = lensOk;
                } else {
                    isComplete = false;
                }
            } else if (activeTab === 'contact_lenses' && selectedContactProductId) {
                let ok = true;
                if (selectedContactLens) {
                    const hasColors = currentProductColors && currentProductColors.length > 0;
                    if (hasColors) ok = ok && (selectedColorCode && selectedColorName);
                    isComplete = ok;
                } else {
                    isComplete = false;
                }
            } else {
                isComplete = false;
            }
        } else {
            isComplete = false;
        }

        step1Completed = isComplete;
        const statusEl = document.getElementById('step1-status');
        const step1El = document.getElementById('step1');
        const progressStep1 = document.querySelector('.progress-step.step1');

        if (isComplete) {
            statusEl.textContent = '✓ Completed';
            statusEl.className = 'step-status-badge status-completed';
            step1El.classList.add('completed');
            step1El.classList.remove('active');
            progressStep1.classList.add('completed');
            progressStep1.classList.remove('active');
            
            // ALWAYS enable Step 2 (Doctor) - for both services AND products
            document.getElementById('step2').classList.remove('locked');
            document.getElementById('step2').classList.add('active');
            document.getElementById('step2-status').textContent = 'Required';
            document.getElementById('step2-status').className = 'step-status-badge status-pending';
            document.getElementById('step2-content').classList.remove('disabled-content');
            document.querySelector('.progress-step.step2').classList.remove('disabled');
            document.querySelector('.progress-step.step2').classList.add('active');
            document.getElementById('doctorsGrid').style.display = 'grid';
            document.getElementById('product-message').style.display = 'none';
            
        } else {
            statusEl.textContent = 'Required';
            statusEl.className = 'step-status-badge status-pending';
            step1El.classList.remove('completed');
            step1El.classList.add('active');
            progressStep1.classList.remove('completed');
            progressStep1.classList.add('active');
            
            document.getElementById('step2').classList.add('locked');
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step2-status').textContent = '🔒 Locked';
            document.getElementById('step2-status').className = 'step-status-badge status-locked';
            document.querySelector('.progress-step.step2').classList.add('disabled');
            document.querySelector('.progress-step.step2').classList.remove('active');
            document.getElementById('step3').classList.add('locked');
            document.getElementById('step3').classList.remove('active');
            document.getElementById('step3-status').textContent = '🔒 Locked';
            document.getElementById('step3-status').className = 'step-status-badge status-locked';
            document.querySelector('.progress-step.step3').classList.add('disabled');
            document.querySelector('.progress-step.step3').classList.remove('active');
            document.getElementById('step4').classList.add('locked');
            document.getElementById('step4').classList.remove('active');
            document.getElementById('step4-status').textContent = '🔒 Locked';
            document.getElementById('step4-status').className = 'step-status-badge status-locked';
            document.querySelector('.progress-step.step4').classList.add('disabled');
            document.querySelector('.progress-step.step4').classList.remove('active');
            document.getElementById('confirmBtn').disabled = true;
        }
        return isComplete;
    }

    // ============================================
    // BUILD PRODUCT CARD
    // ============================================
    function buildProductCard(prod, isSelected) {
        let imgHtml = '<div class="img-fallback"><i class="fas fa-box"></i></div>';
        let imagePath = null;
        if (prod.images_json) {
            try { const imgs = JSON.parse(prod.images_json); if (imgs && imgs.length > 0) imagePath = imgs[0]; } catch(e) {}
        }
        if (!imagePath && prod.images) {
            try { const imgs = JSON.parse(prod.images); if (imgs && imgs.length > 0) imagePath = imgs[0]; } catch(e) {}
        }
        if (!imagePath && prod.image) imagePath = prod.image;
        if (imagePath) {
            let cleanPath = imagePath.replace(/^uploads\/uploads\//, 'uploads/');
            if (cleanPath.startsWith('uploads/')) imgHtml = `<img src="/${cleanPath}" alt="">`;
            else if (cleanPath.startsWith('/uploads/')) imgHtml = `<img src="${cleanPath}" alt="">`;
            else if (cleanPath.startsWith('http')) imgHtml = `<img src="${cleanPath}" alt="">`;
            else imgHtml = `<img src="/assets/images/products/${cleanPath}" alt="">`;
        }
        return `
            <div class="product-card ${isSelected ? 'selected' : ''}" data-product-id="${prod.id}" onclick="selectProduct('${prod.category}', ${prod.id}, '${htmlspecialchars(prod.name)}', ${prod.price})">
                <div class="product-img-thumb">${imgHtml}</div>
                <div class="product-name">${htmlspecialchars(prod.name)}</div>
                <div class="product-category">${htmlspecialchars(prod.category)}</div>
                <div class="product-price">₱${Number(prod.price).toFixed(2)}</div>
            </div>
        `;
    }

    function selectProduct(category, productId, name, price) {
        const activeTab = document.querySelector('.main-tab-btn.active')?.dataset.tab;
        if (activeTab === 'eyewear') {
            selectEyewearProduct(productId, name, price);
        } else if (activeTab === 'contact_lenses') {
            selectContactProduct(productId, name, price);
        }
    }

    // ============================================
    // EYEWEAR
    // ============================================
    function selectEyewearProduct(productId, name, price) {
        document.querySelectorAll('#eyewearGrid .product-card').forEach(c => c.classList.remove('selected'));
        const card = document.querySelector(`#eyewearGrid .product-card[data-product-id="${productId}"]`);
        if (card) card.classList.add('selected');

        selectedEyewearProductId = productId;
        selectedItemId = productId;
        selectedPrice = price;
        selectedItemType = 'product';
        document.getElementById('selectedItemType').value = 'product';
        document.getElementById('selectedItemId').value = productId;

        const lensBox = document.getElementById('eyewearLensSelection');
        if (lensBox) lensBox.classList.add('visible');

        selectedEyewearLens = null;
        document.querySelectorAll('#eyewearLensOptions .lens-option-btn').forEach(b => b.classList.remove('selected'));

        document.getElementById('eyewearClinicVisit').classList.remove('visible');
        document.getElementById('eyewearColorSize').classList.remove('visible');
        
        // Reset RX state
        const rxSection = document.getElementById('rxSection');
        if (rxSection) rxSection.style.display = 'none';
        rxKnowledge = null;
        document.querySelectorAll('input[name="rx_know"]').forEach(r => r.checked = false);
        document.getElementById('rxFormBox').style.display = 'none';
        document.getElementById('rxExamBox').style.display = 'none';
        document.getElementById('prescriptionKnowledge').value = '';

        fetch(`?ajax_get_product_details=1&product_id=${productId}`)
            .then(r => r.json())
            .then(data => {
                currentProductColors = data.colors || [];
                currentProductSizes = data.sizes || [];
                renderEyewearColors(currentProductColors);
                renderEyewearSizes(currentProductSizes);
            })
            .catch(err => console.error('Error fetching product details:', err));

        document.getElementById('summaryItem').textContent = name;
        document.getElementById('summaryType').textContent = 'Product';
        document.getElementById('summaryLens').textContent = '—';
        document.getElementById('summaryColor').textContent = '—';
        document.getElementById('summarySize').textContent = '—';
        document.getElementById('totalPrice').dataset.baseTotal = price;
        document.getElementById('totalPrice').textContent = '₱' + price.toFixed(2);

        selectedColorCode = '';
        selectedColorName = '';
        selectedFrameSize = '';
        document.getElementById('selectedColorCode').value = '';
        document.getElementById('selectedColorName').value = '';
        document.getElementById('selectedFrameSize').value = '';

        document.getElementById('quickClinicVisitInfo').style.display = 'none';

        checkStep1Complete();
        setTimeout(() => lensBox.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
    }

    const LENS_PRICES = { single_vision: 500, progressive: 1500, blue_cut: 800 };

    function selectEyewearLens(btn) {
        document.querySelectorAll('#eyewearLensOptions .lens-option-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedEyewearLens = btn.dataset.lens;
        document.getElementById('selectedLensType').value = selectedEyewearLens;

        const basePrice = selectedPrice;
        const lensPrice = LENS_PRICES[selectedEyewearLens] || 0;
        const totalPrice = basePrice + lensPrice;

        const totalDisplay = document.getElementById('totalPrice');
        if (totalDisplay) {
            totalDisplay.dataset.baseTotal = totalPrice;
            totalDisplay.textContent = '₱' + totalPrice.toFixed(2);
        }

        const lensNames = { single_vision: 'Single Vision', progressive: 'Progressive', blue_cut: 'Blue Cut' };
        document.getElementById('summaryLens').textContent = lensNames[selectedEyewearLens] || selectedEyewearLens;

        // Show clinic visit and prescription section
        const clinicVisit = document.getElementById('eyewearClinicVisit');
        clinicVisit.classList.add('visible');
        
        // Show color/size
        document.getElementById('eyewearColorSize').classList.add('visible');
        
        // Show RX section
        const rxSection = document.getElementById('rxSection');
        if (rxSection) rxSection.style.display = 'block';

        checkStep1Complete();
    }

    // ============================================
    // EYEWEAR COLORS & SIZES
    // ============================================
    function renderEyewearColors(colors) {
        const container = document.getElementById('eyewearColorBtns');
        const hint = document.getElementById('eyewearColorHint');
        const label = document.getElementById('eyewearColorSelected');
        if (!container) return;
        container.innerHTML = '';
        if (!colors || colors.length === 0) {
            container.innerHTML = '<p style="color:var(--text-muted);font-size:13px;">No color variants available.</p>';
            if (hint) hint.style.display = 'none';
            selectedColorCode = 'default';
            selectedColorName = 'Default';
            document.getElementById('selectedColorCode').value = 'default';
            document.getElementById('selectedColorName').value = 'Default';
            document.getElementById('eyewearColorSelected').textContent = 'Default (No variants)';
            document.getElementById('summaryColor').textContent = 'Default';
            checkStep1Complete();
            return;
        }
        const hasStock = colors.some(c => c.quantity > 0);
        colors.forEach(color => {
            const oos = color.quantity <= 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `color-btn ${oos ? 'oos' : ''}`;
            btn.dataset.code = color.color_code;
            btn.dataset.name = color.color_name;
            btn.dataset.qty = color.quantity;
            btn.innerHTML = `
                ${color.color_code && color.color_code.length >= 4 ? `<span class="color-dot" style="background:${color.color_code}"></span>` : ''}
                <span class="color-btn-name">${color.color_name}</span>
                ${oos ? '<span class="color-btn-qty oos-label">Out of Stock</span>' : (color.quantity <= 5 ? `<span class="color-btn-qty low">${color.quantity} left</span>` : `<span class="color-btn-qty">${color.quantity} left</span>`)}
            `;
            btn.onclick = () => selectEyewearColor(btn);
            container.appendChild(btn);
        });
        if (hint) {
            hint.style.display = hasStock ? 'block' : 'none';
            hint.innerHTML = hasStock ? '<i class="fas fa-info-circle"></i> Please select a color to continue.' : '<i class="fas fa-exclamation-triangle"></i> All variants are out of stock.';
        }
        if (label) label.textContent = '— Select a color';
    }

    function selectEyewearColor(btn) {
        if (btn.classList.contains('oos')) return;
        document.querySelectorAll('#eyewearColorBtns .color-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedColorCode = btn.dataset.code;
        selectedColorName = btn.dataset.name;
        document.getElementById('selectedColorCode').value = selectedColorCode;
        document.getElementById('selectedColorName').value = selectedColorName;
        document.getElementById('eyewearColorSelected').textContent = selectedColorName;
        document.getElementById('eyewearColorHint').style.display = 'none';
        document.getElementById('summaryColor').textContent = selectedColorName;
        checkStep1Complete();
    }

    function renderEyewearSizes(sizes) {
        const container = document.getElementById('eyewearSizeBtns');
        const hint = document.getElementById('eyewearSizeHint');
        const label = document.getElementById('eyewearSizeSelected');
        if (!container) return;
        container.innerHTML = '';
        if (!sizes || sizes.length === 0) {
            container.innerHTML = '<p style="color:var(--text-muted);font-size:13px;">No size options available.</p>';
            if (hint) hint.style.display = 'none';
            selectedFrameSize = 'Default';
            document.getElementById('selectedFrameSize').value = 'Default';
            document.getElementById('eyewearSizeSelected').textContent = 'Default (No variants)';
            document.getElementById('summarySize').textContent = 'Default';
            checkStep1Complete();
            return;
        }
        sizes.forEach(size => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'size-btn';
            btn.dataset.size = size;
            btn.textContent = size;
            btn.onclick = () => selectEyewearSize(btn);
            container.appendChild(btn);
        });
        if (hint) hint.style.display = 'block';
        if (label) label.textContent = '— Select a size';
    }

    function selectEyewearSize(btn) {
        document.querySelectorAll('#eyewearSizeBtns .size-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedFrameSize = btn.dataset.size;
        document.getElementById('selectedFrameSize').value = selectedFrameSize;
        document.getElementById('eyewearSizeSelected').textContent = selectedFrameSize;
        document.getElementById('eyewearSizeHint').style.display = 'none';
        document.getElementById('summarySize').textContent = selectedFrameSize;
        checkStep1Complete();
    }

    // ============================================
    // CONTACT LENSES
    // ============================================
    function selectContactProduct(productId, name, price) {
        document.querySelectorAll('#contactLensGrid .product-card').forEach(c => c.classList.remove('selected'));
        const card = document.querySelector(`#contactLensGrid .product-card[data-product-id="${productId}"]`);
        if (card) card.classList.add('selected');

        selectedContactProductId = productId;
        selectedItemId = productId;
        selectedPrice = price;
        selectedItemType = 'product';
        document.getElementById('selectedItemType').value = 'product';
        document.getElementById('selectedItemId').value = productId;

        const lensBox = document.getElementById('contactLensSelection');
        if (lensBox) lensBox.classList.add('visible');

        selectedContactLens = 'daily';
        document.querySelectorAll('#contactLensSelection .lens-option-btn').forEach(b => b.classList.remove('selected'));
        document.querySelector('#contactLensSelection .lens-option-btn[data-lens="daily"]').classList.add('selected');
        document.getElementById('selectedLensType').value = 'daily';

        fetch(`?ajax_get_product_details=1&product_id=${productId}`)
            .then(r => r.json())
            .then(data => {
                currentProductColors = data.colors || [];
                renderContactLensColors(currentProductColors);
            })
            .catch(err => console.error('Error fetching product details:', err));

        document.getElementById('summaryItem').textContent = name;
        document.getElementById('summaryType').textContent = 'Product';
        document.getElementById('summaryLens').textContent = 'Daily Disposable';
        document.getElementById('summaryColor').textContent = '—';
        document.getElementById('summarySize').textContent = '—';
        document.getElementById('totalPrice').dataset.baseTotal = price;
        document.getElementById('totalPrice').textContent = '₱' + price.toFixed(2);

        // Show clinic visit
        const clinicVisit = document.getElementById('contactLensClinicVisit');
        clinicVisit.classList.add('visible');

        selectedColorCode = '';
        selectedColorName = '';
        document.getElementById('selectedColorCode').value = '';
        document.getElementById('selectedColorName').value = '';

        checkStep1Complete();
    }

    function selectContactLens(btn) {
        document.querySelectorAll('#contactLensSelection .lens-option-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedContactLens = btn.dataset.lens;
        document.getElementById('selectedLensType').value = selectedContactLens;
        document.getElementById('summaryLens').textContent = selectedContactLens === 'daily' ? 'Daily Disposable' : 'Monthly Wear';
        checkStep1Complete();
    }

    function renderContactLensColors(colors) {
        const container = document.getElementById('contactLensColorBtns');
        const hint = document.getElementById('clColorHint');
        const label = document.getElementById('clColorSelected');
        if (!container) return;
        container.innerHTML = '';
        if (!colors || colors.length === 0) {
            container.innerHTML = '<p style="color:var(--text-muted);font-size:13px;">No color variants available.</p>';
            if (hint) hint.style.display = 'none';
            selectedColorCode = 'default';
            selectedColorName = 'Default';
            document.getElementById('selectedColorCode').value = 'default';
            document.getElementById('selectedColorName').value = 'Default';
            document.getElementById('clColorSelected').textContent = 'Default (No variants)';
            document.getElementById('summaryColor').textContent = 'Default';
            checkStep1Complete();
            return;
        }
        const hasStock = colors.some(c => c.quantity > 0);
        colors.forEach(color => {
            const oos = color.quantity <= 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `color-btn ${oos ? 'oos' : ''}`;
            btn.dataset.code = color.color_code;
            btn.dataset.name = color.color_name;
            btn.innerHTML = `
                ${color.color_code && color.color_code.length >= 4 ? `<span class="color-dot" style="background:${color.color_code}"></span>` : ''}
                <span class="color-btn-name">${color.color_name}</span>
                ${oos ? '<span class="color-btn-qty oos-label">Out of Stock</span>' : (color.quantity <= 5 ? `<span class="color-btn-qty low">${color.quantity} left</span>` : `<span class="color-btn-qty">${color.quantity} left</span>`)}
            `;
            btn.onclick = () => selectContactLensColor(btn);
            container.appendChild(btn);
        });
        if (hint) {
            hint.style.display = hasStock ? 'block' : 'none';
            hint.innerHTML = hasStock ? '<i class="fas fa-info-circle"></i> Please select a color to continue.' : '<i class="fas fa-exclamation-triangle"></i> All variants are out of stock.';
        }
        if (label) label.textContent = '— Select a color';
        document.getElementById('contactLensColorSize').classList.add('visible');
    }

    function selectContactLensColor(btn) {
        if (btn.classList.contains('oos')) return;
        document.querySelectorAll('#contactLensColorBtns .color-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedColorCode = btn.dataset.code;
        selectedColorName = btn.dataset.name;
        document.getElementById('selectedColorCode').value = selectedColorCode;
        document.getElementById('selectedColorName').value = selectedColorName;
        document.getElementById('clColorSelected').textContent = selectedColorName;
        document.getElementById('clColorHint').style.display = 'none';
        document.getElementById('summaryColor').textContent = selectedColorName;
        checkStep1Complete();
    }

    // ============================================
    // RENDER FUNCTIONS
    // ============================================
    function renderServices() {
        const grid = document.getElementById('servicesGrid');
        const pagination = document.getElementById('servicesPagination');
        if (!grid || !pagination) return;
        const items = grid.querySelectorAll('.service-card');
        const totalItems = items.length;
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;
        const page = Math.min(currentPages.services, totalPages);
        const start = (page - 1) * ITEMS_PER_PAGE;
        const end = Math.min(start + ITEMS_PER_PAGE, totalItems);
        items.forEach((item, i) => {
            item.style.display = (i >= start && i < end) ? 'block' : 'none';
        });
        buildPagination(pagination, page, totalPages, 'services');
    }

    function renderEyewear() {
        const grid = document.getElementById('eyewearGrid');
        const pagination = document.getElementById('eyewearPagination');
        if (!grid || !pagination) return;
        const filtered = eyewearProducts;
        const totalItems = filtered.length;
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;
        const page = Math.min(currentPages.eyewear, totalPages);
        const start = (page - 1) * ITEMS_PER_PAGE;
        const end = Math.min(start + ITEMS_PER_PAGE, totalItems);
        let html = '';
        for (let i = start; i < end; i++) {
            const prod = filtered[i];
            const isSelected = (selectedItemId == prod.id && selectedItemType === 'product');
            html += buildProductCard(prod, isSelected);
        }
        if (filtered.length === 0) html = '<div class="no-slots-message" style="grid-column:1/-1;"><i class="fas fa-glasses"></i><h4>No products found</h4></div>';
        grid.innerHTML = html;
        buildPagination(pagination, page, totalPages, 'eyewear');
    }

    function renderContactLenses() {
        const grid = document.getElementById('contactLensGrid');
        const pagination = document.getElementById('contactLensPagination');
        if (!grid || !pagination) return;
        const filtered = contactLensProducts;
        const totalItems = filtered.length;
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;
        const page = Math.min(currentPages.contact_lenses, totalPages);
        const start = (page - 1) * ITEMS_PER_PAGE;
        const end = Math.min(start + ITEMS_PER_PAGE, totalItems);
        let html = '';
        for (let i = start; i < end; i++) {
            const prod = filtered[i];
            const isSelected = (selectedItemId == prod.id && selectedItemType === 'product');
            html += buildProductCard(prod, isSelected);
        }
        if (filtered.length === 0) html = '<div class="no-slots-message" style="grid-column:1/-1;"><i class="fas fa-eye"></i><h4>No products found</h4></div>';
        grid.innerHTML = html;
        buildPagination(pagination, page, totalPages, 'contact_lenses');
    }

    function buildPagination(container, page, totalPages, tab) {
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        let html = '';
        html += `<button type="button" onclick="goToPage('${tab}', ${page - 1})" ${page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
        for (let i = 1; i <= totalPages; i++) {
            html += `<button type="button" onclick="goToPage('${tab}', ${i})" class="${i === page ? 'active' : ''}">${i}</button>`;
        }
        html += `<button type="button" onclick="goToPage('${tab}', ${page + 1})" ${page >= totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
        container.innerHTML = html;
    }

    function goToPage(tab, page) {
        if (tab === 'services') { currentPages.services = Math.max(1, page); renderServices(); }
        else if (tab === 'eyewear') { currentPages.eyewear = Math.max(1, page); renderEyewear(); }
        else if (tab === 'contact_lenses') { currentPages.contact_lenses = Math.max(1, page); renderContactLenses(); }
    }

    // ============================================
    // SERVICE SELECTION
    // ============================================
    let selectedServices = <?php
        if ($selected_item && $selected_item_type === 'service') {
            echo json_encode([[ 
                'id' => (string)$selected_item['id'],
                'name' => $selected_item['name'],
                'price' => (float)$selected_item['price'],
                'group' => $selected_item['booking_group'] ?? '',
                'max' => (int)($selected_item['max_per_booking'] ?? 1)
            ]]);
        } else {
            echo '[]';
        }
    ?>;

    function handleServiceSelection(checkbox) {
        const id = checkbox.value;
        const group = checkbox.getAttribute('data-group') || '';
        const maxPer = parseInt(checkbox.getAttribute('data-max')) || 1;
        const name = checkbox.getAttribute('data-name');
        const price = parseFloat(checkbox.getAttribute('data-price'));
        const card = checkbox.closest('.service-card');
        const isStandalone = (group === 'treatment' || group === 'repair' || group === '');

        if (checkbox.checked) {
            const hasStandaloneSelected = selectedServices.some(s => (s.group === 'treatment' || s.group === 'repair' || s.group === ''));
            if (hasStandaloneSelected && selectedServices.length > 0) {
                checkbox.checked = false;
                showToast('That service cannot be combined with your current selection. Please book it alone.', 'error');
                return;
            }
            if (isStandalone && selectedServices.length > 0) {
                checkbox.checked = false;
                showToast('This service must be booked alone. Uncheck other services first.', 'error');
                return;
            }
            const countInGroup = selectedServices.filter(s => s.group === group).length;
            if (countInGroup >= maxPer) {
                checkbox.checked = false;
                showToast(`Only ${maxPer} '${group}' service(s) allowed per booking.`, 'error');
                return;
            }
            selectedServices.push({ id, name, price, group, max: maxPer });
            card.classList.add('selected');
        } else {
            selectedServices = selectedServices.filter(s => s.id !== id);
            card.classList.remove('selected');
        }
        
        refreshServiceLocks();
        updateServiceSummary();
        selectedItemType = 'service';
        document.getElementById('selectedItemType').value = 'service';
        document.getElementById('selectedItemId').value = '';

        checkStep1Complete();
    }

    function refreshServiceLocks() {
        const hasStandaloneSelected = selectedServices.some(s => (s.group === 'treatment' || s.group === 'repair' || s.group === ''));
        document.querySelectorAll('.service-checkbox').forEach(cb => {
            const card = cb.closest('.service-card');
            const group = cb.getAttribute('data-group') || '';
            const maxPer = parseInt(cb.getAttribute('data-max')) || 1;
            const isStandalone = (group === 'treatment' || group === 'repair' || group === '');
            const alreadySelected = selectedServices.some(s => s.id === cb.value);
            if (alreadySelected) { card.classList.remove('blocked'); return; }
            let blocked = false;
            if (selectedServices.length > 0) {
                if (hasStandaloneSelected) blocked = true;
                else if (isStandalone) blocked = true;
                else {
                    const countInGroup = selectedServices.filter(s => s.group === group).length;
                    if (countInGroup >= maxPer) blocked = true;
                }
            }
            cb.disabled = blocked;
            card.classList.toggle('blocked', blocked);
        });
    }

    function updateServiceSummary() {
        const wrap = document.getElementById('summaryServicesWrap');
        const list = document.getElementById('summaryServicesList');
        const singleRow = document.getElementById('summarySingleItemRow');
        const totalDisplay = document.getElementById('totalPrice');
        if (selectedServices.length === 0) {
            wrap.style.display = 'none';
            singleRow.style.display = 'flex';
            document.getElementById('summaryItem').textContent = 'Not selected';
            document.getElementById('summaryType').textContent = '—';
            document.getElementById('summaryLens').textContent = '—';
            document.getElementById('summaryColor').textContent = '—';
            document.getElementById('summarySize').textContent = '—';
            if (totalDisplay) totalDisplay.textContent = '₱0.00';
            return;
        }
        singleRow.style.display = 'none';
        wrap.style.display = 'block';
        let total = 0;
        list.innerHTML = '';
        selectedServices.forEach(s => {
            total += s.price;
            const row = document.createElement('div');
            row.className = 'summary-service-row';
            row.innerHTML = `<span>${s.name}</span><span>₱${s.price.toFixed(2)}</span>`;
            list.appendChild(row);
        });
        document.getElementById('summaryType').textContent = 'Service';
        document.getElementById('summaryLens').textContent = '—';
        document.getElementById('summaryColor').textContent = '—';
        document.getElementById('summarySize').textContent = '—';
        if (totalDisplay) {
            totalDisplay.dataset.baseTotal = total;
            totalDisplay.textContent = '₱' + total.toFixed(2);
        }
        selectedPrice = total;
    }

    // ============================================
    // DOCTOR SELECTION
    // ============================================
    function handleDoctorSelection(radio) {
        const doctorCard = radio.closest('.doctor-card');
        if (doctorCard.classList.contains('no-schedule')) {
            alert('This doctor has no schedule set. Please choose another doctor.');
            document.getElementById('anyDoctor').checked = true;
            document.querySelectorAll('.doctor-card').forEach(card => card.classList.remove('selected'));
            document.getElementById('anyDoctorCard').classList.add('selected');
            return false;
        }
        document.querySelectorAll('.doctor-card').forEach(card => card.classList.remove('selected'));
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
                try { selectedDoctorSchedule = JSON.parse(scheduleData); } catch(e) { selectedDoctorSchedule = {}; }
            } else { selectedDoctorSchedule = {}; }
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
        selectedDate = ''; selectedTime = '';
        document.getElementById('selectedDate').value = '';
        document.getElementById('selectedTime').value = '';
        document.getElementById('summaryDate').textContent = '—';
        document.getElementById('summaryTime').textContent = '—';
        renderCalendar(currentMonth);
        document.getElementById('timeSlotsContainer').innerHTML = `<div class="text-center" style="padding:20px;color:var(--text-muted);"><i class="fas fa-clock"></i> Select a date to see available time slots</div>`;
        setTimeout(() => scrollToStep('step3'), 300);
    }

    // ============================================
    // CALENDAR FUNCTIONS
    // ============================================
    const BOOKING_BUFFER_MINUTES = 30;

    function getTodayDateStr() {
        const now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
    }

    function getClinicHoursRangeMinutes() {
        const hoursMatch = clinicHours.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
        if (!hoursMatch) return null;
        let sHour = parseInt(hoursMatch[1]), sMin = parseInt(hoursMatch[2]||0), sAmPm = (hoursMatch[3]||'am').toLowerCase();
        let eHour = parseInt(hoursMatch[4]), eMin = parseInt(hoursMatch[5]||0), eAmPm = (hoursMatch[6]||'pm').toLowerCase();
        if (sAmPm==='pm' && sHour!==12) sHour+=12;
        if (sAmPm==='am' && sHour===12) sHour=0;
        if (eAmPm==='pm' && eHour!==12) eHour+=12;
        if (eAmPm==='am' && eHour===12) eHour=0;
        return { startMinutes: sHour*60+sMin, endMinutes: eHour*60+eMin };
    }

    function getEffectiveEndMinutesForDay(dayOfWeek) {
        const clinicRange = getClinicHoursRangeMinutes();
        if (!clinicRange) return null;
        let effectiveEnd = clinicRange.endMinutes;
        if (selectedItemType==='service' && selectedDoctorId!=='any' && selectedDoctorSchedule && selectedDoctorSchedule[dayOfWeek]) {
            const ranges = selectedDoctorSchedule[dayOfWeek];
            if (!Array.isArray(ranges) || ranges.length===0) return null;
            let latestEnd=0;
            ranges.forEach(r => {
                const parts = r.split('-');
                if (parts.length<2) return;
                const endParts = parts[1].split(':');
                const eh = parseInt(endParts[0]), em = parseInt(endParts[1]||0);
                const mins = eh*60+em;
                if (mins>latestEnd) latestEnd = mins;
            });
            effectiveEnd = Math.min(effectiveEnd, latestEnd);
        }
        return effectiveEnd;
    }

    function hasFutureSlotToday(dateStr, dayOfWeek) {
        const todayStr = getTodayDateStr();
        if (dateStr !== todayStr) return true;
        const now = new Date();
        const nowMinutes = now.getHours()*60 + now.getMinutes();
        const effectiveEnd = getEffectiveEndMinutesForDay(dayOfWeek);
        if (effectiveEnd === null) return false;
        return (nowMinutes + BOOKING_BUFFER_MINUTES) < effectiveEnd;
    }

    function initCalendar() {
        currentMonth = new Date();
        renderCalendar(currentMonth);
    }

    function renderCalendar(date) {
        const year = date.getFullYear(), month = date.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month+1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDay = firstDay.getDay();
        let startOffset = startingDay===0 ? 6 : startingDay-1;
        const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        
        document.getElementById('calendarMonthYear').textContent = monthNames[month] + ' ' + year;
        
        let html = '';
        for (let i=0; i<startOffset; i++) html += '<div class="calendar-day empty"></div>';
        const today = new Date(); today.setHours(0,0,0,0);
        for (let day=1; day<=daysInMonth; day++) {
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            const cellDate = new Date(year, month, day);
            const isPast = cellDate < today;
            const dayOfWeek = ['sun','mon','tue','wed','thu','fri','sat'][cellDate.getDay()];
            const isToday = cellDate.toDateString() === today.toDateString();
            let canSelect = false;
            if (!isPast) {
                if (selectedItemType==='product' || selectedDoctorId==='any') canSelect = true;
                else if (selectedDoctorSchedule && typeof selectedDoctorSchedule==='object' && selectedDoctorSchedule[dayOfWeek] && Array.isArray(selectedDoctorSchedule[dayOfWeek]) && selectedDoctorSchedule[dayOfWeek].length>0) canSelect = true;
            }
            if (canSelect && isToday && !hasFutureSlotToday(dateStr, dayOfWeek)) canSelect = false;
            const isSelected = selectedDate === dateStr;
            let classes = 'calendar-day';
            if (canSelect) classes += ' available';
            if (isPast || !canSelect) classes += ' unavailable';
            if (isToday) classes += ' today';
            if (isSelected) classes += ' selected';
            let onclick = canSelect ? `selectDate('${dateStr}','${dayOfWeek}')` : '';
            html += `<div class="${classes}" ${onclick ? `onclick="${onclick}"` : ''}>${day}</div>`;
        }
        document.getElementById('calendarDays').innerHTML = html;
    }

    function changeMonth(delta) {
        currentMonth.setMonth(currentMonth.getMonth()+delta);
        renderCalendar(currentMonth);
    }

    function selectDate(dateStr, dayOfWeek) {
        if (selectedItemType==='service' && selectedDoctorId!=='any' && selectedDoctorSchedule) {
            if (!selectedDoctorSchedule[dayOfWeek] || !Array.isArray(selectedDoctorSchedule[dayOfWeek]) || selectedDoctorSchedule[dayOfWeek].length===0) {
                alert('Doctor is not available on this date');
                return;
            }
        }
        if (!hasFutureSlotToday(dateStr, dayOfWeek)) {
            alert('No more available time slots for today. Please select another date.');
            return;
        }
        selectedDate = dateStr;
        document.getElementById('selectedDate').value = dateStr;
        renderCalendar(currentMonth);
        const dateObj = new Date(dateStr);
        document.getElementById('summaryDate').textContent = dateObj.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
        selectedTime = '';
        document.getElementById('selectedTime').value = '';
        document.getElementById('summaryTime').textContent = '—';
        loadTimeSlots(dateStr, dayOfWeek);
    }

    function loadTimeSlots(date, dayOfWeek) {
        document.getElementById('timeSlotsContainer').innerHTML = `
            <div class="time-slots-header"><h4>Available Time Slots</h4><span>${new Date(date).toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'})}</span></div>
            <div class="text-center" style="padding:20px;"><div class="loading-spinner" style="margin:0 auto 10px;"></div><p style="color:var(--text-muted);">Loading available time slots...</p></div>
        `;
        setTimeout(() => generateTimeSlots(date, dayOfWeek), 500);
    }

    function generateTimeSlots(date, dayOfWeek) {
        if (!clinicHours || clinicHours.trim()==='') {
            document.getElementById('timeSlotsContainer').innerHTML = `<div class="no-slots-message"><i class="fas fa-exclamation-triangle"></i><h4>Clinic hours not set</h4><p>Please contact the clinic directly.</p></div>`;
            return;
        }
        const lowerHours = clinicHours.toLowerCase();
        let clinicStartHour, clinicStartMinute, clinicStartAmPm, clinicEndHour, clinicEndMinute, clinicEndAmPm;
        if (lowerHours.includes('24/7') || lowerHours.includes('24 hours')) {
            clinicStartHour=9; clinicStartMinute=0; clinicStartAmPm='am';
            clinicEndHour=8; clinicEndMinute=0; clinicEndAmPm='pm';
        } else {
            let hoursMatch = clinicHours.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i);
            if (!hoursMatch) hoursMatch = clinicHours.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/i);
            if (hoursMatch) {
                if (hoursMatch[1] && hoursMatch[4]) {
                    clinicStartHour = parseInt(hoursMatch[1]); clinicStartMinute = parseInt(hoursMatch[2]||0); clinicStartAmPm = hoursMatch[3] ? hoursMatch[3].toLowerCase() : 'am';
                    clinicEndHour = parseInt(hoursMatch[4]); clinicEndMinute = parseInt(hoursMatch[5]||0); clinicEndAmPm = hoursMatch[6] ? hoursMatch[6].toLowerCase() : 'pm';
                } else {
                    clinicStartHour = parseInt(hoursMatch[1]); clinicStartMinute = parseInt(hoursMatch[2]||0);
                    clinicEndHour = parseInt(hoursMatch[3]); clinicEndMinute = parseInt(hoursMatch[4]||0);
                    clinicStartAmPm = (clinicStartHour >= 12) ? 'pm' : 'am';
                    clinicEndAmPm = (clinicEndHour >= 12) ? 'pm' : 'am';
                }
            } else {
                clinicStartHour=9; clinicStartMinute=0; clinicStartAmPm='am';
                clinicEndHour=8; clinicEndMinute=0; clinicEndAmPm='pm';
            }
        }
        let startHour24 = clinicStartHour, endHour24 = clinicEndHour;
        if (clinicStartAmPm==='pm' && clinicStartHour!==12) startHour24 += 12;
        if (clinicStartAmPm==='am' && clinicStartHour===12) startHour24 = 0;
        if (clinicEndAmPm==='pm' && clinicEndHour!==12) endHour24 += 12;
        if (clinicEndAmPm==='am' && clinicEndHour===12) endHour24 = 0;
        const clinicStartMinutes = startHour24*60 + clinicStartMinute;
        const clinicEndMinutes = endHour24*60 + clinicEndMinute;
        
        let doctorTimeRanges = [];
        if (selectedItemType==='service' && selectedDoctorId!=='any' && selectedDoctorSchedule && selectedDoctorSchedule[dayOfWeek]) {
            doctorTimeRanges = selectedDoctorSchedule[dayOfWeek];
        }
        const todayStrForSlots = getTodayDateStr();
        const isDateToday = (date === todayStrForSlots);
        const nowForSlots = new Date();
        const nowMinutesForSlots = nowForSlots.getHours()*60 + nowForSlots.getMinutes();
        
        let availableSlots=[], breakSlots=[], bookedSlotsForDate=[], unavailableSlots=[], pastSlots=[];
        for (let mins = clinicStartMinutes; mins < clinicEndMinutes; mins += 30) {
            const hour = Math.floor(mins/60), minute = mins%60;
            const ampm = hour>=12 ? 'PM' : 'AM';
            const displayHour = hour%12===0 ? 12 : hour%12;
            const displayTime = displayHour+':'+(minute<10?'0'+minute:minute)+' '+ampm;
            const time24h = (hour<10?'0'+hour:hour)+':'+(minute<10?'0'+minute:minute)+':00';
            if (isDateToday && mins < (nowMinutesForSlots + BOOKING_BUFFER_MINUTES)) { pastSlots.push({time:displayTime}); continue; }
            const isBooked = bookedSlots.some(slot => slot.appointment_date === date && slot.appointment_time === time24h);
            if (isBooked) { bookedSlotsForDate.push({time:displayTime,time24h:time24h}); continue; }
            let isBreak=false, breakName='';
            if (clinicBreaks && clinicBreaks.length>0) {
                clinicBreaks.forEach(breakItem => {
                    const startMatch = breakItem.break_start.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i);
                    const endMatch = breakItem.break_end.match(/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i);
                    if (startMatch && endMatch) {
                        let bsHour = parseInt(startMatch[1]), bsMin = parseInt(startMatch[2]||0), bsAmPm = startMatch[3].toLowerCase();
                        let beHour = parseInt(endMatch[1]), beMin = parseInt(endMatch[2]||0), beAmPm = endMatch[3].toLowerCase();
                        if (bsAmPm==='pm' && bsHour!==12) bsHour+=12;
                        if (bsAmPm==='am' && bsHour===12) bsHour=0;
                        if (beAmPm==='pm' && beHour!==12) beHour+=12;
                        if (beAmPm==='am' && beHour===12) beHour=0;
                        const breakStartMins = bsHour*60+bsMin, breakEndMins = beHour*60+beMin;
                        if (mins >= breakStartMins && mins < breakEndMins) { isBreak=true; breakName = breakItem.break_name; }
                    }
                });
            }
            if (isBreak) { breakSlots.push({time:displayTime, breakName:breakName}); continue; }
            if (selectedItemType==='service' && selectedDoctorId!=='any' && doctorTimeRanges.length>0) {
                let isWithinDoctorSchedule = false;
                for (const timeRange of doctorTimeRanges) {
                    const [rangeStart, rangeEnd] = timeRange.split('-');
                    const startParts = rangeStart.split(':'), endParts = rangeEnd.split(':');
                    const rangeStartHour = parseInt(startParts[0]), rangeStartMin = parseInt(startParts[1]||0);
                    const rangeEndHour = parseInt(endParts[0]), rangeEndMin = parseInt(endParts[1]||0);
                    const rangeStartMins = rangeStartHour*60+rangeStartMin, rangeEndMins = rangeEndHour*60+rangeEndMin;
                    if (mins >= rangeStartMins && mins < rangeEndMins) { isWithinDoctorSchedule = true; break; }
                }
                if (!isWithinDoctorSchedule) { unavailableSlots.push({time:displayTime}); continue; }
            }
            availableSlots.push({time:displayTime, time24h:time24h});
        }
        let html = `<div class="time-slots-header"><h4>Available Time Slots</h4><span>${new Date(date).toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'})}</span></div>`;
        if (availableSlots.length>0) {
            html += '<div class="time-slots-grid">';
            availableSlots.forEach(slot => { html += `<div class="time-slot-card available" onclick="selectTime('${slot.time}','${slot.time24h}',this)">${slot.time}</div>`; });
            html += '</div>';
        }
        if (pastSlots.length>0) {
            html += '<h5 style="margin:15px 0 5px;color:var(--text-muted);">Already Passed</h5><div class="time-slots-grid">';
            pastSlots.forEach(slot => { html += `<div class="time-slot-card disabled" title="This time has already passed">${slot.time}<span class="slot-label">Passed</span></div>`; });
            html += '</div>';
        }
        if (unavailableSlots.length>0) {
            html += '<h5 style="margin:15px 0 5px;color:var(--danger);">Outside Doctor Hours</h5><div class="time-slots-grid">';
            unavailableSlots.forEach(slot => { html += `<div class="time-slot-card unavailable" title="Outside doctor\'s working hours">${slot.time}<span class="slot-label">Not available</span></div>`; });
            html += '</div>';
        }
        if (bookedSlotsForDate.length>0) {
            html += '<h5 style="margin:15px 0 5px;color:var(--danger);">Already Booked</h5><div class="time-slots-grid">';
            bookedSlotsForDate.forEach(slot => { html += `<div class="time-slot-card booked" title="Already booked">${slot.time}<span class="slot-label">Booked</span></div>`; });
            html += '</div>';
        }
        if (breakSlots.length>0) {
            html += '<h5 style="margin:15px 0 5px;color:var(--warning);">Break Times</h5><div class="time-slots-grid">';
            breakSlots.forEach(slot => { html += `<div class="time-slot-card disabled" title="${slot.breakName}">${slot.time}<span class="slot-label">${slot.breakName}</span></div>`; });
            html += '</div>';
        }
        if (availableSlots.length===0 && breakSlots.length===0 && bookedSlotsForDate.length===0 && unavailableSlots.length===0 && pastSlots.length===0) {
            html = '<div class="no-slots-message"><i class="fas fa-calendar-times"></i><h4>No available slots</h4><p>Please select another date.</p></div>';
        }
        if (availableSlots.length===0 && isDateToday && pastSlots.length>0 && unavailableSlots.length===0 && bookedSlotsForDate.length===0) {
            html = `<div class="time-slots-header"><h4>Available Time Slots</h4><span>${new Date(date).toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'})}</span></div>
                    <div class="no-slots-message"><i class="fas fa-clock"></i><h4>No more slots today</h4><p>All remaining time slots for today have passed. Please select another date.</p></div>`;
        }
        document.getElementById('timeSlotsContainer').innerHTML = html;
    }

    function selectTime(displayTime, time24h, element) {
        document.querySelectorAll('.time-slot-card').forEach(slot => slot.classList.remove('selected'));
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
        handleContactInput();
        setTimeout(() => scrollToStep('step4'), 300);
    }

    function handleContactInput() {
        const contact = document.querySelector('input[name="contact_number"]').value;
        const itemReady = (selectedItemType === 'service') ? selectedServices.length > 0 : step1Completed;
        if (contact && itemReady && step2Completed && step3Completed && selectedTime) {
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

    // ============================================
    // SWITCH MAIN TAB
    // ============================================
    function switchMainTab(tab) {
        clearErrorMessage();
        
        document.querySelectorAll('.main-tab-btn').forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.querySelector(`.main-tab-btn[data-tab="${tab}"]`);
        if (activeBtn) activeBtn.classList.add('active');
        
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        const targetContent = document.getElementById('tab-' + tab);
        if (targetContent) targetContent.classList.add('active');
        
        if (tab === 'eyewear') renderEyewear();
        else if (tab === 'contact_lenses') renderContactLenses();
        else if (tab === 'services') renderServices();
        
        if (tab === 'services') {
            document.getElementById('doctorsGrid').style.display = 'grid';
            document.getElementById('product-message').style.display = 'none';
        } else {
            document.getElementById('doctorsGrid').style.display = 'grid';
            document.getElementById('product-message').style.display = 'none';
        }
        
        if (!step3Completed) {
            selectedDate = '';
            selectedTime = '';
            document.getElementById('selectedDate').value = '';
            document.getElementById('selectedTime').value = '';
            document.getElementById('summaryDate').textContent = '—';
            document.getElementById('summaryTime').textContent = '—';
            renderCalendar(currentMonth);
            document.getElementById('timeSlotsContainer').innerHTML = `<div class="text-center" style="padding:20px;color:var(--text-muted);"><i class="fas fa-clock"></i> Select a date to see available time slots</div>`;
        }
    }

    // ============================================
    // FORM VALIDATION - ONLY ON SUBMIT
    // ============================================
    document.getElementById('bookingForm').addEventListener('submit', function(e) {
        const contactInput = document.querySelector('input[name="contact_number"]');
        const itemReady = (selectedItemType === 'service') ? selectedServices.length > 0 : step1Completed;

        if (!itemReady || !step2Completed || !step3Completed || !selectedTime || !contactInput.value) {
            e.preventDefault();
            showToast('Please complete all steps before confirming your booking.', 'error');
            if (!itemReady) scrollToStep('step1');
            else if (!step2Completed) scrollToStep('step2');
            else if (!step3Completed || !selectedTime) scrollToStep('step3');
            else if (!contactInput.value) { scrollToStep('step4'); contactInput.style.borderColor='var(--danger)'; }
            return;
        }
        
        const selDate = document.getElementById('selectedDate').value;
        const selTimeVal = document.getElementById('selectedTime').value;
        const todayStrSubmit = getTodayDateStr();
        if (selDate === todayStrSubmit && selTimeVal) {
            const nowSubmit = new Date();
            const nowMinutesSubmit = nowSubmit.getHours()*60 + nowSubmit.getMinutes();
            const [hSubmit, mSubmit] = selTimeVal.split(':').map(Number);
            const selMinutesSubmit = hSubmit*60 + mSubmit;
            if (selMinutesSubmit < nowMinutesSubmit) {
                e.preventDefault();
                showToast('That time has already passed. Please choose another time.', 'error');
                scrollToStep('step3');
            }
        }
    });

    // ============================================
    // INIT
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        clearErrorMessage();
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('theme-dark');
            const themeToggle = document.querySelector('#themeToggle i');
            if (themeToggle) themeToggle.className = 'fas fa-sun';
        }
        initCalendar();

        if (selectedItemType === 'product' && selectedItemId) {
            const inEyewear = eyewearProducts.some(p => p.id == selectedItemId);
            const inContact = contactLensProducts.some(p => p.id == selectedItemId);
            let targetTab = 'eyewear';
            if (inContact) targetTab = 'contact_lenses';
            switchMainTab(targetTab);
            if (inEyewear) {
                setTimeout(() => {
                    const prod = eyewearProducts.find(p => p.id == selectedItemId);
                    if (prod) selectEyewearProduct(prod.id, prod.name, prod.price);
                }, 300);
            } else if (inContact) {
                setTimeout(() => {
                    const prod = contactLensProducts.find(p => p.id == selectedItemId);
                    if (prod) selectContactProduct(prod.id, prod.name, prod.price);
                }, 300);
            }
        } else if (selectedItemType === 'service' && selectedServices.length > 0) {
            switchMainTab('services');
            updateServiceSummary();
            checkStep1Complete();
            document.getElementById('step2').classList.remove('locked');
            document.getElementById('step2').classList.add('active');
            document.getElementById('step2-status').textContent = 'Required';
            document.getElementById('step2-status').className = 'step-status-badge status-pending';
            document.getElementById('step2-content').classList.remove('disabled-content');
            document.querySelector('.progress-step.step2').classList.remove('disabled');
            document.querySelector('.progress-step.step2').classList.add('active');
            document.getElementById('doctorsGrid').style.display = 'grid';
            document.getElementById('product-message').style.display = 'none';
        } else {
            switchMainTab('services');
        }
        if (document.getElementById('anyDoctor')) {
            document.getElementById('anyDoctor').checked = true;
        }
    });
    </script>
</body>
</html>