<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ ITO ANG KULANG!

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

// ✅ RBAC Permission Check - MUST HAVE PAYMENT-CONFIGURATION VIEW PERMISSION
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
        booking_flow
    FROM clinics
    WHERE id = ?
");
$stmt->execute([$clinic_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$booking_flow = $settings['booking_flow'] ?? 'approve_first';
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
        }
        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.25rem;
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
            <p class="page-subtitle mt-1">Manage clinic payment policies and methods</p>
        </div>
        <div>
            <span class="badge bg-info">
                <i class="bi bi-shield-check me-1"></i><?php echo htmlspecialchars($user_role); ?>
            </span>
        </div>
    </div>

    <div class="row">
        <!-- Payment Policy Section -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0 fw-semibold">
                        <i class="bi bi-gear me-2" style="color: var(--teal);"></i>Payment Policy
                    </h5>
                </div>
                <div class="card-body">
                    
                    <form id="paymentForm">
                        <!-- ========================================= -->
                        <!-- BOOKING FLOW SELECTION                    -->
                        <!-- ========================================= -->
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
                            
                            <!-- Flow Explanation -->
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
                        
                        <!-- Policy Selection -->
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
                        
                        <!-- Payment Methods -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold mb-3">Payment Methods Accepted</label>
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
                        
                        <!-- Action Buttons -->
                        <div class="d-flex gap-2">
                            <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-teal" onclick="savePaymentSettings()">
                                    <i class="bi bi-check-circle me-2"></i>Save Payment Settings
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="resetPaymentForm()">
                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Info & Preview -->
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
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-info-circle me-2"></i>Important Notes
                    </h6>
                    <ul class="small mb-0 ps-3">
                        <li><strong>Booking Flow:</strong> <?php echo $booking_flow === 'approve_first' ? 'Approve then Pay' : 'Pay then Approve'; ?></li>
                        <li>Payment policy applies to <strong>new appointments</strong></li>
                        <li>Changes take effect <strong>immediately</strong></li>
                        <li>Patients see policy when booking</li>
                        <li>Finance can track payments by policy</li>
                        <li>Audit logs record all changes</li>
                    </ul>
                </div>
            </div>
            
            <!-- Access Level Info -->
            <div class="alert alert-info mt-3 small">
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
// RBAC PERMISSIONS - Passed from PHP to JavaScript
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

console.log('RBAC Permissions:', permissions);

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
    
    // Update card styling
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
        `;
    }
    
    // Show/hide custom percentage field
    const customContainer = document.getElementById('custom-percentage-container');
    if (policy === 'downpayment_custom') {
        customContainer.style.display = 'block';
    } else {
        customContainer.style.display = 'none';
    }
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

async function savePaymentSettings() {
    if (!permissions.canEdit) {
        Swal.fire('Access Denied', 'You don\'t have permission to edit payment settings', 'error');
        return;
    }
    
    const policy = document.querySelector('input[name="payment_policy"]:checked')?.value;
    const bookingFlow = document.querySelector('input[name="booking_flow"]:checked')?.value;
    
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
        downpayment_percentage: document.getElementById('downpayment_percentage')?.value || 30,
        payment_method_online: document.getElementById('payment_method_online')?.checked ? 1 : 0,
        payment_method_onsite: document.getElementById('payment_method_onsite')?.checked ? 1 : 0
    };
    
    try {
        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        // ✅ FIXED: Changed 'action' to 'type' to match API expectation
        const response = await fetch('api/payment-settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                type: 'save_payment_settings',  // ← Changed from 'action' to 'type'
                data: data 
            })
        });
        
        const result = await response.json();
        Swal.close();
        
        if (result.success) {
            Toast.fire({
                icon: 'success',
                title: 'Payment settings saved successfully!'
            });
            
            // Log audit
            console.log('Payment settings updated by:', currentUserRole);
        } else {
            Swal.fire('Error!', result.error || 'Failed to save', 'error');
        }
    } catch (error) {
        Swal.close();
        console.error('Error:', error);
        Swal.fire('Error!', 'Failed to save settings', 'error');
    }
}
// ============================================
// RESET FORM
// ============================================
function resetPaymentForm() {
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
    // Check if user has view permission
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
    
    // Highlight selected booking flow card
    const selectedFlow = document.querySelector('input[name="booking_flow"]:checked')?.value;
    if (selectedFlow === 'approve_first') {
        document.querySelector('.col-md-6:first-child .flow-card').classList.add('selected');
    } else if (selectedFlow === 'pay_first') {
        document.querySelector('.col-md-6:last-child .flow-card').classList.add('selected');
    }
    
    // Disable all inputs if no edit permission
    if (!permissions.canEdit) {
        const inputs = document.querySelectorAll('input, select, button[onclick="savePaymentSettings()"], button[onclick="resetPaymentForm()"]');
        inputs.forEach(input => {
            if (input.tagName === 'BUTTON') {
                input.disabled = true;
                input.style.opacity = '0.5';
                input.style.cursor = 'not-allowed';
            }
        });
        
        // Add disabled overlay message
        const infoAlert = document.querySelector('.alert-info');
        if (infoAlert) {
            infoAlert.innerHTML += '<br><span class="text-warning"><i class="bi bi-lock me-1"></i>You are in view-only mode</span>';
        }
    }
});
</script>

</body>
</html>