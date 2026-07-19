<?php
class SubscriptionHelper {
    private $pdo;
    private $clinic_id;
    private $subscription = null;
    private $cached_modules = null;
    
    // PayMongo Configuration
    private $paymongo_secret_key;
    private $base_url;
    
    public function __construct($pdo, $clinic_id) {
        $this->pdo = $pdo;
        $this->clinic_id = $clinic_id;
        $this->loadSubscription();
        
        // Initialize PayMongo settings
        $this->paymongo_secret_key = "sk_test_qcZwF33CQGUk9owjBgRtGFbS"; // Same as your existing
        $this->base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
    }
    
    /**
     * Load active subscription from database
     */
    private function loadSubscription() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT cs.*, sp.plan_code, sp.plan_name, sp.features, sp.price_monthly, 
                       sp.max_users, sp.max_storage_mb, sp.has_trial, sp.trial_days
                FROM clinic_subscriptions cs
                JOIN subscription_plans sp ON cs.plan_id = sp.id
                WHERE cs.clinic_id = ? 
                AND cs.status IN ('active', 'trial')
                AND cs.end_date >= CURDATE()
                ORDER BY cs.id DESC 
                LIMIT 1
            ");
            $stmt->execute([$this->clinic_id]);
            $this->subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Cache modules if may subscription
            if ($this->subscription) {
                $this->cached_modules = json_decode($this->subscription['features'], true);
            }
        } catch (PDOException $e) {
            error_log("SubscriptionHelper load error: " . $e->getMessage());
            $this->subscription = null;
            $this->cached_modules = null;
        }
    }
    
    /**
     * Check if clinic has active subscription
     */
    public function hasActiveSubscription() {
        return !empty($this->subscription);
    }
    
    /**
     * Get current plan code (basic, professional, enterprise)
     */
    public function getCurrentPlan() {
        return $this->subscription['plan_code'] ?? null;
    }
    
    /**
     * Get current plan name
     */
    public function getPlanName() {
        return $this->subscription['plan_name'] ?? 'No Active Plan';
    }
    
    /**
     * Get current plan ID
     */
    public function getCurrentPlanId() {
        return $this->subscription['plan_id'] ?? null;
    }
    
    /**
     * Get subscription end date
     */
    public function getEndDate() {
        return $this->subscription['end_date'] ?? null;
    }
    
    /**
     * Get subscription start date
     */
    public function getStartDate() {
        return $this->subscription['start_date'] ?? null;
    }
    
    /**
     * Get remaining trial days
     */
    public function getTrialDaysLeft() {
        if (!$this->subscription || $this->subscription['status'] !== 'trial') {
            return 0;
        }
        $end_date = new DateTime($this->subscription['end_date']);
        $now = new DateTime();
        $diff = $now->diff($end_date);
        return (int)$diff->days;
    }
    
    /**
     * Check if subscription is in trial period
     */
    public function isTrial() {
        return $this->subscription && $this->subscription['status'] === 'trial';
    }
    
    /**
     * Check if subscription is expired
     */
    public function isExpired() {
        if (!$this->subscription) return true;
        $end_date = new DateTime($this->subscription['end_date']);
        $now = new DateTime();
        return $end_date < $now;
    }
    
    /**
     * Get subscription status
     */
    public function getStatus() {
        return $this->subscription['status'] ?? 'none';
    }
    
    /**
     * Check if clinic can access a specific module
     */
    public function canAccessModule($module_name) {
        // SuperAdmin always has access
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'SuperAdmin') {
            return true;
        }
        
        // No subscription = no access to ANY module
        if (!$this->hasActiveSubscription()) {
            return false;
        }
        
        // Check if module is in features array
        return in_array($module_name, $this->cached_modules);
    }
    
    /**
     * Get all accessible modules for current clinic
     */
    public function getAccessibleModules() {
        if (!$this->hasActiveSubscription()) {
            return [];
        }
        return $this->cached_modules;
    }
    
    /**
     * Check if clinic has reached max user limit
     */
    public function hasReachedUserLimit() {
        if (!$this->hasActiveSubscription()) {
            return true;
        }
        
        $max_users = $this->subscription['max_users'] ?? 5;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM users 
                WHERE clinic_id = ? AND status = 'Active'
            ");
            $stmt->execute([$this->clinic_id]);
            $current_users = (int)$stmt->fetchColumn();
            
            return $current_users >= $max_users;
        } catch (PDOException $e) {
            error_log("User limit check error: " . $e->getMessage());
            return true;
        }
    }
    
/**
 * Get remaining user slots
 */
public function getRemainingUserSlots() {
    if (!$this->hasActiveSubscription()) {
        return 0;
    }
    
    $max_users = $this->subscription['max_users'] ?? 5;
    
    try {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM users 
            WHERE clinic_id = ? AND status = 'Active'
        ");
        $stmt->execute([$this->clinic_id]);
        $current_users = (int)$stmt->fetchColumn();
        
        return max(0, $max_users - $current_users);
    } catch (PDOException $e) {
        error_log("User limit check error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get maximum users allowed for current plan
 */
public function getMaxUsers() {
    if (!$this->hasActiveSubscription()) {
        return 5; // default for no subscription
    }
    return $this->subscription['max_users'] ?? 5;
}
    
    /**
     * Redirect if no access to module
     */
    public function redirectIfNoAccess($module_name, $redirectUrl = null) {
        if (!$this->canAccessModule($module_name)) {
            $redirect = $redirectUrl ?? '//views/subscription.php';
            header("Location: $redirect");
            exit;
        }
    }
    
    // ============================================
    // NEW METHODS FOR SUBSCRIPTION MANAGEMENT
    // ============================================
    
    /**
     * Create PayMongo checkout session for subscription
     */
    public function createCheckoutSession($plan_id, $subscription_type = 'monthly', $is_upgrade = false) {
        try {
            // Get plan details
            $stmt = $this->pdo->prepare("
                SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return ['success' => false, 'error' => 'Invalid plan selected'];
            }
            
            // Determine price based on subscription type
            $price = $plan['price_monthly'];
            $duration_days = 30; // 1 month
            $duration_label = 'month';
            
            switch ($subscription_type) {
                case 'quarterly':
                    $price = $plan['price_quarterly'] ?? ($plan['price_monthly'] * 3);
                    $duration_days = 90;
                    $duration_label = 'quarter (3 months)';
                    break;
                case 'semi_annual':
                    $price = $plan['price_semi_annual'] ?? ($plan['price_monthly'] * 6);
                    $duration_days = 180;
                    $duration_label = 'semi-annual (6 months)';
                    break;
                case 'annual':
                    $price = $plan['price_yearly'] ?? ($plan['price_monthly'] * 12);
                    $duration_days = 365;
                    $duration_label = 'annual (12 months)';
                    break;
                default:
                    $subscription_type = 'monthly';
                    $duration_days = 30;
                    $duration_label = 'month';
            }
            
            // For Basic plan (free), no payment needed
            if ($plan['plan_code'] === 'basic' || $price <= 0) {
                return $this->activateFreePlan($plan_id);
            }
            
            // Get clinic details for billing
            $clinic_name = $_SESSION['clinic_name'] ?? 'Clinic';
            $user_email = $_SESSION['email'] ?? '';
            $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            
            // Success and cancel URLs
            $success_url = $this->base_url . "/views/subscription_success.php?plan_id={$plan_id}&type={$subscription_type}";
            if ($is_upgrade) {
                $success_url .= "&upgrade=1";
            }
            $cancel_url = $this->base_url . "/views/subscription.php?cancelled=1";
            
            // Build line items
            $line_items = [
                [
                    'currency' => 'PHP',
                    'amount' => (int)round($price * 100),
                    'name' => $plan['plan_name'] . ' Plan',
                    'description' => $duration_label . ' subscription to ' . $plan['plan_name'] . ' plan. ' . $plan['description'],
                    'quantity' => 1,
                ]
            ];
            
            // Add trial if applicable
            $has_trial = $plan['has_trial'] && !$is_upgrade;
            
            // Build payload
            $payload = [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => true,
                        'show_description' => true,
                        'show_line_items' => true,
                        'description' => $plan['plan_name'] . ' Plan Subscription - ' . $duration_label,
                        'line_items' => $line_items,
                        'payment_method_types' => ['gcash', 'paymaya', 'card'],
                        'success_url' => $success_url,
                        'cancel_url' => $cancel_url,
                        'billing' => [
                            'name' => $clinic_name,
                            'email' => $user_email,
                        ],
                        'metadata' => [
                            'clinic_id' => $this->clinic_id,
                            'plan_id' => $plan_id,
                            'plan_code' => $plan['plan_code'],
                            'subscription_type' => $subscription_type,
                            'duration_days' => $duration_days,
                            'is_upgrade' => $is_upgrade ? '1' : '0'
                        ]
                    ]
                ]
            ];
            
            // Call PayMongo API
            $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($this->paymongo_secret_key . ':')
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $checkout_url = $data['data']['attributes']['checkout_url'] ?? null;
                $checkout_id = $data['data']['id'] ?? null;
                
                // Create pending subscription record
                $start_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime("+{$duration_days} days"));
                
                // If upgrade, we'll handle differently in verify method
                $stmt = $this->pdo->prepare("
                    INSERT INTO clinic_subscriptions 
                    (clinic_id, plan_id, subscription_type, start_date, end_date, status, 
                     paymongo_checkout_id, payment_link, payment_link_generated_at, amount_paid, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), ?, NOW())
                ");
                $stmt->execute([
                    $this->clinic_id,
                    $plan_id,
                    $subscription_type,
                    $start_date,
                    $end_date,
                    $checkout_id,
                    $checkout_url,
                    $price
                ]);
                
                $subscription_id = $this->pdo->lastInsertId();
                
                // Log the transaction
                $this->logSubscriptionAction('checkout_created', $subscription_id, null, $plan_id, $price, $checkout_id);
                
                return [
                    'success' => true,
                    'checkout_url' => $checkout_url,
                    'checkout_id' => $checkout_id,
                    'subscription_id' => $subscription_id,
                    'amount' => $price
                ];
                
            } else {
                $errors = $data['errors'] ?? [];
                $error_msg = !empty($errors) ? $errors[0]['detail'] : 'PayMongo API error: ' . $httpCode;
                return [
                    'success' => false,
                    'error' => $error_msg
                ];
            }
            
        } catch (Exception $e) {
            error_log("Create checkout session error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Activate free plan (Basic) - no payment needed
     */
    public function activateFreePlan($plan_id) {
        try {
            // Check if may existing subscription
            if ($this->hasActiveSubscription()) {
                return ['success' => false, 'error' => 'You already have an active subscription'];
            }
            
            // Get plan details
            $stmt = $this->pdo->prepare("SELECT * FROM subscription_plans WHERE id = ?");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return ['success' => false, 'error' => 'Invalid plan'];
            }
            
            // Basic plan: 10 years validity (libre forever)
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d', strtotime('+10 years'));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO clinic_subscriptions 
                (clinic_id, plan_id, subscription_type, start_date, end_date, status, created_at)
                VALUES (?, ?, 'monthly', ?, ?, 'active', NOW())
            ");
            $stmt->execute([$this->clinic_id, $plan_id, $start_date, $end_date]);
            
            $subscription_id = $this->pdo->lastInsertId();
            
            // Log the transaction
            $this->logSubscriptionAction('activated_free', $subscription_id, null, $plan_id, 0, null);
            
            // Reload subscription
            $this->loadSubscription();
            
            return [
                'success' => true,
                'message' => 'Basic plan activated successfully!',
                'subscription_id' => $subscription_id
            ];
            
        } catch (Exception $e) {
            error_log("Activate free plan error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Verify payment and activate subscription
     */
    public function verifyAndActivateSubscription($checkout_id, $plan_id, $subscription_type = 'monthly', $is_upgrade = false) {
        try {
            // Call PayMongo to verify payment
            $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkout_id}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($this->paymongo_secret_key . ':')
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            $payment_status = $data['data']['attributes']['payment_intent']['attributes']['status']
                            ?? $data['data']['attributes']['status']
                            ?? 'unknown';
            
            if ($payment_status !== 'paid' && $payment_status !== 'succeeded') {
                return [
                    'success' => false,
                    'error' => 'Payment not completed. Status: ' . $payment_status,
                    'status' => $payment_status
                ];
            }
            
            // Get payment details
            $payments = $data['data']['attributes']['payments'] ?? [];
            $payment_ref = !empty($payments) ? ($payments[0]['id'] ?? $checkout_id) : $checkout_id;
            $payment_method = !empty($payments) ? ($payments[0]['attributes']['source']['type'] ?? 'unknown') : 'unknown';
            $amount_paid = !empty($payments) ? ($payments[0]['attributes']['amount'] ?? 0) / 100 : 0;
            
            // Get metadata
            $metadata = $data['data']['attributes']['metadata'] ?? [];
            $duration_days = (int)($metadata['duration_days'] ?? 30);
            
            $this->pdo->beginTransaction();
            
            // Find the pending subscription
            $stmt = $this->pdo->prepare("
                SELECT * FROM clinic_subscriptions 
                WHERE paymongo_checkout_id = ? AND clinic_id = ? AND status = 'pending'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$checkout_id, $this->clinic_id]);
            $pending_sub = $stmt->fetch();
            
            if (!$pending_sub) {
                throw new Exception('Pending subscription not found');
            }
            
            $old_plan_id = null;
            $old_end_date = null;
            
            // If upgrading from existing subscription
            if ($is_upgrade && $this->hasActiveSubscription()) {
                $old_plan_id = $this->subscription['plan_id'];
                $old_end_date = $this->subscription['end_date'];
                
                // ADD to remaining days (not override)
                $current_end = new DateTime($this->subscription['end_date']);
                $new_end = clone $current_end;
                $new_end->modify("+{$duration_days} days");
                $new_end_date = $new_end->format('Y-m-d');
                
                // Update existing subscription to cancelled/expired
                $stmt = $this->pdo->prepare("
                    UPDATE clinic_subscriptions 
                    SET status = 'expired', updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmt->execute([$this->subscription['id'], $this->clinic_id]);
                
                // Update the pending subscription with new end date
                $stmt = $this->pdo->prepare("
                    UPDATE clinic_subscriptions 
                    SET end_date = ?, 
                        amount_paid = ?,
                        payment_method = ?,
                        payment_reference = ?,
                        status = 'active',
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmt->execute([$new_end_date, $amount_paid, $payment_method, $payment_ref, $pending_sub['id'], $this->clinic_id]);
                
                // Log upgrade
                $this->logSubscriptionAction('upgraded', $pending_sub['id'], $old_plan_id, $plan_id, $amount_paid, $payment_ref);
                
            } else {
                // New subscription - use the pending subscription as is
                $stmt = $this->pdo->prepare("
                    UPDATE clinic_subscriptions 
                    SET status = 'active',
                        payment_method = ?,
                        payment_reference = ?,
                        amount_paid = ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmt->execute([$payment_method, $payment_ref, $amount_paid, $pending_sub['id'], $this->clinic_id]);
                
                // Log creation
                $this->logSubscriptionAction('activated_paid', $pending_sub['id'], null, $plan_id, $amount_paid, $payment_ref);
            }
            
            // Record payment transaction
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_transactions 
                (clinic_id, subscription_id, transaction_type, amount, payment_method, payment_reference, paymongo_checkout_id, status, transaction_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $this->clinic_id,
                $pending_sub['id'],
                $is_upgrade ? 'upgrade' : 'subscription',
                $amount_paid,
                $payment_method,
                $payment_ref,
                $checkout_id
            ]);
            
            $this->pdo->commit();
            
            // Reload subscription
            $this->loadSubscription();
            
            return [
                'success' => true,
                'message' => $is_upgrade ? 'Subscription upgraded successfully!' : 'Subscription activated successfully!',
                'plan_name' => $this->subscription['plan_name'],
                'end_date' => $this->subscription['end_date']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Verify subscription error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Log subscription actions for audit trail
     */
    private function logSubscriptionAction($action, $subscription_id, $old_plan_id, $new_plan_id, $amount, $payment_ref) {
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_id = $_SESSION['user_id'] ?? 0;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO subscription_logs 
                (clinic_id, subscription_id, action, old_plan_id, new_plan_id, amount_paid, payment_reference, performed_by, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->clinic_id,
                $subscription_id,
                $action,
                $old_plan_id,
                $new_plan_id,
                $amount,
                $payment_ref,
                $user_id,
                $ip_address
            ]);
        } catch (Exception $e) {
            error_log("Failed to log subscription action: " . $e->getMessage());
        }
    }
    
    /**
     * Get subscription summary (for display)
     */
    public function getSubscriptionSummary() {
        if (!$this->hasActiveSubscription()) {
            return [
                'has_subscription' => false,
                'message' => 'No active subscription',
                'action_url' => '//views/subscription.php',
                'action_text' => 'Subscribe Now'
            ];
        }
        
        $summary = [
            'has_subscription' => true,
            'plan_name' => $this->subscription['plan_name'],
            'plan_code' => $this->subscription['plan_code'],
            'status' => $this->subscription['status'],
            'start_date' => $this->subscription['start_date'],
            'end_date' => $this->subscription['end_date'],
            'days_left' => $this->getTrialDaysLeft(),
            'is_trial' => $this->isTrial(),
            'max_users' => $this->subscription['max_users'],
            'remaining_users' => $this->getRemainingUserSlots()
        ];
        
        if ($this->isTrial()) {
            $summary['message'] = "Trial ends in " . $this->getTrialDaysLeft() . " days";
            $summary['action_url'] = '//views/subscription_manage.php';
            $summary['action_text'] = 'Upgrade Now';
        } else {
            $summary['message'] = "Active " . $this->subscription['plan_name'] . " Plan";
            $summary['action_url'] = '//views/subscription_manage.php';
            $summary['action_text'] = 'Manage';
        }
        
        return $summary;
    }
    
    /**
     * Get payment history
     */
    public function getPaymentHistory($limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM payment_transactions 
                WHERE clinic_id = ? 
                ORDER BY transaction_date DESC 
                LIMIT ?
            ");
            $stmt->execute([$this->clinic_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get payment history error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create trial subscription (for new clinics)
     */
    public function createTrialSubscription($plan_id) {
        try {
            // Check if may existing active subscription
            if ($this->hasActiveSubscription()) {
                return [
                    'success' => false, 
                    'error' => 'You already have an active subscription',
                    'code' => 'ALREADY_SUBSCRIBED'
                ];
            }
            
            // Get plan details
            $stmt = $this->pdo->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return [
                    'success' => false, 
                    'error' => 'Invalid plan selected',
                    'code' => 'INVALID_PLAN'
                ];
            }
            
            // If Basic plan, use free activation
            if ($plan['plan_code'] === 'basic' || $plan['price_monthly'] == 0) {
                return $this->activateFreePlan($plan_id);
            }
            
            // For paid plans with trial, create trial subscription
            $start_date = date('Y-m-d');
            $trial_days = $plan['trial_days'] ?? 30;
            $end_date = date('Y-m-d', strtotime("+{$trial_days} days"));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO clinic_subscriptions 
                (clinic_id, plan_id, subscription_type, start_date, end_date, status, created_at)
                VALUES (?, ?, 'trial', ?, ?, 'trial', NOW())
            ");
            $stmt->execute([$this->clinic_id, $plan_id, $start_date, $end_date]);
            
            $subscription_id = $this->pdo->lastInsertId();
            
            // Log trial creation
            $this->logSubscriptionAction('trial_started', $subscription_id, null, $plan_id, 0, null);
            
            // Reload subscription
            $this->loadSubscription();
            
            return [
                'success' => true, 
                'message' => $trial_days . '-day trial started successfully!',
                'plan_name' => $plan['plan_name'],
                'end_date' => $end_date,
                'trial_days' => $trial_days
            ];
            
        } catch (PDOException $e) {
            error_log("Create trial error: " . $e->getMessage());
            return [
                'success' => false, 
                'error' => 'Database error: ' . $e->getMessage(),
                'code' => 'DB_ERROR'
            ];
        }
    }
}
?>