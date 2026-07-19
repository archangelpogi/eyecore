<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

// Check if user is logged in
if (!isset($_SESSION['clinic_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$clinic_name = $_SESSION['clinic_name'] ?? 'My Clinic';
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$user_role = $_SESSION['role'] ?? '';
$user_email = $_SESSION['email'] ?? '';

$subHelper = new SubscriptionHelper($pdo, $clinic_id);

// If NO active subscription, redirect to subscription page
if (!$subHelper->hasActiveSubscription()) {
    header('Location: subscription.php');
    exit;
}

// Get current subscription details
$currentPlan = $subHelper->getCurrentPlan();
$currentPlanName = $subHelper->getPlanName();
$startDate = $subHelper->getStartDate();
$endDate = $subHelper->getEndDate();
$isTrial = $subHelper->isTrial();
$trialDaysLeft = $subHelper->getTrialDaysLeft();
$status = $subHelper->getStatus();
$remainingUsers = $subHelper->getRemainingUserSlots();
$maxUsers = $subHelper->getMaxUsers() ?? 5;

// Get payment history
$paymentHistory = $subHelper->getPaymentHistory(10);

// Get all plans for upgrade options
$plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC")->fetchAll();

// Handle upgrade request (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $subscription_type = $_POST['subscription_type'] ?? 'monthly';
    
    if ($action === 'upgrade_plan') {
        $result = $subHelper->createCheckoutSession($plan_id, $subscription_type, true);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'renew_subscription') {
        // Renew current plan
        $currentPlanId = $subHelper->getCurrentPlanId();
        $result = $subHelper->createCheckoutSession($currentPlanId, $subscription_type, false);
        echo json_encode($result);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// Helper function to get days remaining
function getDaysRemaining($endDate) {
    if (!$endDate) return 0;
    $end = new DateTime($endDate);
    $now = new DateTime();
    $diff = $now->diff($end);
    return (int)$diff->days;
}

$daysRemaining = getDaysRemaining($endDate);
$isExpiringSoon = $daysRemaining <= 7 && $daysRemaining > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subscription - Eyecore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(135deg, #f0fdfa 0%, #eff6ff 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .back-link {
            color: #0d9488;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            color: #0f766e;
        }
        
        .subscription-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        .subscription-header {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            padding: 30px;
            color: white;
        }
        
        .plan-badge {
            background: rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 8px 20px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .trial-badge {
            background: #fef3c7;
            color: #92400e;
            border-radius: 50px;
            padding: 4px 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .expiring-badge {
            background: #fee2e2;
            color: #dc2626;
            border-radius: 50px;
            padding: 4px 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .info-label {
            color: #64748b;
            font-weight: 500;
        }
        
        .info-value {
            font-weight: 600;
            color: #1e293b;
        }
        
        .upgrade-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
        }
        
        .upgrade-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            border-color: #0d9488;
        }
        
        .upgrade-card.popular {
            border: 2px solid #0d9488;
            position: relative;
        }
        
        .period-btn {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            background: #f1f5f9;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .period-btn.active {
            background: #0d9488;
            color: white;
        }
        
        .btn-upgrade {
            background: #0d9488;
            color: white;
            border-radius: 10px;
            padding: 10px;
            font-weight: 600;
            width: 100%;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-upgrade:hover {
            background: #0f766e;
            transform: scale(1.02);
        }
        
        .btn-renew {
            background: #f59e0b;
            color: white;
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-renew:hover {
            background: #d97706;
        }
        
        .payment-history-table {
            font-size: 0.85rem;
        }
        
        .status-active {
            background: #dcfce7;
            color: #166534;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-trial {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="../main.php" class="back-link">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
            <a href="../admin/logout.php" class="text-muted small">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
        
        <div class="row g-4">
            <!-- Left Column - Current Subscription -->
            <div class="col-lg-5">
                <div class="subscription-card">
                    <div class="subscription-header">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="plan-badge">
                                <i class="bi bi-gem me-1"></i><?php echo ucfirst($currentPlan); ?> Plan
                            </span>
                            <?php if ($isTrial): ?>
                                <span class="trial-badge">
                                    <i class="bi bi-gift me-1"></i>Trial: <?php echo $trialDaysLeft; ?> days left
                                </span>
                            <?php else: ?>
                                <span class="status-active">
                                    <i class="bi bi-check-circle me-1"></i><?php echo ucfirst($status); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h2 class="mb-2"><?php echo htmlspecialchars($clinic_name); ?></h2>
                        <p class="mb-0 opacity-75"><?php echo htmlspecialchars($currentPlanName); ?> Plan</p>
                    </div>
                    
                    <div class="p-4">
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-calendar-check me-2"></i>Start Date</span>
                            <span class="info-value"><?php echo date('F d, Y', strtotime($startDate)); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-calendar-x me-2"></i>End Date</span>
                            <span class="info-value">
                                <?php echo date('F d, Y', strtotime($endDate)); ?>
                                <?php if ($isExpiringSoon && !$isTrial): ?>
                                    <span class="expiring-badge ms-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Expiring soon!
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-hourglass-split me-2"></i>Days Remaining</span>
                            <span class="info-value">
                                <?php echo $daysRemaining; ?> days
                                <?php if ($daysRemaining <= 0): ?>
                                    <span class="expiring-badge ms-2">Expired</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-people me-2"></i>Users</span>
                            <span class="info-value">
                                <?php echo $remainingUsers; ?> / <?php echo $maxUsers; ?> slots available
                            </span>
                        </div>
                        
                        <?php if ($isTrial): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="bi bi-gift me-2"></i>
                                Your trial ends in <strong><?php echo $trialDaysLeft; ?> days</strong>. 
                                <a href="#" onclick="scrollToUpgrade()" class="alert-link">Upgrade now</a> to keep your premium features.
                            </div>
                        <?php elseif ($isExpiringSoon): ?>
                            <div class="alert alert-danger mt-3 mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Your subscription expires in <strong><?php echo $daysRemaining; ?> days</strong>.
                                <button class="btn btn-sm btn-warning ms-2" onclick="showRenewModal()">Renew Now</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Payment History -->
                <div class="subscription-card mt-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2" style="color: #0d9488;"></i>
                            Payment History
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($paymentHistory)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-receipt fs-1"></i>
                                <p class="mb-0 mt-2">No payment history yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm payment-history-table mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4">Date</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th class="pe-4">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paymentHistory as $payment): ?>
                                        <tr>
                                            <td class="ps-4"><?php echo date('M d, Y', strtotime($payment['transaction_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo ucfirst($payment['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                                            <td class="pe-4">
                                                <span class="badge bg-success"><?php echo ucfirst($payment['status']); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Upgrade Options -->
            <div class="col-lg-7">
                <div class="subscription-card">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="mb-0">
                            <i class="bi bi-arrow-up-circle me-2" style="color: #0d9488;"></i>
                            Upgrade Your Plan
                        </h5>
                        <p class="text-muted small mt-2 mb-0">Get more features by upgrading to a higher plan</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php 
                            $upgradePlans = array_filter($plans, function($plan) use ($currentPlan) {
                                $order = ['basic' => 1, 'professional' => 2, 'enterprise' => 3];
                                return $order[$plan['plan_code']] > $order[$currentPlan];
                            });
                            
                            foreach ($upgradePlans as $plan):
                                $features = json_decode($plan['features'], true);
                                $isPopular = ($plan['plan_code'] === 'professional');
                            ?>
                            <div class="col-md-6">
                                <div class="upgrade-card p-3 <?php echo $isPopular ? 'popular' : ''; ?>">
                                    <?php if ($isPopular): ?>
                                        <div class="text-end mb-2">
                                            <span class="trial-badge">Most Popular</span>
                                        </div>
                                    <?php endif; ?>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($plan['plan_name']); ?></h5>
                                    <p class="text-muted small">₱<?php echo number_format($plan['price_monthly'], 0); ?>/month</p>
                                    
                                    <div class="period-selector mb-3" data-plan-id="<?php echo $plan['id']; ?>">
                                        <span class="period-btn active" data-period="monthly" data-price="<?php echo $plan['price_monthly']; ?>">Monthly</span>
                                        <span class="period-btn" data-period="quarterly" data-price="<?php echo $plan['price_quarterly'] ?? ($plan['price_monthly'] * 3); ?>">3 Mos</span>
                                        <span class="period-btn" data-period="semi_annual" data-price="<?php echo $plan['price_semi_annual'] ?? ($plan['price_monthly'] * 6); ?>">6 Mos</span>
                                        <span class="period-btn" data-period="annual" data-price="<?php echo $plan['price_yearly'] ?? ($plan['price_monthly'] * 12); ?>">Yearly</span>
                                    </div>
                                    
                                    <ul class="feature-list list-unstyled small mb-3">
                                        <?php 
                                        $featureLabels = [
                                            'finance' => '💰 Finance & Payments',
                                            'supply_chain' => '🚚 Supply Chain',
                                            'hr' => '👥 Human Resources',
                                            'workforce' => '👷 Workforce'
                                        ];
                                        $newFeatures = array_intersect($features, ['finance', 'supply_chain', 'hr', 'workforce']);
                                        foreach ($newFeatures as $feature):
                                        ?>
                                        <li class="mb-1">
                                            <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 0.7rem;"></i>
                                            <?php echo $featureLabels[$feature] ?? ucfirst($feature); ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    
                                    <button class="btn-upgrade" onclick="upgradePlan(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['plan_name']); ?>')">
                                        <i class="bi bi-arrow-up-circle me-2"></i>Upgrade to <?php echo $plan['plan_name']; ?>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($upgradePlans)): ?>
                                <div class="col-12 text-center py-4">
                                    <i class="bi bi-trophy fs-1 text-warning"></i>
                                    <p class="mt-2 mb-0">You're already on the highest plan!</p>
                                    <small class="text-muted">Thank you for being an Enterprise customer.</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Renew Modal -->
    <div class="modal fade" id="renewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #f59e0b; color: white;">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Renew Subscription</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Renew your <strong><?php echo $currentPlanName; ?></strong> plan.</p>
                    <div class="period-selector mb-3" id="renewPeriodSelector">
                        <span class="period-btn active" data-period="monthly" data-price="<?php echo $currentPlan === 'basic' ? 0 : 2999; ?>">Monthly</span>
                        <span class="period-btn" data-period="quarterly" data-price="<?php echo $currentPlan === 'basic' ? 0 : 8497; ?>">3 Months</span>
                        <span class="period-btn" data-period="semi_annual" data-price="<?php echo $currentPlan === 'basic' ? 0 : 16195; ?>">6 Months</span>
                        <span class="period-btn" data-period="annual" data-price="<?php echo $currentPlan === 'basic' ? 0 : 29990; ?>">Yearly</span>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Renewing will <strong>add</strong> the new period to your current expiration date.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" id="confirmRenewBtn" style="background: #f59e0b; color: white;">
                        <i class="bi bi-credit-card me-2"></i>Proceed to Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let selectedPlanId = null;
        let selectedPlanName = null;
        let selectedPeriod = 'monthly';
        let selectedPrice = 0;
        
        // Initialize period selectors
        document.querySelectorAll('.period-selector').forEach(selector => {
            const btns = selector.querySelectorAll('.period-btn');
            btns.forEach(btn => {
                btn.addEventListener('click', function() {
                    btns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const planId = selector.dataset.planId;
                    if (planId) {
                        selectedPeriod = this.dataset.period;
                        selectedPrice = parseInt(this.dataset.price);
                    }
                });
            });
        });
        
        function upgradePlan(planId, planName) {
            selectedPlanId = planId;
            selectedPlanName = planName;
            
            // Get selected period from the upgrade card
            const selector = document.querySelector(`.period-selector[data-plan-id="${planId}"]`);
            if (selector) {
                const activeBtn = selector.querySelector('.period-btn.active');
                if (activeBtn) {
                    selectedPeriod = activeBtn.dataset.period;
                    selectedPrice = parseInt(activeBtn.dataset.price);
                }
            }
            
            Swal.fire({
                title: 'Upgrade Plan?',
                html: `You're about to upgrade to <strong>${planName}</strong> plan.<br><br>
                       Period: <strong>${selectedPeriod}</strong><br>
                       Price: <strong>₱${selectedPrice.toLocaleString()}</strong><br><br>
                       <span class="text-success">✓ This will ADD to your remaining days</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d9488',
                confirmButtonText: 'Yes, Upgrade',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    processUpgrade();
                }
            });
        }
        
        function processUpgrade() {
            Swal.fire({
                title: 'Processing Upgrade',
                text: 'Creating checkout session...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'upgrade_plan',
                    plan_id: selectedPlanId,
                    subscription_type: selectedPeriod
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.checkout_url) {
                    Swal.close();
                    window.location.href = data.checkout_url;
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Upgrade Failed',
                        text: data.error || 'Something went wrong',
                        confirmButtonColor: '#0d9488'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please try again',
                    confirmButtonColor: '#0d9488'
                });
            });
        }
        
        let renewPeriod = 'monthly';
        let renewPrice = 0;
        
        function showRenewModal() {
            // Reset renew period selector
            const selector = document.getElementById('renewPeriodSelector');
            if (selector) {
                const btns = selector.querySelectorAll('.period-btn');
                btns.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.period === 'monthly') {
                        btn.classList.add('active');
                        renewPeriod = 'monthly';
                        renewPrice = parseInt(btn.dataset.price);
                    }
                });
            }
            
            const modal = new bootstrap.Modal(document.getElementById('renewModal'));
            modal.show();
        }
        
        // Renew period selector
        document.querySelectorAll('#renewPeriodSelector .period-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#renewPeriodSelector .period-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                renewPeriod = this.dataset.period;
                renewPrice = parseInt(this.dataset.price);
            });
        });
        
        document.getElementById('confirmRenewBtn')?.addEventListener('click', function() {
            processRenewal();
        });
        
        function processRenewal() {
            Swal.fire({
                title: 'Processing Renewal',
                text: 'Creating checkout session...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'renew_subscription',
                    subscription_type: renewPeriod
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.checkout_url) {
                    Swal.close();
                    window.location.href = data.checkout_url;
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Renewal Failed',
                        text: data.error || 'Something went wrong',
                        confirmButtonColor: '#0d9488'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please try again',
                    confirmButtonColor: '#0d9488'
                });
            });
        }
        
        function scrollToUpgrade() {
            document.querySelector('.col-lg-7').scrollIntoView({ behavior: 'smooth' });
        }
    </script>
</body>
</html>