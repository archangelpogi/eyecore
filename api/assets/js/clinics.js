const BASE_URL = 'https://eyecore.capstone001.com';
let clinicsTable;
let currentClinicId = null;
let currentClinicName = null;
let currentDocId = null;
let currentStatusTab = '';
let viewedDocs = new Set(); // Track which documents have been viewed

$(document).ready(function() {
    loadStats();
    initializeDataTable();
    
    // Clear viewed docs when modal is closed
    $('#viewModal').on('hidden.bs.modal', function() {
        viewedDocs.clear();
    });
});

function loadStats() {
    fetch(`${BASE_URL}/api/clinics_api.php?action=get_stats`, {
        credentials: 'include'
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pendingClinics').textContent = data.data.pending || 0;
                document.getElementById('activeClinics').textContent = data.data.active || 0;
                document.getElementById('suspendedClinics').textContent = data.data.suspended || 0;
                document.getElementById('totalClinics').textContent = data.data.total_clinics || 0;
            }
        })
        .catch(err => console.error(err));
}

function initializeDataTable() {
    clinicsTable = $('#clinicsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: `${BASE_URL}/api/clinics_api.php?action=get_clinics_datatable`,
            type: 'POST',
            xhrFields: {
                withCredentials: true
            },
            data: function(d) {
                d.status = currentStatusTab || $('#statusFilter').val();
                d.risk = $('#riskFilter').val();
                d.score = $('#scoreFilter').val();
                d.search_text = $('#globalSearch').val();
            }
        },
        columns: [
            {
                data: null,
                render: function(data, type, row) {
                    return `<input type="checkbox" class="clinic-checkbox" value="${row.id}">`;
                },
                orderable: false
            },
            {
                data: null,
                render: function(data, type, row) {
                    return `<div class="fw-semibold">${escapeHtml(row.clinic_name)}</div>`;
                }
            },
            {
                data: null,
                className: 'd-none d-md-table-cell',
                render: function(data, type, row) {
                    return `
                        <div><i class="bi bi-telephone"></i> ${escapeHtml(row.contact || 'No contact')}</div>
                        <small class="text-muted"><i class="bi bi-envelope"></i> ${escapeHtml(row.clinic_email || 'No email')}</small>
                    `;
                }
            },
            {
                data: null,
                render: function(data, type, row) {
                    const score = row.verification_score || 0;
                    const scoreClass = getScoreClass(score);
                    return `
                        <span class="score-badge ${scoreClass}">${score}%</span>
                        <div class="small text-muted mt-1">
                            ${row.staff_count || 0} staff<br>
                            ${row.patient_count || 0} patients
                        </div>
                    `;
                }
            },
            {
                data: 'risk_level',
                render: function(data, type, row) {
                    const riskLevel = data || 'medium';
                    const riskClass = riskLevel === 'high' ? 'danger' : riskLevel === 'medium' ? 'warning' : 'success';
                    return `<span class="badge bg-${riskClass}">${riskLevel.toUpperCase()}</span>`;
                }
            },
            {
                data: 'status',
                render: function(data, type, row) {
                    let statusClass = getStatusClass(data);
                    let badgeHtml = `<span class="badge ${statusClass}">${escapeHtml(data)}</span>`;

                    // 🔔 IF RE-SUBMITTED
                    if (data === 'Reapplying') {
                        badgeHtml += `
                            <span class="badge bg-info ms-1">
                                Re-submitted
                            </span>
                        `;
                    }

                    return `
                        ${badgeHtml}
                        <div class="small text-muted mt-1">
                            ${formatDate(row.created_at)}
                        </div>
                    `;
                }
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                render: function(data, type, row) {
                    return `
                        <button class="btn btn-sm btn-outline-primary"
                            onclick="viewClinic(${row.id})"
                            title="View Clinic">
                            <i class="bi bi-eye"></i>
                        </button>
                    `;
                }
            }
        ],
        columnDefs: [
            {
                targets: 0,
                className: 'dt-body-center',
                searchable: false,
                orderable: false
            },
            {
                targets: [0, 6],
                searchable: false,
                orderable: false
            }
        ],
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[5, 'asc'], [3, 'desc']],
        language: {
            search: "",
            searchPlaceholder: "Search clinics...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ clinics",
            infoEmpty: "No clinics found",
            infoFiltered: "(filtered from _MAX_ total clinics)",
            zeroRecords: "No matching clinics found",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        createdRow: function(row, data, dataIndex) {
            const riskLevel = data.risk_level || 'medium';
            $(row).addClass(`risk-${riskLevel}`);

            // IF RE-SUBMITTED (status = Reapplying)
            if (data.status === 'Reapplying') {
                $(row).addClass('table-info'); // Bootstrap light blue
            }
        }
    });

    $('#globalSearch').on('keyup', function() {
        clinicsTable.search(this.value).draw();
    });
}


function filterTable() {
    clinicsTable.ajax.reload();
}

function setStatusTab(status) {
    currentStatusTab = status;
    $('#clinicTabs button').removeClass('active');
    $(event.target).addClass('active');
    clinicsTable.ajax.reload();
}

// ==========================
// VIEW CLINIC MODAL
// ==========================
function viewClinic(id, name) {
    currentClinicId = id;
    currentClinicName = name;
    viewedDocs.clear(); // Reset viewed docs when opening modal

    fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`, {
        credentials: 'include'
    })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return alert('Error loading clinic details');
            const clinic = data.data;
            renderClinicModal(clinic);
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        })
        .catch(err => {
            console.error(err);
            alert('Network error loading clinic details');
        });
}

// ==========================
// Preview and enable buttons
// ==========================
function previewAndEnableButtons(docId, filePath, docType) {
    // First, preview the document
    previewDocument(filePath, docType);
    
    // Mark this document as viewed
    viewedDocs.add(docId);
    
    // Enable approve and reject buttons for this document
    const approveBtn = document.getElementById(`approveBtn${docId}`);
    const rejectBtn = document.getElementById(`rejectBtn${docId}`);
    
    if (approveBtn && approveBtn.disabled) {
        approveBtn.disabled = false;
        approveBtn.classList.add('btn-pulse');
        setTimeout(() => approveBtn.classList.remove('btn-pulse'), 500);
    }
    
    if (rejectBtn && rejectBtn.disabled) {
        rejectBtn.disabled = false;
        rejectBtn.classList.add('btn-pulse');
        setTimeout(() => rejectBtn.classList.remove('btn-pulse'), 500);
    }
    
    showToast('info', 'Document viewed. Approve/Reject buttons are now enabled.');
}

// ==========================
// RENDER CLINIC MODAL
// ==========================
function renderClinicModal(clinic) {
    const uploadedDocs = clinic.documents || [];

    const updateClinicButtons = () => {
        const allApprovedDocs = uploadedDocs.length > 0 && uploadedDocs.every(d => d.status === 'Approved');
        const anyPendingDocs = uploadedDocs.some(d => !d.status || d.status === 'Pending');
        const anyRejectedDocs = uploadedDocs.some(d => d.status === 'Rejected');

        document.getElementById('approveClinicBtn').disabled = !(allApprovedDocs && clinic.status !== 'Active');
        document.getElementById('suspendClinicBtn').disabled = !(allApprovedDocs && clinic.status !== 'Suspended');
        document.getElementById('rejectClinicBtn').disabled = !(anyPendingDocs || anyRejectedDocs);
    };

    let documentsHtml = '';
    if (uploadedDocs.length > 0) {
        documentsHtml += `<h6 class="mt-3">Uploaded Documents (${uploadedDocs.length})</h6>
                          <div class="list-group mb-3">`;

        const today = new Date();

        uploadedDocs.forEach(doc => {
            const isApproved = doc.status === 'Approved';
            const isRejected = doc.status === 'Rejected';
            const isPending = !isApproved && !isRejected;
            
            const hasBeenViewed = viewedDocs.has(doc.id);
            
            const disableApprove = isApproved || (isPending && !hasBeenViewed);
            const disableReject = isRejected || isApproved || (isPending && !hasBeenViewed);

            let expiryClass = '';
            let expiryTooltip = '';
            if (doc.expires_at) {
                const expiryDate = new Date(doc.expires_at);
                const diffTime = expiryDate - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                if (expiryDate < today) {
                    expiryClass = 'text-danger';
                    expiryTooltip = `Expired ${Math.abs(diffDays)} day(s) ago`;
                } else if (diffDays <= 30) {
                    expiryClass = 'text-warning';
                    expiryTooltip = `Expires in ${diffDays} day(s)`;
                }
            }

            documentsHtml += `
<div class="list-group-item p-3 mb-2 rounded shadow-sm doc-item ${expiryClass}" id="docItem${doc.id}" style="transition: background 0.2s;">
    <div class="d-flex justify-content-between align-items-start flex-wrap">
        <div class="doc-info me-3 flex-grow-1">
            <div class="fw-semibold">${escapeHtml(doc.document_type)}</div>
            <small class="text-muted">
                Uploaded: ${formatDate(doc.uploaded_at)}<br>
                Owner: ${escapeHtml(doc.owner_name || '-')}<br>
                Release: ${doc.released_at ? formatDate(doc.released_at) : '-'}<br>
                Expiry: 
                ${doc.expires_at ? `<span data-bs-toggle="tooltip" title="${expiryTooltip}">${formatDate(doc.expires_at)}</span>` : '-'}
                ${doc.rejection_reason ? `<br><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(doc.rejection_reason)}</span>` : ''}
            </small>
        </div>

        <div class="doc-actions d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2 mt-2 mt-md-0">
            <span id="docStatusBadge${doc.id}" class="badge ${isApproved ? 'bg-success' : isRejected ? 'bg-danger' : 'bg-warning'}">
                ${escapeHtml(doc.status || 'Pending')}
            </span>

            <div class="d-flex flex-column flex-md-row gap-1">
                <button type="button" class="btn btn-success btn-sm ${!hasBeenViewed && isPending ? 'btn-disabled-initially' : ''}" 
                    id="approveBtn${doc.id}" 
                    onclick="reviewDocument(${doc.id}, 'Approved')" 
                    ${disableApprove ? 'disabled' : ''}>
                    <i class="bi bi-check"></i> Approve
                </button>
                <button type="button" class="btn btn-danger btn-sm ${!hasBeenViewed && isPending ? 'btn-disabled-initially' : ''}" 
                    id="rejectBtn${doc.id}" 
                    onclick="showRejectModal(${doc.id})" 
                    ${disableReject ? 'disabled' : ''}>
                    <i class="bi bi-x"></i> Reject
                </button>
                ${doc.file_path ? `<button type="button" class="btn btn-outline-primary btn-sm" onclick="previewAndEnableButtons(${doc.id}, '${escapeHtml(doc.file_path)}', '${escapeHtml(doc.document_type)}')">
                    <i class="bi bi-eye"></i> View
                </button>` : ''}
            </div>
        </div>
    </div>
</div>`;
        });

        documentsHtml += `</div>`;
    } else {
        documentsHtml += `<div class="alert alert-secondary mt-3">No documents uploaded.</div>`;
    }

    document.getElementById('viewModalTitle').textContent = `Clinic Verification – ${escapeHtml(clinic.clinic_name)}`;
    document.getElementById('clinicDetails').innerHTML = `
    <div class="row">
        <div class="col-md-6">
            <h6>Clinic Information</h6>
            <p><strong>Code:</strong> ${escapeHtml(clinic.clinic_code)}</p>
            <p><strong>Name:</strong> ${escapeHtml(clinic.clinic_name)}</p>
            <p><strong>Email:</strong> ${escapeHtml(clinic.clinic_email)}</p>
            <p><strong>Contact:</strong> ${escapeHtml(clinic.contact)}</p>
            <p><strong>Address:</strong> ${escapeHtml(clinic.address)}</p>
            <p><strong>City:</strong> ${escapeHtml(clinic.city)}</p>
            <p><strong>Branch:</strong> ${escapeHtml(clinic.branch || 'N/A')}</p>
            <p><strong>Clinic Type:</strong> ${escapeHtml(clinic.clinic_type)}</p>
            <p><strong>Hospital Name:</strong> ${escapeHtml(clinic.hospital_name || 'N/A')}</p>
            <p><strong>Hospital Address:</strong> ${escapeHtml(clinic.hospital_address || 'N/A')}</p>
            <p><strong>Offers Eye Surgery:</strong> ${clinic.offers_eye_surgery ? 'Yes' : 'No'}</p>
        </div>

        <div class="col-md-6">
            <h6>Verification Details</h6>
            <p><strong>Status:</strong> <span id="clinicStatusBadge" class="badge ${getStatusClass(clinic.status)}">${escapeHtml(clinic.status)}</span></p>
            <p><strong>Created:</strong> ${formatDate(clinic.created_at)}</p>
            <p><strong>Approved:</strong> ${clinic.approved_at ? formatDate(clinic.approved_at) : 'Not yet approved'}</p>
            <p><strong>Admin:</strong> ${escapeHtml(clinic.admin_first_name || '')} ${escapeHtml(clinic.admin_last_name || '')}</p>
            <p><strong>Admin Email:</strong> ${escapeHtml(clinic.admin_email || 'N/A')}</p>
        </div>
    </div>

    <hr>
    
    <div class="row">
        <div class="col-md-5">${documentsHtml}</div>
        <div class="col-md-7">
            <h6 class="mb-2">Document Preview</h6>
            <div id="documentPreview" class="border rounded p-3 text-center text-muted">
                <i class="bi bi-file-earmark-text display-6"></i>
                <p class="mt-2">Click "View" button to preview a document</p>
            </div>
        </div>
    </div>

    <hr class="mt-4">
    
    <div class="row mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-center gap-3 py-3 bg-light rounded">
                <button id="approveClinicBtn"
                    class="btn btn-success px-4"
                    onclick="approveClinicDynamic()">
                    <i class="bi bi-check-all me-2"></i> Approve Clinic
                </button>

                <button id="suspendClinicBtn"
                    class="btn btn-warning px-4"
                    onclick="suspendClinicDynamic()">
                    <i class="bi bi-pause me-2"></i> Suspend Clinic
                </button>

                <button id="rejectClinicBtn"
                    class="btn btn-danger px-4"
                    onclick="rejectClinicDynamic()">
                    <i class="bi bi-x-circle me-2"></i> Reject Clinic
                </button>
            </div>
        </div>
    </div>
`;

    updateClinicButtons();

    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(el => new bootstrap.Tooltip(el));

    // ==========================
    // DOCUMENT REVIEW FUNCTIONS
    // ==========================
    window.showRejectModal = function(docId) {
        const viewModalEl = document.getElementById('viewModal');
        const modalInstance = bootstrap.Modal.getInstance(viewModalEl);

        modalInstance?.hide();

        Swal.fire({
            title: 'Reject Document',
            input: 'textarea',
            inputLabel: 'Reason for rejection',
            inputPlaceholder: 'Enter reason...',
            showCancelButton: true,
            confirmButtonText: 'Reject Document',
            confirmButtonColor: '#dc3545',
            inputValidator: value => {
                if (!value) return 'Reason is required';
            }
        }).then(result => {
            modalInstance?.show();

            if (result.isConfirmed) {
                reviewDocument(docId, 'Rejected', result.value);
            }
        });
    };

    window.reviewDocument = function(docId, status, reason = '') {
        fetch(`${BASE_URL}/api/clinics_api.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'review_document', docId, status, reason})
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return showToast('error', data.error || 'Failed to update document');

            const badge = document.getElementById(`docStatusBadge${docId}`);
            badge.textContent = status;
            badge.className = 'badge ' + (status === 'Approved' ? 'bg-success' : 'bg-danger');

            document.getElementById(`approveBtn${docId}`).disabled = true;
            document.getElementById(`rejectBtn${docId}`).disabled = true;

            const doc = uploadedDocs.find(d => d.id === docId);
            if (doc) doc.status = status;

            updateClinicButtons();

            clinicsTable.ajax.reload(null, false);
            showToast('success', `Document ${status.toLowerCase()}`);
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Network error updating document');
        });
    };
}

function approveClinicDynamic() {
    Swal.fire({
        title: 'Approve Clinic?',
        text: `This will activate "${currentClinicName}"`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve'
    }).then(result => {
        if (result.isConfirmed) updateClinicStatus(currentClinicId, 'Active');
    });
}

function suspendClinicDynamic() {
    const viewModalEl = document.getElementById('viewModal');
    const modalInstance = bootstrap.Modal.getInstance(viewModalEl);

    modalInstance?.hide();

    Swal.fire({
        title: 'Suspend Clinic',
        input: 'textarea',
        inputLabel: 'Reason for suspension',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: 'Suspend',
        inputValidator: value => value ? null : 'Reason is required'
    }).then(result => {
        modalInstance?.show();

        if (result.isConfirmed) updateClinicStatus(currentClinicId, 'Suspended', result.value);
    });
}

function rejectClinicDynamic() {
    const viewModalEl = document.getElementById('viewModal');
    const modalInstance = bootstrap.Modal.getInstance(viewModalEl);

    modalInstance?.hide();

    Swal.fire({
        title: 'Reject Clinic',
        input: 'textarea',
        inputLabel: 'Reason for rejection',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: 'Reject',
        inputValidator: value => value ? null : 'Reason is required'
    }).then(result => {
        modalInstance?.show();

        if (result.isConfirmed) updateClinicStatus(currentClinicId, 'Rejected', result.value);
    });
}

function updateClinicStatus(id, status, reason = '') {

    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch(`${BASE_URL}/api/clinics_api.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'update_status',
            id: id,
            status: status,
            reason: reason
        })
    })
    .then(res => res.json())
    .then(data => {
        Swal.close();

        if (!data.success) {
            return Swal.fire('Error', data.error || 'Something went wrong', 'error');
        }

        Swal.fire({
            icon: 'success',
            title: `Clinic ${status.toLowerCase()} successfully`,
            timer: 1200,
            showConfirmButton: false
        });

        clinicsTable.ajax.reload(null, false);
        loadStats();

        fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`, {
            credentials: 'include'
        })
            .then(res => res.json())
            .then(clinicData => {
                if (clinicData.success) {
                    renderClinicModal(clinicData.data);

                    const modalEl = document.getElementById('viewModal');
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modalInstance.show();
                }
            })
            .catch(err => console.error('Error refreshing modal:', err));
    })
    .catch(err => {
        Swal.close();
        Swal.fire('Error', 'Network error', 'error');
        console.error(err);
    });
}

function exportToExcel() {
    const params = new URLSearchParams({
        status: currentStatusTab || $('#statusFilter').val(),
        risk: $('#riskFilter').val(),
        score: $('#scoreFilter').val()
    });
    
    window.open(`${BASE_URL}/api/clinics_api.php?action=export_report&${params}`, '_blank');
}

function printTable() {
    clinicsTable.button('.buttons-print').trigger();
}

// ==========================
// UTILITY FUNCTIONS
// ==========================
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getStatusClass(status) {
    const classes = {
        'Pending': 'badge-pending',
        'Active': 'badge-active',
        'Suspended': 'badge-suspended',
        'Rejected': 'badge-rejected',
        'Reapplying': 'badge-reapplying'
    };
    return classes[status] || 'badge-secondary';
}

function getScoreClass(score) {
    if (score >= 80) return 'score-high';
    if (score >= 60) return 'score-medium';
    return 'score-low';
}

function showToast(icon, message) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,
        title: message,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
}

function previewDocument(path, docType = '') {
    const previewDiv = document.getElementById('documentPreview');
    if (!previewDiv) return;

    previewDiv.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading document...</p>
        </div>
    `;

    if (docType) {
        const docTitle = document.getElementById('previewTitle');
        if (docTitle) docTitle.textContent = `Preview: ${docType}`;
    }

    if (!path) {
        previewDiv.innerHTML = `<div class="text-muted">No file to preview</div>`;
        return;
    }

    const cleanPath = path.replace(/^\/+/, '');

    const fileUrl = cleanPath.includes('uploads/')
        ? `${BASE_URL}/${cleanPath}`
        : `${BASE_URL}/uploads/${cleanPath}`;

    const ext = cleanPath.split('.').pop().toLowerCase();
    let html = '';

    if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
        html = `<img src="${fileUrl}" class="img-fluid rounded shadow" style="max-height:500px;" onerror="this.onerror=null; document.getElementById('documentPreview').innerHTML='<div class=\\'alert alert-danger\\'>Failed to load image.</div>';">`;
    } else if (ext === 'pdf') {
        html = `<iframe src="${fileUrl}" width="100%" height="500px" class="rounded"></iframe>`;
    } else {
        html = `<a href="${fileUrl}" target="_blank" class="btn btn-primary">Open Document</a>`;
    }

    previewDiv.innerHTML = html;
    console.log('Preview URL:', fileUrl);
}