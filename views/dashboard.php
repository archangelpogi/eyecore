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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0d9488">
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
        
        * { -webkit-tap-highlight-color: transparent; }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            padding-bottom: env(safe-area-inset-bottom);
            overflow-x: hidden;
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
            flex-wrap: wrap;
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
            flex-shrink: 0;
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
            flex-shrink: 0;
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
            white-space: nowrap;
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

        /* ============================================================
           📱 MOBILE RESPONSIVE — COMPLETE FIX
           ============================================================ */

        .row > [class*="col-"] { min-width: 0; }

        @media (max-width: 767.98px) {

            /* ---------- GLOBAL ---------- */
            .container-fluid.p-4 { padding: 0.5rem !important; }
            body { font-size: 13px; }

            /* ---------- WELCOME BANNER ---------- */
            .welcome-section {
                border-radius: 12px;
                padding: 0.8rem 0.9rem;
            }
            .welcome-section > .d-flex {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.4rem;
            }
            .welcome-section h4 { font-size: 0.95rem; line-height: 1.25; }
            .welcome-section p { font-size: 0.72rem; margin-bottom: 0 !important; }
            .welcome-section .text-end {
                text-align: left !important;
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 0.4rem;
                align-items: center;
            }
            .welcome-section .text-end > div { margin-top: 0 !important; }
            .welcome-section .badge-teal { font-size: 0.65rem; padding: 3px 9px; }
            .welcome-section .small { font-size: 0.68rem; }

            /* ---------- 🔑 KPI ROW → CSS GRID (BULLETPROOF) ---------- */
            .row.g-3.mb-4 {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 0.5rem !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                --bs-gutter-x: 0 !important;
            }
            .row.g-3.mb-4 > [class*="col-"] {
                flex: none !important;
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                min-width: 0;
            }
            .row.g-3.mb-4 > .col-12.col-md-4:last-child {
                grid-column: 1 / -1;
            }

            /* ---------- STAT CARD ---------- */
            .stat-card {
                padding: 0.7rem 0.75rem;
                border-radius: 12px;
                height: auto !important;
                min-height: 88px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            .stat-card .d-flex {
                flex-direction: row !important;
                align-items: flex-start !important;
                gap: 0.4rem;
                width: 100%;
            }
            .stat-card .d-flex > div:first-child {
                flex: 1 1 0;
                min-width: 0;
                overflow: hidden;
            }
            .stat-card .d-flex > div:last-child { flex: 0 0 auto; }
            .stat-value {
                font-size: 1rem !important;
                line-height: 1.15;
                font-weight: 700;
                word-break: break-word;
                overflow-wrap: anywhere;
            }
            .stat-label {
                font-size: 0.6rem;
                letter-spacing: 0;
                white-space: normal;
                line-height: 1.2;
                display: block;
                margin-bottom: 2px;
            }
            .stat-trend {
                font-size: 0.58rem;
                margin-top: 3px;
                gap: 2px;
                line-height: 1.3;
                display: block;
                white-space: normal;
                word-break: break-word;
            }
            .stat-trend i { font-size: 0.62rem; }
            .stat-card .card-icon {
                width: 30px !important;
                height: 30px !important;
                font-size: 13px !important;
                border-radius: 9px !important;
                flex-shrink: 0 !important;
            }

            /* ---------- CARDS ---------- */
            .card { border-radius: 12px !important; }
            .card-header-custom {
                padding: 0.6rem 0.75rem;
                flex-wrap: wrap;
                gap: 0.3rem;
            }
            .card-header-custom h6 {
                font-size: 0.78rem;
                white-space: normal;
                overflow: visible;
                text-overflow: unset;
            }
            .card-header-custom .badge { font-size: 0.58rem; }
            .card-header-custom > div { flex-wrap: wrap; gap: 0.25rem; }
            .card-body { padding: 0.6rem; }

            /* ---------- CHARTS ---------- */
            .chart-container {
                height: 200px !important;
                width: 100%;
                min-width: 0;
            }

            /* ---------- QUICK ACTIONS ---------- */
            .row.g-2 {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 0.4rem !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .row.g-2 > .col {
                flex: none !important;
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
            }
            .quick-action { padding: 0.5rem 0.25rem; border-radius: 10px; }
            .quick-action-icon {
                width: 32px;
                height: 32px;
                font-size: 14px;
                border-radius: 9px;
                margin-bottom: 4px;
            }
            .quick-action .small {
                font-size: 0.6rem;
                line-height: 1.15;
                white-space: normal;
            }

            /* ---------- 🔑 TABLES → CARD VIEW ---------- */
            .table-responsive {
                overflow: visible !important;
                border-radius: 0 0 12px 12px;
            }
            .table-custom {
                display: block;
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
            }
            .table-custom thead { display: none !important; }
            .table-custom tbody,
            .table-custom tr,
            .table-custom td {
                display: block;
                width: 100% !important;
            }
            .table-custom tr {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 0.5rem 0.65rem;
                margin-bottom: 0.5rem;
                box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            }
            .table-custom tr:hover { background: #fff; }
            .table-custom td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 0.5rem;
                padding: 0.28rem 0 !important;
                border: none !important;
                font-size: 0.75rem !important;
                white-space: normal !important;
                text-align: right;
                line-height: 1.3;
                min-height: 22px;
            }
            .table-custom td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #64748b;
                font-size: 0.62rem;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                text-align: left;
                flex: 0 0 auto;
            }
            .table-custom td:last-child {
                border-top: 1px dashed #e2e8f0 !important;
                padding-top: 0.45rem !important;
                margin-top: 0.25rem;
            }
            .table-custom td[data-label="Actions"] {
                justify-content: flex-end;
                gap: 0.3rem;
            }
            .table-custom td[data-label="Actions"]::before { margin-right: auto; }
            .table-custom .btn-sm {
                padding: 3px 7px;
                font-size: 0.65rem;
                min-height: 26px;
                min-width: 26px;
            }
            .table-custom code { font-size: 0.65rem; word-break: break-all; }

            /* ---------- ACTIVITY LIST ---------- */
            .activity-item { padding: 0.55rem 0.65rem !important; }
            .activity-item .d-flex { gap: 0.5rem !important; }
            .activity-item .fw-medium { font-size: 0.7rem; }
            .activity-item .small { font-size: 0.6rem; }
            .activity-icon {
                width: 26px !important;
                height: 26px !important;
                font-size: 11px !important;
                border-radius: 7px !important;
                flex-shrink: 0;
            }

            /* ---------- BOTTOM ROWS → STACK ---------- */
            .row.g-3.mt-3,
            .row.g-3:not(.mb-4):not(.mt-3):not(.g-2) {
                display: block !important;
            }
            .row.g-3.mt-3 > [class*="col-"],
            .row.g-3:not(.mb-4):not(.mt-3):not(.g-2) > [class*="col-"] {
                width: 100% !important;
                max-width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                margin-bottom: 0.75rem;
            }
            .row.g-3 > [class*="col-"]:last-child { margin-bottom: 0; }

            /* ---------- MISC ---------- */
            .swal2-popup {
                width: 92% !important;
                font-size: 0.8rem !important;
                padding: 1rem !important;
            }
            .swal2-title { font-size: 0.95rem !important; }
            .swal2-html-container { font-size: 0.78rem !important; }
        }

        /* Extra small phones */
        @media (max-width: 374.98px) {
            .stat-value { font-size: 0.9rem !important; }
            .stat-label { font-size: 0.56rem; }
            .stat-card .card-icon { width: 26px !important; height: 26px !important; font-size: 11px !important; }
            .chart-container { height: 170px !important; }
            .row.g-2 { grid-template-columns: repeat(2, 1fr) !important; }
            .quick-action .small { font-size: 0.58rem; }
            .table-custom td { font-size: 0.7rem !important; }
            .table-custom td::before { font-size: 0.58rem; }
        }

        /* Tablet */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .stat-value { font-size: 1.35rem; }
            .stat-card .card-icon { width: 40px; height: 40px; font-size: 18px; }
            .quick-action .small { font-size: 0.72rem; }
            .chart-container { height: 240px; }
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
        <div class="col-6 col-md-3">
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
        <div class="col-6 col-md-3">
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
        <div class="col-6 col-md-3">
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
        <div class="col-6 col-md-3">
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
        <div class="col-6 col-md-4">
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
        <div class="col-6 col-md-4">
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
        <div class="col-12 col-md-4">
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
        <div class="col-12 col-md-7">
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
        <div class="col-12 col-md-5">
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
         BOTTOM SECTIONS - EQUAL HEIGHT CARDS
    ═══════════════════════════════════════════════════════════════ -->
    
    <!-- Row 1: Recent Patients & Top Selling Items -->
    <div class="row g-3 mb-3">
        <!-- Recent Patients -->
        <div class="col-12 col-md-6 d-flex">
            <div class="card border-0 shadow-sm w-100 d-flex flex-column">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2" style="color: var(--teal);"></i>Recent Patients</h6>
                    <div>
                        <?php if ($canCreate): ?>
                        <a href="?view=patients&action=add" class="btn btn-sm btn-link text-teal me-2 text-decoration-none">Add New</a>
                        <?php endif; ?>
                        <a href="?view=patients" class="btn btn-sm btn-link text-teal text-decoration-none">View All</a>
                    </div>
                </div>
                <div class="card-body p-0 flex-grow-1 d-flex flex-column">
                    <?php if (!empty($recentPatients)): ?>
                        <div class="table-responsive flex-grow-1">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr><th>Code</th><th>Name</th><th>Contact</th><th>Added</th><?php if ($canEdit || $canDelete): ?><th>Actions</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPatients as $patient): ?>
                                    <tr>
                                        <td data-label="Code"><code><?= htmlspecialchars($patient['patient_code']); ?></code></td>
                                        <td data-label="Name" class="fw-medium"><?= htmlspecialchars($patient['patient_name']); ?></td>
                                        <td data-label="Contact"><?= htmlspecialchars($patient['phone'] ?? 'N/A'); ?></td>
                                        <td data-label="Added" class="text-muted small"><?= date('M d', strtotime($patient['created_at'])); ?></td>
                                        <?php if ($canEdit || $canDelete): ?>
                                        <td data-label="Actions">
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
                        <div class="text-center py-4 text-muted d-flex flex-column justify-content-center align-items-center flex-grow-1">
                            <i class="bi bi-people fs-1"></i>
                            <p class="mt-2 mb-0">No patients yet</p>
                            <?php if ($canCreate): ?>
                            <a href="?view=patients&action=add" class="btn btn-sm btn-teal mt-2 text-decoration-none">Add First Patient</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Top Selling Items -->
        <div class="col-12 col-md-6 d-flex">
            <div class="card border-0 shadow-sm w-100 d-flex flex-column">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-trophy me-2" style="color: var(--orange);"></i>Top Selling Items</h6>
                    <a href="?view=reports&tab=inventory" class="btn btn-sm btn-link text-teal text-decoration-none">View All</a>
                </div>
                <div class="card-body p-0 flex-grow-1 d-flex flex-column">
                    <?php if (!empty($topItems)): ?>
                        <div class="table-responsive flex-grow-1">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr><th>Item</th><th>Sold</th><th>Revenue</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topItems as $item): ?>
                                    <tr>
                                        <td data-label="Item" class="fw-medium"><?= htmlspecialchars($item['item_name']); ?></td>
                                        <td data-label="Sold"><?= $item['total_sold']; ?> units</td>
                                        <td data-label="Revenue" class="text-success fw-semibold">₱<?= number_format($item['revenue'], 0); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted d-flex flex-column justify-content-center align-items-center flex-grow-1">
                            <i class="bi bi-box-seam fs-1"></i>
                            <p class="mt-2 mb-0">No sales data yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Upcoming Appointments & Recent Activity -->
    <div class="row g-3">
        <!-- Upcoming Appointments -->
        <div class="col-12 col-md-6 d-flex">
            <div class="card border-0 shadow-sm w-100 d-flex flex-column">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-calendar-event me-2" style="color: var(--teal);"></i>Upcoming Appointments</h6>
                    <div>
                        <?php if ($canCreate): ?>
                        <a href="?view=appointments&action=add" class="btn btn-sm btn-link text-teal me-2 text-decoration-none">Schedule</a>
                        <?php endif; ?>
                        <a href="?view=appointments" class="btn btn-sm btn-link text-teal text-decoration-none">View Calendar</a>
                    </div>
                </div>
                <div class="card-body p-0 flex-grow-1 d-flex flex-column">
                    <?php if (!empty($upcomingAppointments)): ?>
                        <div class="table-responsive flex-grow-1">
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
                                        <td data-label="Date & Time">
                                            <div class="fw-medium"><?= date('M d', strtotime($appt['appointment_date'])); ?></div>
                                            <div class="small text-muted"><?= date('h:i A', strtotime($appt['appointment_time'])); ?></div>
                                        </td>
                                        <td data-label="Patient" class="fw-medium"><?= htmlspecialchars($appt['patient_name'] ?? 'N/A'); ?></td>
                                        <td data-label="Contact"><?= htmlspecialchars($appt['phone'] ?? 'N/A'); ?></td>
                                        <td data-label="Status">
                                            <span class="badge bg-<?= $appt['status'] == 'confirmed' ? 'success' : ($appt['status'] == 'pending' ? 'warning' : 'secondary'); ?>">
                                                <?= ucfirst($appt['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Service"><?= htmlspecialchars($appt['service_name'] ?? 'Check-up'); ?></td>
                                        <?php if ($canEdit || $canApprove || $canReject): ?>
                                        <td data-label="Actions">
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
                        <div class="text-center py-4 text-muted d-flex flex-column justify-content-center align-items-center flex-grow-1">
                            <i class="bi bi-calendar-x fs-1"></i>
                            <p class="mt-2 mb-0">No upcoming appointments</p>
                            <?php if ($canCreate): ?>
                            <a href="?view=appointments&action=add" class="btn btn-sm btn-teal mt-2 text-decoration-none">Schedule Appointment</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-12 col-md-6 d-flex">
            <div class="card border-0 shadow-sm w-100 d-flex flex-column">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-activity me-2" style="color: var(--teal);"></i>Recent Activity</h6>
                    <a href="?view=logs" class="btn btn-sm btn-link text-teal text-decoration-none">View All</a>
                </div>
                <div class="card-body p-0 flex-grow-1 d-flex flex-column">
                    <?php if (!empty($activities)): ?>
                        <div class="flex-grow-1">
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
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted d-flex flex-column justify-content-center align-items-center flex-grow-1">
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
</script>

</body>
</html>