<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$user_id    = $_SESSION['user_id'] ?? 0;
$user_role  = $_SESSION['role'] ?? 'Staff';
$user_name  = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? 'User'));
$user_email = $_SESSION['email'] ?? 'user@email.com';
$clinic_id  = $_SESSION['clinic_id'] ?? null;

$initials = '';
foreach (explode(' ', $user_name) as $i => $part) {
    if ($i >= 2) break;
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = !empty($initials) ? $initials : 'U';

// FETCH NOTIFICATIONS
$notifications = [];
$unread_count = 0;
if ($user_id > 0 && $clinic_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, title, message, type, reference_number, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_count = $stmt->fetchColumn();
    } catch (PDOException $e) { }
}

// FETCH RECENT MESSAGES
$recent_messages = [];
$unread_message_count = 0;
if ($user_id > 0 && $clinic_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chats WHERE clinic_id = ? AND sender_type = 'user' AND is_read = 0");
        $stmt->execute([$clinic_id]);
        $unread_message_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c.user_id as other_user_id, MAX(c.created_at) as last_time,
                (SELECT message FROM chats c2 WHERE c2.clinic_id = c.clinic_id AND c2.user_id = c.user_id ORDER BY c2.created_at DESC LIMIT 1) as last_message,
                (SELECT COUNT(*) FROM chats c3 WHERE c3.clinic_id = c.clinic_id AND c3.user_id = c.user_id AND c3.sender_type = 'user' AND c3.is_read = 0) as unread
            FROM chats c WHERE c.clinic_id = ? GROUP BY c.user_id ORDER BY last_time DESC LIMIT 5
        ");
        $stmt->execute([$clinic_id]);
        $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($chats as $chat) {
            $stmt2 = $pdo->prepare("SELECT first_name, last_name, avatar FROM users WHERE id = ?");
            $stmt2->execute([$chat['other_user_id']]);
            $u = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($u) {
                $fname = trim($u['first_name'] ?? '');
                $lname = trim($u['last_name'] ?? '');
                $msg_initials = strtoupper(substr($fname, 0, 1) . substr($lname, 0, 1));
                if (empty($msg_initials)) $msg_initials = 'U';

                // ✅ ABSOLUTE PATH PARA SA AVATAR
                $avatar_url = null;
                if (!empty($u['avatar'])) {
                    $avatar_url = '/assets/images/profiles/' . $u['avatar'];
                }

                $preview = $chat['last_message'] ?? '📷 Photo';
                if (mb_strlen($preview) > 30) $preview = mb_substr($preview, 0, 30) . '…';
                
                $recent_messages[] = [
                    'user_id' => $chat['other_user_id'], // ✅ ID para sa link
                    'name' => trim($fname . ' ' . $lname),
                    'initials' => $msg_initials,
                    'avatar' => $avatar_url,
                    'message' => $preview,
                    'time' => date('g:i A', strtotime($chat['last_time'])),
                    'unread' => (int)$chat['unread']
                ];
            }
        }
    } catch (PDOException $e) { }
}

function formatNotificationTime($datetime) {
    $diff = (new DateTime())->diff(new DateTime($datetime));
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}
?>
<header class="topbar d-flex justify-content-between align-items-center px-3 py-2">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-link" id="toggleSidebar" type="button">
            <i class="bi bi-list" id="menuIcon"></i>
        </button>
        <small class="text-muted ms-2 d-none d-md-block">
            <?php date_default_timezone_set('Asia/Manila'); echo date('l, F j, Y'); ?>
        </small>
    </div>

    <div class="d-flex align-items-center gap-3">
        <!-- MESSAGES DROPDOWN -->
        <div class="dropdown position-relative">
            <button class="btn btn-link position-relative p-0" 
                    id="messagesDropdown" data-bs-toggle="dropdown" aria-expanded="false" 
                    type="button" style="width:40px; height:40px; z-index: 100;">
                <span class="position-relative d-inline-block">
                    <i class="bi bi-chat-dots fs-4" style="color: #4b5563;"></i>
                    <?php if($unread_message_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill"
                          style="font-size:0.65rem; padding:0.25em 0.45em; background-color: #008080; color: white; z-index: 101;">
                        <?php echo $unread_message_count > 9 ? '9+' : $unread_message_count; ?>
                    </span>
                    <?php endif; ?>
                </span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="messagesDropdown" 
                style="width: 360px; position: fixed !important; top: 60px !important; right: 20px !important; z-index: 9999999 !important; background: white !important; border: 1px solid #e9ecef !important; border-radius: 8px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; max-height: 480px; overflow-y: auto; padding: 0;">
                
                <li class="d-flex justify-content-between align-items-center px-3 py-2" style="border-bottom: 1px solid #e9ecef; position: sticky; top: 0; background: white; z-index: 2;">
                    <span class="fw-semibold" style="color: #1e293b;">Messages</span>
                    <?php if($unread_message_count > 0): ?>
                    <span class="badge" style="background-color: #008080; color: white; font-size: 0.7rem;"><?php echo $unread_message_count; ?> new</span>
                    <?php endif; ?>
                </li>
                
                <?php if (empty($recent_messages)): ?>
                    <li><div class="text-center py-5 px-3"><i class="bi bi-chat-dots" style="font-size: 3rem; color: #008080; opacity: 0.3;"></i><p class="text-muted mt-2 mb-0 small">No messages yet</p></div></li>
                <?php else: ?>
                    <?php foreach ($recent_messages as $msg): ?>
                        <li style="border-bottom: 1px solid #e9ecef; <?php echo $msg['unread'] > 0 ? 'background-color: #f0f9f9;' : ''; ?>">
                            <!-- ✅ DITO DAPAT DERETSO SA CRM MESSAGES PAGE -->
                            <a href="main.php?view=crm_messages&user_id=<?= $msg['user_id'] ?>" class="d-flex align-items-center px-3 py-2 text-decoration-none" style="gap: 12px;">
                                
                                <div style="width: 44px; height: 44px; border-radius: 50%; background-color: #008080; color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 1rem; flex-shrink: 0; overflow: hidden;">
                                    <?php if(!empty($msg['avatar'])): ?>
                                        <img src="<?= htmlspecialchars($msg['avatar']) ?>" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <?= htmlspecialchars($msg['initials']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="fw-semibold small" style="color: <?= $msg['unread'] > 0 ? '#008080' : '#2c3e50' ?>; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px;"><?= htmlspecialchars($msg['name']) ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem; white-space: nowrap; margin-left: 8px;"><?= $msg['time'] ?></small>
                                    </div>
                                    <div class="small text-muted" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8rem;"><?= htmlspecialchars($msg['message']) ?></div>
                                </div>
                                <?php if ($msg['unread'] > 0): ?><div style="width: 8px; height: 8px; border-radius: 50%; background-color: #008080; flex-shrink: 0;"></div><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    
                    <!-- ✅ "SEE ALL" DERETSO SA CRM MESSAGES -->
                    <li style="list-style: none;"><a href="main.php?view=crm_messages" class="d-block text-center py-2 text-decoration-none small" style="color: #008080; border-top: 1px solid #e9ecef;"><i class="bi bi-arrow-right-circle me-1"></i> See all messages</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- NOTIFICATIONS DROPDOWN (WALANG BINAGO) -->
        <div class="dropdown position-relative">
            <button class="btn btn-link position-relative p-0" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false" type="button" style="width:40px; height:40px; z-index: 100;">
                <span class="position-relative d-inline-block">
                    <i class="bi bi-bell fs-4" style="color: #4b5563;"></i>
                    <?php if($unread_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" style="font-size:0.65rem; padding:0.25em 0.45em; background-color: #008080; color: white; z-index: 101;"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                    <?php endif; ?>
                </span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="notificationsDropdown" style="width: 360px; position: fixed !important; top: 60px !important; right: 20px !important; z-index: 9999999 !important; background: white !important; border: 1px solid #e9ecef !important; border-radius: 8px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; max-height: 480px; overflow-y: auto; padding: 0;">
                <li class="d-flex justify-content-between align-items-center px-3 py-2" style="border-bottom: 1px solid #e9ecef; position: sticky; top: 0; background: white; z-index: 2;">
                    <span class="fw-semibold" style="color: #1e293b;">Notifications</span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if($unread_count > 0): ?>
                        <span class="badge" style="background-color: #008080; color: white; font-size: 0.7rem;"><?php echo $unread_count; ?> new</span>
                        <a href="views/notifications.php?mark_all=1" class="text-decoration-none small" style="color: #008080;" onclick="return confirm('Mark all as read?')"><i class="bi bi-check-all"></i></a>
                        <?php endif; ?>
                        <a href="views/notifications.php" class="text-decoration-none small" style="color: #008080;"><i class="bi bi-gear"></i></a>
                    </div>
                </li>
                <?php if (empty($notifications)): ?>
                    <li><div class="text-center py-5 px-3"><i class="bi bi-bell-slash" style="font-size: 3rem; color: #008080; opacity: 0.3;"></i><p class="text-muted mt-2 mb-0 small">No notifications yet</p></div></li>
                <?php else: ?>
                    <?php foreach ($notifications as $note): ?>
                        <li class="notification-item" data-id="<?php echo $note['id']; ?>" data-title="<?php echo htmlspecialchars($note['title']); ?>" data-message="<?php echo htmlspecialchars($note['message']); ?>" data-type="<?php echo $note['type']; ?>" data-reference="<?php echo htmlspecialchars($note['reference_number']); ?>" data-time="<?php echo formatNotificationTime($note['created_at']); ?>" data-created="<?php echo $note['created_at']; ?>" style="border-bottom: 1px solid #e9ecef; <?php echo !$note['is_read'] ? 'background-color: #f0f9f9;' : ''; ?> cursor: pointer; transition: background-color 0.2s; list-style: none;">
                            <div class="d-flex px-3 py-2" style="gap: 12px;">
                                <div style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #008080; font-size: 1.25rem;">
                                    <i class="bi bi-<?php $icon = 'bell'; switch($note['type']) { case 'appointment': $icon = 'calendar-check'; break; case 'inventory': $icon = 'box'; break; case 'patient': $icon = 'person'; break; case 'system': $icon = 'gear'; break; } echo $icon; ?>"></i>
                                </div>
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="fw-semibold small" style="color: <?php echo !$note['is_read'] ? '#008080' : '#2c3e50'; ?>; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;"><?php echo htmlspecialchars($note['title']); ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem; white-space: nowrap; margin-left: 8px;"><?php echo formatNotificationTime($note['created_at']); ?></small>
                                    </div>
                                    <?php if (!empty($note['message'])): ?><div class="small text-muted" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8rem;"><?php echo htmlspecialchars(substr($note['message'], 0, 40)) . (strlen($note['message']) > 40 ? '...' : ''); ?></div><?php endif; ?>
                                    <?php if (!empty($note['reference_number'])): ?><div class="small text-muted" style="font-size: 0.7rem;"><i class="bi bi-hash"></i> <?php echo htmlspecialchars($note['reference_number']); ?></div><?php endif; ?>
                                </div>
                                <?php if (!$note['is_read']): ?><div style="width: 8px; height: 8px; border-radius: 50%; background-color: #008080; align-self: center;"></div><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <li style="list-style: none;"><a href="views/notifications.php" class="d-block text-center py-2 text-decoration-none small" style="color: #008080; border-top: 1px solid #e9ecef;"><i class="bi bi-arrow-right-circle me-1"></i> See all notifications</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- USER DROPDOWN -->
        <div class="dropdown position-relative">
            <button class="btn btn-light d-flex align-items-center gap-2 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" type="button" style="z-index: 100; border: 1px solid #e9ecef;">
                <div class="user-avatar-sm" style="background-color: #008080; color: white;"><?php echo $initials; ?></div>
                <span class="fw-medium small d-none d-md-inline" style="color: #1e293b;"><?php echo htmlspecialchars($user_name); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow" style="position: fixed !important; top: 60px !important; right: 20px !important; z-index: 9999999 !important; background: white !important; border: 1px solid #e9ecef !important; border-radius: 8px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; min-width: 240px;">
                <li class="px-3 py-2" style="border-bottom: 1px solid #e9ecef;">
                    <div class="fw-semibold" style="color: #1e293b;"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($user_email); ?></div>
                    <div class="text-muted small mt-1"><span class="badge" style="background-color: #008080; color: white;"><?php echo htmlspecialchars($user_role); ?></span></div>
                </li>
                <li><a class="dropdown-item py-2" href="main.php?view=profile" style="color: #1e293b;"><i class="bi bi-person me-2" style="color: #008080;"></i> My Profile</a></li>
                <li><hr class="dropdown-divider my-1"></li>
                <li><a class="dropdown-item py-2 text-danger" href="admin/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.1);">
            <div class="modal-header" style="border-bottom: 2px solid #008080; padding: 1rem 1.5rem;">
                <h5 class="modal-title" id="notificationModalTitle"><i class="bi bi-bell" style="color: #008080; margin-right: 8px;"></i> Notification Details</h5>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleSidebar');
    const menuIcon = document.getElementById('menuIcon');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('collapsed');
            if (sidebar.classList.contains('collapsed')) {
                menuIcon.classList.remove('bi-x-lg'); menuIcon.classList.add('bi-list');
            } else {
                menuIcon.classList.remove('bi-list'); menuIcon.classList.add('bi-x-lg');
            }
        });
    }
    
    let notificationModal;
    if (typeof bootstrap !== 'undefined') {
        notificationModal = new bootstrap.Modal(document.getElementById('notificationModal'));
    }
    
    let currentNotificationId = null;
    
    document.querySelectorAll('.notification-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            
            const dropdownBtn = document.getElementById('notificationsDropdown');
            if (dropdownBtn) {
                const dropdownMenu = dropdownBtn.nextElementSibling;
                if (dropdownMenu) { dropdownMenu.style.display = 'none'; dropdownMenu.classList.remove('show'); }
                const dropdownParent = dropdownBtn.closest('.dropdown');
                if (dropdownParent) dropdownParent.classList.remove('show');
                if (typeof bootstrap !== 'undefined') { const bsDropdown = bootstrap.Dropdown.getInstance(dropdownBtn); if (bsDropdown) bsDropdown.hide(); }
                dropdownBtn.blur();
            }
            
            document.querySelectorAll('.dropdown.show, .dropdown-menu.show, [aria-expanded="true"]').forEach(el => {
                if (el.classList) el.classList.remove('show');
                if (el.hasAttribute && el.hasAttribute('aria-expanded')) el.setAttribute('aria-expanded', 'false');
            });
            
            const id = this.getAttribute('data-id'); const title = this.getAttribute('data-title'); const message = this.getAttribute('data-message'); const type = this.getAttribute('data-type'); const reference = this.getAttribute('data-reference'); const time = this.getAttribute('data-time'); const created = this.getAttribute('data-created');
            currentNotificationId = id;
            let icon = 'bell'; switch(type) { case 'appointment': icon = 'calendar-check'; break; case 'inventory': icon = 'box'; break; case 'patient': icon = 'person'; break; case 'system': icon = 'gear'; break; }
            const dateObj = new Date(created);
            
            document.getElementById('notificationModalTitle').innerHTML = `<i class="bi bi-${icon}" style="color: #008080; margin-right: 8px;"></i> Notification`;
            
            let modalBody = `<div style="text-align: center; margin-bottom: 20px;"><div style="width: 64px; height: 64px; border-radius: 50%; background-color: #f0f9f9; display: flex; align-items: center; justify-content: center; color: #008080; font-size: 2rem; margin: 0 auto 16px auto;"><i class="bi bi-${icon}"></i></div><h5 style="color: #1e293b; font-weight: 600; margin-bottom: 8px;">${title}</h5><p style="color: #64748b; font-size: 0.9rem; margin-bottom: 16px;"><i class="bi bi-clock me-1" style="color: #008080;"></i> ${time}</p></div>`;
            
            if (message && message !== '' && message !== 'null') {
                modalBody += `<div style="background-color: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 16px;"><p style="color: #1e293b; line-height: 1.6; margin: 0; font-size: 1rem;">${message.replace(/\n/g, '<br>')}</p></div>`;
            } else {
                modalBody += `<div style="background-color: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 16px; text-align: center;"><p style="color: #94a3b8; margin: 0; font-style: italic;">No additional message</p></div>`;
            }
            
            modalBody += `<div style="display: flex; justify-content: space-between; align-items: center; padding-top: 8px;"><span style="color: #64748b; font-size: 0.85rem;"><i class="bi bi-tag me-1" style="color: #008080;"></i> ${type.charAt(0).toUpperCase() + type.slice(1)}</span>${reference ? `<span style="color: #64748b; font-size: 0.85rem;"><i class="bi bi-hash me-1" style="color: #008080;"></i> ${reference}</span>` : ''}<span style="color: #64748b; font-size: 0.85rem;"><i class="bi bi-calendar me-1" style="color: #008080;"></i> ${dateObj.toLocaleDateString()}</span></div>`;
            
            document.getElementById('notificationModalBody').innerHTML = modalBody;
            markNotificationAsRead(id, this);
            if (notificationModal) notificationModal.show();
        });
    });
    
    document.getElementById('markReadFromModal').addEventListener('click', function() {
        if (currentNotificationId) {
            const notificationItem = document.querySelector(`.notification-item[data-id="${currentNotificationId}"]`);
            markNotificationAsRead(currentNotificationId, notificationItem);
            if (notificationModal) notificationModal.hide();
        }
    });
    
    function markNotificationAsRead(id, element) {
        if (!id) return;
        fetch('views/mark_notification_read.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + id })
        .then(response => response.json())
        .then(data => {
            if (data.success && element) {
                element.style.backgroundColor = 'white';
                const unreadDot = element.querySelector('div[style*="width: 8px; height: 8px;"]'); if (unreadDot) unreadDot.remove();
                const titleElement = element.querySelector('.fw-semibold.small'); if (titleElement) titleElement.style.color = '#2c3e50';
                const unreadBadge = document.querySelector('#notificationsDropdown + .dropdown-menu .badge');
                if (unreadBadge) { let count = parseInt(unreadBadge.textContent) - 1; if (count > 0) { unreadBadge.textContent = count; } else { unreadBadge.remove(); const markAllLink = document.querySelector('#notificationsDropdown + .dropdown-menu a[href*="mark_all"]'); if (markAllLink) markAllLink.remove(); } }
                const bellBadge = document.querySelector('#notificationsDropdown .badge'); if (bellBadge) { let count = parseInt(bellBadge.textContent) - 1; if (count > 0) { bellBadge.textContent = count > 9 ? '9+' : count; } else { bellBadge.remove(); } }
            }
        })
        .catch(error => console.error('Error marking as read:', error));
    }
    
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            let menu = this.nextElementSibling;
            if(menu && menu.classList.contains('dropdown-menu')) {
                document.querySelectorAll('.dropdown-menu[style*="display: block"]').forEach(function(m) { if(m !== menu) { m.style.display = 'none'; m.classList.remove('show'); } });
                if(menu.style.display === 'block') { menu.style.display = 'none'; menu.classList.remove('show'); this.setAttribute('aria-expanded', 'false'); }
                else {
                    let rect = this.getBoundingClientRect();
                    menu.style.cssText = `position: fixed !important; top: ${rect.bottom + 5}px !important; right: ${window.innerWidth - rect.right}px !important; left: auto !important; display: block !important; z-index: 9999999 !important; background: white !important; border: 1px solid #e9ecef !important; border-radius: 8px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; min-width: ${this.id === 'notificationsDropdown' || this.id === 'messagesDropdown' ? '360px' : '240px'} !important; max-height: ${this.id === 'notificationsDropdown' || this.id === 'messagesDropdown' ? '480px' : 'auto'} !important; overflow-y: ${this.id === 'notificationsDropdown' || this.id === 'messagesDropdown' ? 'auto' : 'visible'} !important; padding: 0 !important;`;
                    menu.classList.add('show'); this.setAttribute('aria-expanded', 'true');
                }
            }
        });
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(function(menu) {
                menu.style.display = 'none'; menu.classList.remove('show');
                let btn = menu.previousElementSibling;
                if(btn && btn.hasAttribute('data-bs-toggle')) { btn.setAttribute('aria-expanded', 'false'); }
            });
        }
    });
});
</script>

<style>
:root { --teal: #008080; }
.dropdown-toggle { cursor: pointer !important; pointer-events: auto !important; position: relative; z-index: 100 !important; }
.dropdown-menu * { position: relative; z-index: 1; pointer-events: auto !important; }
.dropdown-menu li, .dropdown-menu .dropdown-item, .dropdown-menu div { background-color: transparent !important; }
.notification-item:hover { background-color: #f8f9fa !important; }
.btn-link:hover { background-color: rgba(0,0,0,0.05); border-radius: 50%; }
.topbar { isolation: isolate; position: sticky; top: 0; background: white; border-bottom: 1px solid #e9ecef; }
.user-avatar-sm { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; }
.modal-header { border-bottom: 2px solid #008080; }
.modal-footer { border-top: 1px solid #e9ecef; }
.dropdown-item { color: #1e293b; transition: all 0.2s; }
.dropdown-item:hover { background-color: #f8f9fa !important; color: #008080 !important; }
.dropdown-item i { color: #008080; }
.dropdown-menu::-webkit-scrollbar { width: 6px; }
.dropdown-menu::-webkit-scrollbar-track { background: #f1f1f1; }
.dropdown-menu::-webkit-scrollbar-thumb { background: #008080; border-radius: 3px; }
.dropdown-menu::-webkit-scrollbar-thumb:hover { background: #006666; }
</style>