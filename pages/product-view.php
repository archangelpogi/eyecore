<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

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

// ============ USER + NAVBAR COUNTS ============
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$pending = (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'"
))['total']) ?? 0;
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);
$sale_count = (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()"
))['total']) ?? 0;
$total_points = (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id"
))['total_points']) ?: 0;
$total_bookings = (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id"
))['total']) ?: 0;
$reservation_count = (mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')"
))['total']) ?? 0;

// ============ PRODUCT + CLINIC ============
$product_query = mysqli_query($conn, "
    SELECT p.*, c.id as clinic_id, c.name as clinic_name, c.address as clinic_address,
           c.city as clinic_city, c.contact as clinic_contact, c.hours as clinic_hours,
           c.logo as clinic_logo, c.offers_delivery, c.allow_cod,
           c.delivery_fee as clinic_delivery_fee, c.delivery_radius_km, c.free_delivery_minimum
    FROM products p JOIN clinics c ON p.clinic_id = c.id WHERE p.id = $product_id
");
if (mysqli_num_rows($product_query) == 0) { header('Location: dashboard.php'); exit(); }
$product = mysqli_fetch_assoc($product_query);
$category = $product['category'];
$clinic_id = $product['clinic_id'];

// ============ LENS INDEX ADD-ONS (DB-driven) ============
$lens_index_addons = [];
$li_extended = @mysqli_query($conn, "
    SELECT id, lens_type, index_value, label, price,
           min_sph, max_sph, min_cyl, max_cyl, stock_qty
    FROM lens_index_addons
    WHERE clinic_id = $clinic_id AND is_active = 1
    ORDER BY lens_type, CAST(index_value AS DECIMAL(4,2)) ASC
");
if (!$li_extended) {
    // Fallback: basic columns lang (kung hindi pa na-add ng admin ang min/max)
    $li_extended = @mysqli_query($conn, "
        SELECT id, lens_type, index_value, label, price
        FROM lens_index_addons
        WHERE clinic_id = $clinic_id AND is_active = 1
        ORDER BY lens_type, CAST(index_value AS DECIMAL(4,2)) ASC
    ");
}
if ($li_extended) {
    while ($row = mysqli_fetch_assoc($li_extended)) {
        $lens_index_addons[] = [
            'id'          => (int)$row['id'],
            'lens_type'   => $row['lens_type'],
            'index_value' => $row['index_value'],
            'label'       => $row['label'],
            'price'       => (float)$row['price'],
            'min_sph'     => isset($row['min_sph']) ? (float)$row['min_sph'] : null,
            'max_sph'     => isset($row['max_sph']) ? (float)$row['max_sph'] : null,
            'min_cyl'     => isset($row['min_cyl']) ? (float)$row['min_cyl'] : null,
            'max_cyl'     => isset($row['max_cyl']) ? (float)$row['max_cyl'] : null,
            'stock_qty'   => isset($row['stock_qty']) ? (int)$row['stock_qty'] : null,
        ];
    }
}

// ============ LENS COATINGS (DB-driven) ============
$lens_coatings = [];
$coatings_query = @mysqli_query($conn, "
    SELECT id, code, name, description, price, applies_to
    FROM lens_coatings
    WHERE clinic_id = $clinic_id AND is_active = 1
    ORDER BY price ASC
");
if ($coatings_query) {
    while ($c = mysqli_fetch_assoc($coatings_query)) {
        $applies = json_decode($c['applies_to'] ?? '[]', true);
        $lens_coatings[] = [
            'id'          => (int)$c['id'],
            'code'        => $c['code'],
            'name'        => $c['name'],
            'description' => $c['description'] ?? '',
            'price'       => (float)$c['price'],
            'applies_to'  => is_array($applies) ? $applies : [],
        ];
    }
}

// ============ DELIVERY ============
$offers_delivery = !empty($product['offers_delivery']);
$allow_cod = !empty($product['allow_cod']);
$default_delivery_fee = (float)($product['clinic_delivery_fee'] ?? 0);
$free_delivery_min = (float)($product['free_delivery_minimum'] ?? 0);

$delivery_fees_list = [];
if ($offers_delivery) {
    $df_query = mysqli_query($conn,
        "SELECT id, region, city, barangay, fee_amount, estimated_days
         FROM delivery_fees WHERE clinic_id = $clinic_id AND is_active = 1 ORDER BY city ASC");
    while ($df = mysqli_fetch_assoc($df_query)) {
        $delivery_fees_list[] = [
            'id' => (int)$df['id'], 'region' => $df['region'], 'city' => $df['city'],
            'barangay' => $df['barangay'], 'fee_amount' => (float)$df['fee_amount'],
            'estimated_days' => (int)$df['estimated_days'],
        ];
    }
}

$user_default_address = $user['delivery_address'] ?? '';
$user_default_contact = $user['contact'] ?? '';
$user_default_barangay = $user['delivery_barangay'] ?? '';
$user_default_city = $user['delivery_city'] ?? '';
$user_default_province = $user['delivery_province'] ?? '';
$user_default_zip = $user['delivery_zip'] ?? '';

// ============ EXTRA FIELDS / SIZES / SPECS ============
$extra_fields = [];
if (!empty($product['extra_fields_json'])) {
    $decoded = json_decode($product['extra_fields_json'], true);
    if (is_array($decoded)) $extra_fields = $decoded;
}
$available_sizes = [];
if (!empty($extra_fields['sizes_available']) && is_array($extra_fields['sizes_available'])) {
    $available_sizes = $extra_fields['sizes_available'];
}

$possible_specs = [];
if (in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses'])) {
    $possible_specs = ['frame_material'=>'Frame Material','frame_style'=>'Frame Shape','frame_type'=>'Frame Type','gender'=>'Gender','weight_group'=>'Weight','collection'=>'Collection','model_no'=>'Model No.','lens_width_mm'=>'Lens Width (mm)','bridge_width_mm'=>'Bridge Width (mm)','temple_length_mm'=>'Temple Length (mm)'];
} elseif ($category == 'Contact Lenses') {
    $possible_specs = ['cl_type'=>'Wear Type','cl_material'=>'Material','cl_color_type'=>'Color Type','cl_water_content'=>'Water Content (%)','cl_base_curve'=>'Base Curve (mm)','cl_diameter'=>'Diameter (mm)','cl_pieces_per_box'=>'Pieces per Box','cl_power_range'=>'Power Range'];
} elseif ($category == 'Lenses') {
    $possible_specs = ['lens_type'=>'Lens Type','lens_material'=>'Material','lens_coating'=>'Coating','lens_index'=>'Refractive Index','lens_diameter'=>'Diameter (mm)','lens_base_curve'=>'Base Curve','lens_sphere_range'=>'Sphere Range','lens_cylinder_range'=>'Cylinder Range','lens_add_range'=>'Add Range'];
} elseif ($category == 'Accessories') {
    $possible_specs = ['accessory_type'=>'Accessory Type','accessory_material'=>'Material','solution_type'=>'Solution Type','solution_volume'=>'Volume (ml)','part_compatibility'=>'Compatibility'];
}
$spec_fields = [];
foreach ($possible_specs as $key => $label) {
    if (!empty($extra_fields[$key])) $spec_fields[$key] = $label;
}

// ============ WARRANTY ============
$warranty_period = $product['warranty_period'] ?? '';
$warranty_coverage = [];
if (!empty($product['warranty_coverage'])) {
    $decoded = json_decode($product['warranty_coverage'], true);
    if (is_array($decoded)) $warranty_coverage = $decoded;
}
$warranty_exclusions = $product['warranty_exclusions'] ?? '';
$warranty_terms = $product['warranty_terms'] ?? '';
$warranty_claim_process = $product['warranty_claim_process'] ?? '';
$warranty_care = $product['warranty_care'] ?? '';
$warranty_premium = floatval($product['warranty_premium_price'] ?? 0);
$period_labels = ['3_months'=>'3 Months','6_months'=>'6 Months','12_months'=>'12 Months','24_months'=>'24 Months','36_months'=>'36 Months'];
$warranty_period_display = $period_labels[$warranty_period] ?? ($warranty_period == 'no_warranty' ? 'No Warranty' : '');
$has_warranty = ($warranty_period && $warranty_period != 'no_warranty');

// ============ CLINIC PAYMENT POLICY ============
$clinic_payment_config = getClinicPaymentPolicy($conn, $clinic_id);
$clinic_payment_policy = $clinic_payment_config['payment_policy'];
$clinic_downpayment_percent = (float)($clinic_payment_config['downpayment_percentage'] ?? 30);
$clinic_booking_flow = $clinic_payment_config['booking_flow'] ?? 'approve_first';

// ============ SALE DETECTION ============
$is_on_sale = !empty($product['is_on_sale']) && $product['is_on_sale'] == 1
    && !empty($product['sale_price']) && $product['sale_price'] > 0
    && !empty($product['sale_end']) && strtotime($product['sale_end']) >= strtotime('today');

$sale_price = $is_on_sale ? (float)$product['sale_price'] : 0;
$original_price = (float)$product['price'];
$display_price = $is_on_sale ? $sale_price : $original_price;
$discount_percent = $is_on_sale ? round((($original_price - $sale_price) / $original_price) * 100) : 0;
$days_left = $is_on_sale ? (int)ceil((strtotime($product['sale_end']) - strtotime('today')) / 86400) : 0;
$savings = $is_on_sale ? ($original_price - $sale_price) : 0;

// ============ PAYMENT ============
$payment_info = calculatePaymentAmounts($conn, $clinic_id, $display_price);
$subtotal_calculated = $payment_info['subtotal'];
$total_amount_calculated = $payment_info['total_amount'];
$requires_downpayment = $payment_info['requires_payment'];
$payment_type_label = $payment_info['payment_type'];
$downpayment_percent = 0; $downpayment_amount_calculated = 0; $balance_amount_calculated = 0;

if ($payment_type_label == 'downpayment') {
    $downpayment_percent = $clinic_downpayment_percent;
    $downpayment_amount_calculated = $payment_info['downpayment_amount'];
    $balance_amount_calculated = $payment_info['balance_amount'];
} elseif ($payment_type_label == 'full') {
    $downpayment_percent = 100;
    $downpayment_amount_calculated = $payment_info['downpayment_amount'];
} elseif ($payment_type_label == 'onsite') {
    $balance_amount_calculated = $total_amount_calculated;
}

$policy_labels = ['full_payment'=>'100% Full Payment','downpayment_30'=>'30% Downpayment','downpayment_custom'=>$clinic_downpayment_percent.'% Downpayment','pay_on_site'=>'Pay On-Site Only','no_payment'=>'Free Service'];
$clinic_payment_policy_display = $policy_labels[$clinic_payment_policy] ?? 'Standard Payment';

// ============ CATEGORY ============
$NEEDS_LENS_SELECTION = in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses', 'Lenses', 'Contact Lenses']);
$IS_ACCESSORY = in_array($category, ['Accessories', 'Parts', 'Cleaning Kits']);
$IS_SERVICE = in_array($category, ['Service', 'Eye Exam', 'Treatment', 'Screening']);
$IS_CONTACT_LENS = ($category === 'Contact Lenses');
$IS_LENS_ONLY = ($category === 'Lenses');

// ============ COLORS ============
$colors_query = mysqli_query($conn, "
    SELECT color_code, color_name, quantity FROM product_color_inventory
    WHERE product_id = $product_id AND clinic_id = $clinic_id AND is_available = 1 ORDER BY color_name");
$product_colors = [];
$total_available_stock = 0;
while ($c = mysqli_fetch_assoc($colors_query)) {
    $product_colors[] = ['code'=>$c['color_code'],'name'=>$c['color_name'],'quantity'=>(int)$c['quantity']];
    if ((int)$c['quantity'] > 0) $total_available_stock += (int)$c['quantity'];
}
$has_colors = !empty($product_colors);
$is_fully_sold_out = $has_colors && $total_available_stock === 0;
$has_stock_tracking = $has_colors;

// ============ IMAGES ============
function getProductImages($product) {
    $images = [];
    if (!empty($product['images_json'])) {
        $imgs = json_decode($product['images_json'], true);
        if (!empty($imgs) && is_array($imgs)) {
            foreach ($imgs as $img) {
                $p = str_replace('uploads/uploads/', 'uploads/', $img);
                $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
            }
        }
    }
    if (empty($images) && !empty($product['images'])) {
        $imgData = $product['images'];
        if (strpos($imgData, '[') === 0) {
            $imgs = json_decode($imgData, true);
            if (!empty($imgs) && is_array($imgs)) {
                foreach ($imgs as $img) {
                    $p = str_replace('uploads/uploads/', 'uploads/', $img);
                    $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
                }
            }
        } else {
            $p = str_replace('uploads/uploads/', 'uploads/', $imgData);
            $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    if (empty($images) && !empty($product['image'])) {
        if (strpos($product['image'], 'uploads/') === false && strpos($product['image'], '/') === false) {
            $images[] = '/assets/images/products/' . $product['image'];
        } else {
            $p = str_replace('uploads/uploads/', 'uploads/', $product['image']);
            $images[] = strpos($p, 'uploads/') === 0 ? '/' . $p : '/uploads/products/' . $p;
        }
    }
    if (empty($images)) $images[] = '/assets/img/no-image.png';
    return $images;
}
$product_images = getProductImages($product);
$has_multiple_images = count($product_images) > 1;

// ============ 3D ============
$has_3d = false;
if (!empty($product['inventory_id'])) {
    $r3d = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT completed_model_file FROM custom_3d_requests
         WHERE inventory_id = {$product['inventory_id']} AND status = 'completed'
         AND completed_model_file IS NOT NULL ORDER BY completed_at DESC LIMIT 1"));
    if ($r3d && !empty($r3d['completed_model_file'])) $has_3d = true;
}
if (!$has_3d) {
    $p3d = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT has_3d, model_file FROM product_3d_models
         WHERE product_id = $product_id AND has_3d = 1 AND model_file IS NOT NULL LIMIT 1"));
    if ($p3d && $p3d['has_3d'] == 1 && !empty($p3d['model_file'])) $has_3d = true;
}

// ============ EXISTING ============
$existing_reservation = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, reservation_code FROM reservations
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending', 'confirmed') LIMIT 1")) ?? null;

$fav_check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
$is_product_favorited = mysqli_num_rows($fav_check) > 0;

$existing_appointment = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, status, ref_no FROM appointments
     WHERE user_id = $user_id AND product_id = $product_id
     AND status IN ('pending', 'confirmed') LIMIT 1")) ?? null;

$similar_query = mysqli_query($conn, "
    SELECT p.* FROM products p
    WHERE p.clinic_id = $clinic_id AND p.id != $product_id
    AND p.category = '" . mysqli_real_escape_string($conn, $category) . "' LIMIT 4");

// ============================================
// AJAX HANDLER
// ============================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    $action = mysqli_real_escape_string($conn, $_POST['ajax_action']);
    $lens_type = mysqli_real_escape_string($conn, $_POST['lens_type'] ?? 'frame_only');
    $lens_index = mysqli_real_escape_string($conn, $_POST['lens_index'] ?? '');
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
    $delivery_payment_method = mysqli_real_escape_string($conn, $_POST['delivery_payment_method'] ?? 'online');
    $fulfillment_type = mysqli_real_escape_string($conn, $_POST['fulfillment_type'] ?? 'pickup');

    // Coatings: validate sa DB, i-append sa notes
    $coatings_input = $_POST['coatings'] ?? '[]';
    $coatings_arr = json_decode($coatings_input, true);
    $coatings_total_price = 0;
    if (is_array($coatings_arr) && !empty($coatings_arr)) {
        $coating_ids = array_filter(array_map('intval', $coatings_arr));
        if (!empty($coating_ids)) {
            $id_list = implode(',', $coating_ids);
            $names_res = mysqli_query($conn, "
                SELECT name, price FROM lens_coatings
                WHERE id IN ($id_list) AND clinic_id = $clinic_id AND is_active = 1");
            $coating_names = [];
            while ($row = mysqli_fetch_assoc($names_res)) {
                $coating_names[] = $row['name'];
                $coatings_total_price += (float)$row['price'];
            }
            if (!empty($coating_names)) {
                $notes = ($notes ? $notes . ' | ' : '') . 'Coatings: ' . implode(', ', $coating_names);
            }
        }
    }

    if ($fulfillment_type === 'pickup' && $delivery_payment_method === 'cod') {
        $delivery_payment_method = 'online';
    } elseif ($fulfillment_type === 'delivery' && $delivery_payment_method === 'pay_at_clinic') {
        $delivery_payment_method = 'online';
    }
    $delivery_name = mysqli_real_escape_string($conn, $_POST['delivery_name'] ?? '');
    $delivery_phone = mysqli_real_escape_string($conn, $_POST['delivery_phone'] ?? '');
    $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');
    $delivery_barangay = mysqli_real_escape_string($conn, $_POST['delivery_barangay'] ?? '');
    $delivery_city = mysqli_real_escape_string($conn, $_POST['delivery_city'] ?? '');
    $delivery_province = mysqli_real_escape_string($conn, $_POST['delivery_province'] ?? '');
    $delivery_zip = mysqli_real_escape_string($conn, $_POST['delivery_zip'] ?? '');
    $delivery_landmark = mysqli_real_escape_string($conn, $_POST['delivery_landmark'] ?? '');

    if ($fulfillment_type === 'delivery' && !$IS_SERVICE) {
        if (!$delivery_name) { echo json_encode(['success'=>false,'message'=>'Please enter the recipient name.']); exit(); }
        if (!$delivery_phone) { echo json_encode(['success'=>false,'message'=>'Please enter the recipient phone.']); exit(); }
        if (!$delivery_address) { echo json_encode(['success'=>false,'message'=>'Please enter the delivery address.']); exit(); }
        if (!$delivery_city) { echo json_encode(['success'=>false,'message'=>'Please select a city.']); exit(); }
        if ($delivery_payment_method === 'cod' && !$allow_cod) {
            echo json_encode(['success'=>false,'message'=>'Cash on Delivery is not available for this clinic.']); exit();
        }
        $preferred_date = date('Y-m-d');
        $preferred_time = date('H:i:s');
    } else {
        if (!$preferred_date || !$preferred_time) { echo json_encode(['success'=>false,'message'=>'Please select a date and time.']); exit(); }
        if (strtotime($preferred_date) < strtotime('today')) { echo json_encode(['success'=>false,'message'=>'Date must be today or in the future.']); exit(); }
    }

    $make_appointment = false;
    if ($IS_SERVICE) $make_appointment = true;
    elseif ($IS_ACCESSORY) $make_appointment = false;
    elseif ($NEEDS_LENS_SELECTION) {
        if ($lens_type === 'frame_only') $make_appointment = false;
        else $make_appointment = ($prescription_knowledge === 'dont_know');
    }

    $prescription_id = null;
    if (!$make_appointment && $prescription_knowledge === 'know' && ($od_sph || $os_sph)) {
        $insert_pres = mysqli_query($conn, "
            INSERT INTO user_prescriptions
            (user_id, product_id, od_sph, od_cyl, od_axis, os_sph, os_cyl, os_axis, prescription_source)
            VALUES ($user_id, $product_id, '$od_sph', '$od_cyl', '$od_axis', '$os_sph', '$os_cyl', '$os_axis', 'user_input')");
        if ($insert_pres) $prescription_id = mysqli_insert_id($conn);
    }

    if ($make_appointment) {
        $dup_apt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM appointments WHERE user_id = $user_id AND product_id = $product_id AND status IN ('pending','confirmed') LIMIT 1"));
        if ($dup_apt) { echo json_encode(['success'=>false,'message'=>'You already have an active appointment for this product.']); exit(); }

        $conflict = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT a.*, c.name as clinic_name FROM appointments a JOIN clinics c ON a.clinic_id = c.id
             WHERE a.user_id = $user_id AND a.appointment_date = '$preferred_date'
             AND a.appointment_time = '$preferred_time' AND a.status != 'cancelled'"));
        if ($conflict) { echo json_encode(['success'=>false,'message'=>'You already have an appointment at ' . $conflict['clinic_name'] . ' at that time.']); exit(); }

        $ref_no = 'APP-' . strtoupper(substr(uniqid(), -8));
        $notes_final = $notes ?: ($IS_SERVICE ? 'Service booking' : 'Needs eye exam before lens fitting');
        $item_type_db = $IS_SERVICE ? 'service' : 'product';

        mysqli_query($conn, "
            INSERT INTO appointments
            (ref_no, clinic_id, item_id, item_type, user_id, product_id,
             appointment_date, appointment_time, status, payment_status,
             appointment_type, total_amount, downpayment_amount, balance_amount,
             notes, contact_number, lens_type, lens_index)
            VALUES
            ('$ref_no', $clinic_id, $product_id, '$item_type_db', $user_id, $product_id,
             '$preferred_date', '$preferred_time', 'pending', 'pending',
             'online', {$display_price}, 0, {$display_price},
             '$notes_final', '$contact_number', '$lens_type', '$lens_index')");

        $appointment_id = mysqli_insert_id($conn);
        if ($prescription_id) {
            mysqli_query($conn, "UPDATE user_prescriptions SET appointment_id = $appointment_id WHERE id = $prescription_id");
        }
        addNotification($user_id, 'appointment', 'Appointment Created',
            "Your appointment at {$product['clinic_name']} is scheduled for $preferred_date at $preferred_time.",
            'my-appointments.php');

        echo json_encode(['success'=>true,'type'=>'appointment','message'=>'Appointment booked! The clinic will confirm your schedule.','redirect'=>'my-appointments.php']);
        exit();
    }

    // RESERVATION
    $dup_res = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM reservations WHERE user_id = $user_id AND product_id = $product_id AND status IN ('pending', 'confirmed') LIMIT 1"));
    if ($dup_res) { echo json_encode(['success'=>false,'message'=>'You already have an active order for this product.']); exit(); }

    $lens_prices = ['frame_only'=>0,'single_vision'=>500,'progressive'=>1500,'blue_cut'=>800,'contact_daily'=>0,'contact_monthly'=>0];
    $lens_price_add = $lens_prices[$lens_type] ?? 0;

    // Lens index price — LAGING galing DB (hindi POST)
    if (!empty($lens_index)) {
        $li_row = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT price FROM lens_index_addons
            WHERE clinic_id = $clinic_id
              AND lens_type = '" . mysqli_real_escape_string($conn, $lens_type) . "'
              AND index_value = '" . mysqli_real_escape_string($conn, $lens_index) . "'
              AND is_active = 1 LIMIT 1"));
        if ($li_row) $lens_price_add += (float)$li_row['price'];
    }
    // Coatings — galing DB (validated na sa taas)
    $lens_price_add += $coatings_total_price;

    $delivery_fee_final = 0; $estimated_days = 0;
    if ($fulfillment_type === 'delivery' && $offers_delivery) {
        $fee_row = null;
        if ($delivery_city) {
            $safe_city = mysqli_real_escape_string($conn, $delivery_city);
            $fee_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT fee_amount, estimated_days FROM delivery_fees
                 WHERE clinic_id = $clinic_id AND city = '$safe_city' AND is_active = 1 LIMIT 1"));
        }
        if ($fee_row) {
            $delivery_fee_final = (float)$fee_row['fee_amount'];
            $estimated_days = (int)$fee_row['estimated_days'];
        } else {
            $delivery_fee_final = $default_delivery_fee;
            $estimated_days = 2;
        }
        $subtotal_before_free = $display_price + $lens_price_add;
        if ($free_delivery_min > 0 && $subtotal_before_free >= $free_delivery_min) $delivery_fee_final = 0;
    }

    // SALE PRICE FIX: gamitin ang $display_price
    $subtotal_amount = $display_price + $lens_price_add + $delivery_fee_final;

    $is_pwd_senior = false;
    if (function_exists('getPwdSeniorDiscountStatus')) {
        $pwd_status = getPwdSeniorDiscountStatus($conn, $user_id, $clinic_id);
        $is_pwd_senior = !empty($pwd_status['eligible']);
    }

    $payment_info = calculatePaymentAmounts($conn, $clinic_id, $subtotal_amount, $is_pwd_senior);
    $subtotal_amount = $payment_info['subtotal'];
    $total_amount = $payment_info['total_amount'];
    $downpayment_amount = $payment_info['downpayment_amount'];
    $balance_amount = $payment_info['balance_amount'];
    $requires_payment = $payment_info['requires_payment'];
    $payment_policy = $payment_info['policy'];
    $payment_type = $payment_info['payment_type'];

    $reservation_code = 'RES-' . strtoupper(substr(uniqid(), -8));
    $is_cod_order = ($fulfillment_type === 'delivery' && $delivery_payment_method === 'cod' && $allow_cod);
    $is_pay_at_clinic = ($fulfillment_type === 'pickup' && $delivery_payment_method === 'pay_at_clinic');

    if ($is_cod_order) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $payment_status = 'cod'; $reservation_status = 'confirmed';
    } elseif ($is_pay_at_clinic) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $payment_status = 'onsite'; $reservation_status = 'confirmed';
    } elseif ($requires_payment) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $payment_status = 'unpaid'; $reservation_status = 'pending';
    } else {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $payment_status = ($payment_type == 'onsite') ? 'onsite' : 'free';
        $reservation_status = 'confirmed';
    }

    $sql_null = function($v) { return $v ? "'" . $v . "'" : 'NULL'; };
    $delivery_name_sql = $sql_null($delivery_name);
    $delivery_phone_sql = $sql_null($delivery_phone);
    $delivery_address_sql = $sql_null($delivery_address);
    $delivery_barangay_sql = $sql_null($delivery_barangay);
    $delivery_city_sql = $sql_null($delivery_city);
    $delivery_province_sql = $sql_null($delivery_province);
    $delivery_zip_sql = $sql_null($delivery_zip);
    $delivery_landmark_sql = $sql_null($delivery_landmark);
    $frame_size_sql = $sql_null($frame_size);

    $delivery_date_sql = 'NULL';
    if ($fulfillment_type === 'delivery' && $estimated_days > 0) {
        $delivery_date_sql = "DATE_ADD(CURDATE(), INTERVAL $estimated_days DAY)";
    }

    // Dete kung may frame_size column
    $has_frame_size_col = false;
    $fs_check = @mysqli_query($conn, "SHOW COLUMNS FROM reservations LIKE 'frame_size'");
    if ($fs_check && mysqli_num_rows($fs_check) > 0) $has_frame_size_col = true;

    $cols = "reservation_code, user_id, product_id, clinic_id, lens_type, lens_index, prescription_id, color_code, color_name, ";
    $vals = "'$reservation_code', $user_id, $product_id, $clinic_id, '$lens_type', '$lens_index', " . ($prescription_id ? $prescription_id : 'NULL') . ", '$color_code', '$color_name', ";
    if ($has_frame_size_col) { $cols .= "frame_size, "; $vals .= "$frame_size_sql, "; }
    $cols .= "total_amount, downpayment_amount, balance_amount, preferred_date, preferred_time, notes, status, payment_status, expires_at, created_at, fulfillment_type, delivery_name, delivery_phone, delivery_address, delivery_barangay, delivery_city, delivery_province, delivery_zip, delivery_landmark, delivery_fee, delivery_status, delivery_date";
    $vals .= "$total_amount, $downpayment_amount, $balance_amount, '$preferred_date', '$preferred_time', '$notes', '$reservation_status', '$payment_status', '$expires_at', NOW(), '$fulfillment_type', $delivery_name_sql, $delivery_phone_sql, $delivery_address_sql, $delivery_barangay_sql, $delivery_city_sql, $delivery_province_sql, $delivery_zip_sql, $delivery_landmark_sql, $delivery_fee_final, 'pending', $delivery_date_sql";

    $insert_query = "INSERT INTO reservations ($cols) VALUES ($vals)";
    $result = mysqli_query($conn, $insert_query);

    if (!$result) {
        error_log("Reservation INSERT failed: " . mysqli_error($conn));
        echo json_encode(['success'=>false,'message'=>'Failed to create reservation. Please try again.']);
        exit();
    }

    $reservation_id = mysqli_insert_id($conn);
    if (!$reservation_id) { echo json_encode(['success'=>false,'message'=>'Database configuration error.']); exit(); }

    if ($fulfillment_type === 'delivery' && !empty($delivery_address)) {
        $esc = fn($v) => mysqli_real_escape_string($conn, $v);
        mysqli_query($conn, "UPDATE users SET
            delivery_address = '{$esc($delivery_address)}', delivery_barangay = '{$esc($delivery_barangay)}',
            delivery_city = '{$esc($delivery_city)}', delivery_province = '{$esc($delivery_province)}',
            delivery_zip = '{$esc($delivery_zip)}', contact = '{$esc($contact_number)}'
            WHERE id = $user_id");
    }

    if ($prescription_id) {
        mysqli_query($conn, "UPDATE user_prescriptions SET reservation_id = $reservation_id WHERE id = $prescription_id");
    }
    if ($fulfillment_type === 'delivery') {
        mysqli_query($conn, "INSERT INTO delivery_status_logs
            (reservation_id, status, notes, updated_by, updated_by_type, created_at)
            VALUES ($reservation_id, 'pending', 'Delivery reservation created', $user_id, 'system', NOW())");
    }

    $fulfillment_label = ($fulfillment_type === 'delivery') ? 'delivery' : 'pickup';

    if ($is_cod_order) {
        $redirect_url = 'my-reservations.php';
        $message = 'Order placed! You will pay ₱' . number_format($total_amount, 2) . ' in cash upon ' . $fulfillment_label . '.';
        $notification_message = "Your COD order for {$product['name']} is confirmed. Pay ₱" . number_format($total_amount, 2) . " cash upon {$fulfillment_label}.";
    } elseif ($is_pay_at_clinic) {
        $redirect_url = 'my-reservations.php';
        $message = 'Order confirmed! Please pay ₱' . number_format($total_amount, 2) . ' at the clinic when you pick up your order.';
        $notification_message = "Your pickup order for {$product['name']} is confirmed. Pay ₱" . number_format($total_amount, 2) . " at the clinic.";
    } elseif ($requires_payment) {
        if ($clinic_booking_flow === 'pay_first') {
            $redirect_url = 'payment.php?reservation_id=' . $reservation_id;
            if ($payment_type == 'full') $message = 'Order created! Please proceed to full payment of ₱' . number_format($total_amount, 2) . '.';
            else {
                $dp = ($payment_policy == 'downpayment_custom') ? $clinic_downpayment_percent : 30;
                $message = 'Order created! Please pay ' . $dp . '% downpayment of ₱' . number_format($downpayment_amount, 2) . '.';
            }
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']} for {$fulfillment_label}. Please pay to confirm.";
        } else {
            $redirect_url = 'my-reservations.php';
            if ($payment_type == 'full') $message = 'Order created! Please wait for clinic approval before making full payment.';
            else {
                $dp = ($payment_policy == 'downpayment_custom') ? $clinic_downpayment_percent : 30;
                $message = 'Order created! Please wait for clinic approval before paying ' . $dp . '% downpayment.';
            }
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']} for {$fulfillment_label}. Please wait for clinic approval.";
        }
    } else {
        $redirect_url = 'my-reservations.php';
        if ($payment_type == 'onsite') {
            $message = 'Reservation confirmed! Please pay ₱' . number_format($total_amount, 2) . ' at the clinic.';
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']} for {$fulfillment_label}. Pay at the clinic.";
        } else {
            $message = 'Reservation confirmed! This is a free service. No payment required.';
            $notification_message = "You reserved {$product['name']} at {$product['clinic_name']} for {$fulfillment_label}.";
        }
    }
    if ($fulfillment_type === 'delivery' && $delivery_fee_final > 0) {
        $message .= ' Delivery fee: ₱' . number_format($delivery_fee_final, 2) . '.';
    }

    addNotification($user_id, 'reservation', 'Reservation Created', $notification_message, $redirect_url);

    echo json_encode([
        'success'=>true,'type'=>'reservation','message'=>$message,'redirect'=>$redirect_url,
        'reservation_id'=>$reservation_id,'total'=>$total_amount,'downpayment'=>$downpayment_amount,
        'balance'=>$balance_amount,'requires_payment'=>$requires_payment,'payment_type'=>$payment_type,
        'booking_flow'=>$clinic_booking_flow,'fulfillment_type'=>$fulfillment_type,
        'delivery_fee'=>$delivery_fee_final,'estimated_days'=>$estimated_days,
        'is_cod'=>$is_cod_order,'is_pay_at_clinic'=>$is_pay_at_clinic
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
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
html, body { width: 100%; overflow-x: hidden; }

:root {
    --primary:#00B761; --primary-dark:#008F4C; --primary-light:#E8FAF0;
    --primary-gradient: linear-gradient(135deg, #00B761, #00A86B);
    --bg-primary:#F4F6F9; --bg-secondary:#FFFFFF;
    --text-primary:#0D1117; --text-secondary:#5A6478; --text-muted:#9CA3AF;
    --border-color:#E5E7EB; --border-light:#F0F2F5;
    --shadow-sm: 0 1px 4px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
    --radius-sm:10px; --radius-md:16px; --radius-lg:24px; --radius-full:999px;
    --danger:#EF4444; --warning:#F59E0B; --success:#00B761; --info:#3B82F6;
    --font-main:'DM Sans', sans-serif; --font-display:'DM Serif Display', serif;
}
.theme-dark {
    --bg-primary:#0D0F14; --bg-secondary:#161B25;
    --text-primary:#F0F4FF; --text-secondary:#8892A4; --text-muted:#4B5563;
    --border-color:#252D3D; --border-light:#1C2235;
    --shadow-sm: 0 1px 4px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --primary-light:#0A2018;
}
body { font-family: var(--font-main); background: var(--bg-primary); color: var(--text-primary); transition: background 0.3s, color 0.3s; }
.main-content { max-width: 1300px; margin: 0 auto; padding: 28px 20px 80px; }
@media (min-width: 1024px) { .main-content { padding: 32px 40px 60px; } }
@media (max-width: 768px) { .main-content { padding: 16px 14px 100px; } }

.breadcrumb { display:flex; align-items:center; gap:8px; margin-bottom:24px; font-size:13px; color:var(--text-muted); flex-wrap:wrap; }
.breadcrumb a { color:var(--primary); text-decoration:none; font-weight:500; display:flex; align-items:center; gap:6px; padding:6px 14px; background:var(--bg-secondary); border-radius:var(--radius-full); border:1px solid var(--border-light); transition:all 0.2s; }
.breadcrumb a:hover { background:var(--primary); color:white; }
.breadcrumb .sep { color:var(--border-color); }
.breadcrumb .current { color:var(--text-secondary); }

.product-grid { display:grid; grid-template-columns:1fr 1.2fr; gap:32px; align-items:start; }
@media (max-width:900px) { .product-grid { grid-template-columns:1fr; } }
.image-section { position:sticky; top:80px; }
@media (max-width:900px) { .image-section { position:static; } }
.image-wrapper { background:var(--bg-secondary); border-radius:var(--radius-lg); border:1px solid var(--border-light); overflow:hidden; box-shadow:var(--shadow-sm); display:flex; }
.thumb-strip { display:flex; flex-direction:column; gap:8px; padding:12px 10px; overflow-y:auto; max-height:440px; background:var(--bg-secondary); border-right:1px solid var(--border-light); flex-shrink:0; }
.thumb { flex-shrink:0; width:64px; height:64px; border-radius:10px; overflow:hidden; border:2px solid var(--border-color); cursor:pointer; transition:all 0.2s; background:var(--bg-primary); }
.thumb img { width:100%; height:100%; object-fit:contain; padding:4px; }
.thumb:hover, .thumb.active { border-color:var(--primary); box-shadow:0 0 0 2px rgba(0,183,97,0.2); }
.main-image-area { position:relative; flex:1; height:440px; overflow:hidden; background:var(--bg-primary); cursor:zoom-in; }
@media (max-width:768px) { .main-image-area { height:300px; } }
.product-swiper { width:100%; height:440px; position:relative; overflow:hidden; }
@media (max-width:900px) { .product-swiper { height:360px; } }
@media (max-width:768px) { .product-swiper { height:300px; } }
.swiper-wrapper { display:flex; width:100%; height:100%; transition:transform 0.4s ease; }
.swiper-slide { min-width:100%; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:var(--bg-primary); flex-shrink:0; }
.swiper-slide img { width:100%; height:100%; object-fit:contain; display:block; transition:transform 0.3s; }
.swiper-slide img:hover { transform:scale(1.04); }
.swiper-pagination { position:absolute; bottom:8px; left:0; right:0; display:flex; justify-content:center; gap:6px; z-index:10; }
.swiper-pagination-bullet { width:7px; height:7px; border-radius:50%; background:var(--primary); opacity:0.35; transition:opacity 0.2s, transform 0.2s; cursor:pointer; border:none; padding:0; }
.swiper-pagination-bullet-active { opacity:1; transform:scale(1.3); }
.img-nav { position:absolute; top:50%; transform:translateY(-50%); width:32px; height:32px; background:rgba(0,0,0,0.4); border:none; border-radius:50%; color:white; display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:10; transition:all 0.2s; backdrop-filter:blur(4px); font-size:13px; }
.img-nav:hover { background:var(--primary); transform:translateY(-50%) scale(1.1); }
.img-nav.prev { left:10px; }
.img-nav.next { right:10px; }
.btn-3d-full { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; background:linear-gradient(135deg,#0EA5E9,#0369A1); color:white; border:none; cursor:pointer; font-family:var(--font-main); font-size:14px; font-weight:600; transition:all 0.2s; text-decoration:none; }
.btn-3d-full:hover { opacity:0.92; }

.lk-lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.lk-lightbox.show { display:flex; }
.lk-lb-inner { display:flex; width:100%; max-width:1000px; max-height:90vh; background:white; border-radius:var(--radius-lg); overflow:hidden; position:relative; }
.lk-lb-thumbs { display:flex; flex-direction:column; gap:8px; padding:16px 12px; overflow-y:auto; max-height:90vh; background:#f8f8f8; border-right:1px solid #eee; flex-shrink:0; width:90px; }
.lk-lb-thumb { width:66px; height:66px; border-radius:8px; overflow:hidden; border:2px solid transparent; cursor:pointer; background:white; }
.lk-lb-thumb img { width:100%; height:100%; object-fit:contain; padding:4px; }
.lk-lb-thumb.active { border-color:var(--primary); }
.lk-lb-main { flex:1; display:flex; align-items:center; justify-content:center; padding:24px; position:relative; background:white; overflow:hidden; }
.lk-lb-main img { max-width:100%; max-height:calc(90vh - 48px); object-fit:contain; }
.lk-lb-prev, .lk-lb-next { position:absolute; top:50%; transform:translateY(-50%); width:42px; height:42px; background:rgba(0,0,0,0.12); border:none; border-radius:50%; color:#333; display:flex; align-items:center; justify-content:center; font-size:18px; cursor:pointer; }
.lk-lb-prev:hover, .lk-lb-next:hover { background:var(--primary); color:white; }
.lk-lb-prev { left:12px; } .lk-lb-next { right:12px; }
.lk-lb-close { position:absolute; top:14px; right:14px; width:36px; height:36px; background:rgba(0,0,0,0.08); border:none; border-radius:50%; color:#333; font-size:18px; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:20; }
.lk-lb-close:hover { background:var(--danger); color:white; }
.lk-lb-counter { position:absolute; bottom:14px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.18); color:#333; padding:4px 12px; border-radius:var(--radius-full); font-size:12px; font-weight:600; }
@media (max-width:700px) {
    .lk-lb-inner { flex-direction:column; max-height:95vh; }
    .lk-lb-thumbs { flex-direction:row; overflow-x:auto; overflow-y:hidden; width:100%; height:auto; padding:10px 12px; border-right:none; border-bottom:1px solid #eee; }
    .lk-lb-thumb { width:50px; height:50px; }
}

.info-section { display:flex; flex-direction:column; gap:18px; }
.product-meta { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:28px; border:1px solid var(--border-light); box-shadow:var(--shadow-sm); }
.category-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; background:var(--primary-light); color:var(--primary); border-radius:var(--radius-full); font-size:12px; font-weight:600; margin-bottom:14px; }
.product-title { font-family:var(--font-display); font-size:30px; line-height:1.2; color:var(--text-primary); margin-bottom:8px; }
@media (max-width:768px) { .product-title { font-size:24px; } }

.price-row { display:flex; align-items:baseline; gap:10px; margin:16px 0 6px; padding:16px; background:var(--primary-light); border-radius:var(--radius-md); }
.price-main { font-size:36px; font-weight:700; color:var(--primary); font-family:var(--font-display); }
.price-main.sale-color { color:#EF4444; }
.price-label { font-size:13px; color:var(--text-muted); }
.price-original { font-size:20px; color:var(--text-muted); text-decoration:line-through; }
.sale-banner { display:flex; align-items:center; gap:8px; padding:10px 14px; background:linear-gradient(135deg,#FF4444,#FF6B6B); border-radius:var(--radius-md); margin-bottom:10px; flex-wrap:wrap; }
.sale-banner i, .sale-banner span { color:white; font-size:13px; font-weight:600; }
.sale-banner .sale-pct { background:rgba(255,255,255,0.25); padding:2px 10px; border-radius:var(--radius-full); font-size:12px; }
.sale-banner .sale-timer { margin-left:auto; font-size:12px; opacity:0.9; }
.savings-row { font-size:12px; color:var(--success); font-weight:600; display:flex; align-items:center; gap:5px; margin-bottom:12px; padding:0 4px; }
.product-desc { color:var(--text-secondary); line-height:1.8; font-size:14px; margin-bottom:6px; }
.payment-policy-card { margin-top:16px; padding:14px; background:var(--bg-primary); border-radius:var(--radius-md); border-left:4px solid var(--primary); font-size:13px; }
.payment-policy-card i { color:var(--primary); margin-right:8px; }
.payment-policy-card .policy-title { font-weight:700; color:var(--text-primary); margin-bottom:4px; }
.payment-policy-card .policy-desc { color:var(--text-secondary); font-size:12px; }

.existing-notice { display:flex; align-items:center; gap:12px; padding:14px 18px; border-radius:var(--radius-md); font-size:13px; font-weight:500; margin-top:12px; }
.existing-notice.reservation { background:#FEF3C7; color:#92400E; border:1px solid #FDE68A; }
.existing-notice.appointment { background:#DBEAFE; color:#1E40AF; border:1px solid #BFDBFE; }
.existing-notice a { color:inherit; font-weight:700; }

/* ============================================ */
/* STEP-BASED FLOW                              */
/* ============================================ */
.flow-step { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:22px; border:1px solid var(--border-light); margin-bottom:16px; transition:opacity 0.3s, border-color 0.2s; }
.flow-step.locked { opacity:0.5; pointer-events:none; }
.flow-step.active { border-color:var(--primary); box-shadow:0 0 0 3px rgba(0,183,97,0.08); }
.flow-step.completed { border-color:var(--primary); }
.flow-step.completed .step-num { background:var(--primary-gradient); }

.step-header { display:flex; align-items:flex-start; gap:14px; margin-bottom:18px; }
.step-num { width:32px; height:32px; border-radius:50%; background:var(--primary-gradient); color:white; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; flex-shrink:0; }
.flow-step.locked .step-num { background:#9CA3AF; }
.step-title { font-size:16px; font-weight:700; color:var(--text-primary); line-height:1.3; }
.step-sub { font-size:12px; color:var(--text-muted); margin-top:2px; }
.optional-tag { font-size:10px; font-weight:600; background:var(--border-light); color:var(--text-muted); padding:2px 8px; border-radius:var(--radius-full); margin-left:6px; vertical-align:middle; }

.rec-notice { display:flex; align-items:flex-start; gap:10px; background:#FEF9E7; border-left:4px solid var(--warning); border-radius:8px; padding:12px 14px; margin-bottom:14px; font-size:13px; color:#78500A; line-height:1.5; }
.theme-dark .rec-notice { background:#2A2210; color:#FCD34D; }
.rec-notice i { color:var(--warning); font-size:16px; flex-shrink:0; margin-top:1px; }

.lens-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:6px; }
@media (max-width:500px) { .lens-grid { grid-template-columns:1fr; } }
.lens-btn { padding:14px 12px; border:2px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-primary); cursor:pointer; transition:all 0.2s; text-align:left; display:flex; flex-direction:column; gap:4px; position:relative; font-family:inherit; }
.lens-btn:hover:not(:disabled):not(.unavailable) { border-color:var(--primary); background:var(--primary-light); }
.lens-btn.selected { border-color:var(--primary); background:var(--primary-light); box-shadow:0 0 0 3px rgba(0,183,97,0.12); }
.lens-btn .ln { font-weight:700; font-size:14px; color:var(--text-primary); }
.lens-btn .lp { font-size:12px; color:var(--primary); font-weight:600; }
.lens-btn .ld { font-size:11px; color:var(--text-muted); margin-top:2px; }
.lens-btn.unavailable { opacity:0.4; cursor:not-allowed; border-style:dashed; }
.lens-btn.unavailable .lp { color:var(--text-muted); }
.lens-btn.recommended::before { content:'⭐ RECOMMENDED'; position:absolute; top:-9px; right:10px; background:var(--primary); color:white; font-size:9px; font-weight:700; padding:2px 8px; border-radius:var(--radius-full); letter-spacing:0.5px; }
.lens-btn.unavailable::after { content:'Hindi compatible sa grado mo'; position:absolute; bottom:6px; right:12px; font-size:9px; color:var(--danger); font-weight:600; }

.rx-toggle { display:flex; gap:12px; margin:0 0 10px; flex-wrap:wrap; }
.rx-option { flex:1; min-width:140px; display:flex; align-items:center; gap:10px; padding:12px 16px; border:2px solid var(--border-color); border-radius:var(--radius-md); cursor:pointer; background:var(--bg-primary); transition:all 0.2s; font-size:13px; font-weight:600; color:var(--text-secondary); }
.rx-option input[type="radio"] { display:none; }
.rx-option:has(input:checked) { border-color:var(--primary); background:var(--primary-light); color:var(--primary); }
.rx-option .icon { width:32px; height:32px; border-radius:8px; background:var(--border-light); display:flex; align-items:center; justify-content:center; font-size:15px; }
.rx-option:has(input:checked) .icon { background:var(--primary); color:white; }

.rx-form { background:var(--bg-primary); border-radius:var(--radius-md); padding:18px; margin-top:12px; border:1px solid var(--border-light); }
.rx-eyes { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width:500px) { .rx-eyes { grid-template-columns:1fr; } }
.rx-eye-box { background:var(--bg-secondary); border-radius:10px; padding:12px; border:1px solid var(--border-light); }
.rx-eye-label { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:10px; display:flex; align-items:center; gap:5px; }
.rx-eye-label span { background:var(--primary); color:white; padding:2px 8px; border-radius:4px; font-size:10px; }
.rx-inputs { display:flex; gap:6px; }
.rx-input-group { flex:1; }
.rx-input-group label { font-size:9px; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:3px; font-weight:600; }
.rx-input-group input { width:100%; padding:8px 6px; border:1.5px solid var(--border-color); border-radius:8px; background:var(--bg-primary); color:var(--text-primary); font-size:13px; text-align:center; font-family:inherit; }
.rx-input-group input:focus { outline:none; border-color:var(--primary); }
.rx-note { font-size:11px; color:var(--text-muted); margin-top:12px; text-align:center; }
.rx-note i { color:var(--primary); }
.rx-validation-msg { font-size:12px; padding:8px 12px; border-radius:8px; margin-top:10px; display:flex; align-items:center; gap:6px; }
.rx-validation-msg.error { background:#FEE2E2; color:#991B1B; }
.rx-validation-msg.warning { background:#FEF3C7; color:#92400E; }

.eye-exam-box { background:var(--primary-light); border:1px solid rgba(0,183,97,0.2); border-radius:var(--radius-md); padding:16px; margin-top:12px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:8px; }
.eye-exam-box i { font-size:28px; color:var(--primary); }
.eye-exam-box p { font-size:13px; color:var(--text-secondary); }

.coating-list { display:flex; flex-direction:column; gap:8px; }
.coating-item { display:flex; align-items:center; gap:12px; padding:12px 14px; border:2px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-primary); cursor:pointer; transition:all 0.2s; }
.coating-item:hover { border-color:var(--primary); }
.coating-item input[type="checkbox"] { display:none; }
.coating-check { width:22px; height:22px; border-radius:6px; border:2px solid var(--border-color); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.coating-check i { color:white; font-size:11px; opacity:0; }
.coating-item input:checked ~ .coating-check { background:var(--primary); border-color:var(--primary); }
.coating-item input:checked ~ .coating-check i { opacity:1; }
.coating-info { flex:1; }
.coating-name { font-size:14px; font-weight:600; color:var(--text-primary); }
.coating-desc { font-size:11px; color:var(--text-muted); margin-top:1px; }
.coating-price { font-size:13px; font-weight:700; color:var(--primary); }

.price-breakdown-card { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:20px; border:1px solid var(--border-light); }
.pb-title { font-size:14px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px; margin-bottom:14px; }
.pb-title i { color:var(--primary); }
.pb-rows { display:flex; flex-direction:column; gap:8px; padding-bottom:12px; border-bottom:1px dashed var(--border-color); margin-bottom:12px; }
.pb-row { display:flex; justify-content:space-between; font-size:13px; }
.pb-row .pb-label { color:var(--text-secondary); }
.pb-row .pb-value { color:var(--text-primary); font-weight:600; }
.pb-row.pb-addon .pb-label::before { content:'+ '; color:var(--primary); }
.pb-total { display:flex; justify-content:space-between; align-items:baseline; }
.pb-total span { font-size:14px; font-weight:700; color:var(--text-primary); }
.pb-total strong { font-size:22px; color:var(--primary); font-family:var(--font-display); }

.color-selector-box, .size-selector-box { background:var(--bg-primary); border-radius:var(--radius-md); padding:16px; margin-bottom:6px; border:1px solid var(--border-light); }
.cs-title { font-size:14px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:7px; margin-bottom:14px; }
.cs-title i { color:var(--primary); }
.cs-selected-label { font-weight:500; color:var(--text-secondary); font-size:13px; margin-left:auto; }
.cs-selected-label.chosen { color:var(--primary); font-weight:600; }
.color-btns, .size-btns { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
.color-btn { display:flex; align-items:center; gap:7px; padding:8px 14px; border:2px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-secondary); cursor:pointer; transition:all 0.2s; font-family:inherit; }
.color-btn:hover:not(:disabled):not(.oos) { border-color:var(--primary); background:var(--primary-light); }
.color-btn.selected { border-color:var(--primary); background:var(--primary-light); box-shadow:0 0 0 3px rgba(0,183,97,0.12); }
.color-btn.oos { opacity:0.45; cursor:not-allowed; border-style:dashed; }
.color-dot { width:14px; height:14px; border-radius:50%; border:1.5px solid rgba(0,0,0,0.15); }
.color-btn-name { font-size:13px; font-weight:600; color:var(--text-primary); }
.color-btn-qty { font-size:11px; color:var(--text-muted); }
.color-btn-qty.low { color:var(--warning); font-weight:600; }
.color-btn-qty.oos-label { color:var(--danger); font-weight:600; }
.cs-hint { font-size:11px; color:var(--text-muted); display:flex; align-items:center; gap:4px; }
.cs-hint i { color:var(--primary); }
.size-btn { padding:8px 16px; border:2px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-secondary); cursor:pointer; font-weight:600; font-size:13px; transition:all 0.2s; font-family:inherit; }
.size-btn:hover, .size-btn.selected { border-color:var(--primary); background:var(--primary-light); color:var(--primary); }

.sold-out-notice { display:flex; align-items:flex-start; gap:12px; background:#FEE2E2; border:2px solid #FECACA; border-radius:var(--radius-md); padding:16px; margin-bottom:16px; }
.sold-out-notice > i { color:var(--danger); font-size:20px; flex-shrink:0; margin-top:2px; }
.sold-out-notice strong { font-size:15px; color:var(--danger); display:block; margin-bottom:4px; }
.sold-out-notice p { font-size:13px; color:var(--text-secondary); margin:0; }

.action-card { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:20px; border:1px solid var(--border-light); box-shadow:var(--shadow-sm); }
.btn-main-action { width:100%; padding:16px; background:var(--primary-gradient); color:white; border:none; border-radius:var(--radius-md); font-size:16px; font-weight:700; font-family:var(--font-main); cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; transition:all 0.2s; box-shadow:0 4px 16px rgba(0,183,97,0.3); }
.btn-main-action:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,183,97,0.4); }
.btn-main-action:disabled { opacity:0.55; cursor:not-allowed; transform:none; box-shadow:none; background:#9CA3AF; }
.btn-main-action.appointment-style { background:linear-gradient(135deg,#3B82F6,#2563EB); box-shadow:0 4px 16px rgba(59,130,246,0.3); }
.action-helper { font-size:12px; color:var(--text-muted); text-align:center; margin-top:10px; }

.clinic-card { background:var(--bg-secondary); border-radius:var(--radius-lg); padding:20px; border:1px solid var(--border-light); box-shadow:var(--shadow-sm); display:flex; gap:14px; align-items:flex-start; }
.clinic-logo-box { width:52px; height:52px; border-radius:14px; background:var(--primary-light); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; }
.clinic-logo-box img { width:100%; height:100%; object-fit:cover; }
.clinic-logo-box i { font-size:24px; color:var(--primary); }
.clinic-info .cn { font-weight:700; font-size:15px; color:var(--text-primary); }
.clinic-info .ca { font-size:12px; color:var(--text-secondary); margin-top:3px; display:flex; align-items:flex-start; gap:4px; }
.clinic-info .ch { font-size:12px; color:var(--text-muted); margin-top:4px; }
.clinic-info .ca i, .clinic-info .ch i { color:var(--primary); }
.clinic-btns { display:flex; gap:8px; margin-top:12px; }
.btn-clinic-sm { flex:1; padding:8px 10px; border-radius:10px; font-size:12px; font-weight:600; text-align:center; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:5px; transition:all 0.2s; }
.btn-clinic-sm.view { background:var(--bg-primary); color:var(--primary); border:1px solid var(--border-light); }
.btn-clinic-sm.view:hover { background:var(--primary-light); }
.btn-clinic-sm.message { background:var(--bg-primary); color:var(--primary); border:1px solid var(--border-light); }
.btn-clinic-sm.message:hover { background:var(--primary); color:white; }

.similar-section { margin-top:48px; }
.similar-section h2 { font-family:var(--font-display); font-size:24px; margin-bottom:20px; color:var(--text-primary); display:flex; align-items:center; gap:10px; }
.similar-section h2 i { color:var(--primary); font-size:20px; }
.similar-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:16px; }
.similar-card { background:var(--bg-secondary); border-radius:var(--radius-md); overflow:hidden; border:1px solid var(--border-light); text-decoration:none; display:block; transition:all 0.2s; }
.similar-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-md); border-color:var(--primary); }
.similar-img { height:120px; overflow:hidden; background:var(--bg-primary); }
.similar-img img { width:100%; height:100%; object-fit:cover; }
.similar-info { padding:12px; }
.similar-name { font-size:13px; font-weight:600; color:var(--text-primary); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.similar-price { font-size:14px; font-weight:700; color:var(--primary); }

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:2000; align-items:center; justify-content:center; padding:16px; }
.modal-overlay.show { display:flex; }
.modal-box { background:var(--bg-secondary); border-radius:var(--radius-lg); width:100%; max-width:500px; max-height:90vh; overflow-y:auto; animation:modalIn 0.25s ease; }
@keyframes modalIn { from { opacity:0; transform:translateY(20px) scale(0.97); } to { opacity:1; transform:none; } }
.modal-head { padding:20px 24px; border-bottom:1px solid var(--border-light); display:flex; align-items:center; justify-content:space-between; background:var(--primary-gradient); border-radius:var(--radius-lg) var(--radius-lg) 0 0; }
.modal-head h3 { font-size:16px; font-weight:700; color:white; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; color:white; font-size:22px; cursor:pointer; opacity:0.8; }
.modal-body { padding:22px 24px; }
.form-group { margin-bottom:16px; }
.form-label { font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:7px; display:block; }
.form-input { width:100%; padding:11px 14px; border:1.5px solid var(--border-color); border-radius:var(--radius-sm); font-size:14px; background:var(--bg-primary); color:var(--text-primary); font-family:var(--font-main); }
.form-input:focus { outline:none; border-color:var(--primary); }
.modal-price-box { background:var(--bg-primary); border-radius:var(--radius-md); padding:14px 16px; margin-top:6px; border:1px solid var(--border-light); }
.mpb-row { display:flex; justify-content:space-between; align-items:center; font-size:13px; margin-bottom:6px; }
.mpb-row:last-child { margin-bottom:0; }
.mpb-row .mpb-label { color:var(--text-secondary); }
.mpb-row .mpb-value { font-weight:600; color:var(--text-primary); }
.mpb-divider { height:1px; background:var(--border-color); margin:10px 0; }
.mpb-row.total .mpb-label { font-weight:700; color:var(--text-primary); }
.mpb-row.total .mpb-value { font-size:16px; color:var(--primary); font-weight:700; }
.modal-apt-notice { background:#EFF6FF; border:1px solid #BFDBFE; border-radius:var(--radius-md); padding:14px; text-align:center; font-size:13px; color:#1E40AF; margin-top:8px; }
.modal-foot { padding:16px 24px; border-top:1px solid var(--border-light); display:flex; gap:10px; }
.btn-modal { flex:1; padding:12px; border-radius:var(--radius-sm); font-size:14px; font-weight:700; cursor:pointer; border:none; display:flex; align-items:center; justify-content:center; gap:7px; font-family:var(--font-main); }
.btn-modal.confirm { background:var(--primary-gradient); color:white; }
.btn-modal.confirm:hover { opacity:0.9; }
.btn-modal.cancel { background:var(--bg-primary); color:var(--text-secondary); border:1px solid var(--border-color); }

.toast-container { position:fixed; top:20px; right:20px; z-index:9990; }
.toast { display:flex; align-items:center; gap:12px; background:var(--bg-secondary); border-radius:var(--radius-md); padding:14px 20px; box-shadow:var(--shadow-lg); margin-bottom:10px; min-width:300px; max-width:380px; animation:toastIn 0.3s ease; border-left:4px solid var(--success); }
.toast.error { border-left-color:var(--danger); }
.toast.info { border-left-color:var(--info); }
.toast i { font-size:18px; }
.toast.success i { color:var(--success); }
.toast.error i { color:var(--danger); }
.toast.info i { color:var(--info); }
.toast span { font-size:13px; color:var(--text-primary); flex:1; }
@keyframes toastIn { from { transform:translateX(100%); opacity:0; } to { transform:none; opacity:1; } }

#loadingOverlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; }
.spinner { width:48px; height:48px; border:4px solid rgba(255,255,255,0.2); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.hidden { display:none !important; }

.fav-product-wrap { margin-bottom:12px; }
.btn-fav-product-full { width:100%; display:flex; align-items:center; justify-content:center; gap:10px; padding:13px 20px; border-radius:var(--radius-full); border:2px solid #e5e7eb; background:transparent; color:var(--text-secondary); font-size:15px; font-weight:600; cursor:pointer; transition:all 0.2s; font-family:inherit; }
.btn-fav-product-full i { font-size:16px; color:#ccc; }
.btn-fav-product-full:hover { border-color:#EF4444; color:#EF4444; }
.btn-fav-product-full.active { border-color:#EF4444; color:#EF4444; background:#fff5f5; }
.btn-fav-product-full.active i { color:#EF4444; }

.specs-accordion, .warranty-box { background:var(--bg-secondary); border-radius:var(--radius-md); margin-bottom:12px; border:1px solid var(--border-light); overflow:hidden; }
.specs-header, .warranty-header { padding:14px 18px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; font-weight:600; background:var(--bg-primary); }
.specs-content, .warranty-content { padding:16px 18px; border-top:1px solid var(--border-light); }
.specs-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:12px; }
.spec-item { display:flex; justify-content:space-between; align-items:center; font-size:13px; padding:6px 0; border-bottom:1px dashed var(--border-light); }
.spec-label { color:var(--text-secondary); font-weight:500; }
.spec-value { color:var(--text-primary); font-weight:600; }
.warranty-period-badge { background:var(--primary-light); padding:10px 15px; border-radius:var(--radius-md); margin-bottom:16px; font-weight:600; color:var(--primary); display:inline-block; }

.fulfillment-toggle { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
.ff-btn { display:flex; flex-direction:column; align-items:center; gap:4px; padding:14px 10px; border:2px solid var(--border-color); border-radius:var(--radius-md); background:var(--bg-primary); cursor:pointer; font-family:var(--font-main); }
.ff-btn i { font-size:20px; color:var(--text-muted); }
.ff-btn span { font-size:14px; font-weight:700; color:var(--text-primary); }
.ff-btn small { font-size:11px; color:var(--text-muted); }
.ff-btn.active { border-color:var(--primary); background:var(--primary-light); box-shadow:0 0 0 3px rgba(0,183,97,0.12); }
.ff-btn.active i { color:var(--primary); }

.form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:480px) { .form-row-2 { grid-template-columns:1fr; } }

.delivery-info-box { display:flex; align-items:center; gap:12px; background:var(--primary-light); border:1px solid rgba(0,183,97,0.2); border-radius:var(--radius-md); padding:14px; margin-bottom:16px; }
.delivery-info-box i { font-size:22px; color:var(--primary); }
.delivery-info-box strong { display:block; font-size:13px; color:var(--text-primary); }
.delivery-info-box small { font-size:11px; color:var(--text-secondary); }

.payment-method-option { display:flex; align-items:center; gap:12px; padding:14px 16px; border:2px solid var(--border-color); border-radius:var(--radius-md); cursor:pointer; background:var(--bg-primary); }
.payment-method-option.selected { border-color:var(--primary); background:var(--primary-light); }
</style>
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div id="loadingOverlay" class="hidden"><div class="spinner"></div></div>

<div class="main-content">
    <div class="breadcrumb">
        <a href="#" onclick="goBack(); return false;"><i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($product['clinic_name']); ?></a>
        <span class="sep">/</span>
        <span class="current"><?php echo htmlspecialchars($product['name']); ?></span>
    </div>

    <div class="product-grid">
        <div class="image-section">
            <div class="image-wrapper">
                <?php if ($has_multiple_images): ?>
                <div class="thumb-strip" id="thumbStrip">
                    <?php foreach ($product_images as $i => $img): ?>
                    <div class="thumb <?php echo $i === 0 ? 'active' : ''; ?>" onclick="goToSlide(<?php echo $i; ?>)" id="thumb-<?php echo $i; ?>">
                        <img src="<?php echo $img; ?>" alt="" onerror="this.src='/assets/img/no-image.png'">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="main-image-area" onclick="openLightbox(currentSlide)">
                    <div class="product-swiper" id="productSlider">
                        <div class="swiper-wrapper" id="sliderWrapper">
                            <?php foreach ($product_images as $i => $img): ?>
                            <div class="swiper-slide"><img src="<?php echo $img; ?>" alt="" onerror="this.src='/assets/img/no-image.png'"></div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($has_multiple_images): ?><div class="swiper-pagination" id="sliderDots"></div><?php endif; ?>
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

        <div class="lk-lightbox" id="lkLightbox" onclick="closeLightbox()">
            <div class="lk-lb-inner" onclick="event.stopPropagation()">
                <div class="lk-lb-thumbs">
                    <?php foreach ($product_images as $i => $img): ?>
                    <div class="lk-lb-thumb <?php echo $i === 0 ? 'active' : ''; ?>" id="lbThumb-<?php echo $i; ?>" onclick="lbGoTo(<?php echo $i; ?>)">
                        <img src="<?php echo $img; ?>" alt="">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="lk-lb-main">
                    <img id="lbMainImg" src="<?php echo $product_images[0]; ?>" alt="">
                    <?php if (count($product_images) > 1): ?>
                    <button class="lk-lb-prev" onclick="lbGoTo(lbCurrentIndex - 1)">&#10094;</button>
                    <button class="lk-lb-next" onclick="lbGoTo(lbCurrentIndex + 1)">&#10095;</button>
                    <?php endif; ?>
                    <div class="lk-lb-counter" id="lbCounter">1 / <?php echo count($product_images); ?></div>
                </div>
                <button class="lk-lb-close" onclick="closeLightbox()">&#10005;</button>
            </div>
        </div>

        <div class="info-section">
            <div class="product-meta">
                <div class="category-pill"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?></div>
                <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                <?php if ($is_on_sale): ?>
                <div class="sale-banner">
                    <i class="fas fa-fire"></i><span>On Sale!</span>
                    <span class="sale-pct">-<?php echo $discount_percent; ?>%</span>
                    <span class="sale-timer"><?php if ($days_left <= 0) echo 'Ends today!'; elseif ($days_left == 1) echo '1 day left'; else echo $days_left . ' days left'; ?></span>
                </div>
                <div class="price-row">
                    <span class="price-main sale-color">₱<?php echo number_format($sale_price, 2); ?></span>
                    <span class="price-original">₱<?php echo number_format($original_price, 2); ?></span>
                </div>
                <div class="savings-row"><i class="fas fa-piggy-bank"></i> You save ₱<?php echo number_format($savings, 2); ?></div>
                <?php else: ?>
                <div class="price-row">
                    <span class="price-main">₱<?php echo number_format($original_price, 2); ?></span>
                    <span class="price-label">Starting price</span>
                </div>
                <?php endif; ?>

                <div class="payment-policy-card">
                    <div class="policy-title">
                        <i class="fas fa-credit-card"></i> Payment Policy: <?php echo $clinic_payment_policy_display; ?>
                        <span style="background:var(--<?php echo $clinic_booking_flow == 'pay_first' ? 'primary' : 'warning'; ?>); color:white; padding:2px 8px; border-radius:20px; font-size:10px; margin-left:8px;">
                            <?php echo $clinic_booking_flow == 'pay_first' ? 'Pay First' : 'Approve First'; ?>
                        </span>
                    </div>
                    <div class="policy-desc">
                        <?php if ($payment_type_label == 'downpayment'): ?>
                            <i class="fas fa-percent"></i> <?php echo $downpayment_percent; ?>% downpayment (₱<?php echo number_format($downpayment_amount_calculated, 2); ?>) required online.
                        <?php elseif ($payment_type_label == 'full'): ?>
                            <i class="fas fa-cash"></i> 100% full payment required online.
                        <?php elseif ($payment_type_label == 'onsite'): ?>
                            <i class="fas fa-store"></i> Pay directly at the clinic. No online payment required.
                        <?php elseif ($payment_type_label == 'free'): ?>
                            <i class="fas fa-gift"></i> This is a free service.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($product['description'])): ?>
                <p class="product-desc"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                <?php endif; ?>

                <!-- COLOR -->
                <?php if ($has_stock_tracking && !$IS_SERVICE): ?>
                <div class="color-selector-box" style="margin-top:16px;">
                    <div class="cs-title">
                        <i class="fas fa-palette"></i> Color / Variant
                        <span class="cs-selected-label" id="csSelectedLabel">— Select a color</span>
                    </div>
                    <?php if ($is_fully_sold_out): ?>
                        <div class="sold-out-notice">
                            <i class="fas fa-times-circle"></i>
                            <div><strong>Out of Stock</strong><p>All variants are currently unavailable.</p></div>
                        </div>
                    <?php else: ?>
                        <div class="color-btns" id="colorBtns">
                            <?php foreach ($product_colors as $color): $oos = $color['quantity'] <= 0; ?>
                                <button class="color-btn <?php echo $oos ? 'oos' : ''; ?>"
                                        data-code="<?php echo htmlspecialchars($color['code']); ?>"
                                        data-name="<?php echo htmlspecialchars($color['name']); ?>"
                                        data-qty="<?php echo $color['quantity']; ?>"
                                        onclick="selectColor(this)" <?php echo $oos ? 'disabled' : ''; ?>>
                                    <?php if (!empty($color['code']) && $color['code'] !== '#' && strlen($color['code']) >= 4): ?>
                                        <span class="color-dot" style="background:<?php echo htmlspecialchars($color['code']); ?>"></span>
                                    <?php endif; ?>
                                    <span class="color-btn-name"><?php echo htmlspecialchars($color['name']); ?></span>
                                    <?php if ($oos): ?><span class="color-btn-qty oos-label">Out of Stock</span>
                                    <?php elseif ($color['quantity'] <= 5): ?><span class="color-btn-qty low"><?php echo $color['quantity']; ?> left</span>
                                    <?php else: ?><span class="color-btn-qty"><?php echo $color['quantity']; ?> left</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="cs-hint" id="csHint"><i class="fas fa-info-circle"></i> Please select a color to continue.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- SIZE -->
                <?php if (in_array($category, ['Frames', 'Eyeglasses', 'Sunglasses']) && !empty($available_sizes)): ?>
                <div class="size-selector-box" style="margin-top:12px;">
                    <div class="cs-title">
                        <i class="fas fa-ruler-combined"></i> Frame Size
                        <span class="cs-selected-label" id="sizeSelectedLabel">— Select a size</span>
                    </div>
                    <div class="size-btns" id="sizeBtns">
                        <?php foreach ($available_sizes as $size): ?>
                        <button class="size-btn" data-size="<?php echo htmlspecialchars($size); ?>" onclick="selectSize(this)"><?php echo htmlspecialchars($size); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="cs-hint" id="sizeHint"><i class="fas fa-info-circle"></i> Please select your preferred frame size.</p>
                </div>
                <?php endif; ?>

                <?php if ($existing_reservation): ?>
                <div class="existing-notice reservation"><i class="fas fa-bookmark"></i> You already have an active order. <a href="my-reservations.php">View it →</a></div>
                <?php elseif ($existing_appointment): ?>
                <div class="existing-notice appointment"><i class="fas fa-calendar-check"></i> You already have a pending appointment. <a href="my-appointments.php">View it →</a></div>
                <?php endif; ?>
            </div>

            <!-- ============================================ -->
            <!-- 4-STEP FLOW                                   -->
            <!-- ============================================ -->
            <?php if (!$IS_SERVICE && !$IS_ACCESSORY): ?>

            <!-- STEP 1: PRESCRIPTION -->
            <div class="flow-step" id="stepRx">
                <div class="step-header">
                    <div class="step-num">1</div>
                    <div>
                        <div class="step-title">Your Prescription</div>
                        <div class="step-sub">Kailangan namin ito para marekomenda ang tamang lens</div>
                    </div>
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
                    <div class="rx-eyes">
                        <div class="rx-eye-box">
                            <div class="rx-eye-label">Right Eye <span>OD</span></div>
                            <div class="rx-inputs">
                                <div class="rx-input-group"><label>SPH</label><input type="number" step="0.25" id="od_sph" placeholder="-4.50" oninput="onRxChange()"></div>
                                <div class="rx-input-group"><label>CYL</label><input type="number" step="0.25" id="od_cyl" placeholder="-1.00" oninput="onRxChange()"></div>
                                <div class="rx-input-group"><label>AXIS</label><input type="number" min="1" max="180" id="od_axis" placeholder="180" oninput="onRxChange()"></div>
                            </div>
                        </div>
                        <div class="rx-eye-box">
                            <div class="rx-eye-label">Left Eye <span>OS</span></div>
                            <div class="rx-inputs">
                                <div class="rx-input-group"><label>SPH</label><input type="number" step="0.25" id="os_sph" placeholder="-4.00" oninput="onRxChange()"></div>
                                <div class="rx-input-group"><label>CYL</label><input type="number" step="0.25" id="os_cyl" placeholder="-0.75" oninput="onRxChange()"></div>
                                <div class="rx-input-group"><label>AXIS</label><input type="number" min="1" max="180" id="os_axis" placeholder="175" oninput="onRxChange()"></div>
                            </div>
                        </div>
                    </div>
                    <div id="rxValidationMsg" class="rx-validation-msg" style="display:none;"></div>
                    <p class="rx-note"><i class="fas fa-shield-alt"></i> Veverify ng optometrist ang prescription mo upon visit.</p>
                </div>

                <div id="rxExamBox" class="eye-exam-box" style="display:none;">
                    <i class="fas fa-calendar-check"></i>
                    <strong style="color:var(--primary)">Eye Exam Required</strong>
                    <p>We'll schedule an appointment for you. Our optometrist will check your vision and recommend the right prescription.</p>
                </div>
            </div>

            <!-- STEP 2: LENS TYPE -->
            <div class="flow-step locked" id="stepLensType">
                <div class="step-header">
                    <div class="step-num">2</div>
                    <div>
                        <div class="step-title">Select Lens Type</div>
                        <div class="step-sub" id="stepLensTypeSub">Punan muna ang prescription sa Step 1</div>
                    </div>
                </div>
                <div class="lens-grid" id="lensGrid">
                    <?php if (!$IS_LENS_ONLY && !$IS_CONTACT_LENS): ?>
                        <button class="lens-btn" data-lens="frame_only" onclick="selectLens(this)">
                            <span class="ln">Frame Only</span><span class="lp">+₱0</span>
                            <span class="ld">Walang lens — frame lang</span>
                        </button>
                    <?php endif; ?>
                    <?php if ($IS_CONTACT_LENS): ?>
                        <button class="lens-btn" data-lens="contact_daily" onclick="selectLens(this)">
                            <span class="ln">Daily Disposable</span><span class="lp">Included</span>
                            <span class="ld">Fresh pair every day</span>
                        </button>
                        <button class="lens-btn" data-lens="contact_monthly" onclick="selectLens(this)">
                            <span class="ln">Monthly Wear</span><span class="lp">Included</span>
                            <span class="ld">Reusable for 30 days</span>
                        </button>
                    <?php else: ?>
                        <button class="lens-btn" data-lens="single_vision" onclick="selectLens(this)">
                            <span class="ln">Single Vision</span><span class="lp">+₱500</span>
                            <span class="ld">For near OR far vision</span>
                        </button>
                        <button class="lens-btn" data-lens="progressive" onclick="selectLens(this)">
                            <span class="ln">Progressive</span><span class="lp">+₱1,500</span>
                            <span class="ld">Near, mid & far vision</span>
                        </button>
                        <?php if (!$IS_LENS_ONLY): ?>
                        <button class="lens-btn" data-lens="blue_cut" onclick="selectLens(this)">
                            <span class="ln">Blue Cut</span><span class="lp">+₱800</span>
                            <span class="ld">Reduces screen glare</span>
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STEP 3: LENS THICKNESS -->
            <div class="flow-step locked" id="stepLensIndex">
                <div class="step-header">
                    <div class="step-num">3</div>
                    <div>
                        <div class="step-title">Recommended Lens Thickness</div>
                        <div class="step-sub" id="lensIndexSub">Pumili muna ng lens type</div>
                    </div>
                </div>
                <div id="lensRecommendationNotice" class="rec-notice" style="display:none;">
                    <i class="fas fa-lightbulb"></i>
                    <span id="recNoticeText"></span>
                </div>
                <div class="lens-grid" id="lensIndexGrid"></div>
            </div>

            <!-- STEP 4: COATINGS -->
            <?php if (!empty($lens_coatings)): ?>
            <div class="flow-step locked" id="stepCoatings">
                <div class="step-header">
                    <div class="step-num">4</div>
                    <div>
                        <div class="step-title">Add-on Coatings <span class="optional-tag">Optional</span></div>
                        <div class="step-sub">I-enhance ang iyong lenses</div>
                    </div>
                </div>
                <div class="coating-list" id="coatingList">
                    <?php foreach ($lens_coatings as $c): ?>
                    <label class="coating-item" data-coating-code="<?php echo htmlspecialchars($c['code']); ?>"
                           data-applies='<?php echo json_encode($c["applies_to"]); ?>'>
                        <input type="checkbox" value="<?php echo $c['id']; ?>"
                               data-price="<?php echo $c['price']; ?>"
                               data-code="<?php echo htmlspecialchars($c['code']); ?>"
                               onchange="onCoatingChange()">
                        <div class="coating-check"><i class="fas fa-check"></i></div>
                        <div class="coating-info">
                            <div class="coating-name"><?php echo htmlspecialchars($c['name']); ?></div>
                            <div class="coating-desc"><?php echo htmlspecialchars($c['description']); ?></div>
                        </div>
                        <div class="coating-price">+₱<?php echo number_format($c['price'], 0); ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- PRICE BREAKDOWN -->
            <div class="price-breakdown-card">
                <div class="pb-title"><i class="fas fa-receipt"></i> Price Breakdown</div>
                <div class="pb-rows" id="pbRows"></div>
                <div class="pb-total">
                    <span>Total</span>
                    <strong id="pbTotal">₱<?php echo number_format($display_price, 2); ?></strong>
                </div>
            </div>

            <?php endif; // !IS_SERVICE && !IS_ACCESSORY ?>

            <!-- FAVORITE -->
            <div class="fav-product-wrap">
                <button class="btn-fav-product-full <?php echo $is_product_favorited ? 'active' : ''; ?>"
                        id="productFavBtn" data-product-id="<?php echo $product_id; ?>" onclick="toggleProductFav(this)">
                    <i class="fa<?php echo $is_product_favorited ? 's' : 'r'; ?> fa-heart"></i>
                    <span><?php echo $is_product_favorited ? 'Saved to Favorites' : 'Save to Favorites'; ?></span>
                </button>
            </div>

            <!-- ACTION BUTTON -->
            <div class="action-card">
                <?php if ($existing_reservation || $existing_appointment): ?>
                    <button class="btn-main-action" disabled>
                        <i class="fas fa-check-circle"></i>
                        <?php echo $existing_reservation ? 'Already Ordered' : 'Already Booked'; ?>
                    </button>
                <?php elseif ($is_fully_sold_out): ?>
                    <button class="btn-main-action" disabled><i class="fas fa-times-circle"></i> Out of Stock</button>
                    <p class="action-helper" style="color:var(--danger);"><i class="fas fa-info-circle"></i> All variants unavailable.</p>
                <?php elseif ($IS_SERVICE): ?>
                    <a href="book-appointment.php?clinic_id=<?php echo $clinic_id; ?>&item_id=<?php echo $product_id; ?>&type=product"
                       class="btn-main-action appointment-style" style="text-decoration:none;">
                        <i class="fas fa-calendar-plus"></i> Book Appointment
                    </a>
                <?php elseif ($IS_ACCESSORY): ?>
                    <button class="btn-main-action" id="mainActionBtn" onclick="openModal('reservation')">
                        <i class="fas fa-bookmark"></i> Order Now
                    </button>
                <?php else: ?>
                    <button class="btn-main-action" id="mainActionBtn" onclick="handleMainAction()" disabled>
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                    <p class="action-helper" id="actionHelper">
                        <i class="fas fa-info-circle"></i> Start with Step 1 — your prescription.
                    </p>
                <?php endif; ?>
            </div>

            <!-- SPECS -->
            <?php if (!empty($spec_fields)): ?>
            <div class="specs-accordion">
                <div class="specs-header" onclick="toggleAccordion('specsContent','specsIcon')">
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

            <!-- WARRANTY -->
            <?php if ($has_warranty): ?>
            <div class="warranty-box">
                <div class="warranty-header" onclick="toggleAccordion('warrantyContent','warrantyIcon')">
                    <span><i class="fas fa-shield-alt"></i> Warranty Information</span>
                    <i class="fas fa-chevron-down" id="warrantyIcon"></i>
                </div>
                <div class="warranty-content" id="warrantyContent" style="display:none;">
                    <div class="warranty-period-badge"><i class="fas fa-clock"></i> <?php echo $warranty_period_display; ?> Warranty</div>
                    <?php if (!empty($warranty_coverage)): ?>
                    <div style="font-size:13px; color:var(--text-secondary); line-height:1.7;">
                        <strong style="color:var(--text-primary); display:block; margin-bottom:8px;">Covered:</strong>
                        <?php foreach ($warranty_coverage as $cover): ?>
                            <div><i class="fas fa-check" style="color:var(--primary)"></i> <?php echo ucfirst(str_replace('_', ' ', $cover)); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($warranty_exclusions)): ?>
                    <div style="margin-top:14px; font-size:13px; color:var(--text-secondary); line-height:1.7;">
                        <strong style="color:var(--text-primary); display:block; margin-bottom:8px;">Not Covered:</strong>
                        <?php echo nl2br(htmlspecialchars($warranty_exclusions)); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- CLINIC -->
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
                        <a href="clinic-details.php?id=<?php echo $clinic_id; ?>" class="btn-clinic-sm view"><i class="fas fa-store"></i> View Clinic</a>
                        <a href="messages.php?clinic_id=<?php echo $clinic_id; ?>&clinic_name=<?php echo urlencode($product['clinic_name']); ?>" class="btn-clinic-sm message"><i class="fas fa-comment-dots"></i> Message</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (mysqli_num_rows($similar_query) > 0): ?>
    <div class="similar-section">
        <h2><i class="fas fa-tags"></i> You May Also Like</h2>
        <div class="similar-grid">
            <?php while ($sim = mysqli_fetch_assoc($similar_query)):
                $sim_imgs = getProductImages($sim);
            ?>
            <a href="product-view.php?id=<?php echo $sim['id']; ?>" class="similar-card">
                <div class="similar-img"><img src="<?php echo $sim_imgs[0]; ?>" alt="" onerror="this.src='/assets/img/no-image.png'"></div>
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

<!-- MODAL -->
<div class="modal-overlay" id="scheduleModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle"><i class="fas fa-shopping-bag"></i> Order This Product</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($offers_delivery && !$IS_SERVICE): ?>
            <div class="fulfillment-toggle">
                <button type="button" class="ff-btn active" data-ff="pickup" onclick="selectFulfillment('pickup')">
                    <i class="fas fa-store"></i><span>Pick-up</span><small>at the clinic</small>
                </button>
                <button type="button" class="ff-btn" data-ff="delivery" onclick="selectFulfillment('delivery')">
                    <i class="fas fa-truck"></i><span>Delivery</span><small>to your address</small>
                </button>
            </div>
            <?php endif; ?>

            <div id="pickupFields">
                <div class="form-group">
                    <label class="form-label">Preferred Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="mDate" class="form-input" min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Preferred Time <span style="color:var(--danger)">*</span></label>
                    <input type="time" id="mTime" class="form-input">
                </div>
            </div>

            <div id="deliveryFields" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Recipient Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="dName" class="form-input" value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Recipient Phone <span style="color:var(--danger)">*</span></label>
                    <input type="tel" id="dPhone" class="form-input" value="<?php echo htmlspecialchars($user_default_contact); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Street Address <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="dAddress" class="form-input" value="<?php echo htmlspecialchars($user_default_address); ?>">
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Barangay</label>
                        <input type="text" id="dBarangay" class="form-input" value="<?php echo htmlspecialchars($user_default_barangay); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">City <span style="color:var(--danger)">*</span></label>
                        <select id="dCity" class="form-input" onchange="updateDeliveryFee()">
                            <option value="">— Select City —</option>
                            <?php foreach ($delivery_fees_list as $df): ?>
                                <option value="<?php echo htmlspecialchars($df['city']); ?>"
                                        data-fee="<?php echo $df['fee_amount']; ?>"
                                        data-days="<?php echo $df['estimated_days']; ?>"
                                        <?php echo ($user_default_city === $df['city']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($df['city']); ?> (₱<?php echo number_format($df['fee_amount'], 2); ?> • <?php echo $df['estimated_days']; ?> day<?php echo $df['estimated_days'] > 1 ? 's' : ''; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <input type="text" id="dProvince" class="form-input" value="<?php echo htmlspecialchars($user_default_province ?: 'Cavite'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ZIP Code</label>
                        <input type="text" id="dZip" class="form-input" value="<?php echo htmlspecialchars($user_default_zip); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Landmark (Optional)</label>
                    <input type="text" id="dLandmark" class="form-input">
                </div>
                <div class="delivery-info-box" id="deliveryInfoBox" style="display:none;">
                    <i class="fas fa-truck"></i>
                    <div><strong id="deliveryInfoText"></strong><small id="deliveryEtaText"></small></div>
                </div>
            </div>

            <div class="form-group" id="paymentMethodSection" style="display:none;">
                <label class="form-label">Payment Method <span style="color:var(--danger)">*</span></label>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <label class="payment-method-option selected" data-method="online" onclick="selectPaymentMethod('online')">
                        <input type="radio" name="delivery_payment_method" value="online" checked style="display:none;">
                        <i class="fas fa-credit-card" style="font-size:20px; color:var(--primary);"></i>
                        <div style="flex:1;">
                            <strong style="font-size:14px; color:var(--text-primary);">Pay Online Now</strong>
                            <div style="font-size:12px; color:var(--text-secondary);">GCash, PayMaya, or Card</div>
                        </div>
                    </label>
                    <label class="payment-method-option cod-option" data-method="cod" onclick="selectPaymentMethod('cod')" style="display:none;">
                        <input type="radio" name="delivery_payment_method" value="cod" style="display:none;">
                        <i class="fas fa-money-bill-wave" style="font-size:20px; color:var(--warning);"></i>
                        <div style="flex:1;">
                            <strong style="font-size:14px; color:var(--text-primary);">Cash on Delivery (COD)</strong>
                            <div style="font-size:12px; color:var(--text-secondary);">Pay cash when you receive</div>
                        </div>
                    </label>
                    <label class="payment-method-option clinic-option" data-method="pay_at_clinic" onclick="selectPaymentMethod('pay_at_clinic')" style="display:none;">
                        <input type="radio" name="delivery_payment_method" value="pay_at_clinic" style="display:none;">
                        <i class="fas fa-store" style="font-size:20px; color:var(--primary);"></i>
                        <div style="flex:1;">
                            <strong style="font-size:14px; color:var(--text-primary);">Pay at Clinic</strong>
                            <div style="font-size:12px; color:var(--text-secondary);">Pay cash upon pickup</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Number <span style="color:var(--danger)">*</span></label>
                <input type="tel" id="mContact" class="form-input" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Notes (Optional)</label>
                <textarea id="mNotes" class="form-input" rows="2" placeholder="Any special requests..."></textarea>
            </div>

            <div class="modal-price-box" id="modalPriceBox">
                <div class="mpb-row"><span class="mpb-label">Product</span><span class="mpb-value">₱<?php echo number_format($display_price, 2); ?></span></div>
                <div class="mpb-row" id="mpbLensRow" style="display:none;"><span class="mpb-label">Lens</span><span class="mpb-value" id="mpbLensVal">+₱0</span></div>
                <div class="mpb-row" id="mpbCoatRow" style="display:none;"><span class="mpb-label">Coatings</span><span class="mpb-value" id="mpbCoatVal">+₱0</span></div>
                <div class="mpb-row" id="mpbDeliveryRow" style="display:none;"><span class="mpb-label">Delivery Fee</span><span class="mpb-value" id="mpbDeliveryVal" style="color:#F59E0B;">+₱0</span></div>
                <div class="mpb-divider"></div>
                <div class="mpb-row total"><span class="mpb-label">Total</span><span class="mpb-value" id="mpbTotal">₱<?php echo number_format($display_price, 2); ?></span></div>
                <div id="mpbPaymentInfo"></div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn-modal cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-modal confirm" id="confirmModalBtn" onclick="submitAction()">
                <i class="fas fa-check"></i> Confirm Order
            </button>
        </div>
    </div>
</div>

<script>
// ============================================
// STATE
// ============================================
let currentSlide = 0;
let selectedLens = null;
let selectedLensIndex = null;
let selectedLensIndexPrice = 0;
let selectedLensIndexData = null;
let selectedCoatings = [];
let rxKnowledge = null;
let userRx = { od_sph:null, od_cyl:null, od_axis:null, os_sph:null, os_cyl:null, os_axis:null };
let selectedColor = null;
let selectedSize = null;
let currentFulfillment = 'pickup';
let currentDeliveryFee = 0;
let currentEstimatedDays = 0;
let selectedDeliveryPayment = 'online';

// ============================================
// CONSTANTS (from PHP)
// ============================================
const BASE_PRICE = <?php echo $display_price; ?>;
const IS_ON_SALE = <?php echo $is_on_sale ? 'true' : 'false'; ?>;
const PAYMENT_TYPE = '<?php echo $payment_type_label; ?>';
const DOWNPAYMENT_PERCENT = <?php echo $downpayment_percent; ?>;
const BOOKING_FLOW = '<?php echo $clinic_booking_flow; ?>';
const IS_SERVICE = <?php echo $IS_SERVICE ? 'true' : 'false'; ?>;
const IS_ACCESSORY = <?php echo $IS_ACCESSORY ? 'true' : 'false'; ?>;
const IS_LENS_ONLY = <?php echo $IS_LENS_ONLY ? 'true' : 'false'; ?>;
const IS_CONTACT_LENS = <?php echo $IS_CONTACT_LENS ? 'true' : 'false'; ?>;
const NEEDS_LENS = <?php echo $NEEDS_LENS_SELECTION ? 'true' : 'false'; ?>;
const CLINIC_ID = <?php echo $clinic_id; ?>;
const PRODUCT_ID = <?php echo $product_id; ?>;
const HAS_DELIVERY = <?php echo ($offers_delivery && !$IS_SERVICE) ? 'true' : 'false'; ?>;
const ALLOW_COD = <?php echo $allow_cod ? 'true' : 'false'; ?>;
const FREE_DELIVERY_MIN = <?php echo $free_delivery_min; ?>;
const HAS_STOCK_TRACKING = <?php echo $has_stock_tracking ? 'true' : 'false'; ?>;
const IS_FULLY_SOLD_OUT = <?php echo $is_fully_sold_out ? 'true' : 'false'; ?>;

// ✅ Base prices per lens type (admin-managed sa future)
const LENS_BASE_PRICES = {
    frame_only: 0, single_vision: 500, progressive: 1500,
    blue_cut: 800, contact_daily: 0, contact_monthly: 0,
};

// ✅ DB-driven: lens index add-ons at coatings
const LENS_INDEX_ADDONS = <?php echo json_encode($lens_index_addons); ?>;
const LENS_COATINGS = <?php echo json_encode($lens_coatings); ?>;

// ============================================
// STEP 1: RX KNOWLEDGE
// ============================================
function handleRxKnowledge(radio) {
    rxKnowledge = radio.value;
    const rxFormBox = document.getElementById('rxFormBox');
    const rxExamBox = document.getElementById('rxExamBox');

    if (rxKnowledge === 'know') {
        rxFormBox.style.display = 'block';
        rxExamBox.style.display = 'none';
        // Unlock step 2
        unlockStep('stepLensType');
        document.getElementById('stepLensTypeSub').textContent = 'Pumili ng lens type';
    } else if (rxKnowledge === 'dont_know') {
        rxFormBox.style.display = 'none';
        rxExamBox.style.display = 'block';
        // Lock steps 2-4
        lockStep('stepLensType');
        lockStep('stepLensIndex');
        lockStep('stepCoatings');
        document.getElementById('stepLensTypeSub').textContent = 'Kailangan muna ng eye exam';
    }
    updateActionButton();
    updatePriceDisplay();
}

// ============================================
// VALIDATION
// ============================================
function onRxChange() {
    const val = id => {
        const el = document.getElementById(id);
        if (!el || el.value === '') return null;
        const n = parseFloat(el.value);
        return isNaN(n) ? null : n;
    };
    userRx = {
        od_sph: val('od_sph'), od_cyl: val('od_cyl'), od_axis: val('od_axis'),
        os_sph: val('os_sph'), os_cyl: val('os_cyl'), os_axis: val('os_axis'),
    };

    const valid = validateRx();
    if (valid && (userRx.od_sph !== null || userRx.os_sph !== null)) {
        // May valid RX na → i-unlock ang step 2
        unlockStep('stepLensType');
        document.getElementById('stepLensTypeSub').textContent = 'Pumili ng lens type';
    }
    updateRecommendedLens();
    updatePriceDisplay();
    updateActionButton();
}

function validateRx() {
    const msgEl = document.getElementById('rxValidationMsg');
    const errors = [];
    const warnings = [];

    const check = (sph, cyl, axis, side) => {
        if (sph !== null && (sph < -20 || sph > 20)) errors.push(`${side} SPH out of range (-20 to +20)`);
        if (cyl !== null && (cyl < -6 || cyl > 6)) errors.push(`${side} CYL out of range (-6 to +6)`);
        if (cyl !== null && cyl !== 0 && (axis === null || axis < 1 || axis > 180)) {
            errors.push(`${side} AXIS required (1-180) kapag may CYL`);
        }
    };
    check(userRx.od_sph, userRx.od_cyl, userRx.od_axis, 'Right Eye');
    check(userRx.os_sph, userRx.os_cyl, userRx.os_axis, 'Left Eye');

    const maxPower = Math.max(
        Math.abs(userRx.od_sph || 0) + Math.abs(userRx.od_cyl || 0),
        Math.abs(userRx.os_sph || 0) + Math.abs(userRx.os_cyl || 0)
    );
    if (maxPower > 8) warnings.push('Mataas ang grado mo — recommended ang 1.67 o 1.74 lens.');

    if (errors.length > 0) {
        msgEl.className = 'rx-validation-msg error';
        msgEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + errors.join(' • ');
        msgEl.style.display = 'flex';
        return false;
    }
    if (warnings.length > 0) {
        msgEl.className = 'rx-validation-msg warning';
        msgEl.innerHTML = '<i class="fas fa-info-circle"></i> ' + warnings.join(' ');
        msgEl.style.display = 'flex';
    } else {
        msgEl.style.display = 'none';
    }
    return true;
}

// ============================================
// COMPATIBILITY + RECOMMENDATION
// ============================================
function getMaxPower() {
    const od = Math.abs(userRx.od_sph || 0) + Math.abs(userRx.od_cyl || 0);
    const os = Math.abs(userRx.os_sph || 0) + Math.abs(userRx.os_cyl || 0);
    return Math.max(od, os);
}

function isIndexCompatible(addon) {
    const maxPower = getMaxPower();
    if (maxPower === 0) return true;
    // Pure DB-driven. Kung walang max sa DB, assume compatible.
    if (addon.max_sph === null || addon.max_sph === undefined) return true;
    const sphMax = Math.max(Math.abs(userRx.od_sph || 0), Math.abs(userRx.os_sph || 0));
    const cylMax = Math.max(Math.abs(userRx.od_cyl || 0), Math.abs(userRx.os_cyl || 0));
    if (sphMax > addon.max_sph) return false;
    if (addon.max_cyl !== null && addon.max_cyl !== undefined && cylMax > addon.max_cyl) return false;
    return true;
}

function getRecommendedIndex(filtered) {
    const maxPower = getMaxPower();
    if (maxPower === 0) return filtered[0]?.index_value;

    const compatible = filtered.filter(a => isIndexCompatible(a));
    if (compatible.length === 0) return null;

    let targetMin;
    if (maxPower <= 2) targetMin = 1.50;
    else if (maxPower <= 4) targetMin = 1.60;
    else if (maxPower <= 6) targetMin = 1.67;
    else if (maxPower <= 8) targetMin = 1.67;
    else targetMin = 1.74;

    const sorted = compatible
        .map(a => ({ ...a, idx: parseFloat(a.index_value) }))
        .filter(a => !isNaN(a.idx))
        .sort((a, b) => a.idx - b.idx);

    if (sorted.length === 0) return compatible[0]?.index_value;
    const match = sorted.find(a => a.idx >= targetMin);
    return (match || sorted[sorted.length - 1])?.index_value;
}

// ============================================
// STEP HELPERS
// ============================================
function unlockStep(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('locked'); el.classList.add('active'); }
}
function lockStep(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('locked'); el.classList.remove('active'); }
}
function completeStep(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('active'); el.classList.add('completed'); }
}

// ============================================
// STEP 2: LENS TYPE
// ============================================
function selectLens(btn) {
    if (!rxKnowledge) {
        showToast('Pumili muna ng prescription option sa Step 1.', 'error');
        return;
    }
    if (rxKnowledge === 'dont_know') {
        showToast('Kailangan mo muna ng eye exam.', 'error');
        return;
    }

    document.querySelectorAll('#lensGrid .lens-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLens = btn.dataset.lens;

    // Reset lower steps
    selectedLensIndex = null;
    selectedLensIndexPrice = 0;
    selectedLensIndexData = null;
    selectedCoatings = [];
    document.querySelectorAll('#coatingList input[type="checkbox"]').forEach(cb => cb.checked = false);

    completeStep('stepLensType');

    const excluded = ['frame_only', 'contact_daily', 'contact_monthly'];
    if (excluded.includes(selectedLens)) {
        lockStep('stepLensIndex');
        lockStep('stepCoatings');
        document.getElementById('lensIndexSub').textContent = 'Hindi applicable sa ' + selectedLens.replace('_', ' ');
    } else {
        updateRecommendedLens();
    }
    updatePriceDisplay();
    updateActionButton();
}

// ============================================
// STEP 3: LENS INDEX (auto-recommend)
// ============================================
function updateRecommendedLens() {
    const stepIdx = document.getElementById('stepLensIndex');
    const grid = document.getElementById('lensIndexGrid');
    const notice = document.getElementById('lensRecommendationNotice');
    const noticeText = document.getElementById('recNoticeText');
    const sub = document.getElementById('lensIndexSub');

    if (!selectedLens || ['frame_only', 'contact_daily', 'contact_monthly'].includes(selectedLens)) {
        lockStep('stepLensIndex');
        return;
    }
    if (userRx.od_sph === null && userRx.os_sph === null) {
        lockStep('stepLensIndex');
        sub.textContent = 'Kailangan muna ng prescription';
        return;
    }

    const filtered = LENS_INDEX_ADDONS.filter(a => a.lens_type === selectedLens);
    if (filtered.length === 0) {
        lockStep('stepLensIndex');
        sub.textContent = 'Walang available na thickness options';
        return;
    }

    unlockStep('stepLensIndex');
    sub.textContent = 'Auto-recommended base sa grado mo';

    const recommended = getRecommendedIndex(filtered);
    const maxPower = getMaxPower();

    if (recommended && maxPower > 0) {
        const recData = filtered.find(a => a.index_value === recommended);
        notice.style.display = 'flex';
        noticeText.innerHTML = `Base sa grado mo (<strong>${maxPower.toFixed(2)}</strong>), recommended ang <strong>${recommended} ${recData?.label || ''}</strong>.`;
    } else {
        notice.style.display = 'none';
    }

    grid.innerHTML = filtered.map(a => {
        const compatible = isIndexCompatible(a);
        const isRec = a.index_value === recommended && compatible;
        const classes = ['lens-btn'];
        if (!compatible) classes.push('unavailable');
        if (isRec) classes.push('recommended');
        if (a.index_value === selectedLensIndex) classes.push('selected');
        const priceTxt = a.price > 0 ? '+₱' + a.price.toLocaleString() : 'Included';
        return `
            <button type="button" class="${classes.join(' ')}"
                    data-index-value="${a.index_value}"
                    data-price="${a.price}"
                    data-id="${a.id}"
                    ${!compatible ? 'disabled' : ''}
                    onclick="selectLensIndex(this, event)">
                <span class="ln">${a.index_value} ${a.label || ''}</span>
                <span class="lp">${priceTxt}</span>
                <span class="ld">${a.label || ''} lens</span>
            </button>`;
    }).join('');

    // Auto-select recommended kung wala pang naka-select o incompatible na ang dating
    const stillValid = selectedLensIndex && filtered.some(
        a => a.index_value === selectedLensIndex && isIndexCompatible(a)
    );
    if (!stillValid) {
        selectedLensIndex = null; selectedLensIndexPrice = 0; selectedLensIndexData = null;
        if (recommended) {
            const recBtn = grid.querySelector(`.lens-btn[data-index-value="${recommended}"]`);
            if (recBtn && !recBtn.disabled) selectLensIndex(recBtn, null);
        }
    }
}

function selectLensIndex(btn, event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    if (btn.disabled || btn.classList.contains('unavailable')) return;

    document.querySelectorAll('#lensIndexGrid .lens-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedLensIndex = btn.dataset.indexValue;
    selectedLensIndexPrice = parseFloat(btn.dataset.price) || 0;
    selectedLensIndexData = { id: btn.dataset.id, index_value: selectedLensIndex, price: selectedLensIndexPrice };

    completeStep('stepLensIndex');

    // Unlock + filter coatings
    const stepCoatings = document.getElementById('stepCoatings');
    if (stepCoatings) {
        unlockStep('stepCoatings');
        filterCoatingsByLensType();
    }

    updatePriceDisplay();
    updateActionButton();
}

// ============================================
// STEP 4: COATINGS
// ============================================
function filterCoatingsByLensType() {
    document.querySelectorAll('.coating-item').forEach(item => {
        const applies = JSON.parse(item.dataset.applies || '[]');
        const matches = applies.length === 0 || applies.includes(selectedLens);
        item.style.display = matches ? 'flex' : 'none';
        if (!matches) {
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = false;
        }
    });
    onCoatingChange();
}

function onCoatingChange() {
    selectedCoatings = [];
    document.querySelectorAll('#coatingList input[type="checkbox"]:checked').forEach(cb => {
        selectedCoatings.push({
            id: cb.value,
            code: cb.dataset.code,
            price: parseFloat(cb.dataset.price) || 0,
        });
    });
    updatePriceDisplay();
}

// ============================================
// PRICE CALCULATION
// ============================================
function getLensPrice() {
    if (!selectedLens || selectedLens === 'frame_only') return 0;
    const base = LENS_BASE_PRICES[selectedLens] || 0;
    const idx = selectedLensIndexPrice || 0;
    return base + idx;
}
function getCoatingsPrice() {
    return selectedCoatings.reduce((s, c) => s + c.price, 0);
}
function getSubtotal() {
    return BASE_PRICE + getLensPrice() + getCoatingsPrice();
}

function updatePriceDisplay() {
    const lensPrice = getLensPrice();
    const coatPrice = getCoatingsPrice();
    const subtotal = BASE_PRICE + lensPrice + coatPrice;
    const deliveryFee = (currentFulfillment === 'delivery') ? currentDeliveryFee : 0;
    const total = subtotal + deliveryFee;

    // Price breakdown card
    const rows = document.getElementById('pbRows');
    if (rows) {
        let html = '';
        html += `<div class="pb-row"><span class="pb-label">${IS_ON_SALE ? 'Frame (sale)' : 'Frame'}</span><span class="pb-value">₱${BASE_PRICE.toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>`;

        if (selectedLens && selectedLens !== 'frame_only') {
            const lensLabel = document.querySelector(`#lensGrid [data-lens="${selectedLens}"] .ln`)?.textContent.trim() || '';
            const idxLabel = selectedLensIndex ? ` (${selectedLensIndex})` : '';
            if (lensPrice > 0) {
                html += `<div class="pb-row pb-addon"><span class="pb-label">${lensLabel} Lens${idxLabel}</span><span class="pb-value">₱${lensPrice.toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>`;
            }
        }
        selectedCoatings.forEach(c => {
            const label = document.querySelector(`[data-coating-code="${c.code}"] .coating-name`)?.textContent || c.code;
            html += `<div class="pb-row pb-addon"><span class="pb-label">${label}</span><span class="pb-value">₱${c.price.toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>`;
        });
        if (deliveryFee > 0) {
            html += `<div class="pb-row pb-addon"><span class="pb-label">Delivery Fee</span><span class="pb-value">₱${deliveryFee.toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>`;
        }
        rows.innerHTML = html;
    }
    const totalEl = document.getElementById('pbTotal');
    if (totalEl) totalEl.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});

    // Modal price box
    const mpbLensRow = document.getElementById('mpbLensRow');
    const mpbLensVal = document.getElementById('mpbLensVal');
    if (mpbLensRow) mpbLensRow.style.display = lensPrice > 0 ? 'flex' : 'none';
    if (mpbLensVal) mpbLensVal.textContent = '+₱' + lensPrice.toLocaleString('en-PH', {minimumFractionDigits:2});

    const mpbCoatRow = document.getElementById('mpbCoatRow');
    const mpbCoatVal = document.getElementById('mpbCoatVal');
    if (mpbCoatRow) mpbCoatRow.style.display = coatPrice > 0 ? 'flex' : 'none';
    if (mpbCoatVal) mpbCoatVal.textContent = '+₱' + coatPrice.toLocaleString('en-PH', {minimumFractionDigits:2});

    const mpbDelRow = document.getElementById('mpbDeliveryRow');
    const mpbDelVal = document.getElementById('mpbDeliveryVal');
    if (mpbDelRow) mpbDelRow.style.display = deliveryFee > 0 ? 'flex' : 'none';
    if (mpbDelVal) mpbDelVal.textContent = '+₱' + deliveryFee.toLocaleString('en-PH', {minimumFractionDigits:2});

    const mpbTotal = document.getElementById('mpbTotal');
    if (mpbTotal) mpbTotal.textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2});

    // Payment info (dp / bal)
    const info = document.getElementById('mpbPaymentInfo');
    if (info) {
        if (PAYMENT_TYPE === 'downpayment' && total > 0) {
            const dp = total * (DOWNPAYMENT_PERCENT / 100);
            const bal = total - dp;
            info.innerHTML = `<div class="mpb-divider"></div>
                <div class="mpb-row"><span class="mpb-label">Downpayment (${DOWNPAYMENT_PERCENT}%)</span><span class="mpb-value" style="color:#F59E0B;">₱${dp.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
                <div class="mpb-row"><span class="mpb-label">Balance (upon visit)</span><span class="mpb-value">₱${bal.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>`;
        } else if (PAYMENT_TYPE === 'full' && total > 0) {
            info.innerHTML = `<div class="mpb-divider"></div><div class="mpb-row"><span class="mpb-label">Full Payment Due</span><span class="mpb-value" style="color:#EF4444;">₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>`;
        } else if (PAYMENT_TYPE === 'onsite') {
            info.innerHTML = `<div class="mpb-divider"></div><div class="mpb-row"><span class="mpb-label">Pay at Clinic</span><span class="mpb-value" style="color:#F59E0B;">₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>`;
        } else {
            info.innerHTML = '';
        }
    }
}

// ============================================
// ACTION BUTTON
// ============================================
function updateActionButton() {
    const btn = document.getElementById('mainActionBtn');
    const helper = document.getElementById('actionHelper');
    if (!btn) return;

    // Kung tapos na o sold out, wag galawin
    if (btn.disabled && !helper) return;

    // Color requirement
    if (HAS_STOCK_TRACKING && !IS_FULLY_SOLD_OUT && !selectedColor) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Pumili ng color/variant sa taas.';
        return;
    }
    // Size requirement
    const hasSizeSelector = document.getElementById('sizeBtns') !== null;
    if (hasSizeSelector && !selectedSize && !IS_LENS_ONLY && !IS_CONTACT_LENS) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Pumili ng frame size sa taas.';
        return;
    }
    // RX
    if (!rxKnowledge) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Pumili sa Step 1: prescription option.';
        return;
    }
    if (rxKnowledge === 'dont_know') {
        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> Book Eye Exam';
        btn.className = 'btn-main-action appointment-style';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Book an eye exam first.';
        return;
    }
    // Lens type
    if (!selectedLens) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.className = 'btn-main-action';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Pumili ng lens type sa Step 2.';
        return;
    }
    // Frame only → pwede na agad
    if (selectedLens === 'frame_only' || IS_CONTACT_LENS) {
        btn.innerHTML = '<i class="fas fa-bookmark"></i> Order Now';
        btn.className = 'btn-main-action';
        btn.disabled = false;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Ready na — i-click para mag-order.';
        return;
    }
    // Kailangan ng lens index
    if (!selectedLensIndex) {
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continue';
        btn.className = 'btn-main-action';
        btn.disabled = true;
        if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Pumili ng lens thickness sa Step 3.';
        return;
    }
    // Ready na
    btn.innerHTML = '<i class="fas fa-bookmark"></i> Order Now';
    btn.className = 'btn-main-action';
    btn.disabled = false;
    if (helper) helper.innerHTML = '<i class="fas fa-info-circle"></i> Ready na — i-click para mag-order.';
}

// ============================================
// MAIN ACTION
// ============================================
function handleMainAction() {
    if (HAS_STOCK_TRACKING && !IS_FULLY_SOLD_OUT && !selectedColor) {
        showToast('Pumili ng color/variant first.', 'error');
        document.getElementById('colorBtns')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    const hasSizeSelector = document.getElementById('sizeBtns') !== null;
    if (hasSizeSelector && !selectedSize && !IS_LENS_ONLY && !IS_CONTACT_LENS) {
        showToast('Pumili ng frame size first.', 'error');
        document.getElementById('sizeBtns')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    if (rxKnowledge === 'dont_know') {
        const params = new URLSearchParams({
            clinic_id: CLINIC_ID, product_id: PRODUCT_ID, rx_knowledge: 'dont_know',
            lens: selectedLens || '', color: selectedColor?.code || ''
        });
        if (selectedLensIndex) params.set('lens_index', selectedLensIndex);
        window.location.href = 'book-specific-product.php?' + params.toString();
        return;
    }
    if (!selectedLens) {
        showToast('Pumili ng lens type sa Step 2.', 'error');
        document.getElementById('stepLensType')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    const needsIndex = !['frame_only', 'contact_daily', 'contact_monthly'].includes(selectedLens);
    if (needsIndex && !selectedLensIndex) {
        showToast('Pumili ng lens thickness sa Step 3.', 'error');
        document.getElementById('stepLensIndex')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    // I-save ang RX values
    window.pendingPrescription = {
        od_sph: document.getElementById('od_sph')?.value || '',
        od_cyl: document.getElementById('od_cyl')?.value || '',
        od_axis: document.getElementById('od_axis')?.value || '',
        os_sph: document.getElementById('os_sph')?.value || '',
        os_cyl: document.getElementById('os_cyl')?.value || '',
        os_axis: document.getElementById('os_axis')?.value || '',
    };
    openModal('reservation');
}

// ============================================
// MODAL
// ============================================
function openModal(type) {
    document.getElementById('scheduleModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    updatePriceDisplay();
    if (HAS_DELIVERY) selectFulfillment('pickup');
}
function closeModal() {
    document.getElementById('scheduleModal').classList.remove('show');
    document.body.style.overflow = '';
}

// ============================================
// FULFILLMENT / PAYMENT METHOD
// ============================================
function selectFulfillment(type) {
    currentFulfillment = type;
    document.querySelectorAll('.ff-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.ff-btn[data-ff="${type}"]`)?.classList.add('active');

    const pickupFields = document.getElementById('pickupFields');
    const deliveryFields = document.getElementById('deliveryFields');
    const paymentSection = document.getElementById('paymentMethodSection');
    const codOpt = document.querySelector('.payment-method-option.cod-option');
    const clinicOpt = document.querySelector('.payment-method-option.clinic-option');

    if (type === 'delivery') {
        pickupFields.style.display = 'none';
        deliveryFields.style.display = 'block';
        paymentSection.style.display = ALLOW_COD ? 'block' : 'none';
        if (codOpt) codOpt.style.display = ALLOW_COD ? 'flex' : 'none';
        if (clinicOpt) clinicOpt.style.display = 'none';
        if (ALLOW_COD && selectedDeliveryPayment !== 'cod') selectPaymentMethod('cod');
        else if (!ALLOW_COD && (selectedDeliveryPayment === 'cod' || selectedDeliveryPayment === 'pay_at_clinic')) selectPaymentMethod('online');
    } else {
        pickupFields.style.display = 'block';
        deliveryFields.style.display = 'none';
        paymentSection.style.display = 'block';
        if (codOpt) codOpt.style.display = 'none';
        if (clinicOpt) clinicOpt.style.display = 'flex';
        if (selectedDeliveryPayment !== 'pay_at_clinic') selectPaymentMethod('pay_at_clinic');
        currentDeliveryFee = 0; currentEstimatedDays = 0;
    }
    updatePriceDisplay();
}
function selectPaymentMethod(method) {
    selectedDeliveryPayment = method;
    document.querySelectorAll('.payment-method-option').forEach(opt => {
        opt.classList.toggle('selected', opt.dataset.method === method);
    });
    updatePriceDisplay();
}
function updateDeliveryFee() {
    const sel = document.getElementById('dCity');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const fee = parseFloat(opt.dataset.fee || 0);
    const days = parseInt(opt.dataset.days || 2);
    let finalFee = fee;
    const subtotal = BASE_PRICE + getLensPrice() + getCoatingsPrice();
    if (FREE_DELIVERY_MIN > 0 && subtotal >= FREE_DELIVERY_MIN) finalFee = 0;
    currentDeliveryFee = finalFee;
    currentEstimatedDays = days;

    const box = document.getElementById('deliveryInfoBox');
    const txt = document.getElementById('deliveryInfoText');
    const eta = document.getElementById('deliveryEtaText');
    if (box && txt) {
        if (sel.value) {
            box.style.display = 'flex';
            txt.textContent = finalFee > 0 ? 'Delivery Fee: ₱' + finalFee.toFixed(2) : 'FREE Delivery! 🎉';
            if (eta) eta.textContent = 'Estimated: ' + days + ' day' + (days > 1 ? 's' : '');
        } else box.style.display = 'none';
    }
    updatePriceDisplay();
}

// ============================================
// COLOR / SIZE
// ============================================
function selectColor(btn) {
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedColor = { code: btn.dataset.code, name: btn.dataset.name, qty: parseInt(btn.dataset.qty) };
    const label = document.getElementById('csSelectedLabel');
    if (label) { label.textContent = selectedColor.name + ' (' + selectedColor.qty + ' left)'; label.classList.add('chosen'); }
    document.getElementById('csHint').style.display = 'none';
    updateActionButton();
}
function selectSize(btn) {
    document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedSize = btn.dataset.size;
    const label = document.getElementById('sizeSelectedLabel');
    if (label) { label.textContent = selectedSize; label.classList.add('chosen'); }
    document.getElementById('sizeHint').style.display = 'none';
    updateActionButton();
}

// ============================================
// SUBMIT
// ============================================
function submitAction() {
    const contact = document.getElementById('mContact').value;
    const notes = document.getElementById('mNotes').value;
    if (!contact) { showToast('Enter your contact number.', 'error'); return; }

    const formData = new FormData();
    formData.append('ajax_action', 'reservation');
    formData.append('lens_type', selectedLens || 'frame_only');
    formData.append('lens_index', selectedLensIndex || '');
    formData.append('prescription_knowledge', rxKnowledge || '');
    formData.append('contact_number', contact);
    formData.append('notes', notes);
    formData.append('fulfillment_type', currentFulfillment);
    formData.append('coatings', JSON.stringify(selectedCoatings.map(c => c.id)));

    if (currentFulfillment === 'delivery' && HAS_DELIVERY) {
        const dName = document.getElementById('dName')?.value || '';
        const dPhone = document.getElementById('dPhone')?.value || '';
        const dAddress = document.getElementById('dAddress')?.value || '';
        const dBarangay = document.getElementById('dBarangay')?.value || '';
        const dCity = document.getElementById('dCity')?.value || '';
        const dProvince = document.getElementById('dProvince')?.value || '';
        const dZip = document.getElementById('dZip')?.value || '';
        const dLandmark = document.getElementById('dLandmark')?.value || '';
        if (!dName) { showToast('Recipient name required.', 'error'); return; }
        if (!dPhone) { showToast('Recipient phone required.', 'error'); return; }
        if (!dAddress) { showToast('Address required.', 'error'); return; }
        if (!dCity) { showToast('City required.', 'error'); return; }
        formData.append('delivery_name', dName);
        formData.append('delivery_phone', dPhone);
        formData.append('delivery_address', dAddress);
        formData.append('delivery_barangay', dBarangay);
        formData.append('delivery_city', dCity);
        formData.append('delivery_province', dProvince);
        formData.append('delivery_zip', dZip);
        formData.append('delivery_landmark', dLandmark);
        formData.append('delivery_payment_method', selectedDeliveryPayment);
    } else {
        const date = document.getElementById('mDate').value;
        const time = document.getElementById('mTime').value;
        if (!date) { showToast('Select a date.', 'error'); return; }
        if (!time) { showToast('Select a time.', 'error'); return; }
        formData.append('preferred_date', date);
        formData.append('preferred_time', time);
        formData.append('delivery_payment_method', selectedDeliveryPayment);
    }

    if (selectedColor) {
        formData.append('color_code', selectedColor.code);
        formData.append('color_name', selectedColor.name);
    }
    if (selectedSize) formData.append('frame_size', selectedSize);

    if (rxKnowledge === 'know' && window.pendingPrescription) {
        Object.keys(window.pendingPrescription).forEach(k => {
            formData.append(k, window.pendingPrescription[k]);
        });
    }

    closeModal();
    showLoading();

    fetch('product-view.php?id=<?php echo $product_id; ?>', { method: 'POST', body: formData })
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
            console.error(err);
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

function toggleAccordion(contentId, iconId) {
    const c = document.getElementById(contentId);
    const i = document.getElementById(iconId);
    if (!c || !i) return;
    const isOpen = c.style.display === 'block';
    c.style.display = isOpen ? 'none' : 'block';
    i.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

function goBack() {
    if (document.referrer && document.referrer.indexOf(window.location.hostname) !== -1) window.history.back();
    else window.location.href = 'clinic-details.php?id=<?php echo $clinic_id; ?>';
}

// ============================================
// SLIDER
// ============================================
const TOTAL_SLIDES = <?php echo count($product_images); ?>;
const lbImages = <?php echo json_encode($product_images); ?>;
let lbCurrentIndex = 0;

function initSlider() {
    if (TOTAL_SLIDES <= 1) return;
    const dotsEl = document.getElementById('sliderDots');
    if (dotsEl) {
        for (let i = 0; i < TOTAL_SLIDES; i++) {
            const btn = document.createElement('button');
            btn.className = 'swiper-pagination-bullet' + (i === 0 ? ' swiper-pagination-bullet-active' : '');
            btn.addEventListener('click', e => { e.stopPropagation(); goToSlide(i); });
            dotsEl.appendChild(btn);
        }
    }
    updateSlider();
}
function updateSlider() {
    const w = document.getElementById('sliderWrapper');
    if (w) w.style.transform = 'translateX(' + (-currentSlide * 100) + '%)';
    document.querySelectorAll('.swiper-pagination-bullet').forEach((b, i) => {
        b.classList.toggle('swiper-pagination-bullet-active', i === currentSlide);
    });
    document.querySelectorAll('.thumb').forEach((t, i) => t.classList.toggle('active', i === currentSlide));
}
function goToSlide(i) {
    currentSlide = ((i % TOTAL_SLIDES) + TOTAL_SLIDES) % TOTAL_SLIDES;
    updateSlider();
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
function lbGoTo(i) {
    lbCurrentIndex = ((i % lbImages.length) + lbImages.length) % lbImages.length;
    lbRender();
    goToSlide(lbCurrentIndex);
}
function lbRender() {
    const img = document.getElementById('lbMainImg');
    if (img) img.src = lbImages[lbCurrentIndex];
    document.querySelectorAll('.lk-lb-thumb').forEach((t, i) => t.classList.toggle('active', i === lbCurrentIndex));
    const c = document.getElementById('lbCounter');
    if (c) c.textContent = (lbCurrentIndex + 1) + ' / ' + lbImages.length;
}

// ============================================
// FAVORITE
// ============================================
function toggleProductFav(btn) {
    const productId = btn.dataset.productId;
    const isActive = btn.classList.contains('active');
    const fd = new FormData();
    fd.append('product_id', productId);
    btn.classList.toggle('active');
    const icon = btn.querySelector('i');
    const label = btn.querySelector('span');
    icon.className = btn.classList.contains('active') ? 'fas fa-heart' : 'far fa-heart';
    label.textContent = btn.classList.contains('active') ? 'Saved to Favorites' : 'Save to Favorites';
    fetch('toggle-product-favorite.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error();
            showToast(data.action === 'added' ? '❤️ Added!' : 'Removed', data.action === 'added' ? 'success' : 'info');
        })
        .catch(() => {
            btn.classList.toggle('active');
            icon.className = isActive ? 'fas fa-heart' : 'far fa-heart';
            label.textContent = isActive ? 'Saved to Favorites' : 'Save to Favorites';
            showToast('Something went wrong.', 'error');
        });
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    initSlider();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const mDate = document.getElementById('mDate');
    if (mDate) mDate.value = tomorrow.toISOString().split('T')[0];

    const dCity = document.getElementById('dCity');
    if (dCity && dCity.value) updateDeliveryFee();

    updatePriceDisplay();
    updateActionButton();
});

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    if (document.getElementById('lkLightbox').classList.contains('show')) {
        if (e.key === 'ArrowRight') lbGoTo(lbCurrentIndex + 1);
        if (e.key === 'ArrowLeft') lbGoTo(lbCurrentIndex - 1);
        if (e.key === 'Escape') closeLightbox();
    } else if (e.key === 'Escape') closeModal();
});
document.getElementById('scheduleModal')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});
</script>
</body>
</html>