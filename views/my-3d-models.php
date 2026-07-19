<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Supply Chain module (Professional or Enterprise plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('supply_chain')) {
    header('Location: ../views/subscription.php');
    exit;
}


if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!RBACHelper::hasPermission('my-3d-models_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access My 3D Models.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinic_id  = $_SESSION['clinic_id'] ?? 0;
$user_id    = $_SESSION['user_id']   ?? 0;
$user_role  = $_SESSION['role']      ?? 'User';
$user_name  = $_SESSION['name']      ?? 'User';

$canView    = RBACHelper::hasPermission('my-3d-models_view');
$canCreate  = RBACHelper::hasPermission('my-3d-models_create');
$canEdit    = RBACHelper::hasPermission('my-3d-models_edit');
$canDelete  = RBACHelper::hasPermission('my-3d-models_delete');
$canApprove = RBACHelper::hasPermission('my-3d-models_approve');
$canReject  = RBACHelper::hasPermission('my-3d-models_reject');

$activeTab = $_GET['tab'] ?? 'completed';

$stmt = $pdo->prepare("
    SELECT r.*,
           i.name as inventory_name, i.brand, i.item_code,
           (SELECT COUNT(*) FROM request_images WHERE request_id = r.id) as image_count,
           DATE_FORMAT(r.completed_at, '%M %d, %Y') as completed_date_formatted
    FROM custom_3d_requests r
    LEFT JOIN inventory i ON r.inventory_id = i.id
    WHERE r.clinic_id = ? AND r.status = 'completed'
    ORDER BY r.completed_at DESC
");
$stmt->execute([$clinic_id]);
$completedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT r.*,
           i.name as inventory_name, i.brand, i.item_code,
           (SELECT COUNT(*) FROM request_images WHERE request_id = r.id) as image_count,
           DATE_FORMAT(r.created_at, '%M %d, %Y') as created_date_formatted
    FROM custom_3d_requests r
    LEFT JOIN inventory i ON r.inventory_id = i.id
    WHERE r.clinic_id = ? AND r.status IN ('pending_payment', 'pending', 'processing')
    ORDER BY
        CASE
            WHEN r.payment_status = 'pending_payment' THEN 1
            WHEN r.payment_status = 'unpaid' THEN 2
            WHEN r.payment_status = 'paid' AND r.status = 'processing' THEN 3
            ELSE 4
        END,
        r.created_at DESC
");
$stmt->execute([$clinic_id]);
$pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCompleted = count($completedRequests);
$totalPending   = count($pendingRequests);
$totalSpent     = array_sum(array_column($completedRequests, 'price'));

$invStmt = $pdo->prepare("
    SELECT id, name, brand, category
    FROM inventory
    WHERE clinic_id = ? AND is_archived = 0 AND category = 'Frames'
    ORDER BY name
");
$invStmt->execute([$clinic_id]);
$inventoryItems = $invStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My 3D Models</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root { --teal: #008080; --teal-dark: #005f5f; --teal-light: #e6f3f3; }
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .card-soft { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        .icon-box { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .text-teal { color: var(--teal) !important; }
        .bg-teal { background-color: var(--teal) !important; }
        .btn-teal { background-color: var(--teal); color: white; border: none; }
        .btn-teal:hover { background-color: var(--teal-dark); color: white; }
        .btn-outline-teal { border: 1px solid var(--teal); color: var(--teal); background: transparent; }
        .btn-outline-teal:hover { background-color: var(--teal); color: white; }
        .hero-banner { background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%); border-radius: 16px; padding: 28px 32px; }
        .stats-number { font-size: 1.8rem; font-weight: 700; }
        .stats-label { color: #6b7280; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .request-card { transition: all 0.3s ease; height: 100%; }
        .request-card:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .model-preview { height: 190px; display: flex; align-items: center; justify-content: center; position: relative; cursor: pointer; background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%); }
        .model-badge { position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.9); backdrop-filter: blur(10px); padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 500; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-processing { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-unpaid { background: #fee2e2; color: #991b1b; }
        .status-pending-payment { background: #ffedd5; color: #9a3412; }
        .nav-tabs .nav-link { color: #6b7280; font-weight: 500; border: none; padding: 12px 24px; }
        .nav-tabs .nav-link:hover { color: var(--teal); border: none; }
        .nav-tabs .nav-link.active { color: var(--teal); font-weight: 600; border-bottom: 3px solid var(--teal); background: transparent; }
        .step-tab { flex: 1; text-align: center; padding: 9px 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .step-tab.active { background: var(--teal); color: white; }
        .step-tab.inactive { background: #e9ecef; color: #6b7280; }
        .step-tab:first-child { border-radius: 8px 0 0 8px; }
        .step-tab:last-child { border-radius: 0 8px 8px 0; }
        .summary-box { background: #f0f9f9; border: 1px solid #b2d8d8; border-radius: 10px; padding: 14px 18px; }
        #viewer-container { width: 100%; height: 500px; background: #f5f5f5; border-radius: 12px; overflow: hidden; }
        .upload-area { border: 2px dashed #c4cdd6; border-radius: 10px; padding: 24px; text-align: center; cursor: pointer; background: #f8fafc; transition: border-color 0.2s; }
        .upload-area:hover { border-color: var(--teal); }
        .img-preview-wrap { position: relative; height: 80px; border-radius: 8px; overflow: visible; }
        .img-preview-wrap img { width: 100%; height: 80px; object-fit: cover; display: block; border-radius: 8px; border: 1px solid #e5e7eb; }
        .img-remove-btn {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #dc2626;
            color: white;
            border: 2px solid white;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            line-height: 1;
            z-index: 10;
            transition: background 0.2s, transform 0.1s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        .img-remove-btn:hover { background: #b91c1c; transform: scale(1.15); }
        .upload-count { font-size: 0.78rem; color: var(--teal); font-weight: 600; margin-top: 6px; }
        
        /* Color Palette Styles */
        .color-palette-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(85px, 1fr));
            gap: 8px;
            max-height: 220px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f9fafb;
        }
        .color-palette-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            border: 1px solid #e5e7eb;
        }
        .color-palette-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: var(--teal);
        }
        .color-swatch {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            flex-shrink: 0;
        }
        .color-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: #374151;
        }
        .color-tag {
            display: inline-flex;
            align-items: center;
            background: #f3f4f6;
            border-radius: 30px;
            padding: 6px 12px;
            margin: 0 8px 8px 0;
            font-size: 0.85rem;
            gap: 8px;
        }
        .color-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .selected-colors-container {
            min-height: 50px;
            padding: 8px;
            background: #f9fafb;
            border-radius: 10px;
            border: 1px dashed #cbd5e1;
        }
        .custom-color-preview {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            transition: background-color 0.2s;
        }
        .color-palette-grid::-webkit-scrollbar {
            width: 6px;
        }
        .color-palette-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        .color-palette-grid::-webkit-scrollbar-thumb {
            background: var(--teal);
            border-radius: 3px;
        }
    </style>
</head>
<body>
<div class="container-fluid p-4">

    <!-- HERO BANNER -->
    <div class="hero-banner mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="text-white">
            <div class="d-flex align-items-center gap-2 mb-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"/>
                    <line x1="6" y1="6" x2="6.01" y2="6"/>
                    <line x1="6" y1="18" x2="6.01" y2="18"/>
                </svg>
                <span style="font-size:0.72rem;opacity:0.82;letter-spacing:1.2px" class="text-uppercase fw-semibold">EyeCore 3D Modeling Service</span>
            </div>
            <h2 class="fw-bold text-white mb-1">My 3D Models</h2>
            <p class="mb-0" style="opacity:0.85;font-size:0.9rem">Custom 3D frame models built for your product catalog — powered by EyeCore</p>
        </div>
        <div class="d-flex flex-column align-items-end gap-2">
            <div class="text-white text-end">
                <div style="font-size:0.72rem;opacity:0.8">per frame design</div>
                <div class="fw-bold" style="font-size:2rem;line-height:1.1">&#8369;100</div>
            </div>
            <?php if ($canCreate): ?>
            <button class="btn fw-semibold px-4 py-2" onclick="openRequestModal()" style="background:white;color:var(--teal);border:none;border-radius:10px">+ Request a 3D Model</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card-soft p-4 d-flex justify-content-between align-items-center">
                <div><div class="stats-label">Total Spent</div><div class="stats-number text-teal">&#8369;<?= number_format($totalSpent, 2) ?></div><div class="small text-muted mt-1">Lifetime investment</div></div>
                <div class="icon-box" style="background:#e6f3f3"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#008080" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-soft p-4 d-flex justify-content-between align-items-center">
                <div><div class="stats-label">Pending Requests</div><div class="stats-number text-teal"><?= $totalPending ?></div><div class="small text-muted mt-1">In progress</div></div>
                <div class="icon-box" style="background:#fef3c7"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#92400e"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-soft p-4 d-flex justify-content-between align-items-center">
                <div><div class="stats-label">Completed Models</div><div class="stats-number text-teal"><?= $totalCompleted ?></div><div class="small text-muted mt-1">Ready to use</div></div>
                <div class="icon-box" style="background:#dcfce7"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#166534"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $activeTab == 'pending' ? 'active' : '' ?>" href="main.php?view=my-3d-models&tab=pending">Pending Requests (<?= $totalPending ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab != 'pending' ? 'active' : '' ?>" href="main.php?view=my-3d-models">Completed Models (<?= $totalCompleted ?>)</a></li>
    </ul>

    <!-- SEARCH & FILTER -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-8"><input type="text" id="searchInput" class="form-control" placeholder="Search by product name..."></div>
            <div class="col-md-4"><select id="statusFilter" class="form-select"><option value="">All</option><?php if ($activeTab == 'pending'): ?><option value="pending_payment">Pending Payment</option><option value="unpaid">Awaiting Payment</option><option value="processing">Processing</option><?php else: ?><option value="thisMonth">This Month</option><option value="lastMonth">Last Month</option><option value="thisYear">This Year</option><?php endif; ?></select></div>
        </div>
    </div>

    <!-- PENDING TAB -->
    <?php if ($activeTab == 'pending'): ?>
        <?php if (empty($pendingRequests)): ?>
        <div class="card-soft p-5 text-center"><div class="text-muted mb-3" style="font-size:3rem">&#9203;</div><h4 class="text-muted">No pending requests</h4><p class="text-muted mb-4">You don't have any active 3D model requests yet.</p><?php if ($canCreate): ?><button class="btn btn-teal px-4" onclick="openRequestModal()">+ Request a 3D Model</button><?php endif; ?></div>
        <?php else: ?>
        <div class="row g-4" id="requestsGrid">
            <?php foreach ($pendingRequests as $req):
                if ($req['payment_status'] == 'pending_payment') { $statusClass = 'status-pending-payment'; $statusText = 'Pending Payment'; }
                elseif ($req['payment_status'] == 'unpaid') { $statusClass = 'status-unpaid'; $statusText = 'Awaiting Payment'; }
                elseif ($req['status'] == 'processing') { $statusClass = 'status-processing'; $statusText = 'Processing'; }
                else { $statusClass = 'status-pending'; $statusText = ucfirst($req['status']); }
            ?>
            <div class="col-md-6 col-lg-4 request-item" data-name="<?= strtolower(htmlspecialchars($req['product_name'])) ?>" data-status="<?= $req['payment_status'] == 'pending_payment' ? 'pending_payment' : ($req['payment_status'] == 'unpaid' ? 'unpaid' : $req['status']) ?>">
                <div class="card-soft overflow-hidden request-card">
                    <div class="model-preview">
                        <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="#008080" stroke-width="1" opacity="0.35"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        <?php if ($req['payment_status'] == 'pending_payment'): ?><div class="model-badge text-warning">&#128179; Payment Pending</div><?php elseif ($req['payment_status'] == 'unpaid'): ?><div class="model-badge text-danger">&#9888; Payment Required</div><?php elseif ($req['status'] == 'processing'): ?><div class="model-badge text-primary">&#9881; Processing</div><?php endif; ?>
                    </div>
                    <div class="p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2"><div><h5 class="fw-bold mb-1"><?= htmlspecialchars($req['product_name']) ?></h5><p class="text-muted small mb-0">Request #<?= htmlspecialchars($req['request_number']) ?></p></div><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></div>
                        <div class="mb-3 small text-muted"><div class="mb-1">&#128197; Requested: <?= date('M d, Y', strtotime($req['created_at'])) ?></div><div>&#127991; Type: Frame</div></div>
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top"><div><small class="text-muted">Price</small><h5 class="fw-bold text-teal mb-0">&#8369;<?= number_format($req['price'], 2) ?></h5></div>
                        <?php if ($req['payment_status'] == 'pending_payment' && $req['paymongo_checkout_id']): ?>
                        <button class="btn btn-sm btn-teal" onclick="continuePayment(<?= $req['id'] ?>, '<?= htmlspecialchars($req['paymongo_checkout_id']) ?>')">Continue Payment</button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-teal" onclick="viewRequestDetails(<?= $req['id'] ?>)">View Details</button>
                        <?php endif; ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if (empty($completedRequests)): ?>
        <div class="card-soft p-5 text-center"><div class="text-muted mb-3" style="font-size:3rem">&#128230;</div><h4 class="text-muted">No completed models yet</h4><p class="text-muted mb-4">Your finished 3D frame models will appear here.</p><?php if ($canCreate): ?><button class="btn btn-teal px-4" onclick="openRequestModal()">+ Request Your First 3D Model</button><?php endif; ?></div>
        <?php else: ?>
        <div class="row g-4" id="requestsGrid">
            <?php foreach ($completedRequests as $req): ?>
            <div class="col-md-6 col-lg-4 request-item" data-name="<?= strtolower(htmlspecialchars($req['product_name'])) ?>" data-date="<?= $req['completed_at'] ?>">
                <div class="card-soft overflow-hidden request-card">
                    <div class="model-preview" onclick="view3DModel(<?= $req['id'] ?>)"><svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#008080" stroke-width="1" opacity="0.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg><div class="model-badge">&#128065; Click to View 3D</div></div>
                    <div class="p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2"><div><h5 class="fw-bold mb-1"><?= htmlspecialchars($req['product_name']) ?></h5><p class="text-muted small mb-0">Request #<?= htmlspecialchars($req['request_number']) ?></p></div><span class="badge bg-teal">Completed</span></div>
                        <div class="mb-3 small text-muted"><div class="mb-1">&#9989; Completed: <?= date('M d, Y', strtotime($req['completed_at'])) ?></div><div>&#127991; Type: Frame</div></div>
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top"><div><small class="text-muted">Price</small><h5 class="fw-bold text-teal mb-0">&#8369;<?= number_format($req['price'], 2) ?></h5></div>
                        <div class="d-flex gap-2"><button class="btn btn-sm btn-outline-teal" onclick="view3DModel(<?= $req['id'] ?>)" title="View 3D">&#128065;</button><button class="btn btn-sm btn-outline-secondary" onclick="postToCatalog(<?= $req['id'] ?>, '<?= htmlspecialchars($req['product_name'], ENT_QUOTES) ?>')" title="Post to Catalog">&#128228;</button><?php if ($req['completed_model_file']): ?><a href="/eyecore/<?= htmlspecialchars($req['completed_model_file']) ?>" class="btn btn-sm btn-teal" download title="Download">&#11015;</a><?php endif; ?></div></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- REQUEST MODAL -->
<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;max-height:90vh;">
            <div class="modal-header border-0 p-0">
                <div class="w-100 p-4" style="background:linear-gradient(135deg,#008080,#005f5f)">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="text-white"><div class="small fw-semibold text-uppercase mb-1" style="opacity:0.8;letter-spacing:1px">EyeCore 3D Service</div><h5 class="fw-bold text-white mb-1">Request a Custom 3D Model</h5><p class="mb-0" style="opacity:0.85;font-size:0.85rem">Fill in the details we'll build it for your catalog</p></div>
                        <div class="text-white text-end"><div style="font-size:0.7rem;opacity:0.8">per frame design</div><div class="fw-bold" style="font-size:1.8rem;line-height:1">&#8369;100</div><button type="button" class="btn-close btn-close-white mt-2" data-bs-dismiss="modal" style="font-size:0.7rem"></button></div>
                    </div>
                </div>
            </div>
            <div class="modal-body p-4" style="max-height: calc(90vh - 140px); overflow-y: auto;">
                <div class="d-flex mb-4">
                    <div class="step-tab active" id="step1_tab" onclick="showStep(1)">1. Product Info</div>
                    <div class="step-tab inactive" id="step2_tab" onclick="showStep(2)">2. References</div>
                    <div class="step-tab inactive" id="step3_tab" onclick="showStep(3)">3. Payment</div>
                </div>
                        
<!-- STEP 1 - UPDATED WITH COLOR PALETTE (WITH SCROLLBAR) -->
<div id="step1_content">
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label fw-semibold">Inventory Item <span class="text-danger">*</span> <span class="text-muted fw-normal small ms-1">(which product to model?)</span></label>
            <select class="form-select" id="req_item_id" onchange="autoFillName()">
                <option value="">-- Select from your inventory --</option>
                <?php $currentCategory = ''; foreach ($inventoryItems as $inv): if ($inv['category'] !== $currentCategory): if ($currentCategory !== '') echo '</optgroup>'; $currentCategory = $inv['category']; echo '<optgroup label="' . htmlspecialchars($currentCategory) . '">'; endif; ?>
                <option value="<?= $inv['id'] ?>" data-name="<?= htmlspecialchars($inv['name']) ?>" data-brand="<?= htmlspecialchars($inv['brand'] ?? '') ?>"><?= htmlspecialchars($inv['name']) ?><?= $inv['brand'] ? ' - ' . htmlspecialchars($inv['brand']) : '' ?></option>
                <?php endforeach; if ($currentCategory !== '') echo '</optgroup>'; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Frame/Brand Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="req_product_name" placeholder="e.g. Ray-Ban Aviator Classic">
            <div class="form-text">Enter the brand and model name of the frame</div>
        </div>
        <input type="hidden" id="req_model_type" value="frame">
        <input type="hidden" id="selected_colors_input" value="">
        
        <!-- COLOR PICKER WITH PALETTE (WITH SCROLLBAR) -->
        <div class="col-12">
            <label class="form-label fw-semibold">Frame Colors <span class="text-danger">*</span></label>
            
            <!-- Pre-defined Color Palette with Scrollbar -->
            <div class="mb-3">
                <div class="small text-muted mb-2">🎨 Click on any color to select:</div>
                <div id="colorPaletteContainer" class="color-palette-grid" style="max-height: 200px; overflow-y: auto;"></div>
            </div>
            
            <!-- OR Divider -->
            <div class="d-flex align-items-center my-3">
                <hr class="flex-grow-1">
                <span class="mx-2 text-muted small">OR</span>
                <hr class="flex-grow-1">
            </div>
            
            <!-- Custom Color Picker -->
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="small text-muted mb-1">Pick Custom Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" id="customColorPicker" class="form-control form-control-color" value="#008080" style="width: 50px; height: 45px; padding: 2px;">
                        <div id="customColorPreview" class="custom-color-preview" style="background-color: #008080; width: 40px; height: 40px; border-radius: 8px; border: 2px solid #e5e7eb;"></div>
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="small text-muted mb-1">Color Name</label>
                    <input type="text" id="customColorName" class="form-control" placeholder="e.g., Forest Green, Midnight Blue" maxlength="30">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-teal w-100" type="button" onclick="addCustomColor()">
                        <i class="bi bi-plus-lg"></i> Add
                    </button>
                </div>
            </div>
            <div class="form-text mt-2">Choose from the color palette above or pick your own custom color. Maximum 5 colors.</div>
            
            <!-- Error Message -->
            <div class="text-danger small mt-2" id="colorError" style="display:none;">
                ⚠️ Maximum 5 colors only!
            </div>
            
            <!-- Selected Colors Display -->
            <div class="mt-3">
                <label class="small text-muted mb-1">Selected Colors:</label>
                <div class="selected-colors-container" id="selectedColorsContainer" style="min-height: 50px; padding: 8px; background: #f9fafb; border-radius: 10px; border: 1px dashed #cbd5e1;"></div>
            </div>
        </div>
        
        <div class="col-12">
            <label class="form-label fw-semibold">Additional Notes</label>
            <textarea class="form-control" id="req_notes" rows="3" placeholder="Material details, logo placement, special instructions..."></textarea>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-4"><button class="btn btn-teal px-4" onclick="goToStep(2)">Next: References →</button></div>
</div>

                <!-- STEP 2 -->
                <div id="step2_content" style="display:none">
                    <p class="text-muted small mb-3">Upload photos of the actual frame to guide our 3D artist. Max 5 images. (Recommended)</p>

                    <input type="file" id="req_images" multiple accept="image/jpeg,image/png,image/jpg" style="display:none">

                    <div class="upload-area mb-3" id="uploadTrigger">
                        <div style="font-size:2rem">&#128248;</div>
                        <p class="text-muted mb-0 small mt-1">Click to upload reference photos</p>
                        <p class="text-muted mb-0" style="font-size:0.75rem">JPG, PNG only &mdash; up to 5 images</p>
                    </div>

                    <div id="uploadCount" class="upload-count" style="display:none"></div>
                    <div id="imagePreview" class="row g-3 mb-3 mt-1"></div>

                    <div class="d-flex justify-content-between mt-2">
                        <button class="btn btn-outline-secondary" onclick="goToStep(1)">Back</button>
                        <button class="btn btn-teal px-4" onclick="goToStep(3)">Next: Payment</button>
                    </div>
                </div>

                <!-- STEP 3 -->
                <div id="step3_content" style="display:none">
                    <div class="summary-box mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="fw-semibold text-teal">Custom 3D Frame Model</div>
                                <div class="small text-muted" id="summary_product_name">-</div>
                                <div class="small text-muted" id="summary_colors">Colors: -</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <div class="fw-semibold">Total Amount:</div>
                            <div class="fw-bold text-teal" style="font-size:1.3rem">&#8369;100.00</div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-4"><input type="radio" class="btn-check" name="payment_method" id="pm_gcash" value="gcash"><label class="btn btn-outline-secondary w-100 py-2" for="pm_gcash">&#128241; GCash</label></div>
                                <div class="col-4"><input type="radio" class="btn-check" name="payment_method" id="pm_maya" value="maya"><label class="btn btn-outline-secondary w-100 py-2" for="pm_maya">&#128179; Maya</label></div>
                                <div class="col-4"><input type="radio" class="btn-check" name="payment_method" id="pm_bank" value="bank_transfer"><label class="btn btn-outline-secondary w-100 py-2" for="pm_bank">&#127970; Bank Transfer</label></div>
                            </div>
                        </div>
                        <div id="gcash_details" class="col-12" style="display:none"><div class="alert alert-info py-2 mb-0 small"><strong>GCash:</strong> 0917 123 4567 &mdash; Eyecore 3D Services</div></div>
                        <div id="maya_details" class="col-12" style="display:none"><div class="alert alert-info py-2 mb-0 small"><strong>Maya:</strong> 0917 123 4567 &mdash; Eyecore 3D Services</div></div>
                        <div id="bank_details" class="col-12" style="display:none"><div class="alert alert-info py-2 mb-0 small"><strong>Bank Transfer:</strong> BDO Account # 1234-5678-90 (Eyecore Inc.)</div></div>
                        <div class="col-12"><div class="alert alert-warning py-2 mb-0 small text-center">&#9888;&#65039; Send exact amount: <strong>&#8369;100.00</strong></div></div>
                        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="termsCheckbox" required><label class="form-check-label small text-muted" for="termsCheckbox">I understand this is a paid service (&#8369;100) processed after payment confirmation.</label></div></div>
                    </div>
                    <div class="d-flex justify-content-between mt-4"><button class="btn btn-outline-secondary" onclick="goToStep(2)">Back</button><button class="btn btn-teal px-4 fw-semibold" onclick="submitRequest()">Proceed to Payment (&#8369;100)</button></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- VIEWER MODAL -->
<div class="modal fade" id="viewerModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-teal text-white">
                <h5 class="modal-title">3D Model Viewer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- COLOR SWITCHER STRIP -->
                <div id="viewerColorSwitcher" style="display:none; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span style="font-size:0.82rem; font-weight:600; color:#374151;">🎨 Colors:</span>
                        <div id="viewerColorButtons" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                        <span style="font-size:0.82rem; color:#6b7280; margin-left:8px;">Viewing: 
                            <strong id="viewerCurrentColor">—</strong>
                        </span>
                    </div>
                </div>
                <div id="viewer-container"></div>
            </div>
            <div class="modal-footer">
                <div class="d-flex justify-content-between w-100 align-items-center">
                    <span id="currentModelName" class="fw-bold"></span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-teal btn-sm" onclick="rotateLeft()">↺ Left</button>
                        <button class="btn btn-outline-teal btn-sm" onclick="rotateRight()">↻ Right</button>
                        <button class="btn btn-outline-teal btn-sm" onclick="resetView()">↻ Reset</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DETAILS MODAL -->
<div class="modal fade" id="requestDetailsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-teal text-white"><h5 class="modal-title">Request Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="requestDetailsBody">Loading...</div></div></div></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
<script>
const permissions = {
    canView: <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit: <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};
let activeTab = '<?= $activeTab ?>';
let currentStep = 1;
let selectedColors = [];
let selectedFiles = [];

// =============================================
// COLOR PALETTE DATA
// =============================================
const colorPalette = [
    { name: 'Black', hex: '#000000' },
    { name: 'White', hex: '#FFFFFF' },
    { name: 'Gold', hex: '#FFD700' },
    { name: 'Silver', hex: '#C0C0C0' },
    { name: 'Rose Gold', hex: '#B76E79' },
    { name: 'Copper', hex: '#B87333' },
    { name: 'Gunmetal', hex: '#2C3539' },
    { name: 'Matte Black', hex: '#2C2C2C' },
    { name: 'Gloss Black', hex: '#111111' },
    { name: 'Tortoise', hex: '#8B5A2B' },
    { name: 'Red', hex: '#FF0000' },
    { name: 'Blue', hex: '#0000FF' },
    { name: 'Green', hex: '#00FF00' },
    { name: 'Purple', hex: '#800080' },
    { name: 'Pink', hex: '#FF69B4' },
    { name: 'Orange', hex: '#FFA500' },
    { name: 'Brown', hex: '#8B4513' },
    { name: 'Teal', hex: '#008080' },
    { name: 'Navy', hex: '#000080' },
    { name: 'Maroon', hex: '#800000' },
    { name: 'Coral', hex: '#FF7F50' },
    { name: 'Lavender', hex: '#E6E6FA' },
    { name: 'Beige', hex: '#F5F5DC' },
    { name: 'Champagne', hex: '#F7E7CE' },
    { name: 'Bronze', hex: '#CD7F32' },
    { name: 'Turquoise', hex: '#40E0D0' },
    { name: 'Magenta', hex: '#FF00FF' },
    { name: 'Cyan', hex: '#00FFFF' },
    { name: 'Olive', hex: '#808000' },
    { name: 'Crimson', hex: '#DC143C' },
    { name: 'Indigo', hex: '#4B0082' }
];

// =============================================
// COLOR PICKER FUNCTIONS
// =============================================
function initColorPalette() {
    const paletteContainer = document.getElementById('colorPaletteContainer');
    if (!paletteContainer) return;
    
    paletteContainer.innerHTML = '';
    
    colorPalette.forEach(color => {
        const colorBtn = document.createElement('div');
        colorBtn.className = 'color-palette-item';
        colorBtn.setAttribute('data-color-name', color.name);
        colorBtn.setAttribute('data-color-hex', color.hex);
        colorBtn.onclick = () => addColorFromPalette(color.name, color.hex);
        colorBtn.innerHTML = `
            <div class="color-swatch" style="background-color: ${color.hex}; border: 1px solid ${color.hex === '#FFFFFF' ? '#ddd' : 'transparent'};"></div>
            <span class="color-label">${escapeHtml(color.name)}</span>
        `;
        paletteContainer.appendChild(colorBtn);
    });
}

function addColorFromPalette(colorName, colorHex) {
    if (selectedColors.some(c => c.name.toLowerCase() === colorName.toLowerCase())) {
        Swal.fire('Duplicate Color', 'This color is already selected.', 'warning');
        return;
    }
    if (selectedColors.length >= 5) {
        document.getElementById('colorError').style.display = 'block';
        setTimeout(() => { document.getElementById('colorError').style.display = 'none'; }, 2000);
        return;
    }

    selectedColors.push({ name: colorName, hex: colorHex });
    updateSelectedColorsDisplay();
}

function addCustomColor() {
    const colorHex = document.getElementById('customColorPicker').value;
    let colorName = document.getElementById('customColorName').value.trim();
    
    if (!colorName) {
        Swal.fire('Missing Color Name', 'Please enter a color name (e.g., Forest Green, Midnight Blue)', 'warning');
        return;
    }
    if (selectedColors.some(c => c.name.toLowerCase() === colorName.toLowerCase())) {
        Swal.fire('Duplicate Color', 'This color is already selected.', 'warning');
        return;
    }
    if (selectedColors.length >= 5) {
        document.getElementById('colorError').style.display = 'block';
        setTimeout(() => { document.getElementById('colorError').style.display = 'none'; }, 2000);
        return;
    }

    selectedColors.push({ name: colorName, hex: colorHex });
    updateSelectedColorsDisplay();
    
    // Clear custom inputs
    document.getElementById('customColorPicker').value = '#008080';
    document.getElementById('customColorName').value = '';
    document.getElementById('customColorPreview').style.backgroundColor = '#008080';
}

function updateSelectedColorsDisplay() {
    const container = document.getElementById('selectedColorsContainer');
    if (!container) return;
    
    container.innerHTML = '';

    if (selectedColors.length === 0) {
        container.innerHTML = '<div class="text-muted small">No colors selected yet. Click on colors above or add custom colors.</div>';
        return;
    }

    selectedColors.forEach(function(color, index) {
        const tag = document.createElement('div');
        tag.className = 'color-tag';
        tag.innerHTML = `
            <div class="color-dot" style="background-color: ${color.hex}; border: 1px solid ${color.hex === '#FFFFFF' ? '#ddd' : 'transparent'};"></div>
            <span>${escapeHtml(color.name)}</span>
            <span style="cursor:pointer; margin-left:8px; color:#dc2626; font-weight:bold;" onclick="removeColor(${index})">&times;</span>
        `;
        container.appendChild(tag);
    });

    document.getElementById('selected_colors_input').value = selectedColors.map(c => c.name).join(', ');
}

function removeColor(index) {
    selectedColors.splice(index, 1);
    updateSelectedColorsDisplay();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// UPLOAD IMAGE LOGIC
// =============================================
document.getElementById('uploadTrigger').addEventListener('click', function () {
    document.getElementById('req_images').click();
});

document.getElementById('req_images').addEventListener('change', function () {
    let newFiles = Array.from(this.files);
    let combined = selectedFiles.concat(newFiles);

    if (combined.length > 5) {
        Swal.fire('Too Many Images', 'Maximum 5 images lang. Mag-remove muna ng ilan.', 'warning');
        this.value = '';
        return;
    }

    newFiles.forEach(function (newFile) {
        let exists = selectedFiles.some(f => f.name === newFile.name && f.size === newFile.size);
        if (!exists) selectedFiles.push(newFile);
    });

    if (selectedFiles.length > 5) selectedFiles = selectedFiles.slice(0, 5);
    this.value = '';
    renderPreviews();
});

function renderPreviews() {
    let preview = document.getElementById('imagePreview');
    let countEl = document.getElementById('uploadCount');
    preview.innerHTML = '';

    if (selectedFiles.length === 0) { countEl.style.display = 'none'; return; }

    countEl.style.display = 'block';
    countEl.textContent = selectedFiles.length + ' / 5 image' + (selectedFiles.length > 1 ? 's' : '') + ' selected';

    selectedFiles.forEach(function (file, index) {
        let col  = document.createElement('div');
        col.className = 'col-3';

        let wrap = document.createElement('div');
        wrap.className = 'img-preview-wrap';

        let removeBtn = document.createElement('button');
        removeBtn.className = 'img-remove-btn';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Remove this image';
        removeBtn.type = 'button';
        removeBtn.setAttribute('data-index', index);
        removeBtn.addEventListener('click', function () {
            removeImage(parseInt(this.getAttribute('data-index')));
        });

        let img = document.createElement('img');
        img.alt = file.name;
        img.style.opacity = '0.5';

        wrap.appendChild(img);
        wrap.appendChild(removeBtn);
        col.appendChild(wrap);
        preview.appendChild(col);

        let reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; img.style.opacity = '1'; };
        reader.readAsDataURL(file);
    });
}

function removeImage(index) {
    selectedFiles.splice(index, 1);
    renderPreviews();
}

// =============================================
// STEP NAVIGATION
// =============================================
function showStep(step) {
    currentStep = step;
    [1, 2, 3].forEach(function (s) {
        let isActive = s === step;
        document.getElementById('step' + s + '_content').style.display = isActive ? '' : 'none';
        let tab = document.getElementById('step' + s + '_tab');
        tab.classList.remove('active', 'inactive');
        tab.classList.add(isActive ? 'active' : 'inactive');
    });

    if (step === 3) {
        fetchPaymentDetails();
        document.getElementById('summary_product_name').textContent = document.getElementById('req_product_name').value || '-';
        document.getElementById('summary_colors').textContent = 'Colors: ' + (selectedColors.map(c => c.name).join(', ') || 'Not specified');
    }
}

function fetchPaymentDetails() {
    $.ajax({
        url: '/api/3d_request.php?action=get_payment_details',
        dataType: 'json',
        success: function (res) {
            if (res.success && res.data) {
                let d = res.data;
                if (d.gcash_number)       $('#gcash_details').html('<div class="alert alert-info py-2 mb-0 small"><strong>GCash:</strong> ' + d.gcash_number + ' — ' + (d.gcash_name || '') + '</div>');
                if (d.maya_number)        $('#maya_details').html('<div class="alert alert-info py-2 mb-0 small"><strong>Maya:</strong> ' + d.maya_number + ' — ' + (d.maya_name || '') + '</div>');
                if (d.bank_account_number) $('#bank_details').html('<div class="alert alert-info py-2 mb-0 small"><strong>' + (d.bank_name || 'Bank') + ':</strong> ' + d.bank_account_number + ' — ' + (d.bank_account_name || '') + '</div>');
            }
        }
    });
}

function goToStep(step) {
    if (step === 2 || step === 3) {
        if (!document.getElementById('req_item_id').value) {
            Swal.fire('Missing Field', 'Please select an inventory item.', 'warning');
            showStep(1); return;
        }
        if (!document.getElementById('req_product_name').value.trim()) {
            Swal.fire('Missing Field', 'Please enter the frame/brand name.', 'warning');
            showStep(1); return;
        }
        if (selectedColors.length === 0) {
            Swal.fire('Missing Field', 'Please add at least one color for the frame.', 'warning');
            showStep(1); return;
        }
    }
    showStep(step);
}

function openRequestModal() {
    if (!permissions.canCreate) {
        Swal.fire('Access Denied', "You don't have permission to request 3D models.", 'error');
        return;
    }

    document.getElementById('req_item_id').value = '';
    document.getElementById('req_product_name').value = '';
    document.getElementById('req_notes').value = '';
    document.getElementById('req_images').value = '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('uploadCount').style.display = 'none';
    document.querySelectorAll('input[name="payment_method"]').forEach(r => r.checked = false);
    document.getElementById('termsCheckbox').checked = false;
    document.getElementById('gcash_details').style.display = 'none';
    document.getElementById('maya_details').style.display = 'none';
    document.getElementById('bank_details').style.display = 'none';

    selectedColors = [];
    document.getElementById('customColorPicker').value = '#008080';
    document.getElementById('customColorName').value = '';
    document.getElementById('customColorPreview').style.backgroundColor = '#008080';
    updateSelectedColorsDisplay();

    selectedFiles = [];
    showStep(1);
    $('#requestModal').modal('show');
}

function autoFillName() {
    let sel = document.getElementById('req_item_id');
    let opt = sel.options[sel.selectedIndex];
    let name  = opt.getAttribute('data-name')  || '';
    let brand = opt.getAttribute('data-brand') || '';
    document.getElementById('req_product_name').value = brand ? name + ' - ' + brand : name;
}

$(document).ready(function () {
    // Initialize color palette
    initColorPalette();
    
    // Live preview for custom color picker
    $('#customColorPicker').on('input', function() {
        $('#customColorPreview').css('background-color', $(this).val());
    });
    
    // Allow Enter key to add custom color
    $('#customColorName').on('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addCustomColor();
        }
    });
    
    $('#searchInput').on('keyup', function () {
        let q = $(this).val().toLowerCase();
        $('.request-item').each(function () {
            $(this).toggle(($(this).data('name') || '').toLowerCase().includes(q));
        });
    });

    $('#statusFilter').on('change', function () {
        let f = $(this).val(), now = new Date();
        $('.request-item').each(function () {
            if (activeTab === 'pending') {
                $(this).toggle(!f || $(this).data('status') === f);
            } else {
                if (!f) { $(this).show(); return; }
                let d = new Date($(this).data('date')), show = false;
                if (f === 'thisMonth')  show = d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
                else if (f === 'lastMonth') { let lm = new Date(now); lm.setMonth(now.getMonth() - 1); show = d.getMonth() === lm.getMonth() && d.getFullYear() === lm.getFullYear(); }
                else if (f === 'thisYear') show = d.getFullYear() === now.getFullYear();
                $(this).toggle(show);
            }
        });
    });

    $('input[name="payment_method"]').on('change', function () {
        $('#gcash_details, #maya_details, #bank_details').hide();
        if ($(this).val() === 'gcash')         $('#gcash_details').show();
        if ($(this).val() === 'maya')          $('#maya_details').show();
        if ($(this).val() === 'bank_transfer') $('#bank_details').show();
    });
});

function submitRequest() {
    let item_id      = document.getElementById('req_item_id').value;
    let product_name = document.getElementById('req_product_name').value.trim();
    let terms        = document.getElementById('termsCheckbox').checked;
    let colors       = selectedColors.map(c => c.name).join(', ');

    if (!item_id)                { Swal.fire('Missing Field', 'Please select an inventory item.', 'warning'); return; }
    if (!product_name)           { Swal.fire('Missing Field', 'Please enter the frame/brand name.', 'warning'); return; }
    if (selectedColors.length === 0) { Swal.fire('Missing Field', 'Please add at least one color.', 'warning'); return; }
    if (!terms)                  { Swal.fire('Confirmation Required', 'Please confirm that you understand this is a paid service.', 'warning'); return; }

    Swal.fire({ title: 'Creating your request...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    let formData = new FormData();
    formData.append('action', 'create_3d_request_temp');
    formData.append('item_id', item_id);
    formData.append('product_name', product_name);
    formData.append('model_type', 'frame');
    formData.append('colors', colors);
    formData.append('notes', document.getElementById('req_notes').value);
    selectedFiles.forEach(f => formData.append('images[]', f));

    $.ajax({
        url: '/api/3d_request.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (res) {
            if (res.success && res.request_id) {
                $.post('/api/paymongo.php', {
                    action: 'create_3d_model_checkout',
                    request_id: res.request_id,
                    amount: 100,
                    product_name: product_name + ' (' + colors + ')'
                }, function (paymentRes) {
                    if (paymentRes.success && paymentRes.checkout_url) {
                        Swal.close();
                        window.location.href = paymentRes.checkout_url;
                    } else {
                        Swal.fire('Payment Error', paymentRes.error || 'Failed to create payment session.', 'error');
                    }
                }, 'json').fail(function () {
                    Swal.fire('Payment Error', 'Unable to connect to payment gateway.', 'error');
                });
            } else {
                Swal.fire('Error', res.message || 'Failed to create request.', 'error');
            }
        },
        error: function () {
            Swal.close();
            Swal.fire('Error', 'Network error. Please try again.', 'error');
        }
    });
}

function continuePayment(requestId, checkoutId) {
    Swal.fire({ title: 'Continue Payment', text: 'You will be redirected to complete your payment.', icon: 'info', showCancelButton: true, confirmButtonColor: '#008080', confirmButtonText: 'Continue', cancelButtonText: 'Cancel' })
    .then(function (result) { if (result.isConfirmed) window.location.href = '/api/paymongo_redirect.php?checkout_id=' + checkoutId + '&request_id=' + requestId; });
}

function postToCatalog(requestId, productName) {
    Swal.fire({ title: 'Post to Catalog?', html: 'Post <strong>' + productName + '</strong> 3D model to your product catalog?', icon: 'question', showCancelButton: true, confirmButtonColor: '#008080', confirmButtonText: 'Yes, Post It' })
    .then(function (result) {
        if (!result.isConfirmed) return;
        $.post('/api/3d_request.php', { action: 'post_to_catalog', request_id: requestId }, function (res) {
            if (res.success) Swal.fire('Posted!', '3D model is now visible in your product catalog.', 'success');
            else Swal.fire('Error', res.message || 'Failed to post to catalog.', 'error');
        }, 'json');
    });
}

// =============================================
// LENS DETECTION HELPER
// =============================================
function isLensMaterial(node, material) {
    // Check by material name
    const materialName = (material.name || '').toLowerCase();
    const nodeName = (node.name || '').toLowerCase();
    
    // Common lens material keywords
    const lensKeywords = ['lens', 'glass', 'clear', 'transparent', 'window', 'lense', 'optic', 'lenses'];
    
    // Check if material name or node name contains lens keyword
    for (let keyword of lensKeywords) {
        if (materialName.includes(keyword) || nodeName.includes(keyword)) {
            return true;
        }
    }
    
    // Check if material has transparency (lenses are often transparent)
    if (material.transparent === true || material.opacity < 1) {
        return true;
    }
    
    return false;
}

// =============================================
// COLOR HELPERS FOR 3D VIEWER
// =============================================
function getViewerColorHex(colorName) {
    const map = {
        'Black':'#000000','Red':'#ff0000','Green':'#00ff00','Blue':'#0000ff',
        'Gold':'#ffd700','Silver':'#c0c0c0','Pink':'#ff69b4','White':'#ffffff',
        'Purple':'#800080','Orange':'#ffa500','Brown':'#8b4513','Cyan':'#00ffff',
        'Magenta':'#ff00ff','Yellow':'#ffff00','Navy':'#000080','Maroon':'#800000',
        'Olive':'#808000','Teal':'#008080','Lavender':'#e6e6fa','Coral':'#ff7f50',
        'Rose Gold':'#B76E79','Copper':'#B87333','Gunmetal':'#2C3539','Matte Black':'#2C2C2C',
        'Gloss Black':'#111111','Tortoise':'#8B5A2B','Beige':'#F5F5DC','Champagne':'#F7E7CE',
        'Bronze':'#CD7F32','Turquoise':'#40E0D0','Crimson':'#DC143C','Indigo':'#4B0082'
    };
    if (map[colorName]) return map[colorName];
    const key = Object.keys(map).find(k => k.toLowerCase() === colorName.toLowerCase());
    if (key) return map[key];
    if (/^#[0-9a-f]{6}$/i.test(colorName)) return colorName;
    return '#888888';
}

function getViewerColorHexNumber(colorName) {
    return parseInt(getViewerColorHex(colorName).replace('#', ''), 16);
}

// =============================================
// GLOBAL VARIABLES FOR 3D VIEWER
// =============================================
let currentScene, currentCamera, currentRenderer, currentControls, currentModel;
let originalLensColors = new Map(); // Store original lens colors

// =============================================
// SWITCH VIEWER COLOR (EXCLUDES LENS)
// =============================================
function switchViewerColor(colorName) {
    if (!currentModel) return;

    // Update button active states
    document.querySelectorAll('.viewer-color-btn').forEach(btn => {
        const isActive = btn.getAttribute('data-color') === colorName;
        btn.classList.toggle('active', isActive);
        if (isActive) {
            btn.style.borderColor = '#008080';
            btn.style.background = '#008080';
            btn.style.color = 'white';
        } else {
            btn.style.borderColor = '#e5e7eb';
            btn.style.background = 'white';
            btn.style.color = '#374151';
        }
    });

    // Update current color display
    const currentColorSpan = document.getElementById('viewerCurrentColor');
    if (currentColorSpan) currentColorSpan.textContent = colorName;

    try {
        currentModel.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                
                materials.forEach((mat, idx) => {
                    if (mat && mat.color) {
                        const isLens = isLensMaterial(node, mat);
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        
                        if (!isLens) {
                            // Change frame color only
                            mat.color.setHex(getViewerColorHexNumber(colorName));
                            const darkColors = ['Black', 'Navy', 'Maroon', 'Matte Black', 'Gloss Black', 'Gunmetal'];
                            mat.emissiveIntensity = darkColors.includes(colorName) ? 0.1 : 0;
                        } else if (originalLensColors.has(key)) {
                            // Restore original lens color
                            const original = originalLensColors.get(key);
                            mat.color.setHex(original.color);
                            mat.transparent = original.transparent;
                            mat.opacity = original.opacity;
                        }
                    }
                });
            }
        });
    } catch (error) {
        console.error('Error changing color:', error);
    }
}

// =============================================
// INIT 3D VIEWER WITH LENS DETECTION
// =============================================
function init3DViewer(modelPath, modelName, colorsArray) {
    colorsArray = colorsArray || [];

    let container = document.getElementById('viewer-container');
    container.innerHTML = '';
    document.getElementById('currentModelName').textContent = modelName;

    const btnContainer = document.getElementById('viewerColorButtons');
    btnContainer.innerHTML = '';

    // Clear previous lens colors
    originalLensColors.clear();

    if (colorsArray.length > 0) {
        colorsArray.forEach(color => {
            const hexVal = getViewerColorHex(color);
            const btn    = document.createElement('button');
            btn.type     = 'button';
            btn.className = 'viewer-color-btn';
            btn.setAttribute('data-color', color);
            btn.onclick  = () => switchViewerColor(color);
            btn.style.cssText = `
                display:inline-flex; align-items:center; gap:6px;
                padding:5px 14px; border-radius:20px; border:2px solid #e5e7eb;
                background:white; cursor:pointer; font-size:0.82rem;
                transition:all 0.15s; white-space:nowrap;
            `;
            btn.innerHTML = `
                <span style="width:14px; height:14px; border-radius:50%;
                      background:${hexVal}; border:1px solid rgba(0,0,0,0.15);
                      display:inline-block; flex-shrink:0;"></span>
                ${esc(color)}
            `;
            btnContainer.appendChild(btn);
        });
        document.getElementById('viewerColorSwitcher').style.display = 'block';
        document.getElementById('viewerCurrentColor').textContent = '—';
    } else {
        document.getElementById('viewerColorSwitcher').style.display = 'none';
    }

    // Setup Three.js scene
    currentScene = new THREE.Scene();
    currentScene.background = new THREE.Color(0xffffff);

    currentCamera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
    currentCamera.position.set(3, 1.5, 4);

    currentRenderer = new THREE.WebGLRenderer({ antialias: true });
    currentRenderer.setSize(container.clientWidth, container.clientHeight);
    currentRenderer.shadowMap.enabled = true;
    container.appendChild(currentRenderer.domElement);

    currentControls = new THREE.OrbitControls(currentCamera, currentRenderer.domElement);
    currentControls.enableDamping   = true;
    currentControls.dampingFactor   = 0.05;
    currentControls.autoRotate      = true;
    currentControls.autoRotateSpeed = 2.0;
    currentControls.enableZoom      = true;

    // Add lights
    currentScene.add(new THREE.AmbientLight(0xffffff, 0.8));

    let dirLight = new THREE.DirectionalLight(0xffffff, 1.5);
    dirLight.position.set(2, 5, 3);
    currentScene.add(dirLight);

    let fillLight = new THREE.DirectionalLight(0xffddbb, 0.8);
    fillLight.position.set(-2, 2, 2);
    currentScene.add(fillLight);

    let backLight = new THREE.PointLight(0xffaa88, 0.4);
    backLight.position.set(0, 1, -2);
    currentScene.add(backLight);

    // Load GLB model
    let loader = new THREE.GLTFLoader();
    loader.load('/' + modelPath, function (gltf) {
        currentModel = gltf.scene;
        
        // Store original lens colors BEFORE any color changes
        currentModel.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                materials.forEach((mat, idx) => {
                    if (isLensMaterial(node, mat) && mat.color) {
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        originalLensColors.set(key, {
                            color: mat.color.getHex(),
                            transparent: mat.transparent || false,
                            opacity: mat.opacity !== undefined ? mat.opacity : 1
                        });
                    }
                });
            }
        });

        // Scale and center model
        let box   = new THREE.Box3().setFromObject(currentModel);
        let size  = box.getSize(new THREE.Vector3());
        let scale = 2.5 / Math.max(size.x, size.y, size.z);
        currentModel.scale.set(scale, scale, scale);

        let center = box.getCenter(new THREE.Vector3());
        currentModel.position.sub(center.multiplyScalar(scale));

        // Enable shadows
        currentModel.traverse(node => {
            if (node.isMesh) { node.castShadow = true; node.receiveShadow = true; }
        });

        currentScene.add(currentModel);

        // Add warning if no lens materials detected
        if (originalLensColors.size === 0 && colorsArray.length > 0) {
            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = `
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 12px;
                margin: 10px 16px;
                text-align: center;
            `;
            warningDiv.innerHTML = `
                <i class="bi bi-info-circle"></i>
                <strong>Note:</strong> No lens material detected in this GLB file. 
                The entire frame (including lens area) will change color.
                For better results, ensure lens materials are named with "lens", "glass", or "clear".
            `;
            
            const colorSwitcher = document.getElementById('viewerColorSwitcher');
            if (colorSwitcher) {
                colorSwitcher.parentNode.insertBefore(warningDiv, colorSwitcher.nextSibling);
            }
        }

        // Apply first color after model loads
        if (colorsArray.length > 0) {
            setTimeout(() => switchViewerColor(colorsArray[0]), 150);
        }

    }, undefined, function (err) {
        console.error('GLTFLoader error:', err);
        container.innerHTML = '<div class="alert alert-danger m-4">Failed to load 3D model file.</div>';
    });

    // Animation loop
    (function animate() {
        requestAnimationFrame(animate);
        if (currentControls) currentControls.update();
        if (currentRenderer) currentRenderer.render(currentScene, currentCamera);
    })();

    // Handle resize
    setTimeout(function () {
        if (currentCamera && currentRenderer && container) {
            currentCamera.aspect = container.clientWidth / container.clientHeight;
            currentCamera.updateProjectionMatrix();
            currentRenderer.setSize(container.clientWidth, container.clientHeight);
        }
    }, 300);

    window.addEventListener('resize', function () {
        if (currentCamera && currentRenderer && container) {
            currentCamera.aspect = container.clientWidth / container.clientHeight;
            currentCamera.updateProjectionMatrix();
            currentRenderer.setSize(container.clientWidth, container.clientHeight);
        }
    });
}

// =============================================
// VIEW 3D MODEL
// =============================================
function view3DModel(requestId) {
    $('#viewerModal').modal('show');
    $('#viewer-container').html('<div class="text-center p-5"><div class="spinner-border text-teal"></div><p class="mt-2 text-muted">Loading 3D model...</p></div>');
    $('#viewerColorSwitcher').hide();
    $('#viewerColorButtons').html('');

    $.ajax({
        url: '/api/3d_request.php?action=get_request_details&id=' + requestId,
        dataType: 'json',
        success: function (res) {
            if (res.success && res.data && res.data.completed_model_file) {
                let colorsArray = [];
                if (res.data.colors_requested) {
                    colorsArray = res.data.colors_requested.split(',').map(c => c.trim()).filter(Boolean);
                }
                init3DViewer(res.data.completed_model_file, res.data.product_name, colorsArray);
            } else {
                $('#viewer-container').html('<div class="alert alert-warning m-4">3D model file not available yet.</div>');
            }
        },
        error: function () {
            $('#viewer-container').html('<div class="alert alert-danger m-4">Error loading 3D model.</div>');
        }
    });
}

function viewRequestDetails(requestId) {
    $('#requestDetailsModal').modal('show');
    $('#requestDetailsBody').html('<div class="text-center p-4"><div class="spinner-border text-teal"></div></div>');
    $.ajax({
        url: '/api/3d_request.php?action=get_request_details&id=' + requestId,
        dataType: 'json',
        success: function (res) {
            if (res.success && res.data) displayRequestDetails(res.data);
            else $('#requestDetailsBody').html('<div class="alert alert-danger">Failed to load details.</div>');
        }
    });
}

function displayRequestDetails(req) {
    let statusClass, statusText;
    if (req.payment_status === 'pending_payment')   { statusClass = 'status-pending-payment'; statusText = 'Pending Payment'; }
    else if (req.payment_status === 'unpaid')        { statusClass = 'status-unpaid';          statusText = 'Awaiting Payment'; }
    else if (req.status === 'processing')            { statusClass = 'status-processing';      statusText = 'Processing'; }
    else if (req.status === 'completed')             { statusClass = 'status-completed';       statusText = 'Completed'; }
    else                                             { statusClass = 'status-pending';         statusText = ucFirst(req.status || ''); }

    let html = '<div class="row">'
        + '<div class="col-md-6">'
        + '<p><strong>Request #:</strong> ' + esc(req.request_number) + '</p>'
        + '<p><strong>Frame/Brand:</strong> ' + esc(req.product_name) + '</p>'
        + '<p><strong>Type:</strong> Frame</p>'
        + '<p><strong>Price:</strong> <span class="fw-bold text-teal">₱' + fmt(req.price) + '</span></p>'
        + '</div>'
        + '<div class="col-md-6">'
        + '<p><strong>Status:</strong> <span class="status-badge ' + statusClass + '">' + statusText + '</span></p>'
        + '<p><strong>Payment:</strong> <span class="badge ' + (req.payment_status === 'paid' ? 'bg-success' : 'bg-warning text-dark') + '">' + req.payment_status + '</span></p>'
        + '<p><strong>Requested:</strong> ' + fmtDate(req.created_at) + '</p>'
        + '</div></div>'
        + '<hr>'
        + '<p><strong>Notes:</strong> ' + (esc(req.notes) || 'None') + '</p>'
        + '<p><strong>Colors:</strong> ' + (esc(req.colors_requested) || 'Not specified') + '</p>';

    if (req.images && req.images.length) {
        html += '<hr><h6>Reference Images</h6><div class="row g-2">';
        req.images.forEach(img => {
            html += '<div class="col-3"><img src="/eyecore/' + img.image_path + '" class="img-fluid rounded border" style="height:80px;width:100%;object-fit:cover"></div>';
        });
        html += '</div>';
    }

    $('#requestDetailsBody').html(html);
}

function rotateLeft()  { if (currentModel) currentModel.rotation.y += 0.5; }
function rotateRight() { if (currentModel) currentModel.rotation.y -= 0.5; }
function resetView()   {
    if (currentCamera)   currentCamera.position.set(3, 1.5, 4);
    if (currentControls) { currentControls.target.set(0, 0, 0); currentControls.update(); }
    if (currentModel)    currentModel.rotation.y = 0;
}

$('#viewerModal').on('hidden.bs.modal', function () {
    if (currentRenderer) currentRenderer.dispose();
    currentScene = currentCamera = currentRenderer = currentControls = currentModel = null;
    originalLensColors.clear();
});

// =============================================
// UTILITY HELPERS
// =============================================
function esc(t)     { if (!t) return ''; let d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function fmt(n)     { return parseFloat(n || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'); }
function fmtDate(s) { if (!s) return 'N/A'; return new Date(s).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' }); }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
</script>
</body>
</html>