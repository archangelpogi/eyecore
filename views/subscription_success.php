<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

$status = $_GET['status'] ?? '';
$plan_id = $_GET['plan_id'] ?? 0;
$subscription_type = $_GET['type'] ?? 'monthly';
$is_upgrade = isset($_GET['upgrade']) && $_GET['upgrade'] === '1';
$clinic_id = $_SESSION['clinic_id'] ?? 0;

if (!$clinic_id) {
    header('Location: ../admin/login.php');
    exit;
}

$subHelper = new SubscriptionHelper($pdo, $clinic_id);

if ($status === 'cancelled') {
    $message = 'You cancelled the payment. No charges were made.';
    $icon = 'info';
    $redirect = 'subscription.php';

    $pdo->prepare("
        UPDATE clinic_subscriptions 
        SET status = 'cancelled', updated_at = NOW()
        WHERE clinic_id = ? AND status = 'pending'
        ORDER BY id DESC LIMIT 1
    ")->execute([$clinic_id]);

} else {
    $stmt = $pdo->prepare("
        SELECT id, plan_id, paymongo_checkout_id 
        FROM clinic_subscriptions 
        WHERE clinic_id = ? AND status = 'pending'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$clinic_id]);
    $pending = $stmt->fetch();

    if (!$pending || !$pending['paymongo_checkout_id']) {
        $message = 'We could not find your pending subscription. Please contact support if you were charged.';
        $icon = 'error';
        $redirect = 'subscription.php';
    } else {
        $result = $subHelper->verifyAndActivateSubscription(
            $pending['paymongo_checkout_id'],
            $plan_id ?: $pending['plan_id'],
            $subscription_type,
            $is_upgrade
        );

        if ($result['success']) {
            $message = 'Your subscription is now active!';
            $icon = 'success';
            $redirect = '../main.php';
        } else {
            $message = $result['error'] ?? 'Payment verification failed. Please contact support if you were charged.';
            $icon = 'warning';
            $redirect = 'subscription.php';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription - Eyecore</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            icon: '<?php echo $icon; ?>',
            title: '<?php echo $status === 'cancelled' ? 'Payment Cancelled' : ($icon === 'success' ? 'Thank You!' : 'Heads Up'); ?>',
            text: '<?php echo addslashes($message); ?>',
            confirmButtonColor: '#0d9488'
        }).then(() => {
            window.location.href = '<?php echo $redirect; ?>';
        });
    </script>
</body>
</html>