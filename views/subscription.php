<?php
session_name('eyecore_admin');
session_start();
include '../config/db.php';
require_once '../include/SubscriptionHelper.php';

// Check if user is logged in
if (!isset($_SESSION['clinic_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$clinic_name = $_SESSION['clinic_name'] ?? 'My Clinic';
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$user_role = $_SESSION['role'] ?? '';

// Initialize subscription helper
$subHelper = new SubscriptionHelper($pdo, $clinic_id);

// If may active subscription na, redirect to main
if ($subHelper->hasActiveSubscription()) {
    header('Location: ../main.php');
    exit;
}

// Handle AJAX requests for payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $subscription_type = $_POST['subscription_type'] ?? 'monthly';
    
    if ($action === 'create_payment') {
        // For paid plans - create PayMongo checkout
        $result = $subHelper->createCheckoutSession($plan_id, $subscription_type, false);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'activate_free') {
        // For Basic plan - free activation
        $result = $subHelper->activateFreePlan($plan_id);
        echo json_encode($result);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// Get all active plans
$plans = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Your Plan - Eyecore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(135deg, #f0fdfa 0%, #eff6ff 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .brand-logo {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            width: 80px;
            height: 80px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 10px 25px -5px rgba(13, 148, 136, 0.3);
        }
        
        .welcome-badge {
            background: white;
            border-radius: 50px;
            padding: 8px 20px;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .plan-card {
            border-radius: 24px;
            transition: all 0.3s ease;
            cursor: pointer;
            background: white;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .plan-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }
        
        .plan-card.popular {
            border: 2px solid #0d9488;
            box-shadow: 0 10px 25px -5px rgba(13, 148, 136, 0.2);
        }
        
        .popular-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #0d9488;
            color: white;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .price {
            font-size: 2.8rem;
            font-weight: 800;
            color: #0f766e;
        }
        
        .price small {
            font-size: 0.9rem;
            font-weight: normal;
            color: #64748b;
        }
        
        .feature-list {
            list-style: none;
            padding-left: 0;
            margin: 20px 0;
        }
        
        .feature-list li {
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
        }
        
        .feature-list li i {
            color: #10b981;
            margin-right: 12px;
            font-size: 1rem;
        }
        
        .btn-subscribe {
            background: #0d9488;
            color: white;
            border-radius: 12px;
            padding: 14px;
            font-weight: 600;
            width: 100%;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-subscribe:hover {
            background: #0f766e;
            transform: scale(1.02);
        }
        
        .btn-logout {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #64748b;
            border-radius: 10px;
            padding: 8px 24px;
            transition: all 0.2s;
        }
        
        .btn-logout:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        
        .period-selector {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .period-btn {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            background: #f1f5f9;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .period-btn.active {
            background: #0d9488;
            color: white;
        }
        
        .period-btn:hover:not(.active) {
            background: #e2e8f0;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-card {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .card-1 { animation-delay: 0.1s; }
        .card-2 { animation-delay: 0.2s; }
        .card-3 { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <div class="container py-5">
        <!-- Header -->
        <div class="text-center mb-5">
            <div class="brand-logo mb-4">
                <i class="bi bi-eye-fill text-white fs-1"></i>
            </div>
            
            <div class="welcome-badge mb-3">
                <i class="bi bi-person-circle me-1"></i>
                Welcome, <strong><?php echo htmlspecialchars($user_name ?: $clinic_name); ?></strong>
                <span class="badge bg-light text-dark ms-2"><?php echo htmlspecialchars($user_role); ?></span>
            </div>
            
            <h1 class="display-5 fw-bold" style="color: #0f766e;">Choose Your Plan</h1>
            <p class="lead text-muted">Select the perfect plan for your clinic.<br>Basic plan is <strong class="text-success">free forever</strong>.</p>
        </div>

        <!-- Plans -->
        <div class="row g-4 justify-content-center">
            <?php 
            $planIcons = [
                'basic' => 'bi-star',
                'professional' => 'bi-gem',
                'enterprise' => 'bi-building'
            ];
            
            foreach ($plans as $index => $plan): 
                $features = json_decode($plan['features'], true);
                $isPopular = ($plan['plan_code'] === 'professional');
                $isFree = ($plan['price_monthly'] == 0);
                $planIcon = $planIcons[$plan['plan_code']] ?? 'bi-box';
                $animationClass = 'animate-card card-' . ($index + 1);
            ?>
            <div class="col-md-4">
                <div class="plan-card h-100 <?php echo $isPopular ? 'popular' : ''; ?> <?php echo $animationClass; ?>" style="opacity: 0;">
                    <?php if ($isPopular): ?>
                        <div class="popular-badge">
                            <i class="bi bi-stars me-1"></i> Most Popular
                        </div>
                    <?php endif; ?>
                    
                    <div class="card-body p-4">
                        <!-- Plan Icon -->
                        <div class="text-center mb-3">
                            <div class="bg-light d-inline-flex p-3 rounded-circle" style="background: #f0fdfa !important;">
                                <i class="bi <?php echo $planIcon; ?> fs-1" style="color: #0d9488;"></i>
                            </div>
                        </div>
                        
                        <h3 class="card-title text-center mb-1"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                        
                        <div class="text-center mb-4">
                            <?php if ($isFree): ?>
                                <div class="price">FREE</div>
                                <small class="text-muted">forever</small>
                            <?php else: ?>
                                <div class="price" id="price-<?php echo $plan['id']; ?>">
                                    ₱<?php echo number_format($plan['price_monthly'], 0); ?>
                                </div>
                                <small class="text-muted" id="period-text-<?php echo $plan['id']; ?>">per month</small>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$isFree): ?>
                            <!-- Period Selector for paid plans -->
                            <div class="period-selector" data-plan-id="<?php echo $plan['id']; ?>">
                                <span class="period-btn active" data-period="monthly" data-price="<?php echo $plan['price_monthly']; ?>">Monthly</span>
                                <span class="period-btn" data-period="quarterly" data-price="<?php echo $plan['price_quarterly'] ?? ($plan['price_monthly'] * 3); ?>">3 Months</span>
                                <span class="period-btn" data-period="semi_annual" data-price="<?php echo $plan['price_semi_annual'] ?? ($plan['price_monthly'] * 6); ?>">6 Months</span>
                                <span class="period-btn" data-period="annual" data-price="<?php echo $plan['price_yearly'] ?? ($plan['price_monthly'] * 12); ?>">Yearly</span>
                            </div>
                        <?php endif; ?>
                        
                        <ul class="feature-list">
                            <?php 
                            $featureDisplay = [
                                'dashboard' => ['icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
                                'reports' => ['icon' => 'bi-graph-up', 'label' => 'Reports & Analytics'],
                                'activity_logs' => ['icon' => 'bi-clock-history', 'label' => 'Activity Logs'],
                                'system_settings' => ['icon' => 'bi-gear', 'label' => 'System Settings'],
                                'optical' => ['icon' => 'bi-eye', 'label' => 'Optical Module'],
                                'customer_care' => ['icon' => 'bi-chat-dots', 'label' => 'Customer Care'],
                                'finance' => ['icon' => 'bi-coin', 'label' => 'Finance & Payments'],
                                'supply_chain' => ['icon' => 'bi-truck', 'label' => 'Supply Chain'],
                                'hr' => ['icon' => 'bi-people', 'label' => 'Human Resources'],
                                'workforce' => ['icon' => 'bi-person-badge', 'label' => 'Workforce']
                            ];
                            
                            foreach ($features as $feature): 
                                $display = $featureDisplay[$feature] ?? ['icon' => 'bi-check-circle', 'label' => ucwords(str_replace('_', ' ', $feature))];
                            ?>
                            <li>
                                <i class="bi <?php echo $display['icon']; ?>"></i>
                                <?php echo htmlspecialchars($display['label']); ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <?php if ($isFree): ?>
                            <button class="btn-subscribe" onclick="activateFreePlan(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['plan_name']); ?>')">
                                <i class="bi bi-check-circle me-2"></i>Start Free Plan
                            </button>
                        <?php else: ?>
                            <button class="btn-subscribe" onclick="processPayment(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['plan_name']); ?>')">
                                <i class="bi bi-credit-card me-2"></i>Subscribe Now
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Features Comparison Table -->
        <div class="row mt-5 pt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h5 class="text-center mb-4">
                            <i class="bi bi-table me-2" style="color: #0d9488;"></i>
                            Feature Comparison
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Feature</th>
                                        <?php foreach ($plans as $plan): ?>
                                        <th class="text-center"><?php echo htmlspecialchars($plan['plan_name']); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $allFeatures = ['dashboard', 'reports', 'activity_logs', 'system_settings', 'optical', 'customer_care', 'finance', 'supply_chain', 'hr', 'workforce'];
                                    $featureNames = [
                                        'dashboard' => 'Dashboard',
                                        'reports' => 'Reports & Analytics',
                                        'activity_logs' => 'Activity Logs',
                                        'system_settings' => 'System Settings',
                                        'optical' => 'Optical Module',
                                        'customer_care' => 'Customer Care',
                                        'finance' => 'Finance & Payments',
                                        'supply_chain' => 'Supply Chain',
                                        'hr' => 'Human Resources',
                                        'workforce' => 'Workforce'
                                    ];
                                    
                                    foreach ($allFeatures as $feature):
                                    ?>
                                    <tr>
                                        <td><?php echo $featureNames[$feature]; ?></td>
                                        <?php foreach ($plans as $plan): 
                                            $planFeatures = json_decode($plan['features'], true);
                                            $hasFeature = in_array($feature, $planFeatures);
                                        ?>
                                        <td class="text-center">
                                            <?php if ($hasFeature): ?>
                                                <i class="bi bi-check-lg text-success fs-5"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash-lg text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 bg-light">
                    <div class="card-body p-4 text-center">
                        <i class="bi bi-question-circle fs-2" style="color: #0d9488;"></i>
                        <h6 class="mt-2">Frequently Asked Questions</h6>
                        <p class="small text-muted mb-0">
                            <strong>Q:</strong> What happens after I subscribe?<br>
                            <strong>A:</strong> You get immediate access to all features in your chosen plan.<br><br>
                            <strong>Q:</strong> Can I change plans later?<br>
                            <strong>A:</strong> Yes, you can upgrade or downgrade anytime from Settings.<br><br>
                            <strong>Q:</strong> Is there a contract?<br>
                            <strong>A:</strong> No, cancel anytime. No hidden fees.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-5 pt-4">
            <a href="../admin/logout.php" class="btn btn-logout btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
            <p class="text-muted small mt-3">
                <i class="bi bi-shield-check me-1"></i> Secure payments powered by PayMongo<br>
                © 2026 Eyecore. All rights reserved.
            </p>
        </div>
    </div>

    <!-- Payment Method Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: #0d9488; color: white;">
                    <h5 class="modal-title"><i class="bi bi-credit-card me-2"></i>Complete Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="paymentPlanInfo" class="mb-3 text-center">
                        <h4 id="paymentPlanName" class="mb-1"></h4>
                        <p class="text-muted" id="paymentPlanPrice"></p>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        You will be redirected to PayMongo to complete your payment via GCash, PayMaya, or Credit Card.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn" id="confirmPaymentBtn" style="background: #0d9488; color: white;">
                        <i class="bi bi-arrow-right-circle me-2"></i>Proceed to Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Make cards visible with animation
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.plan-card');
            cards.forEach(card => {
                card.style.opacity = '1';
            });
            
            // Initialize period selectors for paid plans
            initPeriodSelectors();
        });
        
        // Period selector functionality (for paid plans only)
        function initPeriodSelectors() {
            document.querySelectorAll('.period-selector').forEach(selector => {
                const btns = selector.querySelectorAll('.period-btn');
                const planId = selector.dataset.planId;
                
                btns.forEach(btn => {
                    btn.addEventListener('click', function() {
                        btns.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        
                        // Update price display
                        const price = parseInt(this.dataset.price);
                        const period = this.dataset.period;
                        const priceElement = document.getElementById(`price-${planId}`);
                        const periodTextElement = document.getElementById(`period-text-${planId}`);
                        
                        if (priceElement) {
                            priceElement.innerHTML = `₱${price.toLocaleString()}`;
                        }
                        
                        if (periodTextElement) {
                            let periodText = '';
                            switch(period) {
                                case 'monthly': periodText = 'per month'; break;
                                case 'quarterly': periodText = 'per quarter'; break;
                                case 'semi_annual': periodText = 'per 6 months'; break;
                                case 'annual': periodText = 'per year'; break;
                            }
                            periodTextElement.innerHTML = periodText;
                        }
                        
                        // Store selected period for this plan
                        window[`selectedPeriod_${planId}`] = period;
                        window[`selectedPrice_${planId}`] = price;
                    });
                });
            });
        }
        
        let currentPlanId = null;
        let currentPlanName = null;
        let currentPeriod = 'monthly';
        let currentPrice = 0;
        
        function processPayment(planId, planName) {
            currentPlanId = planId;
            currentPlanName = planName;
            currentPeriod = window[`selectedPeriod_${planId}`] || 'monthly';
            currentPrice = window[`selectedPrice_${planId}`] || 2999;
            
            document.getElementById('paymentPlanName').innerHTML = `<i class="bi bi-gem"></i> ${planName} Plan`;
            
            let periodText = '';
            switch(currentPeriod) {
                case 'monthly': periodText = 'month'; break;
                case 'quarterly': periodText = 'quarter'; break;
                case 'semi_annual': periodText = '6 months'; break;
                case 'annual': periodText = 'year'; break;
            }
            document.getElementById('paymentPlanPrice').innerHTML = `<strong>₱${currentPrice.toLocaleString()}</strong> / ${periodText}`;
            
            const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
            modal.show();
        }
        
        document.getElementById('confirmPaymentBtn')?.addEventListener('click', function() {
            createPaymentCheckout();
        });

        function createPaymentCheckout() {
    Swal.fire({
        title: 'Processing Payment',
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
            action: 'create_payment',
            plan_id: currentPlanId,
            subscription_type: currentPeriod
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.checkout_url) {
            Swal.close();
            // ✅ Simple redirect lang - no need mag-save ng kahit ano
            window.location.href = data.checkout_url;
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Payment Error',
                text: data.error || 'Failed to create checkout session',
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

        function activateFreePlan(planId, planName) {
            Swal.fire({
                title: 'Activate Free Plan?',
                html: `You're about to activate the <strong>${planName}</strong> plan.<br><br>This plan is <strong>free forever</strong> with basic features.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d9488',
                confirmButtonText: 'Yes, Activate',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Activating Plan...',
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
                            action: 'activate_free',
                            plan_id: planId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Plan Activated!',
                                text: data.message,
                                confirmButtonColor: '#0d9488',
                                timer: 2000,
                                timerProgressBar: true
                            }).then(() => {
                                window.location.href = '../main.php';
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.error,
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
            });
        }
    </script>
</body>
</html>