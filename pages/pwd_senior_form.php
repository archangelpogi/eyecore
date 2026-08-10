<?php
// pwd_senior_form.php - PWD/Senior Verification Form with Clinic Selection
// This file is included in profile.php when needed

// Get all clinics for dropdown
$clinics_query = mysqli_query($conn, "SELECT id, name FROM clinics ORDER BY name ASC");

// ✅ CHECK EXISTING VERIFICATIONS FROM user_verifications TABLE
$existing_verifications_query = mysqli_query($conn, "
    SELECT 
        uv.clinic_id,
        uv.status,
        c.name as clinic_name
    FROM user_verifications uv
    LEFT JOIN clinics c ON uv.clinic_id = c.id
    WHERE uv.user_id = $user_id 
    AND uv.status IN ('pending', 'verified')
");
$existing_verifications = [];
while ($row = mysqli_fetch_assoc($existing_verifications_query)) {
    $existing_verifications[] = $row;
}

// ✅ GET ALREADY APPLIED CLINICS
$applied_clinics = [];
$applied_query = mysqli_query($conn, "
    SELECT clinic_id 
    FROM user_verifications 
    WHERE user_id = $user_id 
    AND status != 'none'
");
while ($row = mysqli_fetch_assoc($applied_query)) {
    $applied_clinics[] = $row['clinic_id'];
}
?>

<style>
    /* Additional styles for the clinic selection form */
    .clinic-search-dropdown {
        position: relative;
    }
    
    .clinic-search-dropdown .dropdown-list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--bg-secondary);
        border: 2px solid var(--border-color);
        border-top: none;
        border-radius: 0 0 var(--radius-md) var(--radius-md);
        max-height: 200px;
        overflow-y: auto;
        display: none;
        z-index: 10;
        box-shadow: var(--shadow-md);
    }
    
    .clinic-search-dropdown .dropdown-list.show {
        display: block;
    }
    
    .clinic-search-dropdown .dropdown-item {
        padding: 10px 14px;
        cursor: pointer;
        transition: all 0.2s;
        color: var(--text-primary);
        font-size: 14px;
        border-bottom: 1px solid var(--border-light);
    }
    
    .clinic-search-dropdown .dropdown-item:hover:not([style*="cursor: not-allowed"]) {
        background: var(--primary-light);
        color: var(--primary);
    }
    
    .clinic-search-dropdown .dropdown-item:last-child {
        border-bottom: none;
    }
    
    .clinic-search-dropdown .form-control {
        border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    
    .clinic-search-dropdown .form-control:focus {
        border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    
    .selected-clinic-display {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: var(--bg-primary);
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: all 0.2s;
        color: var(--text-primary);
    }
    
    .selected-clinic-display:hover {
        border-color: var(--primary);
    }
    
    .selected-clinic-display .clinic-name {
        font-weight: 500;
    }
    
    .selected-clinic-display .clinic-arrow {
        color: var(--text-muted);
        transition: transform 0.2s;
    }
    
    .selected-clinic-display.active .clinic-arrow {
        transform: rotate(180deg);
    }
    
    .selected-clinic-display .clear-clinic {
        color: var(--danger);
        cursor: pointer;
        padding: 0 5px;
        font-size: 16px;
    }
    
    .selected-clinic-display .clear-clinic:hover {
        color: #cc0000;
    }
    
    .review-summary {
        background: var(--bg-primary);
        padding: 20px;
        border-radius: var(--radius-md);
        border: 1px solid var(--border-light);
        margin: 15px 0;
    }
    
    .review-summary .review-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid var(--border-light);
        font-size: 14px;
    }
    
    .review-summary .review-row:last-child {
        border-bottom: none;
    }
    
    .review-summary .review-label {
        color: var(--text-secondary);
    }
    
    .review-summary .review-value {
        font-weight: 600;
        color: var(--text-primary);
        text-align: right;
    }
    
    .review-summary .review-value.verified-badge {
        color: var(--success);
    }
    
    .review-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
    
    .review-actions .btn-cancel-review {
        flex: 1;
        padding: 12px;
        background: var(--bg-primary);
        color: var(--text-secondary);
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .review-actions .btn-cancel-review:hover {
        background: var(--danger);
        color: white;
        border-color: var(--danger);
    }
    
    .review-actions .btn-submit-review {
        flex: 2;
        padding: 12px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .review-actions .btn-submit-review:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .review-actions .btn-submit-review:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    
    .form-step-indicator {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .form-step-indicator .step {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--text-muted);
        padding: 6px 12px;
        border-radius: var(--radius-full);
        background: var(--bg-primary);
        border: 1px solid var(--border-light);
    }
    
    .form-step-indicator .step.active {
        background: var(--primary-light);
        color: var(--primary);
        border-color: var(--primary);
        font-weight: 600;
    }
    
    .form-step-indicator .step.completed {
        background: var(--open-bg);
        color: var(--open-text);
        border-color: var(--open-text);
    }
    
    .form-step-indicator .step-arrow {
        color: var(--text-muted);
        font-size: 12px;
    }
    
    /* For mobile responsiveness */
    @media (max-width: 768px) {
        .review-actions {
            flex-direction: column;
        }
        
        .review-actions .btn-cancel-review,
        .review-actions .btn-submit-review {
            width: 100%;
        }
        
        .form-step-indicator {
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .form-step-indicator .step {
            font-size: 11px;
            padding: 4px 10px;
        }
        
        .form-step-indicator .step-arrow {
            display: none;
        }
    }
</style>

<?php if (!empty($existing_verifications)): ?>
<div style="background: var(--primary-light); border: 1px solid var(--primary); border-radius: var(--radius-md); padding: 15px; margin-bottom: 20px;">
    <i class="fas fa-info-circle" style="color: var(--primary);"></i>
    <strong style="color: var(--text-primary);">Your existing verifications:</strong>
    <ul style="margin: 10px 0 0 20px; color: var(--text-secondary);">
        <?php foreach ($existing_verifications as $ev): ?>
            <li>
                <?php echo htmlspecialchars($ev['clinic_name'] ?? 'Unknown Clinic'); ?> - 
                <span style="font-weight: 600; color: <?php echo $ev['status'] == 'verified' ? 'var(--success)' : 'var(--warning)'; ?>;">
                    <?php echo ucfirst($ev['status'] ?? 'pending'); ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p style="margin-top: 10px; font-size: 13px; color: var(--text-secondary);">
        <i class="fas fa-info-circle"></i>
        You can apply for verification at multiple clinics. Each clinic verifies independently.
    </p>
</div>
<?php endif; ?>

<div class="pwd-verification-form" id="pwdSeniorForm">
    <div class="form-step-indicator">
        <span class="step active" id="step1Indicator">
            <i class="fas fa-clinic-medical"></i> Clinic
        </span>
        <span class="step-arrow"><i class="fas fa-chevron-right"></i></span>
        <span class="step" id="step2Indicator">
            <i class="fas fa-tag"></i> Type
        </span>
        <span class="step-arrow"><i class="fas fa-chevron-right"></i></span>
        <span class="step" id="step3Indicator">
            <i class="fas fa-id-card"></i> ID Details
        </span>
        <span class="step-arrow"><i class="fas fa-chevron-right"></i></span>
        <span class="step" id="step4Indicator">
            <i class="fas fa-check-circle"></i> Review
        </span>
    </div>
    
    <form method="POST" action="" enctype="multipart/form-data" id="verificationForm">
        <!-- STEP 1: Select Clinic -->
        <div id="step1" class="form-step">
            <div class="form-group">
                <label><i class="fas fa-clinic-medical"></i> Which clinic would you like to apply for?</label>
                <div class="clinic-search-dropdown">
                    <input type="text" 
                           id="clinicSearch" 
                           class="form-control" 
                           placeholder="🔍 Search clinic..." 
                           autocomplete="off"
                           oninput="filterClinics(this.value)">
                    <div class="dropdown-list" id="clinicDropdown">
                        <?php while($clinic = mysqli_fetch_assoc($clinics_query)): 
                            $has_applied = in_array($clinic['id'], $applied_clinics);
                        ?>
                            <div class="dropdown-item" 
                                 data-id="<?php echo $clinic['id']; ?>" 
                                 data-name="<?php echo htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-has-applied="<?php echo $has_applied ? 'true' : 'false'; ?>"
                                 onclick="selectClinic(this)"
                                 style="<?php echo $has_applied ? 'opacity:0.5;cursor:not-allowed;background:var(--border-light);' : ''; ?>">
                                <?php echo htmlspecialchars($clinic['name']); ?>
                                <?php if ($has_applied): ?>
                                    <span style="font-size:11px;color:var(--text-muted);margin-left:10px;">
                                        <i class="fas fa-check-circle" style="color:var(--success);"></i> Already applied
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <input type="hidden" name="clinic_id" id="selectedClinicId" required>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i>
                    Your verification will be specific to this clinic only.
                </div>
            </div>
            <button type="button" class="btn-submit-verify" onclick="goToStep(2)" disabled id="step1Next">
                <i class="fas fa-arrow-right"></i> Next
            </button>
        </div>
        
        <!-- STEP 2: Select Type -->
        <div id="step2" class="form-step" style="display:none;">
            <div class="form-group">
                <label><i class="fas fa-tag"></i> Verification Type</label>
                <div style="display:flex;gap:15px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 20px;border:2px solid var(--border-color);border-radius:var(--radius-md);transition:all 0.2s;" 
                           class="type-option" 
                           onclick="selectType('senior')">
                        <input type="radio" name="pwd_senior_type" value="senior" style="display:none;" required>
                        <i class="fas fa-user-plus"></i>
                        Senior Citizen
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 20px;border:2px solid var(--border-color);border-radius:var(--radius-md);transition:all 0.2s;" 
                           class="type-option" 
                           onclick="selectType('pwd')">
                        <input type="radio" name="pwd_senior_type" value="pwd" style="display:none;" required>
                        <i class="fas fa-wheelchair"></i>
                        PWD
                    </label>
                </div>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i>
                    Select one type per application.
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-cancel-verify" onclick="goToStep(1)" style="flex:1;">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn-submit-verify" onclick="goToStep(3)" disabled id="step2Next" style="flex:2;">
                    Next <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- STEP 3: ID Details -->
        <div id="step3" class="form-step" style="display:none;">
            <div class="form-group">
                <label id="idNumberLabel"><i class="fas fa-id-card"></i> ID Number</label>
                <input type="text" 
                       name="pwd_senior_id_number" 
                       class="form-control" 
                       id="idNumberInput"
                       placeholder="Enter your ID number" 
                       required>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-upload"></i> Upload Valid ID</label>
                <input type="file" 
                       name="pwd_senior_id_image" 
                       class="form-control" 
                       accept=".jpg,.jpeg,.png,.pdf" 
                       required>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i>
                    Upload a clear photo of your valid ID. Allowed: JPG, PNG, PDF (Max 5MB)
                </div>
            </div>
            
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-cancel-verify" onclick="goToStep(2)" style="flex:1;">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn-submit-verify" onclick="goToStep(4)" id="step3Next" style="flex:2;">
                    Review <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- STEP 4: Review -->
        <div id="step4" class="form-step" style="display:none;">
            <div class="review-summary">
                <div class="review-row">
                    <span class="review-label"><i class="fas fa-clinic-medical"></i> Clinic</span>
                    <span class="review-value" id="reviewClinic">-</span>
                </div>
                <div class="review-row">
                    <span class="review-label"><i class="fas fa-tag"></i> Type</span>
                    <span class="review-value" id="reviewType">-</span>
                </div>
                <div class="review-row">
                    <span class="review-label"><i class="fas fa-id-card"></i> ID Number</span>
                    <span class="review-value" id="reviewIdNumber">-</span>
                </div>
                <div class="review-row">
                    <span class="review-label"><i class="fas fa-upload"></i> ID Upload</span>
                    <span class="review-value verified-badge" id="reviewUpload">
                        <i class="fas fa-check-circle"></i> Uploaded ✓
                    </span>
                </div>
            </div>
            
            <div class="pwd-helper-text" style="margin-bottom:15px;">
                <i class="fas fa-shield-alt"></i>
                Please review your application before submitting.
            </div>
            
            <div class="review-actions">
                <button type="button" class="btn-cancel-review" onclick="goToStep(3)">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="submit" name="submit_pwd_senior" class="btn-submit-review">
                    <i class="fas fa-paper-plane"></i> Submit for Verification
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    // Clinic selection state
    let selectedClinicId = null;
    let selectedClinicName = null;
    let selectedType = null;
    
    // Filter clinics in dropdown
    function filterClinics(search) {
        const dropdown = document.getElementById('clinicDropdown');
        const items = dropdown.querySelectorAll('.dropdown-item');
        const searchLower = search.toLowerCase().trim();
        
        items.forEach(item => {
            const name = item.getAttribute('data-name').toLowerCase();
            const hasApplied = item.getAttribute('data-has-applied') === 'true';
            
            if (name.includes(searchLower) && !hasApplied) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        
        dropdown.classList.add('show');
    }
    
    // Select a clinic - with applied check
    function selectClinic(element) {
        const hasApplied = element.getAttribute('data-has-applied') === 'true';
        
        if (hasApplied) {
            showToast('You already have a pending or approved application for this clinic.', 'error');
            document.getElementById('clinicDropdown').classList.remove('show');
            return;
        }
        
        const id = element.getAttribute('data-id');
        const name = element.getAttribute('data-name');
        
        selectedClinicId = id;
        selectedClinicName = name;
        document.getElementById('selectedClinicId').value = id;
        document.getElementById('clinicSearch').value = name;
        document.getElementById('clinicDropdown').classList.remove('show');
        document.getElementById('step1Next').disabled = false;
        
        // Update review
        document.getElementById('reviewClinic').textContent = name;
    }
    
    // Select verification type
    function selectType(type) {
        selectedType = type;
        
        // Update radio button
        const radios = document.querySelectorAll('input[name="pwd_senior_type"]');
        radios.forEach(radio => {
            if (radio.value === type) {
                radio.checked = true;
            }
        });
        
        // Update visual
        const options = document.querySelectorAll('.type-option');
        options.forEach(opt => {
            opt.style.borderColor = 'var(--border-color)';
            opt.style.background = 'transparent';
        });
        
        const selectedOption = document.querySelector(`.type-option input[value="${type}"]`).closest('.type-option');
        selectedOption.style.borderColor = 'var(--primary)';
        selectedOption.style.background = 'var(--primary-light)';
        
        // Enable next button
        document.getElementById('step2Next').disabled = false;
        
        // Update label
        document.getElementById('idNumberLabel').innerHTML = 
            `<i class="fas fa-id-card"></i> ${type === 'senior' ? 'Senior Citizen' : 'PWD'} ID Number`;
        document.getElementById('idNumberInput').placeholder = 
            `Enter ${type === 'senior' ? 'Senior Citizen' : 'PWD'} ID number`;
        
        // Update review
        document.getElementById('reviewType').textContent = 
            type === 'senior' ? 'Senior Citizen' : 'PWD';
    }
    
    // Navigation between steps
    function goToStep(step) {
        // Hide all steps
        document.querySelectorAll('.form-step').forEach(el => el.style.display = 'none');
        
        // Show target step
        document.getElementById('step' + step).style.display = 'block';
        
        // Update step indicators
        document.querySelectorAll('.step').forEach(el => {
            el.classList.remove('active', 'completed');
        });
        
        for (let i = 1; i <= 4; i++) {
            const indicator = document.getElementById('step' + i + 'Indicator');
            if (i < step) {
                indicator.classList.add('completed');
            } else if (i === step) {
                indicator.classList.add('active');
            }
        }
        
        // If going to step 4, update review
        if (step === 4) {
            document.getElementById('reviewClinic').textContent = selectedClinicName || '-';
            document.getElementById('reviewType').textContent = 
                selectedType === 'senior' ? 'Senior Citizen' : 
                selectedType === 'pwd' ? 'PWD' : '-';
            document.getElementById('reviewIdNumber').textContent = 
                document.getElementById('idNumberInput').value || '-';
        }
        
        // Scroll to form
        document.getElementById('pwdSeniorForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('clinicDropdown');
        const search = document.getElementById('clinicSearch');
        if (!search.contains(event.target) && !dropdown.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });
    
    // Handle Enter key in search
    document.getElementById('clinicSearch').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const visibleItems = document.querySelectorAll('#clinicDropdown .dropdown-item[style*="display: block"]');
            if (visibleItems.length > 0) {
                const first = visibleItems[0];
                const hasApplied = first.getAttribute('data-has-applied') === 'true';
                if (!hasApplied) {
                    selectClinic(first);
                } else {
                    showToast('You already have an application for this clinic.', 'error');
                }
            }
        }
    });
    
    // Validate step 3 (ID number)
    document.getElementById('idNumberInput').addEventListener('input', function() {
        const hasValue = this.value.trim().length > 0;
        document.getElementById('step3Next').disabled = !hasValue;
    });
    
    // Validate file upload
    document.querySelector('input[name="pwd_senior_id_image"]').addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            const file = this.files[0];
            const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!validTypes.includes(file.type)) {
                showToast('Invalid file type. Allowed: JPG, PNG, PDF', 'error');
                this.value = '';
                return;
            }
            
            if (file.size > maxSize) {
                showToast('File too large. Max 5MB.', 'error');
                this.value = '';
                return;
            }
            
            showToast('File uploaded successfully: ' + file.name, 'success');
        }
    });
</script>