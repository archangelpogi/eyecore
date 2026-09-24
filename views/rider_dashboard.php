<?php
// views/rider_dashboard.php — Rider Dashboard with COD Collection & History
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

// ✅ Check if rider is logged in
if (!isset($_SESSION['rider_id']) || !isset($_SESSION['user_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$riderId  = (int)$_SESSION['rider_id'];
$userId   = (int)$_SESSION['user_id'];
$clinicId = (int)($_SESSION['clinic_id'] ?? 0);

// ✅ Verify rider is still active
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
    ORDER BY r.created_at ASC
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

// ✅ DELIVERY HISTORY — All past deliveries (excluding today)
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
    LIMIT 50
");
$stmtHistory->execute([$riderId, $clinicId]);
$deliveryHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ✅ Calculate total collected for today
$stmtCollected = $pdo->prepare("
    SELECT COALESCE(SUM(collected_amount), 0) AS total_collected
    FROM reservations
    WHERE assigned_rider_id = ?
      AND clinic_id = ?
      AND DATE(collected_at) = CURDATE()
");
$stmtCollected->execute([$riderId, $clinicId]);
$totalCollectedToday = (float)($stmtCollected->fetch(PDO::FETCH_ASSOC)['total_collected'] ?? 0);
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
        * { box-sizing: border-box; }

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
            --ec-radius: 16px;
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
        }

        /* ═══════════════ HEADER ═══════════════ */
        .rider-header {
            background: linear-gradient(135deg, var(--ec-primary) 0%, var(--ec-primary-dark) 100%);
            color: #fff;
            padding: 1.25rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(13,110,110,.25);
        }

        .rider-header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .rider-header h1 {
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rider-header .subtitle {
            opacity: .9;
            font-size: .85rem;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rider-header .avatar-badge {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255,255,255,.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,.3);
        }

        .rider-header .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            transition: all .2s;
        }

        .rider-header .btn-icon:hover {
            background: rgba(255,255,255,.25);
            transform: translateY(-1px);
        }

        /* ═══════════════ MAIN CONTENT ═══════════════ */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 6rem;
        }

        /* ═══════════════ STATS ═══════════════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: .875rem;
            margin-bottom: 1.75rem;
        }

        .stat-card {
            background: var(--ec-card);
            border-radius: var(--ec-radius);
            padding: 1.1rem 1.25rem;
            border: 1px solid var(--ec-border);
            box-shadow: var(--ec-shadow-sm);
            transition: all .2s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--ec-primary);
        }

        .stat-card.warning::before { background: var(--ec-warning); }
        .stat-card.info::before { background: var(--ec-info); }
        .stat-card.success::before { background: var(--ec-success); }
        .stat-card.accent::before { background: var(--ec-accent); }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--ec-shadow-md);
        }

        .stat-label {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--ec-text-muted);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-value {
            font-size: 1.85rem;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 6px;
            letter-spacing: -0.02em;
        }

        .stat-value.primary { color: var(--ec-primary); }
        .stat-value.warning { color: var(--ec-warning); }
        .stat-value.info    { color: var(--ec-info); }
        .stat-value.success { color: var(--ec-success); }
        .stat-value.accent  { color: var(--ec-accent); }

        .stat-value.small {
            font-size: 1.25rem;
        }

        /* ═══════════════ SECTION HEADER ═══════════════ */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            margin-top: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .section-header h5 {
            font-size: 1.1rem;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.01em;
        }

        .section-header h5 i {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--ec-primary-light);
            color: var(--ec-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .section-count {
            font-size: .8rem;
            font-weight: 700;
            color: var(--ec-text-muted);
            background: var(--ec-card);
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid var(--ec-border);
        }

        /* ═══════════════ DELIVERY CARDS ═══════════════ */
        .delivery-card {
            background: var(--ec-card);
            border-radius: var(--ec-radius);
            padding: 1.25rem;
            border: 1px solid var(--ec-border);
            box-shadow: var(--ec-shadow-sm);
            transition: all .2s;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
        }

        .delivery-card:hover {
            box-shadow: var(--ec-shadow-md);
            border-color: #cbd5e1;
        }

        .delivery-card.active-card {
            border-left: 4px solid var(--ec-primary);
        }

        .delivery-card.active-card.in_transit {
            border-left-color: var(--ec-info);
        }

        .delivery-card.active-card.picked_up {
            border-left-color: #8b5cf6;
        }

        .delivery-card.active-card.assigned {
            border-left-color: var(--ec-warning);
        }

        .delivery-card.completed-card {
            border-left: 4px solid var(--ec-success);
            opacity: 0.92;
        }

        .delivery-card.completed-card:hover {
            opacity: 1;
        }

        .delivery-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .delivery-id {
            font-weight: 800;
            color: var(--ec-primary);
            font-size: 1rem;
            font-family: 'Courier New', monospace;
            letter-spacing: -0.02em;
        }

        .delivery-date {
            font-size: .75rem;
            color: var(--ec-text-muted);
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .4rem .75rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: .02em;
        }

        .badge-assigned {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-picked_up {
            background: #ede9fe;
            color: #5b21b6;
        }
        .badge-in_transit {
            background: #dbeafe;
            color: #1e40af;
        }
        .badge-delivered {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-cod {
            background: #fef3c7;
            color: #92400e;
            border: 1px dashed #d97706;
        }
        .badge-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .delivery-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: .75rem;
            padding: .75rem 0;
        }

        .delivery-detail {
            display: flex;
            gap: .5rem;
            font-size: .85rem;
            align-items: flex-start;
        }

        .delivery-detail .label {
            color: var(--ec-text-muted);
            font-weight: 600;
            font-size: .75rem;
            min-width: 80px;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding-top: 2px;
        }

        .delivery-detail .value {
            font-weight: 600;
            color: var(--ec-text);
            flex: 1;
            word-break: break-word;
        }

        .delivery-detail .value.amount {
            color: var(--ec-accent);
            font-size: 1.05rem;
            font-weight: 800;
        }

        .delivery-actions {
            display: flex;
            gap: .5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--ec-border);
            flex-wrap: wrap;
        }

        .btn-ec {
            background: var(--ec-primary);
            border: 1px solid var(--ec-primary);
            color: #fff;
            font-weight: 700;
            border-radius: 10px;
            padding: .6rem 1.1rem;
            font-size: .85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .2s;
            cursor: pointer;
        }

        .btn-ec:hover {
            background: var(--ec-primary-dark);
            border-color: var(--ec-primary-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-ec-success {
            background: var(--ec-accent);
            border-color: var(--ec-accent);
        }

        .btn-ec-success:hover {
            background: var(--ec-accent-dark);
            border-color: var(--ec-accent-dark);
        }

        .btn-ec-outline {
            background: transparent;
            border: 1px solid var(--ec-border);
            color: var(--ec-text);
        }

        .btn-ec-outline:hover {
            background: var(--ec-bg);
            border-color: var(--ec-primary);
            color: var(--ec-primary);
        }

        /* ═══════════════ EMPTY STATE ═══════════════ */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--ec-text-muted);
            background: var(--ec-card);
            border-radius: var(--ec-radius);
            border: 2px dashed var(--ec-border);
        }

        .empty-state i {
            font-size: 3.5rem;
            opacity: .3;
            display: block;
            margin-bottom: 1rem;
        }

        .empty-state p {
            margin: 0;
            font-weight: 600;
            font-size: .95rem;
        }

        /* ═══════════════ PROOF PREVIEW ═══════════════ */
        .proof-preview-wrap {
            position: relative;
            border-radius: var(--ec-radius-sm);
            overflow: hidden;
            border: 2px solid var(--ec-border);
        }

        .proof-preview-wrap img {
            width: 100%;
            max-height: 280px;
            object-fit: cover;
            display: block;
        }

        .proof-remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(220, 53, 69, .9);
            color: #fff;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
        }

        .proof-remove-btn:hover {
            background: #dc3545;
            transform: scale(1.1);
        }

        .proof-upload-box {
            border: 2px dashed var(--ec-border);
            border-radius: var(--ec-radius-sm);
            padding: 2rem 1rem;
            text-align: center;
            background: var(--ec-bg);
            cursor: pointer;
            transition: all .2s;
            display: block;
        }

        .proof-upload-box:hover {
            border-color: var(--ec-primary);
            background: var(--ec-primary-light);
        }

        .proof-upload-box i {
            font-size: 2.5rem;
            color: var(--ec-primary);
            margin-bottom: .5rem;
            display: block;
        }

        .proof-upload-box .label {
            font-weight: 700;
            font-size: .95rem;
            color: var(--ec-text);
        }

        .proof-upload-box .hint {
            font-size: .75rem;
            color: var(--ec-text-muted);
            margin-top: .25rem;
        }

        /* ═══════════════ COD SECTION ═══════════════ */
        .cod-total-due {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 16px;
            text-align: center;
        }

        .cod-total-due .label {
            font-size: 12px;
            color: #92400e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .cod-total-due .amount {
            font-size: 2rem;
            font-weight: 800;
            color: #92400e;
            margin-top: 4px;
            letter-spacing: -0.02em;
        }

        .amount-input-wrap {
            position: relative;
        }

        .amount-input-wrap .currency {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 800;
            color: var(--ec-text-muted);
            font-size: 1.2rem;
        }

        .amount-input-wrap input {
            padding-left: 40px;
            font-size: 1.35rem;
            font-weight: 800;
            height: 60px;
            border: 2px solid var(--ec-border);
            border-radius: 12px;
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: all .2s;
        }

        .amount-input-wrap input:focus {
            outline: none;
            border-color: var(--ec-primary);
            box-shadow: 0 0 0 4px rgba(13,110,110,.1);
        }

        .collection-result {
            margin-top: 12px;
            border-radius: 12px;
            padding: 16px;
            animation: slideIn .3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .collection-result.error {
            background: #fee2e2;
            border: 2px solid #dc2626;
        }

        .collection-result.success {
            background: #d1fae5;
            border: 2px solid #10b981;
        }

        .collection-result.change {
            background: #fef3c7;
            border: 2px solid #f59e0b;
        }

        .collection-result .title {
            font-weight: 800;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .collection-result.error .title { color: #991b1b; }
        .collection-result.success .title { color: #065f46; }
        .collection-result.change .title { color: #92400e; }

        .collection-result .desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }

        .change-display {
            background: #fff;
            border: 2px dashed #f59e0b;
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
            text-align: center;
        }

        .change-display .label {
            font-size: 11px;
            color: #92400e;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .change-display .amount {
            font-size: 1.75rem;
            font-weight: 800;
            color: #f59e0b;
            margin-top: 4px;
            letter-spacing: -0.02em;
        }

        /* ═══════════════ MOBILE OPTIMIZATION ═══════════════ */
        @media (max-width: 767.98px) {
            .rider-header { padding: 1rem 1rem; }
            .rider-header h1 { font-size: 1.15rem; }
            .main-content { padding: 1rem .875rem 6rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: .625rem; }
            .stat-card { padding: .9rem 1rem; }
            .stat-value { font-size: 1.5rem; }
            .stat-value.small { font-size: 1.1rem; }
            .delivery-card { padding: 1rem; }
            .delivery-details { grid-template-columns: 1fr; gap: .5rem; }
            .delivery-actions { justify-content: stretch; }
            .delivery-actions .btn-ec { flex: 1; }
            .modal-dialog { margin: .5rem; }
            .cod-total-due .amount { font-size: 1.6rem; }
            .amount-input-wrap input { font-size: 1.15rem; height: 54px; }
        }

        @media (max-width: 400px) {
            .stat-value { font-size: 1.35rem; }
            .stat-value.small { font-size: 1rem; }
        }

        /* ═══════════════ CUSTOM SCROLLBAR ═══════════════ */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--ec-border); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--ec-text-muted); }
    </style>
</head>
<body>

    <!-- ═══ HEADER ═══ -->
    <div class="rider-header">
        <div class="rider-header-inner">
            <div style="display:flex; align-items:center; gap:14px; min-width:0;">
                <div class="avatar-badge">
                    <?= strtoupper(substr($rider['name'], 0, 1)) ?>
                </div>
                <div style="min-width:0;">
                    <h1><i class="bi bi-bicycle"></i> Rider Dashboard</h1>
                    <div class="subtitle">
                        <i class="bi bi-person-circle"></i>
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            Welcome, <?= htmlspecialchars($rider['name']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="btn-icon" onclick="refreshPage()" title="Refresh">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="btn-icon" onclick="logout()" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="main-content">

        <!-- ═══ STATS ═══ -->
        <div class="stats-grid">
            <div class="stat-card warning">
                <div class="stat-label"><i class="bi bi-hourglass-split"></i> Assigned</div>
                <div class="stat-value warning"><?= (int)($stats['assigned'] ?? 0) ?></div>
            </div>
            <div class="stat-card info">
                <div class="stat-label"><i class="bi bi-truck"></i> In Transit</div>
                <div class="stat-value info"><?= (int)($stats['in_transit'] ?? 0) ?></div>
            </div>
            <div class="stat-card success">
                <div class="stat-label"><i class="bi bi-check2-circle"></i> Delivered Today</div>
                <div class="stat-value success"><?= (int)($stats['delivered_today'] ?? 0) ?></div>
            </div>
            <div class="stat-card accent">
                <div class="stat-label"><i class="bi bi-cash-stack"></i> Collected Today</div>
                <div class="stat-value accent small">₱<?= number_format($totalCollectedToday, 2) ?></div>
            </div>
        </div>

        <!-- ═══ ACTIVE DELIVERIES ═══ -->
        <div class="section-header">
            <h5><i class="bi bi-truck"></i> My Active Deliveries</h5>
            <span class="section-count"><?= count($activeDeliveries) ?> item<?= count($activeDeliveries) != 1 ? 's' : '' ?></span>
        </div>

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
                ?>
                <div class="delivery-card active-card <?= $d['delivery_status'] ?>">
                    <div class="delivery-header">
                        <div>
                            <div class="delivery-id"><?= htmlspecialchars($d['reservation_code']) ?></div>
                            <div class="delivery-date">
                                <i class="bi bi-clock"></i>
                                <?= date('M d, Y • g:i A', strtotime($d['created_at'])) ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <span class="badge-status badge-<?= $st['class'] ?>">
                                <i class="bi bi-<?= $st['icon'] ?>"></i><?= $st['label'] ?>
                            </span>
                            <?php if ($isCOD): ?>
                                <span class="badge-status badge-cod">
                                    <i class="bi bi-cash-coin"></i>COD
                                </span>
                            <?php else: ?>
                                <span class="badge-status badge-paid">
                                    <i class="bi bi-check-circle"></i>PAID
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="delivery-details">
                        <div class="delivery-detail">
                            <span class="label">Customer</span>
                            <span class="value"><?= htmlspecialchars($d['customer_name']) ?></span>
                        </div>
                        <div class="delivery-detail">
                            <span class="label">Contact</span>
                            <span class="value"><?= htmlspecialchars($d['customer_contact'] ?? '—') ?></span>
                        </div>
                        <div class="delivery-detail" style="grid-column: 1 / -1;">
                            <span class="label">Address</span>
                            <span class="value">
                                <?= htmlspecialchars($d['delivery_address'] ?? '—') ?>
                                <?= $d['delivery_barangay'] ? ', ' . htmlspecialchars($d['delivery_barangay']) : '' ?>
                                <?= $d['delivery_city'] ? ', ' . htmlspecialchars($d['delivery_city']) : '' ?>
                            </span>
                        </div>
                        <div class="delivery-detail">
                            <span class="label">Total</span>
                            <span class="value amount">₱<?= number_format($d['total_amount'], 2) ?></span>
                        </div>
                        <?php if (!empty($d['tracking_number'])): ?>
                        <div class="delivery-detail">
                            <span class="label">Tracking</span>
                            <span class="value" style="font-family:monospace; font-size:.8rem;"><?= htmlspecialchars($d['tracking_number']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="delivery-actions">
                        <?php if ($d['delivery_status'] === 'assigned'): ?>
                            <button class="btn-ec" onclick="riderAction('pickup', <?= (int)$d['id'] ?>)">
                                <i class="bi bi-box-seam"></i> Mark as Picked Up
                            </button>
                        <?php elseif ($d['delivery_status'] === 'picked_up'): ?>
                            <button class="btn-ec" onclick="riderAction('transit', <?= (int)$d['id'] ?>)">
                                <i class="bi bi-truck"></i> Start Transit
                            </button>
                        <?php elseif ($d['delivery_status'] === 'in_transit'): ?>
                            <button class="btn-ec btn-ec-success" onclick='openDeliveryModal(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                <i class="bi bi-camera"></i> Deliver & Take Photo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ═══ DELIVERED TODAY ═══ -->
        <?php if (!empty($completedToday)): ?>
            <div class="section-header">
                <h5><i class="bi bi-check2-circle"></i> Delivered Today</h5>
                <span class="section-count"><?= count($completedToday) ?> item<?= count($completedToday) != 1 ? 's' : '' ?></span>
            </div>
            <?php foreach ($completedToday as $d): ?>
                <div class="delivery-card completed-card">
                    <div class="delivery-header">
                        <div>
                            <div class="delivery-id"><?= htmlspecialchars($d['reservation_code']) ?></div>
                            <div class="delivery-date">
                                <i class="bi bi-check2-all"></i>
                                Delivered at <?= date('g:i A', strtotime($d['delivered_at'])) ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <span class="badge-status badge-delivered">
                                <i class="bi bi-check2-all"></i>Delivered
                            </span>
                            <?php if (!empty($d['delivery_proof_image'])): ?>
                                <span class="badge-status" style="background:#e0f2fe;color:#075985;">
                                    <i class="bi bi-camera"></i>With Proof
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($d['collected_amount']) && $d['collected_amount'] > 0): ?>
                                <span class="badge-status badge-paid">
                                    <i class="bi bi-cash-stack"></i>₱<?= number_format($d['collected_amount'], 2) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="delivery-details">
                        <div class="delivery-detail">
                            <span class="label">Customer</span>
                            <span class="value"><?= htmlspecialchars($d['customer_name']) ?></span>
                        </div>
                        <div class="delivery-detail">
                            <span class="label">Total</span>
                            <span class="value amount">₱<?= number_format($d['total_amount'], 2) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($d['delivery_proof_image'])): ?>
                        <div style="margin-top:.75rem;">
                            <img src="../<?= htmlspecialchars($d['delivery_proof_image']) ?>" 
                                 alt="Proof" 
                                 class="rounded" 
                                 style="max-height: 140px; cursor: pointer; border:1px solid var(--ec-border);"
                                 onclick="window.open('../<?= htmlspecialchars($d['delivery_proof_image']) ?>', '_blank')">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ═══ DELIVERY HISTORY ═══ -->
        <?php if (!empty($deliveryHistory)): ?>
            <div class="section-header">
                <h5><i class="bi bi-clock-history"></i> Delivery History</h5>
                <span class="section-count"><?= count($deliveryHistory) ?> past<?= count($deliveryHistory) != 1 ? '' : '' ?> deliveries</span>
            </div>
            <?php foreach ($deliveryHistory as $d): ?>
                <div class="delivery-card completed-card" style="opacity:.85;">
                    <div class="delivery-header">
                        <div>
                            <div class="delivery-id"><?= htmlspecialchars($d['reservation_code']) ?></div>
                            <div class="delivery-date">
                                <i class="bi bi-calendar-check"></i>
                                <?= date('M d, Y • g:i A', strtotime($d['delivered_at'])) ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <span class="badge-status badge-delivered">
                                <i class="bi bi-check2-all"></i>Delivered
                            </span>
                            <?php if (!empty($d['collected_amount']) && $d['collected_amount'] > 0): ?>
                                <span class="badge-status badge-paid">
                                    <i class="bi bi-cash-stack"></i>₱<?= number_format($d['collected_amount'], 2) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="delivery-details">
                        <div class="delivery-detail">
                            <span class="label">Customer</span>
                            <span class="value"><?= htmlspecialchars($d['customer_name']) ?></span>
                        </div>
                        <div class="delivery-detail">
                            <span class="label">Total</span>
                            <span class="value amount">₱<?= number_format($d['total_amount'], 2) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <!-- ═══ DELIVERY MODAL with COD COLLECTION ═══ -->
    <div class="modal fade" id="deliveryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border:none; border-radius:var(--ec-radius); overflow:hidden;">
                <div class="modal-header" style="background:linear-gradient(135deg, var(--ec-primary), var(--ec-primary-dark)); color:white; border:none; padding:1.1rem 1.25rem;">
                    <h5 class="modal-title" style="font-weight:700; display:flex; align-items:center; gap:8px;">
                        <i class="bi bi-camera"></i> Deliver & Take Photo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="padding:1.25rem;">

                    <div style="background:var(--ec-bg); border-radius:10px; padding:12px 14px; margin-bottom:1rem;">
                        <div style="font-size:.7rem; color:var(--ec-text-muted); font-weight:700; text-transform:uppercase; letter-spacing:.05em;">Order</div>
                        <div style="font-weight:800; color:var(--ec-primary); font-size:1rem; font-family:monospace;" id="delOrderCode"></div>
                        <div style="font-size:.85rem; color:var(--ec-text); margin-top:4px;" id="delCustomer"></div>
                    </div>

                    <!-- ✅ COD COLLECTION SECTION -->
                    <div id="codCollectionSection" style="display:none;">
                        <div class="cod-total-due">
                            <div class="label">Total Amount Due</div>
                            <div class="amount" id="delTotalDue">₱0.00</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size:.85rem;">
                                <i class="bi bi-cash-stack me-1"></i>
                                Amount Received from Customer <span style="color:var(--ec-danger);">*</span>
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

                    <!-- ✅ Proof of Delivery Upload -->
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:.85rem;">
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
                        <label class="form-label" style="font-size:.85rem; font-weight:600;">
                            Notes <small style="color:var(--ec-text-muted);">(optional)</small>
                        </label>
                        <textarea class="form-control" id="delNotes" rows="2" 
                                  placeholder="e.g. Received by customer, signed..."
                                  style="border-radius:10px; font-size:.9rem;"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--ec-border); padding:1rem 1.25rem;">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px; font-weight:600;">Cancel</button>
                    <button class="btn btn-success" id="confirmDeliverBtn" style="border-radius:10px; font-weight:700; background:var(--ec-accent); border-color:var(--ec-accent);">
                        <i class="bi bi-check-lg me-1"></i>Confirm Delivery
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

                // COD VALIDATION
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

                // Non-COD
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

        // Validation function
        function validateCollection() {
            if (!currentIsCOD) return;

            const input = parseFloat(document.getElementById('amountReceived').value) || 0;
            const resultDiv = document.getElementById('collectionResult');
            const confirmBtn = document.getElementById('confirmDeliverBtn');

            resultDiv.style.display = 'none';
            confirmBtn.disabled = true;

            if (input === 0) return;

            // INSUFFICIENT
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

            // EXACT
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

            // OVERPAYMENT — HAS CHANGE
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
    </script>
</body>
</html>