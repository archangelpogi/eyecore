<?php
include __DIR__ . '/../config/db.php';
session_start();

// Check if SuperAdmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$response = [
    'totalClinics' => 0,
    'activeClinics' => 0,
    'pendingClinics' => 0,
    'totalStaff' => 0,
    'uniqueCities' => 0,
    'growthPercent' => 0,
    'activeCities' => 0,
    'pendingInfo' => '',
    'clinics' => [],
    'topCities' => [],
    'documents' => [],
    'system' => [],
    'charts' => []
];

// Get date ranges
$currentMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
$today = date('Y-m-d');
$thirtyDaysFromNow = date('Y-m-d', strtotime('+30 days'));

try {
    // 1. Get total clinics count
    $query = "SELECT COUNT(*) as total FROM clinics";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['totalClinics'] = $result['total'] ?? 0;

    // 2. Get active clinics count
    $query = "SELECT COUNT(*) as active FROM clinics WHERE status = 'Active'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['activeClinics'] = $result['active'] ?? 0;

    // 3. Get pending clinics count
    $query = "SELECT COUNT(*) as pending FROM clinics WHERE status = 'Pending'";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['pendingClinics'] = $result['pending'] ?? 0;

    // 4. Get total staff count (excluding SuperAdmin)
    $query = "SELECT COUNT(*) as total_staff FROM users WHERE role != 'SuperAdmin' AND clinic_id IS NOT NULL";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['totalStaff'] = $result['total_staff'] ?? 0;

    // 5. Get unique cities count
    $query = "SELECT COUNT(DISTINCT city) as unique_cities FROM clinics WHERE city IS NOT NULL AND city != ''";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['uniqueCities'] = $result['unique_cities'] ?? 0;

    // 6. Get growth percentage (new clinics this month vs last month) - FIXED
    // Method 1: Execute separate queries
    $queryCurrentMonth = "SELECT COUNT(*) as count FROM clinics WHERE created_at >= :current_start";
    $stmtCurrent = $pdo->prepare($queryCurrentMonth);
    $stmtCurrent->execute([':current_start' => $currentMonthStart]);
    $currentResult = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
    $current = $currentResult['count'] ?? 0;
    
    $queryLastMonth = "SELECT COUNT(*) as count FROM clinics WHERE created_at >= :last_start AND created_at < :current_start";
    $stmtLast = $pdo->prepare($queryLastMonth);
    $stmtLast->execute([
        ':last_start' => $lastMonthStart,
        ':current_start' => $currentMonthStart
    ]);
    $lastResult = $stmtLast->fetch(PDO::FETCH_ASSOC);
    $last = $lastResult['count'] ?? 0;
    
    $response['growthPercent'] = $last > 0 ? round((($current - $last) / $last) * 100, 1) : ($current > 0 ? 100 : 0);

    // 7. Get clinic directory data
    $query = "SELECT 
        c.id, 
        c.clinic_code, 
        c.clinic_name, 
        c.city, 
        c.clinic_type, 
        c.status,
        c.risk_level,
        (SELECT COUNT(*) FROM users u WHERE u.clinic_id = c.id AND u.role != 'SuperAdmin') as staff_count
    FROM clinics c
    ORDER BY c.created_at DESC
    LIMIT 10";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['clinics'] = $clinics ?: [];

    // 8. Get top cities by clinic count
    $query = "SELECT 
        city as name,
        COUNT(*) as clinic_count
    FROM clinics 
    WHERE city IS NOT NULL AND city != ''
    GROUP BY city
    ORDER BY clinic_count DESC
    LIMIT 5";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $topCities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['topCities'] = $topCities ?: [];

    // 9. Get document statistics
    $query = "SELECT 
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM clinic_documents";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get expiring documents (expiring within 30 days)
    $expiringQuery = "SELECT COUNT(*) as expiring FROM clinic_documents 
                     WHERE expires_at BETWEEN :today AND :thirty_days_from_now
                     AND status = 'Approved'";
    $expiringStmt = $pdo->prepare($expiringQuery);
    $expiringStmt->execute([
        ':today' => $today,
        ':thirty_days_from_now' => $thirtyDaysFromNow
    ]);
    $expiringResult = $expiringStmt->fetch(PDO::FETCH_ASSOC);
    
    $approvedDocs = $result['approved'] ?? 0;
    $expiringCount = $expiringResult['expiring'] ?? 0;
    $expiryPercent = $approvedDocs > 0 ? round(($expiringCount / $approvedDocs) * 100) : 0;
    
    $response['documents'] = [
        'pending' => $result['pending'] ?? 0,
        'approved' => $approvedDocs,
        'rejected' => $result['rejected'] ?? 0,
        'expiring' => $expiringCount,
        'expiryPercent' => $expiryPercent
    ];

    // 10. Get system statistics
    $query = "SELECT 
        (SELECT COUNT(*) FROM clinics WHERE risk_level = 'high') as high_risk,
        (SELECT COUNT(*) FROM clinics WHERE clinic_type = 'standalone') as standalone,
        (SELECT COUNT(*) FROM clinics WHERE clinic_type = 'hospital_based') as hospital_based,
        (SELECT COUNT(*) FROM clinics WHERE created_at >= :thirty_days_ago) as new_this_month,
        (SELECT COUNT(*) FROM clinics WHERE status IN ('Pending', 'Reapplying')) as attention_needed";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':thirty_days_ago' => $thirtyDaysAgo]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $avgStaff = $response['totalClinics'] > 0 ? round($response['totalStaff'] / $response['totalClinics'], 1) : 0;
    $verificationRate = $response['totalClinics'] > 0 ? round(($response['activeClinics'] / $response['totalClinics']) * 100, 1) : 0;
    
    $response['system'] = [
        'avgStaff' => $avgStaff,
        'verificationRate' => $verificationRate,
        'highRisk' => $result['high_risk'] ?? 0,
        'standalone' => $result['standalone'] ?? 0,
        'hospitalBased' => $result['hospital_based'] ?? 0,
        'newThisMonth' => $result['new_this_month'] ?? 0,
        'attentionNeeded' => $result['attention_needed'] ?? 0
    ];

    // 11. Get chart data - City distribution chart
    $query = "SELECT 
        city,
        COUNT(*) as count
    FROM clinics 
    WHERE city IS NOT NULL AND city != ''
    GROUP BY city
    ORDER BY count DESC
    LIMIT 8";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $cityData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cityLabels = [];
    $cityCounts = [];
    foreach ($cityData as $row) {
        $cityLabels[] = $row['city'];
        $cityCounts[] = $row['count'];
    }

    // Status distribution chart
    $query = "SELECT 
        status,
        COUNT(*) as count
    FROM clinics 
    GROUP BY status
    ORDER BY 
        CASE status
            WHEN 'Active' THEN 1
            WHEN 'Pending' THEN 2
            WHEN 'Reapplying' THEN 3
            WHEN 'Suspended' THEN 4
            WHEN 'Rejected' THEN 5
            ELSE 6
        END";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $statusLabels = [];
    $statusCounts = [];
    foreach ($statusData as $row) {
        $statusLabels[] = $row['status'];
        $statusCounts[] = $row['count'];
    }

    $response['charts'] = [
        'cityDistribution' => [
            'labels' => $cityLabels,
            'data' => $cityCounts
        ],
        'statusDistribution' => [
            'labels' => $statusLabels,
            'data' => $statusCounts
        ]
    ];

    // 12. Get active cities count
    $query = "SELECT COUNT(DISTINCT city) as active_cities FROM clinics WHERE status = 'Active' AND city IS NOT NULL";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $response['activeCities'] = $result['active_cities'] ?? 0;

    // 13. Get pending info text
    $response['pendingInfo'] = $response['pendingClinics'] > 0 ? 
        "{$response['pendingClinics']} awaiting verification" : 
        "All clinics verified";

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error occurred', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    exit();
}

header('Content-Type: application/json');
echo json_encode($response);
?>