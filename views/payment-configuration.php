<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Finance module (Professional or Enterprise plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('finance')) {
    header('Location: ../views/subscription.php');
    exit;
}

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check
if (!RBACHelper::hasPermission('payment-configuration_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Payment Configuration.</p>
        </div>
    </div>
    <?php
    exit;
}

// Get session data
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'User';

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('payment-configuration_view');
$canCreate = RBACHelper::hasPermission('payment-configuration_create');
$canEdit = RBACHelper::hasPermission('payment-configuration_edit');
$canDelete = RBACHelper::hasPermission('payment-configuration_delete');
$canApprove = RBACHelper::hasPermission('payment-configuration_approve');
$canReject = RBACHelper::hasPermission('payment-configuration_reject');

// Get clinic payment settings
$stmt = $pdo->prepare("
    SELECT 
        payment_policy,
        downpayment_percentage,
        payment_method_online,
        payment_method_onsite,
        booking_flow,
        cancellation_deadline,
        refund_policy
    FROM clinics
    WHERE id = ?
");
$stmt->execute([$clinic_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$booking_flow = $settings['booking_flow'] ?? 'approve_first';
$cancellation_deadline = $settings['cancellation_deadline'] ?? 2;
$refund_policy = $settings['refund_policy'] ?? '2:100|1:50|0:0';

// Parse refund policy for display
$refund_tiers = [];
if (!empty($refund_policy)) {
    $parts = explode('|', $refund_policy);
    foreach ($parts as $part) {
        list($days, $percent) = explode(':', $part);
        $refund_tiers[] = ['days' => (int)$days, 'percent' => (int)$percent];
    }
}
// Sort by days (descending)
usort($refund_tiers, function($a, $b) {
    return $b['days'] - $a['days'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Configuration - EyeCore</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #0f172a;
        }
        .page-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        .card {
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            background: white;
        }
        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.25rem;
        }
        
        .card-header .card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
            color: white;
        }
        .btn-outline-teal {
            background: transparent;
            border: 1px solid var(--teal);
            color: var(--teal);
            padding: 8px 20px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .btn-outline-teal:hover {
            background: var(--teal);
            color: white;
        }
        
        .permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--teal);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 9999;
            opacity: 0.7;
        }
        
        .flow-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        .flow-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .flow-card.selected {
            border-color: var(--teal);
            background: linear-gradient(135deg, #f0fdfa, white);
        }
        
        .policy-option {
            padding: 12px;
            border-radius: 12px;
            transition: all 0.2s;
            cursor: pointer;
        }
        .policy-option:hover {
            background: #f8fafc;
        }
        .policy-option input:checked + label {
            color: var(--teal);
        }
        
        .refund-tier-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: var(--teal-soft);
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .refund-tier-row .tier-label {
            font-weight: 600;
            min-width: 120px;
        }
        .refund-tier-row .tier-value {
            font-weight: 700;
            color: var(--teal);
        }
        
        .policy-preview-box {
            background: var(--teal-soft);
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--teal);
        }
        
        .disabled-overlay {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .section-divider {
            border-top: 2px dashed #e2e8f0;
            margin: 24px 0;
        }
        
        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--teal-soft);
            color: var(--teal);
        }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">
                <i class="bi bi-credit-card me-2" style="color: var(--teal);"></i>Payment Configuration
            </h1>
            <p class="page-subtitle mt-1">Manage clinic payment policies, cancellation rules, and refund settings</p>
        </div>
        <div>
            <span class="badge bg-info">
                <i class="bi bi-shield-check me-1"></i><?php echo htmlspecialchars($user_role); ?>
            </span>
        </div>
    </div>

    <div class="row">
        <!-- ============================================================ -->
        <!-- LEFT COLUMN - Main Settings                                    -->
        <!-- ============================================================ -->
        <div class="col-lg-8">
            
            <!-- ========================================== -->
            <!-- SECTION 1: PAYMENT METHODS                 -->
            <!-- ========================================== -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-credit-card me-2" style="color: var(--teal);"></i>Payment Methods
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="payment_method_online" 
                                       <?php echo ($settings['payment_method_online'] ?? 1) ? 'checked' : ''; ?>
                                       <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="payment_method_online">
                                    <i class="bi bi-phone me-2"></i>
                                    <strong>Online Payments</strong>
                                    <br>
                                    <small class="text-muted">GCash, PayMaya, Credit/Debit Cards</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="payment_method_onsite" 
                                       <?php echo ($settings['payment_method_onsite'] ?? 1) ? 'checked' : ''; ?>
                                       <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                <label class="form-check-label" for="payment_method_onsite">
                                    <i class="bi bi-cash-stack me-2"></i>
                                    <strong>On-Site Payments</strong>
                                    <br>
                                    <small class="text-muted">Cash, Bank Transfer, Check</small>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- SECTION 2: PAYMENT TERMS                   -->
            <!-- ========================================== -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-file-text me-2" style="color: var(--teal);"></i>Payment Terms
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Booking Flow -->
                    <div class="mb-4 p-3 bg-light rounded-3">
                        <label class="form-label fw-semibold mb-3">
                            <i class="bi bi-arrow-left-right me-2"></i>Booking Flow
                        </label>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card flow-card <?php echo $booking_flow === 'approve_first' ? 'selected' : ''; ?>" 
                                     onclick="selectBookingFlow('approve_first')">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="booking_flow" 
                                                   id="flow_approve_first" value="approve_first"
                                                   <?php echo $booking_flow === 'approve_first' ? 'checked' : ''; ?>
                                                   <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                            <label class="form-check-label fw-semibold" for="flow_approve_first">
                                                <i class="bi bi-check-circle me-2 text-success"></i>
                                                Approve First, Then Pay
                                            </label>
                                        </div>
                                        <p class="text-muted small mt-2 mb-0 ps-4">
                                            ➜ Clinic approves appointment<br>
                                            ➜ Patient receives approval notice<br>
                                            ➜ Patient proceeds to payment<br>
                                            ➜ Payment completes booking
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card flow-card <?php echo $booking_flow === 'pay_first' ? 'selected' : ''; ?>" 
                                     onclick="selectBookingFlow('pay_first')">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="booking_flow" 
                                                   id="flow_pay_first" value="pay_first"
                                                   <?php echo $booking_flow === 'pay_first' ? 'checked' : ''; ?>
                                                   <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                            <label class="form-check-label fw-semibold" for="flow_pay_first">
                                                <i class="bi bi-credit-card me-2 text-primary"></i>
                                                Pay First, Then Approve
                                            </label>
                                        </div>
                                        <p class="text-muted small mt-2 mb-0 ps-4">
                                            ➜ Patient pays downpayment<br>
                                            ➜ Clinic receives payment notice<br>
                                            ➜ Clinic reviews and approves<br>
                                            ➜ Appointment is confirmed
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3 mb-0" id="flow-explanation">
                            <?php if ($booking_flow === 'approve_first'): ?>
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Current:</strong> Clinic approves appointment first, then patient pays.
                            <?php else: ?>
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Current:</strong> Patient pays first (downpayment), then clinic approves.
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Payment Policy -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-3">Default Payment Policy</label>
                        <div class="row g-3">
                            <?php
                            $policies = [
                                ['id' => 'full_payment', 'label' => '100% Full Payment', 'icon' => 'cash-coin', 'desc' => 'Upfront payment required'],
                                ['id' => 'downpayment_30', 'label' => '30% Downpayment', 'icon' => 'percent', 'desc' => '30% now, 70% on visit'],
                                ['id' => 'downpayment_custom', 'label' => 'Custom Downpayment', 'icon' => 'sliders', 'desc' => 'Configurable percentage'],
                                ['id' => 'pay_on_site', 'label' => 'Pay On-Site Only', 'icon' => 'building', 'desc' => 'No online payment'],
                                ['id' => 'no_payment', 'label' => 'Free Clinic', 'icon' => 'gift', 'desc' => 'No payment required'],
                            ];
                            ?>
                            
                            <?php foreach ($policies as $policy): ?>
                            <div class="col-md-6">
                                <div class="policy-option">
                                    <input class="form-check-input" type="radio" name="payment_policy" 
                                           id="policy_<?php echo $policy['id']; ?>"
                                           value="<?php echo $policy['id']; ?>"
                                           <?php echo ($settings['payment_policy'] ?? '') == $policy['id'] ? 'checked' : ''; ?>
                                           <?php echo !$canEdit ? 'disabled' : ''; ?>
                                           onchange="updatePolicyPreview()">
                                    <label class="form-check-label ms-2" for="policy_<?php echo $policy['id']; ?>">
                                        <i class="bi bi-<?php echo $policy['icon']; ?> me-2"></i>
                                        <strong><?php echo $policy['label']; ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo $policy['desc']; ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Custom Downpayment Percentage -->
                    <div class="mb-4" id="custom-percentage-container" style="display: none;">
                        <label class="form-label fw-semibold">Downpayment Percentage</label>
                        <div class="input-group" style="max-width: 200px;">
                            <input type="number" class="form-control" id="downpayment_percentage" 
                                   min="10" max="90" step="5" 
                                   value="<?php echo $settings['downpayment_percentage'] ?? 30; ?>"
                                   <?php echo !$canEdit ? 'disabled' : ''; ?>>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted d-block mt-2">
                            ✓ Clinic will charge <strong id="percent-display"><?php echo $settings['downpayment_percentage'] ?? 30; ?>%</strong> as downpayment
                            <br>✓ Balance (<strong id="balance-display"><?php echo 100 - ($settings['downpayment_percentage'] ?? 30); ?>%</strong>) due on clinic visit
                        </small>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- SECTION 3: CANCELLATION SETTINGS            -->
            <!-- ========================================== -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-x-circle me-2" style="color: var(--danger, #dc3545);"></i>Cancellation Settings
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-calendar-minus me-2"></i>Cancellation Deadline
                            </label>
                            <div class="input-group" style="max-width: 200px;">
                                <input type="number" class="form-control" id="cancellation_deadline" 
                                       min="0" max="30" step="1" 
                                       value="<?php echo $cancellation_deadline; ?>"
                                       <?php echo !$canEdit ? 'disabled' : ''; ?>>
                                <span class="input-group-text">days before</span>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Patients can cancel up to <strong><?php echo $cancellation_deadline; ?></strong> days before appointment.
                                <br>After that, cancellation is not allowed.
                            </small>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3 h-100 d-flex align-items-center">
                                <div>
                                    <i class="bi bi-info-circle me-2" style="color: var(--teal);"></i>
                                    <strong>How it works:</strong>
                                    <ul class="small mb-0 mt-2 ps-3">
                                        <li>Patient can cancel up to <strong><?php echo $cancellation_deadline; ?></strong> days before</li>
                                        <li>Cancellation after deadline = <span class="text-danger">not allowed</span></li>
                                        <li>No-show = <span class="text-danger">penalty applies</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- SECTION 4: REFUND POLICY                   -->
            <!-- ========================================== -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-arrow-return-left me-2" style="color: var(--warning, #f59e0b);"></i>Refund Policy
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Refund percentage based on how many days before appointment the patient cancels.
                    </p>
                    
                    <div class="row g-3">
                        <!-- Refund Tiers -->
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Refund Tiers</label>
                            
                            <?php foreach ($refund_tiers as $index => $tier): ?>
                            <div class="refund-tier-row">
                                <span class="tier-label">
                                    <?php if ($tier['days'] == 0): ?>
                                        Same day / No-show
                                    <?php elseif ($tier['days'] == 1): ?>
                                        <?php echo $tier['days']; ?> day before
                                    <?php else: ?>
                                        <?php echo $tier['days']; ?>+ days before
                                    <?php endif; ?>
                                </span>
                                <span class="tier-value">
                                    <?php echo $tier['percent']; ?>% refund
                                </span>
                            </div>
                            <?php endforeach; ?>
                            
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-pencil me-1"></i>
                                Edit tiers in the <strong>Refund Policy</strong> section below
                            </small>
                        </div>
                        
                        <!-- Policy Preview -->
                        <div class="col-md-5">
                            <div class="policy-preview-box h-100">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-shield-check" style="color: var(--teal);"></i>
                                    <strong>Current Policy Summary</strong>
                                </div>
                                <ul class="small mb-0 ps-3">
                                    <?php foreach ($refund_tiers as $tier): ?>
                                    <li>
                                        <?php if ($tier['days'] == 0): ?>
                                            <strong>Same day / No-show:</strong> <?php echo $tier['percent']; ?>%
                                        <?php elseif ($tier['days'] == 1): ?>
                                            <strong><?php echo $tier['days']; ?> day before:</strong> <?php echo $tier['percent']; ?>%
                                        <?php else: ?>
                                            <strong><?php echo $tier['days']; ?>+ days before:</strong> <?php echo $tier['percent']; ?>%
                                        <?php endif; ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Refund Policy Editor -->
                    <div class="mt-4 pt-3 border-top">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-sliders me-2"></i>Edit Refund Policy
                        </label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Days</span>
                                    <input type="number" class="form-control" id="refund_days_1" value="2" min="0" max="30">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" id="refund_percent_1" value="100" min="0" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Days</span>
                                    <input type="number" class="form-control" id="refund_days_2" value="1" min="0" max="30">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" id="refund_percent_2" value="50" min="0" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">Days</span>
                                    <input type="number" class="form-control" id="refund_days_3" value="0" min="0" max="30" readonly>
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" id="refund_percent_3" value="0" min="0" max="100">
                                </div>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Format: [Days before appointment] : [Refund percentage]
                            <br>Example: "2 days before = 100%, 1 day before = 50%, same day = 0%"
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- ACTION BUTTONS                             -->
            <!-- ========================================== -->
            <div class="d-flex gap-2 mt-3 mb-4">
                <?php if ($canEdit): ?>
                    <button type="button" class="btn btn-teal" onclick="saveAllSettings()">
                        <i class="bi bi-check-circle me-2"></i>Save All Settings
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- RIGHT COLUMN - Info & Preview                                 -->
        <!-- ============================================================ -->
        <div class="col-lg-4">
            
            <!-- Policy Preview -->
            <div class="card mb-4" id="policyPreviewCard">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">Current Policy</h5>
                </div>
                <div class="card-body">
                    <div id="policyPreviewContent">
                        <p class="text-muted">Select a policy to see preview</p>
                    </div>
                </div>
            </div>
            
            <!-- Quick Info -->
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-info-circle me-2"></i>Policy Summary
                    </h6>
                    <ul class="small mb-0 ps-3">
                        <li><strong>Booking Flow:</strong> <?php echo $booking_flow === 'approve_first' ? 'Approve then Pay' : 'Pay then Approve'; ?></li>
                        <li><strong>Payment Policy:</strong> <?php echo ucwords(str_replace('_', ' ', $settings['payment_policy'] ?? 'Not set')); ?></li>
                        <li><strong>Cancellation Deadline:</strong> <?php echo $cancellation_deadline; ?> days before</li>
                        <li><strong>Refund Tiers:</strong> <?php echo count($refund_tiers); ?> levels</li>
                        <li>Changes take effect <strong>immediately</strong></li>
                        <li>Audit logs record all changes</li>
                    </ul>
                </div>
            </div>
            
            <!-- Access Level Info -->
            <div class="alert alert-info small">
                <i class="bi bi-shield-check me-2"></i>
                <strong>Your Permissions:</strong>
                <br>
                <?php 
                $perms = [];
                if($canView) $perms[] = 'View';
                if($canCreate) $perms[] = 'Create';
                if($canEdit) $perms[] = 'Edit';
                if($canDelete) $perms[] = 'Delete';
                if($canApprove) $perms[] = 'Approve';
                if($canReject) $perms[] = 'Reject';
                echo implode(' · ', $perms);
                ?>
            </div>
        </div>
    </div>
</div>

<!-- RBAC Permission Indicator -->
<div class="permission-badge">
    <i class="bi bi-shield-check"></i> 
    <?php 
    $role_permissions = [];
    if($canView) $role_permissions[] = 'View';
    if($canCreate) $role_permissions[] = 'Create';
    if($canEdit) $role_permissions[] = 'Edit';
    if($canDelete) $role_permissions[] = 'Delete';
    if($canApprove) $role_permissions[] = 'Approve';
    if($canReject) $role_permissions[] = 'Reject';
    echo implode(' · ', $role_permissions);
    ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
// ============================================
// RBAC PERMISSIONS
// ============================================
const permissions = {
    canView: <?php echo json_encode($canView); ?>,
    canCreate: <?php echo json_encode($canCreate); ?>,
    canEdit: <?php echo json_encode($canEdit); ?>,
    canDelete: <?php echo json_encode($canDelete); ?>,
    canApprove: <?php echo json_encode($canApprove); ?>,
    canReject: <?php echo json_encode($canReject); ?>
};

const currentUserId = <?php echo json_encode($user_id); ?>;
const currentUserRole = <?php echo json_encode($user_role); ?>;

const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
});

// ============================================
// BOOKING FLOW SELECTION
// ============================================
function selectBookingFlow(flow) {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to change booking flow', 'error');
        return;
    }
    
    document.querySelectorAll('input[name="booking_flow"]').forEach(radio => {
        radio.checked = (radio.value === flow);
    });
    
    document.querySelectorAll('.flow-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    if (flow === 'approve_first') {
        document.querySelector('.col-md-6:first-child .flow-card').classList.add('selected');
        document.getElementById('flow-explanation').innerHTML = `
            <i class="bi bi-info-circle me-2"></i>
            <strong>Selected:</strong> Clinic approves appointment first, then patient pays.
            <br><small>Best for clinics that need to verify availability/schedule first.</small>
        `;
    } else {
        document.querySelector('.col-md-6:last-child .flow-card').classList.add('selected');
        document.getElementById('flow-explanation').innerHTML = `
            <i class="bi bi-info-circle me-2"></i>
            <strong>Selected:</strong> Patient pays first (downpayment), then clinic approves.
            <br><small>Best for popular clinics to secure appointments with payment.</small>
        `;
    }
}

// ============================================
// POLICY PREVIEW
// ============================================
function updatePolicyPreview() {
    if (!permissions.canView) return;
    
    const policy = document.querySelector('input[name="payment_policy"]:checked')?.value;
    const customPercent = document.getElementById('downpayment_percentage')?.value || 30;
    const deadline = document.getElementById('cancellation_deadline')?.value || 2;
    
    const preview = {
        'full_payment': {
            title: '100% Full Payment',
            icon: 'bi-cash-coin',
            text: 'Patients must pay entire amount before appointment. No balance due on visit.'
        },
        'downpayment_30': {
            title: '30% Downpayment',
            icon: 'bi-percent',
            text: 'Patients pay 30% upfront to secure appointment. 70% balance due on clinic visit.'
        },
        'downpayment_custom': {
            title: 'Custom Downpayment',
            icon: 'bi-sliders',
            text: `Patients pay ${customPercent}% upfront. Balance due on clinic visit.`
        },
        'pay_on_site': {
            title: 'Pay On-Site Only',
            icon: 'bi-building',
            text: 'No online payment. Patients pay full amount when they visit the clinic.'
        },
        'no_payment': {
            title: 'Free Service',
            icon: 'bi-gift',
            text: 'No payment required from patients. This is a free service.'
        }
    };
    
    if (preview[policy]) {
        const p = preview[policy];
        document.getElementById('policyPreviewContent').innerHTML = `
            <div class="text-center mb-3">
                <i class="bi ${p.icon} display-3 text-primary"></i>
            </div>
            <h6 class="fw-semibold text-center mb-2">${p.title}</h6>
            <p class="text-muted small mb-0">${p.text}</p>
            <hr>
            <div class="small">
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Cancellation Deadline:</span>
                    <strong>${deadline} days before</strong>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <span class="text-muted">Refund Policy:</span>
                    <strong>${getRefundSummary()}</strong>
                </div>
            </div>
        `;
    }
}

function getRefundSummary() {
    const days = [
        document.getElementById('refund_days_1')?.value || 2,
        document.getElementById('refund_days_2')?.value || 1,
        document.getElementById('refund_days_3')?.value || 0
    ];
    const percents = [
        document.getElementById('refund_percent_1')?.value || 100,
        document.getElementById('refund_percent_2')?.value || 50,
        document.getElementById('refund_percent_3')?.value || 0
    ];
    
    const parts = [];
    for (let i = 0; i < days.length; i++) {
        const label = days[i] == 0 ? 'Same day' : days[i] + '+ days';
        parts.push(`${label}: ${percents[i]}%`);
    }
    return parts.join(' | ');
}

// ============================================
// DOWNPAYMENT PERCENTAGE UPDATE
// ============================================
document.getElementById('downpayment_percentage')?.addEventListener('input', function() {
    if (!permissions.canEdit) return;
    
    const value = parseInt(this.value);
    const balance = 100 - value;
    document.getElementById('percent-display').textContent = value + '%';
    document.getElementById('balance-display').textContent = balance + '%';
    updatePolicyPreview();
});

// ============================================
// CANCELLATION DEADLINE UPDATE
// ============================================
document.getElementById('cancellation_deadline')?.addEventListener('input', function() {
    updatePolicyPreview();
});

// ============================================
// REFUND TIERS UPDATE
// ============================================
['refund_days_1', 'refund_days_2', 'refund_percent_1', 'refund_percent_2', 'refund_percent_3'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', function() {
        updatePolicyPreview();
        updateRefundTiersDisplay();
    });
});

function updateRefundTiersDisplay() {
    const days = [
        document.getElementById('refund_days_1')?.value || 2,
        document.getElementById('refund_days_2')?.value || 1,
        document.getElementById('refund_days_3')?.value || 0
    ];
    const percents = [
        document.getElementById('refund_percent_1')?.value || 100,
        document.getElementById('refund_percent_2')?.value || 50,
        document.getElementById('refund_percent_3')?.value || 0
    ];
    
    const rows = document.querySelectorAll('.refund-tier-row');
    rows.forEach((row, index) => {
        if (index < days.length) {
            const label = days[index] == 0 ? 'Same day / No-show' : days[index] + '+ days before';
            row.querySelector('.tier-label').textContent = label;
            row.querySelector('.tier-value').textContent = percents[index] + '% refund';
        }
    });
}

// ============================================
// SAVE ALL SETTINGS
// ============================================
async function saveAllSettings() {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to edit payment settings', 'error');
        return;
    }
    
    const policy = document.querySelector('input[name="payment_policy"]:checked')?.value;
    const bookingFlow = document.querySelector('input[name="booking_flow"]:checked')?.value;
    const deadline = document.getElementById('cancellation_deadline')?.value || 2;
    const customPercent = document.getElementById('downpayment_percentage')?.value || 30;
    
    // Build refund policy string
    const refundTiers = [];
    for (let i = 1; i <= 3; i++) {
        const days = document.getElementById('refund_days_' + i)?.value || 0;
        const percent = document.getElementById('refund_percent_' + i)?.value || 0;
        if (i === 3) {
            refundTiers.push('0:' + percent); // Same day is always 0 days
        } else {
            refundTiers.push(days + ':' + percent);
        }
    }
    const refundPolicy = refundTiers.join('|');
    
    if (!policy) {
        Swal.fire('Error!', 'Please select a payment policy', 'error');
        return;
    }
    
    if (!bookingFlow) {
        Swal.fire('Error!', 'Please select a booking flow', 'error');
        return;
    }
    
    const data = {
        booking_flow: bookingFlow,
        payment_policy: policy,
        downpayment_percentage: customPercent,
        payment_method_online: document.getElementById('payment_method_online')?.checked ? 1 : 0,
        payment_method_onsite: document.getElementById('payment_method_onsite')?.checked ? 1 : 0,
        cancellation_deadline: deadline,
        refund_policy: refundPolicy
    };
    
    Swal.fire({
        title: 'Saving Settings...',
        html: 'Please wait while we save your configuration.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    try {
        const response = await fetch('api/payment-settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                type: 'save_payment_settings',
                data: data 
            })
        });
        
        const result = await response.json();
        Swal.close();
        
        if (result.success) {
            Toast.fire({
                icon: 'success',
                title: 'All payment settings saved successfully!'
            });
            
            console.log('✅ Settings saved by:', currentUserRole);
            console.log('📋 Refund Policy:', refundPolicy);
            console.log('📋 Cancellation Deadline:', deadline);
            
        } else {
            Swal.fire('Error!', result.error || 'Failed to save', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error('Error:', error);
        Swal.fire('Error!', 'Failed to save settings: ' + error.message, 'error');
    }
}

// ============================================
// RESET FORM
// ============================================
function resetForm() {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to reset payment settings', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Reset form?',
        text: 'Unsaved changes will be lost',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, reset'
    }).then(result => {
        if (result.isConfirmed) {
            location.reload();
        }
    });
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    if (!permissions.canView) {
        document.querySelector('.container-fluid').innerHTML = `
            <div class="container-fluid p-5 text-center">
                <div class="alert alert-danger">
                    <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                    <h3>Access Denied</h3>
                    <p>You don't have permission to view Payment Configuration.</p>
                </div>
            </div>
        `;
        return;
    }
    
    // Initialize
    updatePolicyPreview();
    updateRefundTiersDisplay();
    
    // Highlight selected booking flow card
    const selectedFlow = document.querySelector('input[name="booking_flow"]:checked')?.value;
    if (selectedFlow === 'approve_first') {
        document.querySelector('.col-md-6:first-child .flow-card').classList.add('selected');
    } else if (selectedFlow === 'pay_first') {
        document.querySelector('.col-md-6:last-child .flow-card').classList.add('selected');
    }
    
    // Show/hide custom percentage
    const policy = document.querySelector('input[name="payment_policy"]:checked')?.value;
    if (policy === 'downpayment_custom') {
        document.getElementById('custom-percentage-container').style.display = 'block';
    }
    
    // Disable all inputs if no edit permission
    if (!permissions.canEdit) {
        const inputs = document.querySelectorAll('input, select, button[onclick="saveAllSettings()"], button[onclick="resetForm()"]');
        inputs.forEach(input => {
            if (input.tagName === 'BUTTON') {
                input.disabled = true;
                input.style.opacity = '0.5';
                input.style.cursor = 'not-allowed';
            }
        });
        
        const infoAlert = document.querySelector('.alert-info');
        if (infoAlert) {
            infoAlert.innerHTML += '<br><span class="text-warning"><i class="bi bi-lock me-1"></i>You are in view-only mode</span>';
        }
    }
});
</script>

</body>
</html>