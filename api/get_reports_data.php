<?php
require_once __DIR__ . '/../config/db.php';
session_start();

// Check if SuperAdmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Access denied']);
    exit();
}

// Get filter parameters
$range = $_GET['range'] ?? '6months';
$city_filter = $_GET['city'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Calculate date ranges
$today = date('Y-m-d');
switch($range) {
    case '30days':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        break;
    default: // 6months
        $start_date = date('Y-m-d', strtotime('-6 months'));
}

$response = [
    'success' => true,
    'data' => [],
    'charts' => [],
    'summary' => []
];

try {
    // Build where conditions based on filters
    $clinicWhere = "1=1";
    $invoiceWhere = "i.invoice_date >= :start_date";
    $params = [':start_date' => $start_date];
    
    if ($city_filter != 'all') {
        $clinicWhere .= " AND c.city = :city";
        $invoiceWhere .= " AND c.city = :city";
        $params[':city'] = $city_filter;
    }
    
    if ($status_filter != 'all') {
        $clinicWhere .= " AND c.status = :status";
        $invoiceWhere .= " AND c.status = :status";
        $params[':status'] = $status_filter;
    }

    error_log("DEBUG: Invoice WHERE clause: " . $invoiceWhere);
    error_log("DEBUG: Params: " . print_r($params, true));

    // 1. SYSTEM SUMMARY DATA
    $summarySql = "SELECT 
        COALESCE(SUM(i.total), 0) as total_revenue,
        COALESCE(SUM(i.amount_paid), 0) as total_paid,
        COUNT(DISTINCT i.clinic_id) as active_clinic_count,
        (SELECT COUNT(*) FROM clinics WHERE status = 'Active') as total_active_clinics,
        (SELECT COUNT(*) FROM clinics WHERE status = 'Pending') as pending_clinics,
        (SELECT COUNT(DISTINCT city) FROM clinics WHERE city IS NOT NULL) as cities_covered,
        (SELECT COUNT(*) FROM users WHERE role != 'SuperAdmin') as total_system_staff
    FROM invoices i
    INNER JOIN clinics c ON i.clinic_id = c.id
    WHERE $invoiceWhere";
    
    error_log("DEBUG: Summary SQL: " . $summarySql);
    
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summaryData = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("DEBUG: Summary Data: " . print_r($summaryData, true));
    
    // Calculate collection rate
    $collectedRevenue = $summaryData['total_paid'] ?? 0;
    $totalBilled = $summaryData['total_revenue'] ?? 0;
    $pendingCollection = $totalBilled - $collectedRevenue;
    $collectionRate = $totalBilled > 0 ? round(($collectedRevenue / $totalBilled) * 100, 1) : 100;
    
    // Calculate average revenue per clinic
    $totalActiveClinics = $summaryData['total_active_clinics'] ?? 1;
    $avgRevenuePerClinic = $totalActiveClinics > 0 ? round($totalBilled / $totalActiveClinics, 2) : 0;
    
    // Calculate growth (simplified - compare with previous period)
    $prevStartDate = date('Y-m-d', strtotime($start_date . ' -6 months'));
    $prevRevenueSql = "SELECT COALESCE(SUM(total), 0) as prev_revenue FROM invoices WHERE invoice_date BETWEEN :prev_start AND :start_date";
    $prevRevenueStmt = $pdo->prepare($prevRevenueSql);
    $prevRevenueStmt->execute([
        ':prev_start' => $prevStartDate,
        ':start_date' => $start_date
    ]);
    $prevRevenueData = $prevRevenueStmt->fetch(PDO::FETCH_ASSOC);
    $prevRevenue = $prevRevenueData['prev_revenue'] ?? 0;
    
    $revenueGrowth = $prevRevenue > 0 ? round((($totalBilled - $prevRevenue) / $prevRevenue) * 100, 1) : ($totalBilled > 0 ? 100 : 0);
    
    $response['summary'] = [
        'totalRevenue' => $totalBilled,
        'totalPaid' => $collectedRevenue,
        'activeClinicCount' => $summaryData['active_clinic_count'] ?? 0,
        'totalActiveClinics' => $totalActiveClinics,
        'pendingClinics' => $summaryData['pending_clinics'] ?? 0,
        'citiesCovered' => $summaryData['cities_covered'] ?? 0,
        'totalSystemStaff' => $summaryData['total_system_staff'] ?? 0,
        'avgRevenuePerClinic' => $avgRevenuePerClinic,
        'collectionRate' => $collectionRate,
        'revenueGrowth' => $revenueGrowth,
        'pendingCollection' => $pendingCollection
    ];

    // 2. MONTHLY REVENUE CHART DATA
    $monthlySql = "SELECT 
        DATE_FORMAT(i.invoice_date, '%b %Y') as month_year,
        DATE_FORMAT(i.invoice_date, '%b') as month,
        YEAR(i.invoice_date) as year,
        MONTH(i.invoice_date) as month_num,
        COALESCE(SUM(i.total), 0) as revenue,
        COUNT(DISTINCT c.id) as clinic_count,
        COUNT(i.id) as transaction_count
    FROM invoices i
    INNER JOIN clinics c ON i.clinic_id = c.id
    WHERE $invoiceWhere
    GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m'), 
             DATE_FORMAT(i.invoice_date, '%b %Y'),
             YEAR(i.invoice_date),
             MONTH(i.invoice_date)
    ORDER BY year, month_num";
    
    error_log("DEBUG: Monthly SQL: " . $monthlySql);
    
    $monthlyStmt = $pdo->prepare($monthlySql);
    $monthlyStmt->execute($params);
    $monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    $response['charts']['monthlyRevenue'] = $monthlyData;

    // 3. CITY REVENUE DISTRIBUTION
    $citySql = "SELECT 
        c.city,
        COUNT(DISTINCT c.id) as clinic_count,
        COALESCE(SUM(i.total), 0) as revenue,
        COUNT(DISTINCT i.patient_id) as patient_count
    FROM clinics c
    LEFT JOIN invoices i ON c.id = i.clinic_id AND i.invoice_date >= :start_date
    WHERE c.city IS NOT NULL AND c.city != ''
    GROUP BY c.city
    ORDER BY revenue DESC
    LIMIT 10";
    
    $cityStmt = $pdo->prepare($citySql);
    $cityStmt->execute([':start_date' => $start_date]);
    $response['charts']['cityRevenue'] = $cityStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. CLINIC STATUS DATA
    $statusSql = "SELECT 
        status,
        COUNT(*) as count,
        SUM(CASE WHEN created_at >= :start_date THEN 1 ELSE 0 END) as new_this_period
    FROM clinics
    GROUP BY status
    ORDER BY FIELD(status, 'Active', 'Pending', 'Reapplying', 'Suspended', 'Rejected')";
    
    $statusStmt = $pdo->prepare($statusSql);
    $statusStmt->execute([':start_date' => $start_date]);
    $response['data']['clinicStatus'] = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. TOP PERFORMING CLINICS
    // First, get total revenue for the period
    $totalRevenueSql = "SELECT COALESCE(SUM(total), 0) as total FROM invoices WHERE invoice_date >= :start_date";
    $totalRevenueStmt = $pdo->prepare($totalRevenueSql);
    $totalRevenueStmt->execute([':start_date' => $start_date]);
    $totalRevenueData = $totalRevenueStmt->fetch(PDO::FETCH_ASSOC);
    $periodTotalRevenue = $totalRevenueData['total'] ?? 1; // Avoid division by zero
    
    $topClinicsSql = "SELECT 
        c.clinic_name,
        c.city,
        c.clinic_type,
        COALESCE(SUM(i.total), 0) as revenue,
        COUNT(DISTINCT i.patient_id) as patients_served
    FROM clinics c
    LEFT JOIN invoices i ON c.id = i.clinic_id AND i.invoice_date >= :start_date
    WHERE c.status = 'Active'
    GROUP BY c.id, c.clinic_name, c.city, c.clinic_type
    ORDER BY revenue DESC
    LIMIT 5";
    
    $topClinicsStmt = $pdo->prepare($topClinicsSql);
    $topClinicsStmt->execute([':start_date' => $start_date]);
    $topClinicsData = $topClinicsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate revenue share for each clinic
    foreach ($topClinicsData as &$clinic) {
        $clinic['revenue_share'] = $periodTotalRevenue > 0 ? round(($clinic['revenue'] / $periodTotalRevenue) * 100, 1) : 0;
    }
    
    $response['data']['topClinics'] = $topClinicsData;

    // 6. CLINIC TYPE PERFORMANCE
    $clinicTypeSql = "SELECT 
        c.clinic_type,
        COUNT(DISTINCT c.id) as clinic_count,
        COALESCE(SUM(i.total), 0) as total_revenue,
        COUNT(DISTINCT i.patient_id) as total_patients
    FROM clinics c
    LEFT JOIN invoices i ON c.id = i.clinic_id AND i.invoice_date >= :start_date
    WHERE c.status = 'Active'
    GROUP BY c.clinic_type";
    
    $clinicTypeStmt = $pdo->prepare($clinicTypeSql);
    $clinicTypeStmt->execute([':start_date' => $start_date]);
    $clinicTypeData = $clinicTypeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate average revenue per clinic
    foreach ($clinicTypeData as &$type) {
        $type['avg_revenue_per_clinic'] = $type['clinic_count'] > 0 ? round($type['total_revenue'] / $type['clinic_count'], 2) : 0;
    }
    
    $response['data']['clinicTypes'] = $clinicTypeData;

} catch (Exception $e) {
    error_log("Error fetching report data: " . $e->getMessage());
    $response = [
        'success' => false,
        'error' => 'Database error occurred',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
?>