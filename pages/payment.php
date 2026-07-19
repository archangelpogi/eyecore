<?php

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id        = $_SESSION['user_id'];
$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$reservation_id = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;

// ============================================
// IF RESERVATION ID IS PROVIDED
// ============================================
if ($reservation_id > 0 && $appointment_id == 0) {

    $res_query = mysqli_query($conn, "
        SELECT r.*, 
               p.name as product_name,
               p.price as product_price,
               p.category as product_category,
               p.image as product_image,
               p.images as product_images_old,
               p.images_json as product_images_json,
               c.name as clinic_name,
               c.address as clinic_address,
               c.contact as clinic_contact,
               c.hours as clinic_hours,
               u.first_name,
               u.last_name,
               u.email,
               u.contact as user_contact
        FROM reservations r
        JOIN products p ON r.product_id = p.id
        JOIN clinics c ON r.clinic_id = c.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = $reservation_id AND r.user_id = $user_id
    ");

    $reservation = mysqli_fetch_assoc($res_query);

    if (!$reservation) {
        header('Location: my-reservations.php?error=reservation_not_found');
        exit();
    }

    $product_image = null;
    if (!empty($reservation['product_images_json'])) {
        $imgs = json_decode($reservation['product_images_json'], true);
        if (!empty($imgs[0])) $product_image = $imgs[0];
    }
    if (!$product_image && !empty($reservation['product_images_old'])) {
        if (strpos($reservation['product_images_old'], '[') === 0) {
            $imgs = json_decode($reservation['product_images_old'], true);
            if (!empty($imgs[0])) $product_image = $imgs[0];
        } else {
            $product_image = $reservation['product_images_old'];
        }
    }
    if (!$product_image && !empty($reservation['product_image'])) {
        $product_image = $reservation['product_image'];
    }

    $appointment = [
        'id'                 => $reservation['id'],
        'clinic_id'          => $reservation['clinic_id'],
        'clinic_name'        => $reservation['clinic_name'],
        'clinic_address'     => $reservation['clinic_address'],
        'clinic_contact'     => $reservation['clinic_contact'],
        'clinic_hours'       => $reservation['clinic_hours'],
        'item_type'          => 'product',
        'item_id'            => $reservation['product_id'],
        'item_name'          => $reservation['product_name'],
        'item_price'         => $reservation['product_price'],
        'item_category'      => $reservation['product_category'],
        'item_image'         => $product_image,
        'first_name'         => $reservation['first_name'],
        'last_name'          => $reservation['last_name'],
        'email'              => $reservation['email'],
        'user_contact'       => $reservation['user_contact'],
        'appointment_date'   => $reservation['preferred_date'],
        'appointment_time'   => $reservation['preferred_time'],
        'status'             => $reservation['status'],
        'payment_status'     => $reservation['payment_status'],
        'total_amount'       => $reservation['total_amount'],
        'downpayment_amount' => $reservation['downpayment_amount'],
        'balance_amount'     => $reservation['balance_amount'],
        'ref_no'             => $reservation['reservation_code'],
        'doctor_name'        => null,
    ];

    $appointment_id = $reservation['id'];

} else {

$query = mysqli_query($conn, "
    SELECT a.*,
           c.name      AS clinic_name,
           c.address   AS clinic_address,
           c.contact   AS clinic_contact,
           c.hours     AS clinic_hours,
           d.name      AS doctor_name,
           d.specialty AS doctor_specialty,
           u.first_name,
           u.last_name,
           u.email,
           u.contact   AS user_contact,
           p.name        AS item_name,
           p.price       AS item_price,
           p.category    AS item_category,
           p.image       AS product_image,
           p.images      AS product_images_old,
           p.images_json AS product_images_json,
           a.lens_type   AS lens_type
    FROM appointments a
    LEFT JOIN clinics  c ON a.clinic_id  = c.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    LEFT JOIN users    u ON a.user_id    = u.id
    LEFT JOIN products p ON a.product_id = p.id
    WHERE a.id = $appointment_id
      AND a.user_id = $user_id
    LIMIT 1
");

$appointment = mysqli_fetch_assoc($query);

if (!$appointment) {
    header('Location: my-appointments.php');
    exit();
}

// ============================================
// ✅ FIX: GET CORRECT PRICE IF MISSING
// ============================================
if (empty($appointment['item_price']) || $appointment['item_price'] <= 0) {
    // Try to get price from products table using product_id
    if (!empty($appointment['product_id']) && $appointment['product_id'] > 0) {
        $price_q = mysqli_query($conn, "SELECT name, price FROM products WHERE id = {$appointment['product_id']}");
        $price_d = mysqli_fetch_assoc($price_q);
        $appointment['item_price'] = $price_d['price'] ?? 0;
        if (empty($appointment['item_name'])) {
            $appointment['item_name'] = $price_d['name'] ?? 'Product';
        }
    }
    // Try to get price from services table using item_id
    elseif (!empty($appointment['item_id']) && $appointment['item_type'] == 'service') {
        $price_q = mysqli_query($conn, "SELECT name, price FROM services WHERE id = {$appointment['item_id']}");
        $price_d = mysqli_fetch_assoc($price_q);
        $appointment['item_price'] = $price_d['price'] ?? 0;
        if (empty($appointment['item_name'])) {
            $appointment['item_name'] = $price_d['name'] ?? 'Service';
        }
    }
    // Fallback to total_amount
    elseif (!empty($appointment['total_amount']) && $appointment['total_amount'] > 0) {
        $appointment['item_price'] = $appointment['total_amount'];
    }
    // Last resort default
    else {
        $appointment['item_price'] = 500; // Default consultation fee
        if (empty($appointment['item_name'])) {
            $appointment['item_name'] = 'Consultation';
        }
    }
}

// Set item fields
$appointment['item_type'] = !empty($appointment['product_id']) ? 'product' : ($appointment['item_type'] ?? 'service');
$appointment['item_id'] = $appointment['product_id'] ?? $appointment['item_id'] ?? null;
$appointment['item_category'] = $appointment['item_category'] ?? ($appointment['item_type'] == 'service' ? 'Service' : 'Product');

// Resolve product image
$product_image = null;
if (!empty($appointment['product_images_json'])) {
    $imgs = json_decode($appointment['product_images_json'], true);
    if (!empty($imgs[0])) $product_image = $imgs[0];
}
if (!$product_image && !empty($appointment['product_images_old'])) {
    if (strpos($appointment['product_images_old'], '[') === 0) {
        $imgs = json_decode($appointment['product_images_old'], true);
        if (!empty($imgs[0])) $product_image = $imgs[0];
    } else {
        $product_image = $appointment['product_images_old'];
    }
}
if (!$product_image && !empty($appointment['product_image'])) {
    $product_image = $appointment['product_image'];
}
$appointment['item_image'] = $product_image;
}

// ============================================
// FALLBACK: If still no price, query directly
// ============================================
if (empty($appointment['item_price']) || $appointment['item_price'] <= 0) {
    $pid = (int)($appointment['item_id'] ?? $appointment['product_id'] ?? 0);
    if ($pid > 0) {
        $price_q = mysqli_query($conn, "SELECT price, name FROM products WHERE id = $pid");
        $price_d = mysqli_fetch_assoc($price_q);
        $appointment['item_price'] = $price_d['price'] ?? 0;
        if (empty($appointment['item_name'])) {
            $appointment['item_name'] = $price_d['name'] ?? 'Unknown Item';
        }
    }
}

// ============================================
// DYNAMIC PAYMENT CALCULATION
// ============================================
$payment_info = calculatePaymentAmounts(
    $conn,
    $appointment['clinic_id'],
    $appointment['item_price']
);

$total_amount       = $payment_info['total_amount'];
$downpayment_amount = $payment_info['downpayment_amount'];
$balance_amount     = $payment_info['balance_amount'];
$payment_type_label = $payment_info['payment_type'];
$requires_payment   = $payment_info['requires_payment'];
$booking_flow       = $payment_info['booking_flow'] ?? 'approve_first';

// ============================================
// CHECK IF APPROVE FIRST AND NOT YET APPROVED
// ============================================
if ($reservation_id == 0) {
    // Statuses that are allowed to proceed to payment:
    // - 'approved' : Clinic approved the appointment, waiting for payment
    // - 'waiting_payment' : Consultation completed, waiting for payment
    $allowed_payment_statuses = ['approved', 'waiting_payment'];
    
    if ($booking_flow === 'approve_first' && !in_array($appointment['status'], $allowed_payment_statuses)) {
        // If status is 'pending', redirect to waiting approval page
        if ($appointment['status'] == 'pending') {
            header('Location: my-appointments.php?msg=waiting_approval');
        } else {
            // Other unexpected status
            header('Location: my-appointments.php?msg=invalid_status');
        }
        exit();
    }
}
// ============================================
// IF NO PAYMENT REQUIRED, REDIRECT
// ============================================
if (!$requires_payment) {
    if ($reservation_id > 0) {
        header('Location: my-reservations.php?msg=no_payment_required');
    } else {
        header('Location: my-appointments.php?msg=no_payment_required');
    }
    exit();
}

// ============================================
// CHECK IF ALREADY PAID
// ============================================
if (in_array($appointment['payment_status'], ['paid', 'downpayment_paid'])) {
    $existing_ref  = $appointment['downpayment_ref'] ?? $appointment['ref_no'] ?? '';
    $existing_type = ($appointment['payment_status'] == 'downpayment_paid') ? 'downpayment' : 'full';
    if ($reservation_id > 0) {
        header('Location: payment-success.php?reservation_id=' . $appointment_id . '&ref_no=' . $existing_ref . '&type=' . $existing_type);
    } else {
        header('Location: payment-success.php?appointment_id=' . $appointment_id . '&ref_no=' . $existing_ref . '&type=' . $existing_type);
    }
    exit();
}

$error = '';

// ============================================
// VALIDATE AMOUNT
// ============================================
if ($downpayment_amount <= 0) {
    $error  = "Invalid payment amount (₱" . number_format($downpayment_amount, 2) . "). ";
    $error .= "Please contact support. (Ref: " . ($reservation_id > 0 ? 'RES-' : 'APP-') . $appointment_id . ")";
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $payment_method = $_POST['payment_method'] ?? '';

    if (empty($payment_method)) {
        $error = "Please select a payment method.";
    } elseif ($downpayment_amount <= 0) {
        $error = "Invalid payment amount.";
    } else {
        $ref_no = 'EYE-' . strtoupper(uniqid());

        if ($reservation_id > 0) {
            mysqli_query($conn, "UPDATE reservations SET ref_no = '$ref_no' WHERE id = $appointment_id");
        } else {
            $check_col = mysqli_query($conn, "SHOW COLUMNS FROM appointments LIKE 'ref_no'");
            if (mysqli_num_rows($check_col) > 0) {
                mysqli_query($conn, "UPDATE appointments SET ref_no = '$ref_no' WHERE id = $appointment_id");
            }
        }

        if ($payment_type_label === 'full') {
            $charge_label = "Full Payment for {$appointment['item_name']} at {$appointment['clinic_name']}";
        } else {
            $percent = ($total_amount > 0) ? round(($downpayment_amount / $total_amount) * 100) : 0;
            $charge_label = "{$percent}% Downpayment for {$appointment['item_name']} at {$appointment['clinic_name']}";
        }

        $secret_key = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";

        $checkoutData = [
            "data" => [
                "attributes" => [
                    "line_items" => [[
                        "currency"    => "PHP",
                        "amount"      => intval($downpayment_amount * 100),
                        "name"        => $appointment['item_name'] . " ({$charge_label})",
                        "description" => $charge_label,
                        "quantity"    => 1
                    ]],
                    "payment_method_types" => [
                        $payment_method == 'gcash'     ? 'gcash'    :
                        ($payment_method == 'grab_pay' ? 'grab_pay' : 'card')
                    ],
                    "success_url" => "http://" . $_SERVER['HTTP_HOST']
                                   . "/pages/payment-success.php"
                                   . "?ref_no={$ref_no}"
                                   . "&" . ($reservation_id > 0 ? "reservation_id" : "appointment_id") . "={$appointment_id}"
                                   . "&type={$payment_type_label}",
                    "cancel_url"  => "http://" . $_SERVER['HTTP_HOST']
                                   . "/pages/payment.php"
                                   . "?" . ($reservation_id > 0 ? "reservation_id" : "appointment_id") . "={$appointment_id}",
                    "description"        => $charge_label,
                    "reference_number"   => $ref_no,
                    "billing" => [
                        "name"  => trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')),
                        "email" => $appointment['email']        ?? '',
                        "phone" => $appointment['user_contact'] ?? ''
                    ],
                    "send_email_receipt" => true,
                    "show_description"   => true,
                    "show_line_items"    => true,
                    "metadata" => [
                        ($reservation_id > 0 ? "reservation_id" : "appointment_id") => $appointment_id,
                        "payment_type" => $payment_type_label,
                        "total_amount" => $total_amount,
                        "balance"      => $balance_amount,
                        "source"       => $reservation_id > 0 ? 'reservation' : 'appointment'
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.paymongo.com/v1/checkout_sessions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkoutData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Basic " . base64_encode($secret_key . ":")
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['errors'])) {
            $error = "PayMongo Error: " . $result['errors'][0]['detail'];
        } else {
            $session_id   = $result['data']['id'];
            $checkout_url = $result['data']['attributes']['checkout_url'];

            if ($reservation_id > 0) {
                mysqli_query($conn, "
                    INSERT INTO payments
                    (reservation_id, user_id, clinic_id, amount, payment_method,
                     payment_status, reference_number, paymongo_session_id, payment_type, created_at)
                    VALUES (
                        $appointment_id, $user_id, {$appointment['clinic_id']},
                        $downpayment_amount, '$payment_method', 'pending',
                        '$ref_no', '$session_id', '$payment_type_label', NOW()
                    )
                ");
                mysqli_query($conn, "
                    UPDATE reservations
                    SET payment_status = 'pending', updated_at = NOW()
                    WHERE id = $appointment_id
                ");
                addNotification($user_id, 'reservation', 'Payment Processing',
                    "Your payment of ₱" . number_format($downpayment_amount, 2)
                    . " for {$appointment['item_name']} at {$appointment['clinic_name']} is being processed.",
                    'my-reservations.php');
            } else {
                mysqli_query($conn, "
                    INSERT INTO payments
                    (appointment_id, user_id, clinic_id, amount, payment_method,
                     payment_status, reference_number, paymongo_session_id, payment_type, created_at)
                    VALUES (
                        $appointment_id, $user_id, {$appointment['clinic_id']},
                        $downpayment_amount, '$payment_method', 'pending',
                        '$ref_no', '$session_id', '$payment_type_label', NOW()
                    )
                ");
                mysqli_query($conn, "
                    UPDATE appointments
                    SET payment_status     = 'downpayment_pending',
                        downpayment_amount = $downpayment_amount,
                        downpayment_ref    = '$ref_no',
                        balance_amount     = $balance_amount,
                        total_amount       = $total_amount
                    WHERE id = $appointment_id
                ");
                addNotification($user_id, 'appointment', 'Payment Processing',
                    "Your payment of ₱" . number_format($downpayment_amount, 2)
                    . " for {$appointment['item_name']} at {$appointment['clinic_name']} is being processed.",
                    'my-appointments.php');
            }

            header("Location: $checkout_url");
            exit();
        }
    }
}

// ============================================
// SIDEBAR VARIABLES
// ============================================
$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data    = mysqli_fetch_assoc($avatar_query);

$pending_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending       = mysqli_fetch_assoc($pending_count)['total'];

$unread_count         = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count       = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$total_points = mysqli_fetch_assoc($points_query)['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($bookings_query)['total'] ?: 0;

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user       = mysqli_fetch_assoc($user_query);

$display_percent = ($total_amount > 0) ? round(($downpayment_amount / $total_amount) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Payment - Eyecore</title>
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
            --balance: #FF8C42;
            
            /* Payment method colors */
            --gcash: #0057e0;
            --gcash-light: #e6f0ff;
            --paymaya: #ff4d4d;
            --paymaya-light: #ffe6e6;
            --grab: #00b14f;
            --grab-light: #e0ffe8;
            --card: #6f42c1;
            --card-light: #f0e6ff;
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
            
            --gcash: #1a73e8;
            --gcash-light: #1e3a5a;
            --paymaya: #ff6666;
            --paymaya-light: #5a2d2d;
            --grab: #00cc66;
            --grab-light: #1e4a2d;
            --card: #8b5cf6;
            --card-light: #3a2d5a;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

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

        /* Search Bar */
        .search-container {
            position: relative;
            width: 280px;
        }

        .search-container i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        .search-container input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 40px;
            font-size: 14px;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .search-container input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
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

        /* Profile Dropdown */
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
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
            flex: 1;
            margin-left: 0;
            background: var(--bg-primary);
            min-height: 100vh;
        }

        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        @media (min-width: 1024px) {
            .content-wrapper {
                padding: 30px 40px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
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
            gap: 20px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
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

        .back-link {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            font-size: 14px;
        }

        .back-link a:hover {
            background: var(--primary);
            color: white;
            transform: translateX(-5px);
        }

        /* ===== PAYMENT GRID ===== */
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 768px) {
            .payment-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== ORDER SUMMARY CARD ===== */
        .order-summary {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .order-summary h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .order-summary h2 i {
            color: var(--primary);
        }

        .clinic-info {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .clinic-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .clinic-details h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .clinic-details p {
            color: var(--text-secondary);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .clinic-details p i {
            color: var(--primary);
            font-size: 12px;
        }

        .product-details {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .product-image {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image .placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-primary);
            color: var(--primary);
            font-size: 20px;
        }

        .product-info-details {
            flex: 1;
        }

        .product-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .product-category {
            font-size: 12px;
            color: var(--primary);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .doctor-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border-color);
        }

        .doctor-info i {
            color: var(--primary);
            font-size: 14px;
        }

        .doctor-info span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .doctor-info strong {
            color: var(--text-primary);
        }

        .order-items {
            margin-bottom: 15px;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
        }

        .order-item:last-child {
            border-bottom: none;
        }

        .order-total {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            margin-top: 10px;
            border-top: 2px solid var(--border-color);
            font-size: 20px;
            font-weight: 700;
        }

        .total-label {
            color: var(--text-primary);
        }

        .total-amount {
            color: var(--success);
        }

        .appointment-dates {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 15px;
            margin-top: 20px;
        }

        .date-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .date-row:last-child {
            margin-bottom: 0;
        }

        .date-row i {
            color: var(--primary);
            width: 20px;
        }

        .date-row strong {
            color: var(--text-primary);
            margin-left: auto;
        }

        /* ===== DOWNPAYMENT SPECIFIC STYLES ===== */
        .payment-breakdown {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--bg-primary) 100%);
            border-radius: var(--radius-md);
            padding: 20px;
            margin: 20px 0;
            border: 2px solid var(--primary);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-label {
            font-weight: 600;
            color: var(--text-primary);
        }

        .breakdown-value {
            font-weight: 700;
        }

        .breakdown-value.total {
            color: var(--primary);
            font-size: 18px;
        }

        .breakdown-value.downpayment {
            color: var(--success);
            font-size: 18px;
        }

        .breakdown-value.balance {
            color: var(--balance);
        }

        .downpayment-badge {
            display: inline-block;
            background: var(--success);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .balance-badge {
            display: inline-block;
            background: var(--balance);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .info-box {
            background: var(--bg-primary);
            border-left: 4px solid var(--info);
            padding: 15px;
            border-radius: var(--radius-md);
            margin: 20px 0;
        }

        .info-box i {
            color: var(--info);
            margin-right: 10px;
        }

        /* ===== PAYMENT METHODS CARD ===== */
        .payment-methods-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .payment-methods-card h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-methods-card h2 i {
            color: var(--primary);
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .payment-option {
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .payment-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .payment-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .payment-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .payment-option i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        .payment-option.gcash i { color: var(--gcash); }
        .payment-option.paymaya i { color: var(--paymaya); }
        .payment-option.grab i { color: var(--grab); }
        .payment-option.card i { color: var(--card); }

        .payment-option span {
            font-weight: 600;
            font-size: 14px;
        }

        .payment-option small {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 5px;
        }

        .payment-form {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid var(--border-light);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 8px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            transition: all 0.2s;
            background: var(--input-bg, var(--bg-primary));
            color: var(--text-primary);
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .form-control[readonly] {
            background: var(--disabled-bg, var(--bg-primary));
            opacity: 0.7;
            cursor: not-allowed;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn-pay {
            width: 100%;
            padding: 15px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-pay:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-pay.gcash { background: var(--gcash); }
        .btn-pay.paymaya { background: var(--paymaya); }
        .btn-pay.grab { background: var(--grab); }
        .btn-pay.card { background: var(--card); }

        .secure-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            margin-top: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .secure-badge i {
            color: var(--success);
            font-size: 20px;
        }

        .test-creds {
            margin-top: 15px;
            padding: 15px;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            border-left: 4px solid var(--warning);
            font-size: 13px;
        }

        .test-creds p {
            margin-bottom: 8px;
            color: var(--warning);
            font-weight: 600;
        }

        .test-creds ul {
            color: var(--text-secondary);
            padding-left: 20px;
        }

        .test-creds li {
            margin-bottom: 4px;
        }

        /* ===== ALERT MESSAGES ===== */
        .alert {
            padding: 15px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: var(--cancelled-bg, #f8d7da);
            color: var(--cancelled-text, #721c24);
            border: 1px solid var(--cancelled-text, #f5c6cb);
        }

        .theme-dark .alert-error {
            background: #4d2d2d;
            color: #ff9999;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .theme-dark .alert-info {
            background: #1e4a5a;
            color: #7ac9e0;
        }

        .alert-success {
            background: var(--confirmed-bg, #d4edda);
            color: var(--confirmed-text, #155724);
            border: 1px solid var(--confirmed-text, #c3e6cb);
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ===== NOTIFICATION DROPDOWN (DESKTOP) ===== */
        .notification-dropdown {
            position: relative;
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
            font-size: 13px;
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
    </style>
</head>
<body>
    <!-- DESKTOP NAVBAR -->
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
                                        <div class="notification-title"><?php echo $notif['title']; ?></div>
                                        <?php if ($notif['message']): ?>
                                            <div class="notification-message"><?php echo $notif['message']; ?></div>
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
            
            <!-- Profile Dropdown -->
            <div class="profile-dropdown">
                <div class="profile-trigger" onclick="toggleProfileMenu()">
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')); ?></div>
                        <div class="profile-points"><?php echo $total_points; ?> pts</div>
                    </div>
                    <div class="profile-avatar">
                        <?php 
                        $avatar = $user_data['avatar'] ?? null;
                        if ($avatar && file_exists("../assets/images/profiles/$avatar")): 
                        ?>
                            <img src="../assets/images/profiles/<?php echo $avatar; ?>" alt="Profile">
                        <?php else: 
                            $full_name = ($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '');
                            $first_letter = strtoupper(substr(trim($full_name), 0, 1)) ?: 'U';
                        ?>
                            <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; color: white;">
                                <?php echo $first_letter; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-menu" id="profileMenu">
                    <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                    <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
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
                                        <div class="notification-title"><?php echo $notif['title']; ?></div>
                                        <?php if ($notif['message']): ?>
                                            <div class="notification-message"><?php echo $notif['message']; ?></div>
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
                        $full_name = ($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '');
                        $first_letter = strtoupper(substr(trim($full_name), 0, 1)) ?: 'U';
                    ?>
                        <div style="width: 100%; height: 100%; background: var(--primary-gradient); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: white;">
                            <?php echo $first_letter; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')); ?></h4>
                    <p><?php echo $total_points; ?> points • <?php echo $total_bookings; ?> bookings</p>
                </div>
            </div>
            <button onclick="closeMobileMenu()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="mobile-menu-items">
            <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
            <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="sale-products.php"><i class="fas fa-tags" style="color: var(--danger);"></i> Hot Sales</a>
            <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
            <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- FLOATING ACTION BUTTON WITH MENU -->
    <div class="fab" onclick="toggleFabMenu()" id="fab">
        <i class="fas fa-plus"></i>
    </div>
    <div class="fab-menu" id="fabMenu">
        <a href="book-appointment.php" class="fab-menu-item" data-tooltip="Book New Appointment">
            <i class="fas fa-calendar-plus"></i>
        </a>
        <a href="dashboard.php" class="fab-menu-item" data-tooltip="Browse Clinics">
            <i class="fas fa-search"></i>
        </a>
        <a href="nearby.php" class="fab-menu-item" data-tooltip="Nearby Clinics">
            <i class="fas fa-location-dot"></i>
        </a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="content-wrapper">
            <!-- Back Button -->
            <div class="back-link">
                <?php if ($reservation_id > 0): ?>
                    <a href="my-reservations.php">
                        <i class="fas fa-arrow-left"></i> Back to My Reservations
                    </a>
                <?php else: ?>
                    <a href="my-appointments.php">
                        <i class="fas fa-arrow-left"></i> Back to My Appointments
                    </a>
                <?php endif; ?>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="fas fa-credit-card"></i>
                    Complete Payment
                    <?php if ($payment_type_label === 'downpayment'): ?>
                        (<?php echo $display_percent; ?>% Downpayment)
                    <?php elseif ($payment_type_label === 'full'): ?>
                        (Full Payment)
                    <?php endif; ?>
                </h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Payment Grid -->
            <div class="payment-grid">
                <!-- Order Summary -->
                <div class="order-summary">
                    <h2><i class="fas fa-receipt"></i> Order Summary</h2>
                    
                    <div class="clinic-info">
                        <div class="clinic-icon">
                            <i class="fas fa-clinic-medical"></i>
                        </div>
                        <div class="clinic-details">
                            <h3><?php echo $appointment['clinic_name']; ?></h3>
                            <p><i class="fas fa-map-marker-alt"></i> <?php echo $appointment['clinic_address'] ?? 'Address not available'; ?></p>
                            <p><i class="fas fa-phone"></i> <?php echo $appointment['clinic_contact']; ?></p>
                        </div>
                    </div>

<div class="product-details">
    <div class="product-image">
        <?php 
        $image = $appointment['item_image'] ?? '';
        if (!empty($image) && strpos($image, 'no-image') === false): 
        ?>
            <img src="/uploads/products/<?php echo $image; ?>" 
                 alt="<?php echo $appointment['item_name'] ?? ''; ?>"
                 onerror="this.parentElement.innerHTML='<div class=\'placeholder\'><i class=\'fas fa-box-open\'></i></div>'">
        <?php else: ?>
            <div class="placeholder">
                <i class="fas fa-box-open"></i>
            </div>
        <?php endif; ?>
    </div>
    <div class="product-info-details">
        <div class="product-category"><?php echo $appointment['item_category'] ?? ($appointment['item_type'] == 'service' ? 'Service' : 'Product'); ?></div>
        <div class="product-name"><?php echo $appointment['item_name']; ?></div>
        
        <!-- ✅✅✅ LENS BREAKDOWN - IDAGDAG ITO ✅✅✅ -->
        <?php 
        $lens_prices = [
            'single_vision' => 500,
            'progressive' => 1500,
            'blue_cut' => 800,
            'contact_daily' => 0,
            'contact_monthly' => 0
        ];
        $lens_type = $appointment['lens_type'] ?? '';
        $lens_price = $lens_prices[$lens_type] ?? 0;
        ?>
        <?php if ($lens_type && $lens_type != 'frame_only' && $lens_price > 0): ?>
        <div class="order-item" style="display: flex; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--border-color);">
            <span style="font-size: 13px; color: var(--text-secondary);">Lens Upgrade (<?php echo ucwords(str_replace('_', ' ', $lens_type)); ?>):</span>
            <span style="font-size: 13px; font-weight: 600; color: var(--primary);">+₱<?php echo number_format($lens_price, 2); ?></span>
        </div>
        <?php endif; ?>
        <!-- END OF LENS BREAKDOWN -->
        
        <?php if (!empty($appointment['doctor_name'])): ?>
        <div class="doctor-info">
            <i class="fas fa-user-md"></i>
            <span>Doctor: <strong>Dr. <?php echo $appointment['doctor_name']; ?></strong></span>
        </div>
        <?php elseif ($appointment['item_type'] == 'service'): ?>
        <div class="doctor-info">
            <i class="fas fa-users"></i>
            <span>Doctor: <strong>Any Available Doctor</strong></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="payment-breakdown">
    <?php 
    $frame_price = $appointment['item_price'] - $lens_price;
    ?>
    <div class="breakdown-item">
        <span class="breakdown-label">Frame Price:</span>
        <span class="breakdown-value">
            ₱<?php echo number_format($frame_price, 2); ?>
        </span>
    </div>
    
    <?php if ($lens_type && $lens_type != 'frame_only' && $lens_price > 0): ?>
    <div class="breakdown-item">
        <span class="breakdown-label">Lens Upgrade (<?php echo ucwords(str_replace('_', ' ', $lens_type)); ?>):</span>
        <span class="breakdown-value">
            +₱<?php echo number_format($lens_price, 2); ?>
        </span>
    </div>
    <?php endif; ?>
    
    <div class="breakdown-item" style="border-top: 1px solid var(--border-color); margin-top: 5px; padding-top: 10px;">
        <span class="breakdown-label">Total Amount:</span>
        <span class="breakdown-value total">
            ₱<?php echo number_format($total_amount, 2); ?>
        </span>
    </div>

    <?php if ($payment_type_label === 'downpayment'): ?>
        <div class="breakdown-item">
            <span class="breakdown-label">
                Downpayment Due <span class="downpayment-badge"><?php echo $display_percent; ?>%</span>
            </span>
            <span class="breakdown-value downpayment">
                ₱<?php echo number_format($downpayment_amount, 2); ?>
            </span>
        </div>
        <div class="breakdown-item">
            <span class="breakdown-label">
                Balance <span class="balance-badge">Pay at clinic</span>
            </span>
            <span class="breakdown-value balance">
                ₱<?php echo number_format($balance_amount, 2); ?>
            </span>
        </div>
    <?php elseif ($payment_type_label === 'full'): ?>
        <div class="breakdown-item">
            <span class="breakdown-label">Amount Due:</span>
            <span class="breakdown-value downpayment">
                ₱<?php echo number_format($downpayment_amount, 2); ?>
            </span>
        </div>
    <?php endif; ?>
</div>

                    <!-- Appointment/Reservation Details -->
                    <div class="appointment-dates">
                        <div class="date-row">
                            <i class="fas fa-calendar"></i>
                            <span><?php echo $reservation_id > 0 ? 'Preferred Date:' : 'Appointment Date:'; ?></span>
                            <strong><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></strong>
                        </div>
                        <div class="date-row">
                            <i class="fas fa-clock"></i>
                            <span>Time:</span>
                            <strong><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></strong>
                        </div>
                        <?php if ($reservation_id > 0 && !empty($appointment['ref_no'])): ?>
                        <div class="date-row">
                            <i class="fas fa-ticket-alt"></i>
                            <span>Reservation Code:</span>
                            <strong><?php echo $appointment['ref_no']; ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Methods Card -->
                <div class="payment-methods-card">
                    <h2><i class="fas fa-credit-card"></i> Select Payment Method</h2>
                    
                    <form method="POST" action="" id="paymentForm">
                        <!-- Payment Options -->
                        <div class="payment-options">
                            <!-- GCash -->
                            <label class="payment-option gcash">
                                <input type="radio" name="payment_method" value="gcash" onclick="selectPaymentMethod('gcash')">
                                <i class="fas fa-mobile-alt"></i>
                                <span>GCash</span>
                                <small>Pay via GCash</small>
                            </label>
                            
                            <!-- PayMaya -->
                            <label class="payment-option paymaya">
                                <input type="radio" name="payment_method" value="paymaya" onclick="selectPaymentMethod('paymaya')">
                                <i class="fas fa-credit-card"></i>
                                <span>PayMaya</span>
                                <small>Pay via PayMaya</small>
                            </label>
                            
                            <!-- GrabPay -->
                            <label class="payment-option grab">
                                <input type="radio" name="payment_method" value="grab_pay" onclick="selectPaymentMethod('grab_pay')">
                                <i class="fas fa-wallet"></i>
                                <span>GrabPay</span>
                                <small>Pay via GrabPay</small>
                            </label>
                            
                            <!-- Card -->
                            <label class="payment-option card">
                                <input type="radio" name="payment_method" value="card" onclick="selectPaymentMethod('card')">
                                <i class="fas fa-credit-card"></i>
                                <span>Credit/Debit Card</span>
                                <small>Visa, Mastercard, JCB</small>
                            </label>
                        </div>
                        
                        <!-- Payment Details -->
                        <div id="gcash-details" class="payment-form" style="display: none;">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                You will be redirected to GCash to complete your payment of 
                                <strong>₱<?php echo number_format($downpayment_amount, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div id="paymaya-details" class="payment-form" style="display: none;">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                You will be redirected to PayMaya to complete your payment of 
                                <strong>₱<?php echo number_format($downpayment_amount, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div id="grab-details" class="payment-form" style="display: none;">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                You will be redirected to GrabPay to complete your payment of 
                                <strong>₱<?php echo number_format($downpayment_amount, 2); ?></strong>
                            </div>
                        </div>
                        
                        <div id="card-details" class="payment-form" style="display: none;">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                You will be redirected to our secure payment gateway to enter your card details.
                                Amount to pay: <strong>₱<?php echo number_format($downpayment_amount, 2); ?></strong>
                            </div>
                        </div>
                        
                        <!-- Pay Button -->
                        <button type="submit" class="btn-pay" id="payButton" 
                                <?php echo ($downpayment_amount <= 0) ? 'disabled' : ''; ?>>
                            <i class="fas fa-lock"></i>
                            Pay ₱<?php echo number_format($downpayment_amount, 2); ?>
                        </button>
                        
                        <!-- Test Credentials -->
                        <div class="test-creds">
                            <p><i class="fas fa-flask"></i> Test Mode Credentials:</p>
                            <ul>
                                <li><strong>GCash:</strong> 09123456789 / Any OTP (123456)</li>
                                <li><strong>Card:</strong> 4343 4343 4343 4343 (any expiry, any CVV)</li>
                                <li><strong>PayMaya:</strong> 09123456789 / Any OTP</li>
                            </ul>
                        </div>
                        
                        <!-- Secure Badge -->
                        <div class="secure-badge">
                            <i class="fas fa-shield-alt"></i>
                            <span>Your payment information is secure and encrypted</span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.querySelector('#themeToggle i');
            
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
                if (icon) icon.className = 'fas fa-moon';
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
                if (icon) icon.className = 'fas fa-sun';
            }
            
            fetch('../includes/toggle-theme.php', {
                method: 'POST',
                body: 'theme=' + (html.classList.contains('theme-dark') ? 'dark' : 'light')
            });
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const icon = document.querySelector('#themeToggle i');
            
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
                if (icon) icon.className = 'fas fa-sun';
            } else {
                document.documentElement.classList.remove('theme-dark');
                if (icon) icon.className = 'fas fa-moon';
            }
            
            // Initialize payment method selection
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            if (selectedMethod) {
                selectPaymentMethod(selectedMethod.value);
            }
        });

        // Payment Method Selection
        function selectPaymentMethod(method) {
            // Update selected class on options
            document.querySelectorAll('.payment-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Hide all payment details
            document.getElementById('gcash-details').style.display = 'none';
            document.getElementById('paymaya-details').style.display = 'none';
            document.getElementById('grab-details').style.display = 'none';
            document.getElementById('card-details').style.display = 'none';
            
            // Show selected payment details and update button
            if (method === 'gcash') {
                document.querySelector('.payment-option.gcash').classList.add('selected');
                document.getElementById('gcash-details').style.display = 'block';
                document.getElementById('payButton').className = 'btn-pay gcash';
            } else if (method === 'paymaya') {
                document.querySelector('.payment-option.paymaya').classList.add('selected');
                document.getElementById('paymaya-details').style.display = 'block';
                document.getElementById('payButton').className = 'btn-pay paymaya';
            } else if (method === 'grab_pay') {
                document.querySelector('.payment-option.grab').classList.add('selected');
                document.getElementById('grab-details').style.display = 'block';
                document.getElementById('payButton').className = 'btn-pay grab';
            } else if (method === 'card') {
                document.querySelector('.payment-option.card').classList.add('selected');
                document.getElementById('card-details').style.display = 'block';
                document.getElementById('payButton').className = 'btn-pay card';
            }
            
            // Update button text with amount
            const amount = <?php echo $downpayment_amount; ?>;
            const methodName = method === 'grab_pay' ? 'GrabPay' : method.charAt(0).toUpperCase() + method.slice(1);
            
            if (method === 'gcash' || method === 'paymaya' || method === 'grab_pay' || method === 'card') {
                document.getElementById('payButton').innerHTML = `<i class="fas fa-lock"></i> Pay ₱${amount.toFixed(2)} via ${methodName}`;
            }
        }

        // Form submission handling
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            
            if (!selectedMethod) {
                e.preventDefault();
                alert('Please select a payment method');
                return false;
            }
            
            // Disable button to prevent double submission
            const btn = document.getElementById('payButton');
            btn.disabled = true;
            
            const amount = <?php echo $downpayment_amount; ?>;
            
            btn.innerHTML = '<span class="loading-spinner"></span> Processing ₱' + amount.toFixed(2) + ' payment...';
        });

        // Notification Functions
        function toggleNotifications() {
            document.getElementById('notificationMenu')?.classList.toggle('show');
        }

        function toggleMobileNotifications() {
            document.getElementById('mobileNotificationMenu')?.classList.toggle('show');
        }

        function markAllAsRead() {
            fetch('../includes/mark-all-notifications-read.php', { method: 'POST' })
                .then(() => location.reload());
        }

        function markAllAsReadMobile() {
            fetch('../includes/mark-all-notifications-read.php', { method: 'POST' })
                .then(() => {
                    document.getElementById('mobileNotificationMenu')?.classList.remove('show');
                    location.reload();
                });
        }

        function handleNotificationClick(event, element, notificationId) {
            markAsRead(notificationId);
        }

        function handleMobileNotificationClick(event, element, notificationId) {
            markAsRead(notificationId);
            document.getElementById('mobileNotificationMenu')?.classList.remove('show');
        }

        function markAsRead(notificationId) {
            fetch('mark-notification-read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + notificationId
            });
        }

        // Profile Menu
        function toggleProfileMenu() {
            document.getElementById('profileMenu')?.classList.toggle('show');
        }

        // Discover Dropdown
        function toggleDiscoverDropdown() {
            document.getElementById('discoverDropdown')?.classList.toggle('show');
            document.querySelector('.dropdown-trigger')?.classList.toggle('active');
        }

        // Mobile Menu
        function toggleMobileMenu() {
            document.getElementById('mobileMenu')?.classList.toggle('open');
            document.getElementById('mobileMenuOverlay')?.classList.toggle('show');
        }

        function closeMobileMenu() {
            document.getElementById('mobileMenu')?.classList.remove('open');
            document.getElementById('mobileMenuOverlay')?.classList.remove('show');
        }

        // FAB Menu
        function toggleFabMenu() {
            document.getElementById('fabMenu')?.classList.toggle('show');
            document.getElementById('fab')?.classList.toggle('active');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.profile-dropdown')) {
                document.getElementById('profileMenu')?.classList.remove('show');
            }
            if (!e.target.closest('.nav-dropdown')) {
                document.getElementById('discoverDropdown')?.classList.remove('show');
                document.querySelector('.dropdown-trigger')?.classList.remove('active');
            }
            if (!e.target.closest('.notification-dropdown')) {
                document.getElementById('notificationMenu')?.classList.remove('show');
            }
            if (!e.target.closest('.mobile-notification-dropdown')) {
                document.getElementById('mobileNotificationMenu')?.classList.remove('show');
            }
            if (!e.target.closest('.fab') && !e.target.closest('.fab-menu')) {
                document.getElementById('fabMenu')?.classList.remove('show');
                document.getElementById('fab')?.classList.remove('active');
            }
        });
    </script>
</body>
</html>