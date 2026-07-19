<?php
/**
 * Eyecore — Shared Navbar Include
 *
 * Required variables before including:
 *   $user, $user_data, $unread_count, $recent_notifications,
 *   $total_points, $total_bookings, $pending, $sale_count
 *
 * Optional:
 *   $active_nav — 'home'|'nearby'|'favorites'|'bookings'|'reservations'|'discover'
 *                 Auto-detected from filename if not set.
 */

if (!isset($active_nav)) {
    $current_file = basename($_SERVER['PHP_SELF']);
    $active_nav = match(true) {
        in_array($current_file, ['dashboard.php','index.php'])                          => 'home',
        $current_file === 'favorites.php'                                               => 'favorites',
        $current_file === 'my-appointments.php'                                         => 'bookings',
        $current_file === 'my-reservations.php'                                         => 'reservations',
        in_array($current_file, ['sale-products.php','clinics-map.php',
                                  'product-details.php','nearby.php'])                 => 'discover',
        default => ''
    };
}

// FIXED sale count — same logic as dashboard.php
// Always recompute — never trust $sale_count from calling page
// (calling pages may use wrong query without sale_start or clinic status check)
$sale_count_q = mysqli_query($conn,
    "SELECT COUNT(*) as total
     FROM products p
     JOIN clinics c ON p.clinic_id = c.id
     WHERE p.is_on_sale  = 1
       AND p.sale_start <= CURDATE()
       AND p.sale_end   >= CURDATE()
       AND c.status      = 'Active'"
);
$sale_count = mysqli_fetch_assoc($sale_count_q)['total'] ?? 0;

// Reservation badge count
if (!isset($reservation_count)) {
    $res_q = mysqli_query($conn,
        "SELECT COUNT(*) as total FROM reservations
         WHERE user_id = {$user_id}
           AND status IN ('pending','confirmed')"
    );
    $reservation_count = mysqli_fetch_assoc($res_q)['total'] ?? 0;
}

// Chat unread count — messages from clinic that user hasn't read
$chat_unread_q = mysqli_query($conn,
    "SELECT COUNT(*) as total FROM chats
     WHERE user_id = {$user_id} AND sender_type = 'clinic' AND is_read = 0"
);
$chat_unread = mysqli_fetch_assoc($chat_unread_q)['total'] ?? 0;

if (!function_exists('navActive')) {
    function navActive(string $key, string $active): string {
        return $key === $active ? ' active' : '';
    }
}

if (!function_exists('navAvatarHtml')) {
    function navAvatarHtml($user, $user_data, string $size = 'md'): string {
        $fs = $size === 'lg' ? '18px' : '15px';
        $av = $user_data['avatar'] ?? null;
        if ($av && file_exists("../assets/images/profiles/$av")) {
            return "<img src='../assets/images/profiles/$av' alt='Profile' style='width:100%;height:100%;object-fit:cover'>";
        }
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $letter = strtoupper(substr($name ?: ($_SESSION['user_name'] ?? 'U'), 0, 1));
        return "<span style='font-weight:800;font-size:{$fs};'>{$letter}</span>";
    }
}
?>
<style>
/* =============================================
   EYECORE NAVBAR — matches dashboard.php design
   ============================================= */

/* CSS variables */
:root {
    --primary:#00B761;--primary-dark:#00874A;--primary-light:#E3FCE9;
    --primary-gradient:linear-gradient(135deg,#00B761,#00A86B);
    --bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;--card-bg:#FFFFFF;
    --text-primary:#111827;--text-secondary:#6B7280;--text-muted:#9CA3AF;
    --border-color:#E5E7EB;--border-light:#F3F4F6;
    --shadow-sm:0 1px 3px rgba(0,0,0,0.06);--shadow-md:0 4px 16px rgba(0,0,0,0.08);
    --shadow-lg:0 12px 40px rgba(0,0,0,0.10);--shadow-hover:0 20px 40px -12px rgba(0,183,97,0.25);
    --radius-sm:10px;--radius-md:14px;--radius-lg:20px;--radius-xl:28px;--radius-full:999px;
    --danger:#EF4444;--warning:#F59E0B;--success:#00B761;--info:#3B82F6;
    --pending-bg:#FEF3C7;--pending-text:#92400E;
    --confirmed-bg:#D1FAE5;--confirmed-text:#065F46;
    --paid-bg:#DBEAFE;--paid-text:#1E40AF;
    --completed-bg:#EDE9FE;--completed-text:#5B21B6;
    --cancelled-bg:#FEE2E2;--cancelled-text:#991B1B;
}
.theme-dark {
    --primary:#00E676;--primary-dark:#00C853;--primary-light:#0D2818;
    --bg-primary:#0D0D0D;--bg-secondary:#161616;--card-bg:#1E1E1E;
    --text-primary:#F9FAFB;--text-secondary:#9CA3AF;--text-muted:#6B7280;
    --border-color:#2A2A2A;--border-light:#222222;
    --shadow-sm:0 1px 3px rgba(0,0,0,0.3);--shadow-md:0 4px 16px rgba(0,0,0,0.4);--shadow-lg:0 12px 40px rgba(0,0,0,0.5);
    --pending-bg:#3d2e00;--pending-text:#fbbf24;
    --confirmed-bg:#052e16;--confirmed-text:#6ee7b7;
    --paid-bg:#1e3a5f;--paid-text:#93c5fd;
    --completed-bg:#2e1065;--completed-text:#c4b5fd;
    --cancelled-bg:#450a0a;--cancelled-text:#fca5a5;
}

/* ICON FIX */
i.fas,i.far,i.fab,i.fal {
    -webkit-text-fill-color:currentColor !important;
    background-clip:unset !important;
    -webkit-background-clip:unset !important;
    background-image:none !important;
}

/* ---- DESKTOP NAVBAR ---- */
.navbar {
    display:flex;justify-content:space-between;align-items:center;
    background:var(--bg-secondary);padding:0 40px;height:64px;
    box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:100;
    border-bottom:1px solid var(--border-light);
}
@media(max-width:1024px){.navbar{padding:0 24px}}
@media(max-width:768px){.navbar{display:none}}

.nav-left{display:flex;align-items:center;gap:32px}
.logo{display:flex;align-items:center;gap:8px;font-size:20px;font-weight:800;color:var(--primary);text-decoration:none;letter-spacing:-.5px}
.logo i{font-size:24px;color:var(--primary)}
.nav-links{display:flex;gap:4px}

.nav-link{display:flex;align-items:center;gap:7px;padding:8px 14px;color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-full);transition:all .15s;font-weight:500;font-size:13px;position:relative;background:none;border:none;cursor:pointer}
.nav-link i{font-size:16px}
.nav-link:hover{color:var(--primary);background:var(--primary-light)}
.nav-link.active{background:var(--primary-light);color:var(--primary);font-weight:700}

/* Reservations — purple */


/* Badges */
.nav-link .badge,.icon-btn .badge,.mobile-nav-item .badge {
    background:var(--danger);color:#fff;font-size:9px;border-radius:var(--radius-full);
    min-width:17px;height:17px;display:flex;align-items:center;justify-content:center;padding:0 4px;font-weight:700;
}
/* nav-link badge sits inline beside the text — no overlap */
/* badge sits at top-right corner of the pill — outside text bounds */
.nav-link .badge{position:absolute;top:-5px;right:-2px;min-width:15px;height:15px;font-size:8px;padding:0 3px}

/* Discover dropdown */
.nav-dropdown{position:relative}
.dropdown-trigger{display:flex;align-items:center;gap:6px}
.dropdown-trigger i.dd-chev{font-size:11px;transition:transform .2s}
.dropdown-trigger.active i.dd-chev{transform:rotate(180deg)}

.dropdown-menu{position:absolute;top:100%;left:0;min-width:210px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:8px;margin-top:10px;display:none;z-index:100;border:1px solid var(--border-light)}
.dropdown-menu.show{display:block;animation:navFadeIn .2s ease}
@keyframes navFadeIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.dropdown-menu a{display:flex;align-items:center;gap:10px;padding:10px 12px;color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-md);transition:all .15s;font-size:13px;position:relative}
.dropdown-menu a:hover{background:var(--bg-primary);color:var(--primary)}
.dropdown-badge{background:var(--danger);color:#fff;font-size:10px;padding:2px 7px;border-radius:var(--radius-full);font-weight:700}
.dd-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.dd-text{font-size:13px;font-weight:500;color:var(--text-primary);display:flex;align-items:center;gap:6px}

/* Nav right */
.nav-right{display:flex;align-items:center;gap:12px}
.search-container{position:relative;width:260px}
.search-container i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:15px}
.search-container input{width:100%;padding:10px 16px 10px 42px;background:var(--bg-primary);border:1px solid var(--border-color);border-radius:var(--radius-full);font-size:13px;color:var(--text-primary);transition:all .2s;font-family:inherit;outline:none}
.search-container input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light)}

.user-stats-badge{display:flex;align-items:center;gap:14px;background:var(--bg-primary);padding:7px 16px;border-radius:var(--radius-full);border:1px solid var(--border-light)}
.stat-badge{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600}

.icon-btn{width:40px;height:40px;background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;color:var(--text-secondary);font-size:16px;position:relative}
.icon-btn:hover{background:var(--primary);color:white;border-color:var(--primary)}
.icon-btn .badge{position:absolute;top:-3px;right:-3px}

/* Notifications */
.notification-dropdown{position:relative}
.notification-menu{position:absolute;top:100%;right:0;width:320px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);display:none;z-index:1000;margin-top:10px;border:1px solid var(--border-light);overflow:hidden}
.notification-menu.show{display:block}
.notification-header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--border-light)}
.notification-header h3{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.notification-header h3 i{color:var(--primary)}
.notification-header button{background:none;border:none;color:var(--primary);cursor:pointer;font-size:12px}
.notification-list{max-height:360px;overflow-y:auto}
.notification-item{display:flex;padding:14px 18px;text-decoration:none;border-bottom:1px solid var(--border-light);transition:all .15s;position:relative}
.notification-item:hover{background:var(--bg-primary)}
.notification-item.unread{background:var(--primary-light)}
.notification-icon{width:40px;height:40px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;margin-right:12px;flex-shrink:0;background:var(--primary-light);color:var(--primary)}
.notification-title{font-size:13px;font-weight:600;margin-bottom:3px;color:var(--text-primary)}
.notification-time{font-size:11px;color:var(--text-muted)}
.notification-dot{position:absolute;top:18px;right:18px;width:7px;height:7px;background:var(--primary);border-radius:50%}
.notification-content{flex:1}
.notification-message{font-size:12px;color:var(--text-secondary);margin-bottom:3px}
.notification-empty{text-align:center;padding:40px 20px;color:var(--text-muted)}
.notification-empty i{font-size:40px;margin-bottom:10px;display:block;opacity:.4}
.notification-footer{padding:12px;text-align:center;border-top:1px solid var(--border-light)}
.notification-footer a{color:var(--primary);text-decoration:none;font-size:12px;font-weight:600}

/* Profile */
.profile-dropdown{position:relative}
.profile-trigger{display:flex;align-items:center;gap:8px;background:var(--bg-primary);padding:4px 4px 4px 14px;border-radius:var(--radius-full);cursor:pointer;border:1px solid var(--border-light);transition:all .15s}
.profile-trigger:hover{border-color:var(--primary)}
.profile-info{text-align:right}
.profile-name{font-size:12px;font-weight:700;color:var(--text-primary)}
.profile-points{font-size:10px;color:var(--primary);font-weight:600}
.profile-avatar{width:34px;height:34px;border-radius:var(--radius-full);background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;overflow:hidden;flex-shrink:0}
.profile-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.profile-menu{position:absolute;top:100%;right:0;width:200px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);display:none;z-index:1000;margin-top:10px;border:1px solid var(--border-light);overflow:hidden}
.profile-menu.show{display:block}
.profile-menu a{display:flex;align-items:center;gap:10px;padding:12px 18px;color:var(--text-secondary);text-decoration:none;transition:all .15s;border-bottom:1px solid var(--border-light);font-size:13px}
.profile-menu a:last-child{border-bottom:none}
.profile-menu a:hover{background:var(--primary-light);color:var(--primary)}
.profile-menu a i{width:18px;color:var(--primary)}

/* ---- MOBILE TOP ---- */
.mobile-top{display:none;position:sticky;top:0;z-index:100;background:var(--bg-secondary);padding:12px 20px;border-bottom:1px solid var(--border-light)}
@media(max-width:768px){.mobile-top{display:flex;justify-content:space-between;align-items:center}}
.mobile-logo{display:flex;align-items:center;gap:7px;font-size:18px;font-weight:800;color:var(--primary);letter-spacing:-.4px}
.mobile-actions{display:flex;align-items:center;gap:10px}

/* Mobile notification dropdown */
.mobile-notification-dropdown{position:relative}
.mobile-notification-menu{position:fixed;top:65px;left:10px;right:10px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);display:none;z-index:2000;border:1px solid var(--border-light);overflow:hidden;max-height:75vh;overflow-y:auto}
.mobile-notification-menu.show{display:block}

/* ---- MOBILE BOTTOM NAV ---- */
.mobile-bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--bg-secondary);border-top:1px solid var(--border-light);z-index:100}
@media(max-width:768px){.mobile-bottom-nav{display:block}}
.mobile-nav-items{display:flex;padding:6px 0 2px}
.mobile-nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;padding:6px 4px;position:relative;cursor:pointer;border:none;background:none}
.mobile-nav-item i{font-size:20px;color:var(--text-muted)}
.mobile-nav-item span{font-size:10px;color:var(--text-muted);font-weight:500}
.mobile-nav-item.active i,.mobile-nav-item.active span{color:var(--primary)}
.mobile-nav-item .badge{position:absolute;top:4px;right:18%;background:var(--danger);color:#fff;font-size:9px;padding:1px 4px;border-radius:var(--radius-full);min-width:15px}


/* ---- MOBILE SIDE MENU ---- */
.mobile-menu-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:998}
.mobile-menu-overlay.show{display:block}
.mobile-menu{position:fixed;top:0;right:-300px;width:280px;height:100vh;background:var(--bg-secondary);box-shadow:var(--shadow-lg);z-index:999;overflow-y:auto;transition:right .3s ease}
.mobile-menu.open{right:0}
.mobile-menu-header{display:flex;justify-content:space-between;align-items:center;padding:20px;border-bottom:1px solid var(--border-light)}
.mobile-user{display:flex;align-items:center;gap:12px}
.mobile-avatar{width:46px;height:46px;border-radius:var(--radius-full);background:var(--primary-gradient);overflow:hidden;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:800}
.mobile-avatar img{width:100%;height:100%;object-fit:cover}
.mobile-user h4{font-size:14px;font-weight:700}
.mobile-user p{font-size:11px;color:var(--text-muted);margin-top:2px}
.mobile-menu-header button{background:none;border:none;color:var(--text-secondary);font-size:22px;cursor:pointer}
.mobile-menu-items{padding:12px;display:flex;flex-direction:column;gap:3px}
.mobile-menu-items a{display:flex;align-items:center;gap:12px;padding:12px 14px;color:var(--text-primary);text-decoration:none;border-radius:var(--radius-md);font-size:14px;font-weight:500;transition:all .15s}
.mobile-menu-items a:hover{background:var(--primary-light);color:var(--primary)}
.mobile-menu-items a i{width:20px;color:var(--primary)}
.mobile-menu-items .logout-link{color:var(--danger);margin-top:12px;border-top:1px solid var(--border-light);padding-top:14px}
.mobile-menu-items .logout-link i{color:var(--danger)}

.chat-bubble-btn{position:fixed;bottom:28px;right:24px;width:52px;height:52px;background:var(--primary-gradient);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:10001;border:none;box-shadow:0 4px 16px rgba(0,183,97,.35);transition:all .25s;pointer-events:all}
.chat-bubble-btn:hover{transform:scale(1.08);box-shadow:0 8px 24px rgba(0,183,97,.4)}
.chat-bubble-btn i{font-size:20px;color:#fff}
.chat-bubble-btn .badge{position:absolute;top:-3px;right:-3px;background:var(--danger);color:#fff;font-size:9px;border-radius:var(--radius-full);min-width:17px;height:17px;display:flex;align-items:center;justify-content:center;padding:0 4px;font-weight:700}
@media(max-width:768px){.chat-bubble-btn{bottom:80px;right:16px;width:46px;height:46px}}

.chat-window{position:fixed;bottom:90px;right:24px;width:300px;height:420px;background:var(--bg-secondary);border-radius:var(--radius-lg);box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:10000;display:none;flex-direction:column;overflow:hidden;border:1px solid var(--border-light)}
.chat-window.open{display:flex}
@media(max-width:768px){.chat-window{right:0;bottom:70px;width:100%;height:60vh;border-radius:var(--radius-lg) var(--radius-lg) 0 0}}

.chat-list-screen,.chat-msg-screen{display:flex;flex-direction:column;height:100%}
.chat-header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--primary-gradient);flex-shrink:0}
.chat-header h3{font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px}
.chat-header h3 button{background:none;border:none;color:rgba(255,255,255,.85);cursor:pointer;font-size:14px;padding:2px;margin-right:2px}
.chat-header-actions{display:flex;align-items:center;gap:4px}
.chat-header-actions button{background:none;border:none;color:rgba(255,255,255,.85);cursor:pointer;font-size:15px;padding:4px;transition:color .15s}
.chat-header-actions button:hover{color:#fff}
.chat-expand-btn{display:flex;align-items:center;justify-content:center;width:26px;height:26px;background:rgba(255,255,255,.2);border-radius:6px;color:#fff;text-decoration:none;font-size:12px;transition:background .15s}
.chat-expand-btn:hover{background:rgba(255,255,255,.35)}

.chat-clinic-list{flex:1;overflow-y:auto;padding:6px}
.chat-clinic-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:var(--radius-md);cursor:pointer;transition:background .15s}
.chat-clinic-item:hover{background:var(--bg-primary)}
.chat-clinic-avatar{width:36px;height:36px;border-radius:50%;flex-shrink:0;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:14px;overflow:hidden}
.chat-clinic-avatar img{width:100%;height:100%;object-fit:cover}
.chat-clinic-info{flex:1;min-width:0}
.chat-clinic-name{font-size:12px;font-weight:600;color:var(--text-primary);margin-bottom:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-clinic-preview{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-clinic-meta{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0}
.chat-clinic-time{font-size:10px;color:var(--text-muted)}
.chat-unread-badge{background:var(--primary);color:#fff;font-size:9px;padding:1px 5px;border-radius:999px;font-weight:700}
.chat-empty{text-align:center;padding:30px 16px;color:var(--text-muted)}
.chat-empty i{font-size:28px;opacity:.3;display:block;margin-bottom:8px}
.chat-empty p{font-size:12px;line-height:1.5}
.chat-empty a{color:var(--primary);text-decoration:none;font-weight:600}

.chat-messages{flex:1;overflow-y:auto;padding:12px 10px;display:flex;flex-direction:column;gap:7px}
.chat-msg-wrap{display:flex;flex-direction:column}
.chat-msg-wrap.user{align-items:flex-end}
.chat-msg-wrap.clinic{align-items:flex-start}
.chat-msg-bubble{max-width:80%;padding:8px 12px;border-radius:14px;font-size:13px;line-height:1.45;word-break:break-word}
.chat-msg-wrap.user .chat-msg-bubble{background:var(--primary-gradient);color:#fff;border-radius:14px 14px 4px 14px}
.chat-msg-wrap.clinic .chat-msg-bubble{background:var(--bg-primary);color:var(--text-primary);border:1px solid var(--border-light);border-radius:14px 14px 14px 4px}
.chat-msg-time{font-size:10px;color:var(--text-muted);margin-top:2px;padding:0 3px}
.chat-load-msg{text-align:center;padding:20px;color:var(--text-muted);font-size:12px}

.chat-input-area{display:flex;align-items:center;gap:7px;padding:9px 10px;border-top:1px solid var(--border-light);flex-shrink:0;background:var(--bg-secondary)}
.chat-input{flex:1;padding:8px 12px;border:1.5px solid var(--border-color);border-radius:var(--radius-full);font-size:13px;background:var(--bg-primary);color:var(--text-primary);outline:none;font-family:inherit;transition:border-color .2s}
.chat-input:focus{border-color:var(--primary)}
.chat-send-btn{width:34px;height:34px;background:var(--primary-gradient);border:none;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:13px;flex-shrink:0;transition:all .2s}
.chat-send-btn:hover{transform:scale(1.08)}
.chat-send-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
</style>

<!-- ===== DESKTOP NAVBAR ===== -->
<nav class="navbar">
    <div class="nav-left">
        <a href="dashboard.php" class="logo"><i class="fas fa-eye"></i><span>eyecore</span></a>
        <div class="nav-links">
            <a href="dashboard.php"       class="nav-link<?php echo navActive('home',$active_nav);?>"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="favorites.php"       class="nav-link<?php echo navActive('favorites',$active_nav);?>"><i class="fas fa-heart"></i><span>Favorites</span></a>
            <a href="my-appointments.php" class="nav-link<?php echo navActive('bookings',$active_nav);?>">
                <i class="fas fa-calendar-check"></i><span>Bookings</span>
                <?php if($pending>0):?><span class="badge"><?php echo $pending;?></span><?php endif;?>
            </a>
            <a href="my-reservations.php" class="nav-link<?php echo navActive('reservations',$active_nav);?>">
                <i class="fas fa-bookmark"></i><span>Reservations</span>
                <?php if($reservation_count>0):?><span class="badge"><?php echo $reservation_count;?></span><?php endif;?>
            </a>
            <div class="nav-dropdown">
                <button class="nav-link dropdown-trigger<?php echo navActive('discover',$active_nav);?>" onclick="navToggleDiscover(this)">
                    Discover <i class="fas fa-chevron-down dd-chev"></i>
                </button>
                <div class="dropdown-menu" id="navDiscoverDD">
                    <a href="sale-products.php">
                        <div class="dd-icon" style="background:#FFF0F0;color:var(--danger)"><i class="fas fa-tags"></i></div>
                        <div class="dd-text"><b>Hot Sales</b><span>Active promos</span></div>
                        <?php if($sale_count>0):?><span class="dropdown-badge"><?php echo $sale_count;?></span><?php endif;?>
                    </a>
                    <a href="nearby.php">
                        <div class="dd-icon" style="background:#F0FDF4;color:var(--primary)"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="dd-text"><b>Nearby Clinics</b><span>Find clinics near you</span></div>
                    </a>
                    <a href="clinics-map.php">
                        <div class="dd-icon" style="background:#EFF6FF;color:var(--info)"><i class="fas fa-map-marked-alt"></i></div>
                        <div class="dd-text"><b>Explore Map</b><span>Browse all clinics</span></div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="nav-right">
        <div class="search-container">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search clinics..." id="navDesktopSearch">
        </div>
        <div class="user-stats-badge">
            <div class="stat-badge" data-tooltip="Points earned"><i class="fas fa-star" style="color:#F59E0B"></i><span><?php echo $total_points;?></span></div>
            <div class="stat-badge" data-tooltip="Total bookings"><i class="fas fa-calendar-check" style="color:var(--primary)"></i><span><?php echo $total_bookings;?></span></div>
        </div>
        <button class="icon-btn" onclick="navToggleTheme()" id="navThemeBtn" data-tooltip="Toggle theme">
            <i class="fas fa-moon" id="navThemeIcon"></i>
        </button>
        <div class="notification-dropdown">
            <button class="icon-btn" onclick="navToggleNotif()" id="navNotifBtn">
                <i class="fas fa-bell"></i>
                <?php if($unread_count>0):?><span class="badge" id="navNotifBadge"><?php echo $unread_count;?></span><?php endif;?>
            </button>
            <div class="notification-menu" id="navNotifMenu">
                <div class="notification-header">
                    <h3><i class="fas fa-bell"></i> Notifications</h3>
                    <?php if($unread_count>0):?><button onclick="navMarkAllRead()"><i class="fas fa-check-double"></i> Mark all read</button><?php endif;?>
                </div>
                <div class="notification-list">
                    <?php if(mysqli_num_rows($recent_notifications)>0):
                        mysqli_data_seek($recent_notifications,0);
                        while($n=mysqli_fetch_assoc($recent_notifications)):?>
                    <a href="<?php echo $n['link']?:'#';?>" class="notification-item <?php echo $n['is_read']?'':'unread';?>" onclick="navHandleNotif(event,this,<?php echo $n['id'];?>)">
                        <div class="notification-icon"><i class="fas <?php echo $n['type']==='appointment'?'fa-calendar-check':($n['type']==='favorite'?'fa-heart':($n['type']==='promo'?'fa-tags':'fa-bell'));?>"></i></div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($n['title']);?></div>
                            <?php if(!empty($n['message'])):?><div class="notification-message"><?php echo htmlspecialchars($n['message']);?></div><?php endif;?>
                            <div class="notification-time"><?php echo timeAgo($n['created_at']);?></div>
                        </div>
                        <?php if(!$n['is_read']):?><div class="notification-dot"></div><?php endif;?>
                    </a>
                    <?php endwhile; else:?>
                    <div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications</p></div>
                    <?php endif;?>
                </div>
                <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
            </div>
        </div>
        <!-- Chat accessible via floating bubble (lower-right) -->
        <div class="profile-dropdown">
            <div class="profile-trigger" onclick="navToggleProfile()">
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars(trim(($user['first_name']??'').' '.($user['last_name']??'')));?></div>
                    <div class="profile-points"><?php echo $total_points;?> pts</div>
                </div>
                <div class="profile-avatar"><?php echo navAvatarHtml($user,$user_data);?></div>
            </div>
            <div class="profile-menu" id="navProfileMenu">
                <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
                <a href="../auth/user_logout.php" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<!-- ===== MOBILE TOP ===== -->
<div class="mobile-top">
    <div class="mobile-logo"><i class="fas fa-eye"></i> eyecore</div>
    <div class="mobile-actions">
        <button class="icon-btn" onclick="navToggleTheme()" style="width:38px;height:38px">
            <i class="fas fa-moon" id="navThemeIconMob"></i>
        </button>
        <div class="mobile-notification-dropdown">
            <button class="icon-btn" onclick="navToggleMobNotif()" style="width:38px;height:38px">
                <i class="fas fa-bell"></i>
                <?php if($unread_count>0):?><span class="badge"><?php echo $unread_count;?></span><?php endif;?>
            </button>
            <div class="mobile-notification-menu" id="navMobNotifMenu">
                <div class="notification-header">
                    <h3><i class="fas fa-bell"></i> Notifications</h3>
                    <?php if($unread_count>0):?><button onclick="navMarkAllRead()"><i class="fas fa-check-double"></i> Mark all read</button><?php endif;?>
                </div>
                <div class="notification-list">
                    <?php mysqli_data_seek($recent_notifications,0);
                    if(mysqli_num_rows($recent_notifications)>0):
                        while($n=mysqli_fetch_assoc($recent_notifications)):?>
                    <a href="<?php echo $n['link']?:'#';?>" class="notification-item <?php echo $n['is_read']?'':'unread';?>">
                        <div class="notification-icon"><i class="fas fa-bell"></i></div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($n['title']);?></div>
                            <div class="notification-time"><?php echo timeAgo($n['created_at']);?></div>
                        </div>
                    </a>
                    <?php endwhile; else:?>
                    <div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications</p></div>
                    <?php endif;?>
                </div>
                <div class="notification-footer"><a href="notifications.php">View all notifications</a></div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MOBILE BOTTOM NAV ===== -->
<div class="mobile-bottom-nav">
    <div class="mobile-nav-items">
        <a href="dashboard.php"       class="mobile-nav-item<?php echo navActive('home',$active_nav);?>"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="favorites.php"       class="mobile-nav-item<?php echo navActive('favorites',$active_nav);?>"><i class="fas fa-heart"></i><span>Fav</span></a>
        <a href="my-appointments.php" class="mobile-nav-item<?php echo navActive('bookings',$active_nav);?>">
            <i class="fas fa-calendar-check"></i><span>Books</span>
            <?php if($pending>0):?><span class="badge"><?php echo $pending;?></span><?php endif;?>
        </a>
        <a href="my-reservations.php" class="mobile-nav-item<?php echo navActive('reservations',$active_nav);?>">
            <i class="fas fa-bookmark"></i><span>Reserved</span>
            <?php if($reservation_count>0):?><span class="badge"><?php echo $reservation_count;?></span><?php endif;?>
        </a>
        <a href="#" class="mobile-nav-item" onclick="navToggleMobMenu()"><i class="fas fa-bars"></i><span>Menu</span></a>
    </div>
</div>

<!-- ===== MOBILE MENU ===== -->
<div class="mobile-menu-overlay" id="navMobOverlay" onclick="navCloseMobMenu()"></div>
<div class="mobile-menu" id="navMobMenu">
    <div class="mobile-menu-header">
        <div class="mobile-user">
            <div class="mobile-avatar"><?php echo navAvatarHtml($user,$user_data,'lg');?></div>
            <div>
                <h4><?php echo htmlspecialchars(trim(($user['first_name']??'').' '.($user['last_name']??'')));?></h4>
                <p><?php echo $total_points;?> pts · <?php echo $total_bookings;?> bookings</p>
            </div>
        </div>
        <button onclick="navCloseMobMenu()"><i class="fas fa-times"></i></button>
    </div>
    <div class="mobile-menu-items">
        <a href="profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
        <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
        <a href="sale-products.php"><i class="fas fa-tags" style="color:var(--danger)"></i> Hot Sales</a>
        <a href="nearby.php"><i class="fas fa-map-marker-alt"></i> Nearby</a>
        <a href="clinics-map.php"><i class="fas fa-map-marked-alt"></i> Explore Map</a>
        <a href="../auth/user_logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- ===== FLOATING CHAT BUBBLE ===== -->
<button class="chat-bubble-btn" onclick="navToggleChat()" id="chatBubbleBtn">
    <i class="fas fa-comment-dots"></i>
    <?php if($chat_unread>0):?><span class="badge" id="chatBubbleBadge"><?php echo $chat_unread;?></span><?php endif;?>
</button>

<!-- ===== FLOATING CHAT WINDOW ===== -->
<div class="chat-window" id="chatWindow">
    <div class="chat-list-screen" id="chatListScreen">
        <div class="chat-header">
            <h3><i class="fas fa-comment-dots"></i> Messages</h3>
            <div class="chat-header-actions">
                <a href="messages.php" class="chat-expand-btn" title="Open full chat">
                    <i class="fas fa-expand-alt"></i>
                </a>
                <button onclick="navCloseChat()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="chat-clinic-list" id="chatClinicList">
            <div class="chat-load-msg"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
    <div class="chat-msg-screen" id="chatMsgScreen" style="display:none">
        <div class="chat-header">
            <h3>
                <button onclick="navBackToList()"><i class="fas fa-arrow-left"></i></button>
                <span id="chatClinicName">Clinic</span>
            </h3>
            <div class="chat-header-actions">
                <a href="messages.php" class="chat-expand-btn" id="chatExpandBtn" title="Open full chat">
                    <i class="fas fa-expand-alt"></i>
                </a>
                <button onclick="navCloseChat()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="chat-messages" id="chatMessages"></div>
        <div class="chat-input-area">
            <input type="text" class="chat-input" id="chatInput" placeholder="Type a message…" maxlength="500"
                   onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();navSendMessage()}">
            <button class="chat-send-btn" id="chatSendBtn" onclick="navSendMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>



<!-- ===== NAVBAR JS ===== -->
<script>
function navToggleTheme(){
    const dark=document.documentElement.classList.toggle('theme-dark');
    document.querySelectorAll('#navThemeIcon,#navThemeIconMob').forEach(i=>i.className='fas fa-'+(dark?'sun':'moon'));
    fetch('user_settings.php?toggle_theme=1&theme='+(dark?'dark':'light'));
}
function navToggleDiscover(btn){
    const m=document.getElementById('navDiscoverDD');
    const o=m.classList.toggle('show');
    btn.classList.toggle('active',o);
}
function navToggleNotif()   {document.getElementById('navNotifMenu').classList.toggle('show')}
function navToggleMobNotif(){document.getElementById('navMobNotifMenu').classList.toggle('show')}
function navToggleProfile() {document.getElementById('navProfileMenu').classList.toggle('show')}
function navToggleMobMenu() {document.getElementById('navMobMenu').classList.toggle('open');document.getElementById('navMobOverlay').classList.toggle('show')}
function navCloseMobMenu()  {document.getElementById('navMobMenu').classList.remove('open');document.getElementById('navMobOverlay').classList.remove('show')}

function navHandleNotif(e,el,id){
    if(!el.getAttribute('href')||el.getAttribute('href')==='#')e.preventDefault();
    fetch('mark_notification_read.php?id='+id);
    el.classList.remove('unread');
    const dot=el.querySelector('.notification-dot');if(dot)dot.remove();
    navUpdateNotifBadge();
}
function navMarkAllRead(){
    fetch('mark_all_notifications_read.php').then(()=>{
        document.querySelectorAll('.notification-item.unread').forEach(i=>{
            i.classList.remove('unread');const d=i.querySelector('.notification-dot');if(d)d.remove();
        });
        const b=document.getElementById('navNotifBadge');if(b)b.remove();
    });
}
function navUpdateNotifBadge(){
    const count=document.querySelectorAll('#navNotifMenu .notification-item.unread').length;
    const badge=document.getElementById('navNotifBadge');
    if(count===0&&badge)badge.remove();
}
document.addEventListener('click',e=>{
    if(!e.target.closest('.notification-dropdown')&&!e.target.closest('.mobile-notification-dropdown')){
        document.getElementById('navNotifMenu')?.classList.remove('show');
        document.getElementById('navMobNotifMenu')?.classList.remove('show');
    }
    if(!e.target.closest('.profile-dropdown'))document.getElementById('navProfileMenu')?.classList.remove('show');
    if(!e.target.closest('.nav-dropdown')){
        document.getElementById('navDiscoverDD')?.classList.remove('show');
        document.querySelector('.dropdown-trigger')?.classList.remove('active');
    }
});
(function(){
    const saved=localStorage.getItem('theme')||'light';
    if(saved==='dark'){
        document.documentElement.classList.add('theme-dark');
        document.querySelectorAll('#navThemeIcon,#navThemeIconMob').forEach(i=>i.className='fas fa-sun');
    }
})();

// ===== CHAT =====
let chatOpen = false;
let chatClinicId = null;
let chatLastId = 0;
let chatPollTimer = null;

function navToggleChat() {
    chatOpen = !chatOpen;
    const win = document.getElementById('chatWindow');
    win.classList.toggle('open', chatOpen);
    if (chatOpen) {
        navLoadClinicList();
        document.getElementById('navNotifMenu')?.classList.remove('show');
        document.getElementById('navProfileMenu')?.classList.remove('show');
    } else {
        navStopPolling();
    }
}
function navCloseChat() {
    chatOpen = false;
    document.getElementById('chatWindow').classList.remove('open');
    navStopPolling();
}
function navBackToList() {
    navStopPolling();
    chatClinicId = null;
    chatLastId = 0;
    const msgs = document.getElementById('chatMsgScreen');
    const list = document.getElementById('chatListScreen');
    msgs.style.display = 'none';
    list.style.cssText = 'display:flex!important;flex-direction:column!important;height:100%!important;flex:1!important';
    navLoadClinicList();
}

function navLoadClinicList() {
    document.getElementById('chatListScreen').style.cssText = 'display:flex!important;flex-direction:column!important;height:100%!important;flex:1!important';
    document.getElementById('chatMsgScreen').style.display = 'none';
    document.getElementById('chatClinicList').innerHTML = '<div class="chat-load-msg"><i class="fas fa-spinner fa-spin"></i></div>';
    const base = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    fetch(base + 'chat_clinics.php')
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(text => {
            let d;
            try { d = JSON.parse(text); } catch(e) { throw new Error('PHP error: ' + text.substring(0,200)); }
            if (!d.success) throw new Error('success:false');
            navUpdateChatBadge(d.total_unread);
            const list = document.getElementById('chatClinicList');
            if (!d.clinics.length) {
                list.innerHTML = '<div class="chat-empty"><i class="fas fa-comment-slash"></i><p>No connected clinics yet.<br><a href="clinics-map.php">Browse clinics</a> to get started.</p></div>';
                return;
            }
            list.innerHTML = d.clinics.map(c => {
                const safeName = c.name.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
                return `<div class="chat-clinic-item" data-id="${c.id}" data-name="${safeName}">
                    <div class="chat-clinic-avatar">
                        ${c.image ? `<img src="${c.image}" alt="">` : '<i class="fas fa-clinic-medical"></i>'}
                    </div>
                    <div class="chat-clinic-info">
                        <div class="chat-clinic-name">${c.name}</div>
                        <div class="chat-clinic-preview">${c.last_message || '<em style="opacity:.6">Start a conversation</em>'}</div>
                    </div>
                    <div class="chat-clinic-meta">
                        ${c.last_time ? `<div class="chat-clinic-time">${c.last_time}</div>` : ''}
                        ${c.unread > 0 ? `<div class="chat-unread-badge">${c.unread}</div>` : ''}
                    </div>
                </div>`;
            }).join('');
            list.querySelectorAll('.chat-clinic-item').forEach(el => {
                el.addEventListener('click', () => navOpenClinicChat(parseInt(el.dataset.id), el.dataset.name));
            });
        })
        .catch(err => {
            console.error('[Chat]', err.message);
            document.getElementById('chatClinicList').innerHTML =
                '<div class="chat-empty"><i class="fas fa-exclamation-circle"></i><p>' + err.message + '</p></div>';
        });
}

function navOpenClinicChat(clinicId, clinicName) {
    chatClinicId = clinicId;
    chatLastId = 0;
    // Decode HTML entities from data attribute
    const ta = document.createElement('textarea');
    ta.innerHTML = clinicName;
    document.getElementById('chatClinicName').textContent = ta.value;
    // Update expand link to include clinic_id
    document.getElementById('chatExpandBtn').href = 'messages.php?clinic_id=' + clinicId;
    const list = document.getElementById('chatListScreen');
    const msgs = document.getElementById('chatMsgScreen');
    list.style.display = 'none';
    msgs.style.cssText = 'display:flex!important;flex-direction:column!important;height:100%!important;flex:1!important';
    document.getElementById('chatMessages').innerHTML = '<div class="chat-load-msg"><i class="fas fa-spinner fa-spin"></i></div>';
    document.getElementById('chatInput').focus();
    navFetchMessages(true);
    navStartPolling();
}

function navFetchMessages(initial = false) {
    if (!chatClinicId) return;
    const base = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')+1);
    fetch(`${base}chat_fetch.php?clinic_id=${chatClinicId}&last_id=${chatLastId}`)
        .then(r => r.text())
        .then(text => {
            let d;
            try { d = JSON.parse(text); } catch(e) {
                if (initial) document.getElementById('chatMessages').innerHTML =
                    '<div class="chat-load-msg" style="color:#EF4444;font-size:11px">⚠ ' + text.substring(0,150) + '</div>';
                return;
            }
            if (!d.success) return;
            const box = document.getElementById('chatMessages');
            if (!box) return;
            if (initial) box.innerHTML = '';
            if (d.messages.length === 0 && initial) {
                box.innerHTML = '<div class="chat-load-msg" style="opacity:.5">No messages yet. Say hello! 👋</div>';
                return;
            }
            const atBottom = box.scrollHeight - box.scrollTop <= box.clientHeight + 40;
            d.messages.forEach(m => {
                const ph = box.querySelector('.chat-load-msg'); if (ph) ph.remove();
                chatLastId = Math.max(chatLastId, m.id);
                const wrap = document.createElement('div');
                wrap.className = 'chat-msg-wrap ' + m.sender_type;
                wrap.innerHTML = '<div class="chat-msg-bubble">' + m.message + '</div><div class="chat-msg-time">' + m.time + '</div>';
                box.appendChild(wrap);
            });
            if (atBottom || initial) box.scrollTop = box.scrollHeight;
            navUpdateChatBadge(0);
        });
}

function navSendMessage() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg || !chatClinicId) return;
    const btn = document.getElementById('chatSendBtn');
    btn.disabled = true; input.value = '';
    const base = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')+1);
    const fd = new FormData();
    fd.append('clinic_id', chatClinicId);
    fd.append('message', msg);
    fetch(base + 'chat_send.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { btn.disabled = false; if (d.success) navFetchMessages(); else input.value = msg; })
        .catch(() => { btn.disabled = false; input.value = msg; });
}

function navStartPolling() { navStopPolling(); chatPollTimer = setInterval(() => navFetchMessages(), 2500); }
function navStopPolling()  { if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; } }

function navUpdateChatBadge(count) {
    ['navChatBadge','chatBubbleBadge'].forEach(id => {
        const el = document.getElementById(id);
        if (count > 0) {
            if (el) { el.textContent = count; }
            else {
                const btn = document.getElementById(id === 'navChatBadge' ? 'navChatBtn' : 'chatBubbleBtn');
                if (btn) { const b = document.createElement('span'); b.id=id; b.className='badge'; b.textContent=count; btn.appendChild(b); }
            }
        } else { if (el) el.remove(); }
    });
}

// Close on outside click
document.addEventListener('click', e => {
    if (chatOpen && !e.target.closest('#chatWindow') && !e.target.closest('.chat-bubble-btn'))
        navCloseChat();
});

// Badge poll every 30s
setInterval(() => {
    if (!chatOpen) {
        const base = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')+1);
        fetch(base + 'chat_clinics.php').then(r=>r.json()).then(d=>{
            if (d.success) navUpdateChatBadge(d.total_unread);
        }).catch(()=>{});
    }
}, 30000);
</script>