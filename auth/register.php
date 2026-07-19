<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Eyecore - Clinic Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root {
            --teal:        #0d9488;
            --teal-dark:   #0f766e;
            --teal-light:  #ccfbf1;
            --teal-xlight: #f0fdfa;
            --slate:       #1e293b;
            --muted:       #64748b;
            --border:      #e2e8f0;
            --danger:      #ef4444;
            --warning:     #f59e0b;
            --success:     #22c55e;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #f0fdfa 0%, #eff6ff 50%, #fdf4ff 100%);
            min-height: 100vh;
        }

        /* ── Card ── */
        .reg-card {
            max-width: 520px;
            margin: 2rem auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(13,148,136,.10), 0 4px 16px rgba(0,0,0,.06);
            padding: 2.4rem 2.2rem;
            position: relative;
            overflow: hidden;
        }
        .reg-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), #0891b2, #7c3aed);
            border-radius: 20px 20px 0 0;
        }

        /* ── Brand ── */
        .brand-logo {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, var(--teal), #0891b2);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 8px 24px rgba(13,148,136,.3);
        }

        /* ── Step Indicator ── */
        .step-track {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 2rem;
        }
        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            position: relative;
            z-index: 1;
        }
        .step-circle {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: var(--border);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: .85rem;
            transition: all .3s ease;
            border: 2px solid transparent;
        }
        .step-circle.active {
            background: var(--teal);
            color: white;
            box-shadow: 0 4px 14px rgba(13,148,136,.4);
            border-color: var(--teal);
        }
        .step-circle.done {
            background: var(--teal-light);
            color: var(--teal);
            border-color: var(--teal);
        }
        .step-label {
            font-size: .65rem;
            font-weight: 600;
            color: var(--muted);
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .step-label.active { color: var(--teal); }
        .step-connector {
            width: 60px; height: 2px;
            background: var(--border);
            margin: 0 4px;
            margin-bottom: 20px;
            transition: background .3s;
            flex-shrink: 0;
        }
        .step-connector.done { background: var(--teal); }

        /* ── Step panels ── */
        .step-panel { display: none; }
        .step-panel.active { display: block; animation: fadeIn .25s ease; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

        /* ── Form controls ── */
        .form-label {
            font-size: .82rem;
            font-weight: 600;
            color: var(--slate);
            margin-bottom: .4rem;
        }
        .form-control, .form-select {
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: .55rem .85rem;
            font-size: .9rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: all .2s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,148,136,.12);
        }
        .input-group .form-control { border-right: none; }
        .input-group .btn-outline-secondary {
            border: 1.5px solid var(--border);
            border-left: none;
            border-radius: 0 10px 10px 0;
            color: var(--muted);
        }
        .input-group .btn-outline-secondary:hover { background: var(--teal-xlight); color: var(--teal); }

        /* ── Buttons ── */
        .btn-teal {
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: .9rem;
            padding: .6rem 1.4rem;
            transition: all .2s;
            letter-spacing: .01em;
        }
        .btn-teal:hover {
            background: linear-gradient(135deg, var(--teal-dark), #115e59);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(13,148,136,.3);
        }
        .btn-teal:disabled { opacity: .6; transform: none; }
        .btn-back {
            background: #f8fafc;
            color: var(--muted);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-weight: 600;
            font-size: .9rem;
            padding: .6rem 1.4rem;
        }
        .btn-back:hover { background: var(--border); color: var(--slate); }

        /* ── Location button ── */
        .btn-location {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: .8rem;
            font-weight: 700;
            color: var(--teal);
            background: var(--teal-xlight);
            border: 1.5px solid var(--teal-light);
            border-radius: 8px;
            padding: 6px 14px;
            cursor: pointer;
            transition: all .2s;
            margin-top: 6px;
        }
        .btn-location:hover {
            background: var(--teal);
            color: white;
            border-color: var(--teal);
        }
        .btn-location i { font-size: 1rem; }
        .btn-location.loading { opacity: .7; pointer-events: none; }
        .location-status {
            font-size: .75rem;
            margin-top: 4px;
            display: none;
        }
        .location-status.success { color: var(--success); display: block; }
        .location-status.error   { color: var(--danger);  display: block; }

        /* ── Password strength ── */
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            margin-top: 6px;
            transition: all .3s;
            width: 0;
        }
        .strength-bar.weak       { width:25%; background:var(--danger); }
        .strength-bar.medium     { width:50%; background:var(--warning); }
        .strength-bar.strong     { width:75%; background:#3b82f6; }
        .strength-bar.very-strong{ width:100%; background:var(--success); }

        .pw-req {
            font-size: .75rem;
            color: var(--muted);
            margin-top: 6px;
            line-height: 1.6;
        }
        .pw-req li { list-style: none; padding-left: 0; }
        .pw-req li::before { content: '○ '; color: var(--border); }
        .pw-req li.met::before { content: '✓ '; color: var(--success); }
        .pw-req li.met { color: var(--success); }

        /* ── OTP input ── */
        .otp-wrapper {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 1.5rem 0;
        }
        .otp-box {
            width: 52px; height: 60px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            border: 2px solid var(--border);
            border-radius: 12px;
            outline: none;
            transition: all .2s;
            caret-color: var(--teal);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .otp-box:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,148,136,.15);
            transform: scale(1.05);
        }
        .otp-box.filled {
            border-color: var(--teal);
            background: var(--teal-xlight);
            color: var(--teal);
        }
        .otp-box.error-shake {
            border-color: var(--danger);
            animation: shake .4s ease;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)}
            20%{transform:translateX(-6px)}
            40%{transform:translateX(6px)}
            60%{transform:translateX(-4px)}
            80%{transform:translateX(4px)}
        }

        .otp-email-display {
            background: var(--teal-xlight);
            border: 1px solid var(--teal-light);
            border-radius: 10px;
            padding: .7rem 1rem;
            font-size: .85rem;
            color: var(--teal-dark);
            font-weight: 600;
            text-align: center;
            margin-bottom: .5rem;
        }
        .otp-timer {
            font-size: .8rem;
            color: var(--muted);
            text-align: center;
            margin-top: .5rem;
        }
        .otp-timer span { font-weight: 700; color: var(--slate); }
        .otp-timer.expiring span { color: var(--danger); }

        .resend-btn {
            background: none;
            border: none;
            color: var(--teal);
            font-weight: 700;
            font-size: .82rem;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
            transition: color .2s;
        }
        .resend-btn:disabled { color: var(--muted); text-decoration: none; cursor: not-allowed; }
        .resend-btn:hover:not(:disabled) { color: var(--teal-dark); }

        /* ── Section header ── */
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.2rem;
        }
        .section-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--teal-xlight);
            display: flex; align-items: center; justify-content: center;
            color: var(--teal);
            font-size: 1rem;
            flex-shrink: 0;
        }
        .section-title { font-weight: 800; font-size: 1rem; color: var(--slate); margin: 0; }
        .section-sub   { font-size: .75rem; color: var(--muted); margin: 0; }

        /* ── Login link ── */
        .login-opt {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            font-size: .85rem;
            color: var(--muted);
        }
        .login-opt a { color: var(--teal); font-weight: 700; text-decoration: none; }
        .login-opt a:hover { text-decoration: underline; }

        /* ── Map ── */
        #clinicMap {
            width: 100%;
            height: 220px;
            border-radius: 12px;
            margin-top: 10px;
            border: 1.5px solid var(--border);
            display: none;
        }
        #clinicMap.show { display: block; }
        .province-lock {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .72rem;
            font-weight: 700;
            color: var(--teal-dark);
            background: var(--teal-xlight);
            border: 1px solid var(--teal-light);
            border-radius: 6px;
            padding: 3px 9px;
            margin-left: 6px;
        }
        .barangay-hint {
            font-size: .72rem;
            color: var(--muted);
            margin-top: 4px;
        }

        /* ── Hospital fields ── */
        #hospitalFields {
            background: var(--teal-xlight);
            border: 1px solid var(--teal-light);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* ── Terms link ── */
        .terms-link { color: var(--teal); font-weight: 600; }

        /* ── Responsive ── */
        @media (max-width: 576px) {
            .reg-card { margin: 1rem; padding: 1.6rem 1.2rem; }
            .otp-box  { width: 44px; height: 52px; font-size: 1.2rem; }
            .step-connector { width: 36px; }
        }
    </style>
</head>
<body>
<div class="container py-4">
<div class="reg-card">

    <!-- Brand -->
    <div class="text-center mb-3">
        <div class="brand-logo">
            <i class="bi bi-eye-fill text-white fs-4"></i>
        </div>
        <h4 class="fw-800 mb-0" style="color:var(--slate);font-weight:800;">Eyecore Clinic Registration</h4>
        <p class="text-muted small mb-0">Complete your registration in 3 simple steps</p>
    </div>

    <!-- Step Indicator -->
    <div class="step-track">
        <div class="step-node">
            <div class="step-circle active" id="circle1">1</div>
            <div class="step-label active" id="label1">Account</div>
        </div>
        <div class="step-connector" id="conn1"></div>
        <div class="step-node">
            <div class="step-circle" id="circle2">2</div>
            <div class="step-label" id="label2">Clinic</div>
        </div>
        <div class="step-connector" id="conn2"></div>
        <div class="step-node">
            <div class="step-circle" id="circle3"><i class="bi bi-shield-lock-fill" style="font-size:.75rem;"></i></div>
            <div class="step-label" id="label3">Verify</div>
        </div>
    </div>

    <form id="registrationForm" method="POST" action="../api/process_registration.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="latitude"  id="lat_field">
        <input type="hidden" name="longitude" id="lng_field">
        <input type="hidden" name="otp_verified" id="otp_verified_field" value="0">

        <!-- ═══════════════════════════════════════════
             STEP 1 — User / Account Info
        ═══════════════════════════════════════════ -->
        <div class="step-panel active" id="step1">
            <div class="section-header">
                <div class="section-icon"><i class="bi bi-person-fill"></i></div>
                <div>
                    <p class="section-title">Your Account</p>
                    <p class="section-sub">Admin credentials for your clinic</p>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" required maxlength="50" placeholder="Juan">
                </div>
                <div class="col-6">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" required maxlength="50" placeholder="Dela Cruz">
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" name="email" id="reg_email" class="form-control" required
                       placeholder="juan@example.com" autocomplete="email">
                <div class="form-text" style="font-size:.75rem;">
                    <i class="bi bi-info-circle me-1"></i>OTP will be sent here for verification.
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control" required
                           autocomplete="new-password" placeholder="Min. 8 characters">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="strength-bar" id="strengthBar"></div>
                <ul class="pw-req" id="pwReqs">
                    <li id="req-len">At least 8 characters</li>
                    <li id="req-upper">One uppercase letter</li>
                    <li id="req-lower">One lowercase letter</li>
                    <li id="req-num">One number</li>
                    <li id="req-special">One special character (@$!%*?&)</li>
                </ul>
            </div>

            <div class="mt-3">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required
                           autocomplete="new-password" placeholder="Re-enter password">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('confirm_password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="invalid-feedback d-block" id="matchErr" style="display:none!important;font-size:.78rem;"></div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="button" class="btn btn-teal px-4" onclick="goStep2()">
                    Next <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             STEP 2 — Clinic Info
        ═══════════════════════════════════════════ -->
        <div class="step-panel" id="step2">
            <div class="section-header">
                <div class="section-icon"><i class="bi bi-hospital-fill"></i></div>
                <div>
                    <p class="section-title">Clinic Information</p>
                    <p class="section-sub">Details about your clinic</p>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Clinic Logo <span class="text-danger">*</span></label>
                <input type="file" name="clinic_logo" class="form-control" accept=".jpg,.jpeg,.png" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Clinic Name <span class="text-danger">*</span></label>
                <input type="text" name="clinic_name" class="form-control" required maxlength="100"
                       placeholder="e.g. 20/20 Optical Clinic">
            </div>

            <div class="mb-3">
                <label class="form-label">Clinic Type <span class="text-danger">*</span></label>
                <select name="clinic_type" id="clinic_type" class="form-select" required>
                    <option value="">-- Select Clinic Type --</option>
                    <option value="standalone">Standalone Optical Clinic</option>
                    <option value="hospital_based">Hospital-Based Optical Clinic</option>
                </select>
            </div>

            <div id="hospitalFields" style="display:none;">
                <div class="mb-3">
                    <label class="form-label">Hospital Name <span class="text-danger">*</span></label>
                    <input type="text" name="hospital_name" class="form-control" placeholder="e.g. St. Luke's Medical Center">
                </div>
                <div class="mb-3">
                    <label class="form-label">Hospital Address</label>
                    <textarea name="hospital_address" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Does this hospital offer eye surgery?</label>
                    <select name="offers_eye_surgery" class="form-select">
                        <option value="no">No</option>
                        <option value="yes">Yes</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Clinic Email <span class="text-danger">*</span></label>
                <input type="email" name="clinic_email" class="form-control" required
                       placeholder="clinic@example.com" autocomplete="email">
            </div>

            <div class="mb-3">
                <label class="form-label">Branch <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="branch" class="form-control" placeholder="e.g. Main Branch" maxlength="50">
            </div>

            <!-- ── Address + Location ── -->
            <div class="mb-2">
                <label class="form-label">
                    Clinic Location <span class="text-danger">*</span>
                    <span class="province-lock"><i class="bi bi-lock-fill"></i> Cavite Province Only</span>
                </label>
                <div>
                    <button type="button" class="btn-location" id="useLocationBtn" onclick="getCurrentLocation()">
                        <i class="bi bi-geo-alt-fill"></i>
                        Use Current Location
                    </button>
                </div>
                <div class="location-status" id="locationStatus"></div>
                <div id="clinicMap"></div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Unit / Floor <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="unit_floor" id="unit_floor" class="form-control"
                           placeholder="e.g. Unit 4, 2nd Floor" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Building Name <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="building_name" id="building_name" class="form-control"
                           placeholder="e.g. Robinson's Place" maxlength="100">
                </div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-8">
                    <label class="form-label">Street Address <span class="text-danger">*</span></label>
                    <input type="text" name="street_address" id="street_address" class="form-control" required
                           placeholder="e.g. 123 Rizal St.">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Landmark <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="landmark" id="landmark" class="form-control"
                           placeholder="e.g. Near town plaza" maxlength="100">
                </div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Barangay <span class="text-danger">*</span></label>
                    <input type="text" name="barangay" id="barangay" class="form-control" required
                           placeholder="e.g. Barangay San Agustin">
                    <div class="barangay-hint" id="barangayHint"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">City / Municipality <span class="text-danger">*</span></label>
                    <select name="city" id="city" class="form-select" required>
                        <option value="">-- Select City --</option>
                        <option value="Cavite City">Cavite City</option>
                        <option value="Bacoor">Bacoor</option>
                        <option value="Imus">Imus</option>
                        <option value="Dasmariñas">Dasmariñas</option>
                        <option value="Tagaytay">Tagaytay</option>
                        <option value="General Trias">General Trias</option>
                        <option value="Kawit">Kawit</option>
                        <option value="Tanza">Tanza</option>
                        <option value="Trece Martires">Trece Martires</option>
                        <option value="Naic">Naic</option>
                        <option value="Silang">Silang</option>
                        <option value="Amadeo">Amadeo</option>
                        <option value="Alfonso">Alfonso</option>
                        <option value="Carmona">Carmona</option>
                        <option value="Gen. Mariano Alvarez">Gen. Mariano Alvarez</option>
                        <option value="Magallanes">Magallanes</option>
                        <option value="Maragondon">Maragondon</option>
                        <option value="Mendez">Mendez</option>
                        <option value="Ternate">Ternate</option>
                    </select>
                    <!-- All options above are Cavite municipalities/cities only — the dropdown itself enforces the province lock for typed selections. -->
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Province <span class="text-danger">*</span></label>
                    <input type="text" id="province_display" class="form-control" value="Cavite" readonly
                           style="background:var(--teal-xlight);color:var(--teal-dark);font-weight:700;">
                </div>
                <div class="col-md-6">
                    <label class="form-label">ZIP Code <span class="text-danger">*</span></label>
                    <input type="text" name="zip_code" id="zip_code" class="form-control" required
                           inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 4114">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input type="tel" name="contact" class="form-control" required
                       pattern="[0-9\-\+\(\)\s]{10,20}"
                       placeholder="09XX-XXX-XXXX">
            </div>

            <input type="hidden" name="province" id="province_field" value="Cavite">
            <input type="hidden" name="location_confirmed_cavite" id="cavite_confirmed_field" value="0">

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="terms" required
                       style="border-color:var(--teal);">
                <label class="form-check-label" for="terms" style="font-size:.85rem;">
                    I agree to the
                    <a href="#" class="terms-link" onclick="showLegal(event,'terms')">Terms & Conditions</a> and
                    <a href="#" class="terms-link" onclick="showLegal(event,'privacy')">Privacy Policy</a>
                </label>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <button type="button" class="btn btn-back" onclick="setStep(1)">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </button>
                <button type="button" class="btn btn-teal px-4" onclick="goStep3()">
                    Next <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             STEP 3 — OTP Verification
        ═══════════════════════════════════════════ -->
        <div class="step-panel" id="step3">
            <div class="section-header">
                <div class="section-icon"><i class="bi bi-shield-lock-fill"></i></div>
                <div>
                    <p class="section-title">Email Verification</p>
                    <p class="section-sub">Enter the 6-digit code we sent you</p>
                </div>
            </div>

            <div class="otp-email-display" id="otpEmailDisplay">
                <i class="bi bi-envelope-fill me-2"></i>
                <span id="otpEmailText">your email</span>
            </div>

            <p class="text-center text-muted" style="font-size:.82rem;">
                Enter the 6-digit OTP sent to your email address.<br>
                <strong>Valid for 5 minutes.</strong>
            </p>

            <!-- 6 OTP boxes -->
            <div class="otp-wrapper" id="otpWrapper">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp0">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp1">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp2">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp3">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp4">
                <input class="otp-box" type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp5">
            </div>

            <input type="hidden" name="otp_code" id="otp_hidden">

            <div class="otp-timer" id="otpTimer">
                Code expires in <span id="timerCount">5:00</span>
            </div>
            <div class="text-center mt-2" style="font-size:.82rem;">
                Didn't receive it?
                <button type="button" class="resend-btn" id="resendBtn" disabled onclick="resendOtp()">
                    Resend OTP
                </button>
                <span id="resendCountdown" style="font-size:.78rem;color:var(--muted);"></span>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <button type="button" class="btn btn-back" onclick="setStep(2)">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </button>
                <button type="button" class="btn btn-teal px-4" id="verifyBtn" onclick="verifyOtp()" disabled>
                    <i class="bi bi-shield-check me-1"></i> Verify & Submit
                </button>
            </div>
        </div>

    </form><!-- /form -->

    <div class="login-opt">
        Already have an account?
        <a href="../admin/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Log in here</a>
    </div>

</div><!-- /reg-card -->
</div><!-- /container -->

<!-- JS Libraries -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
/* ═══════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════ */
let currentStep = 1;
let otpSent     = false;
let timerIntvl  = null;
let resendIntvl = null;
let generatedOtp = '';   // stored after sending

/* ═══════════════════════════════════════════════
   STEP NAVIGATION
═══════════════════════════════════════════════ */
function setStep(n) {
    document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    currentStep = n;
    updateIndicator(n);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateIndicator(n) {
    for (let i = 1; i <= 3; i++) {
        const circle = document.getElementById('circle' + i);
        const label  = document.getElementById('label'  + i);
        circle.classList.remove('active','done');
        label.classList.remove('active');
        if (i < n) {
            circle.classList.add('done');
            if (i < 3) circle.innerHTML = '<i class="bi bi-check-lg" style="font-size:.8rem;"></i>';
        } else if (i === n) {
            circle.classList.add('active');
            label.classList.add('active');
            if (i === 3) circle.innerHTML = '<i class="bi bi-shield-lock-fill" style="font-size:.75rem;"></i>';
            else circle.innerHTML = i;
        } else {
            if (i === 3) circle.innerHTML = '<i class="bi bi-shield-lock-fill" style="font-size:.75rem;"></i>';
            else circle.innerHTML = i;
        }
    }
    // connectors
    document.getElementById('conn1').classList.toggle('done', n > 1);
    document.getElementById('conn2').classList.toggle('done', n > 2);
}

/* ═══════════════════════════════════════════════
   STEP 1 → STEP 2
═══════════════════════════════════════════════ */
function goStep2() {
    const firstName = document.querySelector('[name="first_name"]').value.trim();
    const lastName  = document.querySelector('[name="last_name"]').value.trim();
    const email     = document.getElementById('reg_email').value.trim();
    const password  = document.getElementById('password').value;
    const confirm   = document.getElementById('confirm_password').value;
    const emailRx   = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const pwRx      = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;

    if (!firstName || !lastName) return toast('error','Incomplete','Please enter your full name.');
    if (!emailRx.test(email))    return toast('error','Invalid Email','Enter a valid email address.');
    if (!pwRx.test(password))    return toast('error','Weak Password',
        'Password needs 8+ chars, uppercase, lowercase, number & special character.');
    if (password !== confirm)    return toast('error','Mismatch','Passwords do not match.');

    setStep(2);
}

/* ═══════════════════════════════════════════════
   STEP 2 → STEP 3 (send OTP)
═══════════════════════════════════════════════ */
async function goStep3() {
    // Validate Step 2 fields
    const clinicName    = document.querySelector('[name="clinic_name"]').value.trim();
    const clinicEmail   = document.querySelector('[name="clinic_email"]').value.trim();
    const streetAddress = document.getElementById('street_address').value.trim();
    const barangay      = document.getElementById('barangay').value.trim();
    const cityVal       = document.getElementById('city').value;
    const contact       = document.querySelector('[name="contact"]').value.trim();
    const logo          = document.querySelector('[name="clinic_logo"]').files[0];
    const clinicType    = document.getElementById('clinic_type').value;
    const terms         = document.getElementById('terms').checked;
    const emailRx       = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phoneRx       = /^[0-9\-\+\(\)\s]{10,20}$/;
    const caviteConfirmed = document.getElementById('cavite_confirmed_field').value === '1';

    if (!logo)                       return toast('error','Missing Logo','Please upload a clinic logo.');
    if (!clinicName)                 return toast('error','Missing','Please enter the clinic name.');
    if (!clinicType)                 return toast('error','Missing','Please select clinic type.');
    if (!emailRx.test(clinicEmail))  return toast('error','Invalid Email','Enter a valid clinic email.');
    if (!streetAddress)              return toast('error','Missing','Please enter the street address.');
    if (!barangay)                   return toast('error','Missing','Please enter the barangay.');
    if (!cityVal)                    return toast('error','Missing','Please select your city/municipality.');
    if (!/^\d{4}$/.test(document.getElementById('zip_code').value.trim()))
                                      return toast('error','Invalid ZIP','Enter a valid 4-digit ZIP code.');
    if (!phoneRx.test(contact))      return toast('error','Invalid Contact','Enter a valid phone number.');
    if (!terms)                      return toast('error','Terms Required','Please agree to the Terms & Conditions.');
    if (!caviteConfirmed) return toast('error','Location Required',
        'Please tap "Use Current Location" and confirm your pin is within Cavite before continuing.');

    if (clinicType === 'hospital_based') {
        const hospName = document.querySelector('[name="hospital_name"]').value.trim();
        if (!hospName) return toast('error','Missing','Please enter the hospital name.');
    }

    // Set OTP email display
    const email = document.getElementById('reg_email').value.trim();
    document.getElementById('otpEmailText').textContent = email;

    // Send OTP via AJAX
    const sendBtn = document.querySelector('#step2 .btn-teal');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending OTP...';

    try {
        const resp = await fetch('../api/send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' })
        });
        const data = await resp.json();

        if (data.success) {
            otpSent = true;
            setStep(3);
            startOtpTimer(300); // 5 minutes
            startResendCooldown(60);
            setTimeout(() => document.getElementById('otp0').focus(), 300);
        } else {
            toast('error', 'Failed to Send OTP', data.message || 'Please try again.');
        }
    } catch (e) {
        toast('error', 'Network Error', 'Could not send OTP. Please check your connection.');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = 'Next <i class="bi bi-arrow-right ms-1"></i>';
    }
}

/* ═══════════════════════════════════════════════
   OTP TIMER
═══════════════════════════════════════════════ */
function startOtpTimer(seconds) {
    clearInterval(timerIntvl);
    const el = document.getElementById('timerCount');
    const wrap = document.getElementById('otpTimer');

    timerIntvl = setInterval(() => {
        if (seconds <= 0) {
            clearInterval(timerIntvl);
            el.textContent = 'Expired';
            wrap.classList.add('expiring');
            return;
        }
        seconds--;
        if (seconds <= 60) wrap.classList.add('expiring');
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        el.textContent = `${m}:${s.toString().padStart(2,'0')}`;
    }, 1000);
}

function startResendCooldown(seconds) {
    clearInterval(resendIntvl);
    const btn = document.getElementById('resendBtn');
    const cd  = document.getElementById('resendCountdown');
    btn.disabled = true;

    resendIntvl = setInterval(() => {
        if (seconds <= 0) {
            clearInterval(resendIntvl);
            btn.disabled = false;
            cd.textContent = '';
            return;
        }
        seconds--;
        cd.textContent = `(${seconds}s)`;
    }, 1000);
}

async function resendOtp() {
    const email = document.getElementById('reg_email').value.trim();
    const btn   = document.getElementById('resendBtn');
    btn.disabled = true;

    try {
        const resp = await fetch('../api/send_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' })
        });
        const data = await resp.json();
        if (data.success) {
            clearOtpBoxes();
            startOtpTimer(300);
            startResendCooldown(60);
            toast('success','OTP Sent!','A new OTP has been sent to your email.');
        } else {
            toast('error','Failed','Could not resend OTP.');
            btn.disabled = false;
        }
    } catch (e) {
        toast('error','Error','Network error. Please try again.');
        btn.disabled = false;
    }
}

/* ═══════════════════════════════════════════════
   OTP BOX LOGIC
═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    const boxes = document.querySelectorAll('.otp-box');

    boxes.forEach((box, idx) => {
        box.addEventListener('input', (e) => {
            const val = e.target.value.replace(/\D/g,'');
            e.target.value = val;

            if (val) {
                e.target.classList.add('filled');
                if (idx < 5) boxes[idx + 1].focus();
            } else {
                e.target.classList.remove('filled');
            }

            checkOtpComplete();
        });

        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                boxes[idx - 1].focus();
                boxes[idx - 1].value = '';
                boxes[idx - 1].classList.remove('filled');
            }
        });

        box.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = e.clipboardData.getData('text').replace(/\D/g,'').slice(0,6);
            boxes.forEach((b, i) => {
                b.value = pasted[i] || '';
                if (b.value) b.classList.add('filled');
                else b.classList.remove('filled');
            });
            checkOtpComplete();
            if (pasted.length > 0) boxes[Math.min(pasted.length, 5)].focus();
        });
    });
});

function getOtpValue() {
    return Array.from(document.querySelectorAll('.otp-box'))
                .map(b => b.value).join('');
}

function clearOtpBoxes() {
    document.querySelectorAll('.otp-box').forEach(b => {
        b.value = '';
        b.classList.remove('filled','error-shake');
    });
    document.getElementById('verifyBtn').disabled = true;
}

function checkOtpComplete() {
    const otp = getOtpValue();
    const btn = document.getElementById('verifyBtn');
    btn.disabled = (otp.length < 6);
    document.getElementById('otp_hidden').value = otp;
}

function shakeOtpBoxes() {
    document.querySelectorAll('.otp-box').forEach(b => {
        b.classList.add('error-shake');
        b.classList.remove('filled');
        setTimeout(() => b.classList.remove('error-shake'), 500);
    });
}

/* ═══════════════════════════════════════════════
   VERIFY OTP & SUBMIT
═══════════════════════════════════════════════ */
async function verifyOtp() {
    const otp   = getOtpValue();
    const email = document.getElementById('reg_email').value.trim();

    if (otp.length < 6) return;

    const btn = document.getElementById('verifyBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

    try {
        const resp = await fetch('../api/verify_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, otp, csrf_token: '<?php echo $_SESSION['csrf_token']; ?>' })
        });
        const data = await resp.json();

        if (data.success) {
            // OTP verified — mark and submit the main form
            document.getElementById('otp_verified_field').value = '1';
            clearInterval(timerIntvl);

            Swal.fire({
                icon: 'success',
                title: 'Email Verified!',
                text: 'Submitting your registration...',
                timer: 1800,
                showConfirmButton: false,
                allowOutsideClick: false
            }).then(() => {
                document.getElementById('registrationForm').submit();
            });
        } else {
            shakeOtpBoxes();
            toast('error', 'Invalid OTP', data.message || 'Wrong or expired OTP. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-check me-1"></i> Verify & Submit';
        }
    } catch (e) {
        toast('error', 'Network Error', 'Could not verify OTP.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-shield-check me-1"></i> Verify & Submit';
    }
}

/* ═══════════════════════════════════════════════
   MAP — Leaflet instance (created lazily on first fix)
═══════════════════════════════════════════════ */
let clinicMap = null;
let clinicMarker = null;

function ensureMap(lat, lng) {
    const mapDiv = document.getElementById('clinicMap');
    mapDiv.classList.add('show');

    if (!clinicMap) {
        clinicMap = L.map('clinicMap').setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(clinicMap);

        clinicMarker = L.marker([lat, lng], { draggable: true }).addTo(clinicMap);
        clinicMarker.on('dragend', () => {
            const pos = clinicMarker.getLatLng();
            reverseGeocodeAndValidate(pos.lat, pos.lng, true);
        });

        // Let the user click the map to drop the pin at a new spot
        clinicMap.on('click', (e) => {
            clinicMarker.setLatLng(e.latlng);
            reverseGeocodeAndValidate(e.latlng.lat, e.latlng.lng, true);
        });
    } else {
        clinicMap.setView([lat, lng], 16);
        clinicMarker.setLatLng([lat, lng]);
    }

    setTimeout(() => clinicMap.invalidateSize(), 200);
}

/* ═══════════════════════════════════════════════
   GEOLOCATION — Auto-detect + reverse geocode + Cavite lock
═══════════════════════════════════════════════ */
function getCurrentLocation() {
    if (!navigator.geolocation) {
        return toast('error', 'Not Supported', 'Geolocation is not supported by your browser.');
    }

    const btn = document.getElementById('useLocationBtn');
    const status = document.getElementById('locationStatus');

    btn.classList.add('loading');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:14px;height:14px;border-width:2px;"></span> Getting location...';
    status.className = 'location-status';
    status.textContent = '';

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            await reverseGeocodeAndValidate(lat, lng, false);

            btn.classList.remove('loading');
            btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Refresh Location';
        },
        (err) => {
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Use Current Location';

            const msgs = {
                1: 'Location permission denied. Please enable it in your browser settings.',
                2: 'Location information is unavailable.',
                3: 'Location request timed out.'
            };
            status.className = 'location-status error';
            status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + (msgs[err.code] || 'Unknown error.');
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
}

// Shared by GPS fix, marker drag, and map click — always re-checks the Cavite boundary
async function reverseGeocodeAndValidate(lat, lng, fromMapInteraction) {
    const status = document.getElementById('locationStatus');
    const btn = document.getElementById('useLocationBtn');

    document.getElementById('lat_field').value = lat;
    document.getElementById('lng_field').value = lng;
    ensureMap(lat, lng);

    try {
        const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&zoom=18`,
            { headers: { 'Accept-Language': 'en' } }
        );
        const data = await res.json();

        if (!data || !data.address) {
            status.className = 'location-status error';
            status.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Address lookup failed. Try dropping the pin again.';
            return;
        }

        const addr = data.address;

        // ── Cavite province check ──
        // Nominatim PH results put the province in state / county / state_district
        // depending on how the area is tagged. Check all of them for "Cavite".
        const provinceFields = [addr.state, addr.county, addr.state_district].filter(Boolean);
        const isCavite = provinceFields.some(f => f.toLowerCase().includes('cavite'));

        if (!isCavite) {
            document.getElementById('cavite_confirmed_field').value = '0';
            status.className = 'location-status error';
            status.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' +
                'This pin is outside Cavite province. Registration is only open to clinics located within Cavite — please move the pin or use your current location while inside Cavite.';
            btn.style.background = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            return;
        }

        // ── Inside Cavite: auto-fill street / barangay / city / zip ──
        const road     = addr.road || addr.pedestrian || '';
        const houseNum = addr.house_number || '';
        const street    = [houseNum, road].filter(Boolean).join(' ');
        const barangay  = addr.village || addr.suburb || addr.quarter || addr.neighbourhood || '';
        const zip       = addr.postcode || '';

        // Always sync street/barangay/zip to the pin — dragging or clicking the map
        // means the user is actively telling us "this is the spot," so it should win.
        if (street)   document.getElementById('street_address').value = street;
        if (barangay) document.getElementById('barangay').value = barangay;
        if (zip)      document.getElementById('zip_code').value = zip;
        document.getElementById('barangayHint').textContent = barangay
            ? `Detected: ${barangay}` : '';

        const detectedCity =
            addr.city || addr.town || addr.municipality || addr.county || '';
        trySelectCity(detectedCity);

        document.getElementById('cavite_confirmed_field').value = '1';
        status.className = 'location-status success';
        status.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Location confirmed within Cavite. Fields auto-filled — feel free to adjust.';

        btn.style.background = 'var(--success)';
        btn.style.color = 'white';
        btn.style.borderColor = 'var(--success)';
    } catch (err) {
        status.className = 'location-status error';
        status.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Address lookup failed. Coordinates saved — try again.';
    }
}

function trySelectCity(cityName) {
    const dropdown = document.getElementById('city');
    if (!cityName) return false;

    const norm = cityName.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();

    for (let i = 0; i < dropdown.options.length; i++) {
        const opt = dropdown.options[i].value.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();
        if (!opt) continue;
        if (opt === norm || opt.includes(norm) || norm.includes(opt)) {
            dropdown.selectedIndex = i;
            return true;
        }
    }
    return false;
}

/* ═══════════════════════════════════════════════
   PASSWORD STRENGTH
═══════════════════════════════════════════════ */
document.getElementById('password').addEventListener('input', function() {
    const pw  = this.value;
    const bar = document.getElementById('strengthBar');
    const checks = {
        'req-len':     pw.length >= 8,
        'req-upper':   /[A-Z]/.test(pw),
        'req-lower':   /[a-z]/.test(pw),
        'req-num':     /[0-9]/.test(pw),
        'req-special': /[@$!%*?&]/.test(pw)
    };
    let score = Object.values(checks).filter(Boolean).length;
    Object.entries(checks).forEach(([id, met]) => {
        document.getElementById(id).classList.toggle('met', met);
    });
    bar.className = 'strength-bar ' + (['','weak','medium','strong','strong','very-strong'][score] || '');
    checkPasswordMatch();
});

document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);

function checkPasswordMatch() {
    const pw  = document.getElementById('password').value;
    const cfw = document.getElementById('confirm_password').value;
    const err = document.getElementById('matchErr');
    if (cfw && pw !== cfw) {
        err.textContent = '✗ Passwords do not match';
        err.style.display = 'block';
        err.style.color   = 'var(--danger)';
    } else if (cfw && pw === cfw) {
        err.textContent = '✓ Passwords match';
        err.style.display = 'block';
        err.style.color   = 'var(--success)';
    } else {
        err.style.display = 'none';
    }
}

/* ═══════════════════════════════════════════════
   MISC UI
═══════════════════════════════════════════════ */
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

document.getElementById('clinic_type').addEventListener('change', function() {
    document.getElementById('hospitalFields').style.display =
        this.value === 'hospital_based' ? 'block' : 'none';
});

function toast(icon, title, text) {
    Swal.fire({ icon, title, text, confirmButtonColor: '#0d9488' });
}

function showLegal(e, tab) {
    e.preventDefault();

    Swal.fire({
        title: 'Legal Information',
        html: `
            <div style="text-align:left;">
                <div style="margin-bottom:10px;display:flex;gap:8px;">
                    <button id="tTab" onclick="swLegal('terms')"
                        style="padding:5px 14px;border-radius:6px;border:1.5px solid #0d9488;
                               background:${tab==='terms' ? '#0d9488' : '#f1f5f9'};
                               color:${tab==='terms' ? 'white' : '#334155'};
                               font-weight:600;cursor:pointer;">
                        Terms & Conditions
                    </button>

                    <button id="pTab" onclick="swLegal('privacy')"
                        style="padding:5px 14px;border-radius:6px;border:1.5px solid #0d9488;
                               background:${tab==='privacy' ? '#0d9488' : '#f1f5f9'};
                               color:${tab==='privacy' ? 'white' : '#334155'};
                               font-weight:600;cursor:pointer;">
                        Privacy Policy
                    </button>
                </div>

                <div id="legalTerms" style="display:${tab==='terms' ? 'block' : 'none'}">
                    <ul style="font-size:.85rem;line-height:1.8;padding-left:18px;">
                        <li>You must provide valid, accurate, and up-to-date clinic information.</li>
                        <li>All submitted documents, licenses, and certifications must be authentic and valid.</li>
                        <li>Your clinic is responsible for all medical services, consultations, diagnoses, treatments, and patient care provided by its licensed healthcare professionals.</li>
                        <li>Eyecore serves only as a digital platform for appointment scheduling and medical records management and does not provide medical services.</li>
                        <li>Your clinic agrees to comply with all applicable healthcare laws, regulations, and the Data Privacy Act of 2012 (RA 10173).</li>
                        <li>Eyecore reserves the right to approve, reject, suspend, or terminate clinic registrations that violate these Terms and Conditions.</li>
                        <li>Misrepresentation, submission of fraudulent documents, or violation of these Terms may result in permanent account suspension or termination.</li>
                    </ul>
                </div>

                <div id="legalPrivacy" style="display:${tab==='privacy' ? 'block' : 'none'}">
                    <ul style="font-size:.85rem;line-height:1.8;padding-left:18px;">
                        <li>Clinic information and submitted documents are collected solely for registration, verification, and platform administration.</li>
                        <li>All personal and clinic information is processed in accordance with the Data Privacy Act of 2012 (RA 10173).</li>
                        <li>Your information is protected using appropriate security measures and is accessible only to authorized Eyecore personnel.</li>
                        <li>Eyecore will not disclose your information to unauthorized third parties unless required by law or with your consent.</li>
                        <li>You may request access to, correction of, or deletion of your personal data, subject to applicable legal and regulatory requirements.</li>
                    </ul>
                </div>

            </div>
        `,
        width: '620px',
        confirmButtonText: 'Got it',
        confirmButtonColor: '#0d9488'
    });
}

function swLegal(tab) {
    document.getElementById('legalTerms').style.display   = tab === 'terms'   ? 'block' : 'none';
    document.getElementById('legalPrivacy').style.display = tab === 'privacy' ? 'block' : 'none';
    document.getElementById('tTab').style.background = tab === 'terms'   ? '#0d9488' : '#f1f5f9';
    document.getElementById('pTab').style.background = tab === 'privacy' ? '#0d9488' : '#f1f5f9';
    document.getElementById('tTab').style.color = tab === 'terms'   ? 'white' : '#334155';
    document.getElementById('pTab').style.color = tab === 'privacy' ? 'white' : '#334155';
}
</script>
</body>
</html>