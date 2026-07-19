<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (!RBACHelper::hasPermission('patients_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You do not have permission to view patient records.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId  = $_SESSION['clinic_id'];
$userId    = $_SESSION['user_id'];
$userRole  = $_SESSION['role'] ?? '';

$canView    = RBACHelper::hasPermission('patients_view');
$canCreate  = RBACHelper::hasPermission('patients_create');
$canEdit    = RBACHelper::hasPermission('patients_edit');
$canDelete  = RBACHelper::hasPermission('patients_delete');
$canApprove = RBACHelper::hasPermission('patients_approve');
$canReject  = RBACHelper::hasPermission('patients_reject');

$base_url = 'http://eyecore.capstone001.com/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        /* ── TEAL DESIGN SYSTEM ── */
        :root {
            --teal:       #0d9488;
            --teal-dark:  #0f766e;
            --teal-light: #f0fdfa;
            --teal-mid:   #ccfbf1;
            --teal-border:#99f6e4;
        }

        /* All modal headers → teal */
        .modal-header {
            background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%) !important;
            color: #fff !important;
            border-bottom: none;
        }
        .modal-header .btn-close { filter: invert(1) brightness(2); }
        .modal-header .modal-title { color: #fff !important; font-weight: 700; }

        /* Teal button */
        .btn-teal {
            background: var(--teal);
            border-color: var(--teal);
            color: #fff;
            font-weight: 600;
        }
        .btn-teal:hover { background: var(--teal-dark); border-color: var(--teal-dark); color: #fff; }
        .btn-outline-teal {
            border-color: var(--teal);
            color: var(--teal);
            font-weight: 600;
        }
        .btn-outline-teal:hover { background: var(--teal); color: #fff; }

        /* Stats cards */
        .stat-icon-teal { background: var(--teal-light); color: var(--teal); border-radius: 12px; }

        /* Method cards (consent) */
        .method-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
        }
        .method-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(13,148,136,0.15);
            border-color: var(--teal);
        }

        /* OTP Modal special styling */
        #otpModal .modal-dialog { max-width: 420px; }
        #otpModal .modal-content { border-radius: 20px; overflow: hidden; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        #otpModal .modal-header { padding: 24px 28px 20px; }
        #otpModal .modal-body { padding: 28px; }
        #otpModal .modal-footer { padding: 0 28px 24px; border: none; }

        .otp-input-wrapper { position: relative; }
        .otp-input-wrapper input {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 12px;
            text-align: center;
            border: 2px solid var(--teal-border);
            border-radius: 14px;
            padding: 16px 12px;
            background: var(--teal-light);
            color: var(--teal-dark);
            transition: border-color .2s, box-shadow .2s;
        }
        .otp-input-wrapper input:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,148,136,.15);
            outline: none;
        }

        .otp-icon-ring {
            width: 72px; height: 72px; border-radius: 50%;
            background: var(--teal-mid);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
        }
        .otp-icon-ring i { font-size: 32px; color: var(--teal-dark); }

        .otp-timer { font-size: 13px; color: #94a3b8; text-align: center; margin-top: 10px; }
        .otp-timer span { color: var(--teal); font-weight: 700; }

        .otp-email-badge {
            background: var(--teal-mid);
            border: 1px solid var(--teal-border);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--teal-dark);
            text-align: center;
            margin-bottom: 20px;
            word-break: break-all;
        }

        /* Consent card */
        .consent-card {
            background: var(--teal-light);
            border: 1.5px solid var(--teal-border);
            border-radius: 14px;
        }

        .row.g-3 { display: flex; flex-wrap: wrap; }
        .row.g-3 > .col { flex: 1 1 0px; min-width: 200px; }
        @media (max-width: 768px) { .row.g-3 > .col { flex: 0 0 100%; min-width: 100%; } }
        @media (max-width: 992px) and (min-width: 769px) {
            .row.g-3 > .col { flex: 0 0 calc(50% - 1rem); min-width: calc(50% - 1rem); }
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="bi bi-people me-2" style="color:var(--teal);"></i>Patient Management</h3>
        <?php if ($canCreate): ?>
            <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#addPatientModal">
                <i class="bi bi-person-plus me-2"></i>Add Patient
            </button>
        <?php endif; ?>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><div class="rounded-3 p-3 stat-icon-teal"><i class="bi bi-people fs-2"></i></div></div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="mb-0 fw-bold" id="totalPatients">0</h2>
                            <span class="text-muted small text-uppercase fw-semibold">Total Patients</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><div class="rounded-3 p-3 stat-icon-teal"><i class="bi bi-check-circle fs-2"></i></div></div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="mb-0 fw-bold" id="activePatients">0</h2>
                            <span class="text-muted small text-uppercase fw-semibold">Active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><div class="rounded-3 p-3 stat-icon-teal"><i class="bi bi-person-badge fs-2"></i></div></div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="mb-0 fw-bold" id="withAccount">0</h2>
                            <span class="text-muted small text-uppercase fw-semibold">With Account</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><div class="rounded-3 p-3 stat-icon-teal"><i class="bi bi-heart fs-2"></i></div></div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="mb-0 fw-bold" id="specialCount">0</h2>
                            <span class="text-muted small text-uppercase fw-semibold">Senior/PWD</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0"><div class="rounded-3 p-3 stat-icon-teal"><i class="bi bi-shield-check fs-2"></i></div></div>
                        <div class="flex-grow-1 ms-3">
                            <h2 class="mb-0 fw-bold" style="color:var(--teal);" id="consentGivenCount">0</h2>
                            <span class="text-muted small text-uppercase fw-semibold">Consent Given</span>
                            <div class="small text-muted mt-1"><span id="consentRate">0%</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><input type="text" id="searchInput" class="form-control" placeholder="🔍 Search..."></div>
                <div class="col-md-3">
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="Active">Active</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="genderFilter" class="form-select">
                        <option value="">All Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary w-100" id="resetFilters">
                        <i class="bi bi-x-circle me-2"></i>Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <table id="patientsTable" class="table table-hover align-middle mb-0" style="width:100%">
        <thead class="table-light">
            <tr>
                <th>ID</th><th>Patient</th><th>Age/Type</th><th>Gender</th>
                <th>Contact</th><th>Account</th><th>Consent</th>
                <th>Status</th><th>Created</th><th class="text-end">Actions</th>
            </tr>
        </thead>
    </table>
</div>

<!-- ══════════════════════════════════════════
     ADD PATIENT MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="addPatientModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Account Type -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Account Type</label>
                    <div class="d-flex gap-4">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="accountType" id="typeWith" value="with" checked>
                            <label class="form-check-label" for="typeWith">
                                <i class="bi bi-person-badge me-1" style="color:var(--teal);"></i>With Account
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="accountType" id="typeWithout" value="without">
                            <label class="form-check-label" for="typeWithout">
                                <i class="bi bi-person me-1 text-secondary"></i>Walk-in
                            </label>
                        </div>
                    </div>
                    <!-- Age warning for With Account -->
                    <div id="ageAccountWarning" class="alert alert-warning mt-2 py-2 small d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Age Requirement:</strong> Patients must be at least <strong>18 years old</strong> to create an account.
                    </div>
                </div>

                <form id="addPatientForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" id="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" id="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Age</label>
                            <input type="number" id="age" class="form-control" min="0" max="150" oninput="checkAgeLimit()">
                            <div id="ageLimitMsg" class="text-danger small mt-1 d-none">
                                <i class="bi bi-x-circle me-1"></i>Must be at least 18 years old for account creation.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select id="gender" class="form-select">
                                <option>Male</option>
                                <option>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="tel" id="phone" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Patient Type</label>
                            <select id="patient_type" class="form-select" onchange="toggleRemarks()">
                                <option value="Regular">Regular</option>
                                <option value="Senior">Senior Citizen</option>
                                <option value="PWD">PWD</option>
                            </select>
                        </div>
                        <div class="col-md-8" id="remarksField" style="display:none;">
                            <label class="form-label">Remarks / ID Number</label>
                            <input type="text" id="remarks" class="form-control" placeholder="Enter ID number">
                        </div>
                        <!-- Email -->
                        <div class="col-12" id="emailField">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" id="email" class="form-control" autocomplete="username">
                        </div>
                        <!-- Password fields -->
                        <div class="col-md-6" id="passwordField">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" id="password" class="form-control"
                                    autocomplete="new-password" onkeyup="checkPasswordStrength()">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="progress mt-2" style="height:5px;">
                                <div class="progress-bar" id="strengthBar" style="width:0%;"></div>
                            </div>
                            <small class="text-muted" id="strengthText">Enter password</small>
                            <div class="mt-2 small">
                                <span id="charCheck"    class="text-danger"><i class="bi bi-x-circle"></i> At least 8 characters</span><br>
                                <span id="upperCheck"   class="text-danger"><i class="bi bi-x-circle"></i> At least 1 uppercase</span><br>
                                <span id="numberCheck"  class="text-danger"><i class="bi bi-x-circle"></i> At least 1 number</span><br>
                                <span id="specialCheck" class="text-danger"><i class="bi bi-x-circle"></i> At least 1 special character</span>
                            </div>
                        </div>
                        <div class="col-md-6" id="confirmPasswordField">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" id="confirm_password" class="form-control"
                                    autocomplete="new-password" onkeyup="checkPasswordMatch()">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <small id="matchMsg"></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea id="address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </form>

                <!-- Data Privacy Consent -->
                <div class="consent-card p-4 mt-4">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-shield-lock-fill fs-3 me-3" style="color:var(--teal);"></i>
                        <h5 class="mb-0 fw-bold" style="color:var(--teal-dark);">Data Privacy Consent (RA 10173)</h5>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="dataConsent" style="transform:scale(1.2);margin-right:10px;">
                        <label class="form-check-label fw-bold fs-6" for="dataConsent" style="color:var(--teal-dark);">
                            I give my consent
                        </label>
                    </div>
                    <div class="text-muted" style="padding-left:35px;">
                        <p class="mb-2"><strong>I acknowledge that:</strong></p>
                        <ul class="mb-3" style="list-style-type:disc;">
                            <li class="mb-1">My personal information will be collected, processed, and stored for medical purposes</li>
                            <li class="mb-1">My data will be kept confidential and secure in accordance with the law</li>
                            <li class="mb-1">Only authorized clinic personnel can access my records</li>
                            <li class="mb-1">I have the right to access, correct, or request deletion of my data</li>
                            <li class="mb-1">My data will not be shared with third parties without my consent</li>
                        </ul>
                        <div class="alert py-2 small" style="background:var(--teal-mid);border:1px solid var(--teal-border);color:var(--teal-dark);">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            This consent is required under the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-between align-items-center">
                        <span class="badge bg-light text-dark border"><i class="bi bi-clock-history me-1"></i>Consent Version: v1.0</span>
                        <span class="badge bg-light text-dark border"><i class="bi bi-shield-check me-1"></i>RA 10173 Compliant</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-teal" onclick="savePatient()">
                    <i class="bi bi-check-lg me-1"></i>Save Patient
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     OTP MODAL — No X button, teal only
══════════════════════════════════════════ -->
<div class="modal fade" id="otpModal" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <!-- ↑ static backdrop + keyboard=false → cannot dismiss by clicking outside or pressing Esc -->
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <!-- No btn-close here intentionally -->
                <h5 class="modal-title w-100 text-center">
                    <i class="bi bi-shield-lock me-2"></i>Verify Your Email
                </h5>
            </div>
            <div class="modal-body">
                <div class="otp-icon-ring">
                    <i class="bi bi-envelope-open-text"></i>
                </div>
                <p class="text-center text-muted mb-2" style="font-size:14px;">
                    We sent a 6-digit code to:
                </p>
                <div class="otp-email-badge" id="otpEmail">—</div>
                <div class="otp-input-wrapper mb-2">
                    <input type="text" id="otpCode" class="form-control"
                           placeholder="• • • • • •" maxlength="6"
                           oninput="this.value=this.value.replace(/\D/g,'')">
                </div>
                <div class="otp-timer" id="otpTimerDisplay">
                    Code expires in <span id="otpCountdown">05:00</span>
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-link text-muted" id="resendBtn"
                            style="font-size:13px;" onclick="resendOTP()" disabled>
                        <i class="bi bi-arrow-repeat me-1"></i>Resend code
                    </button>
                </div>
            </div>
            <div class="modal-footer justify-content-center pb-4" style="border:none;">
                <button class="btn btn-teal px-5 py-2" style="border-radius:10px;font-size:15px;font-weight:700;"
                        onclick="verifyOTP()">
                    <i class="bi bi-check-circle me-2"></i>Verify OTP
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     UPGRADE ACCOUNT MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="upgradeAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-up me-2"></i>Upgrade to Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Create account for <span id="upgradePatientName" class="fw-bold"></span></p>
                <form id="upgradeAccountForm">
                    <input type="hidden" id="upgrade_patient_id">
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" id="upgrade_email" class="form-control" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" id="upgrade_password" class="form-control" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" id="upgrade_confirm_password" class="form-control" autocomplete="new-password" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-teal" onclick="upgradeAccount()">
                    <i class="bi bi-person-up me-1"></i>Upgrade Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Patient Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewPatientContent"></div>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editPatientContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Cancel
                </button>
                <button type="submit" form="editPatientForm" class="btn btn-teal" id="updatePatientBtn">
                    <i class="bi bi-check-lg me-1"></i>Update Patient
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const baseUrl    = '<?= $base_url ?>';
const clinicId   = <?= $clinicId ?>;
const userRole   = '<?= $_SESSION['role'] ?? '' ?>';
const permissions = {
    canView:    <?= json_encode($canView) ?>,
    canCreate:  <?= json_encode($canCreate) ?>,
    canEdit:    <?= json_encode($canEdit) ?>,
    canDelete:  <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject:  <?= json_encode($canReject) ?>
};

// ── OTP COUNTDOWN TIMER ─────────────────────────────────────
let otpTimer = null;
let otpSeconds = 300; // 5 minutes

function startOtpCountdown() {
    otpSeconds = 300;
    clearInterval(otpTimer);
    document.getElementById('resendBtn').disabled = true;

    otpTimer = setInterval(() => {
        otpSeconds--;
        const m = String(Math.floor(otpSeconds / 60)).padStart(2, '0');
        const s = String(otpSeconds % 60).padStart(2, '0');
        const el = document.getElementById('otpCountdown');
        if (el) el.textContent = `${m}:${s}`;

        if (otpSeconds <= 0) {
            clearInterval(otpTimer);
            const timerDiv = document.getElementById('otpTimerDisplay');
            if (timerDiv) timerDiv.innerHTML = '<span class="text-danger">Code expired — please resend.</span>';
            const resendBtn = document.getElementById('resendBtn');
            if (resendBtn) resendBtn.disabled = false;
        }
        // Enable resend after 60s
        if (otpSeconds <= 240) {
            const resendBtn = document.getElementById('resendBtn');
            if (resendBtn) resendBtn.disabled = false;
        }
    }, 1000);
}

// ── AGE LIMIT CHECK ──────────────────────────────────────────
function checkAgeLimit() {
    const accountType = $('input[name="accountType"]:checked').val();
    const age = parseInt($('#age').val()) || 0;
    const msgEl = document.getElementById('ageLimitMsg');
    if (accountType === 'with' && age > 0 && age < 18) {
        msgEl.classList.remove('d-none');
    } else {
        msgEl.classList.add('d-none');
    }
}
</script>

<script src="assets/js/patient.js"></script>
</body>
</html>