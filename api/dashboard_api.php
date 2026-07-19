<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// FIXED PATH - adjust according to your actual directory structure
include __DIR__ . '/../config/db.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $clinicId = $_GET['clinic_id'] ?? 'all';
    $dateRange = $_GET['date_range'] ?? 30;
    
    switch ($action) {
        case 'get_clinics':
            echo json_encode(getClinics($pdo));
            break;
            
        case 'get_kpis':
            echo json_encode(getKPIs($pdo, $clinicId, $dateRange));
            break;
            
        case 'get_patient_chart':
            echo json_encode(getPatientChartData($pdo, $clinicId, $dateRange));
            break;
            
        case 'get_sales_chart':
            echo json_encode(getSalesChartData($pdo, $clinicId, $dateRange));
            break;
            
        case 'get_clinic_distribution':
            echo json_encode(getClinicDistribution($pdo));
            break;
            
        case 'get_recent_activity':
            echo json_encode(getRecentActivity($pdo, $clinicId));
            break;
            
        case 'get_low_stock':
            echo json_encode(getLowStockAlerts($pdo, $clinicId));
            break;
            
        case 'get_quick_stats':
            echo json_encode(getQuickStats($pdo, $clinicId));
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} else {
    echo json_encode(['error' => 'Method not allowed']);
}

function getClinics($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT id, clinic_code, clinic_name, status 
            FROM clinics 
            WHERE status = 'Active' 
            ORDER BY clinic_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getKPIs($pdo, $clinicId, $dateRange) {
    try {
        $data = [];
        $clinicCondition = $clinicId === 'all' ? '' : "AND clinic_id = :clinic_id";
        
        // 1. Total Clinics
        if ($clinicId === 'all') {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM clinics WHERE status = 'Active'");
            $data['total_clinics'] = (int)$stmt->fetch()['count'];
        } else {
            $data['total_clinics'] = 1; // Only one clinic selected
        }
        
        // 2. Total Patients
        if ($clinicId === 'all') {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM patients");
            $data['total_patients'] = (int)$stmt->fetch()['count'];
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM patients WHERE clinic_id = :clinic_id");
            $stmt->execute(['clinic_id' => $clinicId]);
            $data['total_patients'] = (int)$stmt->fetch()['count'];
        }
        
        // 3. This Month Patients
        $sql = "SELECT COUNT(*) as count FROM patients 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($clinicId !== 'all') {
            $sql .= " AND clinic_id = :clinic_id";
        }
        
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $data['this_month_patients'] = (int)$stmt->fetch()['count'];
        
        // 4. Total Appointments (Last 30 days)
        $sql = "SELECT COUNT(*) as count FROM appointments 
                WHERE appointment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($clinicId !== 'all') {
            $sql .= " AND clinic_id = :clinic_id";
        }
        
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $data['total_appointments'] = (int)$stmt->fetch()['count'];
        
        // 5. Completed Appointments
        $sql = "SELECT COUNT(*) as count FROM appointments 
                WHERE status = 'Completed' 
                AND appointment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($clinicId !== 'all') {
            $sql .= " AND clinic_id = :clinic_id";
        }
        
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $data['completed_appointments'] = (int)$stmt->fetch()['count'];
        
        // 6. Monthly Revenue
        $sql = "SELECT COALESCE(SUM(total), 0) as revenue 
                FROM invoices 
                WHERE invoice_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        if ($clinicId !== 'all') {
            $sql .= " AND clinic_id = :clinic_id";
        }
        
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $data['monthly_revenue'] = (float)$stmt->fetch()['revenue'];
        
        // 7. Calculate trends (using your database)
        // Clinic trend - compare with last period
        $data['clinic_trend'] = calculateTrend($pdo, 'clinics', 'id', $clinicId, $dateRange);
        $data['patient_trend'] = calculateTrend($pdo, 'patients', 'id', $clinicId, $dateRange);
        $data['appointment_trend'] = calculateTrend($pdo, 'appointments', 'id', $clinicId, $dateRange);
        $data['revenue_trend'] = calculateRevenueTrend($pdo, $clinicId, $dateRange);
        
        return $data;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function calculateTrend($pdo, $table, $idColumn, $clinicId, $dateRange) {
    try {
        $currentPeriod = getCurrentPeriodCondition($dateRange);
        $previousPeriod = getPreviousPeriodCondition($dateRange);
        
        $clinicCondition = $clinicId === 'all' ? '' : "AND clinic_id = :clinic_id";
        
        // Current period count
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $currentPeriod $clinicCondition";
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $current = (int)$stmt->fetch()['count'];
        
        // Previous period count
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $previousPeriod $clinicCondition";
        $stmt = $clinicId === 'all' ? $pdo->query($sql) : $pdo->prepare($sql);
        if ($clinicId !== 'all') $stmt->execute(['clinic_id' => $clinicId]);
        $previous = (int)$stmt->fetch()['count'];
        
        if ($previous == 0) return '+0%';
        
        $trend = (($current - $previous) / $previous) * 100;
        $symbol = $trend >= 0 ? '+' : '';
        
        return $symbol . round($trend) . '%';
    } catch (Exception $e) {
        return '+0%';
    }
}

function calculateRevenueTrend($pdo, $clinicId, $dateRange) {
    try {
        $currentStart = getCurrentPeriodStart($dateRange);
        $previousStart = getPreviousPeriodStart($dateRange);
        
        $clinicCondition = $clinicId === 'all' ? '' : "AND clinic_id = :clinic_id";
        
        // Current period revenue
        $sql = "SELECT COALESCE(SUM(total), 0) as revenue 
                FROM invoices 
                WHERE invoice_date >= :current_start $clinicCondition";
        $stmt = $pdo->prepare($sql);
        $params = ['current_start' => $currentStart];
        if ($clinicId !== 'all') $params['clinic_id'] = $clinicId;
        $stmt->execute($params);
        $current = (float)$stmt->fetch()['revenue'];
        
        // Previous period revenue
        $sql = "SELECT COALESCE(SUM(total), 0) as revenue 
                FROM invoices 
                WHERE invoice_date >= :previous_start 
                AND invoice_date < :current_start $clinicCondition";
        $stmt = $pdo->prepare($sql);
        $params = [
            'previous_start' => $previousStart,
            'current_start' => $currentStart
        ];
        if ($clinicId !== 'all') $params['clinic_id'] = $clinicId;
        $stmt->execute($params);
        $previous = (float)$stmt->fetch()['revenue'];
        
        if ($previous == 0) return '+0%';
        
        $trend = (($current - $previous) / $previous) * 100;
        $symbol = $trend >= 0 ? '+' : '';
        
        return $symbol . round($trend) . '%';
    } catch (Exception $e) {
        return '+0%';
    }
}

function getCurrentPeriodCondition($dateRange) {
    switch($dateRange) {
        case '7': return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '90': return "created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        case '365': return "YEAR(created_at) = YEAR(NOW())";
        default: return "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
}

function getPreviousPeriodCondition($dateRange) {
    switch($dateRange) {
        case '7': return "created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case '90': return "created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
        case '365': return "YEAR(created_at) = YEAR(NOW()) - 1";
        default: return "created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
}

function getCurrentPeriodStart($dateRange) {
    switch($dateRange) {
        case '7': return date('Y-m-d', strtotime('-7 days'));
        case '90': return date('Y-m-d', strtotime('-90 days'));
        case '365': return date('Y-01-01');
        default: return date('Y-m-d', strtotime('-30 days'));
    }
}

function getPreviousPeriodStart($dateRange) {
    switch($dateRange) {
        case '7': return date('Y-m-d', strtotime('-14 days'));
        case '90': return date('Y-m-d', strtotime('-180 days'));
        case '365': return date('Y-01-01', strtotime('-1 year'));
        default: return date('Y-m-d', strtotime('-60 days'));
    }
}

function getPatientChartData($pdo, $clinicId, $dateRange) {
    try {
        $labels = [];
        $data = [];
        
        $months = $dateRange == 7 ? 1 : ($dateRange == 90 ? 6 : 6);
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M', strtotime($date));
            
            $clinicCondition = $clinicId === 'all' ? '' : "AND clinic_id = :clinic_id";
            $sql = "SELECT COUNT(*) as count 
                    FROM patients 
                    WHERE DATE_FORMAT(created_at, '%Y-%m') = :date $clinicCondition";
            
            $stmt = $pdo->prepare($sql);
            $params = ['date' => $date];
            if ($clinicId !== 'all') $params['clinic_id'] = $clinicId;
            
            $stmt->execute($params);
            $result = $stmt->fetch();
            $data[] = (int)$result['count'];
        }
        
        return ['labels' => $labels, 'data' => $data];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getSalesChartData($pdo, $clinicId, $dateRange) {
    try {
        $labels = [];
        $revenueData = [];
        $salesData = [];
        
        $months = $dateRange == 7 ? 1 : ($dateRange == 90 ? 6 : 6);
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M', strtotime($date));
            
            // Revenue from invoices
            $clinicCondition = $clinicId === 'all' ? '' : "AND clinic_id = :clinic_id";
            $sql = "SELECT COALESCE(SUM(total), 0) as revenue 
                    FROM invoices 
                    WHERE DATE_FORMAT(invoice_date, '%Y-%m') = :date $clinicCondition";
            
            $stmt = $pdo->prepare($sql);
            $params = ['date' => $date];
            if ($clinicId !== 'all') $params['clinic_id'] = $clinicId;
            
            $stmt->execute($params);
            $result = $stmt->fetch();
            $revenueData[] = (float)$result['revenue'];
            
            // Count invoices (as sales)
            $sql = "SELECT COUNT(*) as count 
                    FROM invoices 
                    WHERE DATE_FORMAT(invoice_date, '%Y-%m') = :date $clinicCondition";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            $salesData[] = (int)$result['count'];
        }
        
        return [
            'labels' => $labels,
            'revenue' => $revenueData,
            'sales' => $salesData
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getClinicDistribution($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                c.clinic_name,
                COUNT(DISTINCT p.id) as patient_count
            FROM clinics c
            LEFT JOIN patients p ON c.id = p.clinic_id
            WHERE c.status = 'Active'
            GROUP BY c.id, c.clinic_name
            ORDER BY patient_count DESC
            LIMIT 5
        ");
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = [];
        $patientData = [];
        
        foreach ($results as $row) {
            $labels[] = $row['clinic_name'];
            $patientData[] = (int)$row['patient_count'];
        }
        
        return [
            'labels' => $labels,
            'patients' => $patientData
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getRecentActivity($pdo, $clinicId) {
    try {
        // First try to get from activity_logs
        $clinicCondition = $clinicId === 'all' ? '' : "WHERE al.clinic_id = :clinic_id";
        $sql = "
            SELECT 
                al.*,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                u.role
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $clinicCondition
            ORDER BY al.created_at DESC 
            LIMIT 5
        ";
        
        if ($clinicId === 'all') {
            $stmt = $pdo->query($sql);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['clinic_id' => $clinicId]);
        }
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no activities in logs, use appointments as recent activities
        if (empty($activities)) {
            $clinicCondition = $clinicId === 'all' ? '' : "AND a.clinic_id = :clinic_id";
            $sql = "
                SELECT 
                    a.*,
                    CONCAT(p.first_name, ' ', p.last_name) as user_name,
                    'Appointment' as action,
                    'Appointments' as module,
                    CONCAT('Appointment ', a.status, ' for ', p.first_name) as details,
                    a.created_at
                FROM appointments a
                JOIN patients p ON a.patient_id = p.id
                WHERE 1=1 $clinicCondition
                ORDER BY a.created_at DESC 
                LIMIT 5
            ";
            
            if ($clinicId === 'all') {
                $stmt = $pdo->query($sql);
            } else {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['clinic_id' => $clinicId]);
            }
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $activities;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getLowStockAlerts($pdo, $clinicId) {
    try {
        if ($clinicId === 'all') {
            $stmt = $pdo->query("
                SELECT name, category, brand, stock, reorder_level, price
                FROM inventory 
                WHERE stock <= reorder_level 
                AND stock > 0 
                ORDER BY stock ASC 
                LIMIT 5
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT name, category, brand, stock, reorder_level, price
                FROM inventory 
                WHERE clinic_id = :clinic_id 
                AND stock <= reorder_level 
                AND stock > 0 
                ORDER BY stock ASC 
                LIMIT 5
            ");
            $stmt->execute(['clinic_id' => $clinicId]);
        }
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $items;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function getQuickStats($pdo, $clinicId) {
    try {
        $stats = [];
        $today = date('Y-m-d');
        
        // 1. Active Users
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE status = 'Active'");
        $stats['active_users'] = (int)$stmt->fetch()['count'];
        
        // 2. Today's Revenue
        if ($clinicId === 'all') {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total), 0) as revenue 
                FROM invoices 
                WHERE invoice_date = :today
            ");
            $stmt->execute(['today' => $today]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(total), 0) as revenue 
                FROM invoices 
                WHERE invoice_date = :today 
                AND clinic_id = :clinic_id
            ");
            $stmt->execute(['today' => $today, 'clinic_id' => $clinicId]);
        }
        $stats['today_revenue'] = (float)$stmt->fetch()['revenue'];
        
        // 3. Pending Appointments (today and future)
        if ($clinicId === 'all') {
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM appointments 
                WHERE status = 'Pending' 
                AND appointment_date >= CURDATE()
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM appointments 
                WHERE status = 'Pending' 
                AND appointment_date >= CURDATE()
                AND clinic_id = :clinic_id
            ");
            $stmt->execute(['clinic_id' => $clinicId]);
        }
        $stats['pending_appointments'] = (int)$stmt->fetch()['count'];
        
        return $stats;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
?>