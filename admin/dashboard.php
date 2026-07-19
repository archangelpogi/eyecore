<?php
session_start();
require_once __DIR__ . '/../config/db.php';

/* ================== SECURITY CHECK ================== */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ClinicAdmin') {
    header('Location: ../admin/login.php');
    exit;
}

$clinicId     = $_SESSION['clinic_id'];
$clinicName   = $_SESSION['clinic_name'] ?? 'Clinic';
$clinicStatus = 'Pending';

/* ================== GET CLINIC STATUS ================== */
$stmt = $pdo->prepare("SELECT status FROM clinics WHERE id = ?");
$stmt->execute([$clinicId]);
$clinic = $stmt->fetch(PDO::FETCH_ASSOC);

if ($clinic) {
    $clinicStatus = $clinic['status'];
}

/* ================== STATUS HANDLING ================== */
$isActive  = $clinicStatus === 'Active';
$isRejected  = in_array($clinicStatus, ['Rejected', 'Pending']);
$isSuspended = $clinicStatus === 'Suspended';

/* ================== APPROVED DASHBOARD DATA ================== */
if ($isActive) {
    /* ================== KPI CARDS ================== */
    // Total Patients
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ?");
    $stmt->execute([$clinicId]);
    $totalPatients = $stmt->fetchColumn() ?: 0;

    // Today's Appointments
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending
        FROM appointments
        WHERE clinic_id = ? AND appointment_date = ?
    ");
$stmt->execute([$clinicId, $today]);
$todayAppointments = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'completed'=>0, 'pending'=>0];

    // Low Stock
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE clinic_id = ? AND stock <= reorder_level");
$stmt->execute([$clinicId]);
$lowStock = $stmt->fetchColumn() ?: 0;

    // Monthly Sales
    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM invoices 
        WHERE clinic_id = ? 
        AND invoice_date BETWEEN ? AND ? 
        AND status = 'Paid'
    ");
    $stmt->execute([$clinicId, $monthStart, $monthEnd]);
    $monthlySales = $stmt->fetchColumn();

    /* ================== CHARTS DATA ================== */
    // Last 6 months labels
    $labels = [];
    $patientGrowth = [];
    $salesData = [];
    for ($i=5; $i>=0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $monthLabel = date('M', strtotime("-$i months"));
        $labels[] = $monthLabel;

        // Patient growth
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM patients WHERE clinic_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
        $stmt->execute([$clinicId, $month]);
        $patientGrowth[] = (int)($stmt->fetchColumn() ?: 0);

        // Monthly sales
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE clinic_id = ? AND DATE_FORMAT(invoice_date, '%Y-%m') = ? AND status = 'Paid'");
        $stmt->execute([$clinicId, $month]);
        $salesData[] = (float)($stmt->fetchColumn() ?: 0);
    }

    /* ================== RECENT ACTIVITIES ================== */
    $stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE clinic_id = ? ORDER BY created_at DESC LIMIT 4");
    $stmt->execute([$clinicId]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================== LOW STOCK ITEMS ================== */
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE clinic_id = ? AND stock <= reorder_level ORDER BY stock ASC");
    $stmt->execute([$clinicId]);
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($clinicName) ?></title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
    /* FIXED LAYOUT STRUCTURE */
    body {
        background-color: #f8fafc;
        font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        overflow-x: hidden;
    }
    
    /* Main wrapper with fixed topbar */
    .main-wrapper {
        display: flex;
        min-height: 100vh;
    }
    
    /* Fixed Sidebar */
    .sidebar-fixed {
        width: 250px;
        background: white;
        border-right: 1px solid #e2e8f0;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 100;
        overflow-y: auto;
        transition: transform 0.3s ease;
    }
    
    /* Fixed Topbar */
    .topbar-fixed {
        position: fixed;
        top: 0;
        left: 250px;
        right: 0;
        height: 60px;
        background: white;
        border-bottom: 1px solid #e2e8f0;
        z-index: 99;
        transition: left 0.3s ease;
    }
    
    /* Main Content Area */
    .main-content {
        flex: 1;
        margin-left: 250px;
        margin-top: 60px;
        padding: 20px;
        min-height: calc(100vh - 60px);
        transition: margin-left 0.3s ease;
    }
    
    /* Responsive adjustments */
    @media (max-width: 992px) {
        .sidebar-fixed {
            transform: translateX(-100%);
        }
        
        .sidebar-fixed.show {
            transform: translateX(0);
        }
        
        .topbar-fixed {
            left: 0;
        }
        
        .main-content {
            margin-left: 0;
        }
    }
    
    /* Card Styling */
    .stat-card {
        border: none;
        border-radius: 12px;
        background: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s;
        height: 100%;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }
    
    /* Chart container */
    .chart-container {
        position: relative;
        height: 240px;
        width: 100%;
    }
    
    /* Activity items */
    .activity-item {
        border-left: 3px solid #0891b2;
        padding-left: 1rem;
        margin-bottom: 1rem;
    }
    
    /* Quick actions */
    .quick-action-btn {
        display: block;
        padding: 1rem;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        text-decoration: none;
        color: #334155;
        transition: all 0.2s;
        text-align: center;
    }
    
    .quick-action-btn:hover {
        background: #0891b2;
        color: white;
        border-color: #0891b2;
    }
    
    /* Mobile adjustments */
    @media (max-width: 768px) {
        .main-content {
            padding: 15px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }
    }
    </style>
</head>
<body>

<!-- FIXED STRUCTURE -->
<div class="main-wrapper">
 <?php include('../include/header.php'); ?>   
    <!-- Fixed Sidebar -->
    <?php include('../include/sidebar.php'); ?>
    
    <!-- Fixed Topbar -->
    <?php include('../include/topbar.php'); ?>
    
    <!-- Main Content Area -->
    <main class="main-content">
        
        <!-- Dashboard Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 fw-bold mb-2">Dashboard</h1>
                <p class="text-muted mb-0">
                    Welcome back, <span class="fw-semibold"><?= htmlspecialchars($clinicName) ?></span>
                </p>
            </div>
            
            <?php if ($isActive): ?>
            <a href="reports.php" class="btn btn-primary d-flex align-items-center">
                <i class="bi bi-graph-up me-2"></i>
                View Reports
            </a>
            <?php endif; ?>
        </div>

        <!-- Alerts -->
        <?php if ($isSuspended): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-shield-exclamation fs-4 me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Account Suspended</h5>
                    <p class="mb-0">Your clinic account has been suspended. Please contact the Super Administrator.</p>
                </div>
            </div>
        </div>

        <?php elseif ($isRejected): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-info-circle fs-4 me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Clinic Not Approved</h5>
                    <p class="mb-2">Your clinic registration is <strong><?= htmlspecialchars($clinicStatus) ?></strong>.</p>
                    <a href="requirements.php" class="btn btn-warning btn-sm">
                        <i class="bi bi-upload me-1"></i> Submit Requirements
                    </a>
                </div>
            </div>
        </div>

        <?php elseif ($isActive): ?>
        <!-- KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-semibold mb-1">Total Patients</h6>
                            <h2 class="fw-bold mb-0"><?= number_format($totalPatients) ?></h2>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stat-card p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-semibold mb-1">Today's Appointments</h6>
                            <h2 class="fw-bold mb-0"><?= $todayAppointments['total'] ?></h2>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stat-card p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-semibold mb-1">Low Stock Alerts</h6>
                            <h2 class="fw-bold mb-0 text-danger"><?= $lowStock ?></h2>
                        </div>
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="stat-card p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-semibold mb-1">Monthly Sales</h6>
                            <h2 class="fw-bold mb-0">₱<?= number_format($monthlySales, 2) ?></h2>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title fw-semibold mb-3">Patient Growth</h5>
                        <div class="chart-container">
                            <canvas id="patientGrowthChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title fw-semibold mb-3">Monthly Sales (₱)</h5>
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity & Alerts -->
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title fw-semibold mb-3">Recent Activity</h5>
                        <?php if (!empty($activities)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach($activities as $act): ?>
                            <div class="list-group-item border-0 px-0 py-2 activity-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="fw-medium"><?= htmlspecialchars($act['action']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($act['module'] ?? 'System') ?></small>
                                    </div>
                                    <small class="text-muted"><?= date('h:i A', strtotime($act['created_at'])) ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-activity text-muted fs-1 mb-3"></i>
                            <p class="text-muted mb-0">No recent activities</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title fw-semibold mb-3">Low Stock Alerts</h5>
                        <?php if (!empty($lowStockItems)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <tbody>
                                    <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?= htmlspecialchars($item['name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($item['type'] ?? 'N/A') ?></small>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-danger"><?= $item['stock'] ?> left</span>
                                        </td>
                                        <td class="text-end">
                                            <a href="inventory.php?action=edit&id=<?= $item['id'] ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                Restock
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle text-success fs-1 mb-3"></i>
                            <p class="text-success mb-0">All inventory items are well-stocked</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="card-title fw-semibold mb-3">Quick Actions</h5>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <a href="patients.php" class="quick-action-btn">
                            <i class="bi bi-person-plus fs-3 d-block mb-2"></i>
                            <span>Add Patient</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="appointments.php" class="quick-action-btn">
                            <i class="bi bi-calendar-plus fs-3 d-block mb-2"></i>
                            <span>New Appointment</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="sales.php" class="quick-action-btn">
                            <i class="bi bi-receipt fs-3 d-block mb-2"></i>
                            <span>Record Sale</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="reports.php" class="quick-action-btn">
                            <i class="bi bi-graph-up fs-3 d-block mb-2"></i>
                            <span>View Analytics</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart Scripts -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Patient Growth Chart
            const patientCtx = document.getElementById('patientGrowthChart');
            if (patientCtx) {
                new Chart(patientCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($labels) ?>,
                        datasets: [{
                            label: 'Patients',
                            data: <?= json_encode($patientGrowth) ?>,
                            borderColor: '#0891b2',
                            backgroundColor: 'rgba(8, 145, 178, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            }
            
            // Sales Chart
            const salesCtx = document.getElementById('salesChart');
            if (salesCtx) {
                new Chart(salesCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($labels) ?>,
                        datasets: [{
                            label: 'Sales',
                            data: <?= json_encode($salesData) ?>,
                            backgroundColor: '#0891b2'
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
        </script>

        <?php endif; ?>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Mobile sidebar toggle -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.querySelector('.sidebar-fixed');
    
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth < 992 && 
                sidebar.classList.contains('show') &&
                !sidebar.contains(event.target) &&
                !event.target.matches('#toggleSidebar')) {
                sidebar.classList.remove('show');
            }
        });
    }
});
</script>

</body>
</html>