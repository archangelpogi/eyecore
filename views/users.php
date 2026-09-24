<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid p-3 p-md-4">
    
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Employee Management System</h2>
            <p class="text-muted mb-0">Manage employees, attendance, leaves, and payroll</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary px-3" onclick="exportToExcel()">
                <i class="bi bi-download"></i> Export
            </button>
            <button class="btn btn-success px-3" onclick="openAddEmployeeModal()">
                <i class="bi bi-person-plus"></i> Add New Employee
            </button>
        </div>
    </div>
    
    <div class="row g-3 mb-4" id="statsContainer">
        <div class="col-md-3"><div class="card p-3 h-100"><small class="text-muted">Active Employees</small><h3 id="activeEmployees" class="fw-bold mt-1 mb-2">0</h3><small class="text-muted d-block">Currently working</small></div></div>
        <div class="col-md-3"><div class="card p-3 h-100"><small class="text-muted">On Leave</small><h3 id="onLeave" class="fw-bold mt-1 mb-2">0</h3><small class="text-muted d-block">Currently on leave</small></div></div>
        <div class="col-md-3"><div class="card p-3 h-100"><small class="text-muted">Monthly Payroll</small><h3 id="monthlyPayroll" class="fw-bold mt-1 mb-2">₱0</h3><small class="text-muted d-block">Total monthly salary</small></div></div>
        <div class="col-md-3"><div class="card p-3 h-100"><small class="text-muted">Positions</small><h3 id="positionsCount" class="fw-bold mt-1 mb-2">0</h3><small class="text-muted d-block">Job positions</small></div></div>
    </div>
    
    <div class="card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-3"><select id="positionFilter" class="form-select" onchange="filterEmployees()"><option value="">All Positions</option></select></div>
            <div class="col-md-3"><select id="statusFilter" class="form-select" onchange="filterEmployees()"><option value="">All Status</option><option value="Active">Active</option><option value="On-Leave">On Leave</option><option value="Resigned">Resigned</option><option value="Terminated">Terminated</option></select></div>
            <div class="col-md-3"><select id="employmentTypeFilter" class="form-select" onchange="filterEmployees()"><option value="">All Employment Types</option><option value="Regular">Regular</option><option value="Probationary">Probationary</option><option value="Contractual">Contractual</option><option value="Part-time">Part-time</option></select></div>
            <div class="col-md-3"><div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" id="searchEmployees" class="form-control" placeholder="Search employees..."></div></div>
        </div>
    </div>
    
    <div class="card overflow-hidden mb-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="width:100%">
                <thead><tr><th>Employee Details</th><th>Position/Department</th><th>Employment Info</th><th>Salary</th><th>Status</th><th width="200">Actions</th></tr></thead>
                <tbody id="employeesTableBody"></tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL - ADD EMPLOYEE -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-person-plus me-2"></i>Add New Employee
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-0">
                <form id="addEmployeeForm" novalidate>
                    <div class="p-4" style="max-height: 70vh; overflow-y: auto;">

<ul class="nav nav-tabs mb-3" id="addEmployeeTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">
            <i class="bi bi-person me-1"></i> Basic Info
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="employment-tab" data-bs-toggle="tab" data-bs-target="#employment" type="button" role="tab">
            <i class="bi bi-briefcase me-1"></i> Employment
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="government-tab" data-bs-toggle="tab" data-bs-target="#government" type="button" role="tab">
            <i class="bi bi-card-checklist me-1"></i> Government IDs
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank" type="button" role="tab">
            <i class="bi bi-bank me-1"></i> Bank & Emergency
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
            <i class="bi bi-file-earmark me-1"></i> Documents
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="roles-tab" data-bs-toggle="tab" data-bs-target="#rolesTab" type="button" role="tab">
            <i class="bi bi-shield-lock me-1"></i> RBAC Roles
        </button>
    </li>
</ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="addEmployeeTabContent">

<!-- ═══════════════════════════════════════════════════════
     BASIC INFO TAB
     ═══════════════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="basic" role="tabpanel">
    <h6 class="border-bottom pb-2 mb-4">
        <i class="bi bi-info-circle me-2"></i>Personal Information
    </h6>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_first_name">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="add_first_name" name="first_name" autocomplete="given-name" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_last_name">Last Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="add_last_name" name="last_name" autocomplete="family-name" required>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-control" id="add_middle_name" name="middle_name">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Phone Number</label>
            <input type="tel" class="form-control" id="add_phone_number" name="phone_number" placeholder="09XX-XXX-XXXX">
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_email">Email <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="add_email" name="email" autocomplete="email" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_password">Password <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="password" class="form-control" id="add_password" name="password" autocomplete="new-password" required>
                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('add_password')">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_role">Role <span class="text-danger">*</span></label>
            <select class="form-select" id="add_role" name="role" required onchange="toggleOptometristFields()">
                <option value="">Select Role</option>
                <option value="Optometrist">Optometrist</option>
                <option value="Staff">Staff</option>
                <option value="HR">HR</option>
                <option value="Finance">Finance</option>
                <option value="CRM">CRM</option>
                <option value="SCM">SCM</option>
                <option value="Rider">Rider</option>
            </select>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label" for="add_status">Status <span class="text-danger">*</span></label>
            <select class="form-select" id="add_status" name="status" required>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>
    </div>

    <!-- ✅ Optometrist-only fields -->
    <div id="optometristFields" style="display: none;">
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-eye me-2"></i>
            <strong>Optometrist Details</strong> — These fields will be added to the Doctors list.
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_specialty">
                    Specialty
                    <small class="text-muted">(optional)</small>
                </label>
                <input type="text" class="form-control" id="add_specialty" name="specialty"
                       placeholder="e.g. Pediatric Optometry, Contact Lens" maxlength="100">
                <div class="form-text text-muted">Area of expertise or specialization</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_schedule">
                    Schedule
                    <small class="text-muted fw-normal">(optional)</small>
                </label>
                <div id="scheduleBuilder" style="border: 0.5px solid #dee2e6; border-radius: 8px; padding: 10px;">
                    <div id="schedDayRows"></div>
                </div>
                <input type="hidden" id="add_schedule" name="schedule">
                <div class="form-text text-muted">Lagyan ng tsek ang araw at itakda ang oras</div>
            </div>
        </div>
    </div>

    <!-- ✅ Rider-only fields -->
    <div id="riderFields" style="display: none;">
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-bicycle me-2"></i>
            <strong>Rider Details</strong> — These fields will be added to the Riders list.
        </div>

        <!-- Driver's License -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_driver_license_no">
                    Driver's License No. <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="add_driver_license_no" name="driver_license_no"
                       placeholder="e.g. N01-23-456789" maxlength="50" required>
                <div class="form-text text-muted">LTO-issued driver's license number</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_license_expiration_date">
                    License Expiration Date <span class="text-danger">*</span>
                </label>
                <input type="date" class="form-control" id="add_license_expiration_date"
                       name="license_expiration_date" required>
                <div class="form-text text-muted">Valid until</div>
            </div>
        </div>

        <!-- Vehicle Info -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_vehicle_type">
                    Vehicle Type <span class="text-danger">*</span>
                </label>
                <select class="form-select" id="add_vehicle_type" name="vehicle_type" required>
                    <option value="">Select Vehicle</option>
                    <option value="Motorcycle">Motorcycle</option>
                    <option value="Car">Car</option>
                    <option value="Bike">Bicycle</option>
                    <option value="Van">Van</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_vehicle_brand_model">
                    Vehicle Brand &amp; Model
                </label>
                <input type="text" class="form-control" id="add_vehicle_brand_model"
                       name="vehicle_brand_model" placeholder="e.g. Honda Click 125i" maxlength="100">
                <div class="form-text text-muted">Brand and model of the vehicle</div>
            </div>
        </div>

        <!-- Plate & OR/CR -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_plate_number">
                    Plate Number <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="add_plate_number" name="plate_number"
                       placeholder="e.g. ABC-1234" maxlength="20" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_or_cr_number">
                    OR/CR Number
                </label>
                <input type="text" class="form-control" id="add_or_cr_number" name="or_cr_number"
                       placeholder="e.g. 1234567890" maxlength="50">
                <div class="form-text text-muted">Official Receipt / Certificate of Registration</div>
            </div>
        </div>

        <!-- Assigned Clinic (read-only) -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label" for="add_assigned_clinic">Assigned Clinic</label>
                <input type="text" class="form-control bg-light" id="add_assigned_clinic"
                       value="Auto-assigned based on your clinic" readonly>
                <div class="form-text text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Automatically assigned based on the clinic you are logged into.
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <label class="form-label">Birth Date</label>
            <input type="date" class="form-control" id="add_birth_date" name="birth_date">
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Gender</label>
            <select class="form-select" id="add_gender" name="gender">
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>
        </div>
        <div class="col-md-4 mb-3">
            <label class="form-label">Marital Status</label>
            <select class="form-select" id="add_marital_status" name="marital_status">
                <option value="">Select Status</option>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Widowed">Widowed</option>
                <option value="Separated">Separated</option>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-3">
            <label class="form-label">Address</label>
            <textarea class="form-control" id="add_address" name="address" rows="2"></textarea>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════
     END BASIC INFO TAB
     ═══════════════════════════════════════════════════════ -->

                            <!-- Employment Tab -->
                            <div class="tab-pane fade" id="employment" role="tabpanel">
                                <h6 class="border-bottom pb-2 mb-4"><i class="bi bi-briefcase me-2"></i>Employment Details</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Position <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <select class="form-select" id="add_position_id" required>
                                                <option value="">Select Position</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" onclick="openAddPositionModal()">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Date Hired <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="add_date_hired" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Employment Type <span class="text-danger">*</span></label>
                                        <select class="form-select" id="add_employment_type" required>
                                            <option value="">Select Type</option>
                                            <option value="Regular">Regular</option>
                                            <option value="Probationary">Probationary</option>
                                            <option value="Contractual">Contractual</option>
                                            <option value="Part-time">Part-time</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Salary Frequency <span class="text-danger">*</span></label>
                                        <select class="form-select" id="add_salary_frequency" required>
                                            <option value="">Select Frequency</option>
                                            <option value="15days">Every 15 Days</option>
                                            <option value="30days">Every 30 Days</option>
                                            <option value="monthly">Monthly</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Basic Salary (₱) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="add_basic_salary" required step="0.01" min="0" placeholder="Enter amount">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Salary Type</label>
                                        <select class="form-select" id="add_salary_type">
                                            <option value="Fixed">Fixed</option>
                                            <option value="Hourly">Hourly Rate</option>
                                            <option value="Commission">Commission-based</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Date Regularized</label>
                                        <input type="date" class="form-control" id="add_date_regularized">
                                    </div>
                                </div>
                            </div>

                            <!-- Government IDs Tab -->
                            <div class="tab-pane fade" id="government" role="tabpanel">
                                <div class="alert alert-warning mb-4">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <strong>Optional Information</strong> — Can be completed later
                                </div>
                                <h6 class="border-bottom pb-2 mb-4">
                                    <i class="bi bi-card-checklist me-2"></i>Government IDs
                                </h6>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SSS Number</label>
                                        <input type="text" class="form-control" id="add_sss_number"
                                            name="sss_number"
                                            placeholder="XX-XXXXXXX-X"
                                            maxlength="12"
                                            pattern="\d{2}-\d{7}-\d{1}"
                                            oninput="formatSSS(this)"
                                            autocomplete="off">
                                        <div class="form-text text-muted">Format: XX-XXXXXXX-X (10 digits)</div>
                                        <div class="invalid-feedback">Invalid SSS format. Use: XX-XXXXXXX-X</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">PhilHealth Number</label>
                                        <input type="text" class="form-control" id="add_philhealth_number"
                                            name="philhealth_number"
                                            placeholder="XX-XXXXXXXXX-X"
                                            maxlength="14"
                                            pattern="\d{2}-\d{9}-\d{1}"
                                            oninput="formatPhilHealth(this)"
                                            autocomplete="off">
                                        <div class="form-text text-muted">Format: XX-XXXXXXXXX-X (12 digits)</div>
                                        <div class="invalid-feedback">Invalid PhilHealth format. Use: XX-XXXXXXXXX-X</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Pag-IBIG Number</label>
                                        <input type="text" class="form-control" id="add_pagibig_number"
                                            name="pagibig_number"
                                            placeholder="XXXX-XXXX-XXXX"
                                            maxlength="14"
                                            pattern="\d{4}-\d{4}-\d{4}"
                                            oninput="formatPagIbig(this)"
                                            autocomplete="off">
                                        <div class="form-text text-muted">Format: XXXX-XXXX-XXXX (12 digits)</div>
                                        <div class="invalid-feedback">Invalid Pag-IBIG format. Use: XXXX-XXXX-XXXX</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">TIN Number</label>
                                        <input type="text" class="form-control" id="add_tin_number"
                                            name="tin_number"
                                            placeholder="XXX-XXX-XXX"
                                            maxlength="15"
                                            pattern="\d{3}-\d{3}-\d{3}(-\d{3,4})?"
                                            oninput="formatTIN(this)"
                                            autocomplete="off">
                                        <div class="form-text text-muted">Format: XXX-XXX-XXX or XXX-XXX-XXX-XXX (9–12 digits)</div>
                                        <div class="invalid-feedback">Invalid TIN format. Use: XXX-XXX-XXX</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank & Emergency Tab -->
                            <div class="tab-pane fade" id="bank" role="tabpanel">
                                <div class="alert alert-warning mb-4">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <strong>Optional Information</strong> — Can be completed later
                                </div>

                                <h6 class="border-bottom pb-2 mb-4">
                                    <i class="bi bi-bank me-2"></i>Bank Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Bank Name</label>
                                        <select class="form-select" id="add_bank_name" name="bank_name"
                                                onchange="updateBankAccountHint()">
                                            <option value="">Select Bank</option>
                                            <option value="BDO">BDO Unibank</option>
                                            <option value="BPI">BPI</option>
                                            <option value="Metrobank">Metrobank</option>
                                            <option value="Other">Other Bank</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Account Holder</label>
                                        <input type="text" class="form-control" id="add_bank_account_holder"
                                            name="bank_account_holder"
                                            maxlength="100"
                                            placeholder="Full name as printed on card">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Account Number</label>
                                        <input type="text" class="form-control" id="add_bank_account_number"
                                            name="bank_account_number"
                                            placeholder="Enter account number"
                                            maxlength="16"
                                            oninput="formatBankAccount(this)"
                                            autocomplete="off">
                                        <div class="form-text text-muted" id="bank_account_hint">
                                            Select a bank first to see the required format.
                                        </div>
                                        <div class="invalid-feedback" id="bank_account_error">
                                            Invalid account number for the selected bank.
                                        </div>
                                    </div>
                                </div>

                                <h6 class="border-bottom pb-2 mb-4 mt-4">
                                    <i class="bi bi-telephone me-2"></i>Emergency Contact
                                </h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contact Relationship</label>
                                        <select class="form-select" id="add_emergency_contact_relationship">
                                            <option value="">Select Relationship</option>
                                            <option value="Spouse">Spouse</option>
                                            <option value="Parent">Parent</option>
                                            <option value="Sibling">Sibling</option>
                                            <option value="Child">Child</option>
                                            <option value="Friend">Friend</option>
                                            <option value="Relative">Relative</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contact Name</label>
                                        <input type="text" class="form-control" id="add_emergency_contact_name"
                                            maxlength="100">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Contact Number</label>
                                        <input type="tel" class="form-control" id="add_emergency_contact_number"
                                            placeholder="09XX-XXX-XXXX"
                                            maxlength="13"
                                            oninput="formatPhoneNumber(this)">
                                    </div>
                                </div>
                            </div>

                            <!-- Documents Tab -->
                            <div class="tab-pane fade" id="documents" role="tabpanel">
                                <h6 class="border-bottom pb-2 mb-4"><i class="bi bi-file-earmark me-2"></i>Employee Documents (Optional)</h6>
                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    <strong>Note:</strong> You can upload documents now or after employee creation.
                                </div>

                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Resume / CV</label>
                                            <input type="file" class="form-control" id="add_resume" name="resume" accept=".pdf,.jpg,.jpeg,.png">
                                            <div class="form-text">PDF, JPG, PNG • Max 10MB</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Medical Certificate</label>
                                            <input type="file" class="form-control" id="add_medical_cert" name="medical_cert" accept=".pdf,.jpg,.jpeg,.png">
                                            <div class="form-text">Optional • Max 10MB</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Government ID</label>
                                            <input type="file" class="form-control" id="add_government_id" name="government_id" accept=".pdf,.jpg,.jpeg,.png">
                                            <div class="form-text">Valid ID • Max 10MB</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">NBI / Police Clearance</label>
                                            <input type="file" class="form-control" id="add_clearance" name="clearance" accept=".pdf,.jpg,.jpeg,.png">
                                            <div class="form-text">Optional • Max 10MB</div>
                                        </div>
                                    </div>
                                </div>

                                <div id="filePreviewSection" class="mt-4" style="display: none;">
                                    <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-files me-2"></i>Selected Files</h6>
                                    <div id="selectedFilesList" class="list-group"></div>
                                </div>
                            </div>

                            <!-- RBAC Roles Tab -->
                            <div class="tab-pane fade" id="rolesTab" role="tabpanel">
                                <h6 class="border-bottom pb-2 mb-3">
                                    <i class="bi bi-shield-lock me-2"></i>Assign Access Roles
                                </h6>
                                <div class="alert alert-info py-2 small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    These roles control what this employee can <b>see and do</b> in the system.
                                    You can skip this and assign later.
                                </div>
                                <div id="addEmployeeRolesList" class="d-flex flex-column gap-2">
                                    <div class="text-center py-3 text-muted">
                                        <div class="spinner-border spinner-border-sm me-2"></div> Loading roles...
                                    </div>
                                </div>
                            </div>

                        </div><!-- /tab-content -->
                    </div><!-- /p-4 -->

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </button>
                        <div class="btn-group" id="stepNavigation">
                            <button type="button" class="btn btn-outline-secondary" id="backBtn" onclick="previousStep()" style="display: none;">
                                <i class="bi bi-chevron-left me-1"></i> Back
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="nextBtn" onclick="nextStep()">
                                <i class="bi bi-chevron-right me-1"></i> Next
                            </button>
                            <button type="submit" class="btn btn-success" id="createBtn" style="display: none;">
                                <i class="bi bi-check-circle me-2"></i> Create Employee
                            </button>
                        </div>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>


<!-- OTHER MODALS -->
<div class="modal fade" id="addPositionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fw-bold">Add New Position</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="addPositionForm"><div class="modal-body">
            <div class="mb-3"><label class="form-label">Position Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="position_name" required></div>
            <div class="mb-3"><label class="form-label">Department</label><select class="form-select" id="position_department"><option value="">Select Department</option><option value="Optometry">Optometry</option><option value="Human Resources">Human Resources</option><option value="Finance">Finance</option></select></div>
            <div class="mb-3"><label class="form-label">Salary Rate (₱)</label><input type="number" class="form-control" id="salary_rate" step="0.01" min="0"></div>
        </div><div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Position</button>
        </div></form>
    </div></div>
</div>

<div class="modal fade" id="viewEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fw-bold">Employee Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="viewEmployeeContent"></div>
    </div></div>
</div>

<div class="modal fade" id="editEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fw-bold">Edit Employee Information</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="editEmployeeForm"><div class="modal-body" id="editEmployeeContent"></div>
    </div></div>
</div>

<div class="modal fade" id="assignRolesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-shield-lock me-2"></i>Assign Access Roles
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="assign_user_id">
 
                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded mb-3">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold"
                         style="width:44px;height:44px;font-size:16px" id="assign_user_avatar">--</div>
                    <div>
                        <div class="fw-bold" id="assign_user_name">—</div>
                        <small class="text-muted" id="assign_user_role">—</small>
                    </div>
                </div>
 
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    You can assign <strong>multiple roles</strong> to an employee.
                    Their permissions are a combination of all assigned roles.
                </p>
 
                <div id="assignRolesLoading" class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                    Loading roles...
                </div>
 
                <div id="assignRolesList" class="d-flex flex-column gap-2" style="display:none!important;"></div>
                <div id="assignRolesEmpty" class="text-center py-3 text-muted" style="display:none;">
                    <i class="bi bi-shield-exclamation display-6 d-block mb-2 opacity-50"></i>
                    No roles yet. 
                    <a href="main.php?view=roles_management" target="_blank">Create roles here</a>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="saveUserRoles()" id="saveRolesBtn">
                    <i class="bi bi-check-circle me-1"></i>Apply Roles
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/users.js"></script>
</body>
</html>