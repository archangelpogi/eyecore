<?php
session_start();
header('Content-Type: application/json');

class Database {
    private $host = "localhost";
    private $db_name = "u334978718_eyecore_db";
    private $username = "u334978718_eyecore_user";
    private $password = "Eyecore@2026";
    public $conn;
    
    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                  $this->username, 
                                  $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("SET NAMES utf8mb4");
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            return null;
        }
        return $this->conn;
    }
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'SCM';
$session_clinic_id = $_SESSION['clinic_id'] ?? 1;
$user_name = $_SESSION['name'] ?? 'Unknown User';

// Initialize database connection
$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

class SalaryDisbursement {
    private $conn;
    private $user_id;
    private $user_role;
    private $session_clinic_id;
    private $actual_clinic_id;
    private $user_name;
    
    public function __construct($conn, $user_id, $user_role, $session_clinic_id, $user_name) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->user_role = $user_role;
        $this->session_clinic_id = $session_clinic_id;
        $this->user_name = $user_name;
        
        // DETECT ACTUAL CLINIC_ID FROM DATABASE
        $this->detectActualClinicId();
    }
    
    /**
     * DETECT KUNG ANONG CLINIC_ID ANG MAY DATA
     */
    private function detectActualClinicId() {
        try {
            // Try to get clinic_id from payroll table
            $stmt = $this->conn->query("SELECT DISTINCT clinic_id FROM payroll LIMIT 1");
            $payroll_clinic = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payroll_clinic && $payroll_clinic['clinic_id']) {
                $this->actual_clinic_id = $payroll_clinic['clinic_id'];
                error_log("✅ Using clinic_id from payroll: " . $this->actual_clinic_id);
                return;
            }
            
            // Try employees table
            $stmt = $this->conn->query("SELECT DISTINCT clinic_id FROM employees LIMIT 1");
            $emp_clinic = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($emp_clinic && $emp_clinic['clinic_id']) {
                $this->actual_clinic_id = $emp_clinic['clinic_id'];
                error_log("✅ Using clinic_id from employees: " . $this->actual_clinic_id);
                return;
            }
            
            // Fallback to session clinic_id
            $this->actual_clinic_id = $this->session_clinic_id;
            error_log("⚠️ Using session clinic_id: " . $this->actual_clinic_id);
            
        } catch (Exception $e) {
            $this->actual_clinic_id = $this->session_clinic_id;
            error_log("❌ Error detecting clinic_id, using session: " . $this->actual_clinic_id);
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        
        switch($action) {
            case 'get_disbursements':
                $this->getDisbursements();
                break;
            case 'get_stats':
                $this->getStats();
                break;
            case 'get_periods':
                $this->getPeriods();
                break;
            // ===== CSV GENERATOR (WORKING SOLUTION) =====
            case 'generate_bank_csv':
                $this->generateBankCSV();
                break;
            // ===== LEGACY FUNCTIONS (keep for compatibility) =====
            case 'generate_bank_file':
                $this->generateBankFile();
                break;
            case 'export_report':
                $this->exportReport();
            case 'mark_completed':
                $this->markAsCompleted();
                break;
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    }

    private function getPeriods() {
        try {
            $clinic_id_to_use = $this->actual_clinic_id;
            
            $stmt = $this->conn->prepare("
                SELECT DISTINCT 
                    period_start, 
                    period_end,
                    COUNT(*) as record_count,
                    COALESCE(SUM(net_pay), 0) as total_amount
                FROM payroll 
                WHERE clinic_id = :clinic_id
                GROUP BY period_start, period_end
                ORDER BY period_start DESC
            ");
            $stmt->execute(['clinic_id' => $clinic_id_to_use]);
            $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'periods' => $periods,
                'count' => count($periods)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting periods: " . $e->getMessage());
            echo json_encode([
                'success' => false, 
                'error' => $e->getMessage(),
                'periods' => []
            ]);
        }
    }
    
    private function getDisbursements() {
        try {
            $period = $_GET['period'] ?? 'current';
            $department = $_GET['department'] ?? 'all';
            $search = $_GET['search'] ?? '';
            
            // Get period dates
            $period_dates = $this->getPayrollPeriodDates($period);
            
            // ============= DETECT CLINIC_ID =============
            $clinic_id_to_use = $this->actual_clinic_id;
            
            // ============= GET AVAILABLE PERIODS =============
            $stmt = $this->conn->prepare("
                SELECT DISTINCT 
                    period_start, 
                    period_end,
                    COUNT(*) as record_count
                FROM payroll 
                WHERE clinic_id = :clinic_id
                GROUP BY period_start, period_end
                ORDER BY period_start DESC
            ");
            $stmt->execute(['clinic_id' => $clinic_id_to_use]);
            $available_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ============= USE THE LATEST AVAILABLE PERIOD =============
            if (!empty($available_periods)) {
                $latest = $available_periods[0];
                $period_dates['start'] = $latest['period_start'];
                $period_dates['end'] = $latest['period_end'];
                $period_dates['label'] = 'Latest Period';
            }
            
            // ============= GET ALL READY PAYMENTS =============
            $query = "SELECT 
                        p.id,
                        p.employee_id,
                        e.employee_no,
                        CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                        e.position,
                        e.department,
                        e.bank_name,
                        e.bank_account_holder,
                        e.bank_account_number,
                        COALESCE(p.basic_salary, 0) as basic_salary,
                        COALESCE(p.overtime, 0) as overtime,
                        COALESCE(p.holiday_pay, 0) as holiday_pay,
                        COALESCE(p.allowances, 0) as allowances,
                        COALESCE(p.bonuses, 0) as bonuses,
                        COALESCE(p.night_differential, 0) as night_differential,
                        COALESCE(p.tardiness, 0) as tardiness,
                        COALESCE(p.absences, 0) as absences,
                        COALESCE(p.leave_without_pay, 0) as leave_without_pay,
                        COALESCE(p.sss_contribution, 0) as sss_contribution,
                        COALESCE(p.philhealth_contribution, 0) as philhealth_contribution,
                        COALESCE(p.pagibig_contribution, 0) as pagibig_contribution,
                        COALESCE(p.withholding_tax, 0) as withholding_tax,
                        COALESCE(p.other_deductions, 0) as other_deductions,
                        COALESCE(p.gross_pay, 0) as gross_pay,
                        COALESCE(p.total_deductions, 0) as total_deductions,
                        COALESCE(p.net_pay, 0) as net_pay,
                        p.status,
                        p.released_at,
                        p.payment_reference,
                        p.payment_method,
                        p.period_start,
                        p.period_end
                    FROM payroll p
                    INNER JOIN employees e ON p.employee_id = e.id
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE p.clinic_id = :clinic_id";

            $params = ['clinic_id' => $clinic_id_to_use];
            
            // ============= GAMITIN ANG ACTUAL PERIOD =============
            $query .= " AND p.period_start = :period_start AND p.period_end = :period_end";
            $params['period_start'] = $period_dates['start'];
            $params['period_end'] = $period_dates['end'];
            
            // Add department filter
            if ($department !== 'all') {
                $query .= " AND e.department = :department";
                $params['department'] = $department;
            }
            
            // Add search filter
            if (!empty($search)) {
                $query .= " AND (
                    e.employee_no LIKE :search OR 
                    u.first_name LIKE :search OR 
                    u.last_name LIKE :search OR 
                    e.bank_name LIKE :search OR
                    e.bank_account_number LIKE :search
                )";
                $params['search'] = "%$search%";
            }
            
            $query .= " ORDER BY p.period_end DESC, e.department, e.employee_no";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $disbursements = [];
            foreach ($result as $row) {
                // ========== STATUS DETERMINATION ==========
                // 'Ready' -> 'queued' for frontend
                $status = $this->determineDisbursementStatus($row['status']);
                
                // Calculate totals
                $total_allowances = $row['allowances'] + $row['bonuses'] + $row['night_differential'];
                $total_deductions = $row['sss_contribution'] + $row['philhealth_contribution'] + 
                                  $row['pagibig_contribution'] + $row['withholding_tax'] + 
                                  $row['other_deductions'] + $row['tardiness'] + 
                                  $row['absences'] + $row['leave_without_pay'];
                
                // Get payslip code if exists
                $payslip_code = null;
                if ($row['status'] == 'Released') {
                    $stmt2 = $this->conn->prepare("
                        SELECT payslip_code FROM payslips 
                        WHERE payroll_id = :payroll_id LIMIT 1
                    ");
                    $stmt2->execute(['payroll_id' => $row['id']]);
                    $payslip = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $payslip_code = $payslip['payslip_code'] ?? null;
                }
                
                $disbursements[] = [
                    'id' => (int)$row['id'],
                    'employee_id' => (int)$row['employee_id'],
                    'employee_no' => $row['employee_no'] ?? 'N/A',
                    'employee_name' => $row['employee_name'] ?? 'N/A',
                    'position' => $row['position'] ?? 'N/A',
                    'department' => $row['department'] ?? 'N/A',
                    'bank_name' => $row['bank_name'] ?? 'Not Set',
                    'bank_account_holder' => $row['bank_account_holder'] ?? $row['employee_name'],
                    'bank_account_number' => $row['bank_account_number'] ? $this->maskAccountNumber($row['bank_account_number']) : '****0000',
                    'basic_salary' => (float)$row['basic_salary'],
                    'allowances' => (float)$total_allowances,
                    'overtime_pay' => (float)$row['overtime'],
                    'holiday_pay' => (float)$row['holiday_pay'],
                    'tardiness' => (float)$row['tardiness'],
                    'absences' => (float)$row['absences'],
                    'gross_pay' => (float)$row['gross_pay'],
                    'sss' => (float)$row['sss_contribution'],
                    'philhealth' => (float)$row['philhealth_contribution'],
                    'pagibig' => (float)$row['pagibig_contribution'],
                    'tax' => (float)$row['withholding_tax'],
                    'other_deductions' => (float)$row['other_deductions'],
                    'total_deductions' => (float)$total_deductions,
                    'net_pay' => (float)$row['net_pay'],
                    'status' => $status, // 'queued' for 'Ready' status
                    'due_date' => $row['period_end'],
                    'paid_date' => $row['released_at'],
                    'payment_reference' => $row['payment_reference'],
                    'payment_method' => $row['payment_method'],
                    'payslip_code' => $payslip_code,
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $disbursements,
                'total' => count($disbursements),
                'period' => $period_dates,
                'clinic_id_used' => $clinic_id_to_use,
                'message' => count($disbursements) > 0 ? 'Payments fetched!' : 'No payments for this period'
            ]);
            
        } catch (PDOException $e) {
            error_log("PDO Error in getDisbursements: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $e->getMessage()]);
        } catch (Exception $e) {
            error_log("Error in getDisbursements: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function getStats() {
        try {
            $period = $_GET['period'] ?? 'current';
            $period_dates = $this->getPayrollPeriodDates($period);
            
            // Use detected clinic_id
            $clinic_id_to_use = $this->actual_clinic_id;
            
            $query = "SELECT 
                        COUNT(DISTINCT employee_id) as total_employees,
                        COALESCE(SUM(gross_pay), 0) as total_gross,
                        COALESCE(SUM(total_deductions), 0) as total_deductions,
                        COALESCE(SUM(net_pay), 0) as total_net,
                        COALESCE(SUM(CASE WHEN status = 'Released' THEN net_pay ELSE 0 END), 0) as total_released,
                        COALESCE(SUM(CASE WHEN status = 'Ready' THEN net_pay ELSE 0 END), 0) as total_queued,
                        COUNT(CASE WHEN status = 'Released' THEN 1 END) as released_count,
                        COUNT(CASE WHEN status = 'Ready' THEN 1 END) as queued_count,
                        COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) as failed_count,
                        COUNT(CASE WHEN status IN ('Generated', 'Ready') THEN 1 END) as pending_approvals
                    FROM payroll 
                    WHERE clinic_id = :clinic_id";
            
            $params = ['clinic_id' => $clinic_id_to_use];
            
            if ($period_dates['start'] && $period_dates['end']) {
                $query .= " AND period_start >= :period_start AND period_end <= :period_end";
                $params['period_start'] = $period_dates['start'];
                $params['period_end'] = $period_dates['end'];
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'total_employees' => (int)($stats['total_employees'] ?? 0),
                    'total_gross' => (float)($stats['total_gross'] ?? 0),
                    'total_deductions' => (float)($stats['total_deductions'] ?? 0),
                    'total_net' => (float)($stats['total_net'] ?? 0),
                    'total_released' => (float)($stats['total_released'] ?? 0),
                    'total_queued' => (float)($stats['total_queued'] ?? 0),
                    'released_count' => (int)($stats['released_count'] ?? 0),
                    'queued_count' => (int)($stats['queued_count'] ?? 0),
                    'failed_count' => (int)($stats['failed_count'] ?? 0),
                    'pending_approvals' => (int)($stats['pending_approvals'] ?? 0)
                ],
                'period' => $period_dates,
                'clinic_id_used' => $clinic_id_to_use
            ]);
            
        } catch (PDOException $e) {
            error_log("PDO Error in getStats: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
// ===== UPDATE STATUS TO PROCESSING (RECOMMENDED) =====
private function generateBankCSV() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $payroll_ids = $input['payroll_ids'] ?? [];
        
        if (empty($payroll_ids)) {
            throw new Exception('No payments selected');
        }
        
        // Get selected payroll details
        $placeholders = implode(',', array_fill(0, count($payroll_ids), '?'));
        
        $query = "SELECT 
                    p.id,
                    e.employee_no,
                    e.bank_account_holder,
                    e.bank_account_number,
                    e.bank_name,
                    p.net_pay,
                    CONCAT(u.first_name, ' ', u.last_name) as employee_name
                  FROM payroll p
                  INNER JOIN employees e ON p.employee_id = e.id
                  INNER JOIN users u ON e.user_id = u.id
                  WHERE p.id IN ($placeholders)
                  AND p.status = 'Ready'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($payroll_ids);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($result)) {
            throw new Exception('No valid Ready payments found');
        }
        
        // Generate CSV content
        $filename = 'bank_transfer_' . date('Ymd_His') . '.csv';
        $output = fopen('php://temp', 'w');
        
        fputcsv($output, [
            'Account Number', 'Account Name', 'Bank', 'Amount', 
            'Employee ID', 'Employee Name', 'Reference'
        ]);
        
        $total_amount = 0;
        $reference_no = 'PAY-' . date('Ymd') . '-' . uniqid();
        
        foreach ($result as $row) {
            $account_number = preg_replace('/[^0-9]/', '', $row['bank_account_number']);
            fputcsv($output, [
                $account_number,
                $row['bank_account_holder'],
                $row['bank_name'],
                number_format($row['net_pay'], 2, '.', ''),
                $row['employee_no'],
                $row['employee_name'],
                $reference_no
            ]);
            $total_amount += $row['net_pay'];
        }
        
        fputcsv($output, ['', '', 'TOTAL:', number_format($total_amount, 2, '.', ''), '', '', '']);
        
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        // ===== RECOMMENDED: Update to PROCESSING status =====
        $update_query = "UPDATE payroll 
                         SET payment_reference = ?,
                             status = 'Processing',
                             updated_at = NOW()
                         WHERE id IN ($placeholders)";
        $update_stmt = $this->conn->prepare($update_query);
        
        foreach ($payroll_ids as $id) {
            $update_stmt->execute([$reference_no, $id]);
        }
        
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'csv_content' => base64_encode($csv_content),
            'record_count' => count($result),
            'total_amount' => $total_amount,
            'reference_no' => $reference_no,
            'message' => 'CSV generated. Status updated to Processing.'
        ]);
        
    } catch (Exception $e) {
        error_log("Generate Bank CSV Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
    
    // ==================== LEGACY FUNCTIONS (KEEP FOR COMPATIBILITY) ====================
    
    private function generateBankFile() {
        try {
            $payroll_ids = json_decode($_POST['payroll_ids'] ?? '[]');
            
            if (empty($payroll_ids)) {
                throw new Exception('No payments selected');
            }
            
            $placeholders = implode(',', array_fill(0, count($payroll_ids), '?'));
            
            $query = "SELECT 
                        e.employee_no,
                        e.bank_account_holder,
                        e.bank_account_number,
                        p.net_pay,
                        p.payment_reference
                      FROM payroll p
                      INNER JOIN employees e ON p.employee_id = e.id
                      WHERE p.id IN ($placeholders)
                      AND p.status = 'Released'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($payroll_ids);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $filename = 'bank_transfer_' . date('Ymd_His') . '.csv';
            $upload_dir = __DIR__ . '/../uploads/bank_files/';
            
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_path = 'uploads/bank_files/' . $filename;
            $full_path = $upload_dir . $filename;
            
            $fp = fopen($full_path, 'w');
            fputcsv($fp, ['Account Number', 'Account Name', 'Amount', 'Reference', 'Employee ID']);
            
            $total_amount = 0;
            foreach ($result as $row) {
                fputcsv($fp, [
                    $row['bank_account_number'],
                    $row['bank_account_holder'],
                    number_format($row['net_pay'], 2, '.', ''),
                    $row['payment_reference'],
                    $row['employee_no']
                ]);
                $total_amount += $row['net_pay'];
            }
            fclose($fp);
            
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'file_url' => $file_path,
                'record_count' => count($result),
                'total_amount' => $total_amount
            ]);
            
        } catch (Exception $e) {
            error_log("Generate Bank File Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function exportReport() {
        try {
            $period = $_GET['period'] ?? date('Y-m');
            list($year, $month) = explode('-', $period);
            
            $clinic_id_to_use = $this->actual_clinic_id;
            
            $query = "SELECT 
                        e.employee_no,
                        CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                        e.position,
                        e.department,
                        p.net_pay,
                        p.status,
                        p.released_at,
                        p.payment_reference
                      FROM payroll p
                      INNER JOIN employees e ON p.employee_id = e.id
                      INNER JOIN users u ON e.user_id = u.id
                      WHERE p.clinic_id = :clinic_id
                      AND YEAR(p.period_start) = :year
                      AND MONTH(p.period_start) = :month";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                'clinic_id' => $clinic_id_to_use,
                'year' => $year,
                'month' => $month
            ]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $filename = "payroll_disbursement_{$period}.csv";
            $upload_dir = __DIR__ . '/../uploads/reports/';
            
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_path = 'uploads/reports/' . $filename;
            $full_path = $upload_dir . $filename;
            
            $fp = fopen($full_path, 'w');
            fputcsv($fp, ['Employee No', 'Employee Name', 'Position', 'Department', 'Net Pay', 'Status', 'Release Date', 'Reference']);
            
            foreach ($result as $row) {
                fputcsv($fp, [
                    $row['employee_no'],
                    $row['employee_name'],
                    $row['position'],
                    $row['department'],
                    $row['net_pay'],
                    $row['status'],
                    $row['released_at'],
                    $row['payment_reference']
                ]);
            }
            fclose($fp);
            
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'file_url' => $file_path,
                'record_count' => count($result)
            ]);
            
        } catch (Exception $e) {
            error_log("Export Report Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function markAsCompleted() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $payroll_ids = $input['payroll_ids'] ?? [];
        
        if (empty($payroll_ids)) {
            throw new Exception('No payments selected');
        }
        
        $placeholders = implode(',', array_fill(0, count($payroll_ids), '?'));
        
        $query = "UPDATE payroll 
                  SET status = 'Released',
                      released_at = NOW(),
                      updated_at = NOW()
                  WHERE id IN ($placeholders)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($payroll_ids);
        
        echo json_encode([
            'success' => true,
            'updated_count' => $stmt->rowCount(),
            'message' => 'Payments marked as completed'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
    
    // Helper Functions
    private function getPayrollPeriodDates($period) {
        if ($period === 'current') {
            $day = date('j');
            if ($day <= 15) {
                return ['start' => date('Y-m-01'), 'end' => date('Y-m-15')];
            } else {
                return ['start' => date('Y-m-16'), 'end' => date('Y-m-t')];
            }
        } elseif ($period === 'previous') {
            $day = date('j');
            if ($day <= 15) {
                return [
                    'start' => date('Y-m-16', strtotime('first day of last month')),
                    'end' => date('Y-m-t', strtotime('first day of last month'))
                ];
            } else {
                return ['start' => date('Y-m-01'), 'end' => date('Y-m-15')];
            }
        } elseif (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return [
                'start' => date('Y-m-01', strtotime($period . '-01')),
                'end' => date('Y-m-t', strtotime($period . '-01'))
            ];
        }
        return ['start' => date('Y-m-01'), 'end' => date('Y-m-t')];
    }
    
    private function determineDisbursementStatus($status) {
        $map = [
            'Released' => 'completed',
            'Approved' => 'processing',
            'Ready' => 'queued',     // IMPORTANT: 'Ready' -> 'queued' para sa frontend
            'Generated' => 'pending',
            'Draft' => 'draft',
            'Cancelled' => 'failed'
        ];
        return $map[$status] ?? strtolower($status);
    }
    
    private function maskAccountNumber($account_number) {
        if (empty($account_number)) return '****0000';
        $account_number = preg_replace('/[^0-9]/', '', $account_number);
        $length = strlen($account_number);
        if ($length <= 4) return '****' . $account_number;
        return '****' . substr($account_number, -4);
    }
    
    private function generateReferenceNumber() {
        return 'PAY-' . date('Ymd') . '-' . strtoupper(uniqid());
    }
}

// ========== CONSTRUCTOR CALL ==========
$disbursement = new SalaryDisbursement(
    $conn, 
    $current_user_id, 
    $user_role, 
    $session_clinic_id, 
    $user_name
);
$disbursement->handleRequest();
?>