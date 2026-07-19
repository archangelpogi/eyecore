<?php

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Back URL logic
$referrer  = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$back_url  = 'dashboard.php';
$back_text = 'Back to Dashboard';
if (!empty($referrer)) {
    $back_map = [
        'my-appointments.php' => 'Back to Appointments',
        'favorites.php'       => 'Back to Favorites',
        'clinics-map.php'     => 'Back to Map',
        'nearby.php'          => 'Back to Nearby',
        'profile.php'         => 'Back to Profile',
        'settings.php'        => 'Back to Settings',
        'clinic-details.php'  => 'Back to Clinic',
        'clinic-products.php' => 'Back to Products',
        'my-reservations.php' => 'Back to Reservations',
    ];
    foreach ($back_map as $page => $label) {
        if (strpos($referrer, $page) !== false) {
            $back_url  = $page;
            $back_text = $label;
            break;
        }
    }
}

// Navbar vars
$user_query  = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user        = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar, created_at FROM users WHERE id = $user_id");
$user_data   = mysqli_fetch_assoc($avatar_query);

$pending_q  = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending    = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

$unread_count        = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

$sale_count_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count   = mysqli_fetch_assoc($sale_count_q)['total'] ?? 0;

$points_q   = mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id = $user_id");
$total_points = mysqli_fetch_assoc($points_q)['t'] ?? 0;

$bookings_q  = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($bookings_q)['total'] ?? 0;

$res_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending','confirmed')");
$reservation_count = mysqli_fetch_assoc($res_q)['total'] ?? 0;

// Pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$filter_type  = isset($_GET['type']) ? $_GET['type'] : 'all';
$where_clause = "user_id = $user_id";
if ($filter_type === 'unread')     $where_clause .= " AND is_read = 0";
elseif ($filter_type !== 'all')    $where_clause .= " AND type = '" . mysqli_real_escape_string($conn, $filter_type) . "'";

// Actions — handle before output
if (isset($_GET['mark_read'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = $id AND user_id = $user_id");
    header('Location: notifications.php?page='.$page.($filter_type!='all'?'&type='.$filter_type:''));
    exit();
}
if (isset($_GET['mark_all_read'])) {
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header('Location: notifications.php'.($filter_type!='all'?'?type='.$filter_type:''));
    exit();
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM notifications WHERE id = $id AND user_id = $user_id");
    header('Location: notifications.php?page='.$page.($filter_type!='all'?'&type='.$filter_type:''));
    exit();
}
if (isset($_GET['delete_all'])) {
    mysqli_query($conn, "DELETE FROM notifications WHERE user_id = $user_id");
    header('Location: notifications.php');
    exit();
}

// Get counts
$total_all    = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM notifications WHERE user_id = $user_id"))['t'];
$total_unread = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM notifications WHERE user_id = $user_id AND is_read = 0"))['t'];
$total_read   = $total_all - $total_unread;

// Get notifications for current filter
$total_filtered = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM notifications WHERE $where_clause"))['t'];
$total_pages    = max(1, ceil($total_filtered / $per_page));

$notifications_query = mysqli_query($conn, "
    SELECT * FROM notifications
    WHERE $where_clause
    ORDER BY created_at DESC
    LIMIT $offset, $per_page
");

$active_nav = '';
include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Notifications — Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; overflow-x: hidden; background: var(--bg-primary); min-height: 100vh; }
        body { font-family: 'Inter', -apple-system, sans-serif; transition: background 0.3s, color 0.3s; }

        :root {
            --primary: #00B761; --primary-dark: #00994D; --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            --bg-primary: #F5F7FA; --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A; --text-secondary: #6B7280; --text-muted: #9CA3AF;
            --border-color: #E5E7EB; --border-light: #F3F4F6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 8px 20px rgba(0,0,0,0.06);
            --shadow-hover: 0 20px 40px -10px rgba(0,183,97,0.2);
            --radius-sm: 12px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 999px;
            --danger: #EF4444; --warning: #F59E0B; --success: #00B761; --info: #3B82F6;
        }
        .theme-dark {
            --primary: #00E676; --primary-dark: #00C853; --primary-light: #1E3A2E;
            --bg-primary: #0F0F0F; --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF; --text-secondary: #B0B0B0; --text-muted: #6B7280;
            --border-color: #2D2D2D; --border-light: #262626;
        }

        .main-content { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        @media (min-width: 1024px) { .main-content { padding: 30px 40px; } }
        @media (max-width: 768px)  { .main-content { padding: 20px 16px 100px; } }

        /* ===== TOP BAR ===== */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--primary); text-decoration: none;
            font-size: 13px; font-weight: 500;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }
        .btn-back:hover { background: var(--primary); color: white; }

        /* ===== PAGE HEADER ===== */
        .page-header {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            padding: 24px 28px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-title-wrap { display: flex; align-items: center; gap: 14px; }
        .page-title-icon {
            width: 48px; height: 48px;
            background: var(--primary-light);
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            color: var(--primary); font-size: 22px;
        }
        .page-title-wrap h1 { font-size: 22px; font-weight: 700; color: var(--text-primary); }
        .page-title-wrap p  { font-size: 13px; color: var(--text-secondary); margin-top: 2px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn-act {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 18px;
            border-radius: var(--radius-full);
            font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            border: none; transition: all 0.2s; font-family: inherit;
        }
        .btn-green  { background: var(--primary-gradient); color: white; }
        .btn-green:hover  { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,183,97,0.35); }
        .btn-red    { background: var(--danger); color: white; }
        .btn-red:hover    { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(239,68,68,0.35); }

        /* ===== STATS STRIP ===== */
        .stats-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        @media (max-width: 500px) { .stats-strip { grid-template-columns: 1fr; } }
        .stat-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            padding: 16px 20px;
            display: flex; align-items: center; gap: 14px;
            transition: all 0.2s;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .stat-ico {
            width: 42px; height: 42px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .stat-ico.all    { background: var(--primary-light); color: var(--primary); }
        .stat-ico.unread { background: #FEF3C7; color: var(--warning); }
        .stat-ico.read   { background: #DCFCE7; color: #16A34A; }
        .theme-dark .stat-ico.unread { background: #3B2F00; }
        .theme-dark .stat-ico.read   { background: #0D2E1A; }
        .stat-val { font-size: 24px; font-weight: 800; color: var(--text-primary); line-height: 1; }
        .stat-lbl { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

        /* ===== FILTER TABS ===== */
        .filter-tabs {
            display: flex; gap: 8px; flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .filter-tab {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px;
            border-radius: var(--radius-full);
            border: 1.5px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 13px; font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .filter-tab:hover { border-color: var(--primary); color: var(--primary); }
        .filter-tab.active { background: var(--primary-gradient); color: white; border-color: transparent; }

        /* ===== NOTIFICATIONS LIST ===== */
        .notif-list {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-light);
            transition: background 0.15s;
            position: relative;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--bg-primary); }
        .notif-item.unread {
            border-left: 3px solid var(--primary);
            background: rgba(0, 183, 97, 0.03);
        }
        .theme-dark .notif-item.unread { background: rgba(0,230,118,0.05); }

        /* Unread dot */
        .notif-item.unread::after {
            content: '';
            position: absolute;
            top: 20px; right: 20px;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--primary);
        }

        /* Icon */
        .notif-ico {
            width: 42px; height: 42px; flex-shrink: 0;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
        }
        .notif-ico.appointment { background: #DBEAFE; color: #1D4ED8; }
        .notif-ico.favorite    { background: #FCE7F3; color: #BE185D; }
        .notif-ico.system      { background: #DCFCE7; color: #15803D; }
        .notif-ico.promo       { background: #FEF3C7; color: #B45309; }
        .notif-ico.default     { background: var(--primary-light); color: var(--primary); }
        .theme-dark .notif-ico.appointment { background: #1E3A5F; color: #60A5FA; }
        .theme-dark .notif-ico.favorite    { background: #3B0F1E; color: #F472B6; }
        .theme-dark .notif-ico.system      { background: #0D2E1A; color: #4ADE80; }
        .theme-dark .notif-ico.promo       { background: #3B2F00; color: #FCD34D; }

        /* Content */
        .notif-body { flex: 1; min-width: 0; }
        .notif-top  {
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 8px; margin-bottom: 5px;
        }
        .notif-title {
            font-size: 14px; font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .notif-time {
            font-size: 11px; color: var(--text-muted);
            white-space: nowrap; flex-shrink: 0;
            display: flex; align-items: center; gap: 4px;
        }
        .notif-msg {
            font-size: 13px; color: var(--text-secondary);
            line-height: 1.55; margin-bottom: 10px;
        }
        .notif-link {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12px; font-weight: 600;
            color: var(--primary); text-decoration: none;
            transition: gap 0.2s;
        }
        .notif-link:hover { gap: 8px; }

        /* Actions */
        .notif-actions {
            display: flex; gap: 6px;
            flex-shrink: 0; align-items: flex-start;
        }
        .notif-btn {
            width: 30px; height: 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-muted);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; cursor: pointer;
            text-decoration: none; transition: all 0.2s;
        }
        .notif-btn:hover       { background: var(--primary); color: white; border-color: var(--primary); }
        .notif-btn.del:hover   { background: var(--danger);  color: white; border-color: var(--danger); }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 70px 20px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }
        .empty-state i { font-size: 56px; color: var(--text-muted); opacity: 0.35; display: block; margin-bottom: 16px; }
        .empty-state h2 { font-size: 20px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
        .empty-state p  { font-size: 14px; color: var(--text-secondary); margin-bottom: 24px; }
        .btn-browse {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 28px;
            background: var(--primary-gradient);
            color: white; border-radius: var(--radius-full);
            font-size: 14px; font-weight: 700;
            text-decoration: none; transition: all 0.2s;
        }
        .btn-browse:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,183,97,0.35); }

        /* ===== PAGINATION ===== */
        .pagination {
            display: flex; justify-content: center;
            align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: flex; align-items: center; justify-content: center;
            min-width: 36px; height: 36px;
            padding: 0 10px;
            border-radius: var(--radius-sm);
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            color: var(--text-secondary);
            font-size: 13px; font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .pagination a:hover   { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .pg-active { background: var(--primary-gradient); color: white; border-color: transparent; }
        .pagination .pg-disabled { opacity: 0.4; pointer-events: none; }

        @media (max-width: 640px) {
            .notif-item { gap: 12px; padding: 14px 16px; }
            .notif-top  { flex-direction: column; align-items: flex-start; gap: 2px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; }
            .btn-act { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="main-content">

    <!-- Top bar -->
    <div class="top-bar">
        <a href="<?php echo $back_url; ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> <?php echo $back_text; ?>
        </a>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title-wrap">
            <div class="page-title-icon"><i class="fas fa-bell"></i></div>
            <div>
                <h1>Notifications</h1>
                <p><?php echo $total_all; ?> total · <?php echo $total_unread; ?> unread</p>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($total_unread > 0): ?>
                <a href="?mark_all_read=1<?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>" class="btn-act btn-green">
                    <i class="fas fa-check-double"></i> Mark All Read
                </a>
            <?php endif; ?>
            <?php if ($total_all > 0): ?>
                <a href="?delete_all=1" class="btn-act btn-red" id="btn-delete-all"
                   data-href="?delete_all=1">
                    <i class="fas fa-trash"></i> Delete All
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-strip">
        <div class="stat-card">
            <div class="stat-ico all"><i class="fas fa-bell"></i></div>
            <div><div class="stat-val"><?php echo $total_all; ?></div><div class="stat-lbl">Total</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-ico unread"><i class="fas fa-envelope"></i></div>
            <div><div class="stat-val"><?php echo $total_unread; ?></div><div class="stat-lbl">Unread</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-ico read"><i class="fas fa-envelope-open"></i></div>
            <div><div class="stat-val"><?php echo $total_read; ?></div><div class="stat-lbl">Read</div></div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="?type=all"         class="filter-tab <?php echo $filter_type=='all'?'active':''; ?>"><i class="fas fa-list"></i> All</a>
        <a href="?type=unread"      class="filter-tab <?php echo $filter_type=='unread'?'active':''; ?>"><i class="fas fa-envelope"></i> Unread <?php if($total_unread>0): ?><span style="background:rgba(255,255,255,0.3);padding:1px 7px;border-radius:99px;font-size:11px;"><?php echo $total_unread; ?></span><?php endif; ?></a>
        <a href="?type=appointment" class="filter-tab <?php echo $filter_type=='appointment'?'active':''; ?>"><i class="fas fa-calendar-check"></i> Appointments</a>
        <a href="?type=favorite"    class="filter-tab <?php echo $filter_type=='favorite'?'active':''; ?>"><i class="fas fa-heart"></i> Favorites</a>
        <a href="?type=promo"       class="filter-tab <?php echo $filter_type=='promo'?'active':''; ?>"><i class="fas fa-tag"></i> Promos</a>
        <a href="?type=system"      class="filter-tab <?php echo $filter_type=='system'?'active':''; ?>"><i class="fas fa-info-circle"></i> System</a>
    </div>

    <!-- Notifications List -->
    <?php if (mysqli_num_rows($notifications_query) > 0): ?>
    <div class="notif-list">
        <?php while ($notif = mysqli_fetch_assoc($notifications_query)):
            $icon_map = [
                'appointment' => 'fa-calendar-check',
                'favorite'    => 'fa-heart',
                'system'      => 'fa-info-circle',
                'promo'       => 'fa-tag',
            ];
            $ico_class  = $icon_map[$notif['type']] ?? 'fa-bell';
            $type_class = array_key_exists($notif['type'], $icon_map) ? $notif['type'] : 'default';
            $is_unread  = !$notif['is_read'];
        ?>
        <div class="notif-item <?php echo $is_unread ? 'unread' : ''; ?>">

            <!-- Icon -->
            <div class="notif-ico <?php echo $type_class; ?>">
                <i class="fas <?php echo $ico_class; ?>"></i>
            </div>

            <!-- Content -->
            <div class="notif-body">
                <div class="notif-top">
                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                    <div class="notif-time">
                        <i class="fas fa-clock"></i>
                        <?php echo timeAgo($notif['created_at']); ?>
                    </div>
                </div>
                <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                <?php if (!empty($notif['link'])): ?>
                    <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="notif-link">
                        View Details <i class="fas fa-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="notif-actions">
                <?php if ($is_unread): ?>
                    <a href="?mark_read=1&id=<?php echo $notif['id']; ?>&page=<?php echo $page; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>"
                       class="notif-btn" title="Mark as read">
                        <i class="fas fa-check"></i>
                    </a>
                <?php endif; ?>
                <a href="?delete=<?php echo $notif['id']; ?>&page=<?php echo $page; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>"
                   class="notif-btn del notif-delete-btn" title="Delete"
                   data-href="?delete=<?php echo $notif['id']; ?>&page=<?php echo $page; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>">
                    <i class="fas fa-times"></i>
                </a>
            </div>

        </div>
        <?php endwhile; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page-1; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>"><i class="fas fa-chevron-left"></i></a>
        <?php else: ?>
            <span class="pg-disabled"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="pg-active"><?php echo $i; ?></span>
            <?php elseif ($i === 1 || $i === $total_pages || abs($i - $page) <= 1): ?>
                <a href="?page=<?php echo $i; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>"><?php echo $i; ?></a>
            <?php elseif (abs($i - $page) === 2): ?>
                <span style="border:none;background:none;color:var(--text-muted);">…</span>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page+1; ?><?php echo $filter_type!='all'?'&type='.$filter_type:''; ?>"><i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
            <span class="pg-disabled"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-bell-slash"></i>
        <h2>No Notifications</h2>
        <p>
            <?php if ($filter_type !== 'all'): ?>
                Walang <?php echo htmlspecialchars($filter_type); ?> notifications.
                <a href="notifications.php" style="color:var(--primary);">View all</a>
            <?php else: ?>
                Wala ka pang notifications. Mag-appear sila dito kapag may updates.
            <?php endif; ?>
        </p>
        <a href="<?php echo $back_url; ?>" class="btn-browse">
            <i class="fas fa-arrow-left"></i> <?php echo $back_text; ?>
        </a>
    </div>
    <?php endif; ?>

</div>

<script>
// Auto-refresh every 60 seconds
setTimeout(() => location.reload(), 60000);

// SweetAlert2 — Delete individual notification
document.querySelectorAll('.notif-delete-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var url = this.getAttribute('data-href');
        Swal.fire({
            title: 'Delete Notification?',
            text: 'This notification will be permanently removed.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#6B7280',
            confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete it',
            cancelButtonText: 'Cancel',
            borderRadius: '16px',
            customClass: {
                popup: 'swal-custom-popup'
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
});

// SweetAlert2 — Delete ALL notifications
var btnDeleteAll = document.getElementById('btn-delete-all');
if (btnDeleteAll) {
    btnDeleteAll.addEventListener('click', function(e) {
        e.preventDefault();
        var url = this.getAttribute('data-href');
        Swal.fire({
            title: 'Delete All Notifications?',
            text: 'All notifications will be permanently deleted. This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#6B7280',
            confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete all',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'swal-custom-popup'
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
}
</script>
</body>
</html>