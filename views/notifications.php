<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// Mark notification as read if ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['id'], $user_id]);
        
        // Return JSON for AJAX requests
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false]);
            exit;
        }
    }
}

// Mark all as read
if (isset($_GET['mark_all'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        header('Location: main.php?view=notifications');
        exit;
    } catch (PDOException $e) {
        error_log("Error marking all as read: " . $e->getMessage());
    }
}

// Delete notification
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $user_id]);
        header('Location: main.php?view=notifications');
        exit;
    } catch (PDOException $e) {
        error_log("Error deleting notification: " . $e->getMessage());
    }
}

// FETCH REAL NOTIFICATIONS FROM DATABASE
$notifications = [];
$unread_count = 0;

if ($user_id > 0) {
    try {
        // Get unread count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_count = $stmt->fetchColumn();
        
        // Get all notifications
        $stmt = $pdo->prepare("
            SELECT id, title, message, type, reference_number, link, is_read, created_at 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
    }
}

// Get user info for topbar
$user_role = $_SESSION['role'] ?? 'Staff';
$user_email = $_SESSION['email'] ?? 'user@email.com';
$clinic_id = $_SESSION['clinic_id'] ?? null;

// Generate initials
$initials = '';
foreach (explode(' ', $user_name) as $i => $part) {
    if ($i >= 2) break;
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = !empty($initials) ? $initials : 'U';

// Format time function
function formatNotificationTime($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

// Get icon based on type
function getNotificationIcon($type) {
    switch($type) {
        case 'appointment': return 'calendar-check';
        case 'inventory': return 'box';
        case 'patient': return 'person';
        case 'system': return 'gear';
        default: return 'bell';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - EyeCorePH</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* #008080 theme - puro lining lang */
        :root {
            --teal: #008080;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        
        /* TOPBAR - complete with all elements */
        .topbar {
            height: 64px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 99999;
            width: 100%;
        }
        
        .topbar .btn-link {
            color: #4b5563;
            text-decoration: none;
        }
        
        .topbar .btn-link:hover {
            color: #008080;
        }
        
        .user-avatar-sm {
            width: 36px;
            height: 36px;
            background-color: #008080;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        /* Main Content */
        .main-content {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Container */
        .notifications-container {
            max-width: 900px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        /* Header - puro lining lang */
        .notifications-header {
            padding: 20px 25px;
            border-bottom: 2px solid #008080;
            background: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notifications-header h2 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .notifications-header h2 i {
            color: #008080;
            margin-right: 10px;
        }
        
        /* Action buttons */
        .btn-outline-teal {
            color: #008080;
            border: 1px solid #008080;
            background: transparent;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .btn-outline-teal:hover {
            background: #008080;
            color: white;
        }
        
        .btn-teal {
            background: #008080;
            color: white;
            border: 1px solid #008080;
            padding: 8px 16px;
            border-radius: 6px;
        }
        
        .btn-teal:hover {
            background: #006666;
            border-color: #006666;
            color: white;
        }
        
        /* Stats bar */
        .stats-bar {
            padding: 15px 25px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            gap: 20px;
            color: #6c757d;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-item i {
            color: #008080;
        }
        
        .stat-badge {
            background: #008080;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 5px;
        }
        
        /* Notifications list */
        .notifications-list {
            padding: 0;
            margin: 0;
            list-style: none;
        }
        
        .notification-item {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s;
            position: relative;
            cursor: pointer;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-item.unread {
            background-color: #f0f9f9;
            border-left: 3px solid #008080;
        }
        
        .notification-item:hover {
            background-color: #f8f9fa;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #008080;
            background: transparent;
            font-size: 1.25rem;
        }
        
        .notification-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .notification-message {
            color: #6c757d;
            font-size: 0.95rem;
            margin-bottom: 8px;
        }
        
        .notification-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #adb5bd;
        }
        
        .notification-meta i {
            color: #008080;
            margin-right: 3px;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        
        .notification-item:hover .notification-actions {
            opacity: 1;
        }
        
        .action-btn {
            background: transparent;
            border: 1px solid #dee2e6;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .action-btn:hover {
            border-color: #008080;
            color: #008080;
        }
        
        .action-btn.delete:hover {
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #adb5bd;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #008080;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .filter-tabs {
            display: flex;
            border-bottom: 1px solid #e9ecef;
            padding: 0 25px;
            background: white;
        }
        
        .filter-tab {
            padding: 15px 20px;
            cursor: pointer;
            color: #6c757d;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .filter-tab:hover {
            color: #008080;
        }
        
        .filter-tab.active {
            color: #008080;
            border-bottom-color: #008080;
        }
        
        .filter-tab i {
            margin-right: 8px;
        }
        
        .modal-header {
            border-bottom: 2px solid #008080;
            padding: 15px 20px;
        }
        
        .modal-header .modal-title i {
            color: #008080;
            margin-right: 8px;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
        }
    </style>
</head>
<body>

<!-- COMPLETE TOPBAR - with menu, notification icon, date/time -->
<header class="topbar d-flex justify-content-between align-items-center px-3">
    
    <!-- LEFT - with back button to main.php -->
    <div class="d-flex align-items-center gap-2">
        <a href="../main.php" class="btn btn-link" id="backToDashboard">
            <i class="bi bi-arrow-left fs-4"></i>
        </a>

        <small class="text-muted ms-2 d-none d-md-block">
            <?php
            date_default_timezone_set('Asia/Manila');
            echo date('l, F j, Y');
            ?>
        </small>
    </div>

    <!-- RIGHT - with notification icon and user dropdown -->
    <div class="d-flex align-items-center gap-3">

        <!-- NOTIFICATIONS ICON - with unread badge (current page) -->
        <div class="position-relative">
            <a href="notifications.php" class="btn btn-link position-relative p-0" style="width:40px; height:40px;">
                <span class="position-relative d-inline-block">
                    <i class="bi bi-bell fs-4" style="color: #008080;"></i>
                    <?php if($unread_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill"
                          style="font-size:0.65rem; padding:0.25em 0.45em; background-color: #008080; color: white;">
                        <?php echo $unread_count > 9 ? '9+' : $unread_count; ?>
                    </span>
                    <?php endif; ?>
                </span>
            </a>
        </div>

        <!-- USER DROPDOWN -->
        <div class="dropdown position-relative">
            <button class="btn btn-light d-flex align-items-center gap-2 dropdown-toggle"
                    data-bs-toggle="dropdown" 
                    aria-expanded="false"
                    type="button"
                    style="z-index: 100; border: 1px solid #e9ecef;">
                <div class="user-avatar-sm">
                    <?php echo $initials; ?>
                </div>
                <span class="fw-medium small d-none d-md-inline" style="color: #1e293b;">
                    <?php echo htmlspecialchars($user_name); ?>
                </span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end shadow" 
                style="position: fixed !important; top: 60px !important; right: 20px !important; z-index: 9999999 !important; background: white !important; border: 1px solid #e9ecef !important; border-radius: 8px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; min-width: 240px;">
                
                <li class="px-3 py-2" style="border-bottom: 1px solid #e9ecef;">
                    <div class="fw-semibold" style="color: #1e293b;"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($user_email); ?></div>
                    <div class="text-muted small mt-1">
                        <span class="badge" style="background-color: #008080; color: white;"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="../main.php?view=profile" style="color: #1e293b;">
                        <i class="bi bi-person me-2" style="color: #008080;"></i> My Profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item py-2" href="../main.php?view=settings" style="color: #1e293b;">
                        <i class="bi bi-gear me-2" style="color: #008080;"></i> Settings
                    </a>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item py-2 text-danger" href="../logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

    </div>
</header>

<!-- Main Content -->
<div class="main-content">
    <!-- Page Title -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold" style="color: #1e293b;">
            <i class="bi bi-bell me-2" style="color: #008080;"></i> Notifications
        </h4>
        
        <div>
            <?php if ($unread_count > 0): ?>
            <a href="?mark_all=1" class="btn btn-sm btn-outline-teal me-2" onclick="return confirm('Mark all as read?')">
                <i class="bi bi-check-all"></i> Mark all read
            </a>
            <?php endif; ?>
            <button class="btn btn-sm btn-teal" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
    </div>
    
    <!-- Stats bar -->
    <div class="stats-bar mb-3" style="background: white; border-radius: 8px; padding: 15px;">
        <div class="stat-item">
            <i class="bi bi-bell"></i> Total: <span class="fw-semibold"><?php echo count($notifications); ?></span>
        </div>
        <div class="stat-item">
            <i class="bi bi-envelope-open"></i> Unread: 
            <span class="fw-semibold"><?php echo $unread_count; ?></span>
            <?php if ($unread_count > 0): ?>
            <span class="stat-badge"><?php echo $unread_count; ?> new</span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Filter tabs -->
    <div class="filter-tabs" id="filterTabs" style="background: white; border-radius: 8px 8px 0 0;">
        <div class="filter-tab active" data-filter="all">
            <i class="bi bi-list"></i> All
        </div>
        <div class="filter-tab" data-filter="unread">
            <i class="bi bi-envelope"></i> Unread
        </div>
        <div class="filter-tab" data-filter="read">
            <i class="bi bi-envelope-open"></i> Read
        </div>
    </div>
    
    <!-- Notifications List -->
    <?php if (empty($notifications)): ?>
        <div class="empty-state" style="background: white; border-radius: 0 0 8px 8px;">
            <i class="bi bi-bell-slash"></i>
            <h4>No notifications</h4>
            <p class="text-muted">You're all caught up! Check back later.</p>
        </div>
    <?php else: ?>
        <div style="background: white; border-radius: 0 0 8px 8px; border-top: none;">
            <ul class="notifications-list" id="notificationsList">
                <?php foreach ($notifications as $note): ?>
                    <li class="notification-item <?php echo $note['is_read'] ? '' : 'unread'; ?>" 
                        data-id="<?php echo $note['id']; ?>"
                        data-title="<?php echo htmlspecialchars($note['title']); ?>"
                        data-message="<?php echo htmlspecialchars($note['message']); ?>"
                        data-type="<?php echo $note['type']; ?>"
                        data-reference="<?php echo htmlspecialchars($note['reference_number']); ?>"
                        data-time="<?php echo formatNotificationTime($note['created_at']); ?>"
                        data-created="<?php echo $note['created_at']; ?>">
                        
                        <div class="d-flex">
                            <div class="notification-icon me-3">
                                <i class="bi bi-<?php echo getNotificationIcon($note['type']); ?>"></i>
                            </div>
                            
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="notification-title" style="color: <?php echo !$note['is_read'] ? '#008080' : '#2c3e50'; ?>;">
                                            <?php echo htmlspecialchars($note['title']); ?>
                                        </div>
                                        
                                        <?php if (!empty($note['message'])): ?>
                                            <div class="notification-message">
                                                <?php echo htmlspecialchars(substr($note['message'], 0, 60)) . (strlen($note['message']) > 60 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="notification-meta">
                                            <span><i class="bi bi-clock"></i> <?php echo formatNotificationTime($note['created_at']); ?></span>
                                            <?php if (!empty($note['reference_number'])): ?>
                                                <span><i class="bi bi-hash"></i> Ref: <?php echo htmlspecialchars($note['reference_number']); ?></span>
                                            <?php endif; ?>
                                            <span><i class="bi bi-tag"></i> <?php echo ucfirst($note['type'] ?? 'general'); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="notification-actions">
                                        <?php if (!$note['is_read']): ?>
                                            <a href="?id=<?php echo $note['id']; ?>" class="action-btn mark-read-btn" title="Mark as read" data-id="<?php echo $note['id']; ?>">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?delete=<?php echo $note['id']; ?>" class="action-btn delete" title="Delete" onclick="return confirm('Delete this notification?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.1);">
            <div class="modal-header" style="border-bottom: 2px solid #008080; padding: 1rem 1.5rem;">
                <h5 class="modal-title" id="notificationModalTitle">
                    <i class="bi bi-bell" style="color: #008080; margin-right: 8px;"></i>
                    Notification Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="notificationModalBody"></div>
            <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 1rem 1.5rem;">
                <button type="button" class="btn" style="border: 1px solid #008080; color: #008080; background: transparent;" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn" id="markReadFromModal" style="background-color: #008080; color: white; border: none;">Mark as Read</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let notificationModal;
    if (typeof bootstrap !== 'undefined') {
        notificationModal = new bootstrap.Modal(document.getElementById('notificationModal'));
    }
    
    let currentNotificationId = null;
    
    document.querySelectorAll('.notification-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            if (e.target.closest('.notification-actions')) {
                return;
            }
            
            e.preventDefault();
            
            const id = this.getAttribute('data-id');
            const title = this.getAttribute('data-title');
            const message = this.getAttribute('data-message');
            const type = this.getAttribute('data-type');
            const reference = this.getAttribute('data-reference');
            const time = this.getAttribute('data-time');
            const created = this.getAttribute('data-created');
            
            currentNotificationId = id;
            
            let icon = 'bell';
            switch(type) {
                case 'appointment': icon = 'calendar-check'; break;
                case 'inventory': icon = 'box'; break;
                case 'patient': icon = 'person'; break;
                case 'system': icon = 'gear'; break;
            }
            
            const dateObj = new Date(created);
            const formattedDate = dateObj.toLocaleString('en-US', { 
                year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            
            document.getElementById('notificationModalTitle').innerHTML = `
                <i class="bi bi-${icon}" style="color: #008080; margin-right: 8px;"></i>
                ${title}
            `;
            
            let modalBody = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background-color: #f0f9f9; display: flex; align-items: center; justify-content: center; color: #008080; font-size: 1.5rem;">
                            <i class="bi bi-${icon}"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: #1e293b; margin-bottom: 4px;">${title}</div>
                            <div style="color: #64748b; font-size: 0.85rem;">
                                <i class="bi bi-clock me-1" style="color: #008080;"></i> ${time}
                            </div>
                        </div>
                    </div>
            `;
            
            if (message && message !== '' && message !== 'null') {
                modalBody += `
                    <div style="background-color: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 16px; border-left: 3px solid #008080;">
                        <div style="font-weight: 500; color: #1e293b; margin-bottom: 8px;">Message:</div>
                        <div style="color: #475569; line-height: 1.6;">${message.replace(/\n/g, '<br>')}</div>
                    </div>
                `;
            }
            
            modalBody += `
                <div style="border-top: 1px solid #e9ecef; padding-top: 16px;">
                    <div style="display: flex; flex-wrap: wrap; gap: 16px;">
                        <div style="flex: 1; min-width: 120px;">
                            <div style="color: #64748b; font-size: 0.75rem;">TYPE</div>
                            <div style="color: #1e293b; font-weight: 500;">${type.charAt(0).toUpperCase() + type.slice(1)}</div>
                        </div>
                        ${reference ? `
                        <div style="flex: 1; min-width: 120px;">
                            <div style="color: #64748b; font-size: 0.75rem;">REFERENCE</div>
                            <div style="color: #1e293b; font-weight: 500;">${reference}</div>
                        </div>
                        ` : ''}
                        <div style="flex: 1; min-width: 120px;">
                            <div style="color: #64748b; font-size: 0.75rem;">RECEIVED</div>
                            <div style="color: #1e293b; font-weight: 500;">${formattedDate}</div>
                        </div>
                    </div>
                </div>
            </div>`;
            
            document.getElementById('notificationModalBody').innerHTML = modalBody;
            
            if (notificationModal) {
                notificationModal.show();
            }
            
            if (this.classList.contains('unread')) {
                markNotificationAsRead(id, this);
            }
        });
    });
    
    document.getElementById('markReadFromModal').addEventListener('click', function() {
        if (currentNotificationId) {
            const notificationItem = document.querySelector(`.notification-item[data-id="${currentNotificationId}"]`);
            markNotificationAsRead(currentNotificationId, notificationItem);
            if (notificationModal) {
                notificationModal.hide();
            }
        }
    });
    
    function markNotificationAsRead(id, element) {
        if (!id) return;
        
        // Use fetch with AJAX detection
        fetch('notifications.php?id=' + id, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && element) {
                element.classList.remove('unread');
                const titleElement = element.querySelector('.notification-title');
                if (titleElement) titleElement.style.color = '#2c3e50';
                
                const markBtn = element.querySelector('.mark-read-btn');
                if (markBtn) markBtn.remove();
                
                // Update unread count
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Fallback - reload page
            location.reload();
        });
    }
    
    // Filter functionality
    const filterTabs = document.querySelectorAll('.filter-tab');
    const notifications = document.querySelectorAll('.notification-item');
    
    filterTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            filterTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.getAttribute('data-filter');
            
            notifications.forEach(item => {
                if (filter === 'all') {
                    item.style.display = '';
                } else if (filter === 'unread') {
                    item.style.display = item.classList.contains('unread') ? '' : 'none';
                } else if (filter === 'read') {
                    item.style.display = !item.classList.contains('unread') ? '' : 'none';
                }
            });
        });
    });
});
</script>

</body>
</html>