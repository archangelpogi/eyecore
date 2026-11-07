<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// ... rest ng code
ob_start();

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) { ob_end_clean(); header('Location: ../auth/user_login.php'); exit(); }

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ✅ Auto-mark missed - FIXED (handles NULL time)
$autoMark = mysqli_query($conn, "
    UPDATE appointments 
    SET status='missed', updated_at=NOW() 
    WHERE user_id=$user_id 
    AND status IN ('confirmed', 'paid') 
    AND (
        appointment_date < CURDATE() 
        OR (
            appointment_date = CURDATE() 
            AND appointment_time IS NOT NULL 
            AND appointment_time < CURTIME()
        )
    )
");

// ✅ Send notification if appointments were marked as missed
if (mysqli_affected_rows($conn) > 0) {
    $missedQuery = mysqli_query($conn, "
        SELECT a.*, c.clinic_name 
        FROM appointments a
        JOIN clinics c ON a.clinic_id = c.id
        WHERE a.user_id = $user_id 
        AND a.status = 'missed'
        AND a.updated_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    
    while ($missed = mysqli_fetch_assoc($missedQuery)) {
        $formattedDate = date('F j, Y', strtotime($missed['appointment_date']));
        $formattedTime = $missed['appointment_time'] ? date('g:i A', strtotime($missed['appointment_time'])) : 'N/A';
        
        $title = "⏰ You Missed Your Appointment";
        $message = "You missed your appointment at {$missed['clinic_name']} on {$formattedDate} at {$formattedTime}. "
            . "Please contact the clinic to reschedule or check your policy for penalties.";
        
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES ($user_id, '$title', '$message', 'appointment', 'my-appointments.php', 0, NOW())
        ");
    }
}

// ============================================
// CANCEL APPOINTMENT WITH VALIDATION
// (With Reason, Notes & Reschedule Hint)
// ============================================
if (isset($_POST['cancel_appointment'], $_POST['appointment_id'])) {
    
    // ✅ TANGGALIN LAHAT NG BUFFERS — hindi lang isa
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    // ✅ I-set headers bago mag-echo
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    // ✅ I-wrap sa try-catch para hindi mag-500
    try {
        $cid = (int)$_POST['appointment_id'];
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        $cancel_notes  = trim($_POST['cancel_notes'] ?? '');
        
        if ($cancel_reason === '') {
            echo json_encode(['success' => false, 'message' => 'Please select a reason for cancellation.']);
            exit();
        }
        if ($cancel_reason === 'Other' && $cancel_notes === '') {
            echo json_encode(['success' => false, 'message' => 'Please specify your reason in the notes field.']);
            exit();
        }
        if (strlen($cancel_notes) > 500) {
            echo json_encode(['success' => false, 'message' => 'Notes must not exceed 500 characters.']);
            exit();
        }
        
        $allowed_reasons = [
            'Schedule conflict','Personal emergency','Financial reasons',
            'Found another clinic','Feeling unwell','Transportation issues',
            'Changed my mind','Other'
        ];
        if (!in_array($cancel_reason, $allowed_reasons, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid cancellation reason.']);
            exit();
        }
        
        $cancel_reason_esc = mysqli_real_escape_string($conn, $cancel_reason);
        $cancel_notes_esc  = mysqli_real_escape_string($conn, $cancel_notes);
        
        $row = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT a.*, 
                   c.cancellation_deadline, 
                   c.refund_policy,
                   c.penalty_amount,
                   c.clinic_name
            FROM appointments a
            JOIN clinics c ON a.clinic_id = c.id
            WHERE a.id = $cid 
              AND a.user_id = $user_id 
              AND a.status NOT IN ('cancelled','completed','missed','no-show')
        "));
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Appointment not found or cannot be cancelled.']);
            exit();
        }
        
        $apd = new DateTime($row['appointment_date'] . ' ' . ($row['appointment_time'] ?? '00:00:00'));
        $now = new DateTime();
        
        if ($apd < $now) {
            echo json_encode(['success' => false, 'message' => 'Cannot cancel past appointments. Please contact the clinic directly.']);
            exit();
        }
        
        $deadline_days = (int)($row['cancellation_deadline'] ?? 2);
        $deadline_date = clone $apd;
        $deadline_date->modify("-{$deadline_days} days");
        
        if ($now > $deadline_date) {
            $clinic_name = $row['clinic_name'] ?? 'the clinic';
            echo json_encode([
                'success' => false,
                'message' => "Cancellation deadline is {$deadline_days} day(s) before the appointment. You can no longer cancel online. You may still reschedule, or contact {$clinic_name} directly."
            ]);
            exit();
        }
        
        $combined_note = "Cancellation Reason: " . $cancel_reason;
        if ($cancel_notes !== '') {
            $combined_note .= " | Notes: " . $cancel_notes;
        }
        $combined_note_esc = mysqli_real_escape_string($conn, $combined_note);
        $stamp = date('Y-m-d H:i:s');
        
// ✅ Check BOTH columns individually
$has_reason_col = false;
$has_notes_col = false;

$check_col = @mysqli_query($conn, "SHOW COLUMNS FROM appointments LIKE 'cancellation_reason'");
if ($check_col && mysqli_num_rows($check_col) > 0) {
    $has_reason_col = true;
}

$check_col2 = @mysqli_query($conn, "SHOW COLUMNS FROM appointments LIKE 'cancellation_notes'");
if ($check_col2 && mysqli_num_rows($check_col2) > 0) {
    $has_notes_col = true;
}

// ✅ Build SET clause dynamically
$set_parts = ["status = 'cancelled'"];

if ($has_reason_col) {
    $set_parts[] = "cancellation_reason = '$cancel_reason_esc'";
}
if ($has_notes_col) {
    $set_parts[] = "cancellation_notes = '$cancel_notes_esc'";
}

// Always append to notes field
$set_parts[] = "notes = CONCAT(IFNULL(notes,''), '\n[{$stamp}] {$combined_note_esc}')";
$set_parts[] = "updated_at = NOW()";

$set_clause = implode(', ', $set_parts);

$update_sql = "
    UPDATE appointments 
    SET {$set_clause}
    WHERE id = $cid AND user_id = $user_id
";
        
        $ok = mysqli_query($conn, $update_sql);
        
        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
            exit();
        }
        
        // Compute refund
        $refund_policy = $row['refund_policy'] ?? '2:100|1:50|0:0';
        $days_until = $now->diff($apd)->days;
        $refund_percent = 0;
        
        if (!empty($refund_policy)) {
            foreach (explode('|', $refund_policy) as $part) {
                if (strpos($part, ':') === false) continue;
                list($days, $percent) = explode(':', $part, 2);
                if ($days_until >= (int)$days) {
                    $refund_percent = (int)$percent;
                    break;
                }
            }
        }
        
        $downpayment = (float)($row['downpayment_amount'] ?? 0);
        if ($downpayment <= 0) $downpayment = (float)($row['amount_paid'] ?? 0);
        $eligible_amount = $downpayment * ($refund_percent / 100);
        
        @mysqli_query($conn, "
            UPDATE appointments 
            SET refund_eligible = " . ($refund_percent > 0 ? 1 : 0) . ",
                refund_percentage = $refund_percent,
                eligible_refund_amount = $eligible_amount
            WHERE id = $cid
        ");
        
        // Notification
        $notif_title = mysqli_real_escape_string($conn, "Appointment Cancelled");
        $notif_msg = mysqli_real_escape_string($conn, "Your appointment on " . $apd->format('F j, Y g:i A') . " was cancelled. Reason: {$cancel_reason}.");
        
        @mysqli_query($conn, "
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES ($user_id, '$notif_title', '$notif_msg', 'appointment', 'my-appointments.php', 0, NOW())
        ");
        
        echo json_encode([
            'success'         => true,
            'message'         => 'Appointment cancelled.',
            'refund_percent'  => $refund_percent,
            'refund_amount'   => $eligible_amount,
            'reason'          => $cancel_reason,
            'notes'           => $cancel_notes
        ]);
        
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// ============================================
// PATIENT REFUND REQUEST - FIXED
// ============================================
if (isset($_POST['request_refund'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $rid = (int)$_POST['appointment_id'];
    
    // ✅ Get appointment with ALL payment info
    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT 
            a.id,
            a.downpayment_amount,
            a.amount_paid,
            a.total_amount,
            a.clinic_id, 
            a.status,
            a.refund_eligible,
            a.refund_percentage,
            a.eligible_refund_amount,
            a.forfeited_amount,
            u.id as user_id
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.id = $rid AND a.user_id = $user_id
    "));
    
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // ✅ Get the amount paid (downpayment or full payment)
    $downpayment = (float)($row['downpayment_amount'] ?? 0);
    $amountPaid = (float)($row['amount_paid'] ?? 0);
    $totalAmount = (float)($row['total_amount'] ?? 0);
    
    // ✅ Use whichever has value
    $paidAmount = $downpayment > 0 ? $downpayment : $amountPaid;
    
    if ($paidAmount <= 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'No payment found for this appointment. Downpayment: ₱' . number_format($downpayment, 2) . ', Amount Paid: ₱' . number_format($amountPaid, 2)
        ]);
        exit;
    }
    
    // ✅ Check if refund is eligible
    $refundEligible = (int)($row['refund_eligible'] ?? 0);
    $refundPercent = (int)($row['refund_percentage'] ?? 0);
    $eligibleRefund = (float)($row['eligible_refund_amount'] ?? 0);
    
    // ✅ If eligible_refund_amount is 0 but refund_percent > 0, compute it
    if ($eligibleRefund == 0 && $refundPercent > 0 && $paidAmount > 0) {
        $eligibleRefund = $paidAmount * ($refundPercent / 100);
    }
    
    if (!$refundEligible || $refundPercent <= 0 || $eligibleRefund <= 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'This appointment is not eligible for refund. (Eligible: ' . ($refundEligible ? 'Yes' : 'No') . ', Percent: ' . $refundPercent . '%, Amount: ₱' . number_format($eligibleRefund, 2) . ')'
        ]);
        exit;
    }
    
    // ✅ FIXED: Check existing refund request — kasama na ang completed
    $ex = mysqli_query($conn, "
        SELECT id, status, refund_status 
        FROM refund_requests 
        WHERE appointment_id=$rid 
        AND (status IN ('pending','processing','completed','approved') 
             OR refund_status IN ('pending','processing','completed'))
    ");
    if (mysqli_num_rows($ex) > 0) {
        echo json_encode(['success'=>false, 'message'=>'Refund request already submitted.']);
        exit;
    }
    
    $cid = (int)$row['clinic_id'];
    $refundAmount = $eligibleRefund;
    $refundPercent = (int)$row['refund_percentage'];
    
    // ✅ Insert refund request
    $ok = mysqli_query($conn, "
        INSERT INTO refund_requests(
            appointment_id, 
            user_id, 
            clinic_id, 
            amount, 
            request_type, 
            status, 
            refund_status,
            refund_percentage,
            reason,
            created_at
        ) VALUES(
            $rid, 
            $user_id, 
            $cid, 
            $refundAmount, 
            'refund', 
            'pending',
            'pending',
            $refundPercent,
            'Patient requested refund for no-show appointment',
            NOW()
        )
    ");
    
    if ($ok) {
        // ✅ Update appointment refund status
        mysqli_query($conn, "
            UPDATE appointments 
            SET refund_status = 'pending'
            WHERE id = $rid
        ");
        
        echo json_encode([
            'success' => true,
            'message' => "Refund request submitted! Amount: ₱" . number_format($refundAmount, 2) . " ({$refundPercent}% refund)"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to submit refund request: ' . mysqli_error($conn)
        ]);
    }
    exit;
}

if (isset($_POST['delete_appointment'], $_POST['appointment_id'])) {
    ob_end_clean(); header('Content-Type: application/json');
    $did = (int)$_POST['appointment_id'];
    $ok  = mysqli_query($conn, "DELETE FROM appointments WHERE id=$did AND user_id=$user_id AND status='cancelled'");
    echo json_encode(($ok && mysqli_affected_rows($conn) > 0) ? ['success'=>true,'message'=>'Appointment deleted successfully'] : ['success'=>false,'message'=>'Cannot delete this appointment']);
    exit();
}

// ============================================
// VIEW PENALTY - FIXED
// ============================================
if (isset($_POST['view_penalty'], $_POST['appointment_id'])) {
    ob_end_clean(); 
    header('Content-Type: application/json');
    
    $pid = (int)$_POST['appointment_id'];
    
    // ✅ Get penalty from clinic with proper error handling
    $query = mysqli_query($conn, "
        SELECT c.penalty_amount, c.clinic_name, a.appointment_date, a.appointment_time
        FROM appointments a 
        JOIN clinics c ON a.clinic_id = c.id 
        WHERE a.id = $pid AND a.user_id = $user_id
    ");
    
    if (!$query) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . mysqli_error($conn)
        ]);
        exit;
    }
    
    $row = mysqli_fetch_assoc($query);
    
    if (!$row) {
        echo json_encode([
            'success' => false, 
            'message' => 'Appointment not found'
        ]);
        exit;
    }
    
    $penalty_amount = isset($row['penalty_amount']) ? (float)$row['penalty_amount'] : 500;
    
    echo json_encode([
        'success' => true,
        'penalty_amount' => $penalty_amount,
        'clinic_name' => $row['clinic_name'] ?? 'Clinic',
        'appointment_date' => $row['appointment_date'] ?? '',
        'appointment_time' => $row['appointment_time'] ?? '',
        'message' => 'No-show penalty applies'
    ]);
    exit;
}

// ============================================
// GET APPOINTMENT DETAILS WITH CLINIC POLICIES
// ============================================
$query = mysqli_query($conn, "
    SELECT a.*, a.downpayment_amount,
           c.clinic_name, c.address, c.contact, c.clinic_email as email,
           c.latitude, c.longitude, c.hours, c.city,
           c.logo, c.clinic_image, c.cover_photo,
           c.refund_policy, c.reschedule_policy, c.penalty_amount,
           c.cancellation_deadline,
           u.first_name, u.last_name, u.email as user_email, u.contact as user_phone, u.address as user_address,
           p.id as product_id, p.name as product_name, p.description as product_description,
           p.price as product_price,
           p.image as product_image, p.images as product_images_old, p.images_json as product_images_json,
           p.category as product_category,
           p.warranty_period, p.warranty_coverage, p.warranty_terms, p.warranty_premium_price,
           d.name as doctor_name, d.specialty as doctor_specialty,
           r.created_at as purchase_date,
           r.id as reservation_id
    FROM appointments a
    JOIN clinics c ON a.clinic_id=c.id
    JOIN users u ON a.user_id=u.id
    LEFT JOIN products p ON a.product_id=p.id
    LEFT JOIN doctors d ON a.doctor_id=d.id
    LEFT JOIN reservations r ON a.product_id = r.product_id AND r.user_id = a.user_id AND r.status IN ('confirmed', 'completed')
    WHERE a.id=$appointment_id AND a.user_id=$user_id
");
$appointment = mysqli_fetch_assoc($query);
if (!$appointment) { header('Location: my-appointments.php'); exit(); }

$downpayment_amount = max((float)($appointment['downpayment_amount']??0), (float)($appointment['downpayment']??0));

// ============================================
// ✅ FIXED: Check refund eligibility — kasama na ang completed status
// ============================================
$refund_available = false;
$refund_pending = false;
$refund_completed = false;
$refund_rejected = false;
$refund_percent = 0;
$eligible_refund = 0;
$refund_data = null;

if ($appointment['status'] == 'cancelled' && $downpayment_amount > 0) {
    $refund_eligible = (int)($appointment['refund_eligible'] ?? 0);
    $refund_percent = (int)($appointment['refund_percentage'] ?? 0);
    $eligible_refund = (float)($appointment['eligible_refund_amount'] ?? 0);
    
    if ($refund_eligible == 1 && $refund_percent > 0) {
        $refund_available = true;
    }
    
    // ✅ FIXED: Kunin ang pinakabagong refund request — kasama lahat ng status
    $rc = mysqli_query($conn, "
        SELECT id, status, refund_status, amount, paymongo_refund_id, refund_date, created_at
        FROM refund_requests 
        WHERE appointment_id=$appointment_id 
        ORDER BY id DESC LIMIT 1
    ");
    
    if ($rc && mysqli_num_rows($rc) > 0) {
        $refund_data = mysqli_fetch_assoc($rc);
        $current_refund_status = $refund_data['refund_status'] ?? $refund_data['status'];
        
        // ✅ Check kung may active/completed refund na
        if (in_array($current_refund_status, ['pending', 'processing'])) {
            $refund_pending = true;
        }
        if ($current_refund_status === 'completed') {
            $refund_completed = true;
            $refund_pending = true; // ✅ Kapag completed, hindi na dapat magpakita ng button
        }
        if (in_array($current_refund_status, ['rejected', 'failed'])) {
            $refund_rejected = true;
        }
    }
}

// ✅ Check if No-Show
$is_no_show = ($appointment['status'] == 'no-show');
$forfeited_amount = (float)($appointment['forfeited_amount'] ?? 0);
$refund_eligible = (int)($appointment['refund_eligible'] ?? 0);
$refund_percent = (int)($appointment['refund_percentage'] ?? 0);
$eligible_refund = (float)($appointment['eligible_refund_amount'] ?? 0);

// ✅ FIXED: Para sa no-show, i-check din ang refund request status
if ($is_no_show && $downpayment_amount > 0) {
    $rc_no_show = mysqli_query($conn, "
        SELECT id, status, refund_status, amount, paymongo_refund_id, refund_date, created_at
        FROM refund_requests 
        WHERE appointment_id=$appointment_id 
        ORDER BY id DESC LIMIT 1
    ");
    
    if ($rc_no_show && mysqli_num_rows($rc_no_show) > 0) {
        $refund_data = mysqli_fetch_assoc($rc_no_show);
        $current_refund_status = $refund_data['refund_status'] ?? $refund_data['status'];
        
        if (in_array($current_refund_status, ['pending', 'processing'])) {
            $refund_pending = true;
        }
        if ($current_refund_status === 'completed') {
            $refund_completed = true;
            $refund_pending = true;
        }
        if (in_array($current_refund_status, ['rejected', 'failed'])) {
            $refund_rejected = true;
        }
    }
}

// ============================================
// ✅ PAYMENT SUMMARY DATA
// ============================================
$payment_items = [];
$payment_subtotal = 0;
$payment_discount = 0;
$payment_vat = 0;
$payment_total = 0;
$payment_ref = '';
$payment_amount_paid = 0;
$payment_balance = 0;
$payment_status_label = 'Unpaid';
$payment_status_class = 'unpaid';
$payment_discount_type = 'none';
$payment_discount_pct = 0;

// ✅ Get payment record
$payment_query = mysqli_query($conn, "
    SELECT id, amount, payment_method, payment_status, payment_type,
           reference_number, payment_date
    FROM payments
    WHERE appointment_id = $appointment_id
    ORDER BY created_at DESC
    LIMIT 1
");
$payment_record = $payment_query ? mysqli_fetch_assoc($payment_query) : null;
if ($payment_record) {
    $payment_ref = $payment_record['reference_number'] ?? '';
}

// ✅ Get sales record
$sales_query = mysqli_query($conn, "
    SELECT items, subtotal, discount, discount_type, discount_percentage, 
           vat_amount, total_amount, amount_paid
    FROM sales
    WHERE appointment_id = $appointment_id
    ORDER BY id DESC
    LIMIT 1
");
$sales_record = $sales_query ? mysqli_fetch_assoc($sales_query) : null;

// ✅ Parse items from sales
if ($sales_record && !empty($sales_record['items'])) {
    $decoded = json_decode($sales_record['items'], true);
    if (is_array($decoded)) {
        $payment_items = $decoded;
    }
}

// ✅ Fallback: parse items from appointment
if (empty($payment_items) && !empty($appointment['items'])) {
    $decoded = json_decode($appointment['items'], true);
    if (is_array($decoded)) {
        $payment_items = $decoded;
    }
}

// ✅ ENRICH: Add lens items from clinical_notes
if (!empty($appointment['rec_lens_design']) 
    || !empty($appointment['rec_lens_index']) 
    || !empty($appointment['rec_lens_features'])) {
    
    $lensPrices = ['design' => [], 'index' => [], 'feature' => []];
    $lensStmt = mysqli_query($conn, "
        SELECT option_type, option_name, price
        FROM lens_options
        WHERE clinic_id = " . (int)$appointment['clinic_id'] . " AND is_active = 1
    ");
    if ($lensStmt) {
        while ($opt = mysqli_fetch_assoc($lensStmt)) {
            $lensPrices[$opt['option_type']][] = $opt;
        }
    }
    
    $findPrice = function($optionType, $searchName) use ($lensPrices) {
        if (empty($lensPrices[$optionType]) || empty($searchName)) return 0;
        $searchNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $searchName));
        foreach ($lensPrices[$optionType] as $opt) {
            $optNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $opt['option_name']));
            if (strpos($optNorm, $searchNorm) !== false 
                || strpos($searchNorm, $optNorm) !== false) {
                return floatval($opt['price']);
            }
        }
        return 0;
    };
    
    if (!empty($appointment['rec_lens_design'])) {
        $designPrice = $findPrice('design', $appointment['rec_lens_design']);
        $payment_items[] = [
            'name' => $appointment['rec_lens_design'] . ' Lens',
            'price' => $designPrice,
            'quantity' => 1,
            'source' => 'doctor_recommendation'
        ];
    }
    
    if (!empty($appointment['rec_lens_index'])) {
        $indexPrice = $findPrice('index', $appointment['rec_lens_index']);
        $payment_items[] = [
            'name' => $appointment['rec_lens_index'],
            'price' => $indexPrice,
            'quantity' => 1,
            'source' => 'doctor_recommendation'
        ];
    }
    
    $recFeatures = [];
    if (!empty($appointment['rec_lens_features'])) {
        $decoded = json_decode($appointment['rec_lens_features'], true);
        if (is_array($decoded)) $recFeatures = $decoded;
    }
    foreach ($recFeatures as $featureName) {
        $featurePrice = $findPrice('feature', $featureName);
        $payment_items[] = [
            'name' => $featureName,
            'price' => $featurePrice,
            'quantity' => 1,
            'source' => 'doctor_recommendation'
        ];
    }
}

// ✅ Compute subtotal
foreach ($payment_items as $item) {
    $price = floatval($item['price'] ?? $item['unit_price'] ?? 0);
    $qty = intval($item['quantity'] ?? 1);
    $payment_subtotal += $price * $qty;
}

// ✅ Get discount details
$payment_discount_type = $appointment['discount_type'] ?? 'none';
$payment_discount_pct = floatval($appointment['discount_percentage'] ?? 0);
$payment_discount = floatval($appointment['discount_amount'] ?? 0);
$payment_vat = floatval($appointment['vat_amount'] ?? 0);
$payment_total = floatval($appointment['total_amount'] ?? 0);

// ✅ BIR-COMPLIANT: VAT-exclusive muna bago discount
if ($payment_subtotal > 0) {
    if ($payment_discount_type !== 'none' && $payment_discount_pct > 0) {
        // Step 1: Alisin ang VAT para makuha ang VAT-exclusive
        $vat_exclusive = $payment_subtotal / 1.12;

        // Step 2: Discount sa VAT-exclusive amount
        $payment_discount = $vat_exclusive * ($payment_discount_pct / 100);

        // Step 3: Total = VAT-exclusive − discount (VAT-exempt na)
        $payment_total = $vat_exclusive - $payment_discount;
        $payment_vat   = 0;
    } else {
        // Regular customer — may VAT
        $payment_discount = 0;
        $payment_vat      = $payment_subtotal - ($payment_subtotal / 1.12);
        $payment_total    = $payment_subtotal;
    }
}

// ✅ Get amount paid
$payment_amount_paid = floatval($appointment['amount_paid'] ?? 0);
if ($payment_amount_paid == 0) {
    $payment_amount_paid = $downpayment_amount;
}

// ✅ Balance
$payment_balance = max(0, $payment_total - $payment_amount_paid);

// ✅ Payment status
if ($payment_amount_paid >= $payment_total && $payment_total > 0) {
    $payment_status_label = 'PAID';
    $payment_status_class = 'paid';
} elseif ($payment_amount_paid > 0 && $payment_amount_paid < $payment_total) {
    $payment_status_label = 'PARTIAL';
    $payment_status_class = 'partial';
}

// ── Image helpers ──────────────────────────────────────────────────────────────
function resolveImgPath3($path) {
    if (empty($path)) return '';
    $path = str_replace(['uploads/uploads/','uploads//uploads/'],'uploads/',$path);
    if (strpos($path,'/')===0) return $path;
    if (strpos($path,'uploads/')===0) return '/'.$path;
    return '/uploads/products/'.ltrim($path,'/');
}

function getProductImages($product) {
    $imgs=[];
    if (!empty($product['product_images_json'])) {
        $dec=json_decode($product['product_images_json'],true);
        if (is_array($dec)&&count($dec)){foreach($dec as $i){$u=resolveImgPath3($i);if($u)$imgs[]=$u;}if($imgs)return $imgs;}
    }
    if (!empty($product['product_images_old'])) {
        $raw=$product['product_images_old'];
        if (strpos($raw,'[')===0){$dec=json_decode($raw,true);if(is_array($dec)&&count($dec)){foreach($dec as $i){$u=resolveImgPath3($i);if($u)$imgs[]=$u;}if($imgs)return $imgs;}}
        else{$u=resolveImgPath3($raw);if($u)return[$u];}
    }
    if (!empty($product['product_image'])){
        $p=$product['product_image'];
        if(strpos($p,'uploads/')===false&&strpos($p,'/')===false)return['/assets/images/products/'.$p];
        $u=resolveImgPath3($p);if($u)return[$u];
    }
    return['/assets/img/no-image.png'];
}

$product_images = getProductImages($appointment);
$img_count      = count($product_images);
$has_product_img = !($img_count===1 && strpos($product_images[0],'no-image.png')!==false);

if (!function_exists('getClinicImg')) { function getClinicImg($c){if(!empty($c['cover_photo']))return'/assets/images/clinic-covers/'.$c['cover_photo'];if(!empty($c['clinic_image']))return'/assets/images/clinic-images/'.$c['clinic_image'];if(!empty($c['logo']))return'/assets/images/clinic-logos/'.$c['logo'];return null;} }
$clinic_img_fallback = getClinicImg($appointment);
if (!$has_product_img && $clinic_img_fallback) {
    $product_images = [$clinic_img_fallback];
    $img_count      = 1;
    $has_img        = true;
} else {
    $has_img = $has_product_img;
}

$category_icons=['Frames'=>'fa-glasses','Contact Lenses'=>'fa-eye','Service'=>'fa-stethoscope',
                 'Lenses'=>'fa-eye','Accessories'=>'fa-shopping-bag','Eyeglasses'=>'fa-glasses','Sunglasses'=>'fa-sunglasses'];
$category_icon = $category_icons[$appointment['product_category']]??'fa-box';

$user      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$user_id"));
$user_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT avatar FROM users WHERE id=$user_id"));
$pending   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id AND status='pending'"))['total']??0;
$unread_count = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user_id) : 0;
$recent_notifications = function_exists('getRecentNotifications') ? getRecentNotifications($user_id) : [];
$total_bookings = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM appointments WHERE user_id=$user_id"))['total']??0;
$total_points   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"))['t']??0;

$clinic_rating_data = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(AVG(rating),0) as avg_rating,COUNT(*) as total_reviews FROM clinic_reviews WHERE clinic_id={$appointment['clinic_id']}"));
$clinic_avg_rating   = round($clinic_rating_data['avg_rating'],1);
$clinic_total_reviews = $clinic_rating_data['total_reviews'];

$apd = new DateTime($appointment['appointment_date']);
$formatted_date = $apd->format('F j, Y');
$formatted_day  = $apd->format('l');

// ✅ TAMA: gamitin ang appointment_time
$formatted_time = !empty($appointment['appointment_time']) 
    ? date('g:i A', strtotime($appointment['appointment_time'])) 
    : $apd->format('g:i A');

$reviews_query = mysqli_query($conn,"
    SELECT r.*,
           CONCAT(u.first_name,' ',u.last_name) as reviewer_name,
           GROUP_CONCAT(ri.image_path SEPARATOR '||') as review_image_list
    FROM clinic_reviews r
    JOIN users u ON r.user_id=u.id
    LEFT JOIN clinic_review_images ri ON ri.review_id = r.id
    WHERE r.clinic_id={$appointment['clinic_id']}
    GROUP BY r.id
    ORDER BY r.created_at DESC
    LIMIT 3
");

$now=new DateTime(); $interval=$now->diff($apd); $days_until=$interval->days;
if ($apd<$now&&!in_array($appointment['status'],['completed','cancelled','missed','no-show'])){$status_message='Past appointment';$status_class='past';}
elseif($days_until==0){$status_message='Today!';$status_class='today';}
elseif($days_until==1){$status_message='Tomorrow';$status_class='upcoming';}
else{$status_message="In $days_until days";$status_class='upcoming';}

function renderStarRating($rating) {
    $full=floor($rating); $half=($rating-$full)>=0.5; $empty=5-$full-($half?1:0);
    $h=''; for($i=0;$i<$full;$i++)$h.='<i class="fas fa-star"></i>'; if($half)$h.='<i class="fas fa-star-half-alt"></i>'; for($i=0;$i<$empty;$i++)$h.='<i class="far fa-star"></i>'; return $h;
}
if(!function_exists('timeAgo')){function timeAgo($ts){$d=time()-strtotime($ts);if($d<=60)return'Just Now';if($d<=3600)return round($d/60).'m ago';if($d<=86400)return round($d/3600).'h ago';if($d<=604800)return round($d/86400).'d ago';return date('M j, Y',strtotime($ts));}}
if(!function_exists('getThemeClass')){function getThemeClass(){return(isset($_COOKIE['theme'])&&$_COOKIE['theme']==='dark')?'theme-dark':'';}}
if(!function_exists('getClinicImg')){function getClinicImg($c){if(!empty($c['cover_photo']))return'/assets/images/clinic-covers/'.$c['cover_photo'];if(!empty($c['clinic_image']))return'/assets/images/clinic-images/'.$c['clinic_image'];if(!empty($c['logo']))return'/assets/images/clinic-logos/'.$c['logo'];return null;}}
function calculateWarrantyEndDate($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return null;
    
    $date = new DateTime($purchase_date);
    
    $period_map = [
        '3_months' => '+3 months',
        '6_months' => '+6 months',
        '12_months' => '+12 months',
        '24_months' => '+24 months',
        '36_months' => '+36 months'
    ];
    
    if (isset($period_map[$warranty_period])) {
        $date->modify($period_map[$warranty_period]);
        return $date->format('Y-m-d');
    }
    
    return null;
}

function isWithinWarranty($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return false;
    if ($warranty_period == 'no_warranty') return false;
    
    $end_date = calculateWarrantyEndDate($purchase_date, $warranty_period);
    if (!$end_date) return false;
    
    $today = date('Y-m-d');
    return $end_date >= $today;
}

function getWarrantyPeriodDisplay($warranty_period) {
    $labels = [
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '12_months' => '12 Months',
        '24_months' => '24 Months',
        '36_months' => '36 Months'
    ];
    return $labels[$warranty_period] ?? '';
}

$has_warranty = false;
$is_within_warranty = false;
$warranty_end_date = null;
$warranty_display = '';
$can_claim_warranty = false;
$warranty_coverage = [];
$warranty_terms = '';

if (!empty($appointment['product_id']) && !empty($appointment['warranty_period']) && $appointment['warranty_period'] != 'no_warranty') {
    $has_warranty = true;
    $warranty_display = getWarrantyPeriodDisplay($appointment['warranty_period']);
    
    if (!empty($appointment['warranty_coverage'])) {
        $warranty_coverage = json_decode($appointment['warranty_coverage'], true);
        if (!is_array($warranty_coverage)) $warranty_coverage = [];
    }
    if (!empty($appointment['warranty_terms'])) {
        $warranty_terms = $appointment['warranty_terms'];
    }
    
    $purchase_date_to_use = !empty($appointment['purchase_date']) 
        ? $appointment['purchase_date'] 
        : $appointment['appointment_date'];

    if (!empty($purchase_date_to_use)) {
        $warranty_end_date = calculateWarrantyEndDate($purchase_date_to_use, $appointment['warranty_period']);
        $is_within_warranty = isWithinWarranty($purchase_date_to_use, $appointment['warranty_period']);
        $can_claim_warranty = ($appointment['status'] == 'completed' && $is_within_warranty);
    }
}

$existing_claim = null;
if (!empty($appointment['product_id'])) {
    $wc = mysqli_query($conn, "SELECT id, status FROM warranty_claims 
                               WHERE product_id = {$appointment['product_id']} 
                               AND user_id = $user_id 
                               AND status IN ('pending', 'processing', 'approved', 'arrived', 'completed', 'rejected')
                               LIMIT 1");
    if (mysqli_num_rows($wc) > 0) {
        $existing_claim = mysqli_fetch_assoc($wc);
        $can_claim_warranty = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Appointment Details - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg-primary); color:var(--text-primary); min-height:100vh; transition:all 0.3s; }
        .main-content { max-width:1200px; margin:0 auto; padding:28px 40px; }
        @media(max-width:1024px){.main-content{padding:24px;}}
        @media(max-width:768px){.main-content{padding:18px 16px 100px;}}

        .top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;flex-wrap:wrap;gap:15px;}
        .page-title{display:flex;align-items:center;gap:12px;}
        .page-title i{font-size:28px;color:var(--primary);background:var(--primary-light);width:50px;height:50px;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-full);}
        .page-title h1{font-size:24px;font-weight:700;color:var(--text-primary);}
        .back-btn{display:flex;align-items:center;gap:8px;padding:10px 20px;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--radius-full);color:var(--text-secondary);text-decoration:none;font-size:14px;font-weight:500;transition:all 0.3s;}
        .back-btn:hover{background:var(--primary);color:white;border-color:var(--primary);}

        .status-banner{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:20px 30px;margin-bottom:25px;border:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;}
        .status-info{display:flex;align-items:center;gap:15px;}
        .status-icon{width:60px;height:60px;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;font-size:28px;}
        .status-icon.pending{background:#FFF3E0;color:#F57C00;} .status-icon.confirmed{background:#E8F5E9;color:#388E3C;} .status-icon.cancelled{background:#FFEBEE;color:#EF4444;} .status-icon.completed{background:#E3F2FD;color:#1976D2;} .status-icon.missed{background:#F3E8FF;color:#7C3AED;} .status-icon.paid{background:#E8F5E9;color:#00B761;} .status-icon.no-show{background:#F3F4F6;color:#4B5563;}
        .status-text h3{font-size:18px;font-weight:700;margin-bottom:5px;color:var(--text-primary);}
        .status-text p{color:var(--text-secondary);font-size:13px;}
        .status-badge{padding:10px 25px;border-radius:var(--radius-full);font-weight:600;font-size:14px;}
        .status-badge.pending{background:#FFF3E0;color:#F57C00;} .status-badge.confirmed{background:#E8F5E9;color:#388E3C;} .status-badge.cancelled{background:#FFEBEE;color:#EF4444;} .status-badge.completed{background:#E3F2FD;color:#1976D2;} .status-badge.missed{background:#F3E8FF;color:#7C3AED;} .status-badge.paid{background:#E8F5E9;color:#00B761;} .status-badge.no-show{background:#F3F4F6;color:#4B5563;} .status-badge.upcoming{background:var(--primary-light);color:var(--primary-dark);} .status-badge.today{background:#FFF1E6;color:#FF8C42;} .status-badge.past{background:#F5F5F5;color:var(--text-muted);}

        .downpayment-info{background:#FFF3E0;border-radius:var(--radius-md);padding:12px 15px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
        .downpayment-info i{color:#F57C00;} .downpayment-info .amount{font-weight:700;color:#F57C00;font-size:16px;}

        /* ✅ FIXED: Refund completed card */
        .refund-completed-card{background:#D1FAE5; border:1px solid #6EE7B7; border-radius:var(--radius-md); padding:15px; margin-bottom:20px;}
        .refund-completed-card .header{display:flex; align-items:center; gap:8px; color:#065F46; font-weight:700; font-size:15px; margin-bottom:8px;}
        .refund-completed-card .details{color:#065F46; font-size:13px; line-height:1.7;}
        .refund-completed-card .details strong{font-weight:600;}

        /* No-Show Info Box */
        .no-show-info { background:#FEF2F2; border:1px solid #FCA5A5; border-radius:var(--radius-md); padding:15px; margin-bottom:20px; }
        .no-show-info .forfeited-amount { font-size:20px; font-weight:700; color:#DC2626; }

        .product-gallery { background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--border-light); overflow: hidden; margin-bottom: 25px; }
        .gallery-slider-wrap { position: relative; width: 100%; height: 380px; overflow: hidden; background: var(--bg-primary); }
        .pc-slider-track { display: flex; width: 100%; height: 100%; transition: transform 0.4s ease; will-change: transform; }
        .pc-slide { min-width: 100%; width: 100%; height: 100%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--bg-primary); }
        .pc-slide img { width: 100%; height: 100%; max-width: 100%; max-height: 380px; object-fit: contain; padding: 8px; cursor: zoom-in; display: block; }
        .gallery-dots-row { display: flex; justify-content: center; gap: 8px; padding: 12px 0; background: var(--bg-secondary); }
        .gallery-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--border-color); cursor: pointer; transition: all 0.2s; border: none; padding: 0; }
        .gallery-dot.active { width: 24px; border-radius: 4px; background: var(--primary); }
        .gallery-dot:hover  { background: var(--primary); opacity: 0.7; }
        .gallery-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; background: rgba(0,0,0,0.45); border: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; z-index: 10; backdrop-filter: blur(4px); }
        .gallery-arrow:hover { background: var(--primary); }
        .gallery-arrow i { color: white; font-size: 18px; }
        .gallery-arrow.prev { left: 16px; }
        .gallery-arrow.next { right: 16px; }
        .gallery-counter { position: absolute; bottom: 16px; right: 16px; background: rgba(0,0,0,0.6); color: white; padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 500; z-index: 10; }
        .gallery-placeholder { text-align:center; padding:60px 20px; }
        .gallery-placeholder i { font-size:80px; color:var(--primary); opacity:0.5; margin-bottom:16px; display:block; }
        .gallery-placeholder h3 { font-size:18px; font-weight:600; margin-bottom:8px; }
        .gallery-placeholder p { color:var(--text-muted); font-size:13px; }

        .product-info-card{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:20px;border:1px solid var(--border-light);margin-bottom:25px;}
        .product-info-header{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border-light);}
        .product-info-header i{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;}
        .product-info-header h2{font-size:18px;font-weight:700;color:var(--text-primary);}
        .product-name{font-size:20px;font-weight:700;color:var(--text-primary);margin-bottom:8px;}
        .product-price{font-size:24px;font-weight:800;color:var(--primary);margin-bottom:12px;}
        .product-description{color:var(--text-secondary);font-size:14px;line-height:1.6;margin-bottom:16px;}
        .product-category-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--bg-primary);border-radius:var(--radius-full);font-size:12px;color:var(--text-secondary);}

        .details-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:25px;margin-bottom:25px;}
        @media(max-width:768px){.details-grid{grid-template-columns:1fr;}}
        .detail-card{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:25px;border:1px solid var(--border-light);box-shadow:var(--shadow-sm);}
        .card-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:15px;border-bottom:1px solid var(--border-light);}
        .card-header i{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;}
        .card-header h2{font-size:18px;font-weight:700;color:var(--text-primary);}
        .appointment-info{display:flex;flex-direction:column;gap:15px;}
        .info-row{display:flex;align-items:flex-start;gap:15px;}
        .info-icon{width:36px;height:36px;background:var(--bg-primary);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:16px;flex-shrink:0;}
        .info-content{flex:1;}
        .info-label{font-size:12px;color:var(--text-muted);margin-bottom:4px;}
        .info-value{font-size:16px;font-weight:600;color:var(--text-primary);}
        .info-sub{font-size:13px;color:var(--text-secondary);margin-top:2px;}

        .clinic-header-block{display:flex;align-items:center;gap:15px;margin-bottom:20px;}
        .clinic-avatar{width:60px;height:60px;border-radius:var(--radius-md);overflow:hidden;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:white;font-size:28px;}
        .clinic-avatar img{width:100%;height:100%;object-fit:cover;}
        .clinic-header-block h3{font-size:18px;font-weight:700;margin-bottom:5px;color:var(--text-primary);}
        .clinic-rating{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .clinic-rating .stars{display:inline-flex;gap:2px;color:#FFC107;font-size:12px;}
        .clinic-rating .rating-value{font-weight:600;color:var(--text-primary);font-size:13px;}
        .clinic-rating .reviews-count{color:var(--text-muted);font-size:12px;}
        .no-rating{color:var(--text-muted);font-size:12px;display:flex;align-items:center;gap:5px;}
        .clinic-details-list{display:flex;flex-direction:column;gap:15px;margin-bottom:20px;}
        .clinic-detail-item{display:flex;align-items:flex-start;gap:12px;}
        .clinic-detail-item i{width:20px;color:var(--primary);font-size:14px;margin-top:2px;}
        .clinic-detail-item strong{display:block;font-size:13px;font-weight:600;margin-bottom:3px;color:var(--text-primary);}
        .clinic-detail-item span{font-size:13px;color:var(--text-secondary);}
        .clinic-actions{display:flex;gap:10px;margin-top:20px;}
        .btn-primary,.btn-outline{flex:1;padding:12px;border-radius:var(--radius-md);font-size:14px;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.3s;cursor:pointer;}
        .btn-primary{background:var(--primary-gradient);color:white;border:none;}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,183,97,0.3);}
        .btn-outline{border:1.5px solid var(--primary);color:var(--primary);background:transparent;}
        .btn-outline:hover{background:var(--primary);color:white;}

        .reviews-section{background:var(--bg-secondary);border-radius:var(--radius-lg);padding:25px;border:1px solid var(--border-light);margin-bottom:25px;}
        .reviews-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px;}
        .reviews-header h3{font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px;color:var(--text-primary);}
        .review-card{padding:15px 0;border-bottom:1px solid var(--border-light);}
        .review-card:last-child{border-bottom:none;}
        .review-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
        .reviewer{display:flex;align-items:center;gap:10px;}
        .reviewer i{width:32px;height:32px;background:var(--bg-primary);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:14px;}
        .reviewer strong{font-size:14px;font-weight:600;color:var(--text-primary);}
        .review-rating{display:flex;gap:2px;color:#FFC107;font-size:12px;}
        .review-text{font-size:14px;color:var(--text-secondary);line-height:1.5;margin-bottom:8px;}
        .review-date{font-size:11px;color:var(--text-muted);}
        .review-photos{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;}
        .review-photos img{width:64px;height:64px;object-fit:cover;border-radius:var(--radius-md);border:1px solid var(--border-light);cursor:zoom-in;transition:transform 0.15s;}
        .review-photos img:hover{transform:scale(1.05);}
        .btn-write-review{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-full);color:var(--text-primary);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.3s;}
        .btn-write-review:hover{background:var(--primary);color:white;border-color:var(--primary);}
        .empty-reviews{text-align:center;padding:40px;color:var(--text-muted);}
        .empty-reviews i{font-size:48px;margin-bottom:10px;opacity:0.5;}

        .action-buttons{display:flex;gap:15px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px;}
        .action-btn{padding:12px 25px;border-radius:var(--radius-full);font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all 0.3s;border:none;text-decoration:none;}
        .action-btn-danger{background:#EF4444;color:white;} .action-btn-danger:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(239,68,68,0.3);}
        .action-btn-primary{background:var(--primary-gradient);color:white;} .action-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,183,97,0.3);}
        .action-btn-secondary{background:var(--bg-primary);border:1px solid var(--border-color);color:var(--text-primary);} .action-btn-secondary:hover{background:var(--primary);color:white;border-color:var(--primary);}
        .action-btn-warning{background:#F59E0B;color:white;} .action-btn-warning:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(245,158,11,0.3);}
        .action-btn-success{background:#00B761;color:white;cursor:default;} .action-btn-success:hover{transform:none;}
                /* ═══════════════════════════════════════════════════════
           ✅ PAYMENT SUMMARY CARD (Compact)
           ═══════════════════════════════════════════════════════ */
        .payment-summary-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 22px 25px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            margin-bottom: 25px;
        }

        .payment-summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-light);
            flex-wrap: wrap;
            gap: 12px;
        }

        .payment-summary-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .payment-summary-title i {
            width: 36px;
            height: 36px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 16px;
            flex-shrink: 0;
        }

        .payment-summary-title h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .payment-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .payment-status-badge.paid { background: #D1FAE5; color: #065F46; }
        .payment-status-badge.partial { background: #FEF3C7; color: #92400E; }
        .payment-status-badge.unpaid { background: #FEE2E2; color: #991B1B; }
        .theme-dark .payment-status-badge.paid { background: #064E3B; color: #A7F3D0; }
        .theme-dark .payment-status-badge.partial { background: #78350F; color: #FDE68A; }
        .theme-dark .payment-status-badge.unpaid { background: #7F1D1D; color: #FECACA; }

        .payment-breakdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
            margin-bottom: 14px;
        }
        @media (max-width: 500px) {
            .payment-breakdown-grid { grid-template-columns: 1fr; }
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            padding: 4px 0;
        }

        .payment-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .payment-value {
            font-weight: 700;
            color: var(--text-primary);
        }

        .payment-row-discount .payment-value { color: #DC2626; }
        .payment-row-vat .payment-value { color: #065F46; }
        .theme-dark .payment-row-discount .payment-value { color: #FCA5A5; }
        .theme-dark .payment-row-vat .payment-value { color: #6EE7B7; }

        .payment-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-top: 2px solid var(--primary);
            border-bottom: 2px solid var(--primary);
            font-size: 16px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 14px;
        }

        .payment-paid-box {
            background: linear-gradient(135deg, #F0FDF4, #ECFDF5);
            border: 1px solid #BBF7D0;
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 14px;
        }
        .theme-dark .payment-paid-box {
            background: linear-gradient(135deg, #052E16, #064E3B);
            border-color: #065F46;
        }

        .payment-paid-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: 600;
            color: #065F46;
            padding: 3px 0;
        }
        .theme-dark .payment-paid-line { color: #86EFAC; }

        .payment-paid-line i { margin-right: 4px; }

        .payment-paid-amount {
            font-size: 16px;
            font-weight: 800;
            color: #059669;
        }

        .payment-balance-line { border-top: 1px dashed #86EFAC; margin-top: 6px; padding-top: 6px; }
        .payment-balance-amount {
            font-size: 14px;
            font-weight: 700;
            color: #F59E0B;
        }

        .payment-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .payment-ref {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .payment-ref i { color: var(--primary); }
        .payment-ref strong {
            color: var(--text-primary);
            font-family: monospace;
            font-size: 12px;
        }

        .payment-view-receipt-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .payment-view-receipt-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,183,97,0.3);
            color: white;
        }

        @media (max-width: 500px) {
            .payment-summary-card { padding: 18px 16px; }
            .payment-summary-title h2 { font-size: 15px; }
            .payment-total-row { font-size: 15px; }
            .payment-footer { flex-direction: column; align-items: stretch; }
            .payment-view-receipt-btn { justify-content: center; }
        }
        .image-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:10000;cursor:pointer;align-items:center;justify-content:center;}
        .image-modal.show{display:flex;}
        .modal-image{max-width:90%;max-height:90%;object-fit:contain;border-radius:var(--radius-lg);}
        .modal-close{position:absolute;top:20px;right:30px;color:white;font-size:40px;cursor:pointer;transition:all 0.2s;z-index:10001;}
        .modal-close:hover{color:var(--primary);}

        :root{--primary:#00B761;--primary-dark:#00874A;--primary-light:#E3FCE9;--primary-gradient:linear-gradient(135deg,#00B761,#00A86B);--secondary:#FF8C42;--bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;--card-bg:#FFFFFF;--text-primary:#111827;--text-secondary:#6B7280;--text-muted:#9CA3AF;--border-color:#E5E7EB;--border-light:#F3F4F6;--shadow-sm:0 1px 3px rgba(0,0,0,0.06);--shadow-md:0 4px 16px rgba(0,0,0,0.08);--radius-sm:10px;--radius-md:14px;--radius-lg:20px;--radius-xl:28px;--radius-full:999px;--danger:#EF4444;--warning:#F59E0B;--success:#00B761;--info:#3B82F6;}
        .theme-dark{--primary:#00E676;--primary-dark:#00C853;--primary-light:#0D2818;--bg-primary:#0D0D0D;--bg-secondary:#161616;--card-bg:#1E1E1E;--text-primary:#F9FAFB;--text-secondary:#9CA3AF;--text-muted:#6B7280;--border-color:#2A2A2A;--border-light:#222222;}
        
        .warranty-section { background: var(--bg-primary); border-radius: var(--radius-md); padding: 16px; margin-top: 16px; }
        .warranty-badge { background: var(--primary-light); color: var(--primary); padding: 2px 8px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; margin-left: 8px; }
        .warranty-status { padding: 8px 12px; border-radius: var(--radius-md); font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .warranty-status.active { background: #E8F5E9; color: #2E7D32; }
        .warranty-status.expired { background: #FFEBEE; color: #EF4444; }
        .theme-dark .warranty-status.active { background: #0D2818; color: #00E676; }
        .theme-dark .warranty-status.expired { background: #3B0F0F; color: #EF4444; }
        .coverage-tags { display: flex; flex-wrap: wrap; gap: 6px; }
        .coverage-tag { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-full); padding: 2px 10px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; }
        .coverage-tag i { color: var(--success); font-size: 9px; }
        .warranty-terms p { font-size: 12px; color: var(--text-secondary); line-height: 1.5; margin: 0; }
        .btn-warranty-claim { background: linear-gradient(135deg, #00B761, #00A86B); color: white; border: none; border-radius: var(--radius-md); padding: 12px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-warranty-claim:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,183,97,0.3); }
        .btn-warranty-expired { background: #9CA3AF; color: white; border: none; border-radius: var(--radius-md); padding: 12px 16px; font-size: 14px; font-weight: 600; cursor: not-allowed; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; opacity: 0.7; }
        .warranty-note { text-align: center; padding: 8px; }
        .theme-dark .swal2-popup { background:#1E1E1E; color:#F9FAFB; }
        .theme-dark .swal2-title { color:#F9FAFB; }
        .theme-dark .swal2-html-container { color:#9CA3AF; }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>  
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <i class="fas fa-calendar-check"></i>
                <h1>Appointment Details</h1>
            </div>
            <a href="my-appointments.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Appointments</a>
        </div>

        <!-- Status Banner -->
        <div class="status-banner">
            <div class="status-info">
                <div class="status-icon <?php echo $appointment['status']; ?>">
                    <i class="fas fa-<?php echo ['pending'=>'clock','confirmed'=>'check-circle','cancelled'=>'times-circle','missed'=>'calendar-times','paid'=>'money-bill-wave','completed'=>'check-double','no-show'=>'user-times'][$appointment['status']]??'clock'; ?>"></i>
                </div>
                <div class="status-text">
                    <h3>Appointment <?php echo $appointment['status']==='missed'?'Missed':($appointment['status']==='no-show'?'No-Show':ucfirst($appointment['status'])); ?></h3>
                    <p>Booking reference: #<?php echo str_pad($appointment['id'],6,'0',STR_PAD_LEFT); ?></p>
                </div>
            </div>
            <div class="status-badge <?php echo $status_class; ?>"><?php echo $status_message; ?></div>
        </div>

        <!-- Downpayment Info with Refund Eligibility & No-Show Info -->
        <?php if ($downpayment_amount > 0): ?>
        <div class="downpayment-info">
            <div><i class="fas fa-receipt"></i> <strong> Amount Paid:</strong> <span class="amount">₱<?php echo number_format($downpayment_amount,2); ?></span></div>
            
            <?php if ($is_no_show): ?>
                <!-- ✅ NO-SHOW DISPLAY -->
                <div><i class="fas fa-user-times" style="color: #6B7280;"></i> <span class="badge bg-secondary">No-Show</span></div>
                
                <?php if ($forfeited_amount > 0): ?>
                <div><i class="fas fa-times-circle" style="color: #DC2626;"></i> <span>Forfeited: <strong style="color: #DC2626;">₱<?php echo number_format($forfeited_amount, 2); ?></strong></span></div>
                <?php endif; ?>
                
                <?php if ($refund_eligible == 1 && $refund_percent > 0): ?>
                <div><i class="fas fa-check-circle" style="color: #00B761;"></i> <span>Refund: <strong><?php echo $refund_percent; ?>%</strong> (₱<?php echo number_format($eligible_refund, 2); ?>)</span></div>
                <?php endif; ?>
                
            <?php elseif ($appointment['status'] == 'cancelled'): ?>
                <?php if ($refund_completed): ?>
                    <div><i class="fas fa-check-circle" style="color: #065F46;"></i> <span style="color:#065F46; font-weight:600;">Refund Completed: ₱<?php echo number_format($refund_data['amount'] ?? $eligible_refund, 2); ?></span></div>
                <?php elseif ($refund_pending): ?>
                    <div><i class="fas fa-spinner fa-pulse"></i> <span>Refund request pending...</span></div>
                <?php elseif ($refund_rejected): ?>
                    <div><i class="fas fa-times-circle" style="color: #EF4444;"></i> <span>Refund request rejected</span></div>
                <?php elseif ($refund_available): ?>
                    <div><i class="fas fa-check-circle" style="color: #00B761;"></i> <span>Refund eligible: <strong><?php echo $refund_percent; ?>%</strong> (₱<?php echo number_format($eligible_refund, 2); ?>)</span></div>
                <?php elseif (!$refund_available && $refund_percent == 0): ?>
                    <div><i class="fas fa-times-circle" style="color: #EF4444;"></i> <span>No refund available (0% per clinic policy)</span></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ✅ FIXED: Refund Completed Success Card -->
        <?php if ($refund_completed && $refund_data): ?>
        <div class="refund-completed-card">
            <div class="header">
                <i class="fas fa-check-circle" style="font-size:20px;"></i>
                Refund Completed
            </div>
            <div class="details">
                <div><strong>Amount Refunded:</strong> ₱<?php echo number_format($refund_data['amount'] ?? $eligible_refund, 2); ?> (<?php echo $refund_percent; ?>%)</div>
                <?php if (!empty($refund_data['paymongo_refund_id'])): ?>
                    <div><strong>Refund ID:</strong> <?php echo htmlspecialchars($refund_data['paymongo_refund_id']); ?></div>
                <?php endif; ?>
                <?php if (!empty($refund_data['refund_date'])): ?>
                    <div><strong>Refunded on:</strong> <?php echo date('F j, Y g:i A', strtotime($refund_data['refund_date'])); ?></div>
                <?php endif; ?>
                <div style="margin-top:8px; font-size:12px; color:#047857;">
                    <i class="fas fa-info-circle"></i> Amount will reflect in your account within 3-5 business days.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
             PRODUCT GALLERY
             ══════════════════════════════════════════════ -->
        <?php if (!empty($appointment['product_name']) || $has_img): ?>
        <div class="product-gallery">
            <div class="gallery-slider-wrap" id="galleryWrap">
                <?php if ($has_img): ?>
                    <div class="pc-slider-track" id="galleryTrack">
                        <?php foreach ($product_images as $imgUrl): ?>
                        <div class="pc-slide">
                            <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                 alt="<?php echo htmlspecialchars($appointment['product_name']??'Product'); ?>"
                                 onclick="openImageModal('<?php echo htmlspecialchars($imgUrl); ?>','<?php echo htmlspecialchars($appointment['product_name']??'Product'); ?>')"
                                 onerror="this.onerror=null;this.src='/assets/img/no-image.png'">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($img_count > 1): ?>
                    <button class="gallery-arrow prev" onclick="galleryGo(galleryCurrent-1)"><i class="fas fa-chevron-left"></i></button>
                    <button class="gallery-arrow next" onclick="galleryGo(galleryCurrent+1)"><i class="fas fa-chevron-right"></i></button>
                    <div class="gallery-counter" id="galleryCounter"><i class="fas fa-images"></i> 1 / <?php echo $img_count; ?></div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="gallery-placeholder">
                        <i class="fas <?php echo $category_icon; ?>"></i>
                        <h3><?php echo htmlspecialchars($appointment['product_name']??'Product Image'); ?></h3>
                        <p>No image available</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_img && $img_count > 1): ?>
            <div class="gallery-dots-row" id="galleryDots">
                <?php for($i=0;$i<$img_count;$i++): ?>
                <button class="gallery-dot <?php echo $i===0?'active':''; ?>" onclick="galleryGo(<?php echo $i; ?>)"></button>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($appointment['product_name'])): ?>
        <div class="product-info-card">
            <div class="product-info-header"><i class="fas fa-box"></i><h2>Product Information</h2></div>
            <div class="product-name"><?php echo htmlspecialchars($appointment['product_name']); ?></div>
            <?php if (!empty($appointment['product_price'])): ?><div class="product-price">₱<?php echo number_format($appointment['product_price'],2); ?></div><?php endif; ?>
            <?php if (!empty($appointment['product_description'])): ?><div class="product-description"><?php echo nl2br(htmlspecialchars($appointment['product_description'])); ?></div><?php endif; ?>
            <?php if (!empty($appointment['product_category'])): ?><div class="product-category-badge"><i class="fas <?php echo $category_icon; ?>"></i> <?php echo htmlspecialchars($appointment['product_category']); ?></div><?php endif; ?>
            
            <?php if ($has_warranty): ?>
            <div class="warranty-section mt-3 pt-3 border-top">
                <div class="warranty-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <i class="fas fa-shield-alt" style="color: var(--primary);"></i>
                    <strong>Warranty Information</strong>
                    <?php if ($warranty_display): ?>
                    <span class="warranty-badge"><?php echo $warranty_display; ?> Warranty</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($is_within_warranty && $warranty_end_date): ?>
                <div class="warranty-status active">
                    <i class="fas fa-check-circle"></i> Warranty Active until <?php echo date('F d, Y', strtotime($warranty_end_date)); ?>
                </div>
                <?php elseif ($has_warranty && !$is_within_warranty && $warranty_end_date): ?>
                <div class="warranty-status expired">
                    <i class="fas fa-clock"></i> Warranty Expired on <?php echo date('F d, Y', strtotime($warranty_end_date)); ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($warranty_coverage)): ?>
                <div class="warranty-coverage mt-2">
                    <small class="text-muted">Covers:</small>
                    <div class="coverage-tags mt-1">
                        <?php foreach ($warranty_coverage as $cover): ?>
                        <span class="coverage-tag"><i class="fas fa-check"></i> <?php echo ucfirst(str_replace('_', ' ', $cover)); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($warranty_terms)): ?>
                <div class="warranty-terms mt-2">
                    <small class="text-muted">Terms:</small>
                    <p class="small mt-1"><?php echo nl2br(htmlspecialchars(substr($warranty_terms, 0, 200))); ?><?php echo strlen($warranty_terms) > 200 ? '...' : ''; ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($can_claim_warranty): ?>
                <button class="btn-warranty-claim mt-3" onclick="openWarrantyClaim(<?php echo !empty($appointment['reservation_id']) ? $appointment['reservation_id'] : $appointment['id']; ?>, <?php echo $appointment['product_id']; ?>, '<?php echo htmlspecialchars($appointment['product_name']); ?>')">
                    <i class="fas fa-tools"></i> Claim Warranty
                </button>
                <?php elseif (!empty($existing_claim)): ?>
                <div class="warranty-status active mt-3" style="justify-content: center;">
                    <?php if ($existing_claim['status'] == 'pending'): ?>
                        <i class="fas fa-spinner fa-pulse"></i> Warranty Claim Pending Review
                    <?php elseif ($existing_claim['status'] == 'processing'): ?>
                        <i class="fas fa-cog fa-spin"></i> Warranty Claim Being Processed
                    <?php elseif ($existing_claim['status'] == 'approved'): ?>
                        <i class="fas fa-check-circle"></i> Warranty Claim Approved — Visit clinic on scheduled date
                    <?php elseif ($existing_claim['status'] == 'arrived'): ?>
                        <i class="fas fa-door-open"></i> You have arrived — Claim being processed
                    <?php elseif ($existing_claim['status'] == 'completed'): ?>
                        <i class="fas fa-patch-check"></i> Warranty Claim Already Completed
                    <?php elseif ($existing_claim['status'] == 'rejected'): ?>
                        <i class="fas fa-times-circle"></i> Warranty Claim Was Rejected
                    <?php endif; ?>
                </div>
                <?php elseif ($has_warranty && $appointment['status'] == 'completed' && !$is_within_warranty): ?>
                <button class="btn-warranty-expired mt-3" disabled>
                    <i class="fas fa-clock"></i> Warranty Expired
                </button>
                <?php elseif ($has_warranty && $appointment['status'] != 'completed'): ?>
                <div class="warranty-note mt-2">
                    <small class="text-muted"><i class="fas fa-info-circle"></i> Warranty becomes available after appointment completion</small>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
             ✅ PAYMENT SUMMARY CARD (Compact)
             ══════════════════════════════════════════════ -->
        <?php if (!empty($payment_items) || $payment_amount_paid > 0): ?>
        <div class="payment-summary-card">
            <div class="payment-summary-header">
                <div class="payment-summary-title">
                    <i class="fas fa-receipt"></i>
                    <h2>Payment Summary</h2>
                </div>
                <div class="payment-status-badge <?php echo $payment_status_class; ?>">
                    <i class="fas fa-<?php echo $payment_status_class === 'paid' ? 'check-circle' : ($payment_status_class === 'partial' ? 'clock' : 'exclamation-circle'); ?>"></i>
                    <?php echo $payment_status_label; ?>
                </div>
            </div>

            <div class="payment-breakdown-grid">
                <div class="payment-row">
                    <span class="payment-label">Subtotal:</span>
                    <span class="payment-value">₱<?php echo number_format($payment_subtotal, 2); ?></span>
                </div>

                <?php if ($payment_discount_type !== 'none' && $payment_discount > 0): 
                    $discount_icon = $payment_discount_type === 'senior' ? '👴' : ($payment_discount_type === 'pwd' ? '♿' : '🏷️');
                ?>
                <div class="payment-row payment-row-discount">
                    <span class="payment-label">
                        <?php echo $discount_icon; ?> <?php echo strtoupper($payment_discount_type); ?> (<?php echo round($payment_discount_pct); ?>%):
                    </span>
                    <span class="payment-value">-₱<?php echo number_format($payment_discount, 2); ?></span>
                </div>
                <?php endif; ?>

                <div class="payment-row payment-row-vat">
                    <span class="payment-label">📋 VAT:</span>
                    <span class="payment-value">
                        <?php echo $payment_vat > 0 ? '+₱' . number_format($payment_vat, 2) : 'Exempt'; ?>
                    </span>
                </div>
            </div>

            <div class="payment-total-row">
                <span>TOTAL:</span>
                <span>₱<?php echo number_format($payment_total, 2); ?></span>
            </div>

            <div class="payment-paid-box">
                <div class="payment-paid-line">
                    <span><i class="fas fa-check-circle"></i> Amount Paid:</span>
                    <span class="payment-paid-amount">₱<?php echo number_format($payment_amount_paid, 2); ?></span>
                </div>
                <?php if ($payment_balance > 0): ?>
                <div class="payment-paid-line payment-balance-line">
                    <span><i class="fas fa-clock"></i> Balance:</span>
                    <span class="payment-balance-amount">₱<?php echo number_format($payment_balance, 2); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="payment-footer">
                <?php if (!empty($payment_ref)): ?>
                <div class="payment-ref">
                    <i class="fas fa-hashtag"></i>
                    <span>Ref: <strong><?php echo htmlspecialchars($payment_ref); ?></strong></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Details Grid -->
        <div class="details-grid">
            <!-- Appointment Schedule -->
            <div class="detail-card">
                <div class="card-header"><i class="fas fa-calendar-alt"></i><h2>Appointment Schedule</h2></div>
                <div class="appointment-info">
                    <div class="info-row"><div class="info-icon"><i class="fas fa-calendar-day"></i></div><div class="info-content"><div class="info-label">Date</div><div class="info-value"><?php echo $formatted_date; ?></div><div class="info-sub"><?php echo $formatted_day; ?></div></div></div>
                    <div class="info-row"><div class="info-icon"><i class="fas fa-clock"></i></div><div class="info-content"><div class="info-label">Time</div><div class="info-value"><?php echo $formatted_time; ?></div></div></div>
                    <?php if (!empty($appointment['doctor_name'])): ?>
                    <div class="info-row"><div class="info-icon"><i class="fas fa-user-md"></i></div><div class="info-content"><div class="info-label">Doctor</div><div class="info-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div><?php if(!empty($appointment['doctor_specialty'])): ?><div class="info-sub"><?php echo htmlspecialchars($appointment['doctor_specialty']); ?></div><?php endif; ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($appointment['notes'])): ?>
                    <div class="info-row"><div class="info-icon"><i class="fas fa-sticky-note"></i></div><div class="info-content"><div class="info-label">Notes</div><div class="info-value"><?php echo htmlspecialchars($appointment['notes']); ?></div></div></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Clinic Info -->
            <div class="detail-card">
                <div class="card-header"><i class="fas fa-clinic-medical"></i><h2>Clinic Information</h2></div>
                <div class="clinic-header-block">
                    <?php $ci=getClinicImg($appointment); if($ci): ?>
                        <div class="clinic-avatar"><img src="<?php echo htmlspecialchars($ci); ?>" alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>" onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-eye\'></i>'"></div>
                    <?php else: ?><div class="clinic-avatar"><i class="fas fa-eye"></i></div><?php endif; ?>
                    <div>
                        <h3><?php echo htmlspecialchars($appointment['clinic_name']); ?></h3>
                        <div class="clinic-rating">
                            <?php if($clinic_total_reviews>0): ?>
                                <div class="stars"><?php echo renderStarRating($clinic_avg_rating); ?></div>
                                <span class="rating-value"><?php echo $clinic_avg_rating; ?></span>
                                <span class="reviews-count">(<?php echo $clinic_total_reviews; ?> reviews)</span>
                            <?php else: ?><div class="no-rating"><i class="far fa-star"></i> <span>No reviews yet</span></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="clinic-details-list">
                    <div class="clinic-detail-item"><i class="fas fa-map-marker-alt"></i><div><strong>Address</strong><span><?php echo htmlspecialchars($appointment['address']); ?></span></div></div>
                    <?php if (!empty($appointment['contact'])): ?><div class="clinic-detail-item"><i class="fas fa-phone-alt"></i><div><strong>Contact</strong><span><?php echo htmlspecialchars($appointment['contact']); ?></span></div></div><?php endif; ?>
                    <?php if (!empty($appointment['hours'])): ?><div class="clinic-detail-item"><i class="fas fa-clock"></i><div><strong>Operating Hours</strong><span><?php echo htmlspecialchars($appointment['hours']); ?></span></div></div><?php endif; ?>
                </div>
                <div class="clinic-actions">
                    <a href="directions.php?clinic=<?php echo $appointment['clinic_id']; ?>" class="btn-primary"><i class="fas fa-directions"></i> Directions</a>
                    <?php if (!empty($appointment['contact'])): ?><a href="tel:<?php echo $appointment['contact']; ?>" class="btn-outline"><i class="fas fa-phone-alt"></i> Call</a><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Reviews -->
        <div class="reviews-section">
            <div class="reviews-header">
                <h3><i class="fas fa-star" style="color:#FFC107;"></i> Clinic Reviews</h3>
                <?php if ($appointment['status']=='completed'): ?>
                <a href="write-review.php?clinic=<?php echo $appointment['clinic_id']; ?>&appointment=<?php echo $appointment['id']; ?>" class="btn-write-review"><i class="fas fa-pen"></i> Write a Review</a>
                <?php endif; ?>
            </div>
            <?php if (mysqli_num_rows($reviews_query)>0): ?>
                <?php while($review=mysqli_fetch_assoc($reviews_query)): ?>
                <div class="review-card">
                    <div class="review-header">
                        <div class="reviewer"><i class="fas fa-user-circle"></i><strong><?php echo htmlspecialchars($review['reviewer_name']); ?></strong></div>
                        <div class="review-rating"><?php for($i=1;$i<=5;$i++): ?><i class="fas fa-star<?php echo $i<=$review['rating']?'':'-o'; ?>"></i><?php endfor; ?></div>
                    </div>
                    <div class="review-text"><?php echo htmlspecialchars($review['review']); ?></div>
                    <?php if (!empty($review['review_image_list'])): ?>
                    <div class="review-photos">
                        <?php foreach (explode('||', $review['review_image_list']) as $rimg): ?>
                            <?php if (trim($rimg) === '') continue; ?>
                            <img src="<?php echo htmlspecialchars($rimg); ?>" alt="Review photo" onclick="openImageModal('<?php echo htmlspecialchars($rimg); ?>','Review photo')">
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="review-date"><?php echo timeAgo($review['created_at']); ?></div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-reviews"><i class="fas fa-star-of-life"></i><p>No reviews yet. Be the first to share your experience!</p></div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <?php if ($appointment['status'] == 'waiting_payment'): ?>
                <a href="payment.php?appointment_id=<?php echo $appointment['id']; ?>&amount=<?php echo urlencode(number_format($appointment['downpayment_amount'] ?? $appointment['total_amount'] ?? 0, 2)); ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-credit-card"></i> Pay Now (₱<?php echo number_format($appointment['downpayment_amount'] ?? $appointment['total_amount'] ?? 0, 2); ?>)
                </a>
                <button onclick="cancelAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
                    <i class="fas fa-times-circle"></i> Cancel Appointment
                </button>
                
            <?php elseif (in_array($appointment['status'], ['pending', 'confirmed', 'paid'])): ?>
                <button onclick="cancelAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
                    <i class="fas fa-times-circle"></i> Cancel Appointment
                </button>
                <a href="reschedule-appointment.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary">
                    <i class="fas fa-calendar-alt"></i> Reschedule
                </a>
                
            <?php endif; ?>
            
            <?php if ($appointment['status'] == 'paid'): ?>
                <a href="view-receipt.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary" target="_blank">
                    <i class="fas fa-receipt"></i> View Receipt
                </a>
            <?php endif; ?>
            
            <?php if ($appointment['status'] == 'completed'): ?>
                <a href="write-review.php?clinic=<?php echo $appointment['clinic_id']; ?>&appointment=<?php echo $appointment['id']; ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-star"></i> Write a Review
                </a>
                <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-redo-alt"></i> Book Again
                </a>
                <a href="view-receipt.php?id=<?php echo $appointment['id']; ?>" class="action-btn action-btn-secondary" target="_blank">
                    <i class="fas fa-receipt"></i> View Receipt
                </a>
                <button onclick="shareExperience()" class="action-btn action-btn-secondary">
                    <i class="fas fa-share-alt"></i> Share
                </button>
            <?php endif; ?>
            
            <?php if ($appointment['status'] == 'cancelled'): ?>
                <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-redo-alt"></i> Book Again
                </a>
                <button onclick="deleteAppointment(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-danger">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            <?php endif; ?>
            
            <?php if ($appointment['status'] == 'missed'): ?>
                <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-redo-alt"></i> Book Again
                </a>
                <button onclick="viewPenalty(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
                    <i class="fas fa-exclamation-triangle"></i> View Penalty
                </button>
            <?php endif; ?>
            
            <?php if ($appointment['status'] == 'no-show'): ?>
                <a href="book-appointment.php?clinic=<?php echo $appointment['clinic_id']; ?>&product=<?php echo $appointment['product_id']; ?>" class="action-btn action-btn-primary">
                    <i class="fas fa-redo-alt"></i> Book Again
                </a>
                
                <?php if ($refund_completed): ?>
                    <!-- ✅ Refund COMPLETED — success button, walang action -->
                    <button class="action-btn action-btn-success" disabled>
                        <i class="fas fa-check-circle"></i> 
                        Refunded ₱<?php echo number_format($refund_data['amount'] ?? $eligible_refund, 2); ?> (<?php echo $refund_percent; ?>%)
                    </button>
                <?php elseif ($refund_pending): ?>
                    <!-- ✅ Refund PENDING/PROCESSING -->
                    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
                        <i class="fas fa-spinner fa-pulse"></i> Refund Processing...
                    </button>
                <?php elseif ($refund_rejected): ?>
                    <!-- ✅ Refund REJECTED -->
                    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
                        <i class="fas fa-times-circle"></i> Refund Rejected
                    </button>
                <?php elseif ($refund_eligible == 1 && $refund_percent > 0): ?>
                    <!-- ✅ Eligible pa lang — ipakita ang button -->
                    <button onclick="requestRefund(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
                        <i class="fas fa-money-bill-wave"></i> 
                        Request Refund (<?php echo $refund_percent; ?>% • ₱<?php echo number_format($eligible_refund, 2); ?>)
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ✅ FIXED: Refund Request Button for Cancelled -->
        <?php if ($appointment['status'] == 'cancelled' && $downpayment_amount > 0): ?>
            <?php if ($refund_completed): ?>
                <!-- ✅ Refund COMPLETED — info card na lang, walang button -->
                <div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
                    <button class="action-btn action-btn-success" disabled>
                        <i class="fas fa-check-circle"></i> 
                        Refund Completed (<?php echo $refund_percent; ?>% • ₱<?php echo number_format($refund_data['amount'] ?? $eligible_refund, 2); ?>)
                    </button>
                </div>
                
            <?php elseif ($refund_pending): ?>
                <!-- ✅ Refund PENDING/PROCESSING -->
                <div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
                    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
                        <i class="fas fa-spinner fa-pulse"></i> Refund Processing...
                    </button>
                </div>
                
            <?php elseif ($refund_rejected): ?>
                <!-- ✅ Refund REJECTED -->
                <div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
                    <button class="action-btn action-btn-secondary" disabled style="opacity:0.6; cursor:not-allowed;">
                        <i class="fas fa-times-circle"></i> Refund Request Rejected
                    </button>
                </div>
                
            <?php elseif ($refund_available): ?>
                <!-- ✅ Eligible pa lang — ipakita ang button -->
                <div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
                    <button onclick="requestRefund(<?php echo $appointment['id']; ?>)" class="action-btn action-btn-warning">
                        <i class="fas fa-money-bill-wave"></i> 
                        Request Refund (<?php echo $refund_percent; ?>% • ₱<?php echo number_format($eligible_refund, 2); ?>)
                    </button>
                    <small class="text-muted d-block w-100 text-end">
                        <i class="fas fa-info-circle"></i> 
                        Refund eligible: <?php echo $refund_percent; ?>% of downpayment (₱<?php echo number_format($eligible_refund, 2); ?>)
                    </small>
                </div>
                
            <?php elseif ($refund_percent == 0): ?>
                <!-- ✅ Not eligible -->
                <div class="action-buttons" style="margin-top:15px; justify-content:flex-end;">
                    <button class="action-btn action-btn-secondary" disabled style="opacity:0.5; cursor:not-allowed;">
                        <i class="fas fa-times-circle"></i> 
                        No Refund Available (0% per clinic policy)
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div><!-- closing ng main-content -->

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal" onclick="closeImageModal()">
        <span class="modal-close" onclick="closeImageModal()">&times;</span>
        <img class="modal-image" id="modalImage" src="" alt="">
    </div>

    <!-- Warranty Claim Modal -->
    <div class="modal fade" id="warrantyClaimModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #00B761, #00A86B); color: white;">
                    <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Request Warranty Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="warrantyProductInfo" class="alert alert-light border mb-3"></div>
                    <div class="alert alert-warning small">
                        <i class="fas fa-info-circle"></i>
                        <strong>Walk-in Service Only</strong><br>
                        Please bring your product to the clinic for inspection.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Preferred Date</label>
                            <input type="date" id="claim_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preferred Time</label>
                            <input type="time" id="claim_time" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Issue Description</label>
                            <textarea id="claim_description" class="form-control" rows="2" placeholder="Describe the issue..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Upload Reference Photos (Optional)</label>
                            <input type="file" id="claim_photos" class="form-control" multiple accept="image/*">
                            <small class="text-muted">Upload up to 3 photos</small>
                        </div>
                    </div>
                    <input type="hidden" id="claim_reservation_id">
                    <input type="hidden" id="claim_product_id">
                    <input type="hidden" id="claim_product_name">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" style="background: #00B761; color: white;" onclick="submitWarrantyClaim()">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════════
// GALLERY SLIDER
// ══════════════════════════════════════════════════════════════
let galleryCurrent = 0;
const galleryTotal = <?php echo $img_count; ?>;
const galleryTrack = document.getElementById('galleryTrack');
const galleryDots  = document.querySelectorAll('.gallery-dot');
const galleryCtr   = document.getElementById('galleryCounter');

function galleryGo(index) {
    if (!galleryTrack || galleryTotal <= 1) return;
    galleryCurrent = ((index % galleryTotal) + galleryTotal) % galleryTotal;
    galleryTrack.style.transform = 'translateX(' + (-galleryCurrent * 100) + '%)';
    galleryDots.forEach((d, i) => d.classList.toggle('active', i === galleryCurrent));
    if (galleryCtr) galleryCtr.innerHTML = '<i class="fas fa-images"></i> ' + (galleryCurrent + 1) + ' / ' + galleryTotal;
}

(function() {
    if (!galleryTrack) return;
    let sx = 0;
    galleryTrack.addEventListener('touchstart', e => { sx = e.touches[0].clientX; });
    galleryTrack.addEventListener('touchend',   e => {
        const dx = e.changedTouches[0].clientX - sx;
        if (Math.abs(dx) > 40) galleryGo(galleryCurrent + (dx < 0 ? 1 : -1));
    });
})();

// ============================================
// WARRANTY CLAIM FUNCTIONS
// ============================================

function openWarrantyClaim(reservationId, productId, productName) {
    document.getElementById('claim_date').value = '';
    document.getElementById('claim_time').value = '';
    document.getElementById('claim_description').value = '';
    document.getElementById('claim_photos').value = '';
    
    document.getElementById('claim_reservation_id').value = reservationId;
    document.getElementById('claim_product_id').value = productId;
    document.getElementById('claim_product_name').value = productName;
    
    document.getElementById('warrantyProductInfo').innerHTML = `
        <div class="d-flex gap-3">
            <i class="fas fa-box" style="font-size: 40px; color: #00B761;"></i>
            <div>
                <strong>${escapeHtml(productName)}</strong><br>
                <small>Reservation #: ${reservationId}</small><br>
                <small class="text-teal">Warranty claim will be verified by the clinic</small>
            </div>
        </div>
    `;
    
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('claim_date').value = tomorrow.toISOString().split('T')[0];
    
    const modal = new bootstrap.Modal(document.getElementById('warrantyClaimModal'));
    modal.show();
}

function submitWarrantyClaim() {
    const reservationId = document.getElementById('claim_reservation_id').value;
    const productId = document.getElementById('claim_product_id').value;
    const productName = document.getElementById('claim_product_name').value;
    const claimDate = document.getElementById('claim_date').value;
    const claimTime = document.getElementById('claim_time').value;
    const description = document.getElementById('claim_description').value;
    
    if (!claimDate) {
        Swal.fire('Error', 'Please select a date', 'error');
        return;
    }
    if (!claimTime) {
        Swal.fire('Error', 'Please select a time', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Submitting...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'warranty_claim');
    formData.append('reservation_id', reservationId);
    formData.append('product_id', productId);
    formData.append('product_name', productName);
    formData.append('claim_date', claimDate);
    formData.append('claim_time', claimTime);
    formData.append('description', description);
    
    const photosInput = document.getElementById('claim_photos');
    if (photosInput.files) {
        for (let i = 0; i < Math.min(photosInput.files.length, 3); i++) {
            formData.append('photos[]', photosInput.files[i]);
        }
    }
    
    fetch('/api/warranty-claim.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('warrantyClaimModal'));
            modal.hide();
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                confirmButtonColor: '#00B761'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error!', data.message, 'error');
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Image modal
function openImageModal(src, alt) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalImage').alt = alt;
    document.getElementById('imageModal').classList.add('show');
}
function closeImageModal() { document.getElementById('imageModal').classList.remove('show'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImageModal(); });

// ══════════════════════════════════════════════════════════════
// CANCEL APPOINTMENT — FIXED BUTTON LAYOUT
// ══════════════════════════════════════════════════════════════
async function cancelAppointment(id) {
    const cancellationDeadline = <?php echo (int)($appointment['cancellation_deadline'] ?? 2); ?>;
    const refundPolicy = <?php echo json_encode($appointment['refund_policy'] ?? '2:100|1:50|0:0'); ?>;
    const penaltyAmount = <?php echo (float)($appointment['penalty_amount'] ?? 500); ?>;
    const clinicName = <?php echo json_encode($appointment['clinic_name'] ?? 'Clinic'); ?>;
    const appointmentDate = <?php echo json_encode($formatted_date); ?>;
    const appointmentTime = <?php echo json_encode($formatted_time); ?>;
    const downpayment = <?php echo (float)$downpayment_amount; ?>;
    
    // Build refund rows
    const policyParts = refundPolicy.split('|');
    let policyRows = '';
    policyParts.forEach(part => {
        const [days, percent] = part.split(':');
        const refundAmt = (downpayment * percent / 100).toFixed(2);
        const pct = parseInt(percent);
        const amtColor = pct > 0 ? '#059669' : '#9CA3AF';
        policyRows += `
            <div style="display:flex; justify-content:space-between; padding:5px 0; font-size:13px;">
                <span style="color:#6B7280;">${days}+ days before</span>
                <span style="color:${amtColor}; font-weight:600;">${percent}% · ₱${refundAmt}</span>
            </div>`;
    });
    
    // ── STEP 1: Main cancellation dialog ──────────────────────
    const result = await Swal.fire({
        title: 'Cancel Appointment?',
        html: `
            <div style="text-align:left; font-family:inherit;">
                
                <!-- Policy Section -->
                <div style="margin-bottom:16px; padding:14px 16px; background:#FAFAFA; border:1px solid #EFEFEF; border-radius:8px;">
                    <div style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                        Cancellation Policy
                    </div>
                    <div style="font-size:13px; color:#6B7280; margin-bottom:10px;">
                        Deadline: <strong style="color:#111;">${cancellationDeadline} day(s)</strong> before appointment
                    </div>
                    <div style="border-top:1px solid #E5E7EB; padding-top:8px;">
                        ${policyRows}
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:8px 0 0 0; margin-top:6px; border-top:1px solid #E5E7EB; font-size:13px;">
                        <span style="color:#6B7280;">No-show penalty</span>
                        <span style="color:#DC2626; font-weight:600;">₱${penaltyAmount.toFixed(2)}</span>
                    </div>
                </div>
                
                <!-- Appointment Section -->
                <div style="margin-bottom:16px; padding:14px 16px; background:#F0F9FF; border:1px solid #E0F2FE; border-radius:8px;">
                    <div style="font-size:12px; font-weight:700; color:#0369A1; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                        Appointment
                    </div>
                    <div style="font-size:13px; color:#0C4A6E; line-height:1.6;">
                        <strong style="color:#111;">${clinicName}</strong><br>
                        ${appointmentDate} · ${appointmentTime}
                    </div>
                </div>
                
                <!-- Reschedule Hint -->
                <div style="margin-bottom:16px; padding:12px 16px; background:#F0FDF4; border:1px solid #DCFCE7; border-radius:8px; font-size:12.5px; color:#166534; line-height:1.5;">
                    <strong style="color:#15803D;">Tip:</strong> Instead of cancelling, you can reschedule to keep your slot and avoid penalties.
                </div>
                
                <!-- Reason -->
                <div style="margin-bottom:12px;">
                    <label style="font-size:13px; font-weight:600; color:#111; display:block; margin-bottom:6px;">
                        Reason for Cancellation <span style="color:#DC2626;">*</span>
                    </label>
                    <select id="cancel_reason" 
                        style="width:100%; padding:10px 12px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px; font-family:inherit; background:#fff; color:#111; outline:none;">
                        <option value="">Select a reason</option>
                        <option value="Schedule conflict">Schedule conflict</option>
                        <option value="Personal emergency">Personal emergency</option>
                        <option value="Financial reasons">Financial reasons</option>
                        <option value="Found another clinic">Found another clinic</option>
                        <option value="Feeling unwell">Feeling unwell</option>
                        <option value="Transportation issues">Transportation issues</option>
                        <option value="Changed my mind">Changed my mind</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <!-- Notes -->
                <div style="margin-bottom:8px;">
                    <label style="font-size:13px; font-weight:600; color:#111; display:block; margin-bottom:6px;">
                        Additional notes <span style="color:#9CA3AF; font-weight:400;">(optional)</span>
                    </label>
                    <textarea id="cancel_notes" 
                        placeholder="Add details..."
                        style="width:100%; padding:10px 12px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px; font-family:inherit; background:#fff; color:#111; outline:none; resize:vertical; min-height:70px; box-sizing:border-box;"
                        maxlength="500"></textarea>
                    <div style="font-size:11px; color:#9CA3AF; margin-top:4px;">Max 500 characters</div>
                </div>
                
            </div>
        `,
        width: '460px',
        padding: '24px',
        heightAuto: false,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Confirm Cancel',
        denyButtonText: 'Reschedule',
        cancelButtonText: 'Go Back',
        buttonsStyling: false,
        didOpen: () => {
            const styleBtn = (btn, type) => {
                btn.style.padding = '11px 10px';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '12.5px';
                btn.style.fontWeight = '600';
                btn.style.cursor = 'pointer';
                btn.style.margin = '0';
                btn.style.whiteSpace = 'nowrap';
                btn.style.width = '100%';
                btn.style.boxSizing = 'border-box';
                btn.style.transition = 'opacity 0.15s';
                
                if (type === 'confirm') {
                    btn.style.background = '#DC2626';
                    btn.style.color = '#fff';
                    btn.style.border = '1px solid #DC2626';
                } else if (type === 'deny') {
                    btn.style.background = '#059669';
                    btn.style.color = '#fff';
                    btn.style.border = '1px solid #059669';
                } else {
                    btn.style.background = '#fff';
                    btn.style.color = '#374151';
                    btn.style.border = '1px solid #D1D5DB';
                }
            };
            
            styleBtn(Swal.getConfirmButton(), 'confirm');
            styleBtn(Swal.getDenyButton(), 'deny');
            styleBtn(Swal.getCancelButton(), 'cancel');
            
            const actions = Swal.getActions();
            actions.style.display = 'grid';
            actions.style.gridTemplateColumns = '1fr 1fr 1fr';
            actions.style.gap = '8px';
            actions.style.width = '100%';
            actions.style.margin = '20px 0 0 0';
            actions.style.padding = '0';
        },
        preConfirm: () => {
            const reason = document.getElementById('cancel_reason').value;
            const notes = document.getElementById('cancel_notes').value.trim();
            
            if (!reason) {
                Swal.showValidationMessage('Please select a reason');
                return false;
            }
            if (reason === 'Other' && !notes) {
                Swal.showValidationMessage('Please specify your reason in the notes');
                return false;
            }
            return { reason, notes };
        }
    });
    
    // Handle Reschedule
    if (result.isDenied) {
        window.location.href = 'reschedule-appointment.php?id=' + id;
        return;
    }
    
    if (!result.isConfirmed) return;
    
    // ── STEP 2: Final confirmation ────────────────────────────
    const daysUntil = <?php echo $days_until; ?>;
    let refundPercent = 0;
    policyParts.forEach(part => {
        const [days, percent] = part.split(':');
        if (daysUntil >= parseInt(days) && refundPercent === 0) {
            refundPercent = parseInt(percent);
        }
    });
    const refundAmount = (downpayment * refundPercent / 100).toFixed(2);
    const refundColor = refundPercent > 0 ? '#059669' : '#9CA3AF';
    
    const finalConfirm = await Swal.fire({
        title: 'Confirm Cancellation',
        html: `
            <div style="text-align:left; font-size:13px; line-height:1.6;">
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #F3F4F6;">
                    <span style="color:#6B7280;">Reason</span>
                    <span style="color:#111; font-weight:600; text-align:right; max-width:60%;">${result.value.reason}</span>
                </div>
                ${result.value.notes ? `
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #F3F4F6; gap:16px;">
                    <span style="color:#6B7280; flex-shrink:0;">Notes</span>
                    <span style="color:#111; text-align:right; word-break:break-word;">${result.value.notes}</span>
                </div>` : ''}
                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #F3F4F6;">
                    <span style="color:#6B7280;">Refund</span>
                    <span style="color:${refundColor}; font-weight:600;">${refundPercent}% · ₱${refundAmount}</span>
                </div>
                <div style="padding-top:12px; font-size:12px; color:#9CA3AF;">
                    This action cannot be undone.
                </div>
            </div>
        `,
        width: '400px',
        padding: '24px',
        heightAuto: false,
        showCancelButton: true,
        confirmButtonText: 'Yes, Cancel',
        cancelButtonText: 'Go Back',
        buttonsStyling: false,
        didOpen: () => {
            const styleBtn = (btn, filled) => {
                btn.style.padding = '11px 10px';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '13px';
                btn.style.fontWeight = '600';
                btn.style.cursor = 'pointer';
                btn.style.margin = '0';
                btn.style.whiteSpace = 'nowrap';
                btn.style.width = '100%';
                btn.style.boxSizing = 'border-box';
                
                if (filled) {
                    btn.style.background = '#DC2626';
                    btn.style.color = '#fff';
                    btn.style.border = '1px solid #DC2626';
                } else {
                    btn.style.background = '#fff';
                    btn.style.color = '#374151';
                    btn.style.border = '1px solid #D1D5DB';
                }
            };
            
            styleBtn(Swal.getConfirmButton(), true);
            styleBtn(Swal.getCancelButton(), false);
            
            const actions = Swal.getActions();
            actions.style.display = 'grid';
            actions.style.gridTemplateColumns = '1fr 1fr';
            actions.style.gap = '8px';
            actions.style.width = '100%';
            actions.style.margin = '20px 0 0 0';
            actions.style.padding = '0';
        }
    });
    
    if (!finalConfirm.isConfirmed) return;
    
    // ── STEP 3: Submit ────────────────────────────────────────
    Swal.fire({ 
        title: 'Cancelling...', 
        allowOutsideClick: false, 
        showConfirmButton: false,
        heightAuto: false,
        didOpen: () => Swal.showLoading() 
    });
    
    const fd = new URLSearchParams(); 
    fd.append('cancel_appointment', '1'); 
    fd.append('appointment_id', id);
    fd.append('cancel_reason', result.value.reason);
    fd.append('cancel_notes', result.value.notes);
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
            body: fd.toString() 
        });
        const data = await res.json();
        
        if (data.success) {
            let html = '<div style="text-align:left; font-size:13px; line-height:1.6;">';
            html += '<div style="display:flex; align-items:center; gap:8px; padding:10px 0; color:#059669; font-weight:600; border-bottom:1px solid #F3F4F6;">';
            html += '<span style="font-size:16px;">✓</span> Appointment cancelled</div>';
            
            if (data.refund_percent > 0) {
                html += `<div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #F3F4F6;">
                            <span style="color:#6B7280;">Refund eligible</span>
                            <span style="color:#059669; font-weight:600;">${data.refund_percent}% · ₱${data.refund_amount.toFixed(2)}</span>
                         </div>
                         <div style="padding-top:12px; font-size:12px; color:#9CA3AF;">
                            Click "Request Refund" below to claim.
                         </div>`;
            } else {
                html += `<div style="padding:10px 0; font-size:12px; color:#9CA3AF;">
                            No refund available based on clinic policy.
                         </div>`;
            }
            html += '</div>';
            
            await Swal.fire({ 
                title: 'Cancelled',
                html: html,
                width: '400px',
                padding: '24px',
                heightAuto: false,
                confirmButtonText: 'Done',
                buttonsStyling: false,
                didOpen: () => {
                    const btn = Swal.getConfirmButton();
                    btn.style.padding = '11px 24px';
                    btn.style.borderRadius = '6px';
                    btn.style.fontSize = '13px';
                    btn.style.fontWeight = '600';
                    btn.style.background = '#059669';
                    btn.style.color = '#fff';
                    btn.style.border = '1px solid #059669';
                    btn.style.cursor = 'pointer';
                    btn.style.width = '100%';
                    btn.style.margin = '20px 0 0 0';
                    btn.style.boxSizing = 'border-box';
                }
            });
            location.reload();
        } else {
            Swal.fire({ 
                title: 'Failed',
                text: data.message, 
                width: '400px',
                padding: '24px',
                heightAuto: false,
                confirmButtonText: 'OK',
                buttonsStyling: false,
                didOpen: () => {
                    const btn = Swal.getConfirmButton();
                    btn.style.padding = '11px 24px';
                    btn.style.borderRadius = '6px';
                    btn.style.fontSize = '13px';
                    btn.style.fontWeight = '600';
                    btn.style.background = '#DC2626';
                    btn.style.color = '#fff';
                    btn.style.border = '1px solid #DC2626';
                    btn.style.cursor = 'pointer';
                    btn.style.width = '100%';
                    btn.style.margin = '20px 0 0 0';
                    btn.style.boxSizing = 'border-box';
                }
            });
        }
    } catch (error) {
        Swal.fire({ 
            title: 'Error',
            text: 'Something went wrong. Please try again.',
            width: '400px',
            padding: '24px',
            heightAuto: false,
            confirmButtonText: 'OK',
            buttonsStyling: false,
            didOpen: () => {
                const btn = Swal.getConfirmButton();
                btn.style.padding = '11px 24px';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '13px';
                btn.style.fontWeight = '600';
                btn.style.background = '#DC2626';
                btn.style.color = '#fff';
                btn.style.border = '1px solid #DC2626';
                btn.style.cursor = 'pointer';
                btn.style.width = '100%';
                btn.style.margin = '20px 0 0 0';
                btn.style.boxSizing = 'border-box';
            }
        });
    }
}
async function requestRefund(id) {
    const amt = <?php echo $eligible_refund; ?>;
    const percent = <?php echo $refund_percent; ?>;
    
    const r = await Swal.fire({
        title: 'Request Refund',
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#F59E0B',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'Yes, Request Refund',
        cancelButtonText: 'Cancel',
        html: `<div style="text-align:left;">
            <p>You are requesting a refund for this cancelled appointment.</p>
            <div style="background:#FFF3E0;padding:15px;border-radius:10px;margin:15px 0;text-align:center;">
                <i class="fas fa-money-bill-wave" style="font-size:24px;color:#F59E0B;"></i>
                <div style="font-size:20px;font-weight:bold;color:#F59E0B;">₱${amt.toFixed(2)}</div>
                <div style="font-size:12px;color:#666;">${percent}% of downpayment</div>
            </div>
            <p style="font-size:13px;color:#6B7280;">
                <i class="fas fa-info-circle"></i> 
                Refund will be processed within 5-7 business days after clinic approval.
            </p>
        </div>`
    });
    
    if (!r.isConfirmed) return;
    
    Swal.fire({ 
        title: 'Submitting refund request...', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading() 
    });
    
    const fd = new URLSearchParams(); 
    fd.append('request_refund','1'); 
    fd.append('appointment_id', id);
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
            body: fd.toString() 
        });
        const data = await res.json();
        
        if (data.success) {
            await Swal.fire({ 
                icon: 'success', 
                title: 'Refund Request Submitted!', 
                text: data.message, 
                confirmButtonColor: '#00B761' 
            });
            location.reload();
        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'Request Failed', 
                text: data.message, 
                confirmButtonColor: '#EF4444' 
            });
        }
    } catch (error) {
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: 'An error occurred. Please try again.' 
        });
    }
}

// ============================================
// OTHER ACTION HANDLERS (UNCHANGED)
// ============================================
async function deleteAppointment(id) {
    const r = await Swal.fire({ title:'Delete Appointment?', text:'This will be permanently removed.', icon:'warning', showCancelButton:true, confirmButtonColor:'#EF4444', cancelButtonColor:'#6B7280', confirmButtonText:'Yes, delete it!' });
    if (!r.isConfirmed) return;
    Swal.fire({ title:'Deleting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new URLSearchParams(); fd.append('delete_appointment','1'); fd.append('appointment_id',id);
    try {
        const res = await fetch(window.location.href, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString() });
        const data = await res.json();
        if (data.success) { await Swal.fire({ icon:'success', title:'Deleted!', text:data.message, confirmButtonColor:'#00B761' }); window.location.href='my-appointments.php'; }
        else Swal.fire({ icon:'error', title:'Failed', text:data.message, confirmButtonColor:'#EF4444' });
    } catch { Swal.fire({ icon:'error', title:'Error', text:'An error occurred.' }); }
}

// ============================================
// VIEW PENALTY - FIXED
// ============================================
async function viewPenalty(id) {
    Swal.fire({ 
        title: 'Checking...', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading() 
    });
    
    const fd = new URLSearchParams(); 
    fd.append('view_penalty','1'); 
    fd.append('appointment_id', id);
    
    try {
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, 
            body: fd.toString() 
        });
        
        const text = await res.text();
        
        try {
            const data = JSON.parse(text);
            
            if (data.success) {
                const penaltyAmount = data.penalty_amount || 500;
                const clinicName = data.clinic_name || 'Clinic';
                const appointmentDate = data.appointment_date ? new Date(data.appointment_date).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' }) : '';
                const appointmentTime = data.appointment_time ? new Date('1970-01-01T' + data.appointment_time).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }) : '';
                
                Swal.fire({ 
                    title: '⚠️ No-Show Penalty', 
                    icon: 'warning', 
                    confirmButtonColor: '#7C3AED', 
                    confirmButtonText: 'Got it',
                    html: `
                        <div style="text-align:left;">
                            <p><strong>You missed your scheduled appointment.</strong></p>
                            ${appointmentDate ? `<p style="font-size:13px;color:#6B7280;"><i class="fas fa-calendar me-1"></i> ${appointmentDate} ${appointmentTime ? 'at ' + appointmentTime : ''}</p>` : ''}
                            <div style="background:#F3E8FF;padding:15px;border-radius:10px;margin:15px 0;text-align:center;">
                                <i class="fas fa-exclamation-triangle" style="font-size:28px;color:#7C3AED;"></i>
                                <div style="font-size:24px;font-weight:bold;color:#7C3AED;margin-top:5px;">
                                    ₱${penaltyAmount.toFixed(2)}
                                </div>
                                <div style="font-size:13px;color:#6B7280;">Penalty Fee</div>
                            </div>
                            <div style="background:#FEF3C7;padding:10px;border-radius:8px;margin:10px 0;">
                                <p style="font-size:13px;color:#92400E;margin:0;">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <strong>What to do next:</strong>
                                </p>
                                <ul style="font-size:12px;color:#78350F;margin:5px 0 0 0;padding-left:20px;">
                                    <li>Contact the clinic to discuss your penalty</li>
                                    <li>Reschedule your appointment</li>
                                    <li>Ask about payment options</li>
                                </ul>
                            </div>
                            <p style="font-size:12px;color:#6B7280;text-align:center;margin-top:10px;">
                                <i class="fas fa-phone me-1"></i>
                                Contact ${clinicName} for more information.
                            </p>
                        </div>
                    `
                });
            } else {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: data.message || 'Could not retrieve penalty information.' 
                });
            }
        } catch (parseError) {
            console.error('Parse error:', parseError);
            console.error('Response:', text);
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: 'Could not retrieve penalty information. Please try again.' 
            });
        }
    } catch (error) {
        console.error('Fetch error:', error);
        Swal.fire({ 
            icon: 'error', 
            title: 'Error', 
            text: 'Could not retrieve penalty information. Please try again.' 
        });
    }
}
function shareExperience() {
    const clinicName = "<?php echo addslashes($appointment['clinic_name']); ?>";
    if (navigator.share) {
        navigator.share({ title:'My Appointment at '+clinicName, url:window.location.href });
    } else {
        navigator.clipboard.writeText(window.location.href).then(()=>{
            Swal.fire({ icon:'success', title:'Link Copied!', text:'Link copied to clipboard.', timer:2000, showConfirmButton:false });
        });
    }
}
</script>
</body>
</html>