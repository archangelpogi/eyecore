<?php

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    header('Location: dashboard.php');
    exit();
}

// Get user data
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
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

$reservation_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')");
$reservation_row = mysqli_fetch_assoc($reservation_query);
$reservation_count = $reservation_row['total'] ?? 0;

// ============================================
// GET PRODUCT + CLINIC DETAILS
// ============================================
$product_query = mysqli_query($conn, "
    SELECT p.*,
           c.id as clinic_id,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.hours as clinic_hours,
           c.logo as clinic_logo
    FROM products p
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.id = $product_id
");

if (mysqli_num_rows($product_query) == 0) {
    header('Location: dashboard.php');
    exit();
}

// ✅ FIXED: Only fetch once (duplicate line removed)
$product = mysqli_fetch_assoc($product_query);
$category = $product['category'];

// ============================================
// ✅ GET EXTRA FIELDS (product details) - DYNAMIC
// ============================================
$extra_fields = [];
if (!empty($product['extra_fields_json'])) {
    $extra_fields = json_decode($product['extra_fields_json'], true);
}

// ============================================
// ✅ GET AVAILABLE SIZES - ONLY FROM DATABASE (NO DEFAULTS)
// ============================================
$available_sizes = [];
if (!empty($extra_fields['sizes_available']) && is_array($extra_fields['sizes_available'])) {
    $available_sizes = $extra_fields['sizes_available'];
}
// ✅ WALANG DEFAULT SIZES! Kung walang laman, empty array lang.

// ============================================
// ✅ GET SPEC FIELDS - ONLY FROM DATABASE (NO HARD-CODED)
// ============================================
$spec_fields = [];
$spec_fields_display = [];

// Define possible fields per category (para malaman kung ano ang titingnan)
$possible_specs = [];
if (in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses'])) {
    $possible_specs = [
        'frame_material' => 'Frame Material',
        'frame_style' => 'Frame Shape',
        'frame_type' => 'Frame Type',
        'gender' => 'Gender',
        'weight_group' => 'Weight',
        'collection' => 'Collection',
        'model_no' => 'Model No.',
        'lens_width_mm' => 'Lens Width (mm)',
        'bridge_width_mm' => 'Bridge Width (mm)',
        'temple_length_mm' => 'Temple Length (mm)'
    ];
} elseif ($category == 'Contact Lenses') {
    $possible_specs = [
        'cl_type' => 'Wear Type',
        'cl_material' => 'Material',
        'cl_color_type' => 'Color Type',
        'cl_water_content' => 'Water Content (%)',
        'cl_base_curve' => 'Base Curve (mm)',
        'cl_diameter' => 'Diameter (mm)',
        'cl_pieces_per_box' => 'Pieces per Box',
        'cl_power_range' => 'Power Range'
    ];
} elseif ($category == 'Lenses') {
    $possible_specs = [
        'lens_type' => 'Lens Type',
        'lens_material' => 'Material',
        'lens_coating' => 'Coating',
        'lens_index' => 'Refractive Index',
        'lens_diameter' => 'Diameter (mm)',
        'lens_base_curve' => 'Base Curve',
        'lens_sphere_range' => 'Sphere Range',
        'lens_cylinder_range' => 'Cylinder Range',
        'lens_add_range' => 'Add Range'
    ];
} elseif ($category == 'Accessories') {
    $possible_specs = [
        'accessory_type' => 'Accessory Type',
        'accessory_material' => 'Material',
        'solution_type' => 'Solution Type',
        'solution_volume' => 'Volume (ml)',
        'part_compatibility' => 'Compatibility'
    ];
}

// ✅ ONLY ADD FIELDS THAT HAVE VALUES IN DATABASE
foreach ($possible_specs as $key => $label) {
    if (!empty($extra_fields[$key])) {
        $spec_fields[$key] = $label;
        $spec_fields_display[$key] = $extra_fields[$key];
    }
}

// ============================================
// ✅ GET WARRANTY DATA - FULL DETAILS
// ============================================
$warranty_period = $product['warranty_period'] ?? '';
$warranty_coverage = [];
if (!empty($product['warranty_coverage'])) {
    $warranty_coverage = json_decode($product['warranty_coverage'], true);
}
$warranty_exclusions = $product['warranty_exclusions'] ?? '';
$warranty_terms = $product['warranty_terms'] ?? '';
$warranty_claim_process = $product['warranty_claim_process'] ?? '';
$warranty_care = $product['warranty_care'] ?? '';
$warranty_premium = floatval($product['warranty_premium_price'] ?? 0);

$period_labels = [
    '3_months' => '3 Months',
    '6_months' => '6 Months',
    '12_months' => '12 Months',
    '24_months' => '24 Months',
    '36_months' => '36 Months'
];
$warranty_period_display = $period_labels[$warranty_period] ?? ($warranty_period == 'no_warranty' ? 'No Warranty' : '');
$has_warranty = ($warranty_period && $warranty_period != 'no_warranty');
$clinic_id = $product['clinic_id'];

// ============================================
// ✅ GET CLINIC PAYMENT CONFIGURATION
// ============================================
$clinic_payment_config = getClinicPaymentPolicy($conn, $clinic_id);

$clinic_payment_policy = $clinic_payment_config['payment_policy'];
$clinic_downpayment_percent = (float)($clinic_payment_config['downpayment_percentage'] ?? 30);
$clinic_booking_flow = $clinic_payment_config['booking_flow'] ?? 'approve_first';

// ============================================
// SALE DETECTION
// ============================================
$is_on_sale = !empty($product['is_on_sale'])
    && $product['is_on_sale'] == 1
    && !empty($product['sale_price'])
    && $product['sale_price'] > 0
    && !empty($product['sale_end'])
    && strtotime($product['sale_end']) >= strtotime('today');

$sale_price       = $is_on_sale ? (float)$product['sale_price'] : 0;
$original_price   = (float)$product['price'];
$display_price    = $is_on_sale ? $sale_price : $original_price;
$discount_percent = $is_on_sale ? round((($original_price - $sale_price) / $original_price) * 100) : 0;
$days_left        = $is_on_sale ? (int)ceil((strtotime($product['sale_end']) - strtotime('today')) / 86400) : 0;
$savings          = $is_on_sale ? ($original_price - $sale_price) : 0;

// ============================================
// ✅ CALCULATE PAYMENT BASED ON CLINIC POLICY
// ============================================
$payment_info = calculatePaymentAmounts($conn, $clinic_id, $display_price);

$requires_downpayment = $payment_info['requires_payment'];
$downpayment_percent = 0;
$downpayment_amount_calculated = 0;
$balance_amount_calculated = 0;
$payment_type_label = $payment_info['payment_type'];

if ($payment_type_label == 'downpayment') {
    $downpayment_percent = $clinic_downpayment_percent;
    $downpayment_amount_calculated = $payment_info['downpayment_amount'];
    $balance_amount_calculated = $payment_info['balance_amount'];
} elseif ($payment_type_label == 'full') {
    $downpayment_percent = 100;
    $downpayment_amount_calculated = $payment_info['downpayment_amount'];
    $balance_amount_calculated = 0;
} elseif ($payment_type_label == 'onsite') {
    $downpayment_percent = 0;
    $downpayment_amount_calculated = 0;
    $balance_amount_calculated = $display_price;
} else {
    $downpayment_percent = 0;
    $downpayment_amount_calculated = 0;
    $balance_amount_calculated = 0;
}

$clinic_payment_policy_display = '';
$policy_labels = [
    'full_payment' => '100% Full Payment',
    'downpayment_30' => '30% Downpayment',
    'downpayment_custom' => $clinic_downpayment_percent . '% Downpayment',
    'pay_on_site' => 'Pay On-Site Only',
    'no_payment' => 'Free Service'
];
$clinic_payment_policy_display = $policy_labels[$clinic_payment_policy] ?? 'Standard Payment';

// ============================================
// CATEGORY LOGIC
// ============================================
$category = $product['category'];

// Categories that require appointment (with grado)
$NEEDS_LENS_SELECTION = in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses', 'Contact Lenses']);
// Direct reservation only (no appointment)
$IS_ACCESSORY = in_array($category, ['Accessories', 'Parts', 'Cleaning Kits']);
// Direct appointment (services)
$IS_SERVICE = in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening']);
// Contact lens specific
$IS_CONTACT_LENS = ($category === 'Contact Lenses');
// Lenses only (no frame option)
$IS_LENS_ONLY = ($category === 'Lenses');

// ============================================
// GET COLORS / VARIANTS
// ============================================
$colors_query = mysqli_query($conn, "
    SELECT color_code, color_name, quantity
    FROM product_color_inventory
    WHERE product_id = $product_id
    AND clinic_id = $clinic_id
    AND is_available = 1
    ORDER BY color_name
");
$product_colors = [];
$total_available_stock = 0;
while ($c = mysqli_fetch_assoc($colors_query)) {
    $product_colors[] = [
        'code'     => $c['color_code'],
        'name'     => $c['color_name'],
        'quantity' => (int)$c['quantity'],
    ];
    if ((int)$c['quantity'] > 0) {
        $total_available_stock += (int)$c['quantity'];
    }
}
$has_colors        = !empty($product_colors);
$is_fully_sold_out = $has_colors && $total_available_stock === 0;
$has_stock_tracking = $has_colors;

// ============================================
// GET PRODUCT IMAGES
// ============================================
function getProductImages($product) {
    $images = [];
    if (!empty($product['images_json'])) {
        $imgs = json_decode($product['images_json'], true);
        if (!empty($imgs) && is_array($imgs)) {
            foreach ($imgs as $img) {
                $imgPath = str_replace('uploads/uploads/', 'uploads/', $img);
                $images[] = strpos($imgPath, 'uploads/') === 0
                    ? '/' . $imgPath
                    : '/uploads/products/' . $imgPath;
            }
        }
    }
    if (empty($images) && !empty($product['images'])) {
        $imgData = $product['images'];
        if (strpos($imgData, '[') === 0) {
            $imgs = json_decode($imgData, true);
            if (!empty($imgs) && is_array($imgs)) {
                foreach ($imgs as $img) {
                    $imgPath = str_replace('uploads/uploads/', 'uploads/', $img);
                    $images[] = strpos($imgPath, 'uploads/') === 0
                        ? '/' . $imgPath
                        : '/uploads/products/' . $imgPath;
                }
            }
        } else {
            $imgPath = str_replace('uploads/uploads/', 'uploads/', $imgData);
            $images[] = strpos($imgPath, 'uploads/') === 0
                ? '/' . $imgPath
                : '/uploads/products/' . $imgPath;
        }
    }
    if (empty($images) && !empty($product['image'])) {
        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
            $images[] = '/assets/images/products/' . $product['image'];
        } else {
            $imgPath = str_replace('uploads/uploads/', 'uploads/', $product['image']);
            $images[] = strpos($imgPath, 'uploads/') === 0
                ? '/' . $imgPath
                : '/uploads/products/' . $imgPath;
        }
    }
    if (empty($images)) $images[] = '/assets/img/no-image.png';
    return $images;
}

$product_images = getProductImages($product);
$has_multiple_images = count($product_images) > 1;

// ============================================
// CHECK 3D MODEL
// ============================================
$has_3d = false;
if (!empty($product['inventory_id'])) {
    $r3d = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT completed_model_file FROM custom_3d_requests
         WHERE inventory_id = {$product['inventory_id']}
         AND status = 'completed'
         AND completed_model_file IS NOT NULL
         ORDER BY completed_at DESC LIMIT 1"
    ));
    if ($r3d && !empty($r3d['completed_model_file'])) $has_3d = true;
}

// ============================================
// CHECK EXISTING ACTIVE RESERVATION
// ============================================
$existing_reservation = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, reservation_code FROM reservations
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending', 'confirmed') LIMIT 1"
)) ?? null;

// ============================================
// CHECK IF PRODUCT IS FAVORITED
// ============================================
$fav_check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
$is_product_favorited = mysqli_num_rows($fav_check) > 0;

// ============================================
// CHECK EXISTING ACTIVE APPOINTMENT (for this product)
// ============================================
$existing_appointment = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, ref_no FROM appointments
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending', 'confirmed') LIMIT 1"
)) ?? null;

// ============================================
// GET SIMILAR PRODUCTS (same clinic, same category)
// ============================================
$similar_query = mysqli_query($conn, "
    SELECT p.*
    FROM products p
    WHERE p.clinic_id = $clinic_id
    AND p.id != $product_id
    AND p.category = '" . mysqli_real_escape_string($conn, $category) . "'
    LIMIT 4
");

// ============================================
// ✅ FIXED: HANDLE AJAX SUBMISSION
// ============================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    $action = mysqli_real_escape_string($conn, $_POST['ajax_action']); // 'reserve' or 'appointment'
    $lens_type = mysqli_real_escape_string($conn, $_POST['lens_type'] ?? 'frame_only');
    $prescription_knowledge = mysqli_real_escape_string($conn, $_POST['prescription_knowledge'] ?? '');
    $od_sph = mysqli_real_escape_string($conn, $_POST['od_sph'] ?? '');
    $od_cyl = mysqli_real_escape_string($conn, $_POST['od_cyl'] ?? '');
    $od_axis = mysqli_real_escape_string($conn, $_POST['od_axis'] ?? '');
    $os_sph = mysqli_real_escape_string($conn, $_POST['os_sph'] ?? '');
    $os_cyl = mysqli_real_escape_string($conn, $_POST['os_cyl'] ?? '');
    $os_axis = mysqli_real_escape_string($conn, $_POST['os_axis'] ?? '');
    $preferred_date = mysqli_real_escape_string($conn, $_POST['preferred_date'] ?? '');
    $preferred_time = mysqli_real_escape_string($conn, $_POST['preferred_time'] ?? '');
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number'] ?? '');
    $color_code = mysqli_real_escape_string($conn, $_POST['color_code'] ?? '');
    $color_name = mysqli_real_escape_string($conn, $_POST['color_name'] ?? '');
    $frame_size = mysqli_real_escape_string($conn, $_POST['frame_size'] ?? '');

    // Basic validations
    if (!$preferred_date || !$preferred_time) {
        echo json_encode(['success' => false, 'message' => 'Please select a date and time.']);
        exit();
    }
    if (strtotime($preferred_date) < strtotime('today')) {
        echo json_encode(['success' => false, 'message' => 'Date must be today or in the future.']);
        exit();
    }

    // ============================================
    // DETERMINE FLOW
    // ============================================
    $make_appointment = false;

    if ($IS_SERVICE) {
        // Always appointment
        $make_appointment = true;
    } elseif ($IS_ACCESSORY) {
        // Always reservation
        $make_appointment = false;
    } elseif ($NEEDS_LENS_SELECTION) {
        if ($lens_type === 'frame_only') {
            // Frame only = reservation, no prescription needed
            $make_appointment = false;
        } else {
            // With lenses = check prescription knowledge
            $make_appointment = ($prescription_knowledge === 'dont_know');
        }
    }

    // ============================================
    // SAVE PRESCRIPTION (if knows it)
    // ============================================
    $prescription_id = null;
    if (!$make_appointment && $prescription_knowledge === 'know' && ($od_sph || $os_sph)) {
        $insert_pres = mysqli_query($conn, "
            INSERT INTO user_prescriptions
            (user_id, product_id, od_sph, od_cyl, od_axis, os_sph, os_cyl, os_axis, prescription_source)
            VALUES ($user_id, $product_id, '$od_sph', '$od_cyl', '$od_axis', '$os_sph', '$os_cyl', '$os_axis', 'user_input')
        ");
        if ($insert_pres) $prescription_id = mysqli_insert_id($conn);
    }

    // ============================================
    // APPOINTMENT FLOW
    // ============================================
    if ($make_appointment) {
        // Check for duplicate appointment for this product
        $dup_apt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM appointments WHERE user_id = $user_id AND product_id = $product_id AND status IN ('pending','confirmed') LIMIT 1"
        ));
        if ($dup_apt) {
            echo json_encode(['success' => false, 'message' => 'You already have an active appointment for this product.']);
            exit();
        }

        // Check time conflict
        $conflict = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT a.*, c.name as clinic_name FROM appointments a
             JOIN clinics c ON a.clinic_id = c.id
             WHERE a.user_id = $user_id
             AND a.appointment_date = '$preferred_date'
             AND a.appointment_time = '$preferred_time'
             AND a.status != 'cancelled'"
        ));
        if ($conflict) {
            echo json_encode(['success' => false, 'message' => 'You already have an appointment at ' . $conflict['clinic_name'] . ' at that time. Please choose another slot.']);
            exit();
        }

        $ref_no = 'APP-' . strtoupper(substr(uniqid(), -8));
        $notes_final = $notes ?: ($IS_SERVICE ? 'Service booking' : 'Needs eye exam before lens fitting');

        // Determine item_type
        $item_type_db = $IS_SERVICE ? 'service' : 'product';

        mysqli_query($conn, "
            INSERT INTO appointments
            (ref_no, clinic_id, item_id, item_type, user_id, product_id,
             appointment_date, appointment_time, status, payment_status,
             appointment_type, total_amount, downpayment_amount, balance_amount,
             notes, contact_number)
            VALUES
            ('$ref_no', $clinic_id, $product_id, '$item_type_db', $user_id, $product_id,
             '$preferred_date', '$preferred_time', 'pending', 'pending',
             'online', {$product['price']}, 0, {$product['price']},
             '$notes_final', '$contact_number')
        ");

        $appointment_id = mysqli_insert_id($conn);

        // Link prescription if saved
        if ($prescription_id) {
            mysqli_query($conn, "UPDATE user_prescriptions SET appointment_id = $appointment_id WHERE id = $prescription_id");
        }

        addNotification($user_id, 'appointment', 'Appointment Created',
            "Your appointment at {$product['clinic_name']} is scheduled for $preferred_date at $preferred_time. Reference: $ref_no",
            'my-appointments.php'
        );

        echo json_encode([
            'success' => true,
            'type' => 'appointment',
            'message' => 'Appointment booked! The clinic will confirm your schedule.',
            'redirect' => 'my-appointments.php'
        ]);
        exit();
    }

    // ============================================
    // ✅ RESERVATION FLOW - FIXED WITH AUTO_INCREMENT
    // ============================================
    // Check duplicate reservation
    $dup_res = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM reservations WHERE user_id = $user_id AND product_id = $product_id AND status IN ('pending', 'confirmed') LIMIT 1"
    ));
    if ($dup_res) {
        echo json_encode(['success' => false, 'message' => 'You already have an active reservation for this product.']);
        exit();
    }

    // Calculate pricing using clinic payment config
    $lens_prices = [
        'frame_only'      => 0,
        'single_vision'   => 500,
        'progressive'     => 1500,
        'blue_cut'        => 800,
        'contact_daily'   => 0,
        'contact_monthly' => 0,
    ];
    $lens_price_add = $lens_prices[$lens_type] ?? 0;
    $total_amount = $product['price'] + $lens_price_add;

    // ✅ USE CLINIC PAYMENT CONFIGURATION
    $payment_info = calculatePaymentAmounts($conn, $clinic_id, $total_amount);

    $downpayment_amount = $payment_info['downpayment_amount'];
    $balance_amount = $payment_info['balance_amount'];
    $requires_payment = $payment_info['requires_payment'];
    $payment_policy = $payment_info['policy'];
    $payment_type = $payment_info['payment_type'];

    $reservation_code = 'RES-' . strtoupper(substr(uniqid(), -8));

    // ✅ Set expiration and status
    if ($requires_payment) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $payment_status = 'unpaid';
        $reservation_status = 'pending';
    } else {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        if ($payment_type == 'onsite') {
            $payment_status = 'onsite';
        } else {
            $payment_status = 'free';
        }
        $reservation_status = 'confirmed';
    }

    // ✅ FIXED: REMOVED 'id' from INSERT - let AUTO_INCREMENT handle it!
    $insert_query = "
        INSERT INTO reservations
        (reservation_code, user_id, product_id, clinic_id, lens_type, prescription_id,
         color_code, color_name,
         total_amount, downpayment_amount, balance_amount,
         preferred_date, preferred_time, notes, status, payment_status, expires_at, created_at)
        VALUES
        ('$reservation_code', $user_id, $product_id, $clinic_id, '$lens_type',
         " . ($prescription_id ? $prescription_id : 'NULL') . ",
         '$color_code', '$color_name',
         $total_amount, $downpayment_amount, $balance_amount,
         '$preferred_date', '$preferred_time', '$notes', '$reservation_status', '$payment_status', '$expires_at', NOW())
    ";

    $result = mysqli_query($conn, $insert_query);

    if (!$result) {
        error_log("Reservation INSERT failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to create reservation. Please try again.']);
        exit();
    }

    $reservation_id = mysqli_insert_id($conn);

    if (!$reservation_id || $reservation_id == 0) {
        error_log("Reservation ID is 0 after insert. AUTO_INCREMENT may not be set on id column.");
        echo json_encode(['success' => false, 'message' => 'Database configuration error. Please contact support.']);
        exit();
    }

    // Link prescription
    if ($prescription_id) {
        mysqli_query($conn, "UPDATE user_prescriptions SET reservation_id = $reservation_id WHERE id = $prescription_id");
    }

    // ✅ Determine redirect URL based on booking flow
    if ($requires_payment) {
        if ($clinic_booking_flow === 'pay_first') {
            $redirect_url = 'payment.php?reservation_id=' . $reservation_id;
            if ($payment_type == 'full') {
                $message = 'Reservation created! Please proceed to full payment of ₱' . number_format($downpayment_amount, 2) . '.';
            } else {
                $downpayment_percent_display = ($payment_policy == 'downpayment_custom') ? $clinic_downpayment_percent : 30;
                $message = 'Reservation created! Please proceed to pay ' . $downpayment_percent_display . '% downpayment of ₱' . number_format($downpayment_amount, 2) . '.';
            }
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. Please pay to confirm your reservation.";
        } else {
            $redirect_url = 'my-reservations.php';
            if ($payment_type == 'full') {
                $message = 'Reservation created! Please wait for clinic approval before making full payment.';
            } else {
                $downpayment_percent_display = ($payment_policy == 'downpayment_custom') ? $clinic_downpayment_percent : 30;
                $message = 'Reservation created! Please wait for clinic approval before paying ' . $downpayment_percent_display . '% downpayment.';
            }
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. Please wait for clinic approval before making payment.";
        }
    } else {
        if ($payment_type == 'onsite') {
            $redirect_url = 'my-reservations.php';
            $message = 'Reservation confirmed! Please pay ₱' . number_format($total_amount, 2) . ' at the clinic on your visit date.';
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. Please pay at the clinic on your visit date.";
        } else {
            $redirect_url = 'my-reservations.php';
            $message = 'Reservation confirmed! This is a free service. No payment required.';
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. This is a free service.";
        }
    }

    addNotification($user_id, 'reservation', 'Reservation Created', $notification_message, $redirect_url);

    echo json_encode([
        'success' => true,
        'type' => 'reservation',
        'message' => $message,
        'redirect' => $redirect_url,
        'reservation_id' => $reservation_id,
        'downpayment' => $downpayment_amount,
        'total' => $total_amount,
        'requires_payment' => $requires_payment,
        'payment_policy' => $payment_policy,
        'payment_type' => $payment_type,
        'booking_flow' => $clinic_booking_flow
    ]);
    exit();
}

$active_nav = 'discover';
include '../includes/navbar.php';
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($product['name']); ?> — Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <style>
    /* ===== RESET & VARIABLES ===== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    html, body { width: 100%; overflow-x: hidden; }

    :root {
        --primary: #00B761;
        --primary-dark: #008F4C;
        --primary-light: #E8FAF0;
        --primary-gradient: linear-gradient(135deg, #00B761, #00A86B);
        --bg-primary: #F4F6F9;
        --bg-secondary: #FFFFFF;
        --text-primary: #0D1117;
        --text-secondary: #5A6478;
        --text-muted: #9CA3AF;
        --border-color: #E5E7EB;
        --border-light: #F0F2F5;
        --shadow-sm: 0 1px 4px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
        --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
        --radius-sm: 10px;
        --radius-md: 16px;
        --radius-lg: 24px;
        --radius-full: 999px;
        --danger: #EF4444;
        --warning: #F59E0B;
        --success: #00B761;
        --info: #3B82F6;
        --font-main: 'DM Sans', sans-serif;
        --font-display: 'DM Serif Display', serif;
    }

    .theme-dark {
        --bg-primary: #0D0F14;
        --bg-secondary: #161B25;
        --text-primary: #F0F4FF;
        --text-secondary: #8892A4;
        --text-muted: #4B5563;
        --border-color: #252D3D;
        --border-light: #1C2235;
        --shadow-sm: 0 1px 4px rgba(0,0,0,0.3);
        --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
        --primary-light: #0A2018;
    }

    body {
        font-family: var(--font-main);
        background: var(--bg-primary);
        color: var(--text-primary);
        transition: background 0.3s, color 0.3s;
    }

    /* ===== LAYOUT ===== */
    .main-content {
        max-width: 1300px;
        margin: 0 auto;
        padding: 28px 20px 80px;
    }
    @media (min-width: 1024px) { .main-content { padding: 32px 40px 60px; } }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* ===== BREADCRUMB ===== */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 24px;
        font-size: 13px;
        color: var(--text-muted);
        flex-wrap: wrap;
    }
    .breadcrumb a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        background: var(--bg-secondary);
        border-radius: var(--radius-full);
        border: 1px solid var(--border-light);
        transition: all 0.2s;
    }
    .breadcrumb a:hover { background: var(--primary); color: white; }
    .breadcrumb .sep { color: var(--border-color); }
    .breadcrumb .current { color: var(--text-secondary); }

    /* ===== PRODUCT GRID ===== */
    .product-grid {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 32px;
        align-items: start;
    }
    @media (max-width: 900px) { .product-grid { grid-template-columns: 1fr; } }

    /* ===== IMAGE SECTION — Lenskart style ===== */
    .image-section {
        position: sticky;
        top: 80px;
    }
    @media (max-width: 900px) { .image-section { position: static; } }

    .image-wrapper {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        display: flex;
        flex-direction: row;
        gap: 0;
    }

    /* Vertical thumbnail strip — left side */
    .thumb-strip {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 12px 10px;
        overflow-y: auto;
        max-height: 440px;
        background: var(--bg-secondary);
        border-right: 1px solid var(--border-light);
        flex-shrink: 0;
    }
    .thumb-strip::-webkit-scrollbar { width: 3px; }
    .thumb-strip::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }

    .thumb {
        flex-shrink: 0;
        width: 64px;
        height: 64px;
        border-radius: 10px;
        overflow: hidden;
        border: 2px solid var(--border-color);
        cursor: pointer;
        transition: all 0.2s;
        background: var(--bg-primary);
    }
    .thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
    .thumb:hover, .thumb.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(0,183,97,0.2); }

    /* Main image area — right of thumbs */
    .main-image-area {
        position: relative;
        flex: 1;
        height: 440px;
        overflow: hidden;
        background: var(--bg-primary);
        cursor: zoom-in;
    }
    @media (max-width: 768px) { .main-image-area { height: 300px; } }

    /* Custom slider — replaces Swiper for reliability */
    .product-swiper {
        width: 100%;
        height: 440px;
        position: relative;
        overflow: hidden;
    }
    @media (max-width: 900px) { .product-swiper { height: 360px; } }
    @media (max-width: 768px) { .product-swiper { height: 300px; } }
    @media (max-width: 600px) { .product-swiper { height: 280px; } }

    .swiper-wrapper {
        display: flex;
        width: 100%;
        height: 100%;
        transition: transform 0.4s ease;
        will-change: transform;
    }
    .swiper-slide {
        min-width: 100%;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-primary);
        flex-shrink: 0;
    }
    .swiper-slide img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
        transition: transform 0.3s;
    }
    .swiper-slide img:hover { transform: scale(1.04); }

    /* Dot pagination */
    .swiper-pagination {
        position: absolute;
        bottom: 8px;
        left: 0; right: 0;
        display: flex;
        justify-content: center;
        gap: 6px;
        z-index: 10;
        pointer-events: none;
    }
    .swiper-pagination-bullet {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: var(--primary);
        opacity: 0.35;
        transition: opacity 0.2s, transform 0.2s;
        pointer-events: all;
        cursor: pointer;
        border: none;
        padding: 0;
    }
    .swiper-pagination-bullet-active { opacity: 1; transform: scale(1.3); }

    .img-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: rgba(0,0,0,0.55);
        color: white;
        padding: 3px 9px;
        border-radius: var(--radius-full);
        font-size: 11px;
        font-weight: 600;
        z-index: 10;
        backdrop-filter: blur(4px);
        pointer-events: none;
    }

    /* zoom icon overlay on hover */
    .main-image-area::after {
        content: '\f00e';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        bottom: 12px;
        right: 12px;
        background: rgba(0,0,0,0.45);
        color: white;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        opacity: 0;
        transition: opacity 0.2s;
        pointer-events: none;
        z-index: 10;
    }
    .main-image-area:hover::after { opacity: 1; }

    .img-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 32px;
        height: 32px;
        background: rgba(0,0,0,0.4);
        border: none;
        border-radius: 50%;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 10;
        transition: all 0.2s;
        backdrop-filter: blur(4px);
        font-size: 13px;
    }
    .img-nav:hover { background: var(--primary); transform: translateY(-50%) scale(1.1); }
    .img-nav.prev { left: 10px; }
    .img-nav.next { right: 10px; }

    /* 3D Button below image */
    .btn-3d-full {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #0EA5E9, #0369A1);
        color: white;
        border: none;
        cursor: pointer;
        font-family: var(--font-main);
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        text-decoration: none;
    }
    .btn-3d-full:hover { opacity: 0.92; }

    /* Responsive: on mobile, thumbs go horizontal on top */
    @media (max-width: 600px) {
        .image-wrapper { flex-direction: column-reverse; }
        .thumb-strip {
            flex-direction: row;
            max-height: none;
            overflow-x: auto;
            overflow-y: hidden;
            border-right: none;
            border-top: 1px solid var(--border-light);
            padding: 10px 12px;
            width: 100%;
        }
        .thumb { width: 54px; height: 54px; }
        .main-image-area { height: 280px; }
    }

    @media (max-width: 900px) {
        .image-wrapper { flex-direction: column-reverse; }
        .thumb-strip {
            flex-direction: row;
            max-height: none;
            overflow-x: auto;
            overflow-y: hidden;
            border-right: none;
            border-top: 1px solid var(--border-light);
            padding: 10px 14px;
            width: 100%;
        }
        .thumb { width: 60px; height: 60px; }
        .main-image-area { height: 360px; }
    }

    @media (min-width: 901px) {
        .image-wrapper { flex-direction: row; }
        .thumb-strip {
            flex-direction: column;
            max-height: 440px;
            overflow-y: auto;
            overflow-x: hidden;
            border-right: 1px solid var(--border-light);
            border-top: none;
            padding: 12px 10px;
            width: auto;
        }
        .thumb { width: 64px; height: 64px; }
        .main-image-area { height: 440px; }
    }

    /* ===== LENSKART-STYLE LIGHTBOX ===== */
    .lk-lightbox {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.88);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .lk-lightbox.show { display: flex; }

    .lk-lb-inner {
        display: flex;
        gap: 0;
        width: 100%;
        max-width: 1000px;
        max-height: 90vh;
        background: white;
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
        animation: lbIn 0.25s ease;
    }
    .theme-dark .lk-lb-inner { background: #1A1A1A; }
    @keyframes lbIn { from { opacity:0; transform: scale(0.96); } to { opacity:1; transform: none; } }

    /* Lightbox thumbnail strip */
    .lk-lb-thumbs {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 16px 12px;
        overflow-y: auto;
        max-height: 90vh;
        background: #f8f8f8;
        border-right: 1px solid #eee;
        flex-shrink: 0;
        width: 90px;
    }
    .theme-dark .lk-lb-thumbs { background: #111; border-right-color: #2d2d2d; }
    .lk-lb-thumbs::-webkit-scrollbar { width: 3px; }
    .lk-lb-thumbs::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }

    .lk-lb-thumb {
        width: 66px;
        height: 66px;
        border-radius: 8px;
        overflow: hidden;
        border: 2px solid transparent;
        cursor: pointer;
        background: white;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .theme-dark .lk-lb-thumb { background: #222; }
    .lk-lb-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
    .lk-lb-thumb.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(0,183,97,0.25); }

    /* Lightbox main image */
    .lk-lb-main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        position: relative;
        background: white;
        overflow: hidden;
    }
    .theme-dark .lk-lb-main { background: #1A1A1A; }

    .lk-lb-main img {
        max-width: 100%;
        max-height: calc(90vh - 48px);
        object-fit: contain;
        border-radius: 4px;
        transition: opacity 0.2s;
        user-select: none;
    }

    /* Lightbox nav arrows */
    .lk-lb-prev, .lk-lb-next {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 42px;
        height: 42px;
        background: rgba(0,0,0,0.12);
        border: none;
        border-radius: 50%;
        color: #333;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        cursor: pointer;
        transition: all 0.2s;
        z-index: 10;
    }
    .theme-dark .lk-lb-prev, .theme-dark .lk-lb-next { background: rgba(255,255,255,0.1); color: white; }
    .lk-lb-prev:hover, .lk-lb-next:hover { background: var(--primary); color: white; transform: translateY(-50%) scale(1.08); }
    .lk-lb-prev { left: 12px; }
    .lk-lb-next { right: 12px; }

    /* Lightbox close */
    .lk-lb-close {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 36px;
        height: 36px;
        background: rgba(0,0,0,0.08);
        border: none;
        border-radius: 50%;
        color: #333;
        font-size: 18px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        z-index: 20;
    }
    .theme-dark .lk-lb-close { background: rgba(255,255,255,0.1); color: white; }
    .lk-lb-close:hover { background: var(--danger); color: white; }

    /* Lightbox counter */
    .lk-lb-counter {
        position: absolute;
        bottom: 14px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0,0,0,0.18);
        color: #333;
        padding: 4px 12px;
        border-radius: var(--radius-full);
        font-size: 12px;
        font-weight: 600;
        backdrop-filter: blur(4px);
    }
    .theme-dark .lk-lb-counter { background: rgba(255,255,255,0.12); color: white; }

    @media (max-width: 700px) {
        .lk-lb-inner { flex-direction: column; max-height: 95vh; }
        .lk-lb-thumbs {
            flex-direction: row;
            max-height: none;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            height: auto;
            padding: 10px 12px;
            border-right: none;
            border-bottom: 1px solid #eee;
        }
        .lk-lb-thumb { width: 50px; height: 50px; flex-shrink: 0; }
        .lk-lb-main { padding: 16px; }
        .lk-lb-main img { max-height: calc(95vh - 140px); }
        .lk-lightbox { padding: 8px; }
    }

    /* ===== INFO SECTION ===== */
    .info-section { display: flex; flex-direction: column; gap: 18px; }

    .product-meta {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 28px;
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
    }

    .category-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 14px;
        background: var(--primary-light);
        color: var(--primary);
        border-radius: var(--radius-full);
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 14px;
    }

    .product-title {
        font-family: var(--font-display);
        font-size: 30px;
        line-height: 1.2;
        color: var(--text-primary);
        margin-bottom: 8px;
    }
    @media (max-width: 768px) { .product-title { font-size: 24px; } }

    .price-row {
        display: flex;
        align-items: baseline;
        gap: 10px;
        margin: 16px 0 6px;
        padding: 16px;
        background: var(--primary-light);
        border-radius: var(--radius-md);
    }
    .price-main {
        font-size: 36px;
        font-weight: 700;
        color: var(--primary);
        font-family: var(--font-display);
    }
    .price-main.sale-color { color: #EF4444; }
    .price-label { font-size: 13px; color: var(--text-muted); }
    .price-original {
        font-size: 20px;
        color: var(--text-muted);
        text-decoration: line-through;
        font-weight: 400;
    }

    /* Sale banner */
    .sale-banner {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        background: linear-gradient(135deg, #FF4444, #FF6B6B);
        border-radius: var(--radius-md);
        margin-bottom: 10px;
        flex-wrap: wrap;
    }
    .sale-banner i { color: white; font-size: 14px; }
    .sale-banner span { color: white; font-size: 13px; font-weight: 600; }
    .sale-banner .sale-pct {
        background: rgba(255,255,255,0.25);
        padding: 2px 10px;
        border-radius: var(--radius-full);
        font-size: 12px;
    }
    .sale-banner .sale-timer {
        margin-left: auto;
        font-size: 12px;
        font-weight: 500;
        opacity: 0.9;
    }

    /* Savings row */
    .savings-row {
        font-size: 12px;
        color: var(--success);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 12px;
        padding: 0 4px;
    }
    .savings-row i { color: var(--success); }

    .product-desc {
        color: var(--text-secondary);
        line-height: 1.8;
        font-size: 14px;
        margin-bottom: 6px;
    }

    /* Payment Policy Info Card */
    .payment-policy-card {
        margin-top: 16px;
        padding: 14px;
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        border-left: 4px solid var(--primary);
        font-size: 13px;
    }
    .payment-policy-card i {
        color: var(--primary);
        margin-right: 8px;
    }
    .payment-policy-card .policy-title {
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    .payment-policy-card .policy-desc {
        color: var(--text-secondary);
        font-size: 12px;
    }

    /* Existing reservation/appointment notice */
    .existing-notice {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-radius: var(--radius-md);
        font-size: 13px;
        font-weight: 500;
    }
    .existing-notice.reservation { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .existing-notice.appointment { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
    .existing-notice a { color: inherit; font-weight: 700; }

    /* ===== LENS SELECTION CARD ===== */
    .flow-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 24px;
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
    }
    .flow-card-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 18px;
    }
    .flow-card-title i { color: var(--primary); }

    /* Lens option buttons */
    .lens-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 6px;
    }
    @media (max-width: 500px) { .lens-grid { grid-template-columns: 1fr; } }

    .lens-btn {
        padding: 14px 12px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        cursor: pointer;
        transition: all 0.2s;
        text-align: left;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .lens-btn:hover { border-color: var(--primary); background: var(--primary-light); }
    .lens-btn.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
    }
    .lens-btn .ln { font-weight: 700; font-size: 14px; color: var(--text-primary); }
    .lens-btn .lp { font-size: 12px; color: var(--primary); font-weight: 600; }
    .lens-btn .ld { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

    /* Prescription toggle */
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

    /* Prescription form */
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
    .rx-input-group label { font-size: 9px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 3px; font-weight: 600; letter-spacing: 0.5px; }
    .rx-input-group input {
        width: 100%;
        padding: 8px 6px;
        border: 1.5px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-size: 13px;
        text-align: center;
        font-family: var(--font-main);
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

    /* Eye exam info box */
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

    /* Price summary strip */
    .price-summary {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-top: 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        border: 1px solid var(--border-light);
    }
    .price-summary .ps-item { font-size: 13px; color: var(--text-secondary); }
    .price-summary .ps-total { font-size: 16px; font-weight: 700; color: var(--primary); }

    /* ===== COLOR SELECTOR ===== */
    .color-selector-box {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 16px;
        margin-bottom: 6px;
        border: 1px solid var(--border-light);
    }

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
    .cs-selected-label {
        font-weight: 500;
        color: var(--text-secondary);
        font-size: 13px;
        margin-left: auto;
    }
    .cs-selected-label.chosen { color: var(--primary); font-weight: 600; }

    .color-btns {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }

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
        font-family: var(--font-main, inherit);
    }
    .color-btn:hover:not(:disabled):not(.oos) {
        border-color: var(--primary);
        background: var(--primary-light);
    }
    .color-btn.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
    }
    .color-btn.oos {
        opacity: 0.45;
        cursor: not-allowed;
        border-style: dashed;
    }
    .color-dot {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 1.5px solid rgba(0,0,0,0.15);
        flex-shrink: 0;
    }
    .color-btn-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-primary);
    }
    .color-btn-qty {
        font-size: 11px;
        color: var(--text-muted);
        margin-left: 2px;
    }
    .color-btn-qty.low { color: var(--warning); font-weight: 600; }
    .color-btn-qty.oos-label { color: var(--danger); font-weight: 600; }

    .cs-hint {
        font-size: 11px;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .cs-hint i { color: var(--primary); }

    .sold-out-notice {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        background: #FEE2E2;
        border: 1px solid #FECACA;
        border-radius: var(--radius-md);
        padding: 14px;
    }
    .theme-dark .sold-out-notice { background: #3B0F0F; border-color: #7F1D1D; }
    .sold-out-notice > i { color: var(--danger); font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .sold-out-notice strong { font-size: 14px; color: var(--danger); display: block; margin-bottom: 3px; }
    .sold-out-notice p { font-size: 12px; color: var(--text-secondary); }

    /* ===== ACTION CARD ===== */
    .action-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 20px;
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
    }

    .btn-main-action {
        width: 100%;
        padding: 16px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 16px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.2s;
        letter-spacing: 0.3px;
        box-shadow: 0 4px 16px rgba(0,183,97,0.3);
    }
    .btn-main-action:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,183,97,0.4);
    }
    .btn-main-action:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }

    .btn-main-action.appointment-style {
        background: linear-gradient(135deg, #3B82F6, #2563EB);
        box-shadow: 0 4px 16px rgba(59,130,246,0.3);
    }
    .btn-main-action.appointment-style:hover:not(:disabled) {
        box-shadow: 0 8px 24px rgba(59,130,246,0.4);
    }

    .action-helper {
        font-size: 12px;
        color: var(--text-muted);
        text-align: center;
        margin-top: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }

    /* ===== CLINIC CARD ===== */
    .clinic-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 20px;
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }
    .clinic-logo-box {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: var(--primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .clinic-logo-box img { width: 100%; height: 100%; object-fit: cover; }
    .clinic-logo-box i { font-size: 24px; color: var(--primary); }
    .clinic-info { flex: 1; min-width: 0; }
    .clinic-info .cn { font-weight: 700; font-size: 15px; color: var(--text-primary); }
    .clinic-info .ca { font-size: 12px; color: var(--text-secondary); margin-top: 3px; display: flex; align-items: flex-start; gap: 4px; }
    .clinic-info .ch { font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 4px; }
    .clinic-info .ca i, .clinic-info .ch i { color: var(--primary); flex-shrink: 0; margin-top: 1px; }
    .clinic-btns { display: flex; gap: 8px; margin-top: 12px; }
    .btn-clinic-sm {
        flex: 1;
        padding: 8px 10px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        transition: all 0.2s;
    }
    .btn-clinic-sm.view { background: var(--bg-primary); color: var(--primary); border: 1px solid var(--border-light); }
    .btn-clinic-sm.view:hover { background: var(--primary-light); }
    .btn-clinic-sm.message { background: var(--bg-primary); color: var(--primary); border: 1px solid var(--border-light); }
    .btn-clinic-sm.message:hover { background: var(--primary); color: white; border-color: var(--primary); }

    /* ===== SIMILAR PRODUCTS ===== */
    .similar-section { margin-top: 48px; }
    .similar-section h2 {
        font-family: var(--font-display);
        font-size: 24px;
        margin-bottom: 20px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .similar-section h2 i { color: var(--primary); font-size: 20px; }

    .similar-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 16px;
    }
    .similar-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        overflow: hidden;
        border: 1px solid var(--border-light);
        text-decoration: none;
        display: block;
        transition: all 0.2s;
    }
    .similar-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--primary); }
    .similar-img { height: 120px; overflow: hidden; background: var(--bg-primary); }
    .similar-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
    .similar-card:hover .similar-img img { transform: scale(1.05); }
    .similar-info { padding: 12px; }
    .similar-name { font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .similar-price { font-size: 14px; font-weight: 700; color: var(--primary); }

    /* ===== MODAL ===== */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(5px);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .modal-overlay.show { display: flex; }

    .modal-box {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        width: 100%;
        max-width: 460px;
        max-height: 90vh;
        overflow-y: auto;
        animation: modalIn 0.25s ease;
    }
    @keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: none; } }

    .modal-head {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--primary-gradient);
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    }
    .modal-head h3 { font-size: 16px; font-weight: 700; color: white; display: flex; align-items: center; gap: 8px; }
    .modal-close { background: none; border: none; color: white; font-size: 22px; cursor: pointer; opacity: 0.8; line-height: 1; }
    .modal-close:hover { opacity: 1; }

    .modal-body { padding: 22px 24px; }

    .form-group { margin-bottom: 16px; }
    .form-label { font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 7px; display: block; }
    .form-input {
        width: 100%;
        padding: 11px 14px;
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 14px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-family: var(--font-main);
        transition: border-color 0.2s;
    }
    .form-input:focus { outline: none; border-color: var(--primary); }

    .modal-price-box {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-top: 6px;
        border: 1px solid var(--border-light);
    }
    .mpb-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; margin-bottom: 6px; }
    .mpb-row:last-child { margin-bottom: 0; }
    .mpb-row .mpb-label { color: var(--text-secondary); }
    .mpb-row .mpb-value { font-weight: 600; color: var(--text-primary); }
    .mpb-divider { height: 1px; background: var(--border-color); margin: 10px 0; }
    .mpb-row.total .mpb-label { font-weight: 700; color: var(--text-primary); }
    .mpb-row.total .mpb-value { font-size: 16px; color: var(--primary); font-weight: 700; }
    .mpb-row.dp .mpb-value { color: var(--warning); }

    .modal-apt-notice {
        background: #EFF6FF;
        border: 1px solid #BFDBFE;
        border-radius: var(--radius-md);
        padding: 14px;
        text-align: center;
        font-size: 13px;
        color: #1E40AF;
        margin-top: 8px;
    }
    .theme-dark .modal-apt-notice { background: #1E2A4A; border-color: #2D4A8A; color: #93C5FD; }

    .modal-foot {
        padding: 16px 24px;
        border-top: 1px solid var(--border-light);
        display: flex;
        gap: 10px;
    }
    .btn-modal {
        flex: 1;
        padding: 12px;
        border-radius: var(--radius-sm);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        font-family: var(--font-main);
        transition: all 0.2s;
    }
    .btn-modal.confirm { background: var(--primary-gradient); color: white; }
    .btn-modal.confirm:hover { opacity: 0.9; }
    .btn-modal.confirm.apt { background: linear-gradient(135deg, #3B82F6, #2563EB); }
    .btn-modal.cancel { background: var(--bg-primary); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .btn-modal.cancel:hover { border-color: var(--danger); color: var(--danger); }

    /* Toast */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9990; }
    .toast {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        padding: 14px 20px;
        box-shadow: var(--shadow-lg);
        margin-bottom: 10px;
        min-width: 300px;
        max-width: 380px;
        animation: toastIn 0.3s ease;
        border-left: 4px solid var(--success);
    }
    .toast.error { border-left-color: var(--danger); }
    .toast.info { border-left-color: var(--info); }
    .toast i { font-size: 18px; flex-shrink: 0; }
    .toast.success i { color: var(--success); }
    .toast.error i { color: var(--danger); }
    .toast.info i { color: var(--info); }
    .toast span { font-size: 13px; color: var(--text-primary); flex: 1; }
    @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

    /* Loading */
    #loadingOverlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .spinner {
        width: 48px;
        height: 48px;
        border: 4px solid rgba(255,255,255,0.2);
        border-top-color: var(--primary);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hidden { display: none !important; }

    /* ===== PRODUCT PAGE FAVORITE BUTTON ===== */
    .fav-product-wrap { margin-bottom: 12px; }
    .btn-fav-product-full {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 13px 20px;
        border-radius: var(--radius-full, 999px);
        border: 2px solid #e5e7eb;
        background: transparent;
        color: var(--text-secondary, #6B7280);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        font-family: inherit;
    }
    .btn-fav-product-full i { font-size: 16px; color: #ccc; transition: all 0.2s; }
    .btn-fav-product-full:hover { border-color: #EF4444; color: #EF4444; }
    .btn-fav-product-full:hover i { color: #EF4444; }
    .btn-fav-product-full.active { border-color: #EF4444; color: #EF4444; background: #fff5f5; }
    .btn-fav-product-full.active i { color: #EF4444; }
    .btn-fav-product-full.pop { animation: favPop 0.3s ease; }
    @keyframes favPop { 0%{transform:scale(1);} 50%{transform:scale(1.04);} 100%{transform:scale(1);} }
    .theme-dark .btn-fav-product-full { border-color: #333; color: #888; }
    .theme-dark .btn-fav-product-full.active { background: #2a1a1a; border-color: #EF4444; color: #EF4444; }
    /* ===== SIZE SELECTOR ===== */
.size-selector-box {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 16px;
    border: 1px solid var(--border-light);
}
.size-btns {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
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
.size-btn:hover, .size-btn.selected {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary);
}

/* ===== SPECIFICATIONS ACCORDION ===== */
.specs-accordion {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: 12px;
    border: 1px solid var(--border-light);
    overflow: hidden;
}
.specs-header {
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-weight: 600;
    background: var(--bg-primary);
    transition: background 0.2s;
}
.specs-header:hover {
    background: var(--border-light);
}
.specs-content {
    padding: 16px 18px;
    border-top: 1px solid var(--border-light);
}
.specs-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.spec-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    padding: 6px 0;
    border-bottom: 1px dashed var(--border-light);
}
.spec-label {
    color: var(--text-secondary);
    font-weight: 500;
}
.spec-value {
    color: var(--text-primary);
    font-weight: 600;
}

/* Warranty Section - Detailed Display */
.warranty-box {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: 12px;
    border: 1px solid var(--border-light);
    overflow: hidden;
}
.warranty-header {
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-weight: 600;
    background: var(--bg-primary);
}
.warranty-header:hover {
    background: var(--border-light);
}
.warranty-content {
    padding: 16px 18px;
    border-top: 1px solid var(--border-light);
}
.warranty-period-badge {
    background: var(--primary-light);
    padding: 10px 15px;
    border-radius: var(--radius-md);
    margin-bottom: 16px;
    font-weight: 600;
    color: var(--primary);
    display: inline-block;
}
.premium-badge {
    background: #FFC107;
    color: #856404;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    margin-left: 8px;
}
.coverage-title, .exclusions-title, .terms-title, .claim-title, .care-title {
    font-weight: 700;
    margin-bottom: 10px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.coverage-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 16px;
}
.coverage-category {
    flex: 1;
    min-width: 200px;
}
.coverage-category strong {
    font-size: 12px;
    display: block;
    margin-bottom: 6px;
}
.coverage-category ul {
    margin: 0;
    padding-left: 0;
    list-style: none;
}
.coverage-category li {
    font-size: 12px;
    margin: 4px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.coverage-category li i {
    color: var(--success);
    font-size: 10px;
}
.warranty-exclusions {
    background: #FEF3C7;
    border: 1px solid #FDE68A;
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 16px;
}
.theme-dark .warranty-exclusions {
    background: #3B2F0F;
    border-color: #7F6B1D;
}
.warranty-exclusions p {
    font-size: 12px;
    margin: 0;
    line-height: 1.5;
}
.warranty-terms-section {
    margin-bottom: 16px;
}
.warranty-terms-section p {
    font-size: 12px;
    line-height: 1.5;
    color: var(--text-secondary);
}
.warranty-claim {
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-primary);
    border-radius: var(--radius-sm);
}
.warranty-claim p {
    font-size: 12px;
    color: var(--text-secondary);
    margin: 0;
    line-height: 1.5;
}
.warranty-care {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
}
.warranty-care p {
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.5;
}

/* Force prescription section for Lenses */
<?php if ($category === 'Lenses' || $category === 'Contact Lenses'): ?>
#rxSection {
    display: block !important;
}
<?php endif; ?>
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div id="loadingOverlay" class="hidden"><div class="spinner"></div></div>

<div class="main-content">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="clinic-details.php?id=<?php echo $clinic_id; ?>">
            <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($product['clinic_name']); ?>
        </a>
        <span class="sep">/</span>
        <span class="current"><?php echo htmlspecialchars($product['name']); ?></span>
    </div>

    <!-- Main Product Grid -->
    <div class="product-grid">

        <!-- LEFT: Images — Lenskart style -->
        <div class="image-section">
            <div class="image-wrapper">

                <!-- Vertical thumbnail strip (left) -->
                <?php if ($has_multiple_images): ?>
                <div class="thumb-strip" id="thumbStrip">
                    <?php foreach ($product_images as $i => $img): ?>
                    <div class="thumb <?php echo $i === 0 ? 'active' : ''; ?>"
                         onclick="goToSlide(<?php echo $i; ?>)" id="thumb-<?php echo $i; ?>">
                        <img src="<?php echo $img; ?>" alt=""
                             onerror="this.src='/assets/img/no-image.png'">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Main image area (right of thumbs) -->
                <div class="main-image-area" onclick="openLightbox(currentSlide)">
                    <div class="product-swiper" id="productSlider">
                        <div class="swiper-wrapper" id="sliderWrapper">
                            <?php foreach ($product_images as $i => $img): ?>
                            <div class="swiper-slide">
                                <img src="<?php echo $img; ?>"
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     onerror="this.src='/assets/img/no-image.png'">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($has_multiple_images): ?>
                        <div class="swiper-pagination" id="sliderDots"></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($has_multiple_images): ?>
                        <button class="img-nav prev" onclick="event.stopPropagation(); goToSlide(currentSlide - 1)">&#10094;</button>
                        <button class="img-nav next" onclick="event.stopPropagation(); goToSlide(currentSlide + 1)">&#10095;</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($has_3d): ?>
            <a href="3d-view.php?id=<?php echo $product_id; ?>" class="btn-3d-full" style="border-radius:0 0 var(--radius-lg) var(--radius-lg); margin-top:-1px;">
                <i class="fas fa-cube"></i> View in 3D
            </a>
            <?php endif; ?>
        </div>

        <!-- LENSKART-STYLE LIGHTBOX -->
        <div class="lk-lightbox" id="lkLightbox" onclick="closeLightbox()">
            <div class="lk-lb-inner" onclick="event.stopPropagation()">

                <!-- Lightbox thumbnails -->
                <div class="lk-lb-thumbs" id="lbThumbStrip">
                    <?php foreach ($product_images as $i => $img): ?>
                    <div class="lk-lb-thumb <?php echo $i === 0 ? 'active' : ''; ?>"
                         id="lbThumb-<?php echo $i; ?>"
                         onclick="lbGoTo(<?php echo $i; ?>)">
                        <img src="<?php echo $img; ?>" alt=""
                             onerror="this.src='/assets/img/no-image.png'">
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Lightbox main image -->
                <div class="lk-lb-main">
                    <img id="lbMainImg"
                         src="<?php echo $product_images[0]; ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         onerror="this.src='/assets/img/no-image.png'">

                    <?php if (count($product_images) > 1): ?>
                    <button class="lk-lb-prev" onclick="lbGoTo(lbCurrentIndex - 1)">&#10094;</button>
                    <button class="lk-lb-next" onclick="lbGoTo(lbCurrentIndex + 1)">&#10095;</button>
                    <?php endif; ?>

                    <div class="lk-lb-counter" id="lbCounter">1 / <?php echo count($product_images); ?></div>
                </div>

                <!-- Close button -->
                <button class="lk-lb-close" onclick="closeLightbox()">&#10005;</button>
            </div>
        </div>

        <!-- RIGHT: Product Info -->
        <div class="info-section">

            <!-- Product Meta -->
            <div class="product-meta">
                <div class="category-pill">
                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                </div>

                <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                <?php if ($is_on_sale): ?>
                <div class="sale-banner">
                    <i class="fas fa-fire"></i>
                    <span>On Sale!</span>
                    <span class="sale-pct">-<?php echo $discount_percent; ?>%</span>
                    <span class="sale-timer">
                        <?php if ($days_left <= 0): ?>Ends today!
                        <?php elseif ($days_left == 1): ?>1 day left
                        <?php else: echo $days_left . ' days left'; endif; ?>
                    </span>
                </div>
                <div class="price-row">
                    <span class="price-main sale-color">₱<?php echo number_format($sale_price, 2); ?></span>
                    <span class="price-original">₱<?php echo number_format($original_price, 2); ?></span>
                </div>
                <div class="savings-row">
                    <i class="fas fa-piggy-bank"></i> You save ₱<?php echo number_format($savings, 2); ?>
                    — sale ends <?php echo date('M d, Y', strtotime($product['sale_end'])); ?>
                </div>
                <?php else: ?>
                <div class="price-row">
                    <span class="price-main">₱<?php echo number_format($original_price, 2); ?></span>
                    <span class="price-label">Starting price</span>
                </div>
                <?php endif; ?>

                <!-- Payment Policy Info Card -->
                <div class="payment-policy-card">
                    <div class="policy-title">
                        <i class="fas fa-credit-card"></i> Payment Policy: <?php echo $clinic_payment_policy_display; ?>
                        <?php if ($clinic_booking_flow == 'pay_first'): ?>
                            <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-left: 8px;">Pay First</span>
                        <?php else: ?>
                            <span style="background: var(--warning); color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-left: 8px;">Approve First</span>
                        <?php endif; ?>
                    </div>
                    <div class="policy-desc">
                        <?php if ($payment_type_label == 'downpayment'): ?>
                            <i class="fas fa-percent"></i> <?php echo $downpayment_percent; ?>% downpayment (₱<?php echo number_format($downpayment_amount_calculated, 2); ?>) required online.
                            Balance of ₱<?php echo number_format($balance_amount_calculated, 2); ?> to be paid at the clinic.
                        <?php elseif ($payment_type_label == 'full'): ?>
                            <i class="fas fa-cash"></i> 100% full payment of ₱<?php echo number_format($downpayment_amount_calculated, 2); ?> required online.
                        <?php elseif ($payment_type_label == 'onsite'): ?>
                            <i class="fas fa-store"></i> Pay ₱<?php echo number_format($display_price, 2); ?> directly at the clinic. No online payment required.
                        <?php elseif ($payment_type_label == 'free'): ?>
                            <i class="fas fa-gift"></i> This is a free service. No payment required.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($product['description'])): ?>
                <p class="product-desc"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                <?php endif; ?>

                <!-- COLOR / VARIANT SELECTOR -->
                <?php if ($has_stock_tracking && !$IS_SERVICE): ?>
                <div class="color-selector-box">
                    <div class="cs-title">
                        <i class="fas fa-palette"></i>
                        Color / Variant
                        <span class="cs-selected-label" id="csSelectedLabel">— Select a color</span>
                    </div>

                    <?php if ($is_fully_sold_out): ?>
                        <div class="sold-out-notice">
                            <i class="fas fa-times-circle"></i>
                            <div>
                                <strong>Out of Stock</strong>
                                <p>All variants are currently unavailable. You can still browse this product.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="color-btns" id="colorBtns">
                            <?php foreach ($product_colors as $color): ?>
                                <?php $oos = $color['quantity'] <= 0; ?>
                                <button class="color-btn <?php echo $oos ? 'oos' : ''; ?>"
                                        data-code="<?php echo htmlspecialchars($color['code']); ?>"
                                        data-name="<?php echo htmlspecialchars($color['name']); ?>"
                                        data-qty="<?php echo $color['quantity']; ?>"
                                        onclick="selectColor(this)"
                                        <?php echo $oos ? 'disabled' : ''; ?>>
                                    <?php if (!empty($color['code']) && $color['code'] !== '#' && strlen($color['code']) >= 4): ?>
                                        <span class="color-dot" style="background:<?php echo htmlspecialchars($color['code']); ?>"></span>
                                    <?php endif; ?>
                                    <span class="color-btn-name"><?php echo htmlspecialchars($color['name']); ?></span>
                                    <?php if ($oos): ?>
                                        <span class="color-btn-qty oos-label">Out of Stock</span>
                                    <?php elseif ($color['quantity'] <= 5): ?>
                                        <span class="color-btn-qty low"><?php echo $color['quantity']; ?> left</span>
                                    <?php else: ?>
                                        <span class="color-btn-qty"><?php echo $color['quantity']; ?> left</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="cs-hint" id="csHint">
                            <i class="fas fa-info-circle"></i>
                            Please select a color to continue.
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                                <!-- FRAME SIZE SELECTOR (for Frames/Eyeglasses/Sunglasses) - ONLY SHOW IF HAS SIZES -->
                <?php if (in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses']) && !empty($available_sizes)): ?>
                <div class="size-selector-box">
                    <div class="cs-title">
                        <i class="fas fa-ruler-combined"></i>
                        Frame Size
                        <span class="cs-selected-label" id="sizeSelectedLabel">— Select a size</span>
                    </div>
                    <div class="size-btns" id="sizeBtns">
                        <?php foreach ($available_sizes as $size): ?>
                        <button class="size-btn" data-size="<?php echo htmlspecialchars($size); ?>" onclick="selectSize(this)">
                            <?php echo htmlspecialchars($size); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="cs-hint" id="sizeHint">
                        <i class="fas fa-info-circle"></i>
                        Please select your preferred frame size.
                    </p>
                </div>
                <?php endif; ?>

                <!-- Existing reservation notice -->
                <?php if ($existing_reservation): ?>
                <div class="existing-notice reservation">
                    <i class="fas fa-bookmark"></i>
                    You already have an active reservation for this product.
                    <a href="my-reservations.php"> View it →</a>
                </div>
                <?php elseif ($existing_appointment): ?>
                <div class="existing-notice appointment">
                    <i class="fas fa-calendar-check"></i>
                    You already have a pending appointment for this product.
                    <a href="my-appointments.php"> View it →</a>
                </div>
                <?php endif; ?>
            </div>

<?php if (!$IS_SERVICE && !$IS_ACCESSORY): ?>
            <!-- LENS SELECTION -->
            <div class="flow-card">
                <div class="flow-card-title">
                    <i class="fas fa-glasses"></i>
                    <?php if ($IS_CONTACT_LENS): ?>
                        Contact Lenses Options
                    <?php elseif ($IS_LENS_ONLY): ?>
                        Lens Type
                    <?php else: ?>
                        Select Your Lens Option
                    <?php endif; ?>
                </div>

                <?php if (!$IS_LENS_ONLY && !$IS_CONTACT_LENS): ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="frame_only" onclick="selectLens(this)">
                        <span class="ln">Frame Only</span>
                        <span class="lp">+₱0</span>
                        <span class="ld">No lenses included</span>
                    </button>
                    <button class="lens-btn" data-lens="single_vision" onclick="selectLens(this)">
                        <span class="ln">Single Vision</span>
                        <span class="lp">+₱500</span>
                        <span class="ld">For near or far sight</span>
                    </button>
                    <button class="lens-btn" data-lens="progressive" onclick="selectLens(this)">
                        <span class="ln">Progressive</span>
                        <span class="lp">+₱1,500</span>
                        <span class="ld">Near, mid & far vision</span>
                    </button>
                    <button class="lens-btn" data-lens="blue_cut" onclick="selectLens(this)">
                        <span class="ln">Blue Cut</span>
                        <span class="lp">+₱800</span>
                        <span class="ld">Reduces screen glare</span>
                    </button>
                </div>
                <?php elseif ($IS_LENS_ONLY): ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="single_vision" onclick="selectLens(this)">
                        <span class="ln">Single Vision</span>
                        <span class="lp">Included</span>
                        <span class="ld">For near or far sight</span>
                    </button>
                    <button class="lens-btn" data-lens="progressive" onclick="selectLens(this)">
                        <span class="ln">Progressive</span>
                        <span class="lp">+₱1,000</span>
                        <span class="ld">Near, mid & far vision</span>
                    </button>
                    <button class="lens-btn" data-lens="blue_cut" onclick="selectLens(this)">
                        <span class="ln">Blue Cut</span>
                        <span class="lp">+₱300</span>
                        <span class="ld">Reduces screen glare</span>
                    </button>
                </div>
                <?php else: ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="contact_daily" onclick="selectLens(this)">
                        <span class="ln">Daily Disposable</span>
                        <span class="lp">Included</span>
                        <span class="ld">Fresh pair every day</span>
                    </button>
                    <button class="lens-btn" data-lens="contact_monthly" onclick="selectLens(this)">
                        <span class="ln">Monthly Wear</span>
                        <span class="lp">Included</span>
                        <span class="ld">Reusable for 30 days</span>
                    </button>
                </div>
                <?php endif; ?>

<div id="rxSection" style="display: <?php echo ($category === 'Lenses' || $category === 'Contact Lenses') ? 'block' : 'none'; ?>;">
                    <div class="flow-card-title" style="margin-top:18px; margin-bottom:10px; font-size:14px;">
                        <i class="fas fa-prescription"></i> Your Prescription
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
                                        <input type="text" id="od_sph" placeholder="e.g. -1.50">
                                    </div>
                                    <div class="rx-input-group">
                                        <label>CYL</label>
                                        <input type="text" id="od_cyl" placeholder="e.g. -0.50">
                                    </div>
                                    <div class="rx-input-group">
                                        <label>AXIS</label>
                                        <input type="text" id="od_axis" placeholder="e.g. 180">
                                    </div>
                                </div>
                            </div>
                            <div class="rx-eye-box">
                                <div class="rx-eye-label">Left Eye <span>OS</span></div>
                                <div class="rx-inputs">
                                    <div class="rx-input-group">
                                        <label>SPH</label>
                                        <input type="text" id="os_sph" placeholder="e.g. -1.25">
                                    </div>
                                    <div class="rx-input-group">
                                        <label>CYL</label>
                                        <input type="text" id="os_cyl" placeholder="e.g. -0.25">
                                    </div>
                                    <div class="rx-input-group">
                                        <label>AXIS</label>
                                        <input type="text" id="os_axis" placeholder="e.g. 175">
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

                <!-- Price Summary Strip -->
                <div class="price-summary" id="priceSummary">
                    <div>
                        <div class="ps-item">Price: <strong>₱<?php echo number_format($product['price'], 2); ?></strong></div>
                        <div class="ps-item" id="lensAddText" style="display:none;">Lens: <strong id="lensAddAmt">+₱0</strong></div>
                    </div>
                    <div class="ps-total" id="totalDisplay">₱<?php echo number_format($display_price, 2); ?></div>
                </div>

<!-- TECHNICAL SPECIFICATIONS - ONLY SHOW IF HAS DATA -->
<?php if (!empty($spec_fields)): ?>
<div class="specs-accordion" style="margin-top:20px;">
    <div class="specs-header" onclick="toggleSpecs()">
        <span><i class="fas fa-microchip"></i> Technical Specifications</span>
        <i class="fas fa-chevron-down" id="specsIcon"></i>
    </div>
    <div class="specs-content" id="specsContent" style="display:none;">
        <div class="specs-grid">
            <?php foreach ($spec_fields as $key => $label): ?>
            <div class="spec-item">
                <span class="spec-label"><?php echo $label; ?></span>
                <span class="spec-value"><?php echo htmlspecialchars($extra_fields[$key] ?? ''); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- WARRANTY SECTION - DETAILED DISPLAY FOR USERS (tulad ng Lenskart) -->
<?php if ($has_warranty): ?>
<div class="warranty-box">
    <div class="warranty-header" onclick="toggleWarranty()">
        <span><i class="fas fa-shield-alt"></i> Warranty Information</span>
        <i class="fas fa-chevron-down" id="warrantyIcon"></i>
    </div>
    <div class="warranty-content" id="warrantyContent" style="display:none;">
        
        <!-- Warranty Period Badge -->
        <div class="warranty-period-badge">
            <i class="fas fa-clock"></i> <?php echo $warranty_period_display; ?> Warranty
            <?php if ($warranty_premium > 0): ?>
            <span class="premium-badge">+₱<?php echo number_format($warranty_premium, 2); ?> Premium</span>
            <?php endif; ?>
        </div>
        
        <!-- What's Covered (Frame & Lens Issues) -->
        <?php if (!empty($warranty_coverage)): ?>
        <div class="warranty-coverage-list">
            <div class="coverage-title">
                <i class="fas fa-check-circle"></i> What's Covered?
            </div>
            <div class="coverage-grid">
                <?php 
                $frame_covers = ['manufacturing_defect', 'frame_breakage', 'hinge_damage', 'color_fading', 'frame_bent', 'screw_issue'];
                $lens_covers = ['lens_coating', 'lens_crack', 'wrong_power', 'lens_popout', 'photochromic'];
                ?>
                <?php if (array_intersect($frame_covers, $warranty_coverage)): ?>
                <div class="coverage-category">
                    <strong><i class="fas fa-glasses"></i> Frame Issues:</strong>
                    <ul>
                        <?php foreach ($frame_covers as $cover): ?>
                            <?php if (in_array($cover, $warranty_coverage)): ?>
                            <li><i class="fas fa-check"></i> <?php echo ucfirst(str_replace('_', ' ', $cover)); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if (array_intersect($lens_covers, $warranty_coverage)): ?>
                <div class="coverage-category">
                    <strong><i class="fas fa-eye"></i> Lens Issues:</strong>
                    <ul>
                        <?php foreach ($lens_covers as $cover): ?>
                            <?php if (in_array($cover, $warranty_coverage)): ?>
                            <li><i class="fas fa-check"></i> <?php echo ucfirst(str_replace('_', ' ', $cover)); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Exclusions (What's NOT Covered) -->
        <?php if (!empty($warranty_exclusions)): ?>
        <div class="warranty-exclusions">
            <div class="exclusions-title">
                <i class="fas fa-ban"></i> What's NOT Covered?
            </div>
            <p><?php echo nl2br(htmlspecialchars($warranty_exclusions)); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Terms & Conditions -->
        <?php if (!empty($warranty_terms)): ?>
        <div class="warranty-terms-section">
            <div class="terms-title">
                <i class="fas fa-file-alt"></i> Terms & Conditions
            </div>
            <p><?php echo nl2br(htmlspecialchars($warranty_terms)); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- How to Claim Warranty -->
        <div class="warranty-claim">
            <div class="claim-title">
                <i class="fas fa-headset"></i> How to Claim Warranty
            </div>
            <?php if (!empty($warranty_claim_process)): ?>
            <p><?php echo nl2br(htmlspecialchars($warranty_claim_process)); ?></p>
            <?php else: ?>
            <p>Contact the clinic directly through our platform or visit the clinic with your order details and product.</p>
            <?php endif; ?>
        </div>
        
        <!-- Care Instructions -->
        <?php if (!empty($warranty_care)): ?>
        <div class="warranty-care">
            <div class="care-title">
                <i class="fas fa-heart"></i> Care Instructions
            </div>
            <p><?php echo nl2br(htmlspecialchars($warranty_care)); ?></p>
        </div>
        <?php endif; ?>
        
    </div>
</div>
<?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Favorite Button -->
            <div class="fav-product-wrap">
                <button class="btn-fav-product-full <?php echo $is_product_favorited ? 'active' : ''; ?>"
                        id="productFavBtn"
                        data-product-id="<?php echo $product_id; ?>"
                        onclick="toggleProductFav(this)">
                    <i class="fa<?php echo $is_product_favorited ? 's' : 'r'; ?> fa-heart"></i>
                    <span><?php echo $is_product_favorited ? 'Saved to Favorites' : 'Save to Favorites'; ?></span>
                </button>
            </div>

            <!-- Action Button Card -->
            <div class="action-card">
                <?php if ($existing_reservation || $existing_appointment): ?>
                    <button class="btn-main-action" disabled>
                        <i class="fas fa-check-circle"></i>
                        <?php echo $existing_reservation ? 'Already Reserved' : 'Already Booked'; ?>
                    </button>
                <?php elseif ($is_fully_sold_out): ?>
                    <button class="btn-main-action" disabled>
                        <i class="fas fa-times-circle"></i> Out of Stock
                    </button>
                    <p class="action-helper" style="color:var(--danger);">
                        <i class="fas fa-info-circle"></i> All variants are currently unavailable.
                    </p>
                <?php elseif ($IS_SERVICE): ?>
                    <a href="book-appointment.php?clinic_id=<?php echo $clinic_id; ?>&item_id=<?php echo $product_id; ?>&type=product"
                       class="btn-main-action appointment-style" id="mainActionBtn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fas fa-calendar-plus"></i> Book Appointment
                    </a>
                    <p class="action-helper"><i class="fas fa-info-circle"></i> Appointment will be confirmed by the clinic.</p>
                <?php elseif ($IS_ACCESSORY): ?>
                    <button class="btn-main-action" id="mainActionBtn" onclick="openModal('reservation')">
                        <i class="fas fa-bookmark"></i> Reserve This Item
                    </button>
                    <p class="action-helper" id="actionHelper">
                        <i class="fas fa-info-circle"></i> 
                        <?php 
                        if ($payment_type_label == 'downpayment') {
                            echo $downpayment_percent . '% downpayment (₱' . number_format($downpayment_amount_calculated, 2) . ') required to confirm.';
                            if ($clinic_booking_flow == 'pay_first') {
                                echo ' Payment required immediately.';
                            } else {
                                echo ' Wait for clinic approval.';
                            }
                        } elseif ($payment_type_label == 'full') {
                            echo 'Full payment of ₱' . number_format($downpayment_amount_calculated, 2) . ' required to confirm.';
                            if ($clinic_booking_flow == 'pay_first') {
                                echo ' Payment required immediately.';
                            } else {
                                echo ' Wait for clinic approval.';
                            }
                        } elseif ($payment_type_label == 'onsite') {
                            echo 'Pay ₱' . number_format($display_price, 2) . ' at the clinic on your visit date.';
                        } elseif ($payment_type_label == 'free') {
                            echo 'This is a free service. No payment required.';
                        } else {
                            echo 'Select your lens option to continue.';
                        }
                        ?>
                    </p>
                <?php else: ?>
                    <button class="btn-main-action" id="mainActionBtn" onclick="handleMainAction()" <?php echo $has_stock_tracking ? 'disabled' : ''; ?>>
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                    <p class="action-helper" id="actionHelper">
                        <i class="fas fa-info-circle"></i>
                        <?php echo $has_stock_tracking ? 'Select a color variant to continue.' : 'Select your lens option to continue.'; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Clinic Card -->
            <div class="clinic-card">
                <div class="clinic-logo-box">
                    <?php if (!empty($product['clinic_logo'])): ?>
                        <img src="/assets/images/clinic-logos/<?php echo $product['clinic_logo']; ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-store-alt"></i>
                    <?php endif; ?>
                </div>
                <div class="clinic-info" style="flex:1;">
                    <div class="cn"><?php echo htmlspecialchars($product['clinic_name']); ?></div>
                    <div class="ca"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(($product['clinic_address'] ?? '') . ', ' . ($product['clinic_city'] ?? '')); ?></div>
                    <?php if (!empty($product['clinic_hours'])): ?>
                    <div class="ch"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($product['clinic_hours']); ?></div>
                    <?php endif; ?>
                    <div class="clinic-btns">
                        <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-clinic-sm view">
                            <i class="fas fa-store"></i> View Clinic
                        </a>
                        <a href="messages.php?clinic_id=<?php echo $clinic_id; ?>&clinic_name=<?php echo urlencode($product['clinic_name']); ?>" class="btn-clinic-sm message">
                            <i class="fas fa-comment-dots"></i> Message
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Similar Products -->
    <?php if (mysqli_num_rows($similar_query) > 0): ?>
    <div class="similar-section">
        <h2><i class="fas fa-tags"></i> You May Also Like</h2>
        <div class="similar-grid">
            <?php while ($sim = mysqli_fetch_assoc($similar_query)):
                $sim_imgs = getProductImages($sim);
            ?>
            <a href="product-view.php?id=<?php echo $sim['id']; ?>" class="similar-card">
                <div class="similar-img">
                    <img src="<?php echo $sim_imgs[0]; ?>" alt="" onerror="this.src='/assets/img/no-image.png'">
                </div>
                <div class="similar-info">
                    <div class="similar-name"><?php echo htmlspecialchars($sim['name']); ?></div>
                    <div class="similar-price">₱<?php echo number_format($sim['price'], 2); ?></div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- SCHEDULE MODAL -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle"><i class="fas fa-calendar-alt"></i> Schedule Your Visit</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Preferred Date <span style="color:var(--danger)">*</span></label>
                <input type="date" id="mDate" class="form-input" min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Preferred Time <span style="color:var(--danger)">*</span></label>
                <input type="time" id="mTime" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">Contact Number <span style="color:var(--danger)">*</span></label>
                <input type="tel" id="mContact" class="form-input" placeholder="09XX XXX XXXX" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Notes (Optional)</label>
                <textarea id="mNotes" class="form-input" rows="2" placeholder="Any special requests or additional info..."></textarea>
            </div>

            <div class="modal-price-box" id="modalPriceBox">
                <div class="mpb-row">
                    <span class="mpb-label"><?php echo $is_on_sale ? 'Sale Price' : 'Product Price'; ?></span>
                    <span class="mpb-value" <?php echo $is_on_sale ? 'style="color:#EF4444"' : ''; ?>>
                        ₱<?php echo number_format($display_price, 2); ?>
                    </span>
                </div>
                <?php if ($is_on_sale): ?>
                <div class="mpb-row">
                    <span class="mpb-label" style="text-decoration:line-through; color:var(--text-muted)">Original</span>
                    <span class="mpb-value" style="text-decoration:line-through; color:var(--text-muted)">₱<?php echo number_format($original_price, 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="mpb-row" id="mpbLensRow" style="display:none;">
                    <span class="mpb-label">Lens Upgrade</span>
                    <span class="mpb-value" id="mpbLensVal">+₱0</span>
                </div>
                <div class="mpb-divider"></div>
                <div class="mpb-row total">
                    <span class="mpb-label">Total</span>
                    <span class="mpb-value" id="mpbTotal">₱<?php echo number_format($display_price, 2); ?></span>
                </div>
                <?php if ($payment_type_label == 'downpayment'): ?>
                <div class="mpb-row dp">
                    <span class="mpb-label">Downpayment (<?php echo $downpayment_percent; ?>%)</span>
                    <span class="mpb-value" id="mpbDp">₱<?php echo number_format($downpayment_amount_calculated, 2); ?></span>
                </div>
                <div class="mpb-row">
                    <span class="mpb-label">Balance (upon visit)</span>
                    <span class="mpb-value" id="mpbBal">₱<?php echo number_format($balance_amount_calculated, 2); ?></span>
                </div>
                <?php elseif ($payment_type_label == 'full'): ?>
                <div class="mpb-row dp">
                    <span class="mpb-label">Full Payment Due</span>
                    <span class="mpb-value" id="mpbDp" style="color:var(--danger);">₱<?php echo number_format($downpayment_amount_calculated, 2); ?></span>
                </div>
                <?php elseif ($payment_type_label == 'onsite'): ?>
                <div class="mpb-row dp">
                    <span class="mpb-label">Pay at Clinic</span>
                    <span class="mpb-value" id="mpbDp" style="color:var(--warning);">₱<?php echo number_format($display_price, 2); ?></span>
                </div>
                <?php elseif ($payment_type_label == 'free'): ?>
                <div class="mpb-row dp">
                    <span class="mpb-label">FREE</span>
                    <span class="mpb-value" style="color:var(--success);">₱0.00</span>
                </div>
                <?php endif; ?>
                
                <div class="mpb-divider"></div>
                <div class="mpb-row">
                    <span class="mpb-label">Booking Flow:</span>
                    <span class="mpb-value">
                        <?php if ($clinic_booking_flow == 'pay_first'): ?>
                            <i class="fas fa-credit-card"></i> Pay First, Then Approve
                        <?php else: ?>
                            <i class="fas fa-clock"></i> Approve First, Then Pay
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="modal-apt-notice" id="modalAptNotice" style="display:none;">
                <i class="fas fa-calendar-check" style="font-size:20px; display:block; margin-bottom:8px;"></i>
                <strong>Appointment Booking</strong><br>
                The clinic will confirm your appointment schedule. No downpayment required for consultations.
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-modal cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-modal confirm" id="confirmModalBtn" onclick="submitAction()">
                <i class="fas fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<script>
// ============================================
// VARIABLES
// ============================================
let currentSlide = 0;
let pSwiper = { realIndex: 0 };
let selectedLens = '<?php echo ($IS_ACCESSORY || $IS_SERVICE) ? '' : ($IS_LENS_ONLY ? 'single_vision' : ($IS_CONTACT_LENS ? 'contact_daily' : 'frame_only')); ?>';
let rxKnowledge = null;
let currentModal = null;
let selectedColor = null;
let selectedSize = null;

const HAS_STOCK_TRACKING = <?php echo $has_stock_tracking ? 'true' : 'false'; ?>;
const IS_FULLY_SOLD_OUT  = <?php echo $is_fully_sold_out ? 'true' : 'false'; ?>;

const BASE_PRICE = <?php echo $display_price; ?>;
const IS_ON_SALE = <?php echo $is_on_sale ? 'true' : 'false'; ?>;

const PAYMENT_TYPE = '<?php echo $payment_type_label; ?>';
const DOWNPAYMENT_PERCENT = <?php echo $downpayment_percent; ?>;
const DOWNPAYMENT_AMOUNT = <?php echo $downpayment_amount_calculated; ?>;
const BALANCE_AMOUNT = <?php echo $balance_amount_calculated; ?>;
const REQUIRES_PAYMENT = <?php echo $requires_downpayment ? 'true' : 'false'; ?>;
const BOOKING_FLOW = '<?php echo $clinic_booking_flow; ?>';

const IS_SERVICE = <?php echo $IS_SERVICE ? 'true' : 'false'; ?>;
const CLINIC_ID  = <?php echo $clinic_id; ?>;
const PRODUCT_ID = <?php echo $product_id; ?>;
const IS_ACCESSORY = <?php echo $IS_ACCESSORY ? 'true' : 'false'; ?>;
const IS_LENS_ONLY = <?php echo $IS_LENS_ONLY ? 'true' : 'false'; ?>;
const IS_CONTACT_LENS = <?php echo $IS_CONTACT_LENS ? 'true' : 'false'; ?>;
const NEEDS_LENS = <?php echo $NEEDS_LENS_SELECTION ? 'true' : 'false'; ?>;

const LENS_PRICES = {
    frame_only: 0,
    single_vision: 500,
    progressive: 1500,
    blue_cut: 800,
    contact_daily: 0,
    contact_monthly: 0,
};

// ============================================
// SIZE SELECTION
// ============================================
function selectSize(btn) {
    document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedSize = btn.dataset.size;
    const label = document.getElementById('sizeSelectedLabel');
    if (label) {
        label.textContent = selectedSize;
        label.classList.add('chosen');
    }
    const hint = document.getElementById('sizeHint');
    if (hint) hint.style.display = 'none';
    updateActionButton();
}

// ============================================
// ACCORDION TOGGLES
// ============================================
function toggleSpecs() {
    const content = document.getElementById('specsContent');
    const icon = document.getElementById('specsIcon');
    if (!content || !icon) return;
    
    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
        icon.style.transition = 'transform 0.3s';
    } else {
        content.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
    }
}

function toggleWarranty() {
    const content = document.getElementById('warrantyContent');
    const icon = document.getElementById('warrantyIcon');
    if (!content || !icon) return;
    
    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
        icon.style.transition = 'transform 0.3s';
    } else {
        content.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
    }
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    initSlider();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('mDate').value = tomorrow.toISOString().split('T')[0];
    
    // ✅ FIX: For Lenses and Contact Lenses, show prescription section immediately
    const rxSection = document.getElementById('rxSection');
    const hasLensOptions = document.getElementById('lensGrid') !== null;
    
    // For Lenses and Contact Lenses, prescription is always needed
    if (IS_LENS_ONLY || IS_CONTACT_LENS) {
        if (rxSection) {
            rxSection.style.display = 'block';
        }
        // For lenses, we need to ensure the prescription options are visible
        // but don't auto-select anything - let user choose
    }
    // For regular eyewear with lenses selected (not frame_only)
    else if (NEEDS_LENS && selectedLens !== 'frame_only' && selectedLens !== '') {
        if (rxSection) {
            rxSection.style.display = 'block';
        }
    }
    // For frame_only, prescription section should be hidden
    else if (selectedLens === 'frame_only') {
        if (rxSection) {
            rxSection.style.display = 'none';
        }
    }
    
    updatePriceDisplay();
    updateActionButton();
});

// ============================================
// CUSTOM SLIDER
// ============================================
const TOTAL_SLIDES = <?php echo count($product_images); ?>;

function initSlider() {
    if (TOTAL_SLIDES <= 1) return;
    buildDots();
    updateSlider();
}

function buildDots() {
    const dotsEl = document.getElementById('sliderDots');
    if (!dotsEl) return;
    dotsEl.innerHTML = '';
    for (let i = 0; i < TOTAL_SLIDES; i++) {
        const btn = document.createElement('button');
        btn.className = 'swiper-pagination-bullet' + (i === 0 ? ' swiper-pagination-bullet-active' : '');
        btn.addEventListener('click', function(e) { e.stopPropagation(); goToSlide(i); });
        dotsEl.appendChild(btn);
    }
}

function updateSlider() {
    const wrapper = document.getElementById('sliderWrapper');
    if (wrapper) {
        wrapper.style.transform = 'translateX(' + (-currentSlide * 100) + '%)';
    }
    pSwiper.realIndex = currentSlide;
    syncThumbs(currentSlide);
    document.querySelectorAll('.swiper-pagination-bullet').forEach((b, i) => {
        b.classList.toggle('swiper-pagination-bullet-active', i === currentSlide);
    });
}

// ============================================
// IMAGE FUNCTIONS
// ============================================
const lbImages = <?php echo json_encode($product_images); ?>;
let lbCurrentIndex = 0;

function goToSlide(index) {
    currentSlide = ((index % TOTAL_SLIDES) + TOTAL_SLIDES) % TOTAL_SLIDES;
    updateSlider();
}

function syncThumbs(index) {
    document.querySelectorAll('.thumb').forEach((t, i) => t.classList.toggle('active', i === index));
}

function openLightbox(index) {
    lbCurrentIndex = ((index % lbImages.length) + lbImages.length) % lbImages.length;
    document.getElementById('lkLightbox').classList.add('show');
    document.body.style.overflow = 'hidden';
    lbRender();
}

function closeLightbox() {
    document.getElementById('lkLightbox').classList.remove('show');
    document.body.style.overflow = '';
}

function lbGoTo(index) {
    lbCurrentIndex = ((index % lbImages.length) + lbImages.length) % lbImages.length;
    lbRender();
    goToSlide(lbCurrentIndex);
}

function lbRender() {
    const img = document.getElementById('lbMainImg');
    if (img) {
        img.style.opacity = '0';
        img.src = lbImages[lbCurrentIndex];
        img.onload = () => { img.style.opacity = '1'; };
        img.onerror = () => { img.src = '/assets/img/no-image.png'; img.style.opacity = '1'; };
    }
    document.querySelectorAll('.lk-lb-thumb').forEach((t, i) => t.classList.toggle('active', i === lbCurrentIndex));
    const ctr = document.getElementById('lbCounter');
    if (ctr) ctr.textContent = (lbCurrentIndex + 1) + ' / ' + lbImages.length;
}

// ============================================
// COLOR SELECTION
// ============================================
function selectColor(btn) {
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    selectedColor = {
        code: btn.dataset.code,
        name: btn.dataset.name,
        qty:  parseInt(btn.dataset.qty),
    };

    const label = document.getElementById('csSelectedLabel');
    if (label) {
        label.textContent = selectedColor.name + ' (' + selectedColor.qty + ' left)';
        label.classList.add('chosen');
    }

    const hint = document.getElementById('csHint');
    if (hint) hint.style.display = 'none';

    const btn2 = document.getElementById('mainActionBtn');
    if (btn2 && btn2.disabled && !IS_FULLY_SOLD_OUT) {
        btn2.disabled = false;
    }

    updateActionButton();
}

// ============================================
// LENS SELECTION - FIXED
// ============================================
function selectLens(btn) {
    document.querySelectorAll('.lens-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLens = btn.dataset.lens;

    const rxSection = document.getElementById('rxSection');
    const isFrameOnly = (selectedLens === 'frame_only');
    
    // ✅ FIX: For Lenses and Contact Lenses, ALWAYS show prescription section
    if (IS_LENS_ONLY || IS_CONTACT_LENS) {
        if (rxSection) {
            rxSection.style.display = 'block';
        }
        // Don't reset rxKnowledge for lenses
    }
    // For regular eyewear
    else {
        const needsRx = !isFrameOnly && NEEDS_LENS;
        if (rxSection) {
            rxSection.style.display = needsRx ? 'block' : 'none';
            if (isFrameOnly) {
                rxKnowledge = null;
                document.querySelectorAll('input[name="rx_know"]').forEach(r => r.checked = false);
                document.getElementById('rxFormBox').style.display = 'none';
                document.getElementById('rxExamBox').style.display = 'none';
            }
        }
    }

    updatePriceDisplay();
    updateActionButton();
}

function handleRxKnowledge(radio) {
    rxKnowledge = radio.value;
    document.getElementById('rxFormBox').style.display = (rxKnowledge === 'know') ? 'block' : 'none';
    document.getElementById('rxExamBox').style.display = (rxKnowledge === 'dont_know') ? 'block' : 'none';
    updateActionButton();
}

// ============================================
// PRICE DISPLAY
// ============================================
function getLensPrice() {
    return LENS_PRICES[selectedLens] || 0;
}

function getTotalPrice() {
    return BASE_PRICE + getLensPrice();
}

function updatePriceDisplay() {
    const lp = getLensPrice();
    const total = getTotalPrice();

    const lensAddText = document.getElementById('lensAddText');
    const lensAddAmt = document.getElementById('lensAddAmt');
    const totalDisplay = document.getElementById('totalDisplay');

    if (lensAddText && lensAddAmt) {
        lensAddText.style.display = lp > 0 ? 'block' : 'none';
        if (lensAddAmt) lensAddAmt.textContent = '+₱' + lp.toLocaleString();
    }
    if (totalDisplay) totalDisplay.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});

    const mpbLensRow = document.getElementById('mpbLensRow');
    const mpbLensVal = document.getElementById('mpbLensVal');
    const mpbTotal = document.getElementById('mpbTotal');
    const mpbDp = document.getElementById('mpbDp');
    const mpbBal = document.getElementById('mpbBal');

    if (mpbLensRow) mpbLensRow.style.display = lp > 0 ? 'flex' : 'none';
    if (mpbLensVal) mpbLensVal.textContent = '+₱' + lp.toLocaleString();
    if (mpbTotal) mpbTotal.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});

    if (PAYMENT_TYPE === 'downpayment') {
        const dpAmount = total * (DOWNPAYMENT_PERCENT / 100);
        const balAmount = total - dpAmount;
        if (mpbDp) mpbDp.textContent = '₱' + dpAmount.toLocaleString('en-PH', {minimumFractionDigits: 2});
        if (mpbBal) mpbBal.textContent = '₱' + balAmount.toLocaleString('en-PH', {minimumFractionDigits: 2});
    } else if (PAYMENT_TYPE === 'full') {
        if (mpbDp) mpbDp.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
    } else if (PAYMENT_TYPE === 'onsite') {
        if (mpbDp) mpbDp.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
    }
}

// ============================================
// ACTION BUTTON - FIXED
// ============================================
function updateActionButton() {
    const btn = document.getElementById('mainActionBtn');
    const helper = document.getElementById('actionHelper');
    if (!btn) return;

    const hasSizeSelector = document.getElementById('sizeBtns') !== null;
    if (hasSizeSelector && !selectedSize && !IS_FULLY_SOLD_OUT && !IS_LENS_ONLY && !IS_CONTACT_LENS) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Please select a frame size to continue.';
        return;
    }

    const isFrameOnly = (selectedLens === 'frame_only');
    
    // ✅ FIX: For Lenses and Contact Lenses, prescription is ALWAYS required
    if ((IS_LENS_ONLY || IS_CONTACT_LENS) && !rxKnowledge) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Please select whether you know your prescription or need an eye exam.';
    }
    // Frame Only option (for regular eyewear only, not lenses)
    else if (isFrameOnly && !IS_LENS_ONLY && !IS_CONTACT_LENS) {
        btn.innerHTML = '<i class="fas fa-bookmark"></i> Reserve — Frame Only';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) {
            if (PAYMENT_TYPE === 'downpayment') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> ' + DOWNPAYMENT_PERCENT + '% downpayment (₱' + DOWNPAYMENT_AMOUNT.toFixed(2) + ') required.';
                if (BOOKING_FLOW === 'pay_first') {
                    helper.innerHTML += ' Payment required immediately.';
                } else {
                    helper.innerHTML += ' Wait for clinic approval.';
                }
            } else if (PAYMENT_TYPE === 'full') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> Full payment of ₱' + DOWNPAYMENT_AMOUNT.toFixed(2) + ' required.';
                if (BOOKING_FLOW === 'pay_first') {
                    helper.innerHTML += ' Payment required immediately.';
                } else {
                    helper.innerHTML += ' Wait for clinic approval.';
                }
            } else if (PAYMENT_TYPE === 'onsite') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> Pay ₱' + BASE_PRICE.toFixed(2) + ' at the clinic.';
            } else {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> No payment required.';
            }
        }
    } else if (!rxKnowledge) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Choose your prescription option to continue.';
    } else if (rxKnowledge === 'dont_know') {
        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> Book Eye Exam';
        btn.className = 'btn-main-action appointment-style';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> You\'ll be taken to book an eye exam appointment first.';
    } else {
        btn.innerHTML = '<i class="fas fa-bookmark"></i> Reserve with Prescription';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) {
            if (PAYMENT_TYPE === 'downpayment') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> ' + DOWNPAYMENT_PERCENT + '% downpayment required. Prescription verified on visit.';
                if (BOOKING_FLOW === 'pay_first') {
                    helper.innerHTML += ' Payment required immediately.';
                } else {
                    helper.innerHTML += ' Wait for clinic approval.';
                }
            } else if (PAYMENT_TYPE === 'full') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> Full payment required. Prescription verified on visit.';
                if (BOOKING_FLOW === 'pay_first') {
                    helper.innerHTML += ' Payment required immediately.';
                } else {
                    helper.innerHTML += ' Wait for clinic approval.';
                }
            } else if (PAYMENT_TYPE === 'onsite') {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> Pay at clinic on visit date. Prescription verified on visit.';
            } else {
                helper.innerHTML = '<i class="fas fa-info-circle"></i> Free service. Prescription verified on visit.';
            }
        }
    }
}

// ============================================
// HANDLE MAIN ACTION - FIXED
// ============================================
function handleMainAction() {
    const hasSizeSelector = document.getElementById('sizeBtns') !== null;
    // Skip size check for Lenses and Contact Lenses (they don't have frame sizes)
    if (hasSizeSelector && !selectedSize && !IS_FULLY_SOLD_OUT && !IS_LENS_ONLY && !IS_CONTACT_LENS) {
        showToast('Please select a frame size first.', 'error');
        document.getElementById('sizeBtns')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    if (HAS_STOCK_TRACKING && !IS_FULLY_SOLD_OUT && !selectedColor) {
        showToast('Please select a color/variant first.', 'error');
        document.getElementById('colorBtns')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const isFrameOnly = (selectedLens === 'frame_only');
    
    // For Lenses and Contact Lenses, ALWAYS require prescription
    if (IS_LENS_ONLY || IS_CONTACT_LENS) {
        if (!rxKnowledge) {
            showToast('Please choose whether you know your prescription.', 'error');
            document.getElementById('rxSection').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        
        if (rxKnowledge === 'dont_know') {
            const colorCode = selectedColor ? selectedColor.code : '';
            window.location.href = 'book-specific-product.php?clinic_id=' + CLINIC_ID
                + '&product_id=' + PRODUCT_ID
                + '&rx_knowledge=dont_know'
                + '&lens=' + encodeURIComponent(selectedLens || '')
                + '&color=' + encodeURIComponent(colorCode);
            return;
        }
        
        // rxKnowledge === 'know'
        const od_sph = document.getElementById('od_sph')?.value || '';
        const os_sph = document.getElementById('os_sph')?.value || '';
        if (!od_sph && !os_sph) {
            showToast('Please enter at least your sphere values, or select "I need an eye exam".', 'error');
            return;
        }
        
        const od_cyl  = document.getElementById('od_cyl')?.value  || '';
        const od_axis = document.getElementById('od_axis')?.value || '';
        const os_cyl  = document.getElementById('os_cyl')?.value  || '';
        const os_axis = document.getElementById('os_axis')?.value || '';
        const colorCode = selectedColor ? selectedColor.code : '';
        
        window.location.href = 'book-specific-product.php?clinic_id=' + CLINIC_ID
            + '&product_id=' + PRODUCT_ID
            + '&rx_knowledge=know'
            + '&lens=' + encodeURIComponent(selectedLens || '')
            + '&color=' + encodeURIComponent(colorCode)
            + '&od_sph=' + encodeURIComponent(od_sph)
            + '&od_cyl=' + encodeURIComponent(od_cyl)
            + '&od_axis=' + encodeURIComponent(od_axis)
            + '&os_sph=' + encodeURIComponent(os_sph)
            + '&os_cyl=' + encodeURIComponent(os_cyl)
            + '&os_axis=' + encodeURIComponent(os_axis);
        return;
    }
    
    // Original logic for regular eyewear
    if (isFrameOnly) { 
        const colorCode = selectedColor ? selectedColor.code : '';
        window.location.href = 'book-specific-product.php?clinic_id=' + CLINIC_ID
            + '&product_id=' + PRODUCT_ID
            + '&rx_knowledge=' + (rxKnowledge === 'know' ? 'know' : 'dont_know')
            + '&lens=' + encodeURIComponent(selectedLens || '')
            + '&color=' + encodeURIComponent(colorCode);
        return; 
    }

    if (!rxKnowledge) {
        showToast('Please choose whether you know your prescription.', 'error');
        document.getElementById('rxSection').scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    if (rxKnowledge === 'dont_know') {
        const colorCode = selectedColor ? selectedColor.code : '';
        window.location.href = 'book-specific-product.php?clinic_id=' + CLINIC_ID
            + '&product_id=' + PRODUCT_ID
            + '&rx_knowledge=dont_know'
            + '&lens=' + encodeURIComponent(selectedLens || '')
            + '&color=' + encodeURIComponent(colorCode);
        return;
    }

    const od_sph = document.getElementById('od_sph').value;
    const os_sph = document.getElementById('os_sph').value;
    if (!od_sph && !os_sph) {
        showToast('Please enter at least your sphere values, or select "I need an eye exam".', 'error');
        return;
    }

    const od_cyl  = document.getElementById('od_cyl')?.value  || '';
    const od_axis = document.getElementById('od_axis')?.value || '';
    const os_cyl  = document.getElementById('os_cyl')?.value  || '';
    const os_axis = document.getElementById('os_axis')?.value || '';
    const colorCode = selectedColor ? selectedColor.code : '';

    window.location.href = 'book-specific-product.php?clinic_id=' + CLINIC_ID
        + '&product_id=' + PRODUCT_ID
        + '&rx_knowledge=know'
        + '&lens=' + encodeURIComponent(selectedLens || '')
        + '&color=' + encodeURIComponent(colorCode)
        + '&od_sph=' + encodeURIComponent(od_sph)
        + '&od_cyl=' + encodeURIComponent(od_cyl)
        + '&od_axis=' + encodeURIComponent(od_axis)
        + '&os_sph=' + encodeURIComponent(os_sph)
        + '&os_cyl=' + encodeURIComponent(os_cyl)
        + '&os_axis=' + encodeURIComponent(os_axis);
}

// ============================================
// MODAL
// ============================================
function openModal(type) {
    currentModal = type;
    const modal = document.getElementById('scheduleModal');
    const title = document.getElementById('modalTitle');
    const confirmBtn = document.getElementById('confirmModalBtn');
    const priceBox = document.getElementById('modalPriceBox');
    const aptNotice = document.getElementById('modalAptNotice');

    updatePriceDisplay();

    if (type === 'appointment') {
        title.innerHTML = '<i class="fas fa-calendar-plus"></i> Book Appointment';
        confirmBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Book Appointment';
        confirmBtn.className = 'btn-modal confirm apt';
        priceBox.style.display = 'none';
        aptNotice.style.display = 'block';
    } else {
        title.innerHTML = '<i class="fas fa-bookmark"></i> Reserve This Product';
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Reservation';
        confirmBtn.className = 'btn-modal confirm';
        priceBox.style.display = 'block';
        aptNotice.style.display = 'none';
    }

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('scheduleModal').classList.remove('show');
    document.body.style.overflow = '';
}

// ============================================
// SUBMIT
// ============================================
function submitAction() {
    const date = document.getElementById('mDate').value;
    const time = document.getElementById('mTime').value;
    const contact = document.getElementById('mContact').value;
    const notes = document.getElementById('mNotes').value;

    if (!date) { showToast('Please select a date.', 'error'); return; }
    if (!time) { showToast('Please select a time.', 'error'); return; }
    if (!contact) { showToast('Please enter your contact number.', 'error'); return; }

    const formData = new FormData();
    formData.append('ajax_action', currentModal === 'appointment' ? 'appointment' : 'reservation');
    formData.append('lens_type', selectedLens || 'frame_only');
    formData.append('prescription_knowledge', rxKnowledge || '');
    formData.append('preferred_date', date);
    formData.append('preferred_time', time);
    formData.append('contact_number', contact);
    formData.append('notes', notes);

    if (selectedColor) {
        formData.append('color_code', selectedColor.code);
        formData.append('color_name', selectedColor.name);
    }

    if (selectedSize) {
        formData.append('frame_size', selectedSize);
    }

    if (rxKnowledge === 'know') {
        formData.append('od_sph', document.getElementById('od_sph')?.value || '');
        formData.append('od_cyl', document.getElementById('od_cyl')?.value || '');
        formData.append('od_axis', document.getElementById('od_axis')?.value || '');
        formData.append('os_sph', document.getElementById('os_sph')?.value || '');
        formData.append('os_cyl', document.getElementById('os_cyl')?.value || '');
        formData.append('os_axis', document.getElementById('os_axis')?.value || '');
    }

    closeModal();
    showLoading();

    fetch('product-view.php?id=<?php echo $product_id; ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => { window.location.href = data.redirect; }, 1500);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(err => {
        hideLoading();
        console.error('Fetch error:', err);
        showToast('Network error. Please try again.', 'error');
    });
}

// ============================================
// UTILITIES
// ============================================
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3500);
}

function showLoading() { document.getElementById('loadingOverlay').classList.remove('hidden'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.add('hidden'); }

document.addEventListener('keydown', e => {
    const lbOpen = document.getElementById('lkLightbox').classList.contains('show');
    if (lbOpen) {
        if (e.key === 'ArrowRight') lbGoTo(lbCurrentIndex + 1);
        if (e.key === 'ArrowLeft')  lbGoTo(lbCurrentIndex - 1);
        if (e.key === 'Escape')     closeLightbox();
    } else if (e.key === 'Escape') {
        closeModal();
    }
});

document.getElementById('scheduleModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});

function toggleProductFav(btn) {
    const productId = btn.dataset.productId;
    const isActive  = btn.classList.contains('active');
    const formData  = new FormData();
    formData.append('product_id', productId);

    btn.classList.toggle('active');
    btn.classList.add('pop');
    const icon = btn.querySelector('i');
    const label = btn.querySelector('span');
    icon.className    = btn.classList.contains('active') ? 'fas fa-heart' : 'far fa-heart';
    label.textContent = btn.classList.contains('active') ? 'Saved to Favorites' : 'Save to Favorites';
    setTimeout(() => btn.classList.remove('pop'), 300);

    fetch('toggle-product-favorite.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.action === 'added' ? '❤️ Added to favorites!' : 'Removed from favorites', data.action === 'added' ? 'success' : 'info');
            } else {
                btn.classList.toggle('active');
                icon.className    = isActive ? 'fas fa-heart' : 'far fa-heart';
                label.textContent = isActive ? 'Saved to Favorites' : 'Save to Favorites';
                showToast('Something went wrong. Please try again.', 'error');
            }
        })
        .catch(() => {
            btn.classList.toggle('active');
            icon.className    = isActive ? 'fas fa-heart' : 'far fa-heart';
            label.textContent = isActive ? 'Saved to Favorites' : 'Save to Favorites';
            showToast('Network error. Please try again.', 'error');
        });
}
</script>
</body>
</html>