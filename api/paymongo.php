<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$secret_key = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

$action = $_POST['action'] ?? '';

// ============================================
// CREATE CHECKOUT SESSION FOR 3D MODEL REQUEST
// ============================================
if ($action === 'create_3d_model_checkout') {

    $request_id   = (int)($_POST['request_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 100);
    $product_name = $_POST['product_name'] ?? '3D Model Request';
    $user_id      = $_SESSION['user_id'] ?? 0;
    $clinic_id    = $_SESSION['clinic_id'] ?? 0;

    if (!$request_id || $amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid request or amount']);
        exit;
    }

    // Success and cancel URLs
    $success_url = $base_url . "/views/3d_payments_success.php?request_id={$request_id}&status=success";
    $cancel_url  = $base_url . "/views/3d_payments_success.php?request_id={$request_id}&status=cancelled";


    // Fetch request details
    $request_details = null;
    try {
        $stmt = $pdo->prepare("
            SELECT request_number, product_name, model_type, colors_requested
            FROM custom_3d_requests
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$request_id, $clinic_id]);
        $request_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }

    if (!$request_details) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }

    $item_name = $request_details['product_name'] . ' (3D Model)';
    $item_description = 'Request #' . $request_details['request_number'];
    if (!empty($request_details['colors_requested'])) {
        $item_description .= ' - Colors: ' . $request_details['colors_requested'];
    }

    $line_items = [
        [
            'currency'    => 'PHP',
            'amount'      => (int)round($amount * 100),
            'name'        => $item_name,
            'description' => $item_description,
            'quantity'    => 1,
        ]
    ];

// Fetch clinic/system info para sa billing
$billing_name  = '';
$billing_email = '';
$billing_phone = '';
try {
$sys = $pdo->query("SELECT site_name, email_notifications, gcash_number FROM system_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$billing_name  = (string)($sys['site_name'] ?? '');
$billing_email = (string)($sys['email_notifications'] ?? '');
$billing_phone = (string)($sys['gcash_number'] ?? '');

$billing = [];
if (!empty($billing_name))  $billing['name']  = $billing_name;
if (!empty($billing_email)) $billing['email'] = $billing_email;
if (!empty($billing_phone)) $billing['phone'] = $billing_phone;
} catch (Exception $e) {
    // proceed without billing info
}

// Build payload
$payload = [
    'data' => [
        'attributes' => [
            'send_email_receipt'   => true,
            'show_description'     => true,
            'show_line_items'      => true,
            'description'          => '3D Model Service - ' . $request_details['request_number'],
            'line_items'           => $line_items,
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'success_url'          => $success_url,
            'cancel_url'           => $cancel_url,
'billing' => !empty($billing) ? $billing : new stdClass(),
            'metadata'             => [
                'request_id'   => $request_id,
                'request_type' => '3d_model',
                'user_id'      => $user_id,
                'clinic_id'    => $clinic_id
            ]
        ]
    ]
];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 200 || $httpCode === 201) {
        $checkout_url = $data['data']['attributes']['checkout_url'] ?? null;
        $checkout_id  = $data['data']['id'] ?? null;

        // Save checkout session ID to custom_3d_requests table
        if ($checkout_id) {
            try {
                $pdo->prepare("
                    UPDATE custom_3d_requests 
                    SET paymongo_checkout_id = ?,
                        paymongo_status = 'pending',
                        payment_link = ?
                    WHERE id = ?
                ")->execute([$checkout_id, $checkout_url, $request_id]);
            } catch (Exception $e) {
                error_log("Failed to save checkout session: " . $e->getMessage());
            }
        }

        echo json_encode([
            'success'      => true,
            'checkout_url' => $checkout_url,
            'checkout_id'  => $checkout_id,
            'amount'       => $amount
        ]);

    } else {
        $errors    = $data['errors'] ?? [];
        $error_msg = !empty($errors) ? $errors[0]['detail'] : 'PayMongo API error: ' . $httpCode;
        echo json_encode([
            'success' => false,
            'error'   => $error_msg,
            'raw'     => $data
        ]);
    }
    exit;
}

// ============================================
// VERIFY 3D MODEL CHECKOUT SESSION
// ============================================
if ($action === 'verify_3d_model_checkout') {
    $checkout_id = $_POST['checkout_id'] ?? '';
    $request_id  = (int)($_POST['request_id'] ?? 0);

    if (!$checkout_id || !$request_id) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkout_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data      = json_decode($response, true);
    
    // Get payment status from checkout session
    $pm_status = $data['data']['attributes']['payment_intent']['attributes']['status']
              ?? $data['data']['attributes']['status']
              ?? 'unknown';
    
    $payment_method_used = $data['data']['attributes']['payment_intent']['attributes']['payment_method_used']
                          ?? $data['data']['attributes']['payment_method_types'][0]
                          ?? 'unknown';

    if ($pm_status === 'paid' || $pm_status === 'succeeded') {
        try {
            $payments = $data['data']['attributes']['payments'] ?? [];
            $payment_ref = !empty($payments) ? ($payments[0]['id'] ?? $checkout_id) : $checkout_id;
            $paid_amount = !empty($payments)
                ? ($payments[0]['attributes']['amount'] ?? 0) / 100
                : 100;

            // Update request status to paid and processing
            $pdo->prepare("
                UPDATE custom_3d_requests 
                SET payment_status = 'paid',
                    payment_method = ?,
                    payment_reference = ?,
                    payment_date = NOW(),
                    paymongo_status = 'paid',
                    status = 'processing'
                WHERE id = ?
            ")->execute([
                $payment_method_used,
                $payment_ref,
                $request_id
            ]);

            echo json_encode([
                'success' => true,
                'status'  => 'paid',
                'amount'  => $paid_amount,
                'ref'     => $payment_ref
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'status'  => $pm_status
        ]);
    }
    exit;
}

// ============================================
// WEBHOOK FOR PAYMONGO (to auto-update payment status)
// ============================================
if ($action === 'webhook' || ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], 'webhook') !== false)) {
    $input = file_get_contents('php://input');
    $event = json_decode($input, true);
    
    $event_type = $event['data']['attributes']['type'] ?? '';
    $checkout_id = $event['data']['attributes']['data']['id'] ?? '';
    
    if ($event_type === 'checkout_session.payment_paid') {
        // Update payment status for the related request
        try {
            // 1. Update custom_3d_requests
            $stmt = $pdo->prepare("
                UPDATE custom_3d_requests 
                SET payment_status = 'paid',
                    payment_date = NOW(),
                    paymongo_status = 'paid',
                    status = 'processing'
                WHERE paymongo_checkout_id = ?
            ");
            $stmt->execute([$checkout_id]);
            
            // 2. Update expenses
            $stmt2 = $pdo->prepare("
                UPDATE expenses 
                SET paymongo_status = 'paid'
                WHERE paymongo_checkout_id = ?
            ");
            $stmt2->execute([$checkout_id]);
            
            // 3. Update subscription (NEW)
            $stmt3 = $pdo->prepare("
                UPDATE clinic_subscriptions 
                SET status = 'active',
                    updated_at = NOW()
                WHERE paymongo_checkout_id = ? AND status = 'pending'
            ");
            $stmt3->execute([$checkout_id]);
            
            // If subscription was updated, log it
            if ($stmt3->rowCount() > 0) {
                // Get subscription details for logging
                $stmt4 = $pdo->prepare("
                    SELECT cs.*, sp.plan_name 
                    FROM clinic_subscriptions cs
                    JOIN subscription_plans sp ON cs.plan_id = sp.id
                    WHERE cs.paymongo_checkout_id = ?
                ");
                $stmt4->execute([$checkout_id]);
                $subscription = $stmt4->fetch(PDO::FETCH_ASSOC);
                
                if ($subscription) {
                    error_log("Webhook: Subscription activated for clinic {$subscription['clinic_id']} - {$subscription['plan_name']}");
                }
            }
            
            http_response_code(200);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Event ignored']);
    }
    exit;
}

// ============================================
// CREATE CHECKOUT SESSION (redirect flow) - FOR EXPENSES (existing)
// ============================================
if ($action === 'create_checkout') {

    $expense_id  = (int)($_POST['expense_id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $method      = $_POST['payment_method'] ?? '';
    $description = $_POST['description'] ?? 'Expense Payment';
    $name        = $_POST['name'] ?? '';
    $email       = $_POST['email'] ?? '';

    if (!$expense_id || $amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid expense or amount']);
        exit;
    }

    // Map frontend method to PayMongo payment method type
    $method_map = [
        'GCash'   => 'gcash',
        'ATM'     => 'dob',
        'PayMaya' => 'paymaya',
    ];
    $paymongo_method = $method_map[$method] ?? 'gcash';

    // Fetch shipping fee from purchase_orders if this expense has pr_id
    $shipping_fee = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT po.shipping_fee 
            FROM expenses e
            JOIN purchase_orders po ON po.pr_id = e.pr_id
            WHERE e.id = ?
            AND po.shipping_fee > 0
            LIMIT 1
        ");
        $stmt->execute([$expense_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $shipping_fee = (float)$row['shipping_fee'];
        }
    } catch (Exception $e) {
        // No shipping fee, proceed normally
    }

    $total_amount = $amount + $shipping_fee;

    // Build description
    $full_description = $description;
    if ($shipping_fee > 0) {
        $full_description .= " (includes ₱" . number_format($shipping_fee, 2) . " shipping fee)";
    }

    // Success and cancel URLs
    $success_url = $base_url . "/views/payment_success.php?expense_id={$expense_id}&status=success";
    $cancel_url  = $base_url . "/views/payment_success.php?expense_id={$expense_id}&status=cancelled";

    // Fetch expense details for better display
    $expense_details = null;
    try {
        $stmt = $pdo->prepare("
            SELECT e.expense_code, e.vendor, e.category, e.department,
                   pr.pr_number, po.po_number, po.carrier
            FROM expenses e
            LEFT JOIN purchase_requests pr ON pr.id = e.pr_id
            LEFT JOIN purchase_orders po ON po.pr_id = e.pr_id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$expense_id]);
        $expense_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // proceed without details
    }

    // Build clean item name
    $item_name = $expense_details['expense_code'] ?? 'Expense Payment';
    if (!empty($expense_details['vendor'])) {
        $item_name .= ' — ' . $expense_details['vendor'];
    }

    // Build clean description
    $item_desc_parts = [];
    if (!empty($expense_details['category']))   $item_desc_parts[] = $expense_details['category'];
    if (!empty($expense_details['department'])) $item_desc_parts[] = $expense_details['department'];
    if (!empty($expense_details['pr_number']))  $item_desc_parts[] = 'PR# ' . $expense_details['pr_number'];
    if (!empty($expense_details['po_number']))  $item_desc_parts[] = 'PO# ' . $expense_details['po_number'];

    $item_description = !empty($item_desc_parts)
        ? implode(' · ', $item_desc_parts)
        : $description;

    $line_items = [
        [
            'currency'    => 'PHP',
            'amount'      => (int)round($amount * 100),
            'name'        => $item_name,
            'description' => $item_description,
            'quantity'    => 1,
        ]
    ];

    // Shipping fee line item — with carrier name if available
    if ($shipping_fee > 0) {
        $carrier = $expense_details['carrier'] ?? null;
        $line_items[] = [
            'currency'    => 'PHP',
            'amount'      => (int)round($shipping_fee * 100),
            'name'        => $carrier ? "Shipping Fee — {$carrier}" : 'Shipping Fee',
            'description' => $carrier
                ? "Delivery via {$carrier}" . (!empty($expense_details['po_number']) ? " for PO# {$expense_details['po_number']}" : '')
                : 'Delivery and handling charges',
            'quantity'    => 1,
        ];
    }

    // Build payload
    $payload = [
        'data' => [
            'attributes' => [
                'billing'              => [
                    'name'  => $name,
                    'email' => $email,
                ],
                'line_items'           => $line_items,
                'payment_method_types' => [$paymongo_method],
                'success_url'          => $success_url,
                'cancel_url'           => $cancel_url,
                'description'          => $full_description,
                'send_email_receipt'   => true,
                'show_description'     => true,
                'show_line_items'      => true,
            ]
        ]
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 200 || $httpCode === 201) {
        $checkout_url = $data['data']['attributes']['checkout_url'] ?? null;
        $checkout_id  = $data['data']['id'] ?? null;

        // Save checkout session ID to expenses table
        if ($checkout_id) {
            try {
                $pdo->prepare("
                    UPDATE expenses 
                    SET paymongo_checkout_id = ?,
                        paymongo_status = 'pending',
                        payment_link = ?,
                        payment_link_generated_at = NOW()
                    WHERE id = ?
                ")->execute([$checkout_id, $checkout_url, $expense_id]);
            } catch (Exception $e) {
                error_log("Failed to save checkout session: " . $e->getMessage());
            }
        }

        echo json_encode([
            'success'      => true,
            'checkout_url' => $checkout_url,
            'checkout_id'  => $checkout_id,
            'total_amount' => $total_amount,
            'shipping_fee' => $shipping_fee,
        ]);

    } else {
        $errors    = $data['errors'] ?? [];
        $error_msg = !empty($errors) ? $errors[0]['detail'] : 'PayMongo API error: ' . $httpCode;
        echo json_encode([
            'success' => false,
            'error'   => $error_msg,
            'raw'     => $data
        ]);
    }
    exit;
}

// ============================================
// VERIFY CHECKOUT SESSION (called by success page)
// ============================================
if ($action === 'verify_checkout') {
    $checkout_id = $_POST['checkout_id'] ?? '';
    $expense_id  = (int)($_POST['expense_id'] ?? 0);

    if (!$checkout_id || !$expense_id) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkout_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data      = json_decode($response, true);
    $pm_status = $data['data']['attributes']['payment_intent']['attributes']['status']
              ?? $data['data']['attributes']['status']
              ?? 'unknown';

    if ($pm_status === 'paid' || $pm_status === 'succeeded') {
        try {
            $payments    = $data['data']['attributes']['payments'] ?? [];
            $payment_ref = !empty($payments) ? ($payments[0]['id'] ?? $checkout_id) : $checkout_id;
            $paid_amount = !empty($payments)
                ? ($payments[0]['attributes']['amount'] ?? 0) / 100
                : 0;

            $pdo->prepare("
                UPDATE expenses 
                SET status = 'Paid',
                    payment_method = ?,
                    payment_reference = ?,
                    payment_date = NOW(),
                    paymongo_status = 'paid'
                WHERE id = ?
            ")->execute([
                strtoupper($_POST['method'] ?? 'GCASH'),
                $payment_ref,
                $expense_id
            ]);

            echo json_encode([
                'success' => true,
                'status'  => 'paid',
                'amount'  => $paid_amount,
                'ref'     => $payment_ref
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'status'  => $pm_status
        ]);
    }
    exit;
}

// ============================================
// CREATE SUBSCRIPTION CHECKOUT SESSION
// ============================================
if ($action === 'create_subscription_checkout') {
    
    require_once __DIR__ . '/../include/SubscriptionHelper.php';
    
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $subscription_type = $_POST['subscription_type'] ?? 'monthly'; // monthly, quarterly, semi_annual, annual
    $is_upgrade = isset($_POST['is_upgrade']) && $_POST['is_upgrade'] == '1';
    $clinic_id = $_SESSION['clinic_id'] ?? 0;
    
    if (!$plan_id || !$clinic_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid request: missing plan_id or clinic_id']);
        exit;
    }
    
    $subHelper = new SubscriptionHelper($pdo, $clinic_id);
    $result = $subHelper->createCheckoutSession($plan_id, $subscription_type, $is_upgrade);
    
    echo json_encode($result);
    exit;
}

// ============================================
// VERIFY SUBSCRIPTION CHECKOUT SESSION (called by success page)
// ============================================
if ($action === 'verify_subscription_checkout') {
    
    require_once __DIR__ . '/../include/SubscriptionHelper.php';
    
    $checkout_id = $_POST['checkout_id'] ?? '';
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $subscription_type = $_POST['subscription_type'] ?? 'monthly';
    $is_upgrade = isset($_POST['is_upgrade']) && $_POST['is_upgrade'] == '1';
    $clinic_id = $_SESSION['clinic_id'] ?? 0;
    
    if (!$checkout_id || !$plan_id || !$clinic_id) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    $subHelper = new SubscriptionHelper($pdo, $clinic_id);
    $result = $subHelper->verifyAndActivateSubscription($checkout_id, $plan_id, $subscription_type, $is_upgrade);
    
    echo json_encode($result);
    exit;
}

// ============================================
// ACTIVATE FREE PLAN (Basic - no payment)
// ============================================
if ($action === 'activate_free_plan') {
    
    require_once __DIR__ . '/../include/SubscriptionHelper.php';
    
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $clinic_id = $_SESSION['clinic_id'] ?? 0;
    
    if (!$plan_id || !$clinic_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $subHelper = new SubscriptionHelper($pdo, $clinic_id);
    $result = $subHelper->activateFreePlan($plan_id);
    
    echo json_encode($result);
    exit;
}

// ============================================
// CREATE TRIAL SUBSCRIPTION (30-day trial for paid plans)
// ============================================
if ($action === 'create_trial_subscription') {
    
    require_once __DIR__ . '/../include/SubscriptionHelper.php';
    
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $clinic_id = $_SESSION['clinic_id'] ?? 0;
    
    if (!$plan_id || !$clinic_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    $subHelper = new SubscriptionHelper($pdo, $clinic_id);
    $result = $subHelper->createTrialSubscription($plan_id);
    
    echo json_encode($result);
    exit;
}

// ============================================
// WEBHOOK FOR SUBSCRIPTION PAYMENTS (optional, for auto-update)
// ============================================
// Note: This extends the existing webhook functionality
// The existing webhook already handles custom_3d_requests and expenses
// We're adding subscription handling to the same webhook

echo json_encode(['success' => false, 'error' => 'Unknown action']);