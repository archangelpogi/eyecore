<?php
/**
 * include/subscription_guard.php
 * 
 * Centralized subscription guard — compatible sa dalawang scenarios:
 *   1. Direct access sa views/*.php (fresh request → header redirect works)
 *   2. Loaded via main.php (may output na → JS redirect fallback)
 * 
 * Base sa actual menu_items DB (Nov 2026 dump):
 *   - Basic: 15 views (active sub lang, walang module restriction)
 *   - Finance: 3 views (Professional+)
 *   - Supply Chain: 5 views (Professional+)
 *   - HR: 10 views (Enterprise)
 *   - Workforce: 3 views (Enterprise)
 */

// ─────────────────────────────────────────────────────────
// REDIRECT HELPER — DAPAT NAKA-DEFINE BAGO GAMITIN
// ─────────────────────────────────────────────────────────
if (!function_exists('__subscription_guard_redirect')) {
    function __subscription_guard_redirect(string $url): void {
        // Case 1: Fresh request (direct access) — header() works
        if (!headers_sent() && !defined('MAIN_PHP_LOADED')) {
            header('Location: ' . $url);
            exit();
        }
        
        // Case 2: Loaded via main.php OR headers already sent
        // → Use JavaScript redirect
        
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Redirecting...</title>';
        echo '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a;}';
        echo '.box{text-align:center;padding:40px;background:white;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.08);}';
        echo '.spinner{width:40px;height:40px;border:4px solid #e2e8f0;border-top-color:#0d9488;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 20px;}';
        echo '@keyframes spin{to{transform:rotate(360deg);}}';
        echo 'a{color:#0d9488;text-decoration:none;font-weight:600;}</style>';
        echo '</head><body>';
        echo '<div class="box">';
        echo '<div class="spinner"></div>';
        echo '<p>Redirecting...</p>';
        echo '<p><a href="' . htmlspecialchars($url) . '">Click here if not redirected</a></p>';
        echo '</div>';
        echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
        echo '</body></html>';
        exit();
    }
}

// ─────────────────────────────────────────────────────────
// Guard: baka ma-include twice
// ─────────────────────────────────────────────────────────
if (defined('SUBSCRIPTION_GUARD_LOADED')) {
    return;
}
define('SUBSCRIPTION_GUARD_LOADED', true);

// ─────────────────────────────────────────────────────────
// Guard: session check
// ─────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

// ─────────────────────────────────────────────────────────
// Guard: authenticated user check
// ─────────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['clinic_id'])) {
    __subscription_guard_redirect('../admin/login.php');
}

// ─────────────────────────────────────────────────────────
// Guard: SuperAdmin bypass
// ─────────────────────────────────────────────────────────
if (($_SESSION['role'] ?? '') === 'SuperAdmin') {
    return;
}

// ─────────────────────────────────────────────────────────
// WHITELIST: Escape hatch
// ─────────────────────────────────────────────────────────
$__currentView = basename($_SERVER['PHP_SELF'], '.php');
$__whitelist = [
    'subscription', 'upgrade', 'subscription_success', 'subscription_manage',
    'clinic_pending', 'clinic_suspended', 'clinic_rejected',
];

if (in_array($__currentView, $__whitelist)) {
    return;
}

// ─────────────────────────────────────────────────────────
// Load SubscriptionHelper
// ─────────────────────────────────────────────────────────
require_once __DIR__ . '/SubscriptionHelper.php';

$__subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);

// ─────────────────────────────────────────────────────────
// CHECK 1: May active subscription ba?
// ✅ FIXED: Redirect to subscription_manage.php (pareho sa main.php)
// ─────────────────────────────────────────────────────────
if (!$__subHelper->hasActiveSubscription()) {
    __subscription_guard_redirect('../views/subscription_manage.php?expired=1');
}

// ─────────────────────────────────────────────────────────
// CHECK 2: Grace status — blocked na ba (8+ days expired)?
// ✅ FIXED: Redirect to subscription_manage.php
// ─────────────────────────────────────────────────────────
$__expiryInfo = $__subHelper->getExpiryInfo();
if ($__expiryInfo['grace_status'] === 'blocked') {
    __subscription_guard_redirect('../views/subscription_manage.php?blocked=1');
}

// ─────────────────────────────────────────────────────────
// CHECK 3: Module-specific access
// ✅ FIXED: Redirect to subscription_manage.php
// ─────────────────────────────────────────────────────────
$__moduleMap = [
    // Finance (Professional+)
    'sales' => 'finance', 'payment-configuration' => 'finance', 'expenses' => 'finance',
    // Supply Chain (Professional+)
    'inventory' => 'supply_chain', 'purchase_requests' => 'supply_chain',
    'purchase_orders' => 'supply_chain', 'products' => 'supply_chain',
    'supplier' => 'supply_chain', 'my-3d-models' => 'supply_chain',
    // HR (Enterprise)
    'users' => 'hr', 'positions' => 'hr', 'roles_management' => 'hr',
    'attendance' => 'hr', 'schedule_management' => 'hr', 'leave' => 'hr',
    'payroll' => 'hr', 'salary-history' => 'hr', 'payroll-approval' => 'hr', 'payslip' => 'hr',
    // Workforce (Enterprise)
    'my_schedule' => 'workforce', 'qr_attendance' => 'workforce', 'employee_leave' => 'workforce',
];

if (isset($__moduleMap[$__currentView])) {
    $__requiredModule = $__moduleMap[$__currentView];
    if (!$__subHelper->canAccessModule($__requiredModule)) {
        __subscription_guard_redirect('../views/subscription_manage.php?upgrade=1&module=' . urlencode($__requiredModule));
    }
}

// ✅ Lahat ng check passed
unset($__subHelper, $__expiryInfo, $__currentView, $__moduleMap, $__requiredModule, $__whitelist);