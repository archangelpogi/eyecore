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
if (!RBACHelper::hasPermission('decision-support_view')) { exit('Access Denied'); }
$canView = RBACHelper::hasPermission('decision-support_view');
$canCreate = RBACHelper::hasPermission('decision-support_create');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optical Decision Support - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --teal: #008080; --teal-dark: #006666; }
        body { background: #f0f4f4; font-family: 'Inter', sans-serif; }
        .btn-teal { background: var(--teal); color: white; border: none; padding: 8px 20px; border-radius: 8px; transition: all 0.2s; }
        .btn-teal:hover { background: var(--teal-dark); color: white; }
        .bg-teal { background-color: var(--teal) !important; }
        .text-teal { color: var(--teal) !important; }
        .rx-box { font-family: monospace; background: #fff; border: 1px solid #ddd; padding: 10px; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 text-white shadow" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark));">
                <div class="card-body p-4 d-flex align-items-center flex-wrap gap-3">
                    <div class="bg-white bg-opacity-25 rounded-3 p-3"><i class="bi bi-eye fs-1"></i></div>
                    <div class="flex-grow-1">
                        <h3 class="fw-bold mb-1">Optical Decision Support</h3>
                        <p class="mb-0 opacity-75">Basic Optical Diagnostics & Discount Eligibility Assistant.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3"><h5 class="mb-0"><i class="bi bi-search me-2 text-teal"></i>Select Patient to Analyze</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <select id="analysis_patient_id" class="form-select form-select-lg" onchange="loadPatientData()">
                                <option value="">🔍 Search or select patient...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-teal w-100 h-100" onclick="analyzePatient()"><i class="bi bi-cpu me-2"></i>Run Analysis</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4" id="patientDataCard" style="display: none;">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-vcard me-2 text-teal"></i>Patient Profile & Latest Rx</h5>
                    <span class="badge bg-teal" id="patientName"></span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="bg-light p-3 rounded-3 mb-3">
                                <small class="text-muted d-block">Demographics</small>
                                <h6 class="fw-bold mb-0" id="patientAgeGender">-</h6>
                                <small class="text-muted" id="patientType"></small>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="rx-box" id="rxDetails"><small class="text-muted d-block mb-1">Latest Prescription</small><span class="text-muted">No recent Rx found.</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm" id="recommendationsCard" style="display: none;">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clipboard-check me-2 text-success"></i>Analysis Results</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="printSummary()"><i class="bi bi-printer me-1"></i>Print</button>
                        <button class="btn btn-sm btn-teal" onclick="sendEmailToPatient()"><i class="bi bi-envelope-paper me-1"></i>Email</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="discountSection" class="mb-4"></div>
                    <div id="opticalSection"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3"><h5 class="mb-0"><i class="bi bi-book me-2 text-success"></i>Optical Guidelines</h5></div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item border-0 py-3"><i class="bi bi-dot text-teal fs-4"></i><strong>Astigmatism:</strong> Suggest AR coating.</div>
                        <div class="list-group-item border-0 py-3"><i class="bi bi-dot text-teal fs-4"></i><strong>High Myopia:</strong> Recommend High-Index (1.67+).</div>
                        <div class="list-group-item border-0 py-3"><i class="bi bi-dot text-teal fs-4"></i><strong>Presbyopia:</strong> Progressive lenses.</div>
                        <div class="list-group-item border-0 py-3"><i class="bi bi-dot text-teal fs-4"></i><strong>Digital Strain:</strong> Blue light filter.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const permissions = { canView: <?= json_encode($canView) ?>, canCreate: <?= json_encode($canCreate) ?> };
let currentPatientEmail = "";
let currentPatientFullName = "";

$(document).ready(function() {
    $.get('api/decision_support.php?action=get_patients', function(data) {
        let options = '<option value="">🔍 Search or select patient...</option>';
        data.forEach(p => { options += `<option value="${p.id}">${escapeHtml(p.name)} (${p.patient_code}) - ${p.age}y</option>`; });
        $('#analysis_patient_id').html(options);
    });
});

function loadPatientData() {
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) { $('#patientDataCard').hide(); $('#recommendationsCard').hide(); return; }

    $.get('api/decision_support.php?action=get_patient_data', { patient_id: patientId }, function(data) {
        if (data.success && data.patient) {
            $('#patientDataCard').show();
            currentPatientFullName = data.patient.first_name + ' ' + data.patient.last_name;
            currentPatientEmail = data.user_email || "";
            $('#patientName').text(currentPatientFullName);
            $('#patientAgeGender').text(`${data.patient.age} yrs / ${data.patient.gender}`);
            $('#patientType').text(data.patient.patient_type || 'Regular');

            if (data.optical) {
                let opt = data.optical;
                $('#rxDetails').html(`<small class="text-muted d-block mb-1">Latest Optical Record (${new Date(opt.examination_date).toLocaleDateString()})</small><div class="row text-center fw-bold"><div class="col-6 border-end"><div class="text-muted small">OD (Right)</div><div>SPH: ${opt.od_sph || '0.00'}</div><div>CYL: ${opt.od_cyl || '0.00'}</div><div>AXIS: ${opt.od_axis || '---'}</div></div><div class="col-6"><div class="text-muted small">OS (Left)</div><div>SPH: ${opt.os_sph || '0.00'}</div><div>CYL: ${opt.os_cyl || '0.00'}</div><div>AXIS: ${opt.os_axis || '---'}</div></div></div>`);
            } else if (data.rx) {
                let rx = data.rx;
                $('#rxDetails').html(`<small class="text-muted d-block mb-1">Latest Prescription (${new Date(rx.prescription_date).toLocaleDateString()})</small><div class="row text-center fw-bold"><div class="col-6 border-end"><div class="text-muted small">OD (Right)</div><div>SPH: ${rx.sph_r || 'PL'}</div><div>CYL: ${rx.cyl_r || '---'}</div></div><div class="col-6"><div class="text-muted small">OS (Left)</div><div>SPH: ${rx.sph_l || 'PL'}</div><div>CYL: ${rx.cyl_l || '---'}</div></div></div>`);
            } else {
                $('#rxDetails').html(`<span class="text-muted">No recent Optical Record or Prescription found.</span>`);
            }
        }
    });
}

function analyzePatient() {
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) { Swal.fire('Notice', 'Please select a patient first.', 'info'); return; }
    Swal.fire({ title: 'Analyzing data...', didOpen: () => Swal.showLoading() });

    $.ajax({
        url: 'api/decision_support.php?action=analyze',
        method: 'POST',
        data: JSON.stringify({ patient_id: patientId }),
        contentType: 'application/json',
        success: function(data) {
            Swal.close();
            window.lastAnalysisData = data;
            $('#recommendationsCard').show();
            
            let discountHtml = '';
            if (data.is_eligible) {
                let stepsHtml = data.discount_steps.map(step => {
                    if (step.startsWith('<strong>')) return `<div class="fw-bold mt-3 mb-1 text-success">${step}</div>`;
                    return `<li class="mb-1">${step}</li>`;
                }).join('');
                discountHtml = `<div class="alert alert-success border-success d-flex align-items-start"><i class="bi bi-check-circle-fill me-3 fs-4 mt-1"></i><div class="w-100"><h6 class="fw-bold mb-1">Discount Eligible</h6><p class="mb-2 small">${data.discount_message}</p><div class="bg-white p-3 rounded border border-success border-opacity-50"><h6 class="small fw-bold text-success mb-2">Action Plan:</h6><ul class="mb-0 small text-start ps-3">${stepsHtml}</ul></div></div></div>`;
            } else {
                discountHtml = `<div class="alert alert-secondary d-flex align-items-center"><i class="bi bi-info-circle-fill me-3 fs-4"></i><div><strong>No Discount</strong><br><small>${data.discount_message}</small></div></div>`;
            }
            $('#discountSection').html(discountHtml);

            let opticalHtml = `<h6 class="fw-bold mb-3"><i class="bi bi-eyeglasses text-primary me-2"></i>Basic Optical Recommendations</h6>`;
            if (data.optical_findings.length > 0) {
                data.optical_findings.forEach(finding => { opticalHtml += `<div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i><strong>${finding}</strong></div>`; });
            }
            opticalHtml += `<div class="card bg-light border-0 mt-3"><div class="card-body p-3"><h6 class="fw-bold small text-muted mb-2">Suggested Actions:</h6><ul class="mb-0 small text-start ps-3">`;
            data.optical_steps.forEach(step => { opticalHtml += `<li class="mb-1">${step}</li>`; });
            opticalHtml += `</ul></div></div>`;
            $('#opticalSection').html(opticalHtml);
        }
    });
}

// ✅ PROFESSIONAL PRINT LAYOUT
function printSummary() {
    const discountHtml = $('#discountSection').html();
    const opticalHtml = $('#opticalSection').html();
    const today = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Optical Summary - ${currentPatientFullName}</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
                body { font-family: 'Inter', Arial, sans-serif; padding: 40px; color: #1f2937; line-height: 1.5; }
                .header { text-align: center; border-bottom: 3px solid #0d9488; padding-bottom: 20px; margin-bottom: 30px; }
                .header h1 { color: #0f766e; margin: 0 0 5px 0; font-size: 26px; text-transform: uppercase; letter-spacing: 1px; }
                .header p { margin: 0; color: #6b7280; font-size: 14px; }
                .header .patient-info { font-size: 16px; font-weight: 600; color: #111827; margin-top: 15px; }
                .header .date { font-size: 12px; color: #9ca3af; margin-top: 5px; }
                .alert { padding: 15px 20px; margin-bottom: 20px; border-radius: 8px; page-break-inside: avoid; }
                .alert-success { background: #f0fdf4; border-left: 5px solid #10b981; }
                .alert-secondary { background: #f3f4f6; border-left: 5px solid #9ca3af; }
                .alert-warning { background: #fffbeb; border-left: 5px solid #f59e0b; }
                h5, h6 { margin: 0 0 8px 0; color: #111827; }
                .alert-success h6 { color: #065f46; font-size: 16px; }
                .alert-secondary h6 { color: #374151; font-size: 16px; }
                .alert-warning h6 { color: #92400e; font-size: 15px; }
                ul { margin: 0; padding-left: 20px; }
                li { margin-bottom: 6px; color: #4b5563; }
                strong { color: #111827; }
                .section-title { font-size: 18px; font-weight: 700; color: #0d9488; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; margin: 30px 0 15px 0; }
                .footer { margin-top: 50px; padding-top: 15px; border-top: 1px solid #e5e7eb; font-size: 11px; text-align: center; color: #9ca3af; }
                @media print { body { padding: 0; } @page { margin: 2cm; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Optical Summary</h1>
                <p>Patient Examination & Recommendations</p>
                <div class="patient-info">Patient Name: ${currentPatientFullName}</div>
                <div class="date">Date Generated: ${today}</div>
            </div>
            ${discountHtml}
            <div class="section-title">Basic Optical Recommendations</div>
            ${opticalHtml}
            <div class="footer">
                This document is generated by the Eyecore System on behalf of the clinic.<br>
                Please present this summary to your optometrist for your next visit.
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); printWindow.close(); }, 500);
}

function sendEmailToPatient() {
    const patientId = $('#analysis_patient_id').val();
    if (!patientId) { Swal.fire('Notice', 'Please select a patient first.', 'info'); return; }
    if (!currentPatientEmail) { Swal.fire('No Email', 'This patient does not have a registered email address in the system.', 'warning'); return; }

    Swal.fire({
        title: 'Send Summary via Email?',
        html: `The summary will be sent to:<br><strong>${currentPatientEmail}</strong>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: '<i class="bi bi-send me-1"></i> Send',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            const payload = {
                patient_id: patientId,
                is_eligible: window.lastAnalysisData ? window.lastAnalysisData.is_eligible : false,
                discount_message: window.lastAnalysisData ? window.lastAnalysisData.discount_message : '',
                discount_steps: window.lastAnalysisData ? window.lastAnalysisData.discount_steps : [],
                optical_findings: window.lastAnalysisData ? window.lastAnalysisData.optical_findings : [],
                optical_steps: window.lastAnalysisData ? window.lastAnalysisData.optical_steps : []
            };

            return fetch('api/decision_support.php?action=send_email', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const data = JSON.parse(text);
                    if (!data.success) throw new Error(data.message || 'Failed to send email');
                    return data;
                } catch (err) {
                    throw new Error("Server Error: " + text.substring(0, 150) + "...");
                }
            })
            .catch(error => {
                Swal.showValidationMessage(`Error: ${error.message}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('Sent!', 'The summary has been sent to the patient\'s email.', 'success');
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return $('<div>').text(text).html();
}
</script>
</body>
</html>