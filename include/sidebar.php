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

if ($clinic_id) {
    $subHelper         = new SubscriptionHelper($pdo, $clinic_id);
    $hasSubscription   = $subHelper->hasActiveSubscription();
    $subscription_plan = $subHelper->getCurrentPlan(); // 'basic', 'professional', 'enterprise'
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
                        <?php foreach ($group['items'] as $item): ?>
                            <?php 
                            $is_item_active = false;
                            
                            // Get the view name (prefer view_name, fallback to page_name)
                            $item_view = $item['view_name'] ?? $item['page_name'] ?? '';
                            
                            // Check if this menu item matches the current view
                            if (!empty($v) && !empty($item_view) && $v === $item_view) {
                                $is_item_active = true;
                            } 
                            // Check if we're on the page directly
                            elseif (empty($v) && !empty($item['url'])) {
                                $url_parts = parse_url($item['url']);
                                if (isset($url_parts['query'])) {
                                    parse_str($url_parts['query'], $query_params);
                                    if (isset($query_params['view']) && $query_params['view'] === $item_view) {
                                        $is_item_active = true;
                                    }
                                }
                            }
                            ?>
                            <li class="nav-item ms-3">
                                <!-- ITEM LINK: HIGHLIGHT LANG DITO -->
                                <a class="nav-link item-highlight <?= $is_item_active ? 'active' : '' ?>"
                                   href="<?= htmlspecialchars($item['url']) ?>">
                                    <i class="bi <?= $item['icon'] ?>"></i>
                                    <span><?= htmlspecialchars($item['menu_name']) ?></span>
                                    <?php if ($is_item_active): ?>
                                        <span class="ms-auto active-dot"></span>
                                    <?php endif; ?>
                                </a>
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
/* ── Brand / Header ── */
.clinic-logo-container { width: 50px; height: 50px; flex-shrink: 0; }
.clinic-logo-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 2px solid #0d9488; padding: 2px; background: white; }
.clinic-logo-default { width: 100%; height: 100%; background: linear-gradient(135deg, #0d9488, #0891b2); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; border: 2px solid #0d9488; }
.clinic-logo-default i { font-size: 1.5rem; }
.clinic-text-container { flex: 1; min-width: 0; }
.clinic-name { font-weight: 600; color: #2d3748; font-size: 0.95rem; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.eyecore-system { font-size: 0.75rem; color: #0d9488; font-weight: 500; display: block; margin-top: 2px; }
.sidebar-header { padding: 15px; border-bottom: 1px solid #e2e8f0; position: relative; }
.sidebar-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #4a5568; background: none; border: none; font-size: 1.2rem; cursor: pointer; }

/* ── Subscription Badge ── */
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

/* ── Nav Links ── */
.nav-link { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px; transition: all 0.2s; color: #4a5568; text-decoration: none; }
.nav-link:hover { color: #2d3748; }

/* ── GROUP HEADER (PLAIN TEXT) ── */
.group-header-plain { 
    color: #6c757d; 
    font-weight: 700; 
    font-size: 0.75rem; 
    text-transform: uppercase; 
    letter-spacing: 0.5px;
    padding: 8px 12px;
    border-radius: 8px;
}
.group-header-plain i { width: 20px; color: #6c757d; }
.group-header-plain:hover { background: transparent; color: #495057; }
.lock-icon { font-size: 0.65rem; color: #9ca3af; }

/* ── ITEM HIGHLIGHT (TEAL GREEN BUTTON STYLE) ── */
.item-highlight {
    background: transparent;
    color: #4a5568;
    padding: 8px 12px;
    border-radius: 8px;
}
.item-highlight i { width: 20px; color: #6c757d; }
.item-highlight:hover {
    background: #0d9488; /* Teal Green */
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(13, 148, 136, 0.2);
}
.item-highlight:hover i {
    color: white !important;
}

/* ── ACTIVE ITEM (TEAL GREEN HIGHLIGHT) ── */
.item-highlight.active {
    background: #0d9488;
    color: white !important;
    border-radius: 8px;
    padding: 8px 12px;
    box-shadow: 0 4px 8px rgba(13, 148, 136, 0.2);
}
.item-highlight.active i {
    color: white !important;
}
.item-highlight.active .active-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    background: #99f6e4;
    border-radius: 50%;
    margin-left: 8px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

/* ── Dark mode support ── */
.theme-dark .item-highlight:hover,
.theme-dark .item-highlight.active {
    background: #14b8a6;
}

/* ── Upgrade CTA (locked group) ── */
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

/* ── No Subscription Banner ── */
.upgrade-sidebar-banner {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 12px;
    margin: 10px;
}
.btn-teal { background-color: #0d9488; color: white; border: none; }
.btn-teal:hover { background-color: #0f766e; color: white; }

/* ── User Footer ── */
.user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, #0d9488, #0891b2); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; flex-shrink: 0; }
.user-name { font-weight: 500; font-size: 0.9rem; color: #2d3748; line-height: 1.2; }
.user-role { font-size: 0.75rem; color: #718096; line-height: 1.2; }
.trial-days { font-size: 0.65rem; }

/* ── Utilities ── */
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

@media (max-width: 768px) {
    .clinic-name { font-size: 0.85rem; max-width: 120px; }
    .clinic-logo-container { width: 40px; height: 40px; }
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
</script>