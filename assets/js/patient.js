// ============================================================
// PERMISSIONS & INIT
// ============================================================
let table;
let currentUserRole = null;
let userPermissions = { view:false, create:false, edit:false, delete:false, approve:false, reject:false };

async function loadPatientPermissions() {
    if (typeof permissions !== 'undefined') {
        userPermissions = {
            view:    permissions.canView    || false,
            create:  permissions.canCreate  || false,
            edit:    permissions.canEdit    || false,
            delete:  permissions.canDelete  || false,
            approve: permissions.canApprove || false,
            reject:  permissions.canReject  || false
        };
        currentUserRole = userRole || 'user';
        if (table) table.ajax.reload(null, false);
    }
}

function canViewPatients()    { return userPermissions.view; }
function canCreatePatients()  { return userPermissions.create; }
function canEditPatients()    { return userPermissions.edit; }
function canDeletePatients()  { return userPermissions.delete; }
function canApprovePatients() { return userPermissions.approve; }
function canRejectPatients()  { return userPermissions.reject; }

// ============================================================
// DOCUMENT READY
// ============================================================
$(document).ready(function () {
    loadPatientPermissions();

    // Account type toggle — show/hide email & password fields
    $('input[name="accountType"]').change(function () {
        const isWith = $(this).val() === 'with';
        $('#emailField, #passwordField, #confirmPasswordField').toggle(isWith);
        $('#email, #password, #confirm_password').prop('required', isWith);
        $('#ageAccountWarning').toggleClass('d-none', !isWith);
        // Re-run age check when switching type
        checkAgeLimit();
    });

    // DataTable
    table = $('#patientsTable').DataTable({
        processing: false,
        serverSide: true,
        ajax: {
            url: baseUrl + 'api/patients.php?action=fetch',
            type: 'GET',
            cache: false,
            data: function (d) {
                d.status      = $('#statusFilter').val();
                d.gender      = $('#genderFilter').val();
                d.searchInput = $('#searchInput').val();
            },
            dataSrc: function (json) {
                if (json.stats) {
                    $('#totalPatients').text(json.stats.total || 0);
                    $('#activePatients').text(json.stats.active || 0);
                    $('#withAccount').text(json.stats.with_account || 0);
                    $('#specialCount').text((json.stats.senior || 0) + (json.stats.pwd || 0));
                    $('#consentGivenCount').text(json.stats.consent_given || 0);
                    var total      = json.stats.total || 0;
                    var consentPct = total > 0 ? ((json.stats.consent_given || 0) / total * 100).toFixed(1) : 0;
                    $('#consentRate').text(consentPct + '%');
                }
                return json.data || [];
            }
        },
        columns: [
            { data: 'patient_code' },
            {
                data: null,
                render: function (d) {
                    return '<div class="d-flex align-items-center">' +
                        '<div class="rounded-circle d-flex align-items-center justify-content-center me-2 text-white fw-bold" ' +
                        'style="width:40px;height:40px;background:#0d9488;font-size:14px;">' +
                        ((d.first_name ? d.first_name.charAt(0) : '') + (d.last_name ? d.last_name.charAt(0) : '')).toUpperCase() +
                        '</div>' +
                        '<div><div class="fw-medium">' + esc(d.first_name || '') + ' ' + esc(d.last_name || '') + '</div>' +
                        '<small class="text-muted">' + esc(d.email || '') + '</small></div></div>';
                }
            },
            {
                data: null,
                render: function (d) {
                    var badge = '';
                    if (d.patient_type === 'Senior') badge = '<br><span class="badge mt-1" style="background:#0d9488;">Senior</span>';
                    else if (d.patient_type === 'PWD') badge = '<br><span class="badge mt-1" style="background:#0f766e;">PWD</span>';
                    return (d.age || '') + badge;
                }
            },
            {
                data: 'gender',
                render: function (d) {
                    return d ? '<span class="badge" style="background:#0d9488;">' + d + '</span>' : '';
                }
            },
            { data: 'phone' },
            {
                data: 'has_account',
                render: function (d) {
                    return d
                        ? '<span class="badge" style="background:#0f766e;"><i class="bi bi-person-badge me-1"></i>With Account</span>'
                        : '<span class="badge bg-secondary">Walk-in</span>';
                }
            },
            {
                data: 'data_privacy_accepted',
                render: function (d) {
                    return d
                        ? '<span class="badge" style="background:#0d9488;"><i class="bi bi-shield-check me-1"></i>Consent Given</span>'
                        : '<span class="badge bg-danger"><i class="bi bi-shield-exclamation me-1"></i>No Consent</span>';
                }
            },
            {
                data: 'status',
                render: function (d) {
                    return '<span class="badge ' + (d === 'Active' ? '' : 'bg-secondary') + '"' +
                        (d === 'Active' ? ' style="background:#0d9488;"' : '') + '>' + d + '</span>';
                }
            },
            {
                data: 'created_at',
                render: function (d) { return d ? new Date(d).toLocaleDateString() : ''; }
            },
            {
                data: null,
                render: function (d) {
                    var btns = '';
                    if (canViewPatients())
                        btns += '<button class="btn btn-sm btn-outline-secondary me-1" onclick="viewPatientWithConsentCheck(' + d.id + ')"><i class="bi bi-eye"></i></button>';
                    if (canEditPatients())
                        btns += '<button class="btn btn-sm btn-outline-secondary me-1" onclick="editPatientWithConsentCheck(' + d.id + ')"><i class="bi bi-pencil"></i></button>';
                    if (!d.has_account && canCreatePatients())
                        btns += '<button class="btn btn-sm btn-outline-secondary" onclick="showUpgradeModal(' + d.id + ', \'' + esc(d.first_name || '') + ' ' + esc(d.last_name || '') + '\')"><i class="bi bi-person-up"></i></button>';
                    if (canDeletePatients()) {
                        if (d.status === 'Active')
                            btns += '<button class="btn btn-sm btn-outline-secondary ms-1" onclick="archivePatient(' + d.id + ')"><i class="bi bi-archive"></i></button>';
                        else
                            btns += '<button class="btn btn-sm btn-outline-secondary ms-1" onclick="unarchivePatient(' + d.id + ')"><i class="bi bi-arrow-counterclockwise"></i></button>';
                    }
                    if (d.consent_status === 'pending' && canApprovePatients())
                        btns += '<button class="btn btn-sm btn-outline-secondary ms-1" onclick="approveConsent(' + d.id + ')"><i class="bi bi-check-circle"></i></button>';
                    if (d.consent_status === 'pending' && canRejectPatients())
                        btns += '<button class="btn btn-sm btn-outline-danger ms-1" onclick="rejectConsent(' + d.id + ')"><i class="bi bi-x-circle"></i></button>';
                    if (canViewPatients())
                        btns += '<button class="btn btn-sm btn-outline-secondary ms-1" onclick="exportPatientData(' + d.id + ')"><i class="bi bi-download"></i></button>';
                    if (!btns) btns = '<span class="badge bg-secondary">View Only</span>';
                    return '<div class="text-end">' + btns + '</div>';
                }
            }
        ]
    });

    $('#statusFilter, #genderFilter').change(function () { table.draw(false); });
    $('#searchInput').on('keyup', function () { table.draw(false); });
    $('#resetFilters').click(function () {
        $('#statusFilter, #genderFilter, #searchInput').val('');
        table.draw(false);
    });
});

// ============================================================
// SAVE PATIENT  — stores in PHP session first, INSERT after OTP
// ============================================================
function savePatient() {
    if (!canCreatePatients()) {
        Swal.fire({ icon:'error', title:'Access Denied', text:'You do not have permission to add patients.' });
        return;
    }

    // ── Consent check ──
    if (!$('#dataConsent').is(':checked')) {
        Swal.fire({
            icon:'warning', title:'Consent Required',
            text:'Please acknowledge the Data Privacy consent to continue.',
            confirmButtonText:'I Understand',
            confirmButtonColor:'#0d9488'
        });
        return;
    }

    const accountType = $('input[name="accountType"]:checked').val();
    const age = parseInt($('#age').val()) || 0;

    // ── Age limit for With Account ──
    if (accountType === 'with' && age > 0 && age < 18) {
        Swal.fire({
            icon:'warning', title:'Age Requirement',
            html:'Patients must be <strong>at least 18 years old</strong> to create an account.<br><br>Please use <em>Walk-in</em> for minors.',
            confirmButtonColor:'#0d9488'
        });
        return;
    }

    const formData = {
        clinic_id:    clinicId,
        first_name:   $('#first_name').val().trim(),
        last_name:    $('#last_name').val().trim(),
        age:          $('#age').val(),
        gender:       $('#gender').val(),
        phone:        $('#phone').val().trim(),
        email:        $('#email').val().trim(),
        address:      $('#address').val().trim(),
        patient_type: $('#patient_type').val(),
        remarks:      $('#remarks').val().trim(),
        account_type: accountType,
        consent_given: true
    };

    // ── Basic required fields ──
    if (!formData.first_name || !formData.last_name || !formData.phone) {
        Swal.fire({ icon:'error', text:'Please fill all required fields.', confirmButtonColor:'#0d9488' });
        return;
    }

    if (accountType === 'with') {
        const pass    = $('#password').val();
        const confirm = $('#confirm_password').val();

        if (!formData.email) {
            Swal.fire({ icon:'error', text:'Email is required for account creation.', confirmButtonColor:'#0d9488' });
            return;
        }
        if (pass !== confirm) {
            Swal.fire({ icon:'error', text:'Passwords do not match.', confirmButtonColor:'#0d9488' });
            return;
        }
        if (!isPasswordStrong(pass)) {
            Swal.fire({
                icon:'error',
                text:'Password must be at least 8 characters with uppercase, number, and special character.',
                confirmButtonColor:'#0d9488'
            });
            return;
        }
        formData.password = pass;
    }

    Swal.fire({
        title:'Saving…', text:'Please wait.',
        allowOutsideClick:false, showConfirmButton:false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: baseUrl + 'api/patients.php',
        type: 'POST',
        data: JSON.stringify(formData),
        contentType: 'application/json',
        dataType: 'json',
        success: function (r) {
            Swal.close();
            if (r.success) {
                $('#addPatientModal').modal('hide');
                cleanupModals();
                $('#addPatientForm')[0].reset();
                $('#remarksField').hide();
                $('#ageLimitMsg').addClass('d-none');

                if (accountType === 'with' && r.otp_required) {
                    // ── Show OTP modal — table will refresh ONLY after verification ──
                    setTimeout(() => {
                        document.getElementById('otpEmail').textContent = formData.email;
                        document.getElementById('otpCode').value = '';
                        // reset timer display
                        document.getElementById('otpTimerDisplay').innerHTML =
                            'Code expires in <span id="otpCountdown">05:00</span>';
                        document.getElementById('resendBtn').disabled = true;
                        startOtpCountdown();
                        new bootstrap.Modal(document.getElementById('otpModal')).show();
                    }, 400);
                } else {
                    // Walk-in — patient already inserted, refresh table
                    if (table) table.draw(false);
                    Swal.fire({
                        icon:'success', text:r.message,
                        timer:2000, showConfirmButton:false
                    });
                }
            } else {
                Swal.fire({ icon:'error', text:r.message, confirmButtonColor:'#0d9488' });
            }
        },
        error: function () {
            Swal.close();
            Swal.fire({ icon:'error', text:'Connection error.', confirmButtonColor:'#0d9488' });
        }
    });
}

function verifyOTP() {
    const otp = $('#otpCode').val().trim();
    const email = $('#otpEmail').text().trim();

    if (!otp || otp.length !== 6) {
        Swal.fire({ icon: 'error', text: 'Please enter the 6-digit code.', confirmButtonColor: '#0d9488' });
        return;
    }

    Swal.fire({
        title: 'Verifying…',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    // GAMITIN ANG $.ajax INSTEAD OF $.post
    $.ajax({
        url: baseUrl + 'api/patients.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ 
            action: 'verify',  // <-- ILAGAY ANG ACTION SA JSON BODY
            email: email, 
            otp: otp 
        }),
        xhrFields: {
            withCredentials: true
        },
        success: function(r) {
            Swal.close();
            if (r.success) {
                clearInterval(otpTimer);
                bootstrap.Modal.getInstance(document.getElementById('otpModal')).hide();
                cleanupModals();
                if (table) table.draw(false);
                Swal.fire({
                    icon: 'success',
                    title: 'Account Verified!',
                    text: r.message,
                    timer: 2500,
                    showConfirmButton: false
                });
            } else {
                if (r.can_resend) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'OTP Expired',
                        text: r.message,
                        showCancelButton: true,
                        confirmButtonText: 'Resend OTP',
                        cancelButtonText: 'Close',
                        confirmButtonColor: '#0d9488'
                    }).then(res => { if (res.isConfirmed) resendOTP(); });
                } else {
                    Swal.fire({ icon: 'error', text: r.message, confirmButtonColor: '#0d9488' });
                }
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            console.log('Error:', xhr.responseText);
            Swal.fire({ icon: 'error', text: 'Connection error. Please try again.' });
        }
    });
}
function resendOTP() {
    const email = $('#otpEmail').text().trim();
    
    $.ajax({
        url: baseUrl + 'api/patients.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ 
            action: 'resend',  // <-- ILAGAY ANG ACTION SA JSON BODY
            email: email 
        }),
        xhrFields: {
            withCredentials: true
        },
        success: function(r) {
            if (r.success) {
                document.getElementById('otpTimerDisplay').innerHTML = 'Code expires in <span id="otpCountdown">05:00</span>';
                startOtpCountdown();
                $('#otpCode').val('');
                Swal.fire({ icon: 'success', text: 'New code sent!', timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', text: r.message || 'Failed to resend code.' });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', text: 'Connection error. Could not resend OTP.' });
        }
    });
}
// ============================================================
// PASSWORD HELPERS
// ============================================================
function isPasswordStrong(password) {
    return password.length >= 8
        && /[A-Z]/.test(password)
        && /[0-9]/.test(password)
        && /[!@#$%^&*(),.?":{}|<>]/.test(password);
}

function checkPasswordStrength() {
    const pass = $('#password').val();
    const checks = {
        char:    pass.length >= 8,
        upper:   /[A-Z]/.test(pass),
        number:  /[0-9]/.test(pass),
        special: /[!@#$%^&*(),.?":{}|<>]/.test(pass)
    };
    const labels = { char:'At least 8 characters', upper:'At least 1 uppercase', number:'At least 1 number', special:'At least 1 special character' };
    Object.keys(checks).forEach(k => {
        const ok = checks[k];
        $('#' + k + 'Check').html(
            '<span class="' + (ok ? 'text-success' : 'text-danger') + '">' +
            '<i class="bi bi-' + (ok ? 'check' : 'x') + '-circle"></i> ' + labels[k] + '</span>'
        );
    });
    const strength = Object.values(checks).filter(Boolean).length * 25;
    const colors = { 0:'bg-secondary', 25:'bg-danger', 50:'bg-warning', 75:'bg-info', 100:'bg-success' };
    const labels2 = { 0:'Very Weak', 25:'Weak', 50:'Fair', 75:'Good', 100:'Strong' };
    $('#strengthBar').css('width', strength + '%').attr('class', 'progress-bar ' + (colors[strength] || 'bg-danger'));
    $('#strengthText').text(labels2[strength] || 'Weak');
    checkPasswordMatch();
}

function checkPasswordMatch() {
    const pass = $('#password').val(), conf = $('#confirm_password').val();
    if (!conf.length) { $('#matchMsg').text('').removeClass(); return; }
    if (pass === conf) {
        $('#matchMsg').html('<span class="text-success"><i class="bi bi-check-circle"></i> Passwords match</span>');
        $('#confirm_password').removeClass('is-invalid').addClass('is-valid');
    } else {
        $('#matchMsg').html('<span class="text-danger"><i class="bi bi-exclamation-circle"></i> Passwords do not match</span>');
        $('#confirm_password').removeClass('is-valid').addClass('is-invalid');
    }
}

// ============================================================
// TOGGLE REMARKS / TOGGLE PASSWORD
// ============================================================
function toggleRemarks() {
    const type = $('#patient_type').val();
    $('#remarksField').toggle(type === 'Senior' || type === 'PWD');
}

function togglePassword(id) {
    const inp  = $('#' + id);
    const type = inp.attr('type') === 'password' ? 'text' : 'password';
    inp.attr('type', type);
    inp.next().find('i').toggleClass('bi-eye bi-eye-slash');
}

// ============================================================
// UPGRADE ACCOUNT
// ============================================================
function showUpgradeModal(id, name) {
    $('#upgrade_patient_id').val(id);
    $('#upgradePatientName').text(name);
    new bootstrap.Modal(document.getElementById('upgradeAccountModal')).show();
}

function upgradeAccount() {
    if (!canCreatePatients()) {
        Swal.fire('Access Denied', 'You do not have permission to upgrade patient accounts', 'error');
        return;
    }
    
    const data = {
        patient_id: $('#upgrade_patient_id').val(),
        email: $('#upgrade_email').val(),
        password: $('#upgrade_password').val()
    };
    
    if (!data.email || !data.password) {
        return Swal.fire({ icon: 'error', text: 'Please fill all fields.', confirmButtonColor: '#0d9488' });
    }
    if (data.password !== $('#upgrade_confirm_password').val()) {
        return Swal.fire({ icon: 'error', text: 'Passwords do not match.', confirmButtonColor: '#0d9488' });
    }
    if (!isPasswordStrong(data.password)) {
        return Swal.fire({ icon: 'error', text: 'Password too weak.', confirmButtonColor: '#0d9488' });
    }

    $.ajax({
        url: baseUrl + 'api/patients.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ 
            action: 'upgrade',  // <-- ILAGAY ANG ACTION SA JSON BODY
            patient_id: data.patient_id,
            email: data.email,
            password: data.password
        }),
        xhrFields: {
            withCredentials: true
        },
        success: function(r) {
            if (r.success) {
                bootstrap.Modal.getInstance(document.getElementById('upgradeAccountModal')).hide();
                cleanupModals();
                $('#upgradeAccountForm')[0].reset();
                setTimeout(() => {
                    document.getElementById('otpEmail').textContent = data.email;
                    document.getElementById('otpCode').value = '';
                    document.getElementById('otpTimerDisplay').innerHTML = 'Code expires in <span id="otpCountdown">05:00</span>';
                    document.getElementById('resendBtn').disabled = true;
                    startOtpCountdown();
                    new bootstrap.Modal(document.getElementById('otpModal')).show();
                }, 300);
            } else {
                Swal.fire({ icon: 'error', text: r.message, confirmButtonColor: '#0d9488' });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', text: 'Connection error. Please try again.' });
        }
    });
}
// ============================================================
// VIEW PATIENT
// ============================================================
function viewPatient(id) {
    new bootstrap.Modal(document.getElementById('viewPatientModal')).show();
    $('#viewPatientContent').html('<div class="text-center p-4"><div class="spinner-border" style="color:#0d9488;"></div><p class="mt-2 text-muted small">Loading…</p></div>');

    $.get(baseUrl + 'api/patients.php?action=view&id=' + id, function (r) {
        if (!r.success) {
            bootstrap.Modal.getInstance(document.getElementById('viewPatientModal')).hide();
            Swal.fire({ icon:'error', text:r.message });
            return;
        }
        $('#viewPatientContent').html(`
            <ul class="nav nav-tabs border-bottom mb-3" id="viewPatientTabs">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#vTab-info"><i class="bi bi-person-badge me-1"></i>Personal Info</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#vTab-notes"><i class="bi bi-file-medical me-1"></i>Clinical Notes</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#vTab-rx"><i class="bi bi-prescription2 me-1"></i>Prescriptions</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#vTab-appts"><i class="bi bi-calendar-check me-1"></i>Appointments</a></li>
            </ul>
            <div class="tab-content px-1">
                <div class="tab-pane fade show active" id="vTab-info">${r.html}</div>
                <div class="tab-pane fade" id="vTab-notes"><div id="vNotes-content"><div class="text-center py-4"><div class="spinner-border" style="color:#0d9488;width:1.3rem;height:1.3rem;"></div></div></div></div>
                <div class="tab-pane fade" id="vTab-rx"><div id="vRx-content"><div class="text-center py-4"><div class="spinner-border" style="color:#0d9488;width:1.3rem;height:1.3rem;"></div></div></div></div>
                <div class="tab-pane fade" id="vTab-appts"><div id="vAppts-content"><div class="text-center py-4"><div class="spinner-border" style="color:#0d9488;width:1.3rem;height:1.3rem;"></div></div></div></div>
            </div>
        `);

        let notesLoaded = false, rxLoaded = false, apptsLoaded = false;
        $('a[href="#vTab-notes"]').on('shown.bs.tab',  function () { if (!notesLoaded)  { notesLoaded  = true; loadPatientClinicalNotes(id); } });
        $('a[href="#vTab-rx"]').on('shown.bs.tab',     function () { if (!rxLoaded)     { rxLoaded     = true; loadPatientPrescriptions(id); } });
        $('a[href="#vTab-appts"]').on('shown.bs.tab',  function () { if (!apptsLoaded)  { apptsLoaded  = true; loadPatientAppointments(id); } });
    }, 'json');
}

// ============================================================
// CONSENT CHECK WRAPPERS
// ============================================================
function viewPatientWithConsentCheck(id) {
    $.get(baseUrl + 'api/patients.php?action=view&id=' + id, function (r) {
        if (!r.success) { Swal.fire({ icon:'error', text:r.message }); return; }
        const p = r.patient;
        if (p.data_privacy_accepted == 1) {
            viewPatient(id);
        } else if (p.consent_status === 'pending') {
            Swal.fire({
                icon:'info', title:'Consent Pending',
                html:'<p>An online consent form has been sent to <strong>' + esc(p.email || '') + '</strong>.</p>' +
                     '<div class="alert alert-warning"><i class="bi bi-envelope-paper me-2"></i>' +
                     '<strong>Records cannot be accessed yet.</strong><br>The patient must click the link in the email first.</div>',
                showCancelButton:true, confirmButtonText:'Resend Consent Email',
                cancelButtonText:'Close', confirmButtonColor:'#0d9488'
            }).then(res => { if (res.isConfirmed) resendConsentEmail(id, p.email); });
        } else {
            showClinicConsentModal(id, (p.first_name || '') + ' ' + (p.last_name || ''));
        }
    }, 'json');
}

function editPatientWithConsentCheck(id) {
    $.get(baseUrl + 'api/patients.php?action=view&id=' + id, function (r) {
        if (!r.success) { Swal.fire({ icon:'error', text:r.message }); return; }
        const p = r.patient;
        if (p.data_privacy_accepted) editPatient(id);
        else showClinicConsentModal(id, (p.first_name || '') + ' ' + (p.last_name || ''), 'edit');
    }, 'json');
}

// ============================================================
// EDIT PATIENT
// ============================================================
function editPatient(id) {
    if (!canEditPatients()) { Swal.fire('Access Denied', 'No permission to edit patients', 'error'); return; }
    $('#editPatientContent').html('<div class="text-center p-4"><div class="spinner-border" style="color:#0d9488;"></div></div>');
    new bootstrap.Modal(document.getElementById('editPatientModal')).show();
    $.get(baseUrl + 'api/patients.php?action=edit&id=' + id, function (r) {
        if (r.success) {
            $('#editPatientContent').html(r.html);
            $('#editPatientForm').off('submit').on('submit', function (e) { e.preventDefault(); updatePatient(id); });
        } else {
            bootstrap.Modal.getInstance(document.getElementById('editPatientModal')).hide();
            Swal.fire({ icon:'error', text:r.message });
        }
    }, 'json');
}

function updatePatient(id) {
    if (!canEditPatients()) { Swal.fire('Access Denied', 'No permission to update patients', 'error'); return; }
    const formData = {
        id:           $('#edit_patient_id').val(),
        first_name:   $('#edit_first_name').val(),
        last_name:    $('#edit_last_name').val(),
        age:          $('#edit_age').val(),
        gender:       $('#edit_gender').val(),
        phone:        $('#edit_phone').val(),
        address:      $('#edit_address').val(),
        patient_type: $('#edit_patient_type').val(),
        remarks:      $('#edit_remarks').val()
    };
    if (!formData.first_name || !formData.last_name || !formData.phone) {
        return Swal.fire({ icon:'error', text:'Please fill all required fields.', confirmButtonColor:'#0d9488' });
    }
    Swal.fire({ title:'Updating…', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() });
    $.ajax({
        url: baseUrl + 'api/patients.php', method:'PUT',
        data: JSON.stringify(formData), contentType:'application/json',
        success: function (r) {
            Swal.close();
            if (r.success) {
                bootstrap.Modal.getInstance(document.getElementById('editPatientModal')).hide();
                cleanupModals();
                table.draw(false);
                Swal.fire({ icon:'success', text:'Patient updated!', timer:1500, showConfirmButton:false });
            } else { Swal.fire({ icon:'error', text:r.message }); }
        },
        error: function () { Swal.close(); Swal.fire({ icon:'error', text:'Connection error.' }); }
    });
}

// ============================================================
// ARCHIVE / UNARCHIVE
// ============================================================
function archivePatient(id) {
    if (!canDeletePatients()) { Swal.fire('Access Denied', 'No permission to archive patients', 'error'); return; }
    Swal.fire({
        title:'Archive Patient?', text:'This can be reversed.',
        icon:'warning', showCancelButton:true,
        confirmButtonText:'Yes, Archive', confirmButtonColor:'#0d9488'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url:baseUrl+'api/patients.php', type:'PATCH', data:JSON.stringify({id,status:'Archived'}), contentType:'application/json',
            success: function () { table.draw(false); Swal.fire({ icon:'success', text:'Archived.', timer:1500 }); }
        });
    });
}

function unarchivePatient(id) {
    if (!canDeletePatients()) { Swal.fire('Access Denied', 'No permission to restore patients', 'error'); return; }
    Swal.fire({
        title:'Restore Patient?', icon:'question', showCancelButton:true,
        confirmButtonText:'Yes, Restore', confirmButtonColor:'#0d9488'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url:baseUrl+'api/patients.php', type:'PATCH', data:JSON.stringify({id,status:'Active'}), contentType:'application/json',
            success: function () { table.draw(false); Swal.fire({ icon:'success', text:'Restored.', timer:1500 }); }
        });
    });
}

// ============================================================
// EXPORT
// ============================================================
function exportPatientData(id) {
    if (!canViewPatients()) { Swal.fire('Access Denied', 'No permission to export patient data', 'error'); return; }
    Swal.fire({
        title:'Export Patient Data?', icon:'info',
        showCancelButton:true, confirmButtonText:'Export', confirmButtonColor:'#0d9488'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.get(baseUrl + 'api/patients.php?action=export&id=' + id, function (res) {
            if (res.success) {
                const blob = new Blob([JSON.stringify(res, null, 2)], { type:'application/json' });
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = 'patient_' + id + '_data_' + new Date().toISOString().slice(0,10) + '.json';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                Swal.fire({ icon:'success', text:'Data exported.', timer:1500 });
            } else { Swal.fire({ icon:'error', text:res.message }); }
        }, 'json');
    });
}

// ============================================================
// APPROVE / REJECT CONSENT
// ============================================================
function approveConsent(patientId) {
    if (!canApprovePatients()) { Swal.fire('Access Denied', 'No permission to approve consent', 'error'); return; }
    Swal.fire({
        title:'Approve Consent?', icon:'question', showCancelButton:true,
        confirmButtonText:'Yes, Approve', confirmButtonColor:'#0d9488'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url:baseUrl+'api/patients.php', type:'POST', contentType:'application/json',
            data: JSON.stringify({ action:'approve_consent', patient_id:patientId }),
            success: function (res) {
                if (res.success) { table.draw(false); Swal.fire({ icon:'success', text:res.message, timer:2000 }); }
                else { Swal.fire('Error', res.message, 'error'); }
            }
        });
    });
}

function rejectConsent(patientId) {
    if (!canRejectPatients()) { Swal.fire('Access Denied', 'No permission to reject consent', 'error'); return; }
    Swal.fire({
        title:'Reject Consent?',
        html:'<textarea id="rejReason" class="form-control" rows="3" placeholder="Reason…"></textarea>',
        icon:'warning', showCancelButton:true, confirmButtonText:'Yes, Reject', confirmButtonColor:'#dc3545',
        preConfirm: () => { const r = document.getElementById('rejReason').value; if (!r) { Swal.showValidationMessage('Reason is required'); return false; } return r; }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url:baseUrl+'api/patients.php', type:'POST', contentType:'application/json',
            data: JSON.stringify({ action:'reject_consent', patient_id:patientId, reason:r.value }),
            success: function (res) {
                if (res.success) { table.draw(false); Swal.fire({ icon:'success', text:res.message, timer:2000 }); }
                else { Swal.fire('Error', res.message, 'error'); }
            }
        });
    });
}

// ============================================================
// CLINIC CONSENT MODAL & HELPERS  (unchanged logic, teal confirm)
// ============================================================
function showClinicConsentModal(patientId, patientName, action = 'view') {
    $.get(baseUrl + 'api/patients.php?action=check_consent_eligibility&id=' + patientId, function (data) {
        if (!data.success) { Swal.fire({ icon:'error', text:data.message }); return; }
        if (data.has_consent) { action === 'view' ? viewPatient(patientId) : editPatient(patientId); return; }
        let methodsHtml = '<div class="row g-2 mt-3">';
        data.available_methods.forEach(method => {
            if (method.method === 'already_given') {
                methodsHtml += '<div class="col-12"><div class="alert alert-success"><i class="bi ' + method.icon + ' me-2"></i><strong>' + method.name + '</strong><br><small>' + method.description + '</small></div></div>';
            } else {
                methodsHtml += '<div class="col-12 col-md-6"><div class="card h-100 method-card" onclick="selectConsentMethod(\'' + method.method + '\',' + patientId + ',\'' + esc(patientName) + '\',\'' + action + '\')"><div class="card-body text-center"><i class="bi ' + method.icon + ' fs-1" style="color:#0d9488;"></i><h6 class="mt-2 mb-1">' + method.name + '</h6><small class="text-muted">' + method.description + '</small></div></div></div>';
            }
        });
        methodsHtml += '</div>';
        Swal.fire({
            title:'📋 Record Patient Consent',
            html:'<div class="text-start"><div class="alert alert-info mb-3"><i class="bi bi-shield-check me-2"></i><strong>RA 10173</strong><p class="mt-1 mb-0 small">Consent required before accessing records.</p></div><p><strong>Patient:</strong> ' + esc(patientName) + '</p>' + methodsHtml + '</div>',
            showConfirmButton:false, showCancelButton:true, cancelButtonText:'Cancel', width:'600px'
        });
    }, 'json');
}

function selectConsentMethod(method, patientId, patientName, action) {
    Swal.close();
    if (method === 'online') {
        $.get(baseUrl + 'api/patients.php?action=view&id=' + patientId, function (r) {
            if (!r.success || !r.patient || !r.patient.email) {
                Swal.fire({ icon:'error', title:'No Email Found', text:'Please update patient record with email first.', confirmButtonColor:'#0d9488' });
                return;
            }
            const patientEmail = r.patient.email;
            Swal.fire({
                title:'Send Online Consent',
                html:'<div class="text-start"><p>Send consent form to:</p><div class="input-group mb-3"><span class="input-group-text"><i class="bi bi-envelope"></i></span><input type="email" id="patientEmail" class="form-control" value="' + esc(patientEmail) + '" readonly></div><div class="alert alert-info small"><i class="bi bi-envelope-paper me-2"></i>Patient must click the link to give consent.</div></div>',
                showCancelButton:true, confirmButtonText:'Send Consent Form', confirmButtonColor:'#0d9488',
                preConfirm: () => { const e = document.getElementById('patientEmail').value; if (!e) { Swal.showValidationMessage('Email required'); return false; } return e; }
            }).then(result => { if (result.isConfirmed) recordConsentWithMethod(patientId, patientName, method, result.value, action); });
        }, 'json');
    } else {
        Swal.fire({
            title:'Confirm Consent',
            html:'<div class="text-start"><p><strong>Method:</strong> ' + (method === 'physical' ? 'Physical Presence' : 'Recent Appointment') + '</p><div class="alert alert-warning mt-3"><i class="bi bi-check-circle me-2"></i>Certify that the patient voluntarily gave consent and you explained their rights under RA 10173.</div></div>',
            icon:'question', showCancelButton:true, confirmButtonText:'Yes, Record Consent', confirmButtonColor:'#0d9488'
        }).then(result => { if (result.isConfirmed) recordConsentWithMethod(patientId, patientName, method, null, action); });
    }
}

function recordConsentWithMethod(patientId, patientName, method, email, action) {
    Swal.fire({ title:'Recording Consent…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'record_consent');
    fd.append('patient_id', patientId);
    fd.append('consent_method', method);
    if (email) fd.append('patient_email', email);
    fetch(baseUrl + 'api/patients.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon:'success', title:'Consent Recorded!', html:'<p>' + data.message + '</p>', timer:2000, showConfirmButton:false })
                    .then(() => { if (table) table.draw(false); if (action === 'view') viewPatient(patientId); else if (action === 'edit') editPatient(patientId); });
            } else { Swal.fire({ icon:'error', title:'Error', text:data.message }); }
        })
        .catch(() => Swal.fire({ icon:'error', text:'Connection error.' }));
}

function resendConsentEmail(patientId, patientEmail) {
    Swal.fire({ title:'Sending…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
    const fd = new FormData();
    fd.append('action', 'resend_consent');
    fd.append('patient_id', patientId);
    fd.append('patient_email', patientEmail);
    fetch(baseUrl + 'api/patients.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) Swal.fire({ icon:'success', text:'Consent email resent!', timer:2000 });
            else Swal.fire({ icon:'error', text:data.message });
        });
}

// ============================================================
// CLINICAL NOTES / PRESCRIPTIONS / APPOINTMENTS (unchanged logic)
// ============================================================
function loadPatientClinicalNotes(patientId) {
    $.get(baseUrl + 'api/patients.php?action=get_clinical_notes&id=' + patientId, function (r) {
        if (!r.success || !r.notes || r.notes.length === 0) {
            $('#vNotes-content').html('<div class="text-center py-5 text-muted"><i class="bi bi-journal-medical d-block mb-2 opacity-25" style="font-size:2rem;"></i><small>No clinical notes found</small></div>');
            return;
        }
        let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead class="table-light"><tr><th>Date</th><th>Complaint</th><th>Diagnosis</th><th>VA L/R</th><th>Doctor</th><th>Status</th></tr></thead><tbody>';
        r.notes.forEach(n => {
            const dx = n.diagnosis ? '<span class="badge" style="background:#0d9488;font-size:.7rem;">' + n.diagnosis + '</span>' : '—';
            const va = (n.va_left || n.va_right) ? (n.va_left || '—') + ' / ' + (n.va_right || '—') : '—';
            const sb = n.status === 'completed' ? '<span class="badge bg-success" style="font-size:.65rem;">Completed</span>' : '<span class="badge bg-warning text-dark" style="font-size:.65rem;">Draft</span>';
            html += '<tr><td><small class="fw-600">' + (n.formatted_date || '—') + '</small></td><td><small>' + (n.chief_complaint ? n.chief_complaint.substring(0,50) + (n.chief_complaint.length > 50 ? '…' : '') : '—') + '</small></td><td>' + dx + '</td><td><small>' + va + '</small></td><td><small>Dr. ' + (n.doctor_name || 'Unknown') + '</small></td><td>' + sb + '</td></tr>';
        });
        html += '</tbody></table></div><div class="text-muted small px-1">Showing ' + r.notes.length + ' record(s)</div>';
        $('#vNotes-content').html(html);
    }, 'json').fail(function () { $('#vNotes-content').html('<div class="alert alert-danger small py-2">Failed to load clinical notes.</div>'); });
}

function loadPatientPrescriptions(patientId) {
    $.get(baseUrl + 'api/patients.php?action=get_prescriptions&id=' + patientId, function (r) {
        if (!r.success || !r.prescriptions || r.prescriptions.length === 0) {
            $('#vRx-content').html('<div class="text-center py-5 text-muted"><i class="bi bi-prescription2 d-block mb-2 opacity-25" style="font-size:2rem;"></i><small>No prescriptions found</small></div>');
            return;
        }
        window._patientRxHistory = r.prescriptions;
        let html = '';
        r.prescriptions.forEach(function (rx, idx) {
            const expBadge = rx.expiry_date ? '<span class="badge bg-light text-muted border" style="font-size:.62rem;">Expires: ' + rx.expiry_date + '</span>' : '';
            html += '<div class="mb-3 rounded-3 overflow-hidden" style="border:1px solid #e2e8f0;"><div class="d-flex justify-content-between align-items-center px-3 py-2" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;"><span class="fw-700 small" style="color:#0d9488;"><i class="bi bi-prescription2 me-1"></i>' + (rx.formatted_date || '—') + '</span><div class="d-flex align-items-center gap-2">' + expBadge + '<span class="small text-muted">Dr. ' + (rx.doctor_name || 'Unknown') + '</span><button class="btn btn-sm py-0 px-2" style="font-size:.7rem;border:1px solid #0d9488;color:#0d9488;" onclick="printRxFromPatientView(' + idx + ')"><i class="bi bi-printer"></i> Print</button></div></div><div class="p-3"><div class="row g-2 mb-2"><div class="col-6"><div class="p-2 rounded-3" style="background:#f0fdfa;border:1px solid #99f6e4;"><div class="fw-800 mb-2" style="font-size:.67rem;color:#0d9488;">OD (Right)</div><div class="row g-1 text-center"><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">SPH</div><div class="fw-700 small">' + (rx.sph_r || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">CYL</div><div class="fw-700 small">' + (rx.cyl_r || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">AXIS</div><div class="fw-700 small">' + (rx.axis_r || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">ADD</div><div class="fw-700 small">' + (rx.add_r || '—') + '</div></div></div></div></div><div class="col-6"><div class="p-2 rounded-3" style="background:#f0fdfa;border:1px solid #99f6e4;"><div class="fw-800 mb-2" style="font-size:.67rem;color:#0d9488;">OS (Left)</div><div class="row g-1 text-center"><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">SPH</div><div class="fw-700 small">' + (rx.sph_l || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">CYL</div><div class="fw-700 small">' + (rx.cyl_l || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">AXIS</div><div class="fw-700 small">' + (rx.axis_l || '—') + '</div></div><div class="col-3"><div style="font-size:.6rem;color:#94a3b8;">ADD</div><div class="fw-700 small">' + (rx.add_l || '—') + '</div></div></div></div></div></div></div></div>';
        });
        html += '<div class="text-muted small px-1">Showing ' + r.prescriptions.length + ' record(s)</div>';
        $('#vRx-content').html(html);
    }, 'json').fail(function () { $('#vRx-content').html('<div class="alert alert-danger small py-2">Failed to load prescriptions.</div>'); });
}

function loadPatientAppointments(patientId) {
    $.get(baseUrl + 'api/patients.php?action=get_appointments&id=' + patientId, function (r) {
        if (!r.success || !r.appointments || r.appointments.length === 0) {
            $('#vAppts-content').html('<div class="text-center py-5 text-muted"><i class="bi bi-calendar-x d-block mb-2 opacity-25" style="font-size:2rem;"></i><small>No appointments found</small></div>');
            return;
        }
        const statusMap = { completed:{bg:'#0d9488',label:'Completed'}, in_progress:{bg:'#0891b2',label:'In Progress'}, waiting:{bg:'#f59e0b',label:'Waiting'}, cancelled:{bg:'#6b7280',label:'Cancelled'} };
        let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light" style="font-size:.74rem;color:#64748b;text-transform:uppercase;"><tr><th>Date & Time</th><th>Service</th><th>Doctor</th><th>Amount</th><th>Payment</th><th>Status</th></tr></thead><tbody>';
        r.appointments.forEach(appt => {
            const sKey = appt.status_normalized || '';
            const sInfo = statusMap[sKey] || { bg:'#94a3b8', label:appt.status || '—' };
            html += '<tr style="font-size:.8rem;"><td><div class="fw-600">' + (appt.formatted_date || '—') + '</div><div class="text-muted" style="font-size:.72rem;">' + (appt.appointment_time_formatted || '—') + '</div></td><td>' + (appt.product_name || '—') + '</td><td>' + (appt.doctor_name ? 'Dr. ' + appt.doctor_name : '—') + '</td><td>' + (appt.total_amount_formatted || '—') + '</td><td><span class="badge" style="font-size:.65rem;background:' + (appt.payment_status === 'paid' ? '#0d9488' : '#94a3b8') + ';">' + (appt.payment_status || '—') + '</span></td><td><span class="badge" style="font-size:.65rem;background:' + sInfo.bg + ';">' + sInfo.label + '</span></td></tr>';
        });
        html += '</tbody></table></div><div class="text-muted small px-2 pt-2">Showing ' + r.appointments.length + ' record(s)</div>';
        $('#vAppts-content').html(html);
    }, 'json').fail(function () { $('#vAppts-content').html('<div class="alert alert-danger small py-2">Failed to load appointments.</div>'); });
}

// ============================================================
// UTILITIES
// ============================================================
function esc(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }

function cleanupModals() {
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal').forEach(el => {
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    });
}

function printRxFromPatientView(index) {
    const rx = window._patientRxHistory && window._patientRxHistory[index];
    if (!rx) { Swal.fire('Error', 'Prescription data not found.', 'error'); return; }
    const patientName = $('#vPatientName').text() || '—';
    const doctorName  = rx.doctor_name || '—';
    const issuedRaw   = rx.prescription_date || rx.formatted_date || null;
    const issued      = issuedRaw ? new Date(issuedRaw).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }) : new Date().toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
    const expiry      = rx.expiry_date ? new Date(rx.expiry_date).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }) : (() => { const d = issuedRaw ? new Date(issuedRaw) : new Date(); d.setFullYear(d.getFullYear() + 1); return d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }); })();

    const win = window.open('', '_blank', 'width=340,height=720,scrollbars=yes');
    if (!win) { Swal.fire('Blocked', 'Please allow popups for this site.', 'warning'); return; }
    win.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Rx</title><style>body{font-family:monospace;width:302px;margin:0 auto;padding:10px;font-size:9px;} .btn{display:block;width:100%;padding:8px;background:#0d9488;color:#fff;border:none;border-radius:5px;cursor:pointer;margin-top:12px;} @media print{.btn{display:none}}</style></head><body>');
    win.document.write('<div style="text-align:center;border-bottom:1px dashed #ccc;padding-bottom:7px;margin-bottom:7px;"><div style="font-size:12px;font-weight:900;color:#0d9488;">👁 EYECORE OPTICAL CLINIC</div><div style="font-size:8px;color:#64748b;">EyeCore PH &middot; eyecoreph.com</div></div>');
    win.document.write('<div style="font-size:8px;"><strong>Patient:</strong> ' + patientName + '</div><hr style="border:none;border-top:1px dashed #ccc;margin:5px 0;">');
    win.document.write('<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f0fdfa;"><th style="font-size:7px;color:#0d9488;padding:3px;text-align:left;">Eye</th><th style="font-size:7px;color:#0d9488;padding:3px;text-align:center;">SPH</th><th style="font-size:7px;color:#0d9488;padding:3px;text-align:center;">CYL</th><th style="font-size:7px;color:#0d9488;padding:3px;text-align:center;">AXIS</th><th style="font-size:7px;color:#0d9488;padding:3px;text-align:center;">ADD</th></tr></thead><tbody>');
    win.document.write('<tr><td style="padding:3px;font-size:8px;color:#0d9488;">OD</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.sph_r || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.cyl_r || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.axis_r || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.add_r || '—') + '</td></tr>');
    win.document.write('<tr><td style="padding:3px;font-size:8px;color:#0891b2;">OS</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.sph_l || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.cyl_l || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.axis_l || '—') + '</td><td style="text-align:center;padding:3px;font-size:9px;font-weight:700;">' + (rx.add_l || '—') + '</td></tr>');
    win.document.write('</tbody></table>');
    if (rx.pd) win.document.write('<div style="font-size:8px;margin-top:5px;"><strong>PD:</strong> ' + rx.pd + ' (' + (rx.pd_type || 'Binocular') + ')</div>');
    if (rx.notes) win.document.write('<div style="font-size:8px;margin-top:3px;"><strong>Notes:</strong> ' + rx.notes + '</div>');
    win.document.write('<hr style="border:none;border-top:1px solid #0d9488;margin:5px 0;">');
    win.document.write('<div style="display:flex;justify-content:space-between;font-size:8px;"><span><strong>Issued:</strong> ' + issued + '</span><span><strong>Expires:</strong> ' + expiry + '</span></div>');
    win.document.write('<div style="text-align:center;margin-top:10px;"><div style="border-bottom:1px solid #000;width:120px;margin:16px auto 3px;"></div><div style="font-size:9px;font-weight:700;">Dr. ' + doctorName + '</div><div style="font-size:7px;color:#64748b;">Optometrist / Ophthalmologist</div></div>');
    win.document.write('<button class="btn" onclick="window.print()">🖨 PRINT PRESCRIPTION</button>');
    win.document.write('</body></html>');
    win.document.close();
}