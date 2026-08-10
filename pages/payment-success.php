<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get parameters from URL
$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$reservation_id = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;
$ref_no = isset($_GET['ref_no']) ? mysqli_real_escape_string($conn, $_GET['ref_no']) : '';
$payment_type = isset($_GET['type']) ? $_GET['type'] : 'full';

// Determine if this is a reservation or appointment
$is_reservation = ($reservation_id > 0);

// ============================================
// SIMPLE INVENTORY DEDUCTION FUNCTION (NO TRANSACTION)
// ============================================
function deductInventorySimple($conn, $product_id, $clinic_id, $quantity = 1, $color_code = null) {
    $result = ['success' => false, 'message' => ''];
    
    try {
        // Check if product exists
        $product_query = mysqli_query($conn, "SELECT id, name, category FROM products WHERE id = $product_id");
        $product = mysqli_fetch_assoc($product_query);
        
        if (!$product) {
            $result['message'] = "Product not found";
            return $result;
        }
        
        // Skip services
        if ($product['category'] === 'Service') {
            $result['success'] = true;
            $result['message'] = "Service - no inventory";
            return $result;
        }
        
        // IF HAS COLOR CODE - deduct from product_color_inventory
        if (!empty($color_code)) {
            $color_check = mysqli_query($conn, "
                SELECT id, quantity FROM product_color_inventory 
                WHERE product_id = $product_id AND color_code = '$color_code' AND clinic_id = $clinic_id
            ");
            $color_inv = mysqli_fetch_assoc($color_check);
            
            if (!$color_inv) {
                $result['message'] = "Color variant not found";
                return $result;
            }
            
            if ($color_inv['quantity'] < $quantity) {
                $result['message'] = "Insufficient stock for color. Available: {$color_inv['quantity']}";
                return $result;
            }
            
            mysqli_query($conn, "
                UPDATE product_color_inventory 
                SET quantity = quantity - $quantity,
                    updated_at = NOW()
                WHERE id = {$color_inv['id']}
            ");
            
            $result['success'] = true;
            $result['message'] = "Deducted from color inventory";
            return $result;
        }
        
        // NO COLOR CODE - deduct from main inventory
        $inv_check = mysqli_query($conn, "
            SELECT id, stock FROM inventory 
            WHERE product_id = $product_id AND clinic_id = $clinic_id
        ");
        $inventory = mysqli_fetch_assoc($inv_check);
        
        if (!$inventory) {
            $result['message'] = "Inventory not found";
            return $result;
        }
        
        if ($inventory['stock'] < $quantity) {
            $result['message'] = "Insufficient stock. Available: {$inventory['stock']}";
            return $result;
        }
        
        mysqli_query($conn, "
            UPDATE inventory 
            SET stock = stock - $quantity,
                updated_at = NOW()
            WHERE id = {$inventory['id']} AND clinic_id = $clinic_id
        ");
        
        $result['success'] = true;
        $result['message'] = "Deducted from main inventory";
        return $result;
        
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
        return $result;
    }
}

if ($is_reservation) {
    // ============================================
    // RESERVATION PAYMENT SUCCESS
    // ============================================
    
    // Check if already processed
    $check_query = mysqli_query($conn, "
        SELECT payment_status, total_amount, downpayment_amount, balance_amount, product_id, clinic_id, color_code
        FROM reservations 
        WHERE id = $reservation_id AND user_id = $user_id
    ");
    $current = mysqli_fetch_assoc($check_query);
    
    if (!$current) {
        header('Location: my-reservations.php?error=not_found');
        exit();
    }
    
    $already_processed = in_array($current['payment_status'], ['paid', 'downpayment_paid']);
    
    // Get payment details from payments table if ref_no is empty
    if (empty($ref_no) || $already_processed) {
        $ref_query = mysqli_query($conn, "
            SELECT reference_number, amount, payment_method FROM payments 
            WHERE reservation_id = $reservation_id 
            ORDER BY id DESC LIMIT 1
        ");
        if ($ref_row = mysqli_fetch_assoc($ref_query)) {
            $ref_no = $ref_row['reference_number'];
            $paid_amount_from_db = $ref_row['amount'];
            $payment_method_from_db = $ref_row['payment_method'];
        } else {
            $ref_no = 'RES-' . str_pad($reservation_id, 6, '0', STR_PAD_LEFT);
        }
    }
    
    $inventory_deducted = false;
    
    // Process payment if not yet processed
    if (!$already_processed) {
        if ($payment_type == 'downpayment') {
            mysqli_query($conn, "
                UPDATE reservations 
                SET payment_status = 'downpayment_paid', 
                    status = 'confirmed',
                    updated_at = NOW()
                WHERE id = $reservation_id AND user_id = $user_id
            ");
            
            mysqli_query($conn, "
                UPDATE payments 
                SET payment_status = 'paid', 
                    payment_date = NOW() 
                WHERE reservation_id = $reservation_id 
                AND reference_number = '$ref_no'
            ");
            
            $payment_message = "downpayment";
            $success_title = "Downpayment Successful!";
            $success_icon = "fa-hand-holding-usd";
            
        } else {
            // FULL PAYMENT for RESERVATION
            mysqli_query($conn, "
                UPDATE reservations 
                SET payment_status = 'paid', 
                    status = 'confirmed',
                    updated_at = NOW()
                WHERE id = $reservation_id AND user_id = $user_id
            ");
            
            mysqli_query($conn, "
                UPDATE payments 
                SET payment_status = 'paid', 
                    payment_date = NOW() 
                WHERE reservation_id = $reservation_id 
                AND reference_number = '$ref_no'
            ");
            
            // ✅ INVENTORY DEDUCTION FOR RESERVATION (FULL PAYMENT ONLY)
            $inv_result = deductInventorySimple(
                $conn, 
                $current['product_id'], 
                $current['clinic_id'], 
                1,
                $current['color_code'] ?? null
            );
            
            if ($inv_result['success']) {
                $inventory_deducted = true;
            } else {
                error_log("Inventory deduction failed for reservation ID: $reservation_id - " . $inv_result['message']);
            }
            
            $payment_message = "full payment";
            $success_title = "Payment Successful!";
            $success_icon = "fa-check-circle";
        }
    }
    
    // Fetch reservation data
    $query = mysqli_query($conn, "
        SELECT r.*, 
               p.name as product_name,
               p.price as product_price,
               c.name as clinic_name,
               c.address as clinic_address,
               c.contact as clinic_contact,
               COALESCE(pay.payment_method, 'GCash') as payment_method,
               COALESCE(pay.amount, 
                   CASE 
                       WHEN r.payment_status = 'downpayment_paid' THEN r.downpayment_amount
                       WHEN r.payment_status = 'paid' THEN r.total_amount
                       ELSE r.total_amount
                   END
               ) as amount
        FROM reservations r
        JOIN products p ON r.product_id = p.id
        JOIN clinics c ON r.clinic_id = c.id
        LEFT JOIN payments pay ON r.id = pay.reservation_id
        WHERE r.id = $reservation_id AND r.user_id = $user_id
        GROUP BY r.id
    ");
    
    $reservation = mysqli_fetch_assoc($query);
    
    // Override amount from payments table if available
    if (isset($paid_amount_from_db) && $paid_amount_from_db > 0) {
        $reservation['amount'] = $paid_amount_from_db;
    }
    
    // Override payment method if available
    if (isset($payment_method_from_db)) {
        $reservation['payment_method'] = $payment_method_from_db;
    }
    
    // Send notification (once only)
    if (!$already_processed) {
        if ($payment_type == 'downpayment') {
            $notification_message = "Your downpayment of ₱" . number_format($reservation['downpayment_amount'] ?? 0, 2) . 
                                   " for {$reservation['product_name']} at {$reservation['clinic_name']} has been confirmed. " .
                                   "Balance of ₱" . number_format($reservation['balance_amount'] ?? 0, 2) . " is payable upon pickup.";
        } else {
            $notification_message = "Your payment of ₱" . number_format($reservation['amount'], 2) . 
                                   " for {$reservation['product_name']} at {$reservation['clinic_name']} has been confirmed.";
            
            if ($inventory_deducted) {
                $notification_message .= " Your item is now reserved and ready for pickup.";
            }
        }

        addNotification(
            $user_id,
            'reservation',
            'Payment Successful',
            $notification_message,
            'my-reservations.php'
        );
    }
    
    $clinic_name = $reservation['clinic_name'];
    $item_name = $reservation['product_name'];
    $appointment_date = $reservation['preferred_date'];
    $appointment_time = $reservation['preferred_time'];
    $doctor_name = null;
    $amount = $reservation['amount'];
    $payment_method = $reservation['payment_method'] ?? 'GCash';
    $return_url = 'my-reservations.php';
    $success_url = 'my-reservations.php?msg=payment_success';
    
} else {
    // ============================================
    // APPOINTMENT PAYMENT SUCCESS
    // ============================================
    
    if (!$appointment_id) {
        header('Location: my-appointments.php');
        exit();
    }
    
    // CHECK MUNA KUNG PAID NA BAGO MAG-UPDATE
    $check_query = mysqli_query($conn, "
        SELECT payment_status, downpayment_amount, balance_amount, total_amount, product_id, clinic_id, color_code
        FROM appointments 
        WHERE id = $appointment_id AND user_id = $user_id
    ");
    $current = mysqli_fetch_assoc($check_query);
    $already_processed = in_array($current['payment_status'], ['paid', 'downpayment_paid']);
    
    // GET THE CORRECT REFERENCE NUMBER FROM PAYMENTS TABLE IF NOT PROVIDED OR IF ALREADY PROCESSED
    if (empty($ref_no) || $already_processed) {
        $ref_query = mysqli_query($conn, "
            SELECT reference_number, amount, payment_method FROM payments 
            WHERE appointment_id = $appointment_id 
            ORDER BY id DESC LIMIT 1
        ");
        if ($ref_row = mysqli_fetch_assoc($ref_query)) {
            $ref_no = $ref_row['reference_number'];
            $paid_amount_from_db = $ref_row['amount'];
            $payment_method_from_db = $ref_row['payment_method'];
        } else {
            $ref_no = 'EYE-' . str_pad($appointment_id, 6, '0', STR_PAD_LEFT);
        }
    }
    
    $inventory_deducted = false;
    
    // PROCESS PAYMENT IF NOT YET PROCESSED
    if (!$already_processed) {
        if ($payment_type == 'downpayment') {
            // ✅ FIXED: UPDATE APPOINTMENT - DOWNPAYMENT WITH amount_paid
            mysqli_query($conn, "
                UPDATE appointments 
                SET payment_status = 'downpayment_paid', 
                    status = 'confirmed',
                    amount_paid = downpayment_amount  -- ✅ IDINAGDAG
                WHERE id = $appointment_id AND user_id = $user_id
            ");
            
            mysqli_query($conn, "
                UPDATE payments 
                SET payment_status = 'paid', 
                    payment_date = NOW() 
                WHERE appointment_id = $appointment_id 
                AND reference_number = '$ref_no'
                AND payment_type = 'downpayment'
            ");
            
            $payment_message = "downpayment";
            $success_title = "Downpayment Successful!";
            $success_icon = "fa-hand-holding-usd";
            
        } else {
            // ✅ FIXED: FULL PAYMENT FOR APPOINTMENT - WITH amount_paid, subtotal, at payment_status
            mysqli_query($conn, "
                UPDATE appointments 
                SET payment_status = 'paid', 
                    status = 'confirmed',
                    amount_paid = total_amount,
                    subtotal = total_amount,
                    payment_status = 'paid'
                WHERE id = $appointment_id AND user_id = $user_id
            ");
            
            mysqli_query($conn, "
                UPDATE payments 
                SET payment_status = 'paid', 
                    payment_date = NOW() 
                WHERE appointment_id = $appointment_id 
                AND reference_number = '$ref_no'
            ");
            
            // ✅ INVENTORY DEDUCTION FOR APPOINTMENT (FULL PAYMENT ONLY)
            $inv_result = deductInventorySimple(
                $conn, 
                $current['product_id'], 
                $current['clinic_id'], 
                1,
                $current['color_code'] ?? null
            );
            
            if ($inv_result['success']) {
                $inventory_deducted = true;
                // Mark as deducted in appointments table
                mysqli_query($conn, "
                    UPDATE appointments 
                    SET inventory_deducted = 1, 
                        inventory_deducted_at = NOW()
                    WHERE id = $appointment_id
                ");
            } else {
                error_log("Inventory deduction failed for appointment ID: $appointment_id - " . $inv_result['message']);
            }
            
            $payment_message = "full payment";
            $success_title = "Payment Successful!";
            $success_icon = "fa-check-circle";
        }
    }
    
    // FETCH APPOINTMENT DATA
    $query = mysqli_query($conn, "
        SELECT a.*, 
               c.name as clinic_name,
               c.address as clinic_address,
               c.contact as clinic_contact,
               d.name as doctor_name,
               COALESCE(p.payment_method, 'GCash') as payment_method,
               CASE 
                   WHEN a.item_type = 'product' THEN COALESCE(
                       (SELECT name FROM products WHERE id = COALESCE(NULLIF(a.item_id,0), a.product_id)),
                       'Product'
                   )
                   WHEN a.item_type = 'service' THEN COALESCE(
                       (SELECT name FROM services WHERE id = a.item_id),
                       'Service'
                   )
               END as product_name,
               a.downpayment_amount,
               a.balance_amount,
               a.total_amount,
               COALESCE(
                   (SELECT amount FROM payments WHERE appointment_id = a.id ORDER BY id DESC LIMIT 1),
                   CASE
                       WHEN a.payment_status = 'downpayment_paid' THEN a.downpayment_amount
                       WHEN a.payment_status = 'paid' THEN a.total_amount
                       ELSE a.total_amount
                   END
               ) as amount
        FROM appointments a
        JOIN clinics c ON a.clinic_id = c.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN payments p ON a.id = p.appointment_id 
        WHERE a.id = $appointment_id AND a.user_id = $user_id
        GROUP BY a.id
    ");
    
    $appointment = mysqli_fetch_assoc($query);
    
    // Override values
    if (isset($paid_amount_from_db) && $paid_amount_from_db > 0) {
        $appointment['amount'] = $paid_amount_from_db;
    }
    if (isset($payment_method_from_db)) {
        $appointment['payment_method'] = $payment_method_from_db;
    }
    
    // NOTIFICATION - ONCE LANG
    if (!$already_processed) {
        if ($payment_type == 'downpayment') {
            $notification_message = "Your downpayment of ₱" . number_format($appointment['downpayment_amount'] ?? 0, 2) . 
                                   " for {$appointment['product_name']} at {$appointment['clinic_name']} has been confirmed. " .
                                   "Balance of ₱" . number_format($appointment['balance_amount'] ?? 0, 2) . " is payable upon visit.";
        } else {
            $notification_message = "Your payment of ₱" . number_format($appointment['amount'], 2) . 
                                   " for {$appointment['product_name']} at {$appointment['clinic_name']} has been confirmed.";
            
            if ($inventory_deducted) {
                $notification_message .= " Your item is now reserved and ready for your appointment.";
            }
        }
        
        addNotification(
            $user_id,
            'appointment',
            'Payment Successful',
            $notification_message,
            'my-appointments.php'
        );
    }
    
    $clinic_name = $appointment['clinic_name'];
    $item_name = $appointment['product_name'];
    $appointment_date = $appointment['appointment_date'];
    $appointment_time = $appointment['appointment_time'];
    $doctor_name = $appointment['doctor_name'];
    $amount = $appointment['amount'];
    $payment_method = $appointment['payment_method'] ?? 'GCash';
    $return_url = 'my-appointments.php';
    $success_url = 'my-appointments.php?msg=payment_success';
}

// ============================================
// GET USER DATA FOR SIDEBAR (for both)
// ============================================
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$pending_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_count)['total'];

$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Payment Success - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 40px rgba(0,0,0,0.08);
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-full: 999px;
            --success: #00B761;
            --danger: #FF4444;
            --warning: #FF8C42;
            --info: #17A2B8;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #6B7280;
            --border-color: #2D2D2D;
            --border-light: #262626;
        }

        .main-content {
            max-width: 600px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .success-container {
            text-align: center;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 50px 40px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
        }

        .success-icon {
            width: 100px;
            height: 100px;
            background: var(--primary-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 50px;
            color: var(--primary);
            animation: scaleIn 0.5s ease;
        }

        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            80% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .success-message {
            color: var(--text-secondary);
            font-size: 15px;
            margin-bottom: 30px;
        }

        .payment-details-card {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 25px;
            margin: 25px 0;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 14px;
            text-align: right;
        }

        .amount-highlight {
            font-size: 22px;
            color: var(--primary);
            font-weight: 700;
        }

        .reference-number {
            font-family: monospace;
            font-size: 14px;
            background: var(--bg-secondary);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .inventory-status {
            margin-top: 15px;
            padding: 10px;
            background: var(--primary-light);
            border-radius: var(--radius-md);
            text-align: center;
            font-size: 13px;
            color: var(--primary-dark);
        }

        .inventory-status i {
            margin-right: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px 16px;
            }
            .success-container {
                padding: 30px 20px;
            }
            .success-title {
                font-size: 24px;
            }
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            .detail-value {
                text-align: left;
            }
            .action-buttons {
                flex-direction: column;
            }
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body class="<?php echo getThemeClass(); ?>">
    <div style="background: var(--bg-secondary); padding: 15px 20px; border-bottom: 1px solid var(--border-light);">
        <div style="max-width: 600px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-eye" style="color: var(--primary); font-size: 24px;"></i>
                <span style="font-weight: 700; font-size: 18px; color: var(--text-primary);">eyecore</span>
            </div>
            <button onclick="toggleTheme()" style="background: var(--bg-primary); border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; color: var(--text-secondary);">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>

    <div class="main-content">
        <div class="success-container">
            <div class="success-icon">
                <i class="fas <?php echo $success_icon ?? 'fa-check-circle'; ?>"></i>
            </div>
            
            <h1 class="success-title"><?php echo $success_title ?? 'Payment Successful!'; ?></h1>
            <p class="success-message">
                Thank you for your payment. Your <?php echo $is_reservation ? 'reservation' : 'appointment'; ?> has been confirmed.
            </p>

            <?php if ($payment_type != 'downpayment'): ?>
                <?php if ($inventory_deducted): ?>
                <div class="inventory-status">
                    <i class="fas fa-check-circle"></i>
                    ✓ Item has been reserved and deducted from inventory
                </div>
                <?php else: ?>
                <div class="inventory-status" style="background: var(--warning); color: white;">
                    <i class="fas fa-exclamation-triangle"></i>
                    ⚠ Inventory update pending. Please contact the clinic.
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="payment-details-card">
                <div class="detail-row">
                    <span class="detail-label">Reference Number</span>
                    <span class="detail-value">
                        <span class="reference-number"><?php echo $ref_no; ?></span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid</span>
                    <span class="detail-value amount-highlight">₱<?php echo number_format($amount ?? 0, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payment Method</span>
                    <span class="detail-value"><?php echo ucfirst($payment_method ?? 'GCash'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Clinic</span>
                    <span class="detail-value"><?php echo $clinic_name ?? 'N/A'; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Item</span>
                    <span class="detail-value"><?php echo $item_name ?? 'N/A'; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date</span>
                    <span class="detail-value"><?php echo date('F j, Y', strtotime($appointment_date ?? 'now')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Time</span>
                    <span class="detail-value"><?php echo date('g:i A', strtotime($appointment_time ?? '00:00:00')); ?></span>
                </div>
                <?php if ($doctor_name): ?>
                <div class="detail-row">
                    <span class="detail-label">Doctor</span>
                    <span class="detail-value">Dr. <?php echo $doctor_name; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="action-buttons">
                <a href="<?php echo $return_url; ?>" class="btn btn-primary">
                    <i class="fas <?php echo $is_reservation ? 'fa-clock' : 'fa-calendar-check'; ?>"></i> 
                    View My <?php echo $is_reservation ? 'Reservations' : 'Appointments'; ?>
                </a>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </div>
    </div>

    <script>
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.querySelector('button i');
            
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
                if (icon) icon.className = 'fas fa-moon';
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
                if (icon) icon.className = 'fas fa-sun';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            const icon = document.querySelector('button i');
            
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
                if (icon) icon.className = 'fas fa-sun';
            } else {
                document.documentElement.classList.remove('theme-dark');
                if (icon) icon.className = 'fas fa-moon';
            }
        });
    </script>
</body>
</html>