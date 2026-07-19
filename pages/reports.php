<?php
require_once __DIR__ . '/../config/db.php';

// Check if SuperAdmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    header('Location: ../access-denied.php');
    exit();
}

// Get unique cities for filter
$cities = $pdo->query("SELECT DISTINCT city FROM clinics WHERE city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);

// Get date range from GET parameters
$range = $_GET['range'] ?? '6months';
$city_filter = $_GET['city'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinic System Reports - Super Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js for charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
    body {
        background: #f8fafc;
        font-family: 'Segoe UI', sans-serif;
    }
    .navbar-custom {
        background: linear-gradient(to right, #0d9488, #3b82f6);
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .card-soft {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .card-soft:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .icon-box {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    .gradient-btn {
        background: linear-gradient(to right, #0d9488, #3b82f6);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 24px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .gradient-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(13,148,136,0.2);
        color: #fff;
    }
    .chart-container {
        position: relative;
        height: 300px;
    }
    .performance-bar {
        height: 8px;
        background: #e5e7eb;
        border-radius: 4px;
        overflow: hidden;
    }
    .performance-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.5s ease;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-suspended { background: #fee2e2; color: #991b1b; }
    .city-badge {
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 12px;
        background: #f3f4f6;
        color: #4b5563;
    }
    .revenue-display {
        font-size: 1.8rem;
        font-weight: 700;
        background: linear-gradient(to right, #0d9488, #3b82f6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .filter-section {
        background: #fff;
        padding: 1.5rem;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        margin-bottom: 1.5rem;
    }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Clinic System Financial Reports</h2>
            <p class="text-muted mt-1">Complete financial overview and analytics for all clinics in Cavite</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-secondary d-flex align-items-center gap-2" onclick="exportPDF()">
                <i data-lucide="download" style="width:16px;height:16px"></i>
                Export PDF
            </button>
            <button class="btn gradient-btn d-flex align-items-center gap-2" onclick="exportExcel()">
                <i data-lucide="download" style="width:16px;height:16px"></i>
                Export Excel
            </button>
        </div>
    </div>

    <!-- FILTER SECTION -->
    <div class="filter-section">
        <form id="filterForm" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-medium">City Filter</label>
                <select name="city" class="form-select" onchange="applyFilters()">
                    <option value="all">All Cities in Cavite</option>
                    <?php foreach($cities as $city): ?>
                    <option value="<?= htmlspecialchars($city) ?>" <?= $city_filter==$city?'selected':'' ?>>
                        <?= htmlspecialchars($city) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-medium">Time Period</label>
                <select name="range" class="form-select" onchange="applyFilters()">
                    <option value="6months" <?= $range=='6months'?'selected':'' ?>>Last 6 Months</option>
                    <option value="30days" <?= $range=='30days'?'selected':'' ?>>Last 30 Days</option>
                    <option value="90days" <?= $range=='90days'?'selected':'' ?>>Last 90 Days</option>
                    <option value="year" <?= $range=='year'?'selected':'' ?>>This Year</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-medium">Clinic Status</label>
                <select name="status" class="form-select" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="Active" <?= $status_filter=='Active'?'selected':'' ?>>Active Only</option>
                    <option value="Pending" <?= $status_filter=='Pending'?'selected':'' ?>>Pending Only</option>
                    <option value="Suspended" <?= $status_filter=='Suspended'?'selected':'' ?>>Suspended Only</option>
                </select>
            </div>
        </form>
    </div>

    <!-- SYSTEM SUMMARY CARDS -->
    <div id="summaryCards" class="row g-3 mb-4">
        <!-- Cards will be loaded by JavaScript -->
        <div class="col-12 text-center py-5">
            <div class="loading-spinner mx-auto mb-3" style="width: 3rem; height: 3rem;"></div>
            <p class="text-muted">Loading system data...</p>
        </div>
    </div>

    <!-- REVENUE CHARTS -->
    <div class="row g-4 mb-4">
        <!-- Revenue Trend Chart -->
        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h4 class="fw-semibold mb-4 d-flex justify-content-between align-items-center">
                    <span>System Revenue Trend</span>
                    <span class="text-sm text-muted">All Clinics Combined</span>
                </h4>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- City Revenue Distribution -->
        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h4 class="fw-semibold mb-4 d-flex justify-content-between align-items-center">
                    <span>Revenue by City</span>
                    <span class="text-sm text-muted">Cavite Cities Distribution</span>
                </h4>
                <div class="chart-container">
                    <canvas id="cityRevenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- CLINIC PERFORMANCE SECTION -->
    <div class="row g-4 mb-4">
        <!-- Top Performing Clinics -->
        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h4 class="fw-semibold mb-4">Top Performing Clinics</h4>
                <div id="topClinicsTable">
                    <div class="text-center py-4">
                        <div class="loading-spinner mx-auto mb-3"></div>
                        <p class="text-muted">Loading clinic performance data...</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Clinic Status Overview -->
        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h4 class="fw-semibold mb-4">Clinic Status Overview</h4>
                <div class="chart-container">
                    <canvas id="clinicStatusChart"></canvas>
                </div>
                <div id="clinicStatusCards" class="row mt-4 g-2">
                    <!-- Status cards will be loaded by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- CLINIC TYPE PERFORMANCE -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card-soft p-4">
                <h4 class="fw-semibold mb-4">Clinic Type Performance Analysis</h4>
                <div id="clinicTypeTable">
                    <div class="text-center py-4">
                        <div class="loading-spinner mx-auto mb-3"></div>
                        <p class="text-muted">Loading clinic type data...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SYSTEM METRICS -->
    <div id="systemMetrics" class="row g-3 mb-4">
        <!-- Metrics will be loaded by JavaScript -->
    </div>

    <!-- QUICK REPORT ACTIONS -->
    <div class="card-soft p-4 mb-4">
        <h4 class="fw-semibold mb-4">Super Admin Report Actions</h4>
        <div class="row g-3">
            <div class="col-md-3">
                <button class="card-soft p-4 w-100 text-start border-0" onclick="generateRevenueReport()">
                    <i data-lucide="file-text" class="text-teal mb-3" style="width:32px;height:32px"></i>
                    <p class="fw-semibold mb-1">Revenue Report</p>
                    <p class="text-sm text-muted mb-0">Detailed financial analysis</p>
                </button>
            </div>
            <div class="col-md-3">
                <button class="card-soft p-4 w-100 text-start border-0" onclick="generateClinicPerformanceReport()">
                    <i data-lucide="bar-chart-3" class="text-blue mb-3" style="width:32px;height:32px"></i>
                    <p class="fw-semibold mb-1">Clinic Performance</p>
                    <p class="text-sm text-muted mb-0">Individual clinic analysis</p>
                </button>
            </div>
            <div class="col-md-3">
                <button class="card-soft p-4 w-100 text-start border-0" onclick="generateGrowthReport()">
                    <i data-lucide="trending-up" class="text-green mb-3" style="width:32px;height:32px"></i>
                    <p class="fw-semibold mb-1">Growth Forecast</p>
                    <p class="text-sm text-muted mb-0">Trends & projections</p>
                </button>
            </div>
            <div class="col-md-3">
                <button class="card-soft p-4 w-100 text-start border-0" onclick="generateCollectionReport()">
                    <i data-lucide="credit-card" class="text-purple mb-3" style="width:32px;height:32px"></i>
                    <p class="fw-semibold mb-1">Collection Report</p>
                    <p class="text-sm text-muted mb-0">Payment collection analysis</p>
                </button>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="text-center text-muted small mt-4">
        <p>Clinic System Reports • Super Admin Dashboard • Generated on <span id="currentDate"></span></p>
        <p class="mb-0">All data is confidential and for authorized Super Admin use only</p>
    </div>
</div>

<!-- Add loading spinner style -->
<style>
.loading-spinner {
    width: 1.5rem;
    height: 1.5rem;
    border: 2px solid #dee2e6;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/reports.js"></script>
<script>
// Set current date in footer
document.getElementById('currentDate').textContent = new Date().toLocaleDateString('en-PH', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
});

// Initialize Lucide icons
lucide.createIcons();

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadReportData();
});
</script>
</body>
</html>