<?php
// session_name at session_start ay hina-handle ng includes/config.php
include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Navbar required vars
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);
$points_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points = $points_row['t'] ?: 0;
$bookings_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM appointments WHERE user_id=$user_id"));
$total_bookings = $bookings_row['t'] ?: 0;
$pending_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM appointments WHERE user_id=$user_id AND status='pending'"));
$pending = $pending_row['t'] ?: 0;

// Pre-selected clinic from URL
$selected_clinic_id = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;

// All active clinics
$all_clinics_q = mysqli_query($conn,
    "SELECT c.id, c.clinic_name, c.clinic_image, c.logo, c.cover_photo, c.city,
            (SELECT message FROM chats WHERE clinic_id=c.id AND user_id=$user_id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM chats WHERE clinic_id=c.id AND user_id=$user_id ORDER BY created_at DESC LIMIT 1) as last_time,
            (SELECT COUNT(*) FROM chats WHERE clinic_id=c.id AND user_id=$user_id AND sender_type='clinic' AND is_read=0) as unread
     FROM clinics c
     WHERE c.status = 'Active'
     ORDER BY last_time DESC, c.clinic_name ASC"
);
$all_clinics = [];
while ($row = mysqli_fetch_assoc($all_clinics_q)) {
    $img = null;
    if (!empty($row['cover_photo']))     $img = '/assets/images/clinic-covers/'  . $row['cover_photo'];
    elseif (!empty($row['clinic_image'])) $img = '/assets/images/clinic-images/' . $row['clinic_image'];
    elseif (!empty($row['logo']))         $img = '/assets/images/clinic-logos/'  . $row['logo'];
    $all_clinics[] = [
        'id'           => (int)$row['id'],
        'name'         => $row['clinic_name'],
        'city'         => $row['city'] ?? '',
        'image'        => $img,
        'last_message' => $row['last_message'] ? mb_strimwidth($row['last_message'], 0, 40, '…') : '',
        'last_time'    => $row['last_time'] ? date('g:i A', strtotime($row['last_time'])) : '',
        'unread'       => (int)$row['unread'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Messages — Eyecore</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ICON FIX */
i.fas,i.far,i.fab,i.fal{-webkit-text-fill-color:currentColor !important;background-clip:unset !important;-webkit-background-clip:unset !important;background-image:none !important;}

/* CSS variables */
:root{--primary:#00B761;--primary-dark:#00874A;--primary-light:#E3FCE9;--primary-gradient:linear-gradient(135deg,#00B761,#00A86B);--bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;--text-primary:#111827;--text-secondary:#6B7280;--text-muted:#9CA3AF;--border-color:#E5E7EB;--border-light:#F3F4F6;--shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-lg:0 12px 40px rgba(0,0,0,.1);--radius-full:999px;--danger:#EF4444}
.theme-dark{--bg-primary:#0D0D0D;--bg-secondary:#161616;--text-primary:#F9FAFB;--text-secondary:#9CA3AF;--text-muted:#6B7280;--border-color:#2A2A2A;--border-light:#222222;--primary-light:#0D2818}
i.fas,i.far,i.fab,i.fal{-webkit-text-fill-color:currentColor !important;background-image:none !important}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body,input,button,textarea,select{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
html,body{height:100%;overflow:hidden}
body{background:var(--bg-primary);color:var(--text-primary);display:flex;flex-direction:column;font-size:15px}

/* PAGE LAYOUT */
.msg-page{display:flex;flex:1;overflow:hidden;height:calc(100vh - 64px)}
@media(max-width:768px){.msg-page{height:calc(100vh - 60px - 60px)}}

/* ── SIDEBAR ── */
.msg-sidebar{width:320px;flex-shrink:0;border-right:1px solid var(--border-light);display:flex;flex-direction:column;background:var(--bg-secondary)}
@media(max-width:768px){
    .msg-sidebar{width:100%;border-right:none;display:none}
    .msg-sidebar.mob-show{display:flex}
}
.sidebar-head{padding:16px 20px;border-bottom:1px solid var(--border-light);flex-shrink:0}
.sidebar-head h2{font-size:20px;font-weight:800;margin-bottom:12px;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
.sidebar-head h2 i{color:var(--primary)}
.search-wrap{position:relative}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:15px}
.search-wrap input{width:100%;padding:10px 12px 10px 36px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-full);font-size:14px;color:var(--text-primary);outline:none;transition:border-color .2s}
.search-wrap input:focus{border-color:var(--primary)}

.clinic-list{flex:1;overflow-y:auto}
.clinic-item{display:flex;align-items:center;gap:12px;padding:14px 20px;cursor:pointer;transition:background .15s;border-bottom:1px solid var(--border-light);position:relative}
.clinic-item:hover{background:var(--bg-primary)}
.clinic-item.active{background:var(--primary-light)}
.clinic-avatar{width:46px;height:46px;border-radius:50%;flex-shrink:0;overflow:hidden;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px}
.clinic-avatar img{width:100%;height:100%;object-fit:cover}
.clinic-info{flex:1;min-width:0}
.clinic-name{font-size:15px;font-weight:600;color:var(--text-primary);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.clinic-city{font-size:12px;color:var(--text-muted);margin-bottom:2px}
.clinic-preview{font-size:13px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.clinic-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.clinic-time{font-size:11px;color:var(--text-muted)}
.unread-badge{background:var(--primary);color:#fff;font-size:10px;padding:2px 6px;border-radius:999px;font-weight:700}
.sidebar-empty{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:14px}

/* ── CHAT PANEL ── */
.msg-panel{flex:1;display:flex;flex-direction:column;overflow:hidden;background:var(--bg-primary)}
@media(max-width:768px){
    .msg-panel{display:none;position:fixed;inset:0;z-index:200;background:var(--bg-primary)}
    .msg-panel.mob-show{display:flex;flex-direction:column}
}

.chat-panel-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0;padding:40px 24px;text-align:center}
.empty-icon-wrap{width:76px;height:76px;background:#E3FCE9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:20px;position:relative;flex-shrink:0}
.empty-icon-wrap svg{width:34px;height:34px}
.empty-ping{position:absolute;top:-2px;right:-2px;width:20px;height:20px;background:#00B761;border-radius:50%;border:2px solid var(--bg-primary)}
.empty-title{font-size:19px;font-weight:700;color:var(--text-primary);margin-bottom:8px}
.empty-sub{font-size:14px;color:var(--text-secondary);line-height:1.6;margin-bottom:20px;max-width:280px}
.empty-tips{display:flex;flex-direction:column;gap:8px;width:100%;max-width:300px;text-align:left}
.empty-tip{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border-light);font-size:13px;color:var(--text-secondary)}
.empty-tip-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}

.chat-header-bar{display:flex;align-items:center;gap:12px;padding:14px 20px;background:var(--bg-secondary);border-bottom:1px solid var(--border-light);flex-shrink:0}
.back-mob{display:none;background:none;border:none;color:var(--primary);font-size:20px;cursor:pointer;padding:2px}
@media(max-width:768px){.back-mob{display:block}}
.chat-clinic-ava{width:40px;height:40px;border-radius:50%;overflow:hidden;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:16px;flex-shrink:0}
.chat-clinic-ava img{width:100%;height:100%;object-fit:cover}
.chat-clinic-info-bar{flex:1}
.chat-clinic-info-bar .name{font-size:16px;font-weight:700;color:var(--text-primary)}
.chat-clinic-info-bar .city{font-size:13px;color:var(--text-muted)}
.online-dot{width:8px;height:8px;background:#22C55E;border-radius:50%;flex-shrink:0}

.chat-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px}
.msg-wrap{display:flex;flex-direction:column}
.msg-wrap.user{align-items:flex-end}
.msg-wrap.clinic{align-items:flex-start}
.msg-bubble{max-width:70%;padding:11px 15px;border-radius:18px;font-size:15px;line-height:1.55;word-break:break-word}
.msg-wrap.user .msg-bubble{background:var(--primary-gradient);color:#fff;border-radius:18px 18px 4px 18px}
.msg-wrap.clinic .msg-bubble{background:var(--bg-secondary);color:var(--text-primary);border:1px solid var(--border-light);border-radius:18px 18px 18px 4px}
.msg-time{font-size:12px;color:var(--text-muted);margin-top:4px;padding:0 4px}
.msg-date-divider{text-align:center;font-size:12px;color:var(--text-muted);padding:8px 0;display:flex;align-items:center;gap:10px}
.msg-date-divider::before,.msg-date-divider::after{content:'';flex:1;height:1px;background:var(--border-light)}

/* IMAGE MESSAGE */
.msg-bubble.has-image{padding:6px;background:transparent !important;border:none !important}
.msg-bubble .chat-img{max-width:240px;max-height:240px;border-radius:14px;display:block;cursor:pointer;object-fit:cover}
.msg-bubble .chat-img-caption{padding:8px 6px 2px;font-size:14px}
.msg-wrap.user .msg-bubble.has-image .chat-img{border:2px solid var(--primary-light)}
.msg-wrap.clinic .msg-bubble.has-image .chat-img{border:1px solid var(--border-light)}

/* IMAGE PREVIEW BEFORE SENDING */
.image-preview-bar{display:none;align-items:center;gap:10px;padding:10px 20px;background:var(--bg-secondary);border-top:1px solid var(--border-light)}
.image-preview-bar.show{display:flex}
.image-preview-thumb{position:relative;width:56px;height:56px;border-radius:10px;overflow:hidden;flex-shrink:0}
.image-preview-thumb img{width:100%;height:100%;object-fit:cover}
.image-preview-remove{position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:var(--danger);color:#fff;border:2px solid var(--bg-secondary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;cursor:pointer}
.image-preview-label{font-size:13px;color:var(--text-secondary)}

.chat-input-bar{display:flex;align-items:center;gap:10px;padding:14px 20px;background:var(--bg-secondary);border-top:1px solid var(--border-light);flex-shrink:0}
.chat-input-bar input[type=text]{flex:1;padding:12px 18px;background:var(--bg-primary);border:1.5px solid var(--border-color);border-radius:var(--radius-full);font-size:15px;color:var(--text-primary);outline:none;transition:border-color .2s;font-family:inherit}
.chat-input-bar input[type=text]:focus{border-color:var(--primary)}
.attach-btn{width:42px;height:42px;background:var(--bg-primary);border:1.5px solid var(--border-color);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);font-size:17px;flex-shrink:0;transition:all .2s}
.attach-btn:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary)}
.send-btn{width:42px;height:42px;background:var(--primary-gradient);border:none;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:17px;flex-shrink:0;transition:all .2s}
.send-btn:hover{transform:scale(1.08)}
.send-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}

/* LIGHTBOX */
.img-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:5000;align-items:center;justify-content:center;padding:30px}
.img-lightbox.show{display:flex}
.img-lightbox img{max-width:90%;max-height:90%;border-radius:8px}
.img-lightbox-close{position:absolute;top:20px;right:24px;width:40px;height:40px;background:rgba(255,255,255,.15);border:none;border-radius:50%;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center}

</style>
</head>
<body>
<?php $sale_count = 0; include '../includes/navbar.php'; ?>

<div class="msg-page">

    <!-- SIDEBAR -->
    <div class="msg-sidebar" id="msgSidebar">
        <div class="sidebar-head">
            <h2><i class="fas fa-comment-dots"></i> Messages</h2>
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="clinicSearch" placeholder="Search clinics…" oninput="filterClinics(this.value)">
            </div>
        </div>
        <div class="clinic-list" id="clinicList">
            <?php foreach ($all_clinics as $c): ?>
            <div class="clinic-item <?php echo $selected_clinic_id === $c['id'] ? 'active' : ''; ?>"
                 data-id="<?php echo $c['id']; ?>"
                 data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                 data-city="<?php echo htmlspecialchars($c['city'], ENT_QUOTES); ?>"
                 data-image="<?php echo htmlspecialchars($c['image'] ?? '', ENT_QUOTES); ?>"
                 data-search="<?php echo strtolower(htmlspecialchars($c['name'] . ' ' . $c['city'])); ?>"
                 onclick="openChat(this)">
                <div class="clinic-avatar">
                    <?php if ($c['image']): ?>
                        <img src="<?php echo htmlspecialchars($c['image']); ?>" alt="" onerror="this.parentElement.innerHTML='<i class=\'fas fa-clinic-medical\'></i>'">
                    <?php else: ?>
                        <i class="fas fa-clinic-medical"></i>
                    <?php endif; ?>
                </div>
                <div class="clinic-info">
                    <div class="clinic-name"><?php echo htmlspecialchars($c['name']); ?></div>
                    <?php if ($c['city']): ?><div class="clinic-city"><i class="fas fa-map-marker-alt" style="font-size:10px;color:var(--primary)"></i> <?php echo htmlspecialchars($c['city']); ?></div><?php endif; ?>
                    <div class="clinic-preview"><?php echo $c['last_message'] ? htmlspecialchars($c['last_message']) : '<em style="opacity:.6">Start a conversation</em>'; ?></div>
                </div>
                <div class="clinic-meta">
                    <?php if ($c['last_time']): ?><div class="clinic-time"><?php echo $c['last_time']; ?></div><?php endif; ?>
                    <?php if ($c['unread'] > 0): ?><div class="unread-badge"><?php echo $c['unread']; ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CHAT PANEL -->
    <div class="msg-panel" id="msgPanel">
        <!-- Empty state -->
        <div class="chat-panel-empty" id="chatEmptyState">
            <div class="empty-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="#00B761" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <div class="empty-ping"></div>
            </div>
            <div class="empty-title">Your messages</div>
            <div class="empty-sub">Select a clinic from the list to start a conversation or ask a question.</div>
            <div class="empty-tips">
                <div class="empty-tip">
                    <div class="empty-tip-icon" style="background:#E3FCE9">
                        <i class="fas fa-hand-pointer" style="color:#00B761;font-size:13px"></i>
                    </div>
                    <span>Pick any clinic from the left to begin chatting</span>
                </div>
                <div class="empty-tip">
                    <div class="empty-tip-icon" style="background:#EFF6FF">
                        <i class="fas fa-search" style="color:#3B82F6;font-size:12px"></i>
                    </div>
                    <span>Use the search bar to find a specific clinic</span>
                </div>
                <div class="empty-tip">
                    <div class="empty-tip-icon" style="background:#FEF3C7">
                        <i class="fas fa-clock" style="color:#D97706;font-size:12px"></i>
                    </div>
                    <span>Replies usually arrive within a few hours</span>
                </div>
            </div>
        </div>

        <!-- Active chat (hidden until clinic selected) -->
        <div id="activeChatWrap" style="display:none;flex-direction:column;flex:1;overflow:hidden">
            <div class="chat-header-bar">
                <button class="back-mob" onclick="backToSidebar()"><i class="fas fa-arrow-left"></i></button>
                <div class="clinic-avatar chat-clinic-ava" id="chatHeaderAva"><i class="fas fa-clinic-medical"></i></div>
                <div class="chat-clinic-info-bar">
                    <div class="name" id="chatHeaderName">—</div>
                    <div class="city" id="chatHeaderCity"></div>
                </div>
            </div>
            <div class="chat-messages" id="chatMsgs"></div>

            <!-- Image preview before sending -->
            <div class="image-preview-bar" id="imagePreviewBar">
                <div class="image-preview-thumb">
                    <img id="imagePreviewThumb" src="" alt="">
                    <div class="image-preview-remove" onclick="removeSelectedImage()"><i class="fas fa-times"></i></div>
                </div>
                <div class="image-preview-label">Image ready to send</div>
            </div>

            <div class="chat-input-bar">
                <input type="file" id="imageInput" accept="image/*" style="display:none" onchange="handleImageSelect(event)">
                <button class="attach-btn" onclick="document.getElementById('imageInput').click()" title="Send an image">
                    <i class="fas fa-image"></i>
                </button>
                <input type="text" id="msgInput" placeholder="Type a message…" maxlength="500"
                       onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage()}">
                <button class="send-btn" id="sendBtn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen image viewer -->
<div class="img-lightbox" id="imgLightbox" onclick="closeLightbox(event)">
    <button class="img-lightbox-close" onclick="closeLightbox(event)"><i class="fas fa-times"></i></button>
    <img id="imgLightboxImg" src="" alt="">
</div>

<script>
let activeClinicId = null;
let lastMsgId = 0;
let pollTimer = null;
let selectedImageFile = null;

// ── FILTER ──
function filterClinics(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.clinic-item').forEach(el => {
        el.style.display = (!q || el.dataset.search.includes(q)) ? '' : 'none';
    });
}

// ── OPEN CHAT ──
function openChat(el) {
    // Mark active in sidebar
    document.querySelectorAll('.clinic-item').forEach(e => e.classList.remove('active'));
    el.classList.add('active');

    activeClinicId = parseInt(el.dataset.id);
    lastMsgId = 0;
    removeSelectedImage();

    // Update header
    const name = el.dataset.name;
    const city = el.dataset.city;
    const img  = el.dataset.image;

    document.getElementById('chatHeaderName').textContent = name;
    document.getElementById('chatHeaderCity').textContent = city ? '📍 ' + city : '';

    const ava = document.getElementById('chatHeaderAva');
    ava.innerHTML = img
        ? `<img src="${img}" alt="" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-clinic-medical\\'></i>'">`
        : '<i class="fas fa-clinic-medical"></i>';

    // Show chat panel, hide empty state
    document.getElementById('chatEmptyState').style.display = 'none';
    const wrap = document.getElementById('activeChatWrap');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.flex = '1';
    wrap.style.overflow = 'hidden';

    // Mobile: show panel, hide sidebar
    document.getElementById('msgPanel').classList.add('mob-show');
    document.getElementById('msgSidebar').classList.remove('mob-show');

    // Reset messages and fetch
    document.getElementById('chatMsgs').innerHTML =
        '<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:14px"><i class="fas fa-spinner fa-spin"></i></div>';
    document.getElementById('msgInput').focus();

    stopPolling();
    fetchMessages(true);
    startPolling();

    // Update URL without reload
    history.replaceState({}, '', `messages.php?clinic_id=${activeClinicId}`);
}

// ── MOBILE BACK ──
function backToSidebar() {
    stopPolling();
    activeClinicId = null;
    removeSelectedImage();
    document.getElementById('msgPanel').classList.remove('mob-show');
    document.getElementById('msgSidebar').classList.add('mob-show');
    history.replaceState({}, '', 'messages.php');
}

// ── IMAGE SELECT / PREVIEW ──
function handleImageSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        alert('Please choose an image file.');
        event.target.value = '';
        return;
    }
    // 5MB limit
    if (file.size > 5 * 1024 * 1024) {
        alert('Image is too large. Max size is 5MB.');
        event.target.value = '';
        return;
    }

    selectedImageFile = file;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('imagePreviewThumb').src = e.target.result;
        document.getElementById('imagePreviewBar').classList.add('show');
    };
    reader.readAsDataURL(file);
}

function removeSelectedImage() {
    selectedImageFile = null;
    document.getElementById('imageInput').value = '';
    document.getElementById('imagePreviewThumb').src = '';
    document.getElementById('imagePreviewBar').classList.remove('show');
}

// ── LIGHTBOX ──
function openLightbox(src) {
    document.getElementById('imgLightboxImg').src = src;
    document.getElementById('imgLightbox').classList.add('show');
}
function closeLightbox(event) {
    if (event) event.stopPropagation();
    document.getElementById('imgLightbox').classList.remove('show');
    document.getElementById('imgLightboxImg').src = '';
}

// ── FETCH MESSAGES ──
function fetchMessages(initial = false) {
    if (!activeClinicId) return;
    fetch(`chat_fetch.php?clinic_id=${activeClinicId}&last_id=${lastMsgId}`)
        .then(r => r.text())
        .then(text => {
            let d;
            try { d = JSON.parse(text); } catch(e) {
                if (initial) document.getElementById('chatMsgs').innerHTML =
                    '<div style="text-align:center;padding:20px;color:#EF4444;font-size:13px">⚠ ' + text.substring(0,200) + '</div>';
                return;
            }
            if (!d.success) return;
            const box = document.getElementById('chatMsgs');
            if (initial) box.innerHTML = '';
            if (d.messages.length === 0 && initial) {
                box.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-muted);font-size:14px">No messages yet.<br>Say hello! 👋</div>';
                return;
            }
            const atBottom = box.scrollHeight - box.scrollTop <= box.clientHeight + 50;
            d.messages.forEach(m => {
                const ph = box.querySelector('[data-placeholder]');
                if (ph) ph.remove();
                lastMsgId = Math.max(lastMsgId, m.id);
                const wrap = document.createElement('div');
                wrap.className = 'msg-wrap ' + m.sender_type;

                if (m.image) {
                    // Image message (with optional caption text)
                    const captionHtml = m.message ? `<div class="chat-img-caption">${m.message}</div>` : '';
                    wrap.innerHTML = `<div class="msg-bubble has-image">
                        <img class="chat-img" src="${m.image}" alt="Sent image" onclick="openLightbox('${m.image}')">
                        ${captionHtml}
                    </div><div class="msg-time">${m.time}</div>`;
                } else {
                    wrap.innerHTML = `<div class="msg-bubble">${m.message}</div><div class="msg-time">${m.time}</div>`;
                }

                box.appendChild(wrap);
                // Mark this clinic's unread badge in sidebar
                const sideItem = document.querySelector(`.clinic-item[data-id="${activeClinicId}"]`);
                if (sideItem) {
                    const badge = sideItem.querySelector('.unread-badge');
                    if (badge) badge.remove();
                }
            });
            if (atBottom || initial) box.scrollTop = box.scrollHeight;
        });
}

// ── SEND ──
function sendMessage() {
    const input = document.getElementById('msgInput');
    const msg   = input.value.trim();

    if (!activeClinicId) return;
    if (!msg && !selectedImageFile) return;

    const btn = document.getElementById('sendBtn');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('clinic_id', activeClinicId);
    fd.append('message',   msg);
    if (selectedImageFile) {
        fd.append('image', selectedImageFile);
    }

    input.value = '';
    const imageWasSelected = !!selectedImageFile;
    removeSelectedImage();

    fetch('chat_send.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success) {
                fetchMessages();
            } else {
                input.value = msg;
                if (imageWasSelected) alert(d.message || 'Failed to send image.');
            }
        })
        .catch(() => {
            btn.disabled = false;
            input.value = msg;
        });
}

function startPolling() { stopPolling(); pollTimer = setInterval(() => fetchMessages(), 2500); }
function stopPolling()  { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

// ── INIT ── auto-open if clinic_id in URL
document.addEventListener('DOMContentLoaded', () => {
    const preselect = <?php echo $selected_clinic_id ?: 'null'; ?>;
    if (preselect) {
        const el = document.querySelector(`.clinic-item[data-id="${preselect}"]`);
        if (el) openChat(el);
    }
    // Apply saved theme
    const saved = localStorage.getItem('theme') || 'light';
    if (saved === 'dark') document.documentElement.classList.add('theme-dark');
});
</script>
</body>
</html>