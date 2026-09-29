<?php
// views/rider_dashboard.php — Rider Dashboard (ParcelGo Style)
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['rider_id']) || !isset($_SESSION['user_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$riderId  = (int)$_SESSION['rider_id'];
$userId   = (int)$_SESSION['user_id'];
$clinicId = (int)($_SESSION['clinic_id'] ?? 0);

// ✅ Verify rider
$rStmt = $pdo->prepare("
    SELECT r.*, u.email, u.contact 
    FROM riders r
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ? AND r.user_id = ? AND r.status = 'active'
");
$rStmt->execute([$riderId, $userId]);
$rider = $rStmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    session_destroy();
    header('Location: ../admin/login.php?error=rider_not_found');
    exit;
}

// ✅ Stats
$stmtStats = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN delivery_status = 'assigned' THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN delivery_status = 'picked_up' THEN 1 ELSE 0 END) AS picked_up,
        SUM(CASE WHEN delivery_status = 'in_transit' THEN 1 ELSE 0 END) AS in_transit,
        SUM(CASE WHEN delivery_status = 'delivered' AND DATE(delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS delivered_today,
        SUM(CASE WHEN delivery_status IN ('assigned','picked_up','in_transit') THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) AS total_delivered
    FROM reservations
    WHERE assigned_rider_id = ? AND clinic_id = ?
");
$stmtStats->execute([$riderId, $clinicId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ✅ Active deliveries
$stmtActive = $pdo->prepare("
    SELECT r.*,
           CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
           u.contact AS customer_contact,
           p.name AS product_name,
           p.image AS product_image
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN products p ON r.product_id = p.id
    WHERE r.assigned_rider_id = ?
      AND r.clinic_id = ?
      AND r.delivery_status IN ('assigned', 'picked_up', 'in_transit')
    ORDER BY 
        CASE 
            WHEN r.delivery_status = 'in_transit' THEN 0
            WHEN r.delivery_status = 'picked_up' THEN 1
            WHEN r.delivery_status = 'assigned' THEN 2
            ELSE 3
        END,
        r.delivery_date ASC,
        r.delivery_time_start ASC,
        r.created_at ASC
");
$stmtActive->execute([$riderId, $clinicId]);
$activeDeliveries = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

// ✅ Delivered today
$stmtToday = $pdo->prepare("
    SELECT r.*,
           CONCAT(u.first_name, ' ', u.last_name) AS customer_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.assigned_rider_id = ?
      AND r.clinic_id = ?
      AND r.delivery_status = 'delivered'
      AND DATE(r.delivered_at) = CURDATE()
    ORDER BY r.delivered_at DESC
");
$stmtToday->execute([$riderId, $clinicId]);
$completedToday = $stmtToday->fetchAll(PDO::FETCH_ASSOC);

// ✅ History
$stmtHistory = $pdo->prepare("
    SELECT r.*,
           CONCAT(u.first_name, ' ', u.last_name) AS customer_name
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    WHERE r.assigned_rider_id = ?
      AND r.clinic_id = ?
      AND r.delivery_status = 'delivered'
      AND DATE(r.delivered_at) < CURDATE()
    ORDER BY r.delivered_at DESC
    LIMIT 10
");
$stmtHistory->execute([$riderId, $clinicId]);
$deliveryHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ✅ Total collected today
$stmtCollected = $pdo->prepare("
    SELECT COALESCE(SUM(collected_amount), 0) AS total_collected
    FROM reservations
    WHERE assigned_rider_id = ?
      AND clinic_id = ?
      AND DATE(collected_at) = CURDATE()
");
$stmtCollected->execute([$riderId, $clinicId]);
$totalCollectedToday = (float)($stmtCollected->fetch(PDO::FETCH_ASSOC)['total_collected'] ?? 0);

// ✅ Today's schedule count
$stmtTodaySched = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM reservations
    WHERE assigned_rider_id = ?
      AND clinic_id = ?
      AND delivery_date = CURDATE()
      AND delivery_status NOT IN ('delivered', 'cancelled')
");
$stmtTodaySched->execute([$riderId, $clinicId]);
$todaySched = $stmtTodaySched->fetch(PDO::FETCH_ASSOC);

// ✅ Working hours
$scheduleStmt = $pdo->prepare("
    SELECT es.time_in, es.time_out, es.break_start, es.break_end
    FROM employee_schedules es
    INNER JOIN riders r ON r.employee_id = es.employee_id
    WHERE r.id = ? AND es.clinic_id = ? AND es.is_active = 1
      AND (es.effective_from IS NULL OR es.effective_from <= CURDATE())
      AND (es.effective_to   IS NULL OR es.effective_to   >= CURDATE())
    ORDER BY es.created_at DESC
    LIMIT 1
");
$scheduleStmt->execute([$riderId, $clinicId]);
$riderSchedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

// ✅ NEXT delivery
$nextDelivery = !empty($activeDeliveries) ? $activeDeliveries[0] : null;

// ✅ Count remaining today
$remainingToday = (int)($todaySched['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#0d6e6e">
    <title>Rider Dashboard — Eyecore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --ec-primary: #0d6e6e;
            --ec-primary-dark: #095050;
            --ec-primary-light: #e6f4f4;
            --ec-accent: #00B761;
            --ec-accent-dark: #008F4C;
            --ec-text: #0f172a;
            --ec-text-muted: #64748b;
            --ec-border: #e2e8f0;
            --ec-bg: #f4f7f6;
            --ec-card: #ffffff;
            --ec-warning: #f59e0b;
            --ec-danger: #ef4444;
            --ec-success: #10b981;
            --ec-info: #3b82f6;
            --ec-radius: 14px;
            --ec-radius-sm: 10px;
            --ec-shadow-sm: 0 1px 3px rgba(15,23,42,.05);
            --ec-shadow-md: 0 4px 16px rgba(15,23,42,.08);
            --ec-shadow-lg: 0 12px 40px rgba(15,23,42,.12);
        }

        html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }

        body {
            background: var(--ec-bg);
            color: var(--ec-text);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            font-size: 14px;
        }

        /* ═══════════════ HEADER ═══════════════ */
        .rider-header {
            background: #fff;
            padding: .875rem 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--ec-border);
        }

        .rider-header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--ec-primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .brand-name {
            font-size: .95rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.02em;
            line-height: 1.1;
        }

        .brand-sub {
            font-size: .6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--ec-text-muted);
            margin-top: 2px;
        }

        .header-divider {
            width: 1px;
            height: 32px;
            background: var(--ec-border);
            margin: 0 4px;
        }

        .rider-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .rider-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--ec-primary-light);
            color: var(--ec-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px var(--ec-border);
        }

        .rider-info {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .rider-name {
            font-size: .85rem;
            font-weight: 700;
            color: var(--ec-text);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .rider-role {
            font-size: .65rem;
            color: var(--ec-text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .on-duty-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .58rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            border: 1px solid #6ee7b7;
        }

        .header-actions {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid var(--ec-border);
            color: var(--ec-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            cursor: pointer;
            transition: all .15s;
            position: relative;
        }

        .icon-btn:hover {
            background: var(--ec-bg);
            border-color: var(--ec-primary);
            color: var(--ec-primary);
        }

        .icon-btn .notif-dot {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 7px;
            height: 7px;
            background: var(--ec-danger);
            border-radius: 50%;
            border: 1.5px solid #fff;
        }

        .text-btn {
            height: 38px;
            padding: 0 .9rem;
            border-radius: 8px;
            background: #fff;
            border: 1px solid var(--ec-border);
            color: var(--ec-text);
            font-size: .78rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            font-family: inherit;
        }

        .text-btn:hover {
            background: var(--ec-bg);
            border-color: var(--ec-primary);
            color: var(--ec-primary);
        }

        .text-btn.primary {
            background: var(--ec-primary-light);
            border-color: #b2dede;
            color: var(--ec-primary);
        }

        .text-btn.primary:hover {
            background: #d1eded;
        }

        /* ═══════════════ MAIN CONTENT ═══════════════ */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem .75rem 5rem;
        }

        /* ═══════════════ SHIFT BAR ═══════════════ */
        .shift-bar {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 12px;
            padding: .85rem 1.15rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .shift-bar::-webkit-scrollbar { display: none; }

        .shift-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .shift-item-label {
            font-size: .6rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--ec-text-muted);
            font-weight: 700;
        }

        .shift-item-value {
            font-size: 1rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
        }

        .shift-item-sub {
            font-size: .7rem;
            color: var(--ec-text-muted);
            font-weight: 600;
        }

        .shift-divider {
            width: 1px;
            height: 40px;
            background: var(--ec-border);
            flex-shrink: 0;
        }

        /* ═══════════════ STATS CARDS ═══════════════ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .75rem;
            margin-bottom: 1.25rem;
        }

        .stat-card-new {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 12px;
            padding: .85rem .95rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            box-shadow: var(--ec-shadow-sm);
            transition: all .15s;
            min-width: 0;
        }

        .stat-card-new:hover {
            box-shadow: var(--ec-shadow-md);
        }

        .stat-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon-box.warning { background: #fef3c7; color: #d97706; }
        .stat-icon-box.info    { background: #dbeafe; color: #2563eb; }
        .stat-icon-box.success { background: #d1fae5; color: #059669; }
        .stat-icon-box.accent  { background: #fef3c7; color: #d97706; }

        .stat-content {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .stat-label-new {
            font-size: .7rem;
            font-weight: 700;
            color: var(--ec-text-muted);
        }

        .stat-value-new {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.1;
            color: var(--ec-text);
        }

        .stat-sub {
            font-size: .65rem;
            color: var(--ec-text-muted);
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ═══════════════ SECTION HEADER ═══════════════ */
        .section-h {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1.25rem 0 .75rem;
            gap: .5rem;
        }

        .section-h-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--ec-accent);
            flex-shrink: 0;
        }

        .section-h h5 {
            font-size: .85rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--ec-text);
        }

        .section-h-right {
            font-size: .72rem;
            color: var(--ec-text-muted);
            font-weight: 600;
        }

        .section-h-right a,
        .section-h-right button {
            color: var(--ec-primary);
            font-weight: 700;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: inherit;
            padding: 0;
        }

        .section-h-right a:hover,
        .section-h-right button:hover {
            text-decoration: underline;
        }

        /* ═══════════════ HERO — NEXT DELIVERY (ParcelGo Style) ═══════════════ */
        .hero-delivery {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--ec-shadow-md);
            margin-bottom: 1.25rem;
            display: grid;
            grid-template-columns: 1fr 1.4fr;
        }

        /* Left panel — dark green */
        .hero-left {
            background: linear-gradient(135deg, #0d6e6e 0%, #095050 100%);
            color: #fff;
            padding: 1.25rem 1.35rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            min-height: 280px;
        }

        .hero-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 65%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-left-top {
            position: relative;
            z-index: 1;
        }

        .hero-left-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: .85rem;
        }

        .hero-resv {
            font-family: 'Courier New', monospace;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .02em;
            opacity: .9;
        }

        .hero-status-badge {
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.35);
            color: #fff;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .hero-go-label {
            font-size: .6rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            font-weight: 700;
            opacity: .7;
            margin-bottom: .35rem;
        }

        .hero-address {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: .25rem;
            word-break: break-word;
        }

        .hero-city {
            font-size: .82rem;
            opacity: .85;
            font-weight: 600;
        }

        .hero-customer {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 1rem;
            position: relative;
            z-index: 1;
        }

        .hero-customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,.2);
            border: 1.5px solid rgba(255,255,255,.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .hero-customer-info {
            min-width: 0;
        }

        .hero-customer-name {
            font-size: .88rem;
            font-weight: 700;
            line-height: 1.15;
        }

        .hero-customer-phone {
            font-size: .72rem;
            opacity: .85;
            font-family: 'Courier New', monospace;
        }

        .hero-left-actions {
            display: flex;
            gap: .5rem;
            margin-top: 1rem;
            position: relative;
            z-index: 1;
        }

        .hero-action-btn {
            flex: 1;
            background: #fff;
            color: var(--ec-primary);
            border: none;
            padding: .65rem .8rem;
            border-radius: 9px;
            font-size: .75rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            letter-spacing: .03em;
            text-transform: uppercase;
            font-family: inherit;
            min-height: 42px;
        }

        .hero-action-btn:hover {
            background: #f0fdf4;
            color: var(--ec-primary-dark);
            transform: translateY(-1px);
        }

        .hero-action-btn.outline {
            background: transparent;
            color: #fff;
            border: 1.5px solid rgba(255,255,255,.4);
        }

        .hero-action-btn.outline:hover {
            background: rgba(255,255,255,.15);
            color: #fff;
        }

        /* Right panel — white */
        .hero-right {
            padding: 1.25rem 1.35rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #fff;
        }

        .hero-right-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .hero-right-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
        }

        .hero-right-sub {
            font-size: .72rem;
            color: var(--ec-text-muted);
            margin-top: 2px;
            font-weight: 500;
        }

        .hero-cod-box {
            text-align: right;
            flex-shrink: 0;
        }

        .hero-cod-label {
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
            color: var(--ec-text-muted);
        }

        .hero-cod-amount {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.02em;
            margin-top: 2px;
        }

        /* Info grid */
        .hero-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .hero-info-item {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .hero-info-icon {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: var(--ec-bg);
            color: var(--ec-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .hero-info-label {
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
            color: var(--ec-text-muted);
            margin-bottom: 3px;
        }

        .hero-info-value {
            font-size: .85rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
        }

        .hero-info-sub {
            font-size: .68rem;
            color: var(--ec-text-muted);
            margin-top: 1px;
        }

        /* Progress tracker */
        .hero-progress-wrap {
            margin-top: auto;
        }

        .hero-progress-label {
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
            color: var(--ec-text-muted);
            margin-bottom: .65rem;
        }

        .hero-progress-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            padding: 0 .25rem;
        }

        .hero-progress-line {
            position: absolute;
            top: 11px;
            left: 20px;
            right: 20px;
            height: 2px;
            background: var(--ec-border);
            z-index: 0;
        }

        .hero-progress-line-fill {
            height: 100%;
            background: var(--ec-primary);
            transition: width .3s ease;
        }

        .hero-progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            position: relative;
            z-index: 1;
            flex: 1;
        }

        .hero-progress-dot {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid var(--ec-border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--ec-text-muted);
            transition: all .2s;
        }

        .hero-progress-step.done .hero-progress-dot {
            background: var(--ec-primary);
            border-color: var(--ec-primary);
            color: #fff;
        }

        .hero-progress-step.active .hero-progress-dot {
            background: var(--ec-primary);
            border-color: var(--ec-primary);
            color: #fff;
            box-shadow: 0 0 0 4px var(--ec-primary-light);
        }

        .hero-progress-text {
            font-size: .6rem;
            font-weight: 700;
            color: var(--ec-text-muted);
            text-align: center;
            white-space: nowrap;
        }

        .hero-progress-step.done .hero-progress-text,
        .hero-progress-step.active .hero-progress-text {
            color: var(--ec-text);
        }

        /* COD alert */
        .hero-cod-alert {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: .55rem .75rem;
            margin-top: .85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .72rem;
            font-weight: 600;
            color: #92400e;
        }

        .hero-cod-alert i {
            font-size: 14px;
            color: #d97706;
            flex-shrink: 0;
        }

        /* ═══════════════ SEARCH BAR ═══════════════ */
        .search-bar {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-bar i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ec-text-muted);
            font-size: .9rem;
        }

        .search-bar input {
            width: 100%;
            padding: .75rem 1rem .75rem 2.5rem;
            border: 1px solid var(--ec-border);
            border-radius: 10px;
            font-size: .82rem;
            background: #fff;
            font-family: inherit;
            transition: all .15s;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--ec-primary);
            box-shadow: 0 0 0 3px rgba(13,110,110,.08);
        }

        .search-hint {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: .62rem;
            color: var(--ec-text-muted);
            background: var(--ec-bg);
            padding: 3px 7px;
            border-radius: 5px;
            font-weight: 700;
            border: 1px solid var(--ec-border);
            pointer-events: none;
        }

        /* ═══════════════ DELIVERY ROWS (ParcelGo Style) ═══════════════ */
        .delivery-row {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 12px;
            padding: 1rem 1.15rem;
            margin-bottom: .65rem;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr auto;
            gap: 1rem;
            align-items: center;
            transition: all .15s;
            box-shadow: var(--ec-shadow-sm);
        }

        .delivery-row:hover {
            box-shadow: var(--ec-shadow-md);
            border-color: #cbd5e1;
        }

        .delivery-col {
            min-width: 0;
        }

        /* Column 1: ID + customer + address */
        .delivery-id-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .delivery-id {
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            font-weight: 800;
            color: var(--ec-primary);
            letter-spacing: .01em;
        }

        .delivery-status-tag {
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .55rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .tag-assigned   { background: #fef3c7; color: #92400e; }
        .tag-picked_up  { background: #ede9fe; color: #5b21b6; }
        .tag-in_transit { background: #dbeafe; color: #1e40af; }

        .delivery-customer-name {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
            line-height: 1.15;
            margin-bottom: 3px;
        }

        .delivery-address-line {
            font-size: .72rem;
            color: var(--ec-text-muted);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .delivery-address-line i {
            font-size: .7rem;
            flex-shrink: 0;
        }

        /* Column 2: Delivery time */
        .delivery-col-label {
            font-size: .56rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
            color: var(--ec-text-muted);
            margin-bottom: 4px;
        }

        .delivery-time-value {
            font-size: .85rem;
            font-weight: 800;
            color: var(--ec-text);
            display: flex;
            align-items: center;
            gap: 5px;
            letter-spacing: -0.01em;
        }

        .delivery-time-value i {
            color: var(--ec-primary);
            font-size: .85rem;
        }

        .delivery-tracking-sub {
            font-size: .65rem;
            color: var(--ec-text-muted);
            font-family: 'Courier New', monospace;
            margin-top: 3px;
        }

        /* Column 3: Payment */
        .delivery-pay-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .55rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .pay-paid { background: #d1fae5; color: #065f46; }
        .pay-cod  { background: #fef3c7; color: #92400e; }

        .delivery-amount-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.02em;
            line-height: 1.1;
        }

        .delivery-pay-sub {
            font-size: .65rem;
            color: var(--ec-text-muted);
            margin-top: 3px;
            font-weight: 500;
        }

        /* Column 4: Actions */
        .delivery-actions-cell {
            display: flex;
            gap: .5rem;
            align-items: center;
            flex-shrink: 0;
        }

        .delivery-icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #fff;
            border: 1.5px solid var(--ec-border);
            color: var(--ec-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            flex-shrink: 0;
        }

        .delivery-icon-btn:hover {
            background: var(--ec-bg);
            border-color: var(--ec-primary);
            color: var(--ec-primary);
        }

        .delivery-primary-btn {
            height: 44px;
            padding: 0 1.25rem;
            border-radius: 10px;
            background: var(--ec-primary);
            color: #fff;
            border: none;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
            font-family: inherit;
        }

        .delivery-primary-btn:hover {
            background: var(--ec-primary-dark);
            transform: translateY(-1px);
        }

        .delivery-primary-btn i {
            font-size: .95rem;
        }

        /* ═══════════════ DELIVERED ROWS (ParcelGo Style) ═══════════════ */
        .delivered-row {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 12px;
            padding: .9rem 1.15rem;
            margin-bottom: .55rem;
            display: grid;
            grid-template-columns: 60px 1.4fr 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: center;
            transition: all .15s;
            cursor: pointer;
            box-shadow: var(--ec-shadow-sm);
        }

        .delivered-row:hover {
            box-shadow: var(--ec-shadow-md);
            border-color: #cbd5e1;
        }

        .delivered-thumb {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: var(--ec-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ec-primary);
            font-size: 22px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .delivered-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .delivered-name {
            font-size: .95rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
            line-height: 1.15;
            margin-bottom: 3px;
        }

        .delivered-sub {
            font-size: .7rem;
            color: var(--ec-text-muted);
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .delivered-col-label {
            font-size: .56rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
            color: var(--ec-text-muted);
            margin-bottom: 4px;
        }

        .delivered-col-value {
            font-size: .85rem;
            font-weight: 800;
            color: var(--ec-text);
            letter-spacing: -0.01em;
        }

        .delivered-col-value.collected {
            color: var(--ec-accent);
        }

        .delivered-status-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
        }

        /* ═══════════════ HISTORY TABLE (ParcelGo Style) ═══════════════ */
        .history-table {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--ec-shadow-sm);
        }

        .history-thead {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1.5fr 1fr 1fr;
            gap: 1rem;
            padding: .85rem 1.15rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--ec-border);
            font-size: .58rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
            color: var(--ec-text-muted);
        }

        .history-row {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1.5fr 1fr 1fr;
            gap: 1rem;
            padding: .9rem 1.15rem;
            border-bottom: 1px solid var(--ec-border);
            align-items: center;
            transition: background .15s;
            cursor: pointer;
        }

        .history-row:last-child { border-bottom: none; }
        .history-row:hover { background: var(--ec-bg); }

        .history-cell {
            font-size: .8rem;
            color: var(--ec-text);
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .history-cell.date {
            color: var(--ec-text-muted);
            font-size: .75rem;
        }

        .history-cell.resv {
            font-family: 'Courier New', monospace;
            font-weight: 800;
            color: var(--ec-primary);
            font-size: .75rem;
        }

        .history-cell.amount {
            font-weight: 800;
            text-align: right;
            color: var(--ec-text);
        }

        .history-cell.status {
            text-align: right;
        }

        /* ═══════════════ EMPTY STATE ═══════════════ */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--ec-text-muted);
            background: #fff;
            border-radius: 12px;
            border: 2px dashed var(--ec-border);
        }

        .empty-state i {
            font-size: 2.5rem;
            opacity: .3;
            display: block;
            margin-bottom: .65rem;
        }

        .empty-state p {
            margin: 0;
            font-weight: 600;
            font-size: .85rem;
        }

        /* ═══════════════ MODAL STYLES ═══════════════ */
        .proof-preview-wrap {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid var(--ec-border);
        }

        .proof-preview-wrap img {
            width: 100%;
            max-height: 240px;
            object-fit: cover;
            display: block;
        }

        .proof-remove-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(220, 53, 69, .9);
            color: #fff;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
        }

        .proof-upload-box {
            border: 2px dashed var(--ec-border);
            border-radius: 8px;
            padding: 1.5rem 1rem;
            text-align: center;
            background: var(--ec-bg);
            cursor: pointer;
            transition: all .15s;
            display: block;
        }

        .proof-upload-box:hover {
            border-color: var(--ec-primary);
            background: var(--ec-primary-light);
        }

        .proof-upload-box i {
            font-size: 1.75rem;
            color: var(--ec-primary);
            margin-bottom: .35rem;
            display: block;
        }

        .proof-upload-box .label {
            font-weight: 700;
            font-size: .85rem;
            color: var(--ec-text);
        }

        .proof-upload-box .hint {
            font-size: .7rem;
            color: var(--ec-text-muted);
            margin-top: .15rem;
        }

        .cod-total-due {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 12px;
            text-align: center;
        }

        .cod-total-due .label {
            font-size: 10px;
            color: #92400e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .cod-total-due .amount {
            font-size: 1.5rem;
            font-weight: 800;
            color: #92400e;
            margin-top: 2px;
            letter-spacing: -0.02em;
        }

        .amount-input-wrap { position: relative; }

        .amount-input-wrap .currency {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 800;
            color: var(--ec-text-muted);
            font-size: 1rem;
        }

        .amount-input-wrap input {
            padding-left: 34px;
            font-size: 1.15rem;
            font-weight: 800;
            height: 50px;
            border: 2px solid var(--ec-border);
            border-radius: 10px;
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: all .15s;
        }

        .amount-input-wrap input:focus {
            outline: none;
            border-color: var(--ec-primary);
            box-shadow: 0 0 0 3px rgba(13,110,110,.1);
        }

        .collection-result {
            margin-top: 10px;
            border-radius: 10px;
            padding: 12px;
            animation: slideIn .25s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .collection-result.error   { background: #fee2e2; border: 2px solid #dc2626; }
        .collection-result.success { background: #d1fae5; border: 2px solid #10b981; }
        .collection-result.change  { background: #fef3c7; border: 2px solid #f59e0b; }

        .collection-result .title {
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }

        .collection-result.error .title   { color: #991b1b; }
        .collection-result.success .title { color: #065f46; }
        .collection-result.change .title  { color: #92400e; }

        .collection-result .desc {
            font-size: 12px;
            color: #475569;
            line-height: 1.4;
        }

        .change-display {
            background: #fff;
            border: 2px dashed #f59e0b;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
            text-align: center;
        }

        .change-display .label {
            font-size: 10px;
            color: #92400e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .change-display .amount {
            font-size: 1.4rem;
            font-weight: 800;
            color: #f59e0b;
            margin-top: 2px;
        }

        /* ═══════════════ RESPONSIVE ═══════════════ */
        @media (max-width: 991.98px) {
            .hero-delivery {
                grid-template-columns: 1fr;
            }
            .hero-left {
                min-height: auto;
            }
            .hero-address {
                font-size: 1.35rem;
            }
            .delivery-row {
                grid-template-columns: 1fr 1fr;
                gap: .75rem;
            }
            .delivery-actions-cell {
                grid-column: 1 / -1;
                justify-content: flex-end;
                padding-top: .5rem;
                border-top: 1px dashed var(--ec-border);
            }
            .delivered-row {
                grid-template-columns: 60px 1fr 1fr auto;
                gap: .75rem;
            }
            .delivered-row > *:nth-child(3),
            .delivered-row > *:nth-child(4) {
                display: none;
            }
            .history-thead > *:nth-child(3),
            .history-thead > *:nth-child(4),
            .history-row > *:nth-child(3),
            .history-row > *:nth-child(4) {
                display: none;
            }
            .history-thead,
            .history-row {
                grid-template-columns: 1fr 1.5fr 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .rider-header { padding: .7rem .75rem; }
            .brand-name { font-size: .85rem; }
            .brand-sub { font-size: .55rem; }
            .brand-logo { width: 36px; height: 36px; font-size: 16px; }
            .rider-avatar { width: 34px; height: 34px; font-size: 14px; }
            .rider-name { font-size: .78rem; }
            .rider-role { font-size: .6rem; }
            .icon-btn { width: 34px; height: 34px; font-size: 14px; }
            .text-btn { height: 34px; padding: 0 .65rem; font-size: .72rem; }
            .header-divider { display: none; }
            .rider-info { display: none; }
            .text-btn span { display: none; }
            .text-btn { width: 34px; padding: 0; justify-content: center; }
            .text-btn i { margin: 0; }

            .main-content { padding: .75rem .55rem 5rem; }

            .shift-bar { padding: .7rem .85rem; gap: .85rem; }
            .shift-item-value { font-size: .85rem; }
            .shift-divider { height: 32px; }

            .stats-row { grid-template-columns: repeat(2, 1fr); gap: .5rem; }
            .stat-card-new { padding: .7rem .75rem; gap: .6rem; }
            .stat-icon-box { width: 38px; height: 38px; font-size: 16px; }
            .stat-value-new { font-size: 1.15rem; }
            .stat-label-new { font-size: .62rem; }
            .stat-sub { font-size: .58rem; }

            .hero-left { padding: 1rem 1.1rem; }
            .hero-address { font-size: 1.25rem; }
            .hero-city { font-size: .75rem; }
            .hero-right { padding: 1rem 1.1rem; }
            .hero-right-title { font-size: .95rem; }
            .hero-cod-amount { font-size: 1.25rem; }
            .hero-info-grid { gap: .75rem; }
            .hero-info-value { font-size: .78rem; }
            .hero-action-btn { font-size: .68rem; padding: .55rem .6rem; min-height: 38px; }

            .delivery-row {
                grid-template-columns: 1fr;
                gap: .75rem;
                padding: .85rem .95rem;
            }
            .delivery-customer-name { font-size: .95rem; }
            .delivery-amount-value { font-size: 1.05rem; }

            .delivered-row {
                grid-template-columns: 48px 1fr auto;
                gap: .65rem;
                padding: .75rem .85rem;
            }
            .delivered-thumb { width: 48px; height: 48px; font-size: 18px; }
            .delivered-name { font-size: .85rem; }

            .history-thead,
            .history-row {
                grid-template-columns: 1fr 1.5fr 1fr;
                gap: .5rem;
                padding: .65rem .85rem;
            }
            .history-thead { font-size: .5rem; }
            .history-cell { font-size: .72rem; }
            .history-cell.resv { font-size: .68rem; }

            .cod-total-due .amount { font-size: 1.3rem; }
            .amount-input-wrap input { font-size: 1rem; height: 46px; }
        }

        @media (max-width: 480px) {
            .shift-bar { gap: .65rem; padding: .6rem .7rem; }
            .shift-item-value { font-size: .78rem; }
            .shift-item-sub { font-size: .62rem; }
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--ec-border); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--ec-text-muted); }
    </style>
</head>
<body>

    <!-- ═══ HEADER (ParcelGo Style) ═══ -->
    <div class="rider-header">
        <div class="rider-header-inner">
            <div class="header-left">
                <!-- Brand -->
                <div class="brand-logo">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div class="brand-text">
                    <div class="brand-name">Eyecore</div>
                    <div class="brand-sub">Rider</div>
                </div>

                <div class="header-divider"></div>

                <!-- Rider profile -->
                <div class="rider-profile">
                    <div class="rider-avatar">
                        <?= strtoupper(substr($rider['name'], 0, 1)) ?>
                    </div>
                    <div class="rider-info">
                        <div class="rider-name">
                            <?= htmlspecialchars($rider['name']) ?>
                            <span class="on-duty-badge">On Duty</span>
                        </div>
                        <div class="rider-role">
                            <i class="bi bi-bicycle"></i> Delivery Rider
                        </div>
                    </div>
                </div>
            </div>

            <div class="header-actions">
                <button class="icon-btn" title="Notifications">
                    <i class="bi bi-bell"></i>
                </button>
                <button class="text-btn primary" onclick="refreshPage()">
                    <i class="bi bi-arrow-clockwise"></i> <span>Refresh</span>
                </button>
                <button class="text-btn" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
                </button>
            </div>
        </div>
    </div>

    <div class="main-content">

        <!-- ═══ TODAY'S SHIFT BAR ═══ -->
        <div class="shift-bar">
            <div class="shift-item">
                <div class="shift-item-label">Today's Shift</div>
                <div class="shift-item-sub"><?= date('l, F j') ?></div>
            </div>
            <div class="shift-divider"></div>

            <?php if ($riderSchedule): ?>
            <div class="shift-item">
                <div class="shift-item-label">Time In</div>
                <div class="shift-item-value"><?= date('g:i A', strtotime($riderSchedule['time_in'])) ?></div>
            </div>
            <div class="shift-divider"></div>

            <?php if ($riderSchedule['break_start'] && $riderSchedule['break_end']): ?>
            <div class="shift-item">
                <div class="shift-item-label">Break</div>
                <div class="shift-item-value"><?= date('g:i A', strtotime($riderSchedule['break_start'])) ?></div>
            </div>
            <div class="shift-divider"></div>
            <?php endif; ?>

            <div class="shift-item">
                <div class="shift-item-label">Time Out</div>
                <div class="shift-item-value"><?= date('g:i A', strtotime($riderSchedule['time_out'])) ?></div>
            </div>
            <div class="shift-divider"></div>
            <?php endif; ?>

            <div class="shift-item">
                <div class="shift-item-label">Today's Deliveries</div>
                <div class="shift-item-value"><?= (int)($todaySched['total'] ?? 0) ?></div>
            </div>
        </div>

        <!-- ═══ STATS CARDS (ParcelGo Style) ═══ -->
        <div class="stats-row">
            <div class="stat-card-new">
                <div class="stat-icon-box warning">
                    <i class="bi bi-clipboard-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">Assigned</div>
                    <div class="stat-value-new"><?= (int)($stats['assigned'] ?? 0) ?></div>
                    <div class="stat-sub"><?= (int)($stats['assigned'] ?? 0) ?> deliveries remaining</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box info">
                    <i class="bi bi-bicycle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">In Transit</div>
                    <div class="stat-value-new"><?= (int)($stats['in_transit'] ?? 0) ?></div>
                    <div class="stat-sub">Currently on the road</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box success">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">Delivered</div>
                    <div class="stat-value-new"><?= (int)($stats['delivered_today'] ?? 0) ?></div>
                    <div class="stat-sub">Completed today</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box accent">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">COD Collected</div>
                    <div class="stat-value-new">₱<?= number_format($totalCollectedToday, 0) ?></div>
                    <div class="stat-sub">Cash to remit</div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- HERO — NEXT DELIVERY (ParcelGo Style)                    -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <?php if ($nextDelivery): ?>
        <?php
            $nd = $nextDelivery;
            $ndIsCOD = in_array($nd['payment_status'], ['cod', 'onsite', 'unpaid', 'partial']);
            $ndStatus = $nd['delivery_status'];
            $ndStatusLabel = ['assigned'=>'Assigned','picked_up'=>'Picked Up','in_transit'=>'In Transit'][$ndStatus] ?? 'Assigned';
            $ndAddress = trim(($nd['delivery_address'] ?? ''));
            $ndCity = trim(($nd['delivery_barangay'] ?? '') . ($nd['delivery_city'] ? ', '.$nd['delivery_city'] : ''));
            $ndTime = (!empty($nd['delivery_time_start']) && !empty($nd['delivery_time_end']))
                ? date('g:i A', strtotime($nd['delivery_time_start'])) . ' – ' . date('g:i A', strtotime($nd['delivery_time_end']))
                : '—';
            $ndInitials = strtoupper(substr($nd['customer_name'], 0, 1));
            $fullAddress = trim($ndAddress . ($ndCity ? ', '.$ndCity : ''));

            // Progress
            $stepAssigned  = in_array($ndStatus, ['assigned','picked_up','in_transit']) ? 'done' : '';
            $stepPickedUp  = $ndStatus === 'picked_up' ? 'active' : (in_array($ndStatus, ['in_transit']) ? 'done' : '');
            $stepInTransit = $ndStatus === 'in_transit' ? 'active' : '';
            $stepDelivered = '';

            // Progress line fill %
            $fillPercent = '0%';
            if ($ndStatus === 'picked_up') $fillPercent = '33%';
            elseif ($ndStatus === 'in_transit') $fillPercent = '66%';
        ?>
        <div class="section-h">
            <div class="section-h-left">
                <span class="section-dot"></span>
                <h5>Next Delivery</h5>
            </div>
            <div class="section-h-right">
                Stop 1 of <?= $remainingToday + 1 ?> • Deliver by <?= date('g:i A', strtotime($nd['delivery_time_end'] ?? 'now')) ?>
            </div>
        </div>

        <div class="hero-delivery">
            <!-- LEFT: Dark green address panel -->
            <div class="hero-left">
                <div class="hero-left-top">
                    <div class="hero-left-header">
                        <div class="hero-resv">#<?= htmlspecialchars($nd['reservation_code']) ?></div>
                        <div class="hero-status-badge"><?= $ndStatusLabel ?></div>
                    </div>

                    <div class="hero-go-label">Go To</div>
                    <div class="hero-address"><?= htmlspecialchars($ndAddress ?: 'Address not set') ?></div>
                    <?php if ($ndCity): ?>
                        <div class="hero-city"><?= htmlspecialchars($ndCity) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="hero-customer">
                        <div class="hero-customer-avatar"><?= $ndInitials ?></div>
                        <div class="hero-customer-info">
                            <div class="hero-customer-name"><?= htmlspecialchars($nd['customer_name']) ?></div>
                            <div class="hero-customer-phone"><?= htmlspecialchars($nd['customer_contact'] ?? '—') ?></div>
                        </div>
                    </div>

                    <div class="hero-left-actions">
                        <a class="hero-action-btn" href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($fullAddress) ?>" target="_blank">
                            <i class="bi bi-send-fill"></i> Navigate
                        </a>
                        <?php if (!empty($nd['customer_contact'])): ?>
                        <a class="hero-action-btn outline" href="tel:<?= htmlspecialchars($nd['customer_contact']) ?>">
                            <i class="bi bi-telephone-fill"></i> Call Customer
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT: White details panel -->
            <div class="hero-right">
                <div class="hero-right-header">
                    <div>
                        <div class="hero-right-title">Delivery details</div>
                        <?php if (!empty($nd['tracking_number'])): ?>
                            <div class="hero-right-sub">Tracking no. <?= htmlspecialchars($nd['tracking_number']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="hero-cod-box">
                        <div class="hero-cod-label"><?= $ndIsCOD ? 'Collect COD' : 'Amount' ?></div>
                        <div class="hero-cod-amount">₱<?= number_format($nd['total_amount'], 0) ?></div>
                    </div>
                </div>

                <div class="hero-info-grid">
                    <div class="hero-info-item">
                        <div class="hero-info-icon"><i class="bi bi-clock"></i></div>
                        <div>
                            <div class="hero-info-label">Delivery Time</div>
                            <div class="hero-info-value"><?= $ndTime ?></div>
                        </div>
                    </div>
                    <div class="hero-info-item">
                        <div class="hero-info-icon"><i class="bi bi-box"></i></div>
                        <div>
                            <div class="hero-info-label">Parcel</div>
                            <div class="hero-info-value">Qty <?= (int)$nd['quantity'] ?></div>
                            <div class="hero-info-sub"><?= htmlspecialchars($nd['product_name'] ?? '') ?></div>
                        </div>
                    </div>
                </div>

                <div class="hero-progress-wrap">
                    <div class="hero-progress-label">Delivery Progress</div>
                    <div class="hero-progress-bar">
                        <div class="hero-progress-line">
                            <div class="hero-progress-line-fill" style="width: <?= $fillPercent ?>;"></div>
                        </div>

                        <div class="hero-progress-step done">
                            <div class="hero-progress-dot"><i class="bi bi-check-lg"></i></div>
                            <div class="hero-progress-text">Assigned</div>
                        </div>
                        <div class="hero-progress-step <?= $stepPickedUp ?>">
                            <div class="hero-progress-dot">
                                <?= $stepPickedUp === 'done' ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:.4rem;"></i>' ?>
                            </div>
                            <div class="hero-progress-text">Picked Up</div>
                        </div>
                        <div class="hero-progress-step <?= $stepInTransit ?>">
                            <div class="hero-progress-dot">
                                <?= $stepInTransit === 'done' ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:.4rem;"></i>' ?>
                            </div>
                            <div class="hero-progress-text">In Transit</div>
                        </div>
                        <div class="hero-progress-step <?= $stepDelivered ?>">
                            <div class="hero-progress-dot"><i class="bi bi-circle-fill" style="font-size:.4rem;"></i></div>
                            <div class="hero-progress-text">Delivered</div>
                        </div>
                    </div>

                    <?php if ($ndIsCOD): ?>
                    <div class="hero-cod-alert">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span>Collect ₱<?= number_format($nd['total_amount'], 0) ?> cash and confirm the amount before handing over the parcel.</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- ACTIVE DELIVERIES                                        -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="section-h">
            <div class="section-h-left">
                <h5>Active Deliveries</h5>
            </div>
            <div class="section-h-right">
                <?= count($activeDeliveries) ?> remaining
            </div>
        </div>

        <!-- Search bar -->
        <div class="search-bar">
            <i class="bi bi-search"></i>
            <input type="text" id="searchActive" placeholder="Search reservation, customer or tracking number" oninput="filterActive()">
            <span class="search-hint">⌘ K</span>
        </div>

        <div id="activeList">
            <?php if (empty($activeDeliveries)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No active deliveries. Good job! 🎉</p>
                </div>
            <?php else: ?>
                <?php foreach ($activeDeliveries as $d): ?>
                    <?php
                        $statusMap = [
                            'assigned'   => ['label'=>'Assigned','class'=>'assigned','icon'=>'hourglass-split'],
                            'picked_up'  => ['label'=>'Picked Up','class'=>'picked_up','icon'=>'box-seam'],
                            'in_transit' => ['label'=>'In Transit','class'=>'in_transit','icon'=>'truck'],
                        ];
                        $st = $statusMap[$d['delivery_status']] ?? ['label'=>ucfirst($d['delivery_status']),'class'=>'assigned','icon'=>'circle'];
                        $isCOD = in_array($d['payment_status'], ['cod', 'onsite', 'unpaid', 'partial']);
                        $hasTimeSlot = !empty($d['delivery_time_start']) && !empty($d['delivery_time_end']);
                        $address = trim(($d['delivery_address'] ?? '') . ($d['delivery_barangay'] ? ', '.$d['delivery_barangay'] : '') . ($d['delivery_city'] ? ', '.$d['delivery_city'] : ''));
                        $fullAddress = $address;
                        $timeStr = $hasTimeSlot
                            ? date('g:i A', strtotime($d['delivery_time_start'])) . ' – ' . date('g:i A', strtotime($d['delivery_time_end']))
                            : '—';
                        $tracking = $d['tracking_number'] ?? '';
                    ?>
                    <div class="delivery-row" data-search="<?= htmlspecialchars(strtolower($d['reservation_code'] . ' ' . $d['customer_name'] . ' ' . ($d['customer_contact'] ?? '') . ' ' . $address . ' ' . $tracking)) ?>">

                        <!-- Column 1: ID + Customer + Address -->
                        <div class="delivery-col">
                            <div class="delivery-id-badge">
                                <span class="delivery-id">#<?= htmlspecialchars($d['reservation_code']) ?></span>
                                <span class="delivery-status-tag tag-<?= $st['class'] ?>"><?= $st['label'] ?></span>
                            </div>
                            <div class="delivery-customer-name"><?= htmlspecialchars($d['customer_name']) ?></div>
                            <div class="delivery-address-line">
                                <i class="bi bi-geo-alt"></i>
                                <?= htmlspecialchars($address ?: '—') ?>
                            </div>
                        </div>

                        <!-- Column 2: Delivery Time -->
                        <div class="delivery-col">
                            <div class="delivery-col-label">Delivery Time</div>
                            <div class="delivery-time-value">
                                <i class="bi bi-clock-fill"></i>
                                <?= $timeStr ?>
                            </div>
                            <?php if ($tracking): ?>
                                <div class="delivery-tracking-sub"><?= htmlspecialchars($tracking) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Column 3: Payment -->
                        <div class="delivery-col">
                            <div class="delivery-pay-badge <?= $isCOD ? 'pay-cod' : 'pay-paid' ?>">
                                <?= $isCOD ? 'COD' : 'PAID' ?>
                            </div>
                            <div class="delivery-amount-value">₱<?= number_format($d['total_amount'], 0) ?></div>
                            <div class="delivery-pay-sub">
                                <?= $isCOD ? 'Collect on delivery' : 'No cash required' ?>
                            </div>
                        </div>

                        <!-- Column 4: Actions (icons only) -->
                        <div class="delivery-actions-cell">
                            <?php if (!empty($d['customer_contact'])): ?>
                                <a class="delivery-icon-btn" href="tel:<?= htmlspecialchars($d['customer_contact']) ?>" title="Call">
                                    <i class="bi bi-telephone"></i>
                                </a>
                            <?php endif; ?>

                            <?php if ($d['delivery_status'] === 'assigned'): ?>
                                <button class="delivery-primary-btn" onclick="riderAction('pickup', <?= (int)$d['id'] ?>)">
                                    <i class="bi bi-box-seam"></i> Pick Up
                                </button>
                            <?php elseif ($d['delivery_status'] === 'picked_up'): ?>
                                <button class="delivery-primary-btn" onclick="riderAction('transit', <?= (int)$d['id'] ?>)">
                                    <i class="bi bi-bicycle"></i> Start Transit
                                </button>
                            <?php elseif ($d['delivery_status'] === 'in_transit'): ?>
                                <button class="delivery-primary-btn" onclick='openDeliveryModal(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                    <i class="bi bi-box-seam"></i> Deliver
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- No results -->
        <div class="empty-state" id="noResults-active" style="display:none;">
            <i class="bi bi-search"></i>
            <p>No deliveries match your search.</p>
        </div>

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- DELIVERED TODAY                                          -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <?php if (!empty($completedToday)): ?>
            <div class="section-h">
                <div class="section-h-left">
                    <h5>Delivered Today</h5>
                </div>
                <div class="section-h-right">
                    <?= count($completedToday) ?> completed
                </div>
            </div>

            <?php foreach ($completedToday as $d): ?>
                <?php
                    $hasProof = !empty($d['delivery_proof_image']);
                    $collected = (float)($d['collected_amount'] ?? 0);
                    $address = trim(($d['delivery_barangay'] ?? '') . ($d['delivery_city'] ? ', '.$d['delivery_city'] : ''));
                ?>
                <div class="delivered-row" onclick='viewDelivered(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                    <div class="delivered-thumb">
                        <?php if ($hasProof): ?>
                            <img src="../<?= htmlspecialchars($d['delivery_proof_image']) ?>" alt="">
                        <?php else: ?>
                            <i class="bi bi-box-seam"></i>
                        <?php endif; ?>
                    </div>

                    <div style="min-width:0;">
                        <div class="delivered-name"><?= htmlspecialchars($d['customer_name']) ?></div>
                        <div class="delivered-sub">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars($address ?: '—') ?>
                        </div>
                    </div>

                    <div>
                        <div class="delivered-col-label">Delivery Time</div>
                        <div class="delivered-col-value"><?= date('g:i A', strtotime($d['delivered_at'])) ?></div>
                    </div>

                    <div>
                        <div class="delivered-col-label">Amount</div>
                        <div class="delivered-col-value">₱<?= number_format($d['total_amount'], 0) ?></div>
                    </div>

                    <div>
                        <div class="delivered-col-label">COD Collected</div>
                        <div class="delivered-col-value <?= $collected > 0 ? 'collected' : '' ?>">
                            <?= $collected > 0 ? '₱' . number_format($collected, 0) . ' collected' : 'Prepaid' ?>
                        </div>
                    </div>

                    <div style="text-align:right;">
                        <span class="delivered-status-badge">
                            <i class="bi bi-check-lg"></i>Delivered
                        </span>
                        <div class="delivered-sub" style="justify-content:flex-end;margin-top:4px;font-family:monospace;">
                            #<?= htmlspecialchars($d['reservation_code']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- DELIVERY HISTORY                                         -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <?php if (!empty($deliveryHistory)): ?>
            <div class="section-h">
                <div class="section-h-left">
                    <h5>Delivery History</h5>
                </div>
                <div class="section-h-right">
                    <button type="button">View all history</button>
                </div>
            </div>

            <div class="history-table">
                <div class="history-thead">
                    <div>Date</div>
                    <div>Reservation</div>
                    <div>Customer</div>
                    <div style="text-align:right;">Amount</div>
                    <div style="text-align:right;">Status</div>
                </div>

                <?php foreach ($deliveryHistory as $d): ?>
                    <div class="history-row" onclick='viewDelivered(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <div class="history-cell date"><?= date('M d, Y', strtotime($d['delivered_at'])) ?></div>
                        <div class="history-cell resv">#<?= htmlspecialchars($d['reservation_code']) ?></div>
                        <div class="history-cell"><?= htmlspecialchars($d['customer_name']) ?></div>
                        <div class="history-cell amount">₱<?= number_format($d['total_amount'], 0) ?></div>
                        <div class="history-cell status">
                            <span class="delivered-status-badge">
                                <i class="bi bi-check-lg"></i>Delivered
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ═══ DELIVERY MODAL ═══ -->
    <div class="modal fade" id="deliveryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:none; border-radius:var(--ec-radius); overflow:hidden;">
                <div class="modal-header" style="background:linear-gradient(135deg, var(--ec-primary), var(--ec-primary-dark)); color:white; border:none; padding:1rem 1.15rem;">
                    <h5 class="modal-title" style="font-weight:700; display:flex; align-items:center; gap:8px; font-size:.95rem;">
                        <i class="bi bi-camera"></i> Deliver & Take Photo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:1.1rem;">

                    <div style="background:var(--ec-bg); border-radius:10px; padding:10px 12px; margin-bottom:.875rem;">
                        <div style="font-size:.65rem; color:var(--ec-text-muted); font-weight:700; text-transform:uppercase; letter-spacing:.05em;">Order</div>
                        <div style="font-weight:800; color:var(--ec-primary); font-size:.9rem; font-family:monospace;" id="delOrderCode"></div>
                        <div style="font-size:.8rem; color:var(--ec-text); margin-top:2px;" id="delCustomer"></div>
                    </div>

                    <div id="codCollectionSection" style="display:none;">
                        <div class="cod-total-due">
                            <div class="label">Total Amount Due</div>
                            <div class="amount" id="delTotalDue">₱0.00</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size:.8rem;">
                                <i class="bi bi-cash-stack me-1"></i>
                                Amount Received <span style="color:var(--ec-danger);">*</span>
                            </label>
                            <div class="amount-input-wrap">
                                <span class="currency">₱</span>
                                <input type="number" 
                                       id="amountReceived" 
                                       step="0.01" 
                                       min="0" 
                                       placeholder="0.00"
                                       oninput="validateCollection()">
                            </div>
                        </div>

                        <div id="collectionResult" class="collection-result" style="display:none;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:.8rem;">
                            <i class="bi bi-camera-fill me-1" style="color:var(--ec-danger);"></i>
                            Proof of Delivery <span style="color:var(--ec-danger);">*</span>
                        </label>

                        <div id="proofPreviewWrap" class="proof-preview-wrap" style="display:none;">
                            <img id="proofPreview" src="" alt="Proof of Delivery">
                            <button type="button" class="proof-remove-btn" onclick="clearProof()" title="Remove">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <div id="proofUploadWrap">
                            <label for="proofInput" class="proof-upload-box">
                                <i class="bi bi-camera"></i>
                                <div class="label">Tap to take photo</div>
                                <div class="hint">Take a photo of the parcel with the recipient</div>
                            </label>
                            <input type="file" 
                                   id="proofInput"
                                   accept="image/*"
                                   capture="environment"
                                   style="display:none;"
                                   onchange="previewProof(event)">
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" style="font-size:.8rem; font-weight:600;">
                            Notes <small style="color:var(--ec-text-muted);">(optional)</small>
                        </label>
                        <textarea class="form-control" id="delNotes" rows="2" 
                                  placeholder="e.g. Received by customer, signed..."
                                  style="border-radius:10px; font-size:.85rem;"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--ec-border); padding:.875rem 1.15rem;">
                    <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal" style="border-radius:8px; font-weight:600;">Cancel</button>
                    <button class="btn btn-success btn-sm" id="confirmDeliverBtn" style="border-radius:8px; font-weight:700; background:var(--ec-accent); border-color:var(--ec-accent);">
                        <i class="bi bi-check-lg me-1"></i>Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const API_URL = '../api/reservations.php';
        let deliveryModal;
        let pendingDeliveryId = null;
        let currentTotalDue = 0;
        let currentIsCOD = false;

        document.addEventListener('DOMContentLoaded', function () {
            deliveryModal = new bootstrap.Modal(document.getElementById('deliveryModal'));

            document.getElementById('confirmDeliverBtn')?.addEventListener('click', function () {
                if (!pendingDeliveryId) return;

                const proofInput = document.getElementById('proofInput');
                if (!proofInput.files || proofInput.files.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Photo Required',
                        text: 'Please take a photo of the delivered parcel.',
                        confirmButtonColor: '#0d6e6e'
                    });
                    return;
                }

                let collectedAmount = 0;
                if (currentIsCOD) {
                    collectedAmount = parseFloat(document.getElementById('amountReceived').value) || 0;

                    if (collectedAmount < currentTotalDue - 0.01) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Insufficient Payment',
                            html: `
                                <p>Please collect the <strong>full amount</strong> before proceeding.</p>
                                <div style="background:#fee2e2; padding:12px; border-radius:8px; margin-top:12px; text-align:left; font-size:14px;">
                                    <div style="display:flex; justify-content:space-between;">
                                        <span>Total Due:</span>
                                        <strong>₱${currentTotalDue.toFixed(2)}</strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; margin-top:4px;">
                                        <span>Received:</span>
                                        <strong>₱${collectedAmount.toFixed(2)}</strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:1px dashed #fca5a5; color:#dc2626;">
                                        <strong>Short by:</strong>
                                        <strong>₱${(currentTotalDue - collectedAmount).toFixed(2)}</strong>
                                    </div>
                                </div>
                            `,
                            confirmButtonColor: '#dc2626'
                        });
                        return;
                    }

                    const change = collectedAmount - currentTotalDue;
                    Swal.fire({
                        title: 'Confirm Collection',
                        html: `
                            <div style="text-align:left;">
                                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                                    <span>Total Due:</span>
                                    <strong>₱${currentTotalDue.toFixed(2)}</strong>
                                </div>
                                <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee;">
                                    <span>Received:</span>
                                    <strong>₱${collectedAmount.toFixed(2)}</strong>
                                </div>
                                ${change > 0.01 ? `
                                    <div style="display:flex; justify-content:space-between; padding:12px; margin-top:8px; background:#fef3c7; border-radius:8px;">
                                        <span style="color:#92400e;"><strong>Change:</strong></span>
                                        <strong style="color:#f59e0b; font-size:20px;">₱${change.toFixed(2)}</strong>
                                    </div>
                                    <p style="font-size:12px; color:#6b7280; margin-top:8px;">
                                        <i class="bi bi-info-circle"></i> Make sure to hand the change to the customer.
                                    </p>
                                ` : `
                                    <div style="padding:12px; margin-top:8px; background:#d1fae5; border-radius:8px; text-align:center; color:#065f46;">
                                        <i class="bi bi-check-circle"></i> <strong>Exact payment received</strong>
                                    </div>
                                `}
                            </div>
                        `,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'Yes, Confirm Delivery',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (!result.isConfirmed) return;

                        const notes = document.getElementById('delNotes').value.trim();
                        const file = proofInput.files[0];

                        deliveryModal.hide();
                        riderDeliverWithProof(pendingDeliveryId, file, notes, collectedAmount, change);
                        pendingDeliveryId = null;
                    });
                    return;
                }

                const notes = document.getElementById('delNotes').value.trim();
                const file = proofInput.files[0];

                deliveryModal.hide();
                riderDeliverWithProof(pendingDeliveryId, file, notes, 0, 0);
                pendingDeliveryId = null;
            });
        });

        function refreshPage() { location.reload(); }

        function logout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (result.isConfirmed) {
                    window.location.href = '../admin/logout.php';
                }
            });
        }

        function openDeliveryModal(d) {
            pendingDeliveryId = d.id;
            document.getElementById('delOrderCode').textContent = d.reservation_code;
            document.getElementById('delCustomer').textContent = d.customer_name;

            const isCOD = ['cod', 'onsite', 'unpaid', 'partial'].includes(d.payment_status);
            currentIsCOD = isCOD;
            currentTotalDue = parseFloat(d.total_amount) || 0;

            const collectionSection = document.getElementById('codCollectionSection');

            if (isCOD) {
                collectionSection.style.display = 'block';
                document.getElementById('delTotalDue').textContent = '₱' + currentTotalDue.toFixed(2);
                document.getElementById('amountReceived').value = '';
                document.getElementById('collectionResult').style.display = 'none';
                document.getElementById('confirmDeliverBtn').disabled = true;
            } else {
                collectionSection.style.display = 'none';
                document.getElementById('confirmDeliverBtn').disabled = false;
            }

            clearProof();
            document.getElementById('delNotes').value = '';
            deliveryModal.show();
        }

        function validateCollection() {
            if (!currentIsCOD) return;

            const input = parseFloat(document.getElementById('amountReceived').value) || 0;
            const resultDiv = document.getElementById('collectionResult');
            const confirmBtn = document.getElementById('confirmDeliverBtn');

            resultDiv.style.display = 'none';
            confirmBtn.disabled = true;

            if (input === 0) return;

            if (input < currentTotalDue - 0.01) {
                const short = currentTotalDue - input;
                resultDiv.className = 'collection-result error';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `
                    <div class="title">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        INSUFFICIENT PAYMENT
                    </div>
                    <div class="desc">
                        Short by <strong>₱${short.toFixed(2)}</strong>. Full amount required before proceeding.
                    </div>
                `;
                return;
            }

            if (Math.abs(input - currentTotalDue) < 0.01) {
                resultDiv.className = 'collection-result success';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `
                    <div class="title">
                        <i class="bi bi-check-circle-fill"></i>
                        PAYMENT COMPLETE
                    </div>
                    <div class="desc">
                        Exact amount received. You may proceed with delivery confirmation.
                    </div>
                `;
                confirmBtn.disabled = false;
                return;
            }

            if (input > currentTotalDue + 0.01) {
                const change = input - currentTotalDue;
                resultDiv.className = 'collection-result change';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `
                    <div class="title">
                        <i class="bi bi-info-circle-fill"></i>
                        CHANGE DUE
                    </div>
                    <div class="desc">
                        Please give the change to the customer before leaving.
                    </div>
                    <div class="change-display">
                        <div class="label">Change for Customer</div>
                        <div class="amount">₱${change.toFixed(2)}</div>
                    </div>
                `;
                confirmBtn.disabled = false;
                return;
            }
        }

        function previewProof(event) {
            const file = event.target.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('Too Large', 'Image must be under 5MB.', 'warning');
                event.target.value = '';
                return;
            }

            if (!file.type.startsWith('image/')) {
                Swal.fire('Invalid File', 'Please select an image file.', 'warning');
                event.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('proofPreview').src = e.target.result;
                document.getElementById('proofPreviewWrap').style.display = 'block';
                document.getElementById('proofUploadWrap').style.display = 'none';
            };
            reader.readAsDataURL(file);
        }

        function clearProof() {
            document.getElementById('proofInput').value = '';
            document.getElementById('proofPreview').src = '';
            document.getElementById('proofPreviewWrap').style.display = 'none';
            document.getElementById('proofUploadWrap').style.display = 'block';
        }

        function riderDeliverWithProof(orderId, file, notes, collectedAmount = 0, change = 0) {
            Swal.fire({ 
                title: 'Uploading proof...', 
                allowOutsideClick: false, 
                showConfirmButton: false, 
                didOpen: () => Swal.showLoading() 
            });

            const formData = new FormData();
            formData.append('action', 'rider_deliver');
            formData.append('reservation_id', orderId);
            formData.append('delivery_proof', file);
            formData.append('notes', notes);
            formData.append('collected_amount', collectedAmount);
            formData.append('change_amount', change);

            fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ 
                            icon: 'success', 
                            title: 'Delivered!', 
                            text: data.message, 
                            timer: 1500, 
                            showConfirmButton: false 
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message || 'Something went wrong', 'error');
                    }
                })
                .catch(err => {
                    Swal.close();
                    Swal.fire('Error', 'Network error: ' + err.message, 'error');
                });
        }

        function riderAction(action, orderId) {
            const actionLabels = {
                pickup: 'Mark as Picked Up?',
                transit: 'Start Transit?'
            };

            Swal.fire({
                title: actionLabels[action] || 'Confirm?',
                text: 'Order will be updated.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6e6e',
                confirmButtonText: 'Yes, Confirm',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (!result.isConfirmed) return;

                Swal.fire({ 
                    title: 'Processing...', 
                    allowOutsideClick: false, 
                    showConfirmButton: false, 
                    didOpen: () => Swal.showLoading() 
                });

                const formData = new FormData();
                formData.append('action', 'rider_' + action);
                formData.append('reservation_id', orderId);

                fetch(API_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ 
                                icon: 'success', 
                                title: 'Done!', 
                                text: data.message, 
                                timer: 1500, 
                                showConfirmButton: false 
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', data.message || 'Something went wrong', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.close();
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
            });
        }

        function filterActive() {
            const search = document.getElementById('searchActive').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#activeList .delivery-row');
            let visible = 0;

            rows.forEach(row => {
                const match = !search || row.dataset.search.includes(search);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            document.getElementById('noResults-active').style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
        }

        function viewDelivered(d) {
            const collected = parseFloat(d.collected_amount || 0);
            const hasProof = !!d.delivery_proof_image;
            Swal.fire({
                title: d.reservation_code,
                html: `
                    <div style="text-align:left;font-size:14px;">
                        <div style="padding:8px 0;border-bottom:1px solid #eee;">
                            <strong>Customer:</strong> ${d.customer_name || '—'}
                        </div>
                        <div style="padding:8px 0;border-bottom:1px solid #eee;">
                            <strong>Delivered:</strong> ${new Date(d.delivered_at).toLocaleString()}
                        </div>
                        <div style="padding:8px 0;border-bottom:1px solid #eee;">
                            <strong>Total:</strong> ₱${parseFloat(d.total_amount || 0).toFixed(2)}
                        </div>
                        ${collected > 0 ? `<div style="padding:8px 0;border-bottom:1px solid #eee;color:#065f46;">
                            <strong>Collected:</strong> ₱${collected.toFixed(2)}
                        </div>` : ''}
                        ${hasProof ? `
                            <div style="padding:8px 0;">
                                <strong>Proof:</strong><br>
                                <img src="../${d.delivery_proof_image}" style="max-width:100%;max-height:240px;border-radius:8px;margin-top:6px;border:1px solid #eee;">
                            </div>
                        ` : ''}
                    </div>
                `,
                confirmButtonColor: '#0d6e6e',
                confirmButtonText: 'Close'
            });
        }
    </script>
</body>
</html>