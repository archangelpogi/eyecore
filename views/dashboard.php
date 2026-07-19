<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ================== SECURITY CHECK ==================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

// ✅ RBAC Permission Check - MUST HAVE DASHBOARD VIEW PERMISSION
if (!RBACHelper::hasPermission('dashboard_view')) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    </head>
    <body>
        <div class="container-fluid p-5 text-center">
            <div class="alert alert-danger">
                <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                <h3>Access Denied</h3>
                <p>You don't have permission to view the Dashboard.</p>
                <a href="../admin/logout.php" class="btn btn-danger mt-3">Logout</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('dashboard_view');
$canCreate = RBACHelper::hasPermission('dashboard_create');
$canEdit = RBACHelper::hasPermission('dashboard_edit');
$canDelete = RBACHelper::hasPermission('dashboard_delete');
$canApprove = RBACHelper::hasPermission('dashboard_approve');
$canReject = RBACHelper::hasPermission('dashboard_reject');

$clinicId = $_SESSION['clinic_id'];
$clinicName = $_SESSION['clinic_name'] ?? 'My Clinic';
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Check if this ClinicAdmin is also a doctor
$isDoctor = false;
$doctorId = null;
$stmt = $pdo->prepare("SELECT id FROM doctors WHERE clinic_id = ? AND user_id = ? AND is_active = 1");
$stmt->execute([$clinicId, $userId]);
$doctorRow = $stmt->fetch();
if ($doctorRow) {
    $isDoctor = true;
    $doctorId = $doctorRow['id'];
}

/* ================== GET CLINIC STATUS ================== */
$stmt = $pdo->prepare("SELECT status FROM clinics WHERE id = ?");
$stmt->execute([$clinicId]);
$clinicStatus = $stmt->fetchColumn() ?: 'Pending';



/* ================== BUSINESS DASHBOARD DATA ================== */
$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');
$lastDayOfMonth = date('Y-m-t');
$last7Days = date('Y-m-d', strtotime('-7 days'));

// 1. Total Patients
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND status = 'Active'");
$stmt->execute([$clinicId]);
$totalPatients = $stmt->fetchColumn() ?: 0;

// 2. New Patients (This Month)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND DATE(created_at) BETWEEN ? AND ?");
$stmt->execute([$clinicId, $firstDayOfMonth, $lastDayOfMonth]);
$newPatientsMonth = $stmt->fetchColumn() ?: 0;

// 3. Total Revenue (This Month)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?");
$stmt->execute([$clinicId, $firstDayOfMonth, $lastDayOfMonth]);
$monthlyRevenue = $stmt->fetchColumn() ?: 0;

// 4. Revenue Growth
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?");
$stmt->execute([$clinicId, $lastMonthStart, $lastMonthEnd]);
$lastMonthRevenue = $stmt->fetchColumn() ?: 0;
$revenueGrowth = $lastMonthRevenue > 0 ? round(($monthlyRevenue - $lastMonthRevenue) / $lastMonthRevenue * 100, 1) : ($monthlyRevenue > 0 ? 100 : 0);

// 5. Total Appointments (This Month)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND appointment_date BETWEEN ? AND ?");
$stmt->execute([$clinicId, $firstDayOfMonth, $lastDayOfMonth]);
$monthlyAppointments = $stmt->fetchColumn() ?: 0;

// 6. Today's Appointments
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
    FROM appointments
    WHERE clinic_id = ? AND appointment_date = ?
");
$stmt->execute([$clinicId, $today]);
$todayAppointments = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0, 'pending' => 0, 'confirmed' => 0, 'paid' => 0];

// 7. Low Stock Items
$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE clinic_id = ? AND stock <= min_stock AND is_archived = 0");
$stmt->execute([$clinicId]);
$lowStockCount = $stmt->fetchColumn() ?: 0;

// 8. Out of Stock Items
$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE clinic_id = ? AND stock <= 0 AND is_archived = 0");
$stmt->execute([$clinicId]);
$outOfStockCount = $stmt->fetchColumn() ?: 0;

// 9. Pending Collection
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - amount_paid), 0) FROM sales WHERE clinic_id = ? AND status != 'Paid'");
$stmt->execute([$clinicId]);
$pendingCollection = $stmt->fetchColumn() ?: 0;

// 10. Collection Rate
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) as paid, COALESCE(SUM(total_amount), 0) as total FROM sales WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?");
$stmt->execute([$clinicId, $firstDayOfMonth, $lastDayOfMonth]);
$collectionData = $stmt->fetch(PDO::FETCH_ASSOC);
$collectionRate = $collectionData['total'] > 0 ? round($collectionData['paid'] / $collectionData['total'] * 100, 1) : 0;

// 11. Monthly Sales Chart
$labels = [];
$salesData = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthLabel = date('M', strtotime("-$i months"));
    $labels[] = $monthLabel;
    
    $monthStart = date('Y-m-01', strtotime($month));
    $monthEnd = date('Y-m-t', strtotime($month));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?");
    $stmt->execute([$clinicId, $monthStart, $monthEnd]);
    $salesData[] = (float)$stmt->fetchColumn() ?: 0;
}

// 12. Patient Growth Chart
$patientGrowth = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 MONTH)");
    $stmt->execute([$clinicId, $monthStart, $monthStart]);
    $patientGrowth[] = (int)$stmt->fetchColumn() ?: 0;
}

// 13. Top Selling Items
$stmt = $pdo->prepare("
    SELECT 
        si.item_name,
        SUM(si.quantity) as total_sold,
        SUM(si.total_price) as revenue
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
    GROUP BY si.item_name
    ORDER BY total_sold DESC
    LIMIT 5
");
$stmt->execute([$clinicId, $last7Days, $today]);
$topItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 14. Recent Activities
$stmt = $pdo->prepare("
    SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) as user_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.clinic_id = ? 
    ORDER BY al.created_at DESC 
    LIMIT 5
");
$stmt->execute([$clinicId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 15. Upcoming Appointments
$stmt = $pdo->prepare("
    SELECT a.*, 
           CASE 
               WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
               WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
               ELSE a.service_type
           END as patient_name,
           p.phone,
           d.name as doctor_name,
           COALESCE(pr.name, a.service_type, 'Check-up') as service_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN products pr ON a.product_id = pr.id
    WHERE a.clinic_id = ? 
    AND a.appointment_date >= ?
    AND a.status IN ('pending', 'confirmed', 'paid')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 5
");
$stmt->execute([$clinicId, $today]);
$upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 16. Recent Patients
$stmt = $pdo->prepare("
    SELECT id, patient_code, CONCAT(first_name, ' ', last_name) as patient_name, phone, created_at
    FROM patients 
    WHERE clinic_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$clinicId]);
$recentPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================== CLINICAL DECISION SUPPORT DATA ================== */

// 17. Today's Queue (appointments for today)
$stmt = $pdo->prepare("
    SELECT a.*,
           CASE 
               WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
               WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
               ELSE 'Walk-in Patient'
           END as patient_name,
           p.age,
           p.gender,
           p.patient_type,
           d.name as doctor_name,
           COALESCE(pr.name, a.service_type, 'Check-up') as service_name,
           CASE 
               WHEN a.patient_id IS NOT NULL AND p.id IS NOT NULL THEN 0
               WHEN a.patient_id IS NULL AND a.user_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM patients p2 
                   WHERE p2.clinic_id = a.clinic_id 
                   AND p2.email COLLATE utf8mb4_unicode_ci = (
                       SELECT email COLLATE utf8mb4_unicode_ci FROM users WHERE id = a.user_id
                   )
               ) THEN 0
               ELSE 1
           END AS is_new_patient
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN products pr ON a.product_id = pr.id
    WHERE a.clinic_id = ? AND a.appointment_date = ?
    AND a.status IN ('paid', 'confirmed', 'pending')
    ORDER BY a.appointment_time ASC
    LIMIT 8
");
$stmt->execute([$clinicId, $today]);
$todayQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 18. Pending Actions (approvals needed) - Only if user has approve permission
$pendingApprovals = 0;
if ($canApprove) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_approvals
        FROM appointments
        WHERE clinic_id = ? AND status = 'pending'
    ");
    $stmt->execute([$clinicId]);
    $pendingApprovals = $stmt->fetchColumn() ?: 0;
}

// 19. High Risk Patients (based on clinical notes)
$stmt = $pdo->prepare("
    SELECT DISTINCT cn.patient_id,
           CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.age,
           p.gender,
           cn.diagnosis,
           cn.service_date
    FROM clinical_notes cn
    INNER JOIN patients p ON cn.patient_id = p.id
    WHERE cn.clinic_id = ? 
    AND (cn.diagnosis LIKE '%glaucoma%' 
         OR cn.diagnosis LIKE '%cataract%' 
         OR cn.diagnosis LIKE '%diabetic%'
         OR cn.diagnosis LIKE '%keratoconus%'
         OR cn.diagnosis LIKE '%macular%')
    AND p.status = 'Active'
    ORDER BY cn.service_date DESC
    LIMIT 5
");
$stmt->execute([$clinicId]);
$highRiskPatients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 20. Decision Support Stats
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_analyzed,
           SUM(CASE WHEN surgery_recommended = 1 THEN 1 ELSE 0 END) as surgery_recs
    FROM decision_support_logs
    WHERE clinic_id = ?
");
$stmt->execute([$clinicId]);
$decisionStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_analyzed' => 0, 'surgery_recs' => 0];

// 21. Doctor Availability (if attendance table exists)
$doctorsAvailable = [];
if ($isDoctor) {
    $stmt = $pdo->prepare("
        SELECT id, name, specialty
        FROM doctors
        WHERE clinic_id = ? AND is_active = 1
        LIMIT 5
    ");
    $stmt->execute([$clinicId]);
    $doctorsAvailable = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($clinicName); ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --orange: #f97316;
            --orange-light: #fed7aa;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--teal);
        }
        
        .stat-card.orange::before { background: var(--orange); }
        .stat-card.red::before { background: #ef4444; }
        .stat-card.blue::before { background: #3b82f6; }
        .stat-card.purple::before { background: #8b5cf6; }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        
        .stat-trend {
            font-size: 12px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .trend-up { color: #10b981; }
        .trend-down { color: #ef4444; }
        
        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .quick-action {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        
        .quick-action:hover {
            border-color: var(--teal);
            background: #f0fdfa;
            transform: translateY(-2px);
        }
        
        .quick-action-icon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 22px;
            color: var(--teal);
            transition: all 0.2s;
        }
        
        .quick-action:hover .quick-action-icon {
            background: var(--teal-light);
        }
        
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        
        .activity-item:hover {
            background: #f8fafc;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .badge-teal {
            background: #f0fdfa;
            color: var(--teal);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .table-custom th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
        }
        
        .table-custom td {
            padding: 12px 16px;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .table-custom tr:hover {
            background: #f8fafc;
            cursor: pointer;
        }
        
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%);
            border-radius: 24px;
            padding: 1.5rem 2rem;
            color: white;
        }
        
        .card-header-custom {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.25rem;
        }
        
        .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            color: #0f172a;
        }
        
        /* Queue Item Styles */
        .queue-item {
            border: 1px solid #e8edf2;
            border-left: 3px solid transparent;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #fff;
            transition: box-shadow .15s;
        }
        
        .queue-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .queue-item.waiting { border-left-color: #f59e0b; }
        .queue-item.confirmed { border-left-color: #3b82f6; }
        .queue-item.paid { border-left-color: #10b981; }
        
        .queue-time {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }
        
        .queue-name {
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        
        .queue-detail {
            font-size: 11px;
            color: #94a3b8;
        }
        
        .risk-high {
            background: #fef2f2;
            border-left: 3px solid #ef4444;
        }
        
        .risk-medium {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
        }
        
        .doctor-badge {
            background: #f1f5f9;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .pending-badge {
            background: #fef3c7;
            color: #d97706;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Permission Badge */
        .permission-badge {
            position: fixed;
            bottom: 15px;
            right: 15px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 1000;
        }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
    <!-- Welcome Section -->
    <div class="welcome-section mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1 fw-bold">Welcome back, <?= htmlspecialchars($clinicName); ?>!</h4>
                <p class="mb-0 opacity-75">Here's what's happening with your clinic today.</p>
            </div>
            <div class="text-end">
                <div class="badge-teal bg-opacity-20">
                    <i class="bi bi-check-circle-fill me-1"></i> Active Clinic
                </div>
                <div class="small mt-2 opacity-75">
                    <i class="bi bi-calendar3 me-1"></i> <?= date('F j, Y'); ?>
                </div>
                <?php if ($isDoctor): ?>
                <div class="small mt-1">
                    <i class="bi bi-stethoscope me-1"></i> You are also a doctor
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- BUSINESS KPI CARDS Row 1 -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Total Revenue</div>
                        <div class="stat-value">₱<?= number_format($monthlyRevenue, 0); ?></div>
                        <div class="stat-trend <?= $revenueGrowth >= 0 ? 'trend-up' : 'trend-down' ?>">
                            <i class="bi bi-<?= $revenueGrowth >= 0 ? 'arrow-up-short' : 'arrow-down-short' ?>"></i>
                            <?= abs($revenueGrowth); ?>% vs last month
                        </div>
                    </div>
                    <div class="card-icon bg-teal bg-opacity-10" style="color: var(--teal);">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card orange">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Collection Rate</div>
                        <div class="stat-value"><?= $collectionRate; ?>%</div>
                        <div class="stat-trend">
                            <i class="bi bi-credit-card"></i> ₱<?= number_format($pendingCollection, 0); ?> pending
                        </div>
                    </div>
                    <div class="card-icon bg-orange bg-opacity-10" style="color: var(--orange);">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card blue">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Total Patients</div>
                        <div class="stat-value"><?= number_format($totalPatients); ?></div>
                        <div class="stat-trend trend-up">
                            <i class="bi bi-plus-circle"></i> +<?= $newPatientsMonth; ?> new this month
                        </div>
                    </div>
                    <div class="card-icon bg-blue bg-opacity-10" style="color: #3b82f6;">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card red">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Inventory Alerts</div>
                        <div class="stat-value text-danger"><?= $lowStockCount + $outOfStockCount; ?></div>
                        <div class="stat-trend">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?= $lowStockCount; ?> low, <?= $outOfStockCount; ?> out of stock
                        </div>
                    </div>
                    <div class="card-icon bg-danger bg-opacity-10" style="color: #ef4444;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- BUSINESS KPI CARDS Row 2 -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card purple">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Appointments This Month</div>
                        <div class="stat-value"><?= number_format($monthlyAppointments); ?></div>
                        <div class="stat-trend">
                            <i class="bi bi-calendar-check"></i> <?= $todayAppointments['total']; ?> today
                        </div>
                    </div>
                    <div class="card-icon bg-purple bg-opacity-10" style="color: #8b5cf6;">
                        <i class="bi bi-calendar-week"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Today's Status</div>
                        <div class="stat-value">
                            <?= $todayAppointments['completed']; ?>/<?= $todayAppointments['total']; ?>
                        </div>
                        <div class="stat-trend">
                            <span class="text-success"><i class="bi bi-check-circle"></i> <?= $todayAppointments['completed']; ?> completed</span>
                            <span class="text-warning ms-2"><i class="bi bi-clock"></i> <?= $todayAppointments['pending']; ?> pending</span>
                        </div>
                    </div>
                    <div class="card-icon bg-success bg-opacity-10" style="color: #10b981;">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Avg Transaction</div>
                        <div class="stat-value">
                            ₱<?= $monthlyAppointments > 0 ? number_format($monthlyRevenue / max($monthlyAppointments, 1), 0) : 0; ?>
                        </div>
                        <div class="stat-trend">
                            <i class="bi bi-cash-stack"></i> per appointment
                        </div>
                    </div>
                    <div class="card-icon bg-info bg-opacity-10" style="color: #06b6d4;">
                        <i class="bi bi-graph-up"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- BUSINESS CHARTS Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-graph-up me-2" style="color: var(--teal);"></i>Revenue Trend</h6>
                    <span class="badge bg-light text-dark">Last 6 months</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-people me-2" style="color: var(--teal);"></i>Patient Growth</h6>
                    <span class="badge bg-light text-dark">Last 6 months</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="patientChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- QUICK ACTIONS - Only show if user has create permission -->
    <?php if ($canCreate): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header-custom">
            <h6 class="mb-0"><i class="bi bi-lightning-charge me-2" style="color: var(--teal);"></i>Quick Actions</h6>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col">
                    <a href="?view=patients&action=add" class="quick-action">
                        <div class="quick-action-icon"><i class="bi bi-person-plus"></i></div>
                        <div class="small fw-medium">Add Patient</div>
                    </a>
                </div>
                <div class="col">
                    <a href="?view=appointments&action=add" class="quick-action">
                        <div class="quick-action-icon"><i class="bi bi-calendar-plus"></i></div>
                        <div class="small fw-medium">New Appointment</div>
                    </a>
                </div>
                <div class="col">
                    <a href="?view=sales" class="quick-action">
                        <div class="quick-action-icon"><i class="bi bi-receipt"></i></div>
                        <div class="small fw-medium">New Sale</div>
                    </a>
                </div>
                <div class="col">
                    <a href="?view=inventory&action=add" class="quick-action">
                        <div class="quick-action-icon"><i class="bi bi-box-seam"></i></div>
                        <div class="small fw-medium">Add Stock</div>
                    </a>
                </div>
                <div class="col">
                    <a href="?view=reports" class="quick-action">
                        <div class="quick-action-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="small fw-medium">View Reports</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ═══════════════════════════════════════════════════════════════
         CLINICAL DECISION SUPPORT SECTION
    ═══════════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-heart-pulse me-2" style="color: var(--teal);"></i>Clinical Decision Support</h6>
                    <div class="d-flex gap-2">
                        <span class="badge bg-primary"><?= $decisionStats['total_analyzed']; ?> Analyzed</span>
                        <span class="badge bg-danger"><?= $decisionStats['surgery_recs']; ?> Surgery Recs</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Today's Queue -->
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-700 small text-muted"><i class="bi bi-clock-history me-1"></i>Today's Queue</span>
                                <a href="?view=appointments" class="text-teal small">View All</a>
                            </div>
                            <div class="queue-list" style="max-height: 320px; overflow-y: auto;">
                                <?php if (empty($todayQueue)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-calendar-x fs-4"></i>
                                        <p class="small mb-0">No appointments today</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($todayQueue as $q): ?>
                                    <div class="queue-item <?= $q['status']; ?>">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="queue-time"><i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($q['appointment_time'])); ?></span>
                                            <?php if ($q['is_new_patient']): ?>
                                                <span class="badge bg-success" style="font-size: 9px;">New</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="queue-name"><?= htmlspecialchars($q['patient_name'] ?? 'Walk-in'); ?></div>
                                        <div class="queue-detail">
                                            <?= htmlspecialchars($q['service_name']); ?> 
                                            <?php if ($q['doctor_name']): ?> • Dr. <?= htmlspecialchars($q['doctor_name']); ?><?php endif; ?>
                                        </div>
                                        <div class="queue-detail mt-1">
                                            <?php if ($q['age']): ?><?= $q['age']; ?> yrs • <?= $q['gender']; ?><?php endif; ?>
                                        </div>
                                        <?php if ($canApprove && $q['status'] === 'pending'): ?>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-success me-1" onclick="approveAppointment(<?= $q['id']; ?>)">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <?php if ($canReject): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="rejectAppointment(<?= $q['id']; ?>)">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- High Risk Patients -->
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-700 small text-muted"><i class="bi bi-exclamation-triangle me-1 text-danger"></i>High Risk / Urgent Cases</span>
                                <a href="?view=patients&filter=high_risk" class="text-teal small">View All</a>
                            </div>
                            <div class="queue-list" style="max-height: 320px; overflow-y: auto;">
                                <?php if (empty($highRiskPatients)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-shield-check fs-4"></i>
                                        <p class="small mb-0">No high risk patients</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($highRiskPatients as $risk): ?>
                                    <div class="queue-item risk-high">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="queue-name"><?= htmlspecialchars($risk['patient_name']); ?></span>
                                            <span class="badge bg-danger" style="font-size: 9px;"><?= $risk['age']; ?> yrs</span>
                                        </div>
                                        <div class="queue-detail text-danger fw-600">⚠️ <?= htmlspecialchars($risk['diagnosis']); ?></div>
                                        <div class="queue-detail">
                                            <i class="bi bi-calendar me-1"></i>Last: <?= date('M d, Y', strtotime($risk['service_date'])); ?>
                                        </div>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-danger w-100" 
                                                    onclick="viewPatientRisk(<?= $risk['patient_id']; ?>, '<?= addslashes($risk['patient_name']); ?>', '<?= addslashes($risk['diagnosis']); ?>')">
                                                <i class="bi bi-hospital me-1"></i>Refer to Surgery
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Pending Actions & Doctor Status -->
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-700 small text-muted"><i class="bi bi-hourglass-split me-1 text-warning"></i>Pending Actions</span>
                                <a href="?view=appointments" class="text-teal small">Manage</a>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                    <span><i class="bi bi-check-circle text-info me-2"></i>Pending Approvals</span>
                                    <span class="pending-badge"><?= $pendingApprovals; ?> appointment(s)</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center p-2 mt-2 bg-light rounded">
                                    <span><i class="bi bi-credit-card text-warning me-2"></i>Pending Payments</span>
                                    <span class="pending-badge"><?= $todayAppointments['confirmed'] ?? 0; ?> waiting</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center p-2 mt-2 bg-light rounded">
                                    <span><i class="bi bi-box-seam text-danger me-2"></i>Low Stock Items</span>
                                    <span class="pending-badge"><?= $lowStockCount + $outOfStockCount; ?> items</span>
                                </div>
                            </div>
                            
                            <?php if ($isDoctor && !empty($doctorsAvailable)): ?>
                            <div class="mt-3">
                                <span class="fw-700 small text-muted"><i class="bi bi-stethoscope me-1"></i>Doctor Status</span>
                                <div class="mt-2">
                                    <?php foreach ($doctorsAvailable as $doc): ?>
                                    <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                                        <div>
                                            <span class="fw-600">Dr. <?= htmlspecialchars($doc['name']); ?></span>
                                            <div class="small text-muted"><?= htmlspecialchars($doc['specialty'] ?? 'General'); ?></div>
                                        </div>
                                        <span class="badge bg-success">Available</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bottom Sections -->
    <div class="row g-3">
        <!-- Recent Patients - Edit/Delete buttons based on permissions -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2" style="color: var(--teal);"></i>Recent Patients</h6>
                    <div>
                        <?php if ($canCreate): ?>
                        <a href="?view=patients&action=add" class="btn btn-sm btn-link text-teal me-2">Add New</a>
                        <?php endif; ?>
                        <a href="?view=patients" class="btn btn-sm btn-link text-teal">View All</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($recentPatients)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr><th>Code</th><th>Name</th><th>Contact</th><th>Added</th><?php if ($canEdit || $canDelete): ?><th>Actions</th><?php endif; ?></thead>
                                <tbody>
                                    <?php foreach ($recentPatients as $patient): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($patient['patient_code']); ?></code></td>
                                        <td class="fw-medium"><?= htmlspecialchars($patient['patient_name']); ?></td>
                                        <td><?= htmlspecialchars($patient['phone'] ?? 'N/A'); ?></td>
                                        <td class="text-muted small"><?= date('M d', strtotime($patient['created_at'])); ?></td>
                                        <?php if ($canEdit || $canDelete): ?>
                                        <td>
                                            <?php if ($canEdit): ?>
                                            <a href="?view=patients&action=edit&id=<?= $patient['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deletePatient(<?= $patient['id']; ?>, '<?= addslashes($patient['patient_name']); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                              </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-people fs-1"></i>
                            <p class="mt-2 mb-0">No patients yet</p>
                            <?php if ($canCreate): ?>
                            <a href="?view=patients&action=add" class="btn btn-sm btn-teal mt-2">Add First Patient</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Top Selling Items -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-trophy me-2" style="color: var(--orange);"></i>Top Selling Items</h6>
                    <a href="?view=reports&tab=inventory" class="btn btn-sm btn-link text-teal">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($topItems)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr><th>Item</th><th>Sold</th><th>Revenue</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topItems as $item): ?>
                                    <tr>
                                        <td class="fw-medium"><?= htmlspecialchars($item['item_name']); ?></td>
                                        <td><?= $item['total_sold']; ?> units</td>
                                        <td class="text-success fw-semibold">₱<?= number_format($item['revenue'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-box-seam fs-1"></i>
                            <p class="mt-2 mb-0">No sales data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Layout for Upcoming Appointments and Recent Activity -->
    <div class="row g-3 mt-3">
        <!-- Upcoming Appointments - Edit/Approve/Reject buttons based on permissions -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-calendar-event me-2" style="color: var(--teal);"></i>Upcoming Appointments</h6>
                    <div>
                        <?php if ($canCreate): ?>
                        <a href="?view=appointments&action=add" class="btn btn-sm btn-link text-teal me-2">Schedule</a>
                        <?php endif; ?>
                        <a href="?view=appointments" class="btn btn-sm btn-link text-teal">View Calendar</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($upcomingAppointments)): ?>
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Patient</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th>Service</th>
                                        <?php if ($canEdit || $canApprove || $canReject): ?><th>Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingAppointments as $appt): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?= date('M d', strtotime($appt['appointment_date'])); ?></div>
                                            <div class="small text-muted"><?= date('h:i A', strtotime($appt['appointment_time'])); ?></div>
                                        </td>
                                        <td class="fw-medium"><?= htmlspecialchars($appt['patient_name'] ?? 'N/A'); ?></td>
                                        <td><?= htmlspecialchars($appt['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge bg-<?= $appt['status'] == 'confirmed' ? 'success' : ($appt['status'] == 'pending' ? 'warning' : 'secondary'); ?>">
                                                <?= ucfirst($appt['status']); ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($appt['service_name'] ?? 'Check-up'); ?></td>
                                        <?php if ($canEdit || $canApprove || $canReject): ?>
                                        <td>
                                            <?php if ($canEdit): ?>
                                            <a href="?view=appointments&action=edit&id=<?= $appt['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($canApprove && $appt['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-outline-success me-1" onclick="approveAppointment(<?= $appt['id']; ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($canReject && $appt['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-outline-danger" onclick="rejectAppointment(<?= $appt['id']; ?>)">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-calendar-x fs-1"></i>
                            <p class="mt-2 mb-0">No upcoming appointments</p>
                            <?php if ($canCreate): ?>
                            <a href="?view=appointments&action=add" class="btn btn-sm btn-teal mt-2">Schedule Appointment</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-activity me-2" style="color: var(--teal);"></i>Recent Activity</h6>
                    <a href="?view=logs" class="btn btn-sm btn-link text-teal">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($activities)): ?>
                        <?php foreach ($activities as $act): ?>
                        <div class="activity-item px-3">
                            <div class="d-flex gap-3">
                                <div class="activity-icon bg-<?= 
                                    strpos(strtolower($act['action']), 'create') !== false ? 'success' : 
                                    (strpos(strtolower($act['action']), 'update') !== false ? 'info' : 
                                    (strpos(strtolower($act['action']), 'delete') !== false ? 'danger' : 'secondary')) 
                                ?> bg-opacity-10" style="color: <?= 
                                    strpos(strtolower($act['action']), 'create') !== false ? '#10b981' : 
                                    (strpos(strtolower($act['action']), 'update') !== false ? '#06b6d4' : 
                                    (strpos(strtolower($act['action']), 'delete') !== false ? '#ef4444' : '#64748b')) 
                                ?>;">
                                    <i class="bi bi-<?= 
                                        strpos(strtolower($act['action']), 'create') !== false ? 'plus-circle' : 
                                        (strpos(strtolower($act['action']), 'update') !== false ? 'arrow-repeat' : 
                                        (strpos(strtolower($act['action']), 'delete') !== false ? 'trash' : 'info-circle')) 
                                    ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-medium small"><?= htmlspecialchars($act['action']); ?></span>
                                        <span class="text-muted small"><?= date('h:i A', strtotime($act['created_at'])); ?></span>
                                    </div>
                                    <div class="small text-muted"><?= $act['user_name'] ?? 'System'; ?> • <?= $act['table_name'] ?? 'N/A'; ?></div>
                                    <?php if (!empty($act['details'])): ?>
                                        <div class="text-muted small mt-1"><?= substr(htmlspecialchars($act['details']), 0, 60); ?>...</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-activity fs-1"></i>
                            <p class="mt-2 mb-0">No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
// ✅ Pass permissions to JavaScript
const dashboardPermissions = {
    canView: <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit: <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};

console.log('Dashboard Permissions:', dashboardPermissions);

// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($labels); ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?= json_encode($salesData); ?>,
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13, 148, 136, 0.05)',
            fill: true,
            tension: 0.3,
            pointBackgroundColor: '#0d9488',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '₱' + context.raw.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        if(value >= 1000000) return '₱' + (value/1000000).toFixed(1) + 'M';
                        if(value >= 1000) return '₱' + (value/1000).toFixed(0) + 'K';
                        return '₱' + value;
                    }
                }
            }
        }
    }
});

// Patient Growth Chart
const patientCtx = document.getElementById('patientChart').getContext('2d');
new Chart(patientCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels); ?>,
        datasets: [{
            label: 'New Patients',
            data: <?= json_encode($patientGrowth); ?>,
            backgroundColor: '#0d9488',
            borderRadius: 8,
            barPercentage: 0.6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});

// ✅ Approve Appointment Function (requires approve permission)
function approveAppointment(appointmentId) {
    if (!dashboardPermissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve appointments.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Approve Appointment?',
        text: 'This will confirm the appointment.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check-lg"></i> Approve',
        confirmButtonColor: '#10b981'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/appointments.php?action=approve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: appointmentId })
            })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Approved!', 'Appointment has been approved.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', data.message || 'Failed to approve appointment', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'Connection error. Please try again.', 'error');
            });
        }
    });
}

// ✅ Reject Appointment Function (requires reject permission)
function rejectAppointment(appointmentId) {
    if (!dashboardPermissions.canReject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject appointments.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Reject Appointment?',
        text: 'This will cancel the appointment.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-x-lg"></i> Reject',
        confirmButtonColor: '#ef4444'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/appointments.php?action=reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: appointmentId })
            })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Rejected!', 'Appointment has been rejected.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', data.message || 'Failed to reject appointment', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'Connection error. Please try again.', 'error');
            });
        }
    });
}

// ✅ Delete Patient Function (requires delete permission)
function deletePatient(patientId, patientName) {
    if (!dashboardPermissions.canDelete) {
        Swal.fire('Access Denied', 'You don\'t have permission to delete patients.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Delete Patient?',
        html: `Are you sure you want to delete <strong>${patientName}</strong>?<br><small class="text-danger">This action cannot be undone.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash"></i> Delete',
        confirmButtonColor: '#ef4444'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/patients.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: patientId })
            })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire('Deleted!', 'Patient has been deleted.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete patient', 'error');
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire('Error', 'Connection error. Please try again.', 'error');
            });
        }
    });
}

function viewPatientRisk(patientId, patientName, diagnosis) {
    Swal.fire({
        title: 'Patient: ' + patientName,
        html: `
            <div class="text-start">
                <p><strong>Diagnosis:</strong> ${diagnosis}</p>
                <p><strong>Recommendation:</strong> This patient may need surgical evaluation.</p>
                <hr>
                <p class="text-muted small">Would you like to refer this patient to a surgery center?</p>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-hospital me-1"></i> Find Surgery Clinics',
        cancelButtonText: 'Close'
    }).then(result => {
        if (result.isConfirmed) {
            showSurgeryClinicsModal(patientId, patientName, diagnosis);
        }
    });
}

function showSurgeryClinicsModal(patientId, patientName, diagnosis) {
    window.currentReferPatient = {
        id: patientId,
        name: patientName,
        diagnosis: diagnosis
    };
    
    Swal.fire({
        title: 'Loading Surgery Clinics...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false
    });
    
    fetch('api/doctor_dashboard.php?action=get_surgery_clinics')
        .then(res => res.json())
        .then(data => {
            Swal.close();
            
            if (data.success && data.clinics && data.clinics.length > 0) {
                let clinicsHtml = '<div style="max-height: 400px; overflow-y: auto;">';
                data.clinics.forEach(clinic => {
                    clinicsHtml += `
                        <div class="border rounded p-3 mb-2" style="border-left: 3px solid #0d9488;">
                            <div class="fw-bold">${clinic.name}</div>
                            <div class="text-muted small">${clinic.city || ''} ${clinic.province || ''}</div>
                            <div class="text-muted small">${clinic.contact || clinic.phone || 'No contact'}</div>
                            <div class="mt-2">
                                <button class="btn btn-sm btn-teal" onclick="sendReferral(${clinic.id}, '${clinic.name.replace(/'/g, "\\'")}', '${clinic.clinic_email || ''}')">
                                    <i class="bi bi-send me-1"></i> Send Referral
                                </button>
                            </div>
                        </div>
                    `;
                });
                clinicsHtml += '</div>';
                
                Swal.fire({
                    title: 'Eye Surgery Centers',
                    html: clinicsHtml,
                    width: '500px',
                    showConfirmButton: false,
                    showCloseButton: true
                });
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'No Surgery Clinics Found',
                    text: 'No registered eye surgery clinics found on the platform.',
                    confirmButtonText: 'OK'
                });
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load surgery clinics. Please try again.',
                confirmButtonText: 'OK'
            });
        });
}

function sendReferral(clinicId, clinicName, clinicEmail) {
    const patient = window.currentReferPatient;
    if (!patient) return;
    
    Swal.fire({
        title: 'Send Referral?',
        html: `Refer <strong>${patient.name}</strong> to<br><strong>${clinicName}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-send me-1"></i> Send',
        confirmButtonColor: '#0d9488'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Sending referral...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/doctor_dashboard.php?action=send_surgery_referral', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    clinic_id: clinicId,
                    clinic_name: clinicName,
                    clinic_email: clinicEmail,
                    patient_id: patient.id,
                    patient_name: patient.name,
                    diagnosis: patient.diagnosis,
                    referring_doctor: 'Clinic Admin'
                })
            })
            .then(res => res.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Referral Sent!',
                        text: `${clinicName} has been notified.`,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to send referral'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Connection error. Please try again.'
                });
            });
        }
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>