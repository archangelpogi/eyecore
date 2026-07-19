<?php
include __DIR__ . '/../config/db.php';

// Check if user is SuperAdmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    header('Location: ../auth/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Clinic Management - Decision Support System</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {
    background:#f8fafc;
    font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.card-soft {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:12px;
}
.icon-box {
    width:48px;
    height:48px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.score-badge {
    font-weight:600;
    padding:4px 10px;
    border-radius:20px;
    font-size:0.85rem;
}
.score-high { background:#dcfce7; color:#166534; }
.score-medium { background:#fef9c3; color:#854d0e; }
.score-low { background:#fee2e2; color:#991b1b; }
.badge-pending { background:#fef3c7; color:#92400e; }
.badge-active { background:#dcfce7; color:#166534; }
.badge-suspended { background:#f3f4f6; color:#374151; }
.badge-rejected { background:#fee2e2; color:#991b1b; }
.badge-reapplying { background:#e0e7ff; color:#3730a3; }
.risk-low { border-left:4px solid #10b981; }
.risk-medium { border-left:4px solid #f59e0b; }
.risk-high { border-left:4px solid #ef4444; }
.action-btn-sm {
    padding:4px 8px;
    font-size:0.85rem;
}

/* Custom table styles */
#clinicsTable thead th {
    border-bottom: 2px solid #e5e7eb;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #6b7280;
    background: #f9fafb;
    white-space: nowrap;
}
#clinicsTable tbody tr {
    transition: background 0.15s;
}
#clinicsTable tbody tr:hover {
    background: #f0f9ff;
}
.clinics-empty {
    padding: 3rem 0;
    text-align: center;
    color: #9ca3af;
}
.clinics-loading {
    padding: 3rem 0;
    text-align: center;
    color: #6b7280;
}

/* Pagination */
.page-link {
    border-radius: 6px !important;
    margin: 0 2px;
    color: #374151;
    border: 1px solid #e5e7eb;
    font-size: 0.875rem;
}
.page-item.active .page-link {
    background: #0d6efd;
    border-color: #0d6efd;
}
.page-item.disabled .page-link {
    color: #d1d5db;
}

/* Table footer info */
.table-footer-info {
    font-size: 0.875rem;
    color: #6b7280;
}
.length-select {
    display: inline-block;
    width: auto;
    font-size: 0.875rem;
    padding: 4px 8px;
}

/* ============================================================
   DOCUMENT REVIEW SPLIT-VIEW
   ============================================================ */
.doc-review-layout {
    display: flex;
    gap: 16px;
}
.doc-list-panel {
    width: 280px;
    flex-shrink: 0;
    max-height: 520px;
    overflow-y: auto;
    padding-right: 4px;
}
.doc-list-item {
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.15s;
}
.doc-list-item:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.doc-list-item.active {
    border-color: #0d6efd;
    background: #eff6ff;
    box-shadow: 0 0 0 1px #0d6efd inset;
}
.doc-list-item .doc-list-title {
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 4px;
}
.doc-preview-panel {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.doc-preview-frame {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    min-height: 380px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    flex: 1;
}
.doc-preview-frame img,
.doc-preview-frame iframe {
    max-width: 100%;
}
.doc-preview-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    justify-content: flex-end;
}
.doc-preview-empty {
    text-align: center;
    color: #9ca3af;
}

@media (max-width: 768px) {
    .doc-review-layout {
        flex-direction: column;
    }
    .doc-list-panel {
        width: 100%;
        max-height: 220px;
    }
}
</style>
</head>

<body>

<div class="container-fluid p-3 p-md-4">

<!-- HEADER -->
<div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
    <div>
        <h2 class="fw-bold">Clinic Management</h2>
        <p class="text-muted mb-0">Decision Support System - Verify & Manage Clinic Applications</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary px-3 d-flex align-items-center gap-2"
                onclick="exportToExcel()">
            <i class="bi bi-download"></i> Export Excel
        </button>
        <button class="btn btn-outline-success px-3 d-flex align-items-center gap-2"
                onclick="printTable()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<!-- STATS WITH DECISION SUPPORT -->
<div class="row g-3 mb-4" id="statsContainer">
    <div class="col-md-3">
        <div class="card-soft p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Pending Review</small>
                    <h3 id="pendingClinics" class="fw-bold mt-1 mb-2">0</h3>
                </div>
                <div class="icon-box bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-clock"></i>
                </div>
            </div>
            <small class="text-muted d-block">Needs manual verification</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-soft p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Active Clinics</small>
                    <h3 id="activeClinics" class="fw-bold mt-1 mb-2">0</h3>
                </div>
                <div class="icon-box bg-success bg-opacity-10 text-success">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
            <small class="text-muted d-block">Verified and operational</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-soft p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Suspended</small>
                    <h3 id="suspendedClinics" class="fw-bold mt-1 mb-2">0</h3>
                </div>
                <div class="icon-box bg-secondary bg-opacity-10 text-secondary">
                    <i class="bi bi-pause-circle"></i>
                </div>
            </div>
            <small class="text-muted d-block">Temporarily inactive</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-soft p-3 h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">Total Clinics</small>
                    <h3 id="totalClinics" class="fw-bold mt-1 mb-2">0</h3>
                </div>
                <div class="icon-box bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-building"></i>
                </div>
            </div>
            <small class="text-muted d-block">All registered clinics</small>
        </div>
    </div>
</div>

<!-- STATUS TABS -->
<div class="btn-group mb-3" role="group" id="clinicTabs">
    <button type="button" class="btn btn-outline-primary active" onclick="setStatusTab('')">All Clinics</button>
    <button type="button" class="btn btn-outline-warning" onclick="setStatusTab('Pending')">Pending</button>
    <button type="button" class="btn btn-outline-success" onclick="setStatusTab('Active')">Active</button>
    <button type="button" class="btn btn-outline-secondary" onclick="setStatusTab('Suspended')">Suspended</button>
    <button type="button" class="btn btn-outline-danger" onclick="setStatusTab('Rejected')">Rejected</button>
    <button type="button" class="btn btn-outline-info" onclick="setStatusTab('Reapplying')">Reapplying</button>
</div>

<!-- SEARCH & FILTERS -->
<div class="card-soft p-3 mb-4">
    <div class="row g-3 align-items-center">
        <div class="col-md-3">
            <select id="statusFilter" class="form-select" onchange="filterTable()">
                <option value="">All Status</option>
                <option value="Pending">Pending Review</option>
                <option value="Active">Active</option>
                <option value="Suspended">Suspended</option>
                <option value="Rejected">Rejected</option>
                <option value="Reapplying">Reapplying</option>
            </select>
        </div>
        <div class="col-md-3">
            <select id="riskFilter" class="form-select" onchange="filterTable()">
                <option value="">All Risk Levels</option>
                <option value="low">Low Risk</option>
                <option value="medium">Medium Risk</option>
                <option value="high">High Risk</option>
            </select>
        </div>
        <div class="col-md-3">
            <select id="scoreFilter" class="form-select" onchange="filterTable()">
                <option value="">All Scores</option>
                <option value="high">High (80-100)</option>
                <option value="medium">Medium (60-79)</option>
                <option value="low">Low (0-59)</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="globalSearch" class="form-control"
                       placeholder="Search clinics...">
            </div>
        </div>
    </div>
</div>

<!-- DATA TABLE -->
<div class="card-soft overflow-hidden mb-4">
    <!-- Top controls: show entries -->
    <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2 border-bottom">
        <div class="d-flex align-items-center gap-2 table-footer-info">
            Show
            <select id="pageLengthSelect" class="form-select length-select" onchange="changePageLength(this.value)">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            entries
        </div>
        <div id="tableProcessing" class="text-muted small d-none">
            <span class="spinner-border spinner-border-sm me-1"></span> Loading...
        </div>
    </div>

    <div class="table-responsive">
        <table id="clinicsTable" class="table table-hover mb-0" style="width:100%">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                    <th>Clinic Details</th>
                    <th class="d-none d-md-table-cell">Contact</th>
                    <th>Verification Score</th>
                    <th>Risk Level</th>
                    <th>Status</th>
                    <th width="100">Actions</th>
                </tr>
            </thead>
            <tbody id="clinicsTableBody">
                <tr>
                    <td colspan="7" class="clinics-loading">
                        <div class="spinner-border spinner-border-sm me-2"></div> Loading clinics...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bottom: info + pagination -->
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between px-3 py-3 border-top gap-2">
        <div id="tableInfo" class="table-footer-info text-muted">
            Showing 0 to 0 of 0 clinics
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="tablePagination"></ul>
        </nav>
    </div>
</div>

</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="viewModalTitle">Clinic Verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                <div id="clinicDetails">
                    <!-- Clinic details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript Libraries -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const viewModal = document.getElementById('viewModal');
viewModal.addEventListener('shown.bs.modal', function () {
    viewModal.removeAttribute('tabindex');
});
</script>

<!-- Your JS -->
<script src="assets/js/clinics.js?v=<?php echo time(); ?>"></script>
</body>
</html>