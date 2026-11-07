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
// clinicsTable SHIM
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
    button: function() {
        return { trigger: function() { _printTable(); } };
    }
};

// ============================================================
// GLOBALS
// ============================================================
let currentClinicId   = null;
let currentClinicName = null;
let currentClinicData = null;
let currentStatusTab  = '';
let viewedDocs        = new Set();
let selectedDocId     = null;

// ============================================================
// INIT
// ============================================================
$(document).ready(function() {
    loadStats();
    fetchClinics();

    $('#globalSearch').on('input', function() {
        clearTimeout(searchDebounce);
        const val = this.value;
        searchDebounce = setTimeout(function() {
            clinicsTable.search(val).draw();
        }, 350);
    });

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

function renderRows(rows) {
    const tbody = document.getElementById('clinicsTableBody');

    if (!rows || rows.length === 0) {
        // Pinalitan ang colspan from 7 to 6
        tbody.innerHTML = `<tr><td colspan="6" class="clinics-empty">
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

        let rowClass = `risk-${riskLevel}`;
        if (isReapply) rowClass += ' table-info';

        let statusBadge = `<span class="badge ${statusCls}">${escapeHtml(row.status)}</span>`;
        if (isReapply) {
            statusBadge += `<span class="badge bg-info ms-1">Re-submitted</span>`;
        }

        return `
<tr class="${rowClass}">
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

function loadStats() {
    fetch(`${BASE_URL}/api/clinics_api.php?action=get_stats`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('pendingClinics').textContent   = data.data.pending       || 0;
                document.getElementById('activeClinics').textContent    = data.data.active        || 0;
                document.getElementById('reapplyingClinics').textContent = data.data.reapplying   || 0; // ID pinalitan
                document.getElementById('totalClinics').textContent     = data.data.total_clinics || 0;
            }
        })
        .catch(err => console.error(err));
}

function filterTable() {
    clinicsTable.ajax.reload();
}

function setStatusTab(status) {
    currentStatusTab = status;
    clinicsTable.ajax.reload();
}

// ==========================
// VIEW CLINIC MODAL
// ==========================
function viewClinic(id) {
    currentClinicId = id;
    viewedDocs.clear();
    selectedDocId = null;

    fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) return alert('Error loading clinic details');
            const clinic = data.data;
            currentClinicData = clinic;
            currentClinicName = clinic.clinic_name;   // ayos na ang "undefined"
            renderClinicModal(clinic);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show();
        })
        .catch(err => {
            console.error(err);
            alert('Network error loading clinic details');
        });
}

function renderClinicModal(clinic) {
    currentClinicData = clinic;
    currentClinicName = clinic.clinic_name;
    const uploadedDocs = clinic.documents || [];
    const idv = clinic.identity || null;

    // ── Unified selection state ──
    if (idv) {
        window._selectedItem = { type: 'identity' };
    } else if (uploadedDocs.length > 0) {
        window._selectedItem = { type: 'document', id: uploadedDocs[0].id };
    } else {
        window._selectedItem = null;
    }

    // ── 1. UNIFIED LIST (Clean Left Panel) ──
    function renderUnifiedList() {
        const items = [];

        // Identity item
        if (idv) {
            const isIdApproved = idv.status === 'Approved';
            const isIdRejected = idv.status === 'Rejected';
            let idvBadgeClass = 'bg-warning';
            if (isIdApproved) idvBadgeClass = 'bg-success';
            else if (isIdRejected) idvBadgeClass = 'bg-danger';

            const isActive = window._selectedItem &&
                             window._selectedItem.type === 'identity';

            items.push(`
<div class="doc-list-item ${isActive ? 'active' : ''}" onclick="selectItem('identity')">
    <div class="doc-list-title">
        <i></i>Identity Verification
    </div>
    <div class="mt-1">
        <span class="badge ${idvBadgeClass}" style="font-size:0.7rem;">${escapeHtml(idv.status)}</span>
    </div>
</div>`);
        }

        // Document items
        uploadedDocs.forEach(doc => {
            const isApproved         = doc.status === 'Approved';
            const isDeclined         = doc.status === 'Rejected';
            const isAwaitingReupload = doc.status === 'Awaiting Re-upload';
            let badgeClass = 'bg-warning';
            if (isApproved)              badgeClass = 'bg-success';
            else if (isDeclined)         badgeClass = 'bg-danger';
            else if (isAwaitingReupload) badgeClass = 'bg-info';

            const isActive   = window._selectedItem &&
                               window._selectedItem.type === 'document' &&
                               window._selectedItem.id === doc.id;
            const statusText = isDeclined         ? 'Declined'
                             : isAwaitingReupload ? 'Awaiting Re-upload'
                             : (doc.status || 'Pending');

            items.push(`
<div class="doc-list-item ${isActive ? 'active' : ''}" onclick="selectItem('document', ${doc.id})">
    <div class="doc-list-title">${escapeHtml(doc.document_type)}</div>
    <div class="mt-1">
        <span class="badge ${badgeClass}" style="font-size:0.7rem;">${escapeHtml(statusText)}</span>
    </div>
</div>`);
        });

        if (items.length === 0) {
            return `<div class="alert alert-secondary mb-0">No documents uploaded.</div>`;
        }

        return items.join('');
    }

    // ── 2. PREVIEW: IDENTITY ──
    function renderIdentityPreview() {
        if (!idv) return '';
        const isIdApproved = idv.status === 'Approved';
        const isIdPending  = idv.status === 'Pending';
        const isIdRejected = idv.status === 'Rejected';

        let idvBadgeClass = 'bg-warning';
        if (isIdApproved) idvBadgeClass = 'bg-success';
        else if (isIdRejected) idvBadgeClass = 'bg-danger';

        // FLAGS: Attempts at Declined Today
        let attemptFlag = '';
        if (idv.submission_attempts) {
            attemptFlag += `<span class="badge bg-secondary ms-1">Attempt #${idv.submission_attempts}</span>`;
        }
        const isDeclinedToday = isIdRejected && idv.reviewed_at && new Date(idv.reviewed_at).toDateString() === new Date().toDateString();
        if (isDeclinedToday) {
            attemptFlag += `<span class="badge bg-danger ms-1">DECLINED TODAY</span>`;
        }

        return `
<div class="d-flex justify-content-between align-items-start mb-2">
    <div>
        <h6 class="mb-1"><i class="bi bi-person-vcard me-1"></i>Live Selfie & ID</h6>
        <small class="text-muted">Submitted: ${formatDate(idv.created_at)}</small>
    </div>
    <div>
        <span class="badge ${idvBadgeClass}">${idv.status}</span>
        ${attemptFlag}
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <div style="background:#f0fdfa; border:1px solid #5eead4; border-radius:10px; padding:10px 12px;">
            <small style="color:#0f766e; font-weight:700; text-transform:uppercase; font-size:10px;">ID Type</small>
            <div style="font-weight:600; font-size:13px; margin-top:3px;">${escapeHtml(idv.id_type || '—')}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; padding:10px 12px;">
            <small style="color:#1e40af; font-weight:700; text-transform:uppercase; font-size:10px;">ID Number</small>
            <div style="font-weight:600; font-size:13px; margin-top:3px;">${escapeHtml(idv.id_number || '—')}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:10px 12px;">
            <small style="color:#991b1b; font-weight:700; text-transform:uppercase; font-size:10px;">Face Match</small>
            <div style="font-weight:600; font-size:13px; margin-top:3px;">${idv.face_match ? 'Yes' : 'Manual Check'}</div>
        </div>
    </div>
</div>

<div class="doc-preview-frame" style="min-height: 300px; display: flex; gap: 10px; padding: 10px; background: #f8fafc;">
    <div style="flex:1; text-align:center;">
        <div class="text-muted small mb-1">Live Selfie</div>
        <img src="${BASE_URL}/${idv.selfie_path}" class="img-fluid rounded border" style="max-height: 240px; object-fit: contain;">
    </div>
    <div style="flex:1; text-align:center;">
        <div class="text-muted small mb-1">ID Photo</div>
        <img src="${BASE_URL}/${idv.id_image_path}" class="img-fluid rounded border" style="max-height: 240px; object-fit: contain;">
    </div>
</div>

${idv.ocr_raw_text ? `
<div class="mt-2 p-2 bg-light rounded border">
    <small class="text-muted fw-bold"><i class="bi bi-robot"></i> OCR Read:</small>
    <div style="font-family:monospace; font-size:10px; max-height:60px; overflow-y:auto;">${escapeHtml(idv.ocr_raw_text)}</div>
</div>` : ''}

<div class="doc-preview-actions">
    ${isIdPending ? `
        <button type="button" class="btn btn-success btn-sm" onclick="reviewIdentity(${clinic.id}, 'Approved')">
            <i class="bi bi-check"></i> Approve
        </button>
        <button type="button" class="btn btn-danger btn-sm" onclick="reviewIdentity(${clinic.id}, 'Rejected')">
            <i class="bi bi-x"></i> Declined
        </button>
    ` : ''}
</div>`;
    }

    // ── 3. PREVIEW: DOCUMENT ──
    function renderDocPreview(doc) {
        const isApproved         = doc.status === 'Approved';
        const isDeclined         = doc.status === 'Rejected';
        const isAwaitingReupload = doc.status === 'Awaiting Re-upload';
        const hasBeenViewed      = viewedDocs.has(doc.id);

        const disableApprove = isApproved || isDeclined || isAwaitingReupload || !hasBeenViewed;
        const disableReject  = isApproved || isDeclined || isAwaitingReupload || !hasBeenViewed;

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

        const resetButtonHtml = isDeclined ? `
            <button type="button" class="btn btn-warning btn-sm"
                onclick="resetDocAttempts(${doc.id})"
                title="Give the clinic a fresh chance to re-upload">
                <i class="bi bi-arrow-clockwise"></i> Re-open for Re-upload
            </button>` : '';

        const statusLabel = isDeclined         ? 'Declined'
                          : isAwaitingReupload ? 'Awaiting Re-upload'
                          : (doc.status || 'Pending');

        // FLAGS: Attempts at Declined Today
        let attemptFlag = '';
        if (doc.submission_attempts) {
            attemptFlag += `<span class="badge bg-secondary ms-1">Attempt #${doc.submission_attempts}</span>`;
        }
        if (doc.declined_today) {
            attemptFlag += `<span class="badge bg-danger ms-1">DECLINED TODAY</span>`;
        }

        let ocrBadge = '';
        if (doc.ocr_flag) {
            const isBlurry = doc.ocr_flag.toLowerCase().includes('blur');
            const badgeColor = isBlurry ? 'bg-warning text-dark' : 'bg-danger text-white';
            ocrBadge = `<span class="badge ${badgeColor} mt-1 d-block" style="font-size:0.65rem; text-align:left;"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(doc.ocr_flag)}</span>`;
        }

        return `
<div class="d-flex justify-content-between align-items-start mb-2">
    <div>
        <h6 class="mb-1">${escapeHtml(doc.document_type)}</h6>
        <small class="text-muted">
            Uploaded: ${formatDate(doc.uploaded_at)} &nbsp;|&nbsp; Owner: ${escapeHtml(doc.owner_name || '-')}
            ${doc.rejection_reason ? `<br><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(doc.rejection_reason)}</span>` : ''}
        </small>
    </div>
    <div>
        <span id="docStatusBadge${doc.id}" class="badge ${isApproved ? 'bg-success' : isDeclined ? 'bg-danger' : isAwaitingReupload ? 'bg-info' : 'bg-warning'}">
            ${escapeHtml(statusLabel)}
        </span>
        ${attemptFlag}
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-md-4">
        <div style="background:#f0fdfa; border:1px solid #5eead4; border-radius:10px; padding:10px 12px;">
            <small style="color:#0f766e; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-size:10px;">
                <i class="bi bi-person-badge"></i> Owner (from clinic)
            </small>
            <div style="font-weight:600; color:#0d1b2a; font-size:13px; margin-top:3px;">
                ${escapeHtml(doc.owner_name || '—')}
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; padding:10px 12px;">
            <small style="color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-size:10px;">
                <i class="bi bi-calendar-check"></i> Release Date (from clinic)
            </small>
            <div style="font-weight:600; color:#0d1b2a; font-size:13px; margin-top:3px;">
                ${doc.released_at ? formatDate(doc.released_at) : '—'}
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div style="background:${isDocExpired(doc.expires_at) ? '#fef2f2' : '#fffbeb'}; border:1px solid ${isDocExpired(doc.expires_at) ? '#fca5a5' : '#fde68a'}; border-radius:10px; padding:10px 12px;">
            <small style="color:${isDocExpired(doc.expires_at) ? '#991b1b' : '#92400e'}; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-size:10px;">
                <i class="bi bi-calendar-x"></i> Expiry Date (from clinic)
            </small>
            <div style="font-weight:600; color:${isDocExpired(doc.expires_at) ? '#991b1b' : '#0d1b2a'}; font-size:13px; margin-top:3px;">
                ${doc.expires_at ? formatDate(doc.expires_at) : '—'}
                ${isDocExpired(doc.expires_at) ? '<span class="badge bg-danger ms-1" style="font-size:9px;">EXPIRED</span>' : ''}
            </div>
        </div>
    </div>
</div>

${isAwaitingReupload ? `
<div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; padding:14px 16px; margin-bottom:12px; display:flex; gap:10px; align-items:flex-start;">
    <i class="bi bi-hourglass-split" style="color:#1e40af; font-size:20px; flex-shrink:0; margin-top:2px;"></i>
    <div>
        <strong style="color:#1e40af; display:block; margin-bottom:3px;">Waiting for Clinic to Re-upload</strong>
        <span style="color:#1e40af; font-size:12.5px; line-height:1.5;">
            The previous file was cleared. The clinic has been notified to submit a corrected document.
            Once they upload, the new file will appear here for your review.
        </span>
    </div>
</div>` : ''}
<div class="doc-preview-frame">${fileHtml}${ocrBadge}</div>
<div class="doc-preview-actions">
    <button type="button" class="btn btn-success btn-sm" id="approveBtn${doc.id}"
        onclick="reviewDocument(${doc.id}, 'Approved')" ${disableApprove ? 'disabled' : ''}>
        <i class="bi bi-check"></i> Approve
    </button>
    <button type="button" class="btn btn-danger btn-sm" id="rejectBtn${doc.id}"
        onclick="showRejectModal(${doc.id})" ${disableReject ? 'disabled' : ''}>
        <i class="bi bi-x"></i> Decline
    </button>
    ${resetButtonHtml}
</div>
${!hasBeenViewed && !isApproved && !isDeclined && !isAwaitingReupload ? '<p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle"></i> Tignan muna ang dokumento para ma-enable ang Approve/Decline.</p>' : ''}`;
    }

    // ── 4. PREVIEW ROUTER ──
    function renderPreviewPane() {
        if (!window._selectedItem) {
            return `
<div class="doc-preview-frame">
    <div class="doc-preview-empty">
        <i class="bi bi-file-earmark-text display-4 d-block mb-2"></i>
        <p class="mb-0">Pumili ng item sa kaliwa para tignan</p>
    </div>
</div>`;
        }

        if (window._selectedItem.type === 'identity') {
            return renderIdentityPreview();
        }

        if (window._selectedItem.type === 'document') {
            const doc = uploadedDocs.find(d => d.id === window._selectedItem.id);
            if (!doc) {
                return `<div class="doc-preview-empty">Document not found.</div>`;
            }
            return renderDocPreview(doc);
        }

        return '';
    }

    // ── 5. Refresh + selection handlers ──
    window.refreshSplitView = function() {
        const listPanel    = document.getElementById('docListPanel');
        const previewPanel = document.getElementById('docPreviewPanel');
        if (listPanel)    listPanel.innerHTML    = renderUnifiedList();
        if (previewPanel) previewPanel.innerHTML = renderPreviewPane();
        renderClinicActions(currentClinicData);
    };

    window.selectItem = function(type, docId) {
        if (type === 'identity') {
            window._selectedItem = { type: 'identity' };
        } else {
            window._selectedItem = { type: 'document', id: docId };
            viewedDocs.add(docId);
        }
        window.refreshSplitView();
    };

    // ── 6. Render modal HTML ──
    document.getElementById('viewModalTitle').textContent = `Clinic Verifications – ${clinic.clinic_name}`;

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
            <p><strong>Hospital Type:</strong> ${escapeHtml(clinic.hospital_type || 'N/A')}</p>
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

    <div class="doc-review-layout mt-3">
        <div class="doc-list-panel" id="docListPanel">${renderUnifiedList()}</div>
        <div class="doc-preview-panel" id="docPreviewPanel">${renderPreviewPane()}</div>
    </div>`;

    renderClinicActions(clinic);
}

// ═══════════════════════════════════════════════════════════
// REVIEW IDENTITY FUNCTION
// ═══════════════════════════════════════════════════════════
window.reviewIdentity = function(clinicId, status) {
    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));

    if (status === 'Rejected') {
        // Itago ang modal
        modalInstance.hide();

        // I-delay ng 300ms para maging smooth ang transition at mag-focus nang maayos ang textarea
        setTimeout(() => {
            Swal.fire({
                title: 'Decline Identity?',
                input: 'textarea',
                inputLabel: 'Reason for declining',
                inputPlaceholder: 'e.g., Blurry ID, Face mismatch...',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Decline',
                inputValidator: (value) => !value ? 'Reason is required' : null,
                didOpen: () => {
                    const textarea = Swal.getInput();
                    if (textarea) textarea.focus();
                }
            }).then(result => {
                if (result.isConfirmed) {
                    updateIdentityStatus(clinicId, status, result.value);
                } else {
                    modalInstance.show(); // Ibalik modal kapag nag-cancel
                }
            });
        }, 300);
    } else {
        // Approve (walang textarea kaya hindi issue ang focus)
        modalInstance.hide();
        setTimeout(() => {
            Swal.fire({
                title: 'Approve Identity?',
                text: "This will verify the clinic's identity.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                confirmButtonText: 'Yes, Approve'
            }).then(result => {
                if (result.isConfirmed) {
                    updateIdentityStatus(clinicId, status, '');
                } else {
                    modalInstance.show();
                }
            });
        }, 300);
    }
};

// ═══════════════════════════════════════════════════════════
// DOCUMENT REVIEW: showRejectModal
// ═══════════════════════════════════════════════════════════
window.showRejectModal = function(docId) {
    if (!currentClinicData || !currentClinicData.documents) {
        showToast('error', 'Clinic data not loaded. Please refresh.');
        return;
    }

    const doc = currentClinicData.documents.find(d => d.id === docId);
    const currentStatus = doc ? (doc.status || 'Pending') : 'Pending';

    if (currentStatus !== 'Pending') {
        showToast('error', 'This document has already been reviewed.');
        return;
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    setTimeout(() => {
        Swal.fire({
            title: 'Decline Document',
            input: 'textarea',
            inputLabel: 'Reason for declining',
            inputPlaceholder: 'Enter reason...',
            showCancelButton: true,
            confirmButtonText: 'Decline Document',
            confirmButtonColor: '#dc3545',
            inputValidator: value => { if (!value) return 'Reason is required'; },
            didOpen: () => {
                const textarea = Swal.getInput();
                if (textarea) textarea.focus();
            }
        }).then(result => {
            if (result.isConfirmed) {
                reviewDocument(docId, 'Rejected', result.value);
            } else {
                modalInstance.show();
            }
        });
    }, 300);
};

// ═══════════════════════════════════════════════════════════
// DOCUMENT REVIEW: resetDocAttempts
// ═══════════════════════════════════════════════════════════
window.resetDocAttempts = function(docId) {
    if (!currentClinicData) {
        showToast('error', 'Clinic data not loaded. Please refresh.');
        return;
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    setTimeout(() => {
        Swal.fire({
            title: 'Re-open Document for Re-upload?',
            html: `
                <p>This will allow the clinic to <b>re-upload a corrected copy immediately</b> — overriding the "one submission per day" rule.</p>
                <p class="text-muted small mb-0">The document status will be set to <b>Awaiting Re-upload</b> and the old file will be cleared.</p>
            `,
            icon: 'warning',
            input: 'textarea',
            inputLabel: 'Reason for re-opening',
            inputPlaceholder: 'Enter reason...',
            showCancelButton: true,
            confirmButtonText: 'Yes, re-open',
            confirmButtonColor: '#f59e0b',
            inputValidator: value => value ? null : 'Reason is required',
            didOpen: () => {
                const textarea = Swal.getInput();
                if (textarea) textarea.focus();
            }
        }).then(result => {
            if (!result.isConfirmed) {
                modalInstance.show();
                return;
            }

            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

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
                    return Swal.fire('Error', data.error || 'Failed to re-open', 'error')
                               .then(() => modalInstance.show());
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Document Re-opened',
                    text: 'The clinic can now re-upload this document immediately.',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    modalInstance.show();
                });

                fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${currentClinicId}`)
                    .then(res => res.json())
                    .then(clinicData => {
                        if (clinicData.success) {
                            currentClinicData = clinicData.data;
                            renderClinicModal(clinicData.data);
                        }
                    });

                clinicsTable.ajax.reload(null, false);
                loadStats();
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error', 'Network error', 'error')
                    .then(() => modalInstance.show());
            });
        });
    }, 300);
};

// ═══════════════════════════════════════════════════════════
// UPDATE IDENTITY STATUS (FIXED NA RIN)
// ═══════════════════════════════════════════════════════════
function updateIdentityStatus(clinicId, status, reason) {
    // Mag-focus muna tayo sa pag-aayos ng UI transition
    Swal.fire({ 
        title: 'Updating...', 
        allowOutsideClick: false, 
        didOpen: () => Swal.showLoading() 
    });

    fetch(`${BASE_URL}/api/clinics_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_identity_status', clinic_id: clinicId, status: status, reason: reason })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Identity has been ${status.toLowerCase()}.`, 'success').then(() => {
                // I-refresh ang clinic data at ibalik ang modal
                fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${clinicId}`)
                    .then(res => res.json())
                    .then(clinicData => {
                        if (clinicData.success) {
                            currentClinicData = clinicData.data;
                            renderClinicModal(clinicData.data);
                            bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show();
                        }
                    });
            });
                
            clinicsTable.ajax.reload(null, false);
        } else {
            Swal.fire('Error', data.error || 'Failed to update identity', 'error')
                .then(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show());
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Network error', 'error')
            .then(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show());
    });
}

function updateIdentityStatus(clinicId, status, reason) {
    Swal.fire({ title: 'Updating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch(`${BASE_URL}/api/clinics_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_identity_status', clinic_id: clinicId, status: status, reason: reason })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success', `Identity has been ${status.toLowerCase()}.`, 'success');
            
            // I-refresh ang clinic data para mag-update ang UI
            fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${clinicId}`)
                .then(res => res.json())
                .then(clinicData => {
                    if (clinicData.success) {
                        currentClinicData = clinicData.data;
                        renderClinicModal(clinicData.data);
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show();
                    }
                });
                
            clinicsTable.ajax.reload(null, false);
        } else {
            Swal.fire('Error', data.error || 'Failed to update identity', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Network error', 'error');
    });
}

// ==========================
// ACTIVATE BUTTON (footer) + POPUP
// ==========================
function isViolation(doc) {
    const f = (doc.ocr_flag || '').toLowerCase();
    return !!f && !f.includes('blur');
}

function getClinicSuggestions(clinic) {
    const docs = clinic.documents || [];
    const idv = clinic.identity || null; // <--- Kunin ang identity data

    if (docs.length === 0) return [];

    const allApproved  = docs.every(d => d.status === 'Approved');
    const allDeclined  = docs.every(d => d.status === 'Rejected');
    const anyExpired   = docs.some(d => isDocExpired(d.expires_at));
    const allExpired   = docs.every(d => isDocExpired(d.expires_at));
    const anyViolation = docs.some(isViolation);

    // CHECK: Dapat may Identity at Approved
    const idvApproved = idv && idv.status === 'Approved';

    const out = [];
    // LALABAS LANG ANG ACTIVATE KUNG: All docs Approved + No Expired + No Violation + IDENTITY APPROVED
    if (allApproved && !anyExpired && !anyViolation && idvApproved && clinic.status !== 'Active') {
        out.push({ status: 'Active', label: 'Activate Clinic', cls: 'btn-success', icon: 'bi-check-circle',
                   msg: 'All documents and identity are approved.' });
    }
    
    // Kapag may rejected, pwedeng mag-Suspend/Reject pa rin
    if ((allExpired || anyViolation) && clinic.status !== 'Suspended') {
        out.push({ status: 'Suspended', label: 'Suspend Clinic', cls: 'btn-warning', icon: 'bi-pause-circle',
                   msg: anyViolation ? 'A document has a violation flag.' : 'All documents are expired.' });
    }
    if (allDeclined && clinic.status !== 'Rejected') {
        out.push({ status: 'Rejected', label: 'Reject Clinic', cls: 'btn-danger', icon: 'bi-x-circle',
                   msg: 'All documents were declined.' });
    }
    
    return out;
}

function renderClinicActions(clinic) {
    const footer  = document.getElementById('clinicFooter');
    const btnWrap = document.getElementById('clinicFooterButtons');
    const msgEl   = document.getElementById('clinicFooterMsg');
    if (!footer) return;

    const suggestions = getClinicSuggestions(clinic);
    footer.classList.toggle('d-none', suggestions.length === 0);

    msgEl.textContent = suggestions.map(s => s.msg).join(' ');
    btnWrap.innerHTML = suggestions.map(s => `
        <button type="button" class="btn ${s.cls}" onclick="confirmClinicAction('${s.status}')">
            <i class="bi ${s.icon}"></i> ${s.label}
        </button>`).join('');
}

function confirmClinicAction(status) {
    const clinic = currentClinicData;
    if (!clinic) return;

    if (status === 'Active') {
        checkClinicStatusPrompt(clinic, clinic.documents || []);
        return;
    }

    const isReject = status === 'Rejected';
    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    Swal.fire({
        title: isReject ? 'Reject Clinic?' : 'Suspend Clinic?',
        html: `<b>${escapeHtml(clinic.clinic_name)}</b> will be marked as <b>${status}</b>.`,
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Reason',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: isReject ? 'Yes, Reject' : 'Yes, Suspend',
        confirmButtonColor: isReject ? '#dc3545' : '#f59e0b',
        inputValidator: v => v ? null : 'Reason is required'
    }).then(r => {
        if (r.isConfirmed) updateClinicStatusSilently(clinic.id, status, r.value);
        else modalInstance.show();
    });
}

function checkClinicStatusPrompt(clinic, docs) {
    const canActivate = getClinicSuggestions(clinic).some(s => s.status === 'Active');
    if (!canActivate) return false;

    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    Swal.fire({
        title: 'Activate Clinic?',
        html: `All documents for <b>${escapeHtml(clinic.clinic_name)}</b> have been Approved. Do you want to activate the clinic now?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Yes, Activate',
        cancelButtonText: 'Not yet'
    }).then(r => {
        if (r.isConfirmed) {
            updateClinicStatusSilently(clinic.id, 'Active');
        } else {
            modalInstance.show();
        }
    });
    return true;
}

// ==========================
// DOCUMENT REVIEW FUNCTIONS
// ==========================
window.showRejectModal = function(docId) {
    if (!currentClinicData || !currentClinicData.documents) {
        showToast('error', 'Clinic data not loaded. Please refresh.');
        return;
    }

    const doc = currentClinicData.documents.find(d => d.id === docId);
    const currentStatus = doc ? (doc.status || 'Pending') : 'Pending';

    if (currentStatus !== 'Pending') {
        showToast('error', 'This document has already been reviewed.');
        return;
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    Swal.fire({
        title: 'Decline Document',
        input: 'textarea',
        inputLabel: 'Reason for declining',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: 'Decline Document',
        confirmButtonColor: '#dc3545',
        inputValidator: value => { if (!value) return 'Reason is required'; }
    }).then(result => {
        modalInstance.show();
        if (result.isConfirmed) {
            reviewDocument(docId, 'Rejected', result.value);
        }
    });
};

window.reviewDocument = function(docId, status, reason = '') {
    if (!currentClinicData || !currentClinicData.documents) {
        showToast('error', 'Clinic data not loaded. Please refresh.');
        return;
    }

    const doc = currentClinicData.documents.find(d => d.id === docId);
    const currentStatus = doc ? (doc.status || 'Pending') : 'Pending';

    if (currentStatus !== 'Pending') {
        showToast('error', 'This document has already been reviewed.');
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

        window.refreshSplitView();
        clinicsTable.ajax.reload(null, false);
        loadStats();

        const prompted = status === 'Approved'
            ? checkClinicStatusPrompt(currentClinicData, currentClinicData.documents)
            : false;

        if (!prompted) {
            const uiLabel = status === 'Rejected' ? 'declined' : status.toLowerCase();
            showToast('success', `Document ${uiLabel}`);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('error', 'Network error updating document');
    });
};

window.resetDocAttempts = function(docId) {
    if (!currentClinicData) {
        showToast('error', 'Clinic data not loaded. Please refresh.');
        return;
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal'));
    modalInstance.hide();

    Swal.fire({
        title: 'Re-open Document for Re-upload?',
        html: `
            <p>This will allow the clinic to <b>re-upload a corrected copy immediately</b> — overriding the "one submission per day" rule.</p>
            <p class="text-muted small mb-0">The document status will be set to <b>Awaiting Re-upload</b> and the old file will be cleared.</p>
        `,
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Reason for re-opening',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: 'Yes, re-open',
        confirmButtonColor: '#f59e0b',
        inputValidator: value => value ? null : 'Reason is required'
    }).then(result => {
        modalInstance.show();
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
                return Swal.fire('Error', data.error || 'Failed to re-open', 'error');
            }

            Swal.fire({
                icon: 'success',
                title: 'Document Re-opened',
                text: 'The clinic can now re-upload this document immediately.',
                timer: 1500,
                showConfirmButton: false
            });

            fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${currentClinicId}`)
                .then(res => res.json())
                .then(clinicData => {
                    if (clinicData.success) {
                        currentClinicData = clinicData.data;
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

// ==========================
// UPDATE CLINIC STATUS
// ==========================
function updateClinicStatusSilently(id, status, reason = 'Confirmed by admin during document review') {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch(`${BASE_URL}/api/clinics_api.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: id, status: status, reason: reason })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: `Clinic is now ${status}`,
                timer: 1500,
                showConfirmButton: false
            });

            fetch(`${BASE_URL}/api/clinics_api.php?action=get_clinic&id=${id}`)
                .then(res => res.json())
                .then(clinicData => {
                    if (clinicData.success) {
                        currentClinicData = clinicData.data;
                        renderClinicModal(clinicData.data);
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show();
                    }
                });

            clinicsTable.ajax.reload(null, false);
            loadStats();
        } else {
            Swal.fire('Error', data.error || 'Failed to update clinic status', 'error')
                .then(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show());
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Network error', 'error');
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
        if (tr.querySelector('td[colspan]')) return;
        const cells = tr.querySelectorAll('td');
        tableRows += '<tr>';
        cells.forEach((td, i) => {
            if (i === 0) return;
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

function isDocExpired(dateString) {
    if (!dateString) return false;
    const exp = new Date(dateString);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return exp < today;
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