<?php
// includes/notification_dropdown.php
// Ito ang i-include mo sa lahat ng pages para sa notification dropdown

// Ensure user_id is set
if (!isset($user_id)) {
    $user_id = $_SESSION['user_id'] ?? 0;
}

$unread_count = 0;
$notifications = [];

if ($user_id > 0) {
    $unread_count = getUnreadNotificationCount($user_id);
    $notifications_query = getRecentNotifications($user_id);
}
?>

<!-- Notification Dropdown -->
<div class="notification-dropdown">
    <button class="notification-badge" onclick="toggleNotifications()" id="notificationBell">
        <i class="fas fa-bell"></i>
        <?php if ($unread_count > 0): ?>
            <span class="badge" id="notificationBadge"><?php echo $unread_count; ?></span>
        <?php endif; ?>
    </button>
    
    <div class="notification-menu" id="notificationMenu">
        <div class="notification-header">
            <h3><i class="fas fa-bell"></i> Notifications</h3>
            <?php if ($unread_count > 0): ?>
                <button onclick="markAllAsRead()" id="markAllBtn">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            <?php endif; ?>
        </div>
        
        <div class="notification-list" id="notificationList">
            <?php if (isset($notifications_query) && mysqli_num_rows($notifications_query) > 0): ?>
                <?php while($notif = mysqli_fetch_assoc($notifications_query)): ?>
                    <a href="<?php echo $notif['link'] ?: '#'; ?>" 
                       class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>"
                       data-id="<?php echo $notif['id']; ?>"
                       onclick="handleNotificationClick(event, this, <?php echo $notif['id']; ?>)">
                        
                        <div class="notification-icon <?php echo $notif['type']; ?>">
                            <?php 
                            $icons = [
                                'appointment' => '<i class="fas fa-calendar-check"></i>',
                                'favorite' => '<i class="fas fa-heart"></i>',
                                'system' => '<i class="fas fa-info-circle"></i>',
                                'promo' => '<i class="fas fa-tag"></i>'
                            ];
                            echo $icons[$notif['type']] ?? '<i class="fas fa-bell"></i>';
                            ?>
                        </div>
                        
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <?php if ($notif['message']): ?>
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <?php endif; ?>
                            <div class="notification-time">
                                <i class="fas fa-clock"></i> <?php echo timeAgo($notif['created_at']); ?>
                            </div>
                        </div>
                        
                        <?php if (!$notif['is_read']): ?>
                            <div class="notification-dot"></div>
                        <?php endif; ?>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <p>No notifications</p>
                    <small>You're all caught up!</small>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="notification-footer">
            <a href="notifications.php">
                <i class="fas fa-eye"></i> View all notifications
            </a>
        </div>
    </div>
</div>

<!-- Notification CSS - Enhanced -->
<style>
.notification-dropdown {
    position: relative;
    display: inline-block;
}

.notification-badge {
    background: none;
    border: none;
    cursor: pointer;
    position: relative;
    padding: 8px;
    transition: transform 0.2s;
}

.notification-badge:hover {
    transform: scale(1.1);
}

.notification-badge i {
    font-size: 22px;
    color: var(--text-secondary);
    transition: color 0.3s;
}

.notification-badge:hover i {
    color: var(--accent-primary);
}

.notification-badge .badge {
    position: absolute;
    top: 0;
    right: 0;
    background: var(--danger);
    color: white;
    font-size: 11px;
    padding: 2px 5px;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.notification-menu {
    position: absolute;
    top: 100%;
    right: -10px;
    width: 380px;
    background: var(--card-bg);
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--border-color);
    display: none;
    z-index: 1000;
    margin-top: 15px;
    overflow: hidden;
}

.notification-menu::before {
    content: '';
    position: absolute;
    top: -8px;
    right: 20px;
    width: 16px;
    height: 16px;
    background: var(--card-bg);
    border-left: 1px solid var(--border-color);
    border-top: 1px solid var(--border-color);
    transform: rotate(45deg);
    z-index: -1;
}

.notification-menu.show {
    display: block;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    background: linear-gradient(to right, var(--bg-secondary), var(--card-bg));
}

.notification-header h3 {
    font-size: 16px;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notification-header h3 i {
    color: var(--accent-primary);
}

.notification-header button {
    background: none;
    border: none;
    color: var(--accent-primary);
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 20px;
    transition: all 0.3s;
}

.notification-header button:hover {
    background: var(--bg-secondary);
    color: var(--accent-secondary);
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    padding: 16px 20px;
    text-decoration: none;
    border-bottom: 1px solid var(--border-light);
    transition: all 0.3s;
    position: relative;
}

.notification-item:hover {
    background: var(--bg-secondary);
    transform: translateX(5px);
}

.notification-item.unread {
    background: rgba(102, 126, 234, 0.05);
}

.notification-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    flex-shrink: 0;
    font-size: 18px;
    box-shadow: var(--shadow-sm);
}

.notification-icon.appointment {
    background: #e3f2fd;
    color: #1976d2;
}

.notification-icon.favorite {
    background: #fce4ec;
    color: #c2185b;
}

.notification-icon.system {
    background: #e8f5e9;
    color: #388e3c;
}

.notification-icon.promo {
    background: #fff3e0;
    color: #f57c00;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-size: 14px;
    color: var(--text-primary);
    margin-bottom: 4px;
    font-weight: 600;
    padding-right: 20px;
}

.notification-message {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 6px;
    line-height: 1.4;
}

.notification-time {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 5px;
}

.notification-time i {
    font-size: 10px;
}

.notification-dot {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 8px;
    height: 8px;
    background: var(--accent-primary);
    border-radius: 50%;
    animation: blink 2s infinite;
}

@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.notification-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.notification-empty i {
    font-size: 50px;
    margin-bottom: 15px;
    color: var(--text-muted);
    opacity: 0.5;
}

.notification-empty p {
    font-size: 16px;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.notification-empty small {
    font-size: 13px;
    color: var(--text-secondary);
}

.notification-footer {
    padding: 15px;
    text-align: center;
    border-top: 1px solid var(--border-color);
    background: var(--bg-secondary);
}

.notification-footer a {
    color: var(--accent-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 20px;
    border-radius: 20px;
    transition: all 0.3s;
}

.notification-footer a:hover {
    background: var(--card-bg);
    color: var(--accent-secondary);
    transform: translateY(-2px);
}

/* Scrollbar styling */
.notification-list::-webkit-scrollbar {
    width: 6px;
}

.notification-list::-webkit-scrollbar-track {
    background: var(--bg-secondary);
}

.notification-list::-webkit-scrollbar-thumb {
    background: var(--accent-primary);
    border-radius: 3px;
}

.notification-list::-webkit-scrollbar-thumb:hover {
    background: var(--accent-secondary);
}

/* Mobile responsive */
@media (max-width: 768px) {
    .notification-menu {
        width: 320px;
        right: -80px;
    }
    
    .notification-menu::before {
        right: 90px;
    }
}
</style>

<!-- Notification JavaScript - UPDATED with working AJAX -->
<script>
// Toggle notifications dropdown
function toggleNotifications() {
    const menu = document.getElementById('notificationMenu');
    menu.classList.toggle('show');
}

// Close when clicking outside
document.addEventListener('click', function(event) {
    const menu = document.getElementById('notificationMenu');
    const badge = document.querySelector('.notification-badge');
    
    if (menu && badge && !badge.contains(event.target) && !menu.contains(event.target)) {
        menu.classList.remove('show');
    }
});

// Handle notification click - mark as read then redirect
function handleNotificationClick(event, element, notificationId) {
    // Prevent default if there's no link
    if (!element.getAttribute('href') || element.getAttribute('href') === '#') {
        event.preventDefault();
    }
    
    // Mark as read via AJAX
    markAsRead(notificationId, function() {
        // Remove unread class and dot
        element.classList.remove('unread');
        const dot = element.querySelector('.notification-dot');
        if (dot) dot.remove();
        
        // Update badge count
        updateBadgeCount();
    });
    
    // Allow redirect if there's a valid link
    if (element.getAttribute('href') && element.getAttribute('href') !== '#') {
        setTimeout(() => {
            window.location.href = element.getAttribute('href');
        }, 300);
    }
}

// Mark single notification as read
function markAsRead(notificationId, callback) {
    fetch('mark-notification-read.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'id=' + notificationId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && callback) {
            callback();
        }
    })
    .catch(error => console.error('Error:', error));
}

// Mark all notifications as read
function markAllAsRead() {
    fetch('mark-all-notifications-read.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove all unread classes and dots
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
                const dot = item.querySelector('.notification-dot');
                if (dot) dot.remove();
            });
            
            // Remove badge
            const badge = document.getElementById('notificationBadge');
            if (badge) badge.remove();
            
            // Hide mark all button
            const markAllBtn = document.getElementById('markAllBtn');
            if (markAllBtn) markAllBtn.style.display = 'none';
        }
    })
    .catch(error => console.error('Error:', error));
}

// Update badge count (optional - for real-time updates)
function updateBadgeCount() {
    const currentBadge = document.getElementById('notificationBadge');
    const unreadItems = document.querySelectorAll('.notification-item.unread').length;
    
    if (unreadItems === 0) {
        if (currentBadge) currentBadge.remove();
        
        const markAllBtn = document.getElementById('markAllBtn');
        if (markAllBtn) markAllBtn.style.display = 'none';
    } else {
        if (currentBadge) {
            currentBadge.textContent = unreadItems;
        } else {
            // Recreate badge if needed
            const bell = document.querySelector('.notification-badge');
            const badge = document.createElement('span');
            badge.className = 'badge';
            badge.id = 'notificationBadge';
            badge.textContent = unreadItems;
            bell.appendChild(badge);
        }
    }
}

// Optional: Auto-refresh notifications every 30 seconds
let notificationInterval;

function startNotificationRefresh() {
    notificationInterval = setInterval(() => {
        // You can implement AJAX call here to check for new notifications
        console.log('Checking for new notifications...');
    }, 30000);
}

// Start refresh when page loads
document.addEventListener('DOMContentLoaded', function() {
    startNotificationRefresh();
});

// Clean up interval when page unloads
window.addEventListener('beforeunload', function() {
    if (notificationInterval) {
        clearInterval(notificationInterval);
    }
});
</script>