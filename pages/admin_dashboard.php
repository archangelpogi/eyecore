<?php

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($clinicName) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
    /* ================== CUSTOM DASHBOARD STYLES ================== */
    body {
        background-color: #f8f9fa;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .page-header {
        padding: 1.5rem 0;
        border-bottom: 1px solid #e9ecef;
        margin-bottom: 2rem;
        background: white;
        padding: 1.5rem;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .page-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
    }
    
    .page-subtitle {
        color: #6c757d;
        margin: 0.5rem 0 0;
        font-size: 1rem;
    }
    
    /* ================== STAT CARD STYLES ================== */
    .stat-card {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        height: 100%;
    }
    
    .stat-card:hover {
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        transform: translateY(-3px);
    }
    
    .stat-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.2;
        color: #2c3e50;
        margin: 0.5rem 0;
    }
    
    .card-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
    }
    
    /* ================== CARD STYLES ================== */
    .card {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        height: 100%;
        margin-bottom: 1rem;
    }
    
    .card-header {
        background: white;
        border-bottom: 1px solid #e9ecef;
        padding: 1.25rem 1.5rem;
        border-radius: 12px 12px 0 0 !important;
    }
    
    .card-header h5 {
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
        font-size: 1.2rem;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    /* ================== CHART CONTAINER ================== */
    .chart-container {
        position: relative;
        height: 280px;
        width: 100%;
    }
    
    /* ================== ACTIVITY ITEMS ================== */
    .activity-item {
        padding: 1rem;
        border-radius: 10px;
        transition: background-color 0.2s ease;
        border-left: 4px solid #0891b2;
    }
    
    .activity-item:hover {
        background-color: #f8f9fa;
    }
    
    .activity-item:not(:last-child) {
        margin-bottom: 1rem;
    }
    
    /* ================== BUTTON STYLES ================== */
    .btn {
        border-radius: 8px;
        padding: 0.75rem 1.25rem;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
        border: none;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #0e7490 0%, #155e75 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(8, 145, 178, 0.3);
    }
    
    .btn-outline-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    /* ================== LOW STOCK ITEMS ================== */
    .border-bottom {
        border-bottom: 1px solid #e9ecef !important;
    }
    
    .badge {
        font-size: 0.85rem;
        padding: 0.35em 0.65em;
        border-radius: 20px;
    }
    
    /* ================== RESPONSIVE ADJUSTMENTS ================== */
    @media (max-width: 768px) {
        .stat-value {
            font-size: 1.8rem;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            font-size: 1.5rem;
        }
        
        .page-title {
            font-size: 1.5rem;
        }
    }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    
    <div class="page-header d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">
                Welcome, <strong><?= htmlspecialchars($clinicName) ?></strong>
            </p>
        </div>
        <?php if ($isActive): ?>
        <a href="reports.php" class="btn btn-primary">
            <i class="bi bi-activity me-2"></i>View Reports
        </a>
        <?php endif; ?>
    </div>

    <?php if ($isSuspended): ?>
    <!-- ================== SUSPENDED ================== -->
    <div class="alert alert-danger d-flex align-items-center">
        <i class="bi bi-shield-exclamation fs-3 me-3"></i>
        <div>
            <h5 class="mb-1">Account Suspended</h5>
            <p class="mb-0">
                Your clinic account has been <strong>suspended</strong>.<br>
                Please contact the <strong>Super Administrator</strong> to resolve this issue.
            </p>
        </div>
    </div>

    <?php elseif ($isRejected): ?>
    <!-- ================== PENDING / REJECTED ================== -->
    <div class="alert alert-warning d-flex align-items-center">
        <i class="bi bi-info-circle fs-3 me-3"></i>
        <div>
            <h5 class="mb-1">Clinic Not Approved</h5>
            <p class="mb-2">
                Your clinic registration is <strong><?= htmlspecialchars($clinicStatus) ?></strong>.
                Please submit the missing requirements to enable your dashboard.
            </p>
            <a href="requirements.php" class="btn btn-warning">
                <i class="bi bi-upload me-1"></i> Submit Missing Requirements
            </a>
        </div>
    </div>

    <?php elseif ($isActive): ?>
    <!-- ================== APPROVED DASHBOARD ================== -->

    <!-- KPI CARDS -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Total Patients</div>
                        <div class="stat-value"><?= number_format($totalPatients) ?></div>
                    </div>
                    <div class="card-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Today's Appointments</div>
                        <div class="stat-value"><?= $todayAppointments['total'] ?></div>
                        <div class="text-muted small">
                            <?= $todayAppointments['completed'] ?> completed, <?= $todayAppointments['pending'] ?> pending
                        </div>
                    </div>
                    <div class="card-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Low Stock Alerts</div>
                        <div class="stat-value text-danger"><?= $lowStock ?></div>
                        <div class="text-muted small">Items need restocking</div>
                    </div>
                    <div class="card-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Monthly Sales</div>
                        <div class="stat-value">₱<?= number_format($monthlySales, 2) ?></div>
                    </div>
                    <div class="card-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Patient Growth</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="patientGrowthChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Monthly Sales (₱)</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity and Alerts -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Recent Activity</h5></div>
                <div class="card-body">
                    <?php if (!empty($activities)): ?>
                        <?php foreach($activities as $act): ?>
                        <div class="activity-item">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-medium"><?= htmlspecialchars($act['action']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($act['module'] ?? 'System') ?></div>
                                </div>
                                <span class="text-muted small"><?= date('h:i A', strtotime($act['created_at'])) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-info-circle me-2"></i> No recent activities
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Low Stock Alerts</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($lowStockItems)): ?>
                        <?php foreach ($lowStockItems as $item): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <div>
                                <div class="fw-medium"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($item['type'] ?? 'No Type') ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-danger"><?= $item['stock'] ?> left</span>
                                <a href="inventory.php?action=edit&id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">Restock</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-success py-4">
                            <i class="bi bi-check-circle me-2"></i> All inventory items are well-stocked
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Quick Actions</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="patients.php" class="btn btn-primary w-100 py-3">
                        <i class="bi bi-person-plus me-2"></i>Add Patient
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="appointments.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-calendar-plus me-2"></i>New Appointment
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="sales.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-graph-up me-2"></i>Record Sale
                    </a>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="reports.php" class="btn btn-outline-primary w-100 py-3">
                        <i class="bi bi-activity me-2"></i>View Analytics
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for Charts -->
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
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#0891b2',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { 
                            display: false 
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            padding: 10,
                            cornerRadius: 8
                        }
                    }, 
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    } 
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
                        backgroundColor: '#0891b2',
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { 
                            display: false 
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₱' + context.parsed.y.toLocaleString();
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            padding: 10,
                            cornerRadius: 8
                        }
                    }, 
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            },
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    } 
                }
            });
        }
    });
    </script>

    <?php endif; ?>
</div>

<!-- Bootstrap JS (optional, for dropdowns, modals, etc.) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>