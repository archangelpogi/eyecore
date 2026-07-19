<?php
session_name('eyecore_admin');
session_start();

require_once __DIR__ . '/../config/db.php';

$request_id = $_GET['request_id'] ?? 0;
$status     = $_GET['status'] ?? '';

// Get request details first
$req_stmt = $pdo->prepare("SELECT id, status, payment_status, paymongo_checkout_id FROM custom_3d_requests WHERE id = ?");
$req_stmt->execute([$request_id]);
$request = $req_stmt->fetch(PDO::FETCH_ASSOC);

if ($status === 'success' && $request_id && $request) {
    $checkout_id = $request['paymongo_checkout_id'] ?? null;
    
    if ($checkout_id) {
        $secret_key = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";
        $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkout_id}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($secret_key . ':')
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $pm_status = $data['data']['attributes']['payment_intent']['attributes']['status']
                  ?? $data['data']['attributes']['status']
                  ?? 'unknown';

        if ($pm_status === 'paid' || $pm_status === 'succeeded') {
            // Update to paid and processing
            $pdo->prepare("
                UPDATE custom_3d_requests 
                SET payment_status = 'paid',
                    paymongo_status = 'paid',
                    payment_date = NOW(),
                    status = 'processing'
                WHERE id = ?
            ")->execute([$request_id]);
            
            // Update variable for display
            $request['payment_status'] = 'paid';
            $request['status'] = 'processing';
        } else {
            // Payment not completed - keep as pending_payment
            // Do nothing, keep original status
        }
    }
} elseif ($status === 'cancelled' && $request_id && $request) {
    // ✅ FIX: When user cancels payment, update status to 'cancelled'
    // Only update if status is still 'pending_payment'
    if ($request['status'] === 'pending_payment' || $request['payment_status'] === 'pending_payment') {
        $pdo->prepare("
            UPDATE custom_3d_requests 
            SET status = 'cancelled',
                payment_status = 'unpaid',
                cancellation_reason = 'User cancelled payment',
                cancelled_at = NOW()
            WHERE id = ? AND (status = 'pending_payment' OR payment_status = 'pending_payment')
        ")->execute([$request_id]);
        
        // Update variable for display
        $request['status'] = 'cancelled';
        $request['payment_status'] = 'unpaid';
    }
}

// Get fresh request data
$final_stmt = $pdo->prepare("SELECT status, payment_status FROM custom_3d_requests WHERE id = ?");
$final_stmt->execute([$request_id]);
$final_request = $final_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>3D Model Payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --teal: #008080; }
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .card-soft { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; }
        .btn-teal { background-color: var(--teal); color: white; border: none; }
        .btn-teal:hover { background-color: #005f5f; color: white; }
        .btn-outline-teal { border: 1px solid var(--teal); color: var(--teal); background: transparent; }
        .btn-outline-teal:hover { background-color: var(--teal); color: white; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card-soft p-5 text-center">
                    <?php if ($status === 'success' && isset($final_request) && $final_request['payment_status'] === 'paid'): ?>
                        <div class="mb-4" style="font-size:4rem">✅</div>
                        <h3 class="fw-bold text-success mb-3">Payment Successful!</h3>
                        <p class="text-muted mb-4">Your 3D model request has been submitted and is now processing.</p>
                        <p class="small text-muted mb-4">You will receive a notification once your 3D model is ready.</p>
                        <a href="/main.php?view=my-3d-models&tab=pending" class="btn btn-teal px-4">View My Requests</a>
                        
                    <?php elseif ($status === 'cancelled'): ?>
                        <div class="mb-4" style="font-size:4rem">❌</div>
                        <h3 class="fw-bold text-danger mb-3">Payment Cancelled</h3>
                        <p class="text-muted mb-4">You have cancelled the payment process. Your request has been cancelled.</p>
                        <p class="small text-muted mb-4">You can create a new request anytime.</p>
                        <div class="d-flex gap-3 justify-content-center">
                            <a href="/eyecore/main.php?view=my-3d-models" class="btn btn-outline-secondary px-4">Go Back</a>
                            <a href="/eyecore/main.php?view=my-3d-models" class="btn btn-teal px-4" onclick="openRequestModal()">Create New Request</a>
                        </div>
                        
                    <?php elseif ($status === 'success' && isset($final_request) && $final_request['payment_status'] !== 'paid'): ?>
                        <div class="mb-4" style="font-size:4rem">⏳</div>
                        <h3 class="fw-bold text-warning mb-3">Payment Pending</h3>
                        <p class="text-muted mb-4">Your payment is still being processed. Please check back later.</p>
                        <a href="/main.php?view=my-3d-models&tab=pending" class="btn btn-teal px-4">Check Status</a>
                        
                    <?php else: ?>
                        <div class="mb-4" style="font-size:4rem">🤔</div>
                        <h3 class="fw-bold mb-3">Something went wrong</h3>
                        <p class="text-muted mb-4">Please check your request status in the dashboard.</p>
                        <a href="/eyecore/main.php?view=my-3d-models" class="btn btn-teal px-4">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>