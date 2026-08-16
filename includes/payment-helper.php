<?php
// /includes/payment-helper.php

/**
 * ============================================
 * NEW: Get platform-wide tax settings (VAT rate, PWD/Senior discount)
 * Works with BOTH mysqli and PDO connections, since different
 * pages in this system use different drivers.
 *
 * Values are cached per-request in a static variable so we don't
 * re-query system_settings multiple times in one page load.
 * ============================================
 */
function getTaxSettings($conn) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'vat_rate'              => 0.12, // 12% per BIR / TRAIN Law
        'pwd_senior_discount'   => 0.20, // 20% per RA 9994 / RA 10754
        'pwd_senior_vat_exempt' => 1,
    ];

    $isPDO = ($conn instanceof PDO);
    $row = null;

    try {
        if ($isPDO) {
            $stmt = $conn->query("SELECT vat_rate, pwd_senior_discount, pwd_senior_vat_exempt FROM system_settings ORDER BY id DESC LIMIT 1");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        } else {
            $result = mysqli_query($conn, "SELECT vat_rate, pwd_senior_discount, pwd_senior_vat_exempt FROM system_settings ORDER BY id DESC LIMIT 1");
            $row = $result ? mysqli_fetch_assoc($result) : null;
        }
    } catch (Exception $e) {
        error_log("getTaxSettings error: " . $e->getMessage());
        $row = null;
    }

    if ($row && $row['vat_rate'] !== null) {
        $cached = [
            'vat_rate'              => (float)$row['vat_rate'],
            'pwd_senior_discount'   => (float)$row['pwd_senior_discount'],
            'pwd_senior_vat_exempt' => (int)$row['pwd_senior_vat_exempt'],
        ];
    } else {
        // Table/columns not set up yet, or query failed — fall back to legal defaults
        // so the system never accidentally overcharges or undercharges VAT.
        $cached = $defaults;
    }

    return $cached;
}

/**
 * ============================================
 * NEW: Apply VAT and/or PWD/Senior discount to a subtotal.
 *
 * Rules (per Philippine law):
 * - Regular customer: subtotal + 12% VAT
 * - PWD/Senior: (subtotal - 20% discount), and VAT-EXEMPT (no VAT added)
 *   These are mutually exclusive — PWD/Senior never pays VAT on top
 *   of their discount.
 * ============================================
 */
function applyTaxAndDiscount($conn, $subtotal, $is_pwd_senior = false) {
    $subtotal = (float)$subtotal;
    $tax = getTaxSettings($conn);

    $vat_rate       = $tax['vat_rate'];
    $discount_rate  = $tax['pwd_senior_discount'];
    $vat_exempt_law = (bool)$tax['pwd_senior_vat_exempt'];

    if ($is_pwd_senior) {
        $discount_amount = round($subtotal * $discount_rate, 2);
        $discounted_subtotal = round($subtotal - $discount_amount, 2);

        // Legally VAT-exempt — vat_amount stays 0 regardless of vat_rate setting,
        // unless someone has explicitly (and incorrectly) turned the exemption off.
        $vat_amount = $vat_exempt_law ? 0 : round($discounted_subtotal * $vat_rate, 2);

        $final_total = round($discounted_subtotal + $vat_amount, 2);

        return [
            'subtotal'         => $subtotal,
            'is_pwd_senior'    => true,
            'discount_rate'    => $discount_rate,
            'discount_amount'  => $discount_amount,
            'vat_rate'         => $vat_exempt_law ? 0 : $vat_rate,
            'vat_amount'       => $vat_amount,
            'final_total'      => $final_total,
        ];
    }

    // Regular customer — VAT applies, no discount
    $vat_amount  = round($subtotal * $vat_rate, 2);
    $final_total = round($subtotal + $vat_amount, 2);

    return [
        'subtotal'        => $subtotal,
        'is_pwd_senior'   => false,
        'discount_rate'   => 0,
        'discount_amount' => 0,
        'vat_rate'        => $vat_rate,
        'vat_amount'      => $vat_amount,
        'final_total'     => $final_total,
    ];
}

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
 *
 * NEW: now also applies VAT / PWD-Senior discount before computing
 * the downpayment split, via the optional $is_pwd_senior flag.
 * $total_amount passed in here is treated as the SUBTOTAL
 * (sum of service/product prices, before tax/discount).
 */
function calculatePaymentAmounts($conn, $clinic_id, $total_amount, $is_pwd_senior = false) {
    $policy             = getClinicPaymentPolicy($conn, $clinic_id);
    $payment_policy     = $policy['payment_policy'];
    $downpayment_percent = $policy['downpayment_percentage'] ?? 30;

    $subtotal = (float)$total_amount;

    // NEW: apply VAT / PWD-Senior discount to get the real amount owed
    $tax_calc     = applyTaxAndDiscount($conn, $subtotal, $is_pwd_senior);
    $total_amount = $tax_calc['final_total']; // this is now the tax-inclusive / discount-applied total

    $result = [
        'subtotal'               => $tax_calc['subtotal'],
        'is_pwd_senior'          => $tax_calc['is_pwd_senior'],
        'discount_rate'          => $tax_calc['discount_rate'],
        'discount_amount'        => $tax_calc['discount_amount'],
        'vat_rate'               => $tax_calc['vat_rate'],
        'vat_amount'             => $tax_calc['vat_amount'],
        'total_amount'           => $total_amount,
        'policy'                 => $payment_policy,
        'payment_method_online'  => (int)$policy['payment_method_online'],
        'payment_method_onsite'  => (int)$policy['payment_method_onsite'],
        'booking_flow'           => $policy['booking_flow'] ?? 'approve_first'
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
 *
 * NEW: accepts $is_pwd_senior so cards/modals show the
 * correct discounted / VAT-exempt breakdown.
 */
function getPaymentDisplayInfo($conn, $clinic_id, $total_amount, $is_pwd_senior = false) {
    $payment = calculatePaymentAmounts($conn, $clinic_id, $total_amount, $is_pwd_senior);

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

    if ($payment['is_pwd_senior']) {
        $message .= ' (PWD/Senior: 20% discount applied, VAT-exempt)';
    }

    return array_merge($payment, [
        'display_label'   => $label,
        'display_color'   => $color,
        'display_icon'    => $icon,
        'display_message' => $message
    ]);
}

// ============================================
// ✅ ✅ ✅ NEW: CHECK PWD/SENIOR VALIDITY
// ============================================

/**
 * Check if user's PWD/Senior verification is still valid
 * 
 * @param mysqli|PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $clinic_id Clinic ID
 * @return array {
 *     @type bool $is_valid      Whether the verification is valid
 *     @type bool $is_expired    Whether the verification is expired
 *     @type string $status      'verified', 'expired', 'pending', 'rejected', 'none'
 *     @type string $type        'pwd' or 'senior'
 *     @type string $valid_until Expiry date
 *     @type string $date_issued Date issued
 *     @type string $id_number   ID number
 *     @type string $message     User-friendly message
 * }
 */
function checkPwdSeniorValidity($conn, $user_id, $clinic_id) {
    $user_id = (int)$user_id;
    $clinic_id = (int)$clinic_id;
    
    $isPDO = ($conn instanceof PDO);
    
    try {
        if ($isPDO) {
            $stmt = $conn->prepare("
                SELECT 
                    status as pwd_senior_status,
                    verification_type as pwd_senior_type,
                    valid_until,
                    date_issued,
                    id_number
                FROM user_verifications
                WHERE user_id = ? AND clinic_id = ?
                AND status IN ('verified', 'pending', 'rejected')
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$user_id, $clinic_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $result = mysqli_query($conn, "
                SELECT 
                    status as pwd_senior_status,
                    verification_type as pwd_senior_type,
                    valid_until,
                    date_issued,
                    id_number
                FROM user_verifications
                WHERE user_id = $user_id AND clinic_id = $clinic_id
                AND status IN ('verified', 'pending', 'rejected')
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $result = $result ? mysqli_fetch_assoc($result) : null;
        }
        
        // Default response
        $response = [
            'is_valid' => false,
            'is_expired' => false,
            'status' => 'none',
            'type' => '',
            'valid_until' => null,
            'date_issued' => null,
            'id_number' => null,
            'message' => 'No PWD/Senior verification found.'
        ];
        
        if (!$result) {
            $response['message'] = 'No PWD/Senior verification found for this clinic.';
            return $response;
        }
        
        $response['status'] = $result['pwd_senior_status'] ?? 'none';
        $response['type'] = $result['pwd_senior_type'] ?? '';
        $response['valid_until'] = $result['valid_until'] ?? null;
        $response['date_issued'] = $result['date_issued'] ?? null;
        $response['id_number'] = $result['id_number'] ?? null;
        
        // Check if verified
        if ($response['status'] !== 'verified') {
            $response['message'] = 'Your PWD/Senior verification is ' . $response['status'] . '.';
            return $response;
        }
        
        // Check expiry
        $valid_until = $response['valid_until'];
        if (!$valid_until) {
            // No expiry date set - assume valid
            $response['is_valid'] = true;
            $response['message'] = 'PWD/Senior ID is verified (no expiry date set).';
            return $response;
        }
        
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $expiry = new DateTime($valid_until);
        $expiry->setTime(0, 0, 0);
        
        if ($expiry >= $today) {
            $response['is_valid'] = true;
            $response['is_expired'] = false;
            
            // Calculate days remaining
            $diff = $today->diff($expiry);
            $days_remaining = $diff->days;
            
            if ($days_remaining <= 30) {
                $response['message'] = "PWD/Senior ID is valid but will expire in {$days_remaining} days. Please renew soon.";
            } else {
                $response['message'] = 'PWD/Senior ID is valid.';
            }
        } else {
            $response['is_valid'] = false;
            $response['is_expired'] = true;
            
            $diff = $expiry->diff($today);
            $days_overdue = $diff->days;
            $response['message'] = "PWD/Senior ID expired {$days_overdue} days ago. Please renew to continue enjoying discounts.";
        }
        
        return $response;
        
    } catch (Exception $e) {
        error_log("checkPwdSeniorValidity error: " . $e->getMessage());
        return [
            'is_valid' => false,
            'is_expired' => false,
            'status' => 'error',
            'type' => '',
            'valid_until' => null,
            'date_issued' => null,
            'id_number' => null,
            'message' => 'Error checking verification status: ' . $e->getMessage()
        ];
    }
}

/**
 * Get PWD/Senior discount status with expiry check
 * 
 * @param mysqli|PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $clinic_id Clinic ID
 * @return array {
 *     @type bool $eligible     Whether user is eligible for discount
 *     @type string $type       'pwd' or 'senior'
 *     @type bool $is_expired   Whether the ID is expired
 *     @type string $status     'verified', 'expired', 'pending', 'rejected', 'none'
 *     @type string $valid_until Expiry date
 *     @type string $message    User-friendly message
 * }
 */
function getPwdSeniorDiscountStatus($conn, $user_id, $clinic_id) {
    $result = checkPwdSeniorValidity($conn, $user_id, $clinic_id);
    
    return [
        'eligible' => $result['is_valid'] && $result['status'] === 'verified',
        'type' => $result['type'],
        'is_expired' => $result['is_expired'],
        'status' => $result['status'],
        'valid_until' => $result['valid_until'],
        'date_issued' => $result['date_issued'],
        'id_number' => $result['id_number'],
        'message' => $result['message']
    ];
}
?>