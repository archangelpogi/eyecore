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
    exit();
}

if (!RBACHelper::hasPermission('reports_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to view Reports & Analytics.</p>
        </div>
    </div>
    <?php
    exit;
}

$canView   = RBACHelper::hasPermission('reports_view');
$canCreate = RBACHelper::hasPermission('reports_create');
$clinic_id = $_SESSION['clinic_id'];
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');

  :root {
    --teal:       #0d9488;
    --teal-dark:  #0f766e;
    --teal-light: #f0fdfa;
    --teal-mid:   #ccfbf1;
    --ink:        #0f172a;
    --ink-soft:   #334155;
    --muted:      #64748b;
    --border:     #e2e8f0;
    --surface:    #ffffff;
    --bg:         #f8fafc;
    --radius:     14px;
    --radius-sm:  8px;
    --shadow:     0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
    --shadow-md:  0 4px 24px rgba(0,0,0,.08);
  }

  /* =======================================================
     🔑 SUPER CRITICAL FIX: RESET LAHAT NG POSSIBLE OVERFLOW
     ======================================================= */
  #rpt-wrapper, 
  #rpt-wrapper * { 
    box-sizing: border-box !important; 
    font-family: 'Plus Jakarta Sans', sans-serif; 
  }

  #rpt-wrapper { 
    background: var(--bg); 
    min-height: 100vh; 
    padding: 24px 28px 48px; 
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden; 
  }

  /* ── HEADER ── */
  .rpt-hero { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
  .rpt-hero-left { display:flex; align-items:center; gap:14px; min-width: 0; flex: 1 1 auto; }
  .rpt-icon-wrap { width:52px; height:52px; border-radius:14px;
    background:linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 4px 12px rgba(13,148,136,.35); flex-shrink:0; }
  .rpt-icon-wrap i { font-size:22px; color:#fff; }
  .rpt-title { font-size:22px; font-weight:800; color:var(--ink); margin:0; line-height:1.2; }
  .rpt-subtitle { font-size:13px; color:var(--muted); margin:2px 0 0; }
  .rpt-export-group { display:flex; gap:8px; flex-wrap:wrap; }

  /* ── EXPORT BUTTONS ── */
  .btn-export { display:inline-flex; align-items:center; gap:7px; padding:8px 16px;
    border-radius:var(--radius-sm); font-size:12.5px; font-weight:600; cursor:pointer;
    border:1.5px solid; transition:all .2s; text-decoration:none; }
  .btn-export-pdf  { color:#dc2626; border-color:#fca5a5; background:#fff5f5; }
  .btn-export-xlsx { color:#16a34a; border-color:#86efac; background:#f0fdf4; }

  /* ── FILTER CARD ── */
  .rpt-filter-card { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--radius);
    padding:16px 20px; margin-bottom:20px; box-shadow:var(--shadow); width: 100%; min-width: 0; }
  .rpt-filter-grid { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:12px; align-items:end; }
  .rpt-filter-label { font-size:11.5px; font-weight:600; color:var(--muted); margin-bottom:5px; display:block; }
  .rpt-input { width:100%; padding:8px 12px; border:1.5px solid var(--border); border-radius:var(--radius-sm);
    font-size:13px; color:var(--ink); font-family:inherit; transition:border-color .2s; background:var(--surface); min-width: 0; }
  .btn-apply { display:inline-flex; align-items:center; gap:7px; padding:8px 20px;
    background:var(--teal); color:#fff; border:none; border-radius:var(--radius-sm);
    font-size:13px; font-weight:600; cursor:pointer; white-space:nowrap; width:100%; justify-content:center; }

  /* ── TABS ── */
  .rpt-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; width: 100%; }
  .rpt-tab { display:inline-flex; align-items:center; gap:7px; padding:9px 18px;
    border-radius:var(--radius-sm); font-size:13px; font-weight:600; cursor:pointer;
    border:none; background:#f1f5f9; color:var(--muted); transition:all .2s; }
  .rpt-tab.active { background:var(--teal); color:#fff; box-shadow:0 2px 8px rgba(13,148,136,.3); }

  /* ── STAT CARDS ── */
  .rpt-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:14px; margin-bottom:20px; width: 100%; }
  .stat-card { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--radius);
    padding:18px 20px; box-shadow:var(--shadow); display:flex; justify-content:space-between; align-items:flex-start; min-width: 0; }
  .stat-card > div:first-child { flex:1 1 auto; min-width: 0; }
  .stat-val  { font-size:26px; font-weight:800; color:var(--ink); line-height:1; margin:4px 0; font-family:'JetBrains Mono',monospace; word-break:break-word; }
  .stat-lbl  { font-size:12px; font-weight:600; color:var(--muted); }
  .stat-sub  { font-size:11px; color:var(--muted); margin-top:3px; }
  .stat-ico  { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }

  /* ── CHART CARDS ── */
  .rpt-row  { display:grid; gap:14px; margin-bottom:14px; width: 100%; min-width: 0; }
  .rpt-row-2 { grid-template-columns:1fr 1fr; }
  .rpt-row-1 { grid-template-columns:1fr; }
  .chart-card { background:var(--surface); border:1.5px solid var(--border); border-radius:var(--radius);
    box-shadow:var(--shadow); overflow:hidden; width: 100%; max-width: 100%; min-width: 0; }
  .chart-card-head { padding:14px 18px 10px; border-bottom:1.5px solid var(--border); display:flex; align-items:center; gap:8px; }
  .chart-card-head h6 { margin:0; font-size:13.5px; font-weight:700; color:var(--ink); }
  .chart-card-body { padding:16px; width: 100%; min-width: 0; }
  .chart-wrap { position:relative; width:100%; height:100%; min-width: 0; }
  
  .chart-wrap canvas { max-width: 100% !important; min-width: 0 !important; }

  /* ── TABLE (Base) ── */
  .rpt-table-wrap { overflow:auto; max-height:280px; width: 100%; max-width: 100%; min-width: 0; }
  .rpt-table { width:100%; border-collapse:collapse; font-size:12.5px; min-width: 0; }
  .rpt-table thead th { background:var(--teal-light); color:var(--teal-dark); font-size:11px;
    font-weight:700; text-transform:uppercase; padding:10px 14px; border-bottom:1.5px solid var(--teal-mid); white-space:nowrap; }
  .rpt-table tbody td { padding:10px 14px; vertical-align:middle; color:var(--ink-soft); }
  .rpt-table .fw { font-weight:600; color:var(--ink); }
  .rpt-table .money { font-weight:700; color:#10b981; font-family:'JetBrains Mono',monospace; }
  .rpt-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; }
  .rpt-badge-blue  { background:#dbeafe; color:#1d4ed8; }
  .rpt-badge-green { background:#dcfce7; color:#15803d; }
  .rpt-badge-red   { background:#fee2e2; color:#dc2626; }
  .rpt-badge-amber { background:#fef3c7; color:#b45309; }
  .rpt-badge-gray  { background:#f1f5f9; color:#475569; }

  /* ── LOADING & EMPTY ── */
  #rpt-loading { display:none; position:fixed; inset:0; background:rgba(255,255,255,.85); z-index:9999; align-items:center; justify-content:center; flex-direction:column; gap:12px; }
  .rpt-spinner { width:40px; height:40px; border:4px solid var(--teal-mid); border-top-color:var(--teal); border-radius:50%; animation:rpt-spin .7s linear infinite; }
  @keyframes rpt-spin { to { transform:rotate(360deg); } }
  .rpt-empty { padding:60px 20px; text-align:center; color:var(--muted); }

  /* ============================================================
     📱 MOBILE RESPONSIVE (Fix na tugma sa Dashboard)
     ============================================================ */
  @media (max-width: 767.98px) {
    
    /* 1. I-lock ang container sa exact viewport width */
    #rpt-wrapper {
      padding: 12px; 
      width: 100vw !important; 
      max-width: 100vw !important; 
      overflow-x: hidden !important;
    }

    /* 2. I-force lahat ng direct children na sumunod */
    #rpt-wrapper > * { 
      max-width: 100% !important; 
      box-sizing: border-box !important; 
    }

    /* Header */
    .rpt-hero { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 16px; }
    .rpt-title { font-size: 1.15rem; }
    .rpt-subtitle { font-size: 0.75rem; }
    
    /* Export Buttons (Gawing 2 columns) */
    .rpt-export-group { 
      width: 100%; 
      display: grid; 
      grid-template-columns: 1fr 1fr; 
      gap: 8px; 
    }
    .btn-export { width: 100%; justify-content: center; font-size: 11px; padding: 8px; }

    /* Filter (Gawing 2 columns) */
    .rpt-filter-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .rpt-filter-card { padding: 12px; }
    .btn-apply { grid-column: 1 / -1; margin-top: 4px; }

    /* Tabs (Horizontal Scroll) */
    .rpt-tabs { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 5px; -webkit-overflow-scrolling: touch; }
    .rpt-tabs::-webkit-scrollbar { display: none; }
    .rpt-tab { flex: 0 0 auto; padding: 7px 12px; font-size: 11px; }

    /* Stats (Gawing 2 columns) */
    .rpt-stats { grid-template-columns: 1fr 1fr; gap: 10px; }
    .stat-card { padding: 10px; }
    .stat-val { font-size: 15px; word-break: break-all; }
    .stat-lbl, .stat-sub { font-size: 0.65rem; white-space: normal; line-height: 1.2; }
    .stat-ico { width: 30px; height: 30px; font-size: 14px; }

    /* Charts (Stack Vertically) */
    .rpt-row-2 { grid-template-columns: 1fr; }
    .chart-card-body { padding: 10px; }
    .chart-wrap canvas { max-height: 200px !important; }

    /* 🔑 TABLES -> CARD VIEW */
    .rpt-table-wrap { max-height: none; overflow: visible; width: 100% !important; }
    .rpt-table, .rpt-table tbody, .rpt-table tr, .rpt-table td { 
      display: block !important; 
      width: 100% !important; 
      max-width: 100% !important;
    }
    .rpt-table thead { display: none !important; }
    
    /* Card Style */
    .rpt-table tbody tr {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 12px;
      margin-bottom: 10px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    
    /* Flex rows (Label + Value) */
    .rpt-table tbody td {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 5px 0 !important;
      border: none !important;
      font-size: 11.5px;
      text-align: right;
      word-break: break-word;
    }
    
    /* Data Labels */
    .rpt-table tbody td::before {
      content: attr(data-label);
      font-weight: 700;
      color: var(--muted);
      font-size: 9.5px;
      text-transform: uppercase;
      text-align: left;
      margin-right: 10px;
      flex-shrink: 0;
    }
    
    /* First Row (Title) */
    .rpt-table tbody td:first-child {
      border-bottom: 1px dashed var(--border) !important;
      padding-bottom: 8px !important;
      margin-bottom: 4px;
      justify-content: flex-start;
    }
    .rpt-table tbody td:first-child::before { display: none; }
    .rpt-table tbody td:first-child .fw { font-size: 13px; text-align: left; }
    
    /* Colspan (Empty state) */
    .rpt-table tbody td[colspan] { justify-content: center; }
    .rpt-table tbody td[colspan]::before { display: none; }
  }

  @media (max-width: 360px) {
    .stat-val { font-size: 13px; }
    .stat-lbl { font-size: 0.6rem; }
    .rpt-tab { font-size: 10px; padding: 6px 10px; }
    .chart-wrap canvas { max-height: 160px !important; }
  }
</style>

<!-- ───────────────────────────── HTML ───────────────────────────── -->
<div id="rpt-wrapper">

  <!-- Loading -->
  <div id="rpt-loading">
    <div class="rpt-spinner"></div>
    <span style="font-size:13px;color:#64748b;font-family:'Plus Jakarta Sans',sans-serif;">Loading report…</span>
  </div>

  <!-- Hero -->
  <div class="rpt-hero">
    <div class="rpt-hero-left">
      <div class="rpt-icon-wrap"><i class="bi bi-bar-chart-steps"></i></div>
      <div>
        <div class="rpt-title">Reports & Analyticsss</div>
        <div class="rpt-subtitle">Comprehensive business insights and performance metrics</div>
      </div>
    </div>
    <?php if ($canCreate): ?>
    <div class="rpt-export-group">
      <button class="btn-export btn-export-pdf"  onclick="exportReport('pdf')">
        <i class="bi bi-file-pdf"></i> Export PDF
      </button>
      <button class="btn-export btn-export-xlsx" onclick="exportReport('excel')">
        <i class="bi bi-file-excel"></i> Export Excel
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Filter -->
  <div class="rpt-filter-card">
    <div class="rpt-filter-grid">
      <div>
        <label class="rpt-filter-label"><i class="bi bi-calendar3 me-1"></i>Start Date</label>
        <input type="date" id="startDate" class="rpt-input" value="<?= $start_date ?>">
      </div>
      <div>
        <label class="rpt-filter-label"><i class="bi bi-calendar3 me-1"></i>End Date</label>
        <input type="date" id="endDate" class="rpt-input" value="<?= $end_date ?>">
      </div>
      <div>
        <label class="rpt-filter-label"><i class="bi bi-clock-history me-1"></i>Quick Select</label>
        <select id="quickRange" class="rpt-input">
          <option value="7">Last 7 Days</option>
          <option value="30" selected>Last 30 Days</option>
          <option value="90">Last 90 Days</option>
          <option value="180">Last 6 Months</option>
          <option value="365">Last Year</option>
        </select>
      </div>
      <div>
        <button class="btn-apply" onclick="loadReports()">
          <i class="bi bi-search"></i> Apply Filter
        </button>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="rpt-tabs">
    <button class="rpt-tab active" data-report="sales"        onclick="switchTab('sales')">        <i class="bi bi-graph-up"></i>      Sales & Revenue </button>
    <button class="rpt-tab"        data-report="patients"     onclick="switchTab('patients')">     <i class="bi bi-people"></i>        Patients        </button>
    <button class="rpt-tab"        data-report="appointments" onclick="switchTab('appointments')"> <i class="bi bi-calendar-check"></i> Appointments    </button>
    <button class="rpt-tab"        data-report="inventory"    onclick="switchTab('inventory')">    <i class="bi bi-box-seam"></i>      Inventory       </button>
    <button class="rpt-tab"        data-report="services"     onclick="switchTab('services')">     <i class="bi bi-star"></i>          Services        </button>
  </div>

  <!-- Content -->
  <div id="reportContent"></div>
</div>

<!-- ──────────────────────── SCRIPTS ──────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
const rptPerms = { canView: <?= json_encode($canView) ?>, canCreate: <?= json_encode($canCreate) ?> };
let currentReport = 'sales';
let charts = {};
let lastData  = null;

document.addEventListener('DOMContentLoaded', () => {
  if (!rptPerms.canView) {
    document.getElementById('reportContent').innerHTML = `
      <div class="rpt-empty"><i class="bi bi-shield-lock"></i><p>You don't have permission to view reports.</p></div>`;
    return;
  }
  loadReports();

  document.getElementById('quickRange').addEventListener('change', function () {
    const days = parseInt(this.value);
    const end  = new Date(), start = new Date();
    start.setDate(end.getDate() - days);
    document.getElementById('startDate').value = start.toISOString().split('T')[0];
    document.getElementById('endDate').value   = end.toISOString().split('T')[0];
    loadReports();
  });
});

function switchTab(type) {
  currentReport = type;
  document.querySelectorAll('.rpt-tab').forEach(t => {
    const isActive = t.getAttribute('data-report') === type;
    t.classList.toggle('active', isActive);
  });
  loadReports();
}

function loadReports() {
  if (!rptPerms.canView) return;
  const s = document.getElementById('startDate').value;
  const e = document.getElementById('endDate').value;
  const ov = document.getElementById('rpt-loading');
  ov.style.display = 'flex';

  fetch(`api/report.php?report=${currentReport}&start_date=${s}&end_date=${e}`)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        lastData = data;
        renderReport(currentReport, data);
      } else {
        showError(data.message || 'Failed to load report');
      }
    })
    .catch(() => showError('Network error — please check your connection.'))
    .finally(() => { ov.style.display = 'none'; });
}

function showError(msg) {
  document.getElementById('reportContent').innerHTML = `
    <div class="rpt-empty"><i class="bi bi-exclamation-triangle"></i><p>${msg}</p>
      <button class="btn-apply" style="margin:12px auto 0;max-width:180px;" onclick="loadReports()">
        <i class="bi bi-arrow-repeat"></i> Retry
      </button>
    </div>`;
}

Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#64748b';

const PALETTE = ['#0d9488','#10b981','#f59e0b','#3b82f6','#8b5cf6','#ef4444','#ec4899','#14b8a6'];

function lineOpts(label) {
  return {
    responsive: true, maintainAspectRatio: true,
    animation: { duration: 600 },
    plugins: {
      legend: { position:'top', labels:{ usePointStyle:true, pointStyle:'circle', boxWidth:7, padding:14 } },
      tooltip: { callbacks:{ label: ctx => {
        let l = (ctx.dataset.label||'') + ': ';
        return l + (typeof ctx.parsed.y === 'number' && ctx.parsed.y > 100
          ? '₱' + ctx.parsed.y.toLocaleString() : ctx.parsed.y);
      }}}
    },
    scales: {
      y: { beginAtZero:true, grid:{ color:'#f1f5f9' }, border:{ dash:[4,4] },
           ticks:{ callback: v => v>=1e6 ? '₱'+(v/1e6).toFixed(1)+'M'
                                        : v>=1e3 ? '₱'+(v/1e3).toFixed(0)+'K' : '₱'+v }},
      x: { grid:{ display:false } }
    }
  };
}
function donutOpts(pos='bottom') {
  return {
    responsive:true, maintainAspectRatio:true,
    animation:{ duration:600 },
    plugins:{ legend:{ position:pos, labels:{ usePointStyle:true, boxWidth:8, padding:14 }},
      tooltip:{ callbacks:{ label: ctx => ' ₱'+parseFloat(ctx.raw||0).toLocaleString() }}}
  };
}
function barOpts() {
  return {
    responsive:true, maintainAspectRatio:true,
    animation:{ duration:600 },
    plugins:{ legend:{ display:false }},
    scales:{ y:{ beginAtZero:true, grid:{ color:'#f1f5f9' }}, x:{ grid:{ display:false }}}
  };
}
function mkChart(id, cfg) {
  if (charts[id]) { charts[id].destroy(); delete charts[id]; }
  const ctx = document.getElementById(id);
  if (!ctx) return null;
  charts[id] = new Chart(ctx.getContext('2d'), cfg);
  return charts[id];
}

function renderReport(type, data) {
  const c = document.getElementById('reportContent');
  ({ sales:renderSales, patients:renderPatients,
     appointments:renderAppointments, inventory:renderInventory,
     services:renderServices })[type]?.(c, data);
}

// ── SALES ────────────────────────────────────────────────────
function renderSales(c, data) {
  const s  = data.summary || {};
  const mo = data.monthly  || [];
  const ti = data.top_items || [];
  const pm = data.payment_methods || [];

  c.innerHTML = `
  <div class="rpt-stats">
    ${statCard('Total Revenue',   '₱'+fmt(s.total_revenue||0),   (s.growth||0)+'% vs last period','bi-cash-stack','#eff6ff','#3b82f6')}
    ${statCard('Total Collected', '₱'+fmt(s.total_collected||0), (s.collection_rate||0)+'% collection rate','bi-credit-card','#f0fdf4','#10b981')}
    ${statCard('Transactions',    fmt(s.transaction_count||0),   (s.patients_served||0)+' patients served','bi-receipt','#f0fdfa','#0d9488')}
    ${statCard('Pending Balance', '₱'+fmt(s.pending_balance||0), 'To be collected','bi-hourglass-split','#fffbeb','#f59e0b')}
  </div>

  <div class="rpt-row rpt-row-1">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-graph-up" style="color:var(--teal);"></i><h6>Monthly Revenue Trend</h6></div>
      <div class="chart-card-body"><div class="chart-wrap"><canvas id="ch-revenue" height="110"></canvas></div></div>
    </div>
  </div>

  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-trophy" style="color:#f59e0b;"></i><h6>Top Selling Items</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Item</th><th>Type</th><th>Qty</th><th>Revenue</th></tr></thead>
            <tbody>${ti.map(r=>`<tr>
              <td class="fw" data-label="Item">${esc(r.item_name)}</td>
              <td data-label="Type"><span class="rpt-badge ${r.item_type==='product'?'rpt-badge-blue':'rpt-badge-green'}">${r.item_type}</span></td>
              <td data-label="Qty">${r.total_quantity}</td>
              <td class="money" data-label="Revenue">₱${fmt(r.total_revenue)}</td>
            </tr>`).join('')||emptyRow(4)}</tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-credit-card" style="color:#3b82f6;"></i><h6>Payment Methods</h6></div>
      <div class="chart-card-body"><div class="chart-wrap"><canvas id="ch-payment" height="200"></canvas></div></div>
    </div>
  </div>`;

  mkChart('ch-revenue',{type:'line',data:{
    labels: mo.map(m=>m.month),
    datasets:[
      {label:'Revenue (₱)', data:mo.map(m=>parseFloat(m.revenue)),
       borderColor:'#0d9488',backgroundColor:'rgba(13,148,136,.06)',fill:true,tension:.3,
       pointBackgroundColor:'#0d9488',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:4},
      {label:'Collected (₱)',data:mo.map(m=>parseFloat(m.collected)),
       borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.06)',fill:true,tension:.3,
       pointBackgroundColor:'#10b981',pointBorderColor:'#fff',pointBorderWidth:2,pointRadius:4}
    ]},options:lineOpts()});

  mkChart('ch-payment',{type:'doughnut',data:{
    labels:pm.map(p=>p.payment_method),
    datasets:[{data:pm.map(p=>parseFloat(p.total_amount)),backgroundColor:PALETTE}]
  },options:donutOpts()});
}

// ── PATIENTS ─────────────────────────────────────────────────
function renderPatients(c, data) {
  const s  = data.summary || {};
  const ag = data.age_groups || [];
  const tp = data.top_patients || [];
  const gr = data.growth || [];
  const gd = data.gender_data || [];

  c.innerHTML = `
  <div class="rpt-stats">
    ${statCard('Total Patients',    fmt(s.total_patients||0),    '+'+( s.new_patients||0)+' new this period','bi-people','#eff6ff','#3b82f6')}
    ${statCard('Returning Patients',fmt(s.returning_patients||0),(s.returning_rate||0)+'% return rate','bi-arrow-repeat','#f0fdf4','#10b981')}
    ${statCard('Avg Spend / Patient','₱'+fmt(s.avg_spent||0),    'Total: ₱'+fmt(s.total_spent||0),'bi-cash','#fffbeb','#f59e0b')}
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-gender-ambiguous" style="color:#3b82f6;"></i><h6>Patient Demographics</h6></div>
      <div class="chart-card-body"><canvas id="ch-gender" height="200"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-calendar-heart" style="color:#10b981;"></i><h6>Age Distribution</h6></div>
      <div class="chart-card-body"><canvas id="ch-age" height="200"></canvas></div>
    </div>
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-graph-up" style="color:#0d9488;"></i><h6>Patient Growth</h6></div>
      <div class="chart-card-body"><canvas id="ch-growth" height="180"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-trophy" style="color:#f59e0b;"></i><h6>Top Patients by Spending</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Patient</th><th>Visits</th><th>Total Spent</th></tr></thead>
            <tbody>${tp.map(r=>`<tr>
              <td class="fw" data-label="Patient">${esc(r.patient_name)}</td>
              <td data-label="Visits">${r.total_visits}</td>
              <td class="money" data-label="Total Spent">₱${fmt(r.total_spent)}</td>
            </tr>`).join('')||emptyRow(3)}</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>`;

  mkChart('ch-gender',{type:'doughnut',data:{labels:gd.map(g=>g.gender),datasets:[{data:gd.map(g=>g.total_patients),backgroundColor:['#3b82f6','#ec4899','#8b5cf6']}]},options:{...donutOpts(),plugins:{...donutOpts().plugins,tooltip:{callbacks:{label:ctx=>' '+ctx.raw+' patients'}}}}});
  mkChart('ch-age',{type:'bar',data:{labels:ag.map(a=>a.age_group),datasets:[{label:'Patients',data:ag.map(a=>a.patient_count),backgroundColor:'#0d9488',borderRadius:6}]},options:barOpts()});
  mkChart('ch-growth',{type:'line',data:{labels:gr.map(g=>g.month),datasets:[{label:'New Patients',data:gr.map(g=>g.new_patients),borderColor:'#0d9488',backgroundColor:'rgba(13,148,136,.08)',fill:true,tension:.3,pointRadius:3}]},options:{...lineOpts(),scales:{y:{beginAtZero:true,grid:{color:'#f1f5f9'}},x:{grid:{display:false}}}}});
}

// ── APPOINTMENTS ──────────────────────────────────────────────
function renderAppointments(c, data) {
  const s  = data.summary || {};
  const sd = data.status_breakdown || [];
  const mo = data.monthly || [];
  const dr = data.doctor_performance || [];
  const sv = data.service_breakdown || [];

  c.innerHTML = `
  <div class="rpt-stats">
    ${statCard('Total Appointments', fmt(s.total_appointments||0), '', 'bi-calendar-check','#eff6ff','#3b82f6')}
    ${statCard('Completed',          fmt(s.completed||0),         (s.completion_rate||0)+'% rate','bi-check-circle','#f0fdf4','#10b981')}
    ${statCard('Cancelled',          fmt(s.cancelled||0),         (s.cancellation_rate||0)+'% rate','bi-x-circle','#fff5f5','#dc2626')}
    ${statCard('Revenue Collected',  '₱'+fmt(s.paid_amount||0),   fmt(s.paid_count||0)+' paid appts','bi-credit-card','#f0fdfa','#0d9488')}
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-pie-chart" style="color:#3b82f6;"></i><h6>Status Breakdown</h6></div>
      <div class="chart-card-body"><canvas id="ch-status" height="200"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-bar-chart" style="color:#10b981;"></i><h6>Monthly Trend</h6></div>
      <div class="chart-card-body"><canvas id="ch-monthly" height="200"></canvas></div>
    </div>
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-person-badge" style="color:#0d9488;"></i><h6>Doctor Performance</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Doctor</th><th>Appts</th><th>Completed</th><th>Revenue</th></tr></thead>
            <tbody>${dr.map(r=>`<tr>
              <td class="fw" data-label="Doctor">${esc(r.doctor_name)}</td>
              <td data-label="Appts">${r.total_appointments}</td>
              <td data-label="Completed">${r.completed||0}</td>
              <td class="money" data-label="Revenue">₱${fmt(r.revenue_generated||0)}</td>
            </tr>`).join('')||emptyRow(4)}</tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-star" style="color:#f59e0b;"></i><h6>Top Services / Products</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Item</th><th>Type</th><th>Bookings</th><th>Revenue</th></tr></thead>
            <tbody>${sv.map(r=>`<tr>
              <td class="fw" data-label="Item">${esc(r.item_name)}</td>
              <td data-label="Type"><span class="rpt-badge ${r.item_type==='product'?'rpt-badge-blue':'rpt-badge-green'}">${r.item_type}</span></td>
              <td data-label="Bookings">${r.total_appointments}</td>
              <td class="money" data-label="Revenue">₱${fmt(r.total_payment||0)}</td>
            </tr>`).join('')||emptyRow(4)}</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>`;

  mkChart('ch-status',{type:'doughnut',data:{labels:sd.map(s=>s.status),datasets:[{data:sd.map(s=>s.count),backgroundColor:['#10b981','#f59e0b','#ef4444','#6b7280','#3b82f6']}]},options:{...donutOpts(),plugins:{...donutOpts().plugins,tooltip:{callbacks:{label:ctx=>' '+ctx.raw+' appts'}}}}});
  mkChart('ch-monthly',{type:'line',data:{labels:mo.map(m=>m.month),datasets:[{label:'Appointments',data:mo.map(m=>m.total_appointments),borderColor:'#0d9488',backgroundColor:'rgba(13,148,136,.08)',fill:true,tension:.3,pointRadius:3}]},options:{...lineOpts(),scales:{y:{beginAtZero:true,grid:{color:'#f1f5f9'}},x:{grid:{display:false}}}}});
}

// ── INVENTORY ─────────────────────────────────────────────────
function renderInventory(c, data) {
  const s  = data.summary || {};
  const ls = data.low_stock   || [];
  const ts = data.top_selling || [];
  const cv = data.category_value || [];

  c.innerHTML = `
  <div class="rpt-stats">
    ${statCard('Total Items',      fmt(s.total_items||0),       '', 'bi-box','#eff6ff','#3b82f6')}
    ${statCard('Stock Value',      '₱'+fmt(s.total_value||0),  '', 'bi-cash-stack','#f0fdf4','#10b981')}
    ${statCard('Low Stock Items',  fmt(s.low_stock_count||0),   'Need restocking','bi-exclamation-triangle','#fffbeb','#f59e0b')}
    ${statCard('Out of Stock',     fmt(s.out_of_stock_count||0),'','bi-x-octagon','#fff5f5','#dc2626')}
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-pie-chart" style="color:#3b82f6;"></i><h6>Inventory Value by Category</h6></div>
      <div class="chart-card-body"><canvas id="ch-catval" height="200"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-trophy" style="color:#f59e0b;"></i><h6>Top Selling Items</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Item</th><th>Category</th><th>Units Sold</th><th>Revenue</th></tr></thead>
            <tbody>${ts.map(r=>`<tr>
              <td class="fw" data-label="Item">${esc(r.name)}</td>
              <td data-label="Category"><span class="rpt-badge rpt-badge-gray">${r.category}</span></td>
              <td data-label="Units Sold">${r.units_sold}</td>
              <td class="money" data-label="Revenue">₱${fmt(r.revenue)}</td>
            </tr>`).join('')||emptyRow(4)}</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div class="rpt-row rpt-row-1">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-exclamation-triangle" style="color:#dc2626;"></i><h6>Low Stock Alert</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Item Name</th><th>Category</th><th>Stock</th><th>Min</th><th>Status</th><th>Value</th></tr></thead>
            <tbody>${ls.map(r=>`<tr>
              <td class="fw" data-label="Item Name">${esc(r.name)}</td>
              <td data-label="Category">${r.category}</td>
              <td data-label="Stock">${r.stock}</td>
              <td data-label="Min">${r.min_stock}</td>
              <td data-label="Status"><span class="rpt-badge ${r.stock<=0?'rpt-badge-red':'rpt-badge-amber'}">${r.stock<=0?'Out of Stock':'Low Stock'}</span></td>
              <td class="money" data-label="Value">₱${fmt(r.stock*r.selling_price)}</td>
            </tr>`).join('')||emptyRow(6,'No low stock items')}</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>`;

  mkChart('ch-catval',{type:'doughnut',data:{labels:cv.map(c=>c.category),datasets:[{data:cv.map(c=>c.total_value),backgroundColor:PALETTE}]},options:donutOpts()});
}

// ── SERVICES ─────────────────────────────────────────────────
function renderServices(c, data) {
  const s  = data.summary || {};
  const ts = data.top_services || [];
  const cd = data.category_breakdown || [];

  c.innerHTML = `
  <div class="rpt-stats">
    ${statCard('Total Services', fmt(s.total_services||0), '', 'bi-star','#eff6ff','#3b82f6')}
    ${statCard('Total Revenue',  '₱'+fmt(s.total_revenue||0), '', 'bi-cash-stack','#f0fdf4','#10b981')}
    ${statCard('Times Rendered', fmt(s.total_rendered||0), '', 'bi-calendar-check','#f0fdfa','#0d9488')}
  </div>
  <div class="rpt-row rpt-row-2">
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-pie-chart" style="color:#3b82f6;"></i><h6>Services by Category</h6></div>
      <div class="chart-card-body"><canvas id="ch-svccat" height="200"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head"><i class="bi bi-trophy" style="color:#f59e0b;"></i><h6>Top Performing Services</h6></div>
      <div class="chart-card-body p-0">
        <div class="rpt-table-wrap">
          <table class="rpt-table">
            <thead><tr><th>Service</th><th>Category</th><th>Rendered</th><th>Revenue</th></tr></thead>
            <tbody>${ts.map(r=>`<tr>
              <td class="fw" data-label="Service">${esc(r.name)}</td>
              <td data-label="Category"><span class="rpt-badge rpt-badge-gray">${r.category}</span></td>
              <td data-label="Rendered">${r.times_rendered}</td>
              <td class="money" data-label="Revenue">₱${fmt(r.revenue)}</td>
            </tr>`).join('')||emptyRow(4)}</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>`;

  mkChart('ch-svccat',{type:'doughnut',data:{labels:cd.map(c=>c.category),datasets:[{data:cd.map(c=>c.total_revenue),backgroundColor:PALETTE}]},options:donutOpts()});
}

// ── MICRO HELPERS ─────────────────────────────────────────────
function statCard(label, val, sub, icon, bgColor, iconColor) {
  return `<div class="stat-card">
    <div>
      <div class="stat-lbl">${label}</div>
      <div class="stat-val">${val}</div>
      ${sub ? `<div class="stat-sub">${sub}</div>` : ''}
    </div>
    <div class="stat-ico" style="background:${bgColor};color:${iconColor};"><i class="bi ${icon}"></i></div>
  </div>`;
}
function emptyRow(cols, msg='No data available') {
  return `<tr><td colspan="${cols}" style="text-align:center;color:#94a3b8;padding:32px 16px;font-size:12px;">${msg}</td></tr>`;
}
function fmt(n)  { return parseFloat(n||0).toLocaleString('en-PH'); }
function esc(t)  { const d=document.createElement('div'); d.textContent=t||''; return d.innerHTML; }

// ═══════════════════════════════════════════════════════════
//  EXPORT — CLIENT-SIDE PDF (jsPDF) & EXCEL (SheetJS)
// ═══════════════════════════════════════════════════════════
function exportReport(type) {
  if (!rptPerms.canCreate) {
    Swal.fire('Access Denied', "You don't have permission to export reports.", 'warning');
    return;
  }
  if (!lastData) {
    Swal.fire('No Data', 'Please load a report first before exporting.', 'info');
    return;
  }

  const s    = document.getElementById('startDate').value;
  const e    = document.getElementById('endDate').value;
  const title = currentReport.charAt(0).toUpperCase() + currentReport.slice(1) + ' Report';

  if (type === 'pdf') {
    exportPDF(title, s, e);
  } else {
    exportExcel(title, s, e);
  }
}

function exportPDF(title, startDate, endDate) {
  Swal.fire({ title:'Generating PDF…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });

  doc.setFillColor(13, 148, 136);
  doc.rect(0, 0, 210, 28, 'F');
  doc.setTextColor(255, 255, 255);
  doc.setFontSize(16); doc.setFont('helvetica','bold');
  doc.text('EyeCore PH — ' + title, 14, 12);
  doc.setFontSize(9); doc.setFont('helvetica','normal');
  doc.text('Period: ' + startDate + ' to ' + endDate, 14, 20);
  doc.text('Generated: ' + new Date().toLocaleString('en-PH'), 14, 25);

  let y = 36;

  const writeSection = (heading, rows, cols) => {
    if (!rows || rows.length === 0) return;
    doc.setFontSize(11); doc.setFont('helvetica','bold');
    doc.setTextColor(13, 148, 136);
    doc.text(heading, 14, y); y += 4;

    doc.autoTable({
      startY: y,
      head: [cols],
      body: rows,
      theme: 'grid',
      headStyles: { fillColor:[13,148,136], textColor:255, fontSize:8, fontStyle:'bold' },
      bodyStyles: { fontSize:8, textColor:[30,30,30] },
      alternateRowStyles: { fillColor:[240,253,250] },
      margin: { left:14, right:14 },
    });
    y = doc.lastAutoTable.finalY + 8;
  };

  const d = lastData;
  const s = d.summary || {};

  const summaryRows = buildSummaryRows(s);
  writeSection('Summary', summaryRows, ['Metric','Value']);

  switch (currentReport) {
    case 'sales':
      writeSection('Top Selling Items', (d.top_items||[]).map(r=>[r.item_name, r.item_type, r.total_quantity, '₱'+fmt(r.total_revenue)]), ['Item','Type','Quantity','Revenue']);
      writeSection('Payment Methods',   (d.payment_methods||[]).map(r=>[r.payment_method, '₱'+fmt(r.total_amount)]), ['Method','Amount']);
      writeSection('Monthly Trend',     (d.monthly||[]).map(r=>[r.month,'₱'+fmt(r.revenue),'₱'+fmt(r.collected)]), ['Month','Revenue','Collected']);
      break;
    case 'patients':
      writeSection('Top Patients',  (d.top_patients||[]).map(r=>[r.patient_name, r.total_visits,'₱'+fmt(r.total_spent)]),['Patient','Visits','Spent']);
      writeSection('Age Groups',    (d.age_groups||[]).map(r=>[r.age_group, r.patient_count]),['Age Group','Count']);
      break;
    case 'appointments':
      writeSection('Status Breakdown',   (d.status_breakdown||[]).map(r=>[r.status, r.count]),['Status','Count']);
      writeSection('Doctor Performance', (d.doctor_performance||[]).map(r=>[r.doctor_name, r.total_appointments, r.completed||0,'₱'+fmt(r.revenue_generated||0)]),['Doctor','Total','Completed','Revenue']);
      writeSection('Top Services',       (d.service_breakdown||[]).map(r=>[r.item_name, r.item_type, r.total_appointments,'₱'+fmt(r.total_payment||0)]),['Item','Type','Bookings','Revenue']);
      break;
    case 'inventory':
      writeSection('Low Stock Alert', (d.low_stock||[]).map(r=>[r.name,r.category,r.stock,r.min_stock,r.stock<=0?'Out of Stock':'Low Stock','₱'+fmt(r.stock*r.selling_price)]),['Item','Category','Stock','Min','Status','Value']);
      writeSection('Top Selling',     (d.top_selling||[]).map(r=>[r.name,r.category,r.units_sold,'₱'+fmt(r.revenue)]),['Item','Category','Units Sold','Revenue']);
      break;
    case 'services':
      writeSection('Top Services', (d.top_services||[]).map(r=>[r.name,r.category,r.times_rendered,'₱'+fmt(r.revenue)]),['Service','Category','Rendered','Revenue']);
      break;
  }

  const pages = doc.internal.getNumberOfPages();
  for (let i=1;i<=pages;i++){
    doc.setPage(i);
    doc.setFontSize(8); doc.setTextColor(150);
    doc.text('EyeCore PH — Confidential', 14, 290);
    doc.text('Page '+i+' of '+pages, 196, 290, { align:'right' });
  }

  doc.save(`${currentReport}_report_${startDate}_to_${endDate}.pdf`);
  Swal.fire({ icon:'success', title:'PDF Downloaded!', timer:2000, showConfirmButton:false });
}

function exportExcel(title, startDate, endDate) {
  Swal.fire({ title:'Generating Excel…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });

  const wb = XLSX.utils.book_new();
  const d  = lastData;
  const s  = d.summary || {};

  const summaryData = [
    ['EyeCore PH — ' + title],
    ['Period: ' + startDate + ' to ' + endDate],
    ['Generated: ' + new Date().toLocaleString('en-PH')],
    [],
    ['SUMMARY'],
    ...buildSummaryRows(s)
  ];
  const wsSummary = XLSX.utils.aoa_to_sheet(summaryData);
  wsSummary['!cols'] = [{ wch:30 },{ wch:20 }];
  XLSX.utils.book_append_sheet(wb, wsSummary, 'Summary');

  switch (currentReport) {
    case 'sales':
      appendSheet(wb, 'Top Items',       ['Item','Type','Quantity','Revenue'],
        (d.top_items||[]).map(r=>[r.item_name, r.item_type, r.total_quantity, parseFloat(r.total_revenue||0)]));
      appendSheet(wb, 'Payment Methods', ['Method','Amount'],
        (d.payment_methods||[]).map(r=>[r.payment_method, parseFloat(r.total_amount||0)]));
      appendSheet(wb, 'Monthly Trend',   ['Month','Revenue','Collected'],
        (d.monthly||[]).map(r=>[r.month, parseFloat(r.revenue||0), parseFloat(r.collected||0)]));
      break;
    case 'patients':
      appendSheet(wb, 'Top Patients', ['Patient','Visits','Total Spent'],
        (d.top_patients||[]).map(r=>[r.patient_name, r.total_visits, parseFloat(r.total_spent||0)]));
      appendSheet(wb, 'Age Groups', ['Age Group','Count'],
        (d.age_groups||[]).map(r=>[r.age_group, r.patient_count]));
      appendSheet(wb, 'Gender', ['Gender','Count'],
        (d.gender_data||[]).map(r=>[r.gender, r.total_patients]));
      break;
    case 'appointments':
      appendSheet(wb, 'Status',   ['Status','Count'],
        (d.status_breakdown||[]).map(r=>[r.status, r.count]));
      appendSheet(wb, 'Doctors',  ['Doctor','Total','Completed','Revenue'],
        (d.doctor_performance||[]).map(r=>[r.doctor_name, r.total_appointments, r.completed||0, parseFloat(r.revenue_generated||0)]));
      appendSheet(wb, 'Services', ['Item','Type','Bookings','Revenue'],
        (d.service_breakdown||[]).map(r=>[r.item_name, r.item_type, r.total_appointments, parseFloat(r.total_payment||0)]));
      break;
    case 'inventory':
      appendSheet(wb, 'Low Stock',    ['Item','Category','Stock','Min Stock','Status','Value'],
        (d.low_stock||[]).map(r=>[r.name,r.category,r.stock,r.min_stock,r.stock<=0?'Out of Stock':'Low Stock',parseFloat(r.stock*r.selling_price)]));
      appendSheet(wb, 'Top Selling',  ['Item','Category','Units Sold','Revenue'],
        (d.top_selling||[]).map(r=>[r.name,r.category,r.units_sold,parseFloat(r.revenue||0)]));
      break;
    case 'services':
      appendSheet(wb, 'Top Services', ['Service','Category','Rendered','Revenue'],
        (d.top_services||[]).map(r=>[r.name,r.category,r.times_rendered,parseFloat(r.revenue||0)]));
      appendSheet(wb, 'By Category',  ['Category','Revenue'],
        (d.category_breakdown||[]).map(r=>[r.category,parseFloat(r.total_revenue||0)]));
      break;
  }

  XLSX.writeFile(wb, `${currentReport}_report_${startDate}_to_${endDate}.xlsx`);
  Swal.fire({ icon:'success', title:'Excel Downloaded!', timer:2000, showConfirmButton:false });
}

function appendSheet(wb, name, headers, rows) {
  if (!rows || rows.length === 0) return;
  const data = [headers, ...rows];
  const ws   = XLSX.utils.aoa_to_sheet(data);
  ws['!cols'] = headers.map(() => ({ wch:20 }));
  XLSX.utils.book_append_sheet(wb, ws, name.substring(0,31));
}

function buildSummaryRows(s) {
  const rows = [];
  const map = {
    total_revenue:'Total Revenue', total_collected:'Total Collected',
    transaction_count:'Transactions', patients_served:'Patients Served',
    pending_balance:'Pending Balance', collection_rate:'Collection Rate (%)',
    growth:'Growth (%)', total_patients:'Total Patients',
    new_patients:'New Patients', returning_patients:'Returning Patients',
    avg_spent:'Avg Spend/Patient', total_spent:'Total Spent',
    total_appointments:'Total Appointments', completed:'Completed',
    cancelled:'Cancelled', paid_amount:'Revenue Collected',
    total_items:'Total Items', total_value:'Stock Value',
    low_stock_count:'Low Stock', out_of_stock_count:'Out of Stock',
    total_services:'Total Services', total_rendered:'Times Rendered'
  };
  Object.keys(map).forEach(k => {
    if (s[k] !== undefined) rows.push([map[k], s[k]]);
  });
  return rows;
}
</script>