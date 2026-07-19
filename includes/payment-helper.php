<?php
// /includes/payment-helper.php

/**
 * Get clinic payment policy - WORKS WITH BOTH MYSQLI AND PDO
 */
function getClinicPaymentPolicy($conn, $clinic_id) {
    $clinic_id = (int)$clinic_id;
    
    // Check if connection is PDO or mysqli
    $isPDO = ($conn instanceof PDO);
    
    if ($isPDO) {
        // PDO version (ginagamit sa clinic side)
        $stmt = $conn->prepare("
            SELECT 
                payment_policy, 
                downpayment_percentage, 
                payment_method_online, 
                payment_method_onsite,
                booking_flow
            FROM clinics 
            WHERE id = ?
        ");
        $stmt->execute([$clinic_id]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // mysqli version (ginagamit sa user side)
        $result = mysqli_query($conn, "
            SELECT 
                payment_policy, 
                downpayment_percentage, 
                payment_method_online, 
                payment_method_onsite,
                booking_flow
            FROM clinics 
            WHERE id = $clinic_id
        ");
        $policy = mysqli_fetch_assoc($result);
    }
    
    if (!$policy) {
        // Default kung walang nahanap na clinic
        return [
            'payment_policy'          => 'downpayment_30',
            'downpayment_percentage'  => 30,
            'payment_method_online'   => 1,
            'payment_method_onsite'   => 1,
            'booking_flow'            => 'pay_first'  // ← CHANGE TO pay_first
        ];
    }
    
    return $policy;
}

/**
 * Calculate payment amounts based on clinic policy
 * WORKS WITH BOTH MYSQLI AND PDO
 */
function calculatePaymentAmounts($conn, $clinic_id, $total_amount) {
    $policy             = getClinicPaymentPolicy($conn, $clinic_id);
    $payment_policy     = $policy['payment_policy'];
    $downpayment_percent = $policy['downpayment_percentage'] ?? 30;
    $total_amount       = (float)$total_amount;

    $result = [
        'total_amount'          => $total_amount,
        'policy'                => $payment_policy,
        'payment_method_online' => (int)$policy['payment_method_online'],
        'payment_method_onsite' => (int)$policy['payment_method_onsite'],
        'booking_flow'          => $policy['booking_flow'] ?? 'approve_first'
    ];

    switch ($payment_policy) {

        case 'full_payment':
            // Bayad lahat agad
            $result['downpayment_amount'] = $total_amount;
            $result['balance_amount']     = 0;
            $result['payment_type']       = 'full';
            $result['requires_payment']   = true;
            break;

        case 'downpayment_30':
            // 30% muna, yung natira sa clinic
            $result['downpayment_amount'] = round($total_amount * 0.30, 2);
            $result['balance_amount']     = round($total_amount - $result['downpayment_amount'], 2);
            $result['payment_type']       = 'downpayment';
            $result['requires_payment']   = true;
            break;

        case 'downpayment_custom':
            // Custom % na itinakda ng clinic
            $result['downpayment_amount'] = round($total_amount * ($downpayment_percent / 100), 2);
            $result['balance_amount']     = round($total_amount - $result['downpayment_amount'], 2);
            $result['payment_type']       = 'downpayment';
            $result['requires_payment']   = true;
            break;

        case 'no_payment':
            // Libre, walang babayaran
            $result['downpayment_amount'] = 0;
            $result['balance_amount']     = 0;
            $result['payment_type']       = 'free';
            $result['requires_payment']   = false;
            break;

        case 'pay_on_site':
            // Bayad sa clinic mismo, walang online payment
            $result['downpayment_amount'] = 0;
            $result['balance_amount']     = $total_amount;
            $result['payment_type']       = 'onsite';
            $result['requires_payment']   = false;
            break;

        default:
            // Fallback — 30% downpayment kung walang nakilalang policy
            $result['downpayment_amount'] = round($total_amount * 0.30, 2);
            $result['balance_amount']     = round($total_amount - $result['downpayment_amount'], 2);
            $result['payment_type']       = 'downpayment';
            $result['requires_payment']   = true;
    }
    
    // ✅ FIX: Ensure downpayment is not zero when payment is required
    if ($result['requires_payment'] && $result['downpayment_amount'] <= 0 && $total_amount > 0) {
        $result['downpayment_amount'] = $total_amount;
        $result['balance_amount'] = 0;
        $result['payment_type'] = 'full';
    }

    return $result;
}

/**
 * Check kung kailangan ng payment para sa clinic na ito
 */
function isPaymentRequired($conn, $clinic_id) {
    $policy         = getClinicPaymentPolicy($conn, $clinic_id);
    $payment_policy = $policy['payment_policy'];

    // Hindi kailangan ng online payment kung free o pay_on_site
    return !in_array($payment_policy, ['no_payment', 'pay_on_site']);
}

/**
 * Kunin ang available na payment methods ng clinic
 */
function getAvailablePaymentMethods($conn, $clinic_id) {
    $policy  = getClinicPaymentPolicy($conn, $clinic_id);
    $methods = [];

    if ($policy['payment_method_online']) {
        $methods[] = 'online'; // GCash, PayMaya, Card
    }
    if ($policy['payment_method_onsite']) {
        $methods[] = 'onsite'; // Cash, Bank Transfer
    }

    return $methods;
}

// ============================================
// ✅ NEW FUNCTIONS FOR BOOKING FLOW
// ============================================

/**
 * Get clinic booking flow
 * Returns: 'approve_first' or 'pay_first'
 */
function getClinicBookingFlow($conn, $clinic_id) {
    $policy = getClinicPaymentPolicy($conn, $clinic_id);
    return $policy['booking_flow'] ?? 'approve_first';
}

/**
 * Check if clinic requires payment before approval
 * Returns: true if pay_first, false if approve_first
 */
function requiresPaymentBeforeApproval($conn, $clinic_id) {
    $flow = getClinicBookingFlow($conn, $clinic_id);
    return $flow === 'pay_first';
}

/**
 * Helper — i-format ang payment info para sa display
 * Ginagamit sa appointment cards at modals
 */
function getPaymentDisplayInfo($conn, $clinic_id, $total_amount) {
    $payment = calculatePaymentAmounts($conn, $clinic_id, $total_amount);

    $label   = '';
    $color   = '';
    $icon    = '';
    $message = '';

    switch ($payment['payment_type']) {
        case 'full':
            $label   = 'Full Payment Required';
            $color   = '#856404';
            $icon    = 'fa-credit-card';
            $message = 'Bayad lahat: ₱' . number_format($payment['total_amount'], 2);
            break;

        case 'downpayment':
            $label   = 'Downpayment Required';
            $color   = '#0c5460';
            $icon    = 'fa-money-bill-wave';
            $message = 'Downpayment: ₱' . number_format($payment['downpayment_amount'], 2)
                     . ' | Balance sa clinic: ₱' . number_format($payment['balance_amount'], 2);
            break;

        case 'free':
            $label   = 'FREE Appointment';
            $color   = '#155724';
            $icon    = 'fa-check-circle';
            $message = 'Walang bayad para sa appointment na ito';
            break;

        case 'onsite':
            $label   = 'Pay On-Site';
            $color   = '#004085';
            $icon    = 'fa-store';
            $message = 'Bayad sa clinic: ₱' . number_format($payment['total_amount'], 2);
            break;
    }

    return array_merge($payment, [
        'display_label'   => $label,
        'display_color'   => $color,
        'display_icon'    => $icon,
        'display_message' => $message
    ]);
}
?>