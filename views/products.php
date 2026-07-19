<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

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

if (!RBACHelper::hasPermission('products_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access the Product &amp; Service Catalog.</p>
        </div>
    </div>
    <?php
    exit;
}

$canView    = RBACHelper::hasPermission('products_view');
$canCreate  = RBACHelper::hasPermission('products_create');
$canEdit    = RBACHelper::hasPermission('products_edit');
$canDelete  = RBACHelper::hasPermission('products_delete');
$canApprove = RBACHelper::hasPermission('products_approve');
$canReject  = RBACHelper::hasPermission('products_reject');

$clinicId = $_SESSION['clinic_id'] ?? 1;
$userName = $_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? '');

$defaultModels = $pdo->query("SELECT * FROM default_3d_models WHERE model_type = 'frame' ORDER BY display_order")->fetchAll();

$serviceCategories = ['Eye Exam', 'Eyeglasses', 'Contact Lens', 'Treatment', 'Screening', 'Other'];

// ── Category-specific extra fields ─────────────────────────────────────────
$categoryFields = [
    'Frames' => [
        ['id' => 'frame_material', 'label' => 'Frame Material',   'type' => 'select', 'options' => ['Acetate','Metal','Titanium','TR-90','Wood','Horn','Mixed']],
        ['id' => 'frame_shape',    'label' => 'Frame Shape',      'type' => 'select', 'options' => ['Rectangle','Round','Oval','Square','Cat-Eye','Aviator','Geometric','Rimless','Semi-Rimless']],
        ['id' => 'frame_size',     'label' => 'Frame Size',       'type' => 'select', 'options' => ['XS','S','M','L','XL','One Size']],
        ['id' => 'frame_gender',   'label' => 'Gender',           'type' => 'select', 'options' => ['Unisex','Men','Women','Kids']],
        ['id' => 'lens_width',     'label' => 'Lens Width (mm)',  'type' => 'number', 'placeholder' => 'e.g. 52'],
        ['id' => 'bridge_width',   'label' => 'Bridge Width (mm)','type' => 'number', 'placeholder' => 'e.g. 18'],
        ['id' => 'temple_length',  'label' => 'Temple Length (mm)','type' => 'number','placeholder' => 'e.g. 140'],
    ],
    'Lenses' => [
        ['id' => 'lens_type',     'label' => 'Lens Type',        'type' => 'select', 'options' => ['Single Vision','Bifocal','Progressive','Reading']],
        ['id' => 'lens_material', 'label' => 'Lens Material',    'type' => 'select', 'options' => ['CR-39','Polycarbonate','Trivex','Hi-Index 1.67','Hi-Index 1.74','Glass']],
        ['id' => 'lens_coating',  'label' => 'Coating',          'type' => 'select', 'options' => ['None','Anti-Reflective','UV400','Blue Light','Photochromic','Polarized']],
        ['id' => 'lens_index',    'label' => 'Refractive Index', 'type' => 'select', 'options' => ['1.50','1.56','1.60','1.67','1.70','1.74']],
        ['id' => 'sphere_range',  'label' => 'Sphere Range',     'type' => 'text',   'placeholder' => 'e.g. -6.00 to +4.00'],
    ],
    'Contact Lenses' => [
        ['id' => 'cl_brand',      'label' => 'Brand',            'type' => 'text',   'placeholder' => 'e.g. Acuvue'],
        ['id' => 'cl_type',       'label' => 'Wear Type',        'type' => 'select', 'options' => ['Daily','Bi-Weekly','Monthly','Yearly']],
        ['id' => 'cl_water',      'label' => 'Water Content (%)', 'type' => 'number', 'placeholder' => 'e.g. 58'],
        ['id' => 'cl_bc',         'label' => 'Base Curve (mm)',  'type' => 'number', 'placeholder' => 'e.g. 8.5'],
        ['id' => 'cl_diameter',   'label' => 'Diameter (mm)',    'type' => 'number', 'placeholder' => 'e.g. 14.2'],
        ['id' => 'cl_power',      'label' => 'Power Range',      'type' => 'text',   'placeholder' => 'e.g. -8.00 to +4.00'],
        ['id' => 'cl_color',      'label' => 'Colored',          'type' => 'select', 'options' => ['No','Yes']],
    ],
    'Accessories' => [
        ['id' => 'acc_type',      'label' => 'Accessory Type',   'type' => 'select', 'options' => ['Case','Cleaning Kit','Chain/Cord','Strap','Screwdriver Set','Nose Pads','Other']],
        ['id' => 'acc_material',  'label' => 'Material',         'type' => 'text',   'placeholder' => 'e.g. Leather, Microfiber'],
        ['id' => 'acc_compatible','label' => 'Compatible With',  'type' => 'text',   'placeholder' => 'e.g. All frames, Metal frames'],
    ],
    'Solutions' => [
        ['id' => 'sol_type',      'label' => 'Solution Type',    'type' => 'select', 'options' => ['Multi-Purpose','Saline','Hydrogen Peroxide','Daily Cleaner','Rewetting Drops']],
        ['id' => 'sol_volume',    'label' => 'Volume (ml)',      'type' => 'number', 'placeholder' => 'e.g. 360'],
        ['id' => 'sol_brand',     'label' => 'Brand',            'type' => 'text',   'placeholder' => 'e.g. Renu, Opti-Free'],
    ],
    'Parts' => [
        ['id' => 'part_type',     'label' => 'Part Type',        'type' => 'select', 'options' => ['Screw','Hinge','Temple','Nose Pad','Frame Front','Lens']],
        ['id' => 'part_compat',   'label' => 'Compatible Frames','type' => 'text',   'placeholder' => 'e.g. Metal frames, All types'],
        ['id' => 'part_size',     'label' => 'Size/Dimension',   'type' => 'text',   'placeholder' => 'e.g. 1.4mm x 3mm'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product & Service Catalog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* [Your existing CSS styles remain unchanged] */
        :root {
            --teal: #008080;
            --teal-dark: #006666;
            --teal-light: #e0f2f2;
            --teal-mid: #00a0a0;
            --bg: #f0f4f4;
            --card-bg: #ffffff;
            --text: #1a2e2e;
            --text-soft: #4a6060;
            --text-muted: #7a9090;
            --border: #d0e4e4;
            --radius: 14px;
            --radius-sm: 8px;
            --shadow: 0 2px 12px rgba(0,128,128,0.08);
            --shadow-hover: 0 8px 24px rgba(0,128,128,0.18);
        }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', -apple-system, sans-serif; }
        .page-header { background: linear-gradient(135deg, var(--teal) 0%, var(--teal-mid) 60%, #00b8b8 100%); padding: 28px 32px 24px; position: relative; overflow: hidden; }
        .page-header::before { content:''; position:absolute; top:-40px; right:-40px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,0.06); }
        .page-header::after  { content:''; position:absolute; bottom:-60px; right:120px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,0.04); }
        .page-header h4 { font-size:22px; font-weight:700; color:white; margin:0; }
        .page-header p  { font-size:13px; color:rgba(255,255,255,0.8); margin:4px 0 0; }
        .tab-bar { background:var(--card-bg); border-bottom:2px solid var(--border); padding:0 32px; display:flex; gap:4px; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
        .tab-btn { display:flex; align-items:center; gap:8px; padding:16px 20px 14px; font-size:14px; font-weight:600; color:var(--text-soft); border:none; background:none; border-bottom:3px solid transparent; cursor:pointer; transition:all 0.2s; margin-bottom:-2px; }
        .tab-btn:hover { color:var(--teal); }
        .tab-btn.active { color:var(--teal); border-bottom-color:var(--teal); }
        .tab-btn .tab-count { background:var(--teal-light); color:var(--teal); font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .tab-btn.active .tab-count { background:var(--teal); color:white; }
        .tab-panel { display:none; padding:24px 32px 40px; }
        .tab-panel.active { display:block; }
        .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
        .toolbar-left { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .search-box { display:flex; align-items:center; gap:8px; background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius-sm); padding:8px 14px; font-size:13px; color:var(--text); min-width:220px; transition:border-color 0.2s; }
        .search-box:focus-within { border-color:var(--teal); }
        .search-box input { border:none; outline:none; background:transparent; font-size:13px; width:100%; }
        .search-box i { color:var(--text-muted); }
        .filter-select { background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius-sm); padding:8px 14px; font-size:13px; color:var(--text); cursor:pointer; }
        .filter-select:focus { outline:none; border-color:var(--teal); }
        .btn-teal { background:var(--teal); color:white; border:none; border-radius:var(--radius-sm); padding:9px 18px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:all 0.2s; text-decoration:none; }
        .btn-teal:hover { background:var(--teal-dark); color:white; transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,128,128,0.3); }
        .btn-teal:disabled { background:#94a3b8; cursor:not-allowed; transform:none; box-shadow:none; }
        .btn-outline-teal { background:transparent; color:var(--teal); border:1.5px solid var(--teal); border-radius:var(--radius-sm); padding:7px 16px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:all 0.2s; text-decoration:none; }
        .btn-outline-teal:hover { background:var(--teal); color:white; }
        .btn-sm-icon { width:32px; height:32px; border-radius:var(--radius-sm); border:1.5px solid var(--border); background:var(--card-bg); color:var(--text-soft); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:13px; transition:all 0.2s; }
        .btn-sm-icon:hover { border-color:var(--teal); color:var(--teal); }
        .btn-sm-icon.danger:hover { border-color:#dc3545; color:#dc3545; }
        .btn-sm-icon:disabled { opacity:0.3; cursor:not-allowed; pointer-events:none; }
        .products-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:18px; }
        .product-card { background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; transition:all 0.25s; cursor:pointer; }
        .product-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color:var(--teal); }
        .product-card .card-img { width:100%; height:200px; object-fit:cover; background:var(--teal-light); }
        .product-card .card-img-placeholder { width:100%; height:200px; background:linear-gradient(135deg, var(--teal-light), #c0e8e8); display:flex; align-items:center; justify-content:center; font-size:48px; color:var(--teal); }
        .product-card .card-body { padding:14px 16px; }
        .product-card .card-name { font-size:14px; font-weight:700; color:var(--text); margin-bottom:3px; line-height:1.3; }
        .product-card .card-brand { font-size:12px; color:var(--text-muted); margin-bottom:10px; }
        .product-card .card-meta { display:flex; justify-content:space-between; align-items:center; }
        .product-card .card-price { font-size:16px; font-weight:700; color:var(--teal); }
        .product-card .card-price.sale { color:#dc3545; }
        .product-card .card-price-old { font-size:11px; color:var(--text-muted); text-decoration:line-through; }
        .product-card .card-footer { padding:10px 16px; border-top:1px solid var(--border); display:flex; gap:6px; flex-wrap:wrap; }
        .product-card .badge-3d { background:var(--teal); color:white; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; display:inline-flex; align-items:center; gap:3px; }
        .badge-sale { background:#dc3545; color:white; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .badge-cat { background:var(--teal-light); color:var(--teal); font-size:10px; font-weight:600; padding:3px 9px; border-radius:20px; }
        .badge-stock-ok { background:#d4edda; color:#155724; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .badge-stock-low { background:#fff3cd; color:#856404; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .badge-stock-out { background:#f8d7da; color:#721c24; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .badge-pending { background:#fff3cd; color:#856404; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .badge-approved { background:#d4edda; color:#155724; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .badge-rejected { background:#f8d7da; color:#721c24; font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600; }
        .services-table-wrap { background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow); }
        .services-table { width:100%; border-collapse:collapse; }
        .services-table thead th { background:var(--teal-light); color:var(--teal-dark); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; padding:12px 16px; border-bottom:2px solid var(--border); white-space:nowrap; }
        .services-table tbody tr { border-bottom:1px solid var(--border); transition:background 0.15s; }
        .services-table tbody tr:last-child { border-bottom:none; }
        .services-table tbody tr:hover { background:#f8fdfd; }
        .services-table tbody td { padding:13px 16px; font-size:13px; vertical-align:middle; }
        .service-name { font-weight:600; color:var(--text); }
        .service-desc { font-size:12px; color:var(--text-muted); margin-top:2px; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .service-price { font-weight:700; color:var(--teal); font-size:14px; }
        .service-duration { display:inline-flex; align-items:center; gap:4px; background:var(--teal-light); color:var(--teal); font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; }
        .cat-pill { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .cat-eye-exam { background:#e3f2fd; color:#1565c0; }
        .cat-eyeglasses { background:#e8f5e9; color:#2e7d32; }
        .cat-contact-lens { background:#f3e5f5; color:#6a1b9a; }
        .cat-treatment { background:#fff8e1; color:#e65100; }
        .cat-screening { background:#fce4ec; color:#880e4f; }
        .cat-other { background:#f5f5f5; color:#424242; }
        .empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; text-align:center; }
        .empty-state .empty-icon { width:80px; height:80px; background:var(--teal-light); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:36px; color:var(--teal); margin-bottom:20px; }
        .empty-state h5 { font-weight:700; color:var(--text); margin-bottom:8px; }
        .empty-state p { font-size:13px; color:var(--text-muted); margin-bottom:24px; max-width:320px; }
        .modal-header.teal-header { background:linear-gradient(135deg, var(--teal), var(--teal-mid)); color:white; }
        .modal-header.teal-header .btn-close { filter:invert(1); }
        .form-label.fw-semibold { font-size:13px; font-weight:600; color:var(--text-soft); }
        .form-control, .form-select { border:1.5px solid var(--border); border-radius:var(--radius-sm); font-size:13px; color:var(--text); transition:border-color 0.2s; }
        .form-control:focus, .form-select:focus { border-color:var(--teal); box-shadow:0 0 0 3px rgba(0,128,128,0.1); }
        .section-card { border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-bottom:18px; }
        .section-card .section-card-head { background:var(--teal-light); padding:12px 18px; font-size:13px; font-weight:700; color:var(--teal-dark); display:flex; align-items:center; gap:8px; border-bottom:1.5px solid var(--border); }
        .section-card .section-card-body { padding:18px; }
        .color-swatch { width:28px; height:28px; border-radius:50%; display:inline-block; margin:2px; border:2px solid #fff; box-shadow:0 0 0 1px #ddd; cursor:pointer; transition:transform 0.2s; }
        .color-swatch:hover { transform:scale(1.1); }
        .color-swatch-large { width:45px; height:45px; border-radius:50%; cursor:pointer; border:3px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,0.2); transition:transform 0.2s; display:inline-block; }
        .color-swatch-large:hover { transform:scale(1.1); }
        .color-row { background-color:#f8f9fa; border-radius:10px; padding:15px; margin-bottom:12px; border:1px solid #dee2e6; transition:all 0.2s ease; }
        .color-row:hover { background-color:#ffffff; border-color:#008080; box-shadow:0 4px 8px rgba(0,128,128,0.1); transform:translateY(-1px); }
        .color-preview { width:45px; height:45px; border-radius:10px; border:2px solid #fff; box-shadow:0 3px 6px rgba(0,0,0,0.1); transition:transform 0.2s; }
        .color-preview:hover { transform:scale(1.1); }
        .color-name-input { font-weight:500; border:1px solid #e9ecef; transition:all 0.2s; }
        .color-name-input:focus { border-color:#008080; box-shadow:0 0 0 3px rgba(0,128,128,0.1); }
        .color-code-input { cursor:pointer; padding:3px; height:38px; border:1px solid #e9ecef; border-radius:6px; }
        .hex-value { font-family:monospace; font-size:12px; color:#008080; background:#e8f5e9; padding:4px 8px; border-radius:4px; }
        .btn-delete-color { width:36px; height:36px; border-radius:8px; background:transparent; border:1px solid #e0e0e0; color:#dc3545; display:flex; align-items:center; justify-content:center; transition:all 0.2s ease; cursor:pointer; padding:0; opacity:0.7; }
        .btn-delete-color:hover { background:#dc3545; border-color:#dc3545; color:white; transform:scale(1.05); opacity:1; }
        .form-switch .form-check-input { width:2.5em; height:1.25em; margin-right:0.5rem; cursor:pointer; }
        .form-switch .form-check-input:checked { background-color:#008080; border-color:#008080; }
        .form-switch .form-check-label { font-size:0.85rem; color:#6c757d; cursor:pointer; display:flex; align-items:center; }
        .badge.bg-success { background-color:#008080 !important; padding:4px 8px; font-weight:500; }
        .badge.bg-secondary { background-color:#6c757d !important; padding:4px 8px; font-weight:500; }
        .frame-preview-container { width:100%; height:280px; background:#fff; border-radius:var(--radius); overflow:hidden; position:relative; border:2px solid var(--teal); box-shadow:var(--shadow); }
        .frame-preview-container canvas { width:100% !important; height:100% !important; }
        .preview-controls { position:absolute; bottom:10px; right:10px; z-index:10; display:flex; gap:5px; }
        .preview-controls button { background:rgba(0,0,0,0.7); border:none; color:white; width:30px; height:30px; border-radius:50%; cursor:pointer; transition:all 0.2s; }
        .preview-controls button:hover { background:var(--teal); transform:scale(1.05); }
        .color-palette { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; padding:10px; background:var(--bg); border-radius:var(--radius-sm); }
        .model-section-disabled { opacity:0.4; pointer-events:none; }
        .model-section-note { font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:6px; padding:8px 12px; background:var(--teal-light); border-radius:var(--radius-sm); }
        .model-section-note.frame-ready { background:#e8f5e9; color:#2e7d32; }
        #categoryExtraFields .extra-field-group { animation: fadeSlideIn 0.2s ease forwards; }
        @keyframes fadeSlideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeIn { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeOut { from { opacity:1; transform:translateY(0); } to { opacity:0; transform:translateY(10px); } }
        .animate__animated { animation-duration:0.3s; animation-fill-mode:both; }
        .animate__fadeIn { animation-name:fadeIn; }
        .animate__fadeOut { animation-name:fadeOut; }
        #salePreview { background:var(--teal-light); border:1.5px solid var(--border); border-radius:var(--radius-sm); padding:12px 16px; }
        @media (max-width:768px) { .page-header { padding:20px; } .tab-bar { padding:0 16px; overflow-x:auto; } .tab-panel { padding:16px; } .toolbar { flex-direction:column; align-items:stretch; } .search-box { min-width:unset; } .products-grid { grid-template-columns:repeat(auto-fill, minmax(240px,1fr)); gap:12px; } }
        .modal { background-color:rgba(0,0,0,0.5); }
        .modal.show { display:block !important; }
        #frame-viewer-container { width:100%; height:100%; min-height:500px; background:#ffffff; }
    /* Completed Models Section Styles */
.completed-models-header {
    border-bottom: 1px solid var(--border);
    padding-bottom: 8px;
}

.empty-models-state {
    background: var(--teal-light);
    border: 1px dashed var(--teal);
    border-radius: 12px;
}

.empty-models-state i {
    opacity: 0.5;
}

.model-preview-icon {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.completed-model-card:hover {
    background-color: #f8f9fa;
    border-color: #008080 !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,128,128,0.1);
}

.completed-model-card:has(input:checked) {
    background-color: #e8f5e9;
    border-color: #008080 !important;
}

.hover-shadow {
    transition: all 0.2s ease;
}

.completed-models-grid::-webkit-scrollbar {
    width: 6px;
}

.completed-models-grid::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.completed-models-grid::-webkit-scrollbar-thumb {
    background: #008080;
    border-radius: 3px;
}

.completed-models-grid::-webkit-scrollbar-thumb:hover {
    background: #006666;
}

#myModelsSection {
    animation: fadeSlideIn 0.3s ease;
}

/* Warranty Section Styles */
#warranty_suggestion {
    font-size: 11px;
    color: #28a745;
    display: block;
    margin-top: 5px;
}
.warranty-badge {
    background: #17a2b8;
    color: white;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 20px;
    margin-left: 8px;
}

/* Multiple select styling */
select[multiple] {
    background-color: #fff;
    border-radius: 8px;
    padding: 8px;
}

select[multiple] option {
    padding: 6px 10px;
    border-radius: 4px;
    margin: 2px 0;
}

select[multiple] option:checked {
    background: #008080 linear-gradient(0deg, #008080 0%, #008080 100%);
    color: white;
}

select[multiple] option:hover {
    background-color: #e0f2f2;
}

@keyframes fadeSlideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
    </style>
</head>
<body>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4><i class="bi bi-grid me-2"></i>Product & Service Catalog</h4>
            <p>Manage products and clinic services visible to patients</p>
        </div>
    </div>
</div>

<!-- TAB BAR -->
<div class="tab-bar">
    <button class="tab-btn active" id="tab-products" onclick="switchTab('products')">
        <i class="bi bi-box-seam"></i> Products
        <span class="tab-count" id="products-count">0</span>
    </button>
    <button class="tab-btn" id="tab-services" onclick="switchTab('services')">
        <i class="bi bi-clipboard2-pulse"></i> Services
        <span class="tab-count" id="services-count">0</span>
    </button>
</div>

<!-- PRODUCTS PANEL -->
<div class="tab-panel active" id="panel-products">
    <div class="toolbar">
        <div class="toolbar-left">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="productSearch" placeholder="Search products..." oninput="filterProducts()">
            </div>
            <select class="filter-select" id="productCatFilter" onchange="filterProducts()">
                <option value="">All Categories</option>
                <option>Frames</option>
                <option>Lenses</option>
                <option>Contact Lenses</option>
                <option>Accessories</option>
                <option>Solutions</option>
                <option>Parts</option>
            </select>
        </div>
        <?php if ($canCreate): ?>
            <button class="btn-teal" onclick="openPostModal()">
                <i class="bi bi-plus-lg"></i> Post Product
            </button>
        <?php else: ?>
            <button class="btn-teal" disabled title="No permission">
                <i class="bi bi-lock"></i> Post Product
            </button>
        <?php endif; ?>
    </div>
    <div class="products-grid" id="productsGrid">
        <div style="grid-column:1/-1;" class="empty-state">
            <div class="spinner-border" style="color:var(--teal);" role="status"></div>
            <p class="mt-3 text-muted small">Loading products...</p>
        </div>
    </div>
</div>

<!-- SERVICES PANEL -->
<div class="tab-panel" id="panel-services">
    <div class="toolbar">
        <div class="toolbar-left">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="serviceSearch" placeholder="Search services..." oninput="filterServices()">
            </div>
            <select class="filter-select" id="serviceCatFilter" onchange="filterServices()">
                <option value="">All Categories</option>
                <?php foreach ($serviceCategories as $cat): ?>
                    <option><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($canCreate): ?>
            <button class="btn-teal" onclick="openServiceModal()">
                <i class="bi bi-plus-lg"></i> Add Service
            </button>
        <?php else: ?>
            <button class="btn-teal" disabled><i class="bi bi-lock"></i> Add Service</button>
        <?php endif; ?>
    </div>
    <div class="services-table-wrap">
        <table class="services-table" id="servicesTable">
            <thead>
                <tr><th>Service</th><th>Category</th><th>Price</th><th>Duration</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
            </thead>
            <tbody id="servicesBody">
                <tr><td colspan="6"><div class="d-flex flex-column align-items-center gap-2 py-5"><div class="spinner-border" style="color:var(--teal);" role="status"></div><span class="text-muted small">Loading services...</span></div></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- SERVICE MODAL -->
<div class="modal fade" id="serviceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header teal-header">
                <h5 class="modal-title" id="serviceModalTitle"><i class="bi bi-clipboard2-pulse me-2"></i>Add New Service</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="service_id">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="service_name" placeholder="e.g., Basic Eye Exam"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Category <span class="text-danger">*</span></label><select class="form-select" id="service_category"><option value="">-- Select --</option><?php foreach ($serviceCategories as $cat): ?><option><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea class="form-control" id="service_description" rows="3" placeholder="Describe what this service includes..."></textarea></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Price (₱) <span class="text-danger">*</span></label><input type="number" step="0.01" class="form-control" id="service_price" placeholder="0.00"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Duration (minutes)</label><input type="number" class="form-control" id="service_duration" placeholder="e.g., 30"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select class="form-select" id="service_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn-teal" onclick="saveService()"><i class="bi bi-check-lg me-1"></i> Save Service</button></div>
        </div>
    </div>
</div>

<!-- POST PRODUCT MODAL -->
<div class="modal fade" id="postProductModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header teal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Post New Product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="postProductForm" enctype="multipart/form-data">
                    <!-- SELECT ITEM -->
                    <div class="section-card mb-4">
                        <div class="section-card-head"><i class="bi bi-search"></i> Select Item from Inventory</div>
                        <div class="section-card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold">Choose Item</label>
                                    <select class="form-select form-select-lg" id="selectItem" onchange="loadItemDetails()">
                                        <option value="">-- Select an item --</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="button" class="btn-outline-teal w-100" onclick="refreshInventory()"><i class="bi bi-arrow-repeat"></i> Refresh List</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- PRODUCT INFO -->
                    <div class="section-card mb-4">
                        <div class="section-card-head"><i class="bi bi-info-circle"></i> Product Information</div>
                        <div class="section-card-body">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label fw-semibold">Product Name</label><input type="text" class="form-control" id="product_name" readonly></div>
                                <div class="col-md-3"><label class="form-label fw-semibold">Category</label><input type="text" class="form-control" id="product_category" readonly></div>
                                <div class="col-md-3"><label class="form-label fw-semibold">Brand</label><input type="text" class="form-control" id="product_brand" readonly></div>
                                <div class="col-md-4"><label class="form-label fw-semibold">Price (₱)</label><input type="number" class="form-control" id="product_price" readonly></div>
                                <div class="col-md-4"><label class="form-label fw-semibold">Stock</label><input type="number" class="form-control" id="product_stock" readonly></div>
                                <div class="col-md-4"><label class="form-label fw-semibold">Source</label><input type="text" class="form-control" id="product_source" readonly></div>
                                <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea class="form-control" id="product_description" rows="3" placeholder="Add description..."></textarea></div>
                            </div>
                        </div>
                    </div>
                    <!-- CATEGORY EXTRA FIELDS -->
                    <div class="section-card mb-4" id="categoryExtraCard" style="display:none;">
                        <div class="section-card-head"><i class="bi bi-list-check"></i> <span id="categoryExtraTitle">Additional Details</span></div>
                        <div class="section-card-body"><div class="row g-3" id="categoryExtraFields"></div></div>
                    </div>
                    <!-- PRODUCT IMAGES -->
                    <div class="section-card mb-4">
                        <div class="section-card-head"><i class="bi bi-images"></i> Product Images (Max 5)</div>
                        <div class="section-card-body">
                            <div class="row g-2 mb-3" id="imagePreviewContainer"></div>
                            <div class="border rounded-3 p-4 text-center" style="cursor:pointer;background:var(--bg);" onclick="document.getElementById('productImages').click()"><i class="bi bi-cloud-upload fs-1 text-muted"></i><div class="fw-semibold mt-2 text-muted">Click to upload images</div><small class="text-muted">PNG, JPG up to 5MB each</small></div>
                            <input type="file" id="productImages" class="d-none" multiple accept="image/*" onchange="previewImages(this)">
                        </div>
                    </div>
                    <!-- COLOR VARIANTS -->
                    <div class="section-card mb-4" style="border-color:var(--teal);">
                        <div class="section-card-head d-flex justify-content-between align-items-center" style="background:var(--teal-light);color:var(--teal-dark);">
                            <span><i class="bi bi-palette me-2"></i>Color Variants <small class="fw-normal">(for 3D Viewer)</small></span>
                            <button type="button" class="btn-teal btn-sm py-1 px-2" style="font-size:12px;" onclick="addColorRow()"><i class="bi bi-plus-lg me-1"></i>Add Color</button>
                        </div>
                        <div class="section-card-body">
                            <div id="colorRowsContainer">
                                <div class="text-center text-muted py-4" id="noColorsMessage">
                                    <i class="bi bi-palette fs-1 mb-3 opacity-50 d-block"></i>
                                    <p class="mb-2">No colors added yet.</p>
                                    <button type="button" class="btn-teal btn-sm" onclick="addColorRow()"><i class="bi bi-plus-lg me-1"></i>Add Your First Color</button>
                                </div>
                            </div>
                            <small class="text-muted mt-3 d-block"><i class="bi bi-info-circle me-1"></i>Add all available colors. These will appear in the 3D viewer color palette.</small>
                        </div>
                    </div>
                    <!-- SALES SECTION -->
                    <div class="section-card mb-4" style="border-color:#ffc107;">
                        <div class="section-card-head" style="background:#fff8e1;color:#795400;border-color:#ffc107;"><i class="bi bi-tag"></i> Sales Options</div>
                        <div class="section-card-body">
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="enableSale" onchange="toggleSaleFields()"><label class="form-check-label fw-semibold" for="enableSale"><i class="bi bi-percent"></i> Put this product on SALE</label></div>
                            <div id="saleFields" style="display:none;">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label fw-semibold">Sale Price (₱) <span class="text-danger">*</span></label><input type="number" step="0.01" class="form-control" id="sale_price" placeholder="0.00" oninput="updateSalePreview()"><small class="text-muted">Original: ₱<span id="originalPriceDisplay">0.00</span></small></div>
                                    <div class="col-md-4"><label class="form-label fw-semibold">Sale Start</label><input type="datetime-local" class="form-control" id="sale_start"></div>
                                    <div class="col-md-4"><label class="form-label fw-semibold">Sale End</label><input type="datetime-local" class="form-control" id="sale_end"></div>
                                    <div class="col-md-6"><label class="form-label fw-semibold">Sale Label</label><input type="text" class="form-control" id="sale_label" placeholder="e.g., Flash Sale" oninput="updateSalePreview()"></div>
                                    <div class="col-12"><div id="salePreview"><strong>Preview:</strong><span class="text-muted text-decoration-line-through me-2">₱0.00</span><span class="text-danger fw-bold">₱0.00</span><span class="badge bg-danger ms-2">SALE</span></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- WARRANTY SECTION - DETAILED VERSION -->
                    <div class="section-card mb-4" style="border-color:#17a2b8;">
                        <div class="section-card-head" style="background:#e3f2fd;color:#0d47a1;">
                            <i class="bi bi-shield-check me-2"></i> Warranty Information
                            <small class="ms-2 fw-normal text-muted">(Optional - per product)</small>
                        </div>
                        <div class="section-card-body">
                            <div class="row g-3">
                                <!-- Warranty Period -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Warranty Period</label>
                                    <select class="form-select" id="warranty_period" onchange="updateWarrantyPreview()">
                                        <option value="">-- No warranty --</option>
                                        <option value="3_months">3 Months</option>
                                        <option value="6_months">6 Months</option>
                                        <option value="12_months">12 Months (Standard)</option>
                                        <option value="24_months">24 Months (Premium +₱200)</option>
                                        <option value="36_months">36 Months (Extended +₱500)</option>
                                    </select>
                                </div>

                                <!-- Premium Price Display -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Premium Price (if applicable)</label>
                                    <input type="number" class="form-control" id="warranty_premium_price" readonly value="0" step="50">
                                </div>

                                <!-- Warranty Template Selector -->
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Quick Template</label>
                                    <select class="form-select" id="warranty_template" onchange="loadWarrantyTemplate()">
                                        <option value="">-- Select template --</option>
                                        <option value="basic">Basic Warranty</option>
                                        <option value="standard">Standard Warranty</option>
                                        <option value="premium">Premium Warranty</option>
                                        <option value="lenskart">Lenskart Style</option>
                                    </select>
                                </div>

                                <!-- What's Covered (Frame Issues) -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><i class="bi bi-glasses"></i> Frame Issues Covered</label>
                                    <div class="border rounded-3 p-3">
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="manufacturing_defect" id="cov_defect"> <label class="form-check-label small" for="cov_defect">Manufacturing Defects</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="frame_breakage" id="cov_breakage"> <label class="form-check-label small" for="cov_breakage">Frame Breakage (material defect)</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="hinge_damage" id="cov_hinge"> <label class="form-check-label small" for="cov_hinge">Hinge Damage / Detachment</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="color_fading" id="cov_fading"> <label class="form-check-label small" for="cov_fading">Color Fading / Discoloration</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="frame_bent" id="cov_bent"> <label class="form-check-label small" for="cov_bent">Frame Bent / Misalignment</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="screw_issue" id="cov_screw"> <label class="form-check-label small" for="cov_screw">Screw Came Out / Loose</label></div>
                                    </div>
                                </div>

                                <!-- What's Covered (Lens Issues) -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><i class="bi bi-eye"></i> Lens Issues Covered</label>
                                    <div class="border rounded-3 p-3">
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="lens_coating" id="cov_coating"> <label class="form-check-label small" for="cov_coating">Lens Coating Peeling</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="lens_crack" id="cov_crack"> <label class="form-check-label small" for="cov_crack">Lens Cracked (non-impact)</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="wrong_power" id="cov_wrong_power"> <label class="form-check-label small" for="cov_wrong_power">Wrong Power / Prescription</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="lens_popout" id="cov_popout"> <label class="form-check-label small" for="cov_popout">Lens Pop-Out (not repairable)</label></div>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" value="photochromic" id="cov_photo"> <label class="form-check-label small" for="cov_photo">Photochromic Not Working</label></div>
                                    </div>
                                </div>

                                <!-- Warranty Exclusions -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><i class="bi bi-ban"></i> Exclusions (What's NOT Covered)</label>
                                    <textarea class="form-control" id="warranty_exclusions" rows="2" placeholder="List what is NOT covered..."></textarea>
                                    <small class="text-muted">e.g., Accidental drops, impact damage, normal wear and tear, scratches</small>
                                </div>

                                <!-- Warranty Terms -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><i class="bi bi-file-text"></i> Warranty Terms & Conditions</label>
                                    <textarea class="form-control" id="warranty_terms" rows="3" placeholder="Enter detailed warranty terms..."></textarea>
                                </div>

                                <!-- Claim Process -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><i class="bi bi-headset"></i> How to Claim Warranty</label>
                                    <textarea class="form-control" id="warranty_claim_process" rows="2" placeholder="Steps to claim warranty..."></textarea>
                                    <small class="text-muted">e.g., Submit photos, contact clinic, visit store</small>
                                </div>

                                <!-- Care Instructions -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold"><i class="bi bi-heart"></i> Care Instructions</label>
                                    <textarea class="form-control" id="warranty_care" rows="2" placeholder="How to care for this product..."></textarea>
                                </div>

                                <!-- Preview -->
                                <div class="col-12">
                                    <div class="alert alert-info py-2" id="warranty_preview" style="display:none;">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <strong>Warranty Summary:</strong> <span id="warranty_summary_text"></span>
                                        <span id="warranty_price_tag" class="badge bg-warning ms-2"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- 3D MODEL SECTION -->
                    <div class="section-card mb-4" style="border-color:var(--teal);" id="section3DCard">
                        <div class="section-card-head d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-cube me-2"></i>3D Model<span class="ms-2 small fw-normal" id="label3DNote" style="color:var(--text-muted);">(select a product first)</span></span>
                            <div class="form-check form-switch mb-0" id="toggle3DWrap" style="display:none!important;"><input class="form-check-input" type="checkbox" id="enable3DToggle" onchange="onToggle3D(this.checked)"><label class="form-check-label small" for="enable3DToggle" style="color:var(--text-soft);">Enable 3D</label></div>
                        </div>
                        <div class="section-card-body">
                            <div id="non3DNote" class="model-section-note"><i class="bi bi-info-circle"></i>3D model is available for <strong>Frames</strong> only. Select a Frame item to enable this feature.</div>
                            <div id="model3DOptions" style="display:none;">
                                <div class="mb-4"><div class="form-check"><input class="form-check-input" type="radio" name="modelOption" id="optionDefault" value="default" checked onclick="toggleModelOption()"><label class="form-check-label fw-semibold" for="optionDefault"><i class="bi bi-check-circle-fill text-success"></i> Use Default 3D Model (Free)</label></div><div class="ms-4 mt-2" id="defaultModelSection"><label class="form-label fw-semibold">Choose Frame Style:</label><select class="form-select mb-3" id="default_model_id" onchange="previewDefaultFrame3D(this.value)"><option value="">-- Select a frame style --</option><?php foreach ($defaultModels as $model): ?><option value="<?= $model['id'] ?>" data-path="<?= htmlspecialchars($model['model_file']) ?>" data-name="<?= htmlspecialchars($model['model_name']) ?>"><?= htmlspecialchars($model['model_name']) ?></option><?php endforeach; ?></select><div id="default-frame-preview" class="frame-preview-container" style="display:none;"><div class="preview-controls"><button type="button" onclick="rotateDefaultPreview('left')" title="Rotate Left"><i class="bi bi-arrow-left"></i></button><button type="button" onclick="rotateDefaultPreview('right')" title="Rotate Right"><i class="bi bi-arrow-right"></i></button><button type="button" onclick="resetDefaultPreview()" title="Reset View"><i class="bi bi-arrow-repeat"></i></button></div></div><div class="text-center mt-2" id="expandPreviewBtn" style="display:none;"><button type="button" class="btn-outline-teal btn-sm" onclick="expandDefaultPreview()"><i class="bi bi-arrows-fullscreen"></i> View Full Screen</button></div><div id="default-frame-colors" class="color-palette" style="display:none;"></div><small class="text-muted mt-2 d-block">20+ default frames available instantly!</small></div></div>
                                <div class="mb-4"><div class="form-check"><input class="form-check-input" type="radio" name="modelOption" id="optionRequest" value="request" onclick="toggleModelOption()"><label class="form-check-label fw-semibold" for="optionRequest"><i class="bi bi-envelope-paper"></i> Request Custom 3D Model (₱100/model)</label></div><div class="ms-4 mt-2" id="requestModelSection" style="display:none;"><div class="alert alert-info small"><strong>Custom 3D Modeling Service</strong> — ₱100.00 per model (includes 5 color variations)<br><small>Processing time: 2-3 business days after payment</small></div><div class="row g-3"><div class="col-md-6"><label class="form-label fw-semibold">Model Type <span class="text-danger">*</span></label><select class="form-select" id="request_model_type"><option value="">-- Select Type --</option><option value="frame">👓 Frames</option><option value="contact_lens">👁️ Contact Lens</option></select></div><div class="col-md-6"><label class="form-label fw-semibold">Frame/Brand Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="request_product_name" placeholder="e.g., Ray-Ban Aviator"></div><div class="col-12"><label class="form-label fw-semibold">Upload Reference Photos <span class="text-danger">*</span></label><div class="border rounded-3 p-3 text-center" style="cursor:pointer;background:var(--bg);" onclick="document.getElementById('requestImages').click()"><i class="bi bi-camera fs-2 text-muted"></i><div class="small text-muted mt-1">Upload photos (front, side, angle)</div></div><input type="file" id="requestImages" class="d-none" multiple accept="image/*" onchange="previewRequestImages(this)"><div class="row g-2 mt-2" id="requestImagePreviewContainer"></div></div><div class="col-12"><label class="form-label fw-semibold">Additional Notes</label><textarea class="form-control" id="request_notes" rows="2" placeholder="Material details, logo placement, color specs..."></textarea></div><div class="col-12"><label class="form-label fw-semibold">Desired Colors</label><input type="text" class="form-control" id="request_colors" placeholder="e.g., Black, Gold, Silver"></div></div><div class="section-card mt-3" style="border-color:var(--teal);"><div class="section-card-head"><i class="bi bi-credit-card"></i> Payment Method</div><div class="section-card-body"><div class="d-flex gap-3 mb-3"><div class="form-check"><input class="form-check-input" type="radio" name="payment_method" id="payment_gcash" value="gcash" checked><label class="form-check-label" for="payment_gcash">GCash</label></div><div class="form-check"><input class="form-check-input" type="radio" name="payment_method" id="payment_maya" value="maya"><label class="form-check-label" for="payment_maya">Maya</label></div><div class="form-check"><input class="form-check-input" type="radio" name="payment_method" id="payment_bank" value="bank"><label class="form-check-label" for="payment_bank">Bank Transfer</label></div></div><div id="gcash_details" class="p-3 rounded" style="background:var(--bg);"><p class="mb-1 small"><strong>GCash:</strong> 0917 123 4567 — Eyecore 3D Services</p><small class="text-muted">Send exact amount: ₱100.00</small></div><div id="maya_details" class="p-3 rounded" style="background:var(--bg);display:none;"><p class="mb-1 small"><strong>Maya:</strong> 0918 765 4321 — Eyecore 3D Services</p><small class="text-muted">Send exact amount: ₱100.00</small></div><div id="bank_details" class="p-3 rounded" style="background:var(--bg);display:none;"><p class="mb-1 small"><strong>BDO:</strong> 001234567890 — Eyecore 3D Services</p><small class="text-muted">Send exact amount: ₱100.00</small></div><div class="mt-3"><label class="form-label fw-semibold small">Upload Payment Proof</label><input type="file" class="form-control form-control-sm" id="payment_proof" accept="image/*"></div></div></div><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="terms_agree"><label class="form-check-label small" for="terms_agree">I understand this is a paid service (₱100) processed after payment confirmation.</label></div></div></div>
<div><div class="form-check"><input class="form-check-input" type="radio" name="modelOption" id="optionMyModels" value="mymodels" onclick="toggleModelOption()"><label class="form-check-label fw-semibold" for="optionMyModels"><i class="bi bi-cube" style="color:var(--teal);"></i> My Completed 3D Models</label></div>
<div class="ms-4 mt-2" id="myModelsSection" style="display:none;">
    <?php 
    try { 
        $stmt = $pdo->prepare("SELECT r.*, DATE_FORMAT(r.completed_at,'%M %d, %Y') as completed_date FROM custom_3d_requests r WHERE r.clinic_id=? AND r.status='completed' ORDER BY r.completed_at DESC LIMIT 20"); 
        $stmt->execute([$clinicId]); 
        $completedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC); 
    } catch (Exception $e) { 
        $completedRequests = []; 
    } 
    ?>
    
    <?php if (empty($completedRequests)): ?>
        <div class="empty-models-state text-center p-5 bg-light rounded-3">
            <i class="bi bi-cube fs-1 text-muted mb-3 d-block"></i>
            <h6 class="fw-semibold">No Completed 3D Models Yet</h6>
            <p class="text-muted small mb-3">Complete a 3D model request first and it will appear here.</p>
            <button type="button" class="btn-outline-teal btn-sm" onclick="$('#optionRequest').prop('checked',true); toggleModelOption();">
                <i class="bi bi-plus-circle me-1"></i> Request Custom Model
            </button>
        </div>
    <?php else: ?>
        <div class="completed-models-header mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                    <span class="fw-semibold small">My Completed 3D Models</span>
                    <span class="badge bg-success ms-2"><?= count($completedRequests) ?></span>
                </div>
                <small class="text-muted"><i class="bi bi-info-circle"></i> Select a model to attach to this product</small>
            </div>
        </div>
        
        <div class="completed-models-grid" style="max-height: 400px; overflow-y: auto; padding-right: 5px;">
            <?php foreach ($completedRequests as $req): ?>
                <div class="completed-model-card mb-3 p-3 border rounded-3 hover-shadow" style="transition: all 0.2s ease; cursor: pointer;" onclick="selectCompletedModel(<?= $req['id'] ?>, '<?= htmlspecialchars($req['product_name']) ?>')">
                    <div class="d-flex align-items-start gap-3">
                        <!-- Model Preview Icon -->
                        <div class="model-preview-icon flex-shrink-0" style="width: 60px; height: 60px; background: linear-gradient(135deg, #e0f2f2, #b8e0e0); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-cube fs-2" style="color: #008080;"></i>
                        </div>
                        
                        <!-- Model Info -->
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($req['product_name']) ?></div>
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-calendar-check me-1"></i>Completed: <?= $req['completed_date'] ?>
                                        <?php if (!empty($req['colors_requested'])): ?>
                                            <span class="ms-2"><i class="bi bi-palette me-1"></i><?= htmlspecialchars($req['colors_requested']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation(); viewCompletedModel(<?= $req['id'] ?>)" title="Preview 3D Model">
                                        <i class="bi bi-eye"></i> Preview
                                    </button>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="radio" name="completed_model_id" id="cm_<?= $req['id'] ?>" value="<?= $req['id'] ?>" data-file="<?= htmlspecialchars($req['completed_model_file'] ?? '') ?>" data-name="<?= htmlspecialchars($req['product_name']) ?>" onclick="event.stopPropagation();">
                                        <label class="form-check-label small" for="cm_<?= $req['id'] ?>">Select</label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Selected Badge (shown when selected) -->
                            <div id="selected_badge_<?= $req['id'] ?>" class="mt-2" style="display: none;">
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle-fill me-1"></i> Selected
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Selected Model Preview Section -->
        <div id="selectedModelPreview" class="mt-3 p-3 bg-light rounded-3" style="display: none;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <span class="fw-semibold">Selected Model:</span>
                <span id="selectedModelName" class="small"></span>
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="clearSelectedModel()">
                    <i class="bi bi-x-lg"></i> Clear
                </button>
            </div>
        </div>
        
        <small class="text-muted mt-3 d-block">
            <i class="bi bi-info-circle-fill me-1"></i>
            Selected 3D model will be attached to this product and visible in the product viewer.
        </small>
    <?php endif; ?>
</div></div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="selected_item_id">
                    <input type="hidden" id="selected_source">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-teal px-4" onclick="postProduct()"><i class="bi bi-cloud-upload me-2"></i>Post Product</button>
            </div>
        </div>
    </div>
</div>

<!-- FRAME VIEWER MODAL -->
<div class="modal fade" id="frameViewerModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header teal-header py-2"><h5 class="modal-title"><i class="bi bi-eye me-2"></i>Frame Preview</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-0"><div id="frame-viewer-container" style="width:100%;height:500px;background:#f5f5f5;"></div></div>
            <div class="modal-footer py-2"><div class="d-flex justify-content-between align-items-center w-100 flex-wrap gap-2"><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" type="button" onclick="rotateFrame('left')"><i class="bi bi-arrow-left-circle"></i> Left</button><button class="btn btn-outline-secondary" type="button" onclick="rotateFrame('right')"><i class="bi bi-arrow-right-circle"></i> Right</button><button class="btn btn-outline-secondary" type="button" onclick="resetFrameView()"><i class="bi bi-arrow-repeat"></i> Reset</button><button class="btn btn-outline-secondary" type="button" onclick="toggleWireframe()"><i class="bi bi-grid-3x3"></i> Wireframe</button></div><div id="frameColorPalette" class="d-flex gap-2 flex-wrap"></div></div></div>
        </div>
    </div>
</div>

<script>
const CATEGORY_FIELDS = <?= json_encode($categoryFields) ?>;
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>
<script>
// ============================================================
// RBAC PERMISSIONS
// ============================================================
const permissions = {
    canView:    <?= json_encode($canView) ?>,
    canCreate:  <?= json_encode($canCreate) ?>,
    canEdit:    <?= json_encode($canEdit) ?>,
    canDelete:  <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject:  <?= json_encode($canReject) ?>
};
const clinicId = <?= $clinicId ?>;

// ============================================================
// GLOBALS
// ============================================================
let frameScene, frameCamera, frameRenderer, frameModel, frameControls;
let defaultPreviewScene, defaultPreviewCamera, defaultPreviewRenderer, defaultPreviewModel, defaultPreviewControls;
let selectedProductImages = [], selectedRequestImages = [];
let allProducts = [], allServices = [];
let colorRowCount = 0;
let currentDefaultModelPath = null;
let currentCategory = '';
let originalLensColors = new Map(); // Store original lens colors for frame viewer

// ============================================================
// WARRANTY TEMPLATES - DETAILED
// ============================================================

const WARRANTY_TEMPLATES = {
    basic: {
        exclusions: "Accidental damage, drops, impact, misuse, normal wear and tear, scratches, lost or stolen items.",
        terms: "✓ Warranty valid only with original receipt\n✓ Covers manufacturing defects only\n✓ Customer responsible for shipping costs\n✓ Claim must be submitted within warranty period",
        claim_process: "1. Contact the clinic via chat\n2. Submit photos of the issue\n3. Present original receipt\n4. Clinic will inspect and validate",
        care: "• Never keep glasses lens down\n• Always use case for storage\n• Use microfiber cloth for cleaning\n• Use lens cleaner only"
    },
    standard: {
        exclusions: "❌ Accidental drops or impact damage\n❌ Misuse or improper storage\n❌ Normal wear and tear (scratches, fading)\n❌ Loss or theft of product\n❌ Unauthorized repairs or modifications",
        terms: "✅ WARRANTY COVERAGE:\n- Manufacturing defects identified during product evaluation\n- Frame breakage due to material defect (not accidental drops)\n- Lens coating peeling under normal use\n- Hinge mechanism failure (not due to impact)\n\n⏰ All warranty claims are subject to inspection and validation by the clinic team. If the issue is found to be customer-induced, the request will not be considered for warranty.",
        claim_process: "📋 CLAIM PROCESS:\n1. Submit 2-3 clear photos of the product showing the issue\n2. Contact the clinic via chat or visit the clinic\n3. Present original receipt/order confirmation\n4. Clinic will inspect and validate the claim\n5. Resolution within 5-7 business days",
        care: "🧼 CARE INSTRUCTIONS:\n• Never keep glasses lens down\n• Always use case for storage\n• Always use microfiber cloth for cleaning\n• Use lens cleaner and not any other liquid"
    },
    premium: {
        exclusions: "❌ Lost or stolen items\n❌ Unauthorized repairs\n❌ Intentional damage\n❌ Normal wear and tear after 24 months",
        terms: "✅ PREMIUM WARRANTY COVERAGE:\n- All manufacturing defects\n- Frame breakage (any cause except intentional)\n- Lens coating for 24 months\n- Hinge and temple damage\n- Free adjustment and cleaning\n- Express replacement within 3 days",
        claim_process: "📋 CLAIM PROCESS:\n1. Visit any clinic location\n2. Present warranty certificate\n3. Express replacement within 3 days\n4. Free shipping for replacement",
        care: "🧼 PREMIUM CARE:\n• Free lifetime cleaning service\n• Free adjustments anytime\n• Priority customer support"
    },
    lenskart: {
        exclusions: "The warranty coverage mentioned in the policy is applicable only for manufacturing defects identified during product evaluation. Warranty does not cover damage caused due to customer handling, accidental drops, impact, pressure, misuse, or normal wear and tear.\n\nPolicy exclusions:\n• Frame breakages including temples, hinges, nose bridge etc\n• Lens breakages/ cracks/ chipping off/ thickness\n• Scratches on lens or frame\n• Any fitment or size related issues (covered only within 14 days)\n• Change in eye powers (covered only within 14 days)\n• Temple sleeves falling out\n• Misprinting of brand LOGO\n• Any other wear and tear\n• Contact Lenses are not covered under warranty",
        terms: "All warranty claims are subject to inspection and validation by the clinic expert team. If the issue is found to be customer-induced or falls under policy exclusions, the request will not be considered for warranty replacement or exchange, even if the issue type and duration appear covered in the table.\n\nWarranty period starts from the date of product delivery. Warranty for an exchange order is limited to the period of master order only and will not get refreshed.\n\nOnce warranty is approved, customers should be able to place an exchange order for any product and lens package combination restricted to the value of master order. If the same frame or lens package is not available, the company shall not be responsible.",
        claim_process: "📋 HOW TO CLAIM WARRANTY:\n\nCustomers are required to submit 3-4 clear pictures of the product along with their order ID and contact details. Customer can connect on chat support and submit their pictures.\n\nThe team will review the claim and revert within 24 to 48 working hours.\n\nComplete warranty claim process involves validation of defects through the expert team and hence the complete process could take 5-7 working days. Updates and status on your claim shall be sent via email.\n\nFor issues which are repairable, customers are required to visit any of the nearby stores and claim repair services Free Of Cost.\n\nIn case insurance was purchased with the order and customers wish to claim the same, they are advised to visit the nearest store along with KYC document.",
        care: "🧼 CARE INSTRUCTIONS:\n\n• Never keep glasses lens down\n• Always use case for storage\n• Always use selvet for cleaning\n• Use lens cleaner and not any other liquid\n\nAs your eyeglasses are a product of daily usage, kindly follow the cleaning and maintenance protocol."
    }
};

function loadWarrantyTemplate() {
    const template = $('#warranty_template').val();
    if (!template || !WARRANTY_TEMPLATES[template]) return;
    
    const data = WARRANTY_TEMPLATES[template];
    $('#warranty_exclusions').val(data.exclusions || '');
    $('#warranty_terms').val(data.terms || '');
    $('#warranty_claim_process').val(data.claim_process || '');
    $('#warranty_care').val(data.care || '');
    updateWarrantyPreview();
}

const colorSuggestions = {
    'red':'#FF0000','blue':'#0000FF','green':'#00FF00','yellow':'#FFFF00',
    'black':'#000000','white':'#FFFFFF','purple':'#800080','orange':'#FFA500',
    'pink':'#FFC0CB','brown':'#8B4513','gray':'#808080','grey':'#808080',
    'silver':'#C0C0C0','gold':'#FFD700','navy':'#000080','teal':'#008080',
    'maroon':'#800000','olive':'#808000','lime':'#00FF00','cyan':'#00FFFF',
    'magenta':'#FF00FF','violet':'#EE82EE','indigo':'#4B0082','turquoise':'#40E0D0',
    'beige':'#F5F5DC','coral':'#FF7F50','lavender':'#E6E6FA','mint':'#98FB98','peach':'#FFDAB9'
};
const codeToName = {};
Object.keys(colorSuggestions).forEach(k => { codeToName[colorSuggestions[k]] = k; });


// ============================================================
// LENS DETECTION HELPER
// ============================================================
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

// ============================================================
// PERMISSION HELPERS
// ============================================================
function requirePermission(perm, action) {
    if (!permissions[perm]) {
        Swal.fire({ icon:'warning', title:'Access Denied', html:`You don't have permission to <strong>${action}</strong> products.`, confirmButtonColor:'#008080' });
        return false;
    }
    return true;
}

// ============================================================
// TAB SWITCH
// ============================================================
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
}



// ============================================================
// LOAD & RENDER PRODUCTS
// ============================================================
function loadProducts() {
    $.ajax({
        url: 'api/products.php?action=get_products',
        success: function (res) {
            if (res.success) {
                allProducts = res.data || [];
                $('#products-count').text(allProducts.length);
                renderProducts(allProducts);
            } else {
                $('#productsGrid').html(`<div style="grid-column:1/-1;"><div class="empty-state"><div class="empty-icon"><i class="bi bi-exclamation-triangle"></i></div><h5>Failed to load products</h5><p>${res.message||'Unknown error'}</p></div></div>`);
            }
        },
        error: function () {
            $('#productsGrid').html(`<div style="grid-column:1/-1;"><div class="empty-state"><div class="empty-icon"><i class="bi bi-exclamation-triangle"></i></div><h5>Connection Error</h5><p>Please refresh the page.</p></div></div>`);
        }
    });
}

function filterProducts() {
    const q   = $('#productSearch').val().toLowerCase();
    const cat = $('#productCatFilter').val().toLowerCase();
    renderProducts(allProducts.filter(p =>
        (!q   || (p.name||'').toLowerCase().includes(q) || (p.brand||'').toLowerCase().includes(q)) &&
        (!cat || (p.category||'').toLowerCase().includes(cat))
    ));
}

function renderProducts(products) {
    if (!products || !products.length) {
        $('#productsGrid').html(`<div style="grid-column:1/-1;"><div class="empty-state"><div class="empty-icon"><i class="bi bi-box"></i></div><h5>No Products Found</h5><p>Post your first product from inventory.</p>${permissions.canCreate?`<button class="btn-teal" onclick="openPostModal()"><i class="bi bi-plus-lg"></i> Post Product</button>`:''}</div></div>`);
        return;
    }
    let html = '';
    products.forEach(p => {
        const stock      = p.stock || 0;
        const stockBadge = stock <= 0 ? '<span class="badge-stock-out">Out of Stock</span>'
                         : (stock < 10 ? '<span class="badge-stock-low">Low Stock</span>'
                         : '<span class="badge-stock-ok">In Stock</span>');

        const has3d = (p.has_3d == 1 || p.has_3d === true || p.has_3d === '1');
        const badge3d = has3d ? '<span class="badge-3d"><i class="bi bi-cube"></i> 3D</span>' : '';

        const orig    = parseFloat(p.price) || 0;
        const saleP   = parseFloat(p.sale_price) || 0;
        const today   = new Date();
        let saleValid = false;
        if (p.is_on_sale == 1 && saleP > 0 && saleP < orig) {
            if (p.sale_start && p.sale_end) {
                if (today >= new Date(p.sale_start) && today <= new Date(p.sale_end)) saleValid = true;
            } else { saleValid = true; }
        }
        let priceHtml = '', saleBadge = '';
        if (saleValid) {
            const pct = Math.round(((orig - saleP) / orig) * 100);
            priceHtml = `<div><span class="card-price-old">₱${orig.toLocaleString()}</span><br><span class="card-price sale">₱${saleP.toLocaleString()}</span></div>`;
            saleBadge = `<span class="badge-sale">${pct}% OFF</span>`;
        } else {
            priceHtml = `<span class="card-price">₱${orig.toLocaleString()}</span>`;
        }

        const approvalStatus = p.approval_status || 'approved';
        let approvalBadge = '';
        if (approvalStatus === 'pending')  approvalBadge = '<span class="badge-pending"><i class="bi bi-clock me-1"></i>Pending</span>';
        if (approvalStatus === 'rejected') approvalBadge = '<span class="badge-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>';

        let imgHtml = '';
        if (p.images_json) {
            try {
                const imgs = JSON.parse(p.images_json);
                if (imgs && imgs.length) imgHtml = `<img class="card-img" src="/${imgs[0]}" onerror="this.parentNode.innerHTML='<div class=card-img-placeholder>📦</div>'">`;
            } catch (e) {}
        }
        if (!imgHtml) {
            const icon = p.category === 'Frames' ? '👓' : (p.category === 'Contact Lenses' ? '👁️' : '📦');
            imgHtml = `<div class="card-img-placeholder">${icon}</div>`;
        }

        let actionButtons = '';
        if (has3d) {
            actionButtons += `<button class="btn-outline-teal btn-sm" style="flex:1;" onclick="viewFrameOnly(${p.id})"><i class="bi bi-eye"></i> View 3D</button>`;
        }
        if (permissions.canEdit)   actionButtons += `<button class="btn-sm-icon" onclick="editProduct(${p.id})" title="Edit"><i class="bi bi-pencil"></i></button>`;
        if (permissions.canDelete) actionButtons += `<button class="btn-sm-icon danger" onclick="deleteProduct(${p.id}, '${htmlEsc(p.name)}')" title="Delete"><i class="bi bi-trash"></i></button>`;
        if (permissions.canApprove && approvalStatus === 'pending') actionButtons += `<button class="btn-sm-icon" style="border-color:#28a745;color:#28a745;" onclick="approveProduct(${p.id})" title="Approve"><i class="bi bi-check-lg"></i></button>`;
        if (permissions.canReject  && approvalStatus === 'pending') actionButtons += `<button class="btn-sm-icon danger" onclick="rejectProduct(${p.id})" title="Reject"><i class="bi bi-slash-circle"></i></button>`;
        if (!actionButtons) actionButtons = `<span class="text-muted small w-100 text-center"><i class="bi bi-eye"></i> View Only</span>`;

        let colors = [];
        if (p.colors_json) {
            try {
                colors = JSON.parse(p.colors_json);
            } catch(e) {}
        } else if (p.colors) {
            try {
                colors = typeof p.colors === 'string' ? JSON.parse(p.colors) : p.colors;
            } catch(e) {}
        }

        html += `<div class="product-card">
            <div class="position-relative">${imgHtml}
                <div style="position:absolute;top:10px;right:10px;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">${badge3d}${saleBadge}${stockBadge}${approvalBadge}</div>
            </div>
            <div class="card-body">
                <div class="card-name">${htmlEsc(p.name||'—')}</div>
                <div class="card-brand">${htmlEsc(p.brand||'No brand')}</div>
                <div class="card-meta"><span class="badge-cat">${htmlEsc(p.category||'')}</span>${priceHtml}</div>
                ${displayColorSwatches(colors)}
            </div>
            <div class="card-footer">${actionButtons}</div>
        </div>`;
    });
    $('#productsGrid').html(html);
}

function displayColorSwatches(colors) {
    if (!colors || !colors.length) return '';
    let html = '<div style="margin-top:8px;">';
    colors.forEach(c => { html += `<span class="color-swatch" style="background:${c.code};" title="${htmlEsc(c.name)}"></span>`; });
    return html + '</div>';
}

function htmlEsc(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ============================================================
// SERVICES
// ============================================================
function loadServices() {
    $.ajax({
        url: 'api/services.php?action=get_services',
        success: function (res) {
            if (res.success) {
                allServices = res.data || [];
                $('#services-count').text(allServices.length);
                renderServices(allServices);
            }
        }
    });
}

function filterServices() {
    const q   = $('#serviceSearch').val().toLowerCase();
    const cat = $('#serviceCatFilter').val().toLowerCase();
    renderServices(allServices.filter(s =>
        (!q   || (s.name||'').toLowerCase().includes(q) || (s.description||'').toLowerCase().includes(q)) &&
        (!cat || (s.category||'').toLowerCase().includes(cat))
    ));
}

function getCatClass(cat) {
    const m = {'Eye Exam':'cat-eye-exam','Eyeglasses':'cat-eyeglasses','Contact Lens':'cat-contact-lens','Treatment':'cat-treatment','Screening':'cat-screening'};
    return m[cat] || 'cat-other';
}

function renderServices(services) {
    if (!services || !services.length) {
        $('#servicesBody').html(`<td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-clipboard2-pulse"></i></div><h5>No Services Yet</h5><p>Add clinic services.</p>${permissions.canCreate?`<button class="btn-teal" onclick="openServiceModal()"><i class="bi bi-plus-lg"></i> Add Service</button>`:''}</div></td>`);
        return;
    }
    let html = '';
    services.forEach(s => {
        const cat   = s.category || 'Other';
        const price = parseFloat(s.price) || 0;
        const statusBadge = (s.status||'active')==='active' ? '<span class="badge-stock-ok">Active</span>' : '<span style="background:#f8d7da;color:#721c24;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:600;">Inactive</span>';
        const dur = s.duration_minutes ? `<span class="service-duration"><i class="bi bi-clock"></i> ${s.duration_minutes} min</span>` : '<span class="text-muted small">—</span>';
        const editBtn   = permissions.canEdit   ? `<button class="btn-sm-icon" onclick="editService(${s.id})" title="Edit"><i class="bi bi-pencil"></i></button>` : `<button class="btn-sm-icon" disabled><i class="bi bi-pencil"></i></button>`;
        const deleteBtn = permissions.canDelete ? `<button class="btn-sm-icon danger" onclick="deleteService(${s.id},'${htmlEsc(s.name)}')" title="Delete"><i class="bi bi-trash"></i></button>` : `<button class="btn-sm-icon" disabled><i class="bi bi-trash"></i></button>`;
        
        // ✅ FIXED: Proper HTML string using template literals
        html += `
            <tr>
                <td><div class="service-name">${htmlEsc(s.name)}</div><div class="service-desc">${htmlEsc(s.description||'')}</div></td>
                <td><span class="cat-pill ${getCatClass(cat)}">${htmlEsc(cat)}</span></td>
                <td><span class="service-price">₱${price.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></td>
                <td>${dur}</td>
                <td>${statusBadge}</td>
                <td style="text-align:right;"><div class="d-flex justify-content-end gap-1">${editBtn}${deleteBtn}</div></td>
            </tr>
        `;
    });
    $('#servicesBody').html(html);
}

function openServiceModal() {
    if (!requirePermission('canCreate','add')) return;
    $('#service_id,#service_name,#service_description,#service_price,#service_duration').val('');
    $('#service_category').val('');
    $('#service_status').val('active');
    $('#serviceModalTitle').html('<i class="bi bi-clipboard2-pulse me-2"></i>Add New Service');
    new bootstrap.Modal(document.getElementById('serviceModal')).show();
}

function editService(id) {
    if (!requirePermission('canEdit','edit')) return;
    const s = allServices.find(x => x.id == id);
    if (!s) return;
    $('#service_id').val(s.id);
    $('#service_name').val(s.name);
    $('#service_description').val(s.description||'');
    $('#service_price').val(s.price);
    $('#service_duration').val(s.duration_minutes||'');
    $('#service_category').val(s.category||'');
    $('#service_status').val(s.status||'active');
    $('#serviceModalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Service');
    new bootstrap.Modal(document.getElementById('serviceModal')).show();
}

function saveService() {
    const isEdit = !!$('#service_id').val();
    if (isEdit && !requirePermission('canEdit','edit')) return;
    if (!isEdit && !requirePermission('canCreate','create')) return;
    const name = $('#service_name').val().trim(), cat = $('#service_category').val(), price = $('#service_price').val();
    if (!name||!cat||!price) { Swal.fire({icon:'warning',title:'Missing Fields',text:'Name, category, and price are required.',confirmButtonColor:'#008080'}); return; }
    const data = { id:$('#service_id').val()||null, name, category:cat, description:$('#service_description').val().trim(), price, duration_minutes:$('#service_duration').val()||null, status:$('#service_status').val() };
    const action = data.id ? 'update_service' : 'add_service';
    $.ajax({
        url:'api/services.php', method:'POST',
        data:JSON.stringify({action,...data}), contentType:'application/json',
        success:function(res){ if(res.success){ bootstrap.Modal.getInstance(document.getElementById('serviceModal')).hide(); loadServices(); Swal.fire({icon:'success',title:'Saved!',text:res.message,timer:1800,showConfirmButton:false}); } else { Swal.fire({icon:'error',title:'Error',text:res.message,confirmButtonColor:'#008080'}); } },
        error:function(){ Swal.fire({icon:'error',title:'Error',text:'Connection error',confirmButtonColor:'#008080'}); }
    });
}

function deleteService(id, name) {
    if (!requirePermission('canDelete','delete')) return;
    Swal.fire({title:'Delete Service?',html:`Remove <strong>${name}</strong>?`,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, Delete'}).then(r=>{
        if (!r.isConfirmed) return;
        $.ajax({url:'api/services.php',method:'POST',data:JSON.stringify({action:'delete_service',id}),contentType:'application/json',success:function(res){ if(res.success){loadServices();Swal.fire({icon:'success',title:'Deleted',timer:1500,showConfirmButton:false});}else{Swal.fire({icon:'error',text:res.message});}},error:function(){Swal.fire({icon:'error',text:'Connection error'});}});
    });
}

// ============================================================
// PRODUCT MODAL OPEN
// ============================================================
function openPostModal() {
    if (!requirePermission('canCreate','post')) return;
    resetPostForm();
    loadInventoryItems();
    new bootstrap.Modal(document.getElementById('postProductModal')).show();
}

function loadInventoryItems() {
    $.ajax({
        url: 'api/products.php?action=get_inventory_items',
        success: function (res) {
            if (res.success && res.data && res.data.length) {
                let opts = '<option value="">-- Select an item --</option>';
                res.data.forEach(item => {
                    const icon = item.category === 'Frames' ? '👓' : (item.category === 'Contact Lenses' ? '👁️' : '📦');
                    const colorsData = item.colors ? JSON.stringify(item.colors) : '[]';
                    // ✅ Store product_details as JSON string in attribute
                    const productDetailsData = item.product_details ? JSON.stringify(item.product_details) : '{}';
                    
                    console.log(`Item: ${item.name}, Product Details:`, item.product_details);
                    
                    opts += `<option value="${item.id}"
                        data-category="${htmlEsc(item.category)}"
                        data-brand="${htmlEsc(item.brand||'')}"
                        data-price="${item.price}"
                        data-stock="${item.stock}"
                        data-source="${item.source}"
                        data-colors='${colorsData}'
                        data-product-details='${productDetailsData}'>
                        ${icon} ${htmlEsc(item.name)} - ${htmlEsc(item.brand||'No Brand')} (₱${item.price}) [Stock: ${item.stock}]
                    </option>`;
                });
                $('#selectItem').html(opts);
            } else {
                $('#selectItem').html('<option value="">-- No inventory items available --</option>');
            }
        },
        error: function () {
            $('#selectItem').html('<option value="">Connection error — please refresh</option>');
        }
    });
}
function loadItemDetails() {
    const sel = document.getElementById('selectItem');
    const opt = sel.options[sel.selectedIndex];

    if (!opt.value) {
        $('#product_name,#product_category,#product_brand,#product_price,#product_stock,#product_source,#selected_item_id,#selected_source').val('');
        currentCategory = '';
        update3DSection('');
        updateCategoryExtraFieldsFromInventory('', null);
        clearColorRows();
        resetWarrantyForm();
        return;
    }

    const rawName = opt.text.split(' - ')[0].replace(/[👓👁️📦]/g,'').trim();
    const category = opt.getAttribute('data-category') || '';
    const inventoryId = opt.value;

    $('#product_name').val(rawName);
    $('#product_category').val(category);
    $('#product_brand').val(opt.getAttribute('data-brand') || 'N/A');
    $('#product_price').val(opt.getAttribute('data-price'));
    $('#product_stock').val(opt.getAttribute('data-stock'));
    $('#product_source').val(opt.getAttribute('data-source') === 'inventory' ? '📦 Inventory' : '📋 Other');
    $('#selected_item_id').val(opt.value);
    $('#selected_source').val(opt.getAttribute('data-source'));
    document.getElementById('originalPriceDisplay').textContent = parseFloat(opt.getAttribute('data-price')||0).toFixed(2);

    currentCategory = category;
    update3DSection(category);
    
    // ✅ TRY TO GET product_details FROM data-product-details ATTRIBUTE FIRST
    let productDetails = null;
    const productDetailsAttr = opt.getAttribute('data-product-details');
    if (productDetailsAttr && productDetailsAttr !== '{}') {
        try {
            productDetails = JSON.parse(productDetailsAttr);
            console.log('Product details from attribute:', productDetails);
        } catch(e) {
            console.error('Error parsing product details:', e);
        }
    }
    
    // If not in attribute, fetch from API
    if (productDetails) {
        updateCategoryExtraFieldsFromInventory(category, productDetails);
    } else {
        fetchInventoryItemDetails(inventoryId, category);
    }
    
    const colorsAttr = opt.getAttribute('data-colors');
    if (colorsAttr && colorsAttr !== '[]') {
        try {
            const inventoryColors = JSON.parse(colorsAttr);
            if (inventoryColors && inventoryColors.length > 0) {
                clearColorRows();
                loadColorsFromInventory(inventoryColors);
            }
        } catch(e) {
            console.error('Error parsing colors:', e);
        }
    }
    
    triggerWarrantyAutoSuggest();
}

function fetchInventoryItemDetails(inventoryId, category) {
    $.ajax({
        url: `api/inventory.php?action=get_item&id=${inventoryId}`,
        method: 'GET',
        success: function(response) {
            if (response.success && response.data) {
                const item = response.data;
                // Pass the product_details from inventory
                updateCategoryExtraFieldsFromInventory(category, item.product_details);
            } else {
                updateCategoryExtraFieldsFromInventory(category, null);
            }
        },
        error: function() {
            updateCategoryExtraFieldsFromInventory(category, null);
        }
    });
}



function clearColorRows() {
    const container = document.getElementById('colorRowsContainer');
    if (container) {
        container.innerHTML = `
            <div class="text-center text-muted py-4" id="noColorsMessage">
                <i class="bi bi-palette fs-1 mb-3 opacity-50 d-block"></i>
                <p class="mb-2">No colors added yet.</p>
                <button type="button" class="btn-teal btn-sm" onclick="addColorRow()">
                    <i class="bi bi-plus-lg me-1"></i>Add Your First Color
                </button>
            </div>`;
    }
    colorRowCount = 0;
}

function loadColorsFromInventory(colors) {
    if (!colors || colors.length === 0) return;
    
    const container = document.getElementById('colorRowsContainer');
    const noMsg = document.getElementById('noColorsMessage');
    if (noMsg) noMsg.style.display = 'none';
    
    container.innerHTML = '';
    colorRowCount = 0;
    
    colors.forEach(color => {
        colorRowCount++;
        const rowId = `color_row_${colorRowCount}`;
        const row = document.createElement('div');
        row.className = 'color-row animate__animated animate__fadeIn';
        row.id = rowId;
        
        const colorName = color.name || color.color_name || '';
        const colorCode = color.code || color.color_code || '#FF0000';
        const quantity = color.quantity || 0;
        const isAvailable = color.is_available !== undefined ? color.is_available : true;
        
        row.innerHTML = `
            <div class="row align-items-center g-3">
                <div class="col-lg-3 col-md-12">
                    <div class="d-flex align-items-center gap-3">
                        <div class="color-preview" id="preview_${colorRowCount}" style="background-color:${colorCode};"></div>
                        <div class="flex-grow-1">
                            <label class="form-label small text-muted mb-1">Color Name</label>
                            <input type="text" class="form-control form-control-sm color-name-input"
                                   id="color_name_${colorRowCount}" value="${htmlEsc(colorName)}"
                                   oninput="updateColorFromName('${colorRowCount}', this.value)"
                                   list="colorSuggestionsList">
                            <datalist id="colorSuggestionsList">
                                ${Object.keys(colorSuggestions).map(c=>`<option value="${c.charAt(0).toUpperCase()+c.slice(1)}">`).join('')}
                            </datalist>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="form-label small text-muted mb-1">Color Code</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" class="form-control form-control-sm color-code-input"
                               id="color_code_${colorRowCount}" value="${colorCode}"
                               onchange="updateColorFromCode('${colorRowCount}', this.value)">
                        <span class="color-code-text" id="code_text_${colorRowCount}">${colorCode}</span>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="form-label small text-muted mb-1">Quantity</label>
                    <input type="number" class="form-control form-control-sm color-quantity-input"
                           id="color_qty_${colorRowCount}" value="${quantity}" min="0"
                           onchange="updateTotalStock()"
                           onkeyup="updateTotalStock()">
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="form-label small text-muted mb-1">Status</label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               id="color_available_${colorRowCount}" ${isAvailable ? 'checked' : ''}
                               onchange="updateAvailabilityBadge('${colorRowCount}', this.checked)">
                        <label class="form-check-label" for="color_available_${colorRowCount}">
                            <span class="badge ${isAvailable ? 'bg-success' : 'bg-secondary'}" id="avail_badge_${colorRowCount}">${isAvailable ? 'Available' : 'Unavailable'}</span>
                        </label>
                    </div>
                </div>
                <div class="col-lg-2 col-md-8">
                    <label class="form-label small text-muted mb-1">Hex Value</label>
                    <code class="hex-value" id="hex_${colorRowCount}">${colorCode}</code>
                </div>
                <div class="col-lg-1 col-md-4">
                    <button type="button" class="btn-delete-color" onclick="removeColorRow('${rowId}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>`;
        container.appendChild(row);
    });
    setTimeout(() => {
        document.querySelectorAll('.color-row').forEach(row => row.classList.remove('animate__animated','animate__fadeIn'));
    }, 500);
    updateTotalStock();
}

function updateTotalStock() {
    const stockInput = document.getElementById('product_stock');
    const totalSpan = document.getElementById('totalStockFromColors');
    const alertDiv = document.getElementById('stockTotalAlert');
    
    if (!stockInput) return;
    
    const colorRows = document.querySelectorAll('#colorRowsContainer .color-row');
    let totalStock = 0;
    
    colorRows.forEach(row => {
        const rowIdMatch = row.id.match(/\d+$/);
        if (rowIdMatch) {
            const id = rowIdMatch[0];
            const qtyInput = document.getElementById(`color_qty_${id}`);
            if (qtyInput && qtyInput.value) {
                totalStock += parseInt(qtyInput.value) || 0;
            }
        }
    });
    
    stockInput.value = totalStock;
    
    if (totalSpan) totalSpan.textContent = totalStock;
    if (alertDiv) alertDiv.style.display = colorRows.length > 0 ? 'block' : 'none';
    
    return totalStock;
}

function addColorRow() {
    colorRowCount++;
    const container = document.getElementById('colorRowsContainer');
    const noMsg = document.getElementById('noColorsMessage');
    if (noMsg) noMsg.style.display = 'none';

    const rowId = `color_row_${colorRowCount}`;
    const row = document.createElement('div');
    row.className = 'color-row animate__animated animate__fadeIn';
    row.id = rowId;
    
    row.innerHTML = `
        <div class="row align-items-center g-3">
            <div class="col-lg-3 col-md-12">
                <div class="d-flex align-items-center gap-3">
                    <div class="color-preview" id="preview_${colorRowCount}" style="background-color:#FF0000;"></div>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Color Name</label>
                        <input type="text" class="form-control form-control-sm color-name-input"
                               placeholder="e.g., Red, Blue"
                               id="color_name_${colorRowCount}" value="Red"
                               oninput="updateColorFromName('${colorRowCount}', this.value)"
                               list="colorSuggestionsList">
                        <datalist id="colorSuggestionsList">
                            ${Object.keys(colorSuggestions).map(c=>`<option value="${c.charAt(0).toUpperCase()+c.slice(1)}">`).join('')}
                        </datalist>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">Color Code</label>
                <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-sm color-code-input"
                           id="color_code_${colorRowCount}" value="#FF0000"
                           onchange="updateColorFromCode('${colorRowCount}', this.value)">
                    <span class="color-code-text" id="code_text_${colorRowCount}">#FF0000</span>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">Quantity</label>
                <input type="number" class="form-control form-control-sm color-quantity-input"
                       id="color_qty_${colorRowCount}" value="1" min="0"
                       onchange="updateTotalStock()"
                       onkeyup="updateTotalStock()">
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">Status</label>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox"
                           id="color_available_${colorRowCount}" checked
                           onchange="updateAvailabilityBadge('${colorRowCount}', this.checked)">
                    <label class="form-check-label" for="color_available_${colorRowCount}">
                        <span class="badge bg-success" id="avail_badge_${colorRowCount}">Available</span>
                    </label>
                </div>
            </div>
            <div class="col-lg-2 col-md-8">
                <label class="form-label small text-muted mb-1">Hex Value</label>
                <code class="hex-value" id="hex_${colorRowCount}">#FF0000</code>
            </div>
            <div class="col-lg-1 col-md-4">
                <button type="button" class="btn-delete-color" onclick="removeColorRow('${rowId}')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(row);
    setTimeout(() => row.classList.remove('animate__animated','animate__fadeIn'), 500);
    updateTotalStock();
}

function updateColorFromName(rowId, colorName) {
    const code = colorSuggestions[colorName.toLowerCase().trim()];
    if (code) {
        document.getElementById(`color_code_${rowId}`).value = code;
        document.getElementById(`preview_${rowId}`).style.backgroundColor = code;
        document.getElementById(`code_text_${rowId}`).textContent = code;
        document.getElementById(`hex_${rowId}`).textContent = code;
        syncColorPaletteToPreview();
    }
}

function updateColorFromCode(rowId, colorCode) {
    document.getElementById(`preview_${rowId}`).style.backgroundColor = colorCode;
    document.getElementById(`code_text_${rowId}`).textContent = colorCode;
    document.getElementById(`hex_${rowId}`).textContent = colorCode;
    const match = Object.keys(codeToName).find(c => c.toUpperCase() === colorCode.toUpperCase());
    if (match) {
        const n = codeToName[match];
        const el = document.getElementById(`color_name_${rowId}`);
        if (el) el.value = n.charAt(0).toUpperCase() + n.slice(1);
    }
    syncColorPaletteToPreview();
}

function updateAvailabilityBadge(rowId, isAvailable) {
    const badge = document.getElementById(`avail_badge_${rowId}`);
    badge.className = isAvailable ? 'badge bg-success' : 'badge bg-secondary';
    badge.textContent = isAvailable ? 'Available' : 'Unavailable';
}

function removeColorRow(rowId) {
    Swal.fire({title:'Remove Color?',icon:'question',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, remove'}).then(result => {
        if (!result.isConfirmed) return;
        const row = document.getElementById(rowId);
        if (row) {
            row.classList.add('animate__animated','animate__fadeOut');
            setTimeout(() => {
                row.remove();
                const container = document.getElementById('colorRowsContainer');
                const noMsg = document.getElementById('noColorsMessage');
                if (container.querySelectorAll('.color-row').length === 0 && noMsg) noMsg.style.display = 'block';
                syncColorPaletteToPreview();
                updateTotalStock();
            }, 300);
        }
    });
}

function getAllColorRows() {
    const rows = document.querySelectorAll('#colorRowsContainer .color-row');
    const colors = [];
    rows.forEach(row => {
        const id = row.id.split('_').pop();
        const name = document.getElementById(`color_name_${id}`)?.value;
        const code = document.getElementById(`color_code_${id}`)?.value;
        const qty = document.getElementById(`color_qty_${id}`)?.value;
        const avail = document.getElementById(`color_available_${id}`)?.checked || false;
        
        if (name && code) {
            colors.push({ name: name, code: code, quantity: qty ? parseInt(qty) : 0, is_available: avail });
        }
    });
    return colors;
}

function syncColorPaletteToPreview() {
    const colorPalette = document.getElementById('default-frame-colors');
    if (colorPalette && colorPalette.style.display !== 'none') {
        createDefaultFrameColorPalette();
    }
}

// ============================================================
// SALE FIELDS
// ============================================================
function toggleSaleFields() {
    const on = document.getElementById('enableSale').checked;
    document.getElementById('saleFields').style.display = on ? 'block' : 'none';
    if (on) { document.getElementById('originalPriceDisplay').textContent = parseFloat($('#product_price').val()||0).toFixed(2); updateSalePreview(); }
}

function updateSalePreview() {
    const op = parseFloat($('#product_price').val()) || 0;
    const sp = parseFloat($('#sale_price').val()) || 0;
    const sl = $('#sale_label').val() || 'SALE';
    let h = '<strong>Preview:</strong> ';
    if (sp > 0 && sp < op) {
        const pct = Math.round(((op - sp) / op) * 100);
        h += `<span class="text-muted text-decoration-line-through me-2">₱${op.toFixed(2)}</span><span class="text-danger fw-bold">₱${sp.toFixed(2)}</span><span class="badge bg-danger ms-2">${pct}% OFF</span>`;
        if (sl !== 'SALE') h += `<span class="badge bg-warning text-dark ms-1">${sl}</span>`;
    } else { h += '<span class="text-muted">Enter valid sale price</span>'; }
    document.getElementById('salePreview').innerHTML = h;
}

// ============================================================
// IMAGE PREVIEWS
// ============================================================
function previewImages(input) {
    if (!input.files || !input.files.length) return;
    selectedProductImages = [...selectedProductImages, ...Array.from(input.files)].slice(0, 5);
    refreshProductImagePreview(); input.value = '';
}
function refreshProductImagePreview() {
    const c = $('#imagePreviewContainer'); c.empty();
    selectedProductImages.forEach((f, i) => {
        const r = new FileReader();
        r.onload = e => c.append(`<div class="col-3"><div class="position-relative"><img src="${e.target.result}" class="img-fluid rounded-3 border" style="height:100px;width:100%;object-fit:cover;"><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="removeProductImage(${i})"><i class="bi bi-x"></i></button></div></div>`);
        r.readAsDataURL(f);
    });
    if (selectedProductImages.length) c.append(`<div class="col-12"><small class="text-muted">${selectedProductImages.length}/5 image(s) selected</small></div>`);
}
function removeProductImage(i) { selectedProductImages.splice(i,1); refreshProductImagePreview(); }

function previewRequestImages(input) {
    if (!input.files || !input.files.length) return;
    selectedRequestImages = [...selectedRequestImages, ...Array.from(input.files)];
    refreshRequestImagePreview(); input.value = '';
}
function refreshRequestImagePreview() {
    const c = $('#requestImagePreviewContainer'); c.empty();
    selectedRequestImages.forEach((f, i) => {
        const r = new FileReader();
        r.onload = e => c.append(`<div class="col-3"><div class="position-relative"><img src="${e.target.result}" class="img-fluid rounded-3 border" style="height:80px;width:100%;object-fit:cover;"><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="removeRequestImage(${i})"><i class="bi bi-x"></i></button></div></div>`);
        r.readAsDataURL(f);
    });
}
function removeRequestImage(i) { selectedRequestImages.splice(i,1); refreshRequestImagePreview(); }

// ============================================================
// POST PRODUCT
// ============================================================
function postProduct() {
    if (!requirePermission('canCreate','post')) return;

    const itemId = $('#selected_item_id').val();
    if (!itemId) {
        Swal.fire({icon:'warning',title:'Warning',text:'Please select an item first',confirmButtonColor:'#008080'});
        return;
    }

    const formData = new FormData();
    formData.append('item_id',    itemId);
    formData.append('source',     $('#selected_source').val());
    formData.append('name',       $('#product_name').val());
    formData.append('category',   $('#product_category').val());
    formData.append('brand',      $('#product_brand').val());
    formData.append('price',      $('#product_price').val());
    formData.append('stock',      $('#product_stock').val());
    formData.append('description', $('#product_description').val());

    const onSale = document.getElementById('enableSale').checked;
    formData.append('is_on_sale', onSale ? '1' : '0');
    if (onSale) {
        const salePrice    = $('#sale_price').val();
        const originalPrice = parseFloat($('#product_price').val());
        if (!salePrice || parseFloat(salePrice) <= 0) { Swal.fire({icon:'warning',text:'Enter valid sale price',confirmButtonColor:'#008080'}); return; }
        if (parseFloat(salePrice) >= originalPrice)    { Swal.fire({icon:'warning',text:'Sale price must be less than original price',confirmButtonColor:'#008080'}); return; }
        formData.append('sale_price', salePrice);
        formData.append('sale_start', $('#sale_start').val()||'');
        formData.append('sale_end',   $('#sale_end').val()||'');
        formData.append('sale_label', $('#sale_label').val()||'SALE');
    }

    selectedProductImages.forEach(f => formData.append('images[]', f));

    const colors = getAllColorRows();
    if (colors.length) formData.append('colors_json', JSON.stringify(colors));

    const extraFields = getAllExtraFields();
    if (Object.keys(extraFields).length) formData.append('extra_fields_json', JSON.stringify(extraFields));

    // ✅ Add warranty data
    const warrantyData = collectWarrantyData();
    if (warrantyData) {
        formData.append('warranty_period', warrantyData.period);
        formData.append('warranty_coverage', JSON.stringify(warrantyData.coverage));
        formData.append('warranty_terms', warrantyData.terms);
        formData.append('warranty_premium_price', warrantyData.premium_price);
    }

    const isFrame  = currentCategory.toLowerCase() === 'frames';
    const enable3D = document.getElementById('enable3DToggle').checked;

    if (isFrame && enable3D) {
        const modelOption = $('input[name="modelOption"]:checked').val();
        formData.append('model_option', modelOption || 'none');
        if (modelOption === 'default') {
            const defaultModelId = $('#default_model_id').val();
            if (defaultModelId) {
                formData.append('use_default_model', '1');
                formData.append('default_model_id',  defaultModelId);
            }
        } else if (modelOption === 'mymodels') {
            const selectedModel = $('input[name="completed_model_id"]:checked');
            if (selectedModel.length > 0) {
                formData.append('use_completed_model',  '1');
                formData.append('completed_model_id',   selectedModel.val());
                const modelFile = selectedModel.attr('data-file');
                if (modelFile) formData.append('completed_model_file', modelFile);
            }
        } else if (modelOption === 'request') {
            formData.append('model_option', 'request');
        }
    } else {
        formData.append('model_option', 'none');
    }

    bootstrap.Modal.getInstance(document.getElementById('postProductModal')).hide();
    Swal.fire({title:'Posting Product...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});

    $.ajax({
        url: 'api/products.php?action=post_product',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (response) {
            if (response.success) {
                resetPostForm();
                loadProducts();
                Swal.fire({icon:'success',title:'Product Posted!',text:response.message,timer:2000,showConfirmButton:false});
            } else {
                Swal.fire({icon:'error',title:'Error',text:response.message||'Failed to post product',confirmButtonColor:'#008080'});
                new bootstrap.Modal(document.getElementById('postProductModal')).show();
            }
        },
        error: function (xhr, status, error) {
            console.error('Post error:', error, xhr.responseText);
            Swal.fire({icon:'error',title:'Error',text:'Failed to post product: ' + (error||'Unknown error'),confirmButtonColor:'#008080'});
            new bootstrap.Modal(document.getElementById('postProductModal')).show();
        }
    });
}

function resetPostForm() {
    $('#postProductForm')[0].reset();
    selectedProductImages = []; selectedRequestImages = [];
    $('#imagePreviewContainer,#requestImagePreviewContainer').empty();
    document.getElementById('colorRowsContainer').innerHTML = `
        <div class="text-center text-muted py-4" id="noColorsMessage">
            <i class="bi bi-palette fs-1 mb-3 opacity-50 d-block"></i>
            <p class="mb-2">No colors added yet.</p>
            <button type="button" class="btn-teal btn-sm" onclick="addColorRow()"><i class="bi bi-plus-lg me-1"></i>Add Your First Color</button>
        </div>`;
    colorRowCount = 0;
    currentCategory = '';
    $('#defaultModelSection').show();
    $('#requestModelSection,#myModelsSection').hide();
    $('input[name="modelOption"][value="default"]').prop('checked',true);
    cleanupDefaultPreview();
    document.getElementById('enableSale').checked = false;
    document.getElementById('saleFields').style.display = 'none';
    document.getElementById('categoryExtraCard').style.display = 'none';
    document.getElementById('categoryExtraFields').innerHTML = '';
    update3DSection('');
    document.getElementById('enable3DToggle').checked = false;
    $('#gcash_details').show(); $('#maya_details,#bank_details').hide();
    
    // ✅ Reset warranty form
    resetWarrantyForm();
}

function updateCategoryExtraFieldsFromInventory(category, productDetails) {
    console.log('Updating category fields for:', category);
    console.log('Product details received:', productDetails);
    
    const card   = document.getElementById('categoryExtraCard');
    const title  = document.getElementById('categoryExtraTitle');
    const fields = document.getElementById('categoryExtraFields');
    
    if (typeof CATEGORY_FIELDS === 'undefined') {
        console.warn('CATEGORY_FIELDS not defined');
        card.style.display = 'none';
        fields.innerHTML = '';
        return;
    }
    
    const fieldDefs = CATEGORY_FIELDS[category] || [];
    
    if (!fieldDefs.length) {
        card.style.display = 'none';
        fields.innerHTML = '';
        return;
    }
    
    card.style.display = 'block';
    title.textContent  = `${category} Details`;
    let html = '';
    
    fieldDefs.forEach(f => {
        let value = '';
        let isMultipleSelect = false;
        let multipleOptions = [];
        
        // Map frontend field IDs to database column names
        const fieldMapping = {
            'frame_material': 'frame_material',
            'frame_shape': 'frame_style',
            'frame_size': 'sizes_available',      // ✅ Special: multiple sizes
            'frame_gender': 'gender',
            'lens_width': 'lens_width_mm',
            'bridge_width': 'bridge_width_mm',
            'temple_length': 'temple_length_mm',
            'lens_type': 'lens_type',
            'lens_material': 'lens_material',
            'lens_coating': 'lens_coating',
            'lens_index': 'lens_index',
            'sphere_range': 'lens_sphere_range',
            'cl_brand': 'cl_brand',
            'cl_type': 'cl_type',
            'cl_water': 'cl_water_content',
            'cl_bc': 'cl_base_curve',
            'cl_diameter': 'cl_diameter',
            'cl_power': 'cl_power_range',
            'cl_color': 'cl_color_type',
            'acc_type': 'accessory_type',
            'acc_material': 'accessory_material',
            'acc_compatible': 'part_compatibility',
            'sol_type': 'solution_type',
            'sol_volume': 'solution_volume',
            'sol_brand': 'sol_brand',
            'part_type': 'part_type',
            'part_compat': 'part_compatibility',
            'part_size': 'part_size'
        };
        
        const dbKey = fieldMapping[f.id] || f.id;
        
        // ✅ Special handling for sizes_available (multiple values)
        if (dbKey === 'sizes_available' && productDetails && productDetails[dbKey]) {
            if (Array.isArray(productDetails[dbKey]) && productDetails[dbKey].length > 0) {
                isMultipleSelect = true;
                multipleOptions = productDetails[dbKey];
                console.log(`Found multiple sizes: ${multipleOptions.join(', ')}`);
            } else if (typeof productDetails[dbKey] === 'string') {
                // Try to parse if it's a JSON string
                try {
                    const parsed = JSON.parse(productDetails[dbKey]);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        isMultipleSelect = true;
                        multipleOptions = parsed;
                        console.log(`Parsed multiple sizes: ${multipleOptions.join(', ')}`);
                    }
                } catch(e) {
                    // Not JSON, treat as single value
                    value = productDetails[dbKey];
                }
            } else {
                value = productDetails[dbKey];
            }
        } 
        // Regular fields
        else if (productDetails && productDetails[dbKey] !== undefined && productDetails[dbKey] !== null) {
            value = productDetails[dbKey];
        }
        
        html += `<div class="col-md-4 extra-field-group"><label class="form-label fw-semibold">${f.label}</label>`;
        
        // ✅ For multiple sizes - use a multi-select or checkboxes
        if (isMultipleSelect && f.id === 'frame_size') {
            html += `<select class="form-select" id="extra_${f.id}" name="extra_${f.id}" multiple size="3" style="min-height: 80px;">`;
            html += `<option value="">-- Select sizes (Ctrl+Click for multiple) --</option>`;
            
            // Get all possible size options from CATEGORY_FIELDS
            const allSizeOptions = f.options || ['XS','S','M','L','XL','One Size'];
            
            allSizeOptions.forEach(opt => {
                const selected = multipleOptions.includes(opt) ? 'selected' : '';
                html += `<option value="${htmlEsc(opt)}" ${selected}>${htmlEsc(opt)}</option>`;
            });
            html += `</select>`;
            html += `<small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple sizes</small>`;
        } 
        // Regular select fields
        else if (f.type === 'select') {
            html += `<select class="form-select" id="extra_${f.id}" name="extra_${f.id}">`;
            html += `<option value="">-- Select --</option>`;
            (f.options||[]).forEach(o => {
                const selected = (value == o) ? 'selected' : '';
                html += `<option value="${htmlEsc(o)}" ${selected}>${htmlEsc(o)}</option>`;
            });
            html += `</select>`;
        } 
        // Input fields
        else {
            html += `<input type="${f.type}" class="form-control" id="extra_${f.id}" name="extra_${f.id}" 
                           placeholder="${htmlEsc(f.placeholder||'')}" value="${htmlEsc(value)}">`;
        }
        html += `</div>`;
    });
    fields.innerHTML = html;
}

function getAllExtraFields() {
    if (typeof CATEGORY_FIELDS === 'undefined') {
        console.warn('CATEGORY_FIELDS not defined');
        return {};
    }
    const fieldDefs = CATEGORY_FIELDS[currentCategory] || [];
    const result = {};
    
    fieldDefs.forEach(f => {
        const el = document.getElementById(`extra_${f.id}`);
        if (el) {
            // ✅ Special handling for multiple select (frame_size)
            if (f.id === 'frame_size' && el.multiple) {
                // Get all selected options
                const selectedValues = Array.from(el.selectedOptions).map(opt => opt.value);
                if (selectedValues.length > 0) {
                    result['sizes_available'] = selectedValues;
                    console.log('Saved multiple sizes:', selectedValues);
                }
            } 
            // Regular fields
            else if (el.value) {
                // Map frontend field ID to backend column name
                const fieldMapping = {
                    'frame_material': 'frame_material',
                    'frame_shape': 'frame_style',
                    'frame_size': 'sizes_available',
                    'frame_gender': 'gender',
                    'lens_width': 'lens_width_mm',
                    'bridge_width': 'bridge_width_mm',
                    'temple_length': 'temple_length_mm',
                    'lens_type': 'lens_type',
                    'lens_material': 'lens_material',
                    'lens_coating': 'lens_coating',
                    'lens_index': 'lens_index',
                    'sphere_range': 'lens_sphere_range',
                    'cl_brand': 'cl_brand',
                    'cl_type': 'cl_type',
                    'cl_water': 'cl_water_content',
                    'cl_bc': 'cl_base_curve',
                    'cl_diameter': 'cl_diameter',
                    'cl_power': 'cl_power_range',
                    'cl_color': 'cl_color_type',
                    'acc_type': 'accessory_type',
                    'acc_material': 'accessory_material',
                    'acc_compatible': 'part_compatibility',
                    'sol_type': 'solution_type',
                    'sol_volume': 'solution_volume',
                    'sol_brand': 'sol_brand',
                    'part_type': 'part_type',
                    'part_compat': 'part_compatibility',
                    'part_size': 'part_size'
                };
                
                const dbKey = fieldMapping[f.id] || f.id;
                result[dbKey] = el.value;
            }
        }
    });
    
    return result;
}
// ============================================================
// 3D SECTION LOGIC
// ============================================================
function update3DSection(category) {
    const isFrame = category.toLowerCase() === 'frames';
    const non3DNote      = document.getElementById('non3DNote');
    const model3DOptions = document.getElementById('model3DOptions');
    const toggle3DWrap   = document.getElementById('toggle3DWrap');
    const label3DNote    = document.getElementById('label3DNote');
    const enable3D       = document.getElementById('enable3DToggle');
    if (isFrame) {
        non3DNote.style.display = 'none';
        toggle3DWrap.style.removeProperty('display');
        label3DNote.textContent = '(optional for this frame)';
        label3DNote.style.color = 'var(--teal)';
        enable3D.checked = true;
        model3DOptions.style.display = 'block';
    } else if (category === '') {
        non3DNote.style.display = 'flex';
        toggle3DWrap.style.setProperty('display','none','important');
        model3DOptions.style.display = 'none';
        label3DNote.textContent = '(select a product first)';
        label3DNote.style.color = 'var(--text-muted)';
        enable3D.checked = false;
    } else {
        non3DNote.style.display = 'flex';
        non3DNote.innerHTML = `<i class="bi bi-info-circle"></i> 3D model is only available for <strong>Frames</strong>. This item is a <strong>${category}</strong>.`;
        toggle3DWrap.style.setProperty('display','none','important');
        model3DOptions.style.display = 'none';
        label3DNote.textContent = '(frames only)';
        label3DNote.style.color = 'var(--text-muted)';
        enable3D.checked = false;
    }
}

function onToggle3D(enabled) {
    document.getElementById('model3DOptions').style.display = enabled ? 'block' : 'none';
    if (!enabled) {
        cleanupDefaultPreview();
        document.getElementById('default_model_id').value = '';
    }
}

function toggleModelOption() {
    const opt = $('input[name="modelOption"]:checked').val();
    $('#defaultModelSection, #requestModelSection, #myModelsSection').hide();
    cleanupDefaultPreview();
    
    if (opt === 'default') {
        $('#defaultModelSection').show();
        const sel = $('#default_model_id').val();
        if (sel) previewDefaultFrame3D(sel);
    } else if (opt === 'request') {
        $('#requestModelSection').show();
    } else if (opt === 'mymodels') {
        $('#myModelsSection').show();
        // Clear any previous selection when switching to this tab
        if (typeof clearSelectedModel === 'function') {
            clearSelectedModel();
        }
    }
}

function refreshInventory() { loadInventoryItems(); }

// ============================================================
// PRODUCT ACTIONS (Edit, Delete, Approve, Reject)
// ============================================================
function editProduct(id) {
    if (!requirePermission('canEdit','edit')) return;
    $.ajax({
        url:'api/products.php?action=get_products',
        success:function(res){
            if (res.success) {
                const p = res.data.find(x => x.id == id);
                if (!p) return;
                Swal.fire({
                    title:'Edit Product',
                    html:`<div class="text-start"><div class="mb-3"><label class="form-label">Product Name</label><input type="text" id="edit_name" class="form-control" value="${escapeHtml(p.name)}"></div><div class="mb-3"><label class="form-label">Description</label><textarea id="edit_description" class="form-control" rows="3">${escapeHtml(p.description||'')}</textarea></div><div class="mb-3"><label class="form-label">Price (₱)</label><input type="number" id="edit_price" class="form-control" step="0.01" value="${p.price}"></div><div class="mb-3"><label class="form-label">Category</label><input type="text" id="edit_category" class="form-control" value="${escapeHtml(p.category)}"></div></div>`,
                    showCancelButton:true, confirmButtonText:'Save Changes', confirmButtonColor:'#008080',
                    preConfirm:()=>({name:document.getElementById('edit_name').value.trim(),description:document.getElementById('edit_description').value,price:parseFloat(document.getElementById('edit_price').value),category:document.getElementById('edit_category').value.trim()})
                }).then(result => {
                    if (!result.isConfirmed) return;
                    $.ajax({url:'api/products.php',method:'POST',data:JSON.stringify({action:'edit_product',id,...result.value}),contentType:'application/json',
                        success:function(r){ if(r.success){Swal.fire({icon:'success',title:'Updated!',text:r.message,timer:1500,showConfirmButton:false});loadProducts();}else{Swal.fire('Error!',r.message,'error');} },
                        error:function(){Swal.fire('Error!','Failed to update','error');}
                    });
                });
            }
        }
    });
}

function deleteProduct(id, name) {
    if (!requirePermission('canDelete','delete')) return;
    Swal.fire({title:'Delete Product?',html:`Are you sure you want to delete <strong>${escapeHtml(name)}</strong>?`,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, Delete'}).then(result=>{
        if (!result.isConfirmed) return;
        $.ajax({url:'api/products.php',method:'POST',data:JSON.stringify({action:'delete_product',id}),contentType:'application/json',
            success:function(r){ if(r.success){Swal.fire({icon:'success',title:'Deleted!',text:r.message,timer:1500,showConfirmButton:false});loadProducts();}else{Swal.fire('Error!',r.message,'error');} },
            error:function(){Swal.fire('Error!','Failed to delete','error');}
        });
    });
}

function approveProduct(id) {
    if (!requirePermission('canApprove','approve')) return;
    Swal.fire({title:'Approve Product?',icon:'question',showCancelButton:true,confirmButtonColor:'#008080',confirmButtonText:'Yes, Approve'}).then(r=>{
        if (!r.isConfirmed) return;
        $.ajax({url:'api/products.php',method:'POST',data:JSON.stringify({action:'approve_product',id}),contentType:'application/json',
            success:function(res){ if(res.success){loadProducts();Swal.fire({icon:'success',title:'Approved!',timer:1500,showConfirmButton:false});}else{Swal.fire({icon:'error',text:res.message});} }
        });
    });
}

function rejectProduct(id) {
    if (!requirePermission('canReject','reject')) return;
    Swal.fire({title:'Reject Product?',input:'textarea',inputPlaceholder:'Reason for rejection (optional)...',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Yes, Reject'}).then(r=>{
        if (!r.isConfirmed) return;
        $.ajax({url:'api/products.php',method:'POST',data:JSON.stringify({action:'reject_product',id,reason:r.value}),contentType:'application/json',
            success:function(res){ if(res.success){loadProducts();Swal.fire({icon:'success',title:'Rejected',timer:1500,showConfirmButton:false});}else{Swal.fire({icon:'error',text:res.message});} }
        });
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function viewFrameOnly(pid) {
    const modalEl = document.getElementById('frameViewerModal');
    const modal = new bootstrap.Modal(modalEl);
    document.getElementById('frameColorPalette').innerHTML = '<div class="text-muted small p-2">Loading 3D model...</div>';
    const container = document.getElementById('frame-viewer-container');
    container.innerHTML = '<div class="text-center p-5"><div class="spinner-border" style="color:#008080;"></div><p class="mt-2 text-muted small">Loading 3D model...</p></div>';

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);
        $.ajax({
            url: `api/products.php?action=get_product_3d&id=${pid}`,
            success: function (r) {
                if (r.success && r.data && r.data.model_file) {
                    let colors = [];
                    if (r.data.colors_json) {
                        try { colors = JSON.parse(r.data.colors_json); } catch(e) {}
                    } else if (r.data.colors) {
                        try { colors = typeof r.data.colors === 'string' ? JSON.parse(r.data.colors) : r.data.colors; } catch(e) {}
                    }
                    if (!colors || colors.length === 0) {
                        colors = [{name:'Black', code:'#000000', is_available: true},{name:'Gold', code:'#FFD700', is_available: true},{name:'Silver', code:'#C0C0C0', is_available: true},{name:'Brown', code:'#8B4513', is_available: true},{name:'Blue', code:'#0000FF', is_available: true},{name:'Red', code:'#FF0000', is_available: true}];
                    }
                    initFrameViewer(r.data.model_file, colors);
                } else {
                    Swal.fire({icon:'error',text:r.message||'No 3D model available',confirmButtonColor:'#008080'});
                    bootstrap.Modal.getInstance(modalEl).hide();
                }
            },
            error: function () {
                Swal.fire({icon:'error',text:'Failed to load 3D model',confirmButtonColor:'#008080'});
                bootstrap.Modal.getInstance(modalEl).hide();
            }
        });
    }, { once: true });

    modal.show();
}
function initFrameViewer(modelPath, colors) {
    const container = document.getElementById('frame-viewer-container');
    if (!container) return;
    container.innerHTML = '';
    const width  = container.offsetWidth  || 800;
    const height = container.offsetHeight || 500;
    
    // Clear previous lens colors
    originalLensColors.clear();
    
    frameScene    = new THREE.Scene();
    frameScene.background = new THREE.Color(0xffffff);
    frameCamera   = new THREE.PerspectiveCamera(45, width/height, 0.1, 1000);
    frameCamera.position.set(3, 1.5, 4);
    frameRenderer = new THREE.WebGLRenderer({antialias:true});
    frameRenderer.setSize(width, height);
    frameRenderer.setClearColor(0xffffff);
    frameRenderer.shadowMap.enabled = true;
    container.appendChild(frameRenderer.domElement);
    const resizeObserver = new ResizeObserver(() => {
        const w = container.offsetWidth;
        const h = container.offsetHeight;
        frameCamera.aspect = w / h;
        frameCamera.updateProjectionMatrix();
        frameRenderer.setSize(w, h);
    });
    resizeObserver.observe(container);
    
    frameControls = new THREE.OrbitControls(frameCamera, frameRenderer.domElement);
    frameControls.enableDamping = true;
    frameControls.dampingFactor = 0.05;
    frameControls.autoRotate    = true;
    frameControls.autoRotateSpeed = 0.8;
    
    const al = new THREE.AmbientLight(0xffffff, 0.6); frameScene.add(al);
    const dl = new THREE.DirectionalLight(0xffffff, 1); dl.position.set(2,5,3); dl.castShadow = true; frameScene.add(dl);
    const fl = new THREE.DirectionalLight(0xffeedd, 0.5); fl.position.set(-2,2,2); frameScene.add(fl);
    const bl = new THREE.PointLight(0xffaa88, 0.4); bl.position.set(0,1,-2); frameScene.add(bl);
    
let fullPath = modelPath;
if (!fullPath.startsWith('http') && !fullPath.startsWith('/')) fullPath = '/' + modelPath;
    
    const loader = new THREE.GLTFLoader();
    loader.load(fullPath, function (gltf) {
        frameModel = gltf.scene;
        
        // Store original lens colors BEFORE any color changes
        frameModel.traverse(node => {
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
        
        const box    = new THREE.Box3().setFromObject(frameModel);
        const center = box.getCenter(new THREE.Vector3());
        const size   = box.getSize(new THREE.Vector3());
        const scale  = 2.2 / Math.max(size.x, size.y, size.z);
        frameModel.scale.set(scale, scale, scale);
        frameModel.position.set(-center.x*scale, -center.y*scale, -center.z*scale);
        
        frameModel.userData.originalMaterials = [];
        frameModel.traverse(child => {
            if (child.isMesh && child.material) {
                const mats = Array.isArray(child.material) ? child.material : [child.material];
                mats.forEach(m => frameModel.userData.originalMaterials.push(m.clone()));
            }
        });
        
        frameScene.add(frameModel);
        const newCenter = new THREE.Box3().setFromObject(frameModel).getCenter(new THREE.Vector3());
        frameControls.target.copy(newCenter);
        frameControls.update();
        
        const availableColors = colors.filter(c => c.is_available !== false);
        createColorPaletteWithLens(availableColors);
        
        // Add warning if no lens materials detected
        if (originalLensColors.size === 0 && availableColors.length > 0) {
            const warningDiv = document.createElement('div');
            warningDiv.style.cssText = `
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 12px;
                margin: 10px 0;
                text-align: center;
            `;
            warningDiv.innerHTML = `
                <i class="bi bi-info-circle"></i>
                <strong>Note:</strong> No lens material detected in this GLB file. 
                The entire frame (including lens area) will change color.
                For better results, ensure lens materials are named with "lens", "glass", or "clear".
            `;
            const colorPalette = document.getElementById('frameColorPalette');
            if (colorPalette && colorPalette.parentNode) {
                colorPalette.parentNode.insertBefore(warningDiv, colorPalette.nextSibling);
            }
        }
        
    }, null, function (error) {
        console.error('Frame viewer load error:', error);
        container.innerHTML = `<div class="text-center text-danger p-5"><i class="bi bi-exclamation-triangle fs-1"></i><br>Failed to load 3D model</div>`;
    });
    
    (function animate() { 
        requestAnimationFrame(animate); 
        if (frameControls) frameControls.update(); 
        if (frameRenderer && frameScene && frameCamera) frameRenderer.render(frameScene, frameCamera); 
    })();
}

function createColorPaletteWithLens(colors) {
    const pc = document.getElementById('frameColorPalette');
    if (!pc) return;
    const availableColors = colors.filter(c => c.is_available !== false);
    if (!availableColors || availableColors.length === 0) {
        pc.innerHTML = '<div class="text-muted small p-2">No colors available for this product.</div>';
        return;
    }
    let html = `<button class="btn-outline-teal btn-sm me-2" onclick="resetFrameColor()"><i class="bi bi-arrow-repeat"></i> Reset</button>`;
    availableColors.forEach(c => { 
        html += `<div class="color-swatch-large" style="background:${c.code};" onclick="changeFrameColorWithLens('${c.code}')" title="${c.name || c.color_name || 'Color'}"></div>`; 
    });
    pc.innerHTML = html;
}

function changeFrameColorWithLens(code) { 
    if (!frameModel) return;
    
    try {
        frameModel.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                
                materials.forEach((mat, idx) => {
                    if (mat && mat.color) {
                        const isLens = isLensMaterial(node, mat);
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        
                        if (!isLens) {
                            // Change frame color only
                            mat.color.set(code);
                            const darkColors = ['#000000', '#2C2C2C', '#111111', '#2C3539', '#000080', '#800000'];
                            mat.emissiveIntensity = darkColors.includes(code.toLowerCase()) ? 0.1 : 0;
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
    } catch(e) { console.error('Color change error:', e); }
}

function resetFrameColor() { 
    if (!frameModel || !frameModel.userData.originalMaterials) return; 
    let idx = 0; 
    frameModel.traverse(child => { 
        if (child.isMesh) { 
            const mats = Array.isArray(child.material) ? child.material : [child.material]; 
            mats.forEach(m => { 
                if (frameModel.userData.originalMaterials[idx]) {
                    m.color.copy(frameModel.userData.originalMaterials[idx].color);
                    m.transparent = frameModel.userData.originalMaterials[idx].transparent;
                    m.opacity = frameModel.userData.originalMaterials[idx].opacity;
                }
                idx++; 
            }); 
        } 
    }); 
}

function rotateFrame(dir)  { if (frameModel) frameModel.rotation.y += dir === 'left' ? 0.3 : -0.3; }
function toggleWireframe() { if (!frameModel) return; frameModel.traverse(child => { if (child.isMesh) { const mats = Array.isArray(child.material)?child.material:[child.material]; mats.forEach(m=>m.wireframe=!m.wireframe); } }); }
function resetFrameView()  { if (frameModel){ frameModel.rotation.set(0,0,0); } if (frameCamera) { frameCamera.position.set(3,1.5,4); frameCamera.lookAt(0,0,0); } }

// ============================================================
// DEFAULT FRAME 3D PREVIEW WITH LENS DETECTION
// ============================================================
function previewDefaultFrame3D(modelId) {
    cleanupDefaultPreview();
    if (!modelId) {
        document.getElementById('default-frame-preview').style.display = 'none';
        document.getElementById('expandPreviewBtn').style.display = 'none';
        document.getElementById('default-frame-colors').style.display = 'none';
        return;
    }
    const sel = document.getElementById('default_model_id');
    const opt = sel.options[sel.selectedIndex];
    let modelPath = opt.getAttribute('data-path');
    if (!modelPath) { console.error('No model path'); return; }
    if (!modelPath.startsWith('/') && !modelPath.startsWith('http')) modelPath = '/' + modelPath;
    currentDefaultModelPath = modelPath;
    const previewContainer = document.getElementById('default-frame-preview');
    previewContainer.style.display = 'block';
    document.getElementById('expandPreviewBtn').style.display = 'block';
    document.getElementById('default-frame-colors').style.display = 'flex';
    const controlsDiv = previewContainer.querySelector('.preview-controls');
    while (previewContainer.firstChild && previewContainer.firstChild !== controlsDiv) { previewContainer.removeChild(previewContainer.firstChild); }
    const width  = previewContainer.clientWidth || 500;
    const height = 280;
    
    defaultPreviewScene    = new THREE.Scene();
    defaultPreviewScene.background = new THREE.Color(0xffffff);
    defaultPreviewCamera   = new THREE.PerspectiveCamera(45, width/height, 0.1, 1000);
    defaultPreviewCamera.position.set(2, 1.5, 3);
    defaultPreviewRenderer = new THREE.WebGLRenderer({antialias:true});
    defaultPreviewRenderer.setSize(width, height);
    defaultPreviewRenderer.setClearColor(0xffffff);
    previewContainer.insertBefore(defaultPreviewRenderer.domElement, controlsDiv);
    defaultPreviewControls = new THREE.OrbitControls(defaultPreviewCamera, defaultPreviewRenderer.domElement);
    defaultPreviewControls.enableDamping  = true;
    defaultPreviewControls.dampingFactor  = 0.05;
    defaultPreviewControls.autoRotate     = true;
    defaultPreviewControls.autoRotateSpeed = 1.5;
    defaultPreviewControls.enablePan      = false;
    
    const al = new THREE.AmbientLight(0xffffff, 0.7); defaultPreviewScene.add(al);
    const dl = new THREE.DirectionalLight(0xffffff, 1); dl.position.set(3,5,4); defaultPreviewScene.add(dl);
    const fl = new THREE.DirectionalLight(0xffeedd, 0.5); fl.position.set(-2,2,3); defaultPreviewScene.add(fl);
    const bl = new THREE.PointLight(0xffaa88, 0.3); bl.position.set(0,1,-2); defaultPreviewScene.add(bl);
    
    const loader = new THREE.GLTFLoader();
    loader.load(modelPath, function (gltf) {
        defaultPreviewModel = gltf.scene;
        
        // Store original lens colors for default preview
        defaultPreviewModel.userData.originalLensColors = new Map();
        defaultPreviewModel.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                materials.forEach((mat, idx) => {
                    if (isLensMaterial(node, mat) && mat.color) {
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        defaultPreviewModel.userData.originalLensColors.set(key, {
                            color: mat.color.getHex(),
                            transparent: mat.transparent || false,
                            opacity: mat.opacity !== undefined ? mat.opacity : 1
                        });
                    }
                });
            }
        });
        
        const box    = new THREE.Box3().setFromObject(defaultPreviewModel);
        const center = box.getCenter(new THREE.Vector3());
        const size   = box.getSize(new THREE.Vector3());
        const scale  = 2.0 / Math.max(size.x, size.y, size.z);
        defaultPreviewModel.scale.set(scale, scale, scale);
        defaultPreviewModel.position.set(-center.x*scale, -center.y*scale, -center.z*scale);
        defaultPreviewModel.userData.originalMaterials = [];
        defaultPreviewModel.traverse(child => {
            if (child.isMesh && child.material) {
                const mats = Array.isArray(child.material) ? child.material : [child.material];
                mats.forEach(m => defaultPreviewModel.userData.originalMaterials.push(m.clone()));
            }
        });
        defaultPreviewScene.add(defaultPreviewModel);
        const nc = new THREE.Box3().setFromObject(defaultPreviewModel).getCenter(new THREE.Vector3());
        defaultPreviewControls.target.copy(nc);
        defaultPreviewControls.update();
        createDefaultFrameColorPalette();
    }, null, function (err) {
        console.error('Preview load error:', err);
        previewContainer.innerHTML = `<div class="text-center text-danger p-4"><i class="bi bi-exclamation-triangle fs-2"></i><br>Failed to load preview</div>`;
    });
    
    (function animatePreview() { 
        requestAnimationFrame(animatePreview); 
        if (defaultPreviewControls) defaultPreviewControls.update(); 
        if (defaultPreviewRenderer && defaultPreviewScene && defaultPreviewCamera) defaultPreviewRenderer.render(defaultPreviewScene, defaultPreviewCamera); 
    })();
}

function createDefaultFrameColorPalette() {
    const pc = document.getElementById('default-frame-colors');
    if (!pc) return;
    const addedColors = getAllColorRows();
    const cols = addedColors.length > 0 ? addedColors : [{name:'Black',code:'#000000'},{name:'Gold',code:'#FFD700'},{name:'Silver',code:'#C0C0C0'},{name:'Brown',code:'#8B4513'},{name:'Blue',code:'#0000FF'},{name:'Red',code:'#FF0000'}];
    let html = '<strong class="me-2 small align-self-center">Try color:</strong>';
    cols.forEach(c => { 
        html += `<div class="color-swatch-large" style="background:${c.code};" onclick="changeDefaultFrameColorWithLens('${c.code}')" title="${c.name}"></div>`; 
    });
    html += `<div class="color-swatch-large" style="background:#ccc;display:flex;align-items:center;justify-content:center;" onclick="resetDefaultFrameColorWithLens()" title="Reset"><i class="bi bi-arrow-repeat" style="font-size:18px;color:#333;"></i></div>`;
    pc.innerHTML = html;
}

function changeDefaultFrameColorWithLens(code) { 
    if (!defaultPreviewModel) return;
    
    try {
        defaultPreviewModel.traverse(node => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                
                materials.forEach((mat, idx) => {
                    if (mat && mat.color) {
                        const isLens = isLensMaterial(node, mat);
                        const lensMap = defaultPreviewModel.userData.originalLensColors;
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        
                        if (!isLens) {
                            mat.color.set(code);
                        } else if (lensMap && lensMap.has(key)) {
                            const original = lensMap.get(key);
                            mat.color.setHex(original.color);
                            mat.transparent = original.transparent;
                            mat.opacity = original.opacity;
                        }
                    }
                });
            }
        });
    } catch(e) { console.error('Color change error:', e); }
}

function resetDefaultFrameColorWithLens() { 
    if (!defaultPreviewModel || !defaultPreviewModel.userData.originalMaterials) return; 
    let idx = 0; 
    defaultPreviewModel.traverse(child => { 
        if (child.isMesh) { 
            const mats = Array.isArray(child.material) ? child.material : [child.material]; 
            mats.forEach(m => { 
                if (defaultPreviewModel.userData.originalMaterials[idx]) {
                    m.color.copy(defaultPreviewModel.userData.originalMaterials[idx].color);
                }
                idx++; 
            }); 
        } 
    }); 
}

function rotateDefaultPreview(dir) { if (defaultPreviewModel) defaultPreviewModel.rotation.y += dir === 'left' ? 0.3 : -0.3; }
function resetDefaultPreview()     { if (defaultPreviewModel) defaultPreviewModel.rotation.set(0,0,0); }

function cleanupDefaultPreview() {
    if (defaultPreviewRenderer) { defaultPreviewRenderer.dispose(); defaultPreviewRenderer.forceContextLoss(); }
    defaultPreviewScene = defaultPreviewCamera = defaultPreviewRenderer = defaultPreviewModel = defaultPreviewControls = null;
    currentDefaultModelPath = null;
    const previewContainer = document.getElementById('default-frame-preview');
    if (previewContainer) {
        const controlsDiv = previewContainer.querySelector('.preview-controls');
        while (previewContainer.firstChild && previewContainer.firstChild !== controlsDiv) { previewContainer.removeChild(previewContainer.firstChild); }
        previewContainer.style.display = 'none';
    }
    const expandBtn = document.getElementById('expandPreviewBtn');
    if (expandBtn) expandBtn.style.display = 'none';
    const colorPalette = document.getElementById('default-frame-colors');
    if (colorPalette) colorPalette.style.display = 'none';
}

function expandDefaultPreview() {
    if (!currentDefaultModelPath) { Swal.fire({icon:'warning',title:'No Model Selected',text:'Please select a frame style first.',confirmButtonColor:'#008080'}); return; }
    const modal = new bootstrap.Modal(document.getElementById('frameViewerModal'));
    modal.show();
    initFrameViewer(currentDefaultModelPath, getAllColorRows().length > 0 ? getAllColorRows() : [{name:'Black',code:'#000000'},{name:'Gold',code:'#FFD700'},{name:'Silver',code:'#C0C0C0'},{name:'Brown',code:'#8B4513'}]);
}

function viewCompletedModel(rid) {
    $.ajax({
        url: 'api/3d_request.php?action=get_request_details&id=' + rid, 
        dataType:'json',
        success: function (r) {
            if (r.success && r.data && r.data.completed_model_file) {
                const modal = new bootstrap.Modal(document.getElementById('frameViewerModal'));
                modal.show();
                
                // ✅ Get colors from the request
                let colorsArray = [];
                if (r.data.colors_requested) {
                    colorsArray = r.data.colors_requested.split(',').map(c => c.trim()).filter(Boolean);
                    // Convert to format expected by initFrameViewer
                    colorsArray = colorsArray.map(color => ({
                        name: color,
                        code: getColorHexValue(color),
                        is_available: true
                    }));
                }
                
                initFrameViewer(r.data.completed_model_file, colorsArray);
            } else {
                Swal.fire('Error', r.message||'Failed to load 3D model', 'error');
            }
        },
        error: function () { Swal.fire('Error', 'Could not load 3D model', 'error'); }
    });
}

// ✅ Add helper function to get hex from color name
function getColorHexValue(colorName) {
    const colorMap = {
        'Black': '#000000', 'Red': '#ff0000', 'Green': '#00ff00',
        'Blue': '#0000ff', 'Gold': '#ffd700', 'Silver': '#c0c0c0',
        'Pink': '#ff69b4', 'White': '#ffffff', 'Purple': '#800080',
        'Orange': '#ffa500', 'Brown': '#8b4513', 'Cyan': '#00ffff',
        'Magenta': '#ff00ff', 'Yellow': '#ffff00', 'Navy': '#000080',
        'Maroon': '#800000', 'Olive': '#808000', 'Teal': '#008080',
        'Lavender': '#e6e6fa', 'Coral': '#ff7f50', 'Rose Gold': '#B76E79',
        'Copper': '#B87333', 'Gunmetal': '#2C3539', 'Matte Black': '#2C2C2C',
        'Gloss Black': '#111111', 'Tortoise': '#8B5A2B', 'Beige': '#F5F5DC',
        'Champagne': '#F7E7CE', 'Bronze': '#CD7F32', 'Turquoise': '#40E0D0',
        'Crimson': '#DC143C', 'Indigo': '#4B0082'
    };
    
    if (colorMap[colorName]) return colorMap[colorName];
    const key = Object.keys(colorMap).find(k => k.toLowerCase() === colorName.toLowerCase());
    if (key) return colorMap[key];
    return '#888888';
}

// Track selected completed model
let selectedCompletedModelId = null;
let selectedCompletedModelName = null;

function selectCompletedModel(id, name) {
    // Clear previous selection
    if (selectedCompletedModelId) {
        const prevBadge = document.getElementById(`selected_badge_${selectedCompletedModelId}`);
        if (prevBadge) prevBadge.style.display = 'none';
        const prevRadio = document.getElementById(`cm_${selectedCompletedModelId}`);
        if (prevRadio) prevRadio.checked = false;
    }
    
    // Set new selection
    selectedCompletedModelId = id;
    selectedCompletedModelName = name;
    
    // Update radio button
    const radio = document.getElementById(`cm_${id}`);
    if (radio) radio.checked = true;
    
    // Show selected badge
    const badge = document.getElementById(`selected_badge_${id}`);
    if (badge) badge.style.display = 'block';
    
    // Update preview section
    const previewDiv = document.getElementById('selectedModelPreview');
    const nameSpan = document.getElementById('selectedModelName');
    if (previewDiv && nameSpan) {
        nameSpan.textContent = name;
        previewDiv.style.display = 'block';
    }
    
    // Scroll to show the selected model
    const card = document.querySelector(`.completed-model-card:has(#cm_${id})`);
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function clearSelectedModel() {
    if (selectedCompletedModelId) {
        const badge = document.getElementById(`selected_badge_${selectedCompletedModelId}`);
        if (badge) badge.style.display = 'none';
        const radio = document.getElementById(`cm_${selectedCompletedModelId}`);
        if (radio) radio.checked = false;
    }
    selectedCompletedModelId = null;
    selectedCompletedModelName = null;
    
    const previewDiv = document.getElementById('selectedModelPreview');
    if (previewDiv) previewDiv.style.display = 'none';
}

// Add event listeners for radio buttons after page loads
$(document).ready(function() {
    // Load initial data
    loadProducts();
    loadServices();
    loadInventoryItems();

    // Payment method toggle
    $('#gcash_details,#maya_details,#bank_details').hide();
    $('#gcash_details').show();
    $('input[name="payment_method"]').on('change', function () {
        const m = $(this).val();
        $('#gcash_details,#maya_details,#bank_details').hide();
        if (m === 'gcash') $('#gcash_details').show();
        else if (m === 'maya') $('#maya_details').show();
        else if (m === 'bank') $('#bank_details').show();
    });

    // Frame viewer modal cleanup
    $('#frameViewerModal').on('hidden.bs.modal', function () {
        if (frameRenderer) { frameRenderer.dispose(); frameRenderer.forceContextLoss(); }
        frameScene = frameCamera = frameRenderer = frameModel = frameControls = null;
        originalLensColors.clear();
        document.getElementById('frame-viewer-container').innerHTML = '';
    });

    // Post product modal cleanup
    $('#postProductModal').on('hidden.bs.modal', function () {
        cleanupDefaultPreview();
    });

    // ✅ Warranty coverage checkboxes - update preview
    $('#cov_defect, #cov_breakage, #cov_coating, #cov_hinge, #cov_fading').on('change', function() {
        updateWarrantyPreview();
    });
    
    // ✅ Warranty terms input - update preview
    $('#warranty_terms').on('input', function() {
        updateWarrantyPreview();
    });
    
    // ✅ Warranty period change - update preview
    $('#warranty_period').on('change', function() {
        updateWarrantyPreview();
    });

    // Add listener for completed model radio buttons
    $(document).on('change', 'input[name="completed_model_id"]', function() {
        if (this.checked) {
            const id = this.value;
            const name = this.getAttribute('data-name') || '3D Model';
            if (typeof selectCompletedModel === 'function') {
                selectCompletedModel(id, name);
            }
        }
    });
});


// ============================================================
// WARRANTY DECISION SUPPORT SYSTEM
// ============================================================

// Warranty auto-suggest rules
function autoSuggestWarranty() {
    const period = $('#warranty_period').val();
    const category = $('#product_category').val();
    const price = parseFloat($('#product_price').val()) || 0;
    const brand = $('#product_brand').val();
    
    let suggestion = '';
    let premiumPrice = 0;
    
    // Only auto-suggest if user hasn't selected anything yet
    if (!period || period === '') {
        // Decision support logic based on category, price, brand
        if (category === 'Frames') {
            if (price > 5000) {
                suggestion = '💎 Premium frame detected! Recommended: 24 months warranty';
                $('#warranty_period').val('24_months');
                premiumPrice = 200;
            } else if (price > 2000) {
                suggestion = '👍 Standard frame. Recommended: 12 months warranty';
                $('#warranty_period').val('12_months');
                premiumPrice = 0;
            } else if (price > 500) {
                suggestion = '📦 Budget frame. 6 months warranty is standard';
                $('#warranty_period').val('6_months');
                premiumPrice = 0;
            } else {
                suggestion = '💡 Low-cost item. No warranty recommended';
                $('#warranty_period').val('');
            }
        } 
        else if (category === 'Contact Lenses') {
            suggestion = '👁️ Contact lenses usually have 30-day warranty only';
            $('#warranty_period').val('no_warranty');
        } 
        else if (category === 'Sunglasses') {
            if (brand === 'Ray-Ban' || brand === 'Oakley' || brand === 'Gucci') {
                suggestion = '⭐ Premium brand! Includes 24 months warranty';
                $('#warranty_period').val('24_months');
                premiumPrice = 150;
            } else if (price > 3000) {
                suggestion = '🕶️ Premium sunglasses: 12 months warranty';
                $('#warranty_period').val('12_months');
            } else {
                suggestion = '🕶️ Standard sunglasses: 6 months warranty';
                $('#warranty_period').val('6_months');
            }
        }
        else if (category === 'Lenses') {
            if (price > 5000) {
                suggestion = '🔍 Premium lenses: 24 months warranty on coating';
                $('#warranty_period').val('24_months');
                premiumPrice = 300;
            } else {
                suggestion = '🔍 Standard lenses: 12 months warranty on defects';
                $('#warranty_period').val('12_months');
            }
        }
        else if (category === 'Accessories') {
            suggestion = '🧰 Accessories: 3-6 months warranty recommended';
            $('#warranty_period').val('3_months');
        }
        
        $('#warranty_suggestion').html(`<i class="bi bi-robot me-1"></i>${suggestion}`);
    }
    
    // Calculate premium price based on period
    if ($('#warranty_period').val() === '24_months') premiumPrice = 200;
    else if ($('#warranty_period').val() === '36_months') premiumPrice = 500;
    else premiumPrice = 0;
    
    updateWarrantyPreview();
}

// Update warranty preview display
function updateWarrantyPreview() {
    const period = $('#warranty_period').val();
    if (!period || period === '' || period === 'no_warranty') {
        $('#warranty_preview').hide();
        return;
    }
    
    const periodText = $('#warranty_period option:selected').text();
    
    // Get selected coverages
    const coverages = [];
    if ($('#cov_defect').is(':checked')) coverages.push('Manufacturing Defects');
    if ($('#cov_breakage').is(':checked')) coverages.push('Frame Breakage');
    if ($('#cov_coating').is(':checked')) coverages.push('Lens Coating');
    if ($('#cov_hinge').is(':checked')) coverages.push('Hinge Damage');
    if ($('#cov_fading').is(':checked')) coverages.push('Color Fading');
    
    const coverageText = coverages.length > 0 ? coverages.join(', ') : 'Standard coverage only';
    
    let summary = `${periodText} warranty covering: ${coverageText}`;
    let priceTag = '';
    
    // Check if premium price should be applied
    if (period === '24_months') {
        priceTag = '+₱200 premium';
        summary += ' (+₱200)';
    } else if (period === '36_months') {
        priceTag = '+₱500 premium';
        summary += ' (+₱500)';
    }
    
    $('#warranty_summary_text').text(summary);
    $('#warranty_price_tag').text(priceTag);
    $('#warranty_preview').show();
}

function collectWarrantyData() {
    const period = $('#warranty_period').val();
    if (!period || period === '' || period === 'no_warranty') {
        return null;
    }
    
    const coverages = [];
    $('#cov_defect, #cov_breakage, #cov_hinge, #cov_fading, #cov_bent, #cov_screw, #cov_coating, #cov_crack, #cov_wrong_power, #cov_popout, #cov_photo').each(function() {
        if ($(this).is(':checked')) coverages.push($(this).val());
    });
    
    let premiumPrice = 0;
    if (period === '24_months') premiumPrice = 200;
    else if (period === '36_months') premiumPrice = 500;
    
    return {
        period: period,
        coverage: coverages,
        exclusions: $('#warranty_exclusions').val(),
        terms: $('#warranty_terms').val(),
        claim_process: $('#warranty_claim_process').val(),
        care: $('#warranty_care').val(),
        premium_price: premiumPrice
    };
}
function resetWarrantyForm() {
    $('#warranty_period').val('');
    $('#warranty_premium_price').val(0);
    $('#warranty_template').val('');
    $('#cov_defect, #cov_breakage, #cov_hinge, #cov_fading, #cov_bent, #cov_screw, #cov_coating, #cov_crack, #cov_wrong_power, #cov_popout, #cov_photo').prop('checked', false);
    $('#warranty_exclusions, #warranty_terms, #warranty_claim_process, #warranty_care').val('');
    $('#warranty_preview').hide();
}

// Trigger auto-suggest when product is loaded
// Call this inside loadItemDetails() after setting category, price, brand
function triggerWarrantyAutoSuggest() {
    // Short delay to ensure values are set
    setTimeout(function() {
        autoSuggestWarranty();
    }, 100);
}
</script>
</body>
</html>