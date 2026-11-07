<?php
// rider/dashboard.php
session_start();

// ✅ Check kung rider ang naka-login
if (!isset($_SESSION['rider_id']) || ($_SESSION['role'] ?? '') !== 'Rider') {
    header("Location: ../suppliers/supplier_login.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';

$rider_id = (int)$_SESSION['rider_id'];
$supplier_id = (int)$_SESSION['supplier_id'];
$rider_name = $_SESSION['rider_name'] ?? 'Rider';

// Get rider info
$stmt = $pdo->prepare("
    SELECT r.*, s.supplier_name
    FROM supplier_riders r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    WHERE r.id = ?
");
$stmt->execute([$rider_id]);
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    header("Location: ../suppliers/logout.php");
    exit();
}

// Get today's shift for initial render
$day_of_week = (int)date('N');
$shiftStmt = $pdo->prepare("
    SELECT time_in, time_out, break_start, break_end, is_active
    FROM supplier_rider_schedules
    WHERE rider_id = ? AND day_of_week = ?
");
$shiftStmt->execute([$rider_id, $day_of_week]);
$todayShift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
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

        /* ═══ HEADER ═══ */
        .rider-header {
            background: linear-gradient(135deg, var(--ec-primary) 0%, var(--ec-primary-dark) 100%);
            color: white;
            padding: .875rem 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            background: rgba(255,255,255,0.2);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .brand-text { display: flex; flex-direction: column; gap: 0; }
        .brand-name { font-size: .95rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; }
        .brand-sub { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; opacity: .8; margin-top: 2px; }

        .header-divider { width: 1px; height: 32px; background: rgba(255,255,255,0.2); margin: 0 4px; }

        .rider-profile { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .rider-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: 800;
            flex-shrink: 0;
            overflow: hidden;
            border: 2px solid rgba(255,255,255,0.3);
        }
        .rider-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .rider-name { font-size: .85rem; font-weight: 700; white-space: nowrap; }
        .rider-role { font-size: .65rem; opacity: .85; font-weight: 600; display: flex; align-items: center; gap: 3px; }

        .on-duty-badge {
            background: rgba(16, 185, 129, 0.95);
            color: #fff;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .58rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .header-actions { display: flex; gap: 6px; align-items: center; }

        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 8px;
            background: rgba(255,255,255,0.15);
            border: none;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
        }
        .icon-btn:hover { background: rgba(255,255,255,0.25); color: #fff; }

        /* ═══ MAIN CONTENT ═══ */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem .75rem 5rem;
        }

        /* ═══ SHIFT BAR ═══ */
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
        }
        .shift-bar::-webkit-scrollbar { display: none; }

        .shift-item { display: flex; flex-direction: column; gap: 2px; white-space: nowrap; flex-shrink: 0; }
        .shift-item-label {
            font-size: .6rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--ec-text-muted);
            font-weight: 700;
        }
        .shift-item-value { font-size: 1rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; }
        .shift-item-sub { font-size: .7rem; color: var(--ec-text-muted); font-weight: 600; }
        .shift-divider { width: 1px; height: 40px; background: var(--ec-border); flex-shrink: 0; }

        /* ═══ STATS CARDS ═══ */
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
            min-width: 0;
        }

        .stat-icon-box {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-icon-box.warning { background: #fef3c7; color: #d97706; }
        .stat-icon-box.info    { background: #dbeafe; color: #2563eb; }
        .stat-icon-box.success { background: #d1fae5; color: #059669; }
        .stat-icon-box.accent  { background: #f0fdf4; color: #008F4C; }

        .stat-content { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .stat-label-new { font-size: .7rem; font-weight: 700; color: var(--ec-text-muted); }
        .stat-value-new { font-size: 1.35rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; color: var(--ec-text); }
        .stat-sub { font-size: .65rem; color: var(--ec-text-muted); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* ═══ SECTION HEADER ═══ */
        .section-h {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1.25rem 0 .75rem;
            gap: .5rem;
        }
        .section-h-left { display: flex; align-items: center; gap: 8px; }
        .section-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--ec-accent); flex-shrink: 0; }
        .section-h h5 {
            font-size: .85rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--ec-text);
        }
        .section-h-right { font-size: .72rem; color: var(--ec-text-muted); font-weight: 600; }
        .section-h-right a, .section-h-right button {
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
        .section-h-right a:hover, .section-h-right button:hover { text-decoration: underline; }

        /* ═══ HERO — NEXT DELIVERY ═══ */
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
            top: -50%; right: -30%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 65%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-left-top { position: relative; z-index: 1; }
        .hero-left-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: .85rem; }
        .hero-resv { font-family: 'Courier New', monospace; font-size: .78rem; font-weight: 800; letter-spacing: .02em; opacity: .9; }
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
        .hero-go-label { font-size: .6rem; text-transform: uppercase; letter-spacing: .1em; font-weight: 700; opacity: .7; margin-bottom: .35rem; }
        .hero-address { font-size: 1.6rem; font-weight: 800; line-height: 1.1; letter-spacing: -0.02em; margin-bottom: .25rem; word-break: break-word; }
        .hero-city { font-size: .82rem; opacity: .85; font-weight: 600; }

        .hero-customer { display: flex; align-items: center; gap: 10px; margin-top: 1rem; position: relative; z-index: 1; }
        .hero-customer-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(255,255,255,.2);
            border: 1.5px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800;
            flex-shrink: 0;
        }
        .hero-customer-info { min-width: 0; }
        .hero-customer-name { font-size: .88rem; font-weight: 700; line-height: 1.15; }
        .hero-customer-phone { font-size: .72rem; opacity: .85; font-family: 'Courier New', monospace; }

        .hero-left-actions { display: flex; gap: .5rem; margin-top: 1rem; position: relative; z-index: 1; }
        .hero-action-btn {
            flex: 1;
            background: #fff;
            color: var(--ec-primary);
            border: none;
            padding: .65rem .8rem;
            border-radius: 9px;
            font-size: .75rem;
            font-weight: 800;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            letter-spacing: .03em;
            text-transform: uppercase;
            font-family: inherit;
            min-height: 42px;
        }
        .hero-action-btn:hover { background: #f0fdf4; color: var(--ec-primary-dark); transform: translateY(-1px); }
        .hero-action-btn.outline { background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.4); }
        .hero-action-btn.outline:hover { background: rgba(255,255,255,.15); color: #fff; }

        .hero-right { padding: 1.25rem 1.35rem; display: flex; flex-direction: column; justify-content: space-between; background: #fff; }
        .hero-right-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
        .hero-right-title { font-size: 1.1rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; }
        .hero-right-sub { font-size: .72rem; color: var(--ec-text-muted); margin-top: 2px; font-weight: 500; }
        .hero-cod-box { text-align: right; flex-shrink: 0; }
        .hero-cod-label { font-size: .58rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 800; color: var(--ec-text-muted); }
        .hero-cod-amount { font-size: 1.5rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.02em; margin-top: 2px; }

        .hero-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .hero-info-item { display: flex; gap: 8px; align-items: flex-start; }
        .hero-info-icon {
            width: 22px; height: 22px; border-radius: 6px;
            background: var(--ec-bg);
            color: var(--ec-primary);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .hero-info-label { font-size: .58rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; color: var(--ec-text-muted); margin-bottom: 3px; }
        .hero-info-value { font-size: .85rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; }
        .hero-info-sub { font-size: .68rem; color: var(--ec-text-muted); margin-top: 1px; }

        .hero-progress-wrap { margin-top: auto; }
        .hero-progress-label { font-size: .58rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; color: var(--ec-text-muted); margin-bottom: .65rem; }
        .hero-progress-bar { display: flex; align-items: center; justify-content: space-between; position: relative; padding: 0 .25rem; }
        .hero-progress-line { position: absolute; top: 11px; left: 20px; right: 20px; height: 2px; background: var(--ec-border); z-index: 0; }
        .hero-progress-line-fill { height: 100%; background: var(--ec-primary); transition: width .3s ease; }
        .hero-progress-step { display: flex; flex-direction: column; align-items: center; gap: 5px; position: relative; z-index: 1; flex: 1; }
        .hero-progress-dot {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid var(--ec-border);
            display: flex; align-items: center; justify-content: center;
            font-size: 10px;
            color: var(--ec-text-muted);
            transition: all .2s;
        }
        .hero-progress-step.done .hero-progress-dot { background: var(--ec-primary); border-color: var(--ec-primary); color: #fff; }
        .hero-progress-step.active .hero-progress-dot {
            background: var(--ec-primary); border-color: var(--ec-primary); color: #fff;
            box-shadow: 0 0 0 4px var(--ec-primary-light);
        }
        .hero-progress-text { font-size: .6rem; font-weight: 700; color: var(--ec-text-muted); text-align: center; white-space: nowrap; }
        .hero-progress-step.done .hero-progress-text, .hero-progress-step.active .hero-progress-text { color: var(--ec-text); }

        .hero-cod-alert {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: .55rem .75rem;
            margin-top: .85rem;
            display: flex; align-items: center; gap: 8px;
            font-size: .72rem;
            font-weight: 600;
            color: #92400e;
        }
        .hero-cod-alert i { font-size: 14px; color: #d97706; flex-shrink: 0; }

        /* ═══ SEARCH BAR ═══ */
        .search-bar { position: relative; margin-bottom: 1rem; }
        .search-bar i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--ec-text-muted); font-size: .9rem; }
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
        .search-bar input:focus { outline: none; border-color: var(--ec-primary); box-shadow: 0 0 0 3px rgba(13,110,110,.08); }

        /* ═══ DELIVERY ROWS ═══ */
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
            cursor: pointer;
        }
        .delivery-row:hover { box-shadow: var(--ec-shadow-md); border-color: #cbd5e1; }
        .delivery-col { min-width: 0; }

        .delivery-id-badge { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 6px; }
        .delivery-id { font-family: 'Courier New', monospace; font-size: .75rem; font-weight: 800; color: var(--ec-primary); letter-spacing: .01em; }
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

        .delivery-customer-name { font-size: 1.05rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; line-height: 1.15; margin-bottom: 3px; }
        .delivery-address-line { font-size: .72rem; color: var(--ec-text-muted); font-weight: 500; display: flex; align-items: center; gap: 4px; }
        .delivery-address-line i { font-size: .7rem; flex-shrink: 0; }

        .delivery-col-label { font-size: .56rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; color: var(--ec-text-muted); margin-bottom: 4px; }
        .delivery-time-value { font-size: .85rem; font-weight: 800; color: var(--ec-text); display: flex; align-items: center; gap: 5px; letter-spacing: -0.01em; }
        .delivery-time-value i { color: var(--ec-primary); font-size: .85rem; }
        .delivery-tracking-sub { font-size: .65rem; color: var(--ec-text-muted); font-family: 'Courier New', monospace; margin-top: 3px; }

        .delivery-pay-badge {
            display: inline-flex; align-items: center; gap: 3px;
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
        .delivery-amount-value { font-size: 1.15rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.02em; line-height: 1.1; }
        .delivery-pay-sub { font-size: .65rem; color: var(--ec-text-muted); margin-top: 3px; font-weight: 500; }

        .delivery-actions-cell { display: flex; gap: .5rem; align-items: center; flex-shrink: 0; }
        .delivery-icon-btn {
            width: 44px; height: 44px;
            border-radius: 10px;
            background: #fff;
            border: 1.5px solid var(--ec-border);
            color: var(--ec-text);
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            flex-shrink: 0;
        }
        .delivery-icon-btn:hover { background: var(--ec-bg); border-color: var(--ec-primary); color: var(--ec-primary); }

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
            display: inline-flex; align-items: center; gap: 7px;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
            font-family: inherit;
        }
        .delivery-primary-btn:hover { background: var(--ec-primary-dark); transform: translateY(-1px); }

        /* ═══ DELIVERED ROWS ═══ */
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
            cursor: pointer;
            transition: all .15s;
            box-shadow: var(--ec-shadow-sm);
        }
        .delivered-row:hover { box-shadow: var(--ec-shadow-md); border-color: #cbd5e1; }
        .delivered-thumb {
            width: 60px; height: 60px;
            border-radius: 10px;
            background: var(--ec-bg);
            display: flex; align-items: center; justify-content: center;
            color: var(--ec-primary);
            font-size: 22px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .delivered-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .delivered-name { font-size: .95rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; line-height: 1.15; margin-bottom: 3px; }
        .delivered-sub { font-size: .7rem; color: var(--ec-text-muted); display: flex; align-items: center; gap: 3px; }
        .delivered-col-label { font-size: .56rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; color: var(--ec-text-muted); margin-bottom: 4px; }
        .delivered-col-value { font-size: .85rem; font-weight: 800; color: var(--ec-text); letter-spacing: -0.01em; }
        .delivered-status-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            display: inline-flex; align-items: center; gap: 3px;
            white-space: nowrap;
        }

        /* ═══ EMPTY STATE ═══ */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--ec-text-muted);
            background: #fff;
            border-radius: 12px;
            border: 2px dashed var(--ec-border);
        }
        .empty-state i { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: .65rem; }
        .empty-state p { margin: 0; font-weight: 600; font-size: .85rem; }

        /* ═══════════════════════════════════════════════════════ */
        /* SIDE DRAWER — DELIVERY DETAILS                          */
        /* ═══════════════════════════════════════════════════════ */
        .drawer-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(2px);
            z-index: 1040;
            opacity: 0;
            visibility: hidden;
            transition: all .25s;
        }
        .drawer-backdrop.open { opacity: 1; visibility: visible; }

        .delivery-drawer {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            max-width: 520px;
            background: #fff;
            box-shadow: -8px 0 32px rgba(15, 23, 42, 0.15);
            z-index: 1045;
            transform: translateX(100%);
            transition: transform .3s cubic-bezier(.4, 0, .2, 1);
            display: flex;
            flex-direction: column;
        }
        .delivery-drawer.open { transform: translateX(0); }

        .drawer-header {
            background: linear-gradient(135deg, var(--ec-primary) 0%, var(--ec-primary-dark) 100%);
            color: #fff;
            padding: 1.1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }
        .drawer-header-title { font-size: 1.05rem; font-weight: 800; letter-spacing: -0.01em; }
        .drawer-header-sub { font-size: .7rem; opacity: .85; margin-top: 2px; font-weight: 600; }
        .drawer-close-btn {
            width: 36px; height: 36px;
            border-radius: 8px;
            background: rgba(255,255,255,0.15);
            border: none;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: all .15s;
            flex-shrink: 0;
        }
        .drawer-close-btn:hover { background: rgba(255,255,255,0.25); }

        .drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem;
        }

        .drawer-section {
            margin-bottom: 1.5rem;
        }
        .drawer-section-title {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
            color: var(--ec-text-muted);
            margin-bottom: .75rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-card {
            background: var(--ec-bg);
            border: 1px solid var(--ec-border);
            border-radius: 10px;
            padding: .875rem 1rem;
        }
        .info-card-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            padding: .4rem 0;
        }
        .info-card-row:not(:last-child) { border-bottom: 1px solid rgba(0,0,0,0.05); }
        .info-card-label { font-size: .72rem; color: var(--ec-text-muted); font-weight: 600; flex-shrink: 0; }
        .info-card-value { font-size: .82rem; color: var(--ec-text); font-weight: 700; text-align: right; word-break: break-word; }

        .items-list { display: flex; flex-direction: column; gap: .5rem; }
        .item-row {
            background: #fff;
            border: 1px solid var(--ec-border);
            border-radius: 8px;
            padding: .65rem .85rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
        }
        .item-info { min-width: 0; flex: 1; }
        .item-name { font-size: .82rem; font-weight: 700; color: var(--ec-text); margin-bottom: 2px; }
        .item-desc { font-size: .68rem; color: var(--ec-text-muted); }
        .item-qty {
            background: var(--ec-primary-light);
            color: var(--ec-primary);
            padding: 3px 10px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .handover-photos-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .5rem;
        }
        .handover-photo {
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #86efac;
            background: #f0fdf4;
            cursor: pointer;
            transition: all .15s;
            position: relative;
        }
        .handover-photo:hover { transform: scale(1.03); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .handover-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .handover-photo-badge {
            position: absolute;
            top: 4px; left: 4px;
            width: 20px; height: 20px;
            background: rgba(13, 110, 110, 0.9);
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .6rem;
            font-weight: 800;
        }

        .drawer-actions {
            display: flex;
            flex-direction: column;
            gap: .6rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 2px dashed var(--ec-border);
        }

        .action-btn {
            width: 100%;
            min-height: 54px;
            border: none;
            border-radius: 12px;
            font-size: .9rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all .15s;
            font-family: inherit;
            padding: .85rem 1.25rem;
        }
        .action-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.15); }
        .action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .action-btn.start {
            background: linear-gradient(135deg, var(--ec-primary) 0%, var(--ec-primary-dark) 100%);
            color: #fff;
        }
        .action-btn.deliver {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
        }
        .action-btn.fail {
            background: #fff;
            color: var(--ec-danger);
            border: 2px solid var(--ec-danger);
        }
        .action-btn.fail:hover:not(:disabled) { background: #fef2f2; }

        .action-btn i { font-size: 1.2rem; }

        /* ═══ MOBILE ═══ */
        @media (max-width: 991.98px) {
            .hero-delivery { grid-template-columns: 1fr; }
            .hero-left { min-height: auto; }
            .hero-address { font-size: 1.35rem; }
            .delivery-row { grid-template-columns: 1fr 1fr; gap: .75rem; }
            .delivery-actions-cell { grid-column: 1 / -1; justify-content: flex-end; padding-top: .5rem; border-top: 1px dashed var(--ec-border); }
            .delivered-row { grid-template-columns: 60px 1fr 1fr auto; gap: .75rem; }
            .delivered-row > *:nth-child(3), .delivered-row > *:nth-child(4) { display: none; }
        }

        @media (max-width: 767.98px) {
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
            .hero-right { padding: 1rem 1.1rem; }
            .hero-right-title { font-size: .95rem; }
            .hero-cod-amount { font-size: 1.25rem; }
            .hero-info-grid { gap: .75rem; }
            .hero-info-value { font-size: .78rem; }
            .hero-action-btn { font-size: .68rem; padding: .55rem .6rem; min-height: 38px; }
            .delivery-row { grid-template-columns: 1fr; gap: .75rem; padding: .85rem .95rem; }
            .delivery-customer-name { font-size: .95rem; }
            .delivery-amount-value { font-size: 1.05rem; }
            .delivered-row { grid-template-columns: 48px 1fr auto; gap: .65rem; padding: .75rem .85rem; }
            .delivered-thumb { width: 48px; height: 48px; font-size: 18px; }
            .delivered-name { font-size: .85rem; }
        }

        @media (max-width: 576px) {
            .delivery-drawer { max-width: 100%; }
            .handover-photos-grid { grid-template-columns: repeat(2, 1fr); }
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--ec-border); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--ec-text-muted); }

        /* ✅ Camera modal — dapat nasa TAAS ng Mark Delivered modal */
#cameraModal {
    z-index: 1090 !important;
}
#cameraModal .modal-dialog {
    z-index: 1090 !important;
}
#cameraModal .modal-content {
    z-index: 1090 !important;
}
#cameraModal .modal-backdrop {
    z-index: 1085 !important;
}

/* Mark Delivered modal — mas mababa */
#markDeliveredModal {
    z-index: 1055;
}
    
    </style>
</head>
<body>

    <!-- ═══ HEADER ═══ -->
    <div class="rider-header">
        <div class="rider-header-inner">
            <div class="header-left">
                <div class="brand-logo">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div class="brand-text">
                    <div class="brand-name">Eyecore</div>
                    <div class="brand-sub">Rider</div>
                </div>
                <div class="header-divider"></div>
                <div class="rider-profile">
                    <div class="rider-avatar">
                        <?= strtoupper(substr($rider_name, 0, 1)) ?>
                    </div>
                    <div class="rider-info">
                        <div class="rider-name">
                            <?= htmlspecialchars($rider_name) ?>
                            <span class="on-duty-badge">On Duty</span>
                        </div>
                        <div class="rider-role">
                            <i class="bi bi-bicycle"></i> Delivery Rider
                        </div>
                    </div>
                </div>
            </div>
        <div class="header-actions">
            <!-- ✅ MESSAGES ICON -->
            <button class="icon-btn" onclick="toggleRiderChat()" title="Messages" style="position:relative;">
                <i class="bi bi-chat-dots"></i>
                <span id="riderChatBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size:0.55rem; padding:2px 4px; border:1.5px solid #fff;">
                    0
                </span>
            </button>
            
            <button class="icon-btn" onclick="loadAll()" title="Refresh">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <a href="logout.php" class="icon-btn" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
        </div>
    </div>

    <div class="main-content">

        <!-- ═══ SHIFT BAR ═══ -->
        <div class="shift-bar" id="shiftBar">
            <div class="shift-item">
                <div class="shift-item-label">Today's Shift</div>
                <div class="shift-item-sub"><?= date('l, F j') ?></div>
            </div>
            <div class="shift-divider"></div>
            <div class="shift-item">
                <div class="shift-item-label">Loading</div>
                <div class="shift-item-value">—</div>
            </div>
        </div>

        <!-- ═══ STATS CARDS ═══ -->
        <div class="stats-row">
            <div class="stat-card-new">
                <div class="stat-icon-box warning">
                    <i class="bi bi-clipboard-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">Assigned</div>
                    <div class="stat-value-new" id="statAssigned">—</div>
                    <div class="stat-sub">Deliveries remaining</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box info">
                    <i class="bi bi-bicycle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">In Transit</div>
                    <div class="stat-value-new" id="statTransit">—</div>
                    <div class="stat-sub">Currently on the road</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box success">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">Delivered</div>
                    <div class="stat-value-new" id="statToday">—</div>
                    <div class="stat-sub">Completed today</div>
                </div>
            </div>
            <div class="stat-card-new">
                <div class="stat-icon-box accent">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label-new">Total</div>
                    <div class="stat-value-new" id="statTotal">—</div>
                    <div class="stat-sub">All deliveries</div>
                </div>
            </div>
        </div>

        <!-- ═══ PERFORMANCE CARD ═══ -->
        <div class="section-h">
            <div class="section-h-left">
                <h5>Performance (This Month)</h5>
            </div>
        </div>
        <div style="background:linear-gradient(135deg,#064e3b 0%,#065f46 100%);color:#fff;border-radius:16px;padding:1.25rem;box-shadow:0 4px 12px rgba(6,78,59,0.3);margin-bottom:1.25rem;">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;opacity:.85;font-weight:700;margin-bottom:.75rem;">📊 Your Stats</div>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;">
                <div style="text-align:center;">
                    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;" id="perfDeliveries">—</div>
                    <div style="font-size:.65rem;opacity:.8;margin-top:.25rem;text-transform:uppercase;letter-spacing:.03em;">Deliveries</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;" id="perfOnTime">—</div>
                    <div style="font-size:.65rem;opacity:.8;margin-top:.25rem;text-transform:uppercase;letter-spacing:.03em;">On-Time</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;" id="perfSuccess">—</div>
                    <div style="font-size:.65rem;opacity:.8;margin-top:.25rem;text-transform:uppercase;letter-spacing:.03em;">Success</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.5rem;font-weight:800;line-height:1.1;" id="perfRating">—</div>
                    <div style="font-size:.65rem;opacity:.8;margin-top:.25rem;text-transform:uppercase;letter-spacing:.03em;">Rating</div>
                </div>
            </div>
        </div>

        <!-- ═══ NEXT DELIVERY HERO ═══ -->
        <div class="section-h">
            <div class="section-h-left">
                <span class="section-dot"></span>
                <h5>Next Delivery</h5>
            </div>
            <div class="section-h-right" id="nextDeliveryMeta">—</div>
        </div>
        <div id="nextDeliveryContainer">
            <div class="empty-state">
                <div class="spinner-border text-teal"></div>
                <p class="mt-2">Loading...</p>
            </div>
        </div>

        <!-- ═══ ACTIVE DELIVERIES ═══ -->
        <div class="section-h">
            <div class="section-h-left">
                <h5>Active Deliveries</h5>
            </div>
            <div class="section-h-right">
                <span id="activeCount">0</span> remaining
            </div>
        </div>

        <div class="search-bar">
            <i class="bi bi-search"></i>
            <input type="text" id="searchActive" placeholder="Search by PO number, customer, or address" oninput="filterActive()">
        </div>

        <div id="activeList">
            <div class="empty-state">
                <div class="spinner-border text-teal"></div>
                <p class="mt-2">Loading...</p>
            </div>
        </div>

        <div class="empty-state" id="noResults-active" style="display:none;">
            <i class="bi bi-search"></i>
            <p>No deliveries match your search.</p>
        </div>

        <!-- ═══ DELIVERED TODAY ═══ -->
        <div class="section-h">
            <div class="section-h-left">
                <h5>Delivered Today</h5>
            </div>
            <div class="section-h-right">
                <span id="deliveredCount">0</span> completed
            </div>
        </div>
        <div id="deliveredTodayContainer">
            <div class="empty-state">
                <div class="spinner-border text-teal"></div>
                <p class="mt-2">Loading...</p>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- SIDE DRAWER — DELIVERY DETAILS                          -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
    <div class="delivery-drawer" id="deliveryDrawer">
        <div class="drawer-header">
            <div>
                <div class="drawer-header-title" id="drawerTitle">—</div>
                <div class="drawer-header-sub" id="drawerSubtitle">—</div>
            </div>
            <button class="drawer-close-btn" onclick="closeDrawer()" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="drawer-body" id="drawerBody">
            <div class="text-center py-5">
                <div class="spinner-border text-teal"></div>
                <p class="mt-2 text-muted">Loading...</p>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- LIVE CAMERA MODAL (for Mark Delivered)                  -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static" style="z-index: 1090;">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
                <div class="modal-header" style="background:#000;color:#fff;border:none;padding:.75rem 1rem;">
                    <h6 class="modal-title" style="font-size:.9rem;">
                        <i class="bi bi-record-circle text-danger me-2"></i>Live Camera — Delivery Proof
                    </h6>
                    <button type="button" class="btn-close btn-close-white" onclick="closeCamera()"></button>
                </div>
                <div class="modal-body" style="background:#000;padding:0;position:relative;">
                    <video id="cameraStream" autoplay playsinline style="width:100%;display:block;max-height:65vh;object-fit:contain;"></video>
                    <canvas id="cameraCanvas" style="display:none;"></canvas>
                    <div style="position:absolute;top:1rem;left:1rem;background:rgba(0,0,0,0.65);color:#fff;padding:6px 12px;border-radius:999px;font-size:.75rem;font-weight:800;display:flex;align-items:center;gap:6px;">
                        <i class="bi bi-camera"></i>
                        <span id="photoCounter">0 / 5</span>
                    </div>
                </div>
                <div class="modal-footer" style="background:#000;border:none;justify-content:center;padding:1rem;">
                    <button type="button" class="ship-capture-btn" onclick="capturePhoto()" 
                            style="width:70px;height:70px;border-radius:50%;background:#fff;border:4px solid #0d9488;display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <i class="bi bi-camera-fill" style="font-size:1.8rem;color:#0d9488;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MARK DELIVERED MODAL                                    -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal fade" id="markDeliveredModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
                <div class="modal-header" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;">
                    <h5 class="modal-title" style="font-size:1rem;font-weight:800;">
                        <i class="bi bi-check-circle-fill me-2"></i>Confirm Delivery
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:1.25rem;">

                    <!-- Order Info -->
                    <div style="background:var(--ec-bg);border-radius:10px;padding:.875rem 1rem;margin-bottom:1rem;">
                        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;font-weight:800;color:var(--ec-text-muted);margin-bottom:4px;">Order</div>
                        <div style="font-weight:800;color:var(--ec-primary);font-size:.95rem;font-family:monospace;" id="mdOrderCode">—</div>
                        <div style="font-size:.82rem;color:var(--ec-text);margin-top:2px;" id="mdCustomer">—</div>
                    </div>

                    <!-- Photo Proof Preview -->
                    <div class="mb-3">
                        <label style="font-size:.8rem;font-weight:700;display:block;margin-bottom:.5rem;">
                            <i class="bi bi-camera-fill me-1" style="color:var(--ec-danger);"></i>
                            Delivery Photo <span style="color:var(--ec-danger);">*</span>
                        </label>
                        
                        <div id="mdPhotoPreviewWrap" style="display:none;border-radius:10px;overflow:hidden;border:2px solid var(--ec-accent);position:relative;">
                            <img id="mdPhotoPreview" src="" style="width:100%;max-height:220px;object-fit:cover;display:block;">
                            <button type="button" onclick="retakePhoto()" style="position:absolute;top:8px;right:8px;background:rgba(220,53,69,0.9);color:#fff;border:none;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </div>
                        
                        <div id="mdPhotoUploadWrap">
                            <button type="button" onclick="openCameraFromDelivered()" style="width:100%;background:var(--ec-bg);border:2px dashed var(--ec-border);border-radius:10px;padding:1.5rem 1rem;text-align:center;cursor:pointer;transition:all .15s;">
                                <i class="bi bi-camera-video" style="font-size:1.75rem;color:var(--ec-primary);display:block;margin-bottom:.5rem;"></i>
                                <div style="font-weight:700;font-size:.85rem;color:var(--ec-text);">Take Photo Proof</div>
                                <div style="font-size:.7rem;color:var(--ec-text-muted);margin-top:2px;">Live camera only — no gallery</div>
                            </button>
                        </div>
                    </div>

                    <!-- COD Section -->
                    <div id="mdCodSection" style="display:none;margin-bottom:1rem;">
                        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;border-radius:10px;padding:12px;margin-bottom:1rem;text-align:center;">
                            <div style="font-size:.65rem;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Total Amount Due</div>
                            <div style="font-size:1.5rem;font-weight:800;color:#92400e;margin-top:2px;" id="mdTotalDue">₱0.00</div>
                        </div>

                        <label style="font-size:.8rem;font-weight:700;display:block;margin-bottom:.5rem;">
                            <i class="bi bi-cash-stack me-1"></i>
                            Amount Received <span style="color:var(--ec-danger);">*</span>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-weight:800;color:var(--ec-text-muted);">₱</span>
                            <input type="number" id="mdAmountReceived" step="0.01" min="0" placeholder="0.00"
                                   style="width:100%;padding:.75rem 1rem .75rem 2.25rem;border:2px solid var(--ec-border);border-radius:10px;font-size:1.15rem;font-weight:800;font-family:inherit;"
                                   oninput="validateCollection()">
                        </div>

                        <div id="mdCollectionResult" style="margin-top:.75rem;display:none;border-radius:10px;padding:.75rem 1rem;"></div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label style="font-size:.8rem;font-weight:700;display:block;margin-bottom:.5rem;">
                            Notes <span style="color:var(--ec-text-muted);font-weight:500;">(optional)</span>
                        </label>
                        <textarea id="mdNotes" rows="2" placeholder="e.g. Received by customer, signed..."
                                  style="width:100%;border:1px solid var(--ec-border);border-radius:10px;padding:.65rem .85rem;font-size:.85rem;font-family:inherit;resize:vertical;"></textarea>
                    </div>

                </div>
                <div class="modal-footer" style="border-top:1px solid var(--ec-border);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:8px;font-weight:600;">Cancel</button>
                    <button type="button" class="btn" id="mdConfirmBtn" onclick="submitDelivered()" disabled
                            style="border-radius:8px;font-weight:800;background:var(--ec-accent);color:#fff;border:none;padding:.55rem 1.5rem;">
                        <i class="bi bi-check-lg me-1"></i>Confirm Delivered
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- REPORT FAILED MODAL                                     -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <div class="modal fade" id="reportFailedModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
                <div class="modal-header" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;">
                    <h5 class="modal-title" style="font-size:1rem;font-weight:800;">
                        <i class="bi bi-x-circle-fill me-2"></i>Report Failed Delivery
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:1.25rem;">
                    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.8rem;color:#991b1b;font-weight:600;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        I-report ang dahilan kung bakit hindi na-deliver.
                    </div>

                    <label style="font-size:.8rem;font-weight:700;display:block;margin-bottom:.5rem;">
                        Reason <span style="color:var(--ec-danger);">*</span>
                    </label>
                    <select id="rfReason" style="width:100%;border:1px solid var(--ec-border);border-radius:10px;padding:.65rem .85rem;font-size:.85rem;font-family:inherit;background:#fff;">
                        <option value="">Select reason</option>
                        <option value="Clinic closed">Clinic closed</option>
                        <option value="No one to receive">No one to receive</option>
                        <option value="Wrong address">Wrong address</option>
                        <option value="Customer refused">Customer refused</option>
                        <option value="Package damaged">Package damaged</option>
                        <option value="Cannot contact customer">Cannot contact customer</option>
                        <option value="Other">Other</option>
                    </select>

                    <div style="margin-top:1rem;">
                        <label style="font-size:.8rem;font-weight:700;display:block;margin-bottom:.5rem;">
                            Additional Notes <span style="color:var(--ec-text-muted);font-weight:500;">(optional)</span>
                        </label>
                        <textarea id="rfNotes" rows="3" placeholder="e.g. Clinic is closed, no one answered the door..."
                                  style="width:100%;border:1px solid var(--ec-border);border-radius:10px;padding:.65rem .85rem;font-size:.85rem;font-family:inherit;resize:vertical;"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--ec-border);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:8px;font-weight:600;">Cancel</button>
                    <button type="button" class="btn" onclick="submitFailed()"
                            style="border-radius:8px;font-weight:800;background:var(--ec-danger);color:#fff;border:none;padding:.55rem 1.5rem;">
                        <i class="bi bi-x-lg me-1"></i>Submit Report
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ═══════════════════════════════════════════════════════
        // CONFIG
        // ═══════════════════════════════════════════════════════
        const API = '../api/supplier_rider.php';
        
        // Global state
        window._activeDeliveries = [];
        window._currentDelivery = null;
        window._pendingDeliveryId = null;
        window._currentTotalDue = 0;
        window._currentIsCOD = false;
        window._capturedBlob = null;
        window._cameraStream = null;
        window._cameraModal = null;
        window._markDeliveredModal = null;
        window._reportFailedModal = null;
        window._photoFiles = [];
        const MAX_PHOTOS = 5;

        // ═══════════════════════════════════════════════════════
        // LOAD ALL DATA
        // ═══════════════════════════════════════════════════════
        function loadAll() {
            loadShiftInfo();
            loadPerformance();
            loadStats();
            loadDeliveries();
        }

        // ═══════════════════════════════════════════════════════
        // SHIFT INFO
        // ═══════════════════════════════════════════════════════
        function loadShiftInfo() {
            fetch(`${API}?action=shift_info`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const s = data.data;
                    const bar = document.getElementById('shiftBar');
                    
                    if (s.is_day_off) {
                        bar.innerHTML = `
                            <div class="shift-item">
                                <div class="shift-item-label">Today's Shift</div>
                                <div class="shift-item-sub">${s.day_name}</div>
                            </div>
                            <div class="shift-divider"></div>
                            <div class="shift-item">
                                <div class="shift-item-label">Status</div>
                                <div class="shift-item-value">Day Off</div>
                            </div>
                        `;
                    } else {
                        bar.innerHTML = `
                            <div class="shift-item">
                                <div class="shift-item-label">Today's Shift</div>
                                <div class="shift-item-sub">${s.day_name}</div>
                            </div>
                            <div class="shift-divider"></div>
                            <div class="shift-item">
                                <div class="shift-item-label">Time In</div>
                                <div class="shift-item-value">${s.time_in}</div>
                            </div>
                            <div class="shift-divider"></div>
                            ${s.break_start ? `
                            <div class="shift-item">
                                <div class="shift-item-label">Break</div>
                                <div class="shift-item-value">${s.break_start}</div>
                            </div>
                            <div class="shift-divider"></div>
                            ` : ''}
                            <div class="shift-item">
                                <div class="shift-item-label">Time Out</div>
                                <div class="shift-item-value">${s.time_out}</div>
                            </div>
                            <div class="shift-divider"></div>
                            <div class="shift-item">
                                <div class="shift-item-label">Hours</div>
                                <div class="shift-item-value">${s.hours}h</div>
                            </div>
                        `;
                    }
                })
                .catch(err => console.error('Shift error:', err));
        }

        // ═══════════════════════════════════════════════════════
        // PERFORMANCE
        // ═══════════════════════════════════════════════════════
        function loadPerformance() {
            fetch(`${API}?action=performance`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const p = data.data;
                    document.getElementById('perfDeliveries').textContent = p.deliveries_this_month || 0;
                    document.getElementById('perfOnTime').textContent = (p.on_time_rate || 0) + '%';
                    document.getElementById('perfSuccess').textContent = (p.success_rate || 0) + '%';
                    document.getElementById('perfRating').textContent = '⭐ ' + (p.rating || 0).toFixed(1);
                })
                .catch(err => console.error('Performance error:', err));
        }

        // ═══════════════════════════════════════════════════════
        // STATS
        // ═══════════════════════════════════════════════════════
        function loadStats() {
            fetch(`${API}?action=stats`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const s = data.data;
                    document.getElementById('statAssigned').textContent = s.assigned || 0;
                    document.getElementById('statTransit').textContent = s.in_transit || 0;
                    document.getElementById('statToday').textContent = s.delivered_today || 0;
                    document.getElementById('statTotal').textContent = s.total || 0;
                })
                .catch(err => console.error('Stats error:', err));
        }

function loadDeliveries() {
    // Active
    fetch(`${API}?action=my_deliveries&status=active`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const deliveries = data.data || [];
            window._activeDeliveries = deliveries;
            
            document.getElementById('activeCount').textContent = deliveries.length;
            
            renderNextDelivery(deliveries[0] || null);
            renderActiveDeliveries(deliveries);
        })
        .catch(err => console.error('Active error:', err));
    
    // ✅ Completed today — use LOCAL date
    fetch(`${API}?action=my_deliveries&status=completed`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            // ✅ Get today's date in LOCAL timezone (not UTC)
            const now = new Date();
            const today = now.getFullYear() + '-' + 
                         String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                         String(now.getDate()).padStart(2, '0');
            
            console.log('Today (local):', today);
            
            const todayDeliveries = (data.data || []).filter(d => {
                if (!d.actual_delivery) return false;
                const deliveryDate = d.actual_delivery.substring(0, 10);
                return deliveryDate === today;
            });
            
            console.log('Delivered today:', todayDeliveries.length, 'of', (data.data || []).length);
            
            document.getElementById('deliveredCount').textContent = todayDeliveries.length;
            renderDeliveredToday(todayDeliveries);
        })
        .catch(err => console.error('Completed error:', err));
}
        // ═══════════════════════════════════════════════════════
        // RENDER: Next Delivery Hero
        // ═══════════════════════════════════════════════════════
        function renderNextDelivery(d) {
            const container = document.getElementById('nextDeliveryContainer');
            const meta = document.getElementById('nextDeliveryMeta');
            
            if (!d) {
                meta.textContent = '—';
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-check-circle" style="color:#10b981;"></i>
                        <p>All caught up! 🎉<br>Walang pending deliveries sa ngayon.</p>
                    </div>
                `;
                return;
            }
            
            meta.textContent = `Deliver by ${d.scheduled_time_end ? d.scheduled_time_end.substring(0,5) : '—'}`;
            
            const timeWindow = d.time_window || '—';
            const codLabel = d.status === 'To Ship' ? 'Amount' : 'Collect COD';
            
            container.innerHTML = `
                <div class="hero-delivery" onclick="openDrawer(${d.id})" style="cursor:pointer;">
                    <div class="hero-left">
                        <div class="hero-left-top">
                            <div class="hero-left-header">
                                <div class="hero-resv">${escapeHtml(d.po_number)}</div>
                                <div class="hero-status-badge">${(d.rider_status || 'Assigned').toUpperCase()}</div>
                            </div>
                            <div class="hero-go-label">GO TO</div>
                            <div class="hero-address">${escapeHtml(d.clinic_name || d.department || 'Clinic')}</div>
                            <div class="hero-city">${escapeHtml(d.clinic_address || 'Address N/A')}</div>
                        </div>
                        <div>
                            <div class="hero-customer">
                                <div class="hero-customer-avatar">${escapeHtml((d.clinic_name || 'C').substring(0,1).toUpperCase())}</div>
                                <div class="hero-customer-info">
                                    <div class="hero-customer-name">${escapeHtml(d.clinic_name || d.department || '—')}</div>
                                    <div class="hero-customer-phone">${escapeHtml(d.clinic_phone || '—')}</div>
                                </div>
                            </div>
                            <div class="hero-left-actions">
                                <button class="hero-action-btn" onclick="event.stopPropagation(); navigateTo('${escapeHtml(d.clinic_address || '').replace(/'/g, "\\'")}')">
                                    <i class="bi bi-send-fill"></i> Navigate
                                </button>
                                ${d.clinic_phone ? `
                                <a class="hero-action-btn outline" href="tel:${escapeHtml(d.clinic_phone)}" onclick="event.stopPropagation();">
                                    <i class="bi bi-telephone-fill"></i> Call
                                </a>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="hero-right">
                        <div class="hero-right-header">
                            <div>
                                <div class="hero-right-title">Delivery Details</div>
                                ${d.tracking_number ? `<div class="hero-right-sub">Tracking: ${escapeHtml(d.tracking_number)}</div>` : ''}
                            </div>
                            <div class="hero-cod-box">
                                <div class="hero-cod-label">${codLabel}</div>
                                <div class="hero-cod-amount">₱${parseFloat(d.total_amount || 0).toFixed(0)}</div>
                            </div>
                        </div>
                        <div class="hero-info-grid">
                            <div class="hero-info-item">
                                <div class="hero-info-icon"><i class="bi bi-clock"></i></div>
                                <div>
                                    <div class="hero-info-label">Time Window</div>
                                    <div class="hero-info-value">${escapeHtml(timeWindow)}</div>
                                </div>
                            </div>
                            <div class="hero-info-item">
                                <div class="hero-info-icon"><i class="bi bi-box"></i></div>
                                <div>
                                    <div class="hero-info-label">PO Number</div>
                                    <div class="hero-info-value">${escapeHtml(d.pr_number || '—')}</div>
                                </div>
                            </div>
                        </div>
                        <div class="hero-progress-wrap">
                            <div class="hero-progress-label">Progress</div>
                            <div class="hero-progress-bar">
                                <div class="hero-progress-line"><div class="hero-progress-line-fill" style="width:${(d.rider_status === 'In Transit') ? '66%' : (d.rider_status === 'Picked Up' ? '33%' : '0%')};"></div></div>
                                <div class="hero-progress-step done">
                                    <div class="hero-progress-dot"><i class="bi bi-check-lg"></i></div>
                                    <div class="hero-progress-text">Assigned</div>
                                </div>
<div class="hero-progress-step ${(d.rider_status === 'In Transit' || d.rider_status === 'Picked Up') ? 'done' : 'active'}">
    <div class="hero-progress-dot">${(d.rider_status === 'In Transit' || d.rider_status === 'Picked Up') ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle-fill" style="font-size:.4rem;"></i>'}</div>
    <div class="hero-progress-text">Picked Up</div>
</div>
<div class="hero-progress-step ${d.rider_status === 'In Transit' ? 'active' : ''}">
    <div class="hero-progress-dot"><i class="bi bi-circle-fill" style="font-size:.4rem;"></i></div>
    <div class="hero-progress-text">Transit</div>
</div>
                                <div class="hero-progress-step">
                                    <div class="hero-progress-dot"><i class="bi bi-circle-fill" style="font-size:.4rem;"></i></div>
                                    <div class="hero-progress-text">Delivered</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // ═══════════════════════════════════════════════════════
        // RENDER: Active Deliveries List
        // ═══════════════════════════════════════════════════════
        function renderActiveDeliveries(deliveries) {
            const container = document.getElementById('activeList');
            
            if (!deliveries || deliveries.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No active deliveries</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            deliveries.forEach(d => {
const riderStatus = d.rider_status || d.status;
let statusClass = 'tag-assigned';
let statusLabel = 'ASSIGNED';
if (riderStatus === 'In Transit') {
    statusClass = 'tag-in_transit';
    statusLabel = 'IN TRANSIT';
} else if (riderStatus === 'Picked Up') {
    statusClass = 'tag-picked_up';
    statusLabel = 'PICKED UP';
}
                
                html += `
                    <div class="delivery-row" 
                         data-search="${escapeHtml((d.po_number + ' ' + (d.clinic_name || '') + ' ' + (d.clinic_address || '')).toLowerCase())}"
                         onclick="openDrawer(${d.id})">
                        <div class="delivery-col">
                            <div class="delivery-id-badge">
                                <span class="delivery-id">${escapeHtml(d.po_number)}</span>
                                <span class="delivery-status-tag ${statusClass}">${statusLabel}</span>
                            </div>
                            <div class="delivery-customer-name">${escapeHtml(d.clinic_name || d.department || '—')}</div>
                            <div class="delivery-address-line">
                                <i class="bi bi-geo-alt"></i>
                                ${escapeHtml(d.clinic_address || '—')}
                            </div>
                        </div>
                        <div class="delivery-col">
                            <div class="delivery-col-label">Delivery Time</div>
                            <div class="delivery-time-value">
                                <i class="bi bi-clock-fill"></i>
                                ${escapeHtml(d.time_window || '—')}
                            </div>
                            ${d.tracking_number ? `<div class="delivery-tracking-sub">${escapeHtml(d.tracking_number)}</div>` : ''}
                        </div>
                        <div class="delivery-col">
                            <div class="delivery-pay-badge pay-cod">AMOUNT</div>
                            <div class="delivery-amount-value">₱${parseFloat(d.total_amount || 0).toFixed(0)}</div>
                            <div class="delivery-pay-sub">Confirm on delivery</div>
                        </div>
                        <div class="delivery-actions-cell">
                            ${d.clinic_phone ? `
                            <a class="delivery-icon-btn" href="tel:${escapeHtml(d.clinic_phone)}" onclick="event.stopPropagation();" title="Call">
                                <i class="bi bi-telephone"></i>
                            </a>
                            ` : ''}
                            <button class="delivery-primary-btn" onclick="event.stopPropagation(); openDrawer(${d.id})">
                                <i class="bi bi-eye"></i> View
                            </button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // ═══════════════════════════════════════════════════════
        // RENDER: Delivered Today
        // ═══════════════════════════════════════════════════════
        function renderDeliveredToday(deliveries) {
            const container = document.getElementById('deliveredTodayContainer');
            
            if (!deliveries || deliveries.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-box-open"></i>
                        <p>Wala pang delivered today</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            deliveries.forEach(d => {
                const time = d.actual_delivery 
                    ? new Date(d.actual_delivery).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
                    : '';
                
                html += `
                    <div class="delivered-row" onclick="openDrawer(${d.id})">
                        <div class="delivered-thumb">
                            <i class="bi bi-check-circle" style="color:#10b981;"></i>
                        </div>
                        <div>
                            <div class="delivered-name">${escapeHtml(d.clinic_name || d.department || '—')}</div>
                            <div class="delivered-sub">
                                <i class="bi bi-geo-alt"></i> ${escapeHtml(d.clinic_address || '—')}
                            </div>
                        </div>
                        <div>
                            <div class="delivered-col-label">PO Number</div>
                            <div class="delivered-col-value">${escapeHtml(d.po_number)}</div>
                        </div>
                        <div>
                            <div class="delivered-col-label">Delivered At</div>
                            <div class="delivered-col-value">${time}</div>
                        </div>
                        <div>
                            <div class="delivered-col-label">Amount</div>
                            <div class="delivered-col-value">₱${parseFloat(d.total_amount || 0).toFixed(0)}</div>
                        </div>
                        <div style="text-align:right;">
                            <span class="delivered-status-badge">
                                <i class="bi bi-check-lg"></i>Delivered
                            </span>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // ═══════════════════════════════════════════════════════
        // OPEN DRAWER — Delivery Details
        // ═══════════════════════════════════════════════════════
        function openDrawer(deliveryId) {
            const d = window._activeDeliveries.find(x => x.id == deliveryId);
            if (!d) {
                // Try fetch from API (kung delivered na at wala sa active list)
                fetchDeliveryAndShow(deliveryId);
                return;
            }
            
            window._currentDelivery = d;
            renderDrawerContent(d);
            
            document.getElementById('drawerBackdrop').classList.add('open');
            document.getElementById('deliveryDrawer').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function fetchDeliveryAndShow(deliveryId) {
            Swal.fire({ 
                title: 'Loading...', 
                allowOutsideClick: false, 
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch(`${API}?action=get_delivery&id=${deliveryId}`)
                .then(r => r.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        window._currentDelivery = data.data;
                        renderDrawerContent(data.data);
                        
                        document.getElementById('drawerBackdrop').classList.add('open');
                        document.getElementById('deliveryDrawer').classList.add('open');
                        document.body.style.overflow = 'hidden';
                    } else {
                        Swal.fire('Error', data.error || 'Delivery not found', 'error');
                    }
                })
                .catch(err => {
                    Swal.close();
                    Swal.fire('Error', 'Network error: ' + err.message, 'error');
                });
        }

        function closeDrawer() {
            document.getElementById('drawerBackdrop').classList.remove('open');
            document.getElementById('deliveryDrawer').classList.remove('open');
            document.body.style.overflow = '';
            window._currentDelivery = null;
        }

        // ═══════════════════════════════════════════════════════
        // RENDER: Drawer Content
        // ═══════════════════════════════════════════════════════
        function renderDrawerContent(d) {
            const drawerTitle = document.getElementById('drawerTitle');
            const drawerSubtitle = document.getElementById('drawerSubtitle');
            const drawerBody = document.getElementById('drawerBody');
            
            const isShipped = d.status === 'Shipped';
            const riderStatus = d.rider_status || d.status;
            const isAssigned  = riderStatus === 'Assigned';
            const isPickedUp  = riderStatus === 'Picked Up';
            const isInTransit = riderStatus === 'In Transit';
            const isDelivered = riderStatus === 'Delivered';
            
            drawerTitle.textContent = d.po_number || '—';
            drawerSubtitle.textContent = isDelivered ? 'Delivered' : (isShipped ? 'In Transit' : 'Assigned');
            
            // Build handover photos HTML
            let handoverPhotosHtml = '';
            if (d.photos && Array.isArray(d.photos) && d.photos.length > 0) {
                handoverPhotosHtml = `
                    <div class="drawer-section">
                        <div class="drawer-section-title">
                            <i class="bi bi-shield-check" style="color:#059669;"></i>
                            Package Photos (Reference)
                        </div>
                        <div class="handover-photos-grid">
                            ${d.photos.map((p, i) => `
                                <div class="handover-photo" onclick="viewPhoto('../${escapeHtml(p)}', 'Package Photo ${i+1}')">
                                    <img src="../${escapeHtml(p)}" alt="Photo ${i+1}" onerror="this.style.display='none';">
                                    <div class="handover-photo-badge">${i+1}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            // Build items HTML
            let itemsHtml = '';
            if (d.items && Array.isArray(d.items) && d.items.length > 0) {
                itemsHtml = `
                    <div class="drawer-section">
                        <div class="drawer-section-title">
                            <i class="bi bi-box-seam"></i>
                            Items (${d.items.length})
                        </div>
                        <div class="items-list">
                            ${d.items.map(item => `
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-name">${escapeHtml(item.item_name || 'Item')}</div>
                                        ${item.description ? `<div class="item-desc">${escapeHtml(item.description)}</div>` : ''}
                                    </div>
                                    <div class="item-qty">x${item.quantity || 1}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
            
            // Build action buttons HTML
            let actionsHtml = '';
            if (isDelivered) {
                actionsHtml = `
                    <div class="drawer-actions">
                        <div style="background:#d1fae5;border:2px solid #6ee7b7;border-radius:12px;padding:1rem;text-align:center;">
                            <i class="bi bi-check-circle-fill" style="font-size:2rem;color:#10b981;"></i>
                            <div style="font-weight:800;color:#065f46;margin-top:.5rem;">Delivered</div>
                            ${d.actual_delivery ? `<div style="font-size:.75rem;color:#059669;margin-top:.25rem;">${new Date(d.actual_delivery).toLocaleString()}</div>` : ''}
                        </div>
                    </div>
                `;
            } else {
    actionsHtml = `
        <div class="drawer-actions">
            ${isAssigned ? `
                <button class="action-btn start" onclick="pickUpParcel(${d.id})">
                    <i class="bi bi-box-seam-fill"></i>
                    Pick Up Parcel
                </button>
            ` : ''}
            
            ${isPickedUp ? `
                <button class="action-btn start" onclick="startTransit(${d.id})">
                    <i class="bi bi-bicycle"></i>
                    Start Transit
                </button>
            ` : ''}
            
            ${isInTransit ? `
                <button class="action-btn deliver" onclick="openMarkDelivered(${d.id})">
                    <i class="bi bi-check-circle-fill"></i>
                    Mark as Delivered
                </button>
                
                <button class="action-btn fail" onclick="openReportFailed(${d.id})">
                    <i class="bi bi-x-circle"></i>
                    Report Failed Delivery
                </button>
            ` : ''}
        </div>
    `;
}
            
            drawerBody.innerHTML = `
                <!-- Delivery Info -->
                <div class="drawer-section">
                    <div class="drawer-section-title">
                        <i class="bi bi-geo-alt-fill"></i>
                        Delivery Information
                    </div>
                    <div class="info-card">
                        <div class="info-card-row">
                            <div class="info-card-label">Clinic</div>
                            <div class="info-card-value">${escapeHtml(d.clinic_name || d.department || '—')}</div>
                        </div>
                        <div class="info-card-row">
                            <div class="info-card-label">Address</div>
                            <div class="info-card-value">${escapeHtml(d.clinic_address || '—')}</div>
                        </div>
                        <div class="info-card-row">
                            <div class="info-card-label">Phone</div>
                            <div class="info-card-value">${escapeHtml(d.clinic_phone || '—')}</div>
                        </div>
                        <div class="info-card-row">
                            <div class="info-card-label">Time Window</div>
                            <div class="info-card-value">${escapeHtml(d.time_window || '—')}</div>
                        </div>
<div class="info-card-row">
    <div class="info-card-label">${d.is_cod ? 'COD Amount' : 'Total Amount'}</div>
    <div class="info-card-value" style="color:var(--ec-accent);font-size:1rem;">₱${parseFloat(d.total_amount || 0).toFixed(2)}</div>
</div>
<div class="info-card-row">
    <div class="info-card-label">Payment Method</div>
    <div class="info-card-value">
        <span class="badge ${d.is_cod ? 'bg-success' : 'bg-info'}" style="font-size:.7rem;padding:4px 10px;">
            ${escapeHtml(d.payment_method || 'Bank Transfer')}
        </span>
    </div>
</div>
                    </div>
                    
                    <div style="display:flex;gap:.5rem;margin-top:.75rem;">
                        <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.clinic_address || '')}" 
                           target="_blank"
                           style="flex:1;background:var(--ec-primary);color:#fff;border:none;padding:.65rem;border-radius:10px;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;text-transform:uppercase;letter-spacing:.03em;">
                            <i class="bi bi-send-fill"></i> Navigate
                        </a>
                        ${d.clinic_phone ? `
                            <a href="tel:${escapeHtml(d.clinic_phone)}"
                               style="flex:1;background:#fff;color:var(--ec-primary);border:2px solid var(--ec-primary);padding:.65rem;border-radius:10px;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;text-transform:uppercase;letter-spacing:.03em;">
                                <i class="bi bi-telephone-fill"></i> Call
                            </a>
                        ` : ''}
                    </div>
                </div>
                
                ${itemsHtml}
                ${handoverPhotosHtml}
                
                ${d.shipping_remarks ? `
                    <div class="drawer-section">
                        <div class="drawer-section-title">
                            <i class="bi bi-sticky"></i>
                            Supplier Remarks
                        </div>
                        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:.75rem 1rem;font-size:.82rem;color:#92400e;font-weight:600;">
                            ${escapeHtml(d.shipping_remarks)}
                        </div>
                    </div>
                ` : ''}
                
                ${actionsHtml}
            `;
        }

// ═══════════════════════════════════════════════════════
// ACTION: Pick Up Parcel
// ═══════════════════════════════════════════════════════
function pickUpParcel(deliveryId) {
    Swal.fire({
        title: '📦 Pick Up Parcel?',
        html: 'Confirm na nakuha mo na ang parcel mula sa supplier.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6e6e',
        confirmButtonText: 'Yes, Picked Up',
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
        formData.append('action', 'pick_up');
        formData.append('po_id', deliveryId);
        
        fetch(API, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Picked Up!',
                        text: 'Ready ka nang mag-start ng transit.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        loadAll();
                        setTimeout(() => refreshDrawer(deliveryId), 600);
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to pick up', 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    });
}

// ═══════════════════════════════════════════════════════
// ACTION: Start Transit
// ═══════════════════════════════════════════════════════
function startTransit(deliveryId) {
    Swal.fire({
        title: '🚴 Start Transit?',
        html: 'Confirm na nagsisimula ka nang mag-deliver sa clinic.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6e6e',
        confirmButtonText: 'Yes, Start Transit',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        
        Swal.fire({ 
            title: 'Starting...', 
            allowOutsideClick: false, 
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });
        
        const formData = new FormData();
        formData.append('action', 'start_transit');
        formData.append('po_id', deliveryId);
        
        fetch(API, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'In Transit!',
                        text: 'Ingat sa biyahe! 🚴',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        loadAll();
                        setTimeout(() => refreshDrawer(deliveryId), 600);
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to start transit', 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    });
}

// Helper — refresh drawer pagkatapos ng action
function refreshDrawer(deliveryId) {
    const updated = window._activeDeliveries.find(x => x.id == deliveryId);
    if (updated) {
        window._currentDelivery = updated;
        renderDrawerContent(updated);
    }
}

function openMarkDelivered(deliveryId) {
    const d = window._activeDeliveries.find(x => x.id == deliveryId);
    if (!d) return;
    
    window._pendingDeliveryId = deliveryId;
    window._capturedBlob = null;
    
    document.getElementById('mdOrderCode').textContent = d.po_number;
    document.getElementById('mdCustomer').textContent = d.clinic_name || d.department || '—';
    
    // ✅ Determine if COD
    const isCod = d.is_cod === true || d.payment_method === 'COD' || d.payment_method === 'Cash';
    window._currentIsCOD = isCod;
    window._currentTotalDue = parseFloat(d.total_amount || 0);
    
    // ✅ Conditional COD section
    if (isCod) {
        // COD — show cash collection fields
        document.getElementById('mdCodSection').style.display = 'block';
        document.getElementById('mdTotalDue').textContent = '₱' + window._currentTotalDue.toFixed(2);
        document.getElementById('mdAmountReceived').value = '';
        document.getElementById('mdAmountReceived').required = true;
        document.getElementById('mdCollectionResult').style.display = 'none';
    } else {
        // Bank Transfer / Check / Net 30 — hide cash collection
        document.getElementById('mdCodSection').style.display = 'none';
        document.getElementById('mdAmountReceived').value = '0';
        document.getElementById('mdAmountReceived').required = false;
    }
    
    // ✅ Price change warning (kung may estimated_amount)
    const estAmt = parseFloat(d.estimated_amount) || 0;
    const actualAmt = parseFloat(d.total_amount) || 0;
    
    // Check kung existing na ang price change alert, kung wala, i-create
    let alertEl = document.getElementById('mdPriceChangeAlert');
    if (!alertEl) {
        alertEl = document.createElement('div');
        alertEl.id = 'mdPriceChangeAlert';
        alertEl.style.cssText = 'display:none;background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:.65rem .85rem;margin-bottom:1rem;font-size:.75rem;color:#92400e;font-weight:600;';
        // Insert after customer info
        const customerEl = document.getElementById('mdCustomer');
        if (customerEl && customerEl.parentNode) {
            customerEl.parentNode.parentNode.insertBefore(alertEl, customerEl.parentNode.nextSibling);
        }
    }
    
    if (estAmt > 0 && Math.abs(estAmt - actualAmt) > 0.01) {
        const diff = actualAmt - estAmt;
        const pct = parseFloat(d.price_change_percent) || ((diff / estAmt) * 100);
        alertEl.innerHTML = `
            <i class="bi bi-info-circle-fill me-1"></i>
            <strong>Note:</strong> Price adjusted from 
            <span style="text-decoration:line-through;">₱${estAmt.toFixed(2)}</span>
            (<span style="color:${diff > 0 ? '#dc2626' : '#16a34a'};">
                ${diff > 0 ? '+' : ''}${pct.toFixed(2)}%
            </span>)
        `;
        alertEl.style.display = 'block';
    } else {
        alertEl.style.display = 'none';
    }
    
    // Reset photo
    document.getElementById('mdPhotoPreviewWrap').style.display = 'none';
    document.getElementById('mdPhotoUploadWrap').style.display = 'block';
    document.getElementById('mdNotes').value = '';
    document.getElementById('mdConfirmBtn').disabled = true;
    
    window._markDeliveredModal = new bootstrap.Modal(document.getElementById('markDeliveredModal'));
    window._markDeliveredModal.show();
}

function openCameraFromDelivered() {
    window._photoFiles = [];
    
    // ✅ Isara ang Mark Delivered modal bago buksan ang camera
    const mdEl = document.getElementById('markDeliveredModal');
    const mdInstance = bootstrap.Modal.getInstance(mdEl);
    
    if (mdInstance) {
        mdInstance.hide();
        // Hintayin mag-close, tapos buksan ang camera
        setTimeout(() => {
            openCamera();
        }, 400);
    } else {
        openCamera();
    }
}

function validateCollection() {
    // ✅ Skip kung non-COD
    if (!window._currentIsCOD) {
        document.getElementById('mdConfirmBtn').disabled = !window._capturedBlob;
        return;
    }
    
    const input = parseFloat(document.getElementById('mdAmountReceived').value) || 0;
    const resultDiv = document.getElementById('mdCollectionResult');
    const confirmBtn = document.getElementById('mdConfirmBtn');
    
    resultDiv.style.display = 'none';
    confirmBtn.disabled = true;
    
    if (input <= 0) return;
    
    if (input < window._currentTotalDue - 0.01) {
        const short = window._currentTotalDue - input;
        resultDiv.style.display = 'block';
        resultDiv.style.background = '#fee2e2';
        resultDiv.style.border = '2px solid #dc2626';
        resultDiv.style.color = '#991b1b';
        resultDiv.innerHTML = `<strong><i class="bi bi-exclamation-circle-fill"></i> Short by ₱${short.toFixed(2)}</strong>`;
        return;
    }
    
    if (Math.abs(input - window._currentTotalDue) < 0.01) {
        resultDiv.style.display = 'block';
        resultDiv.style.background = '#d1fae5';
        resultDiv.style.border = '2px solid #10b981';
        resultDiv.style.color = '#065f46';
        resultDiv.innerHTML = `<strong><i class="bi bi-check-circle-fill"></i> Exact amount received</strong>`;
        confirmBtn.disabled = !window._capturedBlob;
        return;
    }
    
    const change = input - window._currentTotalDue;
    resultDiv.style.display = 'block';
    resultDiv.style.background = '#fef3c7';
    resultDiv.style.border = '2px solid #f59e0b';
    resultDiv.style.color = '#92400e';
    resultDiv.innerHTML = `<strong><i class="bi bi-info-circle-fill"></i> Change: ₱${change.toFixed(2)}</strong>`;
    confirmBtn.disabled = !window._capturedBlob;
}

function submitDelivered() {
    const deliveryId = window._pendingDeliveryId;
    if (!deliveryId) return;
    
    if (!window._capturedBlob) {
        Swal.fire('Photo Required', 'Please take a photo proof of delivery.', 'warning');
        return;
    }
    
    // ✅ Get amount — kung non-COD, force 0
    let amountReceived = 0;
    let change = 0;
    
    if (window._currentIsCOD) {
        amountReceived = parseFloat(document.getElementById('mdAmountReceived').value) || 0;
        
        if (amountReceived < window._currentTotalDue - 0.01) {
            Swal.fire('Insufficient Payment', 'Please collect the full amount before proceeding.', 'error');
            return;
        }
        
        change = amountReceived - window._currentTotalDue;
    }
    
    const notes = document.getElementById('mdNotes').value.trim();
    
    // ✅ Build confirmation message
    let confirmHtml = '';
    if (window._currentIsCOD) {
        confirmHtml = `
            <div style="text-align:left;font-size:.9rem;">
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.75rem 1rem;margin-bottom:.75rem;">
                    <div><strong>Collected:</strong> ₱${amountReceived.toFixed(2)}</div>
                    ${change > 0.01 ? `<div><strong>Change:</strong> ₱${change.toFixed(2)}</div>` : ''}
                </div>
                <p style="margin:0;font-size:.78rem;color:#6b7280;">Finalize delivery and mark as Delivered?</p>
            </div>
        `;
    } else {
        confirmHtml = `
            <div style="text-align:left;font-size:.9rem;">
                <div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:.75rem 1rem;margin-bottom:.75rem;">
                    <div><strong>Payment Method:</strong> ${escapeHtml(window._currentDelivery?.payment_method || 'Bank Transfer')}</div>
                    <div style="font-size:.75rem;color:#1e40af;margin-top:.25rem;">
                        Walang cash na kokolektahin. Finance ang magpo-process ng payment.
                    </div>
                </div>
                <p style="margin:0;font-size:.78rem;color:#6b7280;">Finalize delivery and mark as Delivered?</p>
            </div>
        `;
    }
    
    Swal.fire({
        title: 'Confirm Delivery?',
        html: confirmHtml,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Yes, Confirm',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        
        Swal.fire({ 
            title: 'Uploading...', 
            allowOutsideClick: false, 
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });
        
        const formData = new FormData();
        formData.append('action', 'mark_delivered');
        formData.append('po_id', deliveryId);
        formData.append('delivery_proof', window._capturedBlob, 'proof_' + Date.now() + '.jpg');
        formData.append('receiver_name', notes || 'Receiver');
        formData.append('collected_amount', amountReceived);
        formData.append('change_amount', change > 0 ? change : 0);
        formData.append('notes', notes);
        
        fetch(API, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window._markDeliveredModal.hide();
                    closeDrawer();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Delivered!',
                        text: 'Good job! 🎉',
                        timer: 1800,
                        showConfirmButton: false
                    }).then(() => {
                        loadAll();
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to save delivery', 'error');
                }
            })
            .catch(err => {
                Swal.close();
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
    });
}
        // ═══════════════════════════════════════════════════════
        // ACTION: Report Failed
        // ═══════════════════════════════════════════════════════
        function openReportFailed(deliveryId) {
            window._pendingDeliveryId = deliveryId;
            document.getElementById('rfReason').value = '';
            document.getElementById('rfNotes').value = '';
            
            window._reportFailedModal = new bootstrap.Modal(document.getElementById('reportFailedModal'));
            window._reportFailedModal.show();
        }

        function submitFailed() {
            const deliveryId = window._pendingDeliveryId;
            const reason = document.getElementById('rfReason').value;
            const notes = document.getElementById('rfNotes').value.trim();
            
            if (!reason) {
                Swal.fire('Required', 'Please select a reason.', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Submit Report?',
                text: 'This will notify the supplier about the failed delivery.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Yes, Submit',
                cancelButtonText: 'Cancel'
            }).then(result => {
                if (!result.isConfirmed) return;
                
                Swal.fire({ 
                    title: 'Submitting...', 
                    allowOutsideClick: false, 
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });
                
                const formData = new FormData();
                formData.append('action', 'report_failed');
                formData.append('po_id', deliveryId);
                formData.append('reason', reason);
                formData.append('notes', notes);
                
                fetch(API, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            window._reportFailedModal.hide();
                            closeDrawer();
                            
                            Swal.fire({
                                icon: 'info',
                                title: 'Reported',
                                text: 'Na-report na ang failed delivery.',
                                timer: 1800,
                                showConfirmButton: false
                            }).then(() => {
                                loadAll();
                            });
                        } else {
                            Swal.fire('Error', data.error || 'Failed to report', 'error');
                        }
                    })
                    .catch(err => {
                        Swal.close();
                        Swal.fire('Error', 'Network error: ' + err.message, 'error');
                    });
            });
        }

async function openCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        Swal.fire({
            icon: 'error',
            title: 'Camera Not Supported',
            html: 'Siguraduhing naka-HTTPS ang site at Chrome/Safari/Edge ang gamit.'
        });
        return;
    }
    
    try {
        window._cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        });
        
        document.getElementById('cameraStream').srcObject = window._cameraStream;
        
        const camEl = document.getElementById('cameraModal');
        camEl.style.zIndex = '1090';
        
        if (!window._cameraModal) {
            window._cameraModal = new bootstrap.Modal(camEl, {
                backdrop: 'static',
                keyboard: false
            });
        }
        window._cameraModal.show();
        
        // ✅ Force z-index sa lahat
        setTimeout(() => {
            camEl.style.zIndex = '1090';
            document.querySelectorAll('.modal-backdrop').forEach((bd, i) => {
                // Backdrop para sa camera ay dapat 1085
                if (i === document.querySelectorAll('.modal-backdrop').length - 1) {
                    bd.style.zIndex = '1085';
                }
            });
        }, 50);
        
        updatePhotoCounter();
    } catch (err) {
        let msg = 'Hindi ma-access ang camera.';
        if (err.name === 'NotAllowedError') msg = 'Pinagbawalan ang camera access.';
        else if (err.name === 'NotFoundError') msg = 'Walang nakitang camera.';
        else if (err.name === 'NotReadableError') msg = 'Ginagamit ng ibang app ang camera.';
        Swal.fire('Camera Error', msg, 'error');
    }
}
function closeCamera() {
    if (window._cameraStream) {
        window._cameraStream.getTracks().forEach(track => track.stop());
        window._cameraStream = null;
    }
    if (window._cameraModal) window._cameraModal.hide();
    
    // ✅ I-reset ang z-index
    const camEl = document.getElementById('cameraModal');
    if (camEl) camEl.style.zIndex = '';
}
        function capturePhoto() {
            const video = document.getElementById('cameraStream');
            const canvas = document.getElementById('cameraCanvas');
            
            if (!video.videoWidth || !video.videoHeight) {
                Swal.fire('Error', 'Camera not ready yet.', 'warning');
                return;
            }
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            
canvas.toBlob(function(blob) {
    if (!blob) return;
    window._capturedBlob = blob;
    
    const url = URL.createObjectURL(blob);
    document.getElementById('mdPhotoPreview').src = url;
    document.getElementById('mdPhotoPreviewWrap').style.display = 'block';
    document.getElementById('mdPhotoUploadWrap').style.display = 'none';
    
    closeCamera();
    
    // ✅ I-reopen ang Mark Delivered modal
    setTimeout(() => {
        if (!window._markDeliveredModal) {
            window._markDeliveredModal = new bootstrap.Modal(document.getElementById('markDeliveredModal'));
        }
        window._markDeliveredModal.show();
        
        setTimeout(() => validateCollection(), 200);
    }, 400);
}, 'image/jpeg', 0.9);
        }

        function retakePhoto() {
            window._capturedBlob = null;
            document.getElementById('mdPhotoPreviewWrap').style.display = 'none';
            document.getElementById('mdPhotoUploadWrap').style.display = 'block';
            document.getElementById('mdConfirmBtn').disabled = true;
        }

        function updatePhotoCounter() {
            const counter = document.getElementById('photoCounter');
            if (counter) counter.textContent = (window._photoFiles?.length || 0) + ' / ' + MAX_PHOTOS;
        }

        // ═══════════════════════════════════════════════════════
        // HELPERS
        // ═══════════════════════════════════════════════════════
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function navigateTo(address) {
            if (!address) return;
            const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(address);
            window.open(url, '_blank');
        }

        function viewPhoto(src, title) {
            Swal.fire({
                imageUrl: src,
                imageAlt: title,
                title: title,
                width: '600px',
                showConfirmButton: false,
                showCloseButton: true,
                background: '#0f172a',
                color: '#fff'
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

        // ═══════════════════════════════════════════════════════
        // INIT
        // ═══════════════════════════════════════════════════════
        document.addEventListener('DOMContentLoaded', function() {
            loadAll();
        });

        // ESC key closes drawer
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDrawer();
        });

        // Auto refresh every 60s
        setInterval(loadAll, 60000);
    </script>

    <!-- ============================================ -->
<!-- ✅ SUPPLIER RIDER CHAT WIDGET (Multi-chat) -->
<!-- ============================================ -->
<div id="riderChatWindow" style="display: none; position: fixed; bottom: 20px; right: 20px; width: 380px; max-width: calc(100vw - 24px); height: 540px; background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); z-index: 9999; flex-direction: column; overflow: hidden; font-family: 'Plus Jakarta Sans', sans-serif;">
    
    <!-- ═══ LIST VIEW ═══ -->
    <div id="riderChatListView" style="display: flex; flex-direction: column; height: 100%;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #0d6e6e, #095050); color: #fff; padding: 16px 18px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
                    <i class="bi bi-chat-dots-fill" style="font-size: 18px;"></i>
                </div>
                <div>
                    <div style="font-weight: 800; font-size: 15px;">Messages</div>
                    <div style="font-size: 11px; opacity: 0.8; margin-top: 1px;">Select a conversation</div>
                </div>
            </div>
            <button onclick="toggleRiderChat()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-x-lg" style="font-size: 13px;"></i>
            </button>
        </div>

        <!-- Chat list -->
        <div id="riderChatList" style="flex: 1; overflow-y: auto; background: #f8fafc;">
            <div style="text-align: center; color: #94a3b8; padding: 40px 20px;">
                <div class="spinner-border spinner-border-sm" style="color: #0d6e6e;"></div>
                <p style="font-size: 13px; margin: 12px 0 0 0;">Loading conversations...</p>
            </div>
        </div>
    </div>

    <!-- ═══ CHAT VIEW ═══ -->
    <div id="riderChatView" style="display: none; flex-direction: column; height: 100%;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #0d6e6e, #095050); color: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                <button onclick="backToChatList()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-arrow-left" style="font-size: 14px;"></i>
                </button>
                <div id="riderChatHeaderAvatar" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0;">?</div>
                <div style="min-width: 0;">
                    <div id="riderChatHeaderName" style="font-weight: 800; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</div>
                    <div id="riderChatHeaderType" style="font-size: 10px; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">—</div>
                </div>
            </div>
            <button onclick="toggleRiderChat()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="bi bi-x-lg" style="font-size: 13px;"></i>
            </button>
        </div>

        <!-- Messages -->
        <div id="riderChatMessages" style="flex: 1; overflow-y: auto; padding: 16px; background: #f8fafc;">
            <div style="text-align: center; color: #94a3b8; padding: 40px 20px;">
                <i class="bi bi-chat-dots" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px;"></i>
                <p style="font-size: 13px; margin: 0;">No messages yet. Say hi! 👋</p>
            </div>
        </div>

        <!-- Image preview -->
        <div id="riderChatImgPreview" style="display: none; padding: 8px 12px; background: #fff; border-top: 1px solid #e2e8f0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <img id="riderChatImgThumb" src="" style="width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #e2e8f0;">
                <span style="font-size: 11px; color: #64748b; flex: 1;">Image ready</span>
                <button onclick="removeRiderChatImage()" style="background: #fee2e2; color: #dc2626; border: none; width: 24px; height: 24px; border-radius: 50%; cursor: pointer;">
                    <i class="bi bi-x" style="font-size: 12px;"></i>
                </button>
            </div>
        </div>

        <!-- Input -->
        <div style="padding: 12px; background: #fff; border-top: 1px solid #e2e8f0;">
            <form onsubmit="sendRiderChatMessage(event)" style="display: flex; gap: 8px; align-items: center;">
                <input type="file" id="riderChatImgInput" accept="image/*" style="display: none;" onchange="handleRiderChatImage(event)">
                <button type="button" onclick="document.getElementById('riderChatImgInput').click()" 
                        style="width: 36px; height: 36px; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-image"></i>
                </button>
                <input type="text" id="riderChatInput" placeholder="Type message..." 
                       style="flex: 1; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 20px; font-size: 13px; outline: none; font-family: inherit;">
                <button type="submit" 
                        style="width: 36px; height: 36px; border-radius: 50%; border: none; background: #0d6e6e; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-send"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================
// ✅ SUPPLIER RIDER CHAT LOGIC (Multi-chat)
// ============================================
const RIDER_CHAT_API = '../api/rider_chat.php';
const RIDER_ID = <?= json_encode($rider_id) ?>;
const RIDER_TYPE = 'supplier';
const RIDER_SUPPLIER_ID = <?= json_encode($supplier_id) ?>;

let riderChatInterval = null;
let riderChatSelectedImage = null;
let currentChatTarget = null;

// ✅ Toggle chat window
function toggleRiderChat() {
    const chatWindow = document.getElementById('riderChatWindow');
    const isOpen = chatWindow.style.display === 'flex';
    
    if (isOpen) {
        chatWindow.style.display = 'none';
        if (riderChatInterval) clearInterval(riderChatInterval);
    } else {
        chatWindow.style.display = 'flex';
        showChatListView();
        loadChatList();
    }
}

// ✅ Show list view
function showChatListView() {
    document.getElementById('riderChatListView').style.display = 'flex';
    document.getElementById('riderChatView').style.display = 'none';
    if (riderChatInterval) clearInterval(riderChatInterval);
    currentChatTarget = null;
}

// ✅ Show chat view
function showChatView(type, targetId, name) {
    document.getElementById('riderChatListView').style.display = 'none';
    document.getElementById('riderChatView').style.display = 'flex';
    
    document.getElementById('riderChatHeaderName').textContent = name;
    document.getElementById('riderChatHeaderType').textContent = type === 'supplier' ? 'Supplier Admin' : 'Clinic';
    document.getElementById('riderChatHeaderAvatar').textContent = (name || '?').charAt(0).toUpperCase();
    
    currentChatTarget = { type, id: targetId, name };
    loadChatMessages();
    
    if (riderChatInterval) clearInterval(riderChatInterval);
    riderChatInterval = setInterval(loadChatMessages, 5000);
}

// ✅ Load list
function loadChatList() {
    const container = document.getElementById('riderChatList');
    container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#94a3b8;"><div class="spinner-border spinner-border-sm" style="color:#0d6e6e;"></div><p style="font-size:13px;margin:12px 0 0 0;">Loading...</p></div>';
    
    fetch(RIDER_CHAT_API + '?action=get_chat_list&rider_id=' + RIDER_ID + '&rider_type=' + RIDER_TYPE)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; color: #94a3b8; padding: 50px 20px;">
                        <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px;"></i>
                        <p style="font-size: 13px; margin: 0; font-weight: 600;">Walang available na chat</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            data.forEach(item => {
                const badge = item.unread > 0 
                    ? `<span style="background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:999px;">${item.unread > 9 ? '9+' : item.unread}</span>` 
                    : '';
                const typeBadge = item.type === 'supplier' 
                    ? '<span style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:800;padding:2px 6px;border-radius:4px;text-transform:uppercase;letter-spacing:0.03em;">Supplier</span>'
                    : '<span style="background:#dbeafe;color:#1e40af;font-size:9px;font-weight:800;padding:2px 6px;border-radius:4px;text-transform:uppercase;letter-spacing:0.03em;">Clinic</span>';
                
                const safeName = (item.name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                
                html += `
                    <div onclick="showChatView('${item.type}', ${item.target_id}, '${safeName}')"
                         style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:#fff;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .15s;"
                         onmouseover="this.style.background='#f0fdfa'"
                         onmouseout="this.style.background='#fff'">
                        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#0d6e6e,#095050);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;flex-shrink:0;">
                            ${escapeHtml(item.avatar_initial || '?')}
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                                <div style="font-weight:800;font-size:13px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;">${escapeHtml(item.name)}</div>
                                ${badge}
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                ${typeBadge}
                                <div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;">${item.last_message || 'No messages yet'}</div>
                            </div>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;flex-shrink:0;">${item.last_time || ''}</div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        })
        .catch(err => {
            console.error('Chat list error:', err);
            container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#dc2626;font-size:12px;">Error loading chats</div>';
        });
}

// ✅ Back to list
function backToChatList() {
    if (riderChatInterval) clearInterval(riderChatInterval);
    showChatListView();
}

// ✅ Load messages
function loadChatMessages() {
    if (!currentChatTarget) return;
    const body = document.getElementById('riderChatMessages');
    if (!body) return;
    
    fetch(RIDER_CHAT_API + '?action=get_messages&rider_id=' + RIDER_ID + '&rider_type=' + RIDER_TYPE + 
          '&chat_with=' + currentChatTarget.type + '&target_id=' + currentChatTarget.id)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                body.innerHTML = `
                    <div style="text-align: center; color: #94a3b8; padding: 40px 20px;">
                        <i class="bi bi-chat-dots" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px;"></i>
                        <p style="font-size: 13px; margin: 0;">No messages yet. Say hi! 👋</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            let lastDate = '';
            
            data.forEach(m => {
                const isMine = m.is_mine;
                const bubbleStyle = isMine 
                    ? 'background: #0d6e6e; color: #fff; margin-left: auto; border-bottom-right-radius: 4px;' 
                    : 'background: #fff; color: #1e293b; border: 1px solid #e2e8f0; margin-right: auto; border-bottom-left-radius: 4px;';
                const align = isMine ? 'justify-content: flex-end;' : 'justify-content: flex-start;';
                
                let imgHtml = '';
                if (m.image) {
                    imgHtml = `<img src="${m.image}" style="max-width: 100%; border-radius: 8px; margin-top: 6px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                }
                
                if (m.date !== lastDate) {
                    html += `<div style="text-align: center; margin: 12px 0;"><span style="background: #f1f5f9; color: #64748b; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 600;">${m.date}</span></div>`;
                    lastDate = m.date;
                }
                
                html += `
                    <div style="display: flex; ${align} margin-bottom: 8px;">
                        <div style="max-width: 80%; padding: 10px 14px; border-radius: 16px; font-size: 13px; ${bubbleStyle}">
                            ${!isMine ? `<div style="font-weight: 700; font-size: 10px; color: #0d6e6e; margin-bottom: 4px;">${m.sender_name}</div>` : ''}
                            <div>${m.message}</div>
                            ${imgHtml}
                            <div style="font-size: 9px; opacity: 0.7; text-align: right; margin-top: 4px;">${m.time}</div>
                        </div>
                    </div>
                `;
            });
            
            body.innerHTML = html;
            body.scrollTop = body.scrollHeight;
        })
        .catch(err => console.error('Chat load error:', err));
}

// ✅ Send message
function sendRiderChatMessage(e) {
    e.preventDefault();
    if (!currentChatTarget) return;
    
    const input = document.getElementById('riderChatInput');
    const message = input.value.trim();
    
    if (!message && !riderChatSelectedImage) return;
    
    input.value = '';
    
    const fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('rider_id', RIDER_ID);
    fd.append('rider_type', RIDER_TYPE);
    fd.append('chat_with', currentChatTarget.type);
    fd.append('target_id', currentChatTarget.id);
    fd.append('message', message);
    if (riderChatSelectedImage) fd.append('image', riderChatSelectedImage);
    
    fetch(RIDER_CHAT_API, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                removeRiderChatImage();
                loadChatMessages();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

function handleRiderChatImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Max 5MB only.'); return; }
    
    riderChatSelectedImage = file;
    
    const reader = new FileReader();
    reader.onload = function(evt) {
        document.getElementById('riderChatImgThumb').src = evt.target.result;
        document.getElementById('riderChatImgPreview').style.display = 'block';
    };
    reader.readAsDataURL(file);
}

function removeRiderChatImage() {
    riderChatSelectedImage = null;
    document.getElementById('riderChatImgInput').value = '';
    document.getElementById('riderChatImgPreview').style.display = 'none';
}

setInterval(function() {
    fetch(RIDER_CHAT_API + '?action=get_unread_count&rider_id=' + RIDER_ID + '&rider_type=' + RIDER_TYPE)
        .then(res => res.json())
        .then(data => {
            const badge = document.getElementById('riderChatBadge');
            if (data.success && data.unread > 0) {
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        })
        .catch(e => console.log(e));
}, 15000);

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>