<?php
// ============================================
// COMPLETE 3D-VIEW.PHP
// WITH SIZE SELECTOR + PAYMENT POLICY + VAT/DISCOUNT
// ✅ ALIGNED with product-view.php flow (Pickup/Delivery + PayMongo)
// ============================================

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$product_id) { header('Location: dashboard.php'); exit(); }

// ============================================
// GET PRODUCT + CLINIC DETAILS
// ============================================
$product_query = mysqli_query($conn, "
    SELECT p.*,
           p3d.model_file,
           p3d.model_type,
           p3d.has_3d,
           c.id as clinic_id,
           c.name as clinic_name,
           c.address as clinic_address,
           c.city as clinic_city,
           c.contact as clinic_contact,
           c.hours as clinic_hours,
           c.logo as clinic_logo,
           c.downpayment_percentage,
           c.gcash_number,
           c.gcash_qr,
           c.payment_method_online,
           c.payment_method_onsite
    FROM products p
    LEFT JOIN product_3d_models p3d ON p.inventory_id = p3d.inventory_id OR p.id = p3d.product_id
    JOIN clinics c ON p.clinic_id = c.id
    WHERE p.id = $product_id
");

if (mysqli_num_rows($product_query) == 0) { header('Location: dashboard.php'); exit(); }
$product = mysqli_fetch_assoc($product_query);
$clinic_id = $product['clinic_id'];

// ============================================
// CATEGORY LOGIC
// ============================================
$category = $product['category'];
$NEEDS_LENS_SELECTION = in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses', 'Contact Lenses']);
$IS_ACCESSORY = in_array($category, ['Accessories', 'Parts', 'Cleaning Kits']);
$IS_SERVICE = in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening']);
$IS_CONTACT_LENS = ($category === 'Contact Lenses');
$IS_LENS_ONLY = ($category === 'Lenses');

// ============================================
// GET EXTRA FIELDS (for sizes)
// ============================================
$extra_fields = [];
if (!empty($product['extra_fields_json'])) {
    $extra_fields = json_decode($product['extra_fields_json'], true);
}
$available_sizes = [];
if (!empty($extra_fields['sizes_available']) && is_array($extra_fields['sizes_available'])) {
    $available_sizes = $extra_fields['sizes_available'];
}
$has_sizes = !empty($available_sizes) && in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses']);

// ============================================
// SALE DETECTION
// ============================================
$is_on_sale = !empty($product['is_on_sale'])
    && $product['is_on_sale'] == 1
    && !empty($product['sale_price'])
    && $product['sale_price'] > 0
    && !empty($product['sale_end'])
    && strtotime($product['sale_end']) >= strtotime('today');

$sale_price     = $is_on_sale ? (float)$product['sale_price'] : 0;
$original_price = (float)$product['price'];
$display_price  = $is_on_sale ? $sale_price : $original_price;
$discount_pct   = $is_on_sale ? round((($original_price - $sale_price) / $original_price) * 100) : 0;
$savings        = $is_on_sale ? ($original_price - $sale_price) : 0;

// ============================================
// 3D MODEL CHECK
// ============================================
$has_3d = !empty($product['model_file']) && ($product['has_3d'] == 1 || $product['has_3d'] == '1');
$model_file = $product['model_file'] ?? '';

// ============================================
// EXISTING RESERVATION / APPOINTMENT CHECK
// ============================================
$existing_reservation = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, reservation_code FROM reservations
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending','confirmed') LIMIT 1"
)) ?? null;

$existing_appointment = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, ref_no FROM appointments
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending','confirmed') LIMIT 1"
)) ?? null;

// ============================================
// GET COLORS
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
$has_colors         = !empty($product_colors);
$is_fully_sold_out  = $has_colors && $total_available_stock === 0;
$has_stock_tracking = $has_colors;

// ============================================
// CHECK IF PRODUCT IS FAVORITED
// ============================================
$fav_check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
$is_product_favorited = mysqli_num_rows($fav_check) > 0;

// ============================================
// GET CLINIC PAYMENT CONFIGURATION
// ============================================
$clinic_payment_config = getClinicPaymentPolicy($conn, $clinic_id);
$clinic_payment_policy = $clinic_payment_config['payment_policy'];
$clinic_downpayment_percent = (float)($clinic_payment_config['downpayment_percentage'] ?? 30);
$clinic_booking_flow = $clinic_payment_config['booking_flow'] ?? 'approve_first';

// ============================================
// ✅ DELIVERY FEATURE — Get clinic delivery settings
// ============================================
$clinic_delivery_q = mysqli_query($conn, "
    SELECT offers_delivery, allow_cod, delivery_fee, free_delivery_minimum
    FROM clinics WHERE id = $clinic_id
");
$clinic_delivery = mysqli_fetch_assoc($clinic_delivery_q);

$offers_delivery           = (int)($clinic_delivery['offers_delivery'] ?? 0);
$allow_cod                 = (int)($clinic_delivery['allow_cod'] ?? 0);
$clinic_delivery_fee       = (float)($clinic_delivery['delivery_fee'] ?? 0);
$clinic_free_delivery_min  = (float)($clinic_delivery['free_delivery_minimum'] ?? 0);

// Payment policy display labels
$policy_labels = [
    'full_payment' => '100% Full Payment',
    'downpayment_30' => '30% Downpayment',
    'downpayment_custom' => $clinic_downpayment_percent . '% Downpayment',
    'pay_on_site' => 'Pay On-Site Only',
    'no_payment' => 'Free Service'
];
$clinic_payment_policy_display = $policy_labels[$clinic_payment_policy] ?? 'Standard Payment';

// ============================================
// ✅ DELIVERY FEATURE — PWD/Senior status (with expiry check)
// ============================================
$pwd_check_q = mysqli_query($conn, "
    SELECT status, verification_type, valid_until
    FROM user_verifications
    WHERE user_id = $user_id
      AND clinic_id = $clinic_id
      AND status = 'verified'
    LIMIT 1
");
$pwd_row = mysqli_fetch_assoc($pwd_check_q);

$is_pwd_senior_display   = false;
$pwd_senior_type_display = '';

if ($pwd_row && $pwd_row['status'] === 'verified') {
    $valid_until = $pwd_row['valid_until'] ?? null;
    if ($valid_until) {
        if (strtotime($valid_until) >= strtotime('today')) {
            $is_pwd_senior_display   = true;
            $pwd_senior_type_display = $pwd_row['verification_type'] ?? 'pwd';
        }
    } else {
        $is_pwd_senior_display   = true;
        $pwd_senior_type_display = $pwd_row['verification_type'] ?? 'pwd';
    }
}

$is_pwd_senior = $is_pwd_senior_display; // For backwards compatibility with existing code

// ============================================
// CALCULATE TAX AND DISCOUNT
// ============================================
$tax_calc = applyTaxAndDiscount($conn, $display_price, $is_pwd_senior_display);

$subtotal = $display_price;
$discount_amount = $tax_calc['discount_amount'];
$discount_rate = $tax_calc['discount_rate'];
$vat_amount = $tax_calc['vat_amount'];
$vat_rate = $tax_calc['vat_rate'];
$final_total = $tax_calc['final_total'];
$subtotal_after_discount = $subtotal - $discount_amount;

// Display rate variables
$vat_rate_display      = $tax_calc['vat_rate'];
$discount_rate_display = $tax_calc['discount_rate'];

// Get clinic payment info
$payment_info = getPaymentDisplayInfo($conn, $clinic_id, $final_total, $is_pwd_senior_display);
$booking_flow = $payment_info['booking_flow'] ?? 'approve_first';
$payment_type = $payment_info['payment_type'] ?? 'downpayment';
$downpayment_percent = $clinic_downpayment_percent;
$downpayment_amount = $payment_info['downpayment_amount'] ?? round($final_total * ($downpayment_percent / 100), 2);
$balance_amount = $payment_info['balance_amount'] ?? ($final_total - $downpayment_amount);
$payment_method_online = $payment_info['payment_method_online'] ?? true;
$payment_method_onsite = $payment_info['payment_method_onsite'] ?? true;

// Navbar vars
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
$reservation_query_nav = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending','confirmed')");
$reservation_row_nav = mysqli_fetch_assoc($reservation_query_nav);
$reservation_count = $reservation_row_nav['total'] ?? 0;
$active_nav = 'discover';

// ============================================
// HANDLE AJAX SUBMISSION — ALIGNED with product-view.php
// ============================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    $action               = mysqli_real_escape_string($conn, $_POST['ajax_action']);
    $lens_type            = mysqli_real_escape_string($conn, $_POST['lens_type'] ?? 'frame_only');
    $prescription_knowledge = mysqli_real_escape_string($conn, $_POST['prescription_knowledge'] ?? '');
    $od_sph  = mysqli_real_escape_string($conn, $_POST['od_sph'] ?? '');
    $od_cyl  = mysqli_real_escape_string($conn, $_POST['od_cyl'] ?? '');
    $od_axis = mysqli_real_escape_string($conn, $_POST['od_axis'] ?? '');
    $os_sph  = mysqli_real_escape_string($conn, $_POST['os_sph'] ?? '');
    $os_cyl  = mysqli_real_escape_string($conn, $_POST['os_cyl'] ?? '');
    $os_axis = mysqli_real_escape_string($conn, $_POST['os_axis'] ?? '');
    $preferred_date  = mysqli_real_escape_string($conn, $_POST['preferred_date'] ?? '');
    $preferred_time  = mysqli_real_escape_string($conn, $_POST['preferred_time'] ?? '');
    $notes           = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $contact_number  = mysqli_real_escape_string($conn, $_POST['contact_number'] ?? '');
    $color_code      = mysqli_real_escape_string($conn, $_POST['color_code'] ?? '');
    $color_name      = mysqli_real_escape_string($conn, $_POST['color_name'] ?? '');
    $frame_size      = mysqli_real_escape_string($conn, $_POST['frame_size'] ?? '');

    // ============================================
    // ✅ DELIVERY FEATURE — Capture delivery fields
    // ============================================
    $fulfillment_type        = mysqli_real_escape_string($conn, $_POST['fulfillment_type'] ?? 'pickup');
    $delivery_name           = mysqli_real_escape_string($conn, $_POST['delivery_name'] ?? '');
    $delivery_phone          = mysqli_real_escape_string($conn, $_POST['delivery_phone'] ?? '');
    $delivery_address        = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');
    $delivery_barangay       = mysqli_real_escape_string($conn, $_POST['delivery_barangay'] ?? '');
    $delivery_city           = mysqli_real_escape_string($conn, $_POST['delivery_city'] ?? '');
    $delivery_province       = mysqli_real_escape_string($conn, $_POST['delivery_province'] ?? '');
    $delivery_zip            = mysqli_real_escape_string($conn, $_POST['delivery_zip'] ?? '');
    $delivery_landmark       = mysqli_real_escape_string($conn, $_POST['delivery_landmark'] ?? '');
    $delivery_date_input     = mysqli_real_escape_string($conn, $_POST['delivery_date'] ?? '');
    $delivery_payment_method = mysqli_real_escape_string($conn, $_POST['delivery_payment_method'] ?? 'online');

    $is_delivery_order = ($fulfillment_type === 'delivery');

    // ============================================
    // ✅ DELIVERY FEATURE — Conditional date/time validation
    // ============================================
    if (!$is_delivery_order) {
        if (!$preferred_date || !$preferred_time) {
            echo json_encode(['success' => false, 'message' => 'Please select a date and time.']);
            exit();
        }
        if (strtotime($preferred_date) < strtotime('today')) {
            echo json_encode(['success' => false, 'message' => 'Date must be today or in the future.']);
            exit();
        }
    } else {
        // Delivery validation
        $delivery_allowed = $IS_ACCESSORY || ($lens_type === 'frame_only' && in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses']));
        if (!$delivery_allowed) {
            echo json_encode(['success' => false, 'message' => 'Delivery is not available for this product type.']);
            exit();
        }
        if (!$offers_delivery) {
            echo json_encode(['success' => false, 'message' => 'This clinic does not offer delivery.']);
            exit();
        }
        if (!$delivery_name || !$delivery_phone || !$delivery_address || !$delivery_barangay || !$delivery_city || !$delivery_province || !$delivery_zip) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required delivery fields.']);
            exit();
        }
        if ($delivery_payment_method === 'cod' && !$allow_cod) {
            echo json_encode(['success' => false, 'message' => 'Cash on Delivery is not available for this clinic.']);
            exit();
        }
        // Dummy date/time for delivery
        if (!$preferred_date) $preferred_date = date('Y-m-d');
        if (!$preferred_time) $preferred_time = '09:00:00';
    }

    $make_appointment = false;
    if ($IS_SERVICE) {
        $make_appointment = true;
    } elseif ($IS_ACCESSORY) {
        $make_appointment = false;
    } elseif ($NEEDS_LENS_SELECTION) {
        if ($lens_type === 'frame_only') {
            $make_appointment = false;
        } else {
            $make_appointment = ($prescription_knowledge === 'dont_know');
        }
    }

    // If appointment needed → redirect to book-specific-product.php
    if ($make_appointment) {
        echo json_encode([
            'success'  => false,
            'redirect_to_booking' => true,
            'message'  => 'Redirecting to book an appointment...',
            'redirect' => 'book-specific-product.php?clinic_id=' . $clinic_id
                        . '&product_id=' . $product_id
                        . '&rx_knowledge=' . ($prescription_knowledge === 'know' ? 'know' : 'dont_know')
                        . '&lens=' . urlencode($lens_type)
                        . '&color=' . urlencode($color_code)
                        . '&od_sph=' . urlencode($od_sph)
                        . '&od_cyl=' . urlencode($od_cyl)
                        . '&od_axis=' . urlencode($od_axis)
                        . '&os_sph=' . urlencode($os_sph)
                        . '&os_cyl=' . urlencode($os_cyl)
                        . '&os_axis=' . urlencode($os_axis)
        ]);
        exit();
    }

    $prescription_id = null;
    if ($prescription_knowledge === 'know' && ($od_sph || $os_sph)) {
        $insert_pres = mysqli_query($conn, "
            INSERT INTO user_prescriptions
            (user_id, product_id, od_sph, od_cyl, od_axis, os_sph, os_cyl, os_axis, prescription_source)
            VALUES ($user_id, $product_id, '$od_sph', '$od_cyl', '$od_axis', '$os_sph', '$os_cyl', '$os_axis', 'user_input')
        ");
        if ($insert_pres) $prescription_id = mysqli_insert_id($conn);
    }

    // RESERVATION FLOW
    $dup_res = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM reservations WHERE user_id = $user_id AND product_id = $product_id AND status IN ('pending','confirmed') LIMIT 1"
    ));
    if ($dup_res) {
        echo json_encode(['success' => false, 'message' => 'You already have an active reservation for this product.']);
        exit();
    }

    $lens_prices = [
        'frame_only'      => 0,
        'single_vision'   => 500,
        'progressive'     => 1500,
        'blue_cut'        => 800,
        'contact_daily'   => 0,
        'contact_monthly' => 0,
    ];
    $lens_price_add = $lens_prices[$lens_type] ?? 0;

    // ✅ Compute delivery fee
    $delivery_fee_final = 0;
    if ($is_delivery_order) {
        $delivery_fee_final = $clinic_delivery_fee;
        $base_subtotal = $product['price'] + $lens_price_add;
        if ($clinic_free_delivery_min > 0 && $base_subtotal >= $clinic_free_delivery_min) {
            $delivery_fee_final = 0;
        }
    }

    // ✅ Compute tax/discount sa product + lens LANG
    $base_subtotal = $product['price'] + $lens_price_add;
    $payment_info_calc = calculatePaymentAmounts($conn, $clinic_id, $base_subtotal, $is_pwd_senior_display);

    $taxed_total = $payment_info_calc['total_amount'];
    $total_amount = $taxed_total + $delivery_fee_final;

    $payment_policy = $payment_info_calc['policy'];
    $payment_type_calc = $payment_info_calc['payment_type'];

    // Recompute downpayment base sa bagong total
    if ($payment_policy === 'full_payment') {
        $downpayment_amount = $total_amount;
        $balance_amount = 0;
        $payment_type_calc = 'full';
        $requires_payment = true;
    } elseif ($payment_policy === 'downpayment_30') {
        $downpayment_amount = round($total_amount * 0.30, 2);
        $balance_amount = round($total_amount - $downpayment_amount, 2);
        $payment_type_calc = 'downpayment';
        $requires_payment = true;
    } elseif ($payment_policy === 'downpayment_custom') {
        $dp_pct = $clinic_downpayment_percent;
        $downpayment_amount = round($total_amount * ($dp_pct / 100), 2);
        $balance_amount = round($total_amount - $downpayment_amount, 2);
        $payment_type_calc = 'downpayment';
        $requires_payment = true;
    } elseif ($payment_policy === 'no_payment') {
        $downpayment_amount = 0;
        $balance_amount = 0;
        $payment_type_calc = 'free';
        $requires_payment = false;
    } else { // pay_on_site
        $downpayment_amount = 0;
        $balance_amount = $total_amount;
        $payment_type_calc = 'onsite';
        $requires_payment = false;
    }

    if ($requires_payment && $downpayment_amount <= 0 && $total_amount > 0) {
        $downpayment_amount = $total_amount;
        $balance_amount = 0;
        $payment_type_calc = 'full';
    }

    $reservation_code = 'RES-' . strtoupper(substr(uniqid(), -8));

    // ✅ COD vs Online vs Pickup status
    $is_cod_order = ($is_delivery_order && $delivery_payment_method === 'cod');

    if ($is_cod_order) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $payment_status = 'cod';
        $reservation_status = 'confirmed';
    } elseif ($requires_payment) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $payment_status = 'unpaid';
        $reservation_status = 'pending';
    } else {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        if ($payment_type_calc == 'onsite') {
            $payment_status = 'onsite';
        } else {
            $payment_status = 'free';
        }
        $reservation_status = 'confirmed';
    }

    $insert_query = "
        INSERT INTO reservations
        (reservation_code, user_id, product_id, clinic_id, lens_type, prescription_id,
         color_code, color_name, frame_size,
         total_amount, downpayment_amount, balance_amount,
         preferred_date, preferred_time, notes, status, payment_status, expires_at, created_at,
         fulfillment_type, delivery_name, delivery_phone, delivery_address,
         delivery_barangay, delivery_city, delivery_province, delivery_zip,
         delivery_landmark, delivery_fee, delivery_date)
        VALUES
        ('$reservation_code', $user_id, $product_id, $clinic_id, '$lens_type',
         " . ($prescription_id ? $prescription_id : 'NULL') . ",
         '$color_code', '$color_name', '$frame_size',
         $total_amount, $downpayment_amount, $balance_amount,
         '$preferred_date', '$preferred_time', '$notes', '$reservation_status', '$payment_status', '$expires_at', NOW(),
         '$fulfillment_type',
         " . ($delivery_name       ? "'$delivery_name'"     : "NULL") . ",
         " . ($delivery_phone      ? "'$delivery_phone'"    : "NULL") . ",
         " . ($delivery_address    ? "'$delivery_address'"  : "NULL") . ",
         " . ($delivery_barangay   ? "'$delivery_barangay'" : "NULL") . ",
         " . ($delivery_city       ? "'$delivery_city'"     : "NULL") . ",
         " . ($delivery_province   ? "'$delivery_province'" : "NULL") . ",
         " . ($delivery_zip        ? "'$delivery_zip'"      : "NULL") . ",
         " . ($delivery_landmark   ? "'$delivery_landmark'" : "NULL") . ",
         $delivery_fee_final,
         " . ($delivery_date_input ? "'$delivery_date_input'" : "NULL") . ")
    ";

    $result = mysqli_query($conn, $insert_query);

    if (!$result) {
        error_log("3D-view Reservation INSERT failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Failed to create reservation. Please try again.']);
        exit();
    }

    $reservation_id = mysqli_insert_id($conn);

    if (!$reservation_id || $reservation_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Database configuration error. Please contact support.']);
        exit();
    }

    if ($prescription_id) {
        mysqli_query($conn, "UPDATE user_prescriptions SET reservation_id = $reservation_id WHERE id = $prescription_id");
    }

    // ✅ Redirect logic — aligned with product-view.php
    if ($is_cod_order) {
        $redirect_url = 'my-reservations.php';
        $message = 'Order placed! You will pay ₱' . number_format($total_amount, 2) . ' upon delivery.';
        $notification_message = "Your delivery order for {$product['name']} is confirmed. Cash on delivery.";
    } elseif ($requires_payment) {
        if ($clinic_booking_flow === 'pay_first') {
            $redirect_url = 'payment.php?reservation_id=' . $reservation_id;
            if ($payment_type_calc == 'full') {
                $message = 'Reservation created! Please proceed to full payment of ₱' . number_format($downpayment_amount, 2) . '.';
            } else {
                $downpayment_percent_display = ($payment_policy == 'downpayment_custom') ? $clinic_downpayment_percent : 30;
                $message = 'Reservation created! Please proceed to pay ' . $downpayment_percent_display . '% downpayment of ₱' . number_format($downpayment_amount, 2) . '.';
            }
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. Please pay to confirm your reservation.";
        } else {
            $redirect_url = 'my-reservations.php';
            $message = 'Reservation created! Please wait for clinic approval.';
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']}. Please wait for clinic approval.";
        }
    } else {
        if ($payment_type_calc == 'onsite') {
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
        'payment_type' => $payment_type_calc,
        'booking_flow' => $clinic_booking_flow,
        'is_cod' => $is_cod_order
    ]);
    exit();
}

include '../includes/navbar.php';

$lens_prices_display = [
    'frame_only'      => 0,
    'single_vision'   => 500,
    'progressive'     => 1500,
    'blue_cut'        => 800,
    'contact_daily'   => 0,
    'contact_monthly' => 0,
];
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>3D View - <?php echo htmlspecialchars($product['name']); ?> - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
    <style>
    /* ===== ALL STYLES ===== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; overflow-x: hidden; background: var(--bg-primary); min-height: 100vh; }
    body { font-family: 'DM Sans', -apple-system, sans-serif; transition: background 0.3s, color 0.3s; }

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
        --viewer-bg: #1A1A2E;
    }

    .theme-dark {
        --bg-primary: #0D0F14;
        --bg-secondary: #161B25;
        --text-primary: #F0F4FF;
        --text-secondary: #8892A4;
        --text-muted: #4B5563;
        --border-color: #252D3D;
        --border-light: #1C2235;
        --primary-light: #0A2018;
        --viewer-bg: #0A0A1A;
    }

    .main-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 28px 20px 80px;
    }
    @media (min-width: 1024px) { .main-content { padding: 32px 40px 60px; } }
    @media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

    /* Breadcrumb */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 24px;
        font-size: 13px;
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
    .breadcrumb .current { color: var(--text-secondary); font-size: 13px; }

    /* Main layout */
    .viewer-layout {
        display: grid;
        grid-template-columns: 1.6fr 1fr;
        gap: 28px;
        align-items: start;
    }
    @media (max-width: 1024px) { .viewer-layout { grid-template-columns: 1fr; } }

    /* ===== 3D VIEWER ===== */
    .viewer-wrapper {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        position: sticky;
        top: 80px;
    }
    @media (max-width: 1024px) { .viewer-wrapper { position: static; } }

    .viewer-canvas {
        position: relative;
        width: 100%;
        height: 480px;
        background: var(--viewer-bg);
        overflow: hidden;
    }
    @media (max-width: 768px) { .viewer-canvas { height: 320px; } }

    #viewer3D { width: 100%; height: 100%; }

    .no-model-msg {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: rgba(255,255,255,0.6);
        gap: 12px;
        text-align: center;
        padding: 20px;
    }
    .no-model-msg i { font-size: 64px; opacity: 0.2; }
    .no-model-msg h3 { color: white; font-size: 18px; }
    .no-model-msg p { font-size: 13px; }

    .viewer-controls {
        display: flex;
        justify-content: center;
        gap: 8px;
        padding: 16px;
        background: var(--bg-secondary);
        border-top: 1px solid var(--border-light);
        flex-wrap: wrap;
    }

    .ctrl-btn {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: 1.5px solid var(--border-color);
        background: var(--bg-primary);
        color: var(--text-secondary);
        font-size: 14px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .ctrl-btn:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.1); }
    .ctrl-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

    .color-strip {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-top: 1px solid var(--border-light);
        background: var(--bg-secondary);
        flex-wrap: wrap;
    }
    .color-strip-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); flex-shrink: 0; }
    .color-swatch {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 2.5px solid white;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
    }
    .color-swatch:hover { transform: scale(1.2); }
    .color-swatch.active { box-shadow: 0 0 0 3px var(--primary); transform: scale(1.1); }
    .color-swatch.reset-btn {
        background: linear-gradient(45deg, #ccc 25%, #eee 25%, #eee 50%, #ccc 50%, #ccc 75%, #eee 75%);
        background-size: 8px 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .color-swatch.reset-btn i { font-size: 11px; color: #666; }

    .viewer-hint {
        display: flex;
        gap: 16px;
        padding: 10px 16px;
        background: var(--bg-primary);
        border-top: 1px solid var(--border-light);
        flex-wrap: wrap;
    }
    .hint-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-muted); }
    .hint-item i { color: var(--primary); font-size: 12px; }

    /* ===== INFO PANEL ===== */
    .info-panel { display: flex; flex-direction: column; gap: 16px; }

    .info-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .info-card-head {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
    }
    .info-card-head i { color: var(--primary); }
    .info-card-body { padding: 20px; }

    .category-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        background: var(--primary-light);
        color: var(--primary);
        border-radius: var(--radius-full);
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 10px;
    }
    .product-title {
        font-family: var(--font-display);
        font-size: 24px;
        color: var(--text-primary);
        margin-bottom: 14px;
        line-height: 1.2;
    }

    .price-box {
        background: var(--primary-light);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-bottom: 14px;
    }
    .price-main { font-size: 28px; font-weight: 700; color: var(--primary); font-family: var(--font-display); }
    .price-main.sale { color: #EF4444; }
    .price-orig { font-size: 14px; color: var(--text-muted); text-decoration: line-through; margin-left: 8px; }

    .sale-banner-sm {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 7px 12px;
        background: linear-gradient(135deg, #EF4444, #FF6B6B);
        border-radius: var(--radius-md);
        margin-bottom: 10px;
        font-size: 12px;
        color: white;
        font-weight: 600;
    }

    .product-desc {
        font-size: 13px;
        color: var(--text-secondary);
        line-height: 1.7;
        margin-bottom: 16px;
    }

    /* ===== PAYMENT POLICY CARD ===== */
    .payment-policy-card {
        margin-top: 16px;
        padding: 14px;
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        border-left: 4px solid var(--primary);
        font-size: 13px;
    }
    .payment-policy-card i { color: var(--primary); margin-right: 8px; }
    .payment-policy-card .policy-title {
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    .payment-policy-card .policy-desc {
        color: var(--text-secondary);
        font-size: 12px;
    }

    .existing-notice {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 12px;
    }
    .existing-notice.reservation { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
    .existing-notice.appointment { background: #DBEAFE; color: #1E40AF; border: 1px solid #BFDBFE; }
    .existing-notice a { color: inherit; font-weight: 700; }

    .btn-main-action {
        width: 100%;
        padding: 14px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 15px;
        font-weight: 700;
        font-family: var(--font-main);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s;
        box-shadow: 0 4px 14px rgba(0,183,97,0.3);
        margin-bottom: 8px;
    }
    .btn-main-action:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,183,97,0.4); }
    .btn-main-action:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
    .btn-main-action.apt { background: linear-gradient(135deg, #3B82F6, #2563EB); box-shadow: 0 4px 14px rgba(59,130,246,0.3); }
    .btn-main-action.apt:hover:not(:disabled) { box-shadow: 0 8px 20px rgba(59,130,246,0.4); }

    .action-helper {
        font-size: 11px;
        color: var(--text-muted);
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        margin-bottom: 4px;
    }

    .view-product-link {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 10px;
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s;
        margin-top: 6px;
    }
    .view-product-link:hover { border-color: var(--primary); color: var(--primary); }

    .flow-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 18px;
        border: 1px solid var(--border-light);
        box-shadow: var(--shadow-sm);
    }
    .flow-card-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 7px;
        margin-bottom: 14px;
    }
    .flow-card-title i { color: var(--primary); }

    .lens-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 6px; }
    .lens-btn {
        padding: 11px 10px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        cursor: pointer;
        transition: all 0.2s;
        text-align: left;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .lens-btn:hover { border-color: var(--primary); background: var(--primary-light); }
    .lens-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
    .lens-btn .ln { font-weight: 700; font-size: 13px; color: var(--text-primary); }
    .lens-btn .lp { font-size: 11px; color: var(--primary); font-weight: 600; }
    .lens-btn .ld { font-size: 10px; color: var(--text-muted); }

    .rx-toggle { display: flex; gap: 10px; margin: 12px 0 8px; flex-wrap: wrap; }
    .rx-option {
        flex: 1;
        min-width: 130px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        background: var(--bg-primary);
        transition: all 0.2s;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary);
    }
    .rx-option input[type="radio"] { display: none; }
    .rx-option:has(input:checked) { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
    .rx-option .icon { width: 28px; height: 28px; border-radius: 7px; background: var(--border-light); display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .rx-option:has(input:checked) .icon { background: var(--primary); color: white; }

    .rx-form { background: var(--bg-primary); border-radius: var(--radius-md); padding: 14px; margin-top: 10px; border: 1px solid var(--border-light); }
    .rx-form h4 { font-size: 12px; font-weight: 700; color: var(--text-primary); margin-bottom: 12px; display: flex; align-items: center; gap: 5px; }
    .rx-eyes { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .rx-eye-box { background: var(--bg-secondary); border-radius: 9px; padding: 10px; border: 1px solid var(--border-light); }
    .rx-eye-label { font-size: 11px; font-weight: 700; color: var(--primary); margin-bottom: 7px; display: flex; align-items: center; gap: 4px; }
    .rx-eye-label span { background: var(--primary); color: white; padding: 1px 6px; border-radius: 3px; font-size: 9px; }
    .rx-inputs { display: flex; gap: 5px; }
    .rx-input-group { flex: 1; }
    .rx-input-group label { font-size: 8px; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 2px; font-weight: 600; }
    .rx-input-group input { width: 100%; padding: 6px 4px; border: 1.5px solid var(--border-color); border-radius: 6px; background: var(--bg-primary); color: var(--text-primary); font-size: 12px; text-align: center; font-family: var(--font-main); }
    .rx-input-group input:focus { outline: none; border-color: var(--primary); }
    .rx-note { font-size: 10px; color: var(--text-muted); margin-top: 10px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px; }
    .rx-note i { color: var(--primary); }

    .eye-exam-box { background: var(--primary-light); border: 1px solid rgba(0,183,97,0.2); border-radius: var(--radius-md); padding: 14px; margin-top: 10px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 6px; }
    .eye-exam-box i { font-size: 24px; color: var(--primary); }
    .eye-exam-box p { font-size: 12px; color: var(--text-secondary); line-height: 1.5; }

    /* ===== COLOR SELECTOR ===== */
    .color-selector-box { background: var(--bg-primary); border-radius: var(--radius-md); padding: 14px; margin-bottom: 8px; border: 1px solid var(--border-light); }
    .cs-title { font-size: 13px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 6px; margin-bottom: 12px; }
    .cs-title i { color: var(--primary); }
    .cs-selected-label { font-weight: 500; color: var(--text-secondary); font-size: 12px; margin-left: auto; }
    .cs-selected-label.chosen { color: var(--primary); font-weight: 600; }
    .color-btns { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 8px; }
    .color-btn { display: flex; align-items: center; gap: 6px; padding: 7px 12px; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); cursor: pointer; transition: all 0.2s; font-family: inherit; }
    .color-btn:hover:not(:disabled):not(.oos) { border-color: var(--primary); background: var(--primary-light); }
    .color-btn.selected { border-color: var(--primary); background: var(--primary-light); box-shadow: 0 0 0 3px rgba(0,183,97,0.12); }
    .color-btn.oos { opacity: 0.45; cursor: not-allowed; border-style: dashed; }
    .color-dot { width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid rgba(0,0,0,0.15); flex-shrink: 0; }
    .color-btn-name { font-size: 12px; font-weight: 600; color: var(--text-primary); }
    .color-btn-qty { font-size: 10px; color: var(--text-muted); margin-left: 2px; }
    .color-btn-qty.low { color: var(--warning); font-weight: 600; }
    .color-btn-qty.oos-label { color: var(--danger); font-weight: 600; }
    .cs-hint { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
    .cs-hint i { color: var(--primary); }
    .sold-out-notice { display: flex; align-items: flex-start; gap: 10px; background: #FEE2E2; border: 1px solid #FECACA; border-radius: var(--radius-md); padding: 12px; }
    .theme-dark .sold-out-notice { background: #3B0F0F; border-color: #7F1D1D; }
    .sold-out-notice > i { color: var(--danger); font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .sold-out-notice strong { font-size: 13px; color: var(--danger); display: block; margin-bottom: 2px; }
    .sold-out-notice p { font-size: 11px; color: var(--text-secondary); }

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

    /* ===== CLINIC CARD ===== */
    .clinic-card {
        background: var(--bg-secondary);
        border-radius: var(--radius-lg);
        padding: 16px;
        border: 1px solid var(--border-light);
        display: flex;
        gap: 12px;
        align-items: flex-start;
        text-decoration: none;
        transition: all 0.2s;
        box-shadow: var(--shadow-sm);
    }
    .clinic-card:hover { border-color: var(--primary); }
    .clinic-logo-box { width: 44px; height: 44px; border-radius: 12px; background: var(--primary-light); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
    .clinic-logo-box img { width: 100%; height: 100%; object-fit: cover; }
    .clinic-logo-box i { font-size: 20px; color: var(--primary); }
    .clinic-info .cn { font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; }
    .clinic-info .ca { font-size: 11px; color: var(--text-secondary); display: flex; align-items: flex-start; gap: 4px; }
    .clinic-info .ca i { color: var(--primary); margin-top: 1px; flex-shrink: 0; }
    .clinic-info .ch { font-size: 11px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
    .clinic-info .ch i { color: var(--primary); }

    /* ===== MODAL STYLES ===== */
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
        max-width: 520px;
        max-height: 92vh;
        overflow-y: auto;
        animation: modalIn 0.25s ease;
    }
    @keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: none; } }
    .modal-head {
        padding: 18px 22px;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--primary-gradient);
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .modal-head h3 { font-size: 16px; font-weight: 700; color: white; display: flex; align-items: center; gap: 8px; }
    .modal-close { background: none; border: none; color: white; font-size: 22px; cursor: pointer; opacity: 0.8; padding: 4px; }
    .modal-close:hover { opacity: 1; transform: rotate(90deg); transition: all 0.2s; }
    .modal-body { padding: 20px 22px; }

    .modal-summary-box {
        background: var(--primary-light);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-bottom: 16px;
        border: 1px solid rgba(0,183,97,0.2);
    }
    .ms-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
        font-size: 13px;
    }
    .ms-row .ms-label { color: var(--text-secondary); font-weight: 500; }
    .ms-row .ms-value { color: var(--text-primary); font-weight: 600; }
    .ms-row .ms-value.success { color: var(--primary); }
    .ms-divider { height: 1px; background: var(--border-color); margin: 6px 0; }

    .modal-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-bottom: 12px;
    }
    @media (max-width: 480px) { .modal-form-row { grid-template-columns: 1fr; } }
    .modal-form-group { margin-bottom: 12px; }
    .modal-form-group.half { margin-bottom: 0; }
    .modal-label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .modal-label i { color: var(--primary); margin-right: 4px; }
    .modal-input {
        width: 100%;
        padding: 10px 12px;
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 13px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-family: var(--font-main);
        transition: border-color 0.2s;
    }
    .modal-input:focus { outline: none; border-color: var(--primary); }
    .modal-textarea { resize: vertical; min-height: 50px; }

    .modal-price-box {
        background: var(--bg-primary);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        margin-top: 6px;
        border: 1px solid var(--border-light);
    }
    .modal-price-title { font-size: 12px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .modal-price-title i { color: var(--primary); }
    .mpb-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        padding: 3px 0;
    }
    .mpb-row .mpb-label { color: var(--text-secondary); }
    .mpb-row .mpb-value { font-weight: 600; color: var(--text-primary); }
    .mpb-row.discount .mpb-value { color: var(--danger); }
    .mpb-divider { height: 1px; background: var(--border-color); margin: 6px 0; }
    .mpb-row.total .mpb-value { font-size: 16px; color: var(--primary); font-weight: 700; }
    .mpb-row.dp .mpb-value { color: var(--warning); }
    .mpb-row.balance .mpb-value { color: var(--text-secondary); }

    .modal-apt-notice {
        background: #EFF6FF;
        border: 1px solid #BFDBFE;
        border-radius: var(--radius-md);
        padding: 12px;
        text-align: center;
        font-size: 12px;
        color: #1E40AF;
        margin-top: 6px;
    }
    .theme-dark .modal-apt-notice { background: #1E2A4A; border-color: #2D4A8A; color: #93C5FD; }
    .modal-exam-notice {
        background: #FEF3C7;
        border: 1px solid #FDE68A;
        border-radius: var(--radius-md);
        padding: 12px;
        text-align: center;
        font-size: 12px;
        color: #92400E;
        margin-top: 6px;
    }
    .theme-dark .modal-exam-notice { background: #3D2E00; border-color: #5A4C00; color: #FBBF24; }

    .modal-foot {
        padding: 14px 22px;
        border-top: 1px solid var(--border-light);
        display: flex;
        gap: 10px;
        background: var(--bg-secondary);
        border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    }
    .btn-modal {
        flex: 1;
        padding: 11px;
        border-radius: var(--radius-sm);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-family: var(--font-main);
        transition: all 0.2s;
    }
    .btn-modal.confirm { background: var(--primary-gradient); color: white; }
    .btn-modal.confirm.apt { background: linear-gradient(135deg, #3B82F6, #2563EB); }
    .btn-modal.confirm:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-modal.cancel { background: var(--bg-primary); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .btn-modal.cancel:hover { border-color: var(--danger); color: var(--danger); }

    /* Toast */
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9990; }
    .toast { display: flex; align-items: center; gap: 10px; background: var(--bg-secondary); border-radius: var(--radius-md); padding: 12px 18px; box-shadow: var(--shadow-lg); margin-bottom: 10px; min-width: 280px; animation: toastIn 0.3s ease; border-left: 4px solid var(--success); }
    .toast.error { border-left-color: var(--danger); }
    .toast.info { border-left-color: var(--info); }
    .toast i { font-size: 16px; flex-shrink: 0; }
    .toast.success i { color: var(--success); }
    .toast.error i { color: var(--danger); }
    .toast.info i { color: var(--info); }
    .toast span { font-size: 13px; color: var(--text-primary); flex: 1; }
    @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

    #loadingOverlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; }
    .spinner { width: 46px; height: 46px; border: 4px solid rgba(255,255,255,0.2); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hidden { display: none !important; }

        /* ===== ✅ DELIVERY FEATURE STYLES ===== */
    .fulfillment-section {
        margin-bottom: 18px;
        padding-bottom: 16px;
        border-bottom: 1px dashed var(--border-color);
    }
    .form-label-big {
        display: block;
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 10px;
    }
    .fulfillment-options {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    @media (max-width: 480px) { .fulfillment-options { grid-template-columns: 1fr; } }

    .fulfillment-btn {
        background: var(--bg-primary);
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 14px 12px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
        font-family: var(--font-main);
    }
    .fulfillment-btn i { font-size: 22px; color: var(--text-secondary); }
    .fulfillment-btn .fulfillment-name { font-size: 14px; font-weight: 700; color: var(--text-primary); }
    .fulfillment-btn .fulfillment-desc { font-size: 11px; color: var(--text-muted); text-align: center; line-height: 1.3; }
    .fulfillment-btn:hover { border-color: var(--primary); }
    .fulfillment-btn.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
    }
    .fulfillment-btn.selected i { color: var(--primary); }

    .delivery-section-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 7px;
        margin-bottom: 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--border-light);
    }
    .delivery-section-title i { color: var(--primary); }

    .form-row-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    @media (max-width: 480px) { .form-row-2 { grid-template-columns: 1fr; } }

    .payment-methods-inline { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
    .payment-method-option {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        background: var(--bg-primary);
        transition: all 0.2s;
    }
    .payment-method-option:hover { border-color: var(--primary); }
    .payment-method-option.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
    }
    .payment-method-option input { display: none; }
    .payment-method-option i { font-size: 20px; color: var(--primary); flex-shrink: 0; }
    .payment-method-option > div { display: flex; flex-direction: column; gap: 2px; flex: 1; }
    .payment-method-option strong { font-size: 13px; color: var(--text-primary); }
    .payment-method-option span { font-size: 12px; color: var(--text-secondary); font-weight: 700; }

    .badge-inline {
        display: inline-block;
        padding: 1px 8px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 700;
        margin-left: 6px;
    }
    .badge-success { background: var(--success); color: white; }
    .badge-warning { background: var(--warning); color: white; }

    .mpb-row .mpb-value.discount-value { color: #EF4444; font-weight: 700; }
    .mpb-row .mpb-value.vat-value { color: var(--warning); font-weight: 700; }

    </style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div id="loadingOverlay" class="hidden"><div class="spinner"></div></div>

<div class="main-content">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="#" onclick="goBack(); return false;" id="backButton">
            <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($product['name']); ?>
        </a>
        <span class="sep">/</span>
        <span class="current"><i class="fas fa-cube"></i> 3D View</span>
    </div>

    <div class="viewer-layout">

        <!-- LEFT: 3D Viewer -->
        <div class="viewer-wrapper">
            <div class="viewer-canvas">
                <div id="viewer3D"></div>
                <?php if (!$has_3d): ?>
                <div class="no-model-msg">
                    <i class="fas fa-cube"></i>
                    <h3>No 3D Model Available</h3>
                    <p><?php echo htmlspecialchars($product['name']); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Viewer controls -->
            <div class="viewer-controls">
                <button class="ctrl-btn" onclick="resetView()" title="Reset View"><i class="fas fa-undo"></i></button>
                <button class="ctrl-btn" id="rotateBtn" onclick="toggleAutoRotate()" title="Auto Rotate"><i class="fas fa-sync-alt"></i></button>
                <button class="ctrl-btn" id="wireBtn" onclick="toggleWireframe()" title="Wireframe"><i class="fas fa-border-all"></i></button>
                <button class="ctrl-btn" onclick="zoomIn()" title="Zoom In"><i class="fas fa-search-plus"></i></button>
                <button class="ctrl-btn" onclick="zoomOut()" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
            </div>

            <!-- Color swatches -->
            <?php if ($has_colors && !$is_fully_sold_out): ?>
            <div class="color-strip">
                <span class="color-strip-label">3D Color:</span>
                <div class="color-swatch reset-btn" onclick="resetColor()" title="Restore original">
                    <i class="fas fa-undo"></i>
                </div>
                <?php foreach ($product_colors as $c): if ($c['quantity'] <= 0) continue; ?>
                <div class="color-swatch"
                     style="background:<?php echo htmlspecialchars($c['code']); ?>"
                     onclick="changeColor('<?php echo htmlspecialchars($c['code']); ?>', this)"
                     title="<?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['quantity']; ?> left)">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Controls hint -->
            <div class="viewer-hint">
                <div class="hint-item"><i class="fas fa-mouse-pointer"></i> Drag to rotate</div>
                <div class="hint-item"><i class="fas fa-search"></i> Scroll to zoom</div>
                <div class="hint-item"><i class="fas fa-arrows-alt"></i> Pinch (mobile)</div>
            </div>
        </div>

        <!-- RIGHT: Info Panel -->
        <div class="info-panel">

            <!-- Product Info Card -->
            <div class="info-card">
                <div class="info-card-head">
                    <i class="fas fa-box"></i> Product Details
                </div>
                <div class="info-card-body">
                    <div class="category-pill">
                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                    </div>
                    <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                    <?php if ($is_on_sale): ?>
                    <div class="sale-banner-sm">
                        <i class="fas fa-fire"></i> On Sale! — Save ₱<?php echo number_format($savings, 2); ?>
                        <span style="margin-left:auto; opacity:0.85; font-size:11px;">-<?php echo $discount_pct; ?>%</span>
                    </div>
                    <?php endif; ?>

                    <div class="price-box">
                        <span class="price-main <?php echo $is_on_sale ? 'sale' : ''; ?>">
                            ₱<?php echo number_format($display_price, 2); ?>
                        </span>
                        <?php if ($is_on_sale): ?>
                            <span class="price-orig">₱<?php echo number_format($original_price, 2); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($product['description'])): ?>
                    <p class="product-desc"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    <?php endif; ?>

                    <!-- ===== PAYMENT POLICY CARD ===== -->
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
                            <?php if ($payment_type == 'downpayment'): ?>
                                <i class="fas fa-percent"></i> <?php echo $downpayment_percent; ?>% downpayment (₱<?php echo number_format($downpayment_amount, 2); ?>) required online.
                                Balance of ₱<?php echo number_format($balance_amount, 2); ?> to be paid at the clinic.
                            <?php elseif ($payment_type == 'full'): ?>
                                <i class="fas fa-cash"></i> 100% full payment of ₱<?php echo number_format($downpayment_amount, 2); ?> required online.
                            <?php elseif ($payment_type == 'onsite'): ?>
                                <i class="fas fa-store"></i> Pay ₱<?php echo number_format($display_price, 2); ?> directly at the clinic. No online payment required.
                            <?php elseif ($payment_type == 'free'): ?>
                                <i class="fas fa-gift"></i> This is a free service. No payment required.
                            <?php endif; ?>
                        </div>
                    </div>

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
                                    <p>All variants are currently unavailable.</p>
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
                                        <?php if (!empty($color['code']) && strlen($color['code']) >= 4): ?>
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
                                <i class="fas fa-info-circle"></i> Please select a color to continue.
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ===== SIZE SELECTOR ===== -->
                    <?php if ($has_sizes): ?>
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
                            <i class="fas fa-info-circle"></i> Please select your preferred frame size.
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- Existing notices -->
                    <?php if ($existing_reservation): ?>
                    <div class="existing-notice reservation">
                        <i class="fas fa-bookmark"></i>
                        Already reserved. <a href="my-reservations.php">View →</a>
                    </div>
                    <?php elseif ($existing_appointment): ?>
                    <div class="existing-notice appointment">
                        <i class="fas fa-calendar-check"></i>
                        Already booked. <a href="my-appointments.php">View →</a>
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

                    <!-- Action Buttons -->
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
                        <button class="btn-main-action apt" onclick="openModal('appointment')">
                            <i class="fas fa-calendar-plus"></i> Book Appointment
                        </button>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> Appointment confirmed by clinic.</p>
                    <?php elseif ($IS_ACCESSORY): ?>
                        <button class="btn-main-action" onclick="openModal('reservation')">
                            <i class="fas fa-bookmark"></i> Reserve This Item
                        </button>
                        <p class="action-helper"><i class="fas fa-info-circle"></i> <?php echo $downpayment_percent; ?>% downpayment required.</p>
                    <?php else: ?>
                        <button class="btn-main-action" id="mainActionBtn" onclick="handleMainAction()" <?php echo ($has_stock_tracking || $has_sizes) ? 'disabled' : ''; ?>>
                            <i class="fas fa-arrow-right"></i> Continue
                        </button>
                        <p class="action-helper" id="actionHelper">
                            <i class="fas fa-info-circle"></i>
                            <?php 
                            if ($has_sizes) {
                                echo 'Please select a frame size to continue.';
                            } elseif ($has_stock_tracking) {
                                echo 'Select a color variant to continue.';
                            } else {
                                echo 'Select your lens option below to continue.';
                            }
                            ?>
                        </p>
                    <?php endif; ?>

                    <!-- View full product page link -->
                    <a href="product-view.php?id=<?php echo $product_id; ?>" class="view-product-link">
                        <i class="fas fa-external-link-alt"></i> View Full Product Page
                    </a>
                </div>
            </div>

            <!-- Lens Selection -->
            <?php if (!$IS_SERVICE && !$IS_ACCESSORY): ?>
            <div class="flow-card">
                <div class="flow-card-title">
                    <i class="fas fa-glasses"></i>
                    <?php if ($IS_CONTACT_LENS): ?>Contact Lens Options
                    <?php elseif ($IS_LENS_ONLY): ?>Lens Type
                    <?php else: ?>Select Lens Option
                    <?php endif; ?>
                </div>

                <?php if (!$IS_LENS_ONLY && !$IS_CONTACT_LENS): ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="frame_only" onclick="selectLens(this)">
                        <span class="ln">Frame Only</span>
                        <span class="lp">+₱0</span>
                        <span class="ld">No lenses</span>
                    </button>
                    <button class="lens-btn" data-lens="single_vision" onclick="selectLens(this)">
                        <span class="ln">Single Vision</span>
                        <span class="lp">+₱500</span>
                        <span class="ld">Near or far sight</span>
                    </button>
                    <button class="lens-btn" data-lens="progressive" onclick="selectLens(this)">
                        <span class="ln">Progressive</span>
                        <span class="lp">+₱1,500</span>
                        <span class="ld">Near, mid & far</span>
                    </button>
                    <button class="lens-btn" data-lens="blue_cut" onclick="selectLens(this)">
                        <span class="ln">Blue Cut</span>
                        <span class="lp">+₱800</span>
                        <span class="ld">Screen glare</span>
                    </button>
                </div>
                <?php elseif ($IS_LENS_ONLY): ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="single_vision" onclick="selectLens(this)">
                        <span class="ln">Single Vision</span><span class="lp">Included</span>
                    </button>
                    <button class="lens-btn" data-lens="progressive" onclick="selectLens(this)">
                        <span class="ln">Progressive</span><span class="lp">+₱1,000</span>
                    </button>
                    <button class="lens-btn" data-lens="blue_cut" onclick="selectLens(this)">
                        <span class="ln">Blue Cut</span><span class="lp">+₱300</span>
                    </button>
                </div>
                <?php else: ?>
                <div class="lens-grid" id="lensGrid">
                    <button class="lens-btn selected" data-lens="contact_daily" onclick="selectLens(this)">
                        <span class="ln">Daily</span><span class="lp">Included</span>
                    </button>
                    <button class="lens-btn" data-lens="contact_monthly" onclick="selectLens(this)">
                        <span class="ln">Monthly</span><span class="lp">Included</span>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Prescription section -->
                <div id="rxSection" style="display:none; margin-top:14px;">
                    <div class="flow-card-title" style="font-size:13px; margin-bottom:8px;">
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
                        <h4><i class="fas fa-edit" style="color:var(--primary)"></i> Enter Prescription</h4>
                        <div class="rx-eyes">
                            <div class="rx-eye-box">
                                <div class="rx-eye-label">Right Eye <span>OD</span></div>
                                <div class="rx-inputs">
                                    <div class="rx-input-group"><label>SPH</label><input type="text" id="od_sph" placeholder="-1.50"></div>
                                    <div class="rx-input-group"><label>CYL</label><input type="text" id="od_cyl" placeholder="-0.50"></div>
                                    <div class="rx-input-group"><label>AXIS</label><input type="text" id="od_axis" placeholder="180"></div>
                                </div>
                            </div>
                            <div class="rx-eye-box">
                                <div class="rx-eye-label">Left Eye <span>OS</span></div>
                                <div class="rx-inputs">
                                    <div class="rx-input-group"><label>SPH</label><input type="text" id="os_sph" placeholder="-1.25"></div>
                                    <div class="rx-input-group"><label>CYL</label><input type="text" id="os_cyl" placeholder="-0.25"></div>
                                    <div class="rx-input-group"><label>AXIS</label><input type="text" id="os_axis" placeholder="175"></div>
                                </div>
                            </div>
                        </div>
                        <p class="rx-note"><i class="fas fa-shield-alt"></i> Verified by optometrist upon visit.</p>
                    </div>
                    <div id="rxExamBox" class="eye-exam-box" style="display:none;">
                        <i class="fas fa-calendar-check"></i>
                        <strong style="color:var(--primary)">Eye Exam Required</strong>
                        <p>We'll schedule an appointment for your eye exam first.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Clinic Card -->
            <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="clinic-card">
                <div class="clinic-logo-box">
                    <?php if (!empty($product['clinic_logo'])): ?>
                        <img src="/assets/images/clinic-logos/<?php echo $product['clinic_logo']; ?>" alt="">
                    <?php else: ?>
                        <i class="fas fa-store-alt"></i>
                    <?php endif; ?>
                </div>
                <div class="clinic-info">
                    <div class="cn"><?php echo htmlspecialchars($product['clinic_name']); ?></div>
                    <div class="ca"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(($product['clinic_address'] ?? '') . ', ' . ($product['clinic_city'] ?? '')); ?></div>
                    <?php if (!empty($product['clinic_hours'])): ?>
                    <div class="ch"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($product['clinic_hours']); ?></div>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-right" style="color:var(--primary); flex-shrink:0; margin-top:2px;"></i>
            </a>

        </div><!-- /info-panel -->
    </div><!-- /viewer-layout -->
</div><!-- /main-content -->

<!-- ============================================ -->
<!-- MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle"><i class="fas fa-calendar-alt"></i> Schedule Your Visit</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
                <div class="modal-body">

            <!-- Fulfillment Selector -->
            <div class="fulfillment-section" id="fulfillmentSection" style="display: none;">
                <label class="form-label-big">How would you like to receive your order?</label>
                <div class="fulfillment-options">
                    <button type="button" class="fulfillment-btn selected" data-type="pickup" onclick="selectFulfillment('pickup')">
                        <i class="fas fa-store"></i>
                        <span class="fulfillment-name">Pickup</span>
                        <span class="fulfillment-desc">Pick up at the clinic</span>
                    </button>
                    <button type="button" class="fulfillment-btn" data-type="delivery" onclick="selectFulfillment('delivery')">
                        <i class="fas fa-truck"></i>
                        <span class="fulfillment-name">Delivery</span>
                        <span class="fulfillment-desc">We deliver to your address</span>
                    </button>
                </div>
            </div>

            <!-- Summary Box (Product + Color + Size + Lens info) -->
            <div class="modal-summary-box" id="modalSummaryBox">
                <div class="ms-row">
                    <span class="ms-label">Product:</span>
                    <span class="ms-value success" id="modalProductName"><?php echo htmlspecialchars($product['name']); ?></span>
                </div>
                <div class="ms-row" id="modalColorRow">
                    <span class="ms-label">Color:</span>
                    <span class="ms-value" id="modalColorName">—</span>
                </div>
                <div class="ms-row" id="modalSizeRow">
                    <span class="ms-label">Size:</span>
                    <span class="ms-value" id="modalSizeName">—</span>
                </div>
                <div class="ms-row" id="modalLensRow">
                    <span class="ms-label">Lens Type:</span>
                    <span class="ms-value" id="modalLensName">Frame Only</span>
                </div>
                <div class="ms-divider"></div>
                <div class="ms-row" id="modalClinicRow">
                    <span class="ms-label">Clinic:</span>
                    <span class="ms-value"><?php echo htmlspecialchars($product['clinic_name']); ?></span>
                </div>
                <?php if (!empty($product['clinic_city'])): ?>
                <div class="ms-row">
                    <span class="ms-label">Location:</span>
                    <span class="ms-value"><?php echo htmlspecialchars($product['clinic_city']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($is_pwd_senior_display): ?>
                <div class="ms-row">
                    <span class="ms-label">Discount:</span>
                    <span class="ms-value" style="color:var(--primary);">PWD/Senior (20%)</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- PICKUP FIELDS -->
            <div id="pickupFields">
                <div class="modal-form-row">
                    <div class="modal-form-group half">
                        <label class="modal-label"><i class="fas fa-calendar-alt"></i> Date *</label>
                        <input type="date" id="mDate" class="modal-input" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="modal-form-group half">
                        <label class="modal-label"><i class="fas fa-clock"></i> Time *</label>
                        <input type="time" id="mTime" class="modal-input">
                    </div>
                </div>

                <div class="modal-form-group">
                    <label class="modal-label"><i class="fas fa-phone"></i> Contact Number *</label>
                    <input type="tel" id="mContact" class="modal-input" placeholder="09XX XXX XXXX" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
                </div>
            </div>

            <!-- DELIVERY FIELDS -->
            <div id="deliveryFields" style="display: none;">
                <div class="delivery-section-title">
                    <i class="fas fa-truck"></i> Delivery Information
                </div>
                <div class="modal-form-group">
                    <label class="modal-label"><i class="fas fa-user"></i> Recipient Name *</label>
                    <input type="text" id="mDeliveryName" class="modal-input" placeholder="Juan Dela Cruz" value="<?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>">
                </div>
                <div class="modal-form-group">
                    <label class="modal-label"><i class="fas fa-phone"></i> Contact Number *</label>
                    <input type="tel" id="mDeliveryPhone" class="modal-input" placeholder="09XX XXX XXXX" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
                </div>
                <div class="modal-form-group">
                    <label class="modal-label"><i class="fas fa-home"></i> Street Address *</label>
                    <input type="text" id="mDeliveryAddress" class="modal-input" placeholder="House #, Street Name" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                </div>
                <div class="form-row-2">
                    <div class="modal-form-group">
                        <label class="modal-label">Barangay *</label>
                        <input type="text" id="mDeliveryBarangay" class="modal-input" placeholder="Barangay">
                    </div>
                    <div class="modal-form-group">
                        <label class="modal-label">City *</label>
                        <input type="text" id="mDeliveryCity" class="modal-input" placeholder="City">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="modal-form-group">
                        <label class="modal-label">Province *</label>
                        <input type="text" id="mDeliveryProvince" class="modal-input" placeholder="Province">
                    </div>
                    <div class="modal-form-group">
                        <label class="modal-label">ZIP Code *</label>
                        <input type="text" id="mDeliveryZip" class="modal-input" placeholder="1234">
                    </div>
                </div>
                <div class="modal-form-group">
                    <label class="modal-label">Landmark (Optional)</label>
                    <input type="text" id="mDeliveryLandmark" class="modal-input" placeholder="Near SM Mall">
                </div>
                <div class="modal-form-group">
                    <label class="modal-label">Preferred Delivery Date (Optional)</label>
                    <input type="date" id="mDeliveryDate" class="modal-input" min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="modal-form-group">
                    <label class="modal-label">Payment Method *</label>
                    <div class="payment-methods-inline">
                        <label class="payment-method-option selected" data-method="online" onclick="selectDeliveryPayment('online')">
                            <input type="radio" name="delivery_payment_method" value="online" checked>
                            <i class="fas fa-credit-card"></i>
                            <div>
                                <strong>Pay Online Now</strong>
                                <span class="pm-total-online">₱0.00</span>
                            </div>
                        </label>
                        <label class="payment-method-option cod-option" data-method="cod" onclick="selectDeliveryPayment('cod')" style="display: none;">
                            <input type="radio" name="delivery_payment_method" value="cod">
                            <i class="fas fa-money-bill-wave"></i>
                            <div>
                                <strong>Cash on Delivery</strong>
                                <span class="pm-total-cod">₱0.00</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Shared Notes -->
            <div class="modal-form-group">
                <label class="modal-label"><i class="fas fa-sticky-note"></i> Notes (Optional)</label>
                <textarea id="mNotes" class="modal-input modal-textarea" rows="2" placeholder="Any special requests..."></textarea>
            </div>

            <!-- Price Breakdown -->
            <div class="modal-price-box" id="modalPriceBox">
                <div class="modal-price-title"><i class="fas fa-receipt"></i> Payment Summary</div>

                <div class="mpb-row">
                    <span class="mpb-label">Product</span>
                    <span class="mpb-value" id="summaryProductValue">₱0.00</span>
                </div>
                <div class="mpb-row" id="mpbLensRow" style="display:none;">
                    <span class="mpb-label">Lens Upgrade</span>
                    <span class="mpb-value" id="mpbLensVal">+₱0</span>
                </div>
                <div class="mpb-row" id="mpbDiscountRow" style="display:none;">
                    <span class="mpb-label">
                        <span id="summaryDiscountLabel">PWD Discount</span>
                        <span class="badge-inline badge-success">20%</span>
                    </span>
                    <span class="mpb-value discount-value" id="summaryDiscountValue">-₱0.00</span>
                </div>
                <div class="mpb-row" id="mpbVatRow" style="display:none;">
                    <span class="mpb-label">
                        VAT <span class="badge-inline badge-warning" id="summaryVatRate">12%</span>
                    </span>
                    <span class="mpb-value vat-value" id="summaryVatValue">+₱0.00</span>
                </div>
                <div class="mpb-row" id="mpbDeliveryRow" style="display:none;">
                    <span class="mpb-label">Delivery Fee</span>
                    <span class="mpb-value" id="summaryDeliveryFee">₱0.00</span>
                </div>

                <div class="mpb-divider"></div>

                <div class="mpb-row total">
                    <span class="mpb-label" style="font-weight:700;">Total</span>
                    <span class="mpb-value" id="mpbTotal">₱0.00</span>
                </div>
            </div>

            <div class="modal-apt-notice" id="modalAptNotice" style="display:none;">
                <i class="fas fa-calendar-check" style="font-size:18px; display:block; margin-bottom:6px;"></i>
                <strong>Appointment Booking</strong><br>
                You'll be redirected to book an appointment.
            </div>

            <div class="modal-exam-notice" id="modalExamNotice" style="display:none;">
                <i class="fas fa-stethoscope" style="font-size:18px; display:block; margin-bottom:6px;"></i>
                <strong>Eye Exam Required</strong><br>
                You'll be redirected to book an eye exam first.
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
// GO BACK FUNCTION - FIXED!
// ============================================
function goBack() {
    // Check if user came from our website
    if (document.referrer && document.referrer.indexOf(window.location.hostname) !== -1) {
        // Go back naturally using browser history
        window.history.back();
    } else {
        // Fallback: go to product view
        window.location.href = 'product-view.php?id=<?php echo $product_id; ?>';
    }
}

// ============================================
// VARIABLES
// ============================================
let selectedLens = '<?php echo ($IS_ACCESSORY || $IS_SERVICE) ? '' : ($IS_LENS_ONLY ? 'single_vision' : ($IS_CONTACT_LENS ? 'contact_daily' : 'frame_only')); ?>';
let rxKnowledge = null;
let currentModal = null;
let selectedColor = null;
let selectedSize = null;

const BASE_PRICE         = <?php echo $display_price; ?>;
const DP_PERCENT         = <?php echo (float)$downpayment_percent; ?>;
const DISCOUNT_AMOUNT    = <?php echo (float)$discount_amount; ?>;
const VAT_RATE           = <?php echo (float)$vat_rate; ?>;
const IS_SERVICE         = <?php echo $IS_SERVICE ? 'true' : 'false'; ?>;
const IS_ACCESSORY       = <?php echo $IS_ACCESSORY ? 'true' : 'false'; ?>;
const IS_LENS_ONLY       = <?php echo $IS_LENS_ONLY ? 'true' : 'false'; ?>;
const IS_CONTACT         = <?php echo $IS_CONTACT_LENS ? 'true' : 'false'; ?>;
const NEEDS_LENS         = <?php echo $NEEDS_LENS_SELECTION ? 'true' : 'false'; ?>;
const HAS_STOCK_TRACKING = <?php echo $has_stock_tracking ? 'true' : 'false'; ?>;
const HAS_SIZES          = <?php echo $has_sizes ? 'true' : 'false'; ?>;
const IS_FULLY_SOLD_OUT  = <?php echo $is_fully_sold_out ? 'true' : 'false'; ?>;
const LENS_PRICES = { frame_only:0, single_vision:500, progressive:1500, blue_cut:800, contact_daily:0, contact_monthly:0 };

// ============================================
// ✅ DELIVERY FEATURE — Constants
// ============================================
const OFFERS_DELIVERY          = <?php echo $offers_delivery ? 'true' : 'false'; ?>;
const ALLOW_COD                = <?php echo $allow_cod ? 'true' : 'false'; ?>;
const CLINIC_DELIVERY_FEE      = <?php echo $clinic_delivery_fee; ?>;
const CLINIC_FREE_DELIVERY_MIN = <?php echo $clinic_free_delivery_min; ?>;

const IS_ACCESSORY_CAT         = <?php echo $IS_ACCESSORY ? 'true' : 'false'; ?>;
const IS_FRAMES_FAMILY         = <?php echo in_array($category, ['Frames','Eyeglasses','Sunglasses']) ? 'true' : 'false'; ?>;

const IS_PWD_SENIOR            = <?php echo $is_pwd_senior_display ? 'true' : 'false'; ?>;
const PWD_SENIOR_TYPE          = '<?php echo $pwd_senior_type_display; ?>';
const VAT_RATE_TAX             = <?php echo $vat_rate_display; ?>;
const DISCOUNT_RATE            = <?php echo $discount_rate_display; ?>;
const PRODUCT_SUBTOTAL         = <?php echo $display_price; ?>;

let selectedFulfillment        = 'pickup';
let selectedDeliveryPayment    = 'online';

// ============================================
// SIZE SELECTION
// ============================================
function selectSize(btn) {
    document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedSize = btn.dataset.size;
    const label = document.getElementById('sizeSelectedLabel');
    if (label) { label.textContent = selectedSize; label.classList.add('chosen'); }
    const hint = document.getElementById('sizeHint');
    if (hint) hint.style.display = 'none';
    updateActionButton();
    updateModalSummary();
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
    if (label) { label.textContent = selectedColor.name + ' (' + selectedColor.qty + ' left)'; label.classList.add('chosen'); }
    const hint = document.getElementById('csHint');
    if (hint) hint.style.display = 'none';
    changeColor(selectedColor.code, null);
    const btn2 = document.getElementById('mainActionBtn');
    if (btn2 && btn2.disabled && !IS_FULLY_SOLD_OUT) btn2.disabled = false;
    updateActionButton();
    updateModalSummary();
}

// ============================================
// LENS SELECTION
// ============================================
function selectLens(btn) {
    document.querySelectorAll('.lens-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLens = btn.dataset.lens;

    const rxSection = document.getElementById('rxSection');
    const isFrameOnly = (selectedLens === 'frame_only');
    if (rxSection) {
        rxSection.style.display = (!isFrameOnly && NEEDS_LENS) ? 'block' : 'none';
        if (isFrameOnly) {
            rxKnowledge = null;
            document.querySelectorAll('input[name="rx_know"]').forEach(r => r.checked = false);
            document.getElementById('rxFormBox').style.display = 'none';
            document.getElementById('rxExamBox').style.display = 'none';
        }
    }
    updatePriceDisplay();
    updateActionButton();
    updateModalSummary();
}

function handleRxKnowledge(radio) {
    rxKnowledge = radio.value;
    document.getElementById('rxFormBox').style.display = rxKnowledge === 'know' ? 'block' : 'none';
    document.getElementById('rxExamBox').style.display = rxKnowledge === 'dont_know' ? 'block' : 'none';
    updateActionButton();
    updateModalSummary();
}

// ============================================
// MODAL SUMMARY UPDATE
// ============================================
function updateModalSummary() {
    const colorRow = document.getElementById('modalColorRow');
    const colorName = document.getElementById('modalColorName');
    if (colorRow) {
        colorRow.style.display = 'flex';
        colorName.textContent = selectedColor ? selectedColor.name : '—';
    }

    const sizeRow = document.getElementById('modalSizeRow');
    const sizeName = document.getElementById('modalSizeName');
    if (sizeRow) {
        sizeRow.style.display = 'flex';
        sizeName.textContent = selectedSize ? selectedSize : '—';
    }

    const lensRow = document.getElementById('modalLensRow');
    const lensName = document.getElementById('modalLensName');
    if (lensRow && selectedLens) {
        const lensLabels = {
            'frame_only': 'Frame Only',
            'single_vision': 'Single Vision',
            'progressive': 'Progressive',
            'blue_cut': 'Blue Cut',
            'contact_daily': 'Contact Lens (Daily)',
            'contact_monthly': 'Contact Lens (Monthly)'
        };
        lensName.textContent = lensLabels[selectedLens] || selectedLens;
    }
}

// ============================================
// PRICE DISPLAY
// ============================================
function getLensPrice() { return LENS_PRICES[selectedLens] || 0; }

function computeDeliveryFee(subtotal) {
    if (!OFFERS_DELIVERY) return 0;
    if (CLINIC_FREE_DELIVERY_MIN > 0 && subtotal >= CLINIC_FREE_DELIVERY_MIN) return 0;
    return CLINIC_DELIVERY_FEE;
}

function updatePriceDisplay() {
    const lensPrice = getLensPrice();
    const subtotal = PRODUCT_SUBTOTAL + lensPrice;

    let vatAmount = 0;
    let discountAmount = 0;
    if (IS_PWD_SENIOR) {
        discountAmount = subtotal * DISCOUNT_RATE;
    } else {
        vatAmount = subtotal * VAT_RATE_TAX;
    }

    const deliveryFee = (selectedFulfillment === 'delivery') ? computeDeliveryFee(subtotal) : 0;
    const finalTotal = subtotal + vatAmount - discountAmount + deliveryFee;

    // Modal breakdown
    const summaryProductValue = document.getElementById('summaryProductValue');
    const mpbLensRow = document.getElementById('mpbLensRow');
    const mpbLensVal = document.getElementById('mpbLensVal');
    const mpbDiscountRow = document.getElementById('mpbDiscountRow');
    const summaryDiscountLabel = document.getElementById('summaryDiscountLabel');
    const summaryDiscountValue = document.getElementById('summaryDiscountValue');
    const mpbVatRow = document.getElementById('mpbVatRow');
    const summaryVatRate = document.getElementById('summaryVatRate');
    const summaryVatValue = document.getElementById('summaryVatValue');
    const mpbDeliveryRow = document.getElementById('mpbDeliveryRow');
    const summaryDeliveryFee = document.getElementById('summaryDeliveryFee');
    const mpbTotal = document.getElementById('mpbTotal');

    if (summaryProductValue) summaryProductValue.textContent = '₱' + subtotal.toLocaleString('en-PH', {minimumFractionDigits: 2});
    if (mpbLensRow) mpbLensRow.style.display = lensPrice > 0 ? 'flex' : 'none';
    if (mpbLensVal) mpbLensVal.textContent = '+₱' + lensPrice.toLocaleString();

    if (mpbDiscountRow) mpbDiscountRow.style.display = (IS_PWD_SENIOR && discountAmount > 0) ? 'flex' : 'none';
    if (summaryDiscountLabel) summaryDiscountLabel.textContent = (PWD_SENIOR_TYPE === 'senior' ? 'Senior' : 'PWD') + ' Discount';
    if (summaryDiscountValue) summaryDiscountValue.textContent = '-₱' + discountAmount.toLocaleString('en-PH', {minimumFractionDigits: 2});

    if (mpbVatRow) mpbVatRow.style.display = (!IS_PWD_SENIOR && vatAmount > 0) ? 'flex' : 'none';
    if (summaryVatRate) summaryVatRate.textContent = (VAT_RATE_TAX * 100).toFixed(0) + '%';
    if (summaryVatValue) summaryVatValue.textContent = '+₱' + vatAmount.toLocaleString('en-PH', {minimumFractionDigits: 2});

    if (mpbDeliveryRow) mpbDeliveryRow.style.display = (selectedFulfillment === 'delivery') ? 'flex' : 'none';
    if (summaryDeliveryFee) summaryDeliveryFee.textContent = deliveryFee === 0 ? 'FREE' : ('₱' + deliveryFee.toLocaleString('en-PH', {minimumFractionDigits: 2}));

    if (mpbTotal) mpbTotal.textContent = '₱' + finalTotal.toLocaleString('en-PH', {minimumFractionDigits: 2});

    document.querySelectorAll('.pm-total-online, .pm-total-cod').forEach(el => {
        el.textContent = '₱' + finalTotal.toLocaleString('en-PH', {minimumFractionDigits: 2});
    });
}

// ============================================
// Fulfillment selectors
// ============================================
function selectFulfillment(type) {
    selectedFulfillment = type;

    document.querySelectorAll('.fulfillment-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.type === type);
    });

    const pickupFields = document.getElementById('pickupFields');
    const deliveryFields = document.getElementById('deliveryFields');

    if (type === 'delivery') {
        pickupFields.style.display = 'none';
        deliveryFields.style.display = 'block';
    } else {
        pickupFields.style.display = 'block';
        deliveryFields.style.display = 'none';
    }

    updatePriceDisplay();
}

function selectDeliveryPayment(method) {
    selectedDeliveryPayment = method;
    document.querySelectorAll('.payment-method-option').forEach(opt => {
        opt.classList.toggle('selected', opt.dataset.method === method);
    });
    updatePriceDisplay();
}

// ============================================
// ACTION BUTTON
// ============================================
function updateActionButton() {
    const btn = document.getElementById('mainActionBtn');
    const helper = document.getElementById('actionHelper');
    if (!btn) return;

    if (HAS_SIZES && !selectedSize && !IS_FULLY_SOLD_OUT && !IS_LENS_ONLY && !IS_CONTACT) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Please select a frame size to continue.';
        return;
    }

    if (HAS_STOCK_TRACKING && !IS_FULLY_SOLD_OUT && !selectedColor) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Please select a color/variant to continue.';
        return;
    }

    const isFrameOnly = (selectedLens === 'frame_only');
    if (isFrameOnly) {
        btn.innerHTML = '<i class="fas fa-bookmark"></i> Reserve — Frame Only';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> ' + DP_PERCENT + '% downpayment required.';
    } else if (!rxKnowledge) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Choose your prescription option above.';
    } else if (rxKnowledge === 'dont_know') {
        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> Book Eye Exam';
        btn.className = 'btn-main-action apt';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> You\'ll be taken to book an eye exam first.';
    } else {
        btn.innerHTML = '<i class="fas fa-bookmark"></i> Reserve with Prescription';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> ' + DP_PERCENT + '% downpayment required.';
    }
}

function handleMainAction() {
    if (HAS_SIZES && !selectedSize && !IS_FULLY_SOLD_OUT && !IS_LENS_ONLY && !IS_CONTACT) {
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
    if (isFrameOnly) { openModal('reservation'); return; }

    if (!rxKnowledge) {
        showToast('Please choose whether you know your prescription.', 'error');
        return;
    }

    if (rxKnowledge === 'dont_know') {
        // Redirect to book-specific-product.php
        const colorCode = selectedColor ? selectedColor.code : '';
        window.location.href = 'book-specific-product.php?clinic_id=' + <?php echo $clinic_id; ?>
            + '&product_id=' + <?php echo $product_id; ?>
            + '&rx_knowledge=dont_know'
            + '&lens=' + encodeURIComponent(selectedLens || '')
            + '&color=' + encodeURIComponent(colorCode);
        return;
    }

    const od_sph = document.getElementById('od_sph')?.value || '';
    const os_sph = document.getElementById('os_sph')?.value || '';
    if (!od_sph && !os_sph) {
        showToast('Please enter your prescription or select "I need an eye exam".', 'error');
        return;
    }

    // Redirect with prescription known
    const od_cyl  = document.getElementById('od_cyl')?.value  || '';
    const od_axis = document.getElementById('od_axis')?.value || '';
    const os_cyl  = document.getElementById('os_cyl')?.value  || '';
    const os_axis = document.getElementById('os_axis')?.value || '';
    const colorCode = selectedColor ? selectedColor.code : '';

    window.location.href = 'book-specific-product.php?clinic_id=' + <?php echo $clinic_id; ?>
        + '&product_id=' + <?php echo $product_id; ?>
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
    updatePriceDisplay();
    updateModalSummary();

    const title = document.getElementById('modalTitle');
    const confirmBtn = document.getElementById('confirmModalBtn');
    const priceBox = document.getElementById('modalPriceBox');
    const aptNotice = document.getElementById('modalAptNotice');
    const examNotice = document.getElementById('modalExamNotice');

    aptNotice.style.display = 'none';
    examNotice.style.display = 'none';

    // Show fulfillment selector only if item is deliverable
    const fulfillmentSection = document.getElementById('fulfillmentSection');
    const canDeliver = OFFERS_DELIVERY && (IS_ACCESSORY_CAT || (IS_FRAMES_FAMILY && selectedLens === 'frame_only'));
    if (fulfillmentSection) {
        fulfillmentSection.style.display = canDeliver ? 'block' : 'none';
    }
    if (canDeliver && ALLOW_COD) {
        const codOption = document.querySelector('.payment-method-option.cod-option');
        if (codOption) codOption.style.display = 'flex';
    }

    // Reset fulfillment
    selectFulfillment('pickup');

    if (type === 'appointment') {
        title.innerHTML = '<i class="fas fa-calendar-plus"></i> Book Appointment';
        confirmBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Book Appointment';
        confirmBtn.className = 'btn-modal confirm apt';
        priceBox.style.display = 'none';
        aptNotice.style.display = 'block';
        if (rxKnowledge === 'dont_know') examNotice.style.display = 'block';
    } else {
        title.innerHTML = '<i class="fas fa-bookmark"></i> Reserve This Product';
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Reservation';
        confirmBtn.className = 'btn-modal confirm';
        priceBox.style.display = 'block';
        aptNotice.style.display = 'none';
        if (rxKnowledge === 'dont_know') examNotice.style.display = 'block';
    }

    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('mDate').value = tomorrow.toISOString().split('T')[0];

    document.getElementById('scheduleModal').classList.add('show');
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
    const formData = new FormData();
    formData.append('ajax_action', currentModal === 'appointment' ? 'appointment' : 'reservation');
    formData.append('lens_type', selectedLens || 'frame_only');
    formData.append('prescription_knowledge', rxKnowledge || '');

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

    if (selectedFulfillment === 'delivery') {
        const name = document.getElementById('mDeliveryName')?.value?.trim() || '';
        const phone = document.getElementById('mDeliveryPhone')?.value?.trim() || '';
        const address = document.getElementById('mDeliveryAddress')?.value?.trim() || '';
        const brgy = document.getElementById('mDeliveryBarangay')?.value?.trim() || '';
        const city = document.getElementById('mDeliveryCity')?.value?.trim() || '';
        const province = document.getElementById('mDeliveryProvince')?.value?.trim() || '';
        const zip = document.getElementById('mDeliveryZip')?.value?.trim() || '';

        if (!name || !phone || !address || !brgy || !city || !province || !zip) {
            showToast('Please complete all required delivery fields.', 'error');
            return;
        }

        formData.append('fulfillment_type', 'delivery');
        formData.append('delivery_name', name);
        formData.append('delivery_phone', phone);
        formData.append('delivery_address', address);
        formData.append('delivery_barangay', brgy);
        formData.append('delivery_city', city);
        formData.append('delivery_province', province);
        formData.append('delivery_zip', zip);
        formData.append('delivery_landmark', document.getElementById('mDeliveryLandmark')?.value || '');
        formData.append('delivery_date', document.getElementById('mDeliveryDate')?.value || '');
        formData.append('delivery_payment_method', selectedDeliveryPayment);
        formData.append('preferred_date', document.getElementById('mDeliveryDate')?.value || '<?php echo date('Y-m-d'); ?>');
        formData.append('preferred_time', '09:00:00');
        formData.append('contact_number', phone);
    } else {
        const date = document.getElementById('mDate').value;
        const time = document.getElementById('mTime').value;
        const contact = document.getElementById('mContact').value;

        if (!date) { showToast('Please select a date.', 'error'); return; }
        if (!time) { showToast('Please select a time.', 'error'); return; }
        if (!contact) { showToast('Please enter your contact number.', 'error'); return; }

        formData.append('fulfillment_type', 'pickup');
        formData.append('preferred_date', date);
        formData.append('preferred_time', time);
        formData.append('contact_number', contact);
    }

    formData.append('notes', document.getElementById('mNotes')?.value || '');

    closeModal();
    showLoading();

    fetch('3d-view.php?id=<?php echo $product_id; ?>', { method:'POST', body:formData })
    .then(r => r.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => { window.location.href = data.redirect; }, 1500);
        } else if (data.redirect_to_booking && data.redirect) {
            // Redirect to book-specific-product.php for eye exam / prescription
            showToast(data.message, 'info');
            setTimeout(() => { window.location.href = data.redirect; }, 800);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(() => { hideLoading(); showToast('Network error. Please try again.', 'error'); });
}

// ============================================
// UTILITIES
// ============================================
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success:'check-circle', error:'exclamation-circle', info:'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type]||'info-circle'}"></i><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity 0.3s'; setTimeout(()=>t.remove(),300); }, 3500);
}

function showLoading() { document.getElementById('loadingOverlay').classList.remove('hidden'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.add('hidden'); }

document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });
document.getElementById('scheduleModal').addEventListener('click', e => { if (e.target===e.currentTarget) closeModal(); });

function toggleProductFav(btn) {
    const productId = btn.dataset.productId;
    const isActive  = btn.classList.contains('active');
    const formData  = new FormData();
    formData.append('product_id', productId);
    btn.classList.toggle('active');
    btn.classList.add('pop');
    const icon  = btn.querySelector('i');
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

// ============================================
// 3D VIEWER
// ============================================
let scene, camera, renderer, controls, model;
let autoRotate = false, wireframeMode = false;
let originalMaterials = [];
let originalLensColors = new Map();

document.addEventListener('DOMContentLoaded', function() {
    <?php if ($has_3d): ?>
    init3DViewer('<?php echo addslashes($model_file); ?>');
    <?php endif; ?>

    // ✅ Initialize button state + price display (same as product-view.php)
    updatePriceDisplay();
    updateActionButton();

    // ✅ Initialize rx section visibility
    const rxSection = document.getElementById('rxSection');
    if (rxSection) {
        if (IS_LENS_ONLY || IS_CONTACT) {
            rxSection.style.display = 'block';
        } else if (NEEDS_LENS && selectedLens !== 'frame_only' && selectedLens !== '') {
            rxSection.style.display = 'block';
        } else if (selectedLens === 'frame_only') {
            rxSection.style.display = 'none';
        }
    }
});

function isLensMaterial(node, material) {
    const materialName = (material.name || '').toLowerCase();
    const nodeName = (node.name || '').toLowerCase();
    const lensKeywords = ['lens', 'glass', 'clear', 'transparent', 'window', 'lense', 'optic', 'lenses'];
    for (let keyword of lensKeywords) {
        if (materialName.includes(keyword) || nodeName.includes(keyword)) {
            return true;
        }
    }
    if (material.transparent === true || material.opacity < 1) {
        return true;
    }
    return false;
}

function init3DViewer(modelPath) {
    const container = document.getElementById('viewer3D');
    if (!container) return;
    originalLensColors.clear();
    scene = new THREE.Scene();
    scene.background = new THREE.Color(0xFFFFFF);
    const w = container.clientWidth || 600;
    const h = container.clientHeight || 480;
    camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 1000);
    camera.position.set(3, 1.5, 4);
    renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(w, h);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer.domElement);
    controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.05;
    controls.autoRotate = false;
    controls.autoRotateSpeed = 2.0;
    controls.enableZoom = true;
    controls.enablePan = false;
    controls.target.set(0, 1.5, 0);
    const ambient = new THREE.AmbientLight(0xffffff, 0.6);
    scene.add(ambient);
    const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
    mainLight.position.set(2, 5, 3);
    mainLight.castShadow = true;
    scene.add(mainLight);
    const fillLight = new THREE.DirectionalLight(0xffddbb, 0.8);
    fillLight.position.set(-2, 2, 2);
    scene.add(fillLight);
    const backLight = new THREE.PointLight(0x88aaff, 0.6);
    backLight.position.set(0, 2, -3);
    scene.add(backLight);
    const loader = new THREE.GLTFLoader();
    const fullPath = '/' + modelPath;
    loader.load(fullPath, function(gltf) {
        model = gltf.scene;
        model.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                materials.forEach((mat, idx) => {
                    if (isLensMaterial(node, mat) && mat.color) {
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        originalLensColors.set(key, {
                            color: mat.color.getHex(),
                            transparent: mat.transparent || false,
                            opacity: mat.opacity !== undefined ? mat.opacity : 1
                        });
                    }
                });
            }
        });
        const box = new THREE.Box3().setFromObject(model);
        const size = box.getSize(new THREE.Vector3());
        const maxDim = Math.max(size.x, size.y, size.z);
        const scale = 2.5 / maxDim;
        model.scale.set(scale, scale, scale);
        model.position.set(0, 1.5, 0);
        model.traverse(node => {
            if (node.isMesh) {
                node.castShadow = true;
                node.receiveShadow = true;
                if (node.material) {
                    const mats = Array.isArray(node.material) ? node.material : [node.material];
                    mats.forEach((mat, idx) => {
                        if (mat && mat.color) {
                            originalMaterials.push({ node, idx: Array.isArray(node.material) ? idx : -1, color: mat.color.clone() });
                        }
                    });
                }
            }
        });
        scene.add(model);
        showToast('3D model loaded!', 'success');
        if (originalLensColors.size === 0) {
            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = `
                position: absolute;
                bottom: 60px;
                left: 16px;
                right: 16px;
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 11px;
                text-align: center;
                z-index: 100;
            `;
            warningDiv.innerHTML = `
                <i class="fas fa-info-circle"></i>
                <strong>Note:</strong> No lens material detected. The entire frame (including lens area) will change color.
            `;
            const viewerCanvas = document.querySelector('.viewer-canvas');
            if (viewerCanvas) viewerCanvas.style.position = 'relative';
            if (viewerCanvas && !viewerCanvas.querySelector('.lens-warning')) {
                warningDiv.classList.add('lens-warning');
                viewerCanvas.appendChild(warningDiv);
                setTimeout(() => warningDiv.remove(), 5000);
            }
        }
    }, null, function(err) { console.error('3D load error:', err); showToast('Failed to load 3D model.', 'error'); });
    function animate() {
        requestAnimationFrame(animate);
        if (controls) { controls.autoRotate = autoRotate; controls.update(); }
        if (renderer && scene && camera) renderer.render(scene, camera);
    }
    animate();
    window.addEventListener('resize', () => {
        const w2 = container.clientWidth;
        const h2 = container.clientHeight;
        if (camera && renderer && w2 && h2) {
            camera.aspect = w2 / h2;
            camera.updateProjectionMatrix();
            renderer.setSize(w2, h2);
        }
    });
}

function resetView() {
    if (camera && controls) { camera.position.set(3, 1.5, 4); controls.target.set(0, 1.5, 0); controls.update(); }
    showToast('View reset', 'info');
}

function toggleAutoRotate() {
    autoRotate = !autoRotate;
    document.getElementById('rotateBtn').classList.toggle('active', autoRotate);
    showToast(autoRotate ? 'Auto-rotate on' : 'Auto-rotate off', 'info');
}

function toggleWireframe() {
    wireframeMode = !wireframeMode;
    document.getElementById('wireBtn').classList.toggle('active', wireframeMode);
    if (model) {
        model.traverse(node => {
            if (node.isMesh) {
                const mats = Array.isArray(node.material) ? node.material : [node.material];
                mats.forEach(mat => { if (mat) mat.wireframe = wireframeMode; });
            }
        });
    }
    showToast(wireframeMode ? 'Wireframe on' : 'Wireframe off', 'info');
}

function zoomIn() { if (camera) camera.position.multiplyScalar(0.9); }
function zoomOut() { if (camera) camera.position.multiplyScalar(1.1); }

function changeColor(colorCode, el) {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
    if (el) el.classList.add('active');
    if (!model) return;
    model.traverse(node => {
        if (node.isMesh && node.material) {
            const materials = Array.isArray(node.material) ? node.material : [node.material];
            materials.forEach((mat, idx) => {
                if (mat && mat.color) {
                    const isLens = isLensMaterial(node, mat);
                    const key = `${node.uuid}_${mat.uuid}_${idx}`;
                    if (!isLens) {
                        mat.color.set(colorCode);
                        const darkColors = ['#000000', '#2C2C2C', '#111111', '#2C3539', '#000080', '#800000'];
                        mat.emissiveIntensity = darkColors.includes(colorCode.toLowerCase()) ? 0.1 : 0;
                    } else if (originalLensColors.has(key)) {
                        const original = originalLensColors.get(key);
                        mat.color.setHex(original.color);
                        mat.transparent = original.transparent;
                        mat.opacity = original.opacity;
                    }
                }
            });
        }
    });
    showToast('Color changed', 'info');
}

function resetColor() {
    document.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
    if (!model) return;
    let idx = 0;
    model.traverse(node => {
        if (node.isMesh && node.material) {
            const materials = Array.isArray(node.material) ? node.material : [node.material];
            materials.forEach((mat, matIdx) => {
                if (mat && mat.color && originalMaterials[idx]) {
                    const isLens = isLensMaterial(node, mat);
                    if (!isLens) {
                        mat.color.copy(originalMaterials[idx].color);
                    } else if (originalLensColors.has(`${node.uuid}_${mat.uuid}_${matIdx}`)) {
                        const original = originalLensColors.get(`${node.uuid}_${mat.uuid}_${matIdx}`);
                        mat.color.setHex(original.color);
                        mat.transparent = original.transparent;
                        mat.opacity = original.opacity;
                    }
                }
                idx++;
            });
        }
    });
    showToast('Original colors restored', 'info');
    }
    
</script>
</body>
</html>