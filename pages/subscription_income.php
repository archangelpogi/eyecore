<?php
include __DIR__ . '/../config/db.php';

// --- Only for SuperAdmin (same pattern as 3d_requests.php) ---
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole != 'SuperAdmin') {
    die("Access Denied");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Income - Eyecore Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #0F766E; --secondary: #0D9488;
            --bg: #F8FAFC; --card: #FFFFFF;
            --text: #0F172A; --muted: #64748B; --border: #E2E8F0;
            --success: #16A34A; --warning: #D97706; --danger: #DC2626;
            --radius: 12px;
        }
        body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: .9rem; }
        .text-muted { color: var(--muted) !important; }

        /* Header */
        .page-title { font-size: 1.25rem; font-weight: 700; color: var(--text); margin: 0; }
        .page-desc { color: var(--muted); font-size: .85rem; margin: 2px 0 0; }

        /* Section headings */
        .section { margin-bottom: 1.5rem; }
        .section-title { font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: .6rem; }

        /* Panels (shared card style) */
        .panel { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 1px 2px rgba(15,23,42,.04); padding: 16px 18px; }
        .panel h6 { font-size: .9rem; font-weight: 600; margin: 0; }

        /* Alert (compact) */
        #expiryAlertBanner { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; border-radius: 10px; padding: 8px 12px; font-size: .85rem; gap: 8px; margin-bottom: 1rem !important; }
        #expiryAlertBanner .btn { font-size: .78rem; padding: 3px 10px; background: transparent; color: var(--danger); border: 1px solid #FCA5A5; }
        #expiryAlertBanner .btn:hover { background: var(--danger); color: #fff; }

        /* Status stat cards */
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; height: 100%; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
        .stat-card .stat-row { display: flex; justify-content: space-between; align-items: center; min-height: 52px; }
        .stat-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; }
        .stat-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; color: var(--text); }
        .stat-label { color: var(--muted); font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }
        .status-row > div { flex: 0 0 auto; width: 25%; }
        @media (max-width: 767.98px) { .status-row > div { width: 50%; } }

        /* Revenue cards: left accent bar-less, money-first, tinted */
        .rev-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; height: 100%; }
        .rev-card .rev-label { color: var(--muted); font-size: .75rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .rev-card .rev-label i { color: var(--secondary); }
        .rev-card .rev-value { font-size: 1.35rem; font-weight: 700; color: var(--primary); font-variant-numeric: tabular-nums; margin-top: 4px; }
        .rev-card.rev-count .rev-value { color: var(--text); }
        .rev-row > div { flex: 0 0 auto; width: 25%; }
        @media (max-width: 767.98px) { .rev-row > div { width: 50%; } }

        /* Trial */
        .trial-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        .trial-bar .input-group { width: 170px; }
        .trial-note { color: var(--muted); font-size: .78rem; }
        .btn-eyecore { background: var(--secondary); border-color: var(--secondary); color: #fff; }
        .btn-eyecore:hover { background: var(--primary); border-color: var(--primary); color: #fff; }
        .collapse-toggle { background: none; border: 0; padding: 4px 0; font-size: .8rem; font-weight: 600; color: var(--muted); display: flex; align-items: center; gap: 6px; width: 100%; text-align: left; }
        .collapse-toggle:hover { color: var(--primary); }
        .collapse-toggle .chev { margin-left: auto; transition: transform .2s; }
        .collapse-toggle[aria-expanded="true"] .chev { transform: rotate(180deg); }
        .trial-collapses { border-top: 1px solid var(--border); margin-top: 14px; padding-top: 6px; }

        /* Filter toolbar */
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .toolbar .form-label { font-size: .72rem; font-weight: 600; color: var(--muted); margin-bottom: 2px; }
        .toolbar .tb-field { min-width: 140px; }
        @media (max-width: 575.98px) { .toolbar .tb-field, .toolbar .tb-btn { flex: 1 1 100%; } .toolbar .btn { width: 100%; } }

        /* Tables */
        .table { margin-bottom: 0; font-size: .85rem; }
        .table thead th { font-size: .7rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); background: #F8FAFC; border-bottom: 1px solid var(--border); padding: 9px 12px; white-space: nowrap; }
        .table tbody td { padding: 10px 12px; border-color: #EEF2F6; vertical-align: middle; }
        .table-hover tbody tr:hover { background: #F8FAFC; }
        .badge { font-weight: 600; font-size: .72rem; padding: .35em .6em; border-radius: 6px; }
        .badge-plan { background: #f0fdfa; color: var(--secondary); border: 1px solid #CCFBF1; }
        .table .badge.bg-danger { background: #FEE2E2 !important; color: #B91C1C; }
        .table .badge.bg-warning { background: #FEF3C7 !important; color: #B45309 !important; }
        .table .badge.bg-success { background: #DCFCE7 !important; color: #15803D; }
        .table .badge.bg-secondary { background: #F1F5F9 !important; color: #475569; }
        .pagination .page-link { color: var(--primary); }

        /* Segmented status filter */
        #statusFilterBtns { background: #F1F5F9; border-radius: 9px; padding: 3px; gap: 2px; }
        #statusFilterBtns .btn { border: 0 !important; border-radius: 7px !important; font-size: .78rem; padding: 4px 10px; color: var(--muted); background: transparent; }
        #statusFilterBtns .btn.active { background: #fff; color: var(--text); box-shadow: 0 1px 2px rgba(15,23,42,.1); }
        #statusFilterBtns .btn:hover:not(.active) { color: var(--text); }
        #statusFilterBtns .badge { font-size: .68rem; padding: .25em .5em; }

        /* Modal */
        #expiredModal .modal-content { border-radius: var(--radius); border: 1px solid var(--border); }
        #expiredModal .modal-header { background: #fff !important; color: var(--text) !important; border-bottom: 1px solid var(--border); padding: 12px 16px; }
        #expiredModal .modal-title { font-size: 1rem; font-weight: 600; color: var(--danger); }
        #expiredModal .btn-close { filter: none; }

        /* ---- v2: fewer cards, dividers instead ---- */
        .kpi-panel { padding: 14px 18px; }
        .kpi-panel .section-title { margin-bottom: .4rem; }
        .section-title .hint { text-transform: none; letter-spacing: 0; font-weight: 400; }
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); }
        .kpi { padding: 4px 16px; border-left: 1px solid var(--border); }
        .kpi:first-child { border-left: 0; padding-left: 0; }
        .kpi-label { color: var(--muted); font-size: .74rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .kpi-label i { font-size: .85rem; color: var(--secondary); }
        .kpi-value { font-size: 1.5rem; font-weight: 700; line-height: 1.25; font-variant-numeric: tabular-nums; }
        .kpi-value.money { color: var(--primary); }
        .kpi-link { cursor: pointer; border-radius: 6px; }
        .kpi-link:hover { background: #F8FAFC; }
        .kpi-sep { border: 0; border-top: 1px solid var(--border); margin: 12px 0; opacity: 1; }
        @media (max-width: 767.98px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); row-gap: 10px; }
            .kpi:nth-child(odd) { border-left: 0; padding-left: 0; }
        }
        .chart-split { display: flex; flex-wrap: wrap; }
        @media (min-width: 992px) {
            .chart-main { width: 65%; }
            .chart-side { width: 35%; border-left: 1px solid var(--border); }
        }
        @media (max-width: 991.98px) {
            .chart-main, .chart-side { width: 100%; }
            .chart-side { border-top: 1px solid var(--border); margin-top: 16px; padding-top: 16px; }
        }
        .panel, .btn-eyecore { box-shadow: none; }

        /* ---- v3: tabs + header filters ---- */
        .nav-tabs { border-bottom: 1px solid var(--border); gap: 4px; }
        .nav-tabs .nav-link { border: 0; border-bottom: 2px solid transparent; border-radius: 0; color: var(--muted); font-size: .85rem; font-weight: 600; padding: 8px 12px; background: none; }
        .nav-tabs .nav-link:hover { color: var(--text); }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom-color: var(--primary); background: none; }
        @media (max-width: 575.98px) { .nav-tabs { flex-wrap: nowrap; overflow-x: auto; } .nav-tabs .nav-link { white-space: nowrap; } }

        /* ---- v4: KPI cards ---- */
        .kcard { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; height: 100%; }
        .klabel { color: var(--muted); font-size: .74rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .kvalue { font-size: 1.5rem; font-weight: 700; line-height: 1.3; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .kvalue.money { color: var(--primary); }
        .klink { cursor: pointer; transition: border-color .15s; }
        .klink:hover { border-color: var(--secondary); }

        /* ---- v5: search + pager ---- */
        .search-box { position: relative; min-width: 230px; flex: 1 1 230px; max-width: 320px; }
        .search-box i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .8rem; pointer-events: none; }
        .search-box input { padding-left: 30px; }
        #clinicPager .page-link, #txSearch { font-size: .78rem; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-3 px-lg-4">

    <!-- ========== PAGE HEADER + GLOBAL FILTERS ========== -->
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
        <div>
            <h1 class="page-title">Subscription Income</h1>
            <p class="page-desc">Totoong kita galing sa completed na payments lang</p>
        </div>
        <div class="toolbar">
            <div class="tb-field">
                <label class="form-label" for="filterDateFrom">From</label>
                <input type="date" class="form-control form-control-sm" id="filterDateFrom">
            </div>
            <div class="tb-field">
                <label class="form-label" for="filterDateTo">To</label>
                <input type="date" class="form-control form-control-sm" id="filterDateTo">
            </div>
            <div class="tb-field">
                <label class="form-label" for="filterPlan">Plan</label>
                <select class="form-select form-select-sm" id="filterPlan">
                    <option value="">All Plans</option>
                </select>
            </div>
            <div class="tb-btn">
                <button class="btn btn-sm btn-eyecore" onclick="applyFilters()">
                    <i class="bi bi-funnel me-1"></i> Apply Filter
                </button>
            </div>
        </div>
    </div>

    <!-- ========== EXPIRY ALERT BANNER ========== -->
    <div id="expiryAlertBanner" class="alert alert-danger d-none align-items-center" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">
            <strong id="expiredCountText">0</strong> clinic(s) have an <strong>expired subscription</strong>.
            <span id="expiringSoonText" class="ms-1"></span>
        </div>
        <button class="btn btn-sm" onclick="showExpiredList()">
            <i class="bi bi-eye me-1"></i> View Details
        </button>
    </div>

    <!-- ========== SUBSCRIPTION OVERVIEW ========== -->
    <div class="section">
        <div class="section-title">Subscription Overview <span class="hint">· click Expired / Expiring to filter the table below</span></div>
        <div class="row g-2 g-md-3">
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-check-circle" style="color:#16A34A"></i>Active Clinics</div>
                    <div class="kvalue" id="statActive">0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard klink" onclick="bootstrap.Tab.getOrCreateInstance(document.getElementById('tabClinicBtn')).show();document.querySelector('#statusFilterBtns [data-status=expiring]').click();document.getElementById('clinicStatusSection').scrollIntoView({behavior:'smooth'})">
                    <div class="klabel"><i class="bi bi-clock-history" style="color:#D97706"></i>Expiring (7 days)</div>
                    <div class="kvalue" id="statExpiringSoon">0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard klink" onclick="bootstrap.Tab.getOrCreateInstance(document.getElementById('tabClinicBtn')).show();document.querySelector('#statusFilterBtns [data-status=expired]').click();document.getElementById('clinicStatusSection').scrollIntoView({behavior:'smooth'})">
                    <div class="klabel"><i class="bi bi-x-octagon" style="color:#DC2626"></i>Expired Clinics</div>
                    <div class="kvalue" id="statExpired">0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-question-circle" style="color:#64748B"></i>No Subscription</div>
                    <div class="kvalue" id="statNoSub">0</div>
                </div></div>
        </div>
    </div>

    <!-- ========== REVENUE SUMMARY ========== -->
    <div class="section">
        <div class="section-title">Revenue Summary</div>
        <div class="row g-2 g-md-3">
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-cash-stack" style="color:#0D9488"></i>All-Time Income</div>
                    <div class="kvalue money" id="statAllTime">₱0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-calendar-month" style="color:#0D9488"></i>This Month</div>
                    <div class="kvalue money" id="statThisMonth">₱0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-calendar-day" style="color:#0D9488"></i>Today</div>
                    <div class="kvalue money" id="statToday">₱0</div>
                </div></div>
                <div class="col-6 col-md-3"><div class="kcard">
                    <div class="klabel"><i class="bi bi-receipt" style="color:#0D9488"></i>Transactions (Filtered)</div>
                    <div class="kvalue" id="statFilteredCount">0</div>
                </div></div>
        </div>
    </div>

    <!-- Charts removed from UI. Hidden canvases kept ONLY so existing loadChart()/loadPlanBreakdown() don't throw. -->
    <div class="d-none" aria-hidden="true">
        <canvas id="incomeChart" height="90"></canvas>
        <canvas id="planChart" height="180"></canvas>
    </div>

    <!-- ========== TABBED DATA SECTION ========== -->
    <div class="panel p-0">
        <ul class="nav nav-tabs px-3 pt-2" id="dataTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" id="tabClinicBtn" data-bs-toggle="tab" data-bs-target="#paneClinic" type="button" role="tab"><i class="bi bi-hospital me-1"></i>Clinic Subscriptions</button></li>
            <li class="nav-item"><button class="nav-link" id="tabTxBtn" data-bs-toggle="tab" data-bs-target="#paneTx" type="button" role="tab"><i class="bi bi-receipt me-1"></i>Transactions</button></li>
            <li class="nav-item"><button class="nav-link" id="tabTrialBtn" data-bs-toggle="tab" data-bs-target="#paneTrial" type="button" role="tab"><i class="bi bi-hourglass-split me-1"></i>Trial Settings</button></li>
        </ul>

        <div class="tab-content">
            <!-- ---- Clinic Subscriptions ---- -->
            <div class="tab-pane fade show active" id="paneClinic" role="tabpanel">
                <div id="clinicStatusSection">
                    <div class="p-3 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="search" class="form-control form-control-sm" id="clinicSearch" placeholder="Search clinic, plan, status…">
                            </div>
                            <select class="form-select form-select-sm" id="clinicStatusSelect" style="width:auto;min-width:170px;">
                                <option value="all">All</option>
                                <option value="expired">Expired</option>
                                <option value="expiring">Expiring Soon</option>
                                <option value="active">Active</option>
                                <option value="none">No Subscription</option>
                            </select>
                        </div>
                        <div class="btn-group btn-group-sm flex-wrap d-none" role="group" id="statusFilterBtns">
                            <button type="button" class="btn btn-outline-secondary active" data-status="all" onclick="filterClinicStatus('all', this)">
                                All <span class="badge bg-secondary ms-1" id="countAll">0</span>
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-status="expired" onclick="filterClinicStatus('expired', this)">
                                Expired <span class="badge bg-danger ms-1" id="countExpired">0</span>
                            </button>
                            <button type="button" class="btn btn-outline-warning" data-status="expiring" onclick="filterClinicStatus('expiring', this)">
                                Expiring <span class="badge bg-warning text-dark ms-1" id="countExpiring">0</span>
                            </button>
                            <button type="button" class="btn btn-outline-success" data-status="active" onclick="filterClinicStatus('active', this)">
                                Active <span class="badge bg-success ms-1" id="countActive">0</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-status="none" onclick="filterClinicStatus('none', this)">
                                No Sub <span class="badge bg-secondary ms-1" id="countNone">0</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Clinic</th><th>Plan</th><th>Start</th><th>End Date</th><th>Days</th><th>Status</th><th>Last Payment</th>
                                </tr>
                            </thead>
                            <tbody id="clinicStatusBody">
                                <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="clinicEmpty" class="text-center text-muted py-4 d-none">No clinics match your search.</div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 pt-2">
                        <span class="text-muted small" id="clinicPageInfo"></span>
                        <ul class="pagination pagination-sm mb-0" id="clinicPager"></ul>
                    </div>
                </div>
            </div>

            <!-- ---- Transactions ---- -->
            <div class="tab-pane fade" id="paneTx" role="tabpanel">
                <div class="p-3 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="search" class="form-control form-control-sm" id="txSearch" placeholder="Search this page…">
                    </div>
                    <span class="text-muted small" id="rangeLabel"></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Date</th><th>Clinic</th><th>Plan</th><th>Type</th><th>Amount</th><th>Method</th><th>Reference</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <nav class="p-3 pt-2">
                    <ul class="pagination pagination-sm justify-content-end mb-0" id="pagination"></ul>
                </nav>
            </div>

            <!-- ---- Trial Settings ---- -->
            <div class="tab-pane fade" id="paneTrial" role="tabpanel">
                <div class="p-3" id="trialSettingsSection">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h6 class="mb-0">Basic Plan Trial Duration</h6>
                        <span class="badge bg-light text-dark border fw-normal" id="trialUpdatedAt">—</span>
                    </div>

                    <div class="trial-bar">
                        <div>
                            <label class="form-label small fw-semibold mb-1" for="trialDaysInput">Current Trial Days</label>
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control" id="trialDaysInput" min="1" max="365" value="3">
                                <span class="input-group-text">days</span>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-eyecore" onclick="saveTrialDays()">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                        <div class="trial-note flex-grow-1" style="min-width:220px;">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Note:</strong> Dynamic — bagong register lang ang may bagong value; existing subs are NOT affected.
                            Naka-snapshot ang trial days sa bawat subscription.
                        </div>
                    </div>

                    <div class="trial-collapses">
                        <button class="collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#snapshotCollapse" aria-expanded="false">
                            <i class="bi bi-people"></i> Snapshot Distribution (existing subscriptions)
                            <i class="bi bi-chevron-down chev"></i>
                        </button>
                        <div class="collapse" id="snapshotCollapse">
                            <div id="snapshotDistribution" class="d-flex flex-wrap gap-2 py-2">
                                <span class="text-muted small">Loading...</span>
                            </div>
                        </div>

                        <button class="collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#historyCollapse" aria-expanded="false">
                            <i class="bi bi-clock-history"></i> Recent Changes
                            <i class="bi bi-chevron-down chev"></i>
                        </button>
                        <div class="collapse" id="historyCollapse">
                            <div class="table-responsive pt-2">
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>Old</th><th>New</th><th>Changed By</th><th>When</th></tr>
                                    </thead>
                                    <tbody id="trialHistoryBody">
                                        <tr><td colspan="4" class="text-muted text-center py-3">Loading...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ========== MODAL: Expired Clinics List (retained, restyled) ========== -->
<div class="modal fade" id="expiredModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>Expired Subscriptions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Clinic</th>
                                <th>Plan</th>
                                <th>Expired On</th>
                                <th>Days Expired</th>
                                <th>Last Payment</th>
                            </tr>
                        </thead>
                        <tbody id="expiredTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-sm btn-eyecore" onclick="goToExpiredFilter()">
                    <i class="bi bi-funnel me-1"></i>Open in Clinic Status Table
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const API = '../api/subscription_income_api.php';
let currentPage = 1;
let incomeChartInstance = null;
let planChartInstance = null;
let currentStatusFilter = 'all';

function fmt(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getFilters() {
    return {
        date_from: document.getElementById('filterDateFrom').value,
        date_to: document.getElementById('filterDateTo').value,
        plan_id: document.getElementById('filterPlan').value
    };
}

function loadSummary() {
    const f = getFilters();
    const qs = new URLSearchParams({ action: 'summary', ...f });
    fetch(`${API}?${qs}`).then(r => r.json()).then(data => {
        if (!data.success) return;
        document.getElementById('statAllTime').textContent = fmt(data.all_time_income);
        document.getElementById('statThisMonth').textContent = fmt(data.this_month_income);
        document.getElementById('statToday').textContent = fmt(data.today_income);
        document.getElementById('statFilteredCount').textContent = data.range_transactions;
        document.getElementById('rangeLabel').textContent = `${f.date_from} to ${f.date_to} — ${fmt(data.range_income)} total`;
    });
}

function loadChart() {
    fetch(`${API}?action=chart`).then(r => r.json()).then(data => {
        if (!data.success) return;
        const labels = data.data.map(r => r.month);
        const values = data.data.map(r => parseFloat(r.total));

        if (incomeChartInstance) incomeChartInstance.destroy();
        incomeChartInstance = new Chart(document.getElementById('incomeChart'), {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Income',
                    data: values,
                    backgroundColor: '#0d9488',
                    borderRadius: 6
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } }
            }
        });
    });
}

function loadPlanBreakdown() {
    const f = getFilters();
    const qs = new URLSearchParams({ action: 'by_plan', ...f });
    fetch(`${API}?${qs}`).then(r => r.json()).then(data => {
        if (!data.success) return;
        const labels = data.data.map(r => r.plan_name);
        const values = data.data.map(r => parseFloat(r.total));

        if (planChartInstance) planChartInstance.destroy();
        planChartInstance = new Chart(document.getElementById('planChart'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#0d9488', '#2563eb', '#d97706', '#dc2626', '#7c3aed']
                }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    });
}

function loadTable(page = 1) {
    currentPage = page;
    const f = getFilters();
    const qs = new URLSearchParams({ action: 'table', page, ...f });
    fetch(`${API}?${qs}`).then(r => r.json()).then(data => {
        const tbody = document.getElementById('tableBody');
        if (!data.success || data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No transactions found.</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = data.data.map(row => `
            <tr>
                <td>${new Date(row.transaction_date).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })}</td>
                <td>${row.clinic_name ?? '—'}</td>
                <td><span class="badge badge-plan">${row.plan_name}</span></td>
                <td class="text-capitalize">${row.transaction_type}</td>
                <td class="fw-semibold">${fmt(row.amount)}</td>
                <td>${row.payment_method ?? '—'}</td>
                <td class="text-muted small">${row.payment_reference ?? '—'}</td>
            </tr>
        `).join('');

        renderPagination(data.current_page, data.total_pages);
    });
}

function renderPagination(current, total) {
    const el = document.getElementById('pagination');
    if (total <= 1) { el.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= total; i++) {
        html += `<li class="page-item ${i === current ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadTable(${i}); return false;" style="${i === current ? 'background:#0d9488;border-color:#0d9488;color:#fff;' : ''}">${i}</a>
        </li>`;
    }
    el.innerHTML = html;
}

function loadPlansDropdown() {
    fetch(`${API}?action=plans_list`).then(r => r.json()).then(data => {
        if (!data.success) return;
        const select = document.getElementById('filterPlan');
        data.data.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.plan_name;
            select.appendChild(opt);
        });
    });
}

// ========== SUBSCRIPTION STATUS SUMMARY (CARDS + BANNER) ==========
function loadSubscriptionStatus() {
    const qs = new URLSearchParams({ action: 'status_summary' });
    fetch(`${API}?${qs}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            document.getElementById('statExpired').textContent = data.expired;
            document.getElementById('statExpiringSoon').textContent = data.expiring_soon;
            document.getElementById('statActive').textContent = data.active;
            document.getElementById('statNoSub').textContent = data.no_subscription;

            const banner = document.getElementById('expiryAlertBanner');
            if (data.expired > 0 || data.expiring_soon > 0) {
                banner.classList.remove('d-none');
                banner.classList.add('d-flex');
                document.getElementById('expiredCountText').textContent = data.expired;
document.getElementById('expiringSoonText').innerHTML = data.expiring_soon > 0
    ? `<strong>${data.expiring_soon}</strong> more expiring soon (within 7 days).`
    : '';
            } else {
                banner.classList.add('d-none');
                banner.classList.remove('d-flex');
            }
        })
        .catch(err => console.error('Status load failed:', err));
}

// ========== FULL CLINIC STATUS TABLE ==========
function loadClinicStatus(status = 'all') {
    currentStatusFilter = status;
    const tbody = document.getElementById('clinicStatusBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>';

    const qs = new URLSearchParams({ action: 'all_clinics_status', status });
    fetch(`${API}?${qs}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Failed to load.</td></tr>';
                return;
            }

            // Update counts
            document.getElementById('countAll').textContent = data.counts.all;
            document.getElementById('countExpired').textContent = data.counts.expired;
            document.getElementById('countExpiring').textContent = data.counts.expiring;
            document.getElementById('countActive').textContent = data.counts.active;
            document.getElementById('countNone').textContent = data.counts.none;

            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No clinics match this filter.</td></tr>';
                return;
            }

            tbody.innerHTML = data.data.map(row => {
                let statusBadge = '';
                let daysText = '—';

                if (row.state === 'expired') {
                    statusBadge = '<span class="badge bg-danger">Expired</span>';
                    daysText = `<span class="text-danger fw-semibold">${row.days_diff}d ago</span>`;
                } else if (row.state === 'expiring') {
                    statusBadge = '<span class="badge bg-warning text-dark">Expiring Soon</span>';
                    daysText = `<span class="text-warning fw-semibold">${row.days_diff}d left</span>`;
                } else if (row.state === 'active') {
                    statusBadge = '<span class="badge bg-success">Active</span>';
                    daysText = `<span class="text-success">${row.days_diff}d left</span>`;
                } else {
                    statusBadge = '<span class="badge bg-secondary">No Subscription</span>';
                    daysText = '—';
                }

                return `
                    <tr>
                        <td class="fw-semibold">${row.clinic_name}</td>
                        <td>${row.plan_name ? `<span class="badge badge-plan">${row.plan_name}</span>` : '—'}</td>
                        <td class="text-muted small">${row.start_date ? new Date(row.start_date).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—'}</td>
                        <td>${row.end_date ? new Date(row.end_date).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—'}</td>
                        <td>${daysText}</td>
                        <td>${statusBadge}</td>
                        <td class="text-muted small">${row.last_transaction ? new Date(row.last_transaction).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : 'Never'}</td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error loading data.</td></tr>';
            console.error(err);
        });
}

function filterClinicStatus(status, btnEl) {
    document.querySelectorAll('#statusFilterBtns .btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');
    loadClinicStatus(status);
}

// ========== EXPIRED MODAL ==========
function showExpiredList() {
    const modal = new bootstrap.Modal(document.getElementById('expiredModal'));
    const tbody = document.getElementById('expiredTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">Loading...</td></tr>';
    modal.show();

    fetch(`${API}?action=expired_list`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No expired clinics. 🎉</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(row => `
                <tr>
                    <td class="fw-semibold">${row.clinic_name ?? '—'}</td>
                    <td><span class="badge badge-plan">${row.plan_name ?? '—'}</span></td>
                    <td>${row.end_date ? new Date(row.end_date).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—'}</td>
                    <td><span class="badge bg-danger">${row.days_expired} days</span></td>
                    <td class="text-muted small">${row.last_payment ? new Date(row.last_payment).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : 'Never'}</td>
                </tr>
            `).join('');
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Error loading data.</td></tr>';
            console.error(err);
        });
}

// NEW (UI helper only): modal shortcut that reuses the existing filterClinicStatus()
function goToExpiredFilter() {
    const modalEl = document.getElementById('expiredModal');
    const inst = bootstrap.Modal.getInstance(modalEl);
    if (inst) inst.hide();
    const btn = document.querySelector('#statusFilterBtns [data-status="expired"]');
    if (btn) filterClinicStatus('expired', btn);
    document.getElementById('clinicStatusSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ========== BASIC PLAN TRIAL SETTINGS ==========
function loadTrialDays() {
    fetch(`${API}?action=get_trial_days`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            document.getElementById('trialDaysInput').value = data.current_value;

            if (data.updated_at) {
                const d = new Date(data.updated_at);
                document.getElementById('trialUpdatedAt').textContent =
                    'Last updated: ' + d.toLocaleString('en-PH', {
                        month: 'short', day: 'numeric', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
            } else {
                document.getElementById('trialUpdatedAt').textContent = 'Never updated';
            }

            const dist = document.getElementById('snapshotDistribution');
            if (!data.snapshot_distribution || data.snapshot_distribution.length === 0) {
                dist.innerHTML = '<span class="text-muted small">No snapshot records yet.</span>';
            } else {
                dist.innerHTML = data.snapshot_distribution.map(s => `
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-people-fill me-1" style="color:#0d9488;"></i>
                        ${s.clinics} clinic${s.clinics > 1 ? 's' : ''} on <strong>${s.days} day${s.days > 1 ? 's' : ''}</strong>
                    </span>
                `).join('');
            }
        })
        .catch(err => console.error('loadTrialDays failed:', err));
}

function loadTrialHistory() {
    fetch(`${API}?action=trial_days_history`)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('trialHistoryBody');
            if (!data.success || !data.data || data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">No changes yet.</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(h => {
                const name = h.changed_by_name || 'Unknown';
                const when = new Date(h.changed_at).toLocaleString('en-PH', {
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
                return `
                    <tr>
                        <td><span class="badge bg-secondary">${h.old_value} days</span></td>
                        <td><span class="badge bg-success">${h.new_value} days</span></td>
                        <td class="small">${name}</td>
                        <td class="small text-muted">${when}</td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => console.error('loadTrialHistory failed:', err));
}

function saveTrialDays() {
    const val = parseInt(document.getElementById('trialDaysInput').value);

    if (!val || val < 1 || val > 365) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid value',
            text: 'Trial days must be between 1 and 365.',
            confirmButtonColor: '#0d9488'
        });
        return;
    }

    Swal.fire({
        title: 'Update Trial Days?',
        html: `Set Basic plan trial to <strong>${val} day${val > 1 ? 's' : ''}</strong>?<br><br>
               <span class="text-muted small">Existing users will NOT be affected.</span>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        confirmButtonText: 'Yes, update'
    }).then(result => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const body = new URLSearchParams({ action: 'update_trial_days', trial_days: val });
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: data.message,
                        timer: 2200,
                        timerProgressBar: true
                    });
                    loadTrialDays();
                    loadTrialHistory();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.error });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }));
    });
}

// Auto-refresh every 5 minutes
setInterval(loadSubscriptionStatus, 5 * 60 * 1000);

function applyFilters() {
    loadSummary();
    loadPlanBreakdown();
    loadTable(1);
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', () => {
    const now = new Date();
    document.getElementById('filterDateFrom').value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    document.getElementById('filterDateTo').value = now.toISOString().split('T')[0];

    loadPlansDropdown();
    loadSummary();
    loadChart();
    loadPlanBreakdown();
    loadTable(1);
    loadSubscriptionStatus();
    loadClinicStatus('all');
    loadTrialDays();
    loadTrialHistory();
});
</script>


<script>
// ========== UI LAYER (additive): search, dropdown filter, row limit ==========
(function () {
    const PAGE_SIZE = 10;
    let cPage = 1;
    const body = document.getElementById('clinicStatusBody');
    const q = document.getElementById('clinicSearch');
    const sel = document.getElementById('clinicStatusSelect');
    const pager = document.getElementById('clinicPager');
    const info = document.getElementById('clinicPageInfo');
    const empty = document.getElementById('clinicEmpty');

    const dataRows = () => [...body.querySelectorAll('tr')].filter(tr => !tr.querySelector('td[colspan]'));

    function renderClinics() {
        const term = q.value.trim().toLowerCase();
        const rows = dataRows();
        const matched = rows.filter(tr => tr.textContent.toLowerCase().includes(term));
        const pages = Math.max(1, Math.ceil(matched.length / PAGE_SIZE));
        if (cPage > pages) cPage = pages;
        rows.forEach(tr => tr.classList.add('d-none'));
        const start = (cPage - 1) * PAGE_SIZE;
        matched.slice(start, start + PAGE_SIZE).forEach(tr => tr.classList.remove('d-none'));

        empty.classList.toggle('d-none', !(rows.length > 0 && matched.length === 0));
        info.textContent = matched.length
            ? `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, matched.length)} of ${matched.length}`
            : '';

        let h = '';
        if (pages > 1) {
            h += `<li class="page-item ${cPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-p="${cPage - 1}">Prev</a></li>`;
            for (let i = 1; i <= pages; i++) {
                h += `<li class="page-item ${i === cPage ? 'active' : ''}"><a class="page-link" href="#" data-p="${i}" ${i === cPage ? 'style="background:#0d9488;border-color:#0d9488;color:#fff;"' : ''}>${i}</a></li>`;
            }
            h += `<li class="page-item ${cPage === pages ? 'disabled' : ''}"><a class="page-link" href="#" data-p="${cPage + 1}">Next</a></li>`;
        }
        pager.innerHTML = h;
    }

    function syncDropdown() {
        const map = { all: ['All', 'countAll'], expired: ['Expired', 'countExpired'], expiring: ['Expiring Soon', 'countExpiring'], active: ['Active', 'countActive'], none: ['No Subscription', 'countNone'] };
        [...sel.options].forEach(o => {
            const [label, id] = map[o.value];
            o.textContent = `${label} (${document.getElementById(id).textContent})`;
        });
        sel.value = currentStatusFilter;
    }

    pager.addEventListener('click', e => {
        const a = e.target.closest('a[data-p]');
        if (!a) return;
        e.preventDefault();
        const p = parseInt(a.dataset.p);
        if (p >= 1) { cPage = p; renderClinics(); }
    });
    q.addEventListener('input', () => { cPage = 1; renderClinics(); });
    sel.addEventListener('change', () => {
        const btn = document.querySelector(`#statusFilterBtns [data-status="${sel.value}"]`);
        if (btn) btn.click();   // reuses existing filterClinicStatus()
    });

    // re-apply whenever existing code re-renders the clinic rows
    new MutationObserver(() => { cPage = 1; syncDropdown(); renderClinics(); })
        .observe(body, { childList: true });

    // transactions: search within the currently loaded page (API has no search param)
    const tx = document.getElementById('txSearch');
    const txBody = document.getElementById('tableBody');
    function renderTx() {
        const term = tx.value.trim().toLowerCase();
        txBody.querySelectorAll('tr').forEach(tr => {
            if (tr.querySelector('td[colspan]')) return;
            tr.classList.toggle('d-none', !tr.textContent.toLowerCase().includes(term));
        });
    }
    tx.addEventListener('input', renderTx);
    new MutationObserver(renderTx).observe(txBody, { childList: true });
})();
</script>

</body>
</html>