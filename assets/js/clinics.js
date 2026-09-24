const BASE_URL = 'https://eyecore.capstone001.com';

// ============================================================
// TABLE STATE
// ============================================================
let currentPage    = 1;
let pageLength     = 10;
let totalRecords   = 0;
let currentSearch  = '';
let searchDebounce = null;

// ============================================================
// clinicsTable SHIM  ← same interface used by existing logic
// ============================================================
const clinicsTable = {
    ajax: {
        reload: function(callback, resetPaging) {
            if (resetPaging !== false) currentPage = 1;
            fetchClinics(callback);
        }
    },
    search: function(val) {
        currentSearch = val;
        return {
            draw: function() {
                currentPage = 1;
                fetchClinics();
            }
        };
    },
    // kept so printTable() call in existing code doesn't break
    button: function() {
        return { trigger: function() { _printTable(); } };
    }
};

// ============================================================
// OTHER EXISTING GLOBALS  (unchanged)
// ============================================================
let currentClinicId   = null;
let currentClinicName = null;
let currentStatusTab  = '';
let viewedDocs        = new Set();
let selectedDocId     = null;

// ── ATTEMPT LIMIT CONFIG ──────────────────────────────
const MAX_ATTEMPTS = 3;

// ============================================================
// INIT
// ============================================================
$(document).ready(function() {
    loadStats();
    fetchClinics();

    // Search with debounce
    $('#globalSearch').on('input', function() {
        clearTimeout(searchDebounce);
        const val = this.value;
        searchDebounce = setTimeout(function() {
            clinicsTable.search(val).draw();
        }, 350);
    });

    // Clear viewed docs when the clinic modal is fully closed
    $('#viewModal').on('hidden.bs.modal', function() {
        viewedDocs.clear();
        selectedDocId = null;
    });
});

// ============================================================
// FETCH & RENDER TABLE
// ============================================================
function fetchClinics(callback) {
    setProcessing(true);

    const payload = {
        draw:        1,
        start:       (currentPage - 1) * pageLength,
        length:      pageLength,
        status:      currentStatusTab || $('#statusFilter').val(),
        risk:        $('#riskFilter').val(),
        score:       $('#scoreFilter').val(),
        search_text: currentSearch || $('#globalSearch').val(),
        'search[value]': currentSearch || $('#globalSearch').val()
    };

    fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinics_datatable`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(payload).toString()
    })
    .then(res => res.json())
    .then(data => {
        setProcessing(false);
        totalRecords = data.recordsFiltered || data.recordsTotal || 0;
        renderRows(data.data || []);
        renderPagination();
        renderTableInfo(data.recordsTotal || 0);
        if (typeof callback === 'function') callback();
    })
    .catch(err => {
        setProcessing(false);
        console.error(err);
        document.getElementById('clinicsTableBody').innerHTML =
            `<tr><td colspan="7" class="text-center text-danger py-4">
                <i class="bi bi-exclamation-triangle me-2"></i>Failed to load clinics.
             </td></tr>`;
    });
}

// ---- Render tbody rows ----
function renderRows(rows) {
    const tbody = document.getElementById('clinicsTableBody');

    if (!rows || rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="clinics-empty">
            <i class="bi bi-inbox display-6 d-block mb-2"></i>No matching clinics found</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(row => {
        const riskLevel  = row.risk_level || 'medium';
        const riskClass  = riskLevel === 'high' ? 'danger' : riskLevel === 'medium' ? 'warning' : 'success';
        const score      = row.verification_score || 0;
        const scoreClass = getScoreClass(score);
        const statusCls  = getStatusClass(row.status);
        const isReapply  = row.status === 'Reapplying';

        // row classes (mirrors createdRow logic)
        let rowClass = `risk-${riskLevel}`;
        if (isReapply) rowClass += ' table-info';

        let statusBadge = `<span class="badge ${statusCls}">${escapeHtml(row.status)}</span>`;
        if (isReapply) {
            statusBadge += `<span class="badge bg-info ms-1">Re-submitted</span>`;
        }

        return `
<tr class="${rowClass}">
    <td class="text-center"><input type="checkbox" class="clinic-checkbox" value="${row.id}"></td>
    <td><div class="fw-semibold">${escapeHtml(row.clinic_name)}</div></td>
    <td class="d-none d-md-table-cell">
        <div><i class="bi bi-telephone"></i> ${escapeHtml(row.contact || 'No contact')}</div>
        <small class="text-muted"><i class="bi bi-envelope"></i> ${escapeHtml(row.clinic_email || 'No email')}</small>
    </td>
    <td>
        <span class="score-badge ${scoreClass}">${score}%</span>
        <div class="small text-muted mt-1">
            ${row.staff_count || 0} staff<br>
            ${row.patient_count || 0} patients
        </div>
    </td>
    <td><span class="badge bg-${riskClass}">${riskLevel.toUpperCase()}</span></td>
    <td>
        ${statusBadge}
        <div class="small text-muted mt-1">${formatDate(row.created_at)}</div>
    </td>
    <td>
        <button class="btn btn-sm btn-outline-primary"
            onclick="viewClinic(${row.id})"
            title="View Clinic">
            <i class="bi bi-eye"></i>
        </button>
    </td>
</tr>`;
    }).join('');
}

// ---- Pagination ----
function renderPagination() {
    const totalPages = Math.ceil(totalRecords / pageLength);
    const ul = document.getElementById('tablePagination');
    if (!ul) return;

    if (totalPages <= 1) { ul.innerHTML = ''; return; }

    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage   = Math.min(totalPages, startPage + maxVisible - 1);
    if (endPage - startPage < maxVisible - 1) startPage = Math.max(1, endPage - maxVisible + 1);

    let html = '';

    // Previous
    html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="goToPage(${currentPage - 1}); return false;">
            <i class="bi bi-chevron-left"></i>
        </a></li>`;

    if (startPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(1); return false;">1</a></li>`;
        if (startPage > 2) html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
    }

    for (let p = startPage; p <= endPage; p++) {
        html += `<li class="page-item ${p === currentPage ? 'active' : ''}">
            <a class="page-link" href="#" onclick="goToPage(${p}); return false;">${p}</a></li>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
        html += `<li class="page-item"><a class="page-link" href="#" onclick="goToPage(${totalPages}); return false;">${totalPages}</a></li>`;
    }

    // Next
    html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="goToPage(${currentPage + 1}); return false;">
            <i class="bi bi-chevron-right"></i>
        </a></li>`;

    ul.innerHTML = html;
}

function goToPage(page) {
    const totalPages = Math.ceil(totalRecords / pageLength);
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    fetchClinics();
}

function renderTableInfo(totalAll) {
    const el = document.getElementById('tableInfo');
    if (!el) return;
    const start = totalRecords === 0 ? 0 : (currentPage - 1) * pageLength + 1;
    const end   = Math.min(currentPage * pageLength, totalRecords);
    let txt     = `Showing ${start} to ${end} of ${totalRecords} clinics`;
    if (totalRecords < totalAll) txt += ` (filtered from ${totalAll} total)`;
    el.textContent = txt;
}

function changePageLength(val) {
    pageLength  = parseInt(val, 10);
    currentPage = 1;
    fetchClinics();
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.clinic-checkbox').forEach(cb => { cb.checked = checkbox.checked; });
}

function setProcessing(show) {
    const el = document.getElementById('tableProcessing');
    if (el) el.classList.toggle('d-none', !show);
}

// ============================================================
// EXISTING FUNCTIONS
// ============================================================

function loadStats() {
    fetch(`${BASE_URL}/api/clinics_api.php?action=get_stats`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pendingClinics').textContent  = data.data.pending        || 0;
                document.getElementById('activeClinics').textContent   = data.data.active         || 0;
                document.getElementById('suspendedClinics').textContent= data.data.suspended      || 0;
                document.getElementById('totalClinics').textContent    = data.data.total_clinics  || 0;
            }
        })
        .catch(err => console.error(err));
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
    currentClinicId   = id;
    currentClinicName = name;
    viewedDocs.clear();
    selectedDocId = null;

    fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`)
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
// RENDER CLINIC MODAL — split view (document list + preview pane)
// ==========================
function renderClinicModal(clinic) {
    const uploadedDocs = clinic.documents || [];
    selectedDocId = null; // reset every time a clinic is (re)loaded

    const updateClinicButtons = () => {
        const allApprovedDocs = uploadedDocs.length > 0 && uploadedDocs.every(d => d.status === 'Approved');
        const anyPendingDocs  = uploadedDocs.some(d => !d.status || d.status === 'Pending');
        const anyRejectedDocs = uploadedDocs.some(d => d.status === 'Rejected');

        document.getElementById('approveClinicBtn').disabled = !(allApprovedDocs && clinic.status !== 'Active');
        document.getElementById('suspendClinicBtn').disabled = !(allApprovedDocs && clinic.status !== 'Suspended');
        document.getElementById('rejectClinicBtn').disabled  = !(anyPendingDocs || anyRejectedDocs);
    };

    function renderDocList() {
        if (uploadedDocs.length === 0) {
            return `<div class="alert alert-secondary">No documents uploaded.</div>`;
        }
        return uploadedDocs.map(doc => {
            const isApproved = doc.status === 'Approved';
            const isRejected = doc.status === 'Rejected';
            const badgeClass = isApproved ? 'bg-success' : isRejected ? 'bg-danger' : 'bg-warning';
            const isActive   = doc.id === selectedDocId;
            return `
<div class="doc-list-item ${isActive ? 'active' : ''}" onclick="selectDocument(${doc.id})">
    <div class="doc-list-title">${escapeHtml(doc.document_type)}</div>
    <span class="badge ${badgeClass}" style="font-size:0.7rem;">${escapeHtml(doc.status || 'Pending')}</span>
    ${doc.max_attempts_reached ? '<span class="badge bg-danger ms-1" style="font-size:0.7rem;">MAX ATTEMPTS</span>' : ''}
</div>`;
        }).join('');
    }

    function renderPreviewPane() {
        const doc = uploadedDocs.find(d => d.id === selectedDocId);

        if (!doc) {
            return `
<div class="doc-preview-frame">
    <div class="doc-preview-empty">
        <i class="bi bi-file-earmark-text display-4 d-block mb-2"></i>
        <p class="mb-0">Pumili ng dokumento sa kaliwa para tignan</p>
    </div>
</div>`;
        }

        const isApproved = doc.status === 'Approved';
        const isRejected = doc.status === 'Rejected';
        const hasBeenViewed = viewedDocs.has(doc.id);
        const maxAttemptsReached = doc.max_attempts_reached || false;

        // ✅ FIX: "final rejection" LANG ang naka-block (3rd attempt rejected)
        // Kapag 3rd attempt pero Pending pa → hindi pa final, pwede pa i-review
        const isFinalRejection = maxAttemptsReached && isRejected;

        const disableApprove = isApproved || isRejected || !hasBeenViewed || isFinalRejection;
        const disableReject  = isApproved || isRejected || !hasBeenViewed || isFinalRejection;

        let fileHtml = '<div class="doc-preview-empty">No file to preview</div>';
        if (doc.file_path) {
            const cleanPath = doc.file_path.replace(/^\/+/, '');
            const fileUrl = cleanPath.includes('uploads/')
                ? `${BASE_URL}/${cleanPath}`
                : `${BASE_URL}/uploads/${cleanPath}`;
            const ext = cleanPath.split('.').pop().toLowerCase();

            if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
                fileHtml = `<img src="${fileUrl}" class="img-fluid rounded" style="max-height:360px;"
                    onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'text-danger\\'>Failed to load image.</div>';">`;
            } else if (ext === 'pdf') {
                fileHtml = `<iframe src="${fileUrl}" width="100%" height="380" style="border:none;border-radius:8px;"></iframe>`;
            } else {
                fileHtml = `<a href="${fileUrl}" target="_blank" class="btn btn-primary"><i class="bi bi-box-arrow-up-right"></i> Open Document</a>`;
            }
        }

        // ✅ Reset button — lalabas lang kapag final rejection
        const resetButtonHtml = isFinalRejection ? `
            <button type="button" class="btn btn-warning btn-sm" 
                onclick="resetDocAttempts(${doc.id})"
                title="Give the clinic a fresh chance to re-upload">
                <i class="bi bi-arrow-clockwise"></i> Reset Attempts
            </button>` : '';

        return `
<div class="d-flex justify-content-between align-items-start mb-2">
    <div>
        <h6 class="mb-1">${escapeHtml(doc.document_type)}</h6>
        <small class="text-muted">
            Uploaded: ${formatDate(doc.uploaded_at)} &nbsp;|&nbsp; Owner: ${escapeHtml(doc.owner_name || '-')}
            ${doc.rejection_reason ? `<br><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(doc.rejection_reason)}</span>` : ''}
        </small>
    </div>
    <span id="docStatusBadge${doc.id}" class="badge ${isApproved ? 'bg-success' : isRejected ? 'bg-danger' : 'bg-warning'}">
        ${escapeHtml(doc.status || 'Pending')}
    </span>
</div>
<div class="doc-preview-frame">${fileHtml}</div>
<div class="doc-preview-actions">
    <button type="button" class="btn btn-success btn-sm" id="approveBtn${doc.id}"
        onclick="reviewDocument(${doc.id}, 'Approved')" ${disableApprove ? 'disabled' : ''}>
        <i class="bi bi-check"></i> Approve
    </button>
    <button type="button" class="btn btn-danger btn-sm" id="rejectBtn${doc.id}"
        onclick="showRejectModal(${doc.id})" ${disableReject ? 'disabled' : ''}>
        <i class="bi bi-x"></i> Reject
    </button>
    ${resetButtonHtml}
</div>
${!hasBeenViewed && !isApproved && !isRejected ? '<p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle"></i> Tignan muna ang dokumento para ma-enable ang Approve/Reject.</p>' : ''}`;
    }

    function refreshSplitView() {
        const listPanel = document.getElementById('docListPanel');
        const previewPanel = document.getElementById('docPreviewPanel');
        if (listPanel) listPanel.innerHTML = renderDocList();
        if (previewPanel) previewPanel.innerHTML = renderPreviewPane();
    }

    window.selectDocument = function(docId) {
        selectedDocId = docId;
        viewedDocs.add(docId);
        refreshSplitView();
    };

    const documentsHtml = `
<h6 class="mt-3 mb-3">Uploaded Documents (${uploadedDocs.length})</h6>
<div class="doc-review-layout">
    <div class="doc-list-panel" id="docListPanel">${renderDocList()}</div>
    <div class="doc-preview-panel" id="docPreviewPanel">${renderPreviewPane()}</div>
</div>`;

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

    ${documentsHtml}

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
    </div>`;

    updateClinicButtons();

    // ==========================
    // DOCUMENT REVIEW — showRejectModal
    // ==========================
    window.showRejectModal = function(docId) {
        const doc = uploadedDocs.find(d => d.id === docId);

        const currentStatus = doc ? (doc.status || 'Pending') : 'Pending';
        if (currentStatus !== 'Pending') {
            showToast('error', 'This document has already been reviewed.');
            return;
        }

        if (doc && doc.max_attempts_reached && doc.status === 'Rejected') {
            showToast('error', 'This document has reached the maximum attempts. Contact support.');
            return;
        }

        // ✅ I-HIDE ang Bootstrap modal bago mag-Swal (para makapag-type sa textarea)
        const viewModalEl   = document.getElementById('viewModal');
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
            inputValidator: value => { if (!value) return 'Reason is required'; }
        }).then(result => {
            // ✅ I-SHOW ulit ang Bootstrap modal pagkatapos
            modalInstance?.show();

            if (result.isConfirmed) {
                reviewDocument(docId, 'Rejected', result.value);
            }
        });
    };

    // ==========================
    // DOCUMENT REVIEW — reviewDocument
    // ==========================
    window.reviewDocument = function(docId, status, reason = '') {
        const doc = uploadedDocs.find(d => d.id === docId);

        const currentStatus = doc ? (doc.status || 'Pending') : 'Pending';
        if (currentStatus !== 'Pending') {
            showToast('error', 'This document has already been reviewed.');
            return;
        }

        // ✅ Block reject LANG kapag final rejection na (3rd attempt rejected)
        if (doc && doc.max_attempts_reached && doc.status === 'Rejected' && status === 'Rejected') {
            showToast('error', 'This document has reached the maximum attempts. Contact support.');
            return;
        }

        fetch(`${BASE_URL}/api/clinics_api.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'review_document', docId, status, reason })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return showToast('error', data.error || 'Failed to update document');

            if (doc) doc.status = status;

            refreshSplitView();
            updateClinicButtons();

            clinicsTable.ajax.reload(null, false);
            loadStats();
            showToast('success', `Document ${status.toLowerCase()}`);
        })
        .catch(err => { console.error(err); showToast('error', 'Network error updating document'); });
    };

    // ==========================
    // DOCUMENT REVIEW — resetDocAttempts
    // ==========================
    window.resetDocAttempts = function(docId) {
        const viewModalEl   = document.getElementById('viewModal');
        const modalInstance = bootstrap.Modal.getInstance(viewModalEl);
        modalInstance?.hide();

        Swal.fire({
            title: 'Reset Document Attempts?',
            html: `
                <p>This will give the clinic a <b>fresh chance</b> to re-upload this document.</p>
                <p class="text-muted small mb-0">The submission counter will reset to 0 and the document status will become <b>Pending</b>.</p>
            `,
            icon: 'warning',
            input: 'textarea',
            inputLabel: 'Reason for reset',
            inputPlaceholder: 'Enter reason...',
            showCancelButton: true,
            confirmButtonText: 'Yes, reset',
            confirmButtonColor: '#f59e0b',
            inputValidator: value => value ? null : 'Reason is required'
        }).then(result => {
            modalInstance?.show();

            if (!result.isConfirmed) return;

            fetch(`${BASE_URL}/api/clinics_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reset_document_attempts',
                    docId: docId,
                    reason: result.value
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    return Swal.fire('Error', data.error || 'Failed to reset', 'error');
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Attempts Reset',
                    text: 'The clinic can now re-upload this document.',
                    timer: 1500,
                    showConfirmButton: false
                });

                // Reload clinic modal
                fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${currentClinicId}`)
                    .then(res => res.json())
                    .then(clinicData => {
                        if (clinicData.success) {
                            renderClinicModal(clinicData.data);
                        }
                    });

                clinicsTable.ajax.reload(null, false);
                loadStats();
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error', 'Network error', 'error');
            });
        });
    };

}

// ==========================
// CLINIC STATUS FUNCTIONS
// ==========================
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
    const viewModalEl   = document.getElementById('viewModal');
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
    const viewModalEl   = document.getElementById('viewModal');
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
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id, status, reason })
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

        fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`)
            .then(res => res.json())
            .then(clinicData => {
                if (clinicData.success) {
                    renderClinicModal(clinicData.data);

                    const modalEl       = document.getElementById('viewModal');
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

// ==========================
// EXPORT & PRINT
// ==========================
function exportToExcel() {
    const params = new URLSearchParams({
        status: currentStatusTab || $('#statusFilter').val(),
        risk:   $('#riskFilter').val(),
        score:  $('#scoreFilter').val()
    });
    window.open(`${BASE_URL}/api/clinics_api.php?action=export_report&${params}`, '_blank');
}

function printTable() {
    _printTable();
}

function _printTable() {
    const rows = document.querySelectorAll('#clinicsTableBody tr');
    let tableRows = '';
    rows.forEach(tr => {
        // Skip loading/empty rows
        if (tr.querySelector('td[colspan]')) return;
        const cells = tr.querySelectorAll('td');
        tableRows += '<tr>';
        cells.forEach((td, i) => {
            if (i === 0) return; // skip checkbox column
            tableRows += `<td>${td.innerText.trim()}</td>`;
        });
        tableRows += '</tr>';
    });

    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>Clinic List</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body{padding:20px;} @media print{button{display:none}}</style>
    </head><body>
        <h4 class="mb-3">Clinic Management – Printed Report</h4>
        <button class="btn btn-sm btn-primary mb-3" onclick="window.print()">Print</button>
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr><th>Clinic</th><th>Contact</th><th>Score</th><th>Risk</th><th>Status</th></tr>
            </thead>
            <tbody>${tableRows}</tbody>
        </table>
    </body></html>`);
    win.document.close();
}

// ==========================
// UTILITY FUNCTIONS
// ==========================
function escapeHtml(text) {
    if (!text) return '';
    const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function getStatusClass(status) {
    const classes = {
        'Pending':    'badge-pending',
        'Active':     'badge-active',
        'Suspended':  'badge-suspended',
        'Rejected':   'badge-rejected',
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
        icon,
        title: message,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
}