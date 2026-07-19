<?php
// index.php - SuperAdmin Dashboard
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include security class
require_once __DIR__ . '/config/security.php';

// AUTH CHECK
if (!Security::isAuthenticated()) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
    header('Location: auth/login.php');
    exit();
}

// SUPERADMIN ONLY CHECK
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'SuperAdmin') {
    Security::logActivity(
        'UNAUTHORIZED_ACCESS',
        'Authorization',
        'Non-SuperAdmin attempted access',
        $_SESSION['user_db_id'] ?? null
    );
    Security::logout();
    header('Location: auth/login.php?error=unauthorized');
    exit();
}

// CURRENT PAGE
$page = $_GET['page'] ?? 'dashboards';

// SUPER ADMIN MODULES
$superAdminModules = [
    'dashboards',
    'clinics',
    'subscription_income',
    '3d_requests',
    'patients',
    'appointments',
    'optical-records',
    'inventory',
    'sales',
    'decision-support',
    'frame-review',
    'users',
    'settings',
    'logs',
    'reports'
];

// BLOCK UNAUTHORIZED PAGE ACCESS
if (!is_array($superAdminModules)) {
    $superAdminModules = ['dashboards']; // fallback just in case
}

if (!in_array($page, $superAdminModules)) {
    Security::logActivity(
        'PAGE_ACCESS_DENIED',
        'Authorization',
        "Attempted to access: $page",
        $_SESSION['user_db_id'] ?? null
    );
    $page = 'dashboards';
}

// Generate CSRF token
$csrf_token = Security::generateCSRFToken();

// Load header
require_once 'includes/header.php';
?>

<div class="wrapper d-flex">
    <?php require_once 'includes/sidebar.php'; ?>

    <div class="main-content flex-fill">
        <?php require_once 'includes/topbar.php'; ?>

        <main class="content-area p-4">

            <?php
            $pageFile = __DIR__ . "/pages/{$page}.php";

            if (file_exists($pageFile)) {
                require $pageFile;
            } else {
                require __DIR__ . '/pages/dashboards.php';
            }
            ?>
        </main>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
