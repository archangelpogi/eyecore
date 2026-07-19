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
    <style>
        body {
            background: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .page-header { color: #0f766e; font-weight: 800; }
        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 22px;
            border: 1px solid #e2e8f0;
            height: 100%;
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
        }
        .stat-value { font-size: 1.7rem; font-weight: 800; color: #0f172a; }
        .stat-label { color: #64748b; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .chart-card, .table-card {
            background: white;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            padding: 24px;
        }
        .filter-bar {
            background: white;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
        }
        .table thead th {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }
        .badge-plan { background: #f0fdfa; color: #0d9488; font-weight: 600; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="page-header mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Subscription Income</h3>
            <p class="text-muted small mb-0">Totoong kita galing sa completed na payments lang</p>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon mb-2" style="background:#f0fdfa; color:#0d9488;"><i class="bi bi-cash-stack"></i></div>
                <div class="stat-value" id="statAllTime">₱0</div>
                <div class="stat-label">All-Time Income</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon mb-2" style="background:#eff6ff; color:#2563eb;"><i class="bi bi-calendar-month"></i></div>
                <div class="stat-value" id="statThisMonth">₱0</div>
                <div class="stat-label">This Month</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon mb-2" style="background:#fef3c7; color:#d97706;"><i class="bi bi-calendar-day"></i></div>
                <div class="stat-value" id="statToday">₱0</div>
                <div class="stat-label">Today</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon mb-2" style="background:#fee2e2; color:#dc2626;"><i class="bi bi-receipt"></i></div>
                <div class="stat-value" id="statFilteredCount">0</div>
                <div class="stat-label">Transactions (filtered)</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control form-control-sm" id="filterDateFrom">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control form-control-sm" id="filterDateTo">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Plan</label>
                <select class="form-select form-select-sm" id="filterPlan">
                    <option value="">All Plans</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-sm w-100" style="background:#0d9488;color:white;" onclick="applyFilters()">
                    <i class="bi bi-funnel me-1"></i> Apply Filter
                </button>
            </div>
        </div>
    </div>

    <!-- Chart + By Plan -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <h6 class="mb-3">Monthly Income (Last 12 Months)</h6>
                <canvas id="incomeChart" height="90"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <h6 class="mb-3">Income by Plan (filtered range)</h6>
                <canvas id="planChart" height="180"></canvas>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="table-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Transactions</h6>
            <span class="text-muted small" id="rangeLabel"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Clinic</th>
                        <th>Plan</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-end" id="pagination"></ul>
        </nav>
    </div>

</div>

<script>
const API = '../api/subscription_income_api.php';
let currentPage = 1;
let incomeChartInstance = null;
let planChartInstance = null;

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
            <a class="page-link" href="#" onclick="loadTable(${i}); return false;" style="${i === current ? 'background:#0d9488;border-color:#0d9488;' : ''}">${i}</a>
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

function applyFilters() {
    loadSummary();
    loadPlanBreakdown();
    loadTable(1);
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    // Default range: this month
    const now = new Date();
    document.getElementById('filterDateFrom').value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    document.getElementById('filterDateTo').value = now.toISOString().split('T')[0];

    loadPlansDropdown();
    loadSummary();
    loadChart();
    loadPlanBreakdown();
    loadTable(1);
});
</script>

</body>
</html>