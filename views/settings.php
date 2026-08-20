<?php
/**
 * Settings View — Clinic Settings
 * Loaded via main.php?view=settings
 */

// Check if user is ClinicAdmin
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

// Get clinic data
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM clinics WHERE id = ?");
$stmt->execute([$clinic_id]);
$clinic = $stmt->fetch(PDO::FETCH_ASSOC);

// Get settings
$stmt_settings = $pdo->prepare("SELECT * FROM settings WHERE clinic_id = ?");
$stmt_settings->execute([$clinic_id]);
$settings = [];
while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Working hours
$working_hours = [];
if (isset($settings['working_hours']) && !empty($settings['working_hours'])) {
    $working_hours = json_decode($settings['working_hours'], true);
}

// Delivery settings - get from database
$clinic['offers_delivery'] = $clinic['offers_delivery'] ?? 0;
$clinic['delivery_fee'] = $clinic['delivery_fee'] ?? 0.00;
$clinic['delivery_radius_km'] = $clinic['delivery_radius_km'] ?? 0;
$clinic['free_delivery_minimum'] = $clinic['free_delivery_minimum'] ?? 0.00;

// Check permission
$canEdit = true; // ClinicAdmin can edit
?>
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">System Settings</h3>
            <p class="text-muted mb-0">Configure clinic and system preferences</p>
        </div>
        <button class="btn btn-primary" onclick="saveAllSettings()">
            <i class="bi bi-save me-2"></i>Save All Changes
        </button>
    </div>

    <!-- Tabs -->
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
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#delivery">
                <i class="bi bi-truck me-1"></i>Delivery Settings
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- ==================================================== -->
        <!-- CLINIC DETAILS TAB -->
        <!-- ==================================================== -->
        <div class="tab-pane fade show active" id="clinic_details">
            <div class="card">
                <div class="card-body">
                    <form id="clinicDetailsForm" class="row g-3">
                        <div class="col-12">
                            <h6 class="border-bottom pb-2 mb-3">
                                <i class="bi bi-info-circle me-2"></i>Basic Information
                            </h6>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Clinic Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_name" 
                                   value="<?php echo $clinic['clinic_name'] ?? ''; ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="clinic_description" rows="4"><?php echo $clinic['description'] ?? ''; ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_contact" 
                                   value="<?php echo $clinic['contact'] ?? ''; ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="clinic_email" 
                                   value="<?php echo $clinic['clinic_email'] ?? ''; ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="clinic_address" 
                                   value="<?php echo $clinic['address'] ?? ''; ?>" required>
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

                        <div class="col-md-4">
                            <label class="form-label">Latitude</label>
                            <input type="text" class="form-control" id="clinic_lat" 
                                   value="<?php echo $clinic['latitude'] ?? ''; ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Longitude</label>
                            <input type="text" class="form-control" id="clinic_lng" 
                                   value="<?php echo $clinic['longitude'] ?? ''; ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Radius (meters)</label>
                            <input type="number" class="form-control" id="clinic_radius" 
                                   value="<?php echo $clinic['radius'] ?? 100; ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="clinic_status">
                                <option value="Active" <?php echo ($clinic['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Suspended" <?php echo ($clinic['status'] ?? '') == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>

                        <div class="col-12 mt-4">
                            <button type="button" class="btn btn-primary" onclick="saveClinicDetails()">
                                <i class="bi bi-save me-2"></i>Update Clinic Details
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
                        <button type="button" class="btn btn-primary" onclick="saveWorkingHours()">
                            Update Working Hours
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- MODULE VISIBILITY TAB -->
        <!-- ==================================================== -->
        <div class="tab-pane fade" id="module_visibility">
            <div class="card">
                <div class="card-body">
                    <div id="moduleVisibilityContainer">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <span class="ms-2">Loading module settings...</span>
                        </div>
                    </div>
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
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- ✅ DELIVERY SETTINGS TAB (WITH FREE DELIVERY TOGGLE) -->
        <!-- ==================================================== -->
        <div class="tab-pane fade" id="delivery">
            <div class="card">
                <div class="card-body">
                    <form id="deliverySettingsForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3">
                                    <i class="bi bi-truck me-2 text-primary"></i>Delivery Settings
                                </h6>
                                <p class="text-muted small mb-3">
                                    Configure delivery options for customer product orders. 
                                    When enabled, customers can choose delivery instead of pickup during checkout.
                                </p>
                            </div>

                            <!-- Enable Delivery -->
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="offers_delivery" 
                                           <?php echo ($clinic['offers_delivery'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="offers_delivery">
                                        Enable Delivery Service
                                    </label>
                                    <div class="text-muted small">Allow customers to have products delivered to their address</div>
                                </div>
                            </div>

                            <!-- Delivery Fee -->
                            <div class="col-md-4">
                                <label class="form-label">Delivery Fee (₱)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="delivery_fee" 
                                       value="<?php echo $clinic['delivery_fee'] ?? 0.00; ?>">
                                <small class="text-muted">Base delivery fee per order</small>
                            </div>

                            <!-- Delivery Radius -->
                            <div class="col-md-4">
                                <label class="form-label">Delivery Radius (km)</label>
                                <input type="number" step="1" min="0" class="form-control" id="delivery_radius_km" 
                                       value="<?php echo $clinic['delivery_radius_km'] ?? 0; ?>">
                                <small class="text-muted">Maximum distance for delivery (0 = unlimited)</small>
                            </div>

                            <!-- ========================================== -->
                            <!-- ✅ FREE DELIVERY TOGGLE + FIELD -->
                            <!-- ========================================== -->
                            <?php 
                            $has_free_delivery = ($clinic['free_delivery_minimum'] ?? 0) > 0;
                            ?>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="enable_free_delivery" 
                                           <?php echo $has_free_delivery ? 'checked' : ''; ?>
                                           onchange="toggleFreeDelivery()">
                                    <label class="form-check-label fw-bold" for="enable_free_delivery">
                                        Enable Free Delivery
                                    </label>
                                    <div class="text-muted small">Customers get free delivery when order meets minimum amount</div>
                                </div>
                            </div>

                            <div class="col-md-4" id="free_delivery_container" 
                                 style="<?php echo $has_free_delivery ? '' : 'display:none;'; ?>">
                                <label class="form-label">Free Delivery Minimum (₱)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="free_delivery_minimum" 
                                       value="<?php echo $clinic['free_delivery_minimum'] ?? 0.00; ?>"
                                       <?php echo $has_free_delivery ? '' : 'disabled'; ?>>
                                <small class="text-muted">Minimum order amount for free delivery</small>
                            </div>

                            <!-- Save Button -->
                            <div class="col-12 mt-3">
                                <button type="button" class="btn btn-primary" onclick="saveDeliverySettings()">
                                    <i class="bi bi-save me-2"></i>Save Delivery Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ✅ DELIVERY SETTINGS JAVASCRIPT (WITH FREE DELIVERY TOGGLE) -->
<script>
// SweetAlert Toast
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
});

// ✅ Toggle free delivery field visibility
function toggleFreeDelivery() {
    const toggle = document.getElementById('enable_free_delivery');
    const container = document.getElementById('free_delivery_container');
    const input = document.getElementById('free_delivery_minimum');
    
    if (toggle && toggle.checked) {
        container.style.display = 'block';
        input.disabled = false;
        input.style.opacity = '1';
        input.style.cursor = 'default';
        // Set default value if empty or 0
        if (!input.value || parseFloat(input.value) === 0) {
            input.value = 500.00;
        }
    } else {
        container.style.display = 'none';
        input.disabled = true;
        input.style.opacity = '0.5';
        input.style.cursor = 'not-allowed';
        input.value = 0;
    }
}

// ✅ Toggle delivery fields dependency (disable when delivery is off)
function toggleDeliveryFields() {
    const toggle = document.getElementById('offers_delivery');
    const isEnabled = toggle ? toggle.checked : false;
    
    // Main delivery fields
    const fields = ['delivery_fee', 'delivery_radius_km'];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = !isEnabled;
            el.style.opacity = isEnabled ? '1' : '0.5';
            el.style.cursor = isEnabled ? 'default' : 'not-allowed';
            el.style.backgroundColor = isEnabled ? '' : '#e9ecef';
        }
    });
    
    // Free delivery toggle - disable when delivery is off
    const freeToggle = document.getElementById('enable_free_delivery');
    if (freeToggle) {
        freeToggle.disabled = !isEnabled;
        freeToggle.style.opacity = isEnabled ? '1' : '0.5';
        freeToggle.style.cursor = isEnabled ? 'default' : 'not-allowed';
    }
    
    // If delivery is off, also hide free delivery
    if (!isEnabled) {
        const container = document.getElementById('free_delivery_container');
        if (container) container.style.display = 'none';
        const input = document.getElementById('free_delivery_minimum');
        if (input) {
            input.disabled = true;
            input.value = 0;
        }
    } else {
        // If delivery is on, check if free delivery should be shown
        if (freeToggle && freeToggle.checked) {
            const container = document.getElementById('free_delivery_container');
            if (container) container.style.display = 'block';
            const input = document.getElementById('free_delivery_minimum');
            if (input) {
                input.disabled = false;
                input.style.opacity = '1';
                input.style.cursor = 'default';
            }
        }
    }
}

// ✅ Load delivery settings from database
function loadDeliverySettings() {
    fetch('api/settings.php?action=get_delivery_settings')
        .then(response => response.json())
        .then(result => {
            if (result.success && result.data) {
                const data = result.data;
                
                // Set toggle state
                const toggle = document.getElementById('offers_delivery');
                if (toggle) {
                    toggle.checked = data.offers_delivery == 1;
                }
                
                // Set field values
                document.getElementById('delivery_fee').value = data.delivery_fee || 0;
                document.getElementById('delivery_radius_km').value = data.delivery_radius_km || 0;
                document.getElementById('free_delivery_minimum').value = data.free_delivery_minimum || 0;
                
                // Set free delivery toggle state
                const freeToggle = document.getElementById('enable_free_delivery');
                if (freeToggle) {
                    freeToggle.checked = (data.free_delivery_minimum || 0) > 0;
                }
                
                // Apply toggle states
                toggleDeliveryFields();
                toggleFreeDelivery();
            }
        })
        .catch(err => console.error('Error loading delivery settings:', err));
}

// ✅ Save delivery settings
function saveDeliverySettings() {
    const toggle = document.getElementById('offers_delivery');
    const freeToggle = document.getElementById('enable_free_delivery');
    const freeInput = document.getElementById('free_delivery_minimum');
    
    // If free delivery is disabled, set to 0
    let freeDeliveryAmount = 0;
    if (freeToggle && freeToggle.checked) {
        freeDeliveryAmount = parseFloat(freeInput.value) || 0;
    }
    
    const data = {
        offers_delivery: toggle ? (toggle.checked ? 1 : 0) : 0,
        delivery_fee: parseFloat(document.getElementById('delivery_fee').value) || 0,
        delivery_radius_km: parseInt(document.getElementById('delivery_radius_km').value) || 0,
        free_delivery_minimum: freeDeliveryAmount
    };
    
    // Debug log
    console.log('Saving delivery data:', data);
    
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
    .then(response => response.json())
    .then(result => {
        Swal.close();
        console.log('Server response:', result);
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Delivery settings saved successfully!' });
            loadDeliverySettings();
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save' });
        }
    })
    .catch(err => {
        Swal.close();
        console.error('Error:', err);
        Toast.fire({ icon: 'error', title: 'Network error. Please try again.' });
    });
}

// ✅ Setup on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDeliverySettings();
    
    // Setup toggle event listeners
    const toggle = document.getElementById('offers_delivery');
    if (toggle) {
        toggle.addEventListener('change', toggleDeliveryFields);
    }
    
    const freeToggle = document.getElementById('enable_free_delivery');
    if (freeToggle) {
        freeToggle.addEventListener('change', toggleFreeDelivery);
    }
});

// ============================================
// DUMMY FUNCTIONS FOR OTHER TABS
// ============================================
function saveClinicDetails() {
    Toast.fire({ icon: 'info', title: 'Clinic details saved!' });
}

function saveWorkingHours() {
    Toast.fire({ icon: 'info', title: 'Working hours saved!' });
}

function saveModuleVisibility() {
    Toast.fire({ icon: 'info', title: 'Module visibility saved!' });
}

function toggleAllModules(enable) {
    Toast.fire({ icon: 'info', title: enable ? 'All modules enabled!' : 'All modules disabled!' });
}

function saveAllSettings() {
    saveDeliverySettings();
}
</script>