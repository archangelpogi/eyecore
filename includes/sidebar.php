<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    die('Access denied.');
}

// SUPERADMIN ONLY
if ($_SESSION['role'] !== 'SuperAdmin') {
    die('Access denied. SuperAdmin only.');
}

// ✅ Include DB connection para available ang $pdo
require_once __DIR__ . '/../config/db.php';

$current_page = $_GET['page'] ?? 'dashboard';
$user_role = $_SESSION['role'];
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? 'Admin'));
$user_email = $_SESSION['email'] ?? 'admin@eyecore.com';
?>
<aside id="sidebar" class="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <div class="sidebar-brand d-flex align-items-center gap-2">
            <i class="bi bi-eye-fill brand-icon"></i>
            <div class="brand-text">
                <h5 class="mb-0">Eyecore</h5>
                <small>Optical Clinic</small>
            </div>
        </div>
        <button class="btn btn-link d-lg-none" id="toggleSidebarMobile">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- Sidebar Navigation -->
    <nav class="sidebar-nav">
        <ul class="nav flex-column">

            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'dashboard') ? 'active' : '' ?>"
                   href="developer.php?page=dashboard">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Clinic Management -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'clinics') ? 'active' : '' ?>"
                   href="developer.php?page=clinics">
                    <i class="bi bi-building"></i>
                    <span>Clinic Management</span>
                </a>
            </li>

            <!-- Supplier Approvals -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'supplier_approvals') ? 'active' : '' ?>"
                href="developer.php?page=supplier_approvals">
                    <i class="bi bi-shop"></i>
                    <span>Supplier Approvals</span>
                    <?php
                    try {
                        $pendingCount = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'Pending'")->fetchColumn();
                        if ($pendingCount > 0): ?>
                            <span class="badge bg-danger ms-auto"><?= $pendingCount ?></span>
                        <?php endif;
                    } catch (Exception $e) {}
                    ?>
                </a>
            </li>
            
            <!-- Subscription Income -->
             <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'subscription_income') ? 'active' : '' ?>"
                   href="developer.php?page=subscription_income">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Subscription Income</span>
                </a>
            </li>

            <!-- 3D Requests -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == '3d_requests') ? 'active' : '' ?>"
                href="developer.php?page=3d_requests">
                    <i class="bi bi-box-seam"></i>
                    <span>3D Model Requests</span>
                    <?php
                    try {
                        $count = $pdo->query("SELECT COUNT(*) FROM custom_3d_requests WHERE status = 'pending'")->fetchColumn();
                        if ($count > 0): ?>
                        <span class="badge bg-danger ms-auto"><?= $count ?></span>
                    <?php endif; 
                    } catch (Exception $e) {}
                    ?>
                </a>
            </li>

            <!-- User & Staff Management -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'users') ? 'active' : '' ?>"
                   href="developer.php?page=users">
                    <i class="bi bi-people-fill"></i>
                    <span>User & Staff Management</span>
                </a>
            </li>

            <!-- Reports & Analytics -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'reports') ? 'active' : '' ?>"
                   href="developer.php?page=reports">
                    <i class="bi bi-bar-chart-line"></i>
                    <span>Reports & Analytics</span>
                </a>
            </li>

            <!-- ✅ SUPPORT INBOX (BAGONG DAGDAG) -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'support_inbox') ? 'active' : '' ?>"
                   href="developer.php?page=support_inbox">
                    <i class="bi bi-headset"></i>
                    <span>Support Inbox</span>
                    <?php
                    try {
                        // Bilang ng unread threads mula sa users
                        $unreadSupport = $pdo->query("
                            SELECT COUNT(DISTINCT thread_id) 
                            FROM support_messages 
                            WHERE is_read_by_support = 0 AND sender_role != 'SuperAdmin'
                        ")->fetchColumn();
                        
                        if ($unreadSupport > 0): ?>
                            <span class="badge bg-danger ms-auto"><?= $unreadSupport ?></span>
                        <?php endif;
                    } catch (Exception $e) {}
                    ?>
                </a>
            </li>

            <!-- System Settings -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'settings') ? 'active' : '' ?>"
                   href="developer.php?page=settings">
                    <i class="bi bi-gear-wide-connected"></i>
                    <span>System Settings</span>
                </a>
            </li>

        </ul>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer mt-auto">
        <div class="user-info d-flex align-items-center gap-2 px-3 py-2">
            <div class="user-avatar">
                <?php
                $initials = '';
                foreach (explode(' ', $user_name) as $part) {
                    if (!empty($part)) {
                        $initials .= strtoupper(substr($part, 0, 1));
                        if (strlen($initials) >= 2) break;
                    }
                }
                echo !empty($initials) ? $initials : 'U';
                ?>
            </div>
            <div class="user-details flex-fill">
                <div class="user-name"><?= htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?= htmlspecialchars($user_role); ?></div>
            </div>
            <a href="auth/logout.php" class="btn btn-link btn-sm" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>