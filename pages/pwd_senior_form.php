<?php
// pwd_senior_form.php - PWD/Senior Verification Form with Clinic Selection
// This file is included in profile.php when needed

// Get all clinics for dropdown
$clinics_query = mysqli_query($conn, "SELECT id, name, city, province FROM clinics WHERE status = 'Active' ORDER BY name ASC");

// ✅ CHECK EXISTING VERIFICATIONS FROM user_verifications TABLE
$existing_verifications_query = mysqli_query($conn, "
    SELECT 
        uv.clinic_id,
        uv.status,
        uv.verification_type,
        uv.id_number,
        uv.date_issued,
        uv.valid_until,
        uv.id_image,
        uv.rejection_reason,
        uv.verified_at,
        c.name as clinic_name
    FROM user_verifications uv
    LEFT JOIN clinics c ON uv.clinic_id = c.id
    WHERE uv.user_id = $user_id 
    AND uv.status != 'none'
    ORDER BY uv.status = 'pending' DESC, uv.created_at DESC
");
$existing_verifications = [];
while ($row = mysqli_fetch_assoc($existing_verifications_query)) {
    $existing_verifications[] = $row;
}

// ✅ GET ALREADY APPLIED CLINICS (pending or verified only)
$applied_clinics = [];
$applied_query = mysqli_query($conn, "
    SELECT clinic_id 
    FROM user_verifications 
    WHERE user_id = $user_id 
    AND status IN ('pending', 'verified')
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
    
    .form-step .btn-submit-verify {
        padding: 12px 30px;
        background: var(--primary-gradient);
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-step .btn-submit-verify:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .form-step .btn-submit-verify:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    
    .form-step .btn-cancel-verify {
        padding: 12px 30px;
        background: var(--bg-primary);
        color: var(--text-secondary);
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-step .btn-cancel-verify:hover {
        background: var(--danger);
        color: white;
        border-color: var(--danger);
    }
    
    .clinic-info-text {
        font-size: 13px;
        color: var(--text-secondary);
        margin-top: 5px;
    }
    
    .clinic-info-text i {
        color: var(--primary);
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
        
        .form-step .btn-submit-verify,
        .form-step .btn-cancel-verify {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<?php if (!empty($existing_verifications)): ?>
<div style="background: var(--primary-light); border: 1px solid var(--primary); border-radius: var(--radius-md); padding: 15px; margin-bottom: 20px;">
    <i class="fas fa-info-circle" style="color: var(--primary);"></i>
    <strong style="color: var(--text-primary);">Your existing verifications:</strong>
    <ul style="margin: 10px 0 0 20px; color: var(--text-secondary); list-style: none; padding-left: 0;">
        <?php foreach ($existing_verifications as $ev): 
            $status_class = '';
            $status_text = '';
            $status_color = '';
            
            if ($ev['status'] == 'verified') {
                $status_class = 'verified';
                $status_text = '✅ Verified';
                $status_color = 'var(--success)';
            } elseif ($ev['status'] == 'pending') {
                $status_class = 'pending';
                $status_text = '⏳ Pending';
                $status_color = 'var(--warning)';
            } elseif ($ev['status'] == 'rejected') {
                $status_class = 'rejected';
                $status_text = '❌ Rejected';
                $status_color = 'var(--danger)';
            } else {
                $status_class = 'none';
                $status_text = 'Not Applied';
                $status_color = 'var(--text-muted)';
            }
            
            $type_label = $ev['verification_type'] == 'senior' ? '👴 Senior' : '♿ PWD';
        ?>
            <li style="padding: 8px 0; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 5px;">
                <span>
                    <strong><?php echo htmlspecialchars($ev['clinic_name'] ?? 'Unknown Clinic'); ?></strong>
                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 10px;"><?php echo $type_label; ?></span>
                </span>
                <span style="font-weight: 600; color: <?php echo $status_color; ?>;">
                    <?php echo $status_text; ?>
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
    
    <form method="POST" action="" enctype="multipart/form-data" id="verificationForm" onsubmit="return validateForm()">
        <!-- STEP 1: Select Clinic -->
        <div id="step1" class="form-step">
            <div class="form-group">
                <label><i class="fas fa-clinic-medical"></i> Which clinic would you like to apply for? <span class="text-danger">*</span></label>
                <div class="clinic-search-dropdown">
                    <input type="text" 
                           id="clinicSearch" 
                           class="form-control" 
                           placeholder="🔍 Search clinic by name..." 
                           autocomplete="off"
                           oninput="filterClinics(this.value)">
                    <div class="dropdown-list" id="clinicDropdown">
                        <?php if (mysqli_num_rows($clinics_query) > 0): ?>
                            <?php while($clinic = mysqli_fetch_assoc($clinics_query)): 
                                $has_applied = in_array($clinic['id'], $applied_clinics);
                            ?>
                                <div class="dropdown-item" 
                                     data-id="<?php echo $clinic['id']; ?>" 
                                     data-name="<?php echo htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                     data-city="<?php echo htmlspecialchars($clinic['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                     data-province="<?php echo htmlspecialchars($clinic['province'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                     data-has-applied="<?php echo $has_applied ? 'true' : 'false'; ?>"
                                     onclick="selectClinic(this)"
                                     style="<?php echo $has_applied ? 'opacity:0.5;cursor:not-allowed;background:var(--border-light);' : ''; ?>">
                                    <strong><?php echo htmlspecialchars($clinic['name']); ?></strong>
                                    <?php if (!empty($clinic['city']) || !empty($clinic['province'])): ?>
                                        <span style="font-size:12px;color:var(--text-muted);display:block;">
                                            <?php echo htmlspecialchars($clinic['city']); ?><?php echo (!empty($clinic['city']) && !empty($clinic['province'])) ? ', ' : ''; ?><?php echo htmlspecialchars($clinic['province']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($has_applied): ?>
                                        <span style="font-size:11px;color:var(--warning);font-weight:600;">
                                            <i class="fas fa-clock"></i> Already applied
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="dropdown-item" style="cursor:default;color:var(--text-muted);">
                                <i class="fas fa-info-circle"></i> No clinics available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <input type="hidden" name="clinic_id" id="selectedClinicId" value="0" required>
                <div id="clinicSelectionDisplay" style="display:none;margin-top:8px;padding:10px;background:var(--primary-light);border-radius:var(--radius-md);border:1px solid var(--primary);">
                    <i class="fas fa-check-circle" style="color:var(--primary);"></i>
                    Selected: <strong id="selectedClinicDisplay">None</strong>
                    <button type="button" onclick="clearClinicSelection()" style="float:right;background:none;border:none;color:var(--danger);cursor:pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i>
                    Your verification will be specific to this clinic only. You can apply to multiple clinics.
                </div>
            </div>
            <button type="button" class="btn-submit-verify" onclick="goToStep(2)" disabled id="step1Next">
                <i class="fas fa-arrow-right"></i> Next
            </button>
        </div>
        
        <!-- STEP 2: Select Type -->
        <div id="step2" class="form-step" style="display:none;">
            <div class="form-group">
                <label><i class="fas fa-tag"></i> Verification Type <span class="text-danger">*</span></label>
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
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn-cancel-verify" onclick="goToStep(1)" style="flex:1;min-width:120px;">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn-submit-verify" onclick="goToStep(3)" disabled id="step2Next" style="flex:2;min-width:120px;">
                    Next <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- STEP 3: ID Details -->
        <div id="step3" class="form-step" style="display:none;">
            <div class="form-group">
                <label id="idNumberLabel"><i class="fas fa-id-card"></i> ID Number <span class="text-danger">*</span></label>
                <input type="text" 
                       name="pwd_senior_id_number" 
                       class="form-control" 
                       id="idNumberInput"
                       placeholder="Enter your ID number" 
                       required>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i>
                    Enter the ID number exactly as it appears on your card.
                </div>
            </div>
            
            <!-- ✅ NEW: Date Issued -->
            <div class="form-group">
                <label><i class="fas fa-calendar-alt"></i> Date Issued <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="date_issued" id="dateIssued" required>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i> The date when your ID was issued.
                </div>
            </div>
            
            <!-- ✅ NEW: Valid Until -->
            <div class="form-group">
                <label><i class="fas fa-calendar-check"></i> Valid Until <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="valid_until" id="validUntil" required>
                <div class="pwd-helper-text">
                    <i class="fas fa-info-circle"></i> The expiration date of your ID.
                </div>
            </div>
            
            <!-- Auto-calculate validity period -->
            <div id="validityInfo" style="display:none;margin-top:10px;padding:12px;background:var(--bg-primary);border-radius:var(--radius-md);border-left:4px solid var(--primary);">
                <strong><i class="fas fa-clock"></i> Validity Period:</strong>
                <span id="validityDisplay"></span>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-upload"></i> Upload Valid ID <span class="text-danger">*</span></label>
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
            
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn-cancel-verify" onclick="goToStep(2)" style="flex:1;min-width:120px;">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="button" class="btn-submit-verify" onclick="goToStep(4)" disabled id="step3Next" style="flex:2;min-width:120px;">
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
                    <span class="review-label"><i class="fas fa-calendar-alt"></i> Date Issued</span>
                    <span class="review-value" id="reviewDateIssued">-</span>
                </div>
                <div class="review-row">
                    <span class="review-label"><i class="fas fa-calendar-check"></i> Valid Until</span>
                    <span class="review-value" id="reviewValidUntil">-</span>
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
                Please review your application before submitting. Once submitted, it cannot be edited.
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
// ============================================
// PWD/SENIOR FORM SCRIPTS
// ============================================

// State variables
let selectedClinicId = null;
let selectedClinicName = null;
let selectedType = null;

// ============================================
// CLINIC DROPDOWN FUNCTIONS
// ============================================

function filterClinics(search) {
    const dropdown = document.getElementById('clinicDropdown');
    const items = dropdown.querySelectorAll('.dropdown-item');
    const searchLower = search.toLowerCase().trim();
    
    dropdown.classList.add('show');
    
    items.forEach(item => {
        const name = item.getAttribute('data-name').toLowerCase();
        const hasApplied = item.getAttribute('data-has-applied') === 'true';
        
        if (name.includes(searchLower) && !hasApplied) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

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
    
    // Show selected clinic
    document.getElementById('selectedClinicDisplay').textContent = name;
    document.getElementById('clinicSelectionDisplay').style.display = 'block';
    
    // Update review
    document.getElementById('reviewClinic').textContent = name;
}

function clearClinicSelection() {
    selectedClinicId = null;
    selectedClinicName = null;
    document.getElementById('selectedClinicId').value = 0;
    document.getElementById('clinicSearch').value = '';
    document.getElementById('clinicDropdown').classList.remove('show');
    document.getElementById('clinicSelectionDisplay').style.display = 'none';
    document.getElementById('step1Next').disabled = true;
    document.getElementById('reviewClinic').textContent = '-';
}

// ============================================
// TYPE SELECTION FUNCTIONS
// ============================================

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
    const labelText = type === 'senior' ? 'Senior Citizen' : 'PWD';
    document.getElementById('idNumberLabel').innerHTML = 
        `<i class="fas fa-id-card"></i> ${labelText} ID Number <span class="text-danger">*</span>`;
    document.getElementById('idNumberInput').placeholder = 
        `Enter ${labelText} ID number`;
    
    // Update review
    document.getElementById('reviewType').textContent = labelText;
}

// ============================================
// STEP NAVIGATION
// ============================================

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
        document.getElementById('reviewDateIssued').textContent = 
            document.getElementById('dateIssued').value ? formatDateDisplay(document.getElementById('dateIssued').value) : '-';
        document.getElementById('reviewValidUntil').textContent = 
            document.getElementById('validUntil').value ? formatDateDisplay(document.getElementById('validUntil').value) : '-';
    }
    
    // Scroll to form
    const form = document.getElementById('pwdSeniorForm');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ============================================
// VALIDITY PERIOD AUTO-CALCULATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    const dateIssued = document.getElementById('dateIssued');
    const validUntil = document.getElementById('validUntil');
    const validityInfo = document.getElementById('validityInfo');
    const validityDisplay = document.getElementById('validityDisplay');
    const step3Next = document.getElementById('step3Next');
    
    function calculateValidity() {
        if (dateIssued.value && validUntil.value) {
            const issued = new Date(dateIssued.value + 'T00:00:00');
            const valid = new Date(validUntil.value + 'T00:00:00');
            
            // Reset validation
            validUntil.style.borderColor = '';
            
            if (valid < issued) {
                validityDisplay.innerHTML = '<span style="color:var(--danger);">⚠️ Valid Until must be after Date Issued</span>';
                validityInfo.style.display = 'block';
                validUntil.style.borderColor = 'var(--danger)';
                step3Next.disabled = true;
                return;
            }
            
            step3Next.disabled = false;
            
            // Calculate difference
            const diffTime = valid - issued;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            const diffMonths = Math.floor(diffDays / 30);
            const diffYears = Math.floor(diffDays / 365);
            
            let validityText = '';
            if (diffYears > 0) {
                validityText = diffYears + ' year(s)';
                const remainingMonths = diffMonths - (diffYears * 12);
                if (remainingMonths > 0) {
                    validityText += ' and ' + remainingMonths + ' month(s)';
                }
            } else if (diffMonths > 0) {
                validityText = diffMonths + ' month(s)';
                const remainingDays = diffDays - (diffMonths * 30);
                if (remainingDays > 0) {
                    validityText += ' and ' + remainingDays + ' day(s)';
                }
            } else {
                validityText = diffDays + ' day(s)';
            }
            
            const issuedStr = formatDateDisplay(dateIssued.value);
            const validStr = formatDateDisplay(validUntil.value);
            
            validityDisplay.innerHTML = `
                <span style="color:var(--primary);">
                    <i class="fas fa-clock"></i> 
                    <strong>${validityText}</strong> (from ${issuedStr} to ${validStr})
                </span>
            `;
            validityInfo.style.display = 'block';
        } else {
            validityInfo.style.display = 'none';
            step3Next.disabled = true;
        }
    }
    
    dateIssued.addEventListener('change', function() {
        calculateValidity();
        // Update review
        document.getElementById('reviewDateIssued').textContent = 
            this.value ? formatDateDisplay(this.value) : '-';
    });
    
    validUntil.addEventListener('change', function() {
        calculateValidity();
        // Update review
        document.getElementById('reviewValidUntil').textContent = 
            this.value ? formatDateDisplay(this.value) : '-';
    });
    
    // ID Number input validation
    document.getElementById('idNumberInput').addEventListener('input', function() {
        const hasValue = this.value.trim().length > 0;
        document.getElementById('step3Next').disabled = !hasValue;
    });
    
    // File upload validation
    document.querySelector('input[name="pwd_senior_id_image"]').addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            const file = this.files[0];
            const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!validTypes.includes(file.type)) {
                showToast('Invalid file type. Allowed: JPG, PNG, PDF', 'error');
                this.value = '';
                document.getElementById('step3Next').disabled = true;
                return;
            }
            
            if (file.size > maxSize) {
                showToast('File too large. Max 5MB.', 'error');
                this.value = '';
                document.getElementById('step3Next').disabled = true;
                return;
            }
            
            document.getElementById('step3Next').disabled = false;
            showToast('File uploaded successfully: ' + file.name, 'success');
        }
    });
});

// ============================================
// FORM VALIDATION BEFORE SUBMIT
// ============================================

function validateForm() {
    const clinicId = document.getElementById('selectedClinicId').value;
    const type = document.querySelector('input[name="pwd_senior_type"]:checked');
    const idNumber = document.getElementById('idNumberInput').value.trim();
    const dateIssued = document.getElementById('dateIssued').value;
    const validUntil = document.getElementById('validUntil').value;
    const idImage = document.querySelector('input[name="pwd_senior_id_image"]').files.length;
    
    if (!clinicId || clinicId == 0) {
        showToast('Please select a clinic.', 'error');
        return false;
    }
    
    if (!type) {
        showToast('Please select verification type (PWD or Senior).', 'error');
        return false;
    }
    
    if (!idNumber) {
        showToast('Please enter your ID number.', 'error');
        return false;
    }
    
    if (!dateIssued) {
        showToast('Please select the date issued.', 'error');
        return false;
    }
    
    if (!validUntil) {
        showToast('Please select the valid until date.', 'error');
        return false;
    }
    
    if (new Date(validUntil) < new Date(dateIssued)) {
        showToast('Valid Until must be after Date Issued.', 'error');
        return false;
    }
    
    if (idImage === 0) {
        showToast('Please upload your valid ID image.', 'error');
        return false;
    }
    
    // Show loading
    showLoading();
    return true;
}

// ============================================
// CLOSE DROPDOWN WHEN CLICKING OUTSIDE
// ============================================

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('clinicDropdown');
    const search = document.getElementById('clinicSearch');
    if (search && dropdown && !search.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// ============================================
// HANDLE ENTER KEY IN SEARCH
// ============================================

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

// ============================================
// TOAST NOTIFICATION
// ============================================

function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) {
        // Fallback: use alert if toast container not found
        alert(message);
        return;
    }
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    
    let icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    if (type === 'info') icon = 'info-circle';
    if (type === 'warning') icon = 'exclamation-triangle';
    
    toast.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// LOADING OVERLAY
// ============================================

function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = 'flex';
    }
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}
</script>