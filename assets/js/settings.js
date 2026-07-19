// SweetAlert for notifications
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
});

// Load all settings on page load
document.addEventListener('DOMContentLoaded', function() {
    loadClinicDetails();
    loadWorkingHours();
    loadModuleVisibility();
    setupWorkingHoursToggle();
    setupLogoUpload();
    setupCoverUpload();
});

// ============================================
// CLINIC DETAILS FUNCTIONS
// ============================================

// Load clinic details for editing
async function loadClinicDetails() {
    try {
        const response = await fetch('api/settings.php?action=get_clinic_details');
        const result = await response.json();
        
        if (result.success && result.data) {
            const clinic = result.data;
            
            const nameInput = document.getElementById('clinic_name');
            if (nameInput) nameInput.value = clinic.clinic_name || '';
            
            const descInput = document.getElementById('clinic_description');
            if (descInput) descInput.value = clinic.description || '';
            
            const contactInput = document.getElementById('clinic_contact');
            if (contactInput) contactInput.value = clinic.contact || '';
            
            const emailInput = document.getElementById('clinic_email');
            if (emailInput) emailInput.value = clinic.clinic_email || '';
            
            const addressInput = document.getElementById('clinic_address');
            if (addressInput) addressInput.value = clinic.address || '';
            
            const cityInput = document.getElementById('clinic_city');
            if (cityInput) cityInput.value = clinic.city || '';
            
            const provinceInput = document.getElementById('clinic_province');
            if (provinceInput) provinceInput.value = clinic.province || '';
            
            const postalInput = document.getElementById('clinic_postal');
            if (postalInput) postalInput.value = clinic.postal_code || '';
            
            const hoursInput = document.getElementById('clinic_hours');
            if (hoursInput) hoursInput.value = clinic.hours || '';
            
            const daysInput = document.getElementById('clinic_days');
            if (daysInput) daysInput.value = clinic.days || '';
            
            const latInput = document.getElementById('clinic_lat');
            if (latInput) latInput.value = clinic.latitude || '';
            
            const lngInput = document.getElementById('clinic_lng');
            if (lngInput) lngInput.value = clinic.longitude || '';
            
            const radiusInput = document.getElementById('clinic_radius');
            if (radiusInput) radiusInput.value = clinic.radius || 100;
            
            const statusSelect = document.getElementById('clinic_status');
            if (statusSelect) statusSelect.value = clinic.status || 'active';
            
            updateClinicPreview();
        }
    } catch (error) {
        console.error('Error loading clinic details:', error);
    }
}

// Update preview
function updateClinicPreview() {
    const nameInput = document.getElementById('clinic_name');
    const addressInput = document.getElementById('clinic_address');
    const contactInput = document.getElementById('clinic_contact');
    
    const name = nameInput ? nameInput.value : 'Clinic Name';
    const address = addressInput ? addressInput.value : 'Address here';
    const contact = contactInput ? contactInput.value : 'Contact here';
    
    const previewName = document.getElementById('previewName');
    const previewAddressText = document.getElementById('previewAddressText');
    const previewContactText = document.getElementById('previewContactText');
    
    if (previewName) previewName.textContent = name;
    if (previewAddressText) previewAddressText.textContent = address;
    if (previewContactText) previewContactText.textContent = contact;
}

// Preview logo before upload
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const currentLogo = document.getElementById('current_logo');
            const logoPlaceholder = document.querySelector('#current_logo').parentElement;
            
            if (currentLogo && currentLogo.tagName === 'IMG') {
                currentLogo.src = e.target.result;
                currentLogo.style.display = 'block';
            } else if (currentLogo) {
                // If it's not an img tag, create one
                const parent = logoPlaceholder;
                parent.innerHTML = `<img src="${e.target.result}" class="img-fluid rounded" style="max-height: 80px;" id="current_logo">`;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Preview cover before upload
function previewCover(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const coverPreview = document.getElementById('cover_preview');
            const coverPlaceholder = document.getElementById('coverPlaceholder');
            
            if (coverPreview) {
                coverPreview.src = e.target.result;
                coverPreview.classList.remove('d-none');
            }
            if (coverPlaceholder) {
                coverPlaceholder.classList.add('d-none');
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Setup cover upload preview
function setupCoverUpload() {
    const coverInput = document.getElementById('clinic_cover');
    if (coverInput) {
        coverInput.addEventListener('change', function(e) {
            previewCover(this);
        });
    }
}

// Get current location using browser geolocation
function getCurrentLocation() {
    if (!navigator.geolocation) {
        Swal.fire('Error!', 'Geolocation is not supported by your browser', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Getting Location...',
        text: 'Please allow location access when prompted',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    navigator.geolocation.getCurrentPosition(
        (position) => {
            const latInput = document.getElementById('clinic_lat');
            const lngInput = document.getElementById('clinic_lng');
            
            if (latInput) latInput.value = position.coords.latitude.toFixed(8);
            if (lngInput) lngInput.value = position.coords.longitude.toFixed(8);
            
            Swal.close();
            Toast.fire({ icon: 'success', title: 'Location captured successfully!' });
        },
        (error) => {
            Swal.close();
            let message = 'Failed to get location';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    message = 'Location access denied. Please enable location permissions.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = 'Location information is unavailable.';
                    break;
                case error.TIMEOUT:
                    message = 'Location request timed out.';
                    break;
            }
            Swal.fire('Error!', message, 'error');
        }
    );
}

// Show location on map
function showLocationOnMap() {
    const lat = document.getElementById('clinic_lat').value;
    const lng = document.getElementById('clinic_lng').value;
    
    if (!lat || !lng) {
        Swal.fire('Error!', 'Please set location first', 'error');
        return;
    }
    
    window.open(`https://www.google.com/maps?q=${lat},${lng}`, '_blank');
}

// Save clinic details
async function saveClinicDetails() {
    const nameInput = document.getElementById('clinic_name');
    const descInput = document.getElementById('clinic_description');
    const contactInput = document.getElementById('clinic_contact');
    const emailInput = document.getElementById('clinic_email');
    const addressInput = document.getElementById('clinic_address');
    const cityInput = document.getElementById('clinic_city');
    const provinceInput = document.getElementById('clinic_province');
    const postalInput = document.getElementById('clinic_postal');
    const hoursInput = document.getElementById('clinic_hours');
    const daysInput = document.getElementById('clinic_days');
    const latInput = document.getElementById('clinic_lat');
    const lngInput = document.getElementById('clinic_lng');
    const radiusInput = document.getElementById('clinic_radius');
    const statusSelect = document.getElementById('clinic_status');
    
    if (!nameInput || !contactInput || !addressInput) {
        Swal.fire('Error!', 'Required form elements not found', 'error');
        return;
    }
    
    const data = {
        name: nameInput.value,
        description: descInput ? descInput.value : '',
        contact: contactInput.value,
        email: emailInput ? emailInput.value : '',
        address: addressInput.value,
        city: cityInput ? cityInput.value : '',
        province: provinceInput ? provinceInput.value : '',
        postal: postalInput ? postalInput.value : '',
        hours: hoursInput ? hoursInput.value : '',
        days: daysInput ? daysInput.value : '',
        latitude: latInput ? latInput.value : '',
        longitude: lngInput ? lngInput.value : '',
        radius: radiusInput ? radiusInput.value : 100,
        status: statusSelect ? statusSelect.value : 'active'
    };
    
    // Upload logo if selected
    const logoInput = document.getElementById('clinic_logo');
    if (logoInput && logoInput.files.length > 0) {
        const formData = new FormData();
        formData.append('clinic_logo', logoInput.files[0]);
        
        try {
            const uploadResponse = await fetch('api/settings.php', {
                method: 'POST',
                body: formData
            });
            const uploadResult = await uploadResponse.json();
            if (!uploadResult.success) {
                Toast.fire({ icon: 'error', title: uploadResult.error || 'Failed to upload logo' });
                return;
            }
        } catch (error) {
            console.error('Logo upload error:', error);
            Toast.fire({ icon: 'error', title: 'Failed to upload logo' });
            return;
        }
    }
    
    // Upload cover if selected
    const coverInput = document.getElementById('clinic_cover');
    if (coverInput && coverInput.files.length > 0) {
        const formData = new FormData();
        formData.append('clinic_cover', coverInput.files[0]);
        
        try {
            const uploadResponse = await fetch('api/settings.php', {
                method: 'POST',
                body: formData
            });
            const uploadResult = await uploadResponse.json();
            if (!uploadResult.success) {
                Toast.fire({ icon: 'error', title: uploadResult.error || 'Failed to upload cover' });
                return;
            }
        } catch (error) {
            console.error('Cover upload error:', error);
            Toast.fire({ icon: 'error', title: 'Failed to upload cover' });
            return;
        }
    }
    
    try {
        Swal.fire({ title: 'Saving...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        const response = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'clinic_details', data: data })
        });
        
        const result = await response.json();
        
        if (result.success) {
            Swal.close();
            Toast.fire({ icon: 'success', title: 'Clinic details saved successfully!' });
            updateClinicPreview();
            setTimeout(loadClinicDetails, 1000);
        } else {
            Swal.close();
            Swal.fire('Error!', result.error || 'Failed to save', 'error');
        }
    } catch (error) {
        Swal.close();
        Swal.fire('Error!', 'Failed to save clinic details', 'error');
    }
}

// Reset form
function resetClinicForm() {
    Swal.fire({
        title: 'Reset changes?',
        text: "Unsaved changes will be lost",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, reset'
    }).then((result) => {
        if (result.isConfirmed) {
            loadClinicDetails();
        }
    });
}

// Setup logo upload preview
function setupLogoUpload() {
    const logoInput = document.getElementById('clinic_logo');
    if (logoInput) {
        logoInput.addEventListener('change', function(e) {
            previewLogo(this);
        });
    }
}

// ============================================
// WORKING HOURS FUNCTIONS
// ============================================

// Load working hours
async function loadWorkingHours() {
    try {
        const response = await fetch('api/settings.php?action=get_working_hours');
        const result = await response.json();
        
        if (result.success && result.working_hours) {
            const workingHours = result.working_hours;
            const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            
            days.forEach(day => {
                const dayData = workingHours[day] || { enabled: false, open: '', close: '' };
                
                const toggle = document.querySelector(`.day-toggle[data-day="${day}"]`);
                const openTime = document.querySelector(`.open-time[data-day="${day}"]`);
                const closeTime = document.querySelector(`.close-time[data-day="${day}"]`);
                
                if (toggle) {
                    toggle.checked = dayData.enabled || false;
                    if (openTime) {
                        openTime.disabled = !dayData.enabled;
                        openTime.value = dayData.open || '';
                    }
                    if (closeTime) {
                        closeTime.disabled = !dayData.enabled;
                        closeTime.value = dayData.close || '';
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error loading working hours:', error);
    }
}

// Setup working hours toggle
function setupWorkingHoursToggle() {
    document.querySelectorAll('.day-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const day = this.dataset.day;
            const openTime = document.querySelector(`.open-time[data-day="${day}"]`);
            const closeTime = document.querySelector(`.close-time[data-day="${day}"]`);
            
            if (this.checked) {
                openTime.disabled = false;
                closeTime.disabled = false;
                if (!openTime.value) openTime.value = '09:00';
                if (!closeTime.value) closeTime.value = '18:00';
            } else {
                openTime.disabled = true;
                closeTime.disabled = true;
                openTime.value = '';
                closeTime.value = '';
            }
        });
    });
}

// Save working hours
async function saveWorkingHours() {
    const workingHours = {};
    const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    
    days.forEach(day => {
        const toggle = document.querySelector(`.day-toggle[data-day="${day}"]`);
        const openTime = document.querySelector(`.open-time[data-day="${day}"]`);
        const closeTime = document.querySelector(`.close-time[data-day="${day}"]`);
        
        workingHours[day] = {
            enabled: toggle ? toggle.checked : false,
            open: openTime ? openTime.value : '',
            close: closeTime ? closeTime.value : ''
        };
    });
    
    try {
        Swal.fire({ title: 'Saving...', text: 'Please wait', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        const response = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'working_hours', data: workingHours })
        });
        
        const result = await response.json();
        Swal.close();
        
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Working hours saved successfully!' });
        } else {
            Toast.fire({ icon: 'error', title: result.error || 'Failed to save working hours' });
        }
    } catch (error) {
        Swal.close();
        Toast.fire({ icon: 'error', title: 'Failed to save working hours' });
    }
}

// ============================================
// MODULE VISIBILITY FUNCTIONS
// ============================================

let currentModules = [];

// Load module visibility settings
async function loadModuleVisibility() {
    try {
        const response = await fetch('api/settings.php?action=get_module_visibility');
        const data = await response.json();
        
        if (data.success) {
            currentModules = data.modules;
            displayModuleVisibility();
        }
    } catch (error) {
        console.error('Error loading module visibility:', error);
    }
}

// Display module toggles
function displayModuleVisibility() {
    const container = document.getElementById('moduleVisibilityContainer');
    if (!container) return;
    
    if (!currentModules || currentModules.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-3">No modules found</div>';
        return;
    }
    
    const moduleIcons = {
        'Human Resources': 'bi-person-gear',
        'Finance & Payments': 'bi-cash-stack',
        'Supply Chain': 'bi-box-seam',
        'Customer Care': 'bi-people-fill',
        'Optical': 'bi-eyeglasses',
        'Workforce': 'bi-briefcase',
        'Administration': 'bi-gear-wide'
    };
    
    let html = '';
    currentModules.forEach(module => {
        const icon = moduleIcons[module.group_name] || 'bi-folder';
        const isEnabled = module.enabled == 1;
        
        html += `
            <div class="mb-3 p-3 border rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi ${icon} fs-5 me-2 ${isEnabled ? 'text-primary' : 'text-muted'}"></i>
                        <strong>${escapeHtml(module.group_name)}</strong>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input module-toggle" 
                               type="checkbox" 
                               data-group="${escapeHtml(module.group_name)}"
                               ${isEnabled ? 'checked' : ''}>
                        <span class="ms-1 small ${isEnabled ? 'text-success' : 'text-muted'}">
                            ${isEnabled ? 'ON' : 'OFF'}
                        </span>
                    </div>
                </div>
                <small class="text-muted d-block mt-2">
                    ${getModuleDescription(module.group_name)}
                </small>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    // Add event listeners to update status text
    document.querySelectorAll('.module-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const statusSpan = this.closest('.d-flex').querySelector('.small');
            const icon = this.closest('.d-flex').querySelector('i');
            if (statusSpan) {
                statusSpan.textContent = this.checked ? 'ON' : 'OFF';
                statusSpan.className = `ms-1 small ${this.checked ? 'text-success' : 'text-muted'}`;
            }
            if (icon) {
                if (this.checked) {
                    icon.classList.add('text-primary');
                    icon.classList.remove('text-muted');
                } else {
                    icon.classList.add('text-muted');
                    icon.classList.remove('text-primary');
                }
            }
        });
    });
}

// Get module description
function getModuleDescription(groupName) {
    const descriptions = {
        'Human Resources': 'Employee records, payroll, attendance, leaves, schedule management',
        'Finance & Payments': 'Sales, billing, expenses, payroll approval, reports',
        'Supply Chain': 'Inventory, purchase orders, suppliers, products catalog',
        'Customer Care': 'Patients, appointments, messages, tasks, CRM dashboard',
        'Optical': 'Doctor dashboard, patient records, decision support',
        'Workforce': 'My schedule, attendance, leave requests',
        'Administration': 'Reports, activity logs, system settings'
    };
    return descriptions[groupName] || 'Module settings';
}

// Save module visibility
async function saveModuleVisibility() {
    const toggles = document.querySelectorAll('.module-toggle');
    const modules = [];
    
    toggles.forEach(toggle => {
        modules.push({
            group_name: toggle.dataset.group,
            enabled: toggle.checked ? 1 : 0
        });
    });
    
    Swal.fire({
        title: 'Saving module settings...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    try {
        const response = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: 'update_module_visibility',
                data: { modules: modules }
            })
        });
        
        const result = await response.json();
        Swal.close();
        
        if (result.success) {
            Toast.fire({ icon: 'success', title: 'Module visibility saved! Refresh to see changes.' });
            
            Swal.fire({
                title: 'Settings Saved!',
                text: 'Refresh the page to see the updated sidebar?',
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: 'Refresh Now',
                cancelButtonText: 'Later'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.reload();
                }
            });
        } else {
            Swal.fire('Error', result.error || 'Failed to save', 'error');
        }
    } catch (error) {
        Swal.close();
        Swal.fire('Error', 'Failed to save module visibility', 'error');
    }
}

// Toggle all modules
function toggleAllModules(enable) {
    document.querySelectorAll('.module-toggle').forEach(toggle => {
        toggle.checked = enable;
        const statusSpan = toggle.closest('.d-flex').querySelector('.small');
        const icon = toggle.closest('.d-flex').querySelector('i');
        if (statusSpan) {
            statusSpan.textContent = enable ? 'ON' : 'OFF';
            statusSpan.className = `ms-1 small ${enable ? 'text-success' : 'text-muted'}`;
        }
        if (icon) {
            if (enable) {
                icon.classList.add('text-primary');
                icon.classList.remove('text-muted');
            } else {
                icon.classList.add('text-muted');
                icon.classList.remove('text-primary');
            }
        }
    });
}

// Save all settings (only Clinic Details, Working Hours, Module Visibility)
async function saveAllSettings() {
    const confirm = await Swal.fire({
        title: 'Save All Settings?',
        text: 'This will update clinic details, working hours, and module visibility.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, save all',
        cancelButtonText: 'Cancel'
    });
    
    if (confirm.isConfirmed) {
        try {
            await saveClinicDetails();
            await saveWorkingHours();
            await saveModuleVisibility();
            
            Swal.fire({ title: 'Success!', text: 'All settings have been saved successfully.', icon: 'success', confirmButtonColor: '#3085d6' });
        } catch (error) {
            Swal.fire({ title: 'Error!', text: 'Failed to save all settings.', icon: 'error', confirmButtonColor: '#3085d6' });
        }
    }
}

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}