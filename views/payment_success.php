<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
require_once __DIR__ . '/../config/db.php';

$expense_id = (int)($_GET['expense_id'] ?? 0);
$status     = $_GET['status'] ?? 'unknown';

// Fetch expense details
$expense = null;
if ($expense_id) {
    $stmt = $pdo->prepare("
        SELECT e.*, po.shipping_fee, po.carrier
        FROM expenses e
        LEFT JOIN purchase_orders po ON po.pr_id = e.pr_id
        WHERE e.id = ?
        LIMIT 1
    ");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Auto-verify if status=success
$verified    = false;
$payment_ref = '';
$actual_method = '';

if ($status === 'success' && $expense && $expense['paymongo_checkout_id']) {

    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$expense['paymongo_checkout_id']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode("sk_test_qcZwF33CQGUk9owjBgRtGFbS" . ':')
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data      = json_decode($response, true);
    $pm_status = $data['data']['attributes']['payment_intent']['attributes']['status']
              ?? $data['data']['attributes']['status']
              ?? 'unknown';

    if ($pm_status === 'paid' || $pm_status === 'succeeded') {

        $pm_payments = $data['data']['attributes']['payments'] ?? [];
        $payment_ref = !empty($pm_payments) ? ($pm_payments[0]['id'] ?? '') : '';

        // ✅ Get actual payment method from PayMongo response
        $actual_method = 'ONLINE';

        if (!empty($pm_payments)) {
            $pm_method    = $pm_payments[0]['attributes']['source']['type'] ?? '';
            $method_map   = [
                'gcash'    => 'GCASH',
                'paymaya'  => 'PAYMAYA',
                'dob'      => 'ATM/ONLINE BANKING',
                'card'     => 'CREDIT/DEBIT CARD',
                'grab_pay' => 'GRABPAY',
            ];
            $actual_method = $method_map[$pm_method] ?? strtoupper($pm_method);
        }

        // Fallback: check payment_method_types from checkout session
        if ($actual_method === 'ONLINE' || empty($actual_method)) {
            $pm_types = $data['data']['attributes']['payment_method_types'] ?? [];
            if (!empty($pm_types)) {
                $method_map    = [
                    'gcash'   => 'GCASH',
                    'paymaya' => 'PAYMAYA',
                    'dob'     => 'ATM/ONLINE BANKING',
                    'card'    => 'CREDIT/DEBIT CARD',
                ];
                $actual_method = $method_map[$pm_types[0]] ?? strtoupper($pm_types[0]);
            }
        }

        // ✅ Update expense to Paid with correct payment method
        $pdo->prepare("
            UPDATE expenses
            SET status                    = 'Paid',
                payment_method            = ?,
                payment_reference         = ?,
                payment_date              = NOW(),
                paymongo_status           = 'paid'
            WHERE id = ?
        ")->execute([
            $actual_method,
            $payment_ref,
            $expense_id
        ]);

        // ✅ Refresh expense data after update
        $stmt = $pdo->prepare("
            SELECT e.*, po.shipping_fee, po.carrier
            FROM expenses e
            LEFT JOIN purchase_orders po ON po.pr_id = e.pr_id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$expense_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);

        $verified = true;
    }
}

// ✅ Method display labels with icons
$method_icons = [
    'GCASH'              => '📱 GCash',
    'PAYMAYA'            => '💜 PayMaya',
    'ATM/ONLINE BANKING' => '💳 ATM/Online Banking',
    'CREDIT/DEBIT CARD'  => '💳 Credit/Debit Card',
    'GRABPAY'            => '🟢 GrabPay',
    'CASH'               => '💵 Cash',
    'BANK TRANSFER'      => '🏦 Bank Transfer',
    'CHECK'              => '📝 Check',
    'ONLINE'             => '🌐 Online Payment',
];

$display_method = '';
if ($expense && !empty($expense['payment_method'])) {
    $method_upper   = strtoupper($expense['payment_method']);
    $display_method = $method_icons[$method_upper] ?? $method_upper;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment <?= $status === 'success' ? 'Successful' : 'Cancelled' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        .card {
            border-radius: 20px;
            border: none;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 1rem;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .ref-code {
            font-family: monospace;
            font-size: 0.78rem;
            word-break: break-all;
            text-align: right;
            max-width: 60%;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4">
<div class="container" style="max-width: 480px;">
    <div class="card p-5 text-center">

        <?php if ($status === 'success' && $verified): ?>
            <!-- ✅ SUCCESS -->
            <div class="icon-circle bg-success bg-opacity-10 text-success mx-auto">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h3 class="fw-bold text-success mb-1">Payment Successful!</h3>
            <p class="text-muted mb-4">Your payment has been processed and recorded.</p>

            <?php if ($expense): ?>
            <div class="bg-light rounded-3 p-3 text-start mb-4">

                <div class="detail-row">
                    <span class="text-muted small">Expense Code</span>
                    <strong><?= htmlspecialchars($expense['expense_code'] ?? 'N/A') ?></strong>
                </div>

                <div class="detail-row">
                    <span class="text-muted small">Description</span>
                    <span class="text-end" style="max-width: 60%; font-size: 0.85rem;">
                        <?= htmlspecialchars($expense['description'] ?? 'N/A') ?>
                    </span>
                </div>

                <div class="detail-row">
                    <span class="text-muted small">Vendor</span>
                    <span><?= htmlspecialchars($expense['vendor'] ?? 'N/A') ?></span>
                </div>

                <div class="detail-row">
                    <span class="text-muted small">Amount Paid</span>
                    <strong class="text-success fs-5">
                        ₱<?= number_format(($expense['amount'] ?? 0) + ($expense['shipping_fee'] ?? 0), 2) ?>
                    </strong>
                </div>

                <?php if (!empty($expense['shipping_fee']) && $expense['shipping_fee'] > 0): ?>
                <div class="detail-row">
                    <span class="text-muted small">
                        Shipping Fee
                        <?= !empty($expense['carrier']) ? '(' . htmlspecialchars($expense['carrier']) . ')' : '' ?>
                    </span>
                    <span class="text-muted">₱<?= number_format($expense['shipping_fee'], 2) ?></span>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="text-muted small">Payment Method</span>
                    <span><?= htmlspecialchars($display_method ?: 'N/A') ?></span>
                </div>

                <div class="detail-row">
                    <span class="text-muted small">Payment Date</span>
                    <span><?= date('M d, Y h:i A') ?></span>
                </div>

                <?php if ($payment_ref): ?>
                <div class="detail-row">
                    <span class="text-muted small">Reference No.</span>
                    <span class="ref-code text-secondary"><?= htmlspecialchars($payment_ref) ?></span>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="text-muted small">Status</span>
                    <span class="badge bg-success px-3 py-2">✅ Paid</span>
                </div>

            </div>
            <?php endif; ?>

            <div class="alert alert-success py-2 small mb-4">
                <i class="bi bi-envelope me-1"></i>
                A payment confirmation has been sent via email.
            </div>

        <?php elseif ($status === 'cancelled'): ?>
            <!-- ❌ CANCELLED -->
            <div class="icon-circle bg-warning bg-opacity-10 text-warning mx-auto">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <h3 class="fw-bold text-warning mb-2">Payment Cancelled</h3>
            <p class="text-muted mb-4">You cancelled the payment. No charges were made.</p>

            <?php if ($expense): ?>
            <div class="bg-light rounded-3 p-3 text-start mb-4">
                <div class="detail-row">
                    <span class="text-muted small">Expense Code</span>
                    <strong><?= htmlspecialchars($expense['expense_code'] ?? 'N/A') ?></strong>
                </div>
                <div class="detail-row">
                    <span class="text-muted small">Amount</span>
                    <strong>₱<?= number_format($expense['amount'] ?? 0, 2) ?></strong>
                </div>
                <div class="detail-row">
                    <span class="text-muted small">Status</span>
                    <span class="badge bg-warning text-dark px-3 py-2">Cancelled</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="alert alert-warning py-2 small mb-4">
                <i class="bi bi-info-circle me-1"></i>
                You can retry the payment from the Expenses page.
            </div>

        <?php else: ?>
            <!-- ⏳ PENDING / UNKNOWN -->
            <div class="icon-circle bg-secondary bg-opacity-10 text-secondary mx-auto">
                <i class="bi bi-clock-fill"></i>
            </div>
            <h3 class="fw-bold mb-2">Payment Pending</h3>
            <p class="text-muted mb-4">
                We're still verifying your payment. This may take a few seconds.
            </p>
            <div class="alert alert-info py-2 small mb-4">
                <i class="bi bi-arrow-clockwise me-1"></i>
                Please refresh this page after a moment to check the status.
            </div>
        <?php endif; ?>

        <a href="../main.php?view=expenses" class="btn btn-primary rounded-pill px-4 py-2">
            <i class="bi bi-arrow-left me-2"></i>Back to Expenses
        </a>

    </div>
</div>
</body>
</html>