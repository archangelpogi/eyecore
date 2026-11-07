<?php
/**
 * Settings View — Clinic Settings
 * Loaded via main.php?view=settings
 */

if ($_SESSION['role'] !== 'ClinicAdmin') {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>Only Clinic Administrators can access System Settings.</p>
        </div>
    </div>
    <?php
    return;
}

$clinic_id = $_SESSION['clinic_id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM clinics WHERE id = ?");
$stmt->execute([$clinic_id]);
$clinic = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt_settings = $pdo->prepare("SELECT * FROM settings WHERE clinic_id = ?");
$stmt_settings->execute([$clinic_id]);
$settings = [];
while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$working_hours = [];
if (isset($settings['working_hours']) && !empty($settings['working_hours'])) {
    $working_hours = json_decode($settings['working_hours'], true);
}

$clinic['offers_delivery'] = $clinic['offers_delivery'] ?? 0;
$clinic['delivery_fee'] = $clinic['delivery_fee'] ?? 0.00;
$clinic['delivery_radius_km'] = $clinic['delivery_radius_km'] ?? 0;
$clinic['free_delivery_minimum'] = $clinic['free_delivery_minimum'] ?? 0.00;

$canEdit = true;
?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- ✅ CUSTOM STYLES — TEAL GREEN MODERN UI -->
<!-- ═══════════════════════════════════════════════════════ -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<!-- ✅ Leaflet (OpenStreetMap) — same as registration -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    :root {
        --settings-primary: #0d9488;
        --settings-primary-dark: #0f766e;
        --settings-accent: #14b8a6;
        --settings-bg: #f0fdfa;
        --settings-border: #e2e8f0;
        --settings-text: #1e293b;
        --settings-muted: #64748b;
    }

    /* 🔑 1. I-LOCK ANG BUONG PAGE SA SCREEN WIDTH */
    .settings-wrapper {
        background: var(--settings-bg);
        padding: 1.5rem;
        border-radius: 16px;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden; 
        box-sizing: border-box;
    }

    .settings-wrapper * {
        box-sizing: border-box;
    }

    .settings-header {
        background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
        color: white;
        padding: 1.75rem 2rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        box-shadow: 0 8px 24px rgba(13, 148, 136, 0.2);
    }
    .settings-header h3 { color: #fff; margin-bottom: 0.25rem; }
    .settings-header p { color: rgba(255,255,255,0.9); margin-bottom: 0; }

    /* Modern Tabs */
    .settings-tabs {
        border: none;
        gap: 0.5rem;
        background: #fff;
        padding: 0.5rem;
        border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    .settings-tabs .nav-link {
        border: none;
        border-radius: 10px;
        color: var(--settings-muted);
        font-weight: 600;
        padding: 0.75rem 1.25rem;
        white-space: nowrap;
        transition: all 0.2s ease;
    }
    .settings-tabs .nav-link:hover {
        background: #f0fdfa;
        color: var(--settings-primary);
    }
    .settings-tabs .nav-link.active {
        background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
    }

    /* Cards */
    .settings-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        overflow: hidden;
        width: 100%;
    }
    .settings-card .card-body { padding: 2rem; }

    /* Section Headers */
    .section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--settings-primary);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--settings-border);
        margin-bottom: 1.5rem;
    }

    /* Form Controls */
    .settings-card .form-control,
    .settings-card .form-select {
        border-radius: 10px;
        border: 1.5px solid var(--settings-border);
        padding: 0.65rem 0.9rem;
        transition: all 0.2s ease;
        max-width: 100%;
    }
    .settings-card .form-control:focus,
    .settings-card .form-select:focus {
        border-color: var(--settings-primary);
        box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.15);
    }
    .settings-card .form-label {
        font-weight: 600;
        color: var(--settings-text);
        font-size: 0.875rem;
        margin-bottom: 0.4rem;
    }

    /* Switches */
    .form-check-input:checked {
        background-color: var(--settings-primary);
        border-color: var(--settings-primary);
    }

    /* Buttons */
    .btn-settings-primary {
        background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
        border: none;
        color: white;
        font-weight: 600;
        padding: 0.65rem 1.5rem;
        border-radius: 10px;
        transition: all 0.2s ease;
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
    }

    /* Day Row */
    .day-row {
        padding: 1rem;
        border-radius: 12px;
        background: #f8fafc;
        margin-bottom: 0.75rem;
        border: 1.5px solid transparent;
    }
    .day-row .day-label {
        font-weight: 700;
        color: var(--settings-text);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Module Card */
    .module-card {
        background: #fff;
        border: 1.5px solid var(--settings-border);
        border-radius: 14px;
        padding: 1.1rem 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        width: 100%;
    }
    .module-card .module-icon {
        width: 42px;
        height: 42px;
        border-radius: 11px;
        background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--settings-primary);
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .module-card .module-name {
        font-weight: 700;
        color: var(--settings-text);
        font-size: 0.875rem;
        line-height: 1.3;
        word-break: break-word;
    }

    /* Upload Box */
    .upload-box {
        border: 2px dashed var(--settings-border);
        border-radius: 14px;
        padding: 1.25rem;
        text-align: center;
        background: #fafbfc;
        width: 100%;
    }
    .upload-box img {
        border-radius: 10px;
        max-width: 100% !important;
    }
    .upload-box .upload-label {
        display: inline-block;
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
        color: white;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        margin-top: 0.75rem;
    }
    .upload-box input[type="file"] { display: none; }

    /* Tables (Default Desktop) */
    .settings-card .table-responsive {
        width: 100%;
        overflow-x: auto;
    }
    .settings-card .table {
        margin-bottom: 0;
        width: 100%;
    }
    .settings-card .table thead th {
        background: #f8fafc;
        color: var(--settings-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        padding: 0.9rem 1rem;
    }
    .settings-card .table tbody td {
        padding: 1rem;
        vertical-align: middle;
    }

            /* ✅ Location Map (same as registration) */
        #settingsClinicMap {
            width: 100%;
            height: 260px;
            border-radius: 12px;
            margin-top: 10px;
            border: 1.5px solid var(--settings-border);
            display: none;
        }
        #settingsClinicMap.show { display: block; }

        .btn-location {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: .8rem;
            font-weight: 700;
            color: var(--settings-primary);
            background: #f0fdfa;
            border: 1.5px solid #ccfbf1;
            border-radius: 8px;
            padding: 8px 16px;
            cursor: pointer;
            transition: all .2s;
            margin-top: 6px;
        }
        .btn-location:hover {
            background: var(--settings-primary);
            color: white;
            border-color: var(--settings-primary);
        }
        .btn-location.loading { opacity: .7; pointer-events: none; }
        .btn-location i { font-size: 1rem; }

        .location-status {
            font-size: .75rem;
            margin-top: 6px;
            display: none;
            padding: 6px 10px;
            border-radius: 8px;
        }
        .location-status.success { 
            color: #065f46; 
            background: #d1fae5;
            display: block; 
        }
        .location-status.error { 
            color: #991b1b; 
            background: #fee2e2;
            display: block; 
        }

    /* ═══════════════════════════════════════════════════════
       📱 MOBILE RESPONSIVE FIX (PAGE & MODALS)
       ═══════════════════════════════════════════════════════ */
    @media (max-width: 767.98px) {
        /* PAGE FIX */
        .settings-wrapper {
            padding: 0.5rem !important;
            border-radius: 12px;
            width: 100vw !important; 
            max-width: 100vw !important;
            overflow-x: hidden !important;
        }
        .settings-wrapper > * { max-width: 100% !important; }

        .settings-header { padding: 1rem 1.1rem; margin-bottom: 1rem; border-radius: 12px; }
        .settings-header h3 { font-size: 1.05rem; }
        .settings-header p { font-size: 0.72rem; margin-bottom: 0.6rem; }
        .settings-header .btn { width: 100%; padding: 0.5rem; font-size: 0.8rem; }

        /* TABS */
        .settings-tabs { padding: 0.4rem; gap: 0.3rem; margin-bottom: 1rem !important; overflow-x: auto; }
        .settings-tabs::-webkit-scrollbar { display: none; }
        .settings-tabs .nav-link { padding: 0.5rem 0.85rem; font-size: 0.72rem; }

        /* CARDS & FORM */
        .settings-card { border-radius: 12px; }
        .settings-card .card-body { padding: 1rem; }
        .section-title { font-size: 0.78rem; padding-bottom: 0.55rem; margin-bottom: 1rem; }
        .settings-card .form-control, .settings-card .form-select { font-size: 0.82rem; padding: 0.5rem 0.7rem; }
        .settings-card .form-label { font-size: 0.75rem; }
        
        /* Force grid to 1 column */
        .settings-card .row > [class*="col-"] { flex: 0 0 100% !important; max-width: 100% !important; }
        .btn-settings-primary { padding: 0.55rem 1rem; font-size: 0.8rem; width: 100%; }

        /* DAY ROWS */
        .day-row { padding: 0.7rem; border-radius: 10px; margin-bottom: 0.55rem; }
        .day-row .row { display: flex !important; flex-direction: column !important; gap: 0.5rem !important; margin: 0 !important; }
        .day-row .row > [class*="col-"] { width: 100% !important; max-width: 100% !important; padding: 0 !important; }

        /* MODULE CARDS */
        .module-card { padding: 0.75rem 0.85rem; border-radius: 10px; }
        .module-card .module-icon { width: 34px; height: 34px; font-size: 0.9rem; }
        .module-card .module-name { font-size: 0.78rem; }

        /* 🔑 TABLES -> CARD VIEW */
        .settings-card .table-responsive { overflow: visible !important; width: 100% !important; }
        .settings-card .table { display: block !important; width: 100% !important; max-width: 100% !important; }
        .settings-card .table thead { display: none !important; }
        .settings-card .table tbody, .settings-card .table tr, .settings-card .table td { display: block !important; width: 100% !important; max-width: 100% !important; }
        .settings-card .table tbody tr { background: #fff; border: 1px solid var(--settings-border); border-radius: 10px; padding: 0.6rem 0.75rem; margin-bottom: 0.55rem; }
        .settings-card .table tbody td { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; padding: 0.3rem 0 !important; border: none !important; font-size: 0.78rem !important; text-align: right; line-height: 1.3; word-break: break-word !important; }
        .settings-card .table tbody td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 0.6rem; text-transform: uppercase; text-align: left; flex-shrink: 0; }
        .settings-card .table tbody td:first-child { border-bottom: 1px dashed var(--settings-border) !important; padding-bottom: 0.45rem !important; margin-bottom: 0.3rem; }
        .settings-card .table tbody td:first-child::before { display: none; }
        .settings-card .table tbody td:first-child strong { font-size: 0.88rem; display: block; text-align: left; width: 100%; }
        .settings-card .table tbody td[colspan] { display: block; text-align: center; }
        .settings-card .table tbody td[colspan]::before { display: none; }

        /* 🔑 2. MODAL FIX (Detach from sidebar & center it) */
        .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 1060 !important; /* Ensure it's above everything */
        }
        .modal-dialog {
            margin: 1.5rem auto !important; 
            max-width: calc(100vw - 1.5rem) !important;
            width: calc(100vw - 1.5rem) !important;
            min-height: calc(100vh - 3rem);
            display: flex;
            align-items: center; /* Center vertically */
        }
        .modal-content {
            width: 100% !important;
            border-radius: 16px !important;
            border: none !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .modal-body {
            padding: 1.25rem !important;
            max-height: calc(100vh - 200px); /* Scrollable body */
            overflow-y: auto;
        }
        .modal-header, .modal-footer {
            padding: 1rem !important;
            flex-shrink: 0; /* Huwag hayaang mag-shrink */
        }
        .modal-footer {
            gap: 0.5rem;
            border-radius: 0 0 16px 16px;
        }
        .modal-footer .btn {
            flex: 1;
            padding: 0.7rem;
            font-size: 0.85rem;
            border-radius: 10px;
        }
        /* Force form fields inside modal to be full width */
        .modal-body .row > [class*="col-"] {
            flex: 0 0 100% !important;
            max-width: 100% !important;
        }
        /* Fix styling inside modal */
        .modal-body .form-label { font-size: 0.8rem; }
        .modal-body .form-control, .modal-body .form-select { font-size: 0.85rem; padding: 0.6rem 0.8rem; }
    }

        .province-lock {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: .72rem;
        font-weight: 700;
        color: var(--settings-primary-dark);
        background: #f0fdfa;
        border: 1px solid #ccfbf1;
        border-radius: 6px;
        padding: 3px 9px;
        margin-left: 6px;
    }

    /* Extra small phones */
    @media (max-width: 374.98px) {
        .settings-tabs .nav-link { padding: 0.45rem 0.7rem; font-size: 0.68rem; }
        .settings-card .table tbody td { font-size: 0.72rem !important; }
        .modal-dialog { margin: 0.5rem auto !important; max-width: calc(100vw - 1rem) !important; width: calc(100vw - 1rem) !important; }
    }
</style>
<div class="container-fluid settings-wrapper">

    <!-- ═══════════ HEADER ═══════════ -->
    <div class="settings-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h3 class="fw-bold">
                <i class="bi bi-gear-fill me-2"></i>System Settings
            </h3>
            <p>Configure your clinic preferences and system options</p>
        </div>
        <button class="btn btn-light fw-bold" onclick="saveAllSettings()">
            <i class="bi bi-cloud-check me-2"></i>Save All Changes
        </button>
    </div>

    <!-- ═══════════ TABS ═══════════ -->
    <ul class="nav nav-pills settings-tabs mb-4" id="settingsTab">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#clinic_details">
                <i class="bi bi-building me-1"></i>Clinic Details
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#hours">
                <i class="bi bi-clock me-1"></i>Working Hours
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#module_visibility">
                <i class="bi bi-grid-3x3-gap me-1"></i>Module Visibility
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#delivery">
                <i class="bi bi-truck me-1"></i>Delivery Settings
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#documents_contract">
                <i class="bi bi-file-earmark-text me-1"></i>Documents & Contract
            </button>
        </li>
    </ul>

    <!-- ═══════════ TAB CONTENT ═══════════ -->
    <div class="tab-content">

    <!-- ═══════════════════════════════════════════════════ -->
<!-- CLINIC DETAILS TAB -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="clinic_details">
    <div class="card settings-card">
        <div class="card-body">

            <!-- BRANDING SECTION -->
            <div class="section-title">
                <i class="bi bi-image-fill"></i> Clinic Branding
            </div>

            <div class="row g-3 mb-4">
                <!-- Logo Upload -->
                <div class="col-12 col-md-4">
                    <div class="upload-box">
                        <img id="logoPreview" 
                             src="<?php echo !empty($clinic['logo']) ? 'assets/images/clinic-logos/' . htmlspecialchars($clinic['logo']) : 'https://via.placeholder.com/200x200/0d9488/ffffff?text=LOGO'; ?>" 
                             alt="Clinic Logo" 
                             class="img-fluid mb-2" 
                             style="max-height:130px; max-width:130px; object-fit:contain;">
                        <div class="fw-bold small text-muted">Clinic Logo</div>
                        <label for="clinic_logo_input" class="upload-label">
                            <i class="bi bi-cloud-upload me-1"></i>Upload Logo
                        </label>
                        <input type="file" 
                               id="clinic_logo_input" 
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               onchange="uploadClinicLogo(this)">
                        <div class="small text-muted mt-2">
                            JPG, PNG, GIF, WEBP • Max 2MB
                        </div>
                    </div>
                </div>

                <!-- Cover Upload -->
                <div class="col-12 col-md-8">
                    <div class="upload-box">
                        <img id="coverPreview" 
                             src="<?php echo !empty($clinic['cover_photo']) ? 'assets/images/clinic-covers/' . htmlspecialchars($clinic['cover_photo']) : 'https://via.placeholder.com/800x200/ccfbf1/0d9488?text=COVER+PHOTO'; ?>" 
                             alt="Cover Photo" 
                             class="img-fluid mb-2" 
                             style="max-height:130px; width:100%; object-fit:cover;">
                        <div class="fw-bold small text-muted">Cover Photo</div>
                        <label for="clinic_cover_input" class="upload-label">
                            <i class="bi bi-cloud-upload me-1"></i>Upload Cover
                        </label>
                        <input type="file" 
                               id="clinic_cover_input" 
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               onchange="uploadClinicCover(this)">
                        <div class="small text-muted mt-2">
                            JPG, PNG, GIF, WEBP • Max 2MB • Recommended 1200×400
                        </div>
                    </div>
                </div>
            </div>

            <!-- BASIC INFORMATION -->
            <div class="section-title">
                <i class="bi bi-info-circle-fill"></i> Basic Information
            </div>

            <form id="clinicDetailsForm" class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label">Clinic Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="clinic_name" 
                           value="<?php echo htmlspecialchars($clinic['clinic_name'] ?? ''); ?>" required>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="clinic_status">
                        <option value="Active" <?php echo ($clinic['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>● Active</option>
                        <option value="Suspended" <?php echo ($clinic['status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>● Suspended</option>
                    </select>
                </div>

                <!-- ✅ STREET ADDRESS (Single field — may Use Current Location + Cavite lock) -->
                <div class="col-12">
                    <label class="form-label">
                        Street Address <span class="text-danger">*</span>
                        <span class="province-lock"><i class="bi bi-lock-fill"></i> Cavite Province Only</span>
                    </label>
                    <input type="text" class="form-control" id="clinic_address" 
                           value="<?php echo htmlspecialchars($clinic['address'] ?? ''); ?>" required
                           placeholder="e.g. 123 Rizal St.">

                    <!-- Use Current Location Button -->
                    <div class="mt-2">
                        <button type="button" class="btn-location" id="settingsUseLocationBtn" onclick="settingsGetCurrentLocation()">
                            <i class="bi bi-geo-alt-fill"></i>
                            Use Current Location
                        </button>
                    </div>
                    <div class="location-status" id="settingsLocationStatus"></div>
                    <div id="settingsClinicMap"></div>
                    <div class="small text-muted mt-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Click the button or tap the map to auto-fill. Only locations within <strong>Cavite</strong> are allowed.
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="clinic_contact" 
                           value="<?php echo htmlspecialchars($clinic['contact'] ?? ''); ?>" required>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" id="clinic_email" 
                           value="<?php echo htmlspecialchars($clinic['clinic_email'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" class="form-control" id="clinic_city" 
                           value="<?php echo htmlspecialchars($clinic['city'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control" id="clinic_province" 
                           value="<?php echo htmlspecialchars($clinic['province'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label">Postal Code</label>
                    <input type="text" class="form-control" id="clinic_postal" 
                           value="<?php echo htmlspecialchars($clinic['postal_code'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label"><i class="bi bi-geo me-1"></i>Latitude</label>
                    <input type="text" class="form-control" id="clinic_lat" 
                           value="<?php echo htmlspecialchars($clinic['latitude'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Longitude</label>
                    <input type="text" class="form-control" id="clinic_lng" 
                           value="<?php echo htmlspecialchars($clinic['longitude'] ?? ''); ?>">
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label"><i class="bi bi-bullseye me-1"></i>Radius (meters)</label>
                    <input type="number" class="form-control" id="clinic_radius" 
                           value="<?php echo htmlspecialchars($clinic['radius'] ?? 100); ?>">
                </div>

                <div class="col-12 mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-settings-primary" onclick="saveClinicDetails()">
                        <i class="bi bi-check-circle me-2"></i>Update Clinic Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- WORKING HOURS TAB -->
        <!-- ═══════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="hours">
            <div class="card settings-card">
                <div class="card-body">
                    <div class="section-title">
                        <i class="bi bi-clock-history"></i> Weekly Operating Hours
                    </div>

                    <form id="hoursForm">
                        <?php
                        $days_display = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        $days_key = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        
                        for ($i = 0; $i < count($days_display); $i++):
                            $day_display = $days_display[$i];
                            $day_key = $days_key[$i];
                            $day_data = isset($working_hours[$day_key]) ? $working_hours[$day_key] : ['enabled' => false, 'open' => '09:00', 'close' => '18:00'];
                            $is_active = !empty($day_data['enabled']);
                        ?>
                        <div class="day-row <?php echo $is_active ? 'day-active' : ''; ?>" data-day-row="<?php echo $day_key; ?>">
                            <div class="row g-3 align-items-center">
                                <div class="col-12 col-md-3">
                                    <div class="day-label"><?php echo $day_display; ?></div>
                                </div>
                                <div class="col-12 col-md-2">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input day-toggle" 
                                               type="checkbox" 
                                               id="toggle_<?php echo $day_key; ?>"
                                               data-day="<?php echo $day_key; ?>"
                                               <?php echo $is_active ? 'checked' : ''; ?>>
                                        <label class="form-check-label small ms-1" for="toggle_<?php echo $day_key; ?>">
                                            <?php echo $is_active ? 'Open' : 'Closed'; ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12 col-md-7">
                                    <div class="row g-2">
                                        <div class="col">
                                            <input type="time" 
                                                   class="form-control open-time" 
                                                   data-day="<?php echo $day_key; ?>"
                                                   value="<?php echo htmlspecialchars($day_data['open'] ?? '09:00'); ?>"
                                                   <?php echo $is_active ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-auto d-flex align-items-center text-muted">
                                            <i class="bi bi-arrow-right"></i>
                                        </div>
                                        <div class="col">
                                            <input type="time" 
                                                   class="form-control close-time" 
                                                   data-day="<?php echo $day_key; ?>"
                                                   value="<?php echo htmlspecialchars($day_data['close'] ?? '18:00'); ?>"
                                                   <?php echo $is_active ? '' : 'disabled'; ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>

                        <div class="mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-settings-primary" onclick="saveWorkingHours()">
                                <i class="bi bi-check-circle me-2"></i>Update Working Hours
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- MODULE VISIBILITY TAB -->
        <!-- ═══════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="module_visibility">
            <div class="card settings-card">
                <div class="card-body">
                    <div class="mb-4">
                        <div class="section-title mb-2">
                            <i class="bi bi-grid-3x3-gap-fill"></i> Module Visibility
                        </div>
                        <p class="text-muted small mb-0">
                            Enable or disable modules for your clinic.
                        </p>
                    </div>

                    <div id="moduleVisibilityContainer">
                        <div class="text-center py-5">
                            <div class="spinner-border text-settings-primary"></div>
                            <div class="mt-3 text-muted">Loading modules...</div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <button class="btn btn-settings-primary" onclick="saveModuleVisibility()">
                            <i class="bi bi-save me-2"></i>Save Module Visibility
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- DELIVERY SETTINGS TAB -->
        <!-- ═══════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="delivery">
            <div class="card settings-card">
                <div class="card-body">
                    <div class="section-title">
                        <i class="bi bi-truck"></i> Delivery Configuration
                    </div>

                    <p class="text-muted small mb-4">
                        Configure delivery options for customer orders. When enabled, customers can choose delivery instead of pickup during checkout.
                    </p>

                    <form id="deliverySettingsForm">
                        <div class="row g-3">

                            <!-- Enable Delivery -->
                            <div class="col-12">
                                <div class="delivery-highlight">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="offers_delivery" 
                                               <?php echo ($clinic['offers_delivery'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="offers_delivery">
                                            <i class="bi bi-truck me-1"></i>Enable Delivery Service
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        Allow customers to have products delivered to their address
                                    </div>
                                </div>
                            </div>

                            <!-- Delivery Fee -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-cash-coin me-1 text-settings-primary"></i>Base Delivery Fee (₱)
                                </label>
                                <input type="number" step="0.01" min="0" class="form-control" id="delivery_fee" 
                                       value="<?php echo htmlspecialchars($clinic['delivery_fee'] ?? 0.00); ?>">
                                <small class="text-muted">Standard delivery fee per order</small>
                            </div>

                            <!-- Delivery Radius -->
                            <div class="col-12 col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-bullseye me-1 text-settings-primary"></i>Delivery Radius (km)
                                </label>
                                <input type="number" step="1" min="0" class="form-control" id="delivery_radius_km" 
                                       value="<?php echo htmlspecialchars($clinic['delivery_radius_km'] ?? 0); ?>">
                                <small class="text-muted">Maximum delivery distance (0 = unlimited)</small>
                            </div>

                            <!-- Free Delivery Toggle -->
                            <?php $has_free_delivery = ($clinic['free_delivery_minimum'] ?? 0) > 0; ?>
                            <div class="col-12 mt-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="enable_free_delivery" 
                                           <?php echo $has_free_delivery ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="enable_free_delivery">
                                        <i class="bi bi-gift me-1 text-success"></i>Enable Free Delivery
                                    </label>
                                    <div class="text-muted small">
                                        Customers get free delivery when their order meets a minimum amount
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-6" id="free_delivery_container" 
                                 style="<?php echo $has_free_delivery ? '' : 'display:none;'; ?>">
                                <label class="form-label">
                                    <i class="bi bi-gift-fill me-1 text-success"></i>Free Delivery Minimum (₱)
                                </label>
                                <input type="number" step="0.01" min="0" class="form-control" id="free_delivery_minimum" 
                                       value="<?php echo htmlspecialchars($clinic['free_delivery_minimum'] ?? 0.00); ?>"
                                       <?php echo $has_free_delivery ? '' : 'disabled'; ?>>
                                <small class="text-muted">Minimum order amount for free delivery</small>
                            </div>

                            <div class="col-12 mt-4 pt-3 border-top">
                                <button type="button" class="btn btn-settings-primary" onclick="saveDeliverySettings()">
                                    <i class="bi bi-check-circle me-2"></i>Save Delivery Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- CITY FEES -->
            <div class="card settings-card mt-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                        <div>
                            <div class="section-title mb-2">
                                <i class="bi bi-geo-alt-fill"></i> City Delivery Fees
                            </div>
                            <p class="text-muted small mb-0">
                                Set specific delivery fees per city. Customers see these during checkout.
                            </p>
                        </div>
                        <button type="button" class="btn btn-settings-primary btn-sm" onclick="openCityModal()">
                            <i class="bi bi-plus-circle me-1"></i>Add City
                        </button>
                    </div>

                    <div id="citiesListContainer">
                        <div class="text-center py-5">
                            <div class="spinner-border text-settings-primary"></div>
                            <div class="mt-3 text-muted">Loading cities...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- DOCUMENTS & CONTRACT TAB -->
        <!-- ═══════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="documents_contract">
            
            <!-- SECTION 1: CLINIC DOCUMENTS -->
            <div class="card settings-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                        <div>
                            <div class="section-title mb-2">
                                <i class="bi bi-file-earmark-text-fill"></i> Clinic Documents
                            </div>
                            <p class="text-muted small mb-0">
                                Upload and manage your clinic's required documents for verification.
                            </p>
                        </div>
                    </div>

                    <div id="documentsListContainer">
                        <div class="text-center py-5">
                            <div class="spinner-border text-settings-primary"></div>
                            <div class="mt-3 text-muted">Loading documents...</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: EYECORE CONTRACT -->
            <div class="card settings-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                        <div>
                            <div class="section-title mb-2">
                                <i class="bi bi-file-earmark-pdf-fill"></i> EyeCore Contract
                            </div>
                            <p class="text-muted small mb-0">
                                Your signed service agreement with EyeCore.
                            </p>
                        </div>
                    </div>

                    <div id="contractContainer">
                        <div class="text-center py-5">
                            <div class="spinner-border text-settings-primary"></div>
                            <div class="mt-3 text-muted">Loading contract...</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════ -->
<!-- CITY MODAL -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="cityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color:white; border:none;">
                <h5 class="modal-title" id="cityModalTitle">
                    <i class="bi bi-geo-alt me-2"></i>Add City
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="city_id">

                <div class="mb-3">
                    <label class="form-label fw-semibold">City / Municipality <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="city_name" 
                           placeholder="e.g. Dasmariñas, Imus, Bacoor">
                    <small class="text-muted">Exact city name (matches customer's selection)</small>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Region</label>
                        <input type="text" class="form-control" id="city_region" 
                               placeholder="e.g. CALABARZON">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Barangay <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" id="city_barangay" 
                               placeholder="e.g. Poblacion">
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Delivery Fee (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="city_fee" 
                               step="0.01" min="1" placeholder="e.g. 50.00">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Estimated Days</label>
                        <input type="number" class="form-control" id="city_days" 
                               min="1" max="30" value="1">
                    </div>
                </div>

                <div class="mt-3 pt-3 border-top">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="city_active" checked>
                        <label class="form-check-label fw-semibold" for="city_active">
                            Active (visible to customers)
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-settings-primary" onclick="saveCity()">
                    <i class="bi bi-save me-1"></i>Save City
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════ -->
<!-- UPLOAD DOCUMENT MODAL -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="docModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color:white; border:none;">
                <h5 class="modal-title" id="docModalTitle">
                    <i class="bi bi-cloud-upload me-2"></i>Upload Document
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="doc_id">
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Document Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="doc_type">
                        <option value="">-- Select Document Type --</option>
                        <option value="Business Permit">Business Permit</option>
                        <option value="DTI">DTI</option>
                        <option value="SEC">SEC</option>
                        <option value="BIR">BIR</option>
                        <option value="Optometrist License">Optometrist License</option>
                        <option value="Barangay Clearance">Barangay Clearance</option>
                        <option value="Health Clearance">Health Clearance</option>
                        <option value="Insurance">Insurance</option>
                        <option value="Other Documents">Other Documents</option>
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Owner Name</label>
                        <input type="text" class="form-control" id="doc_owner" 
                               placeholder="e.g. Juan Dela Cruz">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Released Date</label>
                        <input type="date" class="form-control" id="doc_released">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <input type="date" class="form-control" id="doc_expires">
                        <small class="text-muted">Leave blank if no expiry</small>
                    </div>
                </div>

                <div class="mb-3 mt-3" id="doc_file_container">
                    <label class="form-label fw-semibold">File <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="doc_file" 
                           accept=".pdf,.jpg,.jpeg,.png">
                    <small class="text-muted">PDF, JPG, PNG • Max 5MB</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Remarks</label>
                    <textarea class="form-control" id="doc_remarks" rows="2" 
                              placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-settings-primary" id="docSubmitBtn" onclick="uploadDocument()">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════ -->
<!-- UPLOAD CONTRACT MODAL -->
<!-- ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="contractModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%); color:white; border:none;">
                <h5 class="modal-title" id="contractModalTitle">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Upload Contract
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Contract No.</label>
                        <input type="text" class="form-control" id="contract_no" 
                               placeholder="e.g. EC-2025-0001">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Start Date</label>
                        <input type="date" class="form-control" id="contract_start">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">End Date</label>
                        <input type="date" class="form-control" id="contract_end">
                    </div>
                </div>

                <div class="mb-3 mt-3" id="contract_file_container">
                    <label class="form-label fw-semibold">Contract PDF <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" id="contract_file" accept=".pdf">
                    <small class="text-muted">PDF only • Max 10MB</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea class="form-control" id="contract_notes" rows="2" 
                              placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-settings-primary" onclick="uploadContract()">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
});

// ═══════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ═══════════════════════════════════════════════════════
// CLINIC LOGO & COVER UPLOAD
// ═══════════════════════════════════════════════════════

function uploadClinicLogo(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 2 * 1024 * 1024) {
        Toast.fire({ icon: 'error', title: 'File exceeds 2MB limit' });
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('logoPreview').src = e.target.result;
    };
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('clinic_logo', file);

    Swal.fire({
        title: 'Uploading logo...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Logo uploaded!' });
            document.getElementById('logoPreview').src = 
                'assets/images/clinic-logos/' + result.filename + '?t=' + Date.now();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Upload failed' });
            input.value = '';
        }
    })
    .catch(err => {
        Swal.close();
        console.error(err);
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

function uploadClinicCover(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 2 * 1024 * 1024) {
        Toast.fire({ icon: 'error', title: 'File exceeds 2MB limit' });
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('coverPreview').src = e.target.result;
    };
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('clinic_cover', file);

    Swal.fire({
        title: 'Uploading cover...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Cover uploaded!' });
            document.getElementById('coverPreview').src = 
                'assets/images/clinic-covers/' + result.filename + '?t=' + Date.now();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Upload failed' });
            input.value = '';
        }
    })
    .catch(err => {
        Swal.close();
        console.error(err);
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

function saveClinicDetails() {
    const getVal = (id) => {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
    };
    
    const data = {
        name: getVal('clinic_name'),
        description: getVal('clinic_description'),
        contact: getVal('clinic_contact'),
        email: getVal('clinic_email'),
        address: getVal('clinic_address'),
        city: getVal('clinic_city'),
        province: getVal('clinic_province'),
        postal: getVal('clinic_postal'),
        latitude: getVal('clinic_lat'),
        longitude: getVal('clinic_lng'),
        radius: parseInt(getVal('clinic_radius')) || 100,
        status: getVal('clinic_status') || 'Active'
    };

    if (!data.name || !data.contact || !data.address) {
        Toast.fire({ icon: 'warning', title: 'Please fill in required fields' });
        return;
    }

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'clinic_details', data: data })
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Clinic details saved!' });
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

// ═══════════════════════════════════════════════════════
// WORKING HOURS
// ═══════════════════════════════════════════════════════

function toggleDayRow(toggle) {
    const dayKey = toggle.dataset.day;
    const row = document.querySelector(`[data-day-row="${dayKey}"]`);
    const openInput = document.querySelector(`.open-time[data-day="${dayKey}"]`);
    const closeInput = document.querySelector(`.close-time[data-day="${dayKey}"]`);
    const label = toggle.nextElementSibling;

    if (toggle.checked) {
        row.classList.add('day-active');
        openInput.disabled = false;
        closeInput.disabled = false;
        if (label) label.textContent = 'Open';
    } else {
        row.classList.remove('day-active');
        openInput.disabled = true;
        closeInput.disabled = true;
        if (label) label.textContent = 'Closed';
    }
}

function saveWorkingHours() {
    const data = {};
    document.querySelectorAll('.day-toggle').forEach(toggle => {
        const day = toggle.dataset.day;
        const openInput = document.querySelector(`.open-time[data-day="${day}"]`);
        const closeInput = document.querySelector(`.close-time[data-day="${day}"]`);

        data[day] = {
            enabled: toggle.checked,
            open: toggle.checked ? openInput.value : '',
            close: toggle.checked ? closeInput.value : ''
        };
    });

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'working_hours', data: data })
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Working hours saved!' });
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

// ═══════════════════════════════════════════════════════
// DELIVERY SETTINGS
// ═══════════════════════════════════════════════════════

function toggleFreeDelivery() {
    const toggle = document.getElementById('enable_free_delivery');
    const container = document.getElementById('free_delivery_container');
    const input = document.getElementById('free_delivery_minimum');

    if (!toggle || !container || !input) return;

    if (toggle.checked) {
        container.style.display = 'block';
        input.disabled = false;
        if (!input.value || parseFloat(input.value) === 0) {
            input.value = 500.00;
        }
    } else {
        container.style.display = 'none';
        input.disabled = true;
        input.value = 0;
    }
}

function toggleDeliveryFields() {
    const toggle = document.getElementById('offers_delivery');
    const isEnabled = toggle ? toggle.checked : false;

    ['delivery_fee', 'delivery_radius_km'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = !isEnabled;
            el.style.opacity = isEnabled ? '1' : '0.5';
            el.style.backgroundColor = isEnabled ? '' : '#e9ecef';
        }
    });

    const freeToggle = document.getElementById('enable_free_delivery');
    if (freeToggle) {
        freeToggle.disabled = !isEnabled;
        freeToggle.style.opacity = isEnabled ? '1' : '0.5';
    }

    if (!isEnabled) {
        const container = document.getElementById('free_delivery_container');
        if (container) container.style.display = 'none';
        const input = document.getElementById('free_delivery_minimum');
        if (input) {
            input.disabled = true;
            input.value = 0;
        }
    } else {
        if (freeToggle && freeToggle.checked) {
            const container = document.getElementById('free_delivery_container');
            if (container) container.style.display = 'block';
            const input = document.getElementById('free_delivery_minimum');
            if (input) input.disabled = false;
        }
    }
}

function loadDeliverySettings() {
    fetch('api/settings.php?action=get_delivery_settings')
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data) {
                const data = result.data;

                const toggle = document.getElementById('offers_delivery');
                if (toggle) toggle.checked = data.offers_delivery == 1;

                const feeEl = document.getElementById('delivery_fee');
                if (feeEl) feeEl.value = data.delivery_fee || 0;

                const radiusEl = document.getElementById('delivery_radius_km');
                if (radiusEl) radiusEl.value = data.delivery_radius_km || 0;

                const freeEl = document.getElementById('free_delivery_minimum');
                if (freeEl) freeEl.value = data.free_delivery_minimum || 0;

                const freeToggle = document.getElementById('enable_free_delivery');
                if (freeToggle) {
                    freeToggle.checked = (data.free_delivery_minimum || 0) > 0;
                }

                toggleDeliveryFields();
                toggleFreeDelivery();
            }
        })
        .catch(err => console.error('Error loading delivery settings:', err));
}

function saveDeliverySettings() {
    const toggle = document.getElementById('offers_delivery');
    const freeToggle = document.getElementById('enable_free_delivery');
    const freeInput = document.getElementById('free_delivery_minimum');
    const feeInput = document.getElementById('delivery_fee');
    const radiusInput = document.getElementById('delivery_radius_km');

    let freeDeliveryAmount = 0;
    if (freeToggle && freeToggle.checked && freeInput) {
        freeDeliveryAmount = parseFloat(freeInput.value) || 0;
    }

    const data = {
        offers_delivery: toggle ? (toggle.checked ? 1 : 0) : 0,
        delivery_fee: feeInput ? (parseFloat(feeInput.value) || 0) : 0,
        delivery_radius_km: radiusInput ? (parseInt(radiusInput.value) || 0) : 0,
        free_delivery_minimum: freeDeliveryAmount
    };

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'delivery_settings', data: data })
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Delivery settings saved!' });
            loadDeliverySettings();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}


// ═══════════════════════════════════════════════════════
// DELIVERY FEES (CITIES)
// ═══════════════════════════════════════════════════════

let cityModalInstance = null;

function loadDeliveryFees() {
    const container = document.getElementById('citiesListContainer');
    if (!container) return;

    fetch('api/settings.php?action=get_delivery_fees')
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                renderCitiesList(result.data);
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger settings-alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Failed to load cities: ${result.error || 'Unknown error'}
                    </div>`;
            }
        })
        .catch(err => {
            console.error('Error:', err);
            container.innerHTML = `
                <div class="alert alert-danger settings-alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Network error. Please refresh.
                </div>`;
        });
}

function renderCitiesList(cities) {
    const container = document.getElementById('citiesListContainer');

    if (!cities || cities.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info settings-alert text-center py-4">
                <i class="bi bi-geo display-4 d-block mb-2 text-settings-primary"></i>
                <strong>No cities added yet</strong>
                <div class="small text-muted mt-1">Click "Add City" to start adding delivery locations.</div>
            </div>`;
        return;
    }

    let html = `
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>City</th>
                        <th>Region</th>
                        <th class="text-end">Fee (₱)</th>
                        <th class="text-center">Days</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>`;

    cities.forEach(city => {
        const fee = parseFloat(city.fee_amount).toFixed(2);
        const isActive = city.is_active == 1;

        html += `
            <tr>
                <td data-label="City"><strong>${escapeHtml(city.city)}</strong></td>
                <td data-label="Region"><small class="text-muted">${escapeHtml(city.region || '—')}</small></td>
                <td class="text-end fw-bold text-settings-primary" data-label="Fee">₱${fee}</td>
                <td class="text-center" data-label="Days">
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-clock me-1"></i>${city.estimated_days}d
                    </span>
                </td>
                <td class="text-center" data-label="Status">
                    <span class="badge bg-${isActive ? 'success' : 'secondary'}">
                        ${isActive ? '● Active' : '● Inactive'}
                    </span>
                </td>
                <td class="text-end" data-label="Actions">
                    <button class="btn btn-sm btn-outline-primary" onclick="editCity(${city.id})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteCity(${city.id}, '${escapeHtml(city.city)}')" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
    });

    html += `</tbody></table></div>`;
    container.innerHTML = html;
}

function openCityModal() {
    document.getElementById('cityModalTitle').innerHTML = '<i class="bi bi-geo-alt me-2"></i>Add City';
    document.getElementById('city_id').value = '';
    document.getElementById('city_name').value = '';
    document.getElementById('city_region').value = '';
    document.getElementById('city_barangay').value = '';
    document.getElementById('city_fee').value = '';
    document.getElementById('city_days').value = '1';
    document.getElementById('city_active').checked = true;

    if (!cityModalInstance) {
        cityModalInstance = new bootstrap.Modal(document.getElementById('cityModal'));
    }
    cityModalInstance.show();
}

function editCity(id) {
    fetch('api/settings.php?action=get_delivery_fees')
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                Toast.fire({ icon: 'error', title: 'Failed to load city' });
                return;
            }

            const city = result.data.find(c => c.id == id);
            if (!city) {
                Toast.fire({ icon: 'error', title: 'City not found' });
                return;
            }

            document.getElementById('cityModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit City';
            document.getElementById('city_id').value = city.id;
            document.getElementById('city_name').value = city.city;
            document.getElementById('city_region').value = city.region || '';
            document.getElementById('city_barangay').value = city.barangay || '';
            document.getElementById('city_fee').value = city.fee_amount;
            document.getElementById('city_days').value = city.estimated_days;
            document.getElementById('city_active').checked = city.is_active == 1;

            if (!cityModalInstance) {
                cityModalInstance = new bootstrap.Modal(document.getElementById('cityModal'));
            }
            cityModalInstance.show();
        });
}

function saveCity() {
    const id = document.getElementById('city_id').value;
    const city = document.getElementById('city_name').value.trim();
    const region = document.getElementById('city_region').value.trim();
    const barangay = document.getElementById('city_barangay').value.trim();
    const fee = parseFloat(document.getElementById('city_fee').value) || 0;
    const days = parseInt(document.getElementById('city_days').value) || 1;
    const isActive = document.getElementById('city_active').checked ? 1 : 0;

    if (!city) {
        Toast.fire({ icon: 'warning', title: 'Please enter city name' });
        return;
    }
    if (fee <= 0) {
        Toast.fire({ icon: 'warning', title: 'Please enter valid fee amount' });
        return;
    }

    const isEdit = id && id > 0;
    const type = isEdit ? 'update_delivery_fee' : 'add_delivery_fee';

    const payload = {
        type: type,
        data: {
            city: city,
            region: region,
            barangay: barangay,
            fee_amount: fee,
            estimated_days: days,
            is_active: isActive
        }
    };

    if (isEdit) payload.data.id = parseInt(id);

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            if (cityModalInstance) cityModalInstance.hide();
            Toast.fire({ icon: 'success', title: isEdit ? 'City updated!' : 'City added!' });
            loadDeliveryFees();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

function deleteCity(id, cityName) {
    Swal.fire({
        title: 'Delete City?',
        html: `Are you sure you want to delete <strong>${escapeHtml(cityName)}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Deleting...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'delete_delivery_fee',
                data: { id: id }
            })
        })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                Toast.fire({ icon: 'success', title: 'City deleted!' });
                loadDeliveryFees();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Failed to delete' });
            }
        })
        .catch(err => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
    });
}

// ═══════════════════════════════════════════════════════
// MODULE VISIBILITY
// ═══════════════════════════════════════════════════════

function loadModuleVisibility() {
    const container = document.getElementById('moduleVisibilityContainer');
    if (!container) return;

    fetch('api/settings.php?action=get_module_visibility')
        .then(r => r.json())
        .then(result => {
            console.log('Module visibility response:', result);

            const modules = result.modules || result.data || [];

            if (result.success && modules.length > 0) {
                renderModuleVisibility(modules);
            } else if (result.success) {
                container.innerHTML = `
                    <div class="alert alert-warning settings-alert">
                        <i class="bi bi-info-circle me-1"></i>
                        No modules found in database.
                    </div>`;
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger settings-alert">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        ${result.error || 'Failed to load modules'}
                    </div>`;
            }
        })
        .catch(err => {
            console.error('Module visibility fetch error:', err);
            container.innerHTML = `
                <div class="alert alert-danger settings-alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Network error: ${err.message}
                </div>`;
        });
}

const MODULE_ICONS = {
    'HUMAN RESOURCES': 'bi-people-fill',
    'FINANCE & PAYMENTS': 'bi-cash-stack',
    'SUPPLY CHAIN': 'bi-box-seam-fill',
    'CUSTOMER CARE': 'bi-headset',
    'OPTICAL': 'bi-eyeglasses',
    'WORKFORCE': 'bi-person-badge-fill',
    'ADMINISTRATION': 'bi-shield-lock-fill'
};

function getModuleIcon(name) {
    return MODULE_ICONS[name] || 'bi-grid-3x3-gap-fill';
}

function renderModuleVisibility(modules) {
    const container = document.getElementById('moduleVisibilityContainer');

    let html = '<div class="row g-3">';

    modules.forEach(mod => {
        const enabled = mod.enabled == 1;
        const groupName = mod.group_name;
        const safeName = escapeHtml(groupName);
        const safeId = 'mod_' + groupName.replace(/[^a-zA-Z0-9]/g, '_');
        const icon = getModuleIcon(groupName);

        html += `
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="module-card ${enabled ? '' : 'module-off'}" id="card_${safeId}">
                    <div class="d-flex align-items-center gap-3">
                        <div class="module-icon">
                            <i class="bi ${icon}"></i>
                        </div>
                        <div class="module-name">${safeName}</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input module-toggle" 
                               type="checkbox" 
                               id="${safeId}"
                               data-group="${safeName}"
                               ${enabled ? 'checked' : ''}>
                    </div>
                </div>
            </div>`;
    });

    html += '</div>';
    container.innerHTML = html;

    container.querySelectorAll('.module-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const card = document.getElementById('card_' + this.id);
            if (card) {
                card.classList.toggle('module-off', !this.checked);
            }
        });
    });
}

function saveModuleVisibility() {
    const toggles = document.querySelectorAll('.module-toggle');

    if (toggles.length === 0) {
        Toast.fire({ icon: 'warning', title: 'No modules to save' });
        return;
    }

    const modules = [];
    toggles.forEach(t => {
        modules.push({
            group_name: t.dataset.group,
            enabled: t.checked ? 1 : 0
        });
    });

    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'update_module_visibility',
            data: { modules: modules }
        })
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Module visibility saved!' });
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        console.error(err);
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

function toggleAllModules(enable) {
    document.querySelectorAll('.module-toggle').forEach(t => {
        t.checked = enable;
        const card = document.getElementById('card_' + t.id);
        if (card) card.classList.toggle('module-off', !enable);
    });
}

// ═══════════════════════════════════════════════════════
// SAVE ALL
// ═══════════════════════════════════════════════════════

function saveAllSettings() {
    saveDeliverySettings();
    saveClinicDetails();
}

// ═══════════════════════════════════════════════════════
// DOCUMENTS & CONTRACT
// ═══════════════════════════════════════════════════════

let docModalInstance = null;
let contractModalInstance = null;

function loadDocuments() {
    const container = document.getElementById('documentsListContainer');
    if (!container) return;
    
    fetch('api/settings.php?action=get_documents')
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                renderDocuments(result.data);
            } else {
                container.innerHTML = `<div class="alert alert-danger">${result.error}</div>`;
            }
        })
        .catch(err => {
            container.innerHTML = `<div class="alert alert-danger">Network error</div>`;
        });
}

function renderDocuments(data, summary) {
    const container = document.getElementById('documentsListContainer');
    
    if (!data || data.length === 0) {
        container.innerHTML = `<div class="alert alert-info">No documents found.</div>`;
        return;
    }
    
    let tableHTML = `
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Owner</th>
                        <th>Expiry</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>`;
    
    data.forEach(d => {
        let badge;
        if (d.is_missing) {
            badge = { cls: 'secondary', label: '➕ Missing' };
        } else if (d.is_expired || d.status === 'Awaiting Re-upload') {
            badge = { cls: 'danger', label: '🔴 Expired' };
        } else if (d.is_expiring_soon) {
            badge = { cls: 'warning', label: `⚠️ Expiring (${d.days_left}d)` };
        } else if (d.status === 'Approved') {
            badge = { cls: 'success', label: '✅ Valid' };
        } else if (d.status === 'Pending') {
            badge = { cls: 'info', label: '⏳ Pending' };
        } else if (d.status === 'Rejected') {
            badge = { cls: 'danger', label: '❌ Rejected' };
        } else {
            badge = { cls: 'secondary', label: d.status };
        }
        
        let actions = '';
        if (d.is_missing) {
            actions = `<button class="btn btn-sm btn-settings-primary" 
                               onclick="openDocModal('${escapeHtml(d.document_type)}')" 
                               title="Upload">
                         <i class="bi bi-cloud-upload me-1"></i>Upload
                       </button>`;
        } else {
            if (d.file_path && d.id) {
                actions += `<a href="api/settings.php?action=download_document&id=${d.id}" 
                              class="btn btn-sm btn-outline-primary" target="_blank" title="View">
                              <i class="bi bi-eye"></i>
                            </a>`;
            }
            
            if (d.can_upload || d.is_expired || d.is_expiring_soon) {
                actions += `<button class="btn btn-sm btn-warning ms-1" 
                                   onclick="openDocModal('${escapeHtml(d.document_type)}')" 
                                   title="Upload New">
                              <i class="bi bi-arrow-up-circle"></i>
                            </button>`;
            }
        }
        
        let expiryDisplay = '—';
        if (d.expires_at) {
            if (d.is_expired) {
                expiryDisplay = `<span class="text-danger fw-bold">${d.expires_at}</span>`;
            } else if (d.is_expiring_soon) {
                expiryDisplay = `<span class="text-warning fw-bold">${d.expires_at}</span>`;
            } else {
                expiryDisplay = `<span>${d.expires_at}</span>`;
            }
        }
        
        tableHTML += `
            <tr>
                <td data-label="Document">
                    <i class="bi bi-file-earmark-text text-settings-primary me-2"></i>
                    <strong>${escapeHtml(d.document_type)}</strong>
                    ${d.is_resubmitted == 1 ? '<span class="badge bg-info ms-2">Re-sub</span>' : ''}
                </td>
                <td data-label="Owner"><small>${escapeHtml(d.owner_name || '—')}</small></td>
                <td data-label="Expiry"><small>${expiryDisplay}</small></td>
                <td class="text-center" data-label="Status"><span class="badge bg-${badge.cls}">${badge.label}</span></td>
                <td class="text-end" data-label="Actions">${actions}</td>
            </tr>`;
    });
    
    tableHTML += `</tbody></table></div>`;
    
    container.innerHTML = tableHTML;
}

function getDocStatusBadge(status) {
    const map = {
        'Approved':            { cls: 'success', label: '● Approved' },
        'Pending':             { cls: 'warning', label: '⏳ Pending' },
        'Rejected':            { cls: 'danger',  label: '✕ Rejected' },
        'Awaiting Re-upload':  { cls: 'danger',  label: '⚠ Awaiting Re-upload' }
    };
    return map[status] || { cls: 'secondary', label: status };
}

function openDocModal(preselectedType = null) {
    document.getElementById('doc_id').value = '';
    document.getElementById('doc_type').value = '';
    document.getElementById('doc_type').disabled = false;
    document.getElementById('doc_owner').value = '';
    document.getElementById('doc_released').value = '';
    document.getElementById('doc_expires').value = '';
    document.getElementById('doc_file').value = '';
    document.getElementById('doc_remarks').value = '';
    document.getElementById('doc_file_container').style.display = 'block';
    document.getElementById('doc_file').required = true;
    
    if (preselectedType) {
        document.getElementById('doc_type').value = preselectedType;
        document.getElementById('doc_type').disabled = true;
        document.getElementById('docModalTitle').innerHTML = 
            `<i class="bi bi-cloud-upload me-2"></i>Upload ${preselectedType}`;
    } else {
        document.getElementById('docModalTitle').innerHTML = 
            '<i class="bi bi-cloud-upload me-2"></i>Upload Document';
    }
    
    document.getElementById('docSubmitBtn').innerHTML = 
        '<i class="bi bi-upload me-1"></i>Upload';
    
    if (!docModalInstance) {
        docModalInstance = new bootstrap.Modal(document.getElementById('docModal'));
    }
    docModalInstance.show();
}

function editDoc(id) {
    fetch('api/settings.php?action=get_documents')
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                Toast.fire({ icon: 'error', title: 'Failed to load document' });
                return;
            }
            
            const doc = result.data.find(d => d.id == id);
            if (!doc) {
                Toast.fire({ icon: 'error', title: 'Document not found' });
                return;
            }
            
            document.getElementById('doc_id').value = doc.id;
            document.getElementById('doc_type').value = doc.document_type;
            document.getElementById('doc_type').disabled = true;
            document.getElementById('doc_owner').value = doc.owner_name || '';
            document.getElementById('doc_released').value = doc.released_at || '';
            document.getElementById('doc_expires').value = doc.expires_at || '';
            document.getElementById('doc_remarks').value = doc.remarks || '';
            document.getElementById('doc_file').value = '';
            
            document.getElementById('doc_file_container').style.display = 'none';
            document.getElementById('doc_file').required = false;
            
            document.getElementById('docModalTitle').innerHTML = 
                '<i class="bi bi-pencil me-2"></i>Edit Document';
            document.getElementById('docSubmitBtn').innerHTML = 
                '<i class="bi bi-save me-1"></i>Save Changes';
            
            if (!docModalInstance) {
                docModalInstance = new bootstrap.Modal(document.getElementById('docModal'));
            }
            docModalInstance.show();
        });
}

function reuploadDoc(docType) {
    openDocModal();
    
    document.getElementById('doc_type').value = docType;
    document.getElementById('doc_type').disabled = true;
    
    document.getElementById('docModalTitle').innerHTML = 
        '<i class="bi bi-arrow-up-circle me-2"></i>Re-upload Document';
    
    Toast.fire({ 
        icon: 'info', 
        title: 'Please upload updated copy of ' + docType 
    });
}

function uploadDocument() {
    const id = document.getElementById('doc_id').value;
    const docType = document.getElementById('doc_type').value;
    const fileInput = document.getElementById('doc_file');
    
    if (!docType) {
        Toast.fire({ icon: 'warning', title: 'Please select document type' });
        return;
    }
    
    if (id) {
        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'update_document',
                data: {
                    id: parseInt(id),
                    owner_name: document.getElementById('doc_owner').value.trim(),
                    released_at: document.getElementById('doc_released').value,
                    expires_at: document.getElementById('doc_expires').value,
                    remarks: document.getElementById('doc_remarks').value.trim()
                }
            })
        })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                docModalInstance.hide();
                Toast.fire({ icon: 'success', title: 'Document updated!' });
                loadDocuments();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Update failed' });
            }
        })
        .catch(() => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
        return;
    }
    
    if (!fileInput.files[0]) {
        Toast.fire({ icon: 'warning', title: 'Please select a file' });
        return;
    }
    
    const file = fileInput.files[0];
    if (file.size > 5 * 1024 * 1024) {
        Toast.fire({ icon: 'error', title: 'File exceeds 5MB limit' });
        return;
    }
    
    const fd = new FormData();
    fd.append('type', 'upload_document');
    fd.append('document_type', docType);
    fd.append('owner_name', document.getElementById('doc_owner').value.trim());
    fd.append('released_at', document.getElementById('doc_released').value);
    fd.append('expires_at', document.getElementById('doc_expires').value);
    fd.append('remarks', document.getElementById('doc_remarks').value.trim());
    const originalName = file.name;
    const sanitizedName = originalName
        .replace(/['"`]/g, '')
        .replace(/[^a-zA-Z0-9._-]/g, '_')
        .replace(/_+/g, '_');

    const sanitizedFile = new File([file], sanitizedName, { type: file.type });
    
    fd.append('file', sanitizedFile);
    
    Swal.fire({
        title: 'Uploading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                docModalInstance.hide();
                Toast.fire({ icon: 'success', title: 'Document uploaded!' });
                loadDocuments();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Upload failed' });
            }
        })
        .catch(() => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
}

function deleteDocument(id, docType) {
    Swal.fire({
        title: 'Delete Document?',
        html: `Are you sure you want to delete <strong>${escapeHtml(docType)}</strong>?<br><small class="text-muted">This action cannot be undone.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        
        Swal.fire({
            title: 'Deleting...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'delete_document',
                data: { id: id }
            })
        })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                Toast.fire({ icon: 'success', title: 'Document deleted!' });
                loadDocuments();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Delete failed' });
            }
        })
        .catch(() => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
    });
}

function loadContract() {
    const container = document.getElementById('contractContainer');
    if (!container) return;
    
    fetch('api/settings.php?action=get_contract')
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                renderContract(result.data);
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger settings-alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        ${result.error || 'Failed to load contract'}
                    </div>`;
            }
        })
        .catch(err => {
            console.error('Load contract error:', err);
            container.innerHTML = `
                <div class="alert alert-danger settings-alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Network error. Please refresh.
                </div>`;
        });
}

function renderContract(c) {
    const container = document.getElementById('contractContainer');
    
    if (!container) return;
    
    if (!c) {
        container.innerHTML = `
            <div class="row g-4">
                <div class="col-12">
                    <div class="alert alert-info settings-alert">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-info-circle-fill fs-2 text-settings-primary"></i>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold mb-2">No contract on file yet</h6>
                                <p class="small mb-3">To get started with EyeCore, follow these 3 steps:</p>
                                <ol class="small mb-0 ps-3">
                                    <li class="mb-1"><strong>Download</strong> the contract template</li>
                                    <li class="mb-1"><strong>Print, sign, and scan</strong> the signed copy</li>
                                    <li class="mb-0"><strong>Upload</strong> the signed PDF</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6">
                    <div class="upload-box text-center p-4">
                        <i class="bi bi-file-earmark-arrow-down display-3 text-settings-primary mb-3"></i>
                        <div class="fw-bold mb-2">Step 1: Download Template</div>
                        <div class="small text-muted mb-3">
                            Get the EyeCore Service Agreement template
                        </div>
                        <a href="api/settings.php?action=download_contract_template" 
                           class="btn btn-settings-primary w-100">
                            <i class="bi bi-download me-1"></i>Download Template
                        </a>
                    </div>
                </div>
                
                <div class="col-12 col-md-6">
                    <div class="upload-box text-center p-4">
                        <i class="bi bi-cloud-upload display-3 text-settings-primary mb-3"></i>
                        <div class="fw-bold mb-2">Step 3: Upload Signed Copy</div>
                        <div class="small text-muted mb-3">
                            Upload the signed and scanned contract
                        </div>
                        <button class="btn btn-settings-primary w-100" onclick="openContractModal()">
                            <i class="bi bi-cloud-upload me-1"></i>Upload Signed Contract
                        </button>
                    </div>
                </div>
            </div>`;
        return;
    }
    
    const st = getContractStatusBadge(c.status);
    const daysLeft = c.end_date 
        ? Math.ceil((new Date(c.end_date) - new Date()) / 86400000) 
        : null;
    
    let daysDisplay = '';
    if (daysLeft !== null) {
        if (daysLeft < 0) {
            daysDisplay = `<span class="text-danger fw-bold">Expired ${Math.abs(daysLeft)} days ago</span>`;
        } else if (daysLeft <= 30) {
            daysDisplay = `<span class="text-warning fw-bold">${daysLeft} days remaining</span>`;
        } else {
            daysDisplay = `<span class="text-success">${daysLeft} days remaining</span>`;
        }
    }
    
    let alertBanner = '';
    if (c.status === 'expired') {
        alertBanner = `
            <div class="alert alert-danger settings-alert mt-3 mb-0">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                    <div>
                        <strong>Your contract has expired!</strong>
                        <div class="small">Please upload the renewed contract immediately to avoid service interruption.</div>
                    </div>
                </div>
            </div>`;
    } else if (c.status === 'expiring') {
        alertBanner = `
            <div class="alert alert-warning settings-alert mt-3 mb-0">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                    <div>
                        <strong>Your contract is expiring soon!</strong>
                        <div class="small">Please upload the renewed contract before it expires.</div>
                    </div>
                </div>
            </div>`;
    }
    
    const showRenew = (c.status === 'expired' || c.status === 'expiring');
    
    const renewButtonHTML = showRenew ? `
        <button class="btn btn-warning btn-sm w-100 mt-2" onclick="openRenewModal()">
            <i class="bi bi-arrow-repeat me-1"></i>Renew Contract
        </button>` : '';
    
    container.innerHTML = `
        <div class="row g-4">
            <div class="col-12 col-md-8">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="module-icon" style="width:52px; height:52px; font-size:1.5rem;">
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5">${escapeHtml(c.contract_no || 'No Contract No.')}</div>
                        <span class="badge bg-${st.cls}">${st.label}</span>
                    </div>
                </div>
                
                <div class="row g-3 mt-2">
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-calendar-check me-1"></i>Start Date</span>
                            <span class="info-value">${c.start_date || '—'}</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-calendar-x me-1"></i>End Date</span>
                            <span class="info-value">${c.end_date || '—'}</span>
                        </div>
                    </div>
                    ${daysLeft !== null ? `
                    <div class="col-12">
                        <div class="info-row" style="border-bottom:none;">
                            <span class="info-label"><i class="bi bi-hourglass-split me-1"></i>Days Remaining</span>
                            <span class="info-value">${daysDisplay}</span>
                        </div>
                    </div>
                    ` : ''}
                </div>
                
                ${c.notes ? `
                <div class="mt-3 p-3 bg-light rounded-3">
                    <small class="text-muted d-block mb-1"><i class="bi bi-sticky me-1"></i>Notes:</small>
                    <div class="small">${escapeHtml(c.notes)}</div>
                </div>
                ` : ''}
                
                ${alertBanner}
            </div>
            
            <div class="col-12 col-md-4">
                <div class="upload-box text-center">
                    <i class="bi bi-file-earmark-pdf display-3 text-danger mb-2"></i>
                    <div class="fw-bold small text-muted mb-2">Contract PDF</div>
                    <a href="api/settings.php?action=download_contract&id=${c.id}" 
                       target="_blank" 
                       class="btn btn-settings-primary btn-sm w-100">
                        <i class="bi bi-eye me-1"></i>View Contract
                    </a>
                    ${renewButtonHTML}
                    <hr class="my-3">
                    <small class="text-muted d-block mb-2">Need blank template?</small>
                    <a href="api/settings.php?action=download_contract_template" 
                       class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-download me-1"></i>Download Template
                    </a>
                </div>
            </div>
        </div>`;
}

function openRenewModal() {
    document.getElementById('contract_no').value = '';
    document.getElementById('contract_start').value = '';
    document.getElementById('contract_end').value = '';
    document.getElementById('contract_notes').value = '';
    document.getElementById('contract_file').value = '';
    
    document.getElementById('contractModalTitle').innerHTML = 
        '<i class="bi bi-arrow-repeat me-2"></i>Renew Contract';
    
    fetch('api/settings.php?action=get_contract')
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data) {
                const c = result.data;
                const oldNo = c.contract_no || '';
                const match = oldNo.match(/(\d+)$/);
                if (match) {
                    const nextNo = String(parseInt(match[1]) + 1).padStart(4, '0');
                    const newNo = oldNo.replace(/\d+$/, nextNo);
                    document.getElementById('contract_no').value = newNo;
                } else {
                    document.getElementById('contract_no').value = oldNo;
                }
                
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('contract_start').value = today;
                
                const nextYear = new Date();
                nextYear.setFullYear(nextYear.getFullYear() + 1);
                document.getElementById('contract_end').value = nextYear.toISOString().split('T')[0];
            }
        });
    
    if (!contractModalInstance) {
        contractModalInstance = new bootstrap.Modal(document.getElementById('contractModal'));
    }
    contractModalInstance.show();
}

function getContractStatusBadge(status) {
    const map = {
        'active':     { cls: 'success', label: '● Active' },
        'expiring':   { cls: 'warning', label: '⚠ Expiring Soon' },
        'expired':    { cls: 'danger',  label: '✕ Expired' },
        'terminated': { cls: 'secondary', label: '● Terminated' }
    };
    return map[status] || { cls: 'secondary', label: status };
}

function openContractModal() {
    document.getElementById('contract_no').value = '';
    document.getElementById('contract_start').value = '';
    document.getElementById('contract_end').value = '';
    document.getElementById('contract_notes').value = '';
    document.getElementById('contract_file').value = '';
    
    fetch('api/settings.php?action=get_contract')
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data) {
                const c = result.data;
                document.getElementById('contract_no').value = c.contract_no || '';
                document.getElementById('contract_start').value = c.start_date || '';
                document.getElementById('contract_end').value = c.end_date || '';
                document.getElementById('contract_notes').value = c.notes || '';
                document.getElementById('contractModalTitle').innerHTML = 
                    '<i class="bi bi-arrow-repeat me-2"></i>Replace Contract';
            } else {
                document.getElementById('contractModalTitle').innerHTML = 
                    '<i class="bi bi-cloud-upload me-2"></i>Upload Contract';
            }
        });
    
    if (!contractModalInstance) {
        contractModalInstance = new bootstrap.Modal(document.getElementById('contractModal'));
    }
    contractModalInstance.show();
}

function editContractMeta() {
    fetch('api/settings.php?action=get_contract')
        .then(r => r.json())
        .then(result => {
            if (!result.success || !result.data) {
                Toast.fire({ icon: 'error', title: 'No contract found' });
                return;
            }
            
            const c = result.data;
            
            Swal.fire({
                title: 'Edit Contract Details',
                html: `
                    <div class="text-start">
                        <label class="form-label small fw-bold">Contract No.</label>
                        <input type="text" id="swal_contract_no" class="form-control mb-2" 
                               value="${escapeHtml(c.contract_no || '')}">
                        
                        <label class="form-label small fw-bold">Start Date</label>
                        <input type="date" id="swal_contract_start" class="form-control mb-2" 
                               value="${c.start_date || ''}">
                        
                        <label class="form-label small fw-bold">End Date</label>
                        <input type="date" id="swal_contract_end" class="form-control mb-2" 
                               value="${c.end_date || ''}">
                        
                        <label class="form-label small fw-bold">Notes</label>
                        <textarea id="swal_contract_notes" class="form-control" rows="2">${escapeHtml(c.notes || '')}</textarea>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save Changes',
                confirmButtonColor: '#0d9488',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    return {
                        contract_no: document.getElementById('swal_contract_no').value.trim(),
                        start_date: document.getElementById('swal_contract_start').value,
                        end_date: document.getElementById('swal_contract_end').value,
                        notes: document.getElementById('swal_contract_notes').value.trim()
                    };
                }
            }).then((result) => {
                if (!result.isConfirmed) return;
                saveContractMeta(result.value);
            });
        });
}

function saveContractMeta(data) {
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'update_contract',
            data: data
        })
    })
    .then(r => r.json())
    .then(result => {
        Swal.close();
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Contract updated!' });
            loadContract();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Update failed' });
        }
    })
    .catch(() => {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Network error' });
    });
}

function uploadContract() {
    const fileInput = document.getElementById('contract_file');
    
    if (!fileInput.files[0]) {
        Toast.fire({ icon: 'warning', title: 'Please select a PDF file' });
        return;
    }
    
    const file = fileInput.files[0];
    if (file.size > 10 * 1024 * 1024) {
        Toast.fire({ icon: 'error', title: 'File exceeds 10MB limit' });
        return;
    }
    
    if (!file.name.toLowerCase().endsWith('.pdf')) {
        Toast.fire({ icon: 'error', title: 'Only PDF files are allowed' });
        return;
    }
    
    const fd = new FormData();
    fd.append('type', 'upload_contract');
    fd.append('contract_no', document.getElementById('contract_no').value.trim());
    fd.append('start_date', document.getElementById('contract_start').value);
    fd.append('end_date', document.getElementById('contract_end').value);
    fd.append('notes', document.getElementById('contract_notes').value.trim());
    
    const originalName = file.name;
    const sanitizedName = originalName
        .replace(/['"`]/g, '')
        .replace(/[^a-zA-Z0-9._-]/g, '_')
        .replace(/_+/g, '_');

    const sanitizedFile = new File([file], sanitizedName, { type: file.type });

    fd.append('file', sanitizedFile);
    
    Swal.fire({
        title: 'Uploading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                contractModalInstance.hide();
                Toast.fire({ icon: 'success', title: 'Contract uploaded!' });
                loadContract();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Upload failed' });
            }
        })
        .catch(() => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
}

function deleteContract(id) {
    Swal.fire({
        title: 'Delete Contract?',
        html: `Are you sure you want to delete this contract?<br><small class="text-muted">This action cannot be undone.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        
        Swal.fire({
            title: 'Deleting...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'delete_contract',
                data: { id: id }
            })
        })
        .then(r => r.json())
        .then(result => {
            Swal.close();
            if (result.success) {
                Toast.fire({ icon: 'success', title: 'Contract deleted!' });
                loadContract();
            } else {
                Toast.fire({ icon: 'error', title: result.error || 'Delete failed' });
            }
        })
        .catch(() => {
            Swal.close();
            Toast.fire({ icon: 'error', title: 'Network error' });
        });
    });
}


document.addEventListener('DOMContentLoaded', function() {
    loadDeliverySettings();
    loadDeliveryFees();
    loadModuleVisibility();
    loadDocuments();
    loadContract();
    
    // ✅ Load existing clinic location kung may coordinates na
    const existingLat = parseFloat(document.getElementById('clinic_lat')?.value) || 0;
    const existingLng = parseFloat(document.getElementById('clinic_lng')?.value) || 0;
    if (existingLat && existingLng && existingLat !== 0 && existingLng !== 0) {
        if (isPointInCaviteLand(existingLat, existingLng)) {
            settingsEnsureMap(existingLat, existingLng);
        } else {
            const status = document.getElementById('settingsLocationStatus');
            if (status) {
                status.className = 'location-status error';
                status.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                    '<strong>Current saved location is outside Cavite.</strong> Please update using "Use Current Location".';
            }
        }
    }
    
    const toggle = document.getElementById('offers_delivery');
    if (toggle) toggle.addEventListener('change', toggleDeliveryFields);
    
    const freeToggle = document.getElementById('enable_free_delivery');
    if (freeToggle) freeToggle.addEventListener('change', toggleFreeDelivery);
    
    document.querySelectorAll('.day-toggle').forEach(dt => {
        dt.addEventListener('change', function() {
            toggleDayRow(this);
        });
    });
});

// ═══════════════════════════════════════════════════════
// ✅ USE CURRENT LOCATION + CAVITE-ONLY VALIDATION
// ═══════════════════════════════════════════════════════

let settingsClinicMap = null;
let settingsClinicMarker = null;

// ── Cavite Land Polygon ──
const CAVITE_LAND_POLYGON = [
    [14.4950, 120.8700], [14.4950, 120.9200], [14.4800, 120.9500], [14.4500, 120.9600],
    [14.4300, 121.0000], [14.3800, 121.0500], [14.3200, 121.0500], [14.2500, 120.9800],
    [14.1500, 120.9500], [14.1100, 120.9300], [14.1000, 120.8500], [14.1200, 120.7000],
    [14.2000, 120.6000], [14.3000, 120.5800], [14.4000, 120.5800], [14.4500, 120.6200],
    [14.4600, 120.7000], [14.4800, 120.8000], [14.4950, 120.8700]
];

// ── Cavite Bounding Box (for ocean detection) ──
const CAVITE_BBOX = { minLat: 14.00, maxLat: 14.60, minLng: 120.55, maxLng: 121.15 };

// ── Point-in-polygon check ──
function isPointInCaviteLand(lat, lng) {
    let inside = false;
    const poly = CAVITE_LAND_POLYGON;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const xi = poly[i][0], yi = poly[i][1];
        const xj = poly[j][0], yj = poly[j][1];
        const intersect = ((yi > lng) !== (yj > lng)) && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

// ── Near Cavite bbox (ocean detection) ──
function isNearCavite(lat, lng) {
    return lat >= CAVITE_BBOX.minLat && lat <= CAVITE_BBOX.maxLat
        && lng >= CAVITE_BBOX.minLng && lng <= CAVITE_BBOX.maxLng;
}

// ── Clear all address fields ──
function clearSettingsAddressFields() {
    ['clinic_address', 'clinic_city', 'clinic_province', 'clinic_postal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const latInput = document.getElementById('clinic_lat');
    const lngInput = document.getElementById('clinic_lng');
    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';
}

// ── Ensure map exists ──
function settingsEnsureMap(lat, lng) {
    const mapDiv = document.getElementById('settingsClinicMap');
    if (!mapDiv) return;
    mapDiv.classList.add('show');

    if (!settingsClinicMap) {
        settingsClinicMap = L.map('settingsClinicMap').setView([lat, lng], 14);

        L.polygon(CAVITE_LAND_POLYGON, {
            color: '#0d9488',
            weight: 2,
            opacity: 0.7,
            fillColor: '#14b8a6',
            fillOpacity: 0.08,
            dashArray: '6, 6'
        }).addTo(settingsClinicMap);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(settingsClinicMap);

        settingsClinicMarker = L.marker([lat, lng], { draggable: true }).addTo(settingsClinicMap);

        let dragTimeout = null;
        settingsClinicMarker.on('dragend', () => {
            const pos = settingsClinicMarker.getLatLng();
            clearTimeout(dragTimeout);
            dragTimeout = setTimeout(() => {
                settingsValidateAndFill(pos.lat, pos.lng);
            }, 500);
        });

        let clickTimeout = null;
        settingsClinicMap.on('click', (e) => {
            settingsClinicMarker.setLatLng(e.latlng);
            clearTimeout(clickTimeout);
            clickTimeout = setTimeout(() => {
                settingsValidateAndFill(e.latlng.lat, e.latlng.lng);
            }, 500);
        });
    } else {
        settingsClinicMap.setView([lat, lng], 14);
        settingsClinicMarker.setLatLng([lat, lng]);
    }

    setTimeout(() => settingsClinicMap.invalidateSize(), 200);
}

// ── Get current location via browser GPS ──
function settingsGetCurrentLocation() {
    if (!navigator.geolocation) {
        Toast.fire({ icon: 'error', title: 'Geolocation not supported by your browser' });
        return;
    }

    const btn = document.getElementById('settingsUseLocationBtn');
    const status = document.getElementById('settingsLocationStatus');

    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:14px;height:14px;border-width:2px;"></span> Getting location...';
    status.className = 'location-status';
    status.textContent = '';

    Toast.fire({ icon: 'info', title: 'Please allow location access when prompted' });

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            await settingsValidateAndFill(pos.coords.latitude, pos.coords.longitude);
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Refresh Location';
        },
        (err) => {
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Use Current Location';

            const msgs = {
                1: 'Location permission denied. Please enable it in your browser settings.',
                2: 'Location information unavailable.',
                3: 'Location request timed out.'
            };
            status.className = 'location-status error';
            status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + (msgs[err.code] || 'Unknown error.');
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

// ── Validate: Cavite land only, then auto-fill ──
async function settingsValidateAndFill(lat, lng) {
    const status = document.getElementById('settingsLocationStatus');
    const latInput = document.getElementById('clinic_lat');
    const lngInput = document.getElementById('clinic_lng');

    settingsEnsureMap(lat, lng);

    const isLand = isPointInCaviteLand(lat, lng);

    if (!isLand) {
        const nearCavite = isNearCavite(lat, lng);
        clearSettingsAddressFields();

        status.className = 'location-status error';

        if (nearCavite) {
            status.innerHTML = '<i class="bi bi-water me-1"></i>' +
                '<strong>This pin is in the ocean / body of water.</strong> ' +
                'Please move the pin to a land area within Cavite.';
            Toast.fire({
                icon: 'error',
                title: 'Cannot set: Pin is in the water',
                text: 'Please move the pin to a valid location within Cavite.'
            });
        } else {
            status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' +
                '<strong>This pin is outside Cavite province.</strong> ' +
                'Only clinics located within Cavite are allowed. Please move the pin.';
            Toast.fire({
                icon: 'error',
                title: 'Cannot set: Outside Cavite',
                text: 'Only locations within Cavite province are allowed.'
            });
        }
        return;
    }

    if (latInput) latInput.value = lat.toFixed(6);
    if (lngInput) lngInput.value = lng.toFixed(6);

    status.className = 'location-status';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Getting address...';

    try {
        const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&zoom=18`,
            { headers: { 'Accept-Language': 'en' } }
        );
        const data = await res.json();

        if (!data || !data.address) {
            status.className = 'location-status error';
            status.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Address lookup failed. Coordinates saved — try again.';
            return;
        }

        const addr = data.address;
        const road     = addr.road || addr.pedestrian || addr.footway || addr.path || '';
        const houseNum = addr.house_number || '';
        const street   = [houseNum, road].filter(Boolean).join(' ');
        const cityName = addr.city || addr.town || addr.municipality || addr.county || '';
        const zip      = addr.postcode || '';
        const barangay = addr.village || addr.suburb || addr.quarter || addr.neighbourhood || '';

        let fullStreet = street;
        if (barangay && !fullStreet.toLowerCase().includes(barangay.toLowerCase())) {
            fullStreet = fullStreet ? `${fullStreet}, Brgy. ${barangay}` : `Brgy. ${barangay}`;
        }

        const addressInput = document.getElementById('clinic_address');
        const cityInput = document.getElementById('clinic_city');
        const provinceInput = document.getElementById('clinic_province');
        const postalInput = document.getElementById('clinic_postal');

        if (addressInput && fullStreet) addressInput.value = fullStreet;
        if (cityInput && cityName) cityInput.value = cityName;
        if (provinceInput) provinceInput.value = 'Cavite';
        if (postalInput && zip) postalInput.value = zip;

        status.className = 'location-status success';
        status.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Location confirmed within Cavite. Address auto-filled!';

        Toast.fire({ icon: 'success', title: 'Address auto-filled successfully!' });

    } catch (err) {
        console.error('Nominatim error:', err);
        status.className = 'location-status error';
        status.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Address lookup failed. Coordinates saved — try again.';
    }
}
</script>