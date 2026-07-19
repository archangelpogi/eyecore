<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$period = $_GET['period'] ?? 6;

try {
    switch($action) {
        case 'statistics':
            // Get statistics
            $dateLimit = date('Y-m-d', strtotime("-$period months"));
            
            // Total revenue
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) as total FROM invoices WHERE invoice_date >= ?");
            $stmt->execute([$dateLimit]);
            $total_revenue = $stmt->fetchColumn();
            
            // Total patients
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM patients WHERE status = 'Active'");
            $total_patients = $stmt->fetchColumn();
            
            // Total appointments
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date >= ?");
            $stmt->execute([$dateLimit]);
            $total_appointments = $stmt->fetchColumn();
            
            // Average transaction
            $stmt = $pdo->prepare("SELECT COALESCE(AVG(total), 0) as avg FROM invoices WHERE invoice_date >= ? AND total > 0");
            $stmt->execute([$dateLimit]);
            $avg_transaction = $stmt->fetchColumn();
            
            echo json_encode([
                'total_revenue' => (float)$total_revenue,
                'total_patients' => (int)$total_patients,
                'total_appointments' => (int)$total_appointments,
                'avg_transaction' => (float)$avg_transaction
            ]);
            break;
            
        case 'sales_report':
            // Generate sales report data
            $months = [];
            $sales = [];
            
            for ($i = $period - 1; $i >= 0; $i--) {
                $month = date('M', strtotime("-$i months"));
                $months[] = $month;
                
                // Simulate sales data (replace with actual query)
                $sales[] = rand(40000, 80000);
            }
            
            echo json_encode([
                'labels' => $months,
                'data' => $sales
            ]);
            break;
            
        case 'patient_reports':
            // Patient demographics
            echo json_encode([
                'gender' => [45, 52, 3], // Male, Female, Other
                'age_groups' => [15, 45, 65, 40, 20], // 0-18, 19-30, 31-45, 46-60, 60+
                'growth_labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                'growth_data' => [45, 52, 61, 58, 67, 74]
            ]);
            break;
            
        case 'appointment_reports':
            // Appointment analytics
            echo json_encode([
                'status' => [65, 20, 10, 5], // Completed, Pending, Cancelled, No-show
                'services' => ['Eye Exam', 'Check-up', 'Frame Fitting', 'Consultation'],
                'service_counts' => [45, 32, 28, 15],
                'monthly_labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                'monthly_data' => [85, 92, 78, 95, 88, 102]
            ]);
            break;
            
        case 'inventory_reports':
            // Inventory analysis
            echo json_encode([
                'categories' => ['Frames', 'Lenses', 'Accessories', 'Contact Lenses'],
                'category_counts' => [45, 35, 15, 5],
                'top_items' => ['Ray-Ban Aviator', 'Oakley Frogskins', 'Progressive Lens', 'Blue Light Filter'],
                'item_values' => [125000, 85000, 65000, 45000],
                'top_selling' => [
                    ['name' => 'Ray-Ban Aviator', 'category' => 'Frames', 'sold' => 45, 'revenue' => 112500, 'stock' => 15],
                    ['name' => 'Progressive Lens 1.67', 'category' => 'Lenses', 'sold' => 38, 'revenue' => 95000, 'stock' => 5],
                    ['name' => 'Microfiber Cloth', 'category' => 'Accessories', 'sold' => 120, 'revenue' => 6000, 'stock' => 30],
                    ['name' => 'Oakley Frogskins', 'category' => 'Frames', 'sold' => 22, 'revenue' => 70400, 'stock' => 3],
                    ['name' => 'Blue Light Filter', 'category' => 'Lenses', 'sold' => 28, 'revenue' => 33600, 'stock' => 2]
                ]
            ]);
            break;
            
        case 'export_all':
            // Generate ZIP file with all reports (simplified)
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="reports_' . date('Y-m-d') . '.zip"');
            echo "All reports export feature would generate a ZIP file here.";
            break;
            
        case 'export_patients':
            // Export patient report as CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="patient_report_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Patient ID', 'Name', 'Age', 'Gender', 'Contact', 'Status', 'Last Visit']);
            
            $stmt = $pdo->query("SELECT p.*, MAX(a.appointment_date) as last_visit 
                                FROM patients p 
                                LEFT JOIN appointments a ON p.id = a.patient_id 
                                GROUP BY p.id 
                                ORDER BY p.created_at DESC 
                                LIMIT 100");
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['patient_id'],
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['age'],
                    $row['gender'],
                    $row['contact'],
                    $row['status'],
                    $row['last_visit'] ?? 'Never'
                ]);
            }
            
            fclose($output);
            break;
            
        case 'low_stock_report':
            // Export low stock report
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="low_stock_report_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Item ID', 'Name', 'Category', 'Current Stock', 'Reorder Level', 'Status']);
            
            $stmt = $pdo->query("SELECT * FROM inventory WHERE stock <= reorder_level ORDER BY stock ASC");
            
            while ($row = $stmt->fetch()) {
                $status = $row['stock'] == 0 ? 'Out of Stock' : 
                         ($row['stock'] <= 2 ? 'Critical' : 'Low Stock');
                
                fputcsv($output, [
                    $row['item_id'],
                    $row['name'],
                    $row['category'],
                    $row['stock'],
                    $row['reorder_level'],
                    $status
                ]);
            }
            
            fclose($output);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>