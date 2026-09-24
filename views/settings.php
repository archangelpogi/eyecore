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

    .settings-wrapper {
        background: var(--settings-bg);
        padding: 1.5rem;
        border-radius: 16px;
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
    .form-check-input:focus {
        box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.15);
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
    .btn-settings-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(13, 148, 136, 0.35);
        color: white;
    }

    /* Day Row */
    .day-row {
        padding: 1rem;
        border-radius: 12px;
        background: #f8fafc;
        margin-bottom: 0.75rem;
        transition: all 0.2s ease;
        border: 1.5px solid transparent;
    }
    .day-row:hover {
        background: #f0fdfa;
        border-color: var(--settings-border);
    }
    .day-row .day-label {
        font-weight: 700;
        color: var(--settings-text);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .day-row .day-label::before {
        content: '';
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #cbd5e1;
        transition: all 0.2s ease;
    }
    .day-row.day-active .day-label::before {
        background: #0d9488;
        box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.15);
    }

    /* Module Card */
    .module-card {
        background: #fff;
        border: 1.5px solid var(--settings-border);
        border-radius: 14px;
        padding: 1.1rem 1.25rem;
        transition: all 0.25s ease;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }
    .module-card:hover {
        border-color: var(--settings-primary);
        box-shadow: 0 4px 16px rgba(13, 148, 136, 0.1);
        transform: translateY(-2px);
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
    }
    .module-card.module-off {
        opacity: 0.65;
        background: #fafafa;
    }
    .module-card.module-off .module-icon {
        background: #f1f5f9;
        color: #94a3b8;
    }

    /* Upload Box */
    .upload-box {
        border: 2px dashed var(--settings-border);
        border-radius: 14px;
        padding: 1.25rem;
        text-align: center;
        transition: all 0.2s ease;
        background: #fafbfc;
    }
    .upload-box:hover {
        border-color: var(--settings-primary);
        background: #f0fdfa;
    }
    .upload-box img {
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
        transition: all 0.2s ease;
        margin-top: 0.75rem;
    }
    .upload-box .upload-label:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
    }
    .upload-box input[type="file"] { display: none; }

    /* Alert Boxes */
    .settings-alert {
        border-radius: 12px;
        border: none;
        padding: 1.25rem;
    }

    /* Delivery Section */
    .delivery-highlight {
        background: linear-gradient(135deg, #f0fdfa 0%, #ccfbf1 100%);
        border: 1.5px solid #99f6e4;
        border-radius: 14px;
        padding: 1.25rem;
    }
    .delivery-highlight .form-label {
        color: #0f766e;
    }

    /* Table */
    .settings-card .table {
        margin-bottom: 0;
    }
    .settings-card .table thead th {
        background: #f8fafc;
        color: var(--settings-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid var(--settings-border);
        padding: 0.9rem 1rem;
    }
    .settings-card .table tbody td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--settings-border);
    }
    .settings-card .table tbody tr:hover {
        background: #f0fdfa;
    }

    /* Stats chips */
    .stats-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    .stat-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: #f1f5f9;
        color: var(--settings-muted);
        padding: 0.4rem 0.85rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .stat-chip.active { background: #ccfbf1; color: #0f766e; }
    .stat-chip.inactive { background: #fee2e2; color: #991b1b; }

    /* Teal primary text helper */
    .text-settings-primary { color: var(--settings-primary) !important; }
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
                        <div class="col-md-4">
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
                        <div class="col-md-8">
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
                        <div class="col-md-8">
                            <label class="form-label">Clinic Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_name" 
                                   value="<?php echo htmlspecialchars($clinic['clinic_name'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="clinic_status">
                                <option value="Active" <?php echo ($clinic['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>● Active</option>
                                <option value="Suspended" <?php echo ($clinic['status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>● Suspended</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="clinic_description" rows="3" 
                                      placeholder="Describe your clinic..."><?php echo htmlspecialchars($clinic['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_contact" 
                                   value="<?php echo htmlspecialchars($clinic['contact'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="clinic_email" 
                                   value="<?php echo htmlspecialchars($clinic['clinic_email'] ?? ''); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_address" 
                                   value="<?php echo htmlspecialchars($clinic['address'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" id="clinic_city" 
                                   value="<?php echo htmlspecialchars($clinic['city'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Province</label>
                            <input type="text" class="form-control" id="clinic_province" 
                                   value="<?php echo htmlspecialchars($clinic['province'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Postal Code</label>
                            <input type="text" class="form-control" id="clinic_postal" 
                                   value="<?php echo htmlspecialchars($clinic['postal_code'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><i class="bi bi-geo me-1"></i>Latitude</label>
                            <input type="text" class="form-control" id="clinic_lat" 
                                   value="<?php echo htmlspecialchars($clinic['latitude'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><i class="bi bi-geo-alt me-1"></i>Longitude</label>
                            <input type="text" class="form-control" id="clinic_lng" 
                                   value="<?php echo htmlspecialchars($clinic['longitude'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4">
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
                                <div class="col-md-3">
                                    <div class="day-label"><?php echo $day_display; ?></div>
                                </div>
                                <div class="col-md-2">
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
                                <div class="col-md-7">
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
                    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                        <div>
                            <div class="section-title mb-2">
                                <i class="bi bi-grid-3x3-gap-fill"></i> Module Visibility
                            </div>
                            <p class="text-muted small mb-0">
                                Toggle which modules are visible to staff and users in this clinic.
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-success" onclick="toggleAllModules(true)">
                                <i class="bi bi-check-all me-1"></i>Enable All
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="toggleAllModules(false)">
                                <i class="bi bi-x-circle me-1"></i>Disable All
                            </button>
                        </div>
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
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-cash-coin me-1 text-settings-primary"></i>Base Delivery Fee (₱)
                                </label>
                                <input type="number" step="0.01" min="0" class="form-control" id="delivery_fee" 
                                       value="<?php echo htmlspecialchars($clinic['delivery_fee'] ?? 0.00); ?>">
                                <small class="text-muted">Standard delivery fee per order</small>
                            </div>

                            <!-- Delivery Radius -->
                            <div class="col-md-6">
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

                            <div class="col-md-6" id="free_delivery_container" 
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
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Region</label>
                        <input type="text" class="form-control" id="city_region" 
                               placeholder="e.g. CALABARZON">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Barangay <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" id="city_barangay" 
                               placeholder="e.g. Poblacion">
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Delivery Fee (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="city_fee" 
                               step="0.01" min="1" placeholder="e.g. 50.00">
                    </div>
                    <div class="col-md-6">
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

// ═══════════════════════════════════════════════════════
// CLINIC DETAILS
// ═══════════════════════════════════════════════════════

function saveClinicDetails() {
    const data = {
        name: document.getElementById('clinic_name').value.trim(),
        description: document.getElementById('clinic_description').value.trim(),
        contact: document.getElementById('clinic_contact').value.trim(),
        email: document.getElementById('clinic_email').value.trim(),
        address: document.getElementById('clinic_address').value.trim(),
        city: document.getElementById('clinic_city').value.trim(),
        province: document.getElementById('clinic_province').value.trim(),
        postal: document.getElementById('clinic_postal').value.trim(),
        latitude: document.getElementById('clinic_lat').value.trim(),
        longitude: document.getElementById('clinic_lng').value.trim(),
        radius: parseInt(document.getElementById('clinic_radius').value) || 100,
        status: document.getElementById('clinic_status').value
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
                <td><strong>${escapeHtml(city.city)}</strong></td>
                <td><small class="text-muted">${escapeHtml(city.region || '—')}</small></td>
                <td class="text-end fw-bold text-settings-primary">₱${fee}</td>
                <td class="text-center">
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-clock me-1"></i>${city.estimated_days}d
                    </span>
                </td>
                <td class="text-center">
                    <span class="badge bg-${isActive ? 'success' : 'secondary'}">
                        ${isActive ? '● Active' : '● Inactive'}
                    </span>
                </td>
                <td class="text-end">
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
            <div class="col-md-6 col-lg-4">
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

    // Add change listeners to update card style
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
// ✅ SINGLE DOMContentLoaded — ALL INIT
// ═══════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    loadDeliverySettings();
    loadDeliveryFees();
    loadModuleVisibility();

    // Delivery toggle
    const toggle = document.getElementById('offers_delivery');
    if (toggle) toggle.addEventListener('change', toggleDeliveryFields);

    // Free delivery toggle
    const freeToggle = document.getElementById('enable_free_delivery');
    if (freeToggle) freeToggle.addEventListener('change', toggleFreeDelivery);

    // Working hours day toggles
    document.querySelectorAll('.day-toggle').forEach(dt => {
        dt.addEventListener('change', function() {
            toggleDayRow(this);
        });
    });
});
</script>