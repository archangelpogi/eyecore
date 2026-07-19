<?php

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get real data from database for insights
try {
    // Calculate revenue growth (last month vs previous month)
    $revenueSql = "SELECT 
        SUM(CASE WHEN MONTH(invoice_date) = MONTH(CURDATE()) THEN total ELSE 0 END) as current_month,
        SUM(CASE WHEN MONTH(invoice_date) = MONTH(CURDATE() - INTERVAL 1 MONTH) THEN total ELSE 0 END) as last_month
        FROM invoices";
    $revenueStmt = $pdo->query($revenueSql);
    $revenueData = $revenueStmt->fetch();
    
    $currentMonthRevenue = $revenueData['current_month'] ?? 0;
    $lastMonthRevenue = $revenueData['last_month'] ?? 1; // Avoid division by zero
    
    $revenueGrowth = $lastMonthRevenue > 0 ? 
        round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1) : 0;
    
    // Get patient retention (patients with multiple appointments)
    $retentionSql = "SELECT 
        COUNT(DISTINCT p.id) as total_patients,
        COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN p.id END) as returning_patients
        FROM patients p
        LEFT JOIN appointments a ON p.id = a.patient_id
        WHERE p.status = 'Active'";
    $retentionStmt = $pdo->query($retentionSql);
    $retentionData = $retentionStmt->fetch();
    
    $totalPatients = $retentionData['total_patients'] ?? 1;
    $returningPatients = $retentionData['returning_patients'] ?? 0;
    $patientRetention = $totalPatients > 0 ? round(($returningPatients / $totalPatients) * 100, 1) : 0;
    
    // Get appointment no-show rate
    $appointmentSql = "SELECT 
        COUNT(*) as total_appointments,
        SUM(CASE WHEN status = 'No-show' THEN 1 ELSE 0 END) as no_shows
        FROM appointments
        WHERE MONTH(appointment_date) = MONTH(CURDATE())";
    $appointmentStmt = $pdo->query($appointmentSql);
    $appointmentData = $appointmentStmt->fetch();
    
    $totalAppointments = $appointmentData['total_appointments'] ?? 1;
    $noShows = $appointmentData['no_shows'] ?? 0;
    $noShowRate = $totalAppointments > 0 ? round(($noShows / $totalAppointments) * 100, 1) : 0;
    
    // Get average revenue per patient
    $avgRevenueSql = "SELECT 
        COUNT(DISTINCT patient_id) as total_patients,
        SUM(total) as total_revenue
        FROM invoices
        WHERE MONTH(invoice_date) = MONTH(CURDATE())";
    $avgRevenueStmt = $pdo->query($avgRevenueSql);
    $avgRevenueData = $avgRevenueStmt->fetch();
    
    $totalPatientsMonth = $avgRevenueData['total_patients'] ?? 1;
    $totalRevenueMonth = $avgRevenueData['total_revenue'] ?? 0;
    $avgRevenuePerPatient = $totalPatientsMonth > 0 ? round($totalRevenueMonth / $totalPatientsMonth, 2) : 0;
    
    // Check for low inventory items
    $inventoryAlertsSql = "SELECT i.*, c.clinic_name 
        FROM inventory i 
        LEFT JOIN clinics c ON i.clinic_id = c.id 
        WHERE (i.stock = 0 OR i.stock <= i.reorder_level)
        AND i.item_status IN ('out-of-stock', 'low-stock')
        LIMIT 5";
    $inventoryAlerts = $pdo->query($inventoryAlertsSql)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get clinics with high no-show rates
    $clinicPerformanceSql = "SELECT 
        c.clinic_name,
        COUNT(a.id) as total_appointments,
        SUM(CASE WHEN a.status = 'No-show' THEN 1 ELSE 0 END) as no_shows,
        ROUND(SUM(CASE WHEN a.status = 'No-show' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.id), 1) as no_show_rate
        FROM appointments a
        LEFT JOIN clinics c ON a.clinic_id = c.id
        WHERE MONTH(a.appointment_date) = MONTH(CURDATE())
        GROUP BY c.clinic_name
        HAVING COUNT(a.id) > 0";
    $clinicPerformance = $pdo->query($clinicPerformanceSql)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get predicted revenue (simplified calculation based on last month)
    $predictedRevenueSql = "SELECT 
        c.clinic_name,
        SUM(i.total) as last_month_revenue,
        ROUND(SUM(i.total) * 1.1, 0) as predicted_revenue
        FROM invoices i
        LEFT JOIN clinics c ON i.clinic_id = c.id
        WHERE MONTH(i.invoice_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
        GROUP BY c.clinic_name";
    $predictedRevenue = $pdo->query($predictedRevenueSql)->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching decision support data: " . $e->getMessage());
    // Set default values on error
    $revenueGrowth = 0;
    $patientRetention = 0;
    $noShowRate = 0;
    $avgRevenuePerPatient = 0;
    $inventoryAlerts = [];
    $clinicPerformance = [];
    $predictedRevenue = [];
}

// AI Recommendations (can be extended with real data)
$recommendations = [
    [
        'id' => 1,
        'title' => 'Inventory Restocking Alert',
        'description' => count($inventoryAlerts) > 0 ? 
            count($inventoryAlerts) . ' items are low or out of stock across clinics.' : 
            'Inventory levels are optimal.',
        'priority' => count($inventoryAlerts) > 2 ? 'high' : (count($inventoryAlerts) > 0 ? 'medium' : 'low'),
        'clinic' => 'Multiple Clinics',
        'impact' => count($inventoryAlerts) > 0 ? 'Potential loss of sales' : 'Optimal inventory',
        'action' => count($inventoryAlerts) > 0 ? 'Review and restock items' : 'Maintain current levels',
    ],
    [
        'id' => 2,
        'title' => 'Appointment No-show Reduction',
        'description' => $noShowRate > 10 ? 
            "No-show rate is high at " . $noShowRate . "%. Consider implementing reminder system." : 
            "No-show rate is optimal at " . $noShowRate . "%.",
        'priority' => $noShowRate > 15 ? 'high' : ($noShowRate > 10 ? 'medium' : 'low'),
        'clinic' => 'All Clinics',
        'impact' => $noShowRate > 10 ? 'Revenue loss from missed appointments' : 'Efficient scheduling',
        'action' => $noShowRate > 10 ? 'Implement SMS/email reminders' : 'Continue current practices',
    ],
    [
        'id' => 3,
        'title' => 'Patient Follow-up Optimization',
        'description' => $patientRetention < 80 ? 
            "Patient retention rate is " . $patientRetention . "%. Industry average is 75%." : 
            "Excellent patient retention at " . $patientRetention . "%.",
        'priority' => $patientRetention < 70 ? 'high' : ($patientRetention < 80 ? 'medium' : 'low'),
        'clinic' => 'All Clinics',
        'impact' => $patientRetention < 80 ? 'Opportunity for improved retention' : 'Strong patient loyalty',
        'action' => $patientRetention < 80 ? 'Enhance follow-up program' : 'Maintain current strategy',
    ],
];

// Performance Alerts
$performanceAlerts = [];
foreach ($clinicPerformance as $clinic) {
    if (($clinic['no_show_rate'] ?? 0) > 15) {
        $performanceAlerts[] = [
            'type' => 'warning',
            'title' => 'Needs Attention',
            'message' => $clinic['clinic_name'] . ' has high cancellation rate at ' . ($clinic['no_show_rate'] ?? 0) . '%',
            'icon' => 'alert-circle'
        ];
    }
}

// Add success alerts for good performance
if ($revenueGrowth > 15) {
    $performanceAlerts[] = [
        'type' => 'success',
        'title' => 'Excellent Performance',
        'message' => 'Revenue growth is strong at +' . $revenueGrowth . '%',
        'icon' => 'check-circle'
    ];
}

if ($patientRetention > 85) {
    $performanceAlerts[] = [
        'type' => 'info',
        'title' => 'Opportunity',
        'message' => 'High patient retention rate suggests loyalty program opportunity',
        'icon' => 'lightbulb'
    ];
}

// Ensure we have at least 3 alerts
while (count($performanceAlerts) < 3) {
    $performanceAlerts[] = [
        'type' => count($performanceAlerts) % 3 == 0 ? 'success' : (count($performanceAlerts) % 3 == 1 ? 'warning' : 'info'),
        'title' => ['Excellent Performance', 'Needs Attention', 'Opportunity'][count($performanceAlerts) % 3],
        'message' => ['All clinics meeting performance targets', 'Monitor appointment schedules', 'Consider expanding service offerings'][count($performanceAlerts) % 3],
        'icon' => ['check-circle', 'alert-circle', 'lightbulb'][count($performanceAlerts) % 3]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Decision Support System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
    body{background:#f8fafc;font-family:'Segoe UI',sans-serif}
    .card-soft{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
    .icon-box{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center}
    .gradient-btn{background:linear-gradient(to right,#0d9488,#3b82f6);color:#fff;border:none;border-radius:8px;padding:10px 24px}
    .gradient-btn:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(13,148,136,0.2)}
    .badge-high{background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:20px}
    .badge-medium{background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:20px}
    .badge-low{background:#dbeafe;color:#1e40af;padding:6px 12px;border-radius:20px}
    .progress-bar-teal{background:#0d9488}
    .progress-bar-blue{background:#3b82f6}
    .progress-bar-green{background:#10b981}
    .alert-success{background:#dcfce7;border-color:#86efac}
    .alert-warning{background:#fef3c7;border-color:#fde68a}
    .alert-info{background:#dbeafe;border-color:#93c5fd}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="mb-4">
        <h2 class="fw-bold">Decision Support System</h2>
        <p class="text-muted mt-1">AI-powered recommendations and business insights</p>
    </div>

    <!-- KEY INSIGHTS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between mb-2">
                    <p class="text-sm text-muted">Revenue Growth</p>
                    <i data-lucide="trending-up" class="<?= $revenueGrowth >= 0 ? 'text-success' : 'text-danger rotate-180' ?>" style="width:16px;height:16px"></i>
                </div>
                <p class="h4 fw-bold"><?= $revenueGrowth >= 0 ? '+' : '' ?><?= $revenueGrowth ?>%</p>
                <p class="text-sm text-muted mt-1">vs last month</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between mb-2">
                    <p class="text-sm text-muted">Patient Retention</p>
                    <i data-lucide="trending-up" class="<?= $patientRetention >= 75 ? 'text-success' : 'text-danger rotate-180' ?>" style="width:16px;height:16px"></i>
                </div>
                <p class="h4 fw-bold"><?= $patientRetention ?>%</p>
                <p class="text-sm text-muted mt-1">Industry avg: 75%</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between mb-2">
                    <p class="text-sm text-muted">Appointment No-show</p>
                    <i data-lucide="trending-up" class="<?= $noShowRate <= 10 ? 'text-success rotate-180' : 'text-danger' ?>" style="width:16px;height:16px"></i>
                </div>
                <p class="h4 fw-bold"><?= $noShowRate ?>%</p>
                <p class="text-sm text-muted mt-1">vs target: <10%</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4">
                <div class="d-flex justify-content-between mb-2">
                    <p class="text-sm text-muted">Avg. Revenue/Patient</p>
                    <i data-lucide="trending-up" class="text-success" style="width:16px;height:16px"></i>
                </div>
                <p class="h4 fw-bold">₱<?= number_format($avgRevenuePerPatient, 2) ?></p>
                <p class="text-sm text-muted mt-1">Monthly average</p>
            </div>
        </div>
    </div>

    <!-- AI RECOMMENDATIONS -->
    <div class="card-soft p-4 mb-4">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="icon-box bg-gradient" style="background:linear-gradient(to right,#0d9488,#3b82f6)">
                <i data-lucide="brain" class="text-white" style="width:24px;height:24px"></i>
            </div>
            <div>
                <h3 class="h5 fw-semibold">AI Recommendations</h3>
                <p class="text-sm text-muted">Actionable insights for your business</p>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($recommendations as $rec): ?>
            <div class="p-4 rounded border-start <?= 
                $rec['priority'] == 'high' ? 'border-danger bg-danger bg-opacity-10' : 
                ($rec['priority'] == 'medium' ? 'border-warning bg-warning bg-opacity-10' : 
                'border-primary bg-primary bg-opacity-10') ?>">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i data-lucide="lightbulb" class="text-teal" style="width:20px;height:20px"></i>
                            <h4 class="fw-semibold mb-0"><?= $rec['title'] ?></h4>
                            <span class="badge <?= 
                                $rec['priority'] == 'high' ? 'badge-high' : 
                                ($rec['priority'] == 'medium' ? 'badge-medium' : 'badge-low') ?>">
                                <?= ucfirst($rec['priority']) ?> Priority
                            </span>
                        </div>
                        <p class="text-sm mb-3"><?= $rec['description'] ?></p>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2 text-sm">
                                    <i data-lucide="building-2" class="text-muted" style="width:16px;height:16px"></i>
                                    <span class="text-muted"><?= $rec['clinic'] ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2 text-sm">
                                    <i data-lucide="alert-circle" class="text-muted" style="width:16px;height:16px"></i>
                                    <span class="text-muted"><?= $rec['impact'] ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2 text-sm">
                                    <i data-lucide="check-circle" class="text-muted" style="width:16px;height:16px"></i>
                                    <span class="text-muted"><?= $rec['action'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-center justify-content-end gap-2 mt-3 mt-md-0">
                        <button class="btn btn-primary btn-sm" onclick="takeAction(<?= $rec['id'] ?>)">
                            Take Action
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="dismissRecommendation(<?= $rec['id'] ?>)">
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PREDICTIVE ANALYTICS -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h3 class="fw-semibold mb-4">Predicted Revenue (Next 30 Days)</h3>
                <div class="space-y-3">
                    <?php 
                    $maxRevenue = 0;
                    foreach ($predictedRevenue as $pred): 
                        if ($pred['predicted_revenue'] > $maxRevenue) $maxRevenue = $pred['predicted_revenue'];
                    endforeach; 
                    
                    $colors = ['progress-bar-teal', 'progress-bar-blue', 'progress-bar-green'];
                    $colorIndex = 0;
                    
                    foreach ($predictedRevenue as $pred): 
                        $percentage = $maxRevenue > 0 ? ($pred['predicted_revenue'] / $maxRevenue * 100) : 0;
                    ?>
                    <div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-sm text-muted"><?= $pred['clinic_name'] ?? 'Clinic' ?></span>
                            <span class="fw-semibold">₱<?= number_format($pred['predicted_revenue'] ?? 0) ?></span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar <?= $colors[$colorIndex % 3] ?>" 
                                 style="width:<?= $percentage ?>%"></div>
                        </div>
                    </div>
                    <?php $colorIndex++; endforeach; 
                    
                    // Fallback if no data
                    if (empty($predictedRevenue)): ?>
                    <div class="text-center py-4">
                        <i data-lucide="bar-chart" class="text-muted mb-3" style="width:48px;height:48px"></i>
                        <p class="text-muted">No revenue data available for prediction</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card-soft p-4 h-100">
                <h3 class="fw-semibold mb-4">Performance Alerts</h3>
                <div class="space-y-3">
                    <?php foreach ($performanceAlerts as $alert): ?>
                    <div class="d-flex align-items-start gap-3 p-3 rounded alert-<?= $alert['type'] ?>">
                        <i data-lucide="<?= $alert['icon'] ?>" class="text-<?= 
                            $alert['type'] == 'success' ? 'success' : 
                            ($alert['type'] == 'warning' ? 'warning' : 'info') ?> mt-1"></i>
                        <div>
                            <p class="fw-medium text-<?= 
                                $alert['type'] == 'success' ? 'success' : 
                                ($alert['type'] == 'warning' ? 'warning' : 'info') ?> mb-1">
                                <?= $alert['title'] ?>
                            </p>
                            <p class="text-sm text-<?= 
                                $alert['type'] == 'success' ? 'success' : 
                                ($alert['type'] == 'warning' ? 'warning' : 'info') ?> opacity-75 mb-0">
                                <?= $alert['message'] ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showToast(msg,icon='success'){
    Swal.fire({toast:true,position:'top-end',icon,title:msg,showConfirmButton:false,timer:3000})
}

function takeAction(recommendationId){
    showToast('Action taken for recommendation #' + recommendationId, 'success');
    // In a real application, you would make an API call here
}

function dismissRecommendation(recommendationId){
    Swal.fire({
        title: 'Dismiss Recommendation?',
        text: 'This will remove this recommendation from your view.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9488',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, dismiss it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            showToast('Recommendation dismissed', 'info');
            // In a real application, you would make an API call to mark as dismissed
            setTimeout(() => {
                location.reload();
            }, 1500);
        }
    });
}

// Initialize icons
lucide.createIcons();
</script>
</body>
</html>