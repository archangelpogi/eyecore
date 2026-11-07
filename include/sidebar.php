<?php
// session_name MUST match login.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/RBACHelper.php';
require_once __DIR__ . '/SubscriptionHelper.php';

// Initialize RBAC
RBACHelper::init($pdo);

$clinic_name = $_SESSION['clinic_name'] ?? 'Clinic Name';
$clinic_logo = trim($_SESSION['clinic_logo'] ?? '');
$clinic_id   = $_SESSION['clinic_id'] ?? null;

// Initialize Subscription Helper
$subHelper         = null;
$subscription_plan = 'none';
$hasSubscription   = false;
$grace_status      = $_SESSION['grace_status'] ?? 'ok';
$days_expired      = $_SESSION['days_expired'] ?? 0;

if ($clinic_id) {
    $subHelper         = new SubscriptionHelper($pdo, $clinic_id);
    $hasSubscription   = $subHelper->hasActiveSubscription();
    $subscription_plan = $subHelper->getCurrentPlan();

    // Refresh expiry info
    $expiryInfo   = $subHelper->getExpiryInfo();
    $grace_status = $expiryInfo['grace_status'];
    $days_expired = $expiryInfo['days_expired'];

    // Sync session
    $_SESSION['grace_status'] = $grace_status;
    $_SESSION['days_expired'] = $days_expired;
}

$logo_file_path = !empty($clinic_logo) ? $_SERVER['DOCUMENT_ROOT'] . '/eyecore/uploads/' . $clinic_logo : null;
$logo_url_path  = !empty($clinic_logo) ? 'uploads/' . $clinic_logo : null;

$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$initials  = '';
foreach (explode(' ', $user_name) as $i => $part) {
    if ($i >= 2) break;
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials  = !empty($initials) ? $initials : 'U';
$user_role = $_SESSION['role'] ?? '';
$user_id   = $_SESSION['user_id'] ?? 0;

$v = $_GET['view'] ?? '';
$current_page = basename($_SERVER['PHP_SELF']);

// ============================================
// MAP VIEW TO MODULE - PARA SA ACTIVE DETECTION
// ============================================
$view_to_module = [
    // ADMINISTRATION
    'dashboard' => 'ADMINISTRATION',
    'reports' => 'ADMINISTRATION',
    'logs' => 'ADMINISTRATION',
    'settings' => 'ADMINISTRATION',
    'roles_management' => 'ADMINISTRATION',
    
    // OPTICAL
    'doctor_dashboard' => 'OPTICAL',
    'decision-support' => 'OPTICAL',
    
    // CUSTOMER CARE
    'crm_dashboard' => 'CUSTOMER CARE',
    'patients' => 'CUSTOMER CARE',
    'appointments' => 'CUSTOMER CARE',
    'pwd_senior_verification' => 'CUSTOMER CARE',
    'reservations' => 'CUSTOMER CARE',
    'crm_messages' => 'CUSTOMER CARE',
    'crm_tasks' => 'CUSTOMER CARE',
    'request_data_deletion' => 'CUSTOMER CARE',
    'clinic_reviews' => 'CUSTOMER CARE',
    
    // FINANCE & PAYMENTS
    'sales' => 'FINANCE & PAYMENTS',
    'payment-configuration' => 'FINANCE & PAYMENTS',
    'expenses' => 'FINANCE & PAYMENTS',
    
    // SUPPLY CHAIN
    'inventory' => 'SUPPLY CHAIN',
    'purchase_requests' => 'SUPPLY CHAIN',
    'purchase_orders' => 'SUPPLY CHAIN',
    'products' => 'SUPPLY CHAIN',
    'supplier' => 'SUPPLY CHAIN',
    'my-3d-models' => 'SUPPLY CHAIN',
    
    // HUMAN RESOURCES
    'users' => 'HUMAN RESOURCES',
    'positions' => 'HUMAN RESOURCES',
    'attendance' => 'HUMAN RESOURCES',
    'schedule_management' => 'HUMAN RESOURCES',
    'leave' => 'HUMAN RESOURCES',
    'payroll' => 'HUMAN RESOURCES',
    'salary-history' => 'HUMAN RESOURCES',
    'payslip' => 'HUMAN RESOURCES',
    'payroll-approval' => 'HUMAN RESOURCES',
    
    // WORKFORCE
    'my_schedule' => 'WORKFORCE',
    'qr_attendance' => 'WORKFORCE',
    'employee_leave' => 'WORKFORCE',
];

// Determine current module
$current_module = '';
if (!empty($v)) {
    $current_module = $view_to_module[$v] ?? '';
}

if (empty($current_module)) {
    $page_to_module = [
        'appointment-scheduling.php' => 'CUSTOMER CARE',
        'appointments.php' => 'CUSTOMER CARE',
        'sales.php' => 'FINANCE & PAYMENTS',
        'dashboard.php' => 'ADMINISTRATION',
    ];
    $current_module = $page_to_module[$current_page] ?? '';
}

// Read permissions directly from session
$userPermissions = $_SESSION['permissions'] ?? [];

// Load user roles safely
$userRoles = [];
if ($user_id > 0 && $clinic_id > 0) {
    try {
        $userRoles = RBACHelper::getUserRoles((int)$user_id, (int)$clinic_id);
    } catch (Exception $e) {
        error_log("Error loading user roles in sidebar: " . $e->getMessage());
        $userRoles = [];
    }
}

// Check if ClinicAdmin via RBAC roles
$isClinicAdminViaRBAC = false;
foreach ($userRoles as $role) {
    if (isset($role['role_name']) && $role['role_name'] === 'ClinicAdmin') {
        $isClinicAdminViaRBAC = true;
        break;
    }
}

$is_clinic_admin = ($user_role === 'ClinicAdmin') || $isClinicAdminViaRBAC;

// If not ClinicAdmin and permissions empty, load them now (backup)
if (!$is_clinic_admin && empty($userPermissions) && $user_id > 0 && $clinic_id > 0) {
    RBACHelper::loadPermissionsToSession((int)$user_id, (int)$clinic_id);
    $userPermissions = $_SESSION['permissions'] ?? [];
}

function canAccessPage($requiredPermission, $userPermissions, $is_clinic_admin) {
    if ($is_clinic_admin)           return true;
    if (empty($requiredPermission)) return true;
    return in_array($requiredPermission, $userPermissions);
}

// ============================================================
// SUBSCRIPTION-BASED LOCKING RULES
// ============================================================
$ENTERPRISE_ONLY_GROUPS    = ['HUMAN RESOURCES', 'WORKFORCE'];
$PROFESSIONAL_ONLY_GROUPS  = ['FINANCE & PAYMENTS', 'SUPPLY CHAIN'];

function isGroupLockedByPlan($groupName, $subscription_plan, $ENTERPRISE_ONLY_GROUPS, $PROFESSIONAL_ONLY_GROUPS) {
    if (in_array($groupName, $ENTERPRISE_ONLY_GROUPS)) {
        return $subscription_plan !== 'enterprise';
    }
    if (in_array($groupName, $PROFESSIONAL_ONLY_GROUPS)) {
        return in_array($subscription_plan, ['basic', 'none']);
    }
    return false;
}

function getRequiredPlanForGroup($groupName, $ENTERPRISE_ONLY_GROUPS, $PROFESSIONAL_ONLY_GROUPS) {
    if (in_array($groupName, $ENTERPRISE_ONLY_GROUPS))   return 'enterprise';
    if (in_array($groupName, $PROFESSIONAL_ONLY_GROUPS)) return 'professional';
    return null;
}

// ============================================================
// BUILD MENU GROUPS
// ============================================================
$menuGroups = [];

try {
    $stmt = $pdo->prepare("
        SELECT * FROM menu_items
        WHERE is_active = 1
        ORDER BY group_name, sort_order
    ");
    $stmt->execute();
    $allMenuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // clinic_menu_visibility
    $hidden_modules = [];
    if ($is_clinic_admin && $clinic_id) {
        try {
            $stmt2 = $pdo->prepare("
                SELECT group_name, enabled
                FROM clinic_menu_visibility
                WHERE clinic_id = ?
            ");
            $stmt2->execute([$clinic_id]);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int)$row['enabled'] === 0) {
                    $hidden_modules[] = $row['group_name'];
                }
            }
        } catch (PDOException $e) {
            error_log("Error loading menu visibility: " . $e->getMessage());
        }
    }

    foreach ($allMenuItems as $item) {
        $group              = $item['group_name'];
        $requiredPermission = $item['permission_required'];

        if ($is_clinic_admin && in_array($group, $hidden_modules)) continue;

        if (!$is_clinic_admin) {
            if (!canAccessPage($requiredPermission, $userPermissions, $is_clinic_admin)) continue;
        }

        if (!isset($menuGroups[$group])) {
            $isLocked       = isGroupLockedByPlan($group, $subscription_plan, $ENTERPRISE_ONLY_GROUPS, $PROFESSIONAL_ONLY_GROUPS);
            $requiredPlan   = getRequiredPlanForGroup($group, $ENTERPRISE_ONLY_GROUPS, $PROFESSIONAL_ONLY_GROUPS);

            $menuGroups[$group] = [
                'icon'          => $item['group_icon'] ?? 'bi-folder',
                'items'         => [],
                'locked'        => $isLocked,
                'required_plan' => $requiredPlan,
            ];
        }
        $menuGroups[$group]['items'][] = $item;
    }
} catch (PDOException $e) {
    error_log("Error loading menu items: " . $e->getMessage());
}

// ============================================================
// CUSTOM ORDER FOR MENU GROUPS
// ============================================================
$customOrder = [
    'ADMINISTRATION'    => 1,
    'OPTICAL'           => 2,
    'CUSTOMER CARE'     => 3,
    'FINANCE & PAYMENTS'=> 4,
    'SUPPLY CHAIN'      => 5,
    'HUMAN RESOURCES'   => 6,
    'WORKFORCE'         => 7,
];

uksort($menuGroups, function($a, $b) use ($customOrder) {
    $orderA = $customOrder[$a] ?? 999;
    $orderB = $customOrder[$b] ?? 999;
    return $orderA <=> $orderB;
});

// For JS
$js_user_key    = 'eyecore_onboarded_' . ($clinic_id ?? 0) . '_' . ($user_id ?? 0);
$js_plan        = json_encode($subscription_plan);
?>

<aside id="sidebar" class="sidebar">

    <?php if ($grace_status === 'warning'): ?>
    <!-- RED BANNER: 0-3 days expired -->
    <div class="subscription-alert alert-danger-grace">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill alert-icon"></i>
            <div class="flex-fill">
                <div class="alert-title">Subscription Expired</div>
<div class="alert-text">
    Expired <?= (int)$days_expired ?> day(s) ago.
    <?= $is_clinic_admin ? 'Please renew now.' : 'Ask your admin to renew.' ?>
</div>
                <?php if ($is_clinic_admin): ?>
                    <a href="views/subscription.php" class="alert-btn alert-btn-danger">
                        <i class="bi bi-credit-card me-1"></i> Renew Now
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($grace_status === 'grace'): ?>
    <!-- YELLOW BANNER: 4-7 days expired -->
    <div class="subscription-alert alert-warning-grace">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-clock-history alert-icon"></i>
            <div class="flex-fill">
                <div class="alert-title">Final Warning</div>
<div class="alert-text">
    Expired <?= (int)$days_expired ?> day(s) ago.
    <?php if ($is_clinic_admin): ?>
        You still have full access to renew.
    <?php else: ?>
        <strong>Account will be blocked soon.</strong> Ask your admin to renew.
    <?php endif; ?>
</div>
                <?php if ($is_clinic_admin): ?>
                    <a href="views/subscription.php" class="alert-btn alert-btn-warning">
                        <i class="bi bi-credit-card me-1"></i> Renew Now
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sidebar-header">
        <div class="clinic-brand-wrapper">
            <div class="d-flex align-items-center gap-3">
                <div class="clinic-logo-container">
                    <?php if (!empty($clinic_logo) && file_exists($logo_file_path)): ?>
                        <img src="<?php echo htmlspecialchars($logo_url_path); ?>"
                             alt="<?php echo htmlspecialchars($clinic_name); ?>"
                             class="clinic-logo-img">
                    <?php else: ?>
                        <div class="clinic-logo-default">
                            <i class="bi bi-eye-fill"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="clinic-text-container">
                    <h5 class="clinic-name mb-0"><?php echo htmlspecialchars($clinic_name); ?></h5>
                    <small class="eyecore-system">Eyecore System</small>
                </div>
            </div>
        </div>
        <button class="btn btn-link d-lg-none sidebar-toggle" id="toggleSidebarMobile">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav flex-column">

            <?php if (!$hasSubscription): ?>
                <li class="nav-item mt-3">
                    <div class="upgrade-sidebar-banner text-center p-3">
                        <i class="bi bi-star-fill fs-1 text-warning"></i>
                        <h6 class="mt-2">Unlock Full Access</h6>
                        <p class="small text-muted">Subscribe to access all features</p>
                        <a href="../views/subscription.php" class="btn btn-sm btn-teal w-100">
                            <i class="bi bi-gem me-1"></i> View Plans
                        </a>
                    </div>
                </li>

            <?php else: ?>
                <?php foreach ($menuGroups as $groupName => $group): ?>
                    <?php if (empty($group['items'])) continue; ?>
                    <?php
                        $is_locked     = $group['locked'];
                        $required_plan = $group['required_plan'] ?? null;
                        $plan_label    = $required_plan ? ucfirst($required_plan) : '';
                    ?>

                    <!-- GROUP HEADER: PLAIN TEXT ONLY -->
                    <li class="nav-item mt-3">
                        <div class="nav-link group-header-plain">
                            <i class="bi <?= $group['icon'] ?>"></i>
                            <span><?= htmlspecialchars($groupName) ?></span>
                            <?php if ($is_locked): ?>
                                <span class="ms-auto d-flex align-items-center gap-1">
                                    <i class="bi bi-lock-fill lock-icon"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>

                    <?php if ($is_locked): ?>
                        <li class="nav-item ms-3 mb-2">
                            <a href="views/subscription_manage.php"
                               class="upgrade-plan-hint w-100"
                               title="Upgrade to <?= htmlspecialchars($plan_label) ?> to unlock">
                                <i class="bi bi-gem"></i>
                                Upgrade to <?= htmlspecialchars($plan_label) ?>
                            </a>
                        </li>

                    <?php else: ?>
<?php 
// Separate parent items (parent_id = NULL) and child items
$parentItems = [];
$childItems = [];
foreach ($group['items'] as $item) {
    if (empty($item['parent_id'])) {
        $parentItems[] = $item;
    } else {
        $childItems[$item['parent_id']][] = $item;
    }
}
?>

<?php foreach ($parentItems as $item): 
    $hasChildren = !empty($childItems[$item['id']]);
    $childCount = $hasChildren ? count($childItems[$item['id']]) : 0;
    
    // Check if parent or any child is active
    $is_item_active = false;
    $item_view = $item['view_name'] ?? $item['page_name'] ?? '';
    $item_url_view = '';
    if (!empty($item['url'])) {
        $item_url_parts = parse_url($item['url']);
        if (isset($item_url_parts['query'])) {
            parse_str($item_url_parts['query'], $item_query_params);
            $item_url_view = $item_query_params['view'] ?? '';
        }
    }
    
    // Parent active check
    if (!empty($v) && !empty($item_url_view) && $item_url_view === $v) {
        $is_item_active = true;
    }
    
    // Child active check
    $child_active = false;
    foreach ($childItems[$item['id']] ?? [] as $child) {
        $child_url_view = '';
        if (!empty($child['url'])) {
            $child_url_parts = parse_url($child['url']);
            if (isset($child_url_parts['query'])) {
                parse_str($child_url_parts['query'], $child_query_params);
                $child_url_view = $child_query_params['view'] ?? '';
            }
        }
        if (!empty($v) && !empty($child_url_view) && $child_url_view === $v) {
            $child_active = true;
            $is_item_active = true;
        }
    }
?>
    <li class="nav-item ms-3">
<?php if ($hasChildren): ?>
    <!-- Parent item: label clickable + separate chevron button -->
    <div class="parent-item-wrapper">
        <a class="nav-link item-highlight parent-toggle <?= $is_item_active ? 'active' : '' ?>"
           href="<?= htmlspecialchars($item['url']) ?>">
            <i class="bi <?= $item['icon'] ?>"></i>
            <span><?= htmlspecialchars($item['menu_name']) ?></span>
        </a>
        <!-- Separate chevron button for toggle -->
        <button type="button"
                class="submenu-chevron-btn"
                data-submenu-toggle="submenu-<?= $item['id'] ?>"
                aria-expanded="<?= $is_item_active ? 'true' : 'false' ?>"
                aria-label="Toggle submenu">
            <i class="bi bi-chevron-down submenu-chevron"></i>
        </button>
    </div>
    
    <!-- Submenu (collapsible — custom JS controlled) -->
    <div class="nav-submenu <?= $is_item_active ? 'show' : '' ?>" id="submenu-<?= $item['id'] ?>">
                <ul class="nav flex-column submenu-list">
                    <?php foreach ($childItems[$item['id']] as $child): 
                        $child_view = $child['view_name'] ?? $child['page_name'] ?? '';
                        $child_url_view = '';
                        if (!empty($child['url'])) {
                            $child_url_parts = parse_url($child['url']);
                            if (isset($child_url_parts['query'])) {
                                parse_str($child_url_parts['query'], $child_query_params);
                                $child_url_view = $child_query_params['view'] ?? '';
                            }
                        }
                        $is_child_active = (!empty($v) && !empty($child_url_view) && $child_url_view === $v);
                    ?>
                        <li class="nav-item">
                            <a class="nav-link item-highlight submenu-item <?= $is_child_active ? 'active' : '' ?>"
                               href="<?= htmlspecialchars($child['url']) ?>">
                                <i class="bi <?= $child['icon'] ?>"></i>
                                <span><?= htmlspecialchars($child['menu_name']) ?></span>
                                <?php if ($is_child_active): ?>
                                    <span class="ms-auto active-dot"></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <!-- Regular item (no children) -->
            <a class="nav-link item-highlight <?= $is_item_active ? 'active' : '' ?>"
               href="<?= htmlspecialchars($item['url']) ?>">
                <i class="bi <?= $item['icon'] ?>"></i>
                <span><?= htmlspecialchars($item['menu_name']) ?></span>
                <?php if ($is_item_active): ?>
                    <span class="ms-auto active-dot"></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
                    <?php endif; ?>

                <?php endforeach; ?>
            <?php endif; ?>

        </ul>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer mt-auto">
        <div class="user-info d-flex align-items-center gap-2 px-3 py-2">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div class="user-details flex-fill">
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                    <?php if ($hasSubscription && $subscription_plan): ?>
                        <span class="subscription-badge">
                            <i class="bi bi-gem me-1"></i><?php echo ucfirst($subscription_plan); ?> Plan
                        </span>
                    <?php endif; ?>
            </div>
            <a href="admin/logout.php" class="btn btn-link btn-sm" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>

<style>
/* ══════════════════════════════════════════════════════════
   BRAND / HEADER
   ══════════════════════════════════════════════════════════ */
.clinic-logo-container { width: 50px; height: 50px; flex-shrink: 0; }
.clinic-logo-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 2px solid #0d9488; padding: 2px; background: white; }
.clinic-logo-default { width: 100%; height: 100%; background: linear-gradient(135deg, #0d9488, #0891b2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; border: 2px solid #0d9488; }
.clinic-logo-default i { font-size: 1.5rem; }
.clinic-text-container { flex: 1; min-width: 0; }
.clinic-name { font-weight: 600; color: #2d3748; font-size: 0.95rem; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.eyecore-system { font-size: 0.75rem; color: #0d9488; font-weight: 500; display: block; margin-top: 2px; }
.sidebar-header { padding: 15px; border-bottom: 1px solid #e2e8f0; position: relative; }
.sidebar-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #4a5568; background: none; border: none; font-size: 1.2rem; cursor: pointer; }

/* ══════════════════════════════════════════════════════════
   SUBSCRIPTION BADGE
   ══════════════════════════════════════════════════════════ */
.subscription-badge {
    font-size: 0.6rem;
    color: #0d9488;
    background: #f0fdfa;
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    margin-top: 4px;
    font-weight: 600;
    border: 1px solid #99f6e4;
}

/* ══════════════════════════════════════════════════════════
   BASE NAV LINKS
   ══════════════════════════════════════════════════════════ */
.sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.2s;
    color: #4a5568;
    text-decoration: none;
}

/* ══════════════════════════════════════════════════════════
   GROUP HEADER (PLAIN TEXT)
   ══════════════════════════════════════════════════════════ */
.sidebar .group-header-plain {
    color: #6c757d;
    font-weight: 700;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px 12px;
    border-radius: 8px;
    background: transparent;
}
.sidebar .group-header-plain i { width: 20px; color: #6c757d; }
.sidebar .group-header-plain:hover { background: transparent; color: #495057; }
.lock-icon { font-size: 0.65rem; color: #9ca3af; }

/* ══════════════════════════════════════════════════════════
   ITEM HIGHLIGHT — PREMIUM MINIMALIST (Option 3)
   ══════════════════════════════════════════════════════════ */

/* ── DEFAULT STATE ── */
.sidebar .item-highlight {
    position: relative;
    background: transparent;
    color: #4a5568;
    padding: 10px 14px;
    border-radius: 10px;
    margin: 2px 0;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid transparent;
    text-decoration: none;
    display: flex !important;
    align-items: center;
    gap: 10px;
}

.sidebar .item-highlight i {
    width: 20px;
    color: #6c757d;
    font-size: 1rem;
    transition: all 0.25s ease;
    flex-shrink: 0;
}

/* ── HOVER STATE — Soft teal tint + subtle shift ── */
.sidebar .item-highlight:hover {
    background: linear-gradient(90deg, #f0fdfa 0%, #e6fffa 100%);
    color: #0d9488 !important;
    transform: translateX(4px);
    border-color: rgba(13, 148, 136, 0.15);
}

.sidebar .item-highlight:hover i {
    color: #0d9488 !important;
    transform: scale(1.1);
}

/* ══════════════════════════════════════════════════════════
   ACTIVE STATE — PREMIUM MINIMALIST
   Soft teal tint + left accent bar + teal text + bold
   ══════════════════════════════════════════════════════════ */
.sidebar .item-highlight.active {
    background: linear-gradient(90deg, rgba(13, 148, 136, 0.12) 0%, rgba(13, 148, 136, 0.04) 100%);
    color: #0d9488 !important;
    border-radius: 10px;
    padding: 10px 14px;
    font-weight: 700;
    box-shadow: 
        0 2px 8px rgba(13, 148, 136, 0.1),
        inset 0 1px 0 rgba(255, 255, 255, 0.6);
    border-left: 4px solid #0d9488;
}

.sidebar .item-highlight.active i {
    color: #0d9488 !important;
    transform: scale(1.1);
}

.sidebar .item-highlight.active span {
    color: #0d9488 !important;
}

/* ── ACTIVE DOT (right side) ── */
.sidebar .item-highlight.active .active-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    background: #0d9488;
    border-radius: 50%;
    margin-left: auto;
    box-shadow: 0 0 8px rgba(13, 148, 136, 0.6);
    animation: pulseDot 2s ease-in-out infinite;
}

@keyframes pulseDot {
    0%, 100% { 
        opacity: 1; 
        transform: scale(1);
        box-shadow: 0 0 8px rgba(13, 148, 136, 0.6);
    }
    50% { 
        opacity: 0.7; 
        transform: scale(0.85);
        box-shadow: 0 0 12px rgba(13, 148, 136, 0.9);
    }
}

/* ══════════════════════════════════════════════════════════
   DARK MODE SUPPORT
   ══════════════════════════════════════════════════════════ */
.theme-dark .sidebar .item-highlight {
    color: #a0aec0;
}

.theme-dark .sidebar .item-highlight i {
    color: #718096;
}

.theme-dark .sidebar .item-highlight:hover {
    background: linear-gradient(90deg, rgba(20, 184, 166, 0.15) 0%, rgba(20, 184, 166, 0.08) 100%);
    color: #5eead4 !important;
    border-color: rgba(20, 184, 166, 0.3);
}

.theme-dark .sidebar .item-highlight:hover i {
    color: #5eead4 !important;
}

.theme-dark .sidebar .item-highlight.active {
    background: linear-gradient(90deg, rgba(20, 184, 166, 0.18) 0%, rgba(20, 184, 166, 0.06) 100%);
    color: #5eead4 !important;
    border-left-color: #5eead4;
    box-shadow: 
        0 2px 8px rgba(20, 184, 166, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.theme-dark .sidebar .item-highlight.active i {
    color: #5eead4 !important;
}

.theme-dark .sidebar .item-highlight.active span {
    color: #5eead4 !important;
}

.theme-dark .sidebar .item-highlight.active .active-dot {
    background: #5eead4;
    box-shadow: 0 0 8px rgba(94, 234, 212, 0.8);
}

/* ══════════════════════════════════════════════════════════
   UPGRADE CTA (LOCKED GROUP)
   ══════════════════════════════════════════════════════════ */
.upgrade-plan-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    color: #0d9488;
    background: #f0fdfa;
    border: 1px dashed #0d9488;
    border-radius: 6px;
    padding: 6px 10px;
    margin: 3px 0 6px 0;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.upgrade-plan-hint:hover {
    background: #0d9488;
    color: #fff;
    border-style: solid;
    transform: translateX(3px);
    text-decoration: none;
}
.upgrade-plan-hint i {
    font-size: 0.75rem;
}

/* ══════════════════════════════════════════════════════════
   NO SUBSCRIPTION BANNER
   ══════════════════════════════════════════════════════════ */
.upgrade-sidebar-banner {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 12px;
    margin: 10px;
}
.btn-teal { background-color: #0d9488; color: white; border: none; }
.btn-teal:hover { background-color: #0f766e; color: white; }

/* ══════════════════════════════════════════════════════════
   USER FOOTER
   ══════════════════════════════════════════════════════════ */
.user-avatar { 
    width: 36px; 
    height: 36px; 
    background: linear-gradient(135deg, #0d9488, #0891b2); 
    color: white; 
    border-radius: 50%; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-weight: 600; 
    font-size: 0.9rem; 
    flex-shrink: 0; 
}
.user-name { font-weight: 500; font-size: 0.9rem; color: #2d3748; line-height: 1.2; }
.user-role { font-size: 0.75rem; color: #718096; line-height: 1.2; }
.trial-days { font-size: 0.65rem; }

/* ══════════════════════════════════════════════════════════
   UTILITIES
   ══════════════════════════════════════════════════════════ */
.text-muted { color: #6c757d !important; }
.fw-bold { font-weight: 700; }
.text-uppercase { text-transform: uppercase; }
.small { font-size: 0.75rem; }
.ms-3 { margin-left: 1rem; }
.ms-auto { margin-left: auto !important; }
.mt-3 { margin-top: 1rem; }
.me-1 { margin-right: 0.25rem; }
.w-100 { width: 100%; }
.mb-2 { margin-bottom: 0.5rem; }
.d-flex { display: flex; }
.align-items-center { align-items: center; }
.gap-1 { gap: 0.25rem; }
.gap-2 { gap: 0.5rem; }
.gap-3 { gap: 0.75rem; }
.px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
.py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
.flex-fill { flex: 1 1 auto; }
.mt-auto { margin-top: auto; }
.p-3 { padding: 1rem; }
.text-center { text-align: center; }
.text-warning { color: #f59e0b !important; }
.fs-1 { font-size: 2.25rem; }
.btn { display: inline-block; padding: 0.375rem 0.75rem; border-radius: 0.375rem; cursor: pointer; }
.btn-link { background: none; border: none; color: #6c757d; text-decoration: none; }
.btn-link:hover { color: #2d3748; }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
   ══════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .clinic-name { font-size: 0.85rem; max-width: 120px; }
    .clinic-logo-container { width: 40px; height: 40px; }
}

/* ══════════════════════════════════════════════════════════
   SUBSCRIPTION GRACE BANNERS
   ══════════════════════════════════════════════════════════ */
.subscription-alert {
    padding: 12px 14px;
    margin: 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}
.alert-danger-grace {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-left: 4px solid #dc2626;
}
.alert-warning-grace {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border-left: 4px solid #d97706;
}
.subscription-alert .alert-icon {
    font-size: 1.2rem;
    flex-shrink: 0;
    margin-top: 2px;
}
.alert-danger-grace .alert-icon { color: #dc2626; }
.alert-warning-grace .alert-icon { color: #d97706; }
.subscription-alert .alert-title {
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    line-height: 1.2;
    margin-bottom: 2px;
}
.alert-danger-grace .alert-title { color: #991b1b; }
.alert-warning-grace .alert-title { color: #92400e; }
.subscription-alert .alert-text {
    font-size: 0.75rem;
    line-height: 1.35;
    color: #4b5563;
    margin-bottom: 8px;
}
.subscription-alert .alert-btn {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
    color: white;
}
.alert-btn-danger { background: #dc2626; }
.alert-btn-danger:hover {
    background: #b91c1c;
    color: white;
    transform: translateY(-1px);
}
.alert-btn-warning { background: #d97706; }
.alert-btn-warning:hover {
    background: #b45309;
    color: white;
    transform: translateY(-1px);
}

/* ══════════════════════════════════════════════════════════
   SUBMENU STYLES (Custom — walang Bootstrap dependency)
   ══════════════════════════════════════════════════════════ */
.submenu-chevron {
    font-size: 0.75rem;
    transition: transform 0.25s ease;
    color: #94a3b8;
}

.parent-toggle:hover .submenu-chevron {
    color: #0d9488;
}

.parent-toggle.active .submenu-chevron {
    color: #0d9488;
}

/* Chevron rotation base sa aria-expanded state */
.parent-toggle[aria-expanded="true"] .submenu-chevron {
    transform: rotate(180deg);
}

/* Submenu container — custom toggle (hindi Bootstrap collapse) */
.nav-submenu {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    margin-top: 0;
    margin-bottom: 0;
}

.nav-submenu.show {
    max-height: 500px;
    margin-top: 4px;
    margin-bottom: 4px;
}

.submenu-list {
    padding-left: 0;
    margin-top: 4px;
    margin-bottom: 4px;
    border-left: 2px solid #e2e8f0;
    margin-left: 20px;
    padding-left: 8px;
}

.submenu-item {
    padding: 8px 12px !important;
    font-size: 0.82rem !important;
    color: #64748b !important;
}

.submenu-item i {
    font-size: 0.85rem !important;
    width: 18px !important;
}

.submenu-item:hover {
    color: #0d9488 !important;
    background: linear-gradient(90deg, #f0fdfa 0%, transparent 100%) !important;
}

.submenu-item.active {
    color: #0d9488 !important;
    font-weight: 600 !important;
    background: linear-gradient(90deg, rgba(13, 148, 136, 0.08) 0%, transparent 100%) !important;
    border-left: 3px solid #0d9488;
    margin-left: -11px;
    padding-left: 20px !important;
}

/* ✅ Parent item wrapper — for label + separate chevron button */
.parent-item-wrapper {
    display: flex;
    align-items: center;
    position: relative;
    margin: 2px 0;
}

.parent-item-wrapper .parent-toggle {
    flex: 1;
    margin: 0;
}

/* ✅ Separate chevron button (toggle only) */
.submenu-chevron-btn {
    background: transparent;
    border: none;
    padding: 8px 12px;
    cursor: pointer;
    color: #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s ease;
    margin-right: 4px;
    flex-shrink: 0;
}

.submenu-chevron-btn:hover {
    background: rgba(13, 148, 136, 0.08);
    color: #0d9488;
}

.submenu-chevron-btn[aria-expanded="true"] .submenu-chevron {
    transform: rotate(180deg);
}

.submenu-chevron-btn .submenu-chevron {
    transition: transform 0.25s ease;
    font-size: 0.8rem;
}
</style>

<script>
const SIDEBAR_USER_KEY = '<?= $js_user_key ?>';
const SIDEBAR_PLAN     = <?= $js_plan ?>;

// Mobile toggle
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggleSidebarMobile');
    const sidebar   = document.getElementById('sidebar');
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            this.innerHTML = sidebar.classList.contains('collapsed')
                ? '<i class="bi bi-list"></i>'
                : '<i class="bi bi-x-lg"></i>';
        });
    }

    // Bootstrap tooltips (if available)
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el, { placement: 'right' });
        });
    }
});

// ── Submenu: Pure custom toggle (chevron button only) ──
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.submenu-chevron-btn[data-submenu-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            
            const targetId = this.getAttribute('data-submenu-toggle');
            const target = document.getElementById(targetId);
            if (!target) return;
            
            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            
            // Toggle state
            if (isExpanded) {
                // Collapse
                target.classList.remove('show');
                this.setAttribute('aria-expanded', 'false');
            } else {
                // Expand
                target.classList.add('show');
                this.setAttribute('aria-expanded', 'true');
            }
        });
    });
});
</script>