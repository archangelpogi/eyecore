<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// ✅ Check if user is ClinicAdmin
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
    exit;
}

// ✅ Check view permission
if (!RBACHelper::hasPermission('settings_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to view System Settings.</p>
        </div>
    </div>
    <?php
    exit;
}

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('settings_view');
$canEdit = RBACHelper::hasPermission('settings_edit');

$user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'] ?? 0;

// Get clinic data from database using PDO
$stmt = $pdo->prepare("SELECT * FROM clinics WHERE id = ?");
$stmt->execute([$clinic_id]);
$clinic = $stmt->fetch(PDO::FETCH_ASSOC);

// If no clinic found, set empty array
if (!$clinic) {
    $clinic = [];
}

// Get clinic settings using PDO
$stmt_settings = $pdo->prepare("SELECT * FROM settings WHERE clinic_id = ?");
$stmt_settings->execute([$clinic_id]);
$settings = [];
while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Parse working hours from database - NO DEFAULTS
$working_hours = [];
if (isset($settings['working_hours']) && !empty($settings['working_hours'])) {
    $working_hours = json_decode($settings['working_hours'], true);
}

// Use actual database values - NO DEFAULTS
$clinic['hours'] = $clinic['hours'] ?? '';
$clinic['days'] = $clinic['days'] ?? '';
$clinic['latitude'] = $clinic['latitude'] ?? '';
$clinic['longitude'] = $clinic['longitude'] ?? '';
$clinic['radius'] = $clinic['radius'] ?? '100';
?>
<!-- SweetAlert2 -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">System Settings</h3>
            <p class="text-muted mb-0">Configure clinic and system preferences</p>
        </div>
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="saveAllSettings()">
            <i class="bi bi-save me-2"></i>Save All Changes
        </button>
        <?php endif; ?>
    </div>

    <!-- Tabs - Only 3 tabs: Clinic Details, Working Hours, Module Visibility -->
    <ul class="nav nav-tabs mb-3" id="settingsTab">
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
                <i class="bi bi-eye-slash me-1"></i>Module Visibility
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- ==================================================== -->
        <!-- CLINIC DETAILS TAB (WITH GEOFENCE SECTION) -->
        <!-- ==================================================== -->
        <div class="tab-pane fade show active" id="clinic_details">
            <div class="card">
                <div class="card-body">
                    <form id="clinicDetailsForm" class="row g-3">
                        <!-- Basic Information -->
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mb-3">
                                <i class="bi bi-info-circle me-2"></i>Basic Information
                            </h6>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Clinic Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_name" 
                                   value="<?php echo $clinic['clinic_name'] ?? ''; ?>" 
                                   onkeyup="updateClinicPreview()" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="clinic_description" rows="4" 
                                      onkeyup="updateClinicPreview()"><?php echo $clinic['description'] ?? ''; ?></textarea>
                        </div>

                        <!-- Logo -->
                        <div class="col-md-6">
                            <label class="form-label">Clinic Logo</label>
                            <div class="mb-2">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width: 80px; height: 80px;">
                                        <?php if (!empty($clinic['logo'])): ?>
                                            <img src="/eyecore/assets/images/clinic-logos/<?php echo $clinic['logo']; ?>" 
                                                 class="img-fluid rounded" style="max-height: 80px;" id="current_logo">
                                        <?php else: ?>
                                            <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                                <i class="bi bi-building text-muted fs-1"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <?php if ($canEdit): ?>
                                        <input type="file" class="form-control" id="clinic_logo" accept="image/*" onchange="previewLogo(this)">
                                        <small class="text-muted">Max 2MB. JPG, PNG, GIF</small>
                                        <?php else: ?>
                                        <div class="text-muted">Logo upload is disabled in view-only mode</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cover Photo -->
                        <div class="col-md-6">
                            <label class="form-label">Cover Photo</label>
                            <div class="mb-2">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width: 100%; height: 80px;">
                                        <img id="cover_preview" src="" class="img-fluid rounded d-none" style="max-height: 80px; width: 100%; object-fit: cover;">
                                        <div id="coverPlaceholder" class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 80px;">
                                            <?php if (!empty($clinic['cover_photo'])): ?>
                                                <img src="/eyecore/assets/images/clinic-covers/<?php echo $clinic['cover_photo']; ?>" 
                                                     class="img-fluid rounded" style="max-height: 80px; width: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <i class="bi bi-card-image text-muted"></i> No cover photo
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($canEdit): ?>
                            <input type="file" class="form-control" id="clinic_cover" accept="image/*" onchange="previewCover(this)">
                            <?php else: ?>
                            <div class="text-muted">Cover photo upload is disabled in view-only mode</div>
                            <?php endif; ?>
                        </div>

                        <!-- Contact Information -->
                        <div class="col-md-6">
                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_contact" 
                                   value="<?php echo $clinic['contact'] ?? ''; ?>"
                                   onkeyup="updateClinicPreview()" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="clinic_email" 
                                   value="<?php echo $clinic['clinic_email'] ?? ''; ?>">
                        </div>

                        <!-- Location -->
                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_address" 
                                   value="<?php echo $clinic['address'] ?? ''; ?>"
                                   onkeyup="updateClinicPreview()" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" id="clinic_city" 
                                   value="<?php echo $clinic['city'] ?? ''; ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Province</label>
                            <input type="text" class="form-control" id="clinic_province" 
                                   value="<?php echo $clinic['province'] ?? ''; ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Postal Code</label>
                            <input type="text" class="form-control" id="clinic_postal" 
                                   value="<?php echo $clinic['postal_code'] ?? ''; ?>">
                        </div>

                        <!-- Business Hours -->
                        <div class="col-md-6">
                            <label class="form-label">Opening Hours</label>
                            <input type="text" class="form-control" id="clinic_hours" 
                                   value="<?php echo $clinic['hours'] ?? '9:00 AM - 6:00 PM'; ?>"
                                   placeholder="e.g., 9:00 AM - 6:00 PM">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Days Open</label>
                            <input type="text" class="form-control" id="clinic_days" 
                                   value="<?php echo $clinic['days'] ?? 'Monday - Saturday'; ?>"
                                   placeholder="e.g., Monday - Saturday">
                        </div>

                        <!-- ==================================================== -->
                        <!-- GEOFENCE SECTION - MOVED HERE FROM CLINIC INFORMATION -->
                        <!-- ==================================================== -->
                        <div class="col-12 mt-4">
                            <h6 class="border-bottom pb-2 mb-3">
                                <i class="bi bi-geo-alt-fill me-2"></i>Clinic Location (Geofence for Attendance)
                            </h6>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control" id="clinic_lat" 
                                   value="<?php echo $clinic['latitude'] ?? '14.33681929'; ?>"
                                   placeholder="e.g., 14.33681929">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control" id="clinic_lng" 
                                   value="<?php echo $clinic['longitude'] ?? '120.92940624'; ?>"
                                   placeholder="e.g., 120.92940624">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Radius (meters)</label>
                            <input type="number" class="form-control" id="clinic_radius" 
                                   min="50" max="1000" step="10" 
                                   value="<?php echo $clinic['radius'] ?? '100'; ?>">
                            <small class="text-muted">Geofence radius for attendance tracking</small>
                        </div>
                        
                        <div class="col-12 mt-2">
                            <button type="button" class="btn btn-outline-primary" onclick="getCurrentLocation()">
                                <i class="bi bi-geo-alt me-2"></i>Get Current Location
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="showLocationOnMap()">
                                <i class="bi bi-map me-2"></i>View on Map
                            </button>
                        </div>
                        
                        <!-- End of Geofence Section -->

                        <!-- Status -->
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="clinic_status">
                                <option value="active" <?php echo ($clinic['status'] ?? '') == 'active' ? 'selected' : ''; ?>>Active (Visible to users)</option>
                                <option value="inactive" <?php echo ($clinic['status'] ?? '') == 'inactive' ? 'selected' : ''; ?>>Inactive (Hidden from users)</option>
                            </select>
                        </div>

                        <!-- Save Button -->
                        <div class="col-12 mt-4">
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-primary" onclick="saveClinicDetails()">
                                <i class="bi bi-save me-2"></i>Update Clinic Details
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetClinicForm()">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- WORKING HOURS TAB -->
        <!-- ==================================================== -->
        <div class="tab-pane fade" id="hours">
            <div class="card">
                <div class="card-body">
                    <form id="hoursForm">
                        <?php
                        $days_display = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        $days_key = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        
                        for ($i = 0; $i < count($days_display); $i++):
                            $day_display = $days_display[$i];
                            $day_key = $days_key[$i];
                            // Get from database, if not set then empty
                            $day_data = isset($working_hours[$day_key]) ? $working_hours[$day_key] : ['enabled' => false, 'open' => '', 'close' => ''];
                        ?>
                        <div class="row g-3 align-items-center mb-3 pb-3 border-bottom">
                            <div class="col-md-2">
                                <strong><?php echo $day_display; ?></strong>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input day-toggle" 
                                           type="checkbox" 
                                           data-day="<?php echo $day_key; ?>"
                                           <?php echo !empty($day_data['enabled']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="row g-2">
                                    <div class="col">
                                        <input type="time" 
                                               class="form-control open-time" 
                                               data-day="<?php echo $day_key; ?>"
                                               value="<?php echo htmlspecialchars($day_data['open'] ?? ''); ?>"
                                               <?php echo empty($day_data['enabled']) ? 'disabled' : ''; ?>>
                                    </div>
                                    <div class="col-auto">to</div>
                                    <div class="col">
                                        <input type="time" 
                                               class="form-control close-time" 
                                               data-day="<?php echo $day_key; ?>"
                                               value="<?php echo htmlspecialchars($day_data['close'] ?? ''); ?>"
                                               <?php echo empty($day_data['enabled']) ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                        <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-primary" onclick="saveWorkingHours()">
                            Update Working Hours
                        </button>
                        <?php else: ?>
                        <div class="alert alert-info">You are in view-only mode. Working hours cannot be edited.</div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- MODULE VISIBILITY TAB (Replaces Access Control) -->
        <!-- ==================================================== -->
        <div class="tab-pane fade" id="module_visibility">
            <div class="card">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-eye-slash me-2 text-primary"></i>
                            Module Visibility
                        </h5>
                        <span class="badge bg-info">For Clinic Admin Only</span>
                    </div>
                    <small class="text-muted">
                        Choose which modules you want to see on your sidebar. This will not affect other users' permissions.
                    </small>
                </div>
                <div class="card-body">
                    <div id="moduleVisibilityContainer">
                        <!-- Dynamic module toggles will load here -->
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <span class="ms-2">Loading module settings...</span>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                    <div class="mt-3">
                        <button class="btn btn-sm btn-primary" onclick="saveModuleVisibility()">
                            <i class="bi bi-save"></i> Save Module Visibility
                        </button>
                        <button class="btn btn-sm btn-outline-success ms-2" onclick="toggleAllModules(true)">
                            <i class="bi bi-check-all"></i> Enable All
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="toggleAllModules(false)">
                            <i class="bi bi-x-circle"></i> Disable All
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info mt-3">You are in view-only mode. Module visibility cannot be edited.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Permission badge for view-only mode -->
<?php if (!$canEdit): ?>
<div class="position-fixed bottom-0 end-0 m-3">
    <div class="badge bg-secondary p-2">
        <i class="bi bi-eye me-1"></i> View Only Mode
    </div>
</div>
<?php endif; ?>

<script src="assets/js/settings.js"></script>

<script>
// ✅ Pass permissions to JavaScript
const settingsPermissions = {
    canView: <?= json_encode($canView) ?>,
    canEdit: <?= json_encode($canEdit) ?>
};

console.log('Settings Permissions:', settingsPermissions);

// ✅ Override save functions if no edit permission
<?php if (!$canEdit): ?>
window.saveAllSettings = function() {
    Swal.fire('Access Denied', 'You do not have permission to edit settings.', 'warning');
};
window.saveClinicDetails = function() {
    Swal.fire('Access Denied', 'You do not have permission to edit clinic details.', 'warning');
};
window.saveWorkingHours = function() {
    Swal.fire('Access Denied', 'You do not have permission to edit working hours.', 'warning');
};
window.saveModuleVisibility = function() {
    Swal.fire('Access Denied', 'You do not have permission to edit module visibility.', 'warning');
};
window.toggleAllModules = function() {
    Swal.fire('Access Denied', 'You do not have permission to edit module visibility.', 'warning');
};
<?php endif; ?>
</script>