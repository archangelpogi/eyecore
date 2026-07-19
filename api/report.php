<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission helper functions
function canViewReports() { return RBACHelper::hasPermission('reports_view'); }
function canCreateReports() { return RBACHelper::hasPermission('reports_create'); }

// Check if logged in and has clinic_id
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ✅ Check view permission
if (!canViewReports()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot view reports']);
    exit();
}

$clinic_id = $_SESSION['clinic_id'];
$report_type = isset($_GET['report']) ? $_GET['report'] : 'sales';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Check if export requested
$export = isset($_GET['export']) ? $_GET['export'] : null;

try {
    // Handle export if requested
    if ($export && canCreateReports()) {
        handleExport($pdo, $clinic_id, $report_type, $start_date, $end_date, $export);
        exit();
    }
    
    $response = ['success' => true];
    
    switch($report_type) {
        case 'sales':
            $response = getSalesReport($pdo, $clinic_id, $start_date, $end_date);
            break;
        case 'patients':
            $response = getPatientsReport($pdo, $clinic_id, $start_date, $end_date);
            break;
        case 'appointments':
            $response = getAppointmentsReport($pdo, $clinic_id, $start_date, $end_date);
            break;
        case 'inventory':
            $response = getInventoryReport($pdo, $clinic_id, $start_date, $end_date);
            break;
        case 'services':
            $response = getServicesReport($pdo, $clinic_id, $start_date, $end_date);
            break;
        default:
            $response = ['success' => false, 'message' => 'Invalid report type'];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Reports API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ============================================
// EXPORT HANDLER
// ============================================
function handleExport($pdo, $clinic_id, $report_type, $start_date, $end_date, $format) {
    // Set headers for download
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $report_type . '_report_' . $start_date . '_to_' . $end_date . '.csv"');
        $output = fopen('php://output', 'w');
        
        // Get data based on report type
        switch($report_type) {
            case 'sales':
                exportSalesCSV($pdo, $clinic_id, $start_date, $end_date, $output);
                break;
            case 'patients':
                exportPatientsCSV($pdo, $clinic_id, $start_date, $end_date, $output);
                break;
            case 'appointments':
                exportAppointmentsCSV($pdo, $clinic_id, $start_date, $end_date, $output);
                break;
            case 'inventory':
                exportInventoryCSV($pdo, $clinic_id, $output);
                break;
            case 'services':
                exportServicesCSV($pdo, $clinic_id, $output);
                break;
        }
        fclose($output);
    } else {
        // For JSON export
        $data = [];
        switch($report_type) {
            case 'sales':
                $data = getSalesReport($pdo, $clinic_id, $start_date, $end_date);
                break;
            case 'patients':
                $data = getPatientsReport($pdo, $clinic_id, $start_date, $end_date);
                break;
            case 'appointments':
                $data = getAppointmentsReport($pdo, $clinic_id, $start_date, $end_date);
                break;
            case 'inventory':
                $data = getInventoryReport($pdo, $clinic_id, $start_date, $end_date);
                break;
            case 'services':
                $data = getServicesReport($pdo, $clinic_id, $start_date, $end_date);
                break;
        }
        
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $report_type . '_report_' . $start_date . '_to_' . $end_date . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT);
        }
    }
}

function exportSalesCSV($pdo, $clinic_id, $start_date, $end_date, $output) {
    fputcsv($output, ['Sale ID', 'Date', 'Invoice No', 'Patient', 'Total Amount', 'Amount Paid', 'Payment Method', 'Status']);
    
    $stmt = $pdo->prepare("
        SELECT s.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name
        FROM sales s
        LEFT JOIN patients p ON s.patient_id = p.id
        WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute([$clinic_id, $start_date, $end_date]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['sale_date'],
            $row['invoice_number'],
            $row['patient_name'] ?? 'Walk-in',
            $row['total_amount'],
            $row['amount_paid'],
            $row['payment_method'],
            $row['status']
        ]);
    }
}

function exportPatientsCSV($pdo, $clinic_id, $start_date, $end_date, $output) {
    fputcsv($output, ['Patient ID', 'Name', 'Age', 'Gender', 'Patient Type', 'Email', 'Phone', 'Address', 'Created At', 'Total Spent']);
    
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            COALESCE(SUM(s.total_amount), 0) as total_spent
        FROM patients p
        LEFT JOIN sales s ON p.id = s.patient_id AND s.sale_date BETWEEN ? AND ?
        WHERE p.clinic_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$start_date, $end_date, $clinic_id]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['age'],
            $row['gender'],
            $row['patient_type'],
            $row['email'],
            $row['phone'],
            $row['address'],
            $row['created_at'],
            $row['total_spent']
        ]);
    }
}

function exportAppointmentsCSV($pdo, $clinic_id, $start_date, $end_date, $output) {
    fputcsv($output, ['Appointment ID', 'Date', 'Time', 'Patient', 'Service', 'Doctor', 'Status', 'Payment Status', 'Total Amount']);
    
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            COALESCE(pr.name, a.service_type) as service_name,
            d.name as doctor_name
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN products pr ON a.product_id = pr.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        WHERE a.clinic_id = ? AND a.appointment_date BETWEEN ? AND ?
        ORDER BY a.appointment_date DESC
    ");
    $stmt->execute([$clinic_id, $start_date, $end_date]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['appointment_date'],
            $row['appointment_time'],
            $row['patient_name'] ?? 'Walk-in',
            $row['service_name'] ?? 'Check-up',
            $row['doctor_name'] ?? 'Not assigned',
            $row['status'],
            $row['payment_status'],
            $row['total_amount']
        ]);
    }
}

function exportInventoryCSV($pdo, $clinic_id, $output) {
    fputcsv($output, ['Item ID', 'Name', 'Category', 'Brand', 'Stock', 'Min Stock', 'Selling Price', 'Cost Price', 'Total Value', 'Status']);
    
    $stmt = $pdo->prepare("
        SELECT * FROM inventory
        WHERE clinic_id = ? AND is_archived = 0
        ORDER BY name
    ");
    $stmt->execute([$clinic_id]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['stock'] <= 0 ? 'Out of Stock' : ($row['stock'] <= $row['min_stock'] ? 'Low Stock' : 'In Stock');
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['category'],
            $row['brand'],
            $row['stock'],
            $row['min_stock'],
            $row['selling_price'],
            $row['cost'],
            $row['stock'] * $row['selling_price'],
            $status
        ]);
    }
}

function exportServicesCSV($pdo, $clinic_id, $output) {
    fputcsv($output, ['Service ID', 'Name', 'Category', 'Description', 'Price', 'Duration', 'Status']);
    
    $stmt = $pdo->prepare("
        SELECT * FROM services
        WHERE clinic_id = ?
        ORDER BY name
    ");
    $stmt->execute([$clinic_id]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['category'],
            $row['description'],
            $row['price'],
            $row['duration_minutes'],
            $row['status']
        ]);
    }
}

// ============================================
// SALES REPORT FUNCTIONS
// ============================================

function getSalesReport($pdo, $clinic_id, $start_date, $end_date) {
    // Get previous period for growth calculation
    $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
    $prev_start = date('Y-m-d', strtotime($start_date . " -$days days"));
    $prev_end = date('Y-m-d', strtotime($end_date . " -$days days"));
    
    // Current period summary
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(amount_paid), 0) as total_collected,
            COUNT(DISTINCT id) as transaction_count,
            COUNT(DISTINCT patient_id) as patients_served,
            COALESCE(SUM(total_amount - amount_paid), 0) as pending_balance
        FROM sales
        WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?
    ");
    $stmt->execute([$clinic_id, $start_date, $end_date]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Previous period for growth
    $stmtPrev = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as prev_revenue
        FROM sales
        WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?
    ");
    $stmtPrev->execute([$clinic_id, $prev_start, $prev_end]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    
    $growth = 0;
    if($prev['prev_revenue'] > 0) {
        $growth = round(($current['total_revenue'] - $prev['prev_revenue']) / $prev['prev_revenue'] * 100, 1);
    }
    
    $collection_rate = $current['total_revenue'] > 0 
        ? round($current['total_collected'] / $current['total_revenue'] * 100, 1) 
        : 100;
    
    // Monthly data
    $monthlyStmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(sale_date, '%b %Y') as month,
            DATE_FORMAT(sale_date, '%Y-%m') as month_key,
            COALESCE(SUM(total_amount), 0) as revenue,
            COALESCE(SUM(amount_paid), 0) as collected,
            COUNT(DISTINCT id) as transaction_count
        FROM sales
        WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY month_key, month
        ORDER BY month_key
    ");
    $monthlyStmt->execute([$clinic_id, $start_date, $end_date]);
    $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top selling items
    $topItemsStmt = $pdo->prepare("
        SELECT 
            si.item_name,
            si.item_type,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY si.item_name, si.item_type
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $topItemsStmt->execute([$clinic_id, $start_date, $end_date]);
    $top_items = $topItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment methods
    $paymentStmt = $pdo->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            COALESCE(SUM(amount_paid), 0) as total_amount
        FROM sales
        WHERE clinic_id = ? AND sale_date BETWEEN ? AND ?
        AND payment_method IS NOT NULL
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ");
    $paymentStmt->execute([$clinic_id, $start_date, $end_date]);
    $payment_methods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'summary' => [
            'total_revenue' => $current['total_revenue'],
            'total_collected' => $current['total_collected'],
            'transaction_count' => $current['transaction_count'],
            'patients_served' => $current['patients_served'],
            'pending_balance' => $current['pending_balance'],
            'collection_rate' => $collection_rate,
            'growth' => $growth
        ],
        'monthly' => $monthly,
        'top_items' => $top_items,
        'payment_methods' => $payment_methods
    ];
}

// ============================================
// PATIENTS REPORT FUNCTIONS
// ============================================

function getPatientsReport($pdo, $clinic_id, $start_date, $end_date) {
    // Summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_patients,
            COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as new_patients,
            COUNT(CASE WHEN created_at < ? THEN 1 END) as returning_patients
        FROM patients
        WHERE clinic_id = ?
    ");
    $stmt->execute([$start_date, $end_date, $start_date, $clinic_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total spent
    $spentStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(s.total_amount), 0) as total_spent,
            COALESCE(AVG(s.total_amount), 0) as avg_spent,
            COUNT(DISTINCT s.patient_id) as patients_with_sales
        FROM sales s
        WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
    ");
    $spentStmt->execute([$clinic_id, $start_date, $end_date]);
    $spent = $spentStmt->fetch(PDO::FETCH_ASSOC);
    
    $summary['total_spent'] = $spent['total_spent'];
    $summary['avg_spent'] = $spent['avg_spent'];
    $summary['returning_rate'] = $summary['total_patients'] > 0 
        ? round($summary['returning_patients'] / $summary['total_patients'] * 100, 1) 
        : 0;
    
    // Gender distribution
    $genderStmt = $pdo->prepare("
        SELECT 
            gender,
            COUNT(*) as total_patients
        FROM patients
        WHERE clinic_id = ? AND gender IS NOT NULL
        GROUP BY gender
    ");
    $genderStmt->execute([$clinic_id]);
    $gender_data = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Age groups
    $ageStmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN age < 18 THEN '0-17'
                WHEN age BETWEEN 18 AND 30 THEN '18-30'
                WHEN age BETWEEN 31 AND 45 THEN '31-45'
                WHEN age BETWEEN 46 AND 60 THEN '46-60'
                ELSE '60+'
            END as age_group,
            COUNT(*) as patient_count
        FROM patients
        WHERE clinic_id = ? AND age IS NOT NULL
        GROUP BY age_group
        ORDER BY MIN(age)
    ");
    $ageStmt->execute([$clinic_id]);
    $age_groups = $ageStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Patient growth
    $growthStmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%b %Y') as month,
            DATE_FORMAT(created_at, '%Y-%m') as month_key,
            COUNT(*) as new_patients
        FROM patients
        WHERE clinic_id = ? AND created_at BETWEEN ? AND ?
        GROUP BY month_key, month
        ORDER BY month_key
    ");
    $growthStmt->execute([$clinic_id, $start_date, $end_date]);
    $growth = $growthStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top patients by spending
    $topPatientsStmt = $pdo->prepare("
        SELECT 
            p.id,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            COUNT(s.id) as total_visits,
            COALESCE(SUM(s.total_amount), 0) as total_spent
        FROM patients p
        INNER JOIN sales s ON p.id = s.patient_id
        WHERE p.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY p.id, p.first_name, p.last_name
        ORDER BY total_spent DESC
        LIMIT 10
    ");
    $topPatientsStmt->execute([$clinic_id, $start_date, $end_date]);
    $top_patients = $topPatientsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'summary' => $summary,
        'gender_data' => $gender_data,
        'age_groups' => $age_groups,
        'growth' => $growth,
        'top_patients' => $top_patients
    ];
}

// ============================================
// APPOINTMENTS REPORT FUNCTIONS
// ============================================

function getAppointmentsReport($pdo, $clinic_id, $start_date, $end_date) {
    // Summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_appointments,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
            COUNT(CASE WHEN payment_status IN ('paid', 'downpayment_paid') THEN 1 END) as paid_count,
            COALESCE(SUM(CASE WHEN payment_status IN ('paid', 'downpayment_paid') THEN 
                CASE WHEN payment_type = 'downpayment' THEN downpayment_amount ELSE total_amount END 
            END), 0) as paid_amount
        FROM appointments
        WHERE clinic_id = ? AND appointment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$clinic_id, $start_date, $end_date]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $summary['completion_rate'] = $summary['total_appointments'] > 0 
        ? round($summary['completed'] / $summary['total_appointments'] * 100, 1) 
        : 0;
    $summary['cancellation_rate'] = $summary['total_appointments'] > 0 
        ? round($summary['cancelled'] / $summary['total_appointments'] * 100, 1) 
        : 0;
    
    // Status breakdown
    $statusStmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count
        FROM appointments
        WHERE clinic_id = ? AND appointment_date BETWEEN ? AND ?
        GROUP BY status
    ");
    $statusStmt->execute([$clinic_id, $start_date, $end_date]);
    $status_breakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly trend
    $monthlyStmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(appointment_date, '%b %Y') as month,
            DATE_FORMAT(appointment_date, '%Y-%m') as month_key,
            COUNT(*) as total_appointments,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
        FROM appointments
        WHERE clinic_id = ? AND appointment_date BETWEEN ? AND ?
        GROUP BY month_key, month
        ORDER BY month_key
    ");
    $monthlyStmt->execute([$clinic_id, $start_date, $end_date]);
    $monthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Doctor performance
    $doctorStmt = $pdo->prepare("
        SELECT 
            d.name as doctor_name,
            COUNT(DISTINCT a.id) as total_appointments,
            COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed,
            COALESCE(SUM(p.amount), 0) as revenue_generated
        FROM doctors d
        LEFT JOIN appointments a ON d.id = a.doctor_id 
            AND a.appointment_date BETWEEN ? AND ?
        LEFT JOIN payments p ON a.id = p.appointment_id AND p.payment_status = 'paid'
        WHERE d.clinic_id = ?
        GROUP BY d.id, d.name
        ORDER BY total_appointments DESC
        LIMIT 10
    ");
    $doctorStmt->execute([$start_date, $end_date, $clinic_id]);
    $doctor_performance = $doctorStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Service breakdown
    $serviceStmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN a.item_type = 'product' THEN (SELECT name FROM products WHERE id = a.product_id)
                WHEN a.item_type = 'service' THEN (SELECT name FROM services WHERE id = a.item_id)
                ELSE 'Unknown'
            END as item_name,
            a.item_type,
            COUNT(*) as total_appointments,
            COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.amount END), 0) as total_payment
        FROM appointments a
        LEFT JOIN payments p ON a.id = p.appointment_id
        WHERE a.clinic_id = ? AND a.appointment_date BETWEEN ? AND ?
        GROUP BY item_name, a.item_type
        ORDER BY total_appointments DESC
        LIMIT 10
    ");
    $serviceStmt->execute([$clinic_id, $start_date, $end_date]);
    $service_breakdown = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'summary' => $summary,
        'status_breakdown' => $status_breakdown,
        'monthly' => $monthly,
        'doctor_performance' => $doctor_performance,
        'service_breakdown' => $service_breakdown
    ];
}

// ============================================
// INVENTORY REPORT FUNCTIONS
// ============================================

function getInventoryReport($pdo, $clinic_id, $start_date, $end_date) {
    // Summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            COALESCE(SUM(stock * selling_price), 0) as total_value,
            COUNT(CASE WHEN stock <= min_stock AND stock > 0 THEN 1 END) as low_stock_count,
            COUNT(CASE WHEN stock <= 0 THEN 1 END) as out_of_stock_count
        FROM inventory
        WHERE clinic_id = ? AND is_archived = 0
    ");
    $stmt->execute([$clinic_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Category value
    $categoryStmt = $pdo->prepare("
        SELECT 
            category,
            COUNT(*) as item_count,
            COALESCE(SUM(stock * selling_price), 0) as total_value
        FROM inventory
        WHERE clinic_id = ? AND is_archived = 0
        GROUP BY category
        ORDER BY total_value DESC
    ");
    $categoryStmt->execute([$clinic_id]);
    $category_value = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Low stock items
    $lowStockStmt = $pdo->prepare("
        SELECT 
            name,
            category,
            stock,
            min_stock,
            selling_price,
            stock * selling_price as value
        FROM inventory
        WHERE clinic_id = ? AND is_archived = 0
        AND stock <= min_stock
        ORDER BY stock ASC
        LIMIT 20
    ");
    $lowStockStmt->execute([$clinic_id]);
    $low_stock = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top selling items from sales
    $topSellingStmt = $pdo->prepare("
        SELECT 
            i.name,
            i.category,
            SUM(si.quantity) as units_sold,
            SUM(si.total_price) as revenue
        FROM inventory i
        INNER JOIN sale_items si ON i.id = si.item_id AND si.item_type = 'product'
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY i.id, i.name, i.category
        ORDER BY units_sold DESC
        LIMIT 10
    ");
    $topSellingStmt->execute([$clinic_id, $start_date, $end_date]);
    $top_selling = $topSellingStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'summary' => $summary,
        'category_value' => $category_value,
        'low_stock' => $low_stock,
        'top_selling' => $top_selling
    ];
}

// ============================================
// SERVICES REPORT FUNCTIONS
// ============================================

function getServicesReport($pdo, $clinic_id, $start_date, $end_date) {
    // Summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_services,
            COALESCE(SUM(price), 0) as total_value
        FROM services
        WHERE clinic_id = ? AND status = 'active'
    ");
    $stmt->execute([$clinic_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Revenue from services in sales
    $revenueStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(si.total_price), 0) as total_revenue,
            COUNT(DISTINCT si.sale_id) as total_rendered
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.clinic_id = ? AND si.item_type = 'service'
        AND s.sale_date BETWEEN ? AND ?
    ");
    $revenueStmt->execute([$clinic_id, $start_date, $end_date]);
    $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);
    
    $summary['total_revenue'] = $revenue['total_revenue'];
    $summary['total_rendered'] = $revenue['total_rendered'];
    
    // Category breakdown from services table
    $categoryStmt = $pdo->prepare("
        SELECT 
            category,
            COUNT(*) as service_count,
            COALESCE(SUM(price), 0) as total_value
        FROM services
        WHERE clinic_id = ? AND status = 'active'
        GROUP BY category
        ORDER BY total_value DESC
    ");
    $categoryStmt->execute([$clinic_id]);
    $category_breakdown = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top services from sales
    $topServicesStmt = $pdo->prepare("
        SELECT 
            srv.name,
            srv.category,
            COUNT(DISTINCT si.sale_id) as times_rendered,
            SUM(si.total_price) as revenue
        FROM services srv
        INNER JOIN sale_items si ON srv.id = si.item_id AND si.item_type = 'service'
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.clinic_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY srv.id, srv.name, srv.category
        ORDER BY revenue DESC
        LIMIT 10
    ");
    $topServicesStmt->execute([$clinic_id, $start_date, $end_date]);
    $top_services = $topServicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'summary' => $summary,
        'category_breakdown' => $category_breakdown,
        'top_services' => $top_services
    ];
}
?>