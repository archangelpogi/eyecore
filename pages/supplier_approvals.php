<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// pages/supplier_approvals.php
// SuperAdmin — Supplier Approvals Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security check
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'SuperAdmin') {
    die('Access denied. SuperAdmin only.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

global $pdo;

// ============================================
// FETCH STATS
// ============================================
try {
    $pendingCount  = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'Pending'")->fetchColumn();
    $approvedCount = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'Active'")->fetchColumn();
    $rejectedCount = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'Rejected'")->fetchColumn();
    $totalCount    = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = $approvedCount = $rejectedCount = $totalCount = 0;
}

// ============================================
// FETCH SUPPLIERS
// ============================================
try {
    $stmt = $pdo->query("
        SELECT 
            s.id, s.supplier_code, s.supplier_name, s.contact_person, 
            s.email, s.mobile, s.phone, s.address, s.city, s.state, 
            s.country, s.postal_code, s.tax_id, s.payment_terms, 
            s.category, s.website, s.status, s.created_at,
            s.approved_by, s.approved_at, s.rejection_reason,
            u.email AS user_email, u.status AS user_status,
            u.email_verified_at,
            (SELECT COUNT(*) FROM supplier_documents WHERE supplier_id = s.id) AS doc_count,
            (SELECT COUNT(*) FROM supplier_documents WHERE supplier_id = s.id AND status = 'Pending') AS pending_docs
        FROM suppliers s
        LEFT JOIN users u ON u.id = s.created_by
        ORDER BY 
            FIELD(s.status, 'Pending', 'Rejected', 'Active', 'Suspended', 'Inactive'),
            s.created_at DESC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
    $error = "Failed to load suppliers: " . $e->getMessage();
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Approvals - SuperAdmin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <style>
        :root {
            --primary:#0F766E; --secondary:#0D9488; --bg:#F8FAFC; --card:#fff;
            --text:#0F172A; --muted:#64748B; --border:#E2E8F0; --radius:12px;
        }
        body { background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; font-size:.9rem; }
        .text-muted { color:var(--muted) !important; }
        .page-title { font-size:1.25rem; font-weight:700; margin:0; }
        .page-desc { color:var(--muted); font-size:.85rem; margin:2px 0 0; }

        /* Stat cards */
        .kcard { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:12px 14px; height:100%; display:flex; justify-content:space-between; align-items:center; }
        .klabel { color:var(--muted); font-size:.74rem; font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
        .kvalue { font-size:1.55rem; font-weight:700; line-height:1.25; font-variant-numeric:tabular-nums; }
        .kicon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .kicon svg { width:16px; height:16px; }

        /* Panel: toolbar + table */
        .panel { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
        .toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px 14px; border-bottom:1px solid var(--border); }
        .search-box { position:relative; flex:1 1 240px; max-width:340px; }
        .search-box svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); width:14px; height:14px; color:var(--muted); pointer-events:none; }
        .search-box input { padding-left:32px; }
        .toolbar .form-select { width:auto; min-width:150px; }
        .toolbar .spacer { flex:1 1 auto; }
        .form-control:focus, .form-select:focus { border-color:var(--secondary); box-shadow:0 0 0 .2rem rgba(13,148,136,.2); }

        /* Table */
        #suppliersTable { margin:0 !important; border-collapse:collapse !important; }
        #suppliersTable thead th { font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); background:#F8FAFC; border-bottom:1px solid var(--border); padding:9px 14px; white-space:nowrap; }
        #suppliersTable tbody td { padding:10px 14px; border-color:#EEF2F6; vertical-align:middle; }
        #suppliersTable.table-hover tbody tr:hover { background:#F8FAFC; }
        .supplier-row { cursor:pointer; }
        .user-avatar { width:32px; height:32px; border-radius:50%; background:var(--secondary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px; flex-shrink:0; }

        /* Badges */
        .badge { font-weight:600; font-size:.72rem; padding:.35em .65em; border-radius:6px; }
        .badge-pending { background:#FEF3C7; color:#92400E; }
        .badge-active { background:#DCFCE7; color:#166534; }
        .badge-rejected { background:#FEE2E2; color:#991B1B; }
        .badge-suspended { background:#FEF3C7; color:#92400E; }
        .badge-inactive { background:#F1F5F9; color:#475569; }
        .verify-note { font-size:.72rem; display:inline-flex; align-items:center; gap:3px; margin-left:6px; }
        .doc-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:6px; font-size:.72rem; font-weight:600; }
        .doc-chip.complete { background:#DCFCE7; color:#166534; }
        .doc-chip.pending { background:#FEF3C7; color:#92400E; }
        .doc-chip.missing { background:#F1F5F9; color:#94A3B8; }
        .action-btns .btn { padding:3px 10px; font-size:.78rem; background:var(--secondary); border-color:var(--secondary); }
        .action-btns .btn:hover { background:var(--primary); border-color:var(--primary); }

        /* DataTables footer + buttons */
        .dt-footer { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px; padding:10px 14px; border-top:1px solid var(--border); }
        .dataTables_info { padding:0 !important; color:var(--muted); font-size:.8rem; }
        .dataTables_paginate { padding:0 !important; }
        .page-item.active .page-link { background:var(--secondary); border-color:var(--secondary); }
        .page-link { color:var(--primary); font-size:.8rem; }
        div.dt-buttons .btn { font-size:.78rem; padding:.25rem .6rem; background:#fff; border:1px solid var(--border); color:var(--text); }
        div.dt-buttons .btn:hover { background:#F8FAFC; }
        @media (max-width:575.98px) { .search-box, .toolbar .form-select { flex:1 1 100%; max-width:none; } .toolbar .spacer { display:none; } }

        /* ===== Modal ===== */
        #supplierModal .modal-content { border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
        #supplierModal .modal-header { background:#fff; border-bottom:1px solid var(--border); padding:14px 20px; }
        #supplierModal .modal-title { font-size:1.05rem; font-weight:700; }
        #supplierModal .modal-footer { background:#F8FAFC; border-top:1px solid var(--border); }
        .info-label { font-size:.7rem; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; font-weight:600; margin-bottom:2px; }
        .info-value { font-size:.88rem; color:var(--text); font-weight:500; }
        .section-label { font-size:.72rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }

        .split-layout { display:grid; grid-template-columns:280px 1fr; gap:1.25rem; height:55vh; }
        @media (max-width:768px) {
            .split-layout { grid-template-columns:1fr; height:auto; }
            .doc-sidebar { max-height:200px; }
            .doc-preview-area { min-height:400px; }
        }
        .doc-sidebar { overflow-y:auto; border-right:1px solid var(--border); padding-right:10px; }
        .doc-tab { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid transparent; border-radius:8px; cursor:pointer; margin-bottom:6px; transition:background .15s; }
        .doc-tab:hover { background:#F8FAFC; }
        .doc-tab.active { background:#F0FDFA; border-color:var(--secondary); }
        .doc-tab-icon { width:32px; height:32px; border-radius:8px; background:#F1F5F9; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .doc-tab-icon svg { width:16px; height:16px; }
        .doc-preview-area { display:flex; flex-direction:column; height:100%; }
        .preview-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:.5rem; }
        .preview-body { flex:1; background:#F8FAFC; border:1px solid var(--border); border-radius:var(--radius); padding:.75rem; overflow-y:auto; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .preview-body iframe, .preview-body img { max-width:100%; max-height:100%; border-radius:8px; background:#fff; }
        #supplierModal .badge.bg-success { background:#DCFCE7 !important; color:#166534; }
        #supplierModal .badge.bg-danger { background:#FEE2E2 !important; color:#991B1B; }
        #supplierModal .badge.bg-warning { background:#FEF3C7 !important; color:#92400E !important; }
        #supplierModal .badge.bg-secondary { background:#F1F5F9 !important; color:#475569; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-3 px-lg-4">

    <!-- HEADER -->
    <div class="mb-3">
        <h1 class="page-title">Supplier Approval</h1>
        <p class="page-desc">Review and manage supplier applications</p>
    </div>

    <!-- STATS -->
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="kcard">
                <div><div class="klabel">Pending Review</div><div class="kvalue"><?= e($pendingCount) ?></div></div>
                <div class="kicon" style="background:#FEF3C7;color:#D97706;"><i data-lucide="clock"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kcard">
                <div><div class="klabel">Approved</div><div class="kvalue"><?= e($approvedCount) ?></div></div>
                <div class="kicon" style="background:#DCFCE7;color:#16A34A;"><i data-lucide="check-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kcard">
                <div><div class="klabel">Rejected</div><div class="kvalue"><?= e($rejectedCount) ?></div></div>
                <div class="kicon" style="background:#FEE2E2;color:#DC2626;"><i data-lucide="x-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kcard">
                <div><div class="klabel">Total Suppliers</div><div class="kvalue"><?= e($totalCount) ?></div></div>
                <div class="kicon" style="background:#F0FDFA;color:#0D9488;"><i data-lucide="building-2"></i></div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR + TABLE (one panel) -->
    <div class="panel">
        <div class="toolbar">
            <div class="search-box">
                <i data-lucide="search"></i>
                <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search company, contact, or email…">
            </div>
            <select id="statusFilter" class="form-select form-select-sm">
                <option value="" selected>All Statuses</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="Suspended">Suspended</option>
            </select>
            <select id="categoryFilter" class="form-select form-select-sm">
                <option value="">All Categories</option>
                <?php
                $cats = array_unique(array_column($suppliers, 'category'));
                sort($cats);
                foreach ($cats as $cat) {
                    if (!empty($cat)) echo "<option value='" . e($cat) . "'>" . e($cat) . "</option>";
                }
                ?>
            </select>
            <span class="spacer"></span>
            <div id="exportBtns"></div>
        </div>

        <div class="table-responsive">
            <table id="suppliersTable" class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>Category</th>
                        <th>Documents</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $s):
                        $statusClass = match($s['status']) {
                            'Pending'   => 'badge-pending',
                            'Active'    => 'badge-active',
                            'Rejected'  => 'badge-rejected',
                            'Suspended' => 'badge-suspended',
                            default     => 'badge-inactive'
                        };
                        $statusLabel = match($s['status']) {
                            'Pending'   => 'Pending',
                            'Active'    => 'Approved',
                            'Rejected'  => 'Rejected',
                            'Suspended' => 'Suspended',
                            default     => $s['status']
                        };

                        $docCount = (int)$s['doc_count'];
                        $pendingDocs = (int)$s['pending_docs'];
                        if ($docCount >= 4) {
                            $docClass = 'complete';
                            $docLabel = "{$docCount} uploaded";
                        } elseif ($docCount > 0) {
                            $docClass = 'pending';
                            $docLabel = "{$docCount} partial";
                        } else {
                            $docClass = 'missing';
                            $docLabel = "No documents";
                        }
                        $ts = strtotime($s['created_at']);
                    ?>
                        <tr class="supplier-row" data-supplier-id="<?= (int)$s['id'] ?>" onclick="viewSupplier(<?= (int)$s['id'] ?>)">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar"><?= e(strtoupper(substr($s['supplier_name'], 0, 2))) ?></div>
                                    <div>
                                        <div class="fw-semibold"><?= e($s['supplier_name']) ?></div>
                                        <small class="text-muted"><?= e($s['supplier_code']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div><?= e($s['contact_person']) ?></div>
                                <small class="text-muted"><?= e($s['email']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e($s['category'] ?: 'General') ?></span>
                            </td>
                            <td>
                                <span class="doc-chip <?= $docClass ?>">
                                    <i data-lucide="file-text" style="width:12px;height:12px;"></i>
                                    <?= e($docLabel) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?>"><?= e($statusLabel) ?></span>
                                <?php if ($s['email_verified_at']): ?>
                                    <span class="verify-note text-success" title="Email verified">
                                        <i data-lucide="check" style="width:12px;height:12px;"></i> Verified
                                    </span>
                                <?php else: ?>
                                    <span class="verify-note text-muted" title="Email not verified">
                                        <i data-lucide="clock" style="width:12px;height:12px;"></i> Unverified
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-order="<?= (int)$ts ?>">
                                <div class="small"><?= date('M d, Y', $ts) ?></div>
                                <small class="text-muted"><?= date('h:i A', $ts) ?></small>
                            </td>
                            <td>
                                <div class="action-btns d-flex gap-1">
                                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewSupplier(<?= (int)$s['id'] ?>)">
                                        <i data-lucide="eye" style="width:14px;height:14px;"></i> View
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- SUPPLIER DETAILS MODAL -->
<!-- ============================================ -->
<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="modalSupplierName">Supplier Details</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white" id="supplierModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-teal" role="status"></div>
                    <p class="mt-2 text-muted">Loading details...</p>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center" id="supplierModalFooter">
                <!-- Final Decision buttons will be injected here -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    lucide.createIcons();

    const CSRF_TOKEN = '<?= $csrfToken ?>';

    // HTML-escape helpers for text coming from the API (display only)
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const jsq = s => esc(String(s ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"));

    $(document).ready(function() {
        const table = $('#suppliersTable').DataTable({
            dom: 'Brt<"dt-footer"ip>',          // built-in search removed: we use #searchInput
            buttons: ['copy', 'excel', 'print'],
            pageLength: 10,
            order: [],                           // keep server order (Pending first, newest first)
            columnDefs: [
                { orderable: false, targets: [3, 6] }
            ],
            language: { emptyTable: 'No suppliers found.', zeroRecords: 'No suppliers match your filters.' }
        });

        table.buttons().container().appendTo('#exportBtns');

        // Status column text is "Approved / Pending / ..." (+ Verified note), so match from the start
        $('#statusFilter').on('change', function() {
            const v = this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) : '';
            table.column(4).search(v, true, false).draw();
        });
        $('#categoryFilter').on('change', function() {
            const v = this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '';
            table.column(2).search(v, true, false).draw();
        });
        $('#searchInput').on('keyup', function() {
            table.search(this.value).draw();
        });
    });

    // ============================================
    // GLOBAL STATES FOR MODAL TABS
    // ============================================
    let currentSupplierData = null;
    let currentDocsData = [];

    // ============================================
    // VIEW SUPPLIER DETAILS
    // ============================================
    function viewSupplier(id) {
        const modalEl = document.getElementById('supplierModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const body = document.getElementById('supplierModalBody');
        const footer = document.getElementById('supplierModalFooter');
        const title = document.getElementById('modalSupplierName');

        body.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-teal" role="status"></div>
                <p class="mt-2 text-muted">Loading supplier details...</p>
            </div>`;
        footer.innerHTML = '';
        title.textContent = 'Loading...';

        modal.show();

        fetch('api/supplier_action.php?action=view_json&id=' + id)
            .then(res => res.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to load');

                currentSupplierData = data.supplier;
                currentDocsData = data.documents;

                title.textContent = `Supplier Details - ${data.supplier.supplier_name}`;

                renderSupplierModal(data.supplier, data.documents);
            })
            .catch(err => {
                body.innerHTML = `<div class="alert alert-danger">Failed to load details. Please ensure API returns JSON.</div>`;
            });
    }

    function renderSupplierModal(supplier, documents) {
        const body = document.getElementById('supplierModalBody');
        const footer = document.getElementById('supplierModalFooter');

        // 1. Check status ng docs
        const pendingCount = documents.filter(d => d.status === 'Pending').length;
        const totalDocs = documents.length;
        const isAllApproved = (pendingCount === 0 && totalDocs > 0);
        const hasRejected = documents.some(d => d.status === 'Rejected');

        // 2. Build TOP INFO SECTION
        const createdDate = supplier.created_at ? new Date(supplier.created_at).toLocaleString() : 'N/A';
        const approvedDate = supplier.approved_at ? new Date(supplier.approved_at).toLocaleString() : 'N/A';
        const statusBadge = supplier.status === 'Active' ? 'bg-success' : (supplier.status === 'Rejected' ? 'bg-danger' : 'bg-warning text-dark');

        let topInfoHtml = `
            <div class="row g-4 mb-4 border-bottom pb-4">
                <!-- KALIWA: Company Info -->
                <div class="col-md-6">
                    <div class="section-label mb-3">Company Information</div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="info-label">Supplier Code</div>
                            <div class="info-value">${esc(supplier.supplier_code)}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Category</div>
                            <div class="info-value">${esc(supplier.category || 'General')}</div>
                        </div>
                        <div class="col-sm-12">
                            <div class="info-label">Company Name</div>
                            <div class="info-value fw-bold">${esc(supplier.supplier_name)}</div>
                        </div>
                        <div class="col-sm-12">
                            <div class="info-label">Address</div>
                            <div class="info-value">${esc(supplier.address || 'N/A')}, ${esc(supplier.city || '')}, ${esc(supplier.state || '')} ${esc(supplier.postal_code || '')}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Contact Number</div>
                            <div class="info-value">${esc(supplier.mobile || supplier.phone || 'N/A')}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Email</div>
                            <div class="info-value text-truncate">${esc(supplier.email)}</div>
                        </div>
                    </div>
                </div>

                <!-- KANAN: Verification Details -->
                <div class="col-md-6">
                    <div class="section-label mb-3">Verification Details</div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="info-label">Status</div>
                            <div class="info-value"><span class="badge ${statusBadge}">${esc(supplier.status)}</span></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Registered</div>
                            <div class="info-value">${esc(createdDate)}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Approved At</div>
                            <div class="info-value">${supplier.status === 'Active' ? esc(approvedDate) : '—'}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="info-label">Contact Person</div>
                            <div class="info-value">${esc(supplier.contact_person)}</div>
                        </div>
                        ${supplier.website ? `
                        <div class="col-sm-12">
                            <div class="info-label">Website</div>
                            <div class="info-value text-primary">${esc(supplier.website)}</div>
                        </div>` : ''}
                    </div>
                </div>
            </div>
        `;

        // 3. Build LEFT Sidebar (Docs List)
        let docListHtml = '';
        if (documents.length === 0) {
            docListHtml = `<div class="text-center py-4 text-muted"><i data-lucide="folder-open" class="mb-2" style="width:32px;height:32px;"></i><br>No documents uploaded</div>`;
        } else {
            documents.forEach((doc, index) => {
                const isActive = index === 0 ? 'active' : '';
                const isPdf = doc.mime_type && doc.mime_type.toLowerCase() === 'application/pdf';
                const icon = isPdf ? 'file-text' : 'image';

                let badgeColor = 'secondary';
                if (doc.status === 'Verified') badgeColor = 'success';
                else if (doc.status === 'Pending') badgeColor = 'warning text-dark';
                else if (doc.status === 'Rejected') badgeColor = 'danger';

                docListHtml += `
                    <div class="doc-tab ${isActive}" onclick="switchDocTab(${index}, this)">
                        <div class="doc-tab-icon">
                            <i data-lucide="${icon}" style="color:#0d9488;"></i>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div class="fw-semibold text-truncate" style="font-size:0.85rem;">${esc(doc.document_name)}</div>
                            <span class="badge bg-${badgeColor} mt-1" style="font-size:0.65rem;">${esc(doc.status)}</span>
                        </div>
                    </div>
                `;
            });
        }

        // 4. Build Full Layout
        body.innerHTML = `
            ${topInfoHtml}

            <div class="section-label mb-3">Verification Documents (${totalDocs})</div>

            <div class="split-layout">
                <!-- LEFT SIDEBAR (Documents) -->
                <div class="doc-sidebar">
                    ${docListHtml}
                </div>

                <!-- RIGHT PREVIEW -->
                <div class="doc-preview-area" id="docPreviewArea">
                    ${documents.length > 0 ? '' : '<div class="text-center text-muted">Select a document to preview</div>'}
                </div>
            </div>
        `;

        if (typeof lucide !== 'undefined') lucide.createIcons();

        // Initialize first doc
        if (documents.length > 0) {
            renderDocPreview(documents[0], supplier);
        }

        // 5. Handle Footer
        if (supplier.status === 'Active') {
            footer.innerHTML = `<div class="text-success fw-bold"><i data-lucide="check-circle" class="me-1" style="width:18px;height:18px;"></i> Supplier is Active</div>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;
        } else if (supplier.status === 'Rejected') {
             footer.innerHTML = `<div class="text-danger fw-bold"><i data-lucide="x-circle" class="me-1" style="width:18px;height:18px;"></i> Supplier is Rejected</div>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;
        } else {
            if (isAllApproved && !hasRejected) {
                footer.innerHTML = `
                    <div class="text-muted small"><i data-lucide="info" class="me-1" style="width:14px;height:14px;"></i> All documents approved. You can now make a final decision.</div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-danger" onclick="rejectSupplierFromModal(${Number(supplier.id)}, '${jsq(supplier.supplier_name)}')">
                            <i data-lucide="x" class="me-1" style="width:16px;height:16px;"></i> Reject Supplier
                        </button>
                        <button type="button" class="btn btn-success" onclick="approveSupplierFromModal(${Number(supplier.id)}, '${jsq(supplier.supplier_name)}')">
                            <i data-lucide="check" class="me-1" style="width:16px;height:16px;"></i> Approve Supplier
                        </button>
                    </div>
                `;
            } else {
                footer.innerHTML = `
                    <div class="text-warning text-dark fw-bold small"><i data-lucide="clock" class="me-1" style="width:16px;height:16px;"></i> Waiting for all documents to be verified before final decision.</div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                `;
            }
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    // ============================================
    // SWITCH DOC TABS (RIGHT PREVIEW UPDATE)
    // ============================================
    function switchDocTab(index, tabElement) {
        document.querySelectorAll('.doc-tab').forEach(el => el.classList.remove('active'));
        tabElement.classList.add('active');

        const doc = currentDocsData[index];
        renderDocPreview(doc, currentSupplierData);
    }

    // ============================================
    // RENDER PREVIEW IN RIGHT PANEL
    // ============================================
    function renderDocPreview(doc, supplier) {
        const area = document.getElementById('docPreviewArea');
        const fileUrl = '../' + doc.file_path;
        const isPdf = doc.mime_type && doc.mime_type.toLowerCase() === 'application/pdf';

        let statusBadge = 'bg-secondary';
        if (doc.status === 'Verified') statusBadge = 'bg-success';
        else if (doc.status === 'Pending') statusBadge = 'bg-warning text-dark';
        else if (doc.status === 'Rejected') statusBadge = 'bg-danger';

        let actionBtns = '';
        if (doc.status === 'Pending') {
            actionBtns = `
                <div class="d-flex gap-2 mt-3 w-100">
                    <button class="btn btn-sm btn-outline-danger flex-fill" onclick="declineDocument(${Number(doc.id)}, '${jsq(doc.document_name)}', ${Number(supplier.id)})">
                        <i data-lucide="x" class="me-1" style="width:14px;height:14px;"></i> Decline
                    </button>
                    <button class="btn btn-sm btn-success flex-fill" onclick="approveDocument(${Number(doc.id)}, '${jsq(doc.document_name)}', ${Number(supplier.id)})">
                        <i data-lucide="check" class="me-1" style="width:14px;height:14px;"></i> Approve
                    </button>
                </div>
            `;
        } else if (doc.status === 'Rejected' && doc.rejection_reason) {
            actionBtns = `
                <div class="alert alert-danger py-2 px-3 mt-3 mb-0 w-100" style="font-size:0.85rem;">
                    <strong>Rejection Reason:</strong> ${esc(doc.rejection_reason)}
                </div>
            `;
        }

        // Details block (Owner, Release, Expiry)
        let detailsHtml = `
            <div class="row g-2 mb-3 w-100">
                <div class="col-md-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block" style="font-size:0.65rem; text-transform:uppercase;">Owner</small>
                        <span class="fw-semibold text-truncate d-block" style="font-size:0.85rem;">${esc(supplier.contact_person || 'N/A')}</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block" style="font-size:0.65rem; text-transform:uppercase;">Release Date</small>
                        <span class="fw-semibold" style="font-size:0.85rem;">${esc(doc.release_date || 'N/A')}</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-2 border rounded bg-light">
                        <small class="text-muted d-block" style="font-size:0.65rem; text-transform:uppercase;">Expiry Date</small>
                        <span class="fw-semibold" style="font-size:0.85rem;">${esc(doc.expiry_date || 'N/A')}</span>
                    </div>
                </div>
            </div>
        `;

        let previewHtml = '';
        if (isPdf) {
            previewHtml = `<iframe src="${esc(fileUrl)}" style="width:100%; height:100%; min-height:350px; border:none; border-radius:8px; background:#fff;" title="${esc(doc.document_name)}"></iframe>`;
        } else {
            previewHtml = `<img src="${esc(fileUrl)}" alt="${esc(doc.document_name)}" style="max-width:100%; max-height:100%; border-radius:8px; object-fit:contain;">`;
        }

        area.innerHTML = `
            <!-- HEADER -->
            <div class="preview-header w-100">
                <div>
                    <h5 class="fw-bold mb-1" style="font-size:1rem;">${esc(doc.document_name)}</h5>
                    <div class="text-muted small">
                        Uploaded: ${esc(new Date(doc.upload_date).toLocaleString())}
                    </div>
                </div>
                <span class="badge ${statusBadge} px-3 py-2">${esc(doc.status)}</span>
            </div>

            <!-- DETAILS (Owner, Expiry, etc) -->
            ${detailsHtml}

            <!-- PREVIEW FRAME -->
            <div class="preview-body w-100 mb-2">
                ${previewHtml}
            </div>

            <!-- DOC ACTIONS -->
            ${actionBtns}
        `;

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    // ============================================
    // APPROVE DOCUMENT
    // ============================================
    function approveDocument(docId, docLabel, supplierId) {
        Swal.fire({
            icon: 'question',
            title: 'Approve Document?',
            html: `Approve <strong>${esc(docLabel)}</strong>?`,
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Yes, Approve'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/supplier_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'approve_document',
                        doc_id: docId,
                        csrf_token: CSRF_TOKEN
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Approved!', timer: 1200, showConfirmButton: false })
                            .then(() => viewSupplier(supplierId));
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error.', 'error'));
            }
        });
    }

    // ============================================
    // DECLINE DOCUMENT
    // ============================================
    function declineDocument(docId, docLabel, supplierId) {
        Swal.fire({
            title: 'Decline Document',
            html: `
                <p>Decline <strong>${esc(docLabel)}</strong>?</p>
                <textarea id="swal-decline-reason" class="swal2-textarea" placeholder="Enter reason for declining..." style="margin-top:10px;"></textarea>
            `,
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Decline',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                setTimeout(() => {
                    const textarea = document.getElementById('swal-decline-reason');
                    if (textarea) textarea.focus();
                }, 100);
            },
            preConfirm: () => {
                const reason = document.getElementById('swal-decline-reason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Reason is required');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/supplier_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'decline_document',
                        doc_id: docId,
                        reason: result.value,
                        csrf_token: CSRF_TOKEN
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Declined', timer: 1200, showConfirmButton: false })
                            .then(() => viewSupplier(supplierId));
                    } else {
                        Swal.fire('Error', data.message, 'error').then(() => viewSupplier(supplierId));
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error.', 'error').then(() => viewSupplier(supplierId)));
            }
        });
    }

    // ============================================
    // FINAL DECISION: APPROVE SUPPLIER
    // ============================================
    function approveSupplierFromModal(id, name) {
        Swal.fire({
            icon: 'question',
            title: 'Approve Supplier?',
            html: `Approve <strong>${esc(name)}</strong>?<br><small class="text-muted">Supplier account will be marked as Active.</small>`,
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Yes, Approve'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                fetch('api/supplier_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'approve',
                        id: id,
                        csrf_token: CSRF_TOKEN
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Approved!', text: data.message, timer: 2000, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error.', 'error'));
            }
        });
    }

    // ============================================
    // FINAL DECISION: REJECT SUPPLIER
    // ============================================
    function rejectSupplierFromModal(id, name) {
        Swal.fire({
            title: 'Reject Supplier',
            html: `
                <p>You are about to reject <strong>${esc(name)}</strong>.</p>
                <textarea id="swal-reject-reason" class="swal2-textarea" placeholder="Enter rejection reason..." style="margin-top:10px;"></textarea>
            `,
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Reject',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                setTimeout(() => {
                    const textarea = document.getElementById('swal-reject-reason');
                    if (textarea) textarea.focus();
                }, 100);
            },
            preConfirm: () => {
                const reason = document.getElementById('swal-reject-reason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Reason is required');
                    return false;
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('api/supplier_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'reject',
                        id: id,
                        reason: result.value,
                        csrf_token: CSRF_TOKEN
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Rejected', timer: 1500, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Network error.', 'error'));
            }
        });
    }
</script>

</body>
</html>