<?php
// C:\xampp\htdocs\eyecore\suppliers\supplier_dashboard.php
session_start();
require_once __DIR__ . '/../config/db.php';

// Check if supplier is logged in
if (!isset($_SESSION['supplier_id'])) {
    header("Location: supplier_login.php");
    exit();
}

global $pdo;

try {
    // Get supplier information
    $query = "SELECT s.*, u.last_login, u.email as user_email
              FROM suppliers s 
              JOIN users u ON s.created_by = u.id 
              WHERE s.id = :supplier_id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':supplier_id', $_SESSION['supplier_id']);
    $stmt->execute();
    $supplier = $stmt->fetch();

    // ============================================
    // ✅ VERIFICATION GATE
    // ============================================
    $isVerified = ($supplier['status'] === 'Active');

    $docStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected
        FROM supplier_documents 
        WHERE supplier_id = :id
    ");
    $docStmt->execute([':id' => $_SESSION['supplier_id']]);
    $docStats = $docStmt->fetch();

    $totalDocs    = (int)($docStats['total'] ?? 0);
    $verifiedDocs = (int)($docStats['verified'] ?? 0);
    $pendingDocs  = (int)($docStats['pending'] ?? 0);
    $rejectedDocs = (int)($docStats['rejected'] ?? 0);

    if ($supplier['status'] === 'Rejected') {
        $modalIcon     = 'danger';
        $modalTitle    = 'Registration Rejected';
        $modalMessage  = 'Your registration was rejected. Please review the feedback below and re-upload the required documents.';
        $modalBadge    = 'Rejected';
        $modalBadgeCls = 'bg-danger';
        $modalBtnText  = 'View & Re-upload Documents';
    } elseif ($supplier['status'] === 'Suspended') {
        $modalIcon     = 'danger';
        $modalTitle    = 'Account Suspended';
        $modalMessage  = 'Your account has been suspended. Please contact support for more information.';
        $modalBadge    = 'Suspended';
        $modalBadgeCls = 'bg-danger';
        $modalBtnText  = 'Contact Support';
    } elseif ($totalDocs === 0) {
        $modalIcon     = 'warning';
        $modalTitle    = 'Verification Required';
        $modalMessage  = 'You have not submitted any documents yet. Please upload the required business documents to activate your account.';
        $modalBadge    = 'No Documents';
        $modalBadgeCls = 'bg-warning text-dark';
        $modalBtnText  = 'Submit Requirements';
    } elseif ($rejectedDocs > 0) {
        $modalIcon     = 'warning';
        $modalTitle    = 'Documents Need Attention';
        $modalMessage  = "You have {$rejectedDocs} rejected document" . ($rejectedDocs > 1 ? 's' : '') . ". Please re-upload the rejected files to continue verification.";
        $modalBadge    = 'Action Needed';
        $modalBadgeCls = 'bg-warning text-dark';
        $modalBtnText  = 'Re-upload Documents';
    } else {
        $modalIcon     = 'info';
        $modalTitle    = 'Documents Under Review';
        $modalMessage  = "Your documents are currently being reviewed by our team. This usually takes 1–2 business days. All modules will unlock once your account is verified.";
        $modalBadge    = 'Under Review';
        $modalBadgeCls = 'bg-info';
        $modalBtnText  = 'View Documents';
    }

    // Get supplier contacts
    $contactQuery = "SELECT * FROM supplier_contacts WHERE supplier_id = :supplier_id";
    $contactStmt = $pdo->prepare($contactQuery);
    $contactStmt->bindParam(':supplier_id', $_SESSION['supplier_id']);
    $contactStmt->execute();
    $contacts = $contactStmt->fetchAll();

    // Get documents for Profile display
    $docsStmt = $pdo->prepare("SELECT * FROM supplier_documents WHERE supplier_id = :id ORDER BY document_type");
    $docsStmt->execute([':id' => $_SESSION['supplier_id']]);
    $supplierDocs = $docsStmt->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Supplier Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/eyecore-logo.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --sidebar-width: 280px;
        }
        body {
            background-color: #f3f4f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        .sidebar {
            background: white;
            height: 100vh;
            width: var(--sidebar-width);
            position: fixed;
            left: 0;
            top: 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar-header { padding: 1.5rem 1rem; border-bottom: 1px solid #e5e7eb; }
        .sidebar .nav-link {
            color: #4b5563; padding: 0.75rem 1.5rem; margin: 0.25rem 0;
            border-radius: 0; transition: all 0.3s;
        }
        .sidebar .nav-link:hover { background-color: #f3f4f6; color: var(--teal-600); }
        .sidebar .nav-link.active {
            background: linear-gradient(90deg, var(--teal-600) 0%, #14b8a6 100%);
            color: white; box-shadow: 0 4px 10px rgba(13, 148, 136, 0.3);
        }
        .sidebar .nav-link i { width: 24px; margin-right: 10px; }
        .sidebar .nav-section {
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
            color: #9ca3af; padding: 1rem 1.5rem 0.5rem; font-weight: 600;
        }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; min-height: 100vh; }
        .content-header {
            background: white; padding: 1rem 2rem; border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 2rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .page-title { margin: 0; font-size: 1.5rem; font-weight: 600; color: #1f2937; }
        .dashboard-card {
            background: white; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s; height: 100%;
        }
        .dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
        .btn-teal { background-color: var(--teal-600); color: white; border: none; }
        .btn-teal:hover { background-color: var(--teal-700); color: white; }
        .badge-pending { background-color: #fbbf24; color: #92400e; }
        .badge-approved { background-color: #34d399; color: #065f46; }
        .badge-rejected { background-color: #f87171; color: #991b1b; }
        .badge-shipped { background-color: #60a5fa; color: #1e3a8a; }
        .badge-delivered { background-color: #a78bfa; color: #5b21b6; }
        .module-content { display: none; animation: fadeIn 0.3s ease; }
        .module-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .info-row {
            display: flex; justify-content: space-between; padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child { border-bottom: none; }
        .quick-action-card {
            background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 12px;
            padding: 1.5rem; text-align: center; transition: all 0.3s; cursor: pointer;
        }
        .quick-action-card:hover { background: var(--teal-600); color: white; border-color: var(--teal-600); }
        .quick-action-card i { font-size: 2rem; margin-bottom: 0.5rem; }

        /* RIDER OPTION */
        .rider-option {
            border: 2px solid #e5e7eb; border-radius: 10px; padding: 12px 14px;
            margin-bottom: 8px; cursor: pointer; transition: all 0.15s;
            background: #fff; position: relative;
        }
        .rider-option:hover:not(.rider-disabled) {
            border-color: #0d9488; background: #f0fdfa;
            transform: translateY(-1px); box-shadow: 0 2px 6px rgba(13, 148, 136, 0.15);
        }
        .rider-option.selected { border-color: #0d9488; background: #e1f5ee; box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15); }
        .rider-option.rider-disabled { opacity: 0.6; cursor: not-allowed; background: #f9fafb; border-style: dashed; }
        .rider-option input[type="radio"] { accent-color: #0d9488; width: 18px; height: 18px; cursor: pointer; }
        .rider-option.rider-disabled input[type="radio"] { cursor: not-allowed; }
        .rider-option-header { display: flex; align-items: center; gap: 12px; }
        .rider-option-avatar {
            width: 42px; height: 42px; border-radius: 50%; object-fit: cover;
            background: #f3f4f6; display: flex; align-items: center; justify-content: center;
            color: #9ca3af; font-size: 1.1rem; border: 2px solid #e5e7eb; flex-shrink: 0;
        }
        .rider-option-name { font-weight: 700; font-size: 0.9rem; color: #1f2937; line-height: 1.2; }
        .rider-option-details {
            font-size: 0.72rem; color: #6b7280; margin-top: 2px;
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .rider-option-status {
            margin-top: 6px; padding-top: 6px; border-top: 1px dashed #e5e7eb; font-size: 0.75rem;
        }
        .rider-conflict-list { list-style: none; padding: 0; margin: 0; }
        .rider-conflict-list li {
            color: #dc2626; padding: 2px 0; display: flex; align-items: flex-start;
            gap: 6px; line-height: 1.3;
        }
        .rider-conflict-list li i { margin-top: 3px; flex-shrink: 0; }
        .rider-shift-badge {
            display: inline-flex; align-items: center; gap: 3px; background: #f0fdfa;
            color: #0f766e; padding: 2px 8px; border-radius: 999px;
            font-size: 0.65rem; font-weight: 700; border: 1px solid #5eead4;
        }

        /* SHIP CAMERA */
        .ship-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; }
        .ship-photo-item {
            position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden;
            border: 2px solid #0d9488; background: #f0fdf4;
            box-shadow: 0 2px 6px rgba(13, 148, 136, 0.15);
        }
        .ship-photo-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ship-photo-remove {
            position: absolute; top: 4px; right: 4px; width: 24px; height: 24px;
            border-radius: 50%; background: rgba(220, 53, 69, 0.95); color: white;
            border: 2px solid white; display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 11px; transition: all 0.15s; z-index: 2;
        }
        .ship-photo-remove:hover { background: #dc3545; transform: scale(1.1); }
        .ship-photo-number {
            position: absolute; top: 4px; left: 4px; width: 22px; height: 22px;
            border-radius: 50%; background: rgba(13, 148, 136, 0.95); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.65rem; font-weight: 800; border: 1.5px solid white; z-index: 2;
        }
        .ship-camera-controls { position: absolute; top: 1rem; right: 1rem; display: flex; gap: 8px; z-index: 10; }
        .ship-cam-btn {
            width: 44px; height: 44px; border-radius: 50%; background: rgba(0, 0, 0, 0.6);
            color: white; border: 2px solid rgba(255, 255, 255, 0.5);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; cursor: pointer; transition: all 0.15s;
        }
        .ship-cam-btn:hover { background: rgba(0, 0, 0, 0.85); border-color: white; }
        .ship-photo-counter {
            position: absolute; top: 1rem; left: 1rem; background: rgba(0, 0, 0, 0.65);
            color: white; padding: 6px 12px; border-radius: 999px;
            font-size: 0.75rem; font-weight: 800; display: flex; align-items: center; gap: 6px; z-index: 10;
        }
        .ship-capture-btn { transition: all 0.1s; outline: none; }
        .ship-capture-btn:active { transform: scale(0.92); background: #f0fdf4 !important; }
        .ship-capture-btn:hover { transform: scale(1.05); box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.15); }

        /* CHAT */
        .chat-avatar {
            width: 45px; height: 45px; border-radius: 50%; background-color: var(--teal-600);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem; flex-shrink: 0;
        }
        .chat-list-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background 0.2s;
        }
        .chat-list-item:hover { background-color: #f0fdfa; }
        .chat-list-item.active { background-color: #ccfbf1; border-left: 4px solid var(--teal-600); }
        .msg-bubble {
            max-width: 75%; padding: 0.75rem 1rem; border-radius: 16px;
            word-wrap: break-word; font-size: 0.9rem; display: inline-block;
        }
        .msg-outgoing {
            background: var(--teal-600); color: white;
            border-bottom-right-radius: 4px; margin-left: auto;
        }
        .msg-incoming {
            background: #fff; border: 1px solid #e2e8f0; color: #1e293b;
            border-bottom-left-radius: 4px; margin-right: auto;
        }
        .msg-time { font-size: 0.65rem; opacity: 0.7; margin-top: 4px; text-align: right; }

        /* RIDER WIZARD */
        .rider-wizard-steps {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 0.5rem 0 1rem; position: relative;
        }
        .rider-wizard-steps::before {
            content: ''; position: absolute; top: 22px; left: 8%; right: 8%;
            height: 2px; background: #e5e7eb; z-index: 0;
        }
        .rider-wizard-step {
            display: flex; flex-direction: column; align-items: center;
            gap: 6px; flex: 1; position: relative; z-index: 1;
        }
        .rider-step-circle {
            width: 36px; height: 36px; border-radius: 50%; background: white;
            border: 2px solid #e5e7eb; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 0.85rem;
            color: #9ca3af; transition: all 0.2s;
        }
        .rider-wizard-step.active .rider-step-circle {
            background: var(--teal-600); border-color: var(--teal-600); color: white;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.15);
        }
        .rider-wizard-step.done .rider-step-circle { background: #10b981; border-color: #10b981; color: white; }
        .rider-step-label {
            font-size: 0.65rem; font-weight: 600; color: #9ca3af; text-align: center;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .rider-wizard-step.active .rider-step-label,
        .rider-wizard-step.done .rider-step-label { color: var(--teal-600); }
        .rider-step-content { animation: riderStepFadeIn 0.25s ease; }
        @keyframes riderStepFadeIn { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }

        #addRiderModal .modal-content { max-height: 90vh; display: flex; flex-direction: column; }
        #addRiderModal form { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
        #addRiderModal .modal-body { overflow-y: auto; flex: 1; min-height: 0; }
        #addRiderModal .modal-footer { flex-shrink: 0; }
        #addRiderModal > .modal-dialog > .modal-content > .modal-body:first-of-type { flex-shrink: 0; overflow: visible; }

        .locked-nav { opacity: 0.55; cursor: not-allowed !important; position: relative; }
        .locked-nav:hover { background: #f3f4f6 !important; color: #9ca3af !important; }
        .locked-nav i.fa-lock.ms-auto { color: #9ca3af !important; font-size: 0.75rem; }
        body.supplier-locked .content-header::after {
            content: '🔒 Limited Access — Verify your account to unlock all features';
            display: block; background: #fef3c7; color: #92400e;
            padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.85rem;
            margin-top: 0.5rem; text-align: center;
        }

        @media (max-width: 576px) {
            .ship-photo-grid { grid-template-columns: repeat(auto-fill, minmax(75px, 1fr)); gap: 6px; }
            .ship-cam-btn { width: 38px; height: 38px; font-size: 14px; }
            .ship-photo-counter { font-size: 0.65rem; padding: 4px 9px; }
            .rider-step-label { font-size: 0.55rem; }
            .rider-step-circle { width: 30px; height: 30px; font-size: 0.75rem; }
            .rider-wizard-steps::before { top: 19px; }
        }
    </style>
</head>
<body class="<?= !$isVerified ? 'supplier-locked' : '' ?>">
    <!-- ========== SIDEBAR ========== -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="d-flex align-items-center">
            <!-- ✅ LOGO CONTAINER -->
            <div class="me-3 flex-shrink-0" style="width: 48px; height: 48px;">
                <?php 
                // Check kung may uploaded company_logo ang supplier
                $logoQuery = $pdo->prepare("SELECT file_path FROM supplier_documents WHERE supplier_id = ? AND document_type = 'company_logo' LIMIT 1");
                $logoQuery->execute([$_SESSION['supplier_id']]);
                $logoData = $logoQuery->fetch(PDO::FETCH_ASSOC);
                
                if (!empty($logoData['file_path'])): ?>
                    <!-- Ipakita ang Logo -->
                    <img src="../<?= htmlspecialchars($logoData['file_path']) ?>" 
                         alt="Logo" 
                         class="rounded-circle bg-white shadow-sm" 
                         style="width: 48px; height: 48px; object-fit: contain; padding: 2px;">
                <?php else: ?>
                    <!-- Fallback kung walang logo -->
                    <div class="bg-teal-600 text-white rounded-circle d-flex align-items-center justify-content-center w-100 h-100 shadow-sm">
                        <i class="fas fa-eye fa-lg"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <div>
                <h5 class="mb-0 fw-bold">Eyecore</h5>
                <small class="text-muted d-block lh-1" style="font-size: 0.75rem;">Supplier Portal</small>
            </div>
        </div>
    </div>
    
    <div class="sidebar-welcome px-3 py-2 bg-light">
        <small class="text-muted">Welcome back,</small>
        <div class="fw-bold"><?= htmlspecialchars($supplier['contact_person'] ?? $_SESSION['contact_person']) ?></div>
        <small class="text-muted"><?= htmlspecialchars($supplier['supplier_name'] ?? $_SESSION['supplier_name']) ?></small>
    </div>
    
    <?php
    function navLink($isVerified, $module, $icon, $label, $badge = '', $badgeId = '') {
        $locked   = !$isVerified;
        $cls      = $locked ? 'nav-link locked-nav' : 'nav-link';
        $onclick  = $locked ? 'showLockedAlert()' : "showModule('{$module}')";
        $href     = $locked ? 'javascript:void(0)' : '#';
        $iconHtml = $locked ? 'fa-lock' : $icon;
        $badgeHtml = $badge && !$locked ? $badge : '';
        echo "<a class='{$cls}' href='{$href}' onclick=\"{$onclick}\">
                <i class='fas {$iconHtml}'></i> {$label}
                {$badgeHtml}
                " . ($locked ? "<i class='fas fa-lock ms-auto small text-muted'></i>" : '') . "
              </a>";
    }
    ?>
    
    <nav class="nav flex-column mt-3">
        <div class="nav-section">Main</div>
        <?php navLink($isVerified, 'dashboard', 'fa-tachometer-alt', 'Dashboard'); ?>

        <div class="nav-section mt-3">Purchase Orders</div>
        <?php navLink($isVerified, 'po_pending', 'fa-clock', 'Pending Approval',
            '<span class="badge bg-danger rounded-pill float-end" id="pendingCount">0</span>'); ?>
        <?php navLink($isVerified, 'po_approved', 'fa-check-circle', 'Approved Orders'); ?>
        <?php navLink($isVerified, 'po_toship', 'fa-box', 'To Ship'); ?>
        <?php navLink($isVerified, 'po_shipped', 'fa-truck', 'Shipped'); ?>
        <?php navLink($isVerified, 'po_delivered', 'fa-check-double', 'Delivered'); ?>
        <?php navLink($isVerified, 'po_rejected', 'fa-times-circle', 'Rejected'); ?>

        <div class="nav-section mt-3">Returns & Refunds</div>
        <?php navLink($isVerified, 'returns_management', 'fa-undo-alt', 'Return Requests',
            '<span class="badge bg-danger rounded-pill float-end" id="totalReturnsCount">0</span>'); ?>

        <div class="nav-section mt-3">Products</div>
        <?php navLink($isVerified, 'products_list', 'fa-boxes', 'My Products'); ?>
        <?php navLink($isVerified, 'products_add', 'fa-plus-circle', 'Add Product'); ?>
        <?php navLink($isVerified, 'products_price', 'fa-tag', 'Price List'); ?>
        <?php navLink($isVerified, 'products_stock', 'fa-warehouse', 'Stock Levels'); ?>

        <div class="nav-section mt-3">Deliveries</div>
        <?php navLink($isVerified, 'delivery_history', 'fa-history', 'Delivery History'); ?>

        <div class="nav-section mt-3">Invoices</div>
        <?php navLink($isVerified, 'invoices', 'fa-file-invoice', 'Invoices',
            '<span class="badge bg-danger rounded-pill float-end" id="totalUnpaidInvoices">0</span>'); ?>

        <div class="nav-section mt-3">Riders</div>
        <?php navLink($isVerified, 'riders', 'fa-motorcycle', 'Manage Riders',
            '<span class="badge bg-primary rounded-pill float-end" id="totalRidersCount">0</span>'); ?>

        <?php navLink($isVerified, 'messages', 'fa-comments', 'Messages',
            '<span class="badge bg-danger rounded-pill float-end d-none" id="sidebarChatBadge">0</span>'); ?>

        <div class="nav-section mt-3">Account</div>
        <a class="nav-link" href="#" onclick="showModule('settings_profile')">
            <i class="fas fa-user-cog"></i> Profile
        </a>
        <?php navLink($isVerified, 'settings_contacts', 'fa-address-book', 'Contacts'); ?>

        <hr>
        <a class="nav-link text-danger" href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>

    <!-- ========== MAIN CONTENT ========== -->
    <div class="main-content">
        <div class="content-header">
            <h1 class="page-title" id="currentPageTitle">Dashboard</h1>
            <div class="text-muted">
                <i class="fas fa-calendar me-2"></i><?= date('F d, Y') ?>
            </div>
        </div>

        <!-- ========== DASHBOARD MODULE ========== -->
        <div id="module-dashboard" class="module-content active">
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Pending Approval</div>
                                <div class="h3 mb-0" id="statPending">0</div>
                            </div>
                            <div class="text-warning"><i class="fas fa-clock fa-2x"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Approved Orders</div>
                                <div class="h3 mb-0" id="statApproved">0</div>
                            </div>
                            <div class="text-success"><i class="fas fa-check-circle fa-2x"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">To Ship</div>
                                <div class="h3 mb-0" id="statToShip">0</div>
                            </div>
                            <div class="text-info"><i class="fas fa-box fa-2x"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted small">Month Total</div>
                                <div class="h5 mb-0" id="statMonthTotal">₱0.00</div>
                            </div>
                            <div class="text-primary"><i class="fas fa-peso-sign fa-2x"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12"><h5>Quick Actions</h5></div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('po_pending')">
                        <i class="fas fa-clock"></i>
                        <h6>Review Pending Orders</h6>
                        <small class="text-muted"><?= $pendingCount ?? 0 ?> waiting</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('products_add')">
                        <i class="fas fa-plus-circle"></i>
                        <h6>Add New Product</h6>
                        <small class="text-muted">Update catalog</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('delivery_history')">
                        <i class="fas fa-history"></i>
                        <h6>Delivery History</h6>
                        <small class="text-muted">Completed deliveries</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="quick-action-card" onclick="showModule('invoices')">
                        <i class="fas fa-file-invoice"></i>
                        <h6>View Invoices</h6>
                        <small class="text-muted">Payment status</small>
                    </div>
                </div>
            </div>

            <div class="dashboard-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Recent Pending Orders</h5>
                    <button class="btn btn-sm btn-teal" onclick="showModule('po_pending')">View All</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="recentPendingTable">
                            <tr><td colspan="5" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-3"><i class="fas fa-building me-2"></i>Company Information</h5>
                        <div class="info-row">
                            <span class="text-muted">Company Name:</span>
                            <span class="fw-bold"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Contact Person:</span>
                            <span><?= htmlspecialchars($supplier['contact_person'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Email:</span>
                            <span><?= htmlspecialchars($supplier['email'] ?? '') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Phone:</span>
                            <span><?= htmlspecialchars($supplier['phone'] ?: 'N/A') ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Performance</h5>
                        <div class="info-row">
                            <span class="text-muted">On-Time Delivery:</span>
                            <span class="fw-bold text-success">98%</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Order Accuracy:</span>
                            <span class="fw-bold text-success">100%</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Response Time:</span>
                            <span class="fw-bold">Within 24 hrs</span>
                        </div>
                        <div class="info-row">
                            <span class="text-muted">Rating:</span>
                            <span>
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?= $i <= ($supplier['rating'] ?? 3) ? 'text-warning' : 'text-muted' ?>"></i>
                                <?php endfor; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== PO MODULES ========== -->
        <div id="module-po_pending" class="module-content">
            <h4 class="mb-3">Pending Approval</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover" id="pendingTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date</th>
                                <th>Department</th>
                                <th>Purpose</th>
                                <th>Total</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTableBody">
                            <tr><td colspan="7" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_approved" class="module-content">
            <h4 class="mb-3">Approved Orders</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover" id="approvedTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date</th>
                                <th>Department</th>
                                <th>Total</th>
                                <th>Est. Delivery</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="approvedTableBody">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_toship" class="module-content">
            <h4 class="mb-3">To Ship</h4>
            <div class="dashboard-card">
                <p class="text-muted">
                    Orders ready for shipping. 
                    <span class="badge bg-info">Ship → Assign Rider → Rider delivers</span>
                </p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Rider</th>
                                <th style="width:220px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="toShipTableBody">
                            <tr><td colspan="5" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_shipped" class="module-content">
            <h4 class="mb-3">Shipped Orders</h4>
            <div class="dashboard-card">
                <p class="text-muted">Orders on the way</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Shipped Date</th>
                                <th>Total</th>
                                <th>Tracking</th>
                                <th>Rider</th>
                                <th>Status</th>
                                <th style="width:180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="shippedTableBody">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_delivered" class="module-content">
            <h4 class="mb-3">Delivered Orders</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date Ordered</th>
                                <th>Department</th>
                                <th>Total</th>
                                <th>Delivered Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="deliveredTableBody">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-po_rejected" class="module-content">
            <h4 class="mb-3">Rejected Orders</h4>
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date Ordered</th>
                                <th>Department</th>
                                <th>Total</th>
                                <th>Rejected Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="rejectedTableBody">
                            <tr><td colspan="8" class="text-center">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== DELIVERY HISTORY ========== -->
        <div id="module-delivery_history" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-history me-2"></i>Delivery History</h4>
            </div>

            <div class="dashboard-card mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" id="dhFromDate" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" id="dhToDate" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Search</label>
                        <input type="text" id="deliveryHistorySearch" class="form-control form-control-sm" placeholder="Search PO #, Department...">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button class="btn btn-sm btn-primary flex-fill" onclick="applyDeliveryFilter()"><i class="fas fa-search"></i> Filter</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="clearDeliveryFilter()" title="Clear filters"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Total Delivered</div>
                        <h3 class="mb-0 text-success" id="dhTotalDelivered">0</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Total Value</div>
                        <h3 class="mb-0 text-primary" id="dhTotalValue">₱0.00</h3>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">This Month</div>
                        <h3 class="mb-0 text-info" id="dhThisMonth">0</h3>
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>PR Number</th>
                                <th>Date Ordered</th>
                                <th>Department</th>
                                <th>Total Amount</th>
                                <th>Delivered Date</th>
                                <th>Tracking</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="deliveryHistoryTableBody">
                            <tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm text-teal"></div> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== RIDERS MODULE ========== -->
        <div id="module-riders" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 class="mb-0"><i class="fas fa-motorcycle me-2"></i>Manage Riders</h4>
                    <small class="text-muted">Add, edit, verify, and manage your delivery riders</small>
                </div>
                <button class="btn btn-teal" onclick="openAddRiderModal()">
                    <i class="fas fa-plus-circle me-1"></i> Add New Rider
                </button>
            </div>

            <div class="row mb-4" id="riderStatsRow">
                <div class="col-md-3 mb-2">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Total Riders</div>
                        <h3 class="mb-0 text-primary" id="riderStatTotal">0</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Active</div>
                        <h3 class="mb-0 text-success" id="riderStatActive">0</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Verified</div>
                        <h3 class="mb-0 text-info" id="riderStatVerified">0</h3>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="dashboard-card text-center">
                        <div class="text-muted small mb-1">Pending Verification</div>
                        <h3 class="mb-0 text-warning" id="riderStatPending">0</h3>
                    </div>
                </div>
            </div>

            <div class="dashboard-card mb-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <select id="riderStatusFilter" class="form-select" onchange="loadRiders()">
                            <option value="all">All Status</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Suspended">Suspended</option>
                            <option value="On Leave">On Leave</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" id="riderSearch" class="form-control" 
                                   placeholder="Search by name, phone, or email..." 
                                   onkeyup="if(event.keyCode==13) loadRiders()">
                            <button class="btn btn-primary" onclick="loadRiders()">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3 text-end">
                        <button class="btn btn-outline-secondary" onclick="clearRiderFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width:60px;">Photo</th>
                                <th>Rider</th>
                                <th>Contact</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Verification</th>
                                <th>Deliveries</th>
                                <th style="width:200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ridersTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-teal"></div>
                                    Loading riders...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<!-- ========== SETTINGS: COMPANY PROFILE ========== -->
        <div id="module-settings_profile" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-building me-2"></i>Company Profile</h4>
                <button class="btn btn-teal" id="editProfileBtn" onclick="toggleProfileEdit()">
                    <i class="fas fa-edit me-1"></i> Edit Profile
                </button>
            </div>

            <!-- View Mode -->
            <div id="profileViewMode">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card text-center">
                            <div class="mb-3">
                                <div class="bg-light rounded-circle mx-auto d-flex align-items-center justify-content-center" style="width:100px;height:100px;">
                                    <i class="fas fa-building fa-3x text-muted"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold" id="pv_name"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></h5>
                            <p class="text-muted mb-1" id="pv_code"><?= htmlspecialchars($supplier['supplier_code'] ?? '') ?></p>
                            <span class="badge bg-success" id="pv_status"><?= htmlspecialchars($supplier['status'] ?? 'Active') ?></span>
                            <hr>
                            <div class="text-start">
                                <div class="mb-2"><i class="fas fa-star text-warning me-2"></i>
                                    <?php for($i=1;$i<=5;$i++): ?>
                                        <i class="fas fa-star <?= $i<=($supplier['rating']??3)?'text-warning':'text-muted' ?>"></i>
                                    <?php endfor; ?> <small class="text-muted">(<?= $supplier['rating']??3 ?>/5)</small>
                                </div>
                                <div class="mb-2"><i class="fas fa-tag text-primary me-2"></i><?= htmlspecialchars($supplier['category'] ?? 'General') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8 mb-4">
                        <div class="dashboard-card">
                            <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row"><span class="text-muted">Company Name</span><span class="fw-bold" id="pv_supplier_name"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Contact Person</span><span id="pv_contact_person"><?= htmlspecialchars($supplier['contact_person'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Email</span><span id="pv_email"><?= htmlspecialchars($supplier['email'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Phone</span><span id="pv_phone"><?= htmlspecialchars($supplier['phone'] ?? 'N/A') ?></span></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row"><span class="text-muted">Mobile</span><span id="pv_mobile"><?= htmlspecialchars($supplier['mobile'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Website</span><span id="pv_website"><?= htmlspecialchars($supplier['website'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Tax ID</span><span id="pv_tax_id"><?= htmlspecialchars($supplier['tax_id'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Payment Terms</span><span id="pv_payment_terms"><?= htmlspecialchars($supplier['payment_terms'] ?? 'Net 30') ?></span></div>
                                </div>
                            </div>

                            <h6 class="text-primary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-map-marker-alt me-2"></i>Complete Address</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row"><span class="text-muted">Street Address</span><span id="pv_address"><?= htmlspecialchars($supplier['address'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Barangay</span><span id="pv_barangay"><?= htmlspecialchars($supplier['barangay'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">City / Municipality</span><span id="pv_city"><?= htmlspecialchars($supplier['city'] ?? 'N/A') ?></span></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row"><span class="text-muted">State / Province</span><span id="pv_state"><?= htmlspecialchars($supplier['state'] ?? 'Cavite') ?></span></div>
                                    <div class="info-row"><span class="text-muted">ZIP Code</span><span id="pv_postal"><?= htmlspecialchars($supplier['postal_code'] ?? 'N/A') ?></span></div>
                                    <div class="info-row"><span class="text-muted">Country</span><span id="pv_country"><?= htmlspecialchars($supplier['country'] ?? 'Philippines') ?></span></div>
                                </div>
                            </div>

                            <?php if (!empty($supplier['notes'])): ?>
                            <h6 class="text-primary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                            <p id="pv_notes" class="text-muted"><?= htmlspecialchars($supplier['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ✅ VERIFICATION DOCUMENTS SECTION (PLAIN DESIGN) -->
                <div class="dashboard-card mt-4" id="profileDocumentsSection">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="fas fa-file-shield text-primary me-2"></i>Verification Documents
                        </h5>
                        <?php if (!$isVerified): ?>
                            <span class="badge bg-secondary px-3 py-2">
                                <?= htmlspecialchars($modalBadge) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success px-3 py-2">
                                <i class="fas fa-check me-1"></i>All Verified
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($supplierDocs)): ?>
                        <div class="text-center py-5 bg-light rounded">
                            <i class="fas fa-folder-open fa-3x text-muted mb-3" style="opacity:0.3;"></i>
                            <p class="text-muted mb-0">No documents uploaded yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($supplierDocs as $doc):
                                $badgeCls = 'bg-secondary';
                                if ($doc['status'] === 'Verified') $badgeCls = 'bg-success';
                                elseif ($doc['status'] === 'Rejected') $badgeCls = 'bg-danger';
                                elseif ($doc['status'] === 'Pending') $badgeCls = 'bg-warning text-dark';
                                
                                $isPdf = strtolower($doc['mime_type'] ?? '') === 'application/pdf';
                                $fileIcon = $isPdf ? 'fa-file-pdf' : 'fa-file-image';
                            ?>
                                <div class="col-md-6">
                                    <!-- Plain Card Style -->
                                    <div class="h-100 p-3 rounded border d-flex align-items-center gap-3 bg-white">
                                        
                                        <!-- Icon -->
                                        <div class="rounded bg-light d-flex align-items-center justify-content-center flex-shrink-0"
                                             style="width: 50px; height: 50px;">
                                            <i class="fas <?= $fileIcon ?> fa-lg text-secondary"></i>
                                        </div>

                                        <!-- Details -->
                                        <div class="flex-grow-1" style="min-width: 0;">
                                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;">
                                                <?= htmlspecialchars($doc['document_name']) ?>
                                            </div>
                                            <small class="text-muted d-block text-truncate mb-2" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars($doc['original_name']) ?>
                                            </small>
                                            <span class="badge <?= $badgeCls ?>" style="font-size: 0.65rem; padding: 4px 8px;">
                                                <?= strtoupper(htmlspecialchars($doc['status'])) ?>
                                            </span>
                                        </div>

                                        <!-- Actions -->
                                        <div class="d-flex flex-column gap-2 flex-shrink-0">
                                            <!-- View Modal Button -->
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    onclick="viewDocument('<?= htmlspecialchars($doc['file_path']) ?>', '<?= $isPdf ? 'pdf' : 'image' ?>', '<?= htmlspecialchars($doc['document_name']) ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Re-upload (If Rejected) -->
                                            <?php if ($doc['status'] === 'Rejected'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning"
                                                        onclick="reuploadDoc(<?= $doc['id'] ?>)">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Rejection Reason (If Rejected) -->
                                    <?php if ($doc['status'] === 'Rejected' && !empty($doc['rejection_reason'])): ?>
                                        <div class="text-danger small mt-1 px-1">
                                            <i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($doc['rejection_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Edit Mode -->
            <div id="profileEditMode" style="display:none;">
                <div class="dashboard-card">
                    <form id="profileEditForm">
                        <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Company Name</label>
                                <input type="text" class="form-control" name="supplier_name" value="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($supplier['email'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mobile</label>
                                <input type="text" class="form-control" name="mobile" value="<?= htmlspecialchars($supplier['mobile'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Website</label>
                                <input type="text" class="form-control" name="website" value="<?= htmlspecialchars($supplier['website'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tax ID / TIN</label>
                                <input type="text" class="form-control" name="tax_id" value="<?= htmlspecialchars($supplier['tax_id'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Terms</label>
                                <select class="form-select" name="payment_terms">
                                    <?php foreach(['Net 15','Net 30','Net 45','Net 60','COD','Upon Delivery'] as $term): ?>
                                    <option value="<?= $term ?>" <?= ($supplier['payment_terms']??'Net 30')===$term?'selected':'' ?>><?= $term ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <h6 class="text-primary border-bottom pb-2 mb-3 mt-2"><i class="fas fa-map-marker-alt me-2"></i>Complete Address</h6>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Street Address</label>
                                <input type="text" class="form-control" name="address" value="<?= htmlspecialchars($supplier['address'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Barangay</label>
                                <input type="text" class="form-control" name="barangay" value="<?= htmlspecialchars($supplier['barangay'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City / Municipality</label>
                                <input type="text" class="form-control" name="city" value="<?= htmlspecialchars($supplier['city'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">State / Province</label>
                                <input type="text" class="form-control" name="state" value="<?= htmlspecialchars($supplier['state'] ?? 'Cavite') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">ZIP Code</label>
                                <input type="text" class="form-control" name="postal_code" value="<?= htmlspecialchars($supplier['postal_code'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Country</label>
                                <input type="text" class="form-control" name="country" value="<?= htmlspecialchars($supplier['country'] ?? 'Philippines') ?>">
                            </div>
                        </div>

                        <h6 class="text-primary border-bottom pb-2 mb-3 mt-2"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                        <div class="mb-3">
                            <textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($supplier['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" onclick="toggleProfileEdit()">
                                <i class="fas fa-times me-1"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-teal">
                                <i class="fas fa-save me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ✅ DOCUMENT VIEWER MODAL -->
        <div class="modal fade" id="documentViewerModal" tabindex="-1">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                    <div class="modal-header bg-white border-bottom">
                        <h5 class="modal-title text-dark fw-bold" id="docModalTitle"><i class="fas fa-file-alt me-2 text-primary"></i>Document Viewer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0 bg-light" style="min-height: 60vh; display: flex; align-items: center; justify-content: center;">
                        <!-- Image Container -->
                        <img id="docModalImage" src="" style="display: none; max-width: 100%; max-height: 80vh; object-fit: contain;">
                        <!-- PDF Container -->
                        <iframe id="docModalPdf" src="" style="display: none; width: 100%; height: 80vh; border: none;"></iframe>
                    </div>
                    <div class="modal-footer bg-light border-top-0">
                        <a href="#" id="docModalDownload" class="btn btn-outline-secondary" download>
                            <i class="fas fa-download me-1"></i> Download
                        </a>
                        <button type="button" class="btn btn-teal" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== SETTINGS: CONTACTS ========== -->
        <div id="module-settings_contacts" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-address-book me-2"></i>Contact Persons</h4>
                <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#addContactModal">
                    <i class="fas fa-plus me-1"></i> Add Contact
                </button>
            </div>

            <div id="contactsGrid">
                <?php if (!empty($contacts)): ?>
                    <div class="row" id="contactsContainer">
                    <?php foreach ($contacts as $contact): ?>
                        <div class="col-md-4 mb-3">
                            <div class="dashboard-card">
                                <div class="d-flex align-items-start justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-teal-100 d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:48px;height:48px;background:#e6f4f3;">
                                            <i class="fas fa-user" style="color:var(--teal-600);"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($contact['contact_name'] ?? '') ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($contact['position'] ?? 'N/A') ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($contact['is_primary'])): ?>
                                        <span class="badge bg-success">Primary</span>
                                    <?php endif; ?>
                                </div>
                                <hr class="my-2">
                                <div class="small">
                                    <?php if (!empty($contact['email'])): ?>
                                    <div class="mb-1"><i class="fas fa-envelope text-muted me-2"></i><?= htmlspecialchars($contact['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['phone'])): ?>
                                    <div class="mb-1"><i class="fas fa-phone text-muted me-2"></i><?= htmlspecialchars($contact['phone']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['mobile'])): ?>
                                    <div class="mb-1"><i class="fas fa-mobile-alt text-muted me-2"></i><?= htmlspecialchars($contact['mobile']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($contact['department'])): ?>
                                    <div class="mb-1"><i class="fas fa-building text-muted me-2"></i><?= htmlspecialchars($contact['department']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editContact(<?= $contact['id'] ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteContact(<?= $contact['id'] ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dashboard-card text-center py-5" id="contactsEmptyState">
                        <i class="fas fa-address-book fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No contact persons yet</h5>
                        <p class="text-muted">Add the people from your company who coordinate with Eyecore.</p>
                        <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#addContactModal">
                            <i class="fas fa-plus me-1"></i> Add First Contact
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== PRODUCTS MODULES ========== -->
        <div id="module-products_list" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-boxes me-2"></i>My Products</h4>
                <button class="btn btn-success" onclick="showModule('products_add')">
                    <i class="fas fa-plus-circle"></i> Add New Product
                </button>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-4">
                    <select id="product_category_filter" class="form-select" onchange="loadProducts()">
                        <option value="all">All Categories</option>
                        <option value="Frames">Frames</option>
                        <option value="Lenses">Lenses</option>
                        <option value="Contact Lenses">Contact Lenses</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <div class="input-group">
                        <input type="text" id="product_search" class="form-control" placeholder="Search by name, code, brand..." onkeyup="if(event.keyCode==13) loadProducts()">
                        <button class="btn btn-primary" onclick="loadProducts()"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-secondary" onclick="exportProducts()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Product Code</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Brand</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>3D Model</th>
                                <th>In Inventory</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="products_table_body">
                            <tr><td colspan="10" class="text-center">Loading products...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div id="products_pagination_info"></div>
                    <nav>
                        <ul class="pagination" id="products_pagination"></ul>
                    </nav>
                </div>
            </div>
        </div>

        <div id="module-products_add" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-plus-circle me-2"></i>Add New Product</h4>
                <button class="btn btn-outline-secondary" onclick="showModule('products_list')">
                    <i class="fas fa-arrow-left"></i> Back to List
                </button>
            </div>
            
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="dashboard-card">
                        <form id="add_product_form" enctype="multipart/form-data">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Product Code <span class="text-danger">*</span></label>
                                    <input type="text" name="product_code" id="product_code" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Product Name <span class="text-danger">*</span></label>
                                    <input type="text" name="product_name" id="product_name" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Category <span class="text-danger">*</span></label>
                                    <select name="category" id="product_category" class="form-select" required>
                                        <option value="">Select Category</option>
                                        <option value="Frames">Frames</option>
                                        <option value="Lenses">Lenses</option>
                                        <option value="Contact Lenses">Contact Lenses</option>
                                        <option value="Accessories">Accessories</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Brand</label>
                                    <input type="text" name="brand" id="product_brand" class="form-control">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Cost Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" name="cost_price" id="cost_price" class="form-control" step="0.01" min="0" required>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Selling Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" name="selling_price" id="selling_price" class="form-control" step="0.01" min="0" required>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Wholesale Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" name="wholesale_price" id="wholesale_price" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Initial Stock</label>
                                    <input type="number" name="initial_stock" id="initial_stock" class="form-control" value="0" min="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Low Stock Threshold</label>
                                    <input type="number" name="low_stock_threshold" id="low_stock_threshold" class="form-control" value="5" min="1">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Unit</label>
                                    <select name="unit" id="product_unit" class="form-select">
                                        <option value="pcs">Pieces (pcs)</option>
                                        <option value="box">Box</option>
                                        <option value="pair">Pair</option>
                                        <option value="set">Set</option>
                                        <option value="dozen">Dozen</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Min Order Qty</label>
                                    <input type="number" name="min_order_qty" id="min_order_qty" class="form-control" value="1" min="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Lead Time (days)</label>
                                    <input type="number" name="lead_time_days" id="lead_time_days" class="form-control" min="1">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" id="product_description" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Product Photo <span class="text-muted">(Optional)</span></label>
                                <input type="file" name="photo" id="product_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                <small class="text-muted">Max 5MB. JPG, PNG, GIF, WEBP only.</small>
                                <div id="photo_preview" class="mt-2" style="display: none;">
                                    <img id="preview_image" src="#" alt="Preview" style="max-height: 150px; border-radius: 5px;">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-cube me-1" style="color:#008080;"></i>
                                    3D Model File <span class="text-muted">(Optional)</span>
                                </label>
                                <input type="file" name="model_3d" id="product_model_3d" class="form-control" accept=".glb,.gltf">
                                <small class="text-muted">Max 50MB. <strong>.glb</strong> or <strong>.gltf</strong> only.</small>
                                <div id="model_3d_preview" class="mt-2" style="display: none;">
                                    <div class="border rounded p-3" style="background:#f0fdfa; border-color:#5eead4 !important;">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-cube" style="color:#008080; font-size:1.5rem;"></i>
                                            <div style="flex:1;">
                                                <div class="fw-bold" id="model_3d_filename" style="color:#0f766e; font-size:0.9rem;"></div>
                                                <small class="text-muted" id="model_3d_size"></small>
                                            </div>
                                            <span class="badge" style="background:#008080;">3D Ready</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="product_notes" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <hr>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-secondary" onclick="showModule('products_list')">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save Product
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div id="module-products_price" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-tag me-2"></i>Price List</h4>
                <button class="btn btn-outline-primary" onclick="exportPriceList()">
                    <i class="fas fa-file-excel"></i> Export
                </button>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6 offset-md-6">
                    <div class="input-group">
                        <input type="text" id="price_search" class="form-control" placeholder="Search products..." onkeyup="if(event.keyCode==13) loadPriceList()">
                        <button class="btn btn-primary" onclick="loadPriceList()"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="table-light">
                            <tr>
                                <th>Product Code</th>
                                <th>Product Name</th>
                                <th>Brand</th>
                                <th>Category</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Wholesale</th>
                                <th>Margin %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="price_list_table_body">
                            <tr><td colspan="9" class="text-center">Loading price list...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="module-products_stock" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-warehouse me-2"></i>Stock Levels</h4>
                <button class="btn btn-warning" onclick="showReorderSummary()">
                    <i class="fas fa-exclamation-triangle"></i> Reorder Suggestions
                </button>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="dashboard-card bg-success text-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <small>In Stock</small>
                                <h3 id="summary_in_stock">0</h3>
                            </div>
                            <i class="fas fa-check-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-warning text-dark">
                        <div class="d-flex justify-content-between">
                            <div>
                                <small>Low Stock</small>
                                <h3 id="summary_low_stock">0</h3>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-danger text-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <small>Out of Stock</small>
                                <h3 id="summary_out_of_stock">0</h3>
                            </div>
                            <i class="fas fa-times-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-secondary text-white">
                        <div class="d-flex justify-content-between">
                            <div>
                                <small>Not in Inventory</small>
                                <h3 id="summary_not_in_inventory">0</h3>
                            </div>
                            <i class="fas fa-question-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-12">
                    <div class="input-group">
                        <input type="text" id="stock_search" class="form-control" placeholder="Search products..." onkeyup="if(event.keyCode==13) loadStockLevels()">
                        <button class="btn btn-primary" onclick="loadStockLevels()"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Suggested Order</th>
                                <th>Est. Cost</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="stock_levels_table_body">
                            <tr><td colspan="8" class="text-center">Loading stock levels...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========== RETURNS MODULE ========== -->
        <div id="module-returns_management" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-undo-alt me-2"></i>Return Requests Management</h4>
                <div>
                    <span class="badge bg-warning me-2" id="returnsPendingBadge">Pending: 0</span>
                    <span class="badge bg-info me-2" id="returnsApprovedBadge">Approved: 0</span>
                    <span class="badge bg-success me-2" id="returnsCompletedBadge">Completed: 0</span>
                    <span class="badge bg-danger" id="returnsRejectedBadge">Rejected: 0</span>
                </div>
            </div>
            
            <ul class="nav nav-tabs mb-3" id="returnsTabs">
                <li class="nav-item">
                    <a class="nav-link active" href="#" onclick="switchReturnTab('pending'); return false;">
                        <i class="fas fa-clock me-1"></i>Pending
                        <span class="badge bg-danger ms-1" id="pendingCount">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="switchReturnTab('approved'); return false;">
                        <i class="fas fa-check-circle me-1"></i>Approved
                        <span class="badge bg-info ms-1" id="approvedCount">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="switchReturnTab('completed'); return false;">
                        <i class="fas fa-check-double me-1"></i>Completed
                        <span class="badge bg-success ms-1" id="completedCount">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="switchReturnTab('rejected'); return false;">
                        <i class="fas fa-times-circle me-1"></i>Rejected
                        <span class="badge bg-danger ms-1" id="rejectedCount">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="switchReturnTab('all'); return false;">
                        <i class="fas fa-list me-1"></i>All Returns
                    </a>
                </li>
            </ul>
            
            <div id="pending" class="return-tab-pane active">
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>PO #</th>
                                    <th>Clinic</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pendingReturnsTable">
                                <tr><td colspan="8" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="approved" class="return-tab-pane" style="display:none;">
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>PO #</th>
                                    <th>Clinic</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="approvedReturnsTable">
                                <tr><td colspan="9" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="completed" class="return-tab-pane" style="display:none;">
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>PO #</th>
                                    <th>Clinic</th>
                                    <th>Completed Date</th>
                                    <th>Items</th>
                                    <th>Refund Method</th>
                                    <th>Amount</th>
                                    <th>View</th>
                                </tr>
                            </thead>
                            <tbody id="completedReturnsTable">
                                <tr><td colspan="8" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="rejected" class="return-tab-pane" style="display:none;">
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>PO #</th>
                                    <th>Clinic</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                    <th>Rejection Reason</th>
                                </tr>
                            </thead>
                            <tbody id="rejectedReturnsTable">
                                <tr><td colspan="8" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="all" class="return-tab-pane" style="display:none;">
                <div class="dashboard-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>PO #</th>
                                    <th>Clinic</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Reason</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="allReturnsTable">
                                <tr><td colspan="9" class="text-center">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== INVOICES MODULE ========== -->
        <div id="module-invoices" class="module-content">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4><i class="fas fa-file-invoice me-2"></i>Invoices & Payments</h4>
                <div>
                    <span class="badge bg-danger me-2" id="unpaidBadge">Unpaid: 0</span>
                    <span class="badge bg-success me-2" id="paidBadge">Paid: 0</span>
                    <span class="badge bg-info" id="overdueBadge">Overdue: 0</span>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="dashboard-card bg-danger text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small>Total Unpaid</small>
                                <h3 class="mb-0" id="totalUnpaidAmount">₱0.00</h3>
                            </div>
                            <i class="fas fa-exclamation-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-success text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small>Total Paid</small>
                                <h3 class="mb-0" id="totalPaidAmount">₱0.00</h3>
                            </div>
                            <i class="fas fa-check-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-warning text-dark">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small>This Month</small>
                                <h3 class="mb-0" id="monthTotal">₱0.00</h3>
                            </div>
                            <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card bg-info text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small>On-Time Rate</small>
                                <h3 class="mb-0" id="onTimeRate">0%</h3>
                            </div>
                            <i class="fas fa-chart-line fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <ul class="nav nav-tabs mb-3" id="invoiceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="unpaid-tab" onclick="switchInvoiceTab('unpaid')" type="button" role="tab">
                        <i class="fas fa-exclamation-circle me-1"></i>Unpaid
                        <span class="badge bg-danger ms-1" id="unpaidCount">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="paid-tab" onclick="switchInvoiceTab('paid')" type="button" role="tab">
                        <i class="fas fa-check-circle me-1"></i>Paid
                        <span class="badge bg-success ms-1" id="paidCount">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="history-tab" onclick="switchInvoiceTab('history')" type="button" role="tab">
                        <i class="fas fa-history me-1"></i>Payment History
                    </button>
                </li>
            </ul>
            
            <div id="invoiceTabContent">
                <div style="display:block;" id="unpaid" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <select class="form-select" id="unpaidFilter" onchange="loadUnpaidInvoices()">
                                    <option value="all">All Unpaid</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Ready to Pay">Ready to Pay</option>
                                    <option value="Overdue">Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="unpaidSearch" placeholder="Search by PO # or Description..." onkeyup="if(event.keyCode==13) loadUnpaidInvoices()">
                                    <button class="btn btn-primary" onclick="loadUnpaidInvoices()"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <button class="btn btn-outline-secondary" onclick="exportInvoices('unpaid')">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>PO #</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="unpaidInvoicesTable">
                                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div id="unpaidPaginationInfo"></div>
                            <nav><ul class="pagination" id="unpaidPagination"></ul></nav>
                        </div>
                    </div>
                </div>
                
                <div style="display:none;" id="paid" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <select class="form-select" id="paidFilter" onchange="loadPaidInvoices()">
                                    <option value="all">All Paid</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="paidSearch" placeholder="Search by PO # or Description..." onkeyup="if(event.keyCode==13) loadPaidInvoices()">
                                    <button class="btn btn-primary" onclick="loadPaidInvoices()"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <button class="btn btn-outline-secondary" onclick="exportInvoices('paid')">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>PO #</th>
                                        <th>Description</th>
                                        <th>Paid Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="paidInvoicesTable">
                                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div id="paidPaginationInfo"></div>
                            <nav><ul class="pagination" id="paidPagination"></ul></nav>
                        </div>
                    </div>
                </div>
                
                <div style="display:none;" id="history" role="tabpanel">
                    <div class="dashboard-card">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="historyFrom" value="<?= date('Y-m-01') ?>">
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="historyTo" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="historySearch" placeholder="Search..." onkeyup="if(event.keyCode==13) loadPaymentHistory()">
                                    <button class="btn btn-primary" onclick="loadPaymentHistory()"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                            <div class="col-md-2 text-end">
                                <button class="btn btn-outline-secondary" onclick="exportPaymentHistory()">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice #</th>
                                        <th>PO #</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="paymentHistoryTable">
                                    <tr><td colspan="8" class="text-center">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="alert alert-info mb-0">
                                    <strong>Total for selected period:</strong> <span id="historyTotal">₱0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========== MESSAGES MODULE ========== -->
        <div id="module-messages" class="module-content">
            <div style="height: calc(100vh - 200px); min-height: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); background: white; display: flex; flex-direction: column;">
                
                <div style="background: #f9fafb; border-bottom: 1px solid #e2e8f0; padding: 0 1rem;">
                    <ul class="nav nav-tabs" style="border-bottom: none;">
                        <li class="nav-item">
                            <button class="nav-link active" id="tab-clinics" onclick="switchMessageTab('clinics')" style="font-weight: 700; color: var(--teal-600); border: none; border-bottom: 3px solid var(--teal-600); background: transparent;">
                                <i class="fas fa-store me-1"></i> Clinics
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="tab-riders" onclick="switchMessageTab('riders')" style="font-weight: 700; color: #6b7280; border: none; background: transparent;">
                                <i class="fas fa-motorcycle me-1"></i> Riders
                            </button>
                        </li>
                    </ul>
                </div>

                <div id="msg-tab-clinics" style="display: flex; flex: 1; overflow: hidden;">
                    <div class="col-md-4 d-flex flex-column border-end" style="height: 100%;">
                        <div class="p-3 border-bottom">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" id="chatSearchClinic" class="form-control border-start-0" placeholder="Maghanap ng clinic..." onkeyup="filterClinics(this.value)">
                            </div>
                        </div>
                        <div id="clinicListContainer" class="flex-grow-1" style="overflow-y: auto;"></div>
                    </div>

                    <div class="col-md-8 d-flex flex-column" style="height: 100%; background: #f8fafc;">
                        <input type="hidden" id="activeClinicId" value="">
                        <div id="chatEmptyState" class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-muted">
                            <i class="fas fa-comments" style="font-size: 4rem; opacity: 0.2;"></i>
                            <h5 class="mt-3">Pumili ng clinic para mag-chat</h5>
                        </div>
                        <div id="clinicChatHeader" class="p-3 bg-white border-bottom d-none">
                            <div class="d-flex align-items-center gap-2">
                                <div class="chat-avatar" id="chatClinicAvatar">?</div>
                                <div>
                                    <div class="fw-bold" id="chatClinicName">—</div>
                                    <small class="text-muted">Clinic</small>
                                </div>
                            </div>
                        </div>
                        <div id="clinicMessagesArea" class="flex-grow-1 p-3 d-none" style="overflow-y: auto;"></div>
                        <div id="clinicImgPreview" class="bg-white px-3 py-2 border-top d-none">
                            <div class="d-flex align-items-center gap-2">
                                <img id="clinicImgThumb" src="" style="width: 45px; height: 45px; border-radius: 6px; object-fit: cover; border: 1px solid #ccc;">
                                <span class="small text-muted flex-grow-1">Ready na ang image</span>
                                <button type="button" class="btn btn-sm btn-danger rounded-circle" onclick="removeClinicImage()" style="width:26px;height:26px;padding:0;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div id="clinicChatInputArea" class="p-2 bg-white border-top d-none">
                            <form id="clinicChatForm" class="d-flex gap-2 align-items-center" onsubmit="sendClinicMessage(event)">
                                <input type="file" id="clinicImgInput" accept="image/*" style="display:none;" onchange="handleClinicImage(event)">
                                <button type="button" class="btn btn-light border rounded-circle" onclick="document.getElementById('clinicImgInput').click()" style="width:38px;height:38px;padding:0;">
                                    <i class="fas fa-image text-muted"></i>
                                </button>
                                <textarea id="clinicMsgInput" class="form-control form-control-sm" rows="1" placeholder="I-type ang mensahe..." style="resize:none;border-radius:12px;height:38px;padding-top:8px;"></textarea>
                                <button type="submit" class="btn btn-sm text-white rounded-circle" style="background-color:var(--teal-600);width:38px;height:38px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="msg-tab-riders" style="display: none; flex: 1; overflow: hidden;">
                    <div class="col-md-4 d-flex flex-column border-end" style="height: 100%;">
                        <div class="p-3 border-bottom">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" id="riderSearchChat" class="form-control border-start-0" placeholder="Maghanap ng rider..." onkeyup="filterRiderChatList(this.value)">
                            </div>
                        </div>
                        <div id="riderChatList" class="flex-grow-1" style="overflow-y: auto;"></div>
                    </div>

                    <div class="col-md-8 d-flex flex-column" style="height: 100%; background: #f8fafc;">
                        <input type="hidden" id="activeRiderChatId" value="">
                        <div id="riderChatEmptyState" class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-muted">
                            <i class="fas fa-comments" style="font-size: 4rem; opacity: 0.2;"></i>
                            <h5 class="mt-3">Pumili ng rider para mag-chat</h5>
                        </div>
                        <div id="riderChatHeader" class="p-3 bg-white border-bottom d-none">
                            <div class="d-flex align-items-center gap-2">
                                <div class="chat-avatar" id="riderChatAvatar">?</div>
                                <div>
                                    <div class="fw-bold" id="riderChatName">—</div>
                                    <small class="text-muted"><i class="fas fa-phone"></i> <span id="riderChatPhone">—</span></small>
                                </div>
                            </div>
                        </div>
                        <div id="riderMessagesArea" class="flex-grow-1 p-3 d-none" style="overflow-y: auto;"></div>
                        <div id="riderChatImgPreview" class="bg-white px-3 py-2 border-top d-none">
                            <div class="d-flex align-items-center gap-2">
                                <img id="riderChatImgThumb" src="" style="width: 45px; height: 45px; border-radius: 6px; object-fit: cover; border: 1px solid #ccc;">
                                <span class="small text-muted flex-grow-1">Ready na ang image</span>
                                <button type="button" class="btn btn-sm btn-danger rounded-circle" onclick="removeRiderChatImg()" style="width:26px;height:26px;padding:0;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div id="riderChatInputArea" class="p-2 bg-white border-top d-none">
                            <form id="riderChatForm" class="d-flex gap-2 align-items-center" onsubmit="sendRiderMessage(event)">
                                <input type="file" id="riderChatImgInput" accept="image/*" style="display:none;" onchange="handleRiderChatImg(event)">
                                <button type="button" class="btn btn-light border rounded-circle" onclick="document.getElementById('riderChatImgInput').click()" style="width:38px;height:38px;padding:0;">
                                    <i class="fas fa-image text-muted"></i>
                                </button>
                                <textarea id="riderMsgInput" class="form-control form-control-sm" rows="1" placeholder="I-type ang mensahe..." style="resize:none;border-radius:12px;height:38px;padding-top:8px;"></textarea>
                                <button type="submit" class="btn btn-sm text-white rounded-circle" style="background-color:var(--teal-600);width:38px;height:38px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- ========== MODALS SECTION ========== -->
    <!-- ============================================ -->

    <!-- ========== RE-UPLOAD DOCUMENT MODAL ========== -->
    <div class="modal fade" id="reuploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-redo me-2"></i>Re-upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="reuploadForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="doc_id" id="reupload_doc_id">
                        <label class="form-label">Select new file (PDF, JPG, PNG — max 5MB)</label>
                        <input type="file" name="new_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-upload me-1"></i>Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== ADD CONTACT MODAL ========== -->
    <div class="modal fade" id="addContactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--teal-600);color:white;">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Contact Person</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="addContactForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="contact_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Position / Title</label>
                                <input type="text" class="form-control" name="position" placeholder="e.g. Sales Manager">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mobile</label>
                                <input type="text" class="form-control" name="mobile">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" name="department" placeholder="e.g. Sales, Logistics">
                            </div>
                            <div class="col-12 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" id="isPrimary" value="1">
                                    <label class="form-check-label" for="isPrimary">Set as Primary Contact</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-teal"><i class="fas fa-save me-1"></i> Save Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== EDIT CONTACT MODAL ========== -->
    <div class="modal fade" id="editContactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--teal-600);color:white;">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Contact Person</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editContactForm">
                    <div class="modal-body">
                        <input type="hidden" name="contact_id" id="editContactId">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="contact_name" id="editContactName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Position / Title</label>
                                <input type="text" class="form-control" name="position" id="editContactPosition">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="editContactEmail">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" id="editContactPhone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mobile</label>
                                <input type="text" class="form-control" name="mobile" id="editContactMobile">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" name="department" id="editContactDept">
                            </div>
                            <div class="col-12 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" id="editIsPrimary" value="1">
                                    <label class="form-check-label" for="editIsPrimary">Set as Primary Contact</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-teal"><i class="fas fa-save me-1"></i> Update Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== PRODUCT DETAILS MODAL ========== -->
    <div class="modal fade" id="productDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-box me-2"></i>Product Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="product_details_body">Loading...</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== EDIT PRODUCT MODAL ========== -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="edit_product_form" enctype="multipart/form-data">
                        <input type="hidden" name="product_id" id="edit_product_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Product Code</label>
                                <input type="text" name="product_code" id="edit_product_code" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Product Name</label>
                                <input type="text" name="product_name" id="edit_product_name" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" id="edit_product_category" class="form-select" required>
                                    <option value="Frames">Frames</option>
                                    <option value="Lenses">Lenses</option>
                                    <option value="Contact Lenses">Contact Lenses</option>
                                    <option value="Accessories">Accessories</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Brand</label>
                                <input type="text" name="brand" id="edit_product_brand" class="form-control">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Stock</label>
                                <input type="number" name="stock" id="edit_stock" class="form-control" min="0" value="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Low Stock Threshold</label>
                                <input type="number" name="low_stock_threshold" id="edit_low_stock_threshold" class="form-control" min="1" value="5">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cost Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" name="cost_price" id="edit_cost_price" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Selling Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" name="selling_price" id="edit_selling_price" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Wholesale</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" name="wholesale_price" id="edit_wholesale_price" class="form-control" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_product_description" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Product Photo <span class="text-muted">(Leave empty to keep current)</span></label>
                            <input type="file" name="photo" id="edit_product_photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="remove_photo" id="remove_photo" value="1">
                                <label class="form-check-label">Remove current photo</label>
                            </div>
                            <div id="current_photo_display" class="mt-2"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-cube me-1" style="color:#008080;"></i>
                                3D Model File <span class="text-muted">(Leave empty to keep current)</span>
                            </label>
                            <input type="file" name="model_3d" id="edit_product_model_3d" class="form-control" accept=".glb,.gltf">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="remove_3d_model" id="remove_3d_model" value="1">
                                <label class="form-check-label">Remove current 3D model</label>
                            </div>
                            <div id="edit_model_3d_preview" class="mt-2" style="display: none;">
                                <div class="border rounded p-3" style="background:#f0fdfa; border-color:#5eead4 !important;">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-cube" style="color:#008080; font-size:1.5rem;"></i>
                                        <div style="flex:1;">
                                            <div class="fw-bold" id="edit_model_3d_filename" style="color:#0f766e; font-size:0.9rem;"></div>
                                            <small class="text-muted" id="edit_model_3d_size"></small>
                                        </div>
                                        <span class="badge" style="background:#008080;">New 3D File</span>
                                    </div>
                                </div>
                            </div>
                            <div id="current_model_3d_display" class="mt-2"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateProduct()">Update Product</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== RETURN DETAILS MODAL ========== -->
    <div class="modal fade" id="returnDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-undo-alt me-2"></i>Return Request Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="returnDetailsContent">Loading...</div>
                <div class="modal-footer" id="returnDetailsFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== APPROVE RETURN MODAL ========== -->
    <div class="modal fade" id="approveReturnModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header" style="background:#0d9488;color:white;">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Return — Set Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="approve_return_id">
                    <div class="alert alert-light py-2 mb-3 d-flex justify-content-between small">
                        <span id="approve_return_summary"></span>
                    </div>

                    <p class="fw-bold mb-2 small">Return method <span class="text-danger">*</span></p>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="border rounded p-3 h-100" id="card_pickup"
                                 onclick="selectReturnMethod('pickup')"
                                 style="cursor:pointer;border-width:2px!important;border-color:#0d9488!important;background:#e1f5ee;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <input type="radio" name="return_method" id="rm_pickup" value="Pickup" checked style="accent-color:#0d9488;">
                                    <label for="rm_pickup" class="fw-bold mb-0 small" style="cursor:pointer;color:#085041;">
                                        <i class="fas fa-truck me-1"></i>Pickup
                                    </label>
                                </div>
                                <div class="text-muted" style="font-size:11px;">Supplier will fetch items from the clinic</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 h-100" id="card_dropoff" onclick="selectReturnMethod('dropoff')" style="cursor:pointer;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <input type="radio" name="return_method" id="rm_dropoff" value="Drop-off" style="accent-color:#0d9488;">
                                    <label for="rm_dropoff" class="fw-bold mb-0 small" style="cursor:pointer;">
                                        <i class="fas fa-store me-1"></i>Drop-off
                                    </label>
                                </div>
                                <div class="text-muted" style="font-size:11px;">Clinic will send items to supplier</div>
                            </div>
                        </div>
                    </div>

                    <div id="fields_pickup">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small mb-1">Pickup date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="pickup_date" name="pickup_date"
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                       value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Time window</label>
                                <select class="form-select form-select-sm" id="pickup_time" name="pickup_time">
                                    <option value="9AM-12NN">9AM – 12NN</option>
                                    <option value="1PM-5PM">1PM – 5PM</option>
                                    <option value="Morning (flexible)">Morning (flexible)</option>
                                    <option value="Afternoon (flexible)">Afternoon (flexible)</option>
                                    <option value="Any time">Any time</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Pickup address</label>
                            <input type="text" class="form-control form-control-sm" id="pickup_address" name="pickup_address" placeholder="Auto-filled from clinic address">
                        </div>
                        <div>
                            <label class="form-label small mb-1">Notes (optional)</label>
                            <textarea class="form-control form-control-sm" id="pickup_notes" name="pickup_notes" rows="2" placeholder="e.g. Call before arriving, ask for Juan"></textarea>
                        </div>
                    </div>

                    <div id="fields_dropoff" style="display:none;">
                        <div class="mb-2">
                            <label class="form-label small mb-1">Return address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="dropoff_address" name="dropoff_address"
                                   placeholder="e.g. 456 Supplier Ave, Manila" value="<?= htmlspecialchars($supplier['address'] ?? '') ?>">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small mb-1">Contact person <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="dropoff_contact" name="dropoff_contact"
                                       placeholder="Name" value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Contact number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="dropoff_phone" name="dropoff_phone"
                                       placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Available hours</label>
                            <input type="text" class="form-control form-control-sm" id="dropoff_hours" name="dropoff_hours" placeholder="e.g. Mon–Fri, 9AM–5PM">
                        </div>
                        <div>
                            <label class="form-label small mb-1">Instructions (optional)</label>
                            <textarea class="form-control form-control-sm" id="dropoff_notes" name="dropoff_notes" rows="2" placeholder="e.g. Leave at reception, mention RET number"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm text-white" style="background:#0d9488;" onclick="submitApproveReturn()">
                        <i class="fas fa-check me-1"></i>Confirm return details
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== REJECT RETURN MODAL ========== -->
    <div class="modal fade" id="rejectReturnModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Return Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reject_return_id">
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Ang clinic ay maabisuhan ng rejection at ng reason mo.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason for rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reject_reason" rows="4" placeholder="e.g. Items were already used, return window expired, not covered by warranty..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitRejectReturn()">
                        <i class="fas fa-times me-1"></i>Reject Return
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== INVOICE DETAILS MODAL ========== -->
    <div class="modal fade" id="invoiceDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Invoice Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="invoiceDetailsContent">
                    <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
                </div>
                <div class="modal-footer" id="invoiceDetailsFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== PO DETAILS MODAL (isang beses lang) ========== -->
    <div class="modal fade" id="poDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background-color: #008080;">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Purchase Order Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="poDetailsContent">
                    <div class="text-center py-3"><div class="spinner-border" style="color: #008080;"></div></div>
                </div>
                <div class="modal-footer" id="poDetailsFooter">
                    <button type="button" class="btn text-white" style="background-color: #008080;" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== VERIFICATION MODAL (isang beses lang) ========== -->
    <?php if (!$isVerified): ?>
    <div class="modal fade" id="verificationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:20px;border:none;overflow:hidden;">
                <div class="modal-body p-4 text-center" style="position:relative;">
                    <button type="button" class="btn-close position-absolute" style="top:1rem;right:1rem;" onclick="goToProfileDocuments()"></button>

                    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                         style="width:90px;height:90px;border-radius:50%;
                                background:<?= $modalIcon === 'danger' ? '#fee2e2' : ($modalIcon === 'info' ? '#dbeafe' : '#fef3c7') ?>;">
                        <i class="fas fa-<?= $modalIcon === 'danger' ? 'times-circle' : ($modalIcon === 'info' ? 'clock' : 'shield-alt') ?> fa-3x"
                           style="color:<?= $modalIcon === 'danger' ? '#dc2626' : ($modalIcon === 'info' ? '#2563eb' : '#f59e0b') ?>;"></i>
                    </div>

                    <h3 class="fw-bold mb-2"
                        style="color:<?= $modalIcon === 'danger' ? '#dc2626' : ($modalIcon === 'info' ? '#2563eb' : '#f59e0b') ?>;">
                        <?= htmlspecialchars($modalTitle) ?>
                    </h3>

                    <p class="text-muted mb-4 px-3" style="font-size:0.95rem;">
                        <?= htmlspecialchars($modalMessage) ?>
                    </p>

                    <div class="d-flex align-items-center justify-content-between p-3 mb-4"
                         style="background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                        <div class="d-flex align-items-center gap-2">
                            <div class="d-flex align-items-center justify-content-center"
                                 style="width:36px;height:36px;background:#e0f2fe;border-radius:8px;">
                                <i class="fas fa-file-alt" style="color:#0284c7;"></i>
                            </div>
                            <div class="text-start">
                                <div class="small fw-bold text-dark">Current Status</div>
                                <div class="small text-muted"><?= $verifiedDocs ?> / <?= $totalDocs ?> verified</div>
                            </div>
                        </div>
                        <span class="badge <?= $modalBadgeCls ?>" style="font-size:0.75rem;padding:0.4rem 0.75rem;">
                            <?= htmlspecialchars($modalBadge) ?>
                        </span>
                    </div>

                    <?php if ($totalDocs > 0): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Verification Progress</span>
                            <span><?= round(($verifiedDocs / $totalDocs) * 100) ?>%</span>
                        </div>
                        <div class="progress" style="height:8px;border-radius:4px;">
                            <div class="progress-bar"
                                 style="width:<?= round(($verifiedDocs / $totalDocs) * 100) ?>%;background:linear-gradient(90deg,#0d9488,#14b8a6);"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button class="btn w-100 py-2 mb-2" onclick="goToProfileDocuments()"
                            style="background:linear-gradient(135deg,#6366f1,#a855f7);color:white;border:none;border-radius:10px;font-weight:600;">
                        <i class="fas fa-file-upload me-2"></i>
                        <?= htmlspecialchars($modalBtnText) ?>
                    </button>

                    <p class="small text-muted mb-0 mt-3" style="font-size:0.75rem;">
                        <i class="fas fa-lock me-1"></i> All modules will unlock once verified
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ========== APPROVE PO MODAL ========== -->
    <div class="modal fade" id="approvePOModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Approve Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="approvePOForm">
                    <div class="modal-body">
                        <input type="hidden" name="po_id" id="approve_po_id">
                        <input type="hidden" name="pr_id" id="approve_pr_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Estimated Delivery Date</label>
                            <input type="date" class="form-control" name="estimated_delivery" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== REJECT PO MODAL ========== -->
    <div class="modal fade" id="rejectPOModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Reject Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="rejectPOForm">
                    <div class="modal-body">
                        <input type="hidden" name="po_id" id="reject_po_id">
                        <input type="hidden" name="pr_id" id="reject_pr_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Rejection</label>
                            <textarea class="form-control" name="reason" rows="4" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== SHIP CAMERA MODAL ========== -->
    <div class="modal fade" id="shipCameraModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content" style="border:none;border-radius:12px;overflow:hidden;">
                <div class="modal-header" style="background:#000;color:#fff;border:none;padding:0.75rem 1rem;">
                    <h6 class="modal-title" style="font-size:0.9rem;">
                        <i class="fas fa-camera text-danger me-2"></i>Live Camera — Package Proof
                    </h6>
                    <button type="button" class="btn-close btn-close-white" onclick="closeShipCamera()"></button>
                </div>
                
                <div class="modal-body" style="background:#000;padding:0;position:relative;">
                    <video id="shipCameraStream" autoplay playsinline style="width:100%;display:block;max-height:65vh;object-fit:contain;"></video>
                    <canvas id="shipCameraCanvas" style="display:none;"></canvas>
                    
                    <div id="shipCameraControls" class="ship-camera-controls">
                        <button type="button" class="ship-cam-btn" onclick="switchShipCamera()" title="Switch Camera">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    
                    <div class="ship-photo-counter">
                        <i class="fas fa-camera"></i>
                        <span id="shipPhotoCounter">0 / 5</span>
                    </div>
                </div>
                
                <div class="modal-footer" style="background:#000;border:none;justify-content:center;padding:1rem;gap:1rem;">
                    <button type="button" class="ship-capture-btn" onclick="captureShipPhoto()" 
                            style="width:70px;height:70px;border-radius:50%;background:#fff;border:4px solid #0d9488;display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <i class="fas fa-camera" style="font-size:1.8rem;color:#0d9488;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== ADD RIDER MODAL (5-Step Wizard) ========== -->
    <div class="modal fade" id="addRiderModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--teal-600);color:white;">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Rider</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body pb-0" style="border-bottom:1px solid #e5e7eb;">
                    <div class="rider-wizard-steps">
                        <div class="rider-wizard-step active" data-step="1">
                            <div class="rider-step-circle">1</div>
                            <div class="rider-step-label">Personal</div>
                        </div>
                        <div class="rider-wizard-step" data-step="2">
                            <div class="rider-step-circle">2</div>
                            <div class="rider-step-label">ID & Docs</div>
                        </div>
                        <div class="rider-wizard-step" data-step="3">
                            <div class="rider-step-circle">3</div>
                            <div class="rider-step-label">License</div>
                        </div>
                        <div class="rider-wizard-step" data-step="4">
                            <div class="rider-step-circle">4</div>
                            <div class="rider-step-label">Vehicle</div>
                        </div>
                        <div class="rider-wizard-step" data-step="5">
                            <div class="rider-step-circle">5</div>
                            <div class="rider-step-label">Emergency & PIN</div>
                        </div>
                    </div>
                </div>
                
                <form id="addRiderForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <!-- STEP 1 -->
                        <div class="rider-step-content active" data-step="1">
                            <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-user me-2"></i>Personal Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone" class="form-control" required placeholder="09XX XXX XXXX" pattern="[0-9\s\-\(\)\+]{10,15}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" placeholder="rider@email.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Birthdate</label>
                                    <input type="date" name="birthdate" class="form-control" max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="2" placeholder="House #, Street, Barangay, City, Province"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Profile Photo</label>
                                    <input type="file" name="photo_path" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                            </div>
                        </div>
                        
                        <!-- STEP 2 -->
                        <div class="rider-step-content" data-step="2" style="display:none;">
                            <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-id-card me-2"></i>Government ID & Clearances</h6>
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fas fa-info-circle me-1"></i>I-upload ang malinaw na litrato ng mga dokumento.
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">ID Type</label>
                                    <select name="id_type" class="form-select">
                                        <option value="">Select ID Type</option>
                                        <option value="Driver License">Driver's License</option>
                                        <option value="Passport">Passport</option>
                                        <option value="UMID">UMID</option>
                                        <option value="PhilHealth ID">PhilHealth ID</option>
                                        <option value="SSS ID">SSS ID</option>
                                        <option value="Voter's ID">Voter's ID</option>
                                        <option value="Postal ID">Postal ID</option>
                                        <option value="PRC ID">PRC ID</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">ID Number</label>
                                    <input type="text" name="id_number" class="form-control" placeholder="e.g. 1234-5678-9012">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">ID Photo (Front)</label>
                                    <input type="file" name="id_photo_path" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ID Photo (Back)</label>
                                    <input type="file" name="id_back_photo_path" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                            </div>
                            <h6 class="text-teal border-bottom pb-2 mb-3 mt-3"><i class="fas fa-file-shield me-2"></i>Clearances</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">NBI Clearance <span class="text-danger">*</span></label>
                                    <input type="file" name="nbi_clearance_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">NBI Expiry Date</label>
                                    <input type="date" name="nbi_expiry" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Police Clearance <span class="text-danger">*</span></label>
                                    <input type="file" name="police_clearance_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Police Expiry Date</label>
                                    <input type="date" name="police_expiry" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Barangay Clearance</label>
                                    <input type="file" name="barangay_clearance_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Barangay Expiry Date</label>
                                    <input type="date" name="barangay_expiry" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Medical Certificate</label>
                                    <input type="file" name="medical_cert_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Medical Expiry Date</label>
                                    <input type="date" name="medical_expiry" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <!-- STEP 3 -->
                        <div class="rider-step-content" data-step="3" style="display:none;">
                            <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-id-card-alt me-2"></i>Driver's License</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">License Number</label>
                                    <input type="text" name="license_number" class="form-control" placeholder="e.g. N01-23-456789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" name="license_expiry" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Restriction Codes</label>
                                    <input type="text" name="restriction_codes" class="form-control" placeholder="e.g. A, A1, B, B1">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">License Photo</label>
                                    <input type="file" name="license_photo_path" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                            </div>
                        </div>
                        
                        <!-- STEP 4 -->
                        <div class="rider-step-content" data-step="4" style="display:none;">
                            <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-motorcycle me-2"></i>Vehicle Information</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                                    <select name="vehicle_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <option value="Motorcycle">Motorcycle</option>
                                        <option value="Scooter">Scooter</option>
                                        <option value="Van">Van</option>
                                        <option value="Truck">Truck</option>
                                        <option value="Car">Car</option>
                                        <option value="Bicycle">Bicycle</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Plate Number <span class="text-danger">*</span></label>
                                    <input type="text" name="plate_number" class="form-control" required placeholder="e.g. ABC 1234">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Vehicle Color</label>
                                    <input type="text" name="vehicle_color" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vehicle Make</label>
                                    <input type="text" name="vehicle_make" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vehicle Model</label>
                                    <input type="text" name="vehicle_model" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vehicle Photo <span class="text-danger">*</span></label>
                                    <input type="file" name="vehicle_photo_path" class="form-control" required accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">OR/CR Photo</label>
                                    <input type="file" name="or_cr_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Vehicle Insurance</label>
                                    <input type="file" name="vehicle_insurance_path" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Insurance Expiry Date</label>
                                    <input type="date" name="vehicle_insurance_expiry" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <!-- STEP 5 -->
                        <div class="rider-step-content" data-step="5" style="display:none;">
                            <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-phone-alt me-2"></i>Emergency Contact</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Name</label>
                                    <input type="text" name="emergency_name" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Relationship</label>
                                    <select name="emergency_relationship" class="form-select">
                                        <option value="">Select</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Child">Child</option>
                                        <option value="Relative">Relative</option>
                                        <option value="Friend">Friend</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Phone</label>
                                    <input type="tel" name="emergency_phone" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Blood Type</label>
                                    <select name="blood_type" class="form-select">
                                        <option value="">Unknown</option>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Emergency Address</label>
                                    <textarea name="emergency_address" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Allergies / Medical Conditions</label>
                                    <textarea name="allergies" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            
                            <h6 class="text-teal border-bottom pb-2 mb-3 mt-3"><i class="fas fa-lock me-2"></i>Login PIN</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">PIN (6 digits) <span class="text-danger">*</span></label>
                                    <input type="password" name="pin" id="addRiderPin" class="form-control" required maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="••••••"
                                           style="letter-spacing:6px;font-size:1.2rem;text-align:center;font-family:monospace;">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Confirm PIN <span class="text-danger">*</span></label>
                                    <input type="password" id="addRiderPinConfirm" class="form-control" required maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="••••••"
                                           style="letter-spacing:6px;font-size:1.2rem;text-align:center;font-family:monospace;">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date Hired</label>
                                    <input type="date" name="date_hired" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer" style="justify-content:space-between;">
                        <div>
                            <button type="button" class="btn btn-outline-secondary" id="addRiderPrevBtn" onclick="addRiderPrevStep()" style="display:none;">
                                <i class="fas fa-arrow-left"></i> Previous
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-teal" id="addRiderNextBtn" onclick="addRiderNextStep()">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                            <button type="submit" class="btn btn-success" id="addRiderSubmitBtn" style="display:none;">
                                <i class="fas fa-save"></i> Save Rider
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== VIEW RIDER MODAL ========== -->
    <div class="modal fade" id="viewRiderModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--teal-600);color:white;">
                    <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>Rider Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewRiderBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-teal"></div>
                        <div class="text-muted mt-2">Loading...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="viewRiderEditBtn">
                        <i class="fas fa-edit me-1"></i>Edit Rider
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== EDIT RIDER MODAL ========== -->
    <div class="modal fade" id="editRiderModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Rider</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editRiderForm" enctype="multipart/form-data">
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
                        <input type="hidden" name="rider_id" id="edit_rider_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="edit_rider_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" id="edit_rider_phone" class="form-control" readonly disabled>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_rider_email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Birthdate</label>
                                <input type="date" name="birthdate" id="edit_rider_birthdate" class="form-control">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" id="edit_rider_address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_rider_status" class="form-select">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Suspended">Suspended</option>
                                    <option value="On Leave">On Leave</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date Hired</label>
                                <input type="date" name="date_hired" id="edit_rider_date_hired" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle Type</label>
                                <select name="vehicle_type" id="edit_rider_vehicle_type" class="form-select">
                                    <option value="">Select Type</option>
                                    <option value="Motorcycle">Motorcycle</option>
                                    <option value="Scooter">Scooter</option>
                                    <option value="Van">Van</option>
                                    <option value="Truck">Truck</option>
                                    <option value="Car">Car</option>
                                    <option value="Bicycle">Bicycle</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plate Number</label>
                                <input type="text" name="plate_number" id="edit_rider_plate" class="form-control">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="edit_rider_notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== VERIFY RIDER MODAL ========== -->
    <div class="modal fade" id="verifyRiderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Verify Rider</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="verify_rider_id">
                    <div id="verifyRiderSummary" class="mb-3"></div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Decision</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="verify_decision" id="verify_approve" value="approve" checked>
                            <label class="form-check-label text-success" for="verify_approve">
                                <i class="fas fa-check-circle me-1"></i><strong>Approve</strong>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="verify_decision" id="verify_reject" value="reject">
                            <label class="form-check-label text-danger" for="verify_reject">
                                <i class="fas fa-times-circle me-1"></i><strong>Reject</strong>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3" id="rejectReasonWrap" style="display:none;">
                        <label class="form-label">Reason for rejection <span class="text-danger">*</span></label>
                        <textarea id="verify_reject_reason" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitVerifyRider()">
                        <i class="fas fa-check me-1"></i>Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== SCHEDULE MODAL ========== -->
    <div class="modal fade" id="scheduleModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--teal-600);color:white;">
                    <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Shift Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="scheduleForm">
                    <div class="modal-body">
                        <input type="hidden" id="schedule_rider_id">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <strong id="schedule_rider_name"></strong>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="applyWeekdayPreset()">
                                    <i class="fas fa-briefcase"></i> Mon-Fri
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="applyAllDaysPreset()">
                                    <i class="fas fa-calendar-week"></i> All Days
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:100px;">Day</th>
                                        <th style="width:70px;">Active</th>
                                        <th>Time In</th>
                                        <th>Time Out</th>
                                        <th>Break Start</th>
                                        <th>Break End</th>
                                    </tr>
                                </thead>
                                <tbody id="scheduleTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-teal"><i class="fas fa-save me-1"></i>Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== RESET PIN MODAL ========== -->
    <div class="modal fade" id="resetPinModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Rider PIN</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="resetPinForm">
                    <div class="modal-body">
                        <input type="hidden" id="reset_rider_id">
                        <div class="mb-3">
                            <label class="form-label">Rider</label>
                            <input type="text" id="reset_rider_name" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New PIN (6 digits) <span class="text-danger">*</span></label>
                            <input type="password" id="reset_new_pin" class="form-control" required maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="••••••"
                                   style="letter-spacing:6px;font-size:1.2rem;text-align:center;font-family:monospace;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm PIN <span class="text-danger">*</span></label>
                            <input type="password" id="reset_confirm_pin" class="form-control" required maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="••••••"
                                   style="letter-spacing:6px;font-size:1.2rem;text-align:center;font-family:monospace;">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-secondary" style="background:#0f766e;">
                            <i class="fas fa-key me-1"></i>Reset PIN
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== SHIP & ASSIGN RIDER MODAL ========== -->
    <div class="modal fade" id="shipPOModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#0d9488,#0f766e);color:white;">
                    <h5 class="modal-title"><i class="fas fa-shipping-fast me-2"></i>Ship & Assign Rider</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <form id="shipAndAssignForm" enctype="multipart/form-data">
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
                        <input type="hidden" name="po_id" id="ship_po_id">
                        <input type="hidden" name="pr_id" id="ship_pr_id">
                        <input type="hidden" name="rider_id" id="ship_rider_id">
                        
                        <div class="alert alert-light border py-2 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;font-weight:700;">Shipping Order</div>
                                    <div style="font-weight:800;font-size:1rem;color:#0d9488;" id="ship_po_number">—</div>
                                    <div style="font-size:0.8rem;color:#4b5563;" id="ship_po_customer">—</div>
                                </div>
                                <div class="text-end">
                                    <div style="font-size:0.7rem;color:#6b7280;">Total</div>
                                    <div style="font-weight:800;font-size:1.1rem;color:#059669;" id="ship_po_total">₱0.00</div>
                                </div>
                            </div>
                        </div>
                        
                        <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-camera me-2"></i>1. Package Photo Proof</h6>
                        <input type="file" name="shipment_photos[]" id="shipment_photos" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                        <div id="shipPhotoPreview" class="ship-photo-grid mb-2"></div>
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-teal flex-fill" onclick="openShipCamera()">
                                <i class="fas fa-camera me-1"></i>
                                <span id="shipCameraBtnText">Open Camera & Capture</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearAllShipPhotos()" id="clearShipPhotosBtn" style="display:none;">
                                <i class="fas fa-trash"></i> Clear All
                            </button>
                        </div>
                        
                        <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-peso-sign me-2"></i>2. Shipping Fee <span class="text-danger">*</span></h6>
                        <div class="input-group mb-3">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control" name="shipping_fee" id="shipping_fee" step="0.01" min="1" required placeholder="e.g. 150.00">
                        </div>
                        
                        <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-calendar-alt me-2"></i>3. Delivery Schedule</h6>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Delivery Date <span class="text-danger">*</span></label>
                                <input type="date" id="ship_assign_date" name="delivery_date" class="form-control" 
                                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                       value="<?= date('Y-m-d', strtotime('+2 days')) ?>"
                                       onchange="checkRiderAvailability()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Time From <span class="text-danger">*</span></label>
                                <input type="time" id="ship_assign_time_start" name="time_start" class="form-control" value="09:00" onchange="checkRiderAvailability()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Time To <span class="text-danger">*</span></label>
                                <input type="time" id="ship_assign_time_end" name="time_end" class="form-control" value="11:00" onchange="checkRiderAvailability()">
                            </div>
                        </div>
                        
                        <div class="alert alert-info py-2 small mb-3" id="assignDayInfo" style="display:none;">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="assignDayText"></span>
                        </div>
                        
                        <h6 class="text-teal border-bottom pb-2 mb-3"><i class="fas fa-motorcycle me-2"></i>4. Select Rider <span class="text-danger">*</span></h6>
                        <div id="riderListContainer">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-teal"></div>
                                <span class="text-muted ms-2">Checking availability...</span>
                            </div>
                        </div>
                        
                        <h6 class="text-teal border-bottom pb-2 mb-3 mt-3"><i class="fas fa-comment-alt me-2"></i>5. Remarks <span class="text-muted small">(Optional)</span></h6>
                        <textarea class="form-control mb-3" name="remarks" rows="2" placeholder="e.g. Fragile items packed with bubble wrap"></textarea>
                    </div>
                    
                    <div class="modal-footer" style="justify-content:space-between;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="shipAssignSubmitBtn" disabled>
                            <i class="fas fa-shipping-fast me-1"></i>Ship & Assign Rider
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- ========== SUPPORT CHAT WIDGET ========== -->
    <!-- ============================================ -->
    <div id="supportWidget" style="position: fixed; bottom: 24px; right: 24px; z-index: 9999;">
        <button id="supportToggleBtn" class="btn rounded-circle shadow-lg"
                style="width: 60px; height: 60px; background:#0d9488; color:white; position: relative;">
            <i class="fas fa-headset" style="font-size: 1.5rem;"></i>
            <span id="supportUnreadBadge" class="badge bg-danger rounded-pill d-none"
                  style="position: absolute; top: -4px; right: -4px; font-size: 0.7rem;">0</span>
        </button>

        <div id="supportChatWindow" class="d-none shadow-lg"
             style="position: absolute; bottom: 72px; right: 0; width: 360px; height: 480px;
                    background: #fff; border-radius: 12px; overflow: hidden;
                    display: flex; flex-direction: column; border: 1px solid #e5e7eb;">
            <div style="background:#0d9488; color:white; padding: 12px 16px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div style="font-weight:700; font-size: 0.95rem;"><i class="fas fa-headset me-1"></i>Support Chat</div>
                    <small style="opacity:0.85; font-size: 0.7rem;">Usually replies within minutes</small>
                </div>
                <button onclick="toggleSupportWidget()" class="btn btn-sm text-white" style="padding:0;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="supportMessagesArea" style="flex: 1; overflow-y: auto; padding: 12px; background: #f8fafc;"></div>

            <div id="supportImgPreview" class="d-none px-2 py-1 border-top bg-white">
                <div class="d-flex align-items-center gap-2">
                    <img id="supportImgThumb" src="" style="width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #ccc;">
                    <span class="small text-muted flex-grow-1">Image ready</span>
                    <button type="button" class="btn btn-sm btn-danger rounded-circle" onclick="removeSupImage()" style="width:24px;height:24px;padding:0;">
                        <i class="fas fa-times" style="font-size:0.6rem;"></i>
                    </button>
                </div>
            </div>

            <form onsubmit="sendSupportMessage(event)" class="p-2 border-top bg-white d-flex gap-2 align-items-end">
                <input type="file" id="supImageInput" accept="image/*" style="display:none;" onchange="handleSupImage(event)">
                <button type="button" class="btn btn-light border rounded-circle"
                        onclick="document.getElementById('supImageInput').click()"
                        style="width:36px;height:36px;padding:0;">
                    <i class="fas fa-image text-muted"></i>
                </button>
                <textarea id="supportMessageInput" class="form-control form-control-sm" rows="1" placeholder="Type your message..."
                          style="resize:none; border-radius: 10px; height:36px; padding-top:8px;"></textarea>
                <button type="submit" class="btn btn-sm text-white rounded-circle"
                        style="width:36px;height:36px;background:#0d9488;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ============================================
// GLOBAL VARIABLES
// ============================================
let currentProductPage = 1;
let supplierId = <?= $_SESSION['supplier_id'] ?>;
const IS_VERIFIED = <?= $isVerified ? 'true' : 'false' ?>;
const SUPPORT_API = '../api/support_chat_supplier.php'; 

// ============================================
// MODULE NAVIGATION
// ============================================
function showModule(moduleId) {
    if (!IS_VERIFIED) {
        const locked = ['dashboard','po_pending','po_approved','po_toship','po_shipped',
                        'po_delivered','po_rejected','returns_management','products_list',
                        'products_add','products_price','products_stock','delivery_history',
                        'invoices','riders','messages','settings_contacts'];
        if (locked.includes(moduleId)) {
            showLockedAlert();
            return;
        }
    }
    
    $('.module-content').removeClass('active');
    $(`#module-${moduleId}`).addClass('active');
    $('.nav-link').removeClass('active');

    if (typeof event !== 'undefined' && event && event.target) {
        $(event.target).closest('.nav-link').addClass('active');
    } else {
        $(`.nav-link[onclick*="showModule('${moduleId}')"]`).addClass('active');
    }

    const titles = {
        'dashboard': 'Dashboard', 'po_pending': 'Pending Approval',
        'po_approved': 'Approved Orders', 'po_toship': 'To Ship',
        'po_shipped': 'Shipped', 'po_delivered': 'Delivered',
        'po_rejected': 'Rejected Orders', 'products_list': 'My Products',
        'products_add': 'Add Product', 'products_price': 'Price List',
        'products_stock': 'Stock Levels', 'delivery_history': 'Delivery History',
        'invoices': 'Invoices & Payments', 'settings_profile': 'Company Profile',
        'riders': 'Manage Riders', 'settings_contacts': 'Contact Persons',
        'messages': 'Messages'
    };
    $('#currentPageTitle').text(titles[moduleId] || 'Dashboard');

    switch(moduleId) {
        case 'dashboard': loadDashboardStats(); loadRecentPending(); break;
        case 'po_pending': loadPendingPOs(); break;
        case 'po_approved': loadApprovedPOs(); break;
        case 'po_toship': loadToShipOrders(); break;
        case 'po_shipped': loadShippedOrders(); break;
        case 'po_delivered': loadDeliveredOrders(); break;
        case 'po_rejected': loadRejectedOrders(); break;
        case 'delivery_history': loadDeliveryHistory(); break;
        case 'settings_contacts': loadContacts(); break;
        case 'riders': loadRiders(); loadRiderStats(); break;
        case 'products_list': loadProducts(1); break;
        case 'products_price': loadPriceList(); break;
        case 'products_stock': loadStockLevels(); break;
        case 'messages': loadMessages(); break;
    }
}

function showLockedAlert() {
    Swal.fire({
        icon: 'warning',
        title: '🔒 Module Locked',
        html: `<div style="text-align:left;">
            <p class="mb-2">This module is currently unavailable because your account is not yet verified.
            <div style="background:#fef3c7;padding:10px;border-radius:8px;font-size:0.85rem;">
                <i class="fas fa-info-circle"></i>
                All modules will be unlocked once the admin has reviewed and approved all your submitted documents
            </div>
        </div>`,
        confirmButtonText: '<i class="fas fa-file-upload me-1"></i> View My Documents',
        confirmButtonColor: '#0d9488',
        showCancelButton: true,
        cancelButtonText: 'Close'
    }).then(r => {
        if (r.isConfirmed) goToProfileDocuments();
    });
}

function goToProfileDocuments() {
    const modalEl = document.getElementById('verificationModal');
    if (modalEl) {
        const m = bootstrap.Modal.getInstance(modalEl);
        if (m) m.hide();
    }
    showModule('settings_profile');
    setTimeout(() => {
        const sec = document.getElementById('profileDocumentsSection');
        if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 400);
}

// ============================================
// DASHBOARD
// ============================================
function loadDashboardStats() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'dashboard_stats' },
        success: function(response) {
            if (response.success) {
                $('#statPending').text(response.data.pending_approval || 0);
                $('#statApproved').text(response.data.approved || 0);
                $('#statToShip').text(response.data.to_ship || 0);
                $('#statMonthTotal').text('₱' + parseFloat(response.data.month_total || 0).toFixed(2));
                $('#pendingCount').text(response.data.pending_approval || 0);
            }
        }
    });
}

function loadRecentPending() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Pending Supplier Approval' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.slice(0, 5).forEach(po => {
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${new Date(po.created_at).toLocaleDateString()}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-success" onclick="openApproveModal(${po.id}, ${po.pr_id})"><i class="fas fa-check"></i></button>
                                <button class="btn btn-sm btn-danger" onclick="openRejectModal(${po.id}, ${po.pr_id})"><i class="fas fa-times"></i></button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center">No pending orders</td></tr>';
                }
                $('#recentPendingTable').html(html);
            }
        }
    });
}

function loadPendingPOs() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Pending Supplier Approval' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${new Date(po.created_at).toLocaleDateString()}</td>
                            <td>${po.department}</td>
                            <td>${po.purpose || '-'}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-success" onclick="openApproveModal(${po.id}, ${po.pr_id})"><i class="fas fa-check"></i> Approve</button>
                                <button class="btn btn-sm btn-danger" onclick="openRejectModal(${po.id}, ${po.pr_id})"><i class="fas fa-times"></i> Reject</button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="7" class="text-center">No pending orders</td></tr>';
                }
                $('#pendingTableBody').html(html);
            }
        }
    });
}

function loadApprovedPOs() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'PO Approved' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const estDelivery = po.estimated_delivery
                            ? new Date(po.estimated_delivery).toLocaleDateString()
                            : 'Not set';
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${new Date(po.created_at).toLocaleDateString()}</td>
                            <td>${po.department}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td>${estDelivery}</td>
                            <td><span class="badge bg-success">PO Approved</span></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-warning" onclick="moveToShip(${po.id}, ${po.pr_id})" title="Move to Ship"><i class="fas fa-box"></i> To Ship</button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center">No approved orders</td></tr>';
                }
                $('#approvedTableBody').html(html);
            }
        }
    });
}

function moveToShip(poId, prId) {
    Swal.fire({
        title: 'Move to Ship?',
        text: 'Are you ready to prepare this order for shipping?',
        icon: 'question', showCancelButton: true,
        confirmButtonText: 'Yes, move to ship', cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/supplier_po.php', method: 'POST',
                data: { action: 'move_to_ship', po_id: poId, pr_id: prId },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success').then(() => {
                            loadApprovedPOs();
                            loadDashboardStats();
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                }
            });
        }
    });
}

function loadToShipOrders() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'To Ship' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const itemCount = po.item_count || 0;
                        const poNumber = (po.po_number || '').replace(/'/g, "\\'");
                        const department = (po.department || '').replace(/'/g, "\\'");
                        const hasRider = po.assigned_rider_id && po.assigned_rider_id > 0;

                        const itemCell = itemCount > 0
                            ? `<span class="badge bg-info">${itemCount} item${itemCount > 1 ? 's' : ''}</span>`
                            : `<span class="text-muted small">—</span>`;

                        let riderCell = '<span class="text-muted small">Not assigned</span>';
                        if (hasRider && po.rider_name) {
                            riderCell = `
                                <div style="font-size:0.85rem;font-weight:700;color:#0d9488;">
                                    <i class="fas fa-motorcycle"></i> ${escapeHtml(po.rider_name)}
                                </div>
                                ${po.scheduled_delivery_date ? `
                                    <div style="font-size:0.7rem;color:#6b7280;">
                                        <i class="fas fa-calendar"></i> ${formatDate(po.scheduled_delivery_date)}
                                    </div>
                                ` : ''}
                            `;
                        }

                        html += `<tr>
                            <td>
                                <strong>${po.po_number}</strong><br>
                                <small class="text-muted">PR: ${po.pr_number || '-'}</small>
                            </td>
                            <td>
                                <strong>${po.department || 'N/A'}</strong><br>
                                <small class="text-muted">${po.requested_by || 'Unknown'}</small>
                            </td>
                            <td class="text-center">${itemCell}</td>
                            <td>${riderCell}</td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn btn-sm btn-success" 
                                        onclick="openShipModal(${po.id}, ${po.pr_id}, '${poNumber}', '${department}', ${po.total_amount})" 
                                        title="Ship & Assign Rider">
                                    <i class="fas fa-truck"></i> Ship Now
                                </button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" class="text-center py-4"><i class="fas fa-box-open fa-2x mb-2"></i><br>No orders ready to ship</td></tr>';
                }
                $('#toShipTableBody').html(html);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading to ship orders:', error);
            $('#toShipTableBody').html('<tr><td colspan="5" class="text-center text-danger">Error loading data</td></tr>');
        }
    });
}

function loadShippedOrders() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Shipped' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const shippedDate = po.shipped_date
                            ? new Date(po.shipped_date).toLocaleDateString()
                            : 'Unknown';
                        let riderCell = '<span class="text-muted small">—</span>';
                        if (po.assigned_rider_id && po.rider_name) {
                            riderCell = `<div style="font-size:0.8rem;font-weight:700;color:#0d9488;"><i class="fas fa-motorcycle"></i> ${po.rider_name}</div>`;
                        }
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${shippedDate}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td><span class="badge bg-info">${po.tracking_number || 'No tracking'}</span></td>
                            <td>${riderCell}</td>
                            <td><span class="badge bg-primary">Shipped</span></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-secondary" onclick="viewTracking('${po.tracking_number || ''}')"><i class="fas fa-map-marker-alt"></i></button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center">No shipped orders</td></tr>';
                }
                $('#shippedTableBody').html(html);
            }
        }
    });
}

function loadDeliveredOrders() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Delivered' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const deliveredDate = po.actual_delivery
                            ? new Date(po.actual_delivery).toLocaleDateString()
                            : 'Unknown';
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${new Date(po.created_at).toLocaleDateString()}</td>
                            <td>${po.department || 'N/A'}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td>${deliveredDate}</td>
                            <td><span class="badge bg-success">Delivered</span></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})"><i class="fas fa-eye"></i></button>
                                ${po.tracking_number ? `<button class="btn btn-sm btn-secondary" onclick="viewTracking('${po.tracking_number}')"><i class="fas fa-map-marker-alt"></i></button>` : ''}
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-check-double fa-2x mb-2"></i><br>No delivered orders</td></tr>';
                }
                $('#deliveredTableBody').html(html);
            }
        }
    });
}

function loadRejectedOrders() {
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Supplier Rejected' },
        success: function(response) {
            if (response.success) {
                let html = '';
                if (response.data && response.data.length > 0) {
                    response.data.forEach(po => {
                        const rejectDate = po.response_date
                            ? new Date(po.response_date).toLocaleDateString()
                            : 'Unknown';
                        const reason = po.supplier_response || 'No reason provided';
                        html += `<tr>
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.pr_number}</td>
                            <td>${new Date(po.created_at).toLocaleDateString()}</td>
                            <td>${po.department || 'N/A'}</td>
                            <td>₱${parseFloat(po.total_amount).toFixed(2)}</td>
                            <td>${rejectDate}</td>
                            <td><span class="badge bg-danger">Rejected</span></td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-warning" onclick="viewRejectReason('${reason.replace(/'/g, "\\'")}')"><i class="fas fa-question-circle"></i></button>
                            </td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-times-circle fa-2x mb-2"></i><br>No rejected orders</td></tr>';
                }
                $('#rejectedTableBody').html(html);
            }
        }
    });
}

function viewRejectReason(reason) {
    Swal.fire({ title: 'Rejection Reason', text: reason, icon: 'info', confirmButtonText: 'OK' });
}

function viewTracking(trackingNumber) {
    Swal.fire({
        title: 'Tracking Information',
        html: `Tracking Number: <strong>${trackingNumber}</strong>`,
        icon: 'info', confirmButtonText: 'OK'
    });
}

// ============================================
// PO DETAILS MODAL
// ============================================
function viewPODetails(poId) {
    $('#poDetailsContent').html('<div class="text-center py-3"><div class="spinner-border" style="color: #008080;"></div></div>');
    $('#poDetailsModal').modal('show');

    $.ajax({
        url: '../api/supplier_po.php',
        method: 'GET',
        data: { action: 'get_po_details', id: poId },
        success: function(response) {
            if (response.success) {
                const po = response.data;
                let itemsHtml = '';
                let grandTotal = 0;
                po.items.forEach(item => {
                    const total = parseFloat(item.total_price || 0);
                    grandTotal += total;
                    itemsHtml += `<tr>
                        <td>${item.item_name}</td>
                        <td>${item.description || '-'}</td>
                        <td class="text-center">${item.quantity}</td>
                        <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td class="text-end">₱${total.toFixed(2)}</td>
                    </tr>`;
                });

                const orderDate = po.order_date
                    ? new Date(po.order_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                    : 'N/A';
                const expectedDate = po.expected_date
                    ? new Date(po.expected_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                    : 'Not set';

                const vatRate = parseFloat(po.vat_rate || 0.12);
                const subtotal = parseFloat(po.subtotal || (grandTotal / (1 + vatRate)));
                const vatAmount = parseFloat(po.vat_amount || (grandTotal - subtotal));

                const pm = po.payment_method || 'Bank Transfer';
                const pmBadgeClass = pm === 'COD' ? 'bg-success'
                    : (pm === 'Bank Transfer' ? 'bg-info'
                    : (pm === 'Check' ? 'bg-warning text-dark' : 'bg-primary'));

                const shippingAddress = po.shipping_address || po.full_clinic_address || 'No address provided';

                const html = `
                    <div class="card border" style="border-color: #008080 !important;">
                        <div class="card-body">
                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Order Summary</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0 mb-0">
                                            <tr><th width="45%">PO Number:</th><td class="fw-bold" style="color: #008080;">${po.po_number}</td></tr>
                                            <tr><th>PR Number:</th><td>${po.pr_number}</td></tr>
                                            <tr><th>Order Date:</th><td>${orderDate}</td></tr>
                                            <tr><th>Expected Delivery:</th><td>${expectedDate}</td></tr>
                                            <tr><th>Payment Method:</th><td>
                                                <span class="badge ${pmBadgeClass}">${pm}</span>
                                                ${po.payment_terms ? `<span class="badge bg-light text-dark ms-1">${po.payment_terms}</span>` : ''}
                                            </td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0 mb-0">
                                            <tr><th width="45%">Department:</th><td>${po.department || 'N/A'}</td></tr>
                                            <tr><th>Purpose:</th><td>${po.purpose || 'N/A'}</td></tr>
                                            <tr><th>Terms:</th><td>${po.terms || 'Standard terms apply'}</td></tr>
                                            <tr><th>Subtotal (Net of VAT):</th><td>₱${subtotal.toFixed(2)}</td></tr>
                                            <tr><th>VAT (${(vatRate * 100).toFixed(0)}%):</th><td>₱${vatAmount.toFixed(2)}</td></tr>
                                            <tr style="border-top: 2px solid #dee2e6;"><th>Total Amount:</th><td class="fw-bold text-success fs-6">₱${grandTotal.toFixed(2)}</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Customer Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0 mb-0">
                                            <tr><th width="35%">Clinic Name:</th><td class="fw-bold">${po.clinic_name || 'Eyecore Optical Clinic'}</td></tr>
                                            <tr><th>Shipping Address:</th><td>${shippingAddress.replace(/\n/g, '<br>')}</td></tr>
                                            <tr><th>Contact Person:</th><td>${po.contact_person || 'N/A'}</td></tr>
                                            <tr><th>Phone:</th><td>${po.contact_phone || po.clinic_phone || 'N/A'}</td></tr>
                                            <tr><th>Email:</th><td>${po.contact_email || po.clinic_email || 'N/A'}</td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm border-0 mb-0">
                                            <tr><th width="35%">Requested By:</th><td class="fw-bold">${po.requester_name || po.requested_by || 'Unknown'}</td></tr>
                                            <tr><th>Email:</th><td>${po.requester_email || 'N/A'}</td></tr>
                                            <tr><th>Phone:</th><td>${po.requester_phone || po.contact_phone || 'N/A'}</td></tr>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Order Items</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead style="background-color: #00808020; color: #008080;">
                                            <tr>
                                                <th>Item</th>
                                                <th>Description</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>${itemsHtml}</tbody>
                                        <tfoot style="background-color: #00808020;">
                                            <tr>
                                                <td colspan="4" class="text-end">Subtotal (Net of VAT):</td>
                                                <td class="text-end">₱${subtotal.toFixed(2)}</td>
                                            </tr>
                                            <tr>
                                                <td colspan="4" class="text-end">VAT (${(vatRate * 100).toFixed(0)}%):</td>
                                                <td class="text-end">₱${vatAmount.toFixed(2)}</td>
                                            </tr>
                                            <tr style="border-top: 2px solid #00808040;">
                                                <td colspan="4" class="text-end fw-bold">GRAND TOTAL:</td>
                                                <td class="text-end fw-bold" style="color: #008080;">₱${grandTotal.toFixed(2)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <div class="mb-4">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Delivery Information</h6>
                                <table class="table table-sm border-0 mb-0">
                                    <tr><th width="25%">Ship To:</th><td>${shippingAddress.replace(/\n/g, '<br>')}</td></tr>
                                    <tr><th>Expected Date:</th><td>${expectedDate}</td></tr>
                                    <tr><th>Payment:</th><td>
                                        <span class="badge ${pmBadgeClass}">${pm}</span>
                                        ${po.payment_terms ? `<span class="badge bg-light text-dark ms-1">${po.payment_terms}</span>` : ''}
                                    </td></tr>
                                    ${po.collected_amount > 0 ? `
                                    <tr><th>COD Collected:</th><td>
                                        <span class="badge bg-success">₱${parseFloat(po.collected_amount).toFixed(2)}</span>
                                        ${po.payment_collected_at ? `<small class="text-muted ms-2">on ${new Date(po.payment_collected_at).toLocaleDateString()}</small>` : ''}
                                    </td></tr>` : ''}
                                </table>
                            </div>

                            ${po.pr_notes ? `
                            <div class="mt-3">
                                <h6 class="fw-bold pb-2 border-bottom" style="color: #008080;">Additional Notes</h6>
                                <p class="mb-0">${po.pr_notes}</p>
                            </div>` : ''}

                        </div>
                    </div>`;

                $('#poDetailsContent').html(html);
            } else {
                $('#poDetailsContent').html('<div class="alert alert-danger">Failed to load PO details</div>');
            }
        },
        error: function() {
            $('#poDetailsContent').html('<div class="alert alert-danger">Network error</div>');
        }
    });
}

function openApproveModal(poId, prId) {
    $('#approve_po_id').val(poId);
    $('#approve_pr_id').val(prId);
    $('#approvePOModal').modal('show');
}

$('#approvePOForm').submit(function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'approve_po');
    $.ajax({
        url: '../api/supplier_po.php', method: 'POST',
        data: formData, processData: false, contentType: false,
        success: function(response) {
            if (response.success) {
                Swal.fire('Success', response.message, 'success').then(() => {
                    $('#approvePOModal').modal('hide');
                    loadDashboardStats(); loadPendingPOs(); loadRecentPending();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
});

function openRejectModal(poId, prId) {
    $('#reject_po_id').val(poId);
    $('#reject_pr_id').val(prId);
    $('#rejectPOModal').modal('show');
}

$('#rejectPOForm').submit(function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'reject_po');
    $.ajax({
        url: '../api/supplier_po.php', method: 'POST',
        data: formData, processData: false, contentType: false,
        success: function(response) {
            if (response.success) {
                Swal.fire('Rejected', response.message, 'info').then(() => {
                    $('#rejectPOModal').modal('hide');
                    loadDashboardStats(); loadPendingPOs(); loadRecentPending();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
});

// ============================================
// HELPERS
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
}

// ============================================
// SHIP + ASSIGN RIDER
// ============================================
let shipPhotoFiles = [];
let shipCameraStream = null;
let shipCameraFacing = 'environment';
let shipCameraModal = null;
const MAX_SHIP_PHOTOS = 5;
window.selectedRiderId = null;

function openShipModal(poId, prId, poNumber, customer, total) {
    const form = document.getElementById('shipAndAssignForm');
    if (form) form.reset();

    shipPhotoFiles.forEach(f => { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
    shipPhotoFiles = [];
    renderShipPhotos();
    updateShipCameraBtnText();
    updateShipPhotoCounter();

    window.selectedRiderId = null;
    document.getElementById('ship_rider_id').value = '';
    const submitBtn = document.getElementById('shipAssignSubmitBtn');
    if (submitBtn) submitBtn.disabled = true;

    document.getElementById('ship_po_id').value = poId || '';
    document.getElementById('ship_pr_id').value = prId || '';

    document.getElementById('ship_po_number').textContent = poNumber || '—';
    document.getElementById('ship_po_customer').textContent = customer || '—';
    document.getElementById('ship_po_total').textContent = '₱' + parseFloat(total || 0).toFixed(2);

    const tomorrow = new Date(Date.now() + 2 * 864e5).toISOString().split('T')[0];
    document.getElementById('ship_assign_date').value = tomorrow;
    document.getElementById('ship_assign_time_start').value = '09:00';
    document.getElementById('ship_assign_time_end').value = '11:00';

    const dayInfo = document.getElementById('assignDayInfo');
    if (dayInfo) dayInfo.style.display = 'none';

    document.getElementById('riderListContainer').innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-teal"></div>
            <span class="text-muted ms-2">Checking availability...</span>
        </div>`;

    const modal = new bootstrap.Modal(document.getElementById('shipPOModal'));
    modal.show();

    setTimeout(() => { checkRiderAvailability(); }, 300);
}

async function openShipCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        Swal.fire({
            icon: 'error',
            title: 'Camera Not Supported',
            html: 'Siguraduhing naka-HTTPS ang site at Chrome/Safari/Edge ang gamit.'
        });
        return;
    }

    const currentRiderId = window.selectedRiderId;
    window._savedRiderId = currentRiderId;

    const shipModalEl = document.getElementById('shipPOModal');
    const shipModal = bootstrap.Modal.getInstance(shipModalEl);
    const wasShipModalOpen = shipModal && shipModalEl.classList.contains('show');

    if (wasShipModalOpen) {
        window._shipModalWasOpen = true;
        shipModal.hide();
        await new Promise(r => setTimeout(r, 400));
    } else {
        window._shipModalWasOpen = false;
    }

    try {
        shipCameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: shipCameraFacing },
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        });

        const video = document.getElementById('shipCameraStream');
        video.srcObject = shipCameraStream;

        const camEl = document.getElementById('shipCameraModal');
        camEl.style.zIndex = '1090';

        if (!shipCameraModal) {
            shipCameraModal = new bootstrap.Modal(camEl, {
                backdrop: 'static',
                keyboard: false
            });
        }
        shipCameraModal.show();
        updateShipPhotoCounter();

    } catch (err) {
        if (window._shipModalWasOpen) {
            setTimeout(() => {
                const reopenModal = new bootstrap.Modal(shipModalEl);
                reopenModal.show();
                window._shipModalWasOpen = false;
            }, 300);
        }

        let msg = 'Hindi ma-access ang camera.';
        if (err.name === 'NotAllowedError') msg = 'Pinagbawalan ang camera access.';
        else if (err.name === 'NotFoundError') msg = 'Walang nakitang camera.';
        else if (err.name === 'NotReadableError') msg = 'Ginagamit ng ibang app ang camera.';
        Swal.fire('Camera Error', msg, 'error');
    }
}

function closeShipCamera() {
    if (shipCameraStream) {
        shipCameraStream.getTracks().forEach(track => track.stop());
        shipCameraStream = null;
    }

    if (shipCameraModal) {
        shipCameraModal.hide();
    }

    const camEl = document.getElementById('shipCameraModal');
    if (camEl) camEl.style.zIndex = '';

    setTimeout(() => {
        renderShipPhotos();
        updateShipCameraBtnText();
        updateShipPhotoCounter();
    }, 200);

    if (window._shipModalWasOpen) {
        setTimeout(() => {
            const shipModalEl = document.getElementById('shipPOModal');
            const reopenModal = new bootstrap.Modal(shipModalEl);
            reopenModal.show();
            window._shipModalWasOpen = false;

            setTimeout(() => {
                renderShipPhotos();
                updateShipCameraBtnText();
                updateShipPhotoCounter();
                
                const savedRiderId = window._savedRiderId;
                
                if (savedRiderId) {
                    const riderOption = document.querySelector(`#riderListContainer .rider-option[data-rider-id="${savedRiderId}"]`);
                    if (riderOption && riderOption.dataset.available === '1') {
                        document.querySelectorAll('#riderListContainer .rider-option').forEach(el => el.classList.remove('selected'));
                        riderOption.classList.add('selected');
                        
                        const radio = riderOption.querySelector('input[type="radio"]');
                        if (radio) radio.checked = true;
                        
                        window.selectedRiderId = savedRiderId;
                        const hidden = document.getElementById('ship_rider_id');
                        if (hidden) hidden.value = savedRiderId;
                        
                        const btn = document.getElementById('shipAssignSubmitBtn');
                        if (btn) btn.disabled = false;
                    }
                }
            }, 300);
        }, 400);
    }
}

function captureShipPhoto() {
    const video = document.getElementById('shipCameraStream');
    const canvas = document.getElementById('shipCameraCanvas');

    if (!video.videoWidth || !video.videoHeight) {
        Swal.fire('Error', 'Camera not ready yet.', 'warning');
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(function(blob) {
        if (!blob) return;

        const filename = `ship_${Date.now()}_${shipPhotoFiles.length}.jpg`;
        const file = new File([blob], filename, { type: 'image/jpeg' });

        shipPhotoFiles.push(file);

        video.style.opacity = '0.3';
        setTimeout(() => { video.style.opacity = '1'; }, 100);

        updateShipPhotoCounter();
        renderShipPhotos();
        updateShipCameraBtnText();

    }, 'image/jpeg', 0.85);
}

async function switchShipCamera() {
    if (!shipCameraStream) return;
    shipCameraStream.getTracks().forEach(track => track.stop());
    shipCameraFacing = (shipCameraFacing === 'environment') ? 'user' : 'environment';
    try {
        shipCameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: shipCameraFacing }, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        });
        document.getElementById('shipCameraStream').srcObject = shipCameraStream;
    } catch (err) {
        Swal.fire('Error', 'Hindi ma-switch ang camera.', 'error');
    }
}

function updateShipPhotoCounter() {
    const counter = document.getElementById('shipPhotoCounter');
    if (counter) counter.textContent = `${shipPhotoFiles.length} captured`;
}

function updateShipCameraBtnText() {
    const btnText = document.getElementById('shipCameraBtnText');
    if (btnText) {
        btnText.textContent = shipPhotoFiles.length === 0
            ? 'Open Camera & Capture'
            : `Add More Photos (${shipPhotoFiles.length}/${MAX_SHIP_PHOTOS})`;
    }
    const clearBtn = document.getElementById('clearShipPhotosBtn');
    if (clearBtn) clearBtn.style.display = shipPhotoFiles.length > 0 ? 'inline-block' : 'none';
}

function renderShipPhotos() {
    const grid = document.getElementById('shipPhotoPreview');
    if (!grid) return;
    if (shipPhotoFiles.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:1.5rem 1rem;background:#f9fafb;border:2px dashed #d1d5db;border-radius:10px;color:#9ca3af;">
                <i class="fas fa-camera" style="font-size:1.75rem;margin-bottom:0.5rem;display:block;opacity:0.5;"></i>
                <div style="font-size:0.8rem;font-weight:600;">No photos captured yet</div>
                <div style="font-size:0.7rem;margin-top:0.25rem;">Click "Open Camera & Capture" to start</div>
            </div>`;
        return;
    }
    let html = '';
    shipPhotoFiles.forEach((file, idx) => {
        if (!file._previewUrl) file._previewUrl = URL.createObjectURL(file);
        html += `<div class="ship-photo-item">
            <div class="ship-photo-number">${idx + 1}</div>
            <img src="${file._previewUrl}" alt="Shipment photo ${idx + 1}">
            <button type="button" class="ship-photo-remove" onclick="removeShipPhoto(${idx})" title="Remove"><i class="fas fa-times"></i></button>
        </div>`;
    });
    grid.innerHTML = html;
}

function removeShipPhoto(index) {
    if (index < 0 || index >= shipPhotoFiles.length) return;
    const file = shipPhotoFiles[index];
    if (file._previewUrl) URL.revokeObjectURL(file._previewUrl);
    shipPhotoFiles.splice(index, 1);
    renderShipPhotos();
    updateShipCameraBtnText();
    updateShipPhotoCounter();
}

function clearAllShipPhotos() {
    Swal.fire({
        title: 'Clear all photos?', text: 'Mawawala lahat ng na-capture.',
        icon: 'question', showCancelButton: true,
        confirmButtonText: 'Yes, clear', confirmButtonColor: '#dc3545'
    }).then(result => {
        if (result.isConfirmed) {
            shipPhotoFiles.forEach(f => { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
            shipPhotoFiles = [];
            renderShipPhotos();
            updateShipCameraBtnText();
            updateShipPhotoCounter();
        }
    });
}

// ============================================
// RIDER AVAILABILITY CHECK
// ============================================
function checkRiderAvailability() {
    const date = document.getElementById('ship_assign_date').value;
    const timeStart = document.getElementById('ship_assign_time_start').value;
    const timeEnd = document.getElementById('ship_assign_time_end').value;
    const poId = document.getElementById('ship_po_id').value;

    if (!date || !timeStart || !timeEnd) return;
    if (timeStart >= timeEnd) {
        document.getElementById('riderListContainer').innerHTML = `
            <div class="alert alert-warning mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>Time From must be before Time To
            </div>`;
        return;
    }

    window.selectedRiderId = null;
    const submitBtn = document.getElementById('shipAssignSubmitBtn');
    if (submitBtn) submitBtn.disabled = true;

    document.getElementById('riderListContainer').innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-teal"></div>
            <span class="text-muted ms-2">Checking availability...</span>
        </div>`;

    const url = `../api/supplier_rider_check.php?action=check_availability&date=${encodeURIComponent(date)}&time_start=${encodeURIComponent(timeStart)}&time_end=${encodeURIComponent(timeEnd)}&exclude_po_id=${poId}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('riderListContainer').innerHTML = `<div class="alert alert-danger mb-0">${data.error}</div>`;
                return;
            }
            const dayInfo = document.getElementById('assignDayInfo');
            const dayText = document.getElementById('assignDayText');
            if (dayInfo && dayText) {
                dayText.textContent = `Selected: ${data.day_name}, ${formatDate(date)} • Window: ${timeStart} - ${timeEnd}`;
                dayInfo.style.display = 'block';
            }
            renderRiderList(data);
        })
        .catch(err => {
            document.getElementById('riderListContainer').innerHTML = `<div class="alert alert-danger mb-0">Network error: ${err.message}</div>`;
        });
}

function renderRiderList(data) {
    const container = document.getElementById('riderListContainer');
    const available = data.available || [];
    const unavailable = data.unavailable || [];

    if (available.length === 0 && unavailable.length === 0) {
        container.innerHTML = `<div class="alert alert-warning mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Walang riders na available. Siguraduhing may Active + Verified rider ka.</div>`;
        return;
    }

    let html = '';
    if (available.length > 0) {
        html += `<div class="mb-2" style="font-size:0.72rem;font-weight:800;color:#059669;text-transform:uppercase;letter-spacing:.05em;"><i class="fas fa-check-circle"></i> Available (${available.length})</div>`;
        available.forEach(r => { html += renderRiderOption(r, true); });
    }
    if (unavailable.length > 0) {
        html += `<div class="mt-3 mb-2" style="font-size:0.72rem;font-weight:800;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;"><i class="fas fa-times-circle"></i> Not Available (${unavailable.length})</div>`;
        unavailable.forEach(r => { html += renderRiderOption(r, false); });
    }
    container.innerHTML = html;
}

function renderRiderOption(r, isAvailable) {
    const avatar = r.photo_path
        ? `<img src="../${escapeHtml(r.photo_path)}" class="rider-option-avatar" onerror="this.outerHTML='<div class=\\'rider-option-avatar\\'><i class=\\'fas fa-user\\'></i></div>';">`
        : `<div class="rider-option-avatar"><i class="fas fa-user"></i></div>`;

    let shiftBadge = '';
    if (r.shift_info) {
        shiftBadge = `<span class="rider-shift-badge"><i class="fas fa-clock"></i> ${r.shift_info.time_in} – ${r.shift_info.time_out}</span>`;
    }

    const vehicle = [r.vehicle_type, r.plate_number].filter(Boolean).join(' • ');

    let conflictHtml = '';
    if (!isAvailable && r.conflicts && r.conflicts.length > 0) {
        conflictHtml = `<div class="rider-option-status"><ul class="rider-conflict-list mb-0">${r.conflicts.map(c => `<li><i class="fas fa-exclamation-circle"></i><span>${escapeHtml(c)}</span></li>`).join('')}</ul></div>`;
    } else if (isAvailable) {
        conflictHtml = `<div class="rider-option-status" style="color:#059669;font-size:0.75rem;"><i class="fas fa-check-circle me-1"></i>Available • Nasa shift at walang conflict</div>`;
    }

    const onclickAttr = isAvailable ? `onclick="selectRiderOption(${r.id}, this)"` : '';

    return `<div class="rider-option ${isAvailable ? '' : 'rider-disabled'}" 
                 data-rider-id="${r.id}" 
                 data-available="${isAvailable ? '1' : '0'}"
                 ${onclickAttr}
                 style="cursor:${isAvailable ? 'pointer' : 'not-allowed'};">
        <div class="rider-option-header">
            <input type="radio" name="assign_rider_radio" value="${r.id}" 
                   ${isAvailable ? '' : 'disabled'}
                   onclick="event.stopPropagation(); selectRiderOption(${r.id}, this.closest('.rider-option'))">
            ${avatar}
            <div style="flex:1;min-width:0;">
                <div class="rider-option-name">${escapeHtml(r.full_name)}</div>
                <div class="rider-option-details">
                    <span><i class="fas fa-phone"></i> ${escapeHtml(r.phone)}</span>
                    ${vehicle ? `<span><i class="fas fa-motorcycle"></i> ${escapeHtml(vehicle)}</span>` : ''}
                </div>
                ${shiftBadge ? `<div style="margin-top:4px;">${shiftBadge}</div>` : ''}
            </div>
        </div>
        ${conflictHtml}
    </div>`;
}

window.selectRiderOption = function(riderId, element) {
    if (!riderId) return;

    window.selectedRiderId = riderId;
    window._savedRiderId = riderId;

    const hidden = document.getElementById('ship_rider_id');
    if (hidden) hidden.value = riderId;

    document.querySelectorAll('#riderListContainer .rider-option').forEach(el => {
        el.classList.remove('selected');
    });

    let option = element;
    if (!option || !option.classList || !option.classList.contains('rider-option')) {
        option = document.querySelector(`#riderListContainer .rider-option[data-rider-id="${riderId}"]`);
    }
    if (option) {
        option.classList.add('selected');
    }

    const radio = document.querySelector(`input[name="assign_rider_radio"][value="${riderId}"]`);
    if (radio) radio.checked = true;

    const btn = document.getElementById('shipAssignSubmitBtn');
    if (btn) btn.disabled = false;
};

document.addEventListener('click', function(e) {
    const option = e.target.closest('#riderListContainer .rider-option');
    if (!option) return;
    if (option.dataset.available !== '1') return;
    
    const riderId = parseInt(option.dataset.riderId, 10);
    if (!riderId) return;
    
    if (window.selectedRiderId !== riderId) {
        selectRiderOption(riderId, option);
    }
});

document.addEventListener('change', function (e) {
    if (e.target.name !== 'assign_rider_radio') return;
    const option = e.target.closest('.rider-option');
    if (!option || option.dataset.available !== '1') return;

    window.selectedRiderId = parseInt(option.dataset.riderId, 10);
    const hidden = document.getElementById('ship_rider_id');
    if (hidden) hidden.value = window.selectedRiderId;

    document.querySelectorAll('#riderListContainer .rider-option').forEach(el => el.classList.remove('selected'));
    option.classList.add('selected');

    const btn = document.getElementById('shipAssignSubmitBtn');
    if (btn) btn.disabled = false;
});

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('shipAndAssignForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const shippingFee = parseFloat(document.getElementById('shipping_fee').value);
        if (!shippingFee || shippingFee <= 0) {
            Swal.fire('Required', 'Please enter the shipping fee.', 'warning');
            return;
        }

        const date = document.getElementById('ship_assign_date').value;
        const timeStart = document.getElementById('ship_assign_time_start').value;
        const timeEnd = document.getElementById('ship_assign_time_end').value;

        if (!date || !timeStart || !timeEnd) {
            Swal.fire('Required', 'Please set delivery date and time.', 'warning');
            return;
        }
        if (timeStart >= timeEnd) {
            Swal.fire('Invalid Time', 'Time From must be before Time To.', 'warning');
            return;
        }

        let riderId = null;

        if (window.selectedRiderId) riderId = window.selectedRiderId;
        if (!riderId && window._savedRiderId) riderId = window._savedRiderId;
        if (!riderId) {
            const checkedRadio = document.querySelector('input[name="assign_rider_radio"]:checked');
            if (checkedRadio && checkedRadio.value) riderId = parseInt(checkedRadio.value, 10);
        }
        if (!riderId) {
            const selectedOpt = document.querySelector('#riderListContainer .rider-option.selected');
            if (selectedOpt && selectedOpt.dataset.riderId) riderId = parseInt(selectedOpt.dataset.riderId, 10);
        }
        if (!riderId) {
            const hiddenInput = document.getElementById('ship_rider_id');
            if (hiddenInput && hiddenInput.value) riderId = parseInt(hiddenInput.value, 10);
        }

        if (!riderId || isNaN(riderId)) {
            Swal.fire({
                icon: 'warning',
                title: 'Rider Required',
                html: 'Please select a rider from the list.<br><small class="text-muted">Kung may rider na naka-select, subukan i-refresh ang page.</small>',
                confirmButtonColor: '#0d9488'
            });
            return;
        }

        const formData = new FormData();
        formData.append('action', 'ship_and_assign');
        formData.append('po_id', document.getElementById('ship_po_id').value);
        formData.append('pr_id', document.getElementById('ship_pr_id').value);
        formData.append('shipping_fee', shippingFee);
        formData.append('delivery_date', date);
        formData.append('time_start', timeStart);
        formData.append('time_end', timeEnd);
        formData.append('rider_id', riderId);
        formData.append('remarks', document.querySelector('textarea[name="remarks"]').value);

        shipPhotoFiles.forEach((file, index) => {
            formData.append('shipment_photos[]', file, file.name);
        });

        Swal.fire({
            title: 'Confirm Ship & Assign?',
            html: `<div style="text-align:left;font-size:0.9rem;">
                <div style="background:#f0fdfa;border:1px solid #5eead4;border-radius:8px;padding:10px 12px;margin-bottom:10px;">
                    <div><strong>Photos:</strong> ${shipPhotoFiles.length}</div>
                    <div><strong>Shipping Fee:</strong> ₱${shippingFee.toFixed(2)}</div>
                    <div><strong>Date:</strong> ${formatDate(date)}</div>
                    <div><strong>Time:</strong> ${timeStart} – ${timeEnd}</div>
                    <div><strong>Rider ID:</strong> ${riderId}</div>
                </div>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d9488',
            confirmButtonText: 'Yes, Ship & Assign'
        }).then(result => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Processing...',
                html: `<div class="text-center"><div class="spinner-border text-success mb-2"></div><div>Shipping & assigning...</div></div>`,
                allowOutsideClick: false,
                showConfirmButton: false
            });

            fetch('../api/supplier_po.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Shipped & Assigned!',
                        html: `<div style="text-align:left;">
                            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 12px;margin-bottom:12px;">
                                <div><strong>Reference #:</strong> ${data.tracking || '—'}</div>
                                <div><strong>Rider:</strong> ${data.rider_name || 'Assigned'}</div>
                            </div>
                        </div>`,
                        confirmButtonColor: '#0d9488',
                        confirmButtonText: 'Got it'
                    }).then(() => {
                        shipPhotoFiles.forEach(f => { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
                        shipPhotoFiles = [];
                        window.selectedRiderId = null;
                        window._savedRiderId = null;
                        const modal = bootstrap.Modal.getInstance(document.getElementById('shipPOModal'));
                        if (modal) modal.hide();
                        loadToShipOrders();
                        loadShippedOrders();
                        loadDashboardStats();
                    });
                } else {
                    Swal.fire('Error', data.error || 'Failed to ship', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const shipModal = document.getElementById('shipPOModal');
    if (shipModal) {
        shipModal.addEventListener('hidden.bs.modal', function() {
            closeShipCamera();
            shipPhotoFiles.forEach(f => { if (f._previewUrl) URL.revokeObjectURL(f._previewUrl); });
            shipPhotoFiles = [];
            window.selectedRiderId = null;
        });
    }
});

// ============================================
// DELIVERY HISTORY
// ============================================
let allDeliveryData = [];

function loadDeliveryHistory() {
    $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Loading...</td></tr>');
    $.ajax({
        url: '../api/supplier_po.php', method: 'GET',
        data: { action: 'get_pos', status: 'Delivered' },
        success: function(response) {
            if (response.success) {
                allDeliveryData = response.data || [];
                renderDeliveryHistory(allDeliveryData);
            } else {
                $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center text-danger">Failed to load delivery history</td></tr>');
            }
        },
        error: function() {
            $('#deliveryHistoryTableBody').html('<tr><td colspan="9" class="text-center text-danger">Error loading delivery history</td></tr>');
        }
    });
}

function applyDeliveryFilter() {
    const search = ($('#deliveryHistorySearch').val() || '').toLowerCase().trim();
    const fromVal = $('#dhFromDate').val();
    const toVal = $('#dhToDate').val();
    const fromDate = fromVal ? new Date(fromVal) : null;
    const toDate = toVal ? new Date(toVal + 'T23:59:59') : null;

    const filtered = allDeliveryData.filter(po => {
        if (search) {
            const haystack = `${po.po_number} ${po.pr_number} ${po.department}`.toLowerCase();
            if (!haystack.includes(search)) return false;
        }
        if (fromDate || toDate) {
            const d = po.actual_delivery ? new Date(po.actual_delivery) : null;
            if (!d) return false;
            if (fromDate && d < fromDate) return false;
            if (toDate && d > toDate) return false;
        }
        return true;
    });
    renderDeliveryHistory(filtered);
}

function clearDeliveryFilter() {
    $('#deliveryHistorySearch').val('');
    $('#dhFromDate').val('');
    $('#dhToDate').val('');
    renderDeliveryHistory(allDeliveryData);
}

function renderDeliveryHistory(data) {
    let html = '';
    let totalValue = 0;
    const now = new Date();
    let thisMonthCount = 0;

    if (data.length > 0) {
        data.forEach(po => {
            const amount = parseFloat(po.total_amount || 0);
            totalValue += amount;
            const deliveredDate = po.actual_delivery ? new Date(po.actual_delivery) : null;
            if (deliveredDate && deliveredDate.getMonth() === now.getMonth() && deliveredDate.getFullYear() === now.getFullYear()) {
                thisMonthCount++;
            }
            html += `<tr>
                <td><strong>${po.po_number}</strong></td>
                <td>${po.pr_number || '-'}</td>
                <td>${new Date(po.created_at).toLocaleDateString()}</td>
                <td>${po.department || 'N/A'}</td>
                <td class="fw-bold">₱${amount.toFixed(2)}</td>
                <td>${deliveredDate ? deliveredDate.toLocaleDateString() : '<span class="text-muted">N/A</span>'}</td>
                <td>${po.tracking_number ? `<span class="badge bg-info">${po.tracking_number}</span><br><small class="text-muted">${po.carrier || ''}</small>` : '<span class="text-muted">-</span>'}</td>
                <td><span class="badge bg-success">Delivered</span></td>
                <td><button class="btn btn-sm btn-info" onclick="viewPODetails(${po.id})" title="View Details"><i class="fas fa-eye"></i></button></td>
            </tr>`;
        });
    } else {
        html = '<tr><td colspan="9" class="text-center py-4"><i class="fas fa-truck fa-2x text-muted mb-2 d-block"></i>No delivery records found</td></tr>';
    }

    $('#deliveryHistoryTableBody').html(html);
    $('#dhTotalDelivered').text(data.length);
    $('#dhTotalValue').text('₱' + totalValue.toFixed(2));
    $('#dhThisMonth').text(thisMonthCount);
}

// ============================================
// COMPANY PROFILE
// ============================================
function toggleProfileEdit() {
    const view = document.getElementById('profileViewMode');
    const edit = document.getElementById('profileEditMode');
    const btn = document.getElementById('editProfileBtn');
    if (edit.style.display === 'none') {
        view.style.display = 'none';
        edit.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times me-1"></i> Cancel';
        btn.classList.replace('btn-teal', 'btn-secondary');
    } else {
        view.style.display = 'block';
        edit.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-edit me-1"></i> Edit Profile';
        btn.classList.replace('btn-secondary', 'btn-teal');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const profileForm = document.getElementById('profileEditForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update');
            formData.append('id', supplierId);
            $.ajax({
                url: '../api/supplier.php', method: 'POST',
                data: formData, processData: false, contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Profile Updated!', timer: 1500 }).then(() => { location.reload(); });
                    } else {
                        Swal.fire('Error', response.error || 'Could not update profile', 'error');
                    }
                },
                error: function() { Swal.fire('Error', 'Network error. Please try again.', 'error'); }
            });
        });
    }
});

// ============================================
// CONTACTS
// ============================================
function loadContacts() {
    $.ajax({
        url: '../api/supplier.php', method: 'GET',
        data: { action: 'get_contacts', supplier_id: supplierId },
        success: function(response) {
            if (response.success && response.data) {
                renderContacts(response.data);
            }
        }
    });
}

function renderContacts(contacts) {
    const grid = document.getElementById('contactsContainer');
    const emptyState = document.getElementById('contactsEmptyState');

    if (!contacts || contacts.length === 0) {
        if (grid) grid.innerHTML = '';
        if (emptyState) emptyState.style.display = 'block';
        return;
    }
    if (emptyState) emptyState.style.display = 'none';

    let html = '';
    contacts.forEach(c => {
        html += `<div class="col-md-4 mb-3">
            <div class="dashboard-card">
                <div class="d-flex align-items-start justify-content-between">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:48px;height:48px;background:#e6f4f3;">
                            <i class="fas fa-user" style="color:var(--teal-600);"></i>
                        </div>
                        <div>
                            <div class="fw-bold">${escapeHtml(c.contact_name)}</div>
                            <div class="text-muted small">${escapeHtml(c.position || 'N/A')}</div>
                        </div>
                    </div>
                    ${c.is_primary ? '<span class="badge bg-success">Primary</span>' : ''}
                </div>
                <hr class="my-2">
                <div class="small">
                    ${c.email ? `<div class="mb-1"><i class="fas fa-envelope text-muted me-2"></i>${escapeHtml(c.email)}</div>` : ''}
                    ${c.phone ? `<div class="mb-1"><i class="fas fa-phone text-muted me-2"></i>${escapeHtml(c.phone)}</div>` : ''}
                    ${c.mobile ? `<div class="mb-1"><i class="fas fa-mobile-alt text-muted me-2"></i>${escapeHtml(c.mobile)}</div>` : ''}
                    ${c.department ? `<div class="mb-1"><i class="fas fa-building text-muted me-2"></i>${escapeHtml(c.department)}</div>` : ''}
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editContact(${c.id})"><i class="fas fa-edit"></i> Edit</button>
                    <button class="btn btn-sm btn-outline-danger flex-fill" onclick="deleteContact(${c.id})"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </div>
        </div>`;
    });
    if (grid) grid.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    const addContactForm = document.getElementById('addContactForm');
    if (addContactForm) {
        addContactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'add_contact');
            formData.append('supplier_id', supplierId);
            $.ajax({
                url: '../api/supplier.php', method: 'POST',
                data: formData, processData: false, contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Contact Added!', timer: 1200 }).then(() => {
                            bootstrap.Modal.getInstance(document.getElementById('addContactModal')).hide();
                            addContactForm.reset();
                            loadContacts();
                        });
                    } else {
                        Swal.fire('Error', response.error || 'Could not add contact', 'error');
                    }
                }
            });
        });
    }

    const editContactForm = document.getElementById('editContactForm');
    if (editContactForm) {
        editContactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_contact');
            formData.append('supplier_id', supplierId);
            $.ajax({
                url: '../api/supplier.php', method: 'POST',
                data: formData, processData: false, contentType: false,
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Contact Updated!', timer: 1200 }).then(() => {
                            bootstrap.Modal.getInstance(document.getElementById('editContactModal')).hide();
                            loadContacts();
                        });
                    } else {
                        Swal.fire('Error', response.error || 'Could not update contact', 'error');
                    }
                }
            });
        });
    }
});

function editContact(id) {
    $.get('../api/supplier.php', { action: 'get_contact', id: id, supplier_id: supplierId }, function(r) {
        if (r.success && r.data) {
            const c = r.data;
            $('#editContactId').val(c.id);
            $('#editContactName').val(c.contact_name || '');
            $('#editContactPosition').val(c.position || '');
            $('#editContactEmail').val(c.email || '');
            $('#editContactPhone').val(c.phone || '');
            $('#editContactMobile').val(c.mobile || '');
            $('#editContactDept').val(c.department || '');
            $('#editIsPrimary').prop('checked', c.is_primary == 1);
            $('#editContactModal').modal('show');
        } else {
            Swal.fire('Error', 'Could not load contact details', 'error');
        }
    }).fail(function() { Swal.fire('Error', 'Network error loading contact', 'error'); });
}

function deleteContact(id) {
    Swal.fire({
        title: 'Delete Contact?', text: 'This action cannot be undone.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('../api/supplier.php', { action: 'delete_contact', contact_id: id, supplier_id: supplierId }, function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Contact deleted', timer: 1200 }).then(() => loadContacts());
                } else {
                    Swal.fire('Error', response.error || 'Could not delete contact', 'error');
                }
            });
        }
    });
}

// ============================================
// PRODUCTS
// ============================================
function loadProducts(page = 1) {
    currentProductPage = page;
    const category = document.getElementById('product_category_filter')?.value || 'all';
    const search = document.getElementById('product_search')?.value || '';
    let url = `../api/supplier_products.php?action=get_products&supplier_id=${supplierId}&page=${page}&limit=10`;
    if (category && category !== 'all') url += `&category=${encodeURIComponent(category)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;

    fetch(url).then(res => res.json()).then(data => {
        if (data.success) {
            displayProducts(data.data);
            updateProductPagination(data.pagination);
        } else {
            document.getElementById('products_table_body').innerHTML = `<tr><td colspan="9" class="text-center text-danger">${data.message}</td></tr>`;
        }
    }).catch(err => {
        document.getElementById('products_table_body').innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error loading products</td></tr>';
    });
}

function displayProducts(products) {
    const tbody = document.getElementById('products_table_body');
    if (!tbody) return;
    if (!products || products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4"><i class="fas fa-box-open fa-3x mb-3"></i><br>No products found</td></tr>';
        return;
    }
    let html = '';
    products.forEach(p => {
        const hasPhoto = p.has_photo
            ? `<img src="../${p.photo_path}" alt="Product" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">`
            : '<div class="bg-light text-center p-2" style="width: 50px; height: 50px; border-radius: 5px;"><i class="fas fa-image text-muted"></i></div>';
        const has3d = p.has_3d_model
            ? `<span class="badge" style="background:#008080; font-size:0.75rem;"><i class="fas fa-cube"></i> 3D</span>`
            : '<span class="badge bg-secondary" style="font-size:0.75rem; opacity:0.6;">—</span>';
        const inInventory = p.linked_count > 0
            ? `<span class="badge bg-success">${p.linked_count} items</span>`
            : '<span class="badge bg-secondary">Not linked</span>';

        html += `<tr>
            <td>${hasPhoto}</td>
            <td><strong>${escapeHtml(p.product_code)}</strong></td>
            <td>${escapeHtml(p.product_name)}</td>
            <td>${escapeHtml(p.category)}</td>
            <td>${escapeHtml(p.brand || '-')}</td>
            <td>₱${parseFloat(p.cost_price).toFixed(2)}</td>
            <td>₱${parseFloat(p.selling_price).toFixed(2)}</td>
            <td class="text-center">${has3d}</td>
            <td>${inInventory}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewProduct(${p.id})" title="View"><i class="fas fa-eye"></i></button>
                <button class="btn btn-sm btn-warning" onclick="editProduct(${p.id})" title="Edit"><i class="fas fa-edit"></i></button>
                ${p.linked_count === 0 ? `<button class="btn btn-sm btn-danger" onclick="deleteProduct(${p.id})" title="Delete"><i class="fas fa-trash"></i></button>` : ''}
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function updateProductPagination(pagination) {
    const info = document.getElementById('products_pagination_info');
    const ul = document.getElementById('products_pagination');
    if (!info || !ul) return;
    if (!pagination || pagination.total_pages <= 1) {
        info.innerHTML = `Showing ${pagination?.total || 0} products`;
        ul.innerHTML = '';
        return;
    }
    info.innerHTML = `Page ${pagination.page} of ${pagination.total_pages} (${pagination.total} products)`;
    let pages = '';
    for (let i = 1; i <= pagination.total_pages; i++) {
        pages += `<li class="page-item ${i === pagination.page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadProducts(${i}); return false;">${i}</a></li>`;
    }
    ul.innerHTML = pages;
}

function addProduct() {
    const form = document.getElementById('add_product_form');
    if (!form) return;
    const formData = new FormData(form);
    formData.append('action', 'add_product');
    formData.append('supplier_id', supplierId);

    const model3dInput = document.getElementById('product_model_3d');
    if (model3dInput && model3dInput.files && model3dInput.files[0]) {
        formData.append('model_3d', model3dInput.files[0]);
    }

    Swal.fire({ title: 'Saving product...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('../api/supplier_products.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Product Added!', text: data.message, timer: 1500 }).then(() => {
                form.reset();
                const preview = document.getElementById('photo_preview');
                if (preview) preview.style.display = 'none';
                const model3dPreview = document.getElementById('model_3d_preview');
                if (model3dPreview) model3dPreview.style.display = 'none';
                showModule('products_list');
                loadProducts(1);
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => { Swal.fire('Error', 'Failed to add product', 'error'); });
}

function viewProduct(id) {
    fetch(`../api/supplier_products.php?action=get_product&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayProductDetails(data.data);
                new bootstrap.Modal(document.getElementById('productDetailsModal')).show();
            } else {
                Swal.fire('Error', 'Product not found', 'error');
            }
        });
}

function displayProductDetails(p) {
    const modalBody = document.getElementById('product_details_body');
    if (!modalBody) return;
    const hasPhoto = p.has_photo
        ? `<img src="../${p.photo_path}" alt="Product" style="max-width: 200px; max-height: 200px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">`
        : '<div class="bg-light text-center p-5 rounded"><i class="fas fa-image fa-4x text-muted"></i><p class="mt-2">No photo</p></div>';

    let model3dHtml = '';
    if (p.has_3d_model && p.model_3d_path) {
        model3dHtml = `<div class="mt-3 p-3 rounded" style="background:#f0fdfa; border:1px solid #5eead4;">
            <div class="d-flex align-items-center gap-3 mb-2">
                <i class="fas fa-cube" style="color:#008080; font-size:2rem;"></i>
                <div style="flex:1;">
                    <div class="fw-bold" style="color:#0f766e;"><i class="fas fa-check-circle"></i> 3D Model Available</div>
                    <small class="text-muted">${escapeHtml(p.model_3d_filename || '3D file')}</small>
                </div>
            </div>
            <div class="d-flex gap-2 mt-2">
                <a href="../${escapeHtml(p.model_3d_path)}" download class="btn btn-sm" style="background:#008080; color:white;"><i class="fas fa-download"></i> Download 3D</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="previewModel3D('${escapeHtml(p.model_3d_path)}', '${escapeHtml(p.product_name).replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i> Preview 3D</button>
            </div>
        </div>`;
    } else {
        model3dHtml = `<div class="mt-3 p-3 rounded" style="background:#f8fafc; border:1px dashed #cbd5e1;">
            <div class="d-flex align-items-center gap-3">
                <i class="fas fa-cube text-muted" style="font-size:2rem; opacity:0.4;"></i>
                <div>
                    <div class="text-muted fw-bold">No 3D Model</div>
                    <small class="text-muted">Mag-upload ng .glb file para makita ng clinics sa Available 3D Frames</small>
                </div>
            </div>
        </div>`;
    }

    let linkedHtml = '';
    if (p.linked_inventory && p.linked_inventory.length > 0) {
        linkedHtml = '<h6 class="mt-3">Linked Inventory Items:</h6><ul class="list-group">';
        p.linked_inventory.forEach(inv => {
            linkedHtml += `<li class="list-group-item d-flex justify-content-between align-items-center">${escapeHtml(inv.item_code)} - ${escapeHtml(inv.name)} <span class="badge bg-${inv.stock > 0 ? 'success' : 'danger'}">Stock: ${inv.stock}</span></li>`;
        });
        linkedHtml += '</ul>';
    }

    modalBody.innerHTML = `<div class="row">
        <div class="col-md-4 text-center mb-3">${hasPhoto}</div>
        <div class="col-md-8">
            <h4 class="mb-3">${escapeHtml(p.product_name)}</h4>
            <table class="table table-sm">
                <tr><th width="35%">Product Code:</th><td>${escapeHtml(p.product_code)}</td></tr>
                <tr><th>Category:</th><td>${escapeHtml(p.category)}</td></tr>
                <tr><th>Brand:</th><td>${escapeHtml(p.brand || 'N/A')}</td></tr>
                <tr><th>Cost Price:</th><td class="fw-bold text-primary">₱${parseFloat(p.cost_price).toFixed(2)}</td></tr>
                <tr><th>Selling Price:</th><td class="fw-bold text-success">₱${parseFloat(p.selling_price).toFixed(2)}</td></tr>
                ${p.wholesale_price ? `<tr><th>Wholesale:</th><td>₱${parseFloat(p.wholesale_price).toFixed(2)}</td></tr>` : ''}
                <tr><th>Unit:</th><td>${escapeHtml(p.unit)}</td></tr>
                <tr><th>Min Order:</th><td>${p.min_order_qty}</td></tr>
                ${p.lead_time_days ? `<tr><th>Lead Time:</th><td>${p.lead_time_days} days</td></tr>` : ''}
            </table>
            ${p.description ? `<div class="mt-3"><strong>Description:</strong><p>${escapeHtml(p.description)}</p></div>` : ''}
            ${p.notes ? `<div class="mt-2"><strong>Notes:</strong><p class="text-muted">${escapeHtml(p.notes)}</p></div>` : ''}
            ${model3dHtml}
            ${linkedHtml}
        </div>
    </div>`;
}

function editProduct(id) {
    fetch(`../api/supplier_products.php?action=get_product&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const p = data.data;
                document.getElementById('edit_product_id').value = p.id;
                document.getElementById('edit_product_code').value = p.product_code;
                document.getElementById('edit_product_name').value = p.product_name;
                document.getElementById('edit_product_category').value = p.category;
                document.getElementById('edit_product_brand').value = p.brand || '';
                document.getElementById('edit_cost_price').value = p.cost_price;
                document.getElementById('edit_selling_price').value = p.selling_price;
                document.getElementById('edit_wholesale_price').value = p.wholesale_price || '';
                document.getElementById('edit_product_description').value = p.description || '';
                document.getElementById('edit_stock').value = p.stock || 0;
                document.getElementById('edit_low_stock_threshold').value = p.low_stock_threshold || 5;

                const photoDiv = document.getElementById('current_photo_display');
                if (photoDiv) {
                    if (p.has_photo) {
                        photoDiv.innerHTML = `<div class="border p-2 rounded"><small class="text-muted">Current Photo:</small><br><img src="../${p.photo_path}" style="max-height: 100px; border-radius:6px;"></div>`;
                    } else {
                        photoDiv.innerHTML = '<p class="text-muted mb-0"><small>No current photo</small></p>';
                    }
                }

                const model3dDisplay = document.getElementById('current_model_3d_display');
                if (model3dDisplay) {
                    if (p.has_3d_model && p.model_3d_filename) {
                        model3dDisplay.innerHTML = `<div class="border rounded p-3" style="background:#f0fdfa; border-color:#5eead4 !important;">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-cube" style="color:#008080; font-size:1.5rem;"></i>
                                <div style="flex:1;">
                                    <div class="fw-bold" style="color:#0f766e; font-size:0.9rem;">${escapeHtml(p.model_3d_filename)}</div>
                                    <small class="text-muted">Current 3D model — will be kept if no new file is uploaded</small>
                                </div>
                                <span class="badge" style="background:#5eead4; color:#0f766e;">Existing</span>
                            </div>
                        </div>`;
                    } else {
                        model3dDisplay.innerHTML = '<p class="text-muted mb-0"><small>No 3D model yet</small></p>';
                    }
                }

                const model3dPreview = document.getElementById('edit_model_3d_preview');
                if (model3dPreview) model3dPreview.style.display = 'none';
                const model3dInput = document.getElementById('edit_product_model_3d');
                if (model3dInput) { model3dInput.value = ''; model3dInput.disabled = false; }
                const remove3dCheck = document.getElementById('remove_3d_model');
                if (remove3dCheck) remove3dCheck.checked = false;

                new bootstrap.Modal(document.getElementById('editProductModal')).show();
            } else {
                Swal.fire('Error', 'Failed to load product', 'error');
            }
        });
}

function updateProduct() {
    const form = document.getElementById('edit_product_form');
    if (!form) return;
    const formData = new FormData(form);
    formData.append('action', 'update_product');

    const model3dInput = document.getElementById('edit_product_model_3d');
    if (model3dInput && model3dInput.files && model3dInput.files[0]) {
        formData.append('model_3d', model3dInput.files[0]);
    }

    const productId = formData.get('product_id');
    if (!productId) { Swal.fire('Error', 'Product ID is missing from form', 'error'); return; }

    Swal.fire({ title: 'Updating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch('../api/supplier_products.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({ icon: 'success', title: 'Updated!', text: data.message, timer: 1500 }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editProductModal'));
                if (modal) modal.hide();
                loadProducts(currentProductPage);
            });
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    });
}

function deleteProduct(id) {
    Swal.fire({
        title: 'Delete Product?', text: 'This will deactivate the product. You can restore it later.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('../api/supplier_products.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_product', id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success');
                    loadProducts(currentProductPage);
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });
        }
    });
}

// ============================================
// PRICE LIST & STOCK
// ============================================
function loadPriceList() {
    const search = document.getElementById('price_search')?.value || '';
    let url = `../api/supplier_products.php?action=get_price_list&supplier_id=${supplierId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    fetch(url).then(res => res.json()).then(data => {
        if (data.success) displayPriceList(data.data);
        else {
            const tbody = document.getElementById('price_list_table_body');
            if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${data.message}</td></tr>`;
        }
    });
}

function displayPriceList(prices) {
    const tbody = document.getElementById('price_list_table_body');
    if (!tbody) return;
    if (!prices || prices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4">No price list data</td></tr>';
        return;
    }
    let html = '';
    prices.forEach(p => {
        const margin = p.margin || 0;
        const marginClass = margin > 30 ? 'text-success' : (margin > 15 ? 'text-warning' : 'text-danger');
        html += `<tr>
            <td>${escapeHtml(p.product_code)}</td>
            <td>${escapeHtml(p.product_name)}</td>
            <td>${escapeHtml(p.brand || '-')}</td>
            <td>${escapeHtml(p.category)}</td>
            <td class="text-end">₱${parseFloat(p.cost_price).toFixed(2)}</td>
            <td class="text-end fw-bold">₱${parseFloat(p.selling_price).toFixed(2)}</td>
            <td class="text-end">${p.wholesale_price ? '₱' + parseFloat(p.wholesale_price).toFixed(2) : '-'}</td>
            <td class="text-end ${marginClass}">${margin}%</td>
            <td>${p.in_inventory > 0 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-warning">Not in Inv</span>'}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function loadStockLevels() {
    const search = document.getElementById('stock_search')?.value || '';
    let url = `../api/supplier_products.php?action=get_stock_levels&supplier_id=${supplierId}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    fetch(url).then(res => res.json()).then(data => {
        if (data.success) {
            displayStockLevels(data.data);
            updateStockSummary(data.summary);
        } else {
            const tbody = document.getElementById('stock_levels_table_body');
            if (tbody) tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">${data.message}</td></tr>`;
        }
    });
}

function displayStockLevels(stocks) {
    const tbody = document.getElementById('stock_levels_table_body');
    if (!tbody) return;
    if (!stocks || stocks.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4">No stock data</td></tr>';
        return;
    }
    let html = '';
    stocks.forEach(s => {
        let statusBadge = '', rowClass = '';
        switch(s.stock_status) {
            case 'Low Stock': statusBadge = '<span class="badge bg-warning text-dark">Low Stock</span>'; rowClass = 'table-warning'; break;
            case 'Out of Stock': statusBadge = '<span class="badge bg-danger">Out of Stock</span>'; rowClass = 'table-danger'; break;
            case 'In Stock': statusBadge = '<span class="badge bg-success">In Stock</span>'; break;
            default: statusBadge = '<span class="badge bg-secondary">Not in Inventory</span>'; rowClass = 'table-secondary';
        }
        const suggestedOrder = s.suggested_order || 0;
        const estCost = s.estimated_cost || 0;
        html += `<tr class="${rowClass}">
            <td><strong>${escapeHtml(s.product_name)}</strong><br><small class="text-muted">${escapeHtml(s.brand || '')}</small></td>
            <td>${escapeHtml(s.product_code)}</td>
            <td class="text-center fw-bold">${s.current_stock}</td>
            <td class="text-center">${s.reorder_level}</td>
            <td>${statusBadge}</td>
            <td class="text-center">${suggestedOrder}</td>
            <td class="text-end">₱${estCost.toFixed(2)}</td>
            <td>${suggestedOrder > 0 ? `<button class="btn btn-sm btn-warning" onclick="createReorder(${s.id})"><i class="fas fa-cart-plus"></i></button>` : `<button class="btn btn-sm btn-secondary" disabled><i class="fas fa-check"></i></button>`}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function updateStockSummary(summary) {
    const el = (id) => document.getElementById(id);
    if (el('summary_in_stock')) el('summary_in_stock').textContent = summary.in_stock || 0;
    if (el('summary_low_stock')) el('summary_low_stock').textContent = summary.low_stock || 0;
    if (el('summary_out_of_stock')) el('summary_out_of_stock').textContent = summary.out_of_stock || 0;
    if (el('summary_not_in_inventory')) el('summary_not_in_inventory').textContent = summary.not_in_inventory || 0;
}

function showReorderSummary() {
    fetch(`../api/supplier_products.php?action=get_stock_levels&supplier_id=${supplierId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const lowStock = data.data.filter(s => s.suggested_order > 0);
                if (lowStock.length === 0) {
                    Swal.fire({ icon: 'info', title: 'All Good!', text: 'No items need reordering at this time.' });
                    return;
                }
                let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Current</th><th>Reorder Level</th><th>Suggested</th><th>Est. Cost</th></tr></thead><tbody>';
                let totalCost = 0;
                lowStock.forEach(s => {
                    const cost = s.estimated_cost || 0;
                    totalCost += cost;
                    html += `<tr><td>${escapeHtml(s.product_name)}</td><td class="text-center">${s.current_stock}</td><td class="text-center">${s.reorder_level}</td><td class="text-center fw-bold">${s.suggested_order}</td><td class="text-end">₱${cost.toFixed(2)}</td></tr>`;
                });
                html += `<tr class="table-info fw-bold"><td colspan="4" class="text-end">TOTAL ESTIMATED COST:</td><td class="text-end">₱${totalCost.toFixed(2)}</td></tr></tbody></table></div>`;
                Swal.fire({ title: 'Reorder Suggestions', html: html, icon: 'warning', width: '800px', confirmButtonText: 'OK' });
            }
        });
}

function createReorder(productId) {
    Swal.fire({
        title: 'Create Purchase Request?',
        text: 'This will create a purchase request for this item',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, create'
    }).then((result) => {
        if (result.isConfirmed) {
            showModule('po_pending');
            Swal.fire('Info', 'Purchase request feature coming soon', 'info');
        }
    });
}

function exportProducts() { window.location.href = `../api/supplier_products.php?action=export_products&supplier_id=${supplierId}`; }
function exportPriceList() { window.location.href = `../api/supplier_products.php?action=export_price_list&supplier_id=${supplierId}`; }

// ============================================
// PRODUCTS ADD FORM HANDLERS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const productPhoto = document.getElementById('product_photo');
    if (productPhoto) {
        productPhoto.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('preview_image');
                    const previewDiv = document.getElementById('photo_preview');
                    if (preview && previewDiv) {
                        preview.src = e.target.result;
                        previewDiv.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }

    const model3dAdd = document.getElementById('product_model_3d');
    if (model3dAdd) {
        model3dAdd.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const previewDiv = document.getElementById('model_3d_preview');
            const filenameEl = document.getElementById('model_3d_filename');
            const sizeEl = document.getElementById('model_3d_size');
            if (file && previewDiv) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (!['glb', 'gltf'].includes(ext)) {
                    Swal.fire('Invalid File', 'Please select a .glb or .gltf file', 'warning');
                    this.value = ''; previewDiv.style.display = 'none'; return;
                }
                if (file.size > 50 * 1024 * 1024) {
                    Swal.fire('File Too Large', 'Maximum 50MB only', 'warning');
                    this.value = ''; previewDiv.style.display = 'none'; return;
                }
                if (filenameEl) filenameEl.textContent = file.name;
                if (sizeEl) sizeEl.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                previewDiv.style.display = 'block';
            } else if (previewDiv) previewDiv.style.display = 'none';
        });
    }

    const model3dEdit = document.getElementById('edit_product_model_3d');
    if (model3dEdit) {
        model3dEdit.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const previewDiv = document.getElementById('edit_model_3d_preview');
            const filenameEl = document.getElementById('edit_model_3d_filename');
            const sizeEl = document.getElementById('edit_model_3d_size');
            if (file && previewDiv) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (!['glb', 'gltf'].includes(ext)) {
                    Swal.fire('Invalid File', 'Please select a .glb or .gltf file', 'warning');
                    this.value = ''; previewDiv.style.display = 'none'; return;
                }
                if (file.size > 50 * 1024 * 1024) {
                    Swal.fire('File Too Large', 'Maximum 50MB only', 'warning');
                    this.value = ''; previewDiv.style.display = 'none'; return;
                }
                if (filenameEl) filenameEl.textContent = file.name;
                if (sizeEl) sizeEl.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                previewDiv.style.display = 'block';
            } else if (previewDiv) previewDiv.style.display = 'none';
        });
    }

    const addForm = document.getElementById('add_product_form');
    if (addForm) addForm.addEventListener('submit', function(e) { e.preventDefault(); addProduct(); });

    const editForm = document.getElementById('edit_product_form');
    if (editForm) editForm.addEventListener('submit', function(e) { e.preventDefault(); updateProduct(); });

    const removePhotoCheck = document.getElementById('remove_photo');
    if (removePhotoCheck) {
        removePhotoCheck.addEventListener('change', function() {
            const photoInput = document.getElementById('edit_product_photo');
            if (photoInput) photoInput.disabled = this.checked;
        });
    }

    const remove3dCheck = document.getElementById('remove_3d_model');
    if (remove3dCheck) {
        remove3dCheck.addEventListener('change', function() {
            const model3dInput = document.getElementById('edit_product_model_3d');
            if (model3dInput) {
                model3dInput.disabled = this.checked;
                if (this.checked) {
                    model3dInput.value = '';
                    const previewDiv = document.getElementById('edit_model_3d_preview');
                    if (previewDiv) previewDiv.style.display = 'none';
                }
            }
        });
    }
});

// ============================================
// 3D MODEL PREVIEW
// ============================================
function previewModel3D(modelPath, productName) {
    Swal.fire({
        title: '<span style="color:#008080;"><i class="fas fa-cube"></i> ' + productName + '</span>',
        html: '<div id="preview3dContainer" style="width:100%; height:400px; background:#f5f5f5; border-radius:12px; overflow:hidden;"></div>',
        width: '700px', showConfirmButton: false, showCloseButton: true,
        didOpen: () => {
            const container = document.getElementById('preview3dContainer');
            if (!container) return;
            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0xf5f5f5);
            const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
            camera.position.set(2.5, 1.5, 3);
            const renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            container.appendChild(renderer.domElement);
            const controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.autoRotate = true;
            controls.autoRotateSpeed = 2.0;
            scene.add(new THREE.AmbientLight(0xffffff, 0.7));
            const mainLight = new THREE.DirectionalLight(0xffffff, 1);
            mainLight.position.set(2, 3, 2); scene.add(mainLight);
            const fillLight = new THREE.PointLight(0x88aaff, 0.4);
            fillLight.position.set(-1, 1, 2); scene.add(fillLight);
            const loader = new THREE.GLTFLoader();
            loader.load('../' + modelPath, function(gltf) {
                const model = gltf.scene;
                const box = new THREE.Box3().setFromObject(model);
                const size = box.getSize(new THREE.Vector3());
                const scale = 2 / Math.max(size.x, size.y, size.z);
                model.scale.set(scale, scale, scale);
                const center = box.getCenter(new THREE.Vector3());
                model.position.sub(center.multiplyScalar(scale));
                scene.add(model);
            }, undefined, function(error) {
                container.innerHTML = '<div style="padding:40px; text-align:center; color:#dc2626;">❌ Failed to load 3D model</div>';
            });
            function animate() {
                requestAnimationFrame(animate);
                controls.update();
                renderer.render(scene, camera);
            }
            animate();
            const resizeObserver = new ResizeObserver(() => {
                if (container.clientWidth && container.clientHeight) {
                    camera.aspect = container.clientWidth / container.clientHeight;
                    camera.updateProjectionMatrix();
                    renderer.setSize(container.clientWidth, container.clientHeight);
                }
            });
            resizeObserver.observe(container);
        },
        willClose: () => {
            const container = document.getElementById('preview3dContainer');
            if (container) container.innerHTML = '';
        }
    });
}

// ============================================
// RETURNS MANAGEMENT
// ============================================
function loadReturns() {
    $.ajax({
        url: '../api/supplier_returns.php', method: 'GET',
        data: { action: 'get_returns', supplier_id: supplierId },
        success: function(response) {
            if (response.success) {
                displayReturnsByStatus(response.data);
            }
        }
    });
}

function displayReturnsByStatus(returns) {
    $('#pendingReturnsTable, #approvedReturnsTable, #completedReturnsTable, #rejectedReturnsTable, #allReturnsTable').html('');

    if (!returns || returns.length === 0) {
        $('#pendingReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#approvedReturnsTable').html('<tr><td colspan="9" class="text-center py-4">No return requests found</td></tr>');
        $('#completedReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#rejectedReturnsTable').html('<tr><td colspan="8" class="text-center py-4">No return requests found</td></tr>');
        $('#allReturnsTable').html('<tr><td colspan="9" class="text-center py-4">No return requests found</td></tr>');
        return;
    }

    let pendingRows = '', approvedRows = '', completedRows = '', rejectedRows = '', allRows = '';
    returns.forEach(r => {
        allRows += createReturnRow(r);
        switch(r.status) {
            case 'Pending': pendingRows += createPendingRow(r); break;
            case 'For Pickup': case 'For Drop-off': case 'Ready for Pickup': case 'Dropped Off':
                approvedRows += createApprovedRow(r); break;
            case 'Return Completed': case 'Completed':
                completedRows += createCompletedRow(r); break;
            case 'Rejected': rejectedRows += createRejectedRow(r); break;
        }
    });

    $('#pendingReturnsTable').html(pendingRows || '<tr><td colspan="8" class="text-center py-4">No pending returns</td></tr>');
    $('#approvedReturnsTable').html(approvedRows || '<tr><td colspan="9" class="text-center py-4">No approved returns</td></tr>');
    $('#completedReturnsTable').html(completedRows || '<tr><td colspan="8" class="text-center py-4">No completed returns</td></tr>');
    $('#rejectedReturnsTable').html(rejectedRows || '<tr><td colspan="8" class="text-center py-4">No rejected returns</td></tr>');
    $('#allReturnsTable').html(allRows || '<tr><td colspan="9" class="text-center py-4">No returns found</td></tr>');
}

function createPendingRow(r) {
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td>
            <button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View"><i class="fas fa-eye"></i></button>
            <button class="btn btn-sm btn-success" onclick="openApproveReturnModal(${r.id}, '${r.return_number}', '${r.po_number}', ${r.refund_amount})" title="Approve"><i class="fas fa-check"></i></button>
            <button class="btn btn-sm btn-danger" onclick="openRejectReturnModal(${r.id})" title="Reject"><i class="fas fa-times"></i></button>
        </td>
    </tr>`;
}

function createApprovedRow(r) {
    let actionButton = '';
    if (r.status === 'Ready for Pickup' || r.status === 'Dropped Off') {
        actionButton = `<button class="btn btn-sm btn-success" onclick="completeReturn(${r.id})" title="Complete Return"><i class="fas fa-check-double"></i> Complete</button>`;
    } else if (r.status === 'For Pickup' || r.status === 'For Drop-off') {
        actionButton = `<button class="btn btn-sm btn-secondary" disabled title="Waiting for clinic"><i class="fas fa-clock"></i> Waiting</button>`;
    }
    let statusBadgeClass = 'info', statusText = r.status;
    if (r.status === 'Ready for Pickup') { statusBadgeClass = 'warning'; statusText = 'Ready for Pickup'; }
    else if (r.status === 'Dropped Off') { statusBadgeClass = 'warning'; statusText = 'Dropped Off'; }
    else if (r.status === 'For Pickup') { statusBadgeClass = 'primary'; }
    else if (r.status === 'For Drop-off') { statusBadgeClass = 'primary'; }
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td><span class="badge bg-${statusBadgeClass}">${statusText}</span></td>
        <td>
            <button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View"><i class="fas fa-eye"></i></button>
            ${actionButton}
        </td>
    </tr>`;
}

function createCompletedRow(r) {
    let completedDate = 'N/A';
    if (r.completed_at) completedDate = new Date(r.completed_at).toLocaleDateString();
    else if (r.response_date) completedDate = new Date(r.response_date).toLocaleDateString();
    else if (r.updated_at) completedDate = new Date(r.updated_at).toLocaleDateString();
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${completedDate}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.refund_method}</td>
        <td class="fw-bold text-success">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td><button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})" title="View Details"><i class="fas fa-eye"></i></button></td>
    </tr>`;
}

function createRejectedRow(r) {
    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td>${r.supplier_response || 'No reason provided'}</td>
    </tr>`;
}

function createReturnRow(r) {
    let statusClass = 'secondary', statusText = r.status;
    const statusMap = {
        'Pending': 'warning', 'For Pickup': 'primary', 'For Drop-off': 'primary',
        'Ready for Pickup': 'warning', 'Dropped Off': 'warning',
        'Return Completed': 'success', 'Completed': 'success', 'Rejected': 'danger'
    };
    statusClass = statusMap[r.status] || 'secondary';

    let actionButtons = `<button class="btn btn-sm btn-info" onclick="viewReturnDetails(${r.id})"><i class="fas fa-eye"></i></button>`;
    if (r.status === 'Pending') {
        actionButtons += `
            <button class="btn btn-sm btn-success" onclick="openApproveReturnModal(${r.id}, '${r.return_number}', '${r.po_number}', ${r.refund_amount})"><i class="fas fa-check"></i></button>
            <button class="btn btn-sm btn-danger" onclick="openRejectReturnModal(${r.id})"><i class="fas fa-times"></i></button>`;
    } else if (r.status === 'Ready for Pickup' || r.status === 'Dropped Off') {
        actionButtons += `<button class="btn btn-sm btn-success" onclick="completeReturn(${r.id})"><i class="fas fa-check-double"></i> Complete</button>`;
    }

    return `<tr>
        <td><strong>${r.return_number}</strong></td>
        <td>${r.po_number}</td>
        <td>${r.clinic_name || 'Unknown'}</td>
        <td>${new Date(r.created_at).toLocaleDateString()}</td>
        <td class="text-center">${r.item_count}</td>
        <td>${r.reason}</td>
        <td class="fw-bold">₱${parseFloat(r.refund_amount).toFixed(2)}</td>
        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
        <td>${actionButtons}</td>
    </tr>`;
}

function completeReturn(returnId) {
    Swal.fire({
        title: 'Complete Return?',
        text: 'Confirm that you have received the returned items from the clinic.',
        icon: 'question', showCancelButton: true,
        confirmButtonText: 'Yes, complete return', confirmButtonColor: '#28a745', cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: '../api/supplier_returns.php', method: 'POST',
                data: { action: 'complete_return', return_id: returnId },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({ icon: 'success', title: 'Return Completed!', text: 'The return process has been completed.', timer: 2000 }).then(() => { loadReturns(); });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                },
                error: function() { Swal.fire('Error', 'Network error. Please try again.', 'error'); }
            });
        }
    });
}

function viewReturnDetails(returnId) {
    $('#returnDetailsContent').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#returnDetailsModal').modal('show');
    $.ajax({
        url: '../api/supplier_returns.php', method: 'GET',
        data: { action: 'get_return_details', id: returnId },
        success: function(response) {
            if (response.success) displayReturnDetails(response.data);
            else $('#returnDetailsContent').html('<div class="alert alert-danger">Failed to load details</div>');
        }
    });
}

function displayReturnDetails(r) {
    let itemsHtml = '';
    r.items.forEach(item => {
        itemsHtml += `<tr>
            <td>${item.item_name}</td>
            <td class="text-center">${item.quantity}</td>
            <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
            <td class="text-end">₱${parseFloat(item.total).toFixed(2)}</td>
        </tr>`;
    });

    let photosHtml = '';
    if (r.photos && r.photos.length > 0) {
        r.photos.forEach(photo => {
            photosHtml += `<div class="col-3"><img src="../${photo.photo_path}" class="img-fluid rounded border" style="cursor: pointer;" onclick="window.open('../${photo.photo_path}', '_blank')"></div>`;
        });
    }

    const statusClass = { 'Pending': 'warning', 'Approved': 'info', 'Completed': 'success', 'Rejected': 'danger' }[r.status] || 'secondary';

    const html = `<div class="row">
        <div class="col-md-8">
            <h6 class="text-primary">${r.return_number}</h6>
            <p><strong>PO Number:</strong> ${r.po_number}</p>
            <p><strong>Clinic:</strong> ${r.clinic_name}</p>
            <p><strong>Requested By:</strong> ${r.requested_by_name}</p>
            <p><strong>Date Requested:</strong> ${new Date(r.created_at).toLocaleString()}</p>
            <p><strong>Reason:</strong> ${r.reason}</p>
            <p><strong>Description:</strong> ${r.description || 'N/A'}</p>
            <p><strong>Contact:</strong> ${r.contact_person} - ${r.contact_number}</p>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                <h5><span class="badge bg-${statusClass}">${r.status}</span></h5>
                <h6 class="card-subtitle mt-3 mb-2 text-muted">Refund Method</h6>
                <p>${r.refund_method}</p>
                <h6 class="card-subtitle mt-3 mb-2 text-muted">Refund Amount</h6>
                <h4 class="text-primary">₱${parseFloat(r.refund_amount).toFixed(2)}</h4>
            </div></div>
        </div>
    </div>
    <h6 class="mt-4">Items Returned:</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
            <tbody>${itemsHtml}</tbody>
        </table>
    </div>
    ${photosHtml ? `<h6 class="mt-3">Evidence Photos:</h6><div class="row g-2">${photosHtml}</div>` : ''}`;

    $('#returnDetailsContent').html(html);

    if (r.status === 'Pending') {
        $('#returnDetailsFooter').html(`
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-success" onclick="openApproveReturnModal(${r.id}, '${r.return_number}', '${r.po_number}', ${r.refund_amount})"><i class="fas fa-check me-1"></i>Approve</button>
            <button type="button" class="btn btn-danger" onclick="openRejectReturnModal(${r.id})"><i class="fas fa-times me-1"></i>Reject</button>`);
    } else {
        $('#returnDetailsFooter').html(`<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`);
    }
}

function selectReturnMethod(method) {
    const isPickup = method === 'pickup';
    document.getElementById('rm_pickup').checked = isPickup;
    document.getElementById('rm_dropoff').checked = !isPickup;
    const cardPickup = document.getElementById('card_pickup');
    const cardDropoff = document.getElementById('card_dropoff');
    const fieldsPickup = document.getElementById('fields_pickup');
    const fieldsDropoff = document.getElementById('fields_dropoff');
    if (isPickup) {
        cardPickup.style.cssText = 'cursor:pointer;border:2px solid #0d9488!important;background:#e1f5ee;';
        cardDropoff.style.cssText = 'cursor:pointer;border:0.5px solid #dee2e6;background:;';
        fieldsPickup.style.display = '';
        fieldsDropoff.style.display = 'none';
    } else {
        cardDropoff.style.cssText = 'cursor:pointer;border:2px solid #0d9488!important;background:#e1f5ee;';
        cardPickup.style.cssText = 'cursor:pointer;border:0.5px solid #dee2e6;background:;';
        fieldsDropoff.style.display = '';
        fieldsPickup.style.display = 'none';
    }
}

function openApproveReturnModal(returnId, returnNumber, poNumber, amount) {
    document.getElementById('approve_return_id').value = returnId;
    document.getElementById('approve_return_summary').innerHTML = `<strong>${returnNumber}</strong> &nbsp;·&nbsp; ${poNumber} &nbsp;·&nbsp; ₱${parseFloat(amount).toFixed(2)}`;
    selectReturnMethod('pickup');
    document.getElementById('pickup_date').value = new Date(Date.now() + 2*864e5).toISOString().split('T')[0];
    $('#approveReturnModal').modal('show');
}

function openRejectReturnModal(returnId) {
    document.getElementById('reject_return_id').value = returnId;
    document.getElementById('reject_reason').value = '';
    $('#rejectReturnModal').modal('show');
}

function submitApproveReturn() {
    const returnId = document.getElementById('approve_return_id').value;
    const method = document.querySelector('input[name="return_method"]:checked').value;
    let extraData = { return_method: method };

    if (method === 'Pickup') {
        const date = document.getElementById('pickup_date').value;
        const time = document.getElementById('pickup_time').value;
        const address = document.getElementById('pickup_address').value;
        const notes = document.getElementById('pickup_notes').value;
        if (!date) { Swal.fire('Required', 'Please set a pickup date.', 'warning'); return; }
        extraData = { ...extraData, pickup_date: date, pickup_time: time, pickup_address: address, notes: notes };
    } else {
        const address = document.getElementById('dropoff_address').value;
        const contact = document.getElementById('dropoff_contact').value;
        const phone = document.getElementById('dropoff_phone').value;
        const hours = document.getElementById('dropoff_hours').value;
        const notes = document.getElementById('dropoff_notes').value;
        if (!address || !contact || !phone) { Swal.fire('Required', 'Please fill in address, contact person, and number.', 'warning'); return; }
        extraData = { ...extraData, dropoff_address: address, dropoff_contact: contact, dropoff_phone: phone, dropoff_hours: hours, notes: notes };
    }

    Swal.fire({
        title: 'Confirm approval?',
        html: `Method: <strong>${method}</strong><br>${method === 'Pickup' ? `Date: <strong>${extraData.pickup_date}</strong>, ${extraData.pickup_time}` : `Address: <strong>${extraData.dropoff_address}</strong>`}`,
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0d9488', confirmButtonText: 'Yes, approve'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: '../api/supplier_returns.php', method: 'POST',
            data: { action: 'approve_return', return_id: returnId, ...extraData },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success', title: 'Return Approved!',
                        html: `Status updated to <strong>${method === 'Pickup' ? 'For Pickup' : 'For Drop-off'}</strong>.<br>Clinic has been notified.`,
                        timer: 2500
                    }).then(() => { $('#approveReturnModal').modal('hide'); loadReturns(); });
                } else {
                    Swal.fire('Error', response.error, 'error');
                }
            },
            error: function() { Swal.fire('Error', 'Network error. Please try again.', 'error'); }
        });
    });
}

function submitRejectReturn() {
    const returnId = document.getElementById('reject_return_id').value;
    const reason = document.getElementById('reject_reason').value.trim();
    if (!reason) { Swal.fire('Required', 'Please provide a reason for rejection.', 'warning'); return; }
    $.ajax({
        url: '../api/supplier_returns.php', method: 'POST',
        data: { action: 'reject_return', return_id: returnId, reason: reason },
        success: function(response) {
            if (response.success) {
                Swal.fire({ icon: 'info', title: 'Return Rejected', text: 'The clinic has been notified.', timer: 2000 }).then(() => {
                    $('#rejectReturnModal').modal('hide'); loadReturns();
                });
            } else {
                Swal.fire('Error', response.error, 'error');
            }
        }
    });
}

function switchReturnTab(tabName) {
    document.getElementById('pending').style.display = 'none';
    document.getElementById('approved').style.display = 'none';
    document.getElementById('completed').style.display = 'none';
    document.getElementById('rejected').style.display = 'none';
    document.getElementById('all').style.display = 'none';
    document.getElementById(tabName).style.display = 'block';

    const navLinks = document.querySelectorAll('#returnsTabs .nav-link');
    navLinks.forEach(link => link.classList.remove('active'));
    const activeLink = Array.from(navLinks).find(link => link.textContent.toLowerCase().includes(tabName));
    if (activeLink) activeLink.classList.add('active');
}

// ============================================
// INVOICES
// ============================================
let unpaidPage = 1, paidPage = 1, historyPage = 1;

function loadInvoiceStats() {
    $.ajax({
        url: '../api/supplier_invoices.php', method: 'GET',
        data: { action: 'get_stats', supplier_id: supplierId },
        success: function(response) {
            if (response.success) {
                $('#totalUnpaidInvoices').text(response.data.unpaid_count || 0);
                $('#unpaidBadge').text(`Unpaid: ${response.data.unpaid_count || 0}`);
                $('#paidBadge').text(`Paid: ${response.data.paid_count || 0}`);
                $('#overdueBadge').text(`Overdue: ${response.data.overdue_count || 0}`);
                $('#totalUnpaidAmount').text('₱' + parseFloat(response.data.unpaid_total || 0).toFixed(2));
                $('#totalPaidAmount').text('₱' + parseFloat(response.data.paid_total || 0).toFixed(2));
                $('#monthTotal').text('₱' + parseFloat(response.data.month_total || 0).toFixed(2));
                $('#onTimeRate').text((response.data.on_time_rate || 0) + '%');
                $('#unpaidCount').text(response.data.unpaid_count || 0);
                $('#paidCount').text(response.data.paid_count || 0);
            }
        }
    });
}

function loadUnpaidInvoices(page = 1) {
    unpaidPage = page;
    const filter = $('#unpaidFilter').val();
    const search = $('#unpaidSearch').val();
    $.ajax({
        url: '../api/supplier_invoices.php', method: 'GET',
        data: { action: 'get_invoices', supplier_id: supplierId, status: 'unpaid', filter: filter, search: search, page: page, limit: 10 },
        success: function(response) {
            if (response.success) {
                displayUnpaidInvoices(response.data);
                updatePagination('unpaid', response.pagination);
            } else {
                $('#unpaidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        }
    });
}

function displayUnpaidInvoices(invoices) {
    const tbody = $('#unpaidInvoicesTable');
    if (!invoices || invoices.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><br>No unpaid invoices</td></tr>');
        return;
    }
    let html = '';
    invoices.forEach(inv => {
        let statusBadge = '', statusClass = '';
        switch(inv.status) {
            case 'Pending': statusBadge = '<span class="badge bg-warning text-dark">Pending</span>'; break;
            case 'Approved': statusBadge = '<span class="badge bg-info">Approved</span>'; break;
            case 'Ready to Pay': statusBadge = '<span class="badge bg-primary">Ready to Pay</span>'; break;
            case 'Overdue': statusBadge = '<span class="badge bg-danger">Overdue</span>'; statusClass = 'table-danger'; break;
            default: statusBadge = '<span class="badge bg-secondary">' + inv.status + '</span>';
        }
        const dueDate = new Date(inv.due_date);
        const today = new Date();
        if (inv.status !== 'Paid' && dueDate < today) statusBadge = '<span class="badge bg-danger">Overdue</span>';

        html += `<tr class="${statusClass}">
            <td><strong>${inv.expense_code || inv.invoice_number || '-'}</strong></td>
            <td>${inv.po_number || '-'}</td>
            <td>${escapeHtml(inv.description || '')}</td>
            <td>${inv.expense_date ? new Date(inv.expense_date).toLocaleDateString() : '-'}</td>
            <td>${inv.due_date ? new Date(inv.due_date).toLocaleDateString() : '-'}</td>
            <td class="fw-bold text-danger">₱${parseFloat(inv.amount).toFixed(2)}</td>
            <td>${statusBadge}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewInvoiceDetails(${inv.id})" title="View Details"><i class="fas fa-eye"></i></button>
                <button class="btn btn-sm btn-success" onclick="markAsPaid(${inv.id})" title="Mark as Paid"><i class="fas fa-check-circle"></i></button>
                <button class="btn btn-sm btn-secondary" onclick="printInvoicePreview(${inv.id})" title="Print"><i class="fas fa-print"></i></button>
            </td>
        </tr>`;
    });
    tbody.html(html);
}

function loadPaidInvoices(page = 1) {
    paidPage = page;
    const filter = $('#paidFilter').val();
    const search = $('#paidSearch').val();
    $.ajax({
        url: '../api/supplier_invoices.php', method: 'GET',
        data: { action: 'get_invoices', supplier_id: supplierId, status: 'paid', filter: filter, search: search, page: page, limit: 10 },
        success: function(response) {
            if (response.success) {
                displayPaidInvoices(response.data);
                updatePagination('paid', response.pagination);
            } else {
                $('#paidInvoicesTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        }
    });
}

function displayPaidInvoices(invoices) {
    const tbody = $('#paidInvoicesTable');
    if (!invoices || invoices.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4"><i class="fas fa-receipt fa-2x mb-2"></i><br>No paid invoices</td></tr>');
        return;
    }
    let html = '';
    invoices.forEach(inv => {
        html += `<tr>
            <td><strong>${inv.expense_code || inv.invoice_number || '-'}</strong></td>
            <td>${inv.po_number || '-'}</td>
            <td>${escapeHtml(inv.description || '')}</td>
            <td>${inv.payment_date ? new Date(inv.payment_date).toLocaleDateString() : '-'}</td>
            <td class="fw-bold text-success">₱${parseFloat(inv.amount).toFixed(2)}</td>
            <td>${inv.payment_method || '-'}</td>
            <td>${inv.payment_reference || '-'}</td>
            <td>
                <button class="btn btn-sm btn-info" onclick="viewInvoiceDetails(${inv.id})" title="View"><i class="fas fa-eye"></i></button>
                <button class="btn btn-sm btn-secondary" onclick="printInvoicePreview(${inv.id})" title="Print"><i class="fas fa-print"></i></button>
                ${inv.receipt_path ? `<button class="btn btn-sm btn-primary" onclick="viewReceipt('${inv.receipt_path}')" title="View Receipt"><i class="fas fa-receipt"></i></button>` : ''}
            </td>
        </tr>`;
    });
    tbody.html(html);
}

function loadPaymentHistory(page = 1) {
    historyPage = page;
    const from = $('#historyFrom').val();
    const to = $('#historyTo').val();
    const search = $('#historySearch').val();
    $.ajax({
        url: '../api/supplier_invoices.php', method: 'GET',
        data: { action: 'get_payment_history', supplier_id: supplierId, from: from, to: to, search: search, page: page, limit: 15 },
        success: function(response) {
            if (response.success) {
                displayPaymentHistory(response.data);
                $('#historyTotal').text('₱' + parseFloat(response.total || 0).toFixed(2));
            } else {
                $('#paymentHistoryTable').html('<tr><td colspan="8" class="text-center text-danger">' + response.message + '</td></tr>');
            }
        }
    });
}

function displayPaymentHistory(payments) {
    const tbody = $('#paymentHistoryTable');
    if (!payments || payments.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4">No payment history found</td></tr>');
        return;
    }
    let html = '';
    payments.forEach(p => {
        html += `<tr>
            <td>${p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '-'}</td>
            <td><strong>${p.expense_code || p.invoice_number || '-'}</strong></td>
            <td>${p.po_number || '-'}</td>
            <td>${escapeHtml(p.description || '')}</td>
            <td class="fw-bold text-success">₱${parseFloat(p.amount).toFixed(2)}</td>
            <td>${p.payment_method || '-'}</td>
            <td>${p.payment_reference || '-'}</td>
            <td><span class="badge bg-success">Paid</span></td>
        </tr>`;
    });
    tbody.html(html);
}

function updatePagination(type, pagination) {
    const infoId = '#' + type + 'PaginationInfo';
    const paginationId = '#' + type + 'Pagination';
    if (!pagination || pagination.total_pages <= 1) {
        $(infoId).html(`Showing ${pagination?.total || 0} items`);
        $(paginationId).html('');
        return;
    }
    $(infoId).html(`Page ${pagination.page} of ${pagination.total_pages} (${pagination.total} items)`);
    let pages = '';
    for (let i = 1; i <= pagination.total_pages; i++) {
        const activeClass = i === pagination.page ? 'active' : '';
        pages += `<li class="page-item ${activeClass}"><a class="page-link" href="#" onclick="load${type.charAt(0).toUpperCase() + type.slice(1)}Invoices(${i}); return false;">${i}</a></li>`;
    }
    $(paginationId).html(pages);
}

function viewInvoiceDetails(invoiceId) {
    $('#invoiceDetailsContent').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#invoiceDetailsModal').modal('show');
    $.ajax({
        url: '../api/supplier_invoices.php', method: 'GET',
        data: { action: 'get_invoice_details', id: invoiceId },
        success: function(response) {
            if (response.success) displayInvoiceDetails(response.data);
            else $('#invoiceDetailsContent').html('<div class="alert alert-danger">Failed to load invoice details</div>');
        }
    });
}

function displayInvoiceDetails(inv) {
    const isPaid = inv.status === 'Paid';
    let itemsHtml = '';
    if (inv.items && inv.items.length > 0) {
        inv.items.forEach(item => {
            itemsHtml += `<tr>
                <td>${escapeHtml(item.item_name)}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td class="text-end">₱${parseFloat(item.total_price).toFixed(2)}</td>
            </tr>`;
        });
    }
    const statusClass = isPaid ? 'success' : (inv.status === 'Overdue' ? 'danger' : 'warning');
    const html = `<div class="row">
        <div class="col-md-7">
            <h6 class="text-primary">${inv.expense_code || inv.invoice_number || 'N/A'}</h6>
            <p><strong>PO Number:</strong> ${inv.po_number || 'N/A'}</p>
            <p><strong>PR Number:</strong> ${inv.pr_number || 'N/A'}</p>
            <p><strong>Description:</strong> ${escapeHtml(inv.description || '')}</p>
            <p><strong>Date:</strong> ${inv.expense_date ? new Date(inv.expense_date).toLocaleDateString() : 'N/A'}</p>
            <p><strong>Due Date:</strong> ${inv.due_date ? new Date(inv.due_date).toLocaleDateString() : 'N/A'}</p>
            <p><strong>Department:</strong> ${inv.department || 'N/A'}</p>
        </div>
        <div class="col-md-5">
            <div class="card"><div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                <h5><span class="badge bg-${statusClass}">${inv.status}</span></h5>
                <h6 class="card-subtitle mt-3 mb-2 text-muted">Total Amount</h6>
                <h3 class="text-${isPaid ? 'success' : 'danger'}">₱${parseFloat(inv.amount).toFixed(2)}</h3>
                ${isPaid ? `
                    <h6 class="card-subtitle mt-3 mb-2 text-muted">Payment Details</h6>
                    <p><strong>Date:</strong> ${inv.payment_date ? new Date(inv.payment_date).toLocaleDateString() : 'N/A'}</p>
                    <p><strong>Method:</strong> ${inv.payment_method || 'N/A'}</p>
                    <p><strong>Reference:</strong> ${inv.payment_reference || 'N/A'}</p>` : ''}
            </div></div>
        </div>
    </div>
    ${itemsHtml ? `
        <h6 class="mt-4">Items:</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>${itemsHtml}</tbody>
            </table>
        </div>` : ''}
    ${inv.notes ? `<div class="mt-3"><strong>Notes:</strong><p class="text-muted">${escapeHtml(inv.notes)}</p></div>` : ''}`;

    $('#invoiceDetailsContent').html(html);

    let footerHtml = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    if (!isPaid && inv.status !== 'Paid') {
        footerHtml += `<button type="button" class="btn btn-success" onclick="markAsPaid(${inv.id})"><i class="fas fa-check-circle me-1"></i>Mark as Paid</button>`;
    }
    footerHtml += `<button type="button" class="btn btn-primary" onclick="printInvoice(${inv.id})"><i class="fas fa-print me-1"></i>Print</button>`;
    $('#invoiceDetailsFooter').html(footerHtml);
}

function markAsPaid(invoiceId) {
    Swal.fire({
        title: 'Mark as Paid',
        html: `<div class="text-start">
            <div class="mb-3"><label class="form-label">Payment Date</label><input type="date" id="payment_date" class="form-control" value="${new Date().toISOString().split('T')[0]}"></div>
            <div class="mb-3"><label class="form-label">Payment Method</label><select id="payment_method" class="form-select">
                <option value="Bank Transfer">Bank Transfer</option><option value="Cash">Cash</option>
                <option value="Check">Check</option><option value="GCash">GCash</option><option value="PayMaya">PayMaya</option>
            </select></div>
            <div class="mb-3"><label class="form-label">Reference Number</label><input type="text" id="payment_reference" class="form-control" placeholder="e.g., Transaction ID"></div>
            <div class="mb-3"><label class="form-label">Notes</label><textarea id="payment_notes" class="form-control" rows="2"></textarea></div>
        </div>`,
        showCancelButton: true, confirmButtonText: 'Mark as Paid', confirmButtonColor: '#28a745',
        preConfirm: () => ({
            payment_date: $('#payment_date').val(),
            payment_method: $('#payment_method').val(),
            payment_reference: $('#payment_reference').val(),
            notes: $('#payment_notes').val()
        })
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '../api/supplier_invoices.php', method: 'POST',
                data: { action: 'mark_paid', invoice_id: invoiceId, ...result.value },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', 'Invoice marked as paid', 'success').then(() => {
                            $('#invoiceDetailsModal').modal('hide');
                            loadInvoiceStats();
                            if ($('#unpaid-tab').hasClass('active')) loadUnpaidInvoices();
                            else if ($('#paid-tab').hasClass('active')) loadPaidInvoices();
                            else loadPaymentHistory();
                        });
                    } else {
                        Swal.fire('Error', response.error, 'error');
                    }
                }
            });
        }
    });
}

function printInvoice(invoiceId) { window.open(`../api/supplier_invoices.php?action=print_invoice&id=${invoiceId}`, '_blank'); }
function printInvoicePreview(invoiceId) { printInvoice(invoiceId); }
function viewReceipt(path) { window.open('../' + path, '_blank'); }

function exportInvoices(type) {
    let url = `../api/supplier_invoices.php?action=export_invoices&supplier_id=${supplierId}&type=${type}`;
    if (type === 'unpaid') { url += '&filter=' + $('#unpaidFilter').val() + '&search=' + encodeURIComponent($('#unpaidSearch').val()); }
    else if (type === 'paid') { url += '&filter=' + $('#paidFilter').val() + '&search=' + encodeURIComponent($('#paidSearch').val()); }
    window.location.href = url;
}

function exportPaymentHistory() {
    const from = $('#historyFrom').val(), to = $('#historyTo').val(), search = $('#historySearch').val();
    window.location.href = `../api/supplier_invoices.php?action=export_history&supplier_id=${supplierId}&from=${from}&to=${to}&search=${encodeURIComponent(search)}`;
}

function switchInvoiceTab(tabName) {
    document.getElementById('unpaid').style.display = 'none';
    document.getElementById('paid').style.display = 'none';
    document.getElementById('history').style.display = 'none';
    document.getElementById('unpaid-tab').classList.remove('active');
    document.getElementById('paid-tab').classList.remove('active');
    document.getElementById('history-tab').classList.remove('active');
    document.getElementById(tabName).style.display = 'block';
    document.getElementById(tabName + '-tab').classList.add('active');
    if (tabName === 'unpaid') loadUnpaidInvoices();
    else if (tabName === 'paid') loadPaidInvoices();
    else if (tabName === 'history') loadPaymentHistory();
}

// ============================================
// RIDERS
// ============================================
let allRiders = [];
let currentRiderId = null;
let currentRiderStep = 1;

function loadRiders() {
    const status = document.getElementById('riderStatusFilter')?.value || 'all';
    const search = document.getElementById('riderSearch')?.value || '';
    const tbody = document.getElementById('ridersTableBody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4"><div class="spinner-border spinner-border-sm" style="color:#0d9488;"></div> Loading...</td></tr>`;

    fetch(`../api/supplier_riders.php?action=list&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">${data.error || 'Failed to load riders'}</td></tr>`;
                return;
            }
            allRiders = data.data || [];
            renderRiders(allRiders);
            const countBadge = document.getElementById('totalRidersCount');
            if (countBadge) countBadge.textContent = allRiders.length;
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">Network error</td></tr>`;
        });
}

function renderRiders(riders) {
    const tbody = document.getElementById('ridersTableBody');
    if (!tbody) return;
    if (!riders || riders.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-5">
            <i class="fas fa-motorcycle fa-3x text-muted mb-3" style="opacity:0.3;"></i>
            <div class="text-muted">No riders yet</div>
            <button class="btn btn-teal btn-sm mt-2" onclick="openAddRiderModal()"><i class="fas fa-plus-circle"></i> Add Your First Rider</button>
        </td></tr>`;
        return;
    }
    let html = '';
    riders.forEach(r => {
        const photo = r.photo_path
            ? `<img src="../${escapeHtml(r.photo_path)}" style="width:44px;height:44px;object-fit:cover;border-radius:50%;border:2px solid #e5e7eb;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"><div style="display:none;width:44px;height:44px;background:#f3f4f6;border-radius:50%;align-items:center;justify-content:center;color:#9ca3af;"><i class="fas fa-user"></i></div>`
            : `<div style="width:44px;height:44px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#9ca3af;"><i class="fas fa-user"></i></div>`;

        const statusColors = { 'Active': 'success', 'Inactive': 'secondary', 'Suspended': 'danger', 'On Leave': 'warning' };
        const statusBadge = `<span class="badge bg-${statusColors[r.status] || 'secondary'}">${escapeHtml(r.status)}</span>`;
        const verifColors = { 'Verified': 'success', 'Pending': 'warning', 'Rejected': 'danger', 'Expired': 'secondary' };
        const verifBadge = `<span class="badge bg-${verifColors[r.verification_status] || 'secondary'}"><i class="fas fa-shield-alt"></i> ${escapeHtml(r.verification_status)}</span>`;
        const vehicle = r.vehicle_type
            ? `${escapeHtml(r.vehicle_type)}${r.plate_number ? '<br><small class="text-muted">' + escapeHtml(r.plate_number) + '</small>' : ''}`
            : '<span class="text-muted">—</span>';
        const activeDeliveries = r.active_deliveries > 0
            ? `<span class="badge bg-warning text-dark ms-1" title="Active deliveries">${r.active_deliveries} active</span>` : '';

        html += `<tr>
            <td>${photo}</td>
            <td><div class="fw-bold">${escapeHtml(r.full_name)}</div><small class="text-muted">ID: #${r.id}</small> ${activeDeliveries}</td>
            <td><div><i class="fas fa-phone text-muted me-1"></i>${escapeHtml(r.phone)}</div>${r.email ? `<small class="text-muted"><i class="fas fa-envelope me-1"></i>${escapeHtml(r.email)}</small>` : ''}</td>
            <td>${vehicle}</td>
            <td>${statusBadge}</td>
            <td>${verifBadge}</td>
            <td class="text-center"><div class="fw-bold">${r.total_deliveries}</div><small class="text-muted">completed</small></td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-info" onclick="viewRider(${r.id})" title="View"><i class="fas fa-eye"></i></button>
                    <button class="btn btn-outline-primary" onclick="editRider(${r.id})" title="Edit"><i class="fas fa-edit"></i></button>
                    <button class="btn btn-outline-warning" onclick="openScheduleModal(${r.id})" title="Schedule"><i class="fas fa-calendar-alt"></i></button>
                    <button class="btn btn-outline-secondary" onclick="openResetPinModal(${r.id})" title="Reset PIN"><i class="fas fa-key"></i></button>
                    ${r.verification_status === 'Pending' ? `<button class="btn btn-success" onclick="openVerifyModal(${r.id})" title="Verify"><i class="fas fa-check"></i></button>` : ''}
                    <button class="btn btn-outline-danger" onclick="deleteRider(${r.id})" title="Deactivate"><i class="fas fa-ban"></i></button>
                </div>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function loadRiderStats() {
    fetch('../api/supplier_riders.php?action=stats')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                const d = data.data;
                document.getElementById('riderStatTotal').textContent = d.total_riders || 0;
                document.getElementById('riderStatActive').textContent = d.active_riders || 0;
                document.getElementById('riderStatVerified').textContent = d.verified_riders || 0;
                document.getElementById('riderStatPending').textContent = d.pending_verification || 0;
            }
        });
}

function clearRiderFilters() {
    const filterEl = document.getElementById('riderStatusFilter');
    const searchEl = document.getElementById('riderSearch');
    if (filterEl) filterEl.value = 'all';
    if (searchEl) searchEl.value = '';
    loadRiders();
}

// ============================================
// ADD RIDER WIZARD
// ============================================
function openAddRiderModal() {
    const form = document.getElementById('addRiderForm');
    if (form) form.reset();
    currentRiderStep = 1;
    updateAddRiderStep();
    const pinMsg = document.getElementById('pinMatchMsg');
    if (pinMsg) { pinMsg.textContent = ''; pinMsg.className = 'text-muted'; }
    new bootstrap.Modal(document.getElementById('addRiderModal')).show();
}

function validateAddRiderStep(step) {
    const stepEl = document.querySelector(`.rider-step-content[data-step="${step}"]`);
    if (!stepEl) return true;
    const requiredFields = stepEl.querySelectorAll('[required]');
    let valid = true;
    for (const field of requiredFields) {
        if (!field.value || !field.checkValidity()) { field.reportValidity(); valid = false; break; }
    }
    if (valid && step === 5) {
        const pin = document.getElementById('addRiderPin').value;
        const pinConfirm = document.getElementById('addRiderPinConfirm').value;
        if (pin.length !== 6 || !/^\d{6}$/.test(pin)) {
            Swal.fire('Invalid PIN', 'PIN must be exactly 6 digits (0-9)', 'warning'); valid = false;
        } else if (pin !== pinConfirm) {
            Swal.fire('PIN Mismatch', 'Hindi tugma ang PIN at Confirm PIN', 'warning'); valid = false;
        }
    }
    return valid;
}

function addRiderNextStep() {
    if (!validateAddRiderStep(currentRiderStep)) return;
    if (currentRiderStep < 5) { currentRiderStep++; updateAddRiderStep(); }
}

function addRiderPrevStep() {
    if (currentRiderStep > 1) { currentRiderStep--; updateAddRiderStep(); }
}

function updateAddRiderStep() {
    document.querySelectorAll('.rider-wizard-step').forEach(el => {
        const step = parseInt(el.dataset.step);
        el.classList.remove('active', 'done');
        if (step === currentRiderStep) el.classList.add('active');
        else if (step < currentRiderStep) el.classList.add('done');
    });
    document.querySelectorAll('.rider-step-content').forEach(el => {
        const step = parseInt(el.dataset.step);
        el.style.display = (step === currentRiderStep) ? 'block' : 'none';
    });
    const prevBtn = document.getElementById('addRiderPrevBtn');
    const nextBtn = document.getElementById('addRiderNextBtn');
    const submitBtn = document.getElementById('addRiderSubmitBtn');
    if (prevBtn) prevBtn.style.display = (currentRiderStep > 1) ? 'inline-block' : 'none';
    if (nextBtn) nextBtn.style.display = (currentRiderStep < 5) ? 'inline-block' : 'none';
    if (submitBtn) submitBtn.style.display = (currentRiderStep === 5) ? 'inline-block' : 'none';
    const modalBody = document.querySelector('#addRiderModal .modal-body');
    if (modalBody) modalBody.scrollTop = 0;
}

function viewRider(id) {
    const modalEl = document.getElementById('viewRiderModal');
    const body = document.getElementById('viewRiderBody');
    body.innerHTML = `<div class="text-center py-4"><div class="spinner-border text-teal"></div><div class="text-muted mt-2">Loading rider details...</div></div>`;
    const editBtn = document.getElementById('viewRiderEditBtn');
    if (editBtn) {
        editBtn.onclick = function() {
            bootstrap.Modal.getInstance(modalEl).hide();
            setTimeout(() => editRider(id), 300);
        };
    }
    new bootstrap.Modal(modalEl).show();
    fetch(`../api/supplier_riders.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { body.innerHTML = `<div class="alert alert-danger">${data.error || 'Failed to load rider'}</div>`; return; }
            renderRiderDetails(data.data);
        })
        .catch(err => { body.innerHTML = `<div class="alert alert-danger">Network error: ${err.message}</div>`; });
}

function renderRiderDetails(r) {
    const body = document.getElementById('viewRiderBody');
    const photo = r.photo_path
        ? `<img src="../${escapeHtml(r.photo_path)}" style="width:100px;height:100px;object-fit:cover;border-radius:50%;border:3px solid #e5e7eb;" onerror="this.src='https://via.placeholder.com/100?text=No+Photo';">`
        : `<div style="width:100px;height:100px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:2.5rem;margin:0 auto;"><i class="fas fa-user"></i></div>`;
    const statusColors = { 'Active': 'success', 'Inactive': 'secondary', 'Suspended': 'danger', 'On Leave': 'warning' };
    const verifColors = { 'Verified': 'success', 'Pending': 'warning', 'Rejected': 'danger', 'Expired': 'secondary' };
    const docLink = (path, label, icon = 'fa-file') => {
        if (!path) return `<span class="text-muted small">— Not uploaded</span>`;
        const isPdf = path.toLowerCase().endsWith('.pdf');
        return `<a href="../${escapeHtml(path)}" target="_blank" class="d-inline-flex align-items-center gap-1 small text-decoration-none"><i class="fas ${isPdf ? 'fa-file-pdf text-danger' : icon + ' text-primary'}"></i><span>${escapeHtml(label)}</span></a>`;
    };
    const dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    let scheduleHtml = '';
    if (r.schedule && r.schedule.length > 0) {
        r.schedule.forEach(s => {
            const isActive = s.is_active == 1;
            scheduleHtml += `<tr class="${!isActive ? 'table-secondary' : ''}">
                <td>${dayNames[s.day_of_week] || 'Day ' + s.day_of_week}</td>
                <td>${isActive ? '✅ ' + s.time_in.substring(0,5) + ' – ' + s.time_out.substring(0,5) : '<span class="text-muted">Day Off</span>'}</td>
                <td>${isActive && s.break_start ? s.break_start.substring(0,5) + ' – ' + s.break_end.substring(0,5) : '—'}</td>
            </tr>`;
        });
    } else {
        scheduleHtml = '<tr><td colspan="3" class="text-muted text-center">No schedule set</td></tr>';
    }
    let recentHtml = '';
    if (r.recent_deliveries && r.recent_deliveries.length > 0) {
        r.recent_deliveries.forEach(d => {
            recentHtml += `<tr><td><strong>${escapeHtml(d.po_number)}</strong></td><td><span class="badge bg-secondary">${escapeHtml(d.status)}</span></td><td class="text-end">₱${parseFloat(d.total_amount || 0).toFixed(2)}</td></tr>`;
        });
    } else {
        recentHtml = '<tr><td colspan="3" class="text-muted text-center">No deliveries yet</td></tr>';
    }

    body.innerHTML = `<div class="row align-items-center mb-4">
        <div class="col-md-3 text-center">${photo}</div>
        <div class="col-md-9">
            <h4 class="mb-1">${escapeHtml(r.full_name)}</h4>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="badge bg-${statusColors[r.status] || 'secondary'}">${escapeHtml(r.status)}</span>
                <span class="badge bg-${verifColors[r.verification_status] || 'secondary'}"><i class="fas fa-shield-alt me-1"></i>${escapeHtml(r.verification_status)}</span>
                <span class="badge bg-info"><i class="fas fa-id-card me-1"></i>ID #${r.id}</span>
            </div>
            <div class="row small">
                <div class="col-md-6 mb-1"><i class="fas fa-phone text-muted me-1"></i>${escapeHtml(r.phone)}</div>
                ${r.email ? `<div class="col-md-6 mb-1"><i class="fas fa-envelope text-muted me-1"></i>${escapeHtml(r.email)}</div>` : ''}
                ${r.address ? `<div class="col-12 mb-1"><i class="fas fa-map-marker-alt text-muted me-1"></i>${escapeHtml(r.address)}</div>` : ''}
            </div>
        </div>
    </div>
    <div class="row text-center mb-4">
        <div class="col-4"><div class="p-3 bg-light rounded"><div class="h4 mb-0 text-primary">${r.total_deliveries || 0}</div><small class="text-muted">Total Deliveries</small></div></div>
        <div class="col-4"><div class="p-3 bg-light rounded"><div class="h4 mb-0 text-warning">${parseFloat(r.rating || 0).toFixed(1)} ⭐</div><small class="text-muted">Rating</small></div></div>
        <div class="col-4"><div class="p-3 bg-light rounded"><div class="h4 mb-0 text-info">${r.date_hired ? new Date(r.date_hired).toLocaleDateString('en-US', {month:'short', year:'numeric'}) : 'N/A'}</div><small class="text-muted">Date Hired</small></div></div>
    </div>
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-personal" type="button"><i class="fas fa-user me-1"></i>Personal</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-vehicle" type="button"><i class="fas fa-motorcycle me-1"></i>Vehicle</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button"><i class="fas fa-file-alt me-1"></i>Documents</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-schedule" type="button"><i class="fas fa-calendar-alt me-1"></i>Schedule</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history" type="button"><i class="fas fa-history me-1"></i>History</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-personal">
            <table class="table table-sm">
                <tr><th width="35%">Full Name:</th><td>${escapeHtml(r.full_name)}</td></tr>
                <tr><th>Phone:</th><td>${escapeHtml(r.phone)}</td></tr>
                <tr><th>Email:</th><td>${escapeHtml(r.email || '—')}</td></tr>
                <tr><th>Birthdate:</th><td>${r.birthdate ? new Date(r.birthdate).toLocaleDateString() : '—'}</td></tr>
                <tr><th>Address:</th><td>${escapeHtml(r.address || '—')}</td></tr>
                <tr><th>Blood Type:</th><td>${escapeHtml(r.blood_type || '—')}</td></tr>
                <tr><th>Allergies:</th><td>${escapeHtml(r.allergies || '—')}</td></tr>
            </table>
            <h6 class="text-teal border-bottom pb-1 mb-2 mt-3">Emergency Contact</h6>
            <table class="table table-sm">
                <tr><th width="35%">Name:</th><td>${escapeHtml(r.emergency_name || '—')}</td></tr>
                <tr><th>Relationship:</th><td>${escapeHtml(r.emergency_relationship || '—')}</td></tr>
                <tr><th>Phone:</th><td>${escapeHtml(r.emergency_phone || '—')}</td></tr>
            </table>
        </div>
        <div class="tab-pane fade" id="tab-vehicle">
            <table class="table table-sm">
                <tr><th width="35%">Vehicle Type:</th><td>${escapeHtml(r.vehicle_type || '—')}</td></tr>
                <tr><th>Plate Number:</th><td><strong>${escapeHtml(r.plate_number || '—')}</strong></td></tr>
                <tr><th>Make:</th><td>${escapeHtml(r.vehicle_make || '—')}</td></tr>
                <tr><th>Model:</th><td>${escapeHtml(r.vehicle_model || '—')}</td></tr>
                <tr><th>Color:</th><td>${escapeHtml(r.vehicle_color || '—')}</td></tr>
            </table>
            <div class="mt-2"><strong>Vehicle Photo:</strong><br>
                ${r.vehicle_photo_path ? `<img src="../${escapeHtml(r.vehicle_photo_path)}" style="max-width:100%;max-height:250px;border-radius:8px;margin-top:8px;border:1px solid #e5e7eb;">` : '<div class="text-muted small">— Not uploaded</div>'}
            </div>
        </div>
        <div class="tab-pane fade" id="tab-docs">
            <h6 class="text-teal border-bottom pb-1 mb-2">Government ID</h6>
            <table class="table table-sm">
                <tr><th width="35%">ID Type:</th><td>${escapeHtml(r.id_type || '—')}</td></tr>
                <tr><th>ID Number:</th><td>${escapeHtml(r.id_number || '—')}</td></tr>
                <tr><th>Front Photo:</th><td>${docLink(r.id_photo_path, 'View Front', 'fa-id-card')}</td></tr>
                <tr><th>Back Photo:</th><td>${docLink(r.id_back_photo_path, 'View Back', 'fa-id-card')}</td></tr>
            </table>
            <h6 class="text-teal border-bottom pb-1 mb-2 mt-3">Driver's License</h6>
            <table class="table table-sm">
                <tr><th width="35%">License Number:</th><td>${escapeHtml(r.license_number || '—')}</td></tr>
                <tr><th>Expiry:</th><td>${r.license_expiry ? new Date(r.license_expiry).toLocaleDateString() : '—'}</td></tr>
                <tr><th>Restrictions:</th><td>${escapeHtml(r.restriction_codes || '—')}</td></tr>
                <tr><th>Photo:</th><td>${docLink(r.license_photo_path, 'View License', 'fa-id-card')}</td></tr>
            </table>
            <h6 class="text-teal border-bottom pb-1 mb-2 mt-3">Clearances</h6>
            <table class="table table-sm">
                <tr><th width="35%">NBI Clearance:</th><td>${docLink(r.nbi_clearance_path, 'View NBI', 'fa-file-shield')}${r.nbi_expiry ? `<br><small class="text-muted">Expires: ${new Date(r.nbi_expiry).toLocaleDateString()}</small>` : ''}</td></tr>
                <tr><th>Police Clearance:</th><td>${docLink(r.police_clearance_path, 'View Police', 'fa-file-shield')}${r.police_expiry ? `<br><small class="text-muted">Expires: ${new Date(r.police_expiry).toLocaleDateString()}</small>` : ''}</td></tr>
                <tr><th>Barangay Clearance:</th><td>${docLink(r.barangay_clearance_path, 'View Barangay', 'fa-file-shield')}</td></tr>
                <tr><th>Medical Certificate:</th><td>${docLink(r.medical_cert_path, 'View Medical', 'fa-file-medical')}</td></tr>
                <tr><th>OR/CR:</th><td>${docLink(r.or_cr_path, 'View OR/CR', 'fa-file-alt')}</td></tr>
                <tr><th>Vehicle Insurance:</th><td>${docLink(r.vehicle_insurance_path, 'View Insurance', 'fa-file-contract')}</td></tr>
            </table>
        </div>
        <div class="tab-pane fade" id="tab-schedule">
            <table class="table table-sm">
                <thead class="table-light"><tr><th>Day</th><th>Shift Hours</th><th>Break</th></tr></thead>
                <tbody>${scheduleHtml}</tbody>
            </table>
        </div>
        <div class="tab-pane fade" id="tab-history">
            <table class="table table-sm">
                <thead class="table-light"><tr><th>PO Number</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                <tbody>${recentHtml}</tbody>
            </table>
        </div>
    </div>`;
}

function editRider(id) {
    const modalEl = document.getElementById('editRiderModal');
    const form = document.getElementById('editRiderForm');
    form.reset();
    new bootstrap.Modal(modalEl).show();
    fetch(`../api/supplier_riders.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { Swal.fire('Error', data.error || 'Failed to load rider', 'error'); bootstrap.Modal.getInstance(modalEl).hide(); return; }
            const r = data.data;
            document.getElementById('edit_rider_id').value = r.id;
            document.getElementById('edit_rider_name').value = r.full_name || '';
            document.getElementById('edit_rider_phone').value = r.phone || '';
            document.getElementById('edit_rider_email').value = r.email || '';
            document.getElementById('edit_rider_birthdate').value = r.birthdate || '';
            document.getElementById('edit_rider_address').value = r.address || '';
            document.getElementById('edit_rider_status').value = r.status || 'Active';
            document.getElementById('edit_rider_date_hired').value = r.date_hired || '';
            document.getElementById('edit_rider_vehicle_type').value = r.vehicle_type || '';
            document.getElementById('edit_rider_plate').value = r.plate_number || '';
            document.getElementById('edit_rider_notes').value = r.notes || '';
        })
        .catch(err => { Swal.fire('Error', 'Network error', 'error'); bootstrap.Modal.getInstance(modalEl).hide(); });
}

function openVerifyModal(id) {
    const modalEl = document.getElementById('verifyRiderModal');
    document.getElementById('verify_rider_id').value = id;
    document.getElementById('verify_approve').checked = true;
    document.getElementById('verify_reject_reason').value = '';
    document.getElementById('rejectReasonWrap').style.display = 'none';
    const summaryEl = document.getElementById('verifyRiderSummary');
    summaryEl.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm"></div></div>';
    fetch(`../api/supplier_riders.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const r = data.data;
                summaryEl.innerHTML = `<div class="border rounded p-3 bg-light">
                    <div class="d-flex align-items-center gap-3">
                        ${r.photo_path ? `<img src="../${escapeHtml(r.photo_path)}" style="width:50px;height:50px;object-fit:cover;border-radius:50%;">` : `<div style="width:50px;height:50px;background:#e5e7eb;border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user text-muted"></i></div>`}
                        <div>
                            <div class="fw-bold">${escapeHtml(r.full_name)}</div>
                            <div class="small text-muted">${escapeHtml(r.phone)}</div>
                            <div class="small">
                                ${r.nbi_clearance_path ? '<span class="badge bg-success">✓ NBI</span>' : '<span class="badge bg-danger">✗ NBI</span>'}
                                ${r.police_clearance_path ? '<span class="badge bg-success">✓ Police</span>' : '<span class="badge bg-danger">✗ Police</span>'}
                                ${r.license_photo_path ? '<span class="badge bg-success">✓ License</span>' : '<span class="badge bg-warning">✗ License</span>'}
                            </div>
                        </div>
                    </div>
                </div>`;
            }
        });
    new bootstrap.Modal(modalEl).show();
}

function submitVerifyRider() {
    const riderId = document.getElementById('verify_rider_id').value;
    const decision = document.querySelector('input[name="verify_decision"]:checked').value;
    const reason = document.getElementById('verify_reject_reason').value.trim();
    if (decision === 'reject' && !reason) { Swal.fire('Required', 'Kailangan ng reason para sa rejection', 'warning'); return; }
    Swal.fire({
        title: decision === 'approve' ? 'Approve Rider?' : 'Reject Rider?',
        text: decision === 'approve' ? 'Maaari nang makatanggap ng deliveries ang rider na ito.' : 'Hindi makakatanggap ng deliveries ang rider.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: decision === 'approve' ? '#10b981' : '#dc3545',
        confirmButtonText: decision === 'approve' ? 'Yes, Approve' : 'Yes, Reject'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'verify');
        formData.append('rider_id', riderId);
        formData.append('decision', decision);
        formData.append('reason', reason);
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('../api/supplier_riders.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Done!', text: data.message, timer: 1500, showConfirmButton: false }).then(() => {
                    bootstrap.Modal.getInstance(document.getElementById('verifyRiderModal')).hide();
                    loadRiders(); loadRiderStats();
                });
            } else { Swal.fire('Error', data.error, 'error'); }
        })
        .catch(err => { Swal.fire('Error', 'Network error: ' + err.message, 'error'); });
    });
}

const DAY_NAMES = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function openScheduleModal(id) {
    const modalEl = document.getElementById('scheduleModal');
    document.getElementById('schedule_rider_id').value = id;
    fetch(`../api/supplier_riders.php?action=get_schedule&rider_id=${id}`)
        .then(r => r.json())
        .then(data => { if (data.success) renderScheduleTable(data.data); else Swal.fire('Error', 'Failed to load schedule', 'error'); });
    fetch(`../api/supplier_riders.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => { if (data.success) document.getElementById('schedule_rider_name').textContent = data.data.full_name; });
    new bootstrap.Modal(modalEl).show();
}

function renderScheduleTable(schedule) {
    const tbody = document.getElementById('scheduleTableBody');
    let html = '';
    for (let day = 1; day <= 7; day++) {
        const existing = schedule.find(s => parseInt(s.day_of_week) === day) || {
            day_of_week: day, time_in: '08:00:00', time_out: '17:00:00',
            break_start: '12:00:00', break_end: '13:00:00', is_active: day <= 5 ? 1 : 0
        };
        const isActive = existing.is_active == 1;
        const timeIn = (existing.time_in || '08:00:00').substring(0, 5);
        const timeOut = (existing.time_out || '17:00:00').substring(0, 5);
        const breakStart = existing.break_start ? existing.break_start.substring(0, 5) : '';
        const breakEnd = existing.break_end ? existing.break_end.substring(0, 5) : '';
        html += `<tr data-day="${day}">
            <td><strong>${DAY_NAMES[day]}</strong></td>
            <td class="text-center"><div class="form-check form-switch d-inline-block"><input class="form-check-input day-active-toggle" type="checkbox" data-day="${day}" ${isActive ? 'checked' : ''} onchange="toggleDayInputs(${day})"></div></td>
            <td><input type="time" class="form-control form-control-sm day-time-in" data-day="${day}" value="${timeIn}" ${!isActive ? 'disabled' : ''}></td>
            <td><input type="time" class="form-control form-control-sm day-time-out" data-day="${day}" value="${timeOut}" ${!isActive ? 'disabled' : ''}></td>
            <td><input type="time" class="form-control form-control-sm day-break-start" data-day="${day}" value="${breakStart}" ${!isActive ? 'disabled' : ''}></td>
            <td><input type="time" class="form-control form-control-sm day-break-end" data-day="${day}" value="${breakEnd}" ${!isActive ? 'disabled' : ''}></td>
        </tr>`;
    }
    tbody.innerHTML = html;
}

function toggleDayInputs(day) {
    const toggle = document.querySelector(`.day-active-toggle[data-day="${day}"]`);
    const isChecked = toggle.checked;
    document.querySelectorAll(`input[data-day="${day}"]`).forEach(input => {
        if (!input.classList.contains('day-active-toggle')) input.disabled = !isChecked;
    });
}

function applyWeekdayPreset() {
    for (let day = 1; day <= 7; day++) {
        const toggle = document.querySelector(`.day-active-toggle[data-day="${day}"]`);
        toggle.checked = day <= 5;
        toggleDayInputs(day);
    }
}

function applyAllDaysPreset() {
    for (let day = 1; day <= 7; day++) {
        const toggle = document.querySelector(`.day-active-toggle[data-day="${day}"]`);
        toggle.checked = true;
        toggleDayInputs(day);
    }
}

function openResetPinModal(id) {
    const modalEl = document.getElementById('resetPinModal');
    document.getElementById('reset_rider_id').value = id;
    document.getElementById('reset_new_pin').value = '';
    document.getElementById('reset_confirm_pin').value = '';
    document.getElementById('resetPinMatchMsg').textContent = '';
    fetch(`../api/supplier_riders.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => { if (data.success) document.getElementById('reset_rider_name').value = data.data.full_name; });
    new bootstrap.Modal(modalEl).show();
}

function deleteRider(id) {
    Swal.fire({
        title: 'Deactivate Rider?',
        html: `Ang rider ay hindi na makakatanggap ng bagong deliveries.<br><small class="text-muted">Hindi nito ide-delete ang rider, inactive lang.</small>`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Deactivate'
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('rider_id', id);
        Swal.fire({ title: 'Deactivating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('../api/supplier_riders.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Deactivated', text: data.message, timer: 1500, showConfirmButton: false }).then(() => { loadRiders(); loadRiderStats(); });
            } else { Swal.fire('Error', data.error, 'error'); }
        })
        .catch(err => { Swal.fire('Error', 'Network error: ' + err.message, 'error'); });
    });
}

// ============================================
// CLINIC CHAT
// ============================================
const CLINIC_CHAT_API = '../api/support_chat_supplier.php';
let activeClinicChatId = null;
let clinicChatInterval = null;
let selectedClinicImage = null;

function loadClinicsForChat() {
    const container = document.getElementById('clinicListContainer');
    container.innerHTML = '<div class="text-center py-4 text-muted small"><div class="spinner-border spinner-border-sm text-teal"></div> Loading...</div>';
    
    fetch(CLINIC_CHAT_API + '?action=get_clinics&supplier_id=' + supplierId)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted small">No active clinics found.</div>';
                return;
            }
            
            let html = '';
            data.forEach(c => {
                const initial = c.name.charAt(0).toUpperCase();
                html += `
                    <div class="chat-list-item" onclick="openClinicChat(${c.id}, '${c.name.replace(/'/g, "\\'")}')">
                        <div class="chat-avatar">${initial}</div>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="fw-bold text-truncate" style="font-size: 0.9rem;">${escapeHtml(c.name)}</div>
                            <div class="text-muted small text-truncate">Click to chat</div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = '<div class="text-center py-4 text-danger small">Network error.</div>';
        });
}

function filterClinics(query) {
    const items = document.querySelectorAll('.chat-list-item');
    const q = query.toLowerCase();
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(q) ? '' : 'none';
    });
}

function loadClinicChatMessages() {
    if (!activeClinicChatId) return;
    
    fetch(CLINIC_CHAT_API + '?action=get_clinic_messages&supplier_id=' + supplierId + '&clinic_id=' + activeClinicChatId)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('clinicMessagesArea');
            
            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-comment-slash fs-1 mb-3 opacity-25"></i><p class="small">No messages yet. Say hi! 👋</p></div>';
                return;
            }
            
            let html = '';
            let lastDate = '';
            
            data.forEach(m => {
                const isMine = m.is_mine;
                const bubbleClass = isMine ? 'msg-outgoing' : 'msg-incoming';
                const align = isMine ? 'justify-content-end' : 'justify-content-start';
                
                let imgHtml = '';
                if (m.image) {
                    imgHtml = `<img src="${m.image}" class="img-fluid rounded mt-1 mb-1" style="max-height: 200px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                }
                
                let msgDate = m.date || m.time || '';
                if (msgDate && msgDate !== lastDate) {
                    html += `<div class="text-center my-3"><span class="badge bg-light text-secondary border px-3 py-1 rounded-pill" style="font-size: 0.65rem;">${msgDate}</span></div>`;
                    lastDate = msgDate;
                }
                
                html += `
                    <div class="d-flex ${align} mb-2">
                        <div class="msg-bubble ${bubbleClass}">
                            ${!isMine ? `<div class="fw-bold mb-1" style="font-size:0.7rem; color:var(--teal-600);">${m.sender_name}</div>` : ''}
                            <div>${m.message}</div>
                            ${imgHtml}
                            <div class="msg-time text-end">${m.time}</div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        });
}

function sendClinicMessage(e) {
    e.preventDefault();
    if (!activeClinicChatId) return;
    
    const msgInput = document.getElementById('clinicMsgInput');
    const message = msgInput.value.trim();
    if (!message && !selectedClinicImage) return;
    
    msgInput.value = '';
    
    const fd = new FormData();
    fd.append('supplier_id', supplierId);
    fd.append('clinic_id', activeClinicChatId);
    fd.append('message', message);
    if (selectedClinicImage) fd.append('image', selectedClinicImage);
    
    fetch(CLINIC_CHAT_API + '?action=send_clinic_message', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                removeClinicImage();
                loadClinicChatMessages();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

function handleClinicImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Max 5MB lang po.'); return; }
    selectedClinicImage = file;

    const reader = new FileReader();
    reader.onload = function(evt) {
        const thumb   = document.getElementById('clinicImgThumb');
        const preview = document.getElementById('clinicImgPreview');
        if (thumb)   thumb.src = evt.target.result;
        if (preview) preview.classList.remove('d-none');
    };
    reader.readAsDataURL(file);
}

function removeClinicImage() {
    selectedClinicImage = null;
    const input   = document.getElementById('clinicImgInput');
    const preview = document.getElementById('clinicImgPreview');
    if (input)   input.value = '';
    if (preview) preview.classList.add('d-none');
}

function checkClinicChatUnread() {
    if (typeof supplierId === 'undefined') return;
    
    fetch(CLINIC_CHAT_API + '?action=get_clinic_unread_count&supplier_id=' + supplierId)
        .then(res => res.json())
        .then(data => {
            const badge = document.getElementById('sidebarChatBadge');
            if (data.success && data.unread > 0) {
                badge.textContent = data.unread;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        })
        .catch(err => console.log(err));
}

function openClinicChat(clinicId, clinicName) {
    activeClinicChatId = clinicId;

    const activeIdEl = document.getElementById('activeClinicId');
    if (activeIdEl) activeIdEl.value = clinicId;

    document.querySelectorAll('#clinicListContainer .chat-list-item').forEach(el => el.classList.remove('active'));
    if (typeof event !== 'undefined' && event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }

    const emptyState = document.getElementById('chatEmptyState');
    const header     = document.getElementById('clinicChatHeader');
    const msgArea    = document.getElementById('clinicMessagesArea');
    const inputArea  = document.getElementById('clinicChatInputArea');

    if (emptyState) emptyState.classList.add('d-none');
    if (header)     header.classList.remove('d-none');
    if (msgArea)    msgArea.classList.remove('d-none');
    if (inputArea)  inputArea.classList.remove('d-none');

    const nameEl   = document.getElementById('chatClinicName');
    const avatarEl = document.getElementById('chatClinicAvatar');
    if (nameEl)   nameEl.textContent   = clinicName;
    if (avatarEl) avatarEl.textContent = (clinicName || '?').charAt(0).toUpperCase();

    loadClinicChatMessages();

    if (clinicChatInterval) clearInterval(clinicChatInterval);
    clinicChatInterval = setInterval(loadClinicChatMessages, 5000);
}

// ============================================
// RIDER CHAT
// ============================================
const RIDER_CHAT_API = '../api/rider_chat.php';
let activeRiderChatId = null;
let riderChatInterval = null;
let selectedRiderImage = null;

function loadRidersForChat() {
    const container = document.getElementById('riderChatList');
    container.innerHTML = '<div class="text-center py-4 text-muted small"><div class="spinner-border spinner-border-sm text-teal"></div> Loading...</div>';
    
    fetch('../api/supplier_rider_chat.php?action=get_riders&supplier_id=' + supplierId)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="text-center py-4 text-muted small">No active riders found.</div>';
                return;
            }
            
            let html = '';
            data.forEach(r => {
                const initial = (r.name || '?').charAt(0).toUpperCase();
                const unreadBadge = r.unread > 0 
                    ? `<span class="badge bg-danger rounded-pill ms-2" style="font-size:10px;">${r.unread}</span>` 
                    : '';
                const lastMsg = r.last_message || 'No messages yet';
                
                html += `
                    <div class="chat-list-item" data-name="${escapeHtml(r.name).toLowerCase()}" onclick="openRiderChat(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}', '${escapeHtml(r.phone || '').replace(/'/g, "\\'")}')">
                        <div class="chat-avatar">${initial}</div>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="d-flex align-items-center">
                                <div class="fw-bold text-truncate" style="font-size: 0.9rem;">${escapeHtml(r.name)}</div>
                                ${unreadBadge}
                            </div>
                            <div class="text-muted small text-truncate">${lastMsg}</div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = '<div class="text-center py-4 text-danger small">Network error.</div>';
        });
}

function filterRiderChatList(query) {
    const items = document.querySelectorAll('#riderChatList .chat-list-item');
    const q = query.toLowerCase();
    items.forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(q) ? '' : 'none';
    });
}

function openRiderChat(riderId, name, phone) {
    activeRiderChatId = riderId;

    const activeIdEl = document.getElementById('activeRiderChatId');
    if (activeIdEl) activeIdEl.value = riderId;

    document.querySelectorAll('#riderChatList .chat-list-item').forEach(el => el.classList.remove('active'));
    if (typeof event !== 'undefined' && event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }

    const emptyState = document.getElementById('riderChatEmptyState');
    const header     = document.getElementById('riderChatHeader');
    const msgArea    = document.getElementById('riderMessagesArea');
    const inputArea  = document.getElementById('riderChatInputArea');

    if (emptyState) emptyState.classList.add('d-none');
    if (header)     header.classList.remove('d-none');
    if (msgArea)    msgArea.classList.remove('d-none');
    if (inputArea)  inputArea.classList.remove('d-none');

    const nameEl   = document.getElementById('riderChatName');
    const phoneEl  = document.getElementById('riderChatPhone');
    const avatarEl = document.getElementById('riderChatAvatar');
    if (nameEl)   nameEl.textContent   = name;
    if (phoneEl)  phoneEl.textContent  = phone || '—';
    if (avatarEl) avatarEl.textContent = (name || '?').charAt(0).toUpperCase();

    loadRiderMessages();

    if (riderChatInterval) clearInterval(riderChatInterval);
    riderChatInterval = setInterval(loadRiderMessages, 5000);
}

function loadRiderMessages() {
    if (!activeRiderChatId) return;
    
    fetch('../api/supplier_rider_chat.php?action=get_messages&rider_id=' + activeRiderChatId + '&supplier_id=' + supplierId)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('riderMessagesArea');
            
            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-comment-slash fs-1 mb-3 opacity-25"></i><p class="small">No messages yet. Say hi! 👋</p></div>';
                return;
            }
            
            let html = '';
            let lastDate = '';
            
            data.forEach(m => {
                const isMine = m.is_mine;
                const bubbleClass = isMine ? 'msg-outgoing' : 'msg-incoming';
                const align = isMine ? 'justify-content-end' : 'justify-content-start';
                
                let imgHtml = '';
                if (m.image) {
                    imgHtml = `<img src="${m.image}" class="img-fluid rounded mt-1 mb-1" style="max-height: 200px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                }
                
                let msgDate = m.date || m.time || '';
                if (msgDate && msgDate !== lastDate) {
                    html += `<div class="text-center my-3"><span class="badge bg-light text-secondary border px-3 py-1 rounded-pill" style="font-size: 0.65rem;">${msgDate}</span></div>`;
                    lastDate = msgDate;
                }
                
                html += `
                    <div class="d-flex ${align} mb-2">
                        <div class="msg-bubble ${bubbleClass}">
                            ${!isMine ? `<div class="fw-bold mb-1" style="font-size:0.7rem; color:var(--teal-600);">${m.sender_name}</div>` : ''}
                            <div>${m.message}</div>
                            ${imgHtml}
                            <div class="msg-time text-end">${m.time}</div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        })
        .catch(err => console.error('Load rider messages error:', err));
}

function sendRiderMessage(e) {
    e.preventDefault();
    if (!activeRiderChatId) return;
    
    const msgInput = document.getElementById('riderMsgInput');
    const message = msgInput.value.trim();
    if (!message && !selectedRiderImage) return;
    
    msgInput.value = '';
    
    const fd = new FormData();
    fd.append('rider_id', activeRiderChatId);
    fd.append('supplier_id', supplierId);
    fd.append('message', message);
    if (selectedRiderImage) fd.append('image', selectedRiderImage);
    
    fetch('../api/supplier_rider_chat.php?action=send_message', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                removeRiderChatImg();
                loadRiderMessages();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

function handleRiderChatImg(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Max 5MB only.'); return; }
    selectedRiderImage = file;
    
    const reader = new FileReader();
    reader.onload = function(evt) {
        document.getElementById('riderChatImgThumb').src = evt.target.result;
        document.getElementById('riderChatImgPreview').classList.remove('d-none');
    };
    reader.readAsDataURL(file);
}

function removeRiderChatImg() {
    selectedRiderImage = null;
    document.getElementById('riderChatImgInput').value = '';
    document.getElementById('riderChatImgPreview').classList.add('d-none');
}

setInterval(function() {
    if (typeof supplierId === 'undefined') return;
    fetch(RIDER_CHAT_API + '?action=get_unread_count&rider_type=supplier&supplier_id=' + supplierId + '&rider_id=0')
        .then(res => res.json())
        .then(data => {
            const badge = document.getElementById('sidebarRiderBadge');
            if (badge && data.success && data.unread > 0) {
                badge.textContent = data.unread;
                badge.classList.remove('d-none');
            } else if (badge) {
                badge.classList.add('d-none');
            }
        })
        .catch(e => console.log(e));
}, 15000);

// ============================================
// MESSAGES MODULE LOADER
// ============================================
function loadMessages() {
    const clinicsTab = document.getElementById('msg-tab-clinics');
    const ridersTab  = document.getElementById('msg-tab-riders');
    const tabClinics = document.getElementById('tab-clinics');
    const tabRiders  = document.getElementById('tab-riders');

    if (clinicsTab) clinicsTab.style.display = 'flex';
    if (ridersTab)  ridersTab.style.display  = 'none';

    if (tabClinics) {
        tabClinics.style.borderBottom = '3px solid var(--teal-600)';
        tabClinics.style.color = 'var(--teal-600)';
    }
    if (tabRiders) {
        tabRiders.style.borderBottom = 'none';
        tabRiders.style.color = '#6b7280';
    }

    const clinicEmpty      = document.getElementById('chatEmptyState');
    const clinicHeader     = document.getElementById('clinicChatHeader');
    const clinicMsgArea    = document.getElementById('clinicMessagesArea');
    const clinicInputArea  = document.getElementById('clinicChatInputArea');

    if (clinicEmpty)     clinicEmpty.classList.remove('d-none');
    if (clinicHeader)    clinicHeader.classList.add('d-none');
    if (clinicMsgArea)   clinicMsgArea.classList.add('d-none');
    if (clinicInputArea) clinicInputArea.classList.add('d-none');

    const riderEmpty       = document.getElementById('riderChatEmptyState');
    const riderHeader      = document.getElementById('riderChatHeader');
    const riderMsgArea     = document.getElementById('riderMessagesArea');
    const riderInputArea   = document.getElementById('riderChatInputArea');

    if (riderEmpty)     riderEmpty.classList.remove('d-none');
    if (riderHeader)    riderHeader.classList.add('d-none');
    if (riderMsgArea)   riderMsgArea.classList.add('d-none');
    if (riderInputArea) riderInputArea.classList.add('d-none');

    if (typeof clinicChatInterval !== 'undefined' && clinicChatInterval) {
        clearInterval(clinicChatInterval);
        clinicChatInterval = null;
    }
    if (typeof riderChatInterval !== 'undefined' && riderChatInterval) {
        clearInterval(riderChatInterval);
        riderChatInterval = null;
    }

    if (typeof activeClinicChatId !== 'undefined') activeClinicChatId = null;
    if (typeof activeRiderChatId  !== 'undefined') activeRiderChatId  = null;

    const activeClinicInput = document.getElementById('activeClinicId');
    const activeRiderInput  = document.getElementById('activeRiderChatId');
    if (activeClinicInput) activeClinicInput.value = '';
    if (activeRiderInput)  activeRiderInput.value  = '';

    if (typeof loadClinicsForChat === 'function') {
        loadClinicsForChat();
    } else {
        const container = document.getElementById('clinicListContainer');
        if (container) {
            container.innerHTML = '<div class="text-center py-4 text-muted small">Chat module not loaded.</div>';
        }
    }

    const riderContainer = document.getElementById('riderChatList');
    if (riderContainer) {
        riderContainer.innerHTML = `
            <div class="text-center py-4 text-muted small">
                <i class="fas fa-motorcycle fs-2 d-block mb-2 opacity-25"></i>
                Click the "Riders" tab to load rider chats
            </div>`;
    }

    if (typeof checkClinicChatUnread === 'function') {
        checkClinicChatUnread();
    }

    if (typeof selectedClinicImage !== 'undefined') selectedClinicImage = null;
    if (typeof selectedRiderImage  !== 'undefined') selectedRiderImage  = null;

    const clinicImgPreview = document.getElementById('clinicImgPreview');
    const riderImgPreview  = document.getElementById('riderChatImgPreview');
    if (clinicImgPreview) clinicImgPreview.classList.add('d-none');
    if (riderImgPreview)  riderImgPreview.classList.add('d-none');
}

function switchMessageTab(tab) {
    const clinicsPane = document.getElementById('msg-tab-clinics');
    const ridersPane  = document.getElementById('msg-tab-riders');
    const tabClinics  = document.getElementById('tab-clinics');
    const tabRiders   = document.getElementById('tab-riders');

    if (tab === 'clinics') {
        clinicsPane.style.display = 'flex';
        ridersPane.style.display  = 'none';

        tabClinics.style.borderBottom = '3px solid var(--teal-600)';
        tabClinics.style.color = 'var(--teal-600)';
        tabRiders.style.borderBottom  = 'none';
        tabRiders.style.color = '#6b7280';

        if (typeof riderChatInterval !== 'undefined' && riderChatInterval) {
            clearInterval(riderChatInterval);
            riderChatInterval = null;
        }

    } else {
        clinicsPane.style.display = 'none';
        ridersPane.style.display  = 'flex';

        tabRiders.style.borderBottom  = '3px solid var(--teal-600)';
        tabRiders.style.color = 'var(--teal-600)';
        tabClinics.style.borderBottom = 'none';
        tabClinics.style.color = '#6b7280';

        if (typeof clinicChatInterval !== 'undefined' && clinicChatInterval) {
            clearInterval(clinicChatInterval);
            clinicChatInterval = null;
        }

        if (typeof loadRidersForChat === 'function') {
            loadRidersForChat();
        }
    }
}

// ============================================
// VERIFICATION LOCK JS
// ============================================
$(document).ready(function() {
    if (!IS_VERIFIED) {
        const modalEl = document.getElementById('verificationModal');
        if (modalEl) {
            new bootstrap.Modal(modalEl, {
                backdrop: 'static',
                keyboard: false
            }).show();
        }
    }
});

function reuploadDoc(docId) {
    document.getElementById('reupload_doc_id').value = docId;
    new bootstrap.Modal(document.getElementById('reuploadModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    const reuploadForm = document.getElementById('reuploadForm');
    if (reuploadForm) {
        reuploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'reupload_document');
            fd.append('supplier_id', supplierId);

            Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            try {
                const res = await fetch('https://eyecore.capstone001.com/api/supplier.php', { 
                    method: 'POST', 
                    body: fd,
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Uploaded!',
                        text: 'Your document will be re-reviewed by our team.',
                        timer: 2000
                    });
                    location.reload();
                } else {
                    Swal.fire('Error', data.error || 'Upload failed', 'error');
                }
            } catch (err) {
                Swal.fire('Error', 'Network error: ' + err.message, 'error');
            }
        });
    }
});

// ============================================
// SUPPORT CHAT WIDGET
// ============================================
const SUPPORT_ID = <?= $_SESSION['supplier_id'] ?>;

document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('supportToggleBtn');
    const chatWindow = document.getElementById('supportChatWindow');
    const badge = document.getElementById('supportUnreadBadge');
    let activeThreadId = null;
    let chatInterval = null;
    let selectedImage = null;

    if (!toggleBtn) return;

    function getApiUrl(action, extra = '') {
        return SUPPORT_API + '?action=' + action + '&supplier_id=' + SUPPORT_ID + extra;
    }

    const textarea = document.getElementById('supportMessageInput');
    textarea.addEventListener('input', function() {
        this.style.height = '36px';
        this.style.height = (this.scrollHeight) + 'px';
        if(this.scrollHeight > 100) this.style.height = '100px';
    });

    window.toggleSupportWidget = function() {
        chatWindow.classList.toggle('d-none');
        if (!chatWindow.classList.contains('d-none')) {
            initSupportChat();
            chatInterval = setInterval(checkUnread, 5000);
        } else {
            clearInterval(chatInterval);
        }
    };
    toggleBtn.addEventListener('click', toggleSupportWidget);

    function checkUnread() {
        fetch(getApiUrl('get_unread_count'))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.unread > 0) {
                    badge.textContent = data.unread;
                    badge.classList.remove('d-none');
                    if (activeThreadId) loadSupportMessages(); 
                } else {
                    badge.classList.add('d-none');
                }
            }).catch(e => console.log(e));
    }
    setInterval(checkUnread, 15000); 
    checkUnread();

    function initSupportChat() {
        fetch(getApiUrl('get_threads'))
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    activeThreadId = data[0].id;
                    loadSupportMessages();
                } else {
                    document.getElementById('supportMessagesArea').innerHTML = `
                        <div class="text-center text-muted small mt-3">
                            <i class="fas fa-comments fs-2 d-block mb-2 text-secondary opacity-50"></i>
                            Hello! Are you experiencing any issues with the system? Just type your message here and the developer will respond shortly.
                        </div>`;
                }
            });
    }

    window.loadSupportMessages = function() {
        if (!activeThreadId) return;
        fetch(getApiUrl('get_messages', '&thread_id=' + activeThreadId))
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data)) return;
                const container = document.getElementById('supportMessagesArea');
                
                if(data.length === 0) return;

                let html = '';
                let lastDate = '';

                data.forEach(m => {
                    const isMine = (m.sender_role !== 'SuperAdmin');
                    const bubbleClass = isMine ? 'text-white ml-auto' : 'bg-white border text-dark';
                    const bgStyle = isMine ? 'background-color: #0d9488;' : '';
                    const align = isMine ? 'justify-content-end' : 'justify-content-start';
                    const sender = !isMine ? `<div class="fw-bold mb-1" style="font-size:0.7rem; color:#0d9488;">${m.sender_name}</div>` : '';
                    
                    let imgHtml = '';
                    if (m.image) {
                        imgHtml = `<img src="${m.image}" class="img-fluid rounded mt-1 mb-1" style="max-height: 150px; cursor: pointer;" onclick="window.open('${m.image}', '_blank')">`;
                    }

                    let msgDate = m.date || m.time || '';
                    if (msgDate && msgDate !== lastDate) {
                        html += `<div class="text-center my-3"><span class="badge bg-light text-secondary border px-3 py-1 rounded-pill" style="font-size: 0.65rem; font-weight: 600;">${msgDate}</span></div>`;
                        lastDate = msgDate;
                    }

                    html += `
                        <div class="d-flex ${align} mb-2">
                            <div class="p-2 rounded ${bubbleClass}" style="max-width: 85%; font-size: 0.85rem; border-radius: 12px !important; word-break: break-word; ${bgStyle}">
                                ${sender}
                                <div style="margin-bottom: 2px;">${m.message}</div>
                                ${imgHtml}
                                <div class="text-right mt-1" style="font-size: 0.6rem; opacity: 0.8;">${m.time}</div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
                container.scrollTop = container.scrollHeight;
            });
    }

    window.sendSupportMessage = function(e) {
        e.preventDefault();
        const msgInput = document.getElementById('supportMessageInput');
        const message = msgInput.value.trim();
        if (!message && !selectedImage) return;

        msgInput.value = '';
        msgInput.style.height = '36px';
        
        const fd = new FormData();
        fd.append('supplier_id', SUPPORT_ID);
        if (activeThreadId) fd.append('thread_id', activeThreadId);
        fd.append('message', message);
        if (selectedImage) fd.append('image', selectedImage);

        const container = document.getElementById('supportMessagesArea');
        if(container.querySelector('.text-center')) container.innerHTML = '';

        const endpoint = activeThreadId ? 'send_message' : 'create_thread';
        if(!activeThreadId) fd.append('subject', 'Supplier Support Request');

        fetch(getApiUrl(endpoint), { method: 'POST', body: fd })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); } 
                catch (err) { 
                    console.error("Server returned:", text);
                    throw new Error("Invalid JSON from server.");
                }
            })
            .then(data => {
                if (data.success) {
                    if (!activeThreadId && data.thread_id) {
                        activeThreadId = data.thread_id;
                    }
                    removeSupImage();
                    loadSupportMessages();
                } else {
                    alert('System Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Network Error: ' + err.message));
    };

    window.handleSupImage = function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { alert('Max 5MB only.'); return; }
        selectedImage = file;
        
        const reader = new FileReader();
        reader.onload = function(evt) {
            document.getElementById('supportImgThumb').src = evt.target.result;
            document.getElementById('supportImgPreview').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    };

    window.removeSupImage = function() {
        selectedImage = null;
        document.getElementById('supImageInput').value = '';
        document.getElementById('supportImgPreview').classList.add('d-none');
    };
});

// ============================================
// INIT ON PAGE LOAD
// ============================================
$(document).ready(function() {
    loadDashboardStats();
    loadRecentPending();
    loadReturns();
    loadInvoiceStats();
    
    // Auto check unread messages every 15s
    setInterval(checkClinicChatUnread, 15000);
    checkClinicChatUnread();
});
</script>

</body>
</html>